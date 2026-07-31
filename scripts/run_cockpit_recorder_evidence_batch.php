<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/CockpitRecorderEvidenceQueueService.php';

@set_time_limit(0);
@ini_set('memory_limit', '1024M');

$recordingIds = array();
foreach ($argv ?? array() as $arg) {
    if (!str_starts_with($arg, '--recording-ids=')) {
        continue;
    }
    $recordingIds = array_values(array_unique(array_filter(
        array_map('intval', explode(',', substr($arg, strlen('--recording-ids=')))),
        static fn(int $id): bool => $id > 0
    )));
}

if ($recordingIds === array()) {
    fwrite(STDERR, "Usage: php scripts/run_cockpit_recorder_evidence_batch.php --recording-ids=1,2,3\n");
    exit(1);
}

$lockDir = CockpitRecorderService::projectRoot() . '/storage/locks';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}
$lockHandle = @fopen($lockDir . '/cockpit_evidence_batch.lock', 'c+');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another cockpit evidence batch is already running.\n");
    exit(2);
}

$queue = CockpitRecorderEvidenceQueueService::fromPdo($pdo);
$failed = 0;

try {
    foreach ($recordingIds as $recordingId) {
        echo '[' . gmdate('c') . '] Starting evidence for recording ' . $recordingId . PHP_EOL;
        $result = $queue->retryProcessing($recordingId, true, false);
        echo '[' . gmdate('c') . '] Result for recording ' . $recordingId . ': '
            . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . PHP_EOL;
        if (empty($result['ok'])) {
            $failed++;
        }
    }
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

exit($failed === 0 ? 0 : 1);
