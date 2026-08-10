<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/CvrLiveAudioSegmentService.php';

$segmentId = 0;
foreach (array_slice($argv ?? array(), 1) as $argument) {
    if (str_starts_with($argument, '--segment-id=')) {
        $segmentId = (int)substr($argument, strlen('--segment-id='));
    }
}
if ($segmentId <= 0) {
    fwrite(STDERR, "Usage: php scripts/run_cvr_live_audio_transcription.php --segment-id=123\n");
    exit(2);
}

try {
    $result = (new CvrLiveAudioSegmentService($pdo))->transcribe($segmentId);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
