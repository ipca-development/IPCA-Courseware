<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/GarminAuthSessionService.php';
require_once __DIR__ . '/../../../src/GarminCloudIntegrationService.php';
require_once __DIR__ . '/../../../src/GarminProviderStateService.php';

cw_require_admin();
header('Content-Type: application/json; charset=utf-8');

function garmin_cloud_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        garmin_cloud_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $action = trim((string)($_POST['action'] ?? ''));
    $user = cw_current_user($pdo) ?: array();
    $service = new GarminCloudIntegrationService($pdo);
    $actorUserId = (int)($user['id'] ?? 0);

    if ($action === 'test_connection') {
        garmin_cloud_json(200, array('ok' => true, 'result' => $service->testConnection($actorUserId)));
    }
    if ($action === 'auth_start') {
        garmin_cloud_json(200, array('ok' => true, 'result' => (new GarminAuthSessionService($pdo))->start($actorUserId)));
    }
    if ($action === 'auth_status') {
        garmin_cloud_json(200, array('ok' => true, 'result' => (new GarminAuthSessionService($pdo))->status($actorUserId)));
    }
    if ($action === 'auth_complete') {
        garmin_cloud_json(200, array('ok' => true, 'result' => (new GarminAuthSessionService($pdo))->complete($actorUserId)));
    }
    if ($action === 'auth_cancel') {
        garmin_cloud_json(200, array('ok' => true, 'result' => (new GarminAuthSessionService($pdo))->cancel($actorUserId)));
    }
    if ($action === 'auth_reauthenticate') {
        garmin_cloud_json(200, array('ok' => true, 'result' => (new GarminAuthSessionService($pdo))->reauthenticate($actorUserId)));
    }
    if ($action === 'initial_sync') {
        garmin_cloud_json(200, array('ok' => true, 'result' => $service->runSync('initial', 'manual', $actorUserId)));
    }
    if ($action === 'incremental_sync') {
        garmin_cloud_json(200, array('ok' => true, 'result' => $service->runSync('incremental', 'manual', $actorUserId)));
    }
    if ($action === 'full_reconciliation') {
        garmin_cloud_json(200, array('ok' => true, 'result' => $service->runSync('reconciliation', 'manual', $actorUserId)));
    }
    if ($action === 'download_source') {
        $uuid = trim((string)($_POST['flight_data_log_uuid'] ?? ''));
        garmin_cloud_json(200, array('ok' => true, 'result' => $service->downloadSource($uuid, $actorUserId)));
    }
    if ($action === 'mark_flight_log_visible') {
        (new GarminProviderStateService($pdo))->markAcceptanceCheck('flight_log_status_visible', true, 'Flight Log Garmin Connection UI is visible in testing.');
        garmin_cloud_json(200, array('ok' => true, 'result' => 'flight_log_status_visible marked.'));
    }
    if ($action === 'enable_scheduled_sync') {
        (new GarminProviderStateService($pdo))->updateState(array('scheduled_sync_enabled' => 1), 'garmin_scheduled_sync_enabled', $actorUserId);
        garmin_cloud_json(200, array('ok' => true, 'result' => 'Scheduled Garmin sync enabled.'));
    }
    garmin_cloud_json(400, array('ok' => false, 'error' => 'Unknown Garmin Cloud action.'));
} catch (Throwable $e) {
    garmin_cloud_json(400, array('ok' => false, 'error' => $e->getMessage()));
}
