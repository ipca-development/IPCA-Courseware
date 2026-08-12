<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CvrLiveCockpitMonitorService.php';

cw_require_flight_schedule_editor();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function cvr_monitor_admin_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $user = cw_current_user($pdo);
    if (!is_array($user) || (int)($user['id'] ?? 0) <= 0) {
        cvr_monitor_admin_json(401, array('ok' => false, 'error' => 'Authentication is required.'));
    }
    $service = new CvrLiveCockpitMonitorService($pdo);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        $action = strtolower(trim((string)($_GET['action'] ?? 'status')));
        if ($action === 'manifest') {
            cvr_monitor_admin_json(200, $service->manifest(
                (string)($_GET['lease_uuid'] ?? ''),
                (int)$user['id'],
                (int)($_GET['after_sequence'] ?? 0)
            ));
        }
        cvr_monitor_admin_json(200, $service->statusForAircraft(
            (int)($_GET['aircraft_id'] ?? 0),
            (string)($_GET['claimed_dispatch_uuid'] ?? ''),
            (int)$user['id']
        ));
    }
    if ($method !== 'POST') {
        cvr_monitor_admin_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $raw = file_get_contents('php://input');
    $payload = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        cvr_monitor_admin_json(400, array('ok' => false, 'error' => 'Valid request JSON is required.'));
    }
    $expectedCsrf = (string)($_SESSION['flight_schedule_csrf'] ?? '');
    if ($expectedCsrf === ''
        || !hash_equals($expectedCsrf, (string)($payload['csrf_token'] ?? ''))) {
        cvr_monitor_admin_json(403, array(
            'ok' => false,
            'error' => 'Invalid request token. Refresh the Schedule page and try again.',
        ));
    }
    $action = strtolower(trim((string)($payload['action'] ?? '')));
    if ($action === 'start') {
        cvr_monitor_admin_json(201, $service->startListener(
            (int)($payload['aircraft_id'] ?? 0),
            (string)($payload['claimed_dispatch_uuid'] ?? ''),
            (string)($payload['client_uuid'] ?? ''),
            (int)$user['id']
        ));
    }
    if ($action === 'heartbeat') {
        cvr_monitor_admin_json(200, $service->heartbeat(
            (string)($payload['lease_uuid'] ?? ''),
            (int)$user['id']
        ));
    }
    if ($action === 'stop') {
        cvr_monitor_admin_json(200, $service->stopListener(
            (string)($payload['lease_uuid'] ?? ''),
            (int)$user['id'],
            'staff_stop'
        ));
    }
    cvr_monitor_admin_json(422, array('ok' => false, 'error' => 'Unsupported live monitor action.'));
} catch (InvalidArgumentException $e) {
    cvr_monitor_admin_json(422, array('ok' => false, 'error' => $e->getMessage()));
} catch (RuntimeException $e) {
    cvr_monitor_admin_json(409, array('ok' => false, 'error' => $e->getMessage()));
} catch (Throwable $e) {
    error_log('[cvr live cockpit monitor admin] ' . $e->getMessage());
    cvr_monitor_admin_json(500, array('ok' => false, 'error' => 'Live cockpit monitoring is temporarily unavailable.'));
}
