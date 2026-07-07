<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';

@ini_set('memory_limit', '512M');
@set_time_limit(0);

header('Content-Type: application/json; charset=utf-8');

function cockpit_g3x_finalize_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cockpit_g3x_finalize_uid(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/[^A-Za-z0-9_-]+/', '', $value) ?? '';
    return substr($value, 0, 96);
}

function cockpit_g3x_finalize_meta(string $sessionDir, string $fileType): ?array
{
    $path = $sessionDir . '/' . $fileType . '.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    $decoded = $raw !== false ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : null;
}

function cockpit_g3x_finalize_assemble(string $sessionDir, string $fileType, string $extension): ?string
{
    $meta = cockpit_g3x_finalize_meta($sessionDir, $fileType);
    if ($meta === null) {
        return null;
    }

    $totalChunks = (int)($meta['total_chunks'] ?? 0);
    if ($totalChunks <= 0) {
        throw new RuntimeException('Invalid ' . $fileType . ' upload metadata.');
    }

    $chunkDir = $sessionDir . '/' . $fileType;
    if (!is_dir($chunkDir)) {
        throw new RuntimeException('Missing ' . $fileType . ' chunk directory.');
    }

    $assembledDir = $sessionDir . '/assembled';
    if (!is_dir($assembledDir) && !mkdir($assembledDir, 0775, true) && !is_dir($assembledDir)) {
        throw new RuntimeException('Could not create assembly directory.');
    }

    $assembledPath = $assembledDir . '/' . $fileType . '.' . $extension;
    $out = fopen($assembledPath, 'wb');
    if ($out === false) {
        throw new RuntimeException('Could not create assembled ' . $fileType . ' file.');
    }

    try {
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = $chunkDir . '/' . str_pad((string)$i, 8, '0', STR_PAD_LEFT) . '.part';
            if (!is_file($chunkPath)) {
                throw new RuntimeException('Missing ' . $fileType . ' chunk ' . $i . ' of ' . $totalChunks . '.');
            }
            $in = fopen($chunkPath, 'rb');
            if ($in === false) {
                throw new RuntimeException('Could not read ' . $fileType . ' chunk ' . $i . '.');
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
    } finally {
        fclose($out);
    }

    $expectedSize = (int)($meta['total_size'] ?? 0);
    $actualSize = (int)filesize($assembledPath);
    if ($expectedSize > 0 && $actualSize !== $expectedSize) {
        throw new RuntimeException('Assembled ' . $fileType . ' size mismatch.');
    }

    return $assembledPath;
}

function cockpit_g3x_finalize_remove_tree(string $path): void
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
            cockpit_g3x_finalize_remove_tree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        cockpit_g3x_finalize_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }

    $raw = file_get_contents('php://input');
    $payload = $raw !== false && trim($raw) !== '' ? json_decode($raw, true) : array();
    if (!is_array($payload)) {
        cockpit_g3x_finalize_json(400, array('ok' => false, 'error' => 'Invalid JSON payload.'));
    }

    $recordingUid = cockpit_g3x_finalize_uid((string)($payload['recording_id'] ?? ''));
    if ($recordingUid === '') {
        cockpit_g3x_finalize_json(400, array('ok' => false, 'error' => 'Recording id is required.'));
    }

    $sessionDir = CockpitRecorderService::uploadSessionRoot() . '/' . $recordingUid;
    if (!is_dir($sessionDir)) {
        cockpit_g3x_finalize_json(404, array('ok' => false, 'error' => 'Upload session not found.'));
    }

    $g3xPath = cockpit_g3x_finalize_assemble($sessionDir, 'g3x', 'csv');
    if ($g3xPath === null) {
        cockpit_g3x_finalize_json(400, array('ok' => false, 'error' => 'G3X CSV chunks are missing.'));
    }

    $service = new CockpitRecorderService($pdo);
    $result = $service->storeSupplementalG3X($recordingUid, $g3xPath, (string)($payload['import_profile'] ?? ''));

    cockpit_g3x_finalize_remove_tree($sessionDir);
    cockpit_g3x_finalize_json(200, $result);
} catch (Throwable $e) {
    cockpit_g3x_finalize_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
