<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/CockpitRecorderService.php';
require_once __DIR__ . '/../src/CockpitReconstructionService.php';

/**
 * Replace the active audio file for an existing cockpit recording while keeping
 * the original file and a repair manifest for audit.
 */

function usage(): void
{
    fwrite(STDERR, <<<TXT
Usage:
  php scripts/repair_cockpit_recording_audio.php --recording-id=ID --audio=/path/to/corrected.m4a [options]

Options:
  --recording-id=ID       Numeric database id or recording UID.
  --audio=PATH            Corrected audio file to activate.
  --duration=SECONDS      Override duration. If omitted, ffprobe is used when available.
  --note=TEXT             Repair note stored in the sidecar manifest.
  --dry-run               Validate and print the planned change without copying/updating.
  --reconstruct           Run cockpit replay reconstruction after replacing audio.
  --queue-transcription   Clear transcript text and queue transcription for the corrected audio.
  --help                  Show this help.

TXT);
}

function option_value(array $options, string $key): string
{
    $value = $options[$key] ?? '';
    if (is_array($value)) {
        $value = end($value);
    }
    return trim((string)$value);
}

function bool_option(array $options, string $key): bool
{
    return array_key_exists($key, $options);
}

function resolve_cli_path(string $path, string $projectRoot): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if ($path[0] === '/') {
        return $path;
    }

    $cwdPath = getcwd() . '/' . $path;
    if (is_file($cwdPath)) {
        return $cwdPath;
    }
    return $projectRoot . '/' . ltrim($path, '/');
}

function safe_audio_extension(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, array('m4a', 'mp4', 'aac'), true) ? $ext : 'm4a';
}

function command_path(string $binary): string
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

function audio_duration_seconds(string $path): ?float
{
    $ffprobe = command_path('ffprobe');
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

function table_has_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute(array($table, $column));
    return (int)$stmt->fetchColumn() > 0;
}

function relative_storage_path(string $absolutePath, string $projectRoot): string
{
    $absolutePath = str_replace('\\', '/', $absolutePath);
    $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
    if (str_starts_with($absolutePath, $projectRoot . '/')) {
        return substr($absolutePath, strlen($projectRoot) + 1);
    }
    throw new RuntimeException('Path is outside the project root: ' . $absolutePath);
}

$options = getopt('', array(
    'recording-id:',
    'audio:',
    'duration::',
    'note::',
    'dry-run',
    'reconstruct',
    'queue-transcription',
    'help',
));
if ($options === false || bool_option($options, 'help')) {
    usage();
    exit($options === false ? 1 : 0);
}

$recordingId = option_value($options, 'recording-id');
$audioInput = option_value($options, 'audio');
$note = option_value($options, 'note');
$dryRun = bool_option($options, 'dry-run');
$reconstruct = bool_option($options, 'reconstruct');
$queueTranscription = bool_option($options, 'queue-transcription');
$durationOverride = option_value($options, 'duration');

if ($recordingId === '' || $audioInput === '') {
    usage();
    exit(1);
}

try {
    $service = new CockpitRecorderService($pdo);
    $recording = $service->recordingByAnyId($recordingId, true);
    if (!$recording) {
        throw new RuntimeException('Recording not found: ' . $recordingId);
    }

    $projectRoot = CockpitRecorderService::projectRoot();
    $audioRoot = CockpitRecorderService::audioRoot();
    $correctedPath = resolve_cli_path($audioInput, $projectRoot);
    $correctedRealPath = realpath($correctedPath);
    if ($correctedRealPath === false || !is_file($correctedRealPath)) {
        throw new RuntimeException('Corrected audio file not found: ' . $audioInput);
    }
    $correctedSize = (int)filesize($correctedRealPath);
    if ($correctedSize <= 0) {
        throw new RuntimeException('Corrected audio file is empty.');
    }

    $duration = null;
    if ($durationOverride !== '') {
        if (!is_numeric($durationOverride) || (float)$durationOverride <= 0) {
            throw new RuntimeException('--duration must be a positive number of seconds.');
        }
        $duration = (float)$durationOverride;
    } else {
        $duration = audio_duration_seconds($correctedRealPath);
    }
    if ($duration === null) {
        $duration = max(0.0, (float)($recording['duration_seconds'] ?? 0));
        fwrite(STDERR, "Warning: ffprobe duration unavailable; keeping existing duration {$duration}s.\n");
    }

    $recordingUid = (string)($recording['recording_uid'] ?? '');
    if ($recordingUid === '') {
        throw new RuntimeException('Recording has no recording_uid.');
    }

    $currentRelativePath = trim((string)($recording['storage_path'] ?? ''));
    $currentAbsolutePath = $currentRelativePath !== '' ? $projectRoot . '/' . ltrim($currentRelativePath, '/') : '';
    $currentRealPath = $currentAbsolutePath !== '' ? realpath($currentAbsolutePath) : false;
    $audioRootReal = realpath($audioRoot);
    if ($audioRootReal === false) {
        throw new RuntimeException('Audio storage root is missing: ' . $audioRoot);
    }
    if ($currentRealPath !== false && !str_starts_with($currentRealPath, $audioRootReal)) {
        throw new RuntimeException('Current audio path is outside the audio root.');
    }

    $timestamp = gmdate('Ymd_His');
    $datePath = gmdate('Y/m/d');
    $ext = safe_audio_extension($correctedRealPath);
    $destinationDir = $audioRoot . '/' . $datePath;
    $destinationPath = $destinationDir . '/' . $recordingUid . '.audio-repair-' . $timestamp . '.' . $ext;
    $destinationRelativePath = relative_storage_path($destinationPath, $projectRoot);

    $backupPath = null;
    $backupRelativePath = null;
    if ($currentRealPath !== false && is_file($currentRealPath)) {
        $currentExt = safe_audio_extension($currentRealPath);
        $backupPath = dirname($currentRealPath) . '/' . $recordingUid . '.before-audio-repair-' . $timestamp . '.' . $currentExt;
        $backupRelativePath = relative_storage_path($backupPath, $projectRoot);
    }

    echo 'Recording: #' . (int)$recording['id'] . ' / ' . $recordingUid . PHP_EOL;
    echo 'Current audio: ' . ($currentRelativePath !== '' ? $currentRelativePath : '(none)') . PHP_EOL;
    echo 'Corrected source: ' . $correctedRealPath . PHP_EOL;
    echo 'New active audio: ' . $destinationRelativePath . PHP_EOL;
    if ($backupRelativePath !== null) {
        echo 'Backup copy: ' . $backupRelativePath . PHP_EOL;
    }
    echo 'Corrected size: ' . number_format($correctedSize) . ' bytes' . PHP_EOL;
    echo 'Corrected duration: ' . number_format($duration, 6, '.', '') . ' seconds' . PHP_EOL;
    echo 'Queue transcription: ' . ($queueTranscription ? 'yes' : 'no') . PHP_EOL;
    echo 'Reconstruct after repair: ' . ($reconstruct ? 'yes' : 'no') . PHP_EOL;

    if ($dryRun) {
        echo 'Dry run only; no files or database rows were changed.' . PHP_EOL;
        exit(0);
    }

    if (!is_dir($destinationDir) && !mkdir($destinationDir, 0775, true) && !is_dir($destinationDir)) {
        throw new RuntimeException('Could not create destination directory: ' . $destinationDir);
    }

    if ($backupPath !== null && !copy($currentRealPath, $backupPath)) {
        throw new RuntimeException('Could not create backup copy: ' . $backupPath);
    }
    if (!copy($correctedRealPath, $destinationPath)) {
        throw new RuntimeException('Could not copy corrected audio into storage.');
    }

    $manifest = array(
        'recording_id' => (int)$recording['id'],
        'recording_uid' => $recordingUid,
        'repaired_at_utc' => gmdate('c'),
        'note' => $note,
        'previous_storage_path' => $currentRelativePath,
        'backup_storage_path' => $backupRelativePath,
        'corrected_storage_path' => $destinationRelativePath,
        'corrected_source_path' => $correctedRealPath,
        'previous_duration_seconds' => (float)($recording['duration_seconds'] ?? 0),
        'corrected_duration_seconds' => $duration,
        'previous_file_size_bytes' => (int)($recording['file_size_bytes'] ?? 0),
        'corrected_file_size_bytes' => $correctedSize,
    );
    $manifestPath = $destinationPath . '.repair.json';
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

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
        $correctedSize,
        basename($destinationPath),
        'audio/mp4',
        $ext,
    );

    if (table_has_column($pdo, 'ipca_cockpit_recordings', 'reconstruction_status')) {
        $sets[] = "reconstruction_status = 'not_started'";
        $sets[] = "timeline_status = 'not_started'";
        if (table_has_column($pdo, 'ipca_cockpit_recordings', 'reconstructed_at')) {
            $sets[] = 'reconstructed_at = NULL';
        }
        if (table_has_column($pdo, 'ipca_cockpit_recordings', 'reconstruction_summary_json')) {
            $sets[] = 'reconstruction_summary_json = NULL';
        }
    }

    if ($queueTranscription) {
        $sets[] = "transcription_status = 'queued'";
        $sets[] = 'transcription_progress = 0';
        $sets[] = 'transcript_text = NULL';
    }

    $params[] = (int)$recording['id'];
    $stmt = $pdo->prepare('UPDATE ipca_cockpit_recordings SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($params);

    echo 'Audio repair applied.' . PHP_EOL;
    echo 'Repair manifest: ' . relative_storage_path($manifestPath, $projectRoot) . PHP_EOL;

    if ($reconstruct) {
        echo 'Starting reconstruction...' . PHP_EOL;
        $result = (new CockpitReconstructionService($pdo))->reconstruct((string)$recording['id'], array('replay_source_mode' => 'g3x_only'));
        if (!($result['ok'] ?? false)) {
            throw new RuntimeException('Reconstruction did not report ok=true.');
        }
        echo 'Reconstruction completed. Replay samples: ' . number_format((int)($result['replay_sample_count'] ?? 0)) . PHP_EOL;
    } else {
        echo 'Next: run php scripts/run_cockpit_recorder_reconstruction.php --recording-id=' . (int)$recording['id'] . PHP_EOL;
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Audio repair failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
