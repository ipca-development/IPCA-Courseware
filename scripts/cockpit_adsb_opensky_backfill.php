<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/OpenSkyTrafficArchiveBackfillService.php';

$command = trim((string)($argv[1] ?? 'status'));

try {
    $pdo = cw_db();
    $service = new OpenSkyTrafficArchiveBackfillService($pdo);
    if ($command === 'status') {
        echo json_encode($service->status(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }
    if ($command === 'plan' || $command === 'run-recording') {
        $recording = trim((string)($argv[2] ?? ''));
        if ($recording === '') {
            fwrite(STDERR, "Usage: php scripts/cockpit_adsb_opensky_backfill.php {$command} <recording-id-or-uid> [radius-nm] [chunk-minutes]\n");
            exit(2);
        }
        $radiusNm = isset($argv[3]) && is_numeric($argv[3]) ? (float)$argv[3] : 25.0;
        $chunkMinutes = isset($argv[4]) && is_numeric($argv[4]) ? (int)$argv[4] : (int)(getenv('CW_OPENSKY_BACKFILL_CHUNK_MINUTES') ?: 60);
        $result = $service->backfillRecording($recording, $radiusNm, $chunkMinutes, $command === 'plan');
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php scripts/cockpit_adsb_opensky_backfill.php status\n");
    fwrite(STDERR, "  php scripts/cockpit_adsb_opensky_backfill.php plan <recording-id-or-uid> [radius-nm] [chunk-minutes]\n");
    fwrite(STDERR, "  php scripts/cockpit_adsb_opensky_backfill.php run-recording <recording-id-or-uid> [radius-nm] [chunk-minutes]\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
