<?php
/**
 * AJAX endpoint: place a customer order.
 *
 * POST fields:
 *   restaurant_id  int
 *   items_json     JSON string  e.g. [{"id":1,"name":"Chicken","price":120,"qty":2},...]
 *   total_amount   float
 *   delivery_address string
 *   delivery_lat   float  (optional)
 *   delivery_lng   float  (optional)
 *   payment_method string  cash | gcash | bank_transfer
 *   notes          string  (optional)
 *
 * Returns JSON:
 *   { success, message, order_number?, redirect_url? }
 */
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../includes/IngredientInventoryService.php';

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

$user_id = (int) $_SESSION['user_id'];
$ingredient_inventory = new IngredientInventoryService($conn);

// ── Validate inputs ───────────────────────────────────────────────────────────
$restaurant_id    = (int)   ($_POST['restaurant_id']    ?? 0);
$raw_items        =          $_POST['items_json']        ?? '[]';
$subtotal         = max(0, floatval($_POST['subtotal']        ?? $_POST['total_amount'] ?? 0));
$delivery_fee_in  = max(0, floatval($_POST['delivery_fee']    ?? 0));
$total_amount     = max(0, floatval($_POST['total_amount']    ?? 0));
$delivery_address = trim(sanitize($_POST['delivery_address'] ?? ''));
$delivery_lat     = floatval($_POST['delivery_lat']           ?? 0);
$delivery_lng     = floatval($_POST['delivery_lng']           ?? 0);
$payment_method   = sanitize($_POST['payment_method']         ?? 'cash');
$notes            = sanitize($_POST['notes']                  ?? '');
$service_type_raw = sanitize($_POST['service_type']           ?? 'delivery');
$table_number     = trim(sanitize($_POST['table_number']      ?? ''));

// Validate and normalise service type
$allowed_service_types = ['dine_in', 'takeout', 'delivery'];
if (!in_array($service_type_raw, $allowed_service_types, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid service type selected.']);
    exit;
}
$service_type = $service_type_raw;

// Dine-in orders should have table number (optional but recommended)
if ($service_type === 'dine_in' && empty($table_number)) {
    // Allow empty table_number but log it
    $table_number = 'Unassigned';
}

// Server-side enforce: delivery fee is only ₱50 for delivery, ₱0 otherwise
$delivery_fee = ($service_type === 'delivery') ? 50.00 : 0.00;

// Recompute grand total server-side to prevent client-side manipulation
$total_amount = $subtotal + $delivery_fee;

// Structured address components (used to build the canonical string if present)
$addr_street   = trim(sanitize($_POST['addr_street']   ?? ''));
$addr_barangay = trim(sanitize($_POST['addr_barangay'] ?? ''));
$addr_town     = trim(sanitize($_POST['addr_town']     ?? ''));
$addr_city     = trim(sanitize($_POST['addr_city']     ?? ''));
$addr_province = trim(sanitize($_POST['addr_province'] ?? ''));

// If structured fields were submitted, build a clean canonical address string
if ($addr_street !== '') {
    $parts = array_filter([$addr_street, $addr_barangay, $addr_town, $addr_city, $addr_province]);
    $delivery_address = implode(', ', $parts);
}

$allowed_methods = ['cash', 'gcash', 'bank_transfer', 'paymongo'];
if (!in_array($payment_method, $allowed_methods, true)) {
    $payment_method = 'cash';
}

// Delivery orders must use online payment — cash not accepted
if ($service_type === 'delivery' && $payment_method === 'cash') {
    echo json_encode(['success' => false, 'message' => 'Delivery orders require GCash or Bank Transfer payment.']);
    exit;
}

// Determine initial payment_status based on payment method
// Cash payments start as 'awaiting_cash' — cashier must confirm receipt
// Online payments (gcash, bank_transfer, paymongo) start as 'pending' until webhook confirms
$initial_payment_status = ($payment_method === 'cash') ? 'awaiting_cash' : 'pending';
$initial_order_status   = ($payment_method === 'cash') ? 'pending_payment' : 'pending';

if ($restaurant_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid restaurant.']);
    exit;
}

// Decode and validate items
$items = json_decode($raw_items, true);
if (!is_array($items) || count($items) === 0) {
    echo json_encode(['success' => false, 'message' => 'Your cart is empty.']);
    exit;
}

// Sanitize each cart item
$safe_items = [];
foreach ($items as $item) {
    $safe_items[] = [
        'id'    => (int)    ($item['id']    ?? 0),
        'name'  => htmlspecialchars(strip_tags($item['name']  ?? ''), ENT_QUOTES, 'UTF-8'),
        'price' => max(0, floatval($item['price'] ?? 0)),
        'qty'   => max(1, intval($item['qty'] ?? 1)),
    ];
}
$items_json_clean = json_encode($safe_items);

// Address required only for delivery
if ($service_type === 'delivery' && empty($delivery_address)) {
    echo json_encode(['success' => false, 'message' => 'Delivery address is required for delivery orders.']);
    exit;
}

if ($subtotal <= 0) {
    echo json_encode(['success' => false, 'message' => 'Order subtotal must be greater than zero.']);
    exit;
}

// ── Verify restaurant exists and is active ────────────────────────────────────
$rest_stmt = $conn->prepare("SELECT id, restaurant_name, business_owner_id FROM halal_restaurants WHERE id = ? AND is_active = 1 LIMIT 1");
$rest_stmt->bind_param("i", $restaurant_id);
$rest_stmt->execute();
$restaurant = $rest_stmt->get_result()->fetch_assoc();

if (!$restaurant) {
    echo json_encode(['success' => false, 'message' => 'Restaurant not found or inactive.']);
    exit;
}

// ── Server-side item approval guard ──────────────────────────────────────────
// Re-verify each ordered item is approved and available before accepting the order
$item_ids = array_filter(array_map(fn($i) => (int)($i['id'] ?? 0), $safe_items));
if (!empty($item_ids)) {
    $ids_str = implode(',', $item_ids);
    $approved_ids_res = $conn->query("
        SELECT id FROM menu_items
        WHERE id IN ($ids_str)
          AND restaurant_id = {$restaurant_id}
          AND is_available = 1
          AND approval_status = 'approved'
    ");
    $approved_ids = $approved_ids_res
        ? array_column($approved_ids_res->fetch_all(MYSQLI_ASSOC), 'id')
        : [];
    foreach ($safe_items as $si) {
        if ($si['id'] > 0 && !in_array($si['id'], $approved_ids, false)) {
            echo json_encode(['success' => false, 'message' => 'One or more items in your cart are no longer available. Please refresh and try again.']);
            exit;
        }
    }
}

// ── MANDATORY TPB SURVEY VERIFICATION ─────────────────────────────────────────
// Customer MUST complete survey before placing any order
// Check survey_responses table for most recent valid survey
$survey_stmt = $conn->prepare("
    SELECT id, submitted_at AS created_at, mean_att, mean_sn, mean_pbc, normalized_pi
    FROM tpb_survey_responses
    WHERE user_id = ?
    ORDER BY submitted_at DESC
    LIMIT 1
");
$survey_stmt->bind_param("i", $user_id);
$survey_stmt->execute();
$survey = $survey_stmt->get_result()->fetch_assoc();
$survey_stmt->close();

if (!$survey) {
    echo json_encode([
        'success' => false,
        'message' => 'Survey required: Please complete the Customer Demand Survey before placing an order.',
        'redirect_to_survey' => true,
        'survey_url' => BASE_URL . 'dashboard/customer/tpb_survey.php?return_url=' . urlencode($_SERVER['REQUEST_URI'] ?? '')
    ]);
    exit;
}

$survey_response_id = (int)$survey['id'];

// ── Ensure payments table has the PayMongo columns (idempotent) ──────────────
db_add_column($conn, 'payments', 'paymongo_checkout_url', 'VARCHAR(500) DEFAULT NULL');
db_add_column($conn, 'payments', 'paymongo_raw_response', 'MEDIUMTEXT   DEFAULT NULL');

// ── Insert order ──────────────────────────────────────────────────────────────
$order_number = generateReference('ORD');

// Apply idempotent migrations
db_add_column($conn, 'orders', 'table_number', 'VARCHAR(20) DEFAULT NULL');
db_add_column($conn, 'orders', 'survey_response_id', 'INT UNSIGNED DEFAULT NULL');
db_add_column($conn, 'orders', 'survey_verified', 'TINYINT(1) DEFAULT 0');
db_add_column($conn, 'orders', 'ordered_at', 'DATETIME DEFAULT NULL');
db_add_column($conn, 'orders', 'order_source', "ENUM('customer','pos','api') DEFAULT 'customer'");
db_add_column($conn, 'orders', 'order_placed_via', "ENUM('customer_web','pos','api','mobile') DEFAULT 'customer_web'");

// Columns with ? markers: order_number(s) customer_id(i) restaurant_id(i) items_json(s)
// subtotal(d) delivery_fee(d) total_amount(d) delivery_address(s) service_type(s)
// payment_method(s) payment_status(s) order_status(s) notes(s) table_number(s) survey_response_id(i)
// = 15 placeholders, type string = "siisdddsssssssi"
$ins = $conn->prepare(
    "INSERT INTO orders
        (order_number, customer_id, restaurant_id, items_json, subtotal, delivery_fee, total_amount,
         delivery_address, service_type, payment_method, payment_status, order_status, notes,
         table_number, survey_response_id,
         survey_verified, ordered_at, order_source, order_placed_via)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
             ?, ?,
             1, NOW(), 'customer', 'customer_web')"
);
if (!$ins) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB prepare error: ' . $conn->error]);
    exit;
}
$ins->bind_param("siisdddsssssssi",
    $order_number, $user_id, $restaurant_id,
    $items_json_clean, $subtotal, $delivery_fee, $total_amount,
    $delivery_address, $service_type, $payment_method,
    $initial_payment_status, $initial_order_status,
    $notes, $table_number, $survey_response_id
);

if (!$ins->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to create order: ' . $ins->error]);
    exit;
}
$order_id = $conn->insert_id;

// Feature-flagged inventory reservation. This is deliberately separate from
// payment state: a payment failure/cancellation releases the reservation below.
if (IngredientInventoryService::isFeatureEnabled()) {
    $reservation = $ingredient_inventory->reserveForOrder((int)$order_id, $restaurant_id, $safe_items, $user_id);
    if (!$reservation['success']) {
        $del = $conn->prepare('DELETE FROM orders WHERE id = ? AND customer_id = ?');
        $del->bind_param('ii', $order_id, $user_id); $del->execute(); $del->close();
        echo json_encode(['success' => false, 'message' => $reservation['message']]);
        exit;
    }
}

// ── Link survey to order for analytics ────────────────────────────────────────
db_add_column($conn, 'survey_order_tracking', 'id', 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');
$track_stmt = $conn->prepare("
    INSERT IGNORE INTO survey_order_tracking
        (survey_response_id, order_id, user_id, restaurant_id, order_total, linked_at)
    VALUES (?, ?, ?, ?, ?, NOW())
");
if ($track_stmt) {
    // survey_response_id(i) order_id(i) user_id(i) restaurant_id(i) order_total(d) = "iiiid"
    $track_stmt->bind_param("iiiid", $survey_response_id, $order_id, $user_id, $restaurant_id, $total_amount);
    $track_stmt->execute();
    $track_stmt->close();
}

// ── Notify restaurant owner + assigned cashiers ───────────────────────────────
createNotification(
    $conn,
    $restaurant['business_owner_id'],
    'New Order: ' . $order_number,
    'You have a new order from ' . ($_SESSION['full_name'] ?? 'a customer') . '!',
    'action_required',
    BASE_URL . 'dashboard/business_owner/manage_orders.php'
);

// Also notify any active cashier(s) assigned to this restaurant
$cashier_role_key = 'cashier';
$cn = $conn->query("
    SELECT rs.user_id
    FROM restaurant_staff rs
    JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
    WHERE rs.restaurant_id = {$restaurant_id}
      AND rsr.role_key = 'cashier'
      AND rs.is_active = 1
");
if ($cn) {
    $type_label = match($service_type) {
        'dine_in' => '🍽️ Dine-in',
        'takeout' => '🛍️ Takeout',
        default   => '🛵 Delivery',
    };
    while ($cr = $cn->fetch_assoc()) {
        if ((int)$cr['user_id'] !== (int)$restaurant['business_owner_id']) {
            createNotification(
                $conn,
                (int)$cr['user_id'],
                'New Order: ' . $order_number,
                $type_label . ' order received. Please check the orders queue.',
                'action_required',
                BASE_URL . 'dashboard/business_owner/manage_orders.php'
            );
        }
    }
    $cn->free();
}

// ── Notify the customer that their order was received ─────────────────────────
$cust_type_label = match($service_type) {
    'dine_in' => 'Dine-in',
    'takeout' => 'Takeout',
    default   => 'Delivery',
};
createNotification(
    $conn,
    $user_id,
    '✅ Order Received: ' . $order_number,
    'Your ' . $cust_type_label . ' order of ₱' . number_format($total_amount, 2) . ' at ' . $restaurant['restaurant_name'] . ' has been received.',
    'success',
    BASE_URL . 'dashboard/customer/orders.php'
);

// ── Cash payment — awaiting cashier confirmation ─────────────────────────────
if ($payment_method === 'cash') {
    $type_label = match($service_type) {
        'dine_in'  => 'Dine-in',
        'takeout'  => 'Takeout',
        default    => 'Delivery',
    };
    
    // Generate payment reference for cash orders
    $payment_reference = 'CASH-' . $order_number;
    $conn->query("UPDATE orders SET payment_reference = '{$payment_reference}' WHERE id = {$order_id}");
    
    // Return success with instructions for customer
    echo json_encode([
        'success'      => true,
        'message'      => 'Order ' . $order_number . ' placed (' . $type_label . ')! Please proceed to the cashier to complete payment.',
        'order_number' => $order_number,
        'order_id'     => $order_id,
        'payment_status' => 'awaiting_cash',
        'instructions' => 'Your order is pending. Please pay ₱' . number_format($total_amount, 2) . ' at the cashier counter.',
        'redirect_url' => BASE_URL . 'dashboard/customer/orders.php',
    ]);
    exit;
}

// ── Online payment (GCash / Bank Transfer) — create PayMongo session ──────────
$success_url = BASE_URL . 'dashboard/customer/api/order_payment_callback.php?order_id=' . $order_id . '&status=success';
$cancel_url  = BASE_URL . 'dashboard/customer/api/order_payment_callback.php?order_id=' . $order_id . '&status=cancel';

$description = 'Order ' . $order_number . ' — ' . $restaurant['restaurant_name'];

$checkout = createPayMongoCheckoutSession(
    $total_amount,
    $description,
    $success_url,
    $cancel_url,
    [
        'order_id'     => (string) $order_id,
        'order_number' => $order_number,
        'user_id'      => (string) $user_id,
        'type'         => 'food_order',
    ]
);

if (!$checkout['success']) {
    // PayMongo failed — roll back the order so it doesn't sit as a ghost pending record
    $ingredient_inventory->releaseOrderReservations((int)$order_id, $user_id);
    $del = $conn->prepare("DELETE FROM orders WHERE id = ? AND customer_id = ?");
    if ($del) {
        $del->bind_param("ii", $order_id, $user_id);
        $del->execute();
        $del->close();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Payment gateway error: ' . $checkout['message'] . ' Please try again.',
    ]);
    exit;
}

$checkout_data = $checkout['data']['data'] ?? [];
$checkout_id   = $checkout_data['id']                          ?? '';
$checkout_url  = $checkout_data['attributes']['checkout_url']  ?? '';

if (empty($checkout_id) || empty($checkout_url)) {
    // PayMongo responded but gave no usable URL — roll back order
    $ingredient_inventory->releaseOrderReservations((int)$order_id, $user_id);
    $del = $conn->prepare("DELETE FROM orders WHERE id = ? AND customer_id = ?");
    if ($del) {
        $del->bind_param("ii", $order_id, $user_id);
        $del->execute();
        $del->close();
    }
    echo json_encode(['success' => false, 'message' => 'Payment gateway did not return a checkout URL. Please try again.']);
    exit;
}

// Persist PayMongo payment record
$raw_response = json_encode($checkout['data']);
$pm_ins = $conn->prepare(
    "INSERT INTO payments
        (user_id, reference_type, reference_id, amount, payment_method,
         transaction_reference, paymongo_checkout_url, paymongo_raw_response, status)
     VALUES (?, 'order', ?, ?, ?, ?, ?, ?, 'pending')"
);
if (!$pm_ins) {
    $ingredient_inventory->releaseOrderReservations((int)$order_id, $user_id);
    $del = $conn->prepare("DELETE FROM orders WHERE id = ? AND customer_id = ?");
    if ($del) { $del->bind_param("ii", $order_id, $user_id); $del->execute(); $del->close(); }
    echo json_encode(['success' => false, 'message' => 'Failed to record payment: ' . $conn->error]);
    exit;
}
$pm_ins->bind_param("iidssss",
    $user_id, $order_id, $total_amount,
    $payment_method, $checkout_id, $checkout_url, $raw_response
);
if (!$pm_ins->execute()) {
    $pm_ins->close();
    $ingredient_inventory->releaseOrderReservations((int)$order_id, $user_id);
    $del = $conn->prepare("DELETE FROM orders WHERE id = ? AND customer_id = ?");
    if ($del) { $del->bind_param("ii", $order_id, $user_id); $del->execute(); $del->close(); }
    echo json_encode(['success' => false, 'message' => 'Failed to record payment. Please try again.']);
    exit;
}
$pm_ins->close();

echo json_encode([
    'success'      => true,
    'message'      => 'Redirecting to payment…',
    'order_number' => $order_number,
    'redirect_url' => $checkout_url,
]);
