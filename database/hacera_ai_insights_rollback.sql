-- Destructive rollback for HaCeRa AI Insights feature data only.
-- Take a backup first. Existing HaCeRa, restaurant, and certification data are untouched.
DROP TABLE IF EXISTS `hacera_ai_feedback`;
DROP TABLE IF EXISTS `hacera_ai_actions`;
DROP TABLE IF EXISTS `hacera_ai_evidence_links`;
DROP TABLE IF EXISTS `hacera_ai_findings`;
DROP TABLE IF EXISTS `hacera_ai_runs`;
