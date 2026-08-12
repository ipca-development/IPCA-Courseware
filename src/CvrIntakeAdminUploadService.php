<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/AsyncJobService.php';
require_once __DIR__ . '/CockpitRecorderService.php';
require_once __DIR__ . '/GarminCsvFingerprintService.php';
require_once __DIR__ . '/GarminCsvValidationService.php';
require_once __DIR__ . '/CvrAudioIntakeMetricsService.php';

final class CvrIntakeAdminUploadService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $file
     * @return array<string,mixed>
     */
    public function uploadGarminCsv(array $file, string $aircraftRegistration, ?string $workflowFlightRecordUuid = null): array
    {
        $aircraftRegistration = strtoupper(trim($aircraftRegistration));
        if ($aircraftRegistration === '') {
            throw new RuntimeException('Aircraft registration is required for Garmin CSV upload.');
        }
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Garmin CSV upload failed: ' . $this->uploadErrorText($err));
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid Garmin CSV upload.');
        }
        $originalName = trim((string)($file['name'] ?? 'garmin.csv')) ?: 'garmin.csv';
        if (!str_ends_with(strtolower($originalName), '.csv')) {
            throw new RuntimeException('Garmin evidence must be a .csv file.');
        }

        $fingerprint = (new GarminCsvFingerprintService())->fingerprint($tmp, $originalName);
        $duplicate = $this->csvBySha((string)$fingerprint['sha256']);
        if ($duplicate !== null) {
            return array(
                'ok' => true,
                'status' => 'duplicate',
                'csv_file_id' => (int)$duplicate['id'],
                'csv_file_uuid' => (string)($duplicate['csv_file_uuid'] ?? ''),
                'message' => 'This Garmin CSV already exists in intake.',
            );
        }

        $storagePath = $this->persistCsv($tmp, (string)$fingerprint['sha256'], $originalName);
        $csvFileId = $this->insertGarminCsv($fingerprint, $storagePath, $originalName, $aircraftRegistration, $workflowFlightRecordUuid);
        $this->insertFingerprint($csvFileId, $fingerprint);
        (new GarminCsvValidationService($this->pdo))->validateFile($csvFileId, $storagePath);
        $this->enqueueGarminJobs($csvFileId);

        return array(
            'ok' => true,
            'status' => 'uploaded',
            'csv_file_id' => $csvFileId,
            'message' => 'Garmin CSV uploaded and queued for validation.',
        );
    }

    /**
     * @param array<string,mixed> $file
     * @return array<string,mixed>
     */
    public function uploadAudio(
        array $file,
        int $aircraftId,
        string $startedAtLocal,
        ?float $durationSeconds = null,
        string $studentName = '',
        string $instructorName = '',
        string $missionCode = '',
        ?string $workflowFlightRecordUuid = null,
        ?string $operationalSessionUuid = null
    ): array {
        if ($aircraftId <= 0) {
            throw new RuntimeException('Select a valid aircraft for Cockpit Audio upload.');
        }
        $timezone = cw_aircraft_operational_timezone($this->pdo, $aircraftId);
        $startedAtUtc = cw_local_input_to_utc($startedAtLocal, $timezone);
        if ($startedAtUtc === null) {
            throw new RuntimeException('Recording start time is invalid. Use the local date/time picker.');
        }

        $result = (new CockpitRecorderService($this->pdo))->storeUploadedRecording($file, array(
            'aircraft_id' => $aircraftId,
            'started_at' => $startedAtUtc,
            'duration' => max(0.0, (float)($durationSeconds ?? 0)),
            'input_device' => 'admin_manual_upload',
            'language' => 'en',
        ));

        $recordingId = (int)($result['recording']['id'] ?? 0);
        if ($recordingId > 0) {
            $crew = CvrAudioIntakeMetricsService::crewFromManualForm($studentName, $instructorName);
            (new CvrAudioIntakeMetricsService($this->pdo))->saveIntakeMetadata($recordingId, array(
                'intake_source' => 'manual',
                'intake_mission_code' => strtoupper(trim($missionCode)),
                'intake_crew_json' => $crew === array() ? null : $crew,
            ));
            $this->linkAudioToOperationalSession(
                $recordingId,
                $workflowFlightRecordUuid,
                $operationalSessionUuid
            );
        }

        return array(
            'ok' => true,
            'status' => 'uploaded',
            'recording_id' => $recordingId,
            'recording_uid' => (string)($result['recording']['recording_uid'] ?? ''),
            'message' => 'Cockpit Audio uploaded and queued for transcription.',
        );
    }

    private function linkAudioToOperationalSession(
        int $recordingId,
        ?string $workflowFlightRecordUuid,
        ?string $operationalSessionUuid
    ): void {
        $flightUuid = strtolower(trim((string)$workflowFlightRecordUuid));
        $sessionUuid = strtolower(trim((string)$operationalSessionUuid));
        if ($recordingId <= 0 || $flightUuid === '') {
            return;
        }
        if (!preg_match('/^[0-9a-f-]{36}$/', $flightUuid)
            || ($sessionUuid !== '' && !preg_match('/^[0-9a-f-]{36}$/', $sessionUuid))) {
            throw new RuntimeException('Operational Session audio linkage is invalid.');
        }
        $sets = array('flight_session_uid = ?', "intake_source = 'admin_manual_checkin'");
        $params = array($flightUuid);
        if ($sessionUuid !== '' && $this->columnExists('ipca_cockpit_recordings', 'operational_session_uuid')) {
            $sets[] = 'operational_session_uuid = ?';
            $params[] = $sessionUuid;
        }
        $params[] = $recordingId;
        $this->pdo->prepare(
            'UPDATE ipca_cockpit_recordings SET ' . implode(', ', $sets) . ' WHERE id = ?'
        )->execute($params);
    }

    /**
     * @param array<string,mixed> $fingerprint
     */
    private function insertGarminCsv(
        array $fingerprint,
        string $storagePath,
        string $originalName,
        string $aircraftRegistration,
        ?string $workflowFlightRecordUuid
    ): int {
        $columns = array(
            'csv_file_uuid' => AuditEventService::uuid(),
            'aircraft_registration' => $aircraftRegistration,
            'original_filename' => substr($originalName, 0, 255),
            'storage_path' => $storagePath,
            'sha256' => (string)$fingerprint['sha256'],
            'file_size_bytes' => (int)$fingerprint['file_size_bytes'],
            'import_profile' => (string)$fingerprint['import_profile'],
            'aircraft_ident' => (string)($fingerprint['aircraft_ident'] ?: $aircraftRegistration),
            'product' => (string)($fingerprint['product'] ?? ''),
            'system_identifier' => (string)($fingerprint['system_identifier'] ?? ''),
            'airframe_hours_start' => $fingerprint['airframe_hours_start'] ?? null,
            'engine_hours_start' => $fingerprint['engine_hours_start'] ?? null,
            'first_valid_sample_utc' => $fingerprint['first_valid_sample_utc'] ?? null,
            'last_valid_sample_utc' => $fingerprint['last_valid_sample_utc'] ?? null,
            'valid_row_count' => (int)($fingerprint['valid_row_count'] ?? 0),
        );
        if ($this->columnExists('ipca_garmin_csv_files', 'source')) {
            $columns['source'] = 'cvr_admin_intake';
        }
        if ($this->columnExists('ipca_garmin_csv_files', 'upload_source')) {
            $columns['upload_source'] = 'admin_manual';
        }
        if ($this->columnExists('ipca_garmin_csv_files', 'provider_name')) {
            $columns['provider_name'] = 'admin_intake';
        }
        if ($workflowFlightRecordUuid !== null
            && $workflowFlightRecordUuid !== ''
            && $this->columnExists('ipca_garmin_csv_files', 'workflow_flight_record_uuid')) {
            $columns['workflow_flight_record_uuid'] = strtolower(trim($workflowFlightRecordUuid));
        }

        $fields = array_keys($columns);
        $placeholders = array_map(static fn(string $field): string => ':' . $field, $fields);
        $params = array();
        foreach ($columns as $field => $value) {
            $params[':' . $field] = $value;
        }
        $sql = 'INSERT INTO ipca_garmin_csv_files (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $this->pdo->prepare($sql)->execute($params);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $fingerprint
     */
    private function insertFingerprint(int $csvFileId, array $fingerprint): void
    {
        if (!$this->tableExists('ipca_garmin_csv_fingerprints')) {
            return;
        }
        $this->pdo->prepare("
            INSERT INTO ipca_garmin_csv_fingerprints
              (csv_file_id, fingerprint_uuid, parser_version, normalized_header_hash, first_rows_hash,
               last_rows_hash, gps_path_summary_hash, utc_duration_ms, source_filename, fingerprint_json)
            VALUES
              (:csv_file_id, :fingerprint_uuid, :parser_version, :normalized_header_hash, :first_rows_hash,
               :last_rows_hash, :gps_path_summary_hash, :utc_duration_ms, :source_filename, :fingerprint_json)
        ")->execute(array(
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

    private function enqueueGarminJobs(int $csvFileId): void
    {
        $jobs = new AsyncJobService($this->pdo);
        $jobs->enqueue('GARMIN_CSV_DEEP_ANALYSIS', 'ipca_garmin_csv_files', (string)$csvFileId, array('csv_file_id' => $csvFileId));
        $jobs->enqueue('GARMIN_CSV_FLIGHT_SUMMARY', 'ipca_garmin_csv_files', (string)$csvFileId, array('csv_file_id' => $csvFileId), null, 80);
        $jobs->enqueue('GARMIN_CSV_SESSION_MATCH', 'ipca_garmin_csv_files', (string)$csvFileId, array('csv_file_id' => $csvFileId));
        $jobs->enqueue('FLIGHT_RECORD_DERIVATION', 'ipca_garmin_csv_files', (string)$csvFileId, array('csv_file_id' => $csvFileId));
    }

    private function persistCsv(string $sourcePath, string $sha256, string $originalFilename): string
    {
        $dir = dirname(__DIR__) . '/storage/cvr/garmin_csv/admin_manual/' . gmdate('Y/m/d');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create Garmin CSV storage directory.');
        }
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($originalFilename)) ?: 'garmin.csv';
        $target = $dir . '/' . $sha256 . '-' . $safeName;
        if (!copy($sourcePath, $target)) {
            throw new RuntimeException('Could not persist Garmin CSV evidence.');
        }
        return $target;
    }

    /** @return array<string,mixed>|null */
    private function csvBySha(string $sha256): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_garmin_csv_files WHERE sha256 = ? LIMIT 1');
        $stmt->execute(array(strtolower(trim($sha256))));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute(array($table));
        return (int)$stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute(array($table, $column));
        return (int)$stmt->fetchColumn() > 0;
    }

    private function uploadErrorText(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds upload size limit.',
            UPLOAD_ERR_PARTIAL => 'Upload was interrupted.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary directory is unavailable.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by server extension.',
            default => 'Unknown upload error.',
        };
    }
}
