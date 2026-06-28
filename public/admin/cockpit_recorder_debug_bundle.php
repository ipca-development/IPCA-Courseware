<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/CockpitReconstructionService.php';

cw_require_admin();

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Recording id is required.';
    exit;
}

try {
    (new CockpitReconstructionService($pdo))->streamDebugBundleZip($id);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
    exit;
}
