-- ============================================================================
-- Demand Survey Checkpoint + PayMongo Payment Integration Migration
-- Run against halal_system DB (idempotent — safe to re-run)
-- ============================================================================

-- 1. Ensure survey_responses table exists with all TPB columns
CREATE TABLE IF NOT EXISTS `survey_responses` (
    `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`           INT(11)       NOT NULL,
    `questionnaire_set` TINYINT(1)    NOT NULL COMMENT '1=Muslim, 2=Non-Muslim',
    `religion`          VARCHAR(30)   DEFAULT NULL,
    `food_preference`   VARCHAR(50)   DEFAULT NULL,
    
    -- ATT (Attitude) items
    `ATT1`              TINYINT(1)    NOT NULL COMMENT 'Likert 1-5',
    `ATT2`              TINYINT(1)    NOT NULL,
    `ATT3`              TINYINT(1)    NOT NULL,
    `ATT4`              TINYINT(1)    NOT NULL,
    `ATT5`              TINYINT(1)    NOT NULL,
    
    -- SN (Subjective Norm) items
    `SN1`               TINYINT(1)    NOT NULL,
    `SN2`               TINYINT(1)    NOT NULL,
    `SN3`               TINYINT(1)    NOT NULL,
    `SN4`               TINYINT(1)    NOT NULL,
    `SN5`               TINYINT(1)    NOT NULL,
    
    -- PBC (Perceived Behavioral Control) items
    `PBC1`              TINYINT(1)    NOT NULL,
    `PBC2`              TINYINT(1)    NOT NULL,
    `PBC3`              TINYINT(1)    NOT NULL,
    `PBC4`              TINYINT(1)    NOT NULL,
    `PBC5`              TINYINT(1)    NOT NULL,
    
    -- Computed TPB metrics
    `mean_att`          DECIMAL(4,2)  DEFAULT NULL COMMENT 'Mean of ATT1-5',
    `mean_sn`           DECIMAL(4,2)  DEFAULT NULL COMMENT 'Mean of SN1-5',
    `mean_pbc`          DECIMAL(4,2)  DEFAULT NULL COMMENT 'Mean of PBC1-5',
    `raw_pi`            DECIMAL(5,2)  DEFAULT NULL COMMENT 'ATT+SN+PBC (3-15)',
    `norm_pi`           DECIMAL(4,2)  DEFAULT NULL COMMENT 'Raw PI / 3 (1-5)',
    
    `created_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    KEY `idx_user_survey` (`user_id`),
    CONSTRAINT `sr_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Add PayMongo payment columns to orders table
ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `paymongo_payment_intent_id` VARCHAR(100) DEFAULT NULL AFTER `payment_method`,
    ADD COLUMN IF NOT EXISTS `paymongo_checkout_url`      VARCHAR(500) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `paymongo_raw_response`      TEXT         DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `paymongo_payment_status`    VARCHAR(30)  DEFAULT NULL COMMENT 'awaiting_payment_method, succeeded, failed',
    ADD COLUMN IF NOT EXISTS `survey_completed_at_order`  TINYINT(1)   DEFAULT 0 COMMENT 'Survey status checkpoint at order time';

-- 3. Add index for PayMongo lookups
CREATE INDEX IF NOT EXISTS `idx_paymongo_intent` ON `orders` (`paymongo_payment_intent_id`);

-- 4. Add survey gate checkpoint flag to users (optional tracking)
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `demand_survey_completed` TINYINT(1) DEFAULT 0 AFTER `role_id`,
    ADD COLUMN IF NOT EXISTS `demand_survey_completed_at` DATETIME DEFAULT NULL;

-- 5. Create PayMongo webhook log table for audit trail
CREATE TABLE IF NOT EXISTS `paymongo_webhook_logs` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_type`    VARCHAR(60)  NOT NULL COMMENT 'e.g. payment_intent.succeeded',
    `event_id`      VARCHAR(100) DEFAULT NULL,
    `payment_intent_id` VARCHAR(100) DEFAULT NULL,
    `order_id`      INT(11)      DEFAULT NULL,
    `status`        VARCHAR(30)  DEFAULT NULL,
    `raw_payload`   MEDIUMTEXT   DEFAULT NULL,
    `processed`     TINYINT(1)   DEFAULT 0,
    `error_message` TEXT         DEFAULT NULL,
    `received_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_event_id` (`event_id`),
    KEY `idx_payment_intent` (`payment_intent_id`),
    KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Performance indexes
CREATE INDEX IF NOT EXISTS `idx_survey_checkpoint` ON `users` (`demand_survey_completed`);
