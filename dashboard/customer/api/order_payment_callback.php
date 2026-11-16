<?php
declare(strict_types=1);
/**
 * Customer PayMongo return handler.
 *
 * A browser redirect is never proof of payment. It only asks the server to
 * reconcile the named checkout with PayMongo. The reconciliation code checks
 * the provider session, metadata, amount, and currency before it creates an
 * order; an unverified checkout remains an intent, not an order.
 */
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../includes/RevenuePaymentService.php';
requireLogin();
requireRole([ROLE_CUSTOMER]);

$userId=(int)$_SESSION['user_id'];
$reference=trim((string)($_GET['request'] ?? ''));
$status=(string)($_GET['payment'] ?? $_GET['status'] ?? '');

if ($reference==='') {
    $_SESSION['flash_message']='Payment return was missing its checkout reference. No order was created.';
    $_SESSION['flash_type']='warning';
} else {
    $payments=new RevenuePaymentService($conn);
    $result=$payments->reconcileCustomerOrderPayment($userId,$reference);
    if (($result['success']??false) && ($result['ready']??false) && (int)($result['order_id']??0)>0) {
        $stmt=$conn->prepare('SELECT order_number FROM orders WHERE id=? AND customer_id=? AND payment_status=\'paid\' AND payment_verified_at IS NOT NULL LIMIT 1');
        $orderId=(int)$result['order_id']; $stmt->bind_param('ii',$orderId,$userId); $stmt->execute(); $order=$stmt->get_result()->fetch_assoc(); $stmt->close();
        $_SESSION['flash_message']=$order ? 'Payment verified. Order '.$order['order_number'].' has been created and sent to the restaurant.' : 'Payment verified. Your order is now available.';
        $_SESSION['flash_type']='success';
    } elseif (($result['success']??false)) {
        $_SESSION['flash_message']=$status==='cancelled' ? 'Payment was cancelled or is still unverified. No order was created.' : 'Payment has not been verified by PayMongo yet. No order was created.';
        $_SESSION['flash_type']='info';
    } else {
        $_SESSION['flash_message']='We could not verify this payment yet. No order was created. '.(string)($result['message']??'Please refresh shortly.');
        $_SESSION['flash_type']='warning';
    }
}

header('Location: '.BASE_URL.'dashboard/customer/orders.php');
exit;