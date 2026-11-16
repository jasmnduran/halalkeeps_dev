<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/RevenuePaymentService.php';

function expectRevenue(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

expectRevenue(RevenuePaymentService::moneyMinor(150.00) === 15000, 'PHP to centavo conversion must be exact.');
expectRevenue(RevenuePaymentService::moneyMinor(199.995) === 20000, 'Money conversion must round to centavos.');
expectRevenue(RevenuePaymentService::moneyFromMinor(114) === 1.14, 'Centavo conversion must round-trip.');
expectRevenue(RevenuePaymentService::boundedPlatformFeeRate(false, 0.0114) === 0.0, 'Disabled fee policy must never charge a fee.');
expectRevenue(RevenuePaymentService::boundedPlatformFeeRate(true, 0.0114) === 0.0114, 'Lower approved fee boundary must be allowed.');
expectRevenue(RevenuePaymentService::boundedPlatformFeeRate(true, 0.0120) === 0.0120, 'Upper approved fee boundary must be allowed.');
expectRevenue(RevenuePaymentService::boundedPlatformFeeRate(true, 0.0113) === 0.0, 'Fee below policy range must be rejected.');
expectRevenue(RevenuePaymentService::boundedPlatformFeeRate(true, 0.0121) === 0.0, 'Fee above policy range must be rejected.');

$webhookRaw = '{"data":{"id":"evt_test","attributes":{"type":"checkout_session.payment.paid"}}}';
$webhookSecret = 'unit-test-webhook-secret';
$webhookTime = 1700000000;
$testSignature = hash_hmac('sha256', $webhookTime . '.' . $webhookRaw, $webhookSecret);
expectRevenue(RevenuePaymentService::validWebhookSignature($webhookRaw, 't=' . $webhookTime . ',te=' . $testSignature, $webhookSecret, false, $webhookTime), 'A matching test webhook signature must be accepted.');
expectRevenue(RevenuePaymentService::validWebhookSignature($webhookRaw, 't=' . $webhookTime . ',li=' . $testSignature, $webhookSecret, true, $webhookTime), 'A matching live webhook signature must be accepted.');
expectRevenue(!RevenuePaymentService::validWebhookSignature($webhookRaw, 't=' . $webhookTime . ',li=' . $testSignature, $webhookSecret, false, $webhookTime), 'A live signature must not be accepted for a test webhook.');
expectRevenue(!RevenuePaymentService::validWebhookSignature($webhookRaw, 't=' . $webhookTime . ',te=invalid', $webhookSecret, false, $webhookTime), 'An invalid signature must be rejected.');
expectRevenue(!RevenuePaymentService::validWebhookSignature($webhookRaw, 't=' . ($webhookTime - 301) . ',te=' . $testSignature, $webhookSecret, false, $webhookTime), 'A stale webhook signature must be rejected.');

echo "revenue_payment_unit_test: OK\n";