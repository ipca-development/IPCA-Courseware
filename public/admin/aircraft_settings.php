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

function aircraft_settings_is_composite_alert(array $row): bool
{
    return str_contains((string)($row['display_text'] ?? ''), '/')
        || str_contains((string)($row['alert_key'] ?? ''), '/');
}

function aircraft_settings_seen_at(mixed $value): string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return 'Not recorded';
    }
    $time = strtotime($raw);
    return $time === false ? $raw : date('M j, Y g:i A', $time);
}

function aircraft_settings_severity_label(string $severity): string
{
    return match (strtolower(trim($severity))) {
        'warning' => 'Warning',
        'caution' => 'Caution',
        default => 'Info',
    };
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
$alertGroups = array();
foreach ($alertRows as $row) {
    if (!is_array($row) || aircraft_settings_is_composite_alert($row)) {
        continue;
    }
    $groupAircraft = trim((string)($row['aircraft_type'] ?? '')) ?: 'All aircraft';
    $groupSource = trim((string)($row['source_column'] ?? '')) ?: 'Garmin alert';
    $groupKey = $groupAircraft . "\n" . $groupSource;
    if (!isset($alertGroups[$groupKey])) {
        $alertGroups[$groupKey] = array(
            'aircraft' => $groupAircraft,
            'source' => $groupSource,
            'rows' => array(),
            'count' => 0,
        );
    }
    $alertGroups[$groupKey]['rows'][] = $row;
    $alertGroups[$groupKey]['count']++;
}

cw_header('Aircraft Settings');
?>
<style>
.aircraft-settings-page { display: grid; gap: 18px; color: #0f172a; }
.settings-hero { background: linear-gradient(135deg, #0f172a, #1e3a8a); color: #fff; border-radius: 18px; padding: 22px; box-shadow: 0 16px 34px rgba(15, 23, 42, .18); }
.settings-hero h2 { margin: 0 0 6px; font-size: 26px; }
.settings-hero p { margin: 0; color: rgba(255, 255, 255, .76); }
.settings-hero-grid { display: grid; grid-template-columns: minmax(280px, 1fr) auto; gap: 18px; align-items: end; margin-top: 18px; }
.settings-hero .settings-field label { color: rgba(255, 255, 255, .82); }
.settings-hero .settings-field select { background: #fff; }
.settings-status { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
.settings-card { background: #fff; border: 1px solid rgba(15, 23, 42, .10); border-radius: 16px; padding: 18px; box-shadow: 0 10px 24px rgba(15, 23, 42, .06); }
.settings-card-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 14px; }
.settings-card h3 { margin: 0; font-size: 19px; }
.settings-card p { margin: 5px 0 0; }
.settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 12px; }
.settings-field label { display: block; font-weight: 800; color: #334155; margin-bottom: 5px; }
.settings-field input, .settings-field select { width: 100%; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 11px; padding: 10px 11px; font: inherit; background: #fff; }
.settings-field input:focus, .settings-field select:focus { outline: 2px solid rgba(37, 99, 235, .22); border-color: #2563eb; }
.settings-help { display: block; margin-top: 4px; color: #64748b; font-size: 12px; line-height: 1.35; }
.settings-toggle-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(235px, 1fr)); gap: 10px; }
.settings-toggle { display: flex; align-items: center; gap: 10px; border: 1px solid #e2e8f0; border-radius: 14px; padding: 12px; background: #f8fafc; font-weight: 800; color: #334155; }
.settings-toggle input { width: 18px; height: 18px; flex: 0 0 auto; }
.settings-subtitle { margin: 18px 0 9px; color: #0f172a; font-size: 13px; text-transform: uppercase; letter-spacing: .08em; }
.settings-info-tile { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 12px; color: #334155; }
.settings-info-tile strong { display: block; color: #0f172a; margin-bottom: 3px; }
.settings-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 14px; }
.settings-btn { border: 0; border-radius: 999px; padding: 10px 15px; background: #1d4ed8; color: #fff; font-weight: 800; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
.settings-btn-secondary { background: #e2e8f0; color: #334155; }
.settings-muted { color: #64748b; font-size: 13px; }
.settings-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 12px; padding: 12px; }
.settings-notice { background: #ecfdf5; border: 1px solid #bbf7d0; color: #166534; border-radius: 12px; padding: 12px; }
.settings-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px; }
.settings-tabs a { border-radius: 999px; background: rgba(255, 255, 255, .14); color: #fff; padding: 8px 12px; text-decoration: none; font-weight: 800; font-size: 13px; }
.settings-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; margin-top: 14px; }
.settings-summary div { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 10px 12px; }
.settings-alert-group { border: 1px solid #e2e8f0; border-radius: 16px; padding: 14px; background: #f8fafc; margin-top: 14px; }
.settings-alert-group-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.settings-alert-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); gap: 12px; }
.settings-alert-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 15px; padding: 14px; display: grid; gap: 12px; }
.settings-alert-title { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
.settings-alert-title strong { font-size: 16px; }
.settings-badge { display: inline-flex; align-items: center; border-radius: 999px; padding: 5px 9px; font-size: 12px; font-weight: 900; background: #e2e8f0; color: #334155; white-space: nowrap; }
.settings-badge-warning { background: #fee2e2; color: #991b1b; }
.settings-badge-caution { background: #fef3c7; color: #92400e; }
.settings-badge-info { background: #dbeafe; color: #1e40af; }
.settings-metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 8px; }
.settings-metric { background: #f8fafc; border-radius: 12px; padding: 9px; color: #475569; font-size: 12px; }
.settings-metric strong { display: block; color: #0f172a; font-size: 13px; margin-top: 2px; }
.severity-picker { display: grid; grid-template-columns: repeat(3, 1fr); border: 1px solid #cbd5e1; border-radius: 12px; overflow: hidden; }
.severity-picker label { margin: 0; }
.severity-picker input { position: absolute; opacity: 0; pointer-events: none; }
.severity-picker span { display: block; padding: 9px 6px; text-align: center; background: #fff; color: #475569; font-weight: 900; cursor: pointer; border-left: 1px solid #e2e8f0; }
.severity-picker label:first-child span { border-left: 0; }
.severity-picker input:checked + span { background: #1d4ed8; color: #fff; }
@media (max-width: 760px) {
  .settings-hero-grid, .settings-card-header, .settings-alert-group-header { grid-template-columns: 1fr; display: grid; }
  .settings-status { justify-content: flex-start; }
  .settings-metrics { grid-template-columns: 1fr; }
}
</style>

<div class="aircraft-settings-page">
  <section class="settings-hero">
    <h2>Aircraft Settings</h2>
    <p>Presentation defaults, operational thresholds, and Garmin alert severity assignment for each aircraft.</p>
    <div class="settings-hero-grid">
      <form method="get" class="settings-field">
        <label for="aircraft_id">Aircraft</label>
        <select id="aircraft_id" name="aircraft_id" onchange="this.form.submit()">
          <?php foreach ($aircraft as $row): ?>
            <option value="<?= (int)($row['id'] ?? 0) ?>" <?= (int)($row['id'] ?? 0) === $selectedAircraftId ? 'selected' : '' ?>>
              <?= h(trim((string)($row['registration'] ?? '') . ' ' . (string)($row['display_name'] ?? ''))) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="settings-help">Choose the aircraft to edit. The page reloads with that aircraft's active settings.</span>
        <noscript><button class="settings-btn" type="submit">Load Aircraft</button></noscript>
      </form>
      <div class="settings-status">
        <span class="settings-badge">Replay profile <?= h((string)($sources['replay_profile_version'] ?? 'not saved')) ?></span>
        <span class="settings-badge">Operational <?= h((string)($operational['config_version_uuid'] ?? 'default')) ?></span>
        <a class="settings-btn settings-btn-secondary" href="/admin/cockpit_recorder_aircraft.php">Back to Aircraft</a>
      </div>
    </div>
    <div class="settings-tabs">
      <a href="#player-layout">Player Layout</a>
      <a href="#warning-box">Warning Box</a>
      <a href="#instrument-defaults">Instruments</a>
      <a href="#trim-display">Trim</a>
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

  <?php if ($selectedAircraftId > 0): ?>
    <form method="post">
      <input type="hidden" name="action" value="save_replay_profile">
      <input type="hidden" name="aircraft_id" value="<?= (int)$selectedAircraftId ?>">
      <section id="player-layout" class="settings-card">
        <div class="settings-card-header">
          <div>
            <h3>Player Layout</h3>
            <p class="settings-muted">Default replay layout for this aircraft.</p>
          </div>
          <span class="settings-badge"><?= h((string)($identity['registration'] ?? 'Aircraft')) ?></span>
        </div>
        <div class="settings-grid">
          <div class="settings-field">
            <label for="profile_name">Profile name</label>
            <input id="profile_name" name="profile_name" value="Default" maxlength="128">
            <span class="settings-help">Name for the next saved replay presentation version.</span>
          </div>
          <div class="settings-field">
            <label for="replay_layout_mode">Default replay layout</label>
            <?php $layoutMode = (string)($layout['replay_layout_mode'] ?? 'legacy'); ?>
            <select id="replay_layout_mode" name="replay_layout_mode">
              <option value="legacy" <?= $layoutMode === 'legacy' ? 'selected' : '' ?>>Legacy full-window replay</option>
              <option value="panel" <?= $layoutMode === 'panel' ? 'selected' : '' ?>>Panel layout with engine and compass space</option>
            </select>
            <span class="settings-help">Used when a replay has no personal view override.</span>
          </div>
          <div class="settings-info-tile">
            <strong>Aircraft type</strong>
            <?= h((string)($identity['aircraft_type'] ?? '')) ?>
          </div>
          <div class="settings-info-tile">
            <strong>Fallback status</strong>
            <?= !empty($sources['fallback_used']) ? h((string)($sources['fallback_reason'] ?? 'Fallback in use')) : 'Using aircraft settings' ?>
          </div>
        </div>
      </section>

      <section id="warning-box" class="settings-card">
        <div class="settings-card-header">
          <div>
            <h3>Warning Box Behavior</h3>
            <p class="settings-muted">Controls where stacked Garmin alert lines appear in the player.</p>
          </div>
        </div>
        <div class="settings-grid">
          <div class="settings-field">
            <label for="warning_box_anchor">Anchor</label>
            <?php $warningAnchor = (string)($warningBox['anchor'] ?? 'inset_altitude_profile'); ?>
            <select id="warning_box_anchor" name="warning_box_anchor">
              <option value="inset_altitude_profile" <?= $warningAnchor === 'inset_altitude_profile' ? 'selected' : '' ?>>Inset map altitude section</option>
              <option value="altimeter_bottom" <?= $warningAnchor === 'altimeter_bottom' ? 'selected' : '' ?>>Altimeter bottom</option>
            </select>
            <span class="settings-help">Vertical anchor used by the replay warning box.</span>
          </div>
          <div class="settings-field">
            <label for="warning_box_text_align">Text alignment</label>
            <?php $warningAlign = (string)($warningBox['text_align'] ?? 'left'); ?>
            <select id="warning_box_text_align" name="warning_box_text_align">
              <option value="left" <?= $warningAlign === 'left' ? 'selected' : '' ?>>Left</option>
              <option value="center" <?= $warningAlign === 'center' ? 'selected' : '' ?>>Center</option>
            </select>
            <span class="settings-help">Applies to each alert line in the warning box.</span>
          </div>
          <div class="settings-field">
            <label for="warning_box_left_offset_px">Horizontal offset</label>
            <input id="warning_box_left_offset_px" name="warning_box_left_offset_px" type="number" step="1" value="<?= h((string)($warningBox['left_offset_px'] ?? 0)) ?>">
            <span class="settings-help">Pixels. Negative moves left, positive moves right.</span>
          </div>
          <div class="settings-field">
            <label for="warning_box_width_scale">Width scale</label>
            <input id="warning_box_width_scale" name="warning_box_width_scale" type="number" min="0.5" max="3" step="0.01" value="<?= h((string)($warningBox['width_scale'] ?? 1.33)) ?>">
            <span class="settings-help">Multiplier for the warning box width.</span>
          </div>
        </div>
      </section>

      <section id="instrument-defaults" class="settings-card">
        <div class="settings-card-header">
          <div>
            <h3>Instrument Defaults</h3>
            <p class="settings-muted">Default visibility for instruments when the replay opens.</p>
          </div>
        </div>
        <div class="settings-toggle-grid">
          <?php foreach ($instrumentChoices as $key => $meta): ?>
            <?php $checked = array_key_exists($key, $defaultEnabledInstruments) ? (bool)$defaultEnabledInstruments[$key] : (bool)$meta['default']; ?>
            <label class="settings-toggle">
              <input type="checkbox" name="default_enabled_instruments[<?= h($key) ?>]" value="1" <?= $checked ? 'checked' : '' ?>>
              <span><?= h((string)$meta['label']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </section>

      <section id="trim-display" class="settings-card">
        <div class="settings-card-header">
          <div>
            <h3>Trim Display</h3>
            <p class="settings-muted">Pitch trim convention for the replay trim indicator.</p>
          </div>
        </div>
        <div class="settings-grid">
          <div class="settings-field">
            <label for="trim_min">Scale minimum</label>
            <input id="trim_min" name="trim_min" type="number" step="0.1" value="<?= h((string)($trim['min'] ?? -100)) ?>">
            <span class="settings-help">Usually -100 for full nose down.</span>
          </div>
          <div class="settings-field">
            <label for="trim_neutral">Neutral value</label>
            <input id="trim_neutral" name="trim_neutral" type="number" step="0.1" value="<?= h((string)($trim['neutral'] ?? 0)) ?>">
            <span class="settings-help">The centered trim reference.</span>
          </div>
          <div class="settings-field">
            <label for="trim_max">Scale maximum</label>
            <input id="trim_max" name="trim_max" type="number" step="0.1" value="<?= h((string)($trim['max'] ?? 100)) ?>">
            <span class="settings-help">Usually 100 for full nose up.</span>
          </div>
          <div class="settings-field">
            <label for="trim_nose_down_value">Nose down value</label>
            <input id="trim_nose_down_value" name="trim_nose_down_value" type="number" step="0.1" value="<?= h((string)($trim['nose_down_value'] ?? -100)) ?>">
            <span class="settings-help">Value displayed as nose down.</span>
          </div>
          <div class="settings-field">
            <label for="trim_nose_up_value">Nose up value</label>
            <input id="trim_nose_up_value" name="trim_nose_up_value" type="number" step="0.1" value="<?= h((string)($trim['nose_up_value'] ?? 100)) ?>">
            <span class="settings-help">Value displayed as nose up.</span>
          </div>
          <div class="settings-info-tile">
            <strong>Direction</strong>
            Negative values are nose down. Positive values are nose up.
          </div>
        </div>
        <div class="settings-field" style="margin-top:12px">
          <label for="change_reason">Change reason</label>
          <input id="change_reason" name="change_reason" maxlength="512" value="Aircraft replay presentation settings update">
          <span class="settings-help">Stored with the new replay presentation version.</span>
        </div>
        <div class="settings-actions">
          <button class="settings-btn" type="submit">Save Presentation Settings</button>
        </div>
      </section>
    </form>

    <section id="operational" class="settings-card">
      <div class="settings-card-header">
        <div>
          <h3>Operational Logic</h3>
          <p class="settings-muted">Thresholds used for derived flight records. Saving creates a new version.</p>
        </div>
        <span class="settings-badge"><?= h((string)($operational['config_version_uuid'] ?? 'default preview')) ?></span>
      </div>
      <form method="post">
        <input type="hidden" name="action" value="save_operational_config">
        <input type="hidden" name="aircraft_id" value="<?= (int)$selectedAircraftId ?>">
        <div class="settings-grid">
          <div class="settings-field">
            <label for="hobbs_engine_on_rpm_threshold">Hobbs engine-on threshold</label>
            <input id="hobbs_engine_on_rpm_threshold" name="hobbs_engine_on_rpm_threshold" type="number" min="0" step="1" value="<?= h((string)($operational['hobbs_engine_on_rpm_threshold'] ?? 1000)) ?>">
            <span class="settings-help">RPM required before Hobbs time starts.</span>
          </div>
          <div class="settings-field">
            <label for="hobbs_start_confirm_ms">Hobbs start confirmation</label>
            <input id="hobbs_start_confirm_ms" name="hobbs_start_confirm_ms" type="number" min="0" step="100" value="<?= h((string)($operational['hobbs_start_confirm_ms'] ?? 1000)) ?>">
            <span class="settings-help">Milliseconds above threshold before start.</span>
          </div>
          <div class="settings-field">
            <label for="hobbs_stop_confirm_ms">Hobbs stop confirmation</label>
            <input id="hobbs_stop_confirm_ms" name="hobbs_stop_confirm_ms" type="number" min="0" step="100" value="<?= h((string)($operational['hobbs_stop_confirm_ms'] ?? 5000)) ?>">
            <span class="settings-help">Milliseconds below threshold before stop.</span>
          </div>
          <div class="settings-field">
            <label for="tacho_rpm_threshold">Tacho RPM threshold (optional)</label>
            <input id="tacho_rpm_threshold" name="tacho_rpm_threshold" type="number" min="0" step="1" value="<?= h((string)($operational['tacho_rpm_threshold'] ?? '')) ?>">
            <span class="settings-help">Leave blank when tacho uses the Hobbs threshold.</span>
          </div>
          <div class="settings-field">
            <label for="movement_groundspeed_kt">Movement groundspeed threshold</label>
            <input id="movement_groundspeed_kt" name="movement_groundspeed_kt" type="number" min="0" step="0.1" value="<?= h((string)($operational['movement_groundspeed_kt'] ?? 3.0)) ?>">
            <span class="settings-help">Knots required before ground movement is counted.</span>
          </div>
          <div class="settings-field">
            <label for="movement_confirm_ms">Movement confirmation</label>
            <input id="movement_confirm_ms" name="movement_confirm_ms" type="number" min="0" step="100" value="<?= h((string)($operational['movement_confirm_ms'] ?? 3000)) ?>">
            <span class="settings-help">Milliseconds above movement threshold.</span>
          </div>
          <div class="settings-field">
            <label for="fuel_discrepancy_usg">Fuel discrepancy threshold</label>
            <input id="fuel_discrepancy_usg" name="fuel_discrepancy_usg" type="number" min="0" step="0.1" value="<?= h((string)($operational['fuel_discrepancy_usg'] ?? 1.0)) ?>">
            <span class="settings-help">US gallons before a discrepancy is flagged.</span>
          </div>
          <div class="settings-field">
            <label for="timezone_identifier">Operational timezone</label>
            <input id="timezone_identifier" name="timezone_identifier" value="<?= h((string)($operational['timezone_identifier'] ?? 'UTC')) ?>" maxlength="64">
            <span class="settings-help">IANA timezone name used for operational day grouping.</span>
          </div>
          <div class="settings-field">
            <label for="operational_change_reason">Change reason</label>
            <input id="operational_change_reason" name="operational_change_reason" maxlength="512" value="Operational thresholds update">
            <span class="settings-help">Stored with the new operational version.</span>
          </div>
          <div class="settings-info-tile">
            <strong>Current version</strong>
            <?= h((string)($operational['config_version_uuid'] ?? 'default preview')) ?>
          </div>
        </div>
        <div class="settings-actions">
          <button class="settings-btn" type="submit">Save Operational Version</button>
        </div>
      </form>
    </section>

    <section id="alerts" class="settings-card">
      <div class="settings-card-header">
        <div>
          <h3>Garmin Alert Severity Assignment</h3>
          <p class="settings-muted">Assign each canonical Garmin alert as Warning, Caution, or Info.</p>
        </div>
        <form method="post">
          <input type="hidden" name="action" value="scan_alert_catalog">
          <input type="hidden" name="aircraft_id" value="<?= (int)$selectedAircraftId ?>">
          <button class="settings-btn settings-btn-secondary" type="submit">Scan Garmin CSVs</button>
        </form>
      </div>
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
      <?php if (!$alertGroups): ?>
        <div class="settings-info-tile">
          <strong>No cataloged alerts for this aircraft type yet</strong>
          Run the Garmin CSV scan to populate assignable alerts. Combined slash alerts are not shown here.
        </div>
      <?php endif; ?>
      <?php foreach ($alertGroups as $group): ?>
        <div class="settings-alert-group">
          <div class="settings-alert-group-header">
            <div>
              <strong><?= h((string)$group['aircraft']) ?></strong>
              <div class="settings-muted"><?= h((string)$group['source']) ?></div>
            </div>
            <span class="settings-badge"><?= (int)$group['count'] ?> alert<?= (int)$group['count'] === 1 ? '' : 's' ?></span>
          </div>
          <div class="settings-alert-grid">
          <?php foreach ($group['rows'] as $row): ?>
            <?php $sev = strtolower((string)($row['severity'] ?? 'info')); ?>
            <?php $displayText = trim((string)($row['display_text'] ?: $row['alert_key'] ?? '')); ?>
            <form method="post" class="settings-alert-card">
              <input type="hidden" name="action" value="save_alert">
              <input type="hidden" name="aircraft_id" value="<?= (int)$selectedAircraftId ?>">
              <input type="hidden" name="alert_id" value="<?= (int)($row['id'] ?? 0) ?>">
              <div class="settings-alert-title">
                <strong><?= h($displayText) ?></strong>
                <span class="settings-badge settings-badge-<?= h(in_array($sev, array('warning', 'caution', 'info'), true) ? $sev : 'info') ?>">
                  <?= h(aircraft_settings_severity_label($sev)) ?>
                </span>
              </div>
              <div class="settings-field">
                <label for="display_text_<?= (int)($row['id'] ?? 0) ?>">Display text</label>
                <input id="display_text_<?= (int)($row['id'] ?? 0) ?>" name="display_text" value="<?= h($displayText) ?>" maxlength="255">
                <span class="settings-help">This exact label appears as its own stacked line in the player warning box.</span>
              </div>
              <div>
                <label class="settings-field" style="display:block;margin-bottom:5px;font-weight:800;color:#334155">Severity</label>
                <div class="severity-picker" aria-label="Severity">
                  <label><input type="radio" name="severity" value="warning" <?= $sev === 'warning' ? 'checked' : '' ?>><span>Warning</span></label>
                  <label><input type="radio" name="severity" value="caution" <?= $sev === 'caution' ? 'checked' : '' ?>><span>Caution</span></label>
                  <label><input type="radio" name="severity" value="info" <?= !in_array($sev, array('warning', 'caution'), true) ? 'checked' : '' ?>><span>Info</span></label>
                </div>
              </div>
              <div class="settings-metrics">
                <div class="settings-metric">Source<strong><?= h((string)($row['source_column'] ?? '')) ?></strong></div>
                <div class="settings-metric">Observed<strong><?= number_format((int)($row['observation_count'] ?? 0)) ?></strong></div>
                <div class="settings-metric">First seen<strong><?= h(aircraft_settings_seen_at($row['first_seen_at'] ?? null)) ?></strong></div>
                <div class="settings-metric">Last seen<strong><?= h(aircraft_settings_seen_at($row['last_seen_at'] ?? null)) ?></strong></div>
              </div>
              <div class="settings-field">
                <label for="notes_<?= (int)($row['id'] ?? 0) ?>">Notes</label>
                <input id="notes_<?= (int)($row['id'] ?? 0) ?>" name="notes" value="<?= h((string)($row['notes'] ?? '')) ?>" maxlength="1000">
                <span class="settings-help">Optional classification note.</span>
              </div>
              <div class="settings-actions">
                <button class="settings-btn" type="submit">Save Alert</button>
              </div>
            </form>
          <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</div>

<?php
cw_footer();
