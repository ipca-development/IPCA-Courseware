<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DeviceAuthService.php';
require_once __DIR__ . '/../../../src/FlightSessionService.php';
require_once __DIR__ . '/../../../src/AircraftFuelStateService.php';

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
    $registration = strtoupper(trim((string)($device['aircraft_registration'] ?? '')));
    $aircraftId = $device['aircraft_id'] !== null ? (int)$device['aircraft_id'] : null;
    $fuelState = array(
        'quantity_usg' => null,
        'unit' => 'USG',
        'capacity' => 13.0,
        'source' => 'none',
        'as_of_utc' => null,
        'aircraft_registration' => $registration,
        'uplift_uuid' => null,
    );
    try {
        $fuelState = (new AircraftFuelStateService($pdo))->stateForRegistration($registration, $aircraftId);
    } catch (Throwable $e) {
        error_log('[device_status] fuel_state unavailable: ' . $e->getMessage());
    }
    cvr_device_status_json(200, array(
        'ok' => true,
        'device' => array(
            'device_uuid' => $device['device_uuid'],
            'aircraft_id' => $aircraftId,
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
        'fuel_state' => $fuelState,
        'recent_sessions' => $sessions,
    ));
} catch (Throwable $e) {
    cvr_device_status_json(401, array('ok' => false, 'error' => $e->getMessage()));
}
