<?php
declare(strict_types=1);

/**
 * Persist typed evidence for a recording whose transcription already completed.
 *
 *   php scripts/diagnostics/cockpit_transcript_production_evidence.php --recording-id=553
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/CockpitRecorderService.php';
require_once __DIR__ . '/../../src/AviationEvidence/ProductionTranscriptionEvidenceService.php';

function prod_evidence_arg(string $name, ?string $default = null): ?string
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv ?? array() as $arg) {
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
    }
    return $default;
}

$recordingId = (int)(prod_evidence_arg('recording-id', '0') ?? '0');
if ($recordingId <= 0) {
    fwrite(STDERR, "Usage: --recording-id=N\n");
    exit(1);
}

$recorder = new CockpitRecorderService($pdo);
$recording = $recorder->recordingByAnyId((string)$recordingId);
if (!is_array($recording)) {
    fwrite(STDERR, "Recording not found: {$recordingId}\n");
    exit(1);
}

try {
    $result = ProductionTranscriptionEvidenceService::fromPdo($pdo)->persistAfterTranscription($recordingId, $recording);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
