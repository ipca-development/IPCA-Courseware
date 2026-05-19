-- =============================================================================
-- Controlled Publishing — Canonical Source Layer
-- =============================================================================
-- File:    scripts/sql/2026_05_18_controlled_publishing_canonical_sources.sql
-- Scope:   Phase 1 — create adaptable canonical source tables in ipca_courseware.
-- Purpose: Give the controlled publishing platform a local, stable canonical
--          source model for MCCF requirements, controlled manual excerpts, and
--          future source families without depending on legacy database table
--          shapes at editor/e-reader runtime.
--
-- Apply:   mysql ... < scripts/sql/2026_05_18_controlled_publishing_canonical_sources.sql
-- Safety:  Idempotent CREATE TABLE IF NOT EXISTS only. No seed data.
--
-- Design notes:
--   * These tables store canonical source data and provenance, not book/editor
--     draft content.
--   * Legacy ipca_compliance remains an upstream source for Phase 2 sync. It is
--     not referenced with cross-database foreign keys.
--   * Existing resource_library_*, easa_*, AIM, eCFR, and Compliance OS tables
--     are not modified.
--   * Published/manual editor tables should later link to these rows by local
--     ids and stable source keys.
-- =============================================================================

CREATE TABLE IF NOT EXISTS ipca_canonical_sources (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_key            VARCHAR(96) NOT NULL COMMENT 'Stable key, e.g. legacy_ipca_compliance',
  source_type           VARCHAR(32) NOT NULL COMMENT 'legacy_db | internal_db | api | crawler | json_book | other',
  display_name          VARCHAR(255) NOT NULL,
  authority             VARCHAR(64) NULL COMMENT 'BCAA | EASA | FAA | INTERNAL | OTHER',
  origin_database       VARCHAR(128) NULL COMMENT 'Upstream database/schema when applicable',
  origin_table_prefix   VARCHAR(128) NULL COMMENT 'Optional family prefix, e.g. mccf_ / manual_',
  status                VARCHAR(32) NOT NULL DEFAULT 'active' COMMENT 'active | disabled | retired',
  config_json           JSON NULL COMMENT 'Connection-free source metadata; never store secrets here',
  imported_hash         CHAR(64) NULL COMMENT 'Hash of the last accepted source inventory',
  last_synced_at        DATETIME NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcacs_key (source_key),
  KEY idx_ipcacs_type_status (source_type, status),
  KEY idx_ipcacs_authority (authority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — canonical source registries / upstream systems.';


CREATE TABLE IF NOT EXISTS ipca_canonical_source_sets (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_id             BIGINT UNSIGNED NOT NULL,
  source_set_key        VARCHAR(191) NOT NULL COMMENT 'Stable governed baseline key, e.g. MCCF:OM:REV_6_0',
  source_family         VARCHAR(32) NOT NULL COMMENT 'mccf | manual | regulation | resource_book | lesson_blueprint | other',
  authority             VARCHAR(64) NULL COMMENT 'BCAA | EASA | FAA | INTERNAL | OTHER',
  title                 VARCHAR(512) NOT NULL,
  revision_label        VARCHAR(128) NULL,
  effective_date        DATE NULL,
  supersedes_source_set_id BIGINT UNSIGNED NULL,
  status                VARCHAR(32) NOT NULL DEFAULT 'active' COMMENT 'draft_import | active | superseded | retired',
  source_hash           CHAR(64) NULL COMMENT 'Hash of all canonical rows in this source set',
  metadata_json         JSON NULL,
  first_synced_at       DATETIME NULL,
  last_synced_at        DATETIME NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcass_key (source_set_key),
  KEY idx_ipcass_source (source_id),
  KEY idx_ipcass_family_status (source_family, status),
  KEY idx_ipcass_authority (authority),
  KEY idx_ipcass_supersedes (supersedes_source_set_id),
  CONSTRAINT fk_ipcass_source FOREIGN KEY (source_id)
    REFERENCES ipca_canonical_sources (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipcass_supersedes FOREIGN KEY (supersedes_source_set_id)
    REFERENCES ipca_canonical_source_sets (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — governed canonical source sets / revisions.';


CREATE TABLE IF NOT EXISTS ipca_canonical_documents (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_id             BIGINT UNSIGNED NOT NULL,
  source_set_id         BIGINT UNSIGNED NOT NULL,
  document_key          VARCHAR(191) NOT NULL COMMENT 'Stable local key, e.g. MCCF:OM:OM REV 6.0',
  document_type         VARCHAR(32) NOT NULL COMMENT 'mccf | manual | regulation | resource_book | learning_blueprint | other',
  authority             VARCHAR(64) NULL,
  manual_code           VARCHAR(32) NULL COMMENT 'OM | OMM | TM | etc.',
  revision_code         VARCHAR(128) NULL COMMENT 'Manual revision, MCCF id, API version, etc.',
  revision_date         DATE NULL,
  title                 VARCHAR(512) NOT NULL,
  status                VARCHAR(32) NOT NULL DEFAULT 'active' COMMENT 'active | draft | superseded | retired',
  source_database       VARCHAR(128) NULL,
  source_table          VARCHAR(128) NULL,
  source_pk             VARCHAR(191) NULL COMMENT 'Upstream PK/key when one row represents this document',
  source_hash           CHAR(64) NULL,
  metadata_json         JSON NULL,
  last_synced_at        DATETIME NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcacd_key (source_set_id, document_key),
  KEY idx_ipcacd_source (source_id),
  KEY idx_ipcacd_source_set (source_set_id),
  KEY idx_ipcacd_type_status (document_type, status),
  KEY idx_ipcacd_manual_rev (manual_code, revision_code),
  CONSTRAINT fk_ipcacd_source FOREIGN KEY (source_id)
    REFERENCES ipca_canonical_sources (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipcacd_source_set FOREIGN KEY (source_set_id)
    REFERENCES ipca_canonical_source_sets (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — canonical source documents / editions.';


CREATE TABLE IF NOT EXISTS ipca_canonical_requirements (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_set_id         BIGINT UNSIGNED NOT NULL,
  source_document_id    BIGINT UNSIGNED NOT NULL,
  requirement_key       VARCHAR(128) NOT NULL COMMENT 'Stable requirement key, e.g. OMM4.0|P3|55|2',
  source_mccf_row_id    BIGINT NULL COMMENT 'Legacy ipca_compliance.mccf_requirements.mccf_row_id',
  mccf_id               VARCHAR(128) NULL,
  authority             VARCHAR(64) NULL,
  manual_code           VARCHAR(32) NOT NULL,
  manual_type           VARCHAR(128) NULL,
  manual_part           VARCHAR(64) NULL,
  item_no               VARCHAR(64) NULL,
  sub_item_no           VARCHAR(64) NULL,
  subject               TEXT NOT NULL,
  requirement_text      LONGTEXT NOT NULL,
  regulation_ref        TEXT NULL,
  manual_section_ref    TEXT NULL,
  legacy_excerpt_id     VARCHAR(128) NULL COMMENT 'Legacy inline excerpt_id when present on requirement row',
  applicable            VARCHAR(32) NOT NULL DEFAULT 'Yes',
  remarks               TEXT NULL,
  finding_ref           VARCHAR(255) NULL,
  source_hash           CHAR(64) NOT NULL,
  source_status         VARCHAR(32) NOT NULL DEFAULT 'active' COMMENT 'active | superseded | missing_from_source | retired',
  first_synced_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_synced_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcareq_key (source_set_id, requirement_key),
  KEY idx_ipcareq_source_set (source_set_id),
  KEY idx_ipcareq_doc (source_document_id),
  KEY idx_ipcareq_manual_part (manual_code, manual_part),
  KEY idx_ipcareq_applicable (applicable),
  KEY idx_ipcareq_status (source_status),
  KEY idx_ipcareq_source_row (source_mccf_row_id),
  FULLTEXT KEY ft_ipcareq_text (subject, requirement_text, regulation_ref, manual_section_ref),
  CONSTRAINT fk_ipcareq_source_set FOREIGN KEY (source_set_id)
    REFERENCES ipca_canonical_source_sets (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipcareq_doc FOREIGN KEY (source_document_id)
    REFERENCES ipca_canonical_documents (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — canonical MCCF / compliance requirements.';


CREATE TABLE IF NOT EXISTS ipca_canonical_excerpts (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_set_id         BIGINT UNSIGNED NOT NULL,
  source_document_id    BIGINT UNSIGNED NOT NULL,
  excerpt_key           VARCHAR(128) NOT NULL COMMENT 'Stable source excerpt id, e.g. OM6_P0_0.1',
  excerpt_key_norm      VARCHAR(128) NULL,
  manual_code           VARCHAR(32) NOT NULL,
  manual_rev            VARCHAR(32) NULL,
  manual_part           VARCHAR(64) NULL COMMENT 'Normalized part value across OM/OMM',
  section_ref           VARCHAR(64) NULL,
  title                 VARCHAR(512) NULL,
  body_text             LONGTEXT NOT NULL,
  source_file           VARCHAR(512) NULL,
  source_sha256         CHAR(64) NULL COMMENT 'Upstream-provided sha256 when available',
  content_hash          CHAR(64) NOT NULL COMMENT 'Local normalized content hash',
  -- Optional normalized hierarchy fields for OMM-style source rows.
  hierarchy_level       INT NULL,
  parent_excerpt_key    VARCHAR(128) NULL,
  sort_order            INT NULL,
  source_table          VARCHAR(128) NULL,
  source_hash           CHAR(64) NOT NULL,
  source_status         VARCHAR(32) NOT NULL DEFAULT 'active' COMMENT 'active | superseded | missing_from_source | retired',
  first_synced_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_synced_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcaex_key (source_set_id, excerpt_key),
  KEY idx_ipcaex_source_set (source_set_id),
  KEY idx_ipcaex_doc (source_document_id),
  KEY idx_ipcaex_manual_section (manual_code, manual_rev, manual_part, section_ref),
  KEY idx_ipcaex_parent (parent_excerpt_key),
  KEY idx_ipcaex_status (source_status),
  FULLTEXT KEY ft_ipcaex_text (title, body_text),
  CONSTRAINT fk_ipcaex_source_set FOREIGN KEY (source_set_id)
    REFERENCES ipca_canonical_source_sets (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipcaex_doc FOREIGN KEY (source_document_id)
    REFERENCES ipca_canonical_documents (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — canonical manual / controlled-source excerpts.';


CREATE TABLE IF NOT EXISTS ipca_canonical_requirement_excerpt_links (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_set_id         BIGINT UNSIGNED NOT NULL,
  source_document_id    BIGINT UNSIGNED NOT NULL COMMENT 'Usually the MCCF document containing the requirement',
  requirement_id        BIGINT UNSIGNED NOT NULL,
  excerpt_id            BIGINT UNSIGNED NOT NULL,
  requirement_key       VARCHAR(128) NOT NULL,
  excerpt_key           VARCHAR(128) NOT NULL,
  link_type             VARCHAR(16) NOT NULL DEFAULT 'PRIMARY' COMMENT 'PRIMARY | SUPPORTING',
  confidence            VARCHAR(16) NOT NULL DEFAULT 'AUTO' COMMENT 'AUTO | VERIFIED | MANUAL',
  notes                 TEXT NULL,
  verified_by_source    VARCHAR(128) NULL COMMENT 'Legacy verified_by or other source actor label',
  verified_on           DATETIME NULL,
  source_link_id        BIGINT NULL COMMENT 'Legacy mccf_excerpt_links.link_id',
  source_hash           CHAR(64) NOT NULL,
  source_status         VARCHAR(32) NOT NULL DEFAULT 'active' COMMENT 'active | superseded | missing_from_source | retired',
  first_synced_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_synced_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcarel_source_link (source_set_id, source_link_id),
  UNIQUE KEY uk_ipcarel_req_ex (source_set_id, requirement_id, excerpt_id, link_type),
  KEY idx_ipcarel_source_set (source_set_id),
  KEY idx_ipcarel_req (requirement_id),
  KEY idx_ipcarel_excerpt (excerpt_id),
  KEY idx_ipcarel_keys (requirement_key, excerpt_key),
  KEY idx_ipcarel_status (source_status),
  CONSTRAINT fk_ipcarel_source_set FOREIGN KEY (source_set_id)
    REFERENCES ipca_canonical_source_sets (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipcarel_doc FOREIGN KEY (source_document_id)
    REFERENCES ipca_canonical_documents (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipcarel_req FOREIGN KEY (requirement_id)
    REFERENCES ipca_canonical_requirements (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipcarel_excerpt FOREIGN KEY (excerpt_id)
    REFERENCES ipca_canonical_excerpts (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — canonical requirement to excerpt coverage links.';


CREATE TABLE IF NOT EXISTS ipca_canonical_sync_runs (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_id             BIGINT UNSIGNED NOT NULL,
  source_set_id         BIGINT UNSIGNED NULL,
  run_key               VARCHAR(96) NOT NULL,
  run_type              VARCHAR(32) NOT NULL DEFAULT 'legacy_sync' COMMENT 'legacy_sync | verify | repair | other',
  status                VARCHAR(32) NOT NULL DEFAULT 'running' COMMENT 'running | dry_run | success | failed | partial',
  dry_run               TINYINT(1) NOT NULL DEFAULT 1,
  started_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at          DATETIME NULL,
  expected_counts_json  JSON NULL,
  observed_counts_json  JSON NULL,
  action_counts_json    JSON NULL,
  warnings_json         JSON NULL,
  errors_json           JSON NULL,
  options_json          JSON NULL,
  source_inventory_hash CHAR(64) NULL,
  created_by            INT UNSIGNED NULL COMMENT 'users.id when launched from UI; NULL for CLI/system',
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcasr_key (run_key),
  KEY idx_ipcasr_source_started (source_id, started_at),
  KEY idx_ipcasr_source_set (source_set_id, started_at),
  KEY idx_ipcasr_status (status, started_at),
  CONSTRAINT fk_ipcasr_source FOREIGN KEY (source_id)
    REFERENCES ipca_canonical_sources (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipcasr_source_set FOREIGN KEY (source_set_id)
    REFERENCES ipca_canonical_source_sets (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — canonical source sync/import run audit.';


CREATE TABLE IF NOT EXISTS ipca_canonical_sync_row_map (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sync_run_id           BIGINT UNSIGNED NULL COMMENT 'NULL allowed for dry-run diagnostics not persisted as a run',
  source_set_id         BIGINT UNSIGNED NULL,
  local_table           VARCHAR(128) NOT NULL,
  local_id              BIGINT UNSIGNED NULL,
  source_database       VARCHAR(128) NOT NULL,
  source_table          VARCHAR(128) NOT NULL,
  source_pk             VARCHAR(191) NULL,
  source_stable_key     VARCHAR(191) NOT NULL,
  source_hash           CHAR(64) NULL,
  action                VARCHAR(32) NOT NULL COMMENT 'inserted | updated | unchanged | deactivated | error',
  message               TEXT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipcasrm_source_set (source_set_id),
  KEY idx_ipcasrm_source_local (source_database, source_table, source_stable_key, local_table),
  KEY idx_ipcasrm_run (sync_run_id),
  KEY idx_ipcasrm_action (action),
  KEY idx_ipcasrm_local (local_table, local_id),
  CONSTRAINT fk_ipcasrm_run FOREIGN KEY (sync_run_id)
    REFERENCES ipca_canonical_sync_runs (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipcasrm_source_set FOREIGN KEY (source_set_id)
    REFERENCES ipca_canonical_source_sets (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — traceability map from canonical rows to upstream source rows.';


-- =============================================================================
-- SECTION B. CONTROLLED PUBLISHING FOUNDATION
-- =============================================================================
-- These tables are intentionally skeletal. They provide the source baseline,
-- release snapshot, stable anchor, system-managed section, and Statement of
-- Compliance Source foundations. They do NOT implement the full editor, reader,
-- AI inspector UI, PDF polish, or template designer.

CREATE TABLE IF NOT EXISTS ipca_publishing_book_templates (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template_key          VARCHAR(96) NOT NULL,
  title                 VARCHAR(255) NOT NULL,
  manual_family         VARCHAR(32) NULL COMMENT 'OM | OMM | TM | HANDBOOK | OTHER',
  status                VARCHAR(32) NOT NULL DEFAULT 'draft' COMMENT 'draft | active | retired',
  allowed_block_types_json JSON NULL,
  style_rules_json      JSON NULL,
  metadata_json         JSON NULL,
  created_by            INT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcpbt_key (template_key),
  KEY idx_ipcpbt_family_status (manual_family, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — governed template definitions, not arbitrary page design.';


CREATE TABLE IF NOT EXISTS ipca_publishing_book_template_sections (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template_id           BIGINT UNSIGNED NOT NULL,
  section_key           VARCHAR(128) NOT NULL,
  parent_section_key    VARCHAR(128) NULL,
  title                 VARCHAR(255) NOT NULL,
  section_type          VARCHAR(32) NOT NULL COMMENT 'cover | toc | lep | revision_system | amendment_list | distribution_list | abbreviations | definitions | highlights | content | annex',
  is_required           TINYINT(1) NOT NULL DEFAULT 1,
  is_system_managed     TINYINT(1) NOT NULL DEFAULT 0,
  is_generated          TINYINT(1) NOT NULL DEFAULT 0,
  allow_author_blocks   TINYINT(1) NOT NULL DEFAULT 1,
  sort_order            INT NOT NULL DEFAULT 0,
  rules_json            JSON NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcpbts_template_section (template_id, section_key),
  KEY idx_ipcpbts_parent (template_id, parent_section_key),
  KEY idx_ipcpbts_type (section_type),
  CONSTRAINT fk_ipcpbts_template FOREIGN KEY (template_id)
    REFERENCES ipca_publishing_book_templates (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — mandatory/generated section rules per template.';


CREATE TABLE IF NOT EXISTS ipca_publishing_books (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  book_key              VARCHAR(96) NOT NULL,
  title                 VARCHAR(255) NOT NULL,
  book_type             VARCHAR(32) NOT NULL DEFAULT 'manual' COMMENT 'manual | handbook | courseware | other',
  manual_code           VARCHAR(32) NULL COMMENT 'OM | OMM | TM | etc.',
  owner_user_id         INT UNSIGNED NULL,
  status                VARCHAR(32) NOT NULL DEFAULT 'active' COMMENT 'active | inactive | archived',
  metadata_json         JSON NULL,
  created_by            INT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcpb_key (book_key),
  KEY idx_ipcpb_type_status (book_type, status),
  KEY idx_ipcpb_manual (manual_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — controlled book/manual registry.';


CREATE TABLE IF NOT EXISTS ipca_publishing_book_versions (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  book_id               BIGINT UNSIGNED NOT NULL,
  template_id           BIGINT UNSIGNED NULL,
  version_label         VARCHAR(128) NOT NULL,
  title                 VARCHAR(255) NOT NULL,
  lifecycle_status      VARCHAR(32) NOT NULL DEFAULT 'draft' COMMENT 'draft | in_review | approved | released | superseded | retired',
  effective_date        DATE NULL,
  source_baseline_id    BIGINT UNSIGNED NULL COMMENT 'Required by release workflow; FK intentionally added from baseline side to avoid circular DDL',
  content_hash          CHAR(64) NULL,
  release_hash          CHAR(64) NULL,
  released_at           DATETIME NULL,
  released_by           INT UNSIGNED NULL,
  supersedes_version_id BIGINT UNSIGNED NULL,
  metadata_json         JSON NULL,
  created_by            INT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcpbv_book_version (book_id, version_label),
  KEY idx_ipcpbv_book_status (book_id, lifecycle_status),
  KEY idx_ipcpbv_template (template_id),
  KEY idx_ipcpbv_source_baseline (source_baseline_id),
  KEY idx_ipcpbv_supersedes (supersedes_version_id),
  CONSTRAINT fk_ipcpbv_book FOREIGN KEY (book_id)
    REFERENCES ipca_publishing_books (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipcpbv_template FOREIGN KEY (template_id)
    REFERENCES ipca_publishing_book_templates (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipcpbv_supersedes FOREIGN KEY (supersedes_version_id)
    REFERENCES ipca_publishing_book_versions (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_ipcpbv_release_baseline
    CHECK (lifecycle_status <> 'released' OR source_baseline_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — controlled book/manual versions.';


CREATE TABLE IF NOT EXISTS ipca_publishing_book_version_source_sets (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  book_version_id       BIGINT UNSIGNED NOT NULL,
  source_set_id         BIGINT UNSIGNED NOT NULL,
  selection_role        VARCHAR(32) NOT NULL DEFAULT 'compliance_source' COMMENT 'compliance_source | manual_source | regulation_source | reference_source',
  is_required_for_release TINYINT(1) NOT NULL DEFAULT 1,
  selected_by           INT UNSIGNED NULL,
  selected_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes                 TEXT NULL,
  UNIQUE KEY uk_ipcpbvss_version_set_role (book_version_id, source_set_id, selection_role),
  KEY idx_ipcpbvss_source_set (source_set_id),
  CONSTRAINT fk_ipcpbvss_version FOREIGN KEY (book_version_id)
    REFERENCES ipca_publishing_book_versions (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipcpbvss_source_set FOREIGN KEY (source_set_id)
    REFERENCES ipca_canonical_source_sets (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — Statement of Compliance Source selections for a book version.';


CREATE TABLE IF NOT EXISTS ipca_publishing_source_baselines (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  book_version_id       BIGINT UNSIGNED NOT NULL,
  baseline_key          VARCHAR(128) NOT NULL,
  baseline_status       VARCHAR(32) NOT NULL DEFAULT 'draft' COMMENT 'draft | frozen | released | superseded',
  baseline_hash         CHAR(64) NOT NULL,
  source_snapshot_json  JSON NOT NULL COMMENT 'Frozen source-set/document/hash metadata for release proof',
  mapping_snapshot_json JSON NULL COMMENT 'Placeholder for frozen mapping inventory once mappings exist',
  frozen_at             DATETIME NULL,
  frozen_by             INT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcpbs_key (baseline_key),
  KEY idx_ipcpbs_version (book_version_id, baseline_status),
  KEY idx_ipcpbs_hash (baseline_hash),
  CONSTRAINT fk_ipcpbs_version FOREIGN KEY (book_version_id)
    REFERENCES ipca_publishing_book_versions (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — immutable source baseline foundation for release proof.';


CREATE TABLE IF NOT EXISTS ipca_publishing_source_baseline_sets (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_baseline_id    BIGINT UNSIGNED NOT NULL,
  source_set_id         BIGINT UNSIGNED NOT NULL,
  source_set_hash       CHAR(64) NULL,
  source_set_snapshot_json JSON NOT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcpbss_baseline_set (source_baseline_id, source_set_id),
  KEY idx_ipcpbss_source_set (source_set_id),
  CONSTRAINT fk_ipcpbss_baseline FOREIGN KEY (source_baseline_id)
    REFERENCES ipca_publishing_source_baselines (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipcpbss_source_set FOREIGN KEY (source_set_id)
    REFERENCES ipca_canonical_source_sets (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — frozen source sets included in a source baseline.';


CREATE TABLE IF NOT EXISTS ipca_publishing_book_sections (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  book_version_id       BIGINT UNSIGNED NOT NULL,
  parent_section_id     BIGINT UNSIGNED NULL,
  template_section_id   BIGINT UNSIGNED NULL,
  section_key           VARCHAR(128) NOT NULL,
  stable_anchor         VARCHAR(191) NOT NULL,
  title                 VARCHAR(255) NOT NULL,
  section_type          VARCHAR(32) NOT NULL DEFAULT 'content',
  is_system_managed     TINYINT(1) NOT NULL DEFAULT 0,
  is_generated          TINYINT(1) NOT NULL DEFAULT 0,
  sort_order            INT NOT NULL DEFAULT 0,
  content_hash          CHAR(64) NULL,
  metadata_json         JSON NULL,
  created_by            INT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcpbsct_version_section (book_version_id, section_key),
  UNIQUE KEY uk_ipcpbsct_anchor (book_version_id, stable_anchor),
  KEY idx_ipcpbsct_parent (parent_section_id),
  KEY idx_ipcpbsct_template_section (template_section_id),
  KEY idx_ipcpbsct_type (book_version_id, section_type),
  CONSTRAINT fk_ipcpbsct_version FOREIGN KEY (book_version_id)
    REFERENCES ipca_publishing_book_versions (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipcpbsct_parent FOREIGN KEY (parent_section_id)
    REFERENCES ipca_publishing_book_sections (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipcpbsct_template_section FOREIGN KEY (template_section_id)
    REFERENCES ipca_publishing_book_template_sections (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — structured version sections with stable anchors.';


CREATE TABLE IF NOT EXISTS ipca_publishing_book_blocks (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  book_version_id       BIGINT UNSIGNED NOT NULL,
  section_id            BIGINT UNSIGNED NOT NULL,
  block_key             VARCHAR(128) NOT NULL,
  stable_anchor         VARCHAR(191) NOT NULL COMMENT 'Example: OMM-4.2.1-BLOCK-003',
  block_type            VARCHAR(32) NOT NULL COMMENT 'heading | paragraph | list | table | image | callout | reference | generated_placeholder',
  sort_order            INT NOT NULL DEFAULT 0,
  payload_json          JSON NOT NULL COMMENT 'Structured block payload only; no uncontrolled HTML blobs',
  content_hash          CHAR(64) NOT NULL,
  is_system_managed     TINYINT(1) NOT NULL DEFAULT 0,
  created_by            INT UNSIGNED NULL,
  updated_by            INT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcpbb_version_block (book_version_id, block_key),
  UNIQUE KEY uk_ipcpbb_anchor (book_version_id, stable_anchor),
  KEY idx_ipcpbb_section_order (section_id, sort_order),
  KEY idx_ipcpbb_type (book_version_id, block_type),
  CONSTRAINT fk_ipcpbb_version FOREIGN KEY (book_version_id)
    REFERENCES ipca_publishing_book_versions (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipcpbb_section FOREIGN KEY (section_id)
    REFERENCES ipca_publishing_book_sections (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — structured official content blocks.';


CREATE TABLE IF NOT EXISTS ipca_publishing_release_snapshots (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  book_version_id       BIGINT UNSIGNED NOT NULL,
  source_baseline_id    BIGINT UNSIGNED NOT NULL,
  release_key           VARCHAR(128) NOT NULL,
  release_status        VARCHAR(32) NOT NULL DEFAULT 'draft' COMMENT 'draft | released | superseded | void',
  release_hash          CHAR(64) NOT NULL,
  content_snapshot_json JSON NOT NULL,
  source_snapshot_json  JSON NOT NULL,
  mapping_snapshot_json JSON NULL,
  approval_snapshot_json JSON NULL,
  released_at           DATETIME NULL,
  released_by           INT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcprs_release_key (release_key),
  KEY idx_ipcprs_version (book_version_id, release_status),
  KEY idx_ipcprs_baseline (source_baseline_id),
  KEY idx_ipcprs_hash (release_hash),
  CONSTRAINT fk_ipcprs_version FOREIGN KEY (book_version_id)
    REFERENCES ipca_publishing_book_versions (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipcprs_baseline FOREIGN KEY (source_baseline_id)
    REFERENCES ipca_publishing_source_baselines (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — immutable release snapshot foundation.';

-- =============================================================================
-- END
-- =============================================================================
