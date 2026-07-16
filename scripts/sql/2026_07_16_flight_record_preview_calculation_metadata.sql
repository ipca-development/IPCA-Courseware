-- Flight Record preview calculation metadata and verification state.

SET @table_name := 'ipca_operational_calculation_versions';

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = @table_name
        AND COLUMN_NAME = 'verification_status'
    ),
    'SELECT 1',
    'ALTER TABLE ipca_operational_calculation_versions ADD COLUMN verification_status VARCHAR(32) NOT NULL DEFAULT ''system_pending'' AFTER confidence'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = @table_name
        AND COLUMN_NAME = 'exception_json'
    ),
    'SELECT 1',
    'ALTER TABLE ipca_operational_calculation_versions ADD COLUMN exception_json JSON NULL AFTER verification_status'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND INDEX_NAME = 'idx_operational_calculations_verification'
);

SET @sql := IF(
  @index_exists > 0,
  'SELECT 1',
  'CREATE INDEX idx_operational_calculations_verification ON ipca_operational_calculation_versions (verification_status, calculation_type)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
