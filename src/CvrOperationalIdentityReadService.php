<?php
declare(strict_types=1);

require_once __DIR__ . '/CvrOperationalIdentityService.php';

/**
 * Phase 2A dual-read helper.
 * Prefer verified canonical identity when operational_identity_dual_read_enabled is on;
 * otherwise return legacy identity unchanged. Never mutates source rows.
 */
final class CvrOperationalIdentityReadService
{
    public function __construct(
        private PDO $pdo,
        private ?CvrOperationalIdentityService $identity = null
    ) {
        $this->identity = $identity ?? new CvrOperationalIdentityService($pdo);
    }

    /**
     * @return array{
     *   dual_read_enabled: bool,
     *   resolved: bool,
     *   target_type: ?string,
     *   reservation_uuid: ?string,
     *   leg_uuid: ?string,
     *   alias: ?array<string,mixed>,
     *   legacy: array<string,mixed>
     * }
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
        if (!$this->identity->isFlagEnabled(CvrOperationalIdentityService::FLAG_DUAL_READ)) {
            return array(
                'dual_read_enabled' => false,
                'resolved' => false,
                'target_type' => null,
                'reservation_uuid' => null,
                'leg_uuid' => null,
                'alias' => null,
                'legacy' => $legacy,
            );
        }
        $alias = $this->identity->findAlias($organizationId, $sourceSystem, $aliasType, $aliasValue, $aliasVersion);
        if ($alias === null) {
            return array(
                'dual_read_enabled' => true,
                'resolved' => false,
                'target_type' => null,
                'reservation_uuid' => null,
                'leg_uuid' => null,
                'alias' => null,
                'legacy' => $legacy,
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
        );
    }

    /**
     * Prefer canonical leg for a Dispatch UUID when dual-read is enabled and aliased.
     *
     * @return array{resolved:bool,leg_uuid:?string,reservation_uuid:?string,legacy_dispatch_uuid:string}
     */
    public function preferLegForDispatchUuid(int $organizationId, string $dispatchUuid, ?string $dispatchVersion = null): array
    {
        $dispatchUuid = CvrOperationalIdentityService::isValidUuid($dispatchUuid)
            ? CvrOperationalIdentityService::normalizeUuid($dispatchUuid, 'dispatch_uuid')
            : strtolower(trim($dispatchUuid));
        $primary = $this->resolveAlias($organizationId, 'cvr_unit', 'dispatch_uuid', $dispatchUuid, null);
        if ($primary['resolved'] && $primary['leg_uuid'] !== null) {
            $leg = $this->identity->findLegByUuid($primary['leg_uuid']);
            return array(
                'resolved' => true,
                'leg_uuid' => $primary['leg_uuid'],
                'reservation_uuid' => is_array($leg) ? (string)$leg['reservation_uuid'] : null,
                'legacy_dispatch_uuid' => $dispatchUuid,
            );
        }
        if ($dispatchVersion !== null && trim($dispatchVersion) !== '') {
            $versioned = $this->resolveAlias(
                $organizationId,
                'cvr_unit',
                'dispatch_uuid_version',
                $dispatchUuid,
                trim($dispatchVersion)
            );
            if ($versioned['resolved'] && $versioned['leg_uuid'] !== null) {
                $leg = $this->identity->findLegByUuid($versioned['leg_uuid']);
                return array(
                    'resolved' => true,
                    'leg_uuid' => $versioned['leg_uuid'],
                    'reservation_uuid' => is_array($leg) ? (string)$leg['reservation_uuid'] : null,
                    'legacy_dispatch_uuid' => $dispatchUuid,
                );
            }
        }
        return array(
            'resolved' => false,
            'leg_uuid' => null,
            'reservation_uuid' => null,
            'legacy_dispatch_uuid' => $dispatchUuid,
        );
    }

    /**
     * Prefer canonical reservation for a scheduler_record_id when dual-read is enabled.
     *
     * @return array{resolved:bool,reservation_uuid:?string,legacy_scheduler_record_id:string}
     */
    public function preferReservationForSchedulerRecordId(int $organizationId, string $schedulerRecordId): array
    {
        $schedulerRecordId = trim($schedulerRecordId);
        $resolved = $this->resolveAlias($organizationId, 'schedule', 'scheduler_record_id', $schedulerRecordId, null);
        return array(
            'resolved' => $resolved['resolved'],
            'reservation_uuid' => $resolved['reservation_uuid'],
            'legacy_scheduler_record_id' => $schedulerRecordId,
        );
    }
}
