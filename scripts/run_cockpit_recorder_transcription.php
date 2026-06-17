<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/CockpitRecorderService.php';

$recordingId = 0;
foreach ($argv ?? array() as $arg) {
    if (str_starts_with($arg, '--recording-id=')) {
        $recordingId = (int)substr($arg, strlen('--recording-id='));
    }
}

if ($recordingId <= 0) {
    fwrite(STDERR, "Usage: php scripts/run_cockpit_recorder_transcription.php --recording-id=N\n");
    exit(1);
}

$service = new CockpitRecorderService($pdo);

try {
    $result = $service->processTranscription($recordingId);
    if (!($result['ok'] ?? false)) {
        $error = (string)($result['error'] ?? 'Unknown transcription error.');
        $service->markTranscriptionFailed($recordingId, $error);
        fwrite(STDERR, "Cockpit recorder transcription failed: {$error}\n");
        exit(1);
    }

    echo "Cockpit recorder transcription {$recordingId} completed." . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    $service->markTranscriptionFailed($recordingId, $e->getMessage());
    fwrite(STDERR, 'Cockpit recorder transcription failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
