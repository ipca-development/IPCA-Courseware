<?php
declare(strict_types=1);

require_once __DIR__ . '/AsyncJobService.php';
require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/GarminCsvValidationService.php';
require_once __DIR__ . '/GarminCsvFlightSummaryService.php';
require_once __DIR__ . '/GarminFlightDataSourceClassificationService.php';
require_once __DIR__ . '/GarminFlightDataSourceService.php';
require_once __DIR__ . '/GarminSourceGroupSelectionService.php';

final class SyncAgentGarminIngestionService
{
    private string $providerName = 'desktop_sync_agent';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function upsertEntries(array $payload): array
    {
        $entries = array();
        if (isset($payload['entry']) && is_array($payload['entry'])) {
            $entries[] = $payload['entry'];
        }
        if (isset($payload['entries']) && is_array($payload['entries'])) {
            foreach ($payload['entries'] as $entry) {
                if (is_array($entry)) {
                    $entries[] = $entry;
                }
            }
        }
        $service = new GarminFlightDataSourceService($this->pdo, $this->providerName);
        $results = array();
        foreach ($entries as $entry) {
            $results[] = $service->upsertEntryWithSources($entry);
        }
        return array('ok' => true, 'status' => 'accepted', 'entries_upserted' => count($results), 'results' => $results);
    }

    /**
     * @param array<string,mixed> $token
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function ingestSource(array $token, array $payload): array
    {
        $idempotencyKey = $this->required($payload, 'idempotency_key');
        $existing = $this->acknowledgment($idempotencyKey);
        if ($existing) {
            return array('ok' => true, 'status' => 'already_exists', 'acknowledgment_id' => (int)$existing['id']);
        }
        $artifactType = (string)($payload['artifact_type'] ?? 'GARMIN_ORIGINAL_SOURCE');
        if ($artifactType === 'GARMIN_TRACK_NORMALIZED_JSON') {
            return $this->ingestNormalizedTrackJson($token, $payload);
        }
        $entryUuid = $this->required($payload, 'entry_id');
        $sourceUuid = strtolower($this->required($payload, 'source_uuid'));
        $sha256 = strtolower($this->required($payload, 'sha256'));
        $bytes = base64_decode((string)($payload['content_base64'] ?? ''), true);
        if ($bytes === false || $bytes === '') {
            return array('ok' => false, 'status' => 'rejected', 'message' => 'Invalid source payload.');
        }
        if (strlen($bytes) > 100 * 1024 * 1024) {
            return array('ok' => false, 'status' => 'rejected', 'message' => 'Source file exceeds maximum size.');
        }
        if (hash('sha256', $bytes) !== $sha256) {
            return array('ok' => false, 'status' => 'rejected', 'message' => 'SHA-256 mismatch.');
        }

        $metadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : array();
        $entryPayload = isset($metadata['rawEntry']) && is_array($metadata['rawEntry'])
            ? $metadata['rawEntry']
            : (isset($payload['entry']) && is_array($payload['entry'])
            ? $payload['entry']
            : array(
                'uuid' => $entryUuid,
                'flightDataLogUUIDs' => array($sourceUuid),
                'canonicalTrackUUID' => (string)($metadata['canonicalTrackUUID'] ?? ''),
            ));
        (new GarminFlightDataSourceService($this->pdo, $this->providerName))->upsertEntryWithSources($entryPayload);
        $source = (new GarminFlightDataSourceService($this->pdo, $this->providerName))->sourceByUuid($sourceUuid);
        if (!is_array($source)) {
            return array('ok' => false, 'status' => 'retry_later', 'message' => 'Source metadata is not ready.');
        }

        $storedPath = $this->storeImmutable($sourceUuid, (string)($payload['filename'] ?? ($sourceUuid . '.csv')), $bytes);
        $classification = (new GarminFlightDataSourceClassificationService($this->pdo))->classifyPath($storedPath);
        $csvFileId = $this->ensureCsvFile((int)$source['id'], $source, $storedPath, $sha256, strlen($bytes), $payload, $classification);
        (new GarminFlightDataSourceClassificationService($this->pdo))->classifySource((int)$source['id'], $storedPath);
        $validation = (new GarminCsvValidationService($this->pdo))->validateFile($csvFileId, $storedPath);
        $groupId = $this->sourceGroupId((int)$source['id']);
        if ($groupId > 0) {
            (new GarminSourceGroupSelectionService($this->pdo))->selectForGroup($groupId);
        }
        $this->pdo->prepare("
            UPDATE ipca_garmin_flight_data_sources
            SET download_status = 'downloaded',
                import_status = 'imported',
                validation_status = ?,
                validation_severity = ?,
                garmin_csv_file_id = ?,
                stored_file_path = ?,
                source_filename = ?,
                source_content_type = ?,
                sha256 = ?,
                file_size_bytes = ?,
                downloaded_at = CURRENT_TIMESTAMP(3),
                validated_at = CURRENT_TIMESTAMP(3),
                imported_at = CURRENT_TIMESTAMP(3),
                updated_at = CURRENT_TIMESTAMP(3)
            WHERE id = ?
        ")->execute(array(
            (string)$validation['status'],
            (string)$validation['severity'],
            $csvFileId,
            $storedPath,
            (string)($payload['filename'] ?? ($sourceUuid . '.csv')),
            (string)($payload['content_type'] ?? 'application/octet-stream'),
            $sha256,
            strlen($bytes),
            (int)$source['id'],
        ));
        $this->enqueueFollowupJobs((int)$source['id'], $csvFileId, $groupId);
        $this->linkOriginalSourceToTrack($entryUuid, $sourceUuid, $csvFileId, $metadata, $classification);
        $this->deriveCsvSummaryNow($csvFileId);
        $this->recordAcknowledgment((int)$token['id'], $idempotencyKey, $entryUuid, $sourceUuid, $sha256, 'accepted', $csvFileId);
        return array('ok' => true, 'status' => 'accepted', 'csv_file_id' => $csvFileId, 'source_id' => (int)$source['id']);
    }

    /**
     * @param array<string,mixed> $token
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function ingestNormalizedTrackJson(array $token, array $payload): array
    {
        $idempotencyKey = $this->required($payload, 'idempotency_key');
        $entryUuid = $this->required($payload, 'entry_id');
        $trackUuid = strtolower($this->required($payload, 'source_uuid'));
        $sha256 = strtolower($this->required($payload, 'sha256'));
        $bytes = base64_decode((string)($payload['content_base64'] ?? ''), true);
        if ($bytes === false || $bytes === '') {
            return array('ok' => false, 'status' => 'rejected', 'message' => 'Invalid normalized track payload.');
        }
        if (strlen($bytes) > 100 * 1024 * 1024) {
            return array('ok' => false, 'status' => 'rejected', 'message' => 'Normalized track payload exceeds maximum size.');
        }
        if (hash('sha256', $bytes) !== $sha256) {
            return array('ok' => false, 'status' => 'rejected', 'message' => 'SHA-256 mismatch.');
        }
        $decoded = json_decode($bytes, true);
        if (!is_array($decoded)) {
            return array('ok' => false, 'status' => 'rejected', 'message' => 'Normalized track payload is not JSON.');
        }
        $metadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : array();
        $sessionCount = isset($decoded['sessions']) && is_array($decoded['sessions']) ? count($decoded['sessions']) : (int)($metadata['sessionCount'] ?? 0);
        $fieldCount = 0;
        $sourceDescriptors = array();
        if (isset($decoded['sessions']) && is_array($decoded['sessions'])) {
            foreach ($decoded['sessions'] as $session) {
                if (!is_array($session)) {
                    continue;
                }
                $fieldCount += isset($session['fields']) && is_array($session['fields']) ? count($session['fields']) : 0;
                if (isset($session['sources']) && is_array($session['sources'])) {
                    foreach ($session['sources'] as $source) {
                        if (is_array($source)) {
                            $sourceDescriptors[] = array(
                                'type' => isset($source['type']) ? (string)$source['type'] : '',
                                'name' => isset($source['name']) ? (string)$source['name'] : '',
                            );
                        }
                    }
                }
            }
        }

        $entryPayload = isset($payload['entry']) && is_array($payload['entry'])
            ? $payload['entry']
            : array('uuid' => $entryUuid, 'canonicalTrackUUID' => $trackUuid);
        (new GarminFlightDataSourceService($this->pdo, $this->providerName))->upsertEntryWithSources($entryPayload);
        $storedPath = $this->storeImmutableTrack($entryUuid, $trackUuid, $bytes);
        $this->ensureTrackArtifactTable();
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_normalized_track_artifacts
              (provider_name, garmin_entry_uuid, track_uuid, artifact_type, storage_path, sha256, file_size_bytes,
               content_type, format_version, session_count, field_count, source_descriptors_json, raw_metadata_json,
               first_seen_at, last_seen_at)
            VALUES (?, ?, ?, 'GARMIN_TRACK_NORMALIZED_JSON', ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3))
            ON DUPLICATE KEY UPDATE
              storage_path = VALUES(storage_path),
              sha256 = VALUES(sha256),
              file_size_bytes = VALUES(file_size_bytes),
              content_type = VALUES(content_type),
              format_version = VALUES(format_version),
              session_count = VALUES(session_count),
              field_count = VALUES(field_count),
              source_descriptors_json = VALUES(source_descriptors_json),
              raw_metadata_json = VALUES(raw_metadata_json),
              last_seen_at = CURRENT_TIMESTAMP(3),
              updated_at = CURRENT_TIMESTAMP(3)
        ");
        $stmt->execute(array(
            $this->providerName,
            $entryUuid,
            $trackUuid,
            $storedPath,
            $sha256,
            strlen($bytes),
            (string)($payload['content_type'] ?? 'application/json'),
            isset($decoded['formatVersion']) ? (int)$decoded['formatVersion'] : null,
            $sessionCount,
            $fieldCount,
            AuditEventService::jsonEncode($sourceDescriptors),
            AuditEventService::jsonEncode($metadata),
        ));
        $trackArtifactId = (int)$this->pdo->lastInsertId();
        if ($trackArtifactId <= 0) {
            $lookup = $this->pdo->prepare("
                SELECT id
                FROM ipca_garmin_normalized_track_artifacts
                WHERE provider_name = ?
                  AND garmin_entry_uuid = ?
                  AND track_uuid = ?
                  AND sha256 = ?
                LIMIT 1
            ");
            $lookup->execute(array($this->providerName, $entryUuid, $trackUuid, $sha256));
            $trackArtifactId = (int)$lookup->fetchColumn();
        }
        if ($trackArtifactId > 0) {
            (new AsyncJobService($this->pdo))->enqueue(
                'GARMIN_TRACK_FLIGHT_SUMMARY',
                'ipca_garmin_normalized_track_artifacts',
                (string)$trackArtifactId,
                array('track_artifact_id' => $trackArtifactId, 'entry_uuid' => $entryUuid, 'track_uuid' => $trackUuid),
                null,
                80
            );
        }
        $this->recordAcknowledgment((int)$token['id'], $idempotencyKey, $entryUuid, $trackUuid, $sha256, 'accepted', 0);
        return array('ok' => true, 'status' => 'accepted', 'artifact_type' => 'GARMIN_TRACK_NORMALIZED_JSON', 'track_uuid' => $trackUuid);
    }

    public function completeSync(?string $cursor): array
    {
        return array('ok' => true, 'status' => 'accepted', 'cursor' => $cursor);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function acknowledgment(string $idempotencyKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_sync_agent_upload_acknowledgments WHERE idempotency_key = ? LIMIT 1');
        $stmt->execute(array($idempotencyKey));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function recordAcknowledgment(int $tokenId, string $idempotencyKey, string $entryUuid, string $sourceUuid, string $sha256, string $status, int $csvFileId): void
    {
        $this->pdo->prepare("
            INSERT INTO ipca_sync_agent_upload_acknowledgments
              (token_id, provider_name, idempotency_key, garmin_entry_uuid, flight_data_log_uuid, sha256, status, garmin_csv_file_id, response_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute(array(
            $tokenId,
            $this->providerName,
            $idempotencyKey,
            $entryUuid,
            $sourceUuid,
            $sha256,
            $status,
            $csvFileId > 0 ? $csvFileId : null,
            AuditEventService::jsonEncode(array('status' => $status, 'csv_file_id' => $csvFileId)),
        ));
    }

    private function storeImmutableTrack(string $entryUuid, string $trackUuid, string $bytes): string
    {
        $dir = dirname(__DIR__) . '/storage/cvr/garmin_sync_agent_tracks/' . gmdate('Y/m/d');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create sync-agent Garmin track storage directory.');
        }
        $target = $dir . '/' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $entryUuid) . '-' . $trackUuid . '-track.json';
        $tmp = $target . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, $bytes, LOCK_EX);
        rename($tmp, $target);
        return $target;
    }

    private function ensureTrackArtifactTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS ipca_garmin_normalized_track_artifacts (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              provider_name VARCHAR(64) NOT NULL,
              garmin_entry_uuid CHAR(36) NOT NULL,
              track_uuid CHAR(36) NOT NULL,
              artifact_type VARCHAR(64) NOT NULL,
              storage_path VARCHAR(1024) NOT NULL,
              sha256 CHAR(64) NOT NULL,
              file_size_bytes BIGINT UNSIGNED NOT NULL,
              content_type VARCHAR(128) NOT NULL,
              format_version INT NULL,
              session_count INT NOT NULL DEFAULT 0,
              field_count INT NOT NULL DEFAULT 0,
              source_descriptors_json JSON NULL,
              raw_metadata_json JSON NULL,
              first_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
              last_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
              created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
              updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
              UNIQUE KEY uk_ipca_garmin_track_artifacts (provider_name, garmin_entry_uuid, track_uuid, sha256),
              KEY idx_ipca_garmin_track_artifacts_entry (provider_name, garmin_entry_uuid),
              KEY idx_ipca_garmin_track_artifacts_track (track_uuid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Additive normalized Garmin track JSON artifacts from sync-agent.';
        ");
    }

    private function storeImmutable(string $sourceUuid, string $filename, string $bytes): string
    {
        $dir = dirname(__DIR__) . '/storage/cvr/garmin_sync_agent/' . gmdate('Y/m/d');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create sync-agent Garmin evidence storage directory.');
        }
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($filename)) ?: ($sourceUuid . '.csv');
        $target = $dir . '/' . $sourceUuid . '-' . $safe;
        $tmp = $target . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, $bytes, LOCK_EX);
        rename($tmp, $target);
        return $target;
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $classification
     */
    private function ensureCsvFile(int $sourceId, array $source, string $path, string $sha256, int $fileSize, array $payload, array $classification): int
    {
        $this->ensureCsvEvidenceColumns();
        $sourceUuid = strtolower((string)($source['flight_data_log_uuid'] ?? ($payload['source_uuid'] ?? '')));
        $entry = $this->entryForSource($sourceId);
        $canonicalTrackUuid = (string)($entry['canonical_track_uuid'] ?? (($payload['metadata']['canonicalTrackUUID'] ?? '') ?: ''));
        $stmt = $this->pdo->prepare('SELECT id FROM ipca_garmin_csv_files WHERE provider_name = ? AND flight_data_log_uuid = ? AND sha256 = ? LIMIT 1');
        $stmt->execute(array($this->providerName, $sourceUuid, $sha256));
        $existingId = (int)$stmt->fetchColumn();
        if ($existingId > 0) {
            return $existingId;
        }
        $aircraftRegistration = $this->aircraftRegistrationForEvidence($classification, $entry);
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_csv_files
              (csv_file_uuid, aircraft_registration, source, upload_source, provider_name, original_filename,
               storage_path, sha256, file_size_bytes, mime_type, import_profile, aircraft_ident, product,
               system_identifier, airframe_hours_start, engine_hours_start,
               first_valid_sample_utc, last_valid_sample_utc, valid_row_count,
               flight_data_log_uuid, garmin_entry_uuid, canonical_track_uuid, source_type,
               parser_version, raw_header, parsed_header_json, flightstream_header)
            VALUES
              (:csv_file_uuid, :aircraft_registration, 'garmin_cloud', 'desktop_sync_agent', :provider_name, :original_filename,
               :storage_path, :sha256, :file_size_bytes, :mime_type, :import_profile, :aircraft_ident, :product,
               :system_identifier, :airframe_hours_start, :engine_hours_start,
               :first_valid_sample_utc, :last_valid_sample_utc, :valid_row_count,
               :flight_data_log_uuid, :garmin_entry_uuid, :canonical_track_uuid, :source_type,
               :parser_version, :raw_header, :parsed_header_json, :flightstream_header)
        ");
        $stmt->execute(array(
            ':csv_file_uuid' => AuditEventService::uuid(),
            ':aircraft_registration' => $aircraftRegistration,
            ':provider_name' => $this->providerName,
            ':original_filename' => (string)($payload['filename'] ?? (($source['flight_data_log_uuid'] ?? 'garmin') . '.csv')),
            ':storage_path' => $path,
            ':sha256' => $sha256,
            ':file_size_bytes' => $fileSize,
            ':mime_type' => (string)($payload['content_type'] ?? 'application/octet-stream'),
            ':import_profile' => (string)($classification['parser_profile'] ?? 'desktop_sync_agent'),
            ':aircraft_ident' => (string)($classification['aircraft_ident'] ?? ($entry['aircraft_registration'] ?? '')),
            ':product' => (string)($classification['product'] ?? 'Garmin Sync Agent'),
            ':system_identifier' => (string)($classification['system_identifier'] ?? ''),
            ':airframe_hours_start' => $classification['airframe_hours_start'] ?? null,
            ':engine_hours_start' => $classification['engine_hours_start'] ?? null,
            ':first_valid_sample_utc' => $classification['first_timestamp_utc'] ?? null,
            ':last_valid_sample_utc' => $classification['last_timestamp_utc'] ?? null,
            ':valid_row_count' => (int)($classification['valid_sample_count'] ?? 0),
            ':flight_data_log_uuid' => $sourceUuid,
            ':garmin_entry_uuid' => (string)($entry['garmin_entry_uuid'] ?? ($payload['entry_id'] ?? '')),
            ':canonical_track_uuid' => $canonicalTrackUuid,
            ':source_type' => (string)($classification['data_log_type'] ?? 'UNKNOWN'),
            ':parser_version' => (string)($classification['parser_version'] ?? ''),
            ':raw_header' => (string)($classification['raw_header'] ?? ''),
            ':parsed_header_json' => AuditEventService::jsonEncode($classification['airframe_info_metadata'] ?? array()),
            ':flightstream_header' => (string)($classification['flightstream_header'] ?? ''),
        ));
        return (int)$this->pdo->lastInsertId();
    }

    private function ensureCsvEvidenceColumns(): void
    {
        $columns = array(
            'flight_data_log_uuid' => "ALTER TABLE ipca_garmin_csv_files ADD COLUMN flight_data_log_uuid CHAR(36) NULL AFTER provider_name",
            'garmin_entry_uuid' => "ALTER TABLE ipca_garmin_csv_files ADD COLUMN garmin_entry_uuid CHAR(36) NULL AFTER flight_data_log_uuid",
            'canonical_track_uuid' => "ALTER TABLE ipca_garmin_csv_files ADD COLUMN canonical_track_uuid CHAR(36) NULL AFTER garmin_entry_uuid",
            'source_type' => "ALTER TABLE ipca_garmin_csv_files ADD COLUMN source_type VARCHAR(64) NOT NULL DEFAULT 'UNKNOWN' AFTER canonical_track_uuid",
            'parser_version' => "ALTER TABLE ipca_garmin_csv_files ADD COLUMN parser_version VARCHAR(64) NOT NULL DEFAULT '' AFTER source_type",
            'raw_header' => "ALTER TABLE ipca_garmin_csv_files ADD COLUMN raw_header TEXT NULL AFTER parser_version",
            'parsed_header_json' => "ALTER TABLE ipca_garmin_csv_files ADD COLUMN parsed_header_json JSON NULL AFTER raw_header",
            'flightstream_header' => "ALTER TABLE ipca_garmin_csv_files ADD COLUMN flightstream_header VARCHAR(255) NOT NULL DEFAULT '' AFTER parsed_header_json",
        );
        foreach ($columns as $column => $sql) {
            if (!$this->columnExists('ipca_garmin_csv_files', $column)) {
                $this->pdo->exec($sql);
            }
        }
        if ($this->indexExists('ipca_garmin_csv_files', 'uk_ipca_garmin_csv_files_sha256')) {
            $this->pdo->exec('ALTER TABLE ipca_garmin_csv_files DROP INDEX uk_ipca_garmin_csv_files_sha256');
        }
        if (!$this->indexExists('ipca_garmin_csv_files', 'idx_ipca_garmin_csv_files_sha256')) {
            $this->pdo->exec('CREATE INDEX idx_ipca_garmin_csv_files_sha256 ON ipca_garmin_csv_files (sha256)');
        }
        if (!$this->indexExists('ipca_garmin_csv_files', 'uk_ipca_garmin_csv_files_source_sha')) {
            $this->pdo->exec('CREATE UNIQUE INDEX uk_ipca_garmin_csv_files_source_sha ON ipca_garmin_csv_files (provider_name, flight_data_log_uuid, sha256)');
        }
    }

    private function aircraftRegistrationForEvidence(array $classification, array $entry): string
    {
        $systemId = (string)($classification['system_identifier'] ?? '');
        if ($systemId !== '' && $this->tableExists('ipca_garmin_system_identifier_mappings')) {
            $stmt = $this->pdo->prepare("
                SELECT tail_number
                FROM ipca_garmin_system_identifier_mappings
                WHERE system_identifier = ?
                  AND effective_to_utc IS NULL
                ORDER BY confidence = 'verified' DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute(array($systemId));
            $tail = trim((string)$stmt->fetchColumn());
            if ($tail !== '') {
                return $tail;
            }
        }
        return (string)($entry['aircraft_registration'] ?? '');
    }

    private function linkOriginalSourceToTrack(string $entryUuid, string $sourceUuid, int $csvFileId, array $metadata, array $classification): void
    {
        $trackUuid = (string)($metadata['canonicalTrackUUID'] ?? '');
        if ($trackUuid === '') {
            return;
        }
        $this->ensureFlightDataTrackLinkTable();
        $this->pdo->prepare("
            INSERT INTO ipca_garmin_flight_data_track_links
              (provider_name, garmin_entry_uuid, canonical_track_uuid, flight_data_log_uuid, garmin_csv_file_id,
               system_identifier, first_valid_sample_utc, last_valid_sample_utc, source_group_key)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              garmin_csv_file_id = VALUES(garmin_csv_file_id),
              system_identifier = VALUES(system_identifier),
              first_valid_sample_utc = VALUES(first_valid_sample_utc),
              last_valid_sample_utc = VALUES(last_valid_sample_utc),
              updated_at = CURRENT_TIMESTAMP(3)
        ")->execute(array(
            $this->providerName,
            $entryUuid,
            $trackUuid,
            $sourceUuid,
            $csvFileId,
            (string)($classification['system_identifier'] ?? ''),
            $classification['first_timestamp_utc'] ?? null,
            $classification['last_timestamp_utc'] ?? null,
            $entryUuid . ':' . $trackUuid,
        ));
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM ipca_garmin_normalized_track_artifacts
            WHERE provider_name = ?
              AND garmin_entry_uuid = ?
              AND track_uuid = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute(array($this->providerName, $entryUuid, $trackUuid));
        $trackArtifactId = (int)$stmt->fetchColumn();
        if ($trackArtifactId > 0) {
            (new AsyncJobService($this->pdo))->enqueue(
                'GARMIN_TRACK_FLIGHT_SUMMARY',
                'ipca_garmin_normalized_track_artifacts',
                (string)$trackArtifactId,
                array('track_artifact_id' => $trackArtifactId, 'entry_uuid' => $entryUuid, 'track_uuid' => $trackUuid, 'csv_file_id' => $csvFileId),
                null,
                80
            );
        }
    }

    private function ensureFlightDataTrackLinkTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS ipca_garmin_flight_data_track_links (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              provider_name VARCHAR(64) NOT NULL,
              garmin_entry_uuid CHAR(36) NOT NULL,
              canonical_track_uuid CHAR(36) NOT NULL,
              flight_data_log_uuid CHAR(36) NOT NULL,
              garmin_csv_file_id BIGINT UNSIGNED NOT NULL,
              system_identifier VARCHAR(128) NOT NULL DEFAULT '',
              first_valid_sample_utc DATETIME(3) NULL,
              last_valid_sample_utc DATETIME(3) NULL,
              source_group_key VARCHAR(160) NOT NULL DEFAULT '',
              created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
              updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
              UNIQUE KEY uk_ipca_garmin_fdtl_source (provider_name, flight_data_log_uuid, garmin_csv_file_id),
              KEY idx_ipca_garmin_fdtl_track (provider_name, garmin_entry_uuid, canonical_track_uuid),
              KEY idx_ipca_garmin_fdtl_csv (garmin_csv_file_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * @return array<string,mixed>
     */
    private function entryForSource(int $sourceId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT e.*
            FROM ipca_garmin_flight_data_sources s
            INNER JOIN ipca_garmin_logbook_entries e ON e.id = s.garmin_logbook_entry_id
            WHERE s.id = ?
            LIMIT 1
        ");
        $stmt->execute(array($sourceId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : array();
    }

    private function sourceGroupId(int $sourceId): int
    {
        $stmt = $this->pdo->prepare('SELECT source_group_id FROM ipca_garmin_source_group_members WHERE garmin_flight_data_source_id = ? LIMIT 1');
        $stmt->execute(array($sourceId));
        return (int)$stmt->fetchColumn();
    }

    private function enqueueFollowupJobs(int $sourceId, int $csvFileId, int $sourceGroupId): void
    {
        $jobs = new AsyncJobService($this->pdo);
        if ($sourceGroupId > 0) {
            $jobs->enqueue('GARMIN_SOURCE_GROUP_MATCH', 'ipca_garmin_source_groups', (string)$sourceGroupId, array('source_group_id' => $sourceGroupId));
            $jobs->enqueue('GARMIN_SOURCE_ROLE_SELECTION', 'ipca_garmin_source_groups', (string)$sourceGroupId, array('source_group_id' => $sourceGroupId));
        }
        $jobs->enqueue('GARMIN_CSV_FLIGHT_SUMMARY', 'ipca_garmin_csv_files', (string)$csvFileId, array('csv_file_id' => $csvFileId, 'garmin_source_id' => $sourceId, 'source_group_id' => $sourceGroupId), null, 80);
        $jobs->enqueue('GARMIN_CSV_SESSION_MATCH', 'ipca_garmin_csv_files', (string)$csvFileId, array('csv_file_id' => $csvFileId, 'garmin_source_id' => $sourceId, 'source_group_id' => $sourceGroupId));
        $jobs->enqueue('FLIGHT_RECORD_DERIVATION', 'ipca_garmin_csv_files', (string)$csvFileId, array('csv_file_id' => $csvFileId, 'garmin_source_id' => $sourceId, 'source_group_id' => $sourceGroupId), null, 120);
    }

    private function deriveCsvSummaryNow(int $csvFileId): void
    {
        try {
            (new GarminCsvFlightSummaryService($this->pdo))->deriveAndStore($csvFileId);
        } catch (Throwable $e) {
            error_log('[sync-agent garmin ingestion] CSV summary derivation deferred for csv_file_id=' . $csvFileId . ': ' . $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function required(array $payload, string $key): string
    {
        $value = trim((string)($payload[$key] ?? ''));
        if ($value === '') {
            throw new RuntimeException("Missing required field: {$key}");
        }
        return $value;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute(array($table));
        return (int)$stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute(array($table, $column));
        return (int)$stmt->fetchColumn() > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
        ");
        $stmt->execute(array($table, $index));
        return (int)$stmt->fetchColumn() > 0;
    }
}
