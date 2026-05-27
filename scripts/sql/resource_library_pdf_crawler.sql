-- Online PDF Crawler — batches, staging articles, diffs (Resource Library pdf_book editions).
-- Run after resource_library_editions.sql and resource_library_editions_extend_types.sql
-- (and scripts/sql/resource_library_blocks.sql for publish → blocks).
--
-- Apply: mysql ... < scripts/sql/resource_library_pdf_crawler.sql

CREATE TABLE IF NOT EXISTS resource_library_pdf_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  edition_id INT UNSIGNED NOT NULL COMMENT 'resource_library_editions.id (resource_type = pdf_book)',
  official_pdf_url TEXT NOT NULL,
  storage_relpath VARCHAR(500) NOT NULL COMMENT 'POSIX path under project root to source.pdf',
  file_sha256 CHAR(64) NOT NULL,
  file_size_bytes BIGINT UNSIGNED NULL,
  status ENUM('downloaded','extracting','ready_for_review','published','rejected','failed') NOT NULL DEFAULT 'downloaded',
  article_count INT UNSIGNED NOT NULL DEFAULT 0,
  changed_article_count INT UNSIGNED NOT NULL DEFAULT 0,
  new_article_count INT UNSIGNED NOT NULL DEFAULT 0,
  removed_article_count INT UNSIGNED NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  parse_started_at DATETIME NULL,
  parse_finished_at DATETIME NULL,
  downloaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  published_at DATETIME NULL,
  published_by_user_id INT UNSIGNED NULL,
  rejected_at DATETIME NULL,
  rejected_by_user_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_rlpb_edition (edition_id),
  KEY idx_rlpb_sha (file_sha256),
  KEY idx_rlpb_status (status),
  KEY idx_rlpb_edition_status (edition_id, status),
  UNIQUE KEY uk_rlpb_edition_sha (edition_id, file_sha256),
  CONSTRAINT fk_rlpb_edition FOREIGN KEY (edition_id) REFERENCES resource_library_editions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Online PDF Crawler — immutable downloaded PDF evidence per edition.';

CREATE TABLE IF NOT EXISTS resource_library_pdf_articles_staging (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_id BIGINT UNSIGNED NOT NULL,
  edition_id INT UNSIGNED NOT NULL,
  article_key VARCHAR(190) NOT NULL,
  article_title VARCHAR(500) NULL,
  hierarchy_path TEXT NULL,
  canonical_text MEDIUMTEXT NOT NULL,
  content_hash CHAR(64) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  page_start INT NULL,
  page_end INT NULL,
  legal_state VARCHAR(80) NULL COMMENT 'active | future_law | repealed | language_variant | unknown',
  amendment_notes MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_rlpas_batch_key (batch_id, article_key),
  KEY idx_rlpas_batch (batch_id),
  KEY idx_rlpas_edition (edition_id),
  KEY idx_rlpas_hash (content_hash),
  CONSTRAINT fk_rlpas_batch FOREIGN KEY (batch_id) REFERENCES resource_library_pdf_batches (id) ON DELETE CASCADE,
  CONSTRAINT fk_rlpas_edition FOREIGN KEY (edition_id) REFERENCES resource_library_editions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Online PDF Crawler — parsed articles awaiting review.';

CREATE TABLE IF NOT EXISTS resource_library_pdf_article_diffs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_id BIGINT UNSIGNED NOT NULL,
  edition_id INT UNSIGNED NOT NULL,
  article_key VARCHAR(190) NOT NULL,
  change_type ENUM('new','changed','removed','unchanged') NOT NULL,
  old_content_hash CHAR(64) NULL,
  new_content_hash CHAR(64) NULL,
  old_excerpt MEDIUMTEXT NULL,
  new_excerpt MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rlpad_batch (batch_id),
  KEY idx_rlpad_edition (edition_id),
  KEY idx_rlpad_key (article_key),
  KEY idx_rlpad_type (change_type),
  KEY idx_rlpad_batch_type (batch_id, change_type),
  CONSTRAINT fk_rlpad_batch FOREIGN KEY (batch_id) REFERENCES resource_library_pdf_batches (id) ON DELETE CASCADE,
  CONSTRAINT fk_rlpad_edition FOREIGN KEY (edition_id) REFERENCES resource_library_editions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Online PDF Crawler — article-level diff vs last published batch.';
