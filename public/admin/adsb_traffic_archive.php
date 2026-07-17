<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/AdsbTrafficArchiveService.php';

cw_require_admin();

$error = trim((string)($_GET['error'] ?? ''));
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
try {
    $status = (new AdsbTrafficArchiveService($pdo))->status();
} catch (Throwable $e) {
    $error = $error !== '' ? $error : $e->getMessage();
}

cw_header('ADS-B Traffic Archive');
?>
<style>
.adsb-page{display:grid;gap:16px}.adsb-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:16px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.adsb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.adsb-kv{border:1px solid #e2e8f0;border-radius:12px;padding:10px;background:#f8fafc}.adsb-label{color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.04em}.adsb-value{font-weight:900;margin-top:4px}.adsb-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:end}.adsb-actions input{border:1px solid #cbd5e1;border-radius:8px;padding:7px 8px}.adsb-actions button,.adsb-button{border:0;border-radius:9px;background:#1d4ed8;color:#fff;font-weight:800;padding:8px 11px;cursor:pointer;text-decoration:none}.adsb-actions button.secondary,.adsb-button.secondary{background:#475569}.adsb-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px}.adsb-notice{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:12px}.adsb-warning{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:10px;padding:12px}.adsb-muted{color:#64748b;font-size:13px}.adsb-code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px}
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
</div>
