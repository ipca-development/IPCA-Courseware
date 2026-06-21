-- IPCA Cockpit Recorder POC - GPS JSON upload metadata.
-- Re-run safe: guarded ALTER statements for optional GPS payloads.
-- Apply: mysql ... < scripts/sql/2026_06_20_cockpit_recorder_gps_upload.sql

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_recordings'
    AND COLUMN_NAME = 'gps_storage_path'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN gps_storage_path VARCHAR(1024) NULL AFTER ahrs_sample_count',
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
    AND COLUMN_NAME = 'gps_file_size_bytes'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN gps_file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER gps_storage_path',
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
    AND COLUMN_NAME = 'gps_sample_count'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN gps_sample_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER gps_file_size_bytes',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
