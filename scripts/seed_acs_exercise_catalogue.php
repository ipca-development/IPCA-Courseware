<?php
declare(strict_types=1);

/**
 * Seed ipca_flight_exercise_catalog + ACS bindings from FAA ACS task inventories.
 *
 * Sources: Private Pilot Airplane (FAA-S-ACS-6C), Commercial Pilot Airplane (FAA-S-ACS-7B),
 * Instrument Rating Airplane (FAA-S-ACS-8C).
 *
 * Usage:
 *   php scripts/seed_acs_exercise_catalogue.php
 *   php scripts/seed_acs_exercise_catalogue.php --apply
 */

$root = dirname(__DIR__);
require_once $root . '/src/db.php';

$apply = in_array('--apply', $argv, true);
$jsonPath = $root . '/scripts/data/acs_exercise_catalogue_seed.json';
if (!is_file($jsonPath)) {
    fwrite(STDERR, "Missing seed data: {$jsonPath}\n");
    exit(1);
}

$raw = file_get_contents($jsonPath);
$data = json_decode((string)$raw, true);
if (!is_array($data)) {
    fwrite(STDERR, "Invalid seed JSON\n");
    exit(1);
}

$exercises = is_array($data['exercises'] ?? null) ? $data['exercises'] : array();
$bindings = is_array($data['acs_bindings'] ?? null) ? $data['acs_bindings'] : array();
if ($exercises === array() || $bindings === array()) {
    fwrite(STDERR, "Seed JSON has no exercises/bindings\n");
    exit(1);
}

$pdo = cw_db();

$catalogExists = (bool)$pdo->query(
    "SELECT 1 FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_flight_exercise_catalog' LIMIT 1"
)->fetchColumn();
if (!$catalogExists) {
    fwrite(STDERR, "ipca_flight_exercise_catalog missing. Apply 2026_08_07_flight_exercise_identification.sql first.\n");
    exit(1);
}

echo 'source=' . (string)($data['source'] ?? 'unknown') . "\n";
echo 'exercises=' . count($exercises) . "\n";
echo 'acs_bindings=' . count($bindings) . "\n";
echo 'mode=' . ($apply ? 'APPLY' : 'DRY_RUN') . "\n";

if (!$apply) {
    echo "Re-run with --apply to write rows.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $upsertExercise = $pdo->prepare(
        'INSERT INTO ipca_flight_exercise_catalog
           (exercise_code, display_name, description_text, transcript_aliases_json, detection_rules_json,
            detector_version, is_active, sort_order)
         VALUES (?, ?, ?, ?, ?, \'v1\', 1, ?)
         ON DUPLICATE KEY UPDATE
           display_name = VALUES(display_name),
           description_text = VALUES(description_text),
           transcript_aliases_json = VALUES(transcript_aliases_json),
           -- Preserve richer telemetry rules when already customized; otherwise take seed rules.
           detection_rules_json = IF(
             JSON_LENGTH(COALESCE(detection_rules_json, JSON_OBJECT()), \'$.telemetry\') > 0,
             detection_rules_json,
             VALUES(detection_rules_json)
           ),
           is_active = 1,
           sort_order = VALUES(sort_order)'
    );

    foreach ($exercises as $exercise) {
        $code = (string)($exercise['exercise_code'] ?? '');
        $name = (string)($exercise['display_name'] ?? '');
        if ($code === '' || $name === '') {
            continue;
        }
        $aliases = is_array($exercise['aliases'] ?? null) ? array_values($exercise['aliases']) : array();
        $rules = is_array($exercise['rules'] ?? null) ? $exercise['rules'] : array();
        $aliasesJson = json_encode($aliases, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $rulesJson = json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $upsertExercise->execute(array(
            $code,
            $name,
            (string)($exercise['description_text'] ?? ''),
            $aliasesJson,
            $rulesJson,
            (int)($exercise['sort_order'] ?? 1000),
        ));
    }

    $upsertAcs = $pdo->prepare(
        'INSERT INTO ipca_flight_exercise_acs_bindings
           (exercise_code, qualification_code, acs_task_code, acs_task_title, acs_area_title,
            criteria_json, evaluation_enabled, evaluator_version, is_active)
         VALUES (?, ?, ?, ?, ?, ?, 0, \'v1\', 1)
         ON DUPLICATE KEY UPDATE
           acs_task_title = VALUES(acs_task_title),
           acs_area_title = VALUES(acs_area_title),
           evaluation_enabled = 0,
           is_active = 1'
    );

    $criteria = json_encode(array('evaluation_enabled' => false, 'elements' => array()), JSON_UNESCAPED_SLASHES);
    foreach ($bindings as $binding) {
        $upsertAcs->execute(array(
            (string)$binding['exercise_code'],
            (string)$binding['qualification_code'],
            (string)$binding['acs_task_code'],
            (string)$binding['acs_task_title'],
            (string)$binding['acs_area_title'],
            $criteria,
        ));
    }

    // Correct outdated unusual-attitude IR binding from early seed (IR.IV.A -> IR.IV.B).
    $pdo->exec(
        "DELETE FROM ipca_flight_exercise_acs_bindings
          WHERE exercise_code = 'unusual_attitude_recovery'
            AND qualification_code = 'instrument_rating_airplane'
            AND acs_task_code = 'IR.IV.A'"
    );

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$catalogCount = (int)$pdo->query('SELECT COUNT(*) FROM ipca_flight_exercise_catalog')->fetchColumn();
$acsCount = (int)$pdo->query('SELECT COUNT(*) FROM ipca_flight_exercise_acs_bindings')->fetchColumn();
$byQual = $pdo->query(
    'SELECT qualification_code, COUNT(*) AS n
       FROM ipca_flight_exercise_acs_bindings
      GROUP BY qualification_code
      ORDER BY qualification_code'
)->fetchAll(PDO::FETCH_ASSOC);

echo "catalog_rows={$catalogCount}\n";
echo "acs_binding_rows={$acsCount}\n";
foreach ($byQual as $row) {
    echo 'acs_' . $row['qualification_code'] . '=' . $row['n'] . "\n";
}
echo "OK\n";
