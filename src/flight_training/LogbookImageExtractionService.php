<?php
declare(strict_types=1);

final class LogbookImageExtractionService
{
    /**
     * @return array{rows:list<array<string,mixed>>,warnings:list<string>,model:string}
     */
    public function extractRows(string $imageUrl, ?string $mimeType = null): array
    {
        $imagePath = $this->publicPathForUrl($imageUrl);
        if ($imagePath === '' || !is_file($imagePath)) {
            throw new RuntimeException('Uploaded logbook image was not found on the server.');
        }

        $bytes = file_get_contents($imagePath);
        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Uploaded logbook image could not be read.');
        }
        if (strlen($bytes) > 20 * 1024 * 1024) {
            throw new RuntimeException('Logbook image is too large for extraction. Please upload a smaller image or compressed scan.');
        }

        $mime = $this->resolveMimeType($imagePath, $mimeType);
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($bytes);
        $model = trim((string)(getenv('CW_LOGBOOK_EXTRACT_MODEL') ?: getenv('CW_OPENAI_VISION_MODEL') ?: 'gpt-4.1-mini'));

        $payload = array(
            'model' => $model,
            'input' => array(
                array(
                    'role' => 'system',
                    'content' => array(
                        array('type' => 'input_text', 'text' => $this->systemPrompt()),
                    ),
                ),
                array(
                    'role' => 'user',
                    'content' => array(
                        array('type' => 'input_text', 'text' => 'Extract the visible pilot logbook rows from this image. Return JSON only.'),
                        array('type' => 'input_image', 'image_url' => $dataUrl),
                    ),
                ),
            ),
            'text' => array(
                'format' => array(
                    'type' => 'json_schema',
                    'name' => 'logbook_extraction',
                    'strict' => true,
                    'schema' => $this->schema(),
                ),
            ),
        );

        $response = $this->openAiResponses($payload, 180);
        $json = $this->extractJson($response);
        $rows = is_array($json['rows'] ?? null) ? $json['rows'] : array();
        $warnings = is_array($json['warnings'] ?? null) ? $json['warnings'] : array();

        return array(
            'rows' => $this->normalizeRows($rows),
            'warnings' => array_values(array_map('strval', $warnings)),
            'model' => $model,
        );
    }

    private function systemPrompt(): string
    {
        return implode("\n", array(
            'You extract pilot logbook tables from images.',
            'Return only rows that are visible in the image.',
            'Do not invent missing values. Use null or 0 when a cell is blank or unreadable.',
            'Keep Basic Instrument Flying separate from actual instrument and simulated instrument.',
            'If a value is ambiguous, put the best visible value in the field and add a warning.',
            'Use decimal hours for flight times. Use integer counts for landings.',
            'Use YYYY-MM-DD for dates when possible; otherwise null.',
            'Use HH:MM 24-hour time when possible; otherwise null.',
            'Airport/place fields may contain airport identifiers or handwritten place names.',
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function schema(): array
    {
        $rowProperties = array(
            'entry_date' => array('type' => array('string', 'null')),
            'departure_airport' => array('type' => array('string', 'null')),
            'departure_time' => array('type' => array('string', 'null')),
            'arrival_airport' => array('type' => array('string', 'null')),
            'arrival_time' => array('type' => array('string', 'null')),
            'aircraft_type' => array('type' => array('string', 'null')),
            'aircraft_registration' => array('type' => array('string', 'null')),
            'single_engine_time' => array('type' => 'number'),
            'multi_engine_time' => array('type' => 'number'),
            'pic_time' => array('type' => 'number'),
            'copilot_time' => array('type' => 'number'),
            'dual_received_time' => array('type' => 'number'),
            'instructor_time' => array('type' => 'number'),
            'solo_time' => array('type' => 'number'),
            'cross_country_time' => array('type' => 'number'),
            'cross_country_distance_nm' => array('type' => 'number'),
            'night_time' => array('type' => 'number'),
            'instrument_time' => array('type' => 'number'),
            'actual_instrument_time' => array('type' => 'number'),
            'simulated_instrument_time' => array('type' => 'number'),
            'basic_instrument_flying_time' => array('type' => 'number'),
            'day_landings' => array('type' => 'integer'),
            'night_landings' => array('type' => 'integer'),
            'towered_airport_landings' => array('type' => 'integer'),
            'total_flight_time' => array('type' => 'number'),
            'instructor_name' => array('type' => array('string', 'null')),
            'remarks' => array('type' => array('string', 'null')),
            'endorsements' => array('type' => array('string', 'null')),
            'confidence' => array('type' => 'number'),
            'warnings' => array('type' => 'array', 'items' => array('type' => 'string')),
        );

        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'rows' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => $rowProperties,
                        'required' => array_keys($rowProperties),
                    ),
                ),
                'warnings' => array('type' => 'array', 'items' => array('type' => 'string')),
            ),
            'required' => array('rows', 'warnings'),
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        $normalized = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized[] = array(
                'entry_date' => $this->nullableString($row['entry_date'] ?? null),
                'departure_airport' => $this->nullableString($row['departure_airport'] ?? null),
                'departure_time' => $this->nullableString($row['departure_time'] ?? null),
                'arrival_airport' => $this->nullableString($row['arrival_airport'] ?? null),
                'arrival_time' => $this->nullableString($row['arrival_time'] ?? null),
                'aircraft_type' => $this->nullableString($row['aircraft_type'] ?? null),
                'aircraft_registration' => $this->nullableString($row['aircraft_registration'] ?? null),
                'single_engine_time' => $this->decimal($row['single_engine_time'] ?? 0),
                'multi_engine_time' => $this->decimal($row['multi_engine_time'] ?? 0),
                'pic_time' => $this->decimal($row['pic_time'] ?? 0),
                'copilot_time' => $this->decimal($row['copilot_time'] ?? 0),
                'dual_received_time' => $this->decimal($row['dual_received_time'] ?? 0),
                'instructor_time' => $this->decimal($row['instructor_time'] ?? 0),
                'solo_time' => $this->decimal($row['solo_time'] ?? 0),
                'cross_country_time' => $this->decimal($row['cross_country_time'] ?? 0),
                'cross_country_distance_nm' => $this->decimal($row['cross_country_distance_nm'] ?? 0),
                'night_time' => $this->decimal($row['night_time'] ?? 0),
                'instrument_time' => $this->decimal($row['instrument_time'] ?? 0),
                'actual_instrument_time' => $this->decimal($row['actual_instrument_time'] ?? 0),
                'simulated_instrument_time' => $this->decimal($row['simulated_instrument_time'] ?? 0),
                'basic_instrument_flying_time' => $this->decimal($row['basic_instrument_flying_time'] ?? 0),
                'day_landings' => (int)($row['day_landings'] ?? 0),
                'night_landings' => (int)($row['night_landings'] ?? 0),
                'towered_airport_landings' => (int)($row['towered_airport_landings'] ?? 0),
                'total_flight_time' => $this->decimal($row['total_flight_time'] ?? 0),
                'instructor_name' => $this->nullableString($row['instructor_name'] ?? null),
                'remarks' => $this->nullableString($row['remarks'] ?? null),
                'endorsements' => $this->nullableString($row['endorsements'] ?? null),
                'confidence' => max(0.0, min(1.0, (float)($row['confidence'] ?? 0))),
                'warnings' => is_array($row['warnings'] ?? null) ? array_values(array_map('strval', $row['warnings'])) : array(),
            );
        }
        return $normalized;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function openAiResponses(array $payload, int $timeoutSeconds): array
    {
        $key = trim((string)(getenv('CW_OPENAI_API_KEY') ?: getenv('OPENAI_API_KEY') ?: ''));
        if ($key === '') {
            throw new RuntimeException('Missing CW_OPENAI_API_KEY or OPENAI_API_KEY for logbook extraction.');
        }

        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
            ),
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(30, min(600, $timeoutSeconds)),
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
            throw new RuntimeException('OpenAI returned non-JSON (HTTP ' . $code . '): ' . substr($resp, 0, 300));
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
        $text = '';
        if (is_string($resp['output_text'] ?? null)) {
            $text = trim((string)$resp['output_text']);
        }
        if ($text === '') {
            $out = $resp['output'] ?? array();
            if (is_array($out)) {
                foreach ($out as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $content = $item['content'] ?? array();
                    if (!is_array($content)) {
                        continue;
                    }
                    foreach ($content as $part) {
                        if (is_array($part) && is_string($part['text'] ?? null)) {
                            $text .= (string)$part['text'];
                        }
                    }
                }
            }
        }
        $decoded = json_decode(trim($text), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Logbook extraction returned unreadable JSON.');
        }
        return $decoded;
    }

    private function publicPathForUrl(string $imageUrl): string
    {
        $path = parse_url($imageUrl, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || !str_starts_with($path, '/uploads/flight_training_logbooks/')) {
            return '';
        }
        return dirname(__DIR__, 2) . '/public' . $path;
    }

    private function resolveMimeType(string $path, ?string $mimeType): string
    {
        $mime = trim((string)$mimeType);
        if (in_array($mime, array('image/jpeg', 'image/jpg', 'image/png', 'image/webp'), true)) {
            return $mime === 'image/jpg' ? 'image/jpeg' : $mime;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }

    private function decimal(mixed $value): float
    {
        return round((float)$value, 2);
    }
}
