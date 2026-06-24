<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitAdsbEnrichmentService.php';

cw_require_admin();

$id = trim((string)($_POST['id'] ?? $_GET['id'] ?? ''));
$wantsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

try {
    if ($id === '') {
        throw new RuntimeException('Recording id is required.');
    }

    $service = new CockpitAdsbEnrichmentService($pdo);
    $result = $service->enrich($id);

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: /admin/cockpit_recorder.php?adsb=' . urlencode($id));
    exit;
} catch (Throwable $e) {
    if ($wantsJson) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
    exit;
}
