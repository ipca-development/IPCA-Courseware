-- =============================================================================
-- Compliance Operating System — Phase 1: Initial Schema
-- =============================================================================
-- File:    scripts/sql/compliance_os_phase_1_initial_schema.sql
-- Scope:   Phase 1 ONLY — create new IPCA-native compliance tables.
-- Purpose: Recreate the legacy `ipca_compliance` system (TablePlus dump
--          `table_compliance.sql`) as platform-native tables inside the
--          existing IPCA Courseware schema, prefixed `ipca_compliance_*`.
--
-- Apply:   mysql ... < scripts/sql/compliance_os_phase_1_initial_schema.sql
-- Idempotent: every statement uses CREATE TABLE IF NOT EXISTS — re-runs are safe.
--
-- DESIGN DECISIONS (see Phase-1 report-back for assumptions)
--   * All tables prefixed `ipca_compliance_*` to avoid collisions with
--     existing platform tables (users, roles, resource_library_*, easa_*).
--   * Primary keys: BIGINT UNSIGNED AUTO_INCREMENT throughout the compliance
--     schema, so intra-compliance FKs never have type mismatches. (Legacy
--     used binary(16) UUIDs; we deliberately do NOT carry that over.)
--   * Timestamps: DATETIME (not TIMESTAMP) for 2038-safety + timezone neutrality.
--   * Status / classification / kind columns use VARCHAR(32) by convention so
--     authorities or workflows can introduce new values without ALTER TABLE.
--     Allowed values are documented inline in each column COMMENT.
--   * Cross-table FKs are added only between compliance tables and only when
--     the referenced PK is BIGINT UNSIGNED. No FK is created against `users`
--     because the platform's existing migrations (`easa_user_bookmarks.sql`,
--     `easa_ai_chat.sql`, `easa_erules.sql`) deliberately omit that FK.
--   * Canonical regulatory / manual content is NOT duplicated. Finding-to-
--     regulation and finding-to-manual links are POLYMORPHIC and point at
--     existing platform tables via (source_kind, source_id) tuples — see
--     `ipca_compliance_finding_regulatory_links` and
--     `ipca_compliance_finding_manual_links` below.
--   * Immutability fields (`locked_at`, `locked_by`, `approved_at`,
--     `approved_by`, `approved_by_name`) appear on every record that
--     becomes officially binding (RCA, CAP approval, checklist version,
--     audit checklist snapshot, audit report package, manual release
--     package, meeting transcript). Enforcement is done in PHP services;
--     the columns capture the audit trail.
--   * AI run tracking is centralised in `ipca_compliance_ai_runs` so every
--     AI invocation (RCA suggestion, CAP suggestion, summary, semantic
--     regulatory match, etc.) is logged with its prompt + evidence snapshot
--     + response payload + actor.
--   * Compliance-specific event log lives in `ipca_compliance_case_events`
--     — we do NOT create a second generic platform audit_log table.
--
-- Sections in this file (in order):
--   A. Core case layer
--   B. Audits
--   C. Findings / NCR
--   D. RCA / CAP
--   E. Checklists
--   F. Meetings
--   G. Inbox
--   H. Manual control
--   I. Monitoring
--   J. Part-IS / Digital governance
-- =============================================================================


-- =============================================================================
-- SECTION A. CORE CASE LAYER
-- =============================================================================
-- A "case" is a thin envelope that ties together everything related to a
-- single compliance matter (an audit + its findings + their CAPs + the
-- meetings + the regulatory verifications + the manual change requests +
-- the email correspondence). Other compliance objects can exist without a
-- case (e.g. a one-off ad-hoc finding), but when they share a story they
-- attach to the same case via `ipca_compliance_case_links`.
--
-- `ipca_compliance_case_events` is the compliance-only event log. It is
-- the source-of-truth for "what happened on this case, by whom, when".

CREATE TABLE IF NOT EXISTS ipca_compliance_cases (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  case_code         VARCHAR(64) NOT NULL COMMENT 'Human-readable code e.g. CMS-2026-0042',
  title             VARCHAR(255) NOT NULL,
  case_type         VARCHAR(32) NOT NULL DEFAULT 'AUDIT'
                      COMMENT 'AUDIT | NCR | INVESTIGATION | MANAGEMENT_OF_CHANGE | INCIDENT | INSPECTION | OTHER',
  status            VARCHAR(32) NOT NULL DEFAULT 'OPEN'
                      COMMENT 'OPEN | IN_PROGRESS | WAITING_AUTHORITY | CLOSED | CANCELLED',
  severity          VARCHAR(16) NULL
                      COMMENT 'LOW | MEDIUM | HIGH | CRITICAL — derived from worst child finding',
  authority         VARCHAR(32) NULL
                      COMMENT 'BCAA | FAA | EASA | INTERNAL | OTHER',
  summary           TEXT NULL,
  opened_at         DATETIME NULL,
  due_at            DATETIME NULL,
  closed_at         DATETIME NULL,
  owner_user_id     INT UNSIGNED NULL COMMENT 'Compliance officer owning the case (users.id, no FK by platform convention)',
  created_by        INT UNSIGNED NULL COMMENT 'users.id',
  updated_by        INT UNSIGNED NULL,
  locked_at         DATETIME NULL COMMENT 'Set when case is closed and made immutable',
  locked_by         INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcacases_code (case_code),
  KEY idx_ipcacases_status (status),
  KEY idx_ipcacases_owner_status (owner_user_id, status),
  KEY idx_ipcacases_authority (authority),
  KEY idx_ipcacases_opened (opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — top-level case envelopes (audit / NCR / investigation / MoC).';


CREATE TABLE IF NOT EXISTS ipca_compliance_case_links (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  case_id           BIGINT UNSIGNED NOT NULL,
  entity_type       VARCHAR(32) NOT NULL
                      COMMENT 'audit | finding | corrective_action | meeting | inbound_email | manual_change_request | manual_release_package | monitor_alert | is_incident | ai_run',
  entity_id         BIGINT UNSIGNED NOT NULL COMMENT 'PK of the linked compliance row',
  relation          VARCHAR(32) NULL COMMENT 'Optional sub-relation e.g. PRIMARY | SUPPORTING | FOLLOW_UP',
  created_by        INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcaclinks_case_entity (case_id, entity_type, entity_id),
  KEY idx_ipcaclinks_entity (entity_type, entity_id),
  CONSTRAINT fk_ipcaclinks_case FOREIGN KEY (case_id)
    REFERENCES ipca_compliance_cases (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — polymorphic links from a case to any owned compliance entity.';


CREATE TABLE IF NOT EXISTS ipca_compliance_case_events (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  case_id           BIGINT UNSIGNED NULL COMMENT 'Optional — events on orphan entities allowed',
  entity_type       VARCHAR(32) NOT NULL
                      COMMENT 'case | audit | finding | corrective_action | rca | meeting | inbound_email | manual_change_request | manual_release_package | monitor_alert | is_incident | ai_run | checklist_version | checklist_snapshot',
  entity_id         BIGINT UNSIGNED NULL,
  event_kind        VARCHAR(64) NOT NULL
                      COMMENT 'created | updated | classified | rca_started | rca_locked | cap_approved | cap_closed | doc_uploaded | report_exported | ai_run_logged | status_changed | locked | unlocked | linked | unlinked | etc.',
  actor_user_id     INT UNSIGNED NULL,
  actor_ip          VARCHAR(64) NULL,
  summary           VARCHAR(255) NULL,
  before_json       JSON NULL,
  after_json        JSON NULL,
  metadata_json     JSON NULL,
  occurred_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipcacevents_case_time (case_id, occurred_at),
  KEY idx_ipcacevents_entity_time (entity_type, entity_id, occurred_at),
  KEY idx_ipcacevents_kind_time (event_kind, occurred_at),
  KEY idx_ipcacevents_actor (actor_user_id, occurred_at),
  CONSTRAINT fk_ipcacevents_case FOREIGN KEY (case_id)
    REFERENCES ipca_compliance_cases (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — compliance-specific event log (replaces legacy audit_log).';


-- =============================================================================
-- SECTION B. AUDITS
-- =============================================================================

CREATE TABLE IF NOT EXISTS ipca_compliance_audits (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  case_id             BIGINT UNSIGNED NULL COMMENT 'Optional case envelope',
  audit_code          VARCHAR(64) NOT NULL COMMENT 'Human-readable code e.g. AUD-2026-007',
  title               VARCHAR(255) NOT NULL,
  authority           VARCHAR(32) NOT NULL DEFAULT 'INTERNAL'
                        COMMENT 'BCAA | FAA | EASA | INTERNAL | OTHER',
  audit_category      VARCHAR(32) NULL
                        COMMENT 'SAFETY | COMPLIANCE | QUALITY | SECURITY | OPERATIONAL | OTHER',
  audit_type          VARCHAR(64) NOT NULL
                        COMMENT 'e.g. ANNUAL | INITIAL | RENEWAL | FOLLOW_UP | SPOT | INSPECTION',
  audit_entity        VARCHAR(128) NULL COMMENT 'Subject org / dept / facility',
  external_ref        VARCHAR(128) NULL COMMENT 'Authority reference id',
  status              VARCHAR(32) NOT NULL DEFAULT 'PLANNED'
                        COMMENT 'PLANNED | SCHEDULED | IN_PROGRESS | FIELDWORK_COMPLETE | REPORT_DRAFT | REPORT_ISSUED | WAITING_AUTHORITY | CLOSED | CANCELLED',
  subject             TEXT NULL COMMENT 'Audit scope / charter',
  start_date          DATE NULL,
  end_date            DATE NULL,
  closed_date         DATE NULL,
  lead_auditor_id     INT UNSIGNED NULL COMMENT 'users.id',
  created_by          INT UNSIGNED NULL,
  updated_by          INT UNSIGNED NULL,
  locked_at           DATETIME NULL,
  locked_by           INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcaaud_code (audit_code),
  KEY idx_ipcaaud_case (case_id),
  KEY idx_ipcaaud_status (status),
  KEY idx_ipcaaud_authority_status (authority, status),
  KEY idx_ipcaaud_start (start_date),
  CONSTRAINT fk_ipcaaud_case FOREIGN KEY (case_id)
    REFERENCES ipca_compliance_cases (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — audits (replaces legacy `audits` table; no more text auditors/attendees).';


CREATE TABLE IF NOT EXISTS ipca_compliance_audit_assignments (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  audit_id        BIGINT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL COMMENT 'users.id',
  role            VARCHAR(32) NOT NULL DEFAULT 'AUDITOR'
                    COMMENT 'LEAD_AUDITOR | AUDITOR | OBSERVER | SUBJECT_EXPERT | SCRIBE',
  assigned_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  assigned_by     INT UNSIGNED NULL,
  revoked_at      DATETIME NULL,
  revoked_by      INT UNSIGNED NULL,
  notes           TEXT NULL,
  UNIQUE KEY uk_ipcaaa_audit_user_role (audit_id, user_id, role),
  KEY idx_ipcaaa_user (user_id),
  KEY idx_ipcaaa_active (audit_id, revoked_at),
  CONSTRAINT fk_ipcaaa_audit FOREIGN KEY (audit_id)
    REFERENCES ipca_compliance_audits (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — structured auditor assignment (replaces legacy text auditors column).';


CREATE TABLE IF NOT EXISTS ipca_compliance_audit_participants (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  audit_id        BIGINT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NULL COMMENT 'NULL when participant is external (no platform account)',
  display_name    VARCHAR(255) NULL COMMENT 'Required when user_id is NULL',
  email           VARCHAR(255) NULL,
  organisation    VARCHAR(255) NULL,
  role_at_audit   VARCHAR(64) NULL
                    COMMENT 'AUDITEE | SUBJECT_LEAD | AUTHORITY_INSPECTOR | EXPERT_WITNESS | OBSERVER',
  attended_at     DATETIME NULL,
  notes           TEXT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipcaap_audit (audit_id),
  KEY idx_ipcaap_user (user_id),
  CONSTRAINT fk_ipcaap_audit FOREIGN KEY (audit_id)
    REFERENCES ipca_compliance_audits (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — audit attendees (replaces legacy text attendees column).';


CREATE TABLE IF NOT EXISTS ipca_compliance_audit_documents (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  audit_id        BIGINT UNSIGNED NOT NULL,
  doc_kind        VARCHAR(32) NOT NULL
                    COMMENT 'AUTHORITY_EMAIL | PLAN | MINUTES | EVIDENCE | CHECKLIST | REPORT_DRAFT | OTHER',
  storage_relpath VARCHAR(1024) NOT NULL COMMENT 'Path under project root (storage/compliance/...)',
  original_name   VARCHAR(512) NOT NULL,
  mime_type       VARCHAR(128) NOT NULL,
  file_size       BIGINT UNSIGNED NULL,
  sha256          CHAR(64) NULL,
  uploaded_by     INT UNSIGNED NULL,
  uploaded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes           TEXT NULL,
  KEY idx_ipcaad_audit_kind (audit_id, doc_kind),
  KEY idx_ipcaad_sha (sha256),
  CONSTRAINT fk_ipcaad_audit FOREIGN KEY (audit_id)
    REFERENCES ipca_compliance_audits (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — files attached to an audit.';


CREATE TABLE IF NOT EXISTS ipca_compliance_audit_reports (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  audit_id            BIGINT UNSIGNED NOT NULL,
  report_kind         VARCHAR(32) NOT NULL DEFAULT 'CMAR'
                        COMMENT 'CMAR | NCR | INTERIM | FINAL | AUTHORITY_PACKAGE',
  version_no          INT UNSIGNED NOT NULL DEFAULT 1,
  title               VARCHAR(255) NOT NULL,
  storage_relpath     VARCHAR(1024) NOT NULL COMMENT 'Generated PDF/zip location',
  pdf_sha256          CHAR(64) NULL COMMENT 'Hash of the locked PDF for integrity',
  file_size           BIGINT UNSIGNED NULL,
  payload_json        JSON NULL COMMENT 'Snapshot of source data used to render the report',
  generated_by        INT UNSIGNED NULL,
  generated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_by         INT UNSIGNED NULL,
  approved_by_name    VARCHAR(255) NULL COMMENT 'Cached name for legal record',
  approved_at         DATETIME NULL,
  locked_at           DATETIME NULL,
  locked_by           INT UNSIGNED NULL,
  UNIQUE KEY uk_ipcaar_audit_kind_version (audit_id, report_kind, version_no),
  KEY idx_ipcaar_audit_generated (audit_id, generated_at),
  CONSTRAINT fk_ipcaar_audit FOREIGN KEY (audit_id)
    REFERENCES ipca_compliance_audits (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — generated audit reports (CMAR / NCR / Final). Locked rows are immutable.';


-- =============================================================================
-- SECTION C. FINDINGS / NCR
-- =============================================================================

CREATE TABLE IF NOT EXISTS ipca_compliance_findings (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  audit_id              BIGINT UNSIGNED NULL COMMENT 'Findings can be raised outside an audit',
  case_id               BIGINT UNSIGNED NULL,
  finding_code          VARCHAR(64) NOT NULL COMMENT 'e.g. NCR-2026-014',
  reference             VARCHAR(128) NULL COMMENT 'Authority/auditor reference',
  title                 VARCHAR(255) NOT NULL,
  description           TEXT NOT NULL,
  classification        VARCHAR(32) NOT NULL DEFAULT 'LEVEL_3'
                          COMMENT 'LEVEL_1 | LEVEL_2 | LEVEL_3 | OBSERVATION | INFORMATION',
  severity              VARCHAR(16) NOT NULL DEFAULT 'MEDIUM'
                          COMMENT 'LOW | MEDIUM | HIGH | CRITICAL',
  status                VARCHAR(32) NOT NULL DEFAULT 'OPEN'
                          COMMENT 'OPEN | IN_PROGRESS | WAITING_AUTHORITY | CLOSED | CANCELLED',
  domain_code           VARCHAR(64) NULL COMMENT 'Compliance domain key (free-form, no domain FK yet)',
  requirement_key       VARCHAR(128) NULL COMMENT 'Optional MCCF requirement key for fast filtering',
  regulation_summary    TEXT NULL COMMENT 'Plain-text cached regulation excerpt (free-form, primary refs in finding_regulatory_links)',
  raised_date           DATE NOT NULL,
  target_date           DATE NULL,
  closed_date           DATE NULL,
  cap_selected_option   VARCHAR(16) NULL COMMENT 'Optional selected CAP option label (legacy parity)',
  cap_selected_effort   VARCHAR(32) NULL COMMENT 'Optional selected CAP effort label (legacy parity)',
  notes                 LONGTEXT NULL,
  created_by            INT UNSIGNED NULL,
  updated_by            INT UNSIGNED NULL,
  approved_by           INT UNSIGNED NULL,
  approved_by_name      VARCHAR(255) NULL,
  approved_at           DATETIME NULL,
  locked_at             DATETIME NULL,
  locked_by             INT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcafind_code (finding_code),
  KEY idx_ipcafind_audit (audit_id),
  KEY idx_ipcafind_case (case_id),
  KEY idx_ipcafind_status (status),
  KEY idx_ipcafind_class_status (classification, status),
  KEY idx_ipcafind_target (target_date),
  KEY idx_ipcafind_reqkey (requirement_key),
  CONSTRAINT fk_ipcafind_audit FOREIGN KEY (audit_id)
    REFERENCES ipca_compliance_audits (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipcafind_case FOREIGN KEY (case_id)
    REFERENCES ipca_compliance_cases (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — findings / NCRs (platform-native equivalent of legacy `findings`).';


CREATE TABLE IF NOT EXISTS ipca_compliance_finding_documents (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  finding_id      BIGINT UNSIGNED NOT NULL,
  doc_kind        VARCHAR(32) NOT NULL
                    COMMENT 'AUDIT_REPORT | EMAIL | TRANSCRIPT | EVIDENCE | PHOTO | RECORDING | OTHER',
  storage_relpath VARCHAR(1024) NOT NULL,
  original_name   VARCHAR(512) NOT NULL,
  mime_type       VARCHAR(128) NOT NULL,
  file_size       BIGINT UNSIGNED NULL,
  sha256          CHAR(64) NULL,
  uploaded_by     INT UNSIGNED NULL,
  uploaded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes           TEXT NULL,
  KEY idx_ipcafd_finding_kind (finding_id, doc_kind),
  KEY idx_ipcafd_sha (sha256),
  CONSTRAINT fk_ipcafd_finding FOREIGN KEY (finding_id)
    REFERENCES ipca_compliance_findings (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — files attached to a finding.';


CREATE TABLE IF NOT EXISTS ipca_compliance_finding_regulatory_links (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  finding_id          BIGINT UNSIGNED NOT NULL,
  source_kind         VARCHAR(32) NOT NULL
                        COMMENT 'rl_edition | rl_block | rl_aim_paragraph | easa_node | external_url',
  source_id           VARCHAR(191) NOT NULL
                        COMMENT 'PK of the referenced regulatory row, OR external URL. VARCHAR to support both int IDs and EASA node_uid strings.',
  citation_label      VARCHAR(255) NULL COMMENT 'Cached human label e.g. "AIM 4-3-2" or "FCL.060(b)"',
  citation_url        VARCHAR(2048) NULL COMMENT 'Cached canonical URL when available',
  link_type           VARCHAR(16) NOT NULL DEFAULT 'PRIMARY'
                        COMMENT 'PRIMARY | SUPPORTING',
  confidence          VARCHAR(16) NOT NULL DEFAULT 'AUTO'
                        COMMENT 'AUTO | VERIFIED | MANUAL',
  verified_by         INT UNSIGNED NULL,
  verified_at         DATETIME NULL,
  notes               TEXT NULL,
  created_by          INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcafrl (finding_id, source_kind, source_id),
  KEY idx_ipcafrl_source (source_kind, source_id),
  CONSTRAINT fk_ipcafrl_finding FOREIGN KEY (finding_id)
    REFERENCES ipca_compliance_findings (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — polymorphic finding→regulation links (bridges to resource_library_* and easa_erules_*).';


CREATE TABLE IF NOT EXISTS ipca_compliance_finding_manual_links (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  finding_id          BIGINT UNSIGNED NOT NULL,
  manual_kind         VARCHAR(32) NOT NULL
                        COMMENT 'rl_edition | rl_block | easa_node | om_canonical | omm_canonical | external_doc',
  manual_ref_id       VARCHAR(191) NOT NULL COMMENT 'PK of the manual row / canonical ref',
  manual_label        VARCHAR(255) NULL COMMENT 'Cached human label e.g. "OM Part A §3.4.2"',
  section_path        VARCHAR(1024) NULL COMMENT 'Cached breadcrumb path',
  link_type           VARCHAR(16) NOT NULL DEFAULT 'PRIMARY'
                        COMMENT 'PRIMARY | SUPPORTING',
  notes               TEXT NULL,
  created_by          INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcafml (finding_id, manual_kind, manual_ref_id),
  KEY idx_ipcafml_manual (manual_kind, manual_ref_id),
  CONSTRAINT fk_ipcafml_finding FOREIGN KEY (finding_id)
    REFERENCES ipca_compliance_findings (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — polymorphic finding→manual section links (bridges to canonical manual tables).';


CREATE TABLE IF NOT EXISTS ipca_compliance_finding_mccf_links (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  finding_id          BIGINT UNSIGNED NOT NULL,
  requirement_key     VARCHAR(128) NOT NULL COMMENT 'MCCF requirement_key (canonical, kept as text for forward-compat)',
  manual_code         VARCHAR(32) NULL COMMENT 'e.g. OM | OMM (cached for filtering)',
  mccf_subject        VARCHAR(255) NULL COMMENT 'Cached MCCF subject for display',
  link_type           VARCHAR(16) NOT NULL DEFAULT 'PRIMARY'
                        COMMENT 'PRIMARY | SUPPORTING',
  notes               TEXT NULL,
  created_by          INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcafmccf (finding_id, requirement_key),
  KEY idx_ipcafmccf_req (requirement_key),
  CONSTRAINT fk_ipcafmccf_finding FOREIGN KEY (finding_id)
    REFERENCES ipca_compliance_findings (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — finding→MCCF requirement links (no FK to MCCF: kept by stable requirement_key).';


-- =============================================================================
-- SECTION D. RCA / CAP
-- =============================================================================

CREATE TABLE IF NOT EXISTS ipca_compliance_finding_rca (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  finding_id          BIGINT UNSIGNED NOT NULL,
  method              VARCHAR(32) NOT NULL DEFAULT 'FIVE_WHYS'
                        COMMENT 'FIVE_WHYS | FISHBONE | FAULT_TREE | OTHER',
  steps_json          JSON NOT NULL COMMENT 'Ordered RCA chain (e.g. 5-Whys array)',
  root_cause_text     TEXT NULL COMMENT 'Final root cause statement',
  ai_assisted         TINYINT(1) NOT NULL DEFAULT 0,
  ai_run_id           BIGINT UNSIGNED NULL COMMENT 'Optional FK into ipca_compliance_ai_runs.id',
  approved_by         INT UNSIGNED NULL,
  approved_by_name    VARCHAR(255) NULL,
  approved_at         DATETIME NULL,
  locked_at           DATETIME NULL COMMENT 'When set, RCA becomes immutable',
  locked_by           INT UNSIGNED NULL,
  lock_reason         VARCHAR(255) NULL,
  created_by          INT UNSIGNED NULL,
  updated_by          INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcarca_finding (finding_id),
  KEY idx_ipcarca_locked (locked_at),
  CONSTRAINT fk_ipcarca_finding FOREIGN KEY (finding_id)
    REFERENCES ipca_compliance_findings (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — root-cause analysis per finding. One row per finding; immutable once locked.';


CREATE TABLE IF NOT EXISTS ipca_compliance_corrective_actions (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  finding_id          BIGINT UNSIGNED NOT NULL,
  case_id             BIGINT UNSIGNED NULL,
  action_code         VARCHAR(64) NOT NULL COMMENT 'e.g. CAP-2026-077',
  action_type         VARCHAR(32) NOT NULL DEFAULT 'CORRECTIVE'
                        COMMENT 'CORRECTIVE | PREVENTIVE | CONTAINMENT | IMMEDIATE',
  title               VARCHAR(255) NOT NULL,
  description         TEXT NOT NULL,
  status              VARCHAR(32) NOT NULL DEFAULT 'PROPOSED'
                        COMMENT 'PROPOSED | APPROVED | IN_PROGRESS | COMPLETED | VERIFIED | INEFFECTIVE | CANCELLED',
  effort              VARCHAR(16) NULL COMMENT 'XS | S | M | L | XL',
  responsible_user_id INT UNSIGNED NULL,
  responsible_name    VARCHAR(255) NULL COMMENT 'Optional cached name when no platform user',
  due_date            DATE NULL,
  started_at          DATETIME NULL,
  completed_at        DATETIME NULL,
  verified_at         DATETIME NULL,
  verified_by         INT UNSIGNED NULL,
  ai_assisted         TINYINT(1) NOT NULL DEFAULT 0,
  ai_run_id           BIGINT UNSIGNED NULL,
  approved_by         INT UNSIGNED NULL,
  approved_by_name    VARCHAR(255) NULL,
  approved_at         DATETIME NULL,
  locked_at           DATETIME NULL,
  locked_by           INT UNSIGNED NULL,
  created_by          INT UNSIGNED NULL,
  updated_by          INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcacap_code (action_code),
  KEY idx_ipcacap_finding (finding_id),
  KEY idx_ipcacap_case (case_id),
  KEY idx_ipcacap_status_due (status, due_date),
  KEY idx_ipcacap_responsible (responsible_user_id, status),
  CONSTRAINT fk_ipcacap_finding FOREIGN KEY (finding_id)
    REFERENCES ipca_compliance_findings (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipcacap_case FOREIGN KEY (case_id)
    REFERENCES ipca_compliance_cases (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — Corrective Action Plan items per finding.';


CREATE TABLE IF NOT EXISTS ipca_compliance_cap_evidence (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  corrective_action_id    BIGINT UNSIGNED NOT NULL,
  evidence_kind           VARCHAR(32) NOT NULL DEFAULT 'DOCUMENT'
                            COMMENT 'DOCUMENT | PHOTO | EMAIL | RECORDING | EXTERNAL_LINK | NOTE',
  storage_relpath         VARCHAR(1024) NULL,
  external_url            VARCHAR(2048) NULL,
  title                   VARCHAR(255) NULL,
  description             TEXT NULL,
  mime_type               VARCHAR(128) NULL,
  file_size               BIGINT UNSIGNED NULL,
  sha256                  CHAR(64) NULL,
  uploaded_by             INT UNSIGNED NULL,
  uploaded_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipcacapev_action_kind (corrective_action_id, evidence_kind),
  KEY idx_ipcacapev_sha (sha256),
  CONSTRAINT fk_ipcacapev_action FOREIGN KEY (corrective_action_id)
    REFERENCES ipca_compliance_corrective_actions (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — evidence attached to a corrective action (proves completion).';


CREATE TABLE IF NOT EXISTS ipca_compliance_effectiveness_reviews (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  corrective_action_id    BIGINT UNSIGNED NOT NULL,
  reviewed_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by             INT UNSIGNED NULL,
  reviewer_name           VARCHAR(255) NULL,
  effectiveness           VARCHAR(32) NOT NULL DEFAULT 'NOT_EVALUATED'
                            COMMENT 'NOT_EVALUATED | EFFECTIVE | PARTIALLY_EFFECTIVE | INEFFECTIVE',
  rationale               TEXT NULL,
  next_review_due         DATE NULL,
  approved_by             INT UNSIGNED NULL,
  approved_by_name        VARCHAR(255) NULL,
  approved_at             DATETIME NULL,
  locked_at               DATETIME NULL,
  locked_by               INT UNSIGNED NULL,
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipcaeff_action_time (corrective_action_id, reviewed_at),
  KEY idx_ipcaeff_next_due (next_review_due),
  CONSTRAINT fk_ipcaeff_action FOREIGN KEY (corrective_action_id)
    REFERENCES ipca_compliance_corrective_actions (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — formal effectiveness assessment of a corrective action.';


CREATE TABLE IF NOT EXISTS ipca_compliance_ai_runs (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_object_type      VARCHAR(32) NOT NULL
                            COMMENT 'finding | corrective_action | audit | meeting | inbound_email | manual_change_request | regulatory_search | other',
  source_object_id        BIGINT UNSIGNED NULL,
  run_type                VARCHAR(32) NOT NULL
                            COMMENT 'RCA_SUGGEST | CAP_SUGGEST | RCA_AND_CAP | SUMMARY | REG_MATCH | MANUAL_DIFF | EMAIL_TRIAGE | MEETING_SUMMARY | OTHER',
  status                  VARCHAR(16) NOT NULL DEFAULT 'OK'
                            COMMENT 'OK | ERROR | TIMEOUT | RATE_LIMITED | CANCELLED',
  model                   VARCHAR(64) NULL COMMENT 'Effective model id e.g. gpt-5.4',
  prompt_text             LONGTEXT NULL COMMENT 'Full prompt when storage-safe; otherwise NULL with prompt_hash set',
  prompt_hash             CHAR(64) NULL COMMENT 'sha256 of prompt; used when prompt is sensitive / oversized',
  evidence_snapshot_json  JSON NULL COMMENT 'Frozen snapshot of input evidence (linked excerpts, finding state, etc.)',
  response_json           JSON NULL COMMENT 'Full provider response payload (parsed JSON or wrapped string)',
  response_text           LONGTEXT NULL COMMENT 'Convenience plaintext copy of the main response',
  latency_ms              INT UNSIGNED NULL,
  cost_usd                DECIMAL(10,4) NULL,
  error_message           TEXT NULL,
  created_by              INT UNSIGNED NULL,
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipcaai_source (source_object_type, source_object_id, created_at),
  KEY idx_ipcaai_runtype_time (run_type, created_at),
  KEY idx_ipcaai_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — centralised log of every AI invocation in the compliance domain.';


-- =============================================================================
-- SECTION E. CHECKLISTS
-- =============================================================================
-- Versioned checklist templates. A version goes DRAFT → APPROVED (locked).
-- When an audit runs against an approved version we generate an immutable
-- snapshot row that freezes the items as JSON so subsequent edits to the
-- template cannot drift the answers historically.

CREATE TABLE IF NOT EXISTS ipca_compliance_checklist_templates (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template_code   VARCHAR(64) NOT NULL,
  title           VARCHAR(255) NOT NULL,
  description     TEXT NULL,
  authority       VARCHAR(32) NULL COMMENT 'BCAA | FAA | EASA | INTERNAL | OTHER',
  scope_tags      VARCHAR(255) NULL COMMENT 'CSV of tags for filtering (e.g. OPS,SAFETY,FCL)',
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  current_version_id BIGINT UNSIGNED NULL COMMENT 'Pointer to current approved version (set via service)',
  created_by      INT UNSIGNED NULL,
  updated_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcaclt_code (template_code),
  KEY idx_ipcaclt_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — checklist template root.';


CREATE TABLE IF NOT EXISTS ipca_compliance_checklist_versions (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template_id         BIGINT UNSIGNED NOT NULL,
  version_no          INT UNSIGNED NOT NULL,
  status              VARCHAR(32) NOT NULL DEFAULT 'DRAFT'
                        COMMENT 'DRAFT | PENDING_APPROVAL | APPROVED | ARCHIVED',
  description         TEXT NULL,
  items_count         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Denormalised count of items at lock time',
  approved_by         INT UNSIGNED NULL,
  approved_by_name    VARCHAR(255) NULL,
  approved_at         DATETIME NULL,
  locked_at           DATETIME NULL COMMENT 'When set, no item edits permitted',
  locked_by           INT UNSIGNED NULL,
  created_by          INT UNSIGNED NULL,
  updated_by          INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcaclv_template_version (template_id, version_no),
  KEY idx_ipcaclv_status (status),
  CONSTRAINT fk_ipcaclv_template FOREIGN KEY (template_id)
    REFERENCES ipca_compliance_checklist_templates (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — versioned snapshot of a checklist template.';


CREATE TABLE IF NOT EXISTS ipca_compliance_checklist_items (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  version_id          BIGINT UNSIGNED NOT NULL,
  item_code           VARCHAR(64) NULL COMMENT 'Stable code within version (e.g. 1.2.3)',
  parent_item_id      BIGINT UNSIGNED NULL,
  sort_order          INT UNSIGNED NOT NULL DEFAULT 0,
  item_type           VARCHAR(32) NOT NULL DEFAULT 'QUESTION'
                        COMMENT 'SECTION | QUESTION | MULTI_CHOICE | YES_NO | NUMERIC | TEXT | EVIDENCE_UPLOAD',
  prompt              TEXT NOT NULL,
  guidance            TEXT NULL COMMENT 'Auditor guidance / acceptance criteria',
  is_required         TINYINT(1) NOT NULL DEFAULT 1,
  weight              SMALLINT UNSIGNED NULL,
  options_json        JSON NULL COMMENT 'For MULTI_CHOICE etc.',
  reg_refs_json       JSON NULL COMMENT 'Cached regulatory anchors for this item',
  manual_refs_json    JSON NULL COMMENT 'Cached manual anchors for this item',
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcacli_version_code (version_id, item_code),
  KEY idx_ipcacli_version_sort (version_id, sort_order),
  KEY idx_ipcacli_parent (parent_item_id),
  CONSTRAINT fk_ipcacli_version FOREIGN KEY (version_id)
    REFERENCES ipca_compliance_checklist_versions (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipcacli_parent FOREIGN KEY (parent_item_id)
    REFERENCES ipca_compliance_checklist_items (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — items inside a checklist version (locked alongside the version).';


CREATE TABLE IF NOT EXISTS ipca_compliance_audit_checklist_snapshots (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  audit_id                BIGINT UNSIGNED NOT NULL,
  template_id             BIGINT UNSIGNED NOT NULL,
  version_id              BIGINT UNSIGNED NOT NULL,
  items_snapshot_json     JSON NOT NULL COMMENT 'Frozen copy of items at snapshot time — legal record',
  items_snapshot_sha256   CHAR(64) NOT NULL COMMENT 'sha256 of items_snapshot_json (integrity check)',
  status                  VARCHAR(32) NOT NULL DEFAULT 'OPEN'
                            COMMENT 'OPEN | IN_PROGRESS | COMPLETED | CANCELLED',
  generated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  generated_by            INT UNSIGNED NULL,
  locked_at               DATETIME NULL,
  locked_by               INT UNSIGNED NULL,
  approved_by             INT UNSIGNED NULL,
  approved_by_name        VARCHAR(255) NULL,
  approved_at             DATETIME NULL,
  UNIQUE KEY uk_ipcaacs_audit_template (audit_id, template_id),
  KEY idx_ipcaacs_version (version_id),
  KEY idx_ipcaacs_status (status),
  CONSTRAINT fk_ipcaacs_audit FOREIGN KEY (audit_id)
    REFERENCES ipca_compliance_audits (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipcaacs_template FOREIGN KEY (template_id)
    REFERENCES ipca_compliance_checklist_templates (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipcaacs_version FOREIGN KEY (version_id)
    REFERENCES ipca_compliance_checklist_versions (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — immutable per-audit snapshot of a checklist version (prevents historical drift).';


CREATE TABLE IF NOT EXISTS ipca_compliance_audit_checklist_answers (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  snapshot_id             BIGINT UNSIGNED NOT NULL,
  item_id                 BIGINT UNSIGNED NOT NULL COMMENT 'References ipca_compliance_checklist_items.id',
  answer_value            VARCHAR(64) NULL COMMENT 'Short value (yes/no, choice key, number)',
  answer_text             LONGTEXT NULL,
  answer_payload_json     JSON NULL COMMENT 'Structured payload for complex answers',
  compliance_state        VARCHAR(32) NULL
                            COMMENT 'COMPLIANT | NON_COMPLIANT | OBSERVATION | N_A | PARTIAL | PENDING',
  finding_id              BIGINT UNSIGNED NULL COMMENT 'Optional auto-raised finding for non-compliance',
  answered_by             INT UNSIGNED NULL,
  answered_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by             INT UNSIGNED NULL,
  reviewed_at             DATETIME NULL,
  evidence_count          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_at               DATETIME NULL,
  locked_by               INT UNSIGNED NULL,
  UNIQUE KEY uk_ipcaaca_snapshot_item (snapshot_id, item_id),
  KEY idx_ipcaaca_state (compliance_state),
  KEY idx_ipcaaca_finding (finding_id),
  CONSTRAINT fk_ipcaaca_snapshot FOREIGN KEY (snapshot_id)
    REFERENCES ipca_compliance_audit_checklist_snapshots (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipcaaca_item FOREIGN KEY (item_id)
    REFERENCES ipca_compliance_checklist_items (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipcaaca_finding FOREIGN KEY (finding_id)
    REFERENCES ipca_compliance_findings (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — auditor responses against a checklist snapshot.';


-- =============================================================================
-- SECTION F. MEETINGS
-- =============================================================================

CREATE TABLE IF NOT EXISTS ipca_compliance_meetings (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  case_id             BIGINT UNSIGNED NULL,
  audit_id            BIGINT UNSIGNED NULL,
  meeting_code        VARCHAR(64) NOT NULL COMMENT 'e.g. MTG-2026-031',
  title               VARCHAR(255) NOT NULL,
  meeting_type        VARCHAR(32) NOT NULL DEFAULT 'AUDIT_REVIEW'
                        COMMENT 'AUDIT_OPENING | AUDIT_CLOSING | AUDIT_REVIEW | MGMT_REVIEW | SAFETY_REVIEW | OTHER',
  status              VARCHAR(32) NOT NULL DEFAULT 'SCHEDULED'
                        COMMENT 'SCHEDULED | LIVE | COMPLETED | CANCELLED',
  scheduled_start     DATETIME NULL,
  scheduled_end       DATETIME NULL,
  actual_start        DATETIME NULL,
  actual_end          DATETIME NULL,
  location            VARCHAR(255) NULL,
  agenda              TEXT NULL,
  chair_user_id       INT UNSIGNED NULL,
  scribe_user_id      INT UNSIGNED NULL,
  created_by          INT UNSIGNED NULL,
  updated_by          INT UNSIGNED NULL,
  locked_at           DATETIME NULL,
  locked_by           INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcamtg_code (meeting_code),
  KEY idx_ipcamtg_case (case_id),
  KEY idx_ipcamtg_audit (audit_id),
  KEY idx_ipcamtg_status_start (status, scheduled_start),
  CONSTRAINT fk_ipcamtg_case FOREIGN KEY (case_id)
    REFERENCES ipca_compliance_cases (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipcamtg_audit FOREIGN KEY (audit_id)
    REFERENCES ipca_compliance_audits (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — compliance meeting record.';


CREATE TABLE IF NOT EXISTS ipca_compliance_meeting_attendees (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  meeting_id      BIGINT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NULL,
  display_name    VARCHAR(255) NULL COMMENT 'Required when user_id is NULL',
  email           VARCHAR(255) NULL,
  organisation    VARCHAR(255) NULL,
  attendee_role   VARCHAR(32) NULL
                    COMMENT 'CHAIR | SCRIBE | AUDITOR | AUDITEE | AUTHORITY | OBSERVER | EXPERT',
  rsvp_state      VARCHAR(16) NULL COMMENT 'INVITED | ACCEPTED | DECLINED | TENTATIVE',
  attended        TINYINT(1) NOT NULL DEFAULT 0,
  attended_at     DATETIME NULL,
  notes           TEXT NULL,
  KEY idx_ipcamta_meeting (meeting_id),
  KEY idx_ipcamta_user (user_id),
  CONSTRAINT fk_ipcamta_meeting FOREIGN KEY (meeting_id)
    REFERENCES ipca_compliance_meetings (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — meeting attendees (mix of platform users + external).';


CREATE TABLE IF NOT EXISTS ipca_compliance_meeting_recordings (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  meeting_id          BIGINT UNSIGNED NOT NULL,
  storage_relpath     VARCHAR(1024) NOT NULL,
  original_name       VARCHAR(512) NULL,
  mime_type           VARCHAR(128) NULL,
  file_size           BIGINT UNSIGNED NULL,
  duration_seconds    INT UNSIGNED NULL,
  sha256              CHAR(64) NULL,
  uploaded_by         INT UNSIGNED NULL,
  uploaded_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipcamtr_meeting (meeting_id),
  KEY idx_ipcamtr_sha (sha256),
  CONSTRAINT fk_ipcamtr_meeting FOREIGN KEY (meeting_id)
    REFERENCES ipca_compliance_meetings (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — uploaded meeting recordings (audio/video).';


CREATE TABLE IF NOT EXISTS ipca_compliance_meeting_transcripts (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  meeting_id          BIGINT UNSIGNED NOT NULL,
  recording_id        BIGINT UNSIGNED NULL,
  language            VARCHAR(16) NULL DEFAULT 'en',
  source              VARCHAR(32) NOT NULL DEFAULT 'AUTO'
                        COMMENT 'AUTO | HUMAN | MIXED',
  transcript_text     LONGTEXT NOT NULL,
  segments_json       JSON NULL COMMENT 'Optional timestamped segments / speaker turns',
  word_count          INT UNSIGNED NULL,
  approved_by         INT UNSIGNED NULL,
  approved_by_name    VARCHAR(255) NULL,
  approved_at         DATETIME NULL,
  locked_at           DATETIME NULL,
  locked_by           INT UNSIGNED NULL,
  ai_run_id           BIGINT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ipcamtt_meeting (meeting_id),
  KEY idx_ipcamtt_recording (recording_id),
  FULLTEXT KEY ft_ipcamtt_text (transcript_text),
  CONSTRAINT fk_ipcamtt_meeting FOREIGN KEY (meeting_id)
    REFERENCES ipca_compliance_meetings (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipcamtt_recording FOREIGN KEY (recording_id)
    REFERENCES ipca_compliance_meeting_recordings (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — meeting transcript (immutable once approved).';


CREATE TABLE IF NOT EXISTS ipca_compliance_meeting_summaries (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  meeting_id          BIGINT UNSIGNED NOT NULL,
  summary_text        LONGTEXT NOT NULL,
  highlights_json     JSON NULL COMMENT 'Structured highlights / topics',
  ai_assisted         TINYINT(1) NOT NULL DEFAULT 0,
  ai_run_id           BIGINT UNSIGNED NULL,
  approved_by         INT UNSIGNED NULL,
  approved_by_name    VARCHAR(255) NULL,
  approved_at         DATETIME NULL,
  locked_at           DATETIME NULL,
  locked_by           INT UNSIGNED NULL,
  created_by          INT UNSIGNED NULL,
  updated_by          INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ipcamts_meeting (meeting_id),
  CONSTRAINT fk_ipcamts_meeting FOREIGN KEY (meeting_id)
    REFERENCES ipca_compliance_meetings (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — narrative meeting summary (AI-assisted or human).';


CREATE TABLE IF NOT EXISTS ipca_compliance_meeting_decisions (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  meeting_id          BIGINT UNSIGNED NOT NULL,
  decision_text       TEXT NOT NULL,
  decision_kind       VARCHAR(32) NULL
                        COMMENT 'POLICY | APPROVAL | DIRECTION | ACCEPTANCE | OTHER',
  decided_by          INT UNSIGNED NULL,
  decided_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  rationale           TEXT NULL,
  related_finding_id  BIGINT UNSIGNED NULL,
  related_action_id   BIGINT UNSIGNED NULL,
  locked_at           DATETIME NULL,
  locked_by           INT UNSIGNED NULL,
  KEY idx_ipcamtd_meeting (meeting_id),
  KEY idx_ipcamtd_finding (related_finding_id),
  KEY idx_ipcamtd_action (related_action_id),
  CONSTRAINT fk_ipcamtd_meeting FOREIGN KEY (meeting_id)
    REFERENCES ipca_compliance_meetings (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipcamtd_finding FOREIGN KEY (related_finding_id)
    REFERENCES ipca_compliance_findings (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipcamtd_action FOREIGN KEY (related_action_id)
    REFERENCES ipca_compliance_corrective_actions (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — formal decisions recorded during a meeting.';


CREATE TABLE IF NOT EXISTS ipca_compliance_meeting_actions (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  meeting_id          BIGINT UNSIGNED NOT NULL,
  title               VARCHAR(255) NOT NULL,
  description         TEXT NULL,
  status              VARCHAR(32) NOT NULL DEFAULT 'OPEN'
                        COMMENT 'OPEN | IN_PROGRESS | DONE | CANCELLED',
  responsible_user_id INT UNSIGNED NULL,
  responsible_name    VARCHAR(255) NULL,
  due_date            DATE NULL,
  completed_at        DATETIME NULL,
  promoted_to_action_id BIGINT UNSIGNED NULL COMMENT 'If promoted to formal CAP',
  created_by          INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ipcamta2_meeting (meeting_id),
  KEY idx_ipcamta2_status_due (status, due_date),
  KEY idx_ipcamta2_responsible (responsible_user_id, status),
  CONSTRAINT fk_ipcamta2_meeting FOREIGN KEY (meeting_id)
    REFERENCES ipca_compliance_meetings (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipcamta2_promoted FOREIGN KEY (promoted_to_action_id)
    REFERENCES ipca_compliance_corrective_actions (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — informal action items captured in a meeting (may be promoted to CAPs).';


CREATE TABLE IF NOT EXISTS ipca_compliance_meeting_links (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  meeting_id      BIGINT UNSIGNED NOT NULL,
  entity_type     VARCHAR(32) NOT NULL
                    COMMENT 'finding | corrective_action | audit | manual_change_request | inbound_email | is_incident | other',
  entity_id       BIGINT UNSIGNED NOT NULL,
  relation        VARCHAR(32) NULL COMMENT 'AGENDA_ITEM | DISCUSSED | EVIDENCE | ACTION_FOLLOWUP',
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcamtl (meeting_id, entity_type, entity_id),
  KEY idx_ipcamtl_entity (entity_type, entity_id),
  CONSTRAINT fk_ipcamtl_meeting FOREIGN KEY (meeting_id)
    REFERENCES ipca_compliance_meetings (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — polymorphic links from a meeting to other compliance entities.';


-- =============================================================================
-- SECTION G. INBOX (Inbound email — Phase 7, schema scaffolded now)
-- =============================================================================

CREATE TABLE IF NOT EXISTS ipca_compliance_inbound_emails (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  message_id              VARCHAR(255) NULL COMMENT 'RFC822 Message-Id header',
  in_reply_to             VARCHAR(255) NULL,
  thread_key              VARCHAR(191) NULL COMMENT 'Provider thread id or normalised subject hash',
  provider                VARCHAR(32) NOT NULL DEFAULT 'POSTMARK'
                            COMMENT 'POSTMARK | MAILGUN | SES | IMAP | OTHER',
  provider_message_id     VARCHAR(255) NULL,
  from_email              VARCHAR(255) NOT NULL,
  from_name               VARCHAR(255) NULL,
  to_email                VARCHAR(255) NULL,
  cc_emails               TEXT NULL,
  subject                 VARCHAR(512) NULL,
  body_text               LONGTEXT NULL,
  body_html               LONGTEXT NULL,
  received_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  headers_json            JSON NULL,
  raw_storage_relpath     VARCHAR(1024) NULL COMMENT 'Original raw eml file path',
  classification          VARCHAR(32) NULL
                            COMMENT 'AUTHORITY | INTERNAL | SUPPLIER | UNKNOWN | SPAM',
  triage_state            VARCHAR(32) NOT NULL DEFAULT 'NEW'
                            COMMENT 'NEW | IN_REVIEW | ACTIONED | ARCHIVED | IGNORED',
  case_id                 BIGINT UNSIGNED NULL,
  audit_id                BIGINT UNSIGNED NULL,
  finding_id              BIGINT UNSIGNED NULL,
  assigned_to             INT UNSIGNED NULL,
  ai_summary_run_id       BIGINT UNSIGNED NULL,
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcaie_provider_msg (provider, provider_message_id),
  KEY idx_ipcaie_received (received_at),
  KEY idx_ipcaie_triage (triage_state, received_at),
  KEY idx_ipcaie_case (case_id),
  KEY idx_ipcaie_audit (audit_id),
  KEY idx_ipcaie_finding (finding_id),
  KEY idx_ipcaie_from (from_email),
  KEY idx_ipcaie_thread (thread_key),
  CONSTRAINT fk_ipcaie_case FOREIGN KEY (case_id)
    REFERENCES ipca_compliance_cases (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipcaie_audit FOREIGN KEY (audit_id)
    REFERENCES ipca_compliance_audits (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipcaie_finding FOREIGN KEY (finding_id)
    REFERENCES ipca_compliance_findings (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — inbound emails (authority correspondence, supplier mail, etc.).';


CREATE TABLE IF NOT EXISTS ipca_compliance_inbound_email_attachments (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email_id        BIGINT UNSIGNED NOT NULL,
  storage_relpath VARCHAR(1024) NOT NULL,
  original_name   VARCHAR(512) NOT NULL,
  mime_type       VARCHAR(128) NOT NULL,
  file_size       BIGINT UNSIGNED NULL,
  sha256          CHAR(64) NULL,
  uploaded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipcaiea_email (email_id),
  KEY idx_ipcaiea_sha (sha256),
  CONSTRAINT fk_ipcaiea_email FOREIGN KEY (email_id)
    REFERENCES ipca_compliance_inbound_emails (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — files extracted from inbound emails.';


CREATE TABLE IF NOT EXISTS ipca_compliance_email_links (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email_id        BIGINT UNSIGNED NOT NULL,
  entity_type     VARCHAR(32) NOT NULL
                    COMMENT 'audit | finding | corrective_action | meeting | manual_change_request | case | other',
  entity_id       BIGINT UNSIGNED NOT NULL,
  relation        VARCHAR(32) NULL COMMENT 'EVIDENCE | NOTIFICATION | RESPONSE | REFERENCE',
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcaiel (email_id, entity_type, entity_id),
  KEY idx_ipcaiel_entity (entity_type, entity_id),
  CONSTRAINT fk_ipcaiel_email FOREIGN KEY (email_id)
    REFERENCES ipca_compliance_inbound_emails (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — polymorphic links from an inbound email to other compliance entities.';


-- =============================================================================
-- SECTION H. MANUAL CONTROL (change requests, drafts, releases)
-- =============================================================================
-- Manual content itself is NOT duplicated here — that stays in
-- `resource_library_*` / `easa_erules_*`. These tables describe the
-- governance lifecycle around proposing, drafting and releasing manual
-- changes, plus the link back to canonical manual sections.

CREATE TABLE IF NOT EXISTS ipca_compliance_manual_change_requests (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  case_id             BIGINT UNSIGNED NULL,
  request_code        VARCHAR(64) NOT NULL,
  title               VARCHAR(255) NOT NULL,
  description         TEXT NOT NULL,
  manual_kind         VARCHAR(32) NOT NULL
                        COMMENT 'rl_edition | rl_block | easa_node | om_canonical | omm_canonical | external_doc',
  manual_ref_id       VARCHAR(191) NULL COMMENT 'PK of the manual row / canonical ref this CR targets',
  manual_label        VARCHAR(255) NULL,
  proposed_text       LONGTEXT NULL COMMENT 'Proposed replacement / addition body',
  rationale           TEXT NULL,
  status              VARCHAR(32) NOT NULL DEFAULT 'DRAFT'
                        COMMENT 'DRAFT | SUBMITTED | UNDER_REVIEW | APPROVED | REJECTED | RELEASED | CANCELLED',
  priority            VARCHAR(16) NOT NULL DEFAULT 'NORMAL'
                        COMMENT 'LOW | NORMAL | HIGH | URGENT',
  raised_by           INT UNSIGNED NULL,
  raised_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_by         INT UNSIGNED NULL,
  approved_by_name    VARCHAR(255) NULL,
  approved_at         DATETIME NULL,
  released_at         DATETIME NULL,
  locked_at           DATETIME NULL,
  locked_by           INT UNSIGNED NULL,
  ai_suggested        TINYINT(1) NOT NULL DEFAULT 0,
  ai_run_id           BIGINT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcamcr_code (request_code),
  KEY idx_ipcamcr_status (status),
  KEY idx_ipcamcr_target (manual_kind, manual_ref_id),
  KEY idx_ipcamcr_case (case_id),
  CONSTRAINT fk_ipcamcr_case FOREIGN KEY (case_id)
    REFERENCES ipca_compliance_cases (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — manual change requests (governance only, no manual content duplication).';


CREATE TABLE IF NOT EXISTS ipca_compliance_manual_change_request_links (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_id      BIGINT UNSIGNED NOT NULL,
  entity_type     VARCHAR(32) NOT NULL
                    COMMENT 'finding | audit | corrective_action | meeting | inbound_email | regulation_link | manual_link | other',
  entity_id       BIGINT UNSIGNED NULL,
  external_ref    VARCHAR(255) NULL COMMENT 'For non-id targets such as regulation URL',
  relation        VARCHAR(32) NULL
                    COMMENT 'CAUSED_BY | RESPONDS_TO | EVIDENCE | SUPERSEDES | SUPERSEDED_BY',
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipcamcrl_request (request_id),
  KEY idx_ipcamcrl_entity (entity_type, entity_id),
  CONSTRAINT fk_ipcamcrl_request FOREIGN KEY (request_id)
    REFERENCES ipca_compliance_manual_change_requests (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — polymorphic links from a manual change request to triggering entities.';


CREATE TABLE IF NOT EXISTS ipca_compliance_manual_drafts (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_id          BIGINT UNSIGNED NULL COMMENT 'Originating change request (may be NULL for direct drafts)',
  draft_code          VARCHAR(64) NOT NULL,
  manual_kind         VARCHAR(32) NOT NULL
                        COMMENT 'rl_edition | rl_block | easa_node | om_canonical | omm_canonical | external_doc',
  manual_ref_id       VARCHAR(191) NULL,
  manual_label        VARCHAR(255) NULL,
  draft_title         VARCHAR(255) NOT NULL,
  draft_body          LONGTEXT NOT NULL,
  diff_against_ref    VARCHAR(64) NULL COMMENT 'Optional ref/version we are diffing against',
  diff_json           JSON NULL COMMENT 'Structured diff (added/removed/changed blocks)',
  status              VARCHAR(32) NOT NULL DEFAULT 'DRAFT'
                        COMMENT 'DRAFT | UNDER_REVIEW | APPROVED | REJECTED | PUBLISHED | ARCHIVED',
  version_no          INT UNSIGNED NOT NULL DEFAULT 1,
  ai_assisted         TINYINT(1) NOT NULL DEFAULT 0,
  ai_run_id           BIGINT UNSIGNED NULL,
  created_by          INT UNSIGNED NULL,
  updated_by          INT UNSIGNED NULL,
  approved_by         INT UNSIGNED NULL,
  approved_by_name    VARCHAR(255) NULL,
  approved_at         DATETIME NULL,
  locked_at           DATETIME NULL,
  locked_by           INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcamd_code (draft_code),
  KEY idx_ipcamd_request (request_id),
  KEY idx_ipcamd_status (status),
  KEY idx_ipcamd_target (manual_kind, manual_ref_id),
  CONSTRAINT fk_ipcamd_request FOREIGN KEY (request_id)
    REFERENCES ipca_compliance_manual_change_requests (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — draft manual sections (proposed content prior to release).';


CREATE TABLE IF NOT EXISTS ipca_compliance_manual_release_packages (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  package_code        VARCHAR(64) NOT NULL,
  title               VARCHAR(255) NOT NULL,
  manual_code         VARCHAR(32) NULL COMMENT 'e.g. OM | OMM | EASA-IR | INTERNAL',
  target_revision     VARCHAR(32) NULL,
  effective_date      DATE NULL,
  status              VARCHAR(32) NOT NULL DEFAULT 'PLANNED'
                        COMMENT 'PLANNED | DRAFTING | REVIEW | APPROVED | RELEASED | SUPERSEDED | CANCELLED',
  drafts_json         JSON NULL COMMENT 'Snapshot list of draft_ids + draft_code at package lock time',
  pdf_storage_relpath VARCHAR(1024) NULL,
  pdf_sha256          CHAR(64) NULL,
  released_by         INT UNSIGNED NULL,
  released_by_name    VARCHAR(255) NULL,
  released_at         DATETIME NULL,
  locked_at           DATETIME NULL,
  locked_by           INT UNSIGNED NULL,
  created_by          INT UNSIGNED NULL,
  updated_by          INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcamrp_code (package_code),
  KEY idx_ipcamrp_status_effective (status, effective_date),
  KEY idx_ipcamrp_manual (manual_code, target_revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — release package bundling approved manual drafts. Locked rows are immutable.';


CREATE TABLE IF NOT EXISTS ipca_compliance_manual_release_approvals (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  package_id          BIGINT UNSIGNED NOT NULL,
  approver_user_id    INT UNSIGNED NULL,
  approver_name       VARCHAR(255) NOT NULL,
  approver_role       VARCHAR(64) NULL
                        COMMENT 'ACCOUNTABLE_MANAGER | QUALITY_MANAGER | COMPLIANCE_OFFICER | LEGAL | OTHER',
  decision            VARCHAR(32) NOT NULL
                        COMMENT 'PENDING | APPROVED | REJECTED | RECUSED',
  comments            TEXT NULL,
  decided_at          DATETIME NULL,
  signature_hash      CHAR(64) NULL COMMENT 'Optional cryptographic attestation of approval',
  locked_at           DATETIME NULL,
  locked_by           INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipcamra_package_decision (package_id, decision),
  KEY idx_ipcamra_approver (approver_user_id),
  CONSTRAINT fk_ipcamra_package FOREIGN KEY (package_id)
    REFERENCES ipca_compliance_manual_release_packages (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — multi-sign-off approval rows for a release package.';


-- =============================================================================
-- SECTION I. MONITORING
-- =============================================================================
-- Uses the existing platform automation runtime (automation_flows /
-- automation_flow_runs / automation_flow_actions) for event dispatch.
-- These tables capture compliance-specific monitor RULE DEFINITIONS and
-- their evaluation results, separate from automation flows themselves.

CREATE TABLE IF NOT EXISTS ipca_compliance_monitor_rules (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  rule_code           VARCHAR(64) NOT NULL,
  title               VARCHAR(255) NOT NULL,
  description         TEXT NULL,
  monitor_kind        VARCHAR(32) NOT NULL DEFAULT 'CAP'
                        COMMENT 'CAP | FSTD | SAFETY | CYBER | LIVE | REGULATORY | OTHER',
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  cadence             VARCHAR(32) NULL COMMENT 'EVENT | HOURLY | DAILY | WEEKLY | MONTHLY | CRON',
  cron_expression     VARCHAR(64) NULL COMMENT 'When cadence=CRON',
  event_key           VARCHAR(128) NULL
                        COMMENT 'When cadence=EVENT — references automation_flows.event_key (e.g. compliance.cap.overdue)',
  scope_json          JSON NULL COMMENT 'Filter scope (authorities, areas, asset ids, etc.)',
  threshold_json      JSON NULL COMMENT 'Threshold parameters (overdue days, score, etc.)',
  alert_severity      VARCHAR(16) NOT NULL DEFAULT 'MEDIUM'
                        COMMENT 'LOW | MEDIUM | HIGH | CRITICAL',
  notification_keys   VARCHAR(255) NULL COMMENT 'CSV of notification_template keys to fire on hit',
  created_by          INT UNSIGNED NULL,
  updated_by          INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcamr_code (rule_code),
  KEY idx_ipcamr_kind_active (monitor_kind, is_active),
  KEY idx_ipcamr_event (event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — definitions for compliance monitoring rules.';


CREATE TABLE IF NOT EXISTS ipca_compliance_monitor_runs (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  rule_id             BIGINT UNSIGNED NOT NULL,
  started_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at        DATETIME NULL,
  run_status          VARCHAR(16) NOT NULL DEFAULT 'RUNNING'
                        COMMENT 'RUNNING | SUCCESS | PARTIAL | FAILED',
  result_count        INT UNSIGNED NOT NULL DEFAULT 0,
  hit_count           INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of results that breached thresholds',
  trigger_source      VARCHAR(32) NULL
                        COMMENT 'CRON | EVENT | MANUAL | API',
  trigger_payload     JSON NULL,
  error_message       TEXT NULL,
  KEY idx_ipcamrun_rule_started (rule_id, started_at),
  KEY idx_ipcamrun_status (run_status),
  CONSTRAINT fk_ipcamrun_rule FOREIGN KEY (rule_id)
    REFERENCES ipca_compliance_monitor_rules (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — single evaluation pass of a monitor rule.';


CREATE TABLE IF NOT EXISTS ipca_compliance_monitor_results (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id          BIGINT UNSIGNED NOT NULL,
  rule_id         BIGINT UNSIGNED NOT NULL,
  subject_type    VARCHAR(32) NOT NULL
                    COMMENT 'finding | corrective_action | audit | meeting | inbound_email | manual_change_request | regulation_link | is_asset | other',
  subject_id      BIGINT UNSIGNED NULL,
  is_hit          TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = breached threshold',
  score           DECIMAL(8,4) NULL,
  message         TEXT NULL,
  data_json       JSON NULL COMMENT 'Raw data used in the evaluation',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipcamres_run (run_id),
  KEY idx_ipcamres_rule_hit (rule_id, is_hit),
  KEY idx_ipcamres_subject (subject_type, subject_id),
  CONSTRAINT fk_ipcamres_run FOREIGN KEY (run_id)
    REFERENCES ipca_compliance_monitor_runs (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipcamres_rule FOREIGN KEY (rule_id)
    REFERENCES ipca_compliance_monitor_rules (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — per-subject results produced by a monitor run.';


CREATE TABLE IF NOT EXISTS ipca_compliance_alerts (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  rule_id             BIGINT UNSIGNED NULL,
  result_id           BIGINT UNSIGNED NULL,
  case_id             BIGINT UNSIGNED NULL,
  subject_type        VARCHAR(32) NOT NULL,
  subject_id          BIGINT UNSIGNED NULL,
  alert_kind          VARCHAR(32) NOT NULL DEFAULT 'THRESHOLD'
                        COMMENT 'THRESHOLD | DUE_DATE | DRIFT | INTEGRITY | EXCEPTION | ANOMALY | OTHER',
  severity            VARCHAR(16) NOT NULL DEFAULT 'MEDIUM'
                        COMMENT 'LOW | MEDIUM | HIGH | CRITICAL',
  status              VARCHAR(16) NOT NULL DEFAULT 'OPEN'
                        COMMENT 'OPEN | ACKNOWLEDGED | RESOLVED | DISMISSED',
  title               VARCHAR(255) NOT NULL,
  body                TEXT NULL,
  payload_json        JSON NULL,
  raised_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  acknowledged_by     INT UNSIGNED NULL,
  acknowledged_at     DATETIME NULL,
  resolved_by         INT UNSIGNED NULL,
  resolved_at         DATETIME NULL,
  KEY idx_ipcaalt_status_severity (status, severity),
  KEY idx_ipcaalt_rule (rule_id),
  KEY idx_ipcaalt_subject (subject_type, subject_id),
  KEY idx_ipcaalt_case (case_id),
  CONSTRAINT fk_ipcaalt_rule FOREIGN KEY (rule_id)
    REFERENCES ipca_compliance_monitor_rules (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipcaalt_result FOREIGN KEY (result_id)
    REFERENCES ipca_compliance_monitor_results (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipcaalt_case FOREIGN KEY (case_id)
    REFERENCES ipca_compliance_cases (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — user-visible alert raised from a monitor result or external trigger.';


-- =============================================================================
-- SECTION J. PART-IS / DIGITAL GOVERNANCE
-- =============================================================================
-- Schema scaffolded now; populated in Phase 10. Captures the digital asset
-- inventory, risk register, security incidents, and the regulator-required
-- periodic access + supplier reviews.

CREATE TABLE IF NOT EXISTS ipca_compliance_is_assets (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asset_code          VARCHAR(64) NOT NULL,
  name                VARCHAR(255) NOT NULL,
  asset_type          VARCHAR(32) NOT NULL DEFAULT 'SYSTEM'
                        COMMENT 'SYSTEM | SERVICE | DATABASE | APPLICATION | DEVICE | PROCESS | DATA_SET | OTHER',
  criticality         VARCHAR(16) NOT NULL DEFAULT 'MEDIUM'
                        COMMENT 'LOW | MEDIUM | HIGH | CRITICAL',
  description         TEXT NULL,
  owner_user_id       INT UNSIGNED NULL,
  owner_name          VARCHAR(255) NULL,
  vendor              VARCHAR(255) NULL,
  status              VARCHAR(32) NOT NULL DEFAULT 'ACTIVE'
                        COMMENT 'ACTIVE | DEPRECATED | RETIRED',
  metadata_json       JSON NULL,
  created_by          INT UNSIGNED NULL,
  updated_by          INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcaisa_code (asset_code),
  KEY idx_ipcaisa_type_crit (asset_type, criticality),
  KEY idx_ipcaisa_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — Part-IS information-system asset inventory.';


CREATE TABLE IF NOT EXISTS ipca_compliance_is_risks (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  risk_code               VARCHAR(64) NOT NULL,
  asset_id                BIGINT UNSIGNED NULL,
  title                   VARCHAR(255) NOT NULL,
  description             TEXT NULL,
  category                VARCHAR(32) NULL
                            COMMENT 'CONFIDENTIALITY | INTEGRITY | AVAILABILITY | OPERATIONAL | LEGAL | SUPPLIER | OTHER',
  inherent_likelihood     VARCHAR(16) NULL COMMENT 'LOW | MEDIUM | HIGH | CRITICAL',
  inherent_impact         VARCHAR(16) NULL,
  residual_likelihood     VARCHAR(16) NULL,
  residual_impact         VARCHAR(16) NULL,
  risk_score              DECIMAL(6,2) NULL,
  treatment_plan          TEXT NULL,
  status                  VARCHAR(32) NOT NULL DEFAULT 'OPEN'
                            COMMENT 'OPEN | MITIGATING | ACCEPTED | TRANSFERRED | CLOSED',
  next_review_due         DATE NULL,
  approved_by             INT UNSIGNED NULL,
  approved_by_name        VARCHAR(255) NULL,
  approved_at             DATETIME NULL,
  created_by              INT UNSIGNED NULL,
  updated_by              INT UNSIGNED NULL,
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcaisr_code (risk_code),
  KEY idx_ipcaisr_asset (asset_id),
  KEY idx_ipcaisr_status_due (status, next_review_due),
  CONSTRAINT fk_ipcaisr_asset FOREIGN KEY (asset_id)
    REFERENCES ipca_compliance_is_assets (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — Part-IS risk register.';


CREATE TABLE IF NOT EXISTS ipca_compliance_is_incidents (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  incident_code       VARCHAR(64) NOT NULL,
  case_id             BIGINT UNSIGNED NULL,
  asset_id            BIGINT UNSIGNED NULL,
  title               VARCHAR(255) NOT NULL,
  description         TEXT NOT NULL,
  severity            VARCHAR(16) NOT NULL DEFAULT 'MEDIUM',
  status              VARCHAR(32) NOT NULL DEFAULT 'OPEN'
                        COMMENT 'OPEN | TRIAGING | INVESTIGATING | CONTAINED | RESOLVED | POST_MORTEM | CLOSED',
  detected_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reported_by         INT UNSIGNED NULL,
  reporter_name       VARCHAR(255) NULL,
  contained_at        DATETIME NULL,
  resolved_at         DATETIME NULL,
  closed_at           DATETIME NULL,
  authority_notified  TINYINT(1) NOT NULL DEFAULT 0,
  authority_ref       VARCHAR(128) NULL,
  notes               LONGTEXT NULL,
  created_by          INT UNSIGNED NULL,
  updated_by          INT UNSIGNED NULL,
  locked_at           DATETIME NULL,
  locked_by           INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcaisi_code (incident_code),
  KEY idx_ipcaisi_status_sev (status, severity),
  KEY idx_ipcaisi_case (case_id),
  KEY idx_ipcaisi_asset (asset_id),
  KEY idx_ipcaisi_detected (detected_at),
  CONSTRAINT fk_ipcaisi_case FOREIGN KEY (case_id)
    REFERENCES ipca_compliance_cases (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipcaisi_asset FOREIGN KEY (asset_id)
    REFERENCES ipca_compliance_is_assets (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — Part-IS security incident register.';


CREATE TABLE IF NOT EXISTS ipca_compliance_is_access_reviews (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  review_code         VARCHAR(64) NOT NULL,
  asset_id            BIGINT UNSIGNED NULL,
  scope_description   TEXT NULL,
  period_start        DATE NULL,
  period_end          DATE NULL,
  reviewer_user_id    INT UNSIGNED NULL,
  reviewer_name       VARCHAR(255) NULL,
  status              VARCHAR(32) NOT NULL DEFAULT 'PLANNED'
                        COMMENT 'PLANNED | IN_PROGRESS | COMPLETED | OVERDUE | CANCELLED',
  completed_at        DATETIME NULL,
  findings_count      INT UNSIGNED NOT NULL DEFAULT 0,
  payload_json        JSON NULL COMMENT 'Snapshot of users/roles reviewed and decisions',
  approved_by         INT UNSIGNED NULL,
  approved_by_name    VARCHAR(255) NULL,
  approved_at         DATETIME NULL,
  locked_at           DATETIME NULL,
  locked_by           INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcaisar_code (review_code),
  KEY idx_ipcaisar_status_end (status, period_end),
  KEY idx_ipcaisar_asset (asset_id),
  CONSTRAINT fk_ipcaisar_asset FOREIGN KEY (asset_id)
    REFERENCES ipca_compliance_is_assets (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — Part-IS periodic access reviews.';


CREATE TABLE IF NOT EXISTS ipca_compliance_is_supplier_reviews (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  review_code         VARCHAR(64) NOT NULL,
  supplier_name       VARCHAR(255) NOT NULL,
  service_description TEXT NULL,
  asset_id            BIGINT UNSIGNED NULL COMMENT 'Optional asset that supplier provides',
  contract_ref        VARCHAR(128) NULL,
  period_start        DATE NULL,
  period_end          DATE NULL,
  reviewer_user_id    INT UNSIGNED NULL,
  reviewer_name       VARCHAR(255) NULL,
  risk_rating         VARCHAR(16) NULL COMMENT 'LOW | MEDIUM | HIGH | CRITICAL',
  status              VARCHAR(32) NOT NULL DEFAULT 'PLANNED'
                        COMMENT 'PLANNED | IN_PROGRESS | COMPLETED | OVERDUE | CANCELLED',
  completed_at        DATETIME NULL,
  findings_count      INT UNSIGNED NOT NULL DEFAULT 0,
  payload_json        JSON NULL COMMENT 'Snapshot of supplier evidence and decisions',
  approved_by         INT UNSIGNED NULL,
  approved_by_name    VARCHAR(255) NULL,
  approved_at         DATETIME NULL,
  locked_at           DATETIME NULL,
  locked_by           INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcaissr_code (review_code),
  KEY idx_ipcaissr_status_end (status, period_end),
  KEY idx_ipcaissr_asset (asset_id),
  CONSTRAINT fk_ipcaissr_asset FOREIGN KEY (asset_id)
    REFERENCES ipca_compliance_is_assets (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — Part-IS supplier / third-party reviews.';


-- =============================================================================
-- END OF FILE
-- =============================================================================
-- 48 tables created (3 + 5 + 5 + 5 + 5 + 8 + 3 + 5 + 4 + 5).
-- No platform tables (users, resource_library_*, easa_*, automation_*) are altered.
-- No seed data inserted. No legacy data migrated.
-- =============================================================================
