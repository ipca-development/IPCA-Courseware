-- =============================================================================
-- Compliance Operating System — Corrective Action Deadline Extension Workflow
-- =============================================================================
-- Collective governed extension requests and public tokenized review links.
-- Existing corrective-action due dates remain authoritative until approval.
-- =============================================================================

CREATE TABLE IF NOT EXISTS ipca_compliance_corrective_action_deadline_extension_batches (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  audit_id              BIGINT UNSIGNED NOT NULL,
  request_reference     VARCHAR(100) NOT NULL UNIQUE,
  request_type          ENUM('authority','internal') NOT NULL DEFAULT 'authority',
  status                ENUM('draft','submitted','under_review','approved','partially_approved','rejected','superseded','cancelled') NOT NULL DEFAULT 'draft',
  recipient_email       VARCHAR(255) NULL,
  recipient_name        VARCHAR(255) NULL,
  summary_explanation   LONGTEXT NULL,
  email_thread_id       BIGINT UNSIGNED NULL,
  outbound_email_id     BIGINT UNSIGNED NULL,
  submitted_at          DATETIME NULL,
  submitted_by          BIGINT UNSIGNED NULL,
  reviewed_at           DATETIME NULL,
  reviewed_by_name      VARCHAR(255) NULL,
  reviewed_by_email     VARCHAR(255) NULL,
  review_notes          LONGTEXT NULL,
  created_by            BIGINT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  locked_at             DATETIME NULL,
  KEY idx_ipcacapext_batch_audit (audit_id),
  KEY idx_ipcacapext_batch_status (status),
  KEY idx_ipcacapext_batch_submitted (submitted_at),
  KEY idx_ipcacapext_batch_reference (request_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — collective corrective-action deadline extension requests.';

CREATE TABLE IF NOT EXISTS ipca_compliance_corrective_action_deadline_extension_items (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_id                   BIGINT UNSIGNED NOT NULL,
  corrective_action_id       BIGINT UNSIGNED NOT NULL,
  finding_id                 BIGINT UNSIGNED NOT NULL,
  previous_approved_deadline DATE NOT NULL,
  requested_deadline         DATE NOT NULL,
  approved_deadline          DATE NULL,
  explanation_category       VARCHAR(100) NULL,
  explanation                LONGTEXT NOT NULL,
  status                     ENUM('draft','submitted','approved','rejected','superseded','cancelled') NOT NULL DEFAULT 'draft',
  reviewed_at                DATETIME NULL,
  review_notes               LONGTEXT NULL,
  created_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  locked_at                  DATETIME NULL,
  KEY idx_ipcacapext_item_batch (batch_id),
  KEY idx_ipcacapext_item_action (corrective_action_id),
  KEY idx_ipcacapext_item_finding (finding_id),
  KEY idx_ipcacapext_item_status (status),
  KEY idx_ipcacapext_item_requested (requested_deadline)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — corrective-action deadline extension request items.';

CREATE TABLE IF NOT EXISTS ipca_compliance_public_approval_tokens (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token_hash            CHAR(64) NOT NULL UNIQUE,
  token_type            ENUM('deadline_extension','rca_cap_approval','corrective_action_approval','audit_report_view') NOT NULL,
  batch_id              BIGINT UNSIGNED NULL,
  audit_id              BIGINT UNSIGNED NULL,
  finding_id            BIGINT UNSIGNED NULL,
  corrective_action_id  BIGINT UNSIGNED NULL,
  recipient_email       VARCHAR(255) NOT NULL,
  recipient_name        VARCHAR(255) NULL,
  expires_at            DATETIME NOT NULL,
  used_at               DATETIME NULL,
  revoked_at            DATETIME NULL,
  last_viewed_at        DATETIME NULL,
  view_count            INT UNSIGNED NOT NULL DEFAULT 0,
  created_by            BIGINT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipca_public_tokens_batch (batch_id),
  KEY idx_ipca_public_tokens_audit (audit_id),
  KEY idx_ipca_public_tokens_type_expiry (token_type, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — hashed public approval tokens.';
