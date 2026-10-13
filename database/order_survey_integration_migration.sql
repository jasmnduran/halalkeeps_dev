-- ============================================================================
-- Order-Survey Integration + Payment Verification + Cashier Workflow Migration
-- Run against halal_system DB (idempotent — safe to re-run)
-- ============================================================================

-- 1. Add table_number and survey linkage to orders table
ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `table_number` VARCHAR(20) DEFAULT NULL COMMENT 'Dine-in table number',
    ADD COLUMN IF NOT EXISTS `survey_response_id` INT UNSIGNED DEFAULT NULL COMMENT 'FK to survey_responses that enabled this order',
    ADD COLUMN IF NOT EXISTS `survey_verified` TINYINT(1) DEFAULT 0 COMMENT 'Survey completion verified before payment',
    ADD COLUMN IF NOT EXISTS `ordered_at` DATETIME DEFAULT NULL COMMENT 'When customer placed order';

-- 2. Add payment verification tracking to prevent duplicate revenue counting
ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `payment_verified_at` DATETIME DEFAULT NULL COMMENT 'When payment was verified (idempotent flag)',
    ADD COLUMN IF NOT EXISTS `revenue_counted` TINYINT(1) DEFAULT 0 COMMENT 'Revenue added to financial reports',
    ADD COLUMN IF NOT EXISTS `webhook_event_id` VARCHAR(100) DEFAULT NULL COMMENT 'PayMongo event ID for idempotency';

-- 3. Create index for survey linkage
CREATE INDEX IF NOT EXISTS `idx_survey_link` ON `orders` (`survey_response_id`);
CREATE INDEX IF NOT EXISTS `idx_payment_verified` ON `orders` (`payment_verified_at`);
CREATE INDEX IF NOT EXISTS `idx_revenue_counted` ON `orders` (`revenue_counted`);
CREATE INDEX IF NOT EXISTS `idx_webhook_event` ON `orders` (`webhook_event_id`);

-- 4. Ensure order_status ENUM has full workflow states
-- Note: Cannot use IF NOT EXISTS with ALTER COLUMN, so we'll use a safe approach
-- This will add missing ENUM values without removing existing ones

-- Check and update order_status to include all workflow states
-- Safe approach: Only modify if column exists and is ENUM
SET @db_name = DATABASE();
SET @tbl_name = 'orders';
SET @col_name = 'order_status';

-- Add workflow states if not present (MySQL will ignore if they already exist)
-- Common workflow: pending → confirmed → preparing → ready → completed
-- Also: cancelled, failed

-- We'll handle this in application code via db_add_column or manual ALTER if needed
-- For now, document expected values:
-- 'pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled', 'failed', 'delivered'

-- 5. Add cashier notification tracking
CREATE TABLE IF NOT EXISTS `cashier_notifications` (
    `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `restaurant_id`     INT(11)       NOT NULL,
    `order_id`          INT(11)       NOT NULL,
    `cashier_user_id`   INT(11)       DEFAULT NULL COMMENT 'Assigned cashier (NULL = all cashiers)',
    `notification_type` VARCHAR(50)   NOT NULL DEFAULT 'new_paid_order',
    `is_read`           TINYINT(1)    NOT NULL DEFAULT 0,
    `read_at`           DATETIME      DEFAULT NULL,
    `created_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    KEY `idx_restaurant_read` (`restaurant_id`, `is_read`),
    KEY `idx_cashier` (`cashier_user_id`),
    KEY `idx_order` (`order_id`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Real-time notifications for cashiers on new paid customer orders';

-- 6. Add order_source column to distinguish customer vs POS orders
ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `order_source` ENUM('customer','pos','api') DEFAULT 'customer' COMMENT 'Where order originated';

CREATE INDEX IF NOT EXISTS `idx_order_source` ON `orders` (`order_source`);

-- 7. Ensure payments table has idempotency tracking
ALTER TABLE `payments`
    ADD COLUMN IF NOT EXISTS `webhook_event_id` VARCHAR(100) DEFAULT NULL COMMENT 'PayMongo event ID',
    ADD COLUMN IF NOT EXISTS `verified_at` DATETIME DEFAULT NULL COMMENT 'Payment verification timestamp',
    ADD COLUMN IF NOT EXISTS `revenue_applied` TINYINT(1) DEFAULT 0 COMMENT 'Revenue added to restaurant totals';

CREATE INDEX IF NOT EXISTS `idx_webhook_event` ON `payments` (`webhook_event_id`);
CREATE INDEX IF NOT EXISTS `idx_revenue_applied` ON `payments` (`revenue_applied`);

-- 8. Add survey-to-order relationship tracking table (optional, for analytics)
CREATE TABLE IF NOT EXISTS `survey_order_tracking` (
    `id`                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `survey_response_id` INT UNSIGNED  NOT NULL,
    `order_id`           INT(11)       NOT NULL,
    `user_id`            INT(11)       NOT NULL,
    `restaurant_id`      INT(11)       NOT NULL,
    `order_total`        DECIMAL(10,2) NOT NULL,
    `linked_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    KEY `idx_survey` (`survey_response_id`),
    KEY `idx_order` (`order_id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_restaurant` (`restaurant_id`),
    CONSTRAINT `sot_survey_fk` FOREIGN KEY (`survey_response_id`) 
        REFERENCES `survey_responses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `sot_order_fk` FOREIGN KEY (`order_id`) 
        REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Links survey responses to resulting orders for TPB effectiveness analysis';

-- 9. Add restaurant revenue summary table for performance (cached aggregates)
CREATE TABLE IF NOT EXISTS `restaurant_revenue_summary` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `restaurant_id`   INT(11)         NOT NULL,
    `summary_date`    DATE            NOT NULL,
    `total_revenue`   DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    `order_count`     INT UNSIGNED    NOT NULL DEFAULT 0,
    `customer_orders` INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Orders from customers',
    `pos_orders`      INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Walk-in POS orders',
    `avg_order_value` DECIMAL(10,2)   DEFAULT NULL,
    `dine_in_count`   INT UNSIGNED    NOT NULL DEFAULT 0,
    `takeout_count`   INT UNSIGNED    NOT NULL DEFAULT 0,
    `delivery_count`  INT UNSIGNED    NOT NULL DEFAULT 0,
    `updated_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_restaurant_date` (`restaurant_id`, `summary_date`),
    KEY `idx_date` (`summary_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Daily revenue summaries for Business Owner Dashboard (cached for performance)';

-- 10. Add order status history table for audit trail
CREATE TABLE IF NOT EXISTS `order_status_history` (
    `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `order_id`        INT(11)       NOT NULL,
    `old_status`      VARCHAR(50)   DEFAULT NULL,
    `new_status`      VARCHAR(50)   NOT NULL,
    `changed_by`      INT(11)       DEFAULT NULL COMMENT 'User who made the change',
    `changed_by_role` VARCHAR(50)   DEFAULT NULL COMMENT 'cashier, owner, system',
    `notes`           TEXT          DEFAULT NULL,
    `changed_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    KEY `idx_order` (`order_id`),
    KEY `idx_changed_by` (`changed_by`),
    KEY `idx_changed_at` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Audit trail for order status changes';

-- 11. Performance indexes for common queries
CREATE INDEX IF NOT EXISTS `idx_orders_restaurant_status` ON `orders` (`restaurant_id`, `order_status`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_orders_customer_status` ON `orders` (`customer_id`, `order_status`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_orders_payment_status` ON `orders` (`payment_status`, `payment_verified_at`);
CREATE INDEX IF NOT EXISTS `idx_orders_service_type` ON `orders` (`service_type`);
CREATE INDEX IF NOT EXISTS `idx_orders_created_date` ON `orders` (`created_at`);

-- 12. Add missing indexes for survey tables
CREATE INDEX IF NOT EXISTS `idx_survey_responses_created` ON `survey_responses` (`created_at`);
CREATE INDEX IF NOT EXISTS `idx_survey_responses_user` ON `survey_responses` (`user_id`, `created_at`);

-- 13. Add order_placed_via column to track if order came from customer interface
ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `order_placed_via` ENUM('customer_web','pos','api','mobile') DEFAULT 'customer_web' COMMENT 'Interface used to place order';

-- 14. Ensure ordered_at has proper default for existing rows
UPDATE `orders` 
SET `ordered_at` = `created_at` 
WHERE `ordered_at` IS NULL AND `created_at` IS NOT NULL;

-- 15. Migration notes and validation queries
-- To verify migration success, run:
-- SELECT COUNT(*) FROM cashier_notifications;
-- SELECT COUNT(*) FROM survey_order_tracking;
-- SELECT COUNT(*) FROM restaurant_revenue_summary;
-- SELECT COUNT(*) FROM order_status_history;
-- SHOW INDEX FROM orders WHERE Key_name LIKE 'idx_%';

-- ============================================================================
-- Migration Complete
-- ============================================================================
