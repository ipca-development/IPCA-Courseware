-- Preliminary debriefs do not require Garmin; Garmin creates an enriched superseding bundle/debrief.

ALTER TABLE ipca_manual_intake_bundles
  MODIFY COLUMN garmin_csv_file_id BIGINT UNSIGNED NULL;

SET @column_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_manual_intake_bundles' AND COLUMN_NAME = 'evidence_stage'
);
SET @sql := IF(@column_exists = 0,
  'ALTER TABLE ipca_manual_intake_bundles ADD COLUMN evidence_stage VARCHAR(32) NOT NULL DEFAULT ''final_enriched'' AFTER status',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_operational_session_leg_reviews' AND COLUMN_NAME = 'evidence_source'
);
SET @sql := IF(@column_exists = 0,
  'ALTER TABLE ipca_operational_session_leg_reviews ADD COLUMN evidence_source VARCHAR(32) NOT NULL DEFAULT ''garmin_csv'' AFTER evidence_sha256',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_structured_debriefs' AND COLUMN_NAME = 'evidence_stage'
);
SET @sql := IF(@column_exists = 0,
  'ALTER TABLE ipca_structured_debriefs ADD COLUMN evidence_stage VARCHAR(32) NOT NULL DEFAULT ''final_enriched'' AFTER status',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @index_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_manual_intake_bundles'
    AND INDEX_NAME = 'idx_ipca_manual_intake_bundle_stage'
);
SET @sql := IF(@index_exists = 0,
  'CREATE INDEX idx_ipca_manual_intake_bundle_stage ON ipca_manual_intake_bundles (workflow_flight_record_uuid, evidence_stage, version_number)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
