-- =============================================================================
-- Compliance Operating System — Phase 8: Compliance Communications Center
-- =============================================================================
-- File:    scripts/sql/compliance_os_phase_8_comms_center.sql
-- Scope:   Stage 1 of the Compliance Communications Center — inbound only.
--          Creates new IPCA-native tables to record authority / regulator email
--          correspondence routed through Postmark Inbound. Google Workspace
--          remains the mailbox host; Postmark is the inbound/outbound parser
--          + tracking webhook source.
--
-- Apply:   mysql ... < scripts/sql/compliance_os_phase_8_comms_center.sql
-- Idempotent: every statement uses CREATE TABLE IF NOT EXISTS — re-runs are safe.
--
-- NAMING NOTE — link table:
--   The previous phase already created `ipca_compliance_email_links` for the
--   legacy `ipca_compliance_inbound_emails` table (legacy seed import). That
--   table's FK is to `ipca_compliance_inbound_emails(id)`, so we cannot reuse
--   it for the new comms-center `ipca_compliance_emails(id)` rows. The new
--   table is therefore named `ipca_compliance_email_obj_links` to make the
--   ownership unambiguous and to avoid an ALTER TABLE on a table that may
--   already hold production rows.
--
-- DESIGN DECISIONS:
--   * PK: BIGINT UNSIGNED AUTO_INCREMENT, consistent with the rest of
--     ipca_compliance_*.
--   * Timestamps: DATETIME (2038-safe, timezone neutral).
--   * Enums: kept as enum where the value set is locked by spec; otherwise
--     VARCHAR(32) so authorities/workflows can extend without an ALTER.
--   * JSON columns are nullable so a partially-parsed Postmark payload still
--     stores. The webhook stores `raw_payload_json` so we never lose evidence
--     even if a parsing step fails.
--   * No FKs to the platform `users` table (matches existing compliance-schema
--     convention).
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. ipca_compliance_email_threads
--    Groups inbound + outbound emails by RFC822 thread (Message-Id chain) or
--    by mailbox-hash / normalised subject+contact when chain is missing.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_compliance_email_threads (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  thread_key            VARCHAR(191) NULL
                          COMMENT 'Stable thread token: Postmark MailboxHash, root Message-Id, or hash of (subject_norm,contact)',
  subject_normalized    VARCHAR(255) NULL
                          COMMENT 'Subject with leading Re:/Fwd:/Aw: stripped — used for fallback matching only',
  primary_contact_email VARCHAR(255) NULL,
  authority_name        VARCHAR(255) NULL,
  status                ENUM('open','waiting_internal','waiting_external','closed','archived')
                          NOT NULL DEFAULT 'open',
  priority              ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  last_message_at       DATETIME NULL,
  created_by            INT UNSIGNED NULL COMMENT 'users.id of admin who first surfaced this thread (NULL = webhook origin)',
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ipcaethr_thread_key (thread_key),
  KEY idx_ipcaethr_status (status),
  KEY idx_ipcaethr_priority (priority),
  KEY idx_ipcaethr_last (last_message_at),
  KEY idx_ipcaethr_contact (primary_contact_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance Comms Center — email thread envelope (inbound + outbound).';

-- -----------------------------------------------------------------------------
-- 2. ipca_compliance_emails
--    One row per inbound / outbound email. Body + headers + raw payload are
--    stored for evidence preservation. FK to thread is nullable so a webhook
--    can store the email even if thread resolution is deferred.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_compliance_emails (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  thread_id               BIGINT UNSIGNED NULL,
  direction               ENUM('inbound','outbound') NOT NULL,
  postmark_message_id     VARCHAR(191) NULL COMMENT 'Postmark MessageID (UUID); unique per Postmark event',
  postmark_record_type    VARCHAR(64) NULL  COMMENT 'Inbound | Delivery | Open | Click | Bounce | SpamComplaint',
  message_id_header       VARCHAR(255) NULL COMMENT 'RFC822 Message-Id header from the mail itself',
  in_reply_to_header      VARCHAR(255) NULL,
  references_header       TEXT NULL,
  from_email              VARCHAR(255) NOT NULL,
  from_name               VARCHAR(255) NULL,
  to_json                 JSON NULL,
  cc_json                 JSON NULL,
  bcc_json                JSON NULL,
  reply_to_json           JSON NULL,
  subject                 VARCHAR(500) NULL,
  text_body               MEDIUMTEXT NULL,
  html_body               MEDIUMTEXT NULL,
  stripped_text_reply     MEDIUMTEXT NULL COMMENT 'Postmark `StrippedTextReply` — quoted history removed',
  headers_json            JSON NULL,
  raw_payload_json        JSON NULL COMMENT 'Full original Postmark webhook payload (evidence)',
  received_at             DATETIME NULL,
  sent_at                 DATETIME NULL,
  status                  ENUM('received','queued','sent','delivered','opened','clicked','bounced','failed','archived')
                            NOT NULL DEFAULT 'received',
  spam_score              DECIMAL(8,3) NULL,
  mailbox_hash            VARCHAR(191) NULL,
  created_by              INT UNSIGNED NULL,
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcaem_postmark_msgid (postmark_message_id),
  KEY idx_ipcaem_thread (thread_id),
  KEY idx_ipcaem_direction (direction),
  KEY idx_ipcaem_status (status),
  KEY idx_ipcaem_received (received_at),
  KEY idx_ipcaem_sent (sent_at),
  KEY idx_ipcaem_msgidhdr (message_id_header),
  KEY idx_ipcaem_from (from_email),
  CONSTRAINT fk_ipcaem_thread FOREIGN KEY (thread_id)
    REFERENCES ipca_compliance_email_threads (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance Comms Center — inbound + outbound email records.';

-- -----------------------------------------------------------------------------
-- 3. ipca_compliance_email_attachments
--    Attachment metadata + DigitalOcean Spaces storage location.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_compliance_email_attachments (
  id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email_id                  BIGINT UNSIGNED NOT NULL,
  original_filename         VARCHAR(255) NOT NULL,
  content_type              VARCHAR(191) NULL,
  size_bytes                BIGINT UNSIGNED NULL,
  content_id                VARCHAR(255) NULL COMMENT 'RFC2392 cid: for inline images',
  postmark_attachment_index INT NULL,
  storage_disk              VARCHAR(64) NOT NULL DEFAULT 'spaces'
                              COMMENT 'spaces | local — disk we actually wrote to',
  storage_key               VARCHAR(500) NOT NULL
                              COMMENT 'Object key (Spaces) or relative path (local) under storage/',
  public_url                TEXT NULL,
  sha256                    CHAR(64) NULL,
  is_inline                 TINYINT(1) NOT NULL DEFAULT 0,
  created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipcaea_email (email_id),
  KEY idx_ipcaea_sha (sha256),
  CONSTRAINT fk_ipcaea_email FOREIGN KEY (email_id)
    REFERENCES ipca_compliance_emails (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance Comms Center — files extracted from inbound/outbound emails.';

-- -----------------------------------------------------------------------------
-- 4. ipca_compliance_email_events
--    Delivery / open / click / bounce / spam-complaint events from Postmark
--    tracking webhooks. Also used as an in-system audit log (inbound,
--    outbound_send, webhook_error).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_compliance_email_events (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email_id            BIGINT UNSIGNED NULL,
  postmark_message_id VARCHAR(191) NULL,
  event_type          ENUM('delivery','open','click','bounce','spam_complaint','inbound','outbound_send','webhook_error')
                        NOT NULL,
  event_at            DATETIME NULL,
  recipient_email     VARCHAR(255) NULL,
  event_payload_json  JSON NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipcaev_email (email_id),
  KEY idx_ipcaev_postmark (postmark_message_id),
  KEY idx_ipcaev_type (event_type),
  KEY idx_ipcaev_at (event_at),
  CONSTRAINT fk_ipcaev_email FOREIGN KEY (email_id)
    REFERENCES ipca_compliance_emails (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance Comms Center — Postmark + internal event log per email.';

-- -----------------------------------------------------------------------------
-- 5. ipca_compliance_email_obj_links
--    Links an email or thread to a compliance object (case, finding, audit,
--    corrective_action, manual_change_request, meeting, regulatory_change,
--    authority_report). Polymorphic by (linked_object_type, linked_object_id).
--
--    NOTE: deliberately NOT named `ipca_compliance_email_links` because that
--    table already exists for the legacy `ipca_compliance_inbound_emails`.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_compliance_email_obj_links (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email_id            BIGINT UNSIGNED NULL,
  thread_id           BIGINT UNSIGNED NULL,
  linked_object_type  VARCHAR(100) NOT NULL
                        COMMENT 'compliance_case | finding | audit | corrective_action | manual_change_request | meeting | regulatory_change | authority_report',
  linked_object_id    VARCHAR(100) NOT NULL,
  link_type           ENUM('evidence','authority_communication','source','follow_up','context')
                        NOT NULL DEFAULT 'context',
  created_by          INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipcaeol_email (email_id),
  KEY idx_ipcaeol_thread (thread_id),
  KEY idx_ipcaeol_obj (linked_object_type, linked_object_id),
  CONSTRAINT fk_ipcaeol_email FOREIGN KEY (email_id)
    REFERENCES ipca_compliance_emails (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipcaeol_thread FOREIGN KEY (thread_id)
    REFERENCES ipca_compliance_email_threads (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance Comms Center — link from email/thread to any compliance object.';

-- -----------------------------------------------------------------------------
-- 6. ipca_compliance_email_drafts
--    Outbound drafts staged for review/approval before send. Stage 2 (outbound)
--    uses this; Stage 1 schema lands now so the migration is one apply.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_compliance_email_drafts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  thread_id       BIGINT UNSIGNED NULL,
  to_json         JSON NOT NULL,
  cc_json         JSON NULL,
  bcc_json        JSON NULL,
  subject         VARCHAR(500) NOT NULL,
  html_body       MEDIUMTEXT NULL,
  text_body       MEDIUMTEXT NULL,
  status          ENUM('draft','ready_to_send','sent','cancelled') NOT NULL DEFAULT 'draft',
  created_by      INT UNSIGNED NULL,
  approved_by     INT UNSIGNED NULL,
  approved_at     DATETIME NULL,
  sent_email_id   BIGINT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ipcaed_thread (thread_id),
  KEY idx_ipcaed_status (status),
  KEY idx_ipcaed_created_by (created_by),
  KEY idx_ipcaed_sent_email (sent_email_id),
  CONSTRAINT fk_ipcaed_thread FOREIGN KEY (thread_id)
    REFERENCES ipca_compliance_email_threads (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipcaed_sent_email FOREIGN KEY (sent_email_id)
    REFERENCES ipca_compliance_emails (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance Comms Center — staged outbound drafts pending review/approval.';

-- =============================================================================
-- END OF FILE
-- =============================================================================
