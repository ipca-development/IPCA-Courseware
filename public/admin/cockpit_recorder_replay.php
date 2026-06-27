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
.replay-layout { display: grid; grid-template-columns: minmax(210px, .8fr) minmax(440px, 2fr) minmax(260px, 1fr); gap: 14px; align-items: stretch; }
.replay-left, .replay-center, .replay-right { min-height: 520px; }
.phase-list { display: grid; gap: 8px; }
.phase-row { border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px; background: #f8fafc; display: grid; gap: 4px; }
.phase-row.is-active { border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37, 99, 235, .12); background: #eff6ff; }
.phase-row button, .replay-button { border: 0; border-radius: 8px; background: #1d4ed8; color: #fff; font-weight: 700; padding: 6px 9px; cursor: pointer; }
.replay-map { height: 100%; border-radius: 10px; background: rgba(15, 23, 42, .18); overflow: hidden; position: relative; }
.replay-map svg { width: 100%; height: 100%; display: block; }
.replay-map-layer { position: absolute; inset: 0; overflow: hidden; background: transparent; opacity: .68; }
.replay-map-layer img { position: absolute; width: 256px; height: 256px; max-width: none; user-select: none; pointer-events: none; }
.replay-map-overlay { position: absolute; inset: 0; z-index: 2; }
.replay-map-label { position: absolute; left: 8px; top: 8px; z-index: 3; border-radius: 999px; background: rgba(15, 23, 42, .72); color: #fff; font-size: 10px; font-weight: 800; padding: 4px 7px; backdrop-filter: blur(6px); }
.replay-map-attribution { position: absolute; right: 6px; bottom: 4px; z-index: 3; border-radius: 5px; background: rgba(15, 23, 42, .48); color: rgba(255,255,255,.82); font-size: 9px; padding: 2px 5px; }
.replay-map-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 6px; }
.replay-map-toolbar-group { display: inline-flex; gap: 6px; align-items: center; }
.replay-map-toolbar button { border: 1px solid rgba(255,255,255,.48); border-radius: 999px; background: rgba(15, 23, 42, .72); color: #fff; font-weight: 900; padding: 4px 9px; cursor: pointer; backdrop-filter: blur(6px); }
.replay-map-toolbar button.is-active { background: rgba(217, 70, 239, .82); color: #fff; border-color: rgba(255,255,255,.7); }
.replay-map-toolbar .zoom-button { min-width: 34px; padding-left: 0; padding-right: 0; }
.replay-3d { height: 220px; margin-top: 12px; border: 1px solid #dbeafe; border-radius: 14px; background: linear-gradient(180deg, #eff6ff 0%, #fff 62%, #f8fafc 100%); overflow: hidden; position: relative; }
.replay-3d svg { width: 100%; height: 100%; display: block; }
.replay-3d-label { position: absolute; left: 12px; top: 10px; z-index: 2; border-radius: 999px; background: rgba(15, 23, 42, .75); color: #fff; font-size: 12px; font-weight: 700; padding: 5px 9px; }
.cesium-cockpit { height: 540px; border-radius: 16px; border: 1px solid #dbeafe; background: #0f172a; overflow: hidden; position: relative; }
.cesium-cockpit .cesium-widget, .cesium-cockpit canvas { width: 100%; height: 100%; }
.cesium-unavailable { position: absolute; inset: 0; display: grid; place-items: center; color: #fff; background: linear-gradient(135deg, #0f172a, #1d4ed8); text-align: center; padding: 28px; z-index: 10; }
.cesium-hud { position: absolute; inset: 0; z-index: 4; pointer-events: none; color: #fff; font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; text-shadow: 0 2px 8px rgba(0,0,0,.72); }
.hud-tape { position: absolute; top: 84px; width: 78px; height: 318px; border: 1px solid rgba(255,255,255,.72); border-radius: 10px; background: rgba(15,23,42,.34); backdrop-filter: blur(4px); }
.hud-tape-left { left: 22px; }
.hud-tape-right { right: 118px; }
.hud-vsi { position: absolute; top: 122px; right: 38px; width: 48px; height: 240px; border: 1px solid rgba(255,255,255,.68); border-radius: 9px; background: rgba(15,23,42,.30); }
.hud-value-box { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); min-width: 74px; text-align: center; border-radius: 7px; background: rgba(0,0,0,.82); color: #fff; font-size: 22px; font-weight: 900; padding: 6px 9px; }
.hud-tape-label { position: absolute; left: 0; right: 0; top: 8px; text-align: center; font-size: 10px; font-weight: 800; opacity: .9; }
.hud-altimeter-setting { position: absolute; right: 128px; top: 414px; border-radius: 999px; background: rgba(0,0,0,.76); font-size: 12px; font-weight: 800; padding: 5px 9px; }
.hud-attitude { position: absolute; left: 50%; top: 46%; width: 380px; height: 230px; transform: translate(-50%, -50%); }
.hud-horizon-line { position: absolute; left: 0; right: 0; top: 50%; height: 2px; background: rgba(255,255,255,.62); box-shadow: 0 0 10px rgba(255,255,255,.28); }
.hud-bank-arc { position: absolute; left: 50%; top: 6px; width: 310px; height: 155px; transform: translateX(-50%); border-top: 3px solid rgba(255,255,255,.78); border-radius: 310px 310px 0 0; }
.hud-aircraft-symbol { position: absolute; left: 50%; top: 132px; width: 170px; height: 44px; transform: translateX(-50%); }
.hud-aircraft-symbol:before { content: ""; position: absolute; left: 0; right: 0; top: 20px; height: 5px; background: #ffd400; box-shadow: 0 0 0 1px rgba(0,0,0,.35); clip-path: polygon(0 40%, 42% 40%, 50% 0, 58% 40%, 100% 40%, 100% 60%, 58% 60%, 50% 100%, 42% 60%, 0 60%); }
.hud-slip { position: absolute; left: 50%; bottom: 34px; width: 132px; height: 30px; transform: translateX(-50%); border-radius: 999px; background: rgba(0,0,0,.82); }
.hud-slip:before, .hud-slip:after { content: ""; position: absolute; top: 2px; width: 2px; height: 26px; background: rgba(255,255,255,.9); }
.hud-slip:before { left: 46px; }
.hud-slip:after { right: 46px; }
.hud-slip-ball { position: absolute; top: 4px; left: 54px; width: 22px; height: 22px; border-radius: 50%; background: #fff; transition: transform .18s linear; }
.hud-heading { position: absolute; left: 50%; bottom: 78px; transform: translateX(-50%); min-width: 96px; text-align: center; border-radius: 8px; background: rgba(0,0,0,.78); font-size: 20px; font-weight: 900; padding: 6px 10px; }
.cockpit-map-overlay { position: absolute; left: 16px; bottom: 48px; z-index: 7; width: 190px; height: 168px; border-radius: 13px; padding: 8px; background: rgba(15, 23, 42, .28); border: 1px solid rgba(255,255,255,.26); box-shadow: 0 14px 30px rgba(0,0,0,.24); backdrop-filter: blur(3px); pointer-events: auto; }
.cockpit-map-overlay .replay-map { height: 130px; }
.cockpit-map-title { color: #fff; font-size: 10px; font-weight: 900; letter-spacing: .06em; text-transform: uppercase; text-shadow: 0 2px 6px rgba(0,0,0,.8); }
.replay-controls { display: grid; grid-template-columns: auto 1fr auto; gap: 10px; align-items: center; margin-top: 12px; }
.replay-range { width: 100%; accent-color: #1d4ed8; }
.replay-graphs { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.graph-card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px; background: #f8fafc; }
.graph-card h4 { margin: 0 0 6px; font-size: 12px; color: #334155; text-transform: uppercase; letter-spacing: .04em; }
.graph-value { font-size: 22px; font-weight: 900; color: #0f172a; line-height: 1; }
.graph-range { color: #64748b; font-size: 11px; margin-top: 2px; }
.graph-card svg { width: 100%; height: 78px; display: block; }
.detail-grid { display: grid; gap: 8px; font-size: 13px; }
.detail-row { display: flex; justify-content: space-between; gap: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; }
.event-list { display: grid; gap: 6px; margin-top: 10px; }
.event-row { border-left: 3px solid #1d4ed8; padding: 6px 8px; background: #f8fafc; border-radius: 8px; font-size: 12px; }
.replay-audio { width: 100%; margin-top: 10px; }
.replay-topbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; }
@media (max-width: 1180px) { .replay-layout { grid-template-columns: 1fr; } .replay-left, .replay-center, .replay-right { min-height: auto; } .replay-graphs { grid-template-columns: 1fr; } .cesium-cockpit { height: 460px; } }
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
    <section class="replay-layout" data-replay-id="<?= h((string)$id) ?>">
      <aside class="replay-card replay-left">
        <h3 style="margin-top:0">Flight Phases</h3>
        <div class="phase-list" id="phaseList"><div class="replay-muted">Loading phases...</div></div>
      </aside>

      <main class="replay-card replay-center">
        <h3 style="margin-top:0">Flight Replay</h3>
        <div class="cesium-cockpit" id="cesiumReplay" data-cesium-token="<?= h($cesiumIonToken) ?>">
          <?php if ($cesiumIonToken === ''): ?>
            <div class="cesium-unavailable">
              <div>
                <strong>Cesium token not configured.</strong><br>
                Set <code>CW_CESIUM_ION_TOKEN</code> or <code>CESIUM_ION_TOKEN</code> on the server.
              </div>
            </div>
          <?php endif; ?>
          <div class="cesium-hud" id="cesiumHud" hidden>
            <div class="hud-tape hud-tape-left"><div class="hud-tape-label">GPS GS</div><div class="hud-value-box" id="hudSpeed">-- KT</div></div>
            <div class="hud-horizon-line"></div>
            <div class="hud-attitude"><div class="hud-bank-arc"></div><div class="hud-aircraft-symbol"></div></div>
            <div class="hud-tape hud-tape-right"><div class="hud-tape-label">ALT</div><div class="hud-value-box" id="hudAltitude">-- FT</div></div>
            <div class="hud-vsi"><div class="hud-value-box" id="hudVsi">--</div></div>
            <div class="hud-altimeter-setting" id="hudAltimeter">-- IN</div>
            <div class="hud-heading" id="hudHeading">HDG ---</div>
            <div class="hud-slip"><div class="hud-slip-ball" id="hudSlipBall"></div></div>
          </div>
          <div class="cockpit-map-overlay" aria-label="Track overlay map">
            <div class="replay-map-toolbar">
              <div class="cockpit-map-title">Track</div>
              <div class="replay-map-toolbar-group" role="group" aria-label="Map zoom">
                <button type="button" class="zoom-button" id="zoomOutButton">-</button>
                <button type="button" class="zoom-button" id="zoomInButton">+</button>
              </div>
            </div>
            <div class="replay-map" id="flightMap"></div>
          </div>
        </div>
        <div class="replay-3d" id="flight3D" hidden>
          <div class="replay-3d-label">3D GPS altitude view</div>
        </div>
        <audio class="replay-audio" id="audio" controls preload="metadata" src="/admin/cockpit_recorder_audio.php?id=<?= h((string)$id) ?>"></audio>
        <div class="replay-controls">
          <button class="replay-button" type="button" id="playButton">Play</button>
          <input class="replay-range" id="timeline" type="range" min="0" max="1" step="0.1" value="0">
          <span id="timeLabel" class="replay-muted">0:00</span>
        </div>
      </main>

      <aside class="replay-card replay-right">
        <h3 style="margin-top:0">Flight Details</h3>
        <div class="detail-grid" id="details"><div class="replay-muted">Loading details...</div></div>
        <h4>Events</h4>
        <div class="event-list" id="eventList"></div>
      </aside>
    </section>

    <section class="replay-card">
      <h3 style="margin-top:0">Parameters</h3>
      <div class="replay-graphs" id="graphs"></div>
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
  const phaseList = document.getElementById('phaseList');
  const flightMap = document.getElementById('flightMap');
  const flight3D = document.getElementById('flight3D');
  const cesiumReplay = document.getElementById('cesiumReplay');
  const cesiumHud = document.getElementById('cesiumHud');
  const hudSpeed = document.getElementById('hudSpeed');
  const hudAltitude = document.getElementById('hudAltitude');
  const hudVsi = document.getElementById('hudVsi');
  const hudAltimeter = document.getElementById('hudAltimeter');
  const hudHeading = document.getElementById('hudHeading');
  const hudSlipBall = document.getElementById('hudSlipBall');
  const eventList = document.getElementById('eventList');
  const details = document.getElementById('details');
  const graphs = document.getElementById('graphs');
  const timeline = document.getElementById('timeline');
  const timeLabel = document.getElementById('timeLabel');
  const audio = document.getElementById('audio');
  const playButton = document.getElementById('playButton');
  const zoomOutButton = document.getElementById('zoomOutButton');
  const zoomInButton = document.getElementById('zoomInButton');
  let payload = null;
  let activeT = 0;
  let mapMode = 'aircraft';
  let zoomOffset = 0;
  let animationFrame = null;
  let lastAnimationRenderMs = 0;
  let lastPanelRenderMs = 0;
  let playbackClockBaseT = 0;
  let playbackClockBaseMs = null;
  let cesiumViewer = null;
  let cesiumAircraft = null;
  let cesiumReady = false;
  let cesiumCameraState = null;

  const fmtTime = (seconds) => {
    seconds = Math.max(0, Math.round(Number(seconds) || 0));
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`;
  };

  const number = (value, suffix, digits = 1) => value === null || value === undefined || Number.isNaN(Number(value)) ? '--' : `${Number(value).toFixed(digits)}${suffix}`;
  const feetToMeters = (feet) => Number(feet || 0) * 0.3048;
  const degToRad = (deg) => Number(deg || 0) * Math.PI / 180;
  const normalizeDeg = (deg) => ((Number(deg) % 360) + 360) % 360;
  const bearingBetween = (from, to) => {
    if (!from || !to || from.lat === null || from.lon === null || to.lat === null || to.lon === null) return null;
    const lat1 = degToRad(from.lat);
    const lat2 = degToRad(to.lat);
    const deltaLon = degToRad(Number(to.lon) - Number(from.lon));
    const y = Math.sin(deltaLon) * Math.cos(lat2);
    const x = Math.cos(lat1) * Math.sin(lat2) - Math.sin(lat1) * Math.cos(lat2) * Math.cos(deltaLon);
    const bearing = Math.atan2(y, x) * 180 / Math.PI;
    return Number.isFinite(bearing) ? normalizeDeg(bearing) : null;
  };
  const bestAltitudeFt = (sample) => {
    if (!sample) return 0;
    return Number.isFinite(Number(sample.estimated_true_altitude_from_indicated_ft)) ? Number(sample.estimated_true_altitude_from_indicated_ft)
      : (Number.isFinite(Number(sample.estimated_indicated_altitude_ft)) ? Number(sample.estimated_indicated_altitude_ft)
      : (Number.isFinite(Number(sample.field_calibrated_true_altitude_ft)) ? Number(sample.field_calibrated_true_altitude_ft)
      : (Number.isFinite(Number(sample.gps_altitude_ft)) ? Number(sample.gps_altitude_ft) : 0)));
  };

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

  function activePhase(t) {
    if (!payload) return null;
    return payload.phases.find((phase) => t >= phase.start && t <= phase.end) || payload.phases[0] || null;
  }

  function gpsBounds(samples) {
    const points = samples.filter((s) => s.lat !== null && s.lon !== null);
    if (!points.length) return null;
    const lats = points.map((s) => Number(s.lat));
    const lons = points.map((s) => Number(s.lon));
    return {
      minLat: Math.min(...lats),
      maxLat: Math.max(...lats),
      minLon: Math.min(...lons),
      maxLon: Math.max(...lons),
      count: points.length,
    };
  }

  function isStationaryRecording(samples) {
    const bounds = gpsBounds(samples);
    if (!bounds) return false;
    const latSpanMeters = Math.abs(bounds.maxLat - bounds.minLat) * 111320;
    const avgLatRad = ((bounds.maxLat + bounds.minLat) / 2) * Math.PI / 180;
    const lonSpanMeters = Math.abs(bounds.maxLon - bounds.minLon) * 111320 * Math.max(0.1, Math.cos(avgLatRad));
    return Math.max(latSpanMeters, lonSpanMeters) < 30;
  }

  const tileSize = 256;
  const satelliteTileUrl = (z, x, y) => `https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/${z}/${y}/${x}`;
  const clampLat = (lat) => Math.max(-85.05112878, Math.min(85.05112878, Number(lat)));
  const wrapTileX = (x, z) => {
    const max = 2 ** z;
    return ((x % max) + max) % max;
  };
  const lonToWorldX = (lon, z) => ((Number(lon) + 180) / 360) * (2 ** z) * tileSize;
  const latToWorldY = (lat, z) => {
    const rad = clampLat(lat) * Math.PI / 180;
    return (0.5 - Math.log((1 + Math.sin(rad)) / (1 - Math.sin(rad))) / (4 * Math.PI)) * (2 ** z) * tileSize;
  };

  function chooseMapView(samples, width, height, stationary) {
    const bounds = gpsBounds(samples);
    if (!bounds) return null;
    const centerLat = (bounds.minLat + bounds.maxLat) / 2;
    const centerLon = (bounds.minLon + bounds.maxLon) / 2;
    if (stationary) return { centerLat, centerLon, zoom: 18 };

    for (let zoom = 18; zoom >= 4; zoom--) {
      const minX = lonToWorldX(bounds.minLon, zoom);
      const maxX = lonToWorldX(bounds.maxLon, zoom);
      const minY = latToWorldY(bounds.maxLat, zoom);
      const maxY = latToWorldY(bounds.minLat, zoom);
      if ((maxX - minX) <= width * 0.76 && (maxY - minY) <= height * 0.76) {
        return { centerLat, centerLon, zoom };
      }
    }
    return { centerLat, centerLon, zoom: 4 };
  }

  function clampZoom(zoom) {
    return Math.max(4, Math.min(19, Math.round(Number(zoom) || 4)));
  }

  function chooseActiveMapView(samples, width, height, stationary, current) {
    const routeView = chooseMapView(samples, width, height, stationary);
    if (!routeView) return null;

    if ((mapMode === 'aircraft' || mapMode === 'follow') && current && current.lat !== null && current.lon !== null) {
      const baseZoom = stationary ? 18 : Math.max(routeView.zoom + 4, 15);
      return {
        centerLat: Number(current.lat),
        centerLon: Number(current.lon),
        zoom: clampZoom(baseZoom + zoomOffset),
      };
    }

    return {
      centerLat: routeView.centerLat,
      centerLon: routeView.centerLon,
      zoom: clampZoom(routeView.zoom + zoomOffset),
    };
  }

  function projectGeoPoint(sample, view, width, height) {
    if (!view || sample.lat === null || sample.lon === null) return null;
    const lat = Number(sample.lat);
    const lon = Number(sample.lon);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return null;
    const centerX = lonToWorldX(view.centerLon, view.zoom);
    const centerY = latToWorldY(view.centerLat, view.zoom);
    const x = lonToWorldX(lon, view.zoom) - centerX + width / 2;
    const y = latToWorldY(lat, view.zoom) - centerY + height / 2;
    if (!Number.isFinite(x) || !Number.isFinite(y)) return null;
    return Object.assign({}, sample, { x, y });
  }

  function renderSatelliteTiles(view, width, height) {
    if (!view) return '';
    const centerX = lonToWorldX(view.centerLon, view.zoom);
    const centerY = latToWorldY(view.centerLat, view.zoom);
    const startTileX = Math.floor((centerX - width / 2) / tileSize);
    const endTileX = Math.floor((centerX + width / 2) / tileSize);
    const startTileY = Math.floor((centerY - height / 2) / tileSize);
    const endTileY = Math.floor((centerY + height / 2) / tileSize);
    const maxTile = (2 ** view.zoom) - 1;
    let html = '<div class="replay-map-layer">';
    for (let tileX = startTileX; tileX <= endTileX; tileX++) {
      for (let tileY = startTileY; tileY <= endTileY; tileY++) {
        if (tileY < 0 || tileY > maxTile) continue;
        const wrappedX = wrapTileX(tileX, view.zoom);
        const left = tileX * tileSize - centerX + width / 2;
        const top = tileY * tileSize - centerY + height / 2;
        html += `<img alt="" src="${satelliteTileUrl(view.zoom, wrappedX, tileY)}" style="left:${left.toFixed(1)}px;top:${top.toFixed(1)}px">`;
      }
    }
    html += '</div>';
    return html;
  }

  function renderMap() {
    const width = Math.max(150, Math.round(flightMap.clientWidth || 190));
    const height = Math.max(110, Math.round(flightMap.clientHeight || 130));
    const stationary = isStationaryRecording(payload.samples);
    const current = sampleAt(activeT);
    const view = chooseActiveMapView(payload.samples, width, height, stationary, current);
    const points = payload.samples
      .map((sample) => projectGeoPoint(sample, view, width, height))
      .filter(Boolean);
    const pointForTime = (t) => {
      if (!points.length) return null;
      let best = points[0];
      for (const point of points) {
        if (point.t > t) break;
        best = point;
      }
      return best;
    };
    const currentPoint = current ? pointForTime(current.t) : null;
    const drawablePoints = downsamplePoints(points, 1600);
    const path = stationary ? '' : drawablePoints.map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ');
    const recentTrail = downsamplePoints(points
      .filter((p) => p.t >= activeT - 90 && p.t <= activeT + 3)
      , 500)
      .map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`)
      .join(' ');
    const eventMarkers = (payload.events || []).map((event) => {
      const projected = pointForTime(event.start);
      if (!projected) return '';
      return `<circle cx="${projected.x}" cy="${projected.y}" r="5" fill="#f97316"><title>${event.event_type} ${fmtTime(event.start)}</title></circle>`;
    }).join('');
    const pathLayer = path
      ? `<polyline points="${path}" fill="none" stroke="#d946ef" stroke-width="3.5" stroke-linejoin="round" stroke-linecap="round" opacity=".92"></polyline>`
      : (!points.length ? '<text x="12" y="28" fill="#fff" font-size="11">No GPS</text>' : '');
    const stationaryMessage = stationary
      ? `<text x="${width / 2}" y="${height / 2 + 16}" text-anchor="middle" fill="#fff" font-size="11">Stationary GPS</text>`
      : '';
    const trackAngle = current && Number.isFinite(Number(current.track_deg)) ? Number(current.track_deg) : null;
    const headingAngle = current && Number.isFinite(Number(current.heading_deg)) ? Number(current.heading_deg) : null;
    const groundspeed = current && Number.isFinite(Number(current.groundspeed_kt)) ? Number(current.groundspeed_kt) : 0;
    const aircraftAngle = trackAngle !== null && groundspeed >= 5 ? trackAngle : (headingAngle ?? trackAngle ?? 0);
    const aircraftAngleSource = trackAngle !== null && groundspeed >= 5 ? 'GPS track' : (headingAngle !== null ? 'heading' : 'GPS track');
    flightMap.innerHTML = `
      <div class="replay-map-label">${view ? `Z${view.zoom}` : 'Track'}</div>
      <svg class="replay-map-overlay" viewBox="0 0 ${width} ${height}" role="img" aria-label="Flight path">
        <rect width="${width}" height="${height}" fill="rgba(15, 23, 42, .22)"></rect>
        <defs><linearGradient id="bg" x1="0" x2="1" y1="0" y2="1"><stop stop-color="#eff6ff"/><stop offset="1" stop-color="#ffffff"/></linearGradient></defs>
        ${pathLayer}
        ${recentTrail ? `<polyline points="${recentTrail}" fill="none" stroke="#f0abfc" stroke-width="5" stroke-linejoin="round" stroke-linecap="round" opacity=".95"></polyline>` : ''}
        ${eventMarkers}
        ${stationaryMessage}
        ${currentPoint ? `<g transform="translate(${currentPoint.x.toFixed(1)} ${currentPoint.y.toFixed(1)}) rotate(${aircraftAngle.toFixed(1)})"><title>Aircraft marker rotated by ${aircraftAngleSource}: ${aircraftAngle.toFixed(0)} deg</title><circle cx="0" cy="0" r="9" fill="rgba(15,23,42,.86)" stroke="#fff" stroke-width="2.5"></circle><path d="M 0 -18 L 8 10 L 0 5 L -8 10 Z" fill="#fff" stroke="#d946ef" stroke-width="1.8"></path></g>` : ''}
      </svg>`;
  }

  function downsamplePoints(points, maxPoints) {
    if (!Array.isArray(points) || points.length <= maxPoints) return points;
    const step = Math.ceil(points.length / maxPoints);
    const sampled = [];
    for (let i = 0; i < points.length; i += step) {
      sampled.push(points[i]);
    }
    const last = points[points.length - 1];
    if (sampled[sampled.length - 1] !== last) sampled.push(last);
    return sampled;
  }

  function render3DView() {
    const width = Math.max(320, Math.round(flight3D.clientWidth || 900));
    const height = Math.max(180, Math.round(flight3D.clientHeight || 220));
    const samples = payload.samples.filter((s) => s.lat !== null && s.lon !== null);
    if (!samples.length) {
      flight3D.innerHTML = '<div class="replay-3d-label">3D GPS altitude view</div><svg viewBox="0 0 900 220"><text x="24" y="62" fill="#64748b">No GPS data available</text></svg>';
      return;
    }

    const bounds = gpsBounds(samples);
    const altitudes = samples
      .map((s) => Number(s.gps_altitude_ft))
      .filter((v) => Number.isFinite(v));
    const minAlt = altitudes.length ? Math.min(...altitudes) : 0;
    const maxAlt = altitudes.length ? Math.max(...altitudes) : 1;
    const altSpan = Math.max(50, maxAlt - minAlt);
    const pad = 34;
    const groundY = height - 34;
    const horizonY = 48;
    const current = sampleAt(activeT);

    const project = (sample, elevated) => {
      const nx = (Number(sample.lon) - bounds.minLon) / Math.max(0.000001, bounds.maxLon - bounds.minLon);
      const ny = (Number(sample.lat) - bounds.minLat) / Math.max(0.000001, bounds.maxLat - bounds.minLat);
      const baseX = pad + nx * (width - pad * 2);
      const baseY = groundY - ny * (height * 0.32);
      const perspectiveX = baseX + (ny - 0.5) * 70;
      const alt = Number.isFinite(Number(sample.gps_altitude_ft)) ? Number(sample.gps_altitude_ft) : minAlt;
      const z = ((alt - minAlt) / altSpan) * (height * 0.58);
      return {
        x: perspectiveX,
        groundY: baseY,
        y: elevated ? Math.max(horizonY, baseY - z) : baseY,
        altitude: alt,
      };
    };

    const groundPath = samples.map((s) => {
      const p = project(s, false);
      return `${p.x.toFixed(1)},${p.y.toFixed(1)}`;
    }).join(' ');
    const altitudePath = samples.map((s) => {
      const p = project(s, true);
      return `${p.x.toFixed(1)},${p.y.toFixed(1)}`;
    }).join(' ');
    const currentPoint = current && current.lat !== null && current.lon !== null ? project(current, true) : null;
    const currentGround = current && current.lat !== null && current.lon !== null ? project(current, false) : null;
    const currentAlt = currentPoint ? `${currentPoint.altitude.toFixed(0)} ft` : '--';

    flight3D.innerHTML = `
      <div class="replay-3d-label">3D GPS altitude view · ${currentAlt}</div>
      <svg viewBox="0 0 ${width} ${height}" role="img" aria-label="3D GPS altitude view">
        <defs>
          <linearGradient id="altitudeFill" x1="0" x2="0" y1="0" y2="1">
            <stop stop-color="#2563eb" stop-opacity=".22"/>
            <stop offset="1" stop-color="#2563eb" stop-opacity=".02"/>
          </linearGradient>
        </defs>
        <line x1="${pad}" y1="${groundY}" x2="${width - pad}" y2="${groundY}" stroke="#94a3b8" stroke-width="1.5" stroke-dasharray="5 6"/>
        <text x="${pad}" y="${groundY - 8}" fill="#64748b" font-size="12">Ground track</text>
        <text x="${width - pad}" y="${horizonY + 12}" text-anchor="end" fill="#64748b" font-size="12">${maxAlt.toFixed(0)} ft</text>
        <text x="${width - pad}" y="${groundY - 8}" text-anchor="end" fill="#64748b" font-size="12">${minAlt.toFixed(0)} ft</text>
        <polyline points="${groundPath}" fill="none" stroke="#64748b" stroke-width="2" opacity=".55"/>
        <polyline points="${altitudePath}" fill="none" stroke="#2563eb" stroke-width="4" stroke-linejoin="round" stroke-linecap="round"/>
        ${currentPoint && currentGround ? `<line x1="${currentPoint.x.toFixed(1)}" y1="${currentPoint.y.toFixed(1)}" x2="${currentGround.x.toFixed(1)}" y2="${currentGround.y.toFixed(1)}" stroke="#f97316" stroke-width="3" stroke-linecap="round"/><circle cx="${currentGround.x.toFixed(1)}" cy="${currentGround.y.toFixed(1)}" r="5" fill="#f97316" opacity=".7"/><circle cx="${currentPoint.x.toFixed(1)}" cy="${currentPoint.y.toFixed(1)}" r="10" fill="#0f172a" stroke="#fff" stroke-width="4"/><circle cx="${currentPoint.x.toFixed(1)}" cy="${currentPoint.y.toFixed(1)}" r="5" fill="#2563eb"/><text x="${(currentPoint.x + 14).toFixed(1)}" y="${(currentPoint.y - 12).toFixed(1)}" fill="#0f172a" font-size="13" font-weight="800">${currentAlt}</text>` : ''}
      </svg>`;
  }

  function initCesium() {
    try {
      if (cesiumReady || !cesiumReplay || !payload) return;
      const token = (cesiumReplay.getAttribute('data-cesium-token') || '').trim().replace(/^['"]+|['"]+$/g, '');
      if (!token || typeof Cesium === 'undefined') return;
      const gpsSamples = payload.samples.filter((s) => s.lat !== null && s.lon !== null);
      if (!gpsSamples.length) {
        showCesiumError('No GPS samples available for Cesium replay.');
        return;
      }

      Cesium.Ion.defaultAccessToken = token;
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

      const firstPosition = Cesium.Cartesian3.fromDegrees(
        Number(gpsSamples[0].lon),
        Number(gpsSamples[0].lat),
        Math.max(0, feetToMeters(bestAltitudeFt(gpsSamples[0])))
      );
      cesiumAircraft = cesiumViewer.entities.add({
        name: 'Aircraft',
        position: firstPosition,
        point: {
          pixelSize: 1,
          color: Cesium.Color.WHITE.withAlpha(0.0),
        },
      });
      (payload.events || []).forEach((event) => {
        const s = sampleAt(event.start);
        if (!s || s.lat === null || s.lon === null) return;
        cesiumViewer.entities.add({
          name: event.event_type || 'Event',
          position: Cesium.Cartesian3.fromDegrees(Number(s.lon), Number(s.lat), Math.max(0, feetToMeters(bestAltitudeFt(s))) + 20),
          point: { pixelSize: 10, color: Cesium.Color.ORANGE, outlineColor: Cesium.Color.WHITE, outlineWidth: 2 },
        });
      });
      cesiumHud.hidden = false;
      cesiumReady = true;
      renderCesium();
    } catch (err) {
      showCesiumError(String(err.message || err));
    }
  }

  function showCesiumError(message) {
    if (!cesiumReplay) return;
    cesiumReplay.insertAdjacentHTML('beforeend', `<div class="cesium-unavailable"><div><strong>Cesium could not start.</strong><br>${String(message).replace(/[<>&]/g, (ch) => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[ch]))}</div></div>`);
  }

  function renderCesium() {
    if (!cesiumReady || !cesiumViewer || !cesiumAircraft) return;
    const s = smoothedSampleAt(activeT);
    if (!s || s.lat === null || s.lon === null) return;
    const altitudeM = Math.max(5, feetToMeters(bestAltitudeFt(s)));
    const position = Cesium.Cartesian3.fromDegrees(Number(s.lon), Number(s.lat), altitudeM + 8);
    cesiumAircraft.position = position;
    const groundspeed = Number.isFinite(Number(s.groundspeed_kt)) ? Number(s.groundspeed_kt) : 0;
    const track = Number.isFinite(Number(s.track_deg)) ? Number(s.track_deg) : null;
    const heading = Number.isFinite(Number(s.true_heading_deg)) ? Number(s.true_heading_deg)
      : (Number.isFinite(Number(s.heading_deg)) ? Number(s.heading_deg) : (track ?? 0));
    const pathHeading = groundspeed >= 5
      ? bearingBetween(smoothedSampleAt(activeT - 1.4), smoothedSampleAt(activeT + 1.4))
      : null;
    const cameraHeading = pathHeading !== null ? pathHeading : (track !== null && groundspeed >= 5 ? track : heading);
    const pitch = Number.isFinite(Number(s.pitch_deg)) ? Number(s.pitch_deg) : 0;
    const bank = Number.isFinite(Number(s.bank_deg)) ? Number(s.bank_deg) : 0;
    const targetState = {
      lat: Number(s.lat),
      lon: Number(s.lon),
      altitudeM: altitudeM + 8,
      heading: normalizeDeg(cameraHeading),
      pitch: Math.max(-18, Math.min(8, pitch - 2)),
      roll: Math.max(-28, Math.min(28, bank * 0.65)),
    };
    cesiumCameraState = Object.assign({}, targetState);
    const smoothedPosition = Cesium.Cartesian3.fromDegrees(cesiumCameraState.lon, cesiumCameraState.lat, cesiumCameraState.altitudeM);
    const headingRad = degToRad(cesiumCameraState.heading);
    const pitchRad = degToRad(cesiumCameraState.pitch);
    const rollRad = degToRad(cesiumCameraState.roll);
    const enu = Cesium.Transforms.eastNorthUpToFixedFrame(smoothedPosition);
    const forwardOffset = Cesium.Matrix4.multiplyByPointAsVector(
      enu,
      new Cesium.Cartesian3(Math.sin(headingRad) * 22, Math.cos(headingRad) * 22, 0),
      new Cesium.Cartesian3()
    );
    const eye = Cesium.Cartesian3.add(smoothedPosition, forwardOffset, new Cesium.Cartesian3());
    eye.z += 2.0;
    cesiumViewer.camera.setView({
      destination: eye,
      orientation: {
        heading: headingRad,
        pitch: pitchRad,
        roll: rollRad,
      },
    });
    renderCesiumHud(s, heading);
  }

  function safeRenderCesium() {
    try {
      renderCesium();
    } catch (err) {
      showCesiumError(String(err.message || err));
      cesiumReady = false;
    }
  }

  function safeRender(name, fn) {
    try {
      fn();
    } catch (err) {
      console.error(`Replay render failed: ${name}`, err);
      if (name === 'map') {
        flightMap.innerHTML = `<div class="replay-error">Track map render failed: ${String(err.message || err)}</div>`;
      }
    }
  }

  function renderCesiumHud(s, heading) {
    hudSpeed.textContent = Number.isFinite(Number(s.groundspeed_kt)) ? `${Number(s.groundspeed_kt).toFixed(0)} KT` : '-- KT';
    hudAltitude.textContent = `${bestAltitudeFt(s).toFixed(0)} FT`;
    hudVsi.textContent = Number.isFinite(Number(s.estimated_vertical_speed_fpm)) ? Number(s.estimated_vertical_speed_fpm).toFixed(0) : '--';
    hudAltimeter.textContent = Number.isFinite(Number(s.altimeter_setting_inhg)) ? `${Number(s.altimeter_setting_inhg).toFixed(2)} IN` : '-- IN';
    hudHeading.textContent = `HDG ${Number.isFinite(Number(heading)) ? String(Math.round(heading)).padStart(3, '0') : '---'}`;
    const slip = Math.max(-0.25, Math.min(0.25, Number(s.estimated_slip_skid_g) || 0));
    hudSlipBall.style.transform = `translateX(${(slip * 190).toFixed(1)}px)`;
  }

  function renderPhases() {
    phaseList.innerHTML = '';
    for (const phase of payload.phases) {
      const row = document.createElement('div');
      row.className = 'phase-row';
      row.dataset.start = String(phase.start);
      row.innerHTML = `
        <strong>${phase.phase}</strong>
        <span class="replay-muted">${fmtTime(phase.start)} · ${fmtTime(phase.duration)} · ${(Number(phase.confidence) * 100).toFixed(0)}%</span>
        <button type="button">Jump</button>`;
      row.querySelector('button').addEventListener('click', () => {
        mapMode = 'aircraft';
        zoomOffset = 0;
        seek(phase.start, true);
      });
      phaseList.appendChild(row);
    }
    updateActivePhase();
  }

  function renderDetails() {
    const s = sampleAt(activeT) || {};
    const phase = activePhase(activeT);
    const slipSkid = s.estimated_slip_skid_g === null || s.estimated_slip_skid_g === undefined
      ? 'Unavailable'
      : (Math.abs(Number(s.estimated_slip_skid_g)) < 0.02 ? 'Centered' : (Number(s.estimated_slip_skid_g) > 0 ? 'Right/skid' : 'Left/slip'));
    const adsbAltitudeDetail = s.adsb_baro_altitude_quality === 'good'
      ? `<div class="detail-row"><span>ADS-B baro altitude</span><strong>${number(s.adsb_baro_altitude_ft, ' ft', 0)}</strong></div>
         <div class="detail-row"><span>ADS-B VS</span><strong>${number(s.adsb_vertical_speed_fpm, ' fpm', 0)}</strong></div>`
      : '';
    const markerSource = s.heading_source === 'gps_track'
      ? 'GPS track'
      : (s.heading_source === 'calibrated_magnetic_heading' ? 'Calibrated magnetic heading' : 'Unavailable');
    const headingQuality = s.heading_quality || (markerSource === 'GPS track' ? 'GOOD' : (s.heading_deg !== null && s.heading_deg !== undefined ? 'LOW' : 'INVALID'));
    details.innerHTML = `
      <div class="detail-row"><span>Selected phase</span><strong>${phase ? phase.phase : '--'}</strong></div>
      <div class="detail-row"><span>Time</span><strong>${fmtTime(activeT)}</strong></div>
      <div class="detail-row"><span>ADS-B status</span><strong>${payload.recording.adsb_status || '--'}</strong></div>
      <div class="detail-row"><span>GPS altitude</span><strong>${number(s.gps_altitude_ft, ' ft', 0)}</strong></div>
      <div class="detail-row"><span>Estimated Indicated Alt.</span><strong>${number(s.estimated_indicated_altitude_ft, ' ft', 0)}</strong></div>
      <div class="detail-row"><span>Estimated True Alt.</span><strong>${number(s.estimated_true_altitude_from_indicated_ft, ' ft', 0)}</strong></div>
      <div class="detail-row"><span>Estimated VS</span><strong>${number(s.estimated_vertical_speed_fpm, ' fpm', 0)}</strong></div>
      <div class="detail-row"><span>Altimeter setting</span><strong>${number(s.altimeter_setting_inhg, ' inHg', 2)}</strong></div>
      <div class="detail-row"><span>OAT</span><strong>${number(s.oat_c, ' °C', 1)}</strong></div>
      <div class="detail-row"><span>Airport elevation</span><strong>${number(s.airport_elevation_ft, ' ft', 0)}</strong></div>
      <div class="detail-row"><span>Altitude quality</span><strong>${s.altitude_quality || 'unavailable'}</strong></div>
      <div class="detail-row"><span>VS quality</span><strong>${s.vertical_speed_quality || 'unavailable'}</strong></div>
      ${adsbAltitudeDetail}
      <div class="detail-row"><span>Groundspeed</span><strong>${number(s.groundspeed_kt, ' kt')}</strong></div>
      <div class="detail-row"><span>Pitch</span><strong>${number(s.pitch_deg, ' deg')}</strong></div>
      <div class="detail-row"><span>Bank</span><strong>${number(s.bank_deg, ' deg')}</strong></div>
      <div class="detail-row"><span>Estimated Slip/Skid</span><strong>${s.estimated_slip_skid_g === null || s.estimated_slip_skid_g === undefined ? '--' : `${slipSkid} · ${Number(s.estimated_slip_skid_g).toFixed(3)} g`}</strong></div>
      <div class="detail-row"><span>Slip/Skid quality</span><strong>${s.estimated_slip_skid_quality || 'unavailable'}</strong></div>
      <div class="detail-row"><span>Estimated Wind</span><strong>${s.estimated_wind_speed_kt === null || s.estimated_wind_speed_kt === undefined ? '--' : `${number(s.estimated_wind_direction_deg_true, '°', 0)} / ${number(s.estimated_wind_speed_kt, ' kt', 1)}`}</strong></div>
      <div class="detail-row"><span>Estimated TAS</span><strong>${number(s.estimated_tas_kt, ' kt', 1)}</strong></div>
      <div class="detail-row"><span>Wind quality</span><strong>${s.estimated_wind_quality || 'unavailable'}</strong></div>
      <div class="detail-row"><span>Heading</span><strong>${number(s.heading_deg, ' deg', 0)}</strong></div>
      <div class="detail-row"><span>True heading</span><strong>${number(s.true_heading_deg, ' deg', 0)}</strong></div>
      <div class="detail-row"><span>Track</span><strong>${number(s.track_deg, ' deg', 0)}</strong></div>
      <div class="detail-row"><span>Marker direction</span><strong>${markerSource}</strong></div>
      <div class="detail-row"><span>Heading quality</span><strong>${headingQuality}</strong></div>`;
    eventList.innerHTML = (payload.events || []).map((event) => `
      <div class="event-row"><strong>${event.event_type}</strong><br><span class="replay-muted">${fmtTime(event.start)} · ${event.phase || 'Timeline'} · ${(Number(event.confidence) * 100).toFixed(0)}%</span></div>
    `).join('') || '<div class="replay-muted">No timeline events detected yet.</div>';
  }

  function renderGraphs() {
    graphs.innerHTML = '';
    [
      ['GPS altitude', 'gps_altitude_ft', '#1d4ed8', 'ft'],
      ['Estimated Indicated Alt.', 'estimated_indicated_altitude_ft', '#7c3aed', 'ft'],
      ['Estimated True Alt.', 'estimated_true_altitude_from_indicated_ft', '#2563eb', 'ft'],
      ['Estimated VS', 'estimated_vertical_speed_fpm', '#0f766e', 'fpm'],
      ['Groundspeed', 'groundspeed_kt', '#16a34a', 'kt'],
      ['Pitch', 'pitch_deg', '#f97316', 'deg'],
      ['Bank', 'bank_deg', '#dc2626', 'deg'],
      ['Estimated Slip/Skid', 'estimated_slip_skid_g', '#be123c', 'g'],
      ['Estimated Wind', 'estimated_wind_speed_kt', '#0284c7', 'kt'],
      ['Estimated TAS', 'estimated_tas_kt', '#4f46e5', 'kt'],
      ['Heading', 'heading_deg', '#7c3aed', 'deg'],
      ['Track', 'track_deg', '#0891b2', 'deg'],
    ].forEach(([label, key, color, unit]) => {
      const card = document.createElement('div');
      card.className = 'graph-card';
      const current = sampleAt(activeT) || {};
      const zeroDecimalUnit = unit === 'ft' || unit === 'fpm' || (unit === 'deg' && (key === 'heading_deg' || key === 'track_deg'));
      const decimals = unit === 'g' ? 3 : (zeroDecimalUnit ? 0 : 1);
      const value = current[key] === null || current[key] === undefined || Number.isNaN(Number(current[key]))
        ? '--'
        : `${Number(current[key]).toFixed(decimals)} ${unit}`;
      card.innerHTML = `<h4>${label}</h4><div class="graph-value">${value}</div>${graphSvg(key, color, unit)}`;
      graphs.appendChild(card);
    });
  }

  function graphSvg(key, color, unit) {
    const width = 420, height = 90, pad = 10;
    const values = payload.samples.filter((s) => s[key] !== null && s[key] !== undefined).map((s) => ({ t: Number(s.t), v: Number(s[key]) }));
    if (!values.length) return '<div class="replay-muted">No data</div>';
    const maxT = Math.max(1, Number(payload.recording.duration) || values[values.length - 1].t || 1);
    const minV = Math.min(...values.map((p) => p.v));
    const maxV = Math.max(...values.map((p) => p.v));
    const points = values.map((p) => {
      const x = pad + (p.t / maxT) * (width - pad * 2);
      const y = height - pad - ((p.v - minV) / Math.max(0.000001, maxV - minV)) * (height - pad * 2);
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    }).join(' ');
    const currentX = pad + (activeT / maxT) * (width - pad * 2);
    return `<div class="graph-range">Range ${minV.toFixed(0)}-${maxV.toFixed(0)} ${unit}</div><svg viewBox="0 0 ${width} ${height}"><polyline points="${points}" fill="none" stroke="${color}" stroke-width="3"/><line x1="${currentX}" x2="${currentX}" y1="6" y2="${height - 6}" stroke="#0f172a" stroke-width="1.5"/></svg>`;
  }

  function updateActivePhase() {
    const phase = activePhase(activeT);
    document.querySelectorAll('.phase-row').forEach((row) => {
      const start = Number(row.dataset.start || 0);
      row.classList.toggle('is-active', !!phase && Math.abs(start - phase.start) < 0.001);
    });
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
    updateActivePhase();
    safeRender('map', renderMap);
    safeRenderCesium();
    safeRender('3d', render3DView);
    safeRender('details', renderDetails);
    safeRender('graphs', renderGraphs);
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

  function updateCockpitPlayback(seconds, timestamp) {
    const previousT = activeT;
    activeT = Math.max(0, Number(seconds) || 0);
    if (Math.abs(activeT - previousT) > 3) {
      cesiumCameraState = null;
    }
    timeline.value = String(activeT);
    timeLabel.textContent = fmtTime(activeT);
    safeRenderCesium();

    if (timestamp - lastAnimationRenderMs >= 160) {
      safeRender('map', renderMap);
      lastAnimationRenderMs = timestamp;
    }
    if (timestamp - lastPanelRenderMs >= 500) {
      updateActivePhase();
      safeRender('details', renderDetails);
      lastPanelRenderMs = timestamp;
    }
  }

  timeline.addEventListener('input', () => seek(Number(timeline.value), true));
  audio.addEventListener('timeupdate', () => {
    if (audio.paused) {
      seek(audio.currentTime, false);
    }
  });
  function adjustMapZoom(delta) {
    zoomOffset += delta;
    if (zoomOffset < -6) zoomOffset = -6;
    if (zoomOffset > 6) zoomOffset = 6;
    safeRender('map', renderMap);
  }
  zoomOutButton.addEventListener('click', () => adjustMapZoom(-1));
  zoomInButton.addEventListener('click', () => adjustMapZoom(1));
  flightMap.addEventListener('wheel', (event) => {
    event.preventDefault();
    adjustMapZoom(event.deltaY < 0 ? 1 : -1);
  }, { passive: false });
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
    lastAnimationRenderMs = 0;
    lastPanelRenderMs = 0;
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
    updateCockpitPlayback(playbackClockTime(timestamp), timestamp);
    animationFrame = requestAnimationFrame(animatePlayback);
  }
  window.addEventListener('resize', () => {
    if (!payload) return;
    safeRender('map', renderMap);
    safeRenderCesium();
    safeRender('3d', render3DView);
    safeRender('graphs', renderGraphs);
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
      flightMap.innerHTML = `<div class="replay-error">Could not load replay data: ${String(err.message || err)}</div>`;
      phaseList.innerHTML = '<div class="replay-muted">Reconstruct the recording first.</div>';
      details.innerHTML = '<div class="replay-muted">No replay data loaded.</div>';
      return;
    }

    payload = data;
    const maxT = Math.max(Number(payload.recording.duration) || 0, payload.samples.reduce((max, s) => Math.max(max, Number(s.t) || 0), 1), 1);
    timeline.max = String(maxT);
    safeRender('phases', renderPhases);
    try {
      initCesium();
    } catch (err) {
      showCesiumError(String(err.message || err));
    }
    safeRender('map', renderMap);
    safeRenderCesium();
    safeRender('3d', render3DView);
    safeRender('details', renderDetails);
    safeRender('graphs', renderGraphs);
  }

  loadReplay();
})();
</script>
<?php endif; ?>

<?php
cw_footer();
