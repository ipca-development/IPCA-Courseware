<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';
require_once __DIR__ . '/../../../src/CockpitRecorderAudioRepairService.php';

cw_require_admin();

@ini_set('memory_limit', '256M');
@set_time_limit(300);

header('Content-Type: application/json; charset=utf-8');

function cockpit_audio_repair_chunk_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function cockpit_audio_repair_chunk_header(string $name, string $default = ''): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$key] ?? $default));
}

function cockpit_audio_repair_chunk_safe(string $value, int $max = 128): string
{
    $value = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($value)) ?? '';
    return substr($value, 0, $max);
}

function cockpit_audio_repair_chunk_root(): string
{
    $root = CockpitRecorderService::projectRoot() . '/storage/cockpit_recorder/audio_repair_uploads';
    if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
        throw new RuntimeException('Could not create audio repair upload root.');
    }
    return $root;
}

function cockpit_audio_repair_chunk_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . '/' . $item;
        if (is_dir($child)) {
            cockpit_audio_repair_chunk_remove_tree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

function cockpit_audio_repair_chunk_ext(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, array('m4a', 'mp4', 'aac'), true) ? $ext : 'm4a';
}

try {
    $mode = strtolower(trim((string)($_GET['mode'] ?? $_POST['mode'] ?? cockpit_audio_repair_chunk_header('X-IPCA-Repair-Mode'))));

    if ($mode === 'chunk') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            cockpit_audio_repair_chunk_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
        }
        $session = cockpit_audio_repair_chunk_safe(cockpit_audio_repair_chunk_header('X-IPCA-Repair-Session'));
        $recordingId = cockpit_audio_repair_chunk_safe(cockpit_audio_repair_chunk_header('X-IPCA-Recording-ID'));
        $originalFilename = cockpit_audio_repair_chunk_safe(cockpit_audio_repair_chunk_header('X-IPCA-Original-Filename'), 255);
        $chunkIndex = (int)cockpit_audio_repair_chunk_header('X-IPCA-Chunk-Index', '-1');
        $totalChunks = (int)cockpit_audio_repair_chunk_header('X-IPCA-Total-Chunks', '0');
        $totalSize = (int)cockpit_audio_repair_chunk_header('X-IPCA-Total-Size', '0');
        $chunkSize = (int)cockpit_audio_repair_chunk_header('X-IPCA-Chunk-Size', '0');

        if ($session === '' || $recordingId === '' || $originalFilename === '') {
            cockpit_audio_repair_chunk_json(400, array('ok' => false, 'error' => 'Missing repair upload headers.'));
        }
        if ($chunkIndex < 0 || $totalChunks <= 0 || $chunkIndex >= $totalChunks || $totalChunks > 20000 || $totalSize <= 0) {
            cockpit_audio_repair_chunk_json(400, array('ok' => false, 'error' => 'Invalid chunk metadata.'));
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            cockpit_audio_repair_chunk_json(400, array('ok' => false, 'error' => 'Chunk body is empty.'));
        }
        if (strlen($raw) > 8 * 1024 * 1024) {
            cockpit_audio_repair_chunk_json(400, array('ok' => false, 'error' => 'Chunk is too large.'));
        }
        if ($chunkSize > 0 && strlen($raw) !== $chunkSize) {
            cockpit_audio_repair_chunk_json(400, array('ok' => false, 'error' => 'Chunk size mismatch.'));
        }

        $sessionDir = cockpit_audio_repair_chunk_root() . '/' . $session;
        $chunkDir = $sessionDir . '/chunks';
        if (!is_dir($chunkDir) && !mkdir($chunkDir, 0775, true) && !is_dir($chunkDir)) {
            cockpit_audio_repair_chunk_json(500, array('ok' => false, 'error' => 'Could not create chunk directory.'));
        }

        $meta = array(
            'session' => $session,
            'recording_id' => $recordingId,
            'original_filename' => $originalFilename,
            'total_chunks' => $totalChunks,
            'total_size' => $totalSize,
            'updated_at' => gmdate('c'),
        );
        file_put_contents($sessionDir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $chunkPath = $chunkDir . '/' . str_pad((string)$chunkIndex, 8, '0', STR_PAD_LEFT) . '.part';
        if (file_put_contents($chunkPath, $raw, LOCK_EX) === false) {
            cockpit_audio_repair_chunk_json(500, array('ok' => false, 'error' => 'Could not store chunk.'));
        }

        cockpit_audio_repair_chunk_json(200, array(
            'ok' => true,
            'session' => $session,
            'chunk_index' => $chunkIndex,
            'total_chunks' => $totalChunks,
        ));
    }

    if ($mode === 'finalize') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            cockpit_audio_repair_chunk_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
        }
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            cockpit_audio_repair_chunk_json(400, array('ok' => false, 'error' => 'Invalid JSON payload.'));
        }

        $session = cockpit_audio_repair_chunk_safe((string)($payload['session'] ?? ''));
        $recordingId = trim((string)($payload['recording_id'] ?? ''));
        $note = trim((string)($payload['note'] ?? ''));
        $originalFilename = cockpit_audio_repair_chunk_safe((string)($payload['original_filename'] ?? 'corrected.m4a'), 255);
        $duration = null;
        if (isset($payload['duration_seconds']) && trim((string)$payload['duration_seconds']) !== '') {
            if (!is_numeric($payload['duration_seconds']) || (float)$payload['duration_seconds'] <= 0) {
                cockpit_audio_repair_chunk_json(400, array('ok' => false, 'error' => 'Duration override must be a positive number.'));
            }
            $duration = (float)$payload['duration_seconds'];
        }
        if ($session === '' || $recordingId === '') {
            cockpit_audio_repair_chunk_json(400, array('ok' => false, 'error' => 'Session and recording id are required.'));
        }

        $sessionDir = cockpit_audio_repair_chunk_root() . '/' . $session;
        $metaPath = $sessionDir . '/meta.json';
        $meta = is_file($metaPath) ? json_decode((string)file_get_contents($metaPath), true) : null;
        if (!is_array($meta)) {
            cockpit_audio_repair_chunk_json(404, array('ok' => false, 'error' => 'Repair upload session not found.'));
        }

        $totalChunks = (int)($meta['total_chunks'] ?? 0);
        $expectedSize = (int)($meta['total_size'] ?? 0);
        if ($totalChunks <= 0 || $expectedSize <= 0) {
            cockpit_audio_repair_chunk_json(400, array('ok' => false, 'error' => 'Invalid repair upload metadata.'));
        }

        $assembledDir = $sessionDir . '/assembled';
        if (!is_dir($assembledDir) && !mkdir($assembledDir, 0775, true) && !is_dir($assembledDir)) {
            cockpit_audio_repair_chunk_json(500, array('ok' => false, 'error' => 'Could not create assembly directory.'));
        }
        $assembledPath = $assembledDir . '/corrected.' . cockpit_audio_repair_chunk_ext($originalFilename);
        $out = fopen($assembledPath, 'wb');
        if ($out === false) {
            cockpit_audio_repair_chunk_json(500, array('ok' => false, 'error' => 'Could not assemble corrected audio.'));
        }
        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = $sessionDir . '/chunks/' . str_pad((string)$i, 8, '0', STR_PAD_LEFT) . '.part';
                if (!is_file($chunkPath)) {
                    throw new RuntimeException('Missing chunk ' . ($i + 1) . ' of ' . $totalChunks . '.');
                }
                $in = fopen($chunkPath, 'rb');
                if ($in === false) {
                    throw new RuntimeException('Could not read chunk ' . ($i + 1) . '.');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }
        if ((int)filesize($assembledPath) !== $expectedSize) {
            cockpit_audio_repair_chunk_json(500, array('ok' => false, 'error' => 'Assembled audio size mismatch.'));
        }

        $result = (new CockpitRecorderAudioRepairService($pdo))->repairFromLocalFile(
            $recordingId,
            $assembledPath,
            $originalFilename,
            $duration,
            $note,
            !empty($payload['queue_transcription'])
        );
        cockpit_audio_repair_chunk_remove_tree($sessionDir);

        if (!empty($payload['reconstruct'])) {
            $result['redirect'] = '/admin/api/cockpit_recorder_reconstruct.php?id=' . urlencode((string)$result['recording_id']) . '&replay_source_mode=g3x_only';
        } else {
            $result['redirect'] = '/admin/cockpit_recorder.php?audio_repair=replaced&id=' . urlencode((string)$result['recording_id']);
        }
        cockpit_audio_repair_chunk_json(200, $result);
    }

    cockpit_audio_repair_chunk_json(400, array('ok' => false, 'error' => 'Invalid repair upload mode.'));
} catch (Throwable $e) {
    cockpit_audio_repair_chunk_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
