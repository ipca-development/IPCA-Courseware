-- IPCA Cockpit Recorder - replay v2 quality axis source and reason fields.
-- Re-run safe: adds columns when missing.
-- Apply: mysql ... < scripts/sql/2026_06_28_cockpit_recorder_replay_samples_quality_axes.sql

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'position_source'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN position_source VARCHAR(64) NULL AFTER position_quality', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'altitude_source'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN altitude_source VARCHAR(64) NULL AFTER altitude_quality', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'track_quality'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN track_quality VARCHAR(16) NULL AFTER track_source', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'speed_source'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN speed_source VARCHAR(64) NULL AFTER track_quality', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'speed_quality'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN speed_quality VARCHAR(16) NULL AFTER speed_source', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'position_quality_reason'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN position_quality_reason VARCHAR(128) NULL AFTER position_source', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'altitude_quality_reason'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN altitude_quality_reason VARCHAR(128) NULL AFTER altitude_source', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'attitude_quality_reason'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN attitude_quality_reason VARCHAR(128) NULL AFTER attitude_quality', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'heading_quality_reason'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN heading_quality_reason VARCHAR(128) NULL AFTER heading_quality', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'track_quality_reason'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN track_quality_reason VARCHAR(128) NULL AFTER track_quality', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples' AND COLUMN_NAME = 'speed_quality_reason'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN speed_quality_reason VARCHAR(128) NULL AFTER speed_quality', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
