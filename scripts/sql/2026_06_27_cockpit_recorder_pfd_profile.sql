-- PFD profile (V-speeds, gauge zones, airspeed arcs) per aircraft device.

SET @table_name := 'ipca_aircraft_devices';

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = @table_name
        AND COLUMN_NAME = 'pfd_profile_json'
    ),
    'SELECT 1',
    'ALTER TABLE ipca_aircraft_devices ADD COLUMN pfd_profile_json LONGTEXT NULL AFTER notes'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
