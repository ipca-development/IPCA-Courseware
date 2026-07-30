<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/AviationEvidence/PublishedTranscriptService.php';

header('Content-Type: application/json; charset=utf-8');

function cockpit_publish_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $currentUser = cw_current_user($pdo);
    if (!is_array($currentUser) || (string)($currentUser['role'] ?? '') !== 'admin') {
        cockpit_publish_json(403, array('ok' => false, 'error' => 'Admin access required.'));
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $recordingId = (int)($_GET['recording_id'] ?? $_POST['recording_id'] ?? 0);
    if ($recordingId <= 0) {
        cockpit_publish_json(400, array('ok' => false, 'error' => 'recording_id required.'));
    }

    $service = PublishedTranscriptService::fromPdo($pdo);
    $publishedBy = isset($currentUser['id']) ? (int)$currentUser['id'] : null;

    if ($method === 'GET' && filter_var($_GET['list'] ?? '0', FILTER_VALIDATE_BOOLEAN)) {
        cockpit_publish_json(200, array(
            'ok' => true,
            'recording_id' => $recordingId,
            'versions' => $service->listPublishedVersions($recordingId),
        ));
    }

    if ($method !== 'POST') {
        cockpit_publish_json(405, array('ok' => false, 'error' => 'POST required to publish.'));
    }

    $processingRunId = (int)($_POST['processing_run_id'] ?? 0);
    $result = $processingRunId > 0
        ? $service->publishProcessingRun($recordingId, $processingRunId, $publishedBy)
        : $service->publishLatestForRecording($recordingId, $publishedBy);

    cockpit_publish_json(!empty($result['ok']) ? 200 : 500, $result);
} catch (Throwable $e) {
    cockpit_publish_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
