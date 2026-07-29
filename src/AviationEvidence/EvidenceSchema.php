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
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute(array($table));
        return (bool)$stmt->fetchColumn();
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
}
