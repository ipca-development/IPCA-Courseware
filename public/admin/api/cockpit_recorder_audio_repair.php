<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';

cw_require_admin();

@ini_set('memory_limit', '512M');
@set_time_limit(0);

function cockpit_audio_repair_fail(string $message): void
{
    header('Location: /admin/cockpit_recorder.php?error=' . urlencode($message));
    exit;
}

function cockpit_audio_repair_command_path(string $binary): string
{
    $candidates = array(
        trim((string)getenv(strtoupper($binary))),
        '/opt/homebrew/bin/' . $binary,
        '/usr/local/bin/' . $binary,
        '/usr/bin/' . $binary,
    );
    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }
    if (function_exists('shell_exec')) {
        $resolved = trim((string)@shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));
        if ($resolved !== '' && is_file($resolved) && is_executable($resolved)) {
            return $resolved;
        }
    }
    return '';
}

function cockpit_audio_repair_duration_seconds(string $path): ?float
{
    $ffprobe = cockpit_audio_repair_command_path('ffprobe');
    if ($ffprobe === '') {
        return null;
    }
    $cmd = escapeshellarg($ffprobe)
        . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
        . escapeshellarg($path);
    $output = function_exists('shell_exec') ? trim((string)@shell_exec($cmd)) : '';
    if ($output === '' || !is_numeric($output)) {
        return null;
    }
    $duration = (float)$output;
    return $duration > 0 ? $duration : null;
}

function cockpit_audio_repair_safe_extension(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, array('m4a', 'mp4', 'aac'), true) ? $ext : 'm4a';
}

function cockpit_audio_repair_relative_path(string $absolutePath, string $projectRoot): string
{
    $absolutePath = str_replace('\\', '/', $absolutePath);
    $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
    if (!str_starts_with($absolutePath, $projectRoot . '/')) {
        throw new RuntimeException('Storage path is outside the project root.');
    }
    return substr($absolutePath, strlen($projectRoot) + 1);
}

function cockpit_audio_repair_table_has_column(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'ipca_cockpit_recordings'
          AND COLUMN_NAME = ?
    ");
    $stmt->execute(array($column));
    return (int)$stmt->fetchColumn() > 0;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        cockpit_audio_repair_fail('Method not allowed.');
    }

    $id = trim((string)($_POST['id'] ?? $_POST['recording_id'] ?? ''));
    if ($id === '') {
        cockpit_audio_repair_fail('Recording id is required.');
    }

    $file = $_FILES['audio'] ?? null;
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        cockpit_audio_repair_fail('Corrected audio upload failed.');
    }
    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        cockpit_audio_repair_fail('Corrected audio upload is invalid.');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        cockpit_audio_repair_fail('Corrected audio file is empty.');
    }
    if ($size > 512 * 1024 * 1024) {
        cockpit_audio_repair_fail('Corrected audio file is too large for this repair upload.');
    }

    $service = new CockpitRecorderService($pdo);
    $recording = $service->recordingByAnyId($id, true);
    if (!$recording) {
        cockpit_audio_repair_fail('Recording not found.');
    }

    $projectRoot = CockpitRecorderService::projectRoot();
    $audioRoot = CockpitRecorderService::audioRoot();
    $audioRootReal = realpath($audioRoot);
    if ($audioRootReal === false) {
        cockpit_audio_repair_fail('Audio storage root is missing.');
    }

    $durationOverride = trim((string)($_POST['duration_seconds'] ?? ''));
    if ($durationOverride !== '') {
        if (!is_numeric($durationOverride) || (float)$durationOverride <= 0) {
            cockpit_audio_repair_fail('Duration override must be a positive number of seconds.');
        }
        $duration = (float)$durationOverride;
    } else {
        $duration = cockpit_audio_repair_duration_seconds($tmpPath);
        if ($duration === null) {
            $duration = max(0.0, (float)($recording['duration_seconds'] ?? 0));
        }
    }

    $recordingUid = trim((string)($recording['recording_uid'] ?? ''));
    if ($recordingUid === '') {
        cockpit_audio_repair_fail('Recording has no UID.');
    }

    $currentRelativePath = trim((string)($recording['storage_path'] ?? ''));
    $currentRealPath = false;
    if ($currentRelativePath !== '') {
        $candidate = realpath($projectRoot . '/' . ltrim($currentRelativePath, '/'));
        if ($candidate !== false && str_starts_with($candidate, $audioRootReal) && is_file($candidate)) {
            $currentRealPath = $candidate;
        }
    }

    $timestamp = gmdate('Ymd_His');
    $destinationDir = $audioRoot . '/' . gmdate('Y/m/d');
    if (!is_dir($destinationDir) && !mkdir($destinationDir, 0775, true) && !is_dir($destinationDir)) {
        cockpit_audio_repair_fail('Could not create audio repair storage directory.');
    }

    $originalName = trim((string)($file['name'] ?? 'corrected.m4a'));
    $ext = cockpit_audio_repair_safe_extension($originalName);
    $destinationPath = $destinationDir . '/' . $recordingUid . '.audio-repair-' . $timestamp . '.' . $ext;
    $destinationRelativePath = cockpit_audio_repair_relative_path($destinationPath, $projectRoot);

    $backupRelativePath = null;
    if ($currentRealPath !== false) {
        $backupExt = cockpit_audio_repair_safe_extension($currentRealPath);
        $backupPath = dirname($currentRealPath) . '/' . $recordingUid . '.before-audio-repair-' . $timestamp . '.' . $backupExt;
        if (!copy($currentRealPath, $backupPath)) {
            cockpit_audio_repair_fail('Could not preserve the previous active audio file.');
        }
        $backupRelativePath = cockpit_audio_repair_relative_path($backupPath, $projectRoot);
    }

    if (!move_uploaded_file($tmpPath, $destinationPath)) {
        cockpit_audio_repair_fail('Could not store corrected audio file.');
    }

    $note = trim((string)($_POST['note'] ?? ''));
    $manifest = array(
        'recording_id' => (int)$recording['id'],
        'recording_uid' => $recordingUid,
        'repaired_at_utc' => gmdate('c'),
        'note' => $note,
        'previous_storage_path' => $currentRelativePath,
        'backup_storage_path' => $backupRelativePath,
        'corrected_storage_path' => $destinationRelativePath,
        'previous_duration_seconds' => (float)($recording['duration_seconds'] ?? 0),
        'corrected_duration_seconds' => $duration,
        'previous_file_size_bytes' => (int)($recording['file_size_bytes'] ?? 0),
        'corrected_file_size_bytes' => (int)filesize($destinationPath),
    );
    @file_put_contents($destinationPath . '.repair.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

    $sets = array(
        'storage_path = ?',
        'duration_seconds = ?',
        'file_size_bytes = ?',
        'original_filename = ?',
        'mime_type = ?',
        'file_extension = ?',
        'error_message = NULL',
        'updated_at = CURRENT_TIMESTAMP',
    );
    $params = array(
        $destinationRelativePath,
        $duration,
        (int)filesize($destinationPath),
        basename($destinationPath),
        'audio/mp4',
        $ext,
    );
    if (cockpit_audio_repair_table_has_column($pdo, 'reconstruction_status')) {
        $sets[] = "reconstruction_status = 'not_started'";
        $sets[] = "timeline_status = 'not_started'";
    }
    if (cockpit_audio_repair_table_has_column($pdo, 'reconstructed_at')) {
        $sets[] = 'reconstructed_at = NULL';
    }
    if (cockpit_audio_repair_table_has_column($pdo, 'reconstruction_summary_json')) {
        $sets[] = 'reconstruction_summary_json = NULL';
    }
    if (!empty($_POST['queue_transcription'])) {
        $sets[] = "transcription_status = 'queued'";
        $sets[] = 'transcription_progress = 0';
        $sets[] = 'transcript_text = NULL';
    }

    $params[] = (int)$recording['id'];
    $stmt = $pdo->prepare('UPDATE ipca_cockpit_recordings SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($params);

    if (!empty($_POST['reconstruct'])) {
        header('Location: /admin/api/cockpit_recorder_reconstruct.php?id=' . urlencode((string)$recording['id']) . '&replay_source_mode=g3x_only');
        exit;
    }

    header('Location: /admin/cockpit_recorder.php?audio_repair=replaced&id=' . urlencode((string)$recording['id']));
    exit;
} catch (Throwable $e) {
    cockpit_audio_repair_fail($e->getMessage());
}
