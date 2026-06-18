-- IPCA Admin Logbook CSV import support.
-- Adds candidate/trusted status fields, source preservation, import profile,
-- and simulator/FNPT time required for EGLE_LEGACY_LOGBOOK_V1.

SET @db_name := DATABASE();

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_admin_logbook_entries ADD COLUMN import_profile VARCHAR(64) NULL AFTER source_page_id',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'ipca_admin_logbook_entries'
    AND COLUMN_NAME = 'import_profile'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_admin_logbook_entries ADD COLUMN source_json JSON NULL AFTER import_profile',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'ipca_admin_logbook_entries'
    AND COLUMN_NAME = 'source_json'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_admin_logbook_entries ADD COLUMN fnpt_simulator_time DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER basic_instrument_flying_time',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'ipca_admin_logbook_entries'
    AND COLUMN_NAME = 'fnpt_simulator_time'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_admin_logbook_entries ADD COLUMN accepted_at DATETIME NULL AFTER review_status',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'ipca_admin_logbook_entries'
    AND COLUMN_NAME = 'accepted_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_admin_logbook_entries ADD COLUMN accepted_by INT NULL AFTER accepted_at',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'ipca_admin_logbook_entries'
    AND COLUMN_NAME = 'accepted_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'CREATE INDEX idx_ipca_logbook_entries_import_profile ON ipca_admin_logbook_entries (logbook_id, import_profile)',
    'SELECT 1'
  )
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'ipca_admin_logbook_entries'
    AND INDEX_NAME = 'idx_ipca_logbook_entries_import_profile'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
