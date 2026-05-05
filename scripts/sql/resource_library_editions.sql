-- Resource Library: canonical reference works (PHAK, ACS, etc.) for admin UI and future AI retrieval.
-- Run against your IPCA courseware database (e.g. mysql < scripts/sql/resource_library_editions.sql).
--
-- Structured JSON uploads (admin Resource Library modal) are stored on disk, not in this table:
--   {project}/storage/resource_library/{edition_id}/source.json

CREATE TABLE IF NOT EXISTS resource_library_editions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(512) NOT NULL,
  revision_code VARCHAR(128) NOT NULL COMMENT 'e.g. FAA-H-8083-25C',
  revision_date DATE NOT NULL COMMENT 'FAA revision / publication date for this edition',
  status ENUM('draft', 'live', 'archived') NOT NULL DEFAULT 'draft',
  thumbnail_path VARCHAR(1024) NULL COMMENT 'Site-relative path (/assets/...) or absolute URL',
  work_code VARCHAR(64) NULL COMMENT 'Short code e.g. PHAK, ACS',
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_rl_status_sort (status, sort_order),
  KEY idx_rl_work (work_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Starter row (safe to run once; skip duplicate if you prefer manual INSERT)
INSERT INTO resource_library_editions (title, revision_code, revision_date, status, thumbnail_path, work_code, sort_order)
SELECT * FROM (SELECT
  'Pilot\'s Handbook of Aeronautical Knowledge' AS title,
  'FAA-H-8083-25C' AS revision_code,
  '2023-06-01' AS revision_date,
  'live' AS status,
  '/assets/icons/documents.svg' AS thumbnail_path,
  'PHAK' AS work_code,
  10 AS sort_order
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM resource_library_editions WHERE work_code = 'PHAK' AND revision_code = 'FAA-H-8083-25C' LIMIT 1);
