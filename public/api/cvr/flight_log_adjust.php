<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DeviceAuthService.php';
require_once __DIR__ . '/../../../src/CvrFlightLogService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cvr_flight_log_adjust_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        cvr_flight_log_adjust_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $raw = file_get_contents('php://input');
    $payload = $raw !== false && trim($raw) !== '' ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        cvr_flight_log_adjust_json(400, array('ok' => false, 'error' => 'Valid adjustment JSON is required.'));
    }
    $device = (new DeviceAuthService($pdo))->requireDevice();
    $result = (new CvrFlightLogService($pdo))->adjustForDeviceAircraft($device, $payload);
    cvr_flight_log_adjust_json(200, $result);
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    $authFailure = str_contains(strtolower($message), 'device token')
        || str_contains(strtolower($message), 'credential')
        || str_contains(strtolower($message), 'revoked');
    cvr_flight_log_adjust_json($authFailure ? 401 : 422, array('ok' => false, 'error' => $message));
} catch (Throwable $e) {
    error_log('CVR flight log adjustment failed: ' . $e->getMessage());
    cvr_flight_log_adjust_json(500, array('ok' => false, 'error' => 'Flight log adjustment could not be stored.'));
}
