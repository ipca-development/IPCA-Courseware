<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DeviceAuthService.php';
require_once __DIR__ . '/../../../src/FlightScheduleService.php';
require_once __DIR__ . '/../../../src/FlightSessionService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cvr_scheduled_sessions_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        cvr_scheduled_sessions_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $device = (new DeviceAuthService($pdo))->requireDevice();
    $sessions = (new FlightScheduleService($pdo))->scheduledSessionsForDevice(
        $device,
        isset($_GET['from']) ? (string)$_GET['from'] : null,
        isset($_GET['to']) ? (string)$_GET['to'] : null
    );
    cvr_scheduled_sessions_json(200, array(
        'ok' => true,
        'scheduled_sessions' => $sessions,
        'operational_session_model_enabled' =>
            (new FlightSessionService($pdo))->modelEnabledForDevice($device),
    ));
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    $authFailure = str_contains(strtolower($message), 'device token')
        || str_contains(strtolower($message), 'credential')
        || str_contains(strtolower($message), 'revoked');
    cvr_scheduled_sessions_json($authFailure ? 401 : 422, array('ok' => false, 'error' => $message));
} catch (Throwable $e) {
    error_log('CVR scheduled sessions failed: ' . $e->getMessage());
    cvr_scheduled_sessions_json(500, array('ok' => false, 'error' => 'Scheduled sessions could not be loaded.'));
}
