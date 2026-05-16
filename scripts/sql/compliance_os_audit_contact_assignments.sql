-- =============================================================================
-- Compliance Operating System — Audit Contact Assignments
-- =============================================================================
-- Extends the existing audit assignment table so audits can carry named
-- contacts for lead auditor / auditor / specialist / attendee workflows.
-- =============================================================================

ALTER TABLE ipca_compliance_audit_assignments
  MODIFY user_id INT UNSIGNED NULL COMMENT 'users.id when the contact is a platform user; NULL for external/manual contacts';

SET @col_exists := (
  SELECT COUNT(*)
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'ipca_compliance_audit_assignments'
     AND COLUMN_NAME = 'display_name'
);
SET @sql_add_display := IF(@col_exists = 0,
  'ALTER TABLE ipca_compliance_audit_assignments ADD COLUMN display_name VARCHAR(255) NULL AFTER user_id',
  'SELECT 1');
PREPARE stmt_add_display FROM @sql_add_display;
EXECUTE stmt_add_display;
DEALLOCATE PREPARE stmt_add_display;

SET @col_exists := (
  SELECT COUNT(*)
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'ipca_compliance_audit_assignments'
     AND COLUMN_NAME = 'email'
);
SET @sql_add_email := IF(@col_exists = 0,
  'ALTER TABLE ipca_compliance_audit_assignments ADD COLUMN email VARCHAR(255) NULL AFTER display_name',
  'SELECT 1');
PREPARE stmt_add_email FROM @sql_add_email;
EXECUTE stmt_add_email;
DEALLOCATE PREPARE stmt_add_email;

SET @col_exists := (
  SELECT COUNT(*)
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'ipca_compliance_audit_assignments'
     AND COLUMN_NAME = 'position'
);
SET @sql_add_position := IF(@col_exists = 0,
  'ALTER TABLE ipca_compliance_audit_assignments ADD COLUMN position ENUM(''LEAD_AUDITOR'',''AUDITOR'',''SPECIALIST'',''ATTENDEE'') NULL AFTER email',
  'SELECT 1');
PREPARE stmt_add_position FROM @sql_add_position;
EXECUTE stmt_add_position;
DEALLOCATE PREPARE stmt_add_position;

SET @ix_exists := (
  SELECT COUNT(*)
    FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'ipca_compliance_audit_assignments'
     AND INDEX_NAME = 'idx_ipcaaa_position'
);
SET @sql_add_ix := IF(@ix_exists = 0,
  'ALTER TABLE ipca_compliance_audit_assignments ADD INDEX idx_ipcaaa_position (audit_id, position, revoked_at)',
  'SELECT 1');
PREPARE stmt_add_ix FROM @sql_add_ix;
EXECUTE stmt_add_ix;
DEALLOCATE PREPARE stmt_add_ix;
