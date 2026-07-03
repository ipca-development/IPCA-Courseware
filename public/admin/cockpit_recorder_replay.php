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
.cesium-unavailable { position: absolute; inset: 0; display: grid; place-items: center; color: #fff; background: #0f172a; text-align: center; padding: 28px; z-index: 10; }
.replay-select { border: 1px solid rgba(226, 232, 240, .45); border-radius: 8px; background: rgba(15, 23, 42, .9); color: #e2e8f0; padding: 7px 9px; }
.replay-debug {
  position: absolute;
  top: 12px;
  left: 12px;
  z-index: 21;
  width: 300px;
  max-width: calc(100vw - 24px);
  max-height: min(42vh, 360px);
  overflow: auto;
  pointer-events: none;
  color: #dbeafe;
  background: rgba(15, 23, 42, .66);
  border: 1px solid rgba(148, 163, 184, .35);
  border-radius: 10px;
  padding: 8px;
  font: 10px/1.25 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
  white-space: pre-wrap;
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
  grid-template-columns: 118px 1fr 58px;
  gap: 8px;
  align-items: center;
  margin-top: 6px;
}
.replay-camera-control label { color: #cbd5e1; }
.replay-camera-control input { width: 100%; accent-color: #60a5fa; }
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
  position: absolute;
  right: 12px;
  bottom: 64px;
  z-index: 22;
  width: min(280px, calc(100vw - 24px));
  color: #e2e8f0;
  background: rgba(15, 23, 42, .48);
  border: 1px solid rgba(226, 232, 240, .28);
  border-radius: 12px;
  padding: 10px;
  backdrop-filter: blur(5px);
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
    <div id="loadStatus" class="replay-load">Loading replay data…</div>
    <div id="cesiumReplay" class="cesium-cockpit"></div>
    <div id="replayDebug" class="replay-debug">Replay debug initializing...</div>
    <div id="cameraPanel" class="replay-camera-panel" aria-label="Replay camera controls" hidden>
      <div class="replay-camera-panel-title">Chase / north-up tuning</div>
      <div class="replay-camera-control">
        <label for="cameraRange">Range</label>
        <input id="cameraRange" type="range" min="60" max="260" step="5">
        <output id="cameraRangeValue" for="cameraRange"></output>
      </div>
      <div class="replay-camera-control">
        <label for="cameraHeight">Height offset</label>
        <input id="cameraHeight" type="range" min="8" max="90" step="2">
        <output id="cameraHeightValue" for="cameraHeight"></output>
      </div>
      <div class="replay-camera-control">
        <label for="cameraPitch">Camera pitch</label>
        <input id="cameraPitch" type="range" min="-30" max="-4" step="1">
        <output id="cameraPitchValue" for="cameraPitch"></output>
      </div>
      <div class="replay-camera-control">
        <label for="cameraSmoothing">Smoothing</label>
        <input id="cameraSmoothing" type="range" min="1" max="12" step="0.5">
        <output id="cameraSmoothingValue" for="cameraSmoothing"></output>
      </div>
    </div>
    <div id="terrainWarning" class="replay-terrain-warning" hidden></div>
    <div id="calibrationPanel" class="replay-calibration-panel" aria-label="Camera position calibration">
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
          <button class="replay-calibration-button is-muted" type="button" data-cal-axis="roll" data-cal-sign="1">Roll +</button>
        </div>
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
  const root = document.querySelector('[data-replay-id]');
  const id = root ? root.getAttribute('data-replay-id') : '';
  const standaloneReplay = root ? (root.getAttribute('data-standalone-replay') || '') : '';
  const cesiumToken = root ? (root.getAttribute('data-cesium-token') || '').trim().replace(/^['"]+|['"]+$/g, '') : '';
  const loadStatus = document.getElementById('loadStatus');
  const timeline = document.getElementById('timeline');
  const timeLabel = document.getElementById('timeLabel');
  const audio = document.getElementById('audio');
  const playButton = document.getElementById('playButton');
  const rewindButton = document.getElementById('rewindButton');
  const forwardButton = document.getElementById('forwardButton');
  const cameraModeSelect = document.getElementById('cameraMode');
  const debugOverlay = document.getElementById('replayDebug');
  const cameraPanel = document.getElementById('cameraPanel');
  const cameraRangeInput = document.getElementById('cameraRange');
  const cameraHeightInput = document.getElementById('cameraHeight');
  const cameraPitchInput = document.getElementById('cameraPitch');
  const cameraSmoothingInput = document.getElementById('cameraSmoothing');
  const cameraRangeValue = document.getElementById('cameraRangeValue');
  const cameraHeightValue = document.getElementById('cameraHeightValue');
  const cameraPitchValue = document.getElementById('cameraPitchValue');
  const cameraSmoothingValue = document.getElementById('cameraSmoothingValue');
  const terrainWarning = document.getElementById('terrainWarning');
  const calibrationPanel = document.getElementById('calibrationPanel');
  const calibrationStepSelect = document.getElementById('calibrationStep');
  const calibrationReset = document.getElementById('calibrationReset');
  const calibrationDirectionReset = document.getElementById('calibrationDirectionReset');
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
    eyeHeightM: 1.5,
    forwardOffsetM: 2.0,
    verticalFovDeg: 45,
  };
  const SYNTHETIC_TEST_HEADING_DEG = 230;
  const BODY_AXIS_MAPPING = {
    eyeOffsetXForwardM: 2.0,
    eyeOffsetYRightM: 0.0,
    eyeOffsetZUpM: 1.5,
  };
  const CAMERA_STORAGE_KEY = 'ipca.cockpitReplay.camera.v1';
  const CAMERA_CALIBRATION_STORAGE_KEY = 'ipca.cockpitReplay.cameraCalibration.v4';
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
  const firstFinite = (...values) => {
    for (const value of values) {
      const n = finiteNumber(value);
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
    return {
      forwardM: clamp(firstFinite(saved.forwardM, 0), -200, 200),
      rightM: clamp(firstFinite(saved.rightM, 0), -200, 200),
      upM: clamp(firstFinite(saved.upM, 0), -200, 200),
      yawDeg: clamp(firstFinite(saved.yawDeg, 0), -90, 90),
      pitchDeg: clamp(firstFinite(saved.pitchDeg, 0), -45, 45),
      rollDeg: clamp(firstFinite(saved.rollDeg, 0), -45, 45),
      stepM: clamp(firstFinite(saved.stepM, 1), 0.1, 25),
    };
  }

  function saveCameraCalibration() {
    try {
      localStorage.setItem(CAMERA_CALIBRATION_STORAGE_KEY, JSON.stringify(cameraCalibration));
    } catch (err) {
      // Calibration is a local visual aid; replay should continue if storage is unavailable.
    }
  }

  function updateCalibrationPanel() {
    if (!cameraCalibration) return;
    if (calibrationStepSelect) {
      calibrationStepSelect.value = String(cameraCalibration.stepM);
    }
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

  function updateCameraControlLabels() {
    if (!cameraSettings) return;
    if (cameraRangeValue) cameraRangeValue.textContent = `${Math.round(cameraSettings.rangeM)} m`;
    if (cameraHeightValue) cameraHeightValue.textContent = `+${Math.round(cameraSettings.heightM)} m`;
    if (cameraPitchValue) cameraPitchValue.textContent = `${Math.round(cameraSettings.pitchDeg)} deg`;
    if (cameraSmoothingValue) cameraSmoothingValue.textContent = cameraSettings.smoothing.toFixed(1);
  }

  function syncCameraControls() {
    if (!cameraSettings) return;
    if (cameraRangeInput) cameraRangeInput.value = String(cameraSettings.rangeM);
    if (cameraHeightInput) cameraHeightInput.value = String(cameraSettings.heightM);
    if (cameraPitchInput) cameraPitchInput.value = String(cameraSettings.pitchDeg);
    if (cameraSmoothingInput) cameraSmoothingInput.value = String(cameraSettings.smoothing);
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
      const aircraftAltitudeM = visualAltitudeM(s);
      const groundReferenceM = groundReferenceAltitudeM(s);
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
        aircraftHeading,
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
    if (cesiumViewer.camera && cesiumViewer.camera.frustum) {
      cesiumViewer.camera.frustum.fov = degToRad(SYNTHETIC_VISION_DEFAULTS.verticalFovDeg);
      cesiumViewer.camera.frustum.near = 0.05;
    }
    const aircraftCartesian = Cesium.Cartesian3.fromDegrees(view.aircraftLon, view.aircraftLat, view.aircraftAltitudeM);
    const calibratedHeading = normalizeDeg(Number(view.heading || 0) + (cameraCalibration ? cameraCalibration.yawDeg : 0));
    const calibratedPitch = clamp(Number(view.pitch || 0) + (cameraCalibration ? cameraCalibration.pitchDeg : 0), -89, 89);
    const calibratedRoll = clamp(Number(view.roll || 0) + (cameraCalibration ? cameraCalibration.rollDeg : 0), -89, 89);
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
      verticalFovDeg: SYNTHETIC_VISION_DEFAULTS.verticalFovDeg,
      movementDebug,
      headingDegUsed: calibratedHeading,
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
      updateTerrainHeight(sampleAt(activeT));
      updateDebugOverlay(sampleAt(activeT), displayCamera);
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
    if (!snap && displayCamera && !isSyntheticCameraMode(target.mode)) {
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
      airport_elevation_ft: lerp(before.airport_elevation_ft, after.airport_elevation_ft),
      field_altitude_offset_ft: lerp(before.field_altitude_offset_ft, after.field_altitude_offset_ft),
      oat_c: lerp(before.oat_c, after.oat_c),
      estimated_slip_skid_g: lerp(before.estimated_slip_skid_g, after.estimated_slip_skid_g),
      estimated_wind_speed_kt: lerp(before.estimated_wind_speed_kt, after.estimated_wind_speed_kt),
      estimated_wind_direction_deg_true: lerpAngle(before.estimated_wind_direction_deg_true, after.estimated_wind_direction_deg_true),
      estimated_tas_kt: lerp(before.estimated_tas_kt, after.estimated_tas_kt),
      ias_kt: lerp(before.ias_kt, after.ias_kt),
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
        `camera attached: ${cameraMode === 'synthetic_vision' ? 'aircraft state' : 'forced test attitude'}`,
        `camera smoothing: none`,
        `SVT FOV: ${SYNTHETIC_VISION_DEFAULTS.verticalFovDeg.toFixed(0)} deg`,
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
    debugOverlay.innerHTML = `${escapeHtml(lines.join('\n'))}<div class="replay-debug-quality">${qualityRows}</div>`;
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
    } else {
      activeT = Math.max(0, Math.min(maxT, Number.isFinite(Number(audio.currentTime)) ? Number(audio.currentTime) : activeT));
    }
    timeline.value = String(activeT);
    timeLabel.textContent = fmtTime(activeT);
    safeRenderCesium(false);
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
    if (audio.paused) {
      audio.play();
      playButton.textContent = 'Pause';
    } else {
      audio.pause();
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
  if (cameraRangeInput) {
    cameraRangeInput.addEventListener('input', () => updateCameraSetting('rangeM', cameraRangeInput.value));
  }
  if (cameraHeightInput) {
    cameraHeightInput.addEventListener('input', () => updateCameraSetting('heightM', cameraHeightInput.value));
  }
  if (cameraPitchInput) {
    cameraPitchInput.addEventListener('input', () => updateCameraSetting('pitchDeg', cameraPitchInput.value));
  }
  if (cameraSmoothingInput) {
    cameraSmoothingInput.addEventListener('input', () => updateCameraSetting('smoothing', cameraSmoothingInput.value));
  }
  if (calibrationStepSelect) {
    calibrationStepSelect.addEventListener('change', () => {
      if (!cameraCalibration) return;
      cameraCalibration.stepM = clamp(firstFinite(calibrationStepSelect.value, 1), 0.1, 25);
      saveCameraCalibration();
      updateCalibrationPanel();
    });
  }
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
    if (event.target.closest('.replay-dock, .replay-camera-panel, .replay-calibration-panel, .cesium-viewer-toolbar')) {
      return;
    }
    togglePlayback();
  });
  audio.addEventListener('pause', () => {
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
    if (!payload || (standaloneReplay ? !standalonePlaying : audio.paused)) {
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

  async function loadReplay() {
    let data = null;
    try {
      const replayUrl = standaloneReplay
        ? `/admin/api/cockpit_recorder_standalone_replay.php?name=${encodeURIComponent(standaloneReplay)}`
        : `/api/recordings/replay.php?id=${encodeURIComponent(id)}&version=2`;
      const response = await fetch(replayUrl);
      const text = await response.text();
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

    const samples = (data.samples || []).map((sample) => ({
      ...sample,
      bank_deg: sample.roll_deg ?? sample.bank_deg ?? null,
      gps_altitude_ft: sample.altitude_ft ?? sample.gps_altitude_ft ?? null,
      estimated_indicated_altitude_ft: sample.altitude_ft ?? sample.estimated_indicated_altitude_ft ?? null,
      groundspeed_kt: sample.ground_speed_kt ?? sample.groundspeed_kt ?? null,
    }));

    payload = { ...data, samples };
    positionKeyframes = buildPositionKeyframes(payload.samples || []);
    if (loadStatus) loadStatus.remove();
    const maxT = Math.max(Number(payload.recording.duration) || 0, payload.samples.reduce((max, s) => Math.max(max, Number(s.t) || 0), 1), 1);
    timeline.max = String(maxT);
    try {
      await initCesium();
    } catch (err) {
      showCesiumError(String(err.message || err));
    }
    safeRenderCesium(true);
  }

  cameraCalibration = loadCameraCalibration();
  updateCalibrationPanel();
  cameraSettings = loadCameraSettings();
  syncCameraControls();
  loadReplay();
})();
</script>
<?php endif; ?>

<?php
cw_footer();
