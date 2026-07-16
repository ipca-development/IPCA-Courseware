<?php
declare(strict_types=1);

final class FlightRecordViewService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function isReady(): bool
    {
        foreach (array('ipca_operational_flight_records', 'ipca_operational_flight_record_versions', 'ipca_flight_sessions') as $table) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
            $stmt->execute(array($table));
            if ((int)$stmt->fetchColumn() !== 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $user
     * @return list<array<string,mixed>>
     */
    public function recordsForUser(array $user, int $limit = 100): array
    {
        if (!$this->isReady()) {
            return array();
        }
        $role = strtolower(trim((string)($user['role'] ?? '')));
        if ($role === 'student') {
            return $this->studentRecords((int)$user['id'], $limit);
        }
        return $this->operationsRecords($limit);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function operationsRecords(int $limit): array
    {
        $stmt = $this->pdo->query("
            SELECT
                r.id,
                r.flight_record_uuid,
                r.status,
                s.session_uuid,
                s.aircraft_registration,
                s.avionics_on_utc,
                s.avionics_off_utc,
                v.version_uuid,
                v.version_number,
                v.exact_hobbs_duration_ms,
                v.exact_tacho_duration_ms,
                v.readiness_status,
                v.landing_event_count,
                v.summary_json,
                v.created_at AS version_created_at
            FROM ipca_operational_flight_records r
            INNER JOIN ipca_flight_sessions s ON s.id = r.session_id
            LEFT JOIN ipca_operational_flight_record_versions v ON v.id = r.current_version_id
            ORDER BY COALESCE(s.avionics_on_utc, r.created_at) DESC, r.id DESC
            LIMIT " . max(1, min(500, $limit))
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function studentRecords(int $userId, int $limit): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                r.id,
                r.flight_record_uuid,
                r.status,
                s.session_uuid,
                s.aircraft_registration,
                s.avionics_on_utc,
                s.avionics_off_utc,
                v.version_uuid,
                v.version_number,
                v.exact_hobbs_duration_ms,
                v.exact_tacho_duration_ms,
                v.readiness_status,
                v.landing_event_count,
                p.status AS proposal_status,
                p.proposed_duration_ms,
                p.target_entry_id,
                p.created_at AS proposal_created_at
            FROM ipca_flight_record_logbook_proposals p
            INNER JOIN ipca_operational_flight_record_versions v ON v.id = p.flight_record_version_id
            INNER JOIN ipca_operational_flight_records r ON r.id = v.flight_record_id
            INNER JOIN ipca_flight_sessions s ON s.id = r.session_id
            WHERE p.owner_user_id = ?
            ORDER BY COALESCE(s.avionics_on_utc, p.created_at) DESC, p.id DESC
            LIMIT " . max(1, min(200, $limit))
        );
        $stmt->execute(array($userId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }
}
