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
    $recording = $service->recordingByAnyId($id);
    if (!$recording) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Recording not found.';
        exit;
    }

    $relativePath = (string)($recording['storage_path'] ?? '');
    $path = CockpitRecorderService::projectRoot() . '/' . ltrim($relativePath, '/');
    $audioRoot = realpath(CockpitRecorderService::audioRoot());
    $realPath = realpath($path);
    if ($audioRoot === false || $realPath === false || !str_starts_with($realPath, $audioRoot) || !is_file($realPath)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Audio file not found.';
        exit;
    }

    $mime = (string)($recording['mime_type'] ?? '');
    if ($mime === '') {
        $mime = 'audio/mp4';
    }
    $filename = (string)($recording['original_filename'] ?? '');
    if ($filename === '') {
        $filename = (string)($recording['recording_uid'] ?? 'recording') . '.' . (string)($recording['file_extension'] ?? 'm4a');
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($realPath));
    header('Accept-Ranges: bytes');
    if ((string)($_GET['download'] ?? '') === '1') {
        header('Content-Disposition: attachment; filename="' . addcslashes(basename($filename), '"\\') . '"');
    }
    readfile($realPath);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
    exit;
}
