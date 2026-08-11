<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/AuditEventService.php';
require_once __DIR__ . '/../src/CvrOperationalSessionLegReviewService.php';
require_once __DIR__ . '/../src/FlightSessionService.php';

$apply = in_array('--apply', $argv, true);
$acceptDerived = in_array('--accept-derived', $argv, true);
$flightUuids = array_values(array_filter(
    array_slice($argv, 1),
    static fn(string $value): bool => !in_array($value, array('--apply', '--accept-derived'), true)
));
if ($flightUuids === array()) {
    fwrite(STDERR, "Usage: php scripts/repair_cvr_operational_leg_projection.php [--apply] [--accept-derived] <workflow-flight-record-uuid> [...]\n");
    exit(2);
}

$pdo = cw_db();
$reviewService = new CvrOperationalSessionLegReviewService($pdo);

foreach ($flightUuids as $rawFlightUuid) {
    $flightUuid = strtolower(trim($rawFlightUuid));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $flightUuid)) {
        fwrite(STDERR, "Invalid Flight Record UUID: {$rawFlightUuid}\n");
        exit(2);
    }

    $dispatchStatement = $pdo->prepare(
        'SELECT * FROM ipca_cvr_dispatches
         WHERE LOWER(workflow_flight_record_uuid) = ?
         ORDER BY id DESC LIMIT 1'
    );
    $dispatchStatement->execute(array($flightUuid));
    $dispatch = $dispatchStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($dispatch)) {
        fwrite(STDERR, "{$flightUuid}: Dispatch not found.\n");
        continue;
    }

    $operationalSessionUuid = strtolower(trim((string)($dispatch['operational_session_uuid'] ?? '')));
    $sessionStatement = $pdo->prepare(
        'SELECT * FROM ipca_flight_sessions
         WHERE session_uuid = ? AND model_version = ?
         LIMIT 1'
    );
    $sessionStatement->execute(array($operationalSessionUuid, FlightSessionService::MODEL_OPERATIONAL_V1));
    $canonicalSession = $sessionStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($canonicalSession)) {
        fwrite(STDERR, "{$flightUuid}: canonical Operational Session not found.\n");
        continue;
    }

    $csvStatement = $pdo->prepare(
        'SELECT * FROM ipca_garmin_csv_files
         WHERE LOWER(workflow_flight_record_uuid) = ?
         ORDER BY id DESC LIMIT 1'
    );
    $csvStatement->execute(array($flightUuid));
    $csv = $csvStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($csv)) {
        fwrite(STDERR, "{$flightUuid}: linked Garmin CSV not found.\n");
        continue;
    }

    $canonicalSessionId = (int)$canonicalSession['id'];
    $legacySessionId = (int)($csv['session_id'] ?? 0);
    printf(
        "%s: Dispatch %d, Garmin CSV %d, current session %d, canonical session %d%s\n",
        $flightUuid,
        (int)$dispatch['id'],
        (int)$csv['id'],
        $legacySessionId,
        $canonicalSessionId,
        $apply ? '' : ' [dry run]'
    );
    if (!$apply) {
        continue;
    }

    $pdo->beginTransaction();
    try {
        $canonicalRecordStatement = $pdo->prepare(
            'SELECT * FROM ipca_operational_flight_records WHERE session_id = ? ORDER BY id LIMIT 1 FOR UPDATE'
        );
        $canonicalRecordStatement->execute(array($canonicalSessionId));
        $canonicalRecord = $canonicalRecordStatement->fetch(PDO::FETCH_ASSOC);

        $legacyRecord = null;
        if ($legacySessionId > 0 && $legacySessionId !== $canonicalSessionId) {
            $legacyRecordStatement = $pdo->prepare(
                'SELECT * FROM ipca_operational_flight_records WHERE session_id = ? ORDER BY id LIMIT 1 FOR UPDATE'
            );
            $legacyRecordStatement->execute(array($legacySessionId));
            $legacyRecord = $legacyRecordStatement->fetch(PDO::FETCH_ASSOC);
        }
        if (is_array($legacyRecord) && !is_array($canonicalRecord)) {
            $pdo->prepare(
                'UPDATE ipca_operational_flight_records SET session_id = ?, updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?'
            )->execute(array($canonicalSessionId, (int)$legacyRecord['id']));
            $pdo->prepare(
                'UPDATE ipca_flight_sessions SET current_flight_record_id = ? WHERE id = ?'
            )->execute(array((int)$legacyRecord['id'], $canonicalSessionId));
        } elseif (is_array($legacyRecord) && is_array($canonicalRecord)
            && (int)$legacyRecord['id'] !== (int)$canonicalRecord['id']) {
            throw new RuntimeException('Both legacy and canonical sessions already contain Flight Records; manual merge is required.');
        }

        $pdo->prepare(
            'UPDATE ipca_garmin_csv_files SET session_id = ?, active_for_session = 1 WHERE id = ?'
        )->execute(array($canonicalSessionId, (int)$csv['id']));
        $pdo->prepare(
            'UPDATE ipca_garmin_csv_session_matches SET session_id = ?
             WHERE csv_file_id = ?'
        )->execute(array($canonicalSessionId, (int)$csv['id']));
        $pdo->prepare(
            'UPDATE ipca_cockpit_recordings SET operational_session_uuid = ?
             WHERE LOWER(flight_session_uid) = ?'
        )->execute(array($operationalSessionUuid, $flightUuid));

        if ($legacySessionId > 0 && $legacySessionId !== $canonicalSessionId) {
            $pdo->prepare(
                "UPDATE ipca_flight_sessions
                 SET current_flight_record_id = NULL, status = 'cancelled'
                 WHERE id = ?"
            )->execute(array($legacySessionId));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "{$flightUuid}: identity repair failed: {$e->getMessage()}\n");
        continue;
    }

    try {
        $deviceStatement = $pdo->prepare('SELECT * FROM ipca_cvr_devices WHERE id = ? LIMIT 1');
        $deviceStatement->execute(array((int)$dispatch['device_id']));
        $device = $deviceStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($device)) {
            throw new RuntimeException('CVR device not found.');
        }
        $device['aircraft_registration'] = (string)($dispatch['aircraft_registration'] ?? '');
        $preview = $reviewService->previewForDevice($device, (string)$dispatch['dispatch_uuid']);
        $review = is_array($preview['review'] ?? null) ? $preview['review'] : array();
        $legs = is_array($review['proposed_legs'] ?? null) ? $review['proposed_legs'] : array();
        if ($legs === array()) {
            throw new RuntimeException('No derived legs are available for repair.');
        }
        $acceptedRevisionUuid = strtolower(trim((string)($review['accepted_revision_uuid'] ?? '')));
        if ($acceptedRevisionUuid === '' && !$acceptDerived) {
            printf(
                "%s: identity repaired; no accepted review exists, so derived legs were not auto-accepted.\n",
                $flightUuid
            );
            continue;
        }
        $accepted = $reviewService->acceptForDevice($device, array(
            'revision_uuid' => $acceptedRevisionUuid !== ''
                ? $acceptedRevisionUuid
                : AuditEventService::uuid(),
            'dispatch_uuid' => (string)$dispatch['dispatch_uuid'],
            'evidence_sha256' => (string)($review['evidence_sha256'] ?? ''),
            'legs' => $legs,
        ));

        $currentVersionStatement = $pdo->prepare(
            'SELECT r.current_version_id
             FROM ipca_operational_flight_records r
             WHERE r.session_id = ? ORDER BY r.id LIMIT 1'
        );
        $currentVersionStatement->execute(array($canonicalSessionId));
        $currentVersionId = (int)$currentVersionStatement->fetchColumn();
        if ($currentVersionId > 0) {
            $pdo->prepare(
                'UPDATE ipca_manual_intake_bundles
                 SET operational_flight_record_version_id = ?, processing_error = NULL
                 WHERE dispatch_id = ?'
            )->execute(array($currentVersionId, (int)$dispatch['id']));
        }
        printf(
            "%s: repaired with revision %s and %d accepted legs.\n",
            $flightUuid,
            (string)($accepted['revision_uuid'] ?? ''),
            count($legs)
        );
    } catch (Throwable $e) {
        fwrite(STDERR, "{$flightUuid}: leg projection repair failed: {$e->getMessage()}\n");
    }
}
