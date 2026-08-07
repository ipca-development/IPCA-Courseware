<?php
declare(strict_types=1);

/**
 * Hard-purge one early N428EA test leg (Otto Stoop / Judson Graham, 2026-07-29).
 * Usage:
 *   php scripts/purge_n428ea_2026_07_29_test_leg.php --dry-run
 *   php scripts/purge_n428ea_2026_07_29_test_leg.php --execute
 */

require __DIR__ . '/../src/bootstrap.php';

$mode = $argv[1] ?? '--dry-run';
$execute = ($mode === '--execute');

$ids = array(9);
$expectedTail = 'N428EA';
$expectedWfr = 'b2e1f4b3-f511-42b3-bf8d-9f730a89fc01';
$ph = implode(',', array_fill(0, count($ids), '?'));

$st = $pdo->prepare(
    'SELECT id, aircraft_registration, workflow_flight_record_uuid, dispatch_uuid, mission_code, scheduled_date, crew_json
     FROM ipca_cvr_dispatches WHERE id IN (' . $ph . ')'
);
$st->execute($ids);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) !== count($ids)) {
    fwrite(STDERR, 'Expected ' . count($ids) . ' dispatches, found ' . count($rows) . "\n");
    exit(2);
}
foreach ($rows as $r) {
    if (strtoupper(trim((string)$r['aircraft_registration'])) !== $expectedTail) {
        fwrite(STDERR, "Refusing: dispatch #{$r['id']} is not {$expectedTail}\n");
        exit(2);
    }
    if (strtolower(trim((string)$r['workflow_flight_record_uuid'])) !== $expectedWfr) {
        fwrite(STDERR, "Refusing: dispatch #{$r['id']} workflow UUID mismatch\n");
        exit(2);
    }
}

$uuids = array($expectedWfr);
$uph = implode(',', array_fill(0, count($uuids), '?'));

$rec = $pdo->prepare(
    'SELECT id, recording_uid, storage_path FROM ipca_cockpit_recordings WHERE LOWER(flight_session_uid) IN (' . $uph . ')'
);
$rec->execute($uuids);
$recRows = $rec->fetchAll(PDO::FETCH_ASSOC) ?: array();
$recIds = array_map(static fn(array $row): int => (int)$row['id'], $recRows);
$storagePaths = array();
foreach ($recRows as $row) {
    $path = trim((string)($row['storage_path'] ?? ''));
    if ($path !== '') {
        $storagePaths[] = $path;
    }
}

$csv = $pdo->prepare(
    'SELECT id, storage_path FROM ipca_garmin_csv_files WHERE LOWER(workflow_flight_record_uuid) IN (' . $uph . ')'
);
$csv->execute($uuids);
$csvRows = $csv->fetchAll(PDO::FETCH_ASSOC) ?: array();
$csvIds = array_map(static fn(array $row): int => (int)$row['id'], $csvRows);
foreach ($csvRows as $row) {
    $path = trim((string)($row['storage_path'] ?? ''));
    if ($path !== '') {
        $storagePaths[] = $path;
    }
}

$bundleIds = array();
try {
    $bundle = $pdo->prepare('SELECT id FROM ipca_manual_intake_bundles WHERE dispatch_id IN (' . $ph . ')');
    $bundle->execute($ids);
    $bundleIds = array_map('intval', $bundle->fetchAll(PDO::FETCH_COLUMN) ?: array());
} catch (Throwable) {
    $bundleIds = array();
}

$debriefIds = array();
if ($bundleIds !== array()) {
    $bph = implode(',', array_fill(0, count($bundleIds), '?'));
    try {
        $d = $pdo->prepare('SELECT id FROM ipca_structured_debriefs WHERE bundle_id IN (' . $bph . ')');
        $d->execute($bundleIds);
        $debriefIds = array_map('intval', $d->fetchAll(PDO::FETCH_COLUMN) ?: array());
    } catch (Throwable) {
        $debriefIds = array();
    }
}

/**
 * @param list<int|string> $values
 */
function delete_in(PDO $pdo, string $table, string $column, array $values, bool $execute, array &$report): void
{
    if ($values === array()) {
        return;
    }
    $ph = implode(',', array_fill(0, count($values), '?'));
    try {
        if ($execute) {
            $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `{$column}` IN ({$ph})");
            $stmt->execute(array_values($values));
            $n = $stmt->rowCount();
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` IN ({$ph})");
            $stmt->execute(array_values($values));
            $n = (int)$stmt->fetchColumn();
        }
        if ($n > 0) {
            $report[] = ($execute ? 'deleted' : 'would_delete') . " {$table}.{$column}={$n}";
        }
    } catch (Throwable $e) {
        $report[] = "skip {$table}.{$column}: " . $e->getMessage();
    }
}

$report = array();
echo ($execute ? 'EXECUTE' : 'DRY-RUN') . " N428EA 2026-07-29 Otto Stoop / Judson Graham purge\n";
echo 'dispatch_ids=' . implode(',', $ids) . "\n";
echo 'flight_uuids=' . implode(',', $uuids) . "\n";
echo 'recording_ids=' . implode(',', $recIds) . "\n";
echo 'csv_ids=' . implode(',', $csvIds) . "\n";
echo 'bundle_ids=' . implode(',', $bundleIds) . "\n";
echo 'debrief_ids=' . implode(',', $debriefIds) . "\n";
echo 'storage_paths=' . implode(' | ', $storagePaths) . "\n";

if ($execute) {
    $pdo->beginTransaction();
}

try {
    $shareIds = array();
    if ($debriefIds !== array()) {
        $dph = implode(',', array_fill(0, count($debriefIds), '?'));
        try {
            $shareStmt = $pdo->prepare('SELECT id FROM ipca_replay_debrief_shares WHERE debrief_id IN (' . $dph . ')');
            $shareStmt->execute($debriefIds);
            $shareIds = array_map('intval', $shareStmt->fetchAll(PDO::FETCH_COLUMN) ?: array());
        } catch (Throwable) {
            $shareIds = array();
        }
    }
    delete_in($pdo, 'ipca_replay_debrief_share_access', 'share_id', $shareIds, $execute, $report);
    delete_in($pdo, 'ipca_replay_debrief_shares', 'debrief_id', $debriefIds, $execute, $report);
    delete_in($pdo, 'ipca_structured_debrief_evaluations', 'debrief_id', $debriefIds, $execute, $report);
    delete_in($pdo, 'ipca_structured_debrief_audit', 'debrief_id', $debriefIds, $execute, $report);
    delete_in($pdo, 'ipca_structured_debriefs', 'id', $debriefIds, $execute, $report);

    delete_in($pdo, 'ipca_manual_intake_bundle_items', 'bundle_id', $bundleIds, $execute, $report);
    delete_in($pdo, 'ipca_manual_intake_bundle_audit', 'bundle_id', $bundleIds, $execute, $report);
    delete_in($pdo, 'ipca_manual_intake_bundles', 'id', $bundleIds, $execute, $report);

    delete_in($pdo, 'ipca_cockpit_recording_transcription_chunks', 'recording_id', $recIds, $execute, $report);
    delete_in($pdo, 'ipca_cockpit_transcript_snapshots', 'cockpit_recording_id', $recIds, $execute, $report);
    delete_in($pdo, 'ipca_cockpit_reconstruction_jobs', 'recording_id', $recIds, $execute, $report);
    delete_in($pdo, 'ipca_cockpit_replay_samples', 'recording_id', $recIds, $execute, $report);
    delete_in($pdo, 'ipca_cockpit_flight_samples', 'recording_id', $recIds, $execute, $report);
    delete_in($pdo, 'ipca_cockpit_flight_phases', 'recording_id', $recIds, $execute, $report);
    delete_in($pdo, 'ipca_cockpit_timeline_events', 'recording_id', $recIds, $execute, $report);
    delete_in($pdo, 'ipca_cockpit_adsb_enrichments', 'recording_id', $recIds, $execute, $report);
    delete_in($pdo, 'ipca_cockpit_adsb_ownship_samples', 'recording_id', $recIds, $execute, $report);
    delete_in($pdo, 'ipca_cockpit_adsb_traffic_samples', 'recording_id', $recIds, $execute, $report);
    delete_in($pdo, 'ipca_cockpit_adsb_traffic_aircraft_samples', 'recording_id', $recIds, $execute, $report);
    delete_in($pdo, 'ipca_cockpit_adsb_trace_requests', 'recording_id', $recIds, $execute, $report);
    delete_in($pdo, 'ipca_cockpit_adsb_discovery_requests', 'recording_id', $recIds, $execute, $report);
    delete_in($pdo, 'ipca_cockpit_adsb_candidate_observations', 'recording_id', $recIds, $execute, $report);
    delete_in($pdo, 'ipca_cockpit_recordings', 'id', $recIds, $execute, $report);

    delete_in($pdo, 'ipca_garmin_csv_flight_summaries', 'csv_file_id', $csvIds, $execute, $report);
    delete_in($pdo, 'ipca_garmin_csv_validation_results', 'csv_file_id', $csvIds, $execute, $report);
    delete_in($pdo, 'ipca_garmin_csv_replay_payloads', 'garmin_csv_file_id', $csvIds, $execute, $report);
    delete_in($pdo, 'ipca_garmin_csv_session_matches', 'csv_file_id', $csvIds, $execute, $report);
    delete_in($pdo, 'ipca_garmin_csv_fingerprints', 'csv_file_id', $csvIds, $execute, $report);
    delete_in($pdo, 'ipca_garmin_csv_files', 'id', $csvIds, $execute, $report);

    delete_in($pdo, 'ipca_cvr_flight_events', 'workflow_flight_record_uuid', $uuids, $execute, $report);
    delete_in($pdo, 'ipca_cvr_flight_closures', 'workflow_flight_record_uuid', $uuids, $execute, $report);
    delete_in($pdo, 'ipca_cvr_flight_log_adjustments', 'workflow_flight_record_uuid', $uuids, $execute, $report);
    delete_in($pdo, 'ipca_cvr_recorder_verifications', 'workflow_flight_record_uuid', $uuids, $execute, $report);
    delete_in($pdo, 'ipca_cvr_workflow_evidence_batches', 'workflow_flight_record_uuid', $uuids, $execute, $report);

    delete_in($pdo, 'ipca_cvr_financial_dispatches', 'dispatch_id', $ids, $execute, $report);
    delete_in($pdo, 'ipca_cvr_logbook_hidden_legs', 'dispatch_id', $ids, $execute, $report);
    delete_in($pdo, 'ipca_cvr_dispatch_consents', 'dispatch_id', $ids, $execute, $report);
    delete_in($pdo, 'ipca_cvr_dispatch_versions', 'dispatch_id', $ids, $execute, $report);
    delete_in($pdo, 'ipca_cvr_dispatches', 'id', $ids, $execute, $report);

    if ($execute) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($execute && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'PURGE FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($report as $line) {
    echo $line . "\n";
}

if ($execute && $storagePaths !== array()) {
    foreach ($storagePaths as $path) {
        $candidates = array($path);
        if ($path[0] !== '/') {
            $candidates[] = '/var/www/ipca/' . ltrim($path, '/');
            $candidates[] = '/var/www/ipca/storage/' . ltrim($path, '/');
        }
        $removed = false;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                if (@unlink($candidate)) {
                    echo "removed_file={$candidate}\n";
                    $removed = true;
                    break;
                }
                echo "file_unlink_failed={$candidate}\n";
            }
        }
        if (!$removed) {
            echo "file_not_found={$path}\n";
        }
    }
}

$left = $pdo->prepare('SELECT COUNT(*) FROM ipca_cvr_dispatches WHERE id IN (' . $ph . ')');
$left->execute($ids);
echo 'remaining_dispatches=' . (int)$left->fetchColumn() . "\n";
echo ($execute ? "DONE\n" : "DRY-RUN COMPLETE\n");
