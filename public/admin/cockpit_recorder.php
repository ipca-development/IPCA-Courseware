<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/CockpitRecorderService.php';
require_once __DIR__ . '/../../src/CockpitReconstructionService.php';
require_once __DIR__ . '/../../src/CockpitAdsbEnrichmentService.php';
require_once __DIR__ . '/../../src/GarminCsvImportProfile.php';
require_once __DIR__ . '/../../src/GarminCsvFlightSummaryService.php';
require_once __DIR__ . '/../../src/GarminCsvReplayPayloadService.php';

cw_require_admin();

function cockpit_admin_fmt_bytes(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

function cockpit_admin_fmt_duration(float $seconds): string
{
    $seconds = max(0, (int)round($seconds));
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}

function cockpit_admin_fmt_timestamp(mixed $timestamp): string
{
    $timestamp = trim((string)$timestamp);
    return $timestamp !== '' ? $timestamp : '--';
}

function cockpit_admin_local_timezone(): string
{
    $timezone = trim((string)(getenv('CW_COCKPIT_TIMEZONE') ?: getenv('CW_APP_TIMEZONE') ?: 'America/Los_Angeles'));
    return $timezone !== '' ? $timezone : 'America/Los_Angeles';
}

/**
 * @return array{date:string,time:string,full:string}
 */
function cockpit_admin_local_time_parts(mixed $timestamp): array
{
    $raw = trim((string)$timestamp);
    if ($raw === '') {
        return array('date' => '--', 'time' => '--', 'full' => '--');
    }

    try {
        $local = (new DateTimeImmutable($raw, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(cockpit_admin_local_timezone()));
        return array(
            'date' => $local->format('D M j, Y'),
            'time' => $local->format('H:i'),
            'full' => $local->format('D M j, Y H:i') . ' LT',
        );
    } catch (Throwable $e) {
        return array('date' => $raw, 'time' => '--', 'full' => $raw);
    }
}

function cockpit_admin_fmt_date(mixed $timestamp): string
{
    $timestamp = trim((string)$timestamp);
    if ($timestamp === '') {
        return '--';
    }
    $time = strtotime($timestamp);
    return $time !== false ? gmdate('Y-m-d', $time) : $timestamp;
}

function cockpit_admin_fmt_hours_between(mixed $start, mixed $end): string
{
    $startTs = strtotime(trim((string)$start));
    $endTs = strtotime(trim((string)$end));
    if ($startTs === false || $endTs === false || $endTs <= $startTs) {
        return '--';
    }
    return number_format(($endTs - $startTs) / 3600, 1) . ' h';
}

function cockpit_admin_garmin_candidate_time(array $candidate): string
{
    foreach (array('generated_track_start_utc', 'operational_csv_start', 'replay_csv_start', 'entry_date') as $key) {
        $value = trim((string)($candidate[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function cockpit_admin_garmin_candidate_route(array $candidate): string
{
    $tail = trim((string)($candidate['aircraft_registration'] ?? ''));
    $entry = trim((string)($candidate['garmin_entry_uuid'] ?? ''));
    if ($tail !== '' && $entry !== '') {
        return $tail . ' · Entry ' . substr($entry, 0, 8);
    }
    return $tail !== '' ? $tail : ($entry !== '' ? 'Entry ' . substr($entry, 0, 8) : 'Garmin flight');
}

function cockpit_admin_fmt_rate(mixed $rate): string
{
    return is_numeric($rate) ? number_format((float)$rate, 2) . ' Hz' : '--';
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function cockpit_admin_decode_json_field(array $row, string $key): array
{
    $raw = trim((string)($row[$key] ?? ''));
    if ($raw === '') {
        return array();
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function cockpit_admin_badge_class(string $value): string
{
    $value = strtolower(trim($value));
    if (in_array($value, array('ready', 'uploaded', 'complete', 'accepted'), true)) {
        return 'cockpit-badge-ready';
    }
    if (in_array($value, array('queued', 'transcribing', 'processing', 'pending', 'not_started'), true)) {
        return 'cockpit-badge-progress';
    }
    if (in_array($value, array('failed', 'error'), true)) {
        return 'cockpit-badge-failed';
    }
    if (in_array($value, array('missing', 'warning', 'needs_review', 'review_required'), true)) {
        return 'cockpit-badge-warning';
    }
    return '';
}

function cockpit_admin_table_exists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute(array($tableName));
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * @return array<string,mixed>
 */
function cockpit_admin_selected_garmin_sources(PDO $pdo, string $flightSessionUid, string $aircraftRegistration = '', string $recordingStartedAt = '', float $durationSeconds = 0.0): array
{
    $flightSessionUid = trim($flightSessionUid);
    foreach (array('ipca_flight_sessions', 'ipca_garmin_source_groups', 'ipca_garmin_flight_data_sources', 'ipca_garmin_csv_files') as $table) {
        if (!cockpit_admin_table_exists($pdo, $table)) {
            return array('status' => 'schema_missing', 'message' => 'Garmin source selection tables are not available yet.');
        }
    }

    $session = null;
    if ($flightSessionUid !== '') {
        $sessionStmt = $pdo->prepare('SELECT * FROM ipca_flight_sessions WHERE session_uuid = ? LIMIT 1');
        $sessionStmt->execute(array($flightSessionUid));
        $sessionRow = $sessionStmt->fetch(PDO::FETCH_ASSOC);
        $session = is_array($sessionRow) ? $sessionRow : null;
    }

    $group = null;
    if ($session !== null) {
        $stmt = $pdo->prepare("
        SELECT
            g.id AS source_group_id,
            g.source_group_uuid,
            g.group_match_status,
            g.group_match_confidence,
            g.source_selection_reason,
            g.primary_operational_source_id,
            g.primary_replay_source_id
        FROM ipca_flight_sessions fs
        LEFT JOIN ipca_garmin_source_groups g ON g.matched_flight_session_id = fs.id
        WHERE fs.session_uuid = ?
        ORDER BY g.updated_at DESC, g.id DESC
        LIMIT 1
        ");
        $stmt->execute(array($flightSessionUid));
        $groupRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $group = is_array($groupRow) && !empty($groupRow['source_group_id']) ? $groupRow : null;
    }

    $sourceIds = $group !== null
        ? array_values(array_unique(array_filter(array(
            (int)($group['primary_operational_source_id'] ?? 0),
            (int)($group['primary_replay_source_id'] ?? 0),
        ))))
        : array();
    $sources = array();
    if ($sourceIds) {
        $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
        $sourceStmt = $pdo->prepare("
            SELECT
                s.*,
                f.csv_file_uuid,
                f.original_filename,
                f.import_profile,
                f.aircraft_ident,
                f.product,
                f.valid_row_count,
                f.file_size_bytes AS csv_file_size_bytes,
                f.first_valid_sample_utc,
                f.last_valid_sample_utc
            FROM ipca_garmin_flight_data_sources s
            LEFT JOIN ipca_garmin_csv_files f ON f.id = s.garmin_csv_file_id
            WHERE s.id IN ({$placeholders})
        ");
        $sourceStmt->execute($sourceIds);
        $rows = $sourceStmt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $sources[(int)$row['id']] = $row;
            }
        }
    }

    $candidates = cockpit_admin_garmin_match_candidates($pdo, $session, $aircraftRegistration, $recordingStartedAt, $durationSeconds);
    $status = $group !== null ? 'matched' : ($flightSessionUid === '' ? 'no_session' : 'not_matched');
    $message = $status === 'no_session'
        ? 'No Flight Session is linked to this recording yet.'
        : 'No selected Garmin source group has been matched to this Flight Session yet.';

    return array(
        'status' => $status,
        'message' => $message,
        'session' => $session,
        'group' => $group,
        'operational' => $group !== null && isset($sources[(int)($group['primary_operational_source_id'] ?? 0)]) ? $sources[(int)$group['primary_operational_source_id']] : null,
        'replay' => $group !== null && isset($sources[(int)($group['primary_replay_source_id'] ?? 0)]) ? $sources[(int)$group['primary_replay_source_id']] : null,
        'candidates' => $candidates,
    );
}

/**
 * @param array<string,mixed>|null $session
 * @return list<array<string,mixed>>
 */
function cockpit_admin_garmin_match_candidates(PDO $pdo, ?array $session, string $aircraftRegistration, string $recordingStartedAt, float $durationSeconds): array
{
    $aircraftRegistration = strtoupper(trim($aircraftRegistration));
    $windowStart = trim($recordingStartedAt);
    $windowEnd = '';
    if ($windowStart !== '') {
        $startTs = strtotime($windowStart);
        if ($startTs !== false && $durationSeconds > 0) {
            $windowEnd = gmdate('Y-m-d H:i:s', $startTs + (int)round($durationSeconds));
        }
    }
    if ($session !== null) {
        $aircraftRegistration = strtoupper(trim((string)($session['aircraft_registration'] ?? $aircraftRegistration)));
        $windowStart = trim((string)($session['avionics_on_utc'] ?? $windowStart));
        $windowEnd = trim((string)($session['avionics_off_utc'] ?? $windowEnd));
    }

    $where = array();
    $params = array();
    if ($session !== null && (int)($session['id'] ?? 0) > 0) {
        $where[] = 'g.matched_flight_session_id = ?';
        $params[] = (int)$session['id'];
    }
    if ($aircraftRegistration !== '' && $windowStart !== '') {
        $where[] = "(UPPER(e.aircraft_registration) = ? AND COALESCE(e.generated_track_start_utc, op.csv_first_timestamp_utc, rp.csv_first_timestamp_utc, e.entry_date) BETWEEN DATE_SUB(?, INTERVAL 12 HOUR) AND DATE_ADD(COALESCE(NULLIF(?, ''), ?), INTERVAL 12 HOUR))";
        $params[] = $aircraftRegistration;
        $params[] = $windowStart;
        $params[] = $windowEnd;
        $params[] = $windowStart;
    }
    if (!$where) {
        return array();
    }

    $sql = "
        SELECT
            g.id AS source_group_id,
            g.source_group_uuid,
            g.group_match_status,
            g.group_match_confidence,
            g.primary_operational_source_id,
            g.primary_replay_source_id,
            e.garmin_entry_uuid,
            e.entry_date,
            e.aircraft_registration,
            e.generated_track_start_utc,
            e.generated_track_stop_utc,
            e.canonical_track_uuid,
            op.flight_data_log_uuid AS operational_log_uuid,
            op.data_log_type AS operational_data_log_type,
            op.validation_status AS operational_validation_status,
            op.source_filename AS operational_source_filename,
            op.valid_sample_count AS operational_valid_sample_count,
            op.file_size_bytes AS operational_file_size_bytes,
            op.csv_first_timestamp_utc AS operational_csv_start,
            op.csv_last_timestamp_utc AS operational_csv_end,
            op.source_role AS operational_source_role,
            rp.flight_data_log_uuid AS replay_log_uuid,
            rp.data_log_type AS replay_data_log_type,
            rp.validation_status AS replay_validation_status,
            rp.source_filename AS replay_source_filename,
            rp.valid_sample_count AS replay_valid_sample_count,
            rp.file_size_bytes AS replay_file_size_bytes,
            rp.csv_first_timestamp_utc AS replay_csv_start,
            rp.csv_last_timestamp_utc AS replay_csv_end,
            rp.source_role AS replay_source_role
        FROM ipca_garmin_source_groups g
        LEFT JOIN ipca_garmin_logbook_entries e ON e.id = g.garmin_logbook_entry_id
        LEFT JOIN ipca_garmin_flight_data_sources op ON op.id = g.primary_operational_source_id
        LEFT JOIN ipca_garmin_flight_data_sources rp ON rp.id = g.primary_replay_source_id
        WHERE " . implode(' OR ', array_map(static fn(string $clause): string => '(' . $clause . ')', $where)) . "
        ORDER BY
            (g.matched_flight_session_id IS NOT NULL) DESC,
            ABS(TIMESTAMPDIFF(SECOND, COALESCE(e.generated_track_start_utc, op.csv_first_timestamp_utc, rp.csv_first_timestamp_utc, e.entry_date), ?)) ASC,
            g.updated_at DESC
        LIMIT 6
    ";
    $params[] = $windowStart !== '' ? $windowStart : gmdate('Y-m-d H:i:s');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : array();
}

/**
 * @return array<string,mixed>|null
 */
function cockpit_admin_best_complete_garmin_match(PDO $pdo, string $aircraftRegistration, string $recordingStartedAt, float $durationSeconds): ?array
{
    foreach (array('ipca_garmin_csv_files', 'ipca_garmin_csv_flight_summaries') as $table) {
        if (!cockpit_admin_table_exists($pdo, $table)) {
            return null;
        }
    }
    $aircraftRegistration = strtoupper(trim($aircraftRegistration));
    $startTs = strtotime(trim($recordingStartedAt));
    if ($aircraftRegistration === '' || $startTs === false || $durationSeconds <= 0) {
        return null;
    }
    $windowStart = gmdate('Y-m-d H:i:s', $startTs);
    $windowEnd = gmdate('Y-m-d H:i:s', $startTs + (int)round($durationSeconds));
    $stmt = $pdo->prepare("
        SELECT
          f.id AS csv_file_id,
          f.csv_file_uuid,
          f.aircraft_registration,
          f.system_identifier,
          f.valid_row_count,
          s.departure_time_utc,
          s.arrival_time_utc,
          s.summary_json,
          ABS(TIMESTAMPDIFF(SECOND, s.departure_time_utc, ?)) AS start_delta_seconds,
          GREATEST(
            0,
            TIMESTAMPDIFF(
              SECOND,
              GREATEST(s.departure_time_utc, ?),
              LEAST(s.arrival_time_utc, ?)
            )
          ) AS overlap_seconds
        FROM ipca_garmin_csv_flight_summaries s
        INNER JOIN ipca_garmin_csv_files f ON f.id = s.csv_file_id
        WHERE s.derivation_status = 'ok'
          AND JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.status_label')) = 'Complete'
          AND UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.tail')), f.aircraft_registration, '')) = ?
          AND s.departure_time_utc BETWEEN DATE_SUB(?, INTERVAL 6 HOUR) AND DATE_ADD(?, INTERVAL 6 HOUR)
        ORDER BY overlap_seconds DESC, start_delta_seconds ASC, f.id DESC
        LIMIT 1
    ");
    $stmt->execute(array($windowStart, $windowStart, $windowEnd, $aircraftRegistration, $windowStart, $windowEnd));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    $summary = json_decode((string)($row['summary_json'] ?? '{}'), true);
    $row['summary'] = is_array($summary) ? $summary : array();
    $row['match_confidence'] = (float)min(1.0, max(0.25, ((int)($row['overlap_seconds'] ?? 0) / max(1.0, $durationSeconds))));
    return $row;
}

/**
 * @return list<array<string,mixed>>
 */
function cockpit_admin_garmin_flight_options(PDO $pdo, GarminCsvFlightSummaryService $summaryService): array
{
    foreach (array('ipca_garmin_logbook_entries', 'ipca_garmin_source_groups', 'ipca_garmin_flight_data_sources', 'ipca_garmin_csv_files') as $table) {
        if (!cockpit_admin_table_exists($pdo, $table)) {
            return array();
        }
    }

    $rows = $pdo->query("
        SELECT
            g.id AS source_group_id,
            g.source_group_uuid,
            g.group_match_status,
            e.garmin_entry_uuid,
            e.canonical_track_uuid,
            f.id AS csv_file_id,
            f.csv_file_uuid,
            f.aircraft_registration,
            f.original_filename,
            f.storage_path,
            f.import_profile,
            f.aircraft_ident,
            f.first_valid_sample_utc,
            f.last_valid_sample_utc,
            f.valid_row_count
        FROM ipca_garmin_source_groups g
        INNER JOIN ipca_garmin_logbook_entries e ON e.id = g.garmin_logbook_entry_id
        INNER JOIN ipca_garmin_flight_data_sources s
          ON s.id = COALESCE(g.primary_operational_source_id, g.primary_replay_source_id)
        INNER JOIN ipca_garmin_csv_files f ON f.id = s.garmin_csv_file_id
        WHERE e.deleted_at IS NULL
        ORDER BY COALESCE(f.first_valid_sample_utc, e.generated_track_start_utc, e.entry_date) DESC, g.id DESC
        LIMIT 200
    ");
    $rows = $rows !== false ? $rows->fetchAll(PDO::FETCH_ASSOC) : array();
    if (!is_array($rows)) {
        return array();
    }

    $options = array();
    foreach ($rows as $row) {
        $summary = $summaryService->summaryForCsvFile(array(
            'id' => (int)($row['csv_file_id'] ?? 0),
            'aircraft_registration' => (string)($row['aircraft_registration'] ?? ''),
            'aircraft_ident' => (string)($row['aircraft_ident'] ?? ''),
            'storage_path' => (string)($row['storage_path'] ?? ''),
            'import_profile' => (string)($row['import_profile'] ?? ''),
            'first_valid_sample_utc' => (string)($row['first_valid_sample_utc'] ?? ''),
            'last_valid_sample_utc' => (string)($row['last_valid_sample_utc'] ?? ''),
            'valid_row_count' => (int)($row['valid_row_count'] ?? 0),
        ));
        $row['summary'] = $summary;
        if ((string)($summary['status_label'] ?? '') !== 'Complete') {
            continue;
        }
        $row['dropdown_label'] = (string)$summary['label'] . ' · Hobbs ' . (string)$summary['hobbs_start_lt'] . '-' . (string)$summary['hobbs_end_lt'] . ' LT';
        $options[] = $row;
    }
    return $options;
}

/**
 * @return list<array<string,mixed>>
 */
function cockpit_admin_garmin_replay_options(PDO $pdo, int $limit = 100): array
{
    foreach (array('ipca_garmin_csv_files', 'ipca_garmin_csv_flight_summaries') as $table) {
        if (!cockpit_admin_table_exists($pdo, $table)) {
            return array();
        }
    }
    $limit = max(1, min(250, $limit));
    $rows = $pdo->query("
        SELECT
          f.id AS csv_file_id,
          f.csv_file_uuid,
          f.aircraft_registration,
          f.system_identifier,
          f.original_filename,
          f.file_size_bytes,
          f.valid_row_count,
          f.first_valid_sample_utc,
          f.last_valid_sample_utc,
          s.derivation_status,
          s.departure_time_utc,
          s.arrival_time_utc,
          s.summary_json,
          l.canonical_track_uuid,
          l.garmin_entry_uuid
        FROM ipca_garmin_csv_files f
        LEFT JOIN ipca_garmin_csv_flight_summaries s ON s.csv_file_id = f.id
        LEFT JOIN ipca_garmin_flight_data_track_links l ON l.garmin_csv_file_id = f.id
        WHERE f.storage_path IS NOT NULL
          AND f.storage_path <> ''
        ORDER BY COALESCE(s.departure_time_utc, f.first_valid_sample_utc, f.created_at) DESC, f.id DESC
        LIMIT {$limit}
    ");
    $rows = $rows !== false ? $rows->fetchAll(PDO::FETCH_ASSOC) : array();
    if (!is_array($rows)) {
        return array();
    }
    foreach ($rows as &$row) {
        $summary = json_decode((string)($row['summary_json'] ?? '{}'), true);
        $row['summary'] = is_array($summary) ? $summary : array();
    }
    unset($row);
    return $rows;
}

$error = trim((string)($_GET['error'] ?? ''));
$notice = '';
$pollRecordingId = 0;
$showDeleted = in_array(strtolower(trim((string)($_GET['show_deleted'] ?? ''))), array('1', 'true', 'yes'), true);
$recordingPerPage = (int)($_GET['per_page'] ?? 100);
if (!in_array($recordingPerPage, array(25, 50, 100, 250), true)) {
    $recordingPerPage = 100;
}
$recordingPage = max(1, (int)($_GET['page'] ?? 1));
$recordingOffset = ($recordingPage - 1) * $recordingPerPage;
$currentReturnParams = array(
    'page' => $recordingPage,
    'per_page' => $recordingPerPage,
);
if ($showDeleted) {
    $currentReturnParams['show_deleted'] = 1;
}
$currentReturn = '/admin/cockpit_recorder.php?' . http_build_query($currentReturnParams);
if ((string)($_GET['reconstruction'] ?? '') === 'started') {
    $pollRecordingId = (int)($_GET['id'] ?? 0);
    $notice = 'Reconstruction started in the background. Progress updates automatically in the recording details.';
}
if ((string)($_GET['g3x_upload'] ?? '') === 'attached') {
    $notice = 'Garmin CSV attached. Run reconstruction again for the updated replay.';
}
if (isset($_GET['recordings_hidden'])) {
    $notice = (int)$_GET['recordings_hidden'] . ' recording(s) hidden.';
}
if (isset($_GET['recordings_restored'])) {
    $notice = (int)$_GET['recordings_restored'] . ' recording(s) restored.';
}

$recordings = array();
$recordingCounts = array('total' => 0, 'active' => 0, 'hidden' => 0);
$recordingVisibleTotal = 0;
$recordingPageCount = 1;
$service = null;
$adsbService = null;
$garminSummaryService = new GarminCsvFlightSummaryService($pdo);
$garminReplayPayloadService = new GarminCsvReplayPayloadService($pdo);
$garminFlightOptions = array();
$garminFlightOptionsByGroup = array();
$garminReplayOptions = array();
$garminReplayPayloadsByCsvFileId = array();
$importProfileOptions = GarminCsvImportProfile::options();
try {
    $service = new CockpitRecorderService($pdo);
    $adsbService = new CockpitAdsbEnrichmentService($pdo);
    $recordingCounts = $service->adminRecordingCounts();
    $recordingVisibleTotal = $showDeleted ? (int)$recordingCounts['total'] : (int)$recordingCounts['active'];
    $recordingPageCount = max(1, (int)ceil($recordingVisibleTotal / max(1, $recordingPerPage)));
    if ($recordingPage > $recordingPageCount) {
        $recordingPage = $recordingPageCount;
        $recordingOffset = ($recordingPage - 1) * $recordingPerPage;
        $currentReturnParams['page'] = $recordingPage;
        $currentReturn = '/admin/cockpit_recorder.php?' . http_build_query($currentReturnParams);
    }
    $recordings = $service->adminRecordings($recordingPerPage, $showDeleted, $recordingOffset);
} catch (Throwable $e) {
    $error = $error !== '' ? $error : $e->getMessage();
}
try {
    $garminFlightOptions = cockpit_admin_garmin_flight_options($pdo, $garminSummaryService);
    $garminReplayOptions = cockpit_admin_garmin_replay_options($pdo, 100);
    $garminReplayPayloadsByCsvFileId = $garminReplayPayloadService->payloadsForCsvFileIds(array_map(
        static fn(array $row): int => (int)($row['csv_file_id'] ?? 0),
        $garminReplayOptions
    ));
    foreach ($garminFlightOptions as $garminFlightOption) {
        $garminFlightOptionsByGroup[(int)($garminFlightOption['source_group_id'] ?? 0)] = $garminFlightOption;
    }
} catch (Throwable $e) {
    $error = $error !== '' ? $error : 'Garmin flight options could not be loaded: ' . $e->getMessage();
}

cw_header('Cockpit Recordings');
?>
<style>
.cockpit-recorder-page{display:grid;gap:18px}.cockpit-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.cockpit-muted{color:#64748b;font-size:13px}.cockpit-table-wrap{overflow-x:auto}.cockpit-table{width:100%;border-collapse:collapse;min-width:1220px}.cockpit-table th,.cockpit-table td{border-bottom:1px solid #e2e8f0;padding:14px 10px;text-align:left;vertical-align:middle}.cockpit-table th{color:#475569;font-size:12px;text-transform:uppercase;letter-spacing:.04em}.cockpit-row-title{font-weight:800}.cockpit-row-sub{color:#64748b;font-size:12px;margin-top:3px}.cockpit-badge{display:inline-flex;border-radius:999px;padding:3px 9px;font-size:12px;font-weight:800;background:#e2e8f0;color:#334155}.cockpit-badge-ready{background:#dcfce7;color:#166534}.cockpit-badge-progress{background:#dbeafe;color:#1d4ed8}.cockpit-badge-failed{background:#fee2e2;color:#991b1b}.cockpit-badge-warning{background:#fef3c7;color:#92400e}.cockpit-button{border:0;border-radius:9px;background:#1d4ed8;color:#fff;font-weight:800;padding:8px 11px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px}.cockpit-button.secondary{background:#475569}.cockpit-button.subtle{background:#e2e8f0;color:#0f172a}.cockpit-actions-row{display:flex;flex-wrap:wrap;gap:7px}.cockpit-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px}.cockpit-notice{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:12px}.cockpit-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;border-radius:10px;padding:12px}.cockpit-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.56);display:none;z-index:9999;padding:30px;overflow:auto}.cockpit-modal-backdrop.is-open{display:block}.cockpit-modal{max-width:1120px;margin:0 auto;background:#fff;border-radius:18px;box-shadow:0 25px 70px rgba(15,23,42,.35);overflow:hidden}.cockpit-modal-header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;padding:20px 22px;border-bottom:1px solid #e2e8f0}.cockpit-modal-title{font-size:22px;font-weight:900;margin:0}.cockpit-modal-body{padding:20px 22px;display:grid;gap:16px}.cockpit-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.cockpit-kv{border:1px solid #e2e8f0;border-radius:12px;padding:12px;background:#f8fafc}.cockpit-label{color:#64748b;font-size:12px;text-transform:uppercase;letter-spacing:.04em}.cockpit-value{font-weight:800;margin-top:4px}.cockpit-section{border:1px solid #e2e8f0;border-radius:14px;padding:14px;display:grid;gap:10px}.cockpit-section h3{margin:0}.cockpit-transcript{white-space:pre-wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;max-height:360px;overflow:auto}.cockpit-audio{width:100%;max-width:520px}.cockpit-form-grid{display:grid;gap:8px;max-width:360px}.cockpit-input{border:1px solid #cbd5e1;border-radius:8px;padding:7px 8px;font:inherit;font-size:13px;background:#fff;color:#0f172a}.cockpit-link-grid{display:flex;flex-wrap:wrap;gap:7px 12px}.cockpit-recon-progress{display:grid;gap:8px;padding:10px;border:1px solid #bfdbfe;border-radius:10px;background:#eff6ff}.cockpit-recon-progress[hidden]{display:none!important}.cockpit-recon-progress-bar{height:10px;border-radius:999px;background:#dbeafe;overflow:hidden}.cockpit-recon-progress-fill{height:100%;width:0;background:linear-gradient(90deg,#2563eb,#1d4ed8);transition:width .35s ease}.cockpit-recon-progress-stage{font-size:12px;color:#1e3a8a}.cockpit-recon-progress-message{font-size:12px;color:#334155}.cockpit-recon-progress-times{display:flex;flex-wrap:wrap;gap:8px 12px;font-size:11px;color:#64748b}.cockpit-empty{padding:18px;border:1px dashed #cbd5e1;border-radius:12px;color:#64748b;background:#f8fafc}.cockpit-code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px;word-break:break-all}
.cockpit-bulk-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:end;justify-content:space-between;margin-bottom:12px}.cockpit-bulk-controls{display:flex;flex-wrap:wrap;gap:8px;align-items:end}.cockpit-pager{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;margin:10px 0 14px}.cockpit-pager-controls{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.cockpit-row-deleted{background:#f8fafc;color:#64748b}.cockpit-checkbox{width:16px;height:16px}.cockpit-danger{background:#b91c1c}.cockpit-filter-link{font-weight:800}
.cockpit-recording-time{display:grid;gap:3px}.cockpit-recording-date{font-weight:950;color:#0f172a;font-size:14px}.cockpit-recording-clock{font-weight:900;color:#1d4ed8;font-size:18px}.cockpit-recording-meta{color:#64748b;font-size:11px}.cockpit-pill-row{display:flex;flex-wrap:wrap;gap:5px;margin-top:6px}.cockpit-pill{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:11px;font-weight:900;background:#e2e8f0;color:#334155;white-space:nowrap}.cockpit-pill-good{background:#dcfce7;color:#166534}.cockpit-pill-warn{background:#fef3c7;color:#92400e}.cockpit-pill-bad{background:#fee2e2;color:#991b1b}.cockpit-pill-info{background:#dbeafe;color:#1d4ed8}.cockpit-match-card{border:1px solid #e2e8f0;border-radius:13px;background:#f8fafc;padding:9px;min-width:230px}.cockpit-match-card.good{border-color:#86efac;background:#f0fdf4}.cockpit-match-card.warn{border-color:#fde68a;background:#fffbeb}.cockpit-match-title{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;font-weight:900}.cockpit-match-main{font-weight:950;color:#0f172a;margin-top:3px}.cockpit-evidence-box{display:grid;gap:6px;min-width:160px}.cockpit-audio-actions{display:flex;flex-wrap:wrap;gap:7px;margin-top:5px}.cockpit-audio-actions a{font-weight:900}
</style>

<div class="cockpit-recorder-page">
  <section class="cockpit-card">
    <h2 style="margin-top:0">Cockpit Recordings</h2>
    <p class="cockpit-muted">A compact list of recordings. Use Details for transcript, evidence, reconstruction, ADS-B, downloads, and admin actions.</p>
    <div class="cockpit-info">
      <strong>Source-of-truth note:</strong> own phone/iPad AHRS is decommissioned for operational/replay truth. Garmin-derived evidence is the attitude/avionics source of truth. ADS-B is optional enrichment and must not block operational flight-record completion.
    </div>
    <p><a href="/admin/cockpit_recorder_aircraft.php">Manage aircraft / ADS-B devices</a> · <a href="/admin/adsb_traffic_archive.php">ADS-B Traffic Archive</a></p>
  </section>

  <?php if ($error !== ''): ?>
    <div class="cockpit-error"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if ($notice !== ''): ?>
    <div class="cockpit-notice"><?= h($notice) ?></div>
  <?php endif; ?>

  <section class="cockpit-card">
    <form id="cockpit-bulk-form" method="post" action="/admin/api/cockpit_recorder_bulk_action.php" data-bulk-form>
      <input type="hidden" name="return" value="<?= h($currentReturn) ?>">
      <div class="cockpit-bulk-bar">
        <div>
          <strong><?= $showDeleted ? 'All recordings, including hidden' : 'Active recordings' ?></strong>
          <div class="cockpit-muted">
            <?= (int)$recordingCounts['active'] ?> active · <?= (int)$recordingCounts['hidden'] ?> hidden · <?= (int)$recordingCounts['total'] ?> total.
            Hide keeps the evidence and replay data intact; it only removes recordings from the normal operational list.
          </div>
        </div>
        <div class="cockpit-bulk-controls">
          <label class="cockpit-muted">Reason
            <input class="cockpit-input" type="text" name="reason" maxlength="255" placeholder="test upload, duplicate, irrelevant">
          </label>
          <button class="cockpit-button cockpit-danger" type="submit" name="action" value="soft_delete">Hide selected</button>
          <?php if ($showDeleted): ?>
            <button class="cockpit-button secondary" type="submit" name="action" value="restore">Restore selected</button>
          <?php endif; ?>
          <a class="cockpit-filter-link" href="/admin/cockpit_recorder.php?<?= h(http_build_query($showDeleted ? array('per_page' => $recordingPerPage) : array('show_deleted' => 1, 'per_page' => $recordingPerPage))) ?>"><?= $showDeleted ? 'Show active only' : 'Show hidden too' ?></a>
        </div>
      </div>
    </form>
    <?php
      $recordingFirst = $recordingVisibleTotal > 0 ? $recordingOffset + 1 : 0;
      $recordingLast = min($recordingVisibleTotal, $recordingOffset + count($recordings));
      $previousParams = $currentReturnParams;
      $previousParams['page'] = max(1, $recordingPage - 1);
      $nextParams = $currentReturnParams;
      $nextParams['page'] = min($recordingPageCount, $recordingPage + 1);
    ?>
    <div class="cockpit-pager">
      <div class="cockpit-muted">
        Showing <?= (int)$recordingFirst ?>-<?= (int)$recordingLast ?> of <?= (int)$recordingVisibleTotal ?> <?= $showDeleted ? 'total' : 'active' ?> recordings.
      </div>
      <div class="cockpit-pager-controls">
        <form method="get" class="cockpit-pager-controls">
          <?php if ($showDeleted): ?><input type="hidden" name="show_deleted" value="1"><?php endif; ?>
          <input type="hidden" name="page" value="1">
          <label class="cockpit-muted">Rows
            <select class="cockpit-input" name="per_page" onchange="this.form.submit()">
              <?php foreach (array(25, 50, 100, 250) as $option): ?>
                <option value="<?= $option ?>"<?= $recordingPerPage === $option ? ' selected' : '' ?>><?= $option ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </form>
        <a class="cockpit-button subtle" href="/admin/cockpit_recorder.php?<?= h(http_build_query($previousParams)) ?>"<?= $recordingPage <= 1 ? ' aria-disabled="true"' : '' ?>>Previous</a>
        <span class="cockpit-muted">Page <?= (int)$recordingPage ?> of <?= (int)$recordingPageCount ?></span>
        <a class="cockpit-button subtle" href="/admin/cockpit_recorder.php?<?= h(http_build_query($nextParams)) ?>"<?= $recordingPage >= $recordingPageCount ? ' aria-disabled="true"' : '' ?>>Next</a>
      </div>
    </div>
    <div class="cockpit-table-wrap">
      <table class="cockpit-table">
        <thead>
          <tr>
            <th><input class="cockpit-checkbox" type="checkbox" data-select-all aria-label="Select all recordings"></th>
            <th>Date / Time</th>
            <th>Aircraft</th>
            <th>Duration</th>
            <th>Upload</th>
            <th>Transcript</th>
            <th>Garmin Match</th>
            <th>Replay</th>
            <th>Evidence</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$recordings): ?>
          <tr><td colspan="10" class="cockpit-empty">No cockpit recorder uploads yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($recordings as $row): ?>
          <?php
            $id = (int)($row['id'] ?? 0);
            $recordingUid = (string)($row['recording_uid'] ?? '');
            $upload = (string)($row['upload_status'] ?? '');
            $transcription = (string)($row['transcription_status'] ?? '');
            $reconStatus = (string)($row['reconstruction_status'] ?? 'not_started');
            $timelineStatus = (string)($row['timeline_status'] ?? 'not_started');
            $adsbStatus = (string)($row['adsb_status'] ?? 'not_started');
            $startedAt = (string)($row['started_at'] ?? $row['created_at'] ?? '');
            $uploadedAt = (string)($row['uploaded_at'] ?? '');
            $deletedAt = trim((string)($row['deleted_at'] ?? ''));
            $isDeleted = $deletedAt !== '';
            $audioUrl = '/admin/cockpit_recorder_audio.php?id=' . $id;
            $gpsUrl = '/admin/cockpit_recorder_gps.php?id=' . $id;
            $eventsUrl = '/admin/cockpit_recorder_events.php?id=' . $id;
            $transcript = trim((string)($row['transcript_text'] ?? ''));
            $rowError = trim((string)($row['error_message'] ?? ''));
            $isTestRecording = !empty($row['is_test_recording']);
            $flightSessionUid = trim((string)($row['flight_session_uid'] ?? ''));
            $flightSegmentIndex = max(1, (int)($row['flight_segment_index'] ?? 1));
            $previousSegmentUid = trim((string)($row['previous_segment_uid'] ?? ''));
            $sourceGapSummary = trim((string)($row['source_gap_summary'] ?? ''));
            $recordingEventCount = (int)($row['recording_event_count'] ?? 0);
            $recordingWarningCount = (int)($row['recording_warning_count'] ?? 0);
            $recordingErrorCount = (int)($row['recording_error_count'] ?? 0);
            $rowWarnings = array();
            try {
                $selectedGarmin = cockpit_admin_selected_garmin_sources(
                    $pdo,
                    $flightSessionUid,
                    (string)($row['aircraft_registration'] ?? ''),
                    $startedAt,
                    (float)($row['duration_seconds'] ?? 0)
                );
            } catch (Throwable $e) {
                $selectedGarmin = array('status' => 'error', 'message' => $e->getMessage());
                $rowWarnings[] = 'Garmin source lookup failed: ' . $e->getMessage();
            }
            try {
                $completeGarminMatch = cockpit_admin_best_complete_garmin_match(
                    $pdo,
                    (string)($row['aircraft_registration'] ?? ''),
                    $startedAt,
                    (float)($row['duration_seconds'] ?? 0)
                );
            } catch (Throwable $e) {
                $completeGarminMatch = null;
                $rowWarnings[] = 'Garmin match lookup failed: ' . $e->getMessage();
            }
            $selectedOperationalSource = is_array($selectedGarmin['operational'] ?? null) ? $selectedGarmin['operational'] : null;
            $selectedReplaySource = is_array($selectedGarmin['replay'] ?? null) ? $selectedGarmin['replay'] : null;
            $garminCandidates = isset($selectedGarmin['candidates']) && is_array($selectedGarmin['candidates']) ? $selectedGarmin['candidates'] : array();
            $hasAutoGarminSource = $selectedOperationalSource !== null || $selectedReplaySource !== null;
            $hasCompleteGarminMatch = is_array($completeGarminMatch);
            $selectedGarminGroupId = is_array($selectedGarmin['group'] ?? null) ? (int)($selectedGarmin['group']['source_group_id'] ?? 0) : 0;
            $health = cockpit_admin_decode_json_field($row, 'health_summary_json');
            $healthWarnings = isset($health['warnings']) && is_array($health['warnings']) ? $health['warnings'] : array();
            $healthAudio = isset($health['audio']) && is_array($health['audio']) ? $health['audio'] : array();
            $healthGps = isset($health['gps']) && is_array($health['gps']) ? $health['gps'] : array();
            try {
                $chunks = $service instanceof CockpitRecorderService ? $service->adminTranscriptionChunks($id) : array();
            } catch (Throwable $e) {
                $chunks = array();
                $rowWarnings[] = 'Transcript chunk lookup failed: ' . $e->getMessage();
            }
            $chunkReady = 0;
            $chunkFailed = 0;
            foreach ($chunks as $chunkForCount) {
                if ((string)($chunkForCount['status'] ?? '') === 'ready') {
                    $chunkReady++;
                } elseif ((string)($chunkForCount['status'] ?? '') === 'failed') {
                    $chunkFailed++;
                }
            }
            $reconSummary = cockpit_admin_decode_json_field($row, 'reconstruction_summary_json');
            $sourceAlignment = isset($reconSummary['source_alignment']) && is_array($reconSummary['source_alignment']) ? $reconSummary['source_alignment'] : array();
            $alignmentSources = isset($sourceAlignment['sources']) && is_array($sourceAlignment['sources']) ? $sourceAlignment['sources'] : array();
            $alignmentWarnings = isset($sourceAlignment['warnings']) && is_array($sourceAlignment['warnings']) ? $sourceAlignment['warnings'] : array();
            try {
                $defaultImportProfile = GarminCsvImportProfile::forAircraft(
                    (string)($row['aircraft_registration'] ?? ''),
                    (string)($row['aircraft_display_name'] ?? ''),
                    (string)($row['aircraft_type'] ?? '')
                );
            } catch (Throwable $e) {
                $defaultImportProfile = '';
                $rowWarnings[] = 'Import profile lookup failed: ' . $e->getMessage();
            }
            $replayUrl = '/admin/cockpit_recorder_replay.php?id=' . $id;
            $replayJsonV2Url = '/api/recordings/replay.php?id=' . $id . '&version=2';
            $debugBundleUrl = '/admin/cockpit_recorder_debug_bundle.php?id=' . $id;
            $g3xUrl = '/admin/cockpit_recorder_g3x.php?id=' . $id;
            try {
                $adsbDetail = $adsbService instanceof CockpitAdsbEnrichmentService ? $adsbService->statusForRecording($id) : array();
            } catch (Throwable $e) {
                $adsbDetail = array('status' => $adsbStatus, 'error_message' => $e->getMessage());
                $rowWarnings[] = 'ADS-B status lookup failed: ' . $e->getMessage();
            }
            $adsbDisplayStatus = (string)($adsbDetail['status'] ?? $adsbStatus);
            $adsbOwnshipCount = (int)($adsbDetail['ownship_sample_count'] ?? 0);
            $adsbError = trim((string)($adsbDetail['error_message'] ?? ''));
            $adsbRawUrl = '/admin/cockpit_recorder_adsb.php?id=' . $id . '&type=raw';
            $adsbNormalizedUrl = '/admin/cockpit_recorder_adsb.php?id=' . $id . '&type=normalized';
            $aircraftDisplay = trim((string)($row['aircraft_display_name'] ?: ($row['aircraft_registration'] ?? '')));
            $aircraftDisplay = $aircraftDisplay !== '' ? $aircraftDisplay : 'Not selected';
            $startedLocal = cockpit_admin_local_time_parts($startedAt);
            $uploadedLocal = cockpit_admin_local_time_parts($uploadedAt);
            $matchSummary = $hasCompleteGarminMatch && is_array($completeGarminMatch['summary'] ?? null) ? $completeGarminMatch['summary'] : array();
            $candidate = !$hasCompleteGarminMatch && isset($garminCandidates[0]) && is_array($garminCandidates[0]) ? $garminCandidates[0] : null;
            $candidateLocal = $candidate !== null ? cockpit_admin_local_time_parts(cockpit_admin_garmin_candidate_time($candidate)) : array('date' => '--', 'time' => '--', 'full' => '--');
          ?>
          <tr class="<?= $isDeleted ? 'cockpit-row-deleted' : '' ?>">
            <td><input class="cockpit-checkbox" type="checkbox" name="ids[]" value="<?= $id ?>" form="cockpit-bulk-form" data-recording-checkbox aria-label="Select recording <?= $id ?>"></td>
            <td>
              <div class="cockpit-recording-time">
                <div class="cockpit-recording-date"><?= h($startedLocal['date']) ?></div>
                <div class="cockpit-recording-clock"><?= h($startedLocal['time']) ?> <span class="cockpit-recording-meta">Local</span></div>
                <div class="cockpit-recording-meta">Uploaded <?= h($uploadedAt !== '' ? $uploadedLocal['full'] : 'not recorded') ?></div>
              </div>
              <?php if ($isTestRecording): ?><span class="cockpit-badge cockpit-badge-warning">TEST</span><?php endif; ?>
              <?php if ($isDeleted): ?><span class="cockpit-badge cockpit-badge-warning">Hidden</span><div class="cockpit-row-sub">Hidden <?= h($deletedAt) ?></div><?php endif; ?>
            </td>
            <td>
              <div class="cockpit-row-title"><?= h($aircraftDisplay) ?></div>
              <div class="cockpit-row-sub"><?= h((string)($row['aircraft_type'] ?? '')) ?></div>
              <div class="cockpit-pill-row">
                <?php if (!empty($row['aircraft_registration'])): ?><span class="cockpit-pill cockpit-pill-info"><?= h((string)$row['aircraft_registration']) ?></span><?php endif; ?>
                <?php if (!empty($row['aircraft_adsb_hex'])): ?><span class="cockpit-pill">ADS-B <?= h((string)$row['aircraft_adsb_hex']) ?></span><?php endif; ?>
              </div>
            </td>
            <td><div class="cockpit-recording-clock"><?= h(cockpit_admin_fmt_duration((float)($row['duration_seconds'] ?? 0))) ?></div><div class="cockpit-row-sub"><?= h(cockpit_admin_fmt_bytes((int)($row['file_size_bytes'] ?? 0))) ?></div></td>
            <td><span class="cockpit-pill <?= h(cockpit_admin_badge_class($upload) === 'cockpit-badge-ready' ? 'cockpit-pill-good' : (cockpit_admin_badge_class($upload) === 'cockpit-badge-failed' ? 'cockpit-pill-bad' : 'cockpit-pill-info')) ?>"><?= h($upload) ?></span></td>
            <td><span class="cockpit-pill <?= h(cockpit_admin_badge_class($transcription) === 'cockpit-badge-ready' ? 'cockpit-pill-good' : (cockpit_admin_badge_class($transcription) === 'cockpit-badge-failed' ? 'cockpit-pill-bad' : 'cockpit-pill-info')) ?>"><?= h($transcription) ?></span><div class="cockpit-row-sub"><?= (int)($row['transcription_progress'] ?? 0) ?>%</div></td>
            <td>
              <?php if ($hasCompleteGarminMatch): ?>
                <div class="cockpit-match-card good">
                  <div class="cockpit-match-title">Garmin flight allocated</div>
                  <div class="cockpit-match-main"><?= h((string)($matchSummary['tail'] ?? $completeGarminMatch['aircraft_registration'] ?? '')) ?> · <?= h((string)($matchSummary['dep_airport'] ?? '--')) ?>-<?= h((string)($matchSummary['arr_airport'] ?? '--')) ?></div>
                  <div class="cockpit-row-sub"><?= h((string)($matchSummary['dep_time_lt'] ?? '--')) ?>-<?= h((string)($matchSummary['arr_time_lt'] ?? '--')) ?> Local · <?= h((string)($matchSummary['elapsed_time'] ?? '--')) ?></div>
                  <div class="cockpit-pill-row"><span class="cockpit-pill cockpit-pill-good">Complete</span><span class="cockpit-pill">Confidence <?= h(number_format((float)($completeGarminMatch['match_confidence'] ?? 0), 2)) ?></span></div>
                </div>
              <?php elseif ($candidate !== null): ?>
                <div class="cockpit-match-card warn">
                  <div class="cockpit-match-title">Possible Garmin flight</div>
                  <div class="cockpit-match-main"><?= h(cockpit_admin_garmin_candidate_route($candidate)) ?></div>
                  <div class="cockpit-row-sub"><?= h($candidateLocal['full']) ?></div>
                  <div class="cockpit-pill-row">
                    <span class="cockpit-pill cockpit-pill-warn">Needs allocation</span>
                    <?php if (!empty($candidate['group_match_confidence'])): ?><span class="cockpit-pill">Confidence <?= h(number_format((float)$candidate['group_match_confidence'], 2)) ?></span><?php endif; ?>
                  </div>
                </div>
              <?php else: ?>
                <div class="cockpit-match-card warn">
                  <div class="cockpit-match-title">No complete Garmin flight</div>
                  <div class="cockpit-row-sub">No complete Garmin CSV flight is close enough to this audio recording yet.</div>
                  <div class="cockpit-pill-row"><span class="cockpit-pill cockpit-pill-warn">Not allocated</span></div>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <div class="cockpit-pill-row">
                <span class="cockpit-pill <?= h(cockpit_admin_badge_class($reconStatus) === 'cockpit-badge-ready' ? 'cockpit-pill-good' : (cockpit_admin_badge_class($reconStatus) === 'cockpit-badge-failed' ? 'cockpit-pill-bad' : 'cockpit-pill-info')) ?>" data-recon-badge="<?= $id ?>"><?= h($reconStatus) ?></span>
                <span class="cockpit-pill" data-timeline-badge="<?= $id ?>">Timeline <?= h($timelineStatus) ?></span>
              </div>
            </td>
            <td>
              <div class="cockpit-evidence-box">
                <div class="cockpit-pill-row">
                  <span class="cockpit-pill <?= !empty($row['storage_path']) ? 'cockpit-pill-good' : 'cockpit-pill-bad' ?>">Audio <?= !empty($row['storage_path']) ? 'saved' : 'missing' ?></span>
                  <span class="cockpit-pill <?= !empty($row['gps_storage_path']) ? 'cockpit-pill-good' : 'cockpit-pill-warn' ?>">GPS <?= !empty($row['gps_storage_path']) ? 'saved' : 'missing' ?></span>
                  <span class="cockpit-pill <?= $recordingErrorCount > 0 ? 'cockpit-pill-bad' : ($recordingWarningCount > 0 ? 'cockpit-pill-warn' : ($recordingEventCount > 0 ? 'cockpit-pill-info' : '')) ?>">Events <?= $recordingEventCount > 0 ? h((string)$recordingEventCount) : 'none' ?></span>
                  <span class="cockpit-pill <?= $hasCompleteGarminMatch ? 'cockpit-pill-good' : ($hasAutoGarminSource || !empty($row['g3x_storage_path']) ? 'cockpit-pill-warn' : '') ?>">Garmin <?= $hasCompleteGarminMatch ? 'complete' : ($hasAutoGarminSource ? 'legacy' : (!empty($row['g3x_storage_path']) ? 'manual' : 'not linked')) ?></span>
                </div>
                <?php if (!empty($row['storage_path'])): ?>
                  <div class="cockpit-audio-actions"><a href="<?= h($audioUrl) ?>">Play audio</a><a href="<?= h($audioUrl) ?>&download=1">Download</a></div>
                <?php endif; ?>
                <?php if (!empty($row['recording_events_storage_path'])): ?>
                  <div class="cockpit-row-sub">Operational events: <?= h((string)$recordingEventCount) ?> total, <?= h((string)$recordingWarningCount) ?> warnings, <?= h((string)$recordingErrorCount) ?> errors</div>
                <?php endif; ?>
              </div>
              <?php if ($rowWarnings): ?><div class="cockpit-row-sub">Details warning: <?= h($rowWarnings[0]) ?></div><?php endif; ?>
            </td>
            <td>
              <div class="cockpit-actions-row">
                <button class="cockpit-button" type="button" data-modal-open="recording-modal-<?= $id ?>">Details</button>
                <?php if (!$isDeleted): ?>
                  <a class="cockpit-button secondary" href="<?= h($replayUrl) ?>">Replay</a>
                  <form method="post" action="/admin/api/cockpit_recorder_reconstruct.php?id=<?= $id ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="replay_source_mode" value="g3x_only">
                    <button class="cockpit-button" type="submit"<?= $hasCompleteGarminMatch ? '' : ' disabled' ?>>Build/Update Replay</button>
                  </form>
                  <form method="post" action="/admin/api/cockpit_recorder_bulk_action.php" data-single-delete-form>
                    <input type="hidden" name="return" value="<?= h($currentReturn) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="reason" value="Hidden from cockpit recorder list">
                    <button class="cockpit-button cockpit-danger" type="submit" name="action" value="soft_delete">Hide</button>
                  </form>
                <?php else: ?>
                  <form method="post" action="/admin/api/cockpit_recorder_bulk_action.php">
                    <input type="hidden" name="return" value="<?= h($currentReturn) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button class="cockpit-button secondary" type="submit" name="action" value="restore">Restore</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>

          <tr class="cockpit-modal-host">
            <td colspan="10" style="padding:0;border:0">
              <div class="cockpit-modal-backdrop" id="recording-modal-<?= $id ?>" aria-hidden="true">
                <div class="cockpit-modal" role="dialog" aria-modal="true" aria-labelledby="recording-title-<?= $id ?>">
                  <div class="cockpit-modal-header">
                    <div>
                      <h2 class="cockpit-modal-title" id="recording-title-<?= $id ?>">Recording <?= h($recordingUid !== '' ? $recordingUid : (string)$id) ?></h2>
                      <div class="cockpit-muted"><?= h($startedAt) ?> · <?= h($aircraftDisplay) ?> · <?= h(cockpit_admin_fmt_duration((float)($row['duration_seconds'] ?? 0))) ?></div>
                    </div>
                    <button class="cockpit-button subtle" type="button" data-modal-close>Close</button>
                  </div>
                  <div class="cockpit-modal-body">
                    <div class="cockpit-grid">
                      <div class="cockpit-kv"><div class="cockpit-label">Upload</div><div class="cockpit-value"><span class="cockpit-badge <?= h(cockpit_admin_badge_class($upload)) ?>"><?= h($upload) ?></span></div></div>
                      <div class="cockpit-kv"><div class="cockpit-label">Transcript</div><div class="cockpit-value"><span class="cockpit-badge <?= h(cockpit_admin_badge_class($transcription)) ?>"><?= h($transcription) ?></span> <?= (int)($row['transcription_progress'] ?? 0) ?>%</div></div>
                      <div class="cockpit-kv"><div class="cockpit-label">Replay</div><div class="cockpit-value"><span class="cockpit-badge <?= h(cockpit_admin_badge_class($reconStatus)) ?>" data-recon-badge="<?= $id ?>"><?= h($reconStatus) ?></span></div></div>
                      <div class="cockpit-kv"><div class="cockpit-label">ADS-B</div><div class="cockpit-value"><span class="cockpit-badge <?= h(cockpit_admin_badge_class($adsbDisplayStatus)) ?>"><?= h($adsbDisplayStatus) ?></span></div></div>
                    </div>

                    <section class="cockpit-section">
                      <h3>Summary</h3>
                      <div class="cockpit-grid">
                        <div><strong>Recording ID</strong><div class="cockpit-code"><?= h($recordingUid) ?></div></div>
                        <div><strong>Input device</strong><div><?= h((string)($row['input_device'] ?? '')) ?></div></div>
                        <div><strong>Language</strong><div><?= h((string)($row['language'] ?? 'en')) ?></div></div>
                        <div><strong>File size</strong><div><?= h(cockpit_admin_fmt_bytes((int)($row['file_size_bytes'] ?? 0))) ?></div></div>
                      </div>
                      <?php if ($flightSessionUid !== '' || $flightSegmentIndex > 1 || $previousSegmentUid !== '' || $sourceGapSummary !== ''): ?>
                        <div class="cockpit-muted">
                          Session <?= h($flightSessionUid !== '' ? $flightSessionUid : $recordingUid) ?> · Segment <?= $flightSegmentIndex ?><?= $previousSegmentUid !== '' ? h(' after ' . $previousSegmentUid) : '' ?><?= $sourceGapSummary !== '' ? h(' · ' . $sourceGapSummary) : '' ?>
                        </div>
                      <?php endif; ?>
                    </section>

                    <section class="cockpit-section">
                      <h3>Audio</h3>
                      <?php if ($id > 0 && !empty($row['storage_path'])): ?>
                        <audio class="cockpit-audio" controls preload="none" src="<?= h($audioUrl) ?>"></audio>
                        <div class="cockpit-link-grid"><a href="<?= h($audioUrl) ?>&download=1">Download audio</a></div>
                      <?php else: ?>
                        <div class="cockpit-muted">No audio file available.</div>
                      <?php endif; ?>
                      <?php if ($healthAudio): ?>
                        <div class="cockpit-muted">Health duration: <?= h(cockpit_admin_fmt_duration((float)($healthAudio['duration_seconds'] ?? $row['duration_seconds'] ?? 0))) ?></div>
                      <?php endif; ?>
                    </section>

                    <section class="cockpit-section">
                      <h3>Transcript</h3>
                      <?php if ($rowError !== ''): ?>
                        <div class="cockpit-error"><?= h($rowError) ?></div>
                      <?php elseif ($transcript !== ''): ?>
                        <div class="cockpit-transcript"><?= h($transcript) ?></div>
                      <?php else: ?>
                        <div class="cockpit-muted">Transcript not ready.</div>
                      <?php endif; ?>
                      <?php if ($id > 0 && $transcription !== 'ready'): ?>
                        <div class="cockpit-actions-row">
                          <form method="post" action="/admin/api/cockpit_recorder_transcribe.php"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="mode" value="step"><button class="cockpit-button" type="submit">Process next chunk</button></form>
                          <form method="post" action="/admin/api/cockpit_recorder_transcribe.php"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="mode" value="run_steps"><button class="cockpit-button" type="submit">Process all chunks</button></form>
                          <form method="post" action="/admin/api/cockpit_recorder_transcribe.php"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="mode" value="spawn"><button class="cockpit-button secondary" type="submit">Restart worker</button></form>
                        </div>
                      <?php endif; ?>
                      <?php if ($chunks): ?>
                        <div class="cockpit-muted"><?= count($chunks) ?> chunks · <?= $chunkReady ?> ready<?= $chunkFailed > 0 ? ', ' . $chunkFailed . ' failed' : '' ?></div>
                      <?php endif; ?>
                    </section>

                    <section class="cockpit-section">
                      <h3>Garmin Source / Replay Evidence</h3>
                      <div class="cockpit-info">Normally populated automatically from IPCA Sync Agent uploads. Garmin files are the source of truth for attitude, avionics, and replay calculations.</div>
                      <div class="cockpit-form-grid" style="max-width:680px">
                        <label>Garmin uploaded flight
                          <select class="cockpit-input" name="garmin_source_group_id">
                            <?php if ($garminFlightOptions === array()): ?>
                              <option value="">No non-hidden Garmin flights available yet</option>
                            <?php else: ?>
                              <option value="">Choose a Garmin flight for verification</option>
                              <?php foreach ($garminFlightOptions as $garminOption): ?>
                                <?php
                                  $optionGroupId = (int)($garminOption['source_group_id'] ?? 0);
                                  $optionSelected = $selectedGarminGroupId > 0 && $optionGroupId === $selectedGarminGroupId;
                                ?>
                                <option value="<?= $optionGroupId ?>"<?= $optionSelected ? ' selected' : '' ?>>
                                  <?= h((string)($garminOption['dropdown_label'] ?? 'Garmin flight')) ?>
                                </option>
                              <?php endforeach; ?>
                            <?php endif; ?>
                          </select>
                        </label>
                        <div class="cockpit-muted">Read-only verification list for now. Labels are derived from the Garmin CSV header and rows, including Hobbs Start / End from the RPM-based calculation.</div>
                      </div>
                      <?php if ($hasAutoGarminSource): ?>
                        <div class="cockpit-grid">
                          <?php foreach (array('Operational source' => $selectedOperationalSource, 'Replay source' => $selectedReplaySource) as $sourceLabel => $sourceRow): ?>
                            <div class="cockpit-kv">
                              <div class="cockpit-label"><?= h($sourceLabel) ?></div>
                              <?php if (is_array($sourceRow)): ?>
                                <div class="cockpit-value"><?= h((string)($sourceRow['source_filename'] ?: $sourceRow['original_filename'] ?: $sourceRow['flight_data_log_uuid'] ?? 'Garmin source')) ?></div>
                                <div class="cockpit-muted">
                                  <?= h((string)($sourceRow['data_log_type'] ?? 'unknown')) ?>
                                  · <?= h((string)($sourceRow['validation_status'] ?? 'not validated')) ?>
                                  <?php if (!empty($sourceRow['valid_row_count'])): ?> · <?= number_format((int)$sourceRow['valid_row_count']) ?> rows<?php endif; ?>
                                  <?php if (!empty($sourceRow['csv_file_size_bytes'])): ?> · <?= h(cockpit_admin_fmt_bytes((int)$sourceRow['csv_file_size_bytes'])) ?><?php endif; ?>
                                </div>
                                <div class="cockpit-muted">Coverage <?= h(cockpit_admin_fmt_timestamp($sourceRow['csv_first_timestamp_utc'] ?? '')) ?> to <?= h(cockpit_admin_fmt_timestamp($sourceRow['csv_last_timestamp_utc'] ?? '')) ?></div>
                                <div class="cockpit-code"><?= h((string)($sourceRow['csv_file_uuid'] ?? $sourceRow['flight_data_log_uuid'] ?? '')) ?></div>
                              <?php else: ?>
                                <div class="cockpit-muted">No <?= h(strtolower($sourceLabel)) ?> selected yet.</div>
                              <?php endif; ?>
                            </div>
                          <?php endforeach; ?>
                        </div>
                        <?php if (is_array($selectedGarmin['group'] ?? null)): ?>
                          <div class="cockpit-muted">
                            Source group <?= h((string)($selectedGarmin['group']['source_group_uuid'] ?? '')) ?>
                            · Match <?= h((string)($selectedGarmin['group']['group_match_status'] ?? 'pending')) ?>
                            <?php if (is_numeric($selectedGarmin['group']['group_match_confidence'] ?? null)): ?> · confidence <?= h(number_format((float)$selectedGarmin['group']['group_match_confidence'], 2)) ?><?php endif; ?>
                          </div>
                        <?php endif; ?>
                      <?php else: ?>
                        <div class="cockpit-muted"><?= h((string)($selectedGarmin['message'] ?? 'No auto-linked Garmin source selected yet.')) ?></div>
                        <?php if (!empty($row['g3x_storage_path'])): ?>
                          <div class="cockpit-muted">Legacy manual CSV attached: <?= (int)($row['g3x_row_count'] ?? 0) ?> rows<?= !empty($row['g3x_file_size_bytes']) ? h(' · ' . cockpit_admin_fmt_bytes((int)$row['g3x_file_size_bytes'])) : '' ?><?= !empty($row['g3x_aircraft_ident']) ? h(' · ' . (string)$row['g3x_aircraft_ident']) : '' ?></div>
                        <?php endif; ?>
                      <?php endif; ?>
                      <div class="cockpit-section" style="background:#f8fafc">
                        <h3>Garmin Match Verification</h3>
                        <div class="cockpit-muted">Compact Garmin flight facts for manual verification. Use this to confirm the Garmin source approximately matches the cockpit recording before relying on derived Flight Record values.</div>
                        <?php if ($garminCandidates): ?>
                          <div class="cockpit-grid">
                            <?php foreach ($garminCandidates as $candidate): ?>
                              <?php
                                $candidateStart = (string)($candidate['generated_track_start_utc'] ?: $candidate['operational_csv_start'] ?: $candidate['replay_csv_start'] ?: '');
                                $candidateEnd = (string)($candidate['generated_track_stop_utc'] ?: $candidate['operational_csv_end'] ?: $candidate['replay_csv_end'] ?: '');
                                $candidateOperationalSelected = !empty($candidate['primary_operational_source_id']);
                                $candidateReplaySelected = !empty($candidate['primary_replay_source_id']);
                                $candidateRows = (int)($candidate['operational_valid_sample_count'] ?: $candidate['replay_valid_sample_count'] ?: 0);
                                $candidateBytes = (int)($candidate['operational_file_size_bytes'] ?: $candidate['replay_file_size_bytes'] ?: 0);
                                $candidateOption = $garminFlightOptionsByGroup[(int)($candidate['source_group_id'] ?? 0)] ?? null;
                                $candidateSummary = is_array($candidateOption) && is_array($candidateOption['summary'] ?? null) ? $candidateOption['summary'] : array();
                              ?>
                              <div class="cockpit-kv">
                                <div class="cockpit-label">Garmin flight <?= h((string)($candidateSummary['date_label'] ?? cockpit_admin_fmt_date($candidate['entry_date'] ?: $candidateStart))) ?></div>
                                <div class="cockpit-value"><?= h((string)($candidateSummary['tail'] ?? $candidate['aircraft_registration'] ?: 'Unknown aircraft')) ?></div>
                                <div class="cockpit-muted">
                                  <?= h((string)($candidateSummary['dep_airport'] ?? '--')) ?> <?= h((string)($candidateSummary['dep_time_lt'] ?? cockpit_admin_fmt_timestamp($candidateStart))) ?> LT
                                  - <?= h((string)($candidateSummary['arr_airport'] ?? '--')) ?> <?= h((string)($candidateSummary['arr_time_lt'] ?? cockpit_admin_fmt_timestamp($candidateEnd))) ?> LT
                                  · ET <?= h((string)($candidateSummary['elapsed_time'] ?? cockpit_admin_fmt_hours_between($candidateStart, $candidateEnd))) ?>
                                </div>
                                <div class="cockpit-muted">
                                  Hobbs <?= h((string)($candidateSummary['hobbs_start_lt'] ?? '--')) ?> - <?= h((string)($candidateSummary['hobbs_end_lt'] ?? '--')) ?> LT
                                  · <?= h((string)($candidateSummary['hobbs_hours'] ?? '--')) ?>
                                </div>
                                <div class="cockpit-muted">
                                  Operational: <?= h((string)($candidate['operational_data_log_type'] ?? 'none')) ?>
                                  · Replay: <?= h((string)($candidate['replay_data_log_type'] ?? 'none')) ?>
                                </div>
                                <div class="cockpit-muted">
                                  <?= $candidateRows > 0 ? h(number_format($candidateRows) . ' rows') : 'Rows unknown' ?>
                                  <?= $candidateBytes > 0 ? h(' · ' . cockpit_admin_fmt_bytes($candidateBytes)) : '' ?>
                                </div>
                                <div class="cockpit-muted">
                                  Match <?= h((string)($candidate['group_match_status'] ?? 'pending')) ?>
                                  <?php if (is_numeric($candidate['group_match_confidence'] ?? null)): ?> · confidence <?= h(number_format((float)$candidate['group_match_confidence'], 2)) ?><?php endif; ?>
                                </div>
                                <div>
                                  <?php if ($candidateOperationalSelected): ?><span class="cockpit-badge cockpit-badge-ready">PRIMARY_OPERATIONAL</span><?php endif; ?>
                                  <?php if ($candidateReplaySelected): ?><span class="cockpit-badge cockpit-badge-progress">PRIMARY_REPLAY</span><?php endif; ?>
                                </div>
                                <div class="cockpit-code"><?= h((string)($candidate['garmin_entry_uuid'] ?: $candidate['canonical_track_uuid'] ?: $candidate['source_group_uuid'] ?: '')) ?></div>
                              </div>
                            <?php endforeach; ?>
                          </div>
                        <?php else: ?>
                          <div class="cockpit-muted">No nearby Garmin candidate flights were found for this recording/session window.</div>
                        <?php endif; ?>
                      </div>
                      <details>
                        <summary><strong>Manual correction: attach or replace Garmin CSV</strong></summary>
                        <div class="cockpit-muted">Use this only if the Sync Agent did not auto-match the correct Garmin source. Import type is preserved for simulator, C172SP, and other Garmin profiles.</div>
                        <form class="cockpit-form-grid" method="post" action="/admin/api/cockpit_recorder_g3x_upload.php" enctype="multipart/form-data">
                          <input type="hidden" name="id" value="<?= $id ?>">
                          <label>Import type<select class="cockpit-input" name="import_profile">
                            <?php foreach ($importProfileOptions as $profile => $label): ?>
                              <option value="<?= h($profile) ?>"<?= $profile === $defaultImportProfile ? ' selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                          </select></label>
                          <label>Garmin CSV file<input class="cockpit-input" type="file" name="g3x_csv" accept=".csv,text/csv" required></label>
                          <button class="cockpit-button" type="submit">Manually attach / replace Garmin source</button>
                        </form>
                      </details>
                      <div class="cockpit-link-grid">
                        <?php if (!empty($row['g3x_storage_path'])): ?><a href="<?= h($g3xUrl) ?>">Download manual Garmin CSV</a><?php endif; ?>
                        <a href="<?= h($debugBundleUrl) ?>">Debug bundle</a>
                      </div>
                    </section>

                    <section class="cockpit-section">
                      <h3>Reconstruction / Replay</h3>
                      <div class="cockpit-recon-progress" id="recon-progress-<?= $id ?>" data-recording-id="<?= $id ?>" <?php if ($reconStatus !== 'processing' && $pollRecordingId !== $id): ?>hidden<?php endif; ?>>
                        <div class="cockpit-recon-progress-bar" aria-hidden="true"><div class="cockpit-recon-progress-fill" data-recon-fill="<?= $id ?>" style="width:0%"></div></div>
                        <strong class="cockpit-recon-progress-stage" data-recon-stage="<?= $id ?>">Starting reconstruction…</strong>
                        <div class="cockpit-recon-progress-message" data-recon-message="<?= $id ?>">Waiting for worker…</div>
                        <div class="cockpit-recon-progress-times"><span data-recon-elapsed="<?= $id ?>">Elapsed: --</span><span data-recon-updated="<?= $id ?>">Last update: --</span></div>
                        <button class="cockpit-button" type="button" data-recon-cancel="<?= $id ?>">Cancel Reconstruction</button>
                        <div class="cockpit-badge cockpit-badge-progress" data-recon-stale="<?= $id ?>" hidden>Still processing this stage</div>
                      </div>
                      <?php if ($reconSummary): ?>
                        <div class="cockpit-grid">
                          <div><strong>Samples</strong><div><?= number_format((int)($reconSummary['sample_count'] ?? 0)) ?></div></div>
                          <div><strong>Phases / Events</strong><div><?= (int)($reconSummary['phase_count'] ?? 0) ?> / <?= (int)($reconSummary['event_count'] ?? 0) ?></div></div>
                          <div><strong>Max altitude</strong><div><?= is_numeric($reconSummary['max_altitude_ft'] ?? null) ? h(number_format((float)$reconSummary['max_altitude_ft'], 0) . ' ft') : '--' ?></div></div>
                          <div><strong>Max GS</strong><div><?= is_numeric($reconSummary['max_groundspeed_kt'] ?? null) ? h(number_format((float)$reconSummary['max_groundspeed_kt'], 1) . ' kt') : '--' ?></div></div>
                        </div>
                        <?php if ($alignmentSources || $alignmentWarnings): ?>
                          <div class="cockpit-muted">Source alignment currently reports legacy evidence availability. Garmin remains authoritative for attitude/replay state.</div>
                          <?php foreach ($alignmentWarnings as $warning): ?><div class="cockpit-muted"><?= h((string)$warning) ?></div><?php endforeach; ?>
                        <?php endif; ?>
                      <?php endif; ?>
                      <form class="cockpit-form-grid" method="post" action="/admin/api/cockpit_recorder_reconstruct.php?id=<?= $id ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="replay_source_mode" value="g3x_only">
                        <div class="cockpit-muted">Manual fallback only. Normal replay/Flight Record derivation should come from the selected Garmin source uploaded by the Sync Agent.</div>
                        <button class="cockpit-button" type="submit">Manually reconstruct replay</button>
                      </form>
                      <div class="cockpit-link-grid">
                        <a href="<?= h($replayUrl) ?>">Open replay player</a>
                        <a href="<?= h($replayJsonV2Url) ?>">Replay JSON v2</a>
                      </div>
                    </section>

                    <section class="cockpit-section">
                      <h3>GPS And ADS-B</h3>
                      <div class="cockpit-info">ADS-B is optional replay/debrief enrichment. Per the workflow expansion, it must be replaced by a provider adapter/job layer before it is considered production-grade, and it does not block operational record finalization.</div>
                      <div class="cockpit-grid">
                        <div><strong>Phone GPS evidence</strong><div><?= !empty($row['gps_storage_path']) ? h((int)($row['gps_sample_count'] ?? 0) . ' samples · ' . cockpit_admin_fmt_bytes((int)($row['gps_file_size_bytes'] ?? 0))) : 'No GPS JSON uploaded' ?></div><?php if (!empty($row['gps_storage_path'])): ?><a href="<?= h($gpsUrl) ?>">Download GPS JSON</a><?php endif; ?></div>
                        <div><strong>ADS-B status</strong><div><span class="cockpit-badge <?= h(cockpit_admin_badge_class($adsbDisplayStatus)) ?>"><?= h($adsbDisplayStatus) ?></span></div><div class="cockpit-muted">Ownship samples: <?= $adsbOwnshipCount ?></div></div>
                      </div>
                      <?php if ($adsbError !== ''): ?><div class="cockpit-error"><?= h($adsbError) ?></div><?php endif; ?>
                      <div class="cockpit-actions-row">
                        <?php if (!empty($row['aircraft_adsb_hex'])): ?>
                          <form method="post" action="/admin/api/cockpit_recorder_adsb_enrich.php"><input type="hidden" name="id" value="<?= $id ?>"><button class="cockpit-button secondary" type="submit">Fetch legacy ADS-B enrichment</button></form>
                        <?php endif; ?>
                        <?php if (!empty($adsbDetail['raw_storage_path'])): ?><a href="<?= h($adsbRawUrl) ?>">Raw ADS-B JSON</a><?php endif; ?>
                        <?php if (!empty($adsbDetail['normalized_storage_path'])): ?><a href="<?= h($adsbNormalizedUrl) ?>">Normalized ADS-B JSON</a><?php endif; ?>
                      </div>
                    </section>

                    <section class="cockpit-section">
                      <h3>Recording Event Log</h3>
                      <?php if (!empty($row['recording_events_storage_path'])): ?>
                        <div class="cockpit-grid">
                          <div><strong>Total events</strong><div><?= h((string)$recordingEventCount) ?></div></div>
                          <div><strong>Warnings</strong><div><?= h((string)$recordingWarningCount) ?></div></div>
                          <div><strong>Errors</strong><div><?= h((string)$recordingErrorCount) ?></div></div>
                        </div>
                        <?php if ($sourceGapSummary !== ''): ?><div class="cockpit-error"><?= h($sourceGapSummary) ?></div><?php endif; ?>
                        <div class="cockpit-link-grid">
                          <a href="<?= h($eventsUrl) ?>">Download events.json</a>
                        </div>
                      <?php else: ?>
                        <div class="cockpit-muted">No structured event sidecar was uploaded for this recording.</div>
                      <?php endif; ?>
                    </section>
                  </div>
                </div>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <details class="cockpit-card">
    <summary><strong>Advanced / Garmin-only Replay Test</strong> <span class="cockpit-muted">CSV-only replay builds from IPCA Sync Agent evidence; audio recordings remain the primary view above.</span></summary>
    <div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap;margin-top:14px">
      <div>
        <h3 style="margin:0">Garmin Flights Available for Replay</h3>
        <p class="cockpit-muted">Manual test tool for standalone Garmin CSV replay payloads. Use the Garmin dashboard for normal bulk rebuilds.</p>
      </div>
      <a class="cockpit-button secondary" href="/admin/flight_log_garmin_connection.php">Garmin Dashboard</a>
    </div>
    <?php if ($garminReplayOptions === array()): ?>
      <div class="cockpit-empty">No Garmin CSV evidence is available for replay yet.</div>
    <?php else: ?>
      <div class="cockpit-table-wrap">
        <table class="cockpit-table">
          <thead>
            <tr>
              <th>Date / Time</th>
              <th>Aircraft</th>
              <th>Route</th>
              <th>Hobbs</th>
              <th>Tacho</th>
              <th>Evidence</th>
              <th>Replay Payload</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($garminReplayOptions as $garminReplay): ?>
            <?php
              $summary = is_array($garminReplay['summary'] ?? null) ? $garminReplay['summary'] : array();
              $tail = (string)($summary['tail'] ?? $garminReplay['aircraft_registration'] ?? 'Unknown tail');
              $dateLabel = (string)($summary['date_label'] ?? cockpit_admin_fmt_date($garminReplay['departure_time_utc'] ?? $garminReplay['first_valid_sample_utc'] ?? ''));
              $dep = (string)($summary['dep_airport'] ?? '--');
              $arr = (string)($summary['arr_airport'] ?? '--');
              $depTime = (string)($summary['dep_time_lt'] ?? cockpit_admin_fmt_timestamp($garminReplay['departure_time_utc'] ?? ''));
              $arrTime = (string)($summary['arr_time_lt'] ?? cockpit_admin_fmt_timestamp($garminReplay['arrival_time_utc'] ?? ''));
              $csvFileId = (int)($garminReplay['csv_file_id'] ?? 0);
              $payloadRow = $csvFileId > 0 && isset($garminReplayPayloadsByCsvFileId[$csvFileId]) ? $garminReplayPayloadsByCsvFileId[$csvFileId] : null;
              $payloadReady = is_array($payloadRow) && (string)($payloadRow['build_status'] ?? '') === 'ready' && trim((string)($payloadRow['replay_key'] ?? '')) !== '';
            ?>
            <tr>
              <td>
                <div class="cockpit-row-title"><?= h($dateLabel) ?></div>
                <div class="cockpit-row-sub"><?= h($depTime) ?> LT - <?= h($arrTime) ?> LT · <?= h((string)($summary['elapsed_time'] ?? '--')) ?></div>
              </td>
              <td>
                <div class="cockpit-row-title"><?= h($tail) ?></div>
                <div class="cockpit-row-sub"><?= h((string)($garminReplay['system_identifier'] ?? '')) ?></div>
              </td>
              <td><?= h($dep) ?> - <?= h($arr) ?></td>
              <td>
                <div class="cockpit-row-sub">Out <?= h((string)($summary['hobbs_out'] ?? '--')) ?> · In <?= h((string)($summary['hobbs_in'] ?? '--')) ?></div>
                <strong><?= h((string)($summary['hobbs_time'] ?? '--')) ?></strong>
              </td>
              <td>
                <div class="cockpit-row-sub">Out <?= h((string)($summary['tacho_out'] ?? '--')) ?> · In <?= h((string)($summary['tacho_in'] ?? '--')) ?></div>
                <strong><?= h((string)($summary['tacho_time'] ?? '--')) ?></strong>
              </td>
              <td>
                <div class="cockpit-row-sub"><?= number_format((int)($garminReplay['valid_row_count'] ?? 0)) ?> rows · <?= h(cockpit_admin_fmt_bytes((int)($garminReplay['file_size_bytes'] ?? 0))) ?></div>
                <div class="cockpit-code"><?= h((string)($garminReplay['canonical_track_uuid'] ?: $garminReplay['garmin_entry_uuid'] ?: $garminReplay['csv_file_uuid'] ?? '')) ?></div>
              </td>
              <td>
                <?php if ($payloadReady): ?>
                  <span class="cockpit-badge cockpit-badge-ready">ready</span>
                  <div class="cockpit-row-sub"><?= number_format((int)($payloadRow['sample_count'] ?? 0)) ?> samples</div>
                <?php elseif (is_array($payloadRow) && (string)($payloadRow['build_status'] ?? '') === 'failed'): ?>
                  <span class="cockpit-badge cockpit-badge-failed">failed</span>
                  <div class="cockpit-row-sub"><?= h((string)($payloadRow['last_error'] ?? '')) ?></div>
                <?php else: ?>
                  <span class="cockpit-badge cockpit-badge-progress">not built</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="cockpit-actions-row">
                  <?php if ($payloadReady): ?><a class="cockpit-button secondary" href="/admin/cockpit_recorder_replay.php?standalone=<?= h((string)$payloadRow['replay_key']) ?>">Open</a><?php endif; ?>
                  <form method="post" action="/admin/api/cockpit_recorder_garmin_replay_action.php">
                    <input type="hidden" name="csv_file_id" value="<?= $csvFileId ?>">
                    <?php if ($payloadReady): ?><input type="hidden" name="force" value="1"><?php endif; ?>
                    <button class="cockpit-button" type="submit"><?= $payloadReady ? 'Rebuild' : 'Build' ?> Replay</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </details>
</div>

<script>
(function () {
  const stageLabels = {
    queued: 'Queued',
    loading_raw: 'Loading Garmin source',
    building_canonical_samples: 'Building canonical samples',
    detecting_phases_events: 'Detecting phases and events',
    computing_derived_values: 'Computing derived values',
    building_replay_v2: 'Building replay v2 fixed timeline',
    inserting_replay_v2_samples: 'Inserting replay v2 samples',
    finalizing: 'Finalizing',
    ready: 'Ready',
    failed: 'Failed',
    cancelled: 'Cancelled'
  };
  const pollMs = 3000;
  const timers = new Map();

  function formatDuration(totalSeconds) {
    const seconds = Math.max(0, Math.floor(Number(totalSeconds) || 0));
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`;
  }
  function formatTimestamp(value) {
    if (!value) return '--';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
  }
  function stageLabel(stage) {
    return stageLabels[stage] || (stage ? stage.replace(/_/g, ' ') : 'Processing');
  }
  function updatePanel(recordingId, payload) {
    const panel = document.getElementById('recon-progress-' + recordingId);
    const badges = document.querySelectorAll('[data-recon-badge="' + recordingId + '"]');
    const timelineBadges = document.querySelectorAll('[data-timeline-badge="' + recordingId + '"]');
    const fill = document.querySelector('[data-recon-fill="' + recordingId + '"]');
    const stageEl = document.querySelector('[data-recon-stage="' + recordingId + '"]');
    const messageEl = document.querySelector('[data-recon-message="' + recordingId + '"]');
    const elapsedEl = document.querySelector('[data-recon-elapsed="' + recordingId + '"]');
    const updatedEl = document.querySelector('[data-recon-updated="' + recordingId + '"]');
    const staleEl = document.querySelector('[data-recon-stale="' + recordingId + '"]');
    const cancelBtn = document.querySelector('[data-recon-cancel="' + recordingId + '"]');
    if (!panel) return;

    const job = payload && payload.job ? payload.job : null;
    const reconstructionStatus = payload ? payload.reconstruction_status : '';
    const timelineStatus = payload ? payload.timeline_status : '';
    const active = reconstructionStatus === 'processing' || (job && ['queued', 'processing'].includes(job.status));
    const terminalVisible = job && ['failed', 'cancelled'].includes(job.status);

    panel.hidden = !active && !terminalVisible && reconstructionStatus !== 'processing';
    badges.forEach((badge) => {
      if (reconstructionStatus) {
        badge.textContent = reconstructionStatus;
        badge.className = 'cockpit-badge cockpit-badge-' + reconstructionStatus;
      }
    });
    timelineBadges.forEach((badge) => {
      if (timelineStatus) badge.textContent = timelineStatus;
    });
    if (cancelBtn) {
      cancelBtn.hidden = !active;
      cancelBtn.disabled = false;
    }
    if (!job) {
      if (stageEl) stageEl.textContent = 'Waiting for reconstruction job...';
      if (messageEl) messageEl.textContent = 'Worker has not reported progress yet.';
      return;
    }
    const percent = Math.max(0, Math.min(100, Number(job.progress_percent) || 0));
    if (fill) fill.style.width = percent + '%';
    if (stageEl) stageEl.textContent = stageLabel(job.progress_stage) + ' · ' + percent + '%';
    if (messageEl) messageEl.textContent = job.progress_message || 'Working...';
    if (elapsedEl) elapsedEl.textContent = 'Elapsed: ' + formatDuration(job.elapsed_seconds);
    if (updatedEl) updatedEl.textContent = 'Last update: ' + formatTimestamp(job.updated_at);
    if (staleEl) staleEl.hidden = !job.stale || job.status === 'failed';
    if (job.status === 'ready' || job.status === 'failed' || job.status === 'cancelled' || reconstructionStatus === 'ready' || reconstructionStatus === 'failed') {
      stopPolling(recordingId);
      if (job.status === 'ready' || reconstructionStatus === 'ready') {
        panel.hidden = true;
      }
    }
  }
  async function cancelReconstruction(recordingId, button) {
    if (!recordingId || !window.confirm('Cancel reconstruction for this recording?')) return;
    if (button) {
      button.disabled = true;
      button.textContent = 'Cancelling...';
    }
    try {
      const body = new URLSearchParams();
      body.set('id', recordingId);
      const response = await fetch('/admin/api/cockpit_recorder_cancel_reconstruction.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
        cache: 'no-store'
      });
      const payload = await response.json();
      if (!payload || !payload.ok) throw new Error((payload && payload.error) || 'Could not cancel reconstruction.');
      pollRecording(recordingId);
    } catch (error) {
      if (button) {
        button.disabled = false;
        button.textContent = 'Cancel Reconstruction';
      }
      window.alert(error.message || 'Could not cancel reconstruction.');
    }
  }
  async function pollRecording(recordingId) {
    try {
      const response = await fetch('/admin/api/cockpit_recorder_reconstruction_status.php?id=' + encodeURIComponent(recordingId), {
        headers: { 'Accept': 'application/json' },
        cache: 'no-store'
      });
      const payload = await response.json();
      if (!payload || !payload.ok) return;
      updatePanel(recordingId, payload);
    } catch (error) {
      console.warn('Reconstruction status poll failed for recording ' + recordingId, error);
    }
  }
  function stopPolling(recordingId) {
    const timer = timers.get(recordingId);
    if (timer) {
      clearInterval(timer);
      timers.delete(recordingId);
    }
  }
  function startPolling(recordingId) {
    if (!recordingId || timers.has(recordingId)) return;
    pollRecording(recordingId);
    timers.set(recordingId, setInterval(function () { pollRecording(recordingId); }, pollMs));
  }

  const selectAll = document.querySelector('[data-select-all]');
  const rowCheckboxes = Array.from(document.querySelectorAll('[data-recording-checkbox]'));
  if (selectAll) {
    selectAll.addEventListener('change', () => {
      rowCheckboxes.forEach((checkbox) => {
        checkbox.checked = selectAll.checked;
      });
    });
  }
  document.querySelectorAll('[data-bulk-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      const submitter = event.submitter;
      const action = submitter && submitter.value ? String(submitter.value) : '';
      const selectedCount = rowCheckboxes.filter((checkbox) => checkbox.checked).length;
      if (selectedCount <= 0) {
        event.preventDefault();
        window.alert('Select at least one recording first.');
        return;
      }
      if (action === 'soft_delete' && !window.confirm(`Hide ${selectedCount} selected recording(s)? Evidence files and replay data will be preserved.`)) {
        event.preventDefault();
      }
    });
  });
  document.querySelectorAll('[data-single-delete-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm('Hide this recording from the normal list? Evidence files and replay data will be preserved.')) {
        event.preventDefault();
      }
    });
  });

  document.querySelectorAll('[data-modal-open]').forEach((button) => {
    button.addEventListener('click', () => {
      const modal = document.getElementById(button.getAttribute('data-modal-open'));
      if (modal) {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
      }
    });
  });
  document.querySelectorAll('[data-modal-close]').forEach((button) => {
    button.addEventListener('click', () => {
      const modal = button.closest('.cockpit-modal-backdrop');
      if (modal) {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
      }
    });
  });
  document.querySelectorAll('.cockpit-modal-backdrop').forEach((modal) => {
    modal.addEventListener('click', (event) => {
      if (event.target === modal) {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
      }
    });
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      document.querySelectorAll('.cockpit-modal-backdrop.is-open').forEach((modal) => {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
      });
    }
  });
  document.querySelectorAll('.cockpit-recon-progress[data-recording-id]').forEach(function (panel) {
    if (panel.hidden) return;
    const recordingId = panel.getAttribute('data-recording-id');
    if (recordingId) startPolling(recordingId);
  });
  document.querySelectorAll('[data-recon-cancel]').forEach(function (button) {
    button.addEventListener('click', function () {
      cancelReconstruction(button.getAttribute('data-recon-cancel'), button);
    });
  });
  <?php if ($pollRecordingId > 0): ?>
  startPolling(String(<?= $pollRecordingId ?>));
  <?php endif; ?>
})();
</script>

<?php
cw_footer();
