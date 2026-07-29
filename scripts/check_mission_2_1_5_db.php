<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';

$pdo = cw_db();

$mission = $pdo->prepare('
  SELECT m.id, m.code, m.name, m.current_version_id, m.status, m.updated_at
  FROM ipca_missions m
  WHERE m.organization_id = 1 AND m.code = ?
  LIMIT 1
');
$mission->execute(array('2-1-5'));
$missionRow = $mission->fetch(PDO::FETCH_ASSOC);

if (!is_array($missionRow)) {
    echo json_encode(array('found' => false, 'message' => 'Mission 2-1-5 not found'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$version = $pdo->prepare('
  SELECT id, version_number, description, exercise_json, created_at
  FROM ipca_mission_versions
  WHERE id = ?
  LIMIT 1
');
$version->execute(array((int)$missionRow['current_version_id']));
$versionRow = $version->fetch(PDO::FETCH_ASSOC);
$exercise = json_decode((string)($versionRow['exercise_json'] ?? ''), true);

$docs = $pdo->prepare('
  SELECT document_type, schema_version, source_document, source_revision, source_date, content_sha256, created_at
  FROM ipca_mission_canonical_documents
  WHERE mission_version_id = ?
  ORDER BY document_type
');
$docs->execute(array((int)$missionRow['current_version_id']));
$docRows = $docs->fetchAll(PDO::FETCH_ASSOC);

$versionCount = $pdo->prepare('SELECT COUNT(*) FROM ipca_mission_versions WHERE mission_id = ?');
$versionCount->execute(array((int)$missionRow['id']));

$allVersions = $pdo->prepare('
  SELECT id, version_number,
    JSON_UNQUOTE(JSON_EXTRACT(exercise_json, \'$.schema_version\')) AS schema_version,
    created_at
  FROM ipca_mission_versions
  WHERE mission_id = ?
  ORDER BY version_number ASC
');
$allVersions->execute(array((int)$missionRow['id']));

echo json_encode(array(
    'found' => true,
    'mission' => $missionRow,
    'current_version' => array(
        'id' => (int)($versionRow['id'] ?? 0),
        'version_number' => (int)($versionRow['version_number'] ?? 0),
        'description' => $versionRow['description'] ?? null,
        'created_at' => $versionRow['created_at'] ?? null,
        'schema_version' => is_array($exercise) ? ($exercise['schema_version'] ?? null) : null,
        'has_scenario_plan' => is_array($exercise) && isset($exercise['scenario_plan']),
        'has_evaluation_rubric' => is_array($exercise) && isset($exercise['evaluation_rubric']),
        'task_count' => is_array($exercise['evaluation_rubric']['tasks'] ?? null) ? count($exercise['evaluation_rubric']['tasks']) : 0,
        'srm_count' => is_array($exercise['evaluation_rubric']['srm_items'] ?? null) ? count($exercise['evaluation_rubric']['srm_items']) : 0,
        'chronology_count' => is_array($exercise['scenario_plan']['chronology'] ?? null) ? count($exercise['scenario_plan']['chronology']) : 0,
        'planned_malfunctions' => is_array($exercise) ? ($exercise['scenario_plan']['planned_malfunctions'] ?? null) : null,
        'locations' => is_array($exercise) ? ($exercise['scenario_plan']['locations'] ?? null) : null,
    ),
    'version_count' => (int)$versionCount->fetchColumn(),
    'all_versions' => $allVersions->fetchAll(PDO::FETCH_ASSOC),
    'canonical_documents' => is_array($docRows) ? $docRows : array(),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
