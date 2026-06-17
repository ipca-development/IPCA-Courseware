<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';

header('Content-Type: application/json; charset=utf-8');

function cockpit_transcript_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    cockpit_transcript_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
}

try {
    $id = trim((string)($_GET['id'] ?? ''));
    if ($id === '') {
        cockpit_transcript_json(400, array('ok' => false, 'error' => 'Recording id is required.'));
    }

    $service = new CockpitRecorderService($pdo);
    $payload = $service->transcript($id);
    cockpit_transcript_json(!empty($payload['ok']) ? 200 : 404, $payload);
} catch (Throwable $e) {
    cockpit_transcript_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
