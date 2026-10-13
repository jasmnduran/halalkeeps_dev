<?php
/**
 * AJAX endpoint: customer confirms they received their order.
 *
 * POST:  order_id (int)
 * Guard: order must belong to this customer AND be in 'out_for_delivery' status.
 *
 * On success:
 *   - order_status  → 'delivered'
 *   - delivered_at  → NOW()
 *   - updated_at    → NOW()
 *
 * Returns JSON: { success, message }
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

$user_id  = (int) $_SESSION['user_id'];
$order_id = (int) ($_POST['order_id'] ?? 0);

if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID.']);
    exit;
}

// ── Verify ownership + correct status in one query ────────────────────────────
$chk = $conn->prepare(
    "SELECT id FROM orders
     WHERE id = ? AND customer_id = ? AND order_status = 'out_for_delivery'
     LIMIT 1"
);
$chk->bind_param("ii", $order_id, $user_id);
$chk->execute();

if ($chk->get_result()->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Order not found or not eligible for confirmation.']);
    exit;
}

// ── Perform the status transition ─────────────────────────────────────────────
$upd = $conn->prepare(
    "UPDATE orders
     SET order_status = 'delivered',
         delivered_at = NOW(),
         updated_at   = NOW()
     WHERE id = ? AND customer_id = ? AND order_status = 'out_for_delivery'"
);
$upd->bind_param("ii", $order_id, $user_id);

if (!$upd->execute() || $upd->affected_rows === 0) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not update order status.']);
    exit;
}

// ── Notify the restaurant owner ───────────────────────────────────────────────
$owner_q = $conn->prepare(
    "SELECT hr.business_owner_id, o.order_number
     FROM orders o
     JOIN halal_restaurants hr ON o.restaurant_id = hr.id
     WHERE o.id = ? LIMIT 1"
);
$owner_q->bind_param("i", $order_id);
$owner_q->execute();
$owner_row = $owner_q->get_result()->fetch_assoc();

if ($owner_row) {
    createNotification(
        $conn,
        $owner_row['business_owner_id'],
        'Order Delivered: ' . $owner_row['order_number'],
        'Customer confirmed receipt of order ' . $owner_row['order_number'] . '.',
        'success',
        BASE_URL . 'dashboard/business_owner/manage_orders.php'
    );
}

echo json_encode(['success' => true, 'message' => 'Order marked as delivered. Thank you!']);
