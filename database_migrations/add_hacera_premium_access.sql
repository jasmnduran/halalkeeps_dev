-- HaCeRa premium access binding (additive migration)
-- Apply after database/hacera_ai_insights_migration.sql and
-- database_migrations/add_paymongo_revenue_models.sql.
-- Existing AI records intentionally remain unlinked and therefore locked.

ALTER TABLE `hacera_ai_runs`
    ADD COLUMN IF NOT EXISTS `assessment_version_hash` CHAR(64) DEFAULT NULL AFTER `input_hash`,
    ADD KEY IF NOT EXISTS `idx_hacera_ai_runs_version` (`restaurant_id`, `assessment_version_hash`);
