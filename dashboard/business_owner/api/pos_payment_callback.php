<?php
declare(strict_types=1);
/**
 * Informational POS PayMongo return handler.
 * Orders are confirmed only by the signed PayMongo webhook, never by redirect.
 */
require_once __DIR__ . '/../../../config/app.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);

$restId=getSessionRestaurantId($conn);
$reference=trim((string)($_GET['request'] ?? ''));
$status=(string)($_GET['payment'] ?? $_GET['status'] ?? '');
$orderNumber='';
if ($restId && $reference !== '') {
    $stmt=$conn->prepare("SELECT o.order_number FROM billing_payment_requests b JOIN orders o ON o.id=b.order_id WHERE b.internal_reference=? AND b.restaurant_id=? AND b.service_type='order' LIMIT 1");
    $stmt->bind_param('si',$reference,$restId); $stmt->execute();
    $orderNumber=(string)($stmt->get_result()->fetch_assoc()['order_number'] ?? ''); $stmt->close();
}
if ($status === 'cancelled') {
    $_SESSION['pos_error']='Payment was cancelled. The POS order remains pending unless it is cancelled separately.';
} else {
    $_SESSION['pos_success']=$orderNumber ? 'Payment return received for '.$orderNumber.'. The POS will update after PayMongo webhook verification.' : 'Payment return received. The POS will update after PayMongo webhook verification.';
}
header('Location: '.BASE_URL.'dashboard/business_owner/pos_order_processing.php');
exit;