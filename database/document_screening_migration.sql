-- =============================================================================
-- Intelligent Document Screening (additive migration)
--
-- Apply after taking a database backup. This migration never changes business
-- application decisions, certificate status, HaCeRa answers, or uploaded files.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `document_screening_jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source_type` VARCHAR(40) NOT NULL,
    `source_document_id` INT(11) NOT NULL,
    `application_id` INT(11) DEFAULT NULL,
    `restaurant_id` INT(11) DEFAULT NULL,
    `requested_by` INT(11) NOT NULL,
    `status` VARCHAR(24) NOT NULL DEFAULT 'queued',
    `document_type` VARCHAR(50) DEFAULT NULL,
    `classification_confidence` DECIMAL(5,4) DEFAULT NULL,
    `ocr_method` VARCHAR(50) DEFAULT NULL,
    `ocr_confidence` DECIMAL(5,4) DEFAULT NULL,
    `text_hash` CHAR(64) DEFAULT NULL,
    `extracted_text` MEDIUMTEXT DEFAULT NULL COMMENT 'Temporary OCR text; purge after text_retention_until.',
    `text_retention_until` DATETIME DEFAULT NULL,
    `ruleset_version` VARCHAR(60) NOT NULL DEFAULT 'document-screening-v1',
    `model_version` VARCHAR(120) DEFAULT NULL,
    `error_code` VARCHAR(64) DEFAULT NULL,
    `error_message` VARCHAR(500) DEFAULT NULL,
    `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `started_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_dsj_source` (`source_type`, `source_document_id`),
    KEY `idx_dsj_application` (`application_id`, `requested_at`),
    KEY `idx_dsj_restaurant` (`restaurant_id`, `requested_at`),
    KEY `idx_dsj_queue` (`status`, `requested_at`),
    CONSTRAINT `fk_dsj_application` FOREIGN KEY (`application_id`)
        REFERENCES `business_applications` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_dsj_requested_by` FOREIGN KEY (`requested_by`)
        REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_extracted_fields` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_id` BIGINT UNSIGNED NOT NULL,
    `field_key` VARCHAR(80) NOT NULL,
    `raw_value` TEXT DEFAULT NULL,
    `normalized_value` TEXT DEFAULT NULL,
    `confidence` DECIMAL(5,4) DEFAULT NULL,
    `source_page` SMALLINT UNSIGNED DEFAULT NULL,
    `source_location` VARCHAR(255) DEFAULT NULL,
    `is_uncertain` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_def_job` (`job_id`, `field_key`),
    CONSTRAINT `fk_def_job` FOREIGN KEY (`job_id`)
        REFERENCES `document_screening_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_validation_findings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_id` BIGINT UNSIGNED NOT NULL,
    `application_id` INT(11) DEFAULT NULL,
    `restaurant_id` INT(11) DEFAULT NULL,
    `extracted_field_id` BIGINT UNSIGNED DEFAULT NULL,
    `rule_id` VARCHAR(80) NOT NULL,
    `field_key` VARCHAR(80) DEFAULT NULL,
    `outcome` VARCHAR(32) NOT NULL,
    `severity` VARCHAR(16) NOT NULL DEFAULT 'low',
    `submitted_value` TEXT DEFAULT NULL,
    `extracted_value` TEXT DEFAULT NULL,
    `explanation` TEXT NOT NULL,
    `recommendation` VARCHAR(80) NOT NULL DEFAULT 'continue_review',
    `review_status` VARCHAR(24) NOT NULL DEFAULT 'open',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dvf_job` (`job_id`),
    KEY `idx_dvf_application` (`application_id`, `review_status`, `severity`),
    KEY `idx_dvf_restaurant` (`restaurant_id`, `review_status`),
    CONSTRAINT `fk_dvf_job` FOREIGN KEY (`job_id`)
        REFERENCES `document_screening_jobs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_dvf_field` FOREIGN KEY (`extracted_field_id`)
        REFERENCES `document_extracted_fields` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `certifier_recognition_records` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `certifier_name` VARCHAR(255) NOT NULL,
    `normalized_certifier_name` VARCHAR(255) NOT NULL,
    `recognition_scheme` VARCHAR(50) NOT NULL,
    `recognition_status` VARCHAR(40) NOT NULL DEFAULT 'not_independently_verified',
    `scope_description` TEXT DEFAULT NULL,
    `valid_from` DATE DEFAULT NULL,
    `valid_to` DATE DEFAULT NULL,
    `verification_source` VARCHAR(500) DEFAULT NULL,
    `source_checked_at` DATETIME DEFAULT NULL,
    `source_reference` VARCHAR(255) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_crr_certifier_scheme` (`normalized_certifier_name`, `recognition_scheme`),
    KEY `idx_crr_status` (`recognition_status`),
    CONSTRAINT `fk_crr_created_by` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_review_decisions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `finding_id` BIGINT UNSIGNED NOT NULL,
    `application_id` INT(11) DEFAULT NULL,
    `reviewer_id` INT(11) NOT NULL,
    `decision_type` VARCHAR(40) NOT NULL,
    `corrected_value` TEXT DEFAULT NULL,
    `reviewer_comment` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_drd_finding` (`finding_id`, `created_at`),
    KEY `idx_drd_application` (`application_id`, `created_at`),
    CONSTRAINT `fk_drd_finding` FOREIGN KEY (`finding_id`)
        REFERENCES `document_validation_findings` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_drd_reviewer` FOREIGN KEY (`reviewer_id`)
        REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
