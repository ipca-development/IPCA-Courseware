<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';
require_once __DIR__ . '/IdempotencyKeyBuilder.php';

final class ProviderRunRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $requestConfig
     * @param array<string,mixed>|null $rawJson
     * @param array<string,mixed>|null $capabilityObservations
     * @param array<string,string>|null $evidenceFiles
     * @return array{row: array<string,mixed>, inserted: bool, reused_reason: ?string, segments_persisted: int, words_persisted: int}
     */
    public function insertOrGetExisting(array $params): array
    {
        EvidenceSchema::requireTables($this->pdo, array(
            EvidenceSchema::TABLE_PROVIDER_RUNS,
            EvidenceSchema::TABLE_AUDIO_CHUNKS,
        ));

        $idempotencyKey = (string)$params['idempotency_key'];
        $openaiRequestId = isset($params['openai_request_id']) && is_string($params['openai_request_id']) && $params['openai_request_id'] !== ''
            ? $params['openai_request_id']
            : null;

        $existing = $this->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return array(
                'row' => $existing,
                'inserted' => false,
                'reused_reason' => 'idempotency_key',
                'segments_persisted' => 0,
                'words_persisted' => 0,
            );
        }

        if ($openaiRequestId !== null) {
            $byRequestId = $this->findByOpenaiRequestId($openaiRequestId);
            if ($byRequestId !== null) {
                return array(
                    'row' => $byRequestId,
                    'inserted' => false,
                    'reused_reason' => 'openai_request_id',
                    'segments_persisted' => 0,
                    'words_persisted' => 0,
                );
            }
        }

        $rawJson = is_array($params['raw_json'] ?? null) ? $params['raw_json'] : null;
        $rawPayload = $rawJson ?? array(
            'error' => (string)($params['error_message'] ?? 'empty_response'),
            'http_status' => (int)($params['http_status'] ?? 0),
        );
        $rawString = json_encode($rawPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $responseSha256 = hash('sha256', $rawString);
        $matchingRun = $this->findFirstByResponseHash($responseSha256);

        $uuid = $this->uuid();
        $requestConfig = is_array($params['request_config'] ?? null) ? $params['request_config'] : array();
        $requestConfigHash = IdempotencyKeyBuilder::requestConfigHash($requestConfig);
        $usageJson = isset($rawJson['usage']) ? json_encode($rawJson['usage'], JSON_UNESCAPED_SLASHES) : null;
        $capabilityJson = isset($params['capability_observations']) && is_array($params['capability_observations'])
            ? json_encode($params['capability_observations'], JSON_UNESCAPED_SLASHES)
            : null;
        $evidenceFilesJson = isset($params['evidence_files']) && is_array($params['evidence_files'])
            ? json_encode($params['evidence_files'], JSON_UNESCAPED_SLASHES)
            : null;

        $httpStatus = (int)($params['http_status'] ?? 0);
        $returnedText = trim((string)($params['returned_text'] ?? ($rawJson['text'] ?? '')));

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . EvidenceSchema::TABLE_PROVIDER_RUNS
            . ' (provider_run_uuid, probe_execution_uuid, probe_label, audio_chunk_id, processing_run_id,'
            . ' parent_provider_run_id, matching_response_provider_run_id, idempotency_key, run_purpose, provider, model,'
            . ' provider_reported_model, response_format, request_config_hash, request_config_json, prompt_hash, prompt_text,'
            . ' language_forced, language_code, previous_text_used, timestamp_granularities_json, openai_request_id,'
            . ' http_status, success_status, error_type, error_message, raw_response_json, response_sha256, returned_text,'
            . ' source_audio_sha256, chunk_audio_sha256, chunk_start_time_ms, chunk_duration_ms, source_audio_duration_ms,'
            . ' transcription_duration_ms, request_started_at, request_completed_at, latency_ms, usage_json,'
            . ' capability_observations_json, evidence_files_json, code_version, is_canonical_timeline, retry_count, worker_id)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(array(
            $uuid,
            (string)$params['probe_execution_uuid'],
            (string)$params['probe_label'],
            (int)$params['audio_chunk_id'],
            (int)$params['processing_run_id'],
            isset($params['parent_provider_run_id']) ? (int)$params['parent_provider_run_id'] : null,
            $matchingRun !== null ? (int)$matchingRun['id'] : null,
            $idempotencyKey,
            (string)($params['run_purpose'] ?? EvidenceSchema::RUN_PURPOSE_REPROBE),
            (string)($params['provider'] ?? 'openai'),
            (string)$params['model'],
            isset($params['provider_reported_model']) ? (string)$params['provider_reported_model'] : null,
            (string)$params['response_format'],
            $requestConfigHash,
            json_encode(IdempotencyKeyBuilder::normalizeRequestConfig($requestConfig), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            isset($params['prompt_hash']) ? (string)$params['prompt_hash'] : null,
            isset($params['prompt_text']) ? (string)$params['prompt_text'] : null,
            !empty($params['language_forced']) ? 1 : 0,
            isset($params['language_code']) ? (string)$params['language_code'] : null,
            !empty($params['previous_text_used']) ? 1 : 0,
            isset($params['timestamp_granularities']) && is_array($params['timestamp_granularities'])
                ? json_encode($params['timestamp_granularities'], JSON_UNESCAPED_SLASHES)
                : null,
            $openaiRequestId,
            $httpStatus,
            (string)($params['success_status'] ?? self::successStatusFromHttp($httpStatus)),
            isset($params['error_type']) ? (string)$params['error_type'] : null,
            isset($params['error_message']) ? (string)$params['error_message'] : null,
            $rawString,
            $responseSha256,
            $returnedText !== '' ? $returnedText : null,
            isset($params['source_audio_sha256']) ? (string)$params['source_audio_sha256'] : null,
            (string)$params['chunk_audio_sha256'],
            (int)$params['chunk_start_time_ms'],
            (int)$params['chunk_duration_ms'],
            isset($params['source_audio_duration_ms']) ? (int)$params['source_audio_duration_ms'] : null,
            isset($params['transcription_duration_ms']) ? (int)$params['transcription_duration_ms'] : null,
            isset($params['request_started_at']) ? (string)$params['request_started_at'] : null,
            isset($params['request_completed_at']) ? (string)$params['request_completed_at'] : null,
            isset($params['latency_ms']) ? (int)$params['latency_ms'] : null,
            $usageJson,
            $capabilityJson,
            $evidenceFilesJson,
            isset($params['code_version']) ? (string)$params['code_version'] : EvidenceSchema::SCHEMA_VERSION,
            !empty($params['is_canonical_timeline']) ? 1 : 0,
            (int)($params['retry_count'] ?? 0),
            isset($params['worker_id']) ? (string)$params['worker_id'] : null,
        ));

        $providerRunId = (int)$this->pdo->lastInsertId();
        $segmentsPersisted = 0;
        $wordsPersisted = 0;
        if ($httpStatus >= 200 && $httpStatus < 300 && is_array($rawJson)) {
            $persistWords = (int)($params['word_timestamp_count'] ?? 0) > 0;
            [$segmentsPersisted, $wordsPersisted] = $this->persistSegmentsFromResponse($providerRunId, $rawJson, $persistWords);
        }

        $row = $this->findById($providerRunId);
        return array(
            'row' => $row ?? array('id' => $providerRunId, 'provider_run_uuid' => $uuid, 'response_sha256' => $responseSha256),
            'inserted' => true,
            'reused_reason' => null,
            'segments_persisted' => $segmentsPersisted,
            'words_persisted' => $wordsPersisted,
        );
    }

    /**
     * @return array{0:int,1:int} segments, words
     */
    public function persistSegmentsFromResponse(int $providerRunId, array $rawJson, bool $persistWords): array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROVIDER_SEGMENTS)) {
            return array(0, 0);
        }

        $segments = is_array($rawJson['segments'] ?? null) ? $rawJson['segments'] : array();
        if ($segments === array()) {
            return array(0, 0);
        }

        $insertSegment = $this->pdo->prepare(
            'INSERT INTO ' . EvidenceSchema::TABLE_PROVIDER_SEGMENTS
            . ' (provider_run_id, segment_index, provider_segment_id, seek_ms, start_time_ms, end_time_ms, text,'
            . ' temperature, avg_log_probability, compression_ratio, no_speech_probability, tokens_json, transient_flag)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $insertWord = null;
        if ($persistWords && EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROVIDER_WORDS)) {
            $insertWord = $this->pdo->prepare(
                'INSERT INTO ' . EvidenceSchema::TABLE_PROVIDER_WORDS
                . ' (provider_segment_id, word_index, text, start_time_ms, end_time_ms, confidence)'
                . ' VALUES (?, ?, ?, ?, ?, ?)'
            );
        }

        $segmentCount = 0;
        $wordCount = 0;
        foreach ($segments as $index => $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $startMs = self::secondsToMs($segment['start'] ?? null);
            $endMs = self::secondsToMs($segment['end'] ?? null);
            $seekMs = self::secondsToMs($segment['seek'] ?? null);
            $tokensJson = isset($segment['tokens']) ? json_encode($segment['tokens'], JSON_UNESCAPED_SLASHES) : null;

            $insertSegment->execute(array(
                $providerRunId,
                (int)$index,
                isset($segment['id']) ? (int)$segment['id'] : null,
                $seekMs,
                $startMs ?? 0,
                $endMs ?? ($startMs ?? 0),
                trim((string)($segment['text'] ?? '')),
                isset($segment['temperature']) ? (float)$segment['temperature'] : null,
                isset($segment['avg_logprob']) ? (float)$segment['avg_logprob'] : null,
                isset($segment['compression_ratio']) ? (float)$segment['compression_ratio'] : null,
                isset($segment['no_speech_prob']) ? (float)$segment['no_speech_prob'] : null,
                $tokensJson,
                isset($segment['transient']) ? ((bool)$segment['transient'] ? 1 : 0) : null,
            ));
            $providerSegmentId = (int)$this->pdo->lastInsertId();
            $segmentCount++;

            if ($insertWord !== null && is_array($segment['words'] ?? null)) {
                foreach ($segment['words'] as $wordIndex => $word) {
                    if (!is_array($word)) {
                        continue;
                    }
                    $insertWord->execute(array(
                        $providerSegmentId,
                        (int)$wordIndex,
                        trim((string)($word['word'] ?? $word['text'] ?? '')),
                        self::secondsToMs($word['start'] ?? null),
                        self::secondsToMs($word['end'] ?? null),
                        isset($word['confidence']) ? (float)$word['confidence'] : null,
                    ));
                    $wordCount++;
                }
            }
        }

        return array($segmentCount, $wordCount);
    }

    public function countSegments(int $providerRunId): int
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROVIDER_SEGMENTS)) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . EvidenceSchema::TABLE_PROVIDER_SEGMENTS . ' WHERE provider_run_id = ?'
        );
        $stmt->execute(array($providerRunId));
        return (int)$stmt->fetchColumn();
    }

    public function countWordsForProviderRun(int $providerRunId): int
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROVIDER_WORDS)) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . EvidenceSchema::TABLE_PROVIDER_WORDS . ' w'
            . ' INNER JOIN ' . EvidenceSchema::TABLE_PROVIDER_SEGMENTS . ' s ON s.id = w.provider_segment_id'
            . ' WHERE s.provider_run_id = ?'
        );
        $stmt->execute(array($providerRunId));
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listSegments(int $providerRunId): array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROVIDER_SEGMENTS)) {
            return array();
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_PROVIDER_SEGMENTS
            . ' WHERE provider_run_id = ? ORDER BY segment_index ASC'
        );
        $stmt->execute(array($providerRunId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCanonicalWhisperRunsForProcessingRun(int $processingRunId): array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROVIDER_RUNS)) {
            return array();
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_PROVIDER_RUNS
            . ' WHERE processing_run_id = ? AND is_canonical_timeline = 1 AND success_status = ?'
            . ' ORDER BY chunk_start_time_ms ASC, id ASC'
        );
        $stmt->execute(array($processingRunId, 'success'));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($rows) && $rows !== array()) {
            return $rows;
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_PROVIDER_RUNS
            . ' WHERE processing_run_id = ? AND probe_label = ? AND success_status = ?'
            . ' ORDER BY chunk_start_time_ms ASC, id ASC'
        );
        $stmt->execute(array($processingRunId, 'whisper1_verbose_json', 'success'));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listProductionJsonRunsForProcessingRun(int $processingRunId): array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROVIDER_RUNS)) {
            return array();
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_PROVIDER_RUNS
            . ' WHERE processing_run_id = ? AND probe_label = ? AND success_status = ?'
            . ' ORDER BY chunk_start_time_ms ASC, id ASC'
        );
        $stmt->execute(array($processingRunId, 'production_json', 'success'));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    public function mergedProductionTextForProcessingRun(int $processingRunId): ?string
    {
        $runs = $this->listProductionJsonRunsForProcessingRun($processingRunId);
        if ($runs === array()) {
            return null;
        }
        $parts = array();
        foreach ($runs as $run) {
            $text = trim((string)($run['returned_text'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        if ($parts === array()) {
            return null;
        }
        if (count($parts) === 1) {
            return $parts[0];
        }
        require_once dirname(__DIR__) . '/CockpitRecorderService.php';
        return CockpitRecorderService::mergeTranscriptParts($parts);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByProcessingRunAndLabel(int $processingRunId, string $probeLabel): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROVIDER_RUNS)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_PROVIDER_RUNS
            . ' WHERE processing_run_id = ? AND probe_label = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(array($processingRunId, $probeLabel));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findCanonicalForProcessingRun(int $processingRunId): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROVIDER_RUNS)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_PROVIDER_RUNS
            . ' WHERE processing_run_id = ? AND is_canonical_timeline = 1'
            . ' ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(array($processingRunId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }
        return $this->findByProcessingRunAndLabel($processingRunId, 'whisper1_verbose_json');
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROVIDER_RUNS)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_PROVIDER_RUNS . ' WHERE idempotency_key = ? LIMIT 1'
        );
        $stmt->execute(array($idempotencyKey));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByOpenaiRequestId(string $requestId): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROVIDER_RUNS)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_PROVIDER_RUNS . ' WHERE openai_request_id = ? LIMIT 1'
        );
        $stmt->execute(array($requestId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findFirstByResponseHash(string $responseSha256): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROVIDER_RUNS)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_PROVIDER_RUNS . ' WHERE response_sha256 = ? ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute(array($responseSha256));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROVIDER_RUNS)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ' . EvidenceSchema::TABLE_PROVIDER_RUNS . ' WHERE id = ? LIMIT 1');
        $stmt->execute(array($id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listByProbeExecutionUuid(string $probeExecutionUuid): array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROVIDER_RUNS)) {
            return array();
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_PROVIDER_RUNS
            . ' WHERE probe_execution_uuid = ? ORDER BY id ASC'
        );
        $stmt->execute(array($probeExecutionUuid));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    private static function successStatusFromHttp(int $httpStatus): string
    {
        if ($httpStatus >= 200 && $httpStatus < 300) {
            return 'success';
        }
        if ($httpStatus >= 400) {
            return 'failed';
        }
        return 'unknown';
    }

    private static function secondsToMs(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return (int)round((float)$value * 1000.0);
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
