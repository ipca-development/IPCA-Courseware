<?php
declare(strict_types=1);

final class EvidenceSchema
{
    public const SCHEMA_VERSION = '2026.07.30';
    public const MIGRATION_FILE = 'scripts/sql/2026_07_30_aviation_evidence_platform.sql';

    public const TABLE_PROCESSING_RUNS = 'ipca_evidence_processing_runs';
    public const TABLE_AUDIO_CHUNKS = 'ipca_evidence_audio_chunks';
    public const TABLE_PROVIDER_RUNS = 'ipca_evidence_provider_runs';
    public const TABLE_PROVIDER_SEGMENTS = 'ipca_evidence_provider_segments';
    public const TABLE_PROVIDER_WORDS = 'ipca_evidence_provider_words';
    public const TABLE_PROVIDER_OBSERVATIONS = 'ipca_evidence_provider_observations';
    public const TABLE_MODEL_CAPABILITIES = 'ipca_provider_model_capabilities';
    public const TABLE_PUBLISHED_VERSIONS = 'ipca_evidence_published_transcript_versions';
    public const TABLE_SCHEMA_VERSIONS = 'ipca_evidence_schema_versions';
    public const TABLE_SPEECH_SEGMENTS = 'ipca_evidence_speech_segments';
    public const TABLE_INTERPRETATION_REVISIONS = 'ipca_evidence_interpretation_revisions';
    public const TABLE_INTERPRETATION_CONFIDENCE = 'ipca_evidence_interpretation_confidence_factors';
    public const TABLE_SUPPRESSIONS = 'ipca_evidence_suppressions';
    public const TABLE_DISPLAY_BLOCKS = 'ipca_evidence_display_blocks';
    public const TABLE_CHAPTERS = 'ipca_evidence_chapters';
    public const TABLE_KNOWLEDGE_CORRECTIONS = 'ipca_knowledge_correction_evidence';

    public const PASS4A_VERSION = '2026.07.30.1';
    public const PASS4B_VERSION = '2026.07.30.1';
    public const PASS5_VERSION = '2026.07.30.1';
    public const PUBLISH_SNAPSHOT_VERSION = '2026.07.30.2';

    public const LAYER_PASS4A = 'pass_4a_speech_quality';
    public const LAYER_PASS4B = 'pass_4b_repetition';
    public const LAYER_READABLE = 'readable_primary';

    public const RUN_PURPOSE_INITIAL = 'initial_transcription';
    public const RUN_PURPOSE_RETRY = 'retry_same_run';
    public const RUN_PURPOSE_REPROBE = 'deliberate_reprobe';
    public const RUN_PURPOSE_CONFIG_CHANGE = 'reprocess_config_change';
    public const RUN_PURPOSE_DIFFERENT_MODEL = 'different_provider_model';
    public const RUN_PURPOSE_PHASE0_PROBE = 'phase0_mandatory_probe';

    /** Direct observation keys — not interpretations. */
    public const OBS_RESPONSE_CONTAINS_TEXT = 'response_contains_text';
    public const OBS_RESPONSE_CONTAINS_SEGMENTS = 'response_contains_segments';
    public const OBS_RESPONSE_CONTAINS_WORDS = 'response_contains_words';
    public const OBS_LANGUAGE_RETURNED = 'language_returned';
    public const OBS_DURATION_RETURNED = 'duration_returned';
    public const OBS_SEGMENT_COUNT = 'segment_count';
    public const OBS_WORD_TIMESTAMP_COUNT = 'word_timestamp_count';
    public const OBS_HTTP_STATUS = 'http_status';
    public const OBS_RESPONSE_FORMAT_REJECTED = 'response_format_rejected';
    public const OBS_CHUNK_AUDIO_HASH = 'chunk_audio_hash';
    public const OBS_SOURCE_AUDIO_HASH = 'source_audio_hash';
    public const OBS_PROVIDER_RESPONSE_HASH = 'provider_response_hash';
    public const OBS_USAGE = 'usage';

    public static function tablePresent(PDO $pdo, string $table): bool
    {
        static $cache = array();
        $cacheKey = spl_object_id($pdo) . ':' . $table;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute(array($table));
        $cache[$cacheKey] = (bool)$stmt->fetchColumn();
        return $cache[$cacheKey];
    }

    /**
     * @return list<string>
     */
    public static function persistenceRequiredTables(): array
    {
        return array(
            self::TABLE_PROCESSING_RUNS,
            self::TABLE_AUDIO_CHUNKS,
            self::TABLE_PROVIDER_RUNS,
            self::TABLE_PROVIDER_SEGMENTS,
            self::TABLE_PROVIDER_OBSERVATIONS,
        );
    }

    public static function requireTables(PDO $pdo, array $tables): void
    {
        $missing = array();
        foreach ($tables as $table) {
            if (!self::tablePresent($pdo, $table)) {
                $missing[] = $table;
            }
        }
        if ($missing !== array()) {
            throw new RuntimeException(
                'Missing evidence table(s): ' . implode(', ', $missing)
                . '. Apply ' . self::MIGRATION_FILE . ' first.'
            );
        }
    }

    public static function persistenceReady(PDO $pdo): bool
    {
        foreach (self::persistenceRequiredTables() as $table) {
            if (!self::tablePresent($pdo, $table)) {
                return false;
            }
        }
        return true;
    }

    public static function requirePersistenceReady(PDO $pdo): void
    {
        if (self::persistenceReady($pdo)) {
            return;
        }
        $missing = array();
        foreach (self::persistenceRequiredTables() as $table) {
            if (!self::tablePresent($pdo, $table)) {
                $missing[] = $table;
            }
        }
        throw new RuntimeException(
            'Evidence schema not ready. Missing tables: ' . implode(', ', $missing)
            . '. Apply ' . self::MIGRATION_FILE . ' first, or run with persist=0 for filesystem-only evidence.'
        );
    }

    public static function storePromptText(): bool
    {
        $env = getenv('CW_EVIDENCE_STORE_PROMPT_TEXT');
        return $env === '1' || $env === 'true';
    }

    public static function pass4Ready(PDO $pdo): bool
    {
        foreach (array(
            self::TABLE_SPEECH_SEGMENTS,
            self::TABLE_INTERPRETATION_REVISIONS,
            self::TABLE_SUPPRESSIONS,
        ) as $table) {
            if (!self::tablePresent($pdo, $table)) {
                return false;
            }
        }
        return self::persistenceReady($pdo);
    }

    public static function publishReady(PDO $pdo): bool
    {
        return self::pass4Ready($pdo) && self::tablePresent($pdo, self::TABLE_PUBLISHED_VERSIONS);
    }

    public static function pass5Ready(PDO $pdo): bool
    {
        foreach (array(self::TABLE_DISPLAY_BLOCKS, self::TABLE_CHAPTERS) as $table) {
            if (!self::tablePresent($pdo, $table)) {
                return false;
            }
        }
        return self::pass4Ready($pdo);
    }

    public static function terminologyReady(PDO $pdo): bool
    {
        return self::tablePresent($pdo, self::TABLE_KNOWLEDGE_CORRECTIONS);
    }

    public static function runPass4AfterPersist(): bool
    {
        if (getenv('CW_EVIDENCE_SKIP_PASS4') === '1' || getenv('CW_EVIDENCE_SKIP_PASS4') === 'true') {
            return false;
        }
        return true;
    }

    public static function skipProductionPersist(): bool
    {
        $env = getenv('CW_EVIDENCE_SKIP_PRODUCTION_PERSIST');
        return $env === '1' || $env === 'true';
    }

    public static function productionSkipWhisper(): bool
    {
        $env = getenv('CW_EVIDENCE_PRODUCTION_SKIP_WHISPER');
        return $env === '1' || $env === 'true';
    }

    /**
     * When true (default), transcription uses one Whisper verbose_json pass with timestamps.
     * Set CW_TRANSCRIPTION_LEGACY_DUAL_PASS=1 to restore gpt-4o transcribe + separate Whisper evidence pass.
     */
    public static function whisperFirstTranscription(): bool
    {
        $env = getenv('CW_TRANSCRIPTION_LEGACY_DUAL_PASS');
        return !($env === '1' || $env === 'true');
    }

    public static function defaultAsrModel(): string
    {
        $env = trim((string)(getenv('CW_OPENAI_ASR_MODEL') ?: ''));
        if ($env !== '') {
            return $env;
        }
        return self::whisperFirstTranscription() ? 'whisper-1' : 'gpt-4o-transcribe';
    }

    public static function defaultAsrResponseFormat(): string
    {
        return self::whisperFirstTranscription() ? 'verbose_json' : 'json';
    }

    public static function runPass5AfterPersist(): bool
    {
        if (getenv('CW_EVIDENCE_SKIP_PASS5') === '1' || getenv('CW_EVIDENCE_SKIP_PASS5') === 'true') {
            return false;
        }
        return true;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function currentSchemaVersion(PDO $pdo): ?array
    {
        if (!self::tablePresent($pdo, self::TABLE_SCHEMA_VERSIONS)) {
            return null;
        }
        $row = $pdo->query(
            'SELECT * FROM ' . self::TABLE_SCHEMA_VERSIONS . ' ORDER BY applied_at DESC, id DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function processingRunHasLifecycleColumns(PDO $pdo): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        if (!self::tablePresent($pdo, self::TABLE_PROCESSING_RUNS)) {
            $cache = false;
            return false;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute(array(self::TABLE_PROCESSING_RUNS, 'heartbeat_at'));
            $cache = (int)$stmt->fetchColumn() > 0;
        } catch (Throwable) {
            $cache = false;
        }
        return $cache;
    }
}
