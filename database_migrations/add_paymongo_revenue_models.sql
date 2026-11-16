-- Halal Keeps PayMongo revenue models (additive migration)
-- Apply once per database before enabling the billing interfaces.

ALTER TABLE `orders`
    ADD COLUMN `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `subtotal`,
    ADD COLUMN `platform_fee` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `delivery_fee`,
    ADD COLUMN `platform_fee_rate` DECIMAL(7,6) NOT NULL DEFAULT 0.000000 AFTER `platform_fee`,
    ADD COLUMN `paymongo_checkout_session_id` VARCHAR(100) DEFAULT NULL AFTER `payment_reference`,
    ADD COLUMN `receipt_number` VARCHAR(80) DEFAULT NULL AFTER `paymongo_checkout_session_id`,
    ADD COLUMN `receipt_issued_at` DATETIME DEFAULT NULL AFTER `receipt_number`,
    ADD KEY `idx_orders_paymongo_checkout` (`paymongo_checkout_session_id`),
    ADD KEY `idx_orders_receipt_number` (`receipt_number`);

CREATE TABLE IF NOT EXISTS `billing_payment_requests` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `internal_reference` VARCHAR(80) NOT NULL,
    `purchaser_user_id` INT(11) NOT NULL,
    `restaurant_id` INT(11) DEFAULT NULL,
    `order_id` INT(11) DEFAULT NULL,
    `service_type` VARCHAR(50) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `amount_minor` INT UNSIGNED NOT NULL,
    `currency` CHAR(3) NOT NULL DEFAULT 'PHP',
    `provider` VARCHAR(30) NOT NULL DEFAULT 'paymongo',
    `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
    `checkout_session_id` VARCHAR(100) DEFAULT NULL,
    `provider_payment_id` VARCHAR(100) DEFAULT NULL,
    `idempotency_key` VARCHAR(100) NOT NULL,
    `metadata_json` MEDIUMTEXT DEFAULT NULL,
    `provider_response` MEDIUMTEXT DEFAULT NULL,
    `paid_at` DATETIME DEFAULT NULL,
    `refunded_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bpr_reference` (`internal_reference`),
    UNIQUE KEY `uq_bpr_checkout` (`checkout_session_id`),
    UNIQUE KEY `uq_bpr_idempotency` (`idempotency_key`),
    KEY `idx_bpr_restaurant` (`restaurant_id`,`status`),
    KEY `idx_bpr_order` (`order_id`),
    KEY `idx_bpr_purchaser` (`purchaser_user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hacera_report_purchases` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payment_request_id` BIGINT UNSIGNED DEFAULT NULL,
    `restaurant_id` INT(11) NOT NULL,
    `purchaser_user_id` INT(11) NOT NULL,
    `snapshot_hash` CHAR(64) NOT NULL,
    `snapshot_json` MEDIUMTEXT NOT NULL,
    `delivery_status` VARCHAR(30) NOT NULL DEFAULT 'pending_payment',
    `generated_pdf_path` VARCHAR(500) DEFAULT NULL,
    `generated_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_hrp_request` (`payment_request_id`),
    KEY `idx_hrp_rest_hash` (`restaurant_id`,`snapshot_hash`),
    KEY `idx_hrp_purchaser` (`purchaser_user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pnl_export_purchases` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payment_request_id` BIGINT UNSIGNED DEFAULT NULL,
    `restaurant_id` INT(11) NOT NULL,
    `purchaser_user_id` INT(11) NOT NULL,
    `period_start` DATE NOT NULL,
    `period_end` DATE NOT NULL,
    `snapshot_hash` CHAR(64) NOT NULL,
    `snapshot_json` MEDIUMTEXT NOT NULL,
    `delivery_status` VARCHAR(30) NOT NULL DEFAULT 'pending_payment',
    `generated_pdf_path` VARCHAR(500) DEFAULT NULL,
    `generated_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pep_request` (`payment_request_id`),
    KEY `idx_pep_rest_hash` (`restaurant_id`,`snapshot_hash`),
    KEY `idx_pep_period` (`restaurant_id`,`period_start`,`period_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_storage_packs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payment_request_id` BIGINT UNSIGNED DEFAULT NULL,
    `restaurant_id` INT(11) NOT NULL,
    `purchaser_user_id` INT(11) NOT NULL,
    `additional_slots` INT UNSIGNED NOT NULL DEFAULT 50,
    `activated_at` DATETIME DEFAULT NULL,
    `expires_at` DATETIME DEFAULT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'pending_payment',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_isp_request` (`payment_request_id`),
    KEY `idx_isp_active` (`restaurant_id`,`status`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `halal_keeps_revenue_ledger` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payment_request_id` BIGINT UNSIGNED NOT NULL,
    `restaurant_id` INT(11) DEFAULT NULL,
    `order_id` INT(11) DEFAULT NULL,
    `entry_type` VARCHAR(50) NOT NULL,
    `gross_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `restaurant_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `platform_revenue` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `processor_fee` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `refunded_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `status` VARCHAR(30) NOT NULL DEFAULT 'recognized',
    `recognized_at` DATETIME DEFAULT NULL,
    `refunded_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_hkrl_request` (`payment_request_id`),
    KEY `idx_hkrl_restaurant` (`restaurant_id`,`recognized_at`),
    KEY `idx_hkrl_order` (`order_id`),
    KEY `idx_hkrl_type` (`entry_type`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `paymongo_webhook_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `provider_event_id` VARCHAR(120) NOT NULL,
    `event_type` VARCHAR(100) NOT NULL,
    `livemode` TINYINT(1) NOT NULL DEFAULT 0,
    `signature_valid` TINYINT(1) NOT NULL DEFAULT 0,
    `processing_status` VARCHAR(30) NOT NULL DEFAULT 'processing',
    `payment_request_id` BIGINT UNSIGNED DEFAULT NULL,
    `raw_payload` MEDIUMTEXT NOT NULL,
    `error_message` VARCHAR(500) DEFAULT NULL,
    `received_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pwe_event` (`provider_event_id`),
    KEY `idx_pwe_request` (`payment_request_id`),
    KEY `idx_pwe_status` (`processing_status`,`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `system_settings` (`setting_key`,`setting_value`,`setting_type`,`description`) VALUES
('billing_hacera_report_fee', '150.00', 'number', 'Server-authoritative PHP price for a HaCeRa report PDF.'),
('billing_pnl_export_fee', '150.00', 'number', 'Server-authoritative PHP price for a P&L export.'),
('billing_inventory_pack_fee', '199.00', 'number', 'Server-authoritative PHP price for an inventory expansion pack.'),
('billing_inventory_pack_slots', '50', 'number', 'Additional ingredient-record slots per storage pack.'),
('billing_inventory_pack_days', '90', 'number', 'Inventory expansion pack validity in days.'),
('billing_inventory_base_slots', '49', 'number', 'Included logical kitchen inventory record limit.'),
('billing_platform_fee_enabled', '0', 'boolean', 'Keep disabled until marketplace fee policy is approved.'),
('billing_platform_fee_rate', '0.000000', 'number', 'When enabled must be between 0.0114 and 0.0120.'),
('billing_platform_fee_label', 'Halal Keeps platform fee', 'text', 'Customer-facing fee label for approved marketplace policy.');