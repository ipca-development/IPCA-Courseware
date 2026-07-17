<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/GarminCsvFlightSummaryService.php';
require_once __DIR__ . '/../../src/GarminTrackFlightSummaryService.php';

cw_require_admin();

function garmin_sync_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute(array($table));
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
$summaryService = new GarminCsvFlightSummaryService($pdo);
$trackSummaryService = new GarminTrackFlightSummaryService($pdo);

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
        ELSE 0
      END) AS missing_summaries
    FROM ipca_garmin_csv_files f
    " . ($hasCsvSummaries ? "LEFT JOIN ipca_garmin_csv_flight_summaries s ON s.csv_file_id = f.id" : "LEFT JOIN (SELECT NULL AS csv_file_id) s ON 1 = 0") . "
") : array('total_csv_files' => 0, 'summarized_csv_files' => 0, 'missing_summaries' => 0);

$trackSummaryStats = $hasTracks ? garmin_sync_row($pdo, "
    SELECT
      COUNT(t.id) AS total_track_artifacts,
      SUM(CASE
        WHEN s.track_artifact_id IS NULL THEN 0
        WHEN JSON_EXTRACT(s.summary_json, '$.hobbs_exact') IS NULL THEN 0
        WHEN JSON_EXTRACT(s.summary_json, '$.tacho_exact') IS NULL THEN 0
        WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.hobbs_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 0
        WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.tacho_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 0
        WHEN s.tail_number IN ('', 'Unknown tail', 'Unknown')
          AND JSON_SEARCH(t.source_descriptors_json, 'one', 'Flight Data Log System ID:%') IS NOT NULL THEN 0
        ELSE 1
      END) AS summarized_track_artifacts,
      SUM(CASE
        WHEN s.track_artifact_id IS NULL THEN 1
        WHEN JSON_EXTRACT(s.summary_json, '$.hobbs_exact') IS NULL THEN 1
        WHEN JSON_EXTRACT(s.summary_json, '$.tacho_exact') IS NULL THEN 1
        WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.hobbs_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 1
        WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.tacho_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 1
        WHEN s.tail_number IN ('', 'Unknown tail', 'Unknown')
          AND JSON_SEARCH(t.source_descriptors_json, 'one', 'Flight Data Log System ID:%') IS NOT NULL THEN 1
        ELSE 0
      END) AS missing_track_summaries
    FROM ipca_garmin_normalized_track_artifacts t
    " . ($hasTrackSummaries ? "LEFT JOIN ipca_garmin_track_flight_summaries s ON s.track_artifact_id = t.id" : "LEFT JOIN (SELECT NULL AS track_artifact_id) s ON 1 = 0") . "
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
        " . ($hasTrackCsvLinks ? "LEFT JOIN ipca_garmin_flight_data_track_links track_csv_l
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

cw_header('Garmin Sync Agent');
?>
<style>
.garmin-page{display:grid;gap:16px}.garmin-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:16px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.garmin-muted{color:#64748b;font-size:12px}.garmin-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.garmin-kv{border:1px solid #e2e8f0;border-radius:12px;padding:10px;background:#f8fafc}.garmin-label{color:#64748b;font-size:10px;text-transform:uppercase;letter-spacing:.04em}.garmin-value{font-weight:800;margin-top:3px}.garmin-badge{display:inline-flex;border-radius:999px;padding:2px 7px;font-size:10px;font-weight:800;background:#e2e8f0;color:#334155;white-space:nowrap}.garmin-badge-ok{background:#dcfce7;color:#166534}.garmin-badge-warn{background:#fef3c7;color:#92400e}.garmin-badge-danger{background:#fee2e2;color:#991b1b}.garmin-badge-new{background:#dbeafe;color:#1d4ed8}.garmin-table-wrap{overflow-x:visible}.garmin-flights-scroll{max-height:72vh;overflow:auto;border:1px solid #e2e8f0;border-radius:12px}.garmin-flights-scroll .garmin-table th{position:sticky;top:0;z-index:2;background:#fff}.garmin-table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:11px}.garmin-table th,.garmin-table td{border-bottom:1px solid #e2e8f0;padding:7px 6px;text-align:left;vertical-align:middle;overflow:hidden;text-overflow:ellipsis}.garmin-table th{color:#475569;font-size:9.5px;text-transform:uppercase;letter-spacing:.025em;resize:none;overflow:hidden}.garmin-code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:11px;word-break:break-all}.garmin-toolbar,.garmin-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.garmin-toolbar a,.garmin-toolbar button,.garmin-actions button,.garmin-actions a{border:0;border-radius:10px;background:#0f172a;color:#fff;font-weight:800;padding:8px 10px;text-decoration:none;cursor:pointer;font-size:12px}.garmin-toolbar a.secondary,.garmin-toolbar button.secondary,.garmin-actions .secondary{background:#475569}.garmin-toolbar form{margin:0}.garmin-progress{position:relative;height:18px;background:#e2e8f0;border-radius:999px;overflow:hidden}.garmin-progress span{display:block;height:100%;background:linear-gradient(90deg,#2563eb,#0ea5e9)}.garmin-progress strong{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#0f172a;font-size:11px;font-weight:900;text-shadow:0 1px 0 rgba(255,255,255,.75)}.garmin-empty{padding:18px;border:1px dashed #cbd5e1;border-radius:12px;color:#64748b;background:#f8fafc}.garmin-notice{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:12px}.garmin-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px}.garmin-filter{display:grid;grid-template-columns:110px 132px 132px 110px 110px;gap:8px 10px;margin-top:12px;align-items:end;justify-content:start}.garmin-filter-control{display:grid;grid-template-rows:14px 32px;gap:3px;align-items:end}.garmin-filter-label{display:block;height:14px;color:#64748b;font-size:10px;line-height:14px;text-transform:uppercase;letter-spacing:.04em}.garmin-filter input,.garmin-filter select,.garmin-filter button{box-sizing:border-box;width:100%;height:32px;border-radius:8px;font:inherit;font-size:12px;line-height:1}.garmin-filter input,.garmin-filter select{border:1px solid #cbd5e1;background:#fff;padding:6px 8px}.garmin-filter button{border:0;background:#475569;color:#fff;font-weight:800;padding:6px 10px;cursor:pointer}.garmin-row-button{border:0;background:transparent;color:#1d4ed8;font-weight:900;cursor:pointer;padding:0}.garmin-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;z-index:9999;padding:24px;overflow:auto}.garmin-modal-backdrop.is-open{display:block}.garmin-modal{max-width:980px;margin:0 auto;background:#fff;border-radius:16px;box-shadow:0 24px 70px rgba(15,23,42,.35);overflow:hidden}.garmin-modal-header{display:flex;justify-content:space-between;gap:12px;padding:16px 18px;border-bottom:1px solid #e2e8f0}.garmin-modal-body{padding:16px 18px;display:grid;gap:12px}.garmin-detail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}.garmin-raw-block{max-height:240px;overflow:auto;background:#0f172a;color:#e2e8f0;border-radius:10px;padding:10px;font-size:11px}.garmin-compact{white-space:nowrap}.garmin-tail-pill{display:inline-flex;align-items:center;border:1px solid #cbd5e1;border-radius:999px;padding:2px 7px;font-size:10px;font-weight:900;white-space:nowrap}.garmin-tail-unknown{background:#fee2e2;color:#991b1b;border-color:#fecaca}.garmin-upload-pill{display:inline-flex;border-radius:999px;background:#e0f2fe;color:#075985;padding:2px 7px;font-size:10px;font-weight:900}
</style>
<div class="garmin-page">
  <section class="garmin-card">
    <div style="display:flex;gap:16px;justify-content:space-between;align-items:flex-start;flex-wrap:wrap">
      <div>
        <h2 style="margin:0">Garmin Sync Agent Dashboard</h2>
        <p class="garmin-muted">Server-side view of Garmin data uploaded by the native Mac IPCA Sync Agent. The old cloud-browser Garmin controls have been retired from this page.</p>
      </div>
      <div class="garmin-toolbar">
        <button type="button" data-summary-start>Resume Summary Processing</button>
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

    <?php
      $totalSummaries = (int)($trackSummaryStats['total_track_artifacts'] ?? 0) + (int)($csvSummaryStats['total_csv_files'] ?? 0);
      $doneSummaries = (int)($trackSummaryStats['summarized_track_artifacts'] ?? 0) + (int)($csvSummaryStats['summarized_csv_files'] ?? 0);
      $summaryPercent = $totalSummaries > 0 ? min(100, (int)round(($doneSummaries / $totalSummaries) * 100)) : 0;
    ?>
    <div style="margin:12px 0" data-summary-progress>
      <div class="garmin-muted" data-summary-progress-text>Summary processing <?= number_format($doneSummaries) ?> / <?= number_format($totalSummaries) ?> (<?= $summaryPercent ?>%)</div>
      <div class="garmin-progress"><span data-summary-progress-bar style="width:<?= $summaryPercent ?>%"></span><strong data-summary-progress-percent><?= $summaryPercent ?>%</strong></div>
      <div class="garmin-muted" data-summary-progress-detail style="margin-top:6px">Waiting for processor status...</div>
    </div>

    <?php if ($flightRows === array()): ?>
      <div class="garmin-empty">No Garmin flights match the current filters. Incomplete/GPS-only flights are hidden by default.</div>
    <?php else: ?>
      <div class="garmin-flights-scroll">
      <table class="garmin-table">
        <thead><tr><th style="width:3%"><input type="checkbox" data-select-all-flights></th><th style="width:7%">Flight</th><th style="width:9%">Date</th><th style="width:7%">Tail</th><th style="width:6%">Dep AD</th><th style="width:7%">Dep LT</th><th style="width:7%">Hobbs Out</th><th style="width:7%">Hobbs In</th><th style="width:7%">Hobbs Time</th><th style="width:7%">Tacho Out</th><th style="width:7%">Tacho In</th><th style="width:7%">Tacho Time</th><th style="width:6%">Arr AD</th><th style="width:7%">Arr LT</th><th style="width:8%">Status</th><th style="width:13%">Uploaded</th></tr></thead>
        <tbody>
        <?php foreach ($flightRows as $flight): ?>
          <?php
            $track = $flight['track'];
            $summary = $flight['summary'];
            $flightId = garmin_sync_flight_id((int)$track['id']);
            $modalId = 'garmin-flight-' . (int)$track['id'];
            $statusLabel = (string)$flight['status_label'];
            $statusClass = $statusLabel === 'Complete' ? 'garmin-badge-ok' : ($statusLabel === 'GPS only' ? 'garmin-badge-danger' : 'garmin-badge-warn');
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
          </tr>
          <?php ob_start(); ?>
            <div class="garmin-modal-backdrop" id="<?= h($modalId) ?>">
              <div class="garmin-modal">
                <div class="garmin-modal-header"><div><h3 style="margin:0"><?= h($flightId) ?> · <?= h((string)($summary['tail'] ?? 'Unknown tail')) ?></h3><div class="garmin-muted"><?= h((string)($summary['label'] ?? '')) ?></div></div><button class="garmin-row-button" type="button" data-modal-close>Close</button></div>
                <div class="garmin-modal-body">
                  <div class="garmin-detail-grid">
                    <div class="garmin-kv"><div class="garmin-label">Full Track UUID</div><div class="garmin-code"><?= h((string)$track['track_uuid']) ?></div></div>
                    <div class="garmin-kv"><div class="garmin-label">Entry UUID</div><div class="garmin-code"><?= h((string)$track['garmin_entry_uuid']) ?></div></div>
                    <div class="garmin-kv"><div class="garmin-label">Classification</div><div><?= h((string)$flight['classification']) ?></div></div>
                    <div class="garmin-kv"><div class="garmin-label">Telemetry</div><div><?= number_format((int)$track['session_count']) ?> sessions · <?= number_format((int)$track['field_count']) ?> fields · <?= h(garmin_sync_bytes($track['file_size_bytes'] ?? 0)) ?></div></div>
                    <div class="garmin-kv"><div class="garmin-label">Identity Evidence</div><div>CSV ident <?= h((string)($summary['aircraft_ident_raw'] ?? '--')) ?> · system <?= h((string)($summary['system_id'] ?? '--')) ?> · tail source <?= h((string)($summary['tail_source'] ?? '--')) ?></div></div>
                    <div class="garmin-kv"><div class="garmin-label">Source Quality</div><div><?= h((string)($summary['avionics_family'] ?? '--')) ?> · <?= h((string)($summary['default_quality'] ?? '--')) ?> · counters <?= !empty($summary['provides_counter_headers']) ? 'yes' : 'no' ?></div></div>
                    <div class="garmin-kv"><div class="garmin-label">Hobbs</div><div>Out <?= h((string)($summary['hobbs_out'] ?? '--')) ?> · In <?= h((string)($summary['hobbs_in'] ?? '--')) ?> · Time <?= h((string)($summary['hobbs_time'] ?? '--')) ?></div></div>
                    <div class="garmin-kv"><div class="garmin-label">Tacho</div><div>Out <?= h((string)($summary['tacho_out'] ?? '--')) ?> · In <?= h((string)($summary['tacho_in'] ?? '--')) ?> · Time <?= h((string)($summary['tacho_time'] ?? '--')) ?></div></div>
                    <div class="garmin-kv"><div class="garmin-label">Upload</div><div><?= h((string)($track['device_name'] ?? 'Unknown device')) ?> at <?= h(garmin_sync_datetime((string)$flight['uploaded_at'])) ?></div></div>
                  </div>
                  <div><a href="/admin/api/garmin_artifact_raw.php?track_artifact_id=<?= (int)$track['id'] ?>" target="_blank" rel="noopener">Open full raw Garmin normalized JSON</a></div>
                  <pre class="garmin-raw-block"><?= h(json_encode(array('summary' => $summary, 'source_names' => $flight['source_names'], 'sha256' => $track['sha256'] ?? ''), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                </div>
              </div>
            </div>
          <?php $flightModals[] = ob_get_clean(); ?>
        <?php endforeach; ?>
          <tr data-filter-empty style="display:none"><td colspan="16" class="garmin-empty">No Garmin flights match the current filters. Adjust the filters or choose Show incomplete.</td></tr>
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
<script>
(function(){
  const selectAll = document.querySelector('[data-select-all-flights]');
  const boxes = Array.from(document.querySelectorAll('[data-flight-checkbox]'));
  const filterForm = document.querySelector('.garmin-filter');
  const rows = Array.from(document.querySelectorAll('[data-flight-row]'));
  const emptyRow = document.querySelector('[data-filter-empty]');
  const summaryText = document.querySelector('[data-summary-progress-text]');
  const summaryBar = document.querySelector('[data-summary-progress-bar]');
  const summaryPercentLabel = document.querySelector('[data-summary-progress-percent]');
  const summaryDetail = document.querySelector('[data-summary-progress-detail]');
  const summaryStart = document.querySelector('[data-summary-start]');
  let summaryRunning = false;
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
  if (selectAll) {
    selectAll.addEventListener('change', () => boxes.forEach(box => {
      const row = box.closest('[data-flight-row]');
      if (!row || row.style.display !== 'none') box.checked = selectAll.checked;
    }));
  }
  document.querySelectorAll('[data-modal-open]').forEach(button => {
    button.addEventListener('click', () => {
      const modal = document.getElementById(button.getAttribute('data-modal-open'));
      if (modal) modal.classList.add('is-open');
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
  function updateSummaryProgress(status, message, batch) {
    if (!status || !summaryText || !summaryBar) return;
    const percent = Number(status.percent || 0);
    const csvRemaining = Math.max(0, Number(status.csv_total || 0) - Number(status.csv_done || 0));
    const trackRemaining = Math.max(0, Number(status.track_total || 0) - Number(status.track_done || 0));
    summaryBar.style.width = percent + '%';
    if (summaryPercentLabel) summaryPercentLabel.textContent = percent + '% done';
    const prefix = message ? message + ' · ' : '';
    summaryText.textContent = prefix + 'Summary processing ' + Number(status.done || 0).toLocaleString() + ' / ' + Number(status.total || 0).toLocaleString() + ' (' + percent + '%)';
    if (summaryDetail) {
      const parts = [
        'Remaining ' + Number(status.remaining || 0).toLocaleString(),
        'CSV ' + Number(status.csv_done || 0).toLocaleString() + '/' + Number(status.csv_total || 0).toLocaleString() + ' (' + csvRemaining.toLocaleString() + ' left)',
        'Tracks ' + Number(status.track_done || 0).toLocaleString() + '/' + Number(status.track_total || 0).toLocaleString() + ' (' + trackRemaining.toLocaleString() + ' left)'
      ];
      if (batch) {
        parts.push('Last batch +' + Number(batch.processed || 0).toLocaleString() + ', failed ' + Number(batch.failed || 0).toLocaleString());
      }
      parts.push('Updated ' + new Date().toLocaleTimeString());
      summaryDetail.textContent = parts.join(' · ');
    }
  }
  async function postSummary(action, limit) {
    const body = new FormData();
    body.append('action', action);
    body.append('format', 'json');
    body.append('limit', String(limit || 250));
    const response = await fetch('/admin/api/garmin_csv_summary_action.php', {
      method: 'POST',
      credentials: 'same-origin',
      body
    });
    if (!response.ok) throw new Error('Summary processor returned HTTP ' + response.status);
    return response.json();
  }
  async function runSummaryLoop() {
    if (summaryRunning) return;
    summaryRunning = true;
    if (summaryStart) summaryStart.textContent = 'Processing...';
    try {
      let statusResponse = await postSummary('status', 1);
      updateSummaryProgress(statusResponse.status, 'Checking');
      while (statusResponse.status && Number(statusResponse.status.remaining || 0) > 0) {
        const result = await postSummary('process_next', 250);
        updateSummaryProgress(result.status, 'Processed ' + Number(result.processed || 0).toLocaleString() + ' more', result);
        if (Number(result.processed || 0) === 0) break;
        statusResponse = result;
        await new Promise(resolve => setTimeout(resolve, 50));
      }
      const finalStatus = await postSummary('status', 1);
      updateSummaryProgress(finalStatus.status, Number(finalStatus.status.remaining || 0) === 0 ? 'Complete' : 'Paused');
    } catch (error) {
      if (summaryText) summaryText.textContent = 'Summary processing paused: ' + error.message;
      if (summaryDetail) summaryDetail.textContent = 'The processor stopped before completion. Last update ' + new Date().toLocaleTimeString();
    } finally {
      summaryRunning = false;
      if (summaryStart) summaryStart.textContent = 'Resume Summary Processing';
    }
  }
  if (summaryStart) summaryStart.addEventListener('click', runSummaryLoop);
  runSummaryLoop();
})();
</script>
<?php cw_footer(); ?>
