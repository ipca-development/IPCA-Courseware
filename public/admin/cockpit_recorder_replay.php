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
.replay-page { display: grid; gap: 16px; }
.replay-card { background: #fff; border: 1px solid rgba(15, 23, 42, .12); border-radius: 16px; padding: 16px; box-shadow: 0 12px 28px rgba(15, 23, 42, .07); }
.replay-muted { color: #64748b; font-size: 13px; }
.replay-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 10px; padding: 12px; }
.replay-topbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; }
.replay-controls { display: grid; grid-template-columns: auto 1fr auto; gap: 10px; align-items: center; margin-top: 12px; }
.replay-range { width: 100%; accent-color: #1d4ed8; }
.replay-audio { width: 100%; margin-top: 10px; }
.replay-button { border: 0; border-radius: 8px; background: #1d4ed8; color: #fff; font-weight: 700; padding: 8px 14px; cursor: pointer; }
.cesium-cockpit { position: relative; width: 100%; height: min(72vh, 720px); background: #000; border-radius: 8px; overflow: hidden; }
.cesium-cockpit .cesium-viewer-bottom,
.cesium-cockpit .cesium-viewer-toolbar,
.cesium-cockpit .cesium-viewer-animationContainer,
.cesium-cockpit .cesium-viewer-timelineContainer,
.cesium-cockpit .cesium-viewer-fullscreenContainer { display: none !important; }
.cesium-unavailable { position: absolute; inset: 0; display: grid; place-items: center; color: #fff; background: linear-gradient(135deg, #0f172a, #1d4ed8); text-align: center; padding: 28px; z-index: 10; }
</style>

<div class="replay-page">
  <section class="replay-card">
    <div class="replay-topbar">
      <div>
        <h2 style="margin:0">Cockpit Recorder Replay</h2>
        <?php if ($recording): ?>
          <div class="replay-muted">
            <?= h((string)($recording['recording_uid'] ?? '')) ?>
            <?php if (!empty($recording['aircraft_registration']) || !empty($recording['aircraft_display_name'])): ?>
              · <?= h((string)($recording['aircraft_display_name'] ?: $recording['aircraft_registration'])) ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <div>
        <a href="/admin/cockpit_recorder.php">Back to uploads</a>
        <?php if ($recording): ?>
          · <a href="/admin/cockpit_recorder_g3x.php?id=<?= h((string)$id) ?>">G3X CSV</a>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <?php if ($error !== ''): ?>
    <div class="replay-error"><?= h($error) ?></div>
  <?php else: ?>
    <section class="replay-card" data-replay-id="<?= h((string)$id) ?>" data-cesium-token="<?= h($cesiumIonToken) ?>">
      <div id="loadStatus" class="replay-muted">Loading replay data…</div>
      <div id="cesiumReplay" class="cesium-cockpit"></div>
      <audio class="replay-audio" id="audio" controls preload="metadata" src="/admin/cockpit_recorder_audio.php?id=<?= h((string)$id) ?>"></audio>
      <div class="replay-controls">
        <button class="replay-button" type="button" id="playButton">Play</button>
        <input class="replay-range" id="timeline" type="range" min="0" max="1" step="0.1" value="0">
        <span id="timeLabel" class="replay-muted">0:00</span>
      </div>
    </section>
  <?php endif; ?>
</div>

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
  let payload = null;
  let activeT = 0;
  let animationFrame = null;
  let playbackClockBaseT = 0;
  let playbackClockBaseMs = null;
  let cesiumViewer = null;
  let cesiumReady = false;
  let cesiumCameraState = null;

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
  const bestAltitudeFt = (sample) => {
    if (!sample) return 0;
    return Number.isFinite(Number(sample.estimated_true_altitude_from_indicated_ft)) ? Number(sample.estimated_true_altitude_from_indicated_ft)
      : (Number.isFinite(Number(sample.estimated_indicated_altitude_ft)) ? Number(sample.estimated_indicated_altitude_ft)
      : (Number.isFinite(Number(sample.field_calibrated_true_altitude_ft)) ? Number(sample.field_calibrated_true_altitude_ft)
      : (Number.isFinite(Number(sample.gps_altitude_ft)) ? Number(sample.gps_altitude_ft) : 0)));
  };
  const cameraEyeAltitudeM = (sample) => Math.max(0, feetToMeters(bestAltitudeFt(sample))) + PILOT_EYE_HEIGHT_M;

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
      track_deg: lerpAngle(before.track_deg, after.track_deg),
    });
  }

  function smoothedSampleAt(t) {
    const base = sampleAt(t);
    if (!base) return null;
    const taps = [
      { offset: -1.2, weight: 1 },
      { offset: -0.6, weight: 2 },
      { offset: 0, weight: 4 },
      { offset: 0.6, weight: 2 },
      { offset: 1.2, weight: 1 },
    ];
    const samples = taps
      .map((tap) => ({ sample: sampleAt(Number(t) + tap.offset), weight: tap.weight }))
      .filter((tap) => tap.sample);
    const weightedNumber = (key) => {
      let total = 0;
      let weight = 0;
      for (const tap of samples) {
        const value = Number(tap.sample[key]);
        if (!Number.isFinite(value)) continue;
        total += value * tap.weight;
        weight += tap.weight;
      }
      return weight > 0 ? total / weight : base[key];
    };
    const weightedAngle = (key) => {
      let x = 0;
      let y = 0;
      let weight = 0;
      for (const tap of samples) {
        const value = Number(tap.sample[key]);
        if (!Number.isFinite(value)) continue;
        const rad = degToRad(value);
        x += Math.cos(rad) * tap.weight;
        y += Math.sin(rad) * tap.weight;
        weight += tap.weight;
      }
      return weight > 0 ? normalizeDeg(Math.atan2(y, x) * 180 / Math.PI) : base[key];
    };
    return Object.assign({}, base, {
      t: Number(t),
      lat: weightedNumber('lat'),
      lon: weightedNumber('lon'),
      gps_altitude_ft: weightedNumber('gps_altitude_ft'),
      baro_altitude_ft: weightedNumber('baro_altitude_ft'),
      vertical_speed_fpm: weightedNumber('vertical_speed_fpm'),
      adsb_baro_altitude_ft: weightedNumber('adsb_baro_altitude_ft'),
      adsb_vertical_speed_fpm: weightedNumber('adsb_vertical_speed_fpm'),
      estimated_baro_altitude_ft: weightedNumber('estimated_baro_altitude_ft'),
      estimated_vertical_speed_fpm: weightedNumber('estimated_vertical_speed_fpm'),
      field_calibrated_altitude_ft: weightedNumber('field_calibrated_altitude_ft'),
      field_calibrated_true_altitude_ft: weightedNumber('field_calibrated_true_altitude_ft'),
      estimated_indicated_altitude_ft: weightedNumber('estimated_indicated_altitude_ft'),
      estimated_true_altitude_from_indicated_ft: weightedNumber('estimated_true_altitude_from_indicated_ft'),
      estimated_slip_skid_g: weightedNumber('estimated_slip_skid_g'),
      groundspeed_kt: weightedNumber('groundspeed_kt'),
      pitch_deg: weightedNumber('pitch_deg'),
      bank_deg: weightedNumber('bank_deg'),
      heading_deg: weightedAngle('heading_deg'),
      true_heading_deg: weightedAngle('true_heading_deg'),
      track_deg: weightedAngle('track_deg'),
    });
  }

  function initCesium() {
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

      cesiumViewer.scene.globe.depthTestAgainstTerrain = false;
      cesiumViewer.scene.screenSpaceCameraController.enableCollisionDetection = false;

      cesiumReady = true;
      renderCesium();
    } catch (err) {
      showCesiumError(String(err.message || err));
    }
  }

  function showCesiumError(message) {
    const cesiumReplay = document.getElementById('cesiumReplay');
    if (!cesiumReplay) return;
    cesiumReplay.insertAdjacentHTML('beforeend', `<div class="cesium-unavailable"><div><strong>Cesium could not start.</strong><br>${String(message).replace(/[<>&]/g, (ch) => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[ch]))}</div></div>`);
  }

  function renderCesium() {
    if (!cesiumReady || !cesiumViewer) return;
    const s = smoothedSampleAt(activeT);
    if (!s || s.lat === null || s.lon === null) return;
    const altitudeM = cameraEyeAltitudeM(s);
    const track = Number.isFinite(Number(s.track_deg)) ? Number(s.track_deg) : null;
    const cameraHeading = Number.isFinite(Number(s.heading_deg))
      ? Number(s.heading_deg)
      : (track !== null ? track : 0);
    const pitch = Number.isFinite(Number(s.pitch_deg)) ? Number(s.pitch_deg) : 0;
    const bank = Number.isFinite(Number(s.bank_deg)) ? normalizeSignedDeg(Number(s.bank_deg)) : 0;
    cesiumCameraState = {
      lat: Number(s.lat),
      lon: Number(s.lon),
      altitudeM,
      heading: normalizeDeg(cameraHeading),
      pitch: Math.max(-30, Math.min(30, pitch)),
      roll: Math.max(-45, Math.min(45, bank)),
    };
    const smoothedPosition = Cesium.Cartesian3.fromDegrees(cesiumCameraState.lon, cesiumCameraState.lat, cesiumCameraState.altitudeM);
    cesiumViewer.camera.setView({
      destination: smoothedPosition,
      orientation: {
        heading: degToRad(cesiumCameraState.heading),
        pitch: degToRad(cesiumCameraState.pitch),
        roll: degToRad(cesiumCameraState.roll),
      },
    });
  }

  function safeRenderCesium() {
    try {
      renderCesium();
    } catch (err) {
      showCesiumError(String(err.message || err));
      cesiumReady = false;
    }
  }

  function seek(seconds, syncAudio) {
    const previousT = activeT;
    activeT = Math.max(0, Number(seconds) || 0);
    if (Math.abs(activeT - previousT) > 3) {
      cesiumCameraState = null;
    }
    timeline.value = String(activeT);
    timeLabel.textContent = fmtTime(activeT);
    if (syncAudio && Number.isFinite(audio.duration)) {
      audio.currentTime = Math.min(activeT, audio.duration || activeT);
    }
    safeRenderCesium();
    resetPlaybackClock();
  }

  function resetPlaybackClock(timestamp = performance.now()) {
    playbackClockBaseT = Number.isFinite(Number(audio.currentTime)) ? Number(audio.currentTime) : activeT;
    playbackClockBaseMs = timestamp;
  }

  function playbackClockTime(timestamp) {
    if (playbackClockBaseMs === null) {
      resetPlaybackClock(timestamp);
    }
    const rate = Number.isFinite(Number(audio.playbackRate)) ? Number(audio.playbackRate) : 1;
    const elapsed = Math.max(0, (timestamp - playbackClockBaseMs) / 1000) * rate;
    const predicted = playbackClockBaseT + elapsed;
    const audioTime = Number.isFinite(Number(audio.currentTime)) ? Number(audio.currentTime) : predicted;
    if (Math.abs(audioTime - predicted) > 0.35) {
      resetPlaybackClock(timestamp);
      return playbackClockBaseT;
    }
    const maxT = Number(timeline.max) || predicted;
    return Math.max(0, Math.min(maxT, predicted));
  }

  function updateCockpitPlayback(seconds) {
    const previousT = activeT;
    activeT = Math.max(0, Number(seconds) || 0);
    if (Math.abs(activeT - previousT) > 3) {
      cesiumCameraState = null;
    }
    timeline.value = String(activeT);
    timeLabel.textContent = fmtTime(activeT);
    safeRenderCesium();
  }

  timeline.addEventListener('input', () => seek(Number(timeline.value), true));
  audio.addEventListener('timeupdate', () => {
    if (audio.paused) {
      seek(audio.currentTime, false);
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
    playbackClockBaseMs = null;
    if (animationFrame !== null) {
      cancelAnimationFrame(animationFrame);
      animationFrame = null;
    }
  });
  audio.addEventListener('play', () => {
    playButton.textContent = 'Pause';
    resetPlaybackClock();
    if (animationFrame === null) {
      animationFrame = requestAnimationFrame(animatePlayback);
    }
  });

  function animatePlayback(timestamp) {
    if (audio.paused || !payload) {
      animationFrame = null;
      return;
    }
    updateCockpitPlayback(playbackClockTime(timestamp));
    animationFrame = requestAnimationFrame(animatePlayback);
  }

  window.addEventListener('resize', () => {
    if (!payload) return;
    safeRenderCesium();
  });

  async function loadReplay() {
    let data = null;
    try {
      const response = await fetch(`/api/recordings/replay.php?id=${encodeURIComponent(id)}`);
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

    payload = data;
    if (loadStatus) loadStatus.remove();
    const maxT = Math.max(Number(payload.recording.duration) || 0, payload.samples.reduce((max, s) => Math.max(max, Number(s.t) || 0), 1), 1);
    timeline.max = String(maxT);
    try {
      initCesium();
    } catch (err) {
      showCesiumError(String(err.message || err));
    }
    safeRenderCesium();
  }

  loadReplay();
})();
</script>
<?php endif; ?>

<?php
cw_footer();
