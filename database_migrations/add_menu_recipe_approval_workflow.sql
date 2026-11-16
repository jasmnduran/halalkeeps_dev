-- Menu/recipe approval workflow (additive and safe for existing approved menus)
-- Apply after database/ingredient_menu_mapping_migration.sql.
-- This keeps menu_items.approval_status unchanged for legacy integrations and
-- stores the richer Chef -> Operations Manager workflow separately.

ALTER TABLE `menu_items`
    ADD COLUMN IF NOT EXISTS `workflow_status` VARCHAR(30) NOT NULL DEFAULT 'legacy' AFTER `approval_status`,
    ADD COLUMN IF NOT EXISTS `workflow_updated_at` DATETIME NULL DEFAULT NULL AFTER `workflow_status`;

CREATE INDEX IF NOT EXISTS `idx_menu_workflow_queue`
    ON `menu_items` (`restaurant_id`, `workflow_status`, `approval_status`);

CREATE TABLE IF NOT EXISTS `menu_item_approval_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `restaurant_id` INT(11) NOT NULL,
    `menu_item_id` INT(11) NOT NULL,
    `recipe_id` BIGINT UNSIGNED DEFAULT NULL,
    `recipe_version_no` INT UNSIGNED DEFAULT NULL,
    `submitted_by` INT(11) DEFAULT NULL,
    `reviewed_by` INT(11) DEFAULT NULL,
    `decision` ENUM('submitted','resubmitted','approved','needs_revision','rejected') NOT NULL,
    `remarks` VARCHAR(1000) DEFAULT NULL,
    `submitted_at` DATETIME DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_miah_menu_created` (`menu_item_id`, `created_at`),
    KEY `idx_miah_restaurant_decision` (`restaurant_id`, `decision`, `created_at`),
    CONSTRAINT `fk_miah_menu_item`
        FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_miah_recipe`
        FOREIGN KEY (`recipe_id`) REFERENCES `menu_recipes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Current menu rows pre-date this workflow. Keeping them in the legacy state
-- means the customer/POS compatibility guard continues to serve them while
-- every newly-created menu is explicitly written as a draft by the API.
UPDATE `menu_items`
SET `workflow_status` = 'legacy'
WHERE `workflow_status` IS NULL OR TRIM(`workflow_status`) = '';
