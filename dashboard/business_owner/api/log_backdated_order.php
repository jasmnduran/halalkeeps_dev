<?php
/**
 * Log Backdated Order API
 *
 * Allows Business Owners and Operations Managers to record an order that was
 * made on a specific past (or present) date. The order is immediately set to
 * paid / delivered so it appears in the correct financial-report period.
 *
 * POST params:
 *   order_date       string  YYYY-MM-DD  – date the order actually occurred
 *   order_time       string  HH:MM       – time (defaults to 12:00)
 *   service_type     string  dine_in | takeout | delivery
 *   payment_method   string  cash | gcash | bank_transfer
 *   customer_name    string  optional walk-in name / reference
 *   notes            string  optional
 *   items            JSON    [{"id":N,"qty":N}, ...]  – approved menu item ids
 *
 * Returns JSON: { success, message, order_id?, order_number? }
 */
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../includes/MenuWorkflowService.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);

header('Content-Type: application/json; charset=utf-8');

function fail(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed.', 405);
}

$user_id = (int)$_SESSION['user_id'];

// ── Resolve restaurant ────────────────────────────────────────────────────────
$rest_id = getSessionRestaurantId($conn);
if (!$rest_id) {
    fail('No restaurant linked to your account.');
}

// ── Parse & validate input ────────────────────────────────────────────────────
$order_date_raw  = trim($_POST['order_date']  ?? '');
$order_time_raw  = trim($_POST['order_time']  ?? '12:00');
$service_type    = trim($_POST['service_type'] ?? 'dine_in');
$payment_method  = trim($_POST['payment_method'] ?? 'cash');
$customer_name   = trim(strip_tags($_POST['customer_name'] ?? ''));
$notes_input     = trim(strip_tags($_POST['notes'] ?? ''));
$items_raw_json  = trim($_POST['items'] ?? '[]');

// Date validation
if (!$order_date_raw || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $order_date_raw)) {
    fail('Invalid order date.');
}
$order_date_ts = strtotime($order_date_raw);
if (!$order_date_ts || $order_date_ts > strtotime('today 23:59:59')) {
    fail('Order date cannot be in the future.');
}

// Time validation
if (!preg_match('/^\d{2}:\d{2}$/', $order_time_raw)) {
    $order_time_raw = '12:00';
}
$order_datetime = $order_date_raw . ' ' . $order_time_raw . ':00';

// Validate enums
$valid_service  = ['dine_in', 'takeout', 'delivery'];
$valid_payments = ['cash', 'gcash', 'bank_transfer'];
if (!in_array($service_type,   $valid_service,  true)) $service_type  = 'dine_in';
if (!in_array($payment_method, $valid_payments, true)) $payment_method = 'cash';

// Parse items JSON
$items_input = json_decode($items_raw_json, true);
if (!is_array($items_input) || empty($items_input)) {
    fail('At least one menu item is required.');
}

// ── Validate each menu item against the approved menu ─────────────────────────
$line_items = [];
$subtotal   = 0.0;

foreach ($items_input as $row) {
    $mid = (int)($row['id']  ?? 0);
    $qty = max(1, (int)($row['qty'] ?? 1));
    if (!$mid) continue;

    $mi_stmt = $conn->prepare(
        "SELECT mi.id, mi.name, mi.price, mi.category FROM menu_items mi
         WHERE mi.id = ? AND mi.restaurant_id = ? AND mi.is_available = 1 AND " . MenuWorkflowService::customerEligibilitySql('mi')
    );
    if (!$mi_stmt) continue;
    $mi_stmt->bind_param('ii', $mid, $rest_id);
    $mi_stmt->execute();
    $mi = $mi_stmt->get_result()->fetch_assoc();
    $mi_stmt->close();
    if (!$mi) continue;  // skip items not in approved menu

    $unit_price    = round((float)$mi['price'], 2);
    $line_total    = round($unit_price * $qty, 2);
    $subtotal     += $line_total;
    $line_items[]  = [
        'id'       => $mi['id'],
        'name'     => $mi['name'],
        'category' => $mi['category'],
        'price'    => $unit_price,
        'qty'      => $qty,
        'total'    => $line_total,
    ];
}

if (empty($line_items)) {
    fail('None of the selected items are in the approved menu.');
}

$subtotal     = round($subtotal, 2);
$total_amount = $subtotal;  // no delivery fee for backdated manual entry
$items_json   = json_encode($line_items, JSON_UNESCAPED_UNICODE);

// ── Build notes ───────────────────────────────────────────────────────────────
$notes_parts = ['[Backdated entry]'];
if ($customer_name !== '') $notes_parts[] = 'Customer: ' . $customer_name;
if ($notes_input   !== '') $notes_parts[] = $notes_input;
$notes = implode(' | ', $notes_parts);

// ── Generate order number ─────────────────────────────────────────────────────
// Format: BK-YYYYMMDD-XXXX  (BK = backdated, date of the order, random hex)
$order_number = 'BK-' . date('Ymd', $order_date_ts) . '-' . strtoupper(bin2hex(random_bytes(2)));

// ── Ensure required columns exist (idempotent) ────────────────────────────────
db_add_column($conn, 'orders', 'ordered_at',         'DATETIME DEFAULT NULL');
db_add_column($conn, 'orders', 'order_source',       "ENUM('customer','pos','api') DEFAULT 'customer'");
db_add_column($conn, 'orders', 'order_placed_via',   "ENUM('customer_web','pos','api','mobile') DEFAULT 'customer_web'");
db_add_column($conn, 'orders', 'payment_verified_at','DATETIME DEFAULT NULL');
db_add_column($conn, 'orders', 'revenue_counted',    'TINYINT(1) DEFAULT 0');

// ── Insert order ──────────────────────────────────────────────────────────────
// payment_status = 'paid' and payment_verified_at = backdated datetime so it
// appears in financial reports for the correct historical period.
$ins = $conn->prepare("
    INSERT INTO orders
        (order_number, customer_id, restaurant_id, items_json,
         subtotal, delivery_fee, total_amount,
         payment_method, payment_status, order_status,
         service_type, notes,
         ordered_at, created_at,
         order_source, order_placed_via,
         payment_verified_at, revenue_counted)
    VALUES
        (?, ?, ?, ?,
         ?, 0, ?,
         ?, 'paid', 'delivered',
         ?, ?,
         ?, NOW(),
         'pos', 'pos',
         ?, 1)
");

if (!$ins) {
    fail('DB prepare error: ' . $conn->error, 500);
}

$ins->bind_param('siisddsssss',
    $order_number, $user_id, $rest_id, $items_json,
    $subtotal, $total_amount,
    $payment_method,
    $service_type, $notes,
    $order_datetime,
    $order_datetime      // payment_verified_at = order datetime so period filters work
);

if (!$ins->execute()) {
    fail('Failed to save order: ' . $ins->error, 500);
}
$order_id = (int)$conn->insert_id;
$ins->close();

// ── Activity log ─────────────────────────────────────────────────────────────
logActivity(
    $conn, $user_id,
    'log_backdated_order',
    "Backdated order #{$order_number} logged for {$order_date_raw}. "
        . count($line_items) . " item(s), total ₱" . number_format($total_amount, 2),
    'orders'
);

echo json_encode([
    'success'      => true,
    'message'      => "Order #{$order_number} logged successfully for {$order_date_raw}.",
    'order_id'     => $order_id,
    'order_number' => $order_number,
    'total'        => $total_amount,
    'item_count'   => count($line_items),
]);
