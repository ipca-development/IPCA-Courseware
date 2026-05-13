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
    compliance_page_open(array(
        'overline' => 'Compliance · Comms Center',
        'title' => 'Thread not found',
        'description' => 'No thread on file for the provided id.',
        'back' => array('href' => '/admin/compliance/inbox.php', 'label' => 'Back to inbox'),
    ));
    echo '<section class="cmp-card"><p style="margin:0;">Pick a thread from the <a href="/admin/compliance/inbox.php">inbox</a>.</p></section>';
    compliance_page_close();
    cw_footer();
    return;
}

$thread = ComplianceCommsCenterEngine::getThread($pdo, $threadId);
if ($thread === null) {
    compliance_page_open(array(
        'overline' => 'Compliance · Comms Center',
        'title' => 'Thread not found',
        'description' => 'The thread id ' . (int)$threadId . ' does not exist (or was deleted).',
        'back' => array('href' => '/admin/compliance/inbox.php', 'label' => 'Back to inbox'),
    ));
    echo '<section class="cmp-card"><p style="margin:0;">Pick a thread from the <a href="/admin/compliance/inbox.php">inbox</a>.</p></section>';
    compliance_page_close();
    cw_footer();
    return;
}

$emails = ComplianceCommsCenterEngine::listEmailsForThread($pdo, $threadId);
$links = ComplianceCommsCenterEngine::listObjectLinksForThread($pdo, $threadId);
$pickerGroups = ComplianceCommsCenterEngine::listLinkablePickerOptions($pdo, 200);
$linkable = ComplianceCommsCenterEngine::linkableObjectTypes();
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

$heroActions = array();
if ($latestInboundId > 0) {
    $heroActions[] = array(
        'label' => 'Reply to latest',
        'href' => '/admin/compliance/email_compose.php?reply_to_email_id=' . $latestInboundId,
        'icon' => 'mail',
    );
}
$heroActions[] = array(
    'label' => 'Compose into this thread',
    'href' => '/admin/compliance/email_compose.php?thread_id=' . (int)$thread['id'],
    'icon' => 'plus',
);

$heroDescription = '';
if (!empty($thread['authority_name'])) {
    $heroDescription .= 'Authority ' . (string)$thread['authority_name'] . '. ';
}
if (!empty($thread['primary_contact_email'])) {
    $heroDescription .= 'Contact ' . (string)$thread['primary_contact_email'] . '. ';
}
if (!empty($thread['last_message_at'])) {
    $heroDescription .= 'Last message ' . substr((string)$thread['last_message_at'], 0, 16) . '. ';
}
if ($heroDescription === '') {
    $heroDescription = 'Compliance email thread — inbound and outbound messages, attachments, delivery events and object links.';
}

compliance_page_open(array(
    'overline' => 'Compliance · Comms Center',
    'title' => (string)($thread['subject_normalized'] ?? '(no subject)'),
    'description' => trim($heroDescription),
    'actions' => $heroActions,
    'back' => array(
        'href' => '/admin/compliance/inbox.php',
        'label' => 'Inbox',
        'code' => 'thread #' . (int)$thread['id'],
    ),
    'flash' => $flash,
));
?>
<style>
  .cmpth-pill{display:inline-flex;align-items:center;padding:0 12px;height:28px;border-radius:999px;font-size:11.5px;font-weight:720;letter-spacing:.04em;border:1px solid rgba(15,23,42,0.08);background:#f3f6fb;color:#324155;}
  .cmpth-pill.s-open{background:rgba(48,124,183,0.10);color:#246ea9;border-color:rgba(48,124,183,0.20);}
  .cmpth-pill.s-waiting_internal{background:rgba(196,118,11,0.10);color:#a66508;border-color:rgba(196,118,11,0.20);}
  .cmpth-pill.s-waiting_external{background:rgba(70,55,179,0.10);color:#473cb3;border-color:rgba(70,55,179,0.20);}
  .cmpth-pill.s-closed{background:rgba(32,135,90,0.12);color:#1f7a54;border-color:rgba(32,135,90,0.20);}
  .cmpth-pill.s-archived{background:rgba(86,112,153,0.10);color:#405a82;border-color:rgba(86,112,153,0.18);}
  .cmpth-pill.p-low{background:rgba(86,112,153,0.10);color:#405a82;border-color:rgba(86,112,153,0.18);}
  .cmpth-pill.p-normal{background:rgba(48,124,183,0.10);color:#246ea9;border-color:rgba(48,124,183,0.20);}
  .cmpth-pill.p-high{background:rgba(196,118,11,0.10);color:#a66508;border-color:rgba(196,118,11,0.20);}
  .cmpth-pill.p-urgent{background:rgba(185,54,54,0.12);color:#9a2424;border-color:rgba(185,54,54,0.22);}
  .cmpth-pill.e-received{background:rgba(48,124,183,0.10);color:#246ea9;border-color:rgba(48,124,183,0.20);}
  .cmpth-pill.e-sent{background:rgba(196,118,11,0.10);color:#a66508;border-color:rgba(196,118,11,0.20);}
  .cmpth-pill.e-queued{background:rgba(196,118,11,0.10);color:#a66508;border-color:rgba(196,118,11,0.20);}
  .cmpth-pill.e-delivered{background:rgba(32,135,90,0.12);color:#1f7a54;border-color:rgba(32,135,90,0.20);}
  .cmpth-pill.e-opened{background:rgba(32,135,90,0.14);color:#176f49;border-color:rgba(32,135,90,0.22);}
  .cmpth-pill.e-clicked{background:rgba(32,135,90,0.16);color:#0f5e3a;border-color:rgba(32,135,90,0.26);}
  .cmpth-pill.e-bounced{background:rgba(185,54,54,0.12);color:#9a2424;border-color:rgba(185,54,54,0.22);}
  .cmpth-pill.e-failed{background:rgba(185,54,54,0.12);color:#9a2424;border-color:rgba(185,54,54,0.22);}
  .cmpth-pill.e-archived{background:rgba(86,112,153,0.10);color:#405a82;border-color:rgba(86,112,153,0.18);}
  .cmpth-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;color:var(--text-muted);}
  .cmpth-msg{border:1px solid var(--border-soft);border-radius:14px;padding:16px 18px;margin-bottom:14px;background:#fbfcfe;}
  .cmpth-msg.is-inbound{border-left:4px solid #1e3c72;}
  .cmpth-msg.is-outbound{border-left:4px solid #1f7a54;background:#f5fbf7;}
  .cmpth-msg-meta{font-size:12px;color:var(--text-muted);margin-bottom:8px;}
  .cmpth-msg-meta strong{color:var(--text-strong);}
  .cmpth-msg-body{white-space:pre-wrap;font-size:14px;color:var(--text-strong);background:#fff;border:1px solid var(--border-soft);border-radius:10px;padding:12px 14px;}
  .cmpth-msg-htmlnote{font-size:11px;color:var(--text-muted);margin-top:6px;}
  .cmpth-att{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;}
  .cmpth-att a, .cmpth-att .cmpth-att-row{display:inline-flex;gap:6px;align-items:center;background:#fff;border:1px solid var(--border-soft);border-radius:10px;padding:6px 10px;font-size:12px;color:var(--text-strong);text-decoration:none;}
  .cmpth-att .cmpth-att-key{color:var(--text-muted);font-family:ui-monospace,monospace;}
  .cmpth-linkrow{display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:10px 14px;background:#f8fafc;border:1px solid var(--border-soft);border-radius:12px;margin-bottom:8px;font-size:13px;}
  .cmpth-linkrow form{display:inline;}
  .cmpth-quickactions{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;}
  .cmpth-quickactions form{display:flex;align-items:end;gap:8px;}
  .cmpth-quickactions .cmpth-label{display:block;font-size:11px;font-weight:720;color:#7787a0;text-transform:uppercase;letter-spacing:.06em;margin-bottom:7px;}
  .cmpth-quickactions select{height:42px;min-height:42px;}
</style>

<section class="cmp-card">
  <div class="cmp-list-head" style="margin-bottom:14px;">
    <div class="cmp-list-title">
      <?= compliance_ui_icon('settings') ?>
      <span>Thread management</span>
    </div>
    <div class="cmpth-quickactions">
      <span class="cmpth-pill s-<?= h($status) ?>"><?= h(str_replace('_', ' ', $status)) ?></span>
      <span class="cmpth-pill p-<?= h($priority) ?>"><?= h($priority) ?></span>
    </div>
  </div>

  <div class="cmpth-quickactions">
    <form method="post" action="/admin/compliance/email_thread.php">
      <input type="hidden" name="action" value="set_status">
      <input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">
      <label>
        <span class="cmpth-label">Set status</span>
        <select name="status">
          <?php foreach (array('open','waiting_internal','waiting_external','closed','archived') as $s): ?>
            <option value="<?= h($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= h(str_replace('_', ' ', $s)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="cmp-btn-secondary">Save</button>
    </form>
    <form method="post" action="/admin/compliance/email_thread.php">
      <input type="hidden" name="action" value="set_priority">
      <input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">
      <label>
        <span class="cmpth-label">Priority</span>
        <select name="priority">
          <?php foreach (array('low','normal','high','urgent') as $p): ?>
            <option value="<?= h($p) ?>" <?= $priority === $p ? 'selected' : '' ?>><?= h($p) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="cmp-btn-secondary">Save</button>
    </form>
  </div>
</section>

<section class="cmp-card">
  <div class="cmp-list-head" style="margin-bottom:14px;">
    <div class="cmp-list-title">
      <?= compliance_ui_icon('list') ?>
      <span>Linked compliance objects</span>
    </div>
    <div class="cmp-count-pill"><?= count($links) ?> link<?= count($links) === 1 ? '' : 's' ?></div>
  </div>
  <?php if ($links === array()): ?>
    <p style="margin:0 0 12px;color:#64748b;font-size:13px;">No links yet. Use the form below to link this thread to a case, finding, audit, manual change request, etc.</p>
  <?php else: ?>
    <?php foreach ($links as $l): ?>
      <?php
        $linkType = (string)$l['link_type'];
        $linkedType = (string)$l['linked_object_type'];
        $linkedId = (string)$l['linked_object_id'];
        $typeLabel = (string)($linkable[$linkedType] ?? ucfirst(str_replace('_', ' ', $linkedType)));
        $humanLabel = $linkedId;
        foreach ($pickerGroups as $pg) {
            if ($pg['type'] !== $linkedType) {
                continue;
            }
            foreach ($pg['options'] as $opt) {
                if ((string)$opt['id'] === $linkedId) {
                    $humanLabel = (string)$opt['label'];
                    break 2;
                }
            }
        }
        $deepLinkHref = '';
        switch ($linkedType) {
            case 'finding':                 $deepLinkHref = '/admin/compliance/findings.php?id=' . $linkedId; break;
            case 'audit':                   $deepLinkHref = '/admin/compliance/audits.php?id=' . $linkedId; break;
            case 'corrective_action':       $deepLinkHref = '/admin/compliance/corrective_actions.php?id=' . $linkedId; break;
            case 'manual_change_request':   $deepLinkHref = '/admin/compliance/change_requests.php?id=' . $linkedId; break;
            case 'meeting':                 $deepLinkHref = '/admin/compliance/meetings.php?id=' . $linkedId; break;
        }
      ?>
      <div class="cmpth-linkrow">
        <span class="cmpth-pill p-normal"><?= h(str_replace('_', ' ', $linkType)) ?></span>
        <strong style="color:#0f172a;font-size:12px;text-transform:uppercase;letter-spacing:.04em;"><?= h($typeLabel) ?></strong>
        ·
        <?php if ($deepLinkHref !== ''): ?>
          <a href="<?= h($deepLinkHref) ?>" style="color:#1e3c72;font-weight:700;text-decoration:none;">
            <?= h($humanLabel) ?>
          </a>
        <?php else: ?>
          <span><?= h($humanLabel) ?></span>
        <?php endif; ?>
        <?php if (!empty($l['email_id'])): ?>
          <span style="color:#64748b;font-size:12px;">· email #<?= (int)$l['email_id'] ?></span>
        <?php else: ?>
          <span style="color:#64748b;font-size:12px;">· whole thread</span>
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

  <?php $pickerHasOptions = false; foreach ($pickerGroups as $pg) { if (!empty($pg['options'])) { $pickerHasOptions = true; break; } } ?>
  <form method="post" action="/admin/compliance/email_thread.php" id="cmpthLinkForm" style="margin-top:14px;">
    <input type="hidden" name="action" value="link_object">
    <input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">
    <input type="hidden" name="linked_object_type" id="cmpthLinkedType" value="">
    <input type="hidden" name="linked_object_id" id="cmpthLinkedId" value="">

    <?php if (!$pickerHasOptions): ?>
      <div style="padding:10px 14px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:10px;font-size:13px;">
        No findings, audits, or other compliance objects on file yet — there's nothing to link this thread to.
      </div>
    <?php else: ?>
      <div style="display:grid;grid-template-columns:1.6fr 1fr 220px 130px;gap:10px;align-items:end;">
        <div>
          <span class="cmpth-label">Compliance object</span>
          <select id="cmpthPicker" class="cmpth-input" required>
            <option value="" disabled selected>— Choose a finding, audit, case, …</option>
            <?php foreach ($pickerGroups as $pg): ?>
              <optgroup label="<?= h((string)$pg['type_label']) ?>">
                <?php foreach ($pg['options'] as $opt): ?>
                  <option value="<?= h((string)$pg['type']) ?>|<?= h((string)$opt['id']) ?>"
                          data-type="<?= h((string)$pg['type']) ?>"
                          data-id="<?= h((string)$opt['id']) ?>">
                    <?= h((string)$opt['label']) ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <span class="cmpth-label">Email (optional)</span>
          <select name="email_id" class="cmpth-input">
            <option value="0">Whole thread</option>
            <?php foreach ($emails as $eo): ?>
              <option value="<?= (int)$eo['id'] ?>">
                #<?= (int)$eo['id'] ?> · <?= h(strtoupper((string)$eo['direction'])) ?> · <?= h(substr((string)($eo['subject'] ?? ''), 0, 40)) ?>
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
        <div>
          <span class="cmpth-label">&nbsp;</span>
          <button type="submit" class="cmpth-btn primary">Link</button>
        </div>
      </div>
      <script>
        (function () {
          var form = document.getElementById('cmpthLinkForm');
          var picker = document.getElementById('cmpthPicker');
          var hiddenType = document.getElementById('cmpthLinkedType');
          var hiddenId = document.getElementById('cmpthLinkedId');
          if (!form || !picker) { return; }
          form.addEventListener('submit', function (ev) {
            var opt = picker.options[picker.selectedIndex];
            var type = opt && opt.getAttribute('data-type');
            var id = opt && opt.getAttribute('data-id');
            if (!type || !id) {
              ev.preventDefault();
              picker.focus();
              return false;
            }
            hiddenType.value = type;
            hiddenId.value = id;
          });
        })();
      </script>
    <?php endif; ?>
  </form>
</section>

<?php if ($emails === array()): ?>
  <section class="cmp-card">
    <p style="margin:0;color:var(--text-muted);">This thread has no email rows. (The thread row exists but no message landed — usually a webhook ingestion failure. Check <code>ipca_compliance_email_events</code> for <code>webhook_error</code> rows.)</p>
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
compliance_page_close();
cw_footer();
