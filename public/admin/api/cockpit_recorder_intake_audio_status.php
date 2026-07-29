<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function cockpit_intake_audio_status_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function cockpit_intake_audio_relevant_error(mixed $error): string
{
    $text = trim((string)$error);
    if ($text === '') {
        return '';
    }
    $lower = strtolower($text);
    foreach (array('g3x', 'garmin', 'reconstruction', 'replay', 'ads-b', 'adsb', 'samples inside the recording time window') as $needle) {
        if (str_contains($lower, $needle)) {
            return '';
        }
    }
    return $text;
}

try {
    $currentUser = cw_current_user($pdo);
    if (!is_array($currentUser) || (string)($currentUser['role'] ?? '') !== 'admin') {
        cockpit_intake_audio_status_json(403, array('ok' => false, 'error' => 'Admin access required.'));
    }

    $rawIds = trim((string)($_GET['ids'] ?? ''));
    if ($rawIds === '') {
        cockpit_intake_audio_status_json(400, array('ok' => false, 'error' => 'Recording ids are required.'));
    }

    $ids = array_values(array_filter(array_map('intval', explode(',', $rawIds)), static fn(int $id): bool => $id > 0));
    if ($ids === array()) {
        cockpit_intake_audio_status_json(400, array('ok' => false, 'error' => 'Recording ids are required.'));
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, transcription_status, transcription_progress, error_message
         FROM ipca_cockpit_recordings
         WHERE id IN ({$placeholders})"
    );
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $recordings = array();
    foreach (is_array($rows) ? $rows : array() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $status = strtolower(trim((string)($row['transcription_status'] ?? '')));
        $progress = max(0, min(100, (int)($row['transcription_progress'] ?? 0)));
        $recordings[] = array(
            'id' => (int)($row['id'] ?? 0),
            'transcription_status' => $status,
            'transcription_progress' => $progress,
            'can_view_transcript' => $status === 'ready',
            'error_message' => cockpit_intake_audio_relevant_error($row['error_message'] ?? ''),
        );
    }

    cockpit_intake_audio_status_json(200, array('ok' => true, 'recordings' => $recordings));
} catch (Throwable $e) {
    cockpit_intake_audio_status_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
