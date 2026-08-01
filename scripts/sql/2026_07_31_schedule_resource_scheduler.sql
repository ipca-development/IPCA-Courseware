-- Resource scheduler extensions for aircraft, staff and cohort timeline views.
SET @schedule_table := 'ipca_flight_schedule_slots';

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_flight_schedule_slots ADD COLUMN cohort_id INT NULL AFTER mission_id',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @schedule_table
    AND COLUMN_NAME = 'cohort_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_flight_schedule_slots ADD KEY idx_ipca_flight_schedule_slots_cohort (cohort_id, scheduled_start_time)',
    'SELECT 1'
  )
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @schedule_table
    AND INDEX_NAME = 'idx_ipca_flight_schedule_slots_cohort'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_flight_schedule_slots ADD KEY idx_ipca_flight_schedule_slots_aircraft_time (aircraft_id, scheduled_start_time, scheduled_end_time, status)',
    'SELECT 1'
  )
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @schedule_table
    AND INDEX_NAME = 'idx_ipca_flight_schedule_slots_aircraft_time'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
