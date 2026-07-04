-- IPCA Cockpit Recorder - replay v2 altimeter/VSI instrument fields.
-- Re-run safe: adds Garmin instrument overlay columns when missing.
-- Apply: mysql ... < scripts/sql/2026_07_03_cockpit_recorder_replay_samples_altimeter_fields.sql

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'estimated_indicated_altitude_ft'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN estimated_indicated_altitude_ft DECIMAL(10,1) NULL AFTER baro_altitude_ft',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'estimated_vertical_speed_fpm'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN estimated_vertical_speed_fpm DECIMAL(10,1) NULL AFTER vertical_speed_fpm',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'altimeter_setting_inhg'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN altimeter_setting_inhg DECIMAL(6,2) NULL AFTER estimated_indicated_altitude_ft',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'heading_bug_deg'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN heading_bug_deg DECIMAL(7,2) NULL AFTER altimeter_setting_inhg',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'nav_course_deg'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN nav_course_deg DECIMAL(7,2) NULL AFTER heading_bug_deg',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'nav_bearing_deg'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN nav_bearing_deg DECIMAL(7,2) NULL AFTER nav_course_deg',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'nav_xtk_nm'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN nav_xtk_nm DECIMAL(8,3) NULL AFTER nav_bearing_deg',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'hcdi'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN hcdi DECIMAL(8,3) NULL AFTER nav_xtk_nm',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'altitude_bug_ft'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN altitude_bug_ft DECIMAL(10,1) NULL AFTER hcdi',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'oat_c'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN oat_c DECIMAL(5,1) NULL AFTER altitude_bug_ft',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'isa_deviation_c'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN isa_deviation_c DECIMAL(5,1) NULL AFTER oat_c',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'decision_altitude_ft'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN decision_altitude_ft DECIMAL(10,1) NULL AFTER isa_deviation_c',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'da_ft'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN da_ft DECIMAL(10,1) NULL AFTER decision_altitude_ft',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'minimums_ft'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN minimums_ft DECIMAL(10,1) NULL AFTER da_ft',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
