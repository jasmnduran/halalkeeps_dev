<?php
/**
 * PayMongo callback handler for POS (walk-in) orders.
 *
 * PayMongo redirects here after the customer completes or cancels
 * a GCash / Bank Transfer checkout initiated at the POS terminal.
 *
 * Query params:
 *   order_id  int
 *   status    success | cancel
 *
 * On success: verify checkout session, mark order confirmed + payment paid,
 *             then redirect the cashier back to the POS with a success flash.
 * On cancel:  delete the pending order so the cashier can re-take it,
 *             redirect back with an error flash.
 */
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../includes/IngredientInventoryService.php';

requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);

$order_id = (int)($_GET['order_id'] ?? 0);
$status   = $_GET['status'] ?? 'cancel';

$pos_url = BASE_URL . 'dashboard/business_owner/pos_order_processing.php';

if ($order_id <= 0) {
    $_SESSION['pos_error'] = 'Invalid payment callback — no order ID.';
    header('Location: ' . $pos_url);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Load the order — must belong to this restaurant
$rest_id = getSessionRestaurantId($conn);
if (!$rest_id) {
    $_SESSION['pos_error'] = 'Restaurant not found.';
    header('Location: ' . $pos_url);
    exit;
}

$ord_q = $conn->prepare(
    "SELECT id, order_number, total_amount, payment_method, payment_status
     FROM orders
     WHERE id = ? AND restaurant_id = ? LIMIT 1"
);
$ord_q->bind_param("ii", $order_id, $rest_id);
$ord_q->execute();
$order = $ord_q->get_result()->fetch_assoc();
$ord_q->close();

if (!$order) {
    $_SESSION['pos_error'] = 'Order not found.';
    header('Location: ' . $pos_url);
    exit;
}

// ── Cancel path ───────────────────────────────────────────────────────────────
if ($status === 'cancel') {
    if (IngredientInventoryService::isFeatureEnabled()) {
        try { (new IngredientInventoryService($conn))->releaseOrderReservations($order_id, $user_id); }
        catch (Throwable $e) { error_log('Could not release POS ingredient reservation: ' . $e->getMessage()); }
    }
    // Delete the pending order so the cashier can start fresh
    $del = $conn->prepare("DELETE FROM orders WHERE id = ? AND restaurant_id = ? AND payment_status = 'pending'");
    $del->bind_param("ii", $order_id, $rest_id);
    $del->execute();
    $del->close();

    // Also clean up the payments row if it exists
    $del_pay = $conn->prepare(
        "DELETE FROM payments WHERE reference_type = 'pos_order' AND reference_id = ? AND status = 'pending'"
    );
    $del_pay->bind_param("i", $order_id);
    $del_pay->execute();
    $del_pay->close();

    $_SESSION['pos_error'] = 'Payment cancelled. The order has been cleared — please re-take the order.';
    header('Location: ' . $pos_url);
    exit;
}

// ── Success path: verify with PayMongo ───────────────────────────────────────
$pay_q = $conn->prepare(
    "SELECT * FROM payments
     WHERE reference_type = 'pos_order' AND reference_id = ?
     ORDER BY created_at DESC LIMIT 1"
);
$pay_q->bind_param("i", $order_id);
$pay_q->execute();
$payment = $pay_q->get_result()->fetch_assoc();
$pay_q->close();

$verified = false;

if ($payment && !empty($payment['transaction_reference'])) {
    $checkout_result = retrievePayMongoCheckoutSession($payment['transaction_reference']);

    if ($checkout_result['success'] && isPayMongoCheckoutPaid($checkout_result['data'])) {
        $verified = true;

        // Mark payment verified
        $upd_pay = $conn->prepare("UPDATE payments SET status = 'verified', paid_at = NOW() WHERE id = ?");
        $upd_pay->bind_param("i", $payment['id']);
        $upd_pay->execute();
        $upd_pay->close();

        // Confirm the order
        $upd_ord = $conn->prepare(
            "UPDATE orders SET payment_status = 'paid', order_status = 'confirmed' WHERE id = ?"
        );
        $upd_ord->bind_param("i", $order_id);
        $upd_ord->execute();
        $upd_ord->close();
    }
}

if ($verified) {
    $_SESSION['pos_success'] = 'Payment confirmed via ' . strtoupper($order['payment_method'])
        . ' — Order ' . $order['order_number'] . ' (' . formatCurrency($order['total_amount']) . ') is now confirmed.';
} else {
    // PayMongo redirected here but payment not yet marked paid (rare timing edge case)
    $_SESSION['pos_error'] = 'Payment is still being verified for order ' . $order['order_number']
        . '. Check the order in Manage Orders once confirmed.';
}

header('Location: ' . $pos_url);
exit;
