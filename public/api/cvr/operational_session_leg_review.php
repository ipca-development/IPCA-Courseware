<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DeviceAuthService.php';
require_once __DIR__ . '/../../../src/CvrOperationalSessionLegReviewService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cvr_operational_leg_review_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $device = (new DeviceAuthService($pdo))->requireDevice();
    $service = new CvrOperationalSessionLegReviewService($pdo);
    if ($method === 'GET') {
        if ((string)($_GET['status_only'] ?? '') === '1') {
            cvr_operational_leg_review_json(
                200,
                $service->statusForDevice($device, (string)($_GET['dispatch_uuid'] ?? ''))
            );
        }
        cvr_operational_leg_review_json(
            200,
            $service->previewForDevice($device, (string)($_GET['dispatch_uuid'] ?? ''))
        );
    }
    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $payload = $raw !== false && trim($raw) !== '' ? json_decode($raw, true) : null;
        if (!is_array($payload)) {
            cvr_operational_leg_review_json(400, array('ok' => false, 'error' => 'Valid leg-review JSON is required.'));
        }
        cvr_operational_leg_review_json(200, $service->acceptForDevice($device, $payload));
    }
    cvr_operational_leg_review_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    $lower = strtolower($message);
    $authFailure = str_contains($lower, 'device token')
        || str_contains($lower, 'credential')
        || str_contains($lower, 'revoked');
    cvr_operational_leg_review_json(
        $authFailure ? 401 : 422,
        array('ok' => false, 'error' => $message)
    );
} catch (Throwable $e) {
    error_log('CVR Operational Session leg review failed: ' . $e->getMessage());
    cvr_operational_leg_review_json(
        500,
        array('ok' => false, 'error' => 'Operational Session legs could not be reviewed.')
    );
}
