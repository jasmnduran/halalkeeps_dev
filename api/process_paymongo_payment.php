<?php
declare(strict_types=1);
/**
 * Backward-compatible order checkout endpoint.
 * The legacy amount/payment-method POST values are deliberately ignored.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/RevenuePaymentService.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed.']); exit; }
if (!isLoggedIn() || (int)($_SESSION['role_id'] ?? 0) !== ROLE_CUSTOMER) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Access denied.']); exit; }
$orderId=(int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid order ID.']); exit; }

$result=(new RevenuePaymentService($conn))->startOrderCheckout((int)$_SESSION['user_id'],$orderId);
http_response_code((int)($result['http_status'] ?? ($result['success'] ? 200 : 422)));
echo json_encode([
    'success'=>$result['success'],
    'message'=>$result['message'] ?? ($result['success'] ? 'Redirecting to secure payment.' : 'Could not create checkout.'),
    'checkout_url'=>$result['checkout_url'] ?? null,
    'checkout_session_id'=>$result['checkout_session_id'] ?? null,
]);