<?php
declare(strict_types=1);

/**
 * Export replay debug bundle (replay.json + raw GPS/AHRS/G3X) to a zip file.
 *
 * Usage:
 *   php scripts/export_cockpit_recorder_debug_bundle.php --id=11
 *   php scripts/export_cockpit_recorder_debug_bundle.php --id=95A0C8C2-C460-4E4B-9B07-4772849893DD --out=storage/debug_bundles
 */
$root = dirname(__DIR__);

$loadDotenv = static function (string $path): void {
    if (!is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            continue;
        }
        $key = $m[1];
        $val = $m[2];
        if ($val !== '' && (($val[0] === '"' && str_ends_with($val, '"')) || ($val[0] === "'" && str_ends_with($val, "'")))) {
            $val = substr($val, 1, -1);
        }
        if (getenv($key) !== false) {
            continue;
        }
        putenv($key . '=' . $val);
        $_ENV[$key] = $val;
    }
};

if (!getenv('CW_DB_HOST')) {
    $loadDotenv($root . '/.env');
}

require_once $root . '/src/db.php';
require_once $root . '/src/CockpitRecorderService.php';
require_once $root . '/src/CockpitReconstructionService.php';

$id = '';
$outDir = $root . '/storage/debug_bundles';
foreach ($argv ?? array() as $arg) {
    if (str_starts_with($arg, '--id=')) {
        $id = trim(substr($arg, strlen('--id=')));
    } elseif (str_starts_with($arg, '--out=')) {
        $outDir = trim(substr($arg, strlen('--out=')));
        if (!str_starts_with($outDir, '/')) {
            $outDir = $root . '/' . ltrim($outDir, '/');
        }
    }
}

if ($id === '') {
    fwrite(STDERR, "Usage: php scripts/export_cockpit_recorder_debug_bundle.php --id=RECORDING_ID [--out=storage/debug_bundles]\n");
    exit(1);
}

try {
    $pdo = cw_db();
    $service = new CockpitReconstructionService($pdo);
    $recorder = new CockpitRecorderService($pdo);
    $recording = $recorder->recordingByAnyId($id);
    if (!$recording) {
        throw new RuntimeException('Recording not found: ' . $id);
    }

    $uid = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string)($recording['recording_uid'] ?? ('recording_' . $id)));
    if ($uid === '') {
        $uid = 'recording_' . (string)($recording['id'] ?? $id);
    }

    $zipPath = rtrim($outDir, '/') . '/' . $uid . '.debug_bundle.zip';
    $result = $service->writeDebugBundleZip($id, $zipPath);

    echo 'Wrote ' . $result['zip_path'] . PHP_EOL;
    foreach ($result['entries'] as $entry) {
        $status = !empty($entry['missing']) ? 'missing' : 'included';
        echo '  - ' . $entry['name'] . ': ' . $status . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
