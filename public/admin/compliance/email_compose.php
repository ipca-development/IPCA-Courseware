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

        if ($action === 'remove_attachment') {
            $attId = isset($_POST['attachment_id']) ? (int)$_POST['attachment_id'] : 0;
            if ($postDraftId > 0 && $attId > 0) {
                ComplianceCommsCenterEngine::removeDraftAttachment($pdo, $attId);
                cmpose_flash('success', 'Attachment removed.');
            }
            redirect('/admin/compliance/email_compose.php?draft_id=' . $postDraftId);
        }

        if ($postDraftId > 0) {
            ComplianceCommsCenterEngine::updateDraft($pdo, $postDraftId, $opts);
            $effectiveDraftId = $postDraftId;
        } else {
            $effectiveDraftId = ComplianceCommsCenterEngine::createDraft($pdo, $opts);
        }

        // Process any uploaded attachments before we send/save.
        $attachmentsAddedNotes = array();
        if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
            $count = count($_FILES['attachments']['name']);
            for ($i = 0; $i < $count; $i++) {
                $err = (int)$_FILES['attachments']['error'][$i];
                if ($err === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $upload = array(
                    'name' => (string)$_FILES['attachments']['name'][$i],
                    'type' => (string)($_FILES['attachments']['type'][$i] ?? ''),
                    'tmp_name' => (string)$_FILES['attachments']['tmp_name'][$i],
                    'size' => (int)($_FILES['attachments']['size'][$i] ?? 0),
                    'error' => $err,
                );
                try {
                    ComplianceCommsCenterEngine::attachToDraft(
                        $pdo,
                        $effectiveDraftId,
                        $upload,
                        $uid > 0 ? $uid : null
                    );
                    $attachmentsAddedNotes[] = (string)$upload['name'];
                } catch (Throwable $eAtt) {
                    cmpose_flash('error', 'Attachment "' . (string)$upload['name'] . '" rejected: ' . $eAtt->getMessage());
                    redirect('/admin/compliance/email_compose.php?draft_id=' . $effectiveDraftId);
                }
            }
        }

        if ($action === 'save_draft') {
            $msg = 'Draft saved.';
            if ($attachmentsAddedNotes !== array()) {
                $msg .= ' Added attachment(s): ' . implode(', ', $attachmentsAddedNotes) . '.';
            }
            cmpose_flash('success', $msg);
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

  .cmpec-dropzone-wrap{position:relative;}
  .cmpec-dropzone-label{
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    gap:4px;padding:26px 16px;border:2px dashed #cbd5e1;border-radius:12px;
    background:#f8fafc;color:#475569;cursor:pointer;text-align:center;
    transition:background .12s ease,border-color .12s ease,color .12s ease;
  }
  .cmpec-dropzone-label:hover{background:#f1f5f9;border-color:#94a3b8;}
  .cmpec-dropzone-wrap.is-drag .cmpec-dropzone-label{
    background:#eef2ff;border-color:#1e3c72;color:#1e3c72;
  }
  .cmpec-dropzone-icon{font-size:22px;line-height:1;color:#94a3b8;margin-bottom:4px;}
  .cmpec-dropzone-wrap.is-drag .cmpec-dropzone-icon{color:#1e3c72;}
  .cmpec-dropzone-title{font-weight:700;font-size:14px;color:#0f172a;margin:0;}
  .cmpec-dropzone-sub{font-size:12px;color:#64748b;margin:0;}
  .cmpec-dropzone-sub .cmpec-dropzone-link{color:#1e3c72;font-weight:700;text-decoration:underline;}
  .cmpec-dropzone-hint{font-size:11px;color:#94a3b8;margin:8px 0 0;line-height:1.4;}
  .cmpec-fileinput-hidden{
    position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;
    clip:rect(0,0,0,0);white-space:nowrap;border:0;
  }
  .cmpec-pending{list-style:none;padding:0;margin:10px 0 0;display:flex;flex-direction:column;gap:6px;}
  .cmpec-pending[hidden]{display:none;}
  .cmpec-pending-item{
    display:flex;align-items:center;gap:10px;background:#ffffff;border:1px solid #c7d2fe;
    border-radius:10px;padding:8px 12px;font-size:13px;
  }
  .cmpec-pending-name{color:#0f172a;font-weight:700;}
  .cmpec-pending-meta{color:#64748b;font-size:12px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;}
  .cmpec-pending-spacer{flex:1;}
  .cmpec-pending-remove{
    padding:4px 10px;font-size:11px;border:0;border-radius:8px;cursor:pointer;
    background:#fee2e2;color:#991b1b;font-weight:800;
  }
  .cmpec-pending-remove:hover{background:#fecaca;}
  .cmpec-existing-h{
    margin:18px 0 6px;font-size:12px;color:#475569;letter-spacing:.04em;
    text-transform:uppercase;font-weight:800;
  }
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

  <form method="post" action="/admin/compliance/email_compose.php" enctype="multipart/form-data">
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

    <div class="cmpec-row" style="margin-top:14px;">
      <label>Attachments</label>
      <div>
        <div class="cmpec-dropzone-wrap" id="attDropzone">
          <label class="cmpec-dropzone-label" for="attachments">
            <div class="cmpec-dropzone-icon" aria-hidden="true">⤓</div>
            <p class="cmpec-dropzone-title">Drop files here</p>
            <p class="cmpec-dropzone-sub">or <span class="cmpec-dropzone-link">click to browse</span></p>
            <p class="cmpec-dropzone-hint">
              Each file ≤ 50 MiB. Executable extensions (.exe, .msi, .bat, …) are rejected.
              Existing draft attachments survive Save / Send.
            </p>
          </label>
          <input class="cmpec-fileinput-hidden" type="file" id="attachments" name="attachments[]" multiple>
          <ul class="cmpec-pending" id="attPending" hidden></ul>
        </div>

        <?php
          $currentAttachments = $draftId > 0
              ? ComplianceCommsCenterEngine::listDraftAttachments($pdo, $draftId)
              : array();
        ?>
        <?php if ($currentAttachments !== array()): ?>
          <p class="cmpec-existing-h">Already attached to this draft</p>
          <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:6px;">
            <?php foreach ($currentAttachments as $att):
              $isCdn = (string)$att['storage_disk'] === 'spaces' && !empty($att['public_url']);
            ?>
              <li style="display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:8px 12px;font-size:13px;">
                <?php if ($isCdn): ?>
                  <a href="<?= h((string)$att['public_url']) ?>" target="_blank" rel="noopener" style="color:#1e3c72;font-weight:700;text-decoration:none;">
                    <?= h((string)$att['original_filename']) ?>
                  </a>
                <?php else: ?>
                  <strong><?= h((string)$att['original_filename']) ?></strong>
                <?php endif; ?>
                <span style="color:#64748b;font-size:12px;font-family:ui-monospace,monospace;">
                  · <?= h((string)$att['storage_disk']) ?> · <?= (int)$att['size_bytes'] ?> bytes
                  <?php if (!empty($att['sha256'])): ?>
                    · <?= h(substr((string)$att['sha256'], 0, 12)) ?>…
                  <?php endif; ?>
                </span>
                <span style="flex:1;"></span>
                <button type="submit" name="action" value="remove_attachment" formnovalidate
                        class="cmpec-btn danger"
                        style="padding:4px 10px;font-size:11px;"
                        onclick="document.getElementById('attRemoveId').value=<?= (int)$att['id'] ?>;return confirm('Remove this attachment from the draft?');">
                  Remove
                </button>
              </li>
            <?php endforeach; ?>
          </ul>
          <input type="hidden" id="attRemoveId" name="attachment_id" value="0">
        <?php endif; ?>
      </div>
    </div>

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

<script>
(function () {
  var wrap = document.getElementById('attDropzone');
  var input = document.getElementById('attachments');
  var pending = document.getElementById('attPending');
  if (!wrap || !input || !pending) {
    return;
  }
  if (typeof window.DataTransfer === 'undefined') {
    // Old browser — keep the native input visible so users can still attach files.
    input.classList.remove('cmpec-fileinput-hidden');
    return;
  }

  // Authoritative in-memory list. We mirror it into input.files via DataTransfer
  // on every change so the existing server-side parser sees the same files.
  var staged = [];
  var syncing = false;

  function fmtBytes(n) {
    if (n < 1024) { return n + ' B'; }
    if (n < 1024 * 1024) { return Math.round(n / 1024) + ' KB'; }
    return (Math.round((n / 1024 / 1024) * 10) / 10) + ' MB';
  }

  function fileKey(f) {
    return f.name + '\u0000' + f.size + '\u0000' + (f.lastModified || 0);
  }

  function syncInputFiles() {
    var dt = new DataTransfer();
    staged.forEach(function (f) { dt.items.add(f); });
    syncing = true;
    input.files = dt.files;
    syncing = false;
  }

  function render() {
    while (pending.firstChild) { pending.removeChild(pending.firstChild); }
    if (staged.length === 0) {
      pending.hidden = true;
      return;
    }
    pending.hidden = false;
    staged.forEach(function (f, idx) {
      var li = document.createElement('li');
      li.className = 'cmpec-pending-item';

      var name = document.createElement('span');
      name.className = 'cmpec-pending-name';
      name.textContent = f.name;
      li.appendChild(name);

      var meta = document.createElement('span');
      meta.className = 'cmpec-pending-meta';
      meta.textContent = '· ' + fmtBytes(f.size);
      li.appendChild(meta);

      var spacer = document.createElement('span');
      spacer.className = 'cmpec-pending-spacer';
      li.appendChild(spacer);

      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'cmpec-pending-remove';
      remove.textContent = 'Remove';
      remove.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        staged.splice(idx, 1);
        syncInputFiles();
        render();
      });
      li.appendChild(remove);

      pending.appendChild(li);
    });
  }

  function addFiles(fileList) {
    if (!fileList || fileList.length === 0) { return; }
    var seen = {};
    staged.forEach(function (f) { seen[fileKey(f)] = true; });
    Array.prototype.forEach.call(fileList, function (f) {
      var k = fileKey(f);
      if (!seen[k]) {
        staged.push(f);
        seen[k] = true;
      }
    });
    syncInputFiles();
    render();
  }

  // Native file picker → append rather than replace.
  input.addEventListener('change', function () {
    if (syncing) { return; }
    addFiles(input.files);
  });

  // Drag highlight uses an enter/leave counter so child elements don't flicker.
  var dragDepth = 0;
  wrap.addEventListener('dragenter', function (ev) {
    if (!ev.dataTransfer || !ev.dataTransfer.types) { return; }
    var types = ev.dataTransfer.types;
    var hasFiles = false;
    for (var i = 0; i < types.length; i++) {
      if (types[i] === 'Files') { hasFiles = true; break; }
    }
    if (!hasFiles) { return; }
    ev.preventDefault();
    dragDepth++;
    wrap.classList.add('is-drag');
  });
  wrap.addEventListener('dragover', function (ev) {
    if (!ev.dataTransfer || !ev.dataTransfer.types) { return; }
    var types = ev.dataTransfer.types;
    var hasFiles = false;
    for (var i = 0; i < types.length; i++) {
      if (types[i] === 'Files') { hasFiles = true; break; }
    }
    if (!hasFiles) { return; }
    ev.preventDefault();
    ev.dataTransfer.dropEffect = 'copy';
  });
  wrap.addEventListener('dragleave', function (ev) {
    ev.preventDefault();
    dragDepth = Math.max(0, dragDepth - 1);
    if (dragDepth === 0) { wrap.classList.remove('is-drag'); }
  });
  wrap.addEventListener('drop', function (ev) {
    if (!ev.dataTransfer) { return; }
    ev.preventDefault();
    dragDepth = 0;
    wrap.classList.remove('is-drag');
    if (ev.dataTransfer.files && ev.dataTransfer.files.length > 0) {
      addFiles(ev.dataTransfer.files);
    }
  });

  // Prevent the browser from navigating away if a file is dropped just outside
  // the dropzone (e.g. on the form margin).
  function blockOutside(ev) {
    if (wrap.contains(ev.target)) { return; }
    if (!ev.dataTransfer || !ev.dataTransfer.types) { return; }
    var types = ev.dataTransfer.types;
    for (var i = 0; i < types.length; i++) {
      if (types[i] === 'Files') { ev.preventDefault(); return; }
    }
  }
  window.addEventListener('dragover', blockOutside);
  window.addEventListener('drop', blockOutside);
})();
</script>

<?php
cw_footer();
