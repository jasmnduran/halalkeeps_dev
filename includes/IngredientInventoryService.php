<?php
/**
 * Transaction-safe batch inventory operations for confirmed recipe mappings.
 * Stock is reserved at order creation, consumed when preparation begins, and
 * released on cancellation or failed payment. No payment amount/status is
 * calculated or changed here.
 */
declare(strict_types=1);

require_once __DIR__ . '/RecipeAvailabilityCalculator.php';
require_once __DIR__ . '/IngredientEvidenceService.php';

final class IngredientInventoryService
{
    public function __construct(private mysqli $conn)
    {
    }

    public static function isFeatureEnabled(): bool
    {
        return filter_var(getenv('INGREDIENT_MENU_MAPPING_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    }

    public function schemaReady(): bool
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('ingredient_inventory_batches','menu_recipes','recipe_ingredients','ingredient_stock_reservations')");
        if (!$stmt) return false;
        $stmt->execute();
        $ready = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) === 4;
        $stmt->close();
        return $ready;
    }

    /** @return array<string,mixed> */
    public function availabilityForMenu(int $restaurantId, int $menuItemId, bool $persist = false): array
    {
        $recipe = $this->activeRecipe($restaurantId, $menuItemId);
        if ($recipe === null) return $this->notConfirmed('No active structured recipe is configured.');
        $ingredients = $this->recipeIngredients((int)$recipe['id']);
        if ($ingredients === []) return $this->notConfirmed('The active recipe has no ingredients.');

        $rows = [];
        $maxServings = null;
        $invalid = false;
        $unconfirmed = false;
        $overallEvidence = 'reviewed';
        foreach ($ingredients as $ingredient) {
            $row = [
                'recipe_ingredient_id' => (int)$ingredient['id'],
                'ingredient_name' => $ingredient['ingredient_name'],
                'mapping_status' => $ingredient['mapping_status'],
                'inventory_id' => $ingredient['inventory_id'] === null ? null : (int)$ingredient['inventory_id'],
                'quantity_required' => $ingredient['quantity_required'], 'unit' => $ingredient['unit'],
                'yield_factor' => $ingredient['yield_factor'], 'usable_quantity' => '0.000',
                'supported_servings' => 0, 'evidence_status' => 'missing', 'warnings' => [],
            ];
            if ($ingredient['mapping_status'] !== 'confirmed' || empty($ingredient['inventory_id'])) {
                $row['warnings'][] = 'Ingredient mapping requires chef confirmation.';
                $unconfirmed = true;
                $overallEvidence = $this->moreSevereEvidence($overallEvidence, 'pending_review');
                $rows[] = $row;
                continue;
            }
            $batches = $this->usableBatches($restaurantId, (int)$ingredient['inventory_id']);
            $usable = 0;
            $evidence = 'reviewed';
            foreach ($batches as $batch) {
                $available = RecipeAvailabilityCalculator::quantityToScaled($batch['quantity_available']);
                $reserved = RecipeAvailabilityCalculator::quantityToScaled($batch['quantity_reserved']);
                if ($available === null || $reserved === null || $available < $reserved) {
                    $invalid = true; $row['warnings'][] = 'A batch has an invalid stock quantity.'; continue;
                }
                $converted = RecipeAvailabilityCalculator::convertScaledFloor($available - $reserved, $batch['unit'], $ingredient['unit']);
                if ($converted === null) {
                    $invalid = true; $row['warnings'][] = 'A batch unit is not compatible with the recipe unit.'; continue;
                }
                $usable += $converted;
                $evidence = $this->moreSevereEvidence($evidence, (string)$batch['evidence_status']);
            }
            $row['usable_quantity'] = RecipeAvailabilityCalculator::scaledToDecimal($usable);
            $capacity = RecipeAvailabilityCalculator::ingredientCapacity($row['usable_quantity'], $ingredient['unit'], $ingredient['quantity_required'], $ingredient['unit'], $ingredient['yield_factor']);
            if ($capacity === null) {
                $invalid = true; $row['warnings'][] = 'Recipe quantity or yield is invalid.';
            } else {
                $row['supported_servings'] = $capacity['supported_servings'];
                $maxServings = $maxServings === null ? $capacity['supported_servings'] : min($maxServings, $capacity['supported_servings']);
            }
            if ($batches === []) $row['warnings'][] = 'No usable, unexpired batch is available.';
            $row['evidence_status'] = $evidence;
            $overallEvidence = $this->moreSevereEvidence($overallEvidence, $evidence);
            $rows[] = $row;
        }

        $threshold = max(1, min(1000, (int)(getenv('INGREDIENT_LIMITED_PORTION_THRESHOLD') ?: 10)));
        $status = $unconfirmed ? 'inventory_not_confirmed' : ($invalid || $maxServings === null || $maxServings <= 0 ? 'unavailable' : ($maxServings <= $threshold ? 'limited_stock' : 'available'));
        $result = ['recipe_id' => (int)$recipe['id'], 'maximum_servings' => $maxServings ?? 0,
            'operational_status' => $status, 'ingredient_evidence_status' => $overallEvidence,
            'evidence_review_required' => $overallEvidence !== 'reviewed', 'ingredients' => $rows];
        if ($persist) $this->persistMenuState($restaurantId, $menuItemId, $status, $overallEvidence);
        return $result;
    }

    /** @param array<int,array{id:int,qty:int}> $items @return array{success:bool,message:string} */
    public function reserveForOrder(int $orderId, int $restaurantId, array $items, ?int $recordedBy = null): array
    {
        if (!self::isFeatureEnabled() || !$this->schemaReady()) return ['success' => true, 'message' => 'Ingredient controls are disabled.'];
        $this->conn->begin_transaction();
        try {
            $existing = $this->conn->prepare("SELECT COUNT(*) AS c FROM ingredient_stock_reservations WHERE order_id=? AND reservation_status IN ('active','consumed')");
            $existing->bind_param('i', $orderId); $existing->execute();
            $count = (int)($existing->get_result()->fetch_assoc()['c'] ?? 0); $existing->close();
            if ($count > 0) { $this->conn->commit(); return ['success' => true, 'message' => 'Inventory is already reserved for this order.']; }
            foreach ($items as $item) {
                $menuId = (int)($item['id'] ?? 0); $portions = (int)($item['qty'] ?? 0);
                if ($menuId <= 0 || $portions <= 0) throw new RuntimeException('Order has an invalid menu quantity.');
                foreach ($this->recipeIngredientsForMenu($restaurantId, $menuId) as $ingredient) {
                    if ($ingredient['mapping_status'] !== 'confirmed' || empty($ingredient['inventory_id'])) throw new RuntimeException('Inventory is not confirmed for ' . $ingredient['ingredient_name'] . '.');
                    $needed = $this->requiredForPortions($ingredient, $portions);
                    $this->allocateReservation($orderId, $restaurantId, $ingredient, $needed, $recordedBy);
                }
            }
            $this->conn->commit();
            return ['success' => true, 'message' => 'Ingredient stock reserved.'];
        } catch (Throwable $e) {
            $this->conn->rollback();
            return ['success' => false, 'message' => 'Insufficient confirmed ingredient stock: ' . $e->getMessage()];
        }
    }

    public function releaseOrderReservations(int $orderId, ?int $recordedBy = null): void
    {
        if (!$this->schemaReady()) return;
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare("SELECT r.*, b.quantity_reserved, b.unit AS batch_unit FROM ingredient_stock_reservations r JOIN ingredient_inventory_batches b ON b.id=r.batch_id WHERE r.order_id=? AND r.reservation_status='active' FOR UPDATE");
            $stmt->bind_param('i', $orderId); $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
            foreach ($rows as $row) {
                $quantity = (string)$row['quantity_reserved'];
                $upd = $this->conn->prepare("UPDATE ingredient_inventory_batches SET quantity_reserved=GREATEST(0, quantity_reserved-?), updated_at=NOW() WHERE id=?");
                $upd->bind_param('si', $quantity, $row['batch_id']); $upd->execute(); $upd->close();
                $status = 'released'; $reservation = $this->conn->prepare("UPDATE ingredient_stock_reservations SET reservation_status=?, released_at=NOW() WHERE id=? AND reservation_status='active'");
                $reservation->bind_param('si', $status, $row['id']); $reservation->execute(); $reservation->close();
                $this->movement((int)$row['restaurant_id'], (int)$row['batch_id'], $orderId, 'release', $quantity, $row['unit'], 'Reservation released', 'release:' . $row['id'], $recordedBy);
            }
            $this->conn->commit();
        } catch (Throwable $e) { $this->conn->rollback(); throw $e; }
    }

    public function consumeOrderReservations(int $orderId, ?int $recordedBy = null): void
    {
        if (!$this->schemaReady()) return;
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare("SELECT r.*, b.quantity_available, b.quantity_reserved AS batch_reserved FROM ingredient_stock_reservations r JOIN ingredient_inventory_batches b ON b.id=r.batch_id WHERE r.order_id=? AND r.reservation_status='active' FOR UPDATE");
            $stmt->bind_param('i', $orderId); $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
            foreach ($rows as $row) {
                $available = RecipeAvailabilityCalculator::quantityToScaled($row['quantity_available']);
                $reserved = RecipeAvailabilityCalculator::quantityToScaled($row['batch_reserved']);
                $consume = RecipeAvailabilityCalculator::quantityToScaled($row['quantity_reserved']);
                if ($available === null || $reserved === null || $consume === null || $available < $consume || $reserved < $consume) throw new RuntimeException('Reserved stock no longer has a valid quantity.');
                $qty = (string)$row['quantity_reserved'];
                $upd = $this->conn->prepare("UPDATE ingredient_inventory_batches SET quantity_available=quantity_available-?, quantity_reserved=quantity_reserved-?, batch_status=CASE WHEN quantity_available-? <= 0 THEN 'exhausted' ELSE batch_status END, updated_at=NOW() WHERE id=?");
                $upd->bind_param('sssi', $qty, $qty, $qty, $row['batch_id']); $upd->execute(); $upd->close();
                $status = 'consumed'; $reservation = $this->conn->prepare("UPDATE ingredient_stock_reservations SET reservation_status=?, consumed_at=NOW() WHERE id=? AND reservation_status='active'");
                $reservation->bind_param('si', $status, $row['id']); $reservation->execute(); $reservation->close();
                $this->movement((int)$row['restaurant_id'], (int)$row['batch_id'], $orderId, 'consumption', $qty, $row['unit'], 'Reserved stock consumed for preparation', 'consume:' . $row['id'], $recordedBy);
            }
            $this->conn->commit();
        } catch (Throwable $e) { $this->conn->rollback(); throw $e; }
    }

    /** @return array<int,array<string,mixed>> */
    public function traceabilityFindings(int $restaurantId): array
    {
        return $this->schemaReady() ? IngredientEvidenceService::haceraFindings($this->conn, $restaurantId) : [];
    }

    /** @return array<string,mixed>|null */
    private function activeRecipe(int $restaurantId, int $menuItemId): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM menu_recipes WHERE restaurant_id=? AND menu_item_id=? AND recipe_status='active' LIMIT 1");
        $stmt->bind_param('ii', $restaurantId, $menuItemId); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc() ?: null; $stmt->close(); return $row;
    }

    /** @return array<int,array<string,mixed>> */
    private function recipeIngredients(int $recipeId): array
    {
        $stmt = $this->conn->prepare('SELECT * FROM recipe_ingredients WHERE recipe_id=? ORDER BY id');
        $stmt->bind_param('i', $recipeId); $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private function recipeIngredientsForMenu(int $restaurantId, int $menuId): array
    {
        $stmt = $this->conn->prepare("SELECT ri.* FROM recipe_ingredients ri JOIN menu_recipes mr ON mr.id=ri.recipe_id WHERE mr.restaurant_id=? AND mr.menu_item_id=? AND mr.recipe_status='active' ORDER BY ri.id");
        $stmt->bind_param('ii', $restaurantId, $menuId); $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        if ($rows === []) throw new RuntimeException('No active structured recipe is configured.');
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private function usableBatches(int $restaurantId, int $inventoryId): array
    {
        $stmt = $this->conn->prepare("SELECT id, quantity_available, quantity_reserved, unit, evidence_status, expiration_date FROM ingredient_inventory_batches WHERE restaurant_id=? AND inventory_id=? AND batch_status='active' AND (expiration_date IS NULL OR expiration_date>=CURDATE()) ORDER BY expiration_date IS NULL, expiration_date, id");
        $stmt->bind_param('ii', $restaurantId, $inventoryId); $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); return $rows;
    }

    private function requiredForPortions(array $ingredient, int $portions): int
    {
        $required = RecipeAvailabilityCalculator::quantityToScaled($ingredient['quantity_required']);
        $yield = RecipeAvailabilityCalculator::quantityToScaled($ingredient['yield_factor']);
        if ($required === null || $yield === null || $required <= 0 || $yield <= 0 || $yield > 1000 || $portions <= 0) throw new RuntimeException('Recipe quantity or yield is invalid.');
        $perPortion = intdiv(($required * 1000) + $yield - 1, $yield);
        return $perPortion * $portions;
    }

    private function allocateReservation(int $orderId, int $restaurantId, array $ingredient, int $neededRecipeScaled, ?int $recordedBy): void
    {
        $stmt = $this->conn->prepare("SELECT * FROM ingredient_inventory_batches WHERE restaurant_id=? AND inventory_id=? AND batch_status='active' AND (expiration_date IS NULL OR expiration_date>=CURDATE()) ORDER BY expiration_date IS NULL, expiration_date, id FOR UPDATE");
        $inventoryId = (int)$ingredient['inventory_id']; $stmt->bind_param('ii', $restaurantId, $inventoryId); $stmt->execute(); $batches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        foreach ($batches as $batch) {
            $available = RecipeAvailabilityCalculator::quantityToScaled($batch['quantity_available']); $reserved = RecipeAvailabilityCalculator::quantityToScaled($batch['quantity_reserved']);
            if ($available === null || $reserved === null || $available <= $reserved) continue;
            $remainingBatch = RecipeAvailabilityCalculator::convertScaledFloor($available - $reserved, $batch['unit'], $ingredient['unit']);
            if ($remainingBatch === null) throw new RuntimeException('Incompatible inventory unit for ' . $ingredient['ingredient_name'] . '.');
            $takeRecipe = min($neededRecipeScaled, $remainingBatch);
            $takeBatch = RecipeAvailabilityCalculator::convertScaledCeil($takeRecipe, $ingredient['unit'], $batch['unit']);
            if ($takeBatch === null || $takeBatch <= 0) continue;
            $qty = RecipeAvailabilityCalculator::scaledToDecimal($takeBatch);
            $update = $this->conn->prepare('UPDATE ingredient_inventory_batches SET quantity_reserved=quantity_reserved+?, updated_at=NOW() WHERE id=?');
            $update->bind_param('si', $qty, $batch['id']); $update->execute(); $update->close();
            $reservation = $this->conn->prepare("INSERT INTO ingredient_stock_reservations (restaurant_id,order_id,recipe_ingredient_id,batch_id,quantity_reserved,unit,reservation_status) VALUES (?,?,?,?,?,?,'active')");
            $recipeIngredientId = (int)$ingredient['id']; $reservation->bind_param('iiiiss', $restaurantId, $orderId, $recipeIngredientId, $batch['id'], $qty, $batch['unit']);
            if (!$reservation->execute()) { $error = $reservation->error; $reservation->close(); throw new RuntimeException($error); }
            $reservationId = (int)$this->conn->insert_id; $reservation->close();
            $this->movement($restaurantId, (int)$batch['id'], $orderId, 'reservation', $qty, $batch['unit'], 'FEFO stock reservation', 'reserve:' . $reservationId, $recordedBy);
            $neededRecipeScaled -= $takeRecipe;
            if ($neededRecipeScaled <= 0) return;
        }
        throw new RuntimeException('Not enough usable stock for ' . $ingredient['ingredient_name'] . '.');
    }

    private function movement(int $restaurantId, int $batchId, int $orderId, string $type, string $qty, string $unit, string $note, string $key, ?int $recordedBy): void
    {
        $stmt = $this->conn->prepare('INSERT IGNORE INTO ingredient_stock_movements (restaurant_id,batch_id,order_id,movement_type,quantity,unit,reference_note,idempotency_key,recorded_by) VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('iiisssssi', $restaurantId, $batchId, $orderId, $type, $qty, $unit, $note, $key, $recordedBy); $stmt->execute(); $stmt->close();
    }

    private function persistMenuState(int $restaurantId, int $menuId, string $status, string $evidence): void
    {
        $stmt = $this->conn->prepare('UPDATE menu_items SET operational_status=?, ingredient_evidence_status=?, inventory_checked_at=NOW() WHERE id=? AND restaurant_id=?');
        $stmt->bind_param('ssii', $status, $evidence, $menuId, $restaurantId); $stmt->execute(); $stmt->close();
    }

    /** @return array<string,mixed> */
    private function notConfirmed(string $reason): array
    {
        return ['recipe_id' => null, 'maximum_servings' => 0, 'operational_status' => 'inventory_not_confirmed', 'ingredient_evidence_status' => 'pending_review', 'evidence_review_required' => true, 'ingredients' => [], 'reason' => $reason];
    }

    private function moreSevereEvidence(string $current, string $candidate): string
    {
        $rank = ['reviewed' => 0, 'available' => 1, 'pending_review' => 2, 'requires_clarification' => 3, 'inconclusive' => 4, 'missing' => 5, 'expired' => 6];
        return ($rank[$candidate] ?? 4) > ($rank[$current] ?? 4) ? $candidate : $current;
    }
}
