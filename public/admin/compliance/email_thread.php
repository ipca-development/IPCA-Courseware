<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsCenterEngine.php';

compliance_require_access($pdo);

/**
 * Compliance → Email thread (read-only, Stage 1).
 *
 * Accepts either ?id=<thread_id> or ?email_id=<email_id>. In Stage 1 this is
 * intentionally read-only: it surfaces every email in the thread with its
 * body, attachments and recorded Postmark events so the operator can verify
 * the inbound webhook is delivering correct data. Stage 2 adds reply
 * composition, sending, linking and archiving.
 */

$threadId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$emailIdParam = isset($_GET['email_id']) ? (int)$_GET['email_id'] : 0;

if ($threadId <= 0 && $emailIdParam > 0) {
    $st = $pdo->prepare('SELECT thread_id FROM ipca_compliance_emails WHERE id = ? LIMIT 1');
    $st->execute(array($emailIdParam));
    $threadId = (int)$st->fetchColumn();
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
  .cmpth-note{
    margin:0 0 18px;padding:10px 14px;background:#eef2ff;color:#3730a3;
    border-radius:10px;font-size:13px;
  }
</style>

<p style="margin-bottom:12px;">
  <a class="cmpth-back" href="/admin/compliance/inbox.php">← Inbox</a>
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

  <p class="cmpth-note">
    Stage 1 read-only view. Reply, send-outbound and link-to-compliance-object actions land in Stage 2.
  </p>

  <?php if ($links !== array()): ?>
    <div style="margin-bottom:14px;">
      <strong style="font-size:12px;color:#0f172a;text-transform:uppercase;letter-spacing:.06em;">Linked objects</strong>
      <ul style="margin:6px 0 0;padding-left:18px;font-size:13px;color:#334155;">
        <?php foreach ($links as $l): ?>
          <li>
            <span class="cmpth-mono"><?= h((string)$l['linked_object_type']) ?> · <?= h((string)$l['linked_object_id']) ?></span>
            — <?= h((string)$l['link_type']) ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</section>

<?php if ($emails === array()): ?>
  <section class="cmpth-card">
    <p style="margin:0;color:#64748b;">This thread has no email rows. (The thread row exists but no message landed — usually a webhook ingestion failure. Check <code>ipca_compliance_email_events</code> for <code>webhook_error</code> rows.)</p>
  </section>
<?php else: ?>
  <?php foreach ($emails as $e):
    $eid = (int)$e['id'];
    $direction = (string)$e['direction'];
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
          // Allow only a conservative subset of HTML rather than echoing raw markup.
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
