<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/FlightCircleHistoricalImportService.php';

cw_require_admin();

try {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'ok' => true,
        'status' => (new FlightCircleHistoricalImportService($pdo))->status(10),
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
