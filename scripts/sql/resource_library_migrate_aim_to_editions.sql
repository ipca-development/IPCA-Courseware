-- Safe copy: old resource_library_crawler_sources → resource_library_editions (crawler rows).
-- Run only if resource_library_crawler_sources still exists and extend_types.sql was applied.
--
-- After this INSERT, manually migrate aim_paragraphs / crawler_runs from source_id to edition_id
-- (or rebuild from a fresh crawl). New installs should use scripts/sql/resource_library_aim_crawl.sql only.

INSERT INTO resource_library_editions (
  title, revision_code, revision_date, status, thumbnail_path, work_code, sort_order, resource_type, extra_config_json
)
SELECT
  s.label,
  COALESCE(NULLIF(TRIM(s.change_number), ''), 'AIM'),
  s.effective_date,
  s.status,
  s.thumbnail_path,
  CONCAT('CRAWLER_', UPPER(s.crawler_slot)),
  50,
  'crawler',
  JSON_OBJECT(
    'crawler_slot', s.crawler_slot,
    'crawler_type', s.crawler_type,
    'allowed_url_prefix', s.allowed_url_prefix,
    'notes', s.notes
  )
FROM resource_library_crawler_sources s
WHERE NOT EXISTS (
  SELECT 1 FROM resource_library_editions e
  WHERE e.resource_type = 'crawler'
    AND e.work_code = CONCAT('CRAWLER_', UPPER(s.crawler_slot))
  LIMIT 1
);
