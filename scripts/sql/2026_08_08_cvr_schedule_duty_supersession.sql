-- Offline-safe Duty Assignment replacement synchronization.
-- Additive and idempotent for MySQL 8.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

SET @table_name := 'ipca_flight_schedule_slots';
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'supersedes_scheduler_record_id'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE ipca_flight_schedule_slots ADD COLUMN supersedes_scheduler_record_id CHAR(36) NULL AFTER scheduler_record_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'superseded_by_scheduler_record_id'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE ipca_flight_schedule_slots ADD COLUMN superseded_by_scheduler_record_id CHAR(36) NULL AFTER supersedes_scheduler_record_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name
    AND INDEX_NAME = 'idx_ipca_schedule_supersedes'
);
SET @sql := IF(
  @idx_exists = 0,
  'CREATE INDEX idx_ipca_schedule_supersedes ON ipca_flight_schedule_slots (supersedes_scheduler_record_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name
    AND INDEX_NAME = 'idx_ipca_schedule_superseded_by'
);
SET @sql := IF(
  @idx_exists = 0,
  'CREATE INDEX idx_ipca_schedule_superseded_by ON ipca_flight_schedule_slots (superseded_by_scheduler_record_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- SUPERSEDED is an explicit audit state, distinct from an ordinary cancellation.
SET @constraint_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_operational_reservations'
    AND CONSTRAINT_NAME = 'chk_op_reservations_status'
);
SET @sql := IF(
  @constraint_exists > 0,
  'ALTER TABLE ipca_operational_reservations DROP CHECK chk_op_reservations_status',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE ipca_operational_reservations
  ADD CONSTRAINT chk_op_reservations_status
  CHECK (status IN ('scheduled','active','completed','cancelled','superseded'));

SET @table_name := 'ipca_operational_reservations';
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'supersedes_reservation_uuid'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE ipca_operational_reservations ADD COLUMN supersedes_reservation_uuid CHAR(36) NULL AFTER reservation_uuid',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'superseded_by_reservation_uuid'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE ipca_operational_reservations ADD COLUMN superseded_by_reservation_uuid CHAR(36) NULL AFTER supersedes_reservation_uuid',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name
    AND INDEX_NAME = 'idx_op_reservations_supersedes'
);
SET @sql := IF(
  @idx_exists = 0,
  'CREATE INDEX idx_op_reservations_supersedes ON ipca_operational_reservations (supersedes_reservation_uuid)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name
    AND INDEX_NAME = 'idx_op_reservations_superseded_by'
);
SET @sql := IF(
  @idx_exists = 0,
  'CREATE INDEX idx_op_reservations_superseded_by ON ipca_operational_reservations (superseded_by_reservation_uuid)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
