<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/AsyncJobService.php';
require_once __DIR__ . '/FlyGarminWebSessionProvider.php';
require_once __DIR__ . '/GarminCsvValidationService.php';
require_once __DIR__ . '/GarminFlightDataSourceClassificationService.php';
require_once __DIR__ . '/GarminFlightDataSourceService.php';
require_once __DIR__ . '/GarminProviderStateService.php';
require_once __DIR__ . '/GarminSourceGroupSelectionService.php';
require_once __DIR__ . '/GarminSyncRunService.php';
require_once __DIR__ . '/ValidationResultService.php';

final class GarminCloudIntegrationService
{
    public function __construct(
        private PDO $pdo,
        private ?GarminFlightDataProviderInterface $provider = null,
        private string $providerName = 'flygarmin_web'
    ) {
        $this->provider = $provider ?? new FlyGarminWebSessionProvider();
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $state = (new GarminProviderStateService($this->pdo, $this->providerName))->current();
        return array(
            'provider' => $state,
            'recent_runs' => (new GarminSyncRunService($this->pdo, $this->providerName))->recentRuns(),
            'entries' => (new GarminFlightDataSourceService($this->pdo, $this->providerName))->recentEntries(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function testConnection(?int $actorUserId = null): array
    {
        $result = $this->provider->testConnection();
        $state = new GarminProviderStateService($this->pdo, $this->providerName);
        $data = $result->data ?? array();
        $state->updateState(array(
            'worker_reachable' => $result->ok ? 1 : 0,
            'connection_status' => $result->ok ? 'reachable' : 'unreachable',
            'authentication_status' => $result->ok ? (string)($data['authentication_status'] ?? 'authenticated') : 'sync_error',
            'browser_profile_present' => !empty($data['browser_profile_present']) ? 1 : 0,
            'reauthentication_required' => !empty($data['reauthentication_required']) ? 1 : 0,
            'last_connection_test_at' => gmdate('Y-m-d H:i:s.v'),
            'last_error_code' => $result->error['code'] ?? null,
            'last_error_summary' => $result->error['message'] ?? null,
        ), 'garmin_worker_connection_tested', $actorUserId);
        if ($result->ok && (string)($data['authentication_status'] ?? 'authenticated') === 'authenticated') {
            $state->markAcceptanceCheck('worker_authentication_test', true, 'Worker reached Garmin logbook endpoint with authenticated browser profile.');
        }
        return $result->toArray();
    }

    /**
     * @return array<string,mixed>
     */
    public function runSync(string $syncType, string $triggerType = 'manual', ?int $actorUserId = null): array
    {
        if (!in_array($syncType, array('initial', 'incremental', 'reconciliation'), true)) {
            throw new InvalidArgumentException('Unknown Garmin sync type.');
        }
        $stateService = new GarminProviderStateService($this->pdo, $this->providerName);
        $state = $stateService->current();
        $syncRuns = new GarminSyncRunService($this->pdo, $this->providerName);
        $runId = $syncRuns->start($syncType, $triggerType, $actorUserId, (string)($state['last_version_cursor'] ?? ''));
        $stateService->updateState(array('last_attempted_sync_at' => gmdate('Y-m-d H:i:s.v')), 'garmin_sync_attempted', $actorUserId);

        try {
            $syncResult = match ($syncType) {
                'initial' => $this->provider->runInitialSync(),
                'incremental' => $this->provider->runIncrementalSync((string)($state['last_version_cursor'] ?? '')),
                'reconciliation' => $this->provider->runFullReconciliation(),
            };
            if (!$syncResult->ok()) {
                $error = $syncResult->providerResult->error ?? array('code' => 'GARMIN_SYNC_FAILED', 'message' => 'Garmin sync failed.');
                $syncRuns->fail($runId, (string)$error['code'], (string)$error['message']);
                return $syncResult->toArray();
            }
            $data = $syncResult->data();
            $entries = is_array($data['entries'] ?? null) ? $data['entries'] : array();
            $sourceService = new GarminFlightDataSourceService($this->pdo, $this->providerName);
            $dataLogsDiscovered = 0;
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $upserted = $sourceService->upsertEntryWithSources($entry);
                $dataLogsDiscovered += count($upserted['source_ids']);
                $syncRuns->item($runId, 'garmin_entry', (string)($entry['uuid'] ?? ($entry['id'] ?? 'unknown')), 'upserted', $upserted);
            }
            $cursorAfter = isset($data['cursor']) ? (string)$data['cursor'] : null;
            $syncRuns->succeed($runId, $cursorAfter, array(
                'entries_received' => count($entries),
                'entries_upserted' => count($entries),
                'data_logs_discovered' => $dataLogsDiscovered,
            ));
            $updates = array('last_successful_sync_at' => gmdate('Y-m-d H:i:s.v'));
            if ($cursorAfter !== null && $cursorAfter !== '') {
                $updates['last_version_cursor'] = $cursorAfter;
                $updates['last_version_cursor_decoded_at'] = gmdate('Y-m-d H:i:s.v');
            }
            if ($syncType === 'initial') {
                $updates['last_initial_sync_at'] = gmdate('Y-m-d H:i:s.v');
                $stateService->markAcceptanceCheck('initial_garmin_logbook_sync', true, 'Manual initial sync completed.');
            } elseif ($syncType === 'incremental') {
                $updates['last_incremental_sync_at'] = gmdate('Y-m-d H:i:s.v');
            } else {
                $updates['last_reconciliation_at'] = gmdate('Y-m-d H:i:s.v');
            }
            $stateService->updateState($updates, 'garmin_sync_succeeded', $actorUserId);
            if ($cursorAfter !== null && $cursorAfter !== '') {
                $stateService->markAcceptanceCheck('cursor_persistence', true, 'Garmin version cursor stored after sync.');
            }
            if ($dataLogsDiscovered > 0) {
                $stateService->markAcceptanceCheck('flight_data_uuid_discovered', true, $dataLogsDiscovered . ' flightDataLogUUID(s) discovered.');
            }
            return array('ok' => true, 'run_id' => $runId, 'entries_upserted' => count($entries), 'data_logs_discovered' => $dataLogsDiscovered, 'cursor_after' => $cursorAfter);
        } catch (Throwable $e) {
            $syncRuns->fail($runId, 'GARMIN_SYNC_EXCEPTION', $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function downloadSource(string $flightDataLogUuid, ?int $actorUserId = null): array
    {
        $sourceService = new GarminFlightDataSourceService($this->pdo, $this->providerName);
        $source = $sourceService->sourceByUuid($flightDataLogUuid);
        if ($source === null) {
            throw new RuntimeException('Garmin flight-data source not found.');
        }
        $this->pdo->prepare("UPDATE ipca_garmin_flight_data_sources SET download_status = 'downloading', updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?")
            ->execute(array((int)$source['id']));
        $download = $this->provider->downloadFlightDataLog($flightDataLogUuid);
        if (!$download->ok()) {
            $error = $download->providerResult->error ?? array('code' => 'GARMIN_DOWNLOAD_FAILED', 'message' => 'Garmin source download failed.');
            $this->pdo->prepare("
                UPDATE ipca_garmin_flight_data_sources
                SET download_status = ?,
                    retry_count = retry_count + 1,
                    next_retry_at = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL 30 MINUTE),
                    last_error_code = ?,
                    last_error_summary = ?,
                    updated_at = CURRENT_TIMESTAMP(3)
                WHERE id = ?
            ")->execute(array(
                (string)($download->providerResult->status === 'authentication_required' ? 'authentication_required' : 'failed'),
                (string)$error['code'],
                (string)$error['message'],
                (int)$source['id'],
            ));
            return $download->toArray();
        }
        $data = $download->data();
        $storedPath = $this->persistDownloadedSource($flightDataLogUuid, $data);
        $sha256 = hash_file('sha256', $storedPath);
        $fileSize = filesize($storedPath);
        $classification = (new GarminFlightDataSourceClassificationService($this->pdo))->classifyPath($storedPath);
        $csvFileId = $this->ensureCsvFile((int)$source['id'], $source, $storedPath, $sha256, (int)$fileSize, $data, $classification);
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
            (string)($data['filename'] ?? ($flightDataLogUuid . '.csv')),
            (string)($data['contentType'] ?? 'text/csv'),
            $sha256,
            (int)$fileSize,
            (int)$source['id'],
        ));
        $state = new GarminProviderStateService($this->pdo, $this->providerName);
        $state->markAcceptanceCheck('source_download', true, 'At least one Garmin source downloaded.');
        $state->markAcceptanceCheck('source_classification', true, 'At least one Garmin source classified.');
        $state->markAcceptanceCheck('immutable_evidence_storage', true, 'Original Garmin source stored immutably with SHA-256.');
        $state->markAcceptanceCheck('validation', true, 'At least one Garmin source validated.');
        $this->enqueueFollowupJobs((int)$source['id'], $csvFileId, $groupId);
        return array(
            'ok' => true,
            'source_id' => (int)$source['id'],
            'csv_file_id' => $csvFileId,
            'source_group_id' => $groupId,
            'sha256' => $sha256,
            'classification' => $classification,
            'validation' => $validation,
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    private function persistDownloadedSource(string $flightDataLogUuid, array $data): string
    {
        $dir = dirname(__DIR__) . '/storage/cvr/garmin_cloud/' . gmdate('Y/m/d');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create Garmin Cloud evidence storage directory.');
        }
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename((string)($data['filename'] ?? ($flightDataLogUuid . '.csv')))) ?: ($flightDataLogUuid . '.csv');
        $target = $dir . '/' . $flightDataLogUuid . '-' . $filename;
        if (isset($data['contentBase64']) && is_string($data['contentBase64'])) {
            $bytes = base64_decode($data['contentBase64'], true);
            if ($bytes === false) {
                throw new RuntimeException('Garmin worker returned invalid base64 content.');
            }
            file_put_contents($target, $bytes, LOCK_EX);
            return $target;
        }
        if (isset($data['content']) && is_string($data['content'])) {
            file_put_contents($target, $data['content'], LOCK_EX);
            return $target;
        }
        if (isset($data['localPath']) && is_string($data['localPath']) && is_file($data['localPath'])) {
            if (!copy($data['localPath'], $target)) {
                throw new RuntimeException('Could not copy Garmin worker download into immutable storage.');
            }
            return $target;
        }
        throw new RuntimeException('Garmin worker did not return downloadable source content.');
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $data
     * @param array<string,mixed> $classification
     */
    private function ensureCsvFile(int $sourceId, array $source, string $path, string $sha256, int $fileSize, array $data, array $classification): int
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
            VALUES
              (?, ?, 'garmin_cloud', 'garmin_cloud', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(array(
            AuditEventService::uuid(),
            (string)($entry['aircraft_registration'] ?? ''),
            $this->providerName,
            (string)($data['filename'] ?? (($source['flight_data_log_uuid'] ?? 'garmin') . '.csv')),
            $path,
            $sha256,
            $fileSize,
            (string)($data['contentType'] ?? 'text/csv'),
            (string)($classification['parser_profile'] ?? 'garmin_cloud'),
            (string)($classification['aircraft_ident'] ?? ($entry['aircraft_registration'] ?? '')),
            (string)($classification['product'] ?? 'Garmin Cloud'),
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
        $jobs->enqueue('GARMIN_SOURCE_GROUP_MATCH', 'ipca_garmin_source_groups', (string)$sourceGroupId, array('source_group_id' => $sourceGroupId));
        $jobs->enqueue('GARMIN_CSV_SESSION_MATCH', 'ipca_garmin_csv_files', (string)$csvFileId, array('csv_file_id' => $csvFileId, 'garmin_source_id' => $sourceId, 'source_group_id' => $sourceGroupId));
        $jobs->enqueue('GARMIN_SOURCE_ROLE_SELECTION', 'ipca_garmin_source_groups', (string)$sourceGroupId, array('source_group_id' => $sourceGroupId));
    }
}
