<?php
/** Secured HaCeRa AI advisory API. No scoring, publishing, or certification writes occur here. */
declare(strict_types=1);

// Legacy bootstrap code may emit displayable warnings. Buffer them so an API
// response remains valid JSON; diagnostic details continue to be logged by PHP.
ob_start();

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../includes/HaCeRaContextBuilder.php';
require_once __DIR__ . '/../../../includes/HaCeRaAIService.php';
require_once __DIR__ . '/../../../includes/HaCeRaEvidenceValidator.php';
require_once __DIR__ . '/../../../includes/HaCeRaInsightsRepository.php';
require_once __DIR__ . '/../../../includes/HaCeRaAIProcessor.php';

header('Content-Type: application/json; charset=utf-8');

function hacera_ai_json(array $payload, int $status = 200): void
{
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function hacera_ai_require_csrf(array $body): void
{
    $provided = (string)($_POST['csrf_token'] ?? $body['csrf_token'] ?? '');
    $expected = (string)($_SESSION['hacera_ai_csrf_token'] ?? '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        hacera_ai_json(['success' => false, 'message' => 'Your request could not be verified. Refresh the assessment page and try again.'], 403);
    }
}

if (!isLoggedIn() || (int)($_SESSION['role_id'] ?? 0) !== ROLE_BUSINESS_OWNER || ($_SESSION['staff_role_key'] ?? null) !== null) {
    hacera_ai_json(['success' => false, 'message' => 'Unauthorized.'], 403);
}
if (!HaCeRaAIService::isFeatureEnabled()) {
    hacera_ai_json(['success' => false, 'message' => 'AI Readiness Insights is not enabled for this environment.'], 503);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$body = stripos($contentType, 'application/json') !== false
    ? (json_decode((string)file_get_contents('php://input'), true) ?: [])
    : [];
$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? $body['action'] ?? ''));
$userId = (int)$_SESSION['user_id'];

$restaurantStmt = $conn->prepare('SELECT id FROM halal_restaurants WHERE business_owner_id = ? AND is_active = 1 LIMIT 1');
$restaurantStmt->bind_param('i', $userId);
$restaurantStmt->execute();
$restaurant = $restaurantStmt->get_result()->fetch_assoc();
$restaurantStmt->close();
if (!$restaurant) hacera_ai_json(['success' => false, 'message' => 'No active restaurant is assigned to this account.'], 404);
$restaurantId = (int)$restaurant['id'];

$repository = new HaCeRaInsightsRepository($conn);
if (!$repository->hasSchema()) {
    hacera_ai_json(['success' => false, 'message' => 'AI Insights has not been installed. Run database/hacera_ai_insights_migration.sql first.'], 503);
}
$builder = new HaCeRaContextBuilder($conn);
$service = new HaCeRaAIService();
$processor = new HaCeRaAIProcessor($builder, $service, new HaCeRaEvidenceValidator(), $repository, $conn);

try {
    if ($action === 'generate') {
        hacera_ai_require_csrf($body);
        $dailyLimit = max(1, min(100, (int)(getenv('HACERA_AI_MAX_RUNS_PER_DAY') ?: 10)));
        if ($repository->countRecentRuns($restaurantId) >= $dailyLimit) {
            hacera_ai_json(['success' => false, 'message' => 'The daily AI analysis limit for this restaurant has been reached.'], 429);
        }
        $context = $builder->build($restaurantId);
        $inputHash = $builder->hash($context);
        $existing = $repository->findReusableRun($restaurantId, $inputHash);
        if ($existing !== null) {
            $run = $repository->getRun((int)$existing['id'], $restaurantId);
            hacera_ai_json(['success' => true, 'deduplicated' => true, 'run' => $run]);
        }
        if (!$service->isConfigured()) {
            hacera_ai_json(['success' => false, 'message' => 'The AI provider is not configured.'], 503);
        }
        $runId = $repository->createRun($restaurantId, $userId, $inputHash, $service->provider(), (string)(getenv('HACERA_AI_MODEL') ?: ''), (string)$context['criteria_version']);
        @logActivity($conn, $userId, 'hacera_ai_requested', 'Requested AI readiness analysis for restaurant #' . $restaurantId, 'hacera_ai');

        if (HaCeRaAIService::shouldProcessInline()) {
            $processed = $processor->process($runId, $restaurantId);
            if (empty($processed['success'])) {
                $providerUnavailable = in_array((string)($processed['error_code'] ?? ''), ['provider_unreachable', 'provider_rate_limited', 'provider_temporarily_unavailable'], true);
                // The request itself completed and its failed run was recorded.
                // Return temporary upstream provider failures as JSON so the
                // page can show its friendly error state without a browser
                // resource-load error. Other application failures stay 502.
                hacera_ai_json([
                    'success' => false,
                    'retryable' => $providerUnavailable,
                    'message' => $processed['message'] ?? 'AI analysis failed.',
                    'run_id' => $runId,
                ], $providerUnavailable ? 200 : 502);
            }
            hacera_ai_json(['success' => true, 'run' => $processed['run']]);
        }
        hacera_ai_json(['success' => true, 'queued' => true, 'run' => $repository->getRun($runId, $restaurantId)]);
    }

    if ($action === 'status' || $action === 'results') {
        $runId = (int)($_GET['run_id'] ?? $body['run_id'] ?? 0);
        if ($runId < 1) hacera_ai_json(['success' => false, 'message' => 'A valid analysis request is required.'], 422);
        $run = $repository->getRun($runId, $restaurantId);
        if ($run === null) hacera_ai_json(['success' => false, 'message' => 'Analysis request not found.'], 404);
        $context = $builder->build($restaurantId);
        $run['is_outdated'] = !hash_equals((string)$run['input_hash'], $builder->hash($context));
        hacera_ai_json(['success' => true, 'run' => $run]);
    }

    if ($action === 'history') {
        $currentHash = $builder->hash($builder->build($restaurantId));
        $history = $repository->history($restaurantId);
        foreach ($history as &$row) $row['is_outdated'] = !hash_equals((string)$row['input_hash'], $currentHash);
        hacera_ai_json(['success' => true, 'history' => $history]);
    }

    if ($action === 'update_action') {
        hacera_ai_require_csrf($body);
        $actionId = (int)($_POST['action_id'] ?? $body['action_id'] ?? 0);
        $status = (string)($_POST['action_status'] ?? $body['action_status'] ?? '');
        if ($actionId < 1 || !$repository->updateAction($actionId, $restaurantId, $userId, $status)) {
            hacera_ai_json(['success' => false, 'message' => 'The action could not be updated.'], 422);
        }
        @logActivity($conn, $userId, 'hacera_ai_action_updated', 'Updated AI corrective action #' . $actionId, 'hacera_ai');
        hacera_ai_json(['success' => true, 'message' => 'Action status updated.']);
    }

    if ($action === 'submit_feedback') {
        hacera_ai_require_csrf($body);
        $runId = (int)($_POST['run_id'] ?? $body['run_id'] ?? 0);
        $rating = (int)($_POST['rating'] ?? $body['rating'] ?? 0);
        $comment = (string)($_POST['comment'] ?? $body['comment'] ?? '');
        if ($runId < 1 || $rating < 1 || $rating > 5 || $repository->getRun($runId, $restaurantId) === null) {
            hacera_ai_json(['success' => false, 'message' => 'Feedback could not be saved.'], 422);
        }
        if (!$repository->submitFeedback($runId, $restaurantId, $userId, $rating, $comment)) {
            hacera_ai_json(['success' => false, 'message' => 'Feedback could not be saved.'], 500);
        }
        hacera_ai_json(['success' => true, 'message' => 'Thank you for your feedback.']);
    }
} catch (Throwable $e) {
    error_log('HaCeRa AI API error: ' . $e->getMessage());
    hacera_ai_json(['success' => false, 'message' => 'The AI Insights request could not be completed.'], 500);
}

hacera_ai_json(['success' => false, 'message' => 'Invalid action.'], 400);
