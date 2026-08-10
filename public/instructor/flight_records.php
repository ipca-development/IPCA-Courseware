<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/FlightRecordViewService.php';
require_once __DIR__ . '/../../src/MasterLogbookLogbookProposalService.php';
require_once __DIR__ . '/../../src/CvrLogbookProposalAcceptService.php';

cw_require_login();
$user = cw_current_user($pdo) ?: array();
$role = strtolower(trim((string)($user['role'] ?? '')));
if (!in_array($role, array('admin', 'supervisor', 'instructor', 'chief_instructor'), true)) {
    redirect(cw_home_path_for_role($role));
}

$ownerUserId = (int)($user['id'] ?? 0);
$service = new FlightRecordViewService($pdo);
$cvrProposals = new MasterLogbookLogbookProposalService($pdo);
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'accept_cvr_proposal') {
    try {
        $entryId = (new CvrLogbookProposalAcceptService($pdo))->accept(
            (int)($_POST['proposal_id'] ?? 0),
            $ownerUserId,
            in_array($role, array('admin', 'supervisor', 'chief_instructor'), true)
        );
        $notice = 'Proposal accepted into your logbook (entry #' . $entryId . ').';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$records = $service->recordsForUser($user);
$proposals = $cvrProposals->listForOwner($ownerUserId);

function ifr_fmt_ms(mixed $ms): string
{
    return is_numeric($ms) ? number_format(((float)$ms) / 3600000, 1) . ' h' : '--';
}

function ifr_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

cw_header('Instructor Flight Records');
?>
<style>
.ifr-page{display:grid;gap:18px}.ifr-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.ifr-muted{color:#64748b;font-size:13px}.ifr-list{display:grid;gap:12px}.ifr-record{border:1px solid #e2e8f0;border-radius:12px;padding:14px;background:#f8fafc}.ifr-row{display:flex;justify-content:space-between;gap:12px;border-top:1px solid #e2e8f0;padding-top:8px;margin-top:8px;align-items:center}.ifr-badge{display:inline-flex;border-radius:999px;padding:3px 8px;font-size:12px;font-weight:700;background:#fef3c7;color:#92400e}.ifr-badge-accepted{background:#dcfce7;color:#166534}.ifr-button{border:0;border-radius:8px;background:#1d4ed8;color:#fff;font-weight:700;padding:7px 12px;cursor:pointer}.ifr-notice{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:12px}.ifr-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px}
</style>
<div class="ifr-page">
  <section class="ifr-card">
    <h2 style="margin-top:0">Instructor Flight Records</h2>
    <p class="ifr-muted">Completed Master Logbook legs that include you create proposed logbook entries. Accept them into <a href="/instructor/logbook.php">My Logbook</a>.</p>
    <?php if ($notice !== ''): ?><div class="ifr-notice"><?= ifr_h($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="ifr-error"><?= ifr_h($error) ?></div><?php endif; ?>
  </section>

  <section class="ifr-card">
    <h3 style="margin-top:0">Master Logbook proposals</h3>
    <div class="ifr-list">
      <?php foreach ($proposals as $row): ?>
        <?php
          $values = json_decode((string)($row['proposed_values_json'] ?? '{}'), true);
          $values = is_array($values) ? $values : array();
          $accepted = strtoupper((string)($row['status'] ?? '')) === 'ACCEPTED';
          $route = trim((string)($values['departure_airport'] ?? '')) . ' → ' . trim((string)($values['arrival_airport'] ?? ''));
        ?>
        <article class="ifr-record">
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start">
            <div>
              <strong><?= ifr_h((string)($row['aircraft_registration'] ?? ($values['aircraft_registration'] ?? 'Aircraft'))) ?></strong>
              <div class="ifr-muted"><?= ifr_h($route !== ' → ' ? $route : 'Route pending') ?> · <?= ifr_h((string)($values['entry_date'] ?? '')) ?></div>
            </div>
            <span class="ifr-badge <?= $accepted ? 'ifr-badge-accepted' : '' ?>"><?= ifr_h((string)($row['status'] ?? 'PROPOSED')) ?></span>
          </div>
          <div class="ifr-row"><span>Proposed duration</span><strong><?= ifr_h(ifr_fmt_ms($row['proposed_duration_ms'] ?? null)) ?></strong></div>
          <div class="ifr-row"><span>Role</span><span><?= ifr_h((string)($row['owner_role'] ?? '')) ?></span></div>
          <?php if (!$accepted): ?>
            <div class="ifr-row">
              <span class="ifr-muted">Accept to add this flight to My Logbook</span>
              <form method="post">
                <input type="hidden" name="action" value="accept_cvr_proposal">
                <input type="hidden" name="proposal_id" value="<?= (int)$row['id'] ?>">
                <button class="ifr-button" type="submit">Accept</button>
              </form>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
      <?php if ($proposals === array()): ?>
        <article class="ifr-record ifr-muted">No Master Logbook proposals yet.</article>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($records !== array()): ?>
  <section class="ifr-card">
    <h3 style="margin-top:0">Operational Flight Records</h3>
    <div class="ifr-list" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));display:grid">
      <?php foreach ($records as $row): ?>
        <article class="ifr-record">
          <strong><?= ifr_h((string)($row['aircraft_registration'] ?? 'Aircraft')) ?></strong>
          <div class="ifr-muted"><?= ifr_h((string)($row['avionics_on_utc'] ?? 'No start time')) ?></div>
          <p><span class="ifr-badge"><?= ifr_h((string)($row['readiness_status'] ?? $row['status'] ?? 'draft')) ?></span></p>
          <div>Hobbs: <?= ifr_h(ifr_fmt_ms($row['exact_hobbs_duration_ms'] ?? null)) ?></div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
</div>
<?php cw_footer(); ?>
