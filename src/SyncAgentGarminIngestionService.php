<?php
declare(strict_types=1);

require_once __DIR__ . '/AsyncJobService.php';
require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/GarminCsvValidationService.php';
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

        $entryPayload = isset($payload['entry']) && is_array($payload['entry'])
            ? $payload['entry']
            : array('uuid' => $entryUuid, 'flightDataLogUUIDs' => array($sourceUuid));
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
        $stmt = $this->pdo->prepare('SELECT id FROM ipca_garmin_csv_files WHERE sha256 = ? LIMIT 1');
        $stmt->execute(array($sha256));
        $existingId = (int)$stmt->fetchColumn();
        if ($existingId > 0) {
            return $existingId;
        }
        $entry = $this->entryForSource($sourceId);
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_csv_files
              (csv_file_uuid, aircraft_registration, source, upload_source, provider_name, original_filename,
               storage_path, sha256, file_size_bytes, mime_type, import_profile, aircraft_ident, product,
               first_valid_sample_utc, last_valid_sample_utc, valid_row_count)
            VALUES (?, ?, 'garmin_cloud', 'desktop_sync_agent', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(array(
            AuditEventService::uuid(),
            (string)($entry['aircraft_registration'] ?? ''),
            $this->providerName,
            (string)($payload['filename'] ?? (($source['flight_data_log_uuid'] ?? 'garmin') . '.csv')),
            $path,
            $sha256,
            $fileSize,
            (string)($payload['content_type'] ?? 'application/octet-stream'),
            (string)($classification['parser_profile'] ?? 'desktop_sync_agent'),
            (string)($classification['aircraft_ident'] ?? ($entry['aircraft_registration'] ?? '')),
            (string)($classification['product'] ?? 'Garmin Sync Agent'),
            $classification['first_timestamp_utc'] ?? null,
            $classification['last_timestamp_utc'] ?? null,
            (int)($classification['valid_sample_count'] ?? 0),
        ));
        return (int)$this->pdo->lastInsertId();
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
        $jobs->enqueue('GARMIN_CSV_SESSION_MATCH', 'ipca_garmin_csv_files', (string)$csvFileId, array('csv_file_id' => $csvFileId, 'garmin_source_id' => $sourceId, 'source_group_id' => $sourceGroupId));
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
}
