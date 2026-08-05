<?php
declare(strict_types=1);

/**
 * Phase 4A discrepancy diagnostic for CVR operational legs already on the server.
 *
 * Usage:
 *   php scripts/cvr_phase4a_operational_discrepancy_report.php
 *   php scripts/cvr_phase4a_operational_discrepancy_report.php --limit=50
 *
 * Read-only. Does not mutate data.
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/CvrDataIntakeReadService.php';
require_once __DIR__ . '/../src/CvrOperationalBlockTimeService.php';

$limit = 100;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(500, (int)substr($arg, 8)));
    }
}

$intake = new CvrDataIntakeReadService($pdo);
$blocks = new CvrOperationalBlockTimeService();
$result = $intake->dispatchRows($limit);

if (!$result['available']) {
    fwrite(STDERR, $result['message'] . PHP_EOL);
    exit(1);
}

$discrepancies = array();
foreach ($result['rows'] as $row) {
    $issues = array();
    $off = trim((string)($row['off_block_utc'] ?? ''));
    $on = trim((string)($row['on_block_utc'] ?? ''));
    $startHobbs = $row['starting_hobbs'] ?? null;
    $endHobbs = $row['ending_hobbs'] ?? null;

    if ($off === '') {
        $issues[] = array(
            'field' => 'Off Block',
            'expected' => 'engine_start_off_block timestamp (or Check-In closure off_block_utc)',
            'actual' => '(blank)',
            'source' => 'ipca_cvr_flight_events / closure payload',
            'root_cause' => 'Engine Start event not synced or not linked to workflow_flight_record_uuid',
            'proposed_correction' => 'SYNC NOW from device Log; verify event row exists for this flight record UUID',
        );
    }

    if (is_numeric($startHobbs) && is_numeric($endHobbs) && $off !== '') {
        $expectedOn = $blocks->derivedOnBlockUtc(array(
            'off_block_utc' => $off,
            'starting_hobbs' => $startHobbs,
            'ending_hobbs' => $endHobbs,
            'closure_on_block_utc' => null,
        ));
        if ($expectedOn !== null && $on !== '' && abs(strtotime($on) - strtotime($expectedOn)) > 60) {
            $issues[] = array(
                'field' => 'On Block',
                'expected' => $expectedOn . ' UTC (Off Block + Hobbs delta)',
                'actual' => $on . ' UTC',
                'source' => 'CvrOperationalBlockTimeService::derivedOnBlockUtc',
                'root_cause' => 'Display path used button-press or non-Hobbs-derived On Block',
                'proposed_correction' => 'Use Phase 4A derived On Block only; redeploy admin intake / flight log services',
            );
        }
        if ($on === '') {
            $issues[] = array(
                'field' => 'On Block',
                'expected' => $expectedOn ?? '(Off + Hobbs)',
                'actual' => '(blank)',
                'source' => 'Check-In ending_hobbs + Off Block',
                'root_cause' => 'Check-In/closure not received or ending meters missing',
                'proposed_correction' => 'Confirm flight_record_closure synced for this leg',
            );
        }
    }

    if (trim((string)($row['departure_airport'] ?? '')) === '' || trim((string)($row['arrival_airport'] ?? '')) === '') {
        $issues[] = array(
            'field' => 'Airports',
            'expected' => 'planned_departure_airport / planned_destination_airport from Dispatch payload',
            'actual' => trim((string)($row['departure_airport'] ?? '')) . ' → ' . trim((string)($row['arrival_airport'] ?? '')),
            'source' => 'ipca_cvr_dispatch_versions.payload_json',
            'root_cause' => 'Dispatch version payload missing airports or version join failed',
            'proposed_correction' => 'Inspect dispatch_versions payload for this dispatch_uuid',
        );
    }

    if ($issues === array()) {
        continue;
    }

    $discrepancies[] = array(
        'aircraft' => (string)($row['aircraft_registration'] ?? ''),
        'mission' => (string)($row['mission_code'] ?? ''),
        'dispatch_uuid' => (string)($row['dispatch_uuid'] ?? ''),
        'flight_record_uuid' => (string)($row['workflow_flight_record_uuid'] ?? ''),
        'reservation_uuid' => (string)($row['reservation_uuid'] ?? ''),
        'leg_uuid' => (string)($row['leg_uuid'] ?? ''),
        'sync_status' => (string)($row['sync_status'] ?? ''),
        'issues' => $issues,
    );
}

echo "Phase 4A operational discrepancy report\n";
echo 'Legs scanned: ' . count($result['rows']) . PHP_EOL;
echo 'Legs with discrepancies: ' . count($discrepancies) . PHP_EOL;
echo str_repeat('=', 72) . PHP_EOL;

foreach ($discrepancies as $item) {
    echo 'Aircraft: ' . $item['aircraft'] . '  Mission: ' . $item['mission'] . PHP_EOL;
    echo 'Dispatch: ' . $item['dispatch_uuid'] . PHP_EOL;
    echo 'Flight:   ' . $item['flight_record_uuid'] . PHP_EOL;
    echo 'Reservation/Leg: ' . ($item['reservation_uuid'] ?: '—') . ' / ' . ($item['leg_uuid'] ?: '—') . PHP_EOL;
    echo 'Sync: ' . $item['sync_status'] . PHP_EOL;
    foreach ($item['issues'] as $issue) {
        echo '  - ' . $issue['field'] . PHP_EOL;
        echo '    Expected: ' . $issue['expected'] . PHP_EOL;
        echo '    Actual:   ' . $issue['actual'] . PHP_EOL;
        echo '    Source:   ' . $issue['source'] . PHP_EOL;
        echo '    Cause:    ' . $issue['root_cause'] . PHP_EOL;
        echo '    Fix:      ' . $issue['proposed_correction'] . PHP_EOL;
    }
    echo str_repeat('-', 72) . PHP_EOL;
}

if ($discrepancies === array()) {
    echo "No discrepancies found for scanned CVR operational legs.\n";
    exit(0);
}

exit(2);
