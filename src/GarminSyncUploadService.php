<?php
declare(strict_types=1);

final class GarminSyncUploadException extends RuntimeException
{
    public function __construct(
        string $message,
        private string $stableErrorCode,
        private bool $retryable = false,
        private int $httpStatus = 400
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->stableErrorCode;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

/**
 * Isolated Phase 1 Garmin Sync binary archive.
 *
 * This service deliberately has no dependency on Garmin parsing, CVR storage, or
 * downstream analysis. Content deduplication is global by SHA-256, while every
 * lookup and receipt is authorized through an organization/device-owned upload.
 */
final class GarminSyncUploadService
{
    private string $storageRoot;

    public function __construct(private PDO $pdo, ?string $storageRoot = null)
    {
        $this->storageRoot = rtrim(
            $storageRoot ?? dirname(__DIR__) . '/storage/garmin_sync',
            DIRECTORY_SEPARATOR
        );
    }

    /**
     * @param array<string,mixed> $device
     * @param array<string,mixed> $file
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public function receiveChunk(array $device, array $file, array $meta): array
    {
        $owner = $this->owner($device);
        $uploadUuid = $this->requireUuid((string)($meta['upload_uuid'] ?? ''), 'upload_uuid');
        $requestUuid = trim((string)($meta['request_uuid'] ?? $uploadUuid));
        $expectedSha256 = $this->requireSha256((string)($meta['expected_sha256'] ?? ''));
        $expectedBytes = $this->positiveInt($meta['expected_byte_count'] ?? 0, 'expected_byte_count');
        $totalChunks = $this->positiveInt($meta['total_chunks'] ?? 0, 'total_chunks');
        $chunkIndex = filter_var($meta['chunk_index'] ?? null, FILTER_VALIDATE_INT);
        if ($chunkIndex === false || $chunkIndex < 0 || $chunkIndex >= $totalChunks) {
            throw new GarminSyncUploadException('chunk_index is outside the declared range.', 'INVALID_CHUNK_INDEX');
        }
        if ($requestUuid === '' || strlen($requestUuid) > 128) {
            throw new GarminSyncUploadException('request_uuid is required and must not exceed 128 characters.', 'INVALID_REQUEST_ID');
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_OK);
        if ($uploadError !== UPLOAD_ERR_OK || $tmpPath === '' || !is_file($tmpPath)) {
            throw new GarminSyncUploadException('Multipart chunk is missing or failed to upload.', 'CHUNK_UPLOAD_MISSING', true);
        }
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmpPath)) {
            throw new GarminSyncUploadException('Chunk did not originate from an HTTP upload.', 'INVALID_UPLOAD_SOURCE');
        }

        $session = $this->ensureSession(
            $owner,
            $uploadUuid,
            $requestUuid,
            $expectedSha256,
            $expectedBytes,
            $totalChunks,
            (string)($meta['original_filename'] ?? ($file['name'] ?? ''))
        );
        $this->assertSessionMetadata($session, $expectedSha256, $expectedBytes, $totalChunks);
        if (in_array((string)$session['status'], array('verified', 'duplicate'), true)) {
            return $this->receiptResponse($session, true);
        }

        try {
            $sessionDir = $this->sessionDirectory($uploadUuid);
            $this->ensureDirectory($sessionDir);
            $storageName = str_pad((string)$chunkIndex, 10, '0', STR_PAD_LEFT) . '.part';
            $destination = $sessionDir . '/' . $storageName;
            $incomingHash = hash_file('sha256', $tmpPath);
            $incomingBytes = filesize($tmpPath);
            if (!is_string($incomingHash) || $incomingBytes === false) {
                throw new GarminSyncUploadException('Could not inspect the uploaded chunk.', 'CHUNK_READ_FAILED', true, 500);
            }

            $existing = $this->chunk((int)$session['id'], $chunkIndex);
            if ($existing !== null) {
                if (
                    hash_equals((string)$existing['chunk_sha256'], $incomingHash)
                    && (int)$existing['byte_count'] === (int)$incomingBytes
                ) {
                    return $this->resumeResponse($session);
                }
                throw new GarminSyncUploadException(
                    'A different payload already exists at this chunk index.',
                    'CHUNK_CONFLICT',
                    false,
                    409
                );
            }

            $staging = $destination . '.incoming-' . bin2hex(random_bytes(6));
            $moved = is_uploaded_file($tmpPath)
                ? move_uploaded_file($tmpPath, $staging)
                : rename($tmpPath, $staging);
            if (!$moved || !rename($staging, $destination)) {
                @unlink($staging);
                throw new GarminSyncUploadException('Could not persist the uploaded chunk.', 'CHUNK_STORE_FAILED', true, 500);
            }

            try {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO ipca_garmin_sync_upload_chunks
                      (upload_session_id, chunk_index, byte_count, chunk_sha256, storage_name)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute(array((int)$session['id'], $chunkIndex, (int)$incomingBytes, $incomingHash, $storageName));
            } catch (Throwable $e) {
                @unlink($destination);
                throw $e;
            }
            $this->refreshReceivedState((int)$session['id']);
            $fresh = $this->sessionByUpload($uploadUuid, $owner);
            return $this->resumeResponse($fresh ?? $session);
        } catch (GarminSyncUploadException $e) {
            $this->recordError((int)$session['id'], $e);
            throw $e;
        } catch (Throwable $e) {
            $wrapped = new GarminSyncUploadException('Chunk persistence failed.', 'CHUNK_STORE_FAILED', true, 500);
            $this->recordError((int)$session['id'], $wrapped);
            throw $wrapped;
        }
    }

    /**
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    public function resume(array $device, string $uploadUuid): array
    {
        $owner = $this->owner($device);
        $uploadUuid = $this->requireUuid($uploadUuid, 'upload_uuid');
        $session = $this->sessionByUpload($uploadUuid, $owner);
        if ($session === null) {
            throw new GarminSyncUploadException('Upload session was not found.', 'UPLOAD_NOT_FOUND', false, 404);
        }
        return $this->resumeResponse($session);
    }

    /**
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    public function finalize(array $device, string $uploadUuid): array
    {
        $owner = $this->owner($device);
        $uploadUuid = $this->requireUuid($uploadUuid, 'upload_uuid');
        $session = $this->sessionByUpload($uploadUuid, $owner);
        if ($session === null) {
            throw new GarminSyncUploadException('Upload session was not found.', 'UPLOAD_NOT_FOUND', false, 404);
        }
        if (in_array((string)$session['status'], array('verified', 'duplicate'), true)) {
            return $this->receiptResponse($session, true);
        }

        try {
            $chunks = $this->chunks((int)$session['id']);
            if (count($chunks) !== (int)$session['total_chunks']) {
                throw new GarminSyncUploadException('Not all chunks have been received.', 'UPLOAD_INCOMPLETE', true, 409);
            }

            $sessionDir = $this->sessionDirectory($uploadUuid);
            $assembledPath = $sessionDir . '/assembled.tmp';
            @unlink($assembledPath . '.new');
            $out = fopen($assembledPath . '.new', 'x+b');
            if ($out === false) {
                throw new GarminSyncUploadException('Could not start assembly.', 'ASSEMBLY_OPEN_FAILED', true, 500);
            }
            $hash = hash_init('sha256');
            $assembledBytes = 0;
            try {
                foreach ($chunks as $expectedIndex => $chunk) {
                    if ((int)$chunk['chunk_index'] !== $expectedIndex) {
                        throw new GarminSyncUploadException('Chunk sequence has a gap.', 'UPLOAD_INCOMPLETE', true, 409);
                    }
                    $path = $sessionDir . '/' . basename((string)$chunk['storage_name']);
                    $in = fopen($path, 'rb');
                    if ($in === false) {
                        throw new GarminSyncUploadException('A received chunk is unavailable.', 'CHUNK_READ_FAILED', true, 500);
                    }
                    while (!feof($in)) {
                        $buffer = fread($in, 1024 * 1024);
                        if ($buffer === false) {
                            fclose($in);
                            throw new GarminSyncUploadException('A received chunk could not be read.', 'CHUNK_READ_FAILED', true, 500);
                        }
                        if ($buffer !== '') {
                            $written = fwrite($out, $buffer);
                            if ($written === false || $written !== strlen($buffer)) {
                                fclose($in);
                                throw new GarminSyncUploadException('Assembly write failed.', 'ASSEMBLY_WRITE_FAILED', true, 500);
                            }
                            hash_update($hash, $buffer);
                            $assembledBytes += $written;
                        }
                    }
                    fclose($in);
                }
            } finally {
                fclose($out);
            }
            if (!rename($assembledPath . '.new', $assembledPath)) {
                @unlink($assembledPath . '.new');
                throw new GarminSyncUploadException('Could not complete assembly.', 'ASSEMBLY_WRITE_FAILED', true, 500);
            }

            $actualSha256 = hash_final($hash);
            if ($assembledBytes !== (int)$session['expected_byte_count']) {
                @unlink($assembledPath);
                throw new GarminSyncUploadException('Assembled byte count does not match the declaration.', 'BYTE_COUNT_MISMATCH', false, 422);
            }
            if (!hash_equals((string)$session['expected_sha256'], $actualSha256)) {
                @unlink($assembledPath);
                throw new GarminSyncUploadException('Assembled SHA-256 does not match the declaration.', 'SHA256_MISMATCH', false, 422);
            }

            $archive = $this->archiveByHash($actualSha256);
            $duplicate = $archive !== null;
            if ($archive === null) {
                $archive = $this->persistArchive($session, $assembledPath, $actualSha256, $assembledBytes);
            } else {
                @unlink($assembledPath);
                if ((int)$archive['byte_count'] !== $assembledBytes || !is_file($this->projectPath((string)$archive['storage_path']))) {
                    throw new GarminSyncUploadException('Existing archive object failed an integrity check.', 'ARCHIVE_INTEGRITY_ERROR', false, 500);
                }
            }

            $receipt = array(
                'receipt_uuid' => $this->uuid(),
                'object_id' => (string)$archive['object_uuid'],
                'sha256' => $actualSha256,
                'byte_count' => $assembledBytes,
                'verified' => true,
                'duplicate' => $duplicate,
                'verified_at' => gmdate('c'),
            );
            $stmt = $this->pdo->prepare(
                "UPDATE ipca_garmin_sync_upload_sessions
                 SET status = ?, archive_file_id = ?, receipt_uuid = ?, receipt_json = ?,
                     finalized_at = CURRENT_TIMESTAMP, last_error_code = NULL,
                     last_error_message = NULL, last_error_retryable = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $stmt->execute(array(
                $duplicate ? 'duplicate' : 'verified',
                (int)$archive['id'],
                $receipt['receipt_uuid'],
                $this->json($receipt),
                (int)$session['id'],
            ));
            $this->removeSessionFiles($sessionDir, $chunks);
            return array('ok' => true, 'status' => $duplicate ? 'duplicate' : 'verified', 'receipt' => $receipt);
        } catch (GarminSyncUploadException $e) {
            $this->recordError((int)$session['id'], $e);
            throw $e;
        } catch (Throwable $e) {
            $wrapped = new GarminSyncUploadException('Finalization failed.', 'FINALIZE_FAILED', true, 500);
            $this->recordError((int)$session['id'], $wrapped);
            throw $wrapped;
        }
    }

    /**
     * Hashes are disclosed only when the authenticated organization already has
     * a verified upload receipt for that content. The archive's global dedupe key
     * never creates a cross-organization hash-existence oracle.
     *
     * @param array<string,mixed> $device
     * @param list<mixed> $sha256List
     * @return array<string,mixed>
     */
    public function knownHashes(array $device, array $sha256List): array
    {
        $owner = $this->owner($device);
        $hashes = array();
        foreach ($sha256List as $value) {
            $hash = strtolower(trim((string)$value));
            if (preg_match('/^[a-f0-9]{64}$/', $hash) === 1) {
                $hashes[$hash] = true;
            }
        }
        $known = array();
        if ($hashes !== array()) {
            $placeholders = implode(',', array_fill(0, count($hashes), '?'));
            $params = array_merge(array($owner['organization_id']), array_keys($hashes));
            $stmt = $this->pdo->prepare(
                "SELECT DISTINCT a.object_uuid, a.sha256, a.byte_count
                 FROM ipca_garmin_sync_archive_files a
                 INNER JOIN ipca_garmin_sync_upload_sessions u ON u.archive_file_id = a.id
                 WHERE u.organization_id = ? AND u.status IN ('verified', 'duplicate')
                   AND a.sha256 IN ({$placeholders})"
            );
            $stmt->execute($params);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $known[(string)$row['sha256']] = array(
                    'sha256' => (string)$row['sha256'],
                    'object_id' => (string)$row['object_uuid'],
                    'byte_count' => (int)$row['byte_count'],
                    'verified' => true,
                );
            }
        }
        $unknown = array_values(array_diff(array_keys($hashes), array_keys($known)));
        return array('ok' => true, 'known' => array_values($known), 'unknown' => $unknown);
    }

    /**
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    public function status(array $device, string $uploadUuid): array
    {
        $owner = $this->owner($device);
        $uploadUuid = $this->requireUuid($uploadUuid, 'upload_uuid');
        $session = $this->sessionByUpload($uploadUuid, $owner);
        if ($session === null) {
            throw new GarminSyncUploadException('Upload session was not found.', 'UPLOAD_NOT_FOUND', false, 404);
        }
        $response = $this->resumeResponse($session);
        $response['request_uuid'] = (string)$session['request_uuid'];
        $response['expected_sha256'] = (string)$session['expected_sha256'];
        $response['expected_byte_count'] = (int)$session['expected_byte_count'];
        $response['retry_count'] = (int)$session['retry_count'];
        $response['last_error'] = $session['last_error_code'] === null ? null : array(
            'error_code' => (string)$session['last_error_code'],
            'message' => (string)$session['last_error_message'],
            'retryable' => (bool)$session['last_error_retryable'],
        );
        return $response;
    }

    /** @param array<string,int> $owner */
    private function ensureSession(
        array $owner,
        string $uploadUuid,
        string $requestUuid,
        string $expectedSha256,
        int $expectedBytes,
        int $totalChunks,
        string $filename
    ): array {
        $session = $this->sessionByUpload($uploadUuid, $owner);
        if ($session !== null) {
            return $session;
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO ipca_garmin_sync_upload_sessions
              (upload_uuid, request_uuid, organization_id, device_id, expected_sha256,
               expected_byte_count, total_chunks, received_chunks_json, original_filename, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'receiving')"
        );
        try {
            $stmt->execute(array(
                $uploadUuid,
                $requestUuid,
                $owner['organization_id'],
                $owner['device_id'],
                $expectedSha256,
                $expectedBytes,
                $totalChunks,
                '[]',
                substr(basename($filename), 0, 512),
            ));
        } catch (Throwable $e) {
            $session = $this->sessionByUpload($uploadUuid, $owner);
            if ($session === null) {
                throw new GarminSyncUploadException('Could not create upload session.', 'UPLOAD_CREATE_FAILED', true, 500);
            }
            return $session;
        }
        $session = $this->sessionByUpload($uploadUuid, $owner);
        if ($session === null) {
            throw new GarminSyncUploadException('Could not load upload session.', 'UPLOAD_CREATE_FAILED', true, 500);
        }
        return $session;
    }

    private function assertSessionMetadata(array $session, string $sha256, int $byteCount, int $totalChunks): void
    {
        if (
            !hash_equals((string)$session['expected_sha256'], $sha256)
            || (int)$session['expected_byte_count'] !== $byteCount
            || (int)$session['total_chunks'] !== $totalChunks
        ) {
            throw new GarminSyncUploadException(
                'Upload metadata conflicts with the existing session.',
                'UPLOAD_METADATA_CONFLICT',
                false,
                409
            );
        }
    }

    /** @param array<string,int> $owner */
    private function sessionByUpload(string $uploadUuid, array $owner): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_garmin_sync_upload_sessions
             WHERE upload_uuid = ? AND organization_id = ? AND device_id = ? LIMIT 1'
        );
        $stmt->execute(array($uploadUuid, $owner['organization_id'], $owner['device_id']));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function chunk(int $sessionId, int $chunkIndex): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_garmin_sync_upload_chunks WHERE upload_session_id = ? AND chunk_index = ? LIMIT 1'
        );
        $stmt->execute(array($sessionId, $chunkIndex));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function chunks(int $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_garmin_sync_upload_chunks WHERE upload_session_id = ? ORDER BY chunk_index ASC'
        );
        $stmt->execute(array($sessionId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function refreshReceivedState(int $sessionId): void
    {
        $chunks = $this->chunks($sessionId);
        $indexes = array_map(static fn(array $row): int => (int)$row['chunk_index'], $chunks);
        $bytes = array_sum(array_map(static fn(array $row): int => (int)$row['byte_count'], $chunks));
        $stmt = $this->pdo->prepare(
            "UPDATE ipca_garmin_sync_upload_sessions
             SET received_chunks_json = ?, received_byte_count = ?, status = 'receiving',
                 last_error_code = NULL, last_error_message = NULL, last_error_retryable = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmt->execute(array($this->json($indexes), $bytes, $sessionId));
    }

    private function archiveByHash(string $sha256): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_garmin_sync_archive_files WHERE sha256 = ? LIMIT 1');
        $stmt->execute(array($sha256));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function persistArchive(array $session, string $assembledPath, string $sha256, int $byteCount): array
    {
        $relative = 'storage/garmin_sync/archive/' . gmdate('Y/m/d') . '/' . $sha256 . '.bin';
        $absolute = $this->projectPath($relative);
        $this->ensureDirectory(dirname($absolute));

        if (!is_file($absolute)) {
            $source = fopen($assembledPath, 'rb');
            $destination = fopen($absolute, 'x+b');
            if ($source === false || $destination === false) {
                if (is_resource($source)) {
                    fclose($source);
                }
                if (is_resource($destination)) {
                    fclose($destination);
                }
                throw new GarminSyncUploadException('Could not open the append-only archive target.', 'ARCHIVE_WRITE_FAILED', true, 500);
            }
            $copied = stream_copy_to_stream($source, $destination);
            fflush($destination);
            fclose($source);
            fclose($destination);
            if ($copied !== $byteCount) {
                @unlink($absolute);
                throw new GarminSyncUploadException('Archive copy was incomplete.', 'ARCHIVE_WRITE_FAILED', true, 500);
            }
        }
        @unlink($assembledPath);

        $existingHash = hash_file('sha256', $absolute);
        $existingBytes = filesize($absolute);
        if (!is_string($existingHash) || !hash_equals($sha256, $existingHash) || $existingBytes !== $byteCount) {
            throw new GarminSyncUploadException('Archive target content did not verify.', 'ARCHIVE_INTEGRITY_ERROR', false, 500);
        }

        $objectUuid = $this->uuid();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ipca_garmin_sync_archive_files
                  (object_uuid, sha256, byte_count, storage_path, original_filename,
                   creator_organization_id, creator_device_id, verified_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
            );
            $stmt->execute(array(
                $objectUuid,
                $sha256,
                $byteCount,
                $relative,
                (string)$session['original_filename'],
                (int)$session['organization_id'],
                (int)$session['device_id'],
            ));
        } catch (Throwable $e) {
            $raceWinner = $this->archiveByHash($sha256);
            if ($raceWinner === null) {
                throw new GarminSyncUploadException('Could not register the archive object.', 'ARCHIVE_REGISTER_FAILED', true, 500);
            }
            return $raceWinner;
        }
        $archive = $this->archiveByHash($sha256);
        if ($archive === null) {
            throw new GarminSyncUploadException('Could not load the archive object.', 'ARCHIVE_REGISTER_FAILED', true, 500);
        }
        return $archive;
    }

    private function receiptResponse(array $session, bool $idempotent): array
    {
        $receipt = json_decode((string)($session['receipt_json'] ?? ''), true);
        return array(
            'ok' => true,
            'status' => (string)$session['status'],
            'idempotent' => $idempotent,
            'receipt' => is_array($receipt) ? $receipt : null,
        );
    }

    private function resumeResponse(array $session): array
    {
        $received = json_decode((string)($session['received_chunks_json'] ?? '[]'), true);
        return array(
            'ok' => true,
            'upload_uuid' => (string)$session['upload_uuid'],
            'status' => (string)$session['status'],
            'received_chunks' => is_array($received) ? array_values($received) : array(),
            'received_byte_count' => (int)$session['received_byte_count'],
            'total_chunks' => (int)$session['total_chunks'],
            'complete' => count(is_array($received) ? $received : array()) === (int)$session['total_chunks'],
            'receipt' => isset($session['receipt_json']) && $session['receipt_json'] !== null
                ? json_decode((string)$session['receipt_json'], true)
                : null,
        );
    }

    private function recordError(int $sessionId, GarminSyncUploadException $error): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE ipca_garmin_sync_upload_sessions
                 SET status = 'error', retry_count = retry_count + 1, last_error_code = ?,
                     last_error_message = ?, last_error_retryable = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $stmt->execute(array(
                $error->errorCode(),
                substr($error->getMessage(), 0, 2048),
                $error->retryable() ? 1 : 0,
                $sessionId,
            ));
        } catch (Throwable $ignored) {
            // Preserve the original upload error if status persistence also fails.
        }
    }

    /** @param array<string,mixed> $device @return array{organization_id:int,device_id:int} */
    private function owner(array $device): array
    {
        $deviceId = (int)($device['id'] ?? 0);
        $organizationId = (int)($device['organization_id'] ?? 0);
        if ($deviceId <= 0 || $organizationId <= 0) {
            throw new GarminSyncUploadException('Authenticated device ownership is incomplete.', 'DEVICE_SCOPE_INVALID', false, 403);
        }
        return array('organization_id' => $organizationId, 'device_id' => $deviceId);
    }

    private function requireUuid(string $value, string $field): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value) !== 1) {
            throw new GarminSyncUploadException("{$field} must be a valid UUID.", 'INVALID_UPLOAD_ID');
        }
        return $value;
    }

    private function requireSha256(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new GarminSyncUploadException('expected_sha256 must be a lowercase or uppercase SHA-256 hex digest.', 'INVALID_SHA256');
        }
        return $value;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false || $int <= 0) {
            throw new GarminSyncUploadException("{$field} must be a positive integer.", 'INVALID_UPLOAD_METADATA');
        }
        return $int;
    }

    private function sessionDirectory(string $uploadUuid): string
    {
        return $this->storageRoot . '/upload_sessions/' . $uploadUuid;
    }

    private function projectPath(string $relative): string
    {
        if (str_starts_with($relative, 'storage/garmin_sync/')) {
            return $this->storageRoot . substr($relative, strlen('storage/garmin_sync'));
        }
        throw new GarminSyncUploadException('Archive path escaped isolated storage.', 'ARCHIVE_PATH_INVALID', false, 500);
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) {
            throw new GarminSyncUploadException('Could not create isolated storage directory.', 'STORAGE_UNAVAILABLE', true, 500);
        }
    }

    /** @param list<array<string,mixed>> $chunks */
    private function removeSessionFiles(string $directory, array $chunks): void
    {
        foreach ($chunks as $chunk) {
            @unlink($directory . '/' . basename((string)$chunk['storage_name']));
        }
        @unlink($directory . '/assembled.tmp');
        @rmdir($directory);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function json(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new GarminSyncUploadException('Could not encode upload state.', 'STATE_ENCODING_FAILED', true, 500);
        }
        return $encoded;
    }
}
