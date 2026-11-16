<?php
/**
 * Update Order Status API
 * 
 * Allows cashiers and business owners to update order status.
 * Changes are synchronized across customer, cashier, and owner views.
 * 
 * POST parameters:
 *   - order_id: int (required)
 *   - new_status: string (required) - confirmed, preparing, ready, completed, cancelled
 *   - notes: string (optional)
 * 
 * Returns: JSON {success, message, order}
 */
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../includes/IngredientInventoryService.php';

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

// Only business owners and cashiers can update order status
$role_id = (int)($_SESSION['role_id'] ?? 0);
$staff_role_key = $_SESSION['staff_role_key'] ?? null;

if ($role_id !== ROLE_BUSINESS_OWNER && $staff_role_key !== STAFF_ROLE_CASHIER) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Only cashiers and owners can update orders.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$order_id = (int)($_POST['order_id'] ?? 0);
$new_status = trim(sanitize($_POST['new_status'] ?? ''));
$notes = trim(sanitize($_POST['notes'] ?? ''));

// Validate inputs
if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID.']);
    exit;
}

// Allowed status transitions
$allowed_statuses = ['pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled', 'failed'];
if (!in_array($new_status, $allowed_statuses, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid order status.']);
    exit;
}

// Get current order and verify access
$order_stmt = $conn->prepare("
    SELECT o.id, o.order_number, o.order_status, o.payment_status, o.payment_method, o.restaurant_id, o.customer_id,
           hr.business_owner_id
    FROM orders o
    JOIN halal_restaurants hr ON o.restaurant_id = hr.id
    WHERE o.id = ?
    LIMIT 1
");
$order_stmt->bind_param("i", $order_id);
$order_stmt->execute();
$order = $order_stmt->get_result()->fetch_assoc();
$order_stmt->close();

if (!$order) {
    echo json_encode(['success' => false, 'message' => 'Order not found.']);
    exit;
}

$restaurant_id = (int)$order['restaurant_id'];
$owner_id = (int)$order['business_owner_id'];
$old_status = $order['order_status'];

// Verify user has access to this restaurant
$has_access = false;

if ($role_id === ROLE_BUSINESS_OWNER) {
    // Check if user is the owner
    if ($user_id === $owner_id) {
        $has_access = true;
    } else {
        // Check if user is staff of this restaurant
        $staff_check = $conn->prepare("
            SELECT id FROM restaurant_staff
            WHERE user_id = ? AND restaurant_id = ? AND is_active = 1
            LIMIT 1
        ");
        $staff_check->bind_param("ii", $user_id, $restaurant_id);
        $staff_check->execute();
        $has_access = $staff_check->get_result()->num_rows > 0;
        $staff_check->close();
    }
}

if (!$has_access) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have access to this order.']);
    exit;
}

if (in_array($order['payment_method'], ['gcash', 'bank_transfer', 'paymongo'], true) && $order['payment_status'] !== 'paid') {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'This online order cannot be processed until PayMongo verifies the payment.']);
    exit;
}

// Validate status transition logic
$valid_transitions = [
    'pending' => ['confirmed', 'cancelled'],
    'confirmed' => ['preparing', 'cancelled'],
    'preparing' => ['ready', 'cancelled'],
    'ready' => ['completed', 'cancelled'],
    'completed' => [], // Terminal state
    'cancelled' => [], // Terminal state
    'failed' => []     // Terminal state
];

if (!in_array($new_status, $valid_transitions[$old_status] ?? [], true) && $new_status !== $old_status) {
    // Allow re-setting the same status (idempotent)
    if ($new_status !== $old_status) {
        echo json_encode([
            'success' => false,
            'message' => "Cannot change status from '{$old_status}' to '{$new_status}'. Invalid transition."
        ]);
        exit;
    }
}

// If status hasn't changed, just return success
if ($new_status === $old_status) {
    echo json_encode([
        'success' => true,
        'message' => 'Order status unchanged.',
        'order' => [
            'id' => $order_id,
            'order_number' => $order['order_number'],
            'order_status' => $new_status
        ]
    ]);
    exit;
}

// Update order status
$update_stmt = $conn->prepare("
    UPDATE orders
    SET order_status = ?,
        updated_at = NOW()
    WHERE id = ?
");
$update_stmt->bind_param("si", $new_status, $order_id);

if (!$update_stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update order status: ' . $update_stmt->error]);
    $update_stmt->close();
    exit;
}
$update_stmt->close();

// Inventory consumption is intentionally tied to the preparation lifecycle,
// not payment arithmetic. A cancellation releases only still-active holds.
if (IngredientInventoryService::isFeatureEnabled()) {
    try {
        $inventoryService = new IngredientInventoryService($conn);
        if ($new_status === 'preparing') $inventoryService->consumeOrderReservations($order_id, $user_id);
        if ($new_status === 'cancelled') $inventoryService->releaseOrderReservations($order_id, $user_id);
    } catch (Throwable $e) {
        error_log('Ingredient inventory lifecycle update failed for order ' . $order_id . ': ' . $e->getMessage());
    }
}

// Record status change in audit trail
db_add_column($conn, 'order_status_history', 'id', 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');

$changed_by_role = $staff_role_key ?? 'owner';
$audit_notes = !empty($notes) ? $notes : "Status changed from {$old_status} to {$new_status}";

$history_stmt = $conn->prepare("
    INSERT INTO order_status_history
        (order_id, old_status, new_status, changed_by, changed_by_role, notes, changed_at)
    VALUES (?, ?, ?, ?, ?, ?, NOW())
");
$history_stmt->bind_param("ississ", $order_id, $old_status, $new_status, $user_id, $changed_by_role, $audit_notes);
$history_stmt->execute();
$history_stmt->close();

// Send notification to customer about status change
$status_messages = [
    'confirmed' => 'Your order has been confirmed and will be prepared shortly.',
    'preparing' => 'Your order is now being prepared.',
    'ready' => 'Your order is ready!',
    'completed' => 'Your order has been completed. Thank you!',
    'cancelled' => 'Your order has been cancelled.' . (!empty($notes) ? ' Reason: ' . $notes : '')
];

$notification_title = match($new_status) {
    'confirmed' => 'Order Confirmed',
    'preparing' => 'Order Being Prepared',
    'ready' => 'Order Ready!',
    'completed' => 'Order Completed',
    'cancelled' => 'Order Cancelled',
    default => 'Order Status Updated'
};

$notification_type = match($new_status) {
    'completed' => 'success',
    'cancelled' => 'warning',
    default => 'info'
};

createNotification(
    $conn,
    (int)$order['customer_id'],
    $notification_title,
    $status_messages[$new_status] ?? "Your order status has been updated to: {$new_status}",
    $notification_type,
    BASE_URL . 'dashboard/customer/orders.php'
);

// Log activity
if (function_exists('logActivity')) {
    logActivity(
        $conn,
        $user_id,
        'Order Status Updated',
        "Order #{$order['order_number']} status changed from {$old_status} to {$new_status}",
        'order'
    );
}

// Return success
echo json_encode([
    'success' => true,
    'message' => 'Order status updated successfully.',
    'order' => [
        'id' => $order_id,
        'order_number' => $order['order_number'],
        'old_status' => $old_status,
        'new_status' => $new_status
    ]
]);
