-- Staging rows for EASA eRules XML imports (same DB). Apply after resource_library_easa_erules.sql.

CREATE TABLE IF NOT EXISTS easa_erules_import_nodes_staging (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_id BIGINT UNSIGNED NOT NULL,
  node_uid VARCHAR(96) NOT NULL,
  source_erules_id VARCHAR(256) NULL COMMENT 'ERulesId when present',
  node_type VARCHAR(128) NOT NULL COMMENT 'XML local name',
  parent_node_uid VARCHAR(96) NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  depth SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  path VARCHAR(4096) NULL COMMENT 'Stable path built from titles / ids',
  breadcrumb TEXT NULL,
  title TEXT NULL,
  source_title TEXT NULL,
  plain_text MEDIUMTEXT NOT NULL,
  canonical_text MEDIUMTEXT NULL COMMENT 'Whitespace-collapsed/normalised body for hashing and compare',
  xml_fragment MEDIUMTEXT NULL COMMENT 'Full or truncated outer XML of the regulatory node',
  metadata_json JSON NULL,
  content_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_eensa_batch_uid (batch_id, node_uid),
  KEY idx_eensa_batch (batch_id),
  KEY idx_eensa_erules (source_erules_id(191)),
  KEY idx_eensa_type (batch_id, node_type(64)),
  FULLTEXT KEY ft_eensa_plain (plain_text),
  CONSTRAINT fk_eensa_batch FOREIGN KEY (batch_id) REFERENCES easa_erules_import_batches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
