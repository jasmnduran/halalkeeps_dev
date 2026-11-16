<?php
declare(strict_types=1);
/**
 * PayMongo webhook receiver.
 * No payment is fulfilled from a browser redirect. This endpoint verifies the
 * timestamped Paymongo-Signature header against the raw payload, de-duplicates
 * provider event IDs, validates the expected amount/currency, and then
 * provisions the associated order or paid entitlement.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/RevenuePaymentService.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Method not allowed.']);
    exit;
}
$raw=file_get_contents('php://input');
$signature=(string)($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '');
$result=(new RevenuePaymentService($conn))->processWebhook($raw,$signature);
http_response_code((int)($result['http_status'] ?? ($result['success'] ? 200 : 400)));
echo json_encode($result);