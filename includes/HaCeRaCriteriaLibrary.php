<?php
/**
 * Approved, versioned HaCeRa criteria reference library.
 *
 * This deliberately contains only the assessment requirements already used by
 * HaCeRaCalculator.  It is a reference for advisory AI validation, not a
 * certification standard or a source of certification decisions.
 */
declare(strict_types=1);

require_once __DIR__ . '/HaCeRaCalculator.php';

final class HaCeRaCriteriaLibrary
{
    public const VERSION = 'hacera-checklist-2026-10-01';

    /**
     * @return array<int, array{reference:string,module_id:int,checklist_item_id:int,label:string,weight:int,optional:bool}>
     */
    public static function all(): array
    {
        $criteria = [];
        foreach (HaCeRaCalculator::getModules() as $moduleId => $module) {
            foreach ($module['items'] as $item) {
                $itemId = (int)$item['id'];
                $criteria[$itemId] = [
                    'reference' => self::referenceFor($itemId),
                    'module_id' => (int)$moduleId,
                    'checklist_item_id' => $itemId,
                    'label' => (string)$item['label'],
                    'weight' => (int)$item['weight'],
                    'optional' => !empty($item['optional']),
                ];
            }
        }

        return $criteria;
    }

    public static function find(int $checklistItemId): ?array
    {
        $criteria = self::all();
        return $criteria[$checklistItemId] ?? null;
    }

    public static function referenceFor(int $checklistItemId): string
    {
        return 'HACERA-' . $checklistItemId;
    }
}
