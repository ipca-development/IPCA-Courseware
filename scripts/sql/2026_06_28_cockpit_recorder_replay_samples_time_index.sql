-- IPCA Cockpit Recorder - replay sample time index for fast playback lookups.
-- Re-run safe: adds UNIQUE(recording_id, time_s) when missing.
-- Apply: mysql ... < scripts/sql/2026_06_28_cockpit_recorder_replay_samples_time_index.sql

SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_replay_samples'
    AND INDEX_NAME = 'uk_ipca_cockpit_replay_samples_recording_time'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE ipca_cockpit_replay_samples ADD UNIQUE KEY uk_ipca_cockpit_replay_samples_recording_time (recording_id, time_s)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
