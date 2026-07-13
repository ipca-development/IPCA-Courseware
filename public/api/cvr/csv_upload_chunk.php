<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DeviceAuthService.php';
require_once __DIR__ . '/../../../src/GarminCsvEvidenceService.php';

header('Content-Type: application/json; charset=utf-8');

function cvr_csv_chunk_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function cvr_csv_header(string $name, string $fallback = ''): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$key] ?? $fallback));
}

try {
    $device = (new DeviceAuthService($pdo))->requireDevice();
    $service = new GarminCsvEvidenceService($pdo);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        $uploadUuid = (string)($_GET['upload_uuid'] ?? $_GET['upload_id'] ?? '');
        cvr_csv_chunk_json(200, array('ok' => true, 'received_chunks' => $service->receivedChunks($uploadUuid)));
    }
    if ($method !== 'POST') {
        cvr_csv_chunk_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $file = $_FILES['chunk'] ?? $_FILES['file'] ?? null;
    if (!is_array($file)) {
        cvr_csv_chunk_json(400, array('ok' => false, 'error' => 'CSV chunk file is required.'));
    }
    $meta = array(
        'upload_uuid' => $_POST['upload_uuid'] ?? $_POST['upload_id'] ?? cvr_csv_header('X-IPCA-CVR-CSV-Upload-ID'),
        'request_uuid' => $_POST['request_uuid'] ?? cvr_csv_header('X-IPCA-Request-ID'),
        'session_uuid' => $_POST['session_uuid'] ?? cvr_csv_header('X-IPCA-Flight-Session-ID'),
        'chunk_index' => $_POST['chunk_index'] ?? cvr_csv_header('X-IPCA-Chunk-Index'),
        'total_chunks' => $_POST['total_chunks'] ?? cvr_csv_header('X-IPCA-Total-Chunks'),
        'total_size' => $_POST['total_size'] ?? cvr_csv_header('X-IPCA-Total-Size'),
        'original_filename' => $_POST['original_filename'] ?? cvr_csv_header('X-IPCA-Original-Filename', (string)($file['name'] ?? 'garmin.csv')),
    );
    cvr_csv_chunk_json(200, $service->receiveChunk($device, $file, $meta));
} catch (Throwable $e) {
    $code = str_contains(strtolower($e->getMessage()), 'token') ? 401 : 400;
    cvr_csv_chunk_json($code, array('ok' => false, 'error' => $e->getMessage()));
}
