-- IPCA Flight Training Forms - internal packet instances and inbox.
-- Re-run safe: CREATE TABLE IF NOT EXISTS plus guarded FKs where practical.

CREATE TABLE IF NOT EXISTS ipca_form_instances (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template_id           BIGINT UNSIGNED NOT NULL,
  template_version_id   BIGINT UNSIGNED NOT NULL,
  title                 VARCHAR(255) NOT NULL,
  status                VARCHAR(32) NOT NULL DEFAULT 'sent' COMMENT 'draft | sent | in_progress | completed | cancelled',
  student_user_id       INT NULL,
  instructor_user_id    INT NULL,
  created_by            INT NULL,
  sent_at               DATETIME NULL,
  completed_at          DATETIME NULL,
  cancelled_at          DATETIME NULL,
  context_json          JSON NULL,
  auto_fill_snapshot_json JSON NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ipca_form_instances_template (template_id, template_version_id),
  KEY idx_ipca_form_instances_status (status, updated_at),
  KEY idx_ipca_form_instances_student (student_user_id, status),
  KEY idx_ipca_form_instances_instructor (instructor_user_id, status),
  KEY idx_ipca_form_instances_created_by (created_by),
  CONSTRAINT fk_ipca_form_instances_template FOREIGN KEY (template_id)
    REFERENCES ipca_form_templates (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_form_instances_version FOREIGN KEY (template_version_id)
    REFERENCES ipca_form_template_versions (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training Forms - sendable form packets created from template versions.';

CREATE TABLE IF NOT EXISTS ipca_form_instance_recipients (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  form_instance_id      BIGINT UNSIGNED NOT NULL,
  recipient_user_id     INT NOT NULL,
  recipient_role        VARCHAR(64) NOT NULL COMMENT 'student | instructor | admin | examiner | external_party',
  recipient_name        VARCHAR(255) NULL,
  recipient_email       VARCHAR(255) NULL,
  status                VARCHAR(32) NOT NULL DEFAULT 'pending' COMMENT 'pending | opened | completed | declined | cancelled',
  signing_order         INT NOT NULL DEFAULT 10,
  opened_at             DATETIME NULL,
  completed_at          DATETIME NULL,
  declined_at           DATETIME NULL,
  last_accessed_at      DATETIME NULL,
  metadata_json         JSON NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_form_recipients_instance_user_role (form_instance_id, recipient_user_id, recipient_role),
  KEY idx_ipca_form_recipients_user_status (recipient_user_id, status),
  KEY idx_ipca_form_recipients_instance_status (form_instance_id, status),
  CONSTRAINT fk_ipca_form_recipients_instance FOREIGN KEY (form_instance_id)
    REFERENCES ipca_form_instances (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training Forms - internal recipients assigned to fill/sign a packet.';

CREATE TABLE IF NOT EXISTS ipca_form_instance_field_values (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  form_instance_id      BIGINT UNSIGNED NOT NULL,
  template_field_id     BIGINT UNSIGNED NULL,
  field_key             VARCHAR(128) NOT NULL,
  field_type            VARCHAR(32) NOT NULL,
  label                 VARCHAR(255) NULL,
  required              TINYINT(1) NOT NULL DEFAULT 0,
  assigned_role         VARCHAR(64) NOT NULL DEFAULT 'instructor',
  variable_key          VARCHAR(191) NULL,
  value_text            LONGTEXT NULL,
  value_json            JSON NULL,
  source                VARCHAR(32) NOT NULL DEFAULT 'blank' COMMENT 'auto_fill | user | blank',
  filled_by_user_id     INT NULL,
  signed_at             DATETIME NULL,
  signature_json        JSON NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_form_field_values_instance_key (form_instance_id, field_key),
  KEY idx_ipca_form_field_values_instance_role (form_instance_id, assigned_role),
  KEY idx_ipca_form_field_values_template_field (template_field_id),
  KEY idx_ipca_form_field_values_filled_by (filled_by_user_id),
  CONSTRAINT fk_ipca_form_field_values_instance FOREIGN KEY (form_instance_id)
    REFERENCES ipca_form_instances (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_form_field_values_template_field FOREIGN KEY (template_field_id)
    REFERENCES ipca_form_fields (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training Forms - per-instance field values and signature evidence.';

CREATE TABLE IF NOT EXISTS ipca_internal_inbox_items (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recipient_user_id     INT NOT NULL,
  item_type             VARCHAR(32) NOT NULL DEFAULT 'form' COMMENT 'form | document',
  entity_type           VARCHAR(64) NOT NULL,
  entity_id             BIGINT UNSIGNED NOT NULL,
  title                 VARCHAR(255) NOT NULL,
  summary               TEXT NULL,
  status                VARCHAR(32) NOT NULL DEFAULT 'pending' COMMENT 'pending | opened | completed | cancelled',
  action_url            VARCHAR(500) NOT NULL,
  due_at                DATETIME NULL,
  opened_at             DATETIME NULL,
  completed_at          DATETIME NULL,
  metadata_json         JSON NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_internal_inbox_entity_user (entity_type, entity_id, recipient_user_id),
  KEY idx_ipca_internal_inbox_user_status (recipient_user_id, status, updated_at),
  KEY idx_ipca_internal_inbox_type (item_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Internal inbox for user-assigned forms and documents.';

-- Align user-reference columns with users.id before adding user FKs.
SET @users_id_type := (
  SELECT COLUMN_TYPE
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'id'
  LIMIT 1
);
SET @users_id_type := COALESCE(@users_id_type, 'INT');

SET @sql := CONCAT('ALTER TABLE ipca_form_instances MODIFY student_user_id ', @users_id_type, ' NULL');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := CONCAT('ALTER TABLE ipca_form_instances MODIFY instructor_user_id ', @users_id_type, ' NULL');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := CONCAT('ALTER TABLE ipca_form_instances MODIFY created_by ', @users_id_type, ' NULL');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := CONCAT('ALTER TABLE ipca_form_instance_recipients MODIFY recipient_user_id ', @users_id_type, ' NOT NULL');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := CONCAT('ALTER TABLE ipca_form_instance_field_values MODIFY filled_by_user_id ', @users_id_type, ' NULL');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := CONCAT('ALTER TABLE ipca_internal_inbox_items MODIFY recipient_user_id ', @users_id_type, ' NOT NULL');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_fk := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_form_instances'
    AND CONSTRAINT_NAME = 'fk_ipca_form_instances_student_user'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@has_fk = 0,
  'ALTER TABLE ipca_form_instances ADD CONSTRAINT fk_ipca_form_instances_student_user FOREIGN KEY (student_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_fk := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_form_instances'
    AND CONSTRAINT_NAME = 'fk_ipca_form_instances_instructor_user'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@has_fk = 0,
  'ALTER TABLE ipca_form_instances ADD CONSTRAINT fk_ipca_form_instances_instructor_user FOREIGN KEY (instructor_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_fk := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_form_instance_recipients'
    AND CONSTRAINT_NAME = 'fk_ipca_form_recipients_user'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@has_fk = 0,
  'ALTER TABLE ipca_form_instance_recipients ADD CONSTRAINT fk_ipca_form_recipients_user FOREIGN KEY (recipient_user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_fk := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_internal_inbox_items'
    AND CONSTRAINT_NAME = 'fk_ipca_internal_inbox_user'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@has_fk = 0,
  'ALTER TABLE ipca_internal_inbox_items ADD CONSTRAINT fk_ipca_internal_inbox_user FOREIGN KEY (recipient_user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
