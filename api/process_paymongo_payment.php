<?php
/**
 * PayMongo Payment Intent Creation API
 * 
 * Creates a PayMongo payment intent for GCash or Bank Transfer (PayMaya)
 * and returns the checkout URL for customer redirection.
 * 
 * POST Parameters:
 *   order_id       int
 *   amount         float (in PHP pesos, will be converted to centavos)
 *   payment_method string (gcash | bank_transfer)
 * 
 * Response:
 * {
 *   "success": true,
 *   "payment_intent_id": "pi_xxx",
 *   "checkout_url": "https://pm.link/xxx",
 *   "client_key": "pi_xxx_client_xxx"
 * }
 */
require_once __DIR__ . '/../config/app.php';

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

if ((int)($_SESSION['role_id'] ?? 0) !== ROLE_CUSTOMER) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

// ── Load PayMongo credentials ──────────────────────────────────────────────────
$paymongo_secret_key = getenv('PAYMONGO_SECRET_KEY') ?: PAYMONGO_SECRET_KEY ?? '';
$paymongo_public_key = getenv('PAYMONGO_PUBLIC_KEY') ?: PAYMONGO_PUBLIC_KEY ?? '';

if (empty($paymongo_secret_key)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'PayMongo not configured. Please contact support.']);
    exit;
}

$user_id       = (int)$_SESSION['user_id'];
$order_id      = (int)($_POST['order_id'] ?? 0);
$amount_php    = max(0, floatval($_POST['amount'] ?? 0));
$payment_method_raw = sanitize($_POST['payment_method'] ?? 'gcash');

// Validate inputs
if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID.']);
    exit;
}

if ($amount_php <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid amount.']);
    exit;
}

// Map to PayMongo payment method types
$pm_method_map = [
    'gcash'         => 'gcash',
    'bank_transfer' => 'paymaya',  // PayMongo uses 'paymaya' for bank transfer
];

$pm_method = $pm_method_map[$payment_method_raw] ?? null;

if (!$pm_method) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment method. Use gcash or bank_transfer.']);
    exit;
}

// ── Verify order belongs to this user ─────────────────────────────────────────
$order_stmt = $conn->prepare("
    SELECT id, order_number, total_amount, restaurant_id, payment_status
    FROM orders
    WHERE id = ? AND customer_id = ?
    LIMIT 1
");
$order_stmt->bind_param("ii", $order_id, $user_id);
$order_stmt->execute();
$order = $order_stmt->get_result()->fetch_assoc();
$order_stmt->close();

if (!$order) {
    echo json_encode(['success' => false, 'message' => 'Order not found or access denied.']);
    exit;
}

if ($order['payment_status'] === 'paid') {
    echo json_encode(['success' => false, 'message' => 'Order already paid.']);
    exit;
}

// ── Convert amount to centavos (PayMongo uses smallest currency unit) ─────────
$amount_centavos = (int)round($amount_php * 100);

// ── Build PayMongo Payment Intent payload ──────────────────────────────────────
$success_url = BASE_URL . 'dashboard/customer/order_confirmation.php?order_id=' . $order_id . '&payment=success';
$cancel_url  = BASE_URL . 'dashboard/customer/restaurant_view.php?id=' . $order['restaurant_id'] . '&payment=cancelled';

$payload = [
    'data' => [
        'attributes' => [
            'amount'   => $amount_centavos,
            'currency' => 'PHP',
            'description' => 'Order #' . $order['order_number'] . ' - Halal Restaurant System',
            'statement_descriptor' => 'HALAL ORDER',
            'payment_method_allowed' => [$pm_method],
            'metadata' => [
                'order_id'      => (string)$order_id,
                'order_number'  => $order['order_number'],
                'customer_id'   => (string)$user_id,
                'restaurant_id' => (string)$order['restaurant_id']
            ]
        ]
    ]
];

// ── Call PayMongo API ──────────────────────────────────────────────────────────
$ch = curl_init('https://api.paymongo.com/v1/payment_intents');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($paymongo_secret_key . ':')
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 30
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    error_log("PayMongo API Error: $curl_error");
    echo json_encode(['success' => false, 'message' => 'Payment gateway connection failed.']);
    exit;
}

$result = json_decode($response, true);

if ($http_code !== 200 && $http_code !== 201) {
    $error_msg = $result['errors'][0]['detail'] ?? 'Payment processing failed.';
    error_log("PayMongo API HTTP $http_code: " . $response);
    echo json_encode(['success' => false, 'message' => $error_msg]);
    exit;
}

$payment_intent = $result['data'] ?? null;

if (!$payment_intent || !isset($payment_intent['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid response from payment gateway.']);
    exit;
}

$payment_intent_id = $payment_intent['id'];
$client_key        = $payment_intent['attributes']['client_key'] ?? '';
$payment_status    = $payment_intent['attributes']['status'] ?? 'awaiting_payment_method';

// ── Create PayMongo checkout session ───────────────────────────────────────────
$checkout_payload = [
    'data' => [
        'attributes' => [
            'cancel_url'  => $cancel_url,
            'success_url' => $success_url,
            'line_items'  => [
                [
                    'amount'      => $amount_centavos,
                    'currency'    => 'PHP',
                    'name'        => 'Order #' . $order['order_number'],
                    'quantity'    => 1
                ]
            ],
            'payment_method_types' => [$pm_method],
            'reference_number'     => $order['order_number'],
            'description'          => 'Halal Restaurant Order Payment'
        ]
    ]
];

$ch2 = curl_init('https://api.paymongo.com/v1/checkout_sessions');
curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($paymongo_secret_key . ':')
    ],
    CURLOPT_POSTFIELDS     => json_encode($checkout_payload),
    CURLOPT_TIMEOUT        => 30
]);

$checkout_response = curl_exec($ch2);
$checkout_http_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

$checkout_result = json_decode($checkout_response, true);

if ($checkout_http_code !== 200 && $checkout_http_code !== 201) {
    error_log("PayMongo Checkout API HTTP $checkout_http_code: " . $checkout_response);
    echo json_encode(['success' => false, 'message' => 'Checkout session creation failed.']);
    exit;
}

$checkout_session = $checkout_result['data'] ?? null;
$checkout_url     = $checkout_session['attributes']['checkout_url'] ?? '';

if (empty($checkout_url)) {
    echo json_encode(['success' => false, 'message' => 'No checkout URL received.']);
    exit;
}

// ── Update order with PayMongo details ─────────────────────────────────────────
$update_stmt = $conn->prepare("
    UPDATE orders
    SET paymongo_payment_intent_id = ?,
        paymongo_checkout_url      = ?,
        paymongo_raw_response      = ?,
        paymongo_payment_status    = ?,
        payment_status             = 'pending'
    WHERE id = ?
");

$raw_json = json_encode([
    'payment_intent' => $payment_intent,
    'checkout_session' => $checkout_session
]);

$update_stmt->bind_param("ssssi",
    $payment_intent_id,
    $checkout_url,
    $raw_json,
    $payment_status,
    $order_id
);

$update_stmt->execute();
$update_stmt->close();

// ── Log activity ───────────────────────────────────────────────────────────────
if (function_exists('logActivity')) {
    logActivity($conn, $user_id, 'PayMongo Payment Initiated',
        "Order #{$order['order_number']} - Payment Intent: {$payment_intent_id}", 'payment');
}

// ── Return success with checkout URL ───────────────────────────────────────────
echo json_encode([
    'success'           => true,
    'payment_intent_id' => $payment_intent_id,
    'checkout_url'      => $checkout_url,
    'client_key'        => $client_key,
    'payment_method'    => $pm_method,
    'message'           => 'Redirecting to payment gateway...'
]);
