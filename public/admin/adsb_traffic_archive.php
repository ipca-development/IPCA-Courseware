<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/AdsbTrafficArchiveService.php';

cw_require_admin();

$error = trim((string)($_GET['error'] ?? ''));
$providerConfigNotice = str_contains($error, 'ADS-B historical provider is not configured');
if ($providerConfigNotice) {
    $error = '';
}
$notice = '';
if (isset($_GET['scheduled'])) {
    $notice = 'KTRM ADS-B archive tiles scheduled: ' . (int)$_GET['scheduled'] . '.';
}
if (isset($_GET['processed'])) {
    $notice = 'ADS-B archive tile processed: ' . h((string)$_GET['processed']) . ', samples ' . (int)($_GET['samples'] ?? 0) . '.';
}
if (isset($_GET['batch_processed'])) {
    $notice = 'ADS-B archive batch processed: ' . (int)$_GET['batch_processed'] . ' tile(s), samples ' . (int)($_GET['samples'] ?? 0) . '.';
}
if (isset($_GET['corridor_requested'])) {
    $notice = 'Flight corridor ADS-B coverage requested for recording ' . (int)$_GET['corridor_requested'] . '. Process pending tiles to fetch available traffic.';
}

$status = array();
$recentTraffic = array();
$dashboard = array();
try {
    $archiveService = new AdsbTrafficArchiveService($pdo);
    $status = $archiveService->status();
    $recentTraffic = $archiveService->recentTrafficSamples(250);
    $dashboard = $archiveService->dashboardData('ktrm_live', 1);
} catch (Throwable $e) {
    $error = $error !== '' ? $error : $e->getMessage();
}

$dashboardJson = json_encode($dashboard, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}';
cw_header('ADS-B Traffic Archive');
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
.adsb-page{display:grid;gap:16px}.adsb-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:16px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.adsb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.adsb-kv{border:1px solid #e2e8f0;border-radius:12px;padding:10px;background:#f8fafc}.adsb-label{color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.04em}.adsb-value{font-weight:900;margin-top:4px}.adsb-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:end}.adsb-actions input,.adsb-actions select,.adsb-control{border:1px solid #cbd5e1;border-radius:8px;padding:7px 8px;background:#fff}.adsb-actions button,.adsb-button{border:0;border-radius:9px;background:#1d4ed8;color:#fff;font-weight:800;padding:8px 11px;cursor:pointer;text-decoration:none}.adsb-actions button.secondary,.adsb-button.secondary{background:#475569}.adsb-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px}.adsb-notice{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:12px}.adsb-warning{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:10px;padding:12px}.adsb-muted{color:#64748b;font-size:13px}.adsb-code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px}.adsb-table-wrap{overflow-x:auto}.adsb-table{width:100%;border-collapse:collapse;min-width:760px}.adsb-table th,.adsb-table td{border-bottom:1px solid #e2e8f0;padding:9px 8px;text-align:left}.adsb-table th{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.04em}.adsb-pre{white-space:pre-wrap;background:#0f172a;color:#dbeafe;border-radius:10px;padding:12px;overflow:auto}.adsb-map-layout{display:grid;grid-template-columns:minmax(320px,1.7fr) minmax(260px,.8fr);gap:14px}.adsb-map{height:520px;border:1px solid #cbd5e1;border-radius:14px;overflow:hidden;background:#e2e8f0}.adsb-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin-bottom:12px}.adsb-timeline{width:100%;accent-color:#2563eb}.adsb-growth{height:84px;display:flex;align-items:end;gap:2px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;padding:8px}.adsb-growth-bar{flex:1;min-width:2px;background:#2563eb;border-radius:3px 3px 0 0}.adsb-target-list{display:grid;gap:8px;max-height:180px;overflow:auto}.adsb-aircraft-list{display:grid;gap:6px;max-height:240px;overflow:auto}.adsb-pill{border:1px solid #e2e8f0;border-radius:999px;padding:5px 8px;background:#f8fafc;font-size:12px}.adsb-live-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#16a34a;margin-right:5px}@media(max-width:960px){.adsb-map-layout{grid-template-columns:1fr}.adsb-map{height:420px}}
</style>
<div class="adsb-page">
  <section class="adsb-card">
    <h2 style="margin-top:0">ADS-B Traffic Archive</h2>
    <p class="adsb-muted">Continuous ADSBExchange traffic coverage for Thermal/KTRM within 15 NM. Replays and safety analysis use this archive before falling back to per-recording ADS-B enrichment.</p>
    <?php if ($error !== ''): ?><div class="adsb-error"><?= h($error) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="adsb-notice"><?= h($notice) ?></div><?php endif; ?>
    <?php if (empty($status['historical_provider']['configured'])): ?>
      <div class="adsb-warning">
        <strong>Historical ADS-B backfill provider is not configured.</strong>
        Live KTRM archiving uses the existing TV/radar ADSBExchange key
        (<span class="adsb-code">CW_ADSBEXCHANGE_API_KEY</span>, <span class="adsb-code">CW_RAPIDAPI_KEY</span>, <span class="adsb-code">RAPIDAPI_KEY</span>, or <span class="adsb-code">ADSBEXCHANGE_API_KEY</span>).
        Older historical backfill requires <span class="adsb-code">CW_ADSB_EXCHANGE_BASE_URL</span> plus <span class="adsb-code">CW_ADSB_EXCHANGE_API_KEY</span>.
        Old archive buckets are not filled from live snapshots because that would create misleading historical traffic data.
      </div>
    <?php endif; ?>
  </section>

  <section class="adsb-card">
    <h3 style="margin-top:0">KTRM Coverage</h3>
    <div class="adsb-grid">
      <div class="adsb-kv"><div class="adsb-label">Provider</div><div class="adsb-value"><?= h((string)($status['provider'] ?? 'unknown')) ?></div></div>
      <div class="adsb-kv"><div class="adsb-label">Radius</div><div class="adsb-value"><?= h((string)($status['ktrm']['radius_nm'] ?? '15')) ?> NM</div></div>
      <div class="adsb-kv"><div class="adsb-label">Ready Tiles</div><div class="adsb-value"><?= number_format((int)($status['coverage']['ready'] ?? 0)) ?></div></div>
      <div class="adsb-kv"><div class="adsb-label">Pending Tiles</div><div class="adsb-value"><?= number_format((int)($status['coverage']['pending'] ?? 0)) ?></div></div>
      <div class="adsb-kv"><div class="adsb-label">Failed Tiles</div><div class="adsb-value"><?= number_format((int)($status['coverage']['failed'] ?? 0)) ?></div></div>
      <div class="adsb-kv"><div class="adsb-label">Provider Not Configured</div><div class="adsb-value"><?= number_format((int)($status['coverage']['provider_not_configured'] ?? 0)) ?></div></div>
      <div class="adsb-kv"><div class="adsb-label">Samples</div><div class="adsb-value"><?= number_format((int)($status['coverage']['samples'] ?? 0)) ?></div></div>
      <div class="adsb-kv"><div class="adsb-label">Latest Ready</div><div class="adsb-value"><?= h((string)($status['ktrm']['latest_ready_bucket_end_utc'] ?? 'none')) ?></div></div>
    </div>
  </section>

  <section class="adsb-card" id="adsbDashboard">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap">
      <div>
        <h3 style="margin:0">Realtime Archive Growth</h3>
        <div class="adsb-muted"><span class="adsb-live-dot"></span>Polls the local ADS-B archive every few seconds. No provider calls are made by this dashboard.</div>
      </div>
      <div class="adsb-muted">Last refresh: <span id="adsbRefreshTime">--</span></div>
    </div>
    <div class="adsb-grid" style="margin-top:12px">
      <div class="adsb-kv"><div class="adsb-label">Total Samples</div><div class="adsb-value" id="adsbTotalSamples">--</div></div>
      <div class="adsb-kv"><div class="adsb-label">Unique Aircraft</div><div class="adsb-value" id="adsbUniqueAircraft">--</div></div>
      <div class="adsb-kv"><div class="adsb-label">Recent Samples</div><div class="adsb-value" id="adsbRecentSamples">--</div></div>
      <div class="adsb-kv"><div class="adsb-label">Recent Aircraft</div><div class="adsb-value" id="adsbRecentAircraft">--</div></div>
      <div class="adsb-kv"><div class="adsb-label">Raw Payloads</div><div class="adsb-value" id="adsbRawPayloads">--</div></div>
      <div class="adsb-kv"><div class="adsb-label">Newest Sample</div><div class="adsb-value adsb-code" id="adsbNewestSample">--</div></div>
      <div class="adsb-kv"><div class="adsb-label">Recording Status</div><div class="adsb-value" id="adsbRecordingStatus">--</div><div class="adsb-muted" id="adsbRecordingAge">--</div></div>
    </div>
    <div style="margin-top:12px">
      <div class="adsb-label">Samples per minute</div>
      <div class="adsb-growth" id="adsbGrowthChart" aria-label="Archive growth chart"></div>
    </div>
  </section>

  <section class="adsb-card">
    <h3 style="margin-top:0">Target Map And Historical Aircraft Scrubber</h3>
    <div class="adsb-toolbar">
      <label class="adsb-muted">Target<br><select class="adsb-control" id="adsbTargetSelect"></select></label>
      <label class="adsb-muted">Lookback hours<br><input class="adsb-control" id="adsbHoursInput" type="number" min="1" max="168" value="1"></label>
      <button class="adsb-button" type="button" id="adsbReloadButton">Reload Target</button>
      <button class="adsb-button secondary" type="button" id="adsbNewestButton">Jump To Newest</button>
      <span class="adsb-muted" id="adsbTargetSummary">--</span>
    </div>
    <div class="adsb-map-layout">
      <div>
        <div id="adsbTargetMap" class="adsb-map"></div>
        <div class="adsb-muted" id="adsbMapStatus" style="margin-top:6px">Loading archived traffic map...</div>
        <div style="margin-top:12px">
          <input id="adsbTimeline" class="adsb-timeline" type="range" min="0" max="1" step="1" value="0" aria-label="ADS-B archive time scrubber">
          <div style="display:flex;justify-content:space-between;gap:10px" class="adsb-muted">
            <span id="adsbTimelineStart">--</span>
            <strong id="adsbTimelineCurrent">--</strong>
            <span id="adsbTimelineEnd">--</span>
          </div>
        </div>
      </div>
      <div>
        <div class="adsb-grid">
          <div class="adsb-kv"><div class="adsb-label">Aircraft</div><div class="adsb-value" id="adsbTargetAircraft">--</div></div>
          <div class="adsb-kv"><div class="adsb-label">Samples</div><div class="adsb-value" id="adsbTargetSamples">--</div></div>
        </div>
        <h4>Defined Targets</h4>
        <div class="adsb-target-list" id="adsbTargetList"></div>
        <h4>Aircraft At Selected Time</h4>
        <div class="adsb-aircraft-list" id="adsbAircraftList"></div>
      </div>
    </div>
  </section>

  <section class="adsb-card">
    <h3 style="margin-top:0">Actions</h3>
    <form class="adsb-actions" method="post" action="/admin/api/adsb_archive_action.php">
      <input type="hidden" name="return" value="/admin/adsb_traffic_archive.php">
      <label class="adsb-muted">Lookback minutes<br><input type="number" name="lookback_minutes" min="5" max="1440" value="<?= empty($status['historical_provider']['configured']) ? '5' : '180' ?>"></label>
      <button type="submit" name="action" value="schedule_recent_ktrm">Schedule Recent KTRM Coverage</button>
    </form>
    <form class="adsb-actions" method="post" action="/admin/api/adsb_archive_action.php" style="margin-top:10px">
      <input type="hidden" name="return" value="/admin/adsb_traffic_archive.php">
      <button type="submit" name="action" value="process_tile">Process Next Tile</button>
      <label class="adsb-muted">Batch size<br><input type="number" name="limit" min="1" max="25" value="5"></label>
      <button class="secondary" type="submit" name="action" value="process_batch">Process Batch</button>
    </form>
    <form class="adsb-actions" method="post" action="/admin/api/adsb_archive_action.php" style="margin-top:10px">
      <input type="hidden" name="return" value="/admin/adsb_traffic_archive.php">
      <label class="adsb-muted">Recording ID<br><input type="number" name="recording_id" min="1" placeholder="Cockpit recording id"></label>
      <button class="secondary" type="submit" name="action" value="schedule_recording_corridor">Request Flight Corridor Coverage</button>
    </form>
  </section>

  <section class="adsb-card">
    <h3 style="margin-top:0">Automatic Cron</h3>
    <p class="adsb-muted">Run this every 5 minutes to continuously schedule and process live KTRM ADS-B archive buckets. Configure <span class="adsb-code">CW_ADSB_ARCHIVE_CRON_TOKEN</span> in PHP-FPM/server env first.</p>
    <div class="adsb-pre">*/5 * * * * curl -fsS "https://ipca.training/cron/adsb_archive.php?token=$CW_ADSB_ARCHIVE_CRON_TOKEN&amp;limit=5&amp;lookback_minutes=5" >/dev/null</div>
  </section>

  <section class="adsb-card">
    <h3 style="margin-top:0">Recorded KTRM Traffic</h3>
    <p class="adsb-muted">Recent normalized ADS-B samples within 15 NM of KTRM, recorded in the archive during the last 24 hours.</p>
    <?php if ($recentTraffic === array()): ?>
      <div class="adsb-warning">No archived traffic samples recorded yet. Run the cron or process a live tile.</div>
    <?php else: ?>
      <div class="adsb-scope-grid">
        <svg class="adsb-scope" viewBox="0 0 420 420" role="img" aria-label="KTRM ADS-B traffic scope">
          <circle cx="210" cy="210" r="190" fill="none" stroke="rgba(148,163,184,.45)" stroke-width="1"></circle>
          <circle cx="210" cy="210" r="126.7" fill="none" stroke="rgba(148,163,184,.22)" stroke-width="1"></circle>
          <circle cx="210" cy="210" r="63.3" fill="none" stroke="rgba(148,163,184,.18)" stroke-width="1"></circle>
          <line x1="210" y1="20" x2="210" y2="400" stroke="rgba(148,163,184,.22)" stroke-width="1"></line>
          <line x1="20" y1="210" x2="400" y2="210" stroke="rgba(148,163,184,.22)" stroke-width="1"></line>
          <circle cx="210" cy="210" r="5" fill="#38bdf8"></circle>
          <text x="218" y="206" fill="#e0f2fe" font-size="12" font-family="monospace">KTRM</text>
          <?php foreach (array_slice($recentTraffic, 0, 120) as $target): ?>
            <?php
              $lat = (float)($target['latitude'] ?? 0);
              $lon = (float)($target['longitude'] ?? 0);
              $x = 210 + (($lon - (-116.160156)) * 60.0 * cos(deg2rad(33.626701)) / 15.0 * 190.0);
              $y = 210 - (($lat - 33.626701) * 60.0 / 15.0 * 190.0);
              if ($x < 20 || $x > 400 || $y < 20 || $y > 400) {
                  continue;
              }
              $label = trim((string)($target['callsign'] ?: $target['aircraft_hex'] ?? ''));
            ?>
            <circle cx="<?= h((string)round($x, 1)) ?>" cy="<?= h((string)round($y, 1)) ?>" r="3.5" fill="#facc15"></circle>
            <?php if ($label !== ''): ?><text x="<?= h((string)round($x + 5, 1)) ?>" y="<?= h((string)round($y - 5, 1)) ?>" fill="#fde68a" font-size="9" font-family="monospace"><?= h(substr($label, 0, 8)) ?></text><?php endif; ?>
          <?php endforeach; ?>
        </svg>
        <div class="adsb-grid">
          <div class="adsb-kv"><div class="adsb-label">Samples Shown</div><div class="adsb-value"><?= number_format(count($recentTraffic)) ?></div></div>
          <div class="adsb-kv"><div class="adsb-label">Newest Sample</div><div class="adsb-value"><?= h((string)($recentTraffic[0]['sample_time_utc'] ?? '')) ?></div></div>
          <div class="adsb-kv"><div class="adsb-label">Unique Aircraft</div><div class="adsb-value"><?= number_format(count(array_unique(array_map(static fn(array $row): string => (string)($row['aircraft_hex'] ?? ''), $recentTraffic)))) ?></div></div>
        </div>
      </div>
      <div class="adsb-table-wrap" style="margin-top:14px">
        <table class="adsb-table">
          <thead><tr><th>Time UTC</th><th>Aircraft</th><th>Callsign</th><th>Distance</th><th>Altitude</th><th>GS</th><th>Track</th><th>Position</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($recentTraffic, 0, 80) as $target): ?>
            <tr>
              <td><?= h((string)($target['sample_time_utc'] ?? '')) ?></td>
              <td class="adsb-code"><?= h(strtoupper((string)($target['aircraft_hex'] ?? ''))) ?></td>
              <td><?= h((string)($target['callsign'] ?? '')) ?></td>
              <td><?= is_numeric($target['distance_nm'] ?? null) ? h(number_format((float)$target['distance_nm'], 1) . ' NM') : '--' ?></td>
              <td><?= is_numeric($target['altitude_ft'] ?? null) ? h(number_format((float)$target['altitude_ft'], 0) . ' ft') : '--' ?></td>
              <td><?= is_numeric($target['groundspeed_kt'] ?? null) ? h(number_format((float)$target['groundspeed_kt'], 0) . ' kt') : '--' ?></td>
              <td><?= is_numeric($target['track_deg'] ?? null) ? h(number_format((float)$target['track_deg'], 0) . '°') : '--' ?></td>
              <td class="adsb-code"><?= h(number_format((float)($target['latitude'] ?? 0), 5)) ?>, <?= h(number_format((float)($target['longitude'] ?? 0), 5)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
  const initialDashboard = <?= $dashboardJson ?>;
  let dashboard = initialDashboard && initialDashboard.ok ? initialDashboard : {};
  let map = null;
  let targetCircle = null;
  let targetMarker = null;
  let trackLayer = null;
  let currentLayer = null;
  let refreshTimer = null;

  const el = (id) => document.getElementById(id);
  const fmt = (value) => Number.isFinite(Number(value)) ? Number(value).toLocaleString() : '--';
  const finite = (value) => Number.isFinite(Number(value)) ? Number(value) : null;
  const utcLabel = (epoch) => {
    const n = finite(epoch);
    return n === null ? '--' : new Date(n * 1000).toISOString().replace('T', ' ').slice(0, 19) + ' UTC';
  };
  const epochFromMysqlUtc = (value) => {
    if (!value) return null;
    const parsed = Date.parse(String(value).replace(' ', 'T') + 'Z');
    return Number.isFinite(parsed) ? Math.floor(parsed / 1000) : null;
  };

  function colorFor(hex) {
    let hash = 0;
    String(hex || '').split('').forEach((ch) => { hash = ((hash << 5) - hash) + ch.charCodeAt(0); hash |= 0; });
    return `hsl(${Math.abs(hash) % 360} 82% 45%)`;
  }

  function updateGrowth(data) {
    const growth = data && data.growth ? data.growth : {};
    el('adsbTotalSamples').textContent = fmt(growth.total_samples);
    el('adsbUniqueAircraft').textContent = fmt(growth.unique_aircraft);
    el('adsbRecentSamples').textContent = fmt(growth.recent_samples);
    el('adsbRecentAircraft').textContent = fmt(growth.recent_unique_aircraft);
    el('adsbRawPayloads').textContent = fmt(growth.raw_payloads);
    el('adsbNewestSample').textContent = growth.newest_sample_utc || '--';
    el('adsbRefreshTime').textContent = data.generated_at_utc || new Date().toISOString().replace('T', ' ').slice(0, 19);
    const newestEpoch = epochFromMysqlUtc(growth.newest_sample_utc);
    const ageSeconds = newestEpoch !== null ? Math.max(0, Math.round(Date.now() / 1000 - newestEpoch)) : null;
    const recordingStatus = el('adsbRecordingStatus');
    const recordingAge = el('adsbRecordingAge');
    if (ageSeconds === null) {
      recordingStatus.textContent = 'No Samples';
      recordingStatus.style.color = '#92400e';
      recordingAge.textContent = 'No archived traffic has been recorded yet.';
    } else if (ageSeconds <= 180) {
      recordingStatus.textContent = 'Recording OK';
      recordingStatus.style.color = '#166534';
      recordingAge.textContent = `${ageSeconds}s since newest sample`;
    } else {
      recordingStatus.textContent = 'Stale';
      recordingStatus.style.color = '#92400e';
      recordingAge.textContent = `${Math.round(ageSeconds / 60)} min since newest sample`;
    }
    const chart = el('adsbGrowthChart');
    const buckets = Array.isArray(growth.buckets) ? growth.buckets : [];
    const maxSamples = Math.max(1, ...buckets.map((b) => Number(b.samples) || 0));
    chart.innerHTML = buckets.slice(-120).map((bucket) => {
      const height = Math.max(2, Math.round(((Number(bucket.samples) || 0) / maxSamples) * 68));
      return `<div class="adsb-growth-bar" title="${bucket.bucket_utc}: ${bucket.samples} samples" style="height:${height}px"></div>`;
    }).join('');
  }

  function updateTargets(data) {
    const targets = Array.isArray(data.targets) ? data.targets : [];
    const select = el('adsbTargetSelect');
    const current = select.value || (data.selected_target && data.selected_target.id) || 'ktrm_live';
    select.innerHTML = targets.map((target) => `<option value="${String(target.id).replace(/"/g, '&quot;')}">${String(target.label || target.id)}</option>`).join('');
    select.value = targets.some((target) => String(target.id) === current) ? current : ((data.selected_target && data.selected_target.id) || 'ktrm_live');
    el('adsbTargetList').innerHTML = targets.map((target) => {
      const radius = finite(target.radius_nm);
      return `<div class="adsb-pill"><strong>${String(target.label || target.id)}</strong><br><span class="adsb-muted">${Number(target.lat).toFixed(5)}, ${Number(target.lon).toFixed(5)} · ${radius !== null ? radius.toFixed(1) : '--'} NM · ${String(target.source || '')}</span></div>`;
    }).join('') || '<div class="adsb-muted">No target definitions available.</div>';
  }

  function ensureMap(target) {
    if (typeof L === 'undefined') {
      el('adsbMapStatus').textContent = 'Leaflet map library did not load. Check browser/network blocking for unpkg.com.';
      return false;
    }
    if (!map) {
      map = L.map('adsbTargetMap', { scrollWheelZoom: true });
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
      }).addTo(map);
      trackLayer = L.layerGroup().addTo(map);
      currentLayer = L.layerGroup().addTo(map);
    }
    const lat = finite(target && target.lat) ?? 33.626701;
    const lon = finite(target && target.lon) ?? -116.160156;
    const radiusNm = finite(target && target.radius_nm) ?? 25;
    if (targetCircle) map.removeLayer(targetCircle);
    if (targetMarker) map.removeLayer(targetMarker);
    targetMarker = L.marker([lat, lon]).addTo(map).bindTooltip(String((target && target.label) || 'Target'));
    targetCircle = L.circle([lat, lon], { radius: radiusNm * 1852, color: '#2563eb', weight: 2, fillOpacity: 0.05 }).addTo(map);
    map.fitBounds(targetCircle.getBounds(), { padding: [24, 24] });
    setTimeout(() => map.invalidateSize(), 50);
    return true;
  }

  function updateMap(data) {
    const timeline = data && data.target_timeline ? data.target_timeline : {};
    const target = data.selected_target || timeline.target || {};
    if (!ensureMap(target)) return;
    trackLayer.clearLayers();
    const aircraft = Array.isArray(timeline.aircraft) ? timeline.aircraft : [];
    aircraft.forEach((item) => {
      const points = (Array.isArray(item.samples) ? item.samples : [])
        .map((sample) => [finite(sample.lat), finite(sample.lon)])
        .filter((point) => point[0] !== null && point[1] !== null);
      if (points.length >= 2) {
        L.polyline(points, { color: colorFor(item.hex), weight: 2, opacity: 0.35 }).addTo(trackLayer);
      }
    });
    const start = finite(timeline.start_epoch);
    const end = finite(timeline.end_epoch);
    const input = el('adsbTimeline');
    input.min = start !== null ? String(start) : '0';
    input.max = end !== null ? String(end) : '1';
    input.value = end !== null ? String(end) : input.min;
    input.disabled = start === null || end === null || start === end;
    el('adsbTimelineStart').textContent = utcLabel(start);
    el('adsbTimelineEnd').textContent = utcLabel(end);
    el('adsbTargetAircraft').textContent = fmt(timeline.aircraft_count);
    el('adsbTargetSamples').textContent = fmt(timeline.sample_count);
    const radius = finite(target.radius_nm);
    el('adsbTargetSummary').textContent = `${target.label || target.id || 'Target'} · ${radius !== null ? radius.toFixed(1) : '--'} NM · ${fmt(timeline.aircraft_count)} aircraft`;
    el('adsbMapStatus').textContent = `Loaded ${fmt(timeline.sample_count)} archived samples for ${fmt(timeline.aircraft_count)} aircraft.`;
    renderAtTime(Number(input.value || end || start || 0));
  }

  function nearestSample(samples, epoch) {
    let best = null;
    let bestDelta = Infinity;
    (Array.isArray(samples) ? samples : []).forEach((sample) => {
      const sampleEpoch = finite(sample.epoch);
      if (sampleEpoch === null) return;
      const delta = Math.abs(sampleEpoch - epoch);
      if (delta < bestDelta) {
        best = sample;
        bestDelta = delta;
      }
    });
    return bestDelta <= 300 ? best : null;
  }

  function renderAtTime(epoch) {
    const timeline = dashboard && dashboard.target_timeline ? dashboard.target_timeline : {};
    const aircraft = Array.isArray(timeline.aircraft) ? timeline.aircraft : [];
    if (!currentLayer) {
      el('adsbMapStatus').textContent = 'Map layer is not ready yet.';
      return;
    }
    currentLayer.clearLayers();
    const visible = [];
    aircraft.forEach((item) => {
      const sample = nearestSample(item.samples, epoch);
      if (!sample) return;
      const lat = finite(sample.lat);
      const lon = finite(sample.lon);
      if (lat === null || lon === null) return;
      const label = String(item.callsign || item.hex || '').trim().toUpperCase();
      const color = colorFor(item.hex);
      L.circleMarker([lat, lon], { radius: 6, color, fillColor: color, fillOpacity: 0.9, weight: 2 })
        .addTo(currentLayer)
        .bindTooltip(`${label}<br>${sample.utc || ''}<br>${sample.altitude_ft !== null ? Math.round(sample.altitude_ft) + ' ft' : ''}`, { direction: 'top' });
      visible.push({ label, sample });
    });
    if (visible.length > 0 && map && currentLayer) {
      const layers = currentLayer.getLayers();
      if (layers.length > 0) {
        const group = L.featureGroup(layers);
        map.fitBounds(group.getBounds().pad(0.25), { maxZoom: 11 });
      }
    }
    el('adsbTimelineCurrent').textContent = utcLabel(epoch);
    el('adsbMapStatus').textContent = `Showing ${visible.length} aircraft near ${utcLabel(epoch)}. Timeline contains ${fmt(timeline.sample_count)} samples.`;
    el('adsbAircraftList').innerHTML = visible
      .sort((a, b) => String(a.label).localeCompare(String(b.label)))
      .map((entry) => `<div class="adsb-pill"><strong>${entry.label}</strong><br><span class="adsb-muted">${entry.sample.utc || ''} · ${entry.sample.altitude_ft !== null ? Math.round(entry.sample.altitude_ft).toLocaleString() + ' ft' : '--'} · ${entry.sample.groundspeed_kt !== null ? Math.round(entry.sample.groundspeed_kt) + ' kt' : '--'}</span></div>`)
      .join('') || '<div class="adsb-muted">No aircraft sample within 5 minutes of selected time.</div>';
  }

  function applyDashboard(data) {
    if (!data || !data.ok) {
      el('adsbMapStatus').textContent = data && data.error ? data.error : 'ADS-B dashboard data is unavailable.';
      return;
    }
    dashboard = data;
    try {
      updateGrowth(data);
      updateTargets(data);
      updateMap(data);
    } catch (error) {
      el('adsbMapStatus').textContent = `Map render error: ${error && error.message ? error.message : error}`;
      throw error;
    }
  }

  async function loadDashboard() {
    const target = encodeURIComponent(el('adsbTargetSelect').value || 'ktrm_live');
    const hours = encodeURIComponent(el('adsbHoursInput').value || '6');
    const response = await fetch(`/admin/api/adsb_archive_dashboard.php?target=${target}&hours=${hours}`, { headers: { Accept: 'application/json' } });
    const data = await response.json();
    applyDashboard(data);
  }

  el('adsbTimeline').addEventListener('input', (event) => renderAtTime(Number(event.target.value || 0)));
  el('adsbTargetSelect').addEventListener('change', loadDashboard);
  el('adsbReloadButton').addEventListener('click', loadDashboard);
  el('adsbNewestButton').addEventListener('click', () => {
    const input = el('adsbTimeline');
    input.value = input.max || input.value;
    renderAtTime(Number(input.value || 0));
  });
  applyDashboard(dashboard);
  loadDashboard().catch((error) => {
    el('adsbMapStatus').textContent = `Dashboard API load failed: ${error && error.message ? error.message : error}`;
  });
  refreshTimer = window.setInterval(() => {
    loadDashboard().catch(() => {});
  }, 10000);
  window.addEventListener('beforeunload', () => {
    if (refreshTimer) window.clearInterval(refreshTimer);
  });
})();
</script>
