<?php
declare(strict_types=1);

require_once __DIR__ . '/AsyncJobService.php';
require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/GarminCsvValidationService.php';
require_once __DIR__ . '/GarminCsvFlightSummaryService.php';
require_once __DIR__ . '/GarminCsvFingerprintService.php';
require_once __DIR__ . '/GarminFlightDataSourceClassificationService.php';

final class GarminHistoricalBackfillService
{
    private const PROVIDER_NAME = 'historical_sd_card_csv';
    private const SOURCE_TYPE = 'GARMIN_SD_CARD_HISTORICAL_CSV';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $files Usually $_FILES['garmin_csv_files'].
     * @return array<string,mixed>
     */
    public function createBatchFromUpload(array $files, ?int $createdByUserId = null, string $aircraftHint = '', string $notes = ''): array
    {
        $normalizedFiles = $this->normalizeUploadedFiles($files);
        if ($normalizedFiles === array()) {
            throw new RuntimeException('No historical Garmin CSV files were selected.');
        }

        $batchId = $this->createBatchRecord($createdByUserId, $aircraftHint, $notes);
        $result = $this->addUploadedFilesToBatch($batchId, $files, $aircraftHint);

        return array('ok' => true, 'batch_id' => $batchId, 'files' => $result['files']);
    }

    public function createBatchRecord(?int $createdByUserId = null, string $aircraftHint = '', string $notes = ''): int
    {
        $this->pdo->beginTransaction();
        try {
            $batchId = $this->createBatch($createdByUserId, $aircraftHint, $notes);
            $this->pdo->commit();
            return $batchId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $files Usually $_FILES['garmin_csv_files'].
     * @return array<string,mixed>
     */
    public function addUploadedFilesToBatch(int $batchId, array $files, string $aircraftHint = ''): array
    {
        if ($batchId <= 0) {
            throw new RuntimeException('Historical Garmin batch id is required.');
        }
        $normalizedFiles = $this->normalizeUploadedFiles($files);
        if ($normalizedFiles === array()) {
            throw new RuntimeException('No historical Garmin CSV files were selected.');
        }

        $this->pdo->beginTransaction();
        try {
            $results = array();
            foreach ($normalizedFiles as $file) {
                $results[] = $this->ingestOneUploadedFile($batchId, $file, $aircraftHint);
            }
            $this->refreshBatchCounters($batchId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return array('ok' => true, 'batch_id' => $batchId, 'files' => $results);
    }

    /**
     * @return array<string,mixed>
     */
    public function status(int $limit = 10, ?int $batchId = null): array
    {
        if (!$this->tableExists('ipca_garmin_historical_backfill_batches')) {
            return array('ready' => false, 'message' => 'Historical Garmin backfill tables have not been installed.', 'batches' => array());
        }
        if ($batchId !== null && $batchId > 0) {
            $stmt = $this->pdo->prepare("
                SELECT *
                FROM ipca_garmin_historical_backfill_batches
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->bindValue(1, $batchId, PDO::PARAM_INT);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT *
                FROM ipca_garmin_historical_backfill_batches
                ORDER BY created_at DESC, id DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, max(1, min(50, $limit)), PDO::PARAM_INT);
        }
        $stmt->execute();
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();

        $fileStatuses = $this->countsByStatus('ipca_garmin_historical_backfill_files', 'parse_status', $batchId);
        $duplicateStatuses = $this->countsByStatus('ipca_garmin_historical_backfill_files', 'exact_duplicate_status', $batchId);
        $classes = $this->countsByStatus('ipca_garmin_historical_segments', 'classification', $batchId);
        $reviews = $this->countsByStatus('ipca_garmin_historical_segments', 'review_status', $batchId);
        $activeBatch = $batches[0] ?? null;
        $progress = $this->progressForBatch(is_array($activeBatch) ? (int)$activeBatch['id'] : 0);

        return array(
            'ready' => true,
            'batches' => $batches,
            'active_batch' => $activeBatch,
            'progress' => $progress,
            'file_statuses' => $fileStatuses,
            'duplicate_statuses' => $duplicateStatuses,
            'segment_classifications' => $classes,
            'review_statuses' => $reviews,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function processFile(int $backfillFileId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_garmin_historical_backfill_files WHERE id = ? LIMIT 1');
        $stmt->execute(array($backfillFileId));
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($file)) {
            throw new RuntimeException('Historical Garmin backfill file not found.');
        }
        $csvFileId = (int)($file['csv_file_id'] ?? 0);
        if ($csvFileId <= 0) {
            $this->markFileFailed($backfillFileId, 'No immutable Garmin CSV evidence row is linked.');
            return array('ok' => false, 'status' => 'failed', 'message' => 'No CSV evidence row linked.');
        }

        try {
            $summary = (new GarminCsvFlightSummaryService($this->pdo))->deriveAndStore($csvFileId);
            $classification = $this->classifySummary($summary);
            $this->upsertWholeFileSegment($backfillFileId, $csvFileId, $summary, $classification);
            $this->pdo->prepare("
                UPDATE ipca_garmin_historical_backfill_files
                SET parse_status = 'completed',
                    classification = ?,
                    confidence_score = ?,
                    review_status = ?,
                    resolved_aircraft_registration = ?,
                    evidence_summary_json = ?,
                    updated_at = CURRENT_TIMESTAMP(3)
                WHERE id = ?
            ")->execute(array(
                $classification['classification'],
                $classification['confidence_score'],
                $classification['review_status'],
                (string)($summary['tail'] ?? ''),
                AuditEventService::jsonEncode($summary),
                $backfillFileId,
            ));
            $this->refreshBatchCounters((int)$file['batch_id']);
            return array('ok' => true, 'status' => 'completed', 'classification' => $classification);
        } catch (Throwable $e) {
            $this->markFileFailed($backfillFileId, $e->getMessage());
            $this->refreshBatchCounters((int)$file['batch_id']);
            throw $e;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function recentFiles(int $limit = 25): array
    {
        if (!$this->tableExists('ipca_garmin_historical_backfill_files')) {
            return array();
        }
        $stmt = $this->pdo->prepare("
            SELECT f.*, b.batch_uuid
            FROM ipca_garmin_historical_backfill_files f
            INNER JOIN ipca_garmin_historical_backfill_batches b ON b.id = f.batch_id
            ORDER BY f.created_at DESC, f.id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @param array<string,mixed> $file
     * @return array<string,mixed>
     */
    private function ingestOneUploadedFile(int $batchId, array $file, string $aircraftHint): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return $this->recordFailedUpload($batchId, (string)($file['name'] ?? ''), $aircraftHint, 'Upload failed with code ' . $error);
        }
        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '' || (!is_uploaded_file($tmpName) && !is_file($tmpName))) {
            return $this->recordFailedUpload($batchId, (string)($file['name'] ?? ''), $aircraftHint, 'Uploaded file is missing.');
        }
        $bytes = (string)file_get_contents($tmpName);
        if ($bytes === '') {
            return $this->recordFailedUpload($batchId, (string)($file['name'] ?? ''), $aircraftHint, 'Uploaded file is empty.');
        }
        if (strlen($bytes) > 100 * 1024 * 1024) {
            return $this->recordFailedUpload($batchId, (string)($file['name'] ?? ''), $aircraftHint, 'Uploaded file exceeds the 100 MB safety limit.');
        }

        $sha256 = hash('sha256', $bytes);
        $existingCsvFileId = $this->existingCsvFileForSha($sha256);
        $duplicateStatus = $existingCsvFileId > 0 ? 'previously_imported' : 'new';
        $storedPath = $this->storeImmutable((string)$file['name'], $sha256, $bytes);
        $csvFileId = $existingCsvFileId;
        $classification = array();
        $validation = array();
        $fingerprint = array();

        if ($csvFileId <= 0) {
            $classification = (new GarminFlightDataSourceClassificationService($this->pdo))->classifyPath($storedPath);
            $csvFileId = $this->insertCsvEvidence($storedPath, $sha256, strlen($bytes), (string)$file['name'], $aircraftHint, $classification);
            $validation = (new GarminCsvValidationService($this->pdo))->validateFile($csvFileId, $storedPath);
            try {
                $fingerprint = (new GarminCsvFingerprintService())->fingerprint($storedPath, (string)$file['name']);
                $this->storeFingerprint($csvFileId, $fingerprint);
            } catch (Throwable $e) {
                $fingerprint = array('error' => $e->getMessage());
            }
        }

        $backfillFileId = $this->insertBackfillFile($batchId, $csvFileId, (string)$file['name'], $sha256, strlen($bytes), $duplicateStatus, $existingCsvFileId, $aircraftHint, array(
            'classification' => $classification,
            'validation' => $validation,
            'fingerprint' => $fingerprint,
        ));

        if ($duplicateStatus === 'new') {
            (new AsyncJobService($this->pdo))->enqueue(
                'GARMIN_HISTORICAL_FILE_PROCESS',
                'ipca_garmin_historical_backfill_files',
                (string)$backfillFileId,
                array('backfill_file_id' => $backfillFileId, 'csv_file_id' => $csvFileId),
                null,
                120,
                3,
                'historical_backfill'
            );
        }

        return array(
            'backfill_file_id' => $backfillFileId,
            'csv_file_id' => $csvFileId,
            'sha256' => $sha256,
            'duplicate_status' => $duplicateStatus,
        );
    }

    /**
     * @param array<string,mixed> $classification
     */
    private function insertCsvEvidence(string $path, string $sha256, int $fileSize, string $filename, string $aircraftHint, array $classification): int
    {
        $flightDataLogUuid = AuditEventService::uuid();
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_csv_files
              (csv_file_uuid, aircraft_registration, source, upload_source, provider_name, original_filename,
               storage_path, sha256, file_size_bytes, mime_type, import_profile, aircraft_ident, product,
               system_identifier, airframe_hours_start, engine_hours_start, first_valid_sample_utc,
               last_valid_sample_utc, valid_row_count, flight_data_log_uuid, garmin_entry_uuid,
               canonical_track_uuid, source_type, parser_version, raw_header, parsed_header_json, flightstream_header)
            VALUES
              (:csv_file_uuid, :aircraft_registration, 'garmin_historical_sd_card', 'admin_historical_backfill', :provider_name, :original_filename,
               :storage_path, :sha256, :file_size_bytes, 'text/csv', :import_profile, :aircraft_ident, :product,
               :system_identifier, :airframe_hours_start, :engine_hours_start, :first_valid_sample_utc,
               :last_valid_sample_utc, :valid_row_count, :flight_data_log_uuid, :garmin_entry_uuid,
               '', :source_type, :parser_version, :raw_header, :parsed_header_json, :flightstream_header)
        ");
        $tail = trim($aircraftHint) !== '' ? trim($aircraftHint) : (string)($classification['aircraft_ident'] ?? '');
        $stmt->execute(array(
            ':csv_file_uuid' => AuditEventService::uuid(),
            ':aircraft_registration' => strtoupper($tail),
            ':provider_name' => self::PROVIDER_NAME,
            ':original_filename' => $filename,
            ':storage_path' => $path,
            ':sha256' => $sha256,
            ':file_size_bytes' => $fileSize,
            ':import_profile' => (string)($classification['parser_profile'] ?? 'historical_sd_card'),
            ':aircraft_ident' => (string)($classification['aircraft_ident'] ?? ''),
            ':product' => (string)($classification['product'] ?? ''),
            ':system_identifier' => (string)($classification['system_identifier'] ?? ''),
            ':airframe_hours_start' => $classification['airframe_hours_start'] ?? null,
            ':engine_hours_start' => $classification['engine_hours_start'] ?? null,
            ':first_valid_sample_utc' => $classification['first_timestamp_utc'] ?? null,
            ':last_valid_sample_utc' => $classification['last_timestamp_utc'] ?? null,
            ':valid_row_count' => (int)($classification['valid_sample_count'] ?? 0),
            ':flight_data_log_uuid' => $flightDataLogUuid,
            ':garmin_entry_uuid' => AuditEventService::uuid(),
            ':source_type' => self::SOURCE_TYPE,
            ':parser_version' => (string)($classification['parser_version'] ?? ''),
            ':raw_header' => (string)($classification['raw_header'] ?? ''),
            ':parsed_header_json' => AuditEventService::jsonEncode($classification['airframe_info_metadata'] ?? array()),
            ':flightstream_header' => (string)($classification['flightstream_header'] ?? ''),
        ));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $evidence
     */
    private function insertBackfillFile(int $batchId, int $csvFileId, string $filename, string $sha256, int $bytes, string $duplicateStatus, int $existingCsvFileId, string $aircraftHint, array $evidence): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_historical_backfill_files
              (batch_id, csv_file_id, original_filename, sha256, file_size_bytes, exact_duplicate_status,
               existing_csv_file_id, selected_aircraft_hint, parse_status, review_status, evidence_summary_json)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              id = LAST_INSERT_ID(id),
              csv_file_id = VALUES(csv_file_id),
              exact_duplicate_status = VALUES(exact_duplicate_status),
              existing_csv_file_id = VALUES(existing_csv_file_id),
              evidence_summary_json = VALUES(evidence_summary_json),
              updated_at = CURRENT_TIMESTAMP(3)
        ");
        $parseStatus = $duplicateStatus === 'new' ? 'queued' : 'duplicate';
        $reviewStatus = $duplicateStatus === 'new' ? 'pending' : 'ignored';
        $stmt->execute(array(
            $batchId,
            $csvFileId,
            $filename,
            $sha256,
            $bytes,
            $duplicateStatus,
            $existingCsvFileId > 0 ? $existingCsvFileId : null,
            strtoupper(trim($aircraftHint)),
            $parseStatus,
            $reviewStatus,
            AuditEventService::jsonEncode($evidence),
        ));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private function classifySummary(array $summary): array
    {
        $status = (string)($summary['status'] ?? '');
        $rowCount = (int)($summary['row_count'] ?? 0);
        $tail = trim((string)($summary['tail'] ?? ''));
        $hobbsHours = $this->hoursFromDisplay((string)($summary['hobbs_time'] ?? ''));
        $tachoHours = $this->hoursFromDisplay((string)($summary['tacho_time'] ?? ''));
        $elapsedHours = $this->hoursFromDisplay((string)($summary['elapsed_time'] ?? ''));
        $hasRoute = (string)($summary['dep_airport'] ?? '--') !== '--' && (string)($summary['arr_airport'] ?? '--') !== '--';

        if ($status === 'parse_failed' || $status === 'missing_csv_file' || $rowCount === 0) {
            return array('classification' => 'Empty or No Usable Data', 'confidence_score' => 80.0, 'review_status' => 'needs_review');
        }
        if ($hobbsHours >= 0.2 || $tachoHours >= 0.2 || ($hasRoute && $elapsedHours >= 0.2)) {
            return array('classification' => $hasRoute ? 'Confirmed Flight' : 'Probable Flight', 'confidence_score' => $hasRoute ? 90.0 : 70.0, 'review_status' => $tail !== '' && stripos($tail, 'unknown') === false ? 'candidate' : 'needs_review');
        }
        if ($rowCount < 10 && $hobbsHours <= 0.05 && $tachoHours <= 0.05) {
            return array('classification' => 'Avionics Power On Only', 'confidence_score' => 70.0, 'review_status' => 'candidate');
        }
        return array('classification' => 'Needs Review', 'confidence_score' => 40.0, 'review_status' => 'needs_review');
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $classification
     */
    private function upsertWholeFileSegment(int $backfillFileId, int $csvFileId, array $summary, array $classification): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_historical_segments
              (segment_uuid, backfill_file_id, csv_file_id, segment_index, segment_type, classification,
               confidence_score, start_utc, end_utc, aircraft_registration, departure_airport_code,
               arrival_airport_code, review_status, evidence_json)
            VALUES
              (?, ?, ?, 1, 'whole_file', ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              classification = VALUES(classification),
              confidence_score = VALUES(confidence_score),
              start_utc = VALUES(start_utc),
              end_utc = VALUES(end_utc),
              aircraft_registration = VALUES(aircraft_registration),
              departure_airport_code = VALUES(departure_airport_code),
              arrival_airport_code = VALUES(arrival_airport_code),
              review_status = VALUES(review_status),
              evidence_json = VALUES(evidence_json),
              updated_at = CURRENT_TIMESTAMP(3)
        ");
        $stmt->execute(array(
            AuditEventService::uuid(),
            $backfillFileId,
            $csvFileId,
            (string)$classification['classification'],
            $classification['confidence_score'],
            $this->dateTimeOrNull($summary['start_utc'] ?? null),
            $this->dateTimeOrNull($summary['end_utc'] ?? null),
            (string)($summary['tail'] ?? ''),
            (string)($summary['dep_airport'] ?? '') !== '--' ? (string)$summary['dep_airport'] : null,
            (string)($summary['arr_airport'] ?? '') !== '--' ? (string)$summary['arr_airport'] : null,
            (string)$classification['review_status'],
            AuditEventService::jsonEncode(array('summary' => $summary, 'classification' => $classification)),
        ));
    }

    private function recordFailedUpload(int $batchId, string $filename, string $aircraftHint, string $message): array
    {
        $sha = hash('sha256', $filename . '|' . $message . '|' . microtime(true));
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_historical_backfill_files
              (batch_id, original_filename, sha256, file_size_bytes, exact_duplicate_status, selected_aircraft_hint, parse_status, review_status, error_json)
            VALUES (?, ?, ?, 0, 'failed_upload', ?, 'failed', 'needs_review', ?)
        ");
        $stmt->execute(array($batchId, $filename, $sha, strtoupper(trim($aircraftHint)), AuditEventService::jsonEncode(array('message' => $message))));
        return array('backfill_file_id' => (int)$this->pdo->lastInsertId(), 'status' => 'failed', 'message' => $message);
    }

    private function markFileFailed(int $backfillFileId, string $message): void
    {
        $this->pdo->prepare("
            UPDATE ipca_garmin_historical_backfill_files
            SET parse_status = 'failed',
                review_status = 'needs_review',
                error_json = ?,
                updated_at = CURRENT_TIMESTAMP(3)
            WHERE id = ?
        ")->execute(array(AuditEventService::jsonEncode(array('message' => $message)), $backfillFileId));
    }

    private function createBatch(?int $userId, string $aircraftHint, string $notes): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_historical_backfill_batches
              (batch_uuid, created_by_user_id, source_notes, selected_aircraft_hint, upload_status, processing_status)
            VALUES (?, ?, ?, ?, 'uploaded', 'queued')
        ");
        $stmt->execute(array(AuditEventService::uuid(), $userId, $notes, strtoupper(trim($aircraftHint))));
        return (int)$this->pdo->lastInsertId();
    }

    private function refreshBatchCounters(int $batchId): void
    {
        $stmt = $this->pdo->prepare("
            SELECT
              COUNT(*) AS file_count,
              COALESCE(SUM(file_size_bytes), 0) AS total_bytes,
              SUM(exact_duplicate_status <> 'new') AS duplicate_count,
              SUM(parse_status = 'completed') AS completed_count,
              SUM(parse_status = 'failed') AS failed_count,
              SUM(review_status = 'needs_review') AS needs_review_count,
              SUM(classification <> 'Needs Review') AS classified_count
            FROM ipca_garmin_historical_backfill_files
            WHERE batch_id = ?
        ");
        $stmt->execute(array($batchId));
        $c = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
        $processing = ((int)($c['failed_count'] ?? 0) > 0) ? 'needs_review' : (((int)($c['completed_count'] ?? 0) >= (int)($c['file_count'] ?? 0)) ? 'completed' : 'processing');
        $this->pdo->prepare("
            UPDATE ipca_garmin_historical_backfill_batches
            SET file_count = ?, total_bytes = ?, duplicate_count = ?, completed_count = ?, failed_count = ?,
                needs_review_count = ?, classified_count = ?, processing_status = ?, counters_json = ?,
                completed_at = IF(? = 'completed', CURRENT_TIMESTAMP(3), completed_at),
                updated_at = CURRENT_TIMESTAMP(3)
            WHERE id = ?
        ")->execute(array(
            (int)($c['file_count'] ?? 0),
            (int)($c['total_bytes'] ?? 0),
            (int)($c['duplicate_count'] ?? 0),
            (int)($c['completed_count'] ?? 0),
            (int)($c['failed_count'] ?? 0),
            (int)($c['needs_review_count'] ?? 0),
            (int)($c['classified_count'] ?? 0),
            $processing,
            AuditEventService::jsonEncode($c),
            $processing,
            $batchId,
        ));
    }

    /**
     * @param array<string,mixed> $fingerprint
     */
    private function storeFingerprint(int $csvFileId, array $fingerprint): void
    {
        if (!$this->tableExists('ipca_garmin_csv_fingerprints')) {
            return;
        }
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_csv_fingerprints
              (csv_file_id, fingerprint_uuid, parser_version, normalized_header_hash, first_rows_hash, last_rows_hash,
               gps_path_summary_hash, utc_duration_ms, source_filename, fingerprint_json)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              normalized_header_hash = VALUES(normalized_header_hash),
              first_rows_hash = VALUES(first_rows_hash),
              last_rows_hash = VALUES(last_rows_hash),
              gps_path_summary_hash = VALUES(gps_path_summary_hash),
              fingerprint_json = VALUES(fingerprint_json)
        ");
        $stmt->execute(array(
            $csvFileId,
            AuditEventService::uuid(),
            (string)($fingerprint['parser_version'] ?? 'phase1-v1'),
            (string)($fingerprint['normalized_header_hash'] ?? ''),
            (string)($fingerprint['first_rows_hash'] ?? ''),
            (string)($fingerprint['last_rows_hash'] ?? ''),
            (string)($fingerprint['gps_path_summary_hash'] ?? ''),
            $fingerprint['utc_duration_ms'] ?? null,
            (string)($fingerprint['source_filename'] ?? ''),
            AuditEventService::jsonEncode($fingerprint['fingerprint_json'] ?? $fingerprint),
        ));
    }

    private function existingCsvFileForSha(string $sha256): int
    {
        if (!$this->tableExists('ipca_garmin_csv_files')) {
            throw new RuntimeException('Garmin CSV evidence table is missing. Run the migration first.');
        }
        $stmt = $this->pdo->prepare('SELECT id FROM ipca_garmin_csv_files WHERE sha256 = ? ORDER BY id ASC LIMIT 1');
        $stmt->execute(array($sha256));
        return (int)$stmt->fetchColumn();
    }

    private function storeImmutable(string $filename, string $sha256, string $bytes): string
    {
        $dir = dirname(__DIR__) . '/storage/cvr/garmin_historical_sd_card/' . gmdate('Y/m/d');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create historical Garmin storage directory.');
        }
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($filename)) ?: 'garmin.csv';
        $target = $dir . '/' . substr($sha256, 0, 16) . '-' . $safe;
        $tmp = $target . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, $bytes, LOCK_EX);
        rename($tmp, $target);
        return $target;
    }

    /**
     * @param array<string,mixed> $files
     * @return list<array<string,mixed>>
     */
    private function normalizeUploadedFiles(array $files): array
    {
        if (!is_array($files['name'] ?? null)) {
            return array($files);
        }
        $out = array();
        foreach ((array)$files['name'] as $index => $name) {
            $out[] = array(
                'name' => $name,
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            );
        }
        return $out;
    }

    private function hoursFromDisplay(string $value): float
    {
        if ($value === '' || $value === '--') {
            return 0.0;
        }
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)/', $value, $m)) {
            return (float)$m[1];
        }
        return 0.0;
    }

    private function dateTimeOrNull(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        return date('Y-m-d H:i:s.000', $timestamp);
    }

    /**
     * @return array<string,int>
     */
    /**
     * @return array<string,mixed>
     */
    private function progressForBatch(int $batchId): array
    {
        if ($batchId <= 0 || !$this->tableExists('ipca_garmin_historical_backfill_files')) {
            return array('total' => 0, 'done' => 0, 'remaining' => 0, 'percent' => 0, 'state' => 'idle');
        }
        $stmt = $this->pdo->prepare("
            SELECT
              COUNT(*) AS total,
              SUM(parse_status IN ('completed','duplicate')) AS done,
              SUM(parse_status = 'queued') AS queued,
              SUM(parse_status = 'failed') AS failed,
              SUM(review_status = 'needs_review') AS needs_review,
              SUM(exact_duplicate_status <> 'new') AS duplicates
            FROM ipca_garmin_historical_backfill_files
            WHERE batch_id = ?
        ");
        $stmt->execute(array($batchId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
        $total = (int)($row['total'] ?? 0);
        $done = (int)($row['done'] ?? 0);
        $failed = (int)($row['failed'] ?? 0);
        $remaining = max(0, $total - $done - $failed);
        $percent = $total > 0 ? (int)round((($done + $failed) / $total) * 100) : 0;
        $state = $total === 0 ? 'waiting_for_upload' : ($remaining > 0 ? 'backend_processing' : ($failed > 0 ? 'needs_review' : 'complete'));
        return array(
            'total' => $total,
            'done' => $done,
            'queued' => (int)($row['queued'] ?? 0),
            'failed' => $failed,
            'needs_review' => (int)($row['needs_review'] ?? 0),
            'duplicates' => (int)($row['duplicates'] ?? 0),
            'remaining' => $remaining,
            'percent' => $percent,
            'state' => $state,
        );
    }

    /**
     * @return array<string,int>
     */
    private function countsByStatus(string $table, string $column, ?int $batchId = null): array
    {
        if (!$this->tableExists($table)) {
            return array();
        }
        $params = array();
        $join = '';
        $where = '';
        if ($batchId !== null && $batchId > 0) {
            if ($table === 'ipca_garmin_historical_segments') {
                $join = ' INNER JOIN ipca_garmin_historical_backfill_files f ON f.id = ipca_garmin_historical_segments.backfill_file_id';
                $where = ' WHERE f.batch_id = ?';
            } else {
                $where = ' WHERE batch_id = ?';
            }
            $params[] = $batchId;
        }
        $sql = 'SELECT ' . $column . ' AS k, COUNT(*) AS c FROM ' . $table . $join . $where . ' GROUP BY ' . $column;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        $out = array();
        foreach ($rows as $row) {
            $out[(string)$row['k']] = (int)$row['c'];
        }
        return $out;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute(array($table));
        return (int)$stmt->fetchColumn() > 0;
    }
}
