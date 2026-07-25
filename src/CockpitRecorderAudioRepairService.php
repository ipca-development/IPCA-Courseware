<?php
declare(strict_types=1);

require_once __DIR__ . '/CockpitRecorderService.php';

final class CockpitRecorderAudioRepairService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function repairFromLocalFile(
        string $recordingId,
        string $sourcePath,
        string $originalName,
        ?float $durationOverride = null,
        string $note = '',
        bool $queueTranscription = false
    ): array {
        $recorder = new CockpitRecorderService($this->pdo);
        $recording = $recorder->recordingByAnyId($recordingId, true);
        if (!$recording) {
            throw new RuntimeException('Recording not found.');
        }

        $sourceRealPath = realpath($sourcePath);
        if ($sourceRealPath === false || !is_file($sourceRealPath)) {
            throw new RuntimeException('Corrected audio file not found.');
        }
        $sourceSize = (int)filesize($sourceRealPath);
        if ($sourceSize <= 0) {
            throw new RuntimeException('Corrected audio file is empty.');
        }

        $duration = $durationOverride !== null && $durationOverride > 0
            ? $durationOverride
            : self::audioDurationSeconds($sourceRealPath);
        if ($duration === null) {
            $duration = max(0.0, (float)($recording['duration_seconds'] ?? 0));
        }

        $projectRoot = CockpitRecorderService::projectRoot();
        $audioRoot = CockpitRecorderService::audioRoot();
        $audioRootReal = realpath($audioRoot);
        if ($audioRootReal === false) {
            throw new RuntimeException('Audio storage root is missing.');
        }

        $recordingUid = trim((string)($recording['recording_uid'] ?? ''));
        if ($recordingUid === '') {
            throw new RuntimeException('Recording has no UID.');
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
            throw new RuntimeException('Could not create audio repair storage directory.');
        }

        $ext = self::safeAudioExtension($originalName);
        $destinationPath = $destinationDir . '/' . $recordingUid . '.audio-repair-' . $timestamp . '.' . $ext;
        $destinationRelativePath = self::relativeStoragePath($destinationPath, $projectRoot);

        $backupRelativePath = null;
        if ($currentRealPath !== false) {
            $backupExt = self::safeAudioExtension($currentRealPath);
            $backupPath = dirname($currentRealPath) . '/' . $recordingUid . '.before-audio-repair-' . $timestamp . '.' . $backupExt;
            if (!copy($currentRealPath, $backupPath)) {
                throw new RuntimeException('Could not preserve the previous active audio file.');
            }
            $backupRelativePath = self::relativeStoragePath($backupPath, $projectRoot);
        }

        if (!copy($sourceRealPath, $destinationPath)) {
            throw new RuntimeException('Could not store corrected audio file.');
        }

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
        if ($this->hasColumn('reconstruction_status')) {
            $sets[] = "reconstruction_status = 'not_started'";
            $sets[] = "timeline_status = 'not_started'";
        }
        if ($this->hasColumn('reconstructed_at')) {
            $sets[] = 'reconstructed_at = NULL';
        }
        if ($this->hasColumn('reconstruction_summary_json')) {
            $sets[] = 'reconstruction_summary_json = NULL';
        }
        if ($queueTranscription) {
            $sets[] = "transcription_status = 'queued'";
            $sets[] = 'transcription_progress = 0';
            $sets[] = 'transcript_text = NULL';
        }

        $params[] = (int)$recording['id'];
        $stmt = $this->pdo->prepare('UPDATE ipca_cockpit_recordings SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);

        return array(
            'ok' => true,
            'recording_id' => (int)$recording['id'],
            'recording_uid' => $recordingUid,
            'storage_path' => $destinationRelativePath,
            'backup_storage_path' => $backupRelativePath,
            'duration_seconds' => $duration,
            'file_size_bytes' => (int)filesize($destinationPath),
            'manifest_path' => self::relativeStoragePath($destinationPath . '.repair.json', $projectRoot),
        );
    }

    private function hasColumn(string $column): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'ipca_cockpit_recordings'
              AND COLUMN_NAME = ?
        ");
        $stmt->execute(array($column));
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function safeAudioExtension(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, array('m4a', 'mp4', 'aac'), true) ? $ext : 'm4a';
    }

    private static function relativeStoragePath(string $absolutePath, string $projectRoot): string
    {
        $absolutePath = str_replace('\\', '/', $absolutePath);
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        if (!str_starts_with($absolutePath, $projectRoot . '/')) {
            throw new RuntimeException('Storage path is outside the project root.');
        }
        return substr($absolutePath, strlen($projectRoot) + 1);
    }

    private static function audioDurationSeconds(string $path): ?float
    {
        $ffprobe = self::commandPath('ffprobe');
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

    private static function commandPath(string $binary): string
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
}
