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

    if ((string)($_GET['download'] ?? '') === '1') {
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($realPath));
        header('Accept-Ranges: bytes');
        header('Content-Disposition: attachment; filename="' . addcslashes(basename($filename), '"\\') . '"');
        readfile($realPath);
        exit;
    }

    $size = filesize($realPath);
    $start = 0;
    $end = $size > 0 ? $size - 1 : 0;
    $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
    if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches)) {
        if ($matches[1] === '' && $matches[2] !== '') {
            $suffixLength = max(0, (int)$matches[2]);
            $start = max(0, $size - $suffixLength);
        } elseif ($matches[1] !== '') {
            $start = max(0, (int)$matches[1]);
        }
        if ($matches[2] !== '' && $matches[1] !== '') {
            $end = min($end, (int)$matches[2]);
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . (string)$size);
            exit;
        }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . (string)$size);
    }

    $length = $end - $start + 1;
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . (string)$length);

    $handle = fopen($realPath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Could not open audio file.');
    }
    fseek($handle, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunkSize = min(8192, $remaining);
        $buffer = fread($handle, $chunkSize);
        if ($buffer === false || $buffer === '') {
            break;
        }
        echo $buffer;
        $remaining -= strlen($buffer);
        if (connection_aborted()) {
            break;
        }
    }
    fclose($handle);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
    exit;
}
