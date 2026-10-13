<?php
/**
 * AJAX endpoint: add a single menu item.
 * Access: business_owner (direct or via staff role: owner, ops_manager, chef)
 *
 * Approval workflow:
 *   - Chef submissions   → approval_status = 'pending'  (requires ops/owner review)
 *   - Owner submissions  → approval_status = 'approved' (owner bypass — direct publish)
 *   - Ops Mgr            → approval_status = 'pending'  (consistent with chef flow)
 *
 * Returns JSON: { success, message, item? }
 */
require_once __DIR__ . '/../../../config/app.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}
if ((int)($_SESSION['role_id'] ?? 0) !== ROLE_BUSINESS_OWNER) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$staff_key = $_SESSION['staff_role_key'] ?? null;

// ── Resolve restaurant ────────────────────────────────────────────────────────
if ($staff_key === STAFF_ROLE_CHEF) {
    $sk = STAFF_ROLE_CHEF;
    $rs = $conn->prepare("
        SELECT hr.id, hr.business_owner_id
        FROM restaurant_staff rs
        JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
        JOIN halal_restaurants hr        ON rs.restaurant_id  = hr.id
        WHERE rs.user_id = ? AND rsr.role_key = ? AND rs.is_active = 1
        LIMIT 1
    ");
    $rs->bind_param("is", $user_id, $sk);
    $rs->execute();
    $restaurant = $rs->get_result()->fetch_assoc();
    $rs->close();
} elseif ($staff_key === STAFF_ROLE_OPS_MANAGER) {
    $sk = STAFF_ROLE_OPS_MANAGER;
    $rs = $conn->prepare("
        SELECT hr.id, hr.business_owner_id
        FROM restaurant_staff rs
        JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
        JOIN halal_restaurants hr        ON rs.restaurant_id  = hr.id
        WHERE rs.user_id = ? AND rsr.role_key = ? AND rs.is_active = 1
        LIMIT 1
    ");
    $rs->bind_param("is", $user_id, $sk);
    $rs->execute();
    $restaurant = $rs->get_result()->fetch_assoc();
    $rs->close();
} else {
    // Direct business owner (no staff sub-role)
    $rs = $conn->prepare("SELECT id, business_owner_id FROM halal_restaurants WHERE business_owner_id = ? LIMIT 1");
    $rs->bind_param("i", $user_id);
    $rs->execute();
    $restaurant = $rs->get_result()->fetch_assoc();
    $rs->close();
}

if (!$restaurant) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No restaurant found for your account.']);
    exit;
}

$restaurant_id    = (int)$restaurant['id'];
$restaurant_owner = (int)$restaurant['business_owner_id'];

// ── Approval status: owners publish directly; all others go to pending ────────
$is_direct_owner = ($staff_key === null && $user_id === $restaurant_owner);
$approval_status = $is_direct_owner ? 'approved' : 'pending';

// ── Idempotent schema migration ───────────────────────────────────────────────
db_add_column($conn, 'menu_items', 'approval_status',
    "ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
db_add_column($conn, 'menu_items', 'submitted_by',     'INT UNSIGNED NULL');
db_add_column($conn, 'menu_items', 'reviewed_by',      'INT UNSIGNED NULL');
db_add_column($conn, 'menu_items', 'reviewed_at',      'DATETIME NULL');
db_add_column($conn, 'menu_items', 'rejection_reason', 'VARCHAR(255) NULL');

// ── Validate & sanitize inputs ────────────────────────────────────────────────
$allowed_serving_types = ALLOWED_SERVING_TYPES;
$allowed_categories    = MENU_ITEM_CATEGORIES;

$dish_name = trim(sanitize($_POST['dish_name'] ?? ''));
if ($dish_name === '') {
    echo json_encode(['success' => false, 'message' => 'Dish name is required.']);
    exit;
}

$dish_price   = max(0, floatval($_POST['dish_price']   ?? 0));
$dish_cat     = sanitize($_POST['dish_cat']            ?? '') ?: 'Main Course';
if (!in_array($dish_cat, $allowed_categories, true)) $dish_cat = 'Main Course';

$dish_desc    = sanitize($_POST['dish_desc']           ?? '');
$dish_serving = sanitize($_POST['dish_serving_type']   ?? 'Per serving');
if (!in_array($dish_serving, $allowed_serving_types, true)) $dish_serving = 'Per serving';

// ── Optional image upload ─────────────────────────────────────────────────────
$img_path = '';
if (!empty($_FILES['dish_image']['name']) && $_FILES['dish_image']['error'] === UPLOAD_ERR_OK) {
    $up = uploadFile($_FILES['dish_image'], 'menu_images', ['jpg','jpeg','png','gif','webp']);
    if ($up['success']) {
        $img_path = $up['path'];
    } else {
        error_log('Menu image upload failed: ' . ($up['message'] ?? 'unknown error'));
    }
}

// ── Insert with approval status ───────────────────────────────────────────────
$ins = $conn->prepare(
    "INSERT INTO menu_items
        (restaurant_id, name, description, price, category, image_path,
         serving_type, approval_status, submitted_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
if (!$ins) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit;
}

$ins->bind_param("issdssssi",
    $restaurant_id,
    $dish_name,
    $dish_desc,
    $dish_price,
    $dish_cat,
    $img_path,
    $dish_serving,
    $approval_status,
    $user_id
);

if (!$ins->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save item: ' . $ins->error]);
    exit;
}
$new_id = (int)$conn->insert_id;

// ── Notifications ─────────────────────────────────────────────────────────────
if ($approval_status === 'pending') {
    // Notify ops_manager(s) and owner to review the submission
    $notify_targets = [];

    // Always notify the restaurant owner
    $notify_targets[] = $restaurant_owner;

    // Also notify any active ops_manager(s) for this restaurant
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
        if ($tid === $user_id) continue; // don't notify yourself
        createNotification(
            $conn, $tid,
            'Menu Item Pending Approval',
            '"' . $dish_name . '" was submitted for approval and needs your review.',
            'action_required',
            BASE_URL . 'dashboard/business_owner/operations_manager.php?tab=menu'
        );
    }
} else {
    // Owner published directly — log only
    if (function_exists('logActivity')) {
        logActivity($conn, $user_id, 'Menu Item Published',
            "Owner directly published \"{$dish_name}\" (id={$new_id})", 'menu');
    }
}

$img_url = $img_path ? BASE_URL . 'uploads/' . $img_path : '';

echo json_encode([
    'success' => true,
    'message' => $approval_status === 'approved'
        ? 'Dish published successfully!'
        : 'Dish submitted for approval. An Operations Manager or Owner will review it.',
    'approval_status' => $approval_status,
    'item' => [
        'id'              => $new_id,
        'name'            => $dish_name,
        'description'     => $dish_desc,
        'price'           => $dish_price,
        'category'        => $dish_cat,
        'serving_type'    => $dish_serving,
        'image_url'       => $img_url,
        'approval_status' => $approval_status,
    ],
]);
