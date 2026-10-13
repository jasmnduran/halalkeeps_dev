<?php
/** Secured administrative API for Intelligent Document Screening. */
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../includes/DocumentOCRService.php';
require_once __DIR__ . '/../../../includes/DocumentScreeningRepository.php';
require_once __DIR__ . '/../../../includes/DocumentClassificationService.php';
require_once __DIR__ . '/../../../includes/DocumentFieldExtractor.php';
require_once __DIR__ . '/../../../includes/DocumentConsistencyValidator.php';
require_once __DIR__ . '/../../../includes/CertificateRecognitionService.php';
require_once __DIR__ . '/../../../includes/DocumentScreeningAIService.php';
require_once __DIR__ . '/../../../includes/DocumentScreeningService.php';

header('Content-Type: application/json; charset=utf-8');

function document_screening_json(array $payload, int $status = 200): void
{
    if (ob_get_level() > 0) ob_end_clean();
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function document_screening_require_csrf(array $body): void
{
    $provided = (string)($_POST['csrf_token'] ?? $body['csrf_token'] ?? '');
    $expected = (string)($_SESSION['document_screening_csrf_token'] ?? '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        document_screening_json(['success' => false, 'message' => 'Your request could not be verified. Refresh the page and try again.'], 403);
    }
}

if (!isLoggedIn() || (int)($_SESSION['role_id'] ?? 0) !== ROLE_ADMIN) {
    document_screening_json(['success' => false, 'message' => 'Unauthorized.'], 403);
}
if (!DocumentOCRService::isFeatureEnabled()) {
    document_screening_json(['success' => false, 'message' => 'Intelligent Document Screening is disabled for this environment.'], 503);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$body = stripos($contentType, 'application/json') !== false
    ? (json_decode((string)file_get_contents('php://input'), true) ?: [])
    : [];
$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? $body['action'] ?? ''));
$applicationId = (int)($_POST['application_id'] ?? $_GET['application_id'] ?? $body['application_id'] ?? 0);
if ($applicationId < 1) document_screening_json(['success' => false, 'message' => 'A valid application is required.'], 422);

$repository = new DocumentScreeningRepository($conn);
if (!$repository->hasSchema()) {
    document_screening_json([
        'success' => false,
        'message' => 'Document Screening has not been installed. Apply database/document_screening_migration.sql first.',
        'installation_required' => true,
    ], 503);
}
if ($repository->getApplication($applicationId) === null) {
    document_screening_json(['success' => false, 'message' => 'Application not found.'], 404);
}

$service = new DocumentScreeningService($repository);
$adminId = (int)$_SESSION['user_id'];

try {
    if ($action === 'start_screening') {
        document_screening_require_csrf($body);
        $sourceType = trim((string)($_POST['source_type'] ?? $body['source_type'] ?? ''));
        $sourceId = (int)($_POST['source_document_id'] ?? $body['source_document_id'] ?? 0);
        $jobIds = $service->startForApplication($applicationId, $adminId, $sourceType !== '' ? $sourceType : null, $sourceId > 0 ? $sourceId : null);
        if ($jobIds === []) document_screening_json(['success' => false, 'message' => 'No authorized document reference was selected.'], 422);
        $processed = [];
        if (DocumentOCRService::shouldProcessInline()) {
            foreach ($jobIds as $jobId) $processed[] = $service->process($jobId);
        }
        @logActivity($conn, $adminId, 'document_screening_started', 'Started screening for application #' . $applicationId . ' (' . count($jobIds) . ' document(s))', 'document_screening');
        document_screening_json([
            'success' => true,
            'queued' => !DocumentOCRService::shouldProcessInline(),
            'job_ids' => $jobIds,
            'processed' => $processed,
            'results' => $repository->applicationResults($applicationId),
        ]);
    }

    if (in_array($action, ['screening_status', 'screening_results', 'extracted_fields', 'screening_history'], true)) {
        document_screening_json(['success' => true, 'results' => $repository->applicationResults($applicationId)]);
    }

    if ($action === 'review_finding' || $action === 'request_clarification') {
        document_screening_require_csrf($body);
        $findingId = (int)($_POST['finding_id'] ?? $body['finding_id'] ?? 0);
        $finding = $repository->getFindingForApplication($findingId, $applicationId);
        if ($finding === null) document_screening_json(['success' => false, 'message' => 'The selected finding was not found for this application.'], 404);
        $type = $action === 'request_clarification'
            ? 'request_clarification'
            : trim((string)($_POST['review_action'] ?? $body['review_action'] ?? 'reviewed'));
        $correction = $_POST['corrected_value'] ?? $body['corrected_value'] ?? null;
        $comment = $_POST['reviewer_comment'] ?? $body['reviewer_comment'] ?? null;
        if ($type === 'correct_ocr' && trim((string)$correction) === '') {
            document_screening_json(['success' => false, 'message' => 'Enter the corrected transcription before saving the OCR correction.'], 422);
        }
        if ($type === 'request_clarification' && trim((string)$comment) === '') {
            document_screening_json(['success' => false, 'message' => 'Enter the clarification message that should be sent to the applicant.'], 422);
        }
        if (!$repository->recordDecision($findingId, $applicationId, $adminId, $type, is_string($correction) ? $correction : null, is_string($comment) ? $comment : null)) {
            document_screening_json(['success' => false, 'message' => 'The screening review could not be saved.'], 422);
        }
        if ($type === 'request_clarification') {
            $app = $repository->getApplication($applicationId);
            if ($app !== null) {
                $message = trim((string)$comment);
                $message = $message !== '' ? $message : 'Please review the document information in your business application and provide the requested clarification or updated evidence.';
                @createNotification($conn, (int)$app['user_id'], 'Document clarification requested', $message, 'warning', BASE_URL . 'dashboard/customer/apply_business.php');
            }
        }
        @logActivity($conn, $adminId, 'document_screening_' . $type, 'Reviewed finding #' . $findingId . ' for application #' . $applicationId, 'document_screening');
        document_screening_json(['success' => true, 'message' => 'Screening review saved.', 'results' => $repository->applicationResults($applicationId)]);
    }
} catch (Throwable $e) {
    error_log('Document screening API error: ' . $e->getMessage());
    document_screening_json(['success' => false, 'message' => 'The document screening request could not be completed.'], 500);
}

document_screening_json(['success' => false, 'message' => 'Invalid action.'], 400);
