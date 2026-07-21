<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/CockpitAircraftService.php';
require_once __DIR__ . '/../../src/AircraftSettingsService.php';
require_once __DIR__ . '/../../src/AircraftOperationalConfigService.php';

cw_require_admin();

$aircraftService = new CockpitAircraftService($pdo);
$settingsService = new AircraftSettingsService($pdo);
$operationalService = new AircraftOperationalConfigService($pdo);
$error = '';
$notice = '';
$aircraft = array();
$selectedAircraftId = (int)($_GET['aircraft_id'] ?? $_POST['aircraft_id'] ?? 0);
$selectedAircraft = null;
$resolved = array();
$alertRows = array();
$catalogScanSummary = null;
$operational = array();
$instrumentChoices = array(
    'airspeed_indicator' => array('label' => 'Airspeed Indicator', 'default' => true),
    'trim_position_indicator' => array('label' => 'Trim Position Indicator', 'default' => true),
    'altimeter' => array('label' => 'Altimeter / Vertical Speed', 'default' => true),
    'hsi' => array('label' => 'Horizontal Situation Indicator', 'default' => true),
    'aoa_indicator' => array('label' => 'Angle of Attack Indicator', 'default' => true),
    'inset_map' => array('label' => 'Inset Map', 'default' => true),
    'traffic' => array('label' => 'Traffic Overlay', 'default' => false),
    'horizon_bar' => array('label' => 'Horizon Horizontal Bar', 'default' => true),
    'attitude_indicator' => array('label' => 'Attitude Indicator', 'default' => true),
    'flight_director_bars' => array('label' => 'Flight Director Bars', 'default' => false),
    'flight_path_vector' => array('label' => 'Flight Path Vector', 'default' => false),
    'radio_stack' => array('label' => 'Radio Stack', 'default' => false),
    'navaid_stack' => array('label' => 'Navaid Stack', 'default' => false),
    'autopilot_fma' => array('label' => 'Autopilot FMA', 'default' => false),
    'engine_instrument_stack' => array('label' => 'Engine Instrument Stack', 'default' => true),
    'system_warning_box' => array('label' => 'System Warning Box', 'default' => true),
    'wind_indicator' => array('label' => 'Wind Indicator', 'default' => true),
);

function aircraft_settings_json(mixed $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return is_string($json) ? $json : '{}';
}

function aircraft_settings_float(mixed $value, float $fallback): float
{
    return is_numeric($value) ? (float)$value : $fallback;
}

function aircraft_settings_int(mixed $value, int $fallback): int
{
    return is_numeric($value) ? (int)$value : $fallback;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_replay_profile') {
            $selectedAircraftId = (int)($_POST['aircraft_id'] ?? 0);
            $enabledDefaults = is_array($_POST['default_enabled_instruments'] ?? null)
                ? array_map('strval', array_keys((array)$_POST['default_enabled_instruments']))
                : array();
            $instrumentDefaults = array();
            foreach ($instrumentChoices as $key => $meta) {
                $instrumentDefaults[$key] = in_array($key, $enabledDefaults, true);
            }
            $layoutPayload = array(
                'schema_version' => 1,
                'replay_layout_mode' => (string)($_POST['replay_layout_mode'] ?? 'legacy'),
                'system_warning_box' => array(
                    'anchor' => (string)($_POST['warning_box_anchor'] ?? 'inset_altitude_profile'),
                    'left_offset_px' => aircraft_settings_int($_POST['warning_box_left_offset_px'] ?? 0, 0),
                    'width_scale' => aircraft_settings_float($_POST['warning_box_width_scale'] ?? 1.33, 1.33),
                    'text_align' => (string)($_POST['warning_box_text_align'] ?? 'left'),
                    'grow_direction' => 'up',
                ),
            );
            $instrumentPayload = array(
                'schema_version' => 1,
                'default_enabled_instruments' => $instrumentDefaults,
            );
            $trimPayload = array(
                'schema_version' => 1,
                'min' => aircraft_settings_float($_POST['trim_min'] ?? -100, -100.0),
                'neutral' => aircraft_settings_float($_POST['trim_neutral'] ?? 0, 0.0),
                'max' => aircraft_settings_float($_POST['trim_max'] ?? 100, 100.0),
                'nose_down_value' => aircraft_settings_float($_POST['trim_nose_down_value'] ?? -100, -100.0),
                'nose_up_value' => aircraft_settings_float($_POST['trim_nose_up_value'] ?? 100, 100.0),
                'source' => 'admin_ui',
            );
            $settingsService->saveReplayProfile($selectedAircraftId, array(
                'profile_name' => $_POST['profile_name'] ?? 'Default',
                'layout_config_json' => aircraft_settings_json($layoutPayload),
                'instrument_override_json' => aircraft_settings_json($instrumentPayload),
                'trim_config_json' => aircraft_settings_json($trimPayload),
                'change_reason' => $_POST['change_reason'] ?? 'Aircraft replay presentation settings update',
            ));
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
        } elseif ($action === 'scan_alert_catalog') {
            $selectedAircraftId = (int)($_POST['aircraft_id'] ?? 0);
            $catalogScanSummary = $settingsService->rebuildAlertCatalogFromStoredCsvs();
            $notice = sprintf(
                'Garmin alert catalog rebuilt: %d CSV files scanned, %d canonical alerts found, %d stale combined rows removed.',
                (int)($catalogScanSummary['files_scanned'] ?? 0),
                (int)($catalogScanSummary['canonical_alert_count'] ?? 0),
                (int)($catalogScanSummary['removed_composite_rows'] ?? 0)
            );
        } elseif ($action === 'save_operational_config') {
            $selectedAircraftId = (int)($_POST['aircraft_id'] ?? 0);
            $operationalService->saveVersion($selectedAircraftId, array(
                'hobbs_engine_on_rpm_threshold' => $_POST['hobbs_engine_on_rpm_threshold'] ?? null,
                'hobbs_start_confirm_ms' => $_POST['hobbs_start_confirm_ms'] ?? null,
                'hobbs_stop_confirm_ms' => $_POST['hobbs_stop_confirm_ms'] ?? null,
                'tacho_rpm_threshold' => $_POST['tacho_rpm_threshold'] ?? null,
                'movement_groundspeed_kt' => $_POST['movement_groundspeed_kt'] ?? null,
                'movement_confirm_ms' => $_POST['movement_confirm_ms'] ?? null,
                'fuel_discrepancy_usg' => $_POST['fuel_discrepancy_usg'] ?? null,
                'timezone_identifier' => $_POST['timezone_identifier'] ?? 'UTC',
                'change_reason' => $_POST['operational_change_reason'] ?? 'Operational thresholds update',
            ));
            $notice = 'Operational logic settings saved as a new version.';
        }
    }

    $aircraft = $aircraftService->adminAircraft();
    if ($selectedAircraftId <= 0 && isset($aircraft[0]['id'])) {
        $selectedAircraftId = (int)$aircraft[0]['id'];
    }
    if ($selectedAircraftId > 0) {
        $selectedAircraft = $aircraftService->aircraftById($selectedAircraftId);
        $resolved = $settingsService->resolvedForAircraftId($selectedAircraftId);
        $operational = $operationalService->configForAircraft($selectedAircraftId);
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
$operational = is_array($operational ?? null) ? $operational : (is_array($resolved['operational'] ?? null) ? $resolved['operational'] : array());
$sources = is_array($resolved['sources'] ?? null) ? $resolved['sources'] : array();
$warningBox = is_array($layout['system_warning_box'] ?? null) ? $layout['system_warning_box'] : array();
$defaultEnabledInstruments = is_array($instrumentOverride['default_enabled_instruments'] ?? null)
    ? $instrumentOverride['default_enabled_instruments']
    : array();

cw_header('Aircraft Settings');
?>
<style>
.aircraft-settings-page { display: grid; gap: 18px; }
.settings-card { background: #fff; border: 1px solid rgba(15, 23, 42, .12); border-radius: 14px; padding: 18px; box-shadow: 0 10px 24px rgba(15, 23, 42, .06); }
.settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
.settings-field label { display: block; font-weight: 700; color: #334155; margin-bottom: 5px; }
.settings-field input, .settings-field select, .settings-field textarea { width: 100%; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 10px; padding: 9px 10px; font: inherit; }
.settings-field textarea { min-height: 150px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; }
.settings-check-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 10px; }
.settings-check { display: flex; align-items: center; gap: 9px; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 12px; background: #f8fafc; font-weight: 700; color: #334155; }
.settings-check input { width: auto; }
.settings-subtitle { margin: 16px 0 8px; color: #0f172a; font-size: 14px; text-transform: uppercase; letter-spacing: .04em; }
.settings-readonly { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 12px; color: #334155; }
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
.settings-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; margin-top: 12px; }
.settings-summary div { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 12px; }
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
        <div class="settings-grid">
          <div class="settings-field">
            <label for="profile_name">Profile name</label>
            <input id="profile_name" name="profile_name" value="Default" maxlength="128">
          </div>
          <div class="settings-field">
            <label for="replay_layout_mode">Default replay layout</label>
            <?php $layoutMode = (string)($layout['replay_layout_mode'] ?? 'legacy'); ?>
            <select id="replay_layout_mode" name="replay_layout_mode">
              <option value="legacy" <?= $layoutMode === 'legacy' ? 'selected' : '' ?>>Legacy full-window replay</option>
              <option value="panel" <?= $layoutMode === 'panel' ? 'selected' : '' ?>>Panel layout with engine and compass space</option>
            </select>
          </div>
        </div>
        <h4 class="settings-subtitle">Warning Box Placement</h4>
        <div class="settings-grid">
          <div class="settings-field">
            <label for="warning_box_anchor">Anchor</label>
            <?php $warningAnchor = (string)($warningBox['anchor'] ?? 'inset_altitude_profile'); ?>
            <select id="warning_box_anchor" name="warning_box_anchor">
              <option value="inset_altitude_profile" <?= $warningAnchor === 'inset_altitude_profile' ? 'selected' : '' ?>>Inset map altitude section</option>
              <option value="altimeter_bottom" <?= $warningAnchor === 'altimeter_bottom' ? 'selected' : '' ?>>Altimeter bottom</option>
            </select>
          </div>
          <div class="settings-field">
            <label for="warning_box_left_offset_px">Horizontal offset, px</label>
            <input id="warning_box_left_offset_px" name="warning_box_left_offset_px" type="number" step="1" value="<?= h((string)($warningBox['left_offset_px'] ?? 0)) ?>">
          </div>
          <div class="settings-field">
            <label for="warning_box_width_scale">Width scale</label>
            <input id="warning_box_width_scale" name="warning_box_width_scale" type="number" min="0.5" max="3" step="0.01" value="<?= h((string)($warningBox['width_scale'] ?? 1.33)) ?>">
          </div>
          <div class="settings-field">
            <label for="warning_box_text_align">Text alignment</label>
            <?php $warningAlign = (string)($warningBox['text_align'] ?? 'left'); ?>
            <select id="warning_box_text_align" name="warning_box_text_align">
              <option value="left" <?= $warningAlign === 'left' ? 'selected' : '' ?>>Left</option>
              <option value="center" <?= $warningAlign === 'center' ? 'selected' : '' ?>>Center</option>
            </select>
          </div>
        </div>
      </section>

      <section id="instruments" class="settings-card">
        <h3 style="margin-top:0">Instruments</h3>
        <p class="settings-muted">These are aircraft defaults for new/unset replay views. A user's local replay toggle remains a temporary personal override.</p>
        <div class="settings-check-grid">
          <?php foreach ($instrumentChoices as $key => $meta): ?>
            <?php $checked = array_key_exists($key, $defaultEnabledInstruments) ? (bool)$defaultEnabledInstruments[$key] : (bool)$meta['default']; ?>
            <label class="settings-check">
              <input type="checkbox" name="default_enabled_instruments[<?= h($key) ?>]" value="1" <?= $checked ? 'checked' : '' ?>>
              <span><?= h((string)$meta['label']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="settings-card">
        <h3 style="margin-top:0">Trim Display</h3>
        <p class="settings-muted">Pitch trim convention: nose down is -100, neutral is 0, nose up is +100.</p>
        <div class="settings-grid">
          <div class="settings-field">
            <label for="trim_min">Minimum shown on scale</label>
            <input id="trim_min" name="trim_min" type="number" step="0.1" value="<?= h((string)($trim['min'] ?? -100)) ?>">
          </div>
          <div class="settings-field">
            <label for="trim_neutral">Neutral value</label>
            <input id="trim_neutral" name="trim_neutral" type="number" step="0.1" value="<?= h((string)($trim['neutral'] ?? 0)) ?>">
          </div>
          <div class="settings-field">
            <label for="trim_max">Maximum shown on scale</label>
            <input id="trim_max" name="trim_max" type="number" step="0.1" value="<?= h((string)($trim['max'] ?? 100)) ?>">
          </div>
          <div class="settings-field">
            <label for="trim_nose_down_value">Nose down value</label>
            <input id="trim_nose_down_value" name="trim_nose_down_value" type="number" step="0.1" value="<?= h((string)($trim['nose_down_value'] ?? -100)) ?>">
          </div>
          <div class="settings-field">
            <label for="trim_nose_up_value">Nose up value</label>
            <input id="trim_nose_up_value" name="trim_nose_up_value" type="number" step="0.1" value="<?= h((string)($trim['nose_up_value'] ?? 100)) ?>">
          </div>
          <div class="settings-readonly">
            <strong>Direction</strong><br>
            Negative values are nose down. Positive values are nose up.
          </div>
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
      <p class="settings-muted">These settings affect derived flight records and are saved as versioned operational rules. Changes here do not rewrite old records automatically.</p>
      <form method="post">
        <input type="hidden" name="action" value="save_operational_config">
        <input type="hidden" name="aircraft_id" value="<?= (int)$selectedAircraftId ?>">
        <div class="settings-grid">
          <div class="settings-field">
            <label for="hobbs_engine_on_rpm_threshold">Hobbs engine-on RPM threshold</label>
            <input id="hobbs_engine_on_rpm_threshold" name="hobbs_engine_on_rpm_threshold" type="number" min="0" step="1" value="<?= h((string)($operational['hobbs_engine_on_rpm_threshold'] ?? 1000)) ?>">
          </div>
          <div class="settings-field">
            <label for="hobbs_start_confirm_ms">Hobbs start confirmation, ms</label>
            <input id="hobbs_start_confirm_ms" name="hobbs_start_confirm_ms" type="number" min="0" step="100" value="<?= h((string)($operational['hobbs_start_confirm_ms'] ?? 1000)) ?>">
          </div>
          <div class="settings-field">
            <label for="hobbs_stop_confirm_ms">Hobbs stop confirmation, ms</label>
            <input id="hobbs_stop_confirm_ms" name="hobbs_stop_confirm_ms" type="number" min="0" step="100" value="<?= h((string)($operational['hobbs_stop_confirm_ms'] ?? 5000)) ?>">
          </div>
          <div class="settings-field">
            <label for="tacho_rpm_threshold">Tacho RPM threshold (optional)</label>
            <input id="tacho_rpm_threshold" name="tacho_rpm_threshold" type="number" min="0" step="1" value="<?= h((string)($operational['tacho_rpm_threshold'] ?? '')) ?>">
          </div>
          <div class="settings-field">
            <label for="movement_groundspeed_kt">Movement groundspeed threshold, kt</label>
            <input id="movement_groundspeed_kt" name="movement_groundspeed_kt" type="number" min="0" step="0.1" value="<?= h((string)($operational['movement_groundspeed_kt'] ?? 3.0)) ?>">
          </div>
          <div class="settings-field">
            <label for="movement_confirm_ms">Movement confirmation, ms</label>
            <input id="movement_confirm_ms" name="movement_confirm_ms" type="number" min="0" step="100" value="<?= h((string)($operational['movement_confirm_ms'] ?? 3000)) ?>">
          </div>
          <div class="settings-field">
            <label for="fuel_discrepancy_usg">Fuel discrepancy threshold, USG</label>
            <input id="fuel_discrepancy_usg" name="fuel_discrepancy_usg" type="number" min="0" step="0.1" value="<?= h((string)($operational['fuel_discrepancy_usg'] ?? 1.0)) ?>">
          </div>
          <div class="settings-field">
            <label for="timezone_identifier">Operational timezone</label>
            <input id="timezone_identifier" name="timezone_identifier" value="<?= h((string)($operational['timezone_identifier'] ?? 'UTC')) ?>" maxlength="64" placeholder="UTC">
          </div>
          <div class="settings-field">
            <label for="operational_change_reason">Change reason</label>
            <input id="operational_change_reason" name="operational_change_reason" maxlength="512" value="Operational thresholds update">
          </div>
          <div class="settings-readonly">
            <strong>Current version</strong><br>
            <?= h((string)($operational['config_version_uuid'] ?? 'default preview')) ?>
          </div>
        </div>
        <div class="settings-actions">
          <button class="settings-btn" type="submit">Save Operational Logic Version</button>
        </div>
      </form>
    </section>

    <section id="alerts" class="settings-card">
      <h3 style="margin-top:0">Garmin Alerts</h3>
      <p class="settings-muted">Alert severities are resolved from the current catalog at playback time. Use the scanner to analyze all stored Garmin CSV files and build the canonical alert list. Unknown alerts remain INFO until classified.</p>
      <form method="post" class="settings-actions">
        <input type="hidden" name="action" value="scan_alert_catalog">
        <input type="hidden" name="aircraft_id" value="<?= (int)$selectedAircraftId ?>">
        <button class="settings-btn" type="submit">Scan All Stored Garmin CSVs</button>
      </form>
      <?php if (is_array($catalogScanSummary)): ?>
        <div class="settings-summary">
          <div><strong>Total CSV files</strong><br><?= (int)($catalogScanSummary['files_total'] ?? 0) ?></div>
          <div><strong>Scanned</strong><br><?= (int)($catalogScanSummary['files_scanned'] ?? 0) ?></div>
          <div><strong>Failed</strong><br><?= (int)($catalogScanSummary['files_failed'] ?? 0) ?></div>
          <div><strong>CSV rows checked</strong><br><?= (int)($catalogScanSummary['rows_scanned'] ?? 0) ?></div>
          <div><strong>Canonical alerts</strong><br><?= (int)($catalogScanSummary['canonical_alert_count'] ?? 0) ?></div>
          <div><strong>Combined rows removed</strong><br><?= (int)($catalogScanSummary['removed_composite_rows'] ?? 0) ?></div>
        </div>
      <?php endif; ?>
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
  <?php endif; ?>
</div>

<?php
cw_footer();
