<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';

final class ProviderObservationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $observations keyed observation_key => scalar|array
     */
    public function insertObservations(int $providerRunId, array $observations): int
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROVIDER_OBSERVATIONS)) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . EvidenceSchema::TABLE_PROVIDER_OBSERVATIONS
            . ' (provider_run_id, observation_key, observation_type, value_boolean, value_integer, value_string, value_json)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' observation_type = VALUES(observation_type),'
            . ' value_boolean = VALUES(value_boolean),'
            . ' value_integer = VALUES(value_integer),'
            . ' value_string = VALUES(value_string),'
            . ' value_json = VALUES(value_json)'
        );

        $count = 0;
        foreach ($observations as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            [$type, $bool, $int, $string, $json] = self::encodeValue($value);
            $stmt->execute(array($providerRunId, $key, $type, $bool, $int, $string, $json));
            $count++;
        }
        return $count;
    }

    public function countForProviderRun(int $providerRunId): int
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROVIDER_OBSERVATIONS)) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . EvidenceSchema::TABLE_PROVIDER_OBSERVATIONS . ' WHERE provider_run_id = ?'
        );
        $stmt->execute(array($providerRunId));
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForProviderRun(int $providerRunId): array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROVIDER_OBSERVATIONS)) {
            return array();
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_PROVIDER_OBSERVATIONS
            . ' WHERE provider_run_id = ? ORDER BY observation_key ASC'
        );
        $stmt->execute(array($providerRunId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return array{0:string,1:?int,2:?int,3:?string,4:?string}
     */
    private static function encodeValue(mixed $value): array
    {
        if (is_bool($value)) {
            return array('boolean', $value ? 1 : 0, null, null, null);
        }
        if (is_int($value)) {
            return array('integer', null, $value, null, null);
        }
        if (is_float($value)) {
            return array('string', null, null, (string)$value, null);
        }
        if (is_array($value)) {
            return array('json', null, null, null, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        return array('string', null, null, (string)$value, null);
    }

    /**
     * Build direct observations from a probe HTTP result — no interpretations.
     *
     * @param array<string,mixed> $probeResult
     * @return array<string,mixed>
     */
    public static function directObservationsFromProbeResult(
        array $probeResult,
        string $responseSha256,
        string $chunkAudioSha256,
        string $sourceAudioSha256
    ): array {
        $rawJson = is_array($probeResult['raw_json'] ?? null) ? $probeResult['raw_json'] : array();
        $responseMeta = is_array($probeResult['response'] ?? null) ? $probeResult['response'] : array();
        $segments = is_array($rawJson['segments'] ?? null) ? $rawJson['segments'] : array();
        $httpStatus = (int)($probeResult['http_code'] ?? 0);
        $responseFormat = (string)(is_array($probeResult['request'] ?? null) ? ($probeResult['request']['response_format'] ?? '') : '');
        $rawText = trim((string)($probeResult['raw_provider_text'] ?? ($rawJson['text'] ?? '')));

        $wordCount = (int)($responseMeta['word_timestamp_count'] ?? 0);
        if ($wordCount === 0) {
            foreach ($segments as $segment) {
                if (is_array($segment['words'] ?? null)) {
                    $wordCount += count($segment['words']);
                }
            }
        }

        $observations = array(
            EvidenceSchema::OBS_RESPONSE_CONTAINS_TEXT => $rawText !== '',
            EvidenceSchema::OBS_RESPONSE_CONTAINS_SEGMENTS => count($segments) > 0,
            EvidenceSchema::OBS_RESPONSE_CONTAINS_WORDS => $wordCount > 0,
            EvidenceSchema::OBS_SEGMENT_COUNT => count($segments),
            EvidenceSchema::OBS_WORD_TIMESTAMP_COUNT => $wordCount,
            EvidenceSchema::OBS_HTTP_STATUS => $httpStatus,
            EvidenceSchema::OBS_RESPONSE_FORMAT_REJECTED => $httpStatus === 400 && $responseFormat === 'verbose_json',
            EvidenceSchema::OBS_CHUNK_AUDIO_HASH => $chunkAudioSha256,
            EvidenceSchema::OBS_SOURCE_AUDIO_HASH => $sourceAudioSha256,
            EvidenceSchema::OBS_PROVIDER_RESPONSE_HASH => $responseSha256,
        );

        if (isset($rawJson['language']) && is_string($rawJson['language'])) {
            $observations[EvidenceSchema::OBS_LANGUAGE_RETURNED] = $rawJson['language'];
        }
        if (isset($rawJson['duration']) && is_numeric($rawJson['duration'])) {
            $observations[EvidenceSchema::OBS_DURATION_RETURNED] = (float)$rawJson['duration'];
        }
        if (isset($rawJson['usage']) && is_array($rawJson['usage'])) {
            $observations[EvidenceSchema::OBS_USAGE] = $rawJson['usage'];
        } elseif (isset($responseMeta['observed_fields']['usage']) && is_array($responseMeta['observed_fields']['usage'])) {
            $observations[EvidenceSchema::OBS_USAGE] = $responseMeta['observed_fields']['usage'];
        }

        return $observations;
    }
}
