<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitReconstructionService.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';

cw_require_admin();

@set_time_limit(0);

$id = trim((string)(
    $_POST['id']
    ?? $_GET['id']
    ?? $_POST['recording_id']
    ?? $_GET['recording_id']
    ?? $_POST['recording_uid']
    ?? $_GET['recording_uid']
    ?? ''
));
$altimeterSetting = trim((string)($_POST['altimeter_setting_inhg'] ?? $_GET['altimeter_setting_inhg'] ?? ''));
$airportElevation = trim((string)($_POST['airport_elevation_ft'] ?? $_GET['airport_elevation_ft'] ?? ''));
$oatC = trim((string)($_POST['oat_c'] ?? $_GET['oat_c'] ?? ''));
$replaySourceMode = trim((string)($_POST['replay_source_mode'] ?? $_GET['replay_source_mode'] ?? 'g3x_only'));
$mode = trim((string)($_POST['mode'] ?? $_GET['mode'] ?? 'async'));
$wantsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

function cockpit_reconstruct_spawn_worker(string $id, array $options, int $jobId): bool
{
    if (!function_exists('exec')) {
        return false;
    }

    $php = cockpit_reconstruct_cli_php();
    if ($php === '') {
        return false;
    }
    $script = realpath(__DIR__ . '/../../../scripts/run_cockpit_recorder_reconstruction.php');
    if ($script === false) {
        return false;
    }

    $logDir = CockpitRecorderService::projectRoot() . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    if (!is_dir($logDir) || !is_writable($logDir)) {
        return false;
    }

    $safeId = preg_replace('/[^A-Za-z0-9._-]+/', '-', $id) ?: 'recording';
    $logFile = $logDir . '/cockpit_reconstruction_' . $safeId . '.log';
    @file_put_contents($logFile, '[' . gmdate('c') . '] Spawning cockpit reconstruction worker.' . PHP_EOL, FILE_APPEND);

    $parts = array(
        escapeshellarg($php),
        escapeshellarg($script),
        escapeshellarg('--recording-id=' . $id),
        escapeshellarg('--job-id=' . (string)$jobId),
    );
    if (isset($options['altimeter_setting_inhg'])) {
        $parts[] = escapeshellarg('--altimeter-setting-inhg=' . (string)$options['altimeter_setting_inhg']);
    }
    if (isset($options['airport_elevation_ft'])) {
        $parts[] = escapeshellarg('--airport-elevation-ft=' . (string)$options['airport_elevation_ft']);
    }
    if (isset($options['oat_c'])) {
        $parts[] = escapeshellarg('--oat-c=' . (string)$options['oat_c']);
    }
    if (($options['replay_source_mode'] ?? '') === 'g3x_first' || ($options['replay_source_mode'] ?? '') === 'g3x_only') {
        $parts[] = escapeshellarg('--replay-source-mode=' . (string)$options['replay_source_mode']);
    }

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $cmd = 'start /B "" ' . implode(' ', $parts) . ' >> ' . escapeshellarg($logFile) . ' 2>&1';
    } else {
        $cmd = 'nohup ' . implode(' ', $parts) . ' >> ' . escapeshellarg($logFile) . ' 2>&1 < /dev/null & echo $!';
    }

    @file_put_contents($logFile, '[' . gmdate('c') . '] Command: ' . $cmd . PHP_EOL, FILE_APPEND);
    exec($cmd, $output, $exitCode);
    if ($exitCode !== 0) {
        @file_put_contents($logFile, '[' . gmdate('c') . '] Worker spawn command exited with code ' . $exitCode . PHP_EOL, FILE_APPEND);
        return false;
    }
    return true;
}

function cockpit_reconstruct_cli_php(): string
{
    $candidates = array();
    $bindir = trim((string)PHP_BINDIR);
    if ($bindir !== '') {
        $candidates[] = $bindir . '/php';
    }
    $candidates[] = '/usr/bin/php';
    $candidates[] = '/usr/local/bin/php';
    $candidates[] = '/opt/homebrew/bin/php';

    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_executable($candidate) && !str_contains(basename($candidate), 'php-fpm')) {
            return $candidate;
        }
    }

    if (function_exists('shell_exec')) {
        $resolved = trim((string)@shell_exec('command -v php 2>/dev/null'));
        if ($resolved !== '' && is_executable($resolved)) {
            return $resolved;
        }
    }

    return '';
}

try {
    if ($id === '') {
        throw new RuntimeException('Recording id is required.');
    }

    $service = new CockpitReconstructionService($pdo);
    $options = array();
    if ($altimeterSetting !== '') {
        if (!is_numeric($altimeterSetting)) {
            throw new RuntimeException('Altimeter setting must be numeric.');
        }
        $options['altimeter_setting_inhg'] = (float)$altimeterSetting;
    }
    if ($airportElevation !== '') {
        if (!is_numeric($airportElevation)) {
            throw new RuntimeException('Airport elevation must be numeric.');
        }
        $options['airport_elevation_ft'] = (float)$airportElevation;
    }
    if ($oatC !== '') {
        if (!is_numeric($oatC)) {
            throw new RuntimeException('OAT must be numeric.');
        }
        $options['oat_c'] = (float)$oatC;
    }
    if ($replaySourceMode === '' || $replaySourceMode === 'g3x_first') {
        $replaySourceMode = 'g3x_only';
    }
    if ($replaySourceMode !== 'g3x_only') {
        throw new RuntimeException('G3X-only reconstruction is required. Multi-source reconstruction is disabled.');
    }
    $options['replay_source_mode'] = 'g3x_only';
    if ($mode !== 'sync' && !$wantsJson) {
        $recording = (new CockpitRecorderService($pdo))->recordingByAnyId($id);
        if (!$recording) {
            throw new RuntimeException('Recording not found.');
        }
        $pdo->prepare("
            UPDATE ipca_cockpit_recordings
            SET reconstruction_status = 'processing',
                timeline_status = 'processing',
                error_message = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute(array((int)$recording['id']));

        $jobId = $service->createReconstructionJob((int)$recording['id']);
        if (!cockpit_reconstruct_spawn_worker((string)$recording['id'], $options, $jobId)) {
            $service->reportJobProgress($jobId, CockpitReconstructionService::STAGE_FAILED, 0, 'Could not start reconstruction worker', 'failed', 'Could not start reconstruction worker. Check storage/logs permissions and PHP exec availability.');
            $service->markReconstructionFailed((string)$recording['id'], 'Could not start reconstruction worker. Check storage/logs permissions and PHP exec availability.');
            throw new RuntimeException('Could not start reconstruction worker. Check storage/logs permissions and PHP exec availability.');
        }

        header('Location: /admin/cockpit_recorder.php?reconstruction=started&id=' . urlencode((string)$recording['id']));
        exit;
    }

    $result = $service->reconstruct($id, $options);

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: /admin/cockpit_recorder.php?reconstructed=' . urlencode($id));
    exit;
} catch (Throwable $e) {
    if ($wantsJson) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: /admin/cockpit_recorder.php?error=' . urlencode($e->getMessage()));
    exit;
}
