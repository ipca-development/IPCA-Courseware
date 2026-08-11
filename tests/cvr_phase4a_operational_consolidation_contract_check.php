<?php
declare(strict_types=1);

/**
 * Phase 4A – Operational Data Consolidation contract check.
 */

$root = dirname(__DIR__);
$failures = array();

function require_contains(string $path, string $needle, string $label, array &$failures): void
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        $failures[] = "missing file: {$path}";
        return;
    }
    if (!str_contains($contents, $needle)) {
        $failures[] = "{$label}: expected `{$needle}` in {$path}";
    }
}

function require_absent(string $path, string $needle, string $label, array &$failures): void
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        $failures[] = "missing file: {$path}";
        return;
    }
    if (str_contains($contents, $needle)) {
        $failures[] = "{$label}: unexpected `{$needle}` in {$path}";
    }
}

$block = $root . '/src/CvrOperationalBlockTimeService.php';
$intake = $root . '/src/CvrDataIntakeReadService.php';
$flightLog = $root . '/src/CvrFlightLogService.php';
$page = $root . '/public/admin/master_logbook_intake.php';
$diag = $root . '/scripts/cvr_phase4a_operational_discrepancy_report.php';

require_contains($block, 'class CvrOperationalBlockTimeService', 'block time SSOT', $failures);
require_contains($block, 'function derivedOnBlockUtc', 'On Block derivation', $failures);
require_contains($block, 'function presentationStatuses', 'preferred sync vocabulary', $failures);
require_contains($block, 'Stored on Device', 'stored-on-device label', $failures);
require_contains($block, 'Server Verified', 'server-verified label', $failures);
require_contains($block, 'Audio Uploaded', 'audio-uploaded label', $failures);
require_contains($block, 'Transcript Processing', 'transcript-processing label', $failures);
require_contains($block, 'function parseCrew', 'crew role parser', $failures);

require_contains($intake, 'CvrOperationalBlockTimeService', 'intake uses block SSOT', $failures);
require_contains($intake, 'planned_departure_airport', 'intake reads departure airport', $failures);
require_contains($intake, 'planned_destination_airport', 'intake reads arrival airport', $failures);
require_contains($intake, 'audioStatusByFlightRecord', 'batched audio status', $failures);
require_contains($intake, 'engine_time_hours', 'engine time hours', $failures);
require_contains($intake, 'airborne_time_hours', 'airborne time where available', $failures);
require_contains($intake, 'sync_status', 'sync status field', $failures);
require_absent($intake, "event_type IN ('engine_shutdown_on_block', 'transient_stop_on_block')", 'intake must not use button-press On Block', $failures);

require_contains($flightLog, 'CvrOperationalBlockTimeService', 'flight log uses block SSOT', $failures);
require_contains($flightLog, 'mission_code', 'flight log exposes mission', $failures);
require_contains($flightLog, 'crew_members', 'flight log exposes crew roles', $failures);
require_contains($flightLog, 'sync_status', 'flight log sync presentation', $failures);
require_absent($flightLog, 'arrival_event.timestamp_utc', 'flight log must not prefer button-press arrival', $failures);

require_contains($page, 'Operational Legs', 'intake panel title', $failures);
require_contains($page, 'Off Block', 'off block column', $failures);
require_contains($page, 'On Block', 'on block label', $failures);
require_contains($page, 'Hobbs', 'hobbs meters visible', $failures);
require_contains($page, 'Tacho', 'tacho meters visible', $failures);
require_contains($page, 'legs-fuel-bar', 'fuel remaining gauge', $failures);
require_contains($page, 'legs-oil', 'oil departure value', $failures);
require_contains($page, 'Evidence', 'evidence column', $failures);
require_contains($page, 'legs-route', 'dispatcher route strip', $failures);
require_contains($page, 'legs-crew-name', 'crew name primary', $failures);
require_contains($page, 'legs-meters', 'grouped aircraft meters', $failures);
require_contains($page, 'Replay', 'replay primary action', $failures);
require_absent($page, '<th>Fuel Dep</th>', 'raw fuel-dep column removed from dispatch board', $failures);
require_absent($page, 'KTRMKay', 'route/crew collision marker must not be hardcoded', $failures);
require_contains($page, 'legs_aircraft', 'aircraft filter', $failures);
require_contains($page, 'cvr_intake_california_timezone', 'california/local timezone helper', $failures);
require_contains($page, 'cvr_intake_local_time($pdo', 'local time helper used for display', $failures);
$localTimeFn = '';
if (preg_match('/function cvr_intake_local_time\(.*?^\}/ms', (string)@file_get_contents($page), $m)) {
    $localTimeFn = $m[0];
}
if ($localTimeFn === '' || !str_contains($localTimeFn, 'cvr_intake_california_timezone')) {
    $failures[] = 'cvr_intake_local_time must convert via cvr_intake_california_timezone (not raw UTC)';
}
require_contains($page, '30 / page', 'pagination page size', $failures);
require_contains($page, 'save_operational_leg', 'admin leg save action', $failures);
require_contains($page, 'hide_operational_leg', 'soft remove action', $failures);
require_contains($page, 'legs_show_removed', 'show removed filter', $failures);
require_contains($page, 'Generic Briefing', 'details modal generic briefing', $failures);
require_contains($page, 'format=generic_briefing', 'loads generic briefing copy', $failures);
require_contains($page, 'legs-briefing-copy', 'briefing copy button', $failures);
require_absent($page, 'Server Receipt', 'technical receipt column removed from operational legs table', $failures);
require_contains($intake, 'oil_quantity', 'intake exposes oil departure quantity', $failures);
require_contains($intake, 'has_garmin_csv', 'intake exposes garmin flight data flag', $failures);
require_contains($intake, 'dateFromLocal', 'intake supports date-range filter', $failures);
require_contains($intake, 'onlyHidden', 'intake supports soft-removed filter', $failures);
require_contains($intake, 'recordingMetaByFlightRecord', 'intake batches recording ids for replay', $failures);

require_contains($diag, 'Phase 4A operational discrepancy report', 'discrepancy diagnostic', $failures);
require_contains($diag, 'proposed_correction', 'discrepancy proposes correction', $failures);

require_once $block;
$blocks = new CvrOperationalBlockTimeService();

// Off Block + Hobbs → On Block (0.5h)
$on = $blocks->derivedOnBlockUtc(array(
    'off_block_utc' => '2026-03-08 10:00:00.000',
    'starting_hobbs' => 100.0,
    'ending_hobbs' => 100.5,
));
if ($on === null || !str_starts_with($on, '2026-03-08 10:30:00')) {
    $failures[] = 'derivedOnBlockUtc 0.5h Hobbs delta failed: ' . ($on ?? 'null');
}

// Operational Hobbs always rounds upward to one decimal before time derivation.
$roundedUpOn = $blocks->derivedOnBlockUtc(array(
    'off_block_utc' => '2026-03-08 10:00:00.000',
    'starting_hobbs' => 100.0,
    'ending_hobbs' => 100.501,
));
if ($roundedUpOn === null || !str_starts_with($roundedUpOn, '2026-03-08 10:36:00')) {
    $failures[] = 'derivedOnBlockUtc must round 0.501h upward to 0.6h: ' . ($roundedUpOn ?? 'null');
}

// DST spring-forward day in America/Los_Angeles: UTC math must stay wall-clock-independent
$offUtc = new DateTimeImmutable('2026-03-08 09:00:00', new DateTimeZone('UTC'));
$onUtc = $blocks->derivedOnBlockUtc(array(
    'off_block_utc' => $offUtc->format('Y-m-d H:i:s.v'),
    'starting_hobbs' => 50.0,
    'ending_hobbs' => 51.0,
));
$onParsed = $onUtc !== null ? new DateTimeImmutable($onUtc, new DateTimeZone('UTC')) : null;
if ($onParsed === null || $onParsed->getTimestamp() - $offUtc->getTimestamp() !== 3600) {
    $failures[] = 'DST-safe UTC On Block derivation failed';
}
$offLocal = $offUtc->setTimezone(new DateTimeZone('America/Los_Angeles'));
$onLocal = $onParsed?->setTimezone(new DateTimeZone('America/Los_Angeles'));
if ($offLocal === null || $onLocal === null) {
    $failures[] = 'California timezone conversion failed';
}

$crew = $blocks->parseCrew(json_encode(array(
    array('personName' => 'Alex Pilot', 'role' => 'PIC'),
    array('person_name' => 'Sam Student', 'crew_role' => 'Student'),
)));
if (count($crew) !== 2 || $crew[0]['display'] !== 'Alex Pilot (PIC)') {
    $failures[] = 'parseCrew personName/roles failed';
}

$presentation = $blocks->presentationStatuses(true, true, true, 'uploaded', 'ready');
if (($presentation['sync_status'] ?? '') !== 'Complete') {
    $failures[] = 'presentationStatuses Complete mapping failed';
}
$presentationPending = $blocks->presentationStatuses(false, false, false, 'missing', 'pending');
if (($presentationPending['sync_status'] ?? '') !== 'Stored on Device') {
    $failures[] = 'presentationStatuses Stored on Device mapping failed';
}

foreach (array($block, $intake, $flightLog, $page, $diag) as $path) {
    $out = array();
    $code = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $failures[] = 'php -l failed for ' . $path . ': ' . implode(' ', $out);
    }
}

if ($failures) {
    fwrite(STDERR, "Phase 4A contract FAILED\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "Phase 4A operational consolidation contract OK\n");
exit(0);
