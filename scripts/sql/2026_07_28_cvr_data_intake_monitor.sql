-- Links Garmin CSV evidence received through the CVR app to its local workflow Flight Record.
-- Re-run safe.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

SET @has_garmin_csv_files := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_garmin_csv_files'
);
SET @has_workflow_flight_record_uuid := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_garmin_csv_files'
    AND COLUMN_NAME = 'workflow_flight_record_uuid'
);
SET @sql := IF(
  @has_garmin_csv_files > 0 AND @has_workflow_flight_record_uuid = 0,
  'ALTER TABLE ipca_garmin_csv_files ADD COLUMN workflow_flight_record_uuid VARCHAR(96) NULL AFTER session_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_workflow_flight_record_idx := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_garmin_csv_files'
    AND INDEX_NAME = 'idx_ipca_garmin_csv_files_workflow_record'
);
SET @sql := IF(
  @has_garmin_csv_files > 0 AND @has_workflow_flight_record_idx = 0,
  'CREATE INDEX idx_ipca_garmin_csv_files_workflow_record ON ipca_garmin_csv_files (workflow_flight_record_uuid)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
