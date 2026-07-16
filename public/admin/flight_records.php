<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/FlightRecordViewService.php';

cw_require_admin();

$user = cw_current_user($pdo) ?: array();
$service = new FlightRecordViewService($pdo);
$records = $service->recordsForUser($user);
$notice = '';
$error = trim((string)($_GET['error'] ?? ''));
if ((string)($_GET['rederived'] ?? '') === '1') {
    $notice = 'Flight Record preview recalculated.'
        . (!empty($_GET['readiness']) ? ' Readiness: ' . (string)$_GET['readiness'] . '.' : '');
}

function fr_fmt_ms(mixed $ms): string
{
    if (!is_numeric($ms)) {
        return '--';
    }
    return number_format(((float)$ms) / 3600000, 1) . ' h';
}

function fr_summary(array $row): array
{
    $decoded = json_decode((string)($row['summary_json'] ?? ''), true);
    return is_array($decoded) ? $decoded : array();
}

cw_header('Flight Records');
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
.flight-record-page{display:grid;gap:18px}.flight-record-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.flight-record-muted{color:#64748b;font-size:13px}.flight-record-table-wrap{overflow-x:auto}.flight-record-table{width:100%;border-collapse:collapse;min-width:1040px}.flight-record-table th,.flight-record-table td{border-bottom:1px solid #e2e8f0;padding:10px 8px;text-align:left;vertical-align:top}.flight-record-table th{color:#475569;font-size:12px;text-transform:uppercase;letter-spacing:.04em}.flight-record-badge{display:inline-flex;border-radius:999px;padding:3px 8px;font-size:12px;font-weight:700;background:#e2e8f0;color:#334155}.flight-record-ready{background:#dcfce7;color:#166534}.flight-record-warning{background:#fef3c7;color:#92400e}.flight-record-exceptions{margin:5px 0 0;padding-left:17px;color:#92400e;font-size:12px}.flight-record-code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px}.flight-record-button{border:0;border-radius:9px;background:#1d4ed8;color:#fff;font-weight:800;padding:7px 10px;cursor:pointer}.flight-record-button.secondary{background:#475569}.flight-record-actions{display:flex;flex-wrap:wrap;gap:7px;margin-top:8px}.flight-record-notice{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:12px}.flight-record-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px}.flight-record-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.56);display:none;z-index:9999;padding:28px;overflow:auto}.flight-record-modal-backdrop.is-open{display:block}.flight-record-modal{max-width:1080px;margin:0 auto;background:#fff;border-radius:18px;box-shadow:0 25px 70px rgba(15,23,42,.35);overflow:hidden}.flight-record-modal-header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;padding:18px 20px;border-bottom:1px solid #e2e8f0}.flight-record-modal-body{padding:18px 20px;display:grid;gap:14px}.flight-record-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.flight-record-kv{border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;padding:12px}.flight-record-label{font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.04em}.flight-record-value{font-weight:800;margin-top:4px}.flight-record-map{height:260px;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;background:#f8fafc}.flight-record-section{border:1px solid #e2e8f0;border-radius:14px;padding:14px;display:grid;gap:10px}
</style>
<div class="flight-record-page">
  <section class="flight-record-card">
    <h2 style="margin-top:0">Operational Flight Records</h2>
    <p class="flight-record-muted">Admin view of versioned operational records produced from CVR evidence. Audio, transcript, and replay endpoints remain separate and unchanged.</p>
    <p><a href="/admin/flight_record_logbook_proposals.php">Review Flight Record logbook proposals</a></p>
    <?php if ($notice !== ''): ?><div class="flight-record-notice"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="flight-record-error"><?= h($error) ?></div><?php endif; ?>
    <?php if (!$service->isReady()): ?>
      <p class="flight-record-badge flight-record-warning">Phase 1 database foundation has not been applied yet.</p>
    <?php endif; ?>
  </section>
  <section class="flight-record-card flight-record-table-wrap">
    <table class="flight-record-table">
      <thead><tr><th>Session</th><th>Aircraft</th><th>Status</th><th>Hobbs</th><th>Tacho</th><th>Night / XC</th><th>Landings</th><th>Preview</th><th>Version</th></tr></thead>
      <tbody>
      <?php foreach ($records as $row): ?>
        <?php
          $summary = fr_summary($row);
          $preview = isset($summary['preview']) && is_array($summary['preview']) ? $summary['preview'] : array();
          $calculations = isset($preview['calculations']) && is_array($preview['calculations']) ? $preview['calculations'] : array();
          $route = isset($preview['route']) && is_array($preview['route']) ? $preview['route'] : array();
          $routeJson = json_encode($route, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
          $exceptions = isset($summary['exceptions']) && is_array($summary['exceptions']) ? array_slice($summary['exceptions'], 0, 3) : array();
          $modalId = 'flight-record-modal-' . (int)($row['id'] ?? 0);
          $csvFileId = (int)($summary['csv_file_id'] ?? ($preview['csv_file']['id'] ?? 0));
        ?>
        <tr>
          <td><?= h((string)($row['session_uuid'] ?? '')) ?><br><span class="flight-record-muted"><?= h((string)($row['avionics_on_utc'] ?? '')) ?></span></td>
          <td><?= h((string)($row['aircraft_registration'] ?? '')) ?></td>
          <td><span class="flight-record-badge <?= (($row['readiness_status'] ?? '') === 'ready') ? 'flight-record-ready' : '' ?>"><?= h((string)($row['readiness_status'] ?? $row['status'] ?? 'draft')) ?></span></td>
          <td><?= h(fr_fmt_ms($row['exact_hobbs_duration_ms'] ?? null)) ?></td>
          <td><?= h(fr_fmt_ms($row['exact_tacho_duration_ms'] ?? null)) ?></td>
          <td><?= h(fr_fmt_ms($summary['total_night_duration_ms'] ?? null)) ?><br><span class="flight-record-muted">EASA XC: <?= !empty($summary['cross_country_easa_qualified']) ? 'yes' : 'no' ?> · FAA XC: <?= !empty($summary['cross_country_faa_qualified']) ? 'yes' : 'no' ?></span></td>
          <td><?= h((string)($row['landing_event_count'] ?? '0')) ?></td>
          <td>
            <span class="flight-record-muted">Source: <?= h((string)($summary['source'] ?? '--')) ?> · Calc: <?= h((string)($summary['calculation_version'] ?? '--')) ?></span>
            <?php if ($exceptions): ?>
              <ul class="flight-record-exceptions">
                <?php foreach ($exceptions as $exception): ?><li><?= h((string)$exception) ?></li><?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <div class="flight-record-actions">
              <button class="flight-record-button" type="button" data-modal-open="<?= h($modalId) ?>">Details</button>
              <?php if ($csvFileId > 0): ?>
                <form method="post" action="/admin/api/flight_record_rederive.php" data-rederive-form>
                  <input type="hidden" name="csv_file_id" value="<?= $csvFileId ?>">
                  <input type="hidden" name="return" value="/admin/flight_records.php">
                  <button class="flight-record-button secondary" type="submit">Recalculate</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
          <td><span class="flight-record-code"><?= h((string)($row['version_uuid'] ?? 'not derived')) ?></span></td>
        </tr>
        <tr>
          <td colspan="9" style="padding:0;border:0">
            <div class="flight-record-modal-backdrop" id="<?= h($modalId) ?>" aria-hidden="true">
              <div class="flight-record-modal" role="dialog" aria-modal="true" aria-labelledby="<?= h($modalId) ?>-title">
                <div class="flight-record-modal-header">
                  <div>
                    <h2 id="<?= h($modalId) ?>-title" style="margin:0">Flight Record <?= h((string)($row['flight_record_uuid'] ?? '')) ?></h2>
                    <div class="flight-record-muted"><?= h((string)($row['aircraft_registration'] ?? '')) ?> · <?= h((string)($row['avionics_on_utc'] ?? '')) ?></div>
                  </div>
                  <button class="flight-record-button" type="button" data-modal-close>Close</button>
                </div>
                <div class="flight-record-modal-body">
                  <section class="flight-record-section">
                    <h3 style="margin:0">Flight Calculation</h3>
                    <div class="flight-record-grid">
                      <div class="flight-record-kv"><div class="flight-record-label">Hobbs</div><div class="flight-record-value"><?= h(fr_fmt_ms($row['exact_hobbs_duration_ms'] ?? null)) ?></div><div class="flight-record-muted"><?= h((string)($calculations['hobbs']['verification_status'] ?? '--')) ?></div></div>
                      <div class="flight-record-kv"><div class="flight-record-label">Tacho</div><div class="flight-record-value"><?= h(fr_fmt_ms($row['exact_tacho_duration_ms'] ?? null)) ?></div><div class="flight-record-muted"><?= h((string)($calculations['tacho']['verification_status'] ?? '--')) ?></div></div>
                      <div class="flight-record-kv"><div class="flight-record-label">Night</div><div class="flight-record-value"><?= h(fr_fmt_ms($summary['total_night_duration_ms'] ?? null)) ?></div><div class="flight-record-muted"><?= h((string)($calculations['day_night']['verification_status'] ?? '--')) ?></div></div>
                      <div class="flight-record-kv"><div class="flight-record-label">Crew record</div><div class="flight-record-value">Pending assignment</div><div class="flight-record-muted">Crew version details will appear here when assigned.</div></div>
                    </div>
                    <?php if ($csvFileId > 0): ?>
                      <form method="post" action="/admin/api/flight_record_rederive.php" data-rederive-form>
                        <input type="hidden" name="csv_file_id" value="<?= $csvFileId ?>">
                        <input type="hidden" name="return" value="/admin/flight_records.php">
                        <button class="flight-record-button secondary" type="submit">Recalculate Flight Record preview from Garmin file</button>
                      </form>
                    <?php else: ?>
                      <div class="flight-record-muted">No Garmin CSV id is stored in this version summary yet, so this record cannot be recalculated from this action.</div>
                    <?php endif; ?>
                  </section>
                  <section class="flight-record-section">
                    <h3 style="margin:0">Garmin Route Preview</h3>
                    <div class="flight-record-muted">Small map from the Garmin-derived route sample, for quick validation that the source file matches the expected flight.</div>
                    <div class="flight-record-map" data-flight-map data-route="<?= h($routeJson) ?>"></div>
                  </section>
                </div>
              </div>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$records): ?>
        <tr><td colspan="9" class="flight-record-muted">No Flight Records available yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </section>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
  const maps = new WeakMap();

  function parseRoute(element) {
    try {
      const route = JSON.parse(element.getAttribute('data-route') || '{}');
      return route && Array.isArray(route.points) ? route.points : [];
    } catch (error) {
      return [];
    }
  }

  function renderMap(element) {
    if (!element || maps.has(element) || typeof L === 'undefined') return;
    const points = parseRoute(element)
      .map((point) => [Number(point.lat), Number(point.lon)])
      .filter((point) => Number.isFinite(point[0]) && Number.isFinite(point[1]));
    const map = L.map(element, { scrollWheelZoom: false });
    maps.set(element, map);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    if (points.length < 2) {
      map.setView([33.6267, -116.1600], 8);
      element.insertAdjacentHTML('beforeend', '<div class="flight-record-muted" style="position:absolute;z-index:500;left:12px;top:12px;background:#fff;padding:6px 8px;border-radius:8px;">No route preview stored yet. Re-run derivation for this Garmin file.</div>');
      return;
    }
    const polyline = L.polyline(points, { color: '#2563eb', weight: 3, opacity: 0.85 }).addTo(map);
    L.circleMarker(points[0], { radius: 5, color: '#16a34a', fillColor: '#16a34a', fillOpacity: 1 }).addTo(map).bindTooltip('Start');
    L.circleMarker(points[points.length - 1], { radius: 5, color: '#dc2626', fillColor: '#dc2626', fillOpacity: 1 }).addTo(map).bindTooltip('End');
    map.fitBounds(polyline.getBounds(), { padding: [18, 18] });
    setTimeout(() => map.invalidateSize(), 50);
  }

  function openModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    modal.querySelectorAll('[data-flight-map]').forEach(renderMap);
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  }

  document.querySelectorAll('[data-modal-open]').forEach((button) => {
    button.addEventListener('click', () => openModal(button.getAttribute('data-modal-open') || ''));
  });
  document.querySelectorAll('[data-rederive-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm('Recalculate this Flight Record preview from the existing Garmin file? This creates a new version using the current calculation logic.')) {
        event.preventDefault();
      }
    });
  });
  document.querySelectorAll('[data-modal-close]').forEach((button) => {
    button.addEventListener('click', () => closeModal(button.closest('.flight-record-modal-backdrop')));
  });
  document.querySelectorAll('.flight-record-modal-backdrop').forEach((modal) => {
    modal.addEventListener('click', (event) => {
      if (event.target === modal) closeModal(modal);
    });
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      document.querySelectorAll('.flight-record-modal-backdrop.is-open').forEach(closeModal);
    }
  });
})();
</script>
<?php cw_footer(); ?>
