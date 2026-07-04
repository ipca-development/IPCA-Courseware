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
        array('key' => 'volts', 'label' => 'VOLTS', 'unit' => '', 'min' => 11.5, 'max' => 16, 'value_field' => 'volts', 'decimals' => 1, 'alert_style' => 'yellow_label', 'ranges' => array(
            array('color' => 'red', 'from' => 11.5, 'to' => 12.8),
            array('color' => 'white', 'from' => 12.8, 'to' => 13.2),
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
  height: clamp(230px, 28vw, 300px);
  transform: translateX(-50%);
  pointer-events: none;
  filter: drop-shadow(0 2px 5px rgba(0, 0, 0, .28));
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
.hsi-overlay .hsi-nav {
  stroke: #10d510;
  stroke-width: 7;
  fill: none;
}
.hsi-overlay .hsi-nav-arrow {
  fill: #10d510;
  stroke: #10d510;
  stroke-width: 1;
}
.hsi-overlay .hsi-nav-text {
  fill: #10d510;
  stroke: rgba(15, 23, 42, .20);
  stroke-width: .8px;
}
.hsi-overlay .hsi-heading-text { fill: rgba(255, 255, 255, .98); font-size: 11.5px; }
.hsi-overlay .hsi-heading-value { fill: #ffffff; font-size: 17px; }
.hsi-overlay .hsi-crs-text { fill: rgba(255, 255, 255, .98); font-size: 16px; }
.hsi-overlay .hsi-cyan { fill: #9ffcff; }
.hsi-overlay .hsi-green { fill: #18d918; }
.hsi-overlay .hsi-rose-label {
  font-size: 8px;
  font-weight: 650;
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
  color: rgba(248, 250, 252, .98);
  text-shadow: 0 1px 2px rgba(0, 0, 0, .70);
}
.engine-row-head.is-alert-yellow {
  color: #111;
  background: linear-gradient(165deg, #fff42a 0%, #ffe500 58%, #d8c800 100%);
  padding: 2px 4px;
  text-shadow: none;
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
.engine-bar.is-probe-pair {
  margin-left: 15px;
}
.engine-probe.is-pair-top,
.engine-probe.is-pair-bottom {
  left: -17px;
}
.engine-probe.is-pair-top {
  top: -7px;
}
.engine-probe.is-pair-bottom {
  top: auto;
  bottom: -7px;
  clip-path: polygon(0 100%, 100% 100%, 70% 0, 0 0);
  line-height: 13px;
}
.engine-arc-gauge {
  position: relative;
  height: 88px;
  margin: 0 2px 6px;
}
.engine-arc-gauge.is-rpm {
  height: 116px;
  margin: 0 0 20px;
}
.engine-arc-svg {
  width: 100%;
  height: 78px;
  overflow: visible;
}
.engine-arc-svg.is-rpm {
  height: 110px;
  display: block;
}
.engine-arc-value {
  position: absolute;
  right: 2px;
  bottom: 3px;
  text-align: right;
  font-weight: 900;
}
.engine-arc-gauge.is-rpm .engine-arc-value {
  right: 1px;
  top: 54px;
  bottom: auto;
  min-width: 44px;
  color: #f8fafc;
  text-shadow: 0 1px 2px rgba(0, 0, 0, .72);
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
  font-size: 27px;
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
.attitude-overlay .attitude-slip {
  fill: rgba(255, 255, 255, .88);
}
.attitude-overlay .attitude-bank-pointer {
  fill: #fff;
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
  width: 20px;
  height: 28px;
  transform: translateY(-50%);
  background: #20e4e4;
  border-radius: 2px;
}
.altimeter-bug::after {
  content: "";
  position: absolute;
  right: -10px;
  top: 50%;
  transform: translateY(-50%);
  border-top: 7px solid transparent;
  border-bottom: 7px solid transparent;
  border-left: 10px solid #20e4e4;
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
  grid-template-columns: auto auto auto auto auto 1fr auto;
  gap: 10px;
  align-items: center;
  padding: 10px 14px;
  background: rgba(15, 23, 42, 0.72);
  backdrop-filter: blur(6px);
}
.replay-dock a { color: #e2e8f0; font-size: 13px; text-decoration: none; white-space: nowrap; }
.replay-dock a:hover { color: #fff; }
.replay-button { border: 0; border-radius: 8px; background: #1d4ed8; color: #fff; font-weight: 700; padding: 8px 14px; cursor: pointer; }
.replay-range { width: 100%; accent-color: #60a5fa; margin: 0; }
.replay-time { color: #e2e8f0; font-size: 13px; font-variant-numeric: tabular-nums; white-space: nowrap; }
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
    <svg id="hsiOverlay" class="hsi-overlay" aria-label="Horizontal situation indicator" viewBox="0 0 390 300" hidden></svg>
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
  const horizonLine = document.getElementById('horizonLine');
  const attitudeOverlay = document.getElementById('attitudeOverlay');
  const hsiOverlay = document.getElementById('hsiOverlay');
  const enginePanel = document.getElementById('enginePanel');
  const airspeedTape = document.getElementById('airspeedTape');
  const airspeedTapeBody = document.getElementById('airspeedTapeBody');
  const airspeedTapeScale = document.getElementById('airspeedTapeScale');
  const airspeedTapeColors = document.getElementById('airspeedTapeColors');
  const airspeedTapeBugs = document.getElementById('airspeedTapeBugs');
  const airspeedTapePointer = document.getElementById('airspeedTapePointer');
  const airspeedTasValue = document.getElementById('airspeedTasValue');
  const airspeedGsValue = document.getElementById('airspeedGsValue');
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
  let altimeterSettingUnit = 'hpa';
  let hsiOverlaySignature = '';
  let attitudeOverlaySignature = '';
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
  const DEFAULT_ENABLED_INSTRUMENTS = new Set(['airspeed_indicator', 'altimeter', 'horizon_bar', 'attitude_indicator']);
  const CAMERA_SNAP_SEEK_SEC = 0.75;
  const POSITION_KEY_MIN_DIST_M = 0.15;
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
    if (Number.isFinite(lastTerrainHeightM)) {
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
    return Number.isFinite(lastTerrainHeightM) ? 'cesium_rendered_terrain_ground' : 'replay_ground_altitude';
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
    updateAltimeterTape(sampleAt(activeT), 1 / 60, true);
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
    updateHorizonLine(displayCamera);
    updateAttitudeIndicator(displayCamera, sampleAt(activeT));
    updateAirspeedTape(sampleAt(activeT), 1 / 60, true);
  }

  function instrumentEnabled(key) {
    if (!cameraCalibration || !cameraCalibration.instruments) return DEFAULT_ENABLED_INSTRUMENTS.has(key);
    return cameraCalibration.instruments[key] === true;
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
      airspeedTape.hidden = true;
      displayAirspeedKt = null;
      return;
    }
    const ias = firstFinite(sample.ias_kt, sample.indicated_airspeed_kt, sample.airspeed_kt);
    if (ias === null) {
      airspeedTape.hidden = true;
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
    airspeedTape.hidden = false;
    if (airspeedTapeScale) airspeedTapeScale.innerHTML = scaleHtml;
    if (airspeedTapeColors) airspeedTapeColors.innerHTML = colorsHtml + redHtml;
    if (airspeedTapeBugs) airspeedTapeBugs.innerHTML = bugHtml;
    if (airspeedTapePointer) airspeedTapePointer.textContent = String(Math.round(displayAirspeedKt));
    if (airspeedTasValue) airspeedTasValue.textContent = tas === null ? '--' : String(Math.round(tas));
    if (airspeedGsValue) airspeedGsValue.textContent = gs === null ? '--' : String(Math.round(gs));
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
      altimeterStack.hidden = true;
      displayAltitudeFt = null;
      displayVsiFpm = null;
      return;
    }
    const altitudeFt = indicatedAltitudeFt(sample);
    if (altitudeFt === null) {
      altimeterStack.hidden = true;
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
      ? `<div class="altimeter-bug" style="top:${bugY.toFixed(1)}px"></div>`
      : '';
    const oatC = firstFinite(sample.oat_c);
    const isaDevC = isaDeviationC(sample, altitudeFt, oatC);
    const daFt = decisionAltitudeFt(sample);

    altimeterStack.hidden = false;
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

  function engineFormatValue(value, decimals) {
    if (!Number.isFinite(Number(value))) return '--';
    const places = Math.max(0, Math.min(2, Math.round(Number(decimals) || 0)));
    return Number(value).toFixed(places).replace(/\.0$/, '');
  }

  function engineRangeHtml(instrument) {
    const min = Number(instrument && instrument.min);
    const max = Number(instrument && instrument.max);
    const ranges = Array.isArray(instrument && instrument.ranges) ? instrument.ranges : [];
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

  function engineBarHtml(sample, instrument) {
    const key = String(instrument && instrument.key || '').toLowerCase();
    if (key === 'egt1_f') {
      return engineProbePairHtml(sample, instrument, 'egt2_f', '2');
    }
    if (key === 'coolant1_f') {
      return engineProbePairHtml(sample, instrument, 'coolant2_f', '2');
    }
    const value = engineValue(sample, instrument);
    const label = String((instrument && instrument.label) || '').trim();
    const decimals = Number(instrument && instrument.decimals) || 0;
    const pointer = value === null ? 0 : engineRangePercent(value, instrument.min, instrument.max);
    const probe = String((instrument && instrument.probe_label) || '').trim();
    const alertClass = instrument && instrument.alert_style === 'yellow_label' ? ' is-alert-yellow' : '';
    return `<div class="engine-gauge">
      ${label !== '' ? `<div class="engine-row-head${alertClass}"><span>${escapeHtml(label)}</span><strong class="engine-value">${escapeHtml(engineFormatValue(value, decimals))}</strong></div>` : ''}
      <div class="engine-bar">
        ${engineRangeHtml(instrument)}
        ${probe !== '' ? `<span class="engine-probe">${escapeHtml(probe)}</span>` : ''}
        <span class="engine-pointer" style="left:${pointer.toFixed(2)}%"></span>
        ${instrument && instrument.kind === 'ammeter' ? '<span class="engine-amps-cross"></span>' : ''}
      </div>
    </div>`;
  }

  function engineProbePairHtml(sample, instrument, secondField, secondProbeLabel) {
    const value1 = engineValue(sample, instrument);
    const value2 = firstFinite(sample && sample[secondField]);
    const label = String((instrument && instrument.label) || '').trim();
    const decimals = Number(instrument && instrument.decimals) || 0;
    const pointer1 = value1 === null ? null : engineRangePercent(value1, instrument.min, instrument.max);
    const pointer2 = value2 === null ? null : engineRangePercent(value2, instrument.min, instrument.max);
    const probe1 = String((instrument && instrument.probe_label) || '1').trim() || '1';
    return `<div class="engine-gauge">
      ${label !== '' ? `<div class="engine-row-head"><span>${escapeHtml(label)}</span><strong class="engine-value">${escapeHtml(engineFormatValue(value1, decimals))}</strong></div>` : ''}
      <div class="engine-bar is-probe-pair">
        ${engineRangeHtml(instrument)}
        <span class="engine-probe is-pair-top">${escapeHtml(probe1)}</span>
        <span class="engine-probe is-pair-bottom">${escapeHtml(String(secondProbeLabel || '2'))}</span>
        ${pointer1 === null ? '' : `<span class="engine-pointer" style="left:${pointer1.toFixed(2)}%"></span>`}
        ${pointer2 === null ? '' : `<span class="engine-pointer is-bottom" style="left:${pointer2.toFixed(2)}%"></span>`}
      </div>
    </div>`;
  }

  function engineArcHtml(sample, instrument) {
    if (instrument && String(instrument.key || '').toLowerCase() === 'rpm') {
      return engineRpmArcHtml(sample, instrument);
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

  function engineRpmArcHtml(sample, instrument) {
    const value = engineValue(sample, instrument);
    const decimals = Number(instrument && instrument.decimals) || 0;
    const min = Number(instrument && instrument.min);
    const max = Number(instrument && instrument.max);
    const pct = value === null ? 0 : engineRangePercent(value, min, max);
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
    const pivotY = cy + 8;
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
      <div class="engine-arc-value"><span>${escapeHtml(String(instrument.label || ''))}</span><strong>${escapeHtml(engineFormatValue(value, decimals))}</strong></div>
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

  function updateEnginePanel(sample) {
    if (!enginePanel) return;
    const instruments = engineProfileInstruments();
    if (!sample || instruments.length === 0) {
      enginePanel.innerHTML = '';
      return;
    }
    enginePanel.innerHTML = instruments.filter((instrument) => {
      const key = String(instrument && instrument.key || '').toLowerCase();
      return key !== 'coolant2_f' && key !== 'egt2_f';
    }).map((instrument) => {
      return instrument && instrument.kind === 'arc' ? engineArcHtml(sample, instrument) : engineBarHtml(sample, instrument);
    }).join('');
  }

  function hsiHeadingFromSample(sample) {
    return firstFinite(
      sample && sample.heading_deg_magnetic,
      sample && sample.magnetic_heading_deg,
      sample && sample.heading_deg,
      sample && sample.heading_deg_true,
      sample && sample.true_heading_deg
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
      sample && sample.g3x && sample.g3x.nav_course_deg
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

  function hsiCdiFromSample(sample) {
    return firstFinite(
      sample && sample.horizontal_cdi_deflection,
      sample && sample.hcdi,
      sample && sample.nav_cdi,
      sample && sample.g3x && sample.g3x.hcdi
    );
  }

  function hsiTrackMagneticFromSample(sample) {
    const trackTrue = firstFinite(
      sample && sample.track_deg_true,
      sample && sample.track_deg,
      sample && sample.gps_track_deg,
      sample && sample.g3x && sample.g3x.track_deg_true
    );
    if (trackTrue === null) return null;
    const variation = firstFinite(sample && sample.magnetic_variation_deg, sample && sample.g3x && sample.g3x.magnetic_variation_deg);
    return normalizeDeg(Number(trackTrue) - (variation === null ? 0 : Number(variation)));
  }

  function hsiLabelForDegrees(deg) {
    const normalized = ((Math.round(deg / 30) * 30) % 360 + 360) % 360;
    if (normalized === 0) return 'N';
    if (normalized === 90) return 'E';
    if (normalized === 180) return 'S';
    if (normalized === 270) return 'W';
    return String(normalized / 10);
  }

  function updateHsiOverlay(sample, dtSec = 1 / 60, snap = false) {
    if (!hsiOverlay) return;
    const heading = hsiHeadingFromSample(sample);
    if (heading === null) {
      hsiOverlay.hidden = true;
      hsiOverlaySignature = '';
      displayHsiHeadingDeg = null;
      displayHsiHeadingBugDeg = null;
      return;
    }
    const headingDeg = normalizeDeg(heading);
    const bug = hsiHeadingBugFromSample(sample);
    const bugDeg = bug === null ? null : normalizeDeg(bug);
    const course = hsiCourseFromSample(sample);
    const courseDeg = course === null ? null : normalizeDeg(course);
    const trackMag = hsiTrackMagneticFromSample(sample);
    const cdi = hsiCdiFromSample(sample);
    const cdiOffset = cdi === null ? 0 : clamp(Number(cdi), -1, 1) * 32;
    const navLabel = hsiNavSourceFromSample(sample);
    const alpha = snap ? 1 : smoothFactor(16, dtSec);
    displayHsiHeadingDeg = (snap || displayHsiHeadingDeg === null || !Number.isFinite(displayHsiHeadingDeg))
      ? headingDeg
      : lerpAngleDeg(displayHsiHeadingDeg, headingDeg, alpha);
    displayHsiHeadingBugDeg = bugDeg === null
      ? null
      : ((snap || displayHsiHeadingBugDeg === null || !Number.isFinite(displayHsiHeadingBugDeg))
        ? bugDeg
        : lerpAngleDeg(displayHsiHeadingBugDeg, bugDeg, alpha));

    const cx = 195;
    const cy = 176;
    const r = 126;
    const innerR = 72;
    const headingText = String(Math.round(displayHsiHeadingDeg)).padStart(3, '0') + '°';
    const hdgBugText = displayHsiHeadingBugDeg === null ? '---' : `${String(Math.round(displayHsiHeadingBugDeg)).padStart(3, '0')}°`;
    const crsText = courseDeg === null ? '---' : `${String(Math.round(courseDeg)).padStart(3, '0')}°`;
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
      const rad = degToRad(displayHsiHeadingBugDeg);
      const x = Math.sin(rad) * (r - 10);
      const y = -Math.cos(rad) * (r - 10);
      return `<polygon class="hsi-heading-bug" points="${x.toFixed(1)},${(y - 15).toFixed(1)} ${(x + 10).toFixed(1)},${y.toFixed(1)} ${x.toFixed(1)},${(y + 15).toFixed(1)} ${(x - 10).toFixed(1)},${y.toFixed(1)}"></polygon>`;
    })();
    const trackHtml = trackMag === null ? '' : (() => {
      const rad = degToRad(trackMag);
      const x = Math.sin(rad) * (r - 10);
      const y = -Math.cos(rad) * (r - 10);
      return `<polygon class="hsi-track-diamond" points="${x.toFixed(1)},${(y - 13).toFixed(1)} ${(x + 13).toFixed(1)},${y.toFixed(1)} ${x.toFixed(1)},${(y + 13).toFixed(1)} ${(x - 13).toFixed(1)},${y.toFixed(1)}"></polygon>`;
    })();
    const courseRotation = courseDeg === null ? 0 : normalizeSignedDeg(courseDeg - displayHsiHeadingDeg);
    const courseHtml = courseDeg === null ? '' : `
        <g transform="rotate(${courseRotation.toFixed(2)}) translate(${cdiOffset.toFixed(1)} 0)">
          <line class="hsi-nav" x1="0" y1="${(r - 10).toFixed(1)}" x2="0" y2="${(-r + 10).toFixed(1)}"></line>
          <polygon class="hsi-nav-arrow" points="0,${(-r - 9).toFixed(1)} -8,${(-r + 10).toFixed(1)} 8,${(-r + 10).toFixed(1)}"></polygon>
        </g>
        <text class="hsi-nav-text" x="-76" y="-28" font-size="17">${escapeHtml(navLabel)}</text>`;
    const signature = [
      Math.round(displayHsiHeadingDeg * 10),
      displayHsiHeadingBugDeg === null ? 'x' : Math.round(displayHsiHeadingBugDeg * 10),
      trackMag === null ? 't' : Math.round(trackMag * 10),
      courseDeg === null ? 'c' : Math.round(courseDeg * 10),
      Math.round(cdiOffset),
      navLabel,
      headingText,
      hdgBugText,
      crsText,
    ].join('|');
    if (signature === hsiOverlaySignature) {
      hsiOverlay.hidden = false;
      return;
    }
    hsiOverlaySignature = signature;
    hsiOverlay.innerHTML = `
      <rect class="hsi-label-box" x="166" y="12" width="58" height="31" rx="7"></rect>
      <text class="hsi-heading-value" x="195" y="34" text-anchor="middle">${headingText}</text>
      <rect class="hsi-label-box" x="-2" y="50" width="96" height="34" rx="7"></rect>
      <text class="hsi-heading-text" x="11" y="74">HDG <tspan class="hsi-cyan">${hdgBugText}</tspan></text>
      <rect class="hsi-label-box" x="294" y="50" width="96" height="34" rx="7"></rect>
      <text class="hsi-crs-text" x="307" y="74">CRS <tspan class="hsi-green">${crsText}</tspan></text>
      <g transform="translate(${cx} ${cy})">
        <circle class="hsi-card-fill" cx="0" cy="0" r="${r}"></circle>
        <circle class="hsi-rose-line" cx="0" cy="0" r="${innerR}"></circle>
        <g transform="rotate(${(-displayHsiHeadingDeg).toFixed(2)})">
          ${ticks.join('')}
          ${bugHtml}
          ${trackHtml}
        </g>
        <line class="hsi-course-line" x1="0" y1="${(-r - 12).toFixed(1)}" x2="0" y2="${(-innerR + 8).toFixed(1)}" stroke-dasharray="9 9"></line>
        ${courseHtml}
        <circle class="hsi-aircraft" cx="0" cy="0" r="7"></circle>
        <path class="hsi-aircraft" d="M 0 -31 L 8 -5 L 31 7 L 31 15 L 8 11 L 5 32 L -5 32 L -8 11 L -31 15 L -31 7 L -8 -5 Z" fill="rgba(255,255,255,.88)"></path>
      </g>
    `;
    hsiOverlay.hidden = false;
  }

  function updateHorizonLine(view) {
    if (!horizonLine) return;
    if (!view || !isSyntheticCameraMode(view.mode) || !instrumentEnabled('horizon_bar')) {
      horizonLine.hidden = true;
      return;
    }
    const container = cesiumViewer && cesiumViewer.container ? cesiumViewer.container : document.getElementById('cesiumReplay');
    const width = Number(container && container.clientWidth);
    const height = Number(container && container.clientHeight);
    if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) {
      horizonLine.hidden = true;
      return;
    }
    const dbg = currentCameraDebug || {};
    const pitchDeg = firstFinite(dbg.pitchDegUsed, view.pitch, 0) || 0;
    const rollDeg = firstFinite(dbg.rollDegUsed, view.roll, 0) || 0;
    const verticalFovDeg = Math.max(1, firstFinite(dbg.activeVerticalFovDeg, dbg.verticalFovDeg, SYNTHETIC_VISION_DEFAULTS.verticalFovFallbackDeg) || SYNTHETIC_VISION_DEFAULTS.verticalFovFallbackDeg);
    const halfHeight = height / 2;
    const pitchOffsetPx = Math.tan(degToRad(pitchDeg)) / Math.tan(degToRad(verticalFovDeg) / 2) * halfHeight;
    const horizonOffsetPx = cameraCalibration ? Number(cameraCalibration.horizonBarOffsetPx || 0) : 0;
    const y = clamp(halfHeight + pitchOffsetPx + horizonOffsetPx, -height, height * 2);
    horizonLine.hidden = false;
    horizonLine.style.top = `${y}px`;
    horizonLine.style.transform = `translate(-50%, -50%) rotate(${-rollDeg}deg)`;
  }

  function updateAttitudeIndicator(view, sample) {
    if (!attitudeOverlay) return;
    if (!view || !isSyntheticCameraMode(view.mode) || !instrumentEnabled('attitude_indicator')) {
      attitudeOverlay.hidden = true;
      attitudeOverlay.innerHTML = '';
      return;
    }
    const container = cesiumViewer && cesiumViewer.container ? cesiumViewer.container : document.getElementById('cesiumReplay');
    const width = Number(container && container.clientWidth);
    const height = Number(container && container.clientHeight);
    if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) {
      attitudeOverlay.hidden = true;
      attitudeOverlay.innerHTML = '';
      return;
    }
    const dbg = currentCameraDebug || {};
    const pitchDeg = firstFinite(dbg.pitchDegUsed, view.pitch, 0) || 0;
    const rollDeg = firstFinite(dbg.rollDegUsed, view.roll, 0) || 0;
    const verticalFovDeg = Math.max(1, firstFinite(dbg.activeVerticalFovDeg, dbg.verticalFovDeg, SYNTHETIC_VISION_DEFAULTS.verticalFovFallbackDeg) || SYNTHETIC_VISION_DEFAULTS.verticalFovFallbackDeg);
    const halfHeight = height / 2;
    const horizonOffsetPx = cameraCalibration ? Number(cameraCalibration.horizonBarOffsetPx || 0) : 0;
    const pitchOffsetPx = Math.tan(degToRad(pitchDeg)) / Math.tan(degToRad(verticalFovDeg) / 2) * halfHeight;
    const horizonY = clamp(halfHeight + pitchOffsetPx + horizonOffsetPx, -height, height * 2);
    const referenceY = clamp(height * 0.66 + (cameraCalibration ? Number(cameraCalibration.attitudeReferenceOffsetPx || 0) : 0), 90, height - 90);
    const rootRect = root ? root.getBoundingClientRect() : { left: 0, top: 0 };
    const airspeedRect = airspeedTape && !airspeedTape.hidden ? airspeedTape.getBoundingClientRect() : null;
    const altimeterRect = altimeterStack && !altimeterStack.hidden ? altimeterStack.getBoundingClientRect() : null;
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
    const slipHeight = 8;
    const yellowReferenceY = referenceY + (cameraCalibration ? Number(cameraCalibration.yellowPitchReferenceOffsetPx || 0) : 0);
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
    ].join('|');
    if (signature === attitudeOverlaySignature) {
      attitudeOverlay.hidden = false;
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
        <polygon class="attitude-bank-pointer" points="0,${(-arcRadius + 1).toFixed(1)} -11,${(-arcRadius - 22).toFixed(1)} 11,${(-arcRadius - 22).toFixed(1)}"></polygon>
      </g>
      <g transform="translate(${centerX.toFixed(1)} ${staticPointerY.toFixed(1)})">
        <polygon class="attitude-slip" points="0,0 -${pointerHalfWidth},${pointerHeight} ${pointerHalfWidth},${pointerHeight}"></polygon>
        <polygon class="attitude-slip" points="${(slipX - slipTopHalfWidth).toFixed(1)},${pointerHeight} ${(slipX + slipTopHalfWidth).toFixed(1)},${pointerHeight} ${(slipX + slipBottomHalfWidth).toFixed(1)},${pointerHeight + slipHeight} ${(slipX - slipBottomHalfWidth).toFixed(1)},${pointerHeight + slipHeight}"></polygon>
      </g>
      <g transform="translate(${centerX.toFixed(1)} ${yellowReferenceY.toFixed(1)}) scale(${attitudeYellowReferenceScale})">
        <rect class="attitude-yellow" x="-508" y="-6" width="132" height="12" rx="4" ry="4"></rect>
        <rect class="attitude-yellow" x="376" y="-6" width="132" height="12" rx="4" ry="4"></rect>
        <polygon class="attitude-yellow" points="-272,76 0,-12 -176,76"></polygon>
        <polygon class="attitude-yellow" points="272,76 0,-12 176,76"></polygon>
      </g>
    `;
    attitudeOverlay.hidden = false;
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
    hsiOverlaySignature = '';
    attitudeOverlaySignature = '';
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
      updateHorizonLine(null);
      updateAttitudeIndicator(null, null);
      const freeSample = sampleAt(activeT);
      updateAirspeedTape(freeSample, 1 / 60, true);
      updateAltimeterTape(freeSample, 1 / 60, true);
      updateHsiOverlay(freeSample, 1 / 60, true);
      updateEnginePanel(freeSample);
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
    if (!target) return;

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
    updateAttitudeIndicator(view, sample);
    updateAirspeedTape(sample, dtSec, snap);
    updateAltimeterTape(sample, dtSec, snap);
    updateHsiOverlay(sample, dtSec, snap);
    updateEnginePanel(sample);
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
      estimated_wind_speed_kt: lerp(before.estimated_wind_speed_kt, after.estimated_wind_speed_kt),
      estimated_wind_direction_deg_true: lerpAngle(before.estimated_wind_direction_deg_true, after.estimated_wind_direction_deg_true),
      estimated_tas_kt: lerp(before.estimated_tas_kt, after.estimated_tas_kt),
      ias_kt: lerp(before.ias_kt, after.ias_kt),
      tas_kt: lerp(before.tas_kt, after.tas_kt),
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
      nav_xtk_nm: lerp(before.nav_xtk_nm, after.nav_xtk_nm),
      hcdi: lerp(before.hcdi, after.hcdi),
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
    const dbg = currentCameraDebug || {};
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
    if (event.target.closest('.replay-dock, .replay-menu, .replay-camera-panel, .replay-calibration-panel, .replay-debug, .cesium-viewer-toolbar, .altimeter-footer')) {
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
