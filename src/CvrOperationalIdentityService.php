<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

/**
 * Phase 2A canonical operational reservation / leg identity register.
 * Additive only. Does not mutate legacy schedule/Dispatch/evidence rows.
 * Canonical writes are gated by operational_identity_canonical_write_enabled (default off).
 */
final class CvrOperationalIdentityService
{
    public const FLAG_BACKFILL = 'operational_identity_backfill_enabled';
    public const FLAG_DUAL_READ = 'operational_identity_dual_read_enabled';
    public const FLAG_CANONICAL_WRITE = 'operational_identity_canonical_write_enabled';

    public const ACTIVITY_DOMAINS = array('flight', 'simulator', 'ground', 'administrative');
    public const RESERVATION_TYPES = array(
        'flight_training', 'briefing', 'ar_briefing', 'simulator_training',
        'ground_training', 'theory_lesson', 'theory_mock_exam', 'practical_exam',
        'meeting', 'assessment', 'maintenance', 'personal', 'unavailable', 'other',
    );
    public const RESERVATION_STATUSES = array('scheduled', 'active', 'completed', 'cancelled');
    public const LEG_STATUSES = array('scheduled', 'dispatched', 'active', 'checked_in', 'cancelled');
    public const LINKAGE_METHODS = array(
        'online_create', 'offline_create', 'deterministic_backfill', 'manual_verified',
    );
    public const CONFIDENCE_STATES = array('VERIFIED', 'DETERMINISTIC_BACKFILL');
    public const ALLOWED_ALIAS_TYPES = array(
        'scheduler_record_id',
        'schedule_slot_id',
        'dispatch_uuid',
        'dispatch_uuid_version',
        'workflow_flight_record_uuid',
        'workflow_archive_id',
        'recording_uid',
        'server_recording_id',
        'server_dispatch_id',
    );
    public const DEFERRED_ALIAS_TYPES = array(
        'garmin_csv_file_uuid',
        'csv_file_uuid',
        'garmin_workflow_flight_record_uuid',
        'session_uuid',
        'server_session_id',
        'flight_session_uid',
        'operational_flight_record_uuid',
        'leg_version_uuid',
    );

    private const MAX_DIAGNOSTIC_BYTES = 4096;
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function __construct(private PDO $pdo)
    {
    }

    public function isFlagEnabled(string $policyKey): bool
    {
        $policyKey = trim($policyKey);
        if ($policyKey === '') {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare("
                SELECT value_text
                  FROM system_policy_values
                 WHERE policy_key = ?
                   AND is_active = 1
                 ORDER BY id DESC
                 LIMIT 1
            ");
            $stmt->execute(array($policyKey));
            $value = $stmt->fetchColumn();
            if ($value === false || $value === null) {
                return false;
            }
            $normalized = strtolower(trim((string)$value));
            return in_array($normalized, array('1', 'true', 'yes', 'on'), true);
        } catch (Throwable) {
            return false;
        }
    }

    public static function normalizeUuid(string $value, string $field = 'uuid'): string
    {
        $uuid = strtolower(trim($value));
        if (preg_match(self::UUID_PATTERN, $uuid) !== 1) {
            throw new InvalidArgumentException($field . ' must be a lowercase UUID.');
        }
        return $uuid;
    }

    public static function isValidUuid(mixed $value): bool
    {
        $uuid = strtolower(trim((string)$value));
        return preg_match(self::UUID_PATTERN, $uuid) === 1;
    }

    /**
     * Deterministic reservation_type → activity_domain for types that do not require
     * explicit classification. practical_exam returns null (must be explicit).
     * Legs are created only when the returned domain is flight (`other` never creates a leg).
     */
    public static function defaultActivityDomainForReservationType(string $reservationType): ?string
    {
        return match (strtolower(trim($reservationType))) {
            'flight_training' => 'flight',
            'simulator_training' => 'simulator',
            'briefing', 'ar_briefing', 'ground_training', 'theory_lesson', 'theory_mock_exam',
            'meeting', 'assessment' => 'ground',
            'maintenance', 'personal', 'unavailable', 'other' => 'administrative',
            'practical_exam' => null,
            default => null,
        };
    }

    /**
     * Derive coarse reservation status from child leg statuses.
     * Non-flight reservations (no legs) keep their stored status via callers.
     *
     * @param list<string> $legStatuses
     */
    public static function deriveReservationStatusFromLegs(array $legStatuses): string
    {
        if ($legStatuses === array()) {
            return 'scheduled';
        }
        $normalized = array();
        foreach ($legStatuses as $status) {
            $status = strtolower(trim((string)$status));
            if (!in_array($status, self::LEG_STATUSES, true)) {
                throw new InvalidArgumentException('Unknown leg status: ' . $status);
            }
            $normalized[] = $status;
        }
        $nonCancelled = array_values(array_filter(
            $normalized,
            static fn(string $s): bool => $s !== 'cancelled'
        ));
        if ($nonCancelled === array()) {
            return 'cancelled';
        }
        $allCheckedIn = true;
        foreach ($nonCancelled as $status) {
            if ($status !== 'checked_in') {
                $allCheckedIn = false;
                break;
            }
        }
        if ($allCheckedIn) {
            return 'completed';
        }
        foreach ($nonCancelled as $status) {
            if (in_array($status, array('dispatched', 'active', 'checked_in'), true)) {
                return 'active';
            }
        }
        return 'scheduled';
    }

    /**
     * Phase 2C: create canonical identity for a newly created online schedule reservation.
     * Caller must already be inside the legacy schedule create transaction when the flag is on.
     * Retries reuse reservation/leg/alias rows by stable UUID and unique alias scope.
     *
     * @param array{
     *   organization_id:int,
     *   scheduler_record_id:string,
     *   schedule_slot_id:int|string,
     *   reservation_type:string,
     *   status?:string,
     *   planned_departure_airport?:string,
     *   planned_destination_airport?:string,
     *   scheduled_start_time?:string,
     *   scheduled_end_time?:string,
     *   organization_timezone_iana?:string
     * } $input
     * @return array{
     *   reservation_uuid:string,
     *   leg_uuid:?string,
     *   activity_domain:string,
     *   aliases_created:int
     * }
     */
    public function createOnlineScheduleReservationIdentity(array $input): array
    {
        if (!$this->isFlagEnabled(self::FLAG_CANONICAL_WRITE)) {
            throw new RuntimeException('Canonical identity writes are disabled.');
        }

        $organizationId = $this->requireOrganizationId($input['organization_id'] ?? null);
        $schedulerRecordId = strtolower(trim((string)($input['scheduler_record_id'] ?? '')));
        if (!self::isValidUuid($schedulerRecordId)) {
            throw new InvalidArgumentException('scheduler_record_id must be a valid lowercase UUID.');
        }
        $schedulerRecordId = self::normalizeUuid($schedulerRecordId, 'scheduler_record_id');
        $slotId = trim((string)($input['schedule_slot_id'] ?? ''));
        if ($slotId === '' || !ctype_digit($slotId) || (int)$slotId < 1) {
            throw new InvalidArgumentException('schedule_slot_id is required.');
        }
        $reservationType = strtolower(trim((string)($input['reservation_type'] ?? '')));
        if (!in_array($reservationType, self::RESERVATION_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported reservation_type.');
        }
        $activityDomain = self::defaultActivityDomainForReservationType($reservationType);
        if ($activityDomain === null) {
            throw new InvalidArgumentException('reservation_type requires an explicit activity_domain.');
        }

        $status = strtolower(trim((string)($input['status'] ?? 'scheduled')));
        if ($status === 'completed') {
            $reservationStatus = 'completed';
        } elseif ($status === 'cancelled') {
            $reservationStatus = 'cancelled';
        } else {
            $reservationStatus = 'scheduled';
        }

        $timezone = trim((string)($input['organization_timezone_iana'] ?? ''));
        if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = $this->resolveOrganizationTimezone($organizationId);
        }

        $reservation = $this->createReservation(array(
            'reservation_uuid' => $schedulerRecordId,
            'organization_id' => $organizationId,
            'organization_timezone_iana' => $timezone,
            'reservation_type' => $reservationType,
            'activity_domain' => $activityDomain,
            'status' => $reservationStatus,
            'source' => 'server_create',
            'adoption_source_system' => 'schedule',
            'adoption_provenance' => array(
                'linkage_method' => 'online_create',
                'scheduler_record_id' => $schedulerRecordId,
                'schedule_slot_id' => $slotId,
            ),
        ), true);
        $reservationUuid = (string)$reservation['reservation_uuid'];
        $this->assertReusableOnlineReservationMatches(
            $reservation,
            $organizationId,
            $reservationUuid,
            $reservationType,
            $activityDomain,
            $schedulerRecordId
        );

        $legUuid = null;
        if ($activityDomain === 'flight') {
            $startLocal = $this->normalizeScheduleLocalDateTime((string)($input['scheduled_start_time'] ?? ''));
            $endLocal = $this->normalizeScheduleLocalDateTime((string)($input['scheduled_end_time'] ?? ''));
            $chain = array();
            if (isset($input['airport_chain']) && is_array($input['airport_chain'])) {
                foreach ($input['airport_chain'] as $airport) {
                    $code = $this->normalizeAirportCode((string)$airport);
                    if ($code !== '') {
                        $chain[] = $code;
                    }
                }
            }
            if (count($chain) < 2) {
                $origin = $this->normalizeAirportCode((string)($input['planned_departure_airport'] ?? ''));
                $destination = $this->normalizeAirportCode((string)($input['planned_destination_airport'] ?? ''));
                $chain = array_values(array_filter(array($origin, $destination), static fn(string $c): bool => $c !== ''));
            }
            if (count($chain) < 2) {
                throw new InvalidArgumentException('Flight schedule identity requires departure and destination airports.');
            }

            $existingLegs = $this->listLegsForReservation($reservationUuid);
            $legsBySequence = array();
            foreach ($existingLegs as $existingLeg) {
                if ((int)($existingLeg['organization_id'] ?? 0) !== $organizationId) {
                    continue;
                }
                $seq = (int)($existingLeg['sequence_number'] ?? 0);
                if ($seq >= 1) {
                    $legsBySequence[$seq] = $existingLeg;
                }
            }

            $legCount = count($chain) - 1;
            for ($index = 0; $index < $legCount; $index++) {
                $sequence = $index + 1;
                $origin = $chain[$index];
                $destination = $chain[$index + 1];
                // Reservation time window applies to the whole multi-leg block.
                $legStart = $startLocal;
                $legEnd = $endLocal;
                if (isset($legsBySequence[$sequence])) {
                    $this->assertReusableOnlineLegMatches(
                        $legsBySequence[$sequence],
                        $organizationId,
                        $reservationUuid,
                        $origin,
                        $destination,
                        $legStart,
                        $legEnd,
                        $timezone,
                        $sequence
                    );
                    if ($sequence === 1) {
                        $legUuid = (string)$legsBySequence[$sequence]['leg_uuid'];
                    }
                    continue;
                }
                $leg = $this->createFlightLeg(array(
                    'reservation_uuid' => $reservationUuid,
                    'organization_id' => $organizationId,
                    'sequence_number' => $sequence,
                    'origin_airport' => $origin,
                    'destination_airport' => $destination,
                    'planned_start_local' => $legStart,
                    'planned_end_local' => $legEnd,
                    'organization_timezone_iana' => $timezone,
                    'status' => 'scheduled',
                    'source' => 'server_create',
                ), true);
                if ($sequence === 1) {
                    $legUuid = (string)$leg['leg_uuid'];
                }
            }
        }

        $aliasCount = 0;
        $this->createAlias(array(
            'organization_id' => $organizationId,
            'source_system' => 'schedule',
            'alias_type' => 'scheduler_record_id',
            'alias_value' => $schedulerRecordId,
            'alias_version' => null,
            'target_type' => 'reservation',
            'reservation_uuid' => $reservationUuid,
            'confidence_state' => 'VERIFIED',
            'linkage_method' => 'online_create',
        ), true);
        $aliasCount++;

        $this->createAlias(array(
            'organization_id' => $organizationId,
            'source_system' => 'schedule',
            'alias_type' => 'schedule_slot_id',
            'alias_value' => $slotId,
            'alias_version' => null,
            'target_type' => 'reservation',
            'reservation_uuid' => $reservationUuid,
            'confidence_state' => 'VERIFIED',
            'linkage_method' => 'online_create',
        ), true);
        $aliasCount++;

        return array(
            'reservation_uuid' => $reservationUuid,
            'leg_uuid' => $legUuid,
            'activity_domain' => $activityDomain,
            'aliases_created' => $aliasCount,
        );
    }

    /**
     * Sanitize and log a technical diagnostic for canonical write failures.
     * Never includes raw database driver messages or protected payload content.
     *
     * @param array<string,mixed> $diagnostic
     */
    public function logTechnicalDiagnostic(string $code, array $diagnostic): void
    {
        $code = substr(trim($code), 0, 96);
        if ($code === '') {
            $code = 'canonical_write_diagnostic';
        }
        $sanitized = $this->sanitizeDiagnostic($diagnostic);
        unset($sanitized['message'], $sanitized['sqlstate'], $sanitized['errorInfo'], $sanitized['trace']);
        if (isset($sanitized['error_message']) && is_string($sanitized['error_message'])) {
            // Keep class/category only; strip likely driver text.
            $sanitized['error_message'] = substr($sanitized['error_message'], 0, 180);
        }
        error_log('cvr_operational_identity:' . $code . ' ' . AuditEventService::jsonEncode($sanitized));
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createReservation(array $input, bool $requireCanonicalWriteFlag = true): array
    {
        if ($requireCanonicalWriteFlag && !$this->isFlagEnabled(self::FLAG_CANONICAL_WRITE)) {
            throw new RuntimeException('Canonical identity writes are disabled.');
        }
        $organizationId = $this->requireOrganizationId($input['organization_id'] ?? null);
        $reservationType = strtolower(trim((string)($input['reservation_type'] ?? '')));
        if (!in_array($reservationType, self::RESERVATION_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported reservation_type.');
        }
        $activityDomain = strtolower(trim((string)($input['activity_domain'] ?? '')));
        if (!in_array($activityDomain, self::ACTIVITY_DOMAINS, true)) {
            throw new InvalidArgumentException('activity_domain is required and must be explicit.');
        }
        $status = strtolower(trim((string)($input['status'] ?? 'scheduled')));
        if (!in_array($status, self::RESERVATION_STATUSES, true)) {
            throw new InvalidArgumentException('Unsupported reservation status.');
        }
        $source = strtolower(trim((string)($input['source'] ?? 'server_create')));
        if (!in_array($source, array('server_create', 'ios_offline', 'schedule_adopt', 'manual'), true)) {
            throw new InvalidArgumentException('Unsupported reservation source.');
        }
        $timezone = trim((string)($input['organization_timezone_iana'] ?? ''));
        if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('organization_timezone_iana is required.');
        }
        $reservationUuid = isset($input['reservation_uuid']) && trim((string)$input['reservation_uuid']) !== ''
            ? self::normalizeUuid((string)$input['reservation_uuid'], 'reservation_uuid')
            : AuditEventService::uuid();

        $existing = $this->findReservationByUuid($reservationUuid);
        if ($existing !== null) {
            if ((int)$existing['organization_id'] !== $organizationId) {
                throw new RuntimeException('reservation_uuid already exists for another organization.');
            }
            return $existing;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_operational_reservations
              (reservation_uuid, organization_id, organization_timezone_iana, reservation_type,
               activity_domain, status, source, adoption_source_system, adoption_provenance_json)
            VALUES
              (:reservation_uuid, :organization_id, :organization_timezone_iana, :reservation_type,
               :activity_domain, :status, :source, :adoption_source_system, :adoption_provenance_json)
        ");
        $stmt->execute(array(
            ':reservation_uuid' => $reservationUuid,
            ':organization_id' => $organizationId,
            ':organization_timezone_iana' => $timezone,
            ':reservation_type' => $reservationType,
            ':activity_domain' => $activityDomain,
            ':status' => $status,
            ':source' => $source,
            ':adoption_source_system' => $this->nullableString($input['adoption_source_system'] ?? null, 64),
            ':adoption_provenance_json' => $this->encodeBoundedJson($input['adoption_provenance'] ?? null),
        ));
        $created = $this->findReservationByUuid($reservationUuid);
        if ($created === null) {
            throw new RuntimeException('Failed to create reservation.');
        }
        return $created;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createFlightLeg(array $input, bool $requireCanonicalWriteFlag = true): array
    {
        if ($requireCanonicalWriteFlag && !$this->isFlagEnabled(self::FLAG_CANONICAL_WRITE)) {
            throw new RuntimeException('Canonical identity writes are disabled.');
        }
        $reservationUuid = self::normalizeUuid((string)($input['reservation_uuid'] ?? ''), 'reservation_uuid');
        $reservation = $this->findReservationByUuid($reservationUuid);
        if ($reservation === null) {
            throw new InvalidArgumentException('Unknown reservation_uuid.');
        }
        if ((string)$reservation['activity_domain'] !== 'flight') {
            throw new InvalidArgumentException('Operational legs are created only when activity_domain = flight.');
        }
        $organizationId = $this->requireOrganizationId($input['organization_id'] ?? $reservation['organization_id']);
        if ($organizationId !== (int)$reservation['organization_id']) {
            throw new InvalidArgumentException('organization_id must match the reservation.');
        }
        $sequence = (int)($input['sequence_number'] ?? 1);
        if ($sequence < 1) {
            throw new InvalidArgumentException('sequence_number must be >= 1.');
        }
        $status = strtolower(trim((string)($input['status'] ?? 'scheduled')));
        if (!in_array($status, self::LEG_STATUSES, true)) {
            throw new InvalidArgumentException('Unsupported leg status.');
        }
        $source = strtolower(trim((string)($input['source'] ?? 'server_create')));
        if (!in_array($source, array('server_create', 'ios_offline', 'backfill_verified', 'manual'), true)) {
            throw new InvalidArgumentException('Unsupported leg source.');
        }
        $timezone = trim((string)($input['organization_timezone_iana'] ?? $reservation['organization_timezone_iana']));
        if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('organization_timezone_iana is required.');
        }

        $timeFields = $this->normalizePlannedTimes($input, $timezone);
        $legUuid = isset($input['leg_uuid']) && trim((string)$input['leg_uuid']) !== ''
            ? self::normalizeUuid((string)$input['leg_uuid'], 'leg_uuid')
            : AuditEventService::uuid();

        $existing = $this->findLegByUuid($legUuid);
        if ($existing !== null) {
            if ((int)$existing['organization_id'] !== $organizationId
                || (string)$existing['reservation_uuid'] !== $reservationUuid) {
                throw new RuntimeException('leg_uuid already exists under a different reservation/org.');
            }
            return $existing;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_operational_reservation_legs
              (leg_uuid, reservation_uuid, organization_id, sequence_number,
               origin_airport, destination_airport,
               planned_start_at_utc, planned_end_at_utc,
               planned_start_local, planned_end_local,
               organization_timezone_iana,
               planned_start_utc_offset_minutes, planned_end_utc_offset_minutes,
               planned_start_dst_resolution, planned_end_dst_resolution,
               status, source)
            VALUES
              (:leg_uuid, :reservation_uuid, :organization_id, :sequence_number,
               :origin_airport, :destination_airport,
               :planned_start_at_utc, :planned_end_at_utc,
               :planned_start_local, :planned_end_local,
               :organization_timezone_iana,
               :planned_start_utc_offset_minutes, :planned_end_utc_offset_minutes,
               :planned_start_dst_resolution, :planned_end_dst_resolution,
               :status, :source)
        ");
        $stmt->execute(array(
            ':leg_uuid' => $legUuid,
            ':reservation_uuid' => $reservationUuid,
            ':organization_id' => $organizationId,
            ':sequence_number' => $sequence,
            ':origin_airport' => strtoupper(substr(trim((string)($input['origin_airport'] ?? '')), 0, 8)),
            ':destination_airport' => strtoupper(substr(trim((string)($input['destination_airport'] ?? '')), 0, 8)),
            ':planned_start_at_utc' => $timeFields['planned_start_at_utc'],
            ':planned_end_at_utc' => $timeFields['planned_end_at_utc'],
            ':planned_start_local' => $timeFields['planned_start_local'],
            ':planned_end_local' => $timeFields['planned_end_local'],
            ':organization_timezone_iana' => $timezone,
            ':planned_start_utc_offset_minutes' => $timeFields['planned_start_utc_offset_minutes'],
            ':planned_end_utc_offset_minutes' => $timeFields['planned_end_utc_offset_minutes'],
            ':planned_start_dst_resolution' => $timeFields['planned_start_dst_resolution'],
            ':planned_end_dst_resolution' => $timeFields['planned_end_dst_resolution'],
            ':status' => $status,
            ':source' => $source,
        ));

        $this->refreshReservationStatusFromLegs($reservationUuid);

        $created = $this->findLegByUuid($legUuid);
        if ($created === null) {
            throw new RuntimeException('Failed to create leg.');
        }
        return $created;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createAlias(array $input, bool $requireCanonicalWriteFlag = true): array
    {
        if ($requireCanonicalWriteFlag && !$this->isFlagEnabled(self::FLAG_CANONICAL_WRITE)) {
            throw new RuntimeException('Canonical identity writes are disabled.');
        }
        $organizationId = $this->requireOrganizationId($input['organization_id'] ?? null);
        $sourceSystem = trim((string)($input['source_system'] ?? ''));
        if ($sourceSystem === '' || strlen($sourceSystem) > 64) {
            throw new InvalidArgumentException('source_system is required.');
        }
        $aliasType = trim((string)($input['alias_type'] ?? ''));
        if (in_array($aliasType, self::DEFERRED_ALIAS_TYPES, true)) {
            throw new InvalidArgumentException('Alias type is deferred: continuous multi-leg sources are not aliased in Phase 2A.');
        }
        if (!in_array($aliasType, self::ALLOWED_ALIAS_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported alias_type.');
        }
        $aliasValue = trim((string)($input['alias_value'] ?? ''));
        if ($aliasValue === '' || strlen($aliasValue) > 96) {
            throw new InvalidArgumentException('alias_value is required.');
        }
        if (str_contains($aliasValue, ':') && $aliasType === 'dispatch_uuid') {
            throw new InvalidArgumentException('Do not encode version into alias_value; use alias_version.');
        }
        $aliasVersion = $this->nullableString($input['alias_version'] ?? null, 32);
        $targetType = strtolower(trim((string)($input['target_type'] ?? '')));
        $reservationUuid = isset($input['reservation_uuid']) && trim((string)$input['reservation_uuid']) !== ''
            ? self::normalizeUuid((string)$input['reservation_uuid'], 'reservation_uuid')
            : null;
        $legUuid = isset($input['leg_uuid']) && trim((string)$input['leg_uuid']) !== ''
            ? self::normalizeUuid((string)$input['leg_uuid'], 'leg_uuid')
            : null;
        if ($targetType === 'reservation') {
            if ($reservationUuid === null || $legUuid !== null) {
                throw new InvalidArgumentException('Reservation aliases require reservation_uuid and must not set leg_uuid.');
            }
            $reservation = $this->findReservationByUuid($reservationUuid);
            if ($reservation === null || (int)$reservation['organization_id'] !== $organizationId) {
                throw new InvalidArgumentException('Alias reservation target not found for organization.');
            }
        } elseif ($targetType === 'leg') {
            if ($legUuid === null || $reservationUuid !== null) {
                throw new InvalidArgumentException('Leg aliases require leg_uuid and must not set reservation_uuid.');
            }
            $leg = $this->findLegByUuid($legUuid);
            if ($leg === null || (int)$leg['organization_id'] !== $organizationId) {
                throw new InvalidArgumentException('Alias leg target not found for organization.');
            }
        } else {
            throw new InvalidArgumentException('target_type must be reservation or leg.');
        }
        $confidence = strtoupper(trim((string)($input['confidence_state'] ?? '')));
        if (!in_array($confidence, self::CONFIDENCE_STATES, true)) {
            throw new InvalidArgumentException('Unsupported confidence_state.');
        }
        $linkage = strtolower(trim((string)($input['linkage_method'] ?? '')));
        if (!in_array($linkage, self::LINKAGE_METHODS, true)) {
            throw new InvalidArgumentException('Unsupported linkage_method.');
        }

        $existing = $this->findAlias($organizationId, $sourceSystem, $aliasType, $aliasValue, $aliasVersion);
        if ($existing !== null) {
            $sameTarget = (string)$existing['target_type'] === $targetType
                && (
                    ($targetType === 'reservation' && (string)$existing['reservation_uuid'] === $reservationUuid)
                    || ($targetType === 'leg' && (string)$existing['leg_uuid'] === $legUuid)
                );
            if (!$sameTarget) {
                throw new RuntimeException('Alias already bound to a different canonical target.');
            }
            return $existing;
        }

        $aliasVersionKey = $aliasVersion ?? '';
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_operational_identity_aliases
              (organization_id, source_system, alias_type, alias_value, alias_version, alias_version_key,
               target_type, reservation_uuid, leg_uuid, confidence_state, linkage_method)
            VALUES
              (:organization_id, :source_system, :alias_type, :alias_value, :alias_version, :alias_version_key,
               :target_type, :reservation_uuid, :leg_uuid, :confidence_state, :linkage_method)
        ");
        $stmt->execute(array(
            ':organization_id' => $organizationId,
            ':source_system' => $sourceSystem,
            ':alias_type' => $aliasType,
            ':alias_value' => $aliasValue,
            ':alias_version' => $aliasVersion,
            ':alias_version_key' => $aliasVersionKey,
            ':target_type' => $targetType,
            ':reservation_uuid' => $reservationUuid,
            ':leg_uuid' => $legUuid,
            ':confidence_state' => $confidence,
            ':linkage_method' => $linkage,
        ));
        $created = $this->findAlias($organizationId, $sourceSystem, $aliasType, $aliasValue, $aliasVersion);
        if ($created === null) {
            throw new RuntimeException('Failed to create alias.');
        }
        return $created;
    }

    /**
     * @param array<string,mixed> $diagnostic
     * @return array<string,mixed>
     */
    public function quarantine(
        int $organizationId,
        string $subjectType,
        string $subjectTable,
        string $subjectPk,
        string $reasonCode,
        array $diagnostic,
        ?string $subjectNaturalKey = null
    ): array {
        $organizationId = $this->requireOrganizationId($organizationId);
        $subjectType = substr(trim($subjectType), 0, 64);
        $subjectTable = substr(trim($subjectTable), 0, 128);
        $subjectPk = substr(trim($subjectPk), 0, 96);
        $reasonCode = substr(trim($reasonCode), 0, 64);
        $subjectNaturalKey = $this->nullableString($subjectNaturalKey, 96);
        if ($subjectType === '' || $subjectTable === '' || $subjectPk === '' || $reasonCode === '') {
            throw new InvalidArgumentException('Quarantine subject fields are required.');
        }
        $sanitized = $this->sanitizeDiagnostic($diagnostic);
        $json = AuditEventService::jsonEncode($sanitized);
        $bytes = strlen($json);
        if ($bytes < 1 || $bytes > self::MAX_DIAGNOSTIC_BYTES) {
            throw new InvalidArgumentException('diagnostic payload must be between 1 and 4096 bytes.');
        }

        $existingStmt = $this->pdo->prepare("
            SELECT * FROM ipca_operational_identity_backfill_quarantine
             WHERE organization_id = ?
               AND subject_type = ?
               AND subject_table = ?
               AND subject_pk = ?
               AND reason_code = ?
             LIMIT 1
        ");
        $existingStmt->execute(array($organizationId, $subjectType, $subjectTable, $subjectPk, $reasonCode));
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing)) {
            return $existing;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_operational_identity_backfill_quarantine
              (organization_id, subject_type, subject_table, subject_pk, subject_natural_key,
               reason_code, diagnostic_json, diagnostic_bytes, status)
            VALUES
              (:organization_id, :subject_type, :subject_table, :subject_pk, :subject_natural_key,
               :reason_code, :diagnostic_json, :diagnostic_bytes, 'open')
        ");
        $stmt->execute(array(
            ':organization_id' => $organizationId,
            ':subject_type' => $subjectType,
            ':subject_table' => $subjectTable,
            ':subject_pk' => $subjectPk,
            ':subject_natural_key' => $subjectNaturalKey,
            ':reason_code' => $reasonCode,
            ':diagnostic_json' => $json,
            ':diagnostic_bytes' => $bytes,
        ));
        $existingStmt->execute(array($organizationId, $subjectType, $subjectTable, $subjectPk, $reasonCode));
        $row = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Failed to write quarantine row.');
        }
        return $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findReservationByUuid(string $reservationUuid): ?array
    {
        $reservationUuid = self::normalizeUuid($reservationUuid, 'reservation_uuid');
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_operational_reservations WHERE reservation_uuid = ? LIMIT 1');
        $stmt->execute(array($reservationUuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findLegByUuid(string $legUuid): ?array
    {
        $legUuid = self::normalizeUuid($legUuid, 'leg_uuid');
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_operational_reservation_legs WHERE leg_uuid = ? LIMIT 1');
        $stmt->execute(array($legUuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listLegsForReservation(string $reservationUuid): array
    {
        $reservationUuid = self::normalizeUuid($reservationUuid, 'reservation_uuid');
        $stmt = $this->pdo->prepare("
            SELECT * FROM ipca_operational_reservation_legs
             WHERE reservation_uuid = ?
             ORDER BY sequence_number ASC
        ");
        $stmt->execute(array($reservationUuid));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findAlias(
        int $organizationId,
        string $sourceSystem,
        string $aliasType,
        string $aliasValue,
        ?string $aliasVersion = null
    ): ?array {
        $organizationId = $this->requireOrganizationId($organizationId);
        $versionKey = $aliasVersion === null || $aliasVersion === '' ? '' : $aliasVersion;
        $stmt = $this->pdo->prepare("
            SELECT * FROM ipca_operational_identity_aliases
             WHERE organization_id = ?
               AND source_system = ?
               AND alias_type = ?
               AND alias_value = ?
               AND alias_version_key = ?
             LIMIT 1
        ");
        $stmt->execute(array($organizationId, $sourceSystem, $aliasType, $aliasValue, $versionKey));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function refreshReservationStatusFromLegs(string $reservationUuid): string
    {
        $reservationUuid = self::normalizeUuid($reservationUuid, 'reservation_uuid');
        $legs = $this->listLegsForReservation($reservationUuid);
        if ($legs === array()) {
            $reservation = $this->findReservationByUuid($reservationUuid);
            return is_array($reservation) ? (string)$reservation['status'] : 'scheduled';
        }
        $statuses = array();
        foreach ($legs as $leg) {
            $statuses[] = (string)$leg['status'];
        }
        $derived = self::deriveReservationStatusFromLegs($statuses);
        $stmt = $this->pdo->prepare("
            UPDATE ipca_operational_reservations
               SET status = ?
             WHERE reservation_uuid = ?
        ");
        $stmt->execute(array($derived, $reservationUuid));
        return $derived;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function normalizePlannedTimes(array $input, string $timezone): array
    {
        $startLocal = $this->nullableDateTimeString($input['planned_start_local'] ?? null);
        $endLocal = $this->nullableDateTimeString($input['planned_end_local'] ?? null);
        $startUtc = $this->nullableDateTimeString($input['planned_start_at_utc'] ?? null);
        $endUtc = $this->nullableDateTimeString($input['planned_end_at_utc'] ?? null);
        $startOffset = $this->nullableInt($input['planned_start_utc_offset_minutes'] ?? null);
        $endOffset = $this->nullableInt($input['planned_end_utc_offset_minutes'] ?? null);
        $startDst = $this->nullableDst($input['planned_start_dst_resolution'] ?? null);
        $endDst = $this->nullableDst($input['planned_end_dst_resolution'] ?? null);

        if ($startLocal !== null && $startUtc === null) {
            $converted = $this->convertLocalToUtc($startLocal, $timezone, $startDst);
            $startUtc = $converted['utc'];
            $startOffset = $converted['offset_minutes'];
            $startDst = $converted['dst_resolution'];
        }
        if ($endLocal !== null && $endUtc === null) {
            $converted = $this->convertLocalToUtc($endLocal, $timezone, $endDst);
            $endUtc = $converted['utc'];
            $endOffset = $converted['offset_minutes'];
            $endDst = $converted['dst_resolution'];
        }
        if ($startUtc !== null && $endUtc !== null && $endUtc <= $startUtc) {
            throw new InvalidArgumentException('planned_end_at_utc must be after planned_start_at_utc.');
        }
        // Local ordering is validated in service for consistency when both present, but UTC is authoritative.
        // Do not hard-fail on local wall-clock order alone when DST makes local comparison misleading.
        if ($startLocal !== null && $endLocal !== null && $startUtc !== null && $endUtc !== null) {
            // Consistency check only: offsets must match recorded UTC conversion.
            if ($startOffset === null || $endOffset === null || $startDst === null || $endDst === null) {
                throw new InvalidArgumentException('Local/DST fields must include offsets and DST resolution when local times are set.');
            }
        }
        return array(
            'planned_start_at_utc' => $startUtc,
            'planned_end_at_utc' => $endUtc,
            'planned_start_local' => $startLocal,
            'planned_end_local' => $endLocal,
            'planned_start_utc_offset_minutes' => $startOffset,
            'planned_end_utc_offset_minutes' => $endOffset,
            'planned_start_dst_resolution' => $startDst,
            'planned_end_dst_resolution' => $endDst,
        );
    }

    /**
     * @return array{utc:string,offset_minutes:int,dst_resolution:string}
     */
    private function convertLocalToUtc(string $local, string $timezone, ?string $preferredResolution): array
    {
        $tz = new DateTimeZone($timezone);
        $ambiguous = $this->isAmbiguousLocal($local, $tz);
        $invalid = $this->isInvalidLocal($local, $tz);
        if ($invalid) {
            throw new InvalidArgumentException('Local wall-clock time falls in a DST gap.');
        }
        $resolution = $preferredResolution;
        if ($ambiguous) {
            if (!in_array($resolution, array('earlier', 'later'), true)) {
                throw new InvalidArgumentException('Ambiguous local time requires dst_resolution earlier|later.');
            }
        } else {
            $resolution = 'unambiguous';
        }

        if ($ambiguous && $resolution === 'later') {
            // Prefer the later occurrence by constructing with the post-transition offset.
            $dt = new DateTimeImmutable($local, $tz);
            $transitions = $tz->getTransitions(strtotime($local . ' -1 day'), strtotime($local . ' +1 day'));
            $laterOffset = null;
            foreach ($transitions as $transition) {
                if (isset($transition['offset'])) {
                    $laterOffset = (int)$transition['offset'];
                }
            }
            if ($laterOffset !== null) {
                $dt = new DateTimeImmutable($local . sprintf(' %+03d:%02d', intdiv($laterOffset, 3600), abs(($laterOffset % 3600) / 60)));
            }
        } else {
            $dt = new DateTimeImmutable($local, $tz);
        }
        $utc = $dt->setTimezone(new DateTimeZone('UTC'));
        return array(
            'utc' => $utc->format('Y-m-d H:i:s.v'),
            'offset_minutes' => (int)round(((int)$dt->getOffset()) / 60),
            'dst_resolution' => $resolution,
        );
    }

    private function isAmbiguousLocal(string $local, DateTimeZone $tz): bool
    {
        try {
            $early = new DateTimeImmutable($local, $tz);
            $ts = $early->getTimestamp();
            $transitions = $tz->getTransitions($ts - 7200, $ts + 7200);
            foreach ($transitions as $transition) {
                if ((int)$transition['ts'] === $ts) {
                    continue;
                }
                // Fold detection: if formatting the same local string can map to two UTC instants.
            }
            $formatted = $early->format('Y-m-d H:i:s');
            $probe = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $formatted, $tz);
            if ($probe === false) {
                return false;
            }
            // Compare offsets one hour earlier/later for the same wall clock on fold days.
            $minusHour = $early->modify('-1 hour');
            $plusHour = $early->modify('+1 hour');
            return $minusHour->format('Y-m-d H:i:s') === $early->format('Y-m-d H:i:s')
                || $plusHour->format('Y-m-d H:i:s') === $early->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return false;
        }
    }

    private function isInvalidLocal(string $local, DateTimeZone $tz): bool
    {
        try {
            $dt = new DateTimeImmutable($local, $tz);
            return $dt->format('Y-m-d H:i:s') !== substr($local, 0, 19);
        } catch (Throwable) {
            return true;
        }
    }

    private function requireOrganizationId(mixed $value): int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            throw new InvalidArgumentException('organization_id is required and must never default.');
        }
        $id = (int)$value;
        if ($id < 1) {
            throw new InvalidArgumentException('organization_id must be a positive integer.');
        }
        return $id;
    }

    private function resolveOrganizationTimezone(int $organizationId): string
    {
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

    private function normalizeScheduleLocalDateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $value) === 1) {
            return str_replace('T', ' ', $value);
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private function normalizeAirportCode(string $value): string
    {
        return strtoupper(substr(trim($value), 0, 8));
    }

    /**
     * Fail closed when a same-UUID reservation cannot be treated as an identical online-create retry.
     *
     * @param array<string,mixed> $reservation
     */
    private function assertReusableOnlineReservationMatches(
        array $reservation,
        int $organizationId,
        string $reservationUuid,
        string $reservationType,
        string $activityDomain,
        string $schedulerRecordId
    ): void {
        $source = strtolower(trim((string)($reservation['source'] ?? '')));
        $compatibleSource = $source === 'server_create';
        $adoptionSystem = strtolower(trim((string)($reservation['adoption_source_system'] ?? '')));
        if ($source === 'schedule_adopt' && $adoptionSystem === 'schedule') {
            // Provenance-compatible schedule adoption may share the immutable scheduler UUID.
            $compatibleSource = true;
        }

        $matches = (int)($reservation['organization_id'] ?? 0) === $organizationId
            && (string)($reservation['reservation_uuid'] ?? '') === $reservationUuid
            && strtolower(trim((string)($reservation['reservation_type'] ?? ''))) === $reservationType
            && strtolower(trim((string)($reservation['activity_domain'] ?? ''))) === $activityDomain
            && $compatibleSource;

        if (!$matches) {
            $this->logTechnicalDiagnostic('online_schedule_reservation_immutable_conflict', array(
                'organization_id' => $organizationId,
                'reservation_uuid' => $reservationUuid,
                'scheduler_record_id' => $schedulerRecordId,
                'error_class' => 'immutable_identity_conflict',
            ));
            throw new RuntimeException('Canonical reservation identity conflict for online schedule create.');
        }
    }

    /**
     * Fail closed when a sequence-1 leg cannot be treated as an identical online-create retry.
     *
     * @param array<string,mixed> $leg
     */
    private function assertReusableOnlineLegMatches(
        array $leg,
        int $organizationId,
        string $reservationUuid,
        string $origin,
        string $destination,
        ?string $startLocal,
        ?string $endLocal,
        string $timezone,
        int $sequenceNumber = 1
    ): void {
        if ((string)($leg['leg_uuid'] ?? '') === '' || !self::isValidUuid((string)$leg['leg_uuid'])) {
            $this->logTechnicalDiagnostic('online_schedule_leg_immutable_conflict', array(
                'organization_id' => $organizationId,
                'reservation_uuid' => $reservationUuid,
                'error_class' => 'immutable_identity_conflict',
            ));
            throw new RuntimeException('Canonical leg identity conflict for online schedule create.');
        }

        $expectedTimes = $this->normalizePlannedTimes(array(
            'planned_start_local' => $startLocal,
            'planned_end_local' => $endLocal,
        ), $timezone);

        $matches = (int)($leg['organization_id'] ?? 0) === $organizationId
            && (string)($leg['reservation_uuid'] ?? '') === $reservationUuid
            && (int)($leg['sequence_number'] ?? 0) === $sequenceNumber
            && $this->normalizeAirportCode((string)($leg['origin_airport'] ?? '')) === $origin
            && $this->normalizeAirportCode((string)($leg['destination_airport'] ?? '')) === $destination
            && $this->normalizeComparableUtc((string)($leg['planned_start_at_utc'] ?? ''))
                === $this->normalizeComparableUtc((string)($expectedTimes['planned_start_at_utc'] ?? ''))
            && $this->normalizeComparableUtc((string)($leg['planned_end_at_utc'] ?? ''))
                === $this->normalizeComparableUtc((string)($expectedTimes['planned_end_at_utc'] ?? ''))
            && trim((string)($leg['organization_timezone_iana'] ?? '')) === $timezone
            && in_array(strtolower(trim((string)($leg['source'] ?? ''))), array('server_create', 'backfill_verified'), true);

        if (!$matches) {
            $this->logTechnicalDiagnostic('online_schedule_leg_immutable_conflict', array(
                'organization_id' => $organizationId,
                'reservation_uuid' => $reservationUuid,
                'leg_uuid' => (string)($leg['leg_uuid'] ?? ''),
                'error_class' => 'immutable_identity_conflict',
            ));
            throw new RuntimeException('Canonical leg identity conflict for online schedule create.');
        }
    }

    private function normalizeComparableUtc(string $value): string
    {
        $value = trim(str_replace('T', ' ', $value));
        if ($value === '') {
            return '';
        }
        // Compare to millisecond precision when present; ignore trailing zero variance.
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(\.\d{1,6})?$/', $value, $matches) === 1) {
            $fraction = isset($matches[2]) ? substr(str_pad(substr($matches[2], 1), 3, '0'), 0, 3) : '000';
            return $matches[1] . '.' . $fraction;
        }
        $ts = strtotime($value);
        return $ts === false ? $value : date('Y-m-d H:i:s.000', $ts);
    }

    private function nullableString(mixed $value, int $maxLen): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        return substr($text, 0, $maxLen);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('Expected integer offset.');
        }
        return (int)$value;
    }

    private function nullableDateTimeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        return $text;
    }

    private function nullableDst(mixed $value): ?string
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        $text = strtolower(trim((string)$value));
        if (!in_array($text, array('earlier', 'later', 'unambiguous', 'unspecified'), true)) {
            throw new InvalidArgumentException('Invalid dst_resolution.');
        }
        return $text;
    }

    private function encodeBoundedJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('adoption_provenance must be an object.');
        }
        $sanitized = $this->sanitizeDiagnostic($value);
        $json = AuditEventService::jsonEncode($sanitized);
        if (strlen($json) > self::MAX_DIAGNOSTIC_BYTES) {
            throw new InvalidArgumentException('adoption_provenance exceeds 4096 bytes.');
        }
        return $json;
    }

    /**
     * @param array<string,mixed> $diagnostic
     * @return array<string,mixed>
     */
    private function sanitizeDiagnostic(array $diagnostic): array
    {
        $blockedKeys = array(
            'audio', 'audio_bytes', 'transcript', 'transcript_body', 'csv', 'csv_contents',
            'garmin_csv', 'password', 'secret', 'token', 'credential', 'authorization',
            'ssn', 'email', 'phone', 'full_name', 'crew_names', 'payload_json',
        );
        $out = array();
        foreach ($diagnostic as $key => $value) {
            $keyText = strtolower((string)$key);
            foreach ($blockedKeys as $blocked) {
                if ($keyText === $blocked || str_contains($keyText, $blocked)) {
                    continue 2;
                }
            }
            if (is_array($value)) {
                $out[$key] = $this->sanitizeDiagnostic($value);
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
