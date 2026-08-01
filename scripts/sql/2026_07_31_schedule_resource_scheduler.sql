-- Horizontal resource scheduler support.
-- Adds an optional direct cohort assignment for group reservations.

SET @table_name := 'ipca_flight_schedule_slots';
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'cohort_id'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE ipca_flight_schedule_slots ADD COLUMN cohort_id INT NULL AFTER mission_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND INDEX_NAME = 'idx_ipca_flight_schedule_slots_aircraft_time'
);
SET @sql := IF(
  @index_exists = 0,
  'ALTER TABLE ipca_flight_schedule_slots ADD KEY idx_ipca_flight_schedule_slots_aircraft_time (aircraft_id, scheduled_start_time, scheduled_end_time, status)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND INDEX_NAME = 'idx_ipca_flight_schedule_slots_cohort'
);
SET @sql := IF(
  @index_exists = 0,
  'ALTER TABLE ipca_flight_schedule_slots ADD KEY idx_ipca_flight_schedule_slots_cohort (cohort_id, scheduled_start_time)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
