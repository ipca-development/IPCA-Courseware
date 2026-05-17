-- =============================================================================
-- Compliance OS — Authority Report Documents
-- =============================================================================
-- Official authority PDF reports received for audits and findings.
-- =============================================================================

SET @col_exists := (
  SELECT COUNT(*)
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'ipca_compliance_audit_documents'
     AND COLUMN_NAME = 'received_on'
);
SET @sql_add_received_on := IF(@col_exists = 0,
  'ALTER TABLE ipca_compliance_audit_documents ADD COLUMN received_on DATE NULL AFTER file_size',
  'SELECT 1');
PREPARE stmt_add_received_on FROM @sql_add_received_on;
EXECUTE stmt_add_received_on;
DEALLOCATE PREPARE stmt_add_received_on;

SET @col_exists := (
  SELECT COUNT(*)
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'ipca_compliance_audit_documents'
     AND COLUMN_NAME = 'deleted_at'
);
SET @sql_add_deleted_at := IF(@col_exists = 0,
  'ALTER TABLE ipca_compliance_audit_documents ADD COLUMN deleted_at DATETIME NULL AFTER notes',
  'SELECT 1');
PREPARE stmt_add_audit_deleted_at FROM @sql_add_deleted_at;
EXECUTE stmt_add_audit_deleted_at;
DEALLOCATE PREPARE stmt_add_audit_deleted_at;

SET @col_exists := (
  SELECT COUNT(*)
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'ipca_compliance_audit_documents'
     AND COLUMN_NAME = 'deleted_by'
);
SET @sql_add_deleted_by := IF(@col_exists = 0,
  'ALTER TABLE ipca_compliance_audit_documents ADD COLUMN deleted_by INT UNSIGNED NULL AFTER deleted_at',
  'SELECT 1');
PREPARE stmt_add_audit_deleted_by FROM @sql_add_deleted_by;
EXECUTE stmt_add_audit_deleted_by;
DEALLOCATE PREPARE stmt_add_audit_deleted_by;

CREATE TABLE IF NOT EXISTS ipca_compliance_finding_documents (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  finding_id      BIGINT UNSIGNED NOT NULL,
  doc_kind        VARCHAR(32) NOT NULL
                    COMMENT 'FINDING_REPORT | UPDATED_FINDING_REPORT | OTHER',
  storage_relpath VARCHAR(1024) NOT NULL COMMENT 'Path under project root (storage/compliance/...)',
  original_name   VARCHAR(512) NOT NULL,
  mime_type       VARCHAR(128) NOT NULL,
  file_size       BIGINT UNSIGNED NULL,
  received_on     DATE NULL,
  sha256          CHAR(64) NULL,
  uploaded_by     INT UNSIGNED NULL,
  uploaded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes           TEXT NULL,
  deleted_at      DATETIME NULL,
  deleted_by      INT UNSIGNED NULL,
  KEY idx_ipcafd_finding_kind (finding_id, doc_kind),
  KEY idx_ipcafd_sha (sha256),
  CONSTRAINT fk_ipcafd_finding FOREIGN KEY (finding_id)
    REFERENCES ipca_compliance_findings (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — official authority files attached to a finding.';

SET @col_exists := (
  SELECT COUNT(*)
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'ipca_compliance_finding_documents'
     AND COLUMN_NAME = 'received_on'
);
SET @sql_add_finding_received_on := IF(@col_exists = 0,
  'ALTER TABLE ipca_compliance_finding_documents ADD COLUMN received_on DATE NULL AFTER file_size',
  'SELECT 1');
PREPARE stmt_add_finding_received_on FROM @sql_add_finding_received_on;
EXECUTE stmt_add_finding_received_on;
DEALLOCATE PREPARE stmt_add_finding_received_on;

SET @col_exists := (
  SELECT COUNT(*)
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'ipca_compliance_finding_documents'
     AND COLUMN_NAME = 'deleted_at'
);
SET @sql_add_deleted_at := IF(@col_exists = 0,
  'ALTER TABLE ipca_compliance_finding_documents ADD COLUMN deleted_at DATETIME NULL AFTER notes',
  'SELECT 1');
PREPARE stmt_add_finding_deleted_at FROM @sql_add_deleted_at;
EXECUTE stmt_add_finding_deleted_at;
DEALLOCATE PREPARE stmt_add_finding_deleted_at;

SET @col_exists := (
  SELECT COUNT(*)
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'ipca_compliance_finding_documents'
     AND COLUMN_NAME = 'deleted_by'
);
SET @sql_add_deleted_by := IF(@col_exists = 0,
  'ALTER TABLE ipca_compliance_finding_documents ADD COLUMN deleted_by INT UNSIGNED NULL AFTER deleted_at',
  'SELECT 1');
PREPARE stmt_add_finding_deleted_by FROM @sql_add_deleted_by;
EXECUTE stmt_add_finding_deleted_by;
DEALLOCATE PREPARE stmt_add_finding_deleted_by;
