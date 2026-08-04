<?php
declare(strict_types=1);

require_once __DIR__ . '/CvrOperationalIdentityService.php';
require_once __DIR__ . '/AuditEventService.php';

/**
 * Deterministic Phase 2A identity backfill.
 * Direct immutable relationships only. Source rows are never mutated.
 * Writes require operational_identity_backfill_enabled unless dry-run.
 */
final class CvrOperationalIdentityBackfillService
{
    public function __construct(
        private PDO $pdo,
        private ?CvrOperationalIdentityService $identity = null
    ) {
        $this->identity = $identity ?? new CvrOperationalIdentityService($pdo);
    }

    /**
     * @return array{
     *   dry_run: bool,
     *   scanned_slots: int,
     *   reservations_created: int,
     *   legs_created: int,
     *   aliases_created: int,
     *   quarantined: int,
     *   skipped: int,
     *   actions: list<array<string,mixed>>
     * }
     */
    public function backfill(?int $organizationId = null, bool $dryRun = true, int $limit = 500): array
    {
        if (!$dryRun && !$this->identity->isFlagEnabled(CvrOperationalIdentityService::FLAG_BACKFILL)) {
            throw new RuntimeException('operational_identity_backfill_enabled is off; refusing apply.');
        }

        $summary = array(
            'dry_run' => $dryRun,
            'scanned_slots' => 0,
            'reservations_created' => 0,
            'legs_created' => 0,
            'aliases_created' => 0,
            'quarantined' => 0,
            'skipped' => 0,
            'actions' => array(),
        );

        if (!$this->tableExists('ipca_flight_schedule_slots')) {
            $summary['actions'][] = array('type' => 'skip', 'reason' => 'schedule_table_missing');
            return $summary;
        }

        $sql = "
            SELECT id, scheduler_record_id, organization_id, reservation_type, status,
                   scheduled_start_time, scheduled_end_time,
                   planned_departure_airport, planned_destination_airport,
                   claimed_dispatch_uuid
              FROM ipca_flight_schedule_slots
        ";
        $params = array();
        if ($organizationId !== null) {
            $sql .= ' WHERE organization_id = ?';
            $params[] = $organizationId;
        }
        $sql .= ' ORDER BY id ASC LIMIT ' . max(1, min(5000, $limit));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($slots)) {
            return $summary;
        }

        foreach ($slots as $slot) {
            $summary['scanned_slots']++;
            $result = $this->backfillSlot($slot, $dryRun);
            $summary['actions'][] = $result;
            $summary['reservations_created'] += (int)($result['reservations_created'] ?? 0);
            $summary['legs_created'] += (int)($result['legs_created'] ?? 0);
            $summary['aliases_created'] += (int)($result['aliases_created'] ?? 0);
            $summary['quarantined'] += (int)($result['quarantined'] ?? 0);
            $summary['skipped'] += (int)($result['skipped'] ?? 0);
        }

        if ($this->tableExists('ipca_cvr_dispatches')) {
            $dispatchResult = $this->backfillDispatchAliases($organizationId, $dryRun, $limit);
            $summary['aliases_created'] += (int)$dispatchResult['aliases_created'];
            $summary['quarantined'] += (int)$dispatchResult['quarantined'];
            $summary['actions'] = array_merge($summary['actions'], $dispatchResult['actions']);
        }

        return $summary;
    }

    /**
     * @param array<string,mixed> $slot
     * @return array<string,mixed>
     */
    private function backfillSlot(array $slot, bool $dryRun): array
    {
        $slotId = (string)($slot['id'] ?? '');
        $orgId = (int)($slot['organization_id'] ?? 0);
        $schedulerRecordId = strtolower(trim((string)($slot['scheduler_record_id'] ?? '')));
        $reservationType = strtolower(trim((string)($slot['reservation_type'] ?? '')));
        $result = array(
            'type' => 'slot',
            'subject_pk' => $slotId,
            'scheduler_record_id' => $schedulerRecordId,
            'reservations_created' => 0,
            'legs_created' => 0,
            'aliases_created' => 0,
            'quarantined' => 0,
            'skipped' => 0,
            'notes' => array(),
        );

        if ($orgId < 1) {
            // Cannot write org-scoped quarantine without a real organization_id; skip canonical create.
            $result['quarantined'] = 1;
            $result['skipped'] = 1;
            $result['notes'][] = 'missing_organization_id';
            return $result;
        }

        if (!in_array($reservationType, CvrOperationalIdentityService::RESERVATION_TYPES, true)) {
            $this->maybeQuarantine($dryRun, $orgId, 'schedule_slot', 'ipca_flight_schedule_slots', $slotId, 'unsupported_reservation_type', array(
                'slot_id' => $slotId,
                'reservation_type' => $reservationType,
            ), $schedulerRecordId);
            $result['quarantined'] = 1;
            $result['notes'][] = 'unsupported_reservation_type';
            return $result;
        }

        $activityDomain = CvrOperationalIdentityService::defaultActivityDomainForReservationType($reservationType);
        if ($activityDomain === null) {
            $this->maybeQuarantine($dryRun, $orgId, 'schedule_slot', 'ipca_flight_schedule_slots', $slotId, 'activity_domain_requires_explicit_classification', array(
                'slot_id' => $slotId,
                'reservation_type' => $reservationType,
            ), $schedulerRecordId);
            $result['quarantined'] = 1;
            $result['notes'][] = 'activity_domain_requires_explicit_classification';
            return $result;
        }

        $timezone = $this->organizationTimezone($orgId);
        $adopt = CvrOperationalIdentityService::isValidUuid($schedulerRecordId);
        $reservationUuid = $adopt ? CvrOperationalIdentityService::normalizeUuid($schedulerRecordId, 'scheduler_record_id') : AuditEventService::uuid();

        if ($adopt) {
            $existingAlias = $this->identity->findAlias($orgId, 'schedule', 'scheduler_record_id', $reservationUuid, null);
            if ($existingAlias !== null && (string)$existingAlias['reservation_uuid'] !== $reservationUuid
                && (string)$existingAlias['target_type'] === 'reservation'
                && (string)$existingAlias['reservation_uuid'] !== '') {
                // Alias already points elsewhere — quarantine conflict.
                $this->maybeQuarantine($dryRun, $orgId, 'schedule_slot', 'ipca_flight_schedule_slots', $slotId, 'scheduler_record_id_alias_conflict', array(
                    'slot_id' => $slotId,
                    'scheduler_record_id' => $schedulerRecordId,
                    'existing_reservation_uuid' => $existingAlias['reservation_uuid'],
                ), $schedulerRecordId);
                $result['quarantined'] = 1;
                $result['notes'][] = 'scheduler_record_id_alias_conflict';
                return $result;
            }
        } elseif ($schedulerRecordId === '') {
            $this->maybeQuarantine($dryRun, $orgId, 'schedule_slot', 'ipca_flight_schedule_slots', $slotId, 'missing_scheduler_record_id', array(
                'slot_id' => $slotId,
            ), null);
            $result['quarantined'] = 1;
            $result['notes'][] = 'missing_scheduler_record_id';
            return $result;
        }

        $status = $this->mapScheduleStatusToReservationStatus((string)($slot['status'] ?? 'scheduled'));

        if ($dryRun) {
            $result['notes'][] = 'dry_run_would_create_reservation';
            $result['reservations_created'] = 1;
            $result['aliases_created'] += $schedulerRecordId !== '' ? 1 : 0;
            $result['aliases_created'] += 1; // schedule_slot_id
            if ($activityDomain === 'flight') {
                $routeOk = $this->hasSingleVerifiedRoute($slot);
                if ($routeOk) {
                    $result['legs_created'] = 1;
                    $result['notes'][] = 'dry_run_would_create_flight_leg';
                } else {
                    $result['quarantined'] = 1;
                    $result['notes'][] = 'dry_run_would_quarantine_ambiguous_route';
                }
            } else {
                $result['notes'][] = 'non_flight_domain_no_leg';
            }
            return $result;
        }

        $reservation = $this->identity->createReservation(array(
            'reservation_uuid' => $reservationUuid,
            'organization_id' => $orgId,
            'organization_timezone_iana' => $timezone,
            'reservation_type' => $reservationType,
            'activity_domain' => $activityDomain,
            'status' => $status,
            'source' => 'schedule_adopt',
            'adoption_source_system' => $adopt ? 'schedule' : null,
            'adoption_provenance' => array(
                'adopted_scheduler_uuid' => $adopt,
                'reason' => $adopt ? 'immutable_unique_uuid' : 'generated_new_uuid',
                'schedule_slot_id' => $slotId,
            ),
        ), false);
        $result['reservations_created'] = 1;
        $reservationUuid = (string)$reservation['reservation_uuid'];

        if ($schedulerRecordId !== '') {
            $this->identity->createAlias(array(
                'organization_id' => $orgId,
                'source_system' => 'schedule',
                'alias_type' => 'scheduler_record_id',
                'alias_value' => $schedulerRecordId,
                'alias_version' => null,
                'target_type' => 'reservation',
                'reservation_uuid' => $reservationUuid,
                'confidence_state' => 'DETERMINISTIC_BACKFILL',
                'linkage_method' => 'deterministic_backfill',
            ), false);
            $result['aliases_created']++;
        }

        $this->identity->createAlias(array(
            'organization_id' => $orgId,
            'source_system' => 'schedule',
            'alias_type' => 'schedule_slot_id',
            'alias_value' => $slotId,
            'alias_version' => null,
            'target_type' => 'reservation',
            'reservation_uuid' => $reservationUuid,
            'confidence_state' => 'DETERMINISTIC_BACKFILL',
            'linkage_method' => 'deterministic_backfill',
        ), false);
        $result['aliases_created']++;

        if ($activityDomain !== 'flight') {
            $result['notes'][] = 'non_flight_domain_no_leg';
            return $result;
        }

        if (!$this->hasSingleVerifiedRoute($slot)) {
            $this->maybeQuarantine($dryRun, $orgId, 'schedule_slot', 'ipca_flight_schedule_slots', $slotId, 'ambiguous_or_missing_route', array(
                'slot_id' => $slotId,
                'reservation_uuid' => $reservationUuid,
                'origin' => (string)($slot['planned_departure_airport'] ?? ''),
                'destination' => (string)($slot['planned_destination_airport'] ?? ''),
            ), $schedulerRecordId);
            $result['quarantined'] = 1;
            $result['notes'][] = 'ambiguous_or_missing_route';
            return $result;
        }

        try {
            $leg = $this->identity->createFlightLeg(array(
                'reservation_uuid' => $reservationUuid,
                'organization_id' => $orgId,
                'sequence_number' => 1,
                'origin_airport' => (string)($slot['planned_departure_airport'] ?? ''),
                'destination_airport' => (string)($slot['planned_destination_airport'] ?? ''),
                'planned_start_local' => $this->asLocalDateTime((string)($slot['scheduled_start_time'] ?? '')),
                'planned_end_local' => $this->asLocalDateTime((string)($slot['scheduled_end_time'] ?? '')),
                'organization_timezone_iana' => $timezone,
                'status' => 'scheduled',
                'source' => 'backfill_verified',
            ), false);
            $result['legs_created'] = 1;
            $result['notes'][] = 'leg_created';
            $result['leg_uuid'] = $leg['leg_uuid'];
        } catch (Throwable $e) {
            $this->maybeQuarantine($dryRun, $orgId, 'schedule_slot', 'ipca_flight_schedule_slots', $slotId, 'leg_time_conversion_failed', array(
                'slot_id' => $slotId,
                'reservation_uuid' => $reservationUuid,
                'error' => $e->getMessage(),
            ), $schedulerRecordId);
            $result['quarantined'] = 1;
            $result['notes'][] = 'leg_time_conversion_failed';
        }

        return $result;
    }

    /**
     * @return array{aliases_created:int,quarantined:int,actions:list<array<string,mixed>>}
     */
    private function backfillDispatchAliases(?int $organizationId, bool $dryRun, int $limit): array
    {
        $out = array('aliases_created' => 0, 'quarantined' => 0, 'actions' => array());
        $sql = "
            SELECT d.id, d.dispatch_uuid, d.workflow_flight_record_uuid, d.scheduler_record_id,
                   d.current_version, d.organization_id, d.device_id
              FROM ipca_cvr_dispatches d
             WHERE d.scheduler_record_id IS NOT NULL
               AND d.scheduler_record_id <> ''
        ";
        $params = array();
        if ($organizationId !== null) {
            $sql .= ' AND d.organization_id = ?';
            $params[] = $organizationId;
        }
        $sql .= ' ORDER BY d.id ASC LIMIT ' . max(1, min(5000, $limit));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return $out;
        }

        foreach ($rows as $row) {
            $orgId = (int)($row['organization_id'] ?? 0);
            $dispatchId = (string)($row['id'] ?? '');
            $dispatchUuid = strtolower(trim((string)($row['dispatch_uuid'] ?? '')));
            $schedulerRecordId = strtolower(trim((string)($row['scheduler_record_id'] ?? '')));
            $flightRecordUuid = strtolower(trim((string)($row['workflow_flight_record_uuid'] ?? '')));
            $version = (string)($row['current_version'] ?? '');

            if ($orgId < 1 || !CvrOperationalIdentityService::isValidUuid($dispatchUuid)) {
                $out['quarantined']++;
                $out['actions'][] = array('type' => 'dispatch', 'subject_pk' => $dispatchId, 'note' => 'invalid_org_or_dispatch_uuid');
                if ($orgId >= 1) {
                    $this->maybeQuarantine($dryRun, $orgId, 'dispatch', 'ipca_cvr_dispatches', $dispatchId, 'invalid_dispatch_identity', array(
                        'dispatch_id' => $dispatchId,
                        'dispatch_uuid' => $dispatchUuid,
                    ), $dispatchUuid);
                }
                continue;
            }

            $reservationAlias = $this->identity->findAlias($orgId, 'schedule', 'scheduler_record_id', $schedulerRecordId, null);
            if ($reservationAlias === null || (string)$reservationAlias['target_type'] !== 'reservation') {
                $this->maybeQuarantine($dryRun, $orgId, 'dispatch', 'ipca_cvr_dispatches', $dispatchId, 'missing_verified_reservation_link', array(
                    'dispatch_id' => $dispatchId,
                    'dispatch_uuid' => $dispatchUuid,
                    'scheduler_record_id' => $schedulerRecordId,
                ), $dispatchUuid);
                $out['quarantined']++;
                $out['actions'][] = array('type' => 'dispatch', 'subject_pk' => $dispatchId, 'note' => 'missing_verified_reservation_link');
                continue;
            }
            $reservationUuid = (string)$reservationAlias['reservation_uuid'];
            $reservation = $this->identity->findReservationByUuid($reservationUuid);
            if ($reservation === null || (string)$reservation['activity_domain'] !== 'flight') {
                $this->maybeQuarantine($dryRun, $orgId, 'dispatch', 'ipca_cvr_dispatches', $dispatchId, 'reservation_not_flight_domain', array(
                    'dispatch_id' => $dispatchId,
                    'reservation_uuid' => $reservationUuid,
                ), $dispatchUuid);
                $out['quarantined']++;
                continue;
            }
            $legs = $this->identity->listLegsForReservation($reservationUuid);
            if (count($legs) !== 1) {
                $this->maybeQuarantine($dryRun, $orgId, 'dispatch', 'ipca_cvr_dispatches', $dispatchId, 'flight_reservation_leg_cardinality', array(
                    'dispatch_id' => $dispatchId,
                    'reservation_uuid' => $reservationUuid,
                    'leg_count' => count($legs),
                ), $dispatchUuid);
                $out['quarantined']++;
                continue;
            }
            $legUuid = (string)$legs[0]['leg_uuid'];

            if ($dryRun) {
                $out['aliases_created'] += 2 + (CvrOperationalIdentityService::isValidUuid($flightRecordUuid) ? 1 : 0);
                $out['actions'][] = array(
                    'type' => 'dispatch',
                    'subject_pk' => $dispatchId,
                    'note' => 'dry_run_would_alias_dispatch_to_leg',
                    'leg_uuid' => $legUuid,
                );
                continue;
            }

            $this->identity->createAlias(array(
                'organization_id' => $orgId,
                'source_system' => 'cvr_unit',
                'alias_type' => 'dispatch_uuid',
                'alias_value' => $dispatchUuid,
                'alias_version' => null,
                'target_type' => 'leg',
                'leg_uuid' => $legUuid,
                'confidence_state' => 'DETERMINISTIC_BACKFILL',
                'linkage_method' => 'deterministic_backfill',
            ), false);
            $out['aliases_created']++;

            if ($version !== '') {
                $this->identity->createAlias(array(
                    'organization_id' => $orgId,
                    'source_system' => 'cvr_unit',
                    'alias_type' => 'dispatch_uuid_version',
                    'alias_value' => $dispatchUuid,
                    'alias_version' => $version,
                    'target_type' => 'leg',
                    'leg_uuid' => $legUuid,
                    'confidence_state' => 'DETERMINISTIC_BACKFILL',
                    'linkage_method' => 'deterministic_backfill',
                ), false);
                $out['aliases_created']++;
            }

            $this->identity->createAlias(array(
                'organization_id' => $orgId,
                'source_system' => 'cvr_server',
                'alias_type' => 'server_dispatch_id',
                'alias_value' => $dispatchId,
                'alias_version' => null,
                'target_type' => 'leg',
                'leg_uuid' => $legUuid,
                'confidence_state' => 'DETERMINISTIC_BACKFILL',
                'linkage_method' => 'deterministic_backfill',
            ), false);
            $out['aliases_created']++;

            if (CvrOperationalIdentityService::isValidUuid($flightRecordUuid)) {
                // Default FR → one leg only with verified 1:1 via this unique Dispatch link.
                $this->identity->createAlias(array(
                    'organization_id' => $orgId,
                    'source_system' => 'cvr_unit',
                    'alias_type' => 'workflow_flight_record_uuid',
                    'alias_value' => $flightRecordUuid,
                    'alias_version' => null,
                    'target_type' => 'leg',
                    'leg_uuid' => $legUuid,
                    'confidence_state' => 'DETERMINISTIC_BACKFILL',
                    'linkage_method' => 'deterministic_backfill',
                ), false);
                $out['aliases_created']++;
            }

            $out['actions'][] = array(
                'type' => 'dispatch',
                'subject_pk' => $dispatchId,
                'note' => 'aliased_dispatch_to_leg',
                'leg_uuid' => $legUuid,
            );
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $diagnostic
     */
    private function maybeQuarantine(
        bool $dryRun,
        int $organizationId,
        string $subjectType,
        string $subjectTable,
        string $subjectPk,
        string $reasonCode,
        array $diagnostic,
        ?string $naturalKey
    ): void {
        if ($dryRun || $organizationId < 1) {
            return;
        }
        $this->identity->quarantine(
            $organizationId,
            $subjectType,
            $subjectTable,
            $subjectPk,
            $reasonCode,
            $diagnostic,
            $naturalKey
        );
    }

    /**
     * @param array<string,mixed> $slot
     */
    private function hasSingleVerifiedRoute(array $slot): bool
    {
        $origin = strtoupper(trim((string)($slot['planned_departure_airport'] ?? '')));
        $destination = strtoupper(trim((string)($slot['planned_destination_airport'] ?? '')));
        if ($origin === '' || $destination === '') {
            return false;
        }
        // Single schedule row is itself the verified single planned route; no fuzzy multi-leg inference.
        return true;
    }

    private function mapScheduleStatusToReservationStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'cancelled' => 'cancelled',
            'completed' => 'completed',
            default => 'scheduled',
        };
    }

    private function organizationTimezone(int $organizationId): string
    {
        // Phase 2A: use system default timezone policy when available; else America/Los_Angeles
        // matching current schedule operational calendar behavior. organization_id is still required
        // on every canonical row; timezone is recorded explicitly.
        unset($organizationId);
        try {
            if (function_exists('cw_system_timezone')) {
                $tz = cw_system_timezone($this->pdo);
                if (is_string($tz) && in_array($tz, timezone_identifiers_list(), true)) {
                    return $tz;
                }
            }
        } catch (Throwable) {
        }
        return 'America/Los_Angeles';
    }

    private function asLocalDateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // Normalize to Y-m-d H:i:s[.v]
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $value) === 1) {
            return str_replace('T', ' ', $value);
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private function tableExists(string $table): bool
    {
        try {
            // SQLite and MySQL compatible probe.
            $stmt = $this->pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
            return $stmt !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
