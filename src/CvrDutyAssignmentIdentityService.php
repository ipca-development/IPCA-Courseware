<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

/**
 * Immutable Stage 1 Duty Assignment snapshot.
 *
 * Route, schedule time, exercises, segments, engine sessions, and evidence
 * artifacts are deliberately excluded from the fingerprint.
 */
final class CvrDutyAssignmentIdentityService
{
    public const FLAG_SNAPSHOT_WRITE = 'duty_assignment_snapshot_write_enabled';
    public const FLAG_ENFORCEMENT = 'duty_assignment_enforcement_enabled';
    private const FINGERPRINT_VERSION = 1;
    private const ROLES = array(
        'student', 'instructor', 'supervising_instructor', 'examiner',
        'safety_pilot', 'observer', 'passenger', 'other',
    );

    public function __construct(private PDO $pdo)
    {
    }

    public function isSnapshotWriteEnabled(): bool
    {
        return $this->flag(self::FLAG_SNAPSHOT_WRITE);
    }

    public function isEnforcementEnabled(): bool
    {
        return $this->flag(self::FLAG_ENFORCEMENT);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{snapshot:array<string,mixed>,participants:list<array<string,mixed>>,fingerprint:string}
     */
    public function canonicalize(array $input): array
    {
        $organizationId = (int)($input['organization_id'] ?? 0);
        $aircraftId = (int)($input['aircraft_device_id'] ?? $input['aircraft_id'] ?? 0);
        $registration = strtoupper(substr(trim((string)($input['aircraft_registration'] ?? '')), 0, 32));
        if ($organizationId <= 0 || $aircraftId <= 0 || $registration === '') {
            throw new InvalidArgumentException('Duty Assignment requires organization and aircraft/device identity.');
        }
        $reservationType = $this->token((string)($input['reservation_type'] ?? 'flight_training'), 32);
        $activityDomain = $this->token((string)($input['activity_domain'] ?? 'flight'), 32);
        $category = $this->token(
            (string)($input['training_assignment_category'] ?? $reservationType),
            32
        );
        if ($reservationType === '' || $activityDomain === '' || $category === '') {
            throw new InvalidArgumentException('Duty Assignment type, domain, and category are required.');
        }
        $participants = $this->participants(
            is_array($input['crew'] ?? null) ? array_values($input['crew']) : array()
        );
        if ($participants === array()) {
            throw new InvalidArgumentException('Duty Assignment requires accountable participants.');
        }
        if ($activityDomain === 'flight') {
            $pf = count(array_filter($participants, static fn(array $p): bool =>
                $p['is_accountable'] && $p['pilot_function'] === 'PF'));
            $pic = count(array_filter($participants, static fn(array $p): bool =>
                $p['is_accountable'] && $p['is_pic']));
            if ($pf !== 1 || $pic !== 1) {
                throw new InvalidArgumentException(
                    'Flight Duty Assignment requires exactly one accountable PF and one accountable PIC.'
                );
            }
        }
        $customer = $this->primaryCustomer($participants);
        $missionId = (int)($input['mission_id'] ?? 0);
        $missionCode = strtoupper(substr(trim((string)($input['mission_code'] ?? '')), 0, 64));
        $snapshot = array(
            'fingerprint_version' => self::FINGERPRINT_VERSION,
            'organization_id' => $organizationId,
            'activity_domain' => $activityDomain,
            'reservation_type' => $reservationType,
            'primary_customer_identity_key' => $customer,
            'aircraft_device_id' => $aircraftId,
            'aircraft_registration' => $registration,
            'training_assignment_category' => $category,
            'mission_id' => $missionId > 0 ? $missionId : null,
            'mission_code' => $missionId > 0 ? '' : $missionCode,
            'participants' => array_map(static fn(array $p): array => array(
                'person_identity_key' => $p['person_identity_key'],
                'participant_role' => $p['participant_role'],
                'pilot_function' => $p['pilot_function'],
                'is_pic' => $p['is_pic'],
                'is_primary_customer' => $p['is_primary_customer'],
                'is_accountable' => $p['is_accountable'],
            ), $participants),
        );
        ksort($snapshot);
        return array(
            'snapshot' => $snapshot,
            'participants' => $participants,
            'fingerprint' => hash('sha256', AuditEventService::jsonEncode($snapshot)),
        );
    }

    /** @param array<string,mixed> $input */
    public function writeSnapshot(string $reservationUuid, array $input): array
    {
        $canonical = $this->canonicalize($input);
        if (!$this->isSnapshotWriteEnabled() || !$this->schemaAvailable()) {
            return $canonical;
        }
        $reservationUuid = $this->uuid($reservationUuid);
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
            $snapshot['primary_customer_identity_key'],
            (int)$snapshot['aircraft_device_id'],
            $snapshot['aircraft_registration'],
            $snapshot['reservation_type'],
            $snapshot['activity_domain'],
            $snapshot['training_assignment_category'],
            $snapshot['mission_id'],
            strtoupper(substr(trim((string)($input['mission_code'] ?? '')), 0, 64)),
            AuditEventService::jsonEncode($snapshot),
            substr($this->token((string)($input['source'] ?? 'server_create'), 32), 0, 32),
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

    /** @param array<string,mixed> $input */
    public function assertReservationMatches(string $reservationUuid, array $input): void
    {
        if (!$this->isEnforcementEnabled() || !$this->schemaAvailable()) {
            return;
        }
        $existing = $this->snapshotForReservation($this->uuid($reservationUuid));
        if ($existing === null) {
            throw new RuntimeException('Duty Assignment snapshot is required before Dispatch.');
        }
        $candidate = $this->canonicalize($input);
        if (!hash_equals((string)$existing['duty_fingerprint_sha256'], $candidate['fingerprint'])) {
            throw new RuntimeException('Dispatch does not match the immutable Duty Assignment.');
        }
    }

    /** @param array<string,mixed> $dispatch */
    public function assertDispatchMatches(string $reservationUuid, array $dispatch): void
    {
        if (!$this->isEnforcementEnabled()) {
            return;
        }
        $existing = $this->snapshotForReservation($this->uuid($reservationUuid));
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

    /** @param list<mixed> $crew @return list<array<string,mixed>> */
    private function participants(array $crew): array
    {
        $result = array();
        foreach ($crew as $member) {
            if (!is_array($member)) {
                continue;
            }
            $userId = (int)($member['user_id'] ?? $member['person_id'] ?? 0);
            $external = strtolower(trim((string)($member['external_person_uuid'] ?? '')));
            if ($userId <= 0 && !$this->isUuid($external)) {
                throw new InvalidArgumentException(
                    'New accountable crew require a stable user or external-person identity.'
                );
            }
            $rawPilot = (string)($member['pilot_function'] ?? $member['pilotFunction'] ?? 'NONE');
            $role = $this->role((string)($member['participant_role'] ?? $member['role'] ?? 'other'));
            $rawPilotUpper = strtoupper(trim($rawPilot));
            $result[] = array(
                'person_identity_key' => $userId > 0 ? 'user:' . $userId : 'external:' . $external,
                'person_user_id' => $userId > 0 ? $userId : null,
                'external_person_uuid' => $userId > 0 ? null : $external,
                'person_name_snapshot' => substr(trim((string)($member['person_name'] ?? $member['name'] ?? '')), 0, 255),
                'participant_role' => $role,
                'pilot_function' => self::normalizePilotFunction($rawPilot),
                'is_pic' => (bool)($member['is_pic'] ?? $member['isPIC'] ?? false)
                    || in_array($rawPilotUpper, array('PIC', 'PILOT_IN_COMMAND', 'PILOT IN COMMAND'), true)
                    || strtolower(trim((string)($member['role'] ?? ''))) === 'pic',
                'is_primary_customer' => (bool)($member['is_primary_customer'] ?? false),
                'is_accountable' => array_key_exists('is_accountable', $member)
                    ? (bool)$member['is_accountable']
                    : !in_array($role, array('observer', 'passenger'), true),
            );
        }
        usort($result, static fn(array $a, array $b): int => strcmp(
            implode('|', array($a['person_identity_key'], $a['participant_role'], $a['pilot_function'], (int)$a['is_pic'])),
            implode('|', array($b['person_identity_key'], $b['participant_role'], $b['pilot_function'], (int)$b['is_pic']))
        ));
        return $result;
    }

    /** @param list<array<string,mixed>> $participants */
    private function primaryCustomer(array &$participants): string
    {
        $explicit = array_keys(array_filter($participants, static fn(array $p): bool => $p['is_primary_customer']));
        if (count($explicit) > 1) {
            throw new InvalidArgumentException('Duty Assignment has more than one primary customer.');
        }
        if (count($explicit) === 1) {
            return (string)$participants[$explicit[0]]['person_identity_key'];
        }
        $studentPf = array_keys(array_filter($participants, static fn(array $p): bool =>
            $p['participant_role'] === 'student' && $p['pilot_function'] === 'PF'));
        $students = array_keys(array_filter($participants, static fn(array $p): bool =>
            $p['participant_role'] === 'student'));
        $candidate = count($studentPf) === 1 ? $studentPf[0] : (count($students) === 1 ? $students[0] : null);
        if ($candidate === null) {
            throw new InvalidArgumentException('Select exactly one primary customer or one Student/PF.');
        }
        $participants[$candidate]['is_primary_customer'] = true;
        return (string)$participants[$candidate]['person_identity_key'];
    }

    public static function normalizePilotFunction(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = array(
            '' => 'NONE',
            'PILOT_FLYING' => 'PF',
            'PILOT MONITORING' => 'PM',
            'PILOT_MONITORING' => 'PM',
            'PIC' => 'NONE',
            'PILOT IN COMMAND' => 'NONE',
            'PILOT_IN_COMMAND' => 'NONE',
        )[$value] ?? $value;
        if (!in_array($value, array('NONE', 'PF', 'PM'), true)) {
            throw new InvalidArgumentException('Unsupported pilot function.');
        }
        return $value;
    }

    private function role(string $value): string
    {
        $role = $this->token($value, 32);
        $role = match ($role) {
            'cfi', 'chief_instructor' => 'instructor',
            'supervisor', 'supervisinginstructor' => 'supervising_instructor',
            'safetypilot' => 'safety_pilot',
            'pic' => 'other',
            default => $role,
        };
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Unsupported Duty Assignment participant role.');
        }
        return $role;
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

    private function flag(string $key): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT value_text FROM system_policy_values
                 WHERE policy_key = ? AND is_active = 1 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute(array($key));
            return in_array(strtolower(trim((string)$stmt->fetchColumn())), array('1', 'true', 'yes', 'on'), true);
        } catch (Throwable) {
            return false;
        }
    }

    private function token(string $value, int $max): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        return substr(trim($value, '_'), 0, $max);
    }

    private function uuid(string $value): string
    {
        $value = strtolower(trim($value));
        if (!$this->isUuid($value)) {
            throw new InvalidArgumentException('reservation_uuid must be a valid lowercase UUID.');
        }
        return $value;
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            strtolower(trim($value))
        ) === 1;
    }
}
