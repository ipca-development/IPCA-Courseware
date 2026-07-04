<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitReconstructionService.php';

cw_require_admin();

header('Content-Type: application/json; charset=utf-8');

$id = trim((string)(
    $_POST['id']
    ?? $_GET['id']
    ?? $_POST['recording_id']
    ?? $_GET['recording_id']
    ?? $_POST['recording_uid']
    ?? $_GET['recording_uid']
    ?? ''
));

try {
    if ($id === '') {
        throw new RuntimeException('Recording id is required.');
    }

    $result = (new CockpitReconstructionService($pdo))->cancelReconstruction($id);
    if (!($result['ok'] ?? false)) {
        http_response_code(409);
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
