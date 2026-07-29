<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/FlightSessionService.php';
require_once __DIR__ . '/FlightRecordDerivationService.php';
require_once __DIR__ . '/MissionCatalogService.php';

final class ManualReconstructionBundleService
{
    private const SOURCE_TABLES = array(
        'dispatch' => 'ipca_cvr_dispatches',
        'cockpit_audio' => 'ipca_cockpit_recordings',
        'garmin_csv' => 'ipca_garmin_csv_files',
        'adsb_traffic' => 'ipca_cockpit_adsb_enrichments',
    );

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function freezeAndPrepare(
        int $dispatchId,
        int $recordingId,
        int $garminCsvId,
        bool $includeAdsb,
        ?int $actorUserId
    ): array {
        $this->requireSchema();
        $dispatch = $this->row(self::SOURCE_TABLES['dispatch'], $dispatchId);
        $recording = $this->row(self::SOURCE_TABLES['cockpit_audio'], $recordingId);
        $garmin = $this->row(self::SOURCE_TABLES['garmin_csv'], $garminCsvId);
        $this->assertAllowedSource('dispatch', $dispatch);
        $this->assertAllowedSource('cockpit_audio', $recording);
        $this->assertAllowedSource('garmin_csv', $garmin);

        $workflowUuid = $this->uuid((string)($dispatch['workflow_flight_record_uuid'] ?? ''), 'Dispatch Flight Record');
        $aircraft = $this->normalizeTail((string)($dispatch['aircraft_registration'] ?? ''));
        if ($aircraft === '') {
            throw new RuntimeException('Dispatch aircraft registration is required.');
        }
        foreach (array(
            'Cockpit Audio' => (string)($recording['aircraft_registration'] ?? ''),
            'Garmin CSV' => (string)($garmin['aircraft_registration'] ?? ''),
        ) as $label => $candidate) {
            $candidate = $this->normalizeTail($candidate);
            if ($candidate !== '' && $candidate !== $aircraft) {
                throw new RuntimeException($label . ' aircraft does not match the Dispatch.');
            }
        }

        $warnings = $this->timeWarnings($recording, $garmin);
        $adsb = null;
        if ($includeAdsb && $this->tableExists(self::SOURCE_TABLES['adsb_traffic'])) {
            $statement = $this->pdo->prepare(
                'SELECT * FROM ipca_cockpit_adsb_enrichments WHERE recording_id = ? ORDER BY id DESC LIMIT 1'
            );
            $statement->execute(array($recordingId));
            $adsb = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $items = array(
            'dispatch' => $this->snapshot('dispatch', self::SOURCE_TABLES['dispatch'], $dispatch),
            'cockpit_audio' => $this->snapshot('cockpit_audio', self::SOURCE_TABLES['cockpit_audio'], $recording),
            'garmin_csv' => $this->snapshot('garmin_csv', self::SOURCE_TABLES['garmin_csv'], $garmin),
        );
        if ($adsb) {
            $items['adsb_traffic'] = $this->snapshot('adsb_traffic', self::SOURCE_TABLES['adsb_traffic'], $adsb);
        } elseif ($includeAdsb) {
            $warnings[] = 'No ADS-B enrichment is currently available for the selected Cockpit Audio.';
        }
        $manifest = array(
            'schema_version' => 1,
            'flight_record_uuid' => $workflowUuid,
            'aircraft_registration' => $aircraft,
            'mission_code' => (string)($dispatch['mission_code'] ?? ''),
            'sources' => array_values($items),
            'flightcircle_excluded' => true,
        );
        $manifestJson = AuditEventService::jsonEncode($this->canonicalize($manifest));
        $manifestHash = hash('sha256', $manifestJson);

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT COALESCE(MAX(version_number), 0) + 1
                 FROM ipca_manual_intake_bundles WHERE workflow_flight_record_uuid = ? FOR UPDATE'
            );
            $statement->execute(array($workflowUuid));
            $version = max(1, (int)$statement->fetchColumn());
            $supersedes = null;
            if ($version > 1) {
                $previous = $this->pdo->prepare(
                    'SELECT id FROM ipca_manual_intake_bundles
                     WHERE workflow_flight_record_uuid = ? ORDER BY version_number DESC LIMIT 1'
                );
                $previous->execute(array($workflowUuid));
                $supersedes = (int)$previous->fetchColumn() ?: null;
            }
            $bundleUuid = AuditEventService::uuid();
            $insert = $this->pdo->prepare(
                'INSERT INTO ipca_manual_intake_bundles
                 (bundle_uuid, version_number, supersedes_bundle_id, status, dispatch_id,
                  cockpit_recording_id, garmin_csv_file_id, adsb_enrichment_id,
                  workflow_flight_record_uuid, aircraft_registration, mission_code,
                  manifest_sha256, manifest_json, validation_warnings_json, created_by, frozen_at)
                 VALUES (?, ?, ?, \'frozen\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(3))'
            );
            $insert->execute(array(
                $bundleUuid, $version, $supersedes, $dispatchId, $recordingId, $garminCsvId,
                $adsb['id'] ?? null, $workflowUuid, $aircraft, (string)($dispatch['mission_code'] ?? ''),
                $manifestHash, $manifestJson, AuditEventService::jsonEncode($warnings), $actorUserId,
            ));
            $bundleId = (int)$this->pdo->lastInsertId();
            $itemInsert = $this->pdo->prepare(
                'INSERT INTO ipca_manual_intake_bundle_items
                 (bundle_id, source_type, source_table, source_id, source_uuid, source_sha256, metadata_snapshot_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($items as $item) {
                $itemInsert->execute(array(
                    $bundleId, $item['source_type'], $item['source_table'], $item['source_id'],
                    $item['source_uuid'], $item['source_sha256'], AuditEventService::jsonEncode($item['metadata']),
                ));
            }
            $this->audit($bundleId, 'bundle_frozen', $actorUserId, array(
                'manifest_sha256' => $manifestHash,
                'warnings' => $warnings,
            ));
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        try {
            $this->prepareCanonicalSession($bundleId, $dispatch, $recording, $garmin, $actorUserId);
        } catch (Throwable $e) {
            $this->pdo->prepare(
                'UPDATE ipca_manual_intake_bundles SET status = \'needs_review\', processing_error = ? WHERE id = ?'
            )->execute(array($e->getMessage(), $bundleId));
            throw $e;
        }
        return $this->bundle($bundleId) ?? array();
    }

    /** @return array<string,mixed> */
    public function retryPreparation(int $bundleId, ?int $actorUserId): array
    {
        $bundle = $this->bundle($bundleId);
        if (!$bundle || (string)($bundle['status'] ?? '') !== 'needs_review') {
            throw new RuntimeException('Only a bundle requiring technical review can be retried.');
        }
        $dispatch = $this->row(self::SOURCE_TABLES['dispatch'], (int)$bundle['dispatch_id']);
        $recording = $this->row(self::SOURCE_TABLES['cockpit_audio'], (int)$bundle['cockpit_recording_id']);
        $garmin = $this->row(self::SOURCE_TABLES['garmin_csv'], (int)$bundle['garmin_csv_file_id']);
        $this->pdo->prepare(
            'UPDATE ipca_manual_intake_bundles
             SET status = \'frozen\', processing_error = NULL WHERE id = ? AND status = \'needs_review\''
        )->execute(array($bundleId));
        try {
            $this->prepareCanonicalSession($bundleId, $dispatch, $recording, $garmin, $actorUserId);
            $this->audit($bundleId, 'bundle_preparation_retried', $actorUserId, array(
                'result' => 'reconstruction_ready',
            ));
        } catch (Throwable $e) {
            $this->pdo->prepare(
                'UPDATE ipca_manual_intake_bundles SET status = \'needs_review\', processing_error = ? WHERE id = ?'
            )->execute(array($e->getMessage(), $bundleId));
            throw $e;
        }
        return $this->bundle($bundleId) ?? array();
    }

    /** @return list<array<string,mixed>> */
    public function recentBundles(int $limit = 30): array
    {
        if (!$this->tableExists('ipca_manual_intake_bundles')) {
            return array();
        }
        $sql = '
            SELECT b.*,
                   r.recording_uid, r.transcription_status, r.transcription_progress,
                   r.reconstruction_status, r.timeline_status,
                   (SELECT j.id FROM ipca_cockpit_reconstruction_jobs j
                    WHERE j.recording_id = b.cockpit_recording_id ORDER BY j.id DESC LIMIT 1) AS latest_job_id,
                   (SELECT j.status FROM ipca_cockpit_reconstruction_jobs j
                    WHERE j.recording_id = b.cockpit_recording_id ORDER BY j.id DESC LIMIT 1) AS latest_job_status
            FROM ipca_manual_intake_bundles b
            INNER JOIN ipca_cockpit_recordings r ON r.id = b.cockpit_recording_id
            ORDER BY b.id DESC LIMIT ' . max(1, min(100, $limit));
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /** @return array{ready:bool,reason:string,snapshot_id:?int} */
    public function transcriptGate(int $bundleId): array
    {
        $bundle = $this->bundle($bundleId);
        if (!$bundle) {
            return array('ready' => false, 'reason' => 'Bundle not found.', 'snapshot_id' => null);
        }
        if ((int)($bundle['transcript_snapshot_id'] ?? 0) > 0) {
            return array('ready' => true, 'reason' => '', 'snapshot_id' => (int)$bundle['transcript_snapshot_id']);
        }
        $recording = $this->row('ipca_cockpit_recordings', (int)$bundle['cockpit_recording_id']);
        if (strtolower((string)($recording['transcription_status'] ?? '')) !== 'ready') {
            return array('ready' => false, 'reason' => 'Raw transcript is not Ready.', 'snapshot_id' => null);
        }
        $text = trim((string)($recording['transcript_text'] ?? ''));
        if ($text === '') {
            return array('ready' => false, 'reason' => 'Raw transcript is empty.', 'snapshot_id' => null);
        }
        $chunks = $this->transcriptChunks((int)$recording['id']);
        if ($chunks !== array() && count(array_filter($chunks, fn(array $row): bool => strtolower((string)$row['status']) !== 'ready')) > 0) {
            return array('ready' => false, 'reason' => 'One or more transcript chunks are not Ready.', 'snapshot_id' => null);
        }
        return array('ready' => false, 'reason' => 'Transcript is Ready but must be version-locked.', 'snapshot_id' => null);
    }

    public function lockTranscript(int $bundleId, ?int $actorUserId): int
    {
        $bundle = $this->bundle($bundleId);
        if (!$bundle) {
            throw new RuntimeException('Bundle not found.');
        }
        if ((int)($bundle['transcript_snapshot_id'] ?? 0) > 0) {
            return (int)$bundle['transcript_snapshot_id'];
        }
        $recording = $this->row('ipca_cockpit_recordings', (int)$bundle['cockpit_recording_id']);
        if (strtolower((string)($recording['transcription_status'] ?? '')) !== 'ready') {
            throw new RuntimeException('Raw transcript must be Ready before it can be locked.');
        }
        $text = trim((string)($recording['transcript_text'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('Raw transcript is empty.');
        }
        $chunks = $this->transcriptChunks((int)$recording['id']);
        foreach ($chunks as $chunk) {
            if (strtolower((string)($chunk['status'] ?? '')) !== 'ready') {
                throw new RuntimeException('All transcript chunks must be Ready before locking.');
            }
        }
        $manifest = array_map(fn(array $chunk): array => array(
            'chunk_index' => (int)($chunk['chunk_index'] ?? 0),
            'start_seconds' => (float)($chunk['start_seconds'] ?? 0),
            'end_seconds' => (float)($chunk['end_seconds'] ?? 0),
            'text_sha256' => hash('sha256', (string)($chunk['transcript_text'] ?? '')),
        ), $chunks);
        $hash = hash('sha256', AuditEventService::jsonEncode(array('text' => $text, 'chunks' => $manifest)));
        $existing = $this->pdo->prepare(
            'SELECT id FROM ipca_cockpit_transcript_snapshots WHERE cockpit_recording_id = ? AND transcript_sha256 = ? LIMIT 1'
        );
        $existing->execute(array((int)$recording['id'], $hash));
        $snapshotId = (int)$existing->fetchColumn();
        if ($snapshotId <= 0) {
            $statement = $this->pdo->prepare(
                'INSERT INTO ipca_cockpit_transcript_snapshots
                 (snapshot_uuid, cockpit_recording_id, transcript_sha256, transcript_text,
                  chunks_manifest_json, source_status, word_count, locked_by)
                 VALUES (?, ?, ?, ?, ?, \'ready\', ?, ?)'
            );
            $statement->execute(array(
                AuditEventService::uuid(), (int)$recording['id'], $hash, $text,
                AuditEventService::jsonEncode($manifest), str_word_count($text), $actorUserId,
            ));
            $snapshotId = (int)$this->pdo->lastInsertId();
        }
        $this->pdo->prepare(
            'UPDATE ipca_manual_intake_bundles SET transcript_snapshot_id = ? WHERE id = ? AND transcript_snapshot_id IS NULL'
        )->execute(array($snapshotId, $bundleId));
        $this->audit($bundleId, 'transcript_locked', $actorUserId, array(
            'snapshot_id' => $snapshotId,
            'transcript_sha256' => $hash,
        ));
        return $snapshotId;
    }

    /** @param array<string,mixed> $dispatch @param array<string,mixed> $recording @param array<string,mixed> $garmin */
    private function prepareCanonicalSession(int $bundleId, array $dispatch, array $recording, array $garmin, ?int $actorUserId): void
    {
        $device = $this->row('ipca_cvr_devices', (int)($dispatch['device_id'] ?? 0));
        $device['aircraft_registration'] = (string)($dispatch['aircraft_registration'] ?? '');
        $session = (new FlightSessionService($this->pdo))->sessionForDevice(
            $device,
            (string)$dispatch['workflow_flight_record_uuid']
        );
        $sessionId = (int)($session['id'] ?? 0);
        if ($sessionId <= 0) {
            throw new RuntimeException('Could not establish the canonical Flight Session.');
        }
        if ($this->columnExists('ipca_garmin_csv_files', 'session_id')) {
            $this->pdo->prepare('UPDATE ipca_garmin_csv_files SET session_id = ? WHERE id = ?')
                ->execute(array($sessionId, (int)$garmin['id']));
        }
        if ($this->columnExists('ipca_cockpit_recordings', 'flight_session_uid')) {
            $this->pdo->prepare('UPDATE ipca_cockpit_recordings SET flight_session_uid = ? WHERE id = ?')
                ->execute(array((string)$session['session_uuid'], (int)$recording['id']));
        }
        if ($this->columnExists('ipca_cockpit_recordings', 'g3x_storage_path')) {
            $resolved = $this->resolveStoredGarminPath((string)($garmin['storage_path'] ?? ''));
            $this->pdo->prepare('UPDATE ipca_cockpit_recordings SET g3x_storage_path = ? WHERE id = ?')
                ->execute(array($resolved['relative'], (int)$recording['id']));
        }
        $versionId = null;
        try {
            $derived = (new FlightRecordDerivationService($this->pdo))->deriveFromCsvFile((int)$garmin['id']);
            $versionId = (int)($derived['version']['id'] ?? $derived['flight_record_version']['id'] ?? 0) ?: null;
            if ($versionId !== null) {
                $missionStatement = $this->pdo->prepare(
                    'SELECT current_version_id FROM ipca_missions WHERE UPPER(code) = UPPER(?) LIMIT 1'
                );
                $missionStatement->execute(array((string)($dispatch['mission_code'] ?? '')));
                $missionVersionId = (int)$missionStatement->fetchColumn();
                if ($missionVersionId > 0) {
                    (new MissionCatalogService($this->pdo))->assignMission(
                        $versionId,
                        null,
                        $missionVersionId,
                        null,
                        null,
                        'manual_cvr_bundle',
                        1.0,
                        $actorUserId
                    );
                }
            }
        } catch (Throwable $e) {
            $this->pdo->prepare('UPDATE ipca_manual_intake_bundles SET processing_error = ? WHERE id = ?')
                ->execute(array('Flight Record derivation pending: ' . $e->getMessage(), $bundleId));
        }
        $this->pdo->prepare(
            'UPDATE ipca_manual_intake_bundles
             SET status = \'reconstruction_ready\', operational_flight_record_version_id = ?,
                 replay_status = \'queued\', started_at = CURRENT_TIMESTAMP(3)
             WHERE id = ? AND status = \'frozen\''
        )->execute(array($versionId, $bundleId));
        $this->audit($bundleId, 'reconstruction_prepared', $actorUserId, array(
            'recording_id' => (int)$recording['id'],
            'session_id' => $sessionId,
            'operational_flight_record_version_id' => $versionId,
        ));
    }

    /** @param array<string,mixed> $row */
    private function assertAllowedSource(string $type, array $row): void
    {
        $encoded = strtolower(AuditEventService::jsonEncode($row));
        if (str_contains($encoded, 'flightcircle') || str_contains($encoded, 'historical')) {
            throw new RuntimeException('FlightCircle and historical evidence are prohibited from Reconstruction bundles.');
        }
        if ($type === 'garmin_csv') {
            $allowed = array('iphone_files_import', 'cvr_app', 'ios_share', 'desktop_sync_agent', 'cvr_device', 'garmin_cloud', '');
            foreach (array('upload_source', 'source') as $field) {
                $value = strtolower(trim((string)($row[$field] ?? '')));
                if (!in_array($value, $allowed, true)) {
                    throw new RuntimeException('Garmin source is not allowed for a CVR Reconstruction bundle.');
                }
            }
        }
    }

    /** @param array<string,mixed> $recording @param array<string,mixed> $garmin @return list<string> */
    private function timeWarnings(array $recording, array $garmin): array
    {
        $warnings = array();
        $audioStart = strtotime((string)($recording['started_at'] ?? ''));
        $garminStart = strtotime((string)($garmin['first_valid_sample_utc'] ?? ''));
        $garminEnd = strtotime((string)($garmin['last_valid_sample_utc'] ?? ''));
        if ($audioStart !== false && $garminStart !== false && $garminEnd !== false) {
            $audioEnd = $audioStart + max(0, (int)round((float)($recording['duration_seconds'] ?? 0)));
            if ($audioEnd < $garminStart - 900 || $garminEnd < $audioStart - 900) {
                $warnings[] = 'Cockpit Audio and Garmin time windows do not overlap within 15 minutes.';
            }
        } else {
            $warnings[] = 'Time-overlap validation is incomplete because one source lacks timestamps.';
        }
        return $warnings;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function snapshot(string $type, string $table, array $row): array
    {
        if (!in_array($table, self::SOURCE_TABLES, true)) {
            throw new RuntimeException('Evidence source table is not allowlisted.');
        }
        $metadata = $this->canonicalize($row);
        $json = AuditEventService::jsonEncode($metadata);
        $uuid = (string)($row['dispatch_uuid'] ?? $row['recording_uid'] ?? $row['csv_file_uuid'] ?? $row['id'] ?? '');
        return array(
            'source_type' => $type,
            'source_table' => $table,
            'source_id' => (int)$row['id'],
            'source_uuid' => $uuid,
            'source_sha256' => hash('sha256', $json),
            'metadata' => $metadata,
        );
    }

    /** @return list<array<string,mixed>> */
    private function transcriptChunks(int $recordingId): array
    {
        if (!$this->tableExists('ipca_cockpit_recording_transcription_chunks')) {
            return array();
        }
        $statement = $this->pdo->prepare(
            'SELECT chunk_index, start_seconds, end_seconds, status, transcript_text
             FROM ipca_cockpit_recording_transcription_chunks
             WHERE recording_id = ? ORDER BY chunk_index'
        );
        $statement->execute(array($recordingId));
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /** @return array<string,mixed>|null */
    private function bundle(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM ipca_manual_intake_bundles WHERE id = ? LIMIT 1');
        $statement->execute(array($id));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function row(string $table, int $id): array
    {
        if (!in_array($table, array_values(self::SOURCE_TABLES), true) && $table !== 'ipca_cvr_devices') {
            throw new RuntimeException('Source table is not allowlisted.');
        }
        if ($id <= 0 || !$this->tableExists($table)) {
            throw new RuntimeException('Selected evidence source is unavailable.');
        }
        $statement = $this->pdo->prepare('SELECT * FROM `' . $table . '` WHERE id = ? LIMIT 1');
        $statement->execute(array($id));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Selected evidence source was not found.');
        }
        return $row;
    }

    /** @param array<string,mixed> $detail */
    private function audit(int $bundleId, string $eventType, ?int $actorUserId, array $detail): void
    {
        $this->pdo->prepare(
            'INSERT INTO ipca_manual_intake_bundle_audit
             (event_uuid, bundle_id, event_type, actor_user_id, detail_json)
             VALUES (?, ?, ?, ?, ?)'
        )->execute(array(
            AuditEventService::uuid(), $bundleId, $eventType, $actorUserId,
            AuditEventService::jsonEncode($detail),
        ));
        (new AuditEventService($this->pdo))->record(
            $eventType,
            'ipca_manual_intake_bundles',
            (string)$bundleId,
            null,
            $detail,
            'Immutable CVR Reconstruction bundle lifecycle event.',
            $actorUserId === null ? 'system' : 'user',
            $actorUserId,
            null,
            null,
            1,
            'cvr_reconstruction'
        );
    }

    private function requireSchema(): void
    {
        foreach (array(
            'ipca_manual_intake_bundles',
            'ipca_manual_intake_bundle_items',
            'ipca_cockpit_transcript_snapshots',
            'ipca_manual_intake_bundle_audit'
        ) as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('Manual Reconstruction schema is not installed.');
            }
        }
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute(array($table));
        return (int)$statement->fetchColumn() === 1;
    }

    private function columnExists(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute(array($table, $column));
        return (int)$statement->fetchColumn() === 1;
    }

    private function normalizeTail(string $tail): string
    {
        return strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', trim($tail)));
    }

    /** @return array{absolute:string,relative:string} */
    private function resolveStoredGarminPath(string $storedPath): array
    {
        $storedPath = trim($storedPath);
        if ($storedPath === '') {
            throw new RuntimeException('Selected Garmin CSV storage path is empty.');
        }
        $projectRoot = realpath(dirname(__DIR__));
        $storageRoot = realpath(dirname(__DIR__) . '/storage');
        if ($projectRoot === false || $storageRoot === false) {
            throw new RuntimeException('Server evidence storage is unavailable.');
        }
        $candidate = str_starts_with($storedPath, '/')
            ? $storedPath
            : $projectRoot . '/' . ltrim($storedPath, '/');
        $realPath = realpath($candidate);
        if ($realPath === false
            || !is_file($realPath)
            || !str_starts_with($realPath, $storageRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Selected Garmin CSV storage file is unavailable.');
        }
        return array(
            'absolute' => $realPath,
            'relative' => ltrim(substr($realPath, strlen($projectRoot)), DIRECTORY_SEPARATOR),
        );
    }

    private function uuid(string $value, string $label): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value)) {
            throw new RuntimeException($label . ' UUID is invalid.');
        }
        return $value;
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = array_is_list($item)
                    ? array_map(fn($entry) => is_array($entry) ? $this->canonicalize($entry) : $entry, $item)
                    : $this->canonicalize($item);
            }
        }
        ksort($value);
        return $value;
    }
}
