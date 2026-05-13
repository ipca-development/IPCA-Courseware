<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsCenterEngine.php';

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

cw_header('Compliance · Drafts');
?>
<style>
  .cmpdr-h1{margin:0 0 6px;font-size:22px;color:#0f172a;}
  .cmpdr-sub{margin:0 0 18px;color:#64748b;font-size:14px;}
  .cmpdr-back{color:#1e3c72;font-weight:700;text-decoration:none;}
  .cmpdr-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px 22px;margin-bottom:20px;}
  .cmpdr-flash{margin:0 0 16px;padding:10px 14px;border-radius:10px;font-size:13px;}
  .cmpdr-flash.is-success{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;}
  .cmpdr-flash.is-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
  .cmpdr-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;}
  .cmpdr-tab{
    background:#e2e8f0;color:#0f172a;padding:6px 12px;border-radius:999px;
    text-decoration:none;font-size:12px;font-weight:700;
  }
  .cmpdr-tab.is-on{background:#1e3c72;color:#fff;}
  .cmpdr-table{width:100%;border-collapse:collapse;font-size:14px;}
  .cmpdr-table th{
    text-align:left;font-size:11px;color:#64748b;font-weight:800;letter-spacing:.05em;
    text-transform:uppercase;padding:8px;background:#f1f5f9;
  }
  .cmpdr-table td{padding:10px 8px;border-top:1px solid #e2e8f0;vertical-align:top;}
  .cmpdr-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;}
  .cmpdr-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.04em;}
  .cmpdr-pill.s-draft{background:#fef3c7;color:#92400e;}
  .cmpdr-pill.s-ready_to_send{background:#dbeafe;color:#1e3a8a;}
  .cmpdr-pill.s-sent{background:#d1fae5;color:#065f46;}
  .cmpdr-pill.s-cancelled{background:#e2e8f0;color:#475569;}
  .cmpdr-btn{
    display:inline-block;padding:5px 10px;border-radius:8px;font-size:12px;font-weight:700;
    text-decoration:none;border:0;cursor:pointer;
  }
  .cmpdr-btn.primary{background:#1e3c72;color:#fff;}
  .cmpdr-btn.secondary{background:#e2e8f0;color:#0f172a;}
  .cmpdr-btn.danger{background:#fee2e2;color:#991b1b;}
  .cmpdr-empty{padding:18px;color:#64748b;text-align:center;background:#f8fafc;border-radius:10px;}
  .cmpdr-actions{display:flex;gap:4px;flex-wrap:wrap;}
</style>

<p style="margin-bottom:12px;">
  <a class="cmpdr-back" href="/admin/compliance/inbox.php">← Inbox</a>
</p>

<section style="padding:8px 0 40px;max-width:1200px;">
  <h1 class="cmpdr-h1">Outbound drafts</h1>
  <p class="cmpdr-sub">
    Compliance email drafts waiting to be sent (or already sent / cancelled).
    Hit <strong>Send now</strong> to dispatch via Postmark immediately.
  </p>

  <?php if ($flash !== null): ?>
    <div class="cmpdr-flash is-<?= h((string)$flash['type']) ?>"><?= h((string)$flash['message']) ?></div>
  <?php endif; ?>

  <div class="cmpdr-tabs">
    <a class="cmpdr-tab <?= $filterStatus === '' ? 'is-on' : '' ?>" href="/admin/compliance/email_drafts.php">All</a>
    <?php foreach (array('draft','ready_to_send','sent','cancelled') as $s): ?>
      <a class="cmpdr-tab <?= $filterStatus === $s ? 'is-on' : '' ?>"
         href="/admin/compliance/email_drafts.php?status=<?= h($s) ?>">
        <?= h(str_replace('_', ' ', $s)) ?>
      </a>
    <?php endforeach; ?>
    <span style="flex:1;"></span>
    <a class="cmpdr-btn primary" href="/admin/compliance/email_compose.php">+ New message</a>
  </div>

  <section class="cmpdr-card">
    <?php if ($drafts === array()): ?>
      <div class="cmpdr-empty">
        No drafts in scope. <a href="/admin/compliance/email_compose.php" style="color:#1e3c72;font-weight:700;">Compose a new message</a>.
      </div>
    <?php else: ?>
      <table class="cmpdr-table">
        <thead><tr>
          <th>Status</th>
          <th>Subject</th>
          <th>To</th>
          <th>Thread</th>
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
                <a href="/admin/compliance/email_compose.php?draft_id=<?= $did ?>"
                   style="color:#1e3c72;font-weight:700;text-decoration:none;">
                  <?= h((string)($d['subject'] ?? '(no subject)')) ?>
                </a>
              </td>
              <td class="cmpdr-mono"><?= h(implode(', ', array_filter($toEmails))) ?></td>
              <td>
                <?php if (!empty($d['thread_id'])): ?>
                  <a href="/admin/compliance/email_thread.php?id=<?= (int)$d['thread_id'] ?>"
                     style="color:#1e3c72;text-decoration:none;">
                    #<?= (int)$d['thread_id'] ?>
                    <?php if (!empty($d['thread_subject'])): ?>
                      · <span style="color:#64748b;font-size:12px;"><?= h(substr((string)$d['thread_subject'], 0, 40)) ?></span>
                    <?php endif; ?>
                  </a>
                <?php else: ?>
                  <span style="color:#64748b;">—</span>
                <?php endif; ?>
              </td>
              <td class="cmpdr-mono"><?= h(substr((string)($d['updated_at'] ?? $d['created_at'] ?? ''), 0, 16)) ?></td>
              <td>
                <div class="cmpdr-actions">
                  <?php if ($status === 'draft'): ?>
                    <form method="post" action="/admin/compliance/email_drafts.php" style="display:inline;"
                          onsubmit="return confirm('Send this draft now?');">
                      <input type="hidden" name="action" value="send_now">
                      <input type="hidden" name="draft_id" value="<?= $did ?>">
                      <button type="submit" class="cmpdr-btn primary">Send</button>
                    </form>
                    <a class="cmpdr-btn secondary" href="/admin/compliance/email_compose.php?draft_id=<?= $did ?>">Edit</a>
                    <form method="post" action="/admin/compliance/email_drafts.php" style="display:inline;"
                          onsubmit="return confirm('Cancel this draft?');">
                      <input type="hidden" name="action" value="cancel">
                      <input type="hidden" name="draft_id" value="<?= $did ?>">
                      <button type="submit" class="cmpdr-btn danger">Cancel</button>
                    </form>
                  <?php elseif ($status === 'sent' && !empty($d['sent_email_id'])): ?>
                    <a class="cmpdr-btn secondary" href="/admin/compliance/email_thread.php?email_id=<?= (int)$d['sent_email_id'] ?>">View sent</a>
                  <?php else: ?>
                    <a class="cmpdr-btn secondary" href="/admin/compliance/email_compose.php?draft_id=<?= $did ?>">View</a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</section>

<?php
cw_footer();
