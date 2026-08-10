<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CvrCrewMessageService.php';

cw_require_flight_schedule_editor();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cvr_crew_admin_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $service = new CvrCrewMessageService($pdo);
    if ($method === 'GET') {
        cvr_crew_admin_json(200, $service->activeStatusForAircraft(
            (int)($_GET['aircraft_id'] ?? 0),
            (string)($_GET['claimed_dispatch_uuid'] ?? '')
        ));
    }
    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $payload = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
        if (!is_array($payload)) {
            cvr_crew_admin_json(400, array('ok' => false, 'error' => 'Valid message JSON is required.'));
        }
        $expectedCsrf = (string)($_SESSION['flight_schedule_csrf'] ?? '');
        if ($expectedCsrf === ''
            || !hash_equals($expectedCsrf, (string)($payload['csrf_token'] ?? ''))) {
            cvr_crew_admin_json(
                403,
                array('ok' => false, 'error' => 'Invalid request token. Refresh the Schedule page and try again.')
            );
        }
        $sender = cw_current_user($pdo);
        if (!is_array($sender)) {
            cvr_crew_admin_json(401, array('ok' => false, 'error' => 'Authentication is required.'));
        }
        cvr_crew_admin_json(201, $service->sendForAircraft(
            (int)($payload['aircraft_id'] ?? 0),
            (string)($payload['claimed_dispatch_uuid'] ?? ''),
            (string)($payload['body'] ?? ''),
            $sender
        ));
    }
    cvr_crew_admin_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
} catch (InvalidArgumentException $e) {
    cvr_crew_admin_json(422, array('ok' => false, 'error' => $e->getMessage()));
} catch (RuntimeException $e) {
    cvr_crew_admin_json(409, array('ok' => false, 'error' => $e->getMessage()));
} catch (Throwable $e) {
    error_log('[cvr crew admin messages] ' . $e->getMessage());
    cvr_crew_admin_json(500, array('ok' => false, 'error' => 'Crew messaging is temporarily unavailable.'));
}
