<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/FlightRecordViewService.php';

cw_require_student();

$user = cw_current_user($pdo) ?: array();
$studentUserId = cw_student_view_user_id($pdo, $user);
$viewUser = array_merge($user, array('id' => $studentUserId, 'role' => 'student'));
$service = new FlightRecordViewService($pdo);
$records = $service->recordsForUser($viewUser);

function sfr_fmt_ms(mixed $ms): string
{
    return is_numeric($ms) ? number_format(((float)$ms) / 3600000, 1) . ' h' : '--';
}

cw_header('My Flight Records');
?>
<style>
.sfr-page{display:grid;gap:18px}.sfr-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.sfr-muted{color:#64748b;font-size:13px}.sfr-list{display:grid;gap:12px}.sfr-record{border:1px solid #e2e8f0;border-radius:12px;padding:14px;background:#f8fafc}.sfr-row{display:flex;justify-content:space-between;gap:12px;border-top:1px solid #e2e8f0;padding-top:8px;margin-top:8px}.sfr-badge{display:inline-flex;border-radius:999px;padding:3px 8px;font-size:12px;font-weight:700;background:#fef3c7;color:#92400e}.sfr-badge-accepted{background:#dcfce7;color:#166534}
</style>
<div class="sfr-page">
  <section class="sfr-card">
    <h2 style="margin-top:0">My Flight Records</h2>
    <p class="sfr-muted">Flight Records shown here are proposals until accepted into your official Student Pilot Logbook.</p>
    <?php if (!$service->isReady()): ?>
      <p class="sfr-badge">Phase 1 database foundation has not been applied yet.</p>
    <?php endif; ?>
  </section>
  <section class="sfr-list">
    <?php foreach ($records as $row): ?>
      <article class="sfr-record">
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start">
          <div>
            <strong><?= h((string)($row['aircraft_registration'] ?? 'Aircraft')) ?></strong>
            <div class="sfr-muted"><?= h((string)($row['avionics_on_utc'] ?? 'No start time')) ?></div>
          </div>
          <?php $accepted = ((string)($row['proposal_status'] ?? '')) === 'ACCEPTED'; ?>
          <span class="sfr-badge <?= $accepted ? 'sfr-badge-accepted' : '' ?>"><?= h((string)($row['proposal_status'] ?? 'PROPOSED')) ?></span>
        </div>
        <div class="sfr-row"><span>Proposed duration</span><strong><?= h(sfr_fmt_ms($row['proposed_duration_ms'] ?? $row['exact_hobbs_duration_ms'] ?? null)) ?></strong></div>
        <div class="sfr-row"><span>Flight Record</span><span><?= h((string)($row['flight_record_uuid'] ?? '')) ?></span></div>
      </article>
    <?php endforeach; ?>
    <?php if (!$records): ?>
      <article class="sfr-record sfr-muted">No Flight Record proposals available yet.</article>
    <?php endif; ?>
  </section>
</div>
<?php cw_footer(); ?>
