-- =============================================================================
-- Halal Keeps — Halal Food Business Directory Seed
-- Run once against the halal_system database.
-- All statements are idempotent (CREATE TABLE IF NOT EXISTS / INSERT IGNORE).
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 1. halal_directory_businesses  — public directory of food establishments
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `halal_directory_businesses` (
    `id`               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `business_name`    VARCHAR(255)     NOT NULL,
    `category`         ENUM('halal_certified','muslim_friendly','non_pork') NOT NULL,
    `address`          VARCHAR(500)     DEFAULT NULL,
    `city`             VARCHAR(100)     NOT NULL DEFAULT 'Davao City',
    `province`         VARCHAR(100)     NOT NULL DEFAULT 'Davao del Sur',
    `region_code`      VARCHAR(10)      NOT NULL DEFAULT 'XI',
    `is_halal_keeps`   TINYINT(1)       NOT NULL DEFAULT 0
                       COMMENT '1 = actively registered on the Halal Keeps platform',
    `halal_keeps_id`   INT(11)          DEFAULT NULL
                       COMMENT 'FK to halal_restaurants.id when is_halal_keeps = 1',
    `is_active`        TINYINT(1)       NOT NULL DEFAULT 1,
    `created_at`       TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hdb_city`     (`city`),
    KEY `idx_hdb_region`   (`region_code`),
    KEY `idx_hdb_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. halal_directory_city_metrics  — pre-aggregated/seeded totals per city
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `halal_directory_city_metrics` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `city`                VARCHAR(100) NOT NULL,
    `province`            VARCHAR(100) NOT NULL,
    `region_code`         VARCHAR(10)  NOT NULL,
    `total_food_biz`      INT UNSIGNED NOT NULL DEFAULT 0
                          COMMENT 'Total registered food establishments (LGU estimate)',
    `halal_certified`     INT UNSIGNED NOT NULL DEFAULT 0,
    `muslim_friendly`     INT UNSIGNED NOT NULL DEFAULT 0,
    `non_pork`            INT UNSIGNED NOT NULL DEFAULT 0,
    `is_live`             TINYINT(1)   NOT NULL DEFAULT 0
                          COMMENT '1 = real data, 0 = dummy/seed baseline',
    `updated_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_city_province` (`city`, `province`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- DAVAO CITY — REAL / LIVE DATA
-- ===========================================================================

-- A. Halal-Certified (4)
INSERT IGNORE INTO `halal_directory_businesses`
    (`business_name`, `category`, `address`, `city`, `province`, `region_code`, `is_halal_keeps`)
VALUES
    ('Tinhat Boutique Hotel, Restaurant & Catering Services', 'halal_certified',
     '407-B Artiaga St., Barangay 36-D, Poblacion District, Davao City', 'Davao City', 'Davao del Sur', 'XI', 1),
    ('Bistro Maimoona Restaurant', 'halal_certified',
     'J.P. Laurel Ave., Bajada, Poblacion District, Davao City', 'Davao City', 'Davao del Sur', 'XI', 1),
    ('Sunny Point Hotel', 'halal_certified',
     '818 Don Julian Rodriguez Sr. Ave, Ma-a Rd, Davao City', 'Davao City', 'Davao del Sur', 'XI', 0),
    ('Dusit Thani Residence Davao', 'halal_certified',
     'Maryknoll Drive, Bo. Pampanga, Lanang, Davao City', 'Davao City', 'Davao del Sur', 'XI', 0);

-- B. Muslim-Friendly (5)
INSERT IGNORE INTO `halal_directory_businesses`
    (`business_name`, `category`, `address`, `city`, `province`, `region_code`, `is_halal_keeps`)
VALUES
    ('Biryani Box Halal', 'muslim_friendly',
     'Davao - Cotabato Rd, Sirawan, Toril, Davao City', 'Davao City', 'Davao del Sur', 'XI', 1),
    ('Brew It Cafe', 'muslim_friendly',
     'Door 3, E-Teng Building, Lim St, Toril, Davao City', 'Davao City', 'Davao del Sur', 'XI', 1),
    ('Al-Saba Restaurant', 'muslim_friendly',
     'McArthur Highway, Toril, Davao City', 'Davao City', 'Davao del Sur', 'XI', 0),
    ('Higop Sabaw Repablik', 'muslim_friendly',
     'Tugbok, Davao City', 'Davao City', 'Davao del Sur', 'XI', 0),
    ('Allesa''s Kitchen Halal Food', 'muslim_friendly',
     'Flores Village, 113B Sampaguita St., Bangkal, Davao City', 'Davao City', 'Davao del Sur', 'XI', 0);

-- C. Non-Pork / Compliant (3)
INSERT IGNORE INTO `halal_directory_businesses`
    (`business_name`, `category`, `address`, `city`, `province`, `region_code`, `is_halal_keeps`)
VALUES
    ('Mac-Mac Halal Eatery', 'non_pork',
     'Toril, Davao City', 'Davao City', 'Davao del Sur', 'XI', 0),
    ('Rainash Halal Eatery', 'non_pork',
     'General Lao St, Toril, Davao City', 'Davao City', 'Davao del Sur', 'XI', 0),
    ('Yanong''s Pizza', 'non_pork',
     'Lubogan, Toril, Davao City', 'Davao City', 'Davao del Sur', 'XI', 0);

-- D. Davao City aggregate metric (live = 1)
INSERT INTO `halal_directory_city_metrics`
    (`city`, `province`, `region_code`, `total_food_biz`, `halal_certified`, `muslim_friendly`, `non_pork`, `is_live`)
VALUES
    ('Davao City', 'Davao del Sur', 'XI', 4200, 4, 5, 3, 1)
ON DUPLICATE KEY UPDATE
    `total_food_biz` = 4200,
    `halal_certified` = 4,
    `muslim_friendly` = 5,
    `non_pork` = 3,
    `is_live` = 1;

-- ===========================================================================
-- REGION XI — DUMMY BASELINE SEED (other provinces, is_live = 0)
-- ===========================================================================
INSERT INTO `halal_directory_city_metrics`
    (`city`, `province`, `region_code`, `total_food_biz`, `halal_certified`, `muslim_friendly`, `non_pork`, `is_live`)
VALUES
    ('Digos City',     'Davao del Sur',   'XI',  820,  1, 2, 1, 0),
    ('Tagum City',     'Davao del Norte', 'XI', 1250,  2, 3, 2, 0),
    ('Panabo City',    'Davao del Norte', 'XI',  640,  1, 2, 1, 0),
    ('Mati City',      'Davao Oriental',  'XI',  490,  1, 1, 1, 0),
    ('Nabunturan',     'Davao de Oro',    'XI',  310,  0, 1, 1, 0),
    ('Malita',         'Davao Occidental','XI',  210,  0, 1, 0, 0)
ON DUPLICATE KEY UPDATE
    `total_food_biz` = VALUES(`total_food_biz`),
    `halal_certified` = VALUES(`halal_certified`),
    `muslim_friendly` = VALUES(`muslim_friendly`),
    `non_pork`        = VALUES(`non_pork`),
    `is_live`         = VALUES(`is_live`);
