-- Read-only health check for the SPC mission catalogue.
-- Expected after seeding: 110 active missions, 110 current versions, 110 aliases,
-- no orphan rows, no missing current versions, no missing exercise fields.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

SELECT
  'mission_table_counts' AS check_name,
  COUNT(*) AS total_missions,
  SUM(status = 'active') AS active_missions,
  SUM(current_version_id IS NULL) AS missions_without_current_version
FROM ipca_missions;

SELECT
  'version_alias_counts' AS check_name,
  (SELECT COUNT(*) FROM ipca_mission_versions) AS total_versions,
  (SELECT COUNT(*) FROM ipca_mission_aliases) AS total_aliases,
  (SELECT COUNT(*) FROM ipca_mission_import_batches WHERE source = 'mission_catalogue_SPC.sql') AS seed_import_batches;

SELECT
  'bad_current_version_links' AS check_name,
  COUNT(*) AS issue_count
FROM ipca_missions m
LEFT JOIN ipca_mission_versions v ON v.id = m.current_version_id
WHERE m.current_version_id IS NULL OR v.id IS NULL;

SELECT
  'orphan_versions' AS check_name,
  COUNT(*) AS issue_count
FROM ipca_mission_versions v
LEFT JOIN ipca_missions m ON m.id = v.mission_id
WHERE m.id IS NULL;

SELECT
  'orphan_aliases' AS check_name,
  COUNT(*) AS issue_count
FROM ipca_mission_aliases a
LEFT JOIN ipca_missions m ON m.id = a.mission_id
WHERE m.id IS NULL;

SELECT
  'current_versions_missing_exercise_fields' AS check_name,
  COUNT(*) AS issue_count
FROM ipca_missions m
JOIN ipca_mission_versions v ON v.id = m.current_version_id
WHERE JSON_EXTRACT(v.exercise_json, '$.program') IS NULL
   OR JSON_EXTRACT(v.exercise_json, '$.stage') IS NULL
   OR JSON_EXTRACT(v.exercise_json, '$.phase') IS NULL
   OR JSON_EXTRACT(v.exercise_json, '$.scenario') IS NULL;

SELECT
  'duplicate_mission_codes' AS check_name,
  organization_id,
  code,
  COUNT(*) AS duplicate_count
FROM ipca_missions
GROUP BY organization_id, code
HAVING COUNT(*) > 1;

SELECT
  'missions_by_program' AS check_name,
  JSON_UNQUOTE(JSON_EXTRACT(v.exercise_json, '$.program')) AS program,
  COUNT(*) AS mission_count
FROM ipca_missions m
JOIN ipca_mission_versions v ON v.id = m.current_version_id
GROUP BY program
ORDER BY program;

SELECT
  'first_10_active_missions' AS check_name,
  m.code,
  m.name,
  JSON_UNQUOTE(JSON_EXTRACT(v.exercise_json, '$.program')) AS program,
  JSON_UNQUOTE(JSON_EXTRACT(v.exercise_json, '$.stage')) AS stage,
  JSON_UNQUOTE(JSON_EXTRACT(v.exercise_json, '$.phase')) AS phase,
  JSON_UNQUOTE(JSON_EXTRACT(v.exercise_json, '$.scenario')) AS scenario
FROM ipca_missions m
JOIN ipca_mission_versions v ON v.id = m.current_version_id
WHERE m.status = 'active'
ORDER BY m.code
LIMIT 10;
