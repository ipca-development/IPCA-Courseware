<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/AdsbHistoricalCorridorService.php';

cw_require_admin();
header('Content-Type: application/json; charset=utf-8');

function fr_adsb_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        fr_adsb_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $flightRecordId = (int)($_POST['flight_record_id'] ?? $_GET['flight_record_id'] ?? 0);
    $radiusNm = is_numeric($_POST['radius_nm'] ?? null) ? (float)$_POST['radius_nm'] : 10.0;
    $user = cw_current_user($pdo) ?: array();
    $service = new AdsbHistoricalCorridorService($pdo);
    $request = $service->createRequestForFlightRecord($flightRecordId, (int)($user['id'] ?? 0), $radiusNm);
    $result = $service->fetchAndNormalize((int)$request['id']);
    fr_adsb_json(200, array('ok' => true, 'request_uuid' => $request['request_uuid'], 'result' => $result));
} catch (Throwable $e) {
    fr_adsb_json(400, array('ok' => false, 'error' => $e->getMessage()));
}
