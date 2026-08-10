<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/CvrSyncException.php';
require_once __DIR__ . '/FlightSessionService.php';

/**
 * Release an accidental Dispatch claim so the schedule slot can be used again.
 * Allowed only before flight evidence (events, closures, uploaded audio) exists.
 */
final class CvrDispatchReleaseService
{
    public function __construct(private PDO $pdo)
    {
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
        ?int $deviceId
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
            if ($flightRecordUuid !== '') {
                $this->assertNoFlightEvidence($flightRecordUuid);
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
                'Accidental Dispatch claim released before flight evidence.',
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
