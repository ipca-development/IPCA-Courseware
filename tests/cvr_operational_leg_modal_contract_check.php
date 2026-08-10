<?php
declare(strict_types=1);

/**
 * Contract: Edit Operational Leg modal is a structured aviation correction UI.
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

$page = $root . '/public/admin/master_logbook_intake.php';
$service = $root . '/src/CvrAdminLegCorrectionService.php';
$phase4 = $root . '/tests/cvr_phase4a_operational_consolidation_contract_check.php';

require_contains($service, 'createAdminClosureEvidenceBatch', 'admin closure mints evidence batch', $failures);
require_absent($service, 'VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', 'admin closure no longer inserts null batch_id', $failures);
require_contains($page, 'Route and Time', 'route section', $failures);
require_contains($page, 'data-leg-section="track"', 'gps track section under route', $failures);
require_contains($page, 'legs-track-map', 'track map container', $failures);
require_contains($page, 'legs-track-timeline', 'track time scrubber', $failures);
require_contains($page, 'legs-track-play', 'track play control', $failures);
require_contains($page, 'leg_track_chart.js', 'track chart script', $failures);
require_contains($page, 'leaflet@1.9.4', 'leaflet map library', $failures);
require_contains($root . '/public/admin/assets/leg_track_chart.js', 'trafficAircraft', 'track chart loads traffic', $failures);
require_contains($root . '/public/admin/assets/leg_track_chart.js', '/api/recordings/replay.php', 'track chart uses replay api', $failures);
require_contains($page, 'Aircraft Meters', 'meters section', $failures);
require_contains($page, 'Fuel and Oil', 'fuel section', $failures);
require_contains($page, 'Takeoffs and Landings', 'ops counts section', $failures);
require_contains($page, 'data-segment-ops="takeoff"', 'multi-leg takeoff inputs editable', $failures);
require_contains($page, 'data-segment-ops="landing"', 'multi-leg landing inputs editable', $failures);
require_contains($page, 'data-segment-meter="hobbs-start"', 'multi-leg hobbs start editable', $failures);
require_contains($page, 'data-segment-meter="tacho-end"', 'multi-leg tacho end editable', $failures);
require_contains($page, 'data-segment-fuel="dep"', 'multi-leg fuel departure editable', $failures);
require_contains($page, 'data-segment-fuel="ldg"', 'multi-leg fuel landing editable', $failures);
require_contains($page, 'syncSegmentMeterFuelTotals', 'sums segment meters/fuel into session totals', $failures);
require_contains($page, 'segmentFieldsEditable', 'meters fuel oil gated until financial lock', $failures);
require_contains($page, 'Unlock this Operational Leg before editing.', 'client blocks ops edits while locked', $failures);
require_contains($page, 'leg_segments[', 'posts per-leg operation counts', $failures);
require_contains($page, 'syncSegmentOperationTotals', 'sums segment TO/LDG into session totals', $failures);
require_contains($service, 'function saveSegmentOperationalValues', 'persists per-leg meters fuel and TO/LDG', $failures);
require_contains($service, 'function saveOperationCounts', 'persists per-leg TO/LDG on sibling dispatches', $failures);
require_contains($service, 'function patchLegSegmentOperationalFields', 'updates annotated leg_segments meters fuel TO/LDG', $failures);
require_contains($service, 'function patchLegSegmentOperationCounts', 'updates annotated leg_segments TO/LDG', $failures);
require_contains($page, 'Garmin CSV Recovery', 'garmin recovery section', $failures);
require_contains($page, 'Save Changes', 'save changes label', $failures);
require_contains($page, 'legs-edit-mission', 'mission controlled select', $failures);
require_contains($page, 'Legacy mission', 'legacy mission preservation', $failures);
require_contains($page, 'legs-crew-catalog', 'crew catalog json', $failures);
require_contains($page, 'Historical crew entry', 'historical crew fallback', $failures);
require_contains($page, 'legs-edit-date', 'separate date field', $failures);
require_contains($page, 'legs-edit-off-time', 'separate off-block time field', $failures);
require_contains($page, 'Off Block Time — Local', 'off block local label', $failures);
require_contains($page, 'Calculated On Block', 'derived on block', $failures);
require_contains($page, 'data-fuel-suffix', 'fuel unit suffix', $failures);
require_contains($page, 'legs-edit-oil-value', 'single oil quantity field', $failures);
require_contains($page, 'legs-edit-oil-suffix', 'oil unit from aircraft config', $failures);
require_contains($page, 'Discard unsaved changes', 'discard warning', $failures);
require_contains($page, 'cvr_intake_aircraft_pill_colors', 'distinct aircraft pill colors', $failures);
require_contains($page, 'pattern="([01][0-9]|2[0-3]):[0-5][0-9]"', '24-hour time pattern', $failures);
require_absent($page, 'id="legs-edit-off"', 'old combined off-block field removed', $failures);
require_absent($page, 'id="legs-edit-oil-pct"', 'no separate oil % editable field', $failures);
require_absent($page, 'name="leg[oil_unit]" id="legs-edit-oil-unit" type="text"', 'no free-text oil unit field', $failures);
require_absent($page, 'Save Leg', 'save leg label removed', $failures);
require_absent($page, 'AM/PM', 'no AM/PM controls', $failures);
require_absent($page, 'id="legs-edit-off-time" type="time"', 'avoid locale-dependent time input on off-block', $failures);

require_contains($service, 'oneDecimal', 'one-decimal meter formatting', $failures);
require_contains($service, 'oil_value', 'oil value mapping', $failures);
require_contains($service, '24-hour clock', 'rejects AM/PM off-block strings', $failures);
require_contains($service, 'Landing fuel cannot exceed departure fuel', 'fuel validation', $failures);

require_contains($page, 'toFixed(1)', 'js one-decimal normalization', $failures);
require_contains($page, 'Flight Instructor', 'approved crew roles', $failures);
require_contains($page, 'Generic Briefing', 'generic briefing section in details modal', $failures);
require_contains($page, 'legs-briefing-copy-box', 'briefing textarea', $failures);
require_contains($page, 'format=generic_briefing', 'loads briefing-only copy', $failures);

$copyApi = $root . '/public/admin/api/operational_leg_debrief_copy.php';
require_contains($copyApi, 'generic_briefing', 'copy api supports generic briefing format', $failures);
require_contains($copyApi, 'cvr_debrief_copy_generic_briefing', 'generic briefing builder', $failures);
$copyApiContents = (string)@file_get_contents($copyApi);
if (!preg_match('/function cvr_debrief_copy_generic_briefing.*?^\}/ms', $copyApiContents, $genericFn)) {
    $failures[] = 'generic briefing function not found for content check';
} elseif (str_contains($genericFn[0], 'MISSION STANDARDS') || str_contains($genericFn[0], 'SUMMARY / NEXT STEPS')) {
    $failures[] = 'generic briefing must exclude grades/mission standards/summary sections';
}

if ($failures !== array()) {
    fwrite(STDERR, "Operational leg modal contract FAILED\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . "\n");
    }
    exit(1);
}

passthru('php ' . escapeshellarg($phase4), $phase4Code);
if ($phase4Code !== 0) {
    fwrite(STDERR, "Phase 4A contract failed while checking leg modal contracts.\n");
    exit($phase4Code);
}

echo "Operational leg modal contract OK\n";
