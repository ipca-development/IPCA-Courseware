<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DeviceAuthService.php';
require_once __DIR__ . '/../../../src/CvrLiveCockpitMonitorService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function cvr_monitor_chunk_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function cvr_monitor_chunk_header(string $name): string
{
    return trim((string)($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? ''));
}

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        cvr_monitor_chunk_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $device = (new DeviceAuthService($pdo))->requireDevice();
    $audio = file_get_contents('php://input');
    if (!is_string($audio) || $audio === '') {
        cvr_monitor_chunk_json(400, array('ok' => false, 'error' => 'Monitor audio chunk body is required.'));
    }
    $result = (new CvrLiveCockpitMonitorService($pdo))->receiveChunk(array(
        'broadcast_uuid' => cvr_monitor_chunk_header('X-IPCA-Monitor-Broadcast-UUID'),
        'chunk_uuid' => cvr_monitor_chunk_header('X-IPCA-Monitor-Chunk-UUID'),
        'operational_session_uuid' => cvr_monitor_chunk_header('X-IPCA-Operational-Session-UUID'),
        'sequence_number' => cvr_monitor_chunk_header('X-IPCA-Monitor-Sequence'),
        'started_at_utc' => cvr_monitor_chunk_header('X-IPCA-Monitor-Started-At'),
        'duration_seconds' => cvr_monitor_chunk_header('X-IPCA-Monitor-Duration'),
        'sha256' => cvr_monitor_chunk_header('X-IPCA-SHA256'),
    ), $audio, $device);
    cvr_monitor_chunk_json(202, $result);
} catch (InvalidArgumentException $e) {
    cvr_monitor_chunk_json(422, array('ok' => false, 'error' => $e->getMessage()));
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    $status = str_contains(strtolower($message), 'token') ? 401 : 409;
    cvr_monitor_chunk_json($status, array('ok' => false, 'error' => $message));
} catch (Throwable $e) {
    error_log('[cvr live cockpit monitor chunk] ' . $e->getMessage());
    cvr_monitor_chunk_json(500, array('ok' => false, 'error' => 'Monitor audio chunk could not be accepted.'));
}
