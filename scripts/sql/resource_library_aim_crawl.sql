-- FAA AIM (HTML) crawler index — Resource Library data crawler slot `aim`.
-- Run against your IPCA courseware database after resource_library_editions.sql (no FK to editions).
--
-- Design goals (citation / audit):
--   * One row per indexed unit (chapter, section, or paragraph) with source_url, canonical_url (fragment when known),
--     version fields (effective_date, change_number), crawled_at, content_hash.
--   * citation_status: active rows are used for AI retrieval; superseded / url_broken kept for audit, not cited as current.
--   * parent_id links chapter → section → paragraph for hierarchy and UI.
--   * Crawl jobs recorded in resource_library_crawler_runs for traceability.
--
-- Apply:  mysql ... < scripts/sql/resource_library_aim_crawl.sql

CREATE TABLE IF NOT EXISTS resource_library_crawler_sources (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  crawler_slot VARCHAR(32) NOT NULL COMMENT 'aim | reserved2 | reserved3',
  crawler_type VARCHAR(64) NOT NULL DEFAULT 'aim_html' COMMENT 'aim_html, etc.',
  label VARCHAR(256) NOT NULL,
  allowed_url_prefix VARCHAR(1024) NOT NULL COMMENT 'Crawler must only follow URLs under this prefix',
  effective_date DATE NULL COMMENT 'AIM effective date for this indexed snapshot',
  change_number VARCHAR(64) NULL COMMENT 'AIM change identifier when known',
  status ENUM('draft', 'active', 'archived') NOT NULL DEFAULT 'draft',
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_rlcs_slot_type (crawler_slot, crawler_type),
  KEY idx_rlcs_status (status, crawler_slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resource_library_crawler_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_id INT UNSIGNED NOT NULL,
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  run_status ENUM('running', 'success', 'partial', 'failed') NOT NULL DEFAULT 'running',
  pages_discovered INT UNSIGNED NOT NULL DEFAULT 0,
  paragraphs_upserted INT UNSIGNED NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  meta_json JSON NULL COMMENT 'Crawler version, base URL, limits, etc.',
  KEY idx_rlcr_source_started (source_id, started_at DESC),
  CONSTRAINT fk_rlcr_source FOREIGN KEY (source_id) REFERENCES resource_library_crawler_sources (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resource_library_aim_paragraphs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_id INT UNSIGNED NOT NULL,
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
  effective_date DATE NULL COMMENT 'Denormalized from source for row-level audit',
  change_number VARCHAR(64) NULL COMMENT 'Denormalized from source when known',
  crawled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  content_hash CHAR(64) NOT NULL COMMENT 'sha256 hex of normalized body used for drift detection',
  body_text MEDIUMTEXT NULL COMMENT 'Plain text for FULLTEXT retrieval',
  body_html MEDIUMTEXT NULL COMMENT 'Optional HTML snippet for replay / debugging',
  citation_status ENUM('active', 'superseded', 'url_broken') NOT NULL DEFAULT 'active',
  superseded_by_id BIGINT UNSIGNED NULL COMMENT 'New row that replaces this citation',
  broken_checked_at TIMESTAMP NULL DEFAULT NULL,
  last_http_status SMALLINT NULL COMMENT 'Last validation HTTP status when checked',
  replaced_canonical_url VARCHAR(2048) NULL COMMENT 'Replacement URL discovered on refresh (audit)',
  stable_key VARCHAR(192) NOT NULL COMMENT 'Stable id within source, e.g. c:4 | s:4-3 | p:4-3-2',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_rlap_source_stable (source_id, stable_key),
  KEY idx_rlap_source_status (source_id, citation_status),
  KEY idx_rlap_source_node (source_id, node_type),
  KEY idx_rlap_lookup (source_id, chapter_number, section_number, paragraph_number),
  KEY idx_rlap_canonical (source_id, canonical_url(384)),
  KEY idx_rlap_content_hash (content_hash),
  FULLTEXT KEY ft_rlap_body (body_text),
  CONSTRAINT fk_rlap_source FOREIGN KEY (source_id) REFERENCES resource_library_crawler_sources (id) ON DELETE CASCADE,
  CONSTRAINT fk_rlap_parent FOREIGN KEY (parent_id) REFERENCES resource_library_aim_paragraphs (id) ON DELETE SET NULL,
  CONSTRAINT fk_rlap_superseded_by FOREIGN KEY (superseded_by_id) REFERENCES resource_library_aim_paragraphs (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default AIM source (draft until first successful crawl activates it in app logic).
INSERT INTO resource_library_crawler_sources (
  crawler_slot, crawler_type, label, allowed_url_prefix, effective_date, change_number, status, notes
)
SELECT * FROM (
  SELECT
    'aim' AS crawler_slot,
    'aim_html' AS crawler_type,
    'FAA Aeronautical Information Manual (HTML)' AS label,
    'https://www.faa.gov/Air_traffic/Publications/atpubs/aim_html/' AS allowed_url_prefix,
    NULL AS effective_date,
    NULL AS change_number,
    'draft' AS status,
    'Official FAA AIM HTML tree only; relative links normalized to absolute; anchors preserved in canonical_url.' AS notes
) AS seed
WHERE NOT EXISTS (
  SELECT 1 FROM resource_library_crawler_sources
  WHERE crawler_slot = 'aim' AND crawler_type = 'aim_html'
  LIMIT 1
);
