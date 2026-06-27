-- Flight-session/segment metadata for Cockpit Recorder real-world interruptions.
-- Safe to run repeatedly on MySQL/MariaDB.

SET @table_name := 'ipca_cockpit_recordings';

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_cockpit_recordings ADD COLUMN flight_session_uid VARCHAR(96) NULL AFTER recording_uid',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'flight_session_uid'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_cockpit_recordings ADD COLUMN flight_segment_index INT NOT NULL DEFAULT 1 AFTER flight_session_uid',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'flight_segment_index'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_cockpit_recordings ADD COLUMN previous_segment_uid VARCHAR(96) NULL AFTER flight_segment_index',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'previous_segment_uid'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_cockpit_recordings ADD COLUMN is_test_recording TINYINT(1) NOT NULL DEFAULT 0 AFTER previous_segment_uid',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'is_test_recording'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_cockpit_recordings ADD COLUMN source_gap_summary TEXT NULL AFTER is_test_recording',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'source_gap_summary'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'CREATE INDEX idx_ipca_cockpit_recordings_session ON ipca_cockpit_recordings (flight_session_uid, flight_segment_index)',
    'SELECT 1'
  )
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND INDEX_NAME = 'idx_ipca_cockpit_recordings_session'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
