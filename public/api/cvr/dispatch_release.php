<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DeviceAuthService.php';
require_once __DIR__ . '/../../../src/CvrDispatchReleaseService.php';
require_once __DIR__ . '/../../../src/CvrSyncException.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cvr_dispatch_release_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new CvrTechnicalReviewRequired('Dispatch release method is not supported.');
    }
    $raw = file_get_contents('php://input');
    $payload = $raw !== false && trim($raw) !== '' ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        throw new CvrTechnicalReviewRequired('Dispatch release payload is not valid JSON.');
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

    $dispatchUuid = strtolower(trim((string)($payload['dispatch_uuid'] ?? '')));
    $schedulerRecordId = strtolower(trim((string)($payload['scheduler_record_id'] ?? '')));
    if ($dispatchUuid === '' && $schedulerRecordId === '') {
        throw new CvrUserCorrectionRequired('dispatch_uuid or scheduler_record_id is required to Undispatch.');
    }

    $service = new CvrDispatchReleaseService($pdo);
    if ($dispatchUuid !== '') {
        $result = $service->releaseByDispatchUuid(
            $dispatchUuid,
            $schedulerRecordId !== '' ? $schedulerRecordId : null,
            null,
            'device',
            (int)($device['id'] ?? 0)
        );
    } else {
        $result = $service->releaseBySchedulerRecordId(
            $schedulerRecordId,
            null,
            'device',
            (int)($device['id'] ?? 0)
        );
    }
    $result['request_id'] = cvr_sync_request_id($payload);
    $result['error_code'] = null;
    $result['error'] = null;
    $result['retryable'] = false;
    $result['user_action_required'] = false;
    cvr_dispatch_release_json(200, $result);
} catch (CvrSyncException $e) {
    cvr_dispatch_release_json($e->httpStatus(), $e->payload(cvr_sync_request_id(is_array($payload ?? null) ? $payload : array())));
} catch (Throwable $e) {
    error_log('CVR Dispatch release failed: ' . $e->getMessage());
    $failure = new CvrTemporaryTechnicalFailure();
    cvr_dispatch_release_json($failure->httpStatus(), $failure->payload(cvr_sync_request_id(is_array($payload ?? null) ? $payload : array())));
}
