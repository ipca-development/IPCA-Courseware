<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/GarminHistoricalBackfillService.php';

cw_require_admin();

function garmin_historical_upload_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function garmin_historical_upload_json(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new RuntimeException('POST required.');
    }
    $service = new GarminHistoricalBackfillService($pdo);
    $action = trim((string)($_POST['action'] ?? 'upload'));
    $aircraftHint = trim((string)($_POST['aircraft_hint'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    if ($action === 'create_batch') {
        $batchId = $service->createBatchRecord((int)($_SESSION['user_id'] ?? 0) ?: null, $aircraftHint, $notes);
        garmin_historical_upload_json(200, array('ok' => true, 'batch_id' => $batchId));
    }
    $files = is_array($_FILES['garmin_csv_files'] ?? null) ? $_FILES['garmin_csv_files'] : array();
    $batchId = (int)($_POST['batch_id'] ?? 0);
    if ($action === 'upload_to_batch' || $batchId > 0) {
        $result = $service->addUploadedFilesToBatch($batchId, $files, $aircraftHint);
    } else {
        $result = $service->createBatchFromUpload($files, (int)($_SESSION['user_id'] ?? 0) ?: null, $aircraftHint, $notes);
    }
    if ((string)($_POST['format'] ?? '') === 'json') {
        garmin_historical_upload_json(200, $result);
    }
    garmin_historical_upload_redirect('/admin/flight_log_garmin_connection.php?historical_batch=' . urlencode((string)$result['batch_id']));
} catch (Throwable $e) {
    if ((string)($_POST['format'] ?? '') === 'json') {
        garmin_historical_upload_json(500, array('ok' => false, 'error' => $e->getMessage()));
    }
    garmin_historical_upload_redirect('/admin/flight_log_garmin_connection.php?error=' . urlencode($e->getMessage()));
}
