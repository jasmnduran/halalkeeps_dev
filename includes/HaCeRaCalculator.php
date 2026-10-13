<?php
/**
 * HaCeRaCalculator — Weighted Halal Readiness Assessment Scoring Engine
 *
 * Scoring formula per module:
 *   Section Readiness (%) = (Σ weights of completed items / Σ total possible weights) × 100
 *
 * Halal Readiness Progress (HRP):
 *   HRP = mean of all module section readiness scores
 *
 * Weight tiers:
 *   W=3  High priority  — halal ingredient sources, zero cross-contamination, Shari'ah labelling
 *   W=2  Standard       — business licences, pantry, record-keeping
 *   W=1  Informational  — optional / supplementary items
 */
class HaCeRaCalculator
{
    // ── Static module definitions ────────────────────────────────────────────
    // Each module holds an ordered list of checklist items.
    // 'weight' : 3 = High, 2 = Standard, 1 = Informational/optional
    // 'type'   : 'upload' = requires a document, 'yesno' = boolean check
    // 'optional': true = gap shown as advisory, not blocking

    public static function getModules(): array
    {
        return [
            1 => [
                'title'       => 'Conditions of Certification',
                'icon'        => 'fas fa-file-certificate',
                'description' => 'Business registration, government permits, and legal standing documents.',
                'color'       => '#0284c7',
                'items'       => [
                    ['id' => 101, 'label' => 'DTI / SEC / CDA Business Registration',           'type' => 'upload', 'weight' => 2, 'question' => 'Do you have a DTI / SEC / CDA Business Registration?'],
                    ['id' => 102, 'label' => 'Mayor\'s Business Permit (LGU)',                   'type' => 'upload', 'weight' => 2, 'question' => 'Do you have a Mayor\'s Business Permit from your LGU?'],
                    ['id' => 103, 'label' => 'Barangay Clearance / Permit',                      'type' => 'upload', 'weight' => 2, 'question' => 'Do you have a Barangay Clearance or Permit?'],
                    ['id' => 104, 'label' => 'Sanitary Permit',                                  'type' => 'upload', 'weight' => 2, 'question' => 'Do you have a valid Sanitary Permit?'],
                    ['id' => 105, 'label' => 'Fire Safety Inspection Certificate',               'type' => 'upload', 'weight' => 2, 'question' => 'Do you have a Fire Safety Inspection Certificate?'],
                    ['id' => 106, 'label' => 'BIR Certificate of Registration',                  'type' => 'upload', 'weight' => 2, 'question' => 'Do you have a BIR Certificate of Registration?'],
                    ['id' => 107, 'label' => 'FDA License to Operate (if applicable)',            'type' => 'upload', 'weight' => 1, 'optional' => true, 'question' => 'Do you have an FDA License to Operate (if applicable)?'],
                    ['id' => 108, 'label' => 'DENR / Environmental Compliance Certificate',      'type' => 'upload', 'weight' => 1, 'optional' => true, 'question' => 'Do you have a DENR or Environmental Compliance Certificate?'],
                ],
            ],
            2 => [
                'title'       => 'Raw Materials & Ingredients',
                'icon'        => 'fas fa-leaf',
                'description' => 'Itemised ledger and halal certificates for animal-based or imported ingredients.',
                'color'       => '#16a34a',
                'items'       => [
                    ['id' => 201, 'label' => 'Full Ingredients / Raw Materials List with Sources',  'type' => 'upload', 'weight' => 3, 'question' => 'Do you have a full Ingredients / Raw Materials List with Sources?'],
                    ['id' => 202, 'label' => 'Halal Certificates for ALL Meat & Poultry Products',  'type' => 'upload', 'weight' => 3, 'question' => 'Do you have Halal Certificates for all Meat and Poultry products?'],
                    ['id' => 203, 'label' => 'Halal Certificates for Imported Ingredients',         'type' => 'upload', 'weight' => 3, 'question' => 'Do you have Halal Certificates for all Imported Ingredients?'],
                    ['id' => 204, 'label' => 'Raw Materials / Ingredients Traceability Matrix',     'type' => 'upload', 'weight' => 3, 'question' => 'Do you have a Raw Materials / Ingredients Traceability Matrix?'],
                    ['id' => 205, 'label' => 'No Alcohol / Intoxicants Used in Recipes',            'type' => 'yesno',  'weight' => 3],
                    ['id' => 206, 'label' => 'No Cross-Contamination with Non-Halal Sources',       'type' => 'yesno',  'weight' => 3],
                    ['id' => 207, 'label' => 'Packaging Materials Halal Certificates (if needed)',  'type' => 'upload', 'weight' => 2, 'question' => 'Do you have Halal Certificates for Packaging Materials (if needed)?'],
                    ['id' => 208, 'label' => 'Supplier Halal Verification Records',                 'type' => 'upload', 'weight' => 2, 'question' => 'Do you have Supplier Halal Verification Records?'],
                ],
            ],
            3 => [
                'title'       => 'Processing & Logistics',
                'icon'        => 'fas fa-industry',
                'description' => 'Shari\'ah-compliant production workflow, dedicated transport, and cross-contamination controls.',
                'color'       => '#7c3aed',
                'items'       => [
                    ['id' => 301, 'label' => 'Dedicated Halal Transport (no shared haram loads)',    'type' => 'yesno',  'weight' => 3],
                    ['id' => 302, 'label' => 'Processing Tools Free from Animal Hair / Impurities', 'type' => 'yesno',  'weight' => 3],
                    ['id' => 303, 'label' => 'Alcohol and Liquor Prohibition in Kitchen',            'type' => 'yesno',  'weight' => 3],
                    ['id' => 304, 'label' => 'Flow Chart of Product Processing',                    'type' => 'upload', 'weight' => 2, 'question' => 'Do you have a Flow Chart of your Product Processing?'],
                    ['id' => 305, 'label' => 'Waste Disposal Management Plan',                      'type' => 'upload', 'weight' => 2, 'question' => 'Do you have a Waste Disposal Management Plan?'],
                    ['id' => 306, 'label' => 'Pest Control Programme Documentation',                'type' => 'upload', 'weight' => 2, 'question' => 'Do you have Pest Control Programme Documentation?'],
                    ['id' => 307, 'label' => 'GMP / HACCP / SSOP / ISO Certificates',              'type' => 'upload', 'weight' => 1, 'optional' => true, 'question' => 'Do you have GMP / HACCP / SSOP or ISO Certificates?'],
                    ['id' => 308, 'label' => 'Warehouse Halal Storage System Description',          'type' => 'upload', 'weight' => 2, 'question' => 'Do you have a Warehouse Halal Storage System Description?'],
                    ['id' => 309, 'label' => 'Cold-Chain Integrity for Halal Meat',                 'type' => 'yesno',  'weight' => 2],
                ],
            ],
            4 => [
                'title'       => 'Packaging & Labelling',
                'icon'        => 'fas fa-tag',
                'description' => 'Ten mandatory label verification points per HDIP Shari\'ah standards.',
                'color'       => '#d97706',
                'items'       => [
                    ['id' => 401, 'label' => 'Halal Logo/Mark Displayed on Packaging',              'type' => 'yesno',  'weight' => 3],
                    ['id' => 402, 'label' => 'Product Name in Arabic and English',                  'type' => 'yesno',  'weight' => 3],
                    ['id' => 403, 'label' => 'Complete Ingredient List on Label',                   'type' => 'yesno',  'weight' => 3],
                    ['id' => 404, 'label' => 'Net Weight / Volume Declared',                        'type' => 'yesno',  'weight' => 2],
                    ['id' => 405, 'label' => 'Manufacturer / Distributor Name & Address',           'type' => 'yesno',  'weight' => 2],
                    ['id' => 406, 'label' => 'Country of Origin Declared',                          'type' => 'yesno',  'weight' => 2],
                    ['id' => 407, 'label' => 'Production / Expiry Dates Shown',                     'type' => 'yesno',  'weight' => 2],
                    ['id' => 408, 'label' => 'Allergen Declarations Present',                       'type' => 'yesno',  'weight' => 2],
                    ['id' => 409, 'label' => 'Lot / Batch Number for Traceability',                 'type' => 'yesno',  'weight' => 2],
                    ['id' => 410, 'label' => 'Label Artwork / Packaging Samples Submitted',         'type' => 'upload', 'weight' => 2, 'question' => 'Have you submitted Label Artwork or Packaging Samples?'],
                ],
            ],
            5 => [
                'title'       => 'Premises & Welfare',
                'icon'        => 'fas fa-mosque',
                'description' => 'Fenced premises, ablution & prayer facilities, Muslim crew requirements.',
                'color'       => '#db2777',
                'items'       => [
                    ['id' => 501, 'label' => 'Premises Fenced / Secured from External Contamination', 'type' => 'yesno',  'weight' => 2],
                    ['id' => 502, 'label' => 'Dedicated Ablution (Wudhu) Facilities',                  'type' => 'yesno',  'weight' => 3],
                    ['id' => 503, 'label' => 'Prayer Room / Musalla Available for Staff',               'type' => 'yesno',  'weight' => 3],
                    ['id' => 504, 'label' => 'Separate Changing Rooms (Male / Female)',                 'type' => 'yesno',  'weight' => 2],
                    ['id' => 505, 'label' => 'At Least 2 Muslim Cooks Employed',                       'type' => 'yesno',  'weight' => 3],
                    ['id' => 506, 'label' => 'At Least 2 Muslim Crew Members Employed',                'type' => 'yesno',  'weight' => 3],
                    ['id' => 507, 'label' => 'Kitchen Layout Plan Submitted',                          'type' => 'upload', 'weight' => 2, 'question' => 'Have you submitted a Kitchen Layout Plan?'],
                    ['id' => 508, 'label' => 'Halal Assurance System (HAS) Manual',                   'type' => 'upload', 'weight' => 2, 'question' => 'Do you have a Halal Assurance System (HAS) Manual?'],
                    ['id' => 509, 'label' => 'Proof of Dedicated Halal Prayer Room',                  'type' => 'upload', 'weight' => 1, 'optional' => true, 'question' => 'Do you have Proof of a Dedicated Halal Prayer Room?'],
                ],
            ],
        ];
    }

    // ── Per-module scoring ───────────────────────────────────────────────────

    /**
     * Score a single module given an array of completed item IDs.
     *
     * @param int   $module_id
     * @param int[] $completed_ids  item IDs that are done (upload present OR yesno = yes)
     * @return array{
     *   score: float,            section readiness 0–100
     *   earned_weight: int,
     *   total_weight: int,
     *   completed: int,
     *   total: int,
     *   gaps: array             items not yet completed
     * }
     */
    public static function scoreModule(int $module_id, array $completed_ids): array
    {
        $modules = self::getModules();
        if (!isset($modules[$module_id])) {
            return ['score' => 0, 'earned_weight' => 0, 'total_weight' => 0,
                    'completed' => 0, 'total' => 0, 'gaps' => []];
        }

        $items         = $modules[$module_id]['items'];
        $total_weight  = array_sum(array_column($items, 'weight'));
        $earned_weight = 0;
        $gaps          = [];
        $completed_cnt = 0;

        foreach ($items as $item) {
            if (in_array($item['id'], $completed_ids, true)) {
                $earned_weight += $item['weight'];
                $completed_cnt++;
            } else {
                $gaps[] = $item;
            }
        }

        $score = $total_weight > 0
            ? round(($earned_weight / $total_weight) * 100, 1)
            : 0.0;

        return [
            'score'         => $score,
            'earned_weight' => $earned_weight,
            'total_weight'  => $total_weight,
            'completed'     => $completed_cnt,
            'total'         => count($items),
            'gaps'          => $gaps,
        ];
    }

    // ── Aggregate HRP ────────────────────────────────────────────────────────

    /**
     * Compute the overall Halal Readiness Progress (HRP) as the arithmetic
     * mean of all five module scores.
     *
     * @param array $module_scores  Map of module_id => scoreModule() result
     * @return float HRP 0–100
     */
    public static function computeHRP(array $module_scores): float
    {
        if (empty($module_scores)) return 0.0;
        $total = array_sum(array_column($module_scores, 'score'));
        return round($total / count($module_scores), 1);
    }

    // ── Readiness band ───────────────────────────────────────────────────────

    /**
     * Map a score to a human-readable readiness band with color.
     */
    public static function getBand(float $score): array
    {
        return match(true) {
            $score >= 90 => ['label' => 'Inspection Ready',  'color' => '#16a34a', 'bg' => '#dcfce7', 'icon' => 'fas fa-circle-check'],
            $score >= 70 => ['label' => 'Near Ready',        'color' => '#2563eb', 'bg' => '#dbeafe', 'icon' => 'fas fa-circle-half-stroke'],
            $score >= 50 => ['label' => 'In Progress',       'color' => '#d97706', 'bg' => '#fef3c7', 'icon' => 'fas fa-circle-dot'],
            $score >= 25 => ['label' => 'Early Stage',       'color' => '#ea580c', 'bg' => '#ffedd5', 'icon' => 'fas fa-circle-exclamation'],
            default      => ['label' => 'Not Started',       'color' => '#dc2626', 'bg' => '#fee2e2', 'icon' => 'fas fa-circle-xmark'],
        };
    }

    // ── Build completed IDs from DB responses ────────────────────────────────

    /**
     * Given flat DB rows from business_hacera_responses + hacera_documents,
     * return array of item IDs considered complete.
     *
     * Rules (updated for yes/no + optional upload system):
     *  - All items: complete when response_value = 'yes'
     *  - Document uploads are optional and don't affect completion status
     *
     * @param array $responses  rows from business_hacera_responses
     * @param array $doc_ids    checklist_item_ids that have uploaded docs (kept for reference)
     * @return int[]
     */
    public static function buildCompletedIds(array $responses, array $doc_ids): array
    {
        $completed = [];

        // All items are complete when response_value = 'yes'
        foreach ($responses as $row) {
            if ($row['response_value'] === 'yes') {
                $completed[] = (int)$row['checklist_item_id'];
            }
        }

        return array_unique($completed);
    }

    // ── Priority sort for gap list ───────────────────────────────────────────

    /**
     * Sort gaps descending by weight so the highest-impact missing items
     * appear first in the Gap Analysis Engine.
     */
    public static function sortGapsByPriority(array $gaps): array
    {
        usort($gaps, fn($a, $b) => $b['weight'] <=> $a['weight']);
        return $gaps;
    }
}
