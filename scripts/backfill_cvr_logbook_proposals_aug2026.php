<?php
declare(strict_types=1);

/**
 * One-off backfill: create Master Logbook → individual logbook proposals
 * for named pilots between 2026-08-05 and today (Pacific).
 *
 * Usage (on server):
 *   php scripts/backfill_cvr_logbook_proposals_aug2026.php [--dry-run]
 */

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/MasterLogbookLogbookProposalService.php';

$dryRun = in_array('--dry-run', $argv ?? array(), true);
$pdo = cw_db();
$service = new MasterLogbookLogbookProposalService($pdo);

if (!$service->schemaAvailable()) {
    fwrite(STDERR, "ipca_cvr_logbook_proposals is missing. Apply the migration first.\n");
    exit(1);
}

$ownerIds = array(
    40 => 'Thibault Picard',
    32 => 'Viktor Kumps',
    33 => 'Otto Stoop',
    37 => 'Kilian Creupelandt',
);

$fromLocal = '2026-08-05';
$toLocal = (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
$fromUtc = (new DateTimeImmutable($fromLocal . ' 00:00:00', new DateTimeZone('America/Los_Angeles')))
    ->setTimezone(new DateTimeZone('UTC'))
    ->format('Y-m-d H:i:s');
$toUtcExclusive = (new DateTimeImmutable($toLocal . ' 00:00:00', new DateTimeZone('America/Los_Angeles')))
    ->modify('+1 day')
    ->setTimezone(new DateTimeZone('UTC'))
    ->format('Y-m-d H:i:s');

echo "Backfill window (Pacific dates): {$fromLocal} → {$toLocal}\n";
echo "UTC filter: {$fromUtc} ≤ closure < {$toUtcExclusive}\n";
echo $dryRun ? "DRY RUN\n" : "LIVE WRITE\n";

$stmt = $pdo->prepare(
    'SELECT d.id AS dispatch_id,
            d.aircraft_registration,
            d.mission_code,
            d.workflow_flight_record_uuid,
            d.crew_json,
            d.scheduled_date,
            d.starting_hobbs,
            c.ending_hobbs,
            c.received_at
     FROM ipca_cvr_dispatches d
     INNER JOIN ipca_cvr_flight_closures c ON c.id = (
       SELECT fc.id FROM ipca_cvr_flight_closures fc
       WHERE LOWER(fc.workflow_flight_record_uuid) = LOWER(d.workflow_flight_record_uuid)
       ORDER BY fc.received_at DESC, fc.id DESC
       LIMIT 1
     )
     WHERE c.received_at >= ?
       AND c.received_at < ?
     ORDER BY c.received_at ASC, d.id ASC'
);
$stmt->execute(array($fromUtc, $toUtcExclusive));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();

$hidden = array();
if ($pdo->query("SHOW TABLES LIKE 'ipca_cvr_logbook_hidden_legs'")->fetchColumn()) {
    $hiddenStmt = $pdo->query('SELECT dispatch_id FROM ipca_cvr_logbook_hidden_legs');
    foreach ($hiddenStmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $hidden[(int)$id] = true;
    }
}

$candidateFlights = array();
foreach ($rows as $row) {
    $dispatchId = (int)($row['dispatch_id'] ?? 0);
    if ($dispatchId <= 0 || isset($hidden[$dispatchId])) {
        continue;
    }
    $crew = json_decode((string)($row['crew_json'] ?? '[]'), true);
    if (!is_array($crew)) {
        continue;
    }
    $matched = array();
    foreach ($crew as $member) {
        if (!is_array($member)) {
            continue;
        }
        $personId = (int)($member['person_id'] ?? $member['personId'] ?? $member['user_id'] ?? 0);
        if (isset($ownerIds[$personId])) {
            $matched[$personId] = $ownerIds[$personId];
        }
    }
    if ($matched === array()) {
        continue;
    }
    $candidateFlights[] = array(
        'dispatch_id' => $dispatchId,
        'flight_uuid' => (string)$row['workflow_flight_record_uuid'],
        'aircraft' => (string)$row['aircraft_registration'],
        'mission' => (string)$row['mission_code'],
        'scheduled_date' => (string)$row['scheduled_date'],
        'received_at' => (string)$row['received_at'],
        'matched' => $matched,
    );
}

echo 'Candidate closed flights with target crew: ' . count($candidateFlights) . "\n";

$createdTotal = 0;
$byOwner = array_fill_keys(array_keys($ownerIds), 0);

foreach ($candidateFlights as $flight) {
    echo sprintf(
        "- dispatch #%d %s %s mission=%s matched=%s\n",
        $flight['dispatch_id'],
        $flight['aircraft'],
        $flight['scheduled_date'],
        $flight['mission'],
        implode(', ', $flight['matched'])
    );
    if ($dryRun) {
        continue;
    }
    $proposals = $service->createProposalsForFlightRecord($flight['flight_uuid']);
    foreach ($proposals as $proposal) {
        $ownerId = (int)($proposal['owner_user_id'] ?? 0);
        if (!isset($ownerIds[$ownerId])) {
            // Service creates for all crew with person_id; count only requested owners.
            continue;
        }
        $createdTotal++;
        $byOwner[$ownerId]++;
        echo sprintf(
            "    proposal #%d owner=%s role=%s status=%s duration_ms=%s\n",
            (int)($proposal['id'] ?? 0),
            $ownerIds[$ownerId],
            (string)($proposal['owner_role'] ?? ''),
            (string)($proposal['status'] ?? ''),
            (string)($proposal['proposed_duration_ms'] ?? '')
        );
    }
}

echo "\nSummary:\n";
foreach ($ownerIds as $id => $name) {
    echo "  {$name} (#{$id}): {$byOwner[$id]} proposal(s)\n";
}
echo 'Total proposals for requested pilots: ' . $createdTotal . "\n";
echo $dryRun ? "Dry run complete — re-run without --dry-run to write.\n" : "Backfill complete.\n";
