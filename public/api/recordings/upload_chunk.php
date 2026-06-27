<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';

@ini_set('memory_limit', '256M');
@set_time_limit(300);

header('Content-Type: application/json; charset=utf-8');

function cockpit_chunk_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cockpit_chunk_header(string $name, string $default = ''): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$key] ?? $default));
}

function cockpit_chunk_uid(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/[^A-Za-z0-9_-]+/', '', $value) ?? '';
    return substr($value, 0, 96);
}

function cockpit_chunk_file_type(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, array('audio', 'ahrs', 'gps', 'g3x'), true) ? $value : '';
}

function cockpit_chunk_session_dir(string $recordingUid): string
{
    return CockpitRecorderService::uploadSessionRoot() . '/' . $recordingUid;
}

function cockpit_chunk_received_indices(string $chunkDir): array
{
    if (!is_dir($chunkDir)) {
        return array();
    }

    $received = array();
    $files = scandir($chunkDir);
    if (!is_array($files)) {
        return array();
    }

    foreach ($files as $file) {
        if (!preg_match('/^(\d{8})\.part$/', $file, $matches)) {
            continue;
        }
        $index = (int)$matches[1];
        $path = $chunkDir . '/' . $file;
        if (is_file($path) && filesize($path) > 0) {
            $received[] = $index;
        }
    }

    sort($received, SORT_NUMERIC);
    return $received;
}

function cockpit_chunk_ensure_disk_space(string $path, int $requiredBytes): void
{
    $free = @disk_free_space($path);
    if ($free !== false && $free < max($requiredBytes + (32 * 1024 * 1024), 64 * 1024 * 1024)) {
        throw new RuntimeException('Server storage is low. Free disk space and retry the upload.');
    }
}

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $recordingUid = cockpit_chunk_uid((string)($_GET['recording_id'] ?? ''));
        if ($recordingUid === '') {
            cockpit_chunk_json(400, array('ok' => false, 'error' => 'Recording id is required.'));
        }

        $fileType = cockpit_chunk_file_type((string)($_GET['file_type'] ?? ''));
        if ($fileType === '') {
            cockpit_chunk_json(400, array('ok' => false, 'error' => 'Invalid file type.'));
        }

        $sessionDir = cockpit_chunk_session_dir($recordingUid);
        $chunkDir = $sessionDir . '/' . $fileType;
        $metaPath = $sessionDir . '/' . $fileType . '.json';
        $meta = null;
        if (is_file($metaPath)) {
            $decoded = json_decode((string)file_get_contents($metaPath), true);
            $meta = is_array($decoded) ? $decoded : null;
        }

        $received = cockpit_chunk_received_indices($chunkDir);
        cockpit_chunk_json(200, array(
            'ok' => true,
            'recording_id' => $recordingUid,
            'file_type' => $fileType,
            'received_chunks' => $received,
            'received_count' => count($received),
            'total_chunks' => (int)($meta['total_chunks'] ?? 0),
            'total_size' => (int)($meta['total_size'] ?? 0),
        ));
    }

    if ($method !== 'POST') {
        cockpit_chunk_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }

    $recordingUid = cockpit_chunk_uid(cockpit_chunk_header('X-IPCA-Recording-ID'));
    if ($recordingUid === '') {
        cockpit_chunk_json(400, array('ok' => false, 'error' => 'Recording id is required.'));
    }

    $fileType = cockpit_chunk_file_type(cockpit_chunk_header('X-IPCA-File-Type'));
    if ($fileType === '') {
        cockpit_chunk_json(400, array('ok' => false, 'error' => 'Invalid file type.'));
    }

    $chunkIndex = (int)cockpit_chunk_header('X-IPCA-Chunk-Index', '-1');
    $totalChunks = (int)cockpit_chunk_header('X-IPCA-Total-Chunks', '0');
    $totalSize = (int)cockpit_chunk_header('X-IPCA-Total-Size', '0');
    $chunkSize = (int)cockpit_chunk_header('X-IPCA-Chunk-Size', '0');
    if ($chunkIndex < 0 || $totalChunks <= 0 || $chunkIndex >= $totalChunks) {
        cockpit_chunk_json(400, array('ok' => false, 'error' => 'Invalid chunk index.'));
    }
    if ($totalChunks > 20000 || $totalSize < 0) {
        cockpit_chunk_json(400, array('ok' => false, 'error' => 'Invalid upload size.'));
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        cockpit_chunk_json(400, array('ok' => false, 'error' => 'Chunk body is empty.'));
    }
    $receivedBytes = strlen($raw);
    if ($receivedBytes > 8 * 1024 * 1024) {
        cockpit_chunk_json(400, array('ok' => false, 'error' => 'Chunk is too large.'));
    }
    if ($chunkSize > 0 && $chunkSize !== $receivedBytes) {
        cockpit_chunk_json(400, array(
            'ok' => false,
            'error' => 'Chunk size mismatch. Expected ' . $chunkSize . ' bytes, received ' . $receivedBytes . '.',
        ));
    }

    $root = CockpitRecorderService::uploadSessionRoot();
    if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
        cockpit_chunk_json(500, array('ok' => false, 'error' => 'Could not create upload root.'));
    }

    cockpit_chunk_ensure_disk_space($root, $receivedBytes);

    $sessionDir = cockpit_chunk_session_dir($recordingUid);
    $chunkDir = $sessionDir . '/' . $fileType;
    if (!is_dir($chunkDir) && !mkdir($chunkDir, 0775, true) && !is_dir($chunkDir)) {
        cockpit_chunk_json(500, array('ok' => false, 'error' => 'Could not create upload session.'));
    }

    $chunkPath = $chunkDir . '/' . str_pad((string)$chunkIndex, 8, '0', STR_PAD_LEFT) . '.part';
    if (is_file($chunkPath) && filesize($chunkPath) === $receivedBytes) {
        cockpit_chunk_json(200, array(
            'ok' => true,
            'recording_id' => $recordingUid,
            'file_type' => $fileType,
            'chunk_index' => $chunkIndex,
            'total_chunks' => $totalChunks,
            'received_bytes' => $receivedBytes,
            'already_present' => true,
        ));
    }

    $meta = array(
        'recording_id' => $recordingUid,
        'file_type' => $fileType,
        'total_chunks' => $totalChunks,
        'total_size' => $totalSize,
        'original_filename' => cockpit_chunk_header('X-IPCA-Original-Filename'),
        'mime_type' => cockpit_chunk_header('X-IPCA-Mime-Type'),
        'updated_at' => gmdate('c'),
    );
    file_put_contents($sessionDir . '/' . $fileType . '.json', json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    if (file_put_contents($chunkPath, $raw, LOCK_EX) === false) {
        cockpit_chunk_json(500, array('ok' => false, 'error' => 'Could not store upload chunk.'));
    }
    clearstatcache(true, $chunkPath);
    if (!is_file($chunkPath) || filesize($chunkPath) !== $receivedBytes) {
        @unlink($chunkPath);
        cockpit_chunk_json(500, array('ok' => false, 'error' => 'Uploaded chunk verification failed.'));
    }

    cockpit_chunk_json(200, array(
        'ok' => true,
        'recording_id' => $recordingUid,
        'file_type' => $fileType,
        'chunk_index' => $chunkIndex,
        'total_chunks' => $totalChunks,
        'received_bytes' => $receivedBytes,
    ));
} catch (Throwable $e) {
    error_log('[cockpit upload_chunk] ' . $e->getMessage());
    cockpit_chunk_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
