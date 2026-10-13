<?php
/** Advisory ingredient-evidence screening; never certifies Halal status. */
declare(strict_types=1);

final class IngredientEvidenceService
{
    /** @var array<string,int> */
    private const RANK = [
        'reviewed' => 0, 'available' => 1, 'pending_review' => 2,
        'requires_clarification' => 3, 'inconclusive' => 4, 'missing' => 5, 'expired' => 6,
    ];

    /** @param array<int,array<string,mixed>> $documents @return array{status:string,findings:array<int,array<string,mixed>>,requires_expert_review:bool} */
    public static function evaluate(array $documents, ?string $supplierReference = null, ?string $today = null): array
    {
        $today = $today ?: date('Y-m-d');
        $findings = [];
        if (trim((string)$supplierReference) === '') {
            $findings[] = self::finding('missing_supplier', 'Supplier information is missing from this ingredient batch.', 'medium', true);
        }
        if ($documents === []) {
            $findings[] = self::finding('missing_evidence', 'No supporting ingredient evidence has been recorded for this batch.', 'medium', true);
            return ['status' => 'missing', 'findings' => $findings, 'requires_expert_review' => true];
        }

        $status = 'available';
        foreach ($documents as $document) {
            $documentStatus = (string)($document['document_status'] ?? 'pending_review');
            $expiry = (string)($document['expiration_date'] ?? '');
            if ($expiry !== '' && $expiry !== '0000-00-00' && $expiry < $today) {
                $documentStatus = 'expired';
                $findings[] = self::finding('expired_evidence', 'A supporting document has a recorded expiration date in the past.', 'high', true);
            }
            if (!isset(self::RANK[$documentStatus])) $documentStatus = 'inconclusive';
            if (self::RANK[$documentStatus] > self::RANK[$status]) $status = $documentStatus;
            if ($documentStatus === 'pending_review') {
                $findings[] = self::finding('pending_review', 'Supporting evidence is available but has not been recorded as reviewed.', 'low', true);
            }
            if (in_array($documentStatus, ['inconclusive', 'requires_clarification'], true)) {
                $findings[] = self::finding('inconclusive_evidence', 'Supporting evidence requires clarification by an authorized reviewer.', 'medium', true);
            }
        }
        return ['status' => $status, 'findings' => $findings,
            'requires_expert_review' => $status !== 'reviewed' || $findings !== []];
    }

    /** @return array<int,array<string,mixed>> */
    public static function haceraFindings(mysqli $conn, int $restaurantId): array
    {
        $sql = "SELECT b.id AS batch_id, ki.ingredient_name, b.evidence_status, b.expiration_date, b.supplier_reference,
                       COUNT(d.id) AS document_count,
                       SUM(CASE WHEN d.expiration_date IS NOT NULL AND d.expiration_date < CURDATE() THEN 1 ELSE 0 END) AS expired_documents
                FROM ingredient_inventory_batches b
                JOIN kitchen_inventory ki ON ki.id = b.inventory_id
                LEFT JOIN ingredient_documents d ON d.batch_id = b.id
                WHERE b.restaurant_id = ? AND b.batch_status = 'active'
                GROUP BY b.id, ki.ingredient_name, b.evidence_status, b.expiration_date, b.supplier_reference";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('i', $restaurantId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $findings = [];
        foreach ($rows as $row) {
            $status = (string)$row['evidence_status'];
            $expiredBatch = !empty($row['expiration_date']) && $row['expiration_date'] < date('Y-m-d');
            if ($status === 'reviewed' && !$expiredBatch && (int)$row['expired_documents'] === 0) continue;
            $type = $expiredBatch || (int)$row['expired_documents'] > 0 ? 'expired_supporting_evidence'
                : ($status === 'missing' || (int)$row['document_count'] === 0 ? 'missing_supplier_documentation' : 'ingredient_evidence_review_required');
            $findings[] = [
                'source' => 'ingredient_traceability', 'batch_id' => (int)$row['batch_id'],
                'ingredient_name' => (string)$row['ingredient_name'], 'finding_type' => $type,
                'evidence_status' => $status, 'requires_expert_review' => true,
            ];
        }
        return $findings;
    }

    /** @return array<string,mixed> */
    private static function finding(string $type, string $message, string $severity, bool $expert): array
    {
        return ['type' => $type, 'message' => $message, 'severity' => $severity, 'requires_expert_review' => $expert];
    }
}
