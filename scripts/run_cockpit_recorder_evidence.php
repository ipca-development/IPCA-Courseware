<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/CockpitRecorderService.php';
require_once __DIR__ . '/../src/AviationEvidence/ProductionTranscriptionEvidenceService.php';

@set_time_limit(0);
@ini_set('memory_limit', '1024M');

$recordingId = 0;
foreach ($argv ?? array() as $arg) {
    if (str_starts_with($arg, '--recording-id=')) {
        $recordingId = (int)substr($arg, strlen('--recording-id='));
    }
}

if ($recordingId <= 0) {
    fwrite(STDERR, "Usage: php scripts/run_cockpit_recorder_evidence.php --recording-id=N\n");
    exit(1);
}

$logDir = CockpitRecorderService::projectRoot() . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . '/cockpit_evidence_' . $recordingId . '.log';

function cockpit_evidence_log(string $logFile, string $message): void
{
    @file_put_contents($logFile, '[' . gmdate('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
}

try {
    cockpit_evidence_log($logFile, 'Evidence worker started for recording ' . $recordingId);

    $recorder = new CockpitRecorderService($pdo);
    $recording = $recorder->recordingByAnyId((string)$recordingId);
    if (!is_array($recording)) {
        throw new RuntimeException('Recording not found: ' . $recordingId);
    }

    $result = ProductionTranscriptionEvidenceService::fromPdo($pdo)->persistAfterTranscription($recordingId, $recording);
    cockpit_evidence_log($logFile, 'Result: ' . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    if (!empty($result['ok']) || (!empty($result['skipped']) && ($result['reason'] ?? '') === 'already_persisted')) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $reason = (string)($result['reason'] ?? 'unknown');
    if ($reason === 'in_progress') {
        cockpit_evidence_log($logFile, 'Evidence processing already in progress.');
        exit(0);
    }

    throw new RuntimeException('Evidence processing failed: ' . $reason . (
        isset($result['error']) ? (' — ' . (string)$result['error']) : ''
    ));
} catch (Throwable $e) {
    cockpit_evidence_log($logFile, 'ERROR: ' . $e->getMessage());
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
