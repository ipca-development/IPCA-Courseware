<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DeviceAuthService.php';
require_once __DIR__ . '/../../../src/FlightSessionService.php';

header('Content-Type: application/json; charset=utf-8');

function cvr_device_status_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        cvr_device_status_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $device = (new DeviceAuthService($pdo))->requireDevice();
    $sessions = (new FlightSessionService($pdo))->recentSessionsForDevice((int)$device['id']);
    cvr_device_status_json(200, array(
        'ok' => true,
        'device' => array(
            'device_uuid' => $device['device_uuid'],
            'aircraft_id' => $device['aircraft_id'] !== null ? (int)$device['aircraft_id'] : null,
            'aircraft_registration' => $device['aircraft_registration'],
            'active' => (int)$device['active'] === 1,
            'last_seen_at' => $device['last_seen_at'] ?? null,
        ),
        'server_time_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'configuration' => array(
            'server_authoritative' => true,
            'session_gap_seconds' => 300,
            'csv_upload_enabled' => true,
        ),
        'recent_sessions' => $sessions,
    ));
} catch (Throwable $e) {
    cvr_device_status_json(401, array('ok' => false, 'error' => $e->getMessage()));
}
