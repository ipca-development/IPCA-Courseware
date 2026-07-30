<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';
require_once __DIR__ . '/../../../src/CockpitRecorderEvidenceQueueService.php';
require_once __DIR__ . '/../../../src/CockpitRecorderDebriefQueueService.php';
require_once __DIR__ . '/../../../src/AviationEvidence/TranscriptReviewService.php';

header('Content-Type: application/json; charset=utf-8');

function cockpit_transcript_review_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $currentUser = cw_current_user($pdo);
    if (!is_array($currentUser) || (string)($currentUser['role'] ?? '') !== 'admin') {
        cockpit_transcript_review_json(403, array('ok' => false, 'error' => 'Admin access required.'));
    }

    $id = trim((string)($_GET['id'] ?? ''));
    if ($id === '') {
        cockpit_transcript_review_json(400, array('ok' => false, 'error' => 'Recording id is required.'));
    }

    $layer = trim((string)($_GET['layer'] ?? ''));
    $preferredLayer = $layer !== '' ? $layer : null;

    $service = new CockpitRecorderService($pdo);
    $recording = $service->recordingByAnyId($id);
    if (!is_array($recording)) {
        cockpit_transcript_review_json(404, array('ok' => false, 'error' => 'Recording not found.'));
    }

    $autoQueue = !filter_var($_GET['no_auto_queue'] ?? '0', FILTER_VALIDATE_BOOLEAN);
    $evidenceQueue = null;
    if ($autoQueue) {
        $queue = CockpitRecorderEvidenceQueueService::fromPdo($pdo);
        if ($queue->needsEvidenceProcessing($recording)) {
            $evidenceQueue = $queue->ensureQueued((int)($recording['id'] ?? 0));
            $recording = $service->recordingByAnyId($id) ?? $recording;
        }
        if (strtolower((string)($recording['transcription_status'] ?? '')) === 'ready') {
            CockpitRecorderDebriefQueueService::fromPdo($pdo)->onTranscriptionReady((int)($recording['id'] ?? 0));
        }
    }

    $payload = TranscriptReviewService::fromPdo($pdo)->buildReviewPayload($recording, $preferredLayer);
    if ($evidenceQueue !== null) {
        $payload['evidence_queue'] = $evidenceQueue;
    }
    cockpit_transcript_review_json(200, $payload);
} catch (Throwable $e) {
    cockpit_transcript_review_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
