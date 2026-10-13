<?php
/**
 * Persistence and document-reference boundary for Intelligent Document Screening.
 *
 * The repository never creates, moves, or deletes uploaded files. It resolves
 * only document records already owned by an application or restaurant.
 */
declare(strict_types=1);

final class DocumentScreeningRepository
{
    public const RULESET_VERSION = 'document-screening-v1';

    /** @var mysqli */
    private $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function hasSchema(): bool
    {
        $required = [
            'document_screening_jobs', 'document_extracted_fields',
            'document_validation_findings', 'certifier_recognition_records',
            'document_review_decisions',
        ];
        foreach ($required as $table) {
            if (!$this->hasTable($table)) return false;
        }
        return true;
    }

    public function hasTable(string $table): bool
    {
        $stmt = $this->conn->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $exists = (int)$stmt->get_result()->fetch_row()[0] > 0;
        $stmt->close();
        return $exists;
    }

    /** @return array<string,mixed>|null */
    public function getApplication(int $applicationId): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM business_applications WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $application = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($application === null) return null;

        $application['branches'] = [];
        if ($this->hasTable('business_branches')) {
            $branches = $this->conn->prepare(
                'SELECT * FROM business_branches WHERE application_id = ? ORDER BY is_main DESC, branch_index ASC'
            );
            $branches->bind_param('i', $applicationId);
            $branches->execute();
            $application['branches'] = $branches->get_result()->fetch_all(MYSQLI_ASSOC);
            $branches->close();
        }
        return $application;
    }

    /** @return array<int,array<string,mixed>> */
    public function getApplicationDocuments(int $applicationId): array
    {
        $documents = [];
        $seenPaths = [];

        if ($this->hasTable('branch_documents')) {
            $stmt = $this->conn->prepare(
                'SELECT bd.id, bd.branch_id, bd.application_id, bd.doc_type, bd.original_name,
                        bd.file_path, bd.file_size, bd.mime_type, bd.uploaded_at,
                        bb.branch_index, bb.is_main
                 FROM branch_documents bd
                 LEFT JOIN business_branches bb ON bb.id = bd.branch_id
                 WHERE bd.application_id = ? ORDER BY bd.uploaded_at ASC, bd.id ASC'
            );
            $stmt->bind_param('i', $applicationId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $row['source_type'] = 'branch_document';
                $row['source_document_id'] = (int)$row['id'];
                $documents[] = $row;
                $seenPaths[(string)$row['file_path']] = true;
            }
            $stmt->close();
        }

        if ($this->hasTable('business_application_documents')) {
            $stmt = $this->conn->prepare(
                'SELECT id, application_id, doc_type, original_name, file_path, file_size, mime_type, uploaded_at
                 FROM business_application_documents WHERE application_id = ? ORDER BY uploaded_at ASC, id ASC'
            );
            $stmt->bind_param('i', $applicationId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                if (isset($seenPaths[(string)$row['file_path']])) continue;
                $row['source_type'] = 'business_application_document';
                $row['source_document_id'] = (int)$row['id'];
                $documents[] = $row;
                $seenPaths[(string)$row['file_path']] = true;
            }
            $stmt->close();
        }

        $app = $this->getApplication($applicationId);
        $certificatePath = (string)($app['halal_certificate_path'] ?? '');
        if ($certificatePath !== '' && !isset($seenPaths[$certificatePath])) {
            $documents[] = [
                'id' => $applicationId,
                'source_type' => 'application_certificate',
                'source_document_id' => $applicationId,
                'application_id' => $applicationId,
                'doc_type' => 'halal_certificate',
                'original_name' => 'Uploaded Halal Certificate',
                'file_path' => $certificatePath,
                'file_size' => null,
                'mime_type' => null,
                'uploaded_at' => $app['created_at'] ?? null,
            ];
        }

        return $documents;
    }

    /** @return array<string,mixed>|null */
    public function getApplicationDocument(int $applicationId, string $sourceType, int $sourceDocumentId): ?array
    {
        foreach ($this->getApplicationDocuments($applicationId) as $document) {
            if ((string)$document['source_type'] === $sourceType
                && (int)$document['source_document_id'] === $sourceDocumentId) {
                return $document;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public function getSourceForJob(array $job): ?array
    {
        $applicationId = (int)($job['application_id'] ?? 0);
        if ($applicationId > 0) {
            return $this->getApplicationDocument(
                $applicationId,
                (string)$job['source_type'],
                (int)$job['source_document_id']
            );
        }
        return null;
    }

    public function resolveUploadPath(string $relativePath): ?string
    {
        $relativePath = str_replace('\\', '/', trim($relativePath));
        if ($relativePath === '' || str_contains($relativePath, "\0") || str_starts_with($relativePath, '/')) {
            return null;
        }
        $root = realpath(UPLOAD_DIR);
        $candidate = realpath(UPLOAD_DIR . $relativePath);
        if ($root === false || $candidate === false || !is_file($candidate)) return null;

        $rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $candidatePath = str_replace('\\', '/', $candidate);
        if (!str_starts_with(strtolower($candidatePath), strtolower($rootPrefix))) return null;
        return $candidate;
    }

    public function createJob(int $applicationId, array $document, int $requestedBy): int
    {
        $sourceType = (string)$document['source_type'];
        $sourceId = (int)$document['source_document_id'];
        $stmt = $this->conn->prepare(
            'INSERT INTO document_screening_jobs
                (source_type, source_document_id, application_id, requested_by, status, ruleset_version)
             VALUES (?, ?, ?, ?, "queued", ?)'
        );
        $version = self::RULESET_VERSION;
        $stmt->bind_param('siiis', $sourceType, $sourceId, $applicationId, $requestedBy, $version);
        $stmt->execute();
        $id = (int)$this->conn->insert_id;
        $stmt->close();
        return $id;
    }

    /** @return array<string,mixed>|null */
    public function getJob(int $jobId): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM document_screening_jobs WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $job;
    }

    /** @return array<int,array<string,mixed>> */
    public function claimQueuedJobs(int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $this->conn->begin_transaction();
        try {
            $jobs = $this->conn->query(
                'SELECT * FROM document_screening_jobs WHERE status = "queued" ORDER BY requested_at ASC LIMIT ' . $limit . ' FOR UPDATE'
            )->fetch_all(MYSQLI_ASSOC);
            foreach ($jobs as $job) {
                $id = (int)$job['id'];
                $this->conn->query('UPDATE document_screening_jobs SET status = "processing", started_at = NOW() WHERE id = ' . $id . ' AND status = "queued"');
            }
            $this->conn->commit();
            foreach ($jobs as &$job) $job['status'] = 'processing';
            return $jobs;
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    public function markProcessing(int $jobId): void
    {
        $stmt = $this->conn->prepare(
            'UPDATE document_screening_jobs SET status = "processing", started_at = COALESCE(started_at, NOW()),
             error_code = NULL, error_message = NULL WHERE id = ?'
        );
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $stmt->close();
    }

    /** @param array<string,array<string,mixed>> $fields @return array<string,int> */
    public function replaceExtractedFields(int $jobId, array $fields): array
    {
        $delete = $this->conn->prepare('DELETE FROM document_extracted_fields WHERE job_id = ?');
        $delete->bind_param('i', $jobId);
        $delete->execute();
        $delete->close();

        $ids = [];
        $insert = $this->conn->prepare(
            'INSERT INTO document_extracted_fields
                (job_id, field_key, raw_value, normalized_value, confidence, source_page, source_location, is_uncertain)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($fields as $key => $field) {
            $raw = $field['raw_value'] ?? null;
            $normal = $field['normalized_value'] ?? null;
            $confidence = isset($field['confidence']) ? (float)$field['confidence'] : null;
            $page = isset($field['source_page']) ? (int)$field['source_page'] : null;
            $location = $field['source_location'] ?? null;
            $uncertain = !empty($field['is_uncertain']) ? 1 : 0;
            $insert->bind_param('isssdisi', $jobId, $key, $raw, $normal, $confidence, $page, $location, $uncertain);
            $insert->execute();
            $ids[$key] = (int)$this->conn->insert_id;
        }
        $insert->close();
        return $ids;
    }

    /** @param array<int,array<string,mixed>> $findings @param array<string,int> $fieldIds */
    public function replaceFindings(int $jobId, ?int $applicationId, ?int $restaurantId, array $findings, array $fieldIds): void
    {
        $delete = $this->conn->prepare('DELETE FROM document_validation_findings WHERE job_id = ?');
        $delete->bind_param('i', $jobId);
        $delete->execute();
        $delete->close();

        $insert = $this->conn->prepare(
            'INSERT INTO document_validation_findings
                (job_id, application_id, restaurant_id, extracted_field_id, rule_id, field_key, outcome,
                 severity, submitted_value, extracted_value, explanation, recommendation)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($findings as $finding) {
            $fieldKey = (string)($finding['field_key'] ?? '');
            $fieldId = $fieldIds[$fieldKey] ?? null;
            $rule = (string)$finding['rule_id'];
            $outcome = (string)$finding['outcome'];
            $severity = (string)($finding['severity'] ?? 'low');
            $submitted = $finding['submitted_value'] ?? null;
            $extracted = $finding['extracted_value'] ?? null;
            $explanation = (string)$finding['explanation'];
            $recommendation = (string)($finding['recommendation'] ?? 'continue_review');
            $insert->bind_param(
                'iiiissssssss', $jobId, $applicationId, $restaurantId, $fieldId, $rule, $fieldKey,
                $outcome, $severity, $submitted, $extracted, $explanation, $recommendation
            );
            $insert->execute();
        }
        $insert->close();
    }

    public function completeJob(int $jobId, array $result): void
    {
        $status = (string)($result['status'] ?? 'completed');
        $documentType = $result['document_type'] ?? null;
        $classificationConfidence = isset($result['classification_confidence']) ? (float)$result['classification_confidence'] : null;
        $ocrMethod = $result['ocr_method'] ?? null;
        $ocrConfidence = isset($result['ocr_confidence']) ? (float)$result['ocr_confidence'] : null;
        $textHash = $result['text_hash'] ?? null;
        $text = $result['extracted_text'] ?? null;
        $retention = $result['text_retention_until'] ?? null;
        $modelVersion = $result['model_version'] ?? null;
        $stmt = $this->conn->prepare(
            'UPDATE document_screening_jobs
             SET status = ?, document_type = ?, classification_confidence = ?, ocr_method = ?, ocr_confidence = ?,
                 text_hash = ?, extracted_text = ?, text_retention_until = ?, model_version = ?,
                 error_code = NULL, error_message = NULL, completed_at = NOW()
             WHERE id = ?'
        );
        $stmt->bind_param('ssdsdssssi', $status, $documentType, $classificationConfidence, $ocrMethod, $ocrConfidence,
            $textHash, $text, $retention, $modelVersion, $jobId);
        $stmt->execute();
        $stmt->close();
    }

    public function failJob(int $jobId, string $code, string $message, string $status = 'failed'): void
    {
        $code = substr($code, 0, 64);
        $message = substr($message, 0, 500);
        $stmt = $this->conn->prepare(
            'UPDATE document_screening_jobs SET status = ?, error_code = ?, error_message = ?, completed_at = NOW() WHERE id = ?'
        );
        $stmt->bind_param('sssi', $status, $code, $message, $jobId);
        $stmt->execute();
        $stmt->close();
    }

    /** @return array<string,mixed> */
    public function applicationResults(int $applicationId): array
    {
        $documents = $this->getApplicationDocuments($applicationId);
        $jobs = [];
        $stmt = $this->conn->prepare(
            'SELECT * FROM document_screening_jobs WHERE application_id = ? ORDER BY requested_at DESC, id DESC'
        );
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $row) {
            $key = $row['source_type'] . ':' . $row['source_document_id'];
            if (!isset($jobs[$key])) $jobs[$key] = $row;
        }

        $jobIds = array_values(array_map(static fn(array $job): int => (int)$job['id'], $jobs));
        $fieldsByJob = $this->fieldsByJobs($jobIds);
        $findingsByJob = $this->findingsByJobs($jobIds);
        $processed = 0;
        $requiresReview = 0;
        foreach ($documents as &$document) {
            $key = $document['source_type'] . ':' . $document['source_document_id'];
            $job = $jobs[$key] ?? null;
            $document['screening_job'] = $job;
            $document['fields'] = $job ? ($fieldsByJob[(int)$job['id']] ?? []) : [];
            $document['findings'] = $job ? ($findingsByJob[(int)$job['id']] ?? []) : [];
            if ($job && in_array((string)$job['status'], ['completed', 'requires_review'], true)) $processed++;
            foreach ($document['findings'] as $finding) {
                if ((string)$finding['review_status'] === 'open'
                    && in_array((string)$finding['outcome'], ['mismatch', 'missing_information', 'expired', 'unreadable', 'requires_review'], true)) {
                    $requiresReview++;
                    break;
                }
            }
        }
        unset($document);

        $missing = $this->missingRequiredDocuments($applicationId, $documents);
        return [
            'installed' => true,
            'summary' => [
                'submitted_documents' => count($documents),
                'processed_documents' => $processed,
                'documents_requiring_review' => $requiresReview,
                'missing_required_documents' => count($missing),
                'expired_documents' => $this->countExpired($findingsByJob),
            ],
            'documents' => $documents,
            'missing_required_documents' => $missing,
            'history' => $rows,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function fieldsByJobs(array $jobIds): array
    {
        if ($jobIds === []) return [];
        $ids = implode(',', array_map('intval', $jobIds));
        $rows = $this->conn->query('SELECT * FROM document_extracted_fields WHERE job_id IN (' . $ids . ') ORDER BY id ASC')
            ->fetch_all(MYSQLI_ASSOC);
        $out = [];
        foreach ($rows as $row) $out[(int)$row['job_id']][] = $row;
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function findingsByJobs(array $jobIds): array
    {
        if ($jobIds === []) return [];
        $ids = implode(',', array_map('intval', $jobIds));
        $rows = $this->conn->query(
            'SELECT f.*, d.corrected_value, d.reviewer_comment, d.decision_type, d.created_at AS reviewed_at
             FROM document_validation_findings f
             LEFT JOIN document_review_decisions d ON d.id = (
                 SELECT d2.id FROM document_review_decisions d2 WHERE d2.finding_id = f.id ORDER BY d2.id DESC LIMIT 1
             )
             WHERE f.job_id IN (' . $ids . ') ORDER BY f.id ASC'
        )->fetch_all(MYSQLI_ASSOC);
        $out = [];
        foreach ($rows as $row) $out[(int)$row['job_id']][] = $row;
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function missingRequiredDocuments(int $applicationId, array $documents): array
    {
        $app = $this->getApplication($applicationId);
        if ($app === null) return [];
        $byBranch = [];
        foreach ($documents as $document) {
            $branch = (int)($document['branch_id'] ?? 0);
            $byBranch[$branch][(string)$document['doc_type']] = true;
        }
        $missing = [];
        foreach (($app['branches'] ?? []) as $branch) {
            $required = (string)($branch['cert_status'] ?? '') === 'certified'
                ? ['bir_notice', 'halal_certificate']
                : ['bir_notice', 'business_permit', 'mayors_barangay_permit', 'sanitary_permit', 'lto_permit'];
            foreach ($required as $type) {
                if (empty($byBranch[(int)$branch['id']][$type])) {
                    $missing[] = ['branch_id' => (int)$branch['id'], 'document_type' => $type];
                }
            }
        }
        return $missing;
    }

    /** @param array<int,array<int,array<string,mixed>>> $findingsByJob */
    private function countExpired(array $findingsByJob): int
    {
        $count = 0;
        foreach ($findingsByJob as $findings) {
            foreach ($findings as $finding) {
                if ((string)$finding['outcome'] === 'expired') $count++;
            }
        }
        return $count;
    }

    /** @return array<string,mixed>|null */
    public function getFindingForApplication(int $findingId, int $applicationId): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT f.*, j.source_type, j.source_document_id
             FROM document_validation_findings f
             JOIN document_screening_jobs j ON j.id = f.job_id
             WHERE f.id = ? AND f.application_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $findingId, $applicationId);
        $stmt->execute();
        $finding = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $finding;
    }

    public function recordDecision(int $findingId, int $applicationId, int $reviewerId, string $type, ?string $correctedValue, ?string $comment): bool
    {
        $allowed = ['reviewed', 'confirm_extracted', 'correct_ocr', 'request_clarification'];
        if (!in_array($type, $allowed, true)) return false;
        $correctedValue = $correctedValue === null ? null : mb_substr(trim($correctedValue), 0, 4000, 'UTF-8');
        $comment = $comment === null ? null : mb_substr(trim(strip_tags($comment)), 0, 4000, 'UTF-8');
        $stmt = $this->conn->prepare(
            'INSERT INTO document_review_decisions
                (finding_id, application_id, reviewer_id, decision_type, corrected_value, reviewer_comment)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iiisss', $findingId, $applicationId, $reviewerId, $type, $correctedValue, $comment);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) return false;

        $status = $type === 'request_clarification' ? 'awaiting_applicant' : 'reviewed';
        $update = $this->conn->prepare('UPDATE document_validation_findings SET review_status = ? WHERE id = ?');
        $update->bind_param('si', $status, $findingId);
        $update->execute();
        $update->close();
        return true;
    }

    /** @return array<int,array<string,mixed>> */
    public function recognitionRecords(string $normalizedCertifier): array
    {
        $stmt = $this->conn->prepare(
            'SELECT * FROM certifier_recognition_records WHERE normalized_certifier_name = ? ORDER BY recognition_scheme ASC'
        );
        $stmt->bind_param('s', $normalizedCertifier);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function purgeExpiredText(): int
    {
        $result = $this->conn->query(
            'UPDATE document_screening_jobs SET extracted_text = NULL
             WHERE extracted_text IS NOT NULL AND text_retention_until IS NOT NULL AND text_retention_until < NOW()'
        );
        return $result ? $this->conn->affected_rows : 0;
    }

    /**
     * Controlled HaCeRa interface: returns only finding IDs, rule outcomes,
     * severity and explanations. It excludes OCR text, file paths, source values
     * and applicant personal data.
     *
     * @return array<int,array<string,mixed>>
     */
    public function haceraEvidenceForRestaurant(int $restaurantId): array
    {
        if (!$this->hasSchema()) return [];
        $stmt = $this->conn->prepare(
            'SELECT f.id, f.field_key, f.outcome, f.severity, f.explanation, j.document_type
             FROM halal_restaurants hr
             JOIN business_applications ba ON ba.user_id = hr.business_owner_id
             JOIN document_validation_findings f ON f.application_id = ba.id
             JOIN document_screening_jobs j ON j.id = f.job_id
             WHERE hr.id = ? AND f.review_status IN ("open", "awaiting_applicant")
             ORDER BY ba.created_at DESC, f.id DESC'
        );
        $stmt->bind_param('i', $restaurantId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $evidence = [];
        $seen = [];
        foreach ($rows as $row) {
            $module = $this->haceraModuleForDocument((string)($row['document_type'] ?? ''));
            if ($module === null) continue;
            $key = $module . ':' . $row['id'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $evidence[] = [
                'finding_id' => (int)$row['id'],
                'module_id' => $module,
                'outcome' => (string)$row['outcome'],
                'severity' => (string)$row['severity'],
                'explanation' => (string)$row['explanation'],
                'review_required' => true,
            ];
        }
        return $evidence;
    }

    private function haceraModuleForDocument(string $documentType): ?int
    {
        return match ($documentType) {
            'dti_registration', 'sec_registration', 'bir_registration', 'mayors_permit',
            'business_permit', 'sanitary_permit', 'lto_permit' => 1,
            'halal_certificate', 'raw_material_evidence' => 2,
            'packaging_label' => 4,
            default => null,
        };
    }
}
