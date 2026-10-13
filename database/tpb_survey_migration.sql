-- ============================================================
-- TPB Survey Engine Migration
-- Theory of Planned Behavior — Consumer Demand Survey
-- Formula: Predicted PI = 0.554 × Mean(ATT) + 0.379 × Mean(SN)
-- Run this in phpMyAdmin or MySQL CLI against the halal_system DB
-- ============================================================

-- 1. Survey Responses: one row per survey submission per user
CREATE TABLE IF NOT EXISTS `tpb_survey_responses` (
    `id`              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `user_id`         INT(11)        NOT NULL,
    -- Demographic snapshot captured at time of submission (mirrors users profile)
    `religion`        ENUM('Muslim','Non-Muslim') NOT NULL,
    `food_preference` ENUM('Halal','Non-Pork','Muslim-Friendly','Any') DEFAULT NULL,
    -- Computed scores stored for fast aggregation (avoids re-aggregating items table)
    `mean_att`        DECIMAL(4,3)   DEFAULT NULL COMMENT 'Mean of ATT1-ATT10 (1.000-5.000)',
    `mean_sn`         DECIMAL(4,3)   DEFAULT NULL COMMENT 'Mean of SN1-SN10 (1.000-5.000)',
    `mean_pi_actual`  DECIMAL(4,3)   DEFAULT NULL COMMENT 'Actual Mean(PI) from PI1-PI5 (1.000-5.000)',
    `predicted_pi`    DECIMAL(5,4)   DEFAULT NULL COMMENT '0.554*mean_att + 0.379*mean_sn (result may exceed 5.0 at ceiling)',
    `submitted_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- One active response per user (unique); re-taking the survey UPDATEs the existing row
    UNIQUE KEY `uq_survey_user` (`user_id`),
    KEY `idx_tpb_religion`  (`religion`),
    KEY `idx_tpb_submitted` (`submitted_at`),
    CONSTRAINT `tpb_user_fk`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='TPB survey — one record per user, updated on re-take';

-- 2. Survey Answers: individual Likert responses (1-5) per item per response
CREATE TABLE IF NOT EXISTS `tpb_survey_answers` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `response_id` INT UNSIGNED NOT NULL,
    `item_code`   VARCHAR(10)  NOT NULL COMMENT 'ATT1-ATT10, SN1-SN10, PI1-PI5',
    `score`       TINYINT(1)   NOT NULL COMMENT '1=Strongly Disagree … 5=Strongly Agree',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_answer_item` (`response_id`, `item_code`),
    KEY `idx_tpb_item` (`item_code`),
    CONSTRAINT `tpb_ans_response_fk`
        FOREIGN KEY (`response_id`) REFERENCES `tpb_survey_responses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Individual Likert scores (1-5) per item per survey submission';

-- 3. Analytical view: aggregate PI scores grouped by religion
CREATE OR REPLACE VIEW `tpb_demand_summary` AS
SELECT
    religion,
    COUNT(*)                         AS total_respondents,
    ROUND(AVG(mean_att),    3)       AS avg_att,
    ROUND(AVG(mean_sn),     3)       AS avg_sn,
    ROUND(AVG(predicted_pi),4)       AS avg_predicted_pi,
    ROUND(AVG(mean_pi_actual),3)     AS avg_actual_pi,
    -- Percentage index: scale (score - 1) / 4 * 100  so 1.0→20%, 5.0→100%
    ROUND(((AVG(predicted_pi) - 1) / 4) * 100, 1)  AS pi_percentage_index
FROM `tpb_survey_responses`
GROUP BY religion;

-- 4. Analytical view: overall market demand (all respondents)
CREATE OR REPLACE VIEW `tpb_market_demand` AS
SELECT
    COUNT(*)                                        AS total_respondents,
    ROUND(AVG(mean_att),    3)                      AS avg_att,
    ROUND(AVG(mean_sn),     3)                      AS avg_sn,
    ROUND(AVG(predicted_pi),4)                      AS overall_predicted_pi,
    ROUND(AVG(mean_pi_actual),3)                    AS overall_actual_pi,
    ROUND(((AVG(predicted_pi) - 1) / 4) * 100, 1)  AS overall_pi_percentage
FROM `tpb_survey_responses`;

-- 5. Item-level summary view (to determine dominant driver per demographic)
CREATE OR REPLACE VIEW `tpb_item_averages` AS
SELECT
    r.religion,
    a.item_code,
    ROUND(AVG(a.score), 3) AS avg_score,
    COUNT(*)               AS n
FROM `tpb_survey_answers` a
JOIN `tpb_survey_responses` r ON a.response_id = r.id
GROUP BY r.religion, a.item_code;
