<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/CockpitRecorderService.php';

cw_require_admin();

$id = trim((string)($_GET['id'] ?? ''));
$error = '';
$recording = null;

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
.replay-map { height: 360px; border-radius: 14px; border: 1px solid #dbeafe; background: linear-gradient(135deg, #eef6ff, #ffffff); overflow: hidden; position: relative; }
.replay-map svg { width: 100%; height: 100%; display: block; }
.replay-map-layer { position: absolute; inset: 0; overflow: hidden; background: #0f172a; }
.replay-map-layer img { position: absolute; width: 256px; height: 256px; max-width: none; user-select: none; pointer-events: none; }
.replay-map-overlay { position: absolute; inset: 0; z-index: 2; }
.replay-map-label { position: absolute; left: 12px; top: 12px; z-index: 3; border-radius: 999px; background: rgba(15, 23, 42, .75); color: #fff; font-size: 12px; font-weight: 700; padding: 5px 9px; backdrop-filter: blur(6px); }
.replay-map-attribution { position: absolute; right: 8px; bottom: 6px; z-index: 3; border-radius: 6px; background: rgba(255, 255, 255, .82); color: #334155; font-size: 11px; padding: 3px 6px; }
.replay-map-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.replay-map-toolbar-group { display: inline-flex; gap: 6px; align-items: center; }
.replay-map-toolbar button { border: 1px solid #bfdbfe; border-radius: 999px; background: #eff6ff; color: #1d4ed8; font-weight: 800; padding: 5px 10px; cursor: pointer; }
.replay-map-toolbar button.is-active { background: #1d4ed8; color: #fff; border-color: #1d4ed8; }
.replay-map-toolbar .zoom-button { min-width: 34px; padding-left: 0; padding-right: 0; }
.replay-controls { display: grid; grid-template-columns: auto 1fr auto; gap: 10px; align-items: center; margin-top: 12px; }
.replay-range { width: 100%; accent-color: #1d4ed8; }
.replay-graphs { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.graph-card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px; background: #f8fafc; }
.graph-card h4 { margin: 0 0 6px; font-size: 12px; color: #334155; text-transform: uppercase; letter-spacing: .04em; }
.graph-card svg { width: 100%; height: 78px; display: block; }
.detail-grid { display: grid; gap: 8px; font-size: 13px; }
.detail-row { display: flex; justify-content: space-between; gap: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; }
.event-list { display: grid; gap: 6px; margin-top: 10px; }
.event-row { border-left: 3px solid #1d4ed8; padding: 6px 8px; background: #f8fafc; border-radius: 8px; font-size: 12px; }
.replay-audio { width: 100%; margin-top: 10px; }
.replay-topbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; }
@media (max-width: 1180px) { .replay-layout { grid-template-columns: 1fr; } .replay-left, .replay-center, .replay-right { min-height: auto; } .replay-graphs { grid-template-columns: 1fr; } }
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
        <div class="replay-map-toolbar">
          <div class="replay-map-toolbar-group" role="group" aria-label="Map view mode">
            <button type="button" id="routeViewButton" class="is-active">Route</button>
            <button type="button" id="aircraftViewButton">Aircraft</button>
            <button type="button" id="followViewButton">Follow aircraft</button>
          </div>
          <div class="replay-map-toolbar-group" role="group" aria-label="Map zoom">
            <button type="button" class="zoom-button" id="zoomOutButton">-</button>
            <button type="button" class="zoom-button" id="zoomInButton">+</button>
          </div>
        </div>
        <div class="replay-map" id="flightMap"></div>
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
<script>
(function() {
  const root = document.querySelector('[data-replay-id]');
  const id = root ? root.getAttribute('data-replay-id') : '';
  const phaseList = document.getElementById('phaseList');
  const flightMap = document.getElementById('flightMap');
  const eventList = document.getElementById('eventList');
  const details = document.getElementById('details');
  const graphs = document.getElementById('graphs');
  const timeline = document.getElementById('timeline');
  const timeLabel = document.getElementById('timeLabel');
  const audio = document.getElementById('audio');
  const playButton = document.getElementById('playButton');
  const routeViewButton = document.getElementById('routeViewButton');
  const aircraftViewButton = document.getElementById('aircraftViewButton');
  const followViewButton = document.getElementById('followViewButton');
  const zoomOutButton = document.getElementById('zoomOutButton');
  const zoomInButton = document.getElementById('zoomInButton');
  let payload = null;
  let activeT = 0;
  let mapMode = 'route';
  let zoomOffset = 0;

  const fmtTime = (seconds) => {
    seconds = Math.max(0, Math.round(Number(seconds) || 0));
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`;
  };

  const number = (value, suffix, digits = 1) => value === null || value === undefined || Number.isNaN(Number(value)) ? '--' : `${Number(value).toFixed(digits)}${suffix}`;

  function sampleAt(t) {
    if (!payload || !payload.samples.length) return null;
    let best = payload.samples[0];
    for (const sample of payload.samples) {
      if (sample.t > t) break;
      best = sample;
    }
    return best;
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
    const centerX = lonToWorldX(view.centerLon, view.zoom);
    const centerY = latToWorldY(view.centerLat, view.zoom);
    return Object.assign({}, sample, {
      x: lonToWorldX(sample.lon, view.zoom) - centerX + width / 2,
      y: latToWorldY(sample.lat, view.zoom) - centerY + height / 2,
    });
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
    const width = Math.max(320, Math.round(flightMap.clientWidth || 900));
    const height = Math.max(240, Math.round(flightMap.clientHeight || 420));
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
    const path = stationary ? '' : points.map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ');
    const recentTrail = points
      .filter((p) => p.t >= activeT - 60 && p.t <= activeT + 3)
      .map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`)
      .join(' ');
    const eventMarkers = (payload.events || []).map((event) => {
      const projected = pointForTime(event.start);
      if (!projected) return '';
      return `<circle cx="${projected.x}" cy="${projected.y}" r="5" fill="#f97316"><title>${event.event_type} ${fmtTime(event.start)}</title></circle>`;
    }).join('');
    const pathLayer = path
      ? `<polyline points="${path}" fill="none" stroke="#1d4ed8" stroke-width="4" stroke-linejoin="round" stroke-linecap="round"></polyline>`
      : (!points.length ? '<text x="24" y="40" fill="#64748b">No GPS path available</text>' : '');
    const stationaryMessage = stationary
      ? `<text x="${width / 2}" y="${height / 2 + 34}" text-anchor="middle" fill="#475569" font-size="18">GPS position recorded, no significant movement detected yet.</text>
         <text x="${width / 2}" y="${height / 2 + 58}" text-anchor="middle" fill="#64748b" font-size="14">A real flight or taxi test will draw the plan-view track here.</text>`
      : '';
    const trackAngle = current && Number.isFinite(Number(current.track_deg)) ? Number(current.track_deg) : null;
    const headingAngle = current && Number.isFinite(Number(current.heading_deg)) ? Number(current.heading_deg) : null;
    const groundspeed = current && Number.isFinite(Number(current.groundspeed_kt)) ? Number(current.groundspeed_kt) : 0;
    const aircraftAngle = trackAngle !== null && groundspeed >= 5 ? trackAngle : (headingAngle ?? trackAngle ?? 0);
    const aircraftAngleSource = trackAngle !== null && groundspeed >= 5 ? 'GPS track' : (headingAngle !== null ? 'heading' : 'GPS track');
    flightMap.innerHTML = `
      ${renderSatelliteTiles(view, width, height)}
      <div class="replay-map-label">${view ? `${mapMode === 'route' ? 'Route' : (mapMode === 'follow' ? 'Follow aircraft' : 'Aircraft')} · Satellite Z${view.zoom}` : 'Replay plan view'}</div>
      ${view ? '<div class="replay-map-attribution">Imagery: Esri World Imagery</div>' : ''}
      <svg class="replay-map-overlay" viewBox="0 0 ${width} ${height}" role="img" aria-label="Flight path">
        <rect width="${width}" height="${height}" fill="${view ? 'rgba(15, 23, 42, .08)' : 'url(#bg)'}"></rect>
        <defs><linearGradient id="bg" x1="0" x2="1" y1="0" y2="1"><stop stop-color="#eff6ff"/><stop offset="1" stop-color="#ffffff"/></linearGradient></defs>
        ${pathLayer}
        ${recentTrail ? `<polyline points="${recentTrail}" fill="none" stroke="#f97316" stroke-width="6" stroke-linejoin="round" stroke-linecap="round" opacity=".9"></polyline>` : ''}
        ${eventMarkers}
        ${stationaryMessage}
        ${currentPoint ? `<g transform="translate(${currentPoint.x.toFixed(1)} ${currentPoint.y.toFixed(1)}) rotate(${aircraftAngle.toFixed(1)})"><title>Aircraft marker rotated by ${aircraftAngleSource}: ${aircraftAngle.toFixed(0)} deg</title><circle cx="0" cy="0" r="13" fill="#0f172a" stroke="#fff" stroke-width="4"></circle><path d="M 0 -24 L 10 14 L 0 8 L -10 14 Z" fill="#2563eb" stroke="#fff" stroke-width="2"></path><line x1="0" y1="-34" x2="0" y2="-46" stroke="#fff" stroke-width="3" stroke-linecap="round"></line></g>` : ''}
      </svg>`;
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
        updateMapButtons();
        seek(phase.start, true);
      });
      phaseList.appendChild(row);
    }
    updateActivePhase();
  }

  function renderDetails() {
    const s = sampleAt(activeT) || {};
    const phase = activePhase(activeT);
    const markerSource = Number(s.groundspeed_kt || 0) >= 5 && s.track_deg !== null && s.track_deg !== undefined ? 'GPS track' : 'heading';
    details.innerHTML = `
      <div class="detail-row"><span>Selected phase</span><strong>${phase ? phase.phase : '--'}</strong></div>
      <div class="detail-row"><span>Time</span><strong>${fmtTime(activeT)}</strong></div>
      <div class="detail-row"><span>GPS altitude</span><strong>${number(s.gps_altitude_ft, ' ft', 0)}</strong></div>
      <div class="detail-row"><span>Groundspeed</span><strong>${number(s.groundspeed_kt, ' kt')}</strong></div>
      <div class="detail-row"><span>Pitch</span><strong>${number(s.pitch_deg, ' deg')}</strong></div>
      <div class="detail-row"><span>Bank</span><strong>${number(s.bank_deg, ' deg')}</strong></div>
      <div class="detail-row"><span>Heading</span><strong>${number(s.heading_deg, ' deg', 0)}</strong></div>
      <div class="detail-row"><span>Track</span><strong>${number(s.track_deg, ' deg', 0)}</strong></div>
      <div class="detail-row"><span>Marker direction</span><strong>${markerSource}</strong></div>`;
    eventList.innerHTML = (payload.events || []).map((event) => `
      <div class="event-row"><strong>${event.event_type}</strong><br><span class="replay-muted">${fmtTime(event.start)} · ${event.phase || 'Timeline'} · ${(Number(event.confidence) * 100).toFixed(0)}%</span></div>
    `).join('') || '<div class="replay-muted">No timeline events detected yet.</div>';
  }

  function renderGraphs() {
    graphs.innerHTML = '';
    [
      ['GPS altitude', 'gps_altitude_ft', '#1d4ed8', 'ft'],
      ['Groundspeed', 'groundspeed_kt', '#16a34a', 'kt'],
      ['Pitch', 'pitch_deg', '#f97316', 'deg'],
      ['Bank', 'bank_deg', '#dc2626', 'deg'],
      ['Heading', 'heading_deg', '#7c3aed', 'deg'],
      ['Track', 'track_deg', '#0891b2', 'deg'],
    ].forEach(([label, key, color, unit]) => {
      const card = document.createElement('div');
      card.className = 'graph-card';
      card.innerHTML = `<h4>${label}</h4>${graphSvg(key, color, unit)}`;
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
    return `<svg viewBox="0 0 ${width} ${height}"><polyline points="${points}" fill="none" stroke="${color}" stroke-width="3"/><line x1="${currentX}" x2="${currentX}" y1="6" y2="${height - 6}" stroke="#0f172a" stroke-width="1.5"/><text x="12" y="18" fill="#64748b" font-size="12">${minV.toFixed(0)}-${maxV.toFixed(0)} ${unit}</text></svg>`;
  }

  function updateActivePhase() {
    const phase = activePhase(activeT);
    document.querySelectorAll('.phase-row').forEach((row) => {
      const start = Number(row.dataset.start || 0);
      row.classList.toggle('is-active', !!phase && Math.abs(start - phase.start) < 0.001);
    });
  }

  function updateMapButtons() {
    routeViewButton.classList.toggle('is-active', mapMode === 'route');
    aircraftViewButton.classList.toggle('is-active', mapMode === 'aircraft');
    followViewButton.classList.toggle('is-active', mapMode === 'follow');
  }

  function setMapMode(mode) {
    mapMode = mode;
    if (mode === 'route') {
      zoomOffset = 0;
    }
    updateMapButtons();
    renderMap();
  }

  function seek(seconds, syncAudio) {
    activeT = Math.max(0, Number(seconds) || 0);
    timeline.value = String(activeT);
    timeLabel.textContent = fmtTime(activeT);
    if (syncAudio && Number.isFinite(audio.duration)) {
      audio.currentTime = Math.min(activeT, audio.duration || activeT);
    }
    updateActivePhase();
    renderMap();
    renderDetails();
    renderGraphs();
  }

  timeline.addEventListener('input', () => seek(Number(timeline.value), true));
  audio.addEventListener('timeupdate', () => seek(audio.currentTime, false));
  routeViewButton.addEventListener('click', () => setMapMode('route'));
  aircraftViewButton.addEventListener('click', () => setMapMode('aircraft'));
  followViewButton.addEventListener('click', () => setMapMode('follow'));
  zoomOutButton.addEventListener('click', () => {
    zoomOffset -= 1;
    if (mapMode === 'route' && zoomOffset < -3) zoomOffset = -3;
    if (mapMode !== 'route' && zoomOffset < -6) zoomOffset = -6;
    renderMap();
  });
  zoomInButton.addEventListener('click', () => {
    zoomOffset += 1;
    if (zoomOffset > 6) zoomOffset = 6;
    renderMap();
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
  audio.addEventListener('pause', () => playButton.textContent = 'Play');
  audio.addEventListener('play', () => playButton.textContent = 'Pause');
  window.addEventListener('resize', () => {
    if (!payload) return;
    renderMap();
    renderGraphs();
  });

  fetch(`/api/recordings/replay.php?id=${encodeURIComponent(id)}`)
    .then((response) => response.json())
    .then((data) => {
      if (!data.ok) throw new Error(data.error || 'Replay data not available.');
      payload = data;
      const maxT = Math.max(Number(payload.recording.duration) || 0, ...payload.samples.map((s) => Number(s.t) || 0), 1);
      timeline.max = String(maxT);
      renderPhases();
      renderMap();
      renderDetails();
      renderGraphs();
    })
    .catch((err) => {
      flightMap.innerHTML = `<div class="replay-error">${err.message}</div>`;
      phaseList.innerHTML = '<div class="replay-muted">Reconstruct the recording first.</div>';
      details.innerHTML = '<div class="replay-muted">No replay data loaded.</div>';
    });
})();
</script>
<?php endif; ?>

<?php
cw_footer();
