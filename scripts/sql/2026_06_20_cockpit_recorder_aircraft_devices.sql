-- Cockpit Recorder aircraft/device source of truth.
-- Re-run safe: creates the aircraft/device table and adds optional recording snapshot columns.

CREATE TABLE IF NOT EXISTS ipca_aircraft_devices (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  registration    VARCHAR(32) NOT NULL,
  display_name    VARCHAR(128) NOT NULL DEFAULT '',
  aircraft_type   VARCHAR(64) NOT NULL DEFAULT '',
  adsb_hex        CHAR(6) NOT NULL DEFAULT '',
  home_airport    VARCHAR(8) NOT NULL DEFAULT '',
  notes           TEXT NULL,
  active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_aircraft_devices_registration (registration),
  KEY idx_ipca_aircraft_devices_adsb_hex (adsb_hex),
  KEY idx_ipca_aircraft_devices_active (active, registration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Aircraft/device registry shared by scheduling and Cockpit Recorder.';

SET @has_aircraft_id := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_recordings'
    AND COLUMN_NAME = 'aircraft_id'
);
SET @sql := IF(
  @has_aircraft_id = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN aircraft_id BIGINT UNSIGNED NULL AFTER input_device',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_aircraft_registration := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_recordings'
    AND COLUMN_NAME = 'aircraft_registration'
);
SET @sql := IF(
  @has_aircraft_registration = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN aircraft_registration VARCHAR(32) NOT NULL DEFAULT '''' AFTER aircraft_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_aircraft_display_name := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_recordings'
    AND COLUMN_NAME = 'aircraft_display_name'
);
SET @sql := IF(
  @has_aircraft_display_name = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN aircraft_display_name VARCHAR(128) NOT NULL DEFAULT '''' AFTER aircraft_registration',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_aircraft_type := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_recordings'
    AND COLUMN_NAME = 'aircraft_type'
);
SET @sql := IF(
  @has_aircraft_type = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN aircraft_type VARCHAR(64) NOT NULL DEFAULT '''' AFTER aircraft_display_name',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_aircraft_adsb_hex := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_recordings'
    AND COLUMN_NAME = 'aircraft_adsb_hex'
);
SET @sql := IF(
  @has_aircraft_adsb_hex = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN aircraft_adsb_hex CHAR(6) NOT NULL DEFAULT '''' AFTER aircraft_type',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
