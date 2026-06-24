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

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        cockpit_chunk_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }

    $recordingUid = cockpit_chunk_uid(cockpit_chunk_header('X-IPCA-Recording-ID'));
    if ($recordingUid === '') {
        cockpit_chunk_json(400, array('ok' => false, 'error' => 'Recording id is required.'));
    }

    $fileType = strtolower(cockpit_chunk_header('X-IPCA-File-Type'));
    if (!in_array($fileType, array('audio', 'ahrs', 'gps'), true)) {
        cockpit_chunk_json(400, array('ok' => false, 'error' => 'Invalid file type.'));
    }

    $chunkIndex = (int)cockpit_chunk_header('X-IPCA-Chunk-Index', '-1');
    $totalChunks = (int)cockpit_chunk_header('X-IPCA-Total-Chunks', '0');
    $totalSize = (int)cockpit_chunk_header('X-IPCA-Total-Size', '0');
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
    if (strlen($raw) > 8 * 1024 * 1024) {
        cockpit_chunk_json(400, array('ok' => false, 'error' => 'Chunk is too large.'));
    }

    $root = CockpitRecorderService::uploadSessionRoot();
    $sessionDir = $root . '/' . $recordingUid;
    $chunkDir = $sessionDir . '/' . $fileType;
    if (!is_dir($chunkDir) && !mkdir($chunkDir, 0775, true) && !is_dir($chunkDir)) {
        cockpit_chunk_json(500, array('ok' => false, 'error' => 'Could not create upload session.'));
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

    $chunkPath = $chunkDir . '/' . str_pad((string)$chunkIndex, 8, '0', STR_PAD_LEFT) . '.part';
    if (file_put_contents($chunkPath, $raw, LOCK_EX) === false) {
        cockpit_chunk_json(500, array('ok' => false, 'error' => 'Could not store upload chunk.'));
    }

    cockpit_chunk_json(200, array(
        'ok' => true,
        'recording_id' => $recordingUid,
        'file_type' => $fileType,
        'chunk_index' => $chunkIndex,
        'total_chunks' => $totalChunks,
        'received_bytes' => strlen($raw),
    ));
} catch (Throwable $e) {
    cockpit_chunk_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
