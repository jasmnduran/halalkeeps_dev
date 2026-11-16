<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/IngredientInventoryService.php';
require_once __DIR__ . '/../../includes/MenuWorkflowService.php';
require_once __DIR__ . '/../../includes/RevenuePaymentService.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);
requireStaffRole($conn, STAFF_ROLE_CHEF);

$page_title   = 'Chef Dashboard';
$page_heading = 'Chef & Kitchen Management';
$is_dashboard = true;
$breadcrumbs  = [['label' => 'Chef Dashboard']];
$user_id      = (int)$_SESSION['user_id'];
$rest_id      = getSessionRestaurantId($conn);
$menu_workflow_csrf = MenuWorkflowService::ensureCsrfToken();


// ── Schema for onboarding + grocery lists ────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS `ingredients_category` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `restaurant_id` INT(11) NOT NULL,
    `parent_id` INT UNSIGNED DEFAULT NULL,
    `name` VARCHAR(100) NOT NULL,
    `sort_order` SMALLINT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ic_rest` (`restaurant_id`),
    KEY `idx_ic_parent` (`parent_id`),
    CONSTRAINT `fk_ic_parent` FOREIGN KEY (`parent_id`)
        REFERENCES `ingredients_category`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `grocery_lists` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `restaurant_id` INT(11) NOT NULL,
    `created_by` INT(11) NOT NULL,
    `title` VARCHAR(150) NOT NULL DEFAULT 'Grocery List',
    `notes` TEXT DEFAULT NULL,
    `ops_notes` TEXT DEFAULT NULL,
    `status` ENUM('pending','acknowledged','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending',
    `approved_by` INT(11) DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `grocery_list_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `grocery_list_id` INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `ingredient_name` VARCHAR(150) NOT NULL,
    `quantity` DECIMAL(10,3) NOT NULL DEFAULT 1.000,
    `unit` VARCHAR(30) NOT NULL DEFAULT 'kg',
    `estimated_cost` DECIMAL(10,2) DEFAULT NULL,
    `amount_spent` DECIMAL(10,2) DEFAULT NULL,
    `target_date` DATE DEFAULT NULL,
    `notes` VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_gli_list2` FOREIGN KEY (`grocery_list_id`)
        REFERENCES `grocery_lists`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Idempotent column additions for tables that may have been created in a prior
// session without the full schema.
// Idempotent column additions — one call per column for MySQL 5.6/5.7 compatibility
db_add_column($conn, 'ingredients_category', 'restaurant_id', 'INT(11) NOT NULL DEFAULT 0');
db_add_column($conn, 'ingredients_category', 'parent_id',     'INT UNSIGNED DEFAULT NULL');
db_add_column($conn, 'ingredients_category', 'name',          "VARCHAR(100) NOT NULL DEFAULT ''");
db_add_column($conn, 'ingredients_category', 'sort_order',    'SMALLINT NOT NULL DEFAULT 0');
db_add_column($conn, 'ingredients_category', 'created_at',    'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
// kitchen_inventory: halal workflow + grocery list columns
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
db_add_column($conn, 'kitchen_inventory', 'expiration_date',        'DATE          DEFAULT NULL');
db_add_column($conn, 'kitchen_inventory', 'actual_purchase_date',   'DATE          DEFAULT NULL');
db_add_column($conn, 'kitchen_inventory', 'source_request_item_id', 'INT UNSIGNED DEFAULT NULL');
// One-time migration: copy legacy expiry_date values into the canonical expiration_date column
@$conn->query("UPDATE `kitchen_inventory` SET `expiration_date` = `expiry_date`
    WHERE `expiration_date` IS NULL AND `expiry_date` IS NOT NULL AND `expiry_date` != '0000-00-00'");
// Extend halal_status enum — safe to re-run (MODIFY is idempotent)
$conn->query("ALTER TABLE `kitchen_inventory` MODIFY COLUMN `halal_status`
    ENUM('halal','non_halal','pending_cert','self_certified','fresh_produce') NOT NULL DEFAULT 'pending_cert'");
// Indexes
db_add_unique_key($conn, 'kitchen_inventory', 'idx_ki_gl',  ['grocery_list_id']);
db_add_unique_key($conn, 'kitchen_inventory', 'idx_ki_gli', ['grocery_list_item_id']);
db_add_unique_key($conn, 'kitchen_inventory', 'idx_ki_cat', ['category_id']);
// FK constraints — drop-then-re-add safely
$conn->query("SET FOREIGN_KEY_CHECKS=0");
db_drop_fk($conn, 'kitchen_inventory', 'fk_ki_grocery_list');
db_drop_fk($conn, 'kitchen_inventory', 'fk_ki_grocery_item');
db_drop_fk($conn, 'kitchen_inventory', 'fk_ki_category');
db_add_fk($conn, 'kitchen_inventory', 'fk_ki_grocery_list', 'grocery_list_id',      'grocery_lists',       'id', 'SET NULL');
db_add_fk($conn, 'kitchen_inventory', 'fk_ki_grocery_item', 'grocery_list_item_id', 'grocery_list_items',  'id', 'SET NULL');
db_add_fk($conn, 'kitchen_inventory', 'fk_ki_category',     'category_id',          'ingredients_category','id', 'SET NULL');
$conn->query("SET FOREIGN_KEY_CHECKS=1");
db_add_column($conn, 'grocery_lists', 'restaurant_id', 'INT(11) NOT NULL DEFAULT 0');
db_add_column($conn, 'grocery_lists', 'title',         "VARCHAR(150) NOT NULL DEFAULT 'Grocery List'");
db_add_column($conn, 'grocery_lists', 'approved_by',   'INT(11) DEFAULT NULL');
db_add_column($conn, 'grocery_lists', 'approved_at',   'DATETIME DEFAULT NULL');
db_add_column($conn, 'grocery_lists', 'ops_notes',     'TEXT DEFAULT NULL');
db_add_column($conn, 'grocery_list_items', 'category_id',    'INT UNSIGNED DEFAULT NULL');
db_add_column($conn, 'grocery_list_items', 'estimated_cost', 'DECIMAL(10,2) DEFAULT NULL');
db_add_column($conn, 'grocery_list_items', 'unit_price',     "DECIMAL(10,2) DEFAULT NULL COMMENT 'Price per 1 unit'");
db_add_column($conn, 'grocery_list_items', 'amount_spent',   'DECIMAL(10,2) DEFAULT NULL');
db_add_column($conn, 'grocery_list_items', 'target_date',    'DATE DEFAULT NULL');
db_add_column($conn, 'grocery_list_items', 'notes',          'VARCHAR(255) DEFAULT NULL');

// ── Onboarding guard: check if pantry categories are defined ─────────────
$pantry_setup_done = false;
if ($rest_id) {
    $pc = $conn->query("SELECT COUNT(*) AS n FROM ingredients_category WHERE restaurant_id = {$rest_id}");
    $pantry_setup_done = $pc && (int)$pc->fetch_assoc()['n'] > 0;
}

// ── Load pantry categories (parents + children) ───────────────────────────
$pantry_categories = [];  // [ ['id'=>1,'name'=>'Fresh Produce','children'=>[…]], … ]
if ($rest_id && $pantry_setup_done) {
    $pc_res = $conn->query("
        SELECT id, parent_id, name, sort_order
        FROM ingredients_category
        WHERE restaurant_id = {$rest_id}
        ORDER BY COALESCE(parent_id, id), sort_order
    ");
    $pc_rows = $pc_res ? $pc_res->fetch_all(MYSQLI_ASSOC) : [];
    $pc_map  = [];
    foreach ($pc_rows as $r) $pc_map[$r['id']] = $r + ['children' => []];
    foreach ($pc_map as $id => $r) {
        if ($r['parent_id']) $pc_map[$r['parent_id']]['children'][] = $r;
    }
    foreach ($pc_map as $r) {
        if (!$r['parent_id']) $pantry_categories[] = $r;
    }
}

// ── Load Grocery Lists ────────────────────────────────────────────────────
$grocery_lists = [];
if ($rest_id) {
    $gl_res = $conn->query("
        SELECT gl.*, u.full_name AS creator_name,
               (SELECT COUNT(*) FROM grocery_list_items WHERE grocery_list_id = gl.id) AS item_count
        FROM grocery_lists gl
        JOIN users u ON gl.created_by = u.id
        WHERE gl.restaurant_id = {$rest_id}
        ORDER BY gl.created_at DESC
    ");
    if ($gl_res) {
        while ($glrow = $gl_res->fetch_assoc()) {
            $glid = (int)$glrow['id'];
            $gli_res = $conn->query("
                SELECT gli.*, ic.name AS category_name
                FROM grocery_list_items gli
                LEFT JOIN ingredients_category ic ON gli.category_id = ic.id
                WHERE gli.grocery_list_id = {$glid}
                ORDER BY ic.sort_order, gli.id
            ");
            $glrow['items'] = $gli_res ? $gli_res->fetch_all(MYSQLI_ASSOC) : [];
            $grocery_lists[] = $glrow;
        }
    }
}

// Active tab from URL query string
$active_tab = in_array($_GET['tab'] ?? '', ['inventory','suppliers','grocery_lists','categories','recipes','availability','menu_status','menu_submit'], true) ? $_GET['tab'] : 'inventory';
$inventory_capacity = (new RevenuePaymentService($conn))->getInventoryCapacity($rest_id);
// Load restaurant details
$restaurant = null;
if ($rest_id) {
    $rs = $conn->prepare("SELECT id, restaurant_name, is_active FROM halal_restaurants WHERE id = ? LIMIT 1");
    $rs->bind_param("i", $rest_id);
    $rs->execute();
    $restaurant = $rs->get_result()->fetch_assoc();
    $rs->close();
}

// Ensure database tables exist (trigger schema migrations silently)
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
    `manufacturer_brand`   VARCHAR(150) DEFAULT NULL,
    `halal_status`         ENUM('halal','non_halal','pending_cert') NOT NULL DEFAULT 'pending_cert',
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
    `status`        ENUM('pending','acknowledged','fulfilled','rejected') NOT NULL DEFAULT 'pending',
    `notes`         TEXT DEFAULT NULL,
    `ops_notes`     TEXT DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ir_rest` (`restaurant_id`),
    KEY `idx_ir_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `ingredient_request_items` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id`      INT UNSIGNED NOT NULL,
    `ingredient_name` VARCHAR(150) NOT NULL,
    `quantity`        DECIMAL(10,3) NOT NULL DEFAULT 1.000,
    `unit`            VARCHAR(30) NOT NULL DEFAULT 'kg',
    `notes`           VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_iri_req` (`request_id`),
    CONSTRAINT `fk_iri_req` FOREIGN KEY (`request_id`) REFERENCES `ingredient_requests`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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

// Fetch Kitchen Inventory
$inventory_list = [];
if ($rest_id) {
    // Debug: show all items regardless of status to see if they're being inserted at all
    $inv_res = $conn->query("
        SELECT ki.*, hms.supplier_name, hms.verification_status AS supplier_status
        FROM kitchen_inventory ki
        LEFT JOIN halal_meat_suppliers hms ON ki.supplier_id = hms.id
        WHERE ki.restaurant_id = {$rest_id}
        ORDER BY ki.created_at DESC, ki.ingredient_name ASC
    ");
    if ($inv_res) $inventory_list = $inv_res->fetch_all(MYSQLI_ASSOC);

// Fetch Pending Inspection items
$inspection_list = [];
if ($rest_id) {
    $ins_res = $conn->query("
        SELECT ki.*,
               hms.supplier_name,
               hms.verification_status AS supplier_verified,
               gl.id         AS origin_gl_id,
               gl.title      AS origin_gl_title,
               gli.ingredient_name AS origin_item_name,
               gli.quantity        AS origin_qty,
               gli.unit            AS origin_unit,
               ic.name             AS origin_category
        FROM   kitchen_inventory ki
        LEFT JOIN halal_meat_suppliers hms ON ki.supplier_id       = hms.id
        LEFT JOIN grocery_list_items  gli ON ki.grocery_list_item_id = gli.id
        LEFT JOIN grocery_lists       gl  ON ki.grocery_list_id    = gl.id
        LEFT JOIN ingredients_category ic ON ki.category_id        = ic.id
        WHERE  ki.restaurant_id    = {$rest_id}
          AND  ki.purchase_status  = 'pending_inspection'
        ORDER BY ki.created_at DESC
    ");
    if ($ins_res) $inspection_list = $ins_res->fetch_all(MYSQLI_ASSOC);
}
}

// Fetch Meat Suppliers
$suppliers_list = [];
if ($rest_id) {
    $sup_res = $conn->query("
        SELECT * FROM halal_meat_suppliers
        WHERE restaurant_id = {$rest_id}
        ORDER BY supplier_name ASC
    ");
    if ($sup_res) $suppliers_list = $sup_res->fetch_all(MYSQLI_ASSOC);
}

// Idempotent column additions (run once, safe to repeat)
db_add_column($conn, 'ingredient_requests',      'approved_by',    'INT(11) DEFAULT NULL');
db_add_column($conn, 'ingredient_requests',      'approved_at',    'DATETIME DEFAULT NULL');
@$conn->query("ALTER TABLE `ingredient_requests` MODIFY `status`
    ENUM('pending','acknowledged','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending'");
db_add_column($conn, 'ingredient_request_items', 'estimated_cost', 'DECIMAL(10,2) DEFAULT NULL');
db_add_column($conn, 'ingredient_request_items', 'target_date',    'DATE DEFAULT NULL');
db_add_column($conn, 'ingredient_request_items', 'amount_spent',   'DECIMAL(10,2) DEFAULT NULL');

// Fetch Ingredient Requisitions
$requisitions_list = [];
if ($rest_id) {
    $req_res = $conn->query("
        SELECT ir.*, u.full_name AS requester_name,
               (SELECT COUNT(*) FROM ingredient_request_items WHERE request_id = ir.id) AS item_count
        FROM ingredient_requests ir
        JOIN users u ON ir.requested_by = u.id
        WHERE ir.restaurant_id = {$rest_id}
        ORDER BY ir.created_at DESC
    ");
    if ($req_res) {
        while ($row = $req_res->fetch_assoc()) {
            $rid = (int)$row['id'];
            $items_res = $conn->query("SELECT * FROM ingredient_request_items WHERE request_id = {$rid}");
            $row['items'] = $items_res ? $items_res->fetch_all(MYSQLI_ASSOC) : [];
            $requisitions_list[] = $row;
        }
    }
}

// Fetch Master Menu Items
$menu_items = [];
if ($rest_id) {
    $mi_res = $conn->query("
        SELECT mi.*, 
               (SELECT COUNT(*) FROM menu_recipe_ingredients WHERE menu_item_id = mi.id) AS mapped_count
        FROM menu_items mi
        WHERE mi.restaurant_id = {$rest_id}
        ORDER BY mi.category ASC, mi.name ASC
    ");
    if ($mi_res) $menu_items = $mi_res->fetch_all(MYSQLI_ASSOC);
}

// Fetch All Recipe Mappings
$recipe_mappings = [];
if ($rest_id) {
    $rec_res = $conn->query("
        SELECT mri.*, ki.ingredient_name, ki.halal_status, ki.quantity AS stock, ki.unit AS stock_unit,
               ki.is_meat, hms.supplier_name, hms.verification_status AS supplier_status
        FROM menu_recipe_ingredients mri
        JOIN kitchen_inventory ki ON mri.inventory_id = ki.id
        LEFT JOIN halal_meat_suppliers hms ON ki.supplier_id = hms.id
        JOIN menu_items mi ON mri.menu_item_id = mi.id
        WHERE mi.restaurant_id = {$rest_id}
    ");
    if ($rec_res) {
        while ($rm = $rec_res->fetch_assoc()) {
            $recipe_mappings[$rm['menu_item_id']][] = $rm;
        }
    }
}

// ── Idempotent approval columns ───────────────────────────────────────────────
db_add_column($conn, 'menu_items', 'approval_status',
    "ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
db_add_column($conn, 'menu_items', 'submitted_by',     'INT UNSIGNED NULL');
db_add_column($conn, 'menu_items', 'reviewed_by',      'INT UNSIGNED NULL');
db_add_column($conn, 'menu_items', 'reviewed_at',      'DATETIME NULL');
db_add_column($conn, 'menu_items', 'rejection_reason', 'VARCHAR(255) NULL');

// ── Chef's own submitted menu items ───────────────────────────────────────────
// Structured AI-assisted recipes are the source of truth for confirmed mappings.
// Keep legacy recipe mappings available for menus that have no structured recipe yet.
$structured_recipe_mappings = [];
$structured_recipe_meta = [];
$feature_recipe_table = $conn->query("SHOW TABLES LIKE 'menu_recipes'");
$feature_recipe_ingredients_table = $conn->query("SHOW TABLES LIKE 'recipe_ingredients'");
$feature_visibility_column = $conn->query("SHOW COLUMNS FROM menu_recipes LIKE 'customer_visibility_status'");
$structured_recipe_ready = $rest_id && $feature_recipe_table && $feature_recipe_table->num_rows > 0
    && $feature_recipe_ingredients_table && $feature_recipe_ingredients_table->num_rows > 0
    && $feature_visibility_column && $feature_visibility_column->num_rows > 0;
if ($structured_recipe_ready) {
    $structured_meta_res = $conn->query("SELECT mr.id AS recipe_id, mr.menu_item_id, mr.recipe_name,
            mr.customer_visibility_status,
            COUNT(ri.id) AS total_count,
            COALESCE(SUM(CASE WHEN ri.mapping_status='confirmed' AND ri.inventory_id IS NOT NULL THEN 1 ELSE 0 END),0) AS confirmed_count
        FROM menu_recipes mr
        LEFT JOIN recipe_ingredients ri ON ri.recipe_id = mr.id AND ri.restaurant_id = mr.restaurant_id
        WHERE mr.restaurant_id = {$rest_id} AND mr.recipe_status = 'active'
          AND mr.id = (SELECT MAX(mr_latest.id) FROM menu_recipes mr_latest WHERE mr_latest.restaurant_id = mr.restaurant_id AND mr_latest.menu_item_id = mr.menu_item_id AND mr_latest.recipe_status = 'active')
        GROUP BY mr.id, mr.menu_item_id, mr.recipe_name, mr.customer_visibility_status
        ORDER BY mr.id DESC");
    if ($structured_meta_res) {
        while ($structured_meta = $structured_meta_res->fetch_assoc()) {
            $structured_recipe_meta[(int)$structured_meta['menu_item_id']] = $structured_meta;
        }
    }
    $structured_rows_res = $conn->query("SELECT mr.menu_item_id, ri.id, ri.ingredient_name,
            ri.quantity_required AS quantity_needed, ri.unit,
            ki.ingredient_name AS inventory_name, ki.halal_status,
            ki.quantity AS stock, ki.unit AS stock_unit, ki.is_meat,
            hms.supplier_name, hms.verification_status AS supplier_status
        FROM menu_recipes mr
        JOIN recipe_ingredients ri ON ri.recipe_id = mr.id AND ri.restaurant_id = mr.restaurant_id
        JOIN kitchen_inventory ki ON ki.id = ri.inventory_id AND ki.restaurant_id = mr.restaurant_id
        LEFT JOIN halal_meat_suppliers hms ON ki.supplier_id = hms.id
        WHERE mr.restaurant_id = {$rest_id}
          AND mr.recipe_status = 'active'
          AND mr.id = (SELECT MAX(mr_latest.id) FROM menu_recipes mr_latest WHERE mr_latest.restaurant_id = mr.restaurant_id AND mr_latest.menu_item_id = mr.menu_item_id AND mr_latest.recipe_status = 'active')
          AND ri.mapping_status = 'confirmed'
          AND ri.inventory_id IS NOT NULL
        ORDER BY mr.menu_item_id, ri.id");
    if ($structured_rows_res) {
        while ($structured_row = $structured_rows_res->fetch_assoc()) {
            $structured_row['is_structured'] = 1;
            $structured_recipe_mappings[(int)$structured_row['menu_item_id']][] = $structured_row;
        }
    }
}
$chef_menu_items = [];
if ($rest_id) {
    $cmi = $conn->query("
        SELECT mi.id, mi.name, mi.description, mi.price, mi.category,
               mi.serving_type, mi.image_path, mi.is_available,
               mi.approval_status, mi.workflow_status, mi.rejection_reason,
               mi.operational_status, mi.ingredient_evidence_status,
               mi.submitted_by, mi.reviewed_at,
               reviewer.full_name AS reviewed_by_name
        FROM   menu_items mi
        LEFT JOIN users reviewer ON mi.reviewed_by = reviewer.id
        WHERE  mi.restaurant_id = {$rest_id}
          AND  (mi.submitted_by = {$user_id} OR mi.submitted_by IS NULL)
        ORDER  BY mi.approval_status ASC, mi.updated_at DESC
    ");
    if ($cmi) $chef_menu_items = $cmi->fetch_all(MYSQLI_ASSOC);
}

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
/* Chef Dashboard — responsive */
.chef-nav-tabs {
    display: flex;
    gap: 2px;
    border-bottom: 2px solid var(--neutral-200, #e2e8f0);
    margin-bottom: 24px;
    overflow-x: auto;
    overflow-y: visible;
    padding-bottom: 0;
    scrollbar-width: thin;
    scrollbar-color: var(--neutral-300) transparent;
    -webkit-overflow-scrolling: touch;
}
.chef-nav-tabs::-webkit-scrollbar { height: 4px; }
.chef-nav-tabs::-webkit-scrollbar-thumb { background: var(--neutral-300); border-radius: 2px; }

.chef-nav-tab {
    padding: 10px 14px;
    font-weight: 600;
    font-size: 0.82rem;
    color: var(--neutral-600, #475569);
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    cursor: pointer;
    transition: color .15s, border-color .15s, background .15s;
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    border-radius: 6px 6px 0 0;
    flex-shrink: 0;
}
.chef-nav-tab:hover {
    color: #4f46e5;
    background: rgba(99,102,241,.06);
}
.chef-nav-tab.active {
    color: #4338ca;
    border-bottom-color: #6366f1;
    background: #eef2ff;
    font-weight: 700;
}
.chef-badge {
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.chef-badge-halal { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.chef-badge-nonhalal { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
.chef-badge-pending { background: #fef9c3; color: #854d0e; border: 1px solid #fde047; }
.chef-badge-verified { background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe; }

.recipe-dropzone {
    border: 2px dashed #cbd5e1;
    border-radius: 12px;
    padding: 16px;
    min-height: 140px;
    background: #f8fafc;
    transition: all 0.2s ease;
}
.recipe-dropzone.drag-over {
    border-color: #6366f1;
    background: #eef2ff;
}
.ingredient-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 600;
    margin: 4px;
    cursor: grab;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    user-select: none;
    transition: all 0.15s ease;
}
.ingredient-chip:hover {
    border-color: #6366f1;
    color: #4338ca;
    box-shadow: 0 2px 6px rgba(99, 102, 241, 0.15);
}
.ingredient-chip:active { cursor: grabbing; }

.modal-slide {
    position: fixed;
    top: 0; right: -450px;
    width: 440px; max-width: 90vw; height: 100vh;
    background: #ffffff;
    box-shadow: -4px 0 25px rgba(15, 23, 42, 0.18);
    z-index: 9999;
    transition: right 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    padding: 24px;
    overflow-y: auto;
}
.modal-slide.open { right: 0; }
.modal-overlay {
    position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(4px);
    z-index: 9998;
    display: none;
}
.modal-overlay.open { display: block; }
</style>

<div style="padding: 0;"><!-- Chef Dashboard -->

    <!-- Top Banner -->
    <div style="background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%); border-radius: 16px; padding: 24px 32px; color: #ffffff; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="width: 52px; height: 52px; border-radius: 14px; background: rgba(255,255,255,0.12); display: flex; align-items: center; justify-content: center; font-size: 1.6rem; color: #a5b4fc;">
                <i class="fas fa-utensils"></i>
            </div>
            <div>
                <h2 style="margin: 0; font-size: 1.4rem; font-weight: 800; color: #ffffff;">Chef & Kitchen Workstation</h2>
                <div style="font-size: 0.85rem; color: #c7d2fe; margin-top: 4px;">
                    <i class="fas fa-store" style="margin-right: 4px;"></i> <?= htmlspecialchars($restaurant['restaurant_name'] ?? 'Kitchen') ?>
                </div>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 12px;">
            <button onclick="triggerPOSCheck()" class="btn" style="background: #6366f1; color: #fff; font-weight: 700; border-radius: 10px; padding: 10px 18px; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-rotate"></i> Sync POS Availability
            </button>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="chef-nav-tabs">
        <a href="?tab=inventory" class="chef-nav-tab <?= $active_tab === 'inventory' ? 'active' : '' ?>">
            <i class="fas fa-boxes-stacked"></i> Inventory (<?= count($inventory_list) ?>)
        </a>
        <a href="?tab=suppliers" class="chef-nav-tab <?= $active_tab === 'suppliers' ? 'active' : '' ?>">
            <i class="fas fa-truck-field"></i> Suppliers (<?= count($suppliers_list) ?>)
        </a>
        <a href="?tab=grocery_lists" class="chef-nav-tab <?= $active_tab === 'grocery_lists' ? 'active' : '' ?>">
            <i class="fas fa-shopping-basket"></i> Grocery List (<?= count($grocery_lists) ?>)
        </a>
        <a href="?tab=categories" class="chef-nav-tab <?= $active_tab === 'categories' ? 'active' : '' ?>">
            <i class="fas fa-tags"></i> Categories<?php if (!$pantry_setup_done): ?> <span style="background:#dc2626;color:#fff;font-size:.65rem;padding:1px 6px;border-radius:10px;margin-left:4px;">Setup</span><?php endif; ?>
        </a>
        <a href="?tab=recipes" class="chef-nav-tab <?= $active_tab === 'recipes' ? 'active' : '' ?>">
            <i class="fas fa-scroll"></i> Recipes
        </a>
        <a href="?tab=availability" class="chef-nav-tab <?= $active_tab === 'availability' ? 'active' : '' ?>">
            <i class="fas fa-chart-pie"></i> Availability
        </a>
        <a href="?tab=menu_status" class="chef-nav-tab <?= $active_tab === 'menu_status' ? 'active' : '' ?>">
            <i class="fas fa-toggle-on"></i> POS Guardrails
        </a>
        <a href="?tab=menu_submit" class="chef-nav-tab <?= $active_tab === 'menu_submit' ? 'active' : '' ?>"
           style="<?= $active_tab === 'menu_submit' ? 'border-bottom-color:#059669;color:#047857;' : '' ?>">
            <i class="fas fa-paper-plane"></i> Submit Menu
            <?php
            $pending_count = count(array_filter($chef_menu_items, fn($m) => ($m['workflow_status'] ?? '') === 'pending_review'));
            if ($pending_count): ?>
            <span style="background:#fef3c7;color:#92400e;font-size:.65rem;padding:1px 6px;border-radius:10px;margin-left:4px;font-weight:800;"><?= $pending_count ?></span>
            <?php endif; ?>
        </a>
    </div>

    <!-- ══ TAB 1: KITCHEN INVENTORY ═════════════════════════════════════════════ -->
    <?php if ($active_tab === 'inventory'): ?>
        <div class="alert alert-info" style="margin-bottom:14px;display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;">
            <span><strong><i class="fas fa-boxes"></i> Ingredient record capacity: <?= (int)$inventory_capacity["used"] ?> / <?= (int)$inventory_capacity["limit"] ?></strong><br><small><?= (int)$inventory_capacity["extra_slots"] ?> active expansion slots. Batches do not use extra slots.</small></span>
            <?php if ($inventory_capacity["at_capacity"]): ?><span style="font-weight:700;color:#b91c1c;">Limit reached — ask an owner or operations manager to purchase storage.</span><?php endif; ?>
        </div>
        <div class="card" style="border-radius: 14px; border: 1px solid var(--neutral-200); box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
            <div class="card-header" style="padding: 18px 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; background: #fafafa;">
                <div>
                    <h3 style="margin: 0; font-size: 1.05rem; font-weight: 700;">Raw Ingredients & Halal Log</h3>
                    <p style="margin: 2px 0 0; font-size: 0.8rem; color: var(--neutral-500);">Record received kitchen ingredients, quantities, and verification status</p>
                </div>
                <button onclick="openInventoryModal()" class="btn btn-primary btn-sm" style="border-radius: 8px;">
                    <i class="fas fa-plus"></i> Add New Ingredient
                </button>
            </div>
            <div class="card-body" style="padding: 0;">
                <?php if (empty($inventory_list)): ?>
                    <div style="text-align: center; padding: 48px 24px; color: var(--neutral-400);">
                        <i class="fas fa-box-open" style="font-size: 3rem; margin-bottom: 12px; opacity: 0.5;"></i>
                        <h4>No Kitchen Ingredients Logged</h4>
                        <p style="font-size: 0.85rem;">Click "Add New Ingredient" to log raw stock and verify Halal compliance.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table" style="margin: 0; vertical-align: middle;">
                            <thead style="background: var(--neutral-100); font-size: 0.78rem; text-transform: uppercase; color: var(--neutral-600);">
                                <tr>
                                    <th>Ingredient Name</th>
                                    <th>Halal Status</th>
                                    <th>Expiration Date</th>
                                    <th>Meat & Supplier</th>
                                    <th>Current Stock</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inventory_list as $inv): 
                                    $is_low = (float)$inv['quantity'] <= (float)$inv['low_stock_threshold'];
                                    // Use expiration_date (canonical); fall back to legacy expiry_date
                                    $exp_date = !empty($inv['expiration_date']) ? $inv['expiration_date']
                                              : (!empty($inv['expiry_date']) && $inv['expiry_date'] !== '0000-00-00' ? $inv['expiry_date'] : null);
                                    $exp_badge  = '';
                                    $row_bg     = $is_low ? 'background:#fffbeb;' : '';
                                    if ($exp_date) {
                                        $days_left = (int)ceil((strtotime($exp_date) - time()) / 86400);
                                        if ($days_left < 0) {
                                            $exp_badge = '<span style="display:inline-block;margin-top:3px;font-size:.68rem;font-weight:700;background:#fee2e2;color:#991b1b;padding:1px 6px;border-radius:4px;">EXPIRED</span>';
                                            $row_bg = 'background:#fff5f5;';
                                        } elseif ($days_left <= 7) {
                                            $exp_badge = '<span style="display:inline-block;margin-top:3px;font-size:.68rem;font-weight:700;background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:4px;">EXPIRES IN ' . $days_left . 'D</span>';
                                            if (!$is_low) $row_bg = 'background:#fffbeb;';
                                        }
                                    }
                                ?>
                                    <tr style="<?= $row_bg ?>">
                                        <td>
                                            <strong style="color: var(--neutral-800); font-size: 0.9rem;"><?= htmlspecialchars($inv['ingredient_name']) ?></strong>
                                            <?php if (!empty($inv['notes'])): ?>
                                                <div style="font-size: 0.75rem; color: var(--neutral-500);"><?= htmlspecialchars($inv['notes']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($inv['halal_status'] === 'halal'): ?>
                                                <span class="chef-badge chef-badge-halal"><i class="fas fa-check-circle"></i> Certified Halal</span>
                                            <?php elseif ($inv['halal_status'] === 'self_certified'): ?>
                                                <span class="chef-badge chef-badge-halal"><i class="fas fa-user-check"></i> Muslim-Owned</span>
                                            <?php elseif ($inv['halal_status'] === 'fresh_produce'): ?>
                                                <span class="chef-badge chef-badge-halal"><i class="fas fa-leaf"></i> Fresh Produce</span>
                                            <?php elseif ($inv['halal_status'] === 'non_halal'): ?>
                                                <span class="chef-badge chef-badge-nonhalal"><i class="fas fa-times-circle"></i> Non-Halal</span>
                                            <?php else: ?>
                                                <span class="chef-badge chef-badge-pending"><i class="fas fa-clock"></i> Pending Cert</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($exp_date): ?>
                                                <span style="font-size:.85rem;font-weight:600;color:var(--neutral-700);">
                                                    <?= date('M d, Y', strtotime($exp_date)) ?>
                                                </span>
                                                <?= $exp_badge ?>
                                            <?php else: ?>
                                                <span style="font-size:.8rem;color:var(--neutral-400);">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ((int)$inv['is_meat'] === 1): ?>
                                                <div style="display: flex; align-items: center; gap: 6px;">
                                                    <span style="background: #ffe4e6; color: #9f1239; padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: 700;">MEAT</span>
                                                    <?php if ($inv['supplier_id']): ?>
                                                        <span style="font-size: 0.82rem; font-weight: 600; color: var(--neutral-700);"><?= htmlspecialchars($inv['supplier_name']) ?></span>
                                                        <?php if ($inv['supplier_status'] === 'verified'): ?>
                                                            <i class="fas fa-shield-check" style="color: #16a34a;" title="Verified Meat Supplier"></i>
                                                        <?php else: ?>
                                                            <i class="fas fa-triangle-exclamation" style="color: #d97706;" title="Unverified Supplier"></i>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span style="font-size: 0.78rem; color: #dc2626; font-weight: 600;">Unlinked Supplier</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="font-size: 0.8rem; color: var(--neutral-400);">Non-Meat</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="font-weight: 800; font-size: 0.95rem; color: <?= $is_low ? '#dc2626' : 'var(--neutral-800)' ?>;">
                                                <?= number_format($inv['quantity'], 2) ?> <?= htmlspecialchars($inv['unit']) ?>
                                            </span>
                                            <?php if ($is_low): ?>
                                                <span style="font-size: 0.7rem; background: #fee2e2; color: #991b1b; padding: 1px 5px; border-radius: 4px; margin-left: 4px; font-weight: 700;">LOW STOCK</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button onclick='editInventory(<?= json_encode($inv) ?>)' class="btn btn-sm btn-outline" style="padding: 4px 8px;" title="Edit">
                                                <i class="fas fa-pen"></i>
                                            </button>
                                            <button onclick="deleteInventory(<?= $inv['id'] ?>)" class="btn btn-sm btn-outline" style="padding: 4px 8px; color: #dc2626;" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- TAB 2: MEAT SUPPLIERS -->
    <?php if ($active_tab === 'suppliers'): ?>
        <div class="card" style="border-radius: 14px; border: 1px solid var(--neutral-200); box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
            <div class="card-header" style="padding: 18px 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; background: #fafafa;">
                <div>
                    <h3 style="margin: 0; font-size: 1.05rem; font-weight: 700;">Halal Meat Suppliers Directory</h3>
                    <p style="margin: 2px 0 0; font-size: 0.8rem; color: var(--neutral-500);">Verify slaughterhouses and meat vendors against Halal Keeps system records</p>
                </div>
                <button onclick="openSupplierModal()" class="btn btn-primary btn-sm" style="border-radius: 8px;">
                    <i class="fas fa-plus"></i> Add New Supplier
                </button>
            </div>
            <div class="card-body" style="padding: 0;">
                <?php if (empty($suppliers_list)): ?>
                    <div style="text-align: center; padding: 48px 24px; color: var(--neutral-400);">
                        <i class="fas fa-truck" style="font-size: 3rem; margin-bottom: 12px; opacity: 0.5;"></i>
                        <h4>No Suppliers Registered</h4>
                        <p style="font-size: 0.85rem;">Add your meat suppliers to link them with meat ingredients.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table" style="margin: 0; vertical-align: middle;">
                            <thead style="background: var(--neutral-100); font-size: 0.78rem; text-transform: uppercase; color: var(--neutral-600);">
                                <tr>
                                    <th>Supplier Name</th>
                                    <th>Contact Info</th>
                                    <th>Halal Cert No.</th>
                                    <th>Cert Expiry</th>
                                    <th>Verification Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($suppliers_list as $sup): ?>
                                    <tr>
                                        <td>
                                            <strong style="color: var(--neutral-800); font-size: 0.9rem;"><?= htmlspecialchars($sup['supplier_name']) ?></strong>
                                            <?php if (!empty($sup['contact_person'])): ?>
                                                <div style="font-size: 0.75rem; color: var(--neutral-500);">Attn: <?= htmlspecialchars($sup['contact_person']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size: 0.82rem;">
                                            <div><i class="fas fa-phone" style="font-size: 0.72rem; color: var(--neutral-400);"></i> <?= htmlspecialchars($sup['phone'] ?: '—') ?></div>
                                            <div><i class="fas fa-envelope" style="font-size: 0.72rem; color: var(--neutral-400);"></i> <?= htmlspecialchars($sup['email'] ?: '—') ?></div>
                                        </td>
                                        <td style="font-family: monospace; font-size: 0.85rem; font-weight: 700; color: #4338ca;">
                                            <?= htmlspecialchars($sup['halal_cert_number'] ?: 'N/A') ?>
                                        </td>
                                        <td style="font-size: 0.82rem;">
                                            <?= $sup['halal_cert_expiry'] ? date('M d, Y', strtotime($sup['halal_cert_expiry'])) : '&mdash;' ?>
                                        </td>
                                        <td>
                                            <?php if ($sup['verification_status'] === 'verified'): ?>
                                                <span class="chef-badge chef-badge-verified"><i class="fas fa-shield-check"></i> System Verified</span>
                                            <?php elseif ($sup['verification_status'] === 'rejected'): ?>
                                                <span class="chef-badge chef-badge-nonhalal"><i class="fas fa-ban"></i> Rejected</span>
                                            <?php else: ?>
                                                <span class="chef-badge chef-badge-pending"><i class="fas fa-hourglass"></i> Pending Audit</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($sup['verification_status'] !== 'verified'): ?>
                                                <button onclick="verifySupplier(<?= $sup['id'] ?>, 'verified')" class="btn btn-sm" style="background: #10b981; color: #fff; padding: 4px 8px; font-size: 0.75rem;">
                                                    <i class="fas fa-check"></i> Verify
                                                </button>
                                            <?php else: ?>
                                                <button onclick="verifySupplier(<?= $sup['id'] ?>, 'pending')" class="btn btn-sm btn-outline" style="padding: 4px 8px; font-size: 0.75rem;">
                                                    Revoke
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- TAB 3: REQUISITIONS -->
  <?php if ($active_tab === 'grocery_lists'): ?>
<div class="card" style="border-radius:14px;border:1px solid var(--neutral-200);box-shadow:0 2px 8px rgba(0,0,0,0.04);">
    <div class="card-header" style="padding:18px 24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;background:#fafafa;">
        <div>
            <h3 style="margin:0;font-size:1.05rem;font-weight:700;"><i class="fas fa-shopping-basket" style="color:#6366f1;margin-right:8px;"></i>Grocery Lists</h3>
            <p style="margin:2px 0 0;font-size:.8rem;color:var(--neutral-500);">Categorized requests sent to Operations Manager for procurement</p>
        </div>
        <button onclick="openGroceryModal()" class="btn btn-primary btn-sm" style="border-radius:8px;">
            <i class="fas fa-plus"></i> Create Grocery List
        </button>
    </div>
    <div class="card-body" style="padding:24px;">
        <?php if (empty($grocery_lists)): ?>
        <div style="text-align:center;padding:36px 24px;color:var(--neutral-400);">
            <i class="fas fa-shopping-basket" style="font-size:3rem;margin-bottom:12px;opacity:.5;"></i>
            <h4>No Grocery Lists Yet</h4>
            <p style="font-size:.85rem;">Click "Create Grocery List" to request ingredients from the Ops Manager.</p>
        </div>
        <?php else: ?>
        <div style="display:grid;gap:16px;">
        <?php foreach ($grocery_lists as $gl):
            $status_styles = [
                'pending'      => ['#fef9c3','#854d0e','fas fa-clock'],
                'acknowledged' => ['#dbeafe','#1e40af','fas fa-eye'],
                'approved'     => ['#dcfce7','#166534','fas fa-thumbs-up'],
                'rejected'     => ['#fee2e2','#991b1b','fas fa-times-circle'],
                'fulfilled'    => ['#e0e7ff','#3730a3','fas fa-check-double'],
            ];
            [$sbg,$sfg,$sicon] = $status_styles[$gl['status']] ?? ['#f1f5f9','#334155','fas fa-question'];
            // Group items by category
            $by_cat = [];
            foreach ($gl['items'] as $item) {
                $cname = $item['category_name'] ?? 'Uncategorized';
                $by_cat[$cname][] = $item;
            }
        ?>
        <div style="border:1px solid var(--neutral-200);border-radius:12px;background:#fff;overflow:hidden;">
            <!-- Header row -->
            <div style="padding:14px 20px;background:#fafafa;border-bottom:1px solid var(--neutral-100);
                        display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <div>
                    <span style="font-weight:800;font-size:.95rem;color:#312e81;">
                        #GL-<?= $gl['id'] ?> — <?= htmlspecialchars($gl['title']) ?>
                    </span>
                    <span style="font-size:.75rem;background:<?=$sbg?>;color:<?=$sfg?>;
                                 padding:2px 10px;border-radius:20px;margin-left:10px;font-weight:700;">
                        <i class="<?=$sicon?>"></i> <?= ucfirst($gl['status']) ?>
                    </span>
                    <div style="font-size:.78rem;color:var(--neutral-500);margin-top:2px;">
                        <?= date('M d, Y h:i A', strtotime($gl['created_at'])) ?>
                        · <?= $gl['item_count'] ?> item(s)
                    </div>
                </div>
            </div>
            <?php if (!empty($gl['notes'])): ?>
            <div style="padding:8px 20px;font-size:.82rem;color:var(--neutral-600);background:#f8fafc;border-bottom:1px solid var(--neutral-100);">
                <i class="fas fa-sticky-note" style="color:#f59e0b;margin-right:4px;"></i>
                <?= htmlspecialchars($gl['notes']) ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($gl['ops_notes'])): ?>
            <div style="padding:8px 20px;font-size:.82rem;
                        background:<?= $gl['status']==='rejected'?'#fef2f2':'#f0fdf4' ?>;
                        border-bottom:1px solid var(--neutral-100);
                        border-left:3px solid <?= $gl['status']==='rejected'?'#dc2626':'#16a34a' ?>;">
                <strong>Ops Manager:</strong> <?= htmlspecialchars($gl['ops_notes']) ?>
            </div>
            <?php endif; ?>
            <!-- Items by category -->
            <div style="padding:16px 20px;">
            <?php foreach ($by_cat as $cname => $citems): ?>
            <div style="margin-bottom:14px;">
                <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
                            color:#6366f1;margin-bottom:6px;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-tag"></i> <?= htmlspecialchars($cname) ?>
                </div>
                <table style="width:100%;font-size:.82rem;border-collapse:collapse;">
                    <thead>
                        <tr style="color:var(--neutral-500);font-size:.72rem;text-transform:uppercase;">
                            <th style="text-align:left;padding:4px 8px;font-weight:600;">Ingredient</th>
                            <th style="text-align:right;padding:4px 8px;font-weight:600;">Qty Needed</th>
                            <th style="text-align:right;padding:4px 8px;font-weight:600;">Price / Unit</th>
                            <th style="text-align:right;padding:4px 8px;font-weight:600;">Total Amount</th>
                            <th style="text-align:right;padding:4px 8px;font-weight:600;">Approved (₱)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($citems as $ci):
                        // Derive unit_price: stored value takes priority, fall back to estimated_cost / qty
                        $ci_qty        = max((float)($ci['quantity'] ?? 1), 0.001);
                        $ci_unit_price = ($ci['unit_price'] !== null && $ci['unit_price'] !== '')
                            ? (float)$ci['unit_price']
                            : (($ci['estimated_cost'] !== null) ? round((float)$ci['estimated_cost'] / $ci_qty, 2) : null);
                        $ci_total      = ($ci_unit_price !== null) ? $ci_unit_price * $ci_qty : (float)($ci['estimated_cost'] ?? 0);
                        $ci_total_disp = ($ci['estimated_cost'] !== null || $ci_unit_price !== null)
                            ? '₱' . number_format($ci_total, 2) : '—';
                    ?>
                    <tr style="border-top:1px solid var(--neutral-100);">
                        <td style="padding:5px 8px;font-weight:600;color:var(--neutral-800);">
                            <?= htmlspecialchars($ci['ingredient_name']) ?>
                            <?php if (!empty($ci['notes'])): ?>
                            <div style="font-size:.72rem;color:var(--neutral-400);"><?= htmlspecialchars($ci['notes']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="padding:5px 8px;text-align:right;color:var(--neutral-600);font-weight:700;">
                            <?= number_format($ci_qty, 2) ?> <?= htmlspecialchars($ci['unit']) ?>
                        </td>
                        <td style="padding:5px 8px;text-align:right;color:var(--neutral-500);">
                            <?= $ci_unit_price !== null ? '₱' . number_format($ci_unit_price, 2) . '<span style="font-size:.68rem;color:var(--neutral-400);margin-left:2px;">/ ' . htmlspecialchars($ci['unit']) . '</span>' : '—' ?>
                        </td>
                        <td style="padding:5px 8px;text-align:right;font-weight:700;color:#1d4ed8;">
                            <?= $ci_total_disp ?>
                        </td>
                        <td style="padding:5px 8px;text-align:right;font-weight:700;color:#065f46;">
                            <?= $ci['amount_spent'] ? '₱'.number_format($ci['amount_spent'],2) : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
            </div>
            <?php
            // Compute grocery list totals
            $gl_total_est    = 0;
            $gl_total_spent  = 0;
            foreach ($gl['items'] as $gi_t) {
                $gi_qty   = max((float)($gi_t['quantity'] ?? 1), 0.001);
                $gi_up    = ($gi_t['unit_price'] !== null && $gi_t['unit_price'] !== '')
                    ? (float)$gi_t['unit_price']
                    : (($gi_t['estimated_cost'] !== null) ? round((float)$gi_t['estimated_cost'] / $gi_qty, 2) : null);
                $gl_total_est   += ($gi_up !== null) ? $gi_up * $gi_qty : (float)($gi_t['estimated_cost'] ?? 0);
                $gl_total_spent += (float)($gi_t['amount_spent'] ?? 0);
            }
            ?>
            <?php if ($gl_total_est > 0 || $gl_total_spent > 0): ?>
            <div style="padding:10px 20px;border-top:2px solid var(--neutral-100);background:#f8fafc;
                        display:flex;align-items:center;justify-content:flex-end;gap:24px;font-size:.82rem;">
                <div style="display:flex;align-items:center;gap:6px;">
                    <span style="color:var(--neutral-500);font-weight:600;">Total Estimated:</span>
                    <strong style="color:#1d4ed8;font-size:.9rem;">₱<?= number_format($gl_total_est, 2) ?></strong>
                </div>
                <?php if ($gl_total_spent > 0): ?>
                <div style="display:flex;align-items:center;gap:6px;">
                    <span style="color:var(--neutral-500);font-weight:600;">Total Approved:</span>
                    <strong style="color:#065f46;font-size:.9rem;">₱<?= number_format($gl_total_spent, 2) ?></strong>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <!-- Receive button when approved/acknowledged/pending -->
            <?php if (in_array($gl['status'], ['approved','acknowledged','pending'], true)): ?>
            <div style="padding:12px 20px;border-top:1px solid var(--neutral-100);background:#f0fdf4;">
                <button onclick="openReceiveModal(<?= $gl['id'] ?>, <?= htmlspecialchars(json_encode(array_values($gl['items'])), ENT_QUOTES) ?>)"
                        class="btn btn-primary btn-sm" style="border-radius:8px;">
                    <i class="fas fa-boxes-stacked"></i> Receive &amp; Log to Inventory
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div><!-- /grocery_lists card -->

    <!-- Grocery List Modal -->
    <div id="groceryModal" style="display:none;position:fixed;inset:0;z-index:9999;
         background:rgba(15,23,42,.5);backdrop-filter:blur(4px);align-items:flex-start;justify-content:center;overflow-y:auto;padding:32px 16px;">
        <div style="background:#fff;border-radius:16px;width:100%;max-width:760px;box-shadow:0 20px 60px rgba(0,0,0,.25);margin:auto;">
            <div style="padding:24px 28px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <h3 style="margin:0;font-weight:800;color:#1e293b;font-size:1.1rem;">
                        <i class="fas fa-shopping-basket" style="color:#6366f1;margin-right:8px;"></i>Create Grocery List
                    </h3>
                    <p style="margin:4px 0 0;font-size:.82rem;color:#64748b;">Add ingredients and submit to Operations Manager for procurement</p>
                </div>
                <button onclick="closeGroceryModal()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#94a3b8;">&#x2715;</button>
            </div>
            
            <form id="groceryForm" style="padding:24px 28px;max-height:70vh;overflow-y:auto;">
                <!-- List Title & Notes -->
                <div style="margin-bottom:20px;">
                    <label style="display:block;font-size:.85rem;font-weight:700;color:var(--neutral-600);margin-bottom:6px;">
                        List Title
                    </label>
                    <input type="text" id="glTitle" name="title" class="form-control" 
                           placeholder="e.g., Weekly Produce & Meat Order"
                           value="Grocery List"
                           style="font-size:.9rem;height:36px;">
                </div>

                <div style="margin-bottom:20px;">
                    <label style="display:block;font-size:.85rem;font-weight:700;color:var(--neutral-600);margin-bottom:6px;">
                        Chef Notes (Optional)
                    </label>
                    <textarea name="notes" class="form-control" placeholder="e.g., Urgent - needed by Friday" 
                              style="font-size:.9rem;min-height:60px;"></textarea>
                </div>

                <!-- Items -->
                <div style="margin-bottom:20px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                        <label style="font-size:.85rem;font-weight:700;color:var(--neutral-600);">
                            <i class="fas fa-list"></i> Ingredients
                        </label>
                        <button type="button" onclick="addGroceryItem()" 
                                style="background:none;border:none;color:#6366f1;cursor:pointer;font-size:.8rem;font-weight:700;">
                            <i class="fas fa-plus"></i> Add Item
                        </button>
                    </div>
                    <div id="groceryItemsList" style="display:flex;flex-direction:column;gap:14px;"></div>
                </div>
            </form>

            <div style="padding:16px 28px 24px;display:flex;justify-content:flex-end;gap:10px;border-top:1px solid #f1f5f9;">
                <button onclick="closeGroceryModal()" class="btn btn-outline">Cancel</button>
                <button onclick="submitGroceryList()" id="submitGroceryBtn" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i> Submit to Ops Manager
                </button>
            </div>
        </div>
    </div>
    <?php endif; // grocery_lists tab ?>

    <!-- TAB: CATEGORIES -->
    <?php if ($active_tab === 'categories'): ?>
    <div class="card" style="border-radius:14px;border:1px solid var(--neutral-200);box-shadow:0 2px 8px rgba(0,0,0,0.04);">
        <div class="card-header" style="padding:18px 24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;background:#fafafa;">
            <div>
                <h3 style="margin:0;font-size:1.05rem;font-weight:700;">
                    <i class="fas fa-tags" style="color:#6366f1;margin-right:8px;"></i>Kitchen &amp; Pantry Categories
                </h3>
                <p style="margin:2px 0 0;font-size:.8rem;color:var(--neutral-500);">
                    Define ingredient categories used when creating grocery lists.
                </p>
            </div>
            <button onclick="openCategoryEditor()" class="btn btn-primary btn-sm" style="border-radius:8px;">
                <i class="fas fa-cog"></i> Set Up Categories
            </button>
        </div>

        <div class="card-body" style="padding:24px;">
            <?php if (empty($pantry_categories)): ?>
            <div style="text-align:center;padding:48px 24px;color:var(--neutral-400);">
                <i class="fas fa-tags" style="font-size:3rem;margin-bottom:12px;opacity:.4;"></i>
                <h4>No Categories Defined Yet</h4>
                <p style="font-size:.85rem;">Click "Set Up Categories" to select and configure your kitchen pantry categories.</p>
            </div>
            <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;">
                <?php foreach ($pantry_categories as $parent): ?>
                <div style="border:1px solid #e2e8f0;border-radius:12px;padding:16px;background:#fafafa;">
                    <div style="font-weight:800;font-size:.92rem;color:#1e293b;margin-bottom:10px;display:flex;align-items:center;gap:8px;">
                        <i class="fas fa-folder" style="color:#6366f1;"></i>
                        <?= htmlspecialchars($parent['name']) ?>
                        <span style="margin-left:auto;font-size:.72rem;color:#94a3b8;font-weight:500;">
                            <?= count($parent['children']) ?> sub
                        </span>
                    </div>
                    <?php if (!empty($parent['children'])): ?>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;">
                        <?php foreach ($parent['children'] as $child): ?>
                        <span style="font-size:.75rem;background:#ede9fe;color:#5b21b6;padding:3px 10px;border-radius:20px;font-weight:600;">
                            <?= htmlspecialchars($child['name']) ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p style="font-size:.78rem;color:var(--neutral-400);margin:0;">No sub-categories</p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Category Editor Modal -->
    <div id="categoryEditorModal" style="display:none;position:fixed;inset:0;z-index:9999;
         background:rgba(15,23,42,.5);backdrop-filter:blur(4px);align-items:flex-start;justify-content:center;overflow-y:auto;padding:32px 16px;">
        <div style="background:#fff;border-radius:16px;width:100%;max-width:800px;box-shadow:0 20px 60px rgba(0,0,0,.25);margin:auto;">
            <div style="padding:24px 28px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <h3 style="margin:0;font-weight:800;color:#1e293b;font-size:1.1rem;">
                        <i class="fas fa-tags" style="color:#6366f1;margin-right:8px;"></i>Select Kitchen Categories
                    </h3>
                    <p style="margin:4px 0 0;font-size:.82rem;color:#64748b;">Choose which sub-categories to use for your kitchen. You can customize this later.</p>
                </div>
                <button onclick="closeCategoryEditor()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#94a3b8;line-height:1;">&#x2715;</button>
            </div>
            <div style="padding:24px 28px;max-height:65vh;overflow-y:auto;">
                <div id="catPresetList" style="display:flex;flex-direction:column;gap:18px;"></div>
            </div>
            <div style="padding:16px 28px 24px;display:flex;justify-content:flex-end;gap:10px;border-top:1px solid #f1f5f9;">
                <button onclick="closeCategoryEditor()" class="btn btn-outline">Cancel</button>
                <button onclick="saveCategoriesFromModal()" id="saveCatBtn" class="btn btn-primary">
                    <i class="fas fa-check"></i> Save Categories
                </button>
            </div>
        </div>
    </div>
    <?php endif; // categories tab ?>

    <!-- TAB 5: RECIPES -->
    <?php if ($active_tab === 'recipes'): ?>
    <div class="card" style="border-radius:14px;border:1px solid var(--neutral-200);box-shadow:0 2px 8px rgba(0,0,0,0.04);">
        <div class="card-header" style="padding:18px 24px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <h3 style="margin:0;font-weight:700;color:var(--neutral-800);">
                <i class="fas fa-scroll" style="color:#8b5cf6;margin-right:8px;"></i>
                Recipe Management
            </h3>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" onclick="openAIMappingModal()" class="btn btn-outline btn-sm" style="border-radius:8px;border-color:#8b5cf6;color:#6d28d9;">
                    <i class="fas fa-wand-magic-sparkles"></i> AI-Assisted Mapping
                </button>
                <button onclick="openRecipeModal()" class="btn btn-primary btn-sm" style="border-radius:8px;">
                    <i class="fas fa-plus"></i> New Recipe
                </button>
            </div>
        </div>
        <div class="card-body" style="padding:24px;">
            <?php if (empty($menu_items)): ?>
            <div style="text-align:center;padding:36px 24px;color:var(--neutral-400);">
                <i class="fas fa-book" style="font-size:3rem;margin-bottom:12px;opacity:.5;"></i>
                <h4>No Menu Items Found</h4>
                <p style="font-size:.85rem;">Create menu items first to map recipes.</p>
            </div>
            <?php else: ?>
            <div style="display:grid;gap:16px;">
            <?php foreach ($menu_items as $menu):
                $menuId = (int)$menu['id'];
                $structuredMeta = $structured_recipe_meta[$menuId] ?? null;
                $hasStructuredRecipe = $structuredMeta !== null;
                $ingredients = $hasStructuredRecipe
                    ? ($structured_recipe_mappings[$menuId] ?? [])
                    : ($recipe_mappings[$menuId] ?? []);
                $ing_count = count($ingredients);
                $recipe_visibility = $structuredMeta['customer_visibility_status'] ?? '';
                $recipe_visibility_badges = [
                    'published' => ['Published to customers', '#dcfce7', '#166534'],
                    'needs_reapproval' => ['Needs Chef re-approval', '#fef3c7', '#92400e'],
                    'draft' => ['Chef review pending', '#f1f5f9', '#475569'],
                ];
                [$visibility_label, $visibility_bg, $visibility_fg] = $recipe_visibility_badges[$recipe_visibility] ?? ['Structured recipe', '#f1f5f9', '#475569'];
            ?>
            <div style="border:1px solid var(--neutral-200);border-radius:12px;background:#fff;overflow:hidden;">
                <!-- Card header -->
                <div style="display:flex;align-items:center;justify-content:space-between;
                            padding:16px 20px;border-bottom:<?= $ing_count ? '1px solid var(--neutral-100)' : 'none' ?>;">
                    <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                        <span style="font-weight:800;font-size:.95rem;color:#312e81;
                                     white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?= htmlspecialchars($menu['name']) ?>
                        </span>
                        <?php if ($menu['category'] ?? ''): ?>
                        <span style="font-size:.7rem;background:#ede9fe;color:#6d28d9;
                                     padding:2px 8px;border-radius:20px;white-space:nowrap;flex-shrink:0;">
                            <?= htmlspecialchars($menu['category']) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;margin-left:10px;">
                        <span style="font-size:.75rem;
                                     background:<?= $ing_count ? '#ede9fe' : '#f3f4f6' ?>;
                                     color:<?= $ing_count ? '#6d28d9' : '#6b7280' ?>;
                                     padding:3px 10px;border-radius:20px;font-weight:700;">
                            <?= $ing_count ?> ingredient<?= $ing_count !== 1 ? 's' : '' ?>
                        </span>                        <?php if ($hasStructuredRecipe): ?>
                        <span style="font-size:.68rem;background:<?= $visibility_bg ?>;color:<?= $visibility_fg ?>;padding:3px 9px;border-radius:20px;font-weight:700;white-space:nowrap;">
                            <?= htmlspecialchars($visibility_label) ?>
                        </span>
                        <?php endif; ?>
                        <button onclick="quickAddIngredient(<?= $menu['id'] ?>)"
                                class="btn btn-outline btn-sm"
                                title="Add ingredient to this recipe">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>
                </div>

                <?php if ($ing_count): ?>
                <!-- Ingredient list -->
                <div style="padding:0;">
                    <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
                        <thead>
                            <tr style="background:#fafafa;border-bottom:1px solid var(--neutral-100);">
                                <th style="padding:8px 20px;text-align:left;font-weight:700;
                                           color:var(--neutral-500);text-transform:uppercase;
                                           font-size:.7rem;letter-spacing:.04em;">Ingredient</th>
                                <th style="padding:8px 12px;text-align:right;font-weight:700;
                                           color:var(--neutral-500);text-transform:uppercase;
                                           font-size:.7rem;letter-spacing:.04em;white-space:nowrap;">Qty Needed</th>
                                <th style="padding:8px 12px;text-align:right;font-weight:700;
                                           color:var(--neutral-500);text-transform:uppercase;
                                           font-size:.7rem;letter-spacing:.04em;white-space:nowrap;">In Stock</th>
                                <th style="padding:8px 12px;text-align:center;font-weight:700;
                                           color:var(--neutral-500);text-transform:uppercase;
                                           font-size:.7rem;letter-spacing:.04em;">Halal</th>
                                <th style="padding:8px 12px;text-align:center;font-weight:700;
                                           color:var(--neutral-500);text-transform:uppercase;
                                           font-size:.7rem;letter-spacing:.04em;width:40px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($ingredients as $ing):
                            $halal_colors = [
                                'halal'          => ['#dcfce7','#166534','Halal'],
                                'self_certified'  => ['#d1fae5','#065f46','Self-Cert'],
                                'fresh_produce'  => ['#cffafe','#0e7490','Fresh'],
                                'pending_cert'   => ['#fef9c3','#854d0e','Pending'],
                                'non_halal'      => ['#fee2e2','#991b1b','Non-Halal'],
                            ];
                            [$hbg,$hfg,$hlbl] = $halal_colors[$ing['halal_status']] ?? ['#f1f5f9','#334155',$ing['halal_status']];
                            $stock_ok = (float)$ing['stock'] > 0;
                        ?>
                        <tr style="border-bottom:1px solid var(--neutral-50);
                                   transition:background .1s;"
                            onmouseover="this.style.background='#fafafa'"
                            onmouseout="this.style.background=''">
                            <td style="padding:10px 20px;font-weight:600;color:var(--neutral-800);">
                                <?= htmlspecialchars($ing['ingredient_name']) ?>
                                <?php if ((int)$ing['is_meat']): ?>
                                <span style="font-size:.65rem;background:#fee2e2;color:#991b1b;
                                             padding:1px 6px;border-radius:999px;margin-left:4px;
                                             font-weight:700;vertical-align:middle;">MEAT</span>
                                <?php endif; ?>
                                <?php if (!empty($ing['supplier_name'])): ?>
                                <div style="font-size:.72rem;color:var(--neutral-400);margin-top:2px;">
                                    <i class="fas fa-truck" style="margin-right:3px;"></i>
                                    <?= htmlspecialchars($ing['supplier_name']) ?>
                                    <?php if (($ing['supplier_status'] ?? '') === 'verified'): ?>
                                    <i class="fas fa-check-circle" style="color:#16a34a;margin-left:2px;" title="Verified supplier"></i>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px 12px;text-align:right;color:var(--neutral-700);white-space:nowrap;">
                                <strong><?= number_format($ing['quantity_needed'], 3) ?></strong>
                                <span style="color:var(--neutral-400);margin-left:2px;"><?= htmlspecialchars($ing['unit']) ?></span>
                            </td>
                            <td style="padding:10px 12px;text-align:right;white-space:nowrap;">
                                <span style="font-weight:700;color:<?= $stock_ok ? '#16a34a' : '#dc2626' ?>;">
                                    <?= number_format((float)$ing['stock'], 2) ?>
                                </span>
                                <span style="color:var(--neutral-400);margin-left:2px;font-size:.78rem;">
                                    <?= htmlspecialchars($ing['stock_unit']) ?>
                                </span>
                                <?php if (!$stock_ok): ?>
                                <span style="font-size:.65rem;background:#fee2e2;color:#991b1b;
                                             padding:1px 5px;border-radius:999px;margin-left:3px;font-weight:700;">
                                    OUT
                                </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px 12px;text-align:center;">
                                <span style="font-size:.7rem;background:<?= $hbg ?>;color:<?= $hfg ?>;
                                             padding:2px 8px;border-radius:999px;font-weight:700;white-space:nowrap;">
                                    <?= $hlbl ?>
                                </span>
                            </td>
                            <td style="padding:10px 12px;text-align:center;">
                                                                <?php if (empty($ing['is_structured'])): ?>
                                <button onclick="removeRecipeItem(<?= (int)$ing['id'] ?>)"
                                        title="Remove this ingredient from recipe"
                                        style="background:none;border:none;cursor:pointer;
                                               color:#dc2626;opacity:.6;font-size:.85rem;
                                               padding:3px 6px;border-radius:4px;
                                               transition:opacity .15s;"
                                        onmouseover="this.style.opacity='1'"
                                        onmouseout="this.style.opacity='.6'">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                                <?php else: ?>
                                <span title="Chef-confirmed structured recipe mapping" style="color:#16a34a;font-size:.85rem;">
                                    <i class="fas fa-check-circle"></i>
                                </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div style="padding:16px 20px;font-size:.83rem;color:var(--neutral-400);
                            font-style:italic;">
                    No ingredients mapped yet.
                    <button onclick="quickAddIngredient(<?= $menu['id'] ?>)"
                            style="background:none;border:none;color:#8b5cf6;cursor:pointer;
                                   font-size:.83rem;font-weight:600;padding:0;margin-left:4px;
                                   text-decoration:underline;text-underline-offset:2px;">
                        Add the first ingredient →
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="aiRecipeMappingModal" style="display:none;position:fixed;inset:0;z-index:10020;background:rgba(15,23,42,.60);padding:24px 14px;overflow-y:auto;">
        <div style="max-width:1000px;margin:20px auto;background:#fff;border-radius:16px;box-shadow:0 24px 64px rgba(15,23,42,.32);overflow:hidden;">
            <div style="padding:20px 24px;background:linear-gradient(120deg,#312e81,#5b21b6);color:#fff;display:flex;justify-content:space-between;gap:16px;align-items:flex-start;">
                <div>
                    <h3 style="margin:0;font-size:1.1rem;"><i class="fas fa-wand-magic-sparkles" style="margin-right:8px;"></i>AI-Assisted Ingredient Mapping</h3>
                    <p style="margin:6px 0 0;font-size:.82rem;color:#ddd6fe;">AI may suggest a matching inventory item. A Chef must confirm every mapping before it is used for availability or stock reservations.</p>
                </div>
                <button type="button" onclick="closeAIMappingModal()" aria-label="Close" style="border:0;background:transparent;color:#fff;font-size:1.5rem;line-height:1;cursor:pointer;">&times;</button>
            </div>
            <div style="padding:22px 24px;display:grid;gap:18px;">
                <div style="display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:end;padding:16px;border:1px solid #ddd6fe;border-radius:12px;background:#fafaff;">
                    <label style="display:grid;gap:6px;font-size:.8rem;font-weight:700;color:#4c1d95;">
                        1. Select a menu item
                        <select id="aiRecipeMenuId" class="form-control" style="min-width:0;">
                            <option value="">Select a menu item</option>
                            <?php foreach ($menu_items as $aiMenuItem): ?>
                            <option value="<?= (int)$aiMenuItem['id'] ?>"><?= htmlspecialchars($aiMenuItem['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="button" class="btn btn-primary btn-sm" onclick="loadAIMappingRecipe()" style="height:38px;white-space:nowrap;"><i class="fas fa-folder-open"></i> Load recipe</button>
                </div>

                <div id="aiRecipeCreatePanel" style="display:none;padding:16px;border:1px solid #bfdbfe;border-radius:12px;background:#f8fbff;">
                    <div style="font-size:.86rem;color:#1e3a8a;font-weight:700;margin-bottom:12px;">No structured recipe exists for this menu item yet.</div>
                    <div style="display:grid;grid-template-columns:1.2fr .8fr auto;gap:10px;align-items:end;">
                        <label style="display:grid;gap:5px;font-size:.78rem;font-weight:700;color:#475569;">Recipe name
                            <input id="aiRecipeName" class="form-control" maxlength="180" placeholder="e.g. Chicken Adobo standard recipe">
                        </label>
                        <label style="display:grid;gap:5px;font-size:.78rem;font-weight:700;color:#475569;">Serving size
                            <input id="aiRecipeServing" class="form-control" maxlength="120" placeholder="e.g. 1 serving">
                        </label>
                        <button type="button" class="btn btn-primary btn-sm" onclick="createAIMappingRecipe()" style="height:38px;white-space:nowrap;"><i class="fas fa-plus"></i> Create recipe</button>
                    </div>
                </div>

                <div id="aiRecipeWorkspace" style="display:none;">
                    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-bottom:12px;">
                        <div>
                            <div id="aiRecipeTitle" style="font-weight:800;color:#312e81;"></div>
                            <div id="aiRecipeMeta" style="font-size:.78rem;color:#64748b;margin-top:3px;"></div>
                        </div>
                        <span style="font-size:.76rem;font-weight:700;color:#166534;background:#ecfdf5;border:1px solid #bbf7d0;padding:6px 10px;border-radius:8px;"><i class="fas fa-user-chef"></i> Chef-controlled ingredient review</span>
                    </div>

                    <div style="padding:16px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;">
                        <div style="font-size:.84rem;font-weight:800;color:#334155;margin-bottom:10px;">2. Add recipe ingredient</div>
                        <p style="margin:0 0 12px;font-size:.76rem;color:#64748b;">Leave “Direct inventory mapping” blank to have the system look for a match. Select an item only when the Chef already knows the correct record.</p>
                        <div style="display:grid;grid-template-columns:minmax(150px,1.2fr) 100px 100px minmax(170px,1fr) auto;gap:8px;align-items:end;">
                            <label style="display:grid;gap:5px;font-size:.73rem;font-weight:700;color:#475569;">Recipe ingredient
                                <input id="aiIngredientName" class="form-control" maxlength="180" placeholder="e.g. Boneless chicken breast">
                            </label>
                            <label style="display:grid;gap:5px;font-size:.73rem;font-weight:700;color:#475569;">Quantity
                                <input id="aiIngredientQuantity" class="form-control" type="number" min="0.001" step="0.001" placeholder="0.200">
                            </label>
                            <label style="display:grid;gap:5px;font-size:.73rem;font-weight:700;color:#475569;">Unit
                                <select id="aiIngredientUnit" class="form-control"><option value="g">g</option><option value="kg">kg</option><option value="mg">mg</option><option value="mL">mL</option><option value="L">L</option><option value="each">each</option></select>
                            </label>
                            <label style="display:grid;gap:5px;font-size:.73rem;font-weight:700;color:#475569;">Direct inventory mapping
                                <select id="aiIngredientInventoryId" class="form-control"><option value="">Ask AI / leave unmatched</option>
                                    <?php foreach ($inventory_list as $aiInventory): ?><option value="<?= (int)$aiInventory['id'] ?>"><?= htmlspecialchars($aiInventory['ingredient_name']) ?> (<?= htmlspecialchars($aiInventory['unit']) ?>)</option><?php endforeach; ?>
                                </select>
                            </label>
                            <button type="button" class="btn btn-outline btn-sm" onclick="addAIMappingIngredient()" style="height:38px;white-space:nowrap;"><i class="fas fa-plus"></i> Add</button>
                        </div>
                    </div>

                    <div style="margin-top:16px;padding:16px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
                            <div style="font-size:.84rem;font-weight:800;color:#334155;">Recipe ingredients and mapping state</div>
                            <button type="button" id="aiSuggestButton" class="btn btn-primary btn-sm" onclick="generateAIMappings()" style="border-radius:8px;"><i class="fas fa-wand-magic-sparkles"></i> Generate AI-Assisted Mapping</button>
                        </div>
                        <div id="aiRecipeIngredientList" style="font-size:.82rem;color:#64748b;">No ingredients recorded yet.</div>
                        <div id="aiRecipePublishControls" style="margin-top:14px;padding:12px;border-radius:10px;background:#f8fafc;border:1px solid #e2e8f0;"></div>
                    </div>

                    <div id="aiMappingSuggestions" style="display:none;margin-top:16px;padding:16px;border:1px solid #c4b5fd;border-radius:12px;background:#faf5ff;"></div>
                </div>
                <div id="aiMappingNotice" role="status" style="display:none;padding:10px 12px;border-radius:8px;font-size:.82rem;"></div>
            </div>
        </div>
    </div>
    <?php endif; // recipes tab ?>

    <!-- TAB 6: POS GUARDRAILS (Menu Status) -->
    <?php if ($active_tab === 'availability'): ?>
    <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <div>
                <h3 style="margin:0;"><i class="fas fa-chart-pie" style="color:#6366f1;margin-right:8px;"></i> Menu Availability</h3>
                <p style="margin:5px 0 0;font-size:.8rem;color:var(--neutral-500);">Calculated from confirmed recipe mappings and usable, unexpired batch stock. Evidence findings require review; they are not certification decisions.</p>
            </div>
            <a href="<?= BASE_URL ?>dashboard/business_owner/api/ingredient_menu_api.php" style="font-size:.75rem;color:var(--neutral-400);">API enabled by feature flag</a>
        </div>
        <div class="card-body" style="padding:0;">
        <?php $availabilityService = new IngredientInventoryService($conn); ?>
        <?php if (!$availabilityService->schemaReady()): ?>
            <p style="padding:20px;color:#b45309;">Run the ingredient-menu migration before enabling availability controls.</p>
        <?php else: ?>
            <div class="table-responsive"><table class="table" style="margin:0;"><thead><tr><th>Menu item</th><th>Operational status</th><th>Available portions</th><th>Ingredient evidence</th></tr></thead><tbody>
            <?php foreach ($menu_items as $availabilityMenu): $availability = $availabilityService->availabilityForMenu($rest_id, (int)$availabilityMenu['id'], true); ?>
                <tr><td style="font-weight:700;"><?= htmlspecialchars($availabilityMenu['name']) ?></td>
                    <td><?= htmlspecialchars(str_replace('_', ' ', $availability['operational_status'])) ?></td>
                    <td><?= (int)$availability['maximum_servings'] ?></td>
                    <td><?= htmlspecialchars(str_replace('_', ' ', $availability['ingredient_evidence_status'])) ?><?= $availability['evidence_review_required'] ? ' · review required' : '' ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($active_tab === 'menu_status'): ?>
    <div class="card" style="border-radius:14px;border:1px solid var(--neutral-200);box-shadow:0 2px 8px rgba(0,0,0,0.04);">
        <div class="card-header" style="padding:18px 24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:#fafafa;">
            <div>
                <h3 style="margin:0;font-weight:700;color:var(--neutral-800);font-size:1.05rem;">
                    <i class="fas fa-toggle-on" style="color:#0284c7;margin-right:8px;"></i>
                    POS Guardrails — Menu Availability
                </h3>
                <p style="margin:3px 0 0;font-size:.78rem;color:var(--neutral-500);">
                    Toggle items on/off. Unavailable items are hidden from the POS and customer menu.
                </p>
            </div>
            <span style="font-size:.75rem;background:#e0e7ff;color:#4f46e5;padding:4px 12px;border-radius:20px;font-weight:700;">
                <?= count($menu_items) ?> item(s)
            </span>
        </div>
        <div class="card-body" style="padding:24px;">
            <?php if (empty($menu_items)): ?>
            <div style="text-align:center;padding:36px 24px;color:var(--neutral-400);">
                <i class="fas fa-list" style="font-size:3rem;margin-bottom:12px;opacity:.5;display:block;"></i>
                <h4 style="margin:0 0 8px;">No Menu Items to Manage</h4>
                <p style="font-size:.85rem;margin:0;">Menu status controls will appear once menu items are added.</p>
            </div>
            <?php else: ?>
            <div style="display:grid;gap:10px;">
            <?php foreach ($menu_items as $menu):
                $is_avail = (int)($menu['is_available'] ?? 1) === 1;
                $mapped   = (int)($menu['mapped_count'] ?? 0);
            ?>
            <div id="menu-row-<?= $menu['id'] ?>"
                 style="border:1.5px solid <?= $is_avail ? '#bbf7d0' : '#fca5a5' ?>;border-radius:10px;
                        padding:14px 18px;background:<?= $is_avail ? '#f0fdf4' : '#fff5f5' ?>;
                        display:flex;align-items:center;justify-content:space-between;gap:12px;
                        transition:background .2s,border-color .2s;">
                <!-- Left: info -->
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;color:var(--neutral-800);font-size:.95rem;
                                white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?= htmlspecialchars($menu['name']) ?>
                    </div>
                    <div style="font-size:.78rem;color:#6b7280;margin-top:3px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span id="menu-status-text-<?= $menu['id'] ?>"
                              style="font-weight:700;color:<?= $is_avail ? '#16a34a' : '#dc2626' ?>;">
                            <?= $is_avail ? '● Available' : '● Out of Stock' ?>
                        </span>
                        <?php if ($menu['category'] ?? ''): ?>
                        <span style="color:var(--neutral-400);">·</span>
                        <span><?= htmlspecialchars($menu['category']) ?></span>
                        <?php endif; ?>
                        <span style="color:var(--neutral-400);">·</span>
                        <span><?= $mapped ?> ingredient<?= $mapped !== 1 ? 's' : '' ?> mapped</span>
                    </div>
                </div>

                <!-- Right: toggle switch -->
                <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;">
                    <span id="menu-toggle-label-<?= $menu['id'] ?>"
                          style="font-size:.8rem;font-weight:600;color:var(--neutral-500);min-width:28px;text-align:right;">
                        <?= $is_avail ? 'On' : 'Off' ?>
                    </span>
                    <input type="checkbox"
                           id="menu-toggle-<?= $menu['id'] ?>"
                           <?= $is_avail ? 'checked' : '' ?>
                           onchange="toggleMenuStatus(<?= $menu['id'] ?>, this.checked)"
                           style="display:none;">
                    <label for="menu-toggle-<?= $menu['id'] ?>"
                           id="menu-toggle-thumb-<?= $menu['id'] ?>"
                           style="display:block;position:relative;width:44px;height:24px;
                                  border-radius:24px;cursor:pointer;
                                  background:<?= $is_avail ? '#16a34a' : '#d1d5db' ?>;
                                  transition:background .2s;">
                        <span id="menu-toggle-knob-<?= $menu['id'] ?>"
                              style="position:absolute;top:3px;
                                     left:<?= $is_avail ? '23px' : '3px' ?>;
                                     width:18px;height:18px;border-radius:50%;background:#fff;
                                     transition:left .2s;box-shadow:0 1px 3px rgba(0,0,0,.25);
                                     pointer-events:none;"></span>
                    </label>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; // menu_status tab ?>

    <!-- TAB 7: MENU SUBMISSION ─────────────────────────────────────────────── -->
    <?php if ($active_tab === 'menu_submit'): ?>
    <?php
    $add_url    = BASE_URL . 'dashboard/business_owner/api/add_menu_item.php';
    $update_url = BASE_URL . 'dashboard/business_owner/api/update_menu_item.php';
    // Workflow states are distinct from the legacy approval enum.
    $cmi_drafts = array_values(array_filter($chef_menu_items, fn($m) => ($m['workflow_status'] ?? '') === 'draft'));
    $cmi_pending = array_values(array_filter($chef_menu_items, fn($m) => ($m['workflow_status'] ?? '') === 'pending_review'));
    $cmi_revision = array_values(array_filter($chef_menu_items, fn($m) => ($m['workflow_status'] ?? '') === 'needs_revision'));
    $cmi_rejected = array_values(array_filter($chef_menu_items, fn($m) => ($m['workflow_status'] ?? '') === 'rejected'));
    $cmi_approved = array_values(array_filter($chef_menu_items, fn($m) => ($m['workflow_status'] ?? '') === 'approved' || (($m['workflow_status'] ?? '') === 'legacy' && ($m['approval_status'] ?? '') === 'approved')));
    ?>

    <!-- Info banner -->
    <div style="background:linear-gradient(135deg,#eff6ff,#f0fdf4);border:1.5px solid #bfdbfe;
                border-radius:12px;padding:14px 18px;margin-bottom:20px;
                display:flex;align-items:flex-start;gap:12px;">
        <i class="fas fa-info-circle" style="color:#1d4ed8;font-size:1.1rem;margin-top:1px;flex-shrink:0;"></i>
        <div style="font-size:.85rem;color:#1e40af;line-height:1.55;">
            <strong>How menu submissions work:</strong>
            Create a menu as a <em>Draft</em>, complete its structured recipe, and Chef-confirm every ingredient-to-inventory mapping.
            Only then can it be submitted to the Operations Manager. Manager approval publishes the reviewed recipe; stock and ingredient evidence are checked separately for orderability.
        </div>
    </div>

    <!-- Add New Dish form -->
    <div class="card" style="margin-bottom:20px;border-radius:14px;border:1px solid var(--neutral-200);">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
            <h3 style="margin:0;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-plus" style="color:var(--primary-600);"></i> Create Menu Draft
            </h3>
            <button type="button" id="chefAddDishBtn" class="btn btn-primary btn-sm">
                <i class="fas fa-plus"></i> Add Dish Row
            </button>
        </div>
        <div class="card-body">
            <div id="chefMenuToast" style="display:none;padding:10px 16px;border-radius:8px;font-size:.875rem;font-weight:600;margin-bottom:14px;"></div>
            <div id="chefDishRows" style="display:flex;flex-direction:column;gap:14px;"></div>
            <div id="chefMenuSubmitRow" style="display:none;justify-content:flex-end;gap:10px;margin-top:14px;">
                <button type="button" id="chefSaveMenuBtn" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Draft
                </button>
            </div>
            <div id="chefMenuEmpty" style="text-align:center;padding:32px 24px;color:var(--neutral-400);">
                <i class="fas fa-plus-circle" style="font-size:2rem;margin-bottom:10px;display:block;opacity:.3;"></i>
                <p style="margin:0;font-size:.9rem;">Click "Add Dish Row" to create a menu draft, then complete its recipe.</p>
            </div>
        </div>
    </div>

    <!-- Draft Menus -->
<?php if (!empty($cmi_drafts)): ?>
<div class="card" style="margin-bottom:20px;border:2px solid #bfdbfe;">
    <div class="card-header" style="background:#eff6ff;"><h3 style="color:#1d4ed8;margin:0;"><i class="fas fa-pen-ruler"></i> Draft Menus</h3></div>
    <div class="card-body" style="padding:0;">
    <?php foreach ($cmi_drafts as $item): $draftMeta = $structured_recipe_meta[(int)$item['id']] ?? null; $isReady = $draftMeta && (int)$draftMeta['total_count'] > 0 && (int)$draftMeta['confirmed_count'] === (int)$draftMeta['total_count']; ?>
        <div style="display:flex;align-items:center;gap:12px;padding:13px 18px;border-bottom:1px solid #dbeafe;flex-wrap:wrap;">
            <div style="flex:1;min-width:180px;"><strong><?= htmlspecialchars($item['name']) ?></strong><div style="font-size:.75rem;color:#64748b;"><?= htmlspecialchars($item['category'] ?? '') ?> &middot; <?= formatCurrency($item['price']) ?> &middot; <?= $draftMeta ? (int)$draftMeta['confirmed_count'].'/'.(int)$draftMeta['total_count'].' mappings confirmed' : 'Recipe not created' ?></div></div>
            <a href="?tab=recipes" class="btn btn-sm btn-outline"><i class="fas fa-scroll"></i> <?= $draftMeta ? 'Complete recipe' : 'Create recipe' ?></a>
            <?php if ($isReady): ?><button type="button" class="btn btn-sm btn-primary" onclick="submitMenuForManager(<?= (int)$item['id'] ?>)"><i class="fas fa-paper-plane"></i> Submit review</button><?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline" style="color:#b91c1c;border-color:#fecaca;" onclick="deleteChefMenu(<?= (int)$item['id'] ?>, <?= htmlspecialchars(json_encode($item['name']), ENT_QUOTES) ?>)"><i class="fas fa-trash"></i> Delete</button>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Needs Revision -->
<?php if (!empty($cmi_revision)): ?>
<div class="card" style="margin-bottom:20px;border:2px solid #fdba74;">
    <div class="card-header" style="background:#fff7ed;"><h3 style="color:#9a3412;margin:0;"><i class="fas fa-rotate-left"></i> Needs Revision</h3></div>
    <div class="card-body" style="padding:0;">
    <?php foreach ($cmi_revision as $item): ?>
        <div style="padding:13px 18px;border-bottom:1px solid #fed7aa;"><strong><?= htmlspecialchars($item['name']) ?></strong><div style="font-size:.8rem;color:#9a3412;margin-top:4px;"><strong>Manager feedback:</strong> <?= htmlspecialchars($item['rejection_reason'] ?: 'Review the recipe and resubmit.') ?></div><a href="?tab=recipes" class="btn btn-sm btn-outline" style="margin-top:8px;"><i class="fas fa-scroll"></i> Revise recipe</a> <button type="button" class="btn btn-sm btn-outline" style="margin-top:8px;color:#b91c1c;border-color:#fecaca;" onclick="deleteChefMenu(<?= (int)$item['id'] ?>, <?= htmlspecialchars(json_encode($item['name']), ENT_QUOTES) ?>)"><i class="fas fa-trash"></i> Delete</button></div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<!-- Pending Submissions -->
    <?php if (!empty($cmi_pending)): ?>
    <div class="card" style="margin-bottom:20px;border:2px solid #fde68a;">
        <div class="card-header" style="background:#fef3c7;">
            <h3 style="color:#92400e;margin:0;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-clock"></i> Awaiting Approval
                <span style="background:#fde68a;color:#78350f;font-size:.72rem;padding:2px 9px;border-radius:999px;font-weight:800;"><?= count($cmi_pending) ?></span>
            </h3>
        </div>
        <div class="card-body" style="padding:0;">
        <?php foreach ($cmi_pending as $item): ?>
        <div style="display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid #fef3c7;background:#fffbeb;">
            <div style="width:44px;height:44px;background:#fef9c3;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas fa-clock" style="color:#d97706;"></i>
            </div>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:.9rem;"><?= htmlspecialchars($item['name']) ?></div>
                <div style="font-size:.75rem;color:var(--neutral-400);"><?= htmlspecialchars($item['category']??'') ?> · <?= formatCurrency($item['price']) ?></div>
            </div>
            <span style="background:#fef3c7;color:#92400e;font-size:.72rem;padding:3px 10px;border-radius:999px;font-weight:700;white-space:nowrap;">
                <i class="fas fa-hourglass-half"></i> Pending Review
            </span>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Rejected Items -->
    <?php if (!empty($cmi_rejected)): ?>
    <div class="card" style="margin-bottom:20px;border:2px solid #fecaca;">
        <div class="card-header" style="background:#fef2f2;">
            <h3 style="color:#991b1b;margin:0;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-circle-xmark"></i> Rejected — Needs Revision
                <span style="background:#fecaca;color:#991b1b;font-size:.72rem;padding:2px 9px;border-radius:999px;font-weight:800;"><?= count($cmi_rejected) ?></span>
            </h3>
        </div>
        <div class="card-body" style="padding:0;">
        <?php foreach ($cmi_rejected as $item): ?>
        <div style="padding:14px 18px;border-bottom:1px solid #fee2e2;">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;font-size:.9rem;color:var(--neutral-800);"><?= htmlspecialchars($item['name']) ?></div>
                    <div style="font-size:.75rem;color:var(--neutral-400);margin-top:2px;"><?= htmlspecialchars($item['category']??'') ?> · <?= formatCurrency($item['price']) ?></div>
                </div>
                <button type="button"
                        onclick='openChefEditRow(<?= htmlspecialchars(json_encode([
                            'id'           => $item['id'],
                            'name'         => $item['name'],
                            'description'  => $item['description'] ?? '',
                            'price'        => $item['price'],
                            'category'     => $item['category'] ?? 'Main Course',
                            'serving_type' => $item['serving_type'] ?? 'Per serving',
                            'image_url'    => !empty($item['image_path']) ? BASE_URL.'uploads/'.htmlspecialchars($item['image_path']) : '',
                        ]), ENT_QUOTES) ?>)'
                        class="btn btn-sm btn-outline" style="color:#d97706;border-color:#fde68a;">
                    <i class="fas fa-pencil"></i> Edit &amp; Resubmit
                </button>
                <button type="button" class="btn btn-sm btn-outline" style="color:#b91c1c;border-color:#fecaca;" onclick="deleteChefMenu(<?= (int)$item['id'] ?>, <?= htmlspecialchars(json_encode($item['name']), ENT_QUOTES) ?>)">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </div>
            <?php if ($item['rejection_reason']): ?>
            <div style="margin-top:10px;padding:9px 13px;background:#fee2e2;border-radius:8px;
                        font-size:.82rem;color:#991b1b;border-left:3px solid #dc2626;">
                <i class="fas fa-comment-exclamation" style="margin-right:5px;"></i>
                <strong>Reason for rejection:</strong> <?= htmlspecialchars($item['rejection_reason']) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Approved (Live) Items -->
    <?php if (!empty($cmi_approved)): ?>
    <div class="card" style="border:1px solid var(--neutral-200);">
        <div class="card-header">
            <h3 style="margin:0;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-circle-check" style="color:#16a34a;"></i>
                Approved & Live
                <span style="background:#dcfce7;color:#15803d;font-size:.72rem;padding:2px 9px;border-radius:999px;font-weight:700;"><?= count($cmi_approved) ?></span>
            </h3>
        </div>
        <div class="card-body" style="padding:0;">
        <?php foreach ($cmi_approved as $item): ?>
        <div style="display:flex;align-items:center;gap:12px;padding:11px 18px;border-bottom:1px solid var(--neutral-100);">
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:.9rem;"><?= htmlspecialchars($item['name']) ?></div>
                <div style="font-size:.75rem;color:var(--neutral-400);"><?= htmlspecialchars($item['category']??'') ?> · <?= formatCurrency($item['price']) ?></div>
            </div>
            <span style="background:#dcfce7;color:#15803d;font-size:.7rem;padding:3px 10px;border-radius:999px;font-weight:700;white-space:nowrap;">
                <i class="fas fa-circle-check"></i> Live
            </span>
            <button type="button"
                    onclick='openChefEditRow(<?= htmlspecialchars(json_encode([
                        'id'           => $item['id'],
                        'name'         => $item['name'],
                        'description'  => $item['description'] ?? '',
                        'price'        => $item['price'],
                        'category'     => $item['category'] ?? 'Main Course',
                        'serving_type' => $item['serving_type'] ?? 'Per serving',
                        'image_url'    => !empty($item['image_path']) ? BASE_URL.'uploads/'.htmlspecialchars($item['image_path']) : '',
                    ]), ENT_QUOTES) ?>)'
                    class="btn btn-sm btn-outline" title="Edit (will require re-approval)">
                <i class="fas fa-pencil"></i>
            </button>
                <button type="button" class="btn btn-sm btn-outline" style="color:#b91c1c;border-color:#fecaca;" title="Delete live menu" onclick="deleteChefMenu(<?= (int)$item['id'] ?>, <?= htmlspecialchars(json_encode($item['name']), ENT_QUOTES) ?>, true)">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // menu_submit tab ?>

    <!-- Modal Overlay -->
    <div id="modalOverlay" class="modal-overlay"></div>

    <!-- Recipe Modal (Add Ingredient to Menu Item) -->
    <div id="recipeModal" class="modal-slide">
        <div style="padding:24px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
                <h3 style="margin:0;font-weight:800;color:#1e293b;">
                    <i class="fas fa-scroll" style="color:#8b5cf6;margin-right:8px;"></i>
                    Add Ingredient to Recipe
                </h3>
                <button onclick="closeAllModals()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#94a3b8;">&times;</button>
            </div>
            
            <form id="recipeForm" onsubmit="submitRecipeForm(event)">
                <input type="hidden" id="rec_menu_id" name="menu_item_id" value="">
                
                <div style="margin-bottom:20px;">
                    <label style="display:block;font-size:.85rem;font-weight:700;color:var(--neutral-600);margin-bottom:8px;">
                        Select Ingredient from Inventory
                    </label>
                    <select id="rec_inventory_id" name="inventory_id" class="form-control" required style="height:40px;">
                        <option value="">-- Choose Ingredient --</option>
                        <?php if (!empty($inventory_list)): ?>
                            <?php foreach ($inventory_list as $inv): ?>
                                <option value="<?= $inv['id'] ?>" 
                                        data-unit="<?= htmlspecialchars($inv['unit']) ?>"
                                        data-name="<?= htmlspecialchars($inv['ingredient_name']) ?>">
                                    <?= htmlspecialchars($inv['ingredient_name']) ?> 
                                    (<?= htmlspecialchars($inv['unit']) ?> - <?= number_format($inv['quantity'], 2) ?> in stock)
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="" disabled>No inventory items available</option>
                        <?php endif; ?>
                    </select>
                    <?php if (empty($inventory_list)): ?>
                        <div style="margin-top:8px;font-size:.8rem;color:#dc2626;">
                            <i class="fas fa-exclamation-triangle"></i> No inventory items found. Add ingredients to inventory first.
                        </div>
                    <?php endif; ?>
                </div>
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
                    <div>
                        <label style="display:block;font-size:.85rem;font-weight:700;color:var(--neutral-600);margin-bottom:8px;">
                            Quantity Needed
                        </label>
                        <input type="number" id="rec_quantity" name="quantity_needed" 
                               class="form-control" step="0.001" min="0.001" value="1" required
                               style="height:40px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:.85rem;font-weight:700;color:var(--neutral-600);margin-bottom:8px;">
                            Unit
                        </label>
                        <input type="text" id="rec_unit" name="unit" 
                               class="form-control" value="g" required
                               style="height:40px;">
                    </div>
                </div>
                
                <div style="display:flex;gap:10px;">
                    <button type="button" onclick="closeAllModals()" class="btn btn-outline" style="flex:1;">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="flex:2;">
                        <i class="fas fa-plus"></i> Add to Recipe
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Inventory Modal -->
    <div id="inventoryModal" class="modal-slide">
        <div style="padding:24px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
                <h3 id="invModalTitle" style="margin:0;font-weight:800;color:#1e293b;">
                    <i class="fas fa-boxes-stacked" style="color:#6366f1;margin-right:8px;"></i>
                    Add Kitchen Ingredient
                </h3>
                <button onclick="closeAllModals()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#94a3b8;">&times;</button>
            </div>
            
            <form id="inventoryForm" onsubmit="submitInventoryForm(event)">
                <input type="hidden" id="inv_id" name="inventory_id" value="0">
                
                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:.85rem;font-weight:700;color:var(--neutral-600);margin-bottom:6px;">
                        Ingredient Name *
                    </label>
                    <input type="text" id="inv_name" name="ingredient_name" class="form-control" required
                           style="height:38px;">
                </div>
                
                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:.85rem;font-weight:700;color:var(--neutral-600);margin-bottom:6px;">
                        Expiration Date *
                    </label>
                    <input type="date" id="inv_expiration_date" name="expiration_date" class="form-control" required
                           style="height:38px;">
                </div>
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                    <div>
                        <label style="display:block;font-size:.85rem;font-weight:700;color:var(--neutral-600);margin-bottom:6px;">
                            Halal Status *
                        </label>
                        <select id="inv_halal" name="halal_status" class="form-control" required style="height:38px;">
                            <option value="pending_cert">Pending Certification</option>
                            <option value="halal">Halal Certified</option>
                            <option value="self_certified">Muslim-Owned / Self-Certified</option>
                            <option value="fresh_produce">Fresh Produce</option>
                            <option value="non_halal">Non-Halal</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:.85rem;font-weight:700;color:var(--neutral-600);margin-bottom:6px;">
                            Is Meat?
                        </label>
                        <div style="display:flex;align-items:center;gap:8px;margin-top:10px;">
                            <input type="checkbox" id="inv_is_meat" name="is_meat" 
                                   onchange="toggleMeatSupplierSelect()">
                            <label for="inv_is_meat" style="margin:0;cursor:pointer;">Yes, this is meat</label>
                        </div>
                    </div>
                </div>
                
                <div id="meatSupplierGroup" style="display:none;margin-bottom:16px;">
                    <label style="display:block;font-size:.85rem;font-weight:700;color:var(--neutral-600);margin-bottom:6px;">
                        Meat Supplier
                    </label>
                    <select id="inv_supplier_id" name="supplier_id" class="form-control" style="height:38px;">
                        <option value="">-- Select Supplier --</option>
                        <?php foreach ($suppliers_list as $sup): ?>
                            <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['supplier_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px;">
                    <div>
                        <label style="display:block;font-size:.85rem;font-weight:700;color:var(--neutral-600);margin-bottom:6px;">
                            Quantity *
                        </label>
                        <input type="number" id="inv_qty" name="quantity" class="form-control" step="0.001" 
                               value="0" required style="height:38px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:.85rem;font-weight:700;color:var(--neutral-600);margin-bottom:6px;">
                            Unit *
                        </label>
                        <select id="inv_unit" name="unit" class="form-control" required style="height:38px;">
                            <option value="kg">kg</option>
                            <option value="g">g</option>
                            <option value="ltr">ltr</option>
                            <option value="ml">ml</option>
                            <option value="pc">pc</option>
                            <option value="bunch">bunch</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:.85rem;font-weight:700;color:var(--neutral-600);margin-bottom:6px;">
                            Low Stock Alert
                        </label>
                        <input type="number" id="inv_threshold" name="low_stock_threshold" class="form-control" 
                               step="0.001" value="5" style="height:38px;">
                    </div>
                </div>
                
                <div style="margin-bottom:20px;">
                    <label style="display:block;font-size:.85rem;font-weight:700;color:var(--neutral-600);margin-bottom:6px;">
                        Notes
                    </label>
                    <textarea id="inv_notes" name="notes" class="form-control" rows="2"></textarea>
                </div>
                
                <div style="display:flex;gap:10px;">
                    <button type="button" onclick="closeAllModals()" class="btn btn-outline" style="flex:1;">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="flex:2;">
                        <i class="fas fa-save"></i> Save Ingredient
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Supplier Modal -->
    <div id="supplierModal" class="modal-slide">
        <div style="padding:24px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
                <h3 style="margin:0;font-weight:800;color:#1e293b;">
                    <i class="fas fa-truck-field" style="color:#f59e0b;margin-right:8px;"></i>
                    Add Meat Supplier
                </h3>
                <button onclick="closeAllModals()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#94a3b8;">&times;</button>
            </div>
            
            <form id="supplierForm" onsubmit="submitSupplierForm(event)">
                <input type="hidden" id="sup_id" name="supplier_id" value="0">
                
                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:.85rem;font-weight:700;color:var(--neutral-600);margin-bottom:6px;">
                        Supplier Name *
                    </label>
                    <input type="text" name="supplier_name" class="form-control" required style="height:38px;">
                </div>
                
                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:.85rem;font-weight:700;color:var(--neutral-600);margin-bottom:6px;">
                        Contact Person
                    </label>
                    <input type="text" name="contact_person" class="form-control" style="height:38px;">
                </div>
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                    <div>
                        <label style="display:block;font-size:.85rem;font-weight:700;color:var(--neutral-600);margin-bottom:6px;">
                            Phone
                        </label>
                        <input type="text" name="phone" class="form-control" style="height:38px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:.85rem;font-weight:700;color:var(--neutral-600);margin-bottom:6px;">
                            Email
                        </label>
                        <input type="email" name="email" class="form-control" style="height:38px;">
                    </div>
                </div>
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">
                    <div>
                        <label style="display:block;font-size:.85rem;font-weight:700;color:var(--neutral-600);margin-bottom:6px;">
                            Halal Cert Number
                        </label>
                        <input type="text" name="halal_cert_number" class="form-control" style="height:38px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:.85rem;font-weight:700;color:var(--neutral-600);margin-bottom:6px;">
                            Cert Expiry Date
                        </label>
                        <input type="date" name="halal_cert_expiry" class="form-control" style="height:38px;">
                    </div>
                </div>
                
                <div style="display:flex;gap:10px;">
                    <button type="button" onclick="closeAllModals()" class="btn btn-outline" style="flex:1;">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="flex:2;">
                        <i class="fas fa-save"></i> Save Supplier
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="receiveModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);
         z-index:9999;align-items:flex-start;justify-content:center;overflow-y:auto;padding:32px 16px;">
        <div style="background:#fff;border-radius:16px;width:100%;max-width:680px;
                    box-shadow:0 20px 60px rgba(0,0,0,.25);margin:auto;">
            <div style="padding:20px 24px;border-bottom:1px solid var(--neutral-100);display:flex;justify-content:space-between;align-items:center;">
                <h4 style="margin:0;font-weight:800;color:#1e293b;font-size:1.05rem;">
                    <i class="fas fa-boxes-stacked" style="color:var(--primary-500);margin-right:8px;"></i>
                    Receive &amp; Log to Inventory
                </h4>
                <button onclick="document.getElementById('receiveModal').style.display='none'"
                        style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#94a3b8;line-height:1;">&times;</button>
            </div>
            <form id="receiveForm" onsubmit="submitReceiveForm(event)" style="padding:20px 24px;">
                <input type="hidden" id="recv_action" name="action" value="receive_grocery_list">
                <input type="hidden" id="recv_req_id" name="gl_id" value="">
                <div id="receiveItemsContainer"></div>
                <div style="display:flex;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid var(--neutral-100);">
                    <button type="button"
                            onclick="document.getElementById('receiveModal').style.display='none'"
                            class="btn btn-outline" style="flex:1;">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="flex:2;">
                        <i class="fas fa-check-double"></i> Confirm Receipt &amp; Log to Inventory
                    </button>
                </div>
            </form>
        </div>
    </div>

<script>
const CHEF_API = 'api/chef_action.php';
window.ingredientMenuApi = 'api/ingredient_menu_api.php';
window.menuWorkflowApi = 'api/menu_workflow_api.php';
window.menuWorkflowCsrf = <?= json_encode($menu_workflow_csrf) ?>;

// ── Category Editor ──────────────────────────────────────────────────────
const PRESET_CATEGORIES = [
    {name:'Fresh Produce',            children:['Vegetables','Fruits','Aromatics']},
    {name:'Proteins',                 children:['Meat','Poultry','Seafood','Plant Proteins']},
    {name:'Dairy & Eggs',             children:['Milk and Cream','Cheese','Butter and Yogurt','Eggs']},
    {name:'Staples',                  children:['Grains and Starches','Oils and Fats','Liquids and Broths']},
    {name:'Seasonings & Flavor Enhancers', children:['Spices and Herbs','Sauces and Condiments','Sweeteners']},
    {name:'Beverages',                children:['Soft Drinks','Water','Coffee']},
];

function openCategoryEditor() {
    const modal = document.getElementById('categoryEditorModal');
    if (!modal) {
        console.error('Modal not found: categoryEditorModal');
        alert('Modal element not found. Please refresh the page.');
        return;
    }
    const list = document.getElementById('catPresetList');
    if (!list) {
        console.error('List container not found: catPresetList');
        alert('Category list container not found. Please refresh the page.');
        return;
    }
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    if (list.children.length === 0) {
        renderCategorySelector();
    }
}

function renderCategorySelector() {
    const list = document.getElementById('catPresetList');
    if (!list) {
        console.error('catPresetList not found');
        return;
    }
    
    if (!PRESET_CATEGORIES || PRESET_CATEGORIES.length === 0) {
        console.error('PRESET_CATEGORIES not defined or empty');
        list.innerHTML = '<p style="color:red;">Error: No preset categories available.</p>';
        return;
    }
    
    list.innerHTML = '';
    
    PRESET_CATEGORIES.forEach((parent, pidx) => {
        const pDiv = document.createElement('div');
        pDiv.style.cssText = 'border:1.5px solid #e2e8f0;border-radius:12px;padding:16px;background:#fafafa;';
        
        let html = `
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                <i class="fas fa-folder-open" style="color:#6366f1;font-size:1.1rem;"></i>
                <div style="flex:1;">
                    <div style="font-weight:800;color:#1e293b;font-size:.95rem;">${escHtml(parent.name)}</div>
                    <div style="font-size:.75rem;color:#64748b;margin-top:2px;">Select sub-categories to include</div>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;padding-left:32px;">
        `;
        
        if (!parent.children || parent.children.length === 0) {
            html += `<p style="grid-column:1/-1;color:#999;font-size:.8rem;">No sub-categories</p>`;
        } else {
            parent.children.forEach((child, cidx) => {
                const chkId = 'cat-' + pidx + '-' + cidx;
                html += `
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;user-select:none;">
                        <input type="checkbox" id="${chkId}" class="cat-checkbox" data-parent="${pidx}" data-child="${cidx}" 
                               style="cursor:pointer;" checked>
                        <span style="font-size:.85rem;color:#334155;font-weight:500;">${escHtml(child)}</span>
                    </label>
                `;
            });
        }
        
        html += `</div>`;
        pDiv.innerHTML = html;
        list.appendChild(pDiv);
    });
}

function closeCategoryEditor() {
    document.getElementById('categoryEditorModal').style.display = 'none';
    document.body.style.overflow = '';
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

async function saveCategoriesFromModal() {
    const checkboxes = document.querySelectorAll('.cat-checkbox:checked');
    const selected = {};
    checkboxes.forEach(chk => {
        const pidx = parseInt(chk.dataset.parent);
        const cidx = parseInt(chk.dataset.child);
        if (!selected[pidx]) selected[pidx] = [];
        selected[pidx].push(cidx);
    });
    
    const categories = PRESET_CATEGORIES.map((parent, pidx) => {
        const children = (selected[pidx] || [])
            .map(cidx => ({name: parent.children[cidx]}))
            .filter(c => c.name);
        return {name: parent.name, children};
    }).filter(p => p.children.length > 0);
    
    if (categories.length === 0) { showChefToast('Select at least one sub-category.', true); return; }
    
    const btn = document.getElementById('saveCatBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    
    const payload = {action:'save_categories', categories};
    console.log('Sending payload:', payload);
    console.log('API endpoint:', CHEF_API);
    
    try {
        const r = await fetch(CHEF_API, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        });
        
        console.log('Response status:', r.status);
        const text = await r.text();
        console.log('Response text:', text);
        
        let d;
        try {
            d = JSON.parse(text);
        } catch(e) {
            console.error('Failed to parse JSON:', e);
            showChefToast('Server returned invalid response: ' + text.substring(0, 100), true);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Save Categories';
            return;
        }
        
        if (d.success) {
            closeCategoryEditor();
            showChefToast(d.message, false);
            setTimeout(() => location.href = '?tab=categories', 900);
        } else {
            showChefToast(d.message, true);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Save Categories';
        }
    } catch(e) {
        console.error('Fetch error:', e);
        showChefToast('Network error: ' + e.message, true);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Save Categories';
    }
}

// Close category modal on backdrop click
document.addEventListener('DOMContentLoaded', function() {
    const cm = document.getElementById('categoryEditorModal');
    if (cm) cm.addEventListener('click', function(e) { if (e.target === cm) closeCategoryEditor(); });
});

// ── Grocery List Modal ────────────────────────────────────────────────────
function openGroceryModal() {
    const modal = document.getElementById('groceryModal');
    if (!modal) { alert('Grocery modal not found.'); return; }
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    // Clear and add one empty row
    const list = document.getElementById('groceryItemsList');
    if (list.children.length === 0) {
        addGroceryItem();
    }
}
function closeGroceryModal() {
    const modal = document.getElementById('groceryModal');
    if (modal) modal.style.display = 'none';
    document.body.style.overflow = '';
}

let glItemIndex = 0;
function addGroceryItem() {
    const list = document.getElementById('groceryItemsList');
    const idx = glItemIndex++;
    const row = document.createElement('div');
    row.className = 'gl-item-row';
    row.style.cssText = 'border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px 12px;background:#fafafa;';
    
    const pantryOpts = <?= json_encode(array_map(function($p) { return ['id'=>$p['id'], 'name'=>$p['name'], 'children'=>array_map(function($c){return ['id'=>$c['id'],'name'=>$c['name']];},$p['children'])]; }, $pantry_categories)) ?>;
    
    let catOpts = '<option value="">Choose category...</option>';
    pantryOpts.forEach(p => {
        catOpts += `<optgroup label="${escHtml(p.name)}">`;
        p.children.forEach(c => {
            catOpts += `<option value="${c.id}">${escHtml(c.name)}</option>`;
        });
        catOpts += `</optgroup>`;
    });
    
    row.innerHTML = `
        <!-- Row 1: Ingredient name (full width) + delete button -->
        <div style="display:flex;align-items:flex-end;gap:10px;margin-bottom:10px;">
            <div style="flex:1;">
                <label style="font-size:.75rem;font-weight:700;color:var(--neutral-500);display:block;margin-bottom:4px;">Ingredient *</label>
                <input type="text" name="items[${idx}][name]" class="form-control" placeholder="e.g., Chicken Breast"
                       style="font-size:.85rem;height:36px;" required>
            </div>
            <button type="button" onclick="this.closest('.gl-item-row').remove()"
                    title="Remove item"
                    style="flex-shrink:0;width:34px;height:36px;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;color:#dc2626;font-size:.9rem;cursor:pointer;display:flex;align-items:center;justify-content:center;margin-bottom:0;">
                <i class="fas fa-trash"></i>
            </button>
        </div>
        <!-- Row 2: Qty · Unit · Category · Price/Unit · Total -->
        <div style="display:grid;grid-template-columns:80px 90px 1fr 110px 110px;gap:10px;align-items:end;">
            <div>
                <label style="font-size:.75rem;font-weight:700;color:var(--neutral-500);display:block;margin-bottom:4px;">Qty *</label>
                <input type="number" name="items[${idx}][quantity]" class="form-control gl-qty" step="0.1" min="0.1" value="1"
                       style="font-size:.85rem;height:36px;" required oninput="calcGlTotal(this)">
            </div>
            <div>
                <label style="font-size:.75rem;font-weight:700;color:var(--neutral-500);display:block;margin-bottom:4px;">Unit *</label>
                <select name="items[${idx}][unit]" class="form-control gl-unit" style="font-size:.85rem;height:36px;" required oninput="calcGlTotal(this)">
                    <option value="kg">kg</option>
                    <option value="g">g</option>
                    <option value="ltr">ltr</option>
                    <option value="ml">ml</option>
                    <option value="pc">pc</option>
                    <option value="bunch">bunch</option>
                    <option value="box">box</option>
                </select>
            </div>
            <div>
                <label style="font-size:.75rem;font-weight:700;color:var(--neutral-500);display:block;margin-bottom:4px;">Category / Sub-category</label>
                <select name="items[${idx}][category_id]" class="form-control" style="font-size:.85rem;height:36px;">
                    ${catOpts}
                </select>
            </div>
            <div>
                <label style="font-size:.75rem;font-weight:700;color:var(--neutral-500);display:block;margin-bottom:4px;">₱ / Unit</label>
                <input type="number" name="items[${idx}][unit_price]" class="form-control gl-unit-price" step="0.01" min="0"
                       placeholder="0.00"
                       style="font-size:.85rem;height:36px;" oninput="calcGlTotal(this)">
            </div>
            <div>
                <label style="font-size:.75rem;font-weight:700;color:var(--neutral-500);display:block;margin-bottom:4px;">Total (₱)</label>
                <input type="text" name="items[${idx}][estimated_cost]" class="form-control gl-total"
                       placeholder="—" readonly
                       style="font-size:.85rem;height:36px;background:#f8fafc;color:#1d4ed8;font-weight:700;cursor:default;">
            </div>
        </div>`;
    list.appendChild(row);
}

// Recalculates the Total field whenever Qty or Price/Unit changes
function calcGlTotal(inputEl) {
    const row       = inputEl.closest('.gl-item-row');
    if (!row) return;
    const qtyEl     = row.querySelector('.gl-qty');
    const priceEl   = row.querySelector('.gl-unit-price');
    const totalEl   = row.querySelector('.gl-total');
    if (!qtyEl || !priceEl || !totalEl) return;

    const qty   = parseFloat(qtyEl.value)   || 0;
    const price = parseFloat(priceEl.value) || 0;

    if (qty > 0 && price > 0) {
        const total = (qty * price).toFixed(2);
        totalEl.value = total;
        totalEl.style.color = '#1d4ed8';
    } else {
        totalEl.value = '';
    }
}

async function submitGroceryList() {
    const form = document.getElementById('groceryForm');
    const title = document.getElementById('glTitle').value.trim() || 'Grocery List';
    const notes = form.querySelector('textarea[name="notes"]').value.trim();
    
    const itemRows = document.querySelectorAll('.gl-item-row');
    if (itemRows.length === 0) { showChefToast('Add at least one ingredient.', true); return; }
    
    const items = [];
    itemRows.forEach((row, idx) => {
        const name       = row.querySelector('input[name*="[name]"]').value.trim();
        const qty        = row.querySelector('input[name*="[quantity]"]').value;
        const unit       = row.querySelector('select[name*="[unit]"]').value;
        const catId      = row.querySelector('select[name*="[category_id]"]').value;
        const unitPrice  = row.querySelector('input[name*="[unit_price]"]')?.value ?? '';
        const totalCost  = row.querySelector('input[name*="[estimated_cost]"]')?.value ?? '';
        
        if (!name || !qty || !unit) { showChefToast('Fill in ingredient name, quantity, and unit.', true); return; }
        items.push({
            name,
            quantity:       parseFloat(qty),
            unit,
            category_id:    catId ? parseInt(catId) : null,
            unit_price:     unitPrice  !== '' ? parseFloat(unitPrice)  : null,
            estimated_cost: totalCost  !== '' ? parseFloat(totalCost)  : null,
        });
    });
    
    if (items.length === 0) return;
    
    const btn = document.getElementById('submitGroceryBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…';
    
    try {
        const fd = new FormData();
        fd.append('action', 'submit_grocery_list');
        fd.append('title', title);
        fd.append('notes', notes);
        items.forEach((item, i) => {
            fd.append(`items[${i}][name]`,           item.name);
            fd.append(`items[${i}][quantity]`,        item.quantity);
            fd.append(`items[${i}][unit]`,            item.unit);
            if (item.category_id   != null) fd.append(`items[${i}][category_id]`,   item.category_id);
            if (item.unit_price    != null) fd.append(`items[${i}][unit_price]`,     item.unit_price);
            if (item.estimated_cost != null) fd.append(`items[${i}][estimated_cost]`, item.estimated_cost);
        });
        
        const r = await fetch(CHEF_API, {method: 'POST', body: fd});
        const d = await r.json();
        if (d.success) {
            closeGroceryModal();
            showChefToast(d.message, false);
            setTimeout(() => location.href = '?tab=grocery_lists', 900);
        } else {
            showChefToast(d.message, true);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit to Ops Manager';
        }
    } catch(e) {
        showChefToast('Network error.', true);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit to Ops Manager';
    }
}

function openRejectModal(invId) {
    document.getElementById('rejectInvId').value = invId;
    document.getElementById('rejectNotes').value = '';
    document.getElementById('rejectModal').style.display = 'flex';
}
async function submitReject() {
    const invId = document.getElementById('rejectInvId').value;
    const notes = document.getElementById('rejectNotes').value.trim();
    if (!notes) { alert('Rejection reason is required.'); return; }
    const fd = new FormData();
    fd.append('action',       'reject_inspection');
    fd.append('inv_id',       invId);
    fd.append('reject_notes', notes);
    const r = await fetch(CHEF_API, {method:'POST', body:fd});
    const d = await r.json();
    document.getElementById('rejectModal').style.display = 'none';
    if (d.success) { document.getElementById('ins-row-' + invId)?.remove(); showChefToast(d.message, false); }
    else showChefToast(d.message, true);
}
function selectHalalStatus(invId, status) {
    var colors = {
        halal: ["#16a34a","#bbf7d0","#dcfce7","#f0fdf4"],
        self_certified: ["#2563eb","#bfdbfe","#dbeafe","#eff6ff"],
        non_halal: ["#dc2626","#fca5a5","#fee2e2","#fff1f2"]
    };
    ["halal","self_certified","non_halal"].forEach(function(s) {
        var card = document.getElementById("hs-card-" + s + "-" + invId);
        if (!card) return;
        var active = s === status;
        var c = colors[s];
        card.style.borderColor = active ? c[0] : c[1];
        card.style.background = active ? c[2] : c[3];
        var radio = card.querySelector("input[type=radio]");
        if (radio) radio.checked = active;
    });
    var certRef = document.getElementById("cert-ref-wrap-" + invId);
    var selfCert = document.getElementById("self-cert-wrap-" + invId);
    var rejectRsn = document.getElementById("reject-reason-wrap-" + invId);
    if (certRef) certRef.style.display = (status === "halal") ? "block" : "none";
    if (selfCert) selfCert.style.display = (status === "self_certified") ? "block" : "none";
    if (rejectRsn) rejectRsn.style.display = (status === "non_halal") ? "block" : "none";
}

async function submitInspection(invId) {
    var radio = document.querySelector("input[name=\"hs_" + invId + "\"]:checked");
    var status = radio ? radio.value : "pending_cert";
    var expiry = (document.getElementById("ins-expiry-" + invId) || {}).value || "";
    var purchase = (document.getElementById("ins-purchase-" + invId) || {}).value || "";
    var certRef = (document.getElementById("cert-ref-" + invId) || {}).value || "";
    var selfNote = (document.getElementById("self-cert-" + invId) || {}).value || "";
    var rejRsn = (document.getElementById("reject-reason-" + invId) || {}).value || "";
    if (!expiry) { showChefToast("Expiry date required.", true); return; }
    if (status === "halal" && !certRef) { showChefToast("Cert/brand ref required.", true); return; }
    if (status === "self_certified" && !selfNote) { showChefToast("Supplier note required.", true); return; }
    if (status === "non_halal" && !rejRsn) { showChefToast("Rejection reason required.", true); return; }
    var fd = new FormData();
    fd.append("action", "approve_inspection");
    fd.append("inv_id", invId);
    fd.append("halal_status", status);
    fd.append("expiry_date", expiry);
    fd.append("actual_purchase_date", purchase);
    fd.append("halal_cert_ref", certRef || selfNote);
    fd.append("reject_notes", rejRsn);
    var proofFile = document.getElementById("proof-file-" + invId);
    if (proofFile && proofFile.files[0]) fd.append("halal_proof_0", proofFile.files[0]);
    var btn = document.querySelector("#ins-row-" + invId + " .btn-success");
    if (btn) { btn.disabled = true; btn.innerHTML = "<i class=\"fas fa-spinner fa-spin\"></i>"; }
    try {
        var r = await fetch(CHEF_API, {method:"POST", body:fd});
        var d = await r.json();
        if (d.success) {
            var elem = document.getElementById("ins-row-" + invId);
            if (elem) elem.remove();
            showChefToast(d.message || "Item activated.", false);
        } else {
            showChefToast(d.message, true);
            if (btn) { btn.disabled = false; btn.innerHTML = "<i class=\"fas fa-check-circle\"></i> Activate"; }
        }
    } catch(e) {
        showChefToast("Network error.", true);
        if (btn) { btn.disabled = false; btn.innerHTML = "<i class=\"fas fa-check-circle\"></i> Activate"; }
    }
}

function showChefToast(msg, isErr) {
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;padding:12px 20px;border-radius:10px;font-size:.88rem;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.15);max-width:360px;';
    t.style.background = isErr ? '#fee2e2' : '#dcfce7';
    t.style.color      = isErr ? '#991b1b' : '#166534';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}


// Modal Toggles
function closeAllModals() {
    document.querySelectorAll('.modal-slide').forEach(m => m.classList.remove('open'));
    document.getElementById('modalOverlay').classList.remove('open');
}
function openInventoryModal() {
    document.getElementById('inventoryForm').reset();
    document.getElementById('inv_id').value = '0';
    document.getElementById('invModalTitle').innerText = 'Add Kitchen Ingredient';
    document.getElementById('inventoryModal').classList.add('open');
    document.getElementById('modalOverlay').classList.add('open');
}
function openSupplierModal() {
    document.getElementById('supplierForm').reset();
    document.getElementById('sup_id').value = '0';
    document.getElementById('supplierModal').classList.add('open');
    document.getElementById('modalOverlay').classList.add('open');
}
function openRequisitionModal() {
    const m = document.getElementById('requisitionModal');
    m.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeReqModal() {
    const m = document.getElementById('requisitionModal');
    m.style.display = 'none';
    document.body.style.overflow = '';
    // Also close backdrop click
}
// Close requisition modal when clicking the backdrop
document.addEventListener('DOMContentLoaded', function () {
    const reqModal = document.getElementById('requisitionModal');
    if (reqModal) {
        reqModal.addEventListener('click', function (e) {
            if (e.target === reqModal) closeReqModal();
        });
    }
});
function quickAddIngredient(menuId) {
    document.getElementById('rec_menu_id').value = menuId;
    document.getElementById('recipeModal').classList.add('open');
    document.getElementById('modalOverlay').classList.add('open');
}
function openRecipeModal() {
    document.getElementById('recipeForm').reset();
    document.getElementById('rec_menu_id').value = '';
    document.getElementById('recipeModal').classList.add('open');
    document.getElementById('modalOverlay').classList.add('open');
}
function toggleMenuStatus(menuId, isActive) {
    const val = isActive ? 1 : 0;

    // Optimistic UI update
    const row   = document.getElementById('menu-row-'          + menuId);
    const text  = document.getElementById('menu-status-text-'  + menuId);
    const label = document.getElementById('menu-toggle-label-' + menuId);
    const thumb = document.getElementById('menu-toggle-thumb-' + menuId);
    const knob  = document.getElementById('menu-toggle-knob-'  + menuId);

    if (row) {
        row.style.background   = isActive ? '#f0fdf4' : '#fff5f5';
        row.style.borderColor  = isActive ? '#bbf7d0' : '#fca5a5';
    }
    if (text)  { text.textContent  = isActive ? '● Available' : '● Out of Stock';
                 text.style.color  = isActive ? '#16a34a' : '#dc2626'; }
    if (label) { label.textContent = isActive ? 'On' : 'Off'; }
    if (thumb) { thumb.style.background = isActive ? '#16a34a' : '#d1d5db'; }
    if (knob)  { knob.style.left = isActive ? '23px' : '3px'; }

    const fd = new FormData();
    fd.append('action',      'update_menu_status');
    fd.append('menu_id',     menuId);
    fd.append('is_available', val);

    fetch(CHEF_API, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                // Revert optimistic update on failure
                const reverted = !isActive;
                if (row)   { row.style.background  = reverted ? '#f0fdf4' : '#fff5f5';
                             row.style.borderColor = reverted ? '#bbf7d0' : '#fca5a5'; }
                if (text)  { text.textContent = reverted ? '● Available' : '● Out of Stock';
                             text.style.color = reverted ? '#16a34a' : '#dc2626'; }
                if (label) { label.textContent = reverted ? 'On' : 'Off'; }
                if (thumb) { thumb.style.background = reverted ? '#16a34a' : '#d1d5db'; }
                if (knob)  { knob.style.left = reverted ? '23px' : '3px'; }
                const cb = document.getElementById('menu-toggle-' + menuId);
                if (cb) cb.checked = reverted;
                showChefToast('Failed to update: ' + data.message, true);
            } else {
                showChefToast((isActive ? '✓ ' : '○ ') + 'Menu item ' + (isActive ? 'set to Available' : 'set to Out of Stock'), false);
            }
        })
        .catch(() => showChefToast('Network error. Please try again.', true));
}
// ── Chef Menu Submission JS ───────────────────────────────────────────────────
(function() {
    const ADD_URL    = <?= json_encode(BASE_URL . 'dashboard/business_owner/api/add_menu_item.php') ?>;
    const UPDATE_URL = <?= json_encode(BASE_URL . 'dashboard/business_owner/api/update_menu_item.php') ?>;
    const CATS       = <?= json_encode(MENU_ITEM_CATEGORIES) ?>;
    const SERVES     = <?= json_encode(ALLOWED_SERVING_TYPES) ?>;
    const MENU_WORKFLOW_CSRF = window.menuWorkflowCsrf;

    let dishCount = 0;

    const dishRows     = document.getElementById('chefDishRows');
    const submitRow    = document.getElementById('chefMenuSubmitRow');
    const saveBtn      = document.getElementById('chefSaveMenuBtn');
    const addBtn       = document.getElementById('chefAddDishBtn');
    const emptyState   = document.getElementById('chefMenuEmpty');
    const toast        = document.getElementById('chefMenuToast');

    function showToast(msg, isErr) {
        if (!toast) return;
        toast.textContent    = msg;
        toast.style.display  = 'block';
        toast.style.background = isErr ? '#fef2f2' : '#f0fdf4';
        toast.style.border     = isErr ? '1.5px solid #fca5a5' : '1.5px solid #bbf7d0';
        toast.style.color      = isErr ? '#991b1b' : '#166534';
        clearTimeout(toast._t);
        toast._t = setTimeout(() => toast.style.display = 'none', 5000);
    }

    function esc(str) {
        const d = document.createElement('div');
        d.textContent = String(str ?? '');
        return d.innerHTML;
    }

    function buildRow(item) {
        const idx    = dishCount++;
        const isEdit = item != null;
        const catOpts  = CATS.map(c => `<option${isEdit && item.category === c ? ' selected' : ''}>${c}</option>`).join('');
        const servOpts = SERVES.map(s => `<option${isEdit && item.serving_type === s ? ' selected' : ''}>${s}</option>`).join('');

        const wrap = document.createElement('div');
        wrap.className = 'dish-row';
        if (isEdit) wrap.dataset.editId = item.id;
        wrap.style.cssText = 'background:#f8fafc;border:1.5px solid ' + (isEdit ? '#bfdbfe' : '#e2e8f0') + ';border-radius:12px;padding:14px 16px;display:flex;flex-direction:column;gap:10px;';

        const imgStyle   = isEdit && item.image_url ? `background-image:url('${esc(item.image_url)}');background-size:cover;background-position:center;` : '';
        const iconHidden = isEdit && item.image_url ? 'display:none;' : '';

        wrap.innerHTML = `
            ${isEdit ? `<div style="font-size:.75rem;font-weight:700;color:#1d4ed8;text-transform:uppercase;letter-spacing:.05em;">
                <i class="fas fa-pencil-alt" style="margin-right:4px;"></i>Editing: ${esc(item.name)}
                <span style="margin-left:8px;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:8px;font-size:.7rem;">Will require re-approval</span>
            </div>` : ''}
            <div style="display:flex;align-items:flex-end;gap:10px;flex-wrap:nowrap;">
                <div style="flex-shrink:0;">
                    <div class="dish-field-label">Photo</div>
                    <label style="display:flex;flex-direction:column;align-items:center;justify-content:center;
                                  border:2px dashed #cbd5e1;border-radius:8px;width:72px;height:72px;
                                  cursor:pointer;background:#fff;overflow:hidden;position:relative;${imgStyle}">
                        <i class="fas fa-camera" style="color:#94a3b8;font-size:1.2rem;${iconHidden}"></i>
                        <input type="file" accept="image/*" style="display:none;" class="chef-img-input">
                    </label>
                </div>
                <div style="flex:1 1 140px;min-width:0;">
                    <div class="dish-field-label">Dish Name <span style="color:var(--danger);">*</span></div>
                    <input type="text" class="form-control chef-name-input"
                           value="${isEdit ? esc(item.name) : ''}"
                           placeholder="e.g. Grilled Chicken" style="font-size:.88rem;">
                </div>
                <div style="flex:0 0 100px;">
                    <div class="dish-field-label">Price (&#8369;) <span style="color:var(--danger);">*</span></div>
                    <input type="number" class="form-control chef-price-input"
                           value="${isEdit ? parseFloat(item.price).toFixed(2) : ''}"
                           min="0" step="0.01" placeholder="0.00" style="font-size:.88rem;">
                </div>
                <div style="flex:0 0 140px;">
                    <div class="dish-field-label">Category</div>
                    <select class="form-control chef-cat-input" style="font-size:.85rem;">${catOpts}</select>
                </div>
                <div style="flex:0 0 130px;">
                    <div class="dish-field-label">Serving Type</div>
                    <select class="form-control chef-serve-input" style="font-size:.85rem;">${servOpts}</select>
                </div>
                <div style="flex-shrink:0;padding-bottom:2px;">
                    <button type="button" class="chef-remove-btn" title="${isEdit ? 'Cancel' : 'Remove'}"
                        style="width:36px;height:38px;border:1px solid #fecaca;background:#fef2f2;
                               border-radius:8px;cursor:pointer;color:#dc2626;font-size:.9rem;
                               display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div>
                <div class="dish-field-label">Description <span style="color:var(--neutral-400);font-weight:400;">(optional)</span></div>
                <textarea class="form-control chef-desc-input" rows="2"
                          placeholder="Brief description of the dish…"
                          style="font-size:.85rem;resize:vertical;">${isEdit ? esc(item.description) : ''}</textarea>
            </div>`;

        wrap.querySelector('.chef-remove-btn').addEventListener('click', () => {
            wrap.remove();
            if (!dishRows.querySelectorAll('.dish-row').length) {
                submitRow.style.display = 'none';
                if (emptyState) emptyState.style.display = '';
            }
        });

        const imgInput = wrap.querySelector('.chef-img-input');
        imgInput.addEventListener('change', function() {
            if (!this.files?.[0]) return;
            const reader = new FileReader();
            reader.onload = e => {
                const lbl = this.closest('label');
                lbl.style.backgroundImage    = `url('${e.target.result}')`;
                lbl.style.backgroundSize     = 'cover';
                lbl.style.backgroundPosition = 'center';
                lbl.querySelector('i').style.display = 'none';
            };
            reader.readAsDataURL(this.files[0]);
        });

        return wrap;
    }

    if (addBtn) {
        addBtn.addEventListener('click', () => {
            const row = buildRow(null);
            dishRows.appendChild(row);
            if (emptyState) emptyState.style.display = 'none';
            submitRow.style.display = 'flex';
        });
    }

    window.openChefEditRow = function(item) {
        // Scroll to top of the add form
        document.querySelector('#chefDishRows')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        const row = buildRow(item);
        dishRows.prepend(row);
        if (emptyState) emptyState.style.display = 'none';
        submitRow.style.display = 'flex';
    };

    if (saveBtn) {
        saveBtn.addEventListener('click', async function() {
            const rows = dishRows.querySelectorAll('.dish-row');
            if (!rows.length) return;

            saveBtn.disabled  = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting\u2026';

            let saved = 0, failed = 0;

            for (const row of rows) {
                const name = row.querySelector('.chef-name-input')?.value?.trim() ?? '';
                if (!name) { failed++; continue; }

                const isEdit = !!row.dataset.editId;
                const url    = isEdit ? UPDATE_URL : ADD_URL;

                const fd = new FormData();
                fd.append('csrf_token', MENU_WORKFLOW_CSRF);
                if (isEdit) fd.append('item_id', row.dataset.editId);
                fd.append('dish_name',         name);
                fd.append('dish_price',        row.querySelector('.chef-price-input')?.value  ?? '0');
                fd.append('dish_cat',          row.querySelector('.chef-cat-input')?.value    ?? 'Main Course');
                fd.append('dish_serving_type', row.querySelector('.chef-serve-input')?.value  ?? 'Per serving');
                fd.append('dish_desc',         row.querySelector('.chef-desc-input')?.value   ?? '');

                const imgInput = row.querySelector('.chef-img-input');
                if (imgInput?.files?.[0]) fd.append('dish_image', imgInput.files[0]);

                try {
                    const res  = await fetch(url, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.success) { saved++; row.remove(); }
                    else { failed++; }
                } catch { failed++; }
            }

            saveBtn.disabled  = false;
            saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Draft';

            if (!dishRows.querySelectorAll('.dish-row').length) {
                submitRow.style.display = 'none';
                if (emptyState) emptyState.style.display = '';
            }

            if (saved && !failed)
                showToast(saved + ' menu draft' + (saved !== 1 ? 's' : '') + ' saved. Next: create or complete each recipe.', false);
            else if (saved && failed)
                showToast(saved + ' draft(s) saved, ' + failed + ' failed. Check the required fields.', true);
            else
                showToast('Could not submit — make sure dish names are filled in.', true);

            if (saved) setTimeout(() => location.reload(), 2200);
        });
    }
})();

window.submitMenuForManager = async function(menuId) {
    if (!confirm('Submit this completed menu and recipe to the Operations Manager for review?')) return;
    const body = new FormData();
    body.append('action', 'submit');
    body.append('menu_item_id', String(menuId));
    body.append('csrf_token', window.menuWorkflowCsrf || '');
    try {
        const response = await fetch(window.menuWorkflowApi, { method: 'POST', body: body });
        const result = await response.json();
        if (!result.success) {
            const details = Array.isArray(result.errors) && result.errors.length ? '\n• ' + result.errors.join('\n• ') : '';
            showChefToast((result.message || 'Submission failed.') + details, true);
            return;
        }
        showChefToast(result.message, false);
        setTimeout(() => location.reload(), 900);
    } catch (_) {
        showChefToast('Network error while submitting the menu.', true);
    }
};

window.deleteChefMenu = async function(menuId, menuName, isLive = false) {
    const confirmation = isLive
        ? 'Delete "' + menuName + '" from the live menu?\n\nThis immediately removes it from customer and POS ordering. Its history will be retained.'
        : 'Delete "' + menuName + '"? This permanently removes its draft recipe and ingredient mappings.';
    if (!confirm(confirmation)) return;
    const body = new FormData();
    body.append('action', 'delete');
    body.append('menu_item_id', String(menuId));
    body.append('csrf_token', window.menuWorkflowCsrf || '');
    try {
        const response = await fetch(window.menuWorkflowApi, { method: 'POST', body: body });
        const result = await response.json();
        if (!result.success) {
            showChefToast(result.message || 'Could not delete the menu.', true);
            return;
        }
        showChefToast(result.message, false);
        setTimeout(() => location.reload(), 650);
    } catch (_) {
        showChefToast('Network error while deleting the menu.', true);
    }
};
function toggleMeatSupplierSelect() {
    const isMeat = document.getElementById('inv_is_meat').checked;
    document.getElementById('meatSupplierGroup').style.display = isMeat ? 'block' : 'none';
    
    // Update the checkbox value
    document.getElementById('inv_is_meat').value = isMeat ? 1 : 0;
}

function editInventory(inv) {
    document.getElementById('inv_id').value = inv.id;
    document.getElementById('inv_name').value = inv.ingredient_name;
    // Use expiration_date if set, fall back to legacy expiry_date
    const expDate = inv.expiration_date || (inv.expiry_date && inv.expiry_date !== '0000-00-00' ? inv.expiry_date : '') || '';
    document.getElementById('inv_expiration_date').value = expDate;
    document.getElementById('inv_halal').value = inv.halal_status;
    document.getElementById('inv_is_meat').checked = parseInt(inv.is_meat) === 1;
    document.getElementById('inv_is_meat').value = parseInt(inv.is_meat) === 1 ? 1 : 0;
    document.getElementById('inv_supplier_id').value = inv.supplier_id || '';
    document.getElementById('inv_qty').value = inv.quantity;
    document.getElementById('inv_unit').value = inv.unit;
    document.getElementById('inv_threshold').value = inv.low_stock_threshold;
    document.getElementById('inv_notes').value = inv.notes || '';
    toggleMeatSupplierSelect();
    
    document.getElementById('invModalTitle').innerText = 'Edit Kitchen Ingredient';
    document.getElementById('inventoryModal').classList.add('open');
    document.getElementById('modalOverlay').classList.add('open');
}

// AJAX Submit Handlers
function submitInventoryForm(e) {
    e.preventDefault();
    const formData = new FormData(document.getElementById('inventoryForm'));
    formData.append('action', 'save_inventory');
    fetch(CHEF_API, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) location.reload();
        });
}

function deleteInventory(id) {
    if (!confirm('Are you sure you want to delete this ingredient?')) return;
    const formData = new FormData();
    formData.append('action', 'delete_inventory');
    formData.append('inventory_id', id);
    fetch(CHEF_API, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) location.reload();
        });
}

function submitSupplierForm(e) {
    e.preventDefault();
    const formData = new FormData(document.getElementById('supplierForm'));
    formData.append('action', 'save_supplier');
    fetch(CHEF_API, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) location.reload();
        });
}

function verifySupplier(supId, status) {
    const formData = new FormData();
    formData.append('action', 'verify_supplier');
    formData.append('supplier_id', supId);
    formData.append('status', status);
    fetch(CHEF_API, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) location.reload();
        });
}

// Requisition dynamic rows
let reqRowIndex = 1;
function addReqRow() {
    const container = document.getElementById('reqItemsContainer');
    const div = document.createElement('div');
    div.className = 'req-item-row';
    div.style.cssText = 'display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr 28px;gap:6px;align-items:center;';
    div.innerHTML = `
        <input type="text"   name="items[${reqRowIndex}][name]"          class="form-control" style="font-size:.82rem;" placeholder="Ingredient name" required>
        <input type="number" name="items[${reqRowIndex}][quantity]"       class="form-control" style="font-size:.82rem;" step="0.1" value="1" required>
        <input type="text"   name="items[${reqRowIndex}][unit]"           class="form-control" style="font-size:.82rem;" value="kg" required>
        <input type="number" name="items[${reqRowIndex}][estimated_cost]" class="form-control" style="font-size:.82rem;" step="0.01" placeholder="0.00">
        <input type="date"   name="items[${reqRowIndex}][target_date]"    class="form-control" style="font-size:.82rem;">
        <button type="button" onclick="removeReqRow(this)" style="background:none;border:none;color:#dc2626;padding:0;"><i class="fas fa-times"></i></button>
    `;
    container.appendChild(div);
    reqRowIndex++;
}
function removeReqRow(btn) {
    btn.closest('.req-item-row').remove();
}

function submitRequisitionForm(e) {
    e.preventDefault();
    const formData = new FormData(document.getElementById('requisitionForm'));
    fetch(CHEF_API, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) location.reload();
        });
}

function submitRecipeForm(e) {
    e.preventDefault();
    const formData = new FormData(document.getElementById('recipeForm'));
    formData.append('action', 'save_recipe');
    fetch(CHEF_API, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) location.reload();
        });
}

function removeRecipeItem(mappingId) {
    if (!confirm('Remove this ingredient from recipe?')) return;
    const formData = new FormData();
    formData.append('action', 'delete_recipe_ingredient');
    formData.append('mapping_id', mappingId);
    fetch(CHEF_API, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) location.reload();
        });
}

function triggerPOSCheck() {
    const formData = new FormData();
    formData.append('action', 'run_availability_check');
    fetch(CHEF_API, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) location.reload();
        });
}

// ── Receive Grocery List Items (Chef after approval) ──────────────────────
function openReceiveModal(glId, items) {
    document.getElementById('recv_req_id').value = glId;
    document.getElementById('recv_action').value = 'receive_grocery_list';
    const container = document.getElementById('receiveItemsContainer');
    container.innerHTML = '';

    if (!items || items.length === 0) {
        container.innerHTML = '<p style="color:var(--neutral-400);text-align:center;">No items to receive.</p>';
        return;
    }

    items.forEach((item, idx) => {
        const row = document.createElement('div');
        row.style.cssText = 'border:1px solid var(--neutral-200);border-radius:10px;padding:16px 18px;margin-bottom:12px;background:#fafafa;';
        row.innerHTML = `
            <input type="hidden" name="items[${idx}][item_id]" value="${item.id}">
            <input type="hidden" name="items[${idx}][gl_item_id]" value="${item.id}">
            <input type="hidden" name="items[${idx}][ingredient_name]" value="${item.ingredient_name}">
            <input type="hidden" name="items[${idx}][unit]" value="${item.unit}">
            <div style="font-weight:700;font-size:.92rem;color:var(--neutral-800);margin-bottom:10px;">
                <i class="fas fa-carrot" style="color:#f59e0b;margin-right:6px;"></i>
                ${item.ingredient_name}
                <span style="font-size:.78rem;font-weight:500;color:var(--neutral-500);margin-left:6px;">
                    (Ordered: ${parseFloat(item.quantity).toFixed(2)} ${item.unit})
                </span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
                <div>
                    <label style="font-size:.75rem;font-weight:700;color:var(--neutral-500);display:block;margin-bottom:4px;">
                        Qty Received <span style="color:red">*</span>
                    </label>
                    <input type="number" step="0.01" min="0.01"
                           name="items[${idx}][qty_received]"
                           class="form-control" style="font-size:.85rem;height:34px;"
                           value="${parseFloat(item.quantity).toFixed(2)}" required>
                </div>
                <div>
                    <label style="font-size:.75rem;font-weight:700;color:var(--neutral-500);display:block;margin-bottom:4px;">
                        Expiry Date <span style="color:red">*</span>
                    </label>
                    <input type="date"
                           name="items[${idx}][expiry_date]"
                           class="form-control" style="font-size:.85rem;height:34px;" required>
                </div>
                <div>
                    <label style="font-size:.75rem;font-weight:700;color:var(--neutral-500);display:block;margin-bottom:4px;">
                        Purchase Date <span style="color:red">*</span>
                    </label>
                    <input type="date"
                           name="items[${idx}][actual_purchase_date]"
                           class="form-control" style="font-size:.85rem;height:34px;"
                           value="${new Date().toISOString().split('T')[0]}" required>
                </div>
            </div>
            <div style="margin-top:10px;">
                <label style="font-size:.75rem;font-weight:700;color:var(--neutral-500);display:block;margin-bottom:6px;">
                    <i class="fas fa-certificate" style="color:#16a34a;margin-right:4px;"></i>
                    Halal Proof Photo / PDF <span style="color:red">*</span>
                </label>
                <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;
                              background:#fff;border:1.5px dashed #cbd5e1;border-radius:8px;
                              padding:8px 14px;font-size:.82rem;font-weight:600;color:var(--primary-600);
                              transition:border-color .15s;" id="upload-label-${idx}">
                    <i class="fas fa-camera" style="font-size:1rem;"></i>
                    <span id="upload-name-${idx}">Take Photo or Upload File</span>
                    <input type="file"
                           name="halal_proof_${idx}"
                           accept="image/*,.pdf"
                           capture="environment"
                           style="display:none;"
                           onchange="document.getElementById('upload-name-' + ${idx}).textContent = this.files[0]?.name || 'Take Photo or Upload File'">
                </label>
                <div style="font-size:.72rem;color:var(--neutral-400);margin-top:4px;">
                    JPG, PNG, PDF &middot; max 20 MB &middot; Camera option available on mobile
                </div>
            </div>
            <div style="margin-top:12px;padding:14px;background:#f0fdf4;border-radius:8px;border-left:3px solid #16a34a;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div>
                        <label style="font-size:.75rem;font-weight:700;color:var(--neutral-700);display:block;margin-bottom:6px;">
                            Halal Status <span style="color:#dc2626;">*</span>
                        </label>
                        <select name="items[${idx}][halal_status]" class="form-control" style="font-size:.85rem;height:38px;border:1px solid #cbd5e1;border-radius:6px;padding:6px 10px;" required>
                            <option value="">-- Select Status --</option>
                            <option value="halal">Halal Certified</option>
                            <option value="self_certified">Muslim-Owned / Self-Certified</option>
                            <option value="fresh_produce">Fresh Produce</option>
                            <option value="non_halal">Non-Halal / Reject</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:.75rem;font-weight:700;color:var(--neutral-700);display:block;margin-bottom:6px;">
                            Certificate Ref / Note
                        </label>
                        <input type="text" name="items[${idx}][halal_note]" class="form-control" 
                               placeholder="e.g., IDCP-2024-0812 or Supplier note"
                               style="font-size:.85rem;height:38px;border:1px solid #cbd5e1;border-radius:6px;padding:6px 10px;">
                    </div>
                </div>
            </div>
        `;
        container.appendChild(row);
    });

    const modal = document.getElementById('receiveModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeReceiveModal() {
    document.getElementById('receiveModal').style.display = 'none';
    document.body.style.overflow = '';
}

// Close receive modal on backdrop click
document.addEventListener('DOMContentLoaded', function() {
    const rm = document.getElementById('receiveModal');
    if (rm) rm.addEventListener('click', function(e) {
        if (e.target === rm) closeReceiveModal();
    });
    
    // Close modal overlay when clicked
    const overlay = document.getElementById('modalOverlay');
    if (overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                closeAllModals();
            }
        });
    }
    
    // Auto-set unit when ingredient is selected in recipe modal
    const invSelect = document.getElementById('rec_inventory_id');
    if (invSelect) {
        invSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption && selectedOption.dataset.unit) {
                document.getElementById('rec_unit').value = selectedOption.dataset.unit;
            }
        });
    }
});

async function submitReceiveForm(e) {
    e.preventDefault();
    const form = document.getElementById('receiveForm');
    
    // Validate all halal_status selects have values
    const selects = form.querySelectorAll('select[name*="[halal_status]"]');
    for (let i = 0; i < selects.length; i++) {
        if (!selects[i].value) {
            showChefToast('Please select a Halal Status for all items.', true);
            return;
        }
    }
    
    // Validate that halal proof files were uploaded
    const fileInputs = form.querySelectorAll('input[type="file"][name^="halal_proof_"]');
    for (let i = 0; i < fileInputs.length; i++) {
        if (!fileInputs[i].files || fileInputs[i].files.length === 0) {
            showChefToast('Please upload a halal proof photo/PDF for all items.', true);
            return;
        }
    }
    
    const fd   = new FormData(form);
    const btn  = form.querySelector('button[type="submit"]');
    if (!btn) {
        alert('Submit button not found');
        return;
    }
    btn.disabled = true;
    const origText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';
    try {
        const r = await fetch(CHEF_API, {method: 'POST', body: fd});
        const d = await r.json();
        if (d.success) {
            closeReceiveModal();
            showChefToast(d.message, false);
            setTimeout(() => location.href = '?tab=inventory', 1200);
        } else {
            showChefToast(d.message, true);
            btn.disabled = false;
            btn.innerHTML = origText;
        }
    } catch (err) {
        console.error('Error:', err);
        showChefToast('Network error. Please try again.', true);
        btn.disabled = false;
        btn.innerHTML = origText;
    }
}

// Drag and Drop Logic for Recipe Mapping
let draggedItem = null;
function handleDragStart(e, invObj) {
    draggedItem = invObj;
    e.dataTransfer.setData('text/plain', JSON.stringify(invObj));
}
function handleDragOver(e) {
    e.preventDefault();
    e.currentTarget.classList.add('drag-over');
    e.currentTarget.classList.add('drag-over');
}
function handleDragLeave(e) {
    e.currentTarget.classList.remove('drag-over');
}
function handleDrop(e, menuItemId) {
    e.preventDefault();
    e.currentTarget.classList.remove('drag-over');
    if (!draggedItem) return;
    
    const qty = prompt(`Enter quantity of ${draggedItem.ingredient_name} needed for this menu item:`, '0.250');
    if (!qty) return;
    
    const formData = new FormData();
    formData.append('action', 'save_recipe');
    formData.append('menu_item_id', menuItemId);
    formData.append('inventory_id', draggedItem.id);
    formData.append('quantity_needed', qty);
    formData.append('unit', draggedItem.unit || 'kg');

    fetch(CHEF_API, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) location.reload();
        });
}
</script>
<script src="<?= BASE_URL ?>assets/js/ingredient_recipe_mapping.js?v=3"></script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
