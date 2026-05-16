<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsCenterEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

/**
 * Compliance → Email drafts list.
 *
 * Lists every outbound draft (status filter: all / draft / sent / cancelled),
 * with quick actions to edit, send, or cancel from the list view.
 */

function cmpdr_flash(string $type, string $msg): void
{
    $_SESSION['_ipca_compliance_flash_drafts'] = array('type' => $type, 'message' => $msg);
}

function cmpdr_flash_take(): ?array
{
    if (empty($_SESSION['_ipca_compliance_flash_drafts']) || !is_array($_SESSION['_ipca_compliance_flash_drafts'])) {
        return null;
    }
    $f = $_SESSION['_ipca_compliance_flash_drafts'];
    unset($_SESSION['_ipca_compliance_flash_drafts']);

    return $f;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $draftId = (int)($_POST['draft_id'] ?? 0);
    try {
        if ($action === 'send_now' && $draftId > 0) {
            $draft = ComplianceCommsCenterEngine::getDraft($pdo, $draftId);
            if ($draft === null) {
                cmpdr_flash('error', 'Draft not found.');
                redirect('/admin/compliance/email_drafts.php');
            }
            if ((string)$draft['status'] !== 'draft') {
                cmpdr_flash('error', 'Only drafts in status=draft can be sent.');
                redirect('/admin/compliance/email_drafts.php');
            }
            $to = array();
            foreach (json_decode((string)($draft['to_json'] ?? '[]'), true) ?: array() as $r) {
                if (is_array($r) && !empty($r['Email'])) {
                    $to[] = (string)$r['Email'];
                }
            }
            $cc = array();
            foreach (json_decode((string)($draft['cc_json'] ?? '[]'), true) ?: array() as $r) {
                if (is_array($r) && !empty($r['Email'])) {
                    $cc[] = (string)$r['Email'];
                }
            }
            $bcc = array();
            foreach (json_decode((string)($draft['bcc_json'] ?? '[]'), true) ?: array() as $r) {
                if (is_array($r) && !empty($r['Email'])) {
                    $bcc[] = (string)$r['Email'];
                }
            }
            $result = ComplianceCommsCenterEngine::sendOutbound($pdo, array(
                'thread_id' => isset($draft['thread_id']) && (int)$draft['thread_id'] > 0 ? (int)$draft['thread_id'] : null,
                'to' => $to,
                'cc' => $cc,
                'bcc' => $bcc,
                'subject' => (string)($draft['subject'] ?? ''),
                'text_body' => (string)($draft['text_body'] ?? ''),
                'html_body' => (string)($draft['html_body'] ?? ''),
                'created_by' => $uid > 0 ? $uid : null,
                'draft_id' => $draftId,
            ));
            if (empty($result['ok'])) {
                cmpdr_flash('error', 'Send failed: ' . (string)($result['error'] ?? 'unknown error'));
                redirect('/admin/compliance/email_drafts.php');
            }
            cmpdr_flash('success', 'Sent. Postmark MessageID ' . (string)($result['postmark_message_id'] ?? '—'));
            redirect('/admin/compliance/email_thread.php?id=' . (int)($result['thread_id'] ?? 0));
        }
        if ($action === 'cancel' && $draftId > 0) {
            ComplianceCommsCenterEngine::cancelDraft($pdo, $draftId, $uid);
            cmpdr_flash('success', 'Draft cancelled.');
            redirect('/admin/compliance/email_drafts.php');
        }
        if ($action === 'delete' && $draftId > 0) {
            ComplianceCommsCenterEngine::deleteDraft($pdo, $draftId, $uid);
            if (isset($_SESSION['_ipca_compliance_cap_email_preview']['draft_id'])
                && (int)$_SESSION['_ipca_compliance_cap_email_preview']['draft_id'] === $draftId) {
                unset($_SESSION['_ipca_compliance_cap_email_preview']);
            }
            cmpdr_flash('success', 'Draft deleted.');
            redirect('/admin/compliance/email_drafts.php?status=draft');
        }
    } catch (Throwable $e) {
        cmpdr_flash('error', $e->getMessage());
        redirect('/admin/compliance/email_drafts.php');
    }
}

$filterStatus = isset($_GET['status']) ? (string)$_GET['status'] : '';
$filters = array();
if ($filterStatus !== '') {
    $filters['status'] = $filterStatus;
}
$drafts = ComplianceCommsCenterEngine::listDrafts($pdo, $filters, 200);
$flash = cmpdr_flash_take();

$attachmentCounts = array();
if ($drafts !== array()) {
    $draftIds = array_map(static fn($d) => (int)$d['id'], $drafts);
    $placeholders = implode(',', array_fill(0, count($draftIds), '?'));
    try {
        $st = $pdo->prepare(
            'SELECT draft_id, COUNT(*) AS n FROM ipca_compliance_email_draft_attachments
              WHERE draft_id IN (' . $placeholders . ') GROUP BY draft_id'
        );
        $st->execute($draftIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $attachmentCounts[(int)$row['draft_id']] = (int)$row['n'];
        }
    } catch (Throwable) {
        // Phase 8.5 migration not applied yet — silently fall back to no counts.
    }
}

$draftStatusCounts = array('draft' => 0, 'ready_to_send' => 0, 'sent' => 0, 'cancelled' => 0);
foreach (ComplianceCommsCenterEngine::listDrafts($pdo, array(), 500) as $d) {
    $k = (string)$d['status'];
    if (isset($draftStatusCounts[$k])) {
        $draftStatusCounts[$k]++;
    }
}

cw_header('Compliance · Drafts');

compliance_page_open(array(
    'overline' => 'Compliance · Comms Center',
    'title' => 'Outbound drafts',
    'description' => 'Compliance email drafts waiting to be sent (or already sent / cancelled). Hit Send now to dispatch via Postmark immediately.',
    'actions' => array(
        array('label' => 'New message', 'href' => '/admin/compliance/email_compose.php', 'icon' => 'plus'),
    ),
    'back' => array('href' => '/admin/compliance/inbox.php', 'label' => 'Inbox'),
    'stats' => array(
        array('label' => 'Drafts',         'value' => (int)$draftStatusCounts['draft'],         'href' => '/admin/compliance/email_drafts.php?status=draft'),
        array('label' => 'Ready to send',  'value' => (int)$draftStatusCounts['ready_to_send'], 'href' => '/admin/compliance/email_drafts.php?status=ready_to_send'),
        array('label' => 'Sent',           'value' => (int)$draftStatusCounts['sent'],          'href' => '/admin/compliance/email_drafts.php?status=sent', 'tone' => 'ok'),
        array('label' => 'Cancelled',      'value' => (int)$draftStatusCounts['cancelled'],     'href' => '/admin/compliance/email_drafts.php?status=cancelled'),
    ),
    'flash' => $flash,
));
?>
<style>
  .cmpdr-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;color:var(--text-muted);}
  .cmpdr-pill{display:inline-flex;align-items:center;padding:0 12px;height:28px;border-radius:999px;font-size:11.5px;font-weight:720;letter-spacing:.04em;border:1px solid rgba(15,23,42,0.08);background:#f3f6fb;color:#324155;}
  .cmpdr-pill.s-draft{background:rgba(196,118,11,0.10);color:#a66508;border-color:rgba(196,118,11,0.20);}
  .cmpdr-pill.s-ready_to_send{background:rgba(48,124,183,0.10);color:#246ea9;border-color:rgba(48,124,183,0.20);}
  .cmpdr-pill.s-sent{background:rgba(32,135,90,0.12);color:#1f7a54;border-color:rgba(32,135,90,0.20);}
  .cmpdr-pill.s-cancelled{background:rgba(86,112,153,0.10);color:#405a82;border-color:rgba(86,112,153,0.18);}
  .cmpdr-tabs{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;}
  .cmpdr-empty{padding:30px 26px;color:var(--text-muted);text-align:center;background:#f6f9fd;border-radius:14px;}
  .cmpdr-actions{display:flex;gap:6px;flex-wrap:wrap;}
  .cmpdr-actions form, .cmpdr-actions form button, .cmpdr-actions a{
    height:34px !important;min-height:34px !important;padding:0 12px !important;font-size:12px !important;
  }
</style>

<section class="cmp-card">
  <div class="cmpdr-tabs">
    <a class="cmpdr-tab <?= $filterStatus === '' ? 'is-on' : '' ?>" href="/admin/compliance/email_drafts.php">All</a>
    <?php foreach (array('draft','ready_to_send','sent','cancelled') as $s): ?>
      <a class="cmpdr-tab <?= $filterStatus === $s ? 'is-on' : '' ?>"
         href="/admin/compliance/email_drafts.php?status=<?= h($s) ?>">
        <?= h(str_replace('_', ' ', $s)) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($drafts === array()): ?>
    <div class="cmpdr-empty">
      No drafts in scope. <a href="/admin/compliance/email_compose.php" style="color:#1f4079;font-weight:700;">Compose a new message</a>.
    </div>
  <?php else: ?>
    <div class="compliance-table-wrap">
    <table class="compliance-table">
        <thead><tr>
          <th>Status</th>
          <th>Subject</th>
          <th>To</th>
          <th>Thread</th>
          <th>Att</th>
          <th>Updated</th>
          <th>Actions</th>
        </tr></thead>
        <tbody>
          <?php foreach ($drafts as $d): ?>
            <?php
              $did = (int)$d['id'];
              $status = (string)$d['status'];
              $to = json_decode((string)($d['to_json'] ?? '[]'), true) ?: array();
              $toEmails = array_map(static fn($r) => is_array($r) ? (string)($r['Email'] ?? '') : '', $to);
            ?>
            <tr>
              <td><span class="cmpdr-pill s-<?= h($status) ?>"><?= h(str_replace('_', ' ', $status)) ?></span></td>
              <td>
                <a href="/admin/compliance/email_compose.php?draft_id=<?= $did ?>" style="color:#1f4079;font-weight:700;text-decoration:none;">
                  <?= h((string)($d['subject'] ?? '(no subject)')) ?>
                </a>
              </td>
              <td class="cmpdr-mono"><?= h(implode(', ', array_filter($toEmails))) ?></td>
              <td>
                <?php if (!empty($d['thread_id'])): ?>
                  <a href="/admin/compliance/email_thread.php?id=<?= (int)$d['thread_id'] ?>" style="color:#1f4079;text-decoration:none;">
                    #<?= (int)$d['thread_id'] ?>
                    <?php if (!empty($d['thread_subject'])): ?>
                      · <span class="cmpdr-mono"><?= h(substr((string)$d['thread_subject'], 0, 40)) ?></span>
                    <?php endif; ?>
                  </a>
                <?php else: ?>
                  <span style="color:var(--text-muted);">—</span>
                <?php endif; ?>
              </td>
              <td><?= isset($attachmentCounts[$did]) ? (int)$attachmentCounts[$did] : 0 ?></td>
              <td class="cmpdr-mono"><?= h(substr((string)($d['updated_at'] ?? $d['created_at'] ?? ''), 0, 16)) ?></td>
              <td>
                <div class="cmpdr-actions">
                  <?php if ($status === 'draft'): ?>
                    <form method="post" action="/admin/compliance/email_drafts.php" style="display:inline;"
                          onsubmit="return confirm('Send this draft now?');">
                      <input type="hidden" name="action" value="send_now">
                      <input type="hidden" name="draft_id" value="<?= $did ?>">
                      <button type="submit">Send</button>
                    </form>
                    <a class="cmp-btn-secondary cmp-btn-link" href="/admin/compliance/email_compose.php?draft_id=<?= $did ?>" style="text-decoration:none;">Edit</a>
                    <form method="post" action="/admin/compliance/email_drafts.php" style="display:inline;"
                          onsubmit="return confirm('Cancel this draft?');">
                      <input type="hidden" name="action" value="cancel">
                      <input type="hidden" name="draft_id" value="<?= $did ?>">
                      <button type="submit" class="cmp-btn-danger">Cancel</button>
                    </form>
                    <form method="post" action="/admin/compliance/email_drafts.php" style="display:inline;"
                          onsubmit="return confirm('Delete this draft permanently?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="draft_id" value="<?= $did ?>">
                      <button type="submit" class="cmp-btn-danger">Delete</button>
                    </form>
                  <?php elseif ($status === 'sent' && !empty($d['sent_email_id'])): ?>
                    <a class="cmp-btn-secondary cmp-btn-link" href="/admin/compliance/email_thread.php?email_id=<?= (int)$d['sent_email_id'] ?>" style="text-decoration:none;">View sent</a>
                  <?php else: ?>
                    <a class="cmp-btn-secondary cmp-btn-link" href="/admin/compliance/email_compose.php?draft_id=<?= $did ?>" style="text-decoration:none;">View</a>
                    <form method="post" action="/admin/compliance/email_drafts.php" style="display:inline;"
                          onsubmit="return confirm('Delete this draft permanently?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="draft_id" value="<?= $did ?>">
                      <button type="submit" class="cmp-btn-danger">Delete</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
</section>

<?php
compliance_page_close();
cw_footer();
