<?php
/** Create a menu draft. A recipe and Chef-confirmed mappings are required before submission. */
declare(strict_types=1);
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../includes/MenuWorkflowService.php';

header('Content-Type: application/json; charset=utf-8');
function menu_draft_json(bool $success, string $message, array $extra = [], int $statusCode = 200): never {
    http_response_code($statusCode);
    echo json_encode(['success' => $success, 'message' => $message] + $extra);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') menu_draft_json(false, 'Method not allowed.', [], 405);
if (!isLoggedIn() || (int)($_SESSION['role_id'] ?? 0) !== ROLE_BUSINESS_OWNER) menu_draft_json(false, 'Access denied.', [], 403);
if (!MenuWorkflowService::validCsrfToken($_POST['csrf_token'] ?? null)) menu_draft_json(false, 'Your session token is invalid. Refresh the page and try again.', [], 419);

$userId = (int)($_SESSION['user_id'] ?? 0);
$staffKey = $_SESSION['staff_role_key'] ?? null;
if (!in_array($staffKey, [null, STAFF_ROLE_CHEF], true)) menu_draft_json(false, 'Only the Chef or Business Owner can create menu drafts.', [], 403);
$restaurantId = getSessionRestaurantId($conn);
if ($restaurantId <= 0) menu_draft_json(false, 'No active restaurant was found.', [], 422);
$workflow = new MenuWorkflowService($conn);
if (!$workflow->schemaReady()) menu_draft_json(false, 'The menu workflow migration has not been applied yet.', [], 503);

$name = trim(sanitize($_POST['dish_name'] ?? ''));
$category = trim(sanitize($_POST['dish_cat'] ?? ''));
$priceRaw = trim((string)($_POST['dish_price'] ?? ''));
$description = sanitize($_POST['dish_desc'] ?? '');
$servingType = sanitize($_POST['dish_serving_type'] ?? 'Per serving');
if ($name === '') menu_draft_json(false, 'Menu name is required.');
if ($category === '' || !in_array($category, MENU_ITEM_CATEGORIES, true)) menu_draft_json(false, 'Select a valid menu category.');
if ($priceRaw === '' || !is_numeric($priceRaw) || (float)$priceRaw < 0) menu_draft_json(false, 'A valid menu price is required.');
if (!in_array($servingType, ALLOWED_SERVING_TYPES, true)) $servingType = 'Per serving';
$price = round((float)$priceRaw, 2);

$imagePath = '';
if (!empty($_FILES['dish_image']['name']) && ($_FILES['dish_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $upload = uploadFile($_FILES['dish_image'], 'menu_images', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    if (!$upload['success']) menu_draft_json(false, 'Menu image could not be uploaded.');
    $imagePath = (string)$upload['path'];
}

$stmt = $conn->prepare("INSERT INTO menu_items (restaurant_id,name,description,price,category,image_path,serving_type,is_available,approval_status,workflow_status,workflow_updated_at,submitted_by) VALUES (?,?,?,?,?,?,?,1,'pending','draft',NOW(),?)");
if (!$stmt) menu_draft_json(false, 'Could not prepare menu draft.', [], 500);
$stmt->bind_param('issdsssi', $restaurantId, $name, $description, $price, $category, $imagePath, $servingType, $userId);
if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); menu_draft_json(false, 'Could not save menu draft.', [], 500); }
$menuId = (int)$conn->insert_id;
$stmt->close();

logActivity($conn, $userId, 'Menu draft created', json_encode(['restaurant_id' => $restaurantId, 'menu_item_id' => $menuId]), 'menu_workflow');
menu_draft_json(true, 'Menu draft saved. Create its recipe and confirm every ingredient mapping before submitting it for review.', [
    'approval_status' => 'pending',
    'workflow_status' => 'draft',
    'item' => [
        'id' => $menuId, 'name' => $name, 'description' => $description,
        'price' => $price, 'category' => $category, 'serving_type' => $servingType,
        'image_url' => $imagePath ? BASE_URL . 'uploads/' . $imagePath : '',
        'approval_status' => 'pending', 'workflow_status' => 'draft',
    ],
]);