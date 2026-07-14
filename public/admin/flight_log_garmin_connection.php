<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/GarminCloudIntegrationService.php';

cw_require_admin();

function garmin_badge_class(string $value): string
{
    $value = strtolower($value);
    if (in_array($value, array('authenticated', 'reachable', 'succeeded', 'downloaded', 'supported', 'full_avionics', 'matched', 'imported'), true)) {
        return 'garmin-badge-ok';
    }
    if (in_array($value, array('gps_only', 'partial_avionics', 'needs_admin_review', 'warning', 'pending', 'unknown_supported'), true)) {
        return 'garmin-badge-warn';
    }
    if (in_array($value, array('failed', 'invalid', 'authentication_required', 'session_expired', 'unsupported_format', 'unreachable'), true)) {
        return 'garmin-badge-danger';
    }
    return '';
}

try {
    $data = (new GarminCloudIntegrationService($pdo))->status();
    $foundationReady = true;
} catch (Throwable $e) {
    $data = array('provider' => array(), 'recent_runs' => array(), 'entries' => array(), 'error' => $e->getMessage());
    $foundationReady = false;
}

$provider = $data['provider'] ?? array();
$checks = json_decode((string)($provider['acceptance_checks_json'] ?? '{}'), true);
$checks = is_array($checks) ? $checks : array();
$scheduledEnabled = (int)($provider['scheduled_sync_enabled'] ?? 0) === 1;
$acceptancePassed = (int)($provider['deployment_acceptance_passed'] ?? 0) === 1;
$authSession = is_array($data['auth_session'] ?? null) ? $data['auth_session'] : array('status' => 'unknown');

cw_header('Flight Log - Garmin Connection');
?>
<style>
.garmin-page{display:grid;gap:18px}.garmin-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.garmin-muted{color:#64748b;font-size:13px}.garmin-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.garmin-kv{border:1px solid #e2e8f0;border-radius:12px;padding:12px}.garmin-label{color:#64748b;font-size:12px;text-transform:uppercase;letter-spacing:.04em}.garmin-value{font-weight:800;margin-top:4px}.garmin-badge{display:inline-flex;border-radius:999px;padding:3px 9px;font-size:12px;font-weight:800;background:#e2e8f0;color:#334155}.garmin-badge-ok{background:#dcfce7;color:#166534}.garmin-badge-warn{background:#fef3c7;color:#92400e}.garmin-badge-danger{background:#fee2e2;color:#991b1b}.garmin-actions{display:flex;gap:8px;flex-wrap:wrap}.garmin-actions button{border:0;border-radius:10px;background:#0f172a;color:#fff;font-weight:800;padding:9px 12px;cursor:pointer}.garmin-actions button.secondary{background:#475569}.garmin-actions button.danger{background:#991b1b}.garmin-actions button:disabled{background:#cbd5e1;color:#64748b;cursor:not-allowed}.garmin-table-wrap{overflow-x:auto}.garmin-table{width:100%;border-collapse:collapse;min-width:980px}.garmin-table th,.garmin-table td{border-bottom:1px solid #e2e8f0;padding:10px 8px;text-align:left;vertical-align:top}.garmin-table th{color:#475569;font-size:12px;text-transform:uppercase;letter-spacing:.04em}.garmin-source{border:1px solid #e2e8f0;border-radius:10px;padding:8px;margin:6px 0;background:#f8fafc}.garmin-result{white-space:pre-wrap;background:#0f172a;color:#dbeafe;border-radius:12px;padding:12px;display:none}
</style>
<div class="garmin-page">
  <section class="garmin-card">
    <h2 style="margin-top:0">Garmin Cloud Connection</h2>
    <p class="garmin-muted">Manual testing controls for Garmin Cloud ingestion. Scheduled synchronization remains disabled until every deployment acceptance check has passed in the testing environment.</p>
    <?php if (!$foundationReady): ?>
      <p><span class="garmin-badge garmin-badge-danger">Database foundation missing</span> <?= h((string)($data['error'] ?? '')) ?></p>
    <?php endif; ?>
    <div class="garmin-grid">
      <div class="garmin-kv"><div class="garmin-label">Worker</div><div class="garmin-value"><span class="garmin-badge <?= garmin_badge_class(!empty($provider['worker_reachable']) ? 'reachable' : 'unreachable') ?>"><?= !empty($provider['worker_reachable']) ? 'Reachable' : 'Unreachable' ?></span></div></div>
      <div class="garmin-kv"><div class="garmin-label">Provider</div><div class="garmin-value"><span class="garmin-badge <?= !empty($provider['enabled']) ? 'garmin-badge-ok' : 'garmin-badge-warn' ?>"><?= !empty($provider['enabled']) ? 'Enabled' : 'Disabled' ?></span></div></div>
      <div class="garmin-kv"><div class="garmin-label">Authentication</div><div class="garmin-value"><span class="garmin-badge <?= garmin_badge_class((string)($provider['authentication_status'] ?? 'not_configured')) ?>"><?= h((string)($provider['authentication_status'] ?? 'not_configured')) ?></span></div></div>
      <div class="garmin-kv"><div class="garmin-label">Browser Profile</div><div class="garmin-value"><?= !empty($provider['browser_profile_present']) ? 'Present' : 'Missing' ?></div></div>
      <div class="garmin-kv"><div class="garmin-label">Scheduled Sync</div><div class="garmin-value"><span class="garmin-badge <?= $scheduledEnabled ? 'garmin-badge-ok' : 'garmin-badge-warn' ?>"><?= $scheduledEnabled ? 'Enabled' : 'Disabled until acceptance gates pass' ?></span></div></div>
      <div class="garmin-kv"><div class="garmin-label">Deployment Acceptance</div><div class="garmin-value"><span class="garmin-badge <?= $acceptancePassed ? 'garmin-badge-ok' : 'garmin-badge-warn' ?>"><?= $acceptancePassed ? 'Passed' : 'Manual gates pending' ?></span></div></div>
    </div>
  </section>

  <section class="garmin-card">
    <h3 style="margin-top:0">Manual Actions</h3>
    <div class="garmin-actions">
      <button data-action="test_connection">Test Connection</button>
      <button data-action="initial_sync">Initial Synchronization</button>
      <button data-action="incremental_sync" class="secondary">Incremental Synchronization</button>
      <button data-action="full_reconciliation" class="secondary">Full Reconciliation</button>
      <button data-action="mark_flight_log_visible" class="secondary">Mark UI Visible</button>
      <button data-action="enable_scheduled_sync" class="danger" <?= $acceptancePassed ? '' : 'disabled' ?>>Enable Scheduled Sync</button>
    </div>
    <p class="garmin-muted">The “Enable Scheduled Sync” action is server-enforced and will fail unless all acceptance checks have passed.</p>
    <div id="garmin-result" class="garmin-result"></div>
  </section>

  <section class="garmin-card">
    <h3 style="margin-top:0">Garmin Authentication Session</h3>
    <p class="garmin-muted">Temporary headed Chromium access runs only on this Droplet and is reachable only through an SSH tunnel. Garmin username, password, MFA values, cookies, and browser storage are not captured by IPCA.training.</p>
    <div class="garmin-grid">
      <div class="garmin-kv"><div class="garmin-label">Session Status</div><div class="garmin-value"><span class="garmin-badge <?= garmin_badge_class((string)($authSession['status'] ?? 'idle')) ?>"><?= h((string)($authSession['status'] ?? 'idle')) ?></span></div></div>
      <div class="garmin-kv"><div class="garmin-label">Temporary Browser</div><div class="garmin-value"><?= !empty($authSession['browser_running']) ? 'Running' : 'Not running' ?></div></div>
      <div class="garmin-kv"><div class="garmin-label">Started</div><div class="garmin-value"><?= h((string)($authSession['started_at'] ?? '')) ?></div></div>
      <div class="garmin-kv"><div class="garmin-label">Expires</div><div class="garmin-value"><?= h((string)($authSession['expires_at'] ?? '')) ?></div></div>
    </div>
    <div style="margin-top:12px">
      <div class="garmin-label">Mac SSH Tunnel</div>
      <code><?= h((string)($authSession['mac_ssh_command'] ?? 'ssh -L 5905:127.0.0.1:5905 root@157.230.237.72')) ?></code>
    </div>
    <div style="margin-top:10px">
      <div class="garmin-label">Mac VNC URL</div>
      <code><?= h((string)($authSession['mac_vnc_url'] ?? 'vnc://localhost:5905')) ?></code>
    </div>
    <?php if (!empty($authSession['vnc_password'])): ?>
      <div style="margin-top:10px">
        <div class="garmin-label">One-time VNC Password</div>
        <code><?= h((string)$authSession['vnc_password']) ?></code>
      </div>
    <?php endif; ?>
    <?php if (!empty($authSession['error'])): ?>
      <p><span class="garmin-badge garmin-badge-danger"><?= h((string)$authSession['error']) ?></span></p>
    <?php endif; ?>
    <div class="garmin-actions" style="margin-top:14px">
      <button data-action="auth_start">Start Garmin Authentication Session</button>
      <button data-action="auth_status" class="secondary">Check Authentication Session</button>
      <button data-action="auth_complete">Complete Authentication</button>
      <button data-action="auth_cancel" class="danger">Cancel Authentication Session</button>
      <button data-action="auth_reauthenticate" class="secondary">Reauthenticate Garmin</button>
    </div>
  </section>

  <section class="garmin-card">
    <h3 style="margin-top:0">Deployment Acceptance Gates</h3>
    <div class="garmin-grid">
      <?php foreach ($checks as $key => $check): ?>
        <div class="garmin-kv">
          <div class="garmin-label"><?= h(str_replace('_', ' ', (string)$key)) ?></div>
          <div class="garmin-value"><span class="garmin-badge <?= !empty($check['passed']) ? 'garmin-badge-ok' : 'garmin-badge-warn' ?>"><?= !empty($check['passed']) ? 'Passed' : 'Pending' ?></span></div>
          <?php if (!empty($check['note'])): ?><div class="garmin-muted"><?= h((string)$check['note']) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="garmin-card garmin-table-wrap">
    <h3 style="margin-top:0">Recent Garmin Entries</h3>
    <table class="garmin-table">
      <thead><tr><th>Grouped Flight</th><th>Source Files</th><th>Match</th><th>Primary Sources</th></tr></thead>
      <tbody>
      <?php foreach (($data['entries'] ?? array()) as $entry): ?>
        <tr>
          <td>
            <strong><?= h((string)($entry['aircraft_registration'] ?? 'Aircraft unknown')) ?></strong><br>
            <span class="garmin-muted"><?= h((string)($entry['generated_track_start_utc'] ?? '')) ?> - <?= h((string)($entry['generated_track_stop_utc'] ?? '')) ?></span><br>
            <span class="garmin-muted">Entry <?= h((string)($entry['garmin_entry_uuid'] ?? '')) ?></span>
          </td>
          <td>
            <?php foreach (($entry['sources'] ?? array()) as $source): ?>
              <div class="garmin-source">
                <strong><?= h((string)($source['flight_data_log_uuid'] ?? '')) ?></strong><br>
                <span class="garmin-badge <?= garmin_badge_class(strtolower((string)($source['data_log_type'] ?? 'pending'))) ?>"><?= h((string)($source['data_log_type'] ?? 'awaiting classification')) ?></span>
                <span class="garmin-badge <?= garmin_badge_class((string)($source['download_status'] ?? 'pending')) ?>"><?= h((string)($source['download_status'] ?? 'pending')) ?></span>
                <span class="garmin-badge <?= garmin_badge_class((string)($source['validation_severity'] ?? 'pending')) ?>"><?= h((string)($source['validation_severity'] ?? 'not validated')) ?></span><br>
                <span class="garmin-muted"><?= h((string)($source['csv_first_timestamp_utc'] ?? '')) ?> - <?= h((string)($source['csv_last_timestamp_utc'] ?? '')) ?></span><br>
                <?php if (($source['download_status'] ?? '') !== 'downloaded'): ?>
                  <button data-action="download_source" data-source="<?= h((string)($source['flight_data_log_uuid'] ?? '')) ?>">Download / Classify</button>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </td>
          <td><span class="garmin-badge <?= garmin_badge_class((string)($entry['group_match_status'] ?? 'pending')) ?>"><?= h((string)($entry['group_match_status'] ?? 'pending')) ?></span><br><span class="garmin-muted">Session <?= h((string)($entry['matched_flight_session_id'] ?? 'not matched')) ?></span></td>
          <td>
            Operational: <?= h((string)($entry['primary_operational_source_id'] ?? 'not selected')) ?><br>
            Replay: <?= h((string)($entry['primary_replay_source_id'] ?? 'not selected')) ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($data['entries'])): ?>
        <tr><td colspan="4" class="garmin-muted">No Garmin entries discovered yet. Run Test Connection, then Initial Synchronization.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </section>

  <section class="garmin-card garmin-table-wrap">
    <h3 style="margin-top:0">Recent Sync Runs</h3>
    <table class="garmin-table">
      <thead><tr><th>Type</th><th>Status</th><th>Started</th><th>Entries</th><th>Data Logs</th><th>Classified</th><th>Error</th></tr></thead>
      <tbody>
      <?php foreach (($data['recent_runs'] ?? array()) as $run): ?>
        <tr>
          <td><?= h((string)($run['sync_type'] ?? '')) ?></td>
          <td><span class="garmin-badge <?= garmin_badge_class((string)($run['status'] ?? '')) ?>"><?= h((string)($run['status'] ?? '')) ?></span></td>
          <td><?= h((string)($run['started_at'] ?? '')) ?></td>
          <td><?= h((string)($run['entries_upserted'] ?? '0')) ?></td>
          <td><?= h((string)($run['data_logs_discovered'] ?? '0')) ?></td>
          <td>Full <?= h((string)($run['full_avionics_count'] ?? '0')) ?> / GPS <?= h((string)($run['gps_only_count'] ?? '0')) ?> / Partial <?= h((string)($run['partial_avionics_count'] ?? '0')) ?></td>
          <td><?= h((string)($run['error_summary'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($data['recent_runs'])): ?>
        <tr><td colspan="7" class="garmin-muted">No Garmin sync runs yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </section>
</div>
<script>
document.addEventListener('click', async (event) => {
  const button = event.target.closest('button[data-action]');
  if (!button) return;
  const result = document.getElementById('garmin-result');
  result.style.display = 'block';
  result.textContent = 'Running ' + button.dataset.action + '...';
  button.disabled = true;
  const form = new FormData();
  form.append('action', button.dataset.action);
  if (button.dataset.source) form.append('flight_data_log_uuid', button.dataset.source);
  try {
    const response = await fetch('/admin/api/garmin_cloud_action.php', { method: 'POST', body: form, credentials: 'same-origin' });
    const json = await response.json();
    result.textContent = JSON.stringify(json, null, 2);
    if (json.ok) setTimeout(() => window.location.reload(), 1200);
  } catch (error) {
    result.textContent = String(error);
  } finally {
    button.disabled = false;
  }
});
</script>
<?php cw_footer(); ?>
