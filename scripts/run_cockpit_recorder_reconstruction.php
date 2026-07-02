<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/CockpitReconstructionService.php';

$recordingId = '';
$options = array();

foreach ($argv ?? array() as $arg) {
    if (str_starts_with($arg, '--recording-id=')) {
        $recordingId = trim(substr($arg, strlen('--recording-id=')));
    } elseif (str_starts_with($arg, '--job-id=')) {
        $value = trim(substr($arg, strlen('--job-id=')));
        if (is_numeric($value)) {
            $options['job_id'] = (int)$value;
        }
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
    } elseif (str_starts_with($arg, '--replay-source-mode=')) {
        $value = trim(substr($arg, strlen('--replay-source-mode=')));
        if ($value === 'g3x_only') {
            $options['replay_source_mode'] = 'g3x_only';
        }
    } elseif (str_starts_with($arg, '--g3x-csv-path=')) {
        $value = trim(substr($arg, strlen('--g3x-csv-path=')));
        if ($value !== '') {
            $options['g3x_csv_path'] = $value;
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

    $canonicalCount = (int)($result['sample_count'] ?? 0);
    $replayCount = (int)($result['replay_sample_count'] ?? 0);
    $totalDuration = is_numeric($result['total_duration_s'] ?? null) ? (float)$result['total_duration_s'] : null;

    $replaySourceMode = (string)($result['replay_source_mode'] ?? 'multi_source');

    echo 'Cockpit recorder reconstruction ' . $recordingId . ' completed with '
        . number_format($canonicalCount) . ' canonical samples.'
        . ($replayCount > 0 ? ' Replay v2: ' . number_format($replayCount) . ' samples.' : '')
        . ($replaySourceMode !== 'multi_source' ? ' Replay source mode: ' . $replaySourceMode . '.' : '')
        . PHP_EOL;
    if ($totalDuration !== null) {
        echo 'Total duration: ' . $totalDuration . 's' . PHP_EOL;
    }
    if (!empty($result['profiling']) && is_array($result['profiling'])) {
        echo 'Reconstruction phase timing (seconds): ' . json_encode($result['profiling'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
    exit(0);
} catch (Throwable $e) {
    try {
        (new CockpitReconstructionService($pdo))->markReconstructionFailed($recordingId, $e->getMessage());
    } catch (Throwable) {
        // The original reconstruction failure is the useful error for the worker log.
    }
    fwrite(STDERR, 'Cockpit recorder reconstruction failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
