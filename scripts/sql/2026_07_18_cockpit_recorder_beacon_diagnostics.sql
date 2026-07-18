-- Beacon diagnostics sidecar support for cockpit recorder uploads.
-- Safe to run repeatedly on MySQL/MariaDB.

SET @table_name := 'ipca_cockpit_recordings';

SET @after_clause := (
  SELECT IF(
    COUNT(*) > 0,
    ' AFTER gps_sample_count',
    ''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'gps_sample_count'
);

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    CONCAT('ALTER TABLE ipca_cockpit_recordings ADD COLUMN beacon_storage_path VARCHAR(1024) NULL', @after_clause),
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'beacon_storage_path'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_cockpit_recordings ADD COLUMN beacon_file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER beacon_storage_path',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'beacon_file_size_bytes'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_cockpit_recordings ADD COLUMN beacon_event_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER beacon_file_size_bytes',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'beacon_event_count'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
