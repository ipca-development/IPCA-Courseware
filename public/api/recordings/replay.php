<?php
declare(strict_types=1);

@ini_set('memory_limit', '512M');

$replayApiFatalReserve = str_repeat('x', 262144);
$replayApiJsonStarted = false;
ob_start();

register_shutdown_function(static function () use (&$replayApiFatalReserve, &$replayApiJsonStarted): void {
    $error = error_get_last();
    $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR);
    if (!$error || !in_array((int)($error['type'] ?? 0), $fatalTypes, true) || $replayApiJsonStarted) {
        return;
    }

    $replayApiFatalReserve = '';
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }

    echo json_encode(array(
        'ok' => false,
        'error' => 'Replay API fatal error: ' . (string)($error['message'] ?? 'unknown fatal error'),
        'fatal_type' => (int)($error['type'] ?? 0),
        'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
});

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitReconstructionService.php';

// Phase 1 replay is admin-first because GPS flight tracks can be sensitive.
cw_require_admin();

header('Content-Type: application/json; charset=utf-8');

function replay_api_json_response(array $payload, int $statusCode = 200): void
{
    global $replayApiJsonStarted;

    http_response_code($statusCode);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $replayApiJsonStarted = true;
    echo $json;
    exit;
}

$id = trim((string)($_GET['id'] ?? ''));
$version = trim((string)($_GET['version'] ?? ''));
$compact = in_array(strtolower(trim((string)($_GET['compact'] ?? ''))), array('1', 'true', 'yes'), true);
$sampleStride = max(1, min(10, (int)($_GET['sample_stride'] ?? 1)));
if ($id === '') {
    replay_api_json_response(array('ok' => false, 'error' => 'Recording id is required.'), 400);
}

try {
    $service = new CockpitReconstructionService($pdo);
    if ($version === '2') {
        $metadata = $service->replayPayloadV2Metadata($id);
        if (empty($metadata['ok'])) {
            replay_api_json_response($metadata, 404);
        }
        http_response_code(200);
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $replayApiJsonStarted = true;
        $service->streamReplayPayloadV2Json($id, $compact, $sampleStride);
        exit;
    }

    $payload = $service->replayPayload($id);
    replay_api_json_response($payload, empty($payload['ok']) ? 404 : 200);
} catch (Throwable $e) {
    replay_api_json_response(array(
        'ok' => false,
        'error' => $e->getMessage(),
        'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
    ), 500);
}
