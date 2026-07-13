<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class AdsbHistoricalCorridorService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function createRequestForFlightRecord(int $flightRecordId, int $actorUserId = 0, float $radiusNm = 10.0): array
    {
        $record = $this->flightRecord($flightRecordId);
        if ($record === null) {
            throw new RuntimeException('Flight Record not found.');
        }
        $start = trim((string)($record['avionics_on_utc'] ?? $record['engine_start_utc'] ?? ''));
        $end = trim((string)($record['avionics_off_utc'] ?? $record['engine_stop_utc'] ?? ''));
        if ($start === '' || $end === '') {
            throw new RuntimeException('Flight Record does not have a valid airborne/session time window for ADS-B retrieval.');
        }
        $uuid = AuditEventService::uuid();
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_adsb_historical_requests
              (request_uuid, session_id, flight_record_id, query_start_utc, query_end_utc, search_radius_nm, requested_by)
            VALUES
              (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(array($uuid, (int)$record['session_id'], $flightRecordId, $start, $end, $radiusNm, $actorUserId > 0 ? $actorUserId : null));
        return $this->requestById((int)$this->pdo->lastInsertId()) ?? array();
    }

    /**
     * @return array<string,mixed>
     */
    public function fetchAndNormalize(int $requestId): array
    {
        $request = $this->requestById($requestId);
        if ($request === null) {
            throw new RuntimeException('ADS-B request not found.');
        }
        $this->pdo->prepare("UPDATE ipca_adsb_historical_requests SET status = 'fetching', updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?")
            ->execute(array($requestId));
        try {
            $raw = $this->fetchProvider($request);
            $rawPath = $this->storeJson('raw', (string)$request['request_uuid'], $raw);
            $samples = $this->normalizeProviderPayload($raw, $request);
            $normalized = array(
                'provider' => (string)$request['provider'],
                'request_uuid' => (string)$request['request_uuid'],
                'query_start_utc' => (string)$request['query_start_utc'],
                'query_end_utc' => (string)$request['query_end_utc'],
                'samples' => $samples,
            );
            $normalizedPath = $this->storeJson('normalized', (string)$request['request_uuid'], $normalized);
            $this->replaceTrafficSamples($request, $samples);
            $this->pdo->prepare("
                UPDATE ipca_adsb_historical_requests
                SET status = 'ready',
                    raw_storage_path = ?,
                    normalized_storage_path = ?,
                    traffic_sample_count = ?,
                    error_message = NULL,
                    updated_at = CURRENT_TIMESTAMP(3)
                WHERE id = ?
            ")->execute(array($rawPath, $normalizedPath, count($samples), $requestId));
            return array('ok' => true, 'status' => 'ready', 'sample_count' => count($samples));
        } catch (Throwable $e) {
            $this->pdo->prepare("
                UPDATE ipca_adsb_historical_requests
                SET status = 'failed', error_message = ?, updated_at = CURRENT_TIMESTAMP(3)
                WHERE id = ?
            ")->execute(array($e->getMessage(), $requestId));
            throw $e;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function trafficForFlightRecord(int $flightRecordId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT sample_time_utc, aircraft_hex, callsign, latitude, longitude, altitude_ft, groundspeed_kt, track_deg, vertical_speed_fpm, distance_nm
            FROM ipca_adsb_historical_traffic_samples
            WHERE flight_record_id = ?
            ORDER BY sample_time_utc ASC, aircraft_hex ASC
        ");
        $stmt->execute(array($flightRecordId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function fetchProvider(array $request): array
    {
        $baseUrl = rtrim((string)getenv('CW_ADSB_EXCHANGE_BASE_URL'), '/');
        $apiKey = trim((string)getenv('CW_ADSB_EXCHANGE_API_KEY'));
        if ($baseUrl === '' || $apiKey === '') {
            throw new RuntimeException('ADS-B provider credentials are not configured.');
        }
        $query = http_build_query(array(
            'start' => (string)$request['query_start_utc'],
            'end' => (string)$request['query_end_utc'],
            'lat' => (string)($request['center_latitude'] ?? ''),
            'lon' => (string)($request['center_longitude'] ?? ''),
            'radius_nm' => (string)$request['search_radius_nm'],
        ));
        $context = stream_context_create(array('http' => array(
            'method' => 'GET',
            'header' => "Authorization: Bearer {$apiKey}\r\nAccept: application/json\r\n",
            'timeout' => 30,
        )));
        $raw = file_get_contents($baseUrl . '/historical/corridor?' . $query, false, $context);
        if ($raw === false || trim($raw) === '') {
            throw new RuntimeException('ADS-B provider returned no data.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('ADS-B provider returned invalid JSON.');
        }
        return $decoded;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $request
     * @return list<array<string,mixed>>
     */
    private function normalizeProviderPayload(array $payload, array $request): array
    {
        $items = is_array($payload['aircraft'] ?? null) ? $payload['aircraft'] : (is_array($payload['samples'] ?? null) ? $payload['samples'] : array());
        $samples = array();
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $time = $item['time'] ?? $item['timestamp'] ?? $item['seen_utc'] ?? null;
            $lat = $item['lat'] ?? $item['latitude'] ?? null;
            $lon = $item['lon'] ?? $item['longitude'] ?? null;
            if ($time === null || !is_numeric($lat) || !is_numeric($lon)) {
                continue;
            }
            $samples[] = array(
                'sample_time_utc' => gmdate('Y-m-d H:i:s', is_numeric($time) ? (int)$time : strtotime((string)$time)),
                'aircraft_hex' => strtoupper(substr(preg_replace('/[^A-Fa-f0-9]/', '', (string)($item['hex'] ?? $item['icao'] ?? '')) ?? '', 0, 6)),
                'callsign' => substr(trim((string)($item['flight'] ?? $item['callsign'] ?? '')), 0, 32),
                'latitude' => (float)$lat,
                'longitude' => (float)$lon,
                'altitude_ft' => is_numeric($item['alt_baro'] ?? $item['altitude'] ?? null) ? (float)($item['alt_baro'] ?? $item['altitude']) : null,
                'groundspeed_kt' => is_numeric($item['gs'] ?? $item['groundspeed'] ?? null) ? (float)($item['gs'] ?? $item['groundspeed']) : null,
                'track_deg' => is_numeric($item['track'] ?? null) ? (float)$item['track'] : null,
                'vertical_speed_fpm' => is_numeric($item['baro_rate'] ?? $item['vertical_rate'] ?? null) ? (float)($item['baro_rate'] ?? $item['vertical_rate']) : null,
                'distance_nm' => is_numeric($item['distance_nm'] ?? null) ? (float)$item['distance_nm'] : null,
                'raw' => $item,
            );
        }
        return $samples;
    }

    /**
     * @param array<string,mixed> $request
     * @param list<array<string,mixed>> $samples
     */
    private function replaceTrafficSamples(array $request, array $samples): void
    {
        $requestId = (int)$request['id'];
        $this->pdo->prepare('DELETE FROM ipca_adsb_historical_traffic_samples WHERE request_id = ?')->execute(array($requestId));
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_adsb_historical_traffic_samples
              (request_id, session_id, flight_record_id, sample_time_utc, aircraft_hex, callsign, latitude, longitude,
               altitude_ft, groundspeed_kt, track_deg, vertical_speed_fpm, distance_nm, raw_json)
            VALUES
              (:request_id, :session_id, :flight_record_id, :sample_time_utc, :aircraft_hex, :callsign, :latitude, :longitude,
               :altitude_ft, :groundspeed_kt, :track_deg, :vertical_speed_fpm, :distance_nm, :raw_json)
        ");
        foreach ($samples as $sample) {
            $stmt->execute(array(
                ':request_id' => $requestId,
                ':session_id' => $request['session_id'] ?? null,
                ':flight_record_id' => $request['flight_record_id'] ?? null,
                ':sample_time_utc' => $sample['sample_time_utc'],
                ':aircraft_hex' => $sample['aircraft_hex'],
                ':callsign' => $sample['callsign'],
                ':latitude' => $sample['latitude'],
                ':longitude' => $sample['longitude'],
                ':altitude_ft' => $sample['altitude_ft'],
                ':groundspeed_kt' => $sample['groundspeed_kt'],
                ':track_deg' => $sample['track_deg'],
                ':vertical_speed_fpm' => $sample['vertical_speed_fpm'],
                ':distance_nm' => $sample['distance_nm'],
                ':raw_json' => AuditEventService::jsonEncode($sample['raw']),
            ));
        }
    }

    private function storeJson(string $type, string $requestUuid, array $payload): string
    {
        $dir = dirname(__DIR__) . '/storage/adsb_historical/' . gmdate('Y/m/d');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create ADS-B storage directory.');
        }
        $path = $dir . '/' . $requestUuid . '.' . $type . '.json';
        file_put_contents($path, AuditEventService::jsonEncode($payload));
        return $path;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function flightRecord(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT r.*, s.avionics_on_utc, s.avionics_off_utc, s.engine_start_utc, s.engine_stop_utc
            FROM ipca_operational_flight_records r
            INNER JOIN ipca_flight_sessions s ON s.id = r.session_id
            WHERE r.id = ?
            LIMIT 1
        ");
        $stmt->execute(array($id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function requestById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_adsb_historical_requests WHERE id = ? LIMIT 1');
        $stmt->execute(array($id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
