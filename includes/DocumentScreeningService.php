<?php
/** Coordinates OCR, field extraction, deterministic validation, and review priority. */
declare(strict_types=1);

require_once __DIR__ . '/DocumentScreeningAIService.php';

final class DocumentScreeningService
{
    /** @var DocumentScreeningRepository */
    private $repository;
    /** @var DocumentOCRService */
    private $ocr;
    /** @var DocumentClassificationService */
    private $classifier;
    /** @var DocumentFieldExtractor */
    private $extractor;
    /** @var DocumentConsistencyValidator */
    private $validator;
    /** @var CertificateRecognitionService */
    private $recognition;
    /** @var DocumentScreeningAIService */
    private $ai;

    public function __construct(
        DocumentScreeningRepository $repository,
        ?DocumentOCRService $ocr = null,
        ?DocumentClassificationService $classifier = null,
        ?DocumentFieldExtractor $extractor = null,
        ?DocumentConsistencyValidator $validator = null,
        ?CertificateRecognitionService $recognition = null,
        ?DocumentScreeningAIService $ai = null
    ) {
        $this->repository = $repository;
        $this->ocr = $ocr ?? new DocumentOCRService();
        $this->classifier = $classifier ?? new DocumentClassificationService();
        $this->extractor = $extractor ?? new DocumentFieldExtractor();
        $this->validator = $validator ?? new DocumentConsistencyValidator();
        $this->recognition = $recognition ?? new CertificateRecognitionService();
        $this->ai = $ai ?? new DocumentScreeningAIService();
    }

    /** @return array<int,int> */
    public function startForApplication(int $applicationId, int $requestedBy, ?string $sourceType = null, ?int $sourceDocumentId = null): array
    {
        $documents = $this->repository->getApplicationDocuments($applicationId);
        $jobIds = [];
        foreach ($documents as $document) {
            if ($sourceType !== null && ((string)$document['source_type'] !== $sourceType || (int)$document['source_document_id'] !== $sourceDocumentId)) {
                continue;
            }
            $jobIds[] = $this->repository->createJob($applicationId, $document, $requestedBy);
        }
        return $jobIds;
    }

    /** @return array<string,mixed> */
    public function process(int $jobId): array
    {
        $job = $this->repository->getJob($jobId);
        if ($job === null) return ['success' => false, 'message' => 'Screening job not found.'];
        $application = $this->repository->getApplication((int)$job['application_id']);
        $document = $this->repository->getSourceForJob($job);
        if ($application === null || $document === null) {
            $this->repository->failJob($jobId, 'document_reference_missing', 'The referenced application document could not be found.');
            return ['success' => false, 'message' => 'The referenced application document could not be found.'];
        }
        $this->repository->markProcessing($jobId);
        $path = $this->repository->resolveUploadPath((string)$document['file_path']);
        if ($path === null) {
            $this->repository->failJob($jobId, 'file_unavailable', 'The original upload is not accessible within protected storage.');
            return ['success' => false, 'message' => 'The original upload is not accessible within protected storage.'];
        }

        try {
            $ocr = $this->ocr->extract($path, $document['mime_type'] ?? null);
            if (empty($ocr['success'])) {
                return $this->completeUnavailableOcr($job, (string)($ocr['error_code'] ?? 'ocr_failed'), (string)($ocr['message'] ?? 'OCR could not process this document.'));
            }

            $classification = $this->classifier->classify((string)$ocr['text'], (string)($document['doc_type'] ?? ''));
            $extraction = $this->extractor->extract((string)$ocr['text'], $classification, isset($ocr['confidence']) ? (float)$ocr['confidence'] : null);
            $fields = $extraction['fields'];
            $findings = $this->validator->validate($application, $fields, (string)$classification['document_type']);
            $findings = array_merge($findings, $this->classificationFindings($classification));
            if ((string)$classification['document_type'] === 'halal_certificate') {
                $findings = array_merge($findings, $this->recognition->screen($fields, $this->repository));
            }
            if (isset($ocr['confidence']) && (float)$ocr['confidence'] < 0.65) {
                $findings[] = $this->finding('ocr_confidence', 'ocr_text', 'requires_review', 'medium', null, null,
                    'OCR confidence is low, so extracted values should be checked against the original document.', 'continue_review');
            }
            $aiExplanation = $this->ai->contextualExplanation($classification, $fields, $findings);
            if ($aiExplanation !== null) {
                $findings[] = $this->finding('ai_contextual_explanation', 'review_context', 'matched', 'low', null, null,
                    (string)$aiExplanation['explanation'], 'continue_review');
            }
            $priority = $this->priorityFinding($findings);
            $findings[] = $priority;
            $fieldIds = $this->repository->replaceExtractedFields($jobId, $fields);
            $this->repository->replaceFindings($jobId, (int)$job['application_id'], null, $findings, $fieldIds);

            $retentionDays = max(1, min(90, (int)(getenv('DOCUMENT_SCREENING_TEXT_RETENTION_DAYS') ?: 14)));
            $this->repository->completeJob($jobId, [
                'status' => $priority['outcome'] === 'requires_review' ? 'requires_review' : 'completed',
                'document_type' => $classification['document_type'],
                'classification_confidence' => $classification['confidence'],
                'ocr_method' => $ocr['method'],
                'ocr_confidence' => $ocr['confidence'],
                'text_hash' => hash('sha256', (string)$ocr['text']),
                'extracted_text' => (string)$ocr['text'],
                'text_retention_until' => gmdate('Y-m-d H:i:s', time() + ($retentionDays * 86400)),
                'model_version' => $aiExplanation['model'] ?? null,
            ]);
            return ['success' => true, 'job_id' => $jobId, 'priority' => $priority['severity']];
        } catch (Throwable $e) {
            error_log('Document screening job ' . $jobId . ' failed: ' . $e->getMessage());
            $this->repository->failJob($jobId, 'screening_failed', 'The document screening job could not be completed. Original documents remain available for manual review.');
            return ['success' => false, 'message' => 'The screening job could not be completed.'];
        }
    }

    public function processQueued(int $limit): int
    {
        $processed = 0;
        foreach ($this->repository->claimQueuedJobs($limit) as $job) {
            $this->process((int)$job['id']);
            $processed++;
        }
        return $processed;
    }

    /** @return array<string,mixed> */
    private function completeUnavailableOcr(array $job, string $code, string $message): array
    {
        $outcome = $code === 'unreadable' ? 'unreadable' : 'requires_review';
        $severity = $code === 'ocr_engine_unavailable' ? 'medium' : 'high';
        $finding = $this->finding('ocr_processing', 'ocr_text', $outcome, $severity, null, null,
            $message . ' Manual review of the original document remains available.', 'continue_review');
        $priority = $this->priorityFinding([$finding]);
        $this->repository->replaceExtractedFields((int)$job['id'], []);
        $this->repository->replaceFindings((int)$job['id'], (int)$job['application_id'], null, [$finding, $priority], []);
        $this->repository->completeJob((int)$job['id'], [
            'status' => 'requires_review',
            'document_type' => 'unknown_document',
            'classification_confidence' => 0.0,
            'ocr_method' => null,
            'ocr_confidence' => null,
            'text_hash' => null,
            'extracted_text' => null,
            'text_retention_until' => null,
            'model_version' => null,
        ]);
        return ['success' => true, 'job_id' => (int)$job['id'], 'priority' => $priority['severity']];
    }

    /** @return array<int,array<string,mixed>> */
    private function classificationFindings(array $classification): array
    {
        if (!empty($classification['unexpected_type'])) {
            return [$this->finding('document_type_match', 'document_type', 'requires_review', 'medium',
                (string)($classification['expected_type'] ?? ''), (string)$classification['document_type'],
                'The text indicators suggest a different document category than the upload category. Confirm the original document before requesting a replacement.', 'request_clarification')];
        }
        if ((string)$classification['document_type'] === 'unknown_document') {
            return [$this->finding('document_type_classification', 'document_type', 'requires_review', 'medium', null, null,
                'The document type could not be classified with sufficient confidence and needs manual review.', 'continue_review')];
        }
        return [$this->finding('document_type_classification', 'document_type', 'matched', 'low',
            (string)($classification['expected_type'] ?? ''), (string)$classification['document_type'],
            'The extracted text indicators support the identified document category.', 'continue_review')];
    }

    /** @param array<int,array<string,mixed>> $findings @return array<string,mixed> */
    private function priorityFinding(array $findings): array
    {
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        $max = 1;
        foreach ($findings as $finding) {
            $severity = (string)($finding['severity'] ?? 'low');
            $max = max($max, $rank[$severity] ?? 1);
        }
        $priority = array_search($max, $rank, true) ?: 'low';
        $recommendation = match ($priority) {
            'high', 'critical' => 'verify_issuing_authority',
            'medium' => 'request_clarification',
            default => 'continue_review',
        };
        return $this->finding(
            'screening_priority', 'screening_priority',
            $priority === 'low' ? 'matched' : 'requires_review', $priority,
            null, $priority,
            ucfirst($priority) . ' review priority is based on deterministic document-screening findings. It is not a determination of authenticity or an application decision.',
            $recommendation
        );
    }

    /** @return array<string,mixed> */
    private function finding(string $ruleId, string $fieldKey, string $outcome, string $severity, ?string $submitted, ?string $extracted, string $explanation, string $recommendation): array
    {
        return [
            'rule_id' => $ruleId,
            'field_key' => $fieldKey,
            'outcome' => $outcome,
            'severity' => $severity,
            'submitted_value' => $submitted,
            'extracted_value' => $extracted,
            'explanation' => $explanation,
            'recommendation' => $recommendation,
        ];
    }
}
