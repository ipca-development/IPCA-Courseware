-- IPCA Flight Training Forms foundation.
-- Phase 1 only: template registry, template versions, template fields, and audit log.
-- Re-run safe: CREATE TABLE IF NOT EXISTS plus guarded ALTER statements.

CREATE TABLE IF NOT EXISTS ipca_form_templates (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template_key          VARCHAR(96) NOT NULL,
  title                 VARCHAR(255) NOT NULL,
  description           TEXT NULL,
  category              VARCHAR(96) NULL,
  current_version_id    BIGINT UNSIGNED NULL,
  status                VARCHAR(32) NOT NULL DEFAULT 'draft' COMMENT 'draft | active | archived',
  metadata_json         JSON NULL,
  created_by            INT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_form_templates_key (template_key),
  KEY idx_ipca_form_templates_status_category (status, category),
  KEY idx_ipca_form_templates_current_version (current_version_id),
  KEY idx_ipca_form_templates_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training Forms - reusable structured form templates.';

CREATE TABLE IF NOT EXISTS ipca_form_template_versions (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template_id           BIGINT UNSIGNED NOT NULL,
  version_label         VARCHAR(128) NOT NULL,
  lifecycle_status      VARCHAR(32) NOT NULL DEFAULT 'draft' COMMENT 'draft | active | archived | superseded',
  title                 VARCHAR(255) NOT NULL,
  content_json          JSON NULL COMMENT 'Structured document content blocks and layout metadata.',
  variable_map_json     JSON NULL COMMENT 'Declared variables and bindings used by this template version.',
  field_schema_json     JSON NULL COMMENT 'Frozen field schema snapshot for this template version.',
  content_hash          CHAR(64) NULL,
  supersedes_version_id BIGINT UNSIGNED NULL,
  created_by            INT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_by           INT NULL,
  approved_at           DATETIME NULL,
  UNIQUE KEY uk_ipca_form_template_versions_label (template_id, version_label),
  KEY idx_ipca_form_template_versions_status (template_id, lifecycle_status),
  KEY idx_ipca_form_template_versions_supersedes (supersedes_version_id),
  KEY idx_ipca_form_template_versions_created_by (created_by),
  KEY idx_ipca_form_template_versions_approved_by (approved_by),
  KEY idx_ipca_form_template_versions_hash (content_hash),
  CONSTRAINT fk_ipca_form_template_versions_template FOREIGN KEY (template_id)
    REFERENCES ipca_form_templates (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_form_template_versions_supersedes FOREIGN KEY (supersedes_version_id)
    REFERENCES ipca_form_template_versions (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training Forms - immutable template versions.';

CREATE TABLE IF NOT EXISTS ipca_form_fields (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template_version_id   BIGINT UNSIGNED NOT NULL,
  field_key             VARCHAR(128) NOT NULL,
  field_type            VARCHAR(32) NOT NULL COMMENT 'text | textarea | checkbox | date | signature | initial | table_cell',
  label                 VARCHAR(255) NULL,
  required              TINYINT(1) NOT NULL DEFAULT 0,
  assigned_role         VARCHAR(64) NOT NULL DEFAULT 'instructor' COMMENT 'admin | instructor | student | other_instructor | examiner | external_party',
  variable_key          VARCHAR(191) NULL,
  validation_json       JSON NULL,
  position_json         JSON NULL,
  metadata_json         JSON NULL,
  sort_order            INT NOT NULL DEFAULT 0,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_form_fields_version_key (template_version_id, field_key),
  KEY idx_ipca_form_fields_version_order (template_version_id, sort_order),
  KEY idx_ipca_form_fields_role (template_version_id, assigned_role),
  KEY idx_ipca_form_fields_variable (variable_key),
  CONSTRAINT fk_ipca_form_fields_template_version FOREIGN KEY (template_version_id)
    REFERENCES ipca_form_template_versions (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training Forms - reusable field definitions for template versions.';

CREATE TABLE IF NOT EXISTS ipca_form_audit_log (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  form_instance_id      BIGINT UNSIGNED NULL COMMENT 'Reserved for later phases.',
  template_id           BIGINT UNSIGNED NULL,
  template_version_id   BIGINT UNSIGNED NULL,
  actor_user_id         INT NULL,
  actor_type            VARCHAR(32) NOT NULL DEFAULT 'system' COMMENT 'admin | instructor | student | external | system',
  event_type            VARCHAR(64) NOT NULL COMMENT 'template_created | template_archived | template_version_activated | field_changed | etc.',
  event_json            JSON NULL,
  ip_address            VARCHAR(64) NULL,
  user_agent            VARCHAR(255) NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipca_form_audit_template_time (template_id, created_at),
  KEY idx_ipca_form_audit_version_time (template_version_id, created_at),
  KEY idx_ipca_form_audit_instance_time (form_instance_id, created_at),
  KEY idx_ipca_form_audit_actor_time (actor_user_id, created_at),
  KEY idx_ipca_form_audit_event_time (event_type, created_at),
  CONSTRAINT fk_ipca_form_audit_template FOREIGN KEY (template_id)
    REFERENCES ipca_form_templates (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_form_audit_template_version FOREIGN KEY (template_version_id)
    REFERENCES ipca_form_template_versions (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training Forms - auditable template and form lifecycle events.';

-- Align user-reference columns with the actual users.id type before adding FKs.
SET @users_id_type := (
  SELECT COLUMN_TYPE
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'id'
  LIMIT 1
);
SET @users_id_type := COALESCE(@users_id_type, 'INT');

SET @sql := CONCAT('ALTER TABLE ipca_form_templates MODIFY created_by ', @users_id_type, ' NULL');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := CONCAT('ALTER TABLE ipca_form_template_versions MODIFY created_by ', @users_id_type, ' NULL');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := CONCAT('ALTER TABLE ipca_form_template_versions MODIFY approved_by ', @users_id_type, ' NULL');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := CONCAT('ALTER TABLE ipca_form_audit_log MODIFY actor_user_id ', @users_id_type, ' NULL');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_fk := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_form_templates'
    AND CONSTRAINT_NAME = 'fk_ipca_form_templates_created_by'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(
  @has_fk = 0,
  'ALTER TABLE ipca_form_templates
     ADD CONSTRAINT fk_ipca_form_templates_created_by
     FOREIGN KEY (created_by) REFERENCES users (id)
     ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_fk := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_form_template_versions'
    AND CONSTRAINT_NAME = 'fk_ipca_form_template_versions_created_by'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(
  @has_fk = 0,
  'ALTER TABLE ipca_form_template_versions
     ADD CONSTRAINT fk_ipca_form_template_versions_created_by
     FOREIGN KEY (created_by) REFERENCES users (id)
     ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_fk := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_form_template_versions'
    AND CONSTRAINT_NAME = 'fk_ipca_form_template_versions_approved_by'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(
  @has_fk = 0,
  'ALTER TABLE ipca_form_template_versions
     ADD CONSTRAINT fk_ipca_form_template_versions_approved_by
     FOREIGN KEY (approved_by) REFERENCES users (id)
     ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_fk := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_form_audit_log'
    AND CONSTRAINT_NAME = 'fk_ipca_form_audit_actor'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(
  @has_fk = 0,
  'ALTER TABLE ipca_form_audit_log
     ADD CONSTRAINT fk_ipca_form_audit_actor
     FOREIGN KEY (actor_user_id) REFERENCES users (id)
     ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add current_version_id FK after versions table exists.
SET @has_current_version_fk := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_form_templates'
    AND CONSTRAINT_NAME = 'fk_ipca_form_templates_current_version'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(
  @has_current_version_fk = 0,
  'ALTER TABLE ipca_form_templates
     ADD CONSTRAINT fk_ipca_form_templates_current_version
     FOREIGN KEY (current_version_id)
     REFERENCES ipca_form_template_versions (id) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;