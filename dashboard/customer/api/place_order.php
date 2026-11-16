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
 *   payment_method string  gcash | bank_transfer | paymongo (processed by PayMongo)
 *   notes          string  (optional)
 *
 * Returns JSON:
 *   { success, message, redirect_url? }
 */
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../includes/MenuWorkflowService.php';
require_once __DIR__ . '/../../../includes/IngredientInventoryService.php';
require_once __DIR__ . '/../../../includes/RevenuePaymentService.php';

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
$revenue_payments = new RevenuePaymentService($conn);
// Validate request fields. Client monetary values are intentionally ignored.
$restaurant_id = (int)($_POST['restaurant_id'] ?? 0);
$raw_items = $_POST['items_json'] ?? '[]';
$delivery_address = trim(sanitize($_POST['delivery_address'] ?? ''));
$addr_street = trim(sanitize($_POST['addr_street'] ?? ''));
$addr_barangay = trim(sanitize($_POST['addr_barangay'] ?? ''));
$addr_town = trim(sanitize($_POST['addr_town'] ?? ''));
$addr_city = trim(sanitize($_POST['addr_city'] ?? ''));
$addr_province = trim(sanitize($_POST['addr_province'] ?? ''));
if ($addr_street !== '') {
    $delivery_address = implode(', ', array_filter([$addr_street, $addr_barangay, $addr_town, $addr_city, $addr_province]));
}
$delivery_lat = floatval($_POST['delivery_lat'] ?? 0);
$delivery_lng = floatval($_POST['delivery_lng'] ?? 0);
$payment_method = sanitize($_POST['payment_method'] ?? 'gcash');
$notes = sanitize($_POST['notes'] ?? '');
$service_type = sanitize($_POST['service_type'] ?? 'delivery');
$table_number = trim(sanitize($_POST['table_number'] ?? ''));

if (!in_array($service_type, ['dine_in','takeout','delivery'], true)) {
    echo json_encode(['success'=>false,'message'=>'Invalid service type selected.']); exit;
}
if ($service_type === 'dine_in' && $table_number === '') $table_number = 'Unassigned';
$delivery_fee = $service_type === 'delivery' ? 50.00 : 0.00;
if ($service_type === 'delivery' && $delivery_address === '') {
    echo json_encode(['success'=>false,'message'=>'Delivery address is required for delivery orders.']); exit;
}
// Customer orders use PayMongo only. A request cannot fall back to cash or create
// an order before the payment is independently verified by PayMongo.
$allowed_methods = ['gcash','bank_transfer','paymongo'];
if (!in_array($payment_method, $allowed_methods, true)) {
    echo json_encode(['success'=>false,'message'=>'Customer orders require a PayMongo payment method.']); exit;
}
if ($restaurant_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid restaurant.']); exit; }

$items = json_decode($raw_items, true);
if (!is_array($items) || count($items) === 0) { echo json_encode(['success'=>false,'message'=>'Your cart is empty.']); exit; }
$requested = [];
foreach ($items as $item) {
    $id = (int)($item['id'] ?? 0); $qty = max(1, min(99, (int)($item['qty'] ?? 1)));
    if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid menu item in cart.']); exit; }
    $requested[] = ['id'=>$id,'qty'=>$qty];
}

$rest_stmt = $conn->prepare('SELECT id,restaurant_name,business_owner_id FROM halal_restaurants WHERE id=? AND is_active=1 LIMIT 1');
$rest_stmt->bind_param('i',$restaurant_id); $rest_stmt->execute();
$restaurant=$rest_stmt->get_result()->fetch_assoc(); $rest_stmt->close();
if (!$restaurant) { echo json_encode(['success'=>false,'message'=>'Restaurant not found or inactive.']); exit; }

// Reload names and prices from approved, live menu records; the browser cannot set totals.
$itemIds=array_values(array_unique(array_map(fn($item)=>(int)$item['id'],$requested)));
$idsSql=implode(',',$itemIds);
$menuResult=$conn->query("SELECT mi.id,mi.name,mi.price FROM menu_items mi WHERE mi.id IN ($idsSql) AND mi.restaurant_id={$restaurant_id} AND mi.is_available=1 AND ".MenuWorkflowService::customerEligibilitySql('mi'));
$menuById=[];
if ($menuResult) foreach ($menuResult->fetch_all(MYSQLI_ASSOC) as $row) $menuById[(int)$row['id']]=$row;
$safe_items=[]; $subtotal=0.0;
foreach ($requested as $item) {
    $row=$menuById[(int)$item['id']] ?? null;
    if (!$row || (float)$row['price'] <= 0) { echo json_encode(['success'=>false,'message'=>'One or more items are no longer available. Please refresh and try again.']); exit; }
    $price=round((float)$row['price'],2);
    $safe_items[]=['id'=>(int)$row['id'],'name'=>(string)$row['name'],'price'=>$price,'qty'=>(int)$item['qty']];
    $subtotal += $price * (int)$item['qty'];
}
$subtotal=round($subtotal,2);
if ($subtotal <= 0) { echo json_encode(['success'=>false,'message'=>'Order subtotal must be greater than zero.']); exit; }
$items_json_clean=json_encode($safe_items,JSON_UNESCAPED_UNICODE);
$discount_amount=0.00;
$platform_fee_details=$revenue_payments->platformFeeForOrder($subtotal,$delivery_fee,$payment_method);
$platform_fee=(float)$platform_fee_details['amount'];
$platform_fee_rate=(float)$platform_fee_details['rate'];
$total_amount=round($subtotal-$discount_amount+$delivery_fee+$platform_fee,2);
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

// Check current stock before sending the customer to payment. The paid-order
// fulfillment repeats the reservation inside its transaction, so the client
// cannot use this preflight check to bypass stock controls.
if (IngredientInventoryService::isFeatureEnabled() && $ingredient_inventory->schemaReady()) {
    foreach ($safe_items as $item) {
        $availability=$ingredient_inventory->availabilityForMenu($restaurant_id,(int)$item['id']);
        if (!in_array((string)($availability['operational_status']??''),['available','limited_stock'],true) || (int)($availability['maximum_servings']??0)<(int)$item['qty']) {
            echo json_encode(['success'=>false,'message'=>'Insufficient confirmed ingredient stock for '.(string)$item['name'].'.']); exit;
        }
    }
}

if (!$revenue_payments->schemaReady()) {
    echo json_encode(['success'=>false,'message'=>'Online payments are temporarily unavailable while billing is being installed.']);
    exit;
}

$checkout=$revenue_payments->startCustomerOrderCheckout($user_id,[
    'restaurant_id'=>$restaurant_id,
    'items_json'=>$items_json_clean,
    'subtotal'=>$subtotal,
    'delivery_fee'=>$delivery_fee,
    'platform_fee'=>$platform_fee,
    'platform_fee_rate'=>$platform_fee_rate,
    'total_amount'=>$total_amount,
    'delivery_address'=>$delivery_address,
    'service_type'=>$service_type,
    'payment_method'=>$payment_method,
    'notes'=>$notes,
    'table_number'=>$table_number,
    'survey_response_id'=>$survey_response_id,
]);
if (!$checkout['success']) {
    http_response_code((int)($checkout['http_status']??422));
    echo json_encode(['success'=>false,'message'=>$checkout['message']??'Could not create secure payment checkout.']);
    exit;
}

echo json_encode([
    'success'=>true,
    'message'=>'Redirecting to secure PayMongo payment. Your order will be created only after payment is verified.',
    'redirect_url'=>$checkout['checkout_url'],
]);