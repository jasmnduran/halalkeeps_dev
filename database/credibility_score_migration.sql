-- ============================================================
-- Credibility Score System Migration
-- Run against halal_system DB
-- ============================================================

-- 1. Add credibility columns to halal_restaurants
ALTER TABLE `halal_restaurants`
    ADD COLUMN IF NOT EXISTS `credibility_score`      DECIMAL(5,2)  DEFAULT NULL
        COMMENT 'Composite credibility score 0-100',
    ADD COLUMN IF NOT EXISTS `credibility_tier`       TINYINT(1)    DEFAULT NULL
        COMMENT '3=Top Tier, 2=Highly Recommended, 1=Popular Choice, 0=Unranked',
    ADD COLUMN IF NOT EXISTS `credibility_updated_at` DATETIME      DEFAULT NULL
        COMMENT 'When score was last recomputed';

-- 2. Snapshot log — one row per recompute per restaurant
CREATE TABLE IF NOT EXISTS `restaurant_credibility_log` (
    `id`                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `restaurant_id`       INT(11)       NOT NULL,
    -- Component scores (each 0–100)
    `score_foot_traffic`  DECIMAL(5,2)  NOT NULL DEFAULT 0
        COMMENT 'Dine-in/takeout orders last 30 days (normalised 0-100)',
    `score_engagement`    DECIMAL(5,2)  NOT NULL DEFAULT 0
        COMMENT 'Reviews, rating quality, profile views (normalised 0-100)',
    `score_compliance`    DECIMAL(5,2)  NOT NULL DEFAULT 0
        COMMENT 'Halal certification + HaCeRa HRP (normalised 0-100)',
    `score_popularity`    DECIMAL(5,2)  NOT NULL DEFAULT 0
        COMMENT 'Repeat-visit momentum over 30-day rolling window (normalised 0-100)',
    -- Weighted composite
    `total_score`         DECIMAL(5,2)  NOT NULL DEFAULT 0
        COMMENT 'Final weighted composite 0-100',
    `tier`                TINYINT(1)    NOT NULL DEFAULT 0
        COMMENT '3=Top Tier, 2=Highly Recommended, 1=Popular Choice, 0=Unranked',
    -- Raw inputs snapshot for auditability
    `raw_dine_in_30d`     INT UNSIGNED  NOT NULL DEFAULT 0,
    `raw_profile_views_30d` INT UNSIGNED NOT NULL DEFAULT 0,
    `raw_avg_rating`      DECIMAL(3,2)  NOT NULL DEFAULT 0,
    `raw_total_reviews`   INT UNSIGNED  NOT NULL DEFAULT 0,
    `raw_hacera_hrp`      DECIMAL(5,1)  NOT NULL DEFAULT 0,
    `raw_has_cert`        TINYINT(1)    NOT NULL DEFAULT 0,
    `computed_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rcl_restaurant` (`restaurant_id`),
    KEY `idx_rcl_computed`   (`computed_at`),
    CONSTRAINT `rcl_restaurant_fk`
        FOREIGN KEY (`restaurant_id`) REFERENCES `halal_restaurants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Audit trail of credibility score computations per restaurant';

-- 3. Index for fast leaderboard queries on the main table
CREATE INDEX IF NOT EXISTS `idx_hr_credibility`
    ON `halal_restaurants` (`credibility_score` DESC, `is_active`);
