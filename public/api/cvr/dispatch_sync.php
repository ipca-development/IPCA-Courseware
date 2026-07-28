<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DeviceAuthService.php';
require_once __DIR__ . '/../../../src/CvrDispatchIntakeService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cvr_dispatch_sync_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        cvr_dispatch_sync_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $raw = file_get_contents('php://input');
    $payload = $raw !== false && trim($raw) !== '' ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        cvr_dispatch_sync_json(400, array('ok' => false, 'error' => 'Valid JSON Dispatch payload is required.'));
    }

    $device = (new DeviceAuthService($pdo))->requireDevice();
    $result = (new CvrDispatchIntakeService($pdo))->receive($payload, $device);
    cvr_dispatch_sync_json(200, $result);
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    $status = str_contains(strtolower($message), 'device token')
        || str_contains(strtolower($message), 'credential')
        || str_contains(strtolower($message), 'revoked')
        ? 401
        : 422;
    cvr_dispatch_sync_json($status, array('ok' => false, 'error' => $message));
} catch (Throwable $e) {
    error_log('CVR Dispatch sync failed: ' . $e->getMessage());
    cvr_dispatch_sync_json(500, array('ok' => false, 'error' => 'Dispatch could not be stored.'));
}
