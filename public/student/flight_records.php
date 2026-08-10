<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/FlightRecordViewService.php';
require_once __DIR__ . '/../../src/MasterLogbookLogbookProposalService.php';
require_once __DIR__ . '/../../src/CvrLogbookProposalAcceptService.php';

cw_require_student();

$user = cw_current_user($pdo) ?: array();
$studentUserId = cw_student_view_user_id($pdo, $user);
$viewUser = array_merge($user, array('id' => $studentUserId, 'role' => 'student'));
$service = new FlightRecordViewService($pdo);
$cvrProposals = new MasterLogbookLogbookProposalService($pdo);
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'accept_cvr_proposal') {
    try {
        $entryId = (new CvrLogbookProposalAcceptService($pdo))->accept(
            (int)($_POST['proposal_id'] ?? 0),
            $studentUserId,
            false
        );
        $notice = 'Proposal accepted into your logbook (entry #' . $entryId . ').';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$records = $service->recordsForUser($viewUser);
$proposals = $cvrProposals->listForOwner($studentUserId);

function sfr_fmt_ms(mixed $ms): string
{
    return is_numeric($ms) ? number_format(((float)$ms) / 3600000, 1) . ' h' : '--';
}

function sfr_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

cw_header('My Flight Records');
?>
<style>
.sfr-page{display:grid;gap:18px}.sfr-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.sfr-muted{color:#64748b;font-size:13px}.sfr-list{display:grid;gap:12px}.sfr-record{border:1px solid #e2e8f0;border-radius:12px;padding:14px;background:#f8fafc}.sfr-row{display:flex;justify-content:space-between;gap:12px;border-top:1px solid #e2e8f0;padding-top:8px;margin-top:8px;align-items:center}.sfr-badge{display:inline-flex;border-radius:999px;padding:3px 8px;font-size:12px;font-weight:700;background:#fef3c7;color:#92400e}.sfr-badge-accepted{background:#dcfce7;color:#166534}.sfr-button{border:0;border-radius:8px;background:#1d4ed8;color:#fff;font-weight:700;padding:7px 12px;cursor:pointer}.sfr-notice{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:12px}.sfr-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px}
</style>
<div class="sfr-page">
  <section class="sfr-card">
    <h2 style="margin-top:0">My Flight Records</h2>
    <p class="sfr-muted">Master Logbook legs create proposed entries here. Accept a proposal to add it to your official Student Pilot Logbook.</p>
    <?php if ($notice !== ''): ?><div class="sfr-notice"><?= sfr_h($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="sfr-error"><?= sfr_h($error) ?></div><?php endif; ?>
  </section>

  <section class="sfr-card">
    <h3 style="margin-top:0">Master Logbook proposals</h3>
    <div class="sfr-list">
      <?php foreach ($proposals as $row): ?>
        <?php
          $values = json_decode((string)($row['proposed_values_json'] ?? '{}'), true);
          $values = is_array($values) ? $values : array();
          $accepted = strtoupper((string)($row['status'] ?? '')) === 'ACCEPTED';
          $route = trim((string)($values['departure_airport'] ?? '')) . ' → ' . trim((string)($values['arrival_airport'] ?? ''));
        ?>
        <article class="sfr-record">
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start">
            <div>
              <strong><?= sfr_h((string)($row['aircraft_registration'] ?? ($values['aircraft_registration'] ?? 'Aircraft'))) ?></strong>
              <div class="sfr-muted"><?= sfr_h($route !== ' → ' ? $route : 'Route pending') ?> · <?= sfr_h((string)($values['entry_date'] ?? '')) ?></div>
            </div>
            <span class="sfr-badge <?= $accepted ? 'sfr-badge-accepted' : '' ?>"><?= sfr_h((string)($row['status'] ?? 'PROPOSED')) ?></span>
          </div>
          <div class="sfr-row"><span>Proposed duration</span><strong><?= sfr_h(sfr_fmt_ms($row['proposed_duration_ms'] ?? null)) ?></strong></div>
          <div class="sfr-row"><span>Mission</span><span><?= sfr_h((string)($row['mission_code'] ?? '')) ?></span></div>
          <?php if (!$accepted): ?>
            <div class="sfr-row">
              <span class="sfr-muted">Accept to add this flight to My Logbook</span>
              <form method="post">
                <input type="hidden" name="action" value="accept_cvr_proposal">
                <input type="hidden" name="proposal_id" value="<?= (int)$row['id'] ?>">
                <button class="sfr-button" type="submit">Accept</button>
              </form>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
      <?php if ($proposals === array()): ?>
        <article class="sfr-record sfr-muted">No Master Logbook proposals yet. Completed CVR legs with you in the crew will appear here.</article>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($records !== array()): ?>
  <section class="sfr-card">
    <h3 style="margin-top:0">Legacy Flight Record proposals</h3>
    <div class="sfr-list">
      <?php foreach ($records as $row): ?>
        <article class="sfr-record">
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start">
            <div>
              <strong><?= sfr_h((string)($row['aircraft_registration'] ?? 'Aircraft')) ?></strong>
              <div class="sfr-muted"><?= sfr_h((string)($row['avionics_on_utc'] ?? 'No start time')) ?></div>
            </div>
            <?php $accepted = ((string)($row['proposal_status'] ?? '')) === 'ACCEPTED'; ?>
            <span class="sfr-badge <?= $accepted ? 'sfr-badge-accepted' : '' ?>"><?= sfr_h((string)($row['proposal_status'] ?? 'PROPOSED')) ?></span>
          </div>
          <div class="sfr-row"><span>Proposed duration</span><strong><?= sfr_h(sfr_fmt_ms($row['proposed_duration_ms'] ?? $row['exact_hobbs_duration_ms'] ?? null)) ?></strong></div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
</div>
<?php cw_footer(); ?>
