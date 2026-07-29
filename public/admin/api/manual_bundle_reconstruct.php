<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitReconstructionService.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';
require_once __DIR__ . '/../../../src/AuditEventService.php';
require_once __DIR__ . '/../../../src/ManualReconstructionBundleService.php';

cw_require_admin();

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new RuntimeException('POST is required.');
    }
    $bundleId = (int)($_POST['bundle_id'] ?? 0);
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['cvr_reconstruction_csrf'] ?? ''), $csrf)) {
        throw new RuntimeException('Reconstruction request expired.');
    }
    $statement = $pdo->prepare(
        "SELECT b.*, r.recording_uid
         FROM ipca_manual_intake_bundles b
         INNER JOIN ipca_cockpit_recordings r ON r.id = b.cockpit_recording_id
         WHERE b.id = ? AND b.status IN ('reconstruction_ready','processing') LIMIT 1"
    );
    $statement->execute(array($bundleId));
    $bundle = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($bundle)) {
        throw new RuntimeException('Frozen Reconstruction bundle is unavailable.');
    }
    $source = (new ManualReconstructionBundleService($pdo))->reconstructionSource($bundleId);
    $recordingId = (int)$source['recording_id'];
    $g3xCsvPath = (string)$source['g3x_csv_path'];
    $service = new CockpitReconstructionService($pdo);
    $jobId = $service->createReconstructionJob($recordingId);
    $pdo->prepare(
        "UPDATE ipca_cockpit_recordings
         SET reconstruction_status = 'processing', timeline_status = 'processing',
             error_message = NULL, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    )->execute(array($recordingId));
    $pdo->prepare(
        "UPDATE ipca_manual_intake_bundles
         SET status = 'processing', replay_status = 'processing', reconstruction_job_id = ?,
             processing_error = NULL WHERE id = ?"
    )->execute(array($jobId, $bundleId));

    $php = PHP_BINDIR . '/php';
    $script = realpath(__DIR__ . '/../../../scripts/run_cockpit_recorder_reconstruction.php');
    if (!is_file($php) || !is_executable($php) || $script === false || !function_exists('exec')) {
        throw new RuntimeException('Reconstruction worker is unavailable.');
    }
    $logDir = CockpitRecorderService::projectRoot() . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $log = $logDir . '/manual_bundle_reconstruction_' . $bundleId . '.log';
    $command = 'nohup '
        . escapeshellarg($php) . ' '
        . escapeshellarg($script) . ' '
        . escapeshellarg('--recording-id=' . $recordingId) . ' '
        . escapeshellarg('--job-id=' . $jobId) . ' '
        . escapeshellarg('--bundle-id=' . $bundleId) . ' '
        . escapeshellarg('--g3x-csv-path=' . $g3xCsvPath) . ' '
        . escapeshellarg('--replay-source-mode=g3x_only')
        . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!';
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('Could not start the Reconstruction worker.');
    }
    $pdo->prepare(
        'INSERT INTO ipca_manual_intake_bundle_audit
         (event_uuid, bundle_id, event_type, actor_user_id, detail_json)
         VALUES (?, ?, \'reconstruction_started\', ?, ?)'
    )->execute(array(
        AuditEventService::uuid(),
        $bundleId,
        isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
        AuditEventService::jsonEncode(array('job_id' => $jobId, 'recording_id' => $recordingId)),
    ));
    header('Location: /admin/master_logbook.php?tab=reconstruction&reconstruction=started');
    exit;
} catch (Throwable $e) {
    if (isset($bundleId) && $bundleId > 0) {
        $pdo->prepare(
            "UPDATE ipca_manual_intake_bundles
             SET status = 'needs_review', replay_status = 'failed', processing_error = ? WHERE id = ?"
        )->execute(array($e->getMessage(), $bundleId));
    }
    header('Location: /admin/master_logbook.php?tab=reconstruction&reconstruction_error=' . urlencode($e->getMessage()));
    exit;
}
