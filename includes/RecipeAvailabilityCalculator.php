<?php
/**
 * Deterministic unit conversion and recipe-availability calculator.
 *
 * Quantities use fixed three-decimal scaled integers internally. This avoids
 * binary floating-point arithmetic for stock and portion decisions. It has no
 * database or AI dependency and is intentionally safe to use with AI disabled.
 */
declare(strict_types=1);

final class RecipeAvailabilityCalculator
{
    private const SCALE = 1000;

    /** @var array<string,array{dimension:string,numerator:int,denominator:int,label:string}> */
    private const UNITS = [
        'kg'       => ['dimension' => 'mass',   'numerator' => 1000, 'denominator' => 1,    'label' => 'kg'],
        'g'        => ['dimension' => 'mass',   'numerator' => 1,    'denominator' => 1,    'label' => 'g'],
        'mg'       => ['dimension' => 'mass',   'numerator' => 1,    'denominator' => 1000, 'label' => 'mg'],
        'l'        => ['dimension' => 'volume', 'numerator' => 1000, 'denominator' => 1,    'label' => 'L'],
        'ml'       => ['dimension' => 'volume', 'numerator' => 1,    'denominator' => 1,    'label' => 'mL'],
        'each'     => ['dimension' => 'count',  'numerator' => 1,    'denominator' => 1,    'label' => 'each'],
        'piece'    => ['dimension' => 'count',  'numerator' => 1,    'denominator' => 1,    'label' => 'piece'],
        'bunch'    => ['dimension' => 'count',  'numerator' => 1,    'denominator' => 1,    'label' => 'bunch'],
    ];

    /** @var array<string,string> */
    private const UNIT_ALIASES = [
        'kgs' => 'kg', 'kilogram' => 'kg', 'kilograms' => 'kg',
        'gram' => 'g', 'grams' => 'g', 'grams.' => 'g',
        'milligram' => 'mg', 'milligrams' => 'mg',
        'lt' => 'l', 'ltr' => 'l', 'liter' => 'l', 'liters' => 'l', 'litre' => 'l', 'litres' => 'l',
        'milliliter' => 'ml', 'milliliters' => 'ml', 'millilitre' => 'ml', 'millilitres' => 'ml',
        'pc' => 'piece', 'pcs' => 'piece', 'pieces' => 'piece', 'unit' => 'each', 'units' => 'each',
    ];

    public static function normalizeUnit(string $unit): ?string
    {
        $normal = strtolower(trim($unit));
        $normal = preg_replace('/\s+/', '', $normal) ?? '';
        $normal = self::UNIT_ALIASES[$normal] ?? $normal;
        return isset(self::UNITS[$normal]) ? $normal : null;
    }

    public static function areCompatible(string $fromUnit, string $toUnit): bool
    {
        $from = self::normalizeUnit($fromUnit);
        $to = self::normalizeUnit($toUnit);
        return $from !== null && $to !== null && self::UNITS[$from]['dimension'] === self::UNITS[$to]['dimension'];
    }

    /**
     * Converts a non-negative decimal string/number to a fixed three-decimal
     * integer. Inputs with more precision, negative values, or unsafe ranges
     * are rejected instead of silently rounded.
     */
    public static function quantityToScaled($quantity): ?int
    {
        $raw = trim((string)$quantity);
        if (!preg_match('/^(?:0|[1-9]\d{0,8})(?:\.(\d{1,3}))?$/', $raw, $match)) {
            return null;
        }
        $whole = (int)strtok($raw, '.');
        $fraction = isset($match[1]) ? str_pad($match[1], 3, '0') : '000';
        return ($whole * self::SCALE) + (int)$fraction;
    }

    public static function scaledToDecimal(int $scaled): string
    {
        $sign = $scaled < 0 ? '-' : '';
        $scaled = abs($scaled);
        $whole = intdiv($scaled, self::SCALE);
        $fraction = str_pad((string)($scaled % self::SCALE), 3, '0', STR_PAD_LEFT);
        return $sign . $whole . '.' . $fraction;
    }

    /** Converts scaled quantity from one compatible unit to another, rounding down. */
    public static function convertScaledFloor(int $scaled, string $fromUnit, string $toUnit): ?int
    {
        return self::convertScaled($scaled, $fromUnit, $toUnit, false);
    }

    /** Converts scaled quantity from one compatible unit to another, rounding up. */
    public static function convertScaledCeil(int $scaled, string $fromUnit, string $toUnit): ?int
    {
        return self::convertScaled($scaled, $fromUnit, $toUnit, true);
    }

    /** @return array{usable_scaled:int,required_scaled:int,supported_servings:int}|null */
    public static function ingredientCapacity($usableQuantity, string $stockUnit, $quantityRequired, string $recipeUnit, $yieldFactor = '1.000'): ?array
    {
        $available = self::quantityToScaled($usableQuantity);
        $required = self::quantityToScaled($quantityRequired);
        $yield = self::quantityToScaled($yieldFactor);
        if ($available === null || $required === null || $yield === null || $required <= 0 || $yield <= 0 || $yield > self::SCALE) {
            return null;
        }

        $availableInRecipeUnit = self::convertScaledFloor($available, $stockUnit, $recipeUnit);
        if ($availableInRecipeUnit === null) {
            return null;
        }

        // A yield below 1 means more input is required. Round up to avoid
        // claiming a serving that cannot actually be prepared.
        $adjustedRequired = self::divideRoundUp($required * self::SCALE, $yield);
        return [
            'usable_scaled' => $availableInRecipeUnit,
            'required_scaled' => $adjustedRequired,
            'supported_servings' => intdiv($availableInRecipeUnit, $adjustedRequired),
        ];
    }

    /**
     * @param array<int,array{ingredient_id?:int,ingredient_name:string,usable_quantity:string|float|int,stock_unit:string,quantity_required:string|float|int,recipe_unit:string,yield_factor?:string|float|int}> $ingredients
     * @return array{maximum_servings:?int,ingredients:array<int,array<string,mixed>>,valid:bool,reason:?string}
     */
    public static function calculate(array $ingredients): array
    {
        if ($ingredients === []) {
            return ['maximum_servings' => null, 'ingredients' => [], 'valid' => false, 'reason' => 'No recipe ingredients are configured.'];
        }

        $maxServings = null;
        $rows = [];
        foreach ($ingredients as $ingredient) {
            $capacity = self::ingredientCapacity(
                $ingredient['usable_quantity'] ?? '0',
                (string)($ingredient['stock_unit'] ?? ''),
                $ingredient['quantity_required'] ?? '0',
                (string)($ingredient['recipe_unit'] ?? ''),
                $ingredient['yield_factor'] ?? '1.000'
            );
            if ($capacity === null) {
                return ['maximum_servings' => null, 'ingredients' => $rows, 'valid' => false,
                    'reason' => 'An ingredient has an invalid quantity, yield, or incompatible unit.'];
            }
            $row = $ingredient + $capacity;
            $rows[] = $row;
            $maxServings = $maxServings === null
                ? $capacity['supported_servings']
                : min($maxServings, $capacity['supported_servings']);
        }

        return ['maximum_servings' => $maxServings, 'ingredients' => $rows, 'valid' => true, 'reason' => null];
    }

    /** @return array<string,string> */
    public static function supportedUnits(): array
    {
        $units = [];
        foreach (self::UNITS as $key => $def) {
            $units[$key] = $def['label'];
        }
        return $units;
    }

    private static function convertScaled(int $scaled, string $fromUnit, string $toUnit, bool $roundUp): ?int
    {
        if ($scaled < 0 || !self::areCompatible($fromUnit, $toUnit)) {
            return null;
        }
        $from = self::UNITS[self::normalizeUnit($fromUnit)];
        $to = self::UNITS[self::normalizeUnit($toUnit)];
        $numerator = $scaled * $from['numerator'] * $to['denominator'];
        $denominator = $from['denominator'] * $to['numerator'];
        if ($denominator <= 0) {
            return null;
        }
        return $roundUp ? self::divideRoundUp($numerator, $denominator) : intdiv($numerator, $denominator);
    }

    private static function divideRoundUp(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
