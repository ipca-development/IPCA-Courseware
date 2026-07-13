<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/FlightRecordViewService.php';

cw_require_login();
$user = cw_current_user($pdo) ?: array();
$role = strtolower(trim((string)($user['role'] ?? '')));
if (!in_array($role, array('admin', 'supervisor', 'instructor', 'chief_instructor'), true)) {
    redirect(cw_home_path_for_role($role));
}

$service = new FlightRecordViewService($pdo);
$records = $service->recordsForUser($user);

function ifr_fmt_ms(mixed $ms): string
{
    return is_numeric($ms) ? number_format(((float)$ms) / 3600000, 1) . ' h' : '--';
}

cw_header('Instructor Flight Records');
?>
<style>
.ifr-page{display:grid;gap:18px}.ifr-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.ifr-muted{color:#64748b;font-size:13px}.ifr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}.ifr-record{border:1px solid #e2e8f0;border-radius:12px;padding:12px;background:#f8fafc}.ifr-badge{display:inline-flex;border-radius:999px;padding:3px 8px;font-size:12px;font-weight:700;background:#dbeafe;color:#1e40af}
</style>
<div class="ifr-page">
  <section class="ifr-card">
    <h2 style="margin-top:0">Instructor Flight Records</h2>
    <p class="ifr-muted">Operational records visible for instructor review. Release controls and debrief approvals are added in later phases.</p>
    <?php if (!$service->isReady()): ?>
      <p class="ifr-badge">Phase 1 database foundation has not been applied yet.</p>
    <?php endif; ?>
  </section>
  <section class="ifr-grid">
    <?php foreach ($records as $row): ?>
      <article class="ifr-record">
        <strong><?= h((string)($row['aircraft_registration'] ?? 'Aircraft')) ?></strong>
        <div class="ifr-muted"><?= h((string)($row['avionics_on_utc'] ?? 'No start time')) ?></div>
        <p><span class="ifr-badge"><?= h((string)($row['readiness_status'] ?? $row['status'] ?? 'draft')) ?></span></p>
        <div>Hobbs: <?= h(ifr_fmt_ms($row['exact_hobbs_duration_ms'] ?? null)) ?></div>
        <div>Landings: <?= h((string)($row['landing_event_count'] ?? '0')) ?></div>
      </article>
    <?php endforeach; ?>
    <?php if (!$records): ?>
      <article class="ifr-record ifr-muted">No Flight Records available yet.</article>
    <?php endif; ?>
  </section>
</div>
<?php cw_footer(); ?>
