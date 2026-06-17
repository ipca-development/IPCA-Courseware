<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';

header('Content-Type: application/json; charset=utf-8');

function cockpit_status_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    cockpit_status_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
}

try {
    $id = trim((string)($_GET['id'] ?? ''));
    if ($id === '') {
        cockpit_status_json(400, array('ok' => false, 'error' => 'Recording id is required.'));
    }

    $service = new CockpitRecorderService($pdo);
    $payload = $service->status($id);
    if (!empty($payload['ok']) && is_array($payload['recording'] ?? null)) {
        $recording = $payload['recording'];
        $status = (string)($recording['transcription_status'] ?? '');
        $progress = (int)($recording['progress'] ?? 0);
        if ($status === 'queued' || ($status === 'transcribing' && $progress === 0)) {
            $service->processStubTranscription((int)($recording['id'] ?? 0));
            $payload = $service->status($id);
        }
    }
    cockpit_status_json(!empty($payload['ok']) ? 200 : 404, $payload);
} catch (Throwable $e) {
    cockpit_status_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
