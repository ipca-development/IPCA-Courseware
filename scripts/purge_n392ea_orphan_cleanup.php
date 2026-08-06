<?php
declare(strict_types=1);

/**
 * Finish orphan cleanup after N392EA dispatch hard-delete.
 * Safe: only touches known IDs/UUIDs from the already-deleted test legs.
 */

require __DIR__ . '/../src/bootstrap.php';

$uuids = array(
    '9bd28f38-b9a9-4e95-a7f2-6e2b9ae371a6',
    '1a83fafc-6417-471b-94de-5eb4e30d9a46',
    '49cc5592-14af-4826-b661-3a5b26d05957',
);
$recIds = array(560, 553, 555);
$csvIds = array(485, 486, 483);
$bundleIds = array(4);
$debriefIds = array(8);
$dispatchIds = array(5, 8, 18);

/**
 * @param list<int|string> $values
 */
function delete_in(PDO $pdo, string $table, string $column, array $values, array &$report): void
{
    if ($values === array()) {
        return;
    }
    $ph = implode(',', array_fill(0, count($values), '?'));
    try {
        $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `{$column}` IN ({$ph})");
        $stmt->execute(array_values($values));
        $n = $stmt->rowCount();
        if ($n > 0) {
            $report[] = "deleted {$table}.{$column}={$n}";
        }
    } catch (Throwable $e) {
        $report[] = "skip {$table}.{$column}: " . $e->getMessage();
    }
}

$report = array();
$pdo->beginTransaction();
try {
    $shareIds = array();
    $dph = implode(',', array_fill(0, count($debriefIds), '?'));
    try {
        $shareStmt = $pdo->prepare('SELECT id FROM ipca_replay_debrief_shares WHERE debrief_id IN (' . $dph . ')');
        $shareStmt->execute($debriefIds);
        $shareIds = array_map('intval', $shareStmt->fetchAll(PDO::FETCH_COLUMN) ?: array());
    } catch (Throwable) {
        $shareIds = array();
    }

    delete_in($pdo, 'ipca_replay_debrief_share_access', 'share_id', $shareIds, $report);
    delete_in($pdo, 'ipca_replay_debrief_shares', 'debrief_id', $debriefIds, $report);
    delete_in($pdo, 'ipca_structured_debrief_evaluations', 'debrief_id', $debriefIds, $report);
    delete_in($pdo, 'ipca_structured_debrief_audit', 'debrief_id', $debriefIds, $report);
    delete_in($pdo, 'ipca_structured_debriefs', 'id', $debriefIds, $report);

    delete_in($pdo, 'ipca_manual_intake_bundle_audit', 'bundle_id', $bundleIds, $report);
    delete_in($pdo, 'ipca_manual_intake_bundles', 'id', $bundleIds, $report);

    delete_in($pdo, 'ipca_cockpit_transcript_snapshots', 'cockpit_recording_id', $recIds, $report);
    delete_in($pdo, 'ipca_cockpit_recording_transcription_chunks', 'recording_id', $recIds, $report);
    delete_in($pdo, 'ipca_cockpit_recordings', 'id', $recIds, $report);

    delete_in($pdo, 'ipca_garmin_csv_replay_payloads', 'garmin_csv_file_id', $csvIds, $report);
    delete_in($pdo, 'ipca_garmin_csv_flight_summaries', 'csv_file_id', $csvIds, $report);
    delete_in($pdo, 'ipca_garmin_csv_validation_results', 'csv_file_id', $csvIds, $report);
    delete_in($pdo, 'ipca_garmin_csv_files', 'id', $csvIds, $report);

    delete_in($pdo, 'ipca_cvr_recorder_verifications', 'workflow_flight_record_uuid', $uuids, $report);
    delete_in($pdo, 'ipca_cvr_workflow_evidence_batches', 'workflow_flight_record_uuid', $uuids, $report);
    delete_in($pdo, 'ipca_cvr_flight_events', 'workflow_flight_record_uuid', $uuids, $report);
    delete_in($pdo, 'ipca_cvr_flight_closures', 'workflow_flight_record_uuid', $uuids, $report);
    delete_in($pdo, 'ipca_cvr_logbook_hidden_legs', 'dispatch_id', $dispatchIds, $report);
    delete_in($pdo, 'ipca_cvr_dispatch_consents', 'dispatch_id', $dispatchIds, $report);
    delete_in($pdo, 'ipca_cvr_dispatch_versions', 'dispatch_id', $dispatchIds, $report);
    delete_in($pdo, 'ipca_cvr_dispatches', 'id', $dispatchIds, $report);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'ORPHAN CLEANUP FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($report as $line) {
    echo $line . "\n";
}

$leftDispatch = (int)$pdo->query(
    "SELECT COUNT(*) FROM ipca_cvr_dispatches WHERE UPPER(TRIM(aircraft_registration)) = 'N392EA'"
)->fetchColumn();
$uph = implode(',', array_fill(0, count($uuids), '?'));
$ev = $pdo->prepare('SELECT COUNT(*) FROM ipca_cvr_workflow_evidence_batches WHERE LOWER(workflow_flight_record_uuid) IN (' . $uph . ')');
$ev->execute($uuids);
$leftBatches = (int)$ev->fetchColumn();
$b = $pdo->prepare('SELECT COUNT(*) FROM ipca_manual_intake_bundles WHERE id IN (4)');
$b->execute();
$leftBundles = (int)$b->fetchColumn();

echo "remaining_n392ea_dispatches={$leftDispatch}\n";
echo "remaining_evidence_batches={$leftBatches}\n";
echo "remaining_bundle_4={$leftBundles}\n";
echo "ORPHAN CLEANUP DONE\n";
