<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';

header('Content-Type: application/json; charset=utf-8');

function cockpit_intake_transcript_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $currentUser = cw_current_user($pdo);
    if (!is_array($currentUser) || (string)($currentUser['role'] ?? '') !== 'admin') {
        cockpit_intake_transcript_json(403, array('ok' => false, 'error' => 'Admin access required.'));
    }

    $id = trim((string)($_GET['id'] ?? ''));
    if ($id === '') {
        cockpit_intake_transcript_json(400, array('ok' => false, 'error' => 'Recording id is required.'));
    }

    $service = new CockpitRecorderService($pdo);
    $recording = $service->recordingByAnyId($id);
    if (!is_array($recording)) {
        cockpit_intake_transcript_json(404, array('ok' => false, 'error' => 'Recording not found.'));
    }

    $recordingId = (int)($recording['id'] ?? 0);
    $hasAudio = trim((string)($recording['storage_path'] ?? '')) !== '';
    cockpit_intake_transcript_json(200, array(
        'ok' => true,
        'recording_id' => $recordingId,
        'recording_uid' => (string)($recording['recording_uid'] ?? ''),
        'aircraft_registration' => (string)($recording['aircraft_registration'] ?? $recording['aircraft_ident'] ?? ''),
        'transcription_status' => (string)($recording['transcription_status'] ?? ''),
        'transcription_progress' => (int)($recording['transcription_progress'] ?? 0),
        'audio_url' => $hasAudio ? ('/admin/cockpit_recorder_audio.php?id=' . rawurlencode((string)$recordingId)) : null,
        'transcript_text' => trim((string)($recording['transcript_text'] ?? '')),
        'original_filename' => (string)($recording['original_filename'] ?? ''),
        'file_size_bytes' => (int)($recording['file_size_bytes'] ?? 0),
        'duration_seconds' => (float)($recording['duration_seconds'] ?? 0),
    ));
} catch (Throwable $e) {
    cockpit_intake_transcript_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
