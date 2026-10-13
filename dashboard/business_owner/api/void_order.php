<?php
/**
 * Void Order API  —  POST
 * Requires a valid manager override PIN.
 * Cancels the order (order_status = 'cancelled').
 */
require_once __DIR__ . '/../../../config/app.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);

header('Content-Type: application/json; charset=utf-8');

function jout(bool $ok, string $msg): void {
    echo json_encode(['success' => $ok, 'message' => $msg]);
    exit();
}

$order_id    = (int)($_POST['order_id']    ?? 0);
$manager_pin = trim($_POST['manager_pin']  ?? '');

if (!$order_id)      jout(false, 'Invalid order ID.');
if (strlen($manager_pin) < 4) jout(false, 'PIN must be at least 4 digits.');

// Resolve restaurant for current user
$rest_id = getSessionRestaurantId($conn);
if (!$rest_id) jout(false, 'No restaurant linked to your account.');

// Verify order belongs to this restaurant
$os = $conn->prepare(
    "SELECT id, order_status, order_number FROM orders WHERE id = ? AND restaurant_id = ? LIMIT 1"
);
$os->bind_param("ii", $order_id, $rest_id);
$os->execute();
$order = $os->get_result()->fetch_assoc();

if (!$order)                         jout(false, 'Order not found.');
if ($order['order_status'] === 'cancelled') jout(false, 'This order is already voided.');

// Verify manager PIN
$auth = authorizeManagerAction($conn, $rest_id, $manager_pin);
if (!$auth['ok']) jout(false, $auth['error']);

// Cancel the order
$upd = $conn->prepare(
    "UPDATE orders SET order_status='cancelled', updated_at=NOW() WHERE id = ? AND restaurant_id = ?"
);
$upd->bind_param("ii", $order_id, $rest_id);
if (!$upd->execute()) jout(false, 'Failed to void order: ' . $upd->error);

logActivity($conn, $_SESSION['user_id'],
    'Order Voided',
    "Order #{$order['order_number']} voided by manager: {$auth['manager_name']}",
    'pos'
);

jout(true, "Order {$order['order_number']} has been voided by {$auth['manager_name']}.");
