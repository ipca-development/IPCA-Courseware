<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/CvrAdminLegSplitService.php';
require_once __DIR__ . '/CvrOperationalBlockTimeService.php';
require_once __DIR__ . '/CvrOperationalLegTimelineService.php';
require_once __DIR__ . '/CvrOperationalSessionLegReviewService.php';
require_once __DIR__ . '/CvrWorkflowEvidenceIntakeService.php';
require_once __DIR__ . '/time.php';

/**
 * Online administrative Check-In.
 *
 * Flight closure is committed first. Audio, Garmin, and leg verification are
 * independent recovery stages and cannot roll back or block that closure.
 */
final class CvrAdminManualCheckInService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function checkIn(string $schedulerRecordId, array $input, int $actorUserId): array
    {
        if ($actorUserId <= 0) {
            throw new RuntimeException('Administrator identity is required for online Check-In.');
        }
        $dispatch = $this->dispatchByScheduler($schedulerRecordId);
        $existing = $this->closure((string)$dispatch['workflow_flight_record_uuid']);
        if (is_array($existing)) {
            return $this->recoveryContext((string)$dispatch['workflow_flight_record_uuid']);
        }

        $timezone = cw_aircraft_operational_timezone($this->pdo, (int)$dispatch['aircraft_id']);
        $shutdownLocal = trim((string)($input['engine_shutdown_local'] ?? ''));
        $shutdownUtc = cw_local_input_to_utc($shutdownLocal, $timezone);
        if ($shutdownUtc === null) {
            throw new RuntimeException('Engine shutdown date and time are required.');
        }
        $shutdownTimestamp = strtotime($shutdownUtc . ' UTC');
        if ($shutdownTimestamp === false || $shutdownTimestamp > time() + 300) {
            throw new RuntimeException('Engine shutdown time cannot be in the future.');
        }

        $endingHobbs = $this->meter($input['ending_hobbs'] ?? null, 'Actual End Hobbs');
        $endingTacho = $this->meter($input['ending_tacho'] ?? null, 'Actual End Tacho');
        if ($endingHobbs < (float)$dispatch['starting_hobbs']) {
            throw new RuntimeException('Actual End Hobbs cannot be lower than Dispatch Hobbs.');
        }
        if ($endingTacho < (float)$dispatch['starting_tacho']) {
            throw new RuntimeException('Actual End Tacho cannot be lower than Dispatch Tacho.');
        }
        $fuelRemaining = $this->number($input['fuel_remaining'] ?? null, 'Actual fuel remaining');
        $offBlockUtc = null;
        $offBlockLocal = trim((string)($input['off_block_local'] ?? ''));
        if ($offBlockLocal !== '') {
            $offBlockUtc = cw_local_input_to_utc($offBlockLocal, $timezone);
            if ($offBlockUtc === null || strtotime($offBlockUtc . ' UTC') > $shutdownTimestamp) {
                throw new RuntimeException('Off Block time must be valid and cannot follow Engine Shutdown.');
            }
        }

        $componentUuid = $this->optionalUuid($input['component_uuid'] ?? null) ?? AuditEventService::uuid();
        $closureUuid = $this->optionalUuid($input['closure_uuid'] ?? null) ?? AuditEventService::uuid();
        $evidence = array(
            'closure_uuid' => $closureUuid,
            'timestamp_utc' => $shutdownUtc,
            'timestamp_local' => $shutdownLocal,
            'on_block_utc' => $shutdownUtc,
            'ending_hobbs' => $endingHobbs,
            'ending_tacho' => $endingTacho,
            'fuel_remaining' => number_format($fuelRemaining, 1, '.', ''),
            'verified_takeoff_count' => max(0, (int)($input['verified_takeoff_count'] ?? 0)),
            'verified_landing_count' => max(0, (int)($input['verified_landing_count'] ?? 0)),
            'maintenance_remark' => trim((string)($input['maintenance_remark'] ?? '')),
            'creation_method' => 'admin_online_checkin',
            'actor_user_id' => $actorUserId,
        );
        if ($offBlockUtc !== null) {
            $evidence['off_block_utc'] = $offBlockUtc;
        }

        $payload = array(
            'component_uuid' => $componentUuid,
            'flight_record_uuid' => strtolower((string)$dispatch['workflow_flight_record_uuid']),
            'dispatch_uuid' => strtolower((string)$dispatch['dispatch_uuid']),
            'operational_session_uuid' => strtolower((string)$dispatch['operational_session_uuid']),
            'component_type' => 'flight_record_closure',
            'schema_version' => 2,
            'evidence' => $evidence,
        );
        (new CvrWorkflowEvidenceIntakeService($this->pdo))->receive(
            $payload,
            array(
                'id' => (int)$dispatch['device_id'],
                'organization_id' => (int)($dispatch['organization_id'] ?? 1),
            ),
            array(
                'actor_type' => 'admin',
                'actor_user_id' => $actorUserId,
                'source' => 'online_scheduler',
            )
        );

        $context = $this->recoveryContext((string)$dispatch['workflow_flight_record_uuid']);
        $context['automatic_leg_result'] = $this->attemptAutomaticLegVerification(
            (string)$dispatch['dispatch_uuid'],
            $actorUserId
        );
        return array_merge($context, $this->legStatus((string)$dispatch['workflow_flight_record_uuid']));
    }

    /** @return array<string,mixed> */
    public function recoveryContext(string $workflowFlightRecordUuid): array
    {
        $dispatch = $this->dispatchByWorkflow($workflowFlightRecordUuid);
        $closure = $this->closure((string)$dispatch['workflow_flight_record_uuid']);
        $audio = $this->count(
            'SELECT COUNT(*) FROM ipca_cockpit_recordings WHERE LOWER(flight_session_uid) = LOWER(?)',
            array($dispatch['workflow_flight_record_uuid'])
        );
        $garmin = $this->count(
            'SELECT COUNT(*) FROM ipca_garmin_csv_files WHERE LOWER(workflow_flight_record_uuid) = LOWER(?)',
            array($dispatch['workflow_flight_record_uuid'])
        );
        $crew = json_decode((string)($dispatch['crew_json'] ?? '[]'), true);
        return array_merge(array(
            'ok' => true,
            'dispatch_id' => (int)$dispatch['id'],
            'dispatch_uuid' => strtolower((string)$dispatch['dispatch_uuid']),
            'scheduler_record_id' => strtolower((string)($dispatch['scheduler_record_id'] ?? '')),
            'workflow_flight_record_uuid' => strtolower((string)$dispatch['workflow_flight_record_uuid']),
            'operational_session_uuid' => strtolower((string)$dispatch['operational_session_uuid']),
            'aircraft_id' => (int)$dispatch['aircraft_id'],
            'aircraft_registration' => strtoupper((string)$dispatch['aircraft_registration']),
            'mission_code' => (string)($dispatch['mission_code'] ?? ''),
            'crew' => is_array($crew) ? $crew : array(),
            'starting_hobbs' => (float)$dispatch['starting_hobbs'],
            'starting_tacho' => (float)$dispatch['starting_tacho'],
            'fuel_onboard' => (string)($dispatch['fuel_onboard'] ?? ''),
            'ending_hobbs' => is_array($closure) ? (float)$closure['ending_hobbs'] : null,
            'ending_tacho' => is_array($closure) ? (float)$closure['ending_tacho'] : null,
            'fuel_remaining' => is_array($closure) ? (string)$closure['fuel_remaining'] : null,
            'checked_in' => is_array($closure),
            'has_audio' => $audio > 0,
            'has_garmin_csv' => $garmin > 0,
            'master_logbook_url' => '/admin/master_logbook.php?tab=legs&flight='
                . rawurlencode(strtolower((string)$dispatch['workflow_flight_record_uuid'])),
        ), $this->legStatus((string)$dispatch['workflow_flight_record_uuid']));
    }

    /** @return array<string,mixed> */
    public function attemptAutomaticLegVerification(string $dispatchUuid, int $actorUserId): array
    {
        try {
            $service = new CvrOperationalSessionLegReviewService($this->pdo);
            $previewResult = $service->previewForAdmin($dispatchUuid);
            $preview = is_array($previewResult['review'] ?? null) ? $previewResult['review'] : array();
            if (empty($preview['has_garmin_csv']) || empty($preview['has_cvr_gps'])) {
                return array(
                    'status' => 'manual_required',
                    'message' => 'Automatic verification requires both CVR GPS and Garmin CSV evidence.',
                );
            }
            $legs = is_array($preview['proposed_legs'] ?? null) ? $preview['proposed_legs'] : array();
            if ($legs === array()) {
                return array('status' => 'manual_required', 'message' => 'No automatic leg proposal is available.');
            }
            $accepted = $service->acceptForAdmin($actorUserId, array(
                'revision_uuid' => AuditEventService::uuid(),
                'dispatch_uuid' => $dispatchUuid,
                'evidence_sha256' => (string)($preview['evidence_sha256'] ?? ''),
                'legs' => $legs,
            ));
            return array(
                'status' => 'verified_automatic',
                'message' => 'Legs were automatically verified from CVR GPS and Garmin CSV evidence.',
                'revision_uuid' => $accepted['revision_uuid'] ?? null,
            );
        } catch (Throwable $e) {
            return array('status' => 'manual_required', 'message' => $e->getMessage());
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function acceptManualSingleLeg(
        string $workflowFlightRecordUuid,
        array $input,
        int $actorUserId
    ): array {
        $context = $this->recoveryContext($workflowFlightRecordUuid);
        if (empty($context['checked_in'])) {
            throw new RuntimeException('Check-In must be completed before manual leg verification.');
        }
        $timezone = cw_aircraft_operational_timezone($this->pdo, (int)$context['aircraft_id']);
        $offBlockUtc = cw_local_input_to_utc(trim((string)($input['off_block_local'] ?? '')), $timezone);
        if ($offBlockUtc === null) {
            throw new RuntimeException('Manual leg verification requires Off Block date and time.');
        }
        $departure = strtoupper(trim((string)($input['departure_airport'] ?? '')));
        $arrival = strtoupper(trim((string)($input['arrival_airport'] ?? '')));
        $onBlockUtc = (new CvrOperationalBlockTimeService())->derivedOnBlockUtc(array(
            'off_block_utc' => $offBlockUtc,
            'starting_hobbs' => (float)$context['starting_hobbs'],
            'ending_hobbs' => (float)$context['ending_hobbs'],
        ));
        if ($onBlockUtc === null) {
            throw new RuntimeException('Unable to derive On Block time from the entered Hobbs values.');
        }
        $service = new CvrOperationalSessionLegReviewService($this->pdo);
        return $service->acceptForAdmin($actorUserId, array(
            'revision_uuid' => AuditEventService::uuid(),
            'dispatch_uuid' => (string)$context['dispatch_uuid'],
            'evidence_sha256' => '',
            'legs' => array(array(
                'departure_airport' => $departure,
                'arrival_airport' => $arrival,
                'off_block_utc' => $offBlockUtc,
                'on_block_utc' => $onBlockUtc,
                'starting_hobbs' => (float)$context['starting_hobbs'],
                'ending_hobbs' => (float)$context['ending_hobbs'],
                'starting_tacho' => (float)$context['starting_tacho'],
                'ending_tacho' => (float)$context['ending_tacho'],
                'takeoff_count' => max(0, (int)($input['takeoff_count'] ?? 1)),
                'landing_count' => max(0, (int)($input['landing_count'] ?? 1)),
                'fuel_onboard' => is_numeric($context['fuel_onboard']) ? (float)$context['fuel_onboard'] : null,
                'fuel_remaining' => is_numeric($context['fuel_remaining']) ? (float)$context['fuel_remaining'] : null,
            )),
        ));
    }

    /** @return array<string,mixed> */
    public function parseAppArchive(array $file, string $expectedFlightUuid): array
    {
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return array();
        }
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
            throw new RuntimeException('The App archive JSON could not be uploaded.');
        }
        $json = file_get_contents((string)$file['tmp_name']);
        $payload = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($payload)) {
            throw new RuntimeException('The App archive is not valid JSON.');
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)
            || !str_contains(strtolower($encoded), strtolower(trim($expectedFlightUuid)))) {
            throw new RuntimeException('The App archive does not belong to this Flight Record.');
        }
        return $payload;
    }

    /** @return array<string,mixed> */
    private function legStatus(string $flightUuid): array
    {
        $dispatch = $this->dispatchByWorkflow($flightUuid);
        $stmt = $this->pdo->prepare(
            'SELECT revision_uuid, review_source, reviewed_at_utc
             FROM ipca_operational_session_leg_reviews
             WHERE operational_session_uuid = ? AND status = \'ACCEPTED\'
             ORDER BY revision_number DESC LIMIT 1'
        );
        $stmt->execute(array(strtolower((string)$dispatch['operational_session_uuid'])));
        $review = $stmt->fetch(PDO::FETCH_ASSOC);
        return array(
            'leg_verification_status' => is_array($review) ? 'verified' : 'manual_required',
            'leg_review_revision_uuid' => is_array($review) ? (string)$review['revision_uuid'] : null,
            'leg_review_source' => is_array($review) ? (string)$review['review_source'] : null,
        );
    }

    /** @return array<string,mixed> */
    private function dispatchByScheduler(string $schedulerRecordId): array
    {
        $uuid = $this->uuid($schedulerRecordId, 'scheduler_record_id');
        $stmt = $this->pdo->prepare(
            "SELECT d.*
             FROM ipca_cvr_dispatches d
             INNER JOIN ipca_flight_schedule_slots s
               ON s.scheduler_record_id = d.scheduler_record_id
              AND s.claimed_dispatch_uuid = d.dispatch_uuid
             WHERE LOWER(d.scheduler_record_id) = ?
               AND LOWER(TRIM(COALESCE(d.status, ''))) <> 'released'
               AND d.operational_session_uuid IS NOT NULL
             ORDER BY d.id DESC LIMIT 1"
        );
        $stmt->execute(array($uuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Active Operational Session Dispatch was not found.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function dispatchByWorkflow(string $flightUuid): array
    {
        $uuid = $this->uuid($flightUuid, 'workflow_flight_record_uuid');
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_cvr_dispatches
             WHERE LOWER(workflow_flight_record_uuid) = ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(array($uuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Dispatch for this Flight Record was not found.');
        }
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function closure(string $flightUuid): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_cvr_flight_closures
             WHERE LOWER(workflow_flight_record_uuid) = LOWER(?)
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(array($flightUuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function meter(mixed $value, string $label): float
    {
        if (!is_numeric($value) || (float)$value < 0) {
            throw new RuntimeException($label . ' must be a valid non-negative meter value.');
        }
        return CvrOperationalLegTimelineService::roundUpToTenth((float)$value);
    }

    private function number(mixed $value, string $label): float
    {
        if (!is_numeric($value) || (float)$value < 0) {
            throw new RuntimeException($label . ' must be a valid non-negative quantity.');
        }
        return (float)$value;
    }

    private function count(string $sql, array $params): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function optionalUuid(mixed $value): ?string
    {
        $value = strtolower(trim((string)$value));
        return $value !== '' ? $this->uuid($value, 'UUID') : null;
    }

    private function uuid(string $value, string $label): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value)) {
            throw new RuntimeException($label . ' must be a valid UUID.');
        }
        return $value;
    }
}
