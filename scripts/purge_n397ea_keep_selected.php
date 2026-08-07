<?php
declare(strict_types=1);

/**
 * Hard-purge N397EA test legs, keeping only the explicitly retained operational flights.
 *
 * KEEP (from ops list):
 *   53 Aug6 Kilian KBLH→KTRM
 *   42 Aug6 Kilian KTRM→KBLH
 *   40 Aug6 Celine KTRM→KTRM
 *   26 Aug4 Thibault KTRM→KTRM
 *   17 Aug1 Lynn KTRM→KSNA
 *   19 Aug1 Lynn KSNA→KTRM (return)
 *   16 Aug1 Lynn KTRM→KTRM
 *   7  Jul29 Otto
 *   6  Jul29 Celine
 *   3  Jul29 Imran 1-5-1
 *   2  Jul29 Imran 1-4-9
 *   1  Jul28 Imran incomplete
 *
 * Usage:
 *   php scripts/purge_n397ea_keep_selected.php --dry-run
 *   php scripts/purge_n397ea_keep_selected.php --execute
 */

require __DIR__ . '/../src/bootstrap.php';

$mode = $argv[1] ?? '--dry-run';
$execute = ($mode === '--execute');

$keepIds = array(53, 42, 40, 26, 19, 17, 16, 7, 6, 3, 2, 1);
$expectedTail = 'N397EA';

$all = $pdo->query(
    "SELECT id, aircraft_registration, workflow_flight_record_uuid, mission_code, scheduled_date
     FROM ipca_cvr_dispatches
     WHERE UPPER(aircraft_registration) = 'N397EA'
     ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

$keepMap = array_fill_keys($keepIds, true);
$erase = array();
foreach ($all as $row) {
    $id = (int)$row['id'];
    if (isset($keepMap[$id])) {
        continue;
    }
    if (strtoupper(trim((string)$row['aircraft_registration'])) !== $expectedTail) {
        fwrite(STDERR, "Refusing unexpected tail on dispatch #{$id}\n");
        exit(2);
    }
    $erase[] = $row;
}

if ($erase === array()) {
    echo "Nothing to erase. Keep set already matches all N397EA dispatches.\n";
    exit(0);
}

$ids = array_map(static fn(array $r): int => (int)$r['id'], $erase);
$ph = implode(',', array_fill(0, count($ids), '?'));

$uuids = array();
foreach ($erase as $r) {
    $u = strtolower(trim((string)$r['workflow_flight_record_uuid']));
    if ($u !== '') {
        $uuids[] = $u;
    }
}
$uuids = array_values(array_unique($uuids));
$uph = implode(',', array_fill(0, count($uuids), '?'));

$rec = $pdo->prepare('SELECT id, storage_path FROM ipca_cockpit_recordings WHERE LOWER(flight_session_uid) IN (' . $uph . ')');
$rec->execute($uuids);
$recRows = $rec->fetchAll(PDO::FETCH_ASSOC) ?: array();
$recIds = array_map(static fn(array $row): int => (int)$row['id'], $recRows);

$csv = $pdo->prepare('SELECT id, storage_path FROM ipca_garmin_csv_files WHERE LOWER(workflow_flight_record_uuid) IN (' . $uph . ')');
$csv->execute($uuids);
$csvRows = $csv->fetchAll(PDO::FETCH_ASSOC) ?: array();
$csvIds = array_map(static fn(array $row): int => (int)$row['id'], $csvRows);

$storagePaths = array();
foreach (array_merge($recRows, $csvRows) as $row) {
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
echo ($execute ? 'EXECUTE' : 'DRY-RUN') . " N397EA purge (keep selected)\n";
echo 'keep_ids=' . implode(',', $keepIds) . "\n";
echo "erase_count=" . count($ids) . "\n";
foreach ($erase as $r) {
    echo "ERASE id={$r['id']} date={$r['scheduled_date']} mission={$r['mission_code']} wfr={$r['workflow_flight_record_uuid']}\n";
}
echo 'recording_ids=' . implode(',', $recIds) . "\n";
echo 'csv_ids=' . implode(',', $csvIds) . "\n";
echo 'bundle_ids=' . implode(',', $bundleIds) . "\n";
echo 'debrief_ids=' . implode(',', $debriefIds) . "\n";

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

if ($execute) {
    foreach ($storagePaths as $path) {
        $candidates = array($path);
        if ($path !== '' && $path[0] !== '/') {
            $candidates[] = '/var/www/ipca/' . ltrim($path, '/');
            $candidates[] = '/var/www/ipca/storage/' . ltrim($path, '/');
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && @unlink($candidate)) {
                echo "removed_file={$candidate}\n";
                break;
            }
        }
    }
}

$leftKeep = $pdo->query(
    "SELECT id, scheduled_date, mission_code FROM ipca_cvr_dispatches WHERE UPPER(aircraft_registration)='N397EA' ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);
echo 'remaining_n397ea=' . count($leftKeep) . "\n";
foreach ($leftKeep as $r) {
    echo "KEEP id={$r['id']} date={$r['scheduled_date']} mission={$r['mission_code']}\n";
}
echo ($execute ? "DONE\n" : "DRY-RUN COMPLETE\n");
