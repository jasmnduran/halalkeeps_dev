<?php
/**
 * Menu Item Approval API
 *
 * Access: ROLE_BUSINESS_OWNER with staff_role_key = owner (null) OR ops_manager
 * Method: POST
 *
 * Actions:
 *   approve — sets approval_status = 'approved', notifies chef/submitter
 *   reject  — sets approval_status = 'rejected', stores rejection_reason, notifies chef/submitter
 *
 * POST params:
 *   action          string  'approve' | 'reject'
 *   item_id         int
 *   rejection_reason string  (required for action=reject)
 */
require_once __DIR__ . '/../../../config/app.php';

header('Content-Type: application/json; charset=utf-8');

function api_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_err('Method not allowed.', 405);
if (!isLoggedIn())                          api_err('Not authenticated.', 401);
if ((int)($_SESSION['role_id'] ?? 0) !== ROLE_BUSINESS_OWNER) api_err('Access denied.', 403);

$user_id   = (int)$_SESSION['user_id'];
$staff_key = $_SESSION['staff_role_key'] ?? null;

// ── Role gate: only owner (staff_key = null) or ops_manager may approve/reject ─
$can_approve = ($staff_key === null || $staff_key === STAFF_ROLE_OPS_MANAGER);
if (!$can_approve) {
    api_err('Only the Business Owner or Operations Manager can approve menu items.', 403);
}

// ── Resolve restaurant ────────────────────────────────────────────────────────
if ($staff_key === STAFF_ROLE_OPS_MANAGER) {
    $sk = STAFF_ROLE_OPS_MANAGER;
    $rs = $conn->prepare("
        SELECT hr.id AS restaurant_id, hr.business_owner_id
        FROM   restaurant_staff rs
        JOIN   restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
        JOIN   halal_restaurants hr        ON rs.restaurant_id = hr.id
        WHERE  rs.user_id = ? AND rsr.role_key = ? AND rs.is_active = 1
        LIMIT  1
    ");
    $rs->bind_param("is", $user_id, $sk);
    $rs->execute();
    $rest_row = $rs->get_result()->fetch_assoc();
    $rs->close();
} else {
    // Direct owner
    $rs = $conn->prepare("SELECT id AS restaurant_id, business_owner_id FROM halal_restaurants WHERE business_owner_id = ? LIMIT 1");
    $rs->bind_param("i", $user_id);
    $rs->execute();
    $rest_row = $rs->get_result()->fetch_assoc();
    $rs->close();
}

if (!$rest_row) api_err('No restaurant found for your account.', 422);
$restaurant_id = (int)$rest_row['restaurant_id'];

// ── Input validation ──────────────────────────────────────────────────────────
$action  = trim($_POST['action'] ?? '');
$item_id = (int)($_POST['item_id'] ?? 0);

if (!in_array($action, ['approve', 'reject'], true)) api_err('Invalid action. Use "approve" or "reject".');
if (!$item_id) api_err('Missing item_id.');

$rejection_reason = '';
if ($action === 'reject') {
    $rejection_reason = trim(strip_tags($_POST['rejection_reason'] ?? ''));
    if ($rejection_reason === '') {
        api_err('A rejection reason is required when rejecting a menu item.');
    }
    if (mb_strlen($rejection_reason) > 255) {
        api_err('Rejection reason must be 255 characters or fewer.');
    }
}

// ── Verify item belongs to this restaurant and is pending ─────────────────────
$chk = $conn->prepare("
    SELECT id, name, approval_status, submitted_by
    FROM   menu_items
    WHERE  id = ? AND restaurant_id = ?
    LIMIT  1
");
$chk->bind_param("ii", $item_id, $restaurant_id);
$chk->execute();
$item = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$item) api_err('Menu item not found or access denied.', 404);
if ($item['approval_status'] === 'approved' && $action === 'approve') {
    api_err('This item is already approved.');
}

// ── Apply the decision ────────────────────────────────────────────────────────
if ($action === 'approve') {
    $upd = $conn->prepare("
        UPDATE menu_items
        SET    approval_status   = 'approved',
               reviewed_by      = ?,
               reviewed_at      = NOW(),
               rejection_reason = NULL
        WHERE  id = ? AND restaurant_id = ?
    ");
    $upd->bind_param("iii", $user_id, $item_id, $restaurant_id);
    if (!$upd->execute()) api_err('Failed to approve item: ' . $upd->error, 500);
    $upd->close();

    $notif_title   = 'Menu Item Approved!';
    $notif_message = '"' . $item['name'] . '" has been approved and is now live on the menu.';
    $notif_type    = 'success';

} else {
    // reject
    $upd = $conn->prepare("
        UPDATE menu_items
        SET    approval_status   = 'rejected',
               reviewed_by      = ?,
               reviewed_at      = NOW(),
               rejection_reason = ?
        WHERE  id = ? AND restaurant_id = ?
    ");
    $upd->bind_param("isii", $user_id, $rejection_reason, $item_id, $restaurant_id);
    if (!$upd->execute()) api_err('Failed to reject item: ' . $upd->error, 500);
    $upd->close();

    $notif_title   = 'Menu Item Needs Revision';
    $notif_message = '"' . $item['name'] . '" was not approved. Reason: ' . $rejection_reason;
    $notif_type    = 'warning';
}

// ── Notify the submitting chef / staff member ─────────────────────────────────
$submitted_by = (int)($item['submitted_by'] ?? 0);
if ($submitted_by && $submitted_by !== $user_id) {
    createNotification(
        $conn,
        $submitted_by,
        $notif_title,
        $notif_message,
        $notif_type,
        BASE_URL . 'dashboard/business_owner/chef_dashboard.php?tab=menu_submit'
    );
}

// ── Audit log ─────────────────────────────────────────────────────────────────
if (function_exists('logActivity')) {
    $detail = "Menu item \"{$item['name']}\" (id={$item_id}) {$action}d by user {$user_id}";
    if ($action === 'reject') $detail .= ". Reason: {$rejection_reason}";
    logActivity($conn, $user_id, 'Menu Item ' . ucfirst($action) . 'd', $detail, 'menu_approval');
}

echo json_encode([
    'success' => true,
    'message' => $action === 'approve'
        ? '"' . $item['name'] . '" is now live on the menu.'
        : '"' . $item['name'] . '" has been rejected. The chef has been notified.',
    'item_id'         => $item_id,
    'action'          => $action,
    'approval_status' => $action === 'approve' ? 'approved' : 'rejected',
]);
