<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderEvidenceQueueService.php';

header('Content-Type: application/json; charset=utf-8');

function cockpit_run_evidence_batch_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $currentUser = cw_current_user($pdo);
    if (!is_array($currentUser) || (string)($currentUser['role'] ?? '') !== 'admin') {
        cockpit_run_evidence_batch_json(403, array('ok' => false, 'error' => 'Admin access required.'));
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        cockpit_run_evidence_batch_json(405, array('ok' => false, 'error' => 'POST required.'));
    }

    $rawIds = $_POST['recording_ids'] ?? $_POST['ids'] ?? '';
    if (is_array($rawIds)) {
        $ids = array_values(array_filter(array_map('intval', $rawIds), static fn(int $id): bool => $id > 0));
    } else {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)$rawIds)), static fn(int $id): bool => $id > 0));
    }

    if ($ids === array()) {
        cockpit_run_evidence_batch_json(400, array('ok' => false, 'error' => 'Recording ids are required.'));
    }

    $queue = CockpitRecorderEvidenceQueueService::fromPdo($pdo);
    $result = $queue->retryProcessingBatch($ids);
    cockpit_run_evidence_batch_json(!empty($result['ok']) || (int)($result['started'] ?? 0) > 0 ? 200 : 500, $result);
} catch (Throwable $e) {
    cockpit_run_evidence_batch_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
