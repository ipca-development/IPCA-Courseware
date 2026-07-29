<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/FlightDebriefService.php';

$root = dirname(__DIR__);
$bundleService = file_get_contents($root . '/src/ManualReconstructionBundleService.php') ?: '';
$bundleMigration = file_get_contents($root . '/scripts/sql/2026_07_29_manual_reconstruction_bundles.sql') ?: '';
$missionSeed = file_get_contents($root . '/scripts/seed_mission_1_4_9_canonical.php') ?: '';
$debriefSource = file_get_contents($root . '/src/FlightDebriefService.php') ?: '';
$debriefMigration = file_get_contents($root . '/scripts/sql/2026_07_29_structured_ai_debrief.sql') ?: '';

$service = (new ReflectionClass(FlightDebriefService::class))->newInstanceWithoutConstructor();
$calculate = new ReflectionMethod(FlightDebriefService::class, 'calculateSuggestedOverall');
$sanitize = new ReflectionMethod(FlightDebriefService::class, 'sanitizeEvidenceRefs');

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
    'structured debrief stores append-only evidence and instructor fields' =>
        str_contains($debriefMigration, 'supersedes_debrief_id')
        && str_contains($debriefMigration, 'evidence_refs_json')
        && str_contains($debriefMigration, 'instructor_grade')
        && str_contains($debriefMigration, 'approved_at'),
    'approval and release are instructor-authoritative and gated' =>
        str_contains($debriefSource, 'Every task and SRM item requires an instructor grade before approval.')
        && str_contains($debriefSource, 'Only an instructor-approved debrief can be released.')
        && str_contains($debriefSource, 'Approved debrief versions are immutable.'),
    'missing transcript evidence never defaults to NO' =>
        str_contains($debriefSource, "'suggested_grade' => \$grade")
        && str_contains($debriefSource, "\$grade = null")
        && str_contains($debriefSource, 'unassessed_items_do_not_default_to_no'),
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
    array('type' => 'transcript', 'source' => 'FlightCircle legacy'),
    array('type' => 'unknown', 'id' => 1),
));
$checks['AI evidence sanitizer only allows CVR evidence types'] =
    count($refs) === 2
    && ($refs[0]['type'] ?? '') === 'transcript'
    && ($refs[1]['type'] ?? '') === 'adsb';

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
