-- Optional intake metadata for Cockpit Audio rows shown in Master Logbook.

SET @schema := DATABASE();

SET @has_intake_source := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'ipca_cockpit_recordings' AND COLUMN_NAME = 'intake_source'
);
SET @sql_intake_source := IF(
  @has_intake_source = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN intake_source VARCHAR(32) NULL AFTER input_device',
  'SELECT 1'
);
PREPARE stmt FROM @sql_intake_source;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_intake_mission := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'ipca_cockpit_recordings' AND COLUMN_NAME = 'intake_mission_code'
);
SET @sql_intake_mission := IF(
  @has_intake_mission = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN intake_mission_code VARCHAR(64) NULL AFTER intake_source',
  'SELECT 1'
);
PREPARE stmt FROM @sql_intake_mission;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_intake_crew := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'ipca_cockpit_recordings' AND COLUMN_NAME = 'intake_crew_json'
);
SET @sql_intake_crew := IF(
  @has_intake_crew = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN intake_crew_json JSON NULL AFTER intake_mission_code',
  'SELECT 1'
);
PREPARE stmt FROM @sql_intake_crew;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
