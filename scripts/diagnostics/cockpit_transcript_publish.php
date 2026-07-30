<?php
declare(strict_types=1);

/**
 * Publish readable evidence transcript for a recording.
 *
 *   php scripts/diagnostics/cockpit_transcript_publish.php --recording-id=552
 *   php scripts/diagnostics/cockpit_transcript_publish.php --recording-id=552 --processing-run-id=14
 *   php scripts/diagnostics/cockpit_transcript_publish.php --recording-id=552 --list
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/AviationEvidence/PublishedTranscriptService.php';

function publish_arg(string $name, ?string $default = null): ?string
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv ?? array() as $arg) {
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
        if ($arg === '--' . $name) {
            return '1';
        }
    }
    return $default;
}

$recordingId = (int)(publish_arg('recording-id', '0') ?? '0');
$processingRunId = (int)(publish_arg('processing-run-id', '0') ?? '0');
$listOnly = publish_arg('list', null) !== null;

if ($recordingId <= 0) {
    fwrite(STDERR, "Usage: --recording-id=N [--processing-run-id=N] [--list]\n");
    exit(1);
}

try {
    $service = PublishedTranscriptService::fromPdo($pdo);
    if ($listOnly) {
        $versions = $service->listPublishedVersions($recordingId);
        echo json_encode(array('ok' => true, 'recording_id' => $recordingId, 'versions' => $versions), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $result = $processingRunId > 0
        ? $service->publishProcessingRun($recordingId, $processingRunId)
        : $service->publishLatestForRecording($recordingId);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
