<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';

header('Content-Type: application/json; charset=utf-8');

function cockpit_recordings_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    cockpit_recordings_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
}

try {
    $service = new CockpitRecorderService($pdo);
    $limit = (int)($_GET['limit'] ?? 100);
    cockpit_recordings_json(200, $service->listRecordings($limit));
} catch (Throwable $e) {
    cockpit_recordings_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
