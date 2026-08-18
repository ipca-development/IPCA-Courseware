<?php
declare(strict_types=1);

require_once __DIR__ . '/SafetySupport.php';

final class SafetyHttp
{
    /** @return array<string,mixed> */
    public static function input(): array
    {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            try {
                $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new SafetyException('invalid_json', 'The request body must be valid JSON.', 400);
            }
            if (!is_array($decoded)) {
                throw new SafetyException('invalid_json', 'The request body must be a JSON object.', 400);
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
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        echo SafetySupport::json($payload);
        exit;
    }

    public static function fail(SafetyException $e): never
    {
        self::json($e->httpStatus, array(
            'ok' => false,
            'error' => $e->getMessage(),
            'error_code' => $e->errorCode,
        ));
    }

    public static function requireMethod(string ...$allowed): string
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, array_map('strtoupper', $allowed), true)) {
            throw new SafetyException('method_not_allowed', 'Method not allowed.', 405);
        }
        return $method;
    }

    public static function idempotencyKey(): string
    {
        $key = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
        if ($key === '' || strlen($key) > 200) {
            throw new SafetyException('idempotency_key_required', 'A valid Idempotency-Key header is required.', 400);
        }
        return $key;
    }
}
