-- Destructive rollback for the ingredient-to-menu mapping feature only.
-- Take a backup before running. Existing kitchen inventory, legacy recipe
-- mappings, menu approval data, HaCeRa, payments, and orders are untouched.

DROP TABLE IF EXISTS `ingredient_stock_movements`;
DROP TABLE IF EXISTS `ingredient_stock_reservations`;
DROP TABLE IF EXISTS `ingredient_documents`;
DROP TABLE IF EXISTS `ai_ingredient_mappings`;
DROP TABLE IF EXISTS `ingredient_aliases`;
DROP TABLE IF EXISTS `recipe_versions`;
DROP TABLE IF EXISTS `recipe_ingredients`;
DROP TABLE IF EXISTS `menu_recipes`;
DROP TABLE IF EXISTS `ingredient_inventory_batches`;

ALTER TABLE `menu_items`
    DROP COLUMN IF EXISTS `inventory_checked_at`,
    DROP COLUMN IF EXISTS `ingredient_evidence_status`,
    DROP COLUMN IF EXISTS `operational_status`;
