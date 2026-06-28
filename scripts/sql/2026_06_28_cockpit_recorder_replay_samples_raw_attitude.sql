-- IPCA Cockpit Recorder - raw attitude evidence for replay v2 state samples.
-- Re-run safe: adds raw attitude columns when missing.
-- Apply: mysql ... < scripts/sql/2026_06_28_cockpit_recorder_replay_samples_raw_attitude.sql

SET @raw_pitch_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'raw_pitch_deg'
);
SET @sql := IF(@raw_pitch_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN raw_pitch_deg DECIMAL(7,2) NULL AFTER roll_deg',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @raw_roll_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'raw_roll_deg'
);
SET @sql := IF(@raw_roll_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN raw_roll_deg DECIMAL(7,2) NULL AFTER raw_pitch_deg',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @raw_source_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'raw_attitude_source'
);
SET @sql := IF(@raw_source_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN raw_attitude_source VARCHAR(64) NULL AFTER raw_roll_deg',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @raw_quality_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'raw_attitude_quality'
);
SET @sql := IF(@raw_quality_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN raw_attitude_quality VARCHAR(16) NULL AFTER raw_attitude_source',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
