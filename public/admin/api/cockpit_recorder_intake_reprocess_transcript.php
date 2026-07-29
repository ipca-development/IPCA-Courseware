<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';

header('Content-Type: application/json; charset=utf-8');

function cockpit_intake_reprocess_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $currentUser = cw_current_user($pdo);
    if (!is_array($currentUser) || (string)($currentUser['role'] ?? '') !== 'admin') {
        cockpit_intake_reprocess_json(403, array('ok' => false, 'error' => 'Admin access required.'));
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        cockpit_intake_reprocess_json(405, array('ok' => false, 'error' => 'POST required.'));
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        cockpit_intake_reprocess_json(400, array('ok' => false, 'error' => 'Recording id is required.'));
    }

    $mode = strtolower(trim((string)($_POST['mode'] ?? 'retry')));
    $service = new CockpitRecorderService($pdo);
    if ($mode === 'cleanup') {
        $result = $service->cleanupStoredTranscript($id);
    } else {
        $result = $service->requeueTranscription($id);
    }
    if (empty($result['ok'])) {
        cockpit_intake_reprocess_json(400, array(
            'ok' => false,
            'error' => (string)($result['error'] ?? 'Could not queue transcript re-processing.'),
        ));
    }

    cockpit_intake_reprocess_json(200, $result);
} catch (Throwable $e) {
    cockpit_intake_reprocess_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
