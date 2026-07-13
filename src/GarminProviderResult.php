<?php
declare(strict_types=1);

final class GarminProviderResult
{
    /**
     * @param array<string,mixed>|null $data
     * @param list<string> $warnings
     * @param array{code:string,message:string}|null $error
     */
    public function __construct(
        public bool $ok,
        public string $operation,
        public string $provider,
        public string $status,
        public ?array $data = null,
        public array $warnings = array(),
        public ?array $error = null
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromWorkerPayload(array $payload, string $fallbackOperation = 'unknown'): self
    {
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : null;
        return new self(
            !empty($payload['ok']),
            (string)($payload['operation'] ?? $fallbackOperation),
            (string)($payload['provider'] ?? 'flygarmin_web'),
            (string)($payload['status'] ?? 'sync_error'),
            is_array($payload['data'] ?? null) ? $payload['data'] : null,
            is_array($payload['warnings'] ?? null) ? array_values(array_map('strval', $payload['warnings'])) : array(),
            $error !== null ? array(
                'code' => (string)($error['code'] ?? 'GARMIN_PROVIDER_ERROR'),
                'message' => (string)($error['message'] ?? 'Garmin provider error.'),
            ) : null
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'ok' => $this->ok,
            'operation' => $this->operation,
            'provider' => $this->provider,
            'status' => $this->status,
            'data' => $this->data,
            'warnings' => $this->warnings,
            'error' => $this->error,
        );
    }
}
