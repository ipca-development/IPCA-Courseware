-- IPCA Cockpit Recorder - explicit TRUE heading/track references for replay v2.
-- Re-run safe: adds heading/track/reference columns when missing.
-- Apply: mysql ... < scripts/sql/2026_06_28_cockpit_recorder_replay_samples_heading_track_references.sql

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'heading_deg_true'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN heading_deg_true DECIMAL(7,2) NULL AFTER heading_deg', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'heading_deg_magnetic'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN heading_deg_magnetic DECIMAL(7,2) NULL AFTER heading_deg_true', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'track_deg_true'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN track_deg_true DECIMAL(7,2) NULL AFTER heading_deg_magnetic', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'wind_direction_deg_true'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN wind_direction_deg_true DECIMAL(7,2) NULL AFTER track_deg_true', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'magnetic_variation_deg'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN magnetic_variation_deg DECIMAL(6,2) NULL AFTER wind_direction_deg_true', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'magnetic_variation_source'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN magnetic_variation_source VARCHAR(64) NULL AFTER magnetic_variation_deg', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'compass_deviation_deg'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN compass_deviation_deg DECIMAL(6,2) NULL AFTER magnetic_variation_source', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'compass_deviation_source'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN compass_deviation_source VARCHAR(64) NULL AFTER compass_deviation_deg', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'heading_reference'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN heading_reference VARCHAR(16) NULL AFTER compass_deviation_source', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'track_reference'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN track_reference VARCHAR(16) NULL AFTER heading_reference', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'heading_source'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN heading_source VARCHAR(64) NULL AFTER track_reference', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'track_source'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN track_source VARCHAR(64) NULL AFTER heading_source', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'heading_owner'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN heading_owner VARCHAR(64) NULL AFTER heading_source', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'heading_quality'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN heading_quality VARCHAR(16) NULL AFTER heading_owner', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'crab_angle_deg'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN crab_angle_deg DECIMAL(7,2) NULL AFTER track_source', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
