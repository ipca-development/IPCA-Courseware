<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/CockpitAircraftService.php';
require_once __DIR__ . '/../../src/AircraftSettingsService.php';

cw_require_admin();

$aircraftService = new CockpitAircraftService($pdo);
$settingsService = new AircraftSettingsService($pdo);
$error = '';
$notice = '';
$aircraft = array();
$selectedAircraftId = (int)($_GET['aircraft_id'] ?? $_POST['aircraft_id'] ?? 0);
$selectedAircraft = null;
$resolved = array();
$alertRows = array();

function aircraft_settings_json(mixed $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return is_string($json) ? $json : '{}';
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_replay_profile') {
            $selectedAircraftId = (int)($_POST['aircraft_id'] ?? 0);
            $settingsService->saveReplayProfile($selectedAircraftId, $_POST);
            $notice = 'Aircraft replay settings saved. Existing replay facts do not need to be rebuilt for presentation-only changes.';
        } elseif ($action === 'save_alert') {
            $selectedAircraftId = (int)($_POST['aircraft_id'] ?? 0);
            $settingsService->saveAlertSeverity(
                (int)($_POST['alert_id'] ?? 0),
                (string)($_POST['severity'] ?? 'info'),
                (string)($_POST['display_text'] ?? ''),
                (string)($_POST['notes'] ?? '')
            );
            $notice = 'Garmin alert classification saved. Replays will use the current catalog at playback time.';
        }
    }

    $aircraft = $aircraftService->adminAircraft();
    if ($selectedAircraftId <= 0 && isset($aircraft[0]['id'])) {
        $selectedAircraftId = (int)$aircraft[0]['id'];
    }
    if ($selectedAircraftId > 0) {
        $selectedAircraft = $aircraftService->aircraftById($selectedAircraftId);
        $resolved = $settingsService->resolvedForAircraftId($selectedAircraftId);
        $alertRows = $settingsService->alertCatalogRows((string)($selectedAircraft['aircraft_type'] ?? ''));
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$identity = is_array($resolved['identity'] ?? null) ? $resolved['identity'] : array();
$presentation = is_array($resolved['presentation'] ?? null) ? $resolved['presentation'] : array();
$layout = is_array($presentation['layout'] ?? null) ? $presentation['layout'] : array('schema_version' => 1);
$instruments = is_array($presentation['instruments'] ?? null) ? $presentation['instruments'] : array();
$instrumentOverride = is_array($instruments['aircraft_override'] ?? null) ? $instruments['aircraft_override'] : array('schema_version' => 1);
$trim = is_array($presentation['trim'] ?? null) ? $presentation['trim'] : array('schema_version' => 1, 'min' => -100, 'neutral' => 0, 'max' => 100);
$operational = is_array($resolved['operational'] ?? null) ? $resolved['operational'] : null;
$sources = is_array($resolved['sources'] ?? null) ? $resolved['sources'] : array();

cw_header('Aircraft Settings');
?>
<style>
.aircraft-settings-page { display: grid; gap: 18px; }
.settings-card { background: #fff; border: 1px solid rgba(15, 23, 42, .12); border-radius: 14px; padding: 18px; box-shadow: 0 10px 24px rgba(15, 23, 42, .06); }
.settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
.settings-field label { display: block; font-weight: 700; color: #334155; margin-bottom: 5px; }
.settings-field input, .settings-field select, .settings-field textarea { width: 100%; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 10px; padding: 9px 10px; font: inherit; }
.settings-field textarea { min-height: 150px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; }
.settings-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 12px; }
.settings-btn { border: 0; border-radius: 999px; padding: 9px 14px; background: #1d4ed8; color: #fff; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; }
.settings-btn-secondary { background: #e2e8f0; color: #334155; }
.settings-muted { color: #64748b; font-size: 13px; }
.settings-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 10px; padding: 12px; }
.settings-notice { background: #ecfdf5; border: 1px solid #bbf7d0; color: #166534; border-radius: 10px; padding: 12px; }
.settings-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
.settings-tabs a { border-radius: 999px; background: #f1f5f9; color: #334155; padding: 7px 12px; text-decoration: none; font-weight: 700; font-size: 13px; }
.settings-table-wrap { overflow-x: auto; }
.settings-table { width: 100%; min-width: 980px; border-collapse: collapse; }
.settings-table th, .settings-table td { border-bottom: 1px solid #e2e8f0; padding: 9px 8px; text-align: left; vertical-align: top; }
.settings-table th { color: #475569; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
.settings-pill { display: inline-flex; border-radius: 999px; padding: 3px 8px; font-size: 12px; font-weight: 700; background: #e2e8f0; color: #334155; }
.settings-pill-warning { background: #fee2e2; color: #991b1b; }
.settings-pill-caution { background: #fef3c7; color: #92400e; }
.settings-pill-info { background: #e0f2fe; color: #075985; }
pre.settings-json { margin: 0; white-space: pre-wrap; background: #0f172a; color: #e2e8f0; border-radius: 12px; padding: 12px; max-height: 340px; overflow: auto; }
</style>

<div class="aircraft-settings-page">
  <section class="settings-card">
    <h2 style="margin-top:0">Aircraft Settings</h2>
    <p class="settings-muted">
      Centralized aircraft settings are resolved as replay/player metadata. Replay samples remain factual source-derived data.
      Operational thresholds stay versioned separately from visual layout and presentation defaults.
    </p>
    <div class="settings-actions">
      <a class="settings-btn settings-btn-secondary" href="/admin/cockpit_recorder_aircraft.php">Back to Aircraft</a>
      <?php if ($selectedAircraftId > 0): ?>
        <a class="settings-btn settings-btn-secondary" href="/admin/cockpit_recorder_aircraft_pfd.php?id=<?= (int)$selectedAircraftId ?>">Legacy PFD Profile</a>
      <?php endif; ?>
    </div>
    <div class="settings-tabs">
      <a href="#identity">Identity</a>
      <a href="#layout">Layout</a>
      <a href="#instruments">Instruments</a>
      <a href="#operational">Operational Logic</a>
      <a href="#alerts">Garmin Alerts</a>
      <a href="#advanced">Advanced JSON</a>
    </div>
  </section>

  <?php if ($error !== ''): ?>
    <div class="settings-error"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if ($notice !== ''): ?>
    <div class="settings-notice"><?= h($notice) ?></div>
  <?php endif; ?>

  <section id="identity" class="settings-card">
    <h3 style="margin-top:0">Identity</h3>
    <form method="get" class="settings-grid">
      <div class="settings-field">
        <label for="aircraft_id">Aircraft</label>
        <select id="aircraft_id" name="aircraft_id" onchange="this.form.submit()">
          <?php foreach ($aircraft as $row): ?>
            <option value="<?= (int)($row['id'] ?? 0) ?>" <?= (int)($row['id'] ?? 0) === $selectedAircraftId ? 'selected' : '' ?>>
              <?= h(trim((string)($row['registration'] ?? '') . ' ' . (string)($row['display_name'] ?? ''))) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <noscript><button class="settings-btn" type="submit">Load</button></noscript>
    </form>
    <?php if ($selectedAircraftId > 0): ?>
      <div class="settings-grid" style="margin-top:12px">
        <div><strong>Registration</strong><br><?= h((string)($identity['registration'] ?? '')) ?></div>
        <div><strong>Type / model resolver key</strong><br><?= h((string)($identity['aircraft_type'] ?? '')) ?></div>
        <div><strong>Fallback</strong><br><?= !empty($sources['fallback_used']) ? h((string)($sources['fallback_reason'] ?? 'fallback')) : 'No' ?></div>
        <div><strong>Replay profile version</strong><br><?= h((string)($sources['replay_profile_version'] ?? 'none')) ?></div>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($selectedAircraftId > 0): ?>
    <form method="post">
      <input type="hidden" name="action" value="save_replay_profile">
      <input type="hidden" name="aircraft_id" value="<?= (int)$selectedAircraftId ?>">
      <section id="layout" class="settings-card">
        <h3 style="margin-top:0">Layout</h3>
        <p class="settings-muted">Player layout settings are additive metadata. The player falls back to local defaults and temporary localStorage view overrides.</p>
        <div class="settings-field">
          <label for="layout_config_json">Layout config JSON</label>
          <textarea id="layout_config_json" name="layout_config_json"><?= h(aircraft_settings_json($layout)) ?></textarea>
        </div>
      </section>

      <section id="instruments" class="settings-card">
        <h3 style="margin-top:0">Instruments</h3>
        <p class="settings-muted">Use this JSON for aircraft-specific presentation overrides such as default visible instruments or gauge presentation. Existing legacy PFD data remains visible below.</p>
        <div class="settings-field">
          <label for="instrument_override_json">Instrument override JSON</label>
          <textarea id="instrument_override_json" name="instrument_override_json"><?= h(aircraft_settings_json($instrumentOverride)) ?></textarea>
        </div>
      </section>

      <section class="settings-card">
        <h3 style="margin-top:0">Trim Display</h3>
        <p class="settings-muted">Pitch trim convention: nose down is -100, neutral is 0, nose up is +100.</p>
        <div class="settings-field">
          <label for="trim_config_json">Trim config JSON</label>
          <textarea id="trim_config_json" name="trim_config_json"><?= h(aircraft_settings_json($trim)) ?></textarea>
        </div>
        <div class="settings-field" style="margin-top:12px">
          <label for="change_reason">Change reason</label>
          <input id="change_reason" name="change_reason" maxlength="512" value="Aircraft replay presentation settings update">
        </div>
        <div class="settings-actions">
          <button class="settings-btn" type="submit">Save Replay Presentation Settings</button>
        </div>
      </section>
    </form>

    <section id="operational" class="settings-card">
      <h3 style="margin-top:0">Operational Logic</h3>
      <?php if ($operational): ?>
        <div class="settings-grid">
          <div><strong>Version ID</strong><br><?= h((string)($operational['version_id'] ?? '')) ?></div>
          <div><strong>Hobbs RPM threshold</strong><br><?= h((string)($operational['hobbs_engine_on_rpm_threshold'] ?? '')) ?></div>
          <div><strong>Movement groundspeed</strong><br><?= h((string)($operational['movement_groundspeed_kt'] ?? '')) ?> kt</div>
          <div><strong>Fuel discrepancy</strong><br><?= h((string)($operational['fuel_discrepancy_usg'] ?? '')) ?> USG</div>
        </div>
      <?php else: ?>
        <p class="settings-muted">No operational config version is active for this aircraft.</p>
      <?php endif; ?>
      <p class="settings-muted">These values can alter derived flight records, so they are shown here for visibility and should remain versioned separately from replay/player presentation settings.</p>
    </section>

    <section id="alerts" class="settings-card">
      <h3 style="margin-top:0">Garmin Alerts</h3>
      <p class="settings-muted">Alert severities are resolved from the current catalog at playback time. Unknown alerts remain INFO until classified.</p>
      <div class="settings-table-wrap">
        <table class="settings-table">
          <thead>
            <tr>
              <th>Aircraft Type</th>
              <th>Source</th>
              <th>Alert</th>
              <th>Severity</th>
              <th>Seen</th>
              <th>Notes</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$alertRows): ?>
            <tr><td colspan="7" class="settings-muted">No Garmin alerts have been cataloged yet for this aircraft type.</td></tr>
          <?php endif; ?>
          <?php foreach ($alertRows as $row): ?>
            <tr>
              <form method="post">
                <input type="hidden" name="action" value="save_alert">
                <input type="hidden" name="aircraft_id" value="<?= (int)$selectedAircraftId ?>">
                <input type="hidden" name="alert_id" value="<?= (int)($row['id'] ?? 0) ?>">
                <td><?= h((string)($row['aircraft_type'] ?? '')) ?></td>
                <td><code><?= h((string)($row['source_column'] ?? '')) ?></code></td>
                <td>
                  <div><strong><?= h((string)($row['display_text'] ?: $row['alert_key'] ?? '')) ?></strong></div>
                  <div class="settings-muted"><code><?= h((string)($row['alert_key'] ?? '')) ?></code></div>
                  <input name="display_text" value="<?= h((string)($row['display_text'] ?? '')) ?>" maxlength="255">
                </td>
                <td>
                  <?php $sev = (string)($row['severity'] ?? 'info'); ?>
                  <select name="severity">
                    <option value="warning" <?= $sev === 'warning' ? 'selected' : '' ?>>Warning</option>
                    <option value="caution" <?= $sev === 'caution' ? 'selected' : '' ?>>Caution</option>
                    <option value="info" <?= $sev === 'info' ? 'selected' : '' ?>>Info</option>
                  </select>
                </td>
                <td><?= (int)($row['observation_count'] ?? 0) ?></td>
                <td><textarea name="notes" rows="2" style="min-height:58px"><?= h((string)($row['notes'] ?? '')) ?></textarea></td>
                <td><button class="settings-btn" type="submit">Save</button></td>
              </form>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section id="advanced" class="settings-card">
      <h3 style="margin-top:0">Advanced JSON</h3>
      <div class="settings-grid">
        <div>
          <h4>Resolved Settings</h4>
          <pre class="settings-json"><?= h(aircraft_settings_json($resolved)) ?></pre>
        </div>
        <div>
          <h4>Legacy PFD Profile</h4>
          <pre class="settings-json"><?= h(aircraft_settings_json($instruments['legacy_pfd_profile'] ?? array())) ?></pre>
        </div>
      </div>
    </section>
  <?php endif; ?>
</div>

<?php
cw_footer();
