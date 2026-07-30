<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';
require_once __DIR__ . '/../../../src/AviationEvidence/TerminologyCorrectionService.php';

header('Content-Type: application/json; charset=utf-8');

function cockpit_terminology_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $currentUser = cw_current_user($pdo);
    if (!is_array($currentUser) || (string)($currentUser['role'] ?? '') !== 'admin') {
        cockpit_terminology_json(403, array('ok' => false, 'error' => 'Admin access required.'));
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        cockpit_terminology_json(405, array('ok' => false, 'error' => 'POST required.'));
    }

    $action = trim((string)($_POST['action'] ?? ''));
    $service = TerminologyCorrectionService::fromPdo($pdo);
    $reviewerUserId = isset($currentUser['id']) ? (int)$currentUser['id'] : null;

    if ($action === 'propose') {
        $recordingId = (int)($_POST['recording_id'] ?? 0);
        if ($recordingId <= 0) {
            cockpit_terminology_json(400, array('ok' => false, 'error' => 'recording_id required.'));
        }

        $recorder = new CockpitRecorderService($pdo);
        $recording = $recorder->recordingByAnyId((string)$recordingId);
        if (!is_array($recording)) {
            cockpit_terminology_json(404, array('ok' => false, 'error' => 'Recording not found.'));
        }

        $row = $service->propose(
            $recordingId,
            (string)($_POST['raw_text'] ?? ''),
            (string)($_POST['corrected_text'] ?? ''),
            isset($_POST['speech_segment_id']) && $_POST['speech_segment_id'] !== '' ? (int)$_POST['speech_segment_id'] : null,
            isset($_POST['start_time_ms']) && $_POST['start_time_ms'] !== '' ? (int)$_POST['start_time_ms'] : null,
            isset($_POST['end_time_ms']) && $_POST['end_time_ms'] !== '' ? (int)$_POST['end_time_ms'] : null,
            $reviewerUserId
        );

        cockpit_terminology_json(200, array('ok' => true, 'correction' => $row));
    }

    if ($action === 'accept' || $action === 'reject') {
        $correctionUuid = trim((string)($_POST['correction_uuid'] ?? ''));
        if ($correctionUuid === '') {
            cockpit_terminology_json(400, array('ok' => false, 'error' => 'correction_uuid required.'));
        }

        $row = $service->updateStatus($correctionUuid, $action === 'accept' ? 'accepted' : 'rejected', $reviewerUserId);
        cockpit_terminology_json(200, array('ok' => true, 'correction' => $row));
    }

    cockpit_terminology_json(400, array('ok' => false, 'error' => 'Unknown action.'));
} catch (Throwable $e) {
    cockpit_terminology_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
