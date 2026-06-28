-- IPCA Cockpit Recorder - reconstruction job progress fields.
-- Re-run safe: adds progress_stage and progress_message when missing.
-- Apply: mysql ... < scripts/sql/2026_06_29_cockpit_reconstruction_job_progress.sql

SET @stage_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_reconstruction_jobs'
    AND COLUMN_NAME = 'progress_stage'
);
SET @sql := IF(@stage_exists = 0,
  'ALTER TABLE ipca_cockpit_reconstruction_jobs ADD COLUMN progress_stage VARCHAR(64) NULL AFTER progress',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @message_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_reconstruction_jobs'
    AND COLUMN_NAME = 'progress_message'
);
SET @sql := IF(@message_exists = 0,
  'ALTER TABLE ipca_cockpit_reconstruction_jobs ADD COLUMN progress_message VARCHAR(512) NULL AFTER progress_stage',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
