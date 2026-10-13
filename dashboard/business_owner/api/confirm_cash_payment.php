<?php
/**
 * Cashier API: Confirm Cash Payment Receipt
 * 
 * Endpoint for cashiers to confirm receipt of cash payment from customers.
 * Validates the cash amount received, calculates change, and marks order as paid.
 * 
 * POST params:
 *   order_id       - int    - Order ID
 *   cash_received  - float  - Actual cash amount received from customer
 * 
 * Returns JSON:
 *   { success, message, order_number?, change_amount?, payment_verified_at? }
 */

require_once __DIR__ . '/../../../config/app.php';

header('Content-Type: application/json; charset=utf-8');

// ── Authentication & Authorization ────────────────────────────────────────────
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

$user_id  = (int) $_SESSION['user_id'];
$role_id  = (int) ($_SESSION['role_id'] ?? 0);
// staff_role_key is set by app.php on every request for ROLE_BUSINESS_OWNER sessions.
// null  → actual restaurant owner
// 'cashier' / 'chef' / etc. → staff member sharing the ROLE_BUSINESS_OWNER role_id
$staff_key   = $_SESSION['staff_role_key'] ?? null;
$is_true_owner = ($role_id === ROLE_BUSINESS_OWNER && $staff_key === null);
$is_cashier_staff = ($role_id === ROLE_BUSINESS_OWNER && $staff_key === STAFF_ROLE_CASHIER);

// Resolve which restaurant this user belongs to
$cashier_restaurant_id = 0;
if ($is_cashier_staff || (!$is_true_owner && $role_id === ROLE_BUSINESS_OWNER)) {
    // Staff member — look up their assigned restaurant
    $sc = $conn->prepare("
        SELECT rs.restaurant_id
        FROM restaurant_staff rs
        JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
        WHERE rs.user_id = ? AND rs.is_active = 1 LIMIT 1
    ");
    if ($sc) {
        $sc->bind_param("i", $user_id);
        $sc->execute();
        $sc_row = $sc->get_result()->fetch_assoc();
        $sc->close();
        $cashier_restaurant_id = (int)($sc_row['restaurant_id'] ?? 0);
    }
}

// Only business owners and cashier staff are allowed
if (!$is_true_owner && !$is_cashier_staff) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Only cashiers and business owners can confirm cash payments.']);
    exit;
}

// ── Validate Input ────────────────────────────────────────────────────────────
$order_id      = (int)   ($_POST['order_id']      ?? 0);
$cash_received = max(0, floatval($_POST['cash_received'] ?? 0));

if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID.']);
    exit;
}

if ($cash_received <= 0) {
    echo json_encode(['success' => false, 'message' => 'Cash received amount must be greater than zero.']);
    exit;
}

// ── Fetch Order ───────────────────────────────────────────────────────────────
$order_stmt = $conn->prepare("
    SELECT 
        o.id,
        o.order_number,
        o.customer_id,
        o.restaurant_id,
        o.total_amount,
        o.payment_method,
        o.payment_status,
        o.payment_verified_at,
        o.order_status,
        hr.restaurant_name,
        hr.business_owner_id,
        u.full_name AS customer_name
    FROM orders o
    JOIN halal_restaurants hr ON o.restaurant_id = hr.id
    JOIN users u ON o.customer_id = u.id
    WHERE o.id = ?
    LIMIT 1
");
$order_stmt->bind_param("i", $order_id);
$order_stmt->execute();
$order = $order_stmt->get_result()->fetch_assoc();
$order_stmt->close();

if (!$order) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Order not found.']);
    exit;
}

// ── Authorization: verify user belongs to this restaurant ────────────────────
if ($is_true_owner) {
    // Actual owner must own this restaurant
    if ((int)$order['business_owner_id'] !== $user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied. You do not own this restaurant.']);
        exit;
    }
} else {
    // Cashier staff — must be assigned to this restaurant
    if ($cashier_restaurant_id === 0 || $cashier_restaurant_id !== (int)$order['restaurant_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied. You are not assigned to this restaurant.']);
        exit;
    }
}

// ── Validate Payment Method & Status ──────────────────────────────────────────
if ($order['payment_method'] !== 'cash') {
    echo json_encode([
        'success' => false,
        'message' => 'This order uses ' . htmlspecialchars($order['payment_method']) . ' payment method, not cash.'
    ]);
    exit;
}

// ── IDEMPOTENCY CHECK: Prevent duplicate cash confirmation ────────────────────
if ($order['payment_verified_at'] !== null) {
    // Payment already confirmed — return success with existing data
    $change = max(0, $cash_received - (float)$order['total_amount']);
    echo json_encode([
        'success' => true,
        'message' => 'Payment already confirmed.',
        'already_confirmed' => true,
        'order_number' => $order['order_number'],
        'payment_verified_at' => $order['payment_verified_at'],
        'change_amount' => number_format($change, 2)
    ]);
    exit;
}

if ($order['payment_status'] !== 'awaiting_cash') {
    echo json_encode([
        'success' => false,
        'message' => 'Order payment status is "' . htmlspecialchars($order['payment_status']) . '", expected "awaiting_cash".'
    ]);
    exit;
}

// ── Validate Cash Amount ──────────────────────────────────────────────────────
$amount_due = (float) $order['total_amount'];

if ($cash_received < $amount_due) {
    $shortage = $amount_due - $cash_received;
    echo json_encode([
        'success' => false,
        'message' => 'Insufficient cash received. Amount due: ₱' . number_format($amount_due, 2) . ', received: ₱' . number_format($cash_received, 2) . '. Shortage: ₱' . number_format($shortage, 2),
        'amount_due' => number_format($amount_due, 2),
        'cash_received' => number_format($cash_received, 2),
        'shortage' => number_format($shortage, 2)
    ]);
    exit;
}

// ── Calculate Change ──────────────────────────────────────────────────────────
$change_amount = $cash_received - $amount_due;

// ── Update Order: Mark as Paid ────────────────────────────────────────────────
$update_stmt = $conn->prepare("
    UPDATE orders
    SET 
        payment_status = 'paid',
        order_status = CASE 
            WHEN order_status = 'pending_payment' THEN 'confirmed'
            ELSE order_status
        END,
        cash_received = ?,
        change_amount = ?,
        payment_confirmed_by = ?,
        payment_verified_at = NOW()
    WHERE id = ?
      AND payment_verified_at IS NULL
");

if (!$update_stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit;
}

$update_stmt->bind_param("ddii", $cash_received, $change_amount, $user_id, $order_id);

if (!$update_stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to confirm payment: ' . $update_stmt->error]);
    $update_stmt->close();
    exit;
}

$affected_rows = $update_stmt->affected_rows;
$update_stmt->close();

// Double-check idempotency: if affected_rows is 0, another cashier may have confirmed it simultaneously
if ($affected_rows === 0) {
    // Re-fetch order to get the confirmed data
    $refetch_stmt = $conn->prepare("SELECT payment_verified_at, cash_received, change_amount FROM orders WHERE id = ? LIMIT 1");
    $refetch_stmt->bind_param("i", $order_id);
    $refetch_stmt->execute();
    $refetched = $refetch_stmt->get_result()->fetch_assoc();
    $refetch_stmt->close();
    
    if ($refetched && $refetched['payment_verified_at'] !== null) {
        echo json_encode([
            'success' => true,
            'message' => 'Payment already confirmed by another cashier.',
            'already_confirmed' => true,
            'order_number' => $order['order_number'],
            'payment_verified_at' => $refetched['payment_verified_at']
        ]);
        exit;
    }
    
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to confirm payment. Please try again.']);
    exit;
}

// ── Log Status History ────────────────────────────────────────────────────────
$history_stmt = $conn->prepare("
    INSERT INTO order_status_history 
        (order_id, old_status, new_status, changed_by, notes, changed_at)
    VALUES (?, ?, 'confirmed', ?, ?, NOW())
");
if ($history_stmt) {
    $old_status = $order['order_status'];
    $notes = 'Cash payment confirmed. Received: ₱' . number_format($cash_received, 2) . ', Change: ₱' . number_format($change_amount, 2);
    $history_stmt->bind_param("isis", $order_id, $old_status, $user_id, $notes);
    $history_stmt->execute();
    $history_stmt->close();
}

// ── Notify Customer ───────────────────────────────────────────────────────────
createNotification(
    $conn,
    (int) $order['customer_id'],
    '✅ Payment Confirmed: ' . $order['order_number'],
    'Your cash payment of ₱' . number_format($amount_due, 2) . ' has been confirmed. Your order is now being prepared!',
    'success',
    BASE_URL . 'dashboard/customer/orders.php'
);

// ── Notify Kitchen Staff (if order just transitioned to confirmed) ────────────
if ($order['order_status'] === 'pending_payment') {
    // Notify chef/kitchen staff
    $chef_notify_res = $conn->query("
        SELECT rs.user_id
        FROM restaurant_staff rs
        JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
        WHERE rs.restaurant_id = {$order['restaurant_id']}
          AND rsr.role_key IN ('chef', 'ops_manager')
          AND rs.is_active = 1
    ");
    
    if ($chef_notify_res) {
        while ($chef = $chef_notify_res->fetch_assoc()) {
            createNotification(
                $conn,
                (int) $chef['user_id'],
                '🍳 New Order Ready: ' . $order['order_number'],
                'Payment confirmed. Order ready for preparation.',
                'action_required',
                BASE_URL . 'dashboard/business_owner/chef_dashboard.php'
            );
        }
        $chef_notify_res->free();
    }
}

// ── Success Response ──────────────────────────────────────────────────────────
echo json_encode([
    'success' => true,
    'message' => 'Payment confirmed successfully!',
    'order_number' => $order['order_number'],
    'order_id' => $order_id,
    'amount_due' => number_format($amount_due, 2),
    'cash_received' => number_format($cash_received, 2),
    'change_amount' => number_format($change_amount, 2),
    'payment_verified_at' => date('Y-m-d H:i:s'),
    'customer_name' => $order['customer_name']
]);
