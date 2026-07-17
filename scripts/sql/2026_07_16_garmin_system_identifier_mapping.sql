SET @table_name := 'ipca_garmin_csv_files';

SET @has_system_identifier := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'system_identifier'
);
SET @sql := IF(
  @has_system_identifier = 0,
  'ALTER TABLE ipca_garmin_csv_files ADD COLUMN system_identifier VARCHAR(128) NOT NULL DEFAULT '''' AFTER product',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_airframe_hours_start := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'airframe_hours_start'
);
SET @sql := IF(
  @has_airframe_hours_start = 0,
  'ALTER TABLE ipca_garmin_csv_files ADD COLUMN airframe_hours_start DECIMAL(12,4) NULL AFTER system_identifier',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_engine_hours_start := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'engine_hours_start'
);
SET @sql := IF(
  @has_engine_hours_start = 0,
  'ALTER TABLE ipca_garmin_csv_files ADD COLUMN engine_hours_start DECIMAL(12,4) NULL AFTER airframe_hours_start',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ipca_garmin_system_identifier_mappings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  system_identifier VARCHAR(128) NOT NULL,
  aircraft_id BIGINT UNSIGNED NULL,
  tail_number VARCHAR(32) NOT NULL DEFAULT '',
  source VARCHAR(64) NOT NULL DEFAULT 'admin',
  confidence VARCHAR(32) NOT NULL DEFAULT 'manual',
  effective_from_utc DATETIME(3) NULL,
  effective_to_utc DATETIME(3) NULL,
  notes VARCHAR(512) NOT NULL DEFAULT '',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_garmin_system_identifier_active (system_identifier, effective_to_utc),
  KEY idx_garmin_system_identifier_tail (tail_number),
  KEY idx_garmin_system_identifier_aircraft (aircraft_id),
  CONSTRAINT fk_garmin_system_identifier_aircraft
    FOREIGN KEY (aircraft_id) REFERENCES ipca_aircraft_devices(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Manual/audited mapping from Garmin G3X system identifiers to aircraft tails.';
