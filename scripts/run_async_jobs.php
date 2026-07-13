<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/AsyncJobService.php';
require_once __DIR__ . '/../src/FlightRecordDerivationService.php';
require_once __DIR__ . '/../src/GarminCsvSessionMatchService.php';

@set_time_limit(0);

$queue = 'cvr';
$maxJobs = 10;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--queue=')) {
        $queue = substr($arg, 8);
    } elseif (str_starts_with($arg, '--max-jobs=')) {
        $maxJobs = max(1, (int)substr($arg, 11));
    }
}

$workerId = gethostname() . ':' . getmypid();
$service = new AsyncJobService($pdo);
$processed = 0;

while ($processed < $maxJobs) {
    $job = $service->claim($queue, $workerId);
    if ($job === null) {
        echo "No available jobs.\n";
        break;
    }
    $processed++;
    $jobId = (int)$job['id'];
    $service->markRunning($jobId, $workerId);
    try {
        $payload = json_decode((string)($job['payload_json'] ?? '{}'), true);
        $payload = is_array($payload) ? $payload : array();
        $result = run_cvr_async_job($pdo, (string)$job['job_type'], $payload);
        $service->succeed($jobId, $result);
        echo "Succeeded job {$jobId} ({$job['job_type']}).\n";
    } catch (Throwable $e) {
        $delay = min(3600, 60 * max(1, (int)$job['attempt_count']));
        $service->fail($jobId, $e->getMessage(), $delay);
        echo "Failed job {$jobId}: {$e->getMessage()}\n";
    }
}

echo "Processed {$processed} job(s).\n";

/**
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function run_cvr_async_job(PDO $pdo, string $jobType, array $payload): array
{
    $csvFileId = (int)($payload['csv_file_id'] ?? 0);
    if ($csvFileId <= 0) {
        return array('ok' => true, 'message' => 'No CSV file id in payload.');
    }
    if ($jobType === 'GARMIN_CSV_SESSION_MATCH') {
        $stmt = $pdo->prepare('SELECT * FROM ipca_garmin_csv_files WHERE id = ? LIMIT 1');
        $stmt->execute(array($csvFileId));
        $csvFile = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($csvFile)) {
            throw new RuntimeException('CSV file not found for session-match job.');
        }
        return (new GarminCsvSessionMatchService($pdo))->match($csvFile);
    }
    if ($jobType === 'GARMIN_CSV_DEEP_ANALYSIS') {
        return array('ok' => true, 'message' => 'Deep analysis placeholder completed for Phase 1.', 'csv_file_id' => $csvFileId);
    }
    if ($jobType === 'FLIGHT_RECORD_DERIVATION') {
        return (new FlightRecordDerivationService($pdo))->deriveFromCsvFile($csvFileId);
    }
    return array('ok' => true, 'message' => 'Unknown job type ignored by Phase 1 worker.', 'job_type' => $jobType);
}
