-- IPCA Cockpit Recorder - derived replay altitude, vertical speed, and slip/skid fields.
-- Re-run safe: guarded column additions only.
-- Apply: mysql ... < scripts/sql/2026_06_24_cockpit_recorder_derived_replay_values.sql

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_recordings'
    AND COLUMN_NAME = 'altimeter_setting_inhg'
);
SET @after_clause := IF((
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_recordings'
    AND COLUMN_NAME = 'aircraft_adsb_hex'
) > 0, ' AFTER aircraft_adsb_hex', '');
SET @sql := IF(@col_exists = 0,
  CONCAT('ALTER TABLE ipca_cockpit_recordings ADD COLUMN altimeter_setting_inhg DECIMAL(5,2) NULL', @after_clause),
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_recordings'
    AND COLUMN_NAME = 'altimeter_setting_source'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN altimeter_setting_source VARCHAR(64) NOT NULL DEFAULT ''unavailable'' AFTER altimeter_setting_inhg',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_adsb_ownship_samples'
    AND COLUMN_NAME = 'altimeter_setting_inhg'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_adsb_ownship_samples ADD COLUMN altimeter_setting_inhg DECIMAL(5,2) NULL AFTER on_ground',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_flight_samples'
    AND COLUMN_NAME = 'adsb_baro_altitude_ft'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_flight_samples ADD COLUMN adsb_baro_altitude_ft DECIMAL(10,1) NULL AFTER vertical_speed_fpm',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_flight_samples'
    AND COLUMN_NAME = 'adsb_vertical_speed_fpm'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_flight_samples ADD COLUMN adsb_vertical_speed_fpm DECIMAL(10,1) NULL AFTER adsb_baro_altitude_ft',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_flight_samples'
    AND COLUMN_NAME = 'estimated_baro_altitude_ft'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_flight_samples ADD COLUMN estimated_baro_altitude_ft DECIMAL(10,1) NULL AFTER adsb_vertical_speed_fpm',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_flight_samples'
    AND COLUMN_NAME = 'estimated_vertical_speed_fpm'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_flight_samples ADD COLUMN estimated_vertical_speed_fpm DECIMAL(10,1) NULL AFTER estimated_baro_altitude_ft',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_flight_samples'
    AND COLUMN_NAME = 'altimeter_setting_inhg'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_flight_samples ADD COLUMN altimeter_setting_inhg DECIMAL(5,2) NULL AFTER estimated_vertical_speed_fpm',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_flight_samples'
    AND COLUMN_NAME = 'altimeter_setting_source'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_flight_samples ADD COLUMN altimeter_setting_source VARCHAR(64) NOT NULL DEFAULT ''unavailable'' AFTER altimeter_setting_inhg',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_flight_samples'
    AND COLUMN_NAME = 'altitude_source'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_flight_samples ADD COLUMN altitude_source VARCHAR(32) NOT NULL DEFAULT ''gps'' AFTER altimeter_setting_source',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_flight_samples'
    AND COLUMN_NAME = 'altitude_quality'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_flight_samples ADD COLUMN altitude_quality VARCHAR(32) NOT NULL DEFAULT ''unavailable'' AFTER altitude_source',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_flight_samples'
    AND COLUMN_NAME = 'vertical_speed_source'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_flight_samples ADD COLUMN vertical_speed_source VARCHAR(32) NOT NULL DEFAULT ''unavailable'' AFTER altitude_quality',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_flight_samples'
    AND COLUMN_NAME = 'vertical_speed_quality'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_flight_samples ADD COLUMN vertical_speed_quality VARCHAR(32) NOT NULL DEFAULT ''unavailable'' AFTER vertical_speed_source',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_flight_samples'
    AND COLUMN_NAME = 'estimated_slip_skid_g'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_flight_samples ADD COLUMN estimated_slip_skid_g DECIMAL(8,3) NULL AFTER vertical_speed_quality',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_flight_samples'
    AND COLUMN_NAME = 'estimated_slip_skid_quality'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_flight_samples ADD COLUMN estimated_slip_skid_quality VARCHAR(32) NOT NULL DEFAULT ''unavailable'' AFTER estimated_slip_skid_g',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_flight_samples'
    AND COLUMN_NAME = 'estimated_slip_skid_source'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_flight_samples ADD COLUMN estimated_slip_skid_source VARCHAR(64) NOT NULL DEFAULT ''unavailable'' AFTER estimated_slip_skid_quality',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
