<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/GarminCsvFlightSummaryService.php';
require_once __DIR__ . '/../../src/GarminTrackFlightSummaryService.php';
require_once __DIR__ . '/../../src/GarminProcessingStatusService.php';
require_once __DIR__ . '/../../src/GarminCsvReplayPayloadService.php';
require_once __DIR__ . '/../../src/GarminHistoricalBackfillService.php';
require_once __DIR__ . '/../../src/FlightCircleHistoricalImportService.php';

cw_require_admin();

function garmin_sync_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute(array($table));
    return (int)$stmt->fetchColumn() > 0;
}

function garmin_sync_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute(array($table, $column));
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * @return array<int,array<string,mixed>>
 */
function garmin_sync_rows(PDO $pdo, string $sql, array $params = array()): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : array();
}

/**
 * @return array<string,mixed>|null
 */
function garmin_sync_row(PDO $pdo, string $sql, array $params = array()): ?array
{
    $rows = garmin_sync_rows($pdo, $sql, $params);
    return $rows[0] ?? null;
}

function garmin_sync_badge_class(string $value): string
{
    $value = strtolower(trim($value));
    if (in_array($value, array('accepted', 'uploaded', 'already_exists', 'active', 'online', 'full', 'garmin_avionics_full_or_partial'), true)) {
        return 'garmin-badge-ok';
    }
    if (in_array($value, array('review_required', 'unknown', 'garmin_unknown_track', 'queued', 'partial'), true)) {
        return 'garmin-badge-warn';
    }
    if (in_array($value, array('failed', 'rejected', 'revoked', 'inactive', 'garmin_gps_only'), true)) {
        return 'garmin-badge-danger';
    }
    return '';
}

function garmin_sync_bytes(int|float|string|null $bytes): string
{
    $value = (float)($bytes ?? 0);
    if ($value >= 1024 * 1024 * 1024) {
        return number_format($value / (1024 * 1024 * 1024), 2) . ' GB';
    }
    if ($value >= 1024 * 1024) {
        return number_format($value / (1024 * 1024), 1) . ' MB';
    }
    if ($value >= 1024) {
        return number_format($value / 1024, 1) . ' KB';
    }
    return number_format($value) . ' B';
}

function garmin_sync_datetime(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return 'Not yet';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }
    return date('M j, Y H:i', $timestamp);
}

function garmin_sync_metadata_value(?string $json, string $key): string
{
    if ($json === null || trim($json) === '') {
        return '';
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || !array_key_exists($key, $decoded)) {
        return '';
    }
    $value = $decoded[$key];
    if (is_array($value)) {
        return implode(', ', array_slice(array_map('strval', $value), 0, 4));
    }
    return (string)$value;
}

function garmin_sync_flight_id(int $id): string
{
    return 'A-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

function garmin_sync_date_label(string $value): string
{
    $timestamp = strtotime($value);
    return $timestamp !== false ? date('D, M j Y', $timestamp) : '--';
}

function garmin_sync_modal_date_label(string $value): string
{
    $timestamp = strtotime($value);
    return $timestamp !== false ? date('D M j, Y', $timestamp) : '--';
}

function garmin_sync_airport_label(string $code): string
{
    $code = strtoupper(trim($code));
    $labels = array(
        'KTRM' => 'Thermal, CA',
    );
    if ($code === '' || $code === '--') {
        return '--';
    }
    return isset($labels[$code]) ? $code . ' (' . $labels[$code] . ')' : $code;
}

/**
 * @return list<array{label:string,class:string}>
 */
function garmin_sync_classification_pills(string $classification): array
{
    $classification = strtoupper(trim($classification));
    if ($classification === '') {
        return array(array('label' => 'Unknown', 'class' => 'warn'));
    }
    $pills = array();
    if (str_contains($classification, 'GARMIN')) {
        $pills[] = array('label' => 'Garmin Avionics', 'class' => 'ok');
    }
    if (str_contains($classification, 'FULL')) {
        $pills[] = array('label' => 'Full', 'class' => 'ok');
    } elseif (str_contains($classification, 'PARTIAL')) {
        $pills[] = array('label' => 'Partial', 'class' => 'warn');
    }
    if (str_contains($classification, 'GPS_ONLY')) {
        $pills[] = array('label' => 'GPS Only', 'class' => 'danger');
    }
    if ($pills === array()) {
        $pills[] = array('label' => ucwords(strtolower(str_replace('_', ' ', $classification))), 'class' => 'warn');
    }
    return $pills;
}

function garmin_sync_quality_score(array $summary, string $statusLabel): int
{
    $score = 15;
    if ($statusLabel === 'Complete') {
        $score += 25;
    }
    if (!empty($summary['provides_full_avionics'])) {
        $score += 20;
    }
    if (!empty($summary['provides_counter_headers'])) {
        $score += 20;
    }
    if ((string)($summary['hobbs_out'] ?? '--') !== '--' && (string)($summary['tacho_out'] ?? '--') !== '--') {
        $score += 20;
    }
    return max(0, min(100, $score));
}

function garmin_sync_status_label(array $summary, string $classification): string
{
    $classification = strtoupper(trim($classification));
    if ($classification === 'GARMIN_GPS_ONLY') {
        return 'GPS only';
    }
    if ($classification === 'GARMIN_UNKNOWN_TRACK' || $classification === '') {
        return 'Needs review';
    }
    $hasRoute = (string)($summary['dep_airport'] ?? '--') !== '--' && (string)($summary['arr_airport'] ?? '--') !== '--';
    $hasTime = (string)($summary['dep_time_lt'] ?? '--') !== '--' && (string)($summary['arr_time_lt'] ?? '--') !== '--';
    $hasCounters = (string)($summary['hobbs_out'] ?? '--') !== '--'
        && (string)($summary['hobbs_in'] ?? '--') !== '--'
        && (string)($summary['tacho_out'] ?? '--') !== '--'
        && (string)($summary['tacho_in'] ?? '--') !== '--';
    return $hasRoute && $hasTime && $hasCounters ? 'Complete' : 'Partial';
}

function garmin_sync_number_or_null(mixed $value): ?float
{
    $clean = preg_replace('/[^0-9.\-]+/', '', trim((string)$value));
    if ($clean === '' || !is_numeric($clean)) {
        return null;
    }
    return (float)$clean;
}

function garmin_sync_is_zero_hobbs_avionics_row(array $summary): bool
{
    $hobbsOut = garmin_sync_number_or_null($summary['hobbs_out'] ?? null);
    $hobbsIn = garmin_sync_number_or_null($summary['hobbs_in'] ?? null);
    if ($hobbsOut === null || $hobbsIn === null || abs($hobbsIn - $hobbsOut) > 0.01) {
        return false;
    }
    $hobbsTime = garmin_sync_number_or_null($summary['hobbs_time'] ?? null);
    $hobbsHours = garmin_sync_number_or_null($summary['hobbs_hours'] ?? null);
    return ($hobbsTime !== null && $hobbsTime <= 0.01)
        || ($hobbsHours !== null && $hobbsHours <= 0.01);
}

function garmin_sync_is_new(mixed $uploadedAt): bool
{
    $timestamp = strtotime(trim((string)$uploadedAt));
    return $timestamp !== false && $timestamp >= (time() - 21600);
}

function garmin_sync_duration_label(array $summary): string
{
    $hoursText = trim((string)($summary['hobbs_hours'] ?? $summary['elapsed_time'] ?? ''));
    if ($hoursText === '' || $hoursText === '--') {
        return '--';
    }
    $hours = (float)preg_replace('/[^0-9.]+/', '', $hoursText);
    if ($hours <= 0) {
        return '--';
    }
    $minutes = (int)round($hours * 60);
    return number_format($hours, 1) . ' (' . sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60) . ')';
}

function garmin_sync_tail_pill(string $tail): string
{
    $tail = trim($tail);
    if ($tail === '' || stripos($tail, 'unknown') !== false) {
        return '<span class="garmin-tail-pill garmin-tail-unknown">Unknown</span>';
    }
    $hue = abs(crc32($tail)) % 360;
    return '<span class="garmin-tail-pill" style="background:hsl(' . $hue . ' 76% 92%);color:hsl(' . $hue . ' 72% 24%);border-color:hsl(' . $hue . ' 58% 72%)">' . h($tail) . '</span>';
}

$hasTokens = garmin_sync_table_exists($pdo, 'ipca_sync_agent_tokens');
$hasAcks = garmin_sync_table_exists($pdo, 'ipca_sync_agent_upload_acknowledgments');
$hasTracks = garmin_sync_table_exists($pdo, 'ipca_garmin_normalized_track_artifacts');
$hasSources = garmin_sync_table_exists($pdo, 'ipca_garmin_flight_data_sources');
$hasCsvFiles = garmin_sync_table_exists($pdo, 'ipca_garmin_csv_files');
$hasEntries = garmin_sync_table_exists($pdo, 'ipca_garmin_logbook_entries');
$hasSourceGroups = garmin_sync_table_exists($pdo, 'ipca_garmin_source_groups');
$hasCsvSummaries = garmin_sync_table_exists($pdo, 'ipca_garmin_csv_flight_summaries');
$hasTrackSummaries = garmin_sync_table_exists($pdo, 'ipca_garmin_track_flight_summaries');
$hasFlightStates = garmin_sync_table_exists($pdo, 'ipca_garmin_flight_artifact_states');
$hasTrackCsvLinks = garmin_sync_table_exists($pdo, 'ipca_garmin_flight_data_track_links');
$hasHistoricalBackfill = garmin_sync_table_exists($pdo, 'ipca_garmin_historical_backfill_batches');
$hasFlightCircleMigration = garmin_sync_table_exists($pdo, 'ipca_flightcircle_import_batches');
$hasFlightCircleMatches = garmin_sync_table_exists($pdo, 'ipca_flightcircle_garmin_matches');
$hasFlightCircleStaging = garmin_sync_table_exists($pdo, 'ipca_flightcircle_staging_records');
$hasFlightCircleActiveDataset = $hasFlightCircleMigration && garmin_sync_column_exists($pdo, 'ipca_flightcircle_import_batches', 'active_dataset');
$summaryService = new GarminCsvFlightSummaryService($pdo);
$trackSummaryService = new GarminTrackFlightSummaryService($pdo);
$replayPayloadService = new GarminCsvReplayPayloadService($pdo);
$historicalBackfillService = new GarminHistoricalBackfillService($pdo);
$flightCircleImportService = new FlightCircleHistoricalImportService($pdo);
$processingStatusError = '';
try {
    $processingStatus = (new GarminProcessingStatusService($pdo))->status();
} catch (Throwable $e) {
    $processingStatusError = $e->getMessage();
    $processingStatus = array(
        'state' => 'failed',
        'message' => 'Could not load Garmin processing status: ' . $processingStatusError,
        'csv' => array('done' => 0, 'total' => 0, 'remaining' => 0),
        'tracks' => array('done' => 0, 'total' => 0, 'remaining' => 0),
        'linked_csv_tracks' => array('done' => 0, 'total' => 0, 'remaining' => 0),
        'jobs' => array('queued' => 0, 'running' => 0, 'failed' => 0),
        'needs_review' => array('total' => 0, 'sample' => array()),
        'total' => 0,
        'done' => 0,
        'remaining' => 0,
        'percent' => 0,
        'updated_at' => gmdate('c'),
    );
}
$historicalBackfillStatus = array('ready' => false, 'batches' => array(), 'file_statuses' => array(), 'segment_classifications' => array(), 'review_statuses' => array());
$historicalBackfillFiles = array();
try {
    $historicalBackfillStatus = $historicalBackfillService->status(5);
    $historicalBackfillFiles = $historicalBackfillService->recentFiles(12);
} catch (Throwable $e) {
    $historicalBackfillStatus = array('ready' => false, 'message' => $e->getMessage(), 'batches' => array(), 'file_statuses' => array(), 'segment_classifications' => array(), 'review_statuses' => array());
}
$flightCircleStatus = array('ready' => false, 'batches' => array(), 'identity_mappings' => array(), 'resources' => array(), 'dispositions' => array());
try {
    $flightCircleStatus = $flightCircleImportService->status(5);
} catch (Throwable $e) {
    $flightCircleStatus = array('ready' => false, 'message' => $e->getMessage(), 'batches' => array(), 'identity_mappings' => array(), 'resources' => array(), 'dispositions' => array());
}

$tokens = $hasTokens ? garmin_sync_rows($pdo, "
    SELECT id, token_uuid, display_name, is_active, last_seen_at, revoked_at, created_at
    FROM ipca_sync_agent_tokens
    ORDER BY COALESCE(last_seen_at, created_at) DESC
    LIMIT 10
") : array();

$latestToken = $tokens[0] ?? null;

$ackSummary = $hasAcks ? garmin_sync_rows($pdo, "
    SELECT status, COUNT(*) AS total, MAX(created_at) AS last_seen_at
    FROM ipca_sync_agent_upload_acknowledgments
    GROUP BY status
    ORDER BY total DESC, status ASC
") : array();

$trackSummary = $hasTracks ? garmin_sync_row($pdo, "
    SELECT
      COUNT(*) AS total_tracks,
      COALESCE(SUM(file_size_bytes), 0) AS total_bytes,
      COALESCE(SUM(session_count), 0) AS total_sessions,
      COALESCE(SUM(field_count), 0) AS total_fields,
      MAX(last_seen_at) AS last_track_at
    FROM ipca_garmin_normalized_track_artifacts
") : array('total_tracks' => 0, 'total_bytes' => 0, 'total_sessions' => 0, 'total_fields' => 0, 'last_track_at' => null);

$currentTachoVersionSql = $pdo->quote(TachoCalculationService::VERSION);
if (!is_string($currentTachoVersionSql)) {
    $currentTachoVersionSql = "'" . str_replace("'", "''", TachoCalculationService::VERSION) . "'";
}

$classificationSummary = $hasTracks ? garmin_sync_rows($pdo, "
    SELECT
      COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw_metadata_json, '$.trackClassification')), 'UNKNOWN') AS classification,
      COUNT(*) AS total
    FROM ipca_garmin_normalized_track_artifacts
    GROUP BY classification
    ORDER BY total DESC, classification ASC
") : array();

$csvSummaryStats = $hasCsvFiles ? garmin_sync_row($pdo, "
    SELECT
      COUNT(f.id) AS total_csv_files,
      SUM(CASE
        WHEN s.csv_file_id IS NULL THEN 0
        WHEN JSON_EXTRACT(s.summary_json, '$.hobbs_exact') IS NULL THEN 0
        WHEN JSON_EXTRACT(s.summary_json, '$.tacho_exact') IS NULL THEN 0
        WHEN JSON_EXTRACT(s.summary_json, '$.hobbs_in') IS NULL THEN 0
        WHEN JSON_EXTRACT(s.summary_json, '$.tacho_in') IS NULL THEN 0
        WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.hobbs_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 0
        WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.tacho_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 0
        WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.tacho_calculation_version')), '') <> {$currentTachoVersionSql} THEN 0
        ELSE 1
      END) AS summarized_csv_files,
      SUM(CASE
        WHEN s.csv_file_id IS NULL THEN 1
        WHEN JSON_EXTRACT(s.summary_json, '$.hobbs_exact') IS NULL THEN 1
        WHEN JSON_EXTRACT(s.summary_json, '$.tacho_exact') IS NULL THEN 1
        WHEN JSON_EXTRACT(s.summary_json, '$.hobbs_in') IS NULL THEN 1
        WHEN JSON_EXTRACT(s.summary_json, '$.tacho_in') IS NULL THEN 1
        WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.hobbs_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 1
        WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.tacho_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 1
        WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.tacho_calculation_version')), '') <> {$currentTachoVersionSql} THEN 1
        ELSE 0
      END) AS missing_summaries
    FROM ipca_garmin_csv_files f
    " . ($hasCsvSummaries ? "LEFT JOIN ipca_garmin_csv_flight_summaries s ON s.csv_file_id = f.id" : "LEFT JOIN (SELECT NULL AS csv_file_id) s ON 1 = 0") . "
") : array('total_csv_files' => 0, 'summarized_csv_files' => 0, 'missing_summaries' => 0);

$garminImportRows = array();
$garminImportTailOptions = array();
$garminImportSourceOptions = array();
$garminImportHiddenAvionicsOnlyCount = 0;
if ($hasCsvFiles) {
    $historicalJoin = $hasHistoricalBackfill ? "
        LEFT JOIN ipca_garmin_historical_backfill_files hf ON hf.csv_file_id = f.id
        LEFT JOIN ipca_garmin_historical_backfill_batches hb ON hb.id = hf.batch_id
    " : "
        LEFT JOIN (SELECT NULL AS csv_file_id, NULL AS id, NULL AS batch_id, NULL AS exact_duplicate_status, NULL AS parse_status, NULL AS classification, NULL AS review_status) hf ON 1 = 0
        LEFT JOIN (SELECT NULL AS id, NULL AS batch_uuid) hb ON 1 = 0
    ";
    $flightCircleActiveFilter = $hasFlightCircleActiveDataset ? " AND (m.staging_record_id = 0 OR COALESCE(b.active_dataset, 0) = 1)" : "";
    $flightCircleBatchJoin = $hasFlightCircleActiveDataset ? "LEFT JOIN ipca_flightcircle_import_batches b ON b.id = st.batch_id" : "";
    $flightCircleJoin = ($hasFlightCircleMatches && $hasFlightCircleStaging) ? "
        LEFT JOIN (
          SELECT
            m.csv_file_id,
            COUNT(*) AS fc_match_count,
            MAX(m.confidence_score) AS fc_match_score,
            GROUP_CONCAT(DISTINCT m.match_status ORDER BY m.match_status SEPARATOR ', ') AS fc_match_statuses,
            GROUP_CONCAT(DISTINCT NULLIF(st.id, 0) ORDER BY st.id SEPARATOR ', ') AS fc_staging_ids,
            GROUP_CONCAT(DISTINCT NULLIF(st.user_text, '') ORDER BY st.user_text SEPARATOR ' | ') AS fc_user_text,
            GROUP_CONCAT(DISTINCT NULLIF(st.instructor_text, '') ORDER BY st.instructor_text SEPARATOR ' | ') AS fc_instructor_text,
            GROUP_CONCAT(DISTINCT NULLIF(st.reservation_type, '') ORDER BY st.reservation_type SEPARATOR ', ') AS fc_reservation_type,
            GROUP_CONCAT(DISTINCT CAST(st.hobbs_out AS CHAR) ORDER BY st.hobbs_out SEPARATOR ', ') AS fc_hobbs_out,
            GROUP_CONCAT(DISTINCT CAST(st.hobbs_in AS CHAR) ORDER BY st.hobbs_in SEPARATOR ', ') AS fc_hobbs_in
          FROM ipca_flightcircle_garmin_matches m
          LEFT JOIN ipca_flightcircle_staging_records st ON st.id = m.staging_record_id AND m.staging_record_id > 0
          {$flightCircleBatchJoin}
          WHERE m.csv_file_id IS NOT NULL
            {$flightCircleActiveFilter}
          GROUP BY m.csv_file_id
        ) fc ON fc.csv_file_id = f.id
    " : "
        LEFT JOIN (
          SELECT NULL AS csv_file_id, NULL AS fc_match_count, NULL AS fc_match_score, NULL AS fc_match_statuses,
                 NULL AS fc_staging_ids, NULL AS fc_user_text, NULL AS fc_instructor_text, NULL AS fc_reservation_type,
                 NULL AS fc_hobbs_out, NULL AS fc_hobbs_in
        ) fc ON 1 = 0
    ";
    $garminImportRows = garmin_sync_rows($pdo, "
        SELECT
          f.id AS csv_file_id,
          f.provider_name,
          f.upload_source,
          f.source,
          f.original_filename,
          f.aircraft_registration,
          f.aircraft_ident,
          f.sha256,
          f.created_at,
          f.first_valid_sample_utc,
          f.last_valid_sample_utc,
          s.derivation_status,
          s.tail_number AS summary_tail_number,
          s.departure_airport_code,
          s.arrival_airport_code,
          s.departure_time_utc,
          s.arrival_time_utc,
          s.elapsed_seconds,
          s.summary_json,
          hf.id AS historical_file_id,
          hf.batch_id AS historical_batch_id,
          hb.batch_uuid AS historical_batch_uuid,
          hf.exact_duplicate_status,
          hf.parse_status AS historical_parse_status,
          hf.classification AS historical_classification,
          hf.review_status AS historical_review_status,
          fc.fc_match_count,
          fc.fc_match_score,
          fc.fc_match_statuses,
          fc.fc_staging_ids,
          fc.fc_user_text,
          fc.fc_instructor_text,
          fc.fc_reservation_type,
          fc.fc_hobbs_out,
          fc.fc_hobbs_in
        FROM ipca_garmin_csv_files f
        " . ($hasCsvSummaries ? "LEFT JOIN ipca_garmin_csv_flight_summaries s ON s.csv_file_id = f.id" : "LEFT JOIN (SELECT NULL AS csv_file_id, NULL AS derivation_status, NULL AS tail_number, NULL AS departure_airport_code, NULL AS arrival_airport_code, NULL AS departure_time_utc, NULL AS arrival_time_utc, NULL AS elapsed_seconds, NULL AS summary_json) s ON 1 = 0") . "
        {$historicalJoin}
        {$flightCircleJoin}
        WHERE COALESCE(f.provider_name, '') IN ('desktop_sync_agent', 'historical_sd_card_csv')
           OR COALESCE(f.upload_source, '') IN ('desktop_sync_agent', 'admin_historical_backfill')
           OR COALESCE(f.source, '') IN ('garmin_historical_sd_card', 'garmin_cloud')
        ORDER BY COALESCE(s.departure_time_utc, f.first_valid_sample_utc, f.created_at) DESC, f.id DESC
        LIMIT 1500
    ");
    $visibleGarminImportRows = array();
    foreach ($garminImportRows as $row) {
        $summary = json_decode((string)($row['summary_json'] ?? '{}'), true);
        $summary = is_array($summary) ? $summary : array();
        if (garmin_sync_is_zero_hobbs_avionics_row($summary)) {
            $garminImportHiddenAvionicsOnlyCount++;
            continue;
        }
        $visibleGarminImportRows[] = $row;
        $tail = strtoupper(trim((string)($row['summary_tail_number'] ?? '')));
        if ($tail === '' || $tail === 'UNKNOWN' || $tail === 'UNKNOWN TAIL') {
            $tail = strtoupper(trim((string)($row['aircraft_registration'] ?: $row['aircraft_ident'])));
        }
        if ($tail !== '' && $tail !== 'UNKNOWN' && $tail !== 'UNKNOWN TAIL') {
            $garminImportTailOptions[$tail] = $tail;
        }
        $sourceLabel = (string)($row['provider_name'] ?? '') === 'historical_sd_card_csv' || (string)($row['upload_source'] ?? '') === 'admin_historical_backfill'
            ? 'Bulk Upload'
            : 'IPCA Sync App';
        $garminImportSourceOptions[$sourceLabel] = $sourceLabel;
    }
    $garminImportRows = $visibleGarminImportRows;
    sort($garminImportTailOptions);
    sort($garminImportSourceOptions);
}

$csvTrackJoin = ($hasTrackCsvLinks && $hasCsvSummaries) ? "
    LEFT JOIN (
      SELECT provider_name, garmin_entry_uuid, canonical_track_uuid, MAX(garmin_csv_file_id) AS garmin_csv_file_id
      FROM ipca_garmin_flight_data_track_links
      GROUP BY provider_name, garmin_entry_uuid, canonical_track_uuid
    ) track_csv_l
      ON track_csv_l.provider_name = t.provider_name
     AND track_csv_l.garmin_entry_uuid = t.garmin_entry_uuid
     AND track_csv_l.canonical_track_uuid = t.track_uuid
    LEFT JOIN ipca_garmin_csv_flight_summaries csv_s
      ON csv_s.csv_file_id = track_csv_l.garmin_csv_file_id
" : "";
$csvTrackCompleteCase = ($hasTrackCsvLinks && $hasCsvSummaries) ? "
        WHEN csv_s.csv_file_id IS NOT NULL
          AND JSON_EXTRACT(csv_s.summary_json, '$.hobbs_exact') IS NOT NULL
          AND JSON_EXTRACT(csv_s.summary_json, '$.tacho_exact') IS NOT NULL
          AND JSON_EXTRACT(csv_s.summary_json, '$.hobbs_in') IS NOT NULL
          AND JSON_EXTRACT(csv_s.summary_json, '$.tacho_in') IS NOT NULL
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(csv_s.summary_json, '$.hobbs_exact.counter_start_exact')) AS DECIMAL(12,4)) >= 0
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(csv_s.summary_json, '$.tacho_exact.counter_start_exact')) AS DECIMAL(12,4)) >= 0
          AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(csv_s.summary_json, '$.tacho_calculation_version')), '') = {$currentTachoVersionSql} THEN 1
" : "";
$csvTrackMissingCase = ($hasTrackCsvLinks && $hasCsvSummaries) ? "
        WHEN csv_s.csv_file_id IS NOT NULL
          AND JSON_EXTRACT(csv_s.summary_json, '$.hobbs_exact') IS NOT NULL
          AND JSON_EXTRACT(csv_s.summary_json, '$.tacho_exact') IS NOT NULL
          AND JSON_EXTRACT(csv_s.summary_json, '$.hobbs_in') IS NOT NULL
          AND JSON_EXTRACT(csv_s.summary_json, '$.tacho_in') IS NOT NULL
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(csv_s.summary_json, '$.hobbs_exact.counter_start_exact')) AS DECIMAL(12,4)) >= 0
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(csv_s.summary_json, '$.tacho_exact.counter_start_exact')) AS DECIMAL(12,4)) >= 0
          AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(csv_s.summary_json, '$.tacho_calculation_version')), '') = {$currentTachoVersionSql} THEN 0
" : "";
$trackSummaryStats = $hasTracks ? garmin_sync_row($pdo, "
    SELECT
      COUNT(t.id) AS total_track_artifacts,
      SUM(CASE
        {$csvTrackCompleteCase}
        WHEN s.track_artifact_id IS NULL THEN 0
        WHEN JSON_EXTRACT(s.summary_json, '$.hobbs_exact') IS NULL THEN 0
        WHEN JSON_EXTRACT(s.summary_json, '$.tacho_exact') IS NULL THEN 0
        WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.hobbs_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 0
        WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.tacho_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 0
        WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.tacho_calculation_version')), '') <> {$currentTachoVersionSql} THEN 0
        WHEN s.tail_number IN ('', 'Unknown tail', 'Unknown')
          AND JSON_SEARCH(t.source_descriptors_json, 'one', 'Flight Data Log System ID:%') IS NOT NULL THEN 0
        ELSE 1
      END) AS summarized_track_artifacts,
      SUM(CASE
        {$csvTrackMissingCase}
        WHEN s.track_artifact_id IS NULL THEN 1
        WHEN JSON_EXTRACT(s.summary_json, '$.hobbs_exact') IS NULL THEN 1
        WHEN JSON_EXTRACT(s.summary_json, '$.tacho_exact') IS NULL THEN 1
        WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.hobbs_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 1
        WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.tacho_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 1
        WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.tacho_calculation_version')), '') <> {$currentTachoVersionSql} THEN 1
        WHEN s.tail_number IN ('', 'Unknown tail', 'Unknown')
          AND JSON_SEARCH(t.source_descriptors_json, 'one', 'Flight Data Log System ID:%') IS NOT NULL THEN 1
        ELSE 0
      END) AS missing_track_summaries
    FROM ipca_garmin_normalized_track_artifacts t
    " . ($hasTrackSummaries ? "LEFT JOIN ipca_garmin_track_flight_summaries s ON s.track_artifact_id = t.id" : "LEFT JOIN (SELECT NULL AS track_artifact_id) s ON 1 = 0") . "
    {$csvTrackJoin}
    WHERE t.artifact_type = 'GARMIN_TRACK_NORMALIZED_JSON'
") : array('total_track_artifacts' => 0, 'summarized_track_artifacts' => 0, 'missing_track_summaries' => 0);

$summaryOptionRows = array();
if ($hasTrackSummaries) {
    $summaryOptionRows = array_merge($summaryOptionRows, garmin_sync_rows($pdo, "
        SELECT tail_number, departure_airport_code, arrival_airport_code
        FROM ipca_garmin_track_flight_summaries
        WHERE derivation_status = 'ok'
    "));
}
if ($hasCsvSummaries) {
    $summaryOptionRows = array_merge($summaryOptionRows, garmin_sync_rows($pdo, "
        SELECT tail_number, departure_airport_code, arrival_airport_code
        FROM ipca_garmin_csv_flight_summaries
        WHERE derivation_status = 'ok'
    "));
}

$recentTracks = array();
if ($hasTracks) {
    $ackSelect = $hasAcks ? 'a.status AS upload_status, a.created_at AS uploaded_at' : 'NULL AS upload_status, NULL AS uploaded_at';
    $ackJoin = $hasAcks ? "
        LEFT JOIN ipca_sync_agent_upload_acknowledgments a
          ON a.provider_name = t.provider_name
         AND a.garmin_entry_uuid = t.garmin_entry_uuid
         AND a.flight_data_log_uuid = t.track_uuid
         AND a.sha256 = t.sha256
    " : '';
    $tokenSelect = ($hasAcks && $hasTokens) ? 'tok.display_name AS device_name' : 'NULL AS device_name';
    $tokenJoin = ($hasAcks && $hasTokens) ? 'LEFT JOIN ipca_sync_agent_tokens tok ON tok.id = a.token_id' : '';
    $entrySelect = $hasEntries ? "
          e.aircraft_registration AS entry_aircraft_registration,
          e.generated_track_start_utc AS entry_generated_track_start_utc,
          e.generated_track_stop_utc AS entry_generated_track_stop_utc
    " : "
          NULL AS entry_aircraft_registration,
          NULL AS entry_generated_track_start_utc,
          NULL AS entry_generated_track_stop_utc
    ";
    $entryJoin = $hasEntries ? "
        LEFT JOIN ipca_garmin_logbook_entries e
          ON e.provider_name = t.provider_name
         AND e.garmin_entry_uuid = t.garmin_entry_uuid
    " : '';
    $hasCsvJoinPath = $hasCsvFiles && ($hasTrackCsvLinks || $hasSources);
    $csvSelect = $hasCsvJoinPath ? "
          f.id AS csv_file_id, f.csv_file_uuid, f.aircraft_registration AS csv_aircraft_registration,
          f.original_filename AS csv_original_filename, f.storage_path AS csv_storage_path,
          f.import_profile AS csv_import_profile, f.aircraft_ident AS csv_aircraft_ident,
          f.system_identifier AS csv_system_identifier,
          f.airframe_hours_start AS csv_airframe_hours_start,
          f.engine_hours_start AS csv_engine_hours_start,
          f.first_valid_sample_utc AS csv_first_valid_sample_utc,
          f.last_valid_sample_utc AS csv_last_valid_sample_utc,
          f.valid_row_count AS csv_valid_row_count
    " : "
          NULL AS csv_file_id, NULL AS csv_file_uuid, NULL AS csv_aircraft_registration,
          NULL AS csv_original_filename, NULL AS csv_storage_path, NULL AS csv_import_profile,
          NULL AS csv_aircraft_ident, NULL AS csv_system_identifier,
          NULL AS csv_airframe_hours_start, NULL AS csv_engine_hours_start,
          NULL AS csv_first_valid_sample_utc,
          NULL AS csv_last_valid_sample_utc, NULL AS csv_valid_row_count
    ";
    $csvIdParts = array();
    if ($hasTrackCsvLinks) {
        $csvIdParts[] = 'track_csv_l.garmin_csv_file_id';
    }
    if ($hasAcks) {
        $csvIdParts[] = 'a.garmin_csv_file_id';
    }
    if ($hasSources) {
        $csvIdParts[] = 'direct_s.garmin_csv_file_id';
    }
    if ($hasEntries && $hasSourceGroups) {
        $csvIdParts[] = 'group_s.garmin_csv_file_id';
    }
    $csvIdExpression = count($csvIdParts) > 1 ? 'COALESCE(' . implode(', ', $csvIdParts) . ')' : $csvIdParts[0];
    $stateSelect = $hasFlightStates ? 'st.hidden_at AS hidden_at, st.hidden_reason AS hidden_reason' : 'NULL AS hidden_at, NULL AS hidden_reason';
    $stateJoin = $hasFlightStates ? 'LEFT JOIN ipca_garmin_flight_artifact_states st ON st.track_artifact_id = t.id' : '';
    $sourceJoin = $hasCsvJoinPath ? "
        " . ($hasTrackCsvLinks ? "LEFT JOIN (
          SELECT provider_name, garmin_entry_uuid, canonical_track_uuid, MAX(garmin_csv_file_id) AS garmin_csv_file_id
          FROM ipca_garmin_flight_data_track_links
          GROUP BY provider_name, garmin_entry_uuid, canonical_track_uuid
        ) track_csv_l
          ON track_csv_l.provider_name = t.provider_name
         AND track_csv_l.garmin_entry_uuid = t.garmin_entry_uuid
         AND track_csv_l.canonical_track_uuid = t.track_uuid" : "") . "
        " . ($hasEntries && $hasSourceGroups ? "LEFT JOIN ipca_garmin_source_groups g
          ON g.garmin_logbook_entry_id = e.id" : "") . "
        " . ($hasSources ? "LEFT JOIN ipca_garmin_flight_data_sources direct_s
          ON direct_s.provider_name = t.provider_name
         AND direct_s.flight_data_log_uuid = t.track_uuid" : "") . "
        " . ($hasEntries && $hasSourceGroups ? "LEFT JOIN ipca_garmin_flight_data_sources group_s
          ON group_s.id = COALESCE(g.primary_operational_source_id, g.primary_replay_source_id)" : "") . "
        LEFT JOIN ipca_garmin_csv_files f
          ON f.id = {$csvIdExpression}
    " : '';
    $recentTracks = garmin_sync_rows($pdo, "
        SELECT
          t.id, t.garmin_entry_uuid, t.track_uuid, t.sha256, t.file_size_bytes, t.session_count, t.field_count,
          t.raw_metadata_json, t.source_descriptors_json, t.first_seen_at, t.last_seen_at,
          {$ackSelect}, {$tokenSelect},
          {$entrySelect},
          {$csvSelect},
          {$stateSelect}
        FROM ipca_garmin_normalized_track_artifacts t
        {$ackJoin}
        {$tokenJoin}
        {$entryJoin}
        {$sourceJoin}
        {$stateJoin}
        ORDER BY t.last_seen_at DESC, t.id DESC
    ");
}

$recentAcknowledgments = array();
if ($hasAcks && $hasTokens) {
    $recentAcknowledgments = garmin_sync_rows($pdo, "
        SELECT
          a.id, a.provider_name, a.garmin_entry_uuid, a.flight_data_log_uuid, a.sha256,
          a.status, a.garmin_csv_file_id, a.created_at, tok.display_name AS device_name
        FROM ipca_sync_agent_upload_acknowledgments a
        LEFT JOIN ipca_sync_agent_tokens tok ON tok.id = a.token_id
        ORDER BY a.created_at DESC
        LIMIT 50
    ");
} elseif ($hasAcks) {
    $recentAcknowledgments = garmin_sync_rows($pdo, "
        SELECT
          a.id, a.provider_name, a.garmin_entry_uuid, a.flight_data_log_uuid, a.sha256,
          a.status, a.garmin_csv_file_id, a.created_at, NULL AS device_name
        FROM ipca_sync_agent_upload_acknowledgments a
        ORDER BY a.created_at DESC
        LIMIT 50
    ");
}

$filterTail = strtoupper(trim((string)($_GET['tail'] ?? '')));
$filterDateFrom = trim((string)($_GET['date_from'] ?? ''));
$filterDateTo = trim((string)($_GET['date_to'] ?? ''));
$filterDep = strtoupper(trim((string)($_GET['dep'] ?? '')));
$filterArr = strtoupper(trim((string)($_GET['arr'] ?? '')));
$filterStatus = trim((string)($_GET['status'] ?? ''));
$showIncomplete = in_array(strtolower(trim((string)($_GET['show_incomplete'] ?? ''))), array('1', 'true', 'yes'), true);
$showHidden = in_array(strtolower(trim((string)($_GET['show_hidden'] ?? ''))), array('1', 'true', 'yes'), true);
$newOnly = in_array(strtolower(trim((string)($_GET['new_only'] ?? ''))), array('1', 'true', 'yes'), true);

$flightRows = array();
$flightModals = array();
$tailOptions = array();
$depOptions = array();
$arrOptions = array();
foreach ($summaryOptionRows as $optionRow) {
    $tail = strtoupper(trim((string)($optionRow['tail_number'] ?? '')));
    $dep = strtoupper(trim((string)($optionRow['departure_airport_code'] ?? '')));
    $arr = strtoupper(trim((string)($optionRow['arrival_airport_code'] ?? '')));
    if ($tail !== '' && $tail !== 'UNKNOWN TAIL' && $tail !== 'UNKNOWN') {
        $tailOptions[$tail] = $tail;
    }
    if ($dep !== '' && $dep !== '--') {
        $depOptions[$dep] = $dep;
    }
    if ($arr !== '' && $arr !== '--') {
        $arrOptions[$arr] = $arr;
    }
}
foreach ($recentTracks as $track) {
    $classification = garmin_sync_metadata_value((string)($track['raw_metadata_json'] ?? ''), 'trackClassification');
    $sourceNames = garmin_sync_metadata_value((string)($track['raw_metadata_json'] ?? ''), 'sourceNames');
    $csvSummary = !empty($track['csv_file_id']) ? $summaryService->summaryForCsvFile(array(
        'id' => (int)$track['csv_file_id'],
        'aircraft_registration' => (string)($track['csv_aircraft_registration'] ?? ''),
        'aircraft_ident' => (string)($track['csv_aircraft_ident'] ?? ''),
        'system_identifier' => (string)($track['csv_system_identifier'] ?? ''),
        'airframe_hours_start' => $track['csv_airframe_hours_start'] ?? null,
        'engine_hours_start' => $track['csv_engine_hours_start'] ?? null,
        'storage_path' => (string)($track['csv_storage_path'] ?? ''),
        'import_profile' => (string)($track['csv_import_profile'] ?? ''),
        'first_valid_sample_utc' => (string)($track['csv_first_valid_sample_utc'] ?? ''),
        'last_valid_sample_utc' => (string)($track['csv_last_valid_sample_utc'] ?? ''),
        'valid_row_count' => (int)($track['csv_valid_row_count'] ?? 0),
    )) : array();
    if ($csvSummary === array() || (string)($csvSummary['status'] ?? '') === 'not_analyzed') {
        $csvSummary = $trackSummaryService->summaryForTrackArtifact($track);
    }
    $statusLabel = garmin_sync_status_label($csvSummary, $classification);
    $uploadedAt = (string)($track['uploaded_at'] ?? $track['last_seen_at'] ?? '');
    $isNew = garmin_sync_is_new($uploadedAt);
    $tail = strtoupper((string)($csvSummary['tail'] ?? 'Unknown tail'));
    $dep = strtoupper((string)($csvSummary['dep_airport'] ?? '--'));
    $arr = strtoupper((string)($csvSummary['arr_airport'] ?? '--'));
    $startUtc = (string)($csvSummary['start_utc'] ?? $track['entry_generated_track_start_utc'] ?? '');
    if ($tail !== '' && $tail !== 'UNKNOWN TAIL') {
        $tailOptions[$tail] = $tail;
    }
    if ($dep !== '' && $dep !== '--') {
        $depOptions[$dep] = $dep;
    }
    if ($arr !== '' && $arr !== '--') {
        $arrOptions[$arr] = $arr;
    }
    $flightRows[] = array(
        'track' => $track,
        'summary' => $csvSummary,
        'classification' => $classification,
        'source_names' => $sourceNames,
        'status_label' => $statusLabel,
        'uploaded_at' => $uploadedAt,
        'is_new' => $isNew,
    );
}
sort($tailOptions);
sort($depOptions);
sort($arrOptions);
$visibleCsvFileIds = array_values(array_unique(array_filter(array_map(
    static fn(array $flight): int => (int)($flight['track']['csv_file_id'] ?? 0),
    $flightRows
))));
$replayPayloadsByCsvFileId = array();
$replayPayloadStatusError = '';
try {
    $replayPayloadsByCsvFileId = $replayPayloadService->payloadsForCsvFileIds($visibleCsvFileIds);
} catch (Throwable $e) {
    $replayPayloadStatusError = 'Garmin replay payload status could not be loaded: ' . $e->getMessage();
}

$attentionRows = array();
foreach ($recentTracks as $track) {
    $classification = garmin_sync_metadata_value((string)($track['raw_metadata_json'] ?? ''), 'trackClassification');
    $status = (string)($track['upload_status'] ?? '');
    $fieldCount = (int)($track['field_count'] ?? 0);
    if ($status === '' || $status === 'review_required' || str_contains(strtolower($classification), 'unknown') || $fieldCount === 0) {
        $attentionRows[] = $track;
    }
    if (count($attentionRows) >= 20) {
        break;
    }
}

$acceptedUploads = 0;
$alreadyExists = 0;
$reviewRequired = 0;
$rejectedUploads = 0;
foreach ($ackSummary as $row) {
    $status = (string)($row['status'] ?? '');
    $total = (int)($row['total'] ?? 0);
    if ($status === 'accepted') {
        $acceptedUploads += $total;
    } elseif ($status === 'already_exists') {
        $alreadyExists += $total;
    } elseif ($status === 'review_required') {
        $reviewRequired += $total;
    } elseif (in_array($status, array('rejected', 'failed'), true)) {
        $rejectedUploads += $total;
    }
}

$missingTables = array();
if (!$hasTokens) {
    $missingTables[] = 'ipca_sync_agent_tokens';
}
if (!$hasAcks) {
    $missingTables[] = 'ipca_sync_agent_upload_acknowledgments';
}
if (!$hasTracks) {
    $missingTables[] = 'ipca_garmin_normalized_track_artifacts';
}

$error = trim((string)($_GET['error'] ?? ''));
if ($error === '' && $replayPayloadStatusError !== '') {
    $error = $replayPayloadStatusError;
}
$notice = '';
if (isset($_GET['summary_processed'])) {
    $notice = 'Garmin summaries processed: ' . (int)$_GET['summary_processed']
        . ' (CSV ' . (int)($_GET['csv_processed'] ?? 0)
        . ', tracks ' . (int)($_GET['track_processed'] ?? 0)
        . '); failed: ' . (int)($_GET['summary_failed'] ?? 0) . '.';
}
if (isset($_GET['summary_queued'])) {
    $notice = 'Garmin CSV summary jobs queued: ' . (int)$_GET['summary_queued'] . '.';
}
if (isset($_GET['flights_hidden'])) {
    $notice = (int)$_GET['flights_hidden'] . ' Garmin flight(s) hidden.';
}
if (isset($_GET['flights_restored'])) {
    $notice = (int)$_GET['flights_restored'] . ' Garmin flight(s) restored.';
}
if (isset($_GET['flights_reprocess_queued'])) {
    $notice = 'Garmin flight summary reprocess jobs queued: ' . (int)$_GET['flights_reprocess_queued'] . '.';
}
if (isset($_GET['replays_rebuilt'])) {
    $notice = 'Garmin replay payloads rebuilt: ' . (int)$_GET['replays_rebuilt'] . '.';
}
if (isset($_GET['historical_batch'])) {
    $notice = 'Historical Garmin backfill batch created: #' . (int)$_GET['historical_batch'] . '. Run the historical_backfill worker queue to process uploaded files.';
}
if (isset($_GET['flightcircle_batch'])) {
    $notice = 'FlightCircle historical import completed: batch #' . (int)$_GET['flightcircle_batch'] . '. Review identity suggestions, resource classifications, and logbook proposals before approval.';
}

cw_header('Garmin Sync Agent');
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
.garmin-page{display:grid;gap:16px}.garmin-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:16px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.garmin-muted{color:#64748b;font-size:12px}.garmin-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.garmin-kv{border:1px solid #e2e8f0;border-radius:12px;padding:10px;background:#f8fafc}.garmin-label{color:#64748b;font-size:10px;text-transform:uppercase;letter-spacing:.04em}.garmin-value{font-weight:800;margin-top:3px}.garmin-badge{display:inline-flex;border-radius:999px;padding:2px 7px;font-size:10px;font-weight:800;background:#e2e8f0;color:#334155;white-space:nowrap}.garmin-badge-ok{background:#dcfce7;color:#166534}.garmin-badge-warn{background:#fef3c7;color:#92400e}.garmin-badge-danger{background:#fee2e2;color:#991b1b}.garmin-badge-new{background:#dbeafe;color:#1d4ed8}.garmin-state-badge{display:inline-flex;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.04em}.garmin-state-complete{background:#dcfce7;color:#166534}.garmin-state-processing,.garmin-state-queued,.garmin-state-processing_required{background:#dbeafe;color:#1d4ed8}.garmin-state-needs_review{background:#fef3c7;color:#92400e}.garmin-state-failed{background:#fee2e2;color:#991b1b}.garmin-operational-grid{display:grid;grid-template-columns:1.4fr repeat(3,minmax(135px,1fr));gap:12px;align-items:stretch}.garmin-primary-action{border:0;border-radius:12px;background:#0f172a;color:#fff;font-weight:900;padding:11px 15px;cursor:pointer;font-size:13px}.garmin-primary-action[disabled]{background:#94a3b8;cursor:not-allowed}.garmin-table-wrap{overflow-x:visible}.garmin-flights-scroll{max-height:72vh;overflow:auto;border:1px solid #e2e8f0;border-radius:12px}.garmin-flights-scroll .garmin-table th{position:sticky;top:0;z-index:2;background:#fff}.garmin-table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:11px}.garmin-table th,.garmin-table td{border-bottom:1px solid #e2e8f0;padding:7px 6px;text-align:left;vertical-align:middle;overflow:hidden;text-overflow:ellipsis}.garmin-table th{color:#475569;font-size:9.5px;text-transform:uppercase;letter-spacing:.025em;resize:none;overflow:hidden}.garmin-code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:11px;word-break:break-all}.garmin-toolbar,.garmin-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.garmin-toolbar a,.garmin-toolbar button,.garmin-actions button,.garmin-actions a{border:0;border-radius:10px;background:#0f172a;color:#fff;font-weight:800;padding:8px 10px;text-decoration:none;cursor:pointer;font-size:12px}.garmin-toolbar a.secondary,.garmin-toolbar button.secondary,.garmin-actions .secondary{background:#475569}.garmin-toolbar form{margin:0}.garmin-progress{position:relative;height:18px;background:#e2e8f0;border-radius:999px;overflow:hidden}.garmin-progress span{display:block;height:100%;background:linear-gradient(90deg,#2563eb,#0ea5e9)}.garmin-progress strong{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#0f172a;font-size:11px;font-weight:900;text-shadow:0 1px 0 rgba(255,255,255,.75)}.garmin-empty{padding:18px;border:1px dashed #cbd5e1;border-radius:12px;color:#64748b;background:#f8fafc}.garmin-notice{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:12px}.garmin-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px}.garmin-filter{display:grid;grid-template-columns:110px 132px 132px 110px 110px;gap:8px 10px;margin-top:12px;align-items:end;justify-content:start}.garmin-filter-control{display:grid;grid-template-rows:14px 32px;gap:3px;align-items:end}.garmin-filter-label{display:block;height:14px;color:#64748b;font-size:10px;line-height:14px;text-transform:uppercase;letter-spacing:.04em}.garmin-filter input,.garmin-filter select,.garmin-filter button{box-sizing:border-box;width:100%;height:32px;border-radius:8px;font:inherit;font-size:12px;line-height:1}.garmin-filter input,.garmin-filter select{border:1px solid #cbd5e1;background:#fff;padding:6px 8px}.garmin-filter button{border:0;background:#475569;color:#fff;font-weight:800;padding:6px 10px;cursor:pointer}.garmin-row-button{border:0;background:transparent;color:#1d4ed8;font-weight:900;cursor:pointer;padding:0}.garmin-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;z-index:9999;padding:24px;overflow:auto}.garmin-modal-backdrop.is-open{display:block}.garmin-modal{max-width:980px;margin:0 auto;background:#fff;border-radius:16px;box-shadow:0 24px 70px rgba(15,23,42,.35);overflow:hidden}.garmin-modal-header{display:flex;justify-content:space-between;gap:12px;padding:16px 18px;border-bottom:1px solid #e2e8f0}.garmin-modal-body{padding:16px 18px;display:grid;gap:12px}.garmin-detail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}.garmin-raw-block{max-height:240px;overflow:auto;background:#0f172a;color:#e2e8f0;border-radius:10px;padding:10px;font-size:11px}.garmin-compact{white-space:nowrap}.garmin-tail-pill{display:inline-flex;align-items:center;border:1px solid #cbd5e1;border-radius:999px;padding:2px 7px;font-size:10px;font-weight:900;white-space:nowrap}.garmin-tail-unknown{background:#fee2e2;color:#991b1b;border-color:#fecaca}.garmin-upload-pill{display:inline-flex;border-radius:999px;background:#e0f2fe;color:#075985;padding:2px 7px;font-size:10px;font-weight:900}
.garmin-flight-hero{display:grid;grid-template-columns:1fr 1.25fr .8fr 1.25fr;gap:12px}.garmin-flight-card,.garmin-counter-card,.garmin-source-panel,.garmin-map-panel{border:1px solid #e2e8f0;border-radius:14px;background:#f8fafc;padding:12px}.garmin-flight-big{font-size:17px;font-weight:900;color:#0f172a;margin-top:4px}.garmin-flight-center{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}.garmin-flight-duration{font-size:24px;font-weight:950;color:#1d4ed8}.garmin-counter-grid{display:grid;grid-template-columns:1fr 1fr 1.2fr;gap:12px}.garmin-counter-row{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #e2e8f0;padding:7px 0;font-size:15px}.garmin-counter-row strong{font-size:18px}.garmin-counter-total{margin-top:10px;border-radius:12px;background:#0f172a;color:#fff;padding:9px 10px;font-weight:900}.garmin-source-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:12px}.garmin-source-row{display:grid;grid-template-columns:130px 1fr;gap:10px;align-items:start;padding:7px 0;border-bottom:1px solid #e2e8f0}.garmin-source-row span{color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.04em}.garmin-source-row code{font-size:12px;word-break:break-all}.garmin-pill-row{display:flex;gap:7px;flex-wrap:wrap;align-items:center}.garmin-pill{display:inline-flex;border-radius:999px;background:#e2e8f0;color:#334155;border:1px solid #cbd5e1;padding:4px 9px;font-size:11px;font-weight:900}.garmin-pill-ok,.garmin-pill-good{background:#dcfce7;color:#166534;border-color:#bbf7d0}.garmin-pill-warn{background:#fef3c7;color:#92400e;border-color:#fde68a}.garmin-pill-danger,.garmin-pill-bad{background:#fee2e2;color:#991b1b;border-color:#fecaca}.garmin-quality-bar{height:9px;border-radius:999px;background:#e2e8f0;overflow:hidden;margin:8px 0}.garmin-quality-bar span{display:block;height:100%;border-radius:999px}.garmin-quality-good{background:linear-gradient(90deg,#22c55e,#16a34a)}.garmin-quality-warn{background:linear-gradient(90deg,#f59e0b,#22c55e)}.garmin-quality-bad{background:linear-gradient(90deg,#ef4444,#f59e0b)}.garmin-track-map{position:relative;height:260px;border:1px solid #cbd5e1;border-radius:12px;overflow:hidden;background:#e2e8f0}.garmin-map-message{position:absolute;z-index:500;left:12px;top:12px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:7px 9px;color:#475569;font-size:12px;box-shadow:0 6px 16px rgba(15,23,42,.12)}@media(max-width:900px){.garmin-flight-hero,.garmin-counter-grid,.garmin-source-grid{grid-template-columns:1fr}.garmin-source-row{grid-template-columns:1fr}}
</style>
<div class="garmin-page">
  <section class="garmin-card">
    <div style="display:flex;gap:16px;justify-content:space-between;align-items:flex-start;flex-wrap:wrap">
      <div>
        <h2 style="margin:0">Garmin Sync Agent Dashboard</h2>
        <p class="garmin-muted">Server-side view of Garmin data uploaded by the native Mac IPCA Sync Agent. The old cloud-browser Garmin controls have been retired from this page.</p>
      </div>
      <div class="garmin-toolbar">
        <a href="/admin/flight_records.php" class="secondary">Flight Records</a>
        <a href="/admin/cockpit_recorder.php" class="secondary">Cockpit Recorder</a>
      </div>
    </div>
    <?php if ($missingTables !== array()): ?>
      <p><span class="garmin-badge garmin-badge-danger">Missing tables</span> <?= h(implode(', ', $missingTables)) ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?><div class="garmin-error"><?= h($error) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="garmin-notice"><?= h($notice) ?></div><?php endif; ?>
  </section>

  <section class="garmin-card">
    <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap">
      <div>
        <h3 style="margin:0">Historical SD Card CSV Backfill</h3>
        <p class="garmin-muted">Bulk-upload historical Garmin G3X/G1000 CSV files as immutable SD-card evidence. This does not alter the Mac Sync Agent workflow.</p>
      </div>
      <div class="garmin-actions">
        <a class="secondary" href="/admin/api/garmin_historical_backfill_report.php">Download report</a>
      </div>
    </div>
    <?php if (empty($historicalBackfillStatus['ready'])): ?>
      <div class="garmin-empty" style="margin-top:12px">Historical backfill tables are not installed yet. Run <code>scripts/sql/2026_07_20_historical_garmin_flightcircle_migration.sql</code>.</div>
    <?php else: ?>
      <?php
        $historicalProgress = is_array($historicalBackfillStatus['progress'] ?? null) ? $historicalBackfillStatus['progress'] : array();
        $historicalActiveBatch = is_array($historicalBackfillStatus['active_batch'] ?? null) ? $historicalBackfillStatus['active_batch'] : array();
        $historicalActiveBatchId = (int)($historicalActiveBatch['id'] ?? 0);
        $historicalTotal = (int)($historicalProgress['total'] ?? 0);
        $historicalDone = (int)($historicalProgress['done'] ?? 0);
        $historicalQueued = (int)($historicalProgress['queued'] ?? ($historicalBackfillStatus['file_statuses']['queued'] ?? 0));
        $historicalFailed = (int)($historicalProgress['failed'] ?? 0);
        $historicalDuplicates = (int)($historicalProgress['duplicates'] ?? ($historicalBackfillStatus['duplicate_statuses']['previously_imported'] ?? 0));
        $historicalRemaining = (int)($historicalProgress['remaining'] ?? $historicalQueued);
        $historicalNeedsReview = (int)($historicalProgress['needs_review'] ?? 0);
        $historicalPercent = (float)($historicalProgress['percent'] ?? 0);
        $historicalState = (string)($historicalProgress['state'] ?? 'waiting_for_upload');
        $historicalHumanState = 'Waiting for upload';
        $historicalPrimaryAction = 'Select Garmin CSV files or a folder, then upload.';
        if ($historicalTotal > 0 && $historicalQueued > 0) {
            $historicalHumanState = 'Uploaded, waiting for processing';
            $historicalPrimaryAction = 'Click Process next 25 queued files now, or run the historical_backfill worker.';
        } elseif ($historicalTotal > 0 && $historicalRemaining > 0) {
            $historicalHumanState = 'Processing in background';
            $historicalPrimaryAction = 'Wait for the worker or keep processing chunks from this page.';
        } elseif ($historicalTotal > 0 && $historicalFailed > 0) {
            $historicalHumanState = 'Processing complete with failures';
            $historicalPrimaryAction = 'Review failed rows in the table and reprocess selected files if needed.';
        } elseif ($historicalTotal > 0 && $historicalNeedsReview > 0) {
            $historicalHumanState = 'Ready for review';
            $historicalPrimaryAction = 'Review rows still marked Needs Review and use the table actions.';
        } elseif ($historicalTotal > 0) {
            $historicalHumanState = 'Processing complete';
            $historicalPrimaryAction = 'No action needed here unless you want to inspect/reprocess selected rows.';
        }
      ?>
      <div class="garmin-kv" style="margin-top:14px;border-color:#bae6fd;background:#f0f9ff" data-historical-status-panel data-batch-id="<?= $historicalActiveBatchId ?>">
        <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap">
          <div>
            <div class="garmin-label">Historical batch status</div>
            <div class="garmin-value" style="font-size:18px" data-historical-human-state><?= h($historicalHumanState) ?></div>
            <div class="garmin-muted" data-historical-primary-action><?= h($historicalPrimaryAction) ?></div>
          </div>
          <div class="garmin-actions">
            <?php if ($historicalQueued > 0): ?>
              <button type="button" data-process-historical-queued data-batch-id="<?= $historicalActiveBatchId ?>">Process next 25 queued files now</button>
              <span class="garmin-muted" data-process-historical-message><?= number_format($historicalQueued) ?> queued.</span>
            <?php endif; ?>
            <?php if ($historicalActiveBatchId > 0): ?>
              <a class="secondary" href="/admin/api/garmin_historical_backfill_report.php?batch_id=<?= $historicalActiveBatchId ?>">Download this batch report</a>
            <?php endif; ?>
          </div>
        </div>
        <div style="margin-top:12px">
          <div class="garmin-progress" data-historical-main-progress><span style="width:<?= h((string)$historicalPercent) ?>%"></span><strong><?= h((string)$historicalPercent) ?>%</strong></div>
        </div>
        <div class="garmin-grid" style="margin-top:12px">
          <div class="garmin-kv"><div class="garmin-label">Total in batch</div><div class="garmin-value" data-historical-total><?= number_format($historicalTotal) ?></div></div>
          <div class="garmin-kv"><div class="garmin-label">Queued</div><div class="garmin-value" data-historical-queued><?= number_format($historicalQueued) ?></div></div>
          <div class="garmin-kv"><div class="garmin-label">Processed</div><div class="garmin-value" data-historical-done><?= number_format($historicalDone) ?></div></div>
          <div class="garmin-kv"><div class="garmin-label">Duplicates</div><div class="garmin-value" data-historical-duplicates><?= number_format($historicalDuplicates) ?></div></div>
          <div class="garmin-kv"><div class="garmin-label">Failed</div><div class="garmin-value" data-historical-failed><?= number_format($historicalFailed) ?></div></div>
          <div class="garmin-kv"><div class="garmin-label">Manual review</div><div class="garmin-value" data-historical-needs-review><?= number_format($historicalNeedsReview) ?></div></div>
        </div>
        <div class="garmin-muted" style="margin-top:8px">
          Queued = uploaded but not parsed yet. Processed = parsed/classified or duplicate. Manual review = processed but still needs a human decision. Failed = parser/storage error; select and reprocess or inspect the report.
        </div>
      </div>
      <form method="post" action="/admin/api/garmin_historical_backfill_upload.php" enctype="multipart/form-data" style="display:grid;gap:10px;margin-top:12px" data-historical-garmin-upload data-latest-batch-id="<?= h((string)(($historicalBackfillStatus['active_batch']['id'] ?? '') ?: '')) ?>">
        <div class="garmin-grid">
          <label class="garmin-kv"><span class="garmin-label">Aircraft hint</span><input name="aircraft_hint" placeholder="Optional tail, e.g. N392EA" style="width:100%;box-sizing:border-box;margin-top:6px;border:1px solid #cbd5e1;border-radius:8px;padding:8px"></label>
          <label class="garmin-kv"><span class="garmin-label">Source notes</span><input name="notes" placeholder="SD card box, archive folder, date range" style="width:100%;box-sizing:border-box;margin-top:6px;border:1px solid #cbd5e1;border-radius:8px;padding:8px"></label>
          <label class="garmin-kv"><span class="garmin-label">CSV files</span><input name="garmin_csv_files[]" type="file" accept=".csv,text/csv" multiple style="width:100%;margin-top:6px" data-historical-file-input></label>
          <div class="garmin-kv"><span class="garmin-label">Folder upload</span><input name="garmin_csv_files[]" type="file" accept=".csv,text/csv" multiple webkitdirectory directory style="width:100%;margin-top:6px" data-historical-file-input></div>
        </div>
        <div class="garmin-actions"><button type="submit" data-historical-upload-button>Upload Historical Garmin CSVs</button><span class="garmin-muted">Files are stored first, then processed asynchronously on queue <code>historical_backfill</code>.</span></div>
        <div class="garmin-grid" data-historical-upload-summary style="display:none">
          <div class="garmin-kv"><div class="garmin-label">Selected files</div><div class="garmin-value" data-historical-selected-count>0</div></div>
          <div class="garmin-kv"><div class="garmin-label">Selected size</div><div class="garmin-value" data-historical-selected-size>0 B</div></div>
          <div class="garmin-kv"><div class="garmin-label">Active batch</div><div class="garmin-value" data-historical-batch-label>Not started</div></div>
          <div class="garmin-kv"><div class="garmin-label">Backend state</div><div class="garmin-value" data-historical-backend-state>Waiting</div></div>
        </div>
        <div>
          <div class="garmin-label">Upload transfer</div>
          <div class="garmin-progress" data-historical-upload-progress style="display:none"><span style="width:0%"></span><strong>0%</strong></div>
        </div>
        <div>
          <div class="garmin-label">Backend processing</div>
          <div class="garmin-progress" data-historical-backend-progress style="display:none"><span style="width:0%"></span><strong>0%</strong></div>
        </div>
        <div class="garmin-muted" data-historical-upload-message></div>
      </form>
      <details style="margin-top:14px">
        <summary><strong>Technical counters</strong> <span class="garmin-muted">raw upload and classification counts</span></summary>
      <div class="garmin-grid" style="margin-top:10px">
        <?php foreach (($historicalBackfillStatus['file_statuses'] ?? array()) as $status => $count): ?>
          <div class="garmin-kv"><div class="garmin-label">File <?= h((string)$status) ?></div><div class="garmin-value"><?= number_format((int)$count) ?></div></div>
        <?php endforeach; ?>
        <?php foreach (($historicalBackfillStatus['segment_classifications'] ?? array()) as $status => $count): ?>
          <div class="garmin-kv"><div class="garmin-label"><?= h((string)$status) ?></div><div class="garmin-value"><?= number_format((int)$count) ?></div></div>
        <?php endforeach; ?>
      </div>
      </details>
      <?php if ($historicalBackfillFiles !== array()): ?>
        <div class="garmin-table-wrap" style="margin-top:14px">
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
            <h4 style="margin:0">Recent Historical Garmin Files</h4>
            <div class="garmin-actions">
              <button type="button" class="secondary" data-historical-bulk-action="reprocess">Reprocess selected</button>
              <button type="button" class="secondary" data-historical-bulk-action="mark_avionics_only">Mark selected avionics-only</button>
              <span class="garmin-muted" data-historical-bulk-message></span>
            </div>
          </div>
          <table class="garmin-table">
            <thead><tr><th style="width:4%"><input type="checkbox" data-select-all-historical></th><th>File</th><th>Aircraft</th><th>Duplicate</th><th>Status</th><th>Class</th><th>Review</th></tr></thead>
            <tbody>
              <?php foreach ($historicalBackfillFiles as $file): ?>
                <?php
                  $historicalRowQueued = (string)$file['parse_status'] === 'queued';
                  $historicalClassLabel = $historicalRowQueued ? 'Not processed yet' : (string)$file['classification'];
                  $historicalReviewLabel = $historicalRowQueued ? 'No review yet' : (string)$file['review_status'];
                ?>
                <tr>
                  <td><input type="checkbox" data-historical-file-checkbox value="<?= (int)$file['id'] ?>"></td>
                  <td><span class="garmin-code"><?= h((string)$file['original_filename']) ?></span></td>
                  <td><?= garmin_sync_tail_pill((string)($file['resolved_aircraft_registration'] ?: $file['selected_aircraft_hint'])) ?></td>
                  <td><span class="garmin-badge <?= (string)$file['exact_duplicate_status'] === 'new' ? 'garmin-badge-ok' : 'garmin-badge-warn' ?>"><?= h((string)$file['exact_duplicate_status']) ?></span></td>
                  <td><span class="garmin-badge <?= garmin_sync_badge_class((string)$file['parse_status']) ?>"><?= h((string)$file['parse_status']) ?></span></td>
                  <td><?= h($historicalClassLabel) ?></td>
                  <td><?= h($historicalReviewLabel) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="garmin-card">
    <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap">
      <div>
        <h3 style="margin:0">FlightCircle Historical Migration</h3>
        <p class="garmin-muted">Import FlightCircle exports as migration evidence, normalize into source-neutral operation candidates, suggest missing users, and preserve AATD simulator time separately from aircraft ledgers.</p>
      </div>
    </div>
    <?php if (empty($flightCircleStatus['ready'])): ?>
      <div class="garmin-empty" style="margin-top:12px"><?= h((string)($flightCircleStatus['message'] ?? 'FlightCircle migration tables are not installed yet.')) ?> Run <code>scripts/sql/2026_07_20_historical_garmin_flightcircle_migration.sql</code> if the tables are missing.</div>
    <?php else: ?>
      <form method="post" action="/admin/api/flightcircle_historical_import.php" enctype="multipart/form-data" style="display:grid;gap:10px;margin-top:12px">
        <div class="garmin-grid">
          <label class="garmin-kv"><span class="garmin-label">FlightCircle CSV</span><input name="flightcircle_csv" type="file" accept=".csv,text/csv" required style="width:100%;margin-top:6px"></label>
          <div class="garmin-kv"><span class="garmin-label">Resource rules</span><div class="garmin-muted" style="margin-top:6px">Aircraft: N446CS, N392EA, N641TH, N428EA, N153PC. AATD: AL172M2. Ignored: Classroom I/II, Apple Vision Pro, Exam Room, Main Office.</div></div>
          <div class="garmin-kv"><span class="garmin-label">Informational only</span><div class="garmin-muted" style="margin-top:6px">Training mission codes and FlightCircle routes do not drive authoritative logbook categories or airport detection.</div></div>
          <label class="garmin-kv"><span class="garmin-label">Migration dataset</span><span style="display:flex;gap:8px;align-items:flex-start;margin-top:6px"><input type="checkbox" name="replace_active_dataset" value="1" checked><span>Replace active FlightCircle migration dataset. Older imports remain preserved but are superseded for Garmin enrichment.</span></span></label>
        </div>
        <div class="garmin-actions"><button type="submit">Import FlightCircle Historical CSV</button></div>
      </form>
      <?php $fcActiveValidation = is_array($flightCircleStatus['active_validation'] ?? null) ? $flightCircleStatus['active_validation'] : array(); ?>
      <?php if (!empty($fcActiveValidation['ready'])): ?>
        <?php
          $fcValidationSummary = is_array($fcActiveValidation['summary'] ?? null) ? $fcActiveValidation['summary'] : array();
          $fcMissingDate = (int)($fcValidationSummary['missing_date_rows'] ?? 0);
          $fcMissingTail = (int)($fcValidationSummary['missing_tail_rows'] ?? 0);
          $fcMissingHobbs = (int)($fcValidationSummary['missing_hobbs_out_rows'] ?? 0);
          $fcValidationOk = ($fcMissingDate + $fcMissingTail + $fcMissingHobbs) === 0;
        ?>
        <div class="garmin-kv" style="margin-top:14px;border-color:<?= $fcValidationOk ? '#bbf7d0' : '#fed7aa' ?>;background:<?= $fcValidationOk ? '#f0fdf4' : '#fff7ed' ?>">
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap">
            <div>
              <div class="garmin-label">Active FlightCircle Dataset Validation</div>
              <div class="garmin-muted" style="margin-top:4px">Only this active dataset is used for FlightCircle enrichment. Matching uses tail and Hobbs-Out; date remains visible for review only.</div>
            </div>
            <span class="garmin-badge <?= $fcValidationOk ? 'garmin-badge-ok' : 'garmin-badge-warn' ?>"><?= $fcValidationOk ? 'Ready for enrichment' : 'Needs import review' ?></span>
          </div>
          <div class="garmin-grid" style="margin-top:10px">
            <div><span class="garmin-label">Batch</span><div class="garmin-value">#<?= (int)($fcActiveValidation['batch_id'] ?? 0) ?></div></div>
            <div><span class="garmin-label">Total rows</span><div class="garmin-value"><?= number_format((int)($fcValidationSummary['total_rows'] ?? 0)) ?></div></div>
            <div><span class="garmin-label">Aircraft rows</span><div class="garmin-value"><?= number_format((int)($fcValidationSummary['aircraft_rows'] ?? 0)) ?></div></div>
            <div><span class="garmin-label">AATD rows</span><div class="garmin-value"><?= number_format((int)($fcValidationSummary['simulator_rows'] ?? 0)) ?></div></div>
            <div><span class="garmin-label">Ignored rows</span><div class="garmin-value"><?= number_format((int)($fcValidationSummary['ignored_rows'] ?? 0)) ?></div></div>
            <div><span class="garmin-label">Date range</span><div class="garmin-value"><?= h(substr((string)($fcValidationSummary['first_depart_local'] ?? ''), 0, 10) ?: '--') ?> to <?= h(substr((string)($fcValidationSummary['last_depart_local'] ?? ''), 0, 10) ?: '--') ?></div></div>
            <div><span class="garmin-label">Missing date</span><div class="garmin-value"><?= number_format($fcMissingDate) ?></div></div>
            <div><span class="garmin-label">Missing tail</span><div class="garmin-value"><?= number_format($fcMissingTail) ?></div></div>
            <div><span class="garmin-label">Missing Hobbs-Out</span><div class="garmin-value"><?= number_format($fcMissingHobbs) ?></div></div>
          </div>
          <?php if (($fcActiveValidation['tail_counts'] ?? array()) !== array()): ?>
            <div class="garmin-muted" style="margin-top:8px">Tail counts:
              <?php foreach (array_slice((array)$fcActiveValidation['tail_counts'], 0, 10) as $tailCount): ?>
                <span class="garmin-code"><?= h((string)($tailCount['tail_number'] ?? '--')) ?> <?= number_format((int)($tailCount['total'] ?? 0)) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="garmin-grid" style="margin-top:14px">
        <?php foreach (($flightCircleStatus['resources'] ?? array()) as $status => $count): ?>
          <div class="garmin-kv"><div class="garmin-label">Resource <?= h((string)$status) ?></div><div class="garmin-value"><?= number_format((int)$count) ?></div></div>
        <?php endforeach; ?>
        <?php foreach (($flightCircleStatus['identity_mappings'] ?? array()) as $status => $count): ?>
          <div class="garmin-kv"><div class="garmin-label">Identity <?= h((string)$status) ?></div><div class="garmin-value"><?= number_format((int)$count) ?></div></div>
        <?php endforeach; ?>
      </div>
      <?php if (($flightCircleStatus['batches'] ?? array()) !== array()): ?>
        <div class="garmin-table-wrap" style="margin-top:14px">
          <h4 style="margin:0 0 8px">Recent FlightCircle Imports</h4>
          <table class="garmin-table">
            <thead><tr><th>Batch</th><th>Dataset</th><th>Rows</th><th>Aircraft</th><th>AATD</th><th>Ignored</th><th>Unknown</th><th>Identity Review</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach (($flightCircleStatus['batches'] ?? array()) as $batch): ?>
                <tr>
                  <td><span class="garmin-code"><?= h(substr((string)$batch['batch_uuid'], 0, 8)) ?></span><br><span class="garmin-muted"><?= h((string)$batch['original_filename']) ?></span></td>
                  <td>
                    <?php if (!empty($batch['active_dataset'])): ?>
                      <span class="garmin-badge garmin-badge-ok">Active</span>
                    <?php elseif (!empty($batch['superseded_at'])): ?>
                      <span class="garmin-badge garmin-badge-warn">Superseded</span>
                    <?php else: ?>
                      <span class="garmin-muted">Preserved</span>
                    <?php endif; ?>
                  </td>
                  <td><?= number_format((int)$batch['row_count']) ?></td>
                  <td><?= number_format((int)$batch['aircraft_row_count']) ?></td>
                  <td><?= number_format((int)$batch['simulator_row_count']) ?></td>
                  <td><?= number_format((int)$batch['ignored_row_count']) ?></td>
                  <td><?= number_format((int)$batch['unknown_resource_count']) ?></td>
                  <td><?= number_format((int)$batch['identity_review_count']) ?></td>
                  <td><span class="garmin-badge <?= garmin_sync_badge_class((string)$batch['import_status']) ?>"><?= h((string)$batch['import_status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <?php if (($flightCircleStatus['recent_staging_records'] ?? array()) !== array()): ?>
        <div class="garmin-table-wrap" style="margin-top:14px">
          <h4 style="margin:0 0 8px">Stored FlightCircle Rows</h4>
          <p class="garmin-muted" style="margin-top:0">These are the normalized FlightCircle records used for enrichment. For matching, the important values are Tail and Hobbs-Out. Date is shown for review context only.</p>
          <table class="garmin-table">
            <thead><tr><th>Date</th><th>Tail / Resource</th><th>User</th><th>Instructor</th><th>Reservation</th><th>Hobbs Out</th><th>Hobbs In</th><th>Tach Out</th><th>Tach In</th><th>Disposition</th></tr></thead>
            <tbody>
              <?php foreach (($flightCircleStatus['recent_staging_records'] ?? array()) as $record): ?>
                <tr>
                  <td><?= h(substr((string)($record['depart_local'] ?? ''), 0, 10) ?: '--') ?></td>
                  <td><?= garmin_sync_tail_pill((string)($record['tail_number'] ?? $record['resource_identifier'] ?? '')) ?><br><span class="garmin-muted"><?= h((string)($record['resource_type'] ?? '')) ?></span></td>
                  <td><?= h((string)($record['user_text'] ?? '') ?: '--') ?></td>
                  <td><?= h((string)($record['instructor_text'] ?? '') ?: '--') ?></td>
                  <td><?= h((string)($record['reservation_type'] ?? '') ?: '--') ?></td>
                  <td><strong><?= h($record['hobbs_out'] !== null ? number_format((float)$record['hobbs_out'], 1) : '--') ?></strong></td>
                  <td><?= h($record['hobbs_in'] !== null ? number_format((float)$record['hobbs_in'], 1) : '--') ?></td>
                  <td><?= h($record['tach_out'] !== null ? number_format((float)$record['tach_out'], 1) : '--') ?></td>
                  <td><?= h($record['tach_in'] !== null ? number_format((float)$record['tach_in'], 1) : '--') ?></td>
                  <td><span class="garmin-badge <?= garmin_sync_badge_class((string)($record['import_disposition'] ?? '')) ?>"><?= h((string)($record['import_disposition'] ?? '')) ?></span><br><span class="garmin-muted">FC row #<?= (int)($record['id'] ?? 0) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <?php if (($flightCircleStatus['identity_suggestions'] ?? array()) !== array()): ?>
        <div class="garmin-table-wrap" style="margin-top:14px">
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
            <h4 style="margin:0">Unmatched User Suggestions</h4>
            <div class="garmin-actions">
              <button type="button" class="secondary" data-fc-bulk-create-users>Create selected users</button>
            </div>
          </div>
          <p class="garmin-muted">Create a new IPCA.training user from the source name, or map the source name to an existing user. Once mapped, the suggestion disappears and related crew/logbook proposals receive the correct user ID.</p>
          <table class="garmin-table">
            <thead><tr><th style="width:4%"><input type="checkbox" data-fc-select-all-identities></th><th>Source Name</th><th>Parsed Name</th><th>Context</th><th>Status</th><th>Create</th><th>Map Existing</th></tr></thead>
            <tbody>
              <?php foreach (($flightCircleStatus['identity_suggestions'] ?? array()) as $identity): ?>
                <tr>
                  <td><input type="checkbox" data-fc-identity-checkbox value="<?= (int)$identity['id'] ?>"></td>
                  <td><?= h((string)$identity['source_name']) ?></td>
                  <td><?= h(trim((string)$identity['parsed_first_name'] . ' ' . (string)$identity['parsed_middle_name'] . ' ' . (string)$identity['parsed_last_name'])) ?></td>
                  <td><?= h((string)$identity['suggested_role_context']) ?></td>
                  <td><span class="garmin-badge garmin-badge-warn"><?= h((string)$identity['mapping_status']) ?></span></td>
                  <td><button type="button" class="garmin-row-button" data-fc-create-user="<?= (int)$identity['id'] ?>">Create user</button></td>
                  <td>
                    <select data-fc-existing-user="<?= (int)$identity['id'] ?>" style="max-width:240px;border:1px solid #cbd5e1;border-radius:8px;padding:5px">
                      <option value="">Select existing user...</option>
                      <?php foreach (($flightCircleStatus['existing_users'] ?? array()) as $userOption): ?>
                        <?php
                          $userDisplay = trim((string)($userOption['first_name'] ?? '') . ' ' . (string)($userOption['last_name'] ?? ''));
                          if ($userDisplay === '') {
                              $userDisplay = (string)($userOption['name'] ?? $userOption['email'] ?? ('User #' . $userOption['id']));
                          }
                        ?>
                        <option value="<?= (int)$userOption['id'] ?>"><?= h($userDisplay . ' · ' . (string)($userOption['role'] ?? '') . ' · ' . (string)($userOption['email'] ?? '')) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="button" class="garmin-row-button" data-fc-map-existing="<?= (int)$identity['id'] ?>">Map</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div class="garmin-muted" style="margin-top:8px" data-fc-identity-message></div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="garmin-card" data-processing-card>
    <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap">
      <div>
        <div class="garmin-label">Operational status</div>
        <h2 style="margin:4px 0 6px"><span class="garmin-state-badge garmin-state-<?= h((string)$processingStatus['state']) ?>" data-processing-state><?= h(str_replace('_', ' ', strtoupper((string)$processingStatus['state']))) ?></span></h2>
        <div class="garmin-muted" data-processing-message><?= h((string)$processingStatus['message']) ?></div>
      </div>
      <button type="button" class="garmin-primary-action" data-process-garmin>Process Garmin Data</button>
    </div>
    <div style="margin-top:14px">
      <div class="garmin-progress"><span data-processing-bar style="width:<?= h((string)$processingStatus['percent']) ?>%"></span><strong data-processing-percent><?= h((string)$processingStatus['percent']) ?>%</strong></div>
      <div class="garmin-muted" style="margin-top:6px" data-processing-detail>
        <?= number_format((int)$processingStatus['done']) ?> / <?= number_format((int)$processingStatus['total']) ?> processed ·
        CSV <?= number_format((int)$processingStatus['csv']['done']) ?>/<?= number_format((int)$processingStatus['csv']['total']) ?> ·
        Tracks <?= number_format((int)$processingStatus['tracks']['done']) ?>/<?= number_format((int)$processingStatus['tracks']['total']) ?> ·
        Linked CSV tracks <?= number_format((int)$processingStatus['linked_csv_tracks']['done']) ?>/<?= number_format((int)$processingStatus['linked_csv_tracks']['total']) ?> ·
        Jobs <?= number_format((int)$processingStatus['jobs']['queued']) ?> queued, <?= number_format((int)$processingStatus['jobs']['running']) ?> running, <?= number_format((int)$processingStatus['jobs']['failed']) ?> failed ·
        Updated <?= h((string)$processingStatus['updated_at']) ?>
      </div>
    </div>
    <div data-processing-review style="<?= ((int)$processingStatus['needs_review']['total'] > 0) ? 'margin-top:12px' : 'display:none;margin-top:12px' ?>">
      <div class="garmin-label">Processing attention</div>
      <div class="garmin-muted" data-processing-review-text>
        <?= number_format((int)$processingStatus['needs_review']['total']) ?> Garmin artifact(s) still need summary processing.
      </div>
    </div>
  </section>

  <section class="garmin-card">
    <h3 style="margin-top:0">Sync Agent Status</h3>
    <div class="garmin-grid">
      <div class="garmin-kv"><div class="garmin-label">Last Device Seen</div><div class="garmin-value"><?= h(garmin_sync_datetime((string)($latestToken['last_seen_at'] ?? ''))) ?></div></div>
      <div class="garmin-kv"><div class="garmin-label">Device</div><div class="garmin-value"><?= h((string)($latestToken['display_name'] ?? 'No device token yet')) ?></div></div>
      <div class="garmin-kv"><div class="garmin-label">Device Token</div><div class="garmin-value"><span class="garmin-badge <?= !empty($latestToken['is_active']) && empty($latestToken['revoked_at']) ? 'garmin-badge-ok' : 'garmin-badge-warn' ?>"><?= !empty($latestToken['is_active']) && empty($latestToken['revoked_at']) ? 'Active' : 'Inactive / revoked' ?></span></div></div>
      <div class="garmin-kv"><div class="garmin-label">Accepted Uploads</div><div class="garmin-value"><?= number_format($acceptedUploads) ?></div></div>
      <div class="garmin-kv"><div class="garmin-label">Already Existing</div><div class="garmin-value"><?= number_format($alreadyExists) ?></div></div>
      <div class="garmin-kv"><div class="garmin-label">Needs Review / Rejected</div><div class="garmin-value"><?= number_format($reviewRequired + $rejectedUploads) ?></div></div>
    </div>
  </section>

  <section class="garmin-card">
    <h3 style="margin-top:0">Garmin Track Inventory</h3>
    <div class="garmin-grid">
      <div class="garmin-kv"><div class="garmin-label">Normalized Track Artifacts</div><div class="garmin-value"><?= number_format((int)($trackSummary['total_tracks'] ?? 0)) ?></div></div>
      <div class="garmin-kv"><div class="garmin-label">Stored Payload Size</div><div class="garmin-value"><?= h(garmin_sync_bytes($trackSummary['total_bytes'] ?? 0)) ?></div></div>
      <div class="garmin-kv"><div class="garmin-label">Total Sessions</div><div class="garmin-value"><?= number_format((int)($trackSummary['total_sessions'] ?? 0)) ?></div></div>
      <div class="garmin-kv"><div class="garmin-label">Total Fields</div><div class="garmin-value"><?= number_format((int)($trackSummary['total_fields'] ?? 0)) ?></div></div>
      <div class="garmin-kv"><div class="garmin-label">Last Track Upload</div><div class="garmin-value"><?= h(garmin_sync_datetime((string)($trackSummary['last_track_at'] ?? ''))) ?></div></div>
      <div class="garmin-kv"><div class="garmin-label">CSV Summaries</div><div class="garmin-value"><?= number_format((int)($csvSummaryStats['summarized_csv_files'] ?? 0)) ?> / <?= number_format((int)($csvSummaryStats['total_csv_files'] ?? 0)) ?></div></div>
      <div class="garmin-kv"><div class="garmin-label">Summaries Missing</div><div class="garmin-value"><?= number_format((int)($csvSummaryStats['missing_summaries'] ?? 0)) ?></div></div>
      <div class="garmin-kv"><div class="garmin-label">Track Summaries</div><div class="garmin-value"><?= number_format((int)($trackSummaryStats['summarized_track_artifacts'] ?? 0)) ?> / <?= number_format((int)($trackSummaryStats['total_track_artifacts'] ?? 0)) ?></div></div>
      <div class="garmin-kv"><div class="garmin-label">Track Summaries Missing</div><div class="garmin-value"><?= number_format((int)($trackSummaryStats['missing_track_summaries'] ?? 0)) ?></div></div>
    </div>
    <?php if ($classificationSummary !== array()): ?>
      <div style="margin-top:14px" class="garmin-grid">
        <?php foreach ($classificationSummary as $row): ?>
          <div class="garmin-kv">
            <div class="garmin-label"><?= h((string)($row['classification'] ?? 'UNKNOWN')) ?></div>
            <div class="garmin-value"><?= number_format((int)($row['total'] ?? 0)) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="garmin-card">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap">
      <div>
        <h3 style="margin:0">All Garmin Imports</h3>
        <p class="garmin-muted">One operational list for Garmin CSV files from the IPCA Sync App and Historical Bulk Uploads. Use this list to compare dates, aircraft, Hobbs/Tach, duplicates, and import status.</p>
      </div>
      <div class="garmin-actions">
        <button type="button" data-import-bulk-action="process_selected_inline">Process selected</button>
        <button type="button" class="secondary" data-import-bulk-action="match_flightcircle">Enrich selected with FlightCircle</button>
        <a class="secondary" href="/admin/api/garmin_historical_backfill_report.php">Historical report</a>
      </div>
    </div>
    <?php if ($garminImportRows === array()): ?>
      <div class="garmin-empty" style="margin-top:12px">No Garmin CSV imports found yet.</div>
    <?php else: ?>
      <form class="garmin-filter" data-garmin-import-filter style="grid-template-columns:130px 120px 132px 132px 130px 130px 110px">
        <label class="garmin-filter-control"><span class="garmin-filter-label">Source</span><select name="import_source"><option value="">All</option><?php foreach ($garminImportSourceOptions as $sourceOption): ?><option value="<?= h($sourceOption) ?>"><?= h($sourceOption) ?></option><?php endforeach; ?></select></label>
        <label class="garmin-filter-control"><span class="garmin-filter-label">Tail</span><select name="import_tail"><option value="">All</option><?php foreach ($garminImportTailOptions as $tailOption): ?><option value="<?= h($tailOption) ?>"><?= h($tailOption) ?></option><?php endforeach; ?></select></label>
        <label class="garmin-filter-control"><span class="garmin-filter-label">From</span><input type="date" name="import_from"></label>
        <label class="garmin-filter-control"><span class="garmin-filter-label">To</span><input type="date" name="import_to"></label>
        <label class="garmin-filter-control"><span class="garmin-filter-label">Import state</span><select name="import_state"><option value="">All</option><option value="complete">Complete</option><option value="queued">Queued</option><option value="failed">Failed</option><option value="needs_review">Needs review</option><option value="duplicate">Duplicate</option></select></label>
        <label class="garmin-filter-control"><span class="garmin-filter-label">Duplicate</span><select name="import_duplicate"><option value="">All</option><option value="new">New only</option><option value="duplicate">Duplicates only</option></select></label>
        <div class="garmin-filter-control"><span class="garmin-filter-label">&nbsp;</span><button type="submit">Apply</button></div>
      </form>
      <div class="garmin-muted" style="margin-top:8px" data-garmin-import-count>
        Showing <?= number_format(count($garminImportRows)) ?> import(s), newest first.
      </div>
      <?php if ($garminImportHiddenAvionicsOnlyCount > 0): ?>
        <div class="garmin-muted" style="margin-top:4px">
          Hidden from this operational list: <?= number_format($garminImportHiddenAvionicsOnlyCount) ?> avionics on/off row(s) with Hobbs Out equal to Hobbs In and 0.0 Hobbs time.
        </div>
      <?php endif; ?>
      <div class="garmin-progress" data-import-bulk-progress style="display:none;margin-top:8px"><span style="width:0%"></span><strong>0%</strong></div>
      <div class="garmin-muted" style="margin-top:4px" data-import-bulk-message></div>
      <div class="garmin-flights-scroll" style="margin-top:10px;max-height:68vh">
        <table class="garmin-table">
          <thead><tr><th style="width:3%"><input type="checkbox" data-import-select-all></th><th style="width:7%">Source</th><th style="width:8%">Date</th><th style="width:6%">Tail</th><th style="width:6%">Dep</th><th style="width:6%">Arr</th><th style="width:7%">Hobbs Out</th><th style="width:7%">Hobbs In</th><th style="width:7%">Hobbs</th><th style="width:7%">Tach Out</th><th style="width:7%">Tach In</th><th style="width:7%">Tach</th><th style="width:8%">State</th><th style="width:8%">Class</th><th style="width:10%">FlightCircle</th><th style="width:12%">Crew</th><th style="width:7%">Dup</th><th style="width:12%">File</th></tr></thead>
          <tbody>
          <?php foreach ($garminImportRows as $row): ?>
            <?php
              $summary = json_decode((string)($row['summary_json'] ?? '{}'), true);
              $summary = is_array($summary) ? $summary : array();
              $sourceLabel = (string)($row['provider_name'] ?? '') === 'historical_sd_card_csv' || (string)($row['upload_source'] ?? '') === 'admin_historical_backfill'
                  ? 'Bulk Upload'
                  : 'IPCA Sync App';
              $tail = strtoupper(trim((string)($row['summary_tail_number'] ?? '')));
              if ($tail === '' || $tail === 'UNKNOWN' || $tail === 'UNKNOWN TAIL') {
                  $tail = strtoupper(trim((string)($row['aircraft_registration'] ?: $row['aircraft_ident'])));
              }
              $startUtc = (string)($row['departure_time_utc'] ?? $row['first_valid_sample_utc'] ?? $row['created_at'] ?? '');
              $dateKey = substr($startUtc, 0, 10);
              $duplicateStatus = trim((string)($row['exact_duplicate_status'] ?? ''));
              $isDuplicate = $duplicateStatus !== '' && $duplicateStatus !== 'new';
              $importState = 'complete';
              if (trim((string)($row['historical_parse_status'] ?? '')) !== '') {
                  $importState = (string)$row['historical_parse_status'];
              } elseif (trim((string)($row['derivation_status'] ?? '')) !== '') {
                  $importState = (string)$row['derivation_status'];
              } elseif (empty($row['summary_json'])) {
                  $importState = 'missing_summary';
              }
              if ($isDuplicate) {
                  $importState = 'duplicate';
              }
              $classification = trim((string)($row['historical_classification'] ?? ''));
              if ($classification === '' || ((string)($row['historical_parse_status'] ?? '') === 'queued' && $classification === 'Needs Review')) {
                  $classification = (string)($row['historical_parse_status'] ?? '') === 'queued' ? 'Not processed yet' : (string)($row['derivation_status'] ?? 'Summary');
              }
              $review = trim((string)($row['historical_review_status'] ?? ''));
              $stateClass = in_array($importState, array('complete', 'completed', 'ok'), true) ? 'garmin-badge-ok' : (in_array($importState, array('failed', 'parse_failed'), true) ? 'garmin-badge-danger' : 'garmin-badge-warn');
              $fcMatchCount = (int)($row['fc_match_count'] ?? 0);
              $fcMatchScore = $row['fc_match_score'] !== null ? (float)$row['fc_match_score'] : null;
              $fcMatchStatuses = trim((string)($row['fc_match_statuses'] ?? ''));
              $fcStagingIds = trim((string)($row['fc_staging_ids'] ?? ''));
              $fcUserText = trim((string)($row['fc_user_text'] ?? ''));
              $fcInstructorText = trim((string)($row['fc_instructor_text'] ?? ''));
              $fcReservationType = trim((string)($row['fc_reservation_type'] ?? ''));
              $fcHobbsOut = trim((string)($row['fc_hobbs_out'] ?? ''));
              $fcHobbsIn = trim((string)($row['fc_hobbs_in'] ?? ''));
              $fcBadgeClass = $fcMatchCount > 0 && $fcMatchScore !== null && $fcMatchScore >= 85 ? 'garmin-badge-ok' : ($fcMatchCount > 0 ? 'garmin-badge-warn' : '');
            ?>
            <tr data-garmin-import-row
                data-historical-file-id="<?= (int)($row['historical_file_id'] ?? 0) ?>"
                data-source="<?= h($sourceLabel) ?>"
                data-tail="<?= h($tail) ?>"
                data-date="<?= h($dateKey) ?>"
                data-sort-time="<?= h($startUtc) ?>"
                data-hobbs-out="<?= h((string)($summary['hobbs_out'] ?? '')) ?>"
                data-hobbs-in="<?= h((string)($summary['hobbs_in'] ?? '')) ?>"
                data-state="<?= h(strtolower($importState)) ?>"
                data-fc-match="<?= $fcMatchCount > 0 ? '1' : '0' ?>"
                data-duplicate="<?= $isDuplicate ? 'duplicate' : 'new' ?>">
              <td><input type="checkbox" data-import-checkbox value="<?= (int)($row['historical_file_id'] ?? 0) ?>" <?= ((int)($row['historical_file_id'] ?? 0) <= 0) ? 'disabled' : '' ?>></td>
              <td><span class="garmin-badge <?= $sourceLabel === 'Bulk Upload' ? 'garmin-badge-warn' : 'garmin-badge-ok' ?>"><?= h($sourceLabel) ?></span></td>
              <td class="garmin-compact"><?= h($dateKey !== '' ? $dateKey : '--') ?><br><span class="garmin-muted"><?= h((string)($summary['dep_time_lt'] ?? '')) ?></span></td>
              <td><?= garmin_sync_tail_pill($tail) ?></td>
              <td><?= h((string)($row['departure_airport_code'] ?? $summary['dep_airport'] ?? '--') ?: '--') ?></td>
              <td><?= h((string)($row['arrival_airport_code'] ?? $summary['arr_airport'] ?? '--') ?: '--') ?></td>
              <td data-hobbs-out-cell><?= h((string)($summary['hobbs_out'] ?? '--')) ?></td>
              <td data-hobbs-in-cell><?= h((string)($summary['hobbs_in'] ?? '--')) ?></td>
              <td><?= h((string)($summary['hobbs_time'] ?? '--')) ?></td>
              <td><?= h((string)($summary['tacho_out'] ?? '--')) ?></td>
              <td><?= h((string)($summary['tacho_in'] ?? '--')) ?></td>
              <td><?= h((string)($summary['tacho_time'] ?? '--')) ?></td>
              <td><span class="garmin-badge <?= h($stateClass) ?>"><?= h($importState) ?></span><?php if ($review !== ''): ?><br><span class="garmin-muted"><?= h($review) ?></span><?php endif; ?></td>
              <td><?= h($classification) ?></td>
              <td>
                <?php if ($fcMatchCount > 0): ?>
                  <span class="garmin-badge <?= h($fcBadgeClass) ?>"><?= h($fcMatchStatuses !== '' ? $fcMatchStatuses : 'matched') ?></span>
                  <br><span class="garmin-muted"><?= number_format($fcMatchCount) ?> candidate(s)<?= $fcMatchScore !== null ? ' · ' . number_format($fcMatchScore, 0) . '%' : '' ?></span>
                  <?php if ($fcStagingIds !== ''): ?><br><span class="garmin-muted">FC row <?= h($fcStagingIds) ?></span><?php endif; ?>
                  <?php if ($fcHobbsOut !== ''): ?><br><span class="garmin-muted">FC Hobbs <?= h($fcHobbsOut) ?><?= $fcHobbsIn !== '' ? ' → ' . h($fcHobbsIn) : '' ?></span><?php endif; ?>
                <?php else: ?>
                  <span class="garmin-muted">No FC match</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($fcUserText !== '' || $fcInstructorText !== ''): ?>
                  <strong><?= h($fcUserText !== '' ? $fcUserText : '--') ?></strong>
                  <?php if ($fcInstructorText !== ''): ?><br><span class="garmin-muted">Instr: <?= h($fcInstructorText) ?></span><?php endif; ?>
                  <?php if ($fcReservationType !== ''): ?><br><span class="garmin-muted"><?= h($fcReservationType) ?></span><?php endif; ?>
                <?php else: ?>
                  <span class="garmin-muted">--</span>
                <?php endif; ?>
              </td>
              <td><span class="garmin-badge <?= $isDuplicate ? 'garmin-badge-warn' : 'garmin-badge-ok' ?>"><?= h($duplicateStatus !== '' ? $duplicateStatus : 'new') ?></span></td>
              <td><span class="garmin-code"><?= h((string)$row['original_filename']) ?></span><br><span class="garmin-muted">CSV #<?= (int)$row['csv_file_id'] ?></span></td>
            </tr>
          <?php endforeach; ?>
            <tr data-garmin-import-empty style="display:none"><td colspan="18" class="garmin-empty">No Garmin imports match these filters.</td></tr>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <details class="garmin-card">
    <summary><strong>Technical Diagnostics</strong> <span class="garmin-muted">Raw sync artifacts, attention reasons, upload acknowledgments</span></summary>
  <section class="garmin-table-wrap" style="margin-top:14px">
    <h3 style="margin-top:0">Items Needing Attention</h3>
    <?php if ($attentionRows === array()): ?>
      <div class="garmin-empty">No recent Garmin sync-agent items need attention.</div>
    <?php else: ?>
      <table class="garmin-table">
        <thead><tr><th>Track</th><th>Reason</th><th>Upload</th><th>Telemetry</th><th>Last Seen</th></tr></thead>
        <tbody>
        <?php foreach ($attentionRows as $track): ?>
          <?php
          $classification = garmin_sync_metadata_value((string)($track['raw_metadata_json'] ?? ''), 'trackClassification');
          $status = (string)($track['upload_status'] ?? 'missing_ack');
          $reasons = array();
          if ($status === '' || $status === 'missing_ack') {
              $reasons[] = 'No upload acknowledgment';
          }
          if ($status === 'review_required') {
              $reasons[] = 'Review required';
          }
          if (str_contains(strtolower($classification), 'unknown')) {
              $reasons[] = 'Unknown classification';
          }
          if ((int)($track['field_count'] ?? 0) === 0) {
              $reasons[] = 'No telemetry fields';
          }
          ?>
          <tr>
            <td><div class="garmin-code"><?= h((string)$track['track_uuid']) ?></div><span class="garmin-muted">Entry <?= h((string)$track['garmin_entry_uuid']) ?></span></td>
            <td><?= h(implode(', ', $reasons)) ?></td>
            <td><span class="garmin-badge <?= garmin_sync_badge_class($status) ?>"><?= h($status) ?></span></td>
            <td><?= number_format((int)$track['session_count']) ?> sessions / <?= number_format((int)$track['field_count']) ?> fields<br><span class="garmin-muted"><?= h($classification === '' ? 'Classification unknown' : $classification) ?></span></td>
            <td><?= h(garmin_sync_datetime((string)($track['last_seen_at'] ?? ''))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
  </details>

  <section class="garmin-card">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap">
      <div>
        <h3 style="margin:0">Garmin Flights</h3>
        <p class="garmin-muted">Showing <?= number_format(count($flightRows)) ?> Garmin flight artifact(s). Complete avionics flights are shown by default; raw identifiers and technical evidence are in each row's detail modal.</p>
      </div>
      <form class="garmin-actions" method="post" action="/admin/api/garmin_flights_bulk_action.php" id="garmin-bulk-form">
        <input type="hidden" name="return" value="<?= h('/admin/flight_log_garmin_connection.php' . ($_SERVER['QUERY_STRING'] ? '?' . (string)$_SERVER['QUERY_STRING'] : '')) ?>">
        <input type="hidden" name="reason" value="Hidden from Garmin flight dashboard">
        <button type="submit" name="action" value="hide">Hide selected</button>
        <button class="secondary" type="submit" name="action" value="restore">Restore selected</button>
        <button class="secondary" type="submit" name="action" value="reprocess">Queue reprocess</button>
        <button class="secondary" type="submit" name="action" value="rebuild_replay">Rebuild replay</button>
      </form>
    </div>

    <form class="garmin-filter" method="get" action="/admin/flight_log_garmin_connection.php">
      <label class="garmin-filter-control"><span class="garmin-filter-label">Tail</span><select name="tail" data-filter-field="tail"><option value="">All</option><?php foreach ($tailOptions as $tailOption): ?><option value="<?= h($tailOption) ?>"<?= $filterTail === $tailOption ? ' selected' : '' ?>><?= h($tailOption) ?></option><?php endforeach; ?></select></label>
      <label class="garmin-filter-control"><span class="garmin-filter-label">From</span><input type="date" name="date_from" value="<?= h($filterDateFrom) ?>"></label>
      <label class="garmin-filter-control"><span class="garmin-filter-label">To</span><input type="date" name="date_to" value="<?= h($filterDateTo) ?>"></label>
      <label class="garmin-filter-control"><span class="garmin-filter-label">Dep AD</span><select name="dep" data-filter-field="dep"><option value="">All</option><?php foreach ($depOptions as $airportOption): ?><option value="<?= h($airportOption) ?>"<?= $filterDep === $airportOption ? ' selected' : '' ?>><?= h($airportOption) ?></option><?php endforeach; ?></select></label>
      <label class="garmin-filter-control"><span class="garmin-filter-label">Arr AD</span><select name="arr" data-filter-field="arr"><option value="">All</option><?php foreach ($arrOptions as $airportOption): ?><option value="<?= h($airportOption) ?>"<?= $filterArr === $airportOption ? ' selected' : '' ?>><?= h($airportOption) ?></option><?php endforeach; ?></select></label>
      <label class="garmin-filter-control"><span class="garmin-filter-label">Status</span><select name="status"><option value="">Any</option><?php foreach (array('Complete','Partial','GPS only','Needs review') as $option): ?><option value="<?= h($option) ?>"<?= $filterStatus === $option ? ' selected' : '' ?>><?= h($option) ?></option><?php endforeach; ?></select></label>
      <label class="garmin-filter-control"><span class="garmin-filter-label">Visibility</span><select name="show_incomplete"><option value="0">Complete only</option><option value="1"<?= $showIncomplete ? ' selected' : '' ?>>Show incomplete</option></select></label>
      <label class="garmin-filter-control"><span class="garmin-filter-label">Hidden</span><select name="show_hidden"><option value="0">Hide hidden</option><option value="1"<?= $showHidden ? ' selected' : '' ?>>Show hidden</option></select></label>
      <label class="garmin-filter-control"><span class="garmin-filter-label">New</span><select name="new_only"><option value="0">All</option><option value="1"<?= $newOnly ? ' selected' : '' ?>>New only</option></select></label>
      <div class="garmin-filter-control"><span class="garmin-filter-label">&nbsp;</span><button class="secondary" type="submit">Apply</button></div>
    </form>

    <?php if ($flightRows === array()): ?>
      <div class="garmin-empty">No Garmin flights match the current filters. Incomplete/GPS-only flights are hidden by default.</div>
    <?php else: ?>
      <div class="garmin-flights-scroll">
      <table class="garmin-table">
        <thead><tr><th style="width:3%"><input type="checkbox" data-select-all-flights></th><th style="width:7%">Flight</th><th style="width:9%">Date</th><th style="width:7%">Tail</th><th style="width:6%">Dep AD</th><th style="width:7%">Dep LT</th><th style="width:7%">Hobbs Out</th><th style="width:7%">Hobbs In</th><th style="width:7%">Hobbs Time</th><th style="width:7%">Tacho Out</th><th style="width:7%">Tacho In</th><th style="width:7%">Tacho Time</th><th style="width:6%">Arr AD</th><th style="width:7%">Arr LT</th><th style="width:8%">Status</th><th style="width:13%">Uploaded</th><th style="width:10%">Replay</th></tr></thead>
        <tbody>
        <?php foreach ($flightRows as $flight): ?>
          <?php
            $track = $flight['track'];
            $summary = $flight['summary'];
            $flightId = garmin_sync_flight_id((int)$track['id']);
            $modalId = 'garmin-flight-' . (int)$track['id'];
            $statusLabel = (string)$flight['status_label'];
            $statusClass = $statusLabel === 'Complete' ? 'garmin-badge-ok' : ($statusLabel === 'GPS only' ? 'garmin-badge-danger' : 'garmin-badge-warn');
            $qualityScore = garmin_sync_quality_score($summary, $statusLabel);
            $qualityClass = $qualityScore >= 80 ? 'good' : ($qualityScore >= 50 ? 'warn' : 'bad');
            $csvFileId = (int)($track['csv_file_id'] ?? 0);
            $replayPayload = $csvFileId > 0 && isset($replayPayloadsByCsvFileId[$csvFileId]) ? $replayPayloadsByCsvFileId[$csvFileId] : null;
            $replayReady = is_array($replayPayload) && (string)($replayPayload['build_status'] ?? '') === 'ready' && trim((string)($replayPayload['replay_key'] ?? '')) !== '';
          ?>
          <tr data-flight-row
              data-tail="<?= h(strtoupper((string)($summary['tail'] ?? ''))) ?>"
              data-dep="<?= h(strtoupper((string)($summary['dep_airport'] ?? ''))) ?>"
              data-arr="<?= h(strtoupper((string)($summary['arr_airport'] ?? ''))) ?>"
              data-date="<?= h(substr((string)($summary['start_utc'] ?? ''), 0, 10)) ?>"
              data-status="<?= h($statusLabel) ?>"
              data-new="<?= $flight['is_new'] ? '1' : '0' ?>"
              data-hidden="<?= trim((string)($track['hidden_at'] ?? '')) !== '' ? '1' : '0' ?>">
            <td><input form="garmin-bulk-form" name="track_artifact_ids[]" type="checkbox" data-flight-checkbox value="<?= (int)$track['id'] ?>"></td>
            <td><button class="garmin-row-button" type="button" data-modal-open="<?= h($modalId) ?>"><?= h($flightId) ?></button><?php if ($flight['is_new']): ?> <span class="garmin-badge garmin-badge-new">New</span><?php endif; ?></td>
            <td class="garmin-compact"><?= h((string)($summary['date_label'] ?? garmin_sync_date_label((string)($summary['start_utc'] ?? '')))) ?></td>
            <td class="garmin-compact"><?= garmin_sync_tail_pill((string)($summary['tail'] ?? '')) ?></td>
            <td class="garmin-compact"><?= h((string)($summary['dep_airport'] ?? '--')) ?></td>
            <td class="garmin-compact"><?= h((string)($summary['dep_time_lt'] ?? '--')) ?></td>
            <td class="garmin-compact"><?= h((string)($summary['hobbs_out'] ?? '--')) ?></td>
            <td class="garmin-compact"><?= h((string)($summary['hobbs_in'] ?? '--')) ?></td>
            <td class="garmin-compact"><?= h((string)($summary['hobbs_time'] ?? '--')) ?></td>
            <td class="garmin-compact"><?= h((string)($summary['tacho_out'] ?? '--')) ?></td>
            <td class="garmin-compact"><?= h((string)($summary['tacho_in'] ?? '--')) ?></td>
            <td class="garmin-compact"><?= h((string)($summary['tacho_time'] ?? '--')) ?></td>
            <td class="garmin-compact"><?= h((string)($summary['arr_airport'] ?? '--')) ?></td>
            <td class="garmin-compact"><?= h((string)($summary['arr_time_lt'] ?? '--')) ?></td>
            <td><span class="garmin-badge <?= h($statusClass) ?>"><?= h($statusLabel) ?></span></td>
            <td><span class="garmin-upload-pill">IPCA</span><br><span class="garmin-muted"><?= h(garmin_sync_datetime((string)$flight['uploaded_at'])) ?></span></td>
            <td>
              <?php if ($csvFileId > 0 && $statusLabel === 'Complete'): ?>
                <div class="garmin-actions" style="gap:5px">
                  <?php if ($replayReady): ?>
                    <a class="garmin-row-button" href="/admin/cockpit_recorder_replay.php?standalone=<?= h((string)$replayPayload['replay_key']) ?>">Open</a>
                  <?php endif; ?>
                  <form method="post" action="/admin/api/cockpit_recorder_garmin_replay_action.php">
                    <input type="hidden" name="csv_file_id" value="<?= $csvFileId ?>">
                    <?php if ($replayReady): ?><input type="hidden" name="force" value="1"><?php endif; ?>
                    <button class="garmin-row-button" type="submit"><?= $replayReady ? 'Rebuild' : 'Build' ?></button>
                  </form>
                </div>
              <?php elseif ($csvFileId > 0): ?>
                <span class="garmin-muted">Complete flight required</span>
              <?php else: ?>
                <span class="garmin-muted">No CSV</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php ob_start(); ?>
            <div class="garmin-modal-backdrop" id="<?= h($modalId) ?>">
              <div class="garmin-modal">
                <div class="garmin-modal-header">
                  <div>
                    <div class="garmin-label">Garmin Flight</div>
                    <h3 style="margin:2px 0"><?= h($flightId) ?> · <?= h((string)($summary['tail'] ?? 'Unknown tail')) ?></h3>
                    <div class="garmin-muted"><?= h(garmin_sync_modal_date_label((string)($summary['start_utc'] ?? ''))) ?> · <?= h((string)($summary['dep_airport'] ?? '--')) ?> <?= h((string)($summary['dep_time_lt'] ?? '--')) ?> LT - <?= h((string)($summary['arr_airport'] ?? '--')) ?> <?= h((string)($summary['arr_time_lt'] ?? '--')) ?> LT</div>
                  </div>
                  <button class="garmin-row-button" type="button" data-modal-close>Close</button>
                </div>
                <div class="garmin-modal-body">
                  <div class="garmin-flight-hero">
                    <div class="garmin-flight-card">
                      <div class="garmin-label">Date</div>
                      <div class="garmin-flight-big"><?= h(garmin_sync_modal_date_label((string)($summary['start_utc'] ?? ''))) ?></div>
                    </div>
                    <div class="garmin-flight-card">
                      <div class="garmin-label">Departure Airport</div>
                      <div class="garmin-flight-big"><?= h(garmin_sync_airport_label((string)($summary['dep_airport'] ?? '--'))) ?></div>
                      <div class="garmin-muted">Departure Time <?= h((string)($summary['dep_time_lt'] ?? '--')) ?> Local</div>
                    </div>
                    <div class="garmin-flight-card garmin-flight-center">
                      <div class="garmin-label">Enroute</div>
                      <div class="garmin-flight-duration"><?= h((string)($summary['elapsed_time'] ?? '--')) ?></div>
                    </div>
                    <div class="garmin-flight-card">
                      <div class="garmin-label">Arrival Airport</div>
                      <div class="garmin-flight-big"><?= h(garmin_sync_airport_label((string)($summary['arr_airport'] ?? '--'))) ?></div>
                      <div class="garmin-muted">Arrival Time <?= h((string)($summary['arr_time_lt'] ?? '--')) ?> Local</div>
                    </div>
                  </div>
                  <div class="garmin-counter-grid">
                    <div class="garmin-counter-card">
                      <div class="garmin-label">Hobbs</div>
                      <div class="garmin-counter-row"><span>Out</span><strong><?= h((string)($summary['hobbs_out'] ?? '--')) ?></strong></div>
                      <div class="garmin-counter-row"><span>In</span><strong><?= h((string)($summary['hobbs_in'] ?? '--')) ?></strong></div>
                      <div class="garmin-counter-total">Total Hobbs <?= h((string)($summary['hobbs_time'] ?? '--')) ?></div>
                    </div>
                    <div class="garmin-counter-card">
                      <div class="garmin-label">Tacho</div>
                      <div class="garmin-counter-row"><span>Out</span><strong><?= h((string)($summary['tacho_out'] ?? '--')) ?></strong></div>
                      <div class="garmin-counter-row"><span>In</span><strong><?= h((string)($summary['tacho_in'] ?? '--')) ?></strong></div>
                      <div class="garmin-counter-total">Total Tacho <?= h((string)($summary['tacho_time'] ?? '--')) ?></div>
                    </div>
                    <div class="garmin-counter-card">
                      <div class="garmin-label">At A Glance</div>
                      <div class="garmin-pill-row">
                        <?= garmin_sync_tail_pill((string)($summary['tail'] ?? '')) ?>
                        <span class="garmin-pill garmin-pill-<?= h(strtolower($qualityClass)) ?>"><?= h($statusLabel) ?></span>
                        <span class="garmin-pill">Rows <?= number_format((int)($summary['row_count'] ?? 0)) ?></span>
                      </div>
                    </div>
                  </div>
                  <div class="garmin-source-grid">
                    <div class="garmin-source-panel">
                      <div class="garmin-label">Source Data</div>
                      <div class="garmin-source-row"><span>Full Track UUID</span><code><?= h((string)$track['track_uuid']) ?></code></div>
                      <div class="garmin-source-row"><span>Entry UUID</span><code><?= h((string)$track['garmin_entry_uuid']) ?></code></div>
                      <div class="garmin-source-row"><span>Telemetry</span><strong><?= number_format((int)$track['session_count']) ?> sessions · <?= number_format((int)$track['field_count']) ?> fields · <?= h(garmin_sync_bytes($track['file_size_bytes'] ?? 0)) ?></strong></div>
                    </div>
                    <div class="garmin-source-panel">
                      <div class="garmin-label">Classification</div>
                      <div class="garmin-pill-row">
                        <?php foreach (garmin_sync_classification_pills((string)$flight['classification']) as $pill): ?>
                          <span class="garmin-pill garmin-pill-<?= h($pill['class']) ?>"><?= h($pill['label']) ?></span>
                        <?php endforeach; ?>
                      </div>
                      <div class="garmin-label" style="margin-top:12px">Source Quality</div>
                      <div class="garmin-quality-bar"><span class="garmin-quality-<?= h($qualityClass) ?>" style="width:<?= (int)$qualityScore ?>%"></span></div>
                      <div class="garmin-muted"><?= h((string)($summary['avionics_family'] ?? '--')) ?> · <?= h((string)($summary['default_quality'] ?? '--')) ?> · counters <?= !empty($summary['provides_counter_headers']) ? 'yes' : 'no' ?></div>
                    </div>
                  </div>
                  <div class="garmin-map-panel">
                    <div class="garmin-label">GPS Track Log</div>
                    <div class="garmin-track-map" data-garmin-map data-raw-url="/admin/api/garmin_artifact_raw.php?track_artifact_id=<?= (int)$track['id'] ?>"></div>
                  </div>
                  <div><a href="/admin/api/garmin_artifact_raw.php?track_artifact_id=<?= (int)$track['id'] ?>" target="_blank" rel="noopener">Open full raw Garmin normalized JSON</a></div>
                  <pre class="garmin-raw-block"><?= h(json_encode(array('summary' => $summary, 'source_names' => $flight['source_names'], 'sha256' => $track['sha256'] ?? ''), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                </div>
              </div>
            </div>
          <?php $flightModals[] = ob_get_clean(); ?>
        <?php endforeach; ?>
          <tr data-filter-empty style="display:none"><td colspan="17" class="garmin-empty">No Garmin flights match the current filters. Adjust the filters or choose Show incomplete.</td></tr>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </section>
  <?php foreach ($flightModals as $modalHtml): ?>
    <?= $modalHtml ?>
  <?php endforeach; ?>

  <details class="garmin-card">
    <summary><strong>Recent Upload Acknowledgments</strong> <span class="garmin-muted">technical sync log</span></summary>
  <section class="garmin-table-wrap" style="margin-top:14px">
    <?php if ($recentAcknowledgments === array()): ?>
      <div class="garmin-empty">No sync-agent upload acknowledgments yet.</div>
    <?php else: ?>
      <table class="garmin-table">
        <thead><tr><th>When</th><th>Status</th><th>Device</th><th>Provider</th><th>Entry</th><th>Source / Track</th><th>SHA-256</th></tr></thead>
        <tbody>
        <?php foreach ($recentAcknowledgments as $ack): ?>
          <tr>
            <td><?= h(garmin_sync_datetime((string)($ack['created_at'] ?? ''))) ?></td>
            <td><span class="garmin-badge <?= garmin_sync_badge_class((string)($ack['status'] ?? '')) ?>"><?= h((string)($ack['status'] ?? '')) ?></span></td>
            <td><?= h((string)($ack['device_name'] ?? 'Unknown device')) ?></td>
            <td><?= h((string)($ack['provider_name'] ?? '')) ?></td>
            <td><div class="garmin-code"><?= h((string)($ack['garmin_entry_uuid'] ?? '')) ?></div></td>
            <td><div class="garmin-code"><?= h((string)($ack['flight_data_log_uuid'] ?? '')) ?></div></td>
            <td><div class="garmin-code"><?= h(substr((string)($ack['sha256'] ?? ''), 0, 16)) ?>...</div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
  </details>

  <section class="garmin-card">
    <h3 style="margin-top:0">Sync Agent Devices</h3>
    <?php if ($tokens === array()): ?>
      <div class="garmin-empty">No sync-agent device tokens have checked in yet.</div>
    <?php else: ?>
      <div class="garmin-grid">
        <?php foreach ($tokens as $token): ?>
          <div class="garmin-kv">
            <div class="garmin-label"><?= h((string)($token['display_name'] ?? 'Device')) ?></div>
            <div class="garmin-value"><span class="garmin-badge <?= !empty($token['is_active']) && empty($token['revoked_at']) ? 'garmin-badge-ok' : 'garmin-badge-danger' ?>"><?= !empty($token['is_active']) && empty($token['revoked_at']) ? 'Active' : 'Inactive' ?></span></div>
            <div class="garmin-muted">Last seen <?= h(garmin_sync_datetime((string)($token['last_seen_at'] ?? ''))) ?></div>
            <div class="garmin-code"><?= h((string)($token['token_uuid'] ?? '')) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function(){
  const selectAll = document.querySelector('[data-select-all-flights]');
  const boxes = Array.from(document.querySelectorAll('[data-flight-checkbox]'));
  const filterForm = document.querySelector('.garmin-filter');
  const rows = Array.from(document.querySelectorAll('[data-flight-row]'));
  const emptyRow = document.querySelector('[data-filter-empty]');
  const importFilterForm = document.querySelector('[data-garmin-import-filter]');
  const importRows = Array.from(document.querySelectorAll('[data-garmin-import-row]'));
  const importEmptyRow = document.querySelector('[data-garmin-import-empty]');
  const importCount = document.querySelector('[data-garmin-import-count]');
  const importBulkMessage = document.querySelector('[data-import-bulk-message]');
  const importBulkProgress = document.querySelector('[data-import-bulk-progress]');
  const importSelectAll = document.querySelector('[data-import-select-all]');
  const processButton = document.querySelector('[data-process-garmin]');
  const processingState = document.querySelector('[data-processing-state]');
  const processingMessage = document.querySelector('[data-processing-message]');
  const processingBar = document.querySelector('[data-processing-bar]');
  const processingPercent = document.querySelector('[data-processing-percent]');
  const processingDetail = document.querySelector('[data-processing-detail]');
  const processingReview = document.querySelector('[data-processing-review]');
  const processingReviewText = document.querySelector('[data-processing-review-text]');
  const historicalUploadForm = document.querySelector('[data-historical-garmin-upload]');
  const historicalUploadButton = document.querySelector('[data-historical-upload-button]');
  const historicalUploadProgress = document.querySelector('[data-historical-upload-progress]');
  const historicalBackendProgress = document.querySelector('[data-historical-backend-progress]');
  const historicalUploadMessage = document.querySelector('[data-historical-upload-message]');
  const historicalUploadSummary = document.querySelector('[data-historical-upload-summary]');
  const historicalSelectedCount = document.querySelector('[data-historical-selected-count]');
  const historicalSelectedSize = document.querySelector('[data-historical-selected-size]');
  const historicalBatchLabel = document.querySelector('[data-historical-batch-label]');
  const historicalBackendState = document.querySelector('[data-historical-backend-state]');
  const processHistoricalQueuedButton = document.querySelector('[data-process-historical-queued]');
  const processHistoricalMessage = document.querySelector('[data-process-historical-message]');
  const historicalBulkMessage = document.querySelector('[data-historical-bulk-message]');
  const selectAllHistorical = document.querySelector('[data-select-all-historical]');
  const historicalHumanState = document.querySelector('[data-historical-human-state]');
  const historicalPrimaryAction = document.querySelector('[data-historical-primary-action]');
  const historicalMainProgress = document.querySelector('[data-historical-main-progress]');
  const historicalTotal = document.querySelector('[data-historical-total]');
  const historicalQueued = document.querySelector('[data-historical-queued]');
  const historicalDone = document.querySelector('[data-historical-done]');
  const historicalDuplicates = document.querySelector('[data-historical-duplicates]');
  const historicalFailed = document.querySelector('[data-historical-failed]');
  const historicalNeedsReview = document.querySelector('[data-historical-needs-review]');
  const fcIdentityMessage = document.querySelector('[data-fc-identity-message]');
  const renderedMaps = new WeakMap();
  let processingRunning = false;
  function current(name) {
    const field = filterForm ? filterForm.querySelector('[name="' + name + '"]') : null;
    return field ? String(field.value || '').toUpperCase() : '';
  }
  function applyInstantFilters() {
    const tail = current('tail');
    const dep = current('dep');
    const arr = current('arr');
    const dateFrom = filterForm ? String((filterForm.querySelector('[name="date_from"]') || {}).value || '') : '';
    const dateTo = filterForm ? String((filterForm.querySelector('[name="date_to"]') || {}).value || '') : '';
    const status = filterForm ? String((filterForm.querySelector('[name="status"]') || {}).value || '') : '';
    const showIncomplete = filterForm ? String((filterForm.querySelector('[name="show_incomplete"]') || {}).value || '0') === '1' : false;
    const showHidden = filterForm ? String((filterForm.querySelector('[name="show_hidden"]') || {}).value || '0') === '1' : false;
    const newOnly = filterForm ? String((filterForm.querySelector('[name="new_only"]') || {}).value || '0') === '1' : false;
    let visibleCount = 0;
    rows.forEach(row => {
      let visible = true;
      if (tail && row.dataset.tail !== tail) visible = false;
      if (dep && row.dataset.dep !== dep) visible = false;
      if (arr && row.dataset.arr !== arr) visible = false;
      if (dateFrom && row.dataset.date && row.dataset.date < dateFrom) visible = false;
      if (dateTo && row.dataset.date && row.dataset.date > dateTo) visible = false;
      if (status && row.dataset.status !== status) visible = false;
      if (!showIncomplete && row.dataset.status !== 'Complete') visible = false;
      if (!showHidden && row.dataset.hidden === '1') visible = false;
      if (newOnly && row.dataset.new !== '1') visible = false;
      row.style.display = visible ? '' : 'none';
      if (visible) visibleCount++;
      const box = row.querySelector('[data-flight-checkbox]');
      if (!visible && box) box.checked = false;
    });
    if (emptyRow) emptyRow.style.display = visibleCount === 0 ? '' : 'none';
  }
  if (filterForm) {
    filterForm.addEventListener('submit', event => {
      event.preventDefault();
      applyInstantFilters();
    });
    filterForm.querySelectorAll('input,select').forEach(field => field.addEventListener('input', applyInstantFilters));
    filterForm.querySelectorAll('select').forEach(field => field.addEventListener('change', applyInstantFilters));
    applyInstantFilters();
  }
  function importFilterValue(name) {
    const field = importFilterForm ? importFilterForm.querySelector('[name="' + name + '"]') : null;
    return field ? String(field.value || '') : '';
  }
  function applyGarminImportFilters() {
    const source = importFilterValue('import_source');
    const tail = importFilterValue('import_tail').toUpperCase();
    const dateFrom = importFilterValue('import_from');
    const dateTo = importFilterValue('import_to');
    const state = importFilterValue('import_state').toLowerCase();
    const duplicate = importFilterValue('import_duplicate');
    let visibleCount = 0;
    let visibleMatchedCount = 0;
    const visibleRows = [];
    importRows.forEach(row => {
      row.querySelectorAll('[data-hobbs-out-cell],[data-hobbs-in-cell]').forEach(cell => {
        cell.style.color = '';
        cell.style.fontWeight = '';
        cell.title = '';
      });
      let visible = true;
      const rowState = String(row.dataset.state || '').toLowerCase();
      if (source && row.dataset.source !== source) visible = false;
      if (tail && row.dataset.tail !== tail) visible = false;
      if (dateFrom && row.dataset.date && row.dataset.date < dateFrom) visible = false;
      if (dateTo && row.dataset.date && row.dataset.date > dateTo) visible = false;
      if (duplicate && row.dataset.duplicate !== duplicate) visible = false;
      if (state) {
        if (state === 'complete') {
          visible = visible && ['complete', 'completed', 'ok'].includes(rowState);
        } else if (state === 'needs_review') {
          visible = visible && row.textContent.toLowerCase().includes('needs_review');
        } else {
          visible = visible && rowState.includes(state);
        }
      }
      row.style.display = visible ? '' : 'none';
      if (visible) {
        visibleCount++;
        if (row.dataset.fcMatch === '1') visibleMatchedCount++;
        visibleRows.push(row);
      }
    });
    if (tail && visibleRows.length > 1) {
      const chronological = visibleRows.slice().sort((a, b) => String(a.dataset.sortTime || '').localeCompare(String(b.dataset.sortTime || '')));
      for (let i = 1; i < chronological.length; i++) {
        const prev = chronological[i - 1];
        const curr = chronological[i];
        const prevIn = parseFloat(String(prev.dataset.hobbsIn || '').replace(/[^0-9.-]+/g, ''));
        const currOut = parseFloat(String(curr.dataset.hobbsOut || '').replace(/[^0-9.-]+/g, ''));
        if (Number.isFinite(prevIn) && Number.isFinite(currOut) && Math.abs(currOut - prevIn) > 0.11) {
          const cell = curr.querySelector('[data-hobbs-out-cell]');
          if (cell) {
            cell.style.color = '#b91c1c';
            cell.style.fontWeight = '900';
            cell.title = 'Hobbs continuity gap: previous Hobbs In ' + prevIn.toFixed(1) + ', this Hobbs Out ' + currOut.toFixed(1);
          }
        }
      }
    }
    if (importEmptyRow) importEmptyRow.style.display = visibleCount === 0 ? '' : 'none';
    if (importCount) importCount.textContent = 'Showing ' + visibleCount.toLocaleString() + ' of ' + importRows.length.toLocaleString() + ' import(s), newest first. FlightCircle matched: ' + visibleMatchedCount.toLocaleString() + '.';
  }
  if (importFilterForm) {
    importFilterForm.addEventListener('submit', event => {
      event.preventDefault();
      applyGarminImportFilters();
    });
    importFilterForm.querySelectorAll('input,select').forEach(field => {
      field.addEventListener('input', applyGarminImportFilters);
      field.addEventListener('change', applyGarminImportFilters);
    });
    applyGarminImportFilters();
  }
  function importCheckboxes() {
    return Array.from(document.querySelectorAll('[data-import-checkbox]:not(:disabled)'));
  }
  if (importSelectAll) {
    importSelectAll.addEventListener('change', () => importCheckboxes().forEach(box => {
      const row = box.closest('[data-garmin-import-row]');
      if (!row || row.style.display !== 'none') box.checked = importSelectAll.checked;
    }));
  }
  function setImportBulkProgress(done, total, message) {
    const percent = total > 0 ? Math.round((done / total) * 100) : 0;
    if (importBulkProgress) {
      importBulkProgress.style.display = 'block';
      const bar = importBulkProgress.querySelector('span');
      const label = importBulkProgress.querySelector('strong');
      if (bar) bar.style.width = percent + '%';
      if (label) label.textContent = percent + '%';
    }
    if (importBulkMessage && message) importBulkMessage.textContent = message;
  }
  function chunks(values, size) {
    const out = [];
    for (let i = 0; i < values.length; i += size) out.push(values.slice(i, i + size));
    return out;
  }
  document.querySelectorAll('[data-import-bulk-action]').forEach(button => {
    button.addEventListener('click', async () => {
      const action = button.getAttribute('data-import-bulk-action') || '';
      const ids = importCheckboxes().filter(box => box.checked).map(box => Number(box.value || 0)).filter(Boolean);
      if (ids.length === 0) {
        if (importBulkMessage) importBulkMessage.textContent = 'Select at least one bulk-upload row first.';
        return;
      }
      button.disabled = true;
      setImportBulkProgress(0, ids.length, action === 'match_flightcircle' ? 'Running FlightCircle enrichment/matching...' : 'Processing selected Garmin files in small chunks...');
      try {
        let refreshDelayMs = 1000;
        if (action === 'match_flightcircle') {
          const result = await postHistoricalAction(action, { backfill_file_ids: ids });
          setImportBulkProgress(ids.length, ids.length, 'FlightCircle matching complete.');
          if (importBulkMessage) {
            const diagnostics = Array.isArray(result.no_match_diagnostics) ? result.no_match_diagnostics.slice(0, 3) : [];
            if (diagnostics.length > 0) refreshDelayMs = 6000;
            const diagnosticText = diagnostics.length > 0
              ? ' Examples: ' + diagnostics.map(item => 'Garmin ' + String(item.tail || '--') + ' Hobbs ' + String(item.hobbs_out || '--') + ': ' + String(item.reason || 'no match')).join(' | ')
              : '';
            importBulkMessage.textContent = 'FlightCircle matching complete: scanned ' + Number(result.scanned || 0).toLocaleString() + ' selected Garmin row(s), created ' + Number(result.created || 0).toLocaleString() + ' outcome(s), ambiguous ' + Number(result.ambiguous || 0).toLocaleString() + '.' + diagnosticText + ' Refreshing...';
          }
        } else {
          let processed = 0;
          let failed = 0;
          let skipped = 0;
          let matchCreated = 0;
          let completedIds = 0;
          const idChunks = chunks(ids, 10);
          for (const idChunk of idChunks) {
            const result = await postHistoricalAction(action, { backfill_file_ids: idChunk });
            processed += Number(result.processed || 0);
            failed += Number(result.failed || 0);
            skipped += Number(result.skipped || 0);
            matchCreated += Number(result.match?.created || 0);
            completedIds += idChunk.length;
            setImportBulkProgress(completedIds, ids.length, 'Processed ' + completedIds.toLocaleString() + ' / ' + ids.length.toLocaleString() + ' selected files. Success ' + processed.toLocaleString() + ', failed ' + failed.toLocaleString() + ', skipped ' + skipped.toLocaleString() + '.');
            await new Promise(resolve => setTimeout(resolve, 150));
          }
          if (importBulkMessage) importBulkMessage.textContent = 'Processed ' + processed.toLocaleString()
            + ', failed ' + failed.toLocaleString()
            + ', skipped duplicates ' + skipped.toLocaleString()
            + '. FlightCircle candidates created ' + matchCreated.toLocaleString() + '. Refreshing...';
        }
        setTimeout(() => window.location.reload(), refreshDelayMs);
      } catch (error) {
        if (importBulkMessage) importBulkMessage.textContent = 'Bulk action failed: ' + error.message;
        button.disabled = false;
      }
    });
  });
  if (selectAll) {
    selectAll.addEventListener('change', () => boxes.forEach(box => {
      const row = box.closest('[data-flight-row]');
      if (!row || row.style.display !== 'none') box.checked = selectAll.checked;
    }));
  }
  function historicalCheckboxes() {
    return Array.from(document.querySelectorAll('[data-historical-file-checkbox]'));
  }
  if (selectAllHistorical) {
    selectAllHistorical.addEventListener('change', () => historicalCheckboxes().forEach(box => {
      box.checked = selectAllHistorical.checked;
    }));
  }
  function bytesLabel(bytes) {
    const value = Number(bytes || 0);
    if (value >= 1024 * 1024 * 1024) return (value / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
    if (value >= 1024 * 1024) return (value / (1024 * 1024)).toFixed(1) + ' MB';
    if (value >= 1024) return (value / 1024).toFixed(1) + ' KB';
    return value.toLocaleString() + ' B';
  }
  function selectedHistoricalFiles() {
    if (!historicalUploadForm) return [];
    const fileInputs = Array.from(historicalUploadForm.querySelectorAll('input[type="file"][name="garmin_csv_files[]"]'));
    const byKey = new Map();
    fileInputs.flatMap(input => Array.from(input.files || [])).forEach(file => {
      byKey.set((file.webkitRelativePath || file.name) + ':' + file.size + ':' + file.lastModified, file);
    });
    return Array.from(byKey.values());
  }
  function renderHistoricalSelection() {
    const files = selectedHistoricalFiles();
    const totalBytes = files.reduce((sum, file) => sum + Number(file.size || 0), 0);
    if (historicalUploadSummary) historicalUploadSummary.style.display = files.length > 0 ? 'grid' : 'none';
    if (historicalSelectedCount) historicalSelectedCount.textContent = files.length.toLocaleString();
    if (historicalSelectedSize) historicalSelectedSize.textContent = bytesLabel(totalBytes);
    if (historicalUploadMessage && files.length > 0) {
      historicalUploadMessage.textContent = files.length.toLocaleString() + ' file(s) selected. Upload will run one file at a time to avoid server request-size limits.';
    }
  }
  function setHistoricalUploadProgress(done, total, message) {
    if (!historicalUploadProgress) return;
    const percent = total > 0 ? Math.round((done / total) * 100) : 0;
    historicalUploadProgress.style.display = 'block';
    const bar = historicalUploadProgress.querySelector('span');
    const label = historicalUploadProgress.querySelector('strong');
    if (bar) bar.style.width = percent + '%';
    if (label) label.textContent = percent + '%';
    if (historicalUploadMessage) historicalUploadMessage.textContent = message || (done + ' / ' + total + ' uploaded');
  }
  function historicalHumanStatus(progress) {
    const total = Number(progress?.total || 0);
    const queued = Number(progress?.queued || 0);
    const remaining = Number(progress?.remaining || 0);
    const failed = Number(progress?.failed || 0);
    const review = Number(progress?.needs_review || 0);
    if (total === 0) return ['Waiting for upload', 'Select Garmin CSV files or a folder, then upload.'];
    if (queued > 0) return ['Uploaded, waiting for processing', 'Click Process next 25 queued files now, or run the historical_backfill worker.'];
    if (remaining > 0) return ['Processing in background', 'Wait for the worker or continue processing chunks from this page.'];
    if (failed > 0) return ['Processing complete with failures', 'Review failed rows in the table and reprocess selected files if needed.'];
    if (review > 0) return ['Ready for review', 'Review rows marked Needs Review and use the table actions.'];
    return ['Processing complete', 'No action needed here unless you want to inspect or reprocess selected rows.'];
  }
  function updateHistoricalMainStatus(progress) {
    const percent = Number(progress?.percent || 0);
    const [state, action] = historicalHumanStatus(progress || {});
    if (historicalHumanState) historicalHumanState.textContent = state;
    if (historicalPrimaryAction) historicalPrimaryAction.textContent = action;
    if (historicalTotal) historicalTotal.textContent = Number(progress?.total || 0).toLocaleString();
    if (historicalQueued) historicalQueued.textContent = Number(progress?.queued || 0).toLocaleString();
    if (historicalDone) historicalDone.textContent = Number(progress?.done || 0).toLocaleString();
    if (historicalDuplicates) historicalDuplicates.textContent = Number(progress?.duplicates || 0).toLocaleString();
    if (historicalFailed) historicalFailed.textContent = Number(progress?.failed || 0).toLocaleString();
    if (historicalNeedsReview) historicalNeedsReview.textContent = Number(progress?.needs_review || 0).toLocaleString();
    if (historicalMainProgress) {
      const bar = historicalMainProgress.querySelector('span');
      const label = historicalMainProgress.querySelector('strong');
      if (bar) bar.style.width = percent + '%';
      if (label) label.textContent = percent + '%';
    }
  }
  function setHistoricalBackendProgress(progress, message) {
    updateHistoricalMainStatus(progress || {});
    if (!historicalBackendProgress) return;
    const percent = Number(progress?.percent || 0);
    historicalBackendProgress.style.display = 'block';
    const bar = historicalBackendProgress.querySelector('span');
    const label = historicalBackendProgress.querySelector('strong');
    if (bar) bar.style.width = percent + '%';
    if (label) label.textContent = percent + '%';
    if (historicalBackendState) historicalBackendState.textContent = String(progress?.state || 'waiting').replace(/_/g, ' ');
    if (historicalUploadMessage && message) historicalUploadMessage.textContent = message;
  }
  async function pollHistoricalBackend(batchId, stopWhenComplete) {
    if (!batchId) return;
    for (let i = 0; i < 240; i++) {
      const response = await fetch('/admin/api/garmin_historical_backfill_status.php?batch_id=' + encodeURIComponent(String(batchId)), { credentials: 'same-origin' });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || !payload.ok) throw new Error(payload.error || ('Historical status returned HTTP ' + response.status));
      const progress = payload.status?.progress || {};
      const statuses = payload.status?.file_statuses || {};
      const classes = payload.status?.segment_classifications || {};
      const classText = Object.keys(classes).length ? ' · classes: ' + Object.entries(classes).map(([k, v]) => k + ' ' + v).join(', ') : '';
      setHistoricalBackendProgress(progress, [
        'Backend batch #' + batchId,
        'done ' + Number(progress.done || 0).toLocaleString() + '/' + Number(progress.total || 0).toLocaleString(),
        'queued ' + Number(progress.queued || 0).toLocaleString(),
        'failed ' + Number(progress.failed || 0).toLocaleString(),
        'needs review ' + Number(progress.needs_review || 0).toLocaleString(),
        Object.entries(statuses).map(([k, v]) => k + ' ' + v).join(', ') + classText
      ].filter(Boolean).join(' · '));
      if (stopWhenComplete && Number(progress.total || 0) > 0 && Number(progress.remaining || 0) === 0) return;
      await new Promise(resolve => setTimeout(resolve, 2500));
    }
  }
  async function postHistoricalUpload(body) {
    body.append('format', 'json');
    const response = await fetch('/admin/api/garmin_historical_backfill_upload.php', {
      method: 'POST',
      credentials: 'same-origin',
      body
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload.ok) {
      throw new Error(payload.error || ('Upload returned HTTP ' + response.status));
    }
    return payload;
  }
  async function postHistoricalAction(action, extra) {
    const body = new FormData();
    body.append('action', action);
    Object.entries(extra || {}).forEach(([key, value]) => {
      if (Array.isArray(value)) {
        value.forEach(item => body.append(key + '[]', String(item)));
      } else {
        body.append(key, String(value));
      }
    });
    const response = await fetch('/admin/api/garmin_historical_backfill_action.php', {
      method: 'POST',
      credentials: 'same-origin',
      body
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload.ok) {
      throw new Error(payload.error || ('Historical action returned HTTP ' + response.status));
    }
    return payload;
  }
  async function processHistoricalQueued() {
    if (!processHistoricalQueuedButton) return;
    const batchId = Number(processHistoricalQueuedButton.getAttribute('data-batch-id') || 0);
    processHistoricalQueuedButton.disabled = true;
    processHistoricalQueuedButton.textContent = 'Processing 25 files...';
    try {
      const result = await postHistoricalAction('process_queued_inline', { batch_id: batchId, limit: 25 });
      if (processHistoricalMessage) {
        processHistoricalMessage.textContent = 'Processed ' + Number(result.processed || 0).toLocaleString()
          + ', failed ' + Number(result.failed || 0).toLocaleString()
          + ', remaining ' + Number(result.remaining || 0).toLocaleString() + '.';
      }
      if (result.status?.progress) setHistoricalBackendProgress(result.status.progress, processHistoricalMessage ? processHistoricalMessage.textContent : '');
      if (Number(result.remaining || 0) > 0) {
        processHistoricalQueuedButton.disabled = false;
        processHistoricalQueuedButton.textContent = 'Process next 25 queued files now';
      } else {
        processHistoricalQueuedButton.textContent = 'Processing complete. Refreshing...';
        setTimeout(() => window.location.reload(), 900);
      }
    } catch (error) {
      if (processHistoricalMessage) processHistoricalMessage.textContent = 'Processing failed: ' + error.message;
      processHistoricalQueuedButton.disabled = false;
      processHistoricalQueuedButton.textContent = 'Retry processing queued files';
    }
  }
  if (processHistoricalQueuedButton) {
    processHistoricalQueuedButton.addEventListener('click', processHistoricalQueued);
  }
  document.querySelectorAll('[data-historical-bulk-action]').forEach(button => {
    button.addEventListener('click', async () => {
      const ids = historicalCheckboxes().filter(box => box.checked).map(box => box.value);
      if (ids.length === 0) {
        if (historicalBulkMessage) historicalBulkMessage.textContent = 'Select at least one row first.';
        return;
      }
      button.disabled = true;
      try {
        const result = await postHistoricalAction(button.getAttribute('data-historical-bulk-action') || '', { backfill_file_ids: ids });
        if (historicalBulkMessage) historicalBulkMessage.textContent = 'Updated ' + Number(result.changed || 0).toLocaleString() + ', queued ' + Number(result.queued || 0).toLocaleString() + '. Refreshing...';
        setTimeout(() => window.location.reload(), 900);
      } catch (error) {
        if (historicalBulkMessage) historicalBulkMessage.textContent = 'Action failed: ' + error.message;
        button.disabled = false;
      }
    });
  });
  async function postFlightCircleIdentity(action, mappingId, userId) {
    const body = new FormData();
    body.append('action', action);
    if (Array.isArray(mappingId)) {
      mappingId.forEach(id => body.append('mapping_ids[]', String(id)));
    } else {
      body.append('mapping_id', String(mappingId));
    }
    if (userId) body.append('user_id', String(userId));
    const response = await fetch('/admin/api/flightcircle_identity_action.php', {
      method: 'POST',
      credentials: 'same-origin',
      body
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload.ok) {
      throw new Error(payload.error || ('Identity action returned HTTP ' + response.status));
    }
    return payload;
  }
  const fcSelectAllIdentities = document.querySelector('[data-fc-select-all-identities]');
  const fcIdentityBoxes = () => Array.from(document.querySelectorAll('[data-fc-identity-checkbox]'));
  if (fcSelectAllIdentities) {
    fcSelectAllIdentities.addEventListener('change', () => fcIdentityBoxes().forEach(box => {
      box.checked = fcSelectAllIdentities.checked;
    }));
  }
  const fcBulkCreate = document.querySelector('[data-fc-bulk-create-users]');
  if (fcBulkCreate) {
    fcBulkCreate.addEventListener('click', async () => {
      const ids = fcIdentityBoxes().filter(box => box.checked).map(box => Number(box.value || 0)).filter(Boolean);
      if (ids.length === 0) {
        if (fcIdentityMessage) fcIdentityMessage.textContent = 'Select at least one user suggestion first.';
        return;
      }
      fcBulkCreate.disabled = true;
      if (fcIdentityMessage) fcIdentityMessage.textContent = 'Creating ' + ids.length.toLocaleString() + ' users...';
      try {
        const result = await postFlightCircleIdentity('bulk_create_users', ids);
        if (fcIdentityMessage) fcIdentityMessage.textContent = 'Created ' + Number(result.created_count || 0).toLocaleString()
          + ', mapped existing ' + Number(result.mapped_existing_count || 0).toLocaleString()
          + ', failed ' + Number(result.failed_count || 0).toLocaleString() + '. Refreshing...';
        setTimeout(() => window.location.reload(), 900);
      } catch (error) {
        if (fcIdentityMessage) fcIdentityMessage.textContent = 'Bulk create failed: ' + error.message;
        fcBulkCreate.disabled = false;
      }
    });
  }
  document.querySelectorAll('[data-fc-create-user]').forEach(button => {
    button.addEventListener('click', async () => {
      const mappingId = Number(button.getAttribute('data-fc-create-user') || 0);
      button.disabled = true;
      if (fcIdentityMessage) fcIdentityMessage.textContent = 'Creating user...';
      try {
        const result = await postFlightCircleIdentity('create_user', mappingId);
        if (fcIdentityMessage) fcIdentityMessage.textContent = 'Created user #' + result.user_id + '. Refreshing...';
        setTimeout(() => window.location.reload(), 700);
      } catch (error) {
        if (fcIdentityMessage) fcIdentityMessage.textContent = 'Create user failed: ' + error.message;
        button.disabled = false;
      }
    });
  });
  document.querySelectorAll('[data-fc-map-existing]').forEach(button => {
    button.addEventListener('click', async () => {
      const mappingId = Number(button.getAttribute('data-fc-map-existing') || 0);
      const select = document.querySelector('[data-fc-existing-user="' + mappingId + '"]');
      const userId = select ? Number(select.value || 0) : 0;
      if (!userId) {
        if (fcIdentityMessage) fcIdentityMessage.textContent = 'Select an existing user first.';
        return;
      }
      button.disabled = true;
      if (fcIdentityMessage) fcIdentityMessage.textContent = 'Mapping user...';
      try {
        const result = await postFlightCircleIdentity('map_existing', mappingId, userId);
        if (fcIdentityMessage) fcIdentityMessage.textContent = 'Mapped to user #' + result.user_id + '. Refreshing...';
        setTimeout(() => window.location.reload(), 700);
      } catch (error) {
        if (fcIdentityMessage) fcIdentityMessage.textContent = 'Map existing user failed: ' + error.message;
        button.disabled = false;
      }
    });
  });
  async function uploadHistoricalGarminSequentially(event) {
    if (!historicalUploadForm) return;
    const files = selectedHistoricalFiles();
    if (files.length <= 1) return;
    event.preventDefault();
    if (historicalUploadButton) {
      historicalUploadButton.disabled = true;
      historicalUploadButton.textContent = 'Creating batch...';
    }
    try {
      const aircraftHint = String((historicalUploadForm.querySelector('[name="aircraft_hint"]') || {}).value || '');
      const notes = String((historicalUploadForm.querySelector('[name="notes"]') || {}).value || '');
      const createBody = new FormData();
      createBody.append('action', 'create_batch');
      createBody.append('aircraft_hint', aircraftHint);
      createBody.append('notes', notes);
      const created = await postHistoricalUpload(createBody);
      const batchId = Number(created.batch_id || 0);
      if (!batchId) throw new Error('Batch was not created.');
      if (historicalBatchLabel) historicalBatchLabel.textContent = '#' + batchId;
      if (historicalBackendState) historicalBackendState.textContent = 'receiving files';
      let uploaded = 0;
      let failed = 0;
      setHistoricalUploadProgress(0, files.length, 'Created batch #' + batchId + '. Uploading files one at a time...');
      const backendPoll = pollHistoricalBackend(batchId, false).catch(() => {});
      for (const file of files) {
        const body = new FormData();
        body.append('action', 'upload_to_batch');
        body.append('batch_id', String(batchId));
        body.append('aircraft_hint', aircraftHint);
        body.append('garmin_csv_files[]', file, file.webkitRelativePath || file.name);
        if (historicalUploadButton) historicalUploadButton.textContent = 'Uploading ' + (uploaded + 1) + ' / ' + files.length;
        try {
          await postHistoricalUpload(body);
        } catch (error) {
          failed++;
          if (historicalUploadMessage) historicalUploadMessage.textContent = 'Failed ' + file.name + ': ' + error.message + '. Continuing...';
        }
        uploaded++;
        setHistoricalUploadProgress(uploaded, files.length, 'Uploaded ' + uploaded + ' / ' + files.length + (failed ? ' · failed ' + failed : '') + '.');
      }
      if (historicalUploadButton) historicalUploadButton.textContent = 'Upload complete';
      setHistoricalBackendProgress({ percent: 0, state: 'queued' }, 'Upload transfer complete. Waiting for backend processing updates...');
      await pollHistoricalBackend(batchId, true).catch(() => {});
      window.location.href = '/admin/flight_log_garmin_connection.php?historical_batch=' + encodeURIComponent(String(batchId));
    } catch (error) {
      if (historicalUploadMessage) historicalUploadMessage.textContent = 'Upload failed: ' + error.message;
      if (historicalUploadButton) {
        historicalUploadButton.disabled = false;
        historicalUploadButton.textContent = 'Retry Historical Garmin Upload';
      }
    }
  }
  if (historicalUploadForm) {
    historicalUploadForm.addEventListener('submit', uploadHistoricalGarminSequentially);
    historicalUploadForm.querySelectorAll('[data-historical-file-input]').forEach(input => input.addEventListener('change', renderHistoricalSelection));
    renderHistoricalSelection();
    const latestBatchId = Number(historicalUploadForm.getAttribute('data-latest-batch-id') || 0);
    if (latestBatchId > 0) {
      if (historicalBatchLabel) historicalBatchLabel.textContent = '#' + latestBatchId;
      pollHistoricalBackend(latestBatchId, false).catch(() => {});
    }
  }
  function mapMessage(element, text) {
    if (!element || element.querySelector('.garmin-map-message')) return;
    element.insertAdjacentHTML('beforeend', '<div class="garmin-map-message">' + text + '</div>');
  }
  function fieldIndexes(fields) {
    const indexes = {};
    (Array.isArray(fields) ? fields : []).forEach((field, index) => {
      if (!field || typeof field !== 'object') return;
      const type = ['fieldType', 'name', 'label', 'displayName', 'title', 'id']
        .map((key) => String(field[key] || '').trim().toLowerCase())
        .filter(Boolean)
        .join(' ');
      if (!indexes.time && type === 'time') indexes.time = index;
      if (!indexes.lat && (type.includes('lat') || type === 'latitude')) indexes.lat = index;
      if (!indexes.lon && (type.includes('lon') || type.includes('lng') || type === 'longitude')) indexes.lon = index;
    });
    return indexes;
  }
  function pointsFromTrackJson(json) {
    const points = [];
    (Array.isArray(json?.sessions) ? json.sessions : []).forEach((session) => {
      const indexes = fieldIndexes(session?.fields || []);
      if (indexes.time === undefined || indexes.lat === undefined || indexes.lon === undefined) return;
      (Array.isArray(session?.data) ? session.data : []).forEach((row) => {
        if (!Array.isArray(row)) return;
        const lat = Number(row[indexes.lat]);
        const lon = Number(row[indexes.lon]);
        if (Number.isFinite(lat) && Number.isFinite(lon) && Math.abs(lat) <= 90 && Math.abs(lon) <= 180) {
          points.push([lat, lon]);
        }
      });
    });
    return points;
  }
  async function renderGarminMap(element) {
    if (!element || renderedMaps.has(element)) return;
    if (typeof L === 'undefined') {
      mapMessage(element, 'Map library unavailable.');
      return;
    }
    renderedMaps.set(element, true);
    mapMessage(element, 'Loading GPS track...');
    try {
      const response = await fetch(element.getAttribute('data-raw-url') || '', { credentials: 'same-origin' });
      if (!response.ok) throw new Error('Track JSON returned HTTP ' + response.status);
      const points = pointsFromTrackJson(await response.json());
      element.innerHTML = '';
      const map = L.map(element, { scrollWheelZoom: false });
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap'
      }).addTo(map);
      if (points.length < 2) {
        map.setView([33.6267, -116.1600], 8);
        mapMessage(element, 'No GPS route available for this artifact.');
        return;
      }
      const polyline = L.polyline(points, { color: '#2563eb', weight: 3, opacity: 0.9 }).addTo(map);
      L.circleMarker(points[0], { radius: 5, color: '#16a34a', fillColor: '#16a34a', fillOpacity: 1 }).addTo(map).bindTooltip('Departure');
      L.circleMarker(points[points.length - 1], { radius: 5, color: '#dc2626', fillColor: '#dc2626', fillOpacity: 1 }).addTo(map).bindTooltip('Arrival');
      map.fitBounds(polyline.getBounds(), { padding: [22, 22] });
      setTimeout(() => map.invalidateSize(), 80);
    } catch (error) {
      element.innerHTML = '';
      mapMessage(element, 'Could not load GPS track preview.');
    }
  }
  document.querySelectorAll('[data-modal-open]').forEach(button => {
    button.addEventListener('click', () => {
      const modal = document.getElementById(button.getAttribute('data-modal-open'));
      if (modal) {
        modal.classList.add('is-open');
        modal.querySelectorAll('[data-garmin-map]').forEach(renderGarminMap);
      }
    });
  });
  document.querySelectorAll('[data-modal-close]').forEach(button => {
    button.addEventListener('click', () => {
      const modal = button.closest('.garmin-modal-backdrop');
      if (modal) modal.classList.remove('is-open');
    });
  });
  document.querySelectorAll('.garmin-modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', event => {
      if (event.target === backdrop) backdrop.classList.remove('is-open');
    });
  });
  const bulkForm = document.getElementById('garmin-bulk-form');
  if (bulkForm) {
    bulkForm.addEventListener('submit', event => {
      if (!boxes.some(box => box.checked)) {
        event.preventDefault();
        alert('Select at least one Garmin flight first.');
      }
    });
  }
  function stateLabel(state) {
    return String(state || 'idle').replace(/_/g, ' ').toUpperCase();
  }
  function renderProcessingStatus(status, actionMessage) {
    if (!status) return;
    const percent = Number(status.percent || 0);
    if (processingState) {
      processingState.className = 'garmin-state-badge garmin-state-' + String(status.state || 'idle');
      processingState.textContent = stateLabel(status.state);
    }
    if (processingMessage) processingMessage.textContent = actionMessage || status.message || '';
    if (processingBar) processingBar.style.width = percent + '%';
    if (processingPercent) processingPercent.textContent = percent + '%';
    if (processingDetail) {
      const parts = [
        Number(status.done || 0).toLocaleString() + ' / ' + Number(status.total || 0).toLocaleString() + ' processed',
        'CSV ' + Number(status.csv?.done || 0).toLocaleString() + '/' + Number(status.csv?.total || 0).toLocaleString(),
        'Tracks ' + Number(status.tracks?.done || 0).toLocaleString() + '/' + Number(status.tracks?.total || 0).toLocaleString(),
        'CSV-linked tracks ' + Number(status.linked_csv_tracks?.done || 0).toLocaleString() + '/' + Number(status.linked_csv_tracks?.total || 0).toLocaleString(),
        'Jobs ' + Number(status.jobs?.queued || 0).toLocaleString() + ' queued, ' + Number(status.jobs?.running || 0).toLocaleString() + ' running, ' + Number(status.jobs?.failed || 0).toLocaleString() + ' failed',
        'Updated ' + new Date().toLocaleTimeString()
      ];
      if (status.jobs?.last_error) parts.push('Last error: ' + status.jobs.last_error);
      processingDetail.textContent = parts.join(' · ');
    }
    const reviewCount = Number(status.needs_review?.total || 0);
    if (processingReview) processingReview.style.display = reviewCount > 0 ? 'block' : 'none';
    if (processingReviewText) {
      const sample = Array.isArray(status.needs_review?.sample) ? status.needs_review.sample : [];
      const reasons = sample.slice(0, 3).map(item => item.reason || item.track_uuid).join(' | ');
      processingReviewText.textContent = reviewCount > 0
        ? reviewCount.toLocaleString() + ' Garmin artifact(s) still need summary processing. ' + (reasons ? 'Reason: ' + reasons + '. Use Process Garmin Data, or run the normal Garmin summary worker.' : '')
        : 'No processing attention items.';
    }
    if (processButton && !processingRunning) {
      if (status.state === 'complete') {
        processButton.textContent = 'All Garmin Data Processed';
        processButton.dataset.action = 'complete';
        processButton.disabled = true;
      } else if (status.state === 'failed') {
        processButton.textContent = 'Retry Processing';
        processButton.dataset.action = 'process';
        processButton.disabled = false;
      } else if (status.state === 'needs_review') {
        processButton.textContent = 'Show Processing Attention';
        processButton.dataset.action = 'review';
        processButton.disabled = false;
      } else {
        processButton.textContent = 'Process Garmin Data';
        processButton.dataset.action = 'process';
        processButton.disabled = false;
      }
    }
  }
  function setReviewAction() {
    if (!processButton) return;
    processButton.textContent = 'Show Processing Attention';
    processButton.dataset.action = 'review';
    processButton.disabled = false;
  }
  async function getProcessingStatus() {
    const response = await fetch('/admin/api/garmin_processing_status.php', { credentials: 'same-origin' });
    if (!response.ok) throw new Error('Status returned HTTP ' + response.status);
    return response.json();
  }
  async function postSummary(action, limit) {
    const body = new FormData();
    body.append('action', action);
    body.append('format', 'json');
    body.append('limit', String(limit || 50));
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 30000);
    const response = await fetch('/admin/api/garmin_csv_summary_action.php', {
      method: 'POST',
      credentials: 'same-origin',
      body,
      signal: controller.signal
    }).finally(() => clearTimeout(timeout));
    if (!response.ok) throw new Error('Summary processor returned HTTP ' + response.status);
    return response.json();
  }
  async function processGarminData() {
    if (processingRunning) return;
    if (processButton && processButton.dataset.action === 'review') {
      const statusField = filterForm ? filterForm.querySelector('[name="status"]') : null;
      const incompleteField = filterForm ? filterForm.querySelector('[name="show_incomplete"]') : null;
      if (statusField) statusField.value = 'Needs review';
      if (incompleteField) incompleteField.value = '1';
      applyInstantFilters();
      return;
    }
    processingRunning = true;
    if (processButton) {
      processButton.textContent = 'Processing...';
      processButton.disabled = true;
    }
    try {
      let statusResponse = await getProcessingStatus();
      let batchLimit = 50;
      let stagnantBatches = 0;
      renderProcessingStatus(statusResponse.status, 'Checking Garmin processing status...');
      if (!statusResponse.status || Number(statusResponse.status.remaining || 0) === 0) {
        renderProcessingStatus(statusResponse.status);
        return;
      }
      while (statusResponse.status && Number(statusResponse.status.remaining || 0) > 0) {
        if (processButton) processButton.textContent = 'Processing ' + batchLimit + ' at a time...';
        const beforeRemaining = Number(statusResponse.status.remaining || 0);
        const beforeDone = Number(statusResponse.status.done || 0);
        let result;
        try {
          result = await postSummary('process_next', batchLimit);
        } catch (error) {
          if (batchLimit > 10) {
            batchLimit = Math.max(10, Math.floor(batchLimit / 2));
            renderProcessingStatus(statusResponse.status, 'Server was busy. Retrying smaller batches of ' + batchLimit + '...');
            await new Promise(resolve => setTimeout(resolve, 750));
            continue;
          }
          throw error;
        }
        renderProcessingStatus(result.status, 'Processed ' + Number(result.processed || 0).toLocaleString() + ' more. Failed ' + Number(result.failed || 0).toLocaleString() + '.');
        if (Number(result.processed || 0) === 0) break;
        const afterRemaining = Number(result.status?.remaining || beforeRemaining);
        const afterDone = Number(result.status?.done || beforeDone);
        if (afterRemaining >= beforeRemaining && afterDone <= beforeDone) {
          stagnantBatches++;
          if (stagnantBatches >= 2) {
            renderProcessingStatus(result.status, 'Stopped: remaining records need review.');
            setReviewAction();
            break;
          }
        } else {
          stagnantBatches = 0;
        }
        statusResponse = result;
        await new Promise(resolve => setTimeout(resolve, 150));
      }
      const finalStatus = await getProcessingStatus();
      renderProcessingStatus(finalStatus.status, Number(finalStatus.status?.remaining || 0) === 0 ? 'Complete.' : 'Processing stopped before completion.');
      if (finalStatus.status?.state === 'needs_review') setReviewAction();
    } catch (error) {
      if (processingMessage) processingMessage.textContent = 'Processing failed: ' + error.message;
      if (processButton) {
        processButton.textContent = 'Retry Processing';
        processButton.disabled = false;
      }
    } finally {
      processingRunning = false;
      try {
        const latest = await getProcessingStatus();
        renderProcessingStatus(latest.status);
      } catch (_) {}
    }
  }
  if (processButton) processButton.addEventListener('click', processGarminData);
  getProcessingStatus().then(payload => renderProcessingStatus(payload.status)).catch(() => {});
})();
</script>
<?php cw_footer(); ?>
