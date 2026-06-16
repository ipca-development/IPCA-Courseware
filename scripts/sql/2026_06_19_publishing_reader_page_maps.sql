-- Phase 2: Released reader page maps (deterministic, frozen at release approval).
-- Derived artifact — does not mutate manual content.

CREATE TABLE IF NOT EXISTS ipca_publishing_reader_page_maps (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  book_version_id         BIGINT UNSIGNED NOT NULL,
  layout_profile          VARCHAR(64) NOT NULL COMMENT 'Fixed layout profile key, e.g. LETTER_READER_v1',
  layout_hash             CHAR(64) NOT NULL COMMENT 'SHA-256 of canonical layout profile JSON',
  page_number             INT UNSIGNED NOT NULL,
  section_id              BIGINT UNSIGNED NULL,
  stable_anchor           VARCHAR(128) NULL,
  page_type               VARCHAR(32) NOT NULL DEFAULT 'content' COMMENT 'cover | content | toc | lep | annex',
  is_cover                TINYINT(1) NOT NULL DEFAULT 0,
  is_section_start        TINYINT(1) NOT NULL DEFAULT 0,
  is_major_section_start  TINYINT(1) NOT NULL DEFAULT 0,
  page_html               MEDIUMTEXT NOT NULL COMMENT 'Frozen official page HTML with fixed layout',
  thumbnail_html          TEXT NULL COMMENT 'Frozen thumbnail HTML for filmstrip',
  metadata_json           JSON NULL COMMENT 'Section title, part, generation notes',
  generated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  generated_by_user_id    INT UNSIGNED NULL,
  UNIQUE KEY uk_ipcpm_version_profile_page (book_version_id, layout_profile, page_number),
  KEY idx_ipcpm_version_section (book_version_id, section_id),
  KEY idx_ipcpm_version_anchor (book_version_id, stable_anchor),
  KEY idx_ipcpm_version_profile (book_version_id, layout_profile),
  CONSTRAINT fk_ipcpm_version FOREIGN KEY (book_version_id)
    REFERENCES ipca_publishing_book_versions (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — frozen reader page map for released manuals';
