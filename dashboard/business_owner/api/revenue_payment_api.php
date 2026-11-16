<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../includes/RevenuePaymentService.php';

header('Content-Type: application/json; charset=utf-8');
if (!isLoggedIn() || (int)($_SESSION['role_id'] ?? 0) !== ROLE_BUSINESS_OWNER) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit;
}
$userId=(int)$_SESSION['user_id']; $restaurantId=getSessionRestaurantId($conn);
if (!$restaurantId) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'No active restaurant found.']); exit; }
$service=new RevenuePaymentService($conn); $action=(string)($_REQUEST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'capacity') { echo json_encode(['success'=>true,'capacity'=>$service->getInventoryCapacity($restaurantId),'csrf_token'=>RevenuePaymentService::ensureCsrfToken()]); exit; }
    if ($action === 'history') { echo json_encode(['success'=>true,'purchases'=>$service->recentPurchases($restaurantId,$userId),'csrf_token'=>RevenuePaymentService::ensureCsrfToken()]); exit; }
    http_response_code(404); echo json_encode(['success'=>false,'message'=>'Unknown action.']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !RevenuePaymentService::validCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(419); echo json_encode(['success'=>false,'message'=>'Your billing session expired. Refresh and try again.']); exit;
}
$result = match($action) {
    'start_hacera_report' => $service->startHaceraReport($userId,$restaurantId),
    'start_pnl_export' => $service->startPnlExport($userId,$restaurantId,trim((string)($_POST['period_start']??'')),trim((string)($_POST['period_end']??''))),
    'start_storage_pack' => $service->startStoragePack($userId,$restaurantId),
    default => ['success'=>false,'message'=>'Unknown billing action.','http_status'=>404],
};
http_response_code((int)($result['http_status'] ?? ($result['success'] ? 200 : 422)));
echo json_encode($result);