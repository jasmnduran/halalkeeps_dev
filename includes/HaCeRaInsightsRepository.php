<?php
/** Persistence layer for isolated HaCeRa AI advisory records. */
declare(strict_types=1);

require_once __DIR__ . '/HaCeRaAIService.php';

final class HaCeRaInsightsRepository
{
    public function __construct(private mysqli $conn)
    {
    }

    public function hasSchema(): bool
    {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hacera_ai_runs'"
        );
        $stmt->execute();
        $exists = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) === 1;
        $stmt->close();
        return $exists;
    }

    public function findReusableRun(int $restaurantId, string $inputHash): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT id, status, requested_at, completed_at FROM hacera_ai_runs
             WHERE restaurant_id = ? AND input_hash = ? AND status IN ('queued','processing','completed')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('is', $restaurantId, $inputHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function countRecentRuns(int $restaurantId, int $hours = 24): int
    {
        $hours = max(1, min(168, $hours));
        $sql = "SELECT COUNT(*) AS c FROM hacera_ai_runs WHERE restaurant_id = ?
                AND requested_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $restaurantId);
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        return $count;
    }

    public function createRun(int $restaurantId, int $userId, string $inputHash, string $provider, string $model, string $criteriaVersion): int
    {
        $status = 'queued';
        $promptVersion = HaCeRaAIService::PROMPT_VERSION;
        $stmt = $this->conn->prepare(
            'INSERT INTO hacera_ai_runs
             (restaurant_id, requested_by, status, input_hash, provider, model, prompt_version, criteria_version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iissssss', $restaurantId, $userId, $status, $inputHash, $provider, $model, $promptVersion, $criteriaVersion);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Could not create the AI analysis request: ' . $message);
        }
        $id = (int)$this->conn->insert_id;
        $stmt->close();
        return $id;
    }

    public function markProcessing(int $runId): bool
    {
        $status = 'processing';
        $stmt = $this->conn->prepare(
            "UPDATE hacera_ai_runs SET status = ?, started_at = NOW(), error_code = NULL, error_message = NULL
             WHERE id = ? AND status = 'queued'"
        );
        $stmt->bind_param('si', $status, $runId);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();
        return $changed;
    }

    public function markExpired(int $runId, string $reason): void
    {
        $status = 'expired';
        $reason = mb_substr($reason, 0, 255, 'UTF-8');
        $stmt = $this->conn->prepare(
            "UPDATE hacera_ai_runs SET status = ?, completed_at = NOW(), error_code = 'assessment_changed', error_message = ? WHERE id = ?"
        );
        $stmt->bind_param('ssi', $status, $reason, $runId);
        $stmt->execute();
        $stmt->close();
    }

    public function markFailed(int $runId, string $code, string $message): void
    {
        $status = 'failed';
        $code = mb_substr(preg_replace('/[^a-z0-9_]/i', '_', $code) ?? 'provider_error', 0, 64, 'UTF-8');
        $message = mb_substr(strip_tags($message), 0, 500, 'UTF-8');
        $stmt = $this->conn->prepare(
            'UPDATE hacera_ai_runs SET status = ?, completed_at = NOW(), error_code = ?, error_message = ? WHERE id = ?'
        );
        $stmt->bind_param('sssi', $status, $code, $message, $runId);
        $stmt->execute();
        $stmt->close();
    }

    /** @param array<string,mixed> $validated */
    public function storeValidatedReport(int $runId, int $restaurantId, array $validated): void
    {
        $reportJson = json_encode($validated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $summary = (string)$validated['summary'];
        $findingCount = count($validated['findings'] ?? []);
        $rejectedCount = (int)($validated['rejected_count'] ?? 0);
        $status = 'completed';

        $this->conn->begin_transaction();
        try {
            $update = $this->conn->prepare(
                'UPDATE hacera_ai_runs SET status = ?, summary = ?, report_json = ?, validated_finding_count = ?, rejected_finding_count = ?, completed_at = NOW() WHERE id = ? AND restaurant_id = ?'
            );
            $update->bind_param('sssiiii', $status, $summary, $reportJson, $findingCount, $rejectedCount, $runId, $restaurantId);
            $update->execute();
            if ($update->affected_rows !== 1) {
                throw new RuntimeException('AI analysis request no longer exists.');
            }
            $update->close();

            foreach (($validated['findings'] ?? []) as $finding) {
                $this->insertFinding($runId, $restaurantId, $finding);
            }
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    /** @param array<string,mixed> $finding */
    private function insertFinding(int $runId, int $restaurantId, array $finding): void
    {
        $moduleId = (int)$finding['module_id'];
        $itemId = (int)$finding['checklist_item_id'];
        $title = (string)$finding['title'];
        $type = (string)$finding['finding_type'];
        $explanation = (string)$finding['explanation'];
        $severity = (string)$finding['severity'];
        $criteria = (string)$finding['criteria_reference'];
        $recommendation = (string)$finding['recommended_corrective_action'];
        $evidenceStatus = (string)$finding['evidence_status'];
        $requiresReview = !empty($finding['requires_expert_review']) ? 1 : 0;
        $validationStatus = 'validated';
        $stmt = $this->conn->prepare(
            'INSERT INTO hacera_ai_findings
             (run_id, restaurant_id, module_id, checklist_item_id, title, finding_type, explanation, severity, criteria_reference, recommended_corrective_action, evidence_status, requires_expert_review, validation_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iiiisssssssis', $runId, $restaurantId, $moduleId, $itemId, $title, $type, $explanation, $severity, $criteria, $recommendation, $evidenceStatus, $requiresReview, $validationStatus);
        $stmt->execute();
        $findingId = (int)$this->conn->insert_id;
        $stmt->close();

        foreach (($finding['evidence_document_ids'] ?? []) as $documentId) {
            $documentId = (int)$documentId;
            $link = $this->conn->prepare(
                'INSERT INTO hacera_ai_evidence_links (finding_id, restaurant_id, hacera_document_id) VALUES (?, ?, ?)'
            );
            $link->bind_param('iii', $findingId, $restaurantId, $documentId);
            $link->execute();
            $link->close();
        }

        $priority = match ($severity) { 'critical' => 'critical', 'high' => 'high', 'medium' => 'medium', default => 'low' };
        $actionStatus = $requiresReview ? 'needs_expert_review' : 'not_started';
        $action = $this->conn->prepare(
            'INSERT INTO hacera_ai_actions (run_id, finding_id, restaurant_id, title, description, priority, action_status, requires_expert_review)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $action->bind_param('iiissssi', $runId, $findingId, $restaurantId, $title, $recommendation, $priority, $actionStatus, $requiresReview);
        $action->execute();
        $action->close();
    }

    /** @return array<string,mixed>|null */
    public function getRun(int $runId, int $restaurantId): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM hacera_ai_runs WHERE id = ? AND restaurant_id = ? LIMIT 1');
        $stmt->bind_param('ii', $runId, $restaurantId);
        $stmt->execute();
        $run = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($run === null) return null;
        $run['findings'] = $this->findingsForRun($runId, $restaurantId);
        $run['actions'] = $this->actionsForRun($runId, $restaurantId);
        return $run;
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $restaurantId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->conn->prepare(
            "SELECT r.id, r.status, r.input_hash, r.summary, r.validated_finding_count, r.rejected_finding_count, r.requested_at, r.completed_at, r.criteria_version,
                    SUM(CASE WHEN f.requires_expert_review = 1 THEN 1 ELSE 0 END) AS expert_review_count
             FROM hacera_ai_runs r LEFT JOIN hacera_ai_findings f ON f.run_id = r.id
             WHERE r.restaurant_id = ? GROUP BY r.id ORDER BY r.id DESC LIMIT {$limit}"
        );
        $stmt->bind_param('i', $restaurantId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function claimNextQueued(): ?array
    {
        $row = $this->conn->query("SELECT id, restaurant_id FROM hacera_ai_runs WHERE status = 'queued' ORDER BY id ASC LIMIT 1")->fetch_assoc();
        if (!$row || !$this->markProcessing((int)$row['id'])) return null;
        return ['id' => (int)$row['id'], 'restaurant_id' => (int)$row['restaurant_id']];
    }

    public function updateAction(int $actionId, int $restaurantId, int $userId, string $status): bool
    {
        $allowed = ['not_started', 'in_progress', 'resolved_by_owner', 'needs_expert_review'];
        if (!in_array($status, $allowed, true)) return false;
        $stmt = $this->conn->prepare(
            'UPDATE hacera_ai_actions SET action_status = ?, status_updated_by = ?, status_updated_at = NOW()
             WHERE id = ? AND restaurant_id = ?'
        );
        $stmt->bind_param('siii', $status, $userId, $actionId, $restaurantId);
        $stmt->execute();
        $updated = $stmt->affected_rows === 1;
        $stmt->close();
        return $updated;
    }

    public function submitFeedback(int $runId, int $restaurantId, int $userId, int $rating, string $comment): bool
    {
        $rating = max(1, min(5, $rating));
        $comment = mb_substr(strip_tags($comment), 0, 1000, 'UTF-8');
        $stmt = $this->conn->prepare(
            'INSERT INTO hacera_ai_feedback (run_id, restaurant_id, user_id, rating, comment) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), updated_at = NOW()'
        );
        $stmt->bind_param('iiiis', $runId, $restaurantId, $userId, $rating, $comment);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /** @return array<int,array<string,mixed>> */
    private function findingsForRun(int $runId, int $restaurantId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT f.*, GROUP_CONCAT(el.hacera_document_id ORDER BY el.hacera_document_id) AS evidence_ids
             FROM hacera_ai_findings f LEFT JOIN hacera_ai_evidence_links el ON el.finding_id = f.id
             WHERE f.run_id = ? AND f.restaurant_id = ? GROUP BY f.id ORDER BY FIELD(f.severity, "critical", "high", "medium", "low"), f.id'
        );
        $stmt->bind_param('ii', $runId, $restaurantId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as &$row) {
            $row['evidence_document_ids'] = $row['evidence_ids'] === null || $row['evidence_ids'] === '' ? [] : array_map('intval', explode(',', $row['evidence_ids']));
            unset($row['evidence_ids']);
        }
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private function actionsForRun(int $runId, int $restaurantId): array
    {
        $stmt = $this->conn->prepare('SELECT * FROM hacera_ai_actions WHERE run_id = ? AND restaurant_id = ? ORDER BY FIELD(priority, "critical", "high", "medium", "low"), id');
        $stmt->bind_param('ii', $runId, $restaurantId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}
