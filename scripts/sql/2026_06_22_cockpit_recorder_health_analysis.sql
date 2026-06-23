-- IPCA Cockpit Recorder POC - recording health analysis summary.
-- Re-run safe: guarded ALTER statements for optional health analysis columns.
-- Apply: mysql ... < scripts/sql/2026_06_22_cockpit_recorder_health_analysis.sql

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_recordings'
    AND COLUMN_NAME = 'health_summary_json'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN health_summary_json LONGTEXT NULL AFTER updated_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_recordings'
    AND COLUMN_NAME = 'health_warning_count'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN health_warning_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER health_summary_json',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_recordings'
    AND COLUMN_NAME = 'health_analyzed_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN health_analyzed_at DATETIME NULL AFTER health_warning_count',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
