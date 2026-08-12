<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/CvrSyncException.php';
require_once __DIR__ . '/FlightSessionService.php';
require_once __DIR__ . '/tv_adsb_status.php';

/**
 * Release an accidental Dispatch claim so the schedule slot can be used again.
 * Allowed only before flight evidence (events, closures, uploaded audio) exists.
 */
final class CvrDispatchReleaseService
{
    private const ADMIN_REASON_CODES = array(
        'accidental_dispatch',
        'avionics_maintenance_test',
        'wrong_reservation',
        'wrong_aircraft',
        'wrong_crew',
        'duplicate_dispatch',
        'operation_cancelled',
        'other',
    );

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,string> */
    public static function administrativeReasons(): array
    {
        return array(
            'accidental_dispatch' => 'Accidental Dispatch',
            'avionics_maintenance_test' => 'Avionics or maintenance test',
            'wrong_reservation' => 'Wrong reservation',
            'wrong_aircraft' => 'Wrong aircraft',
            'wrong_crew' => 'Wrong crew',
            'duplicate_dispatch' => 'Duplicate Dispatch',
            'operation_cancelled' => 'Operation cancelled after Dispatch',
            'other' => 'Other',
        );
    }

    /**
     * @return array{
     *   ok:bool,
     *   already_released:bool,
     *   scheduler_record_id:?string,
     *   dispatch_uuid:?string,
     *   flight_record_uuid:?string
     * }
     */
    public function releaseBySchedulerRecordId(
        string $schedulerRecordId,
        ?int $actorUserId = null,
        string $actorType = 'admin',
        ?int $deviceId = null
    ): array {
        $schedulerRecordId = strtolower(trim($schedulerRecordId));
        if (!$this->isUuid($schedulerRecordId)) {
            throw new CvrUserCorrectionRequired('Schedule record id must be a valid UUID.');
        }
        return $this->release(
            schedulerRecordId: $schedulerRecordId,
            dispatchUuid: null,
            actorUserId: $actorUserId,
            actorType: $actorType,
            deviceId: $deviceId
        );
    }

    /**
     * @return array{
     *   ok:bool,
     *   already_released:bool,
     *   scheduler_record_id:?string,
     *   dispatch_uuid:?string,
     *   flight_record_uuid:?string
     * }
     */
    public function releaseByDispatchUuid(
        string $dispatchUuid,
        ?string $schedulerRecordId = null,
        ?int $actorUserId = null,
        string $actorType = 'device',
        ?int $deviceId = null
    ): array {
        $dispatchUuid = strtolower(trim($dispatchUuid));
        if (!$this->isUuid($dispatchUuid)) {
            throw new CvrUserCorrectionRequired('Dispatch UUID must be a valid UUID.');
        }
        $schedulerRecordId = $schedulerRecordId !== null ? strtolower(trim($schedulerRecordId)) : null;
        if ($schedulerRecordId !== null && $schedulerRecordId !== '' && !$this->isUuid($schedulerRecordId)) {
            throw new CvrUserCorrectionRequired('Schedule record id must be a valid UUID.');
        }
        return $this->release(
            schedulerRecordId: ($schedulerRecordId !== null && $schedulerRecordId !== '') ? $schedulerRecordId : null,
            dispatchUuid: $dispatchUuid,
            actorUserId: $actorUserId,
            actorType: $actorType,
            deviceId: $deviceId
        );
    }

    /**
     * Administrative recovery for a Dispatch that may contain stationary recorder
     * evidence but did not become a genuine flight.
     *
     * @return array<string,mixed>
     */
    public function releaseAdministrativelyBySchedulerRecordId(
        string $schedulerRecordId,
        string $reasonCode,
        string $reasonText,
        int $actorUserId,
        string $actorType = 'admin'
    ): array {
        $schedulerRecordId = strtolower(trim($schedulerRecordId));
        $reasonCode = strtolower(trim($reasonCode));
        $reasonText = trim($reasonText);
        if (!$this->isUuid($schedulerRecordId)) {
            throw new CvrUserCorrectionRequired('Schedule record id must be a valid UUID.');
        }
        if (!in_array($reasonCode, self::ADMIN_REASON_CODES, true)) {
            throw new CvrUserCorrectionRequired('Select a valid Undispatch reason.');
        }
        if ($reasonCode === 'other' && $reasonText === '') {
            throw new CvrUserCorrectionRequired('Explain the reason for administrative Undispatch.');
        }
        if ($actorUserId <= 0) {
            throw new CvrUserCorrectionRequired('Administrator identity is required for evidence-bearing Undispatch.');
        }
        if (!$this->tableExists('ipca_cvr_dispatch_release_events')) {
            throw new CvrDependencyNotReady('Administrative Dispatch recovery migration is not available yet.');
        }
        return $this->release(
            schedulerRecordId: $schedulerRecordId,
            dispatchUuid: null,
            actorUserId: $actorUserId,
            actorType: $actorType,
            deviceId: null,
            administrativeOverride: true,
            reasonCode: $reasonCode,
            reasonText: $reasonText
        );
    }

    /**
     * @return array{
     *   ok:bool,
     *   already_released:bool,
     *   scheduler_record_id:?string,
     *   dispatch_uuid:?string,
     *   flight_record_uuid:?string
     * }
     */
    private function release(
        ?string $schedulerRecordId,
        ?string $dispatchUuid,
        ?int $actorUserId,
        string $actorType,
        ?int $deviceId,
        bool $administrativeOverride = false,
        ?string $reasonCode = null,
        ?string $reasonText = null
    ): array {
        if (!$this->tableExists('ipca_flight_schedule_slots') || !$this->tableExists('ipca_cvr_dispatches')) {
            throw new CvrDependencyNotReady('Dispatch release schema is not available yet.');
        }

        $this->pdo->beginTransaction();
        try {
            $slot = null;
            $dispatch = null;

            if ($schedulerRecordId !== null && $schedulerRecordId !== '') {
                $slot = $this->lockSlotBySchedulerRecordId($schedulerRecordId);
            }

            if ($dispatchUuid !== null && $dispatchUuid !== '') {
                $dispatch = $this->lockDispatchByUuid($dispatchUuid);
            } elseif (is_array($slot)) {
                $claimed = strtolower(trim((string)($slot['claimed_dispatch_uuid'] ?? '')));
                if ($claimed !== '') {
                    $dispatch = $this->lockDispatchByUuid($claimed);
                } else {
                    $dispatch = $this->lockDispatchBySchedulerRecordId((string)$slot['scheduler_record_id']);
                }
            }

            if (!is_array($slot) && is_array($dispatch)) {
                $linkedScheduler = strtolower(trim((string)($dispatch['scheduler_record_id'] ?? '')));
                if ($linkedScheduler !== '') {
                    $slot = $this->lockSlotBySchedulerRecordId($linkedScheduler);
                } else {
                    $slot = $this->lockSlotByClaimedDispatchUuid((string)$dispatch['dispatch_uuid']);
                }
            }

            if (!is_array($slot) && !is_array($dispatch)) {
                // A device may have minted and persisted a Dispatch locally while its
                // schedule replacement or Dispatch intake never reached the server.
                // Releasing that valid device-owned UUID is already satisfied here.
                if ($dispatchUuid !== null && $dispatchUuid !== '' && ($deviceId ?? 0) > 0) {
                    $this->pdo->commit();
                    return array(
                        'ok' => true,
                        'already_released' => true,
                        'scheduler_record_id' => $schedulerRecordId,
                        'dispatch_uuid' => $dispatchUuid,
                        'flight_record_uuid' => null,
                    );
                }
                throw new CvrUserCorrectionRequired('No Dispatch claim was found to release.');
            }

            $flightRecordUuid = is_array($dispatch)
                ? strtolower(trim((string)($dispatch['workflow_flight_record_uuid'] ?? '')))
                : '';
            $resolvedDispatchUuid = is_array($dispatch)
                ? strtolower(trim((string)($dispatch['dispatch_uuid'] ?? '')))
                : (is_array($slot) ? strtolower(trim((string)($slot['claimed_dispatch_uuid'] ?? ''))) : '');
            $resolvedSchedulerId = is_array($slot)
                ? strtolower(trim((string)($slot['scheduler_record_id'] ?? '')))
                : (is_array($dispatch) ? strtolower(trim((string)($dispatch['scheduler_record_id'] ?? ''))) : '');

            $slotClaimed = is_array($slot) && (
                (string)($slot['status'] ?? '') === 'claimed'
                || trim((string)($slot['claimed_dispatch_uuid'] ?? '')) !== ''
            );
            $dispatchLinked = is_array($dispatch)
                && strtolower(trim((string)($dispatch['status'] ?? ''))) !== 'released'
                && (
                    trim((string)($dispatch['scheduler_record_id'] ?? '')) !== ''
                    || $slotClaimed
                );

            if (!$slotClaimed && !$dispatchLinked) {
                $this->pdo->commit();
                return array(
                    'ok' => true,
                    'already_released' => true,
                    'scheduler_record_id' => $resolvedSchedulerId !== '' ? $resolvedSchedulerId : null,
                    'dispatch_uuid' => $resolvedDispatchUuid !== '' ? $resolvedDispatchUuid : null,
                    'flight_record_uuid' => $flightRecordUuid !== '' ? $flightRecordUuid : null,
                );
            }

            if (is_array($slot) && (string)($slot['status'] ?? '') === 'cancelled') {
                throw new CvrUserCorrectionRequired('Cancelled reservations cannot be undispatched.');
            }

            // Multi-leg reservations share one schedule slot. Earlier hops can mark the slot
            // completed while a later Dispatch still has no evidence and must remain releaseable.
            // Block "completed" only when THIS Dispatch itself already has closure/events/audio.
            $evidenceSummary = array();
            if ($flightRecordUuid !== '') {
                if ($administrativeOverride) {
                    $evidenceSummary = $this->assertAdministrativeReleaseAllowed(
                        $flightRecordUuid,
                        (string)($dispatch['aircraft_registration'] ?? '')
                    );
                } else {
                    $this->assertNoFlightEvidence($flightRecordUuid);
                }
            }

            if (is_array($dispatch)) {
                $operationalSessionUuid = strtolower(trim((string)($dispatch['operational_session_uuid'] ?? '')));
                if ($operationalSessionUuid !== '') {
                    (new FlightSessionService($this->pdo))->cancelOperationalSession($operationalSessionUuid);
                }
                $this->pdo->prepare(
                    "UPDATE ipca_cvr_dispatches
                     SET status = 'released',
                         scheduler_record_id = NULL,
                         updated_at = CURRENT_TIMESTAMP(3)
                     WHERE id = ?"
                )->execute(array((int)$dispatch['id']));
            }

            if (is_array($slot)) {
                $this->reconcileSlotAfterDispatchRelease(
                    $slot,
                    $resolvedSchedulerId,
                    $resolvedDispatchUuid,
                    $actorUserId
                );
            }

            if ($administrativeOverride && is_array($dispatch)) {
                $this->recordAdministrativeRelease(
                    $dispatch,
                    $resolvedSchedulerId,
                    (string)$reasonCode,
                    (string)$reasonText,
                    $evidenceSummary,
                    $actorType,
                    $actorUserId
                );
            }

            (new AuditEventService($this->pdo))->record(
                'cvr_dispatch_released',
                'ipca_flight_schedule_slots',
                $resolvedSchedulerId !== '' ? $resolvedSchedulerId : ($resolvedDispatchUuid ?: 'unknown'),
                null,
                array(
                    'scheduler_record_id' => $resolvedSchedulerId !== '' ? $resolvedSchedulerId : null,
                    'dispatch_uuid' => $resolvedDispatchUuid !== '' ? $resolvedDispatchUuid : null,
                    'flight_record_uuid' => $flightRecordUuid !== '' ? $flightRecordUuid : null,
                    'actor_type' => $actorType,
                ),
                $administrativeOverride
                    ? 'Administrative Undispatch: ' . (self::administrativeReasons()[(string)$reasonCode] ?? 'Other')
                        . ((string)$reasonText !== '' ? ' — ' . (string)$reasonText : '')
                    : 'Accidental Dispatch claim released before flight evidence.',
                $actorType,
                $actorUserId,
                $deviceId,
                null,
                is_array($dispatch) ? (int)($dispatch['organization_id'] ?? 1) : 1,
                'cvr_app'
            );

            $this->pdo->commit();
            return array(
                'ok' => true,
                'already_released' => false,
                'scheduler_record_id' => $resolvedSchedulerId !== '' ? $resolvedSchedulerId : null,
                'dispatch_uuid' => $resolvedDispatchUuid !== '' ? $resolvedDispatchUuid : null,
                'flight_record_uuid' => $flightRecordUuid !== '' ? $flightRecordUuid : null,
                'administrative_override' => $administrativeOverride,
                'evidence_summary' => $evidenceSummary,
            );
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * After releasing one Dispatch, either reopen the schedule slot or keep it claimed/completed
     * for remaining multi-leg sibling Dispatches / closures.
     *
     * @param array<string,mixed> $slot
     */
    private function reconcileSlotAfterDispatchRelease(
        array $slot,
        string $schedulerRecordId,
        string $releasedDispatchUuid,
        ?int $actorUserId
    ): void {
        $retained = $this->findRetainedSiblingDispatch($schedulerRecordId, $releasedDispatchUuid);
        $siblingClosure = $this->schedulerHasClosureOutsideDispatch($schedulerRecordId, $releasedDispatchUuid);

        if ($retained === null && !$siblingClosure) {
            $this->pdo->prepare(
                "UPDATE ipca_flight_schedule_slots
                 SET status = 'scheduled',
                     claimed_dispatch_uuid = NULL,
                     claimed_at = NULL,
                     updated_by = ?
                 WHERE id = ?"
            )->execute(array($actorUserId, (int)$slot['id']));
            return;
        }

        $status = $siblingClosure ? 'completed' : 'claimed';
        $claimUuid = is_array($retained)
            ? strtolower(trim((string)($retained['dispatch_uuid'] ?? '')))
            : strtolower(trim((string)($slot['claimed_dispatch_uuid'] ?? '')));
        if ($claimUuid === '' || $claimUuid === strtolower(trim($releasedDispatchUuid))) {
            $claimUuid = is_array($retained)
                ? strtolower(trim((string)($retained['dispatch_uuid'] ?? '')))
                : '';
        }
        if ($claimUuid === '') {
            // Closures exist without a live sibling Dispatch — keep completed, clear claim pointer.
            $this->pdo->prepare(
                "UPDATE ipca_flight_schedule_slots
                 SET status = 'completed',
                     claimed_dispatch_uuid = NULL,
                     updated_by = ?
                 WHERE id = ?"
            )->execute(array($actorUserId, (int)$slot['id']));
            return;
        }

        $this->pdo->prepare(
            "UPDATE ipca_flight_schedule_slots
             SET status = ?,
                 claimed_dispatch_uuid = ?,
                 updated_by = ?
             WHERE id = ?"
        )->execute(array($status, $claimUuid, $actorUserId, (int)$slot['id']));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findRetainedSiblingDispatch(string $schedulerRecordId, string $releasedDispatchUuid): ?array
    {
        $schedulerRecordId = strtolower(trim($schedulerRecordId));
        $releasedDispatchUuid = strtolower(trim($releasedDispatchUuid));
        if ($schedulerRecordId === '') {
            return null;
        }
        $statement = $this->pdo->prepare(
            "SELECT *
             FROM ipca_cvr_dispatches
             WHERE LOWER(TRIM(COALESCE(scheduler_record_id, ''))) = ?
               AND LOWER(TRIM(dispatch_uuid)) <> ?
               AND LOWER(TRIM(COALESCE(status, ''))) <> 'released'
             ORDER BY id ASC
             LIMIT 1"
        );
        $statement->execute(array($schedulerRecordId, $releasedDispatchUuid !== '' ? $releasedDispatchUuid : '-'));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function schedulerHasClosureOutsideDispatch(string $schedulerRecordId, string $releasedDispatchUuid): bool
    {
        $schedulerRecordId = strtolower(trim($schedulerRecordId));
        $releasedDispatchUuid = strtolower(trim($releasedDispatchUuid));
        if ($schedulerRecordId === '' || !$this->tableExists('ipca_cvr_flight_closures')) {
            return false;
        }
        // Closures still linked through an active scheduler_record_id on sibling Dispatches.
        $statement = $this->pdo->prepare(
            "SELECT 1
             FROM ipca_cvr_flight_closures closure_record
             INNER JOIN ipca_cvr_dispatches dispatch_record
               ON LOWER(TRIM(dispatch_record.workflow_flight_record_uuid))
                  = LOWER(TRIM(closure_record.workflow_flight_record_uuid))
             WHERE LOWER(TRIM(COALESCE(dispatch_record.scheduler_record_id, ''))) = ?
               AND LOWER(TRIM(dispatch_record.dispatch_uuid)) <> ?
             LIMIT 1"
        );
        $statement->execute(array(
            $schedulerRecordId,
            $releasedDispatchUuid !== '' ? $releasedDispatchUuid : '-',
        ));
        if ($statement->fetchColumn()) {
            return true;
        }
        // Slot claim owner may be an earlier hop that already checked in.
        $slotClaim = $this->pdo->prepare(
            "SELECT LOWER(TRIM(COALESCE(claimed_dispatch_uuid, '')))
             FROM ipca_flight_schedule_slots
             WHERE LOWER(TRIM(scheduler_record_id)) = ?
             LIMIT 1"
        );
        $slotClaim->execute(array($schedulerRecordId));
        $claimed = strtolower(trim((string)($slotClaim->fetchColumn() ?: '')));
        if ($claimed === '' || $claimed === $releasedDispatchUuid) {
            return false;
        }
        $byClaim = $this->pdo->prepare(
            "SELECT 1
             FROM ipca_cvr_flight_closures closure_record
             INNER JOIN ipca_cvr_dispatches dispatch_record
               ON LOWER(TRIM(dispatch_record.workflow_flight_record_uuid))
                  = LOWER(TRIM(closure_record.workflow_flight_record_uuid))
             WHERE LOWER(TRIM(dispatch_record.dispatch_uuid)) = ?
             LIMIT 1"
        );
        $byClaim->execute(array($claimed));
        return (bool)$byClaim->fetchColumn();
    }

    /**
     * Audio and stationary recorder evidence are allowed for administrative
     * recovery. A closure, Garmin data, airborne event, or meaningful movement
     * still requires Check-In instead of Undispatch.
     *
     * @return array<string,mixed>
     */
    private function assertAdministrativeReleaseAllowed(
        string $flightRecordUuid,
        string $aircraftRegistration
    ): array
    {
        $summary = array(
            'closure_count' => 0,
            'event_count' => 0,
            'audio_count' => 0,
            'garmin_count' => 0,
            'airborne_event_count' => 0,
            'maximum_ground_speed_kt' => 0.0,
            'live_adsb_airborne' => false,
            'live_adsb_checked' => false,
        );
        if ($this->tableExists('ipca_cvr_flight_closures')) {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ipca_cvr_flight_closures WHERE workflow_flight_record_uuid = ?'
            );
            $statement->execute(array($flightRecordUuid));
            $summary['closure_count'] = (int)$statement->fetchColumn();
        }
        if ($summary['closure_count'] > 0) {
            throw new CvrUserCorrectionRequired(
                'Undispatch is blocked because Check-In already exists. Use an administrative flight correction instead.'
            );
        }

        if ($this->tableExists('ipca_cvr_flight_events')) {
            $statement = $this->pdo->prepare(
                'SELECT event_type, ground_speed, payload_json
                 FROM ipca_cvr_flight_events
                 WHERE workflow_flight_record_uuid = ?
                 ORDER BY id ASC'
            );
            $statement->execute(array($flightRecordUuid));
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: array() as $event) {
                $summary['event_count']++;
                $type = strtolower(trim((string)($event['event_type'] ?? '')));
                if (str_contains($type, 'takeoff')
                    || str_contains($type, 'landing')
                    || str_contains($type, 'airborne')) {
                    $summary['airborne_event_count']++;
                }
                $speed = is_numeric($event['ground_speed'] ?? null)
                    ? (float)$event['ground_speed']
                    : 0.0;
                $payload = json_decode((string)($event['payload_json'] ?? '{}'), true);
                if (is_array($payload)) {
                    foreach (array('ground_speed', 'ground_speed_kt', 'groundspeed_kt') as $key) {
                        if (is_numeric($payload[$key] ?? null)) {
                            $speed = max($speed, (float)$payload[$key]);
                        }
                    }
                }
                $summary['maximum_ground_speed_kt'] = max(
                    (float)$summary['maximum_ground_speed_kt'],
                    $speed
                );
            }
        }

        if ($this->tableExists('ipca_cockpit_recordings')) {
            $statement = $this->pdo->prepare(
                "SELECT health_summary_json
                 FROM ipca_cockpit_recordings
                 WHERE LOWER(flight_session_uid) = LOWER(?)
                   AND upload_status = 'uploaded'"
            );
            $statement->execute(array($flightRecordUuid));
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: array() as $recording) {
                $summary['audio_count']++;
                $health = json_decode((string)($recording['health_summary_json'] ?? '{}'), true);
                $speed = $health['gps']['max_groundspeed_kt'] ?? null;
                if (is_numeric($speed)) {
                    $summary['maximum_ground_speed_kt'] = max(
                        (float)$summary['maximum_ground_speed_kt'],
                        (float)$speed
                    );
                }
            }
        }

        if ($this->tableExists('ipca_garmin_csv_files')) {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ipca_garmin_csv_files WHERE workflow_flight_record_uuid = ?'
            );
            $statement->execute(array($flightRecordUuid));
            $summary['garmin_count'] = (int)$statement->fetchColumn();
        }
        if ($summary['garmin_count'] > 0) {
            throw new CvrUserCorrectionRequired(
                'Undispatch is blocked because Garmin flight data exists. Complete Check-In or correct the flight administratively.'
            );
        }
        if ($summary['airborne_event_count'] > 0 || (float)$summary['maximum_ground_speed_kt'] >= 30.0) {
            throw new CvrUserCorrectionRequired(
                'Undispatch is blocked because airborne or meaningful movement evidence exists. Complete Check-In instead.'
            );
        }
        $aircraftRegistration = strtoupper(trim($aircraftRegistration));
        if ($aircraftRegistration !== '') {
            try {
                $live = tv_adsb_fetch_by_registration($aircraftRegistration);
                $summary['live_adsb_checked'] = true;
                $summary['live_adsb_airborne'] = is_array($live)
                    && tv_adsb_is_actively_airborne($live);
            } catch (Throwable $e) {
                $summary['live_adsb_error'] = substr($e->getMessage(), 0, 255);
            }
            if ($summary['live_adsb_airborne']) {
                throw new CvrUserCorrectionRequired(
                    'Undispatch is blocked because the aircraft is currently airborne. Complete Check-In instead.'
                );
            }
        }
        return $summary;
    }

    /** @param array<string,mixed> $dispatch @param array<string,mixed> $evidenceSummary */
    private function recordAdministrativeRelease(
        array $dispatch,
        string $schedulerRecordId,
        string $reasonCode,
        string $reasonText,
        array $evidenceSummary,
        string $actorType,
        ?int $actorUserId
    ): void {
        $this->pdo->prepare(
            'INSERT INTO ipca_cvr_dispatch_release_events
              (release_uuid, dispatch_id, dispatch_uuid, scheduler_record_id,
               workflow_flight_record_uuid, operational_session_uuid, release_mode,
               reason_code, reason_text, evidence_summary_json, actor_type, actor_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            AuditEventService::uuid(),
            (int)$dispatch['id'],
            strtolower(trim((string)$dispatch['dispatch_uuid'])),
            $schedulerRecordId !== '' ? $schedulerRecordId : null,
            strtolower(trim((string)($dispatch['workflow_flight_record_uuid'] ?? ''))) ?: null,
            strtolower(trim((string)($dispatch['operational_session_uuid'] ?? ''))) ?: null,
            'administrative_evidence_release',
            $reasonCode,
            $reasonText !== '' ? substr($reasonText, 0, 512) : null,
            AuditEventService::jsonEncode($evidenceSummary),
            substr($actorType, 0, 32),
            $actorUserId,
        ));
    }

    private function assertNoFlightEvidence(string $flightRecordUuid): void
    {
        if ($this->tableExists('ipca_cvr_flight_closures')) {
            $statement = $this->pdo->prepare(
                'SELECT 1 FROM ipca_cvr_flight_closures WHERE workflow_flight_record_uuid = ? LIMIT 1'
            );
            $statement->execute(array($flightRecordUuid));
            if ($statement->fetchColumn()) {
                throw new CvrUserCorrectionRequired('Undispatch is blocked because Check-In / closure evidence already exists.');
            }
        }
        if ($this->tableExists('ipca_cvr_flight_events')) {
            $statement = $this->pdo->prepare(
                'SELECT 1 FROM ipca_cvr_flight_events WHERE workflow_flight_record_uuid = ? LIMIT 1'
            );
            $statement->execute(array($flightRecordUuid));
            if ($statement->fetchColumn()) {
                throw new CvrUserCorrectionRequired('Undispatch is blocked because flight events already exist for this Dispatch.');
            }
        }
        if ($this->tableExists('ipca_cockpit_recordings')) {
            $statement = $this->pdo->prepare(
                "SELECT 1 FROM ipca_cockpit_recordings
                 WHERE flight_session_uid = ?
                   AND upload_status = 'uploaded'
                 LIMIT 1"
            );
            $statement->execute(array($flightRecordUuid));
            if ($statement->fetchColumn()) {
                throw new CvrUserCorrectionRequired('Undispatch is blocked because cockpit audio has already been uploaded.');
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function lockSlotBySchedulerRecordId(string $schedulerRecordId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM ipca_flight_schedule_slots WHERE scheduler_record_id = ? LIMIT 1 FOR UPDATE'
        );
        $statement->execute(array($schedulerRecordId));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function lockSlotByClaimedDispatchUuid(string $dispatchUuid): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM ipca_flight_schedule_slots WHERE claimed_dispatch_uuid = ? LIMIT 1 FOR UPDATE'
        );
        $statement->execute(array($dispatchUuid));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function lockDispatchByUuid(string $dispatchUuid): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM ipca_cvr_dispatches WHERE dispatch_uuid = ? LIMIT 1 FOR UPDATE'
        );
        $statement->execute(array($dispatchUuid));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function lockDispatchBySchedulerRecordId(string $schedulerRecordId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM ipca_cvr_dispatches WHERE scheduler_record_id = ? LIMIT 1 FOR UPDATE'
        );
        $statement->execute(array($schedulerRecordId));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function isUuid(string $value): bool
    {
        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }

    private function tableExists(string $table): bool
    {
        static $cache = array();
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        $statement->execute(array($table));
        $cache[$table] = (bool)$statement->fetchColumn();
        return $cache[$table];
    }
}
