<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();

// Approval updates the database role, but an already signed-in applicant still
// has the previous Customer role in their session. Refresh it before entering
// the owner workspace.
$user_id = (int)$_SESSION['user_id'];
$business_owner_role = ROLE_BUSINESS_OWNER;

$stmt = $conn->prepare(
    "SELECT u.*, r.role_name
     FROM users u
     LEFT JOIN roles r ON r.id = u.role_id
     WHERE u.id = ?
       AND u.role_id = ?
       AND u.role_status = 'approved'
       AND EXISTS (
           SELECT 1
           FROM business_applications ba
           WHERE ba.user_id = u.id AND ba.status = 'approved'
       )
     LIMIT 1"
);
$stmt->bind_param('ii', $user_id, $business_owner_role);
$stmt->execute();
$business_owner = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$business_owner) {
    $_SESSION['flash_message'] = 'Your business dashboard is not available yet.';
    $_SESSION['flash_type'] = 'error';
    header('Location: ' . BASE_URL . 'dashboard/customer/apply_business.php');
    exit();
}

session_regenerate_id(true);
createUserSession($business_owner);

header('Location: ' . BASE_URL . 'dashboard/business_owner/owner_dashboard.php');
exit();
