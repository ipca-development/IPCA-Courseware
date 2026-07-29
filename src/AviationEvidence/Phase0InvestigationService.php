<?php
declare(strict_types=1);

require_once __DIR__ . '/../CockpitRecorderService.php';
require_once __DIR__ . '/../openai.php';
require_once __DIR__ . '/EvidenceSchema.php';
require_once __DIR__ . '/ProviderRunPersister.php';
require_once __DIR__ . '/Phase0PersistenceVerifier.php';

final class Phase0InvestigationService
{
    public const LOOP_PATTERNS = array(
        'snowball_traffic' => 'Snowball traffic',
        'night_three_yankee' => 'Night Three Yankee',
        'night_3_yankee' => 'Night 3 Yankee',
        'snowball_normal' => 'Snowball traffic, Normal',
        'on_final_30' => "we're on final for 30",
    );

    public const HALLUCINATION_PATTERNS = array(
        'population_1038' => 'population of 1,038',
        'municipality' => 'municipality',
        'microsoft_garage' => 'Microsoft Garage',
        'czech_republic' => 'Czech Republic',
        'thermal_treatment' => 'thermal treatment of the material',
        'pilot_program_malaysia' => 'pilot program in Malaysia',
        'food_list' => 'most common types of food',
        'top_flight_english' => 'top flight of English football',
        'nieuwe_tonge' => 'Nieuwe-Tonge',
        'english_football_club' => "club's first in the top flight",
    );

    public function __construct(
        private readonly PDO $pdo,
        private readonly CockpitRecorderService $recorderService,
    ) {
    }

    /**
     * @return array<string, list<array<string,mixed>>|array<string, string>>
     */
    public function findAffected(): array
    {
        $queries = array(
            'snowball' => "SELECT id, recording_uid, duration_seconds, transcription_status, CHAR_LENGTH(transcript_text) AS text_len FROM ipca_cockpit_recordings WHERE transcript_text LIKE '%Snowball traffic%' ORDER BY id DESC LIMIT 5",
            'night_three_yankee' => "SELECT id, recording_uid, duration_seconds, transcription_status, CHAR_LENGTH(transcript_text) AS text_len FROM ipca_cockpit_recordings WHERE transcript_text LIKE '%Night Three Yankee%' ORDER BY id DESC LIMIT 5",
            'encyclopedia_population' => "SELECT id, recording_uid, duration_seconds, transcription_status, CHAR_LENGTH(transcript_text) AS text_len FROM ipca_cockpit_recordings WHERE transcript_text LIKE '%population of 1,038%' ORDER BY id DESC LIMIT 5",
            'encyclopedia_municipality' => "SELECT id, recording_uid, duration_seconds, transcription_status, CHAR_LENGTH(transcript_text) AS text_len FROM ipca_cockpit_recordings WHERE transcript_text LIKE '%municipality%' AND transcript_text LIKE '%province%' ORDER BY id DESC LIMIT 5",
        );
        $results = array();
        foreach ($queries as $key => $sql) {
            try {
                $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                if ($rows) {
                    $results[$key] = $rows;
                }
            } catch (Throwable $e) {
                $results[$key] = array(array('error' => $e->getMessage()));
            }
        }
        return $results;
    }

    /**
     * Search all retained transcript evidence for encyclopedia-style hallucination phrases.
     *
     * @return array<string,mixed>
     */
    public function searchHallucinationHistory(): array
    {
        $sources = array(
            array('table' => 'ipca_cockpit_recordings', 'id_col' => 'id', 'text_col' => 'transcript_text', 'extra' => 'recording_uid'),
            array('table' => 'ipca_cockpit_recording_transcription_chunks', 'id_col' => 'recording_id', 'text_col' => 'transcript_text', 'extra' => 'chunk_index'),
        );

        $hits = array();
        foreach (self::HALLUCINATION_PATTERNS as $key => $phrase) {
            if ($phrase === '') {
                continue;
            }
            foreach ($sources as $source) {
                $sql = 'SELECT ' . $source['id_col'] . ' AS source_id, ' . $source['extra']
                    . ', LEFT(' . $source['text_col'] . ', 240) AS excerpt'
                    . ' FROM ' . $source['table']
                    . ' WHERE ' . $source['text_col'] . ' LIKE ? LIMIT 5';
                try {
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute(array('%' . $phrase . '%'));
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
                    if ($rows) {
                        $hits[$key][] = array(
                            'phrase' => $phrase,
                            'source_table' => $source['table'],
                            'matches' => $rows,
                        );
                    }
                } catch (Throwable $e) {
                    $hits[$key][] = array('error' => $e->getMessage(), 'source_table' => $source['table']);
                }
            }
        }

        if ($this->tableExists('ipca_cockpit_transcript_snapshots')) {
            foreach (self::HALLUCINATION_PATTERNS as $key => $phrase) {
                if ($phrase === '') {
                    continue;
                }
                $stmt = $this->pdo->prepare(
                    'SELECT cockpit_recording_id, snapshot_uuid, locked_at, LEFT(transcript_text, 240) AS excerpt
                     FROM ipca_cockpit_transcript_snapshots WHERE transcript_text LIKE ? LIMIT 5'
                );
                $stmt->execute(array('%' . $phrase . '%'));
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
                if ($rows) {
                    $hits[$key][] = array(
                        'phrase' => $phrase,
                        'source_table' => 'ipca_cockpit_transcript_snapshots',
                        'matches' => $rows,
                    );
                }
            }
        }

        $exactPhrases = array(
            'population of 1,038',
            'The municipality has a population of 1,038',
            'thermal treatment of the material',
            'Microsoft Garage',
            'Czech Republic',
            'top flight of English football',
            'Nieuwe-Tonge',
        );
        $exactHits = array();
        foreach ($exactPhrases as $phrase) {
            foreach (array('ipca_cockpit_recordings', 'ipca_cockpit_recording_transcription_chunks') as $table) {
                $idCol = $table === 'ipca_cockpit_recordings' ? 'id' : 'recording_id';
                $stmt = $this->pdo->prepare("SELECT {$idCol} AS id FROM {$table} WHERE transcript_text LIKE ? LIMIT 3");
                $stmt->execute(array('%' . $phrase . '%'));
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
                if ($rows) {
                    $exactHits[] = array('phrase' => $phrase, 'table' => $table, 'ids' => $rows);
                }
            }
        }

        $provenance = array(
            'exact_phrase_hits' => $exactHits,
            'pattern_hits' => $hits,
            'archived_provider_responses_available' => false,
            'reprocessing_log_table_available' => false,
            'conclusion' => '',
        );

        if ($exactHits === array() && $hits === array()) {
            $provenance['conclusion'] = 'The source of the encyclopedia-style passages cannot be proven because the previous processing pipeline did not preserve immutable raw provider responses. No exact representative phrases remain in ipca_cockpit_recordings, ipca_cockpit_recording_transcription_chunks, or ipca_cockpit_transcript_snapshots.';
        } elseif ($exactHits !== array() || $hits !== array()) {
            $provenance['conclusion'] = 'Partial hallucination-related text found in retained transcript layers only (not in archived provider JSON — none exists). See pattern_hits and exact_phrase_hits for recording/chunk locations.';
        }

        $provenance['notes'] = array(
            'No ipca_evidence_provider_runs or raw_response_json archive exists in the current schema.',
            'resetTranscriptionForRetry() deletes chunk rows; prior versions are not retained.',
            'cleanupStoredTranscript() and cleanTranscriptText() may have removed hallucination blocks from current transcript_text.',
            'Transcript snapshots (if any) only store final text, not provider responses.',
        );

        return $provenance;
    }

    /**
     * Mandatory Phase 0 provider probe: preserve full raw JSON, three-way text comparison, segment timing.
     *
     * @return array<string,mixed>
     */
    public function runMandatoryProviderProbe(
        int $recordingId,
        int $chunkIndex,
        bool $saveEvidence = true,
        string $saveDir = 'storage/cockpit_recorder/phase0_evidence',
        int $persistMode = 0,
        bool $persistFallbackFilesystem = false
    ): array {
        $probeExecutionUuid = self::uuid();
        $recording = $this->recorderService->recordingByAnyId((string)$recordingId);
        if (!is_array($recording)) {
            return array('ok' => false, 'error' => 'Recording not found.');
        }

        $chunkStmt = $this->pdo->prepare(
            'SELECT * FROM ipca_cockpit_recording_transcription_chunks WHERE recording_id = ? AND chunk_index = ? LIMIT 1'
        );
        $chunkStmt->execute(array($recordingId, $chunkIndex));
        $chunkRow = $chunkStmt->fetch(PDO::FETCH_ASSOC);
        $storedChunkText = is_array($chunkRow) ? trim((string)($chunkRow['transcript_text'] ?? '')) : '';

        $audioPath = null;
        try {
            $ref = new ReflectionMethod(CockpitRecorderService::class, 'transcriptionAudioPath');
            $audioPath = (string)$ref->invoke($this->recorderService, $recording);
        } catch (Throwable $e) {
            return array('ok' => false, 'error' => 'Audio path resolution failed: ' . $e->getMessage());
        }
        if ($audioPath === '' || !is_file($audioPath)) {
            return array(
                'ok' => false,
                'error' => 'Audio file not available on this host. Run on App Platform where storage/cockpit_recorder/audio exists.',
                'expected_path' => (string)($recording['storage_path'] ?? ''),
            );
        }

        $start = $chunkIndex * 300.0;
        $duration = min(300.0, max(1.0, (float)($recording['duration_seconds'] ?? 300) - $start));
        $chunkAudioPath = self::extractChunk($audioPath, $start, $duration, $chunkIndex);
        if ($chunkAudioPath === null) {
            return array('ok' => false, 'error' => 'Failed to extract chunk audio via ffmpeg.');
        }

        $sourceAudioSha256 = hash_file('sha256', $audioPath) ?: '';
        $chunkAudioSha256 = hash_file('sha256', $chunkAudioPath) ?: '';
        $chunkByteLength = is_file($chunkAudioPath) ? (int)filesize($chunkAudioPath) : null;
        $chunkStartTimeMs = (int)round($start * 1000.0);
        $chunkDurationMs = (int)round($duration * 1000.0);

        $model = trim((string)(getenv('CW_OPENAI_ASR_MODEL') ?: 'gpt-4o-transcribe'));
        if ($model === '') {
            $model = 'gpt-4o-transcribe';
        }
        $language = self::normalizeLanguage((string)($recording['language'] ?? 'en'));
        $prompt = self::transcriptionPrompt();

        $probeRuns = array();
        $specs = array(
            array('label' => 'production_json', 'model' => $model, 'response_format' => 'json', 'word_timestamps' => false, 'match_production' => true),
            array('label' => 'production_verbose_json', 'model' => $model, 'response_format' => 'verbose_json', 'word_timestamps' => true, 'match_production' => true),
            array('label' => 'whisper1_verbose_json', 'model' => 'whisper-1', 'response_format' => 'verbose_json', 'word_timestamps' => true, 'match_production' => false),
        );

        foreach ($specs as $spec) {
            $probeRuns[] = self::executeProviderProbe(
                $chunkAudioPath,
                $spec['model'],
                $language,
                $prompt,
                $spec['response_format'],
                $spec['word_timestamps'],
                $spec['label'],
                (bool)$spec['match_production']
            );
        }

        @unlink($chunkAudioPath);

        $primary = null;
        foreach ($probeRuns as $run) {
            if (!empty($run['ok']) && ($run['label'] ?? '') === 'production_verbose_json') {
                $primary = $run;
                break;
            }
        }
        if ($primary === null) {
            foreach ($probeRuns as $run) {
                if (!empty($run['ok']) && ($run['label'] ?? '') === 'production_json') {
                    $primary = $run;
                    break;
                }
            }
        }
        if ($primary === null) {
            foreach ($probeRuns as $run) {
                if (!empty($run['ok'])) {
                    $primary = $run;
                    break;
                }
            }
        }

        $threeWay = array('observation' => 'No successful provider response.');
        $firstLoopLayer = null;
        $segmentTiming = null;

        if (is_array($primary)) {
            $rawProviderText = trim((string)($primary['raw_provider_text'] ?? ''));
            $postCleanText = self::cleanTranscriptText($rawProviderText);

            $layerCounts = array(
                'raw_provider_text' => self::loopPatternCounts($rawProviderText),
                'post_per_chunk_clean' => self::loopPatternCounts($postCleanText),
                'stored_chunk_table' => self::loopPatternCounts($storedChunkText),
            );

            $mergedPreview = self::mergeTranscriptParts(array($storedChunkText));
            $displayClean = self::cleanTranscriptText($mergedPreview);
            $layerCounts['merged_simulation'] = self::loopPatternCounts($mergedPreview);
            $layerCounts['display_clean_simulation'] = self::loopPatternCounts($displayClean);

            $firstLoopLayer = self::firstLayerWithFullLoop($layerCounts, 40);

            $threeWay = array(
                'raw_provider_text' => array(
                    'length' => strlen($rawProviderText),
                    'preview' => substr($rawProviderText, 0, 400),
                    'loop_counts' => $layerCounts['raw_provider_text'],
                ),
                'post_per_chunk_clean' => array(
                    'length' => strlen($postCleanText),
                    'identical_to_stored' => $postCleanText === $storedChunkText,
                    'loop_counts' => $layerCounts['post_per_chunk_clean'],
                ),
                'stored_chunk_table' => array(
                    'length' => strlen($storedChunkText),
                    'loop_counts' => $layerCounts['stored_chunk_table'],
                ),
                'first_layer_with_snowball_40plus' => $firstLoopLayer,
                'inferred_conclusion' => self::inferLoopOriginConclusion($firstLoopLayer, $layerCounts),
            );

            if (is_array($primary['raw_json']['segments'] ?? null)) {
                $segmentTiming = self::analyzeRepeatedSegmentTimestamps(
                    $primary['raw_json']['segments'],
                    array('Snowball', 'Night 3 Yankee', 'Night Three Yankee', 'on final for 30')
                );
            } else {
                $segmentTiming = array(
                    'segment_array_present' => false,
                    'single_text_field_only' => $rawProviderText !== '',
                    'note' => 'Provider returned one text field without segment array; timestamp-based loop detection must use word timestamps or external VAD.',
                );
            }
        }

        $report = array(
            'ok' => is_array($primary) && !empty($primary['ok']),
            'generated_at' => gmdate('c'),
            'probe_execution_uuid' => $probeExecutionUuid,
            'recording_id' => $recordingId,
            'chunk_index' => $chunkIndex,
            'chunk_audio' => array(
                'source_sha256' => $sourceAudioSha256,
                'chunk_sha256' => $chunkAudioSha256,
                'chunk_start_seconds' => $start,
                'chunk_duration_seconds' => $duration,
                'chunk_byte_length' => $chunkByteLength,
            ),
            'probe_runs' => array_map(static function (array $run): array {
                $copy = $run;
                unset($copy['raw_json']);
                return $copy;
            }, $probeRuns),
            'three_way_comparison' => $threeWay,
            'segment_timing_analysis' => $segmentTiming,
            'hallucination_history' => $this->searchHallucinationHistory(),
            'pass_4b_detector_priority_evidence' => self::pass4bDetectorNotes($segmentTiming, $primary),
        );

        $evidenceFilesByLabel = array();

        if ($saveEvidence) {
            $absDir = str_starts_with($saveDir, '/') ? $saveDir : CockpitRecorderService::projectRoot() . '/' . ltrim($saveDir, '/');
            if (!is_dir($absDir)) {
                @mkdir($absDir, 0775, true);
            }
            $base = $absDir . '/recording_' . $recordingId . '_chunk_' . $chunkIndex . '_' . gmdate('Ymd_His');
            file_put_contents($base . '_report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $evidenceFilesByLabel['report'] = $base . '_report.json';
            if (is_array($primary) && is_array($primary['raw_json'] ?? null)) {
                file_put_contents($base . '_provider_raw.json', json_encode($primary['raw_json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $evidenceFilesByLabel['primary_raw'] = $base . '_provider_raw.json';
            }
            foreach ($probeRuns as $run) {
                if (!is_array($run['raw_json'] ?? null)) {
                    continue;
                }
                $label = preg_replace('/[^a-z0-9_]+/i', '_', (string)($run['label'] ?? 'probe')) ?? 'probe';
                $path = $base . '_' . $label . '_raw.json';
                file_put_contents($path, json_encode($run['raw_json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $evidenceFilesByLabel[$label] = $path;
            }
            $report['evidence_files'] = array(
                'report' => $base . '_report.json',
                'primary_raw' => $base . '_provider_raw.json',
            ) + $evidenceFilesByLabel;
        }

        if (is_array($primary) && is_array($primary['raw_json'] ?? null)) {
            $report['primary_raw_json'] = $primary['raw_json'];
        }

        $report['persistence'] = $this->maybePersistProbeExecution(
            $persistMode,
            $persistFallbackFilesystem,
            $recordingId,
            $chunkIndex,
            $probeExecutionUuid,
            $sourceAudioSha256,
            $chunkAudioSha256,
            $chunkStartTimeMs,
            $chunkDurationMs,
            $chunkByteLength,
            $probeRuns,
            $evidenceFilesByLabel,
            $prompt
        );

        return $report;
    }

    /**
     * @param list<array<string,mixed>> $probeRuns
     * @param array<string,string> $evidenceFilesByLabel
     * @return array<string,mixed>
     */
    private function maybePersistProbeExecution(
        int $persistMode,
        bool $persistFallbackFilesystem,
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
        string $promptText
    ): array {
        if ($persistMode !== 1) {
            return array(
                'enabled' => false,
                'mode' => 'filesystem_only',
                'typed_persistence_attempted' => false,
                'typed_persistence_succeeded' => false,
            );
        }

        if (!EvidenceSchema::persistenceReady($this->pdo)) {
            if ($persistFallbackFilesystem) {
                return array(
                    'enabled' => true,
                    'mode' => 'filesystem_only_fallback',
                    'typed_persistence_attempted' => false,
                    'typed_persistence_succeeded' => false,
                    'warning' => 'Evidence schema not ready. Applied filesystem-only fallback because persist_fallback was requested.',
                    'schema_version' => null,
                );
            }
            return array(
                'enabled' => true,
                'mode' => 'failed_schema_missing',
                'typed_persistence_attempted' => true,
                'typed_persistence_succeeded' => false,
                'error' => 'Evidence schema not ready. Apply ' . EvidenceSchema::MIGRATION_FILE
                    . ' or rerun with persist=0 (filesystem only).',
            );
        }

        try {
            $persister = ProviderRunPersister::fromPdo($this->pdo);
            $result = $persister->persistProbeExecution(
                $recordingId,
                $chunkIndex,
                $probeExecutionUuid,
                $sourceAudioSha256,
                $chunkAudioSha256,
                $chunkStartTimeMs,
                $chunkDurationMs,
                $chunkByteLength,
                $probeRuns,
                $evidenceFilesByLabel,
                $promptText
            );

            $verifier = Phase0PersistenceVerifier::fromPdo($this->pdo);
            $verification = $verifier->verifyProbeExecution($probeExecutionUuid, $evidenceFilesByLabel);

            return array(
                'enabled' => true,
                'mode' => 'typed_and_filesystem',
                'typed_persistence_attempted' => true,
                'typed_persistence_succeeded' => true,
                'probe_execution_uuid' => $probeExecutionUuid,
                'processing_run_id' => $result['processing_run_id'] ?? null,
                'audio_chunk_id' => $result['audio_chunk_id'] ?? null,
                'schema_version' => $result['schema_version'] ?? EvidenceSchema::SCHEMA_VERSION,
                'provider_runs' => $result['provider_runs'] ?? array(),
                'totals' => $result['totals'] ?? array(),
                'filesystem_evidence_paths' => $evidenceFilesByLabel,
                'verification' => $verification,
            );
        } catch (Throwable $e) {
            return array(
                'enabled' => true,
                'mode' => 'failed',
                'typed_persistence_attempted' => true,
                'typed_persistence_succeeded' => false,
                'error' => $e->getMessage(),
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function investigateRecording(int $recordingId, bool $probeProvider = false, int $probeChunk = -1): array
    {
        $recording = $this->recorderService->recordingByAnyId((string)$recordingId);
        if (!is_array($recording)) {
            return array('ok' => false, 'error' => 'Recording not found.');
        }

        $chunkStmt = $this->pdo->prepare('SELECT * FROM ipca_cockpit_recording_transcription_chunks WHERE recording_id = ? ORDER BY chunk_index ASC');
        $chunkStmt->execute(array($recordingId));
        $chunks = $chunkStmt->fetchAll(PDO::FETCH_ASSOC) ?: array();

        $chunkTexts = array();
        foreach ($chunks as $chunk) {
            if ((string)($chunk['status'] ?? '') === 'ready') {
                $chunkTexts[] = trim((string)($chunk['transcript_text'] ?? ''));
            }
        }

        $storedFinal = trim((string)($recording['transcript_text'] ?? ''));
        $mergedSimulated = self::mergeTranscriptParts($chunkTexts);
        $cleanedFromChunks = self::cleanTranscriptText($mergedSimulated);
        $cleanedFromStored = self::cleanTranscriptText($storedFinal);

        $layerTexts = array(
            'stored_final_transcript_text' => $storedFinal,
            'simulated_merge_from_chunks' => $mergedSimulated,
            'simulated_clean_from_chunks' => $cleanedFromChunks,
            'simulated_clean_from_stored_final' => $cleanedFromStored,
        );
        foreach ($chunks as $chunk) {
            $idx = (int)($chunk['chunk_index'] ?? 0);
            $layerTexts['chunk_' . $idx . '_stored'] = trim((string)($chunk['transcript_text'] ?? ''));
        }

        $patternAppearance = array();
        foreach ($layerTexts as $layer => $text) {
            $patternAppearance[$layer] = array_merge(
                self::scanPatterns($text, self::LOOP_PATTERNS),
                self::scanPatterns($text, self::HALLUCINATION_PATTERNS)
            );
        }

        $audioMeta = array('available' => false);
        $audioPath = null;
        try {
            $ref = new ReflectionMethod(CockpitRecorderService::class, 'transcriptionAudioPath');
            $ref->setAccessible(true);
            $audioPath = (string)$ref->invoke($this->recorderService, $recording);
            if ($audioPath !== '' && is_file($audioPath)) {
                $audioMeta = self::ffprobe($audioPath);
                $audioMeta['sha256'] = hash_file('sha256', $audioPath);
                $audioMeta['file_size_bytes'] = filesize($audioPath);
            }
        } catch (Throwable $e) {
            $audioMeta['error'] = $e->getMessage();
        }

        $defaultModel = trim((string)(getenv('CW_OPENAI_ASR_MODEL') ?: 'gpt-4o-transcribe'));
        if ($defaultModel === '') {
            $defaultModel = 'gpt-4o-transcribe';
        }

        $report = array(
            'ok' => true,
            'generated_at' => gmdate('c'),
            'recording' => array(
                'id' => $recordingId,
                'recording_uid' => (string)($recording['recording_uid'] ?? ''),
                'duration_seconds' => (float)($recording['duration_seconds'] ?? 0),
                'transcription_status' => (string)($recording['transcription_status'] ?? ''),
                'language' => (string)($recording['language'] ?? 'en'),
                'storage_path' => (string)($recording['storage_path'] ?? ''),
            ),
            'provider_configuration' => array(
                'production_model_default' => $defaultModel,
                'production_response_format' => 'json',
                'production_segment_timestamps_requested' => false,
                'production_word_timestamps_requested' => false,
                'production_language_forced' => true,
                'production_language_value' => (string)($recording['language'] ?? 'en'),
                'chunk_duration_seconds' => 300,
                'chunk_audio_overlap_seconds' => 0,
                'chunk_boundary_word_overlap_max' => 16,
                'vad_used' => false,
                'previous_text_conditioning' => false,
                'temperature_fallback' => false,
                'raw_provider_json_persisted' => false,
                'cleanTranscriptText_applied_on_provider_response' => true,
                'translation_in_pipeline' => false,
            ),
            'audio' => $audioMeta,
            'chunks' => array_map(static function (array $chunk): array {
                $text = trim((string)($chunk['transcript_text'] ?? ''));
                return array(
                    'chunk_index' => (int)($chunk['chunk_index'] ?? 0),
                    'start_seconds' => (float)($chunk['start_seconds'] ?? 0),
                    'end_seconds' => (float)($chunk['end_seconds'] ?? 0),
                    'status' => (string)($chunk['status'] ?? ''),
                    'text_length' => strlen($text),
                    'repeat_density' => self::repeatDensity($text),
                    'error_message' => (string)($chunk['error_message'] ?? ''),
                );
            }, $chunks),
            'pattern_first_appearance' => $patternAppearance,
            'layer_lengths' => array_map('strlen', $layerTexts),
            'retry_behavior' => array(
                'resetTranscriptionForRetry_deletes_all_chunks' => true,
                'chunk_store_uses_upsert_on_recording_id_chunk_index' => true,
                'reprocess_deletes_chunks_before_new_run' => true,
                'concurrent_worker_risk' => 'Two workers can race on same queued chunk; last upsert wins, no duplicate rows due to UNIQUE(recording_id, chunk_index)',
            ),
            'legacy_transcript_cache_consumers' => array(
                'src/CockpitRecorderService.php',
                'public/admin/api/cockpit_recorder_intake_transcript.php',
                'public/api/recordings/transcript.php',
                'src/FlightDebriefService.php',
                'src/CockpitReconstructionService.php',
                'src/ManualReconstructionBundleService.php',
                'public/admin/cockpit_recorder.php',
            ),
            'raw_evidence_gaps' => array(
                'Provider JSON is discarded; only cleaned text returned from transcribeAudioFile().',
                'Chunk table stores cleaned text only; no raw_response_json column.',
                'resetTranscriptionForRetry() DELETEs all chunk rows; prior chunk evidence is lost on reprocess.',
                'cleanupStoredTranscript() overwrites ipca_cockpit_recordings.transcript_text in place.',
                'No segment timestamps, word timestamps, or provider segment IDs are stored.',
                'No idempotency key or audio SHA-256 on chunk runs.',
            ),
            'provider_probes' => array(),
            'conclusions' => array(),
        );

        $report['conclusions'] = array_merge(
            $report['conclusions'],
            self::layerConclusions($chunks, $chunkTexts, $mergedSimulated, $storedFinal, $cleanedFromStored)
        );

        if ($probeProvider) {
            $targetChunk = $probeChunk >= 0 ? $probeChunk : self::resolveProbeChunk($chunks, $probeChunk);
            $probeReport = $this->runMandatoryProviderProbe($recordingId, $targetChunk, true, 'storage/cockpit_recorder/phase0_evidence', 0, false);
            $report['provider_probe'] = $probeReport;
            if (!empty($probeReport['three_way_comparison']['inferred_conclusion'])) {
                $report['conclusions'][] = (string)$probeReport['three_way_comparison']['inferred_conclusion'];
            }
            $report['hallucination_history'] = $probeReport['hallucination_history'] ?? $this->searchHallucinationHistory();
        } else {
            $report['hallucination_history'] = $this->searchHallucinationHistory();
        }

        if (!$report['conclusions']) {
            $report['conclusions'][] = 'No loop/hallucination patterns matched stored layers for this recording.';
        }

        return $report;
    }

    /**
     * @param array<string,mixed> $report
     */
    public function toMarkdown(array $report): string
    {
        $lines = array(
            '# Cockpit Transcript Phase 0 Investigation',
            '',
            'Generated: ' . (string)($report['generated_at'] ?? gmdate('c')),
            '',
            '## Recording',
            '',
            '- ID: ' . ($report['recording']['id'] ?? 'n/a'),
            '- UID: ' . ($report['recording']['recording_uid'] ?? 'n/a'),
            '- Duration: ' . ($report['recording']['duration_seconds'] ?? 'n/a') . ' s',
            '',
            '## Pattern first appearance',
            '',
        );
        foreach ($report['pattern_first_appearance'] ?? array() as $layer => $patterns) {
            $lines[] = '### ' . $layer;
            foreach ($patterns as $hit) {
                $lines[] = '- `' . $hit['label'] . '`: count=' . $hit['count'] . ', first_offset=' . ($hit['offset'] ?? 'null');
            }
            $lines[] = '';
        }
        $lines[] = '## Conclusions';
        foreach ($report['conclusions'] ?? array() as $conclusion) {
            $lines[] = '- ' . $conclusion;
        }
        $lines[] = '';
        return implode("\n", $lines);
    }

    /**
     * @param list<array<string,mixed>> $chunks
     * @param list<string> $chunkTexts
     * @return list<string>
     */
    private static function layerConclusions(array $chunks, array $chunkTexts, string $mergedSimulated, string $storedFinal, string $cleanedFromStored): array
    {
        $conclusions = array();
        foreach ($chunks as $chunk) {
            $idx = (int)($chunk['chunk_index'] ?? 0);
            $text = trim((string)($chunk['transcript_text'] ?? ''));
            foreach (self::LOOP_PATTERNS as $pattern) {
                if ($pattern !== '' && stripos($text, $pattern) !== false) {
                    $conclusions[] = "Loop pattern appears in stored chunk {$idx} (post-cleanTranscriptText at transcription time).";
                    break;
                }
            }
            foreach (self::HALLUCINATION_PATTERNS as $pattern) {
                if ($pattern !== '' && stripos($text, $pattern) !== false) {
                    $conclusions[] = "Hallucination pattern appears in stored chunk {$idx} (post-cleanTranscriptText at transcription time).";
                    break;
                }
            }
        }

        foreach (array_merge(self::LOOP_PATTERNS, self::HALLUCINATION_PATTERNS) as $pattern) {
            if ($pattern === '') {
                continue;
            }
            $inChunks = false;
            foreach ($chunkTexts as $text) {
                if (stripos($text, $pattern) !== false) {
                    $inChunks = true;
                    break;
                }
            }
            if (!$inChunks && stripos($mergedSimulated, $pattern) !== false) {
                $conclusions[] = "Pattern \"{$pattern}\" first appears only after merge simulation.";
            }
        }

        $lenStored = strlen($storedFinal);
        $lenClean = strlen($cleanedFromStored);
        if ($lenStored > 0 && $lenClean < $lenStored * 0.8) {
            $conclusions[] = "Display cleanup removes substantial text ({$lenStored} -> {$lenClean} chars).";
        }

        return $conclusions;
    }

    /**
     * @param list<array<string,mixed>> $chunks
     */
    private static function resolveProbeChunk(array $chunks, int $probeChunk): int
    {
        if ($probeChunk >= 0) {
            return $probeChunk;
        }
        foreach ($chunks as $chunk) {
            $text = (string)($chunk['transcript_text'] ?? '');
            if (stripos($text, 'Snowball') !== false || stripos($text, 'population of 1,038') !== false) {
                return (int)$chunk['chunk_index'];
            }
        }
        return 0;
    }

    /**
     * @param list<string> $parts
     */
    private static function mergeTranscriptParts(array $parts): string
    {
        $ref = new ReflectionMethod(CockpitRecorderService::class, 'mergeTranscriptParts');
        $ref->setAccessible(true);
        return (string)$ref->invoke(null, $parts);
    }

    private static function cleanTranscriptText(string $text): string
    {
        $ref = new ReflectionMethod(CockpitRecorderService::class, 'cleanTranscriptText');
        $ref->setAccessible(true);
        return (string)$ref->invoke(null, $text);
    }

    /**
     * @param array<string,string> $patterns
     * @return list<array{key:string,label:string,offset:?int,count:int}>
     */
    private static function scanPatterns(string $text, array $patterns): array
    {
        $hits = array();
        foreach ($patterns as $key => $label) {
            $offset = $label === '' ? null : (stripos($text, $label) === false ? null : stripos($text, $label));
            $hits[] = array(
                'key' => (string)$key,
                'label' => (string)$label,
                'offset' => $offset,
                'count' => $label === '' ? 0 : substr_count(strtolower($text), strtolower($label)),
            );
        }
        return $hits;
    }

    /**
     * @return array{total_words:int,unique_words:int,compression_ratio_estimate:float,top_repeats:list<array{word:string,count:int}>}
     */
    private static function repeatDensity(string $text): array
    {
        $normalized = strtolower(trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text));
        $words = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: array();
        $total = count($words);
        if ($total === 0) {
            return array('total_words' => 0, 'unique_words' => 0, 'compression_ratio_estimate' => 0.0, 'top_repeats' => array());
        }
        $freq = array_count_values($words);
        arsort($freq);
        $top = array();
        foreach (array_slice($freq, 0, 10, true) as $word => $count) {
            if ($count < 3) {
                break;
            }
            $top[] = array('word' => (string)$word, 'count' => (int)$count);
        }
        return array(
            'total_words' => $total,
            'unique_words' => count($freq),
            'compression_ratio_estimate' => round($total / max(1, count($freq)), 3),
            'top_repeats' => $top,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function ffprobe(string $path): array
    {
        $ffprobe = self::findBinary(array('/usr/bin/ffprobe', '/usr/local/bin/ffprobe', '/opt/homebrew/bin/ffprobe', 'ffprobe'));
        if ($ffprobe === '') {
            return array('available' => false);
        }
        $cmd = escapeshellcmd($ffprobe) . ' -v quiet -print_format json -show_format -show_streams ' . escapeshellarg($path) . ' 2>&1';
        $json = json_decode((string)@shell_exec($cmd), true);
        if (!is_array($json)) {
            return array('available' => true, 'error' => 'ffprobe non-JSON');
        }
        $stream = is_array($json['streams'][0] ?? null) ? $json['streams'][0] : array();
        return array(
            'available' => true,
            'duration_seconds' => isset($json['format']['duration']) ? (float)$json['format']['duration'] : null,
            'codec_name' => (string)($stream['codec_name'] ?? ''),
            'sample_rate' => isset($stream['sample_rate']) ? (int)$stream['sample_rate'] : null,
            'channels' => isset($stream['channels']) ? (int)$stream['channels'] : null,
        );
    }

    private static function extractChunk(string $sourcePath, float $startSeconds, float $durationSeconds, int $index): ?string
    {
        $ffmpeg = self::findBinary(array('/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg', 'ffmpeg'));
        if ($ffmpeg === '') {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ipca_phase0_');
        if ($tmp === false) {
            return null;
        }
        @unlink($tmp);
        $outPath = $tmp . '_' . $index . '.m4a';
        $cmd = escapeshellcmd($ffmpeg)
            . ' -y -v error -ss ' . escapeshellarg(sprintf('%.3F', max(0.0, $startSeconds)))
            . ' -t ' . escapeshellarg(sprintf('%.3F', max(1.0, $durationSeconds)))
            . ' -i ' . escapeshellarg($sourcePath)
            . ' -vn -ac 1 -ar 44100 -c:a aac -b:a 96k ' . escapeshellarg($outPath) . ' 2>&1';
        (string)@shell_exec($cmd);
        return is_file($outPath) && filesize($outPath) > 0 ? $outPath : null;
    }

    /**
     * @return array<string,mixed>
     */
    private static function executeProviderProbe(
        string $audioPath,
        string $model,
        string $language,
        string $prompt,
        string $responseFormat,
        bool $wordTimestamps,
        string $label,
        bool $matchProduction
    ): array {
        $postFields = array(
            'file' => new CURLFile($audioPath, mime_content_type($audioPath) ?: 'audio/mp4', basename($audioPath)),
            'model' => $model,
            'response_format' => $responseFormat,
        );
        if ($matchProduction || $prompt !== '') {
            $postFields['prompt'] = $prompt;
        }
        if ($language !== '') {
            $postFields['language'] = $language;
        }
        if ($wordTimestamps && $responseFormat === 'verbose_json') {
            $postFields['timestamp_granularities[]'] = 'word';
            $postFields['timestamp_granularities[]'] = 'segment';
        }

        $requestParams = array(
            'provider' => 'openai',
            'endpoint' => '/v1/audio/transcriptions',
            'model' => $model,
            'response_format' => $responseFormat,
            'language_forced' => $language !== '',
            'language' => $language !== '' ? $language : null,
            'timestamp_granularities_requested' => $wordTimestamps ? array('word', 'segment') : array(),
            'previous_text_conditioning' => false,
            'prompt_supplied' => $prompt,
            'match_production_request' => $matchProduction,
        );

        $requestStartedAt = microtime(true);
        $requestStartedIso = gmdate('Y-m-d H:i:s', (int)$requestStartedAt) . '.' . sprintf('%03d', (int)(($requestStartedAt - floor($requestStartedAt)) * 1000));

        $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . cw_openai_key()),
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_TIMEOUT => 900,
            CURLOPT_CONNECTTIMEOUT => 30,
        ));
        $rawResponse = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $requestCompletedAt = microtime(true);
        $latencyMs = (int)round(($requestCompletedAt - $requestStartedAt) * 1000.0);
        $requestCompletedIso = gmdate('Y-m-d H:i:s', (int)$requestCompletedAt) . '.' . sprintf('%03d', (int)(($requestCompletedAt - floor($requestCompletedAt)) * 1000));

        $headersRaw = is_string($rawResponse) ? substr($rawResponse, 0, $headerSize) : '';
        $body = is_string($rawResponse) ? substr($rawResponse, $headerSize) : '';
        $json = is_string($body) ? json_decode($body, true) : null;
        $segments = is_array($json['segments'] ?? null) ? $json['segments'] : array();

        $wordCount = 0;
        foreach ($segments as $segment) {
            if (is_array($segment['words'] ?? null)) {
                $wordCount += count($segment['words']);
            }
        }

        $rawText = trim((string)($json['text'] ?? ''));
        $responseHeaders = array();
        foreach (explode("\r\n", $headersRaw) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($k))] = trim($v);
            }
        }

        $observedFields = array(
            'text' => isset($json['text']),
            'language' => $json['language'] ?? null,
            'duration' => $json['duration'] ?? null,
            'segments' => count($segments) > 0,
            'task' => $json['task'] ?? null,
            'words_in_segments' => $wordCount,
            'usage' => $json['usage'] ?? null,
        );
        if (is_array($segments[0] ?? null)) {
            $observedFields['segment_field_keys'] = array_keys($segments[0]);
        }

        return array(
            'ok' => $code >= 200 && $code < 300,
            'label' => $label,
            'http_code' => $code,
            'request' => $requestParams,
            'response' => array(
                'format' => $responseFormat,
                'openai_request_id' => $responseHeaders['x-request-id'] ?? null,
                'observed_fields' => $observedFields,
                'segment_count' => count($segments),
                'word_timestamp_count' => $wordCount,
                'timestamp_granularities_accepted' => $wordTimestamps && count($segments) > 0,
            ),
            'raw_provider_text' => $rawText,
            'error' => is_array($json) ? ($json['error']['message'] ?? null) : 'non-json response',
            'raw_json' => $json,
            'request_started_at' => $requestStartedIso,
            'request_completed_at' => $requestCompletedIso,
            'latency_ms' => $latencyMs,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function verifyProbePersistence(string $probeExecutionUuid, ?array $filesystemEvidencePaths = null): array
    {
        return Phase0PersistenceVerifier::fromPdo($this->pdo)->verifyProbeExecution($probeExecutionUuid, $filesystemEvidencePaths);
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * @return array<string,int>
     */
    private static function loopPatternCounts(string $text): array
    {
        $counts = array();
        foreach (self::LOOP_PATTERNS as $key => $label) {
            $counts[$key] = $label === '' ? 0 : substr_count(strtolower($text), strtolower($label));
        }
        $counts['snowball_any'] = substr_count(strtolower($text), 'snowball');
        return $counts;
    }

    /**
     * @param array<string,array<string,int>> $layerCounts
     */
    private static function firstLayerWithFullLoop(array $layerCounts, int $threshold): ?string
    {
        foreach ($layerCounts as $layer => $counts) {
            if (($counts['snowball_traffic'] ?? 0) >= $threshold || ($counts['snowball_any'] ?? 0) >= $threshold) {
                return $layer;
            }
        }
        return null;
    }

    /**
     * @param array<string,array<string,int>> $layerCounts
     */
    private static function inferLoopOriginConclusion(?string $firstLayer, array $layerCounts): string
    {
        if ($firstLayer === 'raw_provider_text') {
            return 'OBSERVATION: Full Snowball loop first appears in exact raw provider response text (before per-chunk cleanTranscriptText).';
        }
        if ($firstLayer === 'post_per_chunk_clean') {
            return 'OBSERVATION: Snowball loop absent or below threshold in raw provider text but present after cleanTranscriptText — loop introduced or amplified by per-chunk cleanup.';
        }
        if ($firstLayer === 'stored_chunk_table') {
            $raw = $layerCounts['raw_provider_text']['snowball_traffic'] ?? 0;
            $clean = $layerCounts['post_per_chunk_clean']['snowball_traffic'] ?? 0;
            if ($raw === $clean) {
                return 'OBSERVATION: Loop in stored chunk matches post-clean count; raw provider count differs — inspect three_way_comparison lengths.';
            }
            return 'OBSERVATION: Loop first appears at stored chunk layer.';
        }
        return 'INFERENCE: Could not determine first loop layer from pattern counts; inspect raw JSON segments manually.';
    }

    /**
     * @param list<array<string,mixed>> $segments
     * @param list<string> $needles
     * @return array<string,mixed>
     */
    private static function analyzeRepeatedSegmentTimestamps(array $segments, array $needles): array
    {
        $matching = array();
        foreach ($segments as $idx => $segment) {
            $text = (string)($segment['text'] ?? '');
            foreach ($needles as $needle) {
                if ($needle !== '' && stripos($text, $needle) !== false) {
                    $matching[] = array(
                        'segment_index' => $idx,
                        'id' => $segment['id'] ?? null,
                        'start' => $segment['start'] ?? null,
                        'end' => $segment['end'] ?? null,
                        'text_preview' => substr($text, 0, 100),
                        'avg_logprob' => $segment['avg_logprob'] ?? null,
                        'no_speech_prob' => $segment['no_speech_prob'] ?? null,
                        'compression_ratio' => $segment['compression_ratio'] ?? null,
                        'temperature' => $segment['temperature'] ?? null,
                        'seek' => $segment['seek'] ?? null,
                        'tokens' => $segment['tokens'] ?? null,
                        'transient' => $segment['transient'] ?? null,
                    );
                    break;
                }
            }
        }

        $starts = array();
        $ends = array();
        foreach ($matching as $row) {
            if ($row['start'] !== null) {
                $starts[] = (float)$row['start'];
            }
            if ($row['end'] !== null) {
                $ends[] = (float)$row['end'];
            }
        }

        $uniqueStarts = array_unique(array_map(fn(float $v): string => sprintf('%.3f', $v), $starts));
        $uniqueEnds = array_unique(array_map(fn(float $v): string => sprintf('%.3f', $v), $ends));

        return array(
            'segment_array_present' => true,
            'matching_segment_count' => count($matching),
            'unique_start_timestamps' => count($uniqueStarts),
            'unique_end_timestamps' => count($uniqueEnds),
            'timestamps_nearly_identical' => count($uniqueStarts) <= 3 && count($matching) > 5,
            'timestamps_advance_across_silence' => count($uniqueStarts) > count($matching) / 2,
            'all_same_audio_interval' => count($uniqueStarts) === 1 && count($matching) > 1,
            'sample_matching_segments' => array_slice($matching, 0, 8),
        );
    }

    /**
     * @param array<string,mixed>|null $segmentTiming
     * @param array<string,mixed>|null $primary
     * @return array<string,mixed>
     */
    private static function pass4bDetectorNotes(?array $segmentTiming, ?array $primary): array
    {
        return array(
            'priority_order' => array(
                'repeated_provider_segment_or_word_timestamps',
                'text_during_vad_silence',
                'very_low_lexical_diversity',
                'abnormal_compression_ratio',
                'repeated_ab_ngram_cycles',
                'word_count_vs_speech_duration',
                'low_average_log_probability',
                'high_no_speech_probability',
            ),
            'segment_timing_available' => is_array($segmentTiming) && !empty($segmentTiming['segment_array_present']),
            'word_timestamps_available' => is_array($primary) && (int)($primary['response']['word_timestamp_count'] ?? 0) > 0,
            'recommendation' => 'Pass 4B must combine timestamp repetition with acoustic Pass 4A; do not rely on text repetition count alone.',
        );
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute(array($table));
        return (bool)$stmt->fetchColumn();
    }

    private static function normalizeLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        $language = preg_replace('/[^a-z0-9_-]+/', '', $language) ?? '';
        return $language !== '' ? substr($language, 0, 16) : 'en';
    }

    private static function transcriptionPrompt(): string
    {
        $ref = new ReflectionMethod(CockpitRecorderService::class, 'transcriptionPrompt');
        return (string)$ref->invoke(null);
    }

    /**
     * @param list<string> $candidates
     */
    private static function findBinary(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (str_contains($candidate, '/') && is_executable($candidate)) {
                return $candidate;
            }
            $resolved = trim((string)@shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));
            if ($resolved !== '' && is_executable($resolved)) {
                return $resolved;
            }
        }
        return '';
    }
}
