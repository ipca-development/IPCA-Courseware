-- FAA NASR regional airport/runway catalog support (CA, AZ, NV).

SET @column_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_airports' AND COLUMN_NAME = 'faa_identifier'
);
SET @sql := IF(@column_exists = 0,
  'ALTER TABLE ipca_airports ADD COLUMN faa_identifier VARCHAR(8) NULL AFTER icao_identifier',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_airports' AND COLUMN_NAME = 'state_code'
);
SET @sql := IF(@column_exists = 0,
  'ALTER TABLE ipca_airports ADD COLUMN state_code CHAR(2) NULL AFTER region',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_airports' AND COLUMN_NAME = 'facility_use'
);
SET @sql := IF(@column_exists = 0,
  'ALTER TABLE ipca_airports ADD COLUMN facility_use VARCHAR(8) NULL AFTER is_towered',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_airport_runways' AND COLUMN_NAME = 'width_ft'
);
SET @sql := IF(@column_exists = 0,
  'ALTER TABLE ipca_airport_runways ADD COLUMN width_ft INT NULL AFTER length_ft',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ipca_airport_runway_ends (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  runway_id                  BIGINT UNSIGNED NOT NULL,
  runway_end_identifier      VARCHAR(8) NOT NULL,
  latitude_deg               DECIMAL(10,7) NOT NULL,
  longitude_deg              DECIMAL(10,7) NOT NULL,
  elevation_ft               INT NULL,
  true_heading_deg           DECIMAL(6,2) NULL,
  source_json                JSON NULL,
  created_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_airport_runway_end (runway_id, runway_end_identifier),
  KEY idx_ipca_airport_runway_ends_position (latitude_deg, longitude_deg),
  CONSTRAINT fk_ipca_airport_runway_ends_runway
    FOREIGN KEY (runway_id) REFERENCES ipca_airport_runways (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='FAA NASR runway-end positions for offline and server flight-event reconciliation.';
