<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

/**
 * Stage 1 canonical Duty Assignment snapshot.
 *
 * The snapshot is immutable. Route, time, exercises, operational segments,
 * engine sessions, and evidence artifacts are intentionally excluded.
 */
final class CvrDutyAssignmentIdentityService
{
    public const FLAG_SNAPSHOT_WRITE = 'duty_assignment_snapshot_write_enabled';
    public const FLAG_ENFORCEMENT = 'duty_assignment_enforcement_enabled';
    public const FINGERPRINT_VERSION = 1;

    private const PILOT_FUNCTIONS = array('NONE', 'PF', 'PM');
    private const PARTICIPANT_ROLES = array(
        'student',
        'instructor',
        'supervising_instructor',
        'examiner',
        'pilot_monitoring',
        'safety_pilot',
        'observer',
        'passenger',
        'other',
    );

    public function __construct(private PDO $pdo)
    {
    }

    public function isSnapshotWriteEnabled(): bool
    {
        return $this->isFlagEnabled(self::FLAG_SNAPSHOT_WRITE);
    }

    public function isEnforcementEnabled(): bool
    {
        return $this->isFlagEnabled(self::FLAG_ENFORCEMENT);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{snapshot:array<string,mixed>,participants:list<array<string,mixed>>,fingerprint:string}
     */
    public function canonicalize(array $input): array
    {
        $organizationId = (int)($input['organization_id'] ?? 0);
        $aircraftDeviceId = (int)($input['aircraft_device_id'] ?? $input['aircraft_id'] ?? 0);
        if ($organizationId <= 0 || $aircraftDeviceId <= 0) {
            throw new InvalidArgumentException('Duty Assignment requires organization and aircraft/device identity.');
        }

        $reservationType = $this->normalizedToken((string)($input['reservation_type'] ?? 'flight_training'), 32);
        $activityDomain = $this->normalizedToken((string)($input['activity_domain'] ?? 'flight'), 32);
        $trainingCategory = $this->normalizedToken(
            (string)($input['training_assignment_category'] ?? $reservationType),
            32
        );
        $missionId = (int)($input['mission_id'] ?? 0);
        $missionCode = strtoupper(substr(trim((string)($input['mission_code'] ?? '')), 0, 64));
        $registration = strtoupper(substr(trim((string)($input['aircraft_registration'] ?? '')), 0, 32));
        if ($reservationType === '' || $activityDomain === '' || $trainingCategory === '' || $registration === '') {
            throw new InvalidArgumentException('Duty Assignment type, domain, category, and aircraft registration are required.');
        }

        $crew = is_array($input['crew'] ?? null) ? array_values($input['crew']) : array();
        $participants = $this->canonicalParticipants($crew);
        if ($participants === array()) {
            throw new InvalidArgumentException('Duty Assignment requires accountable participants.');
        }
        $primaryCustomer = $this->resolvePrimaryCustomer($participants);
        if ($activityDomain === 'flight') {
            $pilotFlyingCount = count(array_filter(
                $participants,
                static fn(array $participant): bool =>
                    $participant['is_accountable'] && $participant['pilot_function'] === 'PF'
            ));
            $picCount = count(array_filter(
                $participants,
                static fn(array $participant): bool =>
                    $participant['is_accountable'] && $participant['is_pic']
            ));
            $customer = current(array_filter(
                $participants,
                static fn(array $participant): bool => $participant['is_primary_customer']
            ));
            if (!is_array($customer)
                || $customer['participant_role'] !== 'student'
                || $customer['pilot_function'] !== 'PF') {
                throw new InvalidArgumentException(
                    'Flight Duty Assignment Customer must be the paying Student and Pilot Flying.'
                );
            }
            if ($pilotFlyingCount !== 1 || $picCount < 1 || $picCount > 2) {
                throw new InvalidArgumentException(
                    'Flight Duty Assignment requires one Customer/PF and one or two pilots logging PIC.'
                );
            }
        }

        $snapshot = array(
            'fingerprint_version' => self::FINGERPRINT_VERSION,
            'organization_id' => $organizationId,
            'activity_domain' => $activityDomain,
            'reservation_type' => $reservationType,
            'primary_customer_identity_key' => $primaryCustomer,
            'aircraft_device_id' => $aircraftDeviceId,
            'aircraft_registration' => $registration,
            'training_assignment_category' => $trainingCategory,
            'mission_id' => $missionId > 0 ? $missionId : null,
            // Mission code participates only where no stable mission id exists.
            'mission_code' => $missionId > 0 ? '' : $missionCode,
            'participants' => array_map(
                static fn(array $participant): array => array(
                    'person_identity_key' => $participant['person_identity_key'],
                    'participant_role' => $participant['participant_role'],
                    'pilot_function' => $participant['pilot_function'],
                    'is_pic' => $participant['is_pic'],
                    'is_primary_customer' => $participant['is_primary_customer'],
                    'is_accountable' => $participant['is_accountable'],
                ),
                $participants
            ),
        );
        $snapshot = $this->canonicalObject($snapshot);
        $json = AuditEventService::jsonEncode($snapshot);

        return array(
            'snapshot' => $snapshot,
            'participants' => $participants,
            'fingerprint' => hash('sha256', $json),
        );
    }

    /**
     * Idempotently persist the one immutable snapshot for a reservation.
     *
     * @param array<string,mixed> $input
     * @return array{snapshot:array<string,mixed>,participants:list<array<string,mixed>>,fingerprint:string}
     */
    public function writeSnapshot(string $reservationUuid, array $input): array
    {
        $canonical = $this->canonicalize($input);
        if (!$this->isSnapshotWriteEnabled() || !$this->schemaAvailable()) {
            return $canonical;
        }
        $reservationUuid = $this->normalizeUuid($reservationUuid);
        $existing = $this->snapshotForReservation($reservationUuid);
        if ($existing !== null) {
            if (!hash_equals((string)$existing['duty_fingerprint_sha256'], $canonical['fingerprint'])) {
                throw new RuntimeException('Material Duty Assignment change requires a new reservation.');
            }
            return $canonical;
        }

        $snapshot = $canonical['snapshot'];
        $stmt = $this->pdo->prepare(
            'INSERT INTO ipca_operational_reservation_duties
             (reservation_uuid, organization_id, contract_version, fingerprint_version,
              duty_fingerprint_sha256, primary_customer_identity_key, aircraft_device_id,
              aircraft_registration_snapshot, reservation_type, activity_domain,
              training_assignment_category, mission_id, mission_code_snapshot,
              duty_snapshot_json, source, created_by_user_id)
             VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(array(
            $reservationUuid,
            (int)$snapshot['organization_id'],
            self::FINGERPRINT_VERSION,
            $canonical['fingerprint'],
            (string)$snapshot['primary_customer_identity_key'],
            (int)$snapshot['aircraft_device_id'],
            (string)$snapshot['aircraft_registration'],
            (string)$snapshot['reservation_type'],
            (string)$snapshot['activity_domain'],
            (string)$snapshot['training_assignment_category'],
            $snapshot['mission_id'],
            strtoupper(substr(trim((string)($input['mission_code'] ?? '')), 0, 64)),
            AuditEventService::jsonEncode($snapshot),
            substr($this->normalizedToken((string)($input['source'] ?? 'server_create'), 32), 0, 32),
            (int)($input['created_by_user_id'] ?? 0) ?: null,
        ));

        $insert = $this->pdo->prepare(
            'INSERT INTO ipca_operational_reservation_duty_participants
             (reservation_uuid, organization_id, person_identity_key, person_user_id,
              external_person_uuid, person_name_snapshot, participant_role, pilot_function,
              is_pic, is_primary_customer, is_accountable, sequence_number)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($canonical['participants'] as $index => $participant) {
            $insert->execute(array(
                $reservationUuid,
                (int)$snapshot['organization_id'],
                $participant['person_identity_key'],
                $participant['person_user_id'],
                $participant['external_person_uuid'],
                $participant['person_name_snapshot'],
                $participant['participant_role'],
                $participant['pilot_function'],
                $participant['is_pic'] ? 1 : 0,
                $participant['is_primary_customer'] ? 1 : 0,
                $participant['is_accountable'] ? 1 : 0,
                $index + 1,
            ));
        }
        return $canonical;
    }

    /**
     * @param array<string,mixed> $input
     */
    public function assertReservationMatches(string $reservationUuid, array $input): void
    {
        if (!$this->isEnforcementEnabled() || !$this->schemaAvailable()) {
            return;
        }
        $existing = $this->snapshotForReservation($this->normalizeUuid($reservationUuid));
        if ($existing === null) {
            throw new RuntimeException('Duty Assignment snapshot is required before Dispatch.');
        }
        $candidate = $this->canonicalize($input);
        if (!hash_equals((string)$existing['duty_fingerprint_sha256'], $candidate['fingerprint'])) {
            throw new RuntimeException('Dispatch does not match the immutable Duty Assignment.');
        }
    }

    /**
     * Validate operational Dispatch fields against the stored scheduled duty.
     *
     * @param array<string,mixed> $dispatch
     */
    public function assertDispatchMatches(string $reservationUuid, array $dispatch): void
    {
        if (!$this->isEnforcementEnabled() || !$this->schemaAvailable()) {
            return;
        }
        $existing = $this->snapshotForReservation($this->normalizeUuid($reservationUuid));
        if ($existing === null) {
            throw new RuntimeException('Duty Assignment snapshot is required before Dispatch.');
        }
        $this->assertReservationMatches($reservationUuid, array(
            'organization_id' => (int)$existing['organization_id'],
            'aircraft_device_id' => (int)($dispatch['aircraft_id'] ?? 0),
            'aircraft_registration' => (string)($dispatch['aircraft_registration'] ?? ''),
            'reservation_type' => (string)$existing['reservation_type'],
            'activity_domain' => (string)$existing['activity_domain'],
            'training_assignment_category' => (string)$existing['training_assignment_category'],
            'mission_id' => $existing['mission_id'] !== null ? (int)$existing['mission_id'] : null,
            'mission_code' => (string)($dispatch['mission_code'] ?? $existing['mission_code_snapshot']),
            'crew' => is_array($dispatch['crew'] ?? null) ? $dispatch['crew'] : array(),
        ));
    }

    /** @return array<string,mixed>|null */
    public function snapshotForReservation(string $reservationUuid): ?array
    {
        if (!$this->schemaAvailable()) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_operational_reservation_duties WHERE reservation_uuid = ? LIMIT 1'
        );
        $stmt->execute(array($reservationUuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param list<mixed> $crew
     * @return list<array<string,mixed>>
     */
    private function canonicalParticipants(array $crew): array
    {
        $participants = array();
        foreach ($crew as $member) {
            if (!is_array($member)) {
                continue;
            }
            $userId = (int)($member['user_id'] ?? $member['person_id'] ?? 0);
            $externalUuid = strtolower(trim((string)($member['external_person_uuid'] ?? '')));
            if ($userId <= 0 && !$this->isUuid($externalUuid)) {
                throw new InvalidArgumentException('New accountable crew require a stable user or external-person identity.');
            }
            $identityKey = $userId > 0 ? 'user:' . $userId : 'external:' . $externalUuid;
            $role = $this->normalizeParticipantRole((string)($member['participant_role'] ?? $member['role'] ?? 'other'));
            $rawPilotFunction = (string)($member['pilot_function'] ?? $member['pilotFunction'] ?? 'NONE');
            $pilotFunction = self::normalizePilotFunction($rawPilotFunction);
            $rawPilotNormalized = strtoupper(trim($rawPilotFunction));
            $isPic = (bool)($member['is_pic'] ?? $member['isPIC'] ?? false)
                || in_array($rawPilotNormalized, array('PIC', 'PILOT_IN_COMMAND', 'PILOT IN COMMAND'), true)
                || strtolower(trim((string)($member['role'] ?? ''))) === 'pic';
            $isAccountable = array_key_exists('is_accountable', $member)
                ? (bool)$member['is_accountable']
                : !in_array($role, array('observer', 'passenger'), true);
            $participants[] = array(
                'person_identity_key' => $identityKey,
                'person_user_id' => $userId > 0 ? $userId : null,
                'external_person_uuid' => $userId > 0 ? null : $externalUuid,
                'person_name_snapshot' => substr(trim((string)($member['person_name'] ?? $member['name'] ?? '')), 0, 255),
                'participant_role' => $role,
                'pilot_function' => $pilotFunction,
                'is_pic' => $isPic,
                'is_primary_customer' => (bool)($member['is_primary_customer'] ?? false),
                'is_accountable' => $isAccountable,
            );
        }
        usort($participants, static function (array $a, array $b): int {
            return strcmp(
                implode('|', array($a['person_identity_key'], $a['participant_role'], $a['pilot_function'], (int)$a['is_pic'])),
                implode('|', array($b['person_identity_key'], $b['participant_role'], $b['pilot_function'], (int)$b['is_pic']))
            );
        });
        return $participants;
    }

    /** @param list<array<string,mixed>> $participants */
    private function resolvePrimaryCustomer(array &$participants): string
    {
        $explicit = array_keys(array_filter(
            $participants,
            static fn(array $p): bool => (bool)$p['is_primary_customer']
        ));
        if (count($explicit) === 1) {
            return (string)$participants[$explicit[0]]['person_identity_key'];
        }
        if (count($explicit) > 1) {
            throw new InvalidArgumentException('Duty Assignment has more than one primary customer.');
        }

        $studentPf = array_keys(array_filter(
            $participants,
            static fn(array $p): bool => $p['participant_role'] === 'student' && $p['pilot_function'] === 'PF'
        ));
        $students = array_keys(array_filter(
            $participants,
            static fn(array $p): bool => $p['participant_role'] === 'student'
        ));
        $candidate = count($studentPf) === 1 ? $studentPf[0] : (count($students) === 1 ? $students[0] : null);
        if ($candidate === null) {
            throw new InvalidArgumentException('Select exactly one primary customer or one Student/PF.');
        }
        $participants[$candidate]['is_primary_customer'] = true;
        return (string)$participants[$candidate]['person_identity_key'];
    }

    public static function normalizePilotFunction(string $value): string
    {
        $normalized = strtoupper(trim($value));
        $aliases = array(
            '' => 'NONE',
            'PILOT_FLYING' => 'PF',
            'PILOT MONITORING' => 'PM',
            'PILOT_MONITORING' => 'PM',
            'PIC' => 'NONE',
            'PILOT IN COMMAND' => 'NONE',
            'PILOT_IN_COMMAND' => 'NONE',
        );
        $normalized = $aliases[$normalized] ?? $normalized;
        if (!in_array($normalized, self::PILOT_FUNCTIONS, true)) {
            throw new InvalidArgumentException('Unsupported pilot function.');
        }
        return $normalized;
    }

    private function normalizeParticipantRole(string $value): string
    {
        $normalized = $this->normalizedToken($value, 32);
        $normalized = match ($normalized) {
            'cfi', 'chief_instructor' => 'instructor',
            'supervisor', 'supervisinginstructor' => 'supervising_instructor',
            'safetypilot' => 'safety_pilot',
            'pic' => 'other',
            default => $normalized,
        };
        if (!in_array($normalized, self::PARTICIPANT_ROLES, true)) {
            throw new InvalidArgumentException('Unsupported Duty Assignment participant role.');
        }
        return $normalized;
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function canonicalObject(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item) && !array_is_list($item)) {
                $value[$key] = $this->canonicalObject($item);
            }
        }
        ksort($value);
        return $value;
    }

    private function schemaAvailable(): bool
    {
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $this->pdo->query(
                    "SELECT 1 FROM sqlite_master WHERE type='table' AND name='ipca_operational_reservation_duties'"
                );
                return $stmt !== false && $stmt->fetchColumn() !== false;
            }
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'ipca_operational_reservation_duties'");
            return $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function isFlagEnabled(string $policyKey): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT value_text FROM system_policy_values
                 WHERE policy_key = ? AND is_active = 1 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute(array($policyKey));
            $value = strtolower(trim((string)$stmt->fetchColumn()));
            return in_array($value, array('1', 'true', 'yes', 'on'), true);
        } catch (Throwable) {
            return false;
        }
    }

    private function normalizedToken(string $value, int $maxLength): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        return substr(trim($value, '_'), 0, $maxLength);
    }

    private function normalizeUuid(string $value): string
    {
        $value = strtolower(trim($value));
        if (!$this->isUuid($value)) {
            throw new InvalidArgumentException('reservation_uuid must be a valid lowercase UUID.');
        }
        return $value;
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', strtolower(trim($value))) === 1;
    }
}
