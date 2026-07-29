-- Widen calculation version labels so long service identifiers (e.g. tacho_rpm_threshold_cumulative_v2) persist.

SET @table_name := 'ipca_operational_calculation_versions';

SET @needs_widen := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'version'
    AND CHARACTER_MAXIMUM_LENGTH < 64
);

SET @sql := IF(
  @needs_widen > 0,
  'ALTER TABLE ipca_operational_calculation_versions MODIFY COLUMN version VARCHAR(64) NOT NULL DEFAULT ''phase1-v1''',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
