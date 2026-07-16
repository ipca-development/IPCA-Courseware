<?php
declare(strict_types=1);

require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/CockpitAircraftService.php';

/**
 * Cockpit Recorder POC storage, upload metadata, and stub transcription workflow.
 */
final class CockpitRecorderService
{
    private const TABLE = 'ipca_cockpit_recordings';
    private const CHUNK_TABLE = 'ipca_cockpit_recording_transcription_chunks';
    private const TRANSCRIPTION_CHUNK_SECONDS = 300.0;

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

    public static function ahrsRoot(): string
    {
        return self::projectRoot() . '/storage/cockpit_recorder/ahrs';
    }

    public static function gpsRoot(): string
    {
        return self::projectRoot() . '/storage/cockpit_recorder/gps';
    }

    public static function g3xRoot(): string
    {
        return self::projectRoot() . '/storage/cockpit_recorder/g3x';
    }

    public static function uploadSessionRoot(): string
    {
        return self::projectRoot() . '/storage/cockpit_recorder/upload_sessions';
    }

    public static function tablesPresent(PDO $pdo): bool
    {
        $stmt = $pdo->query("SHOW TABLES LIKE 'ipca_cockpit_recordings'");
        return $stmt !== false && $stmt->fetchColumn() !== false;
    }

    public static function transcriptionChunkTablePresent(PDO $pdo): bool
    {
        $stmt = $pdo->query("SHOW TABLES LIKE 'ipca_cockpit_recording_transcription_chunks'");
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
     * @param array<string,mixed>|null $ahrsFile
     * @param array<string,mixed>|null $gpsFile
     * @return array<string,mixed>
     */
    public function storeUploadedRecording(array $file, array $metadata, ?array $ahrsFile = null, ?array $gpsFile = null): array
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

        $originalName = trim((string)($file['name'] ?? 'recording.m4a'));
        return $this->storeRecordingFromLocalPaths(
            $tmp,
            true,
            $metadata,
            $originalName,
            (string)($file['type'] ?? ''),
            $ahrsFile,
            $gpsFile,
            null,
            null,
            null
        );
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public function storeAssembledRecording(string $audioPath, array $metadata, string $originalName, string $mimeType, ?string $ahrsPath = null, ?string $gpsPath = null, ?string $g3xPath = null): array
    {
        $this->requireTables();
        if (!is_file($audioPath)) {
            throw new RuntimeException('Assembled audio file is missing.');
        }
        return $this->storeRecordingFromLocalPaths($audioPath, false, $metadata, $originalName, $mimeType, null, null, $ahrsPath, $gpsPath, $g3xPath);
    }

    /**
     * @return array<string,mixed>
     */
    public function storeSupplementalG3X(string $recordingUid, string $g3xPath, ?string $importProfile = null): array
    {
        $this->requireTables();
        $recording = $this->recordingByUid(self::normalizeRecordingUid($recordingUid));
        if (!$recording) {
            throw new RuntimeException('Recording not found.');
        }
        if (!is_file($g3xPath)) {
            throw new RuntimeException('Assembled G3X CSV is missing.');
        }

        $importProfile = $this->resolveImportProfile($importProfile, $recording);
        $this->storeLocalG3X((int)$recording['id'], (string)$recording['recording_uid'], $g3xPath, $importProfile);
        $recording = $this->recordingByUid((string)$recording['recording_uid']) ?: $recording;
        $this->markReconstructionStale((int)$recording['id']);

        return array(
            'ok' => true,
            'recording' => $this->publicRecordingPayload($recording),
        );
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed>|null $ahrsFile
     * @param array<string,mixed>|null $gpsFile
     * @return array<string,mixed>
     */
    private function storeRecordingFromLocalPaths(
        string $audioSourcePath,
        bool $audioIsUploadedFile,
        array $metadata,
        string $originalName,
        string $mimeType,
        ?array $ahrsFile,
        ?array $gpsFile,
        ?string $ahrsPath,
        ?string $gpsPath,
        ?string $g3xPath = null
    ): array {
        $recordingUid = self::normalizeRecordingUid((string)($metadata['recording_id'] ?? ''));
        if ($recordingUid === '') {
            $recordingUid = bin2hex(random_bytes(16));
        }

        $originalName = trim($originalName) !== '' ? trim($originalName) : 'recording.m4a';
        $mimeType = trim($mimeType);
        $ext = self::safeExtension($originalName, $mimeType);
        $relativePath = self::relativeAudioPath($recordingUid, $ext);
        $absolutePath = self::projectRoot() . '/' . $relativePath;
        self::ensureDirectory(dirname($absolutePath));

        $stored = $audioIsUploadedFile
            ? move_uploaded_file($audioSourcePath, $absolutePath)
            : copy($audioSourcePath, $absolutePath);
        if (!$stored) {
            throw new RuntimeException('Could not store uploaded audio.');
        }

        $size = (int)filesize($absolutePath);
        if ($size <= 0) {
            throw new RuntimeException('Uploaded audio file is empty.');
        }

        $startedAt = self::normalizeDateTime((string)($metadata['started_at'] ?? ''));
        $duration = max(0.0, (float)($metadata['duration'] ?? 0));
        $inputDevice = trim((string)($metadata['input_device'] ?? ''));
        $language = self::normalizeLanguage((string)($metadata['language'] ?? 'en'));

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

        $this->storeRecordingAircraftSnapshot((int)$recording['id'], $metadata);
        $this->storeRecordingAltimeterSetting((int)$recording['id'], $metadata);
        $this->storeRecordingAtmosphereMetadata((int)$recording['id'], $metadata);
        $this->storeRecordingSessionMetadata((int)$recording['id'], $metadata);
        $recording = $this->recordingByUid($recordingUid) ?: $recording;

        if ($ahrsFile !== null && $this->isPresentUpload($ahrsFile)) {
            $this->storeUploadedAHRS((int)$recording['id'], $recordingUid, $ahrsFile);
            $recording = $this->recordingByUid($recordingUid) ?: $recording;
        }

        if ($gpsFile !== null && $this->isPresentUpload($gpsFile)) {
            $this->storeUploadedGPS((int)$recording['id'], $recordingUid, $gpsFile);
            $recording = $this->recordingByUid($recordingUid) ?: $recording;
        }

        if ($ahrsPath !== null && is_file($ahrsPath)) {
            $this->storeLocalAHRS((int)$recording['id'], $recordingUid, $ahrsPath);
            $recording = $this->recordingByUid($recordingUid) ?: $recording;
        }

        if ($gpsPath !== null && is_file($gpsPath)) {
            $this->storeLocalGPS((int)$recording['id'], $recordingUid, $gpsPath);
            $recording = $this->recordingByUid($recordingUid) ?: $recording;
        }

        if ($g3xPath !== null && is_file($g3xPath)) {
            $this->storeLocalG3X((int)$recording['id'], $recordingUid, $g3xPath, $this->resolveImportProfile((string)($metadata['import_profile'] ?? ''), $recording));
            $recording = $this->recordingByUid($recordingUid) ?: $recording;
        }

        $this->storeRecordingHealthAnalysis((int)$recording['id']);
        $recording = $this->recordingByUid($recordingUid) ?: $recording;

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
        $where = $this->activeRecordingWhereClause();
        $stmt = $this->pdo->query("
            SELECT *
            FROM " . self::TABLE . "
            {$where}
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
    public function adminRecordings(int $limit = 100, bool $includeDeleted = false): array
    {
        $this->requireTables();
        $limit = max(1, min(250, $limit));
        $where = $includeDeleted ? '' : $this->activeRecordingWhereClause();
        $stmt = $this->pdo->query("
            SELECT *
            FROM " . self::TABLE . "
            {$where}
            ORDER BY COALESCE(started_at, created_at) DESC, id DESC
            LIMIT " . $limit
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function adminTranscriptionChunks(int $recordingId): array
    {
        $this->requireTables();
        if ($recordingId <= 0 || !self::transcriptionChunkTablePresent($this->pdo)) {
            return array();
        }

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM " . self::CHUNK_TABLE . "
            WHERE recording_id = ?
            ORDER BY chunk_index ASC
        ");
        $stmt->execute(array($recordingId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    public function recordingByAnyId(string $id, bool $includeDeleted = false): ?array
    {
        $this->requireTables();
        $id = trim($id);
        if ($id === '') {
            return null;
        }

        $activeWhere = $includeDeleted ? '' : $this->activeRecordingPredicate('AND');
        if (ctype_digit($id)) {
            $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? ' . $activeWhere . ' LIMIT 1');
            $stmt->execute(array((int)$id));
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE recording_uid = ? ' . $activeWhere . ' LIMIT 1');
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
     * @param list<int> $recordingIds
     */
    public function softDeleteRecordings(array $recordingIds, ?int $actorUserId, string $reason = ''): int
    {
        $this->requireTables();
        $this->requireSoftDeleteColumns();
        $ids = $this->normalizeRecordingIds($recordingIds);
        if (!$ids) {
            return 0;
        }

        $reason = substr(trim($reason), 0, 255);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $params = array_merge(array($actorUserId, $reason), $ids);
        $stmt = $this->pdo->prepare("
            UPDATE " . self::TABLE . "
            SET deleted_at = COALESCE(deleted_at, CURRENT_TIMESTAMP),
                deleted_by_user_id = ?,
                delete_reason = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id IN ({$placeholders})
              AND deleted_at IS NULL
        ");
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * @param list<int> $recordingIds
     */
    public function restoreRecordings(array $recordingIds): int
    {
        $this->requireTables();
        $this->requireSoftDeleteColumns();
        $ids = $this->normalizeRecordingIds($recordingIds);
        if (!$ids) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("
            UPDATE " . self::TABLE . "
            SET deleted_at = NULL,
                deleted_by_user_id = NULL,
                delete_reason = '',
                updated_at = CURRENT_TIMESTAMP
            WHERE id IN ({$placeholders})
              AND deleted_at IS NOT NULL
        ");
        $stmt->execute($ids);
        return $stmt->rowCount();
    }

    /**
     * @return array{path:string,mime:string,filename:string}|null
     */
    public function ahrsFileForRecording(string $id): ?array
    {
        return $this->storedEvidenceFileForRecording($id, 'ahrs_storage_path', self::ahrsRoot(), '.ahrs.json', 'application/json');
    }

    /**
     * @return array{path:string,mime:string,filename:string}|null
     */
    public function gpsFileForRecording(string $id): ?array
    {
        return $this->storedEvidenceFileForRecording($id, 'gps_storage_path', self::gpsRoot(), '.gps.json', 'application/json');
    }

    /**
     * @return array{path:string,mime:string,filename:string}|null
     */
    public function g3xFileForRecording(string $id): ?array
    {
        return $this->storedEvidenceFileForRecording($id, 'g3x_storage_path', self::g3xRoot(), '.g3x.csv', 'text/csv');
    }

    /**
     * @return array{path:string,mime:string,filename:string}|null
     */
    private function storedEvidenceFileForRecording(
        string $id,
        string $column,
        string $rootDir,
        string $suffix,
        string $mime
    ): ?array {
        $recording = $this->recordingByAnyId($id);
        if (!$recording) {
            return null;
        }
        if (!$this->hasColumn($column)) {
            return null;
        }
        $relativePath = (string)($recording[$column] ?? '');
        if ($relativePath === '') {
            return null;
        }
        $path = self::projectRoot() . '/' . ltrim($relativePath, '/');
        $realPath = realpath($path);
        $root = realpath($rootDir);
        if ($root === false || $realPath === false || !str_starts_with($realPath, $root) || !is_file($realPath)) {
            return null;
        }
        $filename = (string)($recording['recording_uid'] ?? 'recording') . $suffix;
        return array(
            'path' => $realPath,
            'mime' => $mime,
            'filename' => $filename,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function processTranscription(int $recordingId): array
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
                transcription_progress = 10,
                transcription_started_at = COALESCE(transcription_started_at, CURRENT_TIMESTAMP),
                error_message = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute(array($recordingId));

        $duration = (float)($recording['duration_seconds'] ?? 0);
        $result = $duration > self::TRANSCRIPTION_CHUNK_SECONDS
            ? $this->transcribeWithOpenAIChunks($recording)
            : array(
                'status' => 'ready',
                'text' => $this->transcribeWithOpenAI($recording),
                'error' => null,
            );

        $status = (string)($result['status'] ?? 'failed');
        if (!in_array($status, array('ready', 'failed'), true)) {
            $status = 'failed';
        }
        $transcript = trim((string)($result['text'] ?? ''));
        $error = trim((string)($result['error'] ?? ''));
        $progress = $status === 'ready' ? 100 : 0;

        $this->pdo->prepare("
            UPDATE " . self::TABLE . "
            SET transcription_status = ?,
                transcription_progress = ?,
                transcript_text = ?,
                error_message = ?,
                transcription_completed_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute(array($status, $progress, $transcript !== '' ? $transcript : null, $error !== '' ? $error : null, $recordingId));

        $updated = $this->recordingByAnyId((string)$recordingId);

        return array(
            'ok' => true,
            'done' => true,
            'recording' => $updated ? $this->publicRecordingPayload($updated) : null,
        );
    }

    /**
     * Backward-compatible method name retained for deployed status/worker callers.
     *
     * @return array<string,mixed>
     */
    public function processStubTranscription(int $recordingId): array
    {
        return $this->processTranscription($recordingId);
    }

    /**
     * Processes at most one transcription chunk and returns quickly enough for admin/status polling.
     *
     * @return array<string,mixed>
     */
    public function processTranscriptionStep(int $recordingId): array
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
        if ((string)($recording['transcription_status'] ?? '') === 'ready') {
            return array('ok' => true, 'done' => true, 'recording' => $this->publicRecordingPayload($recording));
        }

        $duration = max(0.0, (float)($recording['duration_seconds'] ?? 0));
        if ($duration <= self::TRANSCRIPTION_CHUNK_SECONDS) {
            return $this->processTranscription($recordingId);
        }
        if (!self::transcriptionChunkTablePresent($this->pdo)) {
            return array('ok' => false, 'done' => true, 'error' => 'Apply scripts/sql/2026_06_22_cockpit_recorder_transcription_chunks.sql first.');
        }

        $chunkCount = max(1, (int)ceil($duration / self::TRANSCRIPTION_CHUNK_SECONDS));
        $existing = $this->transcriptionChunksForRecording($recordingId);
        if (!$existing) {
            for ($index = 0; $index < $chunkCount; $index++) {
                $start = $index * self::TRANSCRIPTION_CHUNK_SECONDS;
                $end = min($duration, $start + self::TRANSCRIPTION_CHUNK_SECONDS);
                $this->storeTranscriptionChunk($recordingId, $index, $start, $end, 'queued', 0, null, null);
            }
        }

        $this->pdo->prepare("
            UPDATE " . self::TABLE . "
            SET transcription_status = 'transcribing',
                transcription_progress = GREATEST(transcription_progress, 10),
                transcription_started_at = COALESCE(transcription_started_at, CURRENT_TIMESTAMP),
                error_message = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute(array($recordingId));

        $chunks = $this->transcriptionChunksForRecording($recordingId);
        $next = null;
        foreach ($chunks as $chunk) {
            $status = (string)($chunk['status'] ?? '');
            if ($status === 'queued' || $status === 'transcribing') {
                $next = $chunk;
                break;
            }
        }

        if ($next === null) {
            return $this->finishSteppedTranscription($recordingId);
        }

        $index = (int)$next['chunk_index'];
        $start = (float)$next['start_seconds'];
        $end = (float)$next['end_seconds'];
        $this->storeTranscriptionChunk($recordingId, $index, $start, $end, 'transcribing', 0, null, null);

        $chunkPath = '';
        try {
            $realPath = $this->transcriptionAudioPath($recording);
            $chunkPath = $this->extractAudioChunk($realPath, $start, max(1.0, $end - $start), (string)($recording['recording_uid'] ?? 'recording'), $index);
            $mime = mime_content_type($chunkPath) ?: 'audio/mp4';
            $text = $this->transcribeAudioFile($chunkPath, $mime, basename($chunkPath), self::normalizeLanguage((string)($recording['language'] ?? 'en')));
            $this->storeTranscriptionChunk($recordingId, $index, $start, $end, 'ready', strlen($text), $text, null);
        } catch (Throwable $e) {
            $this->storeTranscriptionChunk($recordingId, $index, $start, $end, 'failed', 0, null, $e->getMessage());
        } finally {
            if ($chunkPath !== '' && is_file($chunkPath)) {
                @unlink($chunkPath);
            }
        }

        $readyCount = $this->countTranscriptionChunksByStatus($recordingId, 'ready');
        $failedCount = $this->countTranscriptionChunksByStatus($recordingId, 'failed');
        $progress = min(95, 10 + (int)round((($readyCount + $failedCount) / $chunkCount) * 85));
        $this->pdo->prepare("
            UPDATE " . self::TABLE . "
            SET transcription_progress = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute(array($progress, $recordingId));

        if (($readyCount + $failedCount) >= $chunkCount) {
            return $this->finishSteppedTranscription($recordingId);
        }

        $updated = $this->recordingByAnyId((string)$recordingId);
        return array(
            'ok' => true,
            'done' => false,
            'processed_chunk' => $index,
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

    public function resetTranscriptionForRetry(int $recordingId): void
    {
        if ($recordingId <= 0) {
            return;
        }

        $this->pdo->prepare("
            UPDATE " . self::TABLE . "
            SET transcription_status = 'queued',
                transcription_progress = 0,
                transcript_text = NULL,
                error_message = NULL,
                transcription_started_at = NULL,
                transcription_completed_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute(array($recordingId));

        if (self::transcriptionChunkTablePresent($this->pdo)) {
            $this->resetTranscriptionChunks($recordingId);
        }
    }

    public function spawnTranscriptionWorker(int $recordingId): bool
    {
        if ($recordingId <= 0) {
            return false;
        }
        if (!function_exists('exec')) {
            return false;
        }

        $php = self::findBinary(array(
            trim((string)PHP_BINDIR) !== '' ? rtrim((string)PHP_BINDIR, '/') . '/php' : '',
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/homebrew/bin/php',
            'php',
        ));
        if ($php === '') {
            return false;
        }
        $script = realpath(__DIR__ . '/../scripts/run_cockpit_recorder_transcription.php');
        if ($script === false) {
            return false;
        }

        $logDir = self::projectRoot() . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        if (!is_dir($logDir) || !is_writable($logDir)) {
            return false;
        }
        $logFile = $logDir . '/cockpit_recorder_' . $recordingId . '.log';
        @file_put_contents($logFile, '[' . gmdate('c') . '] Spawning cockpit transcription worker.' . PHP_EOL, FILE_APPEND);

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $cmd = 'start /B "" '
                . escapeshellarg($php) . ' '
                . escapeshellarg($script) . ' '
                . '--recording-id=' . $recordingId
                . ' >> ' . escapeshellarg($logFile) . ' 2>&1';
        } else {
            $cmd = 'nohup ' . escapeshellarg($php) . ' '
                . escapeshellarg($script) . ' '
                . '--recording-id=' . $recordingId
                . ' >> ' . escapeshellarg($logFile) . ' 2>&1 < /dev/null & echo $!';
        }

        @file_put_contents($logFile, '[' . gmdate('c') . '] Command: ' . $cmd . PHP_EOL, FILE_APPEND);
        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            @file_put_contents($logFile, '[' . gmdate('c') . '] Worker spawn command exited with code ' . $exitCode . PHP_EOL, FILE_APPEND);
            return false;
        }
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
            'aircraft_id' => (int)($recording['aircraft_id'] ?? 0),
            'aircraft_registration' => (string)($recording['aircraft_registration'] ?? ''),
            'aircraft_display_name' => (string)($recording['aircraft_display_name'] ?? ''),
            'aircraft_type' => (string)($recording['aircraft_type'] ?? ''),
            'aircraft_adsb_hex' => (string)($recording['aircraft_adsb_hex'] ?? ''),
            'flight_session_uid' => (string)($recording['flight_session_uid'] ?? ''),
            'flight_segment_index' => (int)($recording['flight_segment_index'] ?? 1),
            'previous_segment_uid' => (string)($recording['previous_segment_uid'] ?? ''),
            'is_test_recording' => !empty($recording['is_test_recording']),
            'source_gap_summary' => (string)($recording['source_gap_summary'] ?? ''),
            'language' => (string)($recording['language'] ?? 'en'),
            'upload_status' => (string)($recording['upload_status'] ?? 'pending'),
            'transcription_status' => (string)($recording['transcription_status'] ?? 'pending'),
            'progress' => (int)($recording['transcription_progress'] ?? 0),
            'file_size' => (int)($recording['file_size_bytes'] ?? 0),
            'ahrs_available' => trim((string)($recording['ahrs_storage_path'] ?? '')) !== '',
            'ahrs_file_size' => (int)($recording['ahrs_file_size_bytes'] ?? 0),
            'ahrs_sample_count' => (int)($recording['ahrs_sample_count'] ?? 0),
            'gps_available' => trim((string)($recording['gps_storage_path'] ?? '')) !== '',
            'gps_file_size' => (int)($recording['gps_file_size_bytes'] ?? 0),
            'gps_sample_count' => (int)($recording['gps_sample_count'] ?? 0),
            'g3x_available' => trim((string)($recording['g3x_storage_path'] ?? '')) !== '',
            'g3x_file_size' => (int)($recording['g3x_file_size_bytes'] ?? 0),
            'g3x_row_count' => (int)($recording['g3x_row_count'] ?? 0),
            'g3x_aircraft_ident' => (string)($recording['g3x_aircraft_ident'] ?? ''),
            'g3x_imported_at' => $recording['g3x_imported_at'] ?? null,
            'health_warning_count' => (int)($recording['health_warning_count'] ?? 0),
            'health_analyzed_at' => $recording['health_analyzed_at'] ?? null,
            'original_filename' => (string)($recording['original_filename'] ?? ''),
            'uploaded_at' => $recording['uploaded_at'] ?? null,
            'created_at' => $recording['created_at'] ?? null,
            'updated_at' => $recording['updated_at'] ?? null,
            'error' => (string)($recording['error_message'] ?? ''),
        );
    }

    /**
     * @param array<string,mixed> $file
     */
    private function storeUploadedAHRS(int $recordingId, string $recordingUid, array $file): void
    {
        if (!$this->hasColumn('ahrs_storage_path')) {
            return;
        }

        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            return;
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('AHRS upload failed: ' . self::uploadErrorText($err));
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid AHRS upload.');
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            return;
        }
        if ($size > 25 * 1024 * 1024) {
            throw new RuntimeException('AHRS JSON is too large (max 25 MB).');
        }

        $raw = file_get_contents($tmp);
        if ($raw === false || trim($raw) === '') {
            throw new RuntimeException('Could not read AHRS JSON.');
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Invalid AHRS JSON: ' . $e->getMessage());
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('AHRS JSON must be an array of samples.');
        }

        $sampleCount = count($decoded);
        $relativePath = self::relativeAHRSPath($recordingUid);
        $absolutePath = self::projectRoot() . '/' . $relativePath;
        self::ensureDirectory(dirname($absolutePath));

        if (!move_uploaded_file($tmp, $absolutePath)) {
            throw new RuntimeException('Could not store AHRS JSON.');
        }

        $stmt = $this->pdo->prepare("
            UPDATE " . self::TABLE . "
            SET ahrs_storage_path = ?,
                ahrs_file_size_bytes = ?,
                ahrs_sample_count = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute(array($relativePath, $size, $sampleCount, $recordingId));
    }

    /**
     * @param array<string,mixed> $file
     */
    private function storeUploadedGPS(int $recordingId, string $recordingUid, array $file): void
    {
        if (!$this->hasColumn('gps_storage_path')) {
            return;
        }

        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            return;
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('GPS upload failed: ' . self::uploadErrorText($err));
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid GPS upload.');
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            return;
        }
        if ($size > 25 * 1024 * 1024) {
            throw new RuntimeException('GPS JSON is too large (max 25 MB).');
        }

        $raw = file_get_contents($tmp);
        if ($raw === false || trim($raw) === '') {
            throw new RuntimeException('Could not read GPS JSON.');
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Invalid GPS JSON: ' . $e->getMessage());
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('GPS JSON must be an array of samples.');
        }

        $sampleCount = count($decoded);
        $relativePath = self::relativeGPSPath($recordingUid);
        $absolutePath = self::projectRoot() . '/' . $relativePath;
        self::ensureDirectory(dirname($absolutePath));

        if (!move_uploaded_file($tmp, $absolutePath)) {
            throw new RuntimeException('Could not store GPS JSON.');
        }

        $stmt = $this->pdo->prepare("
            UPDATE " . self::TABLE . "
            SET gps_storage_path = ?,
                gps_file_size_bytes = ?,
                gps_sample_count = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute(array($relativePath, $size, $sampleCount, $recordingId));
    }

    private function storeLocalAHRS(int $recordingId, string $recordingUid, string $path): void
    {
        if (!$this->hasColumn('ahrs_storage_path')) {
            return;
        }
        $this->storeLocalSensorJson($recordingId, $recordingUid, $path, 'AHRS', self::relativeAHRSPath($recordingUid), 'ahrs_storage_path', 'ahrs_file_size_bytes', 'ahrs_sample_count');
    }

    private function storeLocalGPS(int $recordingId, string $recordingUid, string $path): void
    {
        if (!$this->hasColumn('gps_storage_path')) {
            return;
        }
        $this->storeLocalSensorJson($recordingId, $recordingUid, $path, 'GPS', self::relativeGPSPath($recordingUid), 'gps_storage_path', 'gps_file_size_bytes', 'gps_sample_count');
    }

    /**
     * @param array<string,mixed> $recording
     */
    private function resolveImportProfile(?string $importProfile, array $recording): string
    {
        require_once __DIR__ . '/GarminCsvImportProfile.php';
        $importProfile = trim((string)$importProfile);
        if ($importProfile !== '') {
            return GarminCsvImportProfile::normalize($importProfile);
        }
        return GarminCsvImportProfile::forAircraft(
            (string)($recording['aircraft_registration'] ?? ''),
            (string)($recording['aircraft_display_name'] ?? ''),
            (string)($recording['aircraft_type'] ?? '')
        );
    }

    private function storeLocalG3X(int $recordingId, string $recordingUid, string $path, ?string $importProfile = null): void
    {
        if (!$this->hasColumn('g3x_storage_path')) {
            return;
        }
        if (!is_file($path)) {
            return;
        }

        require_once __DIR__ . '/G3XFlightStreamParser.php';
        require_once __DIR__ . '/GarminCsvImportProfile.php';
        $size = (int)filesize($path);
        if ($size <= 0) {
            return;
        }
        if ($size > 250 * 1024 * 1024) {
            throw new RuntimeException('Garmin CSV is too large (max 250 MB).');
        }

        $parsed = G3XFlightStreamParser::parseFile($path, $importProfile);
        $relativePath = self::relativeG3XPath($recordingUid);
        $absolutePath = self::projectRoot() . '/' . $relativePath;
        self::ensureDirectory(dirname($absolutePath));
        if (!copy($path, $absolutePath)) {
            throw new RuntimeException('Could not store G3X CSV.');
        }

        $columns = array(
            'g3x_storage_path' => $relativePath,
            'g3x_file_size_bytes' => $size,
            'g3x_row_count' => (int)$parsed['row_count'],
            'g3x_aircraft_ident' => (string)$parsed['aircraft_ident'],
            'g3x_imported_at' => gmdate('Y-m-d H:i:s'),
        );
        $sets = array();
        $params = array();
        foreach ($columns as $column => $value) {
            if (!$this->hasColumn($column)) {
                continue;
            }
            $sets[] = $column . ' = ?';
            $params[] = $value;
        }
        if (!$sets) {
            return;
        }
        $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        $params[] = $recordingId;
        $stmt = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
    }

    private function markReconstructionStale(int $recordingId): void
    {
        if (!$this->hasColumn('reconstruction_status')) {
            return;
        }
        $stmt = $this->pdo->prepare('
            UPDATE ' . self::TABLE . '
            SET reconstruction_status = \'not_started\',
                timeline_status = \'not_started\',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $stmt->execute(array($recordingId));
    }

    private function storeLocalSensorJson(
        int $recordingId,
        string $recordingUid,
        string $sourcePath,
        string $label,
        string $relativePath,
        string $pathColumn,
        string $sizeColumn,
        string $countColumn
    ): void {
        unset($recordingUid);
        if (!is_file($sourcePath)) {
            return;
        }
        $size = (int)filesize($sourcePath);
        if ($size <= 0) {
            return;
        }
        if ($size > 25 * 1024 * 1024) {
            throw new RuntimeException($label . ' JSON is too large (max 25 MB).');
        }
        $raw = file_get_contents($sourcePath);
        if ($raw === false || trim($raw) === '') {
            throw new RuntimeException('Could not read ' . $label . ' JSON.');
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Invalid ' . $label . ' JSON: ' . $e->getMessage());
        }
        if (!is_array($decoded)) {
            throw new RuntimeException($label . ' JSON must be an array of samples.');
        }

        $absolutePath = self::projectRoot() . '/' . $relativePath;
        self::ensureDirectory(dirname($absolutePath));
        if (!copy($sourcePath, $absolutePath)) {
            throw new RuntimeException('Could not store ' . $label . ' JSON.');
        }

        $stmt = $this->pdo->prepare("
            UPDATE " . self::TABLE . "
            SET {$pathColumn} = ?,
                {$sizeColumn} = ?,
                {$countColumn} = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute(array($relativePath, $size, count($decoded), $recordingId));
    }

    /**
     * @param array<string,mixed> $file
     */
    private function isPresentUpload(array $file): bool
    {
        return (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    private function hasColumn(string $columnName): bool
    {
        static $cache = array();
        if (array_key_exists($columnName, $cache)) {
            return (bool)$cache[$columnName];
        }

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute(array(self::TABLE, $columnName));
        $cache[$columnName] = (int)$stmt->fetchColumn() > 0;
        return (bool)$cache[$columnName];
    }

    private function activeRecordingWhereClause(): string
    {
        return $this->activeRecordingPredicate('WHERE');
    }

    private function activeRecordingPredicate(string $prefix): string
    {
        if (!$this->hasColumn('deleted_at')) {
            return '';
        }
        $prefix = strtoupper(trim($prefix));
        if ($prefix !== 'AND' && $prefix !== 'WHERE') {
            $prefix = 'AND';
        }
        return $prefix . ' deleted_at IS NULL';
    }

    private function requireSoftDeleteColumns(): void
    {
        foreach (array('deleted_at', 'deleted_by_user_id', 'delete_reason') as $column) {
            if (!$this->hasColumn($column)) {
                throw new RuntimeException('Apply scripts/sql/2026_07_16_cockpit_recorder_soft_delete.sql before hiding recordings.');
            }
        }
    }

    /**
     * @param list<int> $recordingIds
     * @return list<int>
     */
    private function normalizeRecordingIds(array $recordingIds): array
    {
        $ids = array();
        foreach ($recordingIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function storeRecordingAltimeterSetting(int $recordingId, array $metadata): void
    {
        if ($recordingId <= 0 || !$this->hasColumn('altimeter_setting_inhg')) {
            return;
        }

        $setting = $this->metadataAltimeterSettingInhg($metadata);
        if ($setting === null) {
            return;
        }

        $source = trim((string)($metadata['altimeter_setting_source'] ?? 'app_logged'));
        if ($source === '') {
            $source = 'app_logged';
        }
        $source = substr($source, 0, 64);

        $stmt = $this->pdo->prepare('
            UPDATE ' . self::TABLE . '
            SET altimeter_setting_inhg = ?,
                altimeter_setting_source = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $stmt->execute(array($setting, $source, $recordingId));
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function storeRecordingAtmosphereMetadata(int $recordingId, array $metadata): void
    {
        if ($recordingId <= 0) {
            return;
        }

        $sets = array();
        $values = array();
        if ($this->hasColumn('airport_elevation_ft') && $this->hasColumn('airport_elevation_source')) {
            $elevation = $this->metadataFloat($metadata, array('airport_elevation_ft', 'field_elevation_ft', 'departure_airport_elevation_ft'), -1500.0, 30000.0);
            if ($elevation !== null) {
                $sets[] = 'airport_elevation_ft = ?';
                $values[] = round($elevation, 1);
                $sets[] = 'airport_elevation_source = ?';
                $values[] = substr(trim((string)($metadata['airport_elevation_source'] ?? 'app_logged')), 0, 64) ?: 'app_logged';
            }
        }
        if ($this->hasColumn('oat_c') && $this->hasColumn('oat_source')) {
            $oat = $this->metadataFloat($metadata, array('oat_c', 'temperature_c', 'outside_air_temperature_c'), -80.0, 70.0);
            if ($oat !== null) {
                $sets[] = 'oat_c = ?';
                $values[] = round($oat, 1);
                $sets[] = 'oat_source = ?';
                $values[] = substr(trim((string)($metadata['oat_source'] ?? 'app_logged')), 0, 64) ?: 'app_logged';
            }
        }

        if (!$sets) {
            return;
        }
        $values[] = $recordingId;
        $stmt = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute($values);
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function storeRecordingSessionMetadata(int $recordingId, array $metadata): void
    {
        if ($recordingId <= 0) {
            return;
        }

        $sets = array();
        $values = array();
        $stringColumns = array(
            'flight_session_uid' => substr(trim((string)($metadata['flight_session_uid'] ?? '')), 0, 96),
            'previous_segment_uid' => substr(trim((string)($metadata['previous_segment_uid'] ?? '')), 0, 96),
            'source_gap_summary' => substr(trim((string)($metadata['source_gap_summary'] ?? '')), 0, 2000),
        );
        foreach ($stringColumns as $column => $value) {
            if ($this->hasColumn($column) && $value !== '') {
                $sets[] = $column . ' = ?';
                $values[] = $value;
            }
        }

        if ($this->hasColumn('flight_segment_index')) {
            $sets[] = 'flight_segment_index = ?';
            $values[] = max(1, (int)($metadata['flight_segment_index'] ?? 1));
        }
        if ($this->hasColumn('is_test_recording')) {
            $sets[] = 'is_test_recording = ?';
            $values[] = !empty($metadata['is_test_recording']) ? 1 : 0;
        }

        if (!$sets) {
            return;
        }

        $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        $values[] = $recordingId;
        $stmt = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($values);
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function metadataAltimeterSettingInhg(array $metadata): ?float
    {
        foreach (array('altimeter_setting_inhg', 'qnh_inhg', 'altimeter_inhg', 'altimeter') as $key) {
            if (isset($metadata[$key]) && is_numeric($metadata[$key])) {
                return self::normalizeAltimeterSettingInhg((float)$metadata[$key]);
            }
        }
        foreach (array('qnh_hpa', 'altimeter_hpa') as $key) {
            if (isset($metadata[$key]) && is_numeric($metadata[$key])) {
                return self::normalizeAltimeterSettingInhg((float)$metadata[$key]);
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $metadata
     * @param list<string> $keys
     */
    private function metadataFloat(array $metadata, array $keys, float $min, float $max): ?float
    {
        foreach ($keys as $key) {
            if (isset($metadata[$key]) && is_numeric($metadata[$key])) {
                $value = (float)$metadata[$key];
                if ($value >= $min && $value <= $max) {
                    return $value;
                }
            }
        }
        return null;
    }

    private static function normalizeAltimeterSettingInhg(float $value): ?float
    {
        if ($value >= 25.0 && $value <= 33.5) {
            return round($value, 2);
        }
        if ($value >= 800.0 && $value <= 1100.0) {
            return round($value / 33.8638866667, 2);
        }
        return null;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function storeRecordingAircraftSnapshot(int $recordingId, array $metadata): void
    {
        if ($recordingId <= 0 || !$this->hasColumn('aircraft_id')) {
            return;
        }

        $aircraftId = (int)($metadata['aircraft_id'] ?? 0);
        if ($aircraftId <= 0 || !CockpitAircraftService::tablesPresent($this->pdo)) {
            return;
        }

        $aircraftService = new CockpitAircraftService($this->pdo);
        $aircraft = $aircraftService->aircraftById($aircraftId);
        if (!$aircraft) {
            return;
        }

        $sets = array('aircraft_id = ?');
        $values = array($aircraftId);
        $columns = array(
            'aircraft_registration' => (string)($aircraft['registration'] ?? ''),
            'aircraft_display_name' => (string)($aircraft['display_name'] ?? ''),
            'aircraft_type' => (string)($aircraft['aircraft_type'] ?? ''),
            'aircraft_adsb_hex' => (string)($aircraft['adsb_hex'] ?? ''),
        );

        foreach ($columns as $column => $value) {
            if ($this->hasColumn($column)) {
                $sets[] = $column . ' = ?';
                $values[] = $value;
            }
        }

        $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        $values[] = $recordingId;
        $stmt = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($values);
    }

    private function storeRecordingHealthAnalysis(int $recordingId): void
    {
        if ($recordingId <= 0 || !$this->hasColumn('health_summary_json')) {
            return;
        }

        $recording = $this->recordingByAnyId((string)$recordingId);
        if (!$recording) {
            return;
        }

        try {
            $summary = $this->buildRecordingHealthSummary($recording);
        } catch (Throwable $e) {
            $summary = array(
                'analyzed_at' => gmdate('c'),
                'audio' => array(),
                'ahrs' => array(),
                'gps' => array(),
                'warnings' => array('Health analysis failed: ' . $e->getMessage()),
            );
        }

        $warnings = isset($summary['warnings']) && is_array($summary['warnings']) ? $summary['warnings'] : array();
        $json = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        $sets = array('health_summary_json = ?');
        $values = array($json);
        if ($this->hasColumn('health_warning_count')) {
            $sets[] = 'health_warning_count = ?';
            $values[] = count($warnings);
        }
        if ($this->hasColumn('health_analyzed_at')) {
            $sets[] = 'health_analyzed_at = CURRENT_TIMESTAMP';
        }
        $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        $values[] = $recordingId;

        $stmt = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($values);
    }

    /**
     * @param array<string,mixed> $recording
     * @return array<string,mixed>
     */
    private function buildRecordingHealthSummary(array $recording): array
    {
        $warnings = array();
        $audioPath = $this->safeStoredPath((string)($recording['storage_path'] ?? ''), self::audioRoot());
        $audioPresent = $audioPath !== null && is_file($audioPath);

        if ((string)($recording['upload_status'] ?? '') !== 'uploaded') {
            $warnings[] = 'Incomplete upload: upload status is ' . (string)($recording['upload_status'] ?? 'unknown') . '.';
        }
        if (!$audioPresent || (int)($recording['file_size_bytes'] ?? 0) <= 0) {
            $warnings[] = 'Incomplete upload: audio file is missing or empty.';
        }

        $ahrsPath = $this->safeStoredPath((string)($recording['ahrs_storage_path'] ?? ''), self::ahrsRoot());
        if ($ahrsPath === null) {
            $ahrs = $this->emptySensorHealth();
            $warnings[] = 'Missing AHRS JSON.';
        } else {
            $ahrs = $this->analyzeSensorJson($ahrsPath, 'AHRS', 2.0, 1.5);
            $warnings = array_merge($warnings, $ahrs['warnings']);
            unset($ahrs['warnings']);
        }

        $gpsPath = $this->safeStoredPath((string)($recording['gps_storage_path'] ?? ''), self::gpsRoot());
        if ($gpsPath === null) {
            $gps = $this->emptySensorHealth();
            $gps['max_groundspeed_kt'] = null;
            $gps['first_coordinate'] = null;
            $gps['last_coordinate'] = null;
            $warnings[] = 'Missing GPS JSON.';
        } else {
            $gps = $this->analyzeSensorJson($gpsPath, 'GPS', 0.5, 5.0);
            $warnings = array_merge($warnings, $gps['warnings']);
            unset($gps['warnings']);
        }

        return array(
            'analyzed_at' => gmdate('c'),
            'audio' => array(
                'duration_seconds' => (float)($recording['duration_seconds'] ?? 0),
                'file_size_bytes' => (int)($recording['file_size_bytes'] ?? 0),
                'file_present' => $audioPresent,
            ),
            'ahrs' => $ahrs,
            'gps' => $gps,
            'warnings' => array_values(array_unique($warnings)),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function emptySensorHealth(): array
    {
        return array(
            'sample_count' => 0,
            'first_timestamp' => null,
            'last_timestamp' => null,
            'average_sample_rate_hz' => null,
            'max_timestamp_gap_seconds' => null,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function analyzeSensorJson(string $path, string $label, float $lowRateThresholdHz, float $gapThresholdSeconds): array
    {
        $health = $this->emptySensorHealth();
        $health['warnings'] = array();
        if ($label === 'GPS') {
            $health['max_groundspeed_kt'] = null;
            $health['first_coordinate'] = null;
            $health['last_coordinate'] = null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            $health['warnings'][] = $label . ' JSON is missing or empty.';
            return $health;
        }

        try {
            $samples = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $health['warnings'][] = $label . ' JSON is invalid: ' . $e->getMessage() . '.';
            return $health;
        }

        if (!is_array($samples)) {
            $health['warnings'][] = $label . ' JSON is not an array of samples.';
            return $health;
        }

        $health['sample_count'] = count($samples);
        if (count($samples) === 0) {
            $health['warnings'][] = $label . ' JSON contains no samples.';
            return $health;
        }

        $firstTime = null;
        $lastTime = null;
        $previousSeconds = null;
        $maxGap = null;
        $maxGroundspeed = null;
        $firstCoordinate = null;
        $lastCoordinate = null;

        foreach ($samples as $sample) {
            if (!is_array($sample)) {
                continue;
            }

            $timestamp = isset($sample['timestamp']) ? (string)$sample['timestamp'] : '';
            $seconds = self::parseTimestampSeconds($timestamp);
            if ($timestamp !== '' && $seconds !== null) {
                if ($firstTime === null) {
                    $firstTime = $timestamp;
                }
                $lastTime = $timestamp;
                if ($previousSeconds !== null) {
                    $gap = $seconds - $previousSeconds;
                    if ($gap >= 0 && ($maxGap === null || $gap > $maxGap)) {
                        $maxGap = $gap;
                    }
                }
                $previousSeconds = $seconds;
            }

            if ($label === 'GPS') {
                $lat = $sample['latitude'] ?? null;
                $lon = $sample['longitude'] ?? null;
                if (is_numeric($lat) && is_numeric($lon)) {
                    $coordinate = array('latitude' => (float)$lat, 'longitude' => (float)$lon);
                    if ($firstCoordinate === null) {
                        $firstCoordinate = $coordinate;
                    }
                    $lastCoordinate = $coordinate;
                }

                $speedKt = null;
                if (isset($sample['speedKnots']) && is_numeric($sample['speedKnots'])) {
                    $speedKt = (float)$sample['speedKnots'];
                } elseif (isset($sample['speedMetersPerSecond']) && is_numeric($sample['speedMetersPerSecond'])) {
                    $speedKt = (float)$sample['speedMetersPerSecond'] * 1.943844492;
                }
                if ($speedKt !== null && ($maxGroundspeed === null || $speedKt > $maxGroundspeed)) {
                    $maxGroundspeed = $speedKt;
                }
            }
        }

        $health['first_timestamp'] = $firstTime;
        $health['last_timestamp'] = $lastTime;
        $health['max_timestamp_gap_seconds'] = $maxGap;

        $firstSeconds = self::parseTimestampSeconds((string)$firstTime);
        $lastSeconds = self::parseTimestampSeconds((string)$lastTime);
        if ($firstSeconds !== null && $lastSeconds !== null && $lastSeconds > $firstSeconds && count($samples) > 1) {
            $health['average_sample_rate_hz'] = (count($samples) - 1) / ($lastSeconds - $firstSeconds);
        }

        if ($health['average_sample_rate_hz'] !== null && (float)$health['average_sample_rate_hz'] < $lowRateThresholdHz) {
            $health['warnings'][] = sprintf('%s sample rate is low: %.2f Hz.', $label, (float)$health['average_sample_rate_hz']);
        }
        if ($maxGap !== null && $maxGap > $gapThresholdSeconds) {
            $health['warnings'][] = sprintf('%s timestamp gap detected: %.2f seconds.', $label, $maxGap);
        }
        if ($firstTime === null || $lastTime === null) {
            $health['warnings'][] = $label . ' timestamps are missing or invalid.';
        }

        if ($label === 'GPS') {
            $health['max_groundspeed_kt'] = $maxGroundspeed;
            $health['first_coordinate'] = $firstCoordinate;
            $health['last_coordinate'] = $lastCoordinate;
        }

        return $health;
    }

    private function safeStoredPath(string $relativePath, string $root): ?string
    {
        $relativePath = trim($relativePath);
        if ($relativePath === '') {
            return null;
        }
        $path = self::projectRoot() . '/' . ltrim($relativePath, '/');
        $realPath = realpath($path);
        $realRoot = realpath($root);
        if ($realPath === false || $realRoot === false || !str_starts_with($realPath, $realRoot) || !is_file($realPath)) {
            return null;
        }
        return $realPath;
    }

    private static function parseTimestampSeconds(string $timestamp): ?float
    {
        $timestamp = trim($timestamp);
        if ($timestamp === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($timestamp);
            return (float)$dt->format('U.u');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $recording
     */
    private function transcribeWithOpenAI(array $recording): string
    {
        $realPath = $this->transcriptionAudioPath($recording);
        $language = self::normalizeLanguage((string)($recording['language'] ?? 'en'));
        $mime = mime_content_type($realPath) ?: ((string)($recording['mime_type'] ?? '') ?: 'audio/mp4');
        return $this->transcribeAudioFile($realPath, $mime, basename($realPath), $language);
    }

    /**
     * @param array<string,mixed> $recording
     * @return array{status:string,text:string,error:?string}
     */
    private function transcribeWithOpenAIChunks(array $recording): array
    {
        if (!self::transcriptionChunkTablePresent($this->pdo)) {
            throw new RuntimeException('Apply scripts/sql/2026_06_22_cockpit_recorder_transcription_chunks.sql before transcribing long recordings.');
        }

        $recordingId = (int)($recording['id'] ?? 0);
        if ($recordingId <= 0) {
            throw new RuntimeException('Recording id is missing for chunked transcription.');
        }

        $realPath = $this->transcriptionAudioPath($recording);
        $duration = max(0.0, (float)($recording['duration_seconds'] ?? 0));
        if ($duration <= 0) {
            $duration = self::TRANSCRIPTION_CHUNK_SECONDS;
        }

        $language = self::normalizeLanguage((string)($recording['language'] ?? 'en'));
        $chunkCount = max(1, (int)ceil($duration / self::TRANSCRIPTION_CHUNK_SECONDS));
        $this->resetTranscriptionChunks($recordingId);

        $texts = array();
        $failedChunks = array();

        for ($index = 0; $index < $chunkCount; $index++) {
            $start = $index * self::TRANSCRIPTION_CHUNK_SECONDS;
            $end = min($duration, $start + self::TRANSCRIPTION_CHUNK_SECONDS);
            if ($end <= $start) {
                $end = $start + self::TRANSCRIPTION_CHUNK_SECONDS;
            }

            $this->storeTranscriptionChunk($recordingId, $index, $start, $end, 'queued', 0, null, null);
            $this->storeTranscriptionChunk($recordingId, $index, $start, $end, 'transcribing', 0, null, null);
            $chunkPath = '';

            try {
                $chunkPath = $this->extractAudioChunk($realPath, $start, $end - $start, (string)($recording['recording_uid'] ?? 'recording'), $index);
                $mime = mime_content_type($chunkPath) ?: 'audio/mp4';
                $text = $this->transcribeAudioFile($chunkPath, $mime, basename($chunkPath), $language);
                $texts[] = $text;
                $this->storeTranscriptionChunk($recordingId, $index, $start, $end, 'ready', strlen($text), $text, null);
            } catch (Throwable $e) {
                $message = $e->getMessage();
                $failedChunks[] = $index;
                $this->storeTranscriptionChunk($recordingId, $index, $start, $end, 'failed', 0, null, $message);
            } finally {
                if ($chunkPath !== '' && is_file($chunkPath)) {
                    @unlink($chunkPath);
                }
            }

            $progress = min(95, 10 + (int)round((($index + 1) / $chunkCount) * 85));
            $this->pdo->prepare("
                UPDATE " . self::TABLE . "
                SET transcription_progress = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute(array($progress, $recordingId));
        }

        $combined = self::cleanTranscriptText(trim(implode("\n\n", array_filter(array_map('trim', $texts), fn(string $text): bool => $text !== ''))));
        if ($failedChunks) {
            $failedLabel = implode(', ', array_map(fn(int $i): string => (string)($i + 1), $failedChunks));
            $message = 'Partial transcript: chunk(s) ' . $failedLabel . ' failed. '
                . ($combined !== '' ? 'Partial transcript retained.' : 'No usable transcript text was returned.');
            return array('status' => 'failed', 'text' => $combined, 'error' => $message);
        }

        if ($combined === '') {
            return array('status' => 'failed', 'text' => '', 'error' => 'Chunked transcription returned no text.');
        }

        return array('status' => 'ready', 'text' => $combined, 'error' => null);
    }

    private function transcriptionAudioPath(array $recording): string
    {
        $relativePath = (string)($recording['storage_path'] ?? '');
        $path = self::projectRoot() . '/' . ltrim($relativePath, '/');
        $realPath = realpath($path);
        $audioRoot = realpath(self::audioRoot());
        if ($audioRoot === false || $realPath === false || !str_starts_with($realPath, $audioRoot) || !is_file($realPath)) {
            throw new RuntimeException('Uploaded audio file is missing.');
        }

        return $realPath;
    }

    private function transcribeAudioFile(string $realPath, string $mime, string $filename, string $language): string
    {
        $model = trim((string)(getenv('CW_OPENAI_ASR_MODEL') ?: ''));
        if ($model === '') {
            $model = 'gpt-4o-transcribe';
        }

        $prompt = self::transcriptionPrompt();

        $postFields = array(
            'file' => new CURLFile($realPath, $mime, $filename),
            'model' => $model,
            'prompt' => $prompt,
            'response_format' => 'json',
        );
        if ($language !== '') {
            $postFields['language'] = $language;
        }

        $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . cw_openai_key(),
        ));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_TIMEOUT, 900);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException('OpenAI transcription request failed: ' . $err);
        }

        $json = json_decode((string)$resp, true);
        if (!is_array($json)) {
            throw new RuntimeException('OpenAI transcription returned non-JSON. HTTP ' . $code . ' Body: ' . substr((string)$resp, 0, 800));
        }

        if ($code < 200 || $code >= 300) {
            $msg = (string)($json['error']['message'] ?? ('HTTP ' . $code));
            throw new RuntimeException('OpenAI transcription error: ' . $msg);
        }

        $text = self::cleanTranscriptText(trim((string)($json['text'] ?? '')));
        if ($text === '') {
            throw new RuntimeException('OpenAI transcription returned no text.');
        }

        return $text;
    }

    private function resetTranscriptionChunks(int $recordingId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::CHUNK_TABLE . ' WHERE recording_id = ?');
        $stmt->execute(array($recordingId));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function transcriptionChunksForRecording(int $recordingId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::CHUNK_TABLE . ' WHERE recording_id = ? ORDER BY chunk_index ASC');
        $stmt->execute(array($recordingId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    private function countTranscriptionChunksByStatus(int $recordingId, string $status): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . self::CHUNK_TABLE . ' WHERE recording_id = ? AND status = ?');
        $stmt->execute(array($recordingId, $status));
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<string,mixed>
     */
    private function finishSteppedTranscription(int $recordingId): array
    {
        $chunks = $this->transcriptionChunksForRecording($recordingId);
        $texts = array();
        $failed = array();
        foreach ($chunks as $chunk) {
            if ((string)($chunk['status'] ?? '') === 'ready') {
                $text = trim((string)($chunk['transcript_text'] ?? ''));
                if ($text !== '') {
                    $texts[] = $text;
                }
            } elseif ((string)($chunk['status'] ?? '') === 'failed') {
                $failed[] = (int)($chunk['chunk_index'] ?? 0) + 1;
            }
        }

        $combined = self::cleanTranscriptText(trim(implode("\n\n", $texts)));
        $status = $failed ? 'failed' : 'ready';
        $error = null;
        if ($failed) {
            $error = 'Partial transcript: chunk(s) ' . implode(', ', array_map('strval', $failed)) . ' failed. '
                . ($combined !== '' ? 'Partial transcript retained.' : 'No usable transcript text was returned.');
        } elseif ($combined === '') {
            $status = 'failed';
            $error = 'Chunked transcription returned no text.';
        }

        $this->pdo->prepare("
            UPDATE " . self::TABLE . "
            SET transcription_status = ?,
                transcription_progress = ?,
                transcript_text = ?,
                error_message = ?,
                transcription_completed_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute(array($status, $status === 'ready' ? 100 : 0, $combined !== '' ? $combined : null, $error, $recordingId));

        $updated = $this->recordingByAnyId((string)$recordingId);
        return array(
            'ok' => $status === 'ready',
            'done' => true,
            'recording' => $updated ? $this->publicRecordingPayload($updated) : null,
            'error' => $error,
        );
    }

    private function storeTranscriptionChunk(
        int $recordingId,
        int $index,
        float $start,
        float $end,
        string $status,
        int $textLength,
        ?string $text,
        ?string $error
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO " . self::CHUNK_TABLE . " (
                recording_id,
                chunk_index,
                start_seconds,
                end_seconds,
                status,
                text_length,
                transcript_text,
                error_message,
                created_at,
                updated_at
            ) VALUES (
                :recording_id,
                :chunk_index,
                :start_seconds,
                :end_seconds,
                :status,
                :text_length,
                :transcript_text,
                :error_message,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
            ON DUPLICATE KEY UPDATE
                start_seconds = VALUES(start_seconds),
                end_seconds = VALUES(end_seconds),
                status = VALUES(status),
                text_length = VALUES(text_length),
                transcript_text = VALUES(transcript_text),
                error_message = VALUES(error_message),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(array(
            ':recording_id' => $recordingId,
            ':chunk_index' => $index,
            ':start_seconds' => $start,
            ':end_seconds' => $end,
            ':status' => $status,
            ':text_length' => max(0, $textLength),
            ':transcript_text' => $text,
            ':error_message' => $error !== null ? substr($error, 0, 2000) : null,
        ));
    }

    private function extractAudioChunk(string $sourcePath, float $startSeconds, float $durationSeconds, string $recordingUid, int $index): string
    {
        if (!self::shellExecAvailable()) {
            throw new RuntimeException('ffmpeg chunking requires shell_exec to be available.');
        }

        $ffmpeg = self::findBinary(array('/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg', '/bin/ffmpeg', 'ffmpeg'));
        if ($ffmpeg === '') {
            throw new RuntimeException('ffmpeg is required for long recording chunked transcription.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ipca_cvr_chunk_');
        if ($tmp === false) {
            throw new RuntimeException('Could not create temporary audio chunk.');
        }
        @unlink($tmp);

        $safeUid = self::normalizeRecordingUid($recordingUid) ?: 'recording';
        $outPath = $tmp . '_' . $safeUid . '_' . $index . '.m4a';
        $cmd = escapeshellcmd($ffmpeg)
            . ' -y -v error'
            . ' -ss ' . escapeshellarg(sprintf('%.3F', max(0.0, $startSeconds)))
            . ' -t ' . escapeshellarg(sprintf('%.3F', max(1.0, $durationSeconds)))
            . ' -i ' . escapeshellarg($sourcePath)
            . ' -vn -ac 1 -ar 44100 -c:a aac -b:a 96k '
            . escapeshellarg($outPath)
            . ' 2>&1';

        $output = (string)@shell_exec($cmd);
        if (!is_file($outPath) || filesize($outPath) <= 0) {
            @unlink($outPath);
            throw new RuntimeException('Failed to extract audio chunk. ' . trim($output));
        }

        return $outPath;
    }

    /**
     * @param list<string> $candidates
     */
    private static function findBinary(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            if (str_contains($candidate, '/') && is_executable($candidate)) {
                return $candidate;
            }
            if (!str_contains($candidate, '/') && self::shellExecAvailable()) {
                $resolved = trim((string)@shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));
                if ($resolved !== '' && is_executable($resolved)) {
                    return $resolved;
                }
            }
        }
        return '';
    }

    private static function shellExecAvailable(): bool
    {
        if (!function_exists('shell_exec')) {
            return false;
        }
        $disabled = (string)ini_get('disable_functions');
        if ($disabled === '') {
            return true;
        }
        $parts = array_map('trim', explode(',', $disabled));
        return !in_array('shell_exec', $parts, true);
    }

    private static function transcriptionPrompt(): string
    {
        return "You are transcribing cockpit audio for flight training analysis.\n\n"
            . "The audio may contain ATC radio transmissions, cockpit intercom speech, aircraft noise, static, clicks, clipped transmissions, English, Dutch, and accented speech.\n"
            . "Preserve aviation phraseology, callsigns, runway numbers, headings, altitudes, frequencies, readbacks, airport names, and short radio transmissions as accurately as possible.\n"
            . "Do not invent words when unclear; mark unclear speech as [unclear] when necessary. Do not repeat a phrase to fill silence or noise.";
    }

    private static function cleanTranscriptText(string $text): string
    {
        $text = trim(preg_replace('/[ \t]+/', ' ', $text) ?? $text);
        if ($text === '') {
            return '';
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: array($text);
        if (count($sentences) < 3) {
            return $text;
        }

        $clean = array();
        $seen = array();
        $lastKey = '';
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            $key = self::transcriptRepeatKey($sentence);
            if ($key !== '' && strlen($key) > 30) {
                $count = (int)($seen[$key] ?? 0);
                $seen[$key] = $count + 1;
                if ($key === $lastKey || $count >= 1) {
                    continue;
                }
                $lastKey = $key;
            } else {
                $lastKey = '';
            }

            $clean[] = $sentence;
        }

        $cleaned = trim(implode(' ', $clean));
        return $cleaned !== '' ? $cleaned : $text;
    }

    private static function transcriptRepeatKey(string $sentence): string
    {
        $key = strtolower($sentence);
        $key = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $key) ?? $key;
        return trim(preg_replace('/\s+/', ' ', $key) ?? $key);
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

    private static function relativeAHRSPath(string $recordingUid): string
    {
        $safeUid = self::normalizeRecordingUid($recordingUid) ?: bin2hex(random_bytes(8));
        return 'storage/cockpit_recorder/ahrs/' . gmdate('Y/m/d') . '/' . $safeUid . '.ahrs.json';
    }

    private static function relativeGPSPath(string $recordingUid): string
    {
        $safeUid = self::normalizeRecordingUid($recordingUid) ?: bin2hex(random_bytes(8));
        return 'storage/cockpit_recorder/gps/' . gmdate('Y/m/d') . '/' . $safeUid . '.gps.json';
    }

    private static function relativeG3XPath(string $recordingUid): string
    {
        $safeUid = self::normalizeRecordingUid($recordingUid) ?: bin2hex(random_bytes(8));
        return 'storage/cockpit_recorder/g3x/' . gmdate('Y/m/d') . '/' . $safeUid . '.g3x.csv';
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
