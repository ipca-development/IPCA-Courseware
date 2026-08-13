-- Allow append-only correction of an incorrect Dispatch departure-fuel baseline.

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_cvr_flight_log_adjustments ADD COLUMN fuel_onboard VARCHAR(64) NULL AFTER starting_tacho',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cvr_flight_log_adjustments'
    AND COLUMN_NAME = 'fuel_onboard'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
