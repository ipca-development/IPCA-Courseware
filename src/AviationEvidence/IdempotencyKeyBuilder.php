<?php
declare(strict_types=1);

final class IdempotencyKeyBuilder
{
    /**
     * Persistence retry key: same probe execution + probe label = no duplicate row.
     * Deliberate new probe uses a new probe_execution_uuid → new rows.
     */
    public static function forProbePersistenceRetry(string $probeExecutionUuid, string $probeLabel): string
    {
        return hash('sha256', 'probe_persist|' . strtolower($probeExecutionUuid) . '|' . $probeLabel);
    }

    /**
     * Stable execution id for one production transcription completion (recording + completion time + source hash).
     */
    public static function productionExecutionUuid(int $recordingId, string $transcriptionCompletedAt, string $sourceAudioSha256): string
    {
        $hash = hash('sha256', 'production_execution|' . $recordingId . '|' . $transcriptionCompletedAt . '|' . strtolower($sourceAudioSha256));
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12)
        );
    }

    public static function productionRetryExecutionUuid(string $baseExecutionUuid, int $retryAttempt): string
    {
        $hash = hash('sha256', 'production_retry|' . strtolower($baseExecutionUuid) . '|' . max(1, $retryAttempt));
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12)
        );
    }

    public static function forProductionTranscriptionChunk(string $executionUuid, int $chunkIndex, string $probeLabel): string
    {
        return hash('sha256', 'production_persist|' . strtolower($executionUuid) . '|' . $chunkIndex . '|' . $probeLabel);
    }

    /**
     * @param array<string,mixed> $requestConfig
     */
    public static function normalizeRequestConfig(array $requestConfig): array
    {
        $sanitized = $requestConfig;
        unset($sanitized['authorization'], $sanitized['api_key'], $sanitized['Authorization']);
        ksort($sanitized);
        return $sanitized;
    }

    /**
     * @param array<string,mixed> $requestConfig
     */
    public static function requestConfigHash(array $requestConfig): string
    {
        return hash('sha256', json_encode(self::normalizeRequestConfig($requestConfig), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function promptHash(?string $promptText): ?string
    {
        if ($promptText === null || trim($promptText) === '') {
            return null;
        }
        return hash('sha256', trim($promptText));
    }
}
