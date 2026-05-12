<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceChecklistEngine.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

function ack_flash(string $type, string $msg): void
{
    $_SESSION['_ipca_compliance_flash_ack'] = array('type' => $type, 'message' => $msg);
}

function ack_flash_take(): ?array
{
    if (empty($_SESSION['_ipca_compliance_flash_ack']) || !is_array($_SESSION['_ipca_compliance_flash_ack'])) {
        return null;
    }
    $f = $_SESSION['_ipca_compliance_flash_ack'];
    unset($_SESSION['_ipca_compliance_flash_ack']);

    return $f;
}

$snapshotId = isset($_GET['snapshot_id']) ? (int)$_GET['snapshot_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $snapshotId = (int)($_POST['snapshot_id'] ?? $snapshotId);
    try {
        if ($action === 'save_answer') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            ComplianceChecklistEngine::upsertAnswer($pdo, $snapshotId, $itemId, array(
                'answer_value' => (string)($_POST['answer_value'] ?? ''),
                'answer_text' => (string)($_POST['answer_text'] ?? ''),
                'compliance_state' => (string)($_POST['compliance_state'] ?? ''),
            ), $uid);
            ack_flash('success', 'Answer saved.');
        }
        if ($action === 'set_status') {
            ComplianceChecklistEngine::setSnapshotStatus(
                $pdo,
                $snapshotId,
                (string)($_POST['status'] ?? 'OPEN'),
                $uid
            );
            ack_flash('success', 'Snapshot status updated.');
        }
        redirect('/admin/compliance/audit_checklist.php?snapshot_id=' . $snapshotId);
    } catch (Throwable $e) {
        ack_flash('error', $e->getMessage());
        redirect('/admin/compliance/audit_checklist.php?snapshot_id=' . $snapshotId);
    }
}

$flash = ack_flash_take();
$snap = $snapshotId > 0 ? ComplianceChecklistEngine::getSnapshot($pdo, $snapshotId) : null;

cw_header('Compliance · Audit checklist');

if ($flash !== null) {
    $cls = ($flash['type'] === 'success') ? 'is-ok' : ($flash['type'] === 'warn' ? 'is-warn' : 'is-danger');
    echo '<div class="queue-status ' . h($cls) . '" style="margin:0 0 16px;padding:12px 16px;border-radius:12px;">'
        . h((string)$flash['message']) . '</div>';
}

if ($snap === null) {
    echo '<p>Snapshot not found.</p>';
    echo '<p><a href="/admin/compliance/audits.php" style="color:#1e3c72;font-weight:700;">← Audits</a></p>';
    cw_footer();

    exit;
}

$items = ComplianceChecklistEngine::decodeSnapshotItems($snap['items_snapshot_json'] ?? null);
$answersRows = ComplianceChecklistEngine::listAnswers($pdo, $snapshotId);
$answersByItem = array();
foreach ($answersRows as $a) {
    $answersByItem[(int)$a['item_id']] = $a;
}
$locked = !empty($snap['locked_at']) || strtoupper((string)$snap['status']) === 'COMPLETED';

$states = array(
    '' => '—',
    'COMPLIANT' => 'Compliant',
    'NON_COMPLIANT' => 'Non-compliant',
    'OBSERVATION' => 'Observation',
    'PARTIAL' => 'Partial',
    'N_A' => 'N/A',
    'PENDING' => 'Pending',
);
$statuses = array('OPEN', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED');

$auditId = (int)$snap['audit_id'];
$answered = 0;
$nonCompliant = 0;
foreach ($answersByItem as $a) {
    if (!empty($a['compliance_state']) && (string)$a['compliance_state'] !== 'PENDING') {
        $answered++;
    }
    if ((string)$a['compliance_state'] === 'NON_COMPLIANT') {
        $nonCompliant++;
    }
}
$totalQuestions = 0;
foreach ($items as $it) {
    if (strtoupper((string)($it['item_type'] ?? '')) !== 'SECTION') {
        $totalQuestions++;
    }
}
?>
<p style="margin:0 0 16px;">
  <a href="/admin/compliance/audits.php?id=<?= $auditId ?>" style="color:#1e3c72;font-weight:700;">← Back to audit</a>
  <span style="color:#64748b;margin:0 8px;">|</span>
  <code style="font-family:ui-monospace,monospace;font-size:12px;"><?= h((string)$snap['audit_code']) ?></code>
</p>

<section style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 28px;margin-bottom:24px;max-width:960px;">
  <h2 style="margin:0 0 6px;font-size:20px;">Checklist snapshot</h2>
  <p style="margin:0 0 12px;color:#64748b;font-size:14px;">
    <code style="font-family:ui-monospace,monospace;font-size:12px;"><?= h((string)$snap['template_code']) ?></code>
    — <?= h((string)$snap['template_title']) ?>
    · v<?= (int)$snap['version_no'] ?>
    · <?= count($items) ?> items
    <?php if ($locked): ?>
      <span class="queue-status is-warn" style="margin-left:6px;padding:2px 8px;border-radius:8px;">Locked</span>
    <?php endif; ?>
  </p>
  <p style="margin:0 0 12px;color:#475569;font-size:13px;">
    <strong><?= $answered ?></strong> of <strong><?= $totalQuestions ?></strong> answered
    <?php if ($nonCompliant > 0): ?>
      · <span style="color:#b91c1c;font-weight:700;"><?= $nonCompliant ?> non-compliant</span>
    <?php endif; ?>
  </p>
  <?php if (!$locked): ?>
    <form method="post" style="display:inline-flex;gap:10px;align-items:flex-end;">
      <input type="hidden" name="action" value="set_status">
      <input type="hidden" name="snapshot_id" value="<?= (int)$snapshotId ?>">
      <label>
        <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Snapshot status</span>
        <select name="status" style="padding:8px;border-radius:8px;">
          <?php foreach ($statuses as $s): ?>
            <option value="<?= $s ?>" <?= ((string)$snap['status'] === $s) ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" style="background:#0f766e;color:#fff;border:0;padding:10px 16px;border-radius:10px;font-weight:700;cursor:pointer;">
        Update
      </button>
    </form>
  <?php endif; ?>
</section>

<section style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;max-width:1200px;">
  <table style="width:100%;border-collapse:collapse;font-size:14px;">
    <thead>
      <tr style="background:#f1f5f9;text-align:left;">
        <th style="padding:12px 14px;">#</th>
        <th style="padding:12px 14px;">Prompt</th>
        <th style="padding:12px 14px;">State</th>
        <th style="padding:12px 14px;">Answer</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $idx => $it):
          $type = strtoupper((string)($it['item_type'] ?? 'QUESTION'));
          $isSection = $type === 'SECTION';
          $id = (int)$it['id'];
          $a = $answersByItem[$id] ?? array();
          $rowBg = $isSection ? '#eef2ff' : '#fff';
          ?>
        <tr style="border-top:1px solid #e2e8f0;background:<?= $rowBg ?>;">
          <td style="padding:12px 14px;font-family:ui-monospace,monospace;font-size:12px;vertical-align:top;">
            <?= h((string)($it['item_code'] ?? ('I' . $idx))) ?>
          </td>
          <td style="padding:12px 14px;vertical-align:top;">
            <div style="font-weight:<?= $isSection ? '800' : '600' ?>;color:<?= $isSection ? '#3730a3' : '#0f172a' ?>;">
              <?= nl2br(h((string)($it['prompt'] ?? ''))) ?>
            </div>
            <?php if (!empty($it['guidance'])): ?>
              <div style="font-size:12px;color:#64748b;margin-top:4px;">
                <?= nl2br(h((string)$it['guidance'])) ?>
              </div>
            <?php endif; ?>
          </td>
          <?php if ($isSection): ?>
            <td colspan="2" style="padding:12px 14px;color:#64748b;font-size:12px;vertical-align:top;">Section header</td>
          <?php else: ?>
            <?php $state = (string)($a['compliance_state'] ?? ''); ?>
            <td style="padding:12px 14px;vertical-align:top;width:200px;">
              <?php if ($locked): ?>
                <?= h((string)$states[$state] ?? '—') ?>
              <?php else: ?>
                <form method="post" style="display:flex;flex-direction:column;gap:6px;">
                  <input type="hidden" name="action" value="save_answer">
                  <input type="hidden" name="snapshot_id" value="<?= (int)$snapshotId ?>">
                  <input type="hidden" name="item_id" value="<?= $id ?>">
                  <select name="compliance_state" style="padding:6px;border-radius:6px;font-size:13px;">
                    <?php foreach ($states as $k => $lab): ?>
                      <option value="<?= h($k) ?>" <?= $state === $k ? 'selected' : '' ?>><?= h($lab) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input name="answer_value" placeholder="Short answer" value="<?= h((string)($a['answer_value'] ?? '')) ?>"
                    style="padding:6px;border-radius:6px;border:1px solid #cbd5e1;font-size:13px;">
                  <textarea name="answer_text" rows="2" placeholder="Notes / evidence"
                    style="padding:6px;border-radius:6px;border:1px solid #cbd5e1;font-size:13px;"><?= h((string)($a['answer_text'] ?? '')) ?></textarea>
                  <button type="submit" style="background:#1e3c72;color:#fff;border:0;padding:6px 10px;border-radius:6px;font-weight:700;cursor:pointer;font-size:12px;">
                    Save
                  </button>
                </form>
              <?php endif; ?>
            </td>
            <td style="padding:12px 14px;vertical-align:top;color:#475569;font-size:13px;">
              <?php if ($locked): ?>
                <div><?= h((string)($a['answer_value'] ?? '—')) ?></div>
                <div style="margin-top:4px;color:#64748b;"><?= nl2br(h((string)($a['answer_text'] ?? ''))) ?></div>
              <?php elseif (!empty($a['answered_at'])): ?>
                <div style="font-size:11px;color:#64748b;">last saved <?= h((string)$a['answered_at']) ?></div>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

<?php cw_footer();
