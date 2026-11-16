<?php
/** Secure endpoint for Chef menu submission after recipe mapping is complete. */
declare(strict_types=1);
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../includes/MenuWorkflowService.php';

header('Content-Type: application/json; charset=utf-8');
function mw_json(bool $success, string $message, array $extra = [], int $statusCode = 200): never {
    http_response_code($statusCode);
    echo json_encode(['success' => $success, 'message' => $message] + $extra);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') mw_json(false, 'Method not allowed.', [], 405);
if (!isLoggedIn() || (int)($_SESSION['role_id'] ?? 0) !== ROLE_BUSINESS_OWNER) mw_json(false, 'Access denied.', [], 403);
if (!MenuWorkflowService::validCsrfToken($_POST['csrf_token'] ?? null)) mw_json(false, 'Your session token is invalid. Refresh the page and try again.', [], 419);

$restaurantId = getSessionRestaurantId($conn);
$userId = (int)($_SESSION['user_id'] ?? 0);
$staffKey = $_SESSION['staff_role_key'] ?? null;
if ($restaurantId <= 0 || $userId <= 0) mw_json(false, 'No active restaurant was found.', [], 422);

$workflow = new MenuWorkflowService($conn);
if (!$workflow->schemaReady()) mw_json(false, 'The menu workflow migration has not been applied yet.', [], 503);
$action = trim((string)($_POST['action'] ?? ''));
$menuId = (int)($_POST['menu_item_id'] ?? 0);
if ($menuId <= 0) mw_json(false, 'Menu item is required.');

if ($action === 'submit') {
    if (!in_array($staffKey, [null, STAFF_ROLE_CHEF], true)) mw_json(false, 'Only the Chef or Business Owner can submit a menu for review.', [], 403);
    $result = $workflow->submit($restaurantId, $menuId, $userId);
    mw_json($result['success'], $result['message'], [
        'errors' => $result['errors'] ?? [],
        'availability' => $result['availability'] ?? null,
    ]);
}

if ($action === 'delete') {
    if (!in_array($staffKey, [null, STAFF_ROLE_CHEF], true)) mw_json(false, 'Only the Chef or Business Owner can delete menus.', [], 403);
    $ownerOverride = $staffKey === null && isRestaurantOwner($conn, $userId, $restaurantId);
    $result = $workflow->deleteChefMenu($restaurantId, $menuId, $userId, $ownerOverride);
    mw_json($result['success'], $result['message']);
}
mw_json(false, 'Unknown workflow action.');