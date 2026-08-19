<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new GarminSyncUploadException('Method not allowed.', 'METHOD_NOT_ALLOWED', false, 405);
    }
    $device = (new DeviceAuthService($pdo))->requireDevice();
    $payload = garmin_sync_json_body();
    $result = (new GarminSyncUploadService($pdo))->finalize(
        $device,
        (string)($payload['upload_uuid'] ?? '')
    );
    garmin_sync_json(200, $result);
} catch (Throwable $error) {
    garmin_sync_error($error);
}
