<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new GarminSyncAuthException('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
    }
    $payload = garmin_sync_json_body();
    $result = (new GarminSyncAuthService($pdo))->exchangeEnrollmentCode(
        (string)($payload['enrollment_code'] ?? ''),
        (string)($payload['device_uuid'] ?? ''),
        (string)($payload['display_name'] ?? '')
    );
    garmin_sync_json(201, array(
        'ok' => true,
        'device' => $result['device'],
        'credential' => $result['credential'],
        'credential_uuid' => $result['credential_uuid'],
        'credential_expires_at' => $result['credential_expires_at'],
    ));
} catch (Throwable $error) {
    garmin_sync_error($error);
}
