<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';

@ini_set('upload_max_filesize', '2048M');
@ini_set('post_max_size', '2048M');
@ini_set('memory_limit', '512M');
@set_time_limit(0);

header('Content-Type: application/json; charset=utf-8');

function cockpit_upload_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    cockpit_upload_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
}

try {
    if (empty($_FILES['audio']) || !is_array($_FILES['audio'])) {
        cockpit_upload_json(400, array('ok' => false, 'error' => 'Missing audio file field "audio".'));
    }

    $metadata = array(
        'recording_id' => $_POST['recording_id'] ?? '',
        'started_at' => $_POST['started_at'] ?? '',
        'duration' => $_POST['duration'] ?? 0,
        'input_device' => $_POST['input_device'] ?? '',
        'language' => $_POST['language'] ?? 'en',
    );

    $service = new CockpitRecorderService($pdo);
    cockpit_upload_json(200, $service->storeUploadedRecording($_FILES['audio'], $metadata));
} catch (Throwable $e) {
    cockpit_upload_json(400, array('ok' => false, 'error' => $e->getMessage()));
}
