<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/CompliancePostmarkConfig.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsCenterEngine.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

/**
 * Compliance → Compose outbound email.
 *
 * Two modes:
 *   1. New email          (no query string)
 *   2. Edit existing draft (?draft_id=N)
 *
 * Actions (POST):
 *   - save_draft  → upsert draft, redirect to drafts list
 *   - send_now    → upsert draft, immediately invoke sendOutbound(),
 *                   redirect to the resulting thread
 *   - cancel      → mark draft cancelled (only when editing an existing one)
 *
 * Outbound from-address, server token and stream all come from getenv()
 * via CompliancePostmarkConfig.
 */

function cmpose_flash(string $type, string $msg): void
{
    $_SESSION['_ipca_compliance_flash_email'] = array('type' => $type, 'message' => $msg);
}

function cmpose_flash_take(): ?array
{
    if (empty($_SESSION['_ipca_compliance_flash_email']) || !is_array($_SESSION['_ipca_compliance_flash_email'])) {
        return null;
    }
    $f = $_SESSION['_ipca_compliance_flash_email'];
    unset($_SESSION['_ipca_compliance_flash_email']);

    return $f;
}

$draftId = isset($_GET['draft_id']) ? (int)$_GET['draft_id'] : 0;
$threadIdQs = isset($_GET['thread_id']) ? (int)$_GET['thread_id'] : 0;
$replyToEmailId = isset($_GET['reply_to_email_id']) ? (int)$_GET['reply_to_email_id'] : 0;

$prefill = array(
    'to' => '',
    'cc' => '',
    'bcc' => '',
    'subject' => '',
    'text_body' => '',
    'html_body' => '',
    'thread_id' => $threadIdQs > 0 ? $threadIdQs : null,
);

if ($draftId > 0) {
    $existing = ComplianceCommsCenterEngine::getDraft($pdo, $draftId);
    if ($existing === null) {
        cmpose_flash('error', 'Draft not found.');
        redirect('/admin/compliance/email_drafts.php');
    }
    $prefill['to'] = implode(', ', array_map(static fn($r) => is_array($r) ? (string)($r['Email'] ?? '') : '', json_decode((string)($existing['to_json'] ?? '[]'), true) ?: array()));
    $cc = json_decode((string)($existing['cc_json'] ?? '[]'), true) ?: array();
    $bcc = json_decode((string)($existing['bcc_json'] ?? '[]'), true) ?: array();
    $prefill['cc'] = implode(', ', array_map(static fn($r) => is_array($r) ? (string)($r['Email'] ?? '') : '', $cc));
    $prefill['bcc'] = implode(', ', array_map(static fn($r) => is_array($r) ? (string)($r['Email'] ?? '') : '', $bcc));
    $prefill['subject'] = (string)($existing['subject'] ?? '');
    $prefill['text_body'] = (string)($existing['text_body'] ?? '');
    $prefill['html_body'] = (string)($existing['html_body'] ?? '');
    $prefill['thread_id'] = isset($existing['thread_id']) && (int)$existing['thread_id'] > 0 ? (int)$existing['thread_id'] : null;
} elseif ($replyToEmailId > 0) {
    // Pre-fill from the email we're replying to.
    $st = $pdo->prepare(
        'SELECT id, thread_id, from_email, subject, text_body
           FROM ipca_compliance_emails WHERE id = ? LIMIT 1'
    );
    $st->execute(array($replyToEmailId));
    $src = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($src)) {
        $prefill['to'] = (string)($src['from_email'] ?? '');
        $prefill['thread_id'] = isset($src['thread_id']) && (int)$src['thread_id'] > 0 ? (int)$src['thread_id'] : null;
        $rawSubject = trim((string)($src['subject'] ?? ''));
        if ($rawSubject !== '') {
            $norm = ComplianceCommsCenterEngine::normalizeSubject($rawSubject);
            $prefill['subject'] = 'Re: ' . $norm;
        }
        $quoted = trim((string)($src['text_body'] ?? ''));
        if ($quoted !== '') {
            $quoted = preg_replace('/^/m', '> ', $quoted) ?? $quoted;
            $prefill['text_body'] = "\n\n--- On " . date('Y-m-d') . ", " . (string)($src['from_email'] ?? '') . " wrote: ---\n" . $quoted;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $postDraftId = isset($_POST['draft_id']) ? (int)$_POST['draft_id'] : 0;
    $postReplyToEmailId = isset($_POST['reply_to_email_id']) ? (int)$_POST['reply_to_email_id'] : 0;
    $postThreadId = isset($_POST['thread_id']) ? (int)$_POST['thread_id'] : 0;
    $opts = array(
        'to' => (string)($_POST['to'] ?? ''),
        'cc' => (string)($_POST['cc'] ?? ''),
        'bcc' => (string)($_POST['bcc'] ?? ''),
        'subject' => (string)($_POST['subject'] ?? ''),
        'text_body' => (string)($_POST['text_body'] ?? ''),
        'html_body' => (string)($_POST['html_body'] ?? ''),
        'thread_id' => $postThreadId > 0 ? $postThreadId : null,
        'created_by' => $uid > 0 ? $uid : null,
    );
    try {
        if ($action === 'cancel') {
            if ($postDraftId > 0) {
                ComplianceCommsCenterEngine::cancelDraft($pdo, $postDraftId, $uid);
                cmpose_flash('success', 'Draft cancelled.');
            }
            redirect('/admin/compliance/email_drafts.php');
        }

        if ($postDraftId > 0) {
            ComplianceCommsCenterEngine::updateDraft($pdo, $postDraftId, $opts);
            $effectiveDraftId = $postDraftId;
        } else {
            $effectiveDraftId = ComplianceCommsCenterEngine::createDraft($pdo, $opts);
        }

        if ($action === 'save_draft') {
            cmpose_flash('success', 'Draft saved.');
            redirect('/admin/compliance/email_compose.php?draft_id=' . $effectiveDraftId);
        }

        if ($action === 'send_now') {
            $sendOpts = $opts;
            $sendOpts['draft_id'] = $effectiveDraftId;
            if ($postReplyToEmailId > 0) {
                $sendOpts['reply_to_email_id'] = $postReplyToEmailId;
            }
            $result = ComplianceCommsCenterEngine::sendOutbound($pdo, $sendOpts);
            if (empty($result['ok'])) {
                cmpose_flash('error', 'Send failed: ' . (string)($result['error'] ?? 'unknown error'));
                redirect('/admin/compliance/email_compose.php?draft_id=' . $effectiveDraftId);
            }
            cmpose_flash('success', 'Sent. Postmark MessageID ' . (string)($result['postmark_message_id'] ?? '—'));
            redirect('/admin/compliance/email_thread.php?id=' . (int)($result['thread_id'] ?? 0));
        }
    } catch (Throwable $e) {
        cmpose_flash('error', $e->getMessage());
        $back = $postDraftId > 0
            ? '/admin/compliance/email_compose.php?draft_id=' . $postDraftId
            : '/admin/compliance/email_compose.php';
        redirect($back);
    }
}

$summary = CompliancePostmarkConfig::publicSummary();
$flash = cmpose_flash_take();
$mode = $draftId > 0 ? 'Edit draft' : ($replyToEmailId > 0 ? 'Reply' : 'New message');

cw_header('Compliance · Compose');
?>
<style>
  .cmpec-h1{margin:0 0 6px;font-size:22px;color:#0f172a;}
  .cmpec-sub{margin:0 0 18px;color:#64748b;font-size:14px;}
  .cmpec-back{color:#1e3c72;font-weight:700;text-decoration:none;}
  .cmpec-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px 22px;margin-bottom:20px;}
  .cmpec-row{display:grid;grid-template-columns:120px 1fr;gap:10px;align-items:start;margin-bottom:12px;}
  .cmpec-row label{font-size:12px;font-weight:700;color:#475569;padding-top:9px;}
  .cmpec-input,.cmpec-textarea{
    width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:8px;
    box-sizing:border-box;font-size:14px;font-family:inherit;
  }
  .cmpec-textarea{min-height:180px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;}
  .cmpec-textarea.is-html{min-height:140px;}
  .cmpec-frombox{
    background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;
    padding:8px 12px;font-size:13px;color:#334155;
  }
  .cmpec-flash{margin:0 0 16px;padding:10px 14px;border-radius:10px;font-size:13px;}
  .cmpec-flash.is-success{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;}
  .cmpec-flash.is-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
  .cmpec-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;}
  .cmpec-btn{
    padding:10px 18px;border:0;border-radius:8px;font-weight:800;cursor:pointer;
    font-size:14px;
  }
  .cmpec-btn.primary{background:#1e3c72;color:#fff;}
  .cmpec-btn.secondary{background:#e2e8f0;color:#0f172a;}
  .cmpec-btn.danger{background:#fee2e2;color:#991b1b;}
  .cmpec-note{font-size:12px;color:#64748b;margin-top:6px;}
  .cmpec-warn{
    margin:0 0 16px;padding:10px 14px;background:#fef3c7;color:#92400e;
    border:1px solid #fde68a;border-radius:10px;font-size:13px;
  }
  details.cmpec-html{margin-top:10px;}
  details.cmpec-html summary{cursor:pointer;font-weight:700;color:#3730a3;font-size:13px;}
</style>

<p style="margin-bottom:12px;">
  <a class="cmpec-back" href="/admin/compliance/inbox.php">← Inbox</a>
  <span style="color:#64748b;margin:0 6px;">|</span>
  <a class="cmpec-back" href="/admin/compliance/email_drafts.php">Drafts</a>
</p>

<section class="cmpec-card">
  <h1 class="cmpec-h1">Compose · <?= h($mode) ?></h1>
  <p class="cmpec-sub">
    Outbound compliance email — routed via Postmark stream
    <code><?= h((string)$summary['outbound_stream']) ?></code>. Delivery and bounce
    events will land in this thread automatically.
  </p>

  <?php if ($flash !== null): ?>
    <div class="cmpec-flash is-<?= h((string)$flash['type']) ?>"><?= h((string)$flash['message']) ?></div>
  <?php endif; ?>

  <?php if (empty($summary['server_token_set']) || (string)$summary['from_address'] === ''): ?>
    <div class="cmpec-warn">
      Outbound is not fully configured yet —
      <?= empty($summary['server_token_set']) ? 'POSTMARK_SERVER_TOKEN is missing' : '' ?>
      <?= (string)$summary['from_address'] === '' ? ' COMPLIANCE_INBOX_ADDRESS / COMPLIANCE_POSTMARK_FROM is missing.' : '' ?>
      Save as draft for now; sending will fail until the host env vars are in place.
    </div>
  <?php endif; ?>

  <form method="post" action="/admin/compliance/email_compose.php">
    <input type="hidden" name="draft_id" value="<?= (int)$draftId ?>">
    <input type="hidden" name="thread_id" value="<?= (int)($prefill['thread_id'] ?? 0) ?>">
    <input type="hidden" name="reply_to_email_id" value="<?= (int)$replyToEmailId ?>">

    <div class="cmpec-row">
      <label>From</label>
      <div class="cmpec-frombox">
        <?= h((string)$summary['from_address']) ?: '<em style="color:#b91c1c;">not configured</em>' ?>
        <span style="color:#64748b;font-size:12px;">· stream <?= h((string)$summary['outbound_stream']) ?></span>
      </div>
    </div>

    <div class="cmpec-row">
      <label for="to">To <span style="color:#b91c1c;">*</span></label>
      <div>
        <input class="cmpec-input" type="text" id="to" name="to"
               required value="<?= h($prefill['to']) ?>"
               placeholder="authority.contact@example.org, another@example.org">
        <div class="cmpec-note">Comma-separated. Each recipient is validated before send.</div>
      </div>
    </div>

    <div class="cmpec-row">
      <label for="cc">Cc</label>
      <input class="cmpec-input" type="text" id="cc" name="cc"
             value="<?= h($prefill['cc']) ?>" placeholder="optional, comma-separated">
    </div>

    <div class="cmpec-row">
      <label for="bcc">Bcc</label>
      <input class="cmpec-input" type="text" id="bcc" name="bcc"
             value="<?= h($prefill['bcc']) ?>" placeholder="optional, comma-separated">
    </div>

    <div class="cmpec-row">
      <label for="subject">Subject <span style="color:#b91c1c;">*</span></label>
      <input class="cmpec-input" type="text" id="subject" name="subject" maxlength="500"
             required value="<?= h($prefill['subject']) ?>">
    </div>

    <div class="cmpec-row">
      <label for="text_body">Message</label>
      <div>
        <textarea class="cmpec-textarea" id="text_body" name="text_body"
                  placeholder="Plain text body. Use HTML below only if you really need formatting."><?= h($prefill['text_body']) ?></textarea>
        <div class="cmpec-note">Plain text is the safest choice for authority correspondence.</div>
      </div>
    </div>

    <details class="cmpec-html" <?= $prefill['html_body'] !== '' ? 'open' : '' ?>>
      <summary>Optional HTML body</summary>
      <div class="cmpec-row" style="margin-top:10px;">
        <label for="html_body">HTML body</label>
        <div>
          <textarea class="cmpec-textarea is-html" id="html_body" name="html_body"
                    placeholder="<p>Optional HTML body.</p>"><?= h($prefill['html_body']) ?></textarea>
          <div class="cmpec-note">When present, both text and HTML are sent. Open/click tracking is disabled.</div>
        </div>
      </div>
    </details>

    <div class="cmpec-actions">
      <button class="cmpec-btn primary" type="submit" name="action" value="send_now"
              onclick="return confirm('Send this email now? It will be delivered to the recipients immediately.');">
        Send now
      </button>
      <button class="cmpec-btn secondary" type="submit" name="action" value="save_draft">
        Save draft
      </button>
      <?php if ($draftId > 0): ?>
        <button class="cmpec-btn danger" type="submit" name="action" value="cancel"
                onclick="return confirm('Cancel this draft? You can still see it filtered as Cancelled in the drafts list.');">
          Cancel draft
        </button>
      <?php endif; ?>
      <a class="cmpec-btn secondary" href="/admin/compliance/email_drafts.php" style="text-decoration:none;display:inline-block;line-height:1.2;">
        Back to drafts
      </a>
    </div>
  </form>
</section>

<?php
cw_footer();
