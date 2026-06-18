-- IPCA Admin Logbook E-GLE direct sync foundation.
-- Phase 1: temporary/session credentials in PHP, read-only E-GLE access,
-- user mappings, sync run audit, external IDs, and change detection hashes.

SET @db_name := DATABASE();

CREATE TABLE IF NOT EXISTS ipca_egle_user_mappings (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ipca_user_id           INT NOT NULL,
  egle_userid            VARCHAR(64) NOT NULL,
  egle_email             VARCHAR(255) NULL,
  egle_full_name         VARCHAR(255) NULL,
  mapping_type           VARCHAR(32) NOT NULL DEFAULT 'manual' COMMENT 'suggested | manual | confirmed',
  confidence_score       DECIMAL(5,2) NULL,
  confirmed_by_user_id   INT NULL,
  confirmed_at           DATETIME NULL,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_egle_mapping_ipca_user (ipca_user_id),
  UNIQUE KEY uk_ipca_egle_mapping_egle_userid (egle_userid),
  KEY idx_ipca_egle_mapping_email (egle_email),
  KEY idx_ipca_egle_mapping_confirmed (confirmed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training - confirmed IPCA user to E-GLE userid mappings.';

CREATE TABLE IF NOT EXISTS ipca_egle_sync_runs (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_type              VARCHAR(32) NOT NULL COMMENT 'student | all_students | connection_test | review',
  status                VARCHAR(32) NOT NULL DEFAULT 'started' COMMENT 'started | completed | failed',
  ipca_user_id           INT NULL,
  egle_userid            VARCHAR(64) NULL,
  started_by_user_id     INT NULL,
  started_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at           DATETIME NULL,
  imported_count         INT NOT NULL DEFAULT 0,
  changed_count          INT NOT NULL DEFAULT 0,
  unchanged_count        INT NOT NULL DEFAULT 0,
  pending_review_count   INT NOT NULL DEFAULT 0,
  error_message          TEXT NULL,
  metadata_json          JSON NULL,
  KEY idx_ipca_egle_sync_runs_user_time (ipca_user_id, started_at),
  KEY idx_ipca_egle_sync_runs_status_time (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training - E-GLE sync run history.';

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_admin_logbook_entries ADD COLUMN external_system VARCHAR(32) NULL AFTER source_page_id',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'ipca_admin_logbook_entries'
    AND COLUMN_NAME = 'external_system'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_admin_logbook_entries ADD COLUMN external_id VARCHAR(128) NULL AFTER external_system',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'ipca_admin_logbook_entries'
    AND COLUMN_NAME = 'external_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_admin_logbook_entries ADD COLUMN source_hash CHAR(64) NULL AFTER source_json',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'ipca_admin_logbook_entries'
    AND COLUMN_NAME = 'source_hash'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_admin_logbook_entries ADD COLUMN normalized_hash CHAR(64) NULL AFTER source_hash',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'ipca_admin_logbook_entries'
    AND COLUMN_NAME = 'normalized_hash'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_admin_logbook_entries ADD COLUMN sync_status VARCHAR(32) NULL AFTER normalized_hash',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'ipca_admin_logbook_entries'
    AND COLUMN_NAME = 'sync_status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'CREATE INDEX idx_ipca_logbook_entries_external ON ipca_admin_logbook_entries (external_system, external_id)',
    'SELECT 1'
  )
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'ipca_admin_logbook_entries'
    AND INDEX_NAME = 'idx_ipca_logbook_entries_external'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'CREATE INDEX idx_ipca_logbook_entries_sync_status ON ipca_admin_logbook_entries (logbook_id, sync_status, review_status)',
    'SELECT 1'
  )
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'ipca_admin_logbook_entries'
    AND INDEX_NAME = 'idx_ipca_logbook_entries_sync_status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
