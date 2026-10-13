<?php
/** Feature-flagged API for structured recipes, mapping review, and batch evidence. */
declare(strict_types=1);
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../includes/IngredientInventoryService.php';
require_once __DIR__ . '/../../../includes/IngredientMenuAIService.php';

header('Content-Type: application/json; charset=utf-8');
function imi_json(bool $success, string $message, array $extra = []): never { echo json_encode(['success' => $success, 'message' => $message] + $extra); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isLoggedIn() || (int)($_SESSION['role_id'] ?? 0) !== ROLE_BUSINESS_OWNER) imi_json(false, 'Access denied.', 403);
$restaurantId = getSessionRestaurantId($conn); $userId = (int)$_SESSION['user_id']; $staff = $_SESSION['staff_role_key'] ?? null;
if (!$restaurantId) imi_json(false, 'No active restaurant was found.');
$service = new IngredientInventoryService($conn);
if (!$service->schemaReady()) imi_json(false, 'Ingredient mapping migration has not been applied.');
$action = trim((string)($_POST['action'] ?? ''));
$isChef = $staff === null || $staff === STAFF_ROLE_CHEF;
$isReviewer = $staff === null || $staff === STAFF_ROLE_OPS_MANAGER;

if ($action === 'availability') {
    $menuId = (int)($_POST['menu_item_id'] ?? 0);
    if ($menuId <= 0) imi_json(false, 'Menu item is required.');
    imi_json(true, 'Availability calculated.', ['availability' => $service->availabilityForMenu($restaurantId, $menuId, true)]);
}

if ($action === 'recipe_details') {
    if (!$isChef) imi_json(false, 'Only the Chef or Business Owner can view structured recipes.', 403);
    $menuId = (int)($_POST['menu_item_id'] ?? 0);
    if ($menuId <= 0) imi_json(false, 'Menu item is required.');

    $menu = $conn->prepare('SELECT id, name FROM menu_items WHERE id=? AND restaurant_id=?');
    $menu->bind_param('ii', $menuId, $restaurantId); $menu->execute();
    $menuRow = $menu->get_result()->fetch_assoc(); $menu->close();
    if (!$menuRow) imi_json(false, 'Menu item not found.');

    $recipe = $conn->prepare("SELECT id, recipe_name, serving_size, version_no, customer_visibility_status, customer_visible_at, updated_at FROM menu_recipes WHERE restaurant_id=? AND menu_item_id=? AND recipe_status='active' ORDER BY id DESC LIMIT 1");
    $recipe->bind_param('ii', $restaurantId, $menuId); $recipe->execute();
    $recipeRow = $recipe->get_result()->fetch_assoc(); $recipe->close();
    if (!$recipeRow) imi_json(true, 'No structured recipe exists yet.', ['menu' => $menuRow, 'recipe' => null, 'ingredients' => []]);

    $ingredients = $conn->prepare("SELECT ri.id, ri.ingredient_name, ri.inventory_id, ri.suggested_inventory_id, ri.quantity_required, ri.unit, ri.yield_factor, ri.mapping_status, ri.mapping_source, ri.confirmed_at,
        confirmed.ingredient_name AS confirmed_inventory_name, suggested.ingredient_name AS suggested_inventory_name
        FROM recipe_ingredients ri
        LEFT JOIN kitchen_inventory confirmed ON confirmed.id=ri.inventory_id AND confirmed.restaurant_id=ri.restaurant_id
        LEFT JOIN kitchen_inventory suggested ON suggested.id=ri.suggested_inventory_id AND suggested.restaurant_id=ri.restaurant_id
        WHERE ri.recipe_id=? AND ri.restaurant_id=? ORDER BY ri.id ASC");
    $recipeId = (int)$recipeRow['id']; $ingredients->bind_param('ii', $recipeId, $restaurantId); $ingredients->execute();
    $ingredientRows = $ingredients->get_result()->fetch_all(MYSQLI_ASSOC); $ingredients->close();
    $confirmedCount = 0;
    foreach ($ingredientRows as $ingredientRow) {
        if (($ingredientRow['mapping_status'] ?? '') === 'confirmed' && (int)($ingredientRow['inventory_id'] ?? 0) > 0) $confirmedCount++;
    }
    imi_json(true, 'Structured recipe loaded.', [
        'menu' => $menuRow,
        'recipe' => $recipeRow,
        'ingredients' => $ingredientRows,
        'confirmed_count' => $confirmedCount,
        'ingredient_count' => count($ingredientRows),
    ]);
}

if ($action === 'save_recipe') {
    if (!$isChef) imi_json(false, 'Only the Chef or Business Owner can update recipes.', 403);
    $menuId = (int)($_POST['menu_item_id'] ?? 0); $name = trim(strip_tags((string)($_POST['recipe_name'] ?? '')));
    $serving = trim(strip_tags((string)($_POST['serving_size'] ?? '')));
    if ($menuId <= 0 || $name === '') imi_json(false, 'Menu item and recipe name are required.');
    $menu = $conn->prepare('SELECT id FROM menu_items WHERE id=? AND restaurant_id=?'); $menu->bind_param('ii', $menuId, $restaurantId); $menu->execute(); $exists = $menu->get_result()->fetch_assoc(); $menu->close();
    if (!$exists) imi_json(false, 'Menu item not found.');
    $conn->begin_transaction();
    try {
        $archive = $conn->prepare("UPDATE menu_recipes SET recipe_status='archived', last_updated_by=? WHERE restaurant_id=? AND menu_item_id=? AND recipe_status='active'"); $archive->bind_param('iii', $userId, $restaurantId, $menuId); $archive->execute(); $archive->close();
        $stmt = $conn->prepare("INSERT INTO menu_recipes (restaurant_id,menu_item_id,recipe_name,serving_size,recipe_status,created_by,last_updated_by) VALUES (?,?,?,?, 'active', ?, ?)");
        $stmt->bind_param('iissii', $restaurantId, $menuId, $name, $serving, $userId, $userId); $stmt->execute(); $recipeId = (int)$conn->insert_id; $stmt->close(); $conn->commit();
        imi_json(true, 'Structured recipe created.', ['recipe_id' => $recipeId]);
    } catch (Throwable $e) { $conn->rollback(); imi_json(false, 'Could not save recipe.'); }
}

if ($action === 'save_recipe_ingredient') {
    if (!$isChef) imi_json(false, 'Only the Chef or Business Owner can update recipes.', 403);
    $recipeId = (int)($_POST['recipe_id'] ?? 0); $inventoryId = (int)($_POST['inventory_id'] ?? 0);
    $name = trim(strip_tags((string)($_POST['ingredient_name'] ?? ''))); $qty = trim((string)($_POST['quantity_required'] ?? ''));
    $unit = RecipeAvailabilityCalculator::normalizeUnit((string)($_POST['unit'] ?? '')); $yield = trim((string)($_POST['yield_factor'] ?? '1.000'));
    if ($recipeId <= 0 || $name === '' || RecipeAvailabilityCalculator::quantityToScaled($qty) === null || RecipeAvailabilityCalculator::quantityToScaled($qty) <= 0 || $unit === null || RecipeAvailabilityCalculator::quantityToScaled($yield) === null) imi_json(false, 'Recipe ingredient quantity, unit, or yield is invalid.');
    $recipe = $conn->prepare('SELECT id FROM menu_recipes WHERE id=? AND restaurant_id=? AND recipe_status=\'active\''); $recipe->bind_param('ii', $recipeId, $restaurantId); $recipe->execute(); $ok = $recipe->get_result()->fetch_assoc(); $recipe->close(); if (!$ok) imi_json(false, 'Active recipe not found.');
    $status = 'unmatched'; $source = 'manual'; $confirmedBy = null; $confirmedAt = null; $inventoryDbId = null;
    if ($inventoryId > 0) { $inv = $conn->prepare('SELECT unit FROM kitchen_inventory WHERE id=? AND restaurant_id=?'); $inv->bind_param('ii', $inventoryId, $restaurantId); $inv->execute(); $inventory = $inv->get_result()->fetch_assoc(); $inv->close(); if (!$inventory || !RecipeAvailabilityCalculator::areCompatible($unit, $inventory['unit'])) imi_json(false, 'Inventory ingredient is not owned by this restaurant or has an incompatible unit.'); $status='confirmed'; $inventoryDbId=$inventoryId; $confirmedBy=$userId; $confirmedAt=date('Y-m-d H:i:s'); }
    $stmt = $conn->prepare('INSERT INTO recipe_ingredients (recipe_id,restaurant_id,ingredient_name,inventory_id,quantity_required,unit,yield_factor,mapping_status,mapping_source,confirmed_by,confirmed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param('iisisssssis', $recipeId, $restaurantId, $name, $inventoryDbId, $qty, $unit, $yield, $status, $source, $confirmedBy, $confirmedAt);
    if (!$stmt->execute()) { $error=$stmt->error; $stmt->close(); imi_json(false, $error); } $id=(int)$conn->insert_id; $stmt->close();
    // Any edit after publication requires the Chef to approve the customer listing again.
    $visibility = $conn->prepare("UPDATE menu_recipes SET customer_visibility_status=CASE WHEN customer_visibility_status='published' THEN 'needs_reapproval' ELSE customer_visibility_status END, last_updated_by=? WHERE id=? AND restaurant_id=?");
    if ($visibility) { $visibility->bind_param('iii', $userId, $recipeId, $restaurantId); $visibility->execute(); $visibility->close(); }
    imi_json(true, 'Recipe ingredient saved.', ['recipe_ingredient_id'=>$id]);
}

if ($action === 'suggest_mappings') {
    if (!$isChef) imi_json(false, 'Only the Chef or Business Owner can request mapping suggestions.', 403);
    $recipeId = (int)($_POST['recipe_id'] ?? 0);
    $rs = $conn->prepare('SELECT id,ingredient_name,inventory_id,unit FROM recipe_ingredients WHERE recipe_id=? AND restaurant_id=? AND mapping_status<>\'confirmed\''); $rs->bind_param('ii',$recipeId,$restaurantId); $rs->execute(); $ingredients=$rs->get_result()->fetch_all(MYSQLI_ASSOC); $rs->close();
    $cs=$conn->prepare('SELECT id,ingredient_name,unit FROM kitchen_inventory WHERE restaurant_id=?'); $cs->bind_param('i',$restaurantId); $cs->execute(); $candidates=$cs->get_result()->fetch_all(MYSQLI_ASSOC); $cs->close();
    $as=$conn->prepare('SELECT inventory_id,alias_name,is_verified FROM ingredient_aliases WHERE restaurant_id=?'); $as->bind_param('i',$restaurantId); $as->execute(); $aliases=$as->get_result()->fetch_all(MYSQLI_ASSOC); $as->close();
    $result=(new IngredientMenuAIService())->suggestBatch($ingredients,$candidates,$aliases);
    // Name similarity is never enough: an inventory candidate must use a
    // compatible measurement dimension before it can reach Chef review.
    $recipeUnits = [];
    foreach ($ingredients as $ingredient) $recipeUnits[(int)$ingredient['id']] = (string)$ingredient['unit'];
    $candidateUnits = [];
    foreach ($candidates as $candidate) $candidateUnits[(int)$candidate['id']] = (string)$candidate['unit'];
    foreach ($result['suggestions'] as &$suggestion) {
        $recipeIngredientId = (int)($suggestion['recipe_ingredient_id'] ?? 0);
        $suggestedInventoryId = (int)($suggestion['suggested_inventory_ingredient_id'] ?? 0);
        $recipeUnit = $recipeUnits[$recipeIngredientId] ?? '';
        $candidateUnit = $candidateUnits[$suggestedInventoryId] ?? '';
        if ($suggestedInventoryId > 0 && (!isset($candidateUnits[$suggestedInventoryId]) || !RecipeAvailabilityCalculator::areCompatible($recipeUnit, $candidateUnit))) {
            $suggestion = [
                'recipe_ingredient_id' => $recipeIngredientId,
                'suggested_inventory_ingredient_id' => 0,
                'match_category' => 'unmatched',
                'confidence_indicator' => 'none',
                'explanation' => 'A same-name inventory record was excluded because its measurement unit is incompatible with this recipe requirement.',
                'requires_manual_confirmation' => true,
                'potential_substitution_warning' => 'Use an inventory record with a compatible unit or update the recipe quantity/unit before confirming a mapping.',
            ];
        }
    }
    unset($suggestion);
    $conn->begin_transaction();
    try {
        foreach ($result['suggestions'] as $suggestion) {
            $sid=(int)$suggestion['suggested_inventory_ingredient_id']; $suggestedDbId=$sid > 0 ? $sid : null;
            $category=$suggestion['match_category']; $confidence=$suggestion['confidence_indicator'];
            $explanation=$suggestion['explanation']; $manual=(int)$suggestion['requires_manual_confirmation'];
            $warning=$suggestion['potential_substitution_warning']; $provider=$result['provider']??null;
            $model=$result['model']??null; $version=IngredientMenuAIService::PROMPT_VERSION;
            $ri=(int)$suggestion['recipe_ingredient_id'];
            $mappingStatus = $suggestedDbId === null ? 'unmatched' : 'suggested';
            $mappingSource = $category === 'normalized_name' ? 'normalized_name' : ($category === 'verified_alias' ? 'verified_alias' : ($category === 'semantic' ? 'ai' : 'manual'));
            $update=$conn->prepare('UPDATE recipe_ingredients SET suggested_inventory_id=?, mapping_status=?, mapping_source=? WHERE id=? AND restaurant_id=? AND mapping_status<>\'confirmed\'');
            $update->bind_param('issii', $suggestedDbId, $mappingStatus, $mappingSource, $ri, $restaurantId);
            if (!$update->execute()) { $error=$update->error; $update->close(); throw new RuntimeException($error); }
            $update->close();
            $ins=$conn->prepare('INSERT INTO ai_ingredient_mappings (restaurant_id,recipe_ingredient_id,suggested_inventory_id,match_category,confidence_indicator,explanation,requires_manual_confirmation,potential_substitution_warning,provider,model,prompt_version) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $ins->bind_param('iiisssissss',$restaurantId,$ri,$suggestedDbId,$category,$confidence,$explanation,$manual,$warning,$provider,$model,$version);
            if (!$ins->execute()) { $error=$ins->error; $ins->close(); throw new RuntimeException($error); }
            $ins->close();
        }
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); imi_json(false, 'Could not save mapping suggestions.'); }
    imi_json(true, 'Mapping suggestions prepared for chef review.', ['suggestions'=>$result['suggestions'], 'provider'=>$result['provider'] ?? 'rules', 'model'=>$result['model'] ?? null, 'ai_available'=>IngredientMenuAIService::isFeatureEnabled() && (new IngredientMenuAIService())->isConfigured()]);
}

if ($action === 'confirm_mapping') {
    if (!$isChef) imi_json(false, 'Only the Chef or Business Owner can confirm mappings.', 403);
    $recipeIngredientId=(int)($_POST['recipe_ingredient_id']??0); $inventoryId=(int)($_POST['inventory_id']??0); $decision=trim((string)($_POST['decision']??''));
    if (!in_array($decision,['confirm','reject'],true) || $recipeIngredientId<=0) imi_json(false,'Invalid mapping decision.');
    $conn->begin_transaction();
    try {
        if ($decision==='confirm') {
            $check=$conn->prepare('SELECT ri.unit,ki.unit AS inventory_unit FROM recipe_ingredients ri JOIN kitchen_inventory ki ON ki.id=? AND ki.restaurant_id=ri.restaurant_id WHERE ri.id=? AND ri.restaurant_id=?'); $check->bind_param('iii',$inventoryId,$recipeIngredientId,$restaurantId); $check->execute(); $row=$check->get_result()->fetch_assoc(); $check->close();
            if(!$row || !RecipeAvailabilityCalculator::areCompatible($row['unit'],$row['inventory_unit'])) imi_json(false,'Inventory selection is invalid or unit-incompatible.');
            $status='confirmed'; $source='ai';
            $up=$conn->prepare('UPDATE recipe_ingredients SET inventory_id=?,suggested_inventory_id=NULL,mapping_status=?,mapping_source=?,confirmed_by=?,confirmed_at=NOW() WHERE id=? AND restaurant_id=?');
            $up->bind_param('issiii',$inventoryId,$status,$source,$userId,$recipeIngredientId,$restaurantId);
        } else {
            $status='rejected';
            $up=$conn->prepare('UPDATE recipe_ingredients SET suggested_inventory_id=NULL,mapping_status=? WHERE id=? AND restaurant_id=?');
            $up->bind_param('sii',$status,$recipeIngredientId,$restaurantId);
        }
        if (!$up->execute()) { $error=$up->error; $up->close(); throw new RuntimeException($error); } $up->close();
        $reviewStatus = $decision === 'confirm' ? 'confirmed' : 'rejected';
        $review=$conn->prepare("UPDATE ai_ingredient_mappings SET review_status=?, reviewed_by=?, reviewed_at=NOW() WHERE restaurant_id=? AND recipe_ingredient_id=? AND review_status='pending'");
        $review->bind_param('siii',$reviewStatus,$userId,$restaurantId,$recipeIngredientId);
        if (!$review->execute()) { $error=$review->error; $review->close(); throw new RuntimeException($error); } $review->close();
        // A newly confirmed/rejected mapping invalidates a previously published disclosure.
        $visibility = $conn->prepare("UPDATE menu_recipes SET customer_visibility_status=CASE WHEN customer_visibility_status='published' THEN 'needs_reapproval' ELSE customer_visibility_status END, last_updated_by=? WHERE id=(SELECT recipe_id FROM recipe_ingredients WHERE id=? AND restaurant_id=?) AND restaurant_id=?");
        if ($visibility) { $visibility->bind_param('iiii', $userId, $recipeIngredientId, $restaurantId, $restaurantId); $visibility->execute(); $visibility->close(); }
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); imi_json(false, 'Could not save the chef mapping decision.'); }
    imi_json(true, $decision === 'confirm' ? 'Chef mapping confirmed.' : 'Chef mapping rejected.');
}

if ($action === 'approve_recipe_listing') {
    if (!$isChef) imi_json(false, 'Only the Chef or Business Owner can approve the ingredient listing.', 403);
    $recipeId = (int)($_POST['recipe_id'] ?? 0);
    if ($recipeId <= 0) imi_json(false, 'Recipe is required.');

    $check = $conn->prepare("SELECT mr.id,
        COUNT(ri.id) AS ingredient_count,
        COALESCE(SUM(CASE WHEN ri.mapping_status IN ('confirmed','rejected') THEN 1 ELSE 0 END),0) AS reviewed_count,
        COALESCE(SUM(CASE WHEN ri.mapping_status='confirmed' AND ri.inventory_id IS NOT NULL AND ki.id IS NOT NULL THEN 1 ELSE 0 END),0) AS confirmed_count
        FROM menu_recipes mr
        LEFT JOIN recipe_ingredients ri ON ri.recipe_id=mr.id AND ri.restaurant_id=mr.restaurant_id
        LEFT JOIN kitchen_inventory ki ON ki.id=ri.inventory_id AND ki.restaurant_id=mr.restaurant_id
        WHERE mr.id=? AND mr.restaurant_id=? AND mr.recipe_status='active'
        GROUP BY mr.id");
    $check->bind_param('ii', $recipeId, $restaurantId); $check->execute();
    $recipeCheck = $check->get_result()->fetch_assoc(); $check->close();
    if (!$recipeCheck) imi_json(false, 'Active recipe not found.');
    $ingredientCount = (int)$recipeCheck['ingredient_count'];
    $reviewedCount = (int)$recipeCheck['reviewed_count'];
    $confirmedCount = (int)$recipeCheck['confirmed_count'];
    if ($ingredientCount < 1) imi_json(false, 'Add at least one recipe ingredient before approving the listing.');
    if ($reviewedCount !== $ingredientCount) imi_json(false, 'Review every recipe ingredient before approving the listing. Confirm or explicitly reject each suggestion.');
    if ($confirmedCount < 1) imi_json(false, 'At least one ingredient must be confirmed before the listing can go live.');

    $publish = $conn->prepare("UPDATE menu_recipes SET customer_visibility_status='published', customer_visible_by=?, customer_visible_at=NOW(), last_updated_by=? WHERE id=? AND restaurant_id=? AND recipe_status='active'");
    $publish->bind_param('iiii', $userId, $userId, $recipeId, $restaurantId);
    if (!$publish->execute()) { $error=$publish->error; $publish->close(); imi_json(false, 'Could not approve the ingredient listing.'); }
    $publish->close();
    imi_json(true, 'Recipe ingredient listing approved by the Chef and published to customers.', ['recipe_id'=>$recipeId, 'confirmed_count'=>$confirmedCount]);
}
if ($action === 'record_batch') {
    if (!$isChef) imi_json(false,'Only the Chef or Business Owner can record purchased stock.',403);
    $inventoryId=(int)($_POST['inventory_id']??0); $qty=trim((string)($_POST['quantity']??'')); $unit=RecipeAvailabilityCalculator::normalizeUnit((string)($_POST['unit']??''));
    $batch=trim(strip_tags((string)($_POST['batch_code']??''))); $expiry=trim((string)($_POST['expiration_date']??''));
    $supplier=trim(strip_tags((string)($_POST['supplier_reference']??'')));
    if($inventoryId<=0 || RecipeAvailabilityCalculator::quantityToScaled($qty)===null || RecipeAvailabilityCalculator::quantityToScaled($qty)<=0 || $unit===null) imi_json(false,'A positive quantity and supported unit are required.');
    $i=$conn->prepare('SELECT id,unit FROM kitchen_inventory WHERE id=? AND restaurant_id=?'); $i->bind_param('ii',$inventoryId,$restaurantId); $i->execute(); $inventory=$i->get_result()->fetch_assoc(); $i->close(); if(!$inventory || !RecipeAvailabilityCalculator::areCompatible($unit,$inventory['unit'])) imi_json(false,'Inventory item is invalid or unit-incompatible.');
    $stmt=$conn->prepare("INSERT INTO ingredient_inventory_batches (restaurant_id,inventory_id,batch_code,quantity_received,quantity_available,unit,purchase_date,expiration_date,supplier_reference,source_type,batch_status,evidence_status,recorded_by,last_updated_by) VALUES (?,?,?,?,?,?,CURDATE(),?,?,'purchase','active','pending_review',?,?)");
    if (!$stmt) imi_json(false,'Unable to prepare batch record.');
    $stmt->bind_param('iissssssii',$restaurantId,$inventoryId,$batch,$qty,$qty,$unit,$expiry,$supplier,$userId,$userId);
    if (!$stmt->execute()) { $error=$stmt->error; $stmt->close(); imi_json(false,$error); }
    $batchId=(int)$conn->insert_id; $stmt->close();
    $movement=$conn->prepare("INSERT INTO ingredient_stock_movements (restaurant_id,batch_id,movement_type,quantity,unit,reference_note,idempotency_key,recorded_by) VALUES (?,?,'receipt',?,?,'Stock receipt',?,?)");
    $key='receipt:'.$batchId; $movement->bind_param('iisssi',$restaurantId,$batchId,$qty,$unit,$key,$userId); $movement->execute(); $movement->close();
    imi_json(true,'Ingredient batch recorded with pending evidence review.', ['batch_id'=>$batchId]);
}

if ($action === 'review_evidence') {
    if (!$isReviewer) imi_json(false,'Only the Operations Manager or Business Owner can review evidence.',403);
    $documentId=(int)($_POST['document_id']??0); $status=trim((string)($_POST['document_status']??'')); if(!in_array($status,['pending_review','reviewed','inconclusive','requires_clarification'],true)) imi_json(false,'Invalid evidence review status.');
    $stmt=$conn->prepare('UPDATE ingredient_documents SET document_status=?,reviewed_by=?,reviewed_at=NOW(),review_note=? WHERE id=? AND restaurant_id=?'); $note=mb_substr(trim(strip_tags((string)($_POST['review_note']??''))),0,1000); $stmt->bind_param('sisii',$status,$userId,$note,$documentId,$restaurantId); $stmt->execute(); $stmt->close(); imi_json(true,'Evidence review recorded. It does not establish certificate authenticity.');
}
imi_json(false,'Unknown action.');
