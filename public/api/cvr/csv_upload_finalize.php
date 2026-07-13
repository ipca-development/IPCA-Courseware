<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DeviceAuthService.php';
require_once __DIR__ . '/../../../src/GarminCsvEvidenceService.php';

header('Content-Type: application/json; charset=utf-8');

function cvr_csv_finalize_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        cvr_csv_finalize_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $raw = file_get_contents('php://input');
    $payload = $raw !== false && trim($raw) !== '' ? json_decode($raw, true) : $_POST;
    if (!is_array($payload)) {
        cvr_csv_finalize_json(400, array('ok' => false, 'error' => 'Invalid JSON payload.'));
    }
    $device = (new DeviceAuthService($pdo))->requireDevice();
    $result = (new GarminCsvEvidenceService($pdo))->finalize($device, (string)($payload['upload_uuid'] ?? $payload['upload_id'] ?? ''));
    cvr_csv_finalize_json(200, $result);
} catch (Throwable $e) {
    $code = str_contains(strtolower($e->getMessage()), 'token') ? 401 : 400;
    cvr_csv_finalize_json($code, array('ok' => false, 'error' => $e->getMessage()));
}
