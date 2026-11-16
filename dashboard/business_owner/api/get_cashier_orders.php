<?php
/**
 * Get Cashier Orders API
 * 
 * Fetches customer orders for the cashier/POS interface.
 * Returns paid orders that need processing.
 * 
 * GET parameters:
 *   - status: filter by order_status (optional, defaults to all active)
 *   - limit: number of records (optional, default 50)
 * 
 * Returns: JSON {success, orders[], unread_count}
 */
require_once __DIR__ . '/../../../config/app.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$role_id = (int)($_SESSION['role_id'] ?? 0);
$staff_role_key = $_SESSION['staff_role_key'] ?? null;

if ($role_id !== ROLE_BUSINESS_OWNER && $staff_role_key !== STAFF_ROLE_CASHIER) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Get restaurant ID
$rest_id = getSessionRestaurantId($conn);

if (!$rest_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No restaurant associated with your account.']);
    exit;
}

// Parameters
$status_filter = trim($_GET['status'] ?? '');
$payment_status_filter = trim($_GET['payment_status'] ?? '');
$limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));

// Build query
$query = "
    SELECT o.id, o.order_number, o.customer_id, o.restaurant_id,
           o.items_json, o.subtotal, o.delivery_fee, o.total_amount,
           o.service_type, o.table_number, o.payment_method, o.payment_status,
           o.order_status, o.order_source, o.notes, o.ordered_at, o.created_at,
           u.full_name AS customer_name, u.email AS customer_email,
           o.payment_verified_at
    FROM orders o
    LEFT JOIN users u ON o.customer_id = u.id
    WHERE o.restaurant_id = ?
      AND o.order_source = 'customer'
";

$params = [$rest_id];
$types = 'i';

// Filter by payment_status if provided (for awaiting_cash orders)
if (!empty($payment_status_filter) && in_array($payment_status_filter, ['awaiting_cash', 'paid'], true)) {
    $query .= " AND o.payment_status = ?";
    $params[] = $payment_status_filter;
    $types .= 's';
} else {
    // Default: show only paid orders (exclude awaiting_cash)
    $query .= " AND o.payment_status = 'paid'";
}

// Add status filter if provided
if (!empty($status_filter) && in_array($status_filter, ['pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'], true)) {
    $query .= " AND o.order_status = ?";
    $params[] = $status_filter;
    $types .= 's';
} else {
    // Default: show active orders (not completed or cancelled)
    // For awaiting_cash status, show all including pending_payment
    if (!empty($payment_status_filter) && $payment_status_filter === 'awaiting_cash') {
        $query .= " AND o.order_status NOT IN ('completed', 'cancelled')";
    } else {
        $query .= " AND o.order_status NOT IN ('completed', 'cancelled', 'pending_payment')";
    }
}

$query .= " ORDER BY o.payment_verified_at DESC, o.created_at DESC LIMIT ?";
$params[] = $limit;
$types .= 'i';

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Format orders for response
$formatted_orders = [];
foreach ($orders as $order) {
    $items = json_decode($order['items_json'] ?? '[]', true) ?: [];
    
    $formatted_orders[] = [
        'id' => (int)$order['id'],
        'order_number' => $order['order_number'],
        'customer_name' => $order['customer_name'] ?? 'Guest',
        'customer_email' => $order['customer_email'] ?? '',
        'items' => $items,
        'item_count' => array_sum(array_column($items, 'qty')),
        'subtotal' => (float)$order['subtotal'],
        'delivery_fee' => (float)$order['delivery_fee'],
        'total_amount' => (float)$order['total_amount'],
        'service_type' => $order['service_type'],
        'table_number' => $order['table_number'] ?? '',
        'payment_method' => $order['payment_method'],
        'payment_status' => $order['payment_status'],
        'order_status' => $order['order_status'],
        'order_source' => $order['order_source'],
        'notes' => $order['notes'] ?? '',
        'ordered_at' => $order['ordered_at'],
        'created_at' => $order['created_at'],
        'payment_verified_at' => $order['payment_verified_at'],
        'is_paid' => $order['payment_status'] === 'paid',
        'is_customer_order' => $order['order_source'] === 'customer'
    ];
}

// Get unread notification count for cashier
$unread_count = 0;
if ($staff_role_key === STAFF_ROLE_CASHIER) {
    db_add_column($conn, 'cashier_notifications', 'id', 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');
    
    $unread_stmt = $conn->prepare("
        SELECT COUNT(*) as count
        FROM cashier_notifications
        WHERE restaurant_id = ?
          AND (cashier_user_id = ? OR cashier_user_id IS NULL)
          AND is_read = 0
    ");
    $unread_stmt->bind_param("ii", $rest_id, $user_id);
    $unread_stmt->execute();
    $unread_result = $unread_stmt->get_result()->fetch_assoc();
    $unread_count = (int)($unread_result['count'] ?? 0);
    $unread_stmt->close();
}

echo json_encode([
    'success' => true,
    'orders' => $formatted_orders,
    'order_count' => count($formatted_orders),
    'unread_notifications' => $unread_count,
    'restaurant_id' => $rest_id
]);
