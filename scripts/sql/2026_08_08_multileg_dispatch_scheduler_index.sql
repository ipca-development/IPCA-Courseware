-- Multi-leg reservations share one scheduler_record_id across every hop.
-- A unique index on scheduler_record_id alone blocked legs 2+ from syncing
-- after leg 1 inserted. Keep lookup speed, enforce uniqueness per flight record.
SET @table_name := 'ipca_cvr_dispatches';

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND INDEX_NAME = 'uk_ipca_cvr_dispatches_scheduler'
);
SET @sql := IF(
  @idx_exists > 0,
  'ALTER TABLE ipca_cvr_dispatches DROP INDEX uk_ipca_cvr_dispatches_scheduler',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND INDEX_NAME = 'idx_ipca_cvr_dispatches_scheduler'
);
SET @sql := IF(
  @idx_exists = 0,
  'CREATE INDEX idx_ipca_cvr_dispatches_scheduler ON ipca_cvr_dispatches (scheduler_record_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND INDEX_NAME = 'uk_ipca_cvr_dispatches_scheduler_flight'
);
SET @sql := IF(
  @idx_exists = 0,
  'CREATE UNIQUE INDEX uk_ipca_cvr_dispatches_scheduler_flight ON ipca_cvr_dispatches (scheduler_record_id, workflow_flight_record_uuid)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
