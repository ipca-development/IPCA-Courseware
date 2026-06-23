<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/CockpitRecorderService.php';
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
    $recorder = new CockpitRecorderService($pdo);
    $recording = $recorder->recordingByAnyId($id);
    if (!$recording) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Recording not found.';
        exit;
    }

    $filename = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string)($recording['recording_uid'] ?? ('recording_' . $id)));
    if ($filename === '') {
        $filename = 'ipca_recording_' . $id;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.g3x.csv"');
    header('Cache-Control: no-store');

    (new CockpitReconstructionService($pdo))->streamG3XCsv($id);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
    exit;
}
