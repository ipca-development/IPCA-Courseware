-- IPCA Cockpit Recorder - G3X-first replay v2 fields.
-- Re-run safe: adds Garmin altitude, speed, and engine overlay columns when missing.
-- Apply: mysql ... < scripts/sql/2026_07_02_cockpit_recorder_replay_samples_g3x_first.sql

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'gps_altitude_ft'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN gps_altitude_ft DECIMAL(10,1) NULL AFTER altitude_ft',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'baro_altitude_ft'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN baro_altitude_ft DECIMAL(10,1) NULL AFTER gps_altitude_ft',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'ias_kt'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN ias_kt DECIMAL(10,2) NULL AFTER ground_speed_kt',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'tas_kt'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN tas_kt DECIMAL(10,2) NULL AFTER ias_kt',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'rpm'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN rpm DECIMAL(10,1) NULL AFTER tas_kt',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'manifold_pressure_inhg'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN manifold_pressure_inhg DECIMAL(10,2) NULL AFTER rpm',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'fuel_flow_gph'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN fuel_flow_gph DECIMAL(10,2) NULL AFTER manifold_pressure_inhg',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'oil_pressure_psi'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN oil_pressure_psi DECIMAL(10,2) NULL AFTER fuel_flow_gph',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'oil_temp_f'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN oil_temp_f DECIMAL(10,2) NULL AFTER oil_pressure_psi',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'fuel_pressure_psi'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN fuel_pressure_psi DECIMAL(10,2) NULL AFTER oil_temp_f',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'fuel_qty_gal'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN fuel_qty_gal DECIMAL(10,2) NULL AFTER fuel_pressure_psi',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'volts'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN volts DECIMAL(10,2) NULL AFTER fuel_qty_gal',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'amps'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN amps DECIMAL(10,2) NULL AFTER volts',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'egt1_f'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN egt1_f DECIMAL(10,2) NULL AFTER amps',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'egt2_f'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN egt2_f DECIMAL(10,2) NULL AFTER egt1_f',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
