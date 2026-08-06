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

        $standaloneUpload = in_array(
            strtolower(trim((string)($meta['standalone_upload'] ?? ''))),
            array('1', 'true', 'yes'),
            true
        );
        $session = $standaloneUpload
            ? array()
            : (new FlightSessionService($this->pdo))->sessionForDevice($device, (string)($meta['session_uuid'] ?? ''));
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
    public function finalize(array $device, string $uploadUuid, string $workflowFlightRecordUuid = ''): array
    {
        $uploadUuid = $this->sanitizeUuid($uploadUuid);
        if ($uploadUuid === '') {
            throw new RuntimeException('CSV upload UUID is required.');
        }
        $rawWorkflowUuid = trim($workflowFlightRecordUuid);
        $workflowFlightRecordUuid = $this->sanitizeUuid($rawWorkflowUuid);
        if ($rawWorkflowUuid !== '' && $workflowFlightRecordUuid === '') {
            throw new RuntimeException('workflow_flight_record_uuid must be a valid UUID.');
        }
        if ($workflowFlightRecordUuid !== '') {
            $this->assertWorkflowFlightOwnership($device, $workflowFlightRecordUuid);
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
            $workflowLinked = null;
            if ($workflowFlightRecordUuid !== '') {
                $workflowLinked = $this->linkCsvToWorkflow($duplicate, $workflowFlightRecordUuid);
            }
            $this->pdo->prepare("
                UPDATE ipca_garmin_csv_upload_requests
                SET status = 'duplicate', assembled_path = ?, updated_at = CURRENT_TIMESTAMP(3)
                WHERE id = ?
            ")->execute(array($assembled, (int)$request['id']));
            if ($workflowFlightRecordUuid !== '') {
                require_once __DIR__ . '/CvrAutoReconstructionOrchestrator.php';
                CvrAutoReconstructionOrchestrator::safeConsider(
                    $this->pdo,
                    $workflowFlightRecordUuid,
                    null,
                    null,
                    (int)($duplicate['id'] ?? 0)
                );
            }
            return array(
                'ok' => true,
                'status' => 'duplicate',
                'csv_file_uuid' => $duplicate['csv_file_uuid'],
                'sha256' => $fingerprint['sha256'],
                'workflow_linked' => $workflowLinked,
            );
        }

        $storagePath = $this->persistCsv($assembled, (string)$fingerprint['sha256'], (string)($request['original_filename'] ?? 'garmin.csv'));
        $csvFileId = $this->insertCsvFile(
            $request,
            $device,
            $fingerprint,
            $storagePath,
            $workflowFlightRecordUuid
        );
        $this->insertFingerprint($csvFileId, $fingerprint);
        $validation = (new GarminCsvValidationService($this->pdo))->validateFile($csvFileId, $storagePath);
        $csvFile = $this->csvById($csvFileId);
        $match = $csvFile !== null ? (new GarminCsvSessionMatchService($this->pdo))->match($csvFile) : array();
        $this->classifySupersession($csvFileId, $fingerprint);
        $this->enqueueJobs($csvFileId);
        $workflowLinked = $workflowFlightRecordUuid !== ''
            ? $this->workflowLinkConfirmed($csvFileId, $workflowFlightRecordUuid)
            : null;
        if ($workflowFlightRecordUuid !== '' && !$workflowLinked) {
            throw new RuntimeException('The Garmin CSV was stored but could not be linked to the selected Flight Record.');
        }

        $this->pdo->prepare("
            UPDATE ipca_garmin_csv_upload_requests
            SET status = 'finalized', assembled_path = ?, updated_at = CURRENT_TIMESTAMP(3)
            WHERE id = ?
        ")->execute(array($storagePath, (int)$request['id']));

        if ($workflowFlightRecordUuid !== '') {
            require_once __DIR__ . '/CvrAutoReconstructionOrchestrator.php';
            CvrAutoReconstructionOrchestrator::safeConsider(
                $this->pdo,
                $workflowFlightRecordUuid,
                null,
                null,
                $csvFileId
            );
        }

        return array(
            'ok' => true,
            'status' => 'finalized',
            'csv_file_uuid' => $csvFile['csv_file_uuid'] ?? null,
            'sha256' => $fingerprint['sha256'],
            'workflow_linked' => $workflowLinked,
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
     * @param list<string> $sha256List
     * @return array<string,mixed>
     */
    public function knownHashes(array $device, array $sha256List, string $aircraftRegistration = ''): array
    {
        $normalized = array();
        foreach ($sha256List as $sha256) {
            $sha256 = strtolower(trim((string)$sha256));
            if (preg_match('/^[a-f0-9]{64}$/', $sha256) === 1) {
                $normalized[$sha256] = true;
            }
        }
        if ($normalized === array()) {
            return array('ok' => true, 'known' => array(), 'unknown' => array());
        }

        $known = array();
        $unknown = array();
        foreach (array_keys($normalized) as $sha256) {
            $row = $this->csvBySha($sha256);
            if ($row !== null) {
                $workflowFlightRecordUuid = trim((string)($row['workflow_flight_record_uuid'] ?? ''));
                $known[] = array(
                    'sha256' => $sha256,
                    'csv_file_uuid' => (string)($row['csv_file_uuid'] ?? ''),
                    'status' => 'finalized',
                    'workflow_flight_record_uuid' => $workflowFlightRecordUuid,
                    'workflow_linked' => $workflowFlightRecordUuid !== '',
                );
            } else {
                $unknown[] = $sha256;
            }
        }

        return array(
            'ok' => true,
            'known' => $known,
            'unknown' => $unknown,
            'device_id' => (int)($device['id'] ?? 0),
            'aircraft_registration' => trim($aircraftRegistration),
        );
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
            ':session_id' => isset($session['id']) && (int)$session['id'] > 0 ? (int)$session['id'] : null,
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
    private function insertCsvFile(
        array $request,
        array $device,
        array $fingerprint,
        string $storagePath,
        string $workflowFlightRecordUuid
    ): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_csv_files
              (csv_file_uuid, upload_request_id, session_id, workflow_flight_record_uuid, device_id, aircraft_id, aircraft_registration,
               original_filename, storage_path, sha256, file_size_bytes, import_profile, aircraft_ident,
               product, system_identifier, airframe_hours_start, engine_hours_start, first_valid_sample_utc,
               last_valid_sample_utc, valid_row_count)
            VALUES
              (:csv_file_uuid, :upload_request_id, :session_id, :workflow_flight_record_uuid, :device_id, :aircraft_id, :aircraft_registration,
               :original_filename, :storage_path, :sha256, :file_size_bytes, :import_profile, :aircraft_ident,
               :product, :system_identifier, :airframe_hours_start, :engine_hours_start, :first_valid_sample_utc,
               :last_valid_sample_utc, :valid_row_count)
        ");
        $stmt->execute(array(
            ':csv_file_uuid' => AuditEventService::uuid(),
            ':upload_request_id' => (int)$request['id'],
            ':session_id' => $request['session_id'] ?? null,
            ':workflow_flight_record_uuid' => $workflowFlightRecordUuid !== '' ? $workflowFlightRecordUuid : null,
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
     * @param array<string,mixed> $device
     */
    private function assertWorkflowFlightOwnership(array $device, string $workflowFlightRecordUuid): void
    {
        $deviceId = (int)($device['id'] ?? 0);
        $aircraftId = (int)($device['aircraft_id'] ?? 0);
        $registration = strtoupper(trim((string)($device['aircraft_registration'] ?? '')));
        $organizationId = max(1, (int)($device['organization_id'] ?? 1));

        // Matching Dispatch for this enrolled aircraft → PASS.
        $aircraftPredicate = $aircraftId > 0
            ? 'aircraft_id = :aircraft_id'
            : 'UPPER(aircraft_registration) = :registration';
        $stmt = $this->pdo->prepare(
            'SELECT id
             FROM ipca_cvr_dispatches
             WHERE workflow_flight_record_uuid = :flight_uuid
               AND organization_id = :organization_id
               AND ' . $aircraftPredicate . '
             LIMIT 1'
        );
        $parameters = array(
            ':flight_uuid' => $workflowFlightRecordUuid,
            ':organization_id' => $organizationId,
        );
        if ($aircraftId > 0) {
            $parameters[':aircraft_id'] = $aircraftId;
        } else {
            $parameters[':registration'] = $registration;
        }
        $stmt->execute($parameters);
        if ($stmt->fetchColumn() !== false) {
            return;
        }

        // Any Dispatch for this Flight Record on another aircraft → FAIL.
        $conflict = $this->pdo->prepare(
            'SELECT aircraft_id, aircraft_registration
             FROM ipca_cvr_dispatches
             WHERE workflow_flight_record_uuid = ?
             LIMIT 1'
        );
        $conflict->execute(array($workflowFlightRecordUuid));
        $dispatchRow = $conflict->fetch(PDO::FETCH_ASSOC);
        if (is_array($dispatchRow)) {
            throw new RuntimeException('The selected Flight Record does not belong to this CVR Unit aircraft.');
        }

        // Offline-first: Garmin may finalize while Dispatch is still queued.
        // Ownership is NEVER optional — verify against immutable workflow context when present.
        $contextOwners = $this->workflowFlightOwnerContexts($workflowFlightRecordUuid);
        if ($contextOwners === array()) {
            // No Dispatch and no workflow context yet: authenticated enrolled device/aircraft
            // is the authoritative ownership source for this not-yet-synced Flight Record.
            if ($deviceId <= 0 || ($aircraftId <= 0 && $registration === '')) {
                throw new RuntimeException('The selected Flight Record does not belong to this CVR Unit aircraft.');
            }
            return;
        }

        foreach ($contextOwners as $owner) {
            if ($this->ownerContextMatchesDevice($owner, $deviceId, $aircraftId, $registration)) {
                return;
            }
        }

        throw new RuntimeException('The selected Flight Record does not belong to this CVR Unit aircraft.');
    }

    /**
     * Immutable workflow ownership context for a Flight Record when Dispatch is not yet present.
     *
     * @return list<array{device_id:int,aircraft_id:int,aircraft_registration:string}>
     */
    private function workflowFlightOwnerContexts(string $workflowFlightRecordUuid): array
    {
        $owners = array();
        $seen = array();

        $queries = array(
            'SELECT e.device_id AS device_id,
                    d.aircraft_id AS aircraft_id,
                    UPPER(TRIM(COALESCE(d.aircraft_registration, \'\'))) AS aircraft_registration
             FROM ipca_cvr_workflow_evidence_batches e
             INNER JOIN ipca_cvr_devices d ON d.id = e.device_id
             WHERE e.workflow_flight_record_uuid = ?',
            'SELECT f.device_id AS device_id,
                    f.aircraft_id AS aircraft_id,
                    UPPER(TRIM(COALESCE(f.aircraft_registration, \'\'))) AS aircraft_registration
             FROM ipca_garmin_csv_files f
             WHERE f.workflow_flight_record_uuid = ?
               AND (f.device_id IS NOT NULL OR f.aircraft_id IS NOT NULL OR TRIM(COALESCE(f.aircraft_registration, \'\')) <> \'\')',
        );

        foreach ($queries as $sql) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array($workflowFlightRecordUuid));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
                $deviceId = (int)($row['device_id'] ?? 0);
                $aircraftId = (int)($row['aircraft_id'] ?? 0);
                $registration = strtoupper(trim((string)($row['aircraft_registration'] ?? '')));
                if ($deviceId <= 0 && $aircraftId <= 0 && $registration === '') {
                    continue;
                }
                $key = $deviceId . '|' . $aircraftId . '|' . $registration;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $owners[] = array(
                    'device_id' => $deviceId,
                    'aircraft_id' => $aircraftId,
                    'aircraft_registration' => $registration,
                );
            }
        }

        return $owners;
    }

    /**
     * @param array{device_id:int,aircraft_id:int,aircraft_registration:string} $owner
     */
    private function ownerContextMatchesDevice(array $owner, int $deviceId, int $aircraftId, string $registration): bool
    {
        if ($deviceId > 0 && (int)$owner['device_id'] === $deviceId) {
            return true;
        }
        if ($aircraftId > 0 && (int)$owner['aircraft_id'] === $aircraftId) {
            return true;
        }
        $ownerRegistration = strtoupper(trim((string)$owner['aircraft_registration']));
        return $registration !== '' && $ownerRegistration !== '' && $ownerRegistration === $registration;
    }

    /**
     * @param array<string,mixed> $csv
     */
    private function linkCsvToWorkflow(array $csv, string $workflowFlightRecordUuid): bool
    {
        $existing = strtolower(trim((string)($csv['workflow_flight_record_uuid'] ?? '')));
        if ($existing !== '' && $existing !== $workflowFlightRecordUuid) {
            throw new RuntimeException('This Garmin CSV is already attached to another Flight Record.');
        }
        if ($existing === '') {
            $this->pdo->prepare(
                'UPDATE ipca_garmin_csv_files
                 SET workflow_flight_record_uuid = ?
                 WHERE id = ? AND (workflow_flight_record_uuid IS NULL OR workflow_flight_record_uuid = \'\')'
            )->execute(array($workflowFlightRecordUuid, (int)$csv['id']));
        }
        if (!$this->workflowLinkConfirmed((int)$csv['id'], $workflowFlightRecordUuid)) {
            throw new RuntimeException('The Garmin CSV could not be linked to the selected Flight Record.');
        }
        return true;
    }

    private function workflowLinkConfirmed(int $csvFileId, string $workflowFlightRecordUuid): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM ipca_garmin_csv_files
             WHERE id = ? AND workflow_flight_record_uuid = ?
             LIMIT 1'
        );
        $statement->execute(array($csvFileId, $workflowFlightRecordUuid));
        return $statement->fetchColumn() !== false;
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
        $jobs->enqueue('GARMIN_CSV_FLIGHT_SUMMARY', 'ipca_garmin_csv_files', (string)$csvFileId, array('csv_file_id' => $csvFileId), null, 80);
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
