<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitReconstructionService.php';

// Phase 1 replay is admin-first because GPS flight tracks can be sensitive.
cw_require_admin();

header('Content-Type: application/json; charset=utf-8');

$id = trim((string)($_GET['id'] ?? ''));
$version = trim((string)($_GET['version'] ?? ''));
if ($id === '') {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Recording id is required.'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $service = new CockpitReconstructionService($pdo);
    $payload = $version === '2'
        ? $service->replayPayloadV2($id)
        : $service->replayPayload($id);
    if (empty($payload['ok'])) {
        http_response_code(404);
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
