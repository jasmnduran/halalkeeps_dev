-- Customer checkout intents keep cart data outside orders until PayMongo verifies payment.
-- Apply once after add_paymongo_revenue_models.sql.

CREATE TABLE IF NOT EXISTS `customer_order_checkout_intents` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payment_request_id` BIGINT UNSIGNED DEFAULT NULL,
    `order_id` INT(11) DEFAULT NULL,
    `customer_id` INT(11) NOT NULL,
    `restaurant_id` INT(11) NOT NULL,
    `items_json` MEDIUMTEXT NOT NULL,
    `subtotal` DECIMAL(12,2) NOT NULL,
    `delivery_fee` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `platform_fee` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `platform_fee_rate` DECIMAL(7,6) NOT NULL DEFAULT 0.000000,
    `total_amount` DECIMAL(12,2) NOT NULL,
    `delivery_address` VARCHAR(1000) DEFAULT NULL,
    `service_type` VARCHAR(30) NOT NULL,
    `payment_method` VARCHAR(50) NOT NULL,
    `notes` TEXT DEFAULT NULL,
    `table_number` VARCHAR(20) DEFAULT NULL,
    `survey_response_id` INT UNSIGNED DEFAULT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'pending_payment',
    `paid_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_coci_payment_request` (`payment_request_id`),
    UNIQUE KEY `uq_coci_order` (`order_id`),
    KEY `idx_coci_customer_status` (`customer_id`, `status`, `created_at`),
    KEY `idx_coci_restaurant_status` (`restaurant_id`, `status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;