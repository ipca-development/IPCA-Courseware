<?php
declare(strict_types=1);

/**
 * Cockpit Recorder POC storage, upload metadata, and stub transcription workflow.
 */
final class CockpitRecorderService
{
    private const TABLE = 'ipca_cockpit_recordings';

    public function __construct(private PDO $pdo)
    {
    }

    public static function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    public static function audioRoot(): string
    {
        return self::projectRoot() . '/storage/cockpit_recorder/audio';
    }

    public static function tablesPresent(PDO $pdo): bool
    {
        $stmt = $pdo->query("SHOW TABLES LIKE 'ipca_cockpit_recordings'");
        return $stmt !== false && $stmt->fetchColumn() !== false;
    }

    public function requireTables(): void
    {
        if (!self::tablesPresent($this->pdo)) {
            throw new RuntimeException('Apply scripts/sql/2026_06_17_cockpit_recorder_poc.sql first.');
        }
    }

    /**
     * @param array<string,mixed> $file
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public function storeUploadedRecording(array $file, array $metadata): array
    {
        $this->requireTables();

        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Audio upload failed: ' . self::uploadErrorText($err));
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid audio upload.');
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('Uploaded audio file is empty.');
        }

        $recordingUid = self::normalizeRecordingUid((string)($metadata['recording_id'] ?? ''));
        if ($recordingUid === '') {
            $recordingUid = bin2hex(random_bytes(16));
        }

        $originalName = trim((string)($file['name'] ?? 'recording.m4a'));
        $ext = self::safeExtension($originalName, (string)($file['type'] ?? ''));
        $relativePath = self::relativeAudioPath($recordingUid, $ext);
        $absolutePath = self::projectRoot() . '/' . $relativePath;
        self::ensureDirectory(dirname($absolutePath));

        if (!move_uploaded_file($tmp, $absolutePath)) {
            throw new RuntimeException('Could not store uploaded audio.');
        }

        $startedAt = self::normalizeDateTime((string)($metadata['started_at'] ?? ''));
        $duration = max(0.0, (float)($metadata['duration'] ?? 0));
        $inputDevice = trim((string)($metadata['input_device'] ?? ''));
        $language = self::normalizeLanguage((string)($metadata['language'] ?? 'en'));
        $mimeType = trim((string)($file['type'] ?? ''));

        $stmt = $this->pdo->prepare("
            INSERT INTO " . self::TABLE . " (
                recording_uid,
                started_at,
                duration_seconds,
                input_device,
                language,
                upload_status,
                transcription_status,
                transcription_progress,
                original_filename,
                mime_type,
                file_extension,
                file_size_bytes,
                storage_path,
                transcript_text,
                error_message,
                uploaded_at,
                created_at,
                updated_at
            ) VALUES (
                :recording_uid,
                :started_at,
                :duration_seconds,
                :input_device,
                :language,
                'uploaded',
                'queued',
                0,
                :original_filename,
                :mime_type,
                :file_extension,
                :file_size_bytes,
                :storage_path,
                NULL,
                NULL,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
            ON DUPLICATE KEY UPDATE
                started_at = VALUES(started_at),
                duration_seconds = VALUES(duration_seconds),
                input_device = VALUES(input_device),
                language = VALUES(language),
                upload_status = 'uploaded',
                transcription_status = 'queued',
                transcription_progress = 0,
                original_filename = VALUES(original_filename),
                mime_type = VALUES(mime_type),
                file_extension = VALUES(file_extension),
                file_size_bytes = VALUES(file_size_bytes),
                storage_path = VALUES(storage_path),
                transcript_text = NULL,
                error_message = NULL,
                uploaded_at = CURRENT_TIMESTAMP,
                transcription_started_at = NULL,
                transcription_completed_at = NULL,
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(array(
            ':recording_uid' => $recordingUid,
            ':started_at' => $startedAt,
            ':duration_seconds' => $duration,
            ':input_device' => $inputDevice,
            ':language' => $language,
            ':original_filename' => $originalName,
            ':mime_type' => $mimeType,
            ':file_extension' => $ext,
            ':file_size_bytes' => $size,
            ':storage_path' => $relativePath,
        ));

        $recording = $this->recordingByUid($recordingUid);
        if (!$recording) {
            throw new RuntimeException('Stored recording could not be loaded.');
        }

        $workerSpawned = $this->spawnTranscriptionWorker((int)$recording['id']);

        return array(
            'ok' => true,
            'recording' => $this->publicRecordingPayload($recording),
            'worker_spawned' => $workerSpawned,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function listRecordings(int $limit = 100): array
    {
        $this->requireTables();
        $limit = max(1, min(250, $limit));
        $stmt = $this->pdo->query("
            SELECT *
            FROM " . self::TABLE . "
            ORDER BY COALESCE(started_at, created_at) DESC, id DESC
            LIMIT " . $limit
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();

        return array(
            'ok' => true,
            'recordings' => array_map(fn(array $row): array => $this->publicRecordingPayload($row), $rows),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function adminRecordings(int $limit = 100): array
    {
        $this->requireTables();
        $limit = max(1, min(250, $limit));
        $stmt = $this->pdo->query("
            SELECT *
            FROM " . self::TABLE . "
            ORDER BY COALESCE(started_at, created_at) DESC, id DESC
            LIMIT " . $limit
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array<string,mixed>
     */
    public function status(string $id): array
    {
        $recording = $this->recordingByAnyId($id);
        if (!$recording) {
            return array('ok' => false, 'error' => 'Recording not found.');
        }

        return array(
            'ok' => true,
            'recording' => $this->publicRecordingPayload($recording),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function transcript(string $id): array
    {
        $recording = $this->recordingByAnyId($id);
        if (!$recording) {
            return array('ok' => false, 'error' => 'Recording not found.');
        }

        return array(
            'ok' => true,
            'recording_id' => (string)$recording['recording_uid'],
            'transcription_status' => (string)$recording['transcription_status'],
            'language' => (string)$recording['language'],
            'transcript' => (string)($recording['transcript_text'] ?? ''),
            'error' => (string)($recording['error_message'] ?? ''),
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function recordingByAnyId(string $id): ?array
    {
        $this->requireTables();
        $id = trim($id);
        if ($id === '') {
            return null;
        }

        if (ctype_digit($id)) {
            $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
            $stmt->execute(array((int)$id));
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE recording_uid = ? LIMIT 1');
            $stmt->execute(array($id));
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function recordingByUid(string $uid): ?array
    {
        $this->requireTables();
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE recording_uid = ? LIMIT 1');
        $stmt->execute(array($uid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function processStubTranscription(int $recordingId): array
    {
        $this->requireTables();
        if ($recordingId <= 0) {
            return array('ok' => false, 'done' => true, 'error' => 'Invalid recording id.');
        }

        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        $stmt->execute(array($recordingId));
        $recording = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($recording)) {
            return array('ok' => false, 'done' => true, 'error' => 'Recording not found.');
        }

        if ((string)$recording['transcription_status'] === 'ready') {
            return array('ok' => true, 'done' => true, 'recording' => $this->publicRecordingPayload($recording));
        }

        $this->pdo->prepare("
            UPDATE " . self::TABLE . "
            SET transcription_status = 'transcribing',
                transcription_progress = 25,
                transcription_started_at = COALESCE(transcription_started_at, CURRENT_TIMESTAMP),
                error_message = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute(array($recordingId));

        usleep(250000);

        $transcript = $this->buildStubTranscript($recording);
        $this->pdo->prepare("
            UPDATE " . self::TABLE . "
            SET transcription_status = 'ready',
                transcription_progress = 100,
                transcript_text = ?,
                transcription_completed_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute(array($transcript, $recordingId));

        $updated = $this->recordingByAnyId((string)$recordingId);

        return array(
            'ok' => true,
            'done' => true,
            'recording' => $updated ? $this->publicRecordingPayload($updated) : null,
        );
    }

    public function markTranscriptionFailed(int $recordingId, string $error): void
    {
        if ($recordingId <= 0) {
            return;
        }
        $this->pdo->prepare("
            UPDATE " . self::TABLE . "
            SET transcription_status = 'failed',
                transcription_progress = 0,
                error_message = ?,
                transcription_completed_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute(array(substr($error, 0, 2000), $recordingId));
    }

    public function spawnTranscriptionWorker(int $recordingId): bool
    {
        if ($recordingId <= 0) {
            return false;
        }

        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $script = realpath(__DIR__ . '/../scripts/run_cockpit_recorder_transcription.php');
        if ($script === false) {
            return false;
        }

        $logDir = self::projectRoot() . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . '/cockpit_recorder_' . $recordingId . '.log';

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $cmd = 'start /B "" '
                . escapeshellarg($php) . ' '
                . escapeshellarg($script) . ' '
                . '--recording-id=' . $recordingId
                . ' >> ' . escapeshellarg($logFile) . ' 2>&1';
        } else {
            $cmd = escapeshellarg($php) . ' '
                . escapeshellarg($script) . ' '
                . '--recording-id=' . $recordingId
                . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';
        }

        exec($cmd);
        return true;
    }

    /**
     * @param array<string,mixed> $recording
     * @return array<string,mixed>
     */
    public function publicRecordingPayload(array $recording): array
    {
        return array(
            'id' => (int)($recording['id'] ?? 0),
            'recording_id' => (string)($recording['recording_uid'] ?? ''),
            'started_at' => $recording['started_at'] ?? null,
            'duration' => (float)($recording['duration_seconds'] ?? 0),
            'input_device' => (string)($recording['input_device'] ?? ''),
            'language' => (string)($recording['language'] ?? 'en'),
            'upload_status' => (string)($recording['upload_status'] ?? 'pending'),
            'transcription_status' => (string)($recording['transcription_status'] ?? 'pending'),
            'progress' => (int)($recording['transcription_progress'] ?? 0),
            'file_size' => (int)($recording['file_size_bytes'] ?? 0),
            'original_filename' => (string)($recording['original_filename'] ?? ''),
            'uploaded_at' => $recording['uploaded_at'] ?? null,
            'created_at' => $recording['created_at'] ?? null,
            'updated_at' => $recording['updated_at'] ?? null,
            'error' => (string)($recording['error_message'] ?? ''),
        );
    }

    /**
     * @param array<string,mixed> $recording
     */
    private function buildStubTranscript(array $recording): string
    {
        $uid = (string)($recording['recording_uid'] ?? '');
        $language = (string)($recording['language'] ?? 'en');
        $inputDevice = (string)($recording['input_device'] ?? 'Unknown input');
        $duration = number_format((float)($recording['duration_seconds'] ?? 0), 1);
        $startedAt = (string)($recording['started_at'] ?? '');

        return "Stub transcript for Cockpit Recorder POC\n\n"
            . "Recording ID: {$uid}\n"
            . "Language: {$language}\n"
            . "Started at: {$startedAt}\n"
            . "Duration: {$duration} seconds\n"
            . "Input device: {$inputDevice}\n\n"
            . "This is placeholder transcription text. Replace CockpitRecorderService::processStubTranscription() "
            . "with the production transcription provider when the POC is ready for real ASR.";
    }

    private static function uploadErrorText(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_OK => 'OK',
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form limit',
            UPLOAD_ERR_PARTIAL => 'Partial upload',
            UPLOAD_ERR_NO_FILE => 'No file received',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temp directory',
            UPLOAD_ERR_CANT_WRITE => 'Cannot write temp file',
            UPLOAD_ERR_EXTENSION => 'Blocked by extension',
            default => 'Upload error ' . $code,
        };
    }

    private static function normalizeRecordingUid(string $uid): string
    {
        $uid = trim($uid);
        $uid = preg_replace('/[^A-Za-z0-9._-]+/', '-', $uid) ?? '';
        return trim(substr($uid, 0, 96), '.-_');
    }

    private static function normalizeLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        $language = preg_replace('/[^a-z0-9_-]+/', '', $language) ?? '';
        return $language !== '' ? substr($language, 0, 16) : 'en';
    }

    private static function normalizeDateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($value);
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private static function safeExtension(string $filename, string $mimeType): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]+/', '', $ext) ?? '';
        if (in_array($ext, array('m4a', 'aac', 'wav', 'mp3', 'caf'), true)) {
            return $ext;
        }

        $mimeType = strtolower(trim($mimeType));
        return match ($mimeType) {
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/aac' => 'aac',
            'audio/mpeg' => 'mp3',
            'audio/x-caf' => 'caf',
            default => 'm4a',
        };
    }

    private static function relativeAudioPath(string $recordingUid, string $ext): string
    {
        $safeUid = self::normalizeRecordingUid($recordingUid) ?: bin2hex(random_bytes(8));
        return 'storage/cockpit_recorder/audio/' . gmdate('Y/m/d') . '/' . $safeUid . '.' . $ext;
    }

    private static function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create storage directory.');
        }
    }
}
