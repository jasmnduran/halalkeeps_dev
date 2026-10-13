<?php
/**
 * AJAX endpoint: update an existing menu item.
 * Access: business_owner (direct or via staff role: owner, ops_manager, chef)
 *
 * Approval workflow:
 *   - Chef / Ops Mgr edits  → approval_status reset to 'pending'
 *   - Direct owner edits    → approval_status stays 'approved' (owner bypass)
 *
 * Returns JSON: { success, message, item? }
 */
require_once __DIR__ . '/../../../config/app.php';

header('Content-Type: application/json; charset=utf-8');

function json_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed.', 405);
if (!isLoggedIn())                          json_err('Not authenticated.', 401);
if ((int)($_SESSION['role_id'] ?? 0) !== ROLE_BUSINESS_OWNER) json_err('Access denied.', 403);

$user_id   = (int)$_SESSION['user_id'];
$staff_key = $_SESSION['staff_role_key'] ?? null;
$item_id   = (int)($_POST['item_id'] ?? 0);
if (!$item_id) json_err('Missing item ID.');

// ── Resolve restaurant ────────────────────────────────────────────────────────
if ($staff_key === STAFF_ROLE_CHEF) {
    $sk = STAFF_ROLE_CHEF;
    $rs = $conn->prepare("
        SELECT hr.id AS restaurant_id, hr.business_owner_id
        FROM restaurant_staff rs
        JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
        JOIN halal_restaurants hr        ON rs.restaurant_id  = hr.id
        WHERE rs.user_id = ? AND rsr.role_key = ? AND rs.is_active = 1
        LIMIT 1
    ");
    $rs->bind_param("is", $user_id, $sk);
    $rs->execute();
    $rest_row = $rs->get_result()->fetch_assoc();
    $rs->close();
} elseif ($staff_key === STAFF_ROLE_OPS_MANAGER) {
    $sk = STAFF_ROLE_OPS_MANAGER;
    $rs = $conn->prepare("
        SELECT hr.id AS restaurant_id, hr.business_owner_id
        FROM restaurant_staff rs
        JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
        JOIN halal_restaurants hr        ON rs.restaurant_id  = hr.id
        WHERE rs.user_id = ? AND rsr.role_key = ? AND rs.is_active = 1
        LIMIT 1
    ");
    $rs->bind_param("is", $user_id, $sk);
    $rs->execute();
    $rest_row = $rs->get_result()->fetch_assoc();
    $rs->close();
} else {
    $rs = $conn->prepare("SELECT id AS restaurant_id, business_owner_id FROM halal_restaurants WHERE business_owner_id = ? LIMIT 1");
    $rs->bind_param("i", $user_id);
    $rs->execute();
    $rest_row = $rs->get_result()->fetch_assoc();
    $rs->close();
}

if (!$rest_row) json_err('No restaurant found for your account.', 422);

$restaurant_id    = (int)$rest_row['restaurant_id'];
$restaurant_owner = (int)$rest_row['business_owner_id'];

// ── Idempotent schema migration ───────────────────────────────────────────────
db_add_column($conn, 'menu_items', 'approval_status',
    "ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
db_add_column($conn, 'menu_items', 'submitted_by',     'INT UNSIGNED NULL');
db_add_column($conn, 'menu_items', 'reviewed_by',      'INT UNSIGNED NULL');
db_add_column($conn, 'menu_items', 'reviewed_at',      'DATETIME NULL');
db_add_column($conn, 'menu_items', 'rejection_reason', 'VARCHAR(255) NULL');

// ── Verify item belongs to this restaurant ────────────────────────────────────
$chk = $conn->prepare("
    SELECT id, image_path, submitted_by, approval_status
    FROM menu_items WHERE id = ? AND restaurant_id = ? LIMIT 1
");
$chk->bind_param("ii", $item_id, $restaurant_id);
$chk->execute();
$existing = $chk->get_result()->fetch_assoc();
$chk->close();
if (!$existing) json_err('Menu item not found or access denied.', 404);

// ── Approval rules ────────────────────────────────────────────────────────────
// Direct owner: keep approved. Chef / ops_manager: reset to pending.
$is_direct_owner = ($staff_key === null && $user_id === $restaurant_owner);
$new_approval    = $is_direct_owner ? 'approved' : 'pending';

// ── Validate inputs ───────────────────────────────────────────────────────────
$allowed_serving = ALLOWED_SERVING_TYPES;
$allowed_cats    = MENU_ITEM_CATEGORIES;

$dish_name = trim(sanitize($_POST['dish_name'] ?? ''));
if ($dish_name === '') json_err('Dish name is required.');

$dish_price   = max(0, floatval($_POST['dish_price']   ?? 0));
$dish_cat     = sanitize($_POST['dish_cat']            ?? '') ?: 'Main Course';
if (!in_array($dish_cat, $allowed_cats, true)) $dish_cat = 'Main Course';

$dish_desc    = sanitize($_POST['dish_desc']           ?? '');
$dish_serving = sanitize($_POST['dish_serving_type']   ?? 'Per serving');
if (!in_array($dish_serving, $allowed_serving, true)) $dish_serving = 'Per serving';

// ── Optional image upload ─────────────────────────────────────────────────────
$img_path = $existing['image_path'];
if (!empty($_FILES['dish_image']['name']) && $_FILES['dish_image']['error'] === UPLOAD_ERR_OK) {
    $up = uploadFile($_FILES['dish_image'], 'menu_images', ['jpg','jpeg','png','gif','webp']);
    if ($up['success']) {
        $img_path = $up['path'];
        if (!empty($existing['image_path'])) {
            $old = UPLOAD_DIR . $existing['image_path'];
            if (is_file($old)) @unlink($old);
        }
    } else {
        error_log('Menu image update upload failed: ' . ($up['message'] ?? 'unknown'));
    }
}

// ── Update ────────────────────────────────────────────────────────────────────
// Reset reviewed_by / reviewed_at / rejection_reason when going back to pending
$upd = $conn->prepare("
    UPDATE menu_items
    SET    name            = ?,
           description     = ?,
           price           = ?,
           category        = ?,
           serving_type    = ?,
           image_path      = ?,
           approval_status = ?,
           submitted_by    = ?,
           reviewed_by     = " . ($new_approval === 'pending' ? 'NULL' : '?') . ",
           reviewed_at     = " . ($new_approval === 'pending' ? 'NULL' : '?') . ",
           rejection_reason= " . ($new_approval === 'pending' ? 'NULL' : '?') . ",
           updated_at      = NOW()
    WHERE  id = ? AND restaurant_id = ?
");
// Build bind based on whether owner bypass keeps review metadata
if ($new_approval === 'pending') {
    // reviewed_by / reviewed_at / rejection_reason are hardcoded NULL above
    if (!$upd) json_err('Database error: ' . $conn->error, 500);
    $upd->bind_param("ssdssssiii",
        $dish_name, $dish_desc, $dish_price, $dish_cat, $dish_serving,
        $img_path, $new_approval, $user_id,
        $item_id, $restaurant_id
    );
} else {
    // Owner keeps existing review fields (just re-use the existing values)
    if (!$upd) json_err('Database error: ' . $conn->error, 500);
    $upd->bind_param("ssdssssissii",
        $dish_name, $dish_desc, $dish_price, $dish_cat, $dish_serving,
        $img_path, $new_approval, $user_id,
        $user_id, '', '',   // reviewed_by, reviewed_at, rejection_reason kept for owner
        $item_id, $restaurant_id
    );
}

// Simpler, cleaner approach — two separate UPDATE shapes:
$upd->close();

if ($new_approval === 'pending') {
    $upd2 = $conn->prepare("
        UPDATE menu_items
        SET    name = ?, description = ?, price = ?, category = ?,
               serving_type = ?, image_path = ?,
               approval_status = 'pending',
               submitted_by = ?,
               reviewed_by = NULL, reviewed_at = NULL, rejection_reason = NULL,
               updated_at = NOW()
        WHERE  id = ? AND restaurant_id = ?
    ");
    if (!$upd2) json_err('Database error: ' . $conn->error, 500);
    $upd2->bind_param("ssdsssiis",
        $dish_name, $dish_desc, $dish_price, $dish_cat, $dish_serving,
        $img_path, $user_id, $item_id, $restaurant_id
    );
    if (!$upd2->execute()) json_err('Failed to update item: ' . $upd2->error, 500);
    $upd2->close();
} else {
    // Owner bypass — stays approved, clear rejection if previously rejected
    $upd2 = $conn->prepare("
        UPDATE menu_items
        SET    name = ?, description = ?, price = ?, category = ?,
               serving_type = ?, image_path = ?,
               approval_status = 'approved',
               rejection_reason = NULL,
               updated_at = NOW()
        WHERE  id = ? AND restaurant_id = ?
    ");
    if (!$upd2) json_err('Database error: ' . $conn->error, 500);
    $upd2->bind_param("ssdsssii",
        $dish_name, $dish_desc, $dish_price, $dish_cat, $dish_serving,
        $img_path, $item_id, $restaurant_id
    );
    if (!$upd2->execute()) json_err('Failed to update item: ' . $upd2->error, 500);
    $upd2->close();
}

// ── Notifications ─────────────────────────────────────────────────────────────
if ($new_approval === 'pending') {
    $notify_targets = [$restaurant_owner];

    $ops_q = $conn->query("
        SELECT rs.user_id
        FROM   restaurant_staff rs
        JOIN   restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
        WHERE  rs.restaurant_id = {$restaurant_id}
          AND  rsr.role_key     = 'ops_manager'
          AND  rs.is_active     = 1
    ");
    if ($ops_q) {
        while ($op = $ops_q->fetch_assoc()) {
            $notify_targets[] = (int)$op['user_id'];
        }
    }

    foreach (array_unique($notify_targets) as $tid) {
        if ($tid === $user_id) continue;
        createNotification(
            $conn, $tid,
            'Menu Item Edit Pending Approval',
            '"' . $dish_name . '" was edited and needs re-approval before going live.',
            'action_required',
            BASE_URL . 'dashboard/business_owner/operations_manager.php?tab=menu'
        );
    }
}

if (function_exists('logActivity')) {
    logActivity($conn, $user_id, 'Menu Item Updated',
        "Updated \"{$dish_name}\" (id={$item_id}), new approval_status={$new_approval}", 'menu');
}

$img_url = $img_path ? BASE_URL . 'uploads/' . $img_path : '';

echo json_encode([
    'success'         => true,
    'message'         => $new_approval === 'approved'
        ? 'Menu item updated and published.'
        : 'Edit submitted for approval. Changes will go live after review.',
    'approval_status' => $new_approval,
    'item'            => [
        'id'              => $item_id,
        'name'            => $dish_name,
        'description'     => $dish_desc,
        'price'           => $dish_price,
        'category'        => $dish_cat,
        'serving_type'    => $dish_serving,
        'image_url'       => $img_url,
        'image_path'      => $img_path,
        'approval_status' => $new_approval,
    ],
]);
