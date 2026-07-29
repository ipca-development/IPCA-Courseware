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
