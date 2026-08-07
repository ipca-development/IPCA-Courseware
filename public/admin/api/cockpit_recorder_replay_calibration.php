<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/AircraftSettingsService.php';

header('Content-Type: application/json; charset=utf-8');
cw_require_admin();

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => 'POST required.'), JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = file_get_contents('php://input');
$decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
$body = is_array($decoded) ? $decoded : $_POST;

$aircraftId = (int)($body['aircraft_id'] ?? 0);
$calibration = is_array($body['calibration'] ?? null) ? $body['calibration'] : array();
$reason = trim((string)($body['reason'] ?? 'Replay camera calibration default'));
$seedOnlyIfMissing = !empty($body['seed_only_if_missing']);

try {
    if ($aircraftId <= 0) {
        throw new RuntimeException('aircraft_id is required.');
    }
    if ($calibration === array()) {
        throw new RuntimeException('calibration payload is required.');
    }

    $service = new AircraftSettingsService($pdo);
    if ($seedOnlyIfMissing) {
        $resolved = $service->resolvedForAircraftId($aircraftId);
        $layout = is_array($resolved['presentation']['layout'] ?? null) ? $resolved['presentation']['layout'] : array();
        if (isset($layout['camera_calibration']) && is_array($layout['camera_calibration'])) {
            echo json_encode(array(
                'ok' => true,
                'seeded' => false,
                'calibration' => $layout['camera_calibration'],
                'message' => 'Aircraft already has a shared calibration default.',
            ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
        $reason = 'Auto-seeded from admin browser calibration';
    }

    $actor = cw_current_user($pdo);
    $actorId = is_array($actor) ? (int)($actor['id'] ?? 0) : 0;
    $saved = $service->saveCameraCalibrationDefault(
        $aircraftId,
        $calibration,
        $actorId > 0 ? $actorId : null,
        $reason !== '' ? $reason : 'Replay camera calibration default'
    );

    echo json_encode(array(
        'ok' => true,
        'seeded' => true,
        'calibration' => $saved,
        'message' => 'Aircraft replay calibration default saved.',
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
