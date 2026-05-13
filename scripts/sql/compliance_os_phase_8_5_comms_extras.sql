-- =============================================================================
-- Compliance Operating System — Phase 8.5: Comms Center extras
-- =============================================================================
-- File:    scripts/sql/compliance_os_phase_8_5_comms_extras.sql
-- Scope:   Follow-on capabilities on top of the Phase 8 Communications Center:
--          (1) outbound attachments staged on drafts before send,
--          (2) full-text search across email subject + body.
--
-- Apply:   mysql ... < scripts/sql/compliance_os_phase_8_5_comms_extras.sql
-- Idempotent: each statement guards itself so re-applying is safe.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. ipca_compliance_email_draft_attachments
--    Files attached to an outbound draft BEFORE it ships. When the draft is
--    sent, these rows are converted to ipca_compliance_email_attachments rows
--    keyed to the resulting outbound email and the source files are reused
--    (no re-upload required). Schema mirrors email_attachments so the carry-
--    over copy is straightforward.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_compliance_email_draft_attachments (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  draft_id          BIGINT UNSIGNED NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  content_type      VARCHAR(191) NULL,
  size_bytes        BIGINT UNSIGNED NULL,
  storage_disk      VARCHAR(64) NOT NULL DEFAULT 'spaces'
                      COMMENT 'spaces | local — disk we actually wrote to',
  storage_key       VARCHAR(500) NOT NULL
                      COMMENT 'Object key (Spaces) or relative path (local) under storage/',
  public_url        TEXT NULL,
  sha256            CHAR(64) NULL,
  created_by        INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipcaeda_draft (draft_id),
  KEY idx_ipcaeda_sha (sha256),
  CONSTRAINT fk_ipcaeda_draft FOREIGN KEY (draft_id)
    REFERENCES ipca_compliance_email_drafts (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance Comms Center — files staged on an outbound draft.';

-- -----------------------------------------------------------------------------
-- 2. Full-text index on ipca_compliance_emails (subject + text_body).
--    InnoDB supports FULLTEXT since MySQL 5.6. We guard with information_schema
--    so re-running the migration is safe.
-- -----------------------------------------------------------------------------
SET @ix_exists := (
  SELECT COUNT(*)
    FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'ipca_compliance_emails'
     AND INDEX_NAME   = 'ftx_ipcaem_subject_body'
);
SET @sql_add_ftx := IF(@ix_exists = 0,
  'ALTER TABLE ipca_compliance_emails
     ADD FULLTEXT INDEX ftx_ipcaem_subject_body (subject, text_body)',
  'SELECT 1');
PREPARE stmt_add_ftx FROM @sql_add_ftx;
EXECUTE stmt_add_ftx;
DEALLOCATE PREPARE stmt_add_ftx;

-- =============================================================================
-- END OF FILE
-- =============================================================================
