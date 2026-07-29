<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/AuditEventService.php';
require_once __DIR__ . '/../src/FlightDebriefService.php';

$bundleId = 0;
$jobId = 0;
$actorUserId = null;
foreach ($argv ?? array() as $argument) {
    if (str_starts_with($argument, '--bundle-id=')) {
        $bundleId = (int)substr($argument, strlen('--bundle-id='));
    } elseif (str_starts_with($argument, '--job-id=')) {
        $jobId = (int)substr($argument, strlen('--job-id='));
    } elseif (str_starts_with($argument, '--actor-user-id=')) {
        $actorUserId = (int)substr($argument, strlen('--actor-user-id='));
    }
}
if ($bundleId <= 0 || $jobId <= 0) {
    fwrite(STDERR, "Usage: php scripts/run_structured_flight_debrief.php --bundle-id=N --job-id=N\n");
    exit(1);
}

try {
    $jobStatement = $pdo->prepare(
        "SELECT id, status, entity_id FROM ipca_async_jobs
         WHERE id = ? AND job_type = 'generate_structured_debrief' LIMIT 1"
    );
    $jobStatement->execute(array($jobId));
    $job = $jobStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($job)
        || (int)$job['entity_id'] !== $bundleId
        || !in_array((string)$job['status'], array('running', 'claimed'), true)) {
        throw new RuntimeException('Debrief generation job is unavailable or no longer active.');
    }
    $pdo->prepare(
        "UPDATE ipca_async_jobs
         SET status = 'running', claimed_by = 'structured_debrief_worker',
             heartbeat_at = CURRENT_TIMESTAMP(3), updated_at = CURRENT_TIMESTAMP(3)
         WHERE id = ?"
    )->execute(array($jobId));

    $debrief = (new FlightDebriefService($pdo))->generateStructuredDebrief($bundleId, $actorUserId);
    $debriefId = (int)($debrief['id'] ?? 0);
    if ($debriefId <= 0) {
        throw new RuntimeException('Debrief generation did not create a draft.');
    }
    $pdo->prepare(
        "UPDATE ipca_async_jobs
         SET status = 'succeeded', result_json = ?, last_error = NULL,
             heartbeat_at = CURRENT_TIMESTAMP(3), updated_at = CURRENT_TIMESTAMP(3)
         WHERE id = ?"
    )->execute(array(
        AuditEventService::jsonEncode(array(
            'bundle_id' => $bundleId,
            'debrief_id' => $debriefId,
            'status' => (string)($debrief['status'] ?? 'ai_draft'),
        )),
        $jobId,
    ));
    echo 'Structured debrief generated: ' . $debriefId . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    try {
        $pdo->prepare(
            "UPDATE ipca_async_jobs
             SET status = 'failed', last_error = ?,
                 heartbeat_at = CURRENT_TIMESTAMP(3), updated_at = CURRENT_TIMESTAMP(3)
             WHERE id = ?"
        )->execute(array($e->getMessage(), $jobId));
    } catch (Throwable) {
        // Preserve the original generation failure.
    }
    fwrite(STDERR, 'Structured debrief generation failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
