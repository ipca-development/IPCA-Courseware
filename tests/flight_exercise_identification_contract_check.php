<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/src/FlightExerciseIdentificationService.php') ?: '';
$reconstruction = file_get_contents($root . '/src/CockpitReconstructionService.php') ?: '';
$migration = file_get_contents($root . '/scripts/sql/2026_08_07_flight_exercise_identification.sql') ?: '';
$replayPage = file_get_contents($root . '/public/admin/cockpit_recorder_replay.php') ?: '';

$checks = array(
    'catalog + ACS + SOP + detected instance tables exist in migration' =>
        str_contains($migration, 'CREATE TABLE IF NOT EXISTS ipca_flight_exercise_catalog')
        && str_contains($migration, 'CREATE TABLE IF NOT EXISTS ipca_flight_exercise_acs_bindings')
        && str_contains($migration, 'CREATE TABLE IF NOT EXISTS ipca_flight_exercise_sop_bindings')
        && str_contains($migration, 'CREATE TABLE IF NOT EXISTS ipca_detected_flight_exercises'),
    'ACS/SOP foresight defaults evaluation_enabled off' =>
        str_contains($migration, 'evaluation_enabled TINYINT(1) NOT NULL DEFAULT 0')
        && substr_count($migration, ', 0, \'v1\', 1)') >= 5,
    'core exercise seeds include stalls/slow flight/steep turn/UA' =>
        str_contains($migration, "'power_off_stall'")
        && str_contains($migration, "'power_on_stall'")
        && str_contains($migration, "'slow_flight'")
        && str_contains($migration, "'steep_turn'")
        && str_contains($migration, "'unusual_attitude_recovery'"),
    'detector fuses marker + transcript + telemetry evidence' =>
        str_contains($service, 'transcriptAliasHits')
        && str_contains($service, 'telemetryHits')
        && str_contains($service, 'crewEventsForRecording')
        && str_contains($service, "status' => 'identified'"),
    'reconstruction wires identification into replay payload' =>
        str_contains($reconstruction, 'FlightExerciseIdentificationService')
        && str_contains($reconstruction, 'identifiedExerciseRowsForRecording')
        && str_contains($reconstruction, "'identified_exercises' => \$identifiedExercises")
        && str_contains($reconstruction, "marker' => 'green'"),
    'generic Start of Exercises suppressed when classified nearby' =>
        str_contains($reconstruction, "Prefer named identified exercises over generic")
        && str_contains($reconstruction, "type === 'exercise_marker'"),
    'Events UI renders green EXERCISE markers for identified maneuvers' =>
        str_contains($replayPage, 'is-green')
        && str_contains($replayPage, "marker === 'green'")
        && str_contains($replayPage, 'identified_exercises')
        && str_contains($replayPage, "categoryBadgeLabel")
        && str_contains($replayPage, "return 'EXERCISE'"),
);

$failed = array();
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed === array()) {
    fwrite(STDOUT, "flight_exercise_identification_contract_check OK\n");
    exit(0);
}

fwrite(STDERR, "flight_exercise_identification_contract_check FAILED\n");
foreach ($failed as $label) {
    fwrite(STDERR, ' - ' . $label . "\n");
}
exit(1);
