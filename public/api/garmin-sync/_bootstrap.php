<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DeviceAuthService.php';
require_once __DIR__ . '/../../../src/GarminSyncUploadService.php';

header('Content-Type: application/json; charset=utf-8');

/** @param array<string,mixed> $payload */
function garmin_sync_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function garmin_sync_error(Throwable $error): void
{
    if ($error instanceof GarminSyncUploadException) {
        garmin_sync_json($error->httpStatus(), array(
            'ok' => false,
            'error' => $error->getMessage(),
            'error_code' => $error->errorCode(),
            'retryable' => $error->retryable(),
        ));
    }
    $message = strtolower($error->getMessage());
    if (str_contains($message, 'token') || str_contains($message, 'credential') || str_contains($message, 'device')) {
        garmin_sync_json(401, array(
            'ok' => false,
            'error' => 'Device authentication failed.',
            'error_code' => 'DEVICE_AUTH_FAILED',
            'retryable' => false,
        ));
    }
    garmin_sync_json(500, array(
        'ok' => false,
        'error' => 'Garmin Sync request failed.',
        'error_code' => 'INTERNAL_ERROR',
        'retryable' => true,
    ));
}

/** @return array<string,mixed> */
function garmin_sync_json_body(): array
{
    $raw = file_get_contents('php://input');
    $payload = $raw !== false && trim($raw) !== '' ? json_decode($raw, true) : array();
    if (!is_array($payload)) {
        throw new GarminSyncUploadException('Request body must be a JSON object.', 'INVALID_JSON');
    }
    return $payload;
}

function garmin_sync_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$key] ?? ''));
}
