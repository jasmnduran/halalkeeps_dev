<?php
/**
 * Get Order Statuses API
 * 
 * Returns current status for multiple orders (for polling/real-time updates).
 * 
 * POST body (JSON):
 *   {order_ids: [123, 456, 789]}
 * 
 * Returns: JSON {success, orders: [{id, order_status, payment_status}]}
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

if (($_SESSION['role_id'] ?? 0) !== ROLE_CUSTOMER) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true);
$order_ids = $input['order_ids'] ?? [];

if (!is_array($order_ids) || empty($order_ids)) {
    echo json_encode(['success' => false, 'message' => 'No order IDs provided.']);
    exit;
}

// Sanitize order IDs
$order_ids = array_filter(array_map('intval', $order_ids));

if (empty($order_ids)) {
    echo json_encode(['success' => false, 'message' => 'Invalid order IDs.']);
    exit;
}

// Fetch orders (verify ownership)
$placeholders = implode(',', array_fill(0, count($order_ids), '?'));
$query = "
    SELECT id, order_status, payment_status, updated_at
    FROM orders
    WHERE id IN ($placeholders)
      AND customer_id = ?
";

$stmt = $conn->prepare($query);

// Bind parameters: all order_ids (i) + customer_id (i)
$types = str_repeat('i', count($order_ids)) . 'i';
$params = array_merge($order_ids, [$user_id]);

$stmt->bind_param($types, ...$params);
$stmt->execute();

$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Format response
$formatted_orders = [];
foreach ($orders as $order) {
    $formatted_orders[] = [
        'id' => (int)$order['id'],
        'order_status' => $order['order_status'],
        'payment_status' => $order['payment_status'],
        'updated_at' => $order['updated_at']
    ];
}

echo json_encode([
    'success' => true,
    'orders' => $formatted_orders,
    'count' => count($formatted_orders)
]);
