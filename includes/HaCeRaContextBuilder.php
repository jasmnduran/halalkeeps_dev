<?php
/**
 * Builds the minimum, read-only data set used for HaCeRa AI advisory analysis.
 *
 * Customer, payment, financial, address and account-profile data are never
 * included. Uploaded files are represented only by records owned by the
 * restaurant; their content is deliberately not extracted or sent to an AI.
 */
declare(strict_types=1);

require_once __DIR__ . '/HaCeRaCalculator.php';
require_once __DIR__ . '/HaCeRaCriteriaLibrary.php';
require_once __DIR__ . '/DocumentScreeningRepository.php';
require_once __DIR__ . '/DocumentScreeningHaCeRaBridge.php';
require_once __DIR__ . '/IngredientInventoryService.php';

final class HaCeRaContextBuilder
{
    public function __construct(private mysqli $conn)
    {
    }

    /** @return array<string, mixed> */
    public function build(int $restaurantId): array
    {
        $restaurant = $this->loadRestaurant($restaurantId);
        if ($restaurant === null) {
            throw new RuntimeException('Restaurant was not found.');
        }

        $responses = $this->loadResponses($restaurantId);
        $evidence  = $this->loadEvidence($restaurantId);
        // Screening is advisory only. The bridge exposes no file paths, OCR
        // text, extracted personal data, HaCeRa response changes, or scores.
        $screeningEvidence = [];
        try {
            $screeningEvidence = (new DocumentScreeningHaCeRaBridge($this->conn))->findingsForRestaurant($restaurantId);
        } catch (Throwable $e) {
            error_log('Document-screening HaCeRa bridge unavailable: ' . $e->getMessage());
        }
        $ingredientTraceabilityFindings = [];
        try {
            $ingredientTraceabilityFindings = (new IngredientInventoryService($this->conn))->traceabilityFindings($restaurantId);
        } catch (Throwable $e) {
            error_log('Ingredient traceability HaCeRa bridge unavailable: ' . $e->getMessage());
        }
        $modules   = HaCeRaCalculator::getModules();
        $criteria  = HaCeRaCriteriaLibrary::all();

        $documentItemIds = array_keys($evidence['by_item']);
        $responseRows = [];
        foreach ($responses as $itemId => $response) {
            $responseRows[] = [
                'checklist_item_id' => $itemId,
                'response_value' => $response['response_value'],
            ];
        }
        $completedIds = HaCeRaCalculator::buildCompletedIds($responseRows, $documentItemIds);

        $moduleScores = [];
        $contextModules = [];
        foreach ($modules as $moduleId => $module) {
            $moduleScores[$moduleId] = HaCeRaCalculator::scoreModule((int)$moduleId, $completedIds);
            $items = [];
            foreach ($module['items'] as $item) {
                $itemId = (int)$item['id'];
                $items[] = [
                    'checklist_item_id' => $itemId,
                    'criteria_reference' => $criteria[$itemId]['reference'],
                    'label' => $item['label'],
                    'question' => $item['question'] ?? $item['label'],
                    'weight' => (int)$item['weight'],
                    'optional' => !empty($item['optional']),
                    'response' => $responses[$itemId]['response_value'] ?? 'no',
                    // Missing responses are scored exactly as the existing
                    // calculator scores them; this flag lets advisory output
                    // distinguish missing information from an explicit No.
                    'response_recorded' => array_key_exists($itemId, $responses),
                    // IDs are safe references; file names, paths, notes and file
                    // contents are intentionally excluded from external context.
                    'evidence_document_ids' => $evidence['by_item'][$itemId] ?? [],
                    'evidence_count' => count($evidence['by_item'][$itemId] ?? []),
                ];
            }
            $contextModules[] = [
                'module_id' => (int)$moduleId,
                'title' => $module['title'],
                'score' => $moduleScores[$moduleId]['score'],
                'items' => $items,
            ];
        }

        $hrp = HaCeRaCalculator::computeHRP($moduleScores);
        $band = HaCeRaCalculator::getBand($hrp);

        return [
            'schema_version' => 1,
            'criteria_version' => HaCeRaCriteriaLibrary::VERSION,
            'restaurant_id' => $restaurantId,
            'readiness' => [
                // This is a read-only calculation using the existing scoring
                // engine. It never writes to halal_restaurants or publishes.
                'current_hrp' => $hrp,
                'current_band' => $band['label'],
                'published_hrp' => $restaurant['hacera_hrp'] === null ? null : (float)$restaurant['hacera_hrp'],
                'published_band' => $restaurant['hacera_band'],
                'published_at' => $restaurant['hacera_published_at'],
            ],
            'modules' => $contextModules,
            'approved_criteria' => array_values($criteria),
            'evidence_document_ids' => $evidence['all_ids'],
            'document_screening_evidence' => $screeningEvidence,
            // Advisory traceability context only; it does not modify responses,
            // criteria, scores, readiness classifications, or decisions.
            'ingredient_traceability_findings' => $ingredientTraceabilityFindings,
        ];
    }

    /** @param array<string, mixed> $context */
    public function hash(array $context): string
    {
        return hash('sha256', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed>|null */
    private function loadRestaurant(int $restaurantId): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT id, hacera_hrp, hacera_band, hacera_published_at
             FROM halal_restaurants WHERE id = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->bind_param('i', $restaurantId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    /** @return array<int, array{response_value:string}> */
    private function loadResponses(int $restaurantId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT checklist_item_id, response_value
             FROM business_hacera_responses WHERE restaurant_id = ?'
        );
        $stmt->bind_param('i', $restaurantId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $mapped = [];
        foreach ($rows as $row) {
            $mapped[(int)$row['checklist_item_id']] = ['response_value' => (string)$row['response_value']];
        }
        return $mapped;
    }

    /** @return array{by_item:array<int, array<int>>, all_ids:array<int>} */
    private function loadEvidence(int $restaurantId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT id, checklist_item_id FROM hacera_documents WHERE restaurant_id = ? ORDER BY id ASC'
        );
        $stmt->bind_param('i', $restaurantId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $byItem = [];
        $allIds = [];
        foreach ($rows as $row) {
            $itemId = (int)$row['checklist_item_id'];
            $documentId = (int)$row['id'];
            $byItem[$itemId][] = $documentId;
            $allIds[] = $documentId;
        }

        return ['by_item' => $byItem, 'all_ids' => $allIds];
    }
}
