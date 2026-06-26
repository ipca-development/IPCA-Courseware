<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/CockpitReconstructionService.php';

$recordingId = '';
$options = array();

foreach ($argv ?? array() as $arg) {
    if (str_starts_with($arg, '--recording-id=')) {
        $recordingId = trim(substr($arg, strlen('--recording-id=')));
    } elseif (str_starts_with($arg, '--altimeter-setting-inhg=')) {
        $value = trim(substr($arg, strlen('--altimeter-setting-inhg=')));
        if (is_numeric($value)) {
            $options['altimeter_setting_inhg'] = (float)$value;
        }
    } elseif (str_starts_with($arg, '--airport-elevation-ft=')) {
        $value = trim(substr($arg, strlen('--airport-elevation-ft=')));
        if (is_numeric($value)) {
            $options['airport_elevation_ft'] = (float)$value;
        }
    } elseif (str_starts_with($arg, '--oat-c=')) {
        $value = trim(substr($arg, strlen('--oat-c=')));
        if (is_numeric($value)) {
            $options['oat_c'] = (float)$value;
        }
    }
}

if ($recordingId === '') {
    fwrite(STDERR, "Usage: php scripts/run_cockpit_recorder_reconstruction.php --recording-id=N\n");
    exit(1);
}

try {
    $service = new CockpitReconstructionService($pdo);
    $result = $service->reconstruct($recordingId, $options);
    if (!($result['ok'] ?? false)) {
        fwrite(STDERR, "Cockpit recorder reconstruction failed.\n");
        exit(1);
    }

    echo 'Cockpit recorder reconstruction ' . $recordingId . ' completed with '
        . (int)($result['sample_count'] ?? 0) . ' samples.' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Cockpit recorder reconstruction failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
