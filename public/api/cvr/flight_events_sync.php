<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DeviceAuthService.php';
require_once __DIR__ . '/../../../src/CvrWorkflowEvidenceIntakeService.php';
require_once __DIR__ . '/../../../src/CvrSyncException.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cvr_workflow_evidence_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new CvrTechnicalReviewRequired('Workflow evidence synchronization method is not supported.');
    }
    $raw = file_get_contents('php://input');
    $payload = $raw !== false && trim($raw) !== '' ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        throw new CvrTechnicalReviewRequired('Workflow evidence synchronization payload is not valid JSON.');
    }
    try {
        $device = (new DeviceAuthService($pdo))->requireDevice();
    } catch (RuntimeException $e) {
        $authMessage = strtolower($e->getMessage());
        if (in_array($authMessage, array(
            'device token is required.',
            'device token is invalid.',
            'device is revoked or inactive.',
            'device credential is revoked.',
            'device credential has expired.',
        ), true)) {
            throw new CvrAuthenticationRequired(previous: $e);
        }
        throw $e;
    }
    $result = (new CvrWorkflowEvidenceIntakeService($pdo))->receive($payload, $device);
    $result['request_id'] = cvr_sync_request_id($payload);
    cvr_workflow_evidence_json(200, $result);
} catch (CvrSyncException $e) {
    cvr_workflow_evidence_json($e->httpStatus(), $e->payload(cvr_sync_request_id(is_array($payload ?? null) ? $payload : array())));
} catch (Throwable $e) {
    error_log('CVR workflow evidence sync failed: ' . $e->getMessage());
    $failure = new CvrTemporaryTechnicalFailure();
    cvr_workflow_evidence_json($failure->httpStatus(), $failure->payload(cvr_sync_request_id(is_array($payload ?? null) ? $payload : array())));
}
