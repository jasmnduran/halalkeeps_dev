<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);

// ── Runtime session refresh: re-resolve staff_role_key without requiring re-login
if ((int)($_SESSION['role_id'] ?? 0) === ROLE_BUSINESS_OWNER) {
    $uid_check = (int)$_SESSION['user_id'];
    $sr_check  = $conn->prepare("
        SELECT rsr.role_key FROM restaurant_staff rs
        JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
        WHERE rs.user_id = ? AND rs.is_active = 1 LIMIT 1
    ");
    if ($sr_check) {
        $sr_check->bind_param("i", $uid_check);
        $sr_check->execute();
        $sr_row = $sr_check->get_result()->fetch_assoc();
        $_SESSION['staff_role_key'] = $sr_row ? $sr_row['role_key'] : null;
        $sr_check->close();
    }
}

// ── Access: business owner OR assigned ops manager ────────────────────────────
// Staff members (ops_manager) share this page; pure owners always pass through.
$user_id    = (int)$_SESSION['user_id'];
$staff_key  = $_SESSION['staff_role_key'] ?? null;   // null = actual owner
$is_owner   = ($staff_key === null);

// Ops manager: verify active assignment and resolve their restaurant_id upfront
if (!$is_owner) {
    if ($staff_key !== STAFF_ROLE_OPS_MANAGER) {
        // Cashiers and any other staff role have no business here
        $fallback_map = [
            STAFF_ROLE_CASHIER => BASE_URL . 'dashboard/business_owner/pos_order_processing.php',
            STAFF_ROLE_CHEF    => BASE_URL . 'dashboard/business_owner/chef_dashboard.php',
        ];
        $fallback = $fallback_map[$staff_key] ?? (BASE_URL . 'dashboard/business_owner/owner_dashboard.php');
        header('Location: ' . $fallback);
        exit();
    }

    $sa_stmt = $conn->prepare("
        SELECT rs.restaurant_id
        FROM restaurant_staff rs
        JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
        WHERE rs.user_id = ? AND rsr.role_key = ? AND rs.is_active = 1
        LIMIT 1
    ");
    $ops_role_key = STAFF_ROLE_OPS_MANAGER;
    $sa_stmt->bind_param("is", $user_id, $ops_role_key);
    $sa_stmt->execute();
    $sa_row = $sa_stmt->get_result()->fetch_assoc();

    if (!$sa_row) {
        // No active assignment — redirect to their dashboard home
        header('Location: ' . BASE_URL . 'dashboard/business_owner/owner_dashboard.php');
        exit();
    }
    $assigned_restaurant_id = (int)$sa_row['restaurant_id'];
}

$page_title   = 'My Restaurant';
$page_heading = 'My Restaurant';
$is_dashboard = true;
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/business_owner/'],
    ['label' => 'My Restaurant']
];
$error = ''; $success = '';

// ── Auto-fill source (owner only — ops manager edits the live restaurant row) ─
$auto_name = ''; $auto_address = '';
if ($is_owner) {
    $ba_stmt = $conn->prepare("
        SELECT ba.business_name,
               bb.address_street, bb.address_barangay, bb.address_city,
               bb.address_province, bb.address_region, bb.address_zip
        FROM business_applications ba
        LEFT JOIN business_branches bb ON bb.application_id = ba.id AND bb.is_main = 1
        WHERE ba.user_id = ? AND ba.status = 'approved'
        ORDER BY ba.created_at DESC LIMIT 1
    ");
    $ba_stmt->bind_param("i", $user_id);
    $ba_stmt->execute();
    $ba = $ba_stmt->get_result()->fetch_assoc();

    if ($ba) {
        $auto_name = $ba['business_name'] ?? '';
        $addr_parts = array_filter([
            $ba['address_street']   ?? '',
            $ba['address_barangay'] ?? '',
            $ba['address_city']     ?? '',
            $ba['address_province'] ?? '',
            $ba['address_region']   ?? '',
            $ba['address_zip']      ?? '',
        ]);
        $auto_address = implode(', ', $addr_parts);
    } else {
        // ── Auto-fill source 2: latest verified LOI ───────────────────────────
        $loi_stmt = $conn->prepare("
            SELECT company_name, company_address
            FROM letter_of_intent
            WHERE business_owner_id = ? AND status = 'verified'
            ORDER BY created_at DESC LIMIT 1
        ");
        $loi_stmt->bind_param("i", $user_id);
        $loi_stmt->execute();
        $loi = $loi_stmt->get_result()->fetch_assoc();

        if ($loi) {
            $auto_name    = $loi['company_name']    ?? '';
            $auto_address = $loi['company_address'] ?? '';
        } else {
            // ── Auto-fill source 3: user profile (last resort) ────────────────
            $u_stmt = $conn->prepare("SELECT full_name, address FROM users WHERE id = ? LIMIT 1");
            $u_stmt->bind_param("i", $user_id);
            $u_stmt->execute();
            $u_row = $u_stmt->get_result()->fetch_assoc();
            $auto_name    = $u_row['full_name'] ?? '';
            $auto_address = $u_row['address']   ?? '';
        }
    }
}

// ── Load existing restaurant ──────────────────────────────────────────────────
// Owners: find their own restaurant. Ops managers: use the assigned restaurant.
if ($is_owner) {
    $rest_stmt = $conn->prepare("
        SELECT hr.*, hc.certificate_number, hc.status AS cert_status
        FROM halal_restaurants hr
        LEFT JOIN halal_certificates hc ON hr.certificate_id = hc.id
        WHERE hr.business_owner_id = ?
        LIMIT 1
    ");
    $rest_stmt->bind_param("i", $user_id);
} else {
    $rest_stmt = $conn->prepare("
        SELECT hr.*, hc.certificate_number, hc.status AS cert_status
        FROM halal_restaurants hr
        LEFT JOIN halal_certificates hc ON hr.certificate_id = hc.id
        WHERE hr.id = ?
        LIMIT 1
    ");
    $rest_stmt->bind_param("i", $assigned_restaurant_id);
}
$rest_stmt->execute();
$restaurant = $rest_stmt->get_result()->fetch_assoc();

$restaurant_services = [];
if (!empty($restaurant['services_offered'])) {
    $decoded = json_decode($restaurant['services_offered'], true);
    if (is_array($decoded)) {
        $restaurant_services = $decoded;
    }
}

$allowed_services      = ['Dine-in', 'Takeout', 'Delivery'];
$allowed_serving_types = ALLOWED_SERVING_TYPES;

// ── Handle restaurant save ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_restaurant'])) {
    // Name & address come from hidden auto-filled inputs (read-only enforced by JS)
    $name        = sanitize($_POST['restaurant_name'] ?? $auto_name);
    $address     = sanitize($_POST['address']         ?? $auto_address);
    $cuisine     = sanitize($_POST['cuisine_type']    ?? '');
    $open_from   = sanitize($_POST['open_from']       ?? '');
    $open_to     = sanitize($_POST['open_to']         ?? '');
    $description = sanitize($_POST['description']     ?? '');
    $services    = $_POST['services_offered'] ?? [];
    if (!is_array($services)) {
        $services = [];
    }
    $services = array_values(array_filter(array_map('sanitize', $services), function($service) use ($allowed_services) {
        return in_array($service, $allowed_services, true);
    }));
    $services_json = json_encode($services);

    $hours = ($open_from && $open_to) ? date('h:i A', strtotime($open_from)) . ' - ' . date('h:i A', strtotime($open_to)) : '';

    if ($restaurant) {
        $u = $conn->prepare("UPDATE halal_restaurants SET restaurant_name=?, address=?, cuisine_type=?, operating_hours=?, description=?, services_offered=? WHERE id=?");
        if (!$u) { $error = 'Update prepare failed: ' . $conn->error; }
        else {
            $u->bind_param("ssssssi", $name, $address, $cuisine, $hours, $description, $services_json, $restaurant['id']);
            if ($u->execute()) { $success = 'Restaurant updated successfully!'; }
            else { $error = 'Update failed: ' . $u->error; }
        }
    } else {
        // Only the business owner can register a brand-new restaurant.
        // An ops manager should never reach this branch (the restaurant
        // already exists before they are assigned), but guard it anyway.
        if (!$is_owner) {
            $error = 'Only the business owner can register a new restaurant.';
        } else {
            $i = $conn->prepare("INSERT INTO halal_restaurants (business_owner_id, restaurant_name, address, cuisine_type, operating_hours, description, services_offered) VALUES (?,?,?,?,?,?,?)");
            if (!$i) { $error = 'Insert prepare failed: ' . $conn->error; }
            else {
                $i->bind_param("issssss", $user_id, $name, $address, $cuisine, $hours, $description, $services_json);
                if ($i->execute()) { $success = 'Restaurant registered successfully!'; }
                else { $error = 'Create failed: ' . $i->error; }
            }
        }
    }
    // Reload
    $rest_stmt->execute();
    $restaurant = $rest_stmt->get_result()->fetch_assoc();
    $restaurant_services = [];
    if (!empty($restaurant['services_offered'])) {
        $decoded = json_decode($restaurant['services_offered'], true);
        if (is_array($decoded)) {
            $restaurant_services = $decoded;
        }
    }
}

// ── Handle menu item add (legacy fallback – AJAX endpoint is preferred) ───────
// This block is intentionally kept as a no-op for direct POST to this page.
// All new menu items are saved via dashboard/business_owner/api/add_menu_item.php.

// ── Handle menu item delete ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_menu_item']) && $restaurant) {
    $mi_id = intval($_POST['delete_menu_item']);
    $conn->query("DELETE FROM menu_items WHERE id=$mi_id AND restaurant_id={$restaurant['id']}");
    $success = 'Menu item removed.';
}

// ── Load menu items ───────────────────────────────────────────────────────────
$menu_items = [];
if ($restaurant && !empty($restaurant['id'])) {
    $mi_stmt = $conn->prepare("SELECT id, name, description, price, category, image_path FROM menu_items WHERE restaurant_id = ? AND name IS NOT NULL AND TRIM(name) != '' ORDER BY category ASC, name ASC");
    $mi_stmt->bind_param("i", $restaurant['id']);
    $mi_stmt->execute();
    $menu_items = $mi_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
}
// Normalize and filter out any items with empty names (defensive)
$valid_menu_items = array_values(array_filter($menu_items, function($d) {
    return !empty(trim($d['name'] ?? ''));
}));

// Parse operating hours back to time inputs
$open_from_val = ''; $open_to_val = '';
if (!empty($restaurant['operating_hours'])) {
    // Format: "08:00 AM – 10:00 PM"
    if (preg_match('/(\d{1,2}:\d{2}\s*[AP]M)\s*[–\-]\s*(\d{1,2}:\d{2}\s*[AP]M)/i', $restaurant['operating_hours'], $m)) {
        $open_from_val = date('H:i', strtotime($m[1]));
        $open_to_val   = date('H:i', strtotime($m[2]));
    }
}

// ── PHP helper: render a single menu-item card ───────────────────────────────
function buildMenuItemHTML(array $dish, int $restaurant_id): string {
    $id       = (int)($dish['id']           ?? 0);
    $name     = htmlspecialchars($dish['name']         ?? '');
    $cat      = htmlspecialchars($dish['category']     ?? 'Main Course');
    $desc     = htmlspecialchars($dish['description']  ?? '');
    $serving  = htmlspecialchars($dish['serving_type'] ?? '');
    $price    = formatCurrency($dish['price'] ?? 0);
    $img_url  = !empty($dish['image_url'])
                    ? htmlspecialchars($dish['image_url'])
                    : (!empty($dish['image_path'])
                        ? BASE_URL . 'uploads/' . htmlspecialchars($dish['image_path'])
                        : '');

    $img_html = $img_url
        ? '<img src="' . $img_url . '" style="width:48px;height:48px;object-fit:cover;border-radius:8px;flex-shrink:0;" alt="' . $name . '">'
        : '<div style="width:48px;height:48px;background:#e2e8f0;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas fa-image" style="color:#94a3b8;font-size:1.1rem;"></i></div>';

    $desc_html    = $desc    ? '<div style="font-size:0.78rem;color:var(--neutral-500);margin-top:2px;">' . $desc . '</div>' : '';
    $serving_html = $serving ? '<div style="font-size:0.78rem;color:var(--neutral-500);margin-top:2px;">Serving: ' . $serving . '</div>' : '';

    // JSON payload for openEditRow()
    $edit_json = htmlspecialchars(json_encode([
        'id'           => $id,
        'name'         => $dish['name']         ?? '',
        'description'  => $dish['description']  ?? '',
        'price'        => $dish['price']         ?? 0,
        'category'     => $dish['category']      ?? 'Main Course',
        'serving_type' => $dish['serving_type']  ?? 'Per serving',
        'image_url'    => $img_url ?: '',
    ]), ENT_QUOTES);

    $edit_btn = $id ? '<button type="button" class="btn btn-sm btn-outline" title="Edit"
        onclick="openEditRow(this,' . $edit_json . ')"><i class="fas fa-pencil-alt"></i></button>' : '';

    $delete_html = $id ? '
        <form method="POST" style="margin:0;" onsubmit="return confirm(\'Remove this dish?\')">
            <input type="hidden" name="delete_menu_item" value="' . $id . '">
            <button type="submit" class="btn btn-sm btn-danger" style="padding:4px 8px;"><i class="fas fa-trash"></i></button>
        </form>' : '';

    return '
    <div data-item-id="' . $id . '" style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
        ' . $img_html . '
        <div style="flex:1;min-width:0;">
            <div style="font-weight:700;font-size:0.9rem;">' . $name . '</div>
            <div style="font-size:0.78rem;color:var(--neutral-400);">' . $cat . '</div>
            ' . $desc_html . $serving_html . '
        </div>
        <div style="font-weight:700;color:var(--primary-700);white-space:nowrap;">' . $price . '</div>
        ' . $edit_btn . $delete_html . '
    </div>';
}

require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?= htmlspecialchars($success) ?></span><button class="close-alert"><i class="fas fa-times"></i></button></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i><span><?= htmlspecialchars($error) ?></span><button class="close-alert"><i class="fas fa-times"></i></button></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 380px;gap:24px;align-items:start;">

<!-- ── LEFT: Restaurant Setup Form ────────────────────────────────────────── -->
<div>
<div class="card" style="margin-bottom:24px;">
    <div class="card-header">
        <h3><i class="fas fa-store" style="color:var(--primary-600);margin-right:8px;"></i>
            <?= $restaurant ? 'Restaurant Details' : 'Register Your Restaurant' ?>
        </h3>
        <?php if ($restaurant): ?>
        <span style="background:#dcfce7;color:#166534;padding:4px 12px;border-radius:99px;font-size:0.78rem;font-weight:700;"><i class="fas fa-check-circle" style="margin-right:4px;"></i>Registered</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="POST" id="restForm">
            <input type="hidden" name="save_restaurant" value="1">

            <!-- Auto-filled read-only fields -->
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 16px;margin-bottom:20px;">
                <div style="font-size:0.78rem;font-weight:700;color:#166534;text-transform:uppercase;margin-bottom:10px;letter-spacing:.04em;">
                    <i class="fas fa-lock" style="margin-right:5px;"></i> Auto-Filled from Your Halal Application
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label style="font-size:0.8rem;">Restaurant / Business Name</label>
                        <input type="text" name="restaurant_name" class="form-control"
                            value="<?= htmlspecialchars($restaurant['restaurant_name'] ?? $auto_name) ?>"
                            readonly style="background:#fff;cursor:not-allowed;color:var(--neutral-600);">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label style="font-size:0.8rem;">Business Address</label>
                        <input type="text" name="address" class="form-control"
                            value="<?= htmlspecialchars($restaurant['address'] ?? $auto_address) ?>"
                            readonly style="background:#fff;cursor:not-allowed;color:var(--neutral-600);">
                    </div>
                </div>
            </div>

            <!-- Editable fields -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                <div class="form-group">
                    <label>Cuisine Type <span class="required">*</span></label>
                    <select name="cuisine_type" class="form-control" required>
                        <option value="">Select cuisine...</option>
                        <?php foreach (['Filipino','Middle Eastern','Indian','Malaysian','Indonesian','Turkish','Mediterranean','Asian Fusion','Seafood','Fast Food','Other'] as $t): ?>
                        <option value="<?= $t ?>" <?= ($restaurant['cuisine_type'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="font-weight:600;font-size:0.875rem;color:var(--neutral-700);display:block;margin-bottom:6px;">Operation Hours <span class="required">*</span></label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="time" name="open_from" class="form-control" style="flex:1;"
                            value="<?= htmlspecialchars($open_from_val) ?>" required>
                        <span style="color:var(--neutral-400);font-weight:600;">to</span>
                        <input type="time" name="open_to" class="form-control" style="flex:1;"
                            value="<?= htmlspecialchars($open_to_val) ?>" required>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="3"
                    placeholder="Briefly describe your restaurant — specialties, ambiance, halal commitment..."><?= htmlspecialchars($restaurant['description'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label>Services Offered</label>
                <div style="display:grid;grid-template-columns:repeat(3,minmax(130px,1fr));gap:10px;">
                    <?php foreach ($allowed_services as $service): ?>
                    <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;cursor:pointer;background:#fff;">
                        <input type="checkbox" name="services_offered[]" value="<?= htmlspecialchars($service) ?>"
                            <?= in_array($service, $restaurant_services, true) ? 'checked' : '' ?> style="margin:0;">
                        <span style="font-size:0.9rem;color:var(--neutral-700);"><?= htmlspecialchars($service) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <small style="color:var(--neutral-500);">Select all available service options for your restaurant.</small>
            </div>

            <div style="display:flex;justify-content:flex-end;">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?= $restaurant ? 'Update' : 'Register' ?> Restaurant
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Menu Actions ───────────────────────────────────────────────────────── -->
<?php if ($restaurant): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-utensils" style="color:var(--accent-500);margin-right:8px;"></i> Menu</h3>
        <button type="button" class="btn btn-primary btn-sm" id="addDishBtn">
            <i class="fas fa-plus"></i> Add Dish
        </button>
    </div>
    <div class="card-body">

        <!-- Toast notification area -->
        <div id="menuToast" style="display:none;padding:10px 16px;border-radius:8px;font-size:0.88rem;font-weight:600;margin-bottom:16px;"></div>

        <?php if (defined('FEATURE_MENU_UI') && FEATURE_MENU_UI): ?>
            <!-- Current menu list — items are appended here by JS after AJAX save -->
            <div id="currentMenuSection" <?= empty($valid_menu_items) ? 'style="display:none;margin-bottom:20px;"' : 'style="margin-bottom:20px;"' ?>>
                <div style="font-weight:700;font-size:0.82rem;text-transform:uppercase;color:var(--neutral-500);margin-bottom:10px;">Current Menu</div>
                <div id="menuItemsList" style="display:flex;flex-direction:column;gap:8px;">
                <?php foreach ($valid_menu_items as $dish): ?>
                <?= buildMenuItemHTML($dish, $restaurant['id']) ?>
                <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <div style="margin-bottom:12px;color:var(--neutral-500);">Menu listing is currently hidden.</div>
        <?php endif; ?>

        <!-- Add-dish form — submitted via AJAX, no page reload -->
        <div id="dishRows" style="display:flex;flex-direction:column;gap:14px;">
            <!-- Rows injected by addDishRow() -->
        </div>
        <div id="noRowsMsg" style="text-align:center;padding:24px;color:var(--neutral-400);font-size:0.88rem;">
            <i class="fas fa-plus-circle" style="font-size:1.5rem;display:block;margin-bottom:6px;"></i>
            Click "Add Dish" to add menu items
        </div>
        <div id="menuSubmitRow" style="display:none;margin-top:14px;justify-content:flex-end;gap:10px;">
            <button type="button" id="saveMenuBtn" class="btn btn-accent">
                <i class="fas fa-check"></i> Save All Dishes
            </button>
        </div>
    </div>
</div>
<?php else: ?>
<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    <span>Register your restaurant above first before adding menu items.</span>
</div>
<?php endif; ?>
</div>

<!-- ── RIGHT: Summary panel ────────────────────────────────────────────────── -->
<div style="position:sticky;top:20px;">
    <div class="card">
        <div class="card-header">
            <h3 style="font-size:0.95rem;"><i class="fas fa-info-circle" style="color:var(--primary-600);margin-right:6px;"></i> Profile Summary</h3>
        </div>
        <div class="card-body">
            <?php if ($restaurant): ?>
            <div style="display:flex;flex-direction:column;gap:12px;font-size:0.88rem;">
                <div>
                    <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;color:var(--neutral-400);margin-bottom:2px;">Name</div>
                    <div style="font-weight:600;"><?= htmlspecialchars($restaurant['restaurant_name']) ?></div>
                </div>
                <div>
                    <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;color:var(--neutral-400);margin-bottom:2px;">Address</div>
                    <div><?= htmlspecialchars($restaurant['address']) ?></div>
                </div>
                <?php if (!empty($restaurant['cuisine_type'])): ?>
                <div>
                    <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;color:var(--neutral-400);margin-bottom:2px;">Cuisine</div>
                    <div><?= htmlspecialchars($restaurant['cuisine_type']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($restaurant['operating_hours'])): ?>
                <div>
                    <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;color:var(--neutral-400);margin-bottom:2px;">Hours</div>
                    <div><?= htmlspecialchars($restaurant['operating_hours']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (defined('FEATURE_MENU_UI') && FEATURE_MENU_UI && !empty($valid_menu_items) && count($valid_menu_items) > 0): ?>
                <div>
                    <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;color:var(--neutral-400);margin-bottom:2px;">Menu Items</div>
                    <div><?= count($valid_menu_items) ?> dish<?= count($valid_menu_items) !== 1 ? 'es' : '' ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($restaurant['cert_status'])): ?>
                <div style="padding:10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;text-align:center;">
                    <i class="fas fa-certificate" style="color:var(--accent-500);font-size:1.2rem;display:block;margin-bottom:4px;"></i>
                    <div style="font-weight:700;font-size:0.82rem;color:#166534;">Halal Certified</div>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div style="text-align:center;padding:20px;color:var(--neutral-400);">
                <i class="fas fa-store" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
                <div style="font-size:0.85rem;">Fill in the form to register your restaurant.</div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</div><!-- end grid -->

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

<?php if ($restaurant): ?>
<script>
(function () {
    'use strict';

    const ADD_URL       = '<?= BASE_URL ?>dashboard/business_owner/api/add_menu_item.php';
    const UPDATE_URL    = '<?= BASE_URL ?>dashboard/business_owner/api/update_menu_item.php';
    const CURRENCY      = '₱';
    let   dishCount     = 0;

    const dishRowsEl      = document.getElementById('dishRows');
    const noRowsMsg       = document.getElementById('noRowsMsg');
    const menuSubmitRow   = document.getElementById('menuSubmitRow');
    const saveMenuBtn     = document.getElementById('saveMenuBtn');
    const addDishBtn      = document.getElementById('addDishBtn');
    const menuToast       = document.getElementById('menuToast');
    const menuItemsList   = document.getElementById('menuItemsList');
    const currentMenuSect = document.getElementById('currentMenuSection');

    function showToast(msg, isError) {
        menuToast.textContent      = msg;
        menuToast.style.display    = 'block';
        menuToast.style.background = isError ? '#fef2f2' : '#f0fdf4';
        menuToast.style.border     = isError ? '1px solid #fecaca' : '1px solid #bbf7d0';
        menuToast.style.color      = isError ? '#991b1b' : '#166534';
        clearTimeout(menuToast._timer);
        menuToast._timer = setTimeout(() => { menuToast.style.display = 'none'; }, 5000);
    }

    /* ── Build a dish input row (shared by add + edit) ──────────────────────── */
    function buildRow(item) {
        const idx    = dishCount++;
        const isEdit = item !== null;
        const itemId = isEdit ? item.id : null;

        const wrap = document.createElement('div');
        wrap.className = 'dish-row';
        if (isEdit) wrap.dataset.editId = itemId;
        wrap.style.cssText = [
            'background:#f8fafc',
            'border:1px solid ' + (isEdit ? '#bfdbfe' : '#e2e8f0'),
            'border-radius:12px',
            'padding:14px 16px',
            'display:flex',
            'flex-direction:column',
            'gap:10px',
        ].join(';');

        const cats   = <?= json_encode(MENU_ITEM_CATEGORIES) ?>;
        const serves = <?= json_encode(ALLOWED_SERVING_TYPES) ?>;
        const catOpts  = cats.map(c   => `<option value="${esc(c)}"${isEdit && item.category     === c ? ' selected' : ''}>${esc(c)}</option>`).join('');
        const servOpts = serves.map(s => `<option value="${esc(s)}"${isEdit && item.serving_type === s ? ' selected' : ''}>${esc(s)}</option>`).join('');

        const imgStyle   = isEdit && item.image_url ? `background-image:url('${esc(item.image_url)}');background-size:cover;background-position:center;` : '';
        const iconHidden = isEdit && item.image_url ? 'display:none;' : '';

        wrap.innerHTML = `
            ${isEdit ? `<div style="font-size:.75rem;font-weight:700;color:#1d4ed8;text-transform:uppercase;letter-spacing:.05em;"><i class="fas fa-pencil-alt" style="margin-right:4px;"></i>Editing: ${esc(item.name)}</div>` : ''}
            <div style="display:flex;align-items:flex-end;gap:10px;flex-wrap:nowrap;">
                <div style="flex-shrink:0;">
                    <div class="dish-field-label">Photo</div>
                    <label id="imgLabel${idx}"
                        style="display:flex;flex-direction:column;align-items:center;justify-content:center;
                               border:2px dashed #cbd5e1;border-radius:8px;width:72px;height:72px;
                               cursor:pointer;background:#fff;overflow:hidden;position:relative;${imgStyle}">
                        <i class="fas fa-camera" style="color:#94a3b8;font-size:1.2rem;${iconHidden}" id="imgIcon${idx}"></i>
                        <input type="file" name="dish_image_${idx}" accept="image/*" style="display:none;"
                               data-idx="${idx}" class="dish-img-input">
                    </label>
                </div>
                <div style="flex:1 1 140px;min-width:0;">
                    <div class="dish-field-label">Dish Name <span style="color:var(--danger);">*</span></div>
                    <input type="text" name="dish_name_${idx}" class="form-control dish-name-input"
                           value="${isEdit ? esc(item.name) : ''}"
                           placeholder="e.g. Grilled Chicken" style="font-size:0.88rem;">
                </div>
                <div style="flex:0 0 100px;">
                    <div class="dish-field-label">Price (₱) <span style="color:var(--danger);">*</span></div>
                    <input type="number" name="dish_price_${idx}" class="form-control dish-price-input"
                           value="${isEdit ? parseFloat(item.price).toFixed(2) : ''}"
                           min="0" step="0.01" placeholder="0.00" style="font-size:0.88rem;">
                </div>
                <div style="flex:0 0 140px;">
                    <div class="dish-field-label">Category</div>
                    <select name="dish_cat_${idx}" class="form-control dish-cat-input" style="font-size:0.85rem;">${catOpts}</select>
                </div>
                <div style="flex:0 0 130px;">
                    <div class="dish-field-label">Serving Type</div>
                    <select name="dish_serving_${idx}" class="form-control dish-serve-input" style="font-size:0.85rem;">${servOpts}</select>
                </div>
                <div style="flex-shrink:0;padding-bottom:2px;">
                    <button type="button" class="dish-remove-btn" title="${isEdit ? 'Cancel edit' : 'Remove dish'}"
                        style="width:36px;height:38px;border:1px solid #fecaca;background:#fef2f2;
                               border-radius:8px;cursor:pointer;color:#dc2626;font-size:0.9rem;
                               display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div>
                <div class="dish-field-label">Brief Description <span style="color:var(--neutral-400);font-weight:400;">(optional)</span></div>
                <textarea name="dish_desc_${idx}" class="form-control dish-desc-input"
                          rows="2" placeholder="Describe the dish — ingredients, flavour profile, allergens…"
                          style="font-size:0.83rem;resize:vertical;">${isEdit ? esc(item.description || '') : ''}</textarea>
            </div>`;

        // Image preview
        wrap.querySelector('.dish-img-input').addEventListener('change', function () {
            const label = document.getElementById('imgLabel' + this.dataset.idx);
            const icon  = document.getElementById('imgIcon'  + this.dataset.idx);
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = e => {
                    if (icon) icon.style.display = 'none';
                    label.style.backgroundImage    = `url('${e.target.result}')`;
                    label.style.backgroundSize     = 'cover';
                    label.style.backgroundPosition = 'center';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });

        // Cancel / remove
        wrap.querySelector('.dish-remove-btn').addEventListener('click', function () {
            if (isEdit && itemId) {
                const orig = menuItemsList && menuItemsList.querySelector(`[data-item-id="${itemId}"]`);
                if (orig) orig.style.display = '';
            }
            wrap.remove();
            if (dishRowsEl.querySelectorAll('.dish-row').length === 0) {
                if (noRowsMsg) noRowsMsg.style.display = 'block';
                menuSubmitRow.style.display = 'none';
            }
        });

        return wrap;
    }

    function addDishRow() {
        if (noRowsMsg) noRowsMsg.style.display = 'none';
        menuSubmitRow.style.display = 'flex';
        dishRowsEl.appendChild(buildRow(null));
    }

    window.openEditRow = function (btn, item) {
        if (dishRowsEl.querySelector(`[data-edit-id="${item.id}"]`)) return;
        const card = btn.closest('[data-item-id]');
        if (card) card.style.display = 'none';
        if (noRowsMsg) noRowsMsg.style.display = 'none';
        menuSubmitRow.style.display = 'flex';
        const row = buildRow(item);
        dishRowsEl.appendChild(row);
        row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };

    async function saveAllDishes() {
        const rows = dishRowsEl.querySelectorAll('.dish-row');
        if (!rows.length) return;

        saveMenuBtn.disabled  = true;
        saveMenuBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

        let saved = 0, failed = 0;

        for (const row of rows) {
            const nameInput = row.querySelector('[name^="dish_name_"]');
            const dishName  = nameInput ? nameInput.value.trim() : '';
            if (!dishName) { failed++; continue; }

            const isEdit = !!row.dataset.editId;
            const url    = isEdit ? UPDATE_URL : ADD_URL;

            const fd = new FormData();
            if (isEdit) fd.append('item_id', row.dataset.editId);
            fd.append('dish_name',         dishName);
            fd.append('dish_price',        row.querySelector('[name^="dish_price_"]')?.value   ?? '0');
            fd.append('dish_cat',          row.querySelector('[name^="dish_cat_"]')?.value     ?? 'Main Course');
            fd.append('dish_serving_type', row.querySelector('[name^="dish_serving_"]')?.value ?? 'Per serving');
            fd.append('dish_desc',         row.querySelector('[name^="dish_desc_"]')?.value    ?? '');

            const imgInput = row.querySelector('.dish-img-input');
            if (imgInput && imgInput.files && imgInput.files[0]) {
                fd.append('dish_image', imgInput.files[0]);
            }

            try {
                const res  = await fetch(url, { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    saved++;
                    if (isEdit) {
                        const old = menuItemsList && menuItemsList.querySelector(`[data-item-id="${row.dataset.editId}"]`);
                        if (old) old.remove();
                    }
                    row.remove();
                    appendMenuCard(data.item);
                } else {
                    failed++;
                    console.warn('Save failed:', data.message);
                }
            } catch (err) {
                failed++;
                console.error('Network error:', err);
            }
        }

        if (dishRowsEl.querySelectorAll('.dish-row').length === 0) {
            if (noRowsMsg) noRowsMsg.style.display = 'block';
            menuSubmitRow.style.display = 'none';
        }

        saveMenuBtn.disabled  = false;
        saveMenuBtn.innerHTML = '<i class="fas fa-check"></i> Save All Dishes';

        if (saved > 0 && failed === 0)      showToast(`${saved} item${saved !== 1 ? 's' : ''} saved successfully!`, false);
        else if (saved > 0 && failed > 0)   showToast(`${saved} saved, ${failed} failed. Check required fields.`, true);
        else                                showToast('Could not save. Make sure dish names are filled in.', true);
    }

    function appendMenuCard(item) {
        if (!menuItemsList) return;
        if (currentMenuSect) currentMenuSect.style.display = '';

        const price    = CURRENCY + ' ' + parseFloat(item.price).toFixed(2);
        const imgHtml  = item.image_url
            ? `<img src="${esc(item.image_url)}" style="width:48px;height:48px;object-fit:cover;border-radius:8px;flex-shrink:0;" alt="${esc(item.name)}">`
            : `<div style="width:48px;height:48px;background:#e2e8f0;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas fa-image" style="color:#94a3b8;font-size:1.1rem;"></i></div>`;
        const descHtml    = item.description  ? `<div style="font-size:0.78rem;color:var(--neutral-500);margin-top:2px;">${esc(item.description)}</div>` : '';
        const servingHtml = item.serving_type ? `<div style="font-size:0.78rem;color:var(--neutral-500);margin-top:2px;">Serving: ${esc(item.serving_type)}</div>` : '';

        const card = document.createElement('div');
        card.dataset.itemId = item.id;
        card.style.cssText  = 'display:flex;align-items:center;gap:12px;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;';
        card.innerHTML = `
            ${imgHtml}
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;font-size:0.9rem;">${esc(item.name)}</div>
                <div style="font-size:0.78rem;color:var(--neutral-400);">${esc(item.category)}</div>
                ${descHtml}${servingHtml}
            </div>
            <div style="font-weight:700;color:var(--primary-700);white-space:nowrap;">${price}</div>
            <button type="button" class="btn btn-sm btn-outline" title="Edit"
                    onclick="openEditRow(this, ${JSON.stringify(item).replace(/</g,'\\u003c')})">
                <i class="fas fa-pencil-alt"></i>
            </button>
            <form method="POST" style="margin:0;" onsubmit="return confirm('Remove this dish?')">
                <input type="hidden" name="delete_menu_item" value="${item.id}">
                <button type="submit" class="btn btn-sm btn-danger" style="padding:4px 8px;"><i class="fas fa-trash"></i></button>
            </form>`;

        menuItemsList.appendChild(card);
    }

    function esc(str) {
        const d = document.createElement('div');
        d.textContent = String(str ?? '');
        return d.innerHTML;
    }

    if (addDishBtn)  addDishBtn.addEventListener('click', addDishRow);
    if (saveMenuBtn) saveMenuBtn.addEventListener('click', saveAllDishes);

})();
</script>

<?php endif; ?>
