-- AI-friendly block store for Resource Library editions (PHAK, etc.).
-- Populated from storage/resource_library/{edition_id}/source.json via admin "Sync JSON → database"
-- or scripts/import_resource_library_blocks.php.
--
-- Run after resource_library_editions.sql exists.

CREATE TABLE IF NOT EXISTS resource_library_blocks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  edition_id INT UNSIGNED NOT NULL,
  block_key VARCHAR(384) NOT NULL COMMENT 'Stable key within edition (chapter|local_id or hash)',
  chapter VARCHAR(128) NOT NULL,
  block_local_id VARCHAR(64) NOT NULL COMMENT 'Original id field from JSON (e.g. p001)',
  section_path_json JSON NULL COMMENT 'TOC path array from JSON',
  block_type VARCHAR(32) NULL,
  `level` SMALLINT NULL,
  body_text MEDIUMTEXT NOT NULL COMMENT 'Plain text for retrieval / FULLTEXT',
  sort_index INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Order in source.json',
  payload_json JSON NOT NULL COMMENT 'Full original block object',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_rlb_edition_block (edition_id, block_key),
  KEY idx_rlb_edition_chapter (edition_id, chapter),
  KEY idx_rlb_edition_sort (edition_id, sort_index),
  FULLTEXT KEY ft_rlb_body (body_text),
  CONSTRAINT fk_rlb_edition FOREIGN KEY (edition_id) REFERENCES resource_library_editions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
