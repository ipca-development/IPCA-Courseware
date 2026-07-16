<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/FlightRecordDerivationService.php';

cw_require_admin();
header('Content-Type: application/json; charset=utf-8');

function flight_record_preview_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $csvFileId = (int)($_GET['csv_file_id'] ?? $_POST['csv_file_id'] ?? 0);
    if ($csvFileId <= 0) {
        flight_record_preview_json(400, array('ok' => false, 'error' => 'csv_file_id is required.'));
    }

    $preview = (new FlightRecordDerivationService($pdo))->previewFromCsvFile($csvFileId);
    flight_record_preview_json(200, $preview);
} catch (Throwable $e) {
    flight_record_preview_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
