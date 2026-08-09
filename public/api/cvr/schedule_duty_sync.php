<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DeviceAuthService.php';
require_once __DIR__ . '/../../../src/FlightScheduleService.php';
require_once __DIR__ . '/../../../src/CvrSyncException.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cvr_schedule_duty_sync_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new CvrTechnicalReviewRequired('Schedule Duty synchronization method is not supported.');
    }
    $raw = file_get_contents('php://input');
    $payload = $raw !== false && trim($raw) !== '' ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        throw new CvrTechnicalReviewRequired('Schedule Duty synchronization payload is not valid JSON.');
    }
    try {
        $device = (new DeviceAuthService($pdo))->requireDevice();
    } catch (RuntimeException $e) {
        $message = strtolower($e->getMessage());
        if (str_contains($message, 'device token')
            || str_contains($message, 'credential')
            || str_contains($message, 'revoked')) {
            throw new CvrAuthenticationRequired(previous: $e);
        }
        throw $e;
    }

    try {
        $service = new FlightScheduleService($pdo);
        $result = trim((string)($payload['supersedes_scheduler_record_id'] ?? '')) !== ''
            ? $service->supersedeScheduledDutyFromDevice($device, $payload)
            : $service->createScheduledDutyFromDevice($device, $payload);
    } catch (InvalidArgumentException $e) {
        throw new CvrUserCorrectionRequired($e->getMessage());
    } catch (RuntimeException $e) {
        $safe = array(
            'valid distinct', 'replacement reservation', 'replacement crew',
            'replacement route', 'reservation being replaced', 'only an unclaimed',
            'replacement mission', 'replacement aircraft', 'customer',
            'pilot flying', 'logging pic', 'crew', 'valid matching',
            'enrolled device aircraft', 'scheduled', 'schedule start', 'mission',
            'organization', 'registration', 'informative route', 'different material',
            'user account', 'primary customer',
        );
        $message = strtolower($e->getMessage());
        if (array_filter($safe, static fn(string $needle): bool => str_contains($message, $needle))) {
            throw new CvrUserCorrectionRequired($e->getMessage());
        }
        throw $e;
    }
    $result['request_id'] = cvr_sync_request_id($payload);
    $result['error_code'] = null;
    $result['error'] = null;
    $result['retryable'] = false;
    $result['user_action_required'] = false;
    cvr_schedule_duty_sync_json(200, $result);
} catch (CvrSyncException $e) {
    cvr_schedule_duty_sync_json(
        $e->httpStatus(),
        $e->payload(cvr_sync_request_id(is_array($payload ?? null) ? $payload : array()))
    );
} catch (Throwable $e) {
    error_log('CVR Schedule Duty sync failed: ' . $e->getMessage());
    $failure = new CvrTemporaryTechnicalFailure();
    cvr_schedule_duty_sync_json(
        $failure->httpStatus(),
        $failure->payload(cvr_sync_request_id(is_array($payload ?? null) ? $payload : array()))
    );
}
