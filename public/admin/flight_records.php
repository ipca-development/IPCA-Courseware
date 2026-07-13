<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/FlightRecordViewService.php';

cw_require_admin();

$user = cw_current_user($pdo) ?: array();
$service = new FlightRecordViewService($pdo);
$records = $service->recordsForUser($user);

function fr_fmt_ms(mixed $ms): string
{
    if (!is_numeric($ms)) {
        return '--';
    }
    return number_format(((float)$ms) / 3600000, 1) . ' h';
}

cw_header('Flight Records');
?>
<style>
.flight-record-page{display:grid;gap:18px}.flight-record-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.flight-record-muted{color:#64748b;font-size:13px}.flight-record-table-wrap{overflow-x:auto}.flight-record-table{width:100%;border-collapse:collapse;min-width:920px}.flight-record-table th,.flight-record-table td{border-bottom:1px solid #e2e8f0;padding:10px 8px;text-align:left;vertical-align:top}.flight-record-table th{color:#475569;font-size:12px;text-transform:uppercase;letter-spacing:.04em}.flight-record-badge{display:inline-flex;border-radius:999px;padding:3px 8px;font-size:12px;font-weight:700;background:#e2e8f0;color:#334155}.flight-record-ready{background:#dcfce7;color:#166534}.flight-record-warning{background:#fef3c7;color:#92400e}
</style>
<div class="flight-record-page">
  <section class="flight-record-card">
    <h2 style="margin-top:0">Operational Flight Records</h2>
    <p class="flight-record-muted">Admin view of versioned operational records produced from CVR evidence. Audio, transcript, and replay endpoints remain separate and unchanged.</p>
    <p><a href="/admin/flight_record_logbook_proposals.php">Review Flight Record logbook proposals</a></p>
    <?php if (!$service->isReady()): ?>
      <p class="flight-record-badge flight-record-warning">Phase 1 database foundation has not been applied yet.</p>
    <?php endif; ?>
  </section>
  <section class="flight-record-card flight-record-table-wrap">
    <table class="flight-record-table">
      <thead><tr><th>Session</th><th>Aircraft</th><th>Status</th><th>Hobbs</th><th>Tacho</th><th>Landings</th><th>Version</th></tr></thead>
      <tbody>
      <?php foreach ($records as $row): ?>
        <tr>
          <td><?= h((string)($row['session_uuid'] ?? '')) ?><br><span class="flight-record-muted"><?= h((string)($row['avionics_on_utc'] ?? '')) ?></span></td>
          <td><?= h((string)($row['aircraft_registration'] ?? '')) ?></td>
          <td><span class="flight-record-badge <?= (($row['readiness_status'] ?? '') === 'ready') ? 'flight-record-ready' : '' ?>"><?= h((string)($row['readiness_status'] ?? $row['status'] ?? 'draft')) ?></span></td>
          <td><?= h(fr_fmt_ms($row['exact_hobbs_duration_ms'] ?? null)) ?></td>
          <td><?= h(fr_fmt_ms($row['exact_tacho_duration_ms'] ?? null)) ?></td>
          <td><?= h((string)($row['landing_event_count'] ?? '0')) ?></td>
          <td><?= h((string)($row['version_uuid'] ?? 'not derived')) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$records): ?>
        <tr><td colspan="7" class="flight-record-muted">No Flight Records available yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </section>
</div>
<?php cw_footer(); ?>
