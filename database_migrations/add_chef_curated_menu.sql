-- Chef-curated menu workflow: chef builds dishes from inventory; ops manager polishes listing.

ALTER TABLE `menu_items`
    ADD COLUMN IF NOT EXISTS `curated_by_chef` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `chef_curated_at` DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;
