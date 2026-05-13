<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsCenterEngine.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

/**
 * Compliance → Email thread (Stage 2).
 *
 * Accepts either ?id=<thread_id> or ?email_id=<email_id>. Renders every
 * inbound + outbound email in the thread with attachments + Postmark
 * events. POST actions on this page:
 *   - link_object   — attach an email or the whole thread to a compliance object
 *   - unlink_object — remove an existing object link
 *   - set_status    — change thread.status (open / waiting_internal / etc.)
 *   - set_priority  — change thread.priority
 *
 * Outbound composition is handled by /admin/compliance/email_compose.php;
 * each inbound message offers a "Reply" link that pre-fills that page.
 */

function cmpth_flash(string $type, string $msg): void
{
    $_SESSION['_ipca_compliance_flash_thread'] = array('type' => $type, 'message' => $msg);
}

function cmpth_flash_take(): ?array
{
    if (empty($_SESSION['_ipca_compliance_flash_thread']) || !is_array($_SESSION['_ipca_compliance_flash_thread'])) {
        return null;
    }
    $f = $_SESSION['_ipca_compliance_flash_thread'];
    unset($_SESSION['_ipca_compliance_flash_thread']);

    return $f;
}

$threadId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$emailIdParam = isset($_GET['email_id']) ? (int)$_GET['email_id'] : 0;

if ($threadId <= 0 && $emailIdParam > 0) {
    $st = $pdo->prepare('SELECT thread_id FROM ipca_compliance_emails WHERE id = ? LIMIT 1');
    $st->execute(array($emailIdParam));
    $threadId = (int)$st->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $postThreadId = isset($_POST['thread_id']) ? (int)$_POST['thread_id'] : $threadId;
    try {
        if ($action === 'link_object' && $postThreadId > 0) {
            ComplianceCommsCenterEngine::linkObject($pdo, array(
                'thread_id' => $postThreadId,
                'email_id' => isset($_POST['email_id']) && (int)$_POST['email_id'] > 0 ? (int)$_POST['email_id'] : null,
                'linked_object_type' => (string)($_POST['linked_object_type'] ?? ''),
                'linked_object_id' => (string)($_POST['linked_object_id'] ?? ''),
                'link_type' => (string)($_POST['link_type'] ?? 'context'),
                'created_by' => $uid > 0 ? $uid : null,
            ));
            cmpth_flash('success', 'Link added.');
            redirect('/admin/compliance/email_thread.php?id=' . $postThreadId);
        }
        if ($action === 'unlink_object') {
            $linkId = (int)($_POST['link_id'] ?? 0);
            if ($linkId > 0) {
                ComplianceCommsCenterEngine::unlinkObject($pdo, $linkId);
                cmpth_flash('success', 'Link removed.');
            }
            redirect('/admin/compliance/email_thread.php?id=' . $postThreadId);
        }
        if ($action === 'set_status' && $postThreadId > 0) {
            $newStatus = (string)($_POST['status'] ?? '');
            if (in_array($newStatus, array('open','waiting_internal','waiting_external','closed','archived'), true)) {
                $pdo->prepare('UPDATE ipca_compliance_email_threads SET status = ? WHERE id = ?')
                    ->execute(array($newStatus, $postThreadId));
                cmpth_flash('success', 'Status set to ' . str_replace('_', ' ', $newStatus) . '.');
            } else {
                cmpth_flash('error', 'Unknown status.');
            }
            redirect('/admin/compliance/email_thread.php?id=' . $postThreadId);
        }
        if ($action === 'set_priority' && $postThreadId > 0) {
            $newPrio = (string)($_POST['priority'] ?? '');
            if (in_array($newPrio, array('low','normal','high','urgent'), true)) {
                $pdo->prepare('UPDATE ipca_compliance_email_threads SET priority = ? WHERE id = ?')
                    ->execute(array($newPrio, $postThreadId));
                cmpth_flash('success', 'Priority set to ' . $newPrio . '.');
            } else {
                cmpth_flash('error', 'Unknown priority.');
            }
            redirect('/admin/compliance/email_thread.php?id=' . $postThreadId);
        }
    } catch (Throwable $e) {
        cmpth_flash('error', $e->getMessage());
        redirect('/admin/compliance/email_thread.php?id=' . $postThreadId);
    }
}

cw_header('Compliance · Thread');

if ($threadId <= 0) {
    echo '<p style="margin:8px 0 0;">Thread not found.</p>';
    echo '<p><a href="/admin/compliance/inbox.php" style="color:#1e3c72;font-weight:700;">← Inbox</a></p>';
    cw_footer();
    return;
}

$thread = ComplianceCommsCenterEngine::getThread($pdo, $threadId);
if ($thread === null) {
    echo '<p style="margin:8px 0 0;">Thread not found.</p>';
    echo '<p><a href="/admin/compliance/inbox.php" style="color:#1e3c72;font-weight:700;">← Inbox</a></p>';
    cw_footer();
    return;
}

$emails = ComplianceCommsCenterEngine::listEmailsForThread($pdo, $threadId);
$links = ComplianceCommsCenterEngine::listObjectLinksForThread($pdo, $threadId);
$status = (string)$thread['status'];
$priority = (string)$thread['priority'];
$flash = cmpth_flash_take();

// Determine the most recent inbound message id for a one-click "Reply" affordance.
$latestInboundId = 0;
foreach (array_reverse($emails) as $e) {
    if ((string)$e['direction'] === 'inbound') {
        $latestInboundId = (int)$e['id'];
        break;
    }
}

?>
<style>
  .cmpth-h1{margin:0 0 6px;font-size:22px;color:#0f172a;}
  .cmpth-sub{margin:0 0 18px;color:#64748b;font-size:14px;}
  .cmpth-back{color:#1e3c72;font-weight:700;text-decoration:none;}
  .cmpth-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px 22px;margin-bottom:20px;}
  .cmpth-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.04em;}
  .cmpth-pill.s-open{background:#fee2e2;color:#991b1b;}
  .cmpth-pill.s-waiting_internal{background:#fef3c7;color:#92400e;}
  .cmpth-pill.s-waiting_external{background:#dbeafe;color:#1e3a8a;}
  .cmpth-pill.s-closed{background:#d1fae5;color:#065f46;}
  .cmpth-pill.s-archived{background:#e2e8f0;color:#475569;}
  .cmpth-pill.p-low{background:#e2e8f0;color:#475569;}
  .cmpth-pill.p-normal{background:#dbeafe;color:#1e3a8a;}
  .cmpth-pill.p-high{background:#fef3c7;color:#92400e;}
  .cmpth-pill.p-urgent{background:#fee2e2;color:#991b1b;}
  .cmpth-pill.e-received{background:#dbeafe;color:#1e3a8a;}
  .cmpth-pill.e-sent{background:#fef3c7;color:#92400e;}
  .cmpth-pill.e-queued{background:#fef3c7;color:#92400e;}
  .cmpth-pill.e-delivered{background:#d1fae5;color:#065f46;}
  .cmpth-pill.e-opened{background:#dcfce7;color:#15803d;}
  .cmpth-pill.e-clicked{background:#bbf7d0;color:#166534;}
  .cmpth-pill.e-bounced{background:#fee2e2;color:#991b1b;}
  .cmpth-pill.e-failed{background:#fee2e2;color:#991b1b;}
  .cmpth-pill.e-archived{background:#e2e8f0;color:#475569;}
  .cmpth-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;}
  .cmpth-msg{border:1px solid #e2e8f0;border-radius:14px;padding:16px 18px;margin-bottom:18px;background:#fafbfc;}
  .cmpth-msg.is-inbound{border-left:4px solid #1e3c72;}
  .cmpth-msg.is-outbound{border-left:4px solid #0f766e;background:#f5fff9;}
  .cmpth-msg-meta{font-size:12px;color:#64748b;margin-bottom:8px;}
  .cmpth-msg-meta strong{color:#0f172a;}
  .cmpth-msg-body{white-space:pre-wrap;font-size:14px;color:#1e293b;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;}
  .cmpth-msg-htmlnote{font-size:11px;color:#64748b;margin-top:6px;}
  .cmpth-att{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;}
  .cmpth-att a, .cmpth-att .cmpth-att-row{
    display:inline-flex;gap:6px;align-items:center;
    background:#fff;border:1px solid #e2e8f0;border-radius:10px;
    padding:6px 10px;font-size:12px;color:#0f172a;text-decoration:none;
  }
  .cmpth-att .cmpth-att-key{color:#64748b;font-family:ui-monospace,monospace;}
  .cmpth-table{width:100%;border-collapse:collapse;font-size:13px;}
  .cmpth-table th{
    text-align:left;font-size:11px;color:#64748b;font-weight:800;letter-spacing:.05em;
    text-transform:uppercase;padding:6px 8px;background:#f1f5f9;
  }
  .cmpth-table td{padding:8px;border-top:1px solid #e2e8f0;vertical-align:top;}
  .cmpth-flash{margin:0 0 16px;padding:10px 14px;border-radius:10px;font-size:13px;}
  .cmpth-flash.is-success{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;}
  .cmpth-flash.is-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
  .cmpth-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;}
  .cmpth-btn{
    display:inline-block;padding:7px 12px;border-radius:8px;font-size:12px;font-weight:700;
    text-decoration:none;border:0;cursor:pointer;line-height:1.2;
  }
  .cmpth-btn.primary{background:#1e3c72;color:#fff;}
  .cmpth-btn.secondary{background:#e2e8f0;color:#0f172a;}
  .cmpth-btn.danger{background:#fee2e2;color:#991b1b;}
  .cmpth-form-grid{
    display:grid;grid-template-columns:200px 1fr 1fr 160px auto;gap:10px;align-items:end;
  }
  .cmpth-input{padding:8px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box;font-size:13px;width:100%;}
  .cmpth-label{display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:3px;}
  .cmpth-quickactions{display:flex;flex-wrap:wrap;gap:6px;align-items:center;}
  .cmpth-quickactions form{display:inline-flex;gap:4px;align-items:center;}
  .cmpth-quickactions select{padding:5px 7px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;}
  .cmpth-linkrow{
    display:flex;flex-wrap:wrap;gap:10px;align-items:center;
    padding:8px 10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;
    margin-bottom:6px;font-size:13px;
  }
  .cmpth-linkrow form{display:inline;}
</style>

<p style="margin-bottom:12px;">
  <a class="cmpth-back" href="/admin/compliance/inbox.php">← Inbox</a>
  <span style="color:#64748b;margin:0 6px;">|</span>
  <a class="cmpth-back" href="/admin/compliance/email_drafts.php">Drafts</a>
  <span style="color:#64748b;margin:0 6px;">|</span>
  <span class="cmpth-mono">thread #<?= (int)$thread['id'] ?></span>
</p>

<section class="cmpth-card">
  <h1 class="cmpth-h1"><?= h((string)($thread['subject_normalized'] ?? '(no subject)')) ?></h1>
  <p class="cmpth-sub">
    <span class="cmpth-pill s-<?= h($status) ?>"><?= h(str_replace('_', ' ', $status)) ?></span>
    · <span class="cmpth-pill p-<?= h($priority) ?>"><?= h($priority) ?></span>
    <?php if (!empty($thread['authority_name'])): ?>
      · Authority: <?= h((string)$thread['authority_name']) ?>
    <?php endif; ?>
    <?php if (!empty($thread['primary_contact_email'])): ?>
      · Contact: <?= h((string)$thread['primary_contact_email']) ?>
    <?php endif; ?>
    <?php if (!empty($thread['last_message_at'])): ?>
      · Last message <span class="cmpth-mono"><?= h(substr((string)$thread['last_message_at'], 0, 16)) ?></span>
    <?php endif; ?>
  </p>

  <?php if ($flash !== null): ?>
    <div class="cmpth-flash is-<?= h((string)$flash['type']) ?>"><?= h((string)$flash['message']) ?></div>
  <?php endif; ?>

  <div class="cmpth-actions">
    <?php if ($latestInboundId > 0): ?>
      <a class="cmpth-btn primary"
         href="/admin/compliance/email_compose.php?reply_to_email_id=<?= $latestInboundId ?>">
        Reply to latest
      </a>
    <?php endif; ?>
    <a class="cmpth-btn secondary"
       href="/admin/compliance/email_compose.php?thread_id=<?= (int)$thread['id'] ?>">
      Compose into this thread
    </a>
  </div>

  <div class="cmpth-quickactions" style="margin-top:14px;">
    <form method="post" action="/admin/compliance/email_thread.php">
      <input type="hidden" name="action" value="set_status">
      <input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">
      <span class="cmpth-label" style="margin:0 4px 0 0;">Set status</span>
      <select name="status">
        <?php foreach (array('open','waiting_internal','waiting_external','closed','archived') as $s): ?>
          <option value="<?= h($s) ?>" <?= $status === $s ? 'selected' : '' ?>>
            <?= h(str_replace('_', ' ', $s)) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="cmpth-btn secondary">Save</button>
    </form>
    <form method="post" action="/admin/compliance/email_thread.php">
      <input type="hidden" name="action" value="set_priority">
      <input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">
      <span class="cmpth-label" style="margin:0 4px 0 0;">Priority</span>
      <select name="priority">
        <?php foreach (array('low','normal','high','urgent') as $p): ?>
          <option value="<?= h($p) ?>" <?= $priority === $p ? 'selected' : '' ?>><?= h($p) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="cmpth-btn secondary">Save</button>
    </form>
  </div>
</section>

<section class="cmpth-card">
  <h2 style="margin:0 0 12px;font-size:16px;">Linked compliance objects</h2>
  <?php if ($links === array()): ?>
    <p style="margin:0 0 12px;color:#64748b;font-size:13px;">No links yet. Use the form below to link this thread to a case, finding, audit, manual change request, etc.</p>
  <?php else: ?>
    <?php foreach ($links as $l): ?>
      <div class="cmpth-linkrow">
        <span class="cmpth-pill p-normal"><?= h((string)$l['link_type']) ?></span>
        <span class="cmpth-mono"><?= h((string)$l['linked_object_type']) ?></span>
        ·
        <span class="cmpth-mono"><?= h((string)$l['linked_object_id']) ?></span>
        <?php if (!empty($l['email_id'])): ?>
          · email #<?= (int)$l['email_id'] ?>
        <?php else: ?>
          · whole thread
        <?php endif; ?>
        <span style="flex:1;"></span>
        <form method="post" action="/admin/compliance/email_thread.php"
              onsubmit="return confirm('Remove this link?');">
          <input type="hidden" name="action" value="unlink_object">
          <input type="hidden" name="link_id" value="<?= (int)$l['id'] ?>">
          <input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">
          <button type="submit" class="cmpth-btn danger">Remove</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <form method="post" action="/admin/compliance/email_thread.php" style="margin-top:14px;">
    <input type="hidden" name="action" value="link_object">
    <input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">
    <div class="cmpth-form-grid">
      <div>
        <span class="cmpth-label">Object type</span>
        <select name="linked_object_type" class="cmpth-input" required>
          <?php foreach (ComplianceCommsCenterEngine::linkableObjectTypes() as $val => $label): ?>
            <option value="<?= h($val) ?>"><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <span class="cmpth-label">Object id</span>
        <input class="cmpth-input" type="text" name="linked_object_id" required
               placeholder="case_id / finding_id / audit_id …">
      </div>
      <div>
        <span class="cmpth-label">Email (optional)</span>
        <select name="email_id" class="cmpth-input">
          <option value="0">Whole thread</option>
          <?php foreach ($emails as $eo): ?>
            <option value="<?= (int)$eo['id'] ?>">
              #<?= (int)$eo['id'] ?> · <?= h(strtoupper((string)$eo['direction'])) ?> · <?= h(substr((string)($eo['subject'] ?? ''), 0, 48)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <span class="cmpth-label">Link role</span>
        <select name="link_type" class="cmpth-input">
          <?php foreach (ComplianceCommsCenterEngine::linkTypes() as $val => $label): ?>
            <option value="<?= h($val) ?>" <?= $val === 'authority_communication' ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="cmpth-btn primary">Link</button>
    </div>
  </form>
</section>

<?php if ($emails === array()): ?>
  <section class="cmpth-card">
    <p style="margin:0;color:#64748b;">This thread has no email rows. (The thread row exists but no message landed — usually a webhook ingestion failure. Check <code>ipca_compliance_email_events</code> for <code>webhook_error</code> rows.)</p>
  </section>
<?php else: ?>
  <?php foreach ($emails as $e):
    $eid = (int)$e['id'];
    $direction = (string)$e['direction'];
    $emailStatus = (string)($e['status'] ?? '');
    $attachments = ComplianceCommsCenterEngine::listAttachmentsForEmail($pdo, $eid);
    $events = ComplianceCommsCenterEngine::listEventsForEmail($pdo, $eid);
    $toList = is_string($e['to_json'] ?? null) ? (json_decode((string)$e['to_json'], true) ?: array()) : array();
    $ccList = is_string($e['cc_json'] ?? null) ? (json_decode((string)$e['cc_json'], true) ?: array()) : array();
    $bodyText = (string)($e['text_body'] ?? '');
    $bodyHtml = (string)($e['html_body'] ?? '');
    $hasHtmlOnly = $bodyText === '' && $bodyHtml !== '';
  ?>
    <section class="cmpth-msg <?= $direction === 'inbound' ? 'is-inbound' : 'is-outbound' ?>">
      <div class="cmpth-msg-meta">
        <strong><?= h(strtoupper($direction)) ?></strong>
        <?php if ($emailStatus !== ''): ?>
          <span class="cmpth-pill e-<?= h($emailStatus) ?>" style="margin-left:6px;">
            <?= h($emailStatus) ?>
          </span>
        <?php endif; ?>
        <?php if (!empty($e['received_at']) || !empty($e['sent_at'])): ?>
          · <?= h(substr((string)($e['received_at'] ?? $e['sent_at'] ?? ''), 0, 16)) ?>
        <?php endif; ?>
        · From <strong><?= h((string)($e['from_email'] ?? '')) ?></strong>
        <?php if (!empty($e['from_name'])): ?> (<?= h((string)$e['from_name']) ?>)<?php endif; ?>
        <?php if (is_array($toList) && $toList !== array()): ?>
          · To
          <?php $emails_to = array_map(static fn($x) => is_array($x) ? (string)($x['Email'] ?? '') : '', $toList); ?>
          <span class="cmpth-mono"><?= h(implode(', ', array_filter($emails_to))) ?></span>
        <?php endif; ?>
        <?php if (is_array($ccList) && $ccList !== array()): ?>
          <br>Cc:
          <?php $emails_cc = array_map(static fn($x) => is_array($x) ? (string)($x['Email'] ?? '') : '', $ccList); ?>
          <span class="cmpth-mono"><?= h(implode(', ', array_filter($emails_cc))) ?></span>
        <?php endif; ?>
        <br>
        <span class="cmpth-mono">subject:</span> <?= h((string)($e['subject'] ?? '(no subject)')) ?>
        <?php if (!empty($e['postmark_message_id'])): ?>
          · <span class="cmpth-mono">postmark <?= h(substr((string)$e['postmark_message_id'], 0, 18)) ?>…</span>
        <?php endif; ?>
        <?php if (isset($e['spam_score']) && $e['spam_score'] !== null): ?>
          · spam <?= h((string)$e['spam_score']) ?>
        <?php endif; ?>
      </div>

      <?php if ($bodyText !== ''): ?>
        <div class="cmpth-msg-body"><?= h($bodyText) ?></div>
      <?php elseif ($hasHtmlOnly): ?>
        <div class="cmpth-msg-body" style="white-space:normal;"><?php
          $allowed = '<p><br><strong><em><b><i><u><ul><ol><li><blockquote><pre><code><a><h1><h2><h3><h4><h5><h6><table><thead><tbody><tr><td><th>';
          echo strip_tags($bodyHtml, $allowed);
        ?></div>
        <div class="cmpth-msg-htmlnote">Rendered from HTML body — formatting limited for safety.</div>
      <?php else: ?>
        <div class="cmpth-msg-body" style="color:#64748b;font-style:italic;">(empty body)</div>
      <?php endif; ?>

      <?php if ($attachments !== array()): ?>
        <div class="cmpth-att">
          <?php foreach ($attachments as $a):
            $isCdn = (string)$a['storage_disk'] === 'spaces' && !empty($a['public_url']);
          ?>
            <?php if ($isCdn): ?>
              <a href="<?= h((string)$a['public_url']) ?>" target="_blank" rel="noopener">
                <strong><?= h((string)$a['original_filename']) ?></strong>
                <span class="cmpth-att-key">· <?= h((string)$a['storage_disk']) ?> · <?= (int)$a['size_bytes'] ?> bytes</span>
              </a>
            <?php else: ?>
              <span class="cmpth-att-row">
                <strong><?= h((string)$a['original_filename']) ?></strong>
                <span class="cmpth-att-key">· <?= h((string)$a['storage_disk']) ?> · <?= (int)$a['size_bytes'] ?> bytes · <?= h(substr((string)($a['sha256'] ?? ''), 0, 12)) ?>…</span>
              </span>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($direction === 'inbound'): ?>
        <div class="cmpth-actions" style="margin-top:12px;">
          <a class="cmpth-btn primary" href="/admin/compliance/email_compose.php?reply_to_email_id=<?= $eid ?>">
            Reply
          </a>
        </div>
      <?php endif; ?>

      <?php if ($events !== array()): ?>
        <details style="margin-top:12px;">
          <summary style="cursor:pointer;font-weight:700;color:#3730a3;font-size:13px;">Postmark events (<?= count($events) ?>)</summary>
          <table class="cmpth-table" style="margin-top:8px;">
            <thead><tr>
              <th>When</th><th>Type</th><th>Recipient</th><th>Payload</th>
            </tr></thead>
            <tbody>
              <?php foreach ($events as $ev): ?>
                <tr>
                  <td class="cmpth-mono"><?= h(substr((string)($ev['event_at'] ?? $ev['created_at'] ?? ''), 0, 16)) ?></td>
                  <td><?= h((string)$ev['event_type']) ?></td>
                  <td class="cmpth-mono"><?= h((string)($ev['recipient_email'] ?? '—')) ?></td>
                  <td class="cmpth-mono" style="max-width:380px;word-break:break-all;">
                    <?php
                      $payload = (string)($ev['event_payload_json'] ?? '');
                      $short = $payload !== '' ? substr($payload, 0, 240) . (strlen($payload) > 240 ? '…' : '') : '';
                      echo h($short);
                    ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </details>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
<?php endif; ?>

<?php
cw_footer();
