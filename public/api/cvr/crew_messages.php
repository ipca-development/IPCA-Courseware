<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DeviceAuthService.php';
require_once __DIR__ . '/../../../src/CvrCrewMessageService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cvr_crew_device_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $device = (new DeviceAuthService($pdo))->requireDevice();
    $service = new CvrCrewMessageService($pdo);
    if ($method === 'GET') {
        cvr_crew_device_json(200, $service->pendingForDevice(
            $device,
            (string)($_GET['operational_session_uuid'] ?? '')
        ));
    }
    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $payload = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
        if (!is_array($payload)) {
            cvr_crew_device_json(
                400,
                array('ok' => false, 'error' => 'Valid acknowledgement JSON is required.')
            );
        }
        cvr_crew_device_json(200, $service->acknowledge($device, $payload));
    }
    cvr_crew_device_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
} catch (InvalidArgumentException $e) {
    cvr_crew_device_json(422, array('ok' => false, 'error' => $e->getMessage()));
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    $lower = strtolower($message);
    $authFailure = str_contains($lower, 'device token')
        || str_contains($lower, 'credential')
        || str_contains($lower, 'revoked');
    cvr_crew_device_json(
        $authFailure ? 401 : 409,
        array('ok' => false, 'error' => $message)
    );
} catch (Throwable $e) {
    error_log('[cvr crew device messages] ' . $e->getMessage());
    cvr_crew_device_json(500, array('ok' => false, 'error' => 'Crew messaging is temporarily unavailable.'));
}
