<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/MissionCatalogService.php';

/** @var array{code:string,name:string,description:string,exercise:array<string,mixed>} $missionDefinition */
$missionDefinition = require __DIR__ . '/mission_canonical/mission_2_1_5.php';
$exercise = $missionDefinition['exercise'];

$currentStatement = $pdo->prepare(
    'SELECT m.*, v.exercise_json
     FROM ipca_missions m
     LEFT JOIN ipca_mission_versions v ON v.id = m.current_version_id
     WHERE m.organization_id = 1 AND UPPER(m.code) = ? LIMIT 1'
);
$currentStatement->execute(array($missionDefinition['code']));
$currentMission = $currentStatement->fetch(PDO::FETCH_ASSOC);
$currentExercise = is_array($currentMission)
    ? json_decode((string)($currentMission['exercise_json'] ?? ''), true)
    : null;
if (is_array($currentMission)
    && is_array($currentExercise)
    && ($currentExercise['schema_version'] ?? '') === 'ipca.mission.exercise.v2'
    && is_array($currentExercise['scenario_plan'] ?? null)
    && is_array($currentExercise['evaluation_rubric'] ?? null)) {
    $mission = $currentMission;
} else {
    $mission = (new MissionCatalogService($pdo))->upsertMission(
        $missionDefinition['code'],
        $missionDefinition['name'],
        $missionDefinition['description'],
        $exercise,
        null
    );
}

$missionVersionId = (int)($mission['current_version_id'] ?? 0);
if ($missionVersionId <= 0) {
    throw new RuntimeException('Canonical mission version was not created.');
}
$documentInsert = $pdo->prepare(
    'INSERT INTO ipca_mission_canonical_documents
     (document_uuid, mission_version_id, document_type, schema_version, source_document,
      source_revision, source_date, content_sha256, content_json)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
foreach (array(
    'scenario_plan' => array($exercise['scenario_plan'], $exercise['source']['scenario'], 'scenario.v1'),
    'evaluation_rubric' => array($exercise['evaluation_rubric'], $exercise['source']['evaluation'], 'rubric.v1'),
) as $documentType => [$content, $source, $documentSchemaVersion]) {
    $contentJson = AuditEventService::jsonEncode($content);
    $contentHash = hash('sha256', $contentJson);
    $existingDocument = $pdo->prepare(
        'SELECT id, content_sha256 FROM ipca_mission_canonical_documents
         WHERE mission_version_id = ? AND document_type = ? LIMIT 1'
    );
    $existingDocument->execute(array($missionVersionId, $documentType));
    $existing = $existingDocument->fetch(PDO::FETCH_ASSOC);
    if (is_array($existing)) {
        if (!hash_equals((string)$existing['content_sha256'], $contentHash)) {
            throw new RuntimeException('Existing canonical ' . $documentType . ' document has different content.');
        }
        continue;
    }
    $documentInsert->execute(array(
        AuditEventService::uuid(),
        $missionVersionId,
        $documentType,
        $documentSchemaVersion,
        (string)$source['document'],
        (string)$source['revision'],
        (string)$source['date'],
        $contentHash,
        $contentJson,
    ));
}

echo json_encode(array('ok' => true, 'mission' => $mission), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
