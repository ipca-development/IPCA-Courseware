<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/CvrAdminLegSplitService.php';
require_once __DIR__ . '/MasterLogbookLogbookProposalService.php';

final class CvrOperationalSessionLegReviewService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function previewForDevice(array $device, string $dispatchUuid): array
    {
        $dispatch = $this->ownedDispatch($device, $dispatchUuid, false);
        $preview = (new CvrAdminLegSplitService($this->pdo))->preview((int)$dispatch['id']);
        $latest = $this->latestReview(
            strtolower((string)$dispatch['operational_session_uuid']),
            false
        );
        if (is_array($latest)) {
            $acceptedLegs = json_decode((string)$latest['legs_json'], true);
            if (is_array($acceptedLegs) && $acceptedLegs !== array()) {
                $preview['proposed_legs'] = $acceptedLegs;
                $firstIndex = array_key_first($preview['proposed_legs']);
                $lastIndex = array_key_last($preview['proposed_legs']);
                if ($firstIndex !== null && $lastIndex !== null) {
                    $preview['proposed_legs'][$firstIndex]['starting_hobbs'] = $preview['starting_hobbs'];
                    $preview['proposed_legs'][$firstIndex]['starting_tacho'] = $preview['starting_tacho'];
                    $preview['proposed_legs'][$firstIndex]['fuel_onboard'] = $preview['fuel_start'];
                    $preview['proposed_legs'][$lastIndex]['ending_hobbs'] = $preview['ending_hobbs'];
                    $preview['proposed_legs'][$lastIndex]['ending_tacho'] = $preview['ending_tacho'];
                    $preview['proposed_legs'][$lastIndex]['fuel_remaining'] = $preview['fuel_end'];
                    if (count($preview['proposed_legs']) === 1) {
                        $preview['proposed_legs'][$firstIndex]['takeoff_count'] = $preview['verified_takeoff_count'];
                        $preview['proposed_legs'][$firstIndex]['landing_count'] = $preview['verified_landing_count'];
                    }
                }
            }
        }
        if (is_array($preview['proposed_legs'] ?? null)) {
            $preview['proposed_legs'] = array_map(static function ($leg): mixed {
                if (!is_array($leg)) {
                    return $leg;
                }
                $leg['sequence_number'] = (int)($leg['sequence_number'] ?? $leg['leg_index'] ?? 0);
                return $leg;
            }, $preview['proposed_legs']);
        }
        $preview['operational_session_uuid'] = strtolower((string)$dispatch['operational_session_uuid']);
        $currentEvidence = $this->currentEvidence((string)$dispatch['workflow_flight_record_uuid']);
        $latestMatchesEvidence = is_array($latest)
            && hash_equals((string)$latest['evidence_sha256'], $currentEvidence['sha256']);
        $preview['evidence_sha256'] = $currentEvidence['sha256'];
        $preview['evidence_source'] = $currentEvidence['source'];
        $preview['leg_review_verified'] = $latestMatchesEvidence;
        $preview['reconciliation_required'] = is_array($latest) && !$latestMatchesEvidence;
        $preview['accepted_revision_uuid'] = is_array($latest) ? (string)$latest['revision_uuid'] : null;
        $preview['accepted_revision_number'] = is_array($latest) ? (int)$latest['revision_number'] : null;
        return array('ok' => true, 'review' => $preview);
    }

    /** @return array<string,mixed> */
    public function statusForDevice(array $device, string $dispatchUuid): array
    {
        $dispatch = $this->ownedDispatch($device, $dispatchUuid, false);
        $latest = $this->latestReview(
            strtolower((string)$dispatch['operational_session_uuid']),
            false
        );
        $evidence = $this->currentEvidence((string)$dispatch['workflow_flight_record_uuid']);
        $verified = is_array($latest)
            && hash_equals((string)$latest['evidence_sha256'], $evidence['sha256']);
        return array(
            'ok' => true,
            'dispatch_uuid' => strtolower((string)$dispatch['dispatch_uuid']),
            'verified' => $verified,
            'reconciliation_required' => is_array($latest) && !$verified,
            'evidence_source' => $evidence['source'],
            'revision_uuid' => is_array($latest) ? (string)$latest['revision_uuid'] : null,
            'revision_number' => is_array($latest) ? (int)$latest['revision_number'] : null,
        );
    }

    /** @return array<string,mixed> */
    public function acceptForDevice(array $device, array $input): array
    {
        $revisionUuid = $this->uuid((string)($input['revision_uuid'] ?? ''), 'revision_uuid');
        $dispatchUuid = $this->uuid((string)($input['dispatch_uuid'] ?? ''), 'dispatch_uuid');
        $legs = $this->normalizeLegs($input['legs'] ?? null);
        $deviceId = (int)($device['id'] ?? 0);
        if ($deviceId <= 0) {
            throw new RuntimeException('Authenticated CVR device is required.');
        }

        $this->pdo->beginTransaction();
        try {
            $dispatch = $this->ownedDispatch($device, $dispatchUuid, true);
            $sessionUuid = $this->uuid(
                (string)($dispatch['operational_session_uuid'] ?? ''),
                'operational_session_uuid'
            );
            $flightUuid = $this->uuid(
                (string)($dispatch['workflow_flight_record_uuid'] ?? ''),
                'workflow_flight_record_uuid'
            );
            $canonicalLegs = AuditEventService::jsonEncode($legs);
            $evidence = $this->currentEvidence($flightUuid);
            $evidenceHash = $evidence['sha256'];
            $evidenceSource = $evidence['source'];
            $submittedHash = strtolower(trim((string)($input['evidence_sha256'] ?? '')));
            if ($submittedHash !== '' && !hash_equals($evidenceHash, $submittedHash)) {
                throw new RuntimeException('The available leg evidence changed. Refresh the leg review before accepting.');
            }
            $legs = array_map(static function (array $leg) use ($evidenceSource): array {
                $leg['source'] = $evidenceSource;
                return $leg;
            }, $legs);
            $canonicalLegs = AuditEventService::jsonEncode($legs);

            $existing = $this->reviewByUuid($revisionUuid);
            if (is_array($existing)) {
                if (strtolower((string)$existing['operational_session_uuid']) !== $sessionUuid
                    || !hash_equals((string)$existing['evidence_sha256'], $evidenceHash)
                    || !hash_equals((string)$existing['legs_json'], $canonicalLegs)) {
                    throw new RuntimeException('Leg-review revision UUID conflict.');
                }
                $this->pdo->commit();
                return array(
                    'ok' => true,
                    'already_present' => true,
                    'revision_uuid' => $revisionUuid,
                    'revision_number' => (int)$existing['revision_number'],
                    'legs' => $legs,
                );
            }

            $closure = $this->closure($flightUuid);
            $this->assertEvidenceBounds($dispatch, $closure, $legs);
            $latest = $this->latestReview($sessionUuid, true);
            $revisionNumber = is_array($latest) ? ((int)$latest['revision_number'] + 1) : 1;
            $supersedes = is_array($latest) ? (string)$latest['revision_uuid'] : null;

            $insert = $this->pdo->prepare(
                'INSERT INTO ipca_operational_session_leg_reviews
                 (revision_uuid, operational_session_uuid, dispatch_id, workflow_flight_record_uuid,
                  revision_number, status, evidence_sha256, evidence_source, legs_json, reviewed_by_device_id,
                  supersedes_revision_uuid)
                 VALUES (?, ?, ?, ?, ?, \'ACCEPTED\', ?, ?, ?, ?, ?)'
            );
            $insert->execute(array(
                $revisionUuid,
                $sessionUuid,
                (int)$dispatch['id'],
                $flightUuid,
                $revisionNumber,
                $evidenceHash,
                $evidenceSource,
                $canonicalLegs,
                $deviceId,
                $supersedes ?: null,
            ));
            $this->persistCurrentProjection((int)$dispatch['id'], $legs, $revisionUuid, $evidenceHash);
            (new AuditEventService($this->pdo))->record(
                'cvr.operational_session.legs_reviewed',
                'ipca_operational_session_leg_reviews',
                $revisionUuid,
                $supersedes ? array('supersedes_revision_uuid' => $supersedes) : null,
                array(
                    'operational_session_uuid' => $sessionUuid,
                    'workflow_flight_record_uuid' => $flightUuid,
                    'leg_count' => count($legs),
                    'evidence_sha256' => $evidenceHash,
                    'evidence_source' => $evidenceSource,
                ),
                'CVR device accepted evidence-derived Operational Session legs.',
                'device',
                null,
                $deviceId
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        try {
            (new MasterLogbookLogbookProposalService($this->pdo))
                ->createProposalsForFlightRecord($flightUuid);
        } catch (Throwable $e) {
            error_log('[CvrOperationalSessionLegReview] proposal projection failed: ' . $e->getMessage());
        }

        return array(
            'ok' => true,
            'already_present' => false,
            'revision_uuid' => $revisionUuid,
            'revision_number' => $revisionNumber,
            'legs' => $legs,
        );
    }

    /** @return array<string,mixed> */
    private function ownedDispatch(array $device, string $dispatchUuid, bool $forUpdate): array
    {
        $uuid = $this->uuid($dispatchUuid, 'dispatch_uuid');
        $sql = 'SELECT * FROM ipca_cvr_dispatches
                WHERE LOWER(dispatch_uuid) = ? AND device_id = ?
                  AND operational_session_uuid IS NOT NULL
                LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array($uuid, (int)($device['id'] ?? 0)));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Operational Session Dispatch was not found for this CVR device.');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function normalizeLegs(mixed $input): array
    {
        if (!is_array($input) || $input === array()) {
            throw new RuntimeException('At least one evidence-derived leg is required.');
        }
        $legs = array();
        foreach (array_values($input) as $index => $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Each leg must be an object.');
            }
            $departure = strtoupper(trim((string)($row['departure_airport'] ?? '')));
            $arrival = strtoupper(trim((string)($row['arrival_airport'] ?? '')));
            if (!preg_match('/^[A-Z0-9]{3,4}$/', $departure)
                || !preg_match('/^[A-Z0-9]{3,4}$/', $arrival)) {
                throw new RuntimeException('Each leg requires valid departure and arrival airports.');
            }
            $offBlock = $this->timestamp((string)($row['off_block_utc'] ?? ''), 'off_block_utc');
            $onBlock = $this->timestamp((string)($row['on_block_utc'] ?? ''), 'on_block_utc');
            if (strtotime($onBlock) < strtotime($offBlock)) {
                throw new RuntimeException('Leg on-block time cannot precede off-block time.');
            }
            $legs[] = array(
                'sequence_number' => $index + 1,
                'departure_airport' => $departure,
                'arrival_airport' => $arrival,
                'off_block_utc' => $offBlock,
                'on_block_utc' => $onBlock,
                'starting_hobbs' => round((float)($row['starting_hobbs'] ?? 0), 1),
                'ending_hobbs' => round((float)($row['ending_hobbs'] ?? 0), 1),
                'starting_tacho' => round((float)($row['starting_tacho'] ?? 0), 1),
                'ending_tacho' => round((float)($row['ending_tacho'] ?? 0), 1),
                'takeoff_count' => max(0, (int)($row['takeoff_count'] ?? 0)),
                'landing_count' => max(0, (int)($row['landing_count'] ?? 0)),
                'fuel_onboard' => $this->nullableFloat($row['fuel_onboard'] ?? null),
                'fuel_remaining' => $this->nullableFloat($row['fuel_remaining'] ?? null),
                'source' => trim((string)($row['source'] ?? 'device_reviewed')),
            );
        }
        return $legs;
    }

    /** @param list<array<string,mixed>> $legs */
    private function assertEvidenceBounds(array $dispatch, array $closure, array $legs): void
    {
        $first = $legs[0];
        $last = $legs[count($legs) - 1];
        $flightUuid = strtolower((string)($dispatch['workflow_flight_record_uuid'] ?? ''));
        $adjustment = $this->latestFlightLogAdjustment($flightUuid);
        foreach (array(
            array((float)$first['starting_hobbs'], (float)($adjustment['starting_hobbs'] ?? $dispatch['starting_hobbs']), 'starting Hobbs'),
            array((float)$first['starting_tacho'], (float)($adjustment['starting_tacho'] ?? $dispatch['starting_tacho']), 'starting Tacho'),
            array((float)$last['ending_hobbs'], (float)($adjustment['ending_hobbs'] ?? $closure['ending_hobbs']), 'ending Hobbs'),
            array((float)$last['ending_tacho'], (float)($adjustment['ending_tacho'] ?? $closure['ending_tacho']), 'ending Tacho'),
        ) as [$actual, $expected, $label]) {
            if (abs($actual - $expected) > 0.05) {
                throw new RuntimeException("Verified legs must preserve the {$label} evidence boundary.");
            }
        }
        $closurePayload = json_decode((string)($closure['payload_json'] ?? '{}'), true);
        $closureEvidence = is_array($closurePayload['evidence'] ?? null)
            ? $closurePayload['evidence']
            : (is_array($closurePayload) ? $closurePayload : array());
        $expectedTakeoffs = isset($closureEvidence['verified_takeoff_count'])
            ? max(0, (int)$closureEvidence['verified_takeoff_count'])
            : null;
        $expectedLandings = isset($closureEvidence['verified_landing_count'])
            ? max(0, (int)$closureEvidence['verified_landing_count'])
            : null;
        if ($expectedTakeoffs === null || $expectedLandings === null) {
            $eventCounts = $this->operationEventCounts($flightUuid);
            $expectedTakeoffs ??= $eventCounts['takeoffs'];
            $expectedLandings ??= $eventCounts['landings'];
        }
        $actualTakeoffs = array_sum(array_column($legs, 'takeoff_count'));
        $actualLandings = array_sum(array_column($legs, 'landing_count'));
        if ($expectedTakeoffs !== null && $actualTakeoffs !== $expectedTakeoffs) {
            throw new RuntimeException('Verified leg takeoffs must match the Check-In total.');
        }
        if ($expectedLandings !== null && $actualLandings !== $expectedLandings) {
            throw new RuntimeException('Verified leg landings must match the Check-In total.');
        }

        $expectedFuelStart = is_numeric($dispatch['fuel_onboard'] ?? null)
            ? round((float)$dispatch['fuel_onboard'], 1)
            : null;
        $expectedFuelEnd = $this->nullableFloat(
            $adjustment['fuel_remaining'] ?? $closure['fuel_remaining'] ?? null
        );
        if ($expectedFuelStart !== null && $expectedFuelEnd !== null) {
            if ($first['fuel_onboard'] === null || $last['fuel_remaining'] === null
                || abs((float)$first['fuel_onboard'] - $expectedFuelStart) > 0.05
                || abs((float)$last['fuel_remaining'] - $expectedFuelEnd) > 0.05) {
                throw new RuntimeException('Verified legs must preserve the total fuel evidence boundary.');
            }
            $actualFuelBurn = 0.0;
            for ($index = 0; $index < count($legs); $index++) {
                $leg = $legs[$index];
                if ($leg['fuel_onboard'] === null || $leg['fuel_remaining'] === null
                    || (float)$leg['fuel_remaining'] > (float)$leg['fuel_onboard'] + 0.05) {
                    throw new RuntimeException('Every verified leg requires a valid non-increasing fuel range.');
                }
                $actualFuelBurn += (float)$leg['fuel_onboard'] - (float)$leg['fuel_remaining'];
                if ($index > 0
                    && abs((float)$legs[$index - 1]['fuel_remaining'] - (float)$leg['fuel_onboard']) > 0.05) {
                    throw new RuntimeException('Verified leg fuel boundaries must form a continuous chain.');
                }
            }
            $expectedFuelBurn = max(0.0, $expectedFuelStart - $expectedFuelEnd);
            if (abs($actualFuelBurn - $expectedFuelBurn) > 0.11) {
                throw new RuntimeException('Verified leg fuel consumption must match the Check-In total.');
            }
        }
        for ($index = 0; $index < count($legs); $index++) {
            $leg = $legs[$index];
            if ((float)$leg['ending_hobbs'] < (float)$leg['starting_hobbs']
                || (float)$leg['ending_tacho'] < (float)$leg['starting_tacho']) {
                throw new RuntimeException('Leg meter values cannot decrease.');
            }
            if ($index > 0) {
                $previous = $legs[$index - 1];
                if (abs((float)$previous['ending_hobbs'] - (float)$leg['starting_hobbs']) > 0.05
                    || abs((float)$previous['ending_tacho'] - (float)$leg['starting_tacho']) > 0.05) {
                    throw new RuntimeException('Verified leg meter boundaries must form a continuous chain.');
                }
            }
        }
    }

    /** @return array<string,mixed> */
    private function closure(string $flightUuid): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_cvr_flight_closures
             WHERE LOWER(workflow_flight_record_uuid) = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(array($flightUuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Flight Check-In must be synchronized before legs can be accepted.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function latestFlightLogAdjustment(string $flightUuid): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_cvr_flight_log_adjustments
             WHERE LOWER(workflow_flight_record_uuid) = ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(array(strtolower($flightUuid)));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : array();
    }

    /** @return array{takeoffs:int,landings:int} */
    private function operationEventCounts(string $flightUuid): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
               SUM(CASE WHEN event_type IN ('gps_takeoff_provisional', 'manual_takeoff_adjustment') THEN 1 ELSE 0 END) AS takeoffs,
               SUM(CASE WHEN event_type IN ('gps_landing_provisional', 'manual_landing_adjustment') THEN 1 ELSE 0 END) AS landings
             FROM ipca_cvr_flight_events
             WHERE LOWER(workflow_flight_record_uuid) = ?"
        );
        $stmt->execute(array(strtolower($flightUuid)));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
        return array(
            'takeoffs' => max(0, (int)($row['takeoffs'] ?? 0)),
            'landings' => max(0, (int)($row['landings'] ?? 0)),
        );
    }

    private function garminEvidenceHash(string $flightUuid): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT sha256 FROM ipca_garmin_csv_files
             WHERE LOWER(workflow_flight_record_uuid) = ?
               AND sha256 IS NOT NULL AND sha256 <> \'\'
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(array(strtolower($flightUuid)));
        $value = strtolower(trim((string)$stmt->fetchColumn()));
        return preg_match('/^[a-f0-9]{64}$/', $value) ? $value : null;
    }

    /** @return array{sha256:string,source:string} */
    private function currentEvidence(string $flightUuid): array
    {
        $garminHash = $this->garminEvidenceHash($flightUuid);
        if ($garminHash !== null) {
            return array('sha256' => $garminHash, 'source' => 'verified_garmin_evidence');
        }
        $stmt = $this->pdo->prepare(
            "SELECT event_uuid, payload_sha256
             FROM ipca_cvr_flight_events
             WHERE LOWER(workflow_flight_record_uuid) = ?
               AND event_type IN (
                 'gps_takeoff_provisional','gps_landing_provisional',
                 'engine_start_off_block','engine_shutdown_on_block'
               )
             ORDER BY timestamp_utc, event_uuid"
        );
        $stmt->execute(array(strtolower($flightUuid)));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        if ($rows !== array()) {
            return array(
                'sha256' => hash('sha256', AuditEventService::jsonEncode($rows)),
                'source' => 'ios_gps_provisional',
            );
        }
        return array(
            'sha256' => hash('sha256', 'device-reviewed-offline|' . strtolower($flightUuid)),
            'source' => 'device_reviewed_offline',
        );
    }

    /** @return array<string,mixed>|null */
    private function reviewByUuid(string $revisionUuid): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_operational_session_leg_reviews WHERE revision_uuid = ? LIMIT 1'
        );
        $stmt->execute(array($revisionUuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function latestReview(string $sessionUuid, bool $forUpdate): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_operational_session_leg_reviews
             WHERE operational_session_uuid = ?
             ORDER BY revision_number DESC LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute(array($sessionUuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param list<array<string,mixed>> $legs */
    private function persistCurrentProjection(
        int $dispatchId,
        array $legs,
        string $revisionUuid,
        string $evidenceHash
    ): void {
        $stmt = $this->pdo->prepare(
            'SELECT v.id, v.payload_json
             FROM ipca_cvr_dispatches d
             INNER JOIN ipca_cvr_dispatch_versions v
               ON v.dispatch_id = d.id AND v.dispatch_version = d.current_version
             WHERE d.id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(array($dispatchId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Dispatch projection was not found.');
        }
        $payload = json_decode((string)($row['payload_json'] ?? '{}'), true);
        $payload = is_array($payload) ? $payload : array();
        $payload['leg_segments'] = $legs;
        $payload['planned_departure_airport'] = $legs[0]['departure_airport'];
        $payload['planned_destination_airport'] = $legs[count($legs) - 1]['arrival_airport'];
        $payload['via_airports'] = CvrAdminLegSplitService::viaAirportsFromSegments($legs);
        $payload['operational_leg_review_revision_uuid'] = $revisionUuid;
        $payload['operational_leg_review_evidence_sha256'] = $evidenceHash;
        $this->pdo->prepare(
            'UPDATE ipca_cvr_dispatch_versions SET payload_json = ? WHERE id = ?'
        )->execute(array(AuditEventService::jsonEncode($payload), (int)$row['id']));
    }

    private function uuid(string $value, string $field): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value)) {
            throw new RuntimeException("Valid {$field} is required.");
        }
        return $value;
    }

    private function timestamp(string $value, string $field): string
    {
        $timestamp = strtotime(trim($value));
        if ($timestamp === false) {
            throw new RuntimeException("Valid {$field} is required.");
        }
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' || !is_numeric($value)
            ? null
            : round((float)$value, 1);
    }
}
