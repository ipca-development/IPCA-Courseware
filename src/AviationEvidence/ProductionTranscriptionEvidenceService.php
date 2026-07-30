<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';
require_once __DIR__ . '/IdempotencyKeyBuilder.php';
require_once __DIR__ . '/ProcessingRunRepository.php';
require_once __DIR__ . '/AudioChunkRepository.php';
require_once __DIR__ . '/ProviderRunRepository.php';
require_once __DIR__ . '/ProviderObservationRepository.php';
require_once __DIR__ . '/ProviderModelCapabilitiesRepository.php';
require_once __DIR__ . '/InterpretationRevisionRepository.php';
require_once __DIR__ . '/EvidencePass4Runner.php';
require_once __DIR__ . '/../CockpitRecorderService.php';

/**
 * Persists typed evidence after normal cockpit recorder transcription completes.
 */
final class ProductionTranscriptionEvidenceService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CockpitRecorderService $recorder,
        private readonly ProcessingRunRepository $processingRuns,
        private readonly AudioChunkRepository $audioChunks,
        private readonly ProviderRunRepository $providerRuns,
        private readonly ProviderObservationRepository $observations,
        private readonly ProviderModelCapabilitiesRepository $capabilities,
        private readonly InterpretationRevisionRepository $interpretations,
    ) {
    }

    /**
     * @param array<string,mixed> $recording
     * @return array<string,mixed>
     */
    public function persistAfterTranscription(int $recordingId, array $recording): array
    {
        if (!EvidenceSchema::persistenceReady($this->pdo)) {
            return array('ok' => false, 'skipped' => true, 'reason' => 'schema_not_ready');
        }
        if (EvidenceSchema::skipProductionPersist()) {
            return array('ok' => false, 'skipped' => true, 'reason' => 'env_skip');
        }
        if ((string)($recording['transcription_status'] ?? '') !== 'ready') {
            return array('ok' => false, 'skipped' => true, 'reason' => 'transcription_not_ready');
        }

        $legacyChunks = $this->legacyChunksForEvidence($recordingId, $recording);
        if ($legacyChunks === array()) {
            return array('ok' => false, 'skipped' => true, 'reason' => 'no_transcript_chunks');
        }

        try {
            $audioPath = $this->recorder->resolveTranscriptionAudioPath($recording);
        } catch (Throwable $e) {
            return array('ok' => false, 'skipped' => true, 'reason' => 'audio_unavailable', 'error' => $e->getMessage());
        }

        $sourceAudioSha256 = hash_file('sha256', $audioPath) ?: '';
        if ($sourceAudioSha256 === '') {
            return array('ok' => false, 'skipped' => true, 'reason' => 'source_hash_failed');
        }

        $transcriptionCompletedAt = (string)($recording['transcription_completed_at'] ?? '');
        if ($transcriptionCompletedAt === '') {
            $transcriptionCompletedAt = gmdate('Y-m-d H:i:s');
        }

        $executionUuid = IdempotencyKeyBuilder::productionExecutionUuid(
            $recordingId,
            $transcriptionCompletedAt,
            $sourceAudioSha256
        );

        $existingRun = $this->processingRuns->findByRunUuid($executionUuid);
        if ($existingRun !== null && (string)($existingRun['status'] ?? '') === 'completed') {
            return array(
                'ok' => true,
                'skipped' => true,
                'reason' => 'already_persisted',
                'processing_run_id' => (int)($existingRun['id'] ?? 0),
                'execution_uuid' => $executionUuid,
            );
        }

        $productionModel = trim((string)(getenv('CW_OPENAI_ASR_MODEL') ?: 'gpt-4o-transcribe'));
        if ($productionModel === '') {
            $productionModel = 'gpt-4o-transcribe';
        }
        $language = CockpitRecorderService::normalizeLanguage((string)($recording['language'] ?? 'en'));
        $sourceDurationMs = (int)round(max(0.0, (float)($recording['duration_seconds'] ?? 0)) * 1000.0);
        $skipWhisper = EvidenceSchema::productionSkipWhisper();

        $processingRun = $this->processingRuns->createWithUuid(
            $recordingId,
            $executionUuid,
            EvidenceSchema::RUN_PURPOSE_INITIAL
        );
        $processingRunId = (int)($processingRun['id'] ?? 0);
        if ($processingRunId <= 0) {
            throw new RuntimeException('Failed to create processing run for recording ' . $recordingId);
        }

        $runsOut = array();
        $totalSegments = 0;
        $totalWords = 0;
        $totalObservations = 0;

        foreach ($legacyChunks as $chunk) {
            $chunkIndex = (int)($chunk['chunk_index'] ?? 0);
            $startSeconds = (float)($chunk['start_seconds'] ?? 0);
            $endSeconds = (float)($chunk['end_seconds'] ?? ($startSeconds + CockpitRecorderService::TRANSCRIPTION_CHUNK_SECONDS));
            $chunkDurationMs = (int)round(max(1.0, $endSeconds - $startSeconds) * 1000.0);
            $chunkStartTimeMs = (int)round(max(0.0, $startSeconds) * 1000.0);
            $chunkText = trim((string)($chunk['transcript_text'] ?? ''));
            if ($chunkText === '') {
                continue;
            }

            $chunkPath = '';
            try {
                $chunkPath = $this->recorder->extractTranscriptionChunkForEvidence(
                    $recording,
                    $chunkIndex,
                    $startSeconds,
                    max(1.0, $endSeconds - $startSeconds)
                );
                $chunkAudioSha256 = hash_file('sha256', $chunkPath) ?: '';
                $chunkByteLength = is_file($chunkPath) ? (int)filesize($chunkPath) : null;
                $mime = mime_content_type($chunkPath) ?: 'audio/mp4';

                $audioChunk = $this->audioChunks->upsert(
                    $recordingId,
                    $processingRunId,
                    $chunkIndex,
                    $chunkStartTimeMs,
                    $chunkStartTimeMs + $chunkDurationMs,
                    $chunkAudioSha256,
                    $chunkByteLength
                );
                $audioChunkId = (int)($audioChunk['id'] ?? 0);

                $productionResult = $this->persistProviderRun(
                    $executionUuid,
                    $processingRunId,
                    $audioChunkId,
                    $chunkIndex,
                    'production_json',
                    $productionModel,
                    'json',
                    array(
                        'provider' => 'openai',
                        'model' => $productionModel,
                        'response_format' => 'json',
                        'language_forced' => $language !== '',
                        'language' => $language !== '' ? $language : null,
                    ),
                    array('text' => $chunkText, 'task' => 'transcribe'),
                    200,
                    $chunkText,
                    $sourceAudioSha256,
                    $chunkAudioSha256,
                    $chunkStartTimeMs,
                    $chunkDurationMs,
                    $sourceDurationMs,
                    $language,
                    false,
                    0,
                    0
                );
                $runsOut[] = $productionResult['summary'];
                $totalObservations += $productionResult['observations'];
                $totalSegments += $productionResult['segments'];
                $totalWords += $productionResult['words'];

                if (!$skipWhisper) {
                    $whisperResult = $this->recorder->transcribeOpenAiAudioStructured(
                        $chunkPath,
                        $mime,
                        basename($chunkPath),
                        $language,
                        'whisper-1',
                        'verbose_json',
                        true,
                        true
                    );
                    $whisperJson = is_array($whisperResult['raw_json'] ?? null) ? $whisperResult['raw_json'] : array();
                    $whisperText = trim((string)($whisperResult['text'] ?? ''));
                    $whisperSegments = is_array($whisperJson['segments'] ?? null) ? $whisperJson['segments'] : array();
                    $wordCount = 0;
                    foreach ($whisperSegments as $segment) {
                        if (is_array($segment['words'] ?? null)) {
                            $wordCount += count($segment['words']);
                        }
                    }

                    $whisperPersist = $this->persistProviderRun(
                        $executionUuid,
                        $processingRunId,
                        $audioChunkId,
                        $chunkIndex,
                        'whisper1_verbose_json',
                        'whisper-1',
                        'verbose_json',
                        array(
                            'provider' => 'openai',
                            'model' => 'whisper-1',
                            'response_format' => 'verbose_json',
                            'language_forced' => $language !== '',
                            'language' => $language !== '' ? $language : null,
                            'timestamp_granularities_requested' => array('word', 'segment'),
                        ),
                        $whisperJson,
                        (int)($whisperResult['http_code'] ?? 0),
                        $whisperText,
                        $sourceAudioSha256,
                        $chunkAudioSha256,
                        $chunkStartTimeMs,
                        $chunkDurationMs,
                        $sourceDurationMs,
                        $language,
                        $this->capabilities->supportsSegmentTimestamps('openai', 'whisper-1', 'verbose_json'),
                        count($whisperSegments),
                        $wordCount,
                        isset($whisperResult['openai_request_id']) ? (string)$whisperResult['openai_request_id'] : null,
                        isset($whisperResult['latency_ms']) ? (int)$whisperResult['latency_ms'] : null,
                        isset($whisperResult['request_started_at']) ? (string)$whisperResult['request_started_at'] : null,
                        isset($whisperResult['request_completed_at']) ? (string)$whisperResult['request_completed_at'] : null
                    );
                    $runsOut[] = $whisperPersist['summary'];
                    $totalObservations += $whisperPersist['observations'];
                    $totalSegments += $whisperPersist['segments'];
                    $totalWords += $whisperPersist['words'];
                }
            } finally {
                if ($chunkPath !== '' && is_file($chunkPath)) {
                    @unlink($chunkPath);
                }
            }
        }

        $pass4Result = null;
        if (!$skipWhisper && EvidenceSchema::runPass4AfterPersist() && EvidenceSchema::pass4Ready($this->pdo)) {
            try {
                $pass4Result = EvidencePass4Runner::fromPdo($this->pdo)->runForProcessingRun($processingRunId);
            } catch (Throwable $e) {
                $pass4Result = array('ok' => false, 'error' => $e->getMessage());
            }
        }

        $this->processingRuns->markCompleted($processingRunId);
        $this->updateRecordingProcessingRun($recordingId, $processingRunId, $pass4Result);

        return array(
            'ok' => true,
            'skipped' => false,
            'execution_uuid' => $executionUuid,
            'processing_run_id' => $processingRunId,
            'provider_runs' => $runsOut,
            'totals' => array(
                'provider_runs' => count($runsOut),
                'observations_inserted' => $totalObservations,
                'segments_inserted' => $totalSegments,
                'words_inserted' => $totalWords,
            ),
            'pass_4' => $pass4Result,
        );
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self(
            $pdo,
            new CockpitRecorderService($pdo),
            new ProcessingRunRepository($pdo),
            new AudioChunkRepository($pdo),
            new ProviderRunRepository($pdo),
            new ProviderObservationRepository($pdo),
            new ProviderModelCapabilitiesRepository($pdo),
            new InterpretationRevisionRepository($pdo),
        );
    }

    /**
     * @param array<string,mixed> $recording
     * @return list<array<string,mixed>>
     */
    private function legacyChunksForEvidence(int $recordingId, array $recording): array
    {
        $chunks = $this->recorder->listLegacyTranscriptionChunks($recordingId);
        $ready = array();
        foreach ($chunks as $chunk) {
            if ((string)($chunk['status'] ?? '') !== 'ready') {
                continue;
            }
            $text = trim((string)($chunk['transcript_text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $ready[] = $chunk;
        }
        if ($ready !== array()) {
            return $ready;
        }

        $text = trim((string)($recording['transcript_text'] ?? ''));
        if ($text === '') {
            return array();
        }

        $duration = max(1.0, (float)($recording['duration_seconds'] ?? CockpitRecorderService::TRANSCRIPTION_CHUNK_SECONDS));
        return array(array(
            'chunk_index' => 0,
            'start_seconds' => 0.0,
            'end_seconds' => $duration,
            'transcript_text' => $text,
            'status' => 'ready',
        ));
    }

    /**
     * @param array<string,mixed> $requestConfig
     * @param array<string,mixed> $rawJson
     * @return array{summary: array<string,mixed>, observations: int, segments: int, words: int}
     */
    private function persistProviderRun(
        string $executionUuid,
        int $processingRunId,
        int $audioChunkId,
        int $chunkIndex,
        string $probeLabel,
        string $model,
        string $responseFormat,
        array $requestConfig,
        array $rawJson,
        int $httpStatus,
        string $returnedText,
        string $sourceAudioSha256,
        string $chunkAudioSha256,
        int $chunkStartTimeMs,
        int $chunkDurationMs,
        int $sourceDurationMs,
        string $language,
        bool $isCanonicalTimeline,
        int $segmentCount,
        int $wordCount,
        ?string $openaiRequestId = null,
        ?int $latencyMs = null,
        ?string $requestStartedAt = null,
        ?string $requestCompletedAt = null
    ): array {
        $probeResult = array(
            'ok' => $httpStatus >= 200 && $httpStatus < 300,
            'label' => $probeLabel,
            'http_code' => $httpStatus,
            'request' => $requestConfig,
            'response' => array(
                'openai_request_id' => $openaiRequestId,
                'segment_count' => $segmentCount,
                'word_timestamp_count' => $wordCount,
            ),
            'raw_provider_text' => $returnedText,
            'raw_json' => $rawJson,
            'request_started_at' => $requestStartedAt,
            'request_completed_at' => $requestCompletedAt,
            'latency_ms' => $latencyMs,
        );

        $rawString = json_encode($rawJson, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $responseSha256 = hash('sha256', $rawString);
        [$errorType, $errorMessage] = self::extractError($rawJson, $httpStatus);

        $insertResult = $this->providerRuns->insertOrGetExisting(array(
            'idempotency_key' => IdempotencyKeyBuilder::forProductionTranscriptionChunk($executionUuid, $chunkIndex, $probeLabel),
            'probe_execution_uuid' => $executionUuid,
            'probe_label' => $probeLabel,
            'audio_chunk_id' => $audioChunkId,
            'processing_run_id' => $processingRunId,
            'run_purpose' => EvidenceSchema::RUN_PURPOSE_INITIAL,
            'provider' => 'openai',
            'model' => $model,
            'provider_reported_model' => isset($rawJson['model']) && is_string($rawJson['model']) ? $rawJson['model'] : $model,
            'response_format' => $responseFormat,
            'request_config' => $requestConfig,
            'raw_json' => $rawJson,
            'http_status' => $httpStatus,
            'openai_request_id' => $openaiRequestId,
            'language_forced' => $language !== '',
            'language_code' => $language !== '' ? $language : null,
            'returned_text' => $returnedText,
            'source_audio_sha256' => $sourceAudioSha256,
            'chunk_audio_sha256' => $chunkAudioSha256,
            'chunk_start_time_ms' => $chunkStartTimeMs,
            'chunk_duration_ms' => $chunkDurationMs,
            'source_audio_duration_ms' => $sourceDurationMs,
            'request_started_at' => $requestStartedAt,
            'request_completed_at' => $requestCompletedAt,
            'latency_ms' => $latencyMs,
            'success_status' => $httpStatus >= 200 && $httpStatus < 300 ? 'success' : 'failed',
            'error_type' => $errorType,
            'error_message' => $errorMessage,
            'word_timestamp_count' => $wordCount,
            'is_canonical_timeline' => $isCanonicalTimeline,
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

        return array(
            'summary' => array(
                'probe_label' => $probeLabel,
                'chunk_index' => $chunkIndex,
                'provider_run_id' => $providerRunId,
                'persistence_status' => $insertResult['inserted'] ? 'inserted' : 'reused',
                'segments_inserted' => $insertResult['inserted']
                    ? (int)$insertResult['segments_persisted']
                    : $this->providerRuns->countSegments($providerRunId),
            ),
            'observations' => $obsCount,
            'segments' => $insertResult['inserted']
                ? (int)$insertResult['segments_persisted']
                : $this->providerRuns->countSegments($providerRunId),
            'words' => $insertResult['inserted']
                ? (int)$insertResult['words_persisted']
                : $this->providerRuns->countWordsForProviderRun($providerRunId),
        );
    }

    /**
     * @param array<string,mixed>|null $pass4Result
     */
    private function updateRecordingProcessingRun(int $recordingId, int $processingRunId, ?array $pass4Result): void
    {
        $readableText = null;
        if (is_array($pass4Result) && !empty($pass4Result['ok']) && empty($pass4Result['skipped'])) {
            $readable = $this->interpretations->findLatestReadableForProcessingRun($processingRunId);
            if (is_array($readable)) {
                $readableText = trim((string)($readable['text'] ?? ''));
            }
        }

        $columnPresent = false;
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS"
                . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'current_processing_run_id'"
            );
            $stmt->execute(array('ipca_cockpit_recordings'));
            $columnPresent = (int)$stmt->fetchColumn() > 0;
        } catch (Throwable) {
            $columnPresent = false;
        }

        if ($readableText !== null && $readableText !== '') {
            if ($columnPresent) {
                $this->pdo->prepare(
                    'UPDATE ipca_cockpit_recordings SET transcript_text = ?, current_processing_run_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
                )->execute(array($readableText, $processingRunId, $recordingId));
            } else {
                $this->pdo->prepare(
                    'UPDATE ipca_cockpit_recordings SET transcript_text = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
                )->execute(array($readableText, $recordingId));
            }
            return;
        }

        if ($columnPresent) {
            $this->pdo->prepare(
                'UPDATE ipca_cockpit_recordings SET current_processing_run_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            )->execute(array($processingRunId, $recordingId));
        }
    }

    /**
     * @param array<string,mixed> $rawJson
     * @return array{0:?string,1:?string}
     */
    private static function extractError(array $rawJson, int $httpStatus): array
    {
        if ($httpStatus >= 200 && $httpStatus < 300) {
            return array(null, null);
        }
        if (is_array($rawJson['error'] ?? null)) {
            $err = $rawJson['error'];
            return array(
                isset($err['type']) ? (string)$err['type'] : 'provider_error',
                isset($err['message']) ? (string)$err['message'] : 'Provider request failed',
            );
        }
        return array($httpStatus === 400 ? 'invalid_request_error' : 'http_error', 'Provider request failed');
    }
}
