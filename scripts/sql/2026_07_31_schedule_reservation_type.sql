-- Reservation categories for the IPCA flight schedule.
-- Safe to run after 2026_07_31_scheduled_dispatch_start_end.sql.

SET @table_name := 'ipca_flight_schedule_slots';
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'reservation_type'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE ipca_flight_schedule_slots ADD COLUMN reservation_type VARCHAR(32) NOT NULL DEFAULT ''flight_training'' AFTER organization_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
