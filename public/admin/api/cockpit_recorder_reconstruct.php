<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitReconstructionService.php';

cw_require_admin();

@set_time_limit(0);

$id = trim((string)($_POST['id'] ?? $_GET['id'] ?? ''));
$altimeterSetting = trim((string)($_POST['altimeter_setting_inhg'] ?? $_GET['altimeter_setting_inhg'] ?? ''));
$airportElevation = trim((string)($_POST['airport_elevation_ft'] ?? $_GET['airport_elevation_ft'] ?? ''));
$oatC = trim((string)($_POST['oat_c'] ?? $_GET['oat_c'] ?? ''));
$wantsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

try {
    if ($id === '') {
        throw new RuntimeException('Recording id is required.');
    }

    $service = new CockpitReconstructionService($pdo);
    $options = array();
    if ($altimeterSetting !== '') {
        if (!is_numeric($altimeterSetting)) {
            throw new RuntimeException('Altimeter setting must be numeric.');
        }
        $options['altimeter_setting_inhg'] = (float)$altimeterSetting;
    }
    if ($airportElevation !== '') {
        if (!is_numeric($airportElevation)) {
            throw new RuntimeException('Airport elevation must be numeric.');
        }
        $options['airport_elevation_ft'] = (float)$airportElevation;
    }
    if ($oatC !== '') {
        if (!is_numeric($oatC)) {
            throw new RuntimeException('OAT must be numeric.');
        }
        $options['oat_c'] = (float)$oatC;
    }
    $result = $service->reconstruct($id, $options);

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: /admin/cockpit_recorder.php?reconstructed=' . urlencode($id));
    exit;
} catch (Throwable $e) {
    if ($wantsJson) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
    exit;
}
