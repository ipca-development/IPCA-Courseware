<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/FlightRecordLogbookIntegrationService.php';

cw_require_admin();

$user = cw_current_user($pdo) ?: array();
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $proposalId = (int)($_POST['proposal_id'] ?? 0);
        $entryId = (new FlightRecordLogbookIntegrationService($pdo))->acceptProposalToOfficialLogbook($proposalId, (int)($user['id'] ?? 0));
        $notice = 'Proposal accepted into official logbook entry #' . $entryId . '.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$proposals = array();
try {
    $stmt = $pdo->query("
        SELECT
            p.id,
            p.proposal_uuid,
            p.owner_user_id,
            p.status,
            p.proposed_duration_ms,
            p.target_entry_id,
            p.created_at,
            u.name AS owner_name,
            s.aircraft_registration,
            s.session_uuid,
            lv.departure_airport_code,
            lv.arrival_airport_code
        FROM ipca_flight_record_logbook_proposals p
        INNER JOIN ipca_logbook_proposal_groups g ON g.id = p.proposal_group_id
        INNER JOIN ipca_flight_sessions s ON s.id = g.session_id
        LEFT JOIN ipca_operational_flight_leg_versions lv ON lv.id = p.leg_version_id
        LEFT JOIN users u ON u.id = p.owner_user_id
        ORDER BY p.created_at DESC
        LIMIT 200
    ");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
    $proposals = is_array($rows) ? $rows : array();
} catch (Throwable $e) {
    $error = $error !== '' ? $error : 'Phase 1 proposal tables are not available yet.';
}

function flp_fmt_ms(mixed $ms): string
{
    return is_numeric($ms) ? number_format(((float)$ms) / 3600000, 1) . ' h' : '--';
}

cw_header('Flight Record Logbook Proposals');
?>
<style>
.flp-page{display:grid;gap:18px}.flp-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.flp-muted{color:#64748b;font-size:13px}.flp-table-wrap{overflow-x:auto}.flp-table{width:100%;border-collapse:collapse;min-width:920px}.flp-table th,.flp-table td{border-bottom:1px solid #e2e8f0;padding:10px 8px;text-align:left;vertical-align:top}.flp-table th{color:#475569;font-size:12px;text-transform:uppercase;letter-spacing:.04em}.flp-badge{display:inline-flex;border-radius:999px;padding:3px 8px;font-size:12px;font-weight:700;background:#fef3c7;color:#92400e}.flp-badge-accepted{background:#dcfce7;color:#166534}.flp-button{border:0;border-radius:8px;background:#1d4ed8;color:#fff;font-weight:700;padding:7px 10px;cursor:pointer}.flp-notice{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:12px}.flp-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px}
</style>
<div class="flp-page">
  <section class="flp-card">
    <h2 style="margin-top:0">Flight Record Logbook Proposals</h2>
    <p class="flp-muted">Accepting a proposal creates an official Student Pilot Logbook entry through the existing Admin logbook service, then links it back to the exact Flight Record version and leg.</p>
  </section>
  <?php if ($notice !== ''): ?><div class="flp-notice"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="flp-error"><?= h($error) ?></div><?php endif; ?>
  <section class="flp-card flp-table-wrap">
    <table class="flp-table">
      <thead><tr><th>Student</th><th>Flight</th><th>Duration</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($proposals as $row): ?>
        <?php $accepted = (string)($row['status'] ?? '') === 'ACCEPTED'; ?>
        <tr>
          <td><?= h((string)($row['owner_name'] ?? ('User #' . (string)($row['owner_user_id'] ?? '')))) ?><br><span class="flp-muted"><?= h((string)$row['proposal_uuid']) ?></span></td>
          <td><?= h((string)($row['aircraft_registration'] ?? '')) ?><br><span class="flp-muted"><?= h((string)($row['departure_airport_code'] ?? '')) ?> → <?= h((string)($row['arrival_airport_code'] ?? '')) ?></span></td>
          <td><?= h(flp_fmt_ms($row['proposed_duration_ms'] ?? null)) ?></td>
          <td><span class="flp-badge <?= $accepted ? 'flp-badge-accepted' : '' ?>"><?= h((string)($row['status'] ?? 'PROPOSED')) ?></span></td>
          <td>
            <?php if (!$accepted): ?>
              <form method="post">
                <input type="hidden" name="proposal_id" value="<?= h((string)$row['id']) ?>">
                <button class="flp-button" type="submit">Accept</button>
              </form>
            <?php else: ?>
              <span class="flp-muted">Entry #<?= h((string)($row['target_entry_id'] ?? '')) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$proposals): ?>
        <tr><td colspan="5" class="flp-muted">No Flight Record logbook proposals available yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </section>
</div>
<?php cw_footer(); ?>
