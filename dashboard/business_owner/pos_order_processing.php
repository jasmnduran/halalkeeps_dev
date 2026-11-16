<?php
define('POS_DEBUG', false);
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/MenuWorkflowService.php';
require_once __DIR__ . '/../../includes/IngredientInventoryService.php';
require_once __DIR__ . '/../../includes/RevenuePaymentService.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);
// Only the actual owner and cashier staff may access the POS.
// Ops managers are redirected to their own dashboard.

$page_title   = 'POS — Order Processing';
$page_heading = 'POS — Order Processing';
$is_dashboard = true;
$breadcrumbs  = [
    ['label' => 'POS / Cashier'],
];
$user_id   = (int)$_SESSION['user_id'];
$staff_key = $_SESSION['staff_role_key'] ?? null;
$is_owner  = ($staff_key === null);
$is_mgr    = ($staff_key === STAFF_ROLE_OPS_MANAGER);
$is_cashier= ($staff_key === STAFF_ROLE_CASHIER);
$is_mgr    = ($staff_key === STAFF_ROLE_OPS_MANAGER);
$is_cashier= ($staff_key === STAFF_ROLE_CASHIER);
$error   = '';
$success = '';

if (isset($_SESSION['pos_success'])) {
    $success = $_SESSION['pos_success'];
    unset($_SESSION['pos_success']);
}
if (isset($_SESSION['pos_error'])) {
    $error = $_SESSION['pos_error'];
    unset($_SESSION['pos_error']);
}

// Apply idempotent migrations — free each result immediately to keep
// the connection clean for subsequent prepared statements.
foreach ([
    ['restaurant_staff',  'manager_pin',  'VARCHAR(255) DEFAULT NULL'],
    ['halal_restaurants', 'manager_pin',  'VARCHAR(255) DEFAULT NULL'],
    ['menu_items',        'is_available', 'TINYINT(1) NOT NULL DEFAULT 1'],
] as [$_tbl, $_col, $_def]) {
    db_add_column($conn, $_tbl, $_col, $_def);
}
unset($_tbl, $_col, $_def);

// ── Runtime session refresh: always re-resolve staff_role_key from DB ────────
if ((int)($_SESSION['role_id'] ?? 0) === ROLE_BUSINESS_OWNER) {
    $uid_check  = (int)$_SESSION['user_id'];
    $sr_check   = $conn->prepare("
        SELECT rsr.role_key
        FROM restaurant_staff rs
        JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
        WHERE rs.user_id = ? AND rs.is_active = 1
        LIMIT 1
    ");
    if ($sr_check) {
        $sr_check->bind_param("i", $uid_check);
        $sr_check->execute();
        $sr_row = $sr_check->get_result()->fetch_assoc();
        $_SESSION['staff_role_key'] = $sr_row ? $sr_row['role_key'] : null;
        $sr_check->close();
    }
}

// Re-derive role flags after potential session patch
$staff_key  = $_SESSION['staff_role_key'] ?? null;
$is_owner   = ($staff_key === null);
$is_mgr     = ($staff_key === STAFF_ROLE_OPS_MANAGER);
$is_cashier = ($staff_key === STAFF_ROLE_CASHIER);

// Ops manager has no business on the POS — send them to their own page
if ($is_mgr) {
    header('Location: ' . BASE_URL . 'dashboard/business_owner/operations_manager.php');
    exit();
}

// Resolve restaurant via shared helper (works for owner AND staff)
$rest_id = getSessionRestaurantId($conn);
$restaurant = null;
if ($rest_id) {
    $rs = $conn->prepare("SELECT id, restaurant_name FROM halal_restaurants WHERE id = ? LIMIT 1");
    $rs->bind_param("i", $rest_id);
    $rs->execute();
    $restaurant = $rs->get_result()->fetch_assoc();
    $rs->close();
}

// Idempotent migrations — ensure payments table has PayMongo columns
db_add_column($conn, 'payments', 'paymongo_checkout_url', 'VARCHAR(500) DEFAULT NULL');
db_add_column($conn, 'payments', 'paymongo_raw_response', 'MEDIUMTEXT DEFAULT NULL');

// POST: place walk-in order (cashier + owner)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order']) && $rest_id) {
    $items_raw     = $_POST['items']           ?? [];
    $payment_meth  = trim(strip_tags($_POST['payment_method'] ?? 'cash'));
    $customer_name = trim(strip_tags($_POST['customer_name']   ?? ''));
    $notes_input   = trim(strip_tags($_POST['notes']           ?? ''));
    $svc_type_raw  = trim(strip_tags($_POST['service_type']    ?? 'dine_in'));
    $valid_methods = ['cash','gcash','bank_transfer','card'];
    $valid_svc     = ['dine_in','takeout'];
    if (!in_array($payment_meth, $valid_methods, true)) $payment_meth = 'cash';
    if (!in_array($svc_type_raw,  $valid_svc,     true)) $svc_type_raw  = 'dine_in';

    $notes_parts = [];
    if ($customer_name !== '') $notes_parts[] = "Customer: {$customer_name}";
    if ($notes_input !== '')   $notes_parts[] = $notes_input;
    $notes = implode(' | ', $notes_parts);

    $line_items = [];
    $subtotal   = 0.0;
    foreach ($items_raw as $row) {
        $mid = (int)($row['id'] ?? 0);
        $qty = max(1, (int)($row['qty'] ?? 1));
        if (!$mid) continue;
        $mi_stmt = $conn->prepare(
            "SELECT mi.id, mi.name, mi.price FROM menu_items mi WHERE mi.id = ? AND mi.restaurant_id = ? AND mi.is_available = 1 AND " . MenuWorkflowService::customerEligibilitySql('mi')
        );
        if ($mi_stmt) {
            $mi_stmt->bind_param("ii", $mid, $rest_id);
            $mi_stmt->execute();
            $mi = $mi_stmt->get_result()->fetch_assoc();
            $mi_stmt->close();
            if (!$mi) continue;
            $lt        = round((float)$mi['price'] * $qty, 2);
            $subtotal += $lt;
            $line_items[] = ['id'=>$mi['id'],'name'=>$mi['name'],'price'=>(float)$mi['price'],'qty'=>$qty,'total'=>$lt];
        }
    }

    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || isset($_POST['ajax']);

    if (empty($line_items)) {
        $error = 'Add at least one item to the order.';
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $error]);
            exit();
        }
    } else {
        $order_num  = 'POS-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $items_json = json_encode($line_items);

        // Cash and card: instant confirmed — money collected in-person
        $online_methods = ['gcash', 'bank_transfer'];
        $is_online = in_array($payment_meth, $online_methods, true);
        $posBilling = new RevenuePaymentService($conn);
        $feeDetails = $posBilling->platformFeeForOrder($subtotal, 0.00, $payment_meth);
        $platform_fee = (float)$feeDetails['amount'];
        $platform_fee_rate = (float)$feeDetails['rate'];
        $order_total = round($subtotal + $platform_fee, 2);

        $pay_status   = $is_online ? 'pending'   : 'paid';
        $order_status = $is_online ? 'pending'    : 'confirmed';

        $ins = $conn->prepare("INSERT INTO orders
            (order_number,customer_id,restaurant_id,items_json,subtotal,
             delivery_fee,total_amount,payment_method,payment_status,
             order_status,service_type,notes,ordered_at,created_at)
            VALUES (?,?,?,?,?,0,?,?,?,?,?,?,NOW(),NOW())");
        if (!$ins) {
            $error = 'DB error: ' . $conn->error;
            if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>$error]); exit(); }
        } else {
            $ins->bind_param("siisddsssss",
                $order_num, $user_id, $rest_id, $items_json,
                $subtotal, $order_total,
                $payment_meth, $pay_status, $order_status, $svc_type_raw, $notes);

            if (!$ins->execute()) {
                $error = 'Failed to save order: ' . $ins->error;
                $ins->close();
                if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>$error]); exit(); }
            } else {
                $order_id = (int)$conn->insert_id;
                $ins->close();
                if ($platform_fee > 0) {
                    $feeUpd = $conn->prepare('UPDATE orders SET platform_fee=?,platform_fee_rate=? WHERE id=?');
                    $feeUpd->bind_param('ddi', $platform_fee, $platform_fee_rate, $order_id);
                    $feeUpd->execute();
                    $feeUpd->close();
                }

                if (IngredientInventoryService::isFeatureEnabled()) {
                    $reservation = (new IngredientInventoryService($conn))->reserveForOrder($order_id, $rest_id, $line_items, $user_id);
                    if (!$reservation['success']) {
                        $del = $conn->prepare('DELETE FROM orders WHERE id = ? AND restaurant_id = ?');
                        $del->bind_param('ii', $order_id, $rest_id); $del->execute(); $del->close();
                        $error = $reservation['message'];
                        if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>$error]); exit(); }
                        $_SESSION['pos_error'] = $error;
                        header('Location: ' . BASE_URL . 'dashboard/business_owner/pos_order_processing.php');
                        exit;
                    }
                }

                // Online payment uses the central checkout request and receives fulfillment only
// from a signed PayMongo webhook. The return URL is informational.
                if ($is_online) {
                    if (!$posBilling->schemaReady()) {
                        (new IngredientInventoryService($conn))->releaseOrderReservations($order_id, $user_id);
                        $conn->query("DELETE FROM orders WHERE id = {$order_id}");
                        $error = 'Online payments are temporarily unavailable while billing is being installed.';
                        if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>$error]); exit(); }
                    } else {
                        $checkout = $posBilling->startPosOrderCheckout($user_id, $rest_id, $order_id);
                        if (!$checkout['success']) {
                            (new IngredientInventoryService($conn))->releaseOrderReservations($order_id, $user_id);
                            $conn->query("DELETE FROM orders WHERE id = {$order_id}");
                            $error = $checkout['message'] ?? 'Payment gateway error.';
                            if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>$error]); exit(); }
                        } else {
                            $legacyRaw = json_encode(['billing_payment_request_id'=>$checkout['payment_request_id'],'internal_reference'=>$checkout['internal_reference']]);
                            $pm_ins = $conn->prepare("INSERT INTO payments (user_id,reference_type,reference_id,amount,payment_method,transaction_reference,paymongo_checkout_url,paymongo_raw_response,status) VALUES (?,'order',?,?,'paymongo',?,?,?,'pending')");
                            if ($pm_ins) {
                                $checkoutId=$checkout['checkout_session_id']; $checkoutUrl=$checkout['checkout_url'];
                                $pm_ins->bind_param('iidsss',$user_id,$order_id,$order_total,$checkoutId,$checkoutUrl,$legacyRaw);
                                $pm_ins->execute(); $pm_ins->close();
                            }
                            if ($is_ajax) {
                                header('Content-Type: application/json');
                                echo json_encode(['success'=>true,'redirect_url'=>$checkout['checkout_url'],'order_number'=>$order_num,'message'=>'Redirecting to secure payment; confirmation follows webhook verification.']);
                                exit();
                            }
                            header('Location: '.$checkout['checkout_url']);
                            exit();
                        }
                    }
                } else {
                    // ── ─ Cash / Card: instant confirmed ────────────────────────
                    $msg = "Order {$order_num} placed — Total: " . formatCurrency($subtotal);
                    if ($is_ajax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true, 'message' => $msg]);
                        exit();
                    }
                    $_SESSION['pos_success'] = $msg;
                    header("Location: " . BASE_URL . "dashboard/business_owner/pos_order_processing.php");
                    exit();
                }
            }
        }
    }
}

// Load menu items — show ALL items so cashier can see what's unavailable (greyed out)
$pos_menu_items  = [];
$menu_by_cat = [];
if ($rest_id) {
    $menu_result = $conn->query(
        "SELECT mi.id, mi.name, mi.price, mi.category, mi.image_path, mi.is_available
         FROM menu_items mi
         WHERE mi.restaurant_id = {$rest_id}
           AND " . MenuWorkflowService::customerEligibilitySql('mi') . "
           AND mi.name IS NOT NULL
           AND mi.name != ''
         ORDER BY mi.is_available DESC, mi.category, mi.name"
    );
    if ($menu_result) {
        while ($m = $menu_result->fetch_assoc()) {
            if (
                !isset($m['id'], $m['name'], $m['price'], $m['category'])
                || trim((string)$m['name']) === ''
            ) continue;
            $pos_menu_items[] = $m;
            $menu_by_cat[(string)($m['category'] ?: 'Other')][] = $m;
        }
        $menu_result->free();
    }
}

// Load today's orders
$todays_orders = [];
if ($rest_id) {
    $to_result = $conn->query(
        "SELECT id, order_number, total_amount, payment_method, order_status, ordered_at, notes
         FROM orders
         WHERE restaurant_id = {$rest_id}
           AND DATE(ordered_at) = CURDATE()
         ORDER BY ordered_at DESC
         LIMIT 30"
    );
    if ($to_result) {
        $todays_orders = $to_result->fetch_all(MYSQLI_ASSOC);
        $to_result->free();
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<?php if (defined('POS_DEBUG') && POS_DEBUG): ?>
<pre style="background:#1e293b;color:#a3e635;padding:16px;font-size:.75rem;border-radius:8px;margin-bottom:16px;overflow-x:auto;">
REST_ID: <?= $rest_id ?>

STAFF_KEY: <?= var_export($_SESSION['staff_role_key'] ?? null, true) ?>

MENU COUNT: <?= count($pos_menu_items) ?>

ITEMS:
<?php foreach ($pos_menu_items as $m): ?> id=<?= $m['id'] ?? '' ?> name="<?= htmlspecialchars($m['name'] ?? '') ?>" price=<?= $m['price'] ?? 0 ?> cat=<?= htmlspecialchars($m['category'] ?? '') ?>
<?php endforeach; ?>
</pre>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i>
    <span><?= htmlspecialchars($error) ?></span>
    <button class="close-alert" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i>
    <span><?= htmlspecialchars($success) ?></span>
    <button class="close-alert" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
</div>
<?php endif; ?>

<?php if (!$rest_id): ?>
<div class="card" style="max-width:520px;margin:0 auto;text-align:center;padding:48px 32px;">
    <div style="font-size:2.5rem;color:var(--neutral-300);margin-bottom:16px;"><i class="fas fa-store-slash"></i></div>
    <h3 style="color:var(--neutral-600);margin-bottom:8px;">No Restaurant Found</h3>
    <p style="color:var(--neutral-400);margin-bottom:24px;">No restaurant is linked to your account.</p>
</div>
<?php else: ?>

<div style="display:grid;grid-template-columns:1fr 380px;gap:20px;align-items:start;">

<!-- LEFT: Menu catalogue -->
<div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;">
        <button class="pos-cat-btn active" data-cat="all" onclick="filterCat('all',this)"
                style="padding:6px 14px;border-radius:20px;border:2px solid var(--primary-600);
                       background:var(--primary-600);color:#fff;font-size:.8rem;font-weight:600;cursor:pointer;">All</button>
        <?php foreach (array_keys($menu_by_cat) as $cat): ?>
        <button class="pos-cat-btn" data-cat="<?= htmlspecialchars((string)$cat, ENT_QUOTES) ?>"
                onclick="filterCat(<?= htmlspecialchars(json_encode((string)$cat), ENT_QUOTES) ?>,this)"
                style="padding:6px 14px;border-radius:20px;border:2px solid var(--neutral-200);
                       background:#fff;color:var(--neutral-600);font-size:.8rem;font-weight:600;cursor:pointer;transition:all .15s;">
            <?= htmlspecialchars((string)$cat) ?>
        </button>
        <?php endforeach; ?>
    </div>

    <?php if (empty($pos_menu_items)): ?>
        <div class="card" style="text-align:center;padding:40px;color:var(--neutral-400);">
            <i class="fas fa-utensils" style="font-size:2rem;margin-bottom:12px;display:block;"></i>
            <p style="margin:0;">No available menu items found for this restaurant.</p>
        </div>
    <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;" id="posMenuGrid">
        <?php foreach ($pos_menu_items as $m):
            $m_id      = (int)($m['id'] ?? 0);
            $m_name    = htmlspecialchars((string)($m['name'] ?? ''), ENT_QUOTES);
            $m_price   = (float)($m['price'] ?? 0);
            $m_cat     = htmlspecialchars((string)($m['category'] ?? 'Other'), ENT_QUOTES);
            $m_img     = !empty($m['image_path']) ? BASE_URL . 'uploads/' . htmlspecialchars((string)$m['image_path']) : '';
            $m_avail   = (int)($m['is_available'] ?? 1) === 1;
            $m_price_fmt = formatCurrency($m_price);
            $m_price_js  = number_format($m_price, 2, '.', '');
        ?>
        <div class="pos-item-card <?= $m_avail ? '' : 'pos-item-unavailable' ?>"
             data-cat="<?= $m_cat ?>"
             data-available="<?= $m_avail ? '1' : '0' ?>"
             <?= $m_avail ? "onclick=\"addToCart({$m_id}, " . htmlspecialchars(json_encode((string)($m['name'] ?? '')), ENT_QUOTES) . ", {$m_price_js})\"" : "title=\"This item is currently unavailable\"" ?>
             style="border:2px solid <?= $m_avail ? 'var(--neutral-200)' : '#e5e7eb' ?>;border-radius:12px;overflow:hidden;
                    cursor:<?= $m_avail ? 'pointer' : 'not-allowed' ?>;
                    transition:border-color .15s,transform .12s;
                    background:<?= $m_avail ? '#fff' : '#f9fafb' ?>;
                    opacity:<?= $m_avail ? '1' : '.55' ?>;
                    position:relative;">
            <?php if (!$m_avail): ?>
            <div style="position:absolute;inset:0;z-index:2;display:flex;align-items:center;justify-content:center;
                        background:rgba(255,255,255,.45);border-radius:10px;">
                <span style="background:#dc2626;color:#fff;font-size:.65rem;font-weight:800;
                             padding:3px 8px;border-radius:20px;letter-spacing:.04em;">SOLD OUT</span>
            </div>
            <?php endif; ?>
            <?php if ($m_img): ?>
                <img src="<?= $m_img ?>" alt="<?= $m_name ?>"
                     style="width:100%;height:90px;object-fit:cover;<?= $m_avail ? '' : 'filter:grayscale(60%)' ?>"
                     onerror="this.parentNode.innerHTML='<div style=\'width:100%;height:90px;background:var(--neutral-100);display:flex;align-items:center;justify-content:center;color:var(--neutral-300);font-size:1.8rem;\'><i class=\'fas fa-utensils\'></i></div>'">
            <?php else: ?>
                <div style="width:100%;height:90px;background:var(--neutral-100);
                            display:flex;align-items:center;justify-content:center;color:var(--neutral-300);font-size:1.8rem;">
                    <i class="fas fa-utensils"></i>
                </div>
            <?php endif; ?>
            <div style="padding:8px 10px;">
                <div style="font-weight:600;font-size:.82rem;color:<?= $m_avail ? 'var(--neutral-800)' : 'var(--neutral-400)' ?>;
                            white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                     title="<?= $m_name ?>">
                    <?= $m_name ?>
                </div>
                <div style="color:var(--primary-700);font-weight:700;font-size:.9rem;margin-top:2px;">
                    <?= $m_price_fmt ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- RIGHT: Order panel & Today's Orders -->
<div style="position:sticky;top:20px;">
    <form method="POST" id="posForm">
    <input type="hidden" name="place_order" value="1">
    <div class="card" style="border:2px solid var(--primary-200);">
        <div class="card-header" style="background:var(--primary-700);color:#fff;border-radius:14px 14px 0 0;">
            <h3 style="color:#fff;"><i class="fas fa-cash-register" style="margin-right:8px;"></i>Current Order</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <div id="cartLines" style="min-height:80px;max-height:320px;overflow-y:auto;padding:8px 0;">
                <div id="emptyCartMsg" style="text-align:center;padding:28px;color:var(--neutral-400);font-size:.875rem;">
                    <i class="fas fa-shopping-cart" style="font-size:1.5rem;margin-bottom:8px;display:block;"></i>
                    Tap items to add them
                </div>
            </div>
            <div style="padding:12px 16px;border-top:1px solid var(--neutral-200);">
                <div class="form-group" style="margin-bottom:10px;">
                    <label class="form-label" style="font-size:.78rem;">Service Type</label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                        <label id="svc_dine_label"
                               style="display:flex;align-items:center;justify-content:center;gap:6px;
                                      cursor:pointer;border:2px solid var(--primary-600);border-radius:8px;
                                      padding:8px 10px;font-size:.82rem;font-weight:600;
                                      background:var(--primary-600);color:#fff;transition:all .15s;">
                            <input type="radio" name="service_type" value="dine_in" checked
                                   style="display:none;" onchange="updateSvcLabel()">
                            🍽️ Dine-in
                        </label>
                        <label id="svc_take_label"
                               style="display:flex;align-items:center;justify-content:center;gap:6px;
                                      cursor:pointer;border:2px solid var(--neutral-200);border-radius:8px;
                                      padding:8px 10px;font-size:.82rem;font-weight:600;
                                      background:#fff;color:var(--neutral-600);transition:all .15s;">
                            <input type="radio" name="service_type" value="takeout"
                                   style="display:none;" onchange="updateSvcLabel()">
                            🛍️ Takeout
                        </label>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:10px;">
                    <label class="form-label" style="font-size:.78rem;">Table / Reference <span style="font-weight:400;color:var(--neutral-400);">(optional)</span></label>
                    <input type="text" name="customer_name" class="form-control" placeholder="e.g. Table 3, Walk-in" maxlength="100" style="font-size:.875rem;">
                </div>
                <div class="form-group" style="margin-bottom:10px;">
                    <label class="form-label" style="font-size:.78rem;">Payment Method</label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                        <?php foreach(['cash'=>'Cash','gcash'=>'GCash','card'=>'Card','bank_transfer'=>'Bank'] as $v=>$l): ?>
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;border:1px solid var(--neutral-200);border-radius:8px;padding:6px 10px;font-size:.8rem;font-weight:500;">
                            <input type="radio" name="payment_method" value="<?= $v ?>" <?= $v==='cash'?'checked':'' ?> style="accent-color:var(--primary-600);">
                            <?= $l ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label" style="font-size:.78rem;">Notes (optional)</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Allergies, special requests…" maxlength="255" style="font-size:.82rem;"></textarea>
                </div>
                <div style="background:var(--neutral-50);border-radius:10px;padding:12px 14px;margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:.8rem;color:var(--neutral-500);margin-bottom:4px;">
                        <span>Subtotal</span>
                        <span id="cartSubtotal" style="font-weight:600;color:var(--neutral-700);">₱0.00</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;padding-top:6px;border-top:1px dashed var(--neutral-200);">
                        <span style="font-size:.875rem;font-weight:700;color:var(--neutral-700);">Grand Total</span>
                        <span id="cartTotal" style="font-size:1.4rem;font-weight:800;color:var(--primary-700);">₱0.00</span>
                    </div>
                </div>
                <button type="submit" id="posSubmitBtn" class="btn btn-primary" style="width:100%;font-size:.95rem;padding:12px;" disabled>
                    <i class="fas fa-check-circle"></i> Confirm &amp; Place Order
                </button>
                <button type="button" class="btn btn-outline" style="width:100%;margin-top:8px;font-size:.85rem;" onclick="clearCart()">
                    <i class="fas fa-trash"></i> Clear Cart
                </button>
            </div>
        </div>
    </div>
    </form>

    <!-- Awaiting Cash Payment Section -->
    <div class="card" style="margin-top:16px;">
        <div class="card-header" style="padding:12px 16px;background:linear-gradient(135deg,#f59e0b,#d97706);border-radius:12px 12px 0 0;">
            <h3 style="font-size:.88rem;color:#fff;margin:0;display:flex;align-items:center;justify-content:space-between;">
                <span><i class="fas fa-cash-register" style="margin-right:6px;"></i>Awaiting Cash Payment</span>
                <span id="awaiting-cash-badge" style="background:rgba(255,255,255,0.3);color:#fff;padding:2px 8px;border-radius:12px;font-size:.75rem;font-weight:700;">0</span>
            </h3>
        </div>
        <div class="card-body" id="awaiting-cash-container" style="padding:0;max-height:280px;overflow-y:auto;">
            <div style="text-align:center;padding:16px;color:var(--neutral-400);font-size:.82rem;">
                <i class="fas fa-spinner fa-spin" style="font-size:1.2rem;margin-bottom:8px;display:block;"></i>
                Loading pending cash payments...
            </div>
        </div>
    </div>

    <!-- Customer Orders Queue (Paid orders from customers) -->
    <div class="card" style="margin-top:16px;">
        <div class="card-header" style="padding:12px 16px;background:linear-gradient(135deg,#10b981,#059669);border-radius:12px 12px 0 0;">
            <h3 style="font-size:.88rem;color:#fff;margin:0;display:flex;align-items:center;justify-content:space-between;">
                <span><i class="fas fa-bell" style="margin-right:6px;"></i>Customer Orders</span>
                <span id="customer-order-badge" style="background:rgba(255,255,255,0.3);color:#fff;padding:2px 8px;border-radius:12px;font-size:.75rem;font-weight:700;">0</span>
            </h3>
        </div>
        <div class="card-body" id="customer-orders-container" style="padding:0;max-height:320px;overflow-y:auto;">
            <div style="text-align:center;padding:16px;color:var(--neutral-400);font-size:.82rem;">
                <i class="fas fa-spinner fa-spin" style="font-size:1.2rem;margin-bottom:8px;display:block;"></i>
                Loading customer orders...
            </div>
        </div>
    </div>

    <!-- Today's orders list -->
    <div class="card" style="margin-top:16px;">
        <div class="card-header" style="padding:12px 16px;">
            <h3 style="font-size:.88rem;"><i class="fas fa-history" style="margin-right:6px;color:var(--accent-500);"></i>Today's Orders</h3>
        </div>
        <div class="card-body" style="padding:0;max-height:280px;overflow-y:auto;">
        <?php if (empty($todays_orders)): ?>
            <p style="text-align:center;padding:16px;color:var(--neutral-400);font-size:.82rem;">No orders yet today.</p>
        <?php else: ?>
            <?php foreach ($todays_orders as $to): ?>
            <div style="padding:9px 14px;border-bottom:1px solid var(--neutral-100);">
                <div style="display:flex;justify-content:space-between;align-items:center;font-size:.8rem;">
                    <div>
                        <strong><?= htmlspecialchars($to['order_number']) ?></strong>
                        <div style="color:var(--neutral-400);"><?= date('h:i A',strtotime($to['ordered_at'])) ?></div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-weight:700;color:var(--primary-700);"><?= formatCurrency($to['total_amount']) ?></div>
                        <?= getStatusBadge($to['order_status']) ?>
                    </div>
                </div>
                <?php if ($to['order_status'] !== 'cancelled' && $to['order_status'] !== 'delivered'): ?>
                <div style="margin-top:4px;">
                    <a href="<?= BASE_URL ?>dashboard/business_owner/manage_orders.php"
                       style="font-size:.75rem;color:var(--primary-600);font-weight:500;">
                        <i class="fas fa-external-link-alt"></i> Update in Orders
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>
    </div>
</div>
</div>
<?php endif; ?>

<?php
$extra_js = <<<'EOJS'
<script>
// ── Cart State & Methods ──────────────────────────────────────────────────
const cartItems = {};
const cart = cartItems; // Backwards compatibility alias

function addToCart(id, name, price) {
    if (!id) return;
    if (cartItems[id]) {
        cartItems[id].qty++;
    } else {
        cartItems[id] = { id, name, price: parseFloat(price) || 0, qty: 1 };
    }
    renderCart();
}

function updateQty(id, delta) {
    if (!cartItems[id]) return;
    cartItems[id].qty += delta;
    if (cartItems[id].qty <= 0) {
        delete cartItems[id];
    }
    renderCart();
}

function setQty(id, value) {
    if (!cartItems[id]) return;
    let qty = parseInt(value, 10);
    if (isNaN(qty) || qty <= 0) {
        delete cartItems[id];
    } else {
        cartItems[id].qty = Math.min(999, qty);
    }
    renderCart();
}

function removeItem(id) {
    if (cartItems[id]) {
        delete cartItems[id];
        renderCart();
    }
}

function clearCart() {
    Object.keys(cartItems).forEach(k => delete cartItems[k]);
    renderCart();
}

function renderCart() {
    const lines      = document.getElementById('cartLines');
    const empty      = document.getElementById('emptyCartMsg');
    const subtotalEl = document.getElementById('cartSubtotal');
    const totalEl    = document.getElementById('cartTotal');
    const btn        = document.getElementById('posSubmitBtn');
    const form       = document.getElementById('posForm');
    if (!form) return;

    form.querySelectorAll('.cart-hidden').forEach(e => e.remove());
    const ids = Object.keys(cartItems);

    if (!ids.length) {
        lines.innerHTML = '';
        if (empty) {
            lines.appendChild(empty);
            empty.style.display = 'block';
        }
        if (subtotalEl) subtotalEl.textContent = '₱0.00';
        if (totalEl) totalEl.textContent = '₱0.00';
        if (btn) btn.disabled = true;
        return;
    }

    if (empty) empty.style.display = 'none';

    let html = '', grandTotal = 0, idx = 0;

    ids.forEach(id => {
        const item = cartItems[id];
        const lineTotal = item.price * item.qty;
        grandTotal += lineTotal;

        const hi = (n, v) => {
            const i = document.createElement('input');
            i.type = 'hidden';
            i.name = `items[${idx}][${n}]`;
            i.value = v;
            i.className = 'cart-hidden';
            form.appendChild(i);
        };
        hi('id', item.id);
        hi('qty', item.qty);
        idx++;

        html += `<div class="cart-item-row" style="display:flex;align-items:center;gap:8px;padding:9px 12px;border-bottom:1px solid var(--neutral-100);">
            <div style="flex:1;min-width:0;">
                <div style="font-size:.82rem;font-weight:600;color:var(--neutral-800);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escHtml(item.name)}">${escHtml(item.name)}</div>
                <div style="font-size:.74rem;color:var(--neutral-400);font-weight:500;">₱${item.price.toFixed(2)} each</div>
            </div>
            <div style="display:flex;align-items:center;gap:3px;background:var(--neutral-50);padding:2px 4px;border-radius:6px;border:1px solid var(--neutral-200);">
                <button type="button" class="qty-btn" onclick="updateQty(${item.id},-1)" style="width:22px;height:22px;border-radius:4px;border:1px solid var(--neutral-300);background:#fff;cursor:pointer;font-weight:700;font-size:.8rem;display:flex;align-items:center;justify-content:center;">−</button>
                <input type="number" class="qty-input" value="${item.qty}" min="1" max="999" onchange="setQty(${item.id},this.value)" oninput="setQty(${item.id},this.value)" style="width:36px;height:22px;text-align:center;font-weight:700;font-size:.8rem;border:1px solid var(--neutral-200);border-radius:4px;background:#fff;padding:0;-moz-appearance:textfield;">
                <button type="button" class="qty-btn" onclick="updateQty(${item.id},1)" style="width:22px;height:22px;border-radius:4px;border:1px solid var(--neutral-300);background:#fff;cursor:pointer;font-weight:700;font-size:.8rem;display:flex;align-items:center;justify-content:center;">+</button>
            </div>
            <div style="min-width:58px;text-align:right;font-weight:700;font-size:.85rem;color:var(--primary-700);">₱${lineTotal.toFixed(2)}</div>
            <button type="button" class="remove-btn" onclick="removeItem(${item.id})" title="Remove item" style="background:none;border:none;color:var(--neutral-400);cursor:pointer;font-size:.82rem;padding:3px;margin-left:2px;transition:color .15s;" onmouseenter="this.style.color='var(--danger-600)'" onmouseleave="this.style.color='var(--neutral-400)'">
                <i class="fas fa-trash-alt"></i>
            </button>
        </div>`;
    });

    lines.innerHTML = html;
    if (subtotalEl) subtotalEl.textContent = '₱' + grandTotal.toFixed(2);
    if (totalEl) totalEl.textContent = '₱' + grandTotal.toFixed(2);
    if (btn) btn.disabled = false;
}
function escHtml(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

// ── POS Form AJAX submission ────────────────────────────────────────────────
const posFormEl = document.getElementById('posForm');
if (posFormEl) {
    posFormEl.addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('posSubmitBtn');
        if (!btn || btn.disabled) return;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Placing Order…';

        const fd = new FormData(this);
        fd.append('ajax', '1');

        try {
            const res = await fetch(window.location.href, {
                method: 'POST',
                body: fd,
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            const data = await res.json();
            if (data.success) {
                if (data.redirect_url) {
                    // PayMongo checkout — navigate to payment page
                    window.location.href = data.redirect_url;
                    return; // don't re-enable the button
                }
                clearCart();
                const custInput = document.querySelector('input[name="customer_name"]');
                const notesInput = document.querySelector('textarea[name="notes"]');
                if (custInput) custInput.value = '';
                if (notesInput) notesInput.value = '';
                window.location.reload();
            } else {
                alert(data.message || 'Failed to place order.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm &amp; Place Order';
            }
        } catch (err) {
            this.submit();
        }
    });
}

// ── Service type toggle styling ───────────────────────────────────────────────
function updateSvcLabel() {
    const dineIn  = document.querySelector('input[name="service_type"][value="dine_in"]');
    const takeout = document.querySelector('input[name="service_type"][value="takeout"]');
    const lD = document.getElementById('svc_dine_label');
    const lT = document.getElementById('svc_take_label');
    if (!lD || !lT) return;
    const active  = 'border:2px solid var(--primary-600);background:var(--primary-600);color:#fff;';
    const inactive= 'border:2px solid var(--neutral-200);background:#fff;color:var(--neutral-600);';
    lD.style.cssText += dineIn?.checked  ? active : inactive;
    lT.style.cssText += takeout?.checked ? active : inactive;
}
// Init on page load
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('input[name="service_type"]').forEach(r =>
        r.addEventListener('change', updateSvcLabel)
    );
    updateSvcLabel();
});

// ── Category filter ───────────────────────────────────────────────────────────
function filterCat(cat, btn) {
    document.querySelectorAll('.pos-cat-btn').forEach(b => {
        b.classList.remove('active');
        b.style.background  = '#fff';
        b.style.color       = 'var(--neutral-600)';
        b.style.borderColor = 'var(--neutral-200)';
    });
    if (btn) {
        btn.classList.add('active');
        btn.style.background  = 'var(--primary-600)';
        btn.style.color       = '#fff';
        btn.style.borderColor = 'var(--primary-600)';
    }
    document.querySelectorAll('.pos-item-card').forEach(c => {
        const itemCat = c.getAttribute('data-cat') || '';
        c.style.display = (cat === 'all' || itemCat === cat) ? '' : 'none';
    });
}
document.querySelectorAll('.pos-item-card').forEach(c => {
    if (c.dataset.available === '0') return; // skip unavailable
    c.addEventListener('mouseenter', ()=>{ c.style.borderColor='var(--primary-400)'; c.style.transform='translateY(-2px)'; });
    c.addEventListener('mouseleave', ()=>{ c.style.borderColor='var(--neutral-200)'; c.style.transform=''; });
});

// ── Customer Orders Queue ──────────────────────────────────────────────────
async function fetchCustomerOrders() {
    try {
        const response = await fetch('<?= BASE_URL ?>dashboard/business_owner/api/get_cashier_orders.php');
        const data = await response.json();
        
        if (data.success) {
            renderCustomerOrders(data.orders);
            updateCustomerOrderBadge(data.order_count);
        } else {
            document.getElementById('customer-orders-container').innerHTML = 
                '<div style="text-align:center;padding:16px;color:var(--danger-500);font-size:.82rem;">' +
                '<i class="fas fa-exclamation-circle"></i> ' + (data.message || 'Failed to load orders') +
                '</div>';
        }
    } catch (error) {
        console.error('Error fetching customer orders:', error);
        document.getElementById('customer-orders-container').innerHTML = 
            '<div style="text-align:center;padding:16px;color:var(--danger-500);font-size:.82rem;">' +
            '<i class="fas fa-exclamation-circle"></i> Network error' +
            '</div>';
    }
}

// ── Awaiting Cash Payment Section ──────────────────────────────────────────
async function fetchAwaitingCashOrders() {
    try {
        const response = await fetch('<?= BASE_URL ?>dashboard/business_owner/api/get_cashier_orders.php?payment_status=awaiting_cash');
        const data = await response.json();
        
        if (data.success) {
            renderAwaitingCashOrders(data.orders);
            updateAwaitingCashBadge(data.order_count);
        } else {
            document.getElementById('awaiting-cash-container').innerHTML = 
                '<div style="text-align:center;padding:16px;color:var(--danger-500);font-size:.82rem;">' +
                '<i class="fas fa-exclamation-circle"></i> ' + (data.message || 'Failed to load orders') +
                '</div>';
        }
    } catch (error) {
        console.error('Error fetching awaiting cash orders:', error);
        document.getElementById('awaiting-cash-container').innerHTML = 
            '<div style="text-align:center;padding:16px;color:var(--danger-500);font-size:.82rem;">' +
            '<i class="fas fa-exclamation-circle"></i> Network error' +
            '</div>';
    }
}

function renderAwaitingCashOrders(orders) {
    const container = document.getElementById('awaiting-cash-container');
    
    if (!orders || orders.length === 0) {
        container.innerHTML = '<p style="text-align:center;padding:16px;color:var(--neutral-400);font-size:.82rem;">No pending cash payments.</p>';
        return;
    }
    
    let html = '';
    orders.forEach(order => {
        const serviceIcons = {
            'dine_in': '🍽️',
            'takeout': '🛍️',
            'delivery': '🚗'
        };
        const serviceIcon = serviceIcons[order.service_type] || '📦';
        
        html += `
            <div class="awaiting-cash-item" data-order-id="${order.id}" style="padding:12px 14px;border-bottom:1px solid var(--neutral-100);transition:background .15s;" 
                 onmouseover="this.style.background='#fffbeb'" onmouseout="this.style.background='#fff'">
                <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:6px;">
                    <div>
                        <strong style="font-size:.85rem;color:var(--neutral-800);">${order.order_number}</strong>
                        ${order.table_number ? `<span style="margin-left:6px;background:#fef3c7;color:#92400e;padding:2px 6px;border-radius:4px;font-size:.7rem;font-weight:600;">Table ${order.table_number}</span>` : ''}
                    </div>
                    <span style="background:#f59e0b;color:#fff;padding:2px 8px;border-radius:12px;font-size:.7rem;font-weight:700;">AWAITING CASH</span>
                </div>
                <div style="font-size:.75rem;color:var(--neutral-500);margin-bottom:8px;">
                    ${serviceIcon} ${order.service_type.replace('_', '-')} • ${order.customer_name}
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <span style="font-size:.75rem;color:var(--neutral-600);">${order.item_count} item${order.item_count !== 1 ? 's' : ''}</span>
                    <span style="font-weight:700;font-size:1.1rem;color:#dc2626;">₱${order.total_amount.toFixed(2)}</span>
                </div>
                <button onclick="showReceiveCashModal(${order.id}, ${order.total_amount}, '${order.order_number}')" 
                        class="btn btn-warning btn-sm" 
                        style="width:100%;padding:8px;font-weight:700;display:flex;align-items:center;justify-content:center;gap:6px;">
                    <i class="fas fa-hand-holding-usd"></i>
                    <span>Receive Cash Payment</span>
                </button>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

function updateAwaitingCashBadge(count) {
    const badge = document.getElementById('awaiting-cash-badge');
    if (badge) {
        badge.textContent = count;
        badge.style.display = count > 0 ? 'inline-block' : 'none';
    }
}

function renderCustomerOrders(orders) {
    const container = document.getElementById('customer-orders-container');
    
    if (!orders || orders.length === 0) {
        container.innerHTML = '<p style="text-align:center;padding:16px;color:var(--neutral-400);font-size:.82rem;">No pending customer orders.</p>';
        return;
    }
    
    let html = '';
    orders.forEach(order => {
        const statusColors = {
            'confirmed': '#10b981',
            'preparing': '#f59e0b',
            'ready': '#3b82f6',
            'completed': '#6b7280'
        };
        const statusColor = statusColors[order.order_status] || '#6b7280';
        
        const serviceIcons = {
            'dine_in': '🍽️',
            'takeout': '🛍️',
            'delivery': '🚗'
        };
        const serviceIcon = serviceIcons[order.service_type] || '📦';
        
        html += `
            <div class="customer-order-item" data-order-id="${order.id}" style="padding:12px 14px;border-bottom:1px solid var(--neutral-100);cursor:pointer;transition:background .15s;" 
                 onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'"
                 onclick="showOrderDetails(${order.id}, ${JSON.stringify(order).replace(/"/g, '&quot;')})">
                <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:6px;">
                    <div>
                        <strong style="font-size:.85rem;color:var(--neutral-800);">${order.order_number}</strong>
                        ${order.table_number ? `<span style="margin-left:6px;background:#f0f9ff;color:#0369a1;padding:2px 6px;border-radius:4px;font-size:.7rem;font-weight:600;">Table ${order.table_number}</span>` : ''}
                    </div>
                    <span style="background:${statusColor};color:#fff;padding:2px 8px;border-radius:12px;font-size:.7rem;font-weight:700;text-transform:uppercase;">${order.order_status}</span>
                </div>
                <div style="font-size:.75rem;color:var(--neutral-500);margin-bottom:4px;">
                    ${serviceIcon} ${order.service_type.replace('_', '-')} • ${order.customer_name}
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:.75rem;color:var(--neutral-600);">${order.item_count} item${order.item_count !== 1 ? 's' : ''}</span>
                    <span style="font-weight:700;color:var(--primary-700);">₱${order.total_amount.toFixed(2)}</span>
                </div>
                ${order.is_paid ? '<div style="margin-top:6px;font-size:.7rem;color:#059669;font-weight:600;"><i class="fas fa-check-circle"></i> PAID</div>' : ''}
            </div>
        `;
    });
    
    container.innerHTML = html;
}

function updateCustomerOrderBadge(count) {
    const badge = document.getElementById('customer-order-badge');
    if (badge) {
        badge.textContent = count;
        badge.style.display = count > 0 ? 'inline-block' : 'none';
    }
}

function showOrderDetails(orderId, orderData) {
    const items = orderData.items || [];
    let itemsHtml = '<div style="max-height:200px;overflow-y:auto;margin:16px 0;">';
    items.forEach(item => {
        itemsHtml += `
            <div style="display:flex;justify-content:space-between;padding:8px;border-bottom:1px solid #e5e7eb;">
                <span><strong>${item.name}</strong> × ${item.qty}</span>
                <span>₱${(item.price * item.qty).toFixed(2)}</span>
            </div>
        `;
    });
    itemsHtml += '</div>';
    
    const statusButtons = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:16px;">
            ${orderData.order_status === 'confirmed' ? 
                '<button onclick="updateOrderStatus('+orderId+', \'preparing\')" class="btn btn-warning btn-sm" style="width:100%;">Start Preparing</button>' : ''}
            ${orderData.order_status === 'preparing' ? 
                '<button onclick="updateOrderStatus('+orderId+', \'ready\')" class="btn btn-primary btn-sm" style="width:100%;">Mark Ready</button>' : ''}
            ${orderData.order_status === 'ready' ? 
                '<button onclick="updateOrderStatus('+orderId+', \'completed\')" class="btn btn-success btn-sm" style="width:100%;">Complete Order</button>' : ''}
        </div>
    `;
    
    const modalHtml = `
        <div style="position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;" onclick="this.remove()">
            <div style="background:#fff;border-radius:12px;max-width:500px;width:100%;padding:24px;" onclick="event.stopPropagation()">
                <h3 style="margin:0 0 16px;font-size:1.2rem;color:var(--neutral-800);">
                    Order ${orderData.order_number}
                    <button onclick="this.closest('[style*=\\'position:fixed\\']').remove()" style="float:right;border:none;background:transparent;cursor:pointer;font-size:1.5rem;color:var(--neutral-400);">×</button>
                </h3>
                <div style="background:#f8fafc;padding:12px;border-radius:8px;margin-bottom:12px;">
                    <div style="font-size:.85rem;color:var(--neutral-600);margin-bottom:4px;">Customer: <strong>${orderData.customer_name}</strong></div>
                    <div style="font-size:.85rem;color:var(--neutral-600);margin-bottom:4px;">Service: <strong>${orderData.service_type.replace('_', '-')}</strong></div>
                    ${orderData.table_number ? `<div style="font-size:.85rem;color:var(--neutral-600);">Table: <strong>${orderData.table_number}</strong></div>` : ''}
                </div>
                ${itemsHtml}
                <div style="border-top:2px solid var(--neutral-200);padding-top:12px;">
                    <div style="display:flex;justify-content:space-between;font-size:.9rem;margin-bottom:4px;">
                        <span>Subtotal:</span><span>₱${orderData.subtotal.toFixed(2)}</span>
                    </div>
                    ${orderData.delivery_fee > 0 ? `<div style="display:flex;justify-content:space-between;font-size:.9rem;margin-bottom:4px;"><span>Delivery Fee:</span><span>₱${orderData.delivery_fee.toFixed(2)}</span></div>` : ''}
                    <div style="display:flex;justify-content:space-between;font-size:1.1rem;font-weight:700;color:var(--primary-700);">
                        <span>Total:</span><span>₱${orderData.total_amount.toFixed(2)}</span>
                    </div>
                </div>
                ${statusButtons}
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

async function updateOrderStatus(orderId, newStatus) {
    try {
        const formData = new FormData();
        formData.append('order_id', orderId);
        formData.append('new_status', newStatus);
        
        const response = await fetch('<?= BASE_URL ?>dashboard/business_owner/api/update_order_status.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Close modal
            document.querySelector('[style*="position:fixed"]')?.remove();
            // Refresh orders
            fetchCustomerOrders();
            alert('Order status updated to: ' + newStatus);
        } else {
            alert('Failed to update order: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error updating order status:', error);
        alert('Network error. Please try again.');
    }
}

// ── Cash Confirmation Modal ─────────────────────────────────────────────────
function showReceiveCashModal(orderId, amountDue, orderNumber) {
    // Remove existing modal if any
    const existingModal = document.getElementById('cash-confirmation-modal');
    if (existingModal) existingModal.remove();
    
    const modal = document.createElement('div');
    modal.id = 'cash-confirmation-modal';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:10000;' +
        'display:flex;align-items:center;justify-content:center;padding:20px;animation:fadeIn 0.2s;';
    
    modal.innerHTML = `
        <div style="background:#fff;border-radius:16px;max-width:480px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,0.3);overflow:hidden;">
            <!-- Header -->
            <div style="padding:20px 24px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <h3 style="margin:0;font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:8px;">
                        <i class="fas fa-cash-register"></i>
                        <span>Receive Cash Payment</span>
                    </h3>
                    <button onclick="closeCashModal()" style="background:transparent;border:none;color:#fff;font-size:1.3rem;cursor:pointer;width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:8px;transition:background .15s;" onmouseover="this.style.background='rgba(255,255,255,0.2)'" onmouseout="this.style.background='transparent'">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            
            <!-- Body -->
            <div style="padding:24px;">
                <!-- Order Info -->
                <div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:12px;border-radius:8px;margin-bottom:20px;">
                    <div style="font-size:0.85rem;color:#92400e;margin-bottom:4px;">Order Number</div>
                    <div style="font-size:1.1rem;font-weight:700;color:#78350f;">${orderNumber}</div>
                </div>
                
                <!-- Amount Due -->
                <div style="margin-bottom:20px;">
                    <label style="display:block;font-weight:600;margin-bottom:8px;font-size:0.9rem;color:#374151;">
                        Amount Due
                    </label>
                    <div style="background:#f3f4f6;padding:14px;border-radius:10px;font-size:1.5rem;font-weight:800;color:#dc2626;text-align:center;">
                        ₱${amountDue.toFixed(2)}
                    </div>
                </div>
                
                <!-- Cash Received Input -->
                <div style="margin-bottom:20px;">
                    <label for="cash-received-input" style="display:block;font-weight:600;margin-bottom:8px;font-size:0.9rem;color:#374151;">
                        Cash Received <span style="color:#dc2626;">*</span>
                    </label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:1.2rem;font-weight:700;color:#6b7280;">₱</span>
                        <input type="number" 
                               id="cash-received-input" 
                               step="0.01" 
                               min="0"
                               placeholder="0.00"
                               style="width:100%;padding:14px 14px 14px 38px;border:2px solid #d1d5db;border-radius:10px;font-size:1.2rem;font-weight:700;text-align:left;transition:border-color .2s;"
                               oninput="calculateChange(${amountDue})"
                               onfocus="this.style.borderColor='#f59e0b'"
                               onblur="this.style.borderColor='#d1d5db'">
                    </div>
                    <div id="cash-error" style="display:none;margin-top:8px;color:#dc2626;font-size:0.8rem;font-weight:600;">
                        <i class="fas fa-exclamation-circle"></i> <span></span>
                    </div>
                </div>
                
                <!-- Change Display -->
                <div id="change-display" style="display:none;margin-bottom:20px;">
                    <label style="display:block;font-weight:600;margin-bottom:8px;font-size:0.9rem;color:#374151;">
                        Change
                    </label>
                    <div style="background:#dcfce7;border:2px solid #16a34a;padding:14px;border-radius:10px;font-size:1.3rem;font-weight:800;color:#15803d;text-align:center;">
                        ₱<span id="change-amount">0.00</span>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div style="display:grid;grid-template-columns:1fr 2fr;gap:10px;margin-top:24px;">
                    <button onclick="closeCashModal()" 
                            class="btn btn-secondary"
                            style="padding:12px;font-weight:700;border-radius:10px;">
                        Cancel
                    </button>
                    <button id="confirm-cash-btn" 
                            onclick="confirmCashPayment(${orderId}, ${amountDue})" 
                            class="btn btn-success"
                            style="padding:12px;font-weight:700;border-radius:10px;background:linear-gradient(135deg,#16a34a,#15803d);display:flex;align-items:center;justify-content:center;gap:8px;"
                            disabled>
                        <i class="fas fa-check-circle"></i>
                        <span>Confirm Payment</span>
                    </button>
                </div>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    // Focus on input
    setTimeout(() => {
        document.getElementById('cash-received-input')?.focus();
    }, 100);
    
    // Close on backdrop click
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeCashModal();
    });
    
    // Handle Enter key
    document.getElementById('cash-received-input')?.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            const btn = document.getElementById('confirm-cash-btn');
            if (btn && !btn.disabled) {
                confirmCashPayment(orderId, amountDue);
            }
        }
    });
}

function calculateChange(amountDue) {
    const input = document.getElementById('cash-received-input');
    const cashReceived = parseFloat(input.value) || 0;
    const errorDiv = document.getElementById('cash-error');
    const errorSpan = errorDiv.querySelector('span');
    const changeDisplay = document.getElementById('change-display');
    const changeAmount = document.getElementById('change-amount');
    const confirmBtn = document.getElementById('confirm-cash-btn');
    
    // Reset error
    errorDiv.style.display = 'none';
    input.style.borderColor = '#d1d5db';
    
    if (cashReceived <= 0) {
        changeDisplay.style.display = 'none';
        confirmBtn.disabled = true;
        confirmBtn.style.opacity = '0.5';
        confirmBtn.style.cursor = 'not-allowed';
        return;
    }
    
    if (cashReceived < amountDue) {
        const shortage = amountDue - cashReceived;
        errorDiv.style.display = 'block';
        errorSpan.textContent = `Insufficient amount. Short by ₱${shortage.toFixed(2)}`;
        input.style.borderColor = '#dc2626';
        changeDisplay.style.display = 'none';
        confirmBtn.disabled = true;
        confirmBtn.style.opacity = '0.5';
        confirmBtn.style.cursor = 'not-allowed';
        return;
    }
    
    // Valid amount
    const change = cashReceived - amountDue;
    changeAmount.textContent = change.toFixed(2);
    changeDisplay.style.display = 'block';
    confirmBtn.disabled = false;
    confirmBtn.style.opacity = '1';
    confirmBtn.style.cursor = 'pointer';
}

async function confirmCashPayment(orderId, amountDue) {
    const input = document.getElementById('cash-received-input');
    const cashReceived = parseFloat(input.value) || 0;
    const confirmBtn = document.getElementById('confirm-cash-btn');
    
    if (cashReceived < amountDue) {
        alert('Insufficient cash amount received.');
        return;
    }
    
    // Disable button and show loading
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    
    try {
        const response = await fetch('<?= BASE_URL ?>dashboard/business_owner/api/confirm_cash_payment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                order_id: orderId,
                cash_received: cashReceived
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            closeCashModal();
            
            // Show success message with change info
            const changeMsg = data.change_amount > 0 
                ? `\n\nChange: ₱${data.change_amount}` 
                : '';
            alert(`✅ Payment Confirmed!\n\nOrder: ${data.order_number}\nCash Received: ₱${data.cash_received}\nAmount Due: ₱${data.amount_due}${changeMsg}`);
            
            // Refresh both order lists
            fetchAwaitingCashOrders();
            fetchCustomerOrders();
        } else {
            // Check if already confirmed
            if (data.already_confirmed) {
                alert('⚠️ ' + data.message);
                closeCashModal();
                fetchAwaitingCashOrders();
                fetchCustomerOrders();
            } else {
                alert('❌ Error: ' + (data.message || 'Failed to confirm payment'));
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<i class="fas fa-check-circle"></i> <span>Confirm Payment</span>';
            }
        }
    } catch (error) {
        console.error('Error confirming cash payment:', error);
        alert('❌ Network error. Please try again.');
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-check-circle"></i> <span>Confirm Payment</span>';
    }
}

function closeCashModal() {
    const modal = document.getElementById('cash-confirmation-modal');
    if (modal) {
        modal.style.opacity = '0';
        setTimeout(() => modal.remove(), 200);
    }
}

// Fetch customer orders on page load
fetchCustomerOrders();
fetchAwaitingCashOrders();

// Auto-refresh every 30 seconds
setInterval(() => {
    fetchCustomerOrders();
    fetchAwaitingCashOrders();
}, 30000);
</script>
EOJS;
require_once __DIR__ . '/../../includes/footer.php';
