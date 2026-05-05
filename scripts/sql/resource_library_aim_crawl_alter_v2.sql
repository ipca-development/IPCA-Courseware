-- Upgrade if you already ran an older resource_library_aim_crawl.sql (active status, no thumbnail).
-- If a statement errors (e.g. duplicate column), skip that line and continue.
--
-- mysql ... < scripts/sql/resource_library_aim_crawl_alter_v2.sql

ALTER TABLE resource_library_crawler_sources
  ADD COLUMN thumbnail_path VARCHAR(1024) NULL COMMENT 'Site-relative path or URL for card cover' AFTER notes;

ALTER TABLE resource_library_crawler_sources MODIFY COLUMN status VARCHAR(24) NOT NULL DEFAULT 'draft';
UPDATE resource_library_crawler_sources SET status = 'live' WHERE status = 'active';
ALTER TABLE resource_library_crawler_sources
  MODIFY COLUMN status ENUM('draft', 'live', 'archived') NOT NULL DEFAULT 'draft';
