<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DeviceAuthService.php';
require_once __DIR__ . '/../../../src/CvrFlightLogService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cvr_flight_logs_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        cvr_flight_logs_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $device = (new DeviceAuthService($pdo))->requireDevice();
    $logs = (new CvrFlightLogService($pdo))->forDeviceAircraft($device);
    cvr_flight_logs_json(200, array('ok' => true, 'flight_logs' => $logs));
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    $authFailure = str_contains(strtolower($message), 'device token')
        || str_contains(strtolower($message), 'credential')
        || str_contains(strtolower($message), 'revoked');
    cvr_flight_logs_json($authFailure ? 401 : 422, array('ok' => false, 'error' => $message));
} catch (Throwable $e) {
    error_log('CVR flight logs failed: ' . $e->getMessage());
    cvr_flight_logs_json(500, array('ok' => false, 'error' => 'Aircraft flight logs could not be loaded.'));
}
