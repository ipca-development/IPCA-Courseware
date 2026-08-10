<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DeviceAuthService.php';
require_once __DIR__ . '/../../../src/CvrLiveAudioSegmentService.php';

@set_time_limit(240);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cvr_live_audio_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function cvr_live_audio_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$key] ?? ''));
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        cvr_live_audio_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $device = (new DeviceAuthService($pdo))->requireDevice();
    $audio = file_get_contents('php://input');
    if (!is_string($audio) || $audio === '') {
        cvr_live_audio_json(400, array('ok' => false, 'error' => 'Audio segment body is required.'));
    }
    $result = (new CvrLiveAudioSegmentService($pdo))->receive(array(
        'recording_uid' => cvr_live_audio_header('X-IPCA-Recording-ID'),
        'operational_session_uuid' => cvr_live_audio_header('X-IPCA-Operational-Session-UUID'),
        'workflow_flight_record_uuid' => cvr_live_audio_header('X-IPCA-Flight-Record-UUID'),
        'segment_index' => cvr_live_audio_header('X-IPCA-Segment-Index'),
        'started_at' => cvr_live_audio_header('X-IPCA-Segment-Started-At'),
        'duration_seconds' => cvr_live_audio_header('X-IPCA-Segment-Duration'),
        'sha256' => cvr_live_audio_header('X-IPCA-SHA256'),
        'language' => cvr_live_audio_header('X-IPCA-Language') ?: 'en',
    ), $audio, $device);
    cvr_live_audio_json(202, $result);
} catch (InvalidArgumentException $e) {
    cvr_live_audio_json(422, array('ok' => false, 'error' => $e->getMessage()));
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    $lower = strtolower($message);
    $authFailure = str_contains($lower, 'device token')
        || str_contains($lower, 'credential')
        || str_contains($lower, 'revoked');
    cvr_live_audio_json(
        $authFailure ? 401 : (str_contains($lower, 'immutable segment') ? 409 : 503),
        array('ok' => false, 'error' => $message)
    );
} catch (Throwable $e) {
    error_log('[cvr live audio] ' . $e->getMessage());
    cvr_live_audio_json(500, array('ok' => false, 'error' => 'Live audio segment could not be stored.'));
}
