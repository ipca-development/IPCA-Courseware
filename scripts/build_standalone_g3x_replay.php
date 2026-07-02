<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/StandaloneG3XReplayBuilder.php';

$csvPath = '';
$outputPath = '';

foreach ($argv ?? array() as $arg) {
    if (str_starts_with($arg, '--csv=')) {
        $csvPath = trim(substr($arg, strlen('--csv=')));
    } elseif (str_starts_with($arg, '--output=')) {
        $outputPath = trim(substr($arg, strlen('--output=')));
    }
}

if ($csvPath === '') {
    fwrite(STDERR, "Usage: php scripts/build_standalone_g3x_replay.php --csv=/path/to/g3x.csv [--output=/path/to/replay.json]\n");
    exit(1);
}

try {
    $payload = (new StandaloneG3XReplayBuilder())->build($csvPath);

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Could not encode standalone replay payload.');
    }

    if ($outputPath !== '') {
        $dir = dirname($outputPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create output directory.');
        }
        file_put_contents($outputPath, $json . PHP_EOL);
        echo json_encode(array(
            'ok' => true,
            'output' => $outputPath,
            'samples' => (int)($payload['replay_sample_count'] ?? 0),
            'duration_s' => (float)($payload['recording']['duration'] ?? 0),
            'max_raw_g3x_gap_s' => $payload['max_raw_g3x_gap_s'],
            'warnings' => $payload['warnings'] ?? array(),
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo $json . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Standalone G3X replay build failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

