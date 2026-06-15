<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingMccfIntegrityJobService.php';

$runId = 0;
foreach ($argv ?? array() as $arg) {
    if (str_starts_with($arg, '--run-id=')) {
        $runId = (int)substr($arg, strlen('--run-id='));
    }
}

if ($runId <= 0) {
    fwrite(STDERR, "Usage: php scripts/run_mccf_integrity_job.php --run-id=N\n");
    exit(1);
}

$svc = new ControlledPublishingMccfIntegrityJobService($pdo);
$maxIterations = 5000;
$iteration = 0;

while ($iteration < $maxIterations) {
    $iteration++;
    $result = $svc->processBatch($runId);
    if (!($result['ok'] ?? false) && ($result['done'] ?? false)) {
        fwrite(STDERR, 'Integrity run failed: ' . (string)($result['error'] ?? 'unknown error') . PHP_EOL);
        exit(1);
    }
    if ($result['done'] ?? false) {
        $processed = (int)($result['processed_count'] ?? 0);
        $total = (int)($result['total_count'] ?? 0);
        echo "Integrity run {$runId} completed ({$processed}/{$total})." . PHP_EOL;
        exit(0);
    }
    usleep(100000);
}

fwrite(STDERR, "Integrity run {$runId} stopped after iteration limit." . PHP_EOL);
exit(1);
