<?php
/**
 * Chef recipe submission and Operations Manager review workflow.
 *
 * The service deliberately leaves menu_items.approval_status compatible with
 * older pages. workflow_status provides the richer state machine and
 * menu_item_approval_history preserves every submission and decision.
 */
declare(strict_types=1);

require_once __DIR__ . '/IngredientInventoryService.php';

final class MenuWorkflowService
{
    public const DRAFT = 'draft';
    public const PENDING_REVIEW = 'pending_review';
    public const NEEDS_REVISION = 'needs_revision';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const DELETED = 'deleted';
    public const LEGACY = 'legacy';

    public function __construct(private mysqli $conn)
    {
    }

    public static function ensureCsrfToken(): string
    {
        if (empty($_SESSION['menu_workflow_csrf_token'])) {
            $_SESSION['menu_workflow_csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['menu_workflow_csrf_token'];
    }

    public static function validCsrfToken(?string $provided): bool
    {
        $expected = (string)($_SESSION['menu_workflow_csrf_token'] ?? '');
        return $expected !== '' && is_string($provided) && hash_equals($expected, $provided);
    }

    public function schemaReady(): bool
    {
        $tables = $this->conn->prepare("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('menu_recipes','recipe_ingredients','menu_item_approval_history')");
        if (!$tables) return false;
        $tables->execute();
        $tableCount = (int)($tables->get_result()->fetch_assoc()['c'] ?? 0);
        $tables->close();
        if ($tableCount !== 3) return false;

        $column = $this->conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='menu_items' AND COLUMN_NAME='workflow_status'");
        if (!$column) return false;
        $column->execute();
        $hasColumn = (int)($column->get_result()->fetch_assoc()['c'] ?? 0) === 1;
        $column->close();
        return $hasColumn;
    }

    /** Customer/POS predicate that retains pre-workflow approved menu rows. */
    public static function customerEligibilitySql(string $menuAlias = 'mi'): string
    {
        return "$menuAlias.approval_status = 'approved'
            AND (COALESCE($menuAlias.workflow_status, 'legacy') = 'legacy'
                OR EXISTS (
                    SELECT 1 FROM menu_recipes workflow_recipe
                    WHERE workflow_recipe.restaurant_id = $menuAlias.restaurant_id
                      AND workflow_recipe.menu_item_id = $menuAlias.id
                      AND workflow_recipe.recipe_status = 'active'
                      AND workflow_recipe.customer_visibility_status = 'published'
                ))";
    }

    /** @return array{valid:bool,errors:array<int,string>,recipe:?array,availability:?array} */
    public function validateSubmission(int $restaurantId, int $menuItemId): array
    {
        $errors = [];
        $menu = $this->conn->prepare('SELECT id,name,category,price FROM menu_items WHERE id=? AND restaurant_id=? LIMIT 1');
        $menu->bind_param('ii', $menuItemId, $restaurantId);
        $menu->execute();
        $menuRow = $menu->get_result()->fetch_assoc();
        $menu->close();
        if (!$menuRow) return ['valid' => false, 'errors' => ['Menu item was not found for this restaurant.'], 'recipe' => null, 'availability' => null];
        if (trim((string)$menuRow['name']) === '') $errors[] = 'Menu name is required.';
        if (trim((string)$menuRow['category']) === '') $errors[] = 'Menu category is required.';
        if (!is_numeric($menuRow['price']) || (float)$menuRow['price'] < 0) $errors[] = 'A valid menu price is required.';

        $recipeStmt = $this->conn->prepare("SELECT id,recipe_name,serving_size,version_no FROM menu_recipes WHERE restaurant_id=? AND menu_item_id=? AND recipe_status='active' ORDER BY id DESC LIMIT 1");
        $recipeStmt->bind_param('ii', $restaurantId, $menuItemId);
        $recipeStmt->execute();
        $recipe = $recipeStmt->get_result()->fetch_assoc() ?: null;
        $recipeStmt->close();
        if (!$recipe) {
            $errors[] = 'Create an active recipe before submitting this menu.';
            return ['valid' => false, 'errors' => $errors, 'recipe' => null, 'availability' => null];
        }
        if (trim((string)$recipe['recipe_name']) === '') $errors[] = 'Recipe name is required.';
        if (trim((string)($recipe['serving_size'] ?? '')) === '') $errors[] = 'Recipe serving size is required.';

        $ingredients = $this->conn->prepare("SELECT ri.id,ri.ingredient_name,ri.inventory_id,ri.quantity_required,ri.unit,ri.yield_factor,ri.mapping_status,ki.unit AS inventory_unit
            FROM recipe_ingredients ri
            LEFT JOIN kitchen_inventory ki ON ki.id=ri.inventory_id AND ki.restaurant_id=ri.restaurant_id
            WHERE ri.recipe_id=? AND ri.restaurant_id=? ORDER BY ri.id");
        $recipeId = (int)$recipe['id'];
        $ingredients->bind_param('ii', $recipeId, $restaurantId);
        $ingredients->execute();
        $rows = $ingredients->get_result()->fetch_all(MYSQLI_ASSOC);
        $ingredients->close();
        if ($rows === []) $errors[] = 'Add at least one recipe ingredient before submitting.';
        foreach ($rows as $ingredient) {
            $name = trim((string)$ingredient['ingredient_name']) ?: 'An ingredient';
            $quantity = RecipeAvailabilityCalculator::quantityToScaled($ingredient['quantity_required']);
            $yield = RecipeAvailabilityCalculator::quantityToScaled($ingredient['yield_factor']);
            $unit = RecipeAvailabilityCalculator::normalizeUnit((string)$ingredient['unit']);
            if ($quantity === null || $quantity <= 0 || $yield === null || $yield <= 0 || $yield > 1000 || $unit === null) {
                $errors[] = "$name has an invalid quantity, yield, or unit.";
            }
            if (($ingredient['mapping_status'] ?? '') !== 'confirmed' || empty($ingredient['inventory_id']) || empty($ingredient['inventory_unit'])) {
                $errors[] = "$name still needs a confirmed inventory mapping.";
            } elseif (!RecipeAvailabilityCalculator::areCompatible((string)$ingredient['unit'], (string)$ingredient['inventory_unit'])) {
                $errors[] = "$name is mapped to an inventory unit that is not compatible with its recipe unit.";
            }
        }

        $availability = null;
        $inventoryService = new IngredientInventoryService($this->conn);
        if ($inventoryService->schemaReady()) {
            $availability = $inventoryService->availabilityForMenu($restaurantId, $menuItemId, true);
        }
        return ['valid' => $errors === [], 'errors' => array_values(array_unique($errors)), 'recipe' => $recipe, 'availability' => $availability];
    }

    /** @return array{success:bool,message:string,errors?:array<int,string>,availability?:array<string,mixed>} */
    public function submit(int $restaurantId, int $menuItemId, int $submittedBy): array
    {
        $this->conn->begin_transaction();
        try {
            $lock = $this->conn->prepare('SELECT id,name,workflow_status FROM menu_items WHERE id=? AND restaurant_id=? FOR UPDATE');
            $lock->bind_param('ii', $menuItemId, $restaurantId);
            $lock->execute();
            $menu = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$menu) throw new RuntimeException('Menu item not found or access denied.');
            if (($menu['workflow_status'] ?? '') === self::PENDING_REVIEW) {
                $this->conn->rollback();
                return ['success' => false, 'message' => 'This menu is already awaiting Operations Manager review.'];
            }
            $validation = $this->validateSubmission($restaurantId, $menuItemId);
            if (!$validation['valid']) {
                $this->conn->rollback();
                return ['success' => false, 'message' => 'Complete the required menu and recipe details before submitting.', 'errors' => $validation['errors'], 'availability' => $validation['availability'] ?? null];
            }
            $recipe = $validation['recipe'];
            $previous = (string)($menu['workflow_status'] ?? self::DRAFT);
            $submissionDecision = $previous === self::NEEDS_REVISION ? 'resubmitted' : 'submitted';
            $status = self::PENDING_REVIEW;
            $update = $this->conn->prepare("UPDATE menu_items SET approval_status='pending',workflow_status=?,workflow_updated_at=NOW(),submitted_by=?,reviewed_by=NULL,reviewed_at=NULL,rejection_reason=NULL WHERE id=? AND restaurant_id=?");
            $update->bind_param('siii', $status, $submittedBy, $menuItemId, $restaurantId);
            if (!$update->execute()) throw new RuntimeException('Could not submit menu for review.');
            $update->close();
            $recipeUpdate = $this->conn->prepare("UPDATE menu_recipes SET customer_visibility_status='draft',customer_visible_by=NULL,customer_visible_at=NULL,last_updated_by=? WHERE id=? AND restaurant_id=?");
            $recipeId = (int)$recipe['id'];
            $recipeUpdate->bind_param('iii', $submittedBy, $recipeId, $restaurantId);
            $recipeUpdate->execute();
            $recipeUpdate->close();
            $this->history($restaurantId, $menuItemId, $recipeId, (int)$recipe['version_no'], $submittedBy, null, $submissionDecision, null, true);
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            return ['success' => false, 'message' => 'Could not submit this menu for review.'];
        }

        $this->notifyReviewers($restaurantId, $submittedBy, (string)$menu['name']);
        logActivity($this->conn, $submittedBy, 'Menu submitted for approval', json_encode(['restaurant_id' => $restaurantId, 'menu_item_id' => $menuItemId, 'recipe_id' => (int)$recipe['id']]), 'menu_workflow');
        return ['success' => true, 'message' => 'Menu submitted to the Operations Manager for review.', 'availability' => $validation['availability'] ?? null];
    }

    /** @return array{success:bool,message:string,errors?:array<int,string>} */
    public function review(int $restaurantId, int $menuItemId, int $reviewedBy, string $action, string $remarks, bool $allowOwnerOverride): array
    {
        if (!in_array($action, ['approve', 'needs_revision', 'reject'], true)) return ['success' => false, 'message' => 'Invalid review action.'];
        $remarks = mb_substr(trim(strip_tags($remarks)), 0, 1000, 'UTF-8');
        if (in_array($action, ['needs_revision', 'reject'], true) && $remarks === '') return ['success' => false, 'message' => 'Review feedback is required for this action.'];

        $this->conn->begin_transaction();
        try {
            $lock = $this->conn->prepare('SELECT id,name,submitted_by,approval_status,workflow_status FROM menu_items WHERE id=? AND restaurant_id=? FOR UPDATE');
            $lock->bind_param('ii', $menuItemId, $restaurantId);
            $lock->execute();
            $menu = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$menu) throw new RuntimeException('Menu item not found or access denied.');
            if (($menu['workflow_status'] ?? '') !== self::PENDING_REVIEW) {
                $this->conn->rollback();
                return ['success' => false, 'message' => 'Only submitted menus can be reviewed.'];
            }
            if ((int)($menu['submitted_by'] ?? 0) === $reviewedBy && !$allowOwnerOverride) {
                $this->conn->rollback();
                return ['success' => false, 'message' => 'Operations Managers cannot approve their own menu submission.'];
            }
            $validation = $this->validateSubmission($restaurantId, $menuItemId);
            if ($action === 'approve' && !$validation['valid']) {
                $this->conn->rollback();
                return ['success' => false, 'message' => 'The submitted recipe is no longer complete.', 'errors' => $validation['errors']];
            }
            $recipe = $validation['recipe'];
            if (!$recipe) throw new RuntimeException('Submitted recipe is missing.');
            $workflow = $action === 'approve' ? self::APPROVED : ($action === 'needs_revision' ? self::NEEDS_REVISION : self::REJECTED);
            $approval = $action === 'approve' ? 'approved' : ($action === 'reject' ? 'rejected' : 'pending');
            $availability = $validation['availability'] ?? null;
            $isOrderable = 1;
            if ($action === 'approve' && is_array($availability)) {
                $operational = (string)($availability['operational_status'] ?? 'inventory_not_confirmed');
                $evidence = (string)($availability['ingredient_evidence_status'] ?? 'pending_review');
                $isOrderable = in_array($operational, ['available', 'limited_stock'], true)
                    && !in_array($evidence, ['missing', 'expired', 'inconclusive', 'requires_clarification'], true) ? 1 : 0;
            }
            $menuUpdate = $this->conn->prepare('UPDATE menu_items SET approval_status=?,workflow_status=?,workflow_updated_at=NOW(),reviewed_by=?,reviewed_at=NOW(),rejection_reason=?,is_available=CASE WHEN ?=\'approve\' THEN ? ELSE is_available END WHERE id=? AND restaurant_id=?');
            $reason = $action === 'approve' ? null : $remarks;
            $menuUpdate->bind_param('ssissiii', $approval, $workflow, $reviewedBy, $reason, $action, $isOrderable, $menuItemId, $restaurantId);
            if (!$menuUpdate->execute()) throw new RuntimeException('Could not save menu review.');
            $menuUpdate->close();
            $visibility = $action === 'approve' ? 'published' : 'draft';
            $recipeUpdate = $this->conn->prepare('UPDATE menu_recipes SET customer_visibility_status=?,customer_visible_by=?,customer_visible_at=CASE WHEN ?=\'published\' THEN NOW() ELSE NULL END,last_updated_by=? WHERE id=? AND restaurant_id=?');
            $recipeId = (int)$recipe['id'];
            $visibleBy = $action === 'approve' ? $reviewedBy : null;
            $recipeUpdate->bind_param('sisiii', $visibility, $visibleBy, $visibility, $reviewedBy, $recipeId, $restaurantId);
            if (!$recipeUpdate->execute()) throw new RuntimeException('Could not update recipe publication state.');
            $recipeUpdate->close();
            $this->history($restaurantId, $menuItemId, $recipeId, (int)$recipe['version_no'], (int)($menu['submitted_by'] ?? 0) ?: null, $reviewedBy, $action, $remarks, false);
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            return ['success' => false, 'message' => 'Could not record the menu review.'];
        }

        $submittedBy = (int)($menu['submitted_by'] ?? 0);
        $title = match ($action) {
            'approve' => 'Menu approved',
            'needs_revision' => 'Menu needs revision',
            default => 'Menu rejected',
        };
        $message = match ($action) {
            'approve' => '"' . $menu['name'] . '" was approved and its reviewed recipe is eligible for visibility.',
            'needs_revision' => '"' . $menu['name'] . '" was returned for revision. ' . $remarks,
            default => '"' . $menu['name'] . '" was rejected. ' . $remarks,
        };
        if ($submittedBy > 0 && $submittedBy !== $reviewedBy) createNotification($this->conn, $submittedBy, $title, $message, $action === 'approve' ? 'success' : 'warning', BASE_URL . 'dashboard/business_owner/chef_dashboard.php?tab=menu_submit');
        logActivity($this->conn, $reviewedBy, 'Menu ' . str_replace('_', ' ', $action), json_encode(['restaurant_id' => $restaurantId, 'menu_item_id' => $menuItemId, 'remarks' => $remarks, 'owner_override' => $allowOwnerOverride]), 'menu_workflow');
        return ['success' => true, 'message' => $message];
    }

    public function markMenuChanged(int $restaurantId, int $menuItemId, int $userId): void
    {
        $state = self::DRAFT;
        $menu = $this->conn->prepare("UPDATE menu_items SET approval_status='pending',workflow_status=?,workflow_updated_at=NOW(),reviewed_by=NULL,reviewed_at=NULL WHERE id=? AND restaurant_id=? AND workflow_status<>'pending_review'");
        $menu->bind_param('sii', $state, $menuItemId, $restaurantId);
        $menu->execute();
        $menu->close();
        $recipes = $this->conn->prepare("UPDATE menu_recipes SET customer_visibility_status=CASE WHEN customer_visibility_status='published' THEN 'needs_reapproval' ELSE 'draft' END,customer_visible_by=NULL,customer_visible_at=NULL,last_updated_by=? WHERE restaurant_id=? AND menu_item_id=? AND recipe_status='active'");
        $recipes->bind_param('iii', $userId, $restaurantId, $menuItemId);
        $recipes->execute();
        $recipes->close();
    }

    /** @return array{success:bool,message:string} */
    public function deleteChefMenu(int $restaurantId, int $menuItemId, int $userId, bool $ownerOverride): array
    {
        $this->conn->begin_transaction();
        try {
            $lock = $this->conn->prepare('SELECT id,name,submitted_by,approval_status,workflow_status FROM menu_items WHERE id=? AND restaurant_id=? FOR UPDATE');
            $lock->bind_param('ii', $menuItemId, $restaurantId);
            $lock->execute();
            $menu = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$menu) throw new RuntimeException('Menu item not found or access denied.');

            $status = (string)($menu['workflow_status'] ?? self::LEGACY);
            $isLive = $status === self::APPROVED
                || ($status === self::LEGACY && ($menu['approval_status'] ?? '') === 'approved');
            if (!$isLive && !in_array($status, [self::DRAFT, self::NEEDS_REVISION, self::REJECTED], true)) {
                $this->conn->rollback();
                return ['success' => false, 'message' => 'This menu cannot be deleted while it is awaiting review.'];
            }
            $submittedBy = (int)($menu['submitted_by'] ?? 0);
            if (!$ownerOverride && $submittedBy > 0 && $submittedBy !== $userId) {
                $this->conn->rollback();
                return ['success' => false, 'message' => 'You can delete only menus that you created.'];
            }

            if ($isLive) {
                // Retain order and approval history, but immediately take the item out of every ordering surface.
                $removed = self::DELETED;
                $menuUpdate = $this->conn->prepare("UPDATE menu_items SET approval_status='rejected',workflow_status=?,workflow_updated_at=NOW(),is_available=0,rejection_reason='Removed from live ordering by Chef.' WHERE id=? AND restaurant_id=?");
                $menuUpdate->bind_param('sii', $removed, $menuItemId, $restaurantId);
                if (!$menuUpdate->execute() || $menuUpdate->affected_rows !== 1) throw new RuntimeException('Could not remove live menu from ordering.');
                $menuUpdate->close();
                $recipeUpdate = $this->conn->prepare("UPDATE menu_recipes SET customer_visibility_status='draft',customer_visible_by=NULL,customer_visible_at=NULL,last_updated_by=? WHERE restaurant_id=? AND menu_item_id=? AND recipe_status='active'");
                $recipeUpdate->bind_param('iii', $userId, $restaurantId, $menuItemId);
                if (!$recipeUpdate->execute()) throw new RuntimeException('Could not unpublish the menu recipe.');
                $recipeUpdate->close();
                $this->conn->commit();

                logActivity($this->conn, $userId, 'Live menu deleted', json_encode([
                    'restaurant_id' => $restaurantId,
                    'menu_item_id' => $menuItemId,
                    'menu_name' => $menu['name'],
                    'owner_override' => $ownerOverride,
                ]), 'menu_workflow');
                return ['success' => true, 'message' => 'Menu removed from live customer and POS ordering.'];
            }

            // The legacy mapping table is created lazily in older installations.
            // Remove mappings when it exists, while still allowing empty drafts to be deleted.
            $legacyTable = $this->conn->query("SHOW TABLES LIKE 'menu_recipe_ingredients'");
            $hasLegacyMappings = $legacyTable instanceof mysqli_result && $legacyTable->num_rows > 0;
            if ($legacyTable instanceof mysqli_result) $legacyTable->free();
            if ($hasLegacyMappings) {
                $legacy = $this->conn->prepare('DELETE FROM menu_recipe_ingredients WHERE menu_item_id=?');
                if (!$legacy) throw new RuntimeException('Could not remove legacy menu mappings.');
                $legacy->bind_param('i', $menuItemId);
                if (!$legacy->execute()) throw new RuntimeException('Could not remove legacy menu mappings.');
                $legacy->close();
            }
            $recipes = $this->conn->prepare('DELETE FROM menu_recipes WHERE restaurant_id=? AND menu_item_id=?');
            $recipes->bind_param('ii', $restaurantId, $menuItemId);
            if (!$recipes->execute()) throw new RuntimeException('Could not remove menu recipes.');
            $recipes->close();
            $delete = $this->conn->prepare('DELETE FROM menu_items WHERE id=? AND restaurant_id=?');
            $delete->bind_param('ii', $menuItemId, $restaurantId);
            if (!$delete->execute() || $delete->affected_rows !== 1) throw new RuntimeException('Could not delete menu item.');
            $delete->close();
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            return ['success' => false, 'message' => 'Could not delete the menu item.'];
        }

        logActivity($this->conn, $userId, 'Menu deleted', json_encode([
            'restaurant_id' => $restaurantId,
            'menu_item_id' => $menuItemId,
            'menu_name' => $menu['name'],
            'owner_override' => $ownerOverride,
        ]), 'menu_workflow');
        return ['success' => true, 'message' => 'Menu deleted.'];
    }
    /** @param ?int $submittedBy */
    private function history(int $restaurantId, int $menuItemId, int $recipeId, int $versionNo, ?int $submittedBy, ?int $reviewedBy, string $decision, ?string $remarks, bool $isSubmission): void
    {
        $stmt = $this->conn->prepare('INSERT INTO menu_item_approval_history (restaurant_id,menu_item_id,recipe_id,recipe_version_no,submitted_by,reviewed_by,decision,remarks,submitted_at,reviewed_at) VALUES (?,?,?,?,?,?,?,?,CASE WHEN ? THEN NOW() ELSE NULL END,CASE WHEN ? THEN NULL ELSE NOW() END)');
        $submittedFlag = $isSubmission ? 1 : 0;
        $stmt->bind_param('iiiiiissii', $restaurantId, $menuItemId, $recipeId, $versionNo, $submittedBy, $reviewedBy, $decision, $remarks, $submittedFlag, $submittedFlag);
        if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
        $stmt->close();
    }

    private function notifyReviewers(int $restaurantId, int $submittedBy, string $menuName): void
    {
        $targets = [];
        $owner = $this->conn->prepare('SELECT business_owner_id FROM halal_restaurants WHERE id=?');
        $owner->bind_param('i', $restaurantId); $owner->execute();
        $ownerId = (int)($owner->get_result()->fetch_assoc()['business_owner_id'] ?? 0); $owner->close();
        if ($ownerId > 0) $targets[] = $ownerId;
        $ops = $this->conn->prepare("SELECT rs.user_id FROM restaurant_staff rs JOIN restaurant_staff_roles rsr ON rsr.id=rs.staff_role_id WHERE rs.restaurant_id=? AND rs.is_active=1 AND rsr.role_key='ops_manager'");
        $ops->bind_param('i', $restaurantId); $ops->execute();
        foreach ($ops->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $targets[] = (int)$row['user_id'];
        $ops->close();
        foreach (array_unique($targets) as $target) {
            if ($target !== $submittedBy) createNotification($this->conn, $target, 'Menu awaiting review', '"' . $menuName . '" has a completed recipe ready for Operations Manager review.', 'action_required', BASE_URL . 'dashboard/business_owner/operations_manager.php?tab=menu');
        }
    }
}
