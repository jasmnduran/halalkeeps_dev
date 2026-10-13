-- Intelligent Document Screening rollback.
-- Run only after preserving any required audit records and confirming that the
-- feature flag is disabled. Existing uploads and application data are untouched.

DROP TABLE IF EXISTS `document_review_decisions`;
DROP TABLE IF EXISTS `document_validation_findings`;
DROP TABLE IF EXISTS `document_extracted_fields`;
DROP TABLE IF EXISTS `document_screening_jobs`;
DROP TABLE IF EXISTS `certifier_recognition_records`;
