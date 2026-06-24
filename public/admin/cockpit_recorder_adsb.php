<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/CockpitAdsbEnrichmentService.php';

cw_require_admin();

$id = trim((string)($_GET['id'] ?? ''));
$type = trim((string)($_GET['type'] ?? 'normalized'));
$type = $type === 'raw' ? 'raw' : 'normalized';

if ($id === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Recording id is required.';
    exit;
}

try {
    $file = (new CockpitAdsbEnrichmentService($pdo))->fileForRecording($id, $type);
    if (!$file) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'ADS-B file not found.';
        exit;
    }

    header('Content-Type: ' . $file['mime'] . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]+/', '_', $file['filename']) . '"');
    header('Cache-Control: no-store');
    readfile($file['path']);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
    exit;
}
