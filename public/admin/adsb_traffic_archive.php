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
if (isset($_GET['target_created'])) {
    $notice = 'ADS-B live target created: ' . h((string)$_GET['target_created']) . '. The next cron run will start recording it.';
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
.adsb-page{display:grid;gap:16px}.adsb-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:16px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.adsb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.adsb-kv{border:1px solid #e2e8f0;border-radius:12px;padding:10px;background:#f8fafc}.adsb-label{color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.04em}.adsb-value{font-weight:900;margin-top:4px}.adsb-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:end}.adsb-actions input,.adsb-actions select,.adsb-control{border:1px solid #cbd5e1;border-radius:8px;padding:7px 8px;background:#fff}.adsb-actions button,.adsb-button{border:0;border-radius:9px;background:#1d4ed8;color:#fff;font-weight:800;padding:8px 11px;cursor:pointer;text-decoration:none}.adsb-actions button.secondary,.adsb-button.secondary{background:#475569}.adsb-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px}.adsb-notice{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:12px}.adsb-warning{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:10px;padding:12px}.adsb-muted{color:#64748b;font-size:13px}.adsb-code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px}.adsb-table-wrap{overflow-x:auto}.adsb-table{width:100%;border-collapse:collapse;min-width:760px}.adsb-table th,.adsb-table td{border-bottom:1px solid #e2e8f0;padding:9px 8px;text-align:left}.adsb-table th{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.04em}.adsb-pre{white-space:pre-wrap;background:#0f172a;color:#dbeafe;border-radius:10px;padding:12px;overflow:auto}.adsb-map-layout{display:grid;grid-template-columns:minmax(320px,1.7fr) minmax(260px,.8fr);gap:14px}.adsb-map{height:520px;border:1px solid #cbd5e1;border-radius:14px;overflow:hidden;background:#e2e8f0}.adsb-target-maps{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}.adsb-target-map{height:240px;border:1px solid #cbd5e1;border-radius:12px;overflow:hidden;background:#e2e8f0}.adsb-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin-bottom:12px}.adsb-timeline{width:100%;accent-color:#2563eb}.adsb-growth{height:84px;display:flex;align-items:end;gap:2px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;padding:8px}.adsb-growth-bar{flex:1;min-width:2px;background:#2563eb;border-radius:3px 3px 0 0}.adsb-target-list{display:grid;gap:8px;max-height:180px;overflow:auto}.adsb-aircraft-list{display:grid;gap:6px;max-height:240px;overflow:auto}.adsb-pill{border:1px solid #e2e8f0;border-radius:999px;padding:5px 8px;background:#f8fafc;font-size:12px}.adsb-live-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#16a34a;margin-right:5px}@media(max-width:960px){.adsb-map-layout{grid-template-columns:1fr}.adsb-map{height:420px}}
.adsb-aircraft-symbol{position:relative;display:grid;place-items:center;transform-origin:center}.adsb-aircraft-symbol svg{filter:drop-shadow(0 1px 2px rgba(15,23,42,.38))}.adsb-aircraft-symbol-large-jet,.adsb-aircraft-symbol-military{width:42px;height:42px}.adsb-aircraft-symbol-business-jet{width:36px;height:36px}.adsb-aircraft-symbol-small-prop,.adsb-aircraft-symbol-helicopter{width:30px;height:30px}.adsb-aircraft-symbol-plane{display:block;transform-origin:center}.adsb-aircraft-label{position:absolute;left:30px;top:14px;white-space:nowrap;background:rgba(38,38,38,.72);border:0;border-radius:0;padding:2px 5px 3px;color:#fff;font-size:10px;font-weight:900;line-height:1.05;letter-spacing:-.02em;text-align:left;text-shadow:0 1px 1px #000,1px 0 1px #000,0 -1px 1px #000,-1px 0 1px #000;box-shadow:none;pointer-events:none}.adsb-aircraft-label strong{display:block;font-size:10px;font-weight:900}.adsb-aircraft-label span{display:block;font-size:10px;font-weight:900}.adsb-aircraft-symbol-large-jet .adsb-aircraft-label,.adsb-aircraft-symbol-military .adsb-aircraft-label{left:34px;top:18px}.adsb-aircraft-symbol-business-jet .adsb-aircraft-label{left:32px;top:16px}.adsb-symbol-legend{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 10px}.adsb-symbol-legend span{display:inline-flex;align-items:center;gap:5px;border:1px solid #e2e8f0;border-radius:999px;background:#f8fafc;padding:4px 8px;font-size:12px;color:#475569}.adsb-legend-dot{width:12px;height:12px;border-radius:3px;border:1px solid #111827;display:inline-block}.adsb-legend-airplane{background:#facc15}.adsb-legend-helicopter{background:#2f9e5d}.adsb-legend-military{background:#64748b}
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
      <label class="adsb-muted" style="display:flex;gap:6px;align-items:center;margin-bottom:8px"><input id="adsbBelow10000Filter" type="checkbox"> Show &lt; 10,000 ft only</label>
      <label class="adsb-muted" style="display:flex;gap:6px;align-items:center;margin-bottom:8px"><input id="adsbTrafficLabelsToggle" type="checkbox" checked> Aircraft labels</label>
      <button class="adsb-button" type="button" id="adsbReloadButton">Reload Target</button>
      <button class="adsb-button secondary" type="button" id="adsbNewestButton">Jump To Newest</button>
      <span class="adsb-muted" id="adsbTargetSummary">--</span>
    </div>
    <div class="adsb-map-layout">
      <div>
        <div id="adsbTargetMap" class="adsb-map"></div>
        <div class="adsb-muted" id="adsbMapStatus" style="margin-top:6px">Loading archived traffic map...</div>
        <div style="margin-top:12px">
          <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
            <button class="adsb-button secondary" type="button" id="adsbPlayButton">Play</button>
            <span class="adsb-muted" id="adsbReplayMode">Live</span>
          </div>
          <input id="adsbTimeline" class="adsb-timeline" type="range" min="0" max="1" step="0.1" value="0" aria-label="ADS-B archive time scrubber">
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
        <div class="adsb-symbol-legend" aria-label="Traffic symbol legend">
          <span><i class="adsb-legend-dot adsb-legend-airplane"></i>Airplanes</span>
          <span><i class="adsb-legend-dot adsb-legend-helicopter"></i>Helicopters</span>
          <span><i class="adsb-legend-dot adsb-legend-military"></i>Possible military</span>
        </div>
        <div class="adsb-aircraft-list" id="adsbAircraftList"></div>
      </div>
    </div>
  </section>

  <section class="adsb-card">
    <h3 style="margin-top:0">Target Airports</h3>
    <p class="adsb-muted">Search by ICAO, IATA, airport name, or city. The lookup uses live OurAirports data with local caching, so this is not limited to airports already stored in the IPCA database.</p>
    <div class="adsb-actions" style="margin-bottom:10px">
      <label class="adsb-muted">Airport Search<br><input type="search" id="adsbAirportSearch" placeholder="KPSP, Palm Springs, EBAW, Antwerp"></label>
      <button class="adsb-button secondary" type="button" id="adsbAirportSearchButton">Search Airport</button>
      <label class="adsb-muted">Results<br><select class="adsb-control" id="adsbAirportResults" style="min-width:280px"><option value="">Search first...</option></select></label>
    </div>
    <form class="adsb-actions" method="post" action="/admin/api/adsb_archive_action.php">
      <input type="hidden" name="return" value="/admin/adsb_traffic_archive.php">
      <label class="adsb-muted">Target Name<br><input type="text" name="target_name" id="adsbTargetNameInput" placeholder="KPSP Live Archive" required></label>
      <label class="adsb-muted">Latitude<br><input type="number" name="target_lat" id="adsbTargetLatInput" step="0.000001" placeholder="auto-filled" required></label>
      <label class="adsb-muted">Longitude<br><input type="number" name="target_lon" id="adsbTargetLonInput" step="0.000001" placeholder="auto-filled" required></label>
      <label class="adsb-muted">Radius NM<br><input type="number" name="target_radius_nm" min="0.5" max="25" step="0.5" value="25"></label>
      <button type="submit" name="action" value="create_live_target">Add Target Airport</button>
    </form>
    <div class="adsb-muted" id="adsbAirportSearchStatus" style="margin-top:6px">Select an airport result to auto-fill coordinates.</div>
    <div class="adsb-target-maps" id="adsbTargetMaps" style="margin-top:14px"></div>
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
    <p class="adsb-muted">The server-side live recorder should run every minute. One invocation captures the home airport at high resolution by repeating live snapshots every 10 seconds, while other enabled airport targets are captured once per minute.</p>
    <div class="adsb-pre">* * * * * /usr/bin/flock -n /tmp/ipca-adsb-live.lock /usr/local/bin/ipca-adsb-live.sh &gt;&gt; /var/log/ipca-adsb-live.log 2&gt;&amp;1</div>
    <p class="adsb-muted">To add another airport target, use the airport search form above or insert a point-radius definition. ADS-B Exchange live radius is capped at 25 NM per target. Add <span class="adsb-code">"priority": "home"</span> or <span class="adsb-code">"resolution_seconds": 10</span> only for the primary high-resolution home airport.</p>
    <div class="adsb-pre">INSERT INTO ipca_adsb_geographic_definitions
(definition_uuid, name, definition_type, configuration_json, enabled, live_monitoring_enabled, replay_query_enabled)
VALUES
(UUID(), 'KPSP Live Archive', 'point_radius', JSON_OBJECT('lat', 33.829667, 'lon', -116.506667, 'radius_nm', 25, 'resolution_seconds', 60), 1, 1, 1);</div>
  </section>

  <section class="adsb-card">
    <h3 style="margin-top:0">Recorded KTRM Traffic</h3>
    <p class="adsb-muted">Recent normalized ADS-B samples within 15 NM of KTRM, recorded in the archive during the last 24 hours.</p>
    <?php if ($recentTraffic === array()): ?>
      <div class="adsb-warning">No archived traffic samples recorded yet. Run the cron or process a live tile.</div>
    <?php else: ?>
      <div class="adsb-grid">
        <div class="adsb-kv"><div class="adsb-label">Recent Samples Listed</div><div class="adsb-value"><?= number_format(count($recentTraffic)) ?></div></div>
        <div class="adsb-kv"><div class="adsb-label">Newest Sample</div><div class="adsb-value"><?= h((string)($recentTraffic[0]['sample_time_utc'] ?? '')) ?></div></div>
        <div class="adsb-kv"><div class="adsb-label">Unique Aircraft</div><div class="adsb-value"><?= number_format(count(array_unique(array_map(static fn(array $row): string => (string)($row['aircraft_hex'] ?? ''), $recentTraffic)))) ?></div></div>
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
  let playbackFrame = null;
  let playbackEpoch = null;
  let playbackLastFrameMs = null;
  let replayMode = false;
  let currentMapTargetKey = '';
  let targetMapsSignature = '';
  const playbackSpeed = 1;

  const el = (id) => document.getElementById(id);
  const fmt = (value) => Number.isFinite(Number(value)) ? Number(value).toLocaleString() : '--';
  const finite = (value) => Number.isFinite(Number(value)) ? Number(value) : null;
  const utcLabel = (epoch) => {
    const n = finite(epoch);
    return n === null ? '--' : new Date(n * 1000).toISOString().replace('T', ' ').slice(0, 19) + ' UTC';
  };
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ch]));
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

  function lerp(a, b, ratio) {
    const left = finite(a);
    const right = finite(b);
    if (left === null) return right;
    if (right === null) return left;
    return left + ((right - left) * ratio);
  }

  function lerpAngle(a, b, ratio) {
    const left = finite(a);
    const right = finite(b);
    if (left === null) return right;
    if (right === null) return left;
    const delta = ((((right - left) % 360) + 540) % 360) - 180;
    return (left + (delta * ratio) + 360) % 360;
  }

  function bearingDegrees(from, to) {
    const lat1 = finite(from && from.lat);
    const lon1 = finite(from && from.lon);
    const lat2 = finite(to && to.lat);
    const lon2 = finite(to && to.lon);
    if (lat1 === null || lon1 === null || lat2 === null || lon2 === null) return null;
    const phi1 = lat1 * Math.PI / 180;
    const phi2 = lat2 * Math.PI / 180;
    const lambda = (lon2 - lon1) * Math.PI / 180;
    const y = Math.sin(lambda) * Math.cos(phi2);
    const x = Math.cos(phi1) * Math.sin(phi2) - Math.sin(phi1) * Math.cos(phi2) * Math.cos(lambda);
    return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
  }

  function replayTuning() {
    const target = (dashboard && (dashboard.selected_target || (dashboard.target_timeline && dashboard.target_timeline.target))) || {};
    const highResolution = String(target.priority || '') === 'home';
    return highResolution
      ? { maxInterpolationGap: 30, maxHoldSeconds: 20, trailSeconds: 600 }
      : { maxInterpolationGap: 240, maxHoldSeconds: 120, trailSeconds: 720 };
  }

  function timelineBounds() {
    const input = el('adsbTimeline');
    return {
      min: finite(input && input.min),
      max: finite(input && input.max)
    };
  }

  function clampEpoch(epoch, min, max) {
    const value = finite(epoch);
    if (value === null) return min ?? max ?? 0;
    if (min !== null && value < min) return min;
    if (max !== null && value > max) return max;
    return value;
  }

  function updatePlaybackUi() {
    const button = el('adsbPlayButton');
    const mode = el('adsbReplayMode');
    if (button) button.textContent = playbackFrame !== null ? 'Pause' : 'Play';
    if (mode) mode.textContent = replayMode ? (playbackFrame !== null ? 'Replay Playing' : 'Replay Paused') : 'Live';
  }

  function setTimelineEpoch(epoch) {
    const input = el('adsbTimeline');
    const bounds = timelineBounds();
    const value = clampEpoch(epoch, bounds.min, bounds.max);
    input.value = String(value);
    renderAtTime(value);
  }

  function stopPlayback() {
    if (playbackFrame !== null) {
      window.cancelAnimationFrame(playbackFrame);
      playbackFrame = null;
    }
    playbackLastFrameMs = null;
    updatePlaybackUi();
  }

  function enterLiveMode() {
    stopPlayback();
    replayMode = false;
    const bounds = timelineBounds();
    if (bounds.max !== null) {
      setTimelineEpoch(bounds.max);
    }
    updatePlaybackUi();
  }

  function playbackStep(frameMs) {
    const bounds = timelineBounds();
    if (bounds.min === null || bounds.max === null) {
      stopPlayback();
      return;
    }
    if (playbackLastFrameMs === null) {
      playbackLastFrameMs = frameMs;
    }
    const deltaSeconds = Math.max(0, (frameMs - playbackLastFrameMs) / 1000) * playbackSpeed;
    playbackLastFrameMs = frameMs;
    playbackEpoch = clampEpoch((playbackEpoch ?? Number(el('adsbTimeline').value || bounds.min)) + deltaSeconds, bounds.min, bounds.max);
    setTimelineEpoch(playbackEpoch);
    if (playbackEpoch >= bounds.max - 0.05) {
      enterLiveMode();
      return;
    }
    playbackFrame = window.requestAnimationFrame(playbackStep);
  }

  function startPlayback(epoch) {
    const bounds = timelineBounds();
    if (bounds.min === null || bounds.max === null || bounds.min === bounds.max) return;
    replayMode = true;
    playbackEpoch = clampEpoch(epoch ?? Number(el('adsbTimeline').value || bounds.min), bounds.min, bounds.max);
    if (playbackEpoch >= bounds.max - 0.05) {
      playbackEpoch = bounds.min;
    }
    setTimelineEpoch(playbackEpoch);
    if (playbackFrame === null) {
      playbackLastFrameMs = null;
      playbackFrame = window.requestAnimationFrame(playbackStep);
    }
    updatePlaybackUi();
  }

  function aircraftDisplayType(item, sample) {
    const category = String((sample && sample.category) || '').toUpperCase();
    const callsign = String((item && item.callsign) || '').toUpperCase();
    const alt = finite(sample && sample.altitude_ft);
    const gs = finite(sample && sample.groundspeed_kt);
    if (/^(A7|H)/.test(category) || /(HELI|COPTER|LIFE|MEDEVAC|AIRMED|NIGHT|STAR|CHOPPER)/.test(callsign)) {
      return { key: 'helicopter', label: 'Helicopter Traffic', color: '#2f9e5d', size: 32, viewBox: '0 0 48 48' };
    }
    if (/^(RCH|REACH|CNV|JOSA|PAT|VV|VM|NAVY|ARMY|USAF|AF|MARINE|COAST|GUARD|NATO|GAF|RAF|FNF|BAF|CAF|LAGR|TANKR|TORCH|MACE|HAWK|VIPER|RAVEN|EAGLE|DRAGO|SHELL)/.test(callsign)) {
      return { key: 'military', label: 'Possible Military Traffic', color: '#64748b', size: 42, viewBox: '0 0 64 64' };
    }
    if (/^(AAL|DAL|UAL|SWA|ASA|SKW|ENY|FDX|UPS|BAW|DLH|JBU|NKS|FFT|ACA|KLM|AFR|QTR|UAE|SIA)/.test(callsign) || /^(A4|A5|B4|B5|C4|C5)/.test(category) || (gs !== null && gs >= 300)) {
      return { key: 'large-jet', label: 'Large Jet Airplane', color: '#2563eb', size: 42, viewBox: '0 0 64 64' };
    }
    if (/^(A3|A6|B3|B6|C3|C6)/.test(category) || (gs !== null && gs >= 220) || (alt !== null && alt >= 14000)) {
      return { key: 'business-jet', label: 'Business Jet Airplane', color: '#f8fafc', size: 36, viewBox: '0 0 56 56' };
    }
    return { key: 'small-prop', label: 'Small Prop Airplane', color: '#facc15', size: 30, viewBox: '0 0 48 48' };
  }

  function aircraftShape(displayType) {
    switch (displayType.key) {
      case 'large-jet':
        return '<path d="M31 4c4 0 5 8 5 18l21 12v6l-21-5-3 20 8 5v4l-10-3-10 3v-4l8-5-3-20-21 5v-6l21-12C26 12 27 4 31 4z"/><rect x="13" y="31" width="6" height="8" rx="1"/><rect x="45" y="31" width="6" height="8" rx="1"/>';
      case 'business-jet':
        return '<path d="M27 3c4 0 5 8 5 19l18 14v5l-18-6-2 13 7 4v4l-10-3-10 3v-4l7-4-2-13-18 6v-5l18-14C22 11 23 3 27 3z"/><rect x="16" y="34" width="5" height="8" rx="1"/><rect x="35" y="34" width="5" height="8" rx="1"/>';
      case 'helicopter':
        return '<path d="M23 3h2v42h-2z" fill="#111827"/><path d="M3 23h42v2H3z" fill="#111827"/><path d="M13 8 40 35l-5 5L8 13z" fill="#111827"/><path d="M35 8 8 35l5 5L40 13z" fill="#111827"/><ellipse cx="24" cy="25" rx="8" ry="13"/><path d="M21 37h6v7h-6z"/><path d="M20 44h8v3h-8z"/><path d="M17 22h14v11H17z" fill="rgba(15,23,42,.22)"/><circle cx="24" cy="24" r="3" fill="rgba(255,255,255,.22)"/>';
      case 'military':
        return '<path d="M31 3 41 24l19 11-16 7 2 14-15-6-15 6 2-14-16-7 19-11z"/><path d="M31 9 37 28l11 7-11 4 1 8-7-4-7 4 1-8-11-4 11-7z" fill="rgba(15,23,42,.28)"/>';
      default:
        return '<path d="M23 5c3 0 4 6 4 15l16 4v6l-16-1-2 12 6 3v3l-8-2-8 2v-3l6-3-2-12-16 1v-6l16-4C19 11 20 5 23 5z"/>';
    }
  }

  function aircraftLabel(item, sample, displayType) {
    const label = String((item && item.callsign) || (item && item.hex) || '').trim().toUpperCase();
    const altitude = finite(sample && sample.altitude_ft);
    const speed = finite(sample && sample.groundspeed_kt);
    const speedAltitude = `${speed !== null ? `${Math.round(speed).toLocaleString()} kt` : '-- kt'} ${altitude !== null ? `${Math.round(altitude).toLocaleString()} ft` : '-- ft'}`;
    return `<strong>${escapeHtml(speedAltitude)}</strong><span>${escapeHtml(label || displayType.label)}</span>`;
  }

  function aircraftIcon(item, sample, color, showLabels) {
    const displayType = aircraftDisplayType(item, sample);
    const heading = finite(sample && sample.track_deg) ?? 0;
    const size = displayType.size;
    const stroke = displayType.key === 'business-jet' ? '#0f172a' : '#111827';
    const fill = displayType.color || color;
    return L.divIcon({
      className: '',
      iconSize: [size, size],
      iconAnchor: [size / 2, size / 2],
      html: `<div class="adsb-aircraft-symbol adsb-aircraft-symbol-${displayType.key}"><svg class="adsb-aircraft-symbol-plane" viewBox="${displayType.viewBox}" aria-hidden="true" style="transform:rotate(${heading}deg)"><g fill="${fill}" stroke="${stroke}" stroke-width="2" stroke-linejoin="round">${aircraftShape(displayType)}</g></svg>${showLabels ? `<div class="adsb-aircraft-label">${aircraftLabel(item, sample, displayType)}</div>` : ''}</div>`
    });
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
      return `<div class="adsb-pill"><strong>${escapeHtml(target.label || target.id)}</strong><br><span class="adsb-muted">${Number(target.lat).toFixed(5)}, ${Number(target.lon).toFixed(5)} · ${radius !== null ? radius.toFixed(1) : '--'} NM</span></div>`;
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
    const targetKey = String((target && target.id) || `${lat},${lon},${radiusNm}`);
    const targetChanged = targetKey !== currentMapTargetKey;
    currentMapTargetKey = targetKey;
    if (targetCircle) map.removeLayer(targetCircle);
    if (targetMarker) map.removeLayer(targetMarker);
    targetMarker = L.marker([lat, lon]).addTo(map);
    targetCircle = L.circle([lat, lon], { radius: radiusNm * 1852, color: '#2563eb', weight: 2, fillOpacity: 0.05 }).addTo(map);
    if (targetChanged) {
      map.setView([lat, lon], radiusNm >= 20 ? 9 : (radiusNm >= 10 ? 10 : 11));
    }
    setTimeout(() => map.invalidateSize(), 50);
    return true;
  }

  function renderTargetAirportMaps(data) {
    const container = el('adsbTargetMaps');
    const targets = Array.isArray(data.targets) ? data.targets : [];
    const signature = JSON.stringify(targets.map((target) => [target.id, target.label, target.lat, target.lon, target.radius_nm]));
    if (signature === targetMapsSignature) return;
    targetMapsSignature = signature;
    container.innerHTML = targets.map((target, index) => `
      <div class="adsb-kv">
        <strong>${escapeHtml(target.label || target.id)}</strong>
        <div class="adsb-muted">${Number(target.lat).toFixed(6)}, ${Number(target.lon).toFixed(6)} · ${Number(target.radius_nm).toFixed(1)} NM</div>
        <div class="adsb-target-map" id="adsbTargetMiniMap${index}"></div>
      </div>
    `).join('');
    if (typeof L === 'undefined') return;
    targets.forEach((target, index) => {
      const lat = finite(target.lat);
      const lon = finite(target.lon);
      const radiusNm = finite(target.radius_nm) ?? 25;
      const node = el(`adsbTargetMiniMap${index}`);
      if (lat === null || lon === null || !node) return;
      const mini = L.map(node, { scrollWheelZoom: false, dragging: false, zoomControl: false, attributionControl: false });
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(mini);
      L.circle([lat, lon], { radius: radiusNm * 1852, color: '#2563eb', weight: 2, fillOpacity: 0.08 }).addTo(mini);
      L.marker([lat, lon]).addTo(mini);
      mini.setView([lat, lon], radiusNm >= 20 ? 9 : (radiusNm >= 10 ? 10 : 11));
      setTimeout(() => mini.invalidateSize(), 50);
    });
  }

  function airportOptionLabel(airport) {
    const ident = airport.ident || airport.icao || airport.iata || '';
    const city = airport.municipality ? ` · ${airport.municipality}` : '';
    const country = airport.country ? `, ${airport.country}` : '';
    return `${ident} - ${airport.name || 'Airport'}${city}${country}`;
  }

  function fillTargetFromAirport(airport) {
    if (!airport) return;
    const ident = airport.icao || airport.ident || airport.iata || '';
    el('adsbTargetNameInput').value = `${ident || airport.name} Live Archive`;
    el('adsbTargetLatInput').value = Number(airport.lat).toFixed(6);
    el('adsbTargetLonInput').value = Number(airport.lon).toFixed(6);
    el('adsbAirportSearchStatus').textContent = `Selected ${airportOptionLabel(airport)} from ${airport.source || 'lookup'}.`;
  }

  async function searchAirports() {
    const query = (el('adsbAirportSearch').value || '').trim();
    const status = el('adsbAirportSearchStatus');
    const results = el('adsbAirportResults');
    if (query.length < 2) {
      status.textContent = 'Enter at least 2 characters, such as KPSP or Palm Springs.';
      return;
    }
    status.textContent = 'Searching airport database...';
    results.innerHTML = '<option value="">Searching...</option>';
    const response = await fetch(`/admin/api/airport_search.php?q=${encodeURIComponent(query)}&limit=25`, { headers: { Accept: 'application/json' } });
    const data = await response.json();
    if (!data.ok) {
      throw new Error(data.error || 'Airport search failed.');
    }
    const airports = Array.isArray(data.airports) ? data.airports : [];
    results._airports = airports;
    results.innerHTML = airports.length
      ? airports.map((airport, index) => `<option value="${index}">${airportOptionLabel(airport)}</option>`).join('')
      : '<option value="">No matching airports found</option>';
    status.textContent = airports.length ? `${airports.length} airport result(s). Select one to auto-fill coordinates.` : 'No matching airports found.';
    if (airports.length === 1) {
      results.value = '0';
      fillTargetFromAirport(airports[0]);
    }
  }

  function updateMap(data) {
    const timeline = data && data.target_timeline ? data.target_timeline : {};
    const target = data.selected_target || timeline.target || {};
    if (!ensureMap(target)) return;
    trackLayer.clearLayers();
    const start = finite(timeline.start_epoch);
    const end = finite(timeline.end_epoch);
    const input = el('adsbTimeline');
    const priorEpoch = finite(input.value);
    input.min = start !== null ? String(start) : '0';
    input.max = end !== null ? String(end) : '1';
    const nextEpoch = replayMode && priorEpoch !== null
      ? clampEpoch(priorEpoch, start, end)
      : (end !== null ? end : Number(input.min));
    input.value = String(nextEpoch);
    input.disabled = start === null || end === null || start === end;
    el('adsbTimelineStart').textContent = utcLabel(start);
    el('adsbTimelineEnd').textContent = utcLabel(end);
    el('adsbTargetAircraft').textContent = fmt(timeline.aircraft_count);
    el('adsbTargetSamples').textContent = fmt(timeline.sample_count);
    const radius = finite(target.radius_nm);
    el('adsbTargetSummary').textContent = `${target.label || target.id || 'Target'} · ${radius !== null ? radius.toFixed(1) : '--'} NM · ${fmt(timeline.aircraft_count)} aircraft`;
    el('adsbMapStatus').textContent = `Loaded ${fmt(timeline.sample_count)} archived samples for ${fmt(timeline.aircraft_count)} aircraft.`;
    renderAtTime(Number(input.value || end || start || 0));
    updatePlaybackUi();
  }

  function nearestSample(samples, epoch, maxHoldSeconds) {
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
    return bestDelta <= maxHoldSeconds ? best : null;
  }

  function interpolatedSample(samples, epoch, tuning) {
    const valid = (Array.isArray(samples) ? samples : [])
      .filter((sample) => finite(sample && sample.epoch) !== null && finite(sample && sample.lat) !== null && finite(sample && sample.lon) !== null)
      .sort((a, b) => Number(a.epoch) - Number(b.epoch));
    if (valid.length === 0) return null;

    let before = null;
    let after = null;
    for (const sample of valid) {
      const sampleEpoch = finite(sample.epoch);
      if (sampleEpoch === null) continue;
      if (sampleEpoch <= epoch) before = sample;
      if (sampleEpoch >= epoch) {
        after = sample;
        break;
      }
    }

    if (before && after) {
      const beforeEpoch = finite(before.epoch);
      const afterEpoch = finite(after.epoch);
      if (beforeEpoch === null || afterEpoch === null) return nearestSample(valid, epoch, tuning.maxHoldSeconds);
      if (beforeEpoch === afterEpoch) return { ...before, interpolated: false };
      const gap = afterEpoch - beforeEpoch;
      if (gap > tuning.maxInterpolationGap) return nearestSample(valid, epoch, tuning.maxHoldSeconds);
      const ratio = Math.max(0, Math.min(1, (epoch - beforeEpoch) / gap));
      const derivedTrack = bearingDegrees(before, after);
      const track = lerpAngle(before.track_deg ?? derivedTrack, after.track_deg ?? derivedTrack, ratio);
      return {
        ...before,
        epoch,
        utc: utcLabel(epoch),
        lat: lerp(before.lat, after.lat, ratio),
        lon: lerp(before.lon, after.lon, ratio),
        altitude_ft: lerp(before.altitude_ft, after.altitude_ft, ratio),
        groundspeed_kt: lerp(before.groundspeed_kt, after.groundspeed_kt, ratio),
        track_deg: track,
        category: before.category || after.category || null,
        interpolated: ratio > 0 && ratio < 1
      };
    }

    return nearestSample(valid, epoch, tuning.maxHoldSeconds);
  }

  function trailPoints(samples, epoch, tuning) {
    const start = epoch - tuning.trailSeconds;
    return (Array.isArray(samples) ? samples : [])
      .filter((sample) => {
        const sampleEpoch = finite(sample && sample.epoch);
        return sampleEpoch !== null && sampleEpoch >= start && sampleEpoch <= epoch && finite(sample.lat) !== null && finite(sample.lon) !== null;
      })
      .sort((a, b) => Number(a.epoch) - Number(b.epoch))
      .map((sample) => [finite(sample.lat), finite(sample.lon)])
      .filter((point) => point[0] !== null && point[1] !== null);
  }

  function renderAtTime(epoch) {
    const timeline = dashboard && dashboard.target_timeline ? dashboard.target_timeline : {};
    const aircraft = Array.isArray(timeline.aircraft) ? timeline.aircraft : [];
    const belowOnly = Boolean(el('adsbBelow10000Filter') && el('adsbBelow10000Filter').checked);
    if (!currentLayer) {
      el('adsbMapStatus').textContent = 'Map layer is not ready yet.';
      return;
    }
    currentLayer.clearLayers();
    const visible = [];
    const showLabels = Boolean(el('adsbTrafficLabelsToggle') && el('adsbTrafficLabelsToggle').checked);
    const tuning = replayTuning();
    aircraft.forEach((item) => {
      const sample = interpolatedSample(item.samples, epoch, tuning);
      if (!sample) return;
      const alt = finite(sample.altitude_ft);
      if (belowOnly && (alt === null || alt >= 10000)) return;
      const lat = finite(sample.lat);
      const lon = finite(sample.lon);
      if (lat === null || lon === null) return;
      const label = String(item.callsign || item.hex || '').trim().toUpperCase();
      const color = colorFor(item.hex);
      const trail = trailPoints(item.samples, epoch, tuning);
      if (trail.length >= 2) {
        L.polyline(trail, { color, weight: 3, opacity: 0.42 }).addTo(currentLayer);
      }
      L.marker([lat, lon], { icon: aircraftIcon(item, sample, color, showLabels) })
        .addTo(currentLayer);
      visible.push({ label, sample, displayType: aircraftDisplayType(item, sample) });
    });
    el('adsbTimelineCurrent').textContent = utcLabel(epoch);
    el('adsbMapStatus').textContent = `Showing ${visible.length} aircraft${belowOnly ? ' below 10,000 ft' : ''} near ${utcLabel(epoch)}. Hold ${tuning.maxHoldSeconds}s, interpolate ${tuning.maxInterpolationGap}s.`;
    el('adsbAircraftList').innerHTML = visible
      .sort((a, b) => String(a.label).localeCompare(String(b.label)))
      .map((entry) => `<div class="adsb-pill"><strong>${entry.label}</strong> <span class="adsb-muted">${entry.displayType.label}</span><br><span class="adsb-muted">${entry.sample.utc || ''} · ${entry.sample.altitude_ft !== null ? Math.round(entry.sample.altitude_ft).toLocaleString() + ' ft' : '--'} · ${entry.sample.groundspeed_kt !== null ? Math.round(entry.sample.groundspeed_kt) + ' kt' : '--'}</span></div>`)
      .join('') || '<div class="adsb-muted">No aircraft sample near selected time.</div>';
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
      renderTargetAirportMaps(data);
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

  el('adsbTimeline').addEventListener('input', (event) => {
    const epoch = Number(event.target.value || 0);
    const bounds = timelineBounds();
    if (bounds.max !== null && epoch < bounds.max - 0.5) {
      stopPlayback();
      startPlayback(epoch);
    } else {
      enterLiveMode();
    }
  });
  el('adsbPlayButton').addEventListener('click', () => {
    if (playbackFrame !== null) {
      stopPlayback();
      replayMode = true;
      updatePlaybackUi();
      return;
    }
    startPlayback(Number(el('adsbTimeline').value || 0));
  });
  el('adsbTargetSelect').addEventListener('change', () => {
    stopPlayback();
    replayMode = false;
    currentMapTargetKey = '';
    loadDashboard();
  });
  el('adsbReloadButton').addEventListener('click', loadDashboard);
  el('adsbNewestButton').addEventListener('click', () => {
    enterLiveMode();
    loadDashboard().catch(() => {});
  });
  el('adsbBelow10000Filter').addEventListener('change', () => {
    renderAtTime(Number(el('adsbTimeline').value || 0));
  });
  el('adsbTrafficLabelsToggle').addEventListener('change', () => {
    renderAtTime(Number(el('adsbTimeline').value || 0));
  });
  el('adsbAirportSearchButton').addEventListener('click', () => {
    searchAirports().catch((error) => {
      el('adsbAirportSearchStatus').textContent = `Airport search failed: ${error && error.message ? error.message : error}`;
    });
  });
  el('adsbAirportSearch').addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      el('adsbAirportSearchButton').click();
    }
  });
  el('adsbAirportResults').addEventListener('change', (event) => {
    const airports = event.target._airports || [];
    const airport = airports[Number(event.target.value)];
    fillTargetFromAirport(airport);
  });
  applyDashboard(dashboard);
  loadDashboard().catch((error) => {
    el('adsbMapStatus').textContent = `Dashboard API load failed: ${error && error.message ? error.message : error}`;
  });
  refreshTimer = window.setInterval(() => {
    if (replayMode || playbackFrame !== null) return;
    loadDashboard().catch(() => {});
  }, 10000);
  window.addEventListener('beforeunload', () => {
    stopPlayback();
    if (refreshTimer) window.clearInterval(refreshTimer);
  });
})();
</script>
