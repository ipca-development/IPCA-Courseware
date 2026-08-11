<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/FlightSessionService.php';
require_once __DIR__ . '/FlightRecordDerivationService.php';
require_once __DIR__ . '/MissionCatalogService.php';
require_once __DIR__ . '/AviationEvidence/ProcessingRunRepository.php';

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

    /** @return array<string,mixed>|null */
    public function bundleById(int $bundleId): ?array
    {
        return $this->bundle($bundleId);
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
    public function freezePreliminary(
        int $dispatchId,
        int $recordingId,
        ?int $actorUserId
    ): array {
        $this->requireSchema();
        $dispatch = $this->row(self::SOURCE_TABLES['dispatch'], $dispatchId);
        $recording = $this->row(self::SOURCE_TABLES['cockpit_audio'], $recordingId);
        $this->assertAllowedSource('dispatch', $dispatch);
        $this->assertAllowedSource('cockpit_audio', $recording);
        $workflowUuid = $this->uuid(
            (string)($dispatch['workflow_flight_record_uuid'] ?? ''),
            'Dispatch Flight Record'
        );
        $aircraft = $this->normalizeTail((string)($dispatch['aircraft_registration'] ?? ''));
        $recordingAircraft = $this->normalizeTail((string)($recording['aircraft_registration'] ?? ''));
        if ($aircraft === '') {
            throw new RuntimeException('Dispatch aircraft registration is required.');
        }
        if ($recordingAircraft !== '' && $recordingAircraft !== $aircraft) {
            throw new RuntimeException('Cockpit Audio aircraft does not match the Dispatch.');
        }

        $existing = $this->pdo->prepare(
            'SELECT * FROM ipca_manual_intake_bundles
             WHERE dispatch_id = ? AND cockpit_recording_id = ?
               AND garmin_csv_file_id IS NULL AND evidence_stage = \'preliminary\'
             ORDER BY id DESC LIMIT 1'
        );
        $existing->execute(array($dispatchId, $recordingId));
        $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($existingRow)) {
            return $existingRow;
        }

        $items = array(
            'dispatch' => $this->snapshot('dispatch', self::SOURCE_TABLES['dispatch'], $dispatch),
            'cockpit_audio' => $this->snapshot('cockpit_audio', self::SOURCE_TABLES['cockpit_audio'], $recording),
        );
        $manifest = array(
            'schema_version' => 2,
            'evidence_stage' => 'preliminary',
            'flight_record_uuid' => $workflowUuid,
            'aircraft_registration' => $aircraft,
            'mission_code' => (string)($dispatch['mission_code'] ?? ''),
            'sources' => array_values($items),
            'garmin_required' => false,
            'flightcircle_excluded' => true,
        );
        $manifestJson = AuditEventService::jsonEncode($this->canonicalize($manifest));
        $manifestHash = hash('sha256', $manifestJson);

        $this->pdo->beginTransaction();
        try {
            $sequence = $this->pdo->prepare(
                'SELECT COALESCE(MAX(version_number), 0) + 1
                 FROM ipca_manual_intake_bundles
                 WHERE workflow_flight_record_uuid = ? FOR UPDATE'
            );
            $sequence->execute(array($workflowUuid));
            $version = max(1, (int)$sequence->fetchColumn());
            $supersedes = null;
            if ($version > 1) {
                $previous = $this->pdo->prepare(
                    'SELECT id FROM ipca_manual_intake_bundles
                     WHERE workflow_flight_record_uuid = ?
                     ORDER BY version_number DESC LIMIT 1'
                );
                $previous->execute(array($workflowUuid));
                $supersedes = (int)$previous->fetchColumn() ?: null;
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO ipca_manual_intake_bundles
                 (bundle_uuid, version_number, supersedes_bundle_id, status, evidence_stage,
                  dispatch_id, cockpit_recording_id, garmin_csv_file_id,
                  workflow_flight_record_uuid, aircraft_registration, mission_code,
                  manifest_sha256, manifest_json, validation_warnings_json,
                  created_by, frozen_at, started_at)
                 VALUES (?, ?, ?, \'preliminary_ready\', \'preliminary\', ?, ?, NULL,
                         ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3))'
            );
            $insert->execute(array(
                AuditEventService::uuid(),
                $version,
                $supersedes,
                $dispatchId,
                $recordingId,
                $workflowUuid,
                $aircraft,
                (string)($dispatch['mission_code'] ?? ''),
                $manifestHash,
                $manifestJson,
                AuditEventService::jsonEncode(array(
                    'Preliminary debrief excludes Garmin flight-data evidence.',
                )),
                $actorUserId,
            ));
            $bundleId = (int)$this->pdo->lastInsertId();
            $itemInsert = $this->pdo->prepare(
                'INSERT INTO ipca_manual_intake_bundle_items
                 (bundle_id, source_type, source_table, source_id, source_uuid,
                  source_sha256, metadata_snapshot_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($items as $item) {
                $itemInsert->execute(array(
                    $bundleId,
                    $item['source_type'],
                    $item['source_table'],
                    $item['source_id'],
                    $item['source_uuid'],
                    $item['source_sha256'],
                    AuditEventService::jsonEncode($item['metadata']),
                ));
            }
            $this->audit($bundleId, 'preliminary_bundle_frozen', $actorUserId, array(
                'manifest_sha256' => $manifestHash,
                'garmin_required' => false,
            ));
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $this->bundle($bundleId) ?? array();
    }

    /** @return array<string,mixed> */
    public function supersedeBundleSources(
        int $bundleId,
        int $dispatchId,
        int $recordingId,
        int $garminCsvId,
        bool $includeAdsb,
        ?int $actorUserId
    ): array {
        $existing = $this->bundle($bundleId);
        if (!$existing) {
            throw new RuntimeException('Reconstruction bundle not found.');
        }
        $bundle = $this->freezeAndPrepare(
            $dispatchId > 0 ? $dispatchId : (int)$existing['dispatch_id'],
            $recordingId > 0 ? $recordingId : (int)$existing['cockpit_recording_id'],
            $garminCsvId > 0 ? $garminCsvId : (int)$existing['garmin_csv_file_id'],
            $includeAdsb,
            $actorUserId
        );
        if ((int)($bundle['id'] ?? 0) > 0) {
            $this->audit((int)$bundle['id'], 'bundle_superseded_sources', $actorUserId, array(
                'supersedes_bundle_id' => $bundleId,
                'dispatch_id' => (int)($bundle['dispatch_id'] ?? 0),
                'cockpit_recording_id' => (int)($bundle['cockpit_recording_id'] ?? 0),
                'garmin_csv_file_id' => (int)($bundle['garmin_csv_file_id'] ?? 0),
            ));
        }
        return $bundle;
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

    /** @return array<string,mixed> */
    public function rebuildFlightRecord(int $bundleId, ?int $actorUserId): array
    {
        $bundle = $this->bundle($bundleId);
        if (!$bundle) {
            throw new RuntimeException('Reconstruction bundle not found.');
        }
        try {
            $versionId = $this->deriveFlightRecordVersion(
                (int)$bundle['garmin_csv_file_id'],
                (string)($bundle['mission_code'] ?? ''),
                $actorUserId
            );
            $this->pdo->prepare(
                'UPDATE ipca_manual_intake_bundles
                 SET operational_flight_record_version_id = ?, processing_error = NULL WHERE id = ?'
            )->execute(array($versionId, $bundleId));
            $this->audit($bundleId, 'flight_record_rebuilt', $actorUserId, array(
                'operational_flight_record_version_id' => $versionId,
            ));
        } catch (Throwable $e) {
            $this->pdo->prepare(
                'UPDATE ipca_manual_intake_bundles SET processing_error = ? WHERE id = ?'
            )->execute(array('Flight Record rebuild failed: ' . $e->getMessage(), $bundleId));
            $this->audit($bundleId, 'flight_record_rebuild_failed', $actorUserId, array(
                'error' => $e->getMessage(),
            ));
            throw $e;
        }
        return $this->bundle($bundleId) ?? array();
    }

    /** @return array{recording_id:int,g3x_csv_path:string,g3x_sha256:string} */
    public function reconstructionSource(int $bundleId): array
    {
        $bundle = $this->bundle($bundleId);
        if (!$bundle || !in_array((string)($bundle['status'] ?? ''), array(
            'reconstruction_ready',
            'reconstruction_complete',
            'processing',
        ), true)) {
            throw new RuntimeException('Frozen Reconstruction bundle is unavailable.');
        }
        $statement = $this->pdo->prepare(
            'SELECT metadata_snapshot_json
             FROM ipca_manual_intake_bundle_items
             WHERE bundle_id = ? AND source_type = \'garmin_csv\'
               AND source_table = \'ipca_garmin_csv_files\' LIMIT 1'
        );
        $statement->execute(array($bundleId));
        $metadata = json_decode((string)$statement->fetchColumn(), true);
        if (!is_array($metadata)) {
            throw new RuntimeException('Frozen Garmin source metadata is unavailable.');
        }
        if ((int)($metadata['id'] ?? 0) !== (int)$bundle['garmin_csv_file_id']) {
            throw new RuntimeException('Frozen Garmin source does not match the bundle.');
        }
        $resolved = $this->resolveStoredGarminPath((string)($metadata['storage_path'] ?? ''));
        $expectedHash = strtolower(trim((string)($metadata['sha256'] ?? '')));
        $actualHash = strtolower((string)(hash_file('sha256', $resolved['absolute']) ?: ''));
        if ($expectedHash !== '' && !hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException('Frozen Garmin CSV hash verification failed.');
        }
        return array(
            'recording_id' => (int)$bundle['cockpit_recording_id'],
            'g3x_csv_path' => $resolved['absolute'],
            'g3x_sha256' => $actualHash,
        );
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
                   ,(SELECT j.progress FROM ipca_cockpit_reconstruction_jobs j
                    WHERE j.recording_id = b.cockpit_recording_id ORDER BY j.id DESC LIMIT 1) AS latest_job_progress
                   ,(SELECT j.progress_message FROM ipca_cockpit_reconstruction_jobs j
                    WHERE j.recording_id = b.cockpit_recording_id ORDER BY j.id DESC LIMIT 1) AS latest_job_message
                   ,(SELECT j.error_message FROM ipca_cockpit_reconstruction_jobs j
                    WHERE j.recording_id = b.cockpit_recording_id ORDER BY j.id DESC LIMIT 1) AS latest_job_error
                   ,(SELECT a.id FROM ipca_async_jobs a
                    WHERE a.job_type = \'generate_structured_debrief\'
                      AND a.entity_type = \'ipca_manual_intake_bundles\'
                      AND CAST(a.entity_id AS UNSIGNED) = b.id
                    ORDER BY a.id DESC LIMIT 1) AS debrief_job_id
                   ,(SELECT a.status FROM ipca_async_jobs a
                    WHERE a.job_type = \'generate_structured_debrief\'
                      AND a.entity_type = \'ipca_manual_intake_bundles\'
                      AND CAST(a.entity_id AS UNSIGNED) = b.id
                    ORDER BY a.id DESC LIMIT 1) AS debrief_job_status
                   ,(SELECT a.payload_json FROM ipca_async_jobs a
                    WHERE a.job_type = \'generate_structured_debrief\'
                      AND a.entity_type = \'ipca_manual_intake_bundles\'
                      AND CAST(a.entity_id AS UNSIGNED) = b.id
                    ORDER BY a.id DESC LIMIT 1) AS debrief_job_payload
                   ,(SELECT a.last_error FROM ipca_async_jobs a
                    WHERE a.job_type = \'generate_structured_debrief\'
                      AND a.entity_type = \'ipca_manual_intake_bundles\'
                      AND CAST(a.entity_id AS UNSIGNED) = b.id
                    ORDER BY a.id DESC LIMIT 1) AS debrief_job_error
            FROM ipca_manual_intake_bundles b
            INNER JOIN ipca_cockpit_recordings r ON r.id = b.cockpit_recording_id
            ORDER BY b.id DESC LIMIT ' . max(1, min(100, $limit));
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: array();
        foreach ($rows as &$row) {
            $payload = json_decode((string)($row['debrief_job_payload'] ?? ''), true);
            if (!is_array($payload)) {
                $payload = array();
            }
            $status = strtolower(trim((string)($row['debrief_job_status'] ?? '')));
            $progress = max(0, min(100, (int)($payload['progress'] ?? 0)));
            $message = trim((string)($payload['progress_message'] ?? ''));
            if ($status === 'succeeded') {
                $progress = 100;
                if ($message === '') {
                    $message = 'Ready';
                }
            } elseif (in_array($status, array('pending', 'claimed', 'running', 'retry_wait'), true) && $progress <= 0) {
                $progress = 5;
                if ($message === '') {
                    $message = 'Queued';
                }
            }
            $row['debrief_job_progress'] = $progress;
            $row['debrief_job_message'] = $message;
        }
        unset($row);
        return $rows;
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
        $recordingId = (int)$bundle['cockpit_recording_id'];
        $recording = $this->row('ipca_cockpit_recordings', $recordingId);
        if (strtolower((string)($recording['transcription_status'] ?? '')) !== 'ready') {
            return array('ready' => false, 'reason' => 'Transcription is not Ready yet.', 'snapshot_id' => null);
        }
        $processingRuns = new ProcessingRunRepository($this->pdo);
        if ($processingRuns->findLatestPublishableForRecording($recordingId) === null) {
            return array('ready' => false, 'reason' => 'Pass 4 readable transcript is still processing.', 'snapshot_id' => null);
        }
        $text = trim((string)($recording['transcript_text'] ?? ''));
        if ($text === '') {
            return array('ready' => false, 'reason' => 'Readable transcript is empty.', 'snapshot_id' => null);
        }
        return array('ready' => false, 'reason' => 'Readable transcript is ready but must be version-locked.', 'snapshot_id' => null);
    }

    public function hasCompleteLiveTranscript(int $recordingId): bool
    {
        if ($recordingId <= 0 || !$this->tableExists('ipca_cvr_live_audio_segments')) {
            return false;
        }
        $recording = $this->row('ipca_cockpit_recordings', $recordingId);
        $recordingUid = trim((string)($recording['recording_uid'] ?? ''));
        $recordingDuration = max(0.0, (float)($recording['duration_seconds'] ?? 0));
        if ($recordingUid === '' || $recordingDuration <= 0) {
            return false;
        }
        $statement = $this->pdo->prepare(
            'SELECT segment_index, duration_seconds, transcription_status, transcript_text
             FROM ipca_cvr_live_audio_segments
             WHERE recording_uid = ? ORDER BY segment_index'
        );
        $statement->execute(array($recordingUid));
        $segments = $statement->fetchAll(PDO::FETCH_ASSOC) ?: array();
        if ($segments === array()) {
            return false;
        }
        $expectedIndex = 1;
        $coveredDuration = 0.0;
        foreach ($segments as $segment) {
            if ((int)$segment['segment_index'] !== $expectedIndex
                || strtolower((string)$segment['transcription_status']) !== 'ready') {
                return false;
            }
            $coveredDuration += max(0.0, (float)$segment['duration_seconds']);
            $expectedIndex++;
        }
        return $coveredDuration >= max(1.0, $recordingDuration - 5.0);
    }

    public function lockLiveTranscript(int $bundleId, ?int $actorUserId): int
    {
        $bundle = $this->bundle($bundleId);
        if (!$bundle) {
            throw new RuntimeException('Bundle not found.');
        }
        if ((int)($bundle['transcript_snapshot_id'] ?? 0) > 0) {
            return (int)$bundle['transcript_snapshot_id'];
        }
        $recording = $this->row('ipca_cockpit_recordings', (int)$bundle['cockpit_recording_id']);
        if (!$this->hasCompleteLiveTranscript((int)$recording['id'])) {
            throw new RuntimeException('All in-flight audio segments must be transcribed before the preliminary snapshot is locked.');
        }
        $statement = $this->pdo->prepare(
            'SELECT id, segment_index, started_at, duration_seconds, sha256, transcript_text
             FROM ipca_cvr_live_audio_segments
             WHERE recording_uid = ? AND transcription_status = \'ready\'
             ORDER BY segment_index'
        );
        $statement->execute(array((string)$recording['recording_uid']));
        $segments = $statement->fetchAll(PDO::FETCH_ASSOC) ?: array();
        $parts = array();
        $manifest = array();
        $offset = 0.0;
        foreach ($segments as $segment) {
            $text = trim((string)($segment['transcript_text'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
            $duration = max(0.0, (float)$segment['duration_seconds']);
            $manifest[] = array(
                'live_segment_id' => (int)$segment['id'],
                'segment_index' => (int)$segment['segment_index'],
                'start_seconds' => round($offset, 3),
                'end_seconds' => round($offset + $duration, 3),
                'audio_sha256' => (string)$segment['sha256'],
                'text_sha256' => hash('sha256', $text),
                'provenance' => 'in_flight_finalized_audio_segment',
            );
            $offset += $duration;
        }
        $text = trim(implode("\n\n", $parts));
        if ($text === '') {
            throw new RuntimeException('In-flight segment transcript is empty.');
        }
        $hash = hash('sha256', AuditEventService::jsonEncode(array(
            'text' => $text,
            'chunks' => $manifest,
            'source' => 'live_incremental',
        )));
        $existing = $this->pdo->prepare(
            'SELECT id FROM ipca_cockpit_transcript_snapshots
             WHERE cockpit_recording_id = ? AND transcript_sha256 = ? LIMIT 1'
        );
        $existing->execute(array((int)$recording['id'], $hash));
        $snapshotId = (int)$existing->fetchColumn();
        if ($snapshotId <= 0) {
            $insert = $this->pdo->prepare(
                'INSERT INTO ipca_cockpit_transcript_snapshots
                 (snapshot_uuid, cockpit_recording_id, transcript_sha256, transcript_text,
                  chunks_manifest_json, source_status, word_count, locked_by)
                 VALUES (?, ?, ?, ?, ?, \'live_incremental_ready\', ?, ?)'
            );
            $insert->execute(array(
                AuditEventService::uuid(),
                (int)$recording['id'],
                $hash,
                $text,
                AuditEventService::jsonEncode($manifest),
                str_word_count($text),
                $actorUserId,
            ));
            $snapshotId = (int)$this->pdo->lastInsertId();
        }
        $this->pdo->prepare(
            'UPDATE ipca_manual_intake_bundles
             SET transcript_snapshot_id = ? WHERE id = ? AND transcript_snapshot_id IS NULL'
        )->execute(array($snapshotId, $bundleId));
        $this->audit($bundleId, 'live_incremental_transcript_locked', $actorUserId, array(
            'snapshot_id' => $snapshotId,
            'transcript_sha256' => $hash,
            'segment_count' => count($segments),
        ));
        return $snapshotId;
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
            throw new RuntimeException('Transcription must be Ready before locking.');
        }
        $processingRuns = new ProcessingRunRepository($this->pdo);
        if ($processingRuns->findLatestPublishableForRecording((int)$recording['id']) === null) {
            throw new RuntimeException('Pass 4 readable transcript must be ready before locking.');
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
        $operationalSessionUuid = strtolower(trim((string)($dispatch['operational_session_uuid'] ?? '')));
        $usesOperationalSession = $operationalSessionUuid !== '';
        if ($usesOperationalSession) {
            $session = (new FlightSessionService($this->pdo))->sessionByUuid($operationalSessionUuid);
            if (!is_array($session)
                || (string)($session['model_version'] ?? '') !== FlightSessionService::MODEL_OPERATIONAL_V1
                || (int)($session['device_id'] ?? 0) !== (int)($device['id'] ?? 0)
                || strtolower(trim((string)($session['workflow_flight_record_uuid'] ?? '')))
                    !== strtolower(trim((string)($dispatch['workflow_flight_record_uuid'] ?? '')))) {
                throw new RuntimeException('The canonical Operational Session identity is unavailable or inconsistent.');
            }
        } else {
            // Legacy bundles retain their historical Flight Session identity. Never use the
            // workflow Flight Record UUID as a new-session fallback for Operational Session v1.
            $session = (new FlightSessionService($this->pdo))->sessionForDevice(
                $device,
                (string)$dispatch['workflow_flight_record_uuid']
            );
        }
        $sessionId = (int)($session['id'] ?? 0);
        if ($sessionId <= 0) {
            throw new RuntimeException('Could not establish the canonical Flight Session.');
        }
        if ($this->columnExists('ipca_garmin_csv_files', 'session_id')) {
            $this->pdo->prepare('UPDATE ipca_garmin_csv_files SET session_id = ? WHERE id = ?')
                ->execute(array($sessionId, (int)$garmin['id']));
        }
        if ($usesOperationalSession && $this->columnExists('ipca_cockpit_recordings', 'operational_session_uuid')) {
            $this->pdo->prepare('UPDATE ipca_cockpit_recordings SET operational_session_uuid = ? WHERE id = ?')
                ->execute(array($operationalSessionUuid, (int)$recording['id']));
        } elseif ($this->columnExists('ipca_cockpit_recordings', 'flight_session_uid')) {
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
            $versionId = $this->deriveFlightRecordVersion(
                (int)$garmin['id'],
                (string)($dispatch['mission_code'] ?? ''),
                $actorUserId
            );
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

    private function deriveFlightRecordVersion(int $garminCsvFileId, string $missionCode, ?int $actorUserId): int
    {
        $derived = (new FlightRecordDerivationService($this->pdo))->deriveFromCsvFile($garminCsvFileId);
        $versionId = (int)(
            $derived['flight_record_version_id']
            ?? $derived['version']['id']
            ?? $derived['flight_record_version']['id']
            ?? 0
        );
        if ($versionId <= 0) {
            throw new RuntimeException('Flight Record derivation did not return a version.');
        }
        $missionStatement = $this->pdo->prepare(
            'SELECT current_version_id FROM ipca_missions WHERE UPPER(code) = UPPER(?) LIMIT 1'
        );
        $missionStatement->execute(array($missionCode));
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
        return $versionId;
    }

    /** @param array<string,mixed> $row */
    private function assertAllowedSource(string $type, array $row): void
    {
        $encoded = strtolower(AuditEventService::jsonEncode($row));
        if (str_contains($encoded, 'flightcircle') || str_contains($encoded, 'historical')) {
            throw new RuntimeException('FlightCircle and historical evidence are prohibited from Reconstruction bundles.');
        }
        if ($type === 'garmin_csv') {
            $allowed = array('iphone_files_import', 'cvr_app', 'ios_share', 'desktop_sync_agent', 'cvr_device', 'garmin_cloud', 'admin_manual', 'cvr_admin_intake', '');
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
