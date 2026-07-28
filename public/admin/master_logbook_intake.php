<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/CvrDataIntakeReadService.php';

cw_require_admin();

$intake = new CvrDataIntakeReadService($pdo);
$dispatch = $intake->dispatchRows();
$audio = $intake->audioRows();
$garmin = $intake->garminRows();

function cvr_intake_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cvr_intake_timestamp(mixed $value): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '—';
    }
    $timestamp = strtotime($text);
    return $timestamp === false ? $text : date('M j, Y H:i:s', $timestamp);
}

function cvr_intake_bytes(mixed $value): string
{
    $bytes = max(0, (int)$value);
    if ($bytes === 0) {
        return '—';
    }
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return number_format($bytes) . ' B';
}

function cvr_intake_status_class(mixed $value): string
{
    $status = strtolower(trim((string)$value));
    if (in_array($status, array('ready', 'uploaded', 'received', 'finalized', 'complete', 'completed', 'ok', 'valid', 'active'), true)) {
        return 'intake-status-good';
    }
    if (in_array($status, array('failed', 'error', 'invalid', 'rejected'), true)) {
        return 'intake-status-bad';
    }
    if ($status === '') {
        return 'intake-status-muted';
    }
    return 'intake-status-pending';
}

function cvr_intake_badge(mixed $value): string
{
    $text = trim((string)$value);
    if ($text === '') {
        $text = 'Unknown';
    }
    return '<span class="intake-status ' . cvr_intake_status_class($text) . '">' . cvr_intake_h(strtoupper(str_replace('_', ' ', $text))) . '</span>';
}

function cvr_intake_short_hash(mixed $value): string
{
    $hash = trim((string)$value);
    return $hash === '' ? '—' : substr($hash, 0, 12) . (strlen($hash) > 12 ? '…' : '');
}

function cvr_intake_crew(mixed $value): string
{
    if (is_array($value)) {
        $decoded = $value;
    } else {
        $decoded = json_decode((string)$value, true);
    }
    if (!is_array($decoded) || $decoded === array()) {
        return '—';
    }
    $names = array();
    foreach ($decoded as $member) {
        if (!is_array($member)) {
            continue;
        }
        $name = trim((string)($member['personName'] ?? $member['person_name'] ?? $member['name'] ?? ''));
        $role = trim((string)($member['role'] ?? $member['crew_role'] ?? ''));
        if ($name !== '') {
            $names[] = $name . ($role !== '' ? ' · ' . $role : '');
        }
    }
    return $names === array() ? '—' : implode(', ', $names);
}

cw_header('Master Logbook');
?>
<style>
.intake-page{display:grid;gap:14px}
.intake-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:16px;padding:16px;box-shadow:0 10px 22px rgba(15,23,42,.05)}
.intake-hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap}
.intake-title{margin:0;color:#0f172a;font-size:26px}
.intake-muted{color:#64748b;font-size:12px;line-height:1.45}
.intake-tabs{display:flex;gap:8px;flex-wrap:wrap}
.intake-tab{border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:999px;padding:8px 13px;font-size:12px;font-weight:850;cursor:pointer}
.intake-tab.is-active{background:#1d4ed8;color:#fff;border-color:#1d4ed8}
.intake-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;border-radius:999px;margin-left:5px;padding:0 5px;background:#e2e8f0;color:#334155;font-size:10px}
.intake-tab.is-active .intake-count{background:rgba(255,255,255,.2);color:#fff}
.intake-panel{display:none}
.intake-panel.is-active{display:block}
.intake-panel-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:12px}
.intake-panel-title{margin:0;color:#0f172a;font-size:17px}
.intake-table-wrap{overflow:auto;border:1px solid #e2e8f0;border-radius:12px}
.intake-table{width:100%;min-width:1080px;border-collapse:collapse;font-size:12px}
.intake-table th{padding:9px 10px;background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#475569;text-align:left;text-transform:uppercase;font-size:9px;letter-spacing:.06em;white-space:nowrap}
.intake-table td{padding:9px 10px;border-bottom:1px solid #edf2f7;color:#334155;vertical-align:top}
.intake-table tbody tr:last-child td{border-bottom:0}
.intake-table tbody tr:hover{background:#f8fbff}
.intake-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:11px}
.intake-primary{font-weight:800;color:#0f172a}
.intake-status{display:inline-flex;border-radius:999px;padding:3px 7px;font-size:9px;font-weight:900;white-space:nowrap;background:#e2e8f0;color:#475569}
.intake-status-good{background:#dcfce7;color:#166534}
.intake-status-pending{background:#fef3c7;color:#92400e}
.intake-status-bad{background:#fee2e2;color:#991b1b}
.intake-status-muted{background:#e2e8f0;color:#64748b}
.intake-source-app{background:#dbeafe;color:#1e40af}
.intake-source-sync{background:#ede9fe;color:#5b21b6}
.intake-empty{padding:28px;text-align:center;color:#64748b}
.intake-notice{border:1px solid #fbbf24;background:#fffbeb;color:#92400e;border-radius:12px;padding:12px;font-size:12px}
.intake-error{color:#991b1b;max-width:280px;white-space:normal}
.intake-progress{display:grid;gap:4px;min-width:120px}
.intake-progress-bar{height:5px;background:#e2e8f0;border-radius:999px;overflow:hidden}
.intake-progress-fill{height:100%;background:#2563eb;border-radius:999px}
.intake-refresh{display:inline-flex;align-items:center;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#334155;padding:7px 10px;text-decoration:none;font-size:11px;font-weight:800}
@media(max-width:720px){.intake-card{padding:12px}.intake-title{font-size:22px}}
</style>

<div class="intake-page" data-intake-page>
  <section class="intake-card intake-hero">
    <div>
      <h1 class="intake-title">Data Intake</h1>
      <p class="intake-muted">Raw CVR inputs received by IPCA.training. This page reports receipt and processing only; it does not correlate or merge flight data.</p>
    </div>
    <a class="intake-refresh" href="/admin/master_logbook.php">Refresh data</a>
  </section>

  <section class="intake-card">
    <div class="intake-tabs" role="tablist" aria-label="Data intake sources">
      <button class="intake-tab is-active" type="button" role="tab" aria-selected="true" data-intake-tab="dispatch">
        Dispatch <span class="intake-count"><?= count($dispatch['rows']) ?></span>
      </button>
      <button class="intake-tab" type="button" role="tab" aria-selected="false" data-intake-tab="audio">
        Cockpit Audio <span class="intake-count"><?= count($audio['rows']) ?></span>
      </button>
      <button class="intake-tab" type="button" role="tab" aria-selected="false" data-intake-tab="garmin">
        Garmin CSV <span class="intake-count"><?= count($garmin['rows']) ?></span>
      </button>
    </div>
  </section>

  <section class="intake-card intake-panel is-active" role="tabpanel" data-intake-panel="dispatch">
    <div class="intake-panel-head">
      <div>
        <h2 class="intake-panel-title">Dispatch</h2>
        <div class="intake-muted">Dispatch records received directly from the CVR app.</div>
      </div>
    </div>
    <?php if (!$dispatch['available']): ?>
      <div class="intake-notice"><?= cvr_intake_h($dispatch['message']) ?></div>
    <?php elseif ($dispatch['rows'] === array()): ?>
      <div class="intake-empty">No Dispatch records have been received.</div>
    <?php else: ?>
      <div class="intake-table-wrap">
        <table class="intake-table">
          <thead><tr><th>Received</th><th>Dispatch</th><th>Aircraft</th><th>Mission</th><th>Crew</th><th>Source</th><th>Device</th><th>Status</th><th>Error</th></tr></thead>
          <tbody>
          <?php foreach ($dispatch['rows'] as $row): ?>
            <tr>
              <td><?= cvr_intake_h(cvr_intake_timestamp($row['received_at'] ?? null)) ?></td>
              <td><div class="intake-primary intake-mono"><?= cvr_intake_h($row['dispatch_uuid'] ?? '—') ?></div><div class="intake-muted">Version <?= cvr_intake_h($row['dispatch_version'] ?? '1') ?></div></td>
              <td class="intake-primary"><?= cvr_intake_h($row['aircraft_registration'] ?: '—') ?></td>
              <td><?= cvr_intake_h($row['mission_code'] ?: '—') ?></td>
              <td><?= cvr_intake_h(cvr_intake_crew($row['crew_json'] ?? null)) ?></td>
              <td><?= cvr_intake_h($row['source'] ?: '—') ?></td>
              <td class="intake-mono"><?= cvr_intake_h($row['device_identifier'] ?: '—') ?></td>
              <td><?= cvr_intake_badge($row['status'] ?? '') ?></td>
              <td class="intake-error"><?= cvr_intake_h($row['error_message'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="intake-card intake-panel" role="tabpanel" data-intake-panel="audio">
    <div class="intake-panel-head">
      <div>
        <h2 class="intake-panel-title">Cockpit Audio</h2>
        <div class="intake-muted">Audio upload and transcription status received from recorder units.</div>
      </div>
    </div>
    <?php if (!$audio['available']): ?>
      <div class="intake-notice"><?= cvr_intake_h($audio['message']) ?></div>
    <?php elseif ($audio['rows'] === array()): ?>
      <div class="intake-empty">No Cockpit Audio recordings have been received.</div>
    <?php else: ?>
      <div class="intake-table-wrap">
        <table class="intake-table">
          <thead><tr><th>Received</th><th>Recording</th><th>Aircraft</th><th>Start</th><th>Duration</th><th>Input</th><th>File</th><th>Upload</th><th>Transcription</th><th>Error</th></tr></thead>
          <tbody>
          <?php foreach ($audio['rows'] as $row): ?>
            <?php $transcriptionProgress = max(0, min(100, (int)($row['transcription_progress'] ?? 0))); ?>
            <tr>
              <td><?= cvr_intake_h(cvr_intake_timestamp($row['received_at'] ?? $row['created_at'] ?? null)) ?></td>
              <td><div class="intake-primary intake-mono"><?= cvr_intake_h($row['recording_uid'] ?: '—') ?></div><div class="intake-muted"><?= cvr_intake_h($row['session_uuid'] ?: 'No session link') ?></div></td>
              <td class="intake-primary"><?= cvr_intake_h($row['aircraft_registration'] ?: '—') ?></td>
              <td><?= cvr_intake_h(cvr_intake_timestamp($row['started_at'] ?? null)) ?></td>
              <td><?= (float)($row['duration_seconds'] ?? 0) > 0 ? cvr_intake_h(number_format((float)$row['duration_seconds'], 1) . ' s') : '—' ?></td>
              <td><?= cvr_intake_h($row['input_device'] ?: '—') ?></td>
              <td><div><?= cvr_intake_h($row['original_filename'] ?: '—') ?></div><div class="intake-muted"><?= cvr_intake_h(cvr_intake_bytes($row['file_size_bytes'] ?? 0)) ?></div></td>
              <td><?= cvr_intake_badge($row['upload_status'] ?? '') ?></td>
              <td>
                <div class="intake-progress">
                  <div><?= cvr_intake_badge($row['transcription_status'] ?? '') ?> <span class="intake-muted"><?= $transcriptionProgress ?>%</span></div>
                  <div class="intake-progress-bar"><div class="intake-progress-fill" style="width:<?= $transcriptionProgress ?>%"></div></div>
                </div>
              </td>
              <td class="intake-error"><?= cvr_intake_h($row['error_message'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="intake-card intake-panel" role="tabpanel" data-intake-panel="garmin">
    <div class="intake-panel-head">
      <div>
        <h2 class="intake-panel-title">Garmin CSV</h2>
        <div class="intake-muted">CSV evidence received through the CVR App or Automatic IPCA Sync Agent. Historical and FlightCircle sources are excluded.</div>
      </div>
    </div>
    <?php if (!$garmin['available']): ?>
      <div class="intake-notice"><?= cvr_intake_h($garmin['message']) ?></div>
    <?php elseif ($garmin['rows'] === array()): ?>
      <div class="intake-empty">No current Garmin CSV files have been received.</div>
    <?php else: ?>
      <div class="intake-table-wrap">
        <table class="intake-table">
          <thead><tr><th>Received</th><th>Source</th><th>Filename</th><th>Linked Record</th><th>Aircraft</th><th>Coverage</th><th>Size</th><th>SHA-256</th><th>Rows</th><th>Evidence</th><th>Validation</th></tr></thead>
          <tbody>
          <?php foreach ($garmin['rows'] as $row): ?>
            <?php $sourceIsSync = ($row['source_label'] ?? '') === 'IPCA SYNC AGENT'; ?>
            <tr>
              <td><?= cvr_intake_h(cvr_intake_timestamp($row['received_at'] ?? null)) ?></td>
              <td><span class="intake-status <?= $sourceIsSync ? 'intake-source-sync' : 'intake-source-app' ?>"><?= cvr_intake_h($row['source_label'] ?? 'CVR APP') ?></span><div class="intake-muted"><?= cvr_intake_h($row['provider_name'] ?: '') ?></div></td>
              <td><div class="intake-primary"><?= cvr_intake_h($row['original_filename'] ?: '—') ?></div><div class="intake-muted intake-mono"><?= cvr_intake_h($row['csv_file_uuid'] ?: '—') ?></div></td>
              <td><div class="intake-mono"><?= cvr_intake_h($row['workflow_flight_record_uuid'] ?: '—') ?></div><div class="intake-muted"><?= !empty($row['session_id']) ? 'Session ' . cvr_intake_h($row['session_id']) : 'No canonical session' ?></div></td>
              <td class="intake-primary"><?= cvr_intake_h($row['aircraft_registration'] ?: '—') ?></td>
              <td><div><?= cvr_intake_h(cvr_intake_timestamp($row['first_valid_sample_utc'] ?? null)) ?></div><div class="intake-muted">to <?= cvr_intake_h(cvr_intake_timestamp($row['last_valid_sample_utc'] ?? null)) ?></div></td>
              <td><?= cvr_intake_h(cvr_intake_bytes($row['file_size_bytes'] ?? 0)) ?></td>
              <td class="intake-mono" title="<?= cvr_intake_h($row['sha256'] ?? '') ?>"><?= cvr_intake_h(cvr_intake_short_hash($row['sha256'] ?? '')) ?></td>
              <td><?= cvr_intake_h(number_format((int)($row['valid_row_count'] ?? 0))) ?></td>
              <td><?= cvr_intake_badge($row['evidence_status'] ?? '') ?></td>
              <td><?= cvr_intake_badge($row['validation_status'] ?? '') ?><div class="intake-muted"><?= cvr_intake_h($row['validation_severity'] ?: '') ?></div></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<script>
(function () {
  const page = document.querySelector('[data-intake-page]');
  if (!page) return;
  const tabs = Array.from(page.querySelectorAll('[data-intake-tab]'));
  const panels = Array.from(page.querySelectorAll('[data-intake-panel]'));
  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      const selected = tab.getAttribute('data-intake-tab');
      tabs.forEach((item) => {
        const active = item === tab;
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      panels.forEach((panel) => panel.classList.toggle('is-active', panel.getAttribute('data-intake-panel') === selected));
    });
  });
})();
</script>
<?php cw_footer(); ?>
