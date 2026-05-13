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

require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

if ($snap === null) {
    compliance_page_open(array(
        'overline' => 'Compliance · Audit checklist',
        'title' => 'Snapshot not found',
        'back' => array('href' => '/admin/compliance/audits.php', 'label' => 'Audits'),
        'flash' => $flash,
    ));
    echo '<section class="cmp-card"><p style="margin:0;">No row for that snapshot id.</p></section>';
    compliance_page_close();
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

compliance_page_open(array(
    'overline' => 'Compliance · Audit checklist',
    'title' => 'Checklist snapshot',
    'description' => (string)$snap['template_code'] . ' — ' . (string)$snap['template_title'] . ' · v' . (int)$snap['version_no'] . ' · ' . count($items) . ' items' . ($locked ? ' · Locked' : ''),
    'back' => array(
        'href' => '/admin/compliance/audits.php?id=' . (int)$auditId,
        'label' => 'Back to audit',
        'code' => (string)$snap['audit_code'],
    ),
    'stats' => array(
        array('label' => 'Answered',       'value' => $answered . ' / ' . $totalQuestions),
        array('label' => 'Non-compliant',  'value' => (int)$nonCompliant, 'tone' => $nonCompliant > 0 ? 'crit' : 'ok'),
        array('label' => 'Status',         'value' => (string)$snap['status'], 'tone' => $locked ? 'warn' : 'info'),
    ),
    'flash' => $flash,
));
?>

<section class="cmp-card">
  <h2 style="margin:0 0 6px;">Snapshot status</h2>
  <p style="margin:0 0 12px;color:var(--text-muted);font-size:14px;">
    <span class="cmp-mono"><?= h((string)$snap['template_code']) ?></span>
    — <?= h((string)$snap['template_title']) ?> · v<?= (int)$snap['version_no'] ?> · <?= count($items) ?> items
    <?php if ($locked): ?><span class="cmp-pill cmp-pill-warn" style="margin-left:6px;">Locked</span><?php endif; ?>
  </p>
  <?php if (!$locked): ?>
    <form method="post" class="cmp-toolbar" style="margin:0;">
      <input type="hidden" name="action" value="set_status">
      <input type="hidden" name="snapshot_id" value="<?= (int)$snapshotId ?>">
      <label class="cmp-field" style="min-width:200px;">
        <span class="cmp-field-label">Snapshot status</span>
        <select name="status">
          <?php foreach ($statuses as $s): ?>
            <option value="<?= $s ?>" <?= ((string)$snap['status'] === $s) ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit">Update</button>
    </form>
  <?php endif; ?>
</section>

<section class="cmp-card" style="overflow:hidden;">
  <div class="cmp-list-head" style="margin-bottom:14px;">
    <div class="cmp-list-title"><?= compliance_ui_icon('check') ?><span>Checklist items</span></div>
    <div class="cmp-count-pill"><?= count($items) ?></div>
  </div>
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Prompt</th>
        <th>State</th>
        <th>Answer</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $idx => $it):
          $type = strtoupper((string)($it['item_type'] ?? 'QUESTION'));
          $isSection = $type === 'SECTION';
          $id = (int)$it['id'];
          $a = $answersByItem[$id] ?? array();
          ?>
        <tr<?= $isSection ? ' style="background:rgba(48,124,183,0.06);"' : '' ?>>
          <td class="cmp-mono" style="vertical-align:top;">
            <?= h((string)($it['item_code'] ?? ('I' . $idx))) ?>
          </td>
          <td style="vertical-align:top;">
            <div style="font-weight:<?= $isSection ? '720' : '600' ?>;color:<?= $isSection ? '#1f4079' : '#0f172a' ?>;">
              <?= nl2br(h((string)($it['prompt'] ?? ''))) ?>
            </div>
            <?php if (!empty($it['guidance'])): ?>
              <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                <?= nl2br(h((string)$it['guidance'])) ?>
              </div>
            <?php endif; ?>
          </td>
          <?php if ($isSection): ?>
            <td colspan="2" style="color:var(--text-muted);font-size:12px;vertical-align:top;">Section header</td>
          <?php else: ?>
            <?php
            $state = (string)($a['compliance_state'] ?? '');
            $stateClass = $state === 'NON_COMPLIANT' ? 'cmp-pill-crit' : ($state === 'COMPLIANT' ? 'cmp-pill-ok' : ($state === 'OBSERVATION' || $state === 'PARTIAL' ? 'cmp-pill-warn' : ''));
            ?>
            <td style="vertical-align:top;width:220px;">
              <?php if ($locked): ?>
                <span class="cmp-pill <?= h($stateClass) ?>"><?= h((string)($states[$state] ?? '—')) ?></span>
              <?php else: ?>
                <form method="post" style="display:flex;flex-direction:column;gap:6px;">
                  <input type="hidden" name="action" value="save_answer">
                  <input type="hidden" name="snapshot_id" value="<?= (int)$snapshotId ?>">
                  <input type="hidden" name="item_id" value="<?= $id ?>">
                  <select name="compliance_state">
                    <?php foreach ($states as $k => $lab): ?>
                      <option value="<?= h($k) ?>" <?= $state === $k ? 'selected' : '' ?>><?= h($lab) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input name="answer_value" placeholder="Short answer" value="<?= h((string)($a['answer_value'] ?? '')) ?>">
                  <textarea name="answer_text" rows="2" placeholder="Notes / evidence"><?= h((string)($a['answer_text'] ?? '')) ?></textarea>
                  <button type="submit" style="height:32px;min-height:32px;padding:0 14px;font-size:12px;">Save</button>
                </form>
              <?php endif; ?>
            </td>
            <td style="vertical-align:top;color:#324155;font-size:13px;">
              <?php if ($locked): ?>
                <div><?= h((string)($a['answer_value'] ?? '—')) ?></div>
                <div style="margin-top:4px;color:var(--text-muted);"><?= nl2br(h((string)($a['answer_text'] ?? ''))) ?></div>
              <?php elseif (!empty($a['answered_at'])): ?>
                <div style="font-size:11px;color:var(--text-muted);">last saved <?= h((string)$a['answered_at']) ?></div>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

<?php
compliance_page_close();
cw_footer();
