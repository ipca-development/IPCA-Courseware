<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

try {
    $device = (new DeviceAuthService($pdo))->requireDevice();
    $service = new GarminSyncUploadService($pdo);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        garmin_sync_json(200, $service->resume($device, (string)($_GET['upload_uuid'] ?? '')));
    }
    if ($method !== 'POST') {
        throw new GarminSyncUploadException('Method not allowed.', 'METHOD_NOT_ALLOWED', false, 405);
    }
    $file = $_FILES['chunk'] ?? null;
    if (!is_array($file)) {
        throw new GarminSyncUploadException('Multipart field "chunk" is required.', 'CHUNK_UPLOAD_MISSING', true);
    }
    $meta = array(
        'upload_uuid' => $_POST['upload_uuid'] ?? garmin_sync_header('X-IPCA-Upload-ID'),
        'request_uuid' => $_POST['request_uuid'] ?? garmin_sync_header('X-IPCA-Request-ID'),
        'expected_sha256' => $_POST['expected_sha256'] ?? garmin_sync_header('X-IPCA-Expected-SHA256'),
        'expected_byte_count' => $_POST['expected_byte_count'] ?? garmin_sync_header('X-IPCA-Expected-Bytes'),
        'chunk_index' => $_POST['chunk_index'] ?? garmin_sync_header('X-IPCA-Chunk-Index'),
        'total_chunks' => $_POST['total_chunks'] ?? garmin_sync_header('X-IPCA-Total-Chunks'),
        'original_filename' => $_POST['original_filename'] ?? (string)($file['name'] ?? ''),
    );
    garmin_sync_json(200, $service->receiveChunk($device, $file, $meta));
} catch (Throwable $error) {
    garmin_sync_error($error);
}
