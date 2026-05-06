-- EASA Easy Access Rules (eRules XML) — monitor + import staging (same database as courseware).
-- Run after resource_library_editions.sql exists (optional dependency only for organizational parity).

CREATE TABLE IF NOT EXISTS easa_download_monitor (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  url VARCHAR(2048) NOT NULL,
  label VARCHAR(256) NULL COMMENT 'Human label for admin UI',
  checked_at DATETIME NULL COMMENT 'UTC probe time',
  http_status SMALLINT UNSIGNED NULL,
  final_url VARCHAR(2048) NULL,
  etag VARCHAR(512) NULL,
  last_modified VARCHAR(512) NULL,
  content_length BIGINT UNSIGNED NULL,
  content_hash CHAR(64) NULL COMMENT 'Reserved: optional body/page fingerprint',
  changed_flag TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Set when signature differs from previous probe',
  last_error TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_easa_dm_url (url(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS easa_erules_import_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  status VARCHAR(32) NOT NULL DEFAULT 'uploaded'
    COMMENT 'uploaded | staging | ready_for_review | published | rejected | failed',
  original_filename VARCHAR(512) NOT NULL,
  file_sha256 CHAR(64) NOT NULL,
  storage_relpath VARCHAR(1024) NOT NULL COMMENT 'Path under project root (storage/easa_erules/…)',
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by_user_id INT UNSIGNED NULL,
  publication_meta_json JSON NULL,
  rows_detected INT UNSIGNED NULL COMMENT 'Filled after XML ingest pipeline',
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_eerb_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default row: official Easy Access Rules landing page (manual XML download). Probe respects daily cron / polite intervals.
INSERT IGNORE INTO easa_download_monitor (url, label)
VALUES (
  'https://www.easa.europa.eu/en/downloads/136679/en',
  'EASA Easy Access Rules — downloads'
);
