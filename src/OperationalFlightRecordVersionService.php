<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class OperationalFlightRecordVersionService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function ensureRecordForSession(int $sessionId): array
    {
        if ($sessionId <= 0) {
            throw new RuntimeException('Session id is required.');
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_operational_flight_records WHERE session_id = ? ORDER BY id ASC LIMIT 1');
        $stmt->execute(array($sessionId));
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($record)) {
            return $record;
        }
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_operational_flight_records (flight_record_uuid, session_id)
            VALUES (?, ?)
        ");
        $stmt->execute(array(AuditEventService::uuid(), $sessionId));
        $id = (int)$this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_operational_flight_records WHERE id = ? LIMIT 1');
        $stmt->execute(array($id));
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($record) ? $record : array();
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    public function createVersion(int $flightRecordId, array $summary = array(), string $source = 'system'): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_operational_flight_record_versions WHERE flight_record_id = ? FOR UPDATE');
            $stmt->execute(array($flightRecordId));
            $versionNumber = (int)$stmt->fetchColumn();
            $versionUuid = AuditEventService::uuid();
            $insert = $this->pdo->prepare("
                INSERT INTO ipca_operational_flight_record_versions
                  (flight_record_id, version_uuid, version_number, source, exact_hobbs_duration_ms,
                   exact_tacho_duration_ms, total_night_duration_ms, cross_country_easa_qualified,
                   cross_country_faa_qualified, landing_event_count, readiness_status, summary_json)
                VALUES
                  (:flight_record_id, :version_uuid, :version_number, :source, :exact_hobbs_duration_ms,
                   :exact_tacho_duration_ms, :total_night_duration_ms, :cross_country_easa_qualified,
                   :cross_country_faa_qualified, :landing_event_count, :readiness_status, :summary_json)
            ");
            $insert->execute(array(
                ':flight_record_id' => $flightRecordId,
                ':version_uuid' => $versionUuid,
                ':version_number' => $versionNumber,
                ':source' => substr($source, 0, 64),
                ':exact_hobbs_duration_ms' => $summary['exact_hobbs_duration_ms'] ?? null,
                ':exact_tacho_duration_ms' => $summary['exact_tacho_duration_ms'] ?? null,
                ':total_night_duration_ms' => $summary['total_night_duration_ms'] ?? null,
                ':cross_country_easa_qualified' => !empty($summary['cross_country_easa_qualified']) ? 1 : 0,
                ':cross_country_faa_qualified' => !empty($summary['cross_country_faa_qualified']) ? 1 : 0,
                ':landing_event_count' => (int)($summary['landing_event_count'] ?? 0),
                ':readiness_status' => substr((string)($summary['readiness_status'] ?? 'not_ready'), 0, 32),
                ':summary_json' => AuditEventService::jsonEncode($summary),
            ));
            $versionId = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('UPDATE ipca_operational_flight_records SET current_version_id = ?, updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?')
                ->execute(array($versionId, $flightRecordId));
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->versionById($versionId) ?? array();
    }

    /**
     * @param array<string,mixed> $leg
     */
    public function addLegVersion(int $flightRecordVersionId, array $leg): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_operational_flight_leg_versions
              (leg_version_uuid, flight_record_version_id, leg_index, allocation_start_utc, allocation_end_utc,
               allocated_hobbs_duration_ms, allocated_tacho_duration_ms, departure_airport_code, arrival_airport_code,
               takeoff_utc, landing_utc, first_movement_utc, final_stop_utc, administrative_departure_utc, administrative_arrival_utc,
               night_duration_ms, cross_country_easa_qualified, cross_country_faa_qualified, landing_event_count, notes)
            VALUES
              (:leg_version_uuid, :flight_record_version_id, :leg_index, :allocation_start_utc, :allocation_end_utc,
               :allocated_hobbs_duration_ms, :allocated_tacho_duration_ms, :departure_airport_code, :arrival_airport_code,
               :takeoff_utc, :landing_utc, :first_movement_utc, :final_stop_utc, :administrative_departure_utc, :administrative_arrival_utc,
               :night_duration_ms, :cross_country_easa_qualified, :cross_country_faa_qualified, :landing_event_count, :notes)
        ");
        $stmt->execute(array(
            ':leg_version_uuid' => AuditEventService::uuid(),
            ':flight_record_version_id' => $flightRecordVersionId,
            ':leg_index' => (int)($leg['leg_index'] ?? 1),
            ':allocation_start_utc' => (string)$leg['allocation_start_utc'],
            ':allocation_end_utc' => (string)$leg['allocation_end_utc'],
            ':allocated_hobbs_duration_ms' => (int)$leg['allocated_hobbs_duration_ms'],
            ':allocated_tacho_duration_ms' => $leg['allocated_tacho_duration_ms'] ?? null,
            ':departure_airport_code' => $leg['departure_airport_code'] ?? null,
            ':arrival_airport_code' => $leg['arrival_airport_code'] ?? null,
            ':takeoff_utc' => $leg['takeoff_utc'] ?? null,
            ':landing_utc' => $leg['landing_utc'] ?? null,
            ':first_movement_utc' => $leg['first_movement_utc'] ?? null,
            ':final_stop_utc' => $leg['final_stop_utc'] ?? null,
            ':administrative_departure_utc' => $leg['administrative_departure_utc'] ?? null,
            ':administrative_arrival_utc' => $leg['administrative_arrival_utc'] ?? null,
            ':night_duration_ms' => $leg['night_duration_ms'] ?? null,
            ':cross_country_easa_qualified' => !empty($leg['cross_country_easa_qualified']) ? 1 : 0,
            ':cross_country_faa_qualified' => !empty($leg['cross_country_faa_qualified']) ? 1 : 0,
            ':landing_event_count' => (int)($leg['landing_event_count'] ?? 0),
            ':notes' => $leg['notes'] ?? null,
        ));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function versionById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_operational_flight_record_versions WHERE id = ? LIMIT 1');
        $stmt->execute(array($id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
