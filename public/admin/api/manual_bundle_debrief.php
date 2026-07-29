<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/AuditEventService.php';

cw_require_admin();

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new RuntimeException('POST is required.');
    }
    $bundleId = (int)($_POST['bundle_id'] ?? 0);
    if (!hash_equals(
        (string)($_SESSION['cvr_reconstruction_csrf'] ?? ''),
        (string)($_POST['csrf_token'] ?? '')
    )) {
        throw new RuntimeException('Debrief generation request expired.');
    }
    $bundleStatement = $pdo->prepare(
        'SELECT id, transcript_snapshot_id FROM ipca_manual_intake_bundles WHERE id = ? LIMIT 1'
    );
    $bundleStatement->execute(array($bundleId));
    $bundle = $bundleStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($bundle) || (int)($bundle['transcript_snapshot_id'] ?? 0) <= 0) {
        throw new RuntimeException('A version-locked transcript is required before generating the debrief.');
    }

    $active = $pdo->prepare(
        "SELECT id FROM ipca_async_jobs
         WHERE job_type = 'generate_structured_debrief'
           AND entity_type = 'ipca_manual_intake_bundles'
           AND entity_id = ?
           AND status IN ('pending','claimed','running','retry_wait')
         ORDER BY id DESC LIMIT 1"
    );
    $active->execute(array((string)$bundleId));
    if ((int)$active->fetchColumn() > 0) {
        header('Location: /admin/master_logbook.php?tab=reconstruction&debrief_generation=running');
        exit;
    }

    $sequenceStatement = $pdo->prepare(
        'SELECT COUNT(*) + 1 FROM ipca_structured_debriefs WHERE bundle_id = ?'
    );
    $sequenceStatement->execute(array($bundleId));
    $sequence = max(1, (int)$sequenceStatement->fetchColumn());
    $jobUuid = AuditEventService::uuid();
    $idempotencyKey = hash(
        'sha256',
        'structured-debrief:' . $bundleId . ':' . (int)$bundle['transcript_snapshot_id'] . ':' . $sequence . ':' . $jobUuid
    );
    $actorUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $insert = $pdo->prepare(
        "INSERT INTO ipca_async_jobs
         (job_uuid, organization_id, queue_name, job_type, entity_type, entity_id,
          idempotency_key, priority, status, claimed_by, claimed_at, heartbeat_at,
          attempt_count, max_attempts, payload_json)
         VALUES (?, 1, 'cvr_debrief', 'generate_structured_debrief',
                 'ipca_manual_intake_bundles', ?, ?, 100, 'running',
                 'web_spawn', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), 1, 3, ?)"
    );
    $insert->execute(array(
        $jobUuid,
        (string)$bundleId,
        $idempotencyKey,
        AuditEventService::jsonEncode(array(
            'bundle_id' => $bundleId,
            'transcript_snapshot_id' => (int)$bundle['transcript_snapshot_id'],
            'actor_user_id' => $actorUserId,
        )),
    ));
    $jobId = (int)$pdo->lastInsertId();

    $php = PHP_BINDIR . '/php';
    $script = realpath(__DIR__ . '/../../../scripts/run_structured_flight_debrief.php');
    if (!is_file($php) || !is_executable($php) || $script === false || !function_exists('exec')) {
        throw new RuntimeException('Debrief generation worker is unavailable.');
    }
    $logDir = dirname(__DIR__, 3) . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    if (!is_dir($logDir) || !is_writable($logDir)) {
        throw new RuntimeException('Debrief worker log directory is unavailable.');
    }
    $logFile = $logDir . '/structured_debrief_' . $bundleId . '_' . $jobId . '.log';
    $command = 'nohup '
        . escapeshellarg($php) . ' '
        . escapeshellarg($script) . ' '
        . escapeshellarg('--bundle-id=' . $bundleId) . ' '
        . escapeshellarg('--job-id=' . $jobId)
        . ($actorUserId !== null ? ' ' . escapeshellarg('--actor-user-id=' . $actorUserId) : '')
        . ' >> ' . escapeshellarg($logFile) . ' 2>&1 < /dev/null & echo $!';
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('Could not start the Debrief generation worker.');
    }

    header('Location: /admin/master_logbook.php?tab=reconstruction&debrief_generation=started');
    exit;
} catch (Throwable $e) {
    if (isset($jobId) && $jobId > 0) {
        $pdo->prepare(
            "UPDATE ipca_async_jobs SET status = 'failed', last_error = ?, updated_at = CURRENT_TIMESTAMP(3)
             WHERE id = ?"
        )->execute(array($e->getMessage(), $jobId));
    }
    header('Location: /admin/master_logbook.php?tab=reconstruction&reconstruction_error=' . urlencode($e->getMessage()));
    exit;
}
