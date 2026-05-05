-- Resource Library: canonical resource rows (handbooks, crawlers, API registry) for admin UI and AI retrieval.
-- Run against your IPCA courseware database (e.g. mysql < scripts/sql/resource_library_editions.sql).
--
-- JSON handbook files (json_book): stored on disk under storage/resource_library/{id}/source.json
-- Crawler / API rows: metadata in extra_config_json; no source.json required.
--
-- For PHAK FULLTEXT blocks, run scripts/sql/resource_library_blocks.sql then sync from admin.

CREATE TABLE IF NOT EXISTS resource_library_editions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(512) NOT NULL,
  revision_code VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'Handbook revision code, crawler change id, or API version label',
  revision_date DATE NULL COMMENT 'Publication / effective date (nullable for some resource types)',
  status ENUM('draft', 'live', 'archived') NOT NULL DEFAULT 'draft',
  thumbnail_path VARCHAR(1024) NULL COMMENT 'Site-relative path (/assets/...) or absolute URL',
  work_code VARCHAR(64) NULL COMMENT 'Short code e.g. PHAK, CRAWLER_AIM, LIB_API_DEFAULT',
  sort_order INT NOT NULL DEFAULT 0,
  resource_type VARCHAR(32) NOT NULL DEFAULT 'json_book' COMMENT 'json_book | crawler | api',
  extra_config_json JSON NULL COMMENT 'Type-specific: crawler_slot, allowed_url_prefix, api_base_url, …',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_rl_status_sort (status, sort_order),
  KEY idx_rl_work (work_code),
  KEY idx_rl_resource_type (resource_type, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO resource_library_editions (
  title, revision_code, revision_date, status, thumbnail_path, work_code, sort_order, resource_type, extra_config_json
)
SELECT * FROM (SELECT
  'Pilot\'s Handbook of Aeronautical Knowledge' AS title,
  'FAA-H-8083-25C' AS revision_code,
  '2023-06-01' AS revision_date,
  'live' AS status,
  '/assets/icons/documents.svg' AS thumbnail_path,
  'PHAK' AS work_code,
  10 AS sort_order,
  'json_book' AS resource_type,
  NULL AS extra_config_json
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM resource_library_editions WHERE work_code = 'PHAK' AND revision_code = 'FAA-H-8083-25C' LIMIT 1);
