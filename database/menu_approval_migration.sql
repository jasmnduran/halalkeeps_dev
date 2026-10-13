-- ============================================================
-- Menu Item Approval Workflow Migration
-- Run against halal_system DB (idempotent — safe to re-run)
-- ============================================================

-- 1. Add approval-tracking columns to menu_items
ALTER TABLE `menu_items`
    ADD COLUMN IF NOT EXISTS `approval_status`  ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER `is_available`,
    ADD COLUMN IF NOT EXISTS `submitted_by`     INT UNSIGNED NULL AFTER `approval_status`,
    ADD COLUMN IF NOT EXISTS `reviewed_by`      INT UNSIGNED NULL AFTER `submitted_by`,
    ADD COLUMN IF NOT EXISTS `reviewed_at`      DATETIME     NULL AFTER `reviewed_by`,
    ADD COLUMN IF NOT EXISTS `rejection_reason` VARCHAR(255) NULL AFTER `reviewed_at`;

-- 2. Foreign keys (conditional — skips if already present)
ALTER TABLE `menu_items`
    ADD CONSTRAINT IF NOT EXISTS `fk_menu_submitted_by`
        FOREIGN KEY (`submitted_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    ADD CONSTRAINT IF NOT EXISTS `fk_menu_reviewed_by`
        FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- 3. Performance index for approval queue queries
CREATE INDEX IF NOT EXISTS `idx_mi_approval`
    ON `menu_items` (`restaurant_id`, `approval_status`);

-- 4. CRITICAL: Backfill existing items to 'approved'
--    Ensures currently live items remain visible immediately after deployment.
UPDATE `menu_items`
SET    `approval_status` = 'approved'
WHERE  `approval_status` = 'pending'
   AND `created_at` < NOW() - INTERVAL 1 MINUTE;
--    (Items created within the last minute are left pending because they
--     were just submitted through the new workflow.)
