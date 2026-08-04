<?php
declare(strict_types=1);

require_once __DIR__ . '/CvrOperationalIdentityService.php';

/**
 * Phase 2B canonical identity dual-read projections.
 * Read-only. Never creates or mutates canonical or legacy rows.
 * When operational_identity_dual_read_enabled is off, helpers return null projections
 * without querying identity tables.
 */
final class CvrOperationalIdentityReadService
{
    public const IDENTITY_SOURCE_CANONICAL_ALIAS = 'canonical_alias';
    public const IDENTITY_SOURCE_LEGACY_FALLBACK = 'legacy_fallback';
    public const IDENTITY_SOURCE_CANONICAL_CONFLICT = 'canonical_conflict';
    public const IDENTITY_SOURCE_CANONICAL_UNAVAILABLE = 'canonical_unavailable';

    private const ALLOWED_CONFIDENCE = array('VERIFIED', 'DETERMINISTIC_BACKFILL');

    private ?bool $dualReadEnabledCache = null;

    public function __construct(
        private PDO $pdo,
        private ?CvrOperationalIdentityService $identity = null
    ) {
        $this->identity = $identity ?? new CvrOperationalIdentityService($pdo);
    }

    public function isDualReadEnabled(): bool
    {
        if ($this->dualReadEnabledCache !== null) {
            return $this->dualReadEnabledCache;
        }
        try {
            $this->dualReadEnabledCache = $this->identity->isFlagEnabled(
                CvrOperationalIdentityService::FLAG_DUAL_READ
            );
        } catch (Throwable) {
            $this->dualReadEnabledCache = false;
        }
        return $this->dualReadEnabledCache;
    }

    /**
     * Schedule projection: resolve reservation_uuid from verified aliases only.
     *
     * @return array{
     *   reservation_uuid: ?string,
     *   leg_uuid: null,
     *   identity_source: ?string
     * }|null null when dual-read flag is off (caller must omit fields)
     */
    public function projectScheduleIdentity(
        int $organizationId,
        string $schedulerRecordId,
        ?string $scheduleSlotId = null
    ): ?array {
        if (!$this->isDualReadEnabled()) {
            return null;
        }
        try {
            if ($organizationId < 1) {
                return $this->projection(null, null, self::IDENTITY_SOURCE_LEGACY_FALLBACK);
            }
            $schedulerRecordId = trim($schedulerRecordId);
            $candidates = array();
            if ($schedulerRecordId !== '') {
                $alias = $this->findAcceptedAlias(
                    $organizationId,
                    'schedule',
                    'scheduler_record_id',
                    $schedulerRecordId,
                    null
                );
                if ($alias !== null) {
                    $candidates[] = $alias;
                }
            }
            if ($scheduleSlotId !== null && trim($scheduleSlotId) !== '') {
                $alias = $this->findAcceptedAlias(
                    $organizationId,
                    'schedule',
                    'schedule_slot_id',
                    trim($scheduleSlotId),
                    null
                );
                if ($alias !== null) {
                    $candidates[] = $alias;
                }
            }
            if ($candidates === array()) {
                return $this->projection(null, null, self::IDENTITY_SOURCE_LEGACY_FALLBACK);
            }
            $targets = array();
            foreach ($candidates as $alias) {
                if ((string)$alias['target_type'] !== 'reservation' || $alias['reservation_uuid'] === null) {
                    $this->logIntegrityDiagnostic('schedule_alias_invalid_target', array(
                        'organization_id' => $organizationId,
                        'scheduler_record_id' => $schedulerRecordId,
                        'alias_type' => $alias['alias_type'] ?? null,
                    ));
                    return $this->projection(null, null, self::IDENTITY_SOURCE_CANONICAL_CONFLICT);
                }
                $targets[(string)$alias['reservation_uuid']] = true;
            }
            if (count($targets) > 1) {
                $this->logIntegrityDiagnostic('schedule_alias_conflict', array(
                    'organization_id' => $organizationId,
                    'scheduler_record_id' => $schedulerRecordId,
                    'schedule_slot_id' => $scheduleSlotId,
                    'reservation_uuids' => array_keys($targets),
                ));
                return $this->projection(null, null, self::IDENTITY_SOURCE_CANONICAL_CONFLICT);
            }
            $reservationUuid = (string)array_key_first($targets);
            $reservation = $this->identity->findReservationByUuid($reservationUuid);
            if ($reservation === null || (int)($reservation['organization_id'] ?? 0) !== $organizationId) {
                $this->logIntegrityDiagnostic('schedule_reservation_missing_or_org_mismatch', array(
                    'organization_id' => $organizationId,
                    'scheduler_record_id' => $schedulerRecordId,
                    'reservation_uuid' => $reservationUuid,
                ));
                return $this->projection(null, null, self::IDENTITY_SOURCE_CANONICAL_CONFLICT);
            }

            $activityDomain = (string)($reservation['activity_domain'] ?? '');
            if ($activityDomain !== 'flight') {
                return $this->projection($reservationUuid, null, self::IDENTITY_SOURCE_CANONICAL_ALIAS);
            }

            $legs = $this->identity->listLegsForReservation($reservationUuid);
            $orgLegs = array();
            foreach ($legs as $leg) {
                if ((int)($leg['organization_id'] ?? 0) === $organizationId) {
                    $orgLegs[] = $leg;
                }
            }
            if (count($orgLegs) === 1) {
                return $this->projection(
                    $reservationUuid,
                    (string)$orgLegs[0]['leg_uuid'],
                    self::IDENTITY_SOURCE_CANONICAL_ALIAS
                );
            }

            $this->logIntegrityDiagnostic('schedule_flight_leg_count_unexpected', array(
                'organization_id' => $organizationId,
                'scheduler_record_id' => $schedulerRecordId,
                'reservation_uuid' => $reservationUuid,
                'leg_count' => count($orgLegs),
            ));
            return $this->projection(null, null, self::IDENTITY_SOURCE_CANONICAL_CONFLICT);
        } catch (Throwable $e) {
            $this->logIntegrityDiagnostic('schedule_dual_read_unavailable', array(
                'organization_id' => $organizationId,
                'scheduler_record_id' => $schedulerRecordId,
                'error_class' => $e::class,
            ));
            return $this->projection(null, null, self::IDENTITY_SOURCE_CANONICAL_UNAVAILABLE);
        }
    }

    /**
     * Dispatch / workflow FR projection: resolve leg_uuid from verified aliases only.
     *
     * @return array{
     *   reservation_uuid: ?string,
     *   leg_uuid: ?string,
     *   identity_source: ?string
     * }|null null when dual-read flag is off
     */
    public function projectLegIdentity(
        int $organizationId,
        ?string $dispatchUuid = null,
        ?string $dispatchVersion = null,
        ?string $workflowFlightRecordUuid = null
    ): ?array {
        if (!$this->isDualReadEnabled()) {
            return null;
        }
        try {
            if ($organizationId < 1) {
                return $this->projection(null, null, self::IDENTITY_SOURCE_LEGACY_FALLBACK);
            }
            $candidates = array();
            $dispatchUuid = $dispatchUuid !== null ? strtolower(trim($dispatchUuid)) : '';
            $workflowFlightRecordUuid = $workflowFlightRecordUuid !== null
                ? strtolower(trim($workflowFlightRecordUuid))
                : '';
            $dispatchVersion = $dispatchVersion !== null ? trim((string)$dispatchVersion) : '';

            if ($dispatchUuid !== '' && CvrOperationalIdentityService::isValidUuid($dispatchUuid)) {
                $alias = $this->findAcceptedAlias(
                    $organizationId,
                    'cvr_unit',
                    'dispatch_uuid',
                    $dispatchUuid,
                    null
                );
                if ($alias !== null) {
                    $candidates[] = $alias;
                }
                if ($dispatchVersion !== '') {
                    $versioned = $this->findAcceptedAlias(
                        $organizationId,
                        'cvr_unit',
                        'dispatch_uuid_version',
                        $dispatchUuid,
                        $dispatchVersion
                    );
                    if ($versioned !== null) {
                        $candidates[] = $versioned;
                    }
                }
            }
            if ($workflowFlightRecordUuid !== ''
                && CvrOperationalIdentityService::isValidUuid($workflowFlightRecordUuid)) {
                $alias = $this->findAcceptedAlias(
                    $organizationId,
                    'cvr_unit',
                    'workflow_flight_record_uuid',
                    $workflowFlightRecordUuid,
                    null
                );
                if ($alias !== null) {
                    $candidates[] = $alias;
                }
            }
            if ($candidates === array()) {
                return $this->projection(null, null, self::IDENTITY_SOURCE_LEGACY_FALLBACK);
            }

            $legTargets = array();
            foreach ($candidates as $alias) {
                if ((string)$alias['target_type'] !== 'leg' || $alias['leg_uuid'] === null) {
                    $this->logIntegrityDiagnostic('leg_alias_invalid_target', array(
                        'organization_id' => $organizationId,
                        'dispatch_uuid' => $dispatchUuid,
                        'workflow_flight_record_uuid' => $workflowFlightRecordUuid,
                        'alias_type' => $alias['alias_type'] ?? null,
                    ));
                    return $this->projection(null, null, self::IDENTITY_SOURCE_CANONICAL_CONFLICT);
                }
                $legTargets[(string)$alias['leg_uuid']] = true;
            }
            if (count($legTargets) > 1) {
                $this->logIntegrityDiagnostic('leg_alias_conflict', array(
                    'organization_id' => $organizationId,
                    'dispatch_uuid' => $dispatchUuid,
                    'dispatch_version' => $dispatchVersion !== '' ? $dispatchVersion : null,
                    'workflow_flight_record_uuid' => $workflowFlightRecordUuid !== '' ? $workflowFlightRecordUuid : null,
                    'leg_uuids' => array_keys($legTargets),
                ));
                return $this->projection(null, null, self::IDENTITY_SOURCE_CANONICAL_CONFLICT);
            }

            $legUuid = (string)array_key_first($legTargets);
            $leg = $this->identity->findLegByUuid($legUuid);
            if ($leg === null || (int)$leg['organization_id'] !== $organizationId) {
                // Alias pointed at missing/wrong-org leg: treat as unavailable, never cross-org.
                $this->logIntegrityDiagnostic('leg_alias_target_unavailable', array(
                    'organization_id' => $organizationId,
                    'leg_uuid' => $legUuid,
                ));
                return $this->projection(null, null, self::IDENTITY_SOURCE_CANONICAL_UNAVAILABLE);
            }
            return $this->projection(
                (string)$leg['reservation_uuid'],
                $legUuid,
                self::IDENTITY_SOURCE_CANONICAL_ALIAS
            );
        } catch (Throwable $e) {
            $this->logIntegrityDiagnostic('leg_dual_read_unavailable', array(
                'organization_id' => $organizationId,
                'dispatch_uuid' => $dispatchUuid,
                'error_class' => $e::class,
            ));
            return $this->projection(null, null, self::IDENTITY_SOURCE_CANONICAL_UNAVAILABLE);
        }
    }

    /**
     * Merge dual-read fields into a legacy payload. Flag off → unchanged payload.
     *
     * @param array<string,mixed> $payload
     * @param array{reservation_uuid:?string,leg_uuid:?string,identity_source:?string}|null $projection
     * @return array<string,mixed>
     */
    public function mergeProjection(array $payload, ?array $projection): array
    {
        if ($projection === null) {
            return $payload;
        }
        $payload['reservation_uuid'] = $projection['reservation_uuid'];
        $payload['leg_uuid'] = $projection['leg_uuid'];
        $payload['identity_source'] = $projection['identity_source'];
        return $payload;
    }

    /**
     * @deprecated Prefer projectScheduleIdentity / projectLegIdentity for Phase 2B.
     * @return array<string,mixed>
     */
    public function resolveAlias(
        int $organizationId,
        string $sourceSystem,
        string $aliasType,
        string $aliasValue,
        ?string $aliasVersion = null
    ): array {
        $legacy = array(
            'organization_id' => $organizationId,
            'source_system' => $sourceSystem,
            'alias_type' => $aliasType,
            'alias_value' => $aliasValue,
            'alias_version' => $aliasVersion,
        );
        if (!$this->isDualReadEnabled()) {
            return array(
                'dual_read_enabled' => false,
                'resolved' => false,
                'target_type' => null,
                'reservation_uuid' => null,
                'leg_uuid' => null,
                'alias' => null,
                'legacy' => $legacy,
                'identity_source' => null,
            );
        }
        try {
            $alias = $this->findAcceptedAlias(
                $organizationId,
                $sourceSystem,
                $aliasType,
                $aliasValue,
                $aliasVersion
            );
            if ($alias === null) {
                return array(
                    'dual_read_enabled' => true,
                    'resolved' => false,
                    'target_type' => null,
                    'reservation_uuid' => null,
                    'leg_uuid' => null,
                    'alias' => null,
                    'legacy' => $legacy,
                    'identity_source' => self::IDENTITY_SOURCE_LEGACY_FALLBACK,
                );
            }
            return array(
                'dual_read_enabled' => true,
                'resolved' => true,
                'target_type' => (string)$alias['target_type'],
                'reservation_uuid' => $alias['reservation_uuid'] !== null ? (string)$alias['reservation_uuid'] : null,
                'leg_uuid' => $alias['leg_uuid'] !== null ? (string)$alias['leg_uuid'] : null,
                'alias' => $alias,
                'legacy' => $legacy,
                'identity_source' => self::IDENTITY_SOURCE_CANONICAL_ALIAS,
            );
        } catch (Throwable) {
            return array(
                'dual_read_enabled' => true,
                'resolved' => false,
                'target_type' => null,
                'reservation_uuid' => null,
                'leg_uuid' => null,
                'alias' => null,
                'legacy' => $legacy,
                'identity_source' => self::IDENTITY_SOURCE_CANONICAL_UNAVAILABLE,
            );
        }
    }

    /**
     * @return array{resolved:bool,leg_uuid:?string,reservation_uuid:?string,legacy_dispatch_uuid:string,identity_source:?string}
     */
    public function preferLegForDispatchUuid(int $organizationId, string $dispatchUuid, ?string $dispatchVersion = null): array
    {
        $dispatchUuid = CvrOperationalIdentityService::isValidUuid($dispatchUuid)
            ? CvrOperationalIdentityService::normalizeUuid($dispatchUuid, 'dispatch_uuid')
            : strtolower(trim($dispatchUuid));
        $projection = $this->projectLegIdentity($organizationId, $dispatchUuid, $dispatchVersion, null);
        if ($projection === null) {
            return array(
                'resolved' => false,
                'leg_uuid' => null,
                'reservation_uuid' => null,
                'legacy_dispatch_uuid' => $dispatchUuid,
                'identity_source' => null,
            );
        }
        return array(
            'resolved' => $projection['identity_source'] === self::IDENTITY_SOURCE_CANONICAL_ALIAS,
            'leg_uuid' => $projection['leg_uuid'],
            'reservation_uuid' => $projection['reservation_uuid'],
            'legacy_dispatch_uuid' => $dispatchUuid,
            'identity_source' => $projection['identity_source'],
        );
    }

    /**
     * @return array{resolved:bool,reservation_uuid:?string,legacy_scheduler_record_id:string,identity_source:?string}
     */
    public function preferReservationForSchedulerRecordId(int $organizationId, string $schedulerRecordId): array
    {
        $schedulerRecordId = trim($schedulerRecordId);
        $projection = $this->projectScheduleIdentity($organizationId, $schedulerRecordId, null);
        if ($projection === null) {
            return array(
                'resolved' => false,
                'reservation_uuid' => null,
                'legacy_scheduler_record_id' => $schedulerRecordId,
                'identity_source' => null,
            );
        }
        return array(
            'resolved' => $projection['identity_source'] === self::IDENTITY_SOURCE_CANONICAL_ALIAS,
            'reservation_uuid' => $projection['reservation_uuid'],
            'legacy_scheduler_record_id' => $schedulerRecordId,
            'identity_source' => $projection['identity_source'],
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findAcceptedAlias(
        int $organizationId,
        string $sourceSystem,
        string $aliasType,
        string $aliasValue,
        ?string $aliasVersion
    ): ?array {
        $alias = $this->identity->findAlias(
            $organizationId,
            $sourceSystem,
            $aliasType,
            $aliasValue,
            $aliasVersion
        );
        if ($alias === null) {
            return null;
        }
        $confidence = strtoupper(trim((string)($alias['confidence_state'] ?? '')));
        if (!in_array($confidence, self::ALLOWED_CONFIDENCE, true)) {
            return null;
        }
        return $alias;
    }

    /**
     * @return array{reservation_uuid:?string,leg_uuid:?string,identity_source:string}
     */
    private function projection(?string $reservationUuid, ?string $legUuid, string $source): array
    {
        return array(
            'reservation_uuid' => $reservationUuid,
            'leg_uuid' => $legUuid,
            'identity_source' => $source,
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    private function logIntegrityDiagnostic(string $code, array $context): void
    {
        // Never include credentials, audio, CSV, or transcript bodies.
        $safe = array(
            'code' => $code,
            'context' => $context,
        );
        error_log('CVR operational identity dual-read diagnostic: ' . json_encode(
            $safe,
            JSON_UNESCAPED_SLASHES
        ));
    }
}
