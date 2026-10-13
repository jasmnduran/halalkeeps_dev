-- =============================================================================
-- Migration: Business Applications
-- Run once against the `halal_system` database in phpMyAdmin or MySQL CLI.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Main application record (one per customer submission)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `business_applications` (
    `id`                    INT(11)          NOT NULL AUTO_INCREMENT,
    `user_id`               INT(11)          NOT NULL,

    -- Gatekeeper: did the applicant answer YES or NO to "already halal-certified?"
    `certification_path`    ENUM('certified','not_certified') NOT NULL,

    -- Business profile (shared by both paths)
    `business_name`         VARCHAR(255)     DEFAULT NULL,
    `business_description`  TEXT             NOT NULL,

    -- Location
    `address_street`        VARCHAR(255)     DEFAULT NULL,
    `address_city`          VARCHAR(100)     DEFAULT NULL,
    `address_province`      VARCHAR(100)     DEFAULT NULL,
    `address_zip`           VARCHAR(20)      DEFAULT NULL,
    `latitude`              DECIMAL(10,8)    DEFAULT NULL,
    `longitude`             DECIMAL(11,8)    DEFAULT NULL,

    -- Business owner profile (shared by both paths)
    `owner_name`            VARCHAR(255)     NOT NULL,
    `owner_age`             TINYINT UNSIGNED DEFAULT NULL,
    `owner_religion`        VARCHAR(100)     DEFAULT NULL,

    -- Application state
    `status`                ENUM('pending','under_review','approved','rejected','returned')
                            NOT NULL DEFAULT 'pending',
    `admin_notes`           TEXT             DEFAULT NULL,
    `reviewed_by`           INT(11)          DEFAULT NULL,
    `reviewed_at`           DATETIME         DEFAULT NULL,

    `created_at`            TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_ba_user`       (`user_id`),
    KEY `idx_ba_status`     (`status`),
    KEY `idx_ba_path`       (`certification_path`),

    CONSTRAINT `ba_user_fk`
        FOREIGN KEY (`user_id`)     REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `ba_reviewer_fk`
        FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- 2. Uploaded documents (many per application)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `business_application_documents` (
    `id`                INT(11)      NOT NULL AUTO_INCREMENT,
    `application_id`    INT(11)      NOT NULL,

    -- Controlled vocabulary of document types.
    -- *_opt  = optional (upload encouraged but not required for submission).
    `doc_type`          ENUM(
                            -- Path A  (certified)
                            'halal_certificate',
                            'halal_assurance_manual',
                            -- Path B  (not_certified) — required
                            'business_permit',
                            'sanitary_permit',
                            'lto_permit',
                            'dti_license',
                            'proof_of_halal',
                            'halal_cert_raw_materials',
                            -- Path B  (not_certified) — optional
                            'halal_assurance_manual_opt'
                        ) NOT NULL,

    `original_name`     VARCHAR(255) NOT NULL,
    `file_path`         VARCHAR(500) NOT NULL,
    `file_size`         INT UNSIGNED DEFAULT NULL,   -- bytes
    `mime_type`         VARCHAR(100) DEFAULT NULL,

    `uploaded_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_bad_app`   (`application_id`),
    KEY `idx_bad_type`  (`doc_type`),

    CONSTRAINT `bad_app_fk`
        FOREIGN KEY (`application_id`)
        REFERENCES `business_applications` (`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
