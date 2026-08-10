<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/CvrOperationalBlockTimeService.php';

/**
 * Creates proposed individual logbook entries from completed Master Logbook CVR legs.
 * One proposal per crew member with a mapped user id (students and instructors).
 */
final class MasterLogbookLogbookProposalService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function schemaAvailable(): bool
    {
        return $this->tableExists('ipca_cvr_logbook_proposals')
            && $this->tableExists('ipca_cvr_dispatches')
            && $this->tableExists('ipca_cvr_flight_closures');
    }

    /**
     * Idempotently create/update PROPOSED rows for a completed flight record / dispatch.
     *
     * @return list<array<string,mixed>>
     */
    public function createProposalsForFlightRecord(string $workflowFlightRecordUuid): array
    {
        if (!$this->schemaAvailable()) {
            return array();
        }
        $flightUuid = strtolower(trim($workflowFlightRecordUuid));
        if ($flightUuid === '' || !$this->isUuid($flightUuid)) {
            return array();
        }

        $dispatch = $this->dispatchForFlight($flightUuid);
        if ($dispatch === null) {
            return array();
        }
        $dispatchId = (int)($dispatch['id'] ?? 0);
        if ($dispatchId <= 0) {
            return array();
        }
        if ($this->isDispatchHidden($dispatchId)) {
            return array();
        }

        $closure = $this->latestClosure($flightUuid);
        if ($closure === null) {
            return array();
        }

        $blockTimes = new CvrOperationalBlockTimeService();
        $hobbsHours = $blockTimes->engineTimeHours(
            $dispatch['starting_hobbs'] ?? null,
            $closure['ending_hobbs'] ?? null
        );
        if ($hobbsHours === null || $hobbsHours <= 0) {
            return array();
        }
        $durationMs = (int)round($hobbsHours * 3600000);

        $airports = $this->airportsForDispatch($dispatch);
        $offBlockUtc = $this->offBlockUtc($flightUuid, $closure);
        $onBlockUtc = $blockTimes->derivedOnBlockUtc(array(
            'off_block_utc' => $offBlockUtc,
            'starting_hobbs' => $dispatch['starting_hobbs'] ?? null,
            'ending_hobbs' => $closure['ending_hobbs'] ?? null,
            'closure_on_block_utc' => null,
        ));

        $crew = $this->crewWithUserIds($dispatch['crew_json'] ?? null);
        if ($crew === array()) {
            return array();
        }

        $created = array();
        foreach ($crew as $member) {
            $ownerUserId = (int)($member['person_id'] ?? 0);
            if ($ownerUserId <= 0) {
                continue;
            }
            $ownerRole = $this->normalizeRole((string)($member['role'] ?? ''));
            $entryType = $this->entryTypeForRole($ownerRole);
            $values = $this->proposedValues(
                $dispatch,
                $closure,
                $member,
                $airports,
                $offBlockUtc,
                $onBlockUtc,
                $hobbsHours,
                $ownerRole,
                $entryType
            );
            $proposal = $this->upsertProposal(
                $dispatchId,
                $flightUuid,
                $this->legUuidForDispatch($dispatch),
                $ownerUserId,
                $ownerRole,
                $entryType,
                $durationMs,
                $values
            );
            if ($proposal !== null) {
                $created[] = $proposal;
            }
        }

        return $created;
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed>|null
     */
    private function upsertProposal(
        int $dispatchId,
        string $flightUuid,
        ?string $legUuid,
        int $ownerUserId,
        string $ownerRole,
        string $entryType,
        int $durationMs,
        array $values
    ): ?array {
        $existing = $this->pdo->prepare(
            'SELECT * FROM ipca_cvr_logbook_proposals
             WHERE dispatch_id = ? AND owner_user_id = ? AND entry_type = ?
             LIMIT 1'
        );
        $existing->execute(array($dispatchId, $ownerUserId, $entryType));
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $status = strtoupper(trim((string)($row['status'] ?? '')));
            if ($status === 'ACCEPTED') {
                return $row;
            }
            $update = $this->pdo->prepare(
                'UPDATE ipca_cvr_logbook_proposals
                 SET owner_role = ?,
                     proposed_duration_ms = ?,
                     proposed_values_json = ?,
                     leg_uuid = ?,
                     workflow_flight_record_uuid = ?,
                     status = \'PROPOSED\',
                     updated_at = CURRENT_TIMESTAMP(3)
                 WHERE id = ?'
            );
            $update->execute(array(
                $ownerRole,
                $durationMs,
                AuditEventService::jsonEncode($values),
                $legUuid,
                $flightUuid,
                (int)$row['id'],
            ));
            return $this->proposalById((int)$row['id']);
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO ipca_cvr_logbook_proposals
             (proposal_uuid, dispatch_id, workflow_flight_record_uuid, leg_uuid, owner_user_id,
              owner_role, entry_type, proposed_duration_ms, proposed_values_json, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'PROPOSED\')'
        );
        $insert->execute(array(
            AuditEventService::uuid(),
            $dispatchId,
            $flightUuid,
            $legUuid,
            $ownerUserId,
            $ownerRole,
            $entryType,
            $durationMs,
            AuditEventService::jsonEncode($values),
        ));
        return $this->proposalById((int)$this->pdo->lastInsertId());
    }

    /**
     * @return array<string,mixed>|null
     */
    public function proposalById(int $proposalId): ?array
    {
        if ($proposalId <= 0 || !$this->schemaAvailable()) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_cvr_logbook_proposals WHERE id = ? LIMIT 1');
        $stmt->execute(array($proposalId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForOwner(int $ownerUserId, int $limit = 100): array
    {
        if ($ownerUserId <= 0 || !$this->schemaAvailable()) {
            return array();
        }
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT p.*,
                    d.aircraft_registration,
                    d.mission_code
             FROM ipca_cvr_logbook_proposals p
             INNER JOIN ipca_cvr_dispatches d ON d.id = p.dispatch_id
             WHERE p.owner_user_id = ?
             ORDER BY p.created_at DESC
             LIMIT ' . $limit
        );
        $stmt->execute(array($ownerUserId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRecent(int $limit = 200): array
    {
        if (!$this->schemaAvailable()) {
            return array();
        }
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->query(
            'SELECT p.*,
                    d.aircraft_registration,
                    d.mission_code,
                    u.name AS owner_name,
                    u.email AS owner_email
             FROM ipca_cvr_logbook_proposals p
             INNER JOIN ipca_cvr_dispatches d ON d.id = p.dispatch_id
             LEFT JOIN users u ON u.id = p.owner_user_id
             ORDER BY p.created_at DESC
             LIMIT ' . $limit
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function dispatchForFlight(string $flightUuid): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_cvr_dispatches
             WHERE LOWER(workflow_flight_record_uuid) = ?
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute(array($flightUuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestClosure(string $flightUuid): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_cvr_flight_closures
             WHERE LOWER(workflow_flight_record_uuid) = ?
             ORDER BY received_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(array($flightUuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function isDispatchHidden(int $dispatchId): bool
    {
        if (!$this->tableExists('ipca_cvr_logbook_hidden_legs')) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM ipca_cvr_logbook_hidden_legs WHERE dispatch_id = ? LIMIT 1'
        );
        $stmt->execute(array($dispatchId));
        return (bool)$stmt->fetchColumn();
    }

    /**
     * @param array<string,mixed> $dispatch
     * @return array{departure:string,arrival:string}
     */
    private function airportsForDispatch(array $dispatch): array
    {
        $departure = '';
        $arrival = '';
        $dispatchId = (int)($dispatch['id'] ?? 0);
        $version = (int)($dispatch['current_version'] ?? 1);
        if ($dispatchId > 0 && $this->tableExists('ipca_cvr_dispatch_versions')) {
            $stmt = $this->pdo->prepare(
                'SELECT payload_json FROM ipca_cvr_dispatch_versions
                 WHERE dispatch_id = ? AND dispatch_version = ?
                 LIMIT 1'
            );
            $stmt->execute(array($dispatchId, $version));
            $payload = json_decode((string)$stmt->fetchColumn(), true);
            if (is_array($payload)) {
                $departure = strtoupper(trim((string)($payload['planned_departure_airport'] ?? '')));
                $arrival = strtoupper(trim((string)($payload['planned_destination_airport'] ?? '')));
            }
        }
        return array('departure' => $departure, 'arrival' => $arrival);
    }

    /**
     * @param array<string,mixed> $dispatch
     */
    private function legUuidForDispatch(array $dispatch): ?string
    {
        $schedulerId = strtolower(trim((string)($dispatch['scheduler_record_id'] ?? '')));
        if (!$this->isUuid($schedulerId)) {
            return null;
        }

        $reservationUuid = $schedulerId;
        if ($this->tableExists('ipca_operational_identity_aliases')) {
            $alias = $this->pdo->prepare(
                'SELECT leg_uuid, reservation_uuid FROM ipca_operational_identity_aliases
                 WHERE source_system = \'schedule\'
                   AND alias_type = \'scheduler_record_id\'
                   AND LOWER(alias_value) = ?
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $alias->execute(array($schedulerId));
            $row = $alias->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $leg = strtolower(trim((string)($row['leg_uuid'] ?? '')));
                if ($this->isUuid($leg)) {
                    return $leg;
                }
                $fromAlias = strtolower(trim((string)($row['reservation_uuid'] ?? '')));
                if ($this->isUuid($fromAlias)) {
                    $reservationUuid = $fromAlias;
                }
            }
        }

        if (!$this->tableExists('ipca_operational_reservation_legs')) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT leg_uuid FROM ipca_operational_reservation_legs
             WHERE LOWER(reservation_uuid) = ? OR LOWER(leg_uuid) = ?
             ORDER BY sequence_number ASC
             LIMIT 1'
        );
        $stmt->execute(array($reservationUuid, $schedulerId));
        $leg = strtolower(trim((string)$stmt->fetchColumn()));
        return $this->isUuid($leg) ? $leg : null;
    }

    /**
     * @param array<string,mixed> $closure
     */
    private function offBlockUtc(string $flightUuid, array $closure): ?string
    {
        if ($this->tableExists('ipca_cvr_flight_events')) {
            $stmt = $this->pdo->prepare(
                "SELECT timestamp_utc FROM ipca_cvr_flight_events
                 WHERE LOWER(workflow_flight_record_uuid) = ?
                   AND event_type = 'engine_start_off_block'
                 ORDER BY timestamp_utc ASC
                 LIMIT 1"
            );
            $stmt->execute(array($flightUuid));
            $event = trim((string)$stmt->fetchColumn());
            if ($event !== '') {
                return $event;
            }
        }
        $payload = json_decode((string)($closure['payload_json'] ?? '{}'), true);
        if (is_array($payload)) {
            $fromEvidence = trim((string)(($payload['evidence']['off_block_utc'] ?? $payload['off_block_utc'] ?? '')));
            if ($fromEvidence !== '') {
                return $fromEvidence;
            }
        }
        return null;
    }

    /**
     * @return list<array{person_id:int,name:string,role:string}>
     */
    private function crewWithUserIds(mixed $crewJson): array
    {
        if (is_array($crewJson)) {
            $decoded = $crewJson;
        } else {
            $decoded = json_decode((string)$crewJson, true);
        }
        if (!is_array($decoded)) {
            return array();
        }
        $crew = array();
        foreach ($decoded as $member) {
            if (!is_array($member)) {
                continue;
            }
            $personId = (int)($member['person_id'] ?? $member['personId'] ?? $member['user_id'] ?? 0);
            $name = trim((string)($member['person_name'] ?? $member['personName'] ?? $member['name'] ?? ''));
            $role = $this->normalizeRole((string)($member['role'] ?? $member['crew_role'] ?? ''));
            if ($personId <= 0) {
                continue;
            }
            $crew[] = array(
                'person_id' => $personId,
                'name' => $name,
                'role' => $role,
            );
        }
        return $crew;
    }

    private function normalizeRole(string $role): string
    {
        $normalized = strtolower(trim($role));
        $normalized = str_replace(array(' ', '-'), '_', $normalized);
        return match ($normalized) {
            'student' => 'student',
            'instructor', 'cfi', 'chief_instructor', 'supervisor' => 'instructor',
            'pic', 'pilot_in_command' => 'pic',
            'safetypilot', 'safety_pilot' => 'safetyPilot',
            'observer' => 'observer',
            default => $normalized !== '' ? $normalized : 'crew',
        };
    }

    private function entryTypeForRole(string $role): string
    {
        return match ($role) {
            'student' => 'student_dual',
            'instructor' => 'instructor',
            'pic' => 'pic',
            'safetyPilot' => 'safety_pilot',
            'observer' => 'observer',
            default => 'crew_' . $role,
        };
    }

    /**
     * @param array<string,mixed> $dispatch
     * @param array<string,mixed> $closure
     * @param array{person_id:int,name:string,role:string} $member
     * @param array{departure:string,arrival:string} $airports
     * @return array<string,mixed>
     */
    private function proposedValues(
        array $dispatch,
        array $closure,
        array $member,
        array $airports,
        ?string $offBlockUtc,
        ?string $onBlockUtc,
        float $hobbsHours,
        string $ownerRole,
        string $entryType
    ): array {
        $duration = round($hobbsHours, 2);
        $isStudent = $ownerRole === 'student';
        $isInstructor = $ownerRole === 'instructor';
        $isPic = $ownerRole === 'pic';
        $entryDate = '';
        if ($offBlockUtc !== null && strlen($offBlockUtc) >= 10) {
            $entryDate = substr($offBlockUtc, 0, 10);
        } elseif (trim((string)($dispatch['scheduled_date'] ?? '')) !== '') {
            $entryDate = substr((string)$dispatch['scheduled_date'], 0, 10);
        } else {
            $entryDate = gmdate('Y-m-d');
        }

        return array(
            'entry_date' => $entryDate,
            'departure_airport' => $airports['departure'],
            'departure_time' => $offBlockUtc !== null && strlen($offBlockUtc) >= 16 ? substr($offBlockUtc, 11, 5) : null,
            'arrival_airport' => $airports['arrival'],
            'arrival_time' => $onBlockUtc !== null && strlen($onBlockUtc) >= 16 ? substr($onBlockUtc, 11, 5) : null,
            'aircraft_registration' => strtoupper(trim((string)($dispatch['aircraft_registration'] ?? ''))),
            'total_flight_time' => $duration,
            'single_engine_time' => $duration,
            'dual_received_time' => $isStudent ? $duration : 0,
            'pic_time' => ($isPic || $isInstructor) ? $duration : 0,
            'instructor_name' => $isStudent ? $this->crewNameByRole($dispatch['crew_json'] ?? null, 'instructor') : '',
            'remarks' => 'Proposed from Master Logbook CVR leg'
                . (trim((string)($dispatch['mission_code'] ?? '')) !== ''
                    ? (' · Mission ' . trim((string)$dispatch['mission_code']))
                    : ''),
            'metadata' => array(
                'source' => 'ipca_cvr_master_logbook_proposal',
                'entry_type' => $entryType,
                'owner_role' => $ownerRole,
                'owner_name' => $member['name'],
                'mission_code' => (string)($dispatch['mission_code'] ?? ''),
                'starting_hobbs' => $dispatch['starting_hobbs'] ?? null,
                'ending_hobbs' => $closure['ending_hobbs'] ?? null,
                'starting_tacho' => $dispatch['starting_tacho'] ?? null,
                'ending_tacho' => $closure['ending_tacho'] ?? null,
                'fuel_departure' => $dispatch['fuel_onboard'] ?? null,
                'fuel_landing' => $closure['fuel_remaining'] ?? null,
                'off_block_utc' => $offBlockUtc,
                'on_block_utc' => $onBlockUtc,
                'dispatch_id' => (int)($dispatch['id'] ?? 0),
                'workflow_flight_record_uuid' => (string)($dispatch['workflow_flight_record_uuid'] ?? ''),
            ),
        );
    }

    private function crewNameByRole(mixed $crewJson, string $wantedRole): string
    {
        foreach ($this->crewWithUserIds($crewJson) as $member) {
            if ($this->normalizeRole($member['role']) === $wantedRole) {
                return $member['name'];
            }
        }
        // Fall back to parse without person_id for name-only instructor rows.
        $parsed = (new CvrOperationalBlockTimeService())->parseCrew($crewJson);
        foreach ($parsed as $member) {
            if ($this->normalizeRole((string)($member['role'] ?? '')) === $wantedRole) {
                return (string)($member['name'] ?? '');
            }
        }
        return '';
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
