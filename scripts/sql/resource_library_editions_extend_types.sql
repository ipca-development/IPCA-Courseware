-- Add resource kinds to resource_library_editions (json_book | crawler | api).
-- Run once on existing databases:  mysql ... < scripts/sql/resource_library_editions_extend_types.sql
--
-- Then run scripts/sql/resource_library_aim_crawl.sql for AIM tables + crawler/API seed rows.
-- If you previously used resource_library_crawler_sources, run scripts/sql/resource_library_migrate_aim_to_editions.sql.

ALTER TABLE resource_library_editions
  ADD COLUMN resource_type VARCHAR(32) NOT NULL DEFAULT 'json_book' COMMENT 'json_book | crawler | api' AFTER sort_order,
  ADD COLUMN extra_config_json JSON NULL COMMENT 'Type-specific config (crawler URL slot, API base URL, …)' AFTER resource_type;

ALTER TABLE resource_library_editions
  MODIFY COLUMN revision_date DATE NULL COMMENT 'Publication / effective date (nullable for some resource types)';

UPDATE resource_library_editions
SET resource_type = 'json_book'
WHERE resource_type IS NULL OR TRIM(resource_type) = '';
