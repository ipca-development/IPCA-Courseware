<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/MasterLogbookLogbookProposalService.php';
require_once __DIR__ . '/../../src/CvrLogbookProposalAcceptService.php';

cw_require_admin();

$user = cw_current_user($pdo) ?: array();
$actorUserId = (int)($user['id'] ?? 0);
$notice = '';
$error = '';
$service = new MasterLogbookLogbookProposalService($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'accept_cvr_proposal') {
    try {
        $entryId = (new CvrLogbookProposalAcceptService($pdo))->accept(
            (int)($_POST['proposal_id'] ?? 0),
            $actorUserId,
            true
        );
        $notice = 'Proposal accepted into official logbook entry #' . $entryId . '.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$proposals = array();
try {
    $proposals = $service->listRecent(200);
} catch (Throwable $e) {
    $error = $error !== '' ? $error : $e->getMessage();
}

function clp_fmt_ms(mixed $ms): string
{
    return is_numeric($ms) ? number_format(((float)$ms) / 3600000, 1) . ' h' : '--';
}

function clp_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

cw_header('CVR Logbook Proposals');
?>
<style>
.clp-page{display:grid;gap:18px}.clp-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.clp-muted{color:#64748b;font-size:13px}.clp-table-wrap{overflow-x:auto}.clp-table{width:100%;border-collapse:collapse;min-width:960px}.clp-table th,.clp-table td{border-bottom:1px solid #e2e8f0;padding:10px 8px;text-align:left;vertical-align:top}.clp-table th{color:#475569;font-size:12px;text-transform:uppercase;letter-spacing:.04em}.clp-badge{display:inline-flex;border-radius:999px;padding:3px 8px;font-size:12px;font-weight:700;background:#fef3c7;color:#92400e}.clp-badge-accepted{background:#dcfce7;color:#166534}.clp-button{border:0;border-radius:8px;background:#1d4ed8;color:#fff;font-weight:700;padding:7px 10px;cursor:pointer}.clp-notice{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:12px}.clp-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px}
</style>
<div class="clp-page">
  <section class="clp-card">
    <h2 style="margin-top:0">CVR / Master Logbook Proposals</h2>
    <p class="clp-muted">Auto-created when a CVR Check-In / closure is ingested. Pilots normally accept their own proposals; admins can override here. Phase 1 OFR proposals remain on <a href="/admin/flight_record_logbook_proposals.php">Flight Record Logbook Proposals</a>.</p>
    <?php if (!$service->schemaAvailable()): ?>
      <p class="clp-badge">Apply scripts/sql/2026_08_08_cvr_master_logbook_proposals.sql</p>
    <?php endif; ?>
  </section>
  <?php if ($notice !== ''): ?><div class="clp-notice"><?= clp_h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="clp-error"><?= clp_h($error) ?></div><?php endif; ?>
  <section class="clp-card clp-table-wrap">
    <table class="clp-table">
      <thead>
        <tr>
          <th>Owner</th>
          <th>Flight</th>
          <th>Duration</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($proposals as $row): ?>
        <?php
          $accepted = strtoupper((string)($row['status'] ?? '')) === 'ACCEPTED';
          $values = json_decode((string)($row['proposed_values_json'] ?? '{}'), true);
          $values = is_array($values) ? $values : array();
          $route = trim((string)($values['departure_airport'] ?? '')) . ' → ' . trim((string)($values['arrival_airport'] ?? ''));
        ?>
        <tr>
          <td>
            <?= clp_h((string)($row['owner_name'] ?? ('User #' . (string)($row['owner_user_id'] ?? '')))) ?>
            <br><span class="clp-muted"><?= clp_h((string)($row['owner_role'] ?? '')) ?> · <?= clp_h((string)($row['entry_type'] ?? '')) ?></span>
          </td>
          <td>
            <?= clp_h((string)($row['aircraft_registration'] ?? '')) ?>
            <br><span class="clp-muted"><?= clp_h($route !== ' → ' ? $route : '') ?> · <?= clp_h((string)($values['entry_date'] ?? '')) ?></span>
          </td>
          <td><?= clp_h(clp_fmt_ms($row['proposed_duration_ms'] ?? null)) ?></td>
          <td><span class="clp-badge <?= $accepted ? 'clp-badge-accepted' : '' ?>"><?= clp_h((string)($row['status'] ?? 'PROPOSED')) ?></span></td>
          <td>
            <?php if (!$accepted): ?>
              <form method="post">
                <input type="hidden" name="action" value="accept_cvr_proposal">
                <input type="hidden" name="proposal_id" value="<?= (int)$row['id'] ?>">
                <button class="clp-button" type="submit">Accept for owner</button>
              </form>
            <?php else: ?>
              <span class="clp-muted">Entry #<?= (int)($row['target_entry_id'] ?? 0) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($proposals === array()): ?>
        <tr><td colspan="5" class="clp-muted">No CVR Master Logbook proposals yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </section>
</div>
<?php cw_footer(); ?>
