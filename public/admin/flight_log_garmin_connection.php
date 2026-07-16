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

$hasTokens = garmin_sync_table_exists($pdo, 'ipca_sync_agent_tokens');
$hasAcks = garmin_sync_table_exists($pdo, 'ipca_sync_agent_upload_acknowledgments');
$hasTracks = garmin_sync_table_exists($pdo, 'ipca_garmin_normalized_track_artifacts');
$hasSources = garmin_sync_table_exists($pdo, 'ipca_garmin_flight_data_sources');
$hasCsvFiles = garmin_sync_table_exists($pdo, 'ipca_garmin_csv_files');
$hasEntries = garmin_sync_table_exists($pdo, 'ipca_garmin_logbook_entries');
$hasSourceGroups = garmin_sync_table_exists($pdo, 'ipca_garmin_source_groups');
$hasCsvSummaries = garmin_sync_table_exists($pdo, 'ipca_garmin_csv_flight_summaries');
$hasTrackSummaries = garmin_sync_table_exists($pdo, 'ipca_garmin_track_flight_summaries');
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
      COUNT(s.csv_file_id) AS summarized_csv_files,
      SUM(CASE WHEN s.csv_file_id IS NULL THEN 1 ELSE 0 END) AS missing_summaries
    FROM ipca_garmin_csv_files f
    " . ($hasCsvSummaries ? "LEFT JOIN ipca_garmin_csv_flight_summaries s ON s.csv_file_id = f.id" : "LEFT JOIN (SELECT NULL AS csv_file_id) s ON 1 = 0") . "
") : array('total_csv_files' => 0, 'summarized_csv_files' => 0, 'missing_summaries' => 0);

$trackSummaryStats = $hasTracks ? garmin_sync_row($pdo, "
    SELECT
      COUNT(t.id) AS total_track_artifacts,
      COUNT(s.track_artifact_id) AS summarized_track_artifacts,
      SUM(CASE WHEN s.track_artifact_id IS NULL THEN 1 ELSE 0 END) AS missing_track_summaries
    FROM ipca_garmin_normalized_track_artifacts t
    " . ($hasTrackSummaries ? "LEFT JOIN ipca_garmin_track_flight_summaries s ON s.track_artifact_id = t.id" : "LEFT JOIN (SELECT NULL AS track_artifact_id) s ON 1 = 0") . "
    WHERE t.artifact_type = 'GARMIN_TRACK_NORMALIZED_JSON'
") : array('total_track_artifacts' => 0, 'summarized_track_artifacts' => 0, 'missing_track_summaries' => 0);

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
    $csvSelect = ($hasSources && $hasCsvFiles) ? "
          f.id AS csv_file_id, f.csv_file_uuid, f.aircraft_registration AS csv_aircraft_registration,
          f.original_filename AS csv_original_filename, f.storage_path AS csv_storage_path,
          f.import_profile AS csv_import_profile, f.aircraft_ident AS csv_aircraft_ident,
          f.first_valid_sample_utc AS csv_first_valid_sample_utc,
          f.last_valid_sample_utc AS csv_last_valid_sample_utc,
          f.valid_row_count AS csv_valid_row_count
    " : "
          NULL AS csv_file_id, NULL AS csv_file_uuid, NULL AS csv_aircraft_registration,
          NULL AS csv_original_filename, NULL AS csv_storage_path, NULL AS csv_import_profile,
          NULL AS csv_aircraft_ident, NULL AS csv_first_valid_sample_utc,
          NULL AS csv_last_valid_sample_utc, NULL AS csv_valid_row_count
    ";
    $csvIdParts = array();
    if ($hasAcks) {
        $csvIdParts[] = 'a.garmin_csv_file_id';
    }
    $csvIdParts[] = 'direct_s.garmin_csv_file_id';
    if ($hasEntries && $hasSourceGroups) {
        $csvIdParts[] = 'group_s.garmin_csv_file_id';
    }
    $csvIdExpression = count($csvIdParts) > 1 ? 'COALESCE(' . implode(', ', $csvIdParts) . ')' : $csvIdParts[0];
    $sourceJoin = ($hasSources && $hasCsvFiles) ? "
        " . ($hasEntries ? "LEFT JOIN ipca_garmin_logbook_entries e
          ON e.provider_name = t.provider_name
         AND e.garmin_entry_uuid = t.garmin_entry_uuid" : "") . "
        " . ($hasEntries && $hasSourceGroups ? "LEFT JOIN ipca_garmin_source_groups g
          ON g.garmin_logbook_entry_id = e.id" : "") . "
        LEFT JOIN ipca_garmin_flight_data_sources direct_s
          ON direct_s.provider_name = t.provider_name
         AND direct_s.flight_data_log_uuid = t.track_uuid
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
          {$csvSelect}
        FROM ipca_garmin_normalized_track_artifacts t
        {$ackJoin}
        {$tokenJoin}
        {$sourceJoin}
        ORDER BY t.last_seen_at DESC, t.id DESC
        LIMIT 100
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

cw_header('Garmin Sync Agent');
?>
<style>
.garmin-page{display:grid;gap:18px}.garmin-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.garmin-muted{color:#64748b;font-size:13px}.garmin-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.garmin-kv{border:1px solid #e2e8f0;border-radius:12px;padding:12px;background:#f8fafc}.garmin-label{color:#64748b;font-size:12px;text-transform:uppercase;letter-spacing:.04em}.garmin-value{font-weight:800;margin-top:4px}.garmin-badge{display:inline-flex;border-radius:999px;padding:3px 9px;font-size:12px;font-weight:800;background:#e2e8f0;color:#334155}.garmin-badge-ok{background:#dcfce7;color:#166534}.garmin-badge-warn{background:#fef3c7;color:#92400e}.garmin-badge-danger{background:#fee2e2;color:#991b1b}.garmin-table-wrap{overflow-x:auto}.garmin-table{width:100%;border-collapse:collapse;min-width:980px}.garmin-table th,.garmin-table td{border-bottom:1px solid #e2e8f0;padding:10px 8px;text-align:left;vertical-align:top}.garmin-table th{color:#475569;font-size:12px;text-transform:uppercase;letter-spacing:.04em}.garmin-code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px;word-break:break-all}.garmin-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.garmin-toolbar a,.garmin-toolbar button{border:0;border-radius:10px;background:#0f172a;color:#fff;font-weight:800;padding:9px 12px;text-decoration:none;cursor:pointer}.garmin-toolbar a.secondary,.garmin-toolbar button.secondary{background:#475569}.garmin-toolbar form{margin:0}.garmin-progress{height:10px;background:#e2e8f0;border-radius:999px;overflow:hidden}.garmin-progress span{display:block;height:100%;background:#2563eb}.garmin-empty{padding:18px;border:1px dashed #cbd5e1;border-radius:12px;color:#64748b;background:#f8fafc}.garmin-notice{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:12px}.garmin-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px}
</style>
<div class="garmin-page">
  <section class="garmin-card">
    <div style="display:flex;gap:16px;justify-content:space-between;align-items:flex-start;flex-wrap:wrap">
      <div>
        <h2 style="margin:0">Garmin Sync Agent Dashboard</h2>
        <p class="garmin-muted">Server-side view of Garmin data uploaded by the native Mac IPCA Sync Agent. The old cloud-browser Garmin controls have been retired from this page.</p>
      </div>
      <div class="garmin-toolbar">
        <form method="post" action="/admin/api/garmin_csv_summary_action.php">
          <input type="hidden" name="return" value="/admin/flight_log_garmin_connection.php">
          <input type="hidden" name="action" value="derive_missing_now">
          <input type="hidden" name="limit" value="100">
          <button type="submit">Process Garmin Summaries Now</button>
        </form>
        <form method="post" action="/admin/api/garmin_csv_summary_action.php">
          <input type="hidden" name="return" value="/admin/flight_log_garmin_connection.php">
          <input type="hidden" name="action" value="enqueue_missing">
          <input type="hidden" name="limit" value="500">
          <button class="secondary" type="submit">Queue Remaining Garmin Summaries</button>
        </form>
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

  <section class="garmin-card garmin-table-wrap">
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

  <section class="garmin-card garmin-table-wrap">
    <h3 style="margin-top:0">Recent Garmin Tracks Uploaded by Sync Agent</h3>
    <?php if ($recentTracks === array()): ?>
      <div class="garmin-empty">No normalized Garmin track artifacts have been uploaded yet.</div>
    <?php else: ?>
      <table class="garmin-table">
        <thead><tr><th>Flight</th><th>Route / Time</th><th>Hobbs</th><th>Status</th><th>Classification</th><th>Telemetry</th><th>Size</th><th>Device</th><th>Uploaded</th></tr></thead>
        <tbody>
        <?php foreach ($recentTracks as $track): ?>
          <?php
          $classification = garmin_sync_metadata_value((string)($track['raw_metadata_json'] ?? ''), 'trackClassification');
          $sourceNames = garmin_sync_metadata_value((string)($track['raw_metadata_json'] ?? ''), 'sourceNames');
          $status = (string)($track['upload_status'] ?? 'accepted');
          $csvSummary = !empty($track['csv_file_id']) ? $summaryService->summaryForCsvFile(array(
              'id' => (int)$track['csv_file_id'],
              'aircraft_registration' => (string)($track['csv_aircraft_registration'] ?? ''),
              'aircraft_ident' => (string)($track['csv_aircraft_ident'] ?? ''),
              'storage_path' => (string)($track['csv_storage_path'] ?? ''),
              'import_profile' => (string)($track['csv_import_profile'] ?? ''),
              'first_valid_sample_utc' => (string)($track['csv_first_valid_sample_utc'] ?? ''),
              'last_valid_sample_utc' => (string)($track['csv_last_valid_sample_utc'] ?? ''),
              'valid_row_count' => (int)($track['csv_valid_row_count'] ?? 0),
          )) : array();
          if ($csvSummary === array() || (string)($csvSummary['status'] ?? '') === 'not_analyzed') {
              $csvSummary = $trackSummaryService->summaryForTrackArtifact($track);
          }
          ?>
          <tr>
            <td>
              <strong><?= h((string)($csvSummary['date_label'] ?? '--')) ?></strong><br>
              <?= h((string)($csvSummary['tail'] ?? 'Unknown tail')) ?><br>
              <span class="garmin-muted"><?= h((string)($track['csv_original_filename'] ?? '')) ?></span>
              <div class="garmin-code"><?= h((string)$track['track_uuid']) ?></div>
            </td>
            <td>
              <?= h((string)($csvSummary['dep_airport'] ?? '--')) ?> <?= h((string)($csvSummary['dep_time_lt'] ?? '--')) ?> LT
              - <?= h((string)($csvSummary['arr_airport'] ?? '--')) ?> <?= h((string)($csvSummary['arr_time_lt'] ?? '--')) ?> LT<br>
              <span class="garmin-muted">ET <?= h((string)($csvSummary['elapsed_time'] ?? '--')) ?> · Entry <?= h((string)$track['garmin_entry_uuid']) ?></span>
            </td>
            <td>
              <?= h((string)($csvSummary['hobbs_start_lt'] ?? '--')) ?> - <?= h((string)($csvSummary['hobbs_end_lt'] ?? '--')) ?> LT<br>
              <span class="garmin-muted"><?= h((string)($csvSummary['hobbs_hours'] ?? '--')) ?> · <?= h((string)($csvSummary['hobbs_status'] ?? '')) ?></span>
            </td>
            <td><span class="garmin-badge <?= garmin_sync_badge_class($status) ?>"><?= h($status === '' ? 'stored' : $status) ?></span></td>
            <td><?= h($classification === '' ? 'Unknown' : $classification) ?><br><span class="garmin-muted"><?= h($sourceNames) ?></span></td>
            <td><?= number_format((int)$track['session_count']) ?> sessions<br><?= number_format((int)$track['field_count']) ?> fields</td>
            <td><?= h(garmin_sync_bytes($track['file_size_bytes'] ?? 0)) ?></td>
            <td><?= h((string)($track['device_name'] ?? 'Unknown device')) ?></td>
            <td><?= h(garmin_sync_datetime((string)($track['uploaded_at'] ?? $track['last_seen_at'] ?? ''))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <section class="garmin-card garmin-table-wrap">
    <h3 style="margin-top:0">Recent Upload Acknowledgments</h3>
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
<?php cw_footer(); ?>
