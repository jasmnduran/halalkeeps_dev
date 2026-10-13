<?php
/** Shared processor used by the secured owner API and optional CLI queue worker. */
declare(strict_types=1);

require_once __DIR__ . '/HaCeRaContextBuilder.php';
require_once __DIR__ . '/HaCeRaAIService.php';
require_once __DIR__ . '/HaCeRaEvidenceValidator.php';
require_once __DIR__ . '/HaCeRaInsightsRepository.php';

final class HaCeRaAIProcessor
{
    public function __construct(
        private HaCeRaContextBuilder $contextBuilder,
        private HaCeRaAIService $service,
        private HaCeRaEvidenceValidator $validator,
        private HaCeRaInsightsRepository $repository,
        private mysqli $conn
    ) {
    }

    /** @return array<string,mixed> */
    public function process(int $runId, int $restaurantId, bool $alreadyClaimed = false): array
    {
        if (!$alreadyClaimed && !$this->repository->markProcessing($runId)) {
            return ['success' => false, 'message' => 'This analysis request is no longer available for processing.'];
        }
        try {
            $run = $this->repository->getRun($runId, $restaurantId);
            if ($run === null) {
                return ['success' => false, 'message' => 'Analysis request not found.'];
            }

            $context = $this->contextBuilder->build($restaurantId);
            if (!hash_equals((string)$run['input_hash'], $this->contextBuilder->hash($context))) {
                $this->repository->markExpired($runId, 'The assessment changed before the advisory analysis could run.');
                return ['success' => false, 'message' => 'The assessment changed. Generate a new analysis to use the latest responses.'];
            }

            $providerResult = $this->service->analyse($context);
            if (empty($providerResult['success'])) {
                $message = (string)($providerResult['message'] ?? 'AI analysis failed.');
                $errorCode = (string)($providerResult['error_code'] ?? 'provider_error');
                $this->repository->markFailed($runId, $errorCode, $message);
                return ['success' => false, 'error_code' => $errorCode, 'message' => $message];
            }

            $validated = $this->validator->validate((array)$providerResult['report'], $context);
            $this->repository->storeValidatedReport($runId, $restaurantId, $validated);
            $fresh = $this->repository->getRun($runId, $restaurantId);
            if ($fresh === null) {
                throw new RuntimeException('AI results were saved but could not be reloaded.');
            }
            return ['success' => true, 'run' => $fresh];
        } catch (Throwable $e) {
            // The client receives a safe message; detailed diagnostics stay out of
            // user-facing output and must never contain provider credentials.
            $this->repository->markFailed($runId, 'processing_failed', 'The advisory analysis could not be completed.');
            error_log('HaCeRa AI processing failed for run ' . $runId . ': ' . $e->getMessage());
            return ['success' => false, 'message' => 'The advisory analysis could not be completed. Please try again later.'];
        }
    }

    /** @return int Number of jobs claimed by this worker invocation. */
    public function processQueued(int $maxJobs = 5): int
    {
        $processed = 0;
        while ($processed < max(1, $maxJobs)) {
            $job = $this->repository->claimNextQueued();
            if ($job === null) break;
            $this->process((int)$job['id'], (int)$job['restaurant_id'], true);
            $processed++;
        }
        return $processed;
    }
}
