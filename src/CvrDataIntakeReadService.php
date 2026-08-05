<?php
declare(strict_types=1);

require_once __DIR__ . '/CvrOperationalIdentityReadService.php';
require_once __DIR__ . '/CvrOperationalBlockTimeService.php';

final class CvrDataIntakeReadService
{
    /** @var array<string,bool> */
    private array $tableCache = array();

    /** @var array<string,array<string,bool>> */
    private array $columnCache = array();

    private ?CvrOperationalIdentityReadService $identityRead = null;
    private ?CvrOperationalBlockTimeService $blockTimes = null;

    public function __construct(private PDO $pdo)
    {
    }

    private function identityRead(): CvrOperationalIdentityReadService
    {
        return $this->identityRead ??= new CvrOperationalIdentityReadService($this->pdo);
    }

    private function blockTimes(): CvrOperationalBlockTimeService
    {
        return $this->blockTimes ??= new CvrOperationalBlockTimeService();
    }

    /**
     * @return array{available:bool,rows:list<array<string,mixed>>,message:string}
     */
    public function dispatchRows(int $limit = 100): array
    {
        $table = $this->firstExistingTable(array('ipca_cvr_dispatches', 'ipca_cvr_dispatch_records'));
        if ($table === null) {
            return array(
                'available' => false,
                'rows' => array(),
                'message' => 'No Dispatch intake table is connected yet. Dispatch records created in the CVR app are still local-only.',
            );
        }

        $columns = $this->columns($table);
        $tableAlias = 'd';
        $quotedTable = $this->quoteIdentifier($table) . ' ' . $tableAlias;
        $deviceExpression = $table === 'ipca_cvr_dispatches'
            && isset($columns['device_id'])
            && $this->tableExists('ipca_cvr_devices')
            ? "COALESCE((SELECT dev.device_uuid FROM ipca_cvr_devices dev WHERE dev.id = {$tableAlias}.device_id LIMIT 1), '') AS device_identifier"
            : $this->prefixedColumnExpression($columns, $tableAlias, array('device_uuid', 'device_id'), 'device_identifier');
        $select = array(
            $this->prefixedColumnExpression($columns, $tableAlias, array('id'), 'id', '0'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('dispatch_uuid', 'dispatch_id', 'id'), 'dispatch_uuid'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('current_version', 'version', 'dispatch_version'), 'dispatch_version', '1'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('aircraft_registration', 'tail_number'), 'aircraft_registration'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('mission_code'), 'mission_code'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('status'), 'status'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('source', 'dispatch_source'), 'source'),
            $deviceExpression,
            $this->prefixedColumnExpression($columns, $tableAlias, array('crew_json', 'crew'), 'crew_json', 'NULL'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('error_message', 'last_error'), 'error_message'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('workflow_flight_record_uuid', 'flight_record_uuid'), 'workflow_flight_record_uuid'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('scheduler_record_id'), 'scheduler_record_id', 'NULL'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('organization_id'), 'organization_id', '0'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('last_received_at', 'received_at', 'created_at', 'updated_at'), 'received_at', 'NULL'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('starting_hobbs'), 'starting_hobbs', 'NULL'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('starting_tacho'), 'starting_tacho', 'NULL'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('fuel_onboard'), 'fuel_onboard', "''"),
        );
        if ($table === 'ipca_cvr_dispatches' && $this->tableExists('ipca_cvr_dispatch_versions')) {
            $select[] = "COALESCE((
                SELECT NULLIF(JSON_UNQUOTE(JSON_EXTRACT(v.payload_json, '$.planned_departure_airport')), 'null')
                FROM ipca_cvr_dispatch_versions v
                WHERE v.dispatch_id = {$tableAlias}.id
                  AND v.dispatch_version = {$tableAlias}.current_version
                LIMIT 1
            ), '') AS departure_airport";
            $select[] = "COALESCE((
                SELECT NULLIF(JSON_UNQUOTE(JSON_EXTRACT(v.payload_json, '$.planned_destination_airport')), 'null')
                FROM ipca_cvr_dispatch_versions v
                WHERE v.dispatch_id = {$tableAlias}.id
                  AND v.dispatch_version = {$tableAlias}.current_version
                LIMIT 1
            ), '') AS arrival_airport";
        } else {
            $select[] = "'' AS departure_airport";
            $select[] = "'' AS arrival_airport";
        }
        if ($this->tableExists('ipca_cvr_flight_events')
            && isset($columns['workflow_flight_record_uuid'])) {
            $flightUuidMatch = "LOWER(fe.workflow_flight_record_uuid) = LOWER({$tableAlias}.workflow_flight_record_uuid)";
            $closureOffFallback = 'NULL';
            $closureOnFallback = 'NULL';
            $endingHobbsExpression = 'NULL';
            $endingTachoExpression = 'NULL';
            $fuelRemainingExpression = 'NULL';
            $takeoffExpression = '0';
            $landingExpression = '0';
            if ($this->tableExists('ipca_cvr_flight_closures')) {
                $closureOffFallback = "(SELECT COALESCE(
                        JSON_UNQUOTE(JSON_EXTRACT(fc.payload_json, '$.evidence.off_block_utc')),
                        JSON_UNQUOTE(JSON_EXTRACT(fc.payload_json, '$.off_block_utc'))
                    )
                    FROM ipca_cvr_flight_closures fc
                    WHERE LOWER(fc.workflow_flight_record_uuid) = LOWER({$tableAlias}.workflow_flight_record_uuid)
                    ORDER BY fc.id DESC
                    LIMIT 1)";
                $closureOnFallback = "(SELECT COALESCE(
                        JSON_UNQUOTE(JSON_EXTRACT(fc.payload_json, '$.evidence.on_block_utc')),
                        JSON_UNQUOTE(JSON_EXTRACT(fc.payload_json, '$.on_block_utc'))
                    )
                    FROM ipca_cvr_flight_closures fc
                    WHERE LOWER(fc.workflow_flight_record_uuid) = LOWER({$tableAlias}.workflow_flight_record_uuid)
                      AND COALESCE(
                        JSON_UNQUOTE(JSON_EXTRACT(fc.payload_json, '$.evidence.on_block_source')),
                        JSON_UNQUOTE(JSON_EXTRACT(fc.payload_json, '$.on_block_source')),
                        ''
                      ) = 'off_block_plus_hobbs_increment'
                    ORDER BY fc.id DESC
                    LIMIT 1)";
                $endingHobbsExpression = "(SELECT fc.ending_hobbs
                    FROM ipca_cvr_flight_closures fc
                    WHERE LOWER(fc.workflow_flight_record_uuid) = LOWER({$tableAlias}.workflow_flight_record_uuid)
                    ORDER BY fc.id DESC
                    LIMIT 1)";
                $endingTachoExpression = "(SELECT fc.ending_tacho
                    FROM ipca_cvr_flight_closures fc
                    WHERE LOWER(fc.workflow_flight_record_uuid) = LOWER({$tableAlias}.workflow_flight_record_uuid)
                    ORDER BY fc.id DESC
                    LIMIT 1)";
                $fuelRemainingExpression = "(SELECT fc.fuel_remaining
                    FROM ipca_cvr_flight_closures fc
                    WHERE LOWER(fc.workflow_flight_record_uuid) = LOWER({$tableAlias}.workflow_flight_record_uuid)
                    ORDER BY fc.id DESC
                    LIMIT 1)";
                $takeoffExpression = "COALESCE((
                    SELECT CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(fc.payload_json, '$.evidence.verified_takeoff_count')), 'null') AS UNSIGNED)
                    FROM ipca_cvr_flight_closures fc
                    WHERE LOWER(fc.workflow_flight_record_uuid) = LOWER({$tableAlias}.workflow_flight_record_uuid)
                    ORDER BY fc.id DESC
                    LIMIT 1
                ), (
                    SELECT COUNT(*)
                    FROM ipca_cvr_flight_events te
                    WHERE LOWER(te.workflow_flight_record_uuid) = LOWER({$tableAlias}.workflow_flight_record_uuid)
                      AND te.event_type IN ('gps_takeoff_provisional', 'manual_takeoff_adjustment')
                ), 0)";
                $landingExpression = "COALESCE((
                    SELECT CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(fc.payload_json, '$.evidence.verified_landing_count')), 'null') AS UNSIGNED)
                    FROM ipca_cvr_flight_closures fc
                    WHERE LOWER(fc.workflow_flight_record_uuid) = LOWER({$tableAlias}.workflow_flight_record_uuid)
                    ORDER BY fc.id DESC
                    LIMIT 1
                ), (
                    SELECT COUNT(*)
                    FROM ipca_cvr_flight_events le
                    WHERE LOWER(le.workflow_flight_record_uuid) = LOWER({$tableAlias}.workflow_flight_record_uuid)
                      AND le.event_type IN ('gps_landing_provisional', 'manual_landing_adjustment')
                ), 0)";
            }
            $select[] = "COALESCE(
                (SELECT MIN(fe.timestamp_utc) FROM ipca_cvr_flight_events fe
                    WHERE {$flightUuidMatch}
                      AND fe.event_type = 'engine_start_off_block'),
                {$closureOffFallback}
            ) AS off_block_utc";
            // Raw button-press ON times are intentionally not selected. ON Block is
            // derived as OFF Block + (Ending Hobbs − Starting Hobbs), matching the CVR app.
            $select[] = "{$closureOnFallback} AS closure_on_block_utc";
            $select[] = "{$endingHobbsExpression} AS ending_hobbs";
            $select[] = "{$endingTachoExpression} AS ending_tacho";
            $select[] = "{$fuelRemainingExpression} AS fuel_remaining";
            $select[] = "{$takeoffExpression} AS takeoff_count";
            $select[] = "{$landingExpression} AS landing_count";
            $select[] = "(SELECT
                    ROUND(TIMESTAMPDIFF(SECOND,
                        MIN(CASE WHEN fe.event_type IN ('gps_takeoff_provisional', 'manual_takeoff_adjustment') THEN fe.timestamp_utc END),
                        MAX(CASE WHEN fe.event_type IN ('gps_landing_provisional', 'manual_landing_adjustment') THEN fe.timestamp_utc END)
                    ) / 3600, 2)
                FROM ipca_cvr_flight_events fe
                WHERE {$flightUuidMatch}
            ) AS airborne_time_hours";
            $select[] = $this->tableExists('ipca_cvr_flight_closures')
                ? "EXISTS(
                    SELECT 1 FROM ipca_cvr_flight_closures fc
                    WHERE LOWER(fc.workflow_flight_record_uuid) = LOWER({$tableAlias}.workflow_flight_record_uuid)
                    LIMIT 1
                ) AS has_closure"
                : '0 AS has_closure';
            $select[] = $this->tableExists('ipca_cvr_recorder_verifications')
                ? "EXISTS(
                    SELECT 1 FROM ipca_cvr_recorder_verifications rv
                    WHERE LOWER(rv.workflow_flight_record_uuid) = LOWER({$tableAlias}.workflow_flight_record_uuid)
                    LIMIT 1
                ) AS has_recorder_verification"
                : '0 AS has_recorder_verification';
        } else {
            $select[] = 'NULL AS off_block_utc';
            $select[] = 'NULL AS closure_on_block_utc';
            $select[] = 'NULL AS ending_hobbs';
            $select[] = 'NULL AS ending_tacho';
            $select[] = 'NULL AS fuel_remaining';
            $select[] = '0 AS takeoff_count';
            $select[] = '0 AS landing_count';
            $select[] = 'NULL AS airborne_time_hours';
            $select[] = '0 AS has_closure';
            $select[] = '0 AS has_recorder_verification';
        }
        if ($table === 'ipca_cvr_dispatches' && $this->tableExists('ipca_cvr_dispatch_versions')) {
            $select[] = "COALESCE((
                SELECT v.receipt_uuid
                FROM ipca_cvr_dispatch_versions v
                WHERE v.dispatch_id = {$tableAlias}.id
                  AND v.dispatch_version = {$tableAlias}.current_version
                LIMIT 1
            ), '') AS server_receipt_id";
        } else {
            $select[] = "'' AS server_receipt_id";
        }
        $orderColumn = $this->firstColumn($columns, array('last_received_at', 'received_at', 'created_at', 'updated_at', 'id')) ?? 'id';
        $sql = 'SELECT ' . implode(', ', $select)
            . ' FROM ' . $quotedTable
            . ' ORDER BY ' . $tableAlias . '.' . $this->quoteIdentifier($orderColumn) . ' DESC'
            . ' LIMIT ' . $this->normalizeLimit($limit);

        $rows = $this->fetchAll($sql);
        $audioByFlight = $this->audioStatusByFlightRecord(
            array_values(array_filter(array_map(
                static fn(array $row): string => strtolower(trim((string)($row['workflow_flight_record_uuid'] ?? ''))),
                $rows
            )))
        );
        $projected = array();
        foreach ($rows as $row) {
            $row['on_block_utc'] = $this->blockTimes()->derivedOnBlockUtc($row);
            $row['engine_time_hours'] = $this->blockTimes()->engineTimeHours(
                $row['starting_hobbs'] ?? null,
                $row['ending_hobbs'] ?? null
            );
            $flightKey = strtolower(trim((string)($row['workflow_flight_record_uuid'] ?? '')));
            $audio = $audioByFlight[$flightKey] ?? array(
                'upload_status' => 'missing',
                'transcription_status' => 'pending',
            );
            $presentation = $this->blockTimes()->presentationStatuses(
                trim((string)($row['server_receipt_id'] ?? '')) !== '',
                !empty($row['has_closure']),
                !empty($row['has_recorder_verification']),
                (string)($audio['upload_status'] ?? 'missing'),
                (string)($audio['transcription_status'] ?? 'pending')
            );
            $row['audio_upload_status'] = (string)($audio['upload_status'] ?? 'missing');
            $row['transcript_status'] = (string)($audio['transcription_status'] ?? 'pending');
            $row['sync_status'] = $presentation['sync_status'];
            $row['dispatch_status_label'] = $presentation['dispatch_status'];
            $row['audio_status_label'] = $presentation['audio_status'];
            $row['transcript_status_label'] = $presentation['transcript_status'];
            $row['crew_members'] = $this->blockTimes()->parseCrew($row['crew_json'] ?? null);
            $organizationId = (int)($row['organization_id'] ?? 0);
            $projection = $this->identityRead()->projectLegIdentity(
                $organizationId,
                isset($row['dispatch_uuid']) ? (string)$row['dispatch_uuid'] : null,
                isset($row['dispatch_version']) ? (string)$row['dispatch_version'] : null,
                isset($row['workflow_flight_record_uuid']) ? (string)$row['workflow_flight_record_uuid'] : null
            );
            $projected[] = $this->identityRead()->mergeProjection($row, $projection);
        }

        return array(
            'available' => true,
            'rows' => $projected,
            'message' => '',
        );
    }

    /**
     * Batch audio/transcript status for many flight-record UUIDs (avoids N+1).
     *
     * @param list<string> $flightRecordUuids
     * @return array<string,array{upload_status:string,transcription_status:string}>
     */
    private function audioStatusByFlightRecord(array $flightRecordUuids): array
    {
        $flightRecordUuids = array_values(array_unique(array_filter($flightRecordUuids)));
        if ($flightRecordUuids === array() || !$this->tableExists('ipca_cockpit_recordings')) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($flightRecordUuids), '?'));
        $sql = "
            SELECT
                LOWER(flight_session_uid) AS flight_key,
                CASE
                    WHEN SUM(upload_status = 'failed') > 0 THEN 'failed'
                    WHEN SUM(upload_status = 'uploaded') = COUNT(*) THEN 'uploaded'
                    WHEN SUM(upload_status = 'uploading') > 0 THEN 'uploading'
                    ELSE 'pending'
                END AS upload_status,
                CASE
                    WHEN SUM(transcription_status = 'failed') > 0 THEN 'failed'
                    WHEN SUM(transcription_status = 'ready') = COUNT(*) THEN 'ready'
                    WHEN SUM(transcription_status IN ('queued', 'transcribing')) > 0 THEN 'transcribing'
                    ELSE 'pending'
                END AS transcription_status
            FROM ipca_cockpit_recordings
            WHERE flight_session_uid IS NOT NULL
              AND flight_session_uid <> ''
              AND LOWER(flight_session_uid) IN ({$placeholders})
            GROUP BY LOWER(flight_session_uid)
        ";
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($flightRecordUuids);
            $map = array();
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = strtolower(trim((string)($row['flight_key'] ?? '')));
                if ($key === '') {
                    continue;
                }
                $map[$key] = array(
                    'upload_status' => (string)($row['upload_status'] ?? 'pending'),
                    'transcription_status' => (string)($row['transcription_status'] ?? 'pending'),
                );
            }
            return $map;
        } catch (Throwable) {
            return array();
        }
    }

    /**
     * @deprecated Use CvrOperationalBlockTimeService::derivedOnBlockUtc()
     * @param array<string,mixed> $row
     */
    private function derivedOnBlockUtc(array $row): ?string
    {
        return $this->blockTimes()->derivedOnBlockUtc($row);
    }

    /**
     * @return array{available:bool,rows:list<array<string,mixed>>,message:string}
     */
    public function audioRows(int $limit = 100): array
    {
        $table = 'ipca_cockpit_recordings';
        if (!$this->tableExists($table)) {
            return array(
                'available' => false,
                'rows' => array(),
                'message' => 'Cockpit Audio storage is not installed.',
            );
        }

        $columns = $this->columns($table);
        $tableAlias = 'r';
        $select = array(
            $this->prefixedColumnExpression($columns, $tableAlias, array('id'), 'id', '0'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('recording_uid'), 'recording_uid'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('aircraft_registration', 'aircraft_ident'), 'aircraft_registration'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('flight_session_uid', 'session_uuid'), 'session_uuid'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('started_at'), 'started_at', 'NULL'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('duration_seconds'), 'duration_seconds', '0'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('input_device'), 'input_device'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('intake_source'), 'intake_source'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('intake_mission_code'), 'intake_mission_code'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('intake_crew_json'), 'intake_crew_json', 'NULL'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('original_filename'), 'original_filename'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('file_size_bytes'), 'file_size_bytes', '0'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('storage_path'), 'storage_path'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('recording_events_storage_path'), 'recording_events_storage_path'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('upload_status'), 'upload_status'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('transcription_status'), 'transcription_status'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('transcription_progress'), 'transcription_progress', '0'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('error_message'), 'error_message'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('uploaded_at', 'created_at'), 'received_at', 'NULL'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('created_at'), 'created_at', 'NULL'),
        );

        $dispatchTable = $this->firstExistingTable(array('ipca_cvr_dispatches', 'ipca_cvr_dispatch_records'));
        $sessionColumn = $this->firstColumn($columns, array('flight_session_uid', 'session_uuid'));
        if ($dispatchTable !== null && $sessionColumn !== null) {
            $dispatchColumns = $this->columns($dispatchTable);
            $dispatchAlias = 'd';
            $dispatchFlightColumn = $this->firstColumn($dispatchColumns, array('workflow_flight_record_uuid', 'flight_record_uuid'));
            $missionColumn = $this->firstColumn($dispatchColumns, array('mission_code'));
            $crewColumn = $this->firstColumn($dispatchColumns, array('crew_json', 'crew'));
            $receivedColumn = $this->firstColumn($dispatchColumns, array('last_received_at', 'received_at', 'created_at', 'updated_at')) ?? 'id';
            if ($dispatchFlightColumn !== null && $missionColumn !== null) {
                $select[] = "(SELECT {$dispatchAlias}." . $this->quoteIdentifier($missionColumn) . "
                    FROM " . $this->quoteIdentifier($dispatchTable) . " {$dispatchAlias}
                    WHERE {$dispatchAlias}." . $this->quoteIdentifier($dispatchFlightColumn) . " = {$tableAlias}." . $this->quoteIdentifier($sessionColumn) . "
                    ORDER BY {$dispatchAlias}." . $this->quoteIdentifier($receivedColumn) . " DESC
                    LIMIT 1) AS dispatch_mission_code";
            } else {
                $select[] = "'' AS dispatch_mission_code";
            }
            if ($dispatchFlightColumn !== null && $crewColumn !== null) {
                $select[] = "(SELECT {$dispatchAlias}." . $this->quoteIdentifier($crewColumn) . "
                    FROM " . $this->quoteIdentifier($dispatchTable) . " {$dispatchAlias}
                    WHERE {$dispatchAlias}." . $this->quoteIdentifier($dispatchFlightColumn) . " = {$tableAlias}." . $this->quoteIdentifier($sessionColumn) . "
                    ORDER BY {$dispatchAlias}." . $this->quoteIdentifier($receivedColumn) . " DESC
                    LIMIT 1) AS dispatch_crew_json";
            } else {
                $select[] = 'NULL AS dispatch_crew_json';
            }
        } else {
            $select[] = "'' AS dispatch_mission_code";
            $select[] = 'NULL AS dispatch_crew_json';
        }

        $orderColumn = $this->firstColumn($columns, array('uploaded_at', 'created_at', 'id')) ?? 'id';
        $sql = 'SELECT ' . implode(', ', $select)
            . ' FROM ' . $this->quoteIdentifier($table) . ' ' . $tableAlias
            . ' ORDER BY ' . $tableAlias . '.' . $this->quoteIdentifier($orderColumn) . ' DESC'
            . ' LIMIT ' . $this->normalizeLimit($limit);

        return array(
            'available' => true,
            'rows' => $this->fetchAll($sql),
            'message' => '',
        );
    }

    /**
     * @return array{available:bool,rows:list<array<string,mixed>>,message:string}
     */
    public function garminRows(int $limit = 100): array
    {
        $table = 'ipca_garmin_csv_files';
        if (!$this->tableExists($table)) {
            return array(
                'available' => false,
                'rows' => array(),
                'message' => 'Garmin CSV storage is not installed.',
            );
        }

        $columns = $this->columns($table);
        $validationJoin = '';
        $validationSelect = "'' AS validation_status, '' AS validation_severity";
        if ($this->tableExists('ipca_garmin_csv_validation_results')) {
            $validationJoin = "
                LEFT JOIN ipca_garmin_csv_validation_results v
                  ON v.id = (
                    SELECT v2.id
                    FROM ipca_garmin_csv_validation_results v2
                    WHERE v2.csv_file_id = f.id
                    ORDER BY v2.id DESC
                    LIMIT 1
                  )";
            $validationSelect = "COALESCE(v.status, '') AS validation_status, COALESCE(v.severity, '') AS validation_severity";
        }

        $uploadSourceExpression = isset($columns['upload_source'])
            ? "COALESCE(f.upload_source, '')"
            : "CASE WHEN COALESCE(f.source, '') = 'garmin_cloud' THEN 'desktop_sync_agent' ELSE 'iphone_files_import' END";
        $sourceExpression = isset($columns['source']) ? "COALESCE(f.source, '')" : "''";
        $providerExpression = isset($columns['provider_name']) ? "COALESCE(f.provider_name, '')" : "''";
        $receivedExpression = isset($columns['created_at'])
            ? 'f.created_at'
            : (isset($columns['updated_at']) ? 'f.updated_at' : 'NULL');

        $where = "(
              {$uploadSourceExpression} IN ('iphone_files_import','cvr_app','ios_share','desktop_sync_agent','admin_manual')
              OR {$sourceExpression} IN ('iphone_files_import','cvr_device','cvr_app','garmin_cloud','cvr_admin_intake')
            )
            AND LOWER({$sourceExpression}) NOT LIKE '%historical%'
            AND LOWER({$sourceExpression}) NOT LIKE '%flightcircle%'";

        $select = array(
            $this->prefixedColumnExpression($columns, 'f', array('id'), 'id', '0'),
            $this->prefixedColumnExpression($columns, 'f', array('csv_file_uuid'), 'csv_file_uuid'),
            $this->prefixedColumnExpression($columns, 'f', array('original_filename'), 'original_filename'),
            $this->prefixedColumnExpression($columns, 'f', array('aircraft_registration', 'aircraft_ident'), 'aircraft_registration'),
            $this->prefixedColumnExpression($columns, 'f', array('sha256'), 'sha256'),
            $this->prefixedColumnExpression($columns, 'f', array('file_size_bytes'), 'file_size_bytes', '0'),
            $this->prefixedColumnExpression($columns, 'f', array('evidence_status'), 'evidence_status'),
            $this->prefixedColumnExpression($columns, 'f', array('valid_row_count'), 'valid_row_count', '0'),
            $this->prefixedColumnExpression($columns, 'f', array('first_valid_sample_utc'), 'first_valid_sample_utc', 'NULL'),
            $this->prefixedColumnExpression($columns, 'f', array('last_valid_sample_utc'), 'last_valid_sample_utc', 'NULL'),
            $this->prefixedColumnExpression($columns, 'f', array('system_identifier'), 'system_identifier'),
            $this->prefixedColumnExpression($columns, 'f', array('workflow_flight_record_uuid'), 'workflow_flight_record_uuid'),
            $this->prefixedColumnExpression($columns, 'f', array('session_id'), 'session_id', 'NULL'),
            "{$uploadSourceExpression} AS upload_source",
            "{$sourceExpression} AS source",
            "{$providerExpression} AS provider_name",
            "{$receivedExpression} AS received_at",
            $validationSelect,
        );

        $sql = 'SELECT ' . implode(', ', $select)
            . ' FROM ' . $this->quoteIdentifier($table) . ' f'
            . $validationJoin
            . ' WHERE ' . $where
            . ' ORDER BY received_at DESC, f.id DESC'
            . ' LIMIT ' . $this->normalizeLimit($limit);

        $rows = $this->fetchAll($sql);
        foreach ($rows as &$row) {
            $uploadSource = strtolower(trim((string)($row['upload_source'] ?? '')));
            $source = strtolower(trim((string)($row['source'] ?? '')));
            if ($uploadSource === 'admin_manual' || $source === 'cvr_admin_intake') {
                $row['source_label'] = 'MANUAL UPLOAD';
            } elseif ($uploadSource === 'desktop_sync_agent' || $source === 'garmin_cloud') {
                $row['source_label'] = 'IPCA SYNC AGENT';
            } else {
                $row['source_label'] = 'CVR APP';
            }
        }
        unset($row);

        return array(
            'available' => true,
            'rows' => $rows,
            'message' => '',
        );
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableCache)) {
            return $this->tableCache[$table];
        }
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ");
        $stmt->execute(array($table));
        return $this->tableCache[$table] = (int)$stmt->fetchColumn() > 0;
    }

    /**
     * @param list<string> $tables
     */
    private function firstExistingTable(array $tables): ?string
    {
        foreach ($tables as $table) {
            if ($this->tableExists($table)) {
                return $table;
            }
        }
        return null;
    }

    /**
     * @return array<string,bool>
     */
    private function columns(string $table): array
    {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }
        $stmt = $this->pdo->prepare("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ");
        $stmt->execute(array($table));
        $columns = array();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
            $columns[(string)$column] = true;
        }
        return $this->columnCache[$table] = $columns;
    }

    /**
     * @param array<string,bool> $available
     * @param list<string> $candidates
     */
    private function firstColumn(array $available, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (isset($available[$candidate])) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * @param array<string,bool> $available
     * @param list<string> $candidates
     */
    private function columnExpression(array $available, array $candidates, string $alias, string $fallback = "''"): string
    {
        $column = $this->firstColumn($available, $candidates);
        return ($column !== null ? $this->quoteIdentifier($column) : $fallback)
            . ' AS ' . $this->quoteIdentifier($alias);
    }

    /**
     * @param array<string,bool> $available
     * @param list<string> $candidates
     */
    private function prefixedColumnExpression(array $available, string $prefix, array $candidates, string $alias, string $fallback = "''"): string
    {
        $column = $this->firstColumn($available, $candidates);
        return ($column !== null ? $prefix . '.' . $this->quoteIdentifier($column) : $fallback)
            . ' AS ' . $this->quoteIdentifier($alias);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function normalizeLimit(int $limit): int
    {
        return max(1, min(500, $limit));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchAll(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        return is_array($rows) ? $rows : array();
    }
}
