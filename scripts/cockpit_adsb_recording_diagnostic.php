<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/CockpitAdsbEnrichmentService.php';

$id = trim((string)($argv[1] ?? ''));
if ($id === '') {
    fwrite(STDERR, "Usage: php scripts/cockpit_adsb_recording_diagnostic.php <recording-id-or-uid>\n");
    exit(2);
}

try {
    echo json_encode((new CockpitAdsbEnrichmentService($pdo))->diagnosticForRecording($id), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
