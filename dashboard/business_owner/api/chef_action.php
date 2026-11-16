<?php
/**
 * Chef Action API
 * Handles AJAX actions for chef_dashboard.php and operations_manager.php:
 * - Inventory intake & supplier verification  (Chef)
 * - Ingredient requisitions to Operations Manager  (Chef)
 * - Requisition approve / reject + amount-spent logging  (Ops Manager)
 * - Chef receive-and-inspect flow after approval  (Chef)
 * - Menu recipe ingredient mapping  (Chef)
 * - Automated POS availability status guardrail checks
 */
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../includes/IngredientInventoryService.php';
require_once __DIR__ . '/../../../includes/RevenuePaymentService.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]); // Business owner OR staff with role access checked per-action

header('Content-Type: application/json; charset=utf-8');

$user_id = (int)$_SESSION['user_id'];
$rest_id = getSessionRestaurantId($conn);

function chef_json(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit();
}

function chef_require_role(string $required_role): void {
    $staff_key = $_SESSION['staff_role_key'] ?? null;
    if ($staff_key === null) {
        return;
    }
    if ($staff_key !== $required_role) {
        chef_json(false, 'Access denied for this action.');
    }
}

function notify_ops_managers(mysqli $conn, int $restaurant_id, string $title, string $message, string $link): void {
    $ops_key = STAFF_ROLE_OPS_MANAGER;
    $stmt = $conn->prepare("
        SELECT rs.user_id
        FROM restaurant_staff rs
        JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
        WHERE rs.restaurant_id = ? AND rsr.role_key = ? AND rs.is_active = 1
    ");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('is', $restaurant_id, $ops_key);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        createNotification($conn, (int)$row['user_id'], $title, $message, 'info', $link);
    }
    $stmt->close();

    $owner_stmt = $conn->prepare('SELECT business_owner_id FROM halal_restaurants WHERE id = ? LIMIT 1');
    if ($owner_stmt) {
        $owner_stmt->bind_param('i', $restaurant_id);
        $owner_stmt->execute();
        $owner_row = $owner_stmt->get_result()->fetch_assoc();
        $owner_stmt->close();
        if ($owner_row) {
            createNotification($conn, (int)$owner_row['business_owner_id'], $title, $message, 'info', $link);
        }
    }
}

if (!$rest_id) {
    chef_json(false, 'No active restaurant associated with your account.');
}

// ── Idempotent column: expiration_date (replaces manufacturer_brand) ──────
db_add_column($conn, 'kitchen_inventory', 'expiration_date', 'DATE DEFAULT NULL');

// ── Idempotent Table Migrations ───────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS `halal_meat_suppliers` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `restaurant_id`       INT(11) NOT NULL,
    `supplier_name`       VARCHAR(150) NOT NULL,
    `contact_person`      VARCHAR(100) DEFAULT NULL,
    `phone`               VARCHAR(50)  DEFAULT NULL,
    `email`               VARCHAR(100) DEFAULT NULL,
    `halal_cert_number`   VARCHAR(100) DEFAULT NULL,
    `halal_cert_expiry`   DATE DEFAULT NULL,
    `verification_status` ENUM('verified','pending','rejected') NOT NULL DEFAULT 'pending',
    `verified_by`         INT(11) DEFAULT NULL,
    `verified_at`         DATETIME DEFAULT NULL,
    `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hms_rest` (`restaurant_id`),
    KEY `idx_hms_status` (`verification_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `kitchen_inventory` (
    `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `restaurant_id`        INT(11) NOT NULL,
    `logged_by`            INT(11) NOT NULL,
    `ingredient_name`      VARCHAR(150) NOT NULL,
    `expiration_date`      DATE DEFAULT NULL,
    `halal_status`         ENUM('halal','non_halal','pending_cert','self_certified','fresh_produce') NOT NULL DEFAULT 'pending_cert',
    `is_meat`              TINYINT(1) NOT NULL DEFAULT 0,
    `supplier_id`          INT UNSIGNED DEFAULT NULL,
    `quantity`             DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    `unit`                 VARCHAR(30) NOT NULL DEFAULT 'kg',
    `low_stock_threshold`  DECIMAL(10,3) NOT NULL DEFAULT 5.000,
    `notes`                TEXT DEFAULT NULL,
    `created_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ki_rest` (`restaurant_id`),
    KEY `idx_ki_status` (`halal_status`),
    KEY `idx_ki_supplier` (`supplier_id`),
    CONSTRAINT `fk_ki_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `halal_meat_suppliers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `ingredient_requests` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `restaurant_id` INT(11) NOT NULL,
    `requested_by`  INT(11) NOT NULL,
    `status`        ENUM('pending','acknowledged','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending',
    `notes`         TEXT DEFAULT NULL,
    `ops_notes`     TEXT DEFAULT NULL,
    `approved_by`   INT(11) DEFAULT NULL,
    `approved_at`   DATETIME DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ir_rest` (`restaurant_id`),
    KEY `idx_ir_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Idempotent column additions for ingredient_requests
db_add_column($conn, 'ingredient_requests', 'approved_by', 'INT(11) DEFAULT NULL');
db_add_column($conn, 'ingredient_requests', 'approved_at', 'DATETIME DEFAULT NULL');
// Extend status enum to include 'approved'
$conn->query("ALTER TABLE `ingredient_requests` MODIFY `status`
    ENUM('pending','acknowledged','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending'");

// Grocery List Recipes: store recipe files uploaded by ops manager
$conn->query("CREATE TABLE IF NOT EXISTS `grocery_list_recipes` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `grocery_list_id` INT UNSIGNED NOT NULL,
    `uploaded_by`     INT(11) NOT NULL,
    `file_name`       VARCHAR(255) NOT NULL,
    `file_path`       VARCHAR(500) NOT NULL,
    `file_size`       INT UNSIGNED NOT NULL,
    `file_type`       VARCHAR(50)  NOT NULL,
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_glr_gl` (`grocery_list_id`),
    CONSTRAINT `fk_glr_gl` FOREIGN KEY (`grocery_list_id`) REFERENCES `grocery_lists`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// kitchen_inventory: inspection FK + halal workflow columns
db_add_column($conn, 'kitchen_inventory', 'grocery_list_id',        'INT UNSIGNED DEFAULT NULL');
db_add_column($conn, 'kitchen_inventory', 'grocery_list_item_id',   'INT UNSIGNED DEFAULT NULL');
db_add_column($conn, 'kitchen_inventory', 'category_id',            'INT UNSIGNED DEFAULT NULL');
db_add_column($conn, 'kitchen_inventory', 'purchase_status',        "VARCHAR(30) NOT NULL DEFAULT 'active'");
db_add_column($conn, 'kitchen_inventory', 'qty_purchased',          'DECIMAL(10,3) DEFAULT NULL');
db_add_column($conn, 'kitchen_inventory', 'unit_cost',              'DECIMAL(10,2) DEFAULT NULL');
db_add_column($conn, 'kitchen_inventory', 'halal_proof_image',      'VARCHAR(500)  DEFAULT NULL');
db_add_column($conn, 'kitchen_inventory', 'halal_cert_ref',         'VARCHAR(200)  DEFAULT NULL');
db_add_column($conn, 'kitchen_inventory', 'self_cert_note',         'VARCHAR(255)  DEFAULT NULL');
db_add_column($conn, 'kitchen_inventory', 'expiry_date',            'DATE          DEFAULT NULL');
db_add_column($conn, 'kitchen_inventory', 'actual_purchase_date',   'DATE          DEFAULT NULL');
db_add_column($conn, 'kitchen_inventory', 'source_request_item_id', 'INT UNSIGNED DEFAULT NULL');
$conn->query("ALTER TABLE `kitchen_inventory` MODIFY COLUMN `halal_status`
    ENUM('halal','non_halal','pending_cert','self_certified') NOT NULL DEFAULT 'pending_cert'");
db_add_unique_key($conn, 'kitchen_inventory', 'idx_ki_gl',  ['grocery_list_id']);
db_add_unique_key($conn, 'kitchen_inventory', 'idx_ki_gli', ['grocery_list_item_id']);
db_add_unique_key($conn, 'kitchen_inventory', 'idx_ki_cat', ['category_id']);

// Drop-then-re-add FK constraints safely
$conn->query("SET FOREIGN_KEY_CHECKS=0");
db_drop_fk($conn, 'kitchen_inventory', 'fk_ki_grocery_list');
db_drop_fk($conn, 'kitchen_inventory', 'fk_ki_grocery_item');
db_drop_fk($conn, 'kitchen_inventory', 'fk_ki_category');
db_add_fk($conn, 'kitchen_inventory', 'fk_ki_grocery_list', 'grocery_list_id',      'grocery_lists',       'id', 'SET NULL');
db_add_fk($conn, 'kitchen_inventory', 'fk_ki_grocery_item', 'grocery_list_item_id', 'grocery_list_items',  'id', 'SET NULL');
db_add_fk($conn, 'kitchen_inventory', 'fk_ki_category',     'category_id',          'ingredients_category','id', 'SET NULL');
$conn->query("SET FOREIGN_KEY_CHECKS=1");


$conn->query("CREATE TABLE IF NOT EXISTS `ingredient_request_items` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id`      INT UNSIGNED NOT NULL,
    `ingredient_name` VARCHAR(150) NOT NULL,
    `quantity`        DECIMAL(10,3) NOT NULL DEFAULT 1.000,
    `unit`            VARCHAR(30) NOT NULL DEFAULT 'kg',
    `notes`           VARCHAR(255) DEFAULT NULL,
    `estimated_cost`  DECIMAL(10,2) DEFAULT NULL,
    `target_date`     DATE DEFAULT NULL,
    `amount_spent`    DECIMAL(10,2) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_iri_req` (`request_id`),
    CONSTRAINT `fk_iri_req` FOREIGN KEY (`request_id`) REFERENCES `ingredient_requests`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Idempotent column additions for ingredient_request_items
db_add_column($conn, 'ingredient_request_items', 'estimated_cost', 'DECIMAL(10,2) DEFAULT NULL');
db_add_column($conn, 'ingredient_request_items', 'target_date',    'DATE DEFAULT NULL');
db_add_column($conn, 'ingredient_request_items', 'amount_spent',   'DECIMAL(10,2) DEFAULT NULL');

db_add_column($conn, 'menu_items', 'curated_by_chef', 'TINYINT(1) NOT NULL DEFAULT 0');
db_add_column($conn, 'menu_items', 'chef_curated_at', 'DATETIME DEFAULT NULL');
db_add_column($conn, 'menu_items', 'updated_at',      'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');

$conn->query("CREATE TABLE IF NOT EXISTS `menu_recipe_ingredients` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `menu_item_id`    INT(11) NOT NULL,
    `inventory_id`    INT UNSIGNED NOT NULL,
    `quantity_needed` DECIMAL(10,3) NOT NULL DEFAULT 1.000,
    `unit`            VARCHAR(30) NOT NULL DEFAULT 'g',
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_menu_inv` (`menu_item_id`, `inventory_id`),
    KEY `idx_mri_menu` (`menu_item_id`),
    KEY `idx_mri_inv` (`inventory_id`),
    CONSTRAINT `fk_mri_inv` FOREIGN KEY (`inventory_id`) REFERENCES `kitchen_inventory`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Resolve action from POST, GET, or a JSON body (fetch() with Content-Type: application/json)
$_json_body = [];
$_ct = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($_ct, 'application/json') !== false) {
    $_json_body = json_decode(file_get_contents('php://input'), true) ?? [];
}
$action = trim($_POST['action'] ?? $_GET['action'] ?? ($_json_body['action'] ?? ''));
// ── Pantry Category table ─────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS `ingredients_category` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `restaurant_id` INT(11) NOT NULL,
    `parent_id`     INT UNSIGNED DEFAULT NULL,
    `name`          VARCHAR(100) NOT NULL,
    `sort_order`    SMALLINT NOT NULL DEFAULT 0,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ic_rest`   (`restaurant_id`),
    KEY `idx_ic_parent` (`parent_id`),
    CONSTRAINT `fk_ic_parent` FOREIGN KEY (`parent_id`)
        REFERENCES `ingredients_category`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `grocery_lists` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `restaurant_id` INT(11) NOT NULL,
    `created_by`    INT(11) NOT NULL,
    `title`         VARCHAR(150) NOT NULL DEFAULT 'Grocery List',
    `notes`         TEXT DEFAULT NULL,
    `ops_notes`     TEXT DEFAULT NULL,
    `status`        ENUM('pending','acknowledged','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending',
    `approved_by`   INT(11) DEFAULT NULL,
    `approved_at`   DATETIME DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_gl_rest`   (`restaurant_id`),
    KEY `idx_gl_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `grocery_list_items` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `grocery_list_id` INT UNSIGNED NOT NULL,
    `category_id`     INT UNSIGNED DEFAULT NULL,
    `ingredient_name` VARCHAR(150) NOT NULL,
    `quantity`        DECIMAL(10,3) NOT NULL DEFAULT 1.000,
    `unit`            VARCHAR(30) NOT NULL DEFAULT 'kg',
    `estimated_cost`  DECIMAL(10,2) DEFAULT NULL,
    `amount_spent`    DECIMAL(10,2) DEFAULT NULL,
    `target_date`     DATE DEFAULT NULL,
    `notes`           VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_gli_list` (`grocery_list_id`),
    KEY `idx_gli_cat`  (`category_id`),
    CONSTRAINT `fk_gli_list` FOREIGN KEY (`grocery_list_id`)
        REFERENCES `grocery_lists`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_gli_cat` FOREIGN KEY (`category_id`)
        REFERENCES `ingredients_category`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Helper: sync menu POS availability based on inventory & Halal status
function syncMenuAvailability(mysqli $conn, int $restaurant_id): array {
    $menu_res = $conn->query("SELECT id, name, is_available, price, curated_by_chef FROM menu_items WHERE restaurant_id = {$restaurant_id}");
    if (!$menu_res) return ['updated' => 0, 'details' => []];

    $menu_items = $menu_res->fetch_all(MYSQLI_ASSOC);
    $updated_count = 0;
    $details = [];

    foreach ($menu_items as $mi) {
        $mid = (int)$mi['id'];
        $rec_stmt = $conn->prepare("
            SELECT mri.quantity_needed, ki.ingredient_name, ki.quantity AS stock,
                   ki.halal_status, ki.purchase_status, ki.is_meat, ki.supplier_id,
                   hms.verification_status AS supplier_status, hms.supplier_name
            FROM menu_recipe_ingredients mri
            JOIN kitchen_inventory ki ON mri.inventory_id = ki.id
            LEFT JOIN halal_meat_suppliers hms ON ki.supplier_id = hms.id
            WHERE mri.menu_item_id = ?
        ");
        $rec_stmt->bind_param("i", $mid);
        $rec_stmt->execute();
        $ingredients = $rec_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $rec_stmt->close();

        // No recipe mapped — leave is_available exactly as the chef set it
        if (empty($ingredients)) {
            $details[$mid] = ['name' => $mi['name'], 'available' => (bool)$mi['is_available'], 'reason' => 'No recipe rules set'];
            continue;
        }

        // Only auto-disable when stock is physically depleted or a meat ingredient
        // has no verified supplier. Never auto-enable — that is the chef's call.
        $must_disable = false;
        $reasons      = [];

        foreach ($ingredients as $ing) {
            // Out of physical stock
            if ((float)$ing['stock'] <= 0) {
                $must_disable = true;
                $reasons[]    = "'{$ing['ingredient_name']}' is out of stock";
            }
            // Meat with unverified supplier
            if ((int)$ing['is_meat'] === 1 &&
                (empty($ing['supplier_id']) || ($ing['supplier_status'] ?? '') !== 'verified')) {
                $must_disable = true;
                $sup_name     = $ing['supplier_name'] ?: 'unassigned supplier';
                $reasons[]    = "Meat '{$ing['ingredient_name']}' linked to unverified supplier '{$sup_name}'";
            }
        }

        // If the item must be disabled, force it off regardless of manual state.
        // If it doesn't need to be disabled, leave the chef's manual toggle alone.
        if ($must_disable && (int)$mi['is_available'] !== 0) {
            $zero = 0;
            $upd  = $conn->prepare("UPDATE menu_items SET is_available = 0 WHERE id = ?");
            $upd->bind_param("i", $mid);
            $upd->execute();
            $upd->close();
            $updated_count++;
            $details[$mid] = ['name' => $mi['name'], 'available' => false, 'reasons' => $reasons];
        } else {
            $details[$mid] = ['name' => $mi['name'], 'available' => (bool)$mi['is_available'], 'reasons' => $reasons];
        }
    }

    return ['updated' => $updated_count, 'details' => $details];
}

// ── ACTION HANDLERS ────────────────────────────────────────────────────────

// 1. SAVE INVENTORY ITEM (Chef)
if ($action === 'save_inventory') {
    $inv_id          = (int)($_POST['inventory_id'] ?? 0);
    $ing_name        = trim(strip_tags($_POST['ingredient_name'] ?? ''));
    $expiration_date = !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : null;
    $halal_status    = trim(strip_tags($_POST['halal_status'] ?? 'pending_cert'));
    $is_meat         = isset($_POST['is_meat']) ? 1 : 0;
    $supplier_id     = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
    $qty             = (float)($_POST['quantity'] ?? 0);
    $unit            = trim(strip_tags($_POST['unit'] ?? 'kg'));
    $threshold       = (float)($_POST['low_stock_threshold'] ?? 5);
    $notes           = trim(strip_tags($_POST['notes'] ?? ''));

    if ($ing_name === '') chef_json(false, 'Ingredient name is required.');
    if (!in_array($halal_status, ['halal','non_halal','pending_cert','self_certified','fresh_produce'], true)) {
        $halal_status = 'pending_cert';
    }

    if ($is_meat && $supplier_id) {
        $sup_chk = $conn->prepare("SELECT id FROM halal_meat_suppliers WHERE id = ? AND restaurant_id = ? LIMIT 1");
        $sup_chk->bind_param("ii", $supplier_id, $rest_id);
        $sup_chk->execute();
        if (!$sup_chk->get_result()->fetch_assoc()) {
            $sup_chk->close();
            chef_json(false, 'Selected meat supplier is invalid.');
        }
        $sup_chk->close();
    }

    if ($inv_id > 0) {
        // The master inventory form is the source for a legacy inventory
        // record. Keep its one-time batch snapshot in the same transaction so
        // customer reservations never use stale quantities or expiry dates.
        $inventoryService = null;
        if (IngredientInventoryService::isFeatureEnabled()) {
            $candidateService = new IngredientInventoryService($conn);
            if ($candidateService->schemaReady()) $inventoryService = $candidateService;
        }
        if ($inventoryService !== null) $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("
                UPDATE kitchen_inventory
                SET ingredient_name=?, expiration_date=?, expiry_date=?, halal_status=?, is_meat=?,
                    supplier_id=?, quantity=?, unit=?, low_stock_threshold=?, notes=?, updated_at=NOW()
                WHERE id=? AND restaurant_id=?
            ");
            if (!$stmt) throw new RuntimeException('Unable to prepare the inventory update.');
            $stmt->bind_param("ssssiidsdsii",
                $ing_name, $expiration_date, $expiration_date, $halal_status, $is_meat,
                $supplier_id, $qty, $unit, $threshold, $notes, $inv_id, $rest_id);
            $ok = $stmt->execute();
            $error = $stmt->error;
            $stmt->close();
            if (!$ok) throw new RuntimeException($error ?: 'Unable to update inventory.');

            if ($inventoryService !== null) {
                $inventoryService->synchronizeLegacyBatchFromInventory(
                    $rest_id, $inv_id, (string)$qty, $unit, $expiration_date, $user_id
                );
                $conn->commit();
            }
        } catch (Throwable $e) {
            if ($inventoryService !== null) $conn->rollback();
            chef_json(false, $e->getMessage());
        }
        $msg = 'Inventory item updated.';
    } else {
        // 12 params: i,i,s,s,s,s,i,i,d,s,d,s
        // Count logical ingredient records, not batches. A verified expansion pack is required at capacity.
        $billing = new RevenuePaymentService($conn);
        if ($billing->schemaReady()) {
            $slot = $billing->assertInventorySlotAvailable($rest_id);
            if (!$slot['success']) chef_json(false, $slot['message'], ['capacity' => $slot['capacity']]);
        }
        $stmt = $conn->prepare("
            INSERT INTO kitchen_inventory
                (restaurant_id, logged_by, ingredient_name, expiration_date, expiry_date, halal_status,
                 is_meat, supplier_id, quantity, unit, low_stock_threshold, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iissssiidsds",
            $rest_id, $user_id, $ing_name, $expiration_date, $expiration_date, $halal_status,
            $is_meat, $supplier_id, $qty, $unit, $threshold, $notes);
        $ok  = $stmt->execute();
        $new_inventory_id = $ok ? (int)$conn->insert_id : 0;
        $stmt->close();
        $msg = 'Ingredient added to kitchen inventory.';

        // The legacy Inventory form remains the source for the ingredient
        // master record. When the additive mapping schema is enabled, record
        // the initial quantity as a traceable batch as well. Updates to an
        // existing record are intentionally not treated as a new purchase.
        if ($ok && $new_inventory_id > 0 && IngredientInventoryService::isFeatureEnabled()) {
            $inventoryService = new IngredientInventoryService($conn);
            if ($inventoryService->schemaReady()) {
                $batch = $conn->prepare("INSERT INTO ingredient_inventory_batches
                    (restaurant_id, inventory_id, batch_code, quantity_received, quantity_available, unit,
                     purchase_date, expiration_date, source_type, batch_status, evidence_status,
                     recorded_by, last_updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?, 'purchase', 'active', 'pending_review', ?, ?)");
                if (!$batch) {
                    error_log('Ingredient inventory batch prepare failed for inventory ' . $new_inventory_id . ': ' . $conn->error);
                    $msg = 'Ingredient added, but its traceable batch needs to be recorded before it can be used for availability.';
                } else {
                    $batchCode = 'INV-' . $new_inventory_id . '-' . date('YmdHis');
                    $batch->bind_param('iisssssii', $rest_id, $new_inventory_id, $batchCode, $qty, $qty, $unit, $expiration_date, $user_id, $user_id);
                    $batchOk = $batch->execute();
                    $batchId = $batchOk ? (int)$conn->insert_id : 0;
                    $batchError = $batch->error;
                    $batch->close();
                    if (!$batchOk) {
                        error_log('Ingredient inventory batch insert failed for inventory ' . $new_inventory_id . ': ' . $batchError);
                        $msg = 'Ingredient added, but its traceable batch needs to be recorded before it can be used for availability.';
                    } else {
                        $movement = $conn->prepare("INSERT INTO ingredient_stock_movements
                            (restaurant_id, batch_id, movement_type, quantity, unit, reference_note, idempotency_key, recorded_by)
                            VALUES (?, ?, 'receipt', ?, ?, 'Initial inventory log', ?, ?)");
                        if ($movement) {
                            $idempotencyKey = 'initial-inventory:' . $batchId;
                            $movement->bind_param('iisssi', $rest_id, $batchId, $qty, $unit, $idempotencyKey, $user_id);
                            $movement->execute();
                            $movement->close();
                        }
                        $msg = 'Ingredient added with a traceable batch pending evidence review.';
                    }
                }
            }
        }
    }

    syncMenuAvailability($conn, $rest_id);
    chef_json($ok, $msg);
}

// 2. DELETE INVENTORY ITEM (Chef)
if ($action === 'delete_inventory') {
    $inv_id = (int)($_POST['inventory_id'] ?? 0);
    if (!$inv_id) chef_json(false, 'Missing inventory ID.');
    $del = $conn->prepare("DELETE FROM kitchen_inventory WHERE id = ? AND restaurant_id = ?");
    $del->bind_param("ii", $inv_id, $rest_id);
    $ok = $del->execute();
    $del->close();
    syncMenuAvailability($conn, $rest_id);
    chef_json($ok, 'Inventory item deleted.');
}

// 3. SUBMIT REQUISITION (Chef → Ops Manager)
if ($action === 'submit_requisition') {
    $items = $_POST['items'] ?? [];
    $notes = trim(strip_tags($_POST['notes'] ?? ''));

    if (empty($items) || !is_array($items)) {
        chef_json(false, 'Please add at least one ingredient line item.');
    }

    $conn->begin_transaction();
    try {
        $req_stmt = $conn->prepare("INSERT INTO ingredient_requests (restaurant_id, requested_by, notes) VALUES (?, ?, ?)");
        $req_stmt->bind_param("iis", $rest_id, $user_id, $notes);
        $req_stmt->execute();
        $req_id = $conn->insert_id;
        $req_stmt->close();

        $item_stmt = $conn->prepare("
            INSERT INTO ingredient_request_items
                (request_id, ingredient_name, quantity, unit, notes, estimated_cost, target_date)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $item_count = 0;
        foreach ($items as $item) {
            $iname  = trim(strip_tags($item['name']           ?? ''));
            $iqty   = (float)($item['quantity']               ?? 1);
            $iunit  = trim(strip_tags($item['unit']           ?? 'kg'));
            $inote  = trim(strip_tags($item['notes']          ?? ''));
            $icost  = $item['estimated_cost'] !== '' ? (float)$item['estimated_cost'] : null;
            $idate  = !empty($item['target_date'])            ? $item['target_date'] : null;
            if ($iname === '') continue;
            $item_stmt->bind_param("isdssds", $req_id, $iname, $iqty, $iunit, $inote, $icost, $idate);
            $item_stmt->execute();
            $item_count++;
        }
        $item_stmt->close();

        if ($item_count === 0) throw new Exception('No valid line items provided.');
        $conn->commit();

        // Notify Ops Manager & Owner
        $ops_q = $conn->query("
            SELECT DISTINCT rs.user_id FROM restaurant_staff rs
            JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
            WHERE rs.restaurant_id = {$rest_id} AND rsr.role_key IN ('ops_manager','owner') AND rs.is_active = 1
        ");
        if ($ops_q) {
            while ($op = $ops_q->fetch_assoc()) {
                createNotification($conn, (int)$op['user_id'],
                    'New Requisition List',
                    "Chef submitted a requisition (#REQ-{$req_id}) with {$item_count} item(s). Please review.",
                    'info',
                    BASE_URL . 'dashboard/business_owner/operations_manager.php?tab=requisitions');
            }
        }

        chef_json(true, "Requisition #REQ-{$req_id} submitted successfully.");
    } catch (Exception $e) {
        $conn->rollback();
        chef_json(false, 'Failed to submit requisition: ' . $e->getMessage());
    }
}

// 4. SAVE / UPDATE MEAT SUPPLIER (Chef)
if ($action === 'save_supplier') {
    $sup_id   = (int)($_POST['supplier_id'] ?? 0);
    $sup_name = trim(strip_tags($_POST['supplier_name']    ?? ''));
    $contact  = trim(strip_tags($_POST['contact_person']   ?? ''));
    $phone    = trim(strip_tags($_POST['phone']            ?? ''));
    $email    = trim(strip_tags($_POST['email']            ?? ''));
    $cert_num = trim(strip_tags($_POST['halal_cert_number']?? ''));
    $expiry   = !empty($_POST['halal_cert_expiry']) ? $_POST['halal_cert_expiry'] : null;

    if ($sup_name === '') chef_json(false, 'Supplier name is required.');

    if ($sup_id > 0) {
        $stmt = $conn->prepare("
            UPDATE halal_meat_suppliers
            SET supplier_name=?, contact_person=?, phone=?, email=?, halal_cert_number=?, halal_cert_expiry=?
            WHERE id=? AND restaurant_id=?
        ");
        $stmt->bind_param("ssssssii", $sup_name, $contact, $phone, $email, $cert_num, $expiry, $sup_id, $rest_id);
        $ok = $stmt->execute(); $stmt->close();
        $msg = 'Supplier details updated.';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO halal_meat_suppliers
                (restaurant_id, supplier_name, contact_person, phone, email, halal_cert_number, halal_cert_expiry, verification_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->bind_param("issssss", $rest_id, $sup_name, $contact, $phone, $email, $cert_num, $expiry);
        $ok = $stmt->execute(); $stmt->close();
        $msg = 'New meat supplier added (Pending Verification).';
    }
    chef_json($ok, $msg);
}

// 5. VERIFY MEAT SUPPLIER (Chef)
if ($action === 'verify_supplier') {
    $sup_id = (int)($_POST['supplier_id'] ?? 0);
    $status = trim($_POST['status'] ?? 'verified');
    if (!in_array($status, ['verified','pending','rejected'], true)) $status = 'verified';
    $stmt = $conn->prepare("
        UPDATE halal_meat_suppliers SET verification_status=?, verified_by=?, verified_at=NOW()
        WHERE id=? AND restaurant_id=?
    ");
    $stmt->bind_param("siii", $status, $user_id, $sup_id, $rest_id);
    $ok = $stmt->execute(); $stmt->close();
    syncMenuAvailability($conn, $rest_id);
    chef_json($ok, "Supplier status updated to " . ucfirst($status) . ".");
}

// 6. SAVE MENU RECIPE INGREDIENT MAPPING (Chef)
if ($action === 'save_recipe') {
    $menu_item_id = (int)($_POST['menu_item_id'] ?? 0);
    $inventory_id = (int)($_POST['inventory_id'] ?? 0);
    $qty_needed   = (float)($_POST['quantity_needed'] ?? 1);
    $unit         = trim(strip_tags($_POST['unit'] ?? 'g'));
    if (!$menu_item_id || !$inventory_id) chef_json(false, 'Select both a menu item and an ingredient.');
    $stmt = $conn->prepare("
        INSERT INTO menu_recipe_ingredients (menu_item_id, inventory_id, quantity_needed, unit)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE quantity_needed = VALUES(quantity_needed), unit = VALUES(unit)
    ");
    $stmt->bind_param("iids", $menu_item_id, $inventory_id, $qty_needed, $unit);
    $ok = $stmt->execute(); $stmt->close();
    $sync = syncMenuAvailability($conn, $rest_id);
    chef_json($ok, 'Recipe mapping saved. Menu availability updated.', ['sync' => $sync]);
}

// 7. DELETE RECIPE INGREDIENT MAPPING (Chef)
if ($action === 'delete_recipe_ingredient') {
    $mapping_id = (int)($_POST['mapping_id'] ?? 0);
    if (!$mapping_id) chef_json(false, 'Missing recipe mapping ID.');
    $del = $conn->prepare("DELETE FROM menu_recipe_ingredients WHERE id = ?");
    $del->bind_param("i", $mapping_id);
    $ok = $del->execute(); $del->close();
    syncMenuAvailability($conn, $rest_id);
    chef_json($ok, 'Ingredient removed from recipe.');
}

// 8. TRIGGER POS AVAILABILITY CHECK (Chef)
if ($action === 'run_availability_check') {
    $sync = syncMenuAvailability($conn, $rest_id);
    chef_json(true, "POS guardrail check complete. {$sync['updated']} item(s) updated.", ['sync' => $sync]);
}

// 9. ACKNOWLEDGE REQUISITION (Ops Manager — marks as seen / being reviewed)
if ($action === 'acknowledge_requisition') {
    $req_id = (int)($_POST['req_id'] ?? 0);
    if (!$req_id) chef_json(false, 'Missing requisition ID.');
    $upd = $conn->prepare("UPDATE ingredient_requests SET status='acknowledged', updated_at=NOW() WHERE id=? AND restaurant_id=?");
    $upd->bind_param("ii", $req_id, $rest_id);
    $ok = $upd->execute(); $upd->close();
    if ($ok) {
        $rr = $conn->query("SELECT requested_by FROM ingredient_requests WHERE id={$req_id}")->fetch_assoc();
        if ($rr) createNotification($conn, (int)$rr['requested_by'],
            "Requisition #REQ-{$req_id} Acknowledged",
            "The Ops Manager has acknowledged your requisition and is reviewing it.",
            'info',
            BASE_URL . 'dashboard/business_owner/chef_dashboard.php?tab=requisitions');
    }
    chef_json($ok, 'Requisition acknowledged.');
}

// 10. APPROVE GROCERY LIST (Ops Manager — saves amount_spent per item, creates expense records, notifies chef)
if ($action === 'approve_grocery_list') {
    // Verify ops_manager access
    $staff_role_key = $_SESSION['staff_role_key'] ?? null;
    if ($staff_role_key && $staff_role_key !== STAFF_ROLE_OPS_MANAGER) {
        chef_json(false, 'Access denied: only Ops Managers can approve grocery lists.');
    }
    
    $gl_id     = (int)($_POST['gl_id'] ?? 0);
    $items     = $_POST['items'] ?? [];
    $ops_notes = trim(strip_tags($_POST['ops_notes'] ?? ''));
    
    if (!$gl_id) chef_json(false, 'Missing grocery list ID.');
    if (empty($items)) chef_json(false, 'No items found in request.');

    // Query the grocery_lists table (not ingredient_requests)
    $chk = $conn->prepare("SELECT id, created_by, status FROM grocery_lists WHERE id=? AND restaurant_id=? LIMIT 1");
    if (!$chk) {
        chef_json(false, 'Database query failed: ' . $conn->error);
    }
    $chk->bind_param("ii", $gl_id, $rest_id); 
    if (!$chk->execute()) {
        chef_json(false, 'Query execution failed: ' . $chk->error);
    }
    $gl_row = $chk->get_result()->fetch_assoc(); 
    $chk->close();
    
    if (!$gl_row) chef_json(false, "Grocery list #$gl_id not found in restaurant #$rest_id.");
    if ($gl_row['status'] === 'pending') {
        chef_json(false, 'This grocery list must be acknowledged before it can be approved. Please acknowledge it first.');
    }

    $conn->begin_transaction();
    try {
        $total_amount = 0;
        
        // Update amount_spent for each item
        foreach ($items as $item) {
            $item_id      = (int)($item['item_id']     ?? 0);
            $amount_spent = $item['amount_spent'] !== '' ? (float)$item['amount_spent'] : 0;
            if (!$item_id) continue;
            
            $upd_item = $conn->prepare("UPDATE grocery_list_items SET amount_spent=? WHERE id=? AND grocery_list_id=?");
            $upd_item->bind_param("dii", $amount_spent, $item_id, $gl_id);
            if (!$upd_item->execute()) {
                throw new Exception('Update item failed: ' . $upd_item->error);
            }
            $upd_item->close();
            $total_amount += $amount_spent;
        }

        // Update grocery list status to approved
        $upd_gl = $conn->prepare("
            UPDATE grocery_lists
            SET status='approved', ops_notes=?, approved_by=?, approved_at=NOW(), updated_at=NOW()
            WHERE id=? AND restaurant_id=?
        ");
        $upd_gl->bind_param("siii", $ops_notes, $user_id, $gl_id, $rest_id);
        if (!$upd_gl->execute()) {
            throw new Exception('Update grocery list failed: ' . $upd_gl->error);
        }
        $upd_gl->close();

        $conn->commit();

        // Notify Chef to proceed with receiving
        createNotification($conn, (int)$gl_row['created_by'],
            "Grocery List #GL-{$gl_id} Approved — Ready to Receive",
            "Your grocery list has been approved by the Ops Manager. Please receive and inspect the items.",
            'action_required',
            BASE_URL . 'dashboard/business_owner/chef_dashboard.php?tab=requisitions');

        chef_json(true, "Grocery list #GL-{$gl_id} approved. Chef has been notified.");
    } catch (Exception $e) {
        $conn->rollback();
        chef_json(false, 'Approval failed: ' . $e->getMessage());
    }
}

// 11. REJECT GROCERY LIST (Ops Manager)
if ($action === 'reject_grocery_list') {
    // Verify ops_manager access
    $staff_role_key = $_SESSION['staff_role_key'] ?? null;
    if ($staff_role_key && $staff_role_key !== STAFF_ROLE_OPS_MANAGER) {
        chef_json(false, 'Access denied: only Ops Managers can reject grocery lists.');
    }
    
    $gl_id     = (int)($_POST['gl_id'] ?? 0);
    $ops_notes = trim(strip_tags($_POST['ops_notes'] ?? ''));
    if (!$gl_id) chef_json(false, 'Missing grocery list ID.');
    if ($ops_notes === '') chef_json(false, 'A rejection reason is required.');

    $chk = $conn->prepare("SELECT id, created_by FROM grocery_lists WHERE id=? AND restaurant_id=? LIMIT 1");
    $chk->bind_param("ii", $gl_id, $rest_id); $chk->execute();
    $gl_row = $chk->get_result()->fetch_assoc(); $chk->close();
    if (!$gl_row) chef_json(false, 'Grocery list not found.');

    $upd = $conn->prepare("
        UPDATE grocery_lists
        SET status='rejected', ops_notes=?, approved_by=?, approved_at=NOW(), updated_at=NOW()
        WHERE id=? AND restaurant_id=?
    ");
    $upd->bind_param("siii", $ops_notes, $user_id, $gl_id, $rest_id);
    $ok = $upd->execute(); $upd->close();

    if ($ok) {
        createNotification($conn, (int)$gl_row['created_by'],
            "Grocery List #GL-{$gl_id} Rejected",
            "Your grocery list was rejected by the Ops Manager. Reason: {$ops_notes}",
            'warning',
            BASE_URL . 'dashboard/business_owner/chef_dashboard.php?tab=requisitions');
    }
    chef_json($ok, "Grocery list #GL-{$gl_id} has been rejected.");
}

// 11b. UPLOAD RECIPE (Ops Manager — optional recipe file upload after approval)
if ($action === 'upload_recipe') {
    // Verify ops_manager access
    $staff_role_key = $_SESSION['staff_role_key'] ?? null;
    if ($staff_role_key && $staff_role_key !== STAFF_ROLE_OPS_MANAGER) {
        chef_json(false, 'Access denied: only Ops Managers can upload recipes.');
    }
    
    $gl_id = (int)($_POST['gl_id'] ?? 0);
    if (!$gl_id) chef_json(false, 'Missing grocery list ID.');
    if (!isset($_FILES['recipe_file']) || $_FILES['recipe_file']['error'] !== UPLOAD_ERR_OK) {
        chef_json(false, 'File upload failed or no file provided.');
    }
    
    $file = $_FILES['recipe_file'];
    $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];
    if (!in_array($file['type'], $allowed_types)) {
        chef_json(false, 'Invalid file type. Only JPG, PNG, and PDF are allowed.');
    }
    if ($file['size'] > 20 * 1024 * 1024) { // 20 MB
        chef_json(false, 'File too large. Maximum 20 MB allowed.');
    }
    
    // Verify grocery list exists
    $chk = $conn->prepare("SELECT id FROM grocery_lists WHERE id=? AND restaurant_id=? LIMIT 1");
    $chk->bind_param("ii", $gl_id, $rest_id); $chk->execute();
    $gl_row = $chk->get_result()->fetch_assoc(); $chk->close();
    if (!$gl_row) chef_json(false, 'Grocery list not found.');
    
    // Create uploads directory if it doesn't exist
    $upload_dir = __DIR__ . '/../../../uploads/recipes/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Generate unique filename
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = "recipe_gl{$gl_id}_" . time() . '.' . $ext;
    $filepath = $upload_dir . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        chef_json(false, 'Failed to save file.');
    }
    
    // Store in database
    $relative_path = '/uploads/recipes/' . $filename;
    $ins = $conn->prepare("
        INSERT INTO grocery_list_recipes (grocery_list_id, uploaded_by, file_name, file_path, file_size, file_type)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $ins->bind_param("iisiii", $gl_id, $user_id, $file['name'], $relative_path, $file['size'], $file['type']);
    if (!$ins->execute()) {
        unlink($filepath); // Clean up if DB insert fails
        chef_json(false, 'Failed to save file metadata: ' . $ins->error);
    }
    $ins->close();
    
    chef_json(true, 'Recipe uploaded successfully.');
}

// 12. RECEIVE REQUISITION ITEMS (Chef — after ops manager approves)
//     Chef enters qty received, unit cost, expiry, halal proof photo per line item
if ($action === 'receive_requisition') {
    $req_id = (int)($_POST['req_id'] ?? 0);
    $items  = $_POST['items'] ?? [];
    if (!$req_id || empty($items)) chef_json(false, 'Missing requisition or items.');

    $is_gl = false;
    $chk = $conn->prepare("SELECT id, created_by AS requested_by, status FROM grocery_lists WHERE id=? AND restaurant_id=? LIMIT 1");
    $chk->bind_param("ii", $req_id, $rest_id); $chk->execute();
    $req_row = $chk->get_result()->fetch_assoc(); $chk->close();

    if ($req_row) {
        $is_gl = true;
    } else {
        $chk = $conn->prepare("SELECT id, requested_by, status FROM ingredient_requests WHERE id=? AND restaurant_id=? LIMIT 1");
        $chk->bind_param("ii", $req_id, $rest_id); $chk->execute();
        $req_row = $chk->get_result()->fetch_assoc(); $chk->close();
    }

    if (!$req_row) chef_json(false, 'Grocery list or requisition not found.');

    $conn->begin_transaction();
    try {
        $inserted = 0;
        foreach ($items as $idx => $item) {
            $item_id     = (int)($item['item_id']               ?? 0);
            $qty         = (float)($item['qty_received']        ?? 0);
            $unit_cost   = (float)($item['unit_cost']           ?? 0);
            $expiry      = !empty($item['expiry_date'])         ? $item['expiry_date']          : null;
            $purchase_dt = !empty($item['actual_purchase_date'])? $item['actual_purchase_date'] : date('Y-m-d');
            if (!$item_id || $qty <= 0) continue;

            if ($is_gl) {
                $ri = $conn->prepare("SELECT ingredient_name, unit, category_id FROM grocery_list_items WHERE id=? AND grocery_list_id=?");
            } else {
                $ri = $conn->prepare("SELECT ingredient_name, unit, NULL AS category_id FROM ingredient_request_items WHERE id=? AND request_id=?");
            }
            $ri->bind_param("ii", $item_id, $req_id); $ri->execute();
            $ri_row = $ri->get_result()->fetch_assoc(); $ri->close();
            if (!$ri_row) continue;

            $gl_id     = $is_gl ? $req_id : null;
            $gl_item_id= $is_gl ? $item_id : null;
            $cat_id    = (!empty($ri_row['category_id'])) ? (int)$ri_row['category_id'] : null;

            // Handle halal proof photo upload
            $proof_img = '';
            $file_key  = "halal_proof_{$idx}";
            if (!empty($_FILES[$file_key]['name']) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES[$file_key]['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','webp','pdf'], true) && $_FILES[$file_key]['size'] <= MAX_FILE_SIZE) {
                    $dir = UPLOAD_DIR . 'requisition_proofs/';
                    @mkdir($dir, 0755, true);
                    $fn = uniqid("proof_r{$req_id}_i{$item_id}_", true) . '.' . $ext;
                    if (move_uploaded_file($_FILES[$file_key]['tmp_name'], $dir . $fn)) {
                        $proof_img = 'requisition_proofs/' . $fn;
                    }
                }
            }

            // Insert into kitchen_inventory as pending_inspection
            $ins = $conn->prepare("
                INSERT INTO kitchen_inventory
                    (restaurant_id, logged_by, ingredient_name, unit, quantity,
                     qty_purchased, unit_cost, halal_proof_image,
                     expiry_date, actual_purchase_date,
                     purchase_status, halal_status, source_request_item_id,
                     grocery_list_id, grocery_list_item_id, category_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_inspection', 'pending_cert', ?, ?, ?, ?)
            ");
            $ins->bind_param("iissdddsssiiii",
                $rest_id, $user_id, $ri_row['ingredient_name'], $ri_row['unit'],
                $qty, $qty, $unit_cost, $proof_img, $expiry, $purchase_dt, $item_id,
                $gl_id, $gl_item_id, $cat_id);
            $ins->execute(); $ins->close();
            $inserted++;
        }

        if ($inserted === 0) throw new Exception('No valid items received.');

        if ($is_gl) {
            $upd2 = $conn->prepare("UPDATE grocery_lists SET status='fulfilled', updated_at=NOW() WHERE id=?");
        } else {
            $upd2 = $conn->prepare("UPDATE ingredient_requests SET status='fulfilled', updated_at=NOW() WHERE id=?");
        }
        $upd2->bind_param("i", $req_id); $upd2->execute(); $upd2->close();
        $conn->commit();

        chef_json(true, "{$inserted} item(s) received and queued for Halal inspection.");
    } catch (Exception $e) {
        $conn->rollback();
        chef_json(false, 'Receive failed: ' . $e->getMessage());
    }
}

// 13. SUBMIT / APPROVE INSPECTION (Chef verifies Halal compliance & QC dates)
if ($action === 'approve_inspection' || $action === 'submit_inspection') {
    $inv_id        = (int)($_POST['inv_id'] ?? 0);
    $expiry        = !empty($_POST['expiry_date'])          ? $_POST['expiry_date']           : null;
    $purchase_dt   = !empty($_POST['actual_purchase_date']) ? $_POST['actual_purchase_date']  : date('Y-m-d');
    $halal_st      = in_array($_POST['halal_status'] ?? '', ['halal','self_certified','non_halal','pending_cert'], true)
                      ? $_POST['halal_status'] : 'halal';
    $cert_ref      = trim(strip_tags($_POST['halal_cert_ref'] ?? ''));
    $self_cert_note= trim(strip_tags($_POST['self_cert_note'] ?? ''));

    if (!$inv_id) chef_json(false, 'Missing inventory ID.');

    // Fetch existing item record
    $chk = $conn->prepare("SELECT * FROM kitchen_inventory WHERE id=? AND restaurant_id=? LIMIT 1");
    $chk->bind_param("ii", $inv_id, $rest_id); $chk->execute();
    $inv_item = $chk->get_result()->fetch_assoc(); $chk->close();
    if (!$inv_item) chef_json(false, 'Inventory item not found.');

    // Handle new uploaded proof file if present
    $proof_img = $inv_item['halal_proof_image'] ?? '';
    if (!empty($_FILES['halal_proof_file']['name']) && $_FILES['halal_proof_file']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['halal_proof_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp','pdf'], true) && $_FILES['halal_proof_file']['size'] <= MAX_FILE_SIZE) {
            $dir = UPLOAD_DIR . 'requisition_proofs/';
            @mkdir($dir, 0755, true);
            $fn = uniqid("proof_ins{$inv_id}_", true) . '.' . $ext;
            if (move_uploaded_file($_FILES['halal_proof_file']['tmp_name'], $dir . $fn)) {
                $proof_img = 'requisition_proofs/' . $fn;
            }
        }
    }

    if ($halal_st === 'non_halal') {
        $reject_notes = trim(strip_tags($_POST['reject_notes'] ?? 'Non-Halal / Rejected during inspection'));
        $upd = $conn->prepare("
            UPDATE kitchen_inventory
            SET purchase_status='quarantined', halal_status='non_halal',
                notes=CONCAT(IFNULL(notes,''), ' | QUARANTINED: ', ?),
                expiry_date=?, actual_purchase_date=?, halal_proof_image=?, updated_at=NOW()
            WHERE id=? AND restaurant_id=?
        ");
        $upd->bind_param("ssssii", $reject_notes, $expiry, $purchase_dt, $proof_img, $inv_id, $rest_id);
        $ok = $upd->execute(); $upd->close();
        if ($ok) syncMenuAvailability($conn, $rest_id);
        chef_json($ok, 'Item marked as Non-Halal and quarantined from kitchen use.');
    }

    if ($halal_st === 'halal' && empty($cert_ref) && empty($proof_img)) {
        chef_json(false, 'Halal Certified status requires uploading photo proof or entering Certificate / Brand reference info.');
    }
    if ($halal_st === 'self_certified' && empty($self_cert_note)) {
        chef_json(false, 'Muslim-Owned / Self-Certified status requires entering supplier self-certification details.');
    }

    $new_status = 'VERIFIED_ACTIVE';
    $upd = $conn->prepare("
        UPDATE kitchen_inventory
        SET purchase_status=?, halal_status=?, halal_cert_ref=?, self_cert_note=?,
            expiry_date=?, actual_purchase_date=?, halal_proof_image=?, updated_at=NOW()
        WHERE id=? AND restaurant_id=?
    ");
    $upd->bind_param("sssssssii", $new_status, $halal_st, $cert_ref, $self_cert_note, $expiry, $purchase_dt, $proof_img, $inv_id, $rest_id);
    $ok = $upd->execute(); $upd->close();
    if ($ok) syncMenuAvailability($conn, $rest_id);
    chef_json($ok, 'Halal compliance verified! Item status updated to VERIFIED_ACTIVE and unlocked for recipe preparation.');
}

// 14. REJECT INSPECTION (Chef rejects on QC/Halal grounds)
if ($action === 'reject_inspection') {
    $inv_id       = (int)($_POST['inv_id']       ?? 0);
    $reject_notes = trim(strip_tags($_POST['reject_notes'] ?? 'Quarantined during Halal inspection'));
    if (!$inv_id) chef_json(false, 'Missing inventory ID.');
    $upd = $conn->prepare("
        UPDATE kitchen_inventory
        SET purchase_status='quarantined',
            halal_status='non_halal',
            notes=CONCAT(IFNULL(notes,''), ' | QUARANTINED: ', ?), updated_at=NOW()
        WHERE id=? AND restaurant_id=?
    ");
    $upd->bind_param("sii", $reject_notes, $inv_id, $rest_id);
    $ok = $upd->execute(); $upd->close();
    if ($ok) syncMenuAvailability($conn, $rest_id);
    chef_json($ok, 'Item quarantined and removed from kitchen use.');
}

// ── SAVE PANTRY CATEGORIES (onboarding wizard + category management) ─────
if ($action === 'save_categories') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    $cats = $data['categories'] ?? [];
    if (empty($cats)) chef_json(false, 'No categories provided.');

    $conn->begin_transaction();
    try {
        $conn->query("DELETE FROM `ingredients_category` WHERE restaurant_id = {$rest_id}");
        $par = $conn->prepare("INSERT INTO `ingredients_category` (restaurant_id, parent_id, name, sort_order) VALUES (?,NULL,?,?)");
        $chi = $conn->prepare("INSERT INTO `ingredients_category` (restaurant_id, parent_id, name, sort_order) VALUES (?,?,?,?)");
        $psort = 0;
        foreach ($cats as $cat) {
            $pname = trim(strip_tags($cat['name'] ?? ''));
            if ($pname === '') continue;
            $par->bind_param("isi", $rest_id, $pname, $psort);
            $par->execute();
            $parent_id = (int)$conn->insert_id;
            $csort = 0;
            foreach (($cat['children'] ?? []) as $child) {
                $cname = trim(strip_tags(is_array($child) ? ($child['name'] ?? '') : $child));
                if ($cname === '') continue;
                $chi->bind_param("iisi", $rest_id, $parent_id, $cname, $csort);
                $chi->execute();
                $csort++;
            }
            $psort++;
        }
        $par->close();
        $chi->close();
        $conn->commit();
        chef_json(true, 'Categories saved successfully.');
    } catch (Throwable $e) {
        $conn->rollback();
        chef_json(false, 'Failed to save categories: ' . $e->getMessage());
    }
}

// ── SUBMIT GROCERY LIST (Chef -> Ops Manager) ─────────────────────────────
if ($action === 'submit_grocery_list') {
    $items = $_POST['items'] ?? [];
    $notes = trim(strip_tags($_POST['notes'] ?? ''));
    $title = trim(strip_tags($_POST['title'] ?? 'Grocery List'));
    if (empty($items) || !is_array($items)) chef_json(false, 'Add at least one item.');
    if ($title === '') $title = 'Grocery List';

    $conn->begin_transaction();
    try {
        $gl = $conn->prepare("INSERT INTO grocery_lists (restaurant_id, created_by, title, notes, status) VALUES (?,?,?,?,'pending')");
        $gl->bind_param("iiss", $rest_id, $user_id, $title, $notes);
        $gl->execute();
        $gl_id = (int)$conn->insert_id;
        $gl->close();

        $gi = $conn->prepare("INSERT INTO grocery_list_items (grocery_list_id, category_id, ingredient_name, quantity, unit, unit_price, estimated_cost, target_date, notes) VALUES (?,?,?,?,?,?,?,?,?)");
        $count = 0;
        foreach ($items as $item) {
            $iname      = trim(strip_tags($item['name'] ?? ''));
            $iqty       = (float)($item['quantity'] ?? 1);
            $iunit      = trim(strip_tags($item['unit'] ?? 'kg'));
            $icat       = !empty($item['category_id']) ? (int)$item['category_id'] : null;
            $iunit_price = ($item['unit_price'] ?? '') !== '' ? (float)$item['unit_price'] : null;
            // Auto-compute total if unit_price given; fall back to submitted estimated_cost
            $icost = null;
            if ($iunit_price !== null) {
                $icost = round($iunit_price * $iqty, 2);
            } elseif (($item['estimated_cost'] ?? '') !== '') {
                $icost = (float)$item['estimated_cost'];
            }
            $idate      = !empty($item['target_date']) ? $item['target_date'] : null;
            $inote      = trim(strip_tags($item['notes'] ?? ''));
            if ($iname === '') continue;
            $gi->bind_param("iisdsddss", $gl_id, $icat, $iname, $iqty, $iunit, $iunit_price, $icost, $idate, $inote);
            $gi->execute();
            $count++;
        }
        $gi->close();
        if ($count === 0) throw new Exception('No valid items provided.');
        $conn->commit();

        $ops_q = $conn->query("SELECT DISTINCT rs.user_id FROM restaurant_staff rs JOIN restaurant_staff_roles rsr ON rs.staff_role_id=rsr.id WHERE rs.restaurant_id={$rest_id} AND rsr.role_key IN ('ops_manager','owner') AND rs.is_active=1");
        if ($ops_q) while ($op = $ops_q->fetch_assoc()) {
            createNotification($conn,(int)$op['user_id'],'New Grocery List Submitted',"Chef submitted Grocery List #{$gl_id} — {$count} item(s) pending procurement.",'info',BASE_URL.'dashboard/business_owner/operations_manager.php?tab=requisitions');
        }
        chef_json(true, "Grocery List #GL-{$gl_id} submitted to Ops Manager.", ['gl_id' => $gl_id]);
    } catch (Throwable $e) { $conn->rollback(); chef_json(false, 'Submit failed: '.$e->getMessage()); }
}

// ── ACKNOWLEDGE GROCERY LIST (Ops Manager) ───────────────────────────────
if ($action === 'acknowledge_grocery_list') {
    $gl_id = (int)($_POST['gl_id'] ?? 0);
    if (!$gl_id) chef_json(false, 'Missing grocery list ID.');
    $chk = $conn->prepare("SELECT id, created_by FROM grocery_lists WHERE id=? AND restaurant_id=? LIMIT 1");
    $chk->bind_param("ii", $gl_id, $rest_id); $chk->execute();
    $gl_row = $chk->get_result()->fetch_assoc(); $chk->close();
    if (!$gl_row) chef_json(false, 'Grocery list not found.');
    
    $up = $conn->prepare("UPDATE grocery_lists SET status='acknowledged' WHERE id=?");
    $up->bind_param("i", $gl_id);
    $ok = $up->execute(); $up->close();
    
    if ($ok) {
        createNotification($conn, (int)$gl_row['created_by'], "Grocery List #GL-{$gl_id} Acknowledged", "Ops Manager is reviewing your list.", 'info', BASE_URL.'dashboard/business_owner/chef_dashboard.php?tab=grocery_lists');
        chef_json(true, "Grocery List #GL-{$gl_id} acknowledged.");
    } else {
        chef_json(false, 'Failed to acknowledge grocery list.');
    }
}

// ── APPROVE GROCERY LIST (Ops Manager) ───────────────────────────────────
if ($action === 'approve_grocery_list') {
    $gl_id     = (int)($_POST['gl_id'] ?? 0);
    $items     = $_POST['items'] ?? [];
    $ops_notes = trim(strip_tags($_POST['ops_notes'] ?? ''));
    if (!$gl_id) chef_json(false, 'Missing grocery list ID.');
    $chk = $conn->prepare("SELECT id, created_by, status FROM grocery_lists WHERE id=? AND restaurant_id=? LIMIT 1");
    $chk->bind_param("ii",$gl_id,$rest_id); $chk->execute();
    $gl_row = $chk->get_result()->fetch_assoc(); $chk->close();
    if (!$gl_row) chef_json(false, 'Grocery list not found.');
    if ($gl_row['status'] === 'pending') {
        chef_json(false, 'This grocery list must be acknowledged before it can be approved.');
    }
    $conn->begin_transaction();
    try {
        $ui = $conn->prepare("UPDATE grocery_list_items SET amount_spent=? WHERE id=? AND grocery_list_id=?");
        foreach ($items as $item) {
            $item_id=(int)($item['item_id']??0);
            $spent=($item['amount_spent']??'')!==''?(float)$item['amount_spent']:null;
            if(!$item_id) continue;
            $ui->bind_param("dii",$spent,$item_id,$gl_id); $ui->execute();
        }
        $ui->close();
        $ug=$conn->prepare("UPDATE grocery_lists SET status='approved',ops_notes=?,approved_by=?,approved_at=NOW() WHERE id=?");
        $ug->bind_param("sii",$ops_notes,$user_id,$gl_id); $ug->execute(); $ug->close();
        $conn->commit();
        createNotification($conn,(int)$gl_row['created_by'],"Grocery List #GL-{$gl_id} Approved","Approved by Ops Manager. Proceed to receive items.",'action_required',BASE_URL.'dashboard/business_owner/chef_dashboard.php?tab=grocery_lists');
        chef_json(true,"Grocery List #GL-{$gl_id} approved.");
    } catch(Throwable $e){$conn->rollback();chef_json(false,$e->getMessage());}
}

// ── REJECT GROCERY LIST (Ops Manager) ────────────────────────────────────
if ($action === 'reject_grocery_list') {
    $gl_id     = (int)($_POST['gl_id'] ?? 0);
    $ops_notes = trim(strip_tags($_POST['ops_notes'] ?? ''));
    if (!$gl_id)           chef_json(false, 'Missing grocery list ID.');
    if ($ops_notes === '') chef_json(false, 'Rejection reason is required.');
    $chk=$conn->prepare("SELECT id,created_by FROM grocery_lists WHERE id=? AND restaurant_id=? LIMIT 1");
    $chk->bind_param("ii",$gl_id,$rest_id); $chk->execute();
    $gl_row=$chk->get_result()->fetch_assoc(); $chk->close();
    if (!$gl_row) chef_json(false,'Grocery list not found.');
    $ug=$conn->prepare("UPDATE grocery_lists SET status='rejected',ops_notes=?,approved_by=?,approved_at=NOW() WHERE id=?");
    $ug->bind_param("sii",$ops_notes,$user_id,$gl_id);
    $ok=$ug->execute(); $ug->close();
    if($ok) createNotification($conn,(int)$gl_row['created_by'],"Grocery List #GL-{$gl_id} Rejected","Reason: {$ops_notes}",'warning',BASE_URL.'dashboard/business_owner/chef_dashboard.php?tab=grocery_lists');
    chef_json($ok,"Grocery List #GL-{$gl_id} rejected.");
}

// ── UPDATE MENU STATUS (Chef — toggle menu item availability) ───────────────
if ($action === 'update_menu_status') {
    $menu_id      = (int)($_POST['menu_id']      ?? 0);
    $is_available = isset($_POST['is_available']) ? (int)$_POST['is_available'] : 0;
    $is_available = $is_available ? 1 : 0; // normalise to 1/0

    if (!$menu_id) chef_json(false, 'Missing menu item ID.');

    $stmt = $conn->prepare("UPDATE menu_items SET is_available = ? WHERE id = ? AND restaurant_id = ?");
    if (!$stmt) chef_json(false, 'DB error: ' . $conn->error);
    $stmt->bind_param("iii", $is_available, $menu_id, $rest_id);
    $ok = $stmt->execute();
    $stmt->close();

    chef_json($ok, $ok ? 'Menu availability updated.' : 'Failed to update menu availability.');
}

// ── RECEIVE GROCERY LIST ITEMS (Chef — after ops manager approves) ─────────
// Logs items to kitchen_inventory with halal verification
if ($action === 'receive_grocery_list') {
    $gl_id = (int)($_POST['gl_id'] ?? 0);
    $items = $_POST['items'] ?? [];

    if (!$gl_id) chef_json(false, 'Missing grocery list ID.');
    if (empty($items) || !is_array($items)) chef_json(false, 'No items to receive.');

    // Verify grocery list exists and is approved
    $gl_stmt = $conn->prepare("SELECT id, status FROM grocery_lists WHERE id = ? AND restaurant_id = ? LIMIT 1");
    $gl_stmt->bind_param('ii', $gl_id, $rest_id);
    $gl_stmt->execute();
    $gl_res = $gl_stmt->get_result();
    if (!$gl_res || $gl_res->num_rows === 0) chef_json(false, 'Grocery list not found.');
    $gl_row = $gl_res->fetch_assoc();
    if ($gl_row['status'] !== 'approved') chef_json(false, 'Grocery list must be approved first.');
    $gl_stmt->close();

    $conn->begin_transaction();
    try {
        $uploaded_count = 0;
        foreach ($items as $idx => $item) {
            $item_id         = (int)($item['item_id'] ?? $item['gl_item_id'] ?? 0);
            $qty_received    = (float)($item['qty_received'] ?? 0);
            $unit            = trim($item['unit'] ?? 'kg');
            $expiry          = trim($item['expiry_date'] ?? '');
            $purchase_date   = trim($item['actual_purchase_date'] ?? '');
            $halal_status    = trim($item['halal_status'] ?? '');
            $halal_note      = trim($item['halal_note'] ?? '');
            $ingredient_name = trim($item['ingredient_name'] ?? 'Unknown');

            if (!$item_id || $qty_received <= 0) continue;

            // Validate halal status
            if (empty($halal_status) || !in_array($halal_status, ['halal','self_certified','fresh_produce','non_halal'], true)) {
                $halal_status = 'pending_cert';
            }

            // Handle halal proof file upload
            $proof_file = null;
            $file_key   = "halal_proof_{$idx}";
            if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
                $file    = $_FILES[$file_key];
                $allowed = ['image/jpeg','image/png','image/webp','application/pdf'];
                if (in_array($file['type'], $allowed) && $file['size'] <= 20 * 1024 * 1024) {
                    $upload_dir = __DIR__ . '/../../../uploads/halal_proofs/';
                    if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);
                    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $new_name = 'proof_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], $upload_dir . $new_name)) {
                        $proof_file = 'halal_proofs/' . $new_name;
                    }
                }
            }

            // Resolve category_id from grocery_list_items
            $cat_id   = null;
            $gli_stmt = $conn->prepare("SELECT category_id FROM grocery_list_items WHERE id = ? LIMIT 1");
            $gli_stmt->bind_param('i', $item_id);
            $gli_stmt->execute();
            $gli_res = $gli_stmt->get_result();
            if ($gli_res && $gli_row = $gli_res->fetch_assoc()) {
                $cat_id = $gli_row['category_id'] ? (int)$gli_row['category_id'] : null;
            }
            $gli_stmt->close();

            // Insert — two variants to avoid passing NULL for an integer bind slot
            $purchase_status = 'active';
            if ($cat_id !== null) {
                // 16 params: i  i  s  d  s  s  i  i  i  s  d  s  s  s  s  s
                //            ri ui nm qt ut hs gl it ct ps qp ex pd pf hn hn
                $inv_stmt = $conn->prepare("
                    INSERT INTO kitchen_inventory (
                        restaurant_id, logged_by, ingredient_name, quantity, unit, halal_status,
                        grocery_list_id, grocery_list_item_id, category_id, purchase_status,
                        qty_purchased, expiry_date, actual_purchase_date, halal_proof_image,
                        halal_cert_ref, self_cert_note
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ");
                $inv_stmt->bind_param('iisdssiiisdsssss',
                    $rest_id, $user_id, $ingredient_name, $qty_received, $unit,
                    $halal_status, $gl_id, $item_id, $cat_id, $purchase_status,
                    $qty_received, $expiry, $purchase_date, $proof_file,
                    $halal_note, $halal_note
                );
            } else {
                // 15 params: i  i  s  d  s  s  i  i  s  d  s  s  s  s  s
                //            ri ui nm qt ut hs gl it ps qp ex pd pf hn hn
                $inv_stmt = $conn->prepare("
                    INSERT INTO kitchen_inventory (
                        restaurant_id, logged_by, ingredient_name, quantity, unit, halal_status,
                        grocery_list_id, grocery_list_item_id, purchase_status,
                        qty_purchased, expiry_date, actual_purchase_date, halal_proof_image,
                        halal_cert_ref, self_cert_note
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ");
                $inv_stmt->bind_param('iisdssiisdsssss',
                    $rest_id, $user_id, $ingredient_name, $qty_received, $unit,
                    $halal_status, $gl_id, $item_id, $purchase_status,
                    $qty_received, $expiry, $purchase_date, $proof_file,
                    $halal_note, $halal_note
                );
            }
            $inv_stmt->execute();
            $inv_stmt->close();
            $uploaded_count++;
        }

        // Mark grocery list as fulfilled
        $update_gl = $conn->prepare("UPDATE grocery_lists SET status = 'fulfilled' WHERE id = ?");
        $update_gl->bind_param('i', $gl_id);
        $update_gl->execute();
        $update_gl->close();

        $conn->commit();
        chef_json(true, "Received {$uploaded_count} item(s) and added to inventory.");
    } catch (Throwable $e) {
        $conn->rollback();
        chef_json(false, 'Failed to receive items: ' . $e->getMessage());
    }
}

chef_json(false, 'Invalid action specified.');
