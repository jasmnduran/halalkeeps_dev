-- ============================================================
-- TPB PBC Upgrade Migration
-- Adds PBC construct, questionnaire_set, updates PI formula
-- PI = Mean(ATT) + Mean(SN) + Mean(PBC)  [raw: 3.0–15.0]
-- Normalized PI (1–5) = raw_pi / 3
-- Run against halal_system DB after tpb_survey_migration.sql
-- ============================================================

-- 1. Add new columns to tpb_survey_responses
ALTER TABLE `tpb_survey_responses`
    ADD COLUMN IF NOT EXISTS `questionnaire_set` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1 = Muslim (Set 1), 2 = Non-Muslim (Set 2)' AFTER `food_preference`,
    ADD COLUMN IF NOT EXISTS `mean_pbc`       DECIMAL(4,3) DEFAULT NULL
        COMMENT 'Mean of PBC1-PBC5 (1.000-5.000)' AFTER `mean_sn`,
    ADD COLUMN IF NOT EXISTS `raw_pi`         DECIMAL(5,3) DEFAULT NULL
        COMMENT 'ATT+SN+PBC raw sum (3.000-15.000)' AFTER `mean_pbc`,
    ADD COLUMN IF NOT EXISTS `normalized_pi`  DECIMAL(4,3) DEFAULT NULL
        COMMENT 'raw_pi / 3  →  1.000-5.000' AFTER `raw_pi`;

-- Drop old columns no longer used by the new formula
-- (predicted_pi kept for backward compat; mean_pi_actual kept for validation)
-- We just add the new ones without removing old ones for safety.

-- 2. Refresh analytical views with PBC support

CREATE OR REPLACE VIEW `tpb_demand_summary` AS
SELECT
    religion,
    questionnaire_set,
    COUNT(*)                                        AS total_respondents,
    ROUND(AVG(mean_att),       3)                   AS avg_att,
    ROUND(AVG(mean_sn),        3)                   AS avg_sn,
    ROUND(AVG(mean_pbc),       3)                   AS avg_pbc,
    ROUND(AVG(raw_pi),         3)                   AS avg_raw_pi,
    ROUND(AVG(normalized_pi),  3)                   AS avg_normalized_pi,
    -- Percentage index: (score-1)/4*100  → 20%-100%
    ROUND(((AVG(normalized_pi) - 1) / 4) * 100, 1) AS pi_percentage_index
FROM `tpb_survey_responses`
WHERE normalized_pi IS NOT NULL
GROUP BY religion, questionnaire_set;

CREATE OR REPLACE VIEW `tpb_market_demand` AS
SELECT
    COUNT(*)                                        AS total_respondents,
    ROUND(AVG(mean_att),       3)                   AS avg_att,
    ROUND(AVG(mean_sn),        3)                   AS avg_sn,
    ROUND(AVG(mean_pbc),       3)                   AS avg_pbc,
    ROUND(AVG(raw_pi),         3)                   AS overall_raw_pi,
    ROUND(AVG(normalized_pi),  3)                   AS overall_normalized_pi,
    ROUND(((AVG(normalized_pi) - 1) / 4) * 100, 1) AS overall_pi_percentage
FROM `tpb_survey_responses`
WHERE normalized_pi IS NOT NULL;

CREATE OR REPLACE VIEW `tpb_item_averages` AS
SELECT
    r.religion,
    r.questionnaire_set,
    a.item_code,
    ROUND(AVG(a.score), 3) AS avg_score,
    COUNT(*)               AS n
FROM `tpb_survey_answers` a
JOIN `tpb_survey_responses` r ON a.response_id = r.id
GROUP BY r.religion, r.questionnaire_set, a.item_code;
