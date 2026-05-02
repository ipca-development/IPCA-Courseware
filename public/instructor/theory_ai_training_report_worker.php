<?php
declare(strict_types=1);

if (PHP_SAPI_NAME() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/theory_ai_training_report_job.php';

$jobId = (int)($argv[1] ?? 0);
if ($jobId <= 0) {
    fwrite(STDERR, "Usage: php theory_ai_training_report_worker.php <job_id>\n");
    exit(1);
}

try {
    tatr_process_job($pdo, $jobId);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$row = tatr_get_job($pdo, $jobId);
if (!$row || (string)$row['status'] !== 'complete') {
    fwrite(STDERR, "Job {$jobId} did not complete successfully.\n");
    exit(1);
}

exit(0);
