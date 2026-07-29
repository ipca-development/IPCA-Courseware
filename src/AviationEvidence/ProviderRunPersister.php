<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';
require_once __DIR__ . '/IdempotencyKeyBuilder.php';
require_once __DIR__ . '/ProcessingRunRepository.php';
require_once __DIR__ . '/AudioChunkRepository.php';
require_once __DIR__ . '/ProviderRunRepository.php';
require_once __DIR__ . '/ProviderObservationRepository.php';
require_once __DIR__ . '/ProviderModelCapabilitiesRepository.php';

/**
 * Persists immutable Phase 0 probe evidence into typed tables.
 * Filesystem evidence remains the primary audit trail; this supplements it.
 */
final class ProviderRunPersister
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ProcessingRunRepository $processingRuns,
        private readonly AudioChunkRepository $audioChunks,
        private readonly ProviderRunRepository $providerRuns,
        private readonly ProviderObservationRepository $observations,
        private readonly ProviderModelCapabilitiesRepository $capabilities,
    ) {
    }

    /**
     * Persist all provider HTTP requests from one Phase 0 probe execution.
     *
     * @param list<array<string,mixed>> $probeRuns
     * @param array<string,string> $evidenceFilesByLabel
     * @return array<string,mixed>
     */
    public function persistProbeExecution(
        int $recordingId,
        int $chunkIndex,
        string $probeExecutionUuid,
        string $sourceAudioSha256,
        string $chunkAudioSha256,
        int $chunkStartTimeMs,
        int $chunkDurationMs,
        ?int $chunkByteLength,
        array $probeRuns,
        array $evidenceFilesByLabel,
        ?string $promptText = null
    ): array {
        EvidenceSchema::requirePersistenceReady($this->pdo);

        $processingRun = $this->processingRuns->createWithUuid(
            $recordingId,
            $probeExecutionUuid,
            EvidenceSchema::RUN_PURPOSE_PHASE0_PROBE
        );
        $processingRunId = (int)$processingRun['id'];

        $chunk = $this->audioChunks->upsert(
            $recordingId,
            $processingRunId,
            $chunkIndex,
            $chunkStartTimeMs,
            $chunkStartTimeMs + $chunkDurationMs,
            $chunkAudioSha256,
            $chunkByteLength
        );
        $audioChunkId = (int)$chunk['id'];

        $promptHash = IdempotencyKeyBuilder::promptHash($promptText);
        $storePromptText = EvidenceSchema::storePromptText();

        $runsOut = array();
        $totalObservations = 0;
        $totalSegments = 0;
        $totalWords = 0;

        foreach ($probeRuns as $probeResult) {
            if (!is_array($probeResult)) {
                continue;
            }
            $label = (string)($probeResult['label'] ?? 'probe');
            $request = is_array($probeResult['request'] ?? null) ? $probeResult['request'] : array();
            $responseMeta = is_array($probeResult['response'] ?? null) ? $probeResult['response'] : array();
            $rawJson = is_array($probeResult['raw_json'] ?? null) ? $probeResult['raw_json'] : null;
            $httpStatus = (int)($probeResult['http_code'] ?? 0);

            $rawPayload = $rawJson ?? array(
                'error' => (string)($probeResult['error'] ?? 'empty_response'),
                'http_status' => $httpStatus,
            );
            $rawString = json_encode($rawPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $responseSha256 = hash('sha256', $rawString);

            $provider = (string)($request['provider'] ?? 'openai');
            $model = (string)($request['model'] ?? 'gpt-4o-transcribe');
            $responseFormat = (string)($request['response_format'] ?? 'json');
            $wordTimestampCount = (int)($responseMeta['word_timestamp_count'] ?? 0);

            $openaiRequestId = isset($responseMeta['openai_request_id']) && is_string($responseMeta['openai_request_id'])
                ? $responseMeta['openai_request_id']
                : null;

            [$errorType, $errorMessage] = self::extractError($rawJson, $probeResult, $httpStatus);

            $insertResult = $this->providerRuns->insertOrGetExisting(array(
                'idempotency_key' => IdempotencyKeyBuilder::forProbePersistenceRetry($probeExecutionUuid, $label),
                'probe_execution_uuid' => $probeExecutionUuid,
                'probe_label' => $label,
                'audio_chunk_id' => $audioChunkId,
                'processing_run_id' => $processingRunId,
                'run_purpose' => EvidenceSchema::RUN_PURPOSE_PHASE0_PROBE,
                'provider' => $provider,
                'model' => $model,
                'provider_reported_model' => self::extractReportedModel($rawJson, $model),
                'response_format' => $responseFormat,
                'request_config' => $request,
                'raw_json' => $rawJson,
                'http_status' => $httpStatus,
                'openai_request_id' => $openaiRequestId,
                'prompt_hash' => $promptHash,
                'prompt_text' => $storePromptText ? $promptText : null,
                'language_forced' => (bool)($request['language_forced'] ?? false),
                'language_code' => isset($request['language']) ? (string)$request['language'] : null,
                'previous_text_used' => (bool)($request['previous_text_conditioning'] ?? false),
                'timestamp_granularities' => is_array($request['timestamp_granularities_requested'] ?? null)
                    ? $request['timestamp_granularities_requested']
                    : null,
                'returned_text' => trim((string)($probeResult['raw_provider_text'] ?? ($rawJson['text'] ?? ''))),
                'source_audio_sha256' => $sourceAudioSha256,
                'chunk_audio_sha256' => $chunkAudioSha256,
                'chunk_start_time_ms' => $chunkStartTimeMs,
                'chunk_duration_ms' => $chunkDurationMs,
                'request_started_at' => isset($probeResult['request_started_at']) ? (string)$probeResult['request_started_at'] : null,
                'request_completed_at' => isset($probeResult['request_completed_at']) ? (string)$probeResult['request_completed_at'] : null,
                'latency_ms' => isset($probeResult['latency_ms']) ? (int)$probeResult['latency_ms'] : null,
                'success_status' => $httpStatus >= 200 && $httpStatus < 300 ? 'success' : 'failed',
                'error_type' => $errorType,
                'error_message' => $errorMessage,
                'capability_observations' => is_array($responseMeta['observed_fields'] ?? null) ? $responseMeta['observed_fields'] : null,
                'evidence_files' => self::evidenceFilesForLabel($evidenceFilesByLabel, $label),
                'word_timestamp_count' => $wordTimestampCount,
                'is_canonical_timeline' => $this->capabilities->supportsSegmentTimestamps($provider, $model, $responseFormat),
            ));

            $providerRunId = (int)($insertResult['row']['id'] ?? 0);
            $obsCount = 0;
            if ($providerRunId > 0) {
                $obsCount = $this->observations->insertObservations(
                    $providerRunId,
                    ProviderObservationRepository::directObservationsFromProbeResult(
                        $probeResult,
                        $responseSha256,
                        $chunkAudioSha256,
                        $sourceAudioSha256
                    )
                );
            }

            $segmentCount = $insertResult['inserted']
                ? (int)$insertResult['segments_persisted']
                : $this->providerRuns->countSegments($providerRunId);
            $wordCount = $insertResult['inserted']
                ? (int)$insertResult['words_persisted']
                : $this->providerRuns->countWordsForProviderRun($providerRunId);

            $totalObservations += $obsCount;
            $totalSegments += $segmentCount;
            $totalWords += $wordCount;

            $runsOut[] = array(
                'probe_label' => $label,
                'provider_run_id' => $providerRunId,
                'provider_run_uuid' => (string)($insertResult['row']['provider_run_uuid'] ?? ''),
                'persistence_status' => $insertResult['inserted'] ? 'inserted' : 'reused',
                'reused_reason' => $insertResult['reused_reason'],
                'observations_inserted' => $obsCount,
                'segments_inserted' => $segmentCount,
                'words_inserted' => $wordCount,
                'raw_response_hash' => (string)($insertResult['row']['response_sha256'] ?? $responseSha256),
                'openai_request_id' => $openaiRequestId,
                'http_status' => $httpStatus,
            );
        }

        $this->processingRuns->markCompleted($processingRunId);

        $schemaVersion = EvidenceSchema::currentSchemaVersion($this->pdo);

        return array(
            'ok' => true,
            'probe_execution_uuid' => $probeExecutionUuid,
            'processing_run_id' => $processingRunId,
            'audio_chunk_id' => $audioChunkId,
            'schema_version' => is_array($schemaVersion) ? (string)($schemaVersion['version'] ?? EvidenceSchema::SCHEMA_VERSION) : EvidenceSchema::SCHEMA_VERSION,
            'provider_runs' => $runsOut,
            'totals' => array(
                'provider_runs' => count($runsOut),
                'observations_inserted' => $totalObservations,
                'segments_inserted' => $totalSegments,
                'words_inserted' => $totalWords,
            ),
            'filesystem_evidence_paths' => $evidenceFilesByLabel,
        );
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self(
            $pdo,
            new ProcessingRunRepository($pdo),
            new AudioChunkRepository($pdo),
            new ProviderRunRepository($pdo),
            new ProviderObservationRepository($pdo),
            new ProviderModelCapabilitiesRepository($pdo),
        );
    }

    /**
     * @param array<string,mixed>|null $rawJson
     * @param array<string,mixed> $probeResult
     * @return array{0:?string,1:?string}
     */
    private static function extractError(?array $rawJson, array $probeResult, int $httpStatus): array
    {
        if ($httpStatus >= 200 && $httpStatus < 300) {
            return array(null, null);
        }
        if (is_array($rawJson['error'] ?? null)) {
            $err = $rawJson['error'];
            return array(
                isset($err['type']) ? (string)$err['type'] : 'provider_error',
                isset($err['message']) ? (string)$err['message'] : (string)($probeResult['error'] ?? 'Provider request failed'),
            );
        }
        $message = (string)($probeResult['error'] ?? 'Provider request failed');
        return array($httpStatus === 400 ? 'invalid_request_error' : 'http_error', $message !== '' ? $message : null);
    }

    /**
     * @param array<string,mixed>|null $rawJson
     */
    private static function extractReportedModel(?array $rawJson, string $requestedModel): ?string
    {
        if (is_array($rawJson) && isset($rawJson['model']) && is_string($rawJson['model']) && $rawJson['model'] !== '') {
            return $rawJson['model'];
        }
        return $requestedModel;
    }

    /**
     * @param array<string,string> $evidenceFilesByLabel
     * @return array<string,string>
     */
    private static function evidenceFilesForLabel(array $evidenceFilesByLabel, string $label): array
    {
        $files = array();
        if (isset($evidenceFilesByLabel['report'])) {
            $files['report'] = $evidenceFilesByLabel['report'];
        }
        if (isset($evidenceFilesByLabel[$label])) {
            $files['raw_json'] = $evidenceFilesByLabel[$label];
        }
        if (isset($evidenceFilesByLabel['primary_raw']) && $label === 'production_json') {
            $files['primary_raw'] = $evidenceFilesByLabel['primary_raw'];
        }
        return $files;
    }
}
