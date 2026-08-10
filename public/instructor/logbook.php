<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/flight_training/AdminLogbookService.php';

cw_require_login();
$user = cw_current_user($pdo) ?: array();
$role = strtolower(trim((string)($user['role'] ?? '')));
if (!in_array($role, array('admin', 'supervisor', 'instructor', 'chief_instructor'), true)) {
    redirect(cw_home_path_for_role($role));
}

$ownerUserId = (int)($user['id'] ?? 0);
$service = new AdminLogbookService($pdo);
$service->getOrCreateLogbook($ownerUserId, null, $ownerUserId);
$workspace = $service->loadStudentWorkspace($ownerUserId);
$entries = is_array($workspace['entries'] ?? null) ? $workspace['entries'] : array();
$totals = is_array($workspace['totals'] ?? null) ? $workspace['totals'] : array();

function il_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function il_num(mixed $value): string
{
    return number_format((float)$value, 1, '.', '');
}

$displayName = trim((string)($user['name'] ?? ''));
if ($displayName === '') {
    $displayName = 'Instructor';
}

cw_header('My Logbook');
?>
<style>
.il-page{display:grid;gap:18px}.il-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.il-muted{color:#64748b;font-size:13px}.il-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px}.il-metric{border:1px solid #e2e8f0;border-radius:12px;padding:12px;background:#f8fafc}.il-metric strong{display:block;font-size:20px;margin-top:4px}.il-table{width:100%;border-collapse:collapse}.il-table th,.il-table td{padding:8px;border-bottom:1px solid #e2e8f0;text-align:left;font-size:13px}.il-table th{color:#64748b;font-size:11px;text-transform:uppercase}
</style>
<div class="il-page">
  <section class="il-card">
    <h2 style="margin-top:0"><?= il_h($displayName) ?> · My Logbook</h2>
    <p class="il-muted">Official entries accepted from Master Logbook proposals. Review and accept new flights on <a href="/instructor/flight_records.php">Flight Records</a>.</p>
  </section>
  <section class="il-card">
    <div class="il-grid">
      <div class="il-metric"><span class="il-muted">Total time</span><strong><?= il_h(il_num($totals['total_flight_time'] ?? 0)) ?> h</strong></div>
      <div class="il-metric"><span class="il-muted">PIC</span><strong><?= il_h(il_num($totals['pic_time'] ?? 0)) ?> h</strong></div>
      <div class="il-metric"><span class="il-muted">Entries</span><strong><?= (int)count($entries) ?></strong></div>
    </div>
  </section>
  <section class="il-card">
    <h3 style="margin-top:0">Accepted flights</h3>
    <?php if ($entries === array()): ?>
      <p class="il-muted">No accepted logbook entries yet.</p>
    <?php else: ?>
      <div style="overflow-x:auto">
        <table class="il-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Aircraft</th>
              <th>Route</th>
              <th>Total</th>
              <th>PIC</th>
              <th>Remarks</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($entries as $entry): ?>
            <tr>
              <td><?= il_h((string)($entry['entry_date'] ?? '')) ?></td>
              <td><?= il_h((string)($entry['aircraft_registration'] ?? '')) ?></td>
              <td><?= il_h(trim((string)($entry['departure_airport'] ?? '')) . ' → ' . trim((string)($entry['arrival_airport'] ?? ''))) ?></td>
              <td><?= il_h(il_num($entry['total_flight_time'] ?? 0)) ?></td>
              <td><?= il_h(il_num($entry['pic_time'] ?? 0)) ?></td>
              <td><?= il_h((string)($entry['remarks'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php cw_footer(); ?>
