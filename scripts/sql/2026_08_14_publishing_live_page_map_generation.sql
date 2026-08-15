-- Phase B: revision-safe background generation for authoritative page maps.
-- Production rows remain in ipca_publishing_reader_page_maps. Candidates are
-- isolated here until a complete fingerprint CAS promotes them.

CREATE TABLE IF NOT EXISTS ipca_publishing_page_map_generation_state (
  book_version_id             BIGINT UNSIGNED NOT NULL,
  layout_profile              VARCHAR(64) NOT NULL,
  generation_seq              BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status                      VARCHAR(32) NOT NULL DEFAULT 'stale'
                              COMMENT 'current | pending | generating | stale | failed | retry_available',
  requested_fingerprint_hash  CHAR(64) NOT NULL DEFAULT '',
  requested_fingerprint_json  JSON NULL,
  requested_mutation_json     JSON NULL,
  pending_generation_seq      BIGINT UNSIGNED NULL
                              COMMENT 'Newest coalesced revision waiting behind the active lease',
  pending_fingerprint_hash    CHAR(64) NULL,
  pending_fingerprint_json    JSON NULL,
  pending_mutation_json       JSON NULL,
  pending_requested_by_user_id INT UNSIGNED NULL,
  lease_token                 CHAR(64) NULL,
  lease_expires_at            DATETIME NULL,
  attempt_count               INT UNSIGNED NOT NULL DEFAULT 0,
  requested_by_user_id        INT UNSIGNED NULL,
  last_error_code             VARCHAR(64) NULL,
  last_error_message          VARCHAR(2000) NULL,
  created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (book_version_id, layout_profile),
  KEY idx_ipcpmsgs_claim (status, lease_expires_at, updated_at),
  CONSTRAINT fk_ipcpmsgs_version FOREIGN KEY (book_version_id)
    REFERENCES ipca_publishing_book_versions (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One coalescing/lease row per authoritative page-map version and profile';

CREATE TABLE IF NOT EXISTS ipca_publishing_reader_page_map_staging (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  book_version_id         BIGINT UNSIGNED NOT NULL,
  layout_profile          VARCHAR(64) NOT NULL,
  generation_seq          BIGINT UNSIGNED NOT NULL,
  lease_token             CHAR(64) NOT NULL COMMENT 'Isolates an expired worker from its replacement',
  layout_hash             CHAR(64) NOT NULL,
  page_number             INT UNSIGNED NOT NULL,
  section_id              BIGINT UNSIGNED NULL,
  stable_anchor           VARCHAR(128) NULL,
  page_type               VARCHAR(32) NOT NULL DEFAULT 'content',
  is_cover                TINYINT(1) NOT NULL DEFAULT 0,
  is_section_start        TINYINT(1) NOT NULL DEFAULT 0,
  is_major_section_start  TINYINT(1) NOT NULL DEFAULT 0,
  page_html               MEDIUMTEXT NOT NULL,
  thumbnail_html          TEXT NULL,
  metadata_json           JSON NULL,
  generated_by_user_id    INT UNSIGNED NULL,
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcpmstage_generation_page
    (book_version_id, layout_profile, generation_seq, lease_token, page_number),
  KEY idx_ipcpmstage_generation (book_version_id, layout_profile, generation_seq, lease_token),
  CONSTRAINT fk_ipcpmstage_version FOREIGN KEY (book_version_id)
    REFERENCES ipca_publishing_book_versions (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Unpublished authoritative page candidates; never read by clients';
