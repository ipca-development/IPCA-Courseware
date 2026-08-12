<?php
declare(strict_types=1);

require_once __DIR__ . '/CvrOperationalIdentityReadService.php';
require_once __DIR__ . '/CvrOperationalBlockTimeService.php';
require_once __DIR__ . '/CvrOperationalLegVisibilityService.php';
require_once __DIR__ . '/CvrCrewMessageService.php';

final class CvrDataIntakeReadService
{
    /** @var array<string,bool> */
    private array $tableCache = array();

    /** @var array<string,array<string,bool>> */
    private array $columnCache = array();

    private ?CvrOperationalIdentityReadService $identityRead = null;
    private ?CvrOperationalBlockTimeService $blockTimes = null;
    private ?CvrOperationalLegVisibilityService $visibility = null;

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

    private function visibility(): CvrOperationalLegVisibilityService
    {
        return $this->visibility ??= new CvrOperationalLegVisibilityService($this->pdo);
    }

    /**
     * @return array{
     *   available:bool,
     *   rows:list<array<string,mixed>>,
     *   message:string,
     *   total:int,
     *   limit:int,
     *   offset:int,
     *   page:int,
     *   page_count:int
     * }
     */
    public function dispatchRows(
        int $limit = 30,
        int $offset = 0,
        ?string $aircraftRegistration = null,
        ?string $dateFromLocal = null,
        ?string $dateToLocal = null,
        string $timezone = 'America/Los_Angeles',
        bool $includeHidden = false,
        bool $onlyHidden = false
    ): array {
        $table = $this->firstExistingTable(array('ipca_cvr_dispatches', 'ipca_cvr_dispatch_records'));
        if ($table === null) {
            return array(
                'available' => false,
                'rows' => array(),
                'message' => 'No Dispatch intake table is connected yet. Dispatch records created in the CVR app are still local-only.',
                'total' => 0,
                'limit' => $limit,
                'offset' => 0,
                'page' => 1,
                'page_count' => 1,
            );
        }

        $limit = $this->normalizeLimit($limit);
        $offset = max(0, $offset);
        $aircraftRegistration = strtoupper(trim((string)$aircraftRegistration));
        if ($aircraftRegistration === '') {
            $aircraftRegistration = null;
        }
        $dateFromLocal = trim((string)$dateFromLocal);
        $dateToLocal = trim((string)$dateToLocal);
        if ($dateFromLocal === '') {
            $dateFromLocal = null;
        }
        if ($dateToLocal === '') {
            $dateToLocal = null;
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
            $this->prefixedColumnExpression($columns, $tableAlias, array('operational_session_uuid'), 'operational_session_uuid', 'NULL'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('scheduler_record_id'), 'scheduler_record_id', 'NULL'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('organization_id'), 'organization_id', '0'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('last_received_at', 'received_at', 'created_at', 'updated_at'), 'received_at', 'NULL'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('starting_hobbs'), 'starting_hobbs', 'NULL'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('starting_tacho'), 'starting_tacho', 'NULL'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('fuel_onboard'), 'fuel_onboard', "''"),
            $this->prefixedColumnExpression($columns, $tableAlias, array('oil_percentage'), 'oil_percentage', 'NULL'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('oil_quantity'), 'oil_quantity', 'NULL'),
            $this->prefixedColumnExpression($columns, $tableAlias, array('oil_unit'), 'oil_unit', "''"),
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
            $select[] = "(
                SELECT JSON_EXTRACT(v.payload_json, '$.leg_segments')
                FROM ipca_cvr_dispatch_versions v
                WHERE v.dispatch_id = {$tableAlias}.id
                  AND v.dispatch_version = {$tableAlias}.current_version
                LIMIT 1
            ) AS leg_segments_json";
            $select[] = "(
                SELECT JSON_EXTRACT(v.payload_json, '$.via_airports')
                FROM ipca_cvr_dispatch_versions v
                WHERE v.dispatch_id = {$tableAlias}.id
                  AND v.dispatch_version = {$tableAlias}.current_version
                LIMIT 1
            ) AS via_airports_json";
        } else {
            $select[] = "'' AS departure_airport";
            $select[] = "'' AS arrival_airport";
            $select[] = 'NULL AS leg_segments_json';
            $select[] = 'NULL AS via_airports_json';
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
                        JSON_UNQUOTE(JSON_EXTRACT(fc.payload_json, '$.off_block_utc')),
                        fc.received_at
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

        $offBlockExpr = 'NULL';
        foreach ($select as $selectExpr) {
            if (str_contains($selectExpr, ' AS off_block_utc')) {
                $offBlockExpr = preg_replace('/\s+AS\s+off_block_utc$/i', '', $selectExpr) ?? 'NULL';
                break;
            }
        }

        if ($this->tableExists('ipca_garmin_csv_files') && isset($columns['workflow_flight_record_uuid'])) {
            $select[] = "EXISTS(
                SELECT 1 FROM ipca_garmin_csv_files csv
                WHERE LOWER(csv.workflow_flight_record_uuid) = LOWER({$tableAlias}.workflow_flight_record_uuid)
                LIMIT 1
            ) AS has_garmin_csv";
        } else {
            $select[] = '0 AS has_garmin_csv';
        }

        $where = array('1=1');
        $params = array();
        if (isset($columns['status'])) {
            $where[] = "LOWER(TRIM(COALESCE({$tableAlias}.status, ''))) <> 'released'";
        }
        if ($aircraftRegistration !== null) {
            $regColumn = $this->firstColumn($columns, array('aircraft_registration', 'tail_number'));
            if ($regColumn !== null) {
                $where[] = "UPPER({$tableAlias}." . $this->quoteIdentifier($regColumn) . ') = ?';
                $params[] = $aircraftRegistration;
            }
        }
        $fromUtc = $this->localDateStartUtc($dateFromLocal, $timezone);
        $toUtcExclusive = $this->localDateEndExclusiveUtc($dateToLocal, $timezone);
        if ($fromUtc !== null && $offBlockExpr !== 'NULL') {
            $where[] = "({$offBlockExpr}) >= ?";
            $params[] = $fromUtc;
        }
        if ($toUtcExclusive !== null && $offBlockExpr !== 'NULL') {
            $where[] = "({$offBlockExpr}) < ?";
            $params[] = $toUtcExclusive;
        }
        if ($this->tableExists('ipca_cvr_logbook_hidden_legs')) {
            if ($onlyHidden) {
                $where[] = "EXISTS (
                    SELECT 1 FROM ipca_cvr_logbook_hidden_legs h
                    WHERE h.dispatch_id = {$tableAlias}.id
                )";
            } elseif (!$includeHidden) {
                $where[] = "NOT EXISTS (
                    SELECT 1 FROM ipca_cvr_logbook_hidden_legs h
                    WHERE h.dispatch_id = {$tableAlias}.id
                )";
            }
        }

        $whereSql = implode(' AND ', $where);
        $orderSql = $offBlockExpr !== 'NULL'
            ? "({$offBlockExpr}) DESC, {$tableAlias}.id DESC"
            : ($tableAlias . '.' . $this->quoteIdentifier(
                $this->firstColumn($columns, array('last_received_at', 'received_at', 'created_at', 'updated_at', 'id')) ?? 'id'
            ) . ' DESC');

        // Fetch the full filtered set, then group device multi-leg siblings before paging.
        // LIMIT in SQL would split a reservation across pages and hide Via aggregation.
        $sql = 'SELECT ' . implode(', ', $select)
            . ' FROM ' . $quotedTable
            . ' WHERE ' . $whereSql
            . ' ORDER BY ' . $orderSql;

        $rows = $this->fetchAllPrepared($sql, $params);
        $flightKeys = array_values(array_filter(array_map(
            static fn(array $row): string => strtolower(trim((string)($row['workflow_flight_record_uuid'] ?? ''))),
            $rows
        )));
        $audioByFlight = $this->audioStatusByFlightRecord($flightKeys);
        $recordingByFlight = $this->recordingMetaByFlightRecord($flightKeys);
        $bundleByFlight = $this->reconstructionMetaByFlightRecord($flightKeys);
        $hiddenIds = array();
        if ($includeHidden || $onlyHidden) {
            $hiddenIds = array_fill_keys($this->visibility()->hiddenDispatchIds(), true);
        }
        $projected = array();
        foreach ($rows as $row) {
            $row['is_hidden'] = $onlyHidden || isset($hiddenIds[(int)($row['id'] ?? 0)]);
            $row['on_block_utc'] = $this->blockTimes()->derivedOnBlockUtc($row);
            $row['engine_time_hours'] = $this->blockTimes()->engineTimeHours(
                $row['starting_hobbs'] ?? null,
                $row['ending_hobbs'] ?? null
            );
            $row['tacho_delta_hours'] = $this->blockTimes()->engineTimeHours(
                $row['starting_tacho'] ?? null,
                $row['ending_tacho'] ?? null
            );
            $fuelDep = trim((string)($row['fuel_onboard'] ?? ''));
            $fuelLdg = trim((string)($row['fuel_remaining'] ?? ''));
            $row['fuel_departure'] = $fuelDep;
            $row['fuel_landing'] = $fuelLdg;
            $row['fuel_consumption'] = null;
            if (is_numeric($fuelDep) && is_numeric($fuelLdg)) {
                $row['fuel_consumption'] = round((float)$fuelDep - (float)$fuelLdg, 1);
            }
            $oilQty = $row['oil_quantity'] ?? null;
            $oilPct = $row['oil_percentage'] ?? null;
            $oilUnit = trim((string)($row['oil_unit'] ?? ''));
            if (is_numeric($oilQty)) {
                $row['oil_departure_label'] = rtrim(rtrim(number_format((float)$oilQty, 1, '.', ''), '0'), '.')
                    . ($oilUnit !== '' ? ' ' . $oilUnit : '');
            } elseif (is_numeric($oilPct)) {
                $row['oil_departure_label'] = ((int)$oilPct) . '%';
            } else {
                $row['oil_departure_label'] = '';
            }
            $flightKey = strtolower(trim((string)($row['workflow_flight_record_uuid'] ?? '')));
            $audio = $audioByFlight[$flightKey] ?? array(
                'upload_status' => 'missing',
                'transcription_status' => 'pending',
            );
            $recording = $recordingByFlight[$flightKey] ?? array(
                'recording_id' => null,
                'recording_uid' => '',
                'reconstruction_status' => '',
            );
            $bundle = $bundleByFlight[$flightKey] ?? array(
                'bundle_id' => null,
                'debrief_id' => null,
                'bundle_evidence_stage' => '',
                'debrief_evidence_stage' => '',
                'reconstruction_status' => '',
                'debrief_job_id' => null,
                'debrief_job_status' => '',
                'debrief_job_progress' => 0,
                'debrief_job_message' => '',
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
            $row['has_garmin_csv'] = !empty($row['has_garmin_csv']);
            $row['flight_data_status_label'] = $row['has_garmin_csv'] ? 'Garmin Uploaded' : 'Garmin Missing';
            $row['recording_id'] = $recording['recording_id'];
            $row['recording_uid'] = $recording['recording_uid'];
            $row['bundle_id'] = $bundle['bundle_id'];
            $row['debrief_id'] = $bundle['debrief_id'];
            $row['bundle_evidence_stage'] = (string)($bundle['bundle_evidence_stage'] ?? '');
            $row['debrief_evidence_stage'] = (string)($bundle['debrief_evidence_stage'] ?? '');
            $recordingRecon = strtolower(trim((string)($recording['reconstruction_status'] ?? '')));
            $bundleRecon = strtolower(trim((string)($bundle['reconstruction_status'] ?? '')));
            $row['reconstruction_status'] = $recordingRecon !== '' ? $recordingRecon : $bundleRecon;
            $row['debrief_job_id'] = $bundle['debrief_job_id'] ?? null;
            $row['debrief_job_status'] = (string)($bundle['debrief_job_status'] ?? '');
            $row['debrief_job_progress'] = (int)($bundle['debrief_job_progress'] ?? 0);
            $row['debrief_job_message'] = (string)($bundle['debrief_job_message'] ?? '');
            $row['leg_segments'] = $this->decodeJsonList($row['leg_segments_json'] ?? null);
            $row['via_airports'] = $this->decodeJsonStringList($row['via_airports_json'] ?? null);
            if ($row['via_airports'] === array() && $row['leg_segments'] !== array()) {
                $row['via_airports'] = $this->viaAirportsFromSegments($row['leg_segments']);
            }
            unset($row['leg_segments_json'], $row['via_airports_json']);
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

        $grouped = self::groupDeviceMultiLegDispatchRows($projected);
        $total = count($grouped);
        $pageCount = max(1, (int)ceil(max(0, $total) / $limit));
        if ($offset >= $total && $total > 0) {
            $offset = (int)(($pageCount - 1) * $limit);
        }
        $page = (int)floor($offset / $limit) + 1;
        $paged = array_slice($grouped, $offset, $limit);
        $paged = $this->attachClosedSessionCrewMessages($paged, $timezone);

        return array(
            'available' => true,
            'rows' => array_values($paged),
            'message' => '',
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'page' => $page,
            'page_count' => $pageCount,
        );
    }

    /**
     * Crew communications are archived only on completed Master Logbook rows.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function attachClosedSessionCrewMessages(array $rows, string $timezone): array
    {
        if (!$this->tableExists('ipca_cvr_crew_messages')) {
            foreach ($rows as &$row) {
                $row['crew_messages'] = array();
            }
            unset($row);
            return $rows;
        }
        try {
            $localTimezone = new DateTimeZone(
                in_array($timezone, timezone_identifiers_list(), true)
                    ? $timezone
                    : 'America/Los_Angeles'
            );
        } catch (Throwable) {
            $localTimezone = new DateTimeZone('America/Los_Angeles');
        }
        $service = new CvrCrewMessageService($this->pdo);
        $cache = array();
        foreach ($rows as &$row) {
            $row['crew_messages'] = array();
            $sessionUuid = strtolower(trim((string)($row['operational_session_uuid'] ?? '')));
            $organizationId = (int)($row['organization_id'] ?? 0);
            if (empty($row['has_closure']) || $organizationId <= 0 || $sessionUuid === '') {
                continue;
            }
            $cacheKey = $organizationId . ':' . $sessionUuid;
            if (!array_key_exists($cacheKey, $cache)) {
                try {
                    $cache[$cacheKey] = $service->historyByOperationalSession($organizationId, $sessionUuid);
                } catch (Throwable) {
                    $cache[$cacheKey] = array();
                }
            }
            $row['crew_messages'] = array_map(
                static function (array $message) use ($localTimezone): array {
                    $local = static function (mixed $value) use ($localTimezone): string {
                        $text = trim((string)$value);
                        if ($text === '') {
                            return '';
                        }
                        try {
                            return (new DateTimeImmutable($text, new DateTimeZone('UTC')))
                                ->setTimezone($localTimezone)
                                ->format('H:i:s');
                        } catch (Throwable) {
                            return '';
                        }
                    };
                    return array(
                        'message_uuid' => (string)($message['message_uuid'] ?? ''),
                        'body_text' => (string)($message['body'] ?? ''),
                        'sender_name' => (string)($message['sender_name'] ?? ''),
                        'sender_role' => (string)($message['sender_role'] ?? ''),
                        'sent_at_utc' => (string)($message['sent_at_utc'] ?? ''),
                        'sent_at_local' => $local($message['sent_at_utc'] ?? ''),
                        'acknowledgement_uuid' => (string)($message['acknowledgement_uuid'] ?? ''),
                        'acknowledged_at_utc' => (string)($message['device_event_at_utc'] ?? ''),
                        'acknowledged_at_local' => $local($message['device_event_at_utc'] ?? ''),
                        'server_received_at_utc' => (string)($message['server_received_at_utc'] ?? ''),
                    );
                },
                is_array($cache[$cacheKey]) ? $cache[$cacheKey] : array()
            );
        }
        unset($row);
        return $rows;
    }

    /**
     * Collapse device multi-leg siblings (same scheduler/reservation, one Dispatch per hop)
     * into a single intake row with synthetic leg_segments — matching admin Define Legs UX.
     * Annotated single-dispatch multi-leg rows (already have leg_segments) are left alone.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function groupDeviceMultiLegDispatchRows(array $rows): array
    {
        $buckets = array();
        $order = array();
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = self::deviceMultiLegGroupKey($row);
            if ($key === null) {
                $key = 'singleton:' . (string)($row['id'] ?? ('idx-' . $index));
            }
            if (!isset($buckets[$key])) {
                $buckets[$key] = array();
                $order[] = $key;
            }
            $buckets[$key][] = $row;
        }

        $out = array();
        foreach ($order as $key) {
            $siblings = $buckets[$key] ?? array();
            if ($siblings === array()) {
                continue;
            }
            if (count($siblings) < 2 || str_starts_with($key, 'singleton:')) {
                foreach ($siblings as $sibling) {
                    $out[] = $sibling;
                }
                continue;
            }
            $annotatedRows = array();
            foreach ($siblings as $sibling) {
                $segments = is_array($sibling['leg_segments'] ?? null) ? $sibling['leg_segments'] : array();
                if (count($segments) >= 2) {
                    $annotatedRows[] = $sibling;
                }
            }
            if ($annotatedRows !== array()) {
                // Continuous Check-In already Define-Legs annotated: keep that row only.
                // Incomplete later device hops under the same scheduler are orphans.
                foreach ($annotatedRows as $annotated) {
                    $out[] = $annotated;
                }
                continue;
            }
            $out[] = self::mergeDeviceMultiLegSiblingRows($siblings);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function deviceMultiLegGroupKey(array $row): ?string
    {
        $operationalSession = strtolower(trim((string)($row['operational_session_uuid'] ?? '')));
        if ($operationalSession !== '') {
            return 'session:' . $operationalSession;
        }
        $scheduler = strtolower(trim((string)($row['scheduler_record_id'] ?? '')));
        if ($scheduler !== '') {
            return 'sched:' . $scheduler;
        }
        $reservation = strtolower(trim((string)($row['reservation_uuid'] ?? '')));
        if ($reservation !== '') {
            return 'res:' . $reservation;
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $siblings
     * @return array<string,mixed>
     */
    public static function mergeDeviceMultiLegSiblingRows(array $siblings): array
    {
        usort($siblings, static function (array $a, array $b): int {
            $ao = trim((string)($a['off_block_utc'] ?? ''));
            $bo = trim((string)($b['off_block_utc'] ?? ''));
            if ($ao !== '' && $bo !== '' && $ao !== $bo) {
                return $ao <=> $bo;
            }
            return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
        });

        $segments = array();
        foreach ($siblings as $index => $sibling) {
            $startHobbs = is_numeric($sibling['starting_hobbs'] ?? null) ? (float)$sibling['starting_hobbs'] : null;
            $endHobbs = is_numeric($sibling['ending_hobbs'] ?? null) ? (float)$sibling['ending_hobbs'] : null;
            $startTacho = is_numeric($sibling['starting_tacho'] ?? null) ? (float)$sibling['starting_tacho'] : null;
            $endTacho = is_numeric($sibling['ending_tacho'] ?? null) ? (float)$sibling['ending_tacho'] : null;
            $fuelOn = trim((string)($sibling['fuel_onboard'] ?? $sibling['fuel_departure'] ?? ''));
            $fuelRem = trim((string)($sibling['fuel_remaining'] ?? $sibling['fuel_landing'] ?? ''));
            $segments[] = array(
                'sequence_number' => $index + 1,
                'dispatch_id' => (int)($sibling['id'] ?? 0),
                'dispatch_uuid' => (string)($sibling['dispatch_uuid'] ?? ''),
                'workflow_flight_record_uuid' => (string)($sibling['workflow_flight_record_uuid'] ?? ''),
                'leg_uuid' => (string)($sibling['leg_uuid'] ?? ''),
                'departure_airport' => strtoupper(trim((string)($sibling['departure_airport'] ?? ''))),
                'arrival_airport' => strtoupper(trim((string)($sibling['arrival_airport'] ?? ''))),
                'off_block_utc' => (string)($sibling['off_block_utc'] ?? ''),
                'on_block_utc' => (string)($sibling['on_block_utc'] ?? ''),
                'starting_hobbs' => $startHobbs,
                'ending_hobbs' => $endHobbs,
                'hobbs_delta' => ($startHobbs !== null && $endHobbs !== null)
                    ? round(max(0.0, $endHobbs - $startHobbs), 1)
                    : null,
                'starting_tacho' => $startTacho,
                'ending_tacho' => $endTacho,
                'tacho_delta' => ($startTacho !== null && $endTacho !== null)
                    ? round(max(0.0, $endTacho - $startTacho), 1)
                    : null,
                'fuel_onboard' => $fuelOn !== '' ? $fuelOn : null,
                'fuel_remaining' => $fuelRem !== '' ? $fuelRem : null,
                'fuel_burn' => (is_numeric($fuelOn) && is_numeric($fuelRem))
                    ? round((float)$fuelOn - (float)$fuelRem, 1)
                    : null,
                'takeoff_count' => (int)($sibling['takeoff_count'] ?? 0),
                'landing_count' => (int)($sibling['landing_count'] ?? 0),
                'has_garmin_csv' => !empty($sibling['has_garmin_csv']),
                'recording_uid' => (string)($sibling['recording_uid'] ?? ''),
            );
        }

        $first = $siblings[0];
        $last = $siblings[count($siblings) - 1];
        $merged = $first;
        $evidence = self::pickDeviceMultiLegEvidenceDonor($siblings);
        foreach (array(
            'has_garmin_csv',
            'flight_data_status_label',
            'recording_id',
            'recording_uid',
            'bundle_id',
            'debrief_id',
            'bundle_evidence_stage',
            'debrief_evidence_stage',
            'reconstruction_status',
            'debrief_job_id',
            'debrief_job_status',
            'debrief_job_progress',
            'debrief_job_message',
            'audio_upload_status',
            'transcript_status',
            'audio_status_label',
            'transcript_status_label',
            'sync_status',
            'dispatch_status_label',
        ) as $field) {
            if (array_key_exists($field, $evidence)) {
                $merged[$field] = $evidence[$field];
            }
        }
        // Prefer the evidence-owning flight UUID for CSV attach / replay launch.
        if (trim((string)($evidence['workflow_flight_record_uuid'] ?? '')) !== '') {
            $merged['workflow_flight_record_uuid'] = $evidence['workflow_flight_record_uuid'];
        }
        if ((int)($evidence['id'] ?? 0) > 0) {
            $merged['id'] = (int)$evidence['id'];
            $merged['dispatch_uuid'] = (string)($evidence['dispatch_uuid'] ?? $merged['dispatch_uuid'] ?? '');
        }

        $merged['departure_airport'] = strtoupper(trim((string)($first['departure_airport'] ?? '')));
        $merged['arrival_airport'] = strtoupper(trim((string)($last['arrival_airport'] ?? '')));
        $merged['off_block_utc'] = (string)($first['off_block_utc'] ?? '');
        $merged['on_block_utc'] = (string)($last['on_block_utc'] ?? '');
        $merged['starting_hobbs'] = $first['starting_hobbs'] ?? null;
        $merged['ending_hobbs'] = $last['ending_hobbs'] ?? null;
        $merged['starting_tacho'] = $first['starting_tacho'] ?? null;
        $merged['ending_tacho'] = $last['ending_tacho'] ?? null;
        $merged['fuel_onboard'] = $first['fuel_onboard'] ?? $first['fuel_departure'] ?? null;
        $merged['fuel_remaining'] = $last['fuel_remaining'] ?? $last['fuel_landing'] ?? null;
        $merged['fuel_departure'] = trim((string)($merged['fuel_onboard'] ?? ''));
        $merged['fuel_landing'] = trim((string)($merged['fuel_remaining'] ?? ''));
        if (is_numeric($merged['fuel_departure']) && is_numeric($merged['fuel_landing'])) {
            $merged['fuel_consumption'] = round((float)$merged['fuel_departure'] - (float)$merged['fuel_landing'], 1);
        }
        $merged['takeoff_count'] = array_sum(array_map(
            static fn(array $row): int => (int)($row['takeoff_count'] ?? 0),
            $siblings
        ));
        $merged['landing_count'] = array_sum(array_map(
            static fn(array $row): int => (int)($row['landing_count'] ?? 0),
            $siblings
        ));
        if (is_numeric($merged['starting_hobbs'] ?? null) && is_numeric($merged['ending_hobbs'] ?? null)) {
            $merged['engine_time_hours'] = round(
                max(0.0, (float)$merged['ending_hobbs'] - (float)$merged['starting_hobbs']),
                2
            );
        }
        if (is_numeric($merged['starting_tacho'] ?? null) && is_numeric($merged['ending_tacho'] ?? null)) {
            $merged['tacho_delta_hours'] = round(
                max(0.0, (float)$merged['ending_tacho'] - (float)$merged['starting_tacho']),
                2
            );
        }
        $merged['leg_segments'] = $segments;
        $merged['via_airports'] = self::viaAirportsFromSegmentList($segments);
        $merged['device_multi_leg_grouped'] = true;
        $merged['sibling_dispatch_ids'] = array_values(array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            $siblings
        ));
        $merged['sibling_flight_record_uuids'] = array_values(array_filter(array_map(
            static fn(array $row): string => strtolower(trim((string)($row['workflow_flight_record_uuid'] ?? ''))),
            $siblings
        )));
        $merged['has_closure'] = !empty(array_filter(
            $siblings,
            static fn(array $row): bool => !empty($row['has_closure'])
        ));
        $merged['has_recorder_verification'] = !empty(array_filter(
            $siblings,
            static fn(array $row): bool => !empty($row['has_recorder_verification'])
        ));
        return $merged;
    }

    /**
     * Prefer the sibling that owns Garmin CSV, else a ready replay recording.
     *
     * @param list<array<string,mixed>> $siblings
     * @return array<string,mixed>
     */
    public static function pickDeviceMultiLegEvidenceDonor(array $siblings): array
    {
        $ready = array('ready', 'reconstruction_complete', 'complete');
        foreach ($siblings as $sibling) {
            if (!empty($sibling['has_garmin_csv'])) {
                return $sibling;
            }
        }
        foreach ($siblings as $sibling) {
            $status = strtolower(trim((string)($sibling['reconstruction_status'] ?? '')));
            $uid = trim((string)($sibling['recording_uid'] ?? ''));
            if ($uid !== '' && in_array($status, $ready, true)) {
                return $sibling;
            }
        }
        foreach ($siblings as $sibling) {
            if (trim((string)($sibling['recording_uid'] ?? '')) !== '') {
                return $sibling;
            }
        }
        return $siblings[0];
    }

    /**
     * @param list<array<string,mixed>> $segments
     * @return list<string>
     */
    public static function viaAirportsFromSegmentList(array $segments): array
    {
        if (count($segments) < 2) {
            return array();
        }
        $via = array();
        foreach ($segments as $index => $segment) {
            if ($index === 0) {
                continue;
            }
            $dep = strtoupper(trim((string)($segment['departure_airport'] ?? '')));
            if ($dep !== '' && !in_array($dep, $via, true)) {
                $via[] = $dep;
            }
        }
        $finalArr = strtoupper(trim((string)($segments[count($segments) - 1]['arrival_airport'] ?? '')));
        return array_values(array_filter(
            $via,
            static fn(string $icao): bool => $icao !== '' && $icao !== $finalArr
        ));
    }

    /**
     * @deprecated use viaAirportsFromSegmentList
     * @param list<array<string,mixed>> $segments
     * @return list<string>
     */
    private function viaAirportsFromSegments(array $segments): array
    {
        return self::viaAirportsFromSegmentList($segments);
    }

    /**
     * @param list<string> $flightRecordUuids
     * @return array<string,array{recording_id:?int,recording_uid:string,reconstruction_status?:string}>
     */
    private function recordingMetaByFlightRecord(array $flightRecordUuids): array
    {
        $flightRecordUuids = array_values(array_unique(array_filter($flightRecordUuids)));
        if ($flightRecordUuids === array() || !$this->tableExists('ipca_cockpit_recordings')) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($flightRecordUuids), '?'));
        $sql = "
            SELECT LOWER(flight_session_uid) AS flight_key, id, recording_uid, reconstruction_status
            FROM ipca_cockpit_recordings
            WHERE flight_session_uid IS NOT NULL
              AND flight_session_uid <> ''
              AND LOWER(flight_session_uid) IN ({$placeholders})
            ORDER BY id DESC
        ";
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($flightRecordUuids);
            $map = array();
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = strtolower(trim((string)($row['flight_key'] ?? '')));
                if ($key === '' || isset($map[$key])) {
                    continue;
                }
                $map[$key] = array(
                    'recording_id' => isset($row['id']) ? (int)$row['id'] : null,
                    'recording_uid' => trim((string)($row['recording_uid'] ?? '')),
                    'reconstruction_status' => strtolower(trim((string)($row['reconstruction_status'] ?? ''))),
                );
            }
            return $map;
        } catch (Throwable) {
            return array();
        }
    }

    /**
     * @param list<string> $flightRecordUuids
     * @return array<string,array{
     *   bundle_id:?int,
     *   debrief_id:?int,
     *   reconstruction_status:string,
     *   debrief_job_id:?int,
     *   debrief_job_status:string,
     *   debrief_job_progress:int,
     *   debrief_job_message:string
     * }>
     */
    private function reconstructionMetaByFlightRecord(array $flightRecordUuids): array
    {
        $flightRecordUuids = array_values(array_unique(array_filter($flightRecordUuids)));
        $bundleTable = $this->firstExistingTable(array('ipca_manual_reconstruction_bundles', 'ipca_manual_intake_bundles'));
        if ($flightRecordUuids === array() || $bundleTable === null) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($flightRecordUuids), '?'));
        $statusCol = $this->tableExists($bundleTable) ? $this->columns($bundleTable) : array();
        $statusExpr = isset($statusCol['reconstruction_status'])
            ? 'COALESCE(b.reconstruction_status, \'\')'
            : (isset($statusCol['status']) ? 'COALESCE(b.status, \'\')' : '\'\'');
        $stageExpr = isset($statusCol['evidence_stage'])
            ? 'COALESCE(b.evidence_stage, \'\')'
            : '\'\'';
        $sql = "
            SELECT LOWER(b.workflow_flight_record_uuid) AS flight_key,
                   b.id AS bundle_id,
                   {$stageExpr} AS bundle_evidence_stage,
                   {$statusExpr} AS reconstruction_status
            FROM " . $this->quoteIdentifier($bundleTable) . " b
            WHERE b.workflow_flight_record_uuid IS NOT NULL
              AND LOWER(b.workflow_flight_record_uuid) IN ({$placeholders})
            ORDER BY b.id DESC
        ";
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($flightRecordUuids);
            $map = array();
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = strtolower(trim((string)($row['flight_key'] ?? '')));
                if ($key === '' || isset($map[$key])) {
                    continue;
                }
                $map[$key] = array(
                    'bundle_id' => isset($row['bundle_id']) ? (int)$row['bundle_id'] : null,
                    'debrief_id' => null,
                    'bundle_evidence_stage' => trim((string)($row['bundle_evidence_stage'] ?? '')),
                    'debrief_evidence_stage' => '',
                    'reconstruction_status' => trim((string)($row['reconstruction_status'] ?? '')),
                    'debrief_job_id' => null,
                    'debrief_job_status' => '',
                    'debrief_job_progress' => 0,
                    'debrief_job_message' => '',
                );
            }
            if ($map !== array() && $this->tableExists('ipca_structured_debriefs')) {
                $bundleIds = array_values(array_filter(array_map(
                    static fn(array $m): ?int => $m['bundle_id'],
                    $map
                )));
                if ($bundleIds !== array()) {
                    $bPlaceholders = implode(',', array_fill(0, count($bundleIds), '?'));
                    $dStmt = $this->pdo->prepare(
                        "SELECT bundle_id, id, evidence_stage FROM ipca_structured_debriefs
                         WHERE bundle_id IN ({$bPlaceholders})
                         ORDER BY id DESC"
                    );
                    $dStmt->execute($bundleIds);
                    $debriefByBundle = array();
                    foreach ($dStmt->fetchAll(PDO::FETCH_ASSOC) as $dRow) {
                        $bid = (int)($dRow['bundle_id'] ?? 0);
                        if ($bid > 0 && !isset($debriefByBundle[$bid])) {
                            $debriefByBundle[$bid] = array(
                                'id' => (int)$dRow['id'],
                                'evidence_stage' => trim((string)($dRow['evidence_stage'] ?? '')),
                            );
                        }
                    }
                    foreach ($map as $key => $meta) {
                        $bid = (int)($meta['bundle_id'] ?? 0);
                        if ($bid > 0 && isset($debriefByBundle[$bid])) {
                            $map[$key]['debrief_id'] = $debriefByBundle[$bid]['id'];
                            $map[$key]['debrief_evidence_stage'] = $debriefByBundle[$bid]['evidence_stage'];
                        }
                    }
                }
            }
            if ($map !== array() && $this->tableExists('ipca_async_jobs')) {
                require_once __DIR__ . '/CockpitRecorderDebriefQueueService.php';
                $queue = CockpitRecorderDebriefQueueService::fromPdo($this->pdo);
                $bundleIds = array_values(array_filter(array_map(
                    static fn(array $m): int => (int)($m['bundle_id'] ?? 0),
                    $map
                )));
                $statusByBundle = array();
                foreach ($queue->statusForBundles($bundleIds) as $status) {
                    $bid = (int)($status['bundle_id'] ?? 0);
                    if ($bid > 0) {
                        $statusByBundle[$bid] = $status;
                    }
                }
                foreach ($map as $key => $meta) {
                    $bid = (int)($meta['bundle_id'] ?? 0);
                    $status = $statusByBundle[$bid] ?? null;
                    if (!is_array($status)) {
                        continue;
                    }
                    $map[$key]['debrief_job_id'] = isset($status['job_id']) ? (int)$status['job_id'] : null;
                    $map[$key]['debrief_job_status'] = (string)($status['status'] ?? '');
                    $map[$key]['debrief_job_progress'] = (int)($status['progress'] ?? 0);
                    $map[$key]['debrief_job_message'] = (string)($status['progress_message'] ?? '');
                    if ((int)($status['debrief_id'] ?? 0) > 0 && empty($map[$key]['debrief_id'])) {
                        $map[$key]['debrief_id'] = (int)$status['debrief_id'];
                    }
                }
            }
            return $map;
        } catch (Throwable) {
            return array();
        }
    }

    private function localDateStartUtc(?string $localDate, string $timezone): ?string
    {
        if ($localDate === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $localDate)) {
            return null;
        }
        try {
            $tz = new DateTimeZone($timezone !== '' ? $timezone : 'America/Los_Angeles');
            return (new DateTimeImmutable($localDate . ' 00:00:00', $tz))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function localDateEndExclusiveUtc(?string $localDate, string $timezone): ?string
    {
        if ($localDate === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $localDate)) {
            return null;
        }
        try {
            $tz = new DateTimeZone($timezone !== '' ? $timezone : 'America/Los_Angeles');
            return (new DateTimeImmutable($localDate . ' 00:00:00', $tz))
                ->modify('+1 day')
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
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

    /**
     * @return list<array<string,mixed>>
     */
    private function decodeJsonList(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter($raw, 'is_array'));
        }
        $text = trim((string)($raw ?? ''));
        if ($text === '' || strcasecmp($text, 'null') === 0) {
            return array();
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return array();
        }
        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * @return list<string>
     */
    private function decodeJsonStringList(mixed $raw): array
    {
        if (is_array($raw)) {
            $out = array();
            foreach ($raw as $value) {
                $icao = strtoupper(trim((string)$value));
                if ($icao !== '') {
                    $out[] = $icao;
                }
            }
            return $out;
        }
        $text = trim((string)($raw ?? ''));
        if ($text === '' || strcasecmp($text, 'null') === 0) {
            return array();
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return array();
        }
        return $this->decodeJsonStringList($decoded);
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

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function fetchAllPrepared(string $sql, array $params): array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : array();
        } catch (Throwable) {
            return array();
        }
    }
}
