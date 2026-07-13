<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DeviceAuthService.php';
require_once __DIR__ . '/../../../src/GarminCsvEvidenceService.php';

header('Content-Type: application/json; charset=utf-8');

function cvr_csv_status_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        cvr_csv_status_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $device = (new DeviceAuthService($pdo))->requireDevice();
    $result = (new GarminCsvEvidenceService($pdo))->status(
        $device,
        (string)($_GET['upload_uuid'] ?? $_GET['upload_id'] ?? ''),
        (string)($_GET['csv_file_uuid'] ?? '')
    );
    cvr_csv_status_json(200, $result);
} catch (Throwable $e) {
    $code = str_contains(strtolower($e->getMessage()), 'token') ? 401 : 400;
    cvr_csv_status_json($code, array('ok' => false, 'error' => $e->getMessage()));
}
