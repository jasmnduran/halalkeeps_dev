<?php
/**
 * Staff Application Handler
 *
 * Thin REST wrapper that maps incoming action values to the canonical
 * staff_app_action.php handler (which owns the approve/reject logic,
 * restaurant_staff upsert, user promotion, and notifications).
 *
 * Accepted POST actions:
 *   approve_application  — maps to action=approved  in staff_app_action.php
 *   reject_application   — maps to action=rejected  in staff_app_action.php
 *
 * Passthrough params: app_id, owner_notes
 */
ob_start();
require_once __DIR__ . '/../../../config/app.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);

header('Content-Type: application/json; charset=utf-8');

function json_out(bool $ok, string $msg): void {
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => $ok, 'message' => $msg]);
    exit();
}

$incoming_action = trim($_POST['action'] ?? '');

$action_map = [
    'approve_application' => 'approved',
    'reject_application'  => 'rejected',
];

if (!isset($action_map[$incoming_action])) {
    json_out(false, 'Invalid action: ' . htmlspecialchars($incoming_action));
}

// Re-map and delegate to the canonical handler via an internal include.
// Override $_POST['action'] so staff_app_action.php sees the value it expects.
$_POST['action'] = $action_map[$incoming_action];

// Map frontend param names → staff_app_action.php param names if needed
if (!isset($_POST['app_id']) && isset($_POST['application_id'])) {
    $_POST['app_id'] = $_POST['application_id'];
}
if (!isset($_POST['owner_notes']) && isset($_POST['rejection_reason'])) {
    $_POST['owner_notes'] = $_POST['rejection_reason'];
}

// staff_app_action.php will call exit() after outputting JSON — just include it.
require __DIR__ . '/staff_app_action.php';
