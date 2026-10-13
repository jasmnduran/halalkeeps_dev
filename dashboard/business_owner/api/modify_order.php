<?php
/**
 * Modify Order API  —  POST
 * Requires a valid manager override PIN.
 * Updates the order notes (post-placement modification record).
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
$new_notes   = trim(strip_tags($_POST['notes'] ?? ''));

if (!$order_id)      jout(false, 'Invalid order ID.');
if (strlen($manager_pin) < 4) jout(false, 'PIN must be at least 4 digits.');

// Resolve restaurant for current user
$rest_id = getSessionRestaurantId($conn);
if (!$rest_id) jout(false, 'No restaurant linked to your account.');

// Verify order belongs to this restaurant and is not already cancelled
$os = $conn->prepare(
    "SELECT id, order_number, order_status FROM orders WHERE id = ? AND restaurant_id = ? LIMIT 1"
);
$os->bind_param("ii", $order_id, $rest_id);
$os->execute();
$order = $os->get_result()->fetch_assoc();

if (!$order)                              jout(false, 'Order not found.');
if ($order['order_status'] === 'cancelled') jout(false, 'Cannot modify a voided order.');

// Verify manager PIN
$auth = authorizeManagerAction($conn, $rest_id, $manager_pin);
if (!$auth['ok']) jout(false, $auth['error']);

// Update notes and append override record
$override_note = "[Modified by {$auth['manager_name']} — " . date('Y-m-d H:i') . "]: " . $new_notes;
$combined_notes = $order['notes'] ?? '';
$combined_notes = ($combined_notes ? $combined_notes . "\n" : '') . $override_note;

$upd = $conn->prepare(
    "UPDATE orders SET notes = ?, updated_at = NOW() WHERE id = ? AND restaurant_id = ?"
);
$upd->bind_param("sii", $combined_notes, $order_id, $rest_id);
if (!$upd->execute()) jout(false, 'Failed to modify order: ' . $upd->error);

logActivity($conn, $_SESSION['user_id'],
    'Order Modified',
    "Order #{$order['order_number']} modified by manager: {$auth['manager_name']} — Notes: {$new_notes}",
    'pos'
);

jout(true, "Order {$order['order_number']} has been modified by {$auth['manager_name']}.");
