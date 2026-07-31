<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';
require_once __DIR__ . '/../../../src/CockpitRecorderEvidenceQueueService.php';
require_once __DIR__ . '/../../../src/CockpitRecorderDebriefQueueService.php';

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

        $recorder = new CockpitRecorderService($pdo);
        $evidenceQueue = CockpitRecorderEvidenceQueueService::fromPdo($pdo);
        $recordings = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $recordingId = (int)($row['id'] ?? 0);
            $recording = $recordingId > 0 ? $recorder->recordingByAnyId((string)$recordingId) : null;
            if (is_array($recording) && strtolower(trim((string)($recording['transcription_status'] ?? ''))) === 'ready') {
                if ($evidenceQueue->needsEvidenceProcessing($recording)) {
                    $evidenceQueue->kickoffOnce($recordingId);
                }
                CockpitRecorderDebriefQueueService::fromPdo($pdo)->onTranscriptionReady($recordingId);
                $recording = $recorder->recordingByAnyId((string)$recordingId) ?? $recording;
            }

            $pipeline = is_array($recording) ? $evidenceQueue->publicStatusForRecording($recording) : array(
                'pipeline_stage' => strtolower(trim((string)($row['transcription_status'] ?? ''))),
                'display_status' => strtolower(trim((string)($row['transcription_status'] ?? ''))),
                'display_progress' => max(0, min(100, (int)($row['transcription_progress'] ?? 0))),
                'transcription_status' => strtolower(trim((string)($row['transcription_status'] ?? ''))),
                'transcription_progress' => max(0, min(100, (int)($row['transcription_progress'] ?? 0))),
                'evidence_in_progress' => false,
                'needs_evidence_processing' => false,
                'evidence_step' => null,
                'evidence_step_label' => null,
                'publishable' => false,
                'can_view_transcript' => strtolower(trim((string)($row['transcription_status'] ?? ''))) === 'ready',
                'can_view_structured_transcript' => false,
            );

            $recordings[] = array_merge(array(
                'id' => $recordingId,
                'error_message' => cockpit_intake_audio_relevant_error($row['error_message'] ?? ''),
            ), $pipeline);
        }

    cockpit_intake_audio_status_json(200, array('ok' => true, 'recordings' => $recordings));
} catch (Throwable $e) {
    cockpit_intake_audio_status_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
