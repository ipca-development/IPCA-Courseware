-- Allow append-only correction of an incorrect Dispatch meter baseline.

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_cvr_flight_log_adjustments ADD COLUMN starting_hobbs DECIMAL(12,4) NULL AFTER crew_json',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cvr_flight_log_adjustments'
    AND COLUMN_NAME = 'starting_hobbs'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_cvr_flight_log_adjustments ADD COLUMN starting_tacho DECIMAL(12,4) NULL AFTER starting_hobbs',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cvr_flight_log_adjustments'
    AND COLUMN_NAME = 'starting_tacho'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
