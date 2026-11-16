<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);

$page_title   = 'Manage Orders';
$page_heading = 'Manage Orders';
$is_dashboard = true;
$user_id      = (int)$_SESSION['user_id'];

// ── Session refresh: ensure staff_role_key is always current ─────────────────
if ((int)($_SESSION['role_id'] ?? 0) === ROLE_BUSINESS_OWNER) {
    $sr = $conn->prepare("
        SELECT rsr.role_key
        FROM restaurant_staff rs
        JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
        WHERE rs.user_id = ? AND rs.is_active = 1 LIMIT 1
    ");
    if ($sr) {
        $sr->bind_param("i", $user_id);
        $sr->execute();
        $sr_row = $sr->get_result()->fetch_assoc();
        $_SESSION['staff_role_key'] = $sr_row ? $sr_row['role_key'] : null;
        $sr->close();
    }
}

// ── Resolve restaurant for both owner AND cashier/ops_manager ────────────────
$rest_id         = getSessionRestaurantId($conn);
$staff_key       = $_SESSION['staff_role_key'] ?? null;
$is_cashier_view = ($staff_key === STAFF_ROLE_CASHIER);

// ── Status progression rules ──────────────────────────────────────────────────
// POS orders (dine_in / takeout): preparing → ready → served (delivered)
// Customer delivery orders:       preparing → ready → out_for_delivery → delivered
// "delivered" is the terminal success state for all types.
// Label overrides per service type so cashier sees "Served" for dine-in/takeout.
$status_labels = [
    'pending'          => 'Pending',
    'confirmed'        => 'Confirmed',
    'preparing'        => 'Preparing',
    'ready'            => 'Ready',
    'out_for_delivery' => 'Out for Delivery',
    'delivered'        => 'Delivered / Served',
    'cancelled'        => 'Cancelled',
];

// Terminal statuses — no further action possible
$terminal_statuses = ['delivered', 'cancelled'];

// Next-step map per service_type
$next_by_service = [
    'dine_in'  => [
        'pending'   => 'preparing',
        'confirmed' => 'preparing',
        'preparing' => 'ready',
        'ready'     => 'delivered',   // "Served" for dine-in
    ],
    'takeout'  => [
        'pending'   => 'preparing',
        'confirmed' => 'preparing',
        'preparing' => 'ready',
        'ready'     => 'delivered',   // "Served / Picked up" for takeout
    ],
    'delivery' => [
        'pending'          => 'preparing',
        'confirmed'        => 'preparing',
        'preparing'        => 'ready',
        'ready'            => 'out_for_delivery',
        'out_for_delivery' => 'delivered',
    ],
];

// Label of the next button per service type
$next_label = [
    'dine_in' => [
        'ready' => '✅ Served',
    ],
    'takeout' => [
        'ready' => '✅ Picked Up',
    ],
    'delivery' => [],   // uses default status label
];

// Cashier-allowed status set for the dropdown
$cashier_statuses = ['preparing', 'ready', 'out_for_delivery', 'delivered'];
$all_statuses     = ['pending', 'confirmed', 'preparing', 'ready', 'out_for_delivery', 'delivered', 'cancelled'];

// ── Handle order status update (POST) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id']) && $rest_id) {

    // ── Sub-action: owner manually marks payment as paid ─────────────────────
    if (isset($_POST['mark_paid']) && !$is_cashier_view) {
        header('Content-Type: application/json; charset=utf-8');
        $order_id = (int)$_POST['order_id'];
        $upd = $conn->prepare(
            "UPDATE orders
             SET payment_status = 'paid', payment_verified_at = NOW()
             WHERE id = ? AND restaurant_id = ? AND payment_status != 'paid' AND payment_method = 'cash'"
        );
        if ($upd) {
            $upd->bind_param("ii", $order_id, $rest_id);
            $upd->execute();
            $ok = $upd->affected_rows > 0;
            $upd->close();
            echo json_encode(['success' => $ok, 'message' => $ok ? 'Marked as paid.' : 'Already paid or not found.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'DB error: ' . $conn->error]);
        }
        exit;
    }

    $new_status = sanitize($_POST['new_status'] ?? '');
    $order_id   = (int)$_POST['order_id'];

    // Cashiers can only move to their allowed statuses
    $allowed = $is_cashier_view ? $cashier_statuses : $all_statuses;

    if (in_array($new_status, $allowed, true)) {
        $upd = $conn->prepare(
            "UPDATE orders SET order_status = ? WHERE id = ? AND restaurant_id = ? AND (payment_status = 'paid' OR payment_method = 'cash')"
        );
        if ($upd) {
            $upd->bind_param("sii", $new_status, $order_id, $rest_id);
            $upd->execute();
            $upd->close();
        }
    }
    // Redirect back to same filter to prevent form re-submission on refresh
    $redirect_filter = trim($_GET['status'] ?? 'all');
    header('Location: manage_orders.php?status=' . urlencode($redirect_filter));
    exit();
}

// ── Filter ────────────────────────────────────────────────────────────────────
$filter          = trim($_GET['status'] ?? 'all');
$allowed_filters = array_merge(['all'], $all_statuses);
if (!in_array($filter, $allowed_filters, true)) $filter = 'all';
$where_status    = ($filter !== 'all')
    ? "AND o.order_status = '" . $conn->real_escape_string($filter) . "'"
    : '';

// ── Load orders ───────────────────────────────────────────────────────────────
$orders = [];
if ($rest_id) {
    $result = $conn->query("
        SELECT o.id, o.order_number, o.total_amount, o.payment_method,
               o.payment_status, o.order_status, o.service_type,
               o.created_at, o.items_json, o.notes,
               o.cash_received, o.change_amount, o.table_number,
               u.full_name AS customer_name
        FROM orders o
        LEFT JOIN users u ON o.customer_id = u.id
        WHERE o.restaurant_id = {$rest_id}
          AND (o.payment_status IN ('paid','awaiting_cash') OR o.payment_method = 'cash')
        {$where_status}
        ORDER BY o.created_at DESC
        LIMIT 100
    ");
    if ($result) {
        $orders = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }
}

// ── Counts per status for filter badges ──────────────────────────────────────
$counts = [];
if ($rest_id) {
    $cnt = $conn->query(
        "SELECT order_status, COUNT(*) AS c FROM orders
         WHERE restaurant_id = {$rest_id} AND (payment_status IN ('paid','awaiting_cash') OR payment_method = 'cash') GROUP BY order_status"
    );
    if ($cnt) {
        while ($row = $cnt->fetch_assoc()) $counts[$row['order_status']] = (int)$row['c'];
        $cnt->free();
    }
}
$total_orders = array_sum($counts);

require_once __DIR__ . '/../../includes/header.php';
?>

<?php if (!$rest_id): ?>
<div class="card" style="max-width:520px;margin:0 auto;text-align:center;padding:48px 32px;">
    <div style="font-size:2.5rem;color:var(--neutral-300);margin-bottom:16px;"><i class="fas fa-store-slash"></i></div>
    <h3 style="color:var(--neutral-600);margin-bottom:8px;">No Restaurant Found</h3>
    <p style="color:var(--neutral-400);">No restaurant is linked to your account.</p>
</div>
<?php else: ?>

<!-- ══ PAGE HERO ═══════════════════════════════════════════════════════════ -->
<div style="background:linear-gradient(135deg,#1d4ed8 0%,#2563eb 55%,#0284c7 100%);
            border-radius:var(--radius-xl);padding:24px 32px;margin-bottom:20px;
            position:relative;overflow:hidden;">
    <div style="position:absolute;right:-40px;top:-40px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none;"></div>
    <div style="position:relative;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div>
            <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#93c5fd;margin-bottom:6px;">
                <i class="fas fa-receipt" style="margin-right:5px;"></i>Order Management
            </div>
            <h1 style="font-family:var(--font-display);font-size:1.4rem;font-weight:800;color:#fff;margin:0 0 4px;">
                Manage Orders
            </h1>
            <p style="color:rgba(255,255,255,.7);font-size:.85rem;margin:0;">
                <?= $total_orders ?> total order<?= $total_orders !== 1 ? 's' : '' ?> &nbsp;·&nbsp;
                <?= $counts['pending'] ?? 0 ?> pending &nbsp;·&nbsp;
                <?= ($counts['preparing'] ?? 0) + ($counts['confirmed'] ?? 0) ?> in progress
            </p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="<?= BASE_URL ?>dashboard/business_owner/pos_order_processing.php"
               style="display:inline-flex;align-items:center;gap:8px;background:#fff;color:#1d4ed8;
                      padding:9px 18px;border-radius:var(--radius-md);font-weight:700;font-size:.85rem;
                      text-decoration:none;transition:opacity .15s;white-space:nowrap;"
               onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
                <i class="fas fa-cash-register"></i> POS / Cashier
            </a>
        </div>
    </div>
</div>

<!-- ── Filter tabs ───────────────────────────────────────────────────────── -->
<div style="display:flex;gap:4px;border-bottom:2px solid var(--neutral-200);margin-bottom:24px;flex-wrap:wrap;">
<?php
$filter_tabs = $is_cashier_view
    ? ['all'              => 'All',
       'pending'          => 'New Orders',
       'preparing'        => 'Preparing',
       'ready'            => 'Ready',
       'out_for_delivery' => 'Out for Delivery',
       'delivered'        => 'Delivered']
    : ['all'              => 'All',
       'pending'          => 'Pending',
       'confirmed'        => 'Confirmed',
       'preparing'        => 'Preparing',
       'ready'            => 'Ready',
       'out_for_delivery' => 'Out for Delivery',
       'delivered'        => 'Delivered',
       'cancelled'        => 'Cancelled'];
foreach ($filter_tabs as $tv => $tl):
    $ia  = ($filter === $tv);
    $cnt = ($tv === 'all') ? $total_orders : ($counts[$tv] ?? 0);
?>
<a href="?status=<?= $tv ?>"
   style="padding:10px 16px;font-size:.82rem;font-weight:600;text-decoration:none;
          color:<?= $ia ? 'var(--primary-700)' : 'var(--neutral-500)' ?>;
          border-bottom:2px solid <?= $ia ? 'var(--primary-600)' : 'transparent' ?>;
          margin-bottom:-2px;transition:all .2s;display:flex;align-items:center;gap:5px;">
    <?= $tl ?>
    <?php if ($cnt > 0): ?>
    <span style="background:<?= $ia ? 'var(--primary-600)' : 'var(--neutral-200)' ?>;
                 color:<?= $ia ? '#fff' : 'var(--neutral-600)' ?>;
                 padding:1px 7px;border-radius:999px;font-size:.72rem;"><?= $cnt ?></span>
    <?php endif; ?>
</a>
<?php endforeach; ?>
</div>

<!-- ── Orders list ───────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-receipt" style="color:var(--accent-500);margin-right:8px;"></i>
            <?= htmlspecialchars($filter_tabs[$filter] ?? 'All') ?> Orders
        </h3>
        <span style="font-size:.82rem;color:var(--neutral-400);">
            <?= count($orders) ?> order<?= count($orders) !== 1 ? 's' : '' ?>
        </span>
    </div>

    <?php if (empty($orders)): ?>
    <div class="card-body">
        <div class="empty-state" style="padding:32px;">
            <div class="empty-icon"><i class="fas fa-receipt"></i></div>
            <h3>No Orders</h3>
            <p>No <?= $filter !== 'all' ? strtolower($filter_tabs[$filter] ?? $filter) : '' ?> orders yet.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
        <table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th style="min-width:130px;">Order #</th>
                    <th>Items</th>
                    <th>Total</th>
                    <th>Payment</th>
                    <th>Current Status</th>
                    <th>Placed</th>
                    <th style="min-width:200px;">Order Status</th>
                    <th style="min-width:180px;">Payment Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $order):
                $items     = json_decode($order['items_json'] ?? '[]', true) ?: [];
                $item_lines = array_map(
                    fn($i) => htmlspecialchars($i['name'] ?? '') . ' <span style="color:var(--neutral-400);">×' . (int)($i['qty'] ?? 1) . '</span>',
                    $items
                );
                $cur  = $order['order_status'];
                $svc  = $order['service_type'] ?? 'delivery';  // default delivery for old rows
                $nxt  = $next_by_service[$svc][$cur] ?? null;
                // Override "next" button label for dine-in/takeout "ready → served"
                $nxt_label = $next_label[$svc][$cur]
                             ?? ($nxt ? htmlspecialchars($status_labels[$nxt] ?? ucfirst($nxt)) : '');

                // Service type badge
                $svc_badge_map = [
                    'dine_in'  => ['🍽️', 'Dine-in',  '#f0fdf4', '#166534'],
                    'takeout'  => ['🛍️', 'Takeout',  '#eff6ff', '#1d4ed8'],
                    'delivery' => ['🛵', 'Delivery', '#fff7ed', '#9a3412'],
                ];
                [$svc_icon, $svc_lbl, $svc_bg, $svc_fg] = $svc_badge_map[$svc] ?? ['📦', $svc, '#f1f5f9', '#334155'];
            ?>
            <tr id="order-row-<?= $order['id'] ?>">

                <!-- Order # + service type badge + notes -->
                <td>
                    <div style="font-weight:700;color:var(--primary-700);font-size:.9rem;">
                        <?= htmlspecialchars($order['order_number']) ?>
                    </div>
                    <span style="display:inline-flex;align-items:center;gap:3px;
                                 background:<?= $svc_bg ?>;color:<?= $svc_fg ?>;
                                 padding:2px 8px;border-radius:999px;font-size:.72rem;font-weight:600;margin-top:3px;">
                        <?= $svc_icon ?> <?= $svc_lbl ?>
                    </span>
                    <?php if (!empty($order['table_number'])): ?>
                    <span style="display:inline-flex;align-items:center;gap:3px;
                                 background:#fef3c7;color:#92400e;
                                 padding:2px 8px;border-radius:999px;font-size:.72rem;font-weight:600;margin-top:3px;margin-left:3px;">
                        🪑 Table <?= htmlspecialchars($order['table_number']) ?>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($order['customer_name'])): ?>
                    <div style="font-size:.73rem;color:var(--neutral-500);margin-top:2px;">
                        <i class="fas fa-user" style="font-size:.65rem;"></i> <?= htmlspecialchars($order['customer_name']) ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($order['notes'])): ?>
                    <div style="font-size:.73rem;color:var(--neutral-400);margin-top:2px;max-width:160px;white-space:normal;">
                        📝 <?= htmlspecialchars(substr($order['notes'], 0, 60)) ?>
                    </div>
                    <?php endif; ?>
                </td>

                <!-- Items -->
                <td style="font-size:.8rem;color:var(--neutral-700);max-width:220px;white-space:normal;line-height:1.6;">
                    <?= implode('<br>', $item_lines) ?: '—' ?>
                </td>

                <!-- Total -->
                <td style="font-weight:700;color:var(--primary-700);white-space:nowrap;">
                    <?= formatCurrency($order['total_amount']) ?>
                </td>

                <!-- Payment -->
                <td id="pay-status-cell-<?= $order['id'] ?>"
                    data-payment-status="<?= htmlspecialchars($order['payment_status'], ENT_QUOTES) ?>"
                    data-payment-method="<?= htmlspecialchars($order['payment_method'], ENT_QUOTES) ?>"
                    data-order-number="<?= htmlspecialchars($order['order_number'], ENT_QUOTES) ?>">
                    <?= getStatusBadge($order['payment_status']) ?>
                    <div style="font-size:.72rem;color:var(--neutral-500);margin-top:3px;text-transform:capitalize;">
                        <?= htmlspecialchars(str_replace(['_','paymongo'], [' ','PayMongo'], $order['payment_method'])) ?>
                    </div>
                    <?php if ($order['payment_status'] === 'paid' && !empty($order['cash_received'])): ?>
                    <div style="font-size:.7rem;color:#059669;margin-top:2px;">
                        💵 Rcvd: ₱<?= number_format($order['cash_received'], 2) ?>
                        <?php if ($order['change_amount'] > 0): ?>
                        · Chg: ₱<?= number_format($order['change_amount'], 2) ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </td>

                <!-- Current status -->
                <td><?= getStatusBadge($cur) ?></td>

                <!-- Placed -->
                <td style="font-size:.8rem;color:var(--neutral-400);white-space:nowrap;">
                    <?= timeAgo($order['created_at']) ?>
                </td>

                <!-- Order status action -->
                <td>
                <?php if (in_array($cur, $terminal_statuses, true)): ?>
                    <span style="font-size:.8rem;color:var(--neutral-300);font-style:italic;">
                        <?= $cur === 'delivered' ? '✔ Complete' : '✖ Cancelled' ?>
                    </span>
                <?php elseif ($nxt): ?>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="order_id"   value="<?= $order['id'] ?>">
                        <input type="hidden" name="new_status" value="<?= $nxt ?>">
                        <button type="submit" class="btn btn-sm btn-primary"
                                style="white-space:nowrap;font-size:.78rem;">
                            <i class="fas fa-arrow-right" style="margin-right:4px;"></i>
                            <?= $nxt_label ?>
                        </button>
                    </form>
                <?php else: ?>
                    <span style="font-size:.8rem;color:var(--neutral-400);">—</span>
                <?php endif; ?>
                </td>

                <!-- Payment action column -->
                <td id="pay-action-<?= $order['id'] ?>">
                <?php if ($order['payment_status'] === 'awaiting_cash'): ?>
                    <button
                        onclick="openCashModal(<?= $order['id'] ?>, <?= (float)$order['total_amount'] ?>, '<?= htmlspecialchars(addslashes($order['order_number'])) ?>')"
                        class="btn btn-sm btn-warning"
                        style="white-space:nowrap;font-size:.78rem;background:#f59e0b;border-color:#f59e0b;color:#fff;display:inline-flex;align-items:center;gap:5px;">
                        <i class="fas fa-cash-register"></i> Collect Cash
                    </button>
                <?php elseif ($order['payment_status'] === 'pending' && !$is_cashier_view): ?>
                    <button
                        onclick="markPaid(<?= $order['id'] ?>, '<?= htmlspecialchars(addslashes($order['order_number'])) ?>')"
                        class="btn btn-sm btn-outline"
                        style="white-space:nowrap;font-size:.78rem;display:inline-flex;align-items:center;gap:5px;">
                        <i class="fas fa-check"></i> Mark Paid
                    </button>
                <?php elseif ($order['payment_status'] === 'paid'): ?>
                    <span style="font-size:.78rem;color:#059669;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
                        <i class="fas fa-check-circle"></i> Paid
                    </span>
                <?php else: ?>
                    <span style="font-size:.78rem;color:var(--neutral-400);">—</span>
                <?php endif; ?>
                </td>

            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<!-- ══ CASH PAYMENT MODAL ══════════════════════════════════════════════════ -->
<div id="cashPayModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);
     z-index:9999;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:16px;max-width:440px;width:100%;
                box-shadow:0 24px 60px rgba(0,0,0,.25);overflow:hidden;">

        <!-- Header -->
        <div style="background:linear-gradient(135deg,#f59e0b,#d97706);padding:20px 24px 16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:1.05rem;font-weight:700;color:#fff;
                           display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-cash-register"></i>
                    <span>Collect Cash Payment</span>
                </h3>
                <button onclick="closeCashModal()"
                        style="background:rgba(255,255,255,.2);border:none;color:#fff;
                               width:30px;height:30px;border-radius:50%;cursor:pointer;
                               font-size:1rem;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        <!-- Body -->
        <div style="padding:24px;">

            <!-- Order info -->
            <div style="background:#fef3c7;border-left:4px solid #f59e0b;
                        padding:10px 14px;border-radius:8px;margin-bottom:18px;">
                <div style="font-size:.75rem;color:#92400e;margin-bottom:2px;font-weight:600;">Order Number</div>
                <div id="cashModalOrderNum" style="font-size:1rem;font-weight:800;color:#78350f;"></div>
            </div>

            <!-- Amount due -->
            <div style="margin-bottom:18px;">
                <label style="display:block;font-size:.82rem;font-weight:700;
                               color:var(--neutral-600);margin-bottom:6px;">Amount Due</label>
                <div style="background:#f3f4f6;padding:12px 16px;border-radius:10px;
                            font-size:1.4rem;font-weight:800;color:#dc2626;text-align:center;">
                    ₱<span id="cashModalAmountDue"></span>
                </div>
            </div>

            <!-- Cash received input -->
            <div style="margin-bottom:18px;">
                <label for="cashReceivedInput"
                       style="display:block;font-size:.82rem;font-weight:700;
                              color:var(--neutral-600);margin-bottom:6px;">
                    Cash Received <span style="color:#dc2626;">*</span>
                </label>
                <div style="position:relative;">
                    <span style="position:absolute;left:13px;top:50%;transform:translateY(-50%);
                                 font-size:1.1rem;font-weight:700;color:#6b7280;">₱</span>
                    <input type="number" id="cashReceivedInput" step="0.01" min="0"
                           placeholder="0.00"
                           style="width:100%;padding:12px 12px 12px 34px;
                                  border:2px solid #d1d5db;border-radius:10px;
                                  font-size:1.15rem;font-weight:700;
                                  box-sizing:border-box;transition:border-color .2s;"
                           oninput="calcChange()"
                           onfocus="this.style.borderColor='#f59e0b'"
                           onblur="this.style.borderColor='#d1d5db'">
                </div>
                <div id="cashShortageMsg"
                     style="display:none;margin-top:6px;font-size:.78rem;
                            font-weight:700;color:#dc2626;">
                    <i class="fas fa-exclamation-circle"></i> <span></span>
                </div>
            </div>

            <!-- Change display -->
            <div id="changeDisplay" style="display:none;margin-bottom:18px;">
                <label style="display:block;font-size:.82rem;font-weight:700;
                               color:var(--neutral-600);margin-bottom:6px;">Change</label>
                <div style="background:#dcfce7;border:2px solid #16a34a;
                            padding:12px 16px;border-radius:10px;
                            font-size:1.3rem;font-weight:800;color:#15803d;text-align:center;">
                    ₱<span id="changeAmt">0.00</span>
                </div>
            </div>

            <!-- Buttons -->
            <div style="display:grid;grid-template-columns:1fr 2fr;gap:10px;margin-top:20px;">
                <button onclick="closeCashModal()"
                        class="btn btn-secondary"
                        style="padding:11px;font-weight:700;border-radius:10px;">
                    Cancel
                </button>
                <button id="confirmCashBtn"
                        onclick="submitCashPayment()"
                        class="btn btn-success"
                        disabled
                        style="padding:11px;font-weight:700;border-radius:10px;
                               background:linear-gradient(135deg,#16a34a,#15803d);
                               display:flex;align-items:center;justify-content:center;gap:8px;
                               opacity:.45;cursor:not-allowed;">
                    <i class="fas fa-check-circle"></i>
                    <span>Confirm Payment</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    // ── Cash modal state ───────────────────────────────────────────────────────
    let _orderId   = 0;
    let _amountDue = 0;

    window.openCashModal = function (orderId, amountDue, orderNum) {
        _orderId   = orderId;
        _amountDue = parseFloat(amountDue) || 0;

        document.getElementById('cashModalOrderNum').textContent  = orderNum;
        document.getElementById('cashModalAmountDue').textContent = _amountDue.toFixed(2);
        document.getElementById('cashReceivedInput').value        = '';
        document.getElementById('changeDisplay').style.display    = 'none';
        document.getElementById('cashShortageMsg').style.display  = 'none';
        document.getElementById('cashReceivedInput').style.borderColor = '#d1d5db';

        const btn = document.getElementById('confirmCashBtn');
        btn.disabled = true;
        btn.style.opacity = '.45';
        btn.style.cursor  = 'not-allowed';
        btn.innerHTML     = '<i class="fas fa-check-circle"></i><span> Confirm Payment</span>';

        const modal = document.getElementById('cashPayModal');
        modal.style.display = 'flex';
        setTimeout(() => document.getElementById('cashReceivedInput').focus(), 80);
    };

    window.closeCashModal = function () {
        document.getElementById('cashPayModal').style.display = 'none';
    };

    window.calcChange = function () {
        const received = parseFloat(document.getElementById('cashReceivedInput').value) || 0;
        const shortEl  = document.getElementById('cashShortageMsg');
        const chgDisp  = document.getElementById('changeDisplay');
        const btn      = document.getElementById('confirmCashBtn');
        const input    = document.getElementById('cashReceivedInput');

        shortEl.style.display = 'none';
        input.style.borderColor = '#d1d5db';

        if (received <= 0) {
            chgDisp.style.display = 'none';
            btn.disabled = true; btn.style.opacity = '.45'; btn.style.cursor = 'not-allowed';
            return;
        }

        if (received < _amountDue) {
            const shortage = (_amountDue - received).toFixed(2);
            shortEl.querySelector('span').textContent = `Short by ₱${shortage}`;
            shortEl.style.display = 'block';
            input.style.borderColor = '#dc2626';
            chgDisp.style.display = 'none';
            btn.disabled = true; btn.style.opacity = '.45'; btn.style.cursor = 'not-allowed';
            return;
        }

        const change = (received - _amountDue).toFixed(2);
        document.getElementById('changeAmt').textContent = change;
        chgDisp.style.display = 'block';
        btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer';
    };

    window.submitCashPayment = async function () {
        const received = parseFloat(document.getElementById('cashReceivedInput').value) || 0;
        if (received < _amountDue || _orderId <= 0) return;

        const btn = document.getElementById('confirmCashBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span> Processing…</span>';

        try {
            const fd = new FormData();
            fd.append('order_id',      _orderId);
            fd.append('cash_received', received.toFixed(2));

            const res  = await fetch('<?= BASE_URL ?>dashboard/business_owner/api/confirm_cash_payment.php',
                                     { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                closeCashModal();

                // Update the payment action cell in-place
                const payCell = document.getElementById('pay-action-' + _orderId);
                if (payCell) {
                    payCell.innerHTML =
                        `<span style="font-size:.78rem;color:#059669;font-weight:700;
                                      display:inline-flex;align-items:center;gap:4px;">
                            <i class="fas fa-check-circle"></i> Paid
                        </span>
                        <div style="font-size:.7rem;color:#6b7280;margin-top:3px;">
                            Rcvd: ₱${data.cash_received}
                            ${parseFloat(data.change_amount) > 0 ? ' · Chg: ₱' + data.change_amount : ''}
                        </div>`;
                }

                // Update the payment status badge cell in the same row
                const row = document.getElementById('order-row-' + _orderId);
                if (row) {
                    const statusCell = document.getElementById('pay-status-cell-' + _orderId);
                    if (statusCell) {
                        statusCell.innerHTML =
                            `<span style="background:#dcfce7;color:#166534;padding:3px 10px;
                                          border-radius:999px;font-size:.72rem;font-weight:700;">
                                Paid
                            </span>
                            <div style="font-size:.72rem;color:var(--neutral-500);margin-top:3px;">💵 Cash</div>
                            <div style="font-size:.7rem;color:#059669;margin-top:2px;">
                                💵 Rcvd: ₱${data.cash_received}
                                ${parseFloat(data.change_amount) > 0 ? ' · Chg: ₱' + data.change_amount : ''}
                            </div>`;
                        statusCell.dataset.paymentStatus = 'paid';
                    }
                }

                showPayToast(`✅ Payment confirmed — ${data.order_number}. Change: ₱${data.change_amount}`, false);

            } else if (data.already_confirmed) {
                closeCashModal();
                showPayToast('ℹ️ ' + data.message, false);
            } else {
                btn.disabled = false;
                btn.style.opacity = '1'; btn.style.cursor = 'pointer';
                btn.innerHTML = '<i class="fas fa-check-circle"></i><span> Confirm Payment</span>';
                showPayToast('❌ ' + (data.message || 'Failed to confirm payment.'), true);
            }
        } catch (e) {
            btn.disabled = false;
            btn.style.opacity = '1'; btn.style.cursor = 'pointer';
            btn.innerHTML = '<i class="fas fa-check-circle"></i><span> Confirm Payment</span>';
            showPayToast('❌ Network error. Please try again.', true);
        }
    };

    // ── Mark Paid (owner only, non-cash) ──────────────────────────────────────
    window.markPaid = async function (orderId, orderNum) {
        if (!confirm(`Mark order ${orderNum} as paid?\n\nThis manually records the payment as received.`)) return;

        try {
            const fd = new FormData();
            fd.append('order_id',  orderId);
            fd.append('mark_paid', '1');

            const res  = await fetch(window.location.href + '&ajax=1', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                const payCell = document.getElementById('pay-action-' + orderId);
                if (payCell) {
                    payCell.innerHTML =
                        '<span style="font-size:.78rem;color:#059669;font-weight:700;display:inline-flex;align-items:center;gap:4px;"><i class="fas fa-check-circle"></i> Paid</span>';
                }
                showPayToast('✅ Order ' + orderNum + ' marked as paid.', false);
            } else {
                showPayToast('❌ ' + (data.message || 'Failed.'), true);
            }
        } catch (e) {
            showPayToast('❌ Network error.', true);
        }
    };

    // ── Toast ──────────────────────────────────────────────────────────────────
    function showPayToast(msg, isError) {
        let t = document.getElementById('_payToast');
        if (!t) {
            t = document.createElement('div');
            t.id = '_payToast';
            t.style.cssText =
                'position:fixed;bottom:24px;right:24px;z-index:10000;padding:12px 20px;' +
                'border-radius:10px;font-size:.88rem;font-weight:600;max-width:360px;' +
                'box-shadow:0 8px 24px rgba(0,0,0,.18);transition:opacity .3s;';
            document.body.appendChild(t);
        }
        t.style.background = isError ? '#fef2f2' : '#f0fdf4';
        t.style.color      = isError ? '#991b1b' : '#166534';
        t.style.border     = isError ? '1px solid #fecaca' : '1px solid #bbf7d0';
        t.textContent      = msg;
        t.style.opacity    = '1';
        clearTimeout(t._to);
        t._to = setTimeout(() => { t.style.opacity = '0'; }, 5000);
    }

    // Close modal on backdrop click
    document.getElementById('cashPayModal').addEventListener('click', function (e) {
        if (e.target === this) closeCashModal();
    });

    // Enter key to confirm
    document.getElementById('cashReceivedInput').addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !document.getElementById('confirmCashBtn').disabled) {
            submitCashPayment();
        }
    });

    /* ── Live payment-status polling for GCash / Bank Transfer orders ────────
       Polls every 15 s. When a pending online-payment order transitions to
       'paid' (webhook fired), updates the badge and Payment Action cell live.
    ──────────────────────────────────────────────────────────────────────── */
    const pendingOnlineOrderIds = [];

    document.querySelectorAll('td[id^="pay-status-cell-"]').forEach(cell => {
        const method = cell.dataset.paymentMethod;
        const status = cell.dataset.paymentStatus;
        if ((method === 'gcash' || method === 'bank_transfer') && status === 'pending') {
            const id = cell.id.replace('pay-status-cell-', '');
            pendingOnlineOrderIds.push(id);
        }
    });

    async function pollOnlinePayments() {
        if (pendingOnlineOrderIds.length === 0) return;

        try {
            const res  = await fetch('<?= BASE_URL ?>dashboard/business_owner/api/get_order_payment_statuses.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ order_ids: pendingOnlineOrderIds })
            });
            const data = await res.json();
            if (!data.success || !data.orders) return;

            data.orders.forEach(order => {
                if (order.payment_status !== 'paid') return;

                const cellId = 'pay-status-cell-' + order.id;
                const cell   = document.getElementById(cellId);
                if (!cell || cell.dataset.paymentStatus === 'paid') return;

                const method      = cell.dataset.paymentMethod;
                const orderNumber = cell.dataset.orderNumber;
                const methodLabel = method === 'gcash' ? 'GCash' : 'Bank Transfer';

                // ── Update payment badge cell ─────────────────────────────────
                cell.innerHTML = `
                    <span style="background:#dcfce7;color:#166534;padding:3px 10px;
                                 border-radius:999px;font-size:.72rem;font-weight:700;">
                        Paid
                    </span>
                    <div style="font-size:.72rem;color:var(--neutral-500);margin-top:3px;text-transform:capitalize;">
                        ${methodLabel}
                    </div>`;
                cell.dataset.paymentStatus = 'paid';

                // ── Update Payment Action cell ────────────────────────────────
                const actionCell = document.getElementById('pay-action-' + order.id);
                if (actionCell) {
                    actionCell.innerHTML =
                        `<span style="font-size:.78rem;color:#059669;font-weight:700;
                                      display:inline-flex;align-items:center;gap:4px;">
                            <i class="fas fa-check-circle"></i> Paid
                        </span>`;
                }

                // ── Remove from polling list ──────────────────────────────────
                const idx = pendingOnlineOrderIds.indexOf(String(order.id));
                if (idx > -1) pendingOnlineOrderIds.splice(idx, 1);

                // ── Toast notification ────────────────────────────────────────
                showPayToast(`✅ ${methodLabel} payment received — ${orderNumber}`, false);
            });
        } catch (_) { /* silent — will retry next interval */ }
    }

    if (pendingOnlineOrderIds.length > 0) {
        pollOnlinePayments();
        setInterval(pollOnlinePayments, 15000);
    }

})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
