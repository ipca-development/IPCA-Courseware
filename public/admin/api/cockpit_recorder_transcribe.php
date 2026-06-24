<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';

cw_require_admin();

@set_time_limit(0);
@ini_set('max_execution_time', '0');

$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$mode = trim((string)($_POST['mode'] ?? $_GET['mode'] ?? 'step'));
$wantsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

function cockpit_transcribe_response(bool $ok, string $message, array $payload = array()): void
{
    global $wantsJson;

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(array('ok' => $ok, 'message' => $message), $payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!$ok) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }

    header('Location: /admin/cockpit_recorder.php?transcription=' . urlencode($message));
    exit;
}

try {
    if ($id <= 0) {
        cockpit_transcribe_response(false, 'Recording id is required.');
    }

    $service = new CockpitRecorderService($pdo);
    if ($mode === 'spawn') {
        $service->resetTranscriptionForRetry($id);
        $spawned = $service->spawnTranscriptionWorker($id);
        cockpit_transcribe_response($spawned, $spawned ? 'worker_started' : 'worker_start_failed', array('worker_spawned' => $spawned));
    }

    if ($mode === 'run') {
        $result = $service->processTranscription($id);
    } else {
        $result = $service->processTranscriptionStep($id);
    }
    $ok = (bool)($result['ok'] ?? false);
    $done = (bool)($result['done'] ?? false);
    cockpit_transcribe_response($ok || !$done, $done ? ($ok ? 'completed' : (string)($result['error'] ?? 'Transcription failed.')) : 'processed_next_chunk', $result);
} catch (Throwable $e) {
    cockpit_transcribe_response(false, $e->getMessage());
}
