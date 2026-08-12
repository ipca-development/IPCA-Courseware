<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DeviceAuthService.php';
require_once __DIR__ . '/../../../src/CvrLiveCockpitMonitorService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function cvr_monitor_device_lease_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        cvr_monitor_device_lease_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $device = (new DeviceAuthService($pdo))->requireDevice();
    cvr_monitor_device_lease_json(200, (new CvrLiveCockpitMonitorService($pdo))->deviceLease($device));
} catch (RuntimeException $e) {
    cvr_monitor_device_lease_json(401, array('ok' => false, 'error' => $e->getMessage()));
} catch (Throwable $e) {
    error_log('[cvr live cockpit monitor lease] ' . $e->getMessage());
    cvr_monitor_device_lease_json(500, array('ok' => false, 'error' => 'Monitor lease is temporarily unavailable.'));
}
