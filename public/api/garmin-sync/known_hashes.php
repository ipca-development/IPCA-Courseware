<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new GarminSyncUploadException('Method not allowed.', 'METHOD_NOT_ALLOWED', false, 405);
    }
    $device = (new GarminSyncAuthService($pdo))->requireDevice();
    $payload = garmin_sync_json_body();
    $hashes = $payload['sha256_list'] ?? array();
    if (!is_array($hashes)) {
        throw new GarminSyncUploadException('sha256_list must be an array.', 'INVALID_HASH_LIST');
    }
    garmin_sync_json(200, (new GarminSyncUploadService($pdo))->knownHashes($device, $hashes));
} catch (Throwable $error) {
    garmin_sync_error($error);
}
