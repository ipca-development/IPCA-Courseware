<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CvrDeviceEnrollmentService.php';

header('Content-Type: application/json; charset=utf-8');

function cvr_enroll_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        cvr_enroll_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $raw = file_get_contents('php://input');
    $payload = $raw !== false && trim($raw) !== '' ? json_decode($raw, true) : $_POST;
    if (!is_array($payload)) {
        cvr_enroll_json(400, array('ok' => false, 'error' => 'Invalid JSON payload.'));
    }

    $service = new CvrDeviceEnrollmentService($pdo);
    $result = $service->exchange(
        (string)($payload['enrollment_code'] ?? ''),
        (string)($payload['device_uuid'] ?? ''),
        (string)($payload['display_name'] ?? ''),
        isset($payload['mdm_device_identifier']) ? (string)$payload['mdm_device_identifier'] : null
    );
    cvr_enroll_json(200, $result);
} catch (Throwable $e) {
    cvr_enroll_json(400, array('ok' => false, 'error' => $e->getMessage()));
}
