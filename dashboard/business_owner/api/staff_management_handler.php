<?php
/**
 * Staff Management Handler
 * Handles deactivation and reactivation of restaurant_staff rows.
 *
 * POST actions:
 *   deactivate  — set is_active = 0 for a restaurant_staff row
 *   reactivate  — set is_active = 1 for a restaurant_staff row
 */
ob_start();
require_once __DIR__ . '/../../../config/app.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);
requireStaffAccess('Staff Management Handler');

header('Content-Type: application/json; charset=utf-8');

function json_out(bool $ok, string $msg): void {
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => $ok, 'message' => $msg]);
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$rest_id = getSessionRestaurantId($conn);

if (!$rest_id || !isRestaurantOwner($conn, $user_id, $rest_id)) {
    json_out(false, 'Not authorized.');
}

$action        = trim($_POST['action']        ?? '');
$assignment_id = (int)($_POST['assignment_id'] ?? 0);

if ($assignment_id <= 0) {
    json_out(false, 'Missing or invalid assignment_id.');
}

try {
    if ($action === 'deactivate') {
        $result = deactivateStaffAssignment($conn, $assignment_id, $rest_id, $user_id);
    } elseif ($action === 'reactivate') {
        $result = reactivateStaffAssignment($conn, $assignment_id, $rest_id, $user_id);
    } else {
        json_out(false, 'Unknown action: ' . htmlspecialchars($action));
    }

    json_out($result['success'], $result['message']);

} catch (Throwable $e) {
    json_out(false, 'Error: ' . $e->getMessage());
}
