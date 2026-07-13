<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/AsyncJobService.php';
require_once __DIR__ . '/FlightSessionService.php';
require_once __DIR__ . '/GarminCsvFingerprintService.php';
require_once __DIR__ . '/GarminCsvValidationService.php';
require_once __DIR__ . '/GarminCsvSessionMatchService.php';

final class GarminCsvEvidenceService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    public function receiveChunk(array $device, array $file, array $meta): array
    {
        $uploadUuid = $this->sanitizeUuid((string)($meta['upload_uuid'] ?? $meta['upload_id'] ?? ''));
        if ($uploadUuid === '') {
            throw new RuntimeException('CSV upload UUID is required.');
        }
        $chunkIndex = (int)($meta['chunk_index'] ?? -1);
        $totalChunks = (int)($meta['total_chunks'] ?? 0);
        $totalSize = (int)($meta['total_size'] ?? 0);
        if ($chunkIndex < 0 || $totalChunks <= 0 || $chunkIndex >= $totalChunks) {
            throw new RuntimeException('Invalid CSV chunk metadata.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('CSV chunk file is missing.');
        }

        $session = (new FlightSessionService($this->pdo))->sessionForDevice($device, (string)($meta['session_uuid'] ?? ''));
        $request = $this->ensureUploadRequest($device, $session, $uploadUuid, (string)($meta['request_uuid'] ?? $uploadUuid), $totalChunks, $totalSize, (string)($meta['original_filename'] ?? ''));
        $dir = $this->uploadSessionDir($uploadUuid);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create CSV upload session directory.');
        }
        $part = $dir . '/' . str_pad((string)$chunkIndex, 8, '0', STR_PAD_LEFT) . '.part';
        if (!move_uploaded_file($tmp, $part)) {
            throw new RuntimeException('Could not store CSV chunk.');
        }
        $received = $this->receivedChunkIndexes($dir);
        $this->pdo->prepare("
            UPDATE ipca_garmin_csv_upload_requests
            SET received_chunks_json = ?, status = 'receiving', updated_at = CURRENT_TIMESTAMP(3)
            WHERE id = ?
        ")->execute(array(AuditEventService::jsonEncode($received), (int)$request['id']));

        return array(
            'ok' => true,
            'upload_uuid' => $uploadUuid,
            'session_uuid' => $session['session_uuid'] ?? null,
            'received_chunks' => $received,
            'complete' => count($received) >= $totalChunks,
        );
    }

    /**
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    public function finalize(array $device, string $uploadUuid): array
    {
        $uploadUuid = $this->sanitizeUuid($uploadUuid);
        if ($uploadUuid === '') {
            throw new RuntimeException('CSV upload UUID is required.');
        }
        $request = $this->uploadRequest($uploadUuid);
        if ($request === null) {
            throw new RuntimeException('CSV upload request was not found.');
        }
        if ((int)($request['device_id'] ?? 0) !== (int)$device['id']) {
            throw new RuntimeException('Device cannot finalize another device upload.');
        }
        $assembled = $this->assemble($request);
        $fingerprint = (new GarminCsvFingerprintService())->fingerprint($assembled, (string)($request['original_filename'] ?? ''));
        $duplicate = $this->csvBySha((string)$fingerprint['sha256']);
        if ($duplicate !== null) {
            $this->pdo->prepare("
                UPDATE ipca_garmin_csv_upload_requests
                SET status = 'duplicate', assembled_path = ?, updated_at = CURRENT_TIMESTAMP(3)
                WHERE id = ?
            ")->execute(array($assembled, (int)$request['id']));
            return array('ok' => true, 'status' => 'duplicate', 'csv_file_uuid' => $duplicate['csv_file_uuid'], 'sha256' => $fingerprint['sha256']);
        }

        $storagePath = $this->persistCsv($assembled, (string)$fingerprint['sha256'], (string)($request['original_filename'] ?? 'garmin.csv'));
        $csvFileId = $this->insertCsvFile($request, $device, $fingerprint, $storagePath);
        $this->insertFingerprint($csvFileId, $fingerprint);
        $validation = (new GarminCsvValidationService($this->pdo))->validateFile($csvFileId, $storagePath);
        $csvFile = $this->csvById($csvFileId);
        $match = $csvFile !== null ? (new GarminCsvSessionMatchService($this->pdo))->match($csvFile) : array();
        $this->classifySupersession($csvFileId, $fingerprint);
        $this->enqueueJobs($csvFileId);

        $this->pdo->prepare("
            UPDATE ipca_garmin_csv_upload_requests
            SET status = 'finalized', assembled_path = ?, updated_at = CURRENT_TIMESTAMP(3)
            WHERE id = ?
        ")->execute(array($storagePath, (int)$request['id']));

        return array(
            'ok' => true,
            'status' => 'finalized',
            'csv_file_uuid' => $csvFile['csv_file_uuid'] ?? null,
            'sha256' => $fingerprint['sha256'],
            'validation' => $validation,
            'match' => $match,
        );
    }

    /**
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    public function status(array $device, string $uploadUuid = '', string $csvFileUuid = ''): array
    {
        if ($csvFileUuid !== '') {
            $stmt = $this->pdo->prepare("
                SELECT f.*, v.status AS validation_status, v.severity AS validation_severity, m.match_status, m.confidence
                FROM ipca_garmin_csv_files f
                LEFT JOIN ipca_garmin_csv_validation_results v ON v.csv_file_id = f.id
                LEFT JOIN ipca_garmin_csv_session_matches m ON m.csv_file_id = f.id
                WHERE f.csv_file_uuid = ? AND (f.device_id IS NULL OR f.device_id = ?)
                ORDER BY v.id DESC, m.id DESC
                LIMIT 1
            ");
            $stmt->execute(array($csvFileUuid, (int)$device['id']));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return array('ok' => true, 'csv_file' => is_array($row) ? $row : null);
        }
        $request = $uploadUuid !== '' ? $this->uploadRequest($uploadUuid) : null;
        return array('ok' => true, 'upload_request' => $request);
    }

    /**
     * @return list<int>
     */
    public function receivedChunks(string $uploadUuid): array
    {
        return $this->receivedChunkIndexes($this->uploadSessionDir($this->sanitizeUuid($uploadUuid)));
    }

    /**
     * @param array<string,mixed> $device
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    private function ensureUploadRequest(array $device, array $session, string $uploadUuid, string $requestUuid, int $totalChunks, int $totalSize, string $originalFilename): array
    {
        $existing = $this->uploadRequest($uploadUuid);
        if ($existing !== null) {
            if ((int)$existing['device_id'] !== (int)$device['id']) {
                throw new RuntimeException('Upload UUID already belongs to a different device.');
            }
            return $existing;
        }
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_csv_upload_requests
              (upload_uuid, request_uuid, organization_id, device_id, session_id, original_filename, total_chunks, total_size_bytes)
            VALUES
              (:upload_uuid, :request_uuid, :organization_id, :device_id, :session_id, :original_filename, :total_chunks, :total_size_bytes)
        ");
        $stmt->execute(array(
            ':upload_uuid' => $uploadUuid,
            ':request_uuid' => $this->sanitizeUuid($requestUuid) ?: AuditEventService::uuid(),
            ':organization_id' => (int)($device['organization_id'] ?? 1),
            ':device_id' => (int)$device['id'],
            ':session_id' => (int)($session['id'] ?? 0),
            ':original_filename' => substr($originalFilename, 0, 255),
            ':total_chunks' => $totalChunks,
            ':total_size_bytes' => max(0, $totalSize),
        ));
        return $this->uploadRequest($uploadUuid) ?? array();
    }

    /**
     * @param array<string,mixed> $request
     */
    private function assemble(array $request): string
    {
        $dir = $this->uploadSessionDir((string)$request['upload_uuid']);
        $totalChunks = (int)($request['total_chunks'] ?? 0);
        if ($totalChunks <= 0) {
            throw new RuntimeException('CSV upload has invalid metadata.');
        }
        $assembledDir = $dir . '/assembled';
        if (!is_dir($assembledDir) && !mkdir($assembledDir, 0775, true) && !is_dir($assembledDir)) {
            throw new RuntimeException('Could not create CSV assembly directory.');
        }
        $assembled = $assembledDir . '/garmin.csv';
        $out = fopen($assembled, 'wb');
        if ($out === false) {
            throw new RuntimeException('Could not assemble CSV upload.');
        }
        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $part = $dir . '/' . str_pad((string)$i, 8, '0', STR_PAD_LEFT) . '.part';
                if (!is_file($part)) {
                    throw new RuntimeException('Missing CSV chunk ' . $i . '.');
                }
                $in = fopen($part, 'rb');
                if ($in === false) {
                    throw new RuntimeException('Could not read CSV chunk ' . $i . '.');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }
        $expected = (int)($request['total_size_bytes'] ?? 0);
        if ($expected > 0 && filesize($assembled) !== $expected) {
            throw new RuntimeException('Assembled CSV size mismatch.');
        }
        return $assembled;
    }

    /**
     * @param array<string,mixed> $request
     * @param array<string,mixed> $device
     * @param array<string,mixed> $fingerprint
     */
    private function insertCsvFile(array $request, array $device, array $fingerprint, string $storagePath): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_csv_files
              (csv_file_uuid, upload_request_id, session_id, device_id, aircraft_id, aircraft_registration,
               original_filename, storage_path, sha256, file_size_bytes, import_profile, aircraft_ident,
               product, system_identifier, airframe_hours_start, engine_hours_start, first_valid_sample_utc,
               last_valid_sample_utc, valid_row_count)
            VALUES
              (:csv_file_uuid, :upload_request_id, :session_id, :device_id, :aircraft_id, :aircraft_registration,
               :original_filename, :storage_path, :sha256, :file_size_bytes, :import_profile, :aircraft_ident,
               :product, :system_identifier, :airframe_hours_start, :engine_hours_start, :first_valid_sample_utc,
               :last_valid_sample_utc, :valid_row_count)
        ");
        $stmt->execute(array(
            ':csv_file_uuid' => AuditEventService::uuid(),
            ':upload_request_id' => (int)$request['id'],
            ':session_id' => $request['session_id'] ?? null,
            ':device_id' => (int)$device['id'],
            ':aircraft_id' => $device['aircraft_id'] ?? null,
            ':aircraft_registration' => (string)($device['aircraft_registration'] ?? ''),
            ':original_filename' => (string)($request['original_filename'] ?? ''),
            ':storage_path' => $storagePath,
            ':sha256' => (string)$fingerprint['sha256'],
            ':file_size_bytes' => (int)$fingerprint['file_size_bytes'],
            ':import_profile' => (string)$fingerprint['import_profile'],
            ':aircraft_ident' => (string)$fingerprint['aircraft_ident'],
            ':product' => (string)$fingerprint['product'],
            ':system_identifier' => (string)$fingerprint['system_identifier'],
            ':airframe_hours_start' => $fingerprint['airframe_hours_start'],
            ':engine_hours_start' => $fingerprint['engine_hours_start'],
            ':first_valid_sample_utc' => $fingerprint['first_valid_sample_utc'],
            ':last_valid_sample_utc' => $fingerprint['last_valid_sample_utc'],
            ':valid_row_count' => (int)$fingerprint['valid_row_count'],
        ));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $fingerprint
     */
    private function insertFingerprint(int $csvFileId, array $fingerprint): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_csv_fingerprints
              (csv_file_id, fingerprint_uuid, parser_version, normalized_header_hash, first_rows_hash,
               last_rows_hash, gps_path_summary_hash, utc_duration_ms, source_filename, fingerprint_json)
            VALUES
              (:csv_file_id, :fingerprint_uuid, :parser_version, :normalized_header_hash, :first_rows_hash,
               :last_rows_hash, :gps_path_summary_hash, :utc_duration_ms, :source_filename, :fingerprint_json)
        ");
        $stmt->execute(array(
            ':csv_file_id' => $csvFileId,
            ':fingerprint_uuid' => AuditEventService::uuid(),
            ':parser_version' => (string)$fingerprint['parser_version'],
            ':normalized_header_hash' => (string)$fingerprint['normalized_header_hash'],
            ':first_rows_hash' => (string)$fingerprint['first_rows_hash'],
            ':last_rows_hash' => (string)$fingerprint['last_rows_hash'],
            ':gps_path_summary_hash' => (string)$fingerprint['gps_path_summary_hash'],
            ':utc_duration_ms' => $fingerprint['utc_duration_ms'],
            ':source_filename' => (string)$fingerprint['source_filename'],
            ':fingerprint_json' => AuditEventService::jsonEncode($fingerprint['fingerprint_json']),
        ));
    }

    /**
     * @param array<string,mixed> $fingerprint
     */
    private function classifySupersession(int $csvFileId, array $fingerprint): void
    {
        $stmt = $this->pdo->prepare("
            SELECT f.id
            FROM ipca_garmin_csv_files f
            INNER JOIN ipca_garmin_csv_fingerprints fp ON fp.csv_file_id = f.id
            WHERE f.id <> ?
              AND fp.normalized_header_hash = ?
              AND (fp.first_rows_hash = ? OR fp.last_rows_hash = ? OR fp.gps_path_summary_hash = ?)
            ORDER BY f.created_at DESC
            LIMIT 20
        ");
        $stmt->execute(array(
            $csvFileId,
            (string)$fingerprint['normalized_header_hash'],
            (string)$fingerprint['first_rows_hash'],
            (string)$fingerprint['last_rows_hash'],
            (string)$fingerprint['gps_path_summary_hash'],
        ));
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: array() as $otherId) {
            $this->pdo->prepare("
                INSERT IGNORE INTO ipca_garmin_csv_supersession_links
                  (supersession_uuid, superseding_csv_file_id, superseded_csv_file_id, classification, confidence, comparison_json)
                VALUES
                  (?, ?, ?, 'compatible_overlap', 0.7500, ?)
            ")->execute(array(
                AuditEventService::uuid(),
                $csvFileId,
                (int)$otherId,
                AuditEventService::jsonEncode(array('method' => 'phase1_fingerprint_overlap')),
            ));
        }
    }

    private function enqueueJobs(int $csvFileId): void
    {
        $jobs = new AsyncJobService($this->pdo);
        $jobs->enqueue('GARMIN_CSV_DEEP_ANALYSIS', 'ipca_garmin_csv_files', (string)$csvFileId, array('csv_file_id' => $csvFileId));
        $jobs->enqueue('GARMIN_CSV_SESSION_MATCH', 'ipca_garmin_csv_files', (string)$csvFileId, array('csv_file_id' => $csvFileId));
        $jobs->enqueue('FLIGHT_RECORD_DERIVATION', 'ipca_garmin_csv_files', (string)$csvFileId, array('csv_file_id' => $csvFileId));
    }

    private function persistCsv(string $assembled, string $sha256, string $originalFilename): string
    {
        $dir = $this->storageRoot() . '/garmin_csv/' . gmdate('Y/m/d');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create Garmin CSV storage directory.');
        }
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($originalFilename)) ?: 'garmin.csv';
        $target = $dir . '/' . $sha256 . '-' . $safeName;
        if (!rename($assembled, $target)) {
            if (!copy($assembled, $target)) {
                throw new RuntimeException('Could not persist Garmin CSV evidence.');
            }
        }
        return $target;
    }

    private function storageRoot(): string
    {
        $root = dirname(__DIR__) . '/storage/cvr';
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new RuntimeException('Could not create CVR storage root.');
        }
        return $root;
    }

    private function uploadSessionDir(string $uploadUuid): string
    {
        return $this->storageRoot() . '/csv_upload_sessions/' . $this->sanitizeUuid($uploadUuid);
    }

    /**
     * @return list<int>
     */
    private function receivedChunkIndexes(string $dir): array
    {
        if (!is_dir($dir)) {
            return array();
        }
        $indexes = array();
        foreach (scandir($dir) ?: array() as $file) {
            if (preg_match('/^(\d{8})\.part$/', $file, $m) === 1) {
                $indexes[] = (int)$m[1];
            }
        }
        sort($indexes);
        return $indexes;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function uploadRequest(string $uploadUuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_garmin_csv_upload_requests WHERE upload_uuid = ? LIMIT 1');
        $stmt->execute(array($uploadUuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function csvBySha(string $sha256): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_garmin_csv_files WHERE sha256 = ? LIMIT 1');
        $stmt->execute(array($sha256));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function csvById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_garmin_csv_files WHERE id = ? LIMIT 1');
        $stmt->execute(array($id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function sanitizeUuid(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-f0-9-]{36}$/', $value) === 1 ? $value : '';
    }
}
