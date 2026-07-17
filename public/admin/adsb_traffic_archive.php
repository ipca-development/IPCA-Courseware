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

$status = array();
$recentTraffic = array();
try {
    $archiveService = new AdsbTrafficArchiveService($pdo);
    $status = $archiveService->status();
    $recentTraffic = $archiveService->recentTrafficSamples(250);
} catch (Throwable $e) {
    $error = $error !== '' ? $error : $e->getMessage();
}

cw_header('ADS-B Traffic Archive');
?>
<style>
.adsb-page{display:grid;gap:16px}.adsb-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:16px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.adsb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.adsb-kv{border:1px solid #e2e8f0;border-radius:12px;padding:10px;background:#f8fafc}.adsb-label{color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.04em}.adsb-value{font-weight:900;margin-top:4px}.adsb-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:end}.adsb-actions input{border:1px solid #cbd5e1;border-radius:8px;padding:7px 8px}.adsb-actions button,.adsb-button{border:0;border-radius:9px;background:#1d4ed8;color:#fff;font-weight:800;padding:8px 11px;cursor:pointer;text-decoration:none}.adsb-actions button.secondary,.adsb-button.secondary{background:#475569}.adsb-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px}.adsb-notice{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:12px}.adsb-warning{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:10px;padding:12px}.adsb-muted{color:#64748b;font-size:13px}.adsb-code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px}.adsb-table-wrap{overflow-x:auto}.adsb-table{width:100%;border-collapse:collapse;min-width:760px}.adsb-table th,.adsb-table td{border-bottom:1px solid #e2e8f0;padding:9px 8px;text-align:left}.adsb-table th{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.04em}.adsb-scope{width:100%;max-width:640px;background:#0f172a;border-radius:16px;border:1px solid rgba(148,163,184,.35)}.adsb-scope-grid{display:grid;grid-template-columns:minmax(280px,640px) 1fr;gap:16px;align-items:start}.adsb-pre{white-space:pre-wrap;background:#0f172a;color:#dbeafe;border-radius:10px;padding:12px;overflow:auto}
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
