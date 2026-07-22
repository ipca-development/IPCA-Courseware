<?php
declare(strict_types=1);

/**
 * Read-only orchestration service for the future Master Logbook.
 *
 * This service deliberately returns documented normalized arrays. It does not
 * own ingestion, matching, reconstruction, transcription, replay generation,
 * ADS-B enrichment, verification writes, or official logbook writes.
 */
final class MasterLogbookReadService
{
    private const DEFAULT_PAGE_SIZE = 50;
    private const MAX_PAGE_SIZE = 100;

    /** @var array<string,string> */
    private const SORT_FIELDS = array(
        'date' => 'date_sort',
        'aircraft' => 'aircraft_sort',
        'source_mode' => 'source_mode',
        'processing_status' => 'processing_status',
        'verification_status' => 'verification_status',
        'finalization_status' => 'finalization_status',
        'conflict_status' => 'conflict_status',
    );

    /** @var array<string,string> */
    private const EVENT_PATTERNS = array(
        'current' => '/^current-event:ofr:(\d+)$/',
        'historical' => '/^historical-event:ao:(\d+)$/',
        'simulator' => '/^simulator-event:fcs:(\d+)$/',
        'nonflight' => '/^nonflight-event:fcs:(\d+)$/',
        'unresolved_garmin_csv' => '/^unresolved-garmin:csv:(\d+)$/',
        'unresolved_garmin_segment' => '/^unresolved-garmin-segment:ghs:(\d+)$/',
        'orphan_recording' => '/^orphan-recording:rec:(\d+)$/',
    );

    /** @var array<string,string> */
    private const LEG_PATTERNS = array(
        'current' => '/^current-leg:ofrv:(\d+):leg:(\d+)$/',
        'historical_aggregate' => '/^historical-leg:ao:(\d+):aggregate$/',
        'historical_garmin' => '/^historical-garmin-leg:ao:(\d+):ghs:(\d+)$/',
        'historical_garmin_csv' => '/^historical-garmin-leg:ao:(\d+):csv:(\d+)$/',
        'unresolved_garmin' => '/^unresolved-garmin-leg:csv:(\d+):summary$/',
        'simulator' => '/^simulator-leg:fcs:(\d+):session$/',
        'nonflight' => '/^nonflight-leg:fcs:(\d+):operation$/',
        'orphan_recording' => '/^orphan-recording-leg:rec:(\d+):recording$/',
    );

    private ?PDO $pdo;

    /** @var array<string,mixed>|null */
    private ?array $fixtureData;

    /** @var array<string,bool> */
    private array $tableExistsCache = array();

    private float $lastSchemaMs = 0.0;
    private float $lastCountQueryMs = 0.0;
    private float $lastRowQueryMs = 0.0;
    private int $lastEvidenceQueryCount = 0;
    private float $lastEvidenceQueryMs = 0.0;

    /**
     * @param array<string,mixed>|null $fixtureData
     */
    public function __construct(?PDO $pdo = null, ?array $fixtureData = null)
    {
        $this->pdo = $pdo;
        $this->fixtureData = $fixtureData;
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function listLegRows(array $request = array()): array
    {
        $started = microtime(true);
        $this->lastSchemaMs = 0.0;
        $this->lastCountQueryMs = 0.0;
        $this->lastRowQueryMs = 0.0;
        $this->lastEvidenceQueryCount = 0;
        $this->lastEvidenceQueryMs = 0.0;
        $queryCount = 0;
        $queryMs = 0.0;
        $branchCounts = array();
        $filters = $this->normalizeFilters(isset($request['filters']) && is_array($request['filters']) ? $request['filters'] : $request);
        $page = max(1, (int)($request['page'] ?? $filters['page'] ?? 1));
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, (int)($request['page_size'] ?? $filters['page_size'] ?? self::DEFAULT_PAGE_SIZE)));
        $sortField = (string)($request['sort_field'] ?? $filters['sort_field'] ?? 'date');
        $sortDirection = strtolower((string)($request['sort_direction'] ?? $filters['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        if (!isset(self::SORT_FIELDS[$sortField])) {
            $sortField = 'date';
        }

        $queryStarted = microtime(true);
        $load = $this->loadCandidates($filters, $page, $pageSize, $sortField, $sortDirection);
        $queryMs += $load['query_ms'];
        $queryCount += $load['query_count'];
        $branchCounts = $load['branch_counts'];
        $queryElapsed = (microtime(true) - $queryStarted) * 1000.0;
        if ($queryMs <= 0.0) {
            $queryMs = $queryElapsed;
        }

        $dedupeStarted = microtime(true);
        $deduped = $this->deduplicateCandidates($load['candidates']);
        $dedupeMs = (microtime(true) - $dedupeStarted) * 1000.0;

        $evidenceStarted = microtime(true);
        $rowsWithEvidence = $this->attachBatchedEvidence($deduped['visible_candidates']);
        $evidenceBatchMs = (microtime(true) - $evidenceStarted) * 1000.0;

        $rowBuildStarted = microtime(true);
        $rows = array_values(array_map(array($this, 'candidateToRow'), $rowsWithEvidence));
        $rows = $this->sortRows($rows, $sortField, $sortDirection);
        $totalMatchingRows = isset($load['total_matching_rows']) && is_int($load['total_matching_rows']) ? $load['total_matching_rows'] : count($rows);
        $offset = ($page - 1) * $pageSize;
        $pagedRows = array_slice($rows, $offset, $pageSize);
        $rowBuildMs = (microtime(true) - $rowBuildStarted) * 1000.0;

        $diagnostics = array(
            'query_count' => $queryCount,
            'query_ms' => round($queryMs, 3),
            'schema_table_discovery_ms' => round($this->lastSchemaMs, 3),
            'count_query_ms' => round($this->lastCountQueryMs, 3),
            'row_query_ms' => round($this->lastRowQueryMs, 3),
            'evidence_query_count' => $this->lastEvidenceQueryCount,
            'evidence_query_ms' => round($this->lastEvidenceQueryMs, 3),
            'row_build_ms' => round($rowBuildMs, 3),
            'dedupe_ms' => round($dedupeMs, 3),
            'evidence_batch_ms' => round($evidenceBatchMs, 3),
            'json_serialization_ms' => 0.0,
            'total_ms' => round((microtime(true) - $started) * 1000.0, 3),
            'candidate_count_by_source_branch' => $branchCounts,
            'loaded_candidate_count_by_source_branch' => $load['loaded_branch_counts'] ?? $branchCounts,
            'candidate_count' => count($load['candidates']),
            'suppressed_duplicate_count' => count($deduped['suppressed_duplicates']),
            'suppressed_duplicates' => $deduped['suppressed_duplicates'],
            'unresolved_count' => $this->countUnresolved($load['candidates']),
        );

        return array(
            'rows' => $pagedRows,
            'total_matching_rows' => $totalMatchingRows,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => max(1, (int)ceil($totalMatchingRows / $pageSize)),
            'sort_field' => $sortField,
            'sort_direction' => $sortDirection,
            'applied_filters' => $filters,
            'available_filter_options' => $this->availableFilterOptions($load['candidates']),
            'query_diagnostics' => !empty($request['include_diagnostics']) ? $diagnostics : array(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function eventDetail(string $eventKey): array
    {
        $identity = $this->parseEventKey($eventKey);
        $candidates = $this->loadAllCandidatesForDetail($eventKey);
        $matching = array_values(array_filter($candidates, static function (array $candidate) use ($eventKey): bool {
            return (string)$candidate['event_key'] === $eventKey;
        }));
        if ($matching === array()) {
            throw new InvalidArgumentException('Unknown Master Logbook event key.');
        }

        $deduped = $this->deduplicateCandidates($candidates);
        $eventCandidates = array_values(array_filter($deduped['visible_candidates'], static function (array $candidate) use ($eventKey): bool {
            return (string)$candidate['event_key'] === $eventKey;
        }));
        $event = $eventCandidates[0] ?? $matching[0];
        $eventCandidates = $eventCandidates !== array() ? $eventCandidates : $matching;
        $eventCandidates = $this->attachBatchedEvidence($eventCandidates);
        $event = $eventCandidates[0];
        $row = $this->candidateToRow($event);
        $legs = array();
        foreach ($eventCandidates as $legCandidate) {
            $legs[] = $this->rowLegDetail($this->candidateToRow($legCandidate), $legCandidate);
        }

        return array(
            'identity' => array(
                'event_key' => $eventKey,
                'source_mode' => $event['source_mode'],
                'source_record_keys' => $event['source_record_keys'] ?? array(),
                'parsed' => $identity,
            ),
            'summary' => array(
                'date' => $row['date'],
                'aircraft' => $row['aircraft'],
                'event_start' => $event['event_start'] ?? null,
                'event_end' => $event['event_end'] ?? null,
                'session_duration' => $event['session_duration'] ?? null,
                'event_type' => $event['event_type'] ?? $event['source_mode'],
                'leg_structure_status' => $row['leg_structure_status'],
            ),
            'crew' => $event['crew_detail'] ?? array($row['pilot_1'], $row['pilot_2']),
            'mission' => $event['mission_detail'] ?? $row['mission'],
            'legs' => $event['legs_detail'] ?? $legs,
            'operational_classification' => $event['operational_classification'] ?? $this->emptyProvenanceValue(null, 'not_traced'),
            'evidence' => array(
                'fdm' => $row['fdm'],
                'cvr' => $row['cvr'],
                'flightcircle' => $event['flightcircle'] ?? $this->evidenceState('not_available'),
                'adsb' => $row['adsb'],
                'transcript' => $event['transcript'] ?? $this->transcriptState('not_available'),
                'replay' => $row['replay'],
                'debrief' => $event['debrief'] ?? $this->evidenceState('not_available'),
                'official_logbook' => $event['official_logbook'] ?? $this->evidenceState('not_available'),
                'proposal' => $event['proposal'] ?? $this->evidenceState('not_available'),
            ),
            'map' => array(
                'track_references' => $event['map_track_references'] ?? array(),
                'generation_performed' => false,
            ),
            'transcript' => array(
                'raw_transcript' => $event['raw_transcript'] ?? null,
                'enhanced_transcript' => $event['enhanced_transcript'] ?? null,
                'enhancement_ownership' => $event['enhancement_ownership'] ?? 'not_traced',
            ),
            'maintenance' => array(
                'meter_tacho_facts' => $event['meter_tacho_facts'] ?? array(),
                'squawks' => array('integration_status' => 'not_traced', 'items' => array()),
            ),
            'safety' => array(
                'safety_reports' => array('integration_status' => 'not_traced', 'items' => array()),
            ),
            'verification' => array(
                'processing_status' => $row['processing_status'],
                'verification_status' => $row['verification_status'],
                'finalization_status' => $row['finalization_status'],
                'conflict_status' => $row['conflict_status'],
                'audit_history' => $event['audit_history'] ?? array(),
                'conflicts' => $event['conflicts'] ?? array(),
            ),
            'actions' => $row['available_actions'],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function parseEventKey(string $eventKey): array
    {
        foreach (self::EVENT_PATTERNS as $type => $pattern) {
            if (preg_match($pattern, $eventKey, $matches) === 1) {
                return array('type' => $type, 'ids' => array_map('intval', array_slice($matches, 1)));
            }
        }
        throw new InvalidArgumentException('Unsupported Master Logbook event key format.');
    }

    /**
     * @return array<string,mixed>
     */
    public function parseLegKey(string $legKey): array
    {
        foreach (self::LEG_PATTERNS as $type => $pattern) {
            if (preg_match($pattern, $legKey, $matches) === 1) {
                return array('type' => $type, 'ids' => array_map('intval', array_slice($matches, 1)));
            }
        }
        throw new InvalidArgumentException('Unsupported Master Logbook leg key format.');
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function loadCandidates(array $filters, int $page, int $pageSize, string $sortField, string $sortDirection): array
    {
        if ($this->fixtureData !== null) {
            return $this->loadFixtureCandidates($filters);
        }
        return $this->loadDatabaseCandidates($filters, $page, $pageSize, $sortField, $sortDirection);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadAllCandidatesForDetail(?string $eventKey = null): array
    {
        if ($this->fixtureData !== null) {
            return $this->loadFixtureCandidates($this->normalizeFilters(array('include_unresolved' => true, 'include_simulator' => true, 'include_non_flight' => true)))['candidates'];
        }
        if ($eventKey !== null && $this->pdo instanceof PDO) {
            return $this->loadDatabaseCandidatesForDetail($eventKey);
        }
        return $this->loadDatabaseCandidates($this->normalizeFilters(array('include_unresolved' => true, 'include_simulator' => true, 'include_non_flight' => true)), 1, self::MAX_PAGE_SIZE, 'date', 'desc')['candidates'];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function loadFixtureCandidates(array $filters): array
    {
        $started = microtime(true);
        $branches = isset($this->fixtureData['branches']) && is_array($this->fixtureData['branches']) ? $this->fixtureData['branches'] : array();
        $candidates = array();
        $branchCounts = array();
        foreach ($branches as $branch => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            $branchCounts[(string)$branch] = count($rows);
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $candidate = $this->normalizeCandidate($row, (string)$branch);
                if ($this->candidateAllowedByFilters($candidate, $filters)) {
                    $candidates[] = $candidate;
                }
            }
        }
        return array(
            'candidates' => $candidates,
            'query_count' => 0,
            'query_ms' => round((microtime(true) - $started) * 1000.0, 3),
            'branch_counts' => $branchCounts,
        );
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function loadDatabaseCandidates(array $filters, int $page, int $pageSize, string $sortField, string $sortDirection): array
    {
        if (!$this->pdo instanceof PDO) {
            return array('candidates' => array(), 'query_count' => 0, 'query_ms' => 0.0, 'branch_counts' => array());
        }

        $queryCount = 0;
        $queryMs = 0.0;
        $branchCounts = array();
        $loadedBranchCounts = array();
        $candidates = array();
        $limit = max($pageSize, min(self::MAX_PAGE_SIZE * 5, $page * $pageSize));
        $totalMatchingRows = 0;

        if ($this->tableExists('ipca_operational_flight_records') && $this->tableExists('ipca_operational_flight_record_versions') && $this->tableExists('ipca_operational_flight_leg_versions')) {
            $countResult = $this->timedQuery("
                SELECT COUNT(*) AS row_count
                FROM ipca_operational_flight_records r
                INNER JOIN ipca_flight_sessions s ON s.id = r.session_id
                INNER JOIN ipca_operational_flight_record_versions v ON v.id = r.current_version_id
                INNER JOIN ipca_operational_flight_leg_versions l ON l.flight_record_version_id = v.id
            ", array());
            $queryCount += 1;
            $queryMs += $countResult['ms'];
            $this->lastCountQueryMs += $countResult['ms'];
            $currentTotal = (int)($countResult['rows'][0]['row_count'] ?? 0);
            $totalMatchingRows += $currentTotal;
            $branchCounts['current_operational'] = $currentTotal;

            $result = $this->timedQuery("
                SELECT
                    r.id AS operational_flight_record_id,
                    r.status AS record_status,
                    s.id AS session_id,
                    s.session_uuid,
                    s.aircraft_registration,
                    s.avionics_on_utc,
                    s.avionics_off_utc,
                    v.id AS flight_record_version_id,
                    v.version_uuid,
                    v.status AS version_status,
                    v.readiness_status,
                    v.finalized_at,
                    v.hobbs_start_hours,
                    v.hobbs_end_hours,
                    v.tacho_start_hours,
                    v.tacho_end_hours,
                    v.fuel_start_usg,
                    v.fuel_end_usg,
                    l.leg_index,
                    l.allocation_start_utc,
                    l.allocation_end_utc,
                    l.departure_airport_code,
                    l.arrival_airport_code,
                    l.allocated_hobbs_duration_ms,
                    l.allocated_tacho_duration_ms,
                    l.landing_event_count,
                    l.fuel_start_usg AS leg_fuel_start_usg,
                    l.fuel_end_usg AS leg_fuel_end_usg
                FROM ipca_operational_flight_records r
                INNER JOIN ipca_flight_sessions s ON s.id = r.session_id
                INNER JOIN ipca_operational_flight_record_versions v ON v.id = r.current_version_id
                INNER JOIN ipca_operational_flight_leg_versions l ON l.flight_record_version_id = v.id
                ORDER BY l.allocation_start_utc DESC
                LIMIT " . (int)$limit, array());
            $queryCount += 1;
            $queryMs += $result['ms'];
            $this->lastRowQueryMs += $result['ms'];
            foreach ($result['rows'] as $row) {
                $candidates[] = $this->currentCandidateFromDbRow($row);
            }
            $loadedBranchCounts['current_operational'] = count($result['rows']);
        }

        if ($this->tableExists('ipca_aircraft_operations')) {
            $countResult = $this->timedQuery("
                SELECT COUNT(*) AS row_count
                FROM ipca_aircraft_operations o
            ", array());
            $queryCount += 1;
            $queryMs += $countResult['ms'];
            $this->lastCountQueryMs += $countResult['ms'];
            $historicalTotal = (int)($countResult['rows'][0]['row_count'] ?? 0);
            $totalMatchingRows += $historicalTotal;
            $branchCounts['historical_flightcircle'] = $historicalTotal;

            $result = $this->timedQuery("
                SELECT
                    o.id AS aircraft_operation_id,
                    o.aircraft_registration,
                    o.resource_type,
                    o.operation_status,
                    o.review_status,
                    o.scheduled_start_local,
                    o.scheduled_end_local,
                    o.user_text,
                    o.instructor_text,
                    o.reservation_type,
                    o.rules_text,
                    o.route_text,
                    o.mission_notes
                FROM ipca_aircraft_operations o
                ORDER BY o.scheduled_start_local DESC, o.id DESC
                LIMIT " . (int)$limit, array());
            $queryCount += 1;
            $queryMs += $result['ms'];
            $this->lastRowQueryMs += $result['ms'];
            foreach ($result['rows'] as $row) {
                $candidate = $this->historicalCandidateFromDbRow($row);
                if ($this->candidateAllowedByFilters($candidate, $filters)) {
                    $candidates[] = $candidate;
                }
            }
            $loadedBranchCounts['historical_flightcircle'] = count($result['rows']);
        }

        if ($this->tableExists('ipca_flightcircle_garmin_matches') && $this->tableExists('ipca_garmin_csv_flight_summaries')) {
            $countResult = $this->timedQuery("
                SELECT COUNT(DISTINCT CONCAT(m.operation_id, ':', m.csv_file_id)) AS row_count
                FROM ipca_flightcircle_garmin_matches m
                WHERE m.operation_id IS NOT NULL
                  AND m.csv_file_id IS NOT NULL
                  AND m.match_status IN ('high_confidence','probable')
            ", array());
            $queryCount += 1;
            $queryMs += $countResult['ms'];
            $this->lastCountQueryMs += $countResult['ms'];
            $garminLegTotal = (int)($countResult['rows'][0]['row_count'] ?? 0);
            $totalMatchingRows += $garminLegTotal;
            $branchCounts['historical_garmin_legs'] = $garminLegTotal;

            $result = $this->timedQuery("
                SELECT
                    m.operation_id,
                    m.csv_file_id,
                    MAX(m.match_status) AS match_status,
                    MAX(m.confidence_score) AS confidence_score,
                    s.derivation_status,
                    s.tail_number,
                    s.departure_airport_code,
                    s.arrival_airport_code,
                    s.departure_time_utc,
                    s.arrival_time_utc,
                    s.elapsed_seconds,
                    s.hobbs_duration_seconds,
                    s.hobbs_status,
                    s.row_count
                FROM ipca_flightcircle_garmin_matches m
                INNER JOIN ipca_garmin_csv_flight_summaries s ON s.csv_file_id = m.csv_file_id
                WHERE m.operation_id IS NOT NULL
                  AND m.csv_file_id IS NOT NULL
                  AND m.match_status IN ('high_confidence','probable')
                GROUP BY m.operation_id, m.csv_file_id, s.derivation_status, s.tail_number, s.departure_airport_code, s.arrival_airport_code, s.departure_time_utc, s.arrival_time_utc, s.elapsed_seconds, s.hobbs_duration_seconds, s.hobbs_status, s.row_count
                ORDER BY COALESCE(s.departure_time_utc, s.arrival_time_utc) DESC, m.operation_id DESC
                LIMIT " . (int)$limit, array());
            $queryCount += 1;
            $queryMs += $result['ms'];
            $this->lastRowQueryMs += $result['ms'];
            foreach ($result['rows'] as $row) {
                $candidate = $this->historicalGarminCandidateFromDbRow($row);
                if ($this->candidateAllowedByFilters($candidate, $filters)) {
                    $candidates[] = $candidate;
                }
            }
            $loadedBranchCounts['historical_garmin_legs'] = count($result['rows']);
        }

        if (!empty($filters['include_unresolved']) && $this->tableExists('ipca_garmin_csv_flight_summaries')) {
            $countResult = $this->timedQuery("
                SELECT COUNT(*) AS row_count
                FROM ipca_garmin_csv_flight_summaries s
                LEFT JOIN ipca_flightcircle_garmin_matches m ON m.csv_file_id = s.csv_file_id AND m.operation_id IS NOT NULL AND m.match_status IN ('high_confidence','probable')
                WHERE m.id IS NULL
            ", array());
            $queryCount += 1;
            $queryMs += $countResult['ms'];
            $this->lastCountQueryMs += $countResult['ms'];
            $unresolvedGarminTotal = (int)($countResult['rows'][0]['row_count'] ?? 0);
            $totalMatchingRows += $unresolvedGarminTotal;
            $branchCounts['unresolved_garmin'] = $unresolvedGarminTotal;

            $result = $this->timedQuery("
                SELECT
                    s.csv_file_id,
                    s.derivation_status,
                    s.tail_number,
                    s.departure_airport_code,
                    s.arrival_airport_code,
                    s.departure_time_utc,
                    s.arrival_time_utc,
                    s.elapsed_seconds,
                    s.hobbs_duration_seconds,
                    s.hobbs_status,
                    s.row_count
                FROM ipca_garmin_csv_flight_summaries s
                LEFT JOIN ipca_flightcircle_garmin_matches m ON m.csv_file_id = s.csv_file_id AND m.operation_id IS NOT NULL AND m.match_status IN ('high_confidence','probable')
                WHERE m.id IS NULL
                ORDER BY COALESCE(s.departure_time_utc, s.arrival_time_utc) DESC, s.csv_file_id DESC
                LIMIT " . (int)$limit, array());
            $queryCount += 1;
            $queryMs += $result['ms'];
            $this->lastRowQueryMs += $result['ms'];
            foreach ($result['rows'] as $row) {
                $candidate = $this->unresolvedGarminCandidateFromDbRow($row);
                if ($this->candidateAllowedByFilters($candidate, $filters)) {
                    $candidates[] = $candidate;
                }
            }
            $loadedBranchCounts['unresolved_garmin'] = count($result['rows']);
        }

        if (!empty($filters['include_unresolved']) && $this->tableExists('ipca_cockpit_recordings')) {
            $countResult = $this->timedQuery("SELECT COUNT(*) AS row_count FROM ipca_cockpit_recordings", array());
            $queryCount += 1;
            $queryMs += $countResult['ms'];
            $this->lastCountQueryMs += $countResult['ms'];
            $recordingTotal = (int)($countResult['rows'][0]['row_count'] ?? 0);
            $totalMatchingRows += $recordingTotal;
            $branchCounts['orphan_recording'] = $recordingTotal;

            $result = $this->timedQuery("
                SELECT
                    id,
                    recording_uid,
                    started_at,
                    duration_seconds,
                    aircraft_registration,
                    upload_status,
                    transcription_status,
                    reconstruction_status,
                    storage_path,
                    g3x_storage_path
                FROM ipca_cockpit_recordings
                ORDER BY started_at DESC, id DESC
                LIMIT " . (int)$limit, array());
            $queryCount += 1;
            $queryMs += $result['ms'];
            $this->lastRowQueryMs += $result['ms'];
            foreach ($result['rows'] as $row) {
                $candidate = $this->orphanRecordingCandidateFromDbRow($row);
                if ($this->candidateAllowedByFilters($candidate, $filters)) {
                    $candidates[] = $candidate;
                }
            }
            $loadedBranchCounts['orphan_recording'] = count($result['rows']);
        }

        return array(
            'candidates' => $candidates,
            'query_count' => $queryCount,
            'query_ms' => round($queryMs, 3),
            'branch_counts' => $branchCounts,
            'loaded_branch_counts' => $loadedBranchCounts,
            'total_matching_rows' => $totalMatchingRows,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadDatabaseCandidatesForDetail(string $eventKey): array
    {
        if (!$this->pdo instanceof PDO) {
            return array();
        }
        $identity = $this->parseEventKey($eventKey);
        $type = (string)($identity['type'] ?? '');
        $ids = isset($identity['ids']) && is_array($identity['ids']) ? $identity['ids'] : array();

        if ($type === 'historical' && isset($ids[0])) {
            return $this->loadHistoricalDetailCandidates((int)$ids[0]);
        }
        if ($type === 'unresolved_garmin_csv' && isset($ids[0])) {
            return $this->loadUnresolvedGarminDetailCandidates((int)$ids[0]);
        }
        if ($type === 'orphan_recording' && isset($ids[0])) {
            return $this->loadOrphanRecordingDetailCandidates((int)$ids[0]);
        }

        return $this->loadDatabaseCandidates($this->normalizeFilters(array('include_unresolved' => true, 'include_simulator' => true, 'include_non_flight' => true)), 1, self::MAX_PAGE_SIZE, 'date', 'desc')['candidates'];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadHistoricalDetailCandidates(int $operationId): array
    {
        $candidates = array();
        if ($operationId <= 0) {
            return $candidates;
        }
        if ($this->tableExists('ipca_aircraft_operations')) {
            $result = $this->timedQuery("
                SELECT
                    o.id AS aircraft_operation_id,
                    o.aircraft_registration,
                    o.resource_type,
                    o.operation_status,
                    o.review_status,
                    o.scheduled_start_local,
                    o.scheduled_end_local,
                    o.user_text,
                    o.instructor_text,
                    o.reservation_type,
                    o.rules_text,
                    o.route_text,
                    o.mission_notes
                FROM ipca_aircraft_operations o
                WHERE o.id = ?
                LIMIT 1
            ", array($operationId));
            foreach ($result['rows'] as $row) {
                $candidates[] = $this->historicalCandidateFromDbRow($row);
            }
        }
        if ($this->tableExists('ipca_flightcircle_garmin_matches') && $this->tableExists('ipca_garmin_csv_flight_summaries')) {
            $result = $this->timedQuery("
                SELECT
                    m.operation_id,
                    m.csv_file_id,
                    MAX(m.match_status) AS match_status,
                    MAX(m.confidence_score) AS confidence_score,
                    s.derivation_status,
                    s.tail_number,
                    s.departure_airport_code,
                    s.arrival_airport_code,
                    s.departure_time_utc,
                    s.arrival_time_utc,
                    s.elapsed_seconds,
                    s.hobbs_duration_seconds,
                    s.hobbs_status,
                    s.row_count
                FROM ipca_flightcircle_garmin_matches m
                INNER JOIN ipca_garmin_csv_flight_summaries s ON s.csv_file_id = m.csv_file_id
                WHERE m.operation_id = ?
                  AND m.csv_file_id IS NOT NULL
                  AND m.match_status IN ('high_confidence','probable')
                GROUP BY m.operation_id, m.csv_file_id, s.derivation_status, s.tail_number, s.departure_airport_code, s.arrival_airport_code, s.departure_time_utc, s.arrival_time_utc, s.elapsed_seconds, s.hobbs_duration_seconds, s.hobbs_status, s.row_count
                ORDER BY COALESCE(s.departure_time_utc, s.arrival_time_utc) ASC, m.csv_file_id ASC
            ", array($operationId));
            foreach ($result['rows'] as $row) {
                $candidates[] = $this->historicalGarminCandidateFromDbRow($row);
            }
        }
        return $candidates;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadUnresolvedGarminDetailCandidates(int $csvFileId): array
    {
        if ($csvFileId <= 0 || !$this->tableExists('ipca_garmin_csv_flight_summaries')) {
            return array();
        }
        $result = $this->timedQuery("
            SELECT
                s.csv_file_id,
                s.derivation_status,
                s.tail_number,
                s.departure_airport_code,
                s.arrival_airport_code,
                s.departure_time_utc,
                s.arrival_time_utc,
                s.elapsed_seconds,
                s.hobbs_duration_seconds,
                s.hobbs_status,
                s.row_count
            FROM ipca_garmin_csv_flight_summaries s
            WHERE s.csv_file_id = ?
            LIMIT 1
        ", array($csvFileId));
        $candidates = array();
        foreach ($result['rows'] as $row) {
            $candidates[] = $this->unresolvedGarminCandidateFromDbRow($row);
        }
        return $candidates;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadOrphanRecordingDetailCandidates(int $recordingId): array
    {
        if ($recordingId <= 0 || !$this->tableExists('ipca_cockpit_recordings')) {
            return array();
        }
        $result = $this->timedQuery("
            SELECT
                id,
                recording_uid,
                started_at,
                duration_seconds,
                aircraft_registration,
                upload_status,
                transcription_status,
                reconstruction_status,
                storage_path,
                g3x_storage_path
            FROM ipca_cockpit_recordings
            WHERE id = ?
            LIMIT 1
        ", array($recordingId));
        $candidates = array();
        foreach ($result['rows'] as $row) {
            $candidates[] = $this->orphanRecordingCandidateFromDbRow($row);
        }
        return $candidates;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function currentCandidateFromDbRow(array $row): array
    {
        $recordId = (int)$row['operational_flight_record_id'];
        $versionId = (int)$row['flight_record_version_id'];
        $legIndex = (int)$row['leg_index'];
        return $this->normalizeCandidate(array(
            'event_key' => 'current-event:ofr:' . $recordId,
            'leg_key' => 'current-leg:ofrv:' . $versionId . ':leg:' . $legIndex,
            'source_mode' => 'current_operational',
            'source_branch' => 'current_operational',
            'source_record_keys' => array(
                'operational_flight_record' => 'ofr:' . $recordId,
                'flight_record_version' => 'ofrv:' . $versionId,
                'session' => 'session:' . (int)$row['session_id'],
            ),
            'association_keys' => array('session:' . (int)$row['session_id'], 'ofr:' . $recordId),
            'dedupe_keys' => array('current-leg:ofrv:' . $versionId . ':leg:' . $legIndex),
            'anchor_rank' => !empty($row['finalized_at']) ? 100 : (((string)$row['readiness_status'] === 'ready') ? 90 : 70),
            'leg_structure_type' => 'confirmed_leg',
            'leg_structure_status' => 'confirmed',
            'date' => $this->datePart((string)($row['allocation_start_utc'] ?? $row['avionics_on_utc'] ?? '')),
            'date_sort' => (string)($row['allocation_start_utc'] ?? $row['avionics_on_utc'] ?? ''),
            'aircraft' => $this->provenanceValue((string)($row['aircraft_registration'] ?? ''), (string)($row['aircraft_registration'] ?? ''), 'ipca_flight_sessions.aircraft_registration', 1.0, 'system'),
            'pilot_1' => $this->emptyProvenanceValue(null, 'not_traced'),
            'pilot_1_role' => $this->emptyProvenanceValue(null, 'unresolved'),
            'pilot_2' => $this->emptyProvenanceValue(null, 'not_traced'),
            'pilot_2_role' => $this->emptyProvenanceValue(null, 'unresolved'),
            'departure_local_time' => $this->provenanceValue($row['allocation_start_utc'] ?? null, $row['allocation_start_utc'] ?? null, 'ipca_operational_flight_leg_versions.allocation_start_utc', 0.9, 'system'),
            'arrival_local_time' => $this->provenanceValue($row['allocation_end_utc'] ?? null, $row['allocation_end_utc'] ?? null, 'ipca_operational_flight_leg_versions.allocation_end_utc', 0.9, 'system'),
            'departure_airport' => $this->provenanceValue($row['departure_airport_code'] ?? null, $row['departure_airport_code'] ?? null, 'ipca_operational_flight_leg_versions.departure_airport_code', 0.85, 'system'),
            'arrival_airport' => $this->provenanceValue($row['arrival_airport_code'] ?? null, $row['arrival_airport_code'] ?? null, 'ipca_operational_flight_leg_versions.arrival_airport_code', 0.85, 'system'),
            'departure_hobbs' => $this->provenanceValue($row['hobbs_start_hours'] ?? null, $row['hobbs_start_hours'] ?? null, 'ipca_operational_flight_record_versions.hobbs_start_hours', 0.9, 'system'),
            'arrival_hobbs' => $this->provenanceValue($row['hobbs_end_hours'] ?? null, $row['hobbs_end_hours'] ?? null, 'ipca_operational_flight_record_versions.hobbs_end_hours', 0.9, 'system'),
            'departure_tacho' => $this->provenanceValue($row['tacho_start_hours'] ?? null, $row['tacho_start_hours'] ?? null, 'ipca_operational_flight_record_versions.tacho_start_hours', 0.9, 'system'),
            'arrival_tacho' => $this->provenanceValue($row['tacho_end_hours'] ?? null, $row['tacho_end_hours'] ?? null, 'ipca_operational_flight_record_versions.tacho_end_hours', 0.9, 'system'),
            'hobbs_duration' => $this->durationHours($row['allocated_hobbs_duration_ms'] ?? null),
            'tacho_duration' => $this->durationHours($row['allocated_tacho_duration_ms'] ?? null),
            'landings' => $this->provenanceValue($row['landing_event_count'] ?? null, $row['landing_event_count'] ?? null, 'ipca_operational_flight_leg_versions.landing_event_count', 0.8, 'needs_review'),
            'fuel_out' => $this->provenanceValue($row['leg_fuel_start_usg'] ?? null, $row['leg_fuel_start_usg'] ?? null, 'ipca_operational_flight_leg_versions.fuel_start_usg', 0.7, 'needs_review'),
            'fuel_in' => $this->provenanceValue($row['leg_fuel_end_usg'] ?? null, $row['leg_fuel_end_usg'] ?? null, 'ipca_operational_flight_leg_versions.fuel_end_usg', 0.7, 'needs_review'),
            'mission' => $this->emptyProvenanceValue(null, 'unresolved'),
            'processing_status' => ((string)($row['readiness_status'] ?? '') === 'ready') ? 'ready' : 'pending',
            'verification_status' => 'needs_review',
            'finalization_status' => !empty($row['finalized_at']) ? 'finalized' : 'draft',
            'conflict_status' => 'none',
            'evidence_completeness_status' => 'partial',
        ), 'current_operational');
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function historicalCandidateFromDbRow(array $row): array
    {
        $operationId = (int)$row['aircraft_operation_id'];
        return $this->normalizeCandidate(array(
            'event_key' => 'historical-event:ao:' . $operationId,
            'leg_key' => 'historical-leg:ao:' . $operationId . ':aggregate',
            'source_mode' => 'historical_flightcircle',
            'source_branch' => 'historical_flightcircle',
            'source_record_keys' => array('aircraft_operation' => 'ao:' . $operationId),
            'association_keys' => array('ao:' . $operationId),
            'dedupe_keys' => array('historical-leg:ao:' . $operationId . ':aggregate'),
            'anchor_rank' => 80,
            'leg_structure_type' => 'aggregate_dispatch',
            'leg_structure_status' => 'aggregate',
            'date' => $this->datePart((string)($row['scheduled_start_local'] ?? '')),
            'date_sort' => (string)($row['scheduled_start_local'] ?? ''),
            'aircraft' => $this->provenanceValue((string)($row['aircraft_registration'] ?? ''), (string)($row['aircraft_registration'] ?? ''), 'ipca_aircraft_operations.aircraft_registration', 0.8, (string)($row['review_status'] ?? 'needs_review')),
            'pilot_1' => $this->provenanceValue($row['user_text'] ?? null, null, 'ipca_aircraft_operations.user_text', 0.4, 'unresolved'),
            'pilot_1_role' => $this->emptyProvenanceValue(null, 'unresolved'),
            'pilot_2' => $this->provenanceValue($row['instructor_text'] ?? null, null, 'ipca_aircraft_operations.instructor_text', 0.4, 'unresolved'),
            'pilot_2_role' => $this->emptyProvenanceValue(null, 'unresolved'),
            'departure_local_time' => $this->provenanceValue($row['scheduled_start_local'] ?? null, $row['scheduled_start_local'] ?? null, 'ipca_aircraft_operations.scheduled_start_local', 0.5, 'needs_review'),
            'arrival_local_time' => $this->provenanceValue($row['scheduled_end_local'] ?? null, $row['scheduled_end_local'] ?? null, 'ipca_aircraft_operations.scheduled_end_local', 0.5, 'needs_review'),
            'departure_airport' => $this->emptyProvenanceValue($row['route_text'] ?? null, 'unresolved'),
            'arrival_airport' => $this->emptyProvenanceValue($row['route_text'] ?? null, 'unresolved'),
            'departure_hobbs' => $this->emptyProvenanceValue(null, 'not_loaded'),
            'arrival_hobbs' => $this->emptyProvenanceValue(null, 'not_loaded'),
            'departure_tacho' => $this->emptyProvenanceValue(null, 'not_loaded'),
            'arrival_tacho' => $this->emptyProvenanceValue(null, 'not_loaded'),
            'hobbs_duration' => $this->emptyProvenanceValue(null, 'not_loaded'),
            'tacho_duration' => $this->emptyProvenanceValue(null, 'not_loaded'),
            'landings' => $this->emptyProvenanceValue(null, 'unresolved'),
            'fuel_out' => $this->emptyProvenanceValue(null, 'not_loaded'),
            'fuel_in' => $this->emptyProvenanceValue(null, 'not_loaded'),
            'mission' => $this->provenanceValue($row['mission_notes'] ?? null, null, 'ipca_aircraft_operations.mission_notes', 0.2, 'unresolved'),
            'processing_status' => 'ready',
            'verification_status' => $this->normalizeVerificationStatus((string)($row['review_status'] ?? 'needs_review')),
            'finalization_status' => ((string)($row['operation_status'] ?? '') === 'approved') ? 'finalized' : 'proposed',
            'conflict_status' => 'none',
            'evidence_completeness_status' => 'partial',
        ), 'historical_flightcircle');
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function historicalGarminCandidateFromDbRow(array $row): array
    {
        $operationId = (int)$row['operation_id'];
        $csvFileId = (int)$row['csv_file_id'];
        $start = (string)($row['departure_time_utc'] ?? '');
        $end = (string)($row['arrival_time_utc'] ?? '');
        return $this->normalizeCandidate(array(
            'event_key' => 'historical-event:ao:' . $operationId,
            'leg_key' => 'historical-garmin-leg:ao:' . $operationId . ':csv:' . $csvFileId,
            'source_mode' => 'historical_flightcircle',
            'source_branch' => 'historical_garmin_legs',
            'source_record_keys' => array('aircraft_operation' => 'ao:' . $operationId, 'csv_file' => 'csv:' . $csvFileId),
            'association_keys' => array('ao:' . $operationId, 'csv:' . $csvFileId),
            'dedupe_keys' => array('historical-garmin-leg:ao:' . $operationId . ':csv:' . $csvFileId),
            'anchor_rank' => 85,
            'leg_structure_type' => ((string)($row['match_status'] ?? '') === 'high_confidence') ? 'confirmed_leg' : 'inferred_leg',
            'leg_structure_status' => ((string)($row['match_status'] ?? '') === 'high_confidence') ? 'confirmed' : 'inferred',
            'date' => $this->datePart($start),
            'date_sort' => $start,
            'aircraft' => $this->provenanceValue($row['tail_number'] ?? null, $row['tail_number'] ?? null, 'ipca_garmin_csv_flight_summaries.tail_number', 0.85, 'needs_review'),
            'pilot_1' => $this->emptyProvenanceValue(null, 'not_traced'),
            'pilot_1_role' => $this->emptyProvenanceValue(null, 'unresolved'),
            'pilot_2' => $this->emptyProvenanceValue(null, 'not_traced'),
            'pilot_2_role' => $this->emptyProvenanceValue(null, 'unresolved'),
            'departure_local_time' => $this->provenanceValue($start, $start !== '' ? $start : null, 'ipca_garmin_csv_flight_summaries.departure_time_utc', 0.75, 'needs_review'),
            'arrival_local_time' => $this->provenanceValue($end, $end !== '' ? $end : null, 'ipca_garmin_csv_flight_summaries.arrival_time_utc', 0.75, 'needs_review'),
            'departure_airport' => $this->provenanceValue($row['departure_airport_code'] ?? null, $row['departure_airport_code'] ?? null, 'ipca_garmin_csv_flight_summaries.departure_airport_code', 0.75, 'needs_review'),
            'arrival_airport' => $this->provenanceValue($row['arrival_airport_code'] ?? null, $row['arrival_airport_code'] ?? null, 'ipca_garmin_csv_flight_summaries.arrival_airport_code', 0.75, 'needs_review'),
            'departure_hobbs' => $this->emptyProvenanceValue(null, 'detail_required'),
            'arrival_hobbs' => $this->emptyProvenanceValue(null, 'detail_required'),
            'departure_tacho' => $this->emptyProvenanceValue(null, 'detail_required'),
            'arrival_tacho' => $this->emptyProvenanceValue(null, 'detail_required'),
            'hobbs_duration' => $this->provenanceValue($row['hobbs_duration_seconds'] ?? null, is_numeric($row['hobbs_duration_seconds'] ?? null) ? round(((float)$row['hobbs_duration_seconds']) / 3600.0, 3) : null, 'ipca_garmin_csv_flight_summaries.hobbs_duration_seconds', 0.75, 'needs_review'),
            'tacho_duration' => $this->emptyProvenanceValue(null, 'detail_required'),
            'landings' => $this->emptyProvenanceValue(null, 'unresolved'),
            'fuel_out' => $this->emptyProvenanceValue(null, 'not_available'),
            'fuel_in' => $this->emptyProvenanceValue(null, 'not_available'),
            'mission' => $this->emptyProvenanceValue(null, 'unresolved'),
            'processing_status' => ((string)($row['derivation_status'] ?? '') === 'ok') ? 'ready' : 'pending',
            'verification_status' => 'needs_review',
            'finalization_status' => 'proposed',
            'conflict_status' => 'none',
            'evidence_completeness_status' => 'partial',
            'fdm' => $this->evidenceState(((string)($row['derivation_status'] ?? '') === 'ok' && (int)($row['row_count'] ?? 0) > 0) ? 'usable' : 'incomplete', 1, 'csv:' . $csvFileId, (string)($row['derivation_status'] ?? ''), (float)($row['confidence_score'] ?? 0) / 100.0),
        ), 'historical_garmin_legs');
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function unresolvedGarminCandidateFromDbRow(array $row): array
    {
        $csvFileId = (int)$row['csv_file_id'];
        $start = (string)($row['departure_time_utc'] ?? '');
        $end = (string)($row['arrival_time_utc'] ?? '');
        return $this->normalizeCandidate(array(
            'event_key' => 'unresolved-garmin:csv:' . $csvFileId,
            'leg_key' => 'unresolved-garmin-leg:csv:' . $csvFileId . ':summary',
            'source_mode' => 'unresolved_garmin',
            'source_branch' => 'unresolved_garmin',
            'source_record_keys' => array('csv_file' => 'csv:' . $csvFileId),
            'association_keys' => array('unlinked:csv:' . $csvFileId),
            'dedupe_keys' => array('unlinked:csv:' . $csvFileId),
            'anchor_rank' => 20,
            'leg_structure_type' => 'inferred_leg',
            'leg_structure_status' => 'inferred',
            'date' => $this->datePart($start),
            'date_sort' => $start,
            'aircraft' => $this->provenanceValue($row['tail_number'] ?? null, $row['tail_number'] ?? null, 'ipca_garmin_csv_flight_summaries.tail_number', 0.7, 'unreviewed'),
            'pilot_1' => $this->emptyProvenanceValue(null, 'not_available'),
            'pilot_1_role' => $this->emptyProvenanceValue(null, 'not_available'),
            'pilot_2' => $this->emptyProvenanceValue(null, 'not_available'),
            'pilot_2_role' => $this->emptyProvenanceValue(null, 'not_available'),
            'departure_local_time' => $this->provenanceValue($start, $start !== '' ? $start : null, 'ipca_garmin_csv_flight_summaries.departure_time_utc', 0.7, 'unreviewed'),
            'arrival_local_time' => $this->provenanceValue($end, $end !== '' ? $end : null, 'ipca_garmin_csv_flight_summaries.arrival_time_utc', 0.7, 'unreviewed'),
            'departure_airport' => $this->provenanceValue($row['departure_airport_code'] ?? null, $row['departure_airport_code'] ?? null, 'ipca_garmin_csv_flight_summaries.departure_airport_code', 0.7, 'unreviewed'),
            'arrival_airport' => $this->provenanceValue($row['arrival_airport_code'] ?? null, $row['arrival_airport_code'] ?? null, 'ipca_garmin_csv_flight_summaries.arrival_airport_code', 0.7, 'unreviewed'),
            'departure_hobbs' => $this->emptyProvenanceValue(null, 'detail_required'),
            'arrival_hobbs' => $this->emptyProvenanceValue(null, 'detail_required'),
            'departure_tacho' => $this->emptyProvenanceValue(null, 'detail_required'),
            'arrival_tacho' => $this->emptyProvenanceValue(null, 'detail_required'),
            'hobbs_duration' => $this->provenanceValue($row['hobbs_duration_seconds'] ?? null, is_numeric($row['hobbs_duration_seconds'] ?? null) ? round(((float)$row['hobbs_duration_seconds']) / 3600.0, 3) : null, 'ipca_garmin_csv_flight_summaries.hobbs_duration_seconds', 0.7, 'unreviewed'),
            'tacho_duration' => $this->emptyProvenanceValue(null, 'detail_required'),
            'landings' => $this->emptyProvenanceValue(null, 'unresolved'),
            'fuel_out' => $this->emptyProvenanceValue(null, 'not_available'),
            'fuel_in' => $this->emptyProvenanceValue(null, 'not_available'),
            'mission' => $this->emptyProvenanceValue(null, 'not_available'),
            'processing_status' => ((string)($row['derivation_status'] ?? '') === 'ok') ? 'ready' : 'pending',
            'verification_status' => 'unreviewed',
            'finalization_status' => 'draft',
            'conflict_status' => 'warning',
            'evidence_completeness_status' => 'unresolved',
            'fdm' => $this->evidenceState(((string)($row['derivation_status'] ?? '') === 'ok' && (int)($row['row_count'] ?? 0) > 0) ? 'present' : 'incomplete', 1, 'csv:' . $csvFileId, (string)($row['derivation_status'] ?? ''), 0.5),
        ), 'unresolved_garmin');
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function orphanRecordingCandidateFromDbRow(array $row): array
    {
        $recordingId = (int)$row['id'];
        $started = (string)($row['started_at'] ?? '');
        return $this->normalizeCandidate(array(
            'event_key' => 'orphan-recording:rec:' . $recordingId,
            'leg_key' => 'orphan-recording-leg:rec:' . $recordingId . ':recording',
            'source_mode' => 'orphan_recording',
            'source_branch' => 'orphan_recording',
            'source_record_keys' => array('cockpit_recording' => 'rec:' . $recordingId, 'recording_uid' => (string)($row['recording_uid'] ?? '')),
            'association_keys' => array('unlinked:rec:' . $recordingId),
            'dedupe_keys' => array('unlinked:rec:' . $recordingId),
            'anchor_rank' => 10,
            'leg_structure_type' => 'unresolved_leg_structure',
            'leg_structure_status' => 'unresolved',
            'date' => $this->datePart($started),
            'date_sort' => $started,
            'aircraft' => $this->provenanceValue($row['aircraft_registration'] ?? null, $row['aircraft_registration'] ?? null, 'ipca_cockpit_recordings.aircraft_registration', 0.5, 'unreviewed'),
            'pilot_1' => $this->emptyProvenanceValue(null, 'not_available'),
            'pilot_1_role' => $this->emptyProvenanceValue(null, 'not_available'),
            'pilot_2' => $this->emptyProvenanceValue(null, 'not_available'),
            'pilot_2_role' => $this->emptyProvenanceValue(null, 'not_available'),
            'departure_local_time' => $this->provenanceValue($started, $started !== '' ? $started : null, 'ipca_cockpit_recordings.started_at', 0.5, 'unreviewed'),
            'arrival_local_time' => $this->emptyProvenanceValue(null, 'unresolved'),
            'departure_airport' => $this->emptyProvenanceValue(null, 'not_available'),
            'arrival_airport' => $this->emptyProvenanceValue(null, 'not_available'),
            'departure_hobbs' => $this->emptyProvenanceValue(null, 'not_available'),
            'arrival_hobbs' => $this->emptyProvenanceValue(null, 'not_available'),
            'departure_tacho' => $this->emptyProvenanceValue(null, 'not_available'),
            'arrival_tacho' => $this->emptyProvenanceValue(null, 'not_available'),
            'hobbs_duration' => $this->emptyProvenanceValue(null, 'not_available'),
            'tacho_duration' => $this->emptyProvenanceValue(null, 'not_available'),
            'landings' => $this->emptyProvenanceValue(null, 'not_available'),
            'fuel_out' => $this->emptyProvenanceValue(null, 'not_available'),
            'fuel_in' => $this->emptyProvenanceValue(null, 'not_available'),
            'mission' => $this->emptyProvenanceValue(null, 'not_available'),
            'processing_status' => $this->normalizeProcessingStatus((string)($row['upload_status'] ?? 'pending')),
            'verification_status' => 'unreviewed',
            'finalization_status' => 'draft',
            'conflict_status' => 'warning',
            'evidence_completeness_status' => 'unresolved',
            'cvr' => $this->evidenceState(((string)($row['upload_status'] ?? '') === 'uploaded' && (float)($row['duration_seconds'] ?? 0) > 0) ? 'present' : 'incomplete', 1, 'rec:' . $recordingId, (string)($row['upload_status'] ?? ''), 0.5, array('audio_duration_seconds' => (float)($row['duration_seconds'] ?? 0))),
            'transcript' => $this->transcriptState(((string)($row['transcription_status'] ?? '') === 'ready') ? 'usable' : $this->normalizeEvidenceState((string)($row['transcription_status'] ?? 'not_available')), ((string)($row['transcription_status'] ?? '') === 'ready'), false, (string)($row['transcription_status'] ?? '')),
        ), 'orphan_recording');
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @return array<string,mixed>
     */
    private function deduplicateCandidates(array $candidates): array
    {
        $suppressed = array();
        $eventsWithExplicitLegs = array();
        foreach ($candidates as $candidate) {
            if (
                in_array((string)($candidate['leg_structure_type'] ?? ''), array('confirmed_leg', 'inferred_leg'), true)
                && str_starts_with((string)($candidate['leg_key'] ?? ''), 'historical-garmin-leg:')
            ) {
                $eventsWithExplicitLegs[(string)$candidate['event_key']] = true;
            }
        }

        $filteredCandidates = array();
        foreach ($candidates as $candidate) {
            $eventKey = (string)$candidate['event_key'];
            if (($candidate['leg_structure_type'] ?? '') === 'aggregate_dispatch' && isset($eventsWithExplicitLegs[$eventKey])) {
                $suppressed[] = array(
                    'event_key' => $eventKey,
                    'leg_key' => $candidate['leg_key'],
                    'suppressed_by_event_key' => $eventKey,
                    'suppressed_by_leg_key' => 'explicit_historical_garmin_legs',
                    'association_keys' => $candidate['association_keys'] ?? array(),
                    'dedupe_keys' => $candidate['dedupe_keys'] ?? array(),
                    'reason' => 'explicit_operation_has_confirmed_garmin_leg_boundaries',
                    'source_mode' => $candidate['source_mode'],
                );
                continue;
            }
            $filteredCandidates[] = $candidate;
        }

        $candidates = $filteredCandidates;
        $groups = array();
        foreach ($candidates as $index => $candidate) {
            $keys = isset($candidate['dedupe_keys']) && is_array($candidate['dedupe_keys']) ? $candidate['dedupe_keys'] : array();
            $eligibleKeys = array_values(array_filter(array_map('strval', $keys), static function (string $key): bool {
                return $key !== '' && !str_starts_with($key, 'ambiguous:') && !str_starts_with($key, 'unlinked:');
            }));
            if ($eligibleKeys === array()) {
                $groups['self:' . $index] = array($index);
                continue;
            }
            sort($eligibleKeys);
            $groupKey = 'assoc:' . implode('|', $eligibleKeys);
            $groups[$groupKey][] = $index;
        }

        $visible = array();
        foreach ($groups as $groupKey => $indexes) {
            if (count($indexes) === 1) {
                $visible[] = $candidates[$indexes[0]];
                continue;
            }
            $winnerIndex = $indexes[0];
            foreach ($indexes as $index) {
                if ($this->candidateRank($candidates[$index]) > $this->candidateRank($candidates[$winnerIndex])) {
                    $winnerIndex = $index;
                }
            }
            $visible[] = $candidates[$winnerIndex];
            foreach ($indexes as $index) {
                if ($index === $winnerIndex) {
                    continue;
                }
                $suppressed[] = array(
                    'event_key' => $candidates[$index]['event_key'],
                    'leg_key' => $candidates[$index]['leg_key'],
                    'suppressed_by_event_key' => $candidates[$winnerIndex]['event_key'],
                    'suppressed_by_leg_key' => $candidates[$winnerIndex]['leg_key'],
                    'association_keys' => $this->sharedKeys($candidates[$index], $candidates[$winnerIndex], 'association_keys'),
                    'dedupe_keys' => $this->sharedKeys($candidates[$index], $candidates[$winnerIndex], 'dedupe_keys'),
                    'reason' => 'explicit_association_to_preferred_anchor',
                    'source_mode' => $candidates[$index]['source_mode'],
                );
            }
        }

        return array('visible_candidates' => $visible, 'suppressed_duplicates' => $suppressed);
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @return list<array<string,mixed>>
     */
    private function attachBatchedEvidence(array $candidates): array
    {
        if ($this->fixtureData === null && $this->pdo instanceof PDO) {
            return $this->attachLiveBatchedEvidence($candidates);
        }

        $fixtureEvidence = isset($this->fixtureData['evidence']) && is_array($this->fixtureData['evidence']) ? $this->fixtureData['evidence'] : array();
        foreach ($candidates as $idx => $candidate) {
            $eventKey = (string)$candidate['event_key'];
            if (isset($fixtureEvidence[$eventKey]) && is_array($fixtureEvidence[$eventKey])) {
                $candidates[$idx] = $this->overlayArrays($candidate, $fixtureEvidence[$eventKey]);
            }
            foreach (array('fdm', 'cvr', 'adsb', 'replay', 'flightcircle') as $evidenceType) {
                if (!isset($candidates[$idx][$evidenceType]) || !is_array($candidates[$idx][$evidenceType])) {
                    $candidates[$idx][$evidenceType] = $this->evidenceState('not_available');
                }
            }
            if (!isset($candidates[$idx]['transcript']) || !is_array($candidates[$idx]['transcript'])) {
                $candidates[$idx]['transcript'] = $this->transcriptState('not_available');
            }
            $candidates[$idx]['evidence_completeness_status'] = $this->evidenceCompletenessStatus($candidates[$idx]);
        }
        return $candidates;
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @return list<array<string,mixed>>
     */
    private function attachLiveBatchedEvidence(array $candidates): array
    {
        $operationIds = array();
        $csvIds = array();
        $recordingIds = array();
        foreach ($candidates as $candidate) {
            foreach (($candidate['source_record_keys'] ?? array()) as $key) {
                $key = (string)$key;
                if (preg_match('/^ao:(\d+)$/', $key, $m) === 1) {
                    $operationIds[] = (int)$m[1];
                }
                if (preg_match('/^csv:(\d+)$/', $key, $m) === 1) {
                    $csvIds[] = (int)$m[1];
                }
                if (preg_match('/^rec:(\d+)$/', $key, $m) === 1) {
                    $recordingIds[] = (int)$m[1];
                }
            }
        }
        $operationIds = array_values(array_unique(array_filter($operationIds)));
        $csvIds = array_values(array_unique(array_filter($csvIds)));
        $recordingIds = array_values(array_unique(array_filter($recordingIds)));

        $matchesByOperation = $this->liveMatchesByOperation($operationIds);
        $proposalsByOperation = $this->liveHistoricalProposalsByOperation($operationIds);
        $replayByCsv = $this->liveReplayPayloadsByCsv($csvIds);
        $recordingStatus = $this->liveRecordingStatus($recordingIds);
        $recordingReplay = $this->liveRecordingReplayCounts($recordingIds);
        $adsbByRecording = $this->liveAdsbByRecording($recordingIds);

        foreach ($candidates as $idx => $candidate) {
            $eventKey = (string)$candidate['event_key'];
            $operationId = $this->idFromSourceKeys($candidate, 'ao');
            $csvId = $this->idFromSourceKeys($candidate, 'csv');
            $recordingId = $this->idFromSourceKeys($candidate, 'rec');

            if ($operationId > 0) {
                $match = $matchesByOperation[$operationId] ?? null;
                $proposalCount = (int)($proposalsByOperation[$operationId]['proposal_count'] ?? 0);
                $candidates[$idx]['flightcircle'] = $this->evidenceState('present', 1, 'ao:' . $operationId, (string)($candidate['verification_status'] ?? 'needs_review'), 0.8);
                if (is_array($match)) {
                    $state = ((int)($match['usable_count'] ?? 0) > 0) ? 'present' : 'unresolved';
                    $candidates[$idx]['fdm'] = $this->evidenceState($state, (int)($match['csv_count'] ?? 0), !empty($match['primary_csv_file_id']) ? 'csv:' . (int)$match['primary_csv_file_id'] : null, (string)($match['status_summary'] ?? ''), ((float)($match['max_confidence'] ?? 0)) / 100.0, array('match_statuses' => $match['status_summary'] ?? ''));
                }
                if ($proposalCount > 0) {
                    $candidates[$idx]['proposal'] = $this->evidenceState('present', $proposalCount, 'historical-proposals:ao:' . $operationId, (string)($proposalsByOperation[$operationId]['status_summary'] ?? 'Proposed'), 0.7);
                }
            }

            if ($csvId > 0) {
                $existingFdm = isset($candidates[$idx]['fdm']) && is_array($candidates[$idx]['fdm']) ? $candidates[$idx]['fdm'] : null;
                if ($existingFdm === null || ($existingFdm['state'] ?? '') === 'not_available') {
                    $candidates[$idx]['fdm'] = $this->evidenceState('present', 1, 'csv:' . $csvId, 'summary_present', 0.6);
                }
                if (isset($replayByCsv[$csvId])) {
                    $payload = $replayByCsv[$csvId];
                    $usable = (string)($payload['build_status'] ?? '') === 'ready' && (int)($payload['sample_count'] ?? 0) > 0 && trim((string)($payload['replay_key'] ?? '')) !== '';
                    $candidates[$idx]['replay'] = $this->evidenceState($usable ? 'usable' : $this->normalizeEvidenceState((string)($payload['build_status'] ?? 'present')), 1, 'csv:' . $csvId, (string)($payload['build_status'] ?? ''), $usable ? 0.9 : 0.2, array('sample_count' => (int)($payload['sample_count'] ?? 0))) + array(
                        'replay_type' => 'standalone_garmin',
                        'launch_url' => $usable ? '/admin/cockpit_recorder_replay.php?standalone=' . rawurlencode((string)$payload['replay_key']) : null,
                    );
                }
            }

            if ($recordingId > 0 && isset($recordingStatus[$recordingId])) {
                $recording = $recordingStatus[$recordingId];
                $duration = (float)($recording['duration_seconds'] ?? 0);
                $uploadStatus = (string)($recording['upload_status'] ?? '');
                $transcription = (string)($recording['transcription_status'] ?? '');
                $candidates[$idx]['cvr'] = $this->evidenceState(($uploadStatus === 'uploaded' && $duration > 0) ? 'present' : 'incomplete', 1, 'rec:' . $recordingId, $uploadStatus, 0.6, array('audio_duration_seconds' => $duration));
                $candidates[$idx]['transcript'] = $this->transcriptState($transcription === 'ready' ? 'usable' : $this->normalizeEvidenceState($transcription), $transcription === 'ready', false, $transcription);
                $replaySamples = (int)($recordingReplay[$recordingId]['sample_count'] ?? 0);
                if ($replaySamples > 0 || (string)($recording['reconstruction_status'] ?? '') === 'ready') {
                    $usable = $replaySamples > 0 && (string)($recording['reconstruction_status'] ?? '') === 'ready';
                    $candidates[$idx]['replay'] = $this->evidenceState($usable ? 'usable' : 'present', 1, 'rec:' . $recordingId, (string)($recording['reconstruction_status'] ?? ''), $usable ? 0.9 : 0.5, array('sample_count' => $replaySamples)) + array(
                        'replay_type' => 'cockpit_recording',
                        'launch_url' => $usable ? '/admin/cockpit_recorder_replay.php?id=' . rawurlencode((string)($recording['recording_uid'] ?? $recordingId)) : null,
                    );
                }
                if (isset($adsbByRecording[$recordingId])) {
                    $adsb = $adsbByRecording[$recordingId];
                    $ready = (string)($adsb['status'] ?? '') === 'ready' && ((int)($adsb['ownship_sample_count'] ?? 0) + (int)($adsb['traffic_sample_count'] ?? 0)) > 0;
                    $candidates[$idx]['adsb'] = $this->evidenceState($ready ? 'usable' : $this->normalizeEvidenceState((string)($adsb['status'] ?? 'not_available')), 1, 'adsb:rec:' . $recordingId, (string)($adsb['status'] ?? ''), $ready ? 0.85 : 0.3, array(
                        'ownship_sample_count' => (int)($adsb['ownship_sample_count'] ?? 0),
                        'traffic_sample_count' => (int)($adsb['traffic_sample_count'] ?? 0),
                    ));
                }
            }

            foreach (array('fdm', 'cvr', 'adsb', 'replay', 'flightcircle') as $evidenceType) {
                if (!isset($candidates[$idx][$evidenceType]) || !is_array($candidates[$idx][$evidenceType])) {
                    $candidates[$idx][$evidenceType] = $this->evidenceState('not_available');
                }
            }
            if (!isset($candidates[$idx]['transcript']) || !is_array($candidates[$idx]['transcript'])) {
                $candidates[$idx]['transcript'] = $this->transcriptState('not_available', false, false, 'not_available');
            }
            $candidates[$idx]['evidence_completeness_status'] = $this->evidenceCompletenessStatus($candidates[$idx]);
        }

        return $candidates;
    }

    /**
     * @param list<int> $operationIds
     * @return array<int,array<string,mixed>>
     */
    private function liveMatchesByOperation(array $operationIds): array
    {
        if ($operationIds === array() || !$this->tableExists('ipca_flightcircle_garmin_matches')) {
            return array();
        }
        $rows = $this->timedInQuery("
            SELECT
                operation_id,
                COUNT(DISTINCT csv_file_id) AS csv_count,
                SUM(CASE WHEN match_status IN ('high_confidence','probable') THEN 1 ELSE 0 END) AS usable_count,
                MAX(csv_file_id) AS primary_csv_file_id,
                MAX(confidence_score) AS max_confidence,
                GROUP_CONCAT(DISTINCT match_status ORDER BY match_status SEPARATOR ',') AS status_summary
            FROM ipca_flightcircle_garmin_matches
            WHERE operation_id IN (%s)
            GROUP BY operation_id
        ", $operationIds);
        $out = array();
        foreach ($rows as $row) {
            $out[(int)$row['operation_id']] = $row;
        }
        return $out;
    }

    /**
     * @param list<int> $operationIds
     * @return array<int,array<string,mixed>>
     */
    private function liveHistoricalProposalsByOperation(array $operationIds): array
    {
        if ($operationIds === array() || !$this->tableExists('ipca_historical_logbook_proposals')) {
            return array();
        }
        $rows = $this->timedInQuery("
            SELECT
                operation_id,
                COUNT(*) AS proposal_count,
                GROUP_CONCAT(DISTINCT review_status ORDER BY review_status SEPARATOR ',') AS status_summary
            FROM ipca_historical_logbook_proposals
            WHERE operation_id IN (%s)
            GROUP BY operation_id
        ", $operationIds);
        $out = array();
        foreach ($rows as $row) {
            $out[(int)$row['operation_id']] = $row;
        }
        return $out;
    }

    /**
     * @param list<int> $csvIds
     * @return array<int,array<string,mixed>>
     */
    private function liveReplayPayloadsByCsv(array $csvIds): array
    {
        if ($csvIds === array() || !$this->tableExists('ipca_garmin_csv_replay_payloads')) {
            return array();
        }
        $rows = $this->timedInQuery("
            SELECT garmin_csv_file_id, replay_key, sample_count, build_status, last_error, built_at
            FROM ipca_garmin_csv_replay_payloads
            WHERE garmin_csv_file_id IN (%s)
            ORDER BY built_at DESC, id DESC
        ", $csvIds);
        $out = array();
        foreach ($rows as $row) {
            $id = (int)$row['garmin_csv_file_id'];
            if (!isset($out[$id])) {
                $out[$id] = $row;
            }
        }
        return $out;
    }

    /**
     * @param list<int> $recordingIds
     * @return array<int,array<string,mixed>>
     */
    private function liveRecordingStatus(array $recordingIds): array
    {
        if ($recordingIds === array() || !$this->tableExists('ipca_cockpit_recordings')) {
            return array();
        }
        $rows = $this->timedInQuery("
            SELECT id, recording_uid, duration_seconds, upload_status, transcription_status, reconstruction_status
            FROM ipca_cockpit_recordings
            WHERE id IN (%s)
        ", $recordingIds);
        $out = array();
        foreach ($rows as $row) {
            $out[(int)$row['id']] = $row;
        }
        return $out;
    }

    /**
     * @param list<int> $recordingIds
     * @return array<int,array<string,mixed>>
     */
    private function liveRecordingReplayCounts(array $recordingIds): array
    {
        if ($recordingIds === array() || !$this->tableExists('ipca_cockpit_replay_samples')) {
            return array();
        }
        $rows = $this->timedInQuery("
            SELECT recording_id, COUNT(*) AS sample_count
            FROM ipca_cockpit_replay_samples
            WHERE recording_id IN (%s)
            GROUP BY recording_id
        ", $recordingIds);
        $out = array();
        foreach ($rows as $row) {
            $out[(int)$row['recording_id']] = $row;
        }
        return $out;
    }

    /**
     * @param list<int> $recordingIds
     * @return array<int,array<string,mixed>>
     */
    private function liveAdsbByRecording(array $recordingIds): array
    {
        if ($recordingIds === array() || !$this->tableExists('ipca_cockpit_adsb_enrichments')) {
            return array();
        }
        $rows = $this->timedInQuery("
            SELECT recording_id, status, ownship_sample_count, traffic_sample_count
            FROM ipca_cockpit_adsb_enrichments
            WHERE recording_id IN (%s)
        ", $recordingIds);
        $out = array();
        foreach ($rows as $row) {
            $out[(int)$row['recording_id']] = $row;
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    private function timedInQuery(string $sqlTemplate, array $ids): array
    {
        if (!$this->pdo instanceof PDO || $ids === array()) {
            return array();
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === array()) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = sprintf($sqlTemplate, $placeholders);
        $started = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $elapsed = round((microtime(true) - $started) * 1000.0, 3);
        $this->lastEvidenceQueryCount += 1;
        $this->lastEvidenceQueryMs += $elapsed;
        return is_array($rows) ? $rows : array();
    }

    /**
     * @param array<string,mixed> $candidate
     */
    private function idFromSourceKeys(array $candidate, string $prefix): int
    {
        foreach (($candidate['source_record_keys'] ?? array()) as $key) {
            if (preg_match('/^' . preg_quote($prefix, '/') . ':(\d+)$/', (string)$key, $matches) === 1) {
                return (int)$matches[1];
            }
        }
        return 0;
    }

    /**
     * @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    private function candidateToRow(array $candidate): array
    {
        $replay = isset($candidate['replay']) && is_array($candidate['replay']) ? $candidate['replay'] : $this->evidenceState('not_available');
        $actions = array();
        if (($replay['state'] ?? '') === 'usable' && !empty($replay['launch_url'])) {
            $actions[] = array('type' => 'launch_replay', 'url' => $replay['launch_url'], 'source_key' => $replay['source_key'] ?? null);
        }
        $actions[] = array('type' => 'view_detail', 'event_key' => $candidate['event_key']);

        return array(
            'event_key' => $candidate['event_key'],
            'leg_key' => $candidate['leg_key'],
            'source_mode' => $candidate['source_mode'],
            'leg_structure_type' => $candidate['leg_structure_type'],
            'leg_structure_status' => $candidate['leg_structure_status'],
            'date' => $candidate['date'],
            'aircraft' => $candidate['aircraft'],
            'pilot_1' => $candidate['pilot_1'],
            'pilot_1_role' => $candidate['pilot_1_role'],
            'pilot_2' => $candidate['pilot_2'],
            'pilot_2_role' => $candidate['pilot_2_role'],
            'departure_local_time' => $candidate['departure_local_time'],
            'departure_airport' => $candidate['departure_airport'],
            'departure_hobbs' => $candidate['departure_hobbs'],
            'departure_tacho' => $candidate['departure_tacho'],
            'hobbs_duration' => $candidate['hobbs_duration'],
            'tacho_duration' => $candidate['tacho_duration'],
            'arrival_airport' => $candidate['arrival_airport'],
            'arrival_local_time' => $candidate['arrival_local_time'],
            'arrival_hobbs' => $candidate['arrival_hobbs'],
            'arrival_tacho' => $candidate['arrival_tacho'],
            'landings' => $candidate['landings'],
            'fuel_out' => $candidate['fuel_out'],
            'fuel_in' => $candidate['fuel_in'],
            'mission' => $candidate['mission'],
            'processing_status' => $candidate['processing_status'],
            'verification_status' => $candidate['verification_status'],
            'finalization_status' => $candidate['finalization_status'],
            'conflict_status' => $candidate['conflict_status'],
            'evidence_completeness_status' => $candidate['evidence_completeness_status'],
            'fdm' => $candidate['fdm'],
            'cvr' => $candidate['cvr'],
            'adsb' => $candidate['adsb'],
            'replay' => $replay,
            'available_actions' => $actions,
            'provenance' => $candidate['source_record_keys'] ?? array(),
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeCandidate(array $row, string $branch): array
    {
        $row['source_branch'] = (string)($row['source_branch'] ?? $branch);
        $row['association_keys'] = isset($row['association_keys']) && is_array($row['association_keys']) ? array_values(array_map('strval', $row['association_keys'])) : array();
        $row['dedupe_keys'] = isset($row['dedupe_keys']) && is_array($row['dedupe_keys']) ? array_values(array_map('strval', $row['dedupe_keys'])) : array();
        $row['source_record_keys'] = isset($row['source_record_keys']) && is_array($row['source_record_keys']) ? $row['source_record_keys'] : array();
        $row['anchor_rank'] = (int)($row['anchor_rank'] ?? 0);
        $row['date_sort'] = (string)($row['date_sort'] ?? $row['date'] ?? '');
        foreach (array('aircraft', 'pilot_1', 'pilot_1_role', 'pilot_2', 'pilot_2_role', 'departure_local_time', 'departure_airport', 'departure_hobbs', 'departure_tacho', 'hobbs_duration', 'tacho_duration', 'arrival_airport', 'arrival_local_time', 'arrival_hobbs', 'arrival_tacho', 'landings', 'fuel_out', 'fuel_in', 'mission') as $field) {
            if (!isset($row[$field]) || !is_array($row[$field])) {
                $row[$field] = $this->emptyProvenanceValue($row[$field] ?? null, 'unresolved');
            }
        }
        $row['processing_status'] = $this->normalizeProcessingStatus((string)($row['processing_status'] ?? 'pending'));
        $row['verification_status'] = $this->normalizeVerificationStatus((string)($row['verification_status'] ?? 'needs_review'));
        $row['finalization_status'] = $this->normalizeFinalizationStatus((string)($row['finalization_status'] ?? 'draft'));
        $row['conflict_status'] = $this->normalizeConflictStatus((string)($row['conflict_status'] ?? 'none'));
        $row['leg_structure_status'] = (string)($row['leg_structure_status'] ?? 'unresolved');
        $row['leg_structure_type'] = (string)($row['leg_structure_type'] ?? 'unresolved_leg_structure');
        $row['event_start'] = $row['event_start'] ?? ($row['departure_local_time']['resolved_value'] ?? null);
        $row['event_end'] = $row['event_end'] ?? ($row['arrival_local_time']['resolved_value'] ?? null);
        return $row;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        return array(
            'page' => max(1, (int)($filters['page'] ?? 1)),
            'page_size' => max(1, min(self::MAX_PAGE_SIZE, (int)($filters['page_size'] ?? self::DEFAULT_PAGE_SIZE))),
            'date_from' => $this->nullableString($filters['date_from'] ?? null),
            'date_to' => $this->nullableString($filters['date_to'] ?? null),
            'aircraft' => $this->nullableString($filters['aircraft'] ?? null),
            'crew_member' => $this->nullableString($filters['crew_member'] ?? null),
            'mission' => $this->nullableString($filters['mission'] ?? null),
            'processing_status' => $this->nullableString($filters['processing_status'] ?? null),
            'verification_status' => $this->nullableString($filters['verification_status'] ?? null),
            'finalization_status' => $this->nullableString($filters['finalization_status'] ?? null),
            'conflict_status' => $this->nullableString($filters['conflict_status'] ?? null),
            'fdm_state' => $this->nullableString($filters['fdm_state'] ?? null),
            'cvr_state' => $this->nullableString($filters['cvr_state'] ?? null),
            'adsb_state' => $this->nullableString($filters['adsb_state'] ?? null),
            'replay_state' => $this->nullableString($filters['replay_state'] ?? null),
            'source_mode' => $this->nullableString($filters['source_mode'] ?? null),
            'include_unresolved' => !empty($filters['include_unresolved']),
            'include_simulator' => !empty($filters['include_simulator']),
            'include_non_flight' => !empty($filters['include_non_flight']),
            'sort_field' => isset($filters['sort_field'], self::SORT_FIELDS[(string)$filters['sort_field']]) ? (string)$filters['sort_field'] : 'date',
            'sort_direction' => strtolower((string)($filters['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
        );
    }

    /**
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $filters
     */
    private function candidateAllowedByFilters(array $candidate, array $filters): bool
    {
        $sourceMode = (string)($candidate['source_mode'] ?? '');
        if (!$filters['include_unresolved'] && str_starts_with($sourceMode, 'unresolved')) {
            return false;
        }
        if (!$filters['include_unresolved'] && $sourceMode === 'orphan_recording') {
            return false;
        }
        if (!$filters['include_simulator'] && $sourceMode === 'simulator') {
            return false;
        }
        if (!$filters['include_non_flight'] && $sourceMode === 'non_flight') {
            return false;
        }
        if ($filters['source_mode'] !== null && $sourceMode !== $filters['source_mode']) {
            return false;
        }
        if ($filters['aircraft'] !== null && stripos((string)($candidate['aircraft']['resolved_value'] ?? $candidate['aircraft']['raw_source_value'] ?? ''), $filters['aircraft']) === false) {
            return false;
        }
        foreach (array('processing_status', 'verification_status', 'finalization_status', 'conflict_status') as $statusField) {
            if ($filters[$statusField] !== null && (string)($candidate[$statusField] ?? '') !== $filters[$statusField]) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function sortRows(array $rows, string $sortField, string $sortDirection): array
    {
        $field = self::SORT_FIELDS[$sortField] ?? 'date_sort';
        usort($rows, static function (array $a, array $b) use ($field, $sortDirection): int {
            $left = $field === 'date_sort' ? (string)($a['departure_local_time']['resolved_value'] ?? $a['date'] ?? '') : (string)($a[$field] ?? '');
            $right = $field === 'date_sort' ? (string)($b['departure_local_time']['resolved_value'] ?? $b['date'] ?? '') : (string)($b[$field] ?? '');
            $cmp = $left <=> $right;
            return $sortDirection === 'asc' ? $cmp : -$cmp;
        });
        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @return array<string,list<string>>
     */
    private function availableFilterOptions(array $candidates): array
    {
        $options = array(
            'source_mode' => array(),
            'processing_status' => array(),
            'verification_status' => array(),
            'finalization_status' => array(),
            'conflict_status' => array(),
        );
        foreach ($candidates as $candidate) {
            foreach (array_keys($options) as $field) {
                $value = (string)($candidate[$field] ?? '');
                if ($value !== '' && !in_array($value, $options[$field], true)) {
                    $options[$field][] = $value;
                }
            }
        }
        foreach ($options as $field => $values) {
            sort($values);
            $options[$field] = $values;
        }
        return $options;
    }

    /**
     * @return array<string,mixed>
     */
    public function evidenceState(string $state, int $sourceCount = 0, ?string $primarySourceKey = null, ?string $sourceStatus = null, float $confidence = 0.0, array $diagnostics = array()): array
    {
        return array(
            'state' => $this->normalizeEvidenceState($state),
            'source_count' => $sourceCount,
            'primary_source_key' => $primarySourceKey,
            'source_status' => $sourceStatus,
            'confidence' => $confidence,
            'diagnostics' => $diagnostics,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function transcriptState(string $state, bool $rawAvailable = false, bool $enhancedAvailable = false, ?string $processingStatus = null, array $diagnostics = array()): array
    {
        return array(
            'state' => $this->normalizeEvidenceState($state),
            'raw_available' => $rawAvailable,
            'enhanced_available' => $enhancedAvailable,
            'processing_status' => $processingStatus,
            'diagnostics' => $diagnostics,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function provenanceValue(mixed $raw, mixed $resolved, string $source, float $confidence, string $verificationState, array $conflicts = array()): array
    {
        return array(
            'raw_source_value' => $raw,
            'resolved_value' => $resolved,
            'resolution_source' => $source,
            'confidence' => $confidence,
            'verification_state' => $verificationState,
            'conflict_details' => $conflicts,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function emptyProvenanceValue(mixed $raw = null, string $verificationState = 'unresolved'): array
    {
        return $this->provenanceValue($raw, null, 'not_resolved', 0.0, $verificationState);
    }

    /**
     * @param array<string,mixed> $candidate
     */
    private function evidenceCompletenessStatus(array $candidate): string
    {
        $states = array();
        foreach (array('fdm', 'cvr', 'adsb', 'replay') as $key) {
            $states[] = (string)($candidate[$key]['state'] ?? 'not_available');
        }
        if (in_array('failed', $states, true) || in_array('stale', $states, true) || in_array('superseded', $states, true)) {
            return 'conflicting';
        }
        if (in_array('unresolved', $states, true) || in_array('incomplete', $states, true)) {
            return 'unresolved';
        }
        if (in_array('not_available', $states, true)) {
            return 'partial';
        }
        return 'complete';
    }

    /**
     * @param array<string,mixed> $candidate
     */
    private function candidateRank(array $candidate): int
    {
        $rank = (int)($candidate['anchor_rank'] ?? 0);
        if (($candidate['finalization_status'] ?? '') === 'finalized') {
            $rank += 1000;
        }
        if (($candidate['verification_status'] ?? '') === 'verified') {
            $rank += 100;
        }
        return $rank;
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return list<string>
     */
    private function sharedKeys(array $left, array $right, string $field): array
    {
        $leftKeys = isset($left[$field]) && is_array($left[$field]) ? array_map('strval', $left[$field]) : array();
        $rightKeys = isset($right[$field]) && is_array($right[$field]) ? array_map('strval', $right[$field]) : array();
        return array_values(array_intersect($leftKeys, $rightKeys));
    }

    /**
     * @param list<array<string,mixed>> $candidates
     */
    private function countUnresolved(array $candidates): int
    {
        $count = 0;
        foreach ($candidates as $candidate) {
            $sourceMode = (string)($candidate['source_mode'] ?? '');
            if (str_starts_with($sourceMode, 'unresolved') || $sourceMode === 'orphan_recording' || ($candidate['leg_structure_status'] ?? '') === 'unresolved') {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function rowLegDetail(array $row, array $event): array
    {
        return array(
            'leg_key' => $row['leg_key'],
            'leg_structure_type' => $row['leg_structure_type'],
            'leg_structure_status' => $row['leg_structure_status'],
            'departure_local_time' => $row['departure_local_time'],
            'arrival_local_time' => $row['arrival_local_time'],
            'departure_airport' => $row['departure_airport'],
            'arrival_airport' => $row['arrival_airport'],
            'meter_values' => array(
                'hobbs_out' => $row['departure_hobbs'],
                'hobbs_in' => $row['arrival_hobbs'],
                'tacho_out' => $row['departure_tacho'],
                'tacho_in' => $row['arrival_tacho'],
            ),
            'fuel' => array('out' => $row['fuel_out'], 'in' => $row['fuel_in']),
            'landings' => $row['landings'],
            'source_record_keys' => $event['source_record_keys'] ?? array(),
        );
    }

    private function normalizeEvidenceState(string $state): string
    {
        $allowed = array('usable', 'present', 'processing', 'failed', 'stale', 'superseded', 'incomplete', 'not_available', 'unresolved');
        return in_array($state, $allowed, true) ? $state : 'unresolved';
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $overlay
     * @return array<string,mixed>
     */
    private function overlayArrays(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (isset($base[$key]) && is_array($base[$key]) && is_array($value)) {
                $base[$key] = $this->overlayArrays($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private function normalizeProcessingStatus(string $status): string
    {
        $status = strtolower($status);
        if (in_array($status, array('ready', 'complete', 'completed', 'uploaded'), true)) {
            return 'ready';
        }
        if (in_array($status, array('failed', 'error'), true)) {
            return 'failed';
        }
        if (in_array($status, array('processing', 'running', 'transcribing', 'queued'), true)) {
            return 'processing';
        }
        return 'pending';
    }

    private function normalizeVerificationStatus(string $status): string
    {
        $status = strtolower($status);
        if (in_array($status, array('verified', 'approved', 'accepted'), true)) {
            return 'verified';
        }
        if (in_array($status, array('rejected', 'voided'), true)) {
            return 'rejected';
        }
        if (in_array($status, array('needs_review', 'pending', 'proposed'), true)) {
            return 'needs_review';
        }
        return 'unreviewed';
    }

    private function normalizeFinalizationStatus(string $status): string
    {
        $status = strtolower($status);
        if (in_array($status, array('finalized', 'accepted'), true)) {
            return 'finalized';
        }
        if ($status === 'amended') {
            return 'amended';
        }
        if (in_array($status, array('voided', 'rejected'), true)) {
            return 'voided';
        }
        if ($status === 'proposed') {
            return 'proposed';
        }
        return 'draft';
    }

    private function normalizeConflictStatus(string $status): string
    {
        $status = strtolower($status);
        if (in_array($status, array('blocking_conflict', 'blocking'), true)) {
            return 'blocking_conflict';
        }
        if (in_array($status, array('warning', 'conflict'), true)) {
            return 'warning';
        }
        return 'none';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function datePart(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        return substr($value, 0, 10);
    }

    /**
     * @return array<string,mixed>
     */
    private function durationHours(mixed $milliseconds): array
    {
        if (!is_numeric($milliseconds)) {
            return $this->emptyProvenanceValue(null, 'unresolved');
        }
        $hours = round(((float)$milliseconds) / 3600000.0, 3);
        return $this->provenanceValue($milliseconds, $hours, 'duration_ms', 0.9, 'system');
    }

    private function tableExists(string $table): bool
    {
        if (!$this->pdo instanceof PDO || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return false;
        }
        if (array_key_exists($table, $this->tableExistsCache)) {
            return $this->tableExistsCache[$table];
        }
        $started = microtime(true);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute(array($table));
        $exists = (int)$stmt->fetchColumn() === 1;
        $this->lastSchemaMs += (microtime(true) - $started) * 1000.0;
        $this->tableExistsCache[$table] = $exists;
        return $exists;
    }

    /**
     * @param list<mixed> $params
     * @return array{rows:list<array<string,mixed>>,ms:float}
     */
    private function timedQuery(string $sql, array $params): array
    {
        if (!$this->pdo instanceof PDO) {
            return array('rows' => array(), 'ms' => 0.0);
        }
        $started = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array(
            'rows' => is_array($rows) ? $rows : array(),
            'ms' => round((microtime(true) - $started) * 1000.0, 3),
        );
    }
}
