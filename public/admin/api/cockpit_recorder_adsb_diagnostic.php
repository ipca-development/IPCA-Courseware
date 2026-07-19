<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitAdsbEnrichmentService.php';

cw_require_admin();

header('Content-Type: application/json; charset=utf-8');

try {
    $id = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
    if ($id === '') {
        throw new RuntimeException('Recording id is required.');
    }
    echo json_encode((new CockpitAdsbEnrichmentService($pdo))->diagnosticForRecording($id), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
