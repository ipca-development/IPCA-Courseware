-- Optional aircraft burn rate for Check-In fuel estimate (1.0 Hobbs ≈ N USG).
SET @table_name := 'ipca_aircraft_operational_config_versions';
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'fuel_burn_usg_per_hobbs_hour'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE ipca_aircraft_operational_config_versions ADD COLUMN fuel_burn_usg_per_hobbs_hour DECIMAL(8,3) NOT NULL DEFAULT 3.200 AFTER fuel_unit',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
