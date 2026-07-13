<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/AdsbHistoricalCorridorService.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

function fr_traffic_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        fr_traffic_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $flightRecordId = (int)($_GET['flight_record_id'] ?? 0);
    if ($flightRecordId <= 0) {
        fr_traffic_json(400, array('ok' => false, 'error' => 'flight_record_id is required.'));
    }
    $traffic = (new AdsbHistoricalCorridorService($pdo))->trafficForFlightRecord($flightRecordId);
    fr_traffic_json(200, array('ok' => true, 'flight_record_id' => $flightRecordId, 'traffic' => $traffic));
} catch (Throwable $e) {
    fr_traffic_json(400, array('ok' => false, 'error' => $e->getMessage()));
}
