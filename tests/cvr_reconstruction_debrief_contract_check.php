<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/FlightDebriefService.php';

$root = dirname(__DIR__);
$bundleService = file_get_contents($root . '/src/ManualReconstructionBundleService.php') ?: '';
$bundleMigration = file_get_contents($root . '/scripts/sql/2026_07_29_manual_reconstruction_bundles.sql') ?: '';
$missionSeed = file_get_contents($root . '/scripts/seed_mission_1_4_9_canonical.php') ?: '';
$mission215Seed = file_get_contents($root . '/scripts/mission_canonical/mission_2_1_5.php') ?: '';
$debriefSource = file_get_contents($root . '/src/FlightDebriefService.php') ?: '';
$debriefMigration = file_get_contents($root . '/scripts/sql/2026_07_29_structured_ai_debrief.sql') ?: '';
$manualWorker = file_get_contents($root . '/public/admin/api/manual_bundle_reconstruct.php') ?: '';
$workerScript = file_get_contents($root . '/scripts/run_cockpit_recorder_reconstruction.php') ?: '';
$debriefEndpoint = file_get_contents($root . '/public/admin/api/manual_bundle_debrief.php') ?: '';
$debriefWorker = file_get_contents($root . '/scripts/run_structured_flight_debrief.php') ?: '';
$debriefPage = file_get_contents($root . '/public/admin/master_logbook_intake.php') ?: '';
$derivationService = file_get_contents($root . '/src/FlightRecordDerivationService.php') ?: '';
$genericDebriefMigration = file_get_contents($root . '/scripts/sql/2026_08_01_generic_mission_debrief.sql') ?: '';

$service = (new ReflectionClass(FlightDebriefService::class))->newInstanceWithoutConstructor();
$calculate = new ReflectionMethod(FlightDebriefService::class, 'calculateSuggestedOverall');
$sanitize = new ReflectionMethod(FlightDebriefService::class, 'sanitizeEvidenceRefs');
$genericExerciseMethod = new ReflectionMethod(FlightDebriefService::class, 'genericExercise');
$genericExercise = $genericExerciseMethod->invoke($service, '7-1-5');

$evaluation = static fn(string $id, string $grade, string $required = 'PR', string $type = 'task', string $completion = 'completed'): array => array(
    'rubric_item_id' => $id,
    'rubric_type' => $type,
    'suggested_grade' => $grade,
    'required_standard' => $required,
    'completion_status' => $completion,
    'required' => true,
);

$checks = array(
    'bundle source allowlist excludes FlightCircle and historical evidence' =>
        str_contains($bundleService, "str_contains(\$encoded, 'flightcircle')")
        && str_contains($bundleService, "str_contains(\$encoded, 'historical')")
        && !str_contains($bundleMigration, 'flightcircle'),
    'frozen bundle has immutable manifest and superseding versions' =>
        str_contains($bundleMigration, 'manifest_sha256')
        && str_contains($bundleMigration, 'supersedes_bundle_id')
        && str_contains($bundleService, "status, dispatch_id")
        && str_contains($bundleService, "\\'frozen\\'"),
    'transcript gate requires ready non-empty chunks and version lock' =>
        str_contains($bundleService, "!== 'ready'")
        && str_contains($bundleService, 'Raw transcript is empty.')
        && str_contains($bundleService, 'transcript_snapshot_id'),
    'manual worker receives hash-verified selected Garmin CSV' =>
        str_contains($bundleService, 'Frozen Garmin CSV hash verification failed.')
        && str_contains($manualWorker, '--g3x-csv-path=')
        && str_contains($workerScript, "\$options['g3x_csv_path']"),
    'background worker synchronizes bundle completion and errors' =>
        str_contains($workerScript, 'updateManualBundleReconstruction')
        && str_contains($workerScript, "'reconstruction_complete'")
        && str_contains($workerScript, "'failed'"),
    'debrief generation runs asynchronously outside web request' =>
        str_contains($debriefEndpoint, 'run_structured_flight_debrief.php')
        && str_contains($debriefEndpoint, "'generate_structured_debrief'")
        && str_contains($debriefWorker, 'generateStructuredDebrief')
        && str_contains($debriefWorker, "status = 'succeeded'")
        && str_contains($debriefWorker, "status = 'failed'"),
    'canonical 1-4-9 includes scenario and rubric documents' =>
        str_contains($missionSeed, "'scenario_plan'")
        && str_contains($missionSeed, "'evaluation_rubric'")
        && substr_count($missionSeed, "array('") >= 50,
    'canonical mission includes required maneuver and malfunction sequence' =>
        str_contains($missionSeed, 'slow_flight')
        && str_contains($missionSeed, 'power_off_stalls')
        && str_contains($missionSeed, 'communication_failure')
        && str_contains($missionSeed, 'flap_failure')
        && str_contains($missionSeed, 'forward_slip'),
    'canonical 2-1-5 includes scenario plan rubric and phase 1 progress check sequence' =>
        str_contains($mission215Seed, "'scenario_plan'")
        && str_contains($mission215Seed, "'evaluation_rubric'")
        && str_contains($mission215Seed, 'takeoff.soft_field')
        && str_contains($mission215Seed, 'takeoff.short_field')
        && str_contains($mission215Seed, 'Engine failure in takeoff/climb')
        && str_contains($mission215Seed, 'Salton Sea Training Area'),
    'missing canonical mission data uses a generic evidence-led rubric' =>
        ($genericExercise['metadata']['canonical'] ?? true) === false
        && ($genericExercise['metadata']['mission_code'] ?? '') === '7-1-5'
        && count($genericExercise['evaluation_rubric']['tasks'] ?? array()) >= 4
        && count($genericExercise['evaluation_rubric']['srm_items'] ?? array()) >= 4
        && str_contains($debriefSource, 'uses_generic_mission_rubric')
        && !str_contains($debriefSource, "throw new RuntimeException('Mission requires a canonical scenario_plan and evaluation_rubric.')")
        && str_contains($genericDebriefMigration, 'mission_version_id BIGINT UNSIGNED NULL'),
    'structured debrief stores append-only evidence and instructor fields' =>
        str_contains($debriefMigration, 'supersedes_debrief_id')
        && str_contains($debriefMigration, 'evidence_refs_json')
        && str_contains($debriefMigration, 'instructor_grade')
        && str_contains($debriefMigration, 'approved_at'),
    'one-step verification accepts generated grades without manual regrading' =>
        str_contains($debriefSource, "array('ai_draft', 'instructor_draft')")
        && str_contains($debriefSource, 'SET instructor_grade = suggested_grade')
        && str_contains($debriefSource, "'instructor_verified'")
        && !str_contains($debriefSource, 'Every task and SRM item requires an instructor grade before approval.')
        && str_contains($debriefSource, 'Only an instructor-approved debrief can be released.')
        && str_contains($debriefSource, 'Approved debrief versions are immutable.'),
    'debrief sheet uses modern printable grading layout' =>
        str_contains($debriefPage, 'class="debrief-sheet"')
        && str_contains($debriefPage, 'Verify Debriefing Sheet')
        && str_contains($debriefPage, 'Adjust generated sheet (optional)')
        && str_contains($debriefPage, 'Print / Save as PDF')
        && str_contains($debriefPage, '@media print')
        && str_contains($debriefPage, 'border-radius:22px'),
    'evidence is rendered as readable disclosures instead of JSON' =>
        str_contains($debriefPage, 'cvr_debrief_evidence_label')
        && str_contains($debriefPage, 'Supporting evidence')
        && !str_contains($debriefPage, "json_encode(\$segment['evidence_refs']")
        && str_contains($debriefSource, 'Never put JSON syntax, array notation, hashes, database IDs'),
    'debrief includes copy-ready chronology and canonical logbook fields' =>
        str_contains($debriefPage, 'Copy-ready chronological review')
        && str_contains($debriefPage, 'Copy Full Review')
        && str_contains($debriefPage, 'Flight and Logbook Record')
        && str_contains($debriefPage, '<th>LD-D</th>')
        && str_contains($debriefSource, 'ipca_operational_flight_leg_versions')
        && str_contains($debriefSource, 'ipca_flight_record_logbook_proposals'),
    'logbook block times use App OFF Block and crew Hobbs duration' =>
        str_contains($debriefSource, "event_type = \\'engine_start_off_block\\'")
        && str_contains($debriefSource, 'off_block_plus_hobbs_delta')
        && str_contains($debriefSource, 'ON Block discrepancy: App Engine Stop')
        && str_contains($debriefPage, "\$context['engine_start_utc']")
        && str_contains($debriefPage, "\$context['engine_stop_utc']"),
    'logbook block times display in aircraft operational local time' =>
        str_contains($debriefSource, 'operational_timezone')
        && str_contains($debriefSource, 'cw_logbook_display_timezone')
        && str_contains($debriefSource, 'cw_logbook_time')
        && str_contains((string) file_get_contents($root . '/src/time.php'), 'cw_airport_timezone')
        && str_contains($debriefPage, 'cvr_intake_timestamp($context[\'engine_start_utc\']')
        && str_contains($debriefPage, '$logbookTimezone'),
    'logbook block times fall back to flight record legs and hobbs duration' =>
        str_contains($debriefSource, 'enrichLogbookContext')
        && str_contains($debriefSource, 'allocation_start_utc')
        && str_contains($debriefSource, 'off_block_plus_hobbs_delta')
        && str_contains($debriefSource, 'flightRecordEventUtc'),
    'logbook hobbs uses garmin start and crew end with one decimal' =>
        str_contains($debriefSource, 'garmin_hobbs_start_hours')
        && str_contains($debriefSource, 'starting_hobbs')
        && str_contains($debriefSource, 'appendDispatchStartVerification')
        && str_contains($debriefSource, 'roundLogbookMeter')
        && str_contains($debriefSource, 'ending_hobbs'),
    'canonical derivation returns linkable Flight Record version' =>
        str_contains($derivationService, "'flight_record_version_id' => (int)\$version['id']")
        && str_contains($bundleService, "\$derived['flight_record_version_id']")
        && str_contains($bundleService, 'rebuildFlightRecord')
        && (str_contains($debriefPage, 'Rebuild Flight Record') || str_contains($debriefPage, 'Re-derive Flight Record')),
    'ready frozen reconstruction keeps a direct Replay action' =>
        str_contains($debriefPage, '$bundleReplayReady')
        && str_contains($debriefPage, '/admin/cockpit_recorder_replay.php?id=')
        && str_contains($debriefPage, 'Open Replay')
        && str_contains($debriefPage, 'it does not rebuild Replay'),
    'approval and release remain immutable and instructor-authoritative' =>
        str_contains($debriefSource, 'accepted suggestions are now authoritative')
        && str_contains($debriefSource, 'Only an instructor-approved debrief can be released.')
        && str_contains($debriefSource, 'Approved debrief versions are immutable.'),
    'missing transcript evidence never defaults to NO' =>
        str_contains($debriefSource, "'suggested_grade' => \$grade")
        && str_contains($debriefSource, "\$grade = null")
        && str_contains($debriefSource, 'unassessed_items_do_not_default_to_no'),
    'chief instructor voice avoids template debrief structure' =>
        str_contains($debriefSource, 'v5-chief-instructor-voice')
        && str_contains($debriefSource, 'Never prefix titles with letters or numbers')
        && str_contains($debriefSource, 'Do NOT use the same subsection template every time')
        && str_contains($debriefSource, 'Explain WHY corrections matter')
        && str_contains($debriefSource, 'excited to go fly again'),
    'chronological section labels are deduplicated in UI and normalization' =>
        str_contains($debriefSource, 'sanitizeChronologicalTitle')
        && str_contains($debriefPage, 'cvr_debrief_segment_label')
        && str_contains($debriefPage, 'cvr_debrief_segment_label((string)($segment[\'title\']'),
);

$blue = $calculate->invoke($service, array(
    $evaluation('1', 'PE'), $evaluation('2', 'PR'), $evaluation('3', 'PR'), $evaluation('4', 'PR'),
));
$green = $calculate->invoke($service, array(
    $evaluation('1', 'PR'), $evaluation('2', 'PR'), $evaluation('3', 'PR'), $evaluation('4', 'PR'),
));
$yellow = $calculate->invoke($service, array(
    $evaluation('1', 'EX'), $evaluation('2', 'PR'), $evaluation('3', 'PR'), $evaluation('4', 'PR'),
));
$red = $calculate->invoke($service, array(
    $evaluation('1', 'EX'), $evaluation('2', 'PR'), $evaluation('3', 'PR'),
));
$incomplete = $calculate->invoke($service, array(
    $evaluation('1', '', 'PR', 'task', 'not_completed'),
));
$safetyRed = $calculate->invoke($service, array(
    $evaluation('srm.safety_management', 'PR', 'MD', 'srm'),
    $evaluation('srm.task_management', 'MD', 'MD', 'srm'),
    $evaluation('srm.risk_management', 'MD', 'MD', 'srm'),
));
$checks['overall grading boundaries are deterministic'] =
    $blue['result'] === 'BLUE'
    && $green['result'] === 'GREEN'
    && $yellow['result'] === 'YELLOW'
    && $red['result'] === 'RED'
    && $incomplete['result'] === 'INCOMPLETE'
    && $safetyRed['result'] === 'RED';

$refs = $sanitize->invoke($service, array(
    array('type' => 'transcript', 'chunk' => 4),
    array('type' => 'adsb', 'claim' => 'traffic context only'),
    "Transcript chunk 2 (600-900s): doors locked and flap checks",
    array('source' => 'G3X replay', 'time_range' => '1200-1260s'),
    array('type' => 'transcript', 'source' => 'FlightCircle legacy'),
    array('type' => 'unknown', 'id' => 1),
));
$checks['AI evidence sanitizer only allows CVR evidence types'] =
    count($refs) === 4
    && ($refs[0]['type'] ?? '') === 'transcript'
    && ($refs[1]['type'] ?? '') === 'adsb'
    && ($refs[2]['type'] ?? '') === 'transcript'
    && ($refs[3]['type'] ?? '') === 'garmin';

$checks['flight record continuity JSON path uses MySQL string quotes'] =
    str_contains($derivationService, "JSON_EXTRACT(v.summary_json, \\'$.csv_file_id\\')")
    && !str_contains($derivationService, 'JSON_EXTRACT(v.summary_json, "$.csv_file_id")');

$failed = array();
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== array()) {
    fwrite(STDERR, 'Failed Reconstruction/Debrief checks: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK: Reconstruction and Debrief contract checks passed.' . PHP_EOL;
