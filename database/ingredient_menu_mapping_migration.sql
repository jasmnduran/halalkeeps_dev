-- AI-assisted ingredient-to-menu mapping (additive migration)
--
-- Preconditions:
--   1. Back up the database.
--   2. Apply this file before setting INGREDIENT_MENU_MAPPING_ENABLED=true.
--
-- This migration deliberately does not alter menu approval decisions, HaCeRa
-- responses/scores, payment rules, or certification records.  The existing
-- kitchen_inventory and menu_recipe_ingredients tables remain the compatibility
-- source for legacy screens; the new tables provide batch-safe stock accounting.

ALTER TABLE `menu_items`
    ADD COLUMN IF NOT EXISTS `operational_status`
        ENUM('available','limited_stock','unavailable','inventory_not_confirmed')
        DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `ingredient_evidence_status`
        ENUM('reviewed','available','pending_review','missing','expired','inconclusive','requires_clarification')
        DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `inventory_checked_at` DATETIME DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `ingredient_inventory_batches` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `restaurant_id` INT(11) NOT NULL,
    `inventory_id` INT UNSIGNED NOT NULL COMMENT 'Existing kitchen_inventory ingredient record',
    `batch_code` VARCHAR(120) DEFAULT NULL,
    `quantity_received` DECIMAL(14,3) NOT NULL,
    `quantity_available` DECIMAL(14,3) NOT NULL,
    `quantity_reserved` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    `unit` VARCHAR(30) NOT NULL,
    `purchase_date` DATE DEFAULT NULL,
    `expiration_date` DATE DEFAULT NULL,
    `storage_location` VARCHAR(150) DEFAULT NULL,
    `supplier_reference` VARCHAR(180) DEFAULT NULL,
    `source_type` ENUM('legacy_sync','purchase','adjustment','opening_balance') NOT NULL DEFAULT 'purchase',
    `batch_status` ENUM('active','quarantined','exhausted','void') NOT NULL DEFAULT 'active',
    `evidence_status` ENUM('reviewed','available','pending_review','missing','expired','inconclusive','requires_clarification') NOT NULL DEFAULT 'pending_review',
    `recorded_by` INT(11) NOT NULL,
    `last_updated_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_iib_inventory_source` (`inventory_id`, `source_type`),
    KEY `idx_iib_restaurant_stock` (`restaurant_id`, `batch_status`, `expiration_date`),
    KEY `idx_iib_inventory` (`inventory_id`),
    CONSTRAINT `fk_iib_inventory`
        FOREIGN KEY (`inventory_id`) REFERENCES `kitchen_inventory` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `menu_recipes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `restaurant_id` INT(11) NOT NULL,
    `menu_item_id` INT(11) NOT NULL,
    `recipe_name` VARCHAR(180) NOT NULL,
    `serving_size` VARCHAR(120) DEFAULT NULL,
    `recipe_status` ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    `customer_visibility_status` ENUM('draft','published','needs_reapproval') NOT NULL DEFAULT 'draft' COMMENT 'Chef-controlled customer ingredient disclosure state only',
    `customer_visible_by` INT(11) DEFAULT NULL,
    `customer_visible_at` DATETIME DEFAULT NULL,
    `version_no` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_by` INT(11) NOT NULL,
    `last_updated_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_menu_recipes_menu_status` (`menu_item_id`, `recipe_status`),
    KEY `idx_menu_recipes_restaurant` (`restaurant_id`, `recipe_status`),
    KEY `idx_menu_recipes_menu` (`menu_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compatibility upgrade for databases where menu_recipes was created by an
-- earlier revision of this feature. Publishing a recipe disclosure does not
-- change menu approval, operational availability, or any Halal decision.
ALTER TABLE `menu_recipes`
    ADD COLUMN IF NOT EXISTS `customer_visibility_status`
        ENUM('draft','published','needs_reapproval') NOT NULL DEFAULT 'draft'
        COMMENT 'Chef-controlled customer ingredient disclosure state only',
    ADD COLUMN IF NOT EXISTS `customer_visible_by` INT(11) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `customer_visible_at` DATETIME DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `recipe_ingredients` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `recipe_id` BIGINT UNSIGNED NOT NULL,
    `restaurant_id` INT(11) NOT NULL,
    `ingredient_name` VARCHAR(180) NOT NULL,
    `inventory_id` INT UNSIGNED DEFAULT NULL COMMENT 'Confirmed existing kitchen_inventory ID only',
    `suggested_inventory_id` INT UNSIGNED DEFAULT NULL,
    `quantity_required` DECIMAL(14,3) NOT NULL,
    `unit` VARCHAR(30) NOT NULL,
    `yield_factor` DECIMAL(8,3) NOT NULL DEFAULT 1.000 COMMENT 'Usable yield from 0.001 to 1.000',
    `mapping_status` ENUM('unmatched','suggested','confirmed','rejected') NOT NULL DEFAULT 'unmatched',
    `mapping_source` ENUM('manual','legacy','normalized_name','verified_alias','ai') NOT NULL DEFAULT 'manual',
    `substitution_warning` VARCHAR(500) DEFAULT NULL,
    `confirmed_by` INT(11) DEFAULT NULL,
    `confirmed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ri_recipe` (`recipe_id`),
    KEY `idx_ri_restaurant_mapping` (`restaurant_id`, `mapping_status`),
    KEY `idx_ri_inventory` (`inventory_id`),
    CONSTRAINT `fk_ri_recipe`
        FOREIGN KEY (`recipe_id`) REFERENCES `menu_recipes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ri_inventory`
        FOREIGN KEY (`inventory_id`) REFERENCES `kitchen_inventory` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ri_suggested_inventory`
        FOREIGN KEY (`suggested_inventory_id`) REFERENCES `kitchen_inventory` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recipe_versions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `recipe_id` BIGINT UNSIGNED NOT NULL,
    `restaurant_id` INT(11) NOT NULL,
    `version_no` INT UNSIGNED NOT NULL,
    `snapshot_json` MEDIUMTEXT NOT NULL,
    `change_reason` VARCHAR(255) DEFAULT NULL,
    `created_by` INT(11) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_recipe_versions_recipe_version` (`recipe_id`, `version_no`),
    KEY `idx_recipe_versions_restaurant` (`restaurant_id`, `created_at`),
    CONSTRAINT `fk_recipe_versions_recipe`
        FOREIGN KEY (`recipe_id`) REFERENCES `menu_recipes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ingredient_aliases` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `restaurant_id` INT(11) NOT NULL,
    `inventory_id` INT UNSIGNED NOT NULL,
    `alias_name` VARCHAR(180) NOT NULL,
    `normalized_alias` VARCHAR(180) NOT NULL,
    `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `verified_by` INT(11) DEFAULT NULL,
    `verified_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ingredient_alias` (`restaurant_id`, `normalized_alias`),
    KEY `idx_ingredient_alias_inventory` (`inventory_id`),
    CONSTRAINT `fk_ingredient_alias_inventory`
        FOREIGN KEY (`inventory_id`) REFERENCES `kitchen_inventory` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_ingredient_mappings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `restaurant_id` INT(11) NOT NULL,
    `recipe_ingredient_id` BIGINT UNSIGNED NOT NULL,
    `suggested_inventory_id` INT UNSIGNED DEFAULT NULL,
    `match_category` ENUM('exact_id','normalized_name','verified_alias','semantic','unmatched') NOT NULL,
    `confidence_indicator` ENUM('high','medium','low','none') NOT NULL DEFAULT 'none',
    `explanation` VARCHAR(1000) NOT NULL,
    `requires_manual_confirmation` TINYINT(1) NOT NULL DEFAULT 1,
    `potential_substitution_warning` VARCHAR(500) DEFAULT NULL,
    `provider` VARCHAR(50) DEFAULT NULL,
    `model` VARCHAR(120) DEFAULT NULL,
    `prompt_version` VARCHAR(80) DEFAULT NULL,
    `review_status` ENUM('pending','confirmed','rejected','superseded') NOT NULL DEFAULT 'pending',
    `reviewed_by` INT(11) DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_aim_restaurant_review` (`restaurant_id`, `review_status`, `created_at`),
    KEY `idx_aim_recipe_ingredient` (`recipe_ingredient_id`),
    CONSTRAINT `fk_aim_recipe_ingredient`
        FOREIGN KEY (`recipe_ingredient_id`) REFERENCES `recipe_ingredients` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_aim_suggested_inventory`
        FOREIGN KEY (`suggested_inventory_id`) REFERENCES `kitchen_inventory` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ingredient_documents` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `restaurant_id` INT(11) NOT NULL,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_type` VARCHAR(80) NOT NULL,
    `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
    `supplier_reference` VARCHAR(180) DEFAULT NULL,
    `certificate_reference` VARCHAR(180) DEFAULT NULL,
    `issue_date` DATE DEFAULT NULL,
    `expiration_date` DATE DEFAULT NULL,
    `scope_note` VARCHAR(500) DEFAULT NULL,
    `document_status` ENUM('available','pending_review','reviewed','missing','expired','inconclusive','requires_clarification') NOT NULL DEFAULT 'pending_review',
    `reviewed_by` INT(11) DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `review_note` VARCHAR(1000) DEFAULT NULL,
    `uploaded_by` INT(11) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ingredient_documents_batch` (`batch_id`, `document_status`),
    KEY `idx_ingredient_documents_restaurant` (`restaurant_id`, `created_at`),
    CONSTRAINT `fk_ingredient_documents_batch`
        FOREIGN KEY (`batch_id`) REFERENCES `ingredient_inventory_batches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ingredient_stock_reservations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `restaurant_id` INT(11) NOT NULL,
    `order_id` INT UNSIGNED NOT NULL,
    `recipe_ingredient_id` BIGINT UNSIGNED NOT NULL,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `quantity_reserved` DECIMAL(14,3) NOT NULL,
    `unit` VARCHAR(30) NOT NULL,
    `reservation_status` ENUM('active','consumed','released') NOT NULL DEFAULT 'active',
    `reserved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `released_at` DATETIME DEFAULT NULL,
    `consumed_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_isr_order_recipe_batch` (`order_id`, `recipe_ingredient_id`, `batch_id`),
    KEY `idx_isr_batch_status` (`batch_id`, `reservation_status`),
    KEY `idx_isr_order_status` (`order_id`, `reservation_status`),
    CONSTRAINT `fk_isr_recipe_ingredient`
        FOREIGN KEY (`recipe_ingredient_id`) REFERENCES `recipe_ingredients` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_isr_batch`
        FOREIGN KEY (`batch_id`) REFERENCES `ingredient_inventory_batches` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ingredient_stock_movements` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `restaurant_id` INT(11) NOT NULL,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `order_id` INT UNSIGNED DEFAULT NULL,
    `movement_type` ENUM('receipt','adjustment','reservation','release','consumption','void') NOT NULL,
    `quantity` DECIMAL(14,3) NOT NULL,
    `unit` VARCHAR(30) NOT NULL,
    `reference_note` VARCHAR(500) DEFAULT NULL,
    `idempotency_key` VARCHAR(180) DEFAULT NULL,
    `recorded_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ism_idempotency` (`idempotency_key`),
    KEY `idx_ism_batch_created` (`batch_id`, `created_at`),
    KEY `idx_ism_order` (`order_id`, `movement_type`),
    KEY `idx_ism_restaurant_created` (`restaurant_id`, `created_at`),
    CONSTRAINT `fk_ism_batch`
        FOREIGN KEY (`batch_id`) REFERENCES `ingredient_inventory_batches` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill one batch for every existing kitchen inventory row. Existing rows are
-- treated as a manual/legacy mapping, never as an independent certification.
INSERT INTO `ingredient_inventory_batches`
    (`restaurant_id`, `inventory_id`, `batch_code`, `quantity_received`, `quantity_available`, `quantity_reserved`, `unit`, `expiration_date`, `source_type`, `batch_status`, `evidence_status`, `recorded_by`, `last_updated_by`)
SELECT ki.restaurant_id, ki.id, CONCAT('LEGACY-', ki.id),
       GREATEST(ki.quantity, 0), GREATEST(ki.quantity, 0), 0,
       ki.unit, ki.expiration_date, 'legacy_sync',
       CASE WHEN ki.quantity <= 0 THEN 'exhausted' ELSE 'active' END,
       'pending_review', ki.logged_by, ki.logged_by
FROM kitchen_inventory ki
WHERE NOT EXISTS (
    SELECT 1 FROM ingredient_inventory_batches existing_batch
    WHERE existing_batch.inventory_id = ki.id AND existing_batch.source_type = 'legacy_sync'
);

-- Backfill current legacy recipe mappings. A chef selected these mappings in the
-- legacy UI, so they are recorded as manual legacy mappings rather than AI output.
INSERT INTO `menu_recipes`
    (`restaurant_id`, `menu_item_id`, `recipe_name`, `serving_size`, `recipe_status`, `version_no`, `created_by`, `last_updated_by`)
SELECT mi.restaurant_id, mi.id, CONCAT(mi.name, ' recipe'), NULL, 'active', 1,
       COALESCE(mi.submitted_by, hr.business_owner_id), COALESCE(mi.submitted_by, hr.business_owner_id)
FROM menu_items mi
JOIN halal_restaurants hr ON hr.id = mi.restaurant_id
JOIN menu_recipe_ingredients mri ON mri.menu_item_id = mi.id
WHERE NOT EXISTS (
    SELECT 1 FROM menu_recipes existing_recipe
    WHERE existing_recipe.menu_item_id = mi.id
      AND existing_recipe.recipe_status = 'active'
);

INSERT INTO `recipe_ingredients`
    (`recipe_id`, `restaurant_id`, `ingredient_name`, `inventory_id`, `quantity_required`, `unit`, `yield_factor`, `mapping_status`, `mapping_source`, `confirmed_at`)
SELECT mr.id, mr.restaurant_id, ki.ingredient_name, mri.inventory_id,
       mri.quantity_needed, mri.unit, 1.000, 'confirmed', 'legacy', NOW()
FROM menu_recipe_ingredients mri
JOIN menu_items mi ON mi.id = mri.menu_item_id
JOIN menu_recipes mr ON mr.menu_item_id = mi.id AND mr.recipe_status = 'active'
JOIN kitchen_inventory ki ON ki.id = mri.inventory_id AND ki.restaurant_id = mi.restaurant_id
WHERE NOT EXISTS (
    SELECT 1 FROM recipe_ingredients existing_ingredient
    WHERE existing_ingredient.recipe_id = mr.id
      AND existing_ingredient.inventory_id = mri.inventory_id
      AND existing_ingredient.mapping_source = 'legacy'
);
