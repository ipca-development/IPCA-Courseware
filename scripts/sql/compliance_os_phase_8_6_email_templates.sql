-- =============================================================================
-- Compliance Operating System — Phase 8.6: Versioned email templates
-- =============================================================================
-- Stores editable Compliance Comms Center templates with immutable versions.
-- Apply: mysql ... < scripts/sql/compliance_os_phase_8_6_email_templates.sql
-- =============================================================================

CREATE TABLE IF NOT EXISTS ipca_compliance_email_templates (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template_key       VARCHAR(80) NOT NULL,
  title              VARCHAR(160) NOT NULL,
  description        TEXT NULL,
  current_version_id BIGINT UNSIGNED NULL,
  created_by         INT UNSIGNED NULL,
  updated_by         INT UNSIGNED NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcaet_key (template_key),
  KEY idx_ipcaet_current_version (current_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance Comms Center — editable email template roots.';

CREATE TABLE IF NOT EXISTS ipca_compliance_email_template_versions (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template_id            BIGINT UNSIGNED NOT NULL,
  version_no             INT UNSIGNED NOT NULL,
  subject_template       VARCHAR(500) NOT NULL,
  html_template          MEDIUMTEXT NOT NULL,
  text_template          MEDIUMTEXT NULL,
  allowed_variables_json JSON NULL,
  change_note            VARCHAR(500) NULL,
  created_by             INT UNSIGNED NULL,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcaetv_template_version (template_id, version_no),
  KEY idx_ipcaetv_template_created (template_id, created_at),
  CONSTRAINT fk_ipcaetv_template FOREIGN KEY (template_id)
    REFERENCES ipca_compliance_email_templates (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance Comms Center — immutable email template versions.';

CREATE TABLE IF NOT EXISTS ipca_compliance_settings (
  setting_key        VARCHAR(120) NOT NULL PRIMARY KEY,
  setting_value_json JSON NOT NULL,
  updated_by         INT UNSIGNED NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — org-level configurable settings.';

-- =============================================================================
-- END OF FILE
-- =============================================================================
