<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';
require_once __DIR__ . '/../../../src/CockpitRecorderEvidenceQueueService.php';

header('Content-Type: application/json; charset=utf-8');

function cockpit_run_evidence_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $currentUser = cw_current_user($pdo);
    if (!is_array($currentUser) || (string)($currentUser['role'] ?? '') !== 'admin') {
        cockpit_run_evidence_json(403, array('ok' => false, 'error' => 'Admin access required.'));
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        cockpit_run_evidence_json(405, array('ok' => false, 'error' => 'POST required.'));
    }

    $recordingId = (int)($_POST['recording_id'] ?? $_POST['id'] ?? 0);
    if ($recordingId <= 0) {
        cockpit_run_evidence_json(400, array('ok' => false, 'error' => 'Recording id is required.'));
    }

    $queue = CockpitRecorderEvidenceQueueService::fromPdo($pdo);
    $result = $queue->retryProcessing($recordingId);
    cockpit_run_evidence_json(!empty($result['ok']) ? 200 : 500, $result);
} catch (Throwable $e) {
    cockpit_run_evidence_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
