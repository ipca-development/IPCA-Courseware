<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../src/GarminSyncFileClassificationService.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new GarminSyncUploadException('Method not allowed.', 'METHOD_NOT_ALLOWED', false, 405);
    }
    $device = (new GarminSyncAuthService($pdo))->requireDevice();
    $payload = garmin_sync_json_body();
    $result = (new GarminSyncUploadService($pdo))->finalize(
        $device,
        (string)($payload['upload_uuid'] ?? '')
    );
    $objectUuid = trim((string)($result['receipt']['object_id'] ?? ''));
    if ($objectUuid !== '') {
        try {
            (new GarminSyncFileClassificationService($pdo))->classifyObjectUuid($objectUuid);
        } catch (Throwable $classificationError) {
            // Derived classification must never invalidate an already verified archive receipt.
            error_log('Garmin Sync classification deferred: ' . $classificationError->getMessage());
        }
    }
    garmin_sync_json(200, $result);
} catch (Throwable $error) {
    garmin_sync_error($error);
}
