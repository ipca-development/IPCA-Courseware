<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/CockpitRecorderService.php';

cw_require_admin();

$id = trim((string)($_GET['id'] ?? ''));
$standaloneReplay = trim((string)($_GET['standalone'] ?? ''));
$error = '';
$recording = null;
$cesiumIonToken = trim((string)(getenv('CW_CESIUM_ION_TOKEN') ?: getenv('CESIUM_ION_TOKEN') ?: ''));
$cesiumIonToken = trim($cesiumIonToken, " \t\n\r\0\x0B\"'");
$defaultAirspeedProfile = array(
    'aircraft_model_code' => 'PIAT',
    'aircraft_model_name' => 'Alpha Trainer Pro',
    'units' => 'KT',
    'source' => 'PIAT fallback airspeed markings',
    'tape_min_kt' => 0,
    'tape_max_kt' => 160,
    'white_arc' => array('from_kt' => 42, 'to_kt' => 70),
    'green_arc' => array('from_kt' => 49, 'to_kt' => 104),
    'yellow_arc' => array('from_kt' => 104, 'to_kt' => 130),
    'red_line_kt' => 130,
    'aoa_indicator' => array(
        'visible_threshold' => 0.35,
        'caution_threshold' => 0.55,
        'warning_threshold' => 0.75,
        'stall_threshold' => 1.0,
        'units' => 'normalized',
    ),
    'bugs' => array(
        array('label' => 'R', 'speed_kt' => 50, 'description' => 'Vr Rotation Speed'),
        array('label' => 'X', 'speed_kt' => 58, 'description' => 'Vx Best Angle of Climb'),
        array('label' => 'G', 'speed_kt' => 68, 'description' => 'Vg Best Glide'),
        array('label' => 'Y', 'speed_kt' => 75, 'description' => 'Vy Best Rate of Climb'),
        array('label' => 'A', 'speed_kt' => 90, 'description' => 'Va Design Maneuvering Speed'),
    ),
);
$airspeedProfile = $defaultAirspeedProfile;
$defaultEngineProfile = array(
    'aircraft_model_code' => 'PIAT',
    'aircraft_model_name' => 'Alpha Trainer Pro',
    'source' => 'PIAT fallback engine markings',
    'instruments' => array(
        array('key' => 'rpm', 'label' => 'RPM', 'unit' => '', 'min' => 0, 'max' => 6000, 'kind' => 'arc', 'value_field' => 'rpm', 'decimals' => 0, 'ranges' => array(
            array('color' => 'white', 'from' => 0, 'to' => 1750),
            array('color' => 'green', 'from' => 1750, 'to' => 5500),
            array('color' => 'yellow', 'from' => 5500, 'to' => 5800),
            array('color' => 'red', 'from' => 5800, 'to' => 6000),
        )),
        array('key' => 'fuel_flow_gph', 'label' => 'GPH', 'unit' => '', 'min' => 0, 'max' => 7.9, 'value_field' => 'fuel_flow_gph', 'decimals' => 1, 'ranges' => array(
            array('color' => 'white', 'from' => 0, 'to' => 1.3),
            array('color' => 'green', 'from' => 1.3, 'to' => 6.6),
            array('color' => 'white', 'from' => 6.6, 'to' => 7.9),
        )),
        array('key' => 'oil_pressure_psi', 'label' => 'OIL PSI', 'unit' => '', 'min' => 0, 'max' => 113, 'value_field' => 'oil_pressure_psi', 'decimals' => 0, 'ranges' => array(
            array('color' => 'red', 'from' => 0, 'to' => 12),
            array('color' => 'white', 'from' => 12, 'to' => 29),
            array('color' => 'green', 'from' => 29, 'to' => 73),
            array('color' => 'yellow', 'from' => 73, 'to' => 102),
            array('color' => 'red', 'from' => 102, 'to' => 113),
        )),
        array('key' => 'oil_temp_f', 'label' => 'OIL °F', 'unit' => '', 'min' => 104, 'max' => 300, 'value_field' => 'oil_temp_f', 'decimals' => 0, 'ranges' => array(
            array('color' => 'black', 'from' => 104, 'to' => 122),
            array('color' => 'white', 'from' => 122, 'to' => 194),
            array('color' => 'green', 'from' => 194, 'to' => 230),
            array('color' => 'white', 'from' => 230, 'to' => 248),
            array('color' => 'yellow', 'from' => 248, 'to' => 284),
            array('color' => 'red', 'from' => 284, 'to' => 300),
        )),
        array('key' => 'egt1_f', 'label' => 'EGT °F', 'unit' => '', 'min' => 752, 'max' => 1706, 'value_field' => 'egt1_f', 'decimals' => 0, 'probe_label' => '1', 'ranges' => array(
            array('color' => 'white', 'from' => 752, 'to' => 1022),
            array('color' => 'green', 'from' => 1022, 'to' => 1589),
            array('color' => 'yellow', 'from' => 1589, 'to' => 1616),
            array('color' => 'red', 'from' => 1616, 'to' => 1706),
        )),
        array('key' => 'fuel_qty_gal', 'label' => 'FUEL GAL', 'unit' => '', 'min' => 0, 'max' => 16, 'value_field' => 'fuel_qty_gal', 'decimals' => 0, 'ranges' => array(
            array('color' => 'yellow', 'from' => 0, 'to' => 2),
            array('color' => 'green', 'from' => 2, 'to' => 16),
        )),
        array('key' => 'fuel_pressure_psi', 'label' => 'FUEL PSI', 'unit' => '', 'min' => 0, 'max' => 7.3, 'value_field' => 'fuel_pressure_psi', 'decimals' => 1, 'ranges' => array(
            array('color' => 'white', 'from' => 0, 'to' => 2.2),
            array('color' => 'green', 'from' => 2.2, 'to' => 5.8),
            array('color' => 'white', 'from' => 5.8, 'to' => 7.3),
        )),
        array('key' => 'coolant1_f', 'label' => 'COOLANT °F', 'unit' => '', 'min' => 86, 'max' => 266, 'value_field' => 'coolant1_f', 'decimals' => 0, 'probe_label' => '1', 'ranges' => array(
            array('color' => 'white', 'from' => 86, 'to' => 248),
            array('color' => 'yellow', 'from' => null, 'to' => null),
            array('color' => 'red', 'from' => 248, 'to' => 266),
        )),
        array('key' => 'coolant2_f', 'label' => '', 'unit' => '', 'min' => 86, 'max' => 266, 'value_field' => 'coolant2_f', 'decimals' => 0, 'probe_label' => '2', 'ranges' => array(
            array('color' => 'white', 'from' => 86, 'to' => 248),
            array('color' => 'yellow', 'from' => null, 'to' => null),
            array('color' => 'red', 'from' => 248, 'to' => 266),
        )),
        array('key' => 'volts', 'label' => 'VOLTS', 'unit' => '', 'min' => 11.5, 'max' => 16, 'value_field' => 'volts', 'decimals' => 1, 'alert_style' => 'range_label', 'ranges' => array(
            array('color' => 'red', 'from' => 11.5, 'to' => 12.8),
            array('color' => 'yellow', 'from' => 12.8, 'to' => 13.2),
            array('color' => 'green', 'from' => 13.2, 'to' => 14.6),
            array('color' => 'yellow', 'from' => 14.6, 'to' => 15.5),
            array('color' => 'red', 'from' => 15.5, 'to' => 16),
        )),
        array('key' => 'amps', 'label' => 'AMPS', 'unit' => '', 'min' => -40, 'max' => 40, 'value_field' => 'amps', 'decimals' => 0, 'kind' => 'ammeter', 'ranges' => array(
            array('color' => 'green_line', 'from' => 0, 'to' => 20),
        )),
    ),
);
$engineProfile = $defaultEngineProfile;

function replay_table_exists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute(array($tableName));
    return (int)$stmt->fetchColumn() > 0;
}

function replay_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute(array($tableName, $columnName));
    return (int)$stmt->fetchColumn() > 0;
}

function replay_aircraft_model_code(?array $recording): string
{
    $type = strtoupper(trim((string)($recording['aircraft_type'] ?? '')));
    if ($type !== '' && (str_contains($type, 'PIAT') || str_contains($type, 'ALPHA'))) {
        return 'PIAT';
    }
    return 'PIAT';
}

try {
    if ($id === '' && $standaloneReplay === '') {
        throw new RuntimeException('Recording id is required.');
    }
    if ($id !== '') {
        $recording = (new CockpitRecorderService($pdo))->recordingByAnyId($id);
        if (!$recording) {
            throw new RuntimeException('Recording not found.');
        }
    }
    if (replay_table_exists($pdo, 'ipca_aircraft_instrument_profiles')) {
        $modelCode = replay_aircraft_model_code(is_array($recording) ? $recording : null);
        $hasEngineConfig = replay_column_exists($pdo, 'ipca_aircraft_instrument_profiles', 'engine_config_json');
        $selectColumns = $hasEngineConfig ? 'airspeed_config_json, engine_config_json' : 'airspeed_config_json';
        $stmt = $pdo->prepare('SELECT ' . $selectColumns . ' FROM ipca_aircraft_instrument_profiles WHERE aircraft_model_code = ? AND profile_code = ? AND active = 1 LIMIT 1');
        $stmt->execute(array($modelCode, 'default'));
        $profileRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $json = is_array($profileRow) ? (string)($profileRow['airspeed_config_json'] ?? '') : '';
        if (is_string($json) && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $airspeedProfile = array_replace_recursive($defaultAirspeedProfile, $decoded);
                $airspeedProfile['source'] = (string)($airspeedProfile['source'] ?? 'database');
            }
        }
        $engineJson = is_array($profileRow) ? (string)($profileRow['engine_config_json'] ?? '') : '';
        if ($engineJson !== '') {
            $decoded = json_decode($engineJson, true);
            if (is_array($decoded)) {
                $engineProfile = array_replace_recursive($defaultEngineProfile, $decoded);
                $engineProfile['source'] = (string)($engineProfile['source'] ?? 'database');
            }
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

cw_header('Cockpit Recorder Replay');
?>
<link href="https://cdn.jsdelivr.net/npm/cesium@1.119.0/Build/Cesium/Widgets/widgets.css" rel="stylesheet">
<style>
.replay-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 10px; padding: 12px; margin: 16px; }
.replay-avionics-header {
  width: 100%;
  height: 45px;
  min-height: 45px;
  box-sizing: border-box;
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(68px, .42fr) minmax(0, 1.34fr) minmax(0, 1fr) minmax(0, 1fr);
  align-items: center;
  gap: 2px;
  padding: 3px 4px;
  background: linear-gradient(180deg, rgba(15, 23, 42, .96), rgba(15, 23, 42, .88));
  border-bottom: 1px solid rgba(148, 163, 184, .24);
  color: rgba(226, 232, 240, .72);
  font: 700 11px/1.2 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
  letter-spacing: .08em;
  text-transform: uppercase;
  overflow: hidden;
}
.replay-avionics-brand {
  display: none;
  color: rgba(226, 232, 240, .70);
  font-size: 7px;
  letter-spacing: .10em;
  padding-left: 4px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.replay-avionics-group {
  display: contents;
}
.replay-avionics-group[hidden] {
  display: none !important;
}
.avionics-box {
  height: 39px;
  box-sizing: border-box;
  display: grid;
  grid-template-columns: 32px minmax(0, 1fr) minmax(0, 1fr);
  align-items: stretch;
  overflow: hidden;
  border: 1px solid rgba(226, 232, 240, .32);
  border-radius: 8px;
  background: rgba(15, 23, 42, .52);
  backdrop-filter: blur(7px);
  box-shadow: inset 0 0 12px rgba(15, 23, 42, .42), 0 1px 3px rgba(0, 0, 0, .24);
  color: #f8fafc;
}
.avionics-box.is-radio {
  min-width: 0;
}
.avionics-box.is-nav {
  min-width: 0;
}
.avionics-box.is-xpdr {
  min-width: 0;
  grid-template-columns: 28px minmax(0, 1fr);
}
.avionics-box.is-afcs {
  min-width: 0;
  grid-template-columns: 30px repeat(4, minmax(0, 1fr));
}
.avionics-label {
  min-width: 0;
  padding: 5px 2px 0;
  font-size: 7px;
  font-weight: 900;
  line-height: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: clip;
}
.avionics-sub-label {
  align-self: end;
  padding: 0 7px 5px;
  color: rgba(248, 250, 252, .94);
  font-size: clamp(8px, .75vw, 12px);
  line-height: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  grid-column: 2 / span 1;
}
.avionics-frequency {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-width: 0;
  overflow: hidden;
  border-left: 1px solid rgba(226, 232, 240, .28);
  line-height: 1;
}
.avionics-value {
  max-width: 100%;
  overflow: hidden;
  text-overflow: clip;
  color: #5fe348;
  font-size: 13px;
  font-weight: 500;
  letter-spacing: .02em;
  white-space: nowrap;
}
.avionics-value-line {
  display: flex;
  align-items: baseline;
  justify-content: center;
  gap: 3px;
  max-width: 100%;
  min-width: 0;
}
.avionics-rx-tx {
  color: #5fe348;
  font-size: 7px;
  font-weight: 900;
  line-height: 1;
}
.avionics-value.is-standby {
  color: #78fff1;
}
.avionics-value.is-white {
  color: #f8fafc;
}
.avionics-box.is-radio .avionics-value,
.avionics-box.is-nav .avionics-value {
  font-size: 17.5px;
}
.avionics-name {
  margin-top: 1px;
  max-width: 100%;
  color: #f8fafc;
  font-size: 5.5px;
  font-weight: 900;
  line-height: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.avionics-box.is-nav .avionics-frequency:first-of-type .avionics-name {
  margin-top: 3px;
  font-size: 13.8px;
}
.avionics-box.is-xpdr .avionics-value {
  font-size: 17.5px;
}
.avionics-box.is-xpdr .avionics-name {
  color: #5fe348;
  font-size: 7px;
}
.avionics-afcs-title {
  align-self: start;
  padding: 5px 2px 0;
  font-size: 6.5px;
  font-weight: 900;
  white-space: nowrap;
  overflow: hidden;
}
.avionics-afcs-cell {
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 0;
  overflow: hidden;
  border-left: 1px solid rgba(226, 232, 240, .28);
  color: #5fe348;
  font-size: 16px;
  font-weight: 500;
  line-height: 1;
  white-space: nowrap;
}
.avionics-afcs-cell.is-white {
  color: #f8fafc;
  font-size: 8px;
}
.avionics-afcs-cell.is-pft {
  color: #f8fafc;
  animation: afcs-flash 0.55s steps(1, end) infinite;
}
.avionics-afcs-cell.is-ap-disconnect {
  color: #f8fafc;
}
.avionics-afcs-ap-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border-radius: 1px;
  line-height: 1;
}
.avionics-afcs-cell.is-ap-disconnect .avionics-afcs-ap-badge {
  color: #111827;
  background: #f5d328;
  font-weight: 900;
  animation: afcs-flash 0.45s steps(1, end) infinite;
}
@keyframes afcs-flash {
  0%, 48% { opacity: 1; }
  49%, 100% { opacity: .12; }
}
.avionics-box.is-nav .avionics-frequency:first-of-type .avionics-value {
  color: #f8fafc;
}
.avionics-box.is-nav.is-active-cdi .avionics-frequency:first-of-type .avionics-value {
  color: #5fe348;
}
.avionics-muted {
  opacity: .45;
}
.replay-immersive {
  position: relative;
  width: 100%;
  height: calc(100vh - 88px);
  min-height: 480px;
  background: #000;
  overflow: hidden;
  --attitude-center-x: 50%;
  --panel-engine-width: clamp(0px, 0vw, 0px);
  --panel-bottom-band: 0px;
  --panel-playback-height: 44px;
}
.replay-immersive .cesium-cockpit { position: absolute; inset: 0; }
.replay-immersive .cesium-viewer,
.replay-immersive .cesium-viewer-cesiumWidget,
.replay-immersive .cesium-widget,
.replay-immersive .cesium-widget canvas { width: 100% !important; height: 100% !important; }
.replay-immersive .cesium-viewer-bottom,
.replay-immersive .cesium-viewer-toolbar,
.replay-immersive .cesium-viewer-animationContainer,
.replay-immersive .cesium-viewer-timelineContainer,
.replay-immersive .cesium-viewer-fullscreenContainer,
.replay-immersive .cesium-viewer-bottom .cesium-widget-credits { display: none !important; }
.replay-immersive.is-panel-layout {
  --panel-engine-width: clamp(118px, 13.5vw, 150px);
  --panel-bottom-band: calc(clamp(104px, 17vh, 152px) + 50px);
  --panel-playback-height: 38px;
}
.replay-engine-pane,
.replay-bottom-instrument-pane {
  display: none;
  position: absolute;
  z-index: 17;
  pointer-events: none;
  color: rgba(226, 232, 240, .78);
  font: 700 11px/1.35 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  letter-spacing: .04em;
  text-transform: uppercase;
}
.replay-immersive.is-panel-layout .replay-engine-pane {
  display: flex;
  left: 0;
  top: 0;
  bottom: var(--panel-playback-height);
  width: var(--panel-engine-width);
  align-items: flex-start;
  justify-content: center;
  padding-top: 58px;
  box-sizing: border-box;
  background: linear-gradient(90deg, rgba(15, 23, 42, .68), rgba(15, 23, 42, .50));
  border-right: 1px solid rgba(226, 232, 240, .16);
}
.replay-immersive.is-panel-layout .replay-bottom-instrument-pane {
  display: flex;
  left: var(--panel-engine-width);
  right: 0;
  bottom: var(--panel-playback-height);
  height: var(--panel-bottom-band);
  align-items: center;
  justify-content: center;
  background: linear-gradient(180deg, rgba(15, 23, 42, .00), rgba(15, 23, 42, .14));
  border-top: 1px solid rgba(226, 232, 240, .06);
}
.replay-immersive.is-panel-layout .replay-bottom-instrument-pane .replay-pane-label {
  display: none;
}
.replay-pane-label {
  border: 1px dashed rgba(226, 232, 240, .22);
  border-radius: 999px;
  padding: 6px 10px;
  background: rgba(15, 23, 42, .18);
}
.hsi-overlay {
  position: absolute;
  left: var(--attitude-center-x, 50%);
  bottom: calc(var(--panel-playback-height) + 34px);
  z-index: 19;
  width: clamp(300px, 34vw, 390px);
  height: clamp(255px, 30vw, 330px);
  transform: translateX(-50%);
  pointer-events: none;
  filter: drop-shadow(0 2px 5px rgba(0, 0, 0, .28));
  overflow: visible;
}
.hsi-overlay[hidden],
.attitude-overlay[hidden],
.replay-horizon-line[hidden],
.airspeed-tape[hidden],
.altimeter-stack[hidden],
.engine-panel[hidden] {
  display: none !important;
}
.hsi-overlay text {
  fill: rgba(255, 255, 255, .94);
  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  font-weight: 650;
  paint-order: stroke;
  stroke: rgba(15, 23, 42, .30);
  stroke-width: 1px;
  stroke-linejoin: round;
}
.hsi-overlay .hsi-label-box {
  fill: rgba(15, 23, 42, .68);
  stroke: rgba(255, 255, 255, .20);
  stroke-width: 1;
}
.hsi-overlay .hsi-rose-line,
.hsi-overlay .hsi-tick,
.hsi-overlay .hsi-aircraft,
.hsi-overlay .hsi-course-line {
  stroke: rgba(255, 255, 255, .88);
  stroke-width: 1.4;
  fill: none;
}
.hsi-overlay .hsi-minor-tick {
  stroke: rgba(255, 255, 255, .70);
  stroke-width: .9;
}
.hsi-overlay .hsi-card-fill {
  fill: rgba(40, 40, 40, .46);
  stroke: rgba(255, 255, 255, .28);
  stroke-width: 1.2;
}
.hsi-overlay .hsi-heading-bug {
  fill: rgba(127, 237, 255, .92);
  stroke: rgba(255, 255, 255, .66);
  stroke-width: .8;
}
.hsi-overlay .hsi-track-diamond {
  fill: rgba(218, 63, 255, .90);
  stroke: rgba(255, 255, 255, .66);
  stroke-width: .8;
}
.hsi-overlay .hsi-heading-trend {
  fill: none;
  stroke: #d84cff;
  stroke-width: 5;
  stroke-linecap: round;
  stroke-linejoin: round;
  filter: drop-shadow(0 1px 1px rgba(0, 0, 0, .36));
}
.hsi-overlay .hsi-heading-trend-arrow {
  fill: #d84cff;
  stroke: #d84cff;
  stroke-width: 1;
}
.hsi-overlay .hsi-turn-rate-mark {
  stroke: rgba(255, 255, 255, .96);
  stroke-width: 1.6;
  stroke-linecap: round;
  fill: none;
}
.hsi-overlay .hsi-turn-rate-mark.is-half {
  opacity: .86;
  stroke-width: 1.3;
}
.hsi-overlay .hsi-top-pointer {
  fill: rgba(255, 255, 255, .96);
  stroke: rgba(255, 255, 255, .72);
  stroke-width: .7;
}
.hsi-overlay .hsi-nav {
  stroke: #10d510;
  stroke-width: 5;
  fill: none;
}
.hsi-overlay .hsi-nav.is-gps {
  stroke: #d84cff;
}
.hsi-overlay .hsi-nav-arrow {
  fill: #10d510;
  stroke: #10d510;
  stroke-width: 1;
}
.hsi-overlay .hsi-nav-arrow.is-gps {
  fill: #d84cff;
  stroke: #d84cff;
}
.hsi-overlay .hsi-cdi-dot {
  fill: none;
  stroke: rgba(255, 255, 255, .96);
  stroke-width: 2.4;
}
.hsi-overlay .hsi-cdi-course {
  stroke: #10d510;
  stroke-width: 4;
  fill: none;
}
.hsi-overlay .hsi-cdi-course.is-gps {
  stroke: #d84cff;
}
.hsi-overlay .hsi-to-from-flag {
  fill: #10d510;
  stroke: #10d510;
  stroke-width: .7;
}
.hsi-overlay .hsi-rmi-bearing {
  stroke: #7fefff;
  stroke-width: 2;
  stroke-linecap: butt;
  fill: none;
  filter: drop-shadow(0 1px 1px rgba(0, 0, 0, .36));
}
.hsi-overlay .hsi-rmi-bearing-arrow {
  fill: none;
  stroke: #7fefff;
  stroke-width: 2;
  stroke-linecap: butt;
  stroke-linejoin: miter;
  filter: drop-shadow(0 1px 1px rgba(0, 0, 0, .36));
}
.hsi-overlay .hsi-nav-text {
  fill: #10d510;
  stroke: rgba(15, 23, 42, .20);
  stroke-width: .8px;
}
.hsi-overlay .hsi-nav-text.is-gps {
  fill: #d84cff;
}
.hsi-overlay .hsi-heading-text { fill: rgba(255, 255, 255, .98); font-size: 16px; }
.hsi-overlay .hsi-heading-value { fill: #ffffff; font-size: 17px; }
.hsi-overlay .hsi-crs-text { fill: rgba(255, 255, 255, .98); font-size: 16px; }
.hsi-overlay .hsi-cyan { fill: #9ffcff; }
.hsi-overlay .hsi-green { fill: #18d918; }
.hsi-overlay .hsi-magenta { fill: #d84cff; }
.hsi-overlay .hsi-wind-text {
  fill: rgba(255, 255, 255, .96);
  font-size: 13px;
  font-weight: 760;
}
.hsi-overlay .hsi-wind-calm {
  fill: rgba(255, 255, 255, .96);
  font-size: 14px;
  font-weight: 760;
}
.hsi-overlay .hsi-wind-arrow {
  fill: rgba(255, 255, 255, .96);
  stroke: rgba(255, 255, 255, .72);
  stroke-width: .45;
  filter: drop-shadow(0 1px 1px rgba(0, 0, 0, .42));
}
.hsi-overlay .hsi-rose-label {
  font-size: 20px;
  font-weight: 650;
}
.replay-inset-map {
  position: absolute;
  z-index: 20;
  width: 240px;
  color: rgba(255, 255, 255, .94);
  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  pointer-events: none;
  filter: drop-shadow(0 2px 5px rgba(0, 0, 0, .28));
}
.replay-inset-map[hidden] { display: none !important; }
.replay-inset-map-top,
.replay-inset-map-profile {
  background: rgba(40, 40, 40, .46);
  border: 1px solid rgba(255, 255, 255, .28);
  box-sizing: border-box;
  overflow: hidden;
}
.replay-inset-map-top {
  position: relative;
  width: 100%;
  aspect-ratio: 1 / 1;
  border-radius: 17px 17px 8px 8px;
  pointer-events: auto;
  cursor: grab;
  touch-action: none;
}
.replay-inset-map-top.is-dragging {
  cursor: grabbing;
}
.replay-inset-map-profile {
  width: 100%;
  height: var(--inset-map-profile-height, 54px);
  margin-top: 4px;
  border-radius: 8px 8px 17px 17px;
  pointer-events: none;
}
.replay-inset-map-svg,
.replay-inset-altitude-svg {
  display: block;
  width: 100%;
  height: 100%;
}
.replay-inset-map-controls {
  position: absolute;
  top: 8px;
  left: 8px;
  display: flex;
  flex-direction: column;
  gap: 5px;
}
.replay-inset-map-zoom {
  width: 21px;
  height: 21px;
  border: 1px solid rgba(255, 255, 255, .78);
  border-radius: 50%;
  background: rgba(15, 23, 42, .50);
  color: rgba(255, 255, 255, .96);
  font: 800 15px/17px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  padding: 0;
  text-align: center;
  pointer-events: auto;
  cursor: pointer;
}
.replay-inset-map-range {
  position: absolute;
  left: 8px;
  bottom: 7px;
  min-width: 34px;
  padding: 2px 6px;
  border-radius: 9px;
  background: rgba(15, 23, 42, .56);
  color: rgba(255, 255, 255, .96);
  font-size: 10px;
  font-weight: 750;
  line-height: 1.2;
  text-align: center;
}
.replay-inset-map svg text {
  fill: rgba(255, 255, 255, .95);
  font-weight: 720;
  paint-order: stroke;
  stroke: rgba(15, 23, 42, .45);
  stroke-width: .8px;
  stroke-linejoin: round;
}
.replay-engine-placeholder {
  width: calc(100% - 16px);
  color: #f8fafc;
  text-transform: none;
  letter-spacing: 0;
}
.engine-panel {
  width: calc(100% - 10px);
  color: #f8fafc;
  text-transform: none;
  letter-spacing: 0;
  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}
.engine-gauge {
  margin: 0 2px 20px;
}
.engine-row-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 5px;
  font-weight: 900;
  font-size: 10.5px;
  line-height: 1;
  margin: 0 1px 8px;
  padding: 2px 4px;
  background: transparent;
  box-sizing: border-box;
  color: rgba(248, 250, 252, .98);
  text-shadow: 0 1px 2px rgba(0, 0, 0, .70);
}
.engine-row-head.is-alert-yellow {
  color: #111;
  background: linear-gradient(165deg, #fff42a 0%, #ffe500 58%, #d8c800 100%);
  text-shadow: none;
}
.engine-row-head.is-alert-red {
  color: #fff;
  background: linear-gradient(165deg, #ff2a2a 0%, #df0000 58%, #8f0000 100%);
  text-shadow: 0 1px 2px rgba(0, 0, 0, .80);
  animation: engineAlertFlash .85s steps(2, start) infinite;
}
@keyframes engineAlertFlash {
  0%, 45% { opacity: 1; }
  46%, 100% { opacity: .28; }
}
.engine-value {
  font-size: 14px;
  font-variant-numeric: tabular-nums;
}
.engine-bar {
  position: relative;
  height: 10px;
  border: 0;
  box-shadow: 0 1px 2px rgba(0, 0, 0, .34);
  background: transparent;
  overflow: visible;
}
.engine-bar:not(.is-probe-pair)::after {
  content: "";
  position: absolute;
  left: 0;
  right: 0;
  top: -4px;
  bottom: -2px;
  border-left: 2px solid #f8fafc;
  border-right: 2px solid #f8fafc;
  border-bottom: 2px solid #f8fafc;
  pointer-events: none;
}
.engine-bar.is-probe-pair::after {
  content: "";
  position: absolute;
  left: 0;
  right: 0;
  top: -8px;
  bottom: -8px;
  border-left: 2px solid #f8fafc;
  border-right: 2px solid #f8fafc;
  pointer-events: none;
}
.engine-bar.is-probe-pair::before {
  content: "";
  position: absolute;
  left: 0;
  right: 0;
  bottom: -2px;
  height: 2px;
  background: #f8fafc;
  pointer-events: none;
}
.engine-bar-fill {
  position: absolute;
  top: 0;
  bottom: 0;
}
.engine-bar-fill.is-white {
  background: #f8fafc;
}
.engine-bar-fill.is-green {
  background: #13f018;
}
.engine-bar-fill.is-yellow {
  background: #ffe600;
}
.engine-bar-fill.is-red {
  background: #ff1212;
}
.engine-bar-fill.is-black { background: #f8fafc; }
.engine-bar-fill.is-green-line {
  top: 4px;
  bottom: 4px;
  background: #23f01f;
}
.engine-bar.is-ammeter .engine-bar-fill.is-green-line {
  top: 0;
  bottom: 0;
  background: #13f018;
}
.engine-pointer {
  position: absolute;
  top: -3px;
  width: 0;
  height: 0;
  border-left: 6px solid transparent;
  border-right: 6px solid transparent;
  border-top: 12px solid #f8fafc;
  filter: drop-shadow(0 1px 1px rgba(0, 0, 0, .7));
  transform: translateX(-50%);
}
.engine-pointer.is-bottom {
  top: auto;
  bottom: -3px;
  border-top: 0;
  border-bottom: 12px solid #f8fafc;
}
.engine-pointer.is-probe-number {
  top: -8px;
  width: 0;
  height: 0;
  border: 0;
  color: #111;
  font-size: 9px;
  font-weight: 900;
  line-height: 10px;
  z-index: 3;
}
.engine-pointer.is-probe-number::before {
  content: "";
  position: absolute;
  left: -6px;
  top: 0;
  width: 0;
  height: 0;
  border-left: 6px solid transparent;
  border-right: 6px solid transparent;
  border-top: 12px solid #f8fafc;
  filter: drop-shadow(0 1px 1px rgba(0, 0, 0, .7));
  pointer-events: none;
}
.engine-pointer-label {
  position: absolute;
  left: -6px;
  top: 0;
  width: 12px;
  text-align: center;
  line-height: 10px;
  text-shadow: none;
  filter: none;
  z-index: 2;
}
.engine-pointer.is-probe-number.is-bottom {
  top: auto;
  bottom: -8px;
}
.engine-pointer.is-probe-number.is-bottom::before {
  top: auto;
  bottom: 0;
  border-top: 0;
  border-bottom: 12px solid #f8fafc;
}
.engine-pointer.is-probe-number.is-bottom .engine-pointer-label {
  top: -9px;
}
.engine-probe {
  position: absolute;
  left: -3px;
  top: -7px;
  width: 16px;
  height: 13px;
  color: #111;
  background: linear-gradient(180deg, #fff, #d8dde2);
  clip-path: polygon(0 0, 100% 0, 70% 100%, 0 100%);
  font-size: 9px;
  font-weight: 900;
  line-height: 13px;
  padding-left: 2px;
  z-index: 2;
}
.engine-arc-gauge {
  position: relative;
  height: 88px;
  margin: 0 2px 6px;
}
.engine-arc-gauge.is-rpm {
  height: 116px;
  width: 100%;
  margin: 0 1px 20px;
}
.engine-arc-svg {
  width: 100%;
  height: 78px;
  overflow: visible;
}
.engine-arc-svg.is-rpm {
  width: 148px;
  height: 110px;
  display: block;
  margin: 0 auto;
}
.engine-arc-value {
  position: absolute;
  right: 2px;
  bottom: 3px;
  text-align: right;
  font-weight: 900;
}
.engine-arc-gauge.is-rpm .engine-arc-value {
  right: 2px;
  top: 69px;
  bottom: auto;
  min-width: 44px;
  color: #f8fafc;
  text-shadow: 0 1px 2px rgba(0, 0, 0, .72);
}
.engine-arc-gauge.is-rpm .engine-arc-value.is-alert-yellow {
  color: #ffe600;
}
.engine-arc-gauge.is-rpm .engine-arc-value.is-alert-red {
  color: #ff1f2a;
}
.engine-arc-value span {
  display: block;
  font-size: 12px;
}
.engine-arc-gauge.is-rpm .engine-arc-value span {
  font-size: 15px;
  line-height: 1;
  letter-spacing: -.02em;
}
.engine-arc-value strong {
  font-size: 18px;
}
.engine-arc-gauge.is-rpm .engine-arc-value strong {
  display: block;
  margin-top: 3px;
  font-size: 22px;
  line-height: .95;
  letter-spacing: -.04em;
  font-variant-numeric: tabular-nums;
}
.engine-amps-cross {
  position: absolute;
  inset: 1px 5px;
  pointer-events: none;
}
.engine-amps-cross::before,
.engine-amps-cross::after {
  content: "";
  position: absolute;
  left: 0;
  right: 0;
  top: 50%;
  height: 2px;
  background: rgba(255, 31, 42, .92);
}
.engine-amps-cross::before { transform: rotate(8deg); }
.engine-amps-cross::after { transform: rotate(-8deg); }
.replay-engine-arc {
  height: 82px;
  border-radius: 82px 82px 0 0;
  border-top: 5px solid #3bef3b;
  border-left: 5px solid #3bef3b;
  border-right: 5px solid #3bef3b;
  margin: 0 8px 8px;
  position: relative;
}
.replay-engine-arc::after {
  content: "";
  position: absolute;
  left: 44%;
  bottom: 4px;
  width: 0;
  height: 0;
  border-left: 6px solid transparent;
  border-right: 6px solid transparent;
  border-bottom: 12px solid #fff;
  transform: rotate(-22deg);
}
.replay-engine-large {
  text-align: center;
  font-size: 20px;
  font-weight: 900;
}
.replay-engine-large span {
  font-size: 14px;
  font-weight: 700;
  margin-right: 6px;
}
.replay-engine-row {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  padding: 5px 8px;
  border-top: 1px solid rgba(226, 232, 240, .22);
  font-size: 13px;
}
.replay-engine-row strong {
  font-size: 17px;
}
.replay-engine-bar {
  height: 7px;
  margin: 2px 8px 6px;
  background: linear-gradient(90deg, #18d918 0 80%, rgba(255, 255, 255, .2) 80% 100%);
  position: relative;
}
.replay-engine-bar::after {
  content: "";
  position: absolute;
  left: 72%;
  top: -8px;
  width: 0;
  height: 0;
  border-left: 5px solid transparent;
  border-right: 5px solid transparent;
  border-top: 13px solid #fff;
}
.replay-engine-muted {
  color: rgba(226, 232, 240, .66);
  font-size: 11px;
  text-align: center;
  margin-top: 8px;
}
.replay-immersive.is-panel-layout .airspeed-tape {
  left: calc(var(--panel-engine-width) + clamp(30px, 4vw, 72px) + 60px);
}
.replay-immersive.is-panel-layout .altimeter-stack {
  right: calc(clamp(60px, 8vw, 122px) + 60px);
}
.replay-immersive.is-panel-layout .replay-dock {
  left: 0;
  right: 0;
  bottom: 0;
  min-height: var(--panel-playback-height);
  padding-top: 6px;
  padding-bottom: 6px;
}
.replay-horizon-line {
  position: absolute;
  left: 50%;
  top: 50%;
  z-index: 18;
  width: 160vw;
  height: 2px;
  pointer-events: none;
  background: rgba(255, 255, 255, .78);
  box-shadow: 0 0 8px rgba(15, 23, 42, .32);
  transform-origin: 50% 50%;
  opacity: .86;
}
.attitude-overlay {
  position: absolute;
  inset: 0;
  z-index: 18;
  width: 100%;
  height: 100%;
  pointer-events: none;
  overflow: visible;
  filter: drop-shadow(0 1px 2px rgba(0, 0, 0, .36));
}
.attitude-overlay text {
  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  font-weight: 900;
  fill: rgba(255, 255, 255, .88);
}
.attitude-overlay .attitude-white {
  stroke: rgba(255, 255, 255, .88);
  fill: none;
  stroke-width: 2;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.attitude-overlay .attitude-yellow {
  fill: #f5e91b;
  stroke: rgba(0, 0, 0, .76);
  stroke-width: 2;
  stroke-linejoin: round;
}
.attitude-overlay .attitude-flight-director {
  fill: #d946ef;
  stroke: rgba(0, 0, 0, .82);
  stroke-width: 2;
  stroke-linejoin: round;
}
.attitude-overlay .attitude-flight-director-cap {
  fill: none;
  stroke: rgba(0, 0, 0, .82);
  stroke-width: 2;
  stroke-linecap: square;
}
.attitude-overlay .attitude-slip {
  fill: rgba(255, 255, 255, .88);
}
.attitude-overlay .attitude-bank-pointer {
  fill: #fff;
}
.attitude-overlay .attitude-fpv {
  fill: none;
  stroke: #10d510;
  stroke-width: 3;
  stroke-linecap: round;
  stroke-linejoin: round;
  filter: drop-shadow(0 1px 1px rgba(0, 0, 0, .46));
}
.airspeed-tape {
  position: absolute;
  left: calc(clamp(112px, 18.5vw, 220px) + 60px);
  top: 72px;
  z-index: 19;
  width: 118px;
  height: min(68vh, 560px);
  min-height: 340px;
  display: flex;
  flex-direction: column;
  color: #fff;
  pointer-events: none;
  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  filter: drop-shadow(0 2px 5px rgba(0, 0, 0, .38));
  transform: scale(.7);
  transform-origin: top left;
}
.airspeed-tape-header,
.airspeed-tape-footer {
  height: 42px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 9px;
  background: rgba(0, 0, 0, .88);
  font-weight: 900;
  letter-spacing: .02em;
  flex: 0 0 42px;
  position: relative;
  z-index: 2;
}
.airspeed-tape-header { border-radius: 14px 14px 0 0; }
.airspeed-tape-footer { border-radius: 0 0 14px 14px; }
.airspeed-tape-title,
.airspeed-tape-footer-label { font-size: 21px; }
.airspeed-tape-tas-value,
.airspeed-tape-gs-value { font-size: 18px; }
.airspeed-tape-gs-value,
.airspeed-tape-gs-unit { color: #ff67ff; }
.airspeed-tape-unit { font-size: 14px; margin-left: 3px; }
.airspeed-tape-body {
  position: relative;
  flex: 1 1 auto;
  min-height: 0;
  overflow: hidden;
  background: rgba(40, 40, 40, .56);
  border-left: 1px solid rgba(255, 255, 255, .24);
  border-right: 1px solid rgba(255, 255, 255, .24);
}
.airspeed-tape-scale,
.airspeed-tape-colors,
.airspeed-tape-bugs {
  position: absolute;
  inset: 0;
}
.airspeed-tape-tick {
  position: absolute;
  right: 22px;
  height: 2px;
  background: rgba(255, 255, 255, .92);
  transform: translateY(-1px);
}
.airspeed-tape-tick.is-major { width: 24px; }
.airspeed-tape-tick.is-minor { width: 11px; opacity: .88; }
.airspeed-tape-number {
  position: absolute;
  right: 52px;
  transform: translateY(-50%);
  font-size: 24px;
  font-weight: 800;
  line-height: 1;
}
.airspeed-tape-color-band {
  position: absolute;
  right: 10px;
  width: 8px;
  min-height: 2px;
}
.airspeed-tape-color-band.is-white { background: #fff; }
.airspeed-tape-color-band.is-green { background: #00a000; }
.airspeed-tape-color-band.is-yellow { background: #e0cf00; }
.airspeed-tape-redline {
  position: absolute;
  right: 5px;
  width: 18px;
  height: 4px;
  background: #f11;
  transform: translateY(-2px);
}
.airspeed-tape-pointer {
  position: absolute;
  left: 0;
  top: 50%;
  width: 78px;
  height: 52px;
  transform: translateY(-50%);
  display: grid;
  place-items: center;
  background: #050505;
  border-radius: 8px 6px 6px 8px;
  font-size: 28px;
  font-weight: 900;
}
.airspeed-tape-pointer::after {
  content: "";
  position: absolute;
  right: -17px;
  top: 50%;
  transform: translateY(-50%);
  border-top: 12px solid transparent;
  border-bottom: 12px solid transparent;
  border-left: 17px solid #050505;
}
.airspeed-tape-bug {
  position: absolute;
  right: -1px;
  transform: translateY(-50%);
  min-width: 23px;
  height: 22px;
  display: grid;
  place-items: center;
  color: #8ff;
  background: #050505;
  border-radius: 4px;
  font-size: 16px;
  font-weight: 900;
}
.airspeed-tape-bug::before {
  content: "";
  position: absolute;
  left: -10px;
  border-top: 8px solid transparent;
  border-bottom: 8px solid transparent;
  border-right: 10px solid #050505;
}
.altimeter-stack {
  position: absolute;
  right: calc(clamp(108px, 17vw, 210px) + 60px);
  top: 72px;
  z-index: 19;
  display: flex;
  align-items: center;
  gap: 8px;
  color: #fff;
  pointer-events: none;
  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  filter: drop-shadow(0 2px 5px rgba(0, 0, 0, .38));
  transform: scale(.7);
  transform-origin: top right;
}
.altimeter-tape {
  width: 128px;
  height: min(68vh, 560px);
  min-height: 340px;
  display: flex;
  flex-direction: column;
}
.altimeter-header,
.altimeter-footer {
  height: 42px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 9px;
  background: rgba(0, 0, 0, .88);
  font-weight: 900;
  letter-spacing: .02em;
  flex: 0 0 42px;
  position: relative;
  z-index: 2;
}
.altimeter-header { border-radius: 10px 10px 0 0; color: #5fffff; font-size: 24px; }
.altimeter-footer { border-radius: 0 0 10px 10px; color: #5fffff; font-size: 21px; }
.altimeter-footer { pointer-events: auto; cursor: pointer; }
.altimeter-body {
  position: relative;
  flex: 1 1 auto;
  min-height: 0;
  overflow: hidden;
  background: rgba(40, 40, 40, .56);
  border-left: 1px solid rgba(255, 255, 255, .24);
  border-right: 1px solid rgba(255, 255, 255, .24);
}
.altimeter-scale,
.altimeter-bugs {
  position: absolute;
  inset: 0;
}
.altimeter-tick {
  position: absolute;
  left: 6px;
  height: 2px;
  background: rgba(255, 255, 255, .92);
  transform: translateY(-1px);
}
.altimeter-tick.is-major { width: 27px; height: 3px; }
.altimeter-tick.is-minor { width: 13px; opacity: .9; }
.altimeter-number {
  position: absolute;
  right: 18px;
  transform: translateY(-50%);
  font-size: 21px;
  font-weight: 800;
  line-height: 1;
}
.altimeter-pointer {
  position: absolute;
  right: 0;
  top: 50%;
  width: 90px;
  height: 56px;
  transform: translateY(-50%);
  display: grid;
  place-items: center;
  background: #050505;
  border-radius: 7px 8px 8px 7px;
  font-size: 28px;
  font-weight: 900;
}
.altimeter-pointer::before {
  content: "";
  position: absolute;
  left: -15px;
  top: 50%;
  transform: translateY(-50%);
  border-top: 12px solid transparent;
  border-bottom: 12px solid transparent;
  border-right: 15px solid #050505;
}
.altimeter-bug {
  position: absolute;
  left: 0;
  width: 29px;
  height: 29px;
  transform: translateY(-50%);
  overflow: visible;
}
.altimeter-da {
  position: absolute;
  left: 0;
  bottom: 0;
  right: 0;
  height: 34px;
  display: none;
  align-items: center;
  gap: 8px;
  padding: 0 8px;
  background: rgba(0, 0, 0, .88);
  font-size: 14px;
  font-weight: 900;
}
.vsi-stack {
  position: relative;
  width: 72px;
  height: calc(min(68vh, 560px) - 84px);
  min-height: 256px;
  border-radius: 7px;
  background: rgba(40, 40, 40, .50);
  border: 1px solid rgba(255, 255, 255, .20);
  overflow: visible;
}
.vsi-scale,
.vsi-pointer-layer {
  position: absolute;
  inset: 0;
}
.vsi-tick {
  position: absolute;
  left: 0;
  width: 14px;
  height: 2px;
  background: rgba(255, 255, 255, .92);
  transform: translateY(-1px);
}
.vsi-tick.is-major { width: 22px; height: 3px; }
.vsi-number {
  position: absolute;
  right: 8px;
  transform: translateY(-50%);
  font-size: 22px;
  font-weight: 800;
}
.vsi-pointer {
  position: absolute;
  right: 0;
  top: 50%;
  width: 70px;
  height: 34px;
  transform: translateY(-50%);
  display: grid;
  place-items: center;
  background: #111;
  border-radius: 7px;
  font-size: 18px;
  font-weight: 900;
}
.vsi-pointer::before {
  content: "";
  position: absolute;
  left: -14px;
  top: 50%;
  transform: translateY(-50%);
  border-top: 10px solid transparent;
  border-bottom: 10px solid transparent;
  border-right: 14px solid #111;
}
.aoa-indicator {
  position: absolute;
  z-index: 20;
  width: 72px;
  height: 144px;
  box-sizing: border-box;
  border-radius: 7px;
  background: rgba(40, 40, 40, .56);
  border: 1px solid rgba(255, 255, 255, .20);
  overflow: hidden;
  pointer-events: none;
  filter: drop-shadow(0 2px 5px rgba(0, 0, 0, .38));
}
.aoa-indicator[hidden] { display: none !important; }
.aoa-indicator svg {
  display: block;
  width: 100%;
  height: 100%;
}
.aoa-symbol {
  stroke-linecap: butt;
  stroke-linejoin: miter;
}
.aoa-symbol.is-green { stroke: #73ff45; }
.aoa-symbol.is-yellow { stroke: #ffff00; }
.aoa-symbol.is-red { stroke: #ff2317; }
.aoa-center {
  fill: none;
  stroke: #73ff45;
  stroke-width: 7.5;
}
.temperature-box {
  position: absolute;
  left: 0;
  top: calc(100% + 8px);
  min-width: 118px;
  box-sizing: border-box;
  padding: 6px 8px;
  border-radius: 7px;
  background: rgba(40, 40, 40, .50);
  border: 1px solid rgba(255, 255, 255, .20);
  color: #fff;
  font-size: 15px;
  font-weight: 700;
  line-height: 1.25;
  text-align: left;
}
.replay-dock {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 20;
  display: grid;
  grid-template-columns: auto auto auto auto auto minmax(0, 1fr) max-content;
  gap: 10px;
  align-items: center;
  padding: 10px 14px;
  background: rgba(15, 23, 42, 0.72);
  backdrop-filter: blur(6px);
}
.replay-dock a { color: #e2e8f0; font-size: 13px; text-decoration: none; white-space: nowrap; }
.replay-dock a:hover { color: #fff; }
.replay-button { border: 0; border-radius: 8px; background: #1d4ed8; color: #fff; font-weight: 700; padding: 8px 14px; cursor: pointer; }
.replay-range { width: 100%; min-width: 0; accent-color: #60a5fa; margin: 0; }
.replay-time { min-width: 5.5ch; color: #e2e8f0; font-size: 13px; font-variant-numeric: tabular-nums; white-space: nowrap; }
.replay-load { position: absolute; inset: 0; z-index: 15; display: grid; place-items: center; color: #e2e8f0; background: #0f172a; font-size: 14px; }
.replay-load-card {
  width: min(460px, 78vw);
  padding: 22px 24px;
  border-radius: 16px;
  background: rgba(15, 23, 42, .72);
  box-shadow: 0 16px 40px rgba(0, 0, 0, .32);
  text-align: center;
}
.replay-load-title {
  font-size: 24px;
  font-weight: 700;
  margin-bottom: 14px;
}
.replay-load-bar {
  height: 10px;
  overflow: hidden;
  border-radius: 999px;
  background: rgba(148, 163, 184, .25);
}
.replay-load-fill {
  width: 0%;
  height: 100%;
  border-radius: inherit;
  background: linear-gradient(90deg, #22d3ee, #60a5fa);
  transition: width .18s ease;
}
.replay-load-meta {
  margin-top: 10px;
  color: #cbd5e1;
  font-size: 13px;
  font-variant-numeric: tabular-nums;
}
.cesium-unavailable { position: absolute; inset: 0; display: grid; place-items: center; color: #fff; background: #0f172a; text-align: center; padding: 28px; z-index: 10; }
.replay-select { border: 1px solid rgba(226, 232, 240, .45); border-radius: 8px; background: rgba(15, 23, 42, .9); color: #e2e8f0; padding: 7px 9px; }
.replay-menu {
  position: absolute;
  top: 12px;
  left: 12px;
  z-index: 21;
  display: flex;
  gap: 8px;
}
.replay-menu-button {
  border: 1px solid rgba(226, 232, 240, .32);
  border-radius: 999px;
  background: rgba(15, 23, 42, .52);
  color: #dbeafe;
  padding: 8px 11px;
  font: 700 12px/1 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  cursor: pointer;
  backdrop-filter: blur(7px);
}
.replay-menu-button:hover,
.replay-menu-button.is-active { background: rgba(30, 64, 175, .72); color: #fff; }
.replay-modal {
  position: absolute;
  z-index: 22;
  color: #e2e8f0;
  background: rgba(15, 23, 42, .50);
  border: 1px solid rgba(226, 232, 240, .28);
  border-radius: 14px;
  padding: 12px;
  backdrop-filter: blur(8px);
  box-shadow: 0 18px 50px rgba(0, 0, 0, .26);
}
.replay-debug {
  top: 54px;
  left: 12px;
  width: min(760px, calc(100vw - 24px));
  max-height: min(72vh, 720px);
  overflow: auto;
  font: 10px/1.25 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
}
.replay-debug-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}
.replay-debug-section {
  border-top: 1px solid rgba(148, 163, 184, .24);
  padding-top: 7px;
}
.replay-debug-section-title {
  margin-bottom: 4px;
  color: #bfdbfe;
  font: 800 10px/1 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  letter-spacing: .08em;
  text-transform: uppercase;
}
.replay-debug-line {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  white-space: nowrap;
}
.replay-debug-line span:first-child { color: #cbd5e1; }
.replay-debug-line span:last-child { color: #e2e8f0; text-align: right; overflow: hidden; text-overflow: ellipsis; }
.replay-debug-line.is-camera-used span:last-child { color: #86efac; font-weight: 800; }
.replay-debug-line.is-g3x-data span:last-child { color: #93c5fd; }
@media (max-width: 760px) {
  .replay-debug-grid { grid-template-columns: 1fr; }
}
.replay-debug-quality {
  margin-top: 6px;
  padding-top: 6px;
  border-top: 1px solid rgba(148, 163, 184, .28);
}
.replay-debug-quality-row {
  display: grid;
  grid-template-columns: 58px 1fr;
  gap: 6px;
}
.replay-quality-good { color: #86efac; }
.replay-quality-degraded { color: #fde68a; }
.replay-quality-low { color: #fca5a5; }
.replay-quality-unknown { color: #cbd5e1; }
.replay-camera-panel {
  position: absolute;
  left: 12px;
  bottom: 64px;
  z-index: 21;
  width: min(420px, calc(100vw - 24px));
  color: #e2e8f0;
  background: rgba(15, 23, 42, .78);
  border: 1px solid rgba(148, 163, 184, .35);
  border-radius: 10px;
  padding: 10px;
  font: 12px/1.35 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}
.replay-camera-panel-title {
  font-weight: 800;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: #bfdbfe;
  margin-bottom: 8px;
}
.replay-camera-control {
  display: grid;
  grid-template-columns: 118px 1fr 72px 58px;
  gap: 8px;
  align-items: center;
  margin-top: 6px;
}
.replay-camera-control label { color: #cbd5e1; }
.replay-camera-control input { width: 100%; accent-color: #60a5fa; }
.replay-camera-exact {
  box-sizing: border-box;
  border: 1px solid rgba(226, 232, 240, .25);
  border-radius: 8px;
  background: rgba(15, 23, 42, .86);
  color: #e2e8f0;
  padding: 5px 6px;
  font: inherit;
  font-variant-numeric: tabular-nums;
}
.replay-camera-control output {
  color: #dbeafe;
  font-variant-numeric: tabular-nums;
  text-align: right;
}
.replay-terrain-warning {
  position: absolute;
  top: 12px;
  right: 12px;
  z-index: 21;
  max-width: 340px;
  color: #92400e;
  background: rgba(254, 243, 199, .94);
  border: 1px solid #f59e0b;
  border-radius: 10px;
  padding: 10px;
  font-size: 12px;
}
.replay-calibration-panel {
  top: 54px;
  left: 12px;
  width: min(520px, calc(100vw - 24px));
  max-height: min(78vh, 760px);
  overflow: auto;
  font: 12px/1.3 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}
.replay-calibration-title {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  align-items: center;
  margin-bottom: 8px;
  font-weight: 800;
  color: #bfdbfe;
}
.replay-calibration-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6px;
}
.replay-calibration-section {
  margin-top: 9px;
  padding-top: 8px;
  border-top: 1px solid rgba(226, 232, 240, .18);
}
.replay-calibration-section-title {
  margin-bottom: 6px;
  color: #c7d2fe;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: .05em;
  text-transform: uppercase;
}
.replay-calibration-button {
  border: 1px solid rgba(226, 232, 240, .25);
  border-radius: 8px;
  background: rgba(30, 64, 175, .66);
  color: #fff;
  font-weight: 800;
  padding: 7px 8px;
  cursor: pointer;
}
.replay-calibration-button:hover { background: rgba(37, 99, 235, .82); }
.replay-calibration-button.is-muted { background: rgba(51, 65, 85, .68); }
.replay-calibration-row {
  display: flex;
  gap: 6px;
  align-items: center;
  justify-content: space-between;
  margin-top: 8px;
}
.replay-calibration-select {
  border: 1px solid rgba(226, 232, 240, .25);
  border-radius: 8px;
  background: rgba(15, 23, 42, .86);
  color: #e2e8f0;
  padding: 5px 7px;
}
.replay-calibration-range {
  width: 160px;
  max-width: 45vw;
  accent-color: #60a5fa;
}
.replay-instrument-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 6px;
}
.replay-toggle {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  border: 1px solid rgba(226, 232, 240, .18);
  border-radius: 8px;
  padding: 6px 8px;
  background: rgba(15, 23, 42, .38);
  cursor: pointer;
}
.replay-toggle input { accent-color: #60a5fa; }
.replay-preset-note {
  margin-top: 6px;
  color: #cbd5e1;
  font-size: 11px;
}
.replay-calibration-values {
  margin-top: 8px;
  color: #dbeafe;
  font: 11px/1.35 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
  white-space: pre-wrap;
}
</style>

<?php if ($error !== ''): ?>
  <div class="replay-error"><?= h($error) ?></div>
<?php else: ?>
  <div class="replay-avionics-header" aria-label="Avionics data header">
    <div class="replay-avionics-brand">Avionics data</div>
    <div id="radioStackGroup" class="replay-avionics-group" aria-label="Radio stack"></div>
    <div id="autopilotFmaGroup" class="replay-avionics-group" aria-label="Autopilot flight mode annunciator"></div>
    <div id="navaidStackGroup" class="replay-avionics-group" aria-label="Navaid stack"></div>
    <div id="radioStackEndGroup" class="replay-avionics-group" aria-label="Radio stack continued"></div>
  </div>
  <div
    class="replay-immersive"
    data-replay-id="<?= h((string)$id) ?>"
    data-standalone-replay="<?= h($standaloneReplay) ?>"
    data-replay-mode="cesium-only"
    data-cesium-token="<?= h($cesiumIonToken) ?>"
  >
    <div id="loadStatus" class="replay-load">
      <div class="replay-load-card">
        <div class="replay-load-title">Your Flight Replay is loading...</div>
        <div class="replay-load-bar"><div id="replayLoadFill" class="replay-load-fill"></div></div>
        <div id="replayLoadMeta" class="replay-load-meta">0% - requesting replay data</div>
      </div>
    </div>
    <div class="replay-engine-pane" aria-hidden="true">
      <div id="enginePanel" class="engine-panel" aria-label="Engine instruments"></div>
    </div>
    <div class="replay-bottom-instrument-pane" aria-hidden="true"><span class="replay-pane-label">Compass / HSI reserved</span></div>
    <div id="cesiumReplay" class="cesium-cockpit"></div>
    <div id="horizonLine" class="replay-horizon-line" aria-hidden="true" hidden></div>
    <svg id="attitudeOverlay" class="attitude-overlay" aria-label="Attitude indicator" hidden></svg>
    <svg id="hsiOverlay" class="hsi-overlay" aria-label="Horizontal situation indicator" viewBox="0 0 390 330" hidden></svg>
    <div id="insetMap" class="replay-inset-map" aria-label="Interactive inset map" hidden>
      <div id="insetMapTop" class="replay-inset-map-top" aria-label="Horizontal flight track map">
        <svg id="insetMapSvg" class="replay-inset-map-svg" viewBox="0 0 240 240" role="img" aria-label="North-up flight track"></svg>
        <div class="replay-inset-map-controls" aria-label="Inset map zoom controls">
          <button id="insetMapZoomIn" class="replay-inset-map-zoom" type="button" aria-label="Zoom inset map in">+</button>
          <button id="insetMapZoomOut" class="replay-inset-map-zoom" type="button" aria-label="Zoom inset map out">-</button>
        </div>
        <div id="insetMapRange" class="replay-inset-map-range">-- NM</div>
      </div>
      <div id="insetMapProfile" class="replay-inset-map-profile" aria-label="Vertical altitude profile">
        <svg id="insetAltitudeSvg" class="replay-inset-altitude-svg" viewBox="0 0 240 58" role="img" aria-label="Baro altitude and terrain profile"></svg>
      </div>
    </div>
    <div id="airspeedTape" class="airspeed-tape" aria-label="Airspeed indicator" hidden>
      <div class="airspeed-tape-header">
        <span class="airspeed-tape-title">TAS</span>
        <span><span id="airspeedTasValue" class="airspeed-tape-tas-value">--</span><span class="airspeed-tape-unit">KT</span></span>
      </div>
      <div id="airspeedTapeBody" class="airspeed-tape-body">
        <div id="airspeedTapeColors" class="airspeed-tape-colors"></div>
        <div id="airspeedTapeScale" class="airspeed-tape-scale"></div>
        <div id="airspeedTapeBugs" class="airspeed-tape-bugs"></div>
        <div id="airspeedTapePointer" class="airspeed-tape-pointer">--</div>
      </div>
      <div class="airspeed-tape-footer">
        <span class="airspeed-tape-footer-label">GS</span>
        <span><span id="airspeedGsValue" class="airspeed-tape-gs-value">--</span><span class="airspeed-tape-unit airspeed-tape-gs-unit">KT</span></span>
      </div>
    </div>
    <div id="aoaIndicator" class="aoa-indicator" aria-label="Angle of attack indicator" hidden>
      <svg id="aoaIndicatorSvg" viewBox="0 10 72 124" role="img" aria-label="Angle of attack"></svg>
    </div>
    <div id="altimeterStack" class="altimeter-stack" aria-label="Altimeter and vertical speed indicator" hidden>
      <div class="altimeter-tape">
        <div id="altimeterBugValue" class="altimeter-header">----</div>
        <div id="altimeterBody" class="altimeter-body">
          <div id="altimeterScale" class="altimeter-scale"></div>
          <div id="altimeterBugs" class="altimeter-bugs"></div>
          <div id="altimeterPointer" class="altimeter-pointer">----</div>
          <div id="altimeterDa" class="altimeter-da">DA <span id="altimeterDaValue">----</span>FT</div>
        </div>
        <div id="altimeterSettingValue" class="altimeter-footer">---- HPA</div>
      </div>
      <div class="vsi-stack">
        <div id="vsiScale" class="vsi-scale"></div>
        <div class="vsi-pointer-layer">
          <div id="vsiPointer" class="vsi-pointer">0</div>
        </div>
        <div id="temperatureBox" class="temperature-box">OAT --°C<br>ISA --°C</div>
      </div>
    </div>
    <div class="replay-menu" aria-label="Replay overlay menu">
      <button class="replay-menu-button" type="button" id="calibrationToggle">Camera</button>
      <button class="replay-menu-button" type="button" id="debugToggle">Debug</button>
    </div>
    <div id="replayDebug" class="replay-modal replay-debug" hidden>Replay debug initializing...</div>
    <div id="cameraPanel" class="replay-camera-panel" aria-label="Replay camera controls" hidden>
      <div class="replay-camera-panel-title">Chase / north-up tuning</div>
      <div class="replay-camera-control">
        <label for="cameraRange">Range</label>
        <input id="cameraRange" type="range" min="60" max="260" step="1">
        <input id="cameraRangeExact" class="replay-camera-exact" type="number" min="60" max="260" step="1" aria-label="Exact camera range">
        <output id="cameraRangeValue" for="cameraRange"></output>
      </div>
      <div class="replay-camera-control">
        <label for="cameraHeight">Height offset</label>
        <input id="cameraHeight" type="range" min="8" max="90" step="0.5">
        <input id="cameraHeightExact" class="replay-camera-exact" type="number" min="8" max="90" step="0.5" aria-label="Exact camera height offset">
        <output id="cameraHeightValue" for="cameraHeight"></output>
      </div>
      <div class="replay-camera-control">
        <label for="cameraPitch">Camera pitch</label>
        <input id="cameraPitch" type="range" min="-30" max="-4" step="0.1">
        <input id="cameraPitchExact" class="replay-camera-exact" type="number" min="-30" max="-4" step="0.1" aria-label="Exact camera pitch">
        <output id="cameraPitchValue" for="cameraPitch"></output>
      </div>
      <div class="replay-camera-control">
        <label for="cameraSmoothing">Smoothing</label>
        <input id="cameraSmoothing" type="range" min="1" max="12" step="0.1">
        <input id="cameraSmoothingExact" class="replay-camera-exact" type="number" min="1" max="12" step="0.1" aria-label="Exact camera smoothing">
        <output id="cameraSmoothingValue" for="cameraSmoothing"></output>
      </div>
    </div>
    <div id="terrainWarning" class="replay-terrain-warning" hidden></div>
    <div id="calibrationPanel" class="replay-modal replay-calibration-panel" aria-label="Camera position calibration" hidden>
      <div class="replay-calibration-title">
        <span>Camera Calibration</span>
        <button class="replay-calibration-button is-muted" type="button" id="calibrationReset">Reset</button>
      </div>
      <div class="replay-calibration-grid">
        <button class="replay-calibration-button" type="button" data-cal-axis="forward" data-cal-sign="1">Forward</button>
        <button class="replay-calibration-button" type="button" data-cal-axis="up" data-cal-sign="1">Up</button>
        <button class="replay-calibration-button" type="button" data-cal-axis="right" data-cal-sign="-1">Left</button>
        <button class="replay-calibration-button" type="button" data-cal-axis="forward" data-cal-sign="-1">Back</button>
        <button class="replay-calibration-button" type="button" data-cal-axis="up" data-cal-sign="-1">Down</button>
        <button class="replay-calibration-button" type="button" data-cal-axis="right" data-cal-sign="1">Right</button>
      </div>
      <div class="replay-calibration-section">
        <div class="replay-calibration-section-title">Direction</div>
        <div class="replay-calibration-grid">
          <button class="replay-calibration-button" type="button" data-cal-axis="yaw" data-cal-sign="-1">Yaw ←</button>
          <button class="replay-calibration-button" type="button" data-cal-axis="pitch" data-cal-sign="1">Pitch ↑</button>
          <button class="replay-calibration-button" type="button" data-cal-axis="yaw" data-cal-sign="1">Yaw →</button>
          <button class="replay-calibration-button is-muted" type="button" data-cal-axis="pitch" data-cal-sign="-1">Pitch ↓</button>
          <button class="replay-calibration-button is-muted" type="button" id="calibrationDirectionReset">Reset Dir</button>
          <button class="replay-calibration-button is-muted" type="button" data-cal-axis="roll" data-cal-sign="-1">Roll −</button>
          <button class="replay-calibration-button is-muted" type="button" data-cal-axis="roll" data-cal-sign="1">Roll +</button>
        </div>
      </div>
      <div class="replay-calibration-section">
        <div class="replay-calibration-section-title">Garmin SVT FOV</div>
        <div class="replay-calibration-grid">
          <button class="replay-calibration-button" type="button" id="calibrationFovDecrease">FOV −</button>
          <button class="replay-calibration-button is-muted" type="button" id="calibrationFovReset">Reset FOV</button>
          <button class="replay-calibration-button" type="button" id="calibrationFovIncrease">FOV +</button>
        </div>
        <div class="replay-calibration-row">
          <label for="calibrationFovInput">Horizontal FOV</label>
          <input id="calibrationFovInput" class="replay-calibration-select" type="number" min="35" max="100" step="1" value="80">
        </div>
      </div>
      <div class="replay-calibration-section">
        <div class="replay-calibration-section-title">Smoothness</div>
        <div class="replay-calibration-row">
          <label for="replayLayoutMode">Replay layout</label>
          <select id="replayLayoutMode" class="replay-calibration-select">
            <option value="legacy">Legacy full-window</option>
            <option value="panel">Panel layout: engine + compass space</option>
          </select>
        </div>
        <div class="replay-calibration-row">
          <label for="calibrationSmoothness">Visual position smoothing</label>
          <input id="calibrationSmoothness" class="replay-calibration-range" type="range" min="0" max="20" step="0.5" value="10">
          <output id="calibrationSmoothnessValue" for="calibrationSmoothness">10.0</output>
        </div>
        <div class="replay-calibration-row">
          <label for="horizonBarOffset">Horizon bar vertical offset</label>
          <input id="horizonBarOffset" class="replay-calibration-range" type="range" min="-240" max="240" step="2" value="0">
          <output id="horizonBarOffsetValue" for="horizonBarOffset">0 px</output>
        </div>
        <div class="replay-calibration-row">
          <label for="attitudeReferenceOffset">Attitude reference vertical offset</label>
          <input id="attitudeReferenceOffset" class="replay-calibration-range" type="range" min="-240" max="240" step="2" value="0">
          <output id="attitudeReferenceOffsetValue" for="attitudeReferenceOffset">0 px</output>
        </div>
        <div class="replay-calibration-row">
          <label for="yellowPitchReferenceOffset">Yellow pitch reference vertical offset</label>
          <input id="yellowPitchReferenceOffset" class="replay-calibration-range" type="range" min="-240" max="240" step="2" value="0">
          <output id="yellowPitchReferenceOffsetValue" for="yellowPitchReferenceOffset">0 px</output>
        </div>
        <div class="replay-calibration-row">
          <label for="pitchLadderScale">Pitch ladder spacing</label>
          <input id="pitchLadderScale" class="replay-calibration-range" type="range" min="0.6" max="1.6" step="0.01" value="1">
          <output id="pitchLadderScaleValue" for="pitchLadderScale">1.00x</output>
        </div>
      </div>
      <div class="replay-calibration-section">
        <div class="replay-calibration-section-title">Instruments</div>
        <div class="replay-instrument-grid">
          <label class="replay-toggle"><span>Airspeed Indicator</span><input type="checkbox" data-instrument-toggle="airspeed_indicator"></label>
          <label class="replay-toggle"><span>Altimeter</span><input type="checkbox" data-instrument-toggle="altimeter"></label>
          <label class="replay-toggle"><span>Horizontal Situation Indicator</span><input type="checkbox" data-instrument-toggle="hsi"></label>
          <label class="replay-toggle"><span>Angle of Attack Indicator</span><input type="checkbox" data-instrument-toggle="aoa_indicator"></label>
          <label class="replay-toggle"><span>Inset Map</span><input type="checkbox" data-instrument-toggle="inset_map"></label>
          <label class="replay-toggle"><span>Traffic</span><input type="checkbox" data-instrument-toggle="traffic"></label>
          <label class="replay-toggle"><span>Horizon Horizontal Bar</span><input type="checkbox" data-instrument-toggle="horizon_bar"></label>
          <label class="replay-toggle"><span>Attitude Indicator</span><input type="checkbox" data-instrument-toggle="attitude_indicator"></label>
          <label class="replay-toggle"><span>Flight Director Bars</span><input type="checkbox" data-instrument-toggle="flight_director_bars"></label>
          <label class="replay-toggle"><span>Flight Path Vector</span><input type="checkbox" data-instrument-toggle="flight_path_vector"></label>
          <label class="replay-toggle"><span>Radio Stack</span><input type="checkbox" data-instrument-toggle="radio_stack"></label>
          <label class="replay-toggle"><span>Navaid Stack</span><input type="checkbox" data-instrument-toggle="navaid_stack"></label>
          <label class="replay-toggle"><span>Autopilot FMA</span><input type="checkbox" data-instrument-toggle="autopilot_fma"></label>
          <label class="replay-toggle"><span>Engine Instrument Stack</span><input type="checkbox" data-instrument-toggle="engine_instrument_stack"></label>
          <label class="replay-toggle"><span>Wind Indicator</span><input type="checkbox" data-instrument-toggle="wind_indicator"></label>
        </div>
      </div>
      <div class="replay-calibration-section">
        <div class="replay-calibration-section-title">Camera Presets</div>
        <div class="replay-calibration-row">
          <label for="cameraPresetSelect">Preset</label>
          <select id="cameraPresetSelect" class="replay-calibration-select" disabled>
            <option>Garmin SVT tuned cockpit</option>
          </select>
        </div>
        <div class="replay-preset-note">Preset storage and admin lock hooks are reserved here for the next admin workflow.</div>
      </div>
      <div class="replay-calibration-row">
        <label for="calibrationStep">Step</label>
        <select id="calibrationStep" class="replay-calibration-select">
          <option value="0.5">0.5 m</option>
          <option value="1" selected>1 m</option>
          <option value="5">5 m</option>
        </select>
      </div>
      <div id="calibrationValues" class="replay-calibration-values">F +0.0m | R +0.0m | U +0.0m</div>
    </div>
    <audio id="audio" preload="metadata"<?= $id !== '' ? ' src="/admin/cockpit_recorder_audio.php?id=' . h((string)$id) . '"' : '' ?>></audio>
    <div class="replay-dock">
      <a href="/admin/cockpit_recorder.php">← Back</a>
      <button class="replay-button" type="button" id="rewindButton">−10s</button>
      <button class="replay-button" type="button" id="playButton">Play</button>
      <button class="replay-button" type="button" id="forwardButton">+10s</button>
      <select class="replay-select" id="cameraMode" aria-label="Camera mode">
        <option value="synthetic_vision" selected>Garmin SVT</option>
        <option value="chase">Chase</option>
        <option value="north_up">North up</option>
        <option value="free">Orbit / free</option>
      </select>
      <input class="replay-range" id="timeline" type="range" min="0" max="1" step="0.1" value="0">
      <span id="timeLabel" class="replay-time">0:00</span>
    </div>
  </div>
<?php endif; ?>

<?php if ($error === ''): ?>
<script>window.CESIUM_BASE_URL = 'https://cdn.jsdelivr.net/npm/cesium@1.119.0/Build/Cesium/';</script>
<script src="https://cdn.jsdelivr.net/npm/cesium@1.119.0/Build/Cesium/Cesium.js"></script>
<script>
(function() {
  const AIRSPEED_PROFILE = <?= json_encode($airspeedProfile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const ENGINE_PROFILE = <?= json_encode($engineProfile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const root = document.querySelector('[data-replay-id]');
  const id = root ? root.getAttribute('data-replay-id') : '';
  const standaloneReplay = root ? (root.getAttribute('data-standalone-replay') || '') : '';
  const cesiumToken = root ? (root.getAttribute('data-cesium-token') || '').trim().replace(/^['"]+|['"]+$/g, '') : '';
  const loadStatus = document.getElementById('loadStatus');
  const replayLoadFill = document.getElementById('replayLoadFill');
  const replayLoadMeta = document.getElementById('replayLoadMeta');
  const timeline = document.getElementById('timeline');
  const timeLabel = document.getElementById('timeLabel');
  const audio = document.getElementById('audio');
  const playButton = document.getElementById('playButton');
  const rewindButton = document.getElementById('rewindButton');
  const forwardButton = document.getElementById('forwardButton');
  const cameraModeSelect = document.getElementById('cameraMode');
  const debugOverlay = document.getElementById('replayDebug');
  const radioStackGroup = document.getElementById('radioStackGroup');
  const radioStackEndGroup = document.getElementById('radioStackEndGroup');
  const navaidStackGroup = document.getElementById('navaidStackGroup');
  const autopilotFmaGroup = document.getElementById('autopilotFmaGroup');
  const horizonLine = document.getElementById('horizonLine');
  const attitudeOverlay = document.getElementById('attitudeOverlay');
  const hsiOverlay = document.getElementById('hsiOverlay');
  const insetMap = document.getElementById('insetMap');
  const insetMapTop = document.getElementById('insetMapTop');
  const insetMapSvg = document.getElementById('insetMapSvg');
  const insetAltitudeSvg = document.getElementById('insetAltitudeSvg');
  const insetMapRange = document.getElementById('insetMapRange');
  const insetMapZoomIn = document.getElementById('insetMapZoomIn');
  const insetMapZoomOut = document.getElementById('insetMapZoomOut');
  const enginePanel = document.getElementById('enginePanel');
  const airspeedTape = document.getElementById('airspeedTape');
  const airspeedTapeBody = document.getElementById('airspeedTapeBody');
  const airspeedTapeScale = document.getElementById('airspeedTapeScale');
  const airspeedTapeColors = document.getElementById('airspeedTapeColors');
  const airspeedTapeBugs = document.getElementById('airspeedTapeBugs');
  const airspeedTapePointer = document.getElementById('airspeedTapePointer');
  const airspeedTasValue = document.getElementById('airspeedTasValue');
  const airspeedGsValue = document.getElementById('airspeedGsValue');
  const aoaIndicator = document.getElementById('aoaIndicator');
  const aoaIndicatorSvg = document.getElementById('aoaIndicatorSvg');
  const altimeterStack = document.getElementById('altimeterStack');
  const altimeterBody = document.getElementById('altimeterBody');
  const altimeterScale = document.getElementById('altimeterScale');
  const altimeterBugs = document.getElementById('altimeterBugs');
  const altimeterPointer = document.getElementById('altimeterPointer');
  const altimeterBugValue = document.getElementById('altimeterBugValue');
  const altimeterSettingValue = document.getElementById('altimeterSettingValue');
  const altimeterDa = document.getElementById('altimeterDa');
  const altimeterDaValue = document.getElementById('altimeterDaValue');
  const vsiScale = document.getElementById('vsiScale');
  const vsiPointer = document.getElementById('vsiPointer');
  const temperatureBox = document.getElementById('temperatureBox');
  const calibrationToggle = document.getElementById('calibrationToggle');
  const debugToggle = document.getElementById('debugToggle');
  const cameraPanel = document.getElementById('cameraPanel');
  const cameraRangeInput = document.getElementById('cameraRange');
  const cameraHeightInput = document.getElementById('cameraHeight');
  const cameraPitchInput = document.getElementById('cameraPitch');
  const cameraSmoothingInput = document.getElementById('cameraSmoothing');
  const cameraRangeExact = document.getElementById('cameraRangeExact');
  const cameraHeightExact = document.getElementById('cameraHeightExact');
  const cameraPitchExact = document.getElementById('cameraPitchExact');
  const cameraSmoothingExact = document.getElementById('cameraSmoothingExact');
  const cameraRangeValue = document.getElementById('cameraRangeValue');
  const cameraHeightValue = document.getElementById('cameraHeightValue');
  const cameraPitchValue = document.getElementById('cameraPitchValue');
  const cameraSmoothingValue = document.getElementById('cameraSmoothingValue');
  const terrainWarning = document.getElementById('terrainWarning');
  const calibrationPanel = document.getElementById('calibrationPanel');
  const calibrationStepSelect = document.getElementById('calibrationStep');
  const calibrationReset = document.getElementById('calibrationReset');
  const calibrationDirectionReset = document.getElementById('calibrationDirectionReset');
  const calibrationFovDecrease = document.getElementById('calibrationFovDecrease');
  const calibrationFovIncrease = document.getElementById('calibrationFovIncrease');
  const calibrationFovReset = document.getElementById('calibrationFovReset');
  const calibrationFovInput = document.getElementById('calibrationFovInput');
  const replayLayoutMode = document.getElementById('replayLayoutMode');
  const calibrationSmoothness = document.getElementById('calibrationSmoothness');
  const calibrationSmoothnessValue = document.getElementById('calibrationSmoothnessValue');
  const horizonBarOffset = document.getElementById('horizonBarOffset');
  const horizonBarOffsetValue = document.getElementById('horizonBarOffsetValue');
  const attitudeReferenceOffset = document.getElementById('attitudeReferenceOffset');
  const attitudeReferenceOffsetValue = document.getElementById('attitudeReferenceOffsetValue');
  const yellowPitchReferenceOffset = document.getElementById('yellowPitchReferenceOffset');
  const yellowPitchReferenceOffsetValue = document.getElementById('yellowPitchReferenceOffsetValue');
  const pitchLadderScale = document.getElementById('pitchLadderScale');
  const pitchLadderScaleValue = document.getElementById('pitchLadderScaleValue');
  const instrumentToggles = Array.from(document.querySelectorAll('[data-instrument-toggle]'));
  const calibrationValues = document.getElementById('calibrationValues');
  let payload = null;
  let activeT = 0;
  let animationFrame = null;
  let cesiumViewer = null;
  let cesiumReady = false;
  let displayCamera = null;
  let lastRenderMs = null;
  let positionKeyframes = [];
  let cameraMode = 'synthetic_vision';
  let terrainEnabled = false;
  let terrainStatus = 'not_initialized';
  let terrainWarningMessage = '';
  let lastTerrainSampleMs = 0;
  let lastTerrainHeightM = null;
  let lastTerrainRequestKey = '';
  let lastVisualAltitudeM = null;
  let currentCameraDebug = null;
  let previousSyntheticFrameDebug = null;
  let displayAirspeedKt = null;
  let displayAltitudeFt = null;
  let displayVsiFpm = null;
  let displayHsiHeadingDeg = null;
  let displayHsiHeadingBugDeg = null;
  let displayHsiRmiBearingDeg = null;
  let displayHsiCdiOffsetPx = null;
  let displayAttitudeYellowReferenceX = null;
  let displayFpvHeadingDeltaDeg = null;
  let displayFpvPitchDeltaDeg = null;
  let displayFpvX = null;
  let displayFpvY = null;
  let displayFdRollCommandDeg = null;
  let displayFdPitchCommandDeg = null;
  let lastValidFpvVector = null;
  let lastValidFpvReplayT = null;
  let displayRpm = null;
  const displayEngineValues = new Map();
  let altimeterSettingUnit = 'hpa';
  let hsiOverlaySignature = '';
  let attitudeOverlaySignature = '';
  let insetMapSignature = '';
  let afcsManualDisconnectReplayT = null;
  let afcsLastStateUpper = '';
  let insetMapZoom = 1;
  let insetMapProjection = null;
  let insetMapPanE = 0;
  let insetMapPanN = 0;
  let insetMapDragState = null;
  let suppressInsetMapClick = false;
  let localVisualAltitudeOffsetM = null;
  let localVisualAltitudeOffsetSource = 'not_initialized';
  let visualFallbackPlaying = false;
  let visualFallbackStartedMs = 0;
  let visualFallbackStartedT = 0;
  let standalonePlaying = false;
  let standaloneStartedMs = 0;
  let standaloneStartedT = 0;

  const CAMERA_DEFAULTS = {
    rangeM: 125,
    heightM: 28,
    pitchDeg: -10,
    smoothing: 6,
  };
  const SYNTHETIC_VISION_DEFAULTS = {
    eyeHeightM: 1.4,
    forwardOffsetM: 1.8,
    rightOffsetM: 0.0,
    horizontalFovDeg: 80,
    verticalFovFallbackDeg: 38,
    positionSmoothing: 10,
    yawBiasDeg: 0,
    rollBiasDeg: 0,
  };
  const SYNTHETIC_TEST_HEADING_DEG = 230;
  const AIRSPEED_TAPE_SMOOTHING_RATE = 18;
  const ALTIMETER_TAPE_SMOOTHING_RATE = 18;
  const VSI_SMOOTHING_RATE = 5;
  const RPM_NEEDLE_SMOOTHING_RATE = 4.5;
  const ENGINE_GAUGE_SMOOTHING_RATE = 4.5;
  const ALTIMETER_SETTING_UNIT_STORAGE_KEY = 'ipca.cockpitReplay.altimeterSettingUnit.v1';
  const BODY_AXIS_MAPPING = {
    eyeOffsetXForwardM: SYNTHETIC_VISION_DEFAULTS.forwardOffsetM,
    eyeOffsetYRightM: SYNTHETIC_VISION_DEFAULTS.rightOffsetM,
    eyeOffsetZUpM: SYNTHETIC_VISION_DEFAULTS.eyeHeightM,
  };
  const CAMERA_STORAGE_KEY = 'ipca.cockpitReplay.camera.v1';
  const CAMERA_CALIBRATION_STORAGE_KEY = 'ipca.cockpitReplay.cameraCalibration.v6';
  const CAMERA_PRESET_SCHEMA_VERSION = 1;
  const CAMERA_PRESET_ADMIN_LOCKED = false;
  const INSTRUMENT_TOGGLE_IDS = [
    'airspeed_indicator',
    'altimeter',
    'hsi',
    'aoa_indicator',
    'inset_map',
    'traffic',
    'horizon_bar',
    'attitude_indicator',
    'flight_director_bars',
    'flight_path_vector',
    'radio_stack',
    'navaid_stack',
    'autopilot_fma',
    'engine_instrument_stack',
    'wind_indicator',
  ];
  const DEFAULT_ENABLED_INSTRUMENTS = new Set(['airspeed_indicator', 'altimeter', 'hsi', 'horizon_bar', 'attitude_indicator', 'wind_indicator', 'aoa_indicator', 'inset_map', 'engine_instrument_stack']);
  const IMPLEMENTED_INSTRUMENTS = ['airspeed_indicator', 'altimeter', 'hsi', 'aoa_indicator', 'inset_map', 'traffic', 'horizon_bar', 'attitude_indicator', 'flight_director_bars', 'engine_instrument_stack', 'wind_indicator', 'radio_stack', 'navaid_stack', 'autopilot_fma'];
  const CAMERA_SNAP_SEEK_SEC = 0.75;
  const POSITION_KEY_MIN_DIST_M = 0.15;
  const INSET_MAP_SIZE = 240;
  const INSET_MAP_PADDING = 18;
  const INSET_MAP_MAGENTA = '#ff00df';
  const AIRCRAFT_SILHOUETTE_PATH = 'M 0.0 -30.4 L 0.7 -29.5 L 1.3 -28.6 L 1.8 -27.7 L 2.4 -26.8 L 2.9 -25.9 L 3.3 -24.9 L 3.7 -24.0 L 3.9 -23.1 L 4.2 -22.2 L 4.5 -21.3 L 4.7 -20.3 L 4.9 -19.4 L 5.0 -18.5 L 5.1 -17.6 L 5.2 -16.7 L 28.5 -15.7 L 42.0 -14.8 L 47.8 -13.9 L 48.6 -13.0 L 49.2 -12.1 L 49.6 -11.2 L 49.9 -10.2 L 50.0 -9.3 L 50.0 -8.4 L 49.9 -7.5 L 21.8 -6.6 L 4.7 -5.6 L 4.5 -4.7 L 4.2 -3.8 L 3.9 -2.9 L 3.7 -2.0 L 3.4 -1.0 L 3.3 -0.1 L 3.0 0.8 L 2.8 1.7 L 2.5 2.6 L 2.4 3.5 L 2.1 4.5 L 2.0 5.4 L 1.8 6.3 L 1.7 7.2 L 1.6 8.1 L 1.6 9.1 L 1.4 10.0 L 1.3 10.9 L 1.3 11.8 L 1.3 12.7 L 1.2 13.6 L 1.2 14.6 L 1.2 15.5 L 1.0 16.4 L 1.0 17.3 L 1.0 18.2 L 1.0 19.2 L 1.0 20.1 L 1.0 21.0 L 1.0 21.9 L 1.0 22.8 L 2.2 23.8 L 8.0 24.7 L 10.8 25.6 L 11.3 26.5 L 11.5 27.4 L 11.4 28.3 L 11.0 29.3 L 3.8 30.2 L -1.0 30.4 L -1.4 30.4 L -4.7 30.2 L -11.2 29.3 L -11.4 28.3 L -11.5 27.4 L -11.3 26.5 L -10.6 25.6 L -7.3 24.7 L -1.7 23.8 L -1.0 22.8 L -1.0 21.9 L -1.0 21.0 L -1.0 20.1 L -1.0 19.2 L -1.0 18.2 L -1.0 17.3 L -1.0 16.4 L -1.0 15.5 L -1.0 14.6 L -1.0 13.6 L -1.2 12.7 L -1.3 11.8 L -1.3 10.9 L -1.4 10.0 L -1.6 9.1 L -1.6 8.1 L -1.7 7.2 L -1.8 6.3 L -2.0 5.4 L -2.1 4.5 L -2.4 3.5 L -2.5 2.6 L -2.8 1.7 L -3.0 0.8 L -3.3 -0.1 L -3.4 -1.0 L -3.7 -2.0 L -3.9 -2.9 L -4.2 -3.8 L -4.5 -4.7 L -4.7 -5.6 L -30.8 -6.6 L -49.9 -7.5 L -50.0 -8.4 L -50.0 -9.3 L -49.9 -10.2 L -49.6 -11.2 L -49.2 -12.1 L -48.6 -13.0 L -47.6 -13.9 L -40.7 -14.8 L -26.6 -15.7 L -5.2 -16.7 L -5.1 -17.6 L -5.0 -18.5 L -4.9 -19.4 L -4.7 -20.3 L -4.5 -21.3 L -4.2 -22.2 L -3.9 -23.1 L -3.5 -24.0 L -3.1 -24.9 L -2.8 -25.9 L -2.4 -26.8 L -1.8 -27.7 L -1.2 -28.6 L -0.5 -29.5 L 0.0 -30.4 Z';
  let cameraSettings = null;
  let cameraCalibration = null;

  const fmtTime = (seconds) => {
    seconds = Math.max(0, Math.round(Number(seconds) || 0));
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`;
  };

  const feetToMeters = (feet) => Number(feet || 0) * 0.3048;
  const PILOT_EYE_HEIGHT_M = feetToMeters(5);
  const degToRad = (deg) => Number(deg || 0) * Math.PI / 180;
  const normalizeDeg = (deg) => ((Number(deg) % 360) + 360) % 360;
  const normalizeSignedDeg = (deg) => {
    const normalized = normalizeDeg(deg);
    return normalized > 180 ? normalized - 360 : normalized;
  };
  const finiteNumber = (value) => {
    if (typeof value === 'string' && value.trim() === '') return null;
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
  };
  const positiveFinite = (value) => {
    const n = finiteNumber(value);
    return n !== null && n > 0 ? n : null;
  };
  const firstFinite = (...values) => {
    for (const value of values) {
      const n = finiteNumber(value);
      if (n !== null) return n;
    }
    return null;
  };
  const firstPositiveFinite = (...values) => {
    for (const value of values) {
      const n = positiveFinite(value);
      if (n !== null) return n;
    }
    return null;
  };
  const qualityClass = (quality) => {
    const q = String(quality || '').toUpperCase();
    if (q === 'GOOD') return 'replay-quality-good';
    if (q === 'DEGRADED') return 'replay-quality-degraded';
    if (q === 'LOW') return 'replay-quality-low';
    return 'replay-quality-unknown';
  };
  const escapeHtml = (text) => String(text ?? '').replace(/[<>&"]/g, (ch) => ({
    '<': '&lt;',
    '>': '&gt;',
    '&': '&amp;',
    '"': '&quot;',
  }[ch]));
  const qualityValue = (sample, field) => String((sample && sample[field]) || 'unknown').toUpperCase();
  const sourceValue = (sample, field) => String((sample && sample[field]) || '');
  const magneticToTrueHeadingDeg = (magnetic, variation, trueReference) => {
    const plus = normalizeDeg(magnetic + variation);
    const minus = normalizeDeg(magnetic - variation);
    if (Number.isFinite(trueReference)) {
      const delta = (a, b) => {
        let d = ((b - a + 540) % 360) - 180;
        return Math.abs(d);
      };
      return delta(plus, trueReference) <= delta(minus, trueReference) ? plus : minus;
    }
    return minus;
  };
  const bestAltitudeFt = (sample) => {
    if (!sample) return 0;
    if (Number.isFinite(Number(sample.altitude_ft_msl))) return Number(sample.altitude_ft_msl);
    if (Number.isFinite(Number(sample.altitude_ft))) return Number(sample.altitude_ft);
    return Number.isFinite(Number(sample.estimated_true_altitude_from_indicated_ft)) ? Number(sample.estimated_true_altitude_from_indicated_ft)
      : (Number.isFinite(Number(sample.estimated_indicated_altitude_ft)) ? Number(sample.estimated_indicated_altitude_ft)
      : (Number.isFinite(Number(sample.field_calibrated_true_altitude_ft)) ? Number(sample.field_calibrated_true_altitude_ft)
      : (Number.isFinite(Number(sample.gps_altitude_ft)) ? Number(sample.gps_altitude_ft) : 0)));
  };
  const isGroundSample = (sample) => {
    const speed = Number(sample && (sample.ground_speed_kt ?? sample.groundspeed_kt));
    const phase = String((sample && sample.phase) || '').toLowerCase();
    return (Number.isFinite(speed) && speed < 5)
      || phase.includes('preflight')
      || phase.includes('taxi')
      || phase.includes('ground')
      || phase.includes('block');
  };
  const rawAltitudeM = (sample) => feetToMeters(bestAltitudeFt(sample));
  const terrainLooksCredibleForGround = (msl) => {
    if (!Number.isFinite(msl) || !Number.isFinite(lastTerrainHeightM)) return false;
    if (Math.abs(lastTerrainHeightM - msl) <= 8) return true;
    if (Math.abs(msl) <= 5 && Math.abs(lastTerrainHeightM) <= 8) return true;
    return Math.sign(msl) === Math.sign(lastTerrainHeightM) && Math.abs(lastTerrainHeightM - msl) <= 20;
  };
  const groundReferenceAltitudeM = (sample) => {
    const msl = rawAltitudeM(sample);
    if (!isGroundSample(sample)) {
      return msl;
    }
    if (Number.isFinite(lastTerrainHeightM) && terrainLooksCredibleForGround(msl)) {
      return lastTerrainHeightM;
    }
    if (Number.isFinite(msl)) {
      return msl;
    }
    return Number.isFinite(lastTerrainHeightM) ? lastTerrainHeightM : 0;
  };
  const groundReferenceSource = (sample) => {
    const msl = rawAltitudeM(sample);
    if (!isGroundSample(sample)) {
      return Number.isFinite(msl) ? 'replay_airborne_altitude' : 'unavailable';
    }
    if (Number.isFinite(lastTerrainHeightM) && terrainLooksCredibleForGround(msl)) {
      return 'cesium_rendered_terrain_ground';
    }
    if (Number.isFinite(lastTerrainHeightM) && Number.isFinite(msl)) {
      return 'replay_ground_altitude_terrain_rejected';
    }
    return 'replay_ground_altitude';
  };
  const visualAltitudeM = (sample) => {
    const msl = rawAltitudeM(sample);
    const groundReferenceM = groundReferenceAltitudeM(sample);
    if (isGroundSample(sample)) {
      return groundReferenceM;
    }
    return msl;
  };
  const visualAltitudeState = (sample, timeS) => {
    const garminAltitudeM = rawAltitudeM(sample);
    const ground = isGroundSample(sample);
    const groundReferenceM = groundReferenceAltitudeM(sample);
    if (ground && Number.isFinite(groundReferenceM) && Number.isFinite(garminAltitudeM)) {
      localVisualAltitudeOffsetM = groundReferenceM - garminAltitudeM;
      localVisualAltitudeOffsetSource = Number.isFinite(lastTerrainHeightM)
        ? 'ground_cesium_minus_garmin'
        : 'ground_replay_minus_garmin';
    }

    const offsetM = Number.isFinite(localVisualAltitudeOffsetM) ? localVisualAltitudeOffsetM : 0;
    const altitudeM = Number.isFinite(garminAltitudeM) ? garminAltitudeM + offsetM : groundReferenceM;

    return {
      altitudeM,
      garminAltitudeM,
      groundReferenceM,
      isGround: ground,
      localVisualAltitudeOffsetM: offsetM,
      localVisualAltitudeOffsetSource,
      altitudeCurvePreserved: true,
    };
  };
  const cameraEyeAltitudeM = (sample) => visualAltitudeM(sample) + PILOT_EYE_HEIGHT_M;

  const lerpAngleDeg = (from, to, alpha) => {
    const start = Number(from);
    const end = Number(to);
    if (!Number.isFinite(start)) return end;
    if (!Number.isFinite(end)) return start;
    let delta = ((end - start + 540) % 360) - 180;
    return normalizeDeg(start + delta * alpha);
  };

  const smoothFactor = (rate, dtSec) => 1 - Math.exp(-Math.max(0, rate) * Math.max(0, dtSec));
  const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
  const syntheticVisionHorizontalFovDeg = () => clamp(
    firstFinite(cameraCalibration && cameraCalibration.fovDeg, SYNTHETIC_VISION_DEFAULTS.horizontalFovDeg),
    35,
    100
  );
  const syntheticVisionFovState = () => {
    const container = cesiumViewer && cesiumViewer.container ? cesiumViewer.container : document.getElementById('cesiumReplay');
    const width = Number(container && container.clientWidth);
    const height = Number(container && container.clientHeight);
    if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) {
      const fallbackHorizontalFovDeg = syntheticVisionHorizontalFovDeg();
      return {
        horizontalFovDeg: fallbackHorizontalFovDeg,
        verticalFovDeg: SYNTHETIC_VISION_DEFAULTS.verticalFovFallbackDeg,
        viewportAspectRatio: 16 / 9,
        cesiumFovAxis: 'horizontal',
        cesiumFovDegToSet: fallbackHorizontalFovDeg,
      };
    }
    const aspect = width / height;
    const horizontalFovDeg = syntheticVisionHorizontalFovDeg();
    const hRad = degToRad(horizontalFovDeg);
    const verticalFovDeg = 2 * Math.atan(Math.tan(hRad / 2) / aspect) * 180 / Math.PI;
    const cesiumFovAxis = aspect >= 1 ? 'horizontal' : 'vertical';
    return {
      horizontalFovDeg,
      verticalFovDeg,
      viewportAspectRatio: aspect,
      cesiumFovAxis,
      cesiumFovDegToSet: cesiumFovAxis === 'horizontal' ? horizontalFovDeg : verticalFovDeg,
    };
  };

  function isSyntheticCameraMode(mode = cameraMode) {
    return String(mode || '').startsWith('synthetic_vision');
  }

  function syntheticTestAttitudeForMode(mode) {
    if (mode === 'synthetic_vision_test_bank') {
      return { headingDeg: SYNTHETIC_TEST_HEADING_DEG, pitchDeg: 0, rollDeg: 30 };
    }
    if (mode === 'synthetic_vision_test_pitch_up') {
      return { headingDeg: SYNTHETIC_TEST_HEADING_DEG, pitchDeg: 10, rollDeg: 0 };
    }
    if (mode === 'synthetic_vision_test_pitch_down') {
      return { headingDeg: SYNTHETIC_TEST_HEADING_DEG, pitchDeg: -10, rollDeg: 0 };
    }
    return { headingDeg: SYNTHETIC_TEST_HEADING_DEG, pitchDeg: 10, rollDeg: 30 };
  }

  function isSyntheticTestMode(mode = cameraMode) {
    return String(mode || '').startsWith('synthetic_vision_test');
  }

  function fixedSyntheticTestPosition() {
    const first = positionKeyframes[0];
    if (first) return Object.assign({}, first);
    return { t: 0, lat: 33.6362099, lon: -116.1611667, altitudeM: 0 };
  }

  function loadCameraSettings() {
    let saved = {};
    try {
      saved = JSON.parse(localStorage.getItem(CAMERA_STORAGE_KEY) || '{}') || {};
    } catch (err) {
      saved = {};
    }
    return {
      rangeM: clamp(firstFinite(saved.rangeM, CAMERA_DEFAULTS.rangeM), 60, 260),
      heightM: clamp(firstFinite(saved.heightM, CAMERA_DEFAULTS.heightM), 8, 90),
      pitchDeg: clamp(firstFinite(saved.pitchDeg, CAMERA_DEFAULTS.pitchDeg), -30, -4),
      smoothing: clamp(firstFinite(saved.smoothing, CAMERA_DEFAULTS.smoothing), 1, 12),
    };
  }

  function saveCameraSettings() {
    try {
      localStorage.setItem(CAMERA_STORAGE_KEY, JSON.stringify(cameraSettings));
    } catch (err) {
      // Camera tuning is optional; replay should keep working if storage is unavailable.
    }
  }

  function loadCameraCalibration() {
    let saved = {};
    try {
      saved = JSON.parse(localStorage.getItem(CAMERA_CALIBRATION_STORAGE_KEY) || '{}') || {};
    } catch (err) {
      saved = {};
    }
    const savedInstruments = saved.instruments && typeof saved.instruments === 'object' ? saved.instruments : {};
    const instruments = {};
    INSTRUMENT_TOGGLE_IDS.forEach((key) => {
      instruments[key] = savedInstruments[key] === true || (savedInstruments[key] === undefined && DEFAULT_ENABLED_INSTRUMENTS.has(key));
    });
    if (!IMPLEMENTED_INSTRUMENTS.some((key) => instruments[key] === true)) {
      DEFAULT_ENABLED_INSTRUMENTS.forEach((key) => {
        instruments[key] = true;
      });
    }
    return {
      forwardM: clamp(firstFinite(saved.forwardM, 0), -200, 200),
      rightM: clamp(firstFinite(saved.rightM, 0), -200, 200),
      upM: clamp(firstFinite(saved.upM, 0), -200, 200),
      yawDeg: clamp(firstFinite(saved.yawDeg, 0), -90, 90),
      pitchDeg: clamp(firstFinite(saved.pitchDeg, 0), -45, 45),
      rollDeg: clamp(firstFinite(saved.rollDeg, 0), -45, 45),
      fovDeg: clamp(firstFinite(saved.fovDeg, SYNTHETIC_VISION_DEFAULTS.horizontalFovDeg), 35, 100),
      smoothness: clamp(firstFinite(saved.smoothness, SYNTHETIC_VISION_DEFAULTS.positionSmoothing), 0, 20),
      horizonBarOffsetPx: clamp(firstFinite(saved.horizonBarOffsetPx, 0), -240, 240),
      attitudeReferenceOffsetPx: clamp(firstFinite(saved.attitudeReferenceOffsetPx, 0), -240, 240),
      yellowPitchReferenceOffsetPx: clamp(firstFinite(saved.yellowPitchReferenceOffsetPx, 0), -240, 240),
      pitchLadderScale: clamp(firstFinite(saved.pitchLadderScale, 1), 0.6, 1.6),
      layoutMode: saved.layoutMode === 'panel' ? 'panel' : 'legacy',
      stepM: clamp(firstFinite(saved.stepM, 1), 0.1, 25),
      instruments,
      presetSchemaVersion: CAMERA_PRESET_SCHEMA_VERSION,
      presetAdminLocked: CAMERA_PRESET_ADMIN_LOCKED,
    };
  }

  function saveCameraCalibration() {
    try {
      localStorage.setItem(CAMERA_CALIBRATION_STORAGE_KEY, JSON.stringify(cameraCalibration));
    } catch (err) {
      // Calibration is a local visual aid; replay should continue if storage is unavailable.
    }
  }

  function applyReplayLayoutMode() {
    if (!root || !cameraCalibration) return;
    root.classList.toggle('is-panel-layout', cameraCalibration.layoutMode === 'panel');
    attitudeOverlaySignature = '';
    updateHorizonLine(displayCamera);
    updateAttitudeIndicator(displayCamera, sampleAt(activeT));
    updateAirspeedTape(sampleAt(activeT), 1 / 60, true);
    updateAoaIndicator(sampleAt(activeT));
    updateAltimeterTape(sampleAt(activeT), 1 / 60, true);
    updateInsetMap(sampleAt(activeT), true);
    updateAvionicsHeader(sampleAt(activeT));
    safeRenderCesium(true);
  }

  function loadAltimeterSettingUnit() {
    try {
      const saved = String(localStorage.getItem(ALTIMETER_SETTING_UNIT_STORAGE_KEY) || '').toLowerCase();
      return saved === 'inhg' ? 'inhg' : 'hpa';
    } catch (err) {
      return 'hpa';
    }
  }

  function saveAltimeterSettingUnit() {
    try {
      localStorage.setItem(ALTIMETER_SETTING_UNIT_STORAGE_KEY, altimeterSettingUnit);
    } catch (err) {
      // Unit selection is cosmetic; ignore storage failures.
    }
  }

  function updateCalibrationPanel() {
    if (!cameraCalibration) return;
    if (calibrationStepSelect) {
      calibrationStepSelect.value = String(cameraCalibration.stepM);
    }
    if (calibrationFovInput) {
      calibrationFovInput.value = String(Math.round(cameraCalibration.fovDeg));
    }
    if (replayLayoutMode) {
      replayLayoutMode.value = cameraCalibration.layoutMode === 'panel' ? 'panel' : 'legacy';
    }
    if (calibrationSmoothness) {
      calibrationSmoothness.value = String(cameraCalibration.smoothness);
    }
    if (calibrationSmoothnessValue) {
      calibrationSmoothnessValue.textContent = Number(cameraCalibration.smoothness).toFixed(1);
    }
    if (horizonBarOffset) {
      horizonBarOffset.value = String(cameraCalibration.horizonBarOffsetPx);
    }
    if (horizonBarOffsetValue) {
      horizonBarOffsetValue.textContent = `${Math.round(cameraCalibration.horizonBarOffsetPx)} px`;
    }
    if (attitudeReferenceOffset) {
      attitudeReferenceOffset.value = String(cameraCalibration.attitudeReferenceOffsetPx);
    }
    if (attitudeReferenceOffsetValue) {
      attitudeReferenceOffsetValue.textContent = `${Math.round(cameraCalibration.attitudeReferenceOffsetPx)} px`;
    }
    if (yellowPitchReferenceOffset) {
      yellowPitchReferenceOffset.value = String(cameraCalibration.yellowPitchReferenceOffsetPx);
    }
    if (yellowPitchReferenceOffsetValue) {
      yellowPitchReferenceOffsetValue.textContent = `${Math.round(cameraCalibration.yellowPitchReferenceOffsetPx)} px`;
    }
    if (pitchLadderScale) {
      pitchLadderScale.value = String(cameraCalibration.pitchLadderScale);
    }
    if (pitchLadderScaleValue) {
      pitchLadderScaleValue.textContent = `${Number(cameraCalibration.pitchLadderScale).toFixed(2)}x`;
    }
    instrumentToggles.forEach((toggle) => {
      const key = toggle.getAttribute('data-instrument-toggle') || '';
      toggle.checked = cameraCalibration.instruments && cameraCalibration.instruments[key] === true;
    });
    applyInstrumentVisibility();
    if (!calibrationValues) return;
    const agl = currentCameraDebug && Number.isFinite(Number(currentCameraDebug.cameraHeightAboveTerrainM))
      ? `${Number(currentCameraDebug.cameraHeightAboveTerrainM).toFixed(1)}m AGL`
      : 'AGL --';
    calibrationValues.textContent = [
      `F ${cameraCalibration.forwardM >= 0 ? '+' : ''}${cameraCalibration.forwardM.toFixed(1)}m`,
      `R ${cameraCalibration.rightM >= 0 ? '+' : ''}${cameraCalibration.rightM.toFixed(1)}m`,
      `U ${cameraCalibration.upM >= 0 ? '+' : ''}${cameraCalibration.upM.toFixed(1)}m`,
      `Yaw ${cameraCalibration.yawDeg >= 0 ? '+' : ''}${cameraCalibration.yawDeg.toFixed(1)}deg`,
      `Pitch ${cameraCalibration.pitchDeg >= 0 ? '+' : ''}${cameraCalibration.pitchDeg.toFixed(1)}deg`,
      `Roll ${cameraCalibration.rollDeg >= 0 ? '+' : ''}${cameraCalibration.rollDeg.toFixed(1)}deg`,
      `FOV ${cameraCalibration.fovDeg.toFixed(0)}deg H`,
      `Layout ${cameraCalibration.layoutMode === 'panel' ? 'panel' : 'legacy'}`,
      `Smooth ${cameraCalibration.smoothness.toFixed(1)}`,
      `Horizon ${cameraCalibration.horizonBarOffsetPx >= 0 ? '+' : ''}${Math.round(cameraCalibration.horizonBarOffsetPx)}px`,
      `AttRef ${cameraCalibration.attitudeReferenceOffsetPx >= 0 ? '+' : ''}${Math.round(cameraCalibration.attitudeReferenceOffsetPx)}px`,
      `Yellow ${cameraCalibration.yellowPitchReferenceOffsetPx >= 0 ? '+' : ''}${Math.round(cameraCalibration.yellowPitchReferenceOffsetPx)}px`,
      `Ladder ${cameraCalibration.pitchLadderScale.toFixed(2)}x`,
      agl,
    ].join(' | ');
  }

  function adjustCameraCalibration(axis, sign) {
    if (!cameraCalibration) return;
    const delta = (Number(sign) || 0) * cameraCalibration.stepM;
    if (axis === 'forward') cameraCalibration.forwardM = clamp(cameraCalibration.forwardM + delta, -200, 200);
    if (axis === 'right') cameraCalibration.rightM = clamp(cameraCalibration.rightM + delta, -200, 200);
    if (axis === 'up') cameraCalibration.upM = clamp(cameraCalibration.upM + delta, -200, 200);
    if (axis === 'yaw') cameraCalibration.yawDeg = clamp(cameraCalibration.yawDeg + delta, -90, 90);
    if (axis === 'pitch') cameraCalibration.pitchDeg = clamp(cameraCalibration.pitchDeg + delta, -45, 45);
    if (axis === 'roll') cameraCalibration.rollDeg = clamp(cameraCalibration.rollDeg + delta, -45, 45);
    saveCameraCalibration();
    updateCalibrationPanel();
    safeRenderCesium(true);
  }

  function setSyntheticVisionFov(value) {
    if (!cameraCalibration) return;
    const next = firstFinite(value, SYNTHETIC_VISION_DEFAULTS.horizontalFovDeg);
    cameraCalibration.fovDeg = clamp(Math.round(next), 35, 100);
    saveCameraCalibration();
    updateCalibrationPanel();
    safeRenderCesium(true);
  }

  function setSyntheticVisionSmoothness(value) {
    if (!cameraCalibration) return;
    cameraCalibration.smoothness = clamp(firstFinite(value, SYNTHETIC_VISION_DEFAULTS.positionSmoothing), 0, 20);
    saveCameraCalibration();
    updateCalibrationPanel();
  }

  function setReplayLayoutMode(value) {
    if (!cameraCalibration) return;
    cameraCalibration.layoutMode = value === 'panel' ? 'panel' : 'legacy';
    saveCameraCalibration();
    updateCalibrationPanel();
    applyReplayLayoutMode();
  }

  function setHorizonBarOffset(value) {
    if (!cameraCalibration) return;
    cameraCalibration.horizonBarOffsetPx = clamp(firstFinite(value, 0), -240, 240);
    saveCameraCalibration();
    updateCalibrationPanel();
    updateHorizonLine(displayCamera);
    updateAttitudeIndicator(displayCamera, sampleAt(activeT));
    updateAirspeedTape(sampleAt(activeT), 1 / 60, true);
    updateAoaIndicator(sampleAt(activeT));
    updateAltimeterTape(sampleAt(activeT), 1 / 60, true);
  }

  function setAttitudeReferenceOffset(value) {
    if (!cameraCalibration) return;
    cameraCalibration.attitudeReferenceOffsetPx = clamp(firstFinite(value, 0), -240, 240);
    saveCameraCalibration();
    updateCalibrationPanel();
    updateAttitudeIndicator(displayCamera, sampleAt(activeT));
  }

  function setYellowPitchReferenceOffset(value) {
    if (!cameraCalibration) return;
    cameraCalibration.yellowPitchReferenceOffsetPx = clamp(firstFinite(value, 0), -240, 240);
    saveCameraCalibration();
    updateCalibrationPanel();
    updateAttitudeIndicator(displayCamera, sampleAt(activeT));
  }

  function setPitchLadderScale(value) {
    if (!cameraCalibration) return;
    cameraCalibration.pitchLadderScale = clamp(firstFinite(value, 1), 0.6, 1.6);
    saveCameraCalibration();
    updateCalibrationPanel();
    updateAttitudeIndicator(displayCamera, sampleAt(activeT));
  }

  function setInstrumentPlaceholder(key, enabled) {
    if (!cameraCalibration || !key) return;
    if (!cameraCalibration.instruments || typeof cameraCalibration.instruments !== 'object') {
      cameraCalibration.instruments = {};
    }
    cameraCalibration.instruments[key] = enabled === true;
    saveCameraCalibration();
    updateCalibrationPanel();
    applyInstrumentVisibility();
    updateHorizonLine(displayCamera);
    updateAttitudeIndicator(displayCamera, sampleAt(activeT));
    updateAirspeedTape(sampleAt(activeT), 1 / 60, true);
    updateAoaIndicator(sampleAt(activeT));
    updateAltimeterTape(sampleAt(activeT), 1 / 60, true);
    updateHsiOverlay(sampleAt(activeT), 1 / 60, true);
    updateEnginePanel(sampleAt(activeT), 1 / 60, true);
    updateInsetMap(sampleAt(activeT), true);
    updateAvionicsHeader(sampleAt(activeT));
    safeRenderCesium(true);
  }

  function instrumentEnabled(key) {
    if (!cameraCalibration || !cameraCalibration.instruments) return DEFAULT_ENABLED_INSTRUMENTS.has(key);
    return cameraCalibration.instruments[key] === true;
  }

  function setElementHidden(element, hidden) {
    if (!element) return;
    if (hidden) {
      element.setAttribute('hidden', '');
    } else {
      element.removeAttribute('hidden');
    }
    if ('hidden' in element) {
      element.hidden = hidden === true;
    }
  }

  function elementIsHidden(element) {
    return !element || element.hasAttribute('hidden') || element.hidden === true;
  }

  function applyInstrumentVisibility() {
    if (airspeedTape && !instrumentEnabled('airspeed_indicator')) setElementHidden(airspeedTape, true);
    if (altimeterStack && !instrumentEnabled('altimeter')) setElementHidden(altimeterStack, true);
    if (hsiOverlay && !instrumentEnabled('hsi')) setElementHidden(hsiOverlay, true);
    if (attitudeOverlay && !instrumentEnabled('attitude_indicator')) setElementHidden(attitudeOverlay, true);
    if (horizonLine && !instrumentEnabled('horizon_bar')) setElementHidden(horizonLine, true);
    if (insetMap && !instrumentEnabled('inset_map')) setElementHidden(insetMap, true);
    if (aoaIndicator && !instrumentEnabled('aoa_indicator')) setElementHidden(aoaIndicator, true);
    if (enginePanel && !instrumentEnabled('engine_instrument_stack')) setElementHidden(enginePanel, true);
  }

  function g3xField(sample, ...keys) {
    const g3x = sample && sample.g3x ? sample.g3x : {};
    for (const key of keys) {
      if (sample && Object.prototype.hasOwnProperty.call(sample, key)) {
        const value = sample[key];
        if (value !== null && value !== undefined && String(value).trim() !== '') return value;
      }
      if (g3x && Object.prototype.hasOwnProperty.call(g3x, key)) {
        const value = g3x[key];
        if (value !== null && value !== undefined && String(value).trim() !== '') return value;
      }
    }
    return null;
  }

  function g3xRawField(sample, ...keys) {
    const rawSources = [
      sample && sample.g3x,
      sample && sample.g3x_raw,
      sample && sample.raw_g3x,
    ];
    for (const source of rawSources) {
      if (!source) continue;
      for (const key of keys) {
        if (Object.prototype.hasOwnProperty.call(source, key)) {
          const value = source[key];
          if (value !== null && value !== undefined && String(value).trim() !== '') return value;
        }
      }
    }
    return null;
  }

  function formatAvionicsFrequency(value, band = 'generic') {
    if (value === null || value === undefined || String(value).trim() === '') return '---.---';
    const match = String(value).trim().match(/-?\d+(?:\.\d+)?/);
    const n = match ? Number(match[0]) : Number(value);
    if (!Number.isFinite(n)) return String(value).trim().toUpperCase().slice(0, 7);
    if (band === 'com' && (n < 118 || n > 136.995)) return '---.---';
    if (band === 'nav' && (n < 108 || n > 117.995)) return '---.---';
    if (band !== 'com' && band !== 'nav' && n <= 0) return '---.---';
    return n.toFixed(3);
  }

  function formatAvionicsText(value, fallback = '---') {
    const text = String(value ?? '').trim();
    return text === '' ? fallback : text.toUpperCase();
  }

  function firstAvionicsValue(...values) {
    for (const value of values) {
      if (value !== null && value !== undefined && String(value).trim() !== '') return value;
    }
    return null;
  }

  function normalizeAfcsToken(value, fallback = '--', maxLen = 4) {
    const raw = String(value ?? '').trim().toUpperCase();
    if (raw === '') return fallback;
    if (['0', 'FALSE', 'OFF', 'NO', 'NONE'].includes(raw)) return fallback;
    if (raw === '1' || raw === 'TRUE' || raw === 'ON') return 'AP';
    if (raw.includes('HEADING')) return 'HDG';
    if (raw.includes('NAV')) return raw.includes('2') ? 'NAV2' : 'NAV';
    if (raw.includes('GPS') || raw.includes('FMS')) return 'GPS';
    if (raw.includes('LOC')) return 'LOC';
    if (raw.includes('APP') || raw.includes('APR')) return 'APR';
    if (raw.includes('PITCH')) return 'PIT';
    if (raw.includes('ALT SEL') || raw.includes('ALTS')) return 'ALTS';
    if (raw.includes('ALT')) return 'ALT';
    if (raw.includes('VERT') || raw.includes('VS')) return 'VS';
    if (raw.includes('ROL')) return 'ROL';
    return raw.replace(/\s+/g, '').slice(0, maxLen);
  }

  function afcsStateDisplay(sample) {
    const raw = String(g3xField(sample, 'autopilot_state', 'ap_state') ||
      g3xRawField(sample, 'Autopilot State', 'AP State', 'AfcsOn') ||
      '').trim();
    const upper = raw.toUpperCase();
    const sampleT = sample && Number.isFinite(Number(sample.t)) ? Number(sample.t) : activeT;
    if (upper.includes('MANUAL') && upper.includes('DISENG')) {
      if (!(afcsLastStateUpper.includes('MANUAL') && afcsLastStateUpper.includes('DISENG')) || afcsManualDisconnectReplayT === null || Number(sampleT) < afcsManualDisconnectReplayT) {
        afcsManualDisconnectReplayT = Number(sampleT);
      }
      afcsLastStateUpper = upper;
      if (Number(sampleT) - afcsManualDisconnectReplayT <= 3) {
        return { text: 'AP', className: ' is-ap-disconnect', modesActive: false };
      }
      return { text: '', className: '', modesActive: false };
    }
    if (upper.includes('PFT')) {
      afcsManualDisconnectReplayT = null;
      afcsLastStateUpper = upper;
      return { text: 'PFT', className: ' is-pft', modesActive: false };
    }
    if (upper.includes('POWER')) {
      afcsManualDisconnectReplayT = null;
      afcsLastStateUpper = upper;
      return { text: 'Powerup', className: ' is-white', modesActive: false };
    }
    if (upper === 'AP' || upper.startsWith('AP ') || upper.includes('AP /') || upper.includes('ENGAGED') || upper === '1' || upper === 'TRUE' || upper === 'ON') {
      afcsManualDisconnectReplayT = null;
      afcsLastStateUpper = upper;
      return { text: 'AP', className: '', modesActive: true };
    }
    afcsManualDisconnectReplayT = null;
    afcsLastStateUpper = upper;
    return { text: '', className: '', modesActive: false };
  }

  function splitVerticalAfcsMode(value, fallback = '--') {
    const raw = String(value ?? '').trim();
    if (raw === '') return { active: fallback, armed: '' };
    const match = raw.match(/^([^()]+?)(?:\s*\(([^)]+)\))?$/);
    const active = normalizeAfcsToken(match ? match[1] : raw, fallback, 4);
    const armed = match && match[2] ? normalizeAfcsToken(match[2], '', 4) : '';
    return { active, armed };
  }

  function flightDirectorActive(sample) {
    const raw = String(
      g3xField(sample, 'flight_director_state', 'fd_state', 'flight_director_active', 'fd_active') ||
      g3xRawField(sample, 'Flight Director State', 'FD State', 'Flight Director Active', 'FD Active') ||
      ''
    ).trim().toUpperCase();
    if (raw === '') return false;
    if (['0', 'FALSE', 'OFF', 'NO', 'NONE'].includes(raw)) return false;
    return raw.includes('FD') || raw.includes('ON') || raw.includes('ACTIVE') || raw === '1' || raw === 'TRUE';
  }

  function afcsModeActiveText(value) {
    const text = String(value ?? '').trim().toUpperCase();
    if (text === '' || ['0', 'FALSE', 'OFF', 'NO', 'NONE', '--', '---'].includes(text)) return '';
    return text;
  }

  function flightDirectorCommandsActive(sample) {
    if (!sample) return false;
    const apState = String(g3xField(sample, 'autopilot_state', 'ap_state') || '').trim().toUpperCase();
    const lateralMode = afcsModeActiveText(g3xField(sample, 'fd_lateral_mode', 'fd_lat_mode', 'autopilot_lateral_mode'));
    const verticalMode = afcsModeActiveText(g3xField(sample, 'fd_vertical_mode', 'fd_vert_mode', 'autopilot_vertical_mode'));
    if (flightDirectorActive(sample) || lateralMode !== '' || verticalMode !== '') return true;
    return apState === 'AP' || apState.startsWith('AP ') || apState.includes('AP /') || apState.includes('ENGAGED');
  }

  function flightDirectorCommandFromSample(sample) {
    if (!flightDirectorCommandsActive(sample)) return null;
    const rollCommand = firstFinite(
      sample && sample.fd_roll_command_deg,
      sample && sample.ap_roll_command_deg,
      sample && sample.g3x && sample.g3x.fd_roll_command_deg,
      sample && sample.g3x && sample.g3x.ap_roll_command_deg
    );
    const pitchCommand = firstFinite(
      sample && sample.fd_pitch_command_deg,
      sample && sample.ap_pitch_command_deg,
      sample && sample.g3x && sample.g3x.fd_pitch_command_deg,
      sample && sample.g3x && sample.g3x.ap_pitch_command_deg
    );
    if (rollCommand === null && pitchCommand === null) return null;
    return {
      rollDeg: rollCommand,
      pitchDeg: pitchCommand,
    };
  }

  function comRxTxStatus(sample, index) {
    const prefix = `com${index}`;
    const exact = String(
      g3xField(sample, `${prefix}_status`, `${prefix}_rx_tx`, `${prefix}_rxtx`, `${prefix}_mhz`, `${prefix}_active_mhz`) ||
      g3xRawField(
        sample,
        `COM Frequency ${index} (MHz)`,
        `COM${index}`,
        `COM${index} Active Frequency (MHz)`,
        `COM${index} Status`,
        `COM ${index} Status`,
        `COM${index} RX/TX`,
        `COM ${index} RX/TX`,
        `COM${index} RxTx`,
        `COM ${index} RxTx`,
        `COM${index} Monitor`,
        `COM ${index} Monitor`
      ) ||
      ''
    ).toUpperCase();
    const raw = exact || scannedComRxTxStatus(sample, index);
    if (raw.includes('TX') || raw.includes('TRANSMIT')) return 'TX';
    if (raw.includes('RX') || raw.includes('RECEIV')) return 'RX';
    return '';
  }

  function scannedComRxTxStatus(sample, index) {
    const sources = [sample, sample && sample.g3x, sample && sample.g3x_raw, sample && sample.raw_g3x];
    const otherIndex = index === 1 ? '2' : '1';
    for (const source of sources) {
      if (!source || typeof source !== 'object') continue;
      for (const [key, value] of Object.entries(source)) {
        const keyText = String(key || '').toUpperCase();
        const compactKey = keyText.replace(/[^A-Z0-9]/g, '');
        if (!compactKey.includes('COM') || !compactKey.includes(String(index)) || compactKey.includes(`COM${otherIndex}`)) continue;
        const valueText = String(value ?? '').trim().toUpperCase();
        if (valueText.includes('TX') || valueText.includes('TRANSMIT')) return valueText;
        if (valueText.includes('RX') || valueText.includes('RECEIV')) return valueText;
      }
    }
    return '';
  }

  function radioFrequencyBoxHtml(kind, label, activeFreq, activeName, standbyFreq, standbyName, activeCdi = false, activeStatus = '') {
    const activeNameText = formatAvionicsText(activeName, '');
    const standbyNameText = formatAvionicsText(standbyName, '');
    const band = kind === 'nav' ? 'nav' : 'com';
    const activeFrequencyText = formatAvionicsFrequency(activeFreq, band);
    const standbyFrequencyText = formatAvionicsFrequency(standbyFreq, band);
    const standbyMissing = standbyFrequencyText === '---.---' && standbyNameText === '';
    const statusText = String(activeStatus || '').toUpperCase();
    return `
      <div class="avionics-box is-${kind}${activeCdi ? ' is-active-cdi' : ''}">
        <div class="avionics-label">${escapeHtml(label)}</div>
        <div class="avionics-frequency">
          <div class="avionics-value-line"><span class="avionics-value">${escapeHtml(activeFrequencyText)}</span>${statusText ? `<span class="avionics-rx-tx">${escapeHtml(statusText)}</span>` : ''}</div>
          <div class="avionics-name">${escapeHtml(activeNameText || ' ')}</div>
        </div>
        <div class="avionics-frequency${standbyMissing ? ' avionics-muted' : ''}">
          <div class="avionics-value is-standby">${escapeHtml(standbyFrequencyText)}</div>
          <div class="avionics-name">${escapeHtml(standbyNameText || ' ')}</div>
        </div>
      </div>`;
  }

  function transponderBoxHtml(sample) {
    const code = g3xField(sample, 'transponder_code', 'xpdr_code') ||
      g3xRawField(sample, 'Transponder Code', 'XPDR Code', 'Squawk', 'XPDR');
    const mode = g3xField(sample, 'transponder_mode', 'xpdr_mode') ||
      g3xRawField(sample, 'Transponder Mode', 'XPDR Mode');
    const codeText = String(formatAvionicsText(code, '----')).replace(/\D+/g, '').slice(0, 4) || '----';
    const modeText = normalizeAfcsToken(mode, '---', 4);
    return `
      <div class="avionics-box is-xpdr">
        <div class="avionics-label">XPDR</div>
        <div class="avionics-frequency">
          <div class="avionics-value">${escapeHtml(codeText)}</div>
          <div class="avionics-name">${escapeHtml(modeText)}</div>
        </div>
      </div>`;
  }

  function autopilotFmaHtml(sample) {
    const lateralMode = g3xField(sample, 'fd_lateral_mode', 'fd_lat_mode', 'autopilot_lateral_mode') ||
      g3xRawField(sample, 'FD Lateral Mode', 'AP Lateral Mode', 'Lateral Mode');
    const verticalMode = g3xField(sample, 'fd_vertical_mode', 'fd_vert_mode', 'autopilot_vertical_mode') ||
      g3xRawField(sample, 'FD Vertical Mode', 'AP Vertical Mode', 'Vertical Mode');
    const armedMode = g3xField(sample, 'autopilot_armed_mode', 'ap_armed_mode') ||
      g3xRawField(sample, 'AP Armed Mode', 'Armed Mode', 'ALT Armed');
    const apState = afcsStateDisplay(sample);
    const lateralModeActive = afcsModeActiveText(lateralMode);
    const verticalModeActive = afcsModeActiveText(verticalMode);
    const modesActive = apState.modesActive || flightDirectorActive(sample) || lateralModeActive !== '' || verticalModeActive !== '';
    const lateralLabel = modesActive ? normalizeAfcsToken(lateralMode, '--', 4) : '';
    const verticalModes = modesActive ? splitVerticalAfcsMode(verticalMode, '--') : { active: '', armed: '' };
    const armedLabel = modesActive ? (verticalModes.armed || normalizeAfcsToken(armedMode, '', 4)) : '';
    return `
      <div class="avionics-box is-afcs">
        <div class="avionics-afcs-title">AFCS</div>
        <div class="avionics-afcs-cell${apState.className}"><span class="avionics-afcs-ap-badge">${escapeHtml(apState.text || ' ')}</span></div>
        <div class="avionics-afcs-cell">${escapeHtml(lateralLabel || ' ')}</div>
        <div class="avionics-afcs-cell">${escapeHtml(verticalModes.active || ' ')}</div>
        <div class="avionics-afcs-cell is-white">${escapeHtml(armedLabel || ' ')}</div>
      </div>`;
  }

  function updateAvionicsHeader(sample) {
    const radioVisible = instrumentEnabled('radio_stack');
    if (radioStackGroup || radioStackEndGroup) {
      const com1Active = firstAvionicsValue(
        g3xField(sample, 'com1_mhz', 'com1_active_mhz'),
        g3xRawField(sample, 'COM Frequency 1 (MHz)', 'COM1', 'COM1 Active Frequency (MHz)')
      );
      const com1Standby = firstAvionicsValue(
        g3xField(sample, 'com1_standby_mhz', 'com1_stby_mhz'),
        g3xRawField(sample, 'COM Standby Frequency 1 (MHz)', 'COM1 Standby Frequency (MHz)', 'COM1 Stby', 'COM1SB')
      );
      const com2Active = firstAvionicsValue(
        g3xField(sample, 'com2_mhz', 'com2_active_mhz'),
        g3xRawField(sample, 'COM Frequency 2 (MHz)', 'COM2', 'COM2 Active Frequency (MHz)')
      );
      const com2Standby = firstAvionicsValue(
        g3xField(sample, 'com2_standby_mhz', 'com2_stby_mhz'),
        g3xRawField(sample, 'COM Standby Frequency 2 (MHz)', 'COM2 Standby Frequency (MHz)', 'COM2 Stby', 'COM2SB')
      );
      if (radioStackGroup) {
        setElementHidden(radioStackGroup, !radioVisible);
        radioStackGroup.innerHTML = [
          radioFrequencyBoxHtml('radio', 'COM 1', com1Active, g3xField(sample, 'com1_name', 'com1_active_name') || g3xRawField(sample, 'COM1 Name', 'COM1 Active Name'), com1Standby, g3xField(sample, 'com1_standby_name', 'com1_stby_name') || g3xRawField(sample, 'COM1 Standby Name'), false, comRxTxStatus(sample, 1)),
          transponderBoxHtml(sample),
        ].join('');
      }
      if (radioStackEndGroup) {
        setElementHidden(radioStackEndGroup, !radioVisible);
        radioStackEndGroup.innerHTML = radioVisible
          ? radioFrequencyBoxHtml('radio', 'COM 2', com2Active, g3xField(sample, 'com2_name', 'com2_active_name') || g3xRawField(sample, 'COM2 Name', 'COM2 Active Name'), com2Standby, g3xField(sample, 'com2_standby_name', 'com2_stby_name') || g3xRawField(sample, 'COM2 Standby Name'), false, comRxTxStatus(sample, 2))
          : '';
      }
    }
    if (autopilotFmaGroup) {
      const visible = instrumentEnabled('autopilot_fma');
      setElementHidden(autopilotFmaGroup, !visible);
      autopilotFmaGroup.innerHTML = visible ? autopilotFmaHtml(sample) : '';
    }
    if (navaidStackGroup) {
      const visible = instrumentEnabled('navaid_stack');
      setElementHidden(navaidStackGroup, !visible);
      if (visible) {
        const rawSource = hsiRawNavSourceFromSample(sample).toUpperCase().replace(/\s+/g, '');
        const navActive = rawSource.includes('NAV2') || rawSource.includes('VOR2');
        const nav2Active = firstAvionicsValue(
          g3xField(sample, 'nav2_mhz', 'nav2_active_mhz'),
          g3xRawField(sample, 'NAV Frequency 2 (MHz)', 'NAV2', 'NAV2 Active Frequency (MHz)')
        );
        const nav2Standby = firstAvionicsValue(
          g3xField(sample, 'nav2_standby_mhz', 'nav2_stby_mhz'),
          g3xRawField(sample, 'NAV Standby Frequency 2 (MHz)', 'NAV2 Standby Frequency (MHz)', 'NAV2 Stby', 'NAV2SB')
        );
        navaidStackGroup.innerHTML = radioFrequencyBoxHtml(
          'nav',
          'NAV 2',
          nav2Active,
          g3xField(sample, 'nav2_name', 'nav2_active_name', 'nav_identifier') || g3xRawField(sample, 'NAV2 Name', 'NAV2 Active Name', 'Nav Identifier', 'NavIdent'),
          nav2Standby,
          g3xField(sample, 'nav2_standby_name', 'nav2_stby_name') || g3xRawField(sample, 'NAV2 Standby Name'),
          navActive
        );
      }
    }
  }

  function instrumentAnchorRect(element) {
    if (!element) return null;
    if (!elementIsHidden(element)) {
      const rect = element.getBoundingClientRect();
      return rect.width > 0 && rect.height > 0 ? rect : null;
    }
    const previousVisibility = element.style.visibility;
    element.style.visibility = 'hidden';
    setElementHidden(element, false);
    const rect = element.getBoundingClientRect();
    setElementHidden(element, true);
    element.style.visibility = previousVisibility;
    return rect.width > 0 && rect.height > 0 ? rect : null;
  }

  function airspeedProfileNumber(path, fallback) {
    let value = AIRSPEED_PROFILE;
    for (const key of path) {
      value = value && value[key];
    }
    const n = Number(value);
    return Number.isFinite(n) ? n : fallback;
  }

  function airspeedSpeedToY(speedKt, currentIasKt, centerY, pxPerKt) {
    return centerY + (currentIasKt - speedKt) * pxPerKt;
  }

  function airspeedHtmlBand(className, fromKt, toKt, currentIasKt, centerY, pxPerKt, heightPx) {
    const from = Number(fromKt);
    const to = Number(toKt);
    if (!Number.isFinite(from) || !Number.isFinite(to) || to <= from) return '';
    const yTopRaw = airspeedSpeedToY(to, currentIasKt, centerY, pxPerKt);
    const yBottomRaw = airspeedSpeedToY(from, currentIasKt, centerY, pxPerKt);
    const yTop = clamp(yTopRaw, -heightPx, heightPx * 2);
    const yBottom = clamp(yBottomRaw, -heightPx, heightPx * 2);
    if (yBottom < 0 || yTop > heightPx) return '';
    return `<div class="airspeed-tape-color-band ${className}" style="top:${yTop.toFixed(1)}px;height:${Math.max(2, yBottom - yTop).toFixed(1)}px"></div>`;
  }

  function updateAirspeedTape(sample, dtSec = 1 / 60, snap = false) {
    if (!airspeedTape) return;
    if (!sample || !instrumentEnabled('airspeed_indicator')) {
      setElementHidden(airspeedTape, true);
      displayAirspeedKt = null;
      return;
    }
    const ias = firstFinite(sample.ias_kt, sample.indicated_airspeed_kt, sample.airspeed_kt);
    if (ias === null) {
      setElementHidden(airspeedTape, true);
      displayAirspeedKt = null;
      return;
    }
    if (snap || displayAirspeedKt === null || !Number.isFinite(displayAirspeedKt) || Math.abs(displayAirspeedKt - ias) > 25) {
      displayAirspeedKt = ias;
    } else {
      const alpha = smoothFactor(AIRSPEED_TAPE_SMOOTHING_RATE, dtSec);
      displayAirspeedKt += (ias - displayAirspeedKt) * alpha;
    }
    const tas = firstFinite(sample.tas_kt, sample.estimated_tas_kt);
    const gs = firstFinite(sample.ground_speed_kt, sample.groundspeed_kt);
    const bodyHeight = Number(airspeedTapeBody && airspeedTapeBody.clientHeight) || 420;
    const centerY = bodyHeight / 2;
    const pxPerKt = 8;
    const tapeMin = airspeedProfileNumber(['tape_min_kt'], 0);
    const tapeMax = airspeedProfileNumber(['tape_max_kt'], 160);
    const visibleMin = Math.max(tapeMin, Math.floor((displayAirspeedKt - (centerY / pxPerKt) - 10) / 5) * 5);
    const visibleMax = Math.min(tapeMax, Math.ceil((displayAirspeedKt + (centerY / pxPerKt) + 10) / 5) * 5);
    const tickMin = Math.ceil(visibleMin / 5) * 5;
    let scaleHtml = '';
    for (let speed = tickMin; speed <= visibleMax; speed += 5) {
      const y = airspeedSpeedToY(speed, displayAirspeedKt, centerY, pxPerKt);
      const major = speed % 10 === 0;
      scaleHtml += `<div class="airspeed-tape-tick ${major ? 'is-major' : 'is-minor'}" style="top:${y.toFixed(1)}px"></div>`;
      if (major) {
        scaleHtml += `<div class="airspeed-tape-number" style="top:${y.toFixed(1)}px">${Math.round(speed)}</div>`;
      }
    }
    const colorsHtml = [
      airspeedHtmlBand('is-white', airspeedProfileNumber(['white_arc', 'from_kt'], 42), airspeedProfileNumber(['white_arc', 'to_kt'], 70), displayAirspeedKt, centerY, pxPerKt, bodyHeight),
      airspeedHtmlBand('is-green', airspeedProfileNumber(['green_arc', 'from_kt'], 49), airspeedProfileNumber(['green_arc', 'to_kt'], 104), displayAirspeedKt, centerY, pxPerKt, bodyHeight),
      airspeedHtmlBand('is-yellow', airspeedProfileNumber(['yellow_arc', 'from_kt'], 104), airspeedProfileNumber(['yellow_arc', 'to_kt'], 130), displayAirspeedKt, centerY, pxPerKt, bodyHeight),
    ].join('');
    const redLineKt = airspeedProfileNumber(['red_line_kt'], 130);
    const redLineY = airspeedSpeedToY(redLineKt, displayAirspeedKt, centerY, pxPerKt);
    const redHtml = redLineY >= 0 && redLineY <= bodyHeight
      ? `<div class="airspeed-tape-redline" style="top:${redLineY.toFixed(1)}px"></div>`
      : '';
    const bugs = Array.isArray(AIRSPEED_PROFILE.bugs) ? AIRSPEED_PROFILE.bugs : [];
    const bugHtml = bugs.map((bug) => {
      const speed = Number(bug && bug.speed_kt);
      const label = String((bug && bug.label) || '').slice(0, 2);
      const y = airspeedSpeedToY(speed, displayAirspeedKt, centerY, pxPerKt);
      if (!Number.isFinite(speed) || y < -20 || y > bodyHeight + 20 || label === '') return '';
      return `<div class="airspeed-tape-bug" style="top:${y.toFixed(1)}px">${escapeHtml(label)}</div>`;
    }).join('');
    setElementHidden(airspeedTape, false);
    if (airspeedTapeScale) airspeedTapeScale.innerHTML = scaleHtml;
    if (airspeedTapeColors) airspeedTapeColors.innerHTML = colorsHtml + redHtml;
    if (airspeedTapeBugs) airspeedTapeBugs.innerHTML = bugHtml;
    if (airspeedTapePointer) airspeedTapePointer.textContent = String(Math.round(displayAirspeedKt));
    if (airspeedTasValue) airspeedTasValue.textContent = tas === null ? '--' : String(Math.round(tas));
    if (airspeedGsValue) airspeedGsValue.textContent = gs === null ? '--' : String(Math.round(gs));
  }

  function aoaProfileNumber(key, fallback) {
    return airspeedProfileNumber(['aoa_indicator', key], fallback);
  }

  function normalizedAoaRatio(aoa, stallThreshold) {
    const value = Number(aoa);
    const stall = Number(stallThreshold);
    if (!Number.isFinite(value) || !Number.isFinite(stall) || stall <= 0) return null;
    return clamp(value / stall, 0, 1.2);
  }

  function aoaIndicatorPlacement() {
    if (!aoaIndicator || !airspeedTape) return false;
    const airspeedRect = instrumentAnchorRect(airspeedTape);
    if (!airspeedRect) return false;
    const rootRect = root ? root.getBoundingClientRect() : { left: 0, top: 0 };
    const airspeedStyle = window.getComputedStyle(airspeedTape);
    const scaleMatch = String(airspeedStyle.transform || '').match(/^matrix\(([^,]+),/);
    const scale = scaleMatch ? Number(scaleMatch[1]) : 1;
    const width = Math.max(44, Math.round(72 * scale));
    const vsiHeight = Math.max(0, airspeedRect.height - (84 * scale));
    const height = Math.max(76, Math.round(vsiHeight * 0.4) - 20);
    const gap = Math.max(8, Math.round(10 * scale));
    aoaIndicator.style.width = `${width}px`;
    aoaIndicator.style.height = `${height}px`;
    aoaIndicator.style.left = `${Math.round(airspeedRect.right - rootRect.left + gap)}px`;
    aoaIndicator.style.top = `${Math.round(airspeedRect.top - rootRect.top + (42 * scale))}px`;
    return true;
  }

  function updateAoaIndicator(sample) {
    if (!aoaIndicator || !aoaIndicatorSvg) return;
    const aoa = firstFinite(sample && sample.aoa, sample && sample.angle_of_attack_deg);
    const visibleThreshold = aoaProfileNumber('visible_threshold', 0.35);
    const cautionThreshold = aoaProfileNumber('caution_threshold', 0.55);
    const warningThreshold = aoaProfileNumber('warning_threshold', 0.75);
    const stallThreshold = aoaProfileNumber('stall_threshold', 1.0);
    if (!sample || !instrumentEnabled('aoa_indicator') || aoa === null || aoa < visibleThreshold || !aoaIndicatorPlacement()) {
      setElementHidden(aoaIndicator, true);
      return;
    }
    const ratio = normalizedAoaRatio(aoa, stallThreshold);
    if (ratio === null) {
      setElementHidden(aoaIndicator, true);
      return;
    }
    const activeGreen = aoa >= visibleThreshold ? 1 : 0;
    const activeYellow = aoa >= cautionThreshold ? 1 : 0;
    const activeRed = aoa >= warningThreshold ? 1 : 0;
    const clipY = clamp(144 - (ratio * 144), 0, 144);
    aoaIndicatorSvg.innerHTML = `
      <defs>
        <clipPath id="aoaActiveClip"><rect x="0" y="${clipY.toFixed(1)}" width="72" height="${(144 - clipY).toFixed(1)}"></rect></clipPath>
      </defs>
      <g opacity=".28">
        <path class="aoa-symbol is-red" d="M16 8 L36 28 L56 8 M16 26 L36 46 L56 26" fill="none" stroke-width="7"></path>
        <path class="aoa-symbol is-yellow" d="M16 48 L36 68 L56 48 M16 66 L36 86 L56 66" fill="none" stroke-width="7"></path>
        <path class="aoa-symbol is-yellow" d="M14 94 H28 L34 101 M58 94 H44 L38 101" fill="none" stroke-width="6"></path>
        <path class="aoa-symbol is-green" d="M14 111 H27 M45 111 H58 M14 123 H58 M14 135 H58" fill="none" stroke-width="6"></path>
        <circle class="aoa-center" cx="36" cy="111" r="9"></circle>
      </g>
      <g clip-path="url(#aoaActiveClip)">
        ${activeRed ? '<path class="aoa-symbol is-red" d="M16 8 L36 28 L56 8 M16 26 L36 46 L56 26" fill="none" stroke-width="7"></path>' : ''}
        ${activeYellow ? '<path class="aoa-symbol is-yellow" d="M16 48 L36 68 L56 48 M16 66 L36 86 L56 66 M14 94 H28 L34 101 M58 94 H44 L38 101" fill="none" stroke-width="6"></path>' : ''}
        ${activeGreen ? '<path class="aoa-symbol is-green" d="M14 111 H27 M45 111 H58 M14 123 H58 M14 135 H58" fill="none" stroke-width="6"></path><circle class="aoa-center" cx="36" cy="111" r="9"></circle>' : ''}
      </g>`;
    setElementHidden(aoaIndicator, false);
  }

  function altitudeSpeedToY(altitudeFt, currentAltitudeFt, centerY, pxPerFt) {
    return centerY + (currentAltitudeFt - altitudeFt) * pxPerFt;
  }

  function selectedAltitudeBugFt(sample) {
    return firstPositiveFinite(
      sample && sample.altitude_bug_ft,
      sample && sample.selected_altitude_ft,
      sample && sample.sel_alt_ft
    );
  }

  function indicatedAltitudeFt(sample) {
    return firstFinite(
      sample && sample.estimated_indicated_altitude_ft,
      sample && sample.baro_altitude_ft,
      sample && sample.altitude_ft_msl,
      sample && sample.altitude_ft
    );
  }

  function altimeterHpa(sample) {
    const hpa = firstPositiveFinite(sample && sample.altimeter_setting_hpa);
    if (hpa !== null) return hpa;
    const inhg = firstPositiveFinite(sample && sample.altimeter_setting_inhg);
    return inhg === null ? null : inhg * 33.8638866667;
  }

  function altimeterInhg(sample) {
    const inhg = firstPositiveFinite(sample && sample.altimeter_setting_inhg);
    if (inhg !== null) return inhg;
    const hpa = firstPositiveFinite(sample && sample.altimeter_setting_hpa);
    return hpa === null ? null : hpa / 33.8638866667;
  }

  function formatAltimeterSetting(sample) {
    if (altimeterSettingUnit === 'inhg') {
      const inhg = altimeterInhg(sample);
      return inhg === null ? '---- IN' : `${inhg.toFixed(2)} IN`;
    }
    const hpa = altimeterHpa(sample);
    return hpa === null ? '---- HPA' : `${Math.round(hpa)} HPA`;
  }

  function isaDeviationC(sample, altitudeFt, oatC) {
    if (oatC !== null && Number.isFinite(Number(altitudeFt))) {
      const isaC = 15 - 1.98 * (Number(altitudeFt) / 1000);
      return oatC - isaC;
    }
    const provided = firstFinite(sample && sample.isa_deviation_c, sample && sample.isa_dev_c);
    if (provided !== null) return provided;
    return null;
  }

  function decisionAltitudeFt(sample) {
    return firstPositiveFinite(
      sample && sample.decision_altitude_ft,
      sample && sample.da_ft,
      sample && sample.minimums_ft,
      sample && sample.minimum_altitude_ft
    );
  }

  function updateAltimeterTape(sample, dtSec = 1 / 60, snap = false) {
    if (!altimeterStack) return;
    if (!sample || !instrumentEnabled('altimeter')) {
      setElementHidden(altimeterStack, true);
      displayAltitudeFt = null;
      displayVsiFpm = null;
      return;
    }
    const altitudeFt = indicatedAltitudeFt(sample);
    if (altitudeFt === null) {
      setElementHidden(altimeterStack, true);
      displayAltitudeFt = null;
      displayVsiFpm = null;
      return;
    }
    if (snap || displayAltitudeFt === null || !Number.isFinite(displayAltitudeFt) || Math.abs(displayAltitudeFt - altitudeFt) > 1000) {
      displayAltitudeFt = altitudeFt;
    } else {
      const alpha = smoothFactor(ALTIMETER_TAPE_SMOOTHING_RATE, dtSec);
      displayAltitudeFt += (altitudeFt - displayAltitudeFt) * alpha;
    }
    const vsiFpm = firstFinite(sample.vertical_speed_fpm, sample.estimated_vertical_speed_fpm, 0) || 0;
    if (snap || displayVsiFpm === null || !Number.isFinite(displayVsiFpm) || Math.abs(displayVsiFpm - vsiFpm) > 2500) {
      displayVsiFpm = vsiFpm;
    } else {
      const alpha = smoothFactor(VSI_SMOOTHING_RATE, dtSec);
      displayVsiFpm += (vsiFpm - displayVsiFpm) * alpha;
    }

    const bodyHeight = Number(altimeterBody && altimeterBody.clientHeight) || 440;
    const centerY = bodyHeight / 2;
    const pxPerFt = 0.68;
    const visibleMin = Math.floor((displayAltitudeFt - (centerY / pxPerFt) - 100) / 20) * 20;
    const visibleMax = Math.ceil((displayAltitudeFt + (centerY / pxPerFt) + 100) / 20) * 20;
    let scaleHtml = '';
    for (let alt = visibleMin; alt <= visibleMax; alt += 20) {
      const y = altitudeSpeedToY(alt, displayAltitudeFt, centerY, pxPerFt);
      const major = alt % 100 === 0;
      scaleHtml += `<div class="altimeter-tick ${major ? 'is-major' : 'is-minor'}" style="top:${y.toFixed(1)}px"></div>`;
      if (major) {
        scaleHtml += `<div class="altimeter-number" style="top:${y.toFixed(1)}px">${Math.round(alt)}</div>`;
      }
    }

    const bugFt = selectedAltitudeBugFt(sample);
    const bugY = bugFt === null ? null : altitudeSpeedToY(bugFt, displayAltitudeFt, centerY, pxPerFt);
    const bugHtml = bugY !== null && bugY >= -20 && bugY <= bodyHeight + 20
      ? `<svg class="altimeter-bug" style="top:${bugY.toFixed(1)}px" viewBox="-11 -11 22 22" aria-hidden="true">
          <path d="M -10 -6 H -3.4 L 0 -1.5 L 3.4 -6 H 10 V 7 H -10 Z" transform="rotate(90)" fill="rgba(127, 237, 255, .92)" stroke="rgba(255, 255, 255, .66)" stroke-width=".8" stroke-linejoin="round"></path>
        </svg>`
      : '';
    const oatC = firstFinite(sample.oat_c);
    const isaDevC = isaDeviationC(sample, altitudeFt, oatC);
    const daFt = decisionAltitudeFt(sample);

    setElementHidden(altimeterStack, false);
    if (altimeterScale) altimeterScale.innerHTML = scaleHtml;
    if (altimeterBugs) altimeterBugs.innerHTML = bugHtml;
    if (altimeterPointer) altimeterPointer.textContent = String(Math.round(displayAltitudeFt / 20) * 20);
    if (altimeterBugValue) altimeterBugValue.textContent = bugFt === null ? '----' : `${Math.round(bugFt)}FT`;
    if (altimeterSettingValue) altimeterSettingValue.textContent = formatAltimeterSetting(sample);
    if (temperatureBox) {
      const oatText = oatC === null ? '----' : `${Math.round(oatC)}°C`;
      const isaText = isaDevC === null ? '----' : `${isaDevC >= 0 ? '+' : ''}${Math.round(isaDevC)}°C`;
      temperatureBox.innerHTML = `OAT ${escapeHtml(oatText)}<br>ISA ${escapeHtml(isaText)}`;
    }
    if (altimeterDa && altimeterDaValue) {
      if (daFt === null) {
        altimeterDa.style.display = 'none';
      } else {
        altimeterDa.style.display = 'flex';
        altimeterDaValue.textContent = String(Math.round(daFt));
      }
    }
    updateVsiTape(displayVsiFpm);
  }

  function updateVsiTape(vsiFpm) {
    if (!vsiScale || !vsiPointer) return;
    const height = Number(vsiScale.parentElement && vsiScale.parentElement.clientHeight) || 360;
    const centerY = height / 2;
    const maxFpm = 2000;
    const yForVsi = (value) => centerY - clamp(value, -maxFpm, maxFpm) / maxFpm * (height * 0.44);
    const marks = [-2000, -1000, -500, 0, 500, 1000, 2000];
    vsiScale.innerHTML = marks.map((value) => {
      const major = value === -2000 || value === -1000 || value === 0 || value === 1000 || value === 2000;
      const label = Math.abs(value) >= 1000 ? String(Math.abs(value) / 1000) : (value === 0 ? '0' : '.5');
      const y = yForVsi(value);
      return `<div class="vsi-tick ${major ? 'is-major' : 'is-minor'}" style="top:${y.toFixed(1)}px"></div>${major || value === 500 || value === -500 ? `<div class="vsi-number" style="top:${y.toFixed(1)}px">${escapeHtml(label)}</div>` : ''}`;
    }).join('');
    const pointerY = yForVsi(vsiFpm);
    vsiPointer.style.top = `${pointerY.toFixed(1)}px`;
    const roundedFpm = Math.round(vsiFpm / 100) * 100;
    vsiPointer.textContent = roundedFpm > 0 ? `+${roundedFpm}` : String(roundedFpm).replace(/^-0$/, '0');
  }

  function engineProfileInstruments() {
    return Array.isArray(ENGINE_PROFILE && ENGINE_PROFILE.instruments) ? ENGINE_PROFILE.instruments : [];
  }

  function engineValue(sample, instrument) {
    if (!sample || !instrument) return null;
    const field = String(instrument.value_field || instrument.key || '');
    return firstFinite(sample[field]);
  }

  function engineRangePercent(value, min, max) {
    const lo = Number(min);
    const hi = Number(max);
    if (!Number.isFinite(lo) || !Number.isFinite(hi) || hi <= lo) return 0;
    return clamp((Number(value) - lo) / (hi - lo) * 100, 0, 100);
  }

  function enginePointerPercent(value, min, max) {
    return clamp(engineRangePercent(value, min, max), 3, 97);
  }

  function engineSmoothedValue(field, value, dtSec, snap = false) {
    const key = String(field || '');
    if (value === null || value === undefined || value === '') {
      if (key !== '') displayEngineValues.delete(key);
      return null;
    }
    const numeric = Number(value);
    if (key === '' || !Number.isFinite(numeric)) {
      if (key !== '') displayEngineValues.delete(key);
      return numeric;
    }
    const previous = displayEngineValues.get(key);
    if (snap || !Number.isFinite(previous)) {
      displayEngineValues.set(key, numeric);
      return numeric;
    }
    const smoothed = previous + (numeric - previous) * smoothFactor(ENGINE_GAUGE_SMOOTHING_RATE, dtSec);
    displayEngineValues.set(key, smoothed);
    return smoothed;
  }

  function engineFormatValue(value, decimals) {
    if (!Number.isFinite(Number(value))) return '--';
    const places = Math.max(0, Math.min(2, Math.round(Number(decimals) || 0)));
    return Number(value).toFixed(places);
  }

  function engineDisplayValue(sample, instrument, value, decimals) {
    const text = engineFormatValue(value, decimals);
    if (text === '--' || !instrument || String(instrument.kind || '').toLowerCase() !== 'ammeter') return text;
    const numeric = Number(value);
    return numeric > 0 ? `+${text}` : text;
  }

  function engineRangesForInstrument(instrument) {
    if (String(instrument && instrument.key || '').toLowerCase() === 'volts') {
      return [
        { color: 'red', from: 11.5, to: 12.8 },
        { color: 'yellow', from: 12.8, to: 13.2 },
        { color: 'green', from: 13.2, to: 14.6 },
        { color: 'yellow', from: 14.6, to: 15.5 },
        { color: 'red', from: 15.5, to: 16 },
      ];
    }
    return Array.isArray(instrument && instrument.ranges) ? instrument.ranges : [];
  }

  function engineRangeHtml(instrument) {
    const min = Number(instrument && instrument.min);
    const max = Number(instrument && instrument.max);
    const ranges = engineRangesForInstrument(instrument);
    return ranges.map((range) => {
      const from = Number(range && range.from);
      const to = Number(range && range.to);
      if (!Number.isFinite(from) || !Number.isFinite(to) || !Number.isFinite(min) || !Number.isFinite(max) || max <= min) return '';
      const left = engineRangePercent(from, min, max);
      const width = Math.max(0, engineRangePercent(to, min, max) - left);
      const color = String((range && range.color) || 'white').replace(/[^a-z_-]/gi, '').toLowerCase().replace(/_/g, '-');
      return `<span class="engine-bar-fill is-${escapeHtml(color)}" style="left:${left.toFixed(2)}%;width:${width.toFixed(2)}%"></span>`;
    }).join('');
  }

  function engineRangeColorForValue(instrument, value) {
    const numeric = Number(value);
    const ranges = engineRangesForInstrument(instrument);
    if (!Number.isFinite(numeric) || ranges.length === 0) return '';
    let matchedColor = '';
    let matchedSeverity = -1;
    for (let i = 0; i < ranges.length; i += 1) {
      const range = ranges[i];
      const from = Number(range && range.from);
      const to = Number(range && range.to);
      if (!Number.isFinite(from) || !Number.isFinite(to)) continue;
      if (numeric >= from && numeric <= to) {
        const color = String((range && range.color) || '').toLowerCase();
        const severity = color === 'red' ? 3 : (color === 'yellow' || color === 'amber' ? 2 : (color === 'green' ? 1 : 0));
        if (severity > matchedSeverity) {
          matchedSeverity = severity;
          matchedColor = color;
        }
      }
    }
    return matchedColor;
  }

  function engineAlertClassForRangeColor(color) {
    if (color === 'red') return ' is-alert-red';
    if (color === 'yellow' || color === 'amber') return ' is-alert-yellow';
    return '';
  }

  function engineMoreSevereRangeColor(colorA, colorB) {
    const severity = (color) => color === 'red' ? 3 : (color === 'yellow' || color === 'amber' ? 2 : (color === 'green' ? 1 : 0));
    return severity(colorB) > severity(colorA) ? colorB : colorA;
  }

  function engineLabelAlertClass(instrument, value) {
    if (String(instrument && instrument.key || '').toLowerCase() === 'rpm') return '';
    const color = engineRangeColorForValue(instrument, value);
    return engineAlertClassForRangeColor(color);
  }

  function engineBarHtml(sample, instrument, dtSec = 1 / 60, snap = false) {
    const key = String(instrument && instrument.key || '').toLowerCase();
    if (key === 'egt1_f') {
      return engineProbePairHtml(sample, instrument, 'egt2_f', '2', dtSec, snap);
    }
    if (key === 'coolant1_f') {
      return engineProbePairHtml(sample, instrument, 'coolant2_f', '2', dtSec, snap);
    }
    const value = engineValue(sample, instrument);
    const smoothedValue = engineSmoothedValue(instrument && (instrument.value_field || instrument.key), value, dtSec, snap);
    const label = String((instrument && instrument.label) || '').trim();
    const decimals = Number(instrument && instrument.decimals) || 0;
    const pointer = value === null ? 3 : enginePointerPercent(smoothedValue, instrument.min, instrument.max);
    const probe = String((instrument && instrument.probe_label) || '').trim();
    const alertClass = engineLabelAlertClass(instrument, value);
    const ammeter = instrument && String(instrument.kind || '').toLowerCase() === 'ammeter';
    const baseFill = ammeter ? '<span class="engine-bar-fill is-white" style="left:0;width:100%"></span>' : '';
    return `<div class="engine-gauge">
      ${label !== '' ? `<div class="engine-row-head${alertClass}"><span>${escapeHtml(label)}</span><strong class="engine-value">${escapeHtml(engineDisplayValue(sample, instrument, value, decimals))}</strong></div>` : ''}
      <div class="engine-bar${ammeter ? ' is-ammeter' : ''}">
        ${baseFill}${engineRangeHtml(instrument)}
        ${probe !== '' ? `<span class="engine-probe">${escapeHtml(probe)}</span>` : ''}
        <span class="engine-pointer" style="left:${pointer.toFixed(2)}%"></span>
      </div>
    </div>`;
  }

  function engineProbePairHtml(sample, instrument, secondField, secondProbeLabel, dtSec = 1 / 60, snap = false) {
    const value1 = engineValue(sample, instrument);
    const value2 = firstFinite(sample && sample[secondField]);
    const smoothedValue1 = engineSmoothedValue(instrument && (instrument.value_field || instrument.key), value1, dtSec, snap);
    const smoothedValue2 = engineSmoothedValue(secondField, value2, dtSec, snap);
    const label = String((instrument && instrument.label) || '').trim();
    const decimals = Number(instrument && instrument.decimals) || 0;
    const displayValue = firstFinite(
      value1 !== null && value2 !== null ? Math.max(value1, value2) : null,
      value1,
      value2
    );
    const pointer1 = value1 === null ? null : enginePointerPercent(smoothedValue1, instrument.min, instrument.max);
    const pointer2 = value2 === null ? null : enginePointerPercent(smoothedValue2, instrument.min, instrument.max);
    const probe1 = String((instrument && instrument.probe_label) || '1').trim() || '1';
    const alertColor = engineMoreSevereRangeColor(
      engineRangeColorForValue(instrument, value1),
      engineRangeColorForValue(instrument, value2)
    );
    const alertClass = engineAlertClassForRangeColor(alertColor);
    return `<div class="engine-gauge">
      ${label !== '' ? `<div class="engine-row-head${alertClass}"><span>${escapeHtml(label)}</span><strong class="engine-value">${escapeHtml(engineFormatValue(displayValue, decimals))}</strong></div>` : ''}
      <div class="engine-bar is-probe-pair">
        ${engineRangeHtml(instrument)}
        ${pointer1 === null ? '' : `<span class="engine-pointer is-probe-number" style="left:${pointer1.toFixed(2)}%"><span class="engine-pointer-label">${escapeHtml(probe1)}</span></span>`}
        ${pointer2 === null ? '' : `<span class="engine-pointer is-probe-number is-bottom" style="left:${pointer2.toFixed(2)}%"><span class="engine-pointer-label">${escapeHtml(String(secondProbeLabel || '2'))}</span></span>`}
      </div>
    </div>`;
  }

  function engineArcHtml(sample, instrument, dtSec = 1 / 60, snap = false) {
    if (instrument && String(instrument.key || '').toLowerCase() === 'rpm') {
      return engineRpmArcHtml(sample, instrument, dtSec, snap);
    }
    const value = engineValue(sample, instrument);
    const decimals = Number(instrument && instrument.decimals) || 0;
    const pct = value === null ? 0 : engineRangePercent(value, instrument.min, instrument.max);
    const angle = -135 + pct / 100 * 270;
    const ranges = Array.isArray(instrument && instrument.ranges) ? instrument.ranges : [];
    const rangeArcs = ranges.map((range) => {
      const fromPct = engineRangePercent(Number(range && range.from), instrument.min, instrument.max);
      const toPct = engineRangePercent(Number(range && range.to), instrument.min, instrument.max);
      if (toPct <= fromPct) return '';
      const color = String((range && range.color) || 'white').replace(/[^a-z_-]/gi, '').toLowerCase().replace(/_/g, '-');
      return `<path d="${engineArcPath(55, 70, 47, -135 + fromPct / 100 * 270, -135 + toPct / 100 * 270)}" stroke="var(--engine-${escapeHtml(color)}, #fff)" stroke-width="8" fill="none" stroke-linecap="butt"></path>`;
    }).join('');
    const needleRad = degToRad(angle);
    const needleX = 55 + Math.cos(needleRad) * 42;
    const needleY = 70 + Math.sin(needleRad) * 42;
    return `<div class="engine-arc-gauge">
      <svg class="engine-arc-svg" viewBox="0 0 110 88" style="--engine-white:#f8fafc;--engine-green:#23f01f;--engine-yellow:#ffe91a;--engine-red:#ff1f2a;--engine-black:#050505">
        ${rangeArcs}
        <line x1="55" y1="70" x2="${needleX.toFixed(1)}" y2="${needleY.toFixed(1)}" stroke="#f8fafc" stroke-width="7" stroke-linecap="round"></line>
      </svg>
      <div class="engine-arc-value"><span>${escapeHtml(String(instrument.label || ''))}</span><strong>${escapeHtml(engineFormatValue(value, decimals))}</strong></div>
    </div>`;
  }

  function engineRpmArcHtml(sample, instrument, dtSec = 1 / 60, snap = false) {
    const value = engineValue(sample, instrument);
    const decimals = Number(instrument && instrument.decimals) || 0;
    const min = Number(instrument && instrument.min);
    const max = Number(instrument && instrument.max);
    const targetRpm = Number(value);
    if (!Number.isFinite(targetRpm)) {
      displayRpm = null;
    } else if (snap || displayRpm === null || !Number.isFinite(displayRpm)) {
      displayRpm = targetRpm;
    } else {
      displayRpm += (targetRpm - displayRpm) * smoothFactor(RPM_NEEDLE_SMOOTHING_RATE, dtSec);
    }
    const displayRoundedRpm = Number.isFinite(displayRpm)
      ? Math.round(displayRpm / 10) * 10
      : (Number.isFinite(targetRpm) ? Math.round(targetRpm / 10) * 10 : null);
    const pct = Number.isFinite(displayRoundedRpm) ? engineRangePercent(displayRoundedRpm, min, max) : 0;
    const startTopAngle = 225;
    const sweepTopAngle = 225;
    const startAngle = startTopAngle - 90;
    const endAngle = startAngle + sweepTopAngle;
    const needleTopAngle = startTopAngle + pct / 100 * sweepTopAngle;
    const ranges = Array.isArray(instrument && instrument.ranges) ? instrument.ranges : [];
    const cx = 62;
    const cy = 62;
    const r = 50;
    const pivotX = cx;
    const pivotY = cy;
    const valueAlertClass = engineAlertClassForRangeColor(engineRangeColorForValue(instrument, displayRoundedRpm));
    const displayValue = Number.isFinite(displayRoundedRpm) ? String(displayRoundedRpm) : '--';
    const rangeArcs = ranges.map((range) => {
      const fromPct = engineRangePercent(Number(range && range.from), min, max);
      const toPct = engineRangePercent(Number(range && range.to), min, max);
      if (toPct <= fromPct) return '';
      const color = String((range && range.color) || 'white').replace(/[^a-z_-]/gi, '').toLowerCase().replace(/_/g, '-');
      const fromAngle = startAngle + fromPct / 100 * sweepTopAngle;
      const toAngle = startAngle + toPct / 100 * sweepTopAngle;
      return `<path d="${engineArcPath(cx, cy, r, fromAngle, toAngle)}" stroke="url(#rpm-${escapeHtml(color)}-gradient)" stroke-width="11" fill="none" stroke-linecap="butt"></path>`;
    }).join('');
    return `<div class="engine-arc-gauge is-rpm">
      <svg class="engine-arc-svg is-rpm" viewBox="0 0 148 118" aria-hidden="true">
        <defs>
          <linearGradient id="rpm-white-gradient" x1="0%" y1="20%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#ffffff"></stop>
            <stop offset="54%" stop-color="#f8fafc"></stop>
            <stop offset="100%" stop-color="#bfc4c8"></stop>
          </linearGradient>
          <linearGradient id="rpm-green-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#6cff55"></stop>
            <stop offset="40%" stop-color="#10f018"></stop>
            <stop offset="100%" stop-color="#03b914"></stop>
          </linearGradient>
          <linearGradient id="rpm-yellow-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#fff33b"></stop>
            <stop offset="100%" stop-color="#cfc500"></stop>
          </linearGradient>
          <linearGradient id="rpm-red-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#ff3b2f"></stop>
            <stop offset="100%" stop-color="#e00000"></stop>
          </linearGradient>
          <linearGradient id="rpm-needle-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#ffffff"></stop>
            <stop offset="52%" stop-color="#f7f7f7"></stop>
            <stop offset="100%" stop-color="#c6c6c6"></stop>
          </linearGradient>
          <filter id="rpm-subtle-shadow" x="-20%" y="-20%" width="140%" height="140%">
            <feDropShadow dx="0" dy="1" stdDeviation=".7" flood-color="#000000" flood-opacity=".35"></feDropShadow>
          </filter>
        </defs>
        <circle cx="${cx}" cy="${cy}" r="60" fill="none" stroke="rgba(255,255,255,.06)" stroke-width=".7"></circle>
        <circle cx="${cx}" cy="${cy}" r="36" fill="none" stroke="rgba(255,255,255,.08)" stroke-width=".55"></circle>
        <path d="${engineArcPath(cx, cy, r, startAngle, endAngle)}" stroke="#f8fafc" stroke-width="14" fill="none" stroke-linecap="butt" filter="url(#rpm-subtle-shadow)"></path>
        ${rangeArcs}
        <g transform="translate(${pivotX.toFixed(1)} ${pivotY.toFixed(1)}) rotate(${needleTopAngle.toFixed(1)})">
          <path d="M -6 2 Q -5 8 0 10 Q 5 8 6 2 L 1.4 -48 Q 0 -54 -1.4 -48 Z" fill="url(#rpm-needle-gradient)" stroke="rgba(255,255,255,.92)" stroke-width=".35"></path>
        </g>
      </svg>
      <div class="engine-arc-value${valueAlertClass}"><span>${escapeHtml(String(instrument.label || ''))}</span><strong>${escapeHtml(displayValue)}</strong></div>
    </div>`;
  }

  function engineArcPath(cx, cy, r, startDeg, endDeg) {
    const start = degToRad(startDeg);
    const end = degToRad(endDeg);
    const x1 = cx + Math.cos(start) * r;
    const y1 = cy + Math.sin(start) * r;
    const x2 = cx + Math.cos(end) * r;
    const y2 = cy + Math.sin(end) * r;
    const large = Math.abs(endDeg - startDeg) > 180 ? 1 : 0;
    return `M ${x1.toFixed(1)} ${y1.toFixed(1)} A ${r} ${r} 0 ${large} 1 ${x2.toFixed(1)} ${y2.toFixed(1)}`;
  }

  function updateEnginePanel(sample, dtSec = 1 / 60, snap = false) {
    if (!enginePanel) return;
    const instruments = engineProfileInstruments();
    if (!sample || instruments.length === 0 || !instrumentEnabled('engine_instrument_stack')) {
      setElementHidden(enginePanel, true);
      enginePanel.innerHTML = '';
      displayRpm = null;
      displayEngineValues.clear();
      return;
    }
    setElementHidden(enginePanel, false);
    enginePanel.innerHTML = instruments.filter((instrument) => {
      const key = String(instrument && instrument.key || '').toLowerCase();
      return key !== 'coolant2_f' && key !== 'egt2_f';
    }).map((instrument) => {
      return instrument && instrument.kind === 'arc' ? engineArcHtml(sample, instrument, dtSec, snap) : engineBarHtml(sample, instrument, dtSec, snap);
    }).join('');
  }

  function hsiHeadingFromSample(sample) {
    return firstFinite(
      sample && sample.heading_deg_magnetic,
      sample && sample.magnetic_heading_deg,
      sample && sample.heading_deg,
      sample && sample.heading_deg_true,
      sample && sample.true_heading_deg,
      sample && sample.track_deg_true,
      sample && sample.gps_track_deg,
      displayCamera && displayCamera.heading,
      displayCamera && displayCamera.aircraftHeading
    );
  }

  function hsiHeadingBugFromSample(sample) {
    return firstFinite(
      sample && sample.heading_bug_deg,
      sample && sample.selected_heading_deg,
      sample && sample.sel_hdg_deg,
      sample && sample.g3x && sample.g3x.sel_hdg_deg
    );
  }

  function hsiCourseFromSample(sample) {
    return firstFinite(
      sample && sample.nav_course_deg,
      sample && sample.nav_crs_deg,
      sample && sample.selected_course_deg,
      sample && sample.selected_course,
      sample && sample.course_deg,
      sample && sample.crs_deg,
      sample && sample.g3x && sample.g3x.nav_course_deg,
      sample && sample.g3x && sample.g3x.nav_crs_deg,
      sample && sample.g3x && sample.g3x.NavCRS,
      sample && sample.g3x && sample.g3x['Nav Course (deg)'],
      sample && sample.g3x && sample.g3x.CRS,
      sample && sample.g3x_raw && sample.g3x_raw.NavCRS,
      sample && sample.g3x_raw && sample.g3x_raw['Nav Course (deg)'],
      sample && sample.raw_g3x && sample.raw_g3x.NavCRS,
      sample && sample.raw_g3x && sample.raw_g3x['Nav Course (deg)'],
      sample && sample.g3x && sample.g3x.selected_course_deg,
      sample && sample.g3x && sample.g3x.selected_course,
      sample && sample.g3x && sample.g3x.course_deg,
      sample && sample.g3x && sample.g3x.crs_deg
    );
  }

  function hsiNavSourceFromSample(sample) {
    const value = String(
      (sample && (sample.nav_source || sample.nav_identifier || sample.nav_ident)) ||
      (sample && sample.g3x && (sample.g3x.nav_source || sample.g3x.nav_identifier || sample.g3x.nav_ident)) ||
      'NAV2'
    ).trim();
    return value === '' ? 'NAV2' : value.slice(0, 8);
  }

  function hsiRawNavSourceFromSample(sample) {
    return String(
      (sample && sample.nav_source) ||
      (sample && sample.g3x && sample.g3x.nav_source) ||
      ''
    ).trim();
  }

  function hsiNavAnnunciationFromSample(sample) {
    return String(
      (sample && (sample.nav_annunciation || sample.gps_annunciation || sample.gps_mode)) ||
      (sample && sample.g3x && (sample.g3x.nav_annunciation || sample.g3x.gps_annunciation || sample.g3x.gps_mode)) ||
      ''
    ).trim();
  }

  function hsiNavDisplay(sample) {
    const rawSource = hsiRawNavSourceFromSample(sample);
    const fallback = hsiNavSourceFromSample(sample);
    const source = (rawSource || fallback || 'NAV2').toUpperCase().replace(/\s+/g, '');
    const isGps = source.includes('GPS') || source.includes('FMS');
    if (isGps) {
      const annunciation = hsiNavAnnunciationFromSample(sample).toUpperCase();
      const suffix = annunciation === '' ? '' : ` ${annunciation.slice(0, 5)}`;
      return { label: `GPS${suffix}`, isGps: true };
    }
    const navLabel = source.includes('VOR2') || source.includes('NAV2') ? 'NAV2'
      : (source.includes('VOR1') || source.includes('NAV1') ? 'NAV1' : fallback.toUpperCase().slice(0, 8));
    return { label: navLabel, isGps: false };
  }

  function hsiCdiFromSample(sample) {
    return firstFinite(
      sample && sample.horizontal_cdi_deflection,
      sample && sample.hcdi,
      sample && sample.nav_cdi,
      sample && sample.g3x && sample.g3x.hcdi
    );
  }

  function hsiCdiOffsetPxFromSample(sample, courseDeg, navDisplay) {
    const fullScalePx = 64;
    const bearing = hsiNavBearingFromSample(sample);
    if (!navDisplay.isGps && bearing !== null && courseDeg !== null) {
      const courseDelta = normalizeSignedDeg(Number(bearing) - Number(courseDeg));
      return clamp(courseDelta / 10, -1, 1) * fullScalePx;
    }

    const cdi = hsiCdiFromSample(sample);
    if (cdi === null) return 0;

    const cdiValue = Number(cdi);
    if (!Number.isFinite(cdiValue)) return 0;

    const fullScaleFt = firstFinite(
      sample && sample.hcdi_full_scale_ft,
      sample && sample.g3x && sample.g3x.hcdi_full_scale_ft
    );
    if (fullScaleFt !== null && Number(fullScaleFt) > 0 && Math.abs(cdiValue) > 1) {
      return clamp(cdiValue / Number(fullScaleFt), -1, 1) * fullScalePx;
    }

    const normalizedCdi = Math.abs(cdiValue) > 1 ? cdiValue / 2 : cdiValue;
    return clamp(normalizedCdi, -1, 1) * fullScalePx;
  }

  function hsiToFromFromSample(sample, courseDeg) {
    const explicit = String(
      (sample && (sample.nav_to_from || sample.to_from || sample.nav_flag)) ||
      (sample && sample.g3x && (sample.g3x.nav_to_from || sample.g3x.to_from || sample.g3x.nav_flag)) ||
      ''
    ).toUpperCase();
    if (explicit.includes('FROM') || explicit === 'FR') return 'FROM';
    if (explicit.includes('TO')) return 'TO';
    const bearing = firstFinite(sample && sample.nav_bearing_deg, sample && sample.g3x && sample.g3x.nav_bearing_deg);
    if (bearing === null || courseDeg === null) return 'TO';
    return Math.abs(normalizeSignedDeg(Number(bearing) - Number(courseDeg))) <= 90 ? 'TO' : 'FROM';
  }

  function hsiNavBearingFromSample(sample) {
    return firstFinite(
      sample && sample.nav_bearing_deg,
      sample && sample.nav_brg_deg,
      sample && sample.bearing_deg,
      sample && sample.g3x && sample.g3x.nav_bearing_deg,
      sample && sample.g3x && sample.g3x.nav_brg_deg,
      sample && sample.g3x && sample.g3x.NavBrg,
      sample && sample.g3x && sample.g3x['Nav Bearing (deg)'],
      sample && sample.g3x_raw && sample.g3x_raw.NavBrg,
      sample && sample.g3x_raw && sample.g3x_raw['Nav Bearing (deg)'],
      sample && sample.raw_g3x && sample.raw_g3x.NavBrg,
      sample && sample.raw_g3x && sample.raw_g3x['Nav Bearing (deg)']
    );
  }

  function hsiRmiBearingHtml(bearingDeg, headingDeg, innerR, outerR) {
    if (bearingDeg === null) return '';
    const rotation = normalizeSignedDeg(Number(bearingDeg) - Number(headingDeg));
    const tickBoundary = outerR - 12;
    const topOuter = -tickBoundary;
    const topInner = -innerR;
    const bottomInner = innerR;
    const bottomOuter = tickBoundary;
    return `
      <g transform="rotate(${rotation.toFixed(2)})">
        <line class="hsi-rmi-bearing" x1="0" y1="${topInner.toFixed(1)}" x2="0" y2="${topOuter.toFixed(1)}"></line>
        <path class="hsi-rmi-bearing-arrow" d="M -9 ${(topOuter + 15).toFixed(1)} L 0 ${topOuter.toFixed(1)} L 9 ${(topOuter + 15).toFixed(1)}"></path>
        <line class="hsi-rmi-bearing" x1="0" y1="${bottomInner.toFixed(1)}" x2="0" y2="${bottomOuter.toFixed(1)}"></line>
      </g>`;
  }

  function hsiTrackMagneticFromSample(sample) {
    const explicitMagnetic = firstFinite(
      sample && sample.track_deg_magnetic,
      sample && sample.magnetic_track_deg,
      sample && sample.g3x && sample.g3x.track_deg_magnetic,
      sample && sample.g3x && sample.g3x.magnetic_track_deg
    );
    if (explicitMagnetic !== null) return normalizeDeg(explicitMagnetic);

    const trackTrue = firstFinite(
      sample && sample.track_deg_true,
      sample && sample.gps_track_deg,
      sample && sample.g3x && sample.g3x.track_deg_true,
      sample && sample.g3x && sample.g3x.gps_track_deg
    );
    if (trackTrue !== null) {
      const variation = firstFinite(sample && sample.magnetic_variation_deg, sample && sample.g3x && sample.g3x.magnetic_variation_deg);
      return normalizeDeg(Number(trackTrue) + (variation === null ? 0 : Number(variation)));
    }

    const legacyTrack = firstFinite(sample && sample.track_deg, sample && sample.g3x && sample.g3x.track_deg);
    return legacyTrack === null ? null : normalizeDeg(legacyTrack);
  }

  function windSpeedFromSample(sample) {
    return firstFinite(
      sample && sample.wind_speed_kt,
      sample && sample.g3x && sample.g3x.wind_speed_kt
    );
  }

  function windDirectionFromGarminSample(sample) {
    const direction = firstFinite(
      sample && sample.wind_direction_deg,
      sample && sample.g3x && sample.g3x.wind_dir_deg,
      sample && sample.g3x && sample.g3x.wind_direction_deg
    );
    return direction === null ? null : normalizeDeg(direction);
  }

  function aircraftTrueHeadingFromSample(sample) {
    const trueHeading = firstFinite(sample && sample.heading_deg_true, sample && sample.true_heading_deg);
    if (trueHeading !== null) return normalizeDeg(trueHeading);
    const magnetic = firstFinite(sample && sample.heading_deg_magnetic, sample && sample.magnetic_heading_deg, sample && sample.heading_deg);
    if (magnetic === null) return null;
    const variation = firstFinite(sample && sample.magnetic_variation_deg, sample && sample.g3x && sample.g3x.magnetic_variation_deg);
    return normalizeDeg(Number(magnetic) + (variation === null ? 0 : Number(variation)));
  }

  function fpvVectorFromSample(sample, aircraftPitchDeg, referenceHeadingDeg = null) {
    const velE = firstFinite(sample && sample.velocity_e_mps, sample && sample.g3x && sample.g3x.velocity_e_mps);
    const velN = firstFinite(sample && sample.velocity_n_mps, sample && sample.g3x && sample.g3x.velocity_n_mps);
    let velU = firstFinite(sample && sample.velocity_u_mps, sample && sample.g3x && sample.g3x.velocity_u_mps);
    const groundspeedKt = firstFinite(sample && sample.ground_speed_kt, sample && sample.groundspeed_kt, sample && sample.g3x && sample.g3x.ground_speed_kt);
    const horizontalMps = velE !== null && velN !== null
      ? Math.hypot(Number(velE), Number(velN))
      : (groundspeedKt === null ? null : Number(groundspeedKt) * 0.514444);
    const horizontalSpeedOk = (Number.isFinite(horizontalMps) && horizontalMps >= 2.5) || (groundspeedKt !== null && Number(groundspeedKt) >= 5);
    if (!horizontalSpeedOk) return null;
    const trackTrue = firstFinite(sample && sample.track_deg_true, sample && sample.gps_track_deg, sample && sample.g3x && sample.g3x.track_deg_true, sample && sample.g3x && sample.g3x.gps_track_deg);
    const velocityHeadingTrueDeg = velE !== null && velN !== null ? normalizeDeg(Math.atan2(Number(velE), Number(velN)) * 180 / Math.PI) : null;
    const fpvHeadingTrueDeg = velocityHeadingTrueDeg !== null ? velocityHeadingTrueDeg : (trackTrue !== null ? normalizeDeg(trackTrue) : null);
    if (fpvHeadingTrueDeg === null) return null;
    if (!Number.isFinite(horizontalMps) || horizontalMps < 2.5) return null;
    if (velU === null) {
      const vsiFpm = firstFinite(sample && sample.vertical_speed_fpm, sample && sample.estimated_vertical_speed_fpm);
      velU = vsiFpm === null ? 0 : Number(vsiFpm) * 0.00508;
    }
    const fpvPitchDeg = Math.atan2(Number(velU), Number(horizontalMps)) * 180 / Math.PI;
    const variation = firstFinite(sample && sample.magnetic_variation_deg, sample && sample.g3x && sample.g3x.magnetic_variation_deg);
    const referenceHeading = firstFinite(referenceHeadingDeg);
    const screenHeadingDeg = firstFinite(referenceHeading, syntheticVisionHeadingFromSample(sample));
    let aircraftHeadingDeg = screenHeadingDeg === null ? null : normalizeDeg(screenHeadingDeg);
    let fpvHeadingDeg = fpvHeadingTrueDeg;
    let headingReference = referenceHeading !== null ? 'CAMERA_CALIBRATED' : 'SCREEN';
    if (aircraftHeadingDeg === null) {
      aircraftHeadingDeg = aircraftTrueHeadingFromSample(sample);
      headingReference = 'TRUE';
    }
    if (aircraftHeadingDeg === null) return null;
    const headingDeltaDeg = normalizeSignedDeg(fpvHeadingDeg - aircraftHeadingDeg);
    return {
      headingDeltaDeg,
      displayHeadingDeltaDeg: clamp(headingDeltaDeg, -22, 22),
      pitchDeltaDeg: fpvPitchDeg - Number(aircraftPitchDeg || 0),
      fpvHeadingDeg,
      fpvHeadingTrueDeg,
      velocityHeadingTrueDeg,
      trackTrueDeg: trackTrue === null ? null : normalizeDeg(trackTrue),
      fpvPitchDeg,
      aircraftHeadingDeg,
      magneticVariationDeg: variation,
      headingReference,
    };
  }

  function hsiWindIndicatorHtml(sample, headingDeg) {
    if (!instrumentEnabled('wind_indicator')) return '';
    const speed = windSpeedFromSample(sample);
    if (speed === null) return '';
    const x = -2;
    const y = 91;
    const width = 64;
    const height = Number(speed) < 3 ? 34 : 66;
    const centerX = x + width / 2;
    if (Number(speed) < 3) {
      return `
      <rect class="hsi-label-box" x="${x}" y="${y}" width="${width}" height="${height}" rx="7"></rect>
      <text class="hsi-wind-calm" x="${centerX}" y="${y + 22}" text-anchor="middle">Calm</text>`;
    }
    const direction = windDirectionFromGarminSample(sample);
    if (direction === null) return '';
    const roundedDirection = ((Math.round(direction / 10) * 10) % 360 + 360) % 360;
    const directionText = `${String(roundedDirection === 0 ? 360 : roundedDirection).padStart(3, '0')}°`;
    const arrowRotation = normalizeSignedDeg(direction - headingDeg + 180);
    const speedText = `${Math.round(Number(speed))} KT`;
    return `
      <rect class="hsi-label-box" x="${x}" y="${y}" width="${width}" height="${height}" rx="7"></rect>
      <g transform="translate(${centerX} ${y + 17}) rotate(${arrowRotation.toFixed(1)})">
        <path class="hsi-wind-arrow" d="M 0 -11 L 4.3 -1 L 1.4 -1 L 1.4 10 L -1.4 10 L -1.4 -1 L -4.3 -1 Z"></path>
      </g>
      <text class="hsi-wind-text" x="${centerX}" y="${y + 42}" text-anchor="middle">${directionText}</text>
      <text class="hsi-wind-text" x="${centerX}" y="${y + 58}" text-anchor="middle">${escapeHtml(speedText)}</text>`;
  }

  function hsiLabelForDegrees(deg) {
    const normalized = ((Math.round(deg / 30) * 30) % 360 + 360) % 360;
    if (normalized === 0) return 'N';
    if (normalized === 90) return 'E';
    if (normalized === 180) return 'S';
    if (normalized === 270) return 'W';
    return String(normalized / 10);
  }

  function hsiHeadingRateDegPerSec(sample) {
    if (!payload || !Array.isArray(payload.samples) || payload.samples.length < 2 || !sample) return null;
    const samples = payload.samples;
    const firstT = Number(samples[0] && samples[0].t);
    const lastT = Number(samples[samples.length - 1] && samples[samples.length - 1].t);
    const t = clamp(firstFinite(sample.t, activeT), firstT, lastT);
    let beforeT = Math.max(firstT, t - 1.0);
    let afterT = Math.min(lastT, t);
    if (afterT - beforeT < 0.25) {
      beforeT = t;
      afterT = Math.min(lastT, t + 1.0);
    }
    const span = afterT - beforeT;
    if (!Number.isFinite(span) || span < 0.25) return null;
    const beforeHeading = hsiHeadingFromSample(sampleAt(beforeT));
    const afterHeading = hsiHeadingFromSample(sampleAt(afterT));
    if (beforeHeading === null || afterHeading === null) return null;
    return normalizeSignedDeg(Number(afterHeading) - Number(beforeHeading)) / span;
  }

  function hsiTrendPoint(deg, radius) {
    const rad = degToRad(deg);
    return {
      x: Math.sin(rad) * radius,
      y: -Math.cos(rad) * radius,
    };
  }

  function hsiHeadingTrendHtml(sample, radius) {
    const rateDegPerSec = hsiHeadingRateDegPerSec(sample);
    if (rateDegPerSec === null || Math.abs(rateDegPerSec) < 0.15) return '';
    const projectedDeg = clamp(rateDegPerSec * 6.0, -30, 30);
    if (Math.abs(projectedDeg) < 1) return '';
    const start = hsiTrendPoint(0, radius);
    const end = hsiTrendPoint(projectedDeg, radius);
    const large = Math.abs(projectedDeg) > 180 ? 1 : 0;
    const sweep = projectedDeg >= 0 ? 1 : 0;
    const arrowAngle = projectedDeg >= 0 ? projectedDeg : projectedDeg + 180;
    const arrowHtml = Math.abs(rateDegPerSec) > 4.0
      ? `<polygon class="hsi-heading-trend-arrow" points="0,-5 9,0 0,5" transform="translate(${end.x.toFixed(1)} ${end.y.toFixed(1)}) rotate(${arrowAngle.toFixed(1)})"></polygon>`
      : '';
    return `<path class="hsi-heading-trend" d="M ${start.x.toFixed(1)} ${start.y.toFixed(1)} A ${radius.toFixed(1)} ${radius.toFixed(1)} 0 ${large} ${sweep} ${end.x.toFixed(1)} ${end.y.toFixed(1)}"></path>${arrowHtml}`;
  }

  function hsiTurnRateMarksHtml(radius) {
    return [-18, -9, 9, 18].map((deg) => {
      const half = Math.abs(deg) === 9;
      const inner = hsiTrendPoint(deg, radius + 2);
      const outer = hsiTrendPoint(deg, radius + 14);
      return `<line class="hsi-turn-rate-mark ${half ? 'is-half' : ''}" x1="${inner.x.toFixed(1)}" y1="${inner.y.toFixed(1)}" x2="${outer.x.toFixed(1)}" y2="${outer.y.toFixed(1)}"></line>`;
    }).join('');
  }

  function updateHsiOverlay(sample, dtSec = 1 / 60, snap = false) {
    if (!hsiOverlay) return;
    if (!instrumentEnabled('hsi')) {
      setElementHidden(hsiOverlay, true);
      hsiOverlaySignature = '';
      displayHsiHeadingDeg = null;
      displayHsiHeadingBugDeg = null;
      displayHsiRmiBearingDeg = null;
      displayHsiCdiOffsetPx = null;
      return;
    }
    const heading = hsiHeadingFromSample(sample);
    if (heading === null) {
      setElementHidden(hsiOverlay, true);
      hsiOverlaySignature = '';
      displayHsiHeadingDeg = null;
      displayHsiHeadingBugDeg = null;
      displayHsiRmiBearingDeg = null;
      displayHsiCdiOffsetPx = null;
      return;
    }
    const headingDeg = normalizeDeg(heading);
    const bug = hsiHeadingBugFromSample(sample);
    const bugDeg = bug === null ? null : normalizeDeg(bug);
    const course = hsiCourseFromSample(sample);
    const courseDeg = course === null ? null : normalizeDeg(course);
    const trackMag = hsiTrackMagneticFromSample(sample);
    const navDisplay = hsiNavDisplay(sample);
    const cdiOffsetTarget = hsiCdiOffsetPxFromSample(sample, courseDeg, navDisplay);
    const navLabel = navDisplay.label;
    const navColorClass = navDisplay.isGps ? 'is-gps' : '';
    const crsValueClass = navDisplay.isGps ? 'hsi-magenta' : 'hsi-green';
    const toFrom = hsiToFromFromSample(sample, courseDeg);
    const alpha = snap ? 1 : smoothFactor(16, dtSec);
    const cdiAlpha = snap ? 1 : smoothFactor(8, dtSec);
    displayHsiHeadingDeg = (snap || displayHsiHeadingDeg === null || !Number.isFinite(displayHsiHeadingDeg))
      ? headingDeg
      : lerpAngleDeg(displayHsiHeadingDeg, headingDeg, alpha);
    displayHsiHeadingBugDeg = bugDeg === null
      ? null
      : ((snap || displayHsiHeadingBugDeg === null || !Number.isFinite(displayHsiHeadingBugDeg))
        ? bugDeg
        : lerpAngleDeg(displayHsiHeadingBugDeg, bugDeg, alpha));
    const rmiBearing = hsiNavBearingFromSample(sample);
    displayHsiRmiBearingDeg = rmiBearing === null
      ? null
      : ((snap || displayHsiRmiBearingDeg === null || !Number.isFinite(displayHsiRmiBearingDeg))
        ? normalizeDeg(rmiBearing)
        : lerpAngleDeg(displayHsiRmiBearingDeg, normalizeDeg(rmiBearing), alpha));
    displayHsiCdiOffsetPx = (snap || displayHsiCdiOffsetPx === null || !Number.isFinite(displayHsiCdiOffsetPx))
      ? cdiOffsetTarget
      : displayHsiCdiOffsetPx + (cdiOffsetTarget - displayHsiCdiOffsetPx) * cdiAlpha;

    const cx = 195;
    const cy = 176;
    const r = 126;
    const innerR = 72;
    const turnMarkRadius = r - 1;
    const trendHtml = hsiHeadingTrendHtml(sample, turnMarkRadius);
    const turnRateMarksHtml = hsiTurnRateMarksHtml(turnMarkRadius);
    const rmiBearingHtml = hsiRmiBearingHtml(displayHsiRmiBearingDeg, displayHsiHeadingDeg, innerR, r);
    const headingText = String(Math.round(displayHsiHeadingDeg)).padStart(3, '0') + '°';
    const hdgBugText = displayHsiHeadingBugDeg === null ? '---' : `${String(Math.round(displayHsiHeadingBugDeg)).padStart(3, '0')}°`;
    const crsText = courseDeg === null ? '---' : `${String(Math.round(courseDeg)).padStart(3, '0')}°`;
    const windHtml = hsiWindIndicatorHtml(sample, displayHsiHeadingDeg);
    const bugWidth = 2 * (r - 12) * Math.sin(degToRad(5));
    const bugHalf = bugWidth / 2;
    const bugNotchHalf = Math.min(4.5, bugHalf * .34);
    const ticks = [];
    for (let deg = 0; deg < 360; deg += 5) {
      const rad = degToRad(deg);
      const major = deg % 30 === 0;
      const ten = deg % 10 === 0;
      const inner = major ? r - 17 : (ten ? r - 12 : r - 7);
      ticks.push(`<line class="${major || ten ? 'hsi-tick' : 'hsi-minor-tick'}" x1="${(Math.sin(rad) * inner).toFixed(1)}" y1="${(-Math.cos(rad) * inner).toFixed(1)}" x2="${(Math.sin(rad) * r).toFixed(1)}" y2="${(-Math.cos(rad) * r).toFixed(1)}"></line>`);
      if (major) {
        const labelR = r - 33;
        const label = hsiLabelForDegrees(deg);
        const x = Math.sin(rad) * labelR;
        const y = -Math.cos(rad) * labelR;
        ticks.push(`<text class="hsi-rose-label" x="${x.toFixed(1)}" y="${(y + 3).toFixed(1)}" text-anchor="middle" transform="rotate(${deg} ${x.toFixed(1)} ${y.toFixed(1)})">${label}</text>`);
      }
    }
    const bugHtml = displayHsiHeadingBugDeg === null ? '' : (() => {
      return `<g transform="rotate(${displayHsiHeadingBugDeg.toFixed(2)}) translate(0 ${(-r + 7).toFixed(1)})">
        <path class="hsi-heading-bug" d="M ${(-bugHalf).toFixed(1)} -6 H ${(-bugNotchHalf).toFixed(1)} L 0 -1.5 L ${bugNotchHalf.toFixed(1)} -6 H ${bugHalf.toFixed(1)} V 7 H ${(-bugHalf).toFixed(1)} Z"></path>
      </g>`;
    })();
    const trackHtml = trackMag === null ? '' : (() => {
      return `<g transform="rotate(${trackMag.toFixed(2)}) translate(0 ${(-r + 8).toFixed(1)})">
        <polygon class="hsi-track-diamond" points="0,-8 4.5,0 0,8 -4.5,0"></polygon>
      </g>`;
    })();
    const courseRotation = courseDeg === null ? 0 : normalizeSignedDeg(courseDeg - displayHsiHeadingDeg);
    const navTextHtml = navDisplay.isGps
      ? (() => {
        const parts = navLabel.split(/\s+/, 2);
        const mode = parts.length > 1 ? parts[1] : '';
        return `<text class="hsi-nav-text is-gps" x="-76" y="-28" font-size="17">${escapeHtml(parts[0] || 'GPS')}</text>${mode ? `<text class="hsi-nav-text is-gps" x="28" y="-28" font-size="17">${escapeHtml(mode)}</text>` : ''}`;
      })()
      : `<text class="hsi-nav-text" x="-76" y="-28" font-size="17">${escapeHtml(navLabel)}</text>`;
    const courseHtml = courseDeg === null ? '' : `
        <g transform="rotate(${courseRotation.toFixed(2)})">
          ${[-64, -32, 32, 64].map((x) => `<circle class="hsi-cdi-dot" cx="${x}" cy="0" r="4.2"></circle>`).join('')}
          <line class="hsi-nav ${navColorClass}" x1="0" y1="${(r - 12).toFixed(1)}" x2="0" y2="54"></line>
          <line class="hsi-nav ${navColorClass}" x1="0" y1="-54" x2="0" y2="${(-r + 36).toFixed(1)}"></line>
          <polygon class="hsi-nav-arrow ${navColorClass}" points="0,${(-r + 24).toFixed(1)} -7,${(-r + 38).toFixed(1)} 7,${(-r + 38).toFixed(1)}"></polygon>
          <g transform="translate(${displayHsiCdiOffsetPx.toFixed(1)} 0)">
            <line class="hsi-cdi-course ${navColorClass}" x1="0" y1="-48" x2="0" y2="48"></line>
          </g>
          ${toFrom === 'FROM'
            ? '<polygon class="hsi-to-from-flag" points="-5,54 5,54 0,65"></polygon>'
            : '<polygon class="hsi-to-from-flag" points="-5,-54 5,-54 0,-65"></polygon>'}
        </g>
        ${navTextHtml}`;
    const signature = [
      Math.round(displayHsiHeadingDeg * 10),
      displayHsiHeadingBugDeg === null ? 'x' : Math.round(displayHsiHeadingBugDeg * 10),
      trackMag === null ? 't' : Math.round(trackMag * 10),
      courseDeg === null ? 'c' : Math.round(courseDeg * 10),
      courseDeg === null ? 'cr' : Math.round(courseRotation * 10),
      Math.round(displayHsiCdiOffsetPx),
      navLabel,
      headingText,
      hdgBugText,
      crsText,
      windHtml,
      navColorClass,
      crsValueClass,
      toFrom,
      navTextHtml,
      trendHtml,
      turnRateMarksHtml,
      rmiBearingHtml,
    ].join('|');
    if (signature === hsiOverlaySignature) {
      setElementHidden(hsiOverlay, false);
      return;
    }
    hsiOverlaySignature = signature;
    hsiOverlay.innerHTML = `
      <rect class="hsi-label-box" x="166" y="7" width="58" height="31" rx="7"></rect>
      <text class="hsi-heading-value" x="195" y="29" text-anchor="middle">${headingText}</text>
      <rect class="hsi-label-box" x="-2" y="50" width="96" height="34" rx="7"></rect>
      <text class="hsi-heading-text" x="11" y="74">HDG <tspan class="hsi-cyan">${hdgBugText}</tspan></text>
      ${windHtml}
      <rect class="hsi-label-box" x="294" y="50" width="96" height="34" rx="7"></rect>
      <text class="hsi-crs-text" x="307" y="74">CRS <tspan class="${crsValueClass}">${crsText}</tspan></text>
      <g transform="translate(${cx} ${cy})">
        <circle class="hsi-card-fill" cx="0" cy="0" r="${r}"></circle>
        <circle class="hsi-rose-line" cx="0" cy="0" r="${innerR}"></circle>
        <g transform="rotate(${(-displayHsiHeadingDeg).toFixed(2)})">
          ${ticks.join('')}
          ${bugHtml}
          ${trackHtml}
        </g>
        ${turnRateMarksHtml}
        ${trendHtml}
        <polygon class="hsi-top-pointer" points="0,${(-r + 4).toFixed(1)} -5.8,${(-r - 8.2).toFixed(1)} 5.8,${(-r - 8.2).toFixed(1)}"></polygon>
        <line class="hsi-course-line" x1="0" y1="${(-r - 12).toFixed(1)}" x2="0" y2="${(-innerR + 8).toFixed(1)}" stroke-dasharray="9 9"></line>
        ${rmiBearingHtml}
        ${courseHtml}
        <g transform="scale(.60)">
          <path d="${AIRCRAFT_SILHOUETTE_PATH}" fill="none" stroke="rgba(255,255,255,.96)" stroke-width="2.2" stroke-linejoin="round"></path>
        </g>
      </g>
    `;
    setElementHidden(hsiOverlay, false);
  }

  function instrumentViewFromSample(sample) {
    if (!sample) return null;
    return {
      mode: cameraMode || 'synthetic_vision',
      heading: firstFinite(
        displayCamera && displayCamera.heading,
        displayCamera && displayCamera.aircraftHeading,
        hsiHeadingFromSample(sample),
        0
      ) || 0,
      pitch: aircraftPitchFromSample(sample),
      roll: aircraftRollFromSample(sample),
    };
  }

  function updateHorizonLine(view) {
    if (!horizonLine) return;
    if (!view) view = instrumentViewFromSample(sampleAt(activeT));
    const mode = view && view.mode ? view.mode : cameraMode;
    if (!view || !instrumentEnabled('horizon_bar')) {
      setElementHidden(horizonLine, true);
      return;
    }
    const container = cesiumViewer && cesiumViewer.container ? cesiumViewer.container : document.getElementById('cesiumReplay');
    const width = Number(container && container.clientWidth);
    const height = Number(container && container.clientHeight);
    if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) {
      setElementHidden(horizonLine, true);
      return;
    }
    const dbg = currentCameraDebug || {};
    const pitchDeg = firstFinite(view.pitch, dbg.uncalibratedPitchDeg, dbg.pitchDegUsed, 0) || 0;
    const rollDeg = firstFinite(view.roll, dbg.uncalibratedRollDeg, dbg.rollDegUsed, 0) || 0;
    const verticalFovDeg = Math.max(1, firstFinite(dbg.activeVerticalFovDeg, dbg.verticalFovDeg, SYNTHETIC_VISION_DEFAULTS.verticalFovFallbackDeg) || SYNTHETIC_VISION_DEFAULTS.verticalFovFallbackDeg);
    const halfHeight = height / 2;
    const pitchOffsetPx = Math.tan(degToRad(pitchDeg)) / Math.tan(degToRad(verticalFovDeg) / 2) * halfHeight;
    const horizonOffsetPx = cameraCalibration ? Number(cameraCalibration.horizonBarOffsetPx || 0) : 0;
    const referenceY = clamp(height * 0.66 + (cameraCalibration ? Number(cameraCalibration.attitudeReferenceOffsetPx || 0) : 0), 90, height - 90);
    const yellowReferenceY = referenceY + (cameraCalibration ? Number(cameraCalibration.yellowPitchReferenceOffsetPx || 0) : 0);
    const y = clamp(yellowReferenceY + pitchOffsetPx + horizonOffsetPx, -height, height * 2);
    setElementHidden(horizonLine, false);
    horizonLine.style.top = `${y}px`;
    horizonLine.style.transform = `translate(-50%, -50%) rotate(${-rollDeg}deg)`;
  }

  function updateAttitudeIndicator(view, sample, dtSec = 1 / 60, snap = false) {
    if (!attitudeOverlay) return;
    if (!view) view = instrumentViewFromSample(sample);
    const mode = view && view.mode ? view.mode : cameraMode;
    if (!view || !instrumentEnabled('attitude_indicator')) {
      setElementHidden(attitudeOverlay, true);
      attitudeOverlay.innerHTML = '';
      displayAttitudeYellowReferenceX = null;
      displayFpvHeadingDeltaDeg = null;
      displayFpvPitchDeltaDeg = null;
      displayFpvX = null;
      displayFpvY = null;
      displayFdRollCommandDeg = null;
      displayFdPitchCommandDeg = null;
      lastValidFpvVector = null;
      lastValidFpvReplayT = null;
      return;
    }
    const container = cesiumViewer && cesiumViewer.container ? cesiumViewer.container : document.getElementById('cesiumReplay');
    const width = Number(container && container.clientWidth);
    const height = Number(container && container.clientHeight);
    if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) {
      setElementHidden(attitudeOverlay, true);
      attitudeOverlay.innerHTML = '';
      displayAttitudeYellowReferenceX = null;
      displayFpvHeadingDeltaDeg = null;
      displayFpvPitchDeltaDeg = null;
      displayFpvX = null;
      displayFpvY = null;
      displayFdRollCommandDeg = null;
      displayFdPitchCommandDeg = null;
      lastValidFpvVector = null;
      lastValidFpvReplayT = null;
      return;
    }
    const dbg = currentCameraDebug || {};
    const pitchDeg = firstFinite(view.pitch, dbg.uncalibratedPitchDeg, dbg.pitchDegUsed, 0) || 0;
    const rollDeg = firstFinite(view.roll, dbg.uncalibratedRollDeg, dbg.rollDegUsed, 0) || 0;
    const verticalFovDeg = Math.max(1, firstFinite(dbg.activeVerticalFovDeg, dbg.verticalFovDeg, SYNTHETIC_VISION_DEFAULTS.verticalFovFallbackDeg) || SYNTHETIC_VISION_DEFAULTS.verticalFovFallbackDeg);
    const halfHeight = height / 2;
    const horizonOffsetPx = cameraCalibration ? Number(cameraCalibration.horizonBarOffsetPx || 0) : 0;
    const pitchOffsetPx = Math.tan(degToRad(pitchDeg)) / Math.tan(degToRad(verticalFovDeg) / 2) * halfHeight;
    const referenceY = clamp(height * 0.66 + (cameraCalibration ? Number(cameraCalibration.attitudeReferenceOffsetPx || 0) : 0), 90, height - 90);
    const yellowReferenceY = referenceY + (cameraCalibration ? Number(cameraCalibration.yellowPitchReferenceOffsetPx || 0) : 0);
    const horizonY = clamp(yellowReferenceY + pitchOffsetPx + horizonOffsetPx, -height, height * 2);
    const rootRect = root ? root.getBoundingClientRect() : { left: 0, top: 0 };
    const airspeedRect = instrumentAnchorRect(airspeedTape);
    const altimeterRect = instrumentAnchorRect(altimeterStack);
    const airspeedWidth = airspeedRect ? airspeedRect.width : 118;
    const tapeMargin = airspeedWidth * 0.8;
    const arcLeft = airspeedRect ? airspeedRect.right - rootRect.left + tapeMargin : width * 0.23;
    const arcRight = altimeterRect ? altimeterRect.left - rootRect.left - tapeMargin : width * 0.77;
    const arcSpan = Math.max(220, arcRight - arcLeft);
    const centerX = Number.isFinite(arcLeft) && Number.isFinite(arcRight) && arcRight > arcLeft
      ? (arcLeft + arcRight) / 2
      : width / 2;
    if (root) {
      root.style.setProperty('--attitude-center-x', `${centerX.toFixed(1)}px`);
    }
    const attitudeRollArcScale = 0.6;
    const attitudePitchMarkScale = 0.5;
    const attitudeYellowReferenceScale = 0.5;
    const pitchLadderScaleFactor = cameraCalibration ? Number(cameraCalibration.pitchLadderScale || 1) : 1;
    const pitchPx = (deg) => -Math.tan(degToRad(deg)) / Math.tan(degToRad(verticalFovDeg) / 2) * halfHeight * pitchLadderScaleFactor;
    const pitchMarks = [-15, -10, -5, 5, 10, 15].map((deg) => {
      const y = pitchPx(deg);
      const major = Math.abs(deg) % 10 === 0;
      const half = (major ? 76 : 45) * attitudePitchMarkScale;
      const labelOffset = 22 * attitudePitchMarkScale;
      const fontSize = (major ? 23 : 20) * attitudePitchMarkScale;
      const label = Math.abs(deg);
      const text = major || Math.abs(deg) === 5
        ? `<text x="${-(half + labelOffset)}" y="${(y + 4).toFixed(1)}" font-size="${fontSize.toFixed(1)}" text-anchor="middle">${label}</text><text x="${(half + labelOffset)}" y="${(y + 4).toFixed(1)}" font-size="${fontSize.toFixed(1)}" text-anchor="middle">${label}</text>`
        : '';
      return `<line class="attitude-white" x1="${-half}" y1="${y.toFixed(1)}" x2="${half}" y2="${y.toFixed(1)}"></line>${text}`;
    }).join('');
    const tapeTopY = airspeedRect ? Math.max(8, airspeedRect.top - rootRect.top) : 72;
    const arcRadius = clamp(arcSpan / (2 * Math.sin(degToRad(60))), 170, 360);
    const arcCenterY = tapeTopY + (arcRadius * attitudeRollArcScale);
    const arcPoints = [];
    for (let bank = -60; bank <= 60; bank += 4) {
      arcPoints.push(`${(Math.sin(degToRad(bank)) * arcRadius).toFixed(1)},${(-Math.cos(degToRad(bank)) * arcRadius).toFixed(1)}`);
    }
    const bankTicks = [-60, -45, -30, -20, -10, 0, 10, 20, 30, 45, 60].map((bank) => {
      const tickLen = Math.abs(bank) === 30 || Math.abs(bank) === 60 ? 20 : 13;
      const innerX = Math.sin(degToRad(bank)) * arcRadius;
      const innerY = -Math.cos(degToRad(bank)) * arcRadius;
      const outerX = Math.sin(degToRad(bank)) * (arcRadius + tickLen);
      const outerY = -Math.cos(degToRad(bank)) * (arcRadius + tickLen);
      return `<line class="attitude-white" x1="${outerX.toFixed(1)}" y1="${outerY.toFixed(1)}" x2="${innerX.toFixed(1)}" y2="${innerY.toFixed(1)}"></line>`;
    }).join('');
    const slip = clamp(firstFinite(sample && sample.estimated_slip_skid_g, sample && sample.slip_skid_g, 0) || 0, -0.35, 0.35);
    const slipX = slip / 0.35 * 54;
    const staticPointerY = tapeTopY + 6;
    const pointerHalfWidth = 11;
    const pointerHeight = 22;
    const slipTopHalfWidth = pointerHalfWidth;
    const slipBottomHalfWidth = 16;
    const slipGap = 2;
    const slipHeight = 8;
    const yellowBankShiftPx = Math.tan(degToRad(clamp(rollDeg, -75, 75))) * (yellowReferenceY - horizonY);
    const yellowReferenceXTarget = centerX + yellowBankShiftPx;
    const yellowAlpha = snap ? 1 : smoothFactor(12, dtSec);
    displayAttitudeYellowReferenceX = displayAttitudeYellowReferenceX === null || !Number.isFinite(displayAttitudeYellowReferenceX)
      ? yellowReferenceXTarget
      : displayAttitudeYellowReferenceX + (yellowReferenceXTarget - displayAttitudeYellowReferenceX) * yellowAlpha;
    const horizontalFovDeg = Math.max(1, firstFinite(dbg.activeHorizontalFovDeg, dbg.horizontalFovDeg, syntheticVisionHorizontalFovDeg()) || syntheticVisionHorizontalFovDeg());
    const fpvReferenceHeadingDeg = firstFinite(dbg.headingDegUsed, view && view.heading);
    const fpvEnabled = instrumentEnabled('flight_path_vector');
    const rawFpv = fpvEnabled ? fpvVectorFromSample(sample, pitchDeg, fpvReferenceHeadingDeg) : null;
    const sampleT = sample && Number.isFinite(Number(sample.t)) ? Number(sample.t) : null;
    let fpv = fpvEnabled ? rawFpv : null;
    if (rawFpv) {
      lastValidFpvVector = rawFpv;
      lastValidFpvReplayT = sampleT;
    } else if (fpvEnabled && !snap && lastValidFpvVector && sampleT !== null && lastValidFpvReplayT !== null && Math.abs(sampleT - lastValidFpvReplayT) <= 0.4) {
      fpv = lastValidFpvVector;
    }
    let fpvHtml = '';
    if (fpv) {
      const fpvSourceAlpha = snap ? 1 : smoothFactor(0.9, dtSec);
      const fpvMaxHeadingStepDeg = snap ? Infinity : 4 * Math.max(1 / 120, dtSec);
      const fpvMaxPitchStepDeg = snap ? Infinity : 3 * Math.max(1 / 120, dtSec);
      displayFpvHeadingDeltaDeg = displayFpvHeadingDeltaDeg === null || !Number.isFinite(displayFpvHeadingDeltaDeg)
        ? fpv.headingDeltaDeg
        : normalizeSignedDeg(displayFpvHeadingDeltaDeg + clamp(
          normalizeSignedDeg(fpv.headingDeltaDeg - displayFpvHeadingDeltaDeg) * fpvSourceAlpha,
          -fpvMaxHeadingStepDeg,
          fpvMaxHeadingStepDeg
        ));
      displayFpvPitchDeltaDeg = displayFpvPitchDeltaDeg === null || !Number.isFinite(displayFpvPitchDeltaDeg)
        ? fpv.pitchDeltaDeg
        : displayFpvPitchDeltaDeg + clamp(
          (fpv.pitchDeltaDeg - displayFpvPitchDeltaDeg) * fpvSourceAlpha,
          -fpvMaxPitchStepDeg,
          fpvMaxPitchStepDeg
        );
      const fpvDisplayHeadingDeltaDeg = clamp(displayFpvHeadingDeltaDeg, -22, 22);
      const fpvDisplayPitchDeltaDeg = clamp(displayFpvPitchDeltaDeg, -22, 22);
      const fpvOffsetX = clamp(
        Math.tan(degToRad(fpvDisplayHeadingDeltaDeg)) / Math.tan(degToRad(horizontalFovDeg) / 2) * (width / 2),
        -width * 0.42,
        width * 0.42
      );
      const fpvOffsetY = clamp(pitchPx(fpvDisplayPitchDeltaDeg), -height * 0.42, height * 0.42);
      const rollRad = degToRad(-rollDeg);
      const fpvTargetX = displayAttitudeYellowReferenceX + (Math.cos(rollRad) * fpvOffsetX) - (Math.sin(rollRad) * fpvOffsetY);
      const fpvTargetY = yellowReferenceY + (Math.sin(rollRad) * fpvOffsetX) + (Math.cos(rollRad) * fpvOffsetY);
      displayFpvX = fpvTargetX;
      displayFpvY = fpvTargetY;
      fpvHtml = `
      <g class="attitude-fpv" transform="translate(${displayFpvX.toFixed(1)} ${displayFpvY.toFixed(1)})">
        <circle cx="0" cy="0" r="12"></circle>
        <line x1="-27" y1="0" x2="-12" y2="0"></line>
        <line x1="12" y1="0" x2="27" y2="0"></line>
        <line x1="0" y1="-22" x2="0" y2="-12"></line>
      </g>`;
    } else {
      displayFpvHeadingDeltaDeg = null;
      displayFpvPitchDeltaDeg = null;
      displayFpvX = null;
      displayFpvY = null;
      lastValidFpvVector = null;
      lastValidFpvReplayT = null;
    }
    const fdBarsEnabled = instrumentEnabled('flight_director_bars');
    const fdCommand = fdBarsEnabled ? flightDirectorCommandFromSample(sample) : null;
    let flightDirectorHtml = '';
    if (fdCommand) {
      const fdAlpha = snap ? 1 : smoothFactor(16, dtSec);
      const fdRollTarget = fdCommand.rollDeg === null ? rollDeg : Number(fdCommand.rollDeg);
      const fdPitchTarget = fdCommand.pitchDeg === null ? pitchDeg : Number(fdCommand.pitchDeg);
      displayFdRollCommandDeg = displayFdRollCommandDeg === null || !Number.isFinite(displayFdRollCommandDeg)
        ? fdRollTarget
        : displayFdRollCommandDeg + normalizeSignedDeg(fdRollTarget - displayFdRollCommandDeg) * fdAlpha;
      displayFdPitchCommandDeg = displayFdPitchCommandDeg === null || !Number.isFinite(displayFdPitchCommandDeg)
        ? fdPitchTarget
        : displayFdPitchCommandDeg + (fdPitchTarget - displayFdPitchCommandDeg) * fdAlpha;
      const fdRollErrorDeg = clamp(normalizeSignedDeg(displayFdRollCommandDeg - rollDeg), -35, 35);
      const fdPitchErrorDeg = clamp(displayFdPitchCommandDeg - pitchDeg, -15, 15);
      const fdY = yellowReferenceY + clamp(pitchPx(fdPitchErrorDeg), -height * 0.28, height * 0.28);
      flightDirectorHtml = `
      <g transform="translate(${displayAttitudeYellowReferenceX.toFixed(1)} ${fdY.toFixed(1)}) rotate(${(-fdRollErrorDeg).toFixed(2)}) scale(${attitudeYellowReferenceScale})">
        <polygon class="attitude-flight-director" points="0,-12 -326,42 -326,72 -272,76"></polygon>
        <polygon class="attitude-flight-director" points="0,-12 326,42 326,72 272,76"></polygon>
        <line class="attitude-flight-director-cap" x1="-326" y1="42" x2="-272" y2="76"></line>
        <line class="attitude-flight-director-cap" x1="326" y1="42" x2="272" y2="76"></line>
      </g>`;
    } else {
      displayFdRollCommandDeg = null;
      displayFdPitchCommandDeg = null;
    }
    const signature = [
      Math.round(width),
      Math.round(height),
      Math.round(horizonY),
      Math.round(referenceY),
      Math.round(yellowReferenceY),
      Math.round(arcCenterY),
      Math.round(arcRadius),
      Math.round(rollDeg * 10),
      Math.round(verticalFovDeg),
      Math.round(pitchLadderScaleFactor * 100),
      Math.round(attitudeRollArcScale * 100),
      Math.round(attitudePitchMarkScale * 100),
      Math.round(attitudeYellowReferenceScale * 100),
      Math.round(slipX),
      Math.round(displayAttitudeYellowReferenceX),
      displayFpvX === null ? 'fpv-x' : Math.round(displayFpvX),
      displayFpvY === null ? 'fpv-y' : Math.round(displayFpvY),
      fdBarsEnabled ? 'fd-on' : 'fd-off',
      displayFdRollCommandDeg === null ? 'fd-roll' : Math.round(displayFdRollCommandDeg * 10),
      displayFdPitchCommandDeg === null ? 'fd-pitch' : Math.round(displayFdPitchCommandDeg * 10),
    ].join('|');
    if (signature === attitudeOverlaySignature) {
      setElementHidden(attitudeOverlay, false);
      return;
    }
    attitudeOverlaySignature = signature;
    attitudeOverlay.setAttribute('viewBox', `0 0 ${width.toFixed(1)} ${height.toFixed(1)}`);
    attitudeOverlay.innerHTML = `
      <g transform="translate(${centerX.toFixed(1)} ${horizonY.toFixed(1)}) rotate(${(-rollDeg).toFixed(2)})">
        ${pitchMarks}
      </g>
      <g transform="translate(${centerX.toFixed(1)} ${arcCenterY.toFixed(1)}) rotate(${(-rollDeg).toFixed(2)}) scale(${attitudeRollArcScale})">
        <polyline class="attitude-white" points="${arcPoints.join(' ')}"></polyline>
        ${bankTicks}
        <polygon class="attitude-bank-pointer" points="0,${(-arcRadius + 1).toFixed(1)} -16.5,${(-arcRadius - 33).toFixed(1)} 16.5,${(-arcRadius - 33).toFixed(1)}"></polygon>
      </g>
      <g transform="translate(${centerX.toFixed(1)} ${staticPointerY.toFixed(1)})">
        <polygon class="attitude-slip" points="0,0 -${pointerHalfWidth},${pointerHeight} ${pointerHalfWidth},${pointerHeight}"></polygon>
        <polygon class="attitude-slip" points="${(slipX - slipTopHalfWidth).toFixed(1)},${pointerHeight + slipGap} ${(slipX + slipTopHalfWidth).toFixed(1)},${pointerHeight + slipGap} ${(slipX + slipBottomHalfWidth).toFixed(1)},${pointerHeight + slipGap + slipHeight} ${(slipX - slipBottomHalfWidth).toFixed(1)},${pointerHeight + slipGap + slipHeight}"></polygon>
      </g>
      ${fpvHtml}
      ${flightDirectorHtml}
      <g transform="translate(${displayAttitudeYellowReferenceX.toFixed(1)} ${yellowReferenceY.toFixed(1)}) scale(${attitudeYellowReferenceScale})">
        <rect class="attitude-yellow" x="-508" y="-6" width="132" height="12" rx="4" ry="4"></rect>
        <rect class="attitude-yellow" x="376" y="-6" width="132" height="12" rx="4" ry="4"></rect>
        <polygon class="attitude-yellow" points="-272,76 0,-12 -176,76"></polygon>
        <polygon class="attitude-yellow" points="272,76 0,-12 176,76"></polygon>
      </g>
    `;
    setElementHidden(attitudeOverlay, false);
  }

  function setModalOpen(panel, button, open) {
    if (!panel) return;
    panel.hidden = open !== true;
    if (button) button.classList.toggle('is-active', open === true);
  }

  function toggleModal(panel, button) {
    setModalOpen(panel, button, panel ? panel.hidden : false);
  }

  function updateCameraControlLabels() {
    if (!cameraSettings) return;
    if (cameraRangeValue) cameraRangeValue.textContent = `${Math.round(cameraSettings.rangeM)} m`;
    if (cameraHeightValue) cameraHeightValue.textContent = `+${Number(cameraSettings.heightM).toFixed(1)} m`;
    if (cameraPitchValue) cameraPitchValue.textContent = `${Number(cameraSettings.pitchDeg).toFixed(1)} deg`;
    if (cameraSmoothingValue) cameraSmoothingValue.textContent = cameraSettings.smoothing.toFixed(1);
  }

  function syncCameraControls() {
    if (!cameraSettings) return;
    if (cameraRangeInput) cameraRangeInput.value = String(cameraSettings.rangeM);
    if (cameraHeightInput) cameraHeightInput.value = String(cameraSettings.heightM);
    if (cameraPitchInput) cameraPitchInput.value = String(cameraSettings.pitchDeg);
    if (cameraSmoothingInput) cameraSmoothingInput.value = String(cameraSettings.smoothing);
    if (cameraRangeExact) cameraRangeExact.value = String(Math.round(cameraSettings.rangeM));
    if (cameraHeightExact) cameraHeightExact.value = Number(cameraSettings.heightM).toFixed(1);
    if (cameraPitchExact) cameraPitchExact.value = Number(cameraSettings.pitchDeg).toFixed(1);
    if (cameraSmoothingExact) cameraSmoothingExact.value = Number(cameraSettings.smoothing).toFixed(1);
    updateCameraControlLabels();
  }

  function updateCameraSetting(key, value) {
    if (!cameraSettings) return;
    const next = finiteNumber(value);
    if (next === null) return;
    if (key === 'rangeM') cameraSettings.rangeM = clamp(next, 60, 260);
    if (key === 'heightM') cameraSettings.heightM = clamp(next, 8, 90);
    if (key === 'pitchDeg') cameraSettings.pitchDeg = clamp(next, -30, -4);
    if (key === 'smoothing') cameraSettings.smoothing = clamp(next, 1, 12);
    syncCameraControls();
    updateCameraControlLabels();
    saveCameraSettings();
    safeRenderCesium(false);
  }

  const haversineM = (lat1, lon1, lat2, lon2) => {
    const phi1 = degToRad(lat1);
    const phi2 = degToRad(lat2);
    const dPhi = degToRad(lat2 - lat1);
    const dLambda = degToRad(lon2 - lon1);
    const a = Math.sin(dPhi / 2) ** 2 + Math.cos(phi1) * Math.cos(phi2) * Math.sin(dLambda / 2) ** 2;
    return 6371000 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(Math.max(0, 1 - a)));
  };

  const bearingDeg = (lat1, lon1, lat2, lon2) => {
    const phi1 = degToRad(lat1);
    const phi2 = degToRad(lat2);
    const lambda1 = degToRad(lon1);
    const lambda2 = degToRad(lon2);
    const y = Math.sin(lambda2 - lambda1) * Math.cos(phi2);
    const x = Math.cos(phi1) * Math.sin(phi2) - Math.sin(phi1) * Math.cos(phi2) * Math.cos(lambda2 - lambda1);
    return normalizeDeg(Math.atan2(y, x) * 180 / Math.PI);
  };

  function buildPositionKeyframes(samples) {
    const keys = [];
    let lastKey = null;
    for (const sample of samples) {
      const lat = Number(sample.lat);
      const lon = Number(sample.lon);
      const t = Number(sample.t);
      if (!Number.isFinite(lat) || !Number.isFinite(lon) || !Number.isFinite(t)) continue;
      const altitudeM = cameraEyeAltitudeM(sample);
      if (!lastKey) {
        lastKey = { t, lat, lon, altitudeM };
        keys.push(lastKey);
        continue;
      }
      const movedM = haversineM(lastKey.lat, lastKey.lon, lat, lon);
      if (movedM >= POSITION_KEY_MIN_DIST_M) {
        lastKey = { t, lat, lon, altitudeM };
        keys.push(lastKey);
      } else {
        lastKey.altitudeM = altitudeM;
      }
    }
    return keys;
  }

  function positionAt(t) {
    if (!positionKeyframes.length) return null;
    const time = Number(t);
    if (time <= positionKeyframes[0].t) {
      return Object.assign({}, positionKeyframes[0]);
    }
    const last = positionKeyframes[positionKeyframes.length - 1];
    if (time >= last.t) {
      return Object.assign({}, last);
    }
    let lo = 0;
    let hi = positionKeyframes.length - 1;
    while (lo + 1 < hi) {
      const mid = Math.floor((lo + hi) / 2);
      if (positionKeyframes[mid].t <= time) lo = mid;
      else hi = mid;
    }
    const before = positionKeyframes[lo];
    const after = positionKeyframes[hi];
    const span = Math.max(0.001, after.t - before.t);
    const ratio = Math.max(0, Math.min(1, (time - before.t) / span));
    return {
      t: time,
      lat: before.lat + (after.lat - before.lat) * ratio,
      lon: before.lon + (after.lon - before.lon) * ratio,
      altitudeM: before.altitudeM + (after.altitudeM - before.altitudeM) * ratio,
    };
  }

  function insetTrackSamples() {
    if (!payload || !Array.isArray(payload.samples)) return [];
    return payload.samples
      .map((sample) => {
        const lat = finiteNumber(sample.lat);
        const lon = finiteNumber(sample.lon);
        const t = finiteNumber(sample.t);
        if (lat === null || lon === null || t === null) return null;
        return { sample, lat, lon, t };
      })
      .filter(Boolean);
  }

  function insetMapAirports() {
    const airports = payload && payload.airports && typeof payload.airports === 'object' ? payload.airports : {};
    const entries = [];
    ['departure', 'destination'].forEach((role) => {
      const airport = airports[role];
      const lat = finiteNumber(airport && airport.lat);
      const lon = finiteNumber(airport && airport.lon);
      const icao = String((airport && airport.icao) || '').trim().toUpperCase();
      if (lat === null || lon === null || icao === '') return;
      if (entries.some((entry) => entry.icao === icao && Math.abs(entry.lat - lat) < 0.0001 && Math.abs(entry.lon - lon) < 0.0001)) return;
      entries.push({ role, icao, lat, lon });
    });
    return entries;
  }

  function insetOwnshipHex() {
    const hex = payload && payload.recording && payload.recording.aircraft
      ? String(payload.recording.aircraft.adsb_hex || '').trim().toLowerCase()
      : '';
    return hex.replace(/[^a-f0-9]/g, '').slice(0, 6);
  }

  function insetTrafficTargetsAt(t) {
    const rows = payload && Array.isArray(payload.traffic) ? payload.traffic : [];
    if (!rows.length || !instrumentEnabled('traffic')) return [];
    const ownshipHex = insetOwnshipHex();
    const activeT = finiteNumber(t) || 0;
    const windowS = 20;
    const nearestByHex = new Map();
    rows.forEach((row) => {
      const rowT = finiteNumber(row && row.t);
      const lat = finiteNumber(row && row.lat);
      const lon = finiteNumber(row && row.lon);
      const hex = String((row && row.hex) || '').trim().toLowerCase();
      if (rowT === null || lat === null || lon === null || hex === '' || (ownshipHex && hex === ownshipHex)) return;
      if (Math.abs(rowT - activeT) > windowS) return;
      const existing = nearestByHex.get(hex);
      if (!existing || Math.abs(existing.t - activeT) > Math.abs(rowT - activeT)) {
        nearestByHex.set(hex, {
          t: rowT,
          hex,
          cs: String((row && row.cs) || '').trim().toUpperCase(),
          lat,
          lon,
          trk: finiteNumber(row && row.trk) || 0,
          alt: finiteNumber(row && row.alt),
          dist: finiteNumber(row && row.dist),
        });
      }
    });
    return Array.from(nearestByHex.values()).sort((a, b) => (a.dist ?? 999) - (b.dist ?? 999));
  }

  function insetProjector(track, sample) {
    if (!track.length) return null;
    let minLat = Infinity;
    let maxLat = -Infinity;
    let minLon = Infinity;
    let maxLon = -Infinity;
    track.forEach((point) => {
      minLat = Math.min(minLat, point.lat);
      maxLat = Math.max(maxLat, point.lat);
      minLon = Math.min(minLon, point.lon);
      maxLon = Math.max(maxLon, point.lon);
    });
    const originLat = (minLat + maxLat) / 2;
    const originLon = (minLon + maxLon) / 2;
    const latLonToNm = (lat, lon) => ({
      e: (lon - originLon) * 60 * Math.cos(degToRad(originLat)),
      n: (lat - originLat) * 60,
    });
    const nmPoints = track.map((point) => ({ ...point, ...latLonToNm(point.lat, point.lon) }));
    let minE = Infinity;
    let maxE = -Infinity;
    let minN = Infinity;
    let maxN = -Infinity;
    nmPoints.forEach((point) => {
      minE = Math.min(minE, point.e);
      maxE = Math.max(maxE, point.e);
      minN = Math.min(minN, point.n);
      maxN = Math.max(maxN, point.n);
    });
    const currentLat = finiteNumber(sample && sample.lat);
    const currentLon = finiteNumber(sample && sample.lon);
    const currentNm = currentLat !== null && currentLon !== null ? latLonToNm(currentLat, currentLon) : null;
    const baseCenterE = (minE + maxE) / 2;
    const baseCenterN = (minN + maxN) / 2;
    const zoom = clamp(insetMapZoom, 1, 16);
    const centerE = (zoom > 1 && currentNm ? currentNm.e : baseCenterE) + insetMapPanE;
    const centerN = (zoom > 1 && currentNm ? currentNm.n : baseCenterN) + insetMapPanN;
    const widthNm = Math.max(0.01, maxE - minE);
    const heightNm = Math.max(0.01, maxN - minN);
    const fullRangeNm = Math.max(widthNm, heightNm, 0.25);
    const scale = ((INSET_MAP_SIZE - INSET_MAP_PADDING * 2) / fullRangeNm) * zoom;
    const project = (lat, lon) => {
      const nm = latLonToNm(lat, lon);
      return {
        x: INSET_MAP_SIZE / 2 + (nm.e - centerE) * scale,
        y: INSET_MAP_SIZE / 2 - (nm.n - centerN) * scale,
        e: nm.e,
        n: nm.n,
      };
    };
    return {
      project,
      latLonToNm,
      baseCenterE,
      baseCenterN,
      rangeNm: fullRangeNm / zoom,
      scale,
      points: nmPoints,
    };
  }

  function insetTrackDirection(sample, track, currentIndex) {
    const trueTrack = firstFinite(sample && sample.track_deg_true, sample && sample.gps_track_deg);
    if (trueTrack !== null) return normalizeDeg(trueTrack);
    const prev = track[Math.max(0, currentIndex - 1)];
    const next = track[Math.min(track.length - 1, currentIndex + 1)];
    if (prev && next && (prev.lat !== next.lat || prev.lon !== next.lon)) {
      return bearingDeg(prev.lat, prev.lon, next.lat, next.lon);
    }
    return 0;
  }

  function nearestInsetTrackIndex(track, time) {
    if (!track.length) return 0;
    let bestIndex = 0;
    let bestDelta = Infinity;
    track.forEach((point, index) => {
      const delta = Math.abs(point.t - time);
      if (delta < bestDelta) {
        bestDelta = delta;
        bestIndex = index;
      }
    });
    return bestIndex;
  }

  function updateInsetMapPlacement() {
    if (!insetMap || !root) return false;
    const rootRect = root.getBoundingClientRect();
    const hsiRect = instrumentAnchorRect(hsiOverlay);
    const airspeedRect = instrumentAnchorRect(airspeedTape);
    if (!hsiRect || hsiRect.width <= 0 || hsiRect.height <= 0) {
      setElementHidden(insetMap, true);
      return false;
    }
    const desiredLeft = airspeedRect ? airspeedRect.left : rootRect.left + 18;
    const profileHeight = clamp(Math.round(hsiRect.height * 0.17), 42, 58);
    const verticalSize = clamp(Math.round(hsiRect.height - profileHeight - 4), 170, 240);
    const hsiScaleX = hsiRect.width / 390;
    const hsiScaleY = hsiRect.height / 330;
    const hdgBoxLeft = hsiRect.left - (2 * hsiScaleX);
    const maxWidthBeforeHdg = Math.max(120, hdgBoxLeft - 20 - desiredLeft);
    const topSize = Math.round(Math.min(verticalSize, maxWidthBeforeHdg));
    const top = hsiRect.top + (50 * hsiScaleY);
    const left = Math.min(desiredLeft, rootRect.right - topSize - 12);
    insetMap.style.left = `${Math.round(Math.max(rootRect.left + 12, left) - rootRect.left)}px`;
    insetMap.style.top = `${Math.round(top - rootRect.top)}px`;
    insetMap.style.width = `${topSize}px`;
    insetMap.style.setProperty('--inset-map-profile-height', `${profileHeight}px`);
    return true;
  }

  function updateInsetMap(sample, snap = false) {
    if (!insetMap || !insetMapSvg || !insetAltitudeSvg) return;
    if (!sample || !instrumentEnabled('inset_map')) {
      setElementHidden(insetMap, true);
      insetMapSignature = '';
      insetMapProjection = null;
      return;
    }
    const track = insetTrackSamples();
    if (track.length < 2 || !updateInsetMapPlacement()) {
      setElementHidden(insetMap, true);
      insetMapSignature = '';
      insetMapProjection = null;
      return;
    }
    const projector = insetProjector(track, sample);
    if (!projector) {
      setElementHidden(insetMap, true);
      return;
    }
    const activeTime = firstFinite(sample.t, activeT) || 0;
    const currentIndex = nearestInsetTrackIndex(track, activeTime);
    const currentTrackPoint = track[currentIndex] || track[0];
    const aircraftPos = projector.project(currentTrackPoint.lat, currentTrackPoint.lon);
    const aircraftTrack = insetTrackDirection(currentTrackPoint.sample, track, currentIndex);
    const stride = Math.max(1, Math.ceil(track.length / 1300));
    const pathPoints = [];
    track.forEach((point, index) => {
      if (index % stride !== 0 && index !== track.length - 1) return;
      const projected = projector.project(point.lat, point.lon);
      pathPoints.push(`${projected.x.toFixed(1)},${projected.y.toFixed(1)}`);
    });
    const airports = insetMapAirports().map((airport) => ({
      ...airport,
      ...projector.project(airport.lat, airport.lon),
    }));
    const trafficTargets = insetTrafficTargetsAt(activeTime).map((target) => ({
      ...target,
      ...projector.project(target.lat, target.lon),
    }));
    const rangeNm = projector.rangeNm;
    if (insetMapRange) {
      insetMapRange.textContent = `${rangeNm >= 10 ? Math.round(rangeNm) : rangeNm.toFixed(1)} NM`;
    }
    const signature = [
      Math.round(activeTime * 5),
      insetMapZoom,
      pathPoints.length,
      airports.map((airport) => airport.icao).join(','),
      trafficTargets.map((target) => `${target.hex}:${target.cs}:${Math.round(target.x)}:${Math.round(target.y)}:${Math.round(target.trk)}`).join(','),
      Math.round(aircraftPos.x),
      Math.round(aircraftPos.y),
      Math.round(aircraftTrack),
      Math.round(rangeNm * 10),
      insetMap.style.left,
      insetMap.style.top,
      insetMap.style.width,
      Math.round(insetMapPanE * 100),
      Math.round(insetMapPanN * 100),
      snap ? 'snap' : 'run',
    ].join('|');
    if (signature !== insetMapSignature) {
      const airportHtml = airports.map((airport) => `
        <g>
          <circle cx="${airport.x.toFixed(1)}" cy="${airport.y.toFixed(1)}" r="8.0" fill="none" stroke="rgba(255,255,255,.90)" stroke-width="3"></circle>
          <text x="${(airport.x + 12).toFixed(1)}" y="${(airport.y - 9).toFixed(1)}" font-size="11" text-anchor="start">${escapeHtml(airport.icao)}</text>
        </g>
      `).join('');
      const trafficHtml = trafficTargets.map((target) => {
        const label = escapeHtml(target.cs || target.hex.toUpperCase());
        const labelX = clamp(target.x + 7, 4, INSET_MAP_SIZE - 4);
        const labelY = clamp(target.y - 7, 10, INSET_MAP_SIZE - 4);
        return `
          <g transform="translate(${target.x.toFixed(1)} ${target.y.toFixed(1)}) rotate(${target.trk.toFixed(1)})">
            <polygon points="0,-5.5 3.4,4.2 0,1.8 -3.4,4.2" fill="rgba(180,245,255,.95)" stroke="rgba(255,255,255,.88)" stroke-width="1.1" stroke-linejoin="round"></polygon>
          </g>
          <text x="${labelX.toFixed(1)}" y="${labelY.toFixed(1)}" font-size="9.5" text-anchor="start" fill="rgba(225,250,255,.98)" stroke="rgba(0,0,0,.85)" stroke-width="2.4" paint-order="stroke">${label}</text>
        `;
      }).join('');
      const planePath = 'M 0.0 -30.4 L 0.7 -29.5 L 1.3 -28.6 L 1.8 -27.7 L 2.4 -26.8 L 2.9 -25.9 L 3.3 -24.9 L 3.7 -24.0 L 3.9 -23.1 L 4.2 -22.2 L 4.5 -21.3 L 4.7 -20.3 L 4.9 -19.4 L 5.0 -18.5 L 5.1 -17.6 L 5.2 -16.7 L 28.5 -15.7 L 42.0 -14.8 L 47.8 -13.9 L 48.6 -13.0 L 49.2 -12.1 L 49.6 -11.2 L 49.9 -10.2 L 50.0 -9.3 L 50.0 -8.4 L 49.9 -7.5 L 21.8 -6.6 L 4.7 -5.6 L 4.5 -4.7 L 4.2 -3.8 L 3.9 -2.9 L 3.7 -2.0 L 3.4 -1.0 L 3.3 -0.1 L 3.0 0.8 L 2.8 1.7 L 2.5 2.6 L 2.4 3.5 L 2.1 4.5 L 2.0 5.4 L 1.8 6.3 L 1.7 7.2 L 1.6 8.1 L 1.6 9.1 L 1.4 10.0 L 1.3 10.9 L 1.3 11.8 L 1.3 12.7 L 1.2 13.6 L 1.2 14.6 L 1.2 15.5 L 1.0 16.4 L 1.0 17.3 L 1.0 18.2 L 1.0 19.2 L 1.0 20.1 L 1.0 21.0 L 1.0 21.9 L 1.0 22.8 L 2.2 23.8 L 8.0 24.7 L 10.8 25.6 L 11.3 26.5 L 11.5 27.4 L 11.4 28.3 L 11.0 29.3 L 3.8 30.2 L -1.0 30.4 L -1.4 30.4 L -4.7 30.2 L -11.2 29.3 L -11.4 28.3 L -11.5 27.4 L -11.3 26.5 L -10.6 25.6 L -7.3 24.7 L -1.7 23.8 L -1.0 22.8 L -1.0 21.9 L -1.0 21.0 L -1.0 20.1 L -1.0 19.2 L -1.0 18.2 L -1.0 17.3 L -1.0 16.4 L -1.0 15.5 L -1.0 14.6 L -1.0 13.6 L -1.2 12.7 L -1.3 11.8 L -1.3 10.9 L -1.4 10.0 L -1.6 9.1 L -1.6 8.1 L -1.7 7.2 L -1.8 6.3 L -2.0 5.4 L -2.1 4.5 L -2.4 3.5 L -2.5 2.6 L -2.8 1.7 L -3.0 0.8 L -3.3 -0.1 L -3.4 -1.0 L -3.7 -2.0 L -3.9 -2.9 L -4.2 -3.8 L -4.5 -4.7 L -4.7 -5.6 L -30.8 -6.6 L -49.9 -7.5 L -50.0 -8.4 L -50.0 -9.3 L -49.9 -10.2 L -49.6 -11.2 L -49.2 -12.1 L -48.6 -13.0 L -47.6 -13.9 L -40.7 -14.8 L -26.6 -15.7 L -5.2 -16.7 L -5.1 -17.6 L -5.0 -18.5 L -4.9 -19.4 L -4.7 -20.3 L -4.5 -21.3 L -4.2 -22.2 L -3.9 -23.1 L -3.5 -24.0 L -3.1 -24.9 L -2.8 -25.9 L -2.4 -26.8 L -1.8 -27.7 L -1.2 -28.6 L -0.5 -29.5 L 0.0 -30.4 Z';
      insetMapSvg.innerHTML = `
        <rect x="0" y="0" width="${INSET_MAP_SIZE}" height="${INSET_MAP_SIZE}" fill="rgba(40,40,40,.12)"></rect>
        <polyline points="${pathPoints.join(' ')}" fill="none" stroke="${INSET_MAP_MAGENTA}" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"></polyline>
        ${airportHtml}
        ${trafficHtml}
        <g transform="translate(${aircraftPos.x.toFixed(1)} ${aircraftPos.y.toFixed(1)}) rotate(${aircraftTrack.toFixed(1)}) scale(.46)">
          <path d="${planePath}" fill="rgba(0,0,0,.96)" stroke="rgba(255,255,255,.96)" stroke-width="4.5" stroke-linejoin="round"></path>
        </g>
        <g transform="translate(208 37)">
          <text x="0" y="-18" font-size="11" text-anchor="middle">N</text>
          <polygon points="0,-13 -6,8 0,4 6,8" fill="rgba(255,255,255,.96)" stroke="rgba(15,23,42,.35)" stroke-width=".7"></polygon>
        </g>
      `;
      insetMapSignature = signature;
    }
    insetMapProjection = { projector, track };
    updateInsetAltitudeProfile(sample, track);
    setElementHidden(insetMap, false);
  }

  function insetProfileAltitudeFt(sample) {
    return firstFinite(
      sample && sample.estimated_indicated_altitude_ft,
      sample && sample.baro_altitude_ft,
      sample && sample.altitude_ft_msl,
      sample && sample.altitude_ft
    );
  }

  function updateInsetAltitudeProfile(sample, track) {
    if (!insetAltitudeSvg || !track.length) return;
    const currentT = firstFinite(sample && sample.t, activeT) || 0;
    const windowS = 600;
    const halfWindow = windowS / 2;
    const profile = track
      .filter((point) => Math.abs(point.t - currentT) <= halfWindow)
      .map((point) => {
        const altitude = insetProfileAltitudeFt(point.sample);
        if (altitude === null) return null;
        const agl = firstFinite(point.sample && point.sample.height_agl_ft);
        const terrain = agl !== null ? altitude - agl : null;
        return { t: point.t, altitude, terrain };
      })
      .filter(Boolean);
    const currentAltitude = insetProfileAltitudeFt(sample);
    const currentAgl = firstFinite(sample && sample.height_agl_ft);
    if (profile.length < 2 || currentAltitude === null) {
      insetAltitudeSvg.innerHTML = '';
      return;
    }
    const terrainValues = profile.map((point) => point.terrain).filter((value) => value !== null);
    const minValue = Math.min(...profile.map((point) => point.altitude), ...(terrainValues.length ? terrainValues : profile.map((point) => point.altitude))) - 120;
    const maxValue = Math.max(...profile.map((point) => point.altitude), currentAltitude) + 120;
    const span = Math.max(100, maxValue - minValue);
    const width = 240;
    const height = 58;
    const yForAlt = (altitude) => clamp(height - 8 - ((altitude - minValue) / span) * (height - 16), 6, height - 7);
    const xForT = (t) => width / 2 + ((t - currentT) / windowS) * width;
    const altPoints = profile.map((point) => `${xForT(point.t).toFixed(1)},${yForAlt(point.altitude).toFixed(1)}`);
    const terrainPoints = profile.map((point) => {
      const terrain = point.terrain !== null ? point.terrain : minValue;
      return `${xForT(point.t).toFixed(1)},${yForAlt(terrain).toFixed(1)}`;
    });
    const terrainPolygon = [`0,${height}`, ...terrainPoints, `${width},${height}`].join(' ');
    const currentY = yForAlt(currentAltitude);
    const aglText = currentAgl !== null ? `AGL ${Math.round(currentAgl)} FT` : 'AGL --';
    insetAltitudeSvg.innerHTML = `
      <polygon points="${terrainPolygon}" fill="rgba(34,197,94,.25)" stroke="rgba(34,197,94,.45)" stroke-width=".8"></polygon>
      <polyline points="${altPoints.join(' ')}" fill="none" stroke="${INSET_MAP_MAGENTA}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></polyline>
      <line x1="120" y1="5" x2="120" y2="${height - 5}" stroke="rgba(255,255,255,.20)" stroke-width="1"></line>
      <circle cx="120" cy="${currentY.toFixed(1)}" r="4.2" fill="${INSET_MAP_MAGENTA}" stroke="rgba(255,255,255,.92)" stroke-width="1.4"></circle>
      <text x="8" y="17" font-size="10" text-anchor="start">BARO ${Math.round(currentAltitude)} FT</text>
      <text x="8" y="32" font-size="10" text-anchor="start">${escapeHtml(aglText)}</text>
    `;
  }

  function trueHeadingFromSample(sample) {
    if (!sample) return 0;
    const explicitTrue = firstFinite(sample.heading_deg_true, sample.true_heading_deg);
    if (explicitTrue !== null) return normalizeDeg(explicitTrue);
    const magnetic = Number(sample.heading_deg);
    const variation = Number.isFinite(Number(sample.magnetic_variation_deg))
      ? Number(sample.magnetic_variation_deg)
      : (sample.g3x && Number.isFinite(Number(sample.g3x.magnetic_variation_deg))
        ? Number(sample.g3x.magnetic_variation_deg)
        : null);
    if (Number.isFinite(magnetic) && variation !== null) {
      return magneticToTrueHeadingDeg(magnetic, variation, null);
    }
    return Number.isFinite(magnetic) ? normalizeDeg(magnetic) : 0;
  }

  function aircraftHeadingFromSample(sample) {
    const explicitTrue = firstFinite(sample && sample.heading_deg_true, sample && sample.true_heading_deg, sample && sample.heading_deg);
    if (explicitTrue !== null) return normalizeDeg(explicitTrue);

    return trueHeadingFromSample(sample);
  }

  function syntheticVisionHeadingFromSample(sample) {
    const magnetic = firstFinite(sample && sample.heading_deg_magnetic);
    if (magnetic !== null) return normalizeDeg(magnetic);
    return aircraftHeadingFromSample(sample);
  }

  function syntheticVisionHeadingSource(sample) {
    if (firstFinite(sample && sample.heading_deg_magnetic) !== null) return 'heading_deg_magnetic';
    if (firstFinite(sample && sample.heading_deg_true, sample && sample.true_heading_deg) !== null) return 'heading_deg_true';
    if (firstFinite(sample && sample.heading_deg) !== null) return 'heading_deg_legacy';
    return 'fallback';
  }

  function aircraftPitchFromSample(sample) {
    return clamp(firstFinite(sample && sample.pitch_deg, sample && sample.visual_pitch_deg, 0) || 0, -45, 45);
  }

  function aircraftRollFromSample(sample) {
    return clamp(firstFinite(sample && sample.roll_deg, sample && sample.bank_deg, sample && sample.visual_roll_deg, 0) || 0, -75, 75);
  }

  function targetCameraAt(t) {
    const pos = isSyntheticTestMode() ? fixedSyntheticTestPosition() : positionAt(t);
    const s = sampleAt(t);
    if (!pos || !s) return null;
    const aircraftHeading = isSyntheticCameraMode() ? syntheticVisionHeadingFromSample(s) : aircraftHeadingFromSample(s);
    if (isSyntheticCameraMode()) {
      const altitudeState = visualAltitudeState(s, t);
      const aircraftAltitudeM = altitudeState.altitudeM;
      const groundReferenceM = altitudeState.groundReferenceM;
      const groundSource = groundReferenceSource(s);
      const testAttitude = cameraMode === 'synthetic_vision' ? null : syntheticTestAttitudeForMode(cameraMode);
      const headingDeg = testAttitude ? testAttitude.headingDeg : aircraftHeading;
      const pitchDeg = testAttitude ? testAttitude.pitchDeg : aircraftPitchFromSample(s);
      const rollDeg = testAttitude ? testAttitude.rollDeg : aircraftRollFromSample(s);
      return {
        mode: cameraMode,
        cameraMethod: 'setView direction/up',
        aircraftLat: pos.lat,
        aircraftLon: pos.lon,
        aircraftAltitudeM,
        lat: pos.lat,
        lon: pos.lon,
        altitudeM: aircraftAltitudeM + SYNTHETIC_VISION_DEFAULTS.eyeHeightM,
        rawAltitudeM: rawAltitudeM(s),
        visualAltitudeM: aircraftAltitudeM,
        groundReferenceAltitudeM: groundReferenceM,
        groundReferenceSource: groundSource,
        garminAltitudeM: altitudeState.garminAltitudeM,
        isGroundSample: altitudeState.isGround,
        localVisualAltitudeOffsetM: altitudeState.localVisualAltitudeOffsetM,
        localVisualAltitudeOffsetSource: altitudeState.localVisualAltitudeOffsetSource,
        altitudeCurvePreserved: altitudeState.altitudeCurvePreserved,
        aircraftHeading,
        headingSourceForCamera: syntheticVisionHeadingSource(s),
        heading: headingDeg,
        pitch: pitchDeg,
        roll: rollDeg,
        modelYaw: headingDeg,
        interpolatedHeading: headingDeg,
        interpolatedTrack: firstFinite(s.track_deg_true, s.track_deg),
        testAttitude,
      };
    }
    const aircraftAltitudeM = visualAltitudeM(s);
    const groundReferenceM = groundReferenceAltitudeM(s);
    const groundSource = groundReferenceSource(s);
    const heading = cameraMode === 'north_up' ? 0 : aircraftHeading;
    return {
      mode: cameraMode === 'north_up' ? 'north_up' : 'chase',
      cameraMethod: 'setView',
      aircraftLat: pos.lat,
      aircraftLon: pos.lon,
      aircraftAltitudeM,
      lat: pos.lat,
      lon: pos.lon,
      altitudeM: aircraftAltitudeM,
      rawAltitudeM: rawAltitudeM(s),
      visualAltitudeM: aircraftAltitudeM,
      groundReferenceAltitudeM: groundReferenceM,
      groundReferenceSource: groundSource,
      aircraftHeading,
      heading,
      pitch: cameraSettings.pitchDeg,
      roll: 0,
      modelYaw: aircraftHeading,
      interpolatedHeading: aircraftHeading,
      interpolatedTrack: firstFinite(s.track_deg_true, s.track_deg),
    };
  }

  function offsetLatLon(lat, lon, headingDeg, backMeters, rightMeters) {
    const headingRad = degToRad(headingDeg);
    const north = -Math.cos(headingRad) * backMeters + Math.sin(headingRad) * rightMeters;
    const east = -Math.sin(headingRad) * backMeters - Math.cos(headingRad) * rightMeters;
    const dLat = north / 6378137;
    const dLon = east / (6378137 * Math.cos(degToRad(lat)));
    return {
      lat: lat + dLat * 180 / Math.PI,
      lon: lon + dLon * 180 / Math.PI,
    };
  }

  function resetDisplayCamera() {
    displayCamera = null;
    lastRenderMs = null;
    previousSyntheticFrameDebug = null;
    displayAirspeedKt = null;
    displayAltitudeFt = null;
    displayVsiFpm = null;
    displayHsiHeadingDeg = null;
    displayHsiHeadingBugDeg = null;
    displayHsiRmiBearingDeg = null;
    displayHsiCdiOffsetPx = null;
    displayAttitudeYellowReferenceX = null;
    displayFpvHeadingDeltaDeg = null;
    displayFpvPitchDeltaDeg = null;
    displayFpvX = null;
    displayFpvY = null;
    displayFdRollCommandDeg = null;
    displayFdPitchCommandDeg = null;
    lastValidFpvVector = null;
    lastValidFpvReplayT = null;
    displayRpm = null;
    displayEngineValues.clear();
    hsiOverlaySignature = '';
    attitudeOverlaySignature = '';
    insetMapSignature = '';
  }

  function applyCameraModeControls() {
    if (!cesiumViewer) return;
    const controller = cesiumViewer.scene.screenSpaceCameraController;
    const free = cameraMode === 'free';
    if (cameraPanel) cameraPanel.hidden = !(cameraMode === 'chase' || cameraMode === 'north_up');
    controller.enableRotate = free;
    controller.enableTranslate = free;
    controller.enableZoom = free;
    controller.enableTilt = free;
    controller.enableLook = free;
  }

  function cesiumOrientationFromAviation(headingDeg, pitchDeg, rollDeg) {
    // Diagnostic-only HPR values. The rendered SVT camera uses explicit ENU
    // direction/up vectors below so it cannot silently pick the wrong Cesium axis.
    return {
      headingDeg: normalizeDeg(headingDeg),
      pitchDeg: Number(pitchDeg) || 0,
      rollDeg: Number(rollDeg) || 0,
      headingRad: degToRad(normalizeDeg(headingDeg)),
      pitchRad: degToRad(Number(pitchDeg) || 0),
      rollRad: degToRad(Number(rollDeg) || 0),
    };
  }

  function crossCartesian(a, b) {
    return new Cesium.Cartesian3(
      a.y * b.z - a.z * b.y,
      a.z * b.x - a.x * b.z,
      a.x * b.y - a.y * b.x
    );
  }

  function scaleCartesian(v, scalar) {
    return new Cesium.Cartesian3(v.x * scalar, v.y * scalar, v.z * scalar);
  }

  function addCartesian(a, b) {
    return new Cesium.Cartesian3(a.x + b.x, a.y + b.y, a.z + b.z);
  }

  function aviationBasisEnu(headingDeg, pitchDeg, rollDeg) {
    const h = degToRad(normalizeDeg(headingDeg));
    const p = degToRad(Number(pitchDeg) || 0);
    const r = degToRad(Number(rollDeg) || 0);
    const forward = Cesium.Cartesian3.normalize(new Cesium.Cartesian3(
      Math.cos(p) * Math.sin(h),
      Math.cos(p) * Math.cos(h),
      Math.sin(p)
    ), new Cesium.Cartesian3());
    const rightLevel = Cesium.Cartesian3.normalize(new Cesium.Cartesian3(
      Math.cos(h),
      -Math.sin(h),
      0
    ), new Cesium.Cartesian3());
    const upNoRoll = Cesium.Cartesian3.normalize(crossCartesian(rightLevel, forward), new Cesium.Cartesian3());
    const up = Cesium.Cartesian3.normalize(addCartesian(
      scaleCartesian(upNoRoll, Math.cos(r)),
      scaleCartesian(rightLevel, Math.sin(r))
    ), new Cesium.Cartesian3());

    return { forward, right: rightLevel, up };
  }

  function cartesianToDebug(value) {
    if (!value) return '--';
    return `${value.x.toFixed(2)}, ${value.y.toFixed(2)}, ${value.z.toFixed(2)}`;
  }

  function quaternionToDebug(value) {
    if (!value) return '--';
    return `${value.x.toFixed(5)}, ${value.y.toFixed(5)}, ${value.z.toFixed(5)}, ${value.w.toFixed(5)}`;
  }

  function applySyntheticCameraView(view, sample) {
    const fovState = syntheticVisionFovState();
    const verticalFovDeg = fovState.verticalFovDeg;
    if (cesiumViewer.camera && cesiumViewer.camera.frustum) {
      cesiumViewer.camera.frustum.fov = degToRad(fovState.cesiumFovDegToSet);
      cesiumViewer.camera.frustum.near = 0.05;
    }
    const aircraftCartesian = Cesium.Cartesian3.fromDegrees(view.aircraftLon, view.aircraftLat, view.aircraftAltitudeM);
    const calibratedHeading = normalizeDeg(Number(view.heading || 0) + SYNTHETIC_VISION_DEFAULTS.yawBiasDeg + (cameraCalibration ? cameraCalibration.yawDeg : 0));
    const calibratedPitch = clamp(Number(view.pitch || 0) + (cameraCalibration ? cameraCalibration.pitchDeg : 0), -89, 89);
    const calibratedRoll = clamp(Number(view.roll || 0) + SYNTHETIC_VISION_DEFAULTS.rollBiasDeg + (cameraCalibration ? cameraCalibration.rollDeg : 0), -89, 89);
    const visualCameraRoll = calibratedRoll;
    const rollConvention = 'garmin_roll_direct';
    const orientation = cesiumOrientationFromAviation(calibratedHeading, calibratedPitch, visualCameraRoll);
    const enuTransform = Cesium.Transforms.eastNorthUpToFixedFrame(aircraftCartesian);
    const basis = aviationBasisEnu(calibratedHeading, calibratedPitch, visualCameraRoll);
    const eyeOffsetEnu = addCartesian(
      addCartesian(
        scaleCartesian(basis.forward, BODY_AXIS_MAPPING.eyeOffsetXForwardM),
        scaleCartesian(basis.right, BODY_AXIS_MAPPING.eyeOffsetYRightM)
      ),
      scaleCartesian(basis.up, BODY_AXIS_MAPPING.eyeOffsetZUpM)
    );
    let cameraWorld = Cesium.Matrix4.multiplyByPoint(enuTransform, eyeOffsetEnu, new Cesium.Cartesian3());
    const direction = Cesium.Cartesian3.normalize(
      Cesium.Matrix4.multiplyByPointAsVector(enuTransform, basis.forward, new Cesium.Cartesian3()),
      new Cesium.Cartesian3()
    );
    const up = Cesium.Cartesian3.normalize(
      Cesium.Matrix4.multiplyByPointAsVector(enuTransform, basis.up, new Cesium.Cartesian3()),
      new Cesium.Cartesian3()
    );
    const rightWorld = Cesium.Cartesian3.normalize(crossCartesian(direction, up), new Cesium.Cartesian3());
    if (cameraCalibration) {
      const calibrationOffsetWorld = addCartesian(
        addCartesian(
          scaleCartesian(direction, cameraCalibration.forwardM),
          scaleCartesian(rightWorld, cameraCalibration.rightM)
        ),
        scaleCartesian(up, cameraCalibration.upM)
      );
      cameraWorld = Cesium.Cartesian3.add(cameraWorld, calibrationOffsetWorld, new Cesium.Cartesian3());
    }
    const rotation = new Cesium.Matrix3(
      direction.x, rightWorld.x, up.x,
      direction.y, rightWorld.y, up.y,
      direction.z, rightWorld.z, up.z
    );
    const quaternion = Cesium.Quaternion.fromRotationMatrix(rotation, new Cesium.Quaternion());
    cesiumViewer.camera.setView({
      destination: cameraWorld,
      orientation: {
        direction,
        up,
      },
    });
    const activeFrustum = cesiumViewer.camera && cesiumViewer.camera.frustum ? cesiumViewer.camera.frustum : null;
    const activeCesiumFovRad = activeFrustum && Number.isFinite(Number(activeFrustum.fov)) ? Number(activeFrustum.fov) : null;
    const activeCesiumFovDeg = activeCesiumFovRad !== null ? activeCesiumFovRad * 180 / Math.PI : null;
    const activeCesiumAspectRatio = activeFrustum && Number.isFinite(Number(activeFrustum.aspectRatio))
      ? Number(activeFrustum.aspectRatio)
      : fovState.viewportAspectRatio;
    const activeCesiumFovAxis = activeCesiumAspectRatio >= 1 ? 'horizontal' : 'vertical';
    const activeHorizontalFovDeg = activeCesiumFovDeg === null
      ? null
      : (activeCesiumFovAxis === 'horizontal'
        ? activeCesiumFovDeg
        : 2 * Math.atan(Math.tan(degToRad(activeCesiumFovDeg) / 2) * activeCesiumAspectRatio) * 180 / Math.PI);
    const activeVerticalFovDeg = activeCesiumFovDeg === null
      ? null
      : (activeCesiumFovAxis === 'vertical'
        ? activeCesiumFovDeg
        : 2 * Math.atan(Math.tan(degToRad(activeCesiumFovDeg) / 2) / activeCesiumAspectRatio) * 180 / Math.PI);

    const cameraCartographic = Cesium.Cartographic.fromCartesian(cameraWorld);
    const cameraLat = cameraCartographic.latitude * 180 / Math.PI;
    const cameraLon = cameraCartographic.longitude * 180 / Math.PI;
    const groundReferenceM = Number.isFinite(Number(view.groundReferenceAltitudeM)) ? Number(view.groundReferenceAltitudeM) : view.aircraftAltitudeM;
    const calibrationUpM = cameraCalibration ? Number(cameraCalibration.upM || 0) : 0;
    const syntheticEyeUpM = BODY_AXIS_MAPPING.eyeOffsetZUpM;
    const cameraMinusAircraftM = cameraCartographic.height - view.aircraftAltitudeM;
    const rawGarminAltitudeFt = sample && Number.isFinite(Number(sample.altitude_ft_msl ?? sample.altitude_ft))
      ? Number(sample.altitude_ft_msl ?? sample.altitude_ft)
      : null;
    const rawGarminAltitudeM = rawGarminAltitudeFt !== null ? feetToMeters(rawGarminAltitudeFt) : null;
    const rawGarminRollDeg = sample && Number.isFinite(Number(sample.roll_deg ?? sample.bank_deg))
      ? Number(sample.roll_deg ?? sample.bank_deg)
      : null;
    const movementDebug = previousSyntheticFrameDebug ? {
      aircraftMoveM: haversineM(previousSyntheticFrameDebug.aircraftLat, previousSyntheticFrameDebug.aircraftLon, view.aircraftLat, view.aircraftLon),
      aircraftMoveBearingDeg: bearingDeg(previousSyntheticFrameDebug.aircraftLat, previousSyntheticFrameDebug.aircraftLon, view.aircraftLat, view.aircraftLon),
      cameraMoveM: haversineM(previousSyntheticFrameDebug.cameraLat, previousSyntheticFrameDebug.cameraLon, cameraLat, cameraLon),
      cameraMoveBearingDeg: bearingDeg(previousSyntheticFrameDebug.cameraLat, previousSyntheticFrameDebug.cameraLon, cameraLat, cameraLon),
    } : null;
    previousSyntheticFrameDebug = {
      aircraftLat: view.aircraftLat,
      aircraftLon: view.aircraftLon,
      cameraLat,
      cameraLon,
    };
    currentCameraDebug = {
      method: 'setView direction/up from aviation ENU vectors',
      cameraMode,
      aircraftLat: view.aircraftLat,
      aircraftLon: view.aircraftLon,
      aircraftAltitudeFt: sample && Number.isFinite(Number(sample.altitude_ft)) ? Number(sample.altitude_ft) : null,
      aircraftAltitudeM: view.aircraftAltitudeM,
      rawGarminAltitudeFt,
      rawGarminAltitudeM,
      isGroundSample: isGroundSample(sample),
      altitudeSource: sourceValue(sample, 'altitude_source') || '',
      terrainHeightM: Number.isFinite(lastTerrainHeightM) ? lastTerrainHeightM : null,
      groundReferenceAltitudeM: groundReferenceM,
      groundReferenceSource: view.groundReferenceSource || null,
      garminAltitudeM: Number.isFinite(Number(view.garminAltitudeM)) ? Number(view.garminAltitudeM) : null,
      localVisualAltitudeOffsetM: Number.isFinite(Number(view.localVisualAltitudeOffsetM)) ? Number(view.localVisualAltitudeOffsetM) : null,
      localVisualAltitudeOffsetSource: view.localVisualAltitudeOffsetSource || null,
      altitudeCurvePreserved: view.altitudeCurvePreserved === true,
      cameraLat,
      cameraLon,
      cameraHeightM: cameraCartographic.height,
      finalCameraAltitudeM: cameraCartographic.height,
      cameraHeightAboveAircraftM: cameraMinusAircraftM,
      cameraHeightAboveTerrainM: cameraCartographic.height - groundReferenceM,
      cameraHeightAboveCesiumTerrainM: Number.isFinite(lastTerrainHeightM) ? cameraCartographic.height - lastTerrainHeightM : null,
      calibrationUpM,
      syntheticEyeUpM,
      totalVerticalOffsetM: cameraMinusAircraftM,
      aircraftCartesian,
      cameraCartesian: cameraWorld,
      cameraDirection: direction,
      cameraUp: up,
      orientationQuaternion: quaternion,
      calibration: cameraCalibration ? { ...cameraCalibration } : null,
      bodyAxisMapping: 'ENU explicit: heading -> forward, pitch -> forward.z, Garmin roll direct for camera horizon convention',
      horizontalFovDeg: fovState.horizontalFovDeg,
      verticalFovDeg,
      viewportAspectRatio: fovState.viewportAspectRatio,
      cesiumFovDegToSet: fovState.cesiumFovDegToSet,
      cesiumFovAxis: fovState.cesiumFovAxis,
      activeCesiumFovDeg,
      activeCesiumFovRad,
      activeCesiumFovAxis,
      activeCesiumAspectRatio,
      activeHorizontalFovDeg,
      activeVerticalFovDeg,
      yawBiasDeg: SYNTHETIC_VISION_DEFAULTS.yawBiasDeg,
      rollBiasDeg: SYNTHETIC_VISION_DEFAULTS.rollBiasDeg,
      movementDebug,
      headingDegUsed: calibratedHeading,
      headingDegBeforeCalibration: view.heading,
      headingSourceForCamera: view.headingSourceForCamera || null,
      headingDegTrue: firstFinite(sample && sample.heading_deg_true, sample && sample.true_heading_deg),
      headingDegMagnetic: firstFinite(sample && sample.heading_deg_magnetic),
      headingDegLegacy: firstFinite(sample && sample.heading_deg),
      magneticVariationDeg: firstFinite(sample && sample.magnetic_variation_deg),
      headingReference: sourceValue(sample, 'heading_reference') || '',
      yawCalibrationDeg: cameraCalibration ? Number(cameraCalibration.yawDeg || 0) : 0,
      totalYawOffsetDeg: normalizeSignedDeg(calibratedHeading - Number(view.heading || 0)),
      pitchDegUsed: calibratedPitch,
      rollDegUsed: visualCameraRoll,
      rawGarminRollDeg,
      visualCameraRollDeg: visualCameraRoll,
      visualRollInverted: false,
      rollConvention,
      uncalibratedHeadingDeg: view.heading,
      uncalibratedPitchDeg: view.pitch,
      uncalibratedRollDeg: view.roll,
      cesiumHeadingDeg: orientation.headingDeg,
      cesiumPitchDeg: orientation.pitchDeg,
      cesiumRollDeg: orientation.rollDeg,
      cesiumHeadingRad: orientation.headingRad,
      cesiumPitchRad: orientation.pitchRad,
      cesiumRollRad: orientation.rollRad,
    };
    updateCalibrationPanel();
  }

  function applyWorldCameraView(view, cameraPos, cameraAltitudeM) {
    const groundReferenceM = Number.isFinite(Number(view.groundReferenceAltitudeM)) ? Number(view.groundReferenceAltitudeM) : view.aircraftAltitudeM;
    cesiumViewer.camera.setView({
      destination: Cesium.Cartesian3.fromDegrees(cameraPos.lon, cameraPos.lat, cameraAltitudeM),
      orientation: {
        heading: degToRad(view.heading),
        pitch: degToRad(view.pitch),
        roll: degToRad(view.roll),
      },
    });
    currentCameraDebug = {
      method: 'setView',
      cameraMode,
      aircraftLat: view.aircraftLat,
      aircraftLon: view.aircraftLon,
      aircraftAltitudeFt: null,
      aircraftAltitudeM: view.aircraftAltitudeM,
      terrainHeightM: Number.isFinite(lastTerrainHeightM) ? lastTerrainHeightM : null,
      groundReferenceAltitudeM: groundReferenceM,
      groundReferenceSource: view.groundReferenceSource || null,
      cameraLat: cameraPos.lat,
      cameraLon: cameraPos.lon,
      cameraHeightM: cameraAltitudeM,
      cameraHeightAboveAircraftM: cameraAltitudeM - view.aircraftAltitudeM,
      cameraHeightAboveTerrainM: cameraAltitudeM - groundReferenceM,
      cameraHeightAboveCesiumTerrainM: Number.isFinite(lastTerrainHeightM) ? cameraAltitudeM - lastTerrainHeightM : null,
      headingDegUsed: view.heading,
      pitchDegUsed: view.pitch,
      rollDegUsed: view.roll,
      cesiumHeadingDeg: normalizeDeg(view.heading),
      cesiumPitchDeg: view.pitch,
      cesiumRollDeg: view.roll,
      cesiumHeadingRad: degToRad(view.heading),
      cesiumPitchRad: degToRad(view.pitch),
      cesiumRollRad: degToRad(view.roll),
    };
  }

  function renderCesium(snap = false) {
    if (!cesiumReady || !cesiumViewer) return;
    if (cameraMode === 'free') {
      const freeSample = sampleAt(activeT);
      const freeInstrumentView = instrumentViewFromSample(freeSample);
      updateHorizonLine(freeInstrumentView);
      updateAttitudeIndicator(freeInstrumentView, freeSample, 1 / 60, true);
      updateAirspeedTape(freeSample, 1 / 60, true);
      updateAoaIndicator(freeSample);
      updateAltimeterTape(freeSample, 1 / 60, true);
      updateHsiOverlay(freeSample, 1 / 60, true);
      updateEnginePanel(freeSample, 1 / 60, true);
      updateInsetMap(freeSample, true);
      updateAvionicsHeader(freeSample);
      updateTerrainHeight(freeSample);
      updateDebugOverlay(freeSample, displayCamera);
      return;
    }
    const now = performance.now();
    const dtSec = lastRenderMs === null ? 1 / 60 : Math.min(0.1, Math.max(1 / 120, (now - lastRenderMs) / 1000));
    lastRenderMs = now;
    const sample = sampleAt(activeT);
    updateTerrainHeight(sample);
    const target = targetCameraAt(activeT);
    if (!target) {
      const fallbackInstrumentView = instrumentViewFromSample(sample);
      updateHorizonLine(fallbackInstrumentView);
      updateAttitudeIndicator(fallbackInstrumentView, sample, dtSec, snap);
      updateAirspeedTape(sample, dtSec, snap);
      updateAoaIndicator(sample);
      updateAltimeterTape(sample, dtSec, snap);
      updateHsiOverlay(sample, dtSec, snap);
      updateEnginePanel(sample, dtSec, snap);
      updateInsetMap(sample, snap);
      updateAvionicsHeader(sample);
      updateDebugOverlay(sample, fallbackInstrumentView);
      return;
    }

    let view = target;
    if (!snap && displayCamera && isSyntheticCameraMode(target.mode)) {
      const smoothingRate = cameraCalibration ? cameraCalibration.smoothness : SYNTHETIC_VISION_DEFAULTS.positionSmoothing;
      const posAlpha = smoothFactor(smoothingRate, dtSec);
      const smoothLat = displayCamera.aircraftLat + (target.aircraftLat - displayCamera.aircraftLat) * posAlpha;
      const smoothLon = displayCamera.aircraftLon + (target.aircraftLon - displayCamera.aircraftLon) * posAlpha;
      const smoothAircraftAlt = displayCamera.aircraftAltitudeM + (target.aircraftAltitudeM - displayCamera.aircraftAltitudeM) * posAlpha;
      const smoothVisualAlt = displayCamera.visualAltitudeM + (target.visualAltitudeM - displayCamera.visualAltitudeM) * posAlpha;
      view = Object.assign({}, target, {
        lat: smoothLat,
        lon: smoothLon,
        aircraftLat: smoothLat,
        aircraftLon: smoothLon,
        aircraftAltitudeM: smoothAircraftAlt,
        visualAltitudeM: smoothVisualAlt,
        altitudeM: smoothAircraftAlt + SYNTHETIC_VISION_DEFAULTS.eyeHeightM,
      });
    } else if (!snap && displayCamera && !isSyntheticCameraMode(target.mode)) {
      const rotAlpha = smoothFactor(cameraSettings.smoothing, dtSec);
      const altAlpha = smoothFactor(Math.max(1, cameraSettings.smoothing * 0.55), dtSec);
      view = {
        mode: target.mode,
        lat: target.lat,
        lon: target.lon,
        altitudeM: displayCamera.altitudeM + (target.altitudeM - displayCamera.altitudeM) * altAlpha,
        rawAltitudeM: target.rawAltitudeM,
        visualAltitudeM: displayCamera.visualAltitudeM + (target.visualAltitudeM - displayCamera.visualAltitudeM) * altAlpha,
        groundReferenceAltitudeM: target.groundReferenceAltitudeM,
        groundReferenceSource: target.groundReferenceSource,
        aircraftHeading: target.aircraftHeading,
        heading: lerpAngleDeg(displayCamera.heading, target.heading, rotAlpha),
        pitch: target.pitch,
        roll: target.roll,
        modelYaw: target.modelYaw,
        interpolatedHeading: target.interpolatedHeading,
        interpolatedTrack: target.interpolatedTrack,
      };
    }
    displayCamera = Object.assign({}, view);
    lastVisualAltitudeM = view.visualAltitudeM;
    const cameraPos = isSyntheticCameraMode(view.mode)
      ? { lat: view.lat, lon: view.lon }
      : offsetLatLon(view.lat, view.lon, view.heading, cameraSettings.rangeM, 0);
    const cameraAltitudeM = isSyntheticCameraMode(view.mode)
      ? view.altitudeM
      : view.altitudeM + cameraSettings.heightM;
    if (isSyntheticCameraMode(view.mode)) {
      applySyntheticCameraView(view, sample);
    } else {
      applyWorldCameraView(view, cameraPos, cameraAltitudeM);
    }
    updateHorizonLine(view);
    updateAttitudeIndicator(view, sample, dtSec, snap);
    updateAirspeedTape(sample, dtSec, snap);
    updateAoaIndicator(sample);
    updateAltimeterTape(sample, dtSec, snap);
    updateHsiOverlay(sample, dtSec, snap);
    updateEnginePanel(sample, dtSec, snap);
    updateInsetMap(sample, snap);
    updateAvionicsHeader(sample);
    updateDebugOverlay(sample, view);
  }

  function sampleAt(t) {
    if (!payload || !payload.samples.length) return null;
    const samples = payload.samples;
    if (t <= samples[0].t) return samples[0];
    if (t >= samples[samples.length - 1].t) return samples[samples.length - 1];

    let low = 1;
    let high = samples.length - 1;
    let index = high;
    while (low <= high) {
      const mid = Math.floor((low + high) / 2);
      if (samples[mid].t >= t) {
        index = mid;
        high = mid - 1;
      } else {
        low = mid + 1;
      }
    }
    const before = samples[index - 1];
    const after = samples[index];

    const span = Math.max(0.001, Number(after.t) - Number(before.t));
    const ratio = Math.max(0, Math.min(1, (Number(t) - Number(before.t)) / span));
    const lerp = (a, b) => {
      if (a === null || a === undefined || b === null || b === undefined) return a ?? b ?? null;
      return Number(a) + (Number(b) - Number(a)) * ratio;
    };
    const lerpAngle = (a, b) => {
      if (a === null || a === undefined || b === null || b === undefined) return a ?? b ?? null;
      const start = Number(a);
      const end = Number(b);
      let delta = ((end - start + 540) % 360) - 180;
      return (start + delta * ratio + 360) % 360;
    };

    return Object.assign({}, before, {
      t: Number(t),
      lat: lerp(before.lat, after.lat),
      lon: lerp(before.lon, after.lon),
      altitude_ft: lerp(before.altitude_ft, after.altitude_ft),
      altitude_ft_msl: lerp(before.altitude_ft_msl, after.altitude_ft_msl),
      visual_altitude_ft: lerp(before.visual_altitude_ft, after.visual_altitude_ft),
      gps_altitude_ft: lerp(before.gps_altitude_ft, after.gps_altitude_ft),
      baro_altitude_ft: lerp(before.baro_altitude_ft, after.baro_altitude_ft),
      vertical_speed_fpm: lerp(before.vertical_speed_fpm, after.vertical_speed_fpm),
      adsb_baro_altitude_ft: lerp(before.adsb_baro_altitude_ft, after.adsb_baro_altitude_ft),
      adsb_vertical_speed_fpm: lerp(before.adsb_vertical_speed_fpm, after.adsb_vertical_speed_fpm),
      estimated_baro_altitude_ft: lerp(before.estimated_baro_altitude_ft, after.estimated_baro_altitude_ft),
      estimated_vertical_speed_fpm: lerp(before.estimated_vertical_speed_fpm, after.estimated_vertical_speed_fpm),
      field_calibrated_altitude_ft: lerp(before.field_calibrated_altitude_ft, after.field_calibrated_altitude_ft),
      field_calibrated_true_altitude_ft: lerp(before.field_calibrated_true_altitude_ft, after.field_calibrated_true_altitude_ft),
      estimated_indicated_altitude_ft: lerp(before.estimated_indicated_altitude_ft, after.estimated_indicated_altitude_ft),
      estimated_true_altitude_from_indicated_ft: lerp(before.estimated_true_altitude_from_indicated_ft, after.estimated_true_altitude_from_indicated_ft),
      altimeter_setting_inhg: lerp(before.altimeter_setting_inhg, after.altimeter_setting_inhg),
      altimeter_setting_hpa: lerp(before.altimeter_setting_hpa, after.altimeter_setting_hpa),
      altitude_bug_ft: lerp(before.altitude_bug_ft, after.altitude_bug_ft),
      decision_altitude_ft: lerp(before.decision_altitude_ft, after.decision_altitude_ft),
      da_ft: lerp(before.da_ft, after.da_ft),
      minimums_ft: lerp(before.minimums_ft, after.minimums_ft),
      airport_elevation_ft: lerp(before.airport_elevation_ft, after.airport_elevation_ft),
      field_altitude_offset_ft: lerp(before.field_altitude_offset_ft, after.field_altitude_offset_ft),
      oat_c: lerp(before.oat_c, after.oat_c),
      isa_deviation_c: lerp(before.isa_deviation_c, after.isa_deviation_c),
      estimated_slip_skid_g: lerp(before.estimated_slip_skid_g, after.estimated_slip_skid_g),
      slip_skid_g: lerp(before.slip_skid_g, after.slip_skid_g),
      lateral_acceleration_g: lerp(before.lateral_acceleration_g, after.lateral_acceleration_g),
      normal_acceleration_g: lerp(before.normal_acceleration_g, after.normal_acceleration_g),
      acceleration_g: lerp(before.acceleration_g, after.acceleration_g),
      estimated_wind_speed_kt: lerp(before.estimated_wind_speed_kt, after.estimated_wind_speed_kt),
      estimated_wind_direction_deg_true: lerpAngle(before.estimated_wind_direction_deg_true, after.estimated_wind_direction_deg_true),
      estimated_tas_kt: lerp(before.estimated_tas_kt, after.estimated_tas_kt),
      sel_ias_kt: lerp(before.sel_ias_kt, after.sel_ias_kt),
      sel_vspeed_fpm: lerp(before.sel_vspeed_fpm, after.sel_vspeed_fpm),
      ias_kt: lerp(before.ias_kt, after.ias_kt),
      tas_kt: lerp(before.tas_kt, after.tas_kt),
      aoa: lerp(before.aoa, after.aoa),
      aoa_cp: lerp(before.aoa_cp, after.aoa_cp),
      groundspeed_kt: lerp(before.groundspeed_kt, after.groundspeed_kt),
      pitch_deg: lerp(before.pitch_deg, after.pitch_deg),
      roll_deg: lerp(before.roll_deg, after.roll_deg),
      visual_pitch_deg: lerp(before.visual_pitch_deg, after.visual_pitch_deg),
      visual_roll_deg: lerp(before.visual_roll_deg, after.visual_roll_deg),
      raw_pitch_deg: lerp(before.raw_pitch_deg, after.raw_pitch_deg),
      raw_roll_deg: lerp(before.raw_roll_deg, after.raw_roll_deg),
      bank_deg: lerp(before.bank_deg, after.bank_deg),
      heading_deg: lerpAngle(before.heading_deg, after.heading_deg),
      heading_deg_true: lerpAngle(before.heading_deg_true, after.heading_deg_true),
      heading_deg_magnetic: lerpAngle(before.heading_deg_magnetic, after.heading_deg_magnetic),
      heading_bug_deg: lerpAngle(before.heading_bug_deg, after.heading_bug_deg),
      nav_course_deg: lerpAngle(before.nav_course_deg, after.nav_course_deg),
      nav_bearing_deg: lerpAngle(before.nav_bearing_deg, after.nav_bearing_deg),
      velocity_e_mps: lerp(before.velocity_e_mps, after.velocity_e_mps),
      velocity_n_mps: lerp(before.velocity_n_mps, after.velocity_n_mps),
      velocity_u_mps: lerp(before.velocity_u_mps, after.velocity_u_mps),
      nav_xtk_nm: lerp(before.nav_xtk_nm, after.nav_xtk_nm),
      nav_distance_nm: lerp(before.nav_distance_nm, after.nav_distance_nm),
      hcdi: lerp(before.hcdi, after.hcdi),
      hcdi_full_scale_ft: lerp(before.hcdi_full_scale_ft, after.hcdi_full_scale_ft),
      hcdi_scale: lerp(before.hcdi_scale, after.hcdi_scale),
      vcdi: lerp(before.vcdi, after.vcdi),
      vcdi_full_scale_ft: lerp(before.vcdi_full_scale_ft, after.vcdi_full_scale_ft),
      vnav_cdi: lerp(before.vnav_cdi, after.vnav_cdi),
      vnav_altitude_ft: lerp(before.vnav_altitude_ft, after.vnav_altitude_ft),
      density_altitude_ft: lerp(before.density_altitude_ft, after.density_altitude_ft),
      height_agl_ft: lerp(before.height_agl_ft, after.height_agl_ft),
      wind_speed_kt: lerp(before.wind_speed_kt, after.wind_speed_kt),
      wind_direction_deg: lerpAngle(before.wind_direction_deg, after.wind_direction_deg),
      elevator_trim_pct: lerp(before.elevator_trim_pct, after.elevator_trim_pct),
      fd_roll_command_deg: lerp(before.fd_roll_command_deg, after.fd_roll_command_deg),
      fd_pitch_command_deg: lerp(before.fd_pitch_command_deg, after.fd_pitch_command_deg),
      fd_altitude_ft: lerp(before.fd_altitude_ft, after.fd_altitude_ft),
      ap_roll_command_deg: lerp(before.ap_roll_command_deg, after.ap_roll_command_deg),
      ap_pitch_command_deg: lerp(before.ap_pitch_command_deg, after.ap_pitch_command_deg),
      ap_vs_command_fpm: lerp(before.ap_vs_command_fpm, after.ap_vs_command_fpm),
      ap_altitude_command_ft: lerp(before.ap_altitude_command_ft, after.ap_altitude_command_ft),
      ap_roll_torque_pct: lerp(before.ap_roll_torque_pct, after.ap_roll_torque_pct),
      ap_pitch_torque_pct: lerp(before.ap_pitch_torque_pct, after.ap_pitch_torque_pct),
      com1_mhz: lerp(before.com1_mhz, after.com1_mhz),
      com1_standby_mhz: lerp(before.com1_standby_mhz, after.com1_standby_mhz),
      com2_mhz: lerp(before.com2_mhz, after.com2_mhz),
      com2_standby_mhz: lerp(before.com2_standby_mhz, after.com2_standby_mhz),
      nav2_mhz: lerp(before.nav2_mhz, after.nav2_mhz),
      nav2_standby_mhz: lerp(before.nav2_standby_mhz, after.nav2_standby_mhz),
      true_heading_deg: lerpAngle(before.true_heading_deg, after.true_heading_deg),
      camera_heading_deg: lerpAngle(before.camera_heading_deg, after.camera_heading_deg),
      magnetic_variation_deg: lerp(before.magnetic_variation_deg, after.magnetic_variation_deg),
      track_deg: lerpAngle(before.track_deg, after.track_deg),
      track_deg_true: lerpAngle(before.track_deg_true, after.track_deg_true),
      wind_direction_deg_true: lerpAngle(before.wind_direction_deg_true, after.wind_direction_deg_true),
      crab_angle_deg: lerp(before.crab_angle_deg, after.crab_angle_deg),
    });
  }

  function updateTerrainHeight(sample) {
    if (!terrainEnabled || !cesiumViewer || !sample || typeof Cesium === 'undefined') return;
    const lat = Number(sample.lat);
    const lon = Number(sample.lon);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;
    const now = performance.now();
    const key = `${lat.toFixed(4)},${lon.toFixed(4)}`;
    if (now - lastTerrainSampleMs < 2000 && key === lastTerrainRequestKey) return;
    lastTerrainSampleMs = now;
    lastTerrainRequestKey = key;
    const cartographic = Cesium.Cartographic.fromDegrees(lon, lat);
    Cesium.sampleTerrainMostDetailed(cesiumViewer.terrainProvider, [cartographic])
      .then((updated) => {
        if (updated && updated[0] && Number.isFinite(updated[0].height)) {
          lastTerrainHeightM = updated[0].height;
        }
      })
      .catch(() => {
        terrainStatus = 'height_unavailable';
      });
  }

  function updateDebugOverlay(sample, view) {
    if (!debugOverlay) return;
    const headingDegTrue = firstFinite(sample && sample.heading_deg_true, sample && sample.true_heading_deg);
    const headingLegacy = firstFinite(sample && sample.heading_deg);
    const heading = sample ? aircraftHeadingFromSample(sample) : null;
    const interpolatedHeading = view && Number.isFinite(Number(view.interpolatedHeading)) ? normalizeDeg(Number(view.interpolatedHeading)) : heading;
    const trackDegTrue = firstFinite(sample && sample.track_deg_true);
    const track = trackDegTrue !== null ? normalizeDeg(trackDegTrue) : null;
    const interpolatedTrack = view && Number.isFinite(Number(view.interpolatedTrack)) ? normalizeDeg(Number(view.interpolatedTrack)) : track;
    const crab = sample && Number.isFinite(Number(sample.crab_angle_deg)) ? normalizeSignedDeg(Number(sample.crab_angle_deg)) : null;
    const slipSkid = sample && Number.isFinite(Number(sample.estimated_slip_skid_g ?? sample.slip_skid_g)) ? Number(sample.estimated_slip_skid_g ?? sample.slip_skid_g) : null;
    const cameraHeading = view && Number.isFinite(Number(view.heading)) ? normalizeDeg(Number(view.heading)) : null;
    const legacyCameraHeading = sample && Number.isFinite(Number(sample.camera_heading_deg)) ? normalizeDeg(Number(sample.camera_heading_deg)) : null;
    const modelYaw = view && Number.isFinite(Number(view.modelYaw)) ? normalizeDeg(Number(view.modelYaw)) : null;
    const cameraPitch = view && Number.isFinite(Number(view.pitch)) ? Number(view.pitch) : null;
    const pitch = sample && Number.isFinite(Number(sample.pitch_deg)) ? Number(sample.pitch_deg) : null;
    const roll = sample && Number.isFinite(Number(sample.bank_deg ?? sample.roll_deg)) ? Number(sample.bank_deg ?? sample.roll_deg) : null;
    const visualPitch = sample && Number.isFinite(Number(sample.visual_pitch_deg)) ? Number(sample.visual_pitch_deg) : null;
    const visualRoll = sample && Number.isFinite(Number(sample.visual_roll_deg)) ? Number(sample.visual_roll_deg) : null;
    const altitudeFt = sample && Number.isFinite(Number(sample.altitude_ft_msl ?? sample.altitude_ft)) ? Number(sample.altitude_ft_msl ?? sample.altitude_ft) : null;
    const visualFt = Number.isFinite(lastVisualAltitudeM) ? lastVisualAltitudeM / 0.3048 : null;
    const vs = sample && Number.isFinite(Number(sample.vertical_speed_fpm)) ? Number(sample.vertical_speed_fpm) : null;
    const aoa = firstFinite(sample && sample.aoa, sample && sample.angle_of_attack_deg);
    const aoaCp = firstFinite(sample && sample.aoa_cp);
    const aoaVisibleThreshold = aoaProfileNumber('visible_threshold', 0.35);
    const aoaCautionThreshold = aoaProfileNumber('caution_threshold', 0.55);
    const aoaWarningThreshold = aoaProfileNumber('warning_threshold', 0.75);
    const aoaStallThreshold = aoaProfileNumber('stall_threshold', 1.0);
    const debugNavCourse = sample ? hsiCourseFromSample(sample) : null;
    const debugNavBearing = sample ? hsiNavBearingFromSample(sample) : null;
    const debugNavSource = sample ? hsiRawNavSourceFromSample(sample) || hsiNavSourceFromSample(sample) : '';
    const debugHcdi = firstFinite(sample && sample.hcdi, sample && sample.horizontal_cdi_deflection, sample && sample.nav_cdi);
    const dbg = currentCameraDebug || {};
    const debugFpvReferenceHeadingDeg = firstFinite(dbg.headingDegUsed, view && view.heading);
    const debugFpv = sample ? fpvVectorFromSample(sample, pitch === null ? 0 : pitch, debugFpvReferenceHeadingDeg) : null;
    const terrain = Number.isFinite(lastTerrainHeightM) ? `${lastTerrainHeightM.toFixed(1)} m` : '--';
    const qualityRows = [
      ['position', 'position_quality', 'position_source', 'position_quality_reason'],
      ['altitude', 'altitude_quality', 'altitude_source', 'altitude_quality_reason'],
      ['attitude', 'attitude_quality', 'raw_attitude_source', 'attitude_quality_reason'],
      ['heading', 'heading_quality', 'heading_source', 'heading_quality_reason'],
      ['track', 'track_quality', 'track_source', 'track_quality_reason'],
      ['speed', 'speed_quality', 'speed_source', 'speed_quality_reason'],
    ].map(([label, qualityField, sourceField]) => {
      const quality = qualityValue(sample, qualityField);
      const source = sourceValue(sample, sourceField);
      const suffix = source;
      return `<div class="replay-debug-quality-row"><span>${escapeHtml(label)}</span><span class="${qualityClass(quality)}">${escapeHtml(quality)}${suffix ? ` <span class="replay-quality-unknown">(${escapeHtml(suffix)})</span>` : ''}</span></div>`;
    }).join('');
    const movement = dbg.movementDebug || {};
    const fmtNum = (value, digits = 1) => Number.isFinite(Number(value)) ? Number(value).toFixed(digits) : '--';
    const cameraConfigLines = isSyntheticCameraMode()
      ? [
        `camera method: ${dbg.method || '--'}`,
        `synthetic eye: +${SYNTHETIC_VISION_DEFAULTS.eyeHeightM.toFixed(1)} m`,
        `synthetic forward: ${SYNTHETIC_VISION_DEFAULTS.forwardOffsetM.toFixed(1)} m`,
        `synthetic right: ${SYNTHETIC_VISION_DEFAULTS.rightOffsetM.toFixed(1)} m`,
        `camera attached: ${cameraMode === 'synthetic_vision' ? 'aircraft state' : 'forced test attitude'}`,
        `camera smoothing: visual position only (${fmtNum(cameraCalibration && cameraCalibration.smoothness, 1)})`,
        `SVT FOV: ${fmtNum(dbg.horizontalFovDeg, 0)} deg H / ${fmtNum(dbg.verticalFovDeg, 1)} deg V`,
        `SVT aspect: ${fmtNum(dbg.viewportAspectRatio, 2)}`,
        `SVT Cesium set FOV: ${fmtNum(dbg.cesiumFovDegToSet, 1)} deg ${dbg.cesiumFovAxis || '--'}`,
        `SVT active frustum: ${fmtNum(dbg.activeCesiumFovDeg, 1)} deg ${dbg.activeCesiumFovAxis || '--'}`,
        `SVT active H/V: ${fmtNum(dbg.activeHorizontalFovDeg, 1)} deg H / ${fmtNum(dbg.activeVerticalFovDeg, 1)} deg V`,
        `SVT active aspect: ${fmtNum(dbg.activeCesiumAspectRatio, 2)}`,
        `SVT yaw bias: ${SYNTHETIC_VISION_DEFAULTS.yawBiasDeg.toFixed(1)} deg`,
        `SVT roll bias: ${SYNTHETIC_VISION_DEFAULTS.rollBiasDeg.toFixed(1)} deg`,
      ]
      : [
        `camera method: ${dbg.method || '--'}`,
        `camera range: ${cameraSettings.rangeM.toFixed(0)} m`,
        `camera height: +${cameraSettings.heightM.toFixed(0)} m`,
        `camera smoothing: ${cameraSettings.smoothing.toFixed(1)}`,
      ];
    const lines = [
      `t: ${sample ? Number(sample.t || 0).toFixed(1) : '--'} s`,
      `heading_deg_true: ${headingDegTrue === null ? '--' : normalizeDeg(headingDegTrue).toFixed(1)} deg`,
      `heading_deg legacy: ${headingLegacy === null ? '--' : normalizeDeg(headingLegacy).toFixed(1)} deg`,
      `track_deg_true: ${track === null ? '--' : track.toFixed(1)} deg`,
      `crab angle: ${crab === null ? '--' : crab.toFixed(1)} deg`,
      `current interpolated heading: ${interpolatedHeading === null ? '--' : interpolatedHeading.toFixed(1)} deg`,
      `current interpolated track: ${interpolatedTrack === null ? '--' : interpolatedTrack.toFixed(1)} deg`,
      `camera_heading_deg legacy: ${legacyCameraHeading === null ? '--' : legacyCameraHeading.toFixed(1)} deg`,
      `camera heading rendered: ${cameraHeading === null ? '--' : cameraHeading.toFixed(1)} deg`,
      `model_yaw_deg: ${modelYaw === null ? '--' : modelYaw.toFixed(1)} deg`,
      `heading_owner: ${sourceValue(sample, 'heading_owner') || '--'}`,
      `heading_source: ${sourceValue(sample, 'heading_source') || '--'}`,
      `track_source: ${sourceValue(sample, 'track_source') || '--'}`,
      `camera pitch: ${cameraPitch === null ? '--' : cameraPitch.toFixed(1)} deg`,
      `camera mode: ${cameraMode}`,
      ...cameraConfigLines,
      `aircraft lat/lon: ${fmtNum(dbg.aircraftLat, 7)}, ${fmtNum(dbg.aircraftLon, 7)}`,
      `aircraft altitude_ft: ${fmtNum(firstFinite(sample && sample.altitude_ft, dbg.aircraftAltitudeFt), 1)}`,
      `raw Garmin altitude_ft: ${fmtNum(dbg.rawGarminAltitudeFt, 1)}`,
      `raw Garmin altitude_m: ${fmtNum(dbg.rawGarminAltitudeM, 2)}`,
      `is ground sample: ${dbg.isGroundSample === true ? 'yes' : (dbg.isGroundSample === false ? 'no' : '--')}`,
      `altitude source: ${dbg.altitudeSource || '--'}`,
      `aircraft altitude_m: ${fmtNum(dbg.aircraftAltitudeM, 2)}`,
      `terrain height_m: ${fmtNum(dbg.terrainHeightM, 2)}`,
      `ground reference_m: ${fmtNum(dbg.groundReferenceAltitudeM, 2)}`,
      `ground reference source: ${dbg.groundReferenceSource || '--'}`,
      `Garmin altitude_m target: ${fmtNum(dbg.garminAltitudeM, 2)}`,
      `local visual offset_m: ${fmtNum(dbg.localVisualAltitudeOffsetM, 2)}`,
      `local visual offset source: ${dbg.localVisualAltitudeOffsetSource || '--'}`,
      `altitude curve preserved: ${dbg.altitudeCurvePreserved === true ? 'yes' : (dbg.altitudeCurvePreserved === false ? 'no' : '--')}`,
      `camera lat/lon: ${fmtNum(dbg.cameraLat, 7)}, ${fmtNum(dbg.cameraLon, 7)}`,
      `camera height_m: ${fmtNum(dbg.cameraHeightM, 2)}`,
      `final aircraft altitude_m: ${fmtNum(dbg.aircraftAltitudeM, 2)}`,
      `final camera altitude_m: ${fmtNum(dbg.finalCameraAltitudeM, 2)}`,
      `camera above aircraft_m: ${fmtNum(dbg.cameraHeightAboveAircraftM, 2)}`,
      `calibration up_m: ${fmtNum(dbg.calibrationUpM, 2)}`,
      `synthetic eye up_m: ${fmtNum(dbg.syntheticEyeUpM, 2)}`,
      `total vertical offset_m: ${fmtNum(dbg.totalVerticalOffsetM, 2)}`,
      `camera AGL corrected_m: ${fmtNum(dbg.cameraHeightAboveTerrainM, 2)}`,
      `camera AGL Cesium terrain_m: ${fmtNum(dbg.cameraHeightAboveCesiumTerrainM, 2)}`,
      `aircraft move: ${fmtNum(movement.aircraftMoveM, 2)} m @ ${fmtNum(movement.aircraftMoveBearingDeg, 1)} deg`,
      `camera move: ${fmtNum(movement.cameraMoveM, 2)} m @ ${fmtNum(movement.cameraMoveBearingDeg, 1)} deg`,
      `aircraft Cartesian: ${cartesianToDebug(dbg.aircraftCartesian)}`,
      `camera Cartesian: ${cartesianToDebug(dbg.cameraCartesian)}`,
      `camera direction: ${cartesianToDebug(dbg.cameraDirection)}`,
      `camera up: ${cartesianToDebug(dbg.cameraUp)}`,
      `orientation quaternion: ${quaternionToDebug(dbg.orientationQuaternion)}`,
      `body axes: ${dbg.bodyAxisMapping || '--'}`,
      `heading source for camera: ${dbg.headingSourceForCamera || '--'}`,
      `heading before calibration: ${fmtNum(dbg.headingDegBeforeCalibration, 1)} deg`,
      `heading true/magnetic/legacy: ${fmtNum(dbg.headingDegTrue, 1)} / ${fmtNum(dbg.headingDegMagnetic, 1)} / ${fmtNum(dbg.headingDegLegacy, 1)} deg`,
      `heading reference: ${dbg.headingReference || '--'}`,
      `mag variation: ${fmtNum(dbg.magneticVariationDeg, 1)} deg`,
      `yaw calibration: ${fmtNum(dbg.yawCalibrationDeg, 1)} deg`,
      `total yaw offset applied: ${fmtNum(dbg.totalYawOffsetDeg, 1)} deg`,
      `heading used by camera: ${fmtNum(dbg.headingDegUsed, 1)} deg`,
      `pitch used by camera: ${fmtNum(dbg.pitchDegUsed, 1)} deg`,
      `roll used by camera: ${fmtNum(dbg.rollDegUsed, 1)} deg`,
      `raw Garmin roll_deg: ${fmtNum(dbg.rawGarminRollDeg, 1)} deg`,
      `visual camera roll_deg: ${fmtNum(dbg.visualCameraRollDeg, 1)} deg`,
      `visual roll inverted: ${dbg.visualRollInverted === true ? 'yes' : (dbg.visualRollInverted === false ? 'no' : '--')}`,
      `roll convention: ${dbg.rollConvention || '--'}`,
      `Cesium heading: ${fmtNum(dbg.cesiumHeadingDeg, 1)} deg / ${fmtNum(dbg.cesiumHeadingRad, 4)} rad`,
      `Cesium pitch: ${fmtNum(dbg.cesiumPitchDeg, 1)} deg / ${fmtNum(dbg.cesiumPitchRad, 4)} rad`,
      `Cesium roll: ${fmtNum(dbg.cesiumRollDeg, 1)} deg / ${fmtNum(dbg.cesiumRollRad, 4)} rad`,
      `pitch: ${pitch === null ? '--' : pitch.toFixed(1)} deg`,
      `roll/bank: ${roll === null ? '--' : roll.toFixed(1)} deg`,
      `visual pitch: ${visualPitch === null ? '--' : visualPitch.toFixed(1)} deg`,
      `visual roll: ${visualRoll === null ? '--' : visualRoll.toFixed(1)} deg`,
      `altitude MSL: ${altitudeFt === null ? '--' : altitudeFt.toFixed(1)} ft`,
      `visual altitude: ${visualFt === null ? '--' : visualFt.toFixed(1)} ft`,
      `vertical speed: ${vs === null ? '--' : vs.toFixed(1)} fpm`,
      `terrain enabled: ${terrainEnabled ? 'yes' : 'no'} (${terrainStatus})`,
      `terrain under aircraft: ${terrain}`,
    ];
    const renderDebugLine = (line, className = '') => {
      const idx = String(line).indexOf(':');
      const label = idx >= 0 ? line.slice(0, idx) : line;
      const value = idx >= 0 ? line.slice(idx + 1).trim() : '';
      return `<div class="replay-debug-line ${className}"><span>${escapeHtml(label)}</span><span>${escapeHtml(value)}</span></div>`;
    };
    const renderDebugSection = (title, sectionLines, className = '') => (
      `<div class="replay-debug-section"><div class="replay-debug-section-title">${escapeHtml(title)}</div>${sectionLines.map((line) => renderDebugLine(line, className)).join('')}</div>`
    );
    const cameraSteeringLines = [
      `camera mode: ${cameraMode}`,
      ...cameraConfigLines,
      `heading source for camera: ${dbg.headingSourceForCamera || '--'}`,
      `heading before calibration: ${fmtNum(dbg.headingDegBeforeCalibration, 1)} deg`,
      `yaw calibration: ${fmtNum(dbg.yawCalibrationDeg, 1)} deg`,
      `total yaw offset applied: ${fmtNum(dbg.totalYawOffsetDeg, 1)} deg`,
      `heading used by camera: ${fmtNum(dbg.headingDegUsed, 1)} deg`,
      `pitch used by camera: ${fmtNum(dbg.pitchDegUsed, 1)} deg`,
      `roll used by camera: ${fmtNum(dbg.rollDegUsed, 1)} deg`,
      `camera direction: ${cartesianToDebug(dbg.cameraDirection)}`,
      `camera up: ${cartesianToDebug(dbg.cameraUp)}`,
    ];
    const g3xDataLines = [
      `heading_deg_true: ${headingDegTrue === null ? '--' : normalizeDeg(headingDegTrue).toFixed(1)} deg`,
      `heading_deg legacy: ${headingLegacy === null ? '--' : normalizeDeg(headingLegacy).toFixed(1)} deg`,
      `heading true/magnetic/legacy: ${fmtNum(dbg.headingDegTrue, 1)} / ${fmtNum(dbg.headingDegMagnetic, 1)} / ${fmtNum(dbg.headingDegLegacy, 1)} deg`,
      `heading reference: ${dbg.headingReference || '--'}`,
      `mag variation: ${fmtNum(dbg.magneticVariationDeg, 1)} deg`,
      `track_deg_true: ${track === null ? '--' : track.toFixed(1)} deg`,
      `crab angle: ${crab === null ? '--' : crab.toFixed(1)} deg`,
      `raw Garmin altitude_ft: ${fmtNum(dbg.rawGarminAltitudeFt, 1)}`,
      `raw Garmin altitude_m: ${fmtNum(dbg.rawGarminAltitudeM, 2)}`,
      `raw Garmin roll_deg: ${fmtNum(dbg.rawGarminRollDeg, 1)} deg`,
      `pitch: ${pitch === null ? '--' : pitch.toFixed(1)} deg`,
      `roll/bank: ${roll === null ? '--' : roll.toFixed(1)} deg`,
      `AOA: ${aoa === null ? '--' : aoa.toFixed(4)}`,
      `AOA Cp: ${aoaCp === null ? '--' : aoaCp.toFixed(4)}`,
      `AOA thresholds: visible ${fmtNum(aoaVisibleThreshold, 4)} / caution ${fmtNum(aoaCautionThreshold, 4)} / warning ${fmtNum(aoaWarningThreshold, 4)} / stall ${fmtNum(aoaStallThreshold, 4)}`,
      `NavSrc: ${debugNavSource || '--'}`,
      `NavCRS/course: ${debugNavCourse === null ? '--' : normalizeDeg(debugNavCourse).toFixed(1)} deg`,
      `NavBrg/RMI: ${debugNavBearing === null ? '--' : normalizeDeg(debugNavBearing).toFixed(1)} deg`,
      `HCDI: ${debugHcdi === null ? '--' : debugHcdi.toFixed(3)}`,
      `FPV ref/display heading: ${debugFpv ? `${debugFpv.headingReference} / ${debugFpv.fpvHeadingDeg.toFixed(1)} deg` : '--'}`,
      `FPV true velocity/track: ${debugFpv ? `${debugFpv.velocityHeadingTrueDeg === null ? '--' : debugFpv.velocityHeadingTrueDeg.toFixed(1)} / ${debugFpv.trackTrueDeg === null ? '--' : debugFpv.trackTrueDeg.toFixed(1)} deg` : '--'}`,
      `FPV true heading/pitch: ${debugFpv ? `${debugFpv.fpvHeadingTrueDeg.toFixed(1)} / ${debugFpv.fpvPitchDeg.toFixed(1)} deg` : '--'}`,
      `FPV drift/display: ${debugFpv ? `${debugFpv.headingDeltaDeg.toFixed(1)} / ${debugFpv.displayHeadingDeltaDeg.toFixed(1)} deg` : '--'}`,
      `FPV aircraft heading/ref: ${debugFpv ? `${debugFpv.aircraftHeadingDeg.toFixed(1)} deg / ${debugFpv.headingReference}` : '--'}`,
      `FPV magnetic variation: ${debugFpv && debugFpv.magneticVariationDeg !== null ? `${Number(debugFpv.magneticVariationDeg).toFixed(1)} deg` : '--'}`,
      `slip/skid: ${slipSkid === null ? '--' : slipSkid.toFixed(3)} g`,
      `slip/skid source: ${sourceValue(sample, 'estimated_slip_skid_source') || '--'}`,
    ];
    const replayStateLines = [
      `t: ${sample ? Number(sample.t || 0).toFixed(1) : '--'} s`,
      `current interpolated heading: ${interpolatedHeading === null ? '--' : interpolatedHeading.toFixed(1)} deg`,
      `current interpolated track: ${interpolatedTrack === null ? '--' : interpolatedTrack.toFixed(1)} deg`,
      `camera_heading_deg legacy: ${legacyCameraHeading === null ? '--' : legacyCameraHeading.toFixed(1)} deg`,
      `camera heading rendered: ${cameraHeading === null ? '--' : cameraHeading.toFixed(1)} deg`,
      `model_yaw_deg: ${modelYaw === null ? '--' : modelYaw.toFixed(1)} deg`,
      `heading_owner: ${sourceValue(sample, 'heading_owner') || '--'}`,
      `heading_source: ${sourceValue(sample, 'heading_source') || '--'}`,
      `track_source: ${sourceValue(sample, 'track_source') || '--'}`,
      `altitude MSL: ${altitudeFt === null ? '--' : altitudeFt.toFixed(1)} ft`,
      `visual altitude: ${visualFt === null ? '--' : visualFt.toFixed(1)} ft`,
      `vertical speed: ${vs === null ? '--' : vs.toFixed(1)} fpm`,
    ];
    const geometryLines = [
      `aircraft lat/lon: ${fmtNum(dbg.aircraftLat, 7)}, ${fmtNum(dbg.aircraftLon, 7)}`,
      `camera lat/lon: ${fmtNum(dbg.cameraLat, 7)}, ${fmtNum(dbg.cameraLon, 7)}`,
      `aircraft altitude_m: ${fmtNum(dbg.aircraftAltitudeM, 2)}`,
      `final camera altitude_m: ${fmtNum(dbg.finalCameraAltitudeM, 2)}`,
      `camera above aircraft_m: ${fmtNum(dbg.cameraHeightAboveAircraftM, 2)}`,
      `camera AGL corrected_m: ${fmtNum(dbg.cameraHeightAboveTerrainM, 2)}`,
      `terrain enabled: ${terrainEnabled ? 'yes' : 'no'} (${terrainStatus})`,
      `terrain under aircraft: ${terrain}`,
      `aircraft move: ${fmtNum(movement.aircraftMoveM, 2)} m @ ${fmtNum(movement.aircraftMoveBearingDeg, 1)} deg`,
      `camera move: ${fmtNum(movement.cameraMoveM, 2)} m @ ${fmtNum(movement.cameraMoveBearingDeg, 1)} deg`,
      `aircraft Cartesian: ${cartesianToDebug(dbg.aircraftCartesian)}`,
      `camera Cartesian: ${cartesianToDebug(dbg.cameraCartesian)}`,
      `orientation quaternion: ${quaternionToDebug(dbg.orientationQuaternion)}`,
    ];
    debugOverlay.innerHTML = `<div class="replay-calibration-title"><span>Replay Debug</span></div><div class="replay-debug-grid">${
      renderDebugSection('Camera Steering', cameraSteeringLines, 'is-camera-used')
    }${
      renderDebugSection('G3X / Garmin Data', g3xDataLines, 'is-g3x-data')
    }${
      renderDebugSection('Replay State', replayStateLines)
    }${
      renderDebugSection('Geometry / Terrain', geometryLines)
    }</div><div class="replay-debug-quality">${qualityRows}</div>`;
  }

  async function initCesium() {
    try {
      const cesiumReplay = document.getElementById('cesiumReplay');
      if (cesiumReady || !cesiumReplay || !payload) return;
      if (!cesiumToken || typeof Cesium === 'undefined') {
        showCesiumError('Cesium token not configured.');
        return;
      }
      const gpsSamples = payload.samples.filter((s) => s.lat !== null && s.lon !== null);
      if (!gpsSamples.length) {
        showCesiumError('No GPS samples available for Cesium replay.');
        return;
      }

      Cesium.Ion.defaultAccessToken = cesiumToken;
      let startupTerrain = null;
      if (Cesium.Terrain && typeof Cesium.Terrain.fromWorldTerrain === 'function') {
        startupTerrain = Cesium.Terrain.fromWorldTerrain({
          requestVertexNormals: true,
          requestWaterMask: true,
        });
      }
      cesiumViewer = new Cesium.Viewer(cesiumReplay, {
        animation: false,
        baseLayerPicker: false,
        fullscreenButton: false,
        geocoder: false,
        homeButton: false,
        infoBox: false,
        navigationHelpButton: false,
        sceneModePicker: false,
        selectionIndicator: false,
        timeline: false,
        shouldAnimate: false,
        ...(startupTerrain ? { terrain: startupTerrain } : {}),
      });

      try {
        if (!startupTerrain && typeof Cesium.createWorldTerrainAsync === 'function') {
          cesiumViewer.terrainProvider = await Cesium.createWorldTerrainAsync({
            requestVertexNormals: true,
            requestWaterMask: true,
          });
        } else if (!startupTerrain && typeof Cesium.createWorldTerrain === 'function') {
          cesiumViewer.terrainProvider = Cesium.createWorldTerrain({
            requestVertexNormals: true,
            requestWaterMask: true,
          });
        } else if (!startupTerrain) {
          throw new Error('World terrain API is unavailable in this Cesium build.');
        }
        terrainEnabled = true;
        terrainStatus = 'enabled';
      } catch (terrainErr) {
        terrainEnabled = false;
        terrainStatus = 'ellipsoid_fallback';
        terrainWarningMessage = 'Cesium terrain failed to load. Using ellipsoid.';
        if (terrainWarning) {
          terrainWarning.textContent = terrainWarningMessage;
          terrainWarning.hidden = false;
        }
      }
      cesiumViewer.scene.globe.depthTestAgainstTerrain = false;
      const controller = cesiumViewer.scene.screenSpaceCameraController;
      controller.enableCollisionDetection = false;
      applyCameraModeControls();
      if (cesiumViewer.cesiumWidget && cesiumViewer.cesiumWidget.creditContainer) {
        cesiumViewer.cesiumWidget.creditContainer.style.display = 'none';
      }

      cesiumReady = true;
      renderCesium(true);
    } catch (err) {
      showCesiumError(String(err.message || err));
    }
  }

  function showCesiumError(message) {
    const cesiumReplay = document.getElementById('cesiumReplay');
    if (!cesiumReplay) return;
    cesiumReplay.insertAdjacentHTML('beforeend', `<div class="cesium-unavailable"><div><strong>Cesium could not start.</strong><br>${String(message).replace(/[<>&]/g, (ch) => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[ch]))}</div></div>`);
  }

  function safeRenderCesium(snap = false) {
    try {
      renderCesium(snap);
    } catch (err) {
      showCesiumError(String(err.message || err));
      cesiumReady = false;
    }
  }

  function seek(seconds, syncAudio, forceSnap = false) {
    const previousT = activeT;
    activeT = Math.max(0, Number(seconds) || 0);
    if (standaloneReplay && standalonePlaying) {
      standaloneStartedMs = performance.now();
      standaloneStartedT = activeT;
    } else if (visualFallbackPlaying) {
      visualFallbackStartedMs = performance.now();
      visualFallbackStartedT = activeT;
    }
    const snap = forceSnap || Math.abs(activeT - previousT) > CAMERA_SNAP_SEEK_SEC;
    if (snap) {
      resetDisplayCamera();
    }
    timeline.value = String(activeT);
    timeLabel.textContent = fmtTime(activeT);
    if (!standaloneReplay && syncAudio && Number.isFinite(audio.duration)) {
      audio.currentTime = Math.min(activeT, audio.duration || activeT);
    }
    safeRenderCesium(snap);
  }

  function updateCockpitPlayback() {
    const maxT = Number(timeline.max) || 0;
    if (standaloneReplay) {
      const elapsed = Math.max(0, (performance.now() - standaloneStartedMs) / 1000);
      activeT = Math.max(0, Math.min(maxT, standaloneStartedT + elapsed));
      if (activeT >= maxT) {
        standalonePlaying = false;
        playButton.textContent = 'Play';
      }
    } else if (visualFallbackPlaying) {
      const elapsed = Math.max(0, (performance.now() - visualFallbackStartedMs) / 1000);
      activeT = Math.max(0, Math.min(maxT, visualFallbackStartedT + elapsed));
      if (activeT >= maxT) {
        visualFallbackPlaying = false;
        playButton.textContent = 'Play';
      }
    } else {
      activeT = Math.max(0, Math.min(maxT, Number.isFinite(Number(audio.currentTime)) ? Number(audio.currentTime) : activeT));
    }
    timeline.value = String(activeT);
    timeLabel.textContent = fmtTime(activeT);
    safeRenderCesium(false);
  }

  function startVisualFallbackPlayback() {
    visualFallbackPlaying = true;
    visualFallbackStartedMs = performance.now();
    visualFallbackStartedT = activeT;
    playButton.textContent = 'Pause';
    lastRenderMs = null;
    if (animationFrame === null) {
      animationFrame = requestAnimationFrame(animatePlayback);
    }
  }

  function togglePlayback() {
    if (standaloneReplay) {
      standalonePlaying = !standalonePlaying;
      playButton.textContent = standalonePlaying ? 'Pause' : 'Play';
      if (standalonePlaying) {
        standaloneStartedMs = performance.now();
        standaloneStartedT = activeT;
        lastRenderMs = null;
        if (animationFrame === null) {
          animationFrame = requestAnimationFrame(animatePlayback);
        }
      } else if (animationFrame !== null) {
        cancelAnimationFrame(animationFrame);
        animationFrame = null;
      }
      return;
    }
    if (visualFallbackPlaying) {
      visualFallbackPlaying = false;
      if (animationFrame !== null && audio.paused) {
        cancelAnimationFrame(animationFrame);
        animationFrame = null;
      }
      playButton.textContent = 'Play';
      return;
    }
    if (audio.paused) {
      let playPromise = null;
      try {
        playPromise = audio.play();
      } catch (err) {
        startVisualFallbackPlayback();
        return;
      }
      playButton.textContent = 'Pause';
      if (animationFrame === null) {
        animationFrame = requestAnimationFrame(animatePlayback);
      }
      if (playPromise && typeof playPromise.catch === 'function') {
        playPromise.catch(() => {
          startVisualFallbackPlayback();
        });
      } else {
        window.setTimeout(() => {
          if (audio.paused && !visualFallbackPlaying) startVisualFallbackPlayback();
        }, 150);
      }
    } else {
      audio.pause();
      visualFallbackPlaying = false;
      playButton.textContent = 'Play';
    }
  }

  function skipBy(deltaSeconds) {
    const maxT = Number(timeline.max) || 0;
    seek(Math.max(0, Math.min(maxT, activeT + deltaSeconds)), !standaloneReplay, true);
  }

  function playFromInsetMap() {
    if (standaloneReplay) {
      if (!standalonePlaying) {
        togglePlayback();
      }
      return;
    }
    if (audio.paused && !visualFallbackPlaying) {
      togglePlayback();
    }
  }

  function setInsetMapZoom(nextZoom) {
    insetMapZoom = clamp(Number(nextZoom) || 1, 1, 16);
    insetMapSignature = '';
    updateInsetMap(sampleAt(activeT), true);
  }

  function startInsetMapPan(event) {
    if (!insetMapProjection || !insetMapProjection.projector || !insetMapProjection.projector.scale) return;
    if (event.button !== undefined && event.button !== 0) return;
    event.preventDefault();
    event.stopPropagation();
    suppressInsetMapClick = false;
    insetMapDragState = {
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      lastX: event.clientX,
      lastY: event.clientY,
      moved: false,
    };
    if (insetMapTop && typeof insetMapTop.setPointerCapture === 'function') {
      insetMapTop.setPointerCapture(event.pointerId);
    }
    if (insetMapTop) {
      insetMapTop.classList.add('is-dragging');
    }
  }

  function moveInsetMapPan(event) {
    if (!insetMapDragState || event.pointerId !== insetMapDragState.pointerId || !insetMapProjection || !insetMapProjection.projector || !insetMapProjection.projector.scale) return;
    event.preventDefault();
    event.stopPropagation();
    const dx = event.clientX - insetMapDragState.lastX;
    const dy = event.clientY - insetMapDragState.lastY;
    const totalDx = event.clientX - insetMapDragState.startX;
    const totalDy = event.clientY - insetMapDragState.startY;
    if (Math.hypot(totalDx, totalDy) > 3) {
      insetMapDragState.moved = true;
      suppressInsetMapClick = true;
    }
    insetMapPanE -= dx / insetMapProjection.projector.scale;
    insetMapPanN += dy / insetMapProjection.projector.scale;
    insetMapDragState.lastX = event.clientX;
    insetMapDragState.lastY = event.clientY;
    insetMapSignature = '';
    updateInsetMap(sampleAt(activeT), true);
  }

  function endInsetMapPan(event) {
    if (!insetMapDragState || event.pointerId !== insetMapDragState.pointerId) return;
    event.preventDefault();
    event.stopPropagation();
    if (insetMapTop && typeof insetMapTop.releasePointerCapture === 'function') {
      try {
        insetMapTop.releasePointerCapture(event.pointerId);
      } catch (err) {
        // Pointer capture may already be released by the browser.
      }
    }
    if (insetMapTop) {
      insetMapTop.classList.remove('is-dragging');
    }
    suppressInsetMapClick = insetMapDragState.moved;
    insetMapDragState = null;
  }

  function insetMapSvgPoint(event) {
    if (!insetMapSvg) return null;
    const rect = insetMapSvg.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) return null;
    return {
      x: ((event.clientX - rect.left) / rect.width) * INSET_MAP_SIZE,
      y: ((event.clientY - rect.top) / rect.height) * INSET_MAP_SIZE,
    };
  }

  function seekInsetMapTrack(event) {
    if (suppressInsetMapClick) {
      suppressInsetMapClick = false;
      event.preventDefault();
      event.stopPropagation();
      return;
    }
    if (!insetMapProjection || !insetMapProjection.track || !insetMapProjection.projector) return;
    const click = insetMapSvgPoint(event);
    if (!click) return;
    let best = null;
    for (const point of insetMapProjection.track) {
      const projected = insetMapProjection.projector.project(point.lat, point.lon);
      const distance = Math.hypot(projected.x - click.x, projected.y - click.y);
      if (best === null || distance < best.distance) {
        best = { distance, t: point.t, point };
      }
    }
    if (!best || best.distance > 16) return;
    event.preventDefault();
    event.stopPropagation();
    const selectedNm = insetMapProjection.projector.latLonToNm(best.point.lat, best.point.lon);
    if (insetMapZoom > 1) {
      insetMapPanE = 0;
      insetMapPanN = 0;
    } else {
      insetMapPanE = selectedNm.e - insetMapProjection.projector.baseCenterE;
      insetMapPanN = selectedNm.n - insetMapProjection.projector.baseCenterN;
    }
    insetMapSignature = '';
    seek(best.t, !standaloneReplay, true);
    playFromInsetMap();
  }

  cameraModeSelect.addEventListener('change', () => {
    cameraMode = cameraModeSelect.value || 'synthetic_vision';
    applyCameraModeControls();
    resetDisplayCamera();
    safeRenderCesium(true);
  });
  if (calibrationToggle) {
    calibrationToggle.addEventListener('click', () => toggleModal(calibrationPanel, calibrationToggle));
  }
  if (debugToggle) {
    debugToggle.addEventListener('click', () => toggleModal(debugOverlay, debugToggle));
  }
  if (cameraRangeInput) {
    cameraRangeInput.addEventListener('input', () => updateCameraSetting('rangeM', cameraRangeInput.value));
  }
  if (cameraRangeExact) {
    cameraRangeExact.addEventListener('change', () => updateCameraSetting('rangeM', cameraRangeExact.value));
    cameraRangeExact.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') cameraRangeExact.blur();
    });
  }
  if (cameraHeightInput) {
    cameraHeightInput.addEventListener('input', () => updateCameraSetting('heightM', cameraHeightInput.value));
  }
  if (cameraHeightExact) {
    cameraHeightExact.addEventListener('change', () => updateCameraSetting('heightM', cameraHeightExact.value));
    cameraHeightExact.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') cameraHeightExact.blur();
    });
  }
  if (cameraPitchInput) {
    cameraPitchInput.addEventListener('input', () => updateCameraSetting('pitchDeg', cameraPitchInput.value));
  }
  if (cameraPitchExact) {
    cameraPitchExact.addEventListener('change', () => updateCameraSetting('pitchDeg', cameraPitchExact.value));
    cameraPitchExact.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') cameraPitchExact.blur();
    });
  }
  if (cameraSmoothingInput) {
    cameraSmoothingInput.addEventListener('input', () => updateCameraSetting('smoothing', cameraSmoothingInput.value));
  }
  if (cameraSmoothingExact) {
    cameraSmoothingExact.addEventListener('change', () => updateCameraSetting('smoothing', cameraSmoothingExact.value));
    cameraSmoothingExact.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') cameraSmoothingExact.blur();
    });
  }
  if (calibrationStepSelect) {
    calibrationStepSelect.addEventListener('change', () => {
      if (!cameraCalibration) return;
      cameraCalibration.stepM = clamp(firstFinite(calibrationStepSelect.value, 1), 0.1, 25);
      saveCameraCalibration();
      updateCalibrationPanel();
    });
  }
  if (calibrationSmoothness) {
    calibrationSmoothness.addEventListener('input', () => setSyntheticVisionSmoothness(calibrationSmoothness.value));
  }
  if (replayLayoutMode) {
    replayLayoutMode.addEventListener('change', () => setReplayLayoutMode(replayLayoutMode.value));
  }
  if (horizonBarOffset) {
    horizonBarOffset.addEventListener('input', () => setHorizonBarOffset(horizonBarOffset.value));
  }
  if (attitudeReferenceOffset) {
    attitudeReferenceOffset.addEventListener('input', () => setAttitudeReferenceOffset(attitudeReferenceOffset.value));
  }
  if (yellowPitchReferenceOffset) {
    yellowPitchReferenceOffset.addEventListener('input', () => setYellowPitchReferenceOffset(yellowPitchReferenceOffset.value));
  }
  if (pitchLadderScale) {
    pitchLadderScale.addEventListener('input', () => setPitchLadderScale(pitchLadderScale.value));
  }
  instrumentToggles.forEach((toggle) => {
    toggle.addEventListener('change', () => {
      setInstrumentPlaceholder(toggle.getAttribute('data-instrument-toggle') || '', toggle.checked);
    });
  });
  if (calibrationPanel) {
    calibrationPanel.querySelectorAll('[data-cal-axis]').forEach((button) => {
      button.addEventListener('click', () => {
        adjustCameraCalibration(button.getAttribute('data-cal-axis') || '', Number(button.getAttribute('data-cal-sign') || 0));
      });
    });
  }
  if (calibrationReset) {
    calibrationReset.addEventListener('click', () => {
      if (!cameraCalibration) return;
      cameraCalibration.forwardM = 0;
      cameraCalibration.rightM = 0;
      cameraCalibration.upM = 0;
      saveCameraCalibration();
      updateCalibrationPanel();
      safeRenderCesium(true);
    });
  }
  if (calibrationDirectionReset) {
    calibrationDirectionReset.addEventListener('click', () => {
      if (!cameraCalibration) return;
      cameraCalibration.yawDeg = 0;
      cameraCalibration.pitchDeg = 0;
      cameraCalibration.rollDeg = 0;
      saveCameraCalibration();
      updateCalibrationPanel();
      safeRenderCesium(true);
    });
  }
  if (calibrationFovDecrease) {
    calibrationFovDecrease.addEventListener('click', () => setSyntheticVisionFov(syntheticVisionHorizontalFovDeg() - 1));
  }
  if (calibrationFovIncrease) {
    calibrationFovIncrease.addEventListener('click', () => setSyntheticVisionFov(syntheticVisionHorizontalFovDeg() + 1));
  }
  if (calibrationFovReset) {
    calibrationFovReset.addEventListener('click', () => setSyntheticVisionFov(SYNTHETIC_VISION_DEFAULTS.horizontalFovDeg));
  }
  if (calibrationFovInput) {
    calibrationFovInput.addEventListener('change', () => setSyntheticVisionFov(calibrationFovInput.value));
  }
  if (altimeterSettingValue) {
    altimeterSettingValue.addEventListener('click', (event) => {
      event.stopPropagation();
      altimeterSettingUnit = altimeterSettingUnit === 'inhg' ? 'hpa' : 'inhg';
      saveAltimeterSettingUnit();
      updateAltimeterTape(sampleAt(activeT), 1 / 60, true);
    });
  }
  if (insetMapZoomIn) {
    insetMapZoomIn.addEventListener('pointerdown', (event) => event.stopPropagation());
    insetMapZoomIn.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      setInsetMapZoom(insetMapZoom * 2);
    });
  }
  if (insetMapZoomOut) {
    insetMapZoomOut.addEventListener('pointerdown', (event) => event.stopPropagation());
    insetMapZoomOut.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      setInsetMapZoom(insetMapZoom / 2);
    });
  }
  if (insetMapTop) {
    insetMapTop.addEventListener('pointerdown', startInsetMapPan);
    insetMapTop.addEventListener('pointermove', moveInsetMapPan);
    insetMapTop.addEventListener('pointerup', endInsetMapPan);
    insetMapTop.addEventListener('pointercancel', endInsetMapPan);
    insetMapTop.addEventListener('click', seekInsetMapTrack);
  }
  timeline.addEventListener('input', () => seek(Number(timeline.value), !standaloneReplay, true));
  audio.addEventListener('timeupdate', () => {
    if (!standaloneReplay && audio.paused) {
      seek(audio.currentTime, false, true);
    }
  });
  playButton.addEventListener('click', togglePlayback);
  rewindButton.addEventListener('click', () => skipBy(-10));
  forwardButton.addEventListener('click', () => skipBy(10));
  root.addEventListener('click', (event) => {
    if (event.target.closest('.replay-dock, .replay-menu, .replay-camera-panel, .replay-calibration-panel, .replay-debug, .cesium-viewer-toolbar, .altimeter-footer, .replay-inset-map')) {
      return;
    }
    togglePlayback();
  });
  audio.addEventListener('pause', () => {
    if (visualFallbackPlaying) return;
    playButton.textContent = 'Play';
    if (animationFrame !== null) {
      cancelAnimationFrame(animationFrame);
      animationFrame = null;
    }
  });
  audio.addEventListener('play', () => {
    playButton.textContent = 'Pause';
    lastRenderMs = null;
    if (animationFrame === null) {
      animationFrame = requestAnimationFrame(animatePlayback);
    }
  });

  function animatePlayback() {
    if (!payload || (standaloneReplay ? !standalonePlaying : (audio.paused && !visualFallbackPlaying))) {
      animationFrame = null;
      return;
    }
    updateCockpitPlayback();
    animationFrame = requestAnimationFrame(animatePlayback);
  }

  window.addEventListener('resize', () => {
    if (!payload) return;
    safeRenderCesium(true);
  });

  function setReplayLoadProgress(percent, message) {
    const pct = clamp(Math.round(Number(percent) || 0), 0, 100);
    if (replayLoadFill) replayLoadFill.style.width = `${pct}%`;
    if (replayLoadMeta) replayLoadMeta.textContent = `${pct}% - ${message}`;
  }

  async function fetchReplayJsonWithProgress(url) {
    setReplayLoadProgress(2, 'requesting replay data');
    const response = await fetch(url);
    if (!response.body || typeof response.body.getReader !== 'function') {
      const text = await response.text();
      setReplayLoadProgress(88, 'parsing replay data');
      return { response, text };
    }
    const totalBytes = Number(response.headers.get('content-length')) || 0;
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let receivedBytes = 0;
    let chunks = '';
    let chunkCount = 0;
    while (true) {
      const { value, done } = await reader.read();
      if (done) break;
      receivedBytes += value.byteLength;
      chunkCount++;
      chunks += decoder.decode(value, { stream: true });
      const networkPct = totalBytes > 0
        ? 5 + Math.min(78, (receivedBytes / totalBytes) * 78)
        : Math.min(84, 5 + chunkCount * 2);
      setReplayLoadProgress(networkPct, totalBytes > 0 ? 'downloading replay data' : 'streaming replay data');
    }
    chunks += decoder.decode();
    setReplayLoadProgress(88, 'parsing replay data');
    return { response, text: chunks };
  }

  async function loadReplay() {
    let data = null;
    try {
      const replayUrl = standaloneReplay
        ? `/admin/api/cockpit_recorder_standalone_replay.php?name=${encodeURIComponent(standaloneReplay)}`
        : `/api/recordings/replay.php?id=${encodeURIComponent(id)}&version=2&compact=1&sample_stride=3`;
      const { response, text } = await fetchReplayJsonWithProgress(replayUrl);
      try {
        data = JSON.parse(text);
      } catch (jsonErr) {
        throw new Error(`Replay API returned non-JSON HTTP ${response.status}: ${text.slice(0, 240)}`);
      }
      if (!response.ok) throw new Error(data.error || `Replay API HTTP ${response.status}`);
      if (!data.ok) throw new Error(data.error || 'Replay data not available.');
    } catch (err) {
      if (loadStatus) {
        loadStatus.innerHTML = `<div class="replay-error">Could not load replay data: ${String(err.message || err).replace(/[<>&]/g, (ch) => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[ch]))}</div>`;
      }
      return;
    }

    setReplayLoadProgress(93, 'preparing replay timeline');
    const samples = (data.samples || []).map((sample) => ({
      ...sample,
      bank_deg: sample.roll_deg ?? sample.bank_deg ?? null,
      gps_altitude_ft: sample.gps_altitude_ft ?? sample.altitude_ft ?? null,
      estimated_indicated_altitude_ft: sample.estimated_indicated_altitude_ft ?? sample.baro_altitude_ft ?? sample.altitude_ft ?? null,
      groundspeed_kt: sample.ground_speed_kt ?? sample.groundspeed_kt ?? null,
    }));

    payload = { ...data, samples };
    positionKeyframes = buildPositionKeyframes(payload.samples || []);
    const maxT = Math.max(Number(payload.recording.duration) || 0, payload.samples.reduce((max, s) => Math.max(max, Number(s.t) || 0), 1), 1);
    timeline.max = String(maxT);
    setReplayLoadProgress(97, 'starting visual engine');
    try {
      await initCesium();
    } catch (err) {
      showCesiumError(String(err.message || err));
    }
    safeRenderCesium(true);
    setReplayLoadProgress(100, 'ready');
    if (loadStatus) loadStatus.remove();
  }

  cameraCalibration = loadCameraCalibration();
  altimeterSettingUnit = loadAltimeterSettingUnit();
  updateCalibrationPanel();
  cameraSettings = loadCameraSettings();
  syncCameraControls();
  applyReplayLayoutMode();
  loadReplay();
})();
</script>
<?php endif; ?>

<?php
cw_footer();
