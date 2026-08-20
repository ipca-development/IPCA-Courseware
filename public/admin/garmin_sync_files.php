<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_admin();

function garmin_sync_files_organization_id(PDO $pdo): int
{
    if (function_exists('cw_current_organization_id')) {
        try {
            $organizationId = (int)cw_current_organization_id($pdo);
            if ($organizationId > 0) {
                return $organizationId;
            }
        } catch (Throwable) {
            // This repository's established single-organization fallback is organization 1.
        }
    }
    return 1;
}

function garmin_sync_files_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = array('KB', 'MB', 'GB', 'TB');
    $value = (float)$bytes;
    foreach ($units as $unit) {
        $value /= 1024;
        if ($value < 1024 || $unit === 'TB') {
            return number_format($value, $value >= 10 ? 1 : 2) . ' ' . $unit;
        }
    }
    return $bytes . ' B';
}

$organizationId = garmin_sync_files_organization_id($pdo);
$allowedStatuses = array('all', 'receiving', 'verified', 'duplicate', 'error');
$status = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}
$allowedKinds = array('all', 'flight', 'junk', 'unclassified');
$kind = strtolower(trim((string)($_GET['kind'] ?? 'all')));
if (!in_array($kind, $allowedKinds, true)) {
    $kind = 'all';
}
$search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 120);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;
$offset = ($page - 1) * $perPage;
$error = null;
$rows = array();
$totalRows = 0;
$stats = array(
    'sessions' => 0,
    'completed' => 0,
    'unique_files' => 0,
    'receiving' => 0,
    'errors' => 0,
    'unique_bytes' => 0,
    'flight_csv' => 0,
    'junk' => 0,
    'unclassified' => 0,
);

try {
    $statsStmt = $pdo->prepare(
        "SELECT COUNT(*) AS sessions,
                COALESCE(SUM(status IN ('verified', 'duplicate')), 0) AS completed,
                COUNT(DISTINCT archive_file_id) AS unique_files,
                COALESCE(SUM(status = 'receiving'), 0) AS receiving,
                COALESCE(SUM(status = 'error'), 0) AS errors
         FROM ipca_garmin_sync_upload_sessions
         WHERE organization_id = ?"
    );
    $statsStmt->execute(array($organizationId));
    $stats = array_merge($stats, $statsStmt->fetch(PDO::FETCH_ASSOC) ?: array());

    $bytesStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(a.byte_count), 0)
         FROM ipca_garmin_sync_archive_files a
         INNER JOIN (
             SELECT DISTINCT archive_file_id
             FROM ipca_garmin_sync_upload_sessions
             WHERE organization_id = ? AND archive_file_id IS NOT NULL
         ) owned ON owned.archive_file_id = a.id"
    );
    $bytesStmt->execute(array($organizationId));
    $stats['unique_bytes'] = (int)$bytesStmt->fetchColumn();

    $classificationStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(c.source_kind = 'GARMIN_FLIGHT_CSV'), 0) AS flight_csv,
                COALESCE(SUM(c.id IS NOT NULL AND c.source_kind <> 'GARMIN_FLIGHT_CSV'), 0) AS junk,
                COALESCE(SUM(c.id IS NULL), 0) AS unclassified
         FROM (
             SELECT DISTINCT archive_file_id
             FROM ipca_garmin_sync_upload_sessions
             WHERE organization_id = ? AND archive_file_id IS NOT NULL
         ) owned
         LEFT JOIN ipca_garmin_sync_file_classifications c
           ON c.archive_file_id = owned.archive_file_id"
    );
    $classificationStmt->execute(array($organizationId));
    $stats = array_merge($stats, $classificationStmt->fetch(PDO::FETCH_ASSOC) ?: array());

    $where = array('s.organization_id = ?');
    $params = array($organizationId);
    if ($status !== 'all') {
        $where[] = 's.status = ?';
        $params[] = $status;
    }
    if ($kind === 'flight') {
        $where[] = "c.source_kind = 'GARMIN_FLIGHT_CSV'";
    } elseif ($kind === 'junk') {
        $where[] = "c.id IS NOT NULL AND c.source_kind <> 'GARMIN_FLIGHT_CSV'";
    } elseif ($kind === 'unclassified') {
        $where[] = 'c.id IS NULL';
    }
    if ($search !== '') {
        $where[] = '(s.original_filename LIKE ? OR s.upload_uuid LIKE ? OR s.expected_sha256 LIKE ? OR a.object_uuid LIKE ? OR c.aircraft_registration LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM ipca_garmin_sync_upload_sessions s
         LEFT JOIN ipca_garmin_sync_archive_files a ON a.id = s.archive_file_id
         LEFT JOIN ipca_garmin_sync_file_classifications c ON c.archive_file_id = a.id
         WHERE {$whereSql}"
    );
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();

    $listStmt = $pdo->prepare(
        "SELECT s.upload_uuid, s.device_id, s.original_filename, s.status, s.expected_sha256,
                s.expected_byte_count, s.received_byte_count, s.total_chunks,
                s.received_chunks_json, s.retry_count, s.last_error_code,
                s.last_error_message, s.receipt_uuid, s.finalized_at,
                s.created_at, s.updated_at, a.object_uuid, a.verified_at,
                d.device_uuid, d.display_name AS device_name,
                c.source_kind, c.analysis_eligible, c.aircraft_registration,
                c.product, c.system_identifier, c.classification_reason,
                EXISTS(
                    SELECT 1 FROM ipca_aircraft_devices fleet
                    WHERE UPPER(fleet.registration) = UPPER(c.aircraft_registration)
                      AND fleet.active = 1
                ) AS registration_in_active_fleet
         FROM ipca_garmin_sync_upload_sessions s
         LEFT JOIN ipca_garmin_sync_archive_files a ON a.id = s.archive_file_id
         LEFT JOIN ipca_garmin_sync_file_classifications c ON c.archive_file_id = a.id
         LEFT JOIN ipca_garmin_sync_devices d
           ON d.id = s.device_id AND d.organization_id = s.organization_id
         WHERE {$whereSql}
         ORDER BY s.updated_at DESC, s.id DESC
         LIMIT ? OFFSET ?"
    );
    $bindIndex = 1;
    foreach ($params as $param) {
        $listStmt->bindValue($bindIndex++, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $listStmt->bindValue($bindIndex++, $perPage, PDO::PARAM_INT);
    $listStmt->bindValue($bindIndex, $offset, PDO::PARAM_INT);
    $listStmt->execute();
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
$queryForPage = static function (int $targetPage) use ($status, $kind, $search): string {
    return http_build_query(array_filter(
        array('status' => $status, 'kind' => $kind, 'q' => $search, 'page' => $targetPage),
        static fn($value): bool => $value !== '' && $value !== 'all'
    ));
};

cw_header('Garmin Sync uploaded files');
?>
<style>
.gs-files { max-width: 1500px; margin: 0 auto; padding: 24px; }
.gs-head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; }
.gs-head h1 { margin:0 0 6px; }
.gs-head p { margin:0; color:#475569; }
.gs-actions { display:flex; gap:8px; flex-wrap:wrap; }
.gs-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin:20px 0; }
.gs-stat { background:#fff; border:1px solid #dbe3ec; border-radius:12px; padding:14px; }
.gs-stat strong { display:block; font-size:1.45rem; color:#0f172a; }
.gs-stat span { color:#64748b; font-size:.86rem; }
.gs-filter { display:flex; gap:10px; align-items:end; flex-wrap:wrap; margin:18px 0; padding:14px; background:#f8fafc; border:1px solid #dbe3ec; border-radius:12px; }
.gs-filter label { display:grid; gap:5px; font-weight:700; color:#334155; }
.gs-filter input,.gs-filter select { min-height:40px; border:1px solid #94a3b8; border-radius:8px; padding:7px 10px; background:#fff; }
.gs-table-wrap { overflow:auto; border:1px solid #dbe3ec; border-radius:12px; background:#fff; }
.gs-table { width:100%; border-collapse:collapse; font-size:.88rem; white-space:nowrap; }
.gs-table th,.gs-table td { padding:10px 12px; border-bottom:1px solid #e2e8f0; text-align:left; vertical-align:top; }
.gs-table th { position:sticky; top:0; background:#f1f5f9; color:#334155; z-index:1; }
.gs-table tr:last-child td { border-bottom:0; }
.gs-name { max-width:300px; white-space:normal; overflow-wrap:anywhere; font-weight:700; }
.gs-code { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.78rem; }
.gs-muted { color:#64748b; }
.gs-status { display:inline-block; border-radius:999px; padding:3px 8px; font-size:.75rem; font-weight:800; text-transform:uppercase; }
.gs-status--verified { background:#dcfce7; color:#166534; }
.gs-status--duplicate { background:#dbeafe; color:#1e40af; }
.gs-status--receiving { background:#fef3c7; color:#92400e; }
.gs-status--error { background:#fee2e2; color:#991b1b; }
.gs-kind { display:inline-block; border-radius:7px; padding:4px 7px; font-size:.75rem; font-weight:800; }
.gs-kind--flight { background:#dcfce7; color:#166534; }
.gs-kind--junk { background:#fee2e2; color:#991b1b; }
.gs-kind--pending { background:#e2e8f0; color:#475569; }
.gs-registration { font-size:1rem; font-weight:900; color:#0f172a; }
.gs-error { margin:16px 0; padding:12px; border:1px solid #fecaca; border-radius:10px; color:#991b1b; background:#fef2f2; }
.gs-pagination { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:14px; }
@media (max-width:700px) { .gs-files { padding:14px; } }
</style>

<main class="gs-files">
  <div class="gs-head">
    <div>
      <h1>Garmin Sync uploaded files</h1>
      <p>Read-only view of uploader sessions and verified archive files for this organization.</p>
    </div>
    <div class="gs-actions">
      <a class="btn btn-secondary" href="/admin/garmin_sync_enrollment.php">Device enrollment</a>
      <a class="btn btn-secondary" href="/admin/garmin_sync_files.php">Refresh</a>
    </div>
  </div>

  <?php if ($error !== null): ?>
    <div class="gs-error" role="alert"><?= h($error) ?></div>
  <?php else: ?>
    <section class="gs-stats" aria-label="Garmin Sync totals">
      <div class="gs-stat"><strong><?= number_format((int)$stats['unique_files']) ?></strong><span>Unique verified files</span></div>
      <div class="gs-stat"><strong><?= number_format((int)$stats['flight_csv']) ?></strong><span>Garmin flight CSVs</span></div>
      <div class="gs-stat"><strong><?= number_format((int)$stats['junk']) ?></strong><span>Junk / unsupported</span></div>
      <div class="gs-stat"><strong><?= number_format((int)$stats['unclassified']) ?></strong><span>Awaiting classification</span></div>
      <div class="gs-stat"><strong><?= garmin_sync_files_format_bytes((int)$stats['unique_bytes']) ?></strong><span>Verified archive size</span></div>
      <div class="gs-stat"><strong><?= number_format((int)$stats['completed']) ?></strong><span>Completed uploads</span></div>
      <div class="gs-stat"><strong><?= number_format((int)$stats['receiving']) ?></strong><span>Receiving</span></div>
      <div class="gs-stat"><strong><?= number_format((int)$stats['errors']) ?></strong><span>Upload errors</span></div>
    </section>

    <form class="gs-filter" method="get">
      <label>
        Status
        <select name="status">
          <?php foreach ($allowedStatuses as $option): ?>
            <option value="<?= h($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= h(ucfirst($option)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        File type
        <select name="kind">
          <?php foreach ($allowedKinds as $option): ?>
            <option value="<?= h($option) ?>" <?= $kind === $option ? 'selected' : '' ?>><?= h(ucfirst($option)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        Filename, registration, upload ID, object ID, or SHA-256
        <input type="search" name="q" value="<?= h($search) ?>" size="42" placeholder="Search received files">
      </label>
      <button class="btn btn-primary" type="submit">Filter</button>
      <?php if ($status !== 'all' || $kind !== 'all' || $search !== ''): ?>
        <a class="btn btn-secondary" href="/admin/garmin_sync_files.php">Clear</a>
      <?php endif; ?>
    </form>

    <p class="gs-muted">
      Showing <?= number_format(count($rows)) ?> of <?= number_format($totalRows) ?> matching upload sessions.
      Duplicate uploads point to an existing verified archive object.
    </p>

    <div class="gs-table-wrap">
      <table class="gs-table">
        <thead>
          <tr>
            <th>Filename</th>
            <th>File type</th>
            <th>Airplane registration</th>
            <th>Status</th>
            <th>Size / received</th>
            <th>Device</th>
            <th>Uploaded (UTC)</th>
            <th>SHA-256</th>
            <th>Upload / object</th>
            <th>Retries / error</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($rows === array()): ?>
            <tr><td colspan="10" class="gs-muted">No Garmin Sync uploads match this view.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $row): ?>
            <?php
              $rowStatus = (string)$row['status'];
              $deviceLabel = trim((string)($row['device_name'] ?? ''));
              if ($deviceLabel === '') {
                  $deviceLabel = (string)($row['device_uuid'] ?? 'Device ' . $row['device_id']);
              }
              $receivedChunks = json_decode((string)($row['received_chunks_json'] ?? '[]'), true);
              $receivedChunkCount = is_array($receivedChunks) ? count($receivedChunks) : 0;
              $sourceKind = trim((string)($row['source_kind'] ?? ''));
              $isFlightCsv = $sourceKind === 'GARMIN_FLIGHT_CSV';
            ?>
            <tr>
              <td class="gs-name"><?= h((string)$row['original_filename']) ?></td>
              <td>
                <?php if ($isFlightCsv): ?>
                  <span class="gs-kind gs-kind--flight">Garmin flight CSV</span>
                <?php elseif ($sourceKind !== ''): ?>
                  <span class="gs-kind gs-kind--junk">Junk / unsupported</span>
                <?php else: ?>
                  <span class="gs-kind gs-kind--pending">Unclassified</span>
                <?php endif; ?>
                <?php if (!empty($row['classification_reason'])): ?>
                  <br><span class="gs-muted"><?= h((string)$row['classification_reason']) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($row['aircraft_registration'])): ?>
                  <span class="gs-registration"><?= h((string)$row['aircraft_registration']) ?></span>
                  <?php if (empty($row['registration_in_active_fleet'])): ?><br><span class="gs-muted">Not in active fleet</span><?php endif; ?>
                <?php else: ?>
                  <span class="gs-muted"><?= $isFlightCsv ? 'Not identified' : '—' ?></span>
                <?php endif; ?>
              </td>
              <td><span class="gs-status gs-status--<?= h($rowStatus) ?>"><?= h($rowStatus) ?></span></td>
              <td>
                <?= h(garmin_sync_files_format_bytes((int)$row['expected_byte_count'])) ?><br>
                <span class="gs-muted">
                  <?= h(garmin_sync_files_format_bytes((int)$row['received_byte_count'])) ?>,
                  <?= number_format($receivedChunkCount) ?>/<?= number_format((int)$row['total_chunks']) ?> chunks
                </span>
              </td>
              <td><?= h($deviceLabel) ?></td>
              <td>
                <?= h((string)($row['finalized_at'] ?: $row['updated_at'])) ?><br>
                <span class="gs-muted">Started <?= h((string)$row['created_at']) ?></span>
              </td>
              <td class="gs-code" title="<?= h((string)$row['expected_sha256']) ?>"><?= h(substr((string)$row['expected_sha256'], 0, 16)) ?>&hellip;</td>
              <td class="gs-code">
                Upload: <?= h((string)$row['upload_uuid']) ?><br>
                <?php if (!empty($row['object_uuid'])): ?>Object: <?= h((string)$row['object_uuid']) ?><?php else: ?><span class="gs-muted">No archive object yet</span><?php endif; ?>
              </td>
              <td>
                <?= number_format((int)$row['retry_count']) ?>
                <?php if (!empty($row['last_error_code'])): ?>
                  <br><strong><?= h((string)$row['last_error_code']) ?></strong>
                  <br><span class="gs-muted"><?= h((string)$row['last_error_message']) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav class="gs-pagination" aria-label="Garmin upload pages">
        <div>
          <?php if ($page > 1): ?><a class="btn btn-secondary" href="?<?= h($queryForPage($page - 1)) ?>">Previous</a><?php endif; ?>
        </div>
        <span>Page <?= number_format($page) ?> of <?= number_format($totalPages) ?></span>
        <div>
          <?php if ($page < $totalPages): ?><a class="btn btn-secondary" href="?<?= h($queryForPage($page + 1)) ?>">Next</a><?php endif; ?>
        </div>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</main>
<?php cw_footer(); ?>
