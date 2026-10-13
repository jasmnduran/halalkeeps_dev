-- ============================================================
-- Restaurant Staff Management Migration
-- Run once against the halal_final database.
-- All statements are idempotent (IF NOT EXISTS / IF NOT EXISTS).
-- ============================================================

-- ── 1. Restaurant-level staff roles ─────────────────────────────────────────
-- These are PER-RESTAURANT roles that are separate from the system-wide
-- `roles` table. A user can be a Business Owner in the system (role_id = 2)
-- and simultaneously be the Operations Manager of a specific restaurant.

CREATE TABLE IF NOT EXISTS `restaurant_staff_roles` (
    `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_key`    VARCHAR(30)  NOT NULL COMMENT 'machine key: owner, ops_manager, cashier, chef',
    `role_label`  VARCHAR(60)  NOT NULL COMMENT 'display label',
    `description` VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_role_key` (`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default roles (ON DUPLICATE KEY UPDATE prevents auto_increment overflow)
INSERT INTO `restaurant_staff_roles` (`role_key`, `role_label`, `description`) VALUES
('owner',       'Business Owner',     'Full administrative access: analytics, financials, staff management.'),
('ops_manager', 'Operations Manager', 'Store profile, menu management, and Halal compliance.'),
('cashier',     'Cashier',            'POS order intake and payment processing only.'),
('chef',        'Chef / Head Cook',   'Manages kitchen inventory, requisitions, and menu recipe mapping.')
ON DUPLICATE KEY UPDATE role_label = VALUES(role_label), description = VALUES(description);


-- ── 2. Staff assignment table ────────────────────────────────────────────────
-- Maps a system user to a restaurant with a restaurant-level staff role.
-- One user can hold one role per restaurant (UNIQUE constraint on user+restaurant).

CREATE TABLE IF NOT EXISTS `restaurant_staff` (
    `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `restaurant_id` INT(11)          NOT NULL,
    `user_id`       INT(11)          NOT NULL,
    `staff_role_id` SMALLINT UNSIGNED NOT NULL,
    `is_active`     TINYINT(1)       NOT NULL DEFAULT 1,
    `assigned_by`   INT(11)          NOT NULL COMMENT 'user_id of the Business Owner who assigned this',
    `assigned_at`   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_restaurant` (`user_id`, `restaurant_id`),
    KEY `idx_rs_restaurant` (`restaurant_id`),
    KEY `idx_rs_user`       (`user_id`),
    KEY `idx_rs_role`       (`staff_role_id`),
    CONSTRAINT `rs_restaurant_fk`  FOREIGN KEY (`restaurant_id`) REFERENCES `halal_restaurants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `rs_user_fk`        FOREIGN KEY (`user_id`)       REFERENCES `users`             (`id`) ON DELETE CASCADE,
    CONSTRAINT `rs_staff_role_fk`  FOREIGN KEY (`staff_role_id`) REFERENCES `restaurant_staff_roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 3. Revenue snapshot table (for owner dashboard charts) ──────────────────
-- Pre-aggregated daily revenue per restaurant for fast dashboard queries.
-- Populated by a nightly job or on each order completion (INSERT … ON DUPLICATE KEY UPDATE).

CREATE TABLE IF NOT EXISTS `restaurant_revenue_daily` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `restaurant_id` INT(11)      NOT NULL,
    `snapshot_date` DATE         NOT NULL,
    `total_orders`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `gross_revenue` DECIMAL(12,2)     NOT NULL DEFAULT 0.00,
    `updated_at`    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rrd_rest_date` (`restaurant_id`, `snapshot_date`),
    KEY `idx_rrd_date` (`snapshot_date`),
    CONSTRAINT `rrd_restaurant_fk` FOREIGN KEY (`restaurant_id`) REFERENCES `halal_restaurants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Manager PIN columns (added in RBAC v2) ────────────────────────────────
-- manager_pin stores a bcrypt hash of a 4–8 digit PIN.
-- NULL = no PIN set (manager cannot authorise via PIN until one is configured).

ALTER TABLE `restaurant_staff`
    ADD COLUMN IF NOT EXISTS `manager_pin` VARCHAR(255) DEFAULT NULL
    COMMENT 'bcrypt hash of manager override PIN (ops_manager / owner only)';

ALTER TABLE `halal_restaurants`
    ADD COLUMN IF NOT EXISTS `manager_pin` VARCHAR(255) DEFAULT NULL
    COMMENT 'bcrypt hash of the restaurant owner override PIN';
