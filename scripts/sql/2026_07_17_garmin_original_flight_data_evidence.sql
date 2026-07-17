SET @table_name := 'ipca_garmin_csv_files';

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'flight_data_log_uuid'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_garmin_csv_files ADD COLUMN flight_data_log_uuid CHAR(36) NULL AFTER provider_name', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'garmin_entry_uuid'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_garmin_csv_files ADD COLUMN garmin_entry_uuid CHAR(36) NULL AFTER flight_data_log_uuid', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'canonical_track_uuid'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_garmin_csv_files ADD COLUMN canonical_track_uuid CHAR(36) NULL AFTER garmin_entry_uuid', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'source_type'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_garmin_csv_files ADD COLUMN source_type VARCHAR(64) NOT NULL DEFAULT ''UNKNOWN'' AFTER canonical_track_uuid', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'parser_version'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_garmin_csv_files ADD COLUMN parser_version VARCHAR(64) NOT NULL DEFAULT '''' AFTER source_type', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'raw_header'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_garmin_csv_files ADD COLUMN raw_header TEXT NULL AFTER parser_version', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'parsed_header_json'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_garmin_csv_files ADD COLUMN parsed_header_json JSON NULL AFTER raw_header', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'flightstream_header'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_garmin_csv_files ADD COLUMN flightstream_header VARCHAR(255) NOT NULL DEFAULT '''' AFTER parsed_header_json', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_garmin_csv_files' AND INDEX_NAME = 'uk_ipca_garmin_csv_files_source_sha'
);
SET @sql := IF(@idx_exists = 0, 'CREATE UNIQUE INDEX uk_ipca_garmin_csv_files_source_sha ON ipca_garmin_csv_files (provider_name, flight_data_log_uuid, sha256)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_garmin_csv_files' AND INDEX_NAME = 'uk_ipca_garmin_csv_files_sha256'
);
SET @sql := IF(@idx_exists > 0, 'ALTER TABLE ipca_garmin_csv_files DROP INDEX uk_ipca_garmin_csv_files_sha256', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_garmin_csv_files' AND INDEX_NAME = 'idx_ipca_garmin_csv_files_sha256'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_ipca_garmin_csv_files_sha256 ON ipca_garmin_csv_files (sha256)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ipca_garmin_flight_data_track_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider_name VARCHAR(64) NOT NULL,
  garmin_entry_uuid CHAR(36) NOT NULL,
  canonical_track_uuid CHAR(36) NOT NULL,
  flight_data_log_uuid CHAR(36) NOT NULL,
  garmin_csv_file_id BIGINT UNSIGNED NOT NULL,
  system_identifier VARCHAR(128) NOT NULL DEFAULT '',
  first_valid_sample_utc DATETIME(3) NULL,
  last_valid_sample_utc DATETIME(3) NULL,
  source_group_key VARCHAR(160) NOT NULL DEFAULT '',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_fdtl_source (provider_name, flight_data_log_uuid, garmin_csv_file_id),
  KEY idx_ipca_garmin_fdtl_track (provider_name, garmin_entry_uuid, canonical_track_uuid),
  KEY idx_ipca_garmin_fdtl_csv (garmin_csv_file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Links original Garmin Flight Data evidence to normalized Track JSON artifacts.';
