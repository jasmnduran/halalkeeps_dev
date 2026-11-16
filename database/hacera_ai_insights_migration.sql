-- HaCeRa AI Readiness Insights (additive migration)
-- Run after taking a database backup. This migration does not alter existing
-- HaCeRa responses, HRP fields, scoring rules, or certification tables.

CREATE TABLE IF NOT EXISTS `hacera_ai_runs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `restaurant_id` INT(11) NOT NULL,
    `requested_by` INT(11) NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'queued',
    `input_hash` CHAR(64) NOT NULL,
    `assessment_version_hash` CHAR(64) DEFAULT NULL COMMENT 'Paid HaCeRa assessment version; legacy rows stay locked',
    `provider` VARCHAR(50) NOT NULL DEFAULT 'openai_compatible',
    `model` VARCHAR(120) NOT NULL DEFAULT '',
    `prompt_version` VARCHAR(60) NOT NULL,
    `criteria_version` VARCHAR(80) NOT NULL,
    `summary` MEDIUMTEXT DEFAULT NULL,
    `report_json` MEDIUMTEXT DEFAULT NULL COMMENT 'Validated report only; never raw provider request/response',
    `validated_finding_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `rejected_finding_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `error_code` VARCHAR(64) DEFAULT NULL,
    `error_message` VARCHAR(500) DEFAULT NULL,
    `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `started_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_hacera_ai_runs_restaurant` (`restaurant_id`, `requested_at`),
    KEY `idx_hacera_ai_runs_queue` (`status`, `requested_at`),
    KEY `idx_hacera_ai_runs_hash` (`restaurant_id`, `input_hash`),
    KEY `idx_hacera_ai_runs_version` (`restaurant_id`, `assessment_version_hash`),
    CONSTRAINT `fk_hacera_ai_runs_restaurant`
        FOREIGN KEY (`restaurant_id`) REFERENCES `halal_restaurants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hacera_ai_runs_user`
        FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hacera_ai_findings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `run_id` BIGINT UNSIGNED NOT NULL,
    `restaurant_id` INT(11) NOT NULL,
    `module_id` TINYINT UNSIGNED NOT NULL,
    `checklist_item_id` SMALLINT UNSIGNED NOT NULL,
    `title` VARCHAR(180) NOT NULL,
    `finding_type` VARCHAR(40) NOT NULL,
    `explanation` TEXT NOT NULL,
    `severity` VARCHAR(16) NOT NULL,
    `criteria_reference` VARCHAR(40) NOT NULL,
    `recommended_corrective_action` TEXT NOT NULL,
    `evidence_status` VARCHAR(40) NOT NULL,
    `requires_expert_review` TINYINT(1) NOT NULL DEFAULT 0,
    `validation_status` VARCHAR(20) NOT NULL DEFAULT 'validated',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hacera_ai_findings_run` (`run_id`),
    KEY `idx_hacera_ai_findings_rest_item` (`restaurant_id`, `checklist_item_id`),
    CONSTRAINT `fk_hacera_ai_findings_run`
        FOREIGN KEY (`run_id`) REFERENCES `hacera_ai_runs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hacera_ai_findings_restaurant`
        FOREIGN KEY (`restaurant_id`) REFERENCES `halal_restaurants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hacera_ai_evidence_links` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `finding_id` BIGINT UNSIGNED NOT NULL,
    `restaurant_id` INT(11) NOT NULL,
    `hacera_document_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_hacera_ai_evidence_link` (`finding_id`, `hacera_document_id`),
    KEY `idx_hacera_ai_evidence_rest_doc` (`restaurant_id`, `hacera_document_id`),
    CONSTRAINT `fk_hacera_ai_evidence_finding`
        FOREIGN KEY (`finding_id`) REFERENCES `hacera_ai_findings` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hacera_ai_evidence_restaurant`
        FOREIGN KEY (`restaurant_id`) REFERENCES `halal_restaurants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hacera_ai_actions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `run_id` BIGINT UNSIGNED NOT NULL,
    `finding_id` BIGINT UNSIGNED NOT NULL,
    `restaurant_id` INT(11) NOT NULL,
    `title` VARCHAR(180) NOT NULL,
    `description` TEXT NOT NULL,
    `priority` VARCHAR(16) NOT NULL,
    `action_status` VARCHAR(30) NOT NULL DEFAULT 'not_started',
    `requires_expert_review` TINYINT(1) NOT NULL DEFAULT 0,
    `status_updated_by` INT(11) DEFAULT NULL,
    `status_updated_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hacera_ai_actions_restaurant` (`restaurant_id`, `action_status`),
    KEY `idx_hacera_ai_actions_run` (`run_id`),
    CONSTRAINT `fk_hacera_ai_actions_run`
        FOREIGN KEY (`run_id`) REFERENCES `hacera_ai_runs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hacera_ai_actions_finding`
        FOREIGN KEY (`finding_id`) REFERENCES `hacera_ai_findings` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hacera_ai_actions_restaurant`
        FOREIGN KEY (`restaurant_id`) REFERENCES `halal_restaurants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hacera_ai_actions_user`
        FOREIGN KEY (`status_updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hacera_ai_feedback` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `run_id` BIGINT UNSIGNED NOT NULL,
    `restaurant_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `rating` TINYINT UNSIGNED NOT NULL,
    `comment` VARCHAR(1000) NOT NULL DEFAULT '',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_hacera_ai_feedback_user_run` (`run_id`, `user_id`),
    KEY `idx_hacera_ai_feedback_restaurant` (`restaurant_id`, `created_at`),
    CONSTRAINT `fk_hacera_ai_feedback_run`
        FOREIGN KEY (`run_id`) REFERENCES `hacera_ai_runs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hacera_ai_feedback_restaurant`
        FOREIGN KEY (`restaurant_id`) REFERENCES `halal_restaurants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hacera_ai_feedback_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
