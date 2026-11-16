<?php
/**
 * Operations Manager menu review endpoint.
 * Keeps the legacy URL while enforcing the structured Chef recipe workflow.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../includes/MenuWorkflowService.php';

header('Content-Type: application/json; charset=utf-8');
function menu_review_json(bool $success, string $message, array $extra = [], int $statusCode = 200): never {
    http_response_code($statusCode);
    echo json_encode(['success' => $success, 'message' => $message] + $extra);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') menu_review_json(false, 'Method not allowed.', [], 405);
if (!isLoggedIn() || (int)($_SESSION['role_id'] ?? 0) !== ROLE_BUSINESS_OWNER) menu_review_json(false, 'Access denied.', [], 403);
if (!MenuWorkflowService::validCsrfToken($_POST['csrf_token'] ?? null)) menu_review_json(false, 'Your session token is invalid. Refresh the page and try again.', [], 419);

$userId = (int)($_SESSION['user_id'] ?? 0);
$staffKey = $_SESSION['staff_role_key'] ?? null;
$canReview = $staffKey === null || $staffKey === STAFF_ROLE_OPS_MANAGER;
if (!$canReview) menu_review_json(false, 'Only the Business Owner or Operations Manager can review menus.', [], 403);
$restaurantId = getSessionRestaurantId($conn);
if ($restaurantId <= 0) menu_review_json(false, 'No restaurant found for this account.', [], 422);

$action = trim((string)($_POST['action'] ?? ''));
if ($action === 'return') $action = 'needs_revision';
if (!in_array($action, ['approve', 'needs_revision', 'reject'], true)) menu_review_json(false, 'Invalid menu review action.');
$menuId = (int)($_POST['item_id'] ?? $_POST['menu_item_id'] ?? 0);
if ($menuId <= 0) menu_review_json(false, 'Menu item is required.');
$remarks = trim((string)($_POST['review_remarks'] ?? $_POST['rejection_reason'] ?? ''));

$workflow = new MenuWorkflowService($conn);
if (!$workflow->schemaReady()) menu_review_json(false, 'The menu workflow migration has not been applied yet.', [], 503);
$isOwnerOverride = $staffKey === null && isRestaurantOwner($conn, $userId, $restaurantId);
$result = $workflow->review($restaurantId, $menuId, $userId, $action, $remarks, $isOwnerOverride);
menu_review_json($result['success'], $result['message'], ['errors' => $result['errors'] ?? []]);