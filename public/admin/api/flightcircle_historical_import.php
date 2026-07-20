<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/FlightCircleHistoricalImportService.php';

cw_require_admin();

function flightcircle_import_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flightcircle_import_json(int $code, array $payload): void
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
    $upload = is_array($_FILES['flightcircle_csv'] ?? null) ? $_FILES['flightcircle_csv'] : array();
    $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('FlightCircle CSV upload failed with code ' . $error . '.');
    }
    $tmpName = (string)($upload['tmp_name'] ?? '');
    $filename = (string)($upload['name'] ?? 'flightcircle.csv');
    $result = (new FlightCircleHistoricalImportService($pdo))->importUploadedFile($tmpName, $filename, (int)($_SESSION['user_id'] ?? 0) ?: null);
    if ((string)($_POST['format'] ?? '') === 'json') {
        flightcircle_import_json(200, $result);
    }
    flightcircle_import_redirect('/admin/flight_log_garmin_connection.php?flightcircle_batch=' . urlencode((string)$result['batch_id']));
} catch (Throwable $e) {
    if ((string)($_POST['format'] ?? '') === 'json') {
        flightcircle_import_json(500, array('ok' => false, 'error' => $e->getMessage()));
    }
    flightcircle_import_redirect('/admin/flight_log_garmin_connection.php?error=' . urlencode($e->getMessage()));
}
