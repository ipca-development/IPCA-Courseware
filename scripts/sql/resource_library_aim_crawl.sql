-- FAA AIM (HTML) crawler index — paragraphs and runs reference resource_library_editions.id
-- (resource_type = 'crawler', extra_config_json for slot / URL prefix / notes).
-- Run after scripts/sql/resource_library_editions.sql and scripts/sql/resource_library_editions_extend_types.sql
-- (or a fresh editions.sql that already includes resource_type + extra_config_json).
--
-- Apply:  mysql ... < scripts/sql/resource_library_aim_crawl.sql
-- Initial HTML import: php scripts/aim_bootstrap_import.php (see script docblock).

CREATE TABLE IF NOT EXISTS resource_library_crawler_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  edition_id INT UNSIGNED NOT NULL COMMENT 'resource_library_editions.id (crawler type)',
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  run_status ENUM('running', 'success', 'partial', 'failed') NOT NULL DEFAULT 'running',
  pages_discovered INT UNSIGNED NOT NULL DEFAULT 0,
  paragraphs_upserted INT UNSIGNED NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  meta_json JSON NULL COMMENT 'Crawler version, base URL, limits, etc.',
  KEY idx_rlcr_edition_started (edition_id, started_at DESC),
  CONSTRAINT fk_rlcr_edition FOREIGN KEY (edition_id) REFERENCES resource_library_editions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resource_library_aim_paragraphs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  edition_id INT UNSIGNED NOT NULL COMMENT 'resource_library_editions.id (crawler type)',
  parent_id BIGINT UNSIGNED NULL COMMENT 'Parent chapter or section row',
  node_type ENUM('chapter', 'section', 'paragraph') NOT NULL DEFAULT 'paragraph',
  chapter_number VARCHAR(32) NULL,
  section_number VARCHAR(32) NULL COMMENT 'e.g. 4-3',
  paragraph_number VARCHAR(64) NULL COMMENT 'e.g. 4-3-2',
  display_title VARCHAR(512) NULL COMMENT 'Human heading for this node (AIM title / paragraph title)',
  page_title VARCHAR(512) NULL COMMENT 'HTML document title of the crawled page',
  source_url VARCHAR(2048) NOT NULL COMMENT 'Exact page URL as fetched (child page, not only index)',
  canonical_url VARCHAR(2048) NOT NULL COMMENT 'Preferred citation URL; include #fragment when available',
  fragment VARCHAR(256) NULL COMMENT 'URL fragment without leading #',
  effective_date DATE NULL COMMENT 'Denormalized from edition for row-level audit',
  change_number VARCHAR(64) NULL COMMENT 'Denormalized from edition when known',
  crawled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  content_hash CHAR(64) NOT NULL COMMENT 'sha256 hex of normalized body used for drift detection',
  body_text MEDIUMTEXT NULL COMMENT 'Plain text for FULLTEXT retrieval',
  body_html MEDIUMTEXT NULL COMMENT 'Optional HTML snippet for replay / debugging',
  citation_status ENUM('active', 'superseded', 'url_broken') NOT NULL DEFAULT 'active',
  superseded_by_id BIGINT UNSIGNED NULL COMMENT 'New row that replaces this citation',
  broken_checked_at TIMESTAMP NULL DEFAULT NULL,
  last_http_status SMALLINT NULL COMMENT 'Last validation HTTP status when checked',
  replaced_canonical_url VARCHAR(2048) NULL COMMENT 'Replacement URL discovered on refresh (audit)',
  stable_key VARCHAR(192) NOT NULL COMMENT 'Stable id within edition, e.g. c:4 | s:4-3 | p:4-3-2',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_rlap_edition_stable (edition_id, stable_key),
  KEY idx_rlap_edition_status (edition_id, citation_status),
  KEY idx_rlap_edition_node (edition_id, node_type),
  KEY idx_rlap_lookup (edition_id, chapter_number, section_number, paragraph_number),
  KEY idx_rlap_canonical (edition_id, canonical_url(384)),
  KEY idx_rlap_content_hash (content_hash),
  FULLTEXT KEY ft_rlap_body (body_text),
  CONSTRAINT fk_rlap_edition FOREIGN KEY (edition_id) REFERENCES resource_library_editions (id) ON DELETE CASCADE,
  CONSTRAINT fk_rlap_parent FOREIGN KEY (parent_id) REFERENCES resource_library_aim_paragraphs (id) ON DELETE SET NULL,
  CONSTRAINT fk_rlap_superseded_by FOREIGN KEY (superseded_by_id) REFERENCES resource_library_aim_paragraphs (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed AIM crawler as an edition row (metadata lives in resource_library_editions).
INSERT INTO resource_library_editions (
  title, revision_code, revision_date, status, thumbnail_path, work_code, sort_order, resource_type, extra_config_json
)
SELECT * FROM (
  SELECT
    'FAA Aeronautical Information Manual (AIM)' AS title,
    'AIM' AS revision_code,
    NULL AS revision_date,
    'draft' AS status,
    NULL AS thumbnail_path,
    'CRAWLER_AIM' AS work_code,
    50 AS sort_order,
    'crawler' AS resource_type,
    JSON_OBJECT(
      'crawler_slot', 'aim',
      'crawler_type', 'aim_html',
      'allowed_url_prefix', 'https://www.faa.gov/Air_traffic/Publications/atpubs/aim_html/',
      'notes', 'Official FAA AIM HTML tree only; relative links normalized to absolute; anchors preserved in canonical_url.'
    ) AS extra_config_json
) AS seed
WHERE NOT EXISTS (
  SELECT 1 FROM resource_library_editions WHERE work_code = 'CRAWLER_AIM' AND resource_type = 'crawler' LIMIT 1
);

-- Optional: default API resource placeholder (same table, resource_type = api).
INSERT INTO resource_library_editions (
  title, revision_code, revision_date, status, thumbnail_path, work_code, sort_order, resource_type, extra_config_json
)
SELECT * FROM (
  SELECT
    'Library HTTP APIs' AS title,
    'v1' AS revision_code,
    CURDATE() AS revision_date,
    'draft' AS status,
    NULL AS thumbnail_path,
    'LIB_API_DEFAULT' AS work_code,
    60 AS sort_order,
    'api' AS resource_type,
    JSON_OBJECT(
      'api_base_url', '',
      'notes', 'Register admin-facing HTTP entrypoints for the resource library (OpenAPI / health URLs can go here).'
    ) AS extra_config_json
) AS seed2
WHERE NOT EXISTS (
  SELECT 1 FROM resource_library_editions WHERE work_code = 'LIB_API_DEFAULT' AND resource_type = 'api' LIMIT 1
);

-- eCFR versioner — drives AI training report regulatory excerpt (Resource Library APIs tab).
INSERT INTO resource_library_editions (
  title, revision_code, revision_date, status, thumbnail_path, work_code, sort_order, resource_type, extra_config_json
)
SELECT * FROM (
  SELECT
    'U.S. Government eCFR API' AS title,
    'v1' AS revision_code,
    CURDATE() AS revision_date,
    'live' AS status,
    NULL AS thumbnail_path,
    'ECFR_API' AS work_code,
    58 AS sort_order,
    'api' AS resource_type,
    JSON_OBJECT(
      'api_base_url', 'https://www.ecfr.gov',
      'ecfr_title_number', 14,
      'ecfr_section', '61.105',
      'ecfr_training_report', TRUE,
      'notes', 'eCFR versioner v1. Training report uses title + section from extra; api_base_url must be HTTPS host (paths are appended in code).'
    ) AS extra_config_json
) AS seed_ecfr
WHERE NOT EXISTS (
  SELECT 1 FROM resource_library_editions WHERE work_code = 'ECFR_API' AND resource_type = 'api' LIMIT 1
);
