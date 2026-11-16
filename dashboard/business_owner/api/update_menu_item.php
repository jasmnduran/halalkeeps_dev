<?php
/** Update a menu draft or return an approved menu to recipe completion before re-review. */
declare(strict_types=1);
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../includes/MenuWorkflowService.php';

header('Content-Type: application/json; charset=utf-8');
function menu_update_json(bool $success, string $message, array $extra = [], int $statusCode = 200): never {
    http_response_code($statusCode);
    echo json_encode(['success' => $success, 'message' => $message] + $extra);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') menu_update_json(false, 'Method not allowed.', [], 405);
if (!isLoggedIn() || (int)($_SESSION['role_id'] ?? 0) !== ROLE_BUSINESS_OWNER) menu_update_json(false, 'Access denied.', [], 403);
if (!MenuWorkflowService::validCsrfToken($_POST['csrf_token'] ?? null)) menu_update_json(false, 'Your session token is invalid. Refresh the page and try again.', [], 419);

$userId = (int)($_SESSION['user_id'] ?? 0);
$staffKey = $_SESSION['staff_role_key'] ?? null;
if (!in_array($staffKey, [null, STAFF_ROLE_CHEF], true)) menu_update_json(false, 'Only the Chef or Business Owner can edit menu drafts.', [], 403);
$restaurantId = getSessionRestaurantId($conn);
$menuId = (int)($_POST['item_id'] ?? 0);
if ($restaurantId <= 0 || $menuId <= 0) menu_update_json(false, 'Menu item and active restaurant are required.', [], 422);
$workflow = new MenuWorkflowService($conn);
if (!$workflow->schemaReady()) menu_update_json(false, 'The menu workflow migration has not been applied yet.', [], 503);

$existingStmt = $conn->prepare('SELECT id,image_path,workflow_status FROM menu_items WHERE id=? AND restaurant_id=? LIMIT 1');
$existingStmt->bind_param('ii', $menuId, $restaurantId); $existingStmt->execute();
$existing = $existingStmt->get_result()->fetch_assoc(); $existingStmt->close();
if (!$existing) menu_update_json(false, 'Menu item not found or access denied.', [], 404);
if (($existing['workflow_status'] ?? '') === MenuWorkflowService::PENDING_REVIEW) menu_update_json(false, 'This menu is currently under review. Wait for a decision before editing it.');

$name = trim(sanitize($_POST['dish_name'] ?? ''));
$category = trim(sanitize($_POST['dish_cat'] ?? ''));
$priceRaw = trim((string)($_POST['dish_price'] ?? ''));
$description = sanitize($_POST['dish_desc'] ?? '');
$servingType = sanitize($_POST['dish_serving_type'] ?? 'Per serving');
if ($name === '') menu_update_json(false, 'Menu name is required.');
if ($category === '' || !in_array($category, MENU_ITEM_CATEGORIES, true)) menu_update_json(false, 'Select a valid menu category.');
if ($priceRaw === '' || !is_numeric($priceRaw) || (float)$priceRaw < 0) menu_update_json(false, 'A valid menu price is required.');
if (!in_array($servingType, ALLOWED_SERVING_TYPES, true)) $servingType = 'Per serving';
$price = round((float)$priceRaw, 2);

$imagePath = (string)($existing['image_path'] ?? '');
$newImagePath = null;
if (!empty($_FILES['dish_image']['name']) && ($_FILES['dish_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $upload = uploadFile($_FILES['dish_image'], 'menu_images', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    if (!$upload['success']) menu_update_json(false, 'Menu image could not be uploaded.');
    $imagePath = (string)$upload['path'];
    $newImagePath = $imagePath;
}

$workflowStatus = ($existing['workflow_status'] ?? '') === MenuWorkflowService::NEEDS_REVISION
    ? MenuWorkflowService::NEEDS_REVISION : MenuWorkflowService::DRAFT;
$update = $conn->prepare("UPDATE menu_items SET name=?,description=?,price=?,category=?,serving_type=?,image_path=?,approval_status='pending',workflow_status=?,workflow_updated_at=NOW(),submitted_by=?,reviewed_by=NULL,reviewed_at=NULL,rejection_reason=NULL,updated_at=NOW() WHERE id=? AND restaurant_id=?");
$update->bind_param('ssdssssiii', $name, $description, $price, $category, $servingType, $imagePath, $workflowStatus, $userId, $menuId, $restaurantId);
if (!$update->execute()) { $update->close(); menu_update_json(false, 'Could not update menu draft.', [], 500); }
$update->close();
$recipeVisibility = $conn->prepare("UPDATE menu_recipes SET customer_visibility_status=CASE WHEN customer_visibility_status='published' THEN 'needs_reapproval' ELSE 'draft' END,customer_visible_by=NULL,customer_visible_at=NULL,last_updated_by=? WHERE restaurant_id=? AND menu_item_id=? AND recipe_status='active'");
$recipeVisibility->bind_param('iii', $userId, $restaurantId, $menuId); $recipeVisibility->execute(); $recipeVisibility->close();
if ($newImagePath !== null && !empty($existing['image_path'])) {
    $oldFile = UPLOAD_DIR . $existing['image_path'];
    if (is_file($oldFile)) @unlink($oldFile);
}

logActivity($conn, $userId, 'Menu draft updated', json_encode(['restaurant_id' => $restaurantId, 'menu_item_id' => $menuId]), 'menu_workflow');
menu_update_json(true, 'Menu details saved. Recheck its recipe and submit it for Operations Manager review when complete.', [
    'approval_status' => 'pending', 'workflow_status' => $workflowStatus,
    'item' => ['id' => $menuId, 'name' => $name, 'description' => $description, 'price' => $price,
        'category' => $category, 'serving_type' => $servingType,
        'image_url' => $imagePath ? BASE_URL . 'uploads/' . $imagePath : '',
        'image_path' => $imagePath, 'approval_status' => 'pending', 'workflow_status' => $workflowStatus],
]);