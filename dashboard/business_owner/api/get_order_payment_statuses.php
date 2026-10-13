<?php
/**
 * Business Owner API: Get Order Payment Statuses
 *
 * Returns current payment_status for a set of order IDs that belong to
 * the authenticated business owner's restaurant. Used by manage_orders.php
 * to poll for GCash / Bank Transfer confirmations in real-time.
 *
 * POST body (JSON):
 *   { "order_ids": [123, 456] }
 *
 * Returns:
 *   { "success": true, "orders": [{ "id", "payment_status", "order_status", "updated_at" }] }
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

$role_id = (int)($_SESSION['role_id'] ?? 0);
if ($role_id !== ROLE_BUSINESS_OWNER) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Resolve the restaurant this user owns (or is staff of)
$staff_key = $_SESSION['staff_role_key'] ?? null;

if ($staff_key !== null) {
    // Staff member — get their assigned restaurant
    $rs = $conn->prepare("
        SELECT rs.restaurant_id
        FROM restaurant_staff rs
        WHERE rs.user_id = ? AND rs.is_active = 1
        LIMIT 1
    ");
    $rs->bind_param("i", $user_id);
    $rs->execute();
    $rs_row = $rs->get_result()->fetch_assoc();
    $rs->close();
    $rest_id = (int)($rs_row['restaurant_id'] ?? 0);
} else {
    // Actual owner
    $ro = $conn->prepare("
        SELECT id FROM halal_restaurants WHERE business_owner_id = ? AND is_active = 1 LIMIT 1
    ");
    $ro->bind_param("i", $user_id);
    $ro->execute();
    $ro_row = $ro->get_result()->fetch_assoc();
    $ro->close();
    $rest_id = (int)($ro_row['id'] ?? 0);
}

if ($rest_id === 0) {
    echo json_encode(['success' => false, 'message' => 'No active restaurant found.']);
    exit;
}

// Parse body
$input     = json_decode(file_get_contents('php://input'), true);
$order_ids = $input['order_ids'] ?? [];

if (!is_array($order_ids) || empty($order_ids)) {
    echo json_encode(['success' => false, 'message' => 'No order IDs provided.']);
    exit;
}

$order_ids = array_values(array_filter(array_map('intval', $order_ids)));

if (empty($order_ids)) {
    echo json_encode(['success' => false, 'message' => 'Invalid order IDs.']);
    exit;
}

// Fetch — verify orders belong to this restaurant
$placeholders = implode(',', array_fill(0, count($order_ids), '?'));
$types        = str_repeat('i', count($order_ids)) . 'i';
$params       = array_merge($order_ids, [$rest_id]);

$stmt = $conn->prepare("
    SELECT id, payment_status, order_status, updated_at
    FROM orders
    WHERE id IN ($placeholders)
      AND restaurant_id = ?
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$formatted = array_map(fn($r) => [
    'id'             => (int)$r['id'],
    'payment_status' => $r['payment_status'],
    'order_status'   => $r['order_status'],
    'updated_at'     => $r['updated_at'],
], $rows);

echo json_encode(['success' => true, 'orders' => $formatted, 'count' => count($formatted)]);
