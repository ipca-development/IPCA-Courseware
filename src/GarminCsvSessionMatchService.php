<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class GarminCsvSessionMatchService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $csvFile
     * @return array<string,mixed>
     */
    public function match(array $csvFile): array
    {
        $sessionId = isset($csvFile['session_id']) ? (int)$csvFile['session_id'] : 0;
        if ($sessionId <= 0) {
            $sessionId = $this->bestTimeWindowSession($csvFile);
        }
        $status = $sessionId > 0 ? 'matched' : 'needs_admin_review';
        $confidence = $sessionId > 0 ? 0.9000 : 0.0000;
        $method = $sessionId > 0 ? 'device_aircraft_time_window' : 'no_candidate';
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_csv_session_matches
              (match_uuid, csv_file_id, session_id, match_status, confidence, match_method, evidence_json)
            VALUES
              (:match_uuid, :csv_file_id, :session_id, :match_status, :confidence, :match_method, :evidence_json)
        ");
        $stmt->execute(array(
            ':match_uuid' => AuditEventService::uuid(),
            ':csv_file_id' => (int)$csvFile['id'],
            ':session_id' => $sessionId > 0 ? $sessionId : null,
            ':match_status' => $status,
            ':confidence' => $confidence,
            ':match_method' => $method,
            ':evidence_json' => AuditEventService::jsonEncode(array(
                'aircraft_id' => $csvFile['aircraft_id'] ?? null,
                'device_id' => $csvFile['device_id'] ?? null,
                'first_valid_sample_utc' => $csvFile['first_valid_sample_utc'] ?? null,
                'last_valid_sample_utc' => $csvFile['last_valid_sample_utc'] ?? null,
            )),
        ));
        if ($sessionId > 0) {
            $this->pdo->prepare('UPDATE ipca_garmin_csv_files SET session_id = ?, active_for_session = 1 WHERE id = ?')
                ->execute(array($sessionId, (int)$csvFile['id']));
        }
        return array('status' => $status, 'session_id' => $sessionId, 'confidence' => $confidence, 'method' => $method);
    }

    /**
     * @param array<string,mixed> $csvFile
     */
    private function bestTimeWindowSession(array $csvFile): int
    {
        $aircraftId = (int)($csvFile['aircraft_id'] ?? 0);
        $deviceId = (int)($csvFile['device_id'] ?? 0);
        $first = trim((string)($csvFile['first_valid_sample_utc'] ?? ''));
        $last = trim((string)($csvFile['last_valid_sample_utc'] ?? ''));
        if ($aircraftId <= 0 || $first === '' || $last === '') {
            return 0;
        }
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM ipca_flight_sessions
            WHERE aircraft_id = :aircraft_id
              AND (:device_id = 0 OR device_id IS NULL OR device_id = :device_id)
              AND (
                (avionics_on_utc IS NULL OR avionics_on_utc <= DATE_ADD(:last_sample, INTERVAL 10 MINUTE))
                AND (avionics_off_utc IS NULL OR avionics_off_utc >= DATE_SUB(:first_sample, INTERVAL 10 MINUTE))
              )
            ORDER BY ABS(TIMESTAMPDIFF(SECOND, COALESCE(avionics_on_utc, created_at), :first_sample)) ASC, id ASC
            LIMIT 1
        ");
        $stmt->execute(array(
            ':aircraft_id' => $aircraftId,
            ':device_id' => $deviceId,
            ':first_sample' => $first,
            ':last_sample' => $last,
        ));
        return (int)($stmt->fetchColumn() ?: 0);
    }
}
