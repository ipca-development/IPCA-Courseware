<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DeviceAuthService.php';
require_once __DIR__ . '/../../../src/CvrDispatchIntakeService.php';
require_once __DIR__ . '/../../../src/CvrSyncException.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cvr_dispatch_sync_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new CvrTechnicalReviewRequired('Dispatch synchronization method is not supported.');
    }
    $raw = file_get_contents('php://input');
    $payload = $raw !== false && trim($raw) !== '' ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        throw new CvrTechnicalReviewRequired('Dispatch synchronization payload is not valid JSON.');
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
    $result = (new CvrDispatchIntakeService($pdo))->receive($payload, $device);
    $result['request_id'] = cvr_sync_request_id($payload);
    cvr_dispatch_sync_json(200, $result);
} catch (CvrSyncException $e) {
    cvr_dispatch_sync_json($e->httpStatus(), $e->payload(cvr_sync_request_id(is_array($payload ?? null) ? $payload : array())));
} catch (Throwable $e) {
    error_log('CVR Dispatch sync failed: ' . $e->getMessage());
    $message = 'Synchronization is temporarily unavailable.';
    if ($e instanceof PDOException) {
        $sqlState = (string)$e->getCode();
        $detail = $e->getMessage();
        if ($sqlState === '23000' && str_contains($detail, 'uk_ipca_cvr_dispatches_scheduler')) {
            $message = 'This multi-leg schedule already has a Dispatch row; later legs must sync under the shared reservation without a unique scheduler lock.';
        } elseif ($sqlState === '23000') {
            $message = 'Dispatch could not be stored because of a database uniqueness conflict.';
        }
    }
    $failure = new CvrTemporaryTechnicalFailure($message);
    cvr_dispatch_sync_json($failure->httpStatus(), $failure->payload(cvr_sync_request_id(is_array($payload ?? null) ? $payload : array())));
}
