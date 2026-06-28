<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitReconstructionService.php';

cw_require_admin();

header('Content-Type: application/json; charset=utf-8');

$id = trim((string)(
    $_GET['id']
    ?? $_GET['recording_id']
    ?? $_GET['recording_uid']
    ?? ''
));

if ($id === '') {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Recording id is required.'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $service = new CockpitReconstructionService($pdo);
    $result = $service->reconstructionJobStatus($id);
    if (!($result['ok'] ?? false)) {
        http_response_code(404);
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
