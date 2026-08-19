<?php
declare(strict_types=1);

require_once __DIR__ . '/SchedulerApiException.php';

final class SchedulerHttp
{
    /** @return array<string,mixed> */
    public static function input(): array
    {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new SchedulerApiException('invalid_request', 'The request body is not valid JSON.', 400, false, true, $e);
            }
            if (!is_array($decoded)) {
                throw new SchedulerApiException('invalid_request', 'The request body must be a JSON object.', 400, false, true);
            }
            return $decoded;
        }
        return array_merge($_GET, $_POST);
    }

    /** @param array<string,mixed> $payload */
    public static function json(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function method(array|string $allowed): string
    {
        $allowed = array_map('strtoupper', is_array($allowed) ? $allowed : array($allowed));
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, $allowed, true)) {
            throw new SchedulerApiException('invalid_request', 'Method not allowed.', 405, false, false);
        }
        return $method;
    }

    public static function requestId(): ?string
    {
        $value = trim((string)($_SERVER['HTTP_X_IPCA_REQUEST_ID'] ?? ''));
        return $value !== '' ? substr($value, 0, 128) : null;
    }

    public static function idempotencyKey(): string
    {
        $value = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
        if ($value === '' || strlen($value) > 128) {
            throw new SchedulerApiException(
                'invalid_request',
                'A valid Idempotency-Key header is required.',
                400,
                false,
                true
            );
        }
        return $value;
    }
}
