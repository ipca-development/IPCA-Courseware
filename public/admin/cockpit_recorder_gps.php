<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/CockpitRecorderService.php';

cw_require_admin();

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Recording id is required.';
    exit;
}

try {
    $service = new CockpitRecorderService($pdo);
    $file = $service->gpsFileForRecording($id);
    if (!$file) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'GPS file not found.';
        exit;
    }

    header('Content-Type: ' . $file['mime']);
    header('Content-Length: ' . (string)filesize($file['path']));
    header('Content-Disposition: attachment; filename="' . addcslashes(basename($file['filename']), '"\\') . '"');
    readfile($file['path']);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
    exit;
}
