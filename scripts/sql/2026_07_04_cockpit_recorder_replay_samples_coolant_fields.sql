-- Persist Garmin G3X coolant/CHT probe values in replay v2 samples.

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'coolant1_f'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN coolant1_f DECIMAL(10,2) NULL AFTER egt2_f',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND COLUMN_NAME = 'coolant2_f'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN coolant2_f DECIMAL(10,2) NULL AFTER coolant1_f',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
