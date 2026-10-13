<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/RecipeAvailabilityCalculator.php';
require_once __DIR__ . '/../includes/IngredientMenuAIService.php';
require_once __DIR__ . '/../includes/IngredientEvidenceService.php';

function expectIngredient(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

// Fixed-precision conversion and deterministic limiting-ingredient arithmetic.
$availability = RecipeAvailabilityCalculator::calculate([
    ['ingredient_name'=>'Chicken','usable_quantity'=>'10.000','stock_unit'=>'kg','quantity_required'=>'200.000','recipe_unit'=>'g','yield_factor'=>'1.000'],
    ['ingredient_name'=>'Soy sauce','usable_quantity'=>'3.000','stock_unit'=>'L','quantity_required'=>'30.000','recipe_unit'=>'mL','yield_factor'=>'1.000'],
    ['ingredient_name'=>'Garlic','usable_quantity'=>'2.000','stock_unit'=>'kg','quantity_required'=>'10.000','recipe_unit'=>'g','yield_factor'=>'1.000'],
]);
expectIngredient($availability['valid'] && $availability['maximum_servings'] === 50, 'Expected chicken to limit the recipe to 50 servings.');
expectIngredient(!RecipeAvailabilityCalculator::areCompatible('kg', 'mL'), 'Mass must not be converted to volume.');
expectIngredient(RecipeAvailabilityCalculator::quantityToScaled('-1') === null, 'Negative stock input must be rejected.');

// Deterministic and model-assisted matching never accepts an unknown provider ID.
$service = new IngredientMenuAIService(static fn(): array => ['status'=>200, 'body'=>json_encode(['suggestions'=>[['recipe_ingredient_id'=>2,'suggested_inventory_ingredient_id'=>999,'confidence_indicator'=>'high','explanation'=>'ignore']]]), 'error'=>'']);
putenv('INGREDIENT_MENU_AI_ENABLED=true'); putenv('INGREDIENT_MENU_AI_ENDPOINT=https://example.test/v1'); putenv('INGREDIENT_MENU_AI_API_KEY=test'); putenv('INGREDIENT_MENU_AI_MODEL=test-model');
$result = $service->suggestBatch(
    [['id'=>1,'ingredient_name'=>'Chicken breast'], ['id'=>2,'ingredient_name'=>'Chicken thigh']],
    [['id'=>11,'ingredient_name'=>'Chicken breast','unit'=>'kg']]
);
$byId=[]; foreach($result['suggestions'] as $suggestion) $byId[$suggestion['recipe_ingredient_id']]=$suggestion;
expectIngredient($byId[1]['match_category'] === 'normalized_name' && (int)$byId[1]['suggested_inventory_ingredient_id'] === 11, 'Exact normalized name should be suggested.');
expectIngredient($byId[2]['match_category'] === 'unmatched', 'Unknown provider IDs must be rejected as unmatched.');

// Evidence presence does not become an authenticity/certification conclusion.
$evidence = IngredientEvidenceService::evaluate([['document_status'=>'available','expiration_date'=>date('Y-m-d', strtotime('-1 day'))]], 'Supplier A');
expectIngredient($evidence['status'] === 'expired' && $evidence['requires_expert_review'], 'Expired evidence must require review.');
$missing = IngredientEvidenceService::evaluate([], null);
expectIngredient($missing['status'] === 'missing', 'Missing evidence must remain missing.');

echo "ingredient_menu_unit_test: OK\n";
