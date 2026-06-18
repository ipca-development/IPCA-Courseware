<?php
declare(strict_types=1);

final class AirportDataService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<string>
     */
    public function missingTables(): array
    {
        $missing = array();
        foreach (array('ipca_airports', 'ipca_airport_runways') as $table) {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = :table
            ");
            $stmt->execute(array(':table' => $table));
            if ((int)$stmt->fetchColumn() === 0) {
                $missing[] = $table;
            }
        }
        return $missing;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function lookupAirport(string $icaoIdentifier, bool $allowAiLookup = false): ?array
    {
        $icao = $this->normalizeIcao($icaoIdentifier);
        if ($icao === null || $this->missingTables() !== array()) {
            return null;
        }
        $airport = $this->getAirport($icao);
        if ($airport !== null || !$allowAiLookup) {
            return $airport;
        }
        return $this->populateAirportWithAi($icao);
    }

    public function straightLineDistanceNm(string $departure, string $arrival): ?float
    {
        $dep = $this->lookupAirport($departure, false);
        $arr = $this->lookupAirport($arrival, false);
        if ($dep === null || $arr === null) {
            return null;
        }
        $lat1 = (float)$dep['latitude_deg'];
        $lon1 = (float)$dep['longitude_deg'];
        $lat2 = (float)$arr['latitude_deg'];
        $lon2 = (float)$arr['longitude_deg'];
        $earthRadiusNm = 3440.065;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return round($earthRadiusNm * $c, 1);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getAirport(string $icao): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_airports WHERE icao_identifier = :icao LIMIT 1');
        $stmt->execute(array(':icao' => $icao));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $runways = $this->pdo->prepare('SELECT * FROM ipca_airport_runways WHERE airport_id = :id ORDER BY runway_identifier');
        $runways->execute(array(':id' => (int)$row['id']));
        $row['runways'] = $runways->fetchAll(PDO::FETCH_ASSOC) ?: array();
        return $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function populateAirportWithAi(string $icao): ?array
    {
        $key = trim((string)(getenv('CW_OPENAI_API_KEY') ?: getenv('OPENAI_API_KEY') ?: ''));
        if ($key === '') {
            return null;
        }

        $model = trim((string)(getenv('CW_AIRPORT_LOOKUP_MODEL') ?: getenv('CW_OPENAI_MODEL') ?: 'gpt-4.1-mini'));
        $payload = array(
            'model' => $model,
            'input' => array(
                array(
                    'role' => 'system',
                    'content' => array(
                        array('type' => 'input_text', 'text' => implode("\n", array(
                            'Find authoritative airport metadata for a pilot training logbook.',
                            'Return JSON only. Do not invent values.',
                            'Use the ICAO identifier exactly as requested.',
                            'Runway magnetic directions should be degrees magnetic when available.',
                            'If tower status is uncertain, set is_towered to false and lower confidence.',
                        ))),
                    ),
                ),
                array(
                    'role' => 'user',
                    'content' => array(
                        array('type' => 'input_text', 'text' => 'Airport ICAO identifier: ' . $icao),
                    ),
                ),
            ),
            'tools' => array(
                array('type' => 'web_search_preview'),
            ),
            'text' => array(
                'format' => array(
                    'type' => 'json_schema',
                    'name' => 'airport_lookup',
                    'strict' => true,
                    'schema' => $this->airportLookupSchema(),
                ),
            ),
        );

        try {
            $response = $this->openAiResponses($payload, 90);
            $data = $this->extractJson($response);
            if (!is_array($data['airport'] ?? null)) {
                return null;
            }
            $airport = $data['airport'];
            if ($this->normalizeIcao((string)($airport['icao_identifier'] ?? '')) !== $icao) {
                return null;
            }
            return $this->saveAirportFromLookup($airport, $data);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $airport
     * @param array<string,mixed> $source
     * @return array<string,mixed>|null
     */
    private function saveAirportFromLookup(array $airport, array $source): ?array
    {
        $icao = $this->normalizeIcao((string)($airport['icao_identifier'] ?? ''));
        $name = trim((string)($airport['full_name'] ?? ''));
        $lat = $airport['latitude_deg'] ?? null;
        $lon = $airport['longitude_deg'] ?? null;
        if ($icao === null || $name === '' || !is_numeric($lat) || !is_numeric($lon)) {
            return null;
        }
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_airports
              (icao_identifier, full_name, city, region, country, latitude_deg, longitude_deg, elevation_ft, is_towered, source, source_confidence, source_json, fetched_at)
            VALUES
              (:icao, :full_name, :city, :region, :country, :lat, :lon, :elevation_ft, :is_towered, 'ai_search', :confidence, :source_json, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
              full_name = VALUES(full_name),
              city = VALUES(city),
              region = VALUES(region),
              country = VALUES(country),
              latitude_deg = VALUES(latitude_deg),
              longitude_deg = VALUES(longitude_deg),
              elevation_ft = VALUES(elevation_ft),
              is_towered = VALUES(is_towered),
              source = VALUES(source),
              source_confidence = VALUES(source_confidence),
              source_json = VALUES(source_json),
              fetched_at = CURRENT_TIMESTAMP,
              updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(array(
            ':icao' => $icao,
            ':full_name' => $name,
            ':city' => $this->nullableText($airport['city'] ?? null, 128),
            ':region' => $this->nullableText($airport['region'] ?? null, 128),
            ':country' => $this->nullableText($airport['country'] ?? null, 128),
            ':lat' => round((float)$lat, 7),
            ':lon' => round((float)$lon, 7),
            ':elevation_ft' => is_numeric($airport['elevation_ft'] ?? null) ? (int)$airport['elevation_ft'] : null,
            ':is_towered' => !empty($airport['is_towered']) ? 1 : 0,
            ':confidence' => is_numeric($airport['confidence'] ?? null) ? max(0, min(1, (float)$airport['confidence'])) : null,
            ':source_json' => json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ));

        $airportId = (int)$this->pdo->lastInsertId();
        if ($airportId <= 0) {
            $existing = $this->getAirport($icao);
            $airportId = (int)($existing['id'] ?? 0);
        }
        if ($airportId > 0 && is_array($airport['runways'] ?? null)) {
            $runwayStmt = $this->pdo->prepare("
                INSERT INTO ipca_airport_runways
                  (airport_id, runway_identifier, magnetic_direction_deg, length_ft, surface, source_json)
                VALUES
                  (:airport_id, :runway_identifier, :magnetic_direction_deg, :length_ft, :surface, :source_json)
                ON DUPLICATE KEY UPDATE
                  magnetic_direction_deg = VALUES(magnetic_direction_deg),
                  length_ft = VALUES(length_ft),
                  surface = VALUES(surface),
                  source_json = VALUES(source_json),
                  updated_at = CURRENT_TIMESTAMP
            ");
            foreach ($airport['runways'] as $runway) {
                if (!is_array($runway)) {
                    continue;
                }
                $identifier = strtoupper(trim((string)($runway['runway_identifier'] ?? '')));
                if ($identifier === '') {
                    continue;
                }
                $runwayStmt->execute(array(
                    ':airport_id' => $airportId,
                    ':runway_identifier' => substr($identifier, 0, 16),
                    ':magnetic_direction_deg' => is_numeric($runway['magnetic_direction_deg'] ?? null) ? round((float)$runway['magnetic_direction_deg'], 1) : null,
                    ':length_ft' => is_numeric($runway['length_ft'] ?? null) ? (int)$runway['length_ft'] : null,
                    ':surface' => $this->nullableText($runway['surface'] ?? null, 64),
                    ':source_json' => json_encode($runway, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ));
            }
        }
        return $this->getAirport($icao);
    }

    /**
     * @return array<string,mixed>
     */
    private function airportLookupSchema(): array
    {
        $runway = array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'runway_identifier' => array('type' => 'string'),
                'magnetic_direction_deg' => array('type' => array('number', 'null')),
                'length_ft' => array('type' => array('integer', 'null')),
                'surface' => array('type' => array('string', 'null')),
            ),
            'required' => array('runway_identifier', 'magnetic_direction_deg', 'length_ft', 'surface'),
        );
        $airport = array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'icao_identifier' => array('type' => 'string'),
                'full_name' => array('type' => 'string'),
                'city' => array('type' => array('string', 'null')),
                'region' => array('type' => array('string', 'null')),
                'country' => array('type' => array('string', 'null')),
                'latitude_deg' => array('type' => 'number'),
                'longitude_deg' => array('type' => 'number'),
                'elevation_ft' => array('type' => array('integer', 'null')),
                'is_towered' => array('type' => 'boolean'),
                'confidence' => array('type' => 'number'),
                'runways' => array('type' => 'array', 'items' => $runway),
            ),
            'required' => array('icao_identifier', 'full_name', 'city', 'region', 'country', 'latitude_deg', 'longitude_deg', 'elevation_ft', 'is_towered', 'confidence', 'runways'),
        );
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'airport' => $airport,
                'warnings' => array('type' => 'array', 'items' => array('type' => 'string')),
                'sources' => array('type' => 'array', 'items' => array('type' => 'string')),
            ),
            'required' => array('airport', 'warnings', 'sources'),
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function openAiResponses(array $payload, int $timeoutSeconds): array
    {
        $key = trim((string)(getenv('CW_OPENAI_API_KEY') ?: getenv('OPENAI_API_KEY') ?: ''));
        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $key, 'Content-Type: application/json'),
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(30, min(180, $timeoutSeconds)),
        ));
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) {
            throw new RuntimeException('OpenAI request failed: ' . $err);
        }
        $json = json_decode($resp, true);
        if (!is_array($json)) {
            throw new RuntimeException('OpenAI returned non-JSON.');
        }
        if ($code < 200 || $code >= 300) {
            $msg = is_string($json['error']['message'] ?? null) ? $json['error']['message'] : ('HTTP ' . $code);
            throw new RuntimeException('OpenAI error: ' . $msg);
        }
        return $json;
    }

    /**
     * @param array<string,mixed> $resp
     * @return array<string,mixed>
     */
    private function extractJson(array $resp): array
    {
        $text = is_string($resp['output_text'] ?? null) ? trim((string)$resp['output_text']) : '';
        if ($text === '' && is_array($resp['output'] ?? null)) {
            foreach ($resp['output'] as $item) {
                if (!is_array($item) || !is_array($item['content'] ?? null)) {
                    continue;
                }
                foreach ($item['content'] as $part) {
                    if (is_array($part) && is_string($part['text'] ?? null)) {
                        $text .= (string)$part['text'];
                    }
                }
            }
        }
        $decoded = json_decode(trim($text), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Airport lookup returned unreadable JSON.');
        }
        return $decoded;
    }

    private function normalizeIcao(string $value): ?string
    {
        $icao = strtoupper(trim($value));
        return preg_match('/^[A-Z]{4}$/', $icao) === 1 ? $icao : null;
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        $text = trim((string)($value ?? ''));
        return $text === '' ? null : mb_substr($text, 0, $limit);
    }
}
