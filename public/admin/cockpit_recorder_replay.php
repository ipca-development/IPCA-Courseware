<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/CockpitRecorderService.php';

cw_require_admin();

$id = trim((string)($_GET['id'] ?? ''));
$error = '';
$recording = null;
$cesiumIonToken = trim((string)(getenv('CW_CESIUM_ION_TOKEN') ?: getenv('CESIUM_ION_TOKEN') ?: ''));
$cesiumIonToken = trim($cesiumIonToken, " \t\n\r\0\x0B\"'");

try {
    if ($id === '') {
        throw new RuntimeException('Recording id is required.');
    }
    $recording = (new CockpitRecorderService($pdo))->recordingByAnyId($id);
    if (!$recording) {
        throw new RuntimeException('Recording not found.');
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
  grid-template-columns: auto auto auto 1fr auto;
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
  min-width: 260px;
  max-width: 340px;
  color: #dbeafe;
  background: rgba(15, 23, 42, .78);
  border: 1px solid rgba(148, 163, 184, .35);
  border-radius: 10px;
  padding: 10px;
  font: 12px/1.4 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
  white-space: pre-wrap;
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
</style>

<?php if ($error !== ''): ?>
  <div class="replay-error"><?= h($error) ?></div>
<?php else: ?>
  <div
    class="replay-immersive"
    data-replay-id="<?= h((string)$id) ?>"
    data-replay-mode="cesium-only"
    data-cesium-token="<?= h($cesiumIonToken) ?>"
  >
    <div id="loadStatus" class="replay-load">Loading replay data…</div>
    <div id="cesiumReplay" class="cesium-cockpit"></div>
    <div id="replayDebug" class="replay-debug">Replay debug initializing...</div>
    <div id="terrainWarning" class="replay-terrain-warning" hidden></div>
    <audio id="audio" preload="metadata" src="/admin/cockpit_recorder_audio.php?id=<?= h((string)$id) ?>"></audio>
    <div class="replay-dock">
      <a href="/admin/cockpit_recorder.php">← Back</a>
      <button class="replay-button" type="button" id="playButton">Play</button>
      <select class="replay-select" id="cameraMode" aria-label="Camera mode">
        <option value="follow_heading" selected>Follow heading</option>
        <option value="north_up">North up</option>
        <option value="free">Free camera</option>
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
  const cesiumToken = root ? (root.getAttribute('data-cesium-token') || '').trim().replace(/^['"]+|['"]+$/g, '') : '';
  const loadStatus = document.getElementById('loadStatus');
  const timeline = document.getElementById('timeline');
  const timeLabel = document.getElementById('timeLabel');
  const audio = document.getElementById('audio');
  const playButton = document.getElementById('playButton');
  const cameraModeSelect = document.getElementById('cameraMode');
  const debugOverlay = document.getElementById('replayDebug');
  const terrainWarning = document.getElementById('terrainWarning');
  let payload = null;
  let activeT = 0;
  let animationFrame = null;
  let cesiumViewer = null;
  let cesiumReady = false;
  let displayCamera = null;
  let lastRenderMs = null;
  let positionKeyframes = [];
  let cameraMode = 'follow_heading';
  let terrainEnabled = false;
  let terrainStatus = 'not_initialized';
  let terrainWarningMessage = '';
  let lastTerrainSampleMs = 0;
  let lastTerrainHeightM = null;
  let lastTerrainRequestKey = '';
  let lastVisualAltitudeM = null;

  const CAMERA_ROT_SMOOTH_RATE = 7;
  const CAMERA_ALTITUDE_SMOOTH_RATE = 3;
  const CAMERA_SNAP_SEEK_SEC = 0.75;
  const POSITION_KEY_MIN_DIST_M = 0.15;
  const CHASE_RANGE_M = 170;
  const CHASE_HEIGHT_M = 65;
  const CHASE_PITCH_DEG = -28;

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
  const visualAltitudeM = (sample) => {
    const msl = rawAltitudeM(sample);
    if (Number.isFinite(Number(sample && sample.visual_altitude_ft))) {
      return feetToMeters(Number(sample.visual_altitude_ft));
    }
    if (isGroundSample(sample) && Number.isFinite(lastTerrainHeightM)) {
      return Math.max(msl, lastTerrainHeightM + 2);
    }
    if (Number.isFinite(lastTerrainHeightM)) {
      return Math.max(msl, lastTerrainHeightM + 2);
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

  const haversineM = (lat1, lon1, lat2, lon2) => {
    const phi1 = degToRad(lat1);
    const phi2 = degToRad(lat2);
    const dPhi = degToRad(lat2 - lat1);
    const dLambda = degToRad(lon2 - lon1);
    const a = Math.sin(dPhi / 2) ** 2 + Math.cos(phi1) * Math.cos(phi2) * Math.sin(dLambda / 2) ** 2;
    return 6371000 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(Math.max(0, 1 - a)));
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
    if (Number.isFinite(Number(sample.camera_heading_deg))) {
      return normalizeDeg(Number(sample.camera_heading_deg));
    }
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

  function targetCameraAt(t) {
    const pos = positionAt(t);
    const s = sampleAt(t);
    if (!pos || !s) return null;
    const aircraftHeading = Number.isFinite(Number(s.camera_heading_deg))
      ? normalizeDeg(Number(s.camera_heading_deg))
      : trueHeadingFromSample(s);
    const heading = cameraMode === 'north_up' ? 0 : aircraftHeading;
    const altitudeM = visualAltitudeM(s);
    return {
      lat: pos.lat,
      lon: pos.lon,
      altitudeM,
      rawAltitudeM: rawAltitudeM(s),
      visualAltitudeM: altitudeM,
      aircraftHeading,
      heading,
      pitch: CHASE_PITCH_DEG,
      roll: 0,
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
  }

  function applyCameraModeControls() {
    if (!cesiumViewer) return;
    const controller = cesiumViewer.scene.screenSpaceCameraController;
    const free = cameraMode === 'free';
    controller.enableRotate = free;
    controller.enableTranslate = free;
    controller.enableZoom = free;
    controller.enableTilt = free;
    controller.enableLook = free;
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
    if (!snap && displayCamera) {
      const rotAlpha = smoothFactor(CAMERA_ROT_SMOOTH_RATE, dtSec);
      const altAlpha = smoothFactor(CAMERA_ALTITUDE_SMOOTH_RATE, dtSec);
      view = {
        lat: target.lat,
        lon: target.lon,
        altitudeM: displayCamera.altitudeM + (target.altitudeM - displayCamera.altitudeM) * altAlpha,
        rawAltitudeM: target.rawAltitudeM,
        visualAltitudeM: displayCamera.visualAltitudeM + (target.visualAltitudeM - displayCamera.visualAltitudeM) * altAlpha,
        aircraftHeading: target.aircraftHeading,
        heading: lerpAngleDeg(displayCamera.heading, target.heading, rotAlpha),
        pitch: CHASE_PITCH_DEG,
        roll: 0,
      };
    }
    displayCamera = Object.assign({}, view);
    lastVisualAltitudeM = view.visualAltitudeM;
    const cameraPos = offsetLatLon(view.lat, view.lon, view.heading, CHASE_RANGE_M, 0);
    cesiumViewer.camera.setView({
      destination: Cesium.Cartesian3.fromDegrees(cameraPos.lon, cameraPos.lat, view.altitudeM + CHASE_HEIGHT_M),
      orientation: {
        heading: degToRad(view.heading),
        pitch: degToRad(view.pitch),
        roll: degToRad(view.roll),
      },
    });
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
      bank_deg: lerp(before.bank_deg, after.bank_deg),
      heading_deg: lerpAngle(before.heading_deg, after.heading_deg),
      true_heading_deg: lerpAngle(before.true_heading_deg, after.true_heading_deg),
      camera_heading_deg: lerpAngle(before.camera_heading_deg, after.camera_heading_deg),
      magnetic_variation_deg: lerp(before.magnetic_variation_deg, after.magnetic_variation_deg),
      track_deg: lerpAngle(before.track_deg, after.track_deg),
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
    const heading = sample && Number.isFinite(Number(sample.heading_deg)) ? normalizeDeg(Number(sample.heading_deg)) : null;
    const cameraHeading = view && Number.isFinite(Number(view.heading)) ? normalizeDeg(Number(view.heading)) : null;
    const pitch = sample && Number.isFinite(Number(sample.pitch_deg)) ? Number(sample.pitch_deg) : null;
    const roll = sample && Number.isFinite(Number(sample.bank_deg ?? sample.roll_deg)) ? Number(sample.bank_deg ?? sample.roll_deg) : null;
    const altitudeFt = sample && Number.isFinite(Number(sample.altitude_ft_msl ?? sample.altitude_ft)) ? Number(sample.altitude_ft_msl ?? sample.altitude_ft) : null;
    const visualFt = Number.isFinite(lastVisualAltitudeM) ? lastVisualAltitudeM / 0.3048 : null;
    const vs = sample && Number.isFinite(Number(sample.vertical_speed_fpm)) ? Number(sample.vertical_speed_fpm) : null;
    const terrain = Number.isFinite(lastTerrainHeightM) ? `${lastTerrainHeightM.toFixed(1)} m` : '--';
    debugOverlay.textContent = [
      `t: ${sample ? Number(sample.t || 0).toFixed(1) : '--'} s`,
      `aircraft heading: ${heading === null ? '--' : heading.toFixed(1)} deg`,
      `camera heading: ${cameraHeading === null ? '--' : cameraHeading.toFixed(1)} deg`,
      `camera mode: ${cameraMode}`,
      `pitch: ${pitch === null ? '--' : pitch.toFixed(1)} deg`,
      `roll/bank: ${roll === null ? '--' : roll.toFixed(1)} deg`,
      `altitude MSL: ${altitudeFt === null ? '--' : altitudeFt.toFixed(1)} ft`,
      `visual altitude: ${visualFt === null ? '--' : visualFt.toFixed(1)} ft`,
      `vertical speed: ${vs === null ? '--' : vs.toFixed(1)} fpm`,
      `terrain enabled: ${terrainEnabled ? 'yes' : 'no'} (${terrainStatus})`,
      `terrain under aircraft: ${terrain}`,
    ].join('\n');
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
      });

      try {
        if (typeof Cesium.createWorldTerrainAsync === 'function') {
          cesiumViewer.terrainProvider = await Cesium.createWorldTerrainAsync();
        } else if (typeof Cesium.createWorldTerrain === 'function') {
          cesiumViewer.terrainProvider = Cesium.createWorldTerrain();
        } else {
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
    const snap = forceSnap || Math.abs(activeT - previousT) > CAMERA_SNAP_SEEK_SEC;
    if (snap) {
      resetDisplayCamera();
    }
    timeline.value = String(activeT);
    timeLabel.textContent = fmtTime(activeT);
    if (syncAudio && Number.isFinite(audio.duration)) {
      audio.currentTime = Math.min(activeT, audio.duration || activeT);
    }
    safeRenderCesium(snap);
  }

  function updateCockpitPlayback() {
    const maxT = Number(timeline.max) || 0;
    activeT = Math.max(0, Math.min(maxT, Number.isFinite(Number(audio.currentTime)) ? Number(audio.currentTime) : activeT));
    timeline.value = String(activeT);
    timeLabel.textContent = fmtTime(activeT);
    safeRenderCesium(false);
  }

  cameraModeSelect.addEventListener('change', () => {
    cameraMode = cameraModeSelect.value || 'follow_heading';
    applyCameraModeControls();
    resetDisplayCamera();
    safeRenderCesium(true);
  });
  timeline.addEventListener('input', () => seek(Number(timeline.value), true, true));
  audio.addEventListener('timeupdate', () => {
    if (audio.paused) {
      seek(audio.currentTime, false, true);
    }
  });
  playButton.addEventListener('click', () => {
    if (audio.paused) {
      audio.play();
      playButton.textContent = 'Pause';
    } else {
      audio.pause();
      playButton.textContent = 'Play';
    }
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
    if (audio.paused || !payload) {
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
      const response = await fetch(`/api/recordings/replay.php?id=${encodeURIComponent(id)}&version=2`);
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

  loadReplay();
})();
</script>
<?php endif; ?>

<?php
cw_footer();
