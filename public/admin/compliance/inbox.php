<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/CompliancePostmarkConfig.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsCenterEngine.php';

compliance_require_access($pdo);

/**
 * Compliance → Inbox.
 *
 * Stage 1 of the Compliance Communications Center: thread-based read view of
 * everything that arrives via the Postmark inbound webhook. Outbound (reply
 * compose / send) lands in Stage 2 once inbound is proven.
 */

$filterStatus = isset($_GET['status']) ? (string)$_GET['status'] : '';
$filterPriority = isset($_GET['priority']) ? (string)$_GET['priority'] : '';
$filterAttachments = isset($_GET['attachments']) && (string)$_GET['attachments'] === '1';
$filterLinked = isset($_GET['linked']) ? (string)$_GET['linked'] : '';
$filterQuery = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$filters = array();
if ($filterStatus !== '') {
    $filters['status'] = $filterStatus;
}
if ($filterPriority !== '') {
    $filters['priority'] = $filterPriority;
}
if ($filterAttachments) {
    $filters['has_attachments'] = true;
}
if ($filterLinked === 'linked') {
    $filters['linked'] = true;
} elseif ($filterLinked === 'unlinked') {
    $filters['linked'] = false;
}
if ($filterQuery !== '') {
    $filters['q'] = $filterQuery;
}

$threads = ComplianceCommsCenterEngine::listThreads($pdo, $filters, 200);
$stats = ComplianceCommsCenterEngine::threadStats($pdo);
$latest = ComplianceCommsCenterEngine::latestInbound($pdo);
$summary = CompliancePostmarkConfig::publicSummary();

cw_header('Compliance · Inbox');
?>
<style>
  .cmpcc-h1{margin:0 0 6px;font-size:24px;color:#0f172a;}
  .cmpcc-sub{margin:0 0 22px;color:#64748b;max-width:760px;line-height:1.55;}
  .cmpcc-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px 22px;margin-bottom:20px;}
  .cmpcc-kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:24px;max-width:1200px;}
  .cmpcc-kpi-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:14px 18px;text-decoration:none;color:inherit;display:block;transition:box-shadow .12s ease;}
  .cmpcc-kpi-card:hover{box-shadow:0 4px 14px rgba(15,23,42,.06);}
  .cmpcc-kpi-label{font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;}
  .cmpcc-kpi-big{font-size:26px;font-weight:800;color:#0f172a;margin-top:4px;}
  .cmpcc-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;}
  .cmpcc-tab{
    background:#e2e8f0;color:#0f172a;padding:6px 12px;border-radius:999px;
    text-decoration:none;font-size:12px;font-weight:700;
  }
  .cmpcc-tab.is-on{background:#1e3c72;color:#fff;}
  .cmpcc-table{width:100%;border-collapse:collapse;font-size:14px;}
  .cmpcc-table th{
    text-align:left;font-size:11px;color:#64748b;font-weight:800;letter-spacing:.05em;
    text-transform:uppercase;padding:8px;background:#f1f5f9;
  }
  .cmpcc-table td{padding:10px 8px;border-top:1px solid #e2e8f0;vertical-align:top;}
  .cmpcc-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;}
  .cmpcc-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.04em;}
  .cmpcc-pill.s-open{background:#fee2e2;color:#991b1b;}
  .cmpcc-pill.s-waiting_internal{background:#fef3c7;color:#92400e;}
  .cmpcc-pill.s-waiting_external{background:#dbeafe;color:#1e3a8a;}
  .cmpcc-pill.s-closed{background:#d1fae5;color:#065f46;}
  .cmpcc-pill.s-archived{background:#e2e8f0;color:#475569;}
  .cmpcc-pill.p-low{background:#e2e8f0;color:#475569;}
  .cmpcc-pill.p-normal{background:#dbeafe;color:#1e3a8a;}
  .cmpcc-pill.p-high{background:#fef3c7;color:#92400e;}
  .cmpcc-pill.p-urgent{background:#fee2e2;color:#991b1b;}
  .cmpcc-input{padding:8px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box;font-size:13px;}
  .cmpcc-help{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:18px 20px;font-size:13px;color:#334155;line-height:1.55;}
  .cmpcc-help code{background:#eef2ff;color:#3730a3;padding:1px 6px;border-radius:6px;font-size:12px;}
  .cmpcc-help ul{margin:6px 0 0;padding-left:18px;}
  .cmpcc-help li{margin-bottom:4px;}
  .cmpcc-empty{padding:18px;color:#64748b;text-align:center;background:#f8fafc;border-radius:10px;}
  .cmpcc-flex{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
  .cmpcc-clear{font-size:12px;font-weight:700;color:#3730a3;text-decoration:none;margin-left:6px;}
</style>

<section style="padding:8px 0 40px;max-width:1200px;">
  <h1 class="cmpcc-h1">Compliance inbox</h1>
  <p class="cmpcc-sub">
    Every email routed through <code><?= h($summary['inbox_address'] !== '' ? (string)$summary['inbox_address'] : 'compliance@ipca.training') ?></code>
    arrives here via the Postmark inbound webhook. Threads are auto-grouped from <code>In-Reply-To</code> / <code>References</code> headers
    when available, falling back to mailbox-hash or normalised subject + sender.
  </p>

  <div class="cmpcc-kpis">
    <a class="cmpcc-kpi-card" href="/admin/compliance/inbox.php?status=open">
      <div class="cmpcc-kpi-label">Open threads</div>
      <div class="cmpcc-kpi-big"><?= (int)$stats['open'] ?></div>
    </a>
    <a class="cmpcc-kpi-card" href="/admin/compliance/inbox.php?status=waiting_internal">
      <div class="cmpcc-kpi-label">Waiting internal</div>
      <div class="cmpcc-kpi-big"><?= (int)$stats['waiting_internal'] ?></div>
    </a>
    <a class="cmpcc-kpi-card" href="/admin/compliance/inbox.php?status=waiting_external">
      <div class="cmpcc-kpi-label">Waiting external</div>
      <div class="cmpcc-kpi-big"><?= (int)$stats['waiting_external'] ?></div>
    </a>
    <a class="cmpcc-kpi-card" href="/admin/compliance/inbox.php?linked=unlinked">
      <div class="cmpcc-kpi-label">Unlinked</div>
      <div class="cmpcc-kpi-big"><?= (int)$stats['unlinked'] ?></div>
    </a>
    <a class="cmpcc-kpi-card" href="/admin/compliance/inbox.php?attachments=1">
      <div class="cmpcc-kpi-label">With attachments</div>
      <div class="cmpcc-kpi-big"><?= (int)$stats['has_attachments'] ?></div>
    </a>
    <a class="cmpcc-kpi-card" href="/admin/compliance/inbox.php?status=closed">
      <div class="cmpcc-kpi-label">Closed</div>
      <div class="cmpcc-kpi-big"><?= (int)$stats['closed'] ?></div>
    </a>
  </div>

  <section class="cmpcc-card">
    <form method="get" style="display:grid;grid-template-columns:1fr 160px 160px 160px auto;gap:10px;align-items:end;">
      <label>
        <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Search</span>
        <input class="cmpcc-input" type="search" name="q" placeholder="subject, sender, authority…"
               value="<?= h($filterQuery) ?>" style="width:100%;">
      </label>
      <label>
        <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Status</span>
        <select class="cmpcc-input" name="status" style="width:100%;">
          <option value="">All</option>
          <?php foreach (array('open','waiting_internal','waiting_external','closed','archived') as $s): ?>
            <option value="<?= h($s) ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Priority</span>
        <select class="cmpcc-input" name="priority" style="width:100%;">
          <option value="">All</option>
          <?php foreach (array('low','normal','high','urgent') as $p): ?>
            <option value="<?= h($p) ?>" <?= $filterPriority === $p ? 'selected' : '' ?>><?= h($p) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Linked</span>
        <select class="cmpcc-input" name="linked" style="width:100%;">
          <option value="">All</option>
          <option value="linked" <?= $filterLinked === 'linked' ? 'selected' : '' ?>>Linked only</option>
          <option value="unlinked" <?= $filterLinked === 'unlinked' ? 'selected' : '' ?>>Unlinked only</option>
        </select>
      </label>
      <button type="submit" style="background:#1e3c72;color:#fff;border:0;padding:10px 16px;border-radius:8px;font-weight:800;cursor:pointer;">Filter</button>
    </form>
    <div class="cmpcc-flex" style="margin-top:10px;">
      <label style="display:inline-flex;gap:6px;align-items:center;font-size:13px;color:#334155;">
        <input type="checkbox" form="hiddenAttForm" disabled <?= $filterAttachments ? 'checked' : '' ?>>
        Has attachments
      </label>
      <?php if ($filterStatus !== '' || $filterPriority !== '' || $filterAttachments || $filterLinked !== '' || $filterQuery !== ''): ?>
        <a class="cmpcc-clear" href="/admin/compliance/inbox.php">Clear filters</a>
      <?php endif; ?>
    </div>
  </section>

  <section class="cmpcc-card">
    <?php if ($threads === array()): ?>
      <div class="cmpcc-empty">
        No threads in scope. Either nothing has arrived yet, or your filter excludes everything on file.
      </div>
    <?php else: ?>
      <table class="cmpcc-table">
        <thead><tr>
          <th>Status</th>
          <th>Subject</th>
          <th>Contact</th>
          <th>Last message</th>
          <th>Msgs</th>
          <th>Att</th>
          <th>Links</th>
          <th>Priority</th>
        </tr></thead>
        <tbody>
          <?php foreach ($threads as $t): ?>
            <?php
              $tid = (int)$t['id'];
              $status = (string)$t['status'];
              $priority = (string)$t['priority'];
              $subjectShow = trim((string)($t['last_subject'] ?? '')) !== ''
                  ? (string)$t['last_subject']
                  : ((string)($t['subject_normalized'] ?? '(no subject)'));
            ?>
            <tr>
              <td><span class="cmpcc-pill s-<?= h($status) ?>"><?= h(str_replace('_', ' ', $status)) ?></span></td>
              <td>
                <a href="/admin/compliance/email_thread.php?id=<?= $tid ?>"
                   style="color:#1e3c72;font-weight:700;text-decoration:none;">
                  <?= h($subjectShow !== '' ? $subjectShow : '(no subject)') ?>
                </a>
                <?php if (!empty($t['authority_name'])): ?>
                  <div style="font-size:11px;color:#64748b;">Authority: <?= h((string)$t['authority_name']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <div><?= h((string)($t['primary_contact_email'] ?? $t['last_from_email'] ?? '—')) ?></div>
              </td>
              <td class="cmpcc-mono"><?= h(substr((string)($t['last_message_at'] ?? $t['created_at'] ?? ''), 0, 16)) ?></td>
              <td><?= (int)$t['message_count'] ?></td>
              <td><?= (int)$t['attachment_count'] ?></td>
              <td><?= (int)$t['link_count'] ?></td>
              <td><span class="cmpcc-pill p-<?= h($priority) ?>"><?= h($priority) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <section class="cmpcc-card">
    <h2 style="margin:0 0 12px;font-size:16px;">Webhook & integration status</h2>
    <table class="cmpcc-table" style="margin-bottom:14px;">
      <tbody>
        <tr>
          <td style="width:260px;font-weight:700;">Compliance inbox address</td>
          <td class="cmpcc-mono"><?= h((string)$summary['inbox_address']) ?: '<em style="color:#b91c1c;">not set</em>' ?></td>
        </tr>
        <tr>
          <td style="font-weight:700;">Outbound from address</td>
          <td class="cmpcc-mono"><?= h((string)$summary['from_address']) ?: '<em style="color:#64748b;">defaults to inbox address</em>' ?></td>
        </tr>
        <tr>
          <td style="font-weight:700;">Outbound stream</td>
          <td class="cmpcc-mono"><?= h((string)$summary['outbound_stream']) ?></td>
        </tr>
        <tr>
          <td style="font-weight:700;">Postmark server token</td>
          <td><?= $summary['server_token_set'] ? '<span class="cmpcc-pill p-normal">configured</span>' : '<span class="cmpcc-pill p-urgent">missing</span>' ?>
              <span class="cmpcc-mono" style="margin-left:6px;"><?= h((string)$summary['server_token_masked']) ?></span></td>
        </tr>
        <tr>
          <td style="font-weight:700;">Inbound webhook secret</td>
          <td><?= $summary['inbound_secret_set'] ? '<span class="cmpcc-pill p-normal">configured</span>' : '<span class="cmpcc-pill p-urgent">missing</span>' ?>
              <span class="cmpcc-mono" style="margin-left:6px;"><?= h((string)$summary['inbound_secret_masked']) ?></span></td>
        </tr>
        <tr>
          <td style="font-weight:700;">Tracking webhook secret</td>
          <td><?= $summary['tracking_secret_set'] ? '<span class="cmpcc-pill p-normal">configured</span>' : '<span class="cmpcc-pill p-normal" style="background:#fef3c7;color:#92400e;">missing (stage 2)</span>' ?>
              <span class="cmpcc-mono" style="margin-left:6px;"><?= h((string)$summary['tracking_secret_masked']) ?></span></td>
        </tr>
        <tr>
          <td style="font-weight:700;">Inbound webhook URL</td>
          <td class="cmpcc-mono" style="word-break:break-all;">
            <?= $summary['inbound_webhook_url'] !== '' ? h((string)$summary['inbound_webhook_url']) : '<em style="color:#b91c1c;">set CW_PUBLIC_BASE_URL + POSTMARK_INBOUND_WEBHOOK_SECRET</em>' ?>
          </td>
        </tr>
        <tr>
          <td style="font-weight:700;">Tracking webhook URL</td>
          <td class="cmpcc-mono" style="word-break:break-all;">
            <?= $summary['tracking_webhook_url'] !== '' ? h((string)$summary['tracking_webhook_url']) : '<em style="color:#64748b;">stage 2</em>' ?>
          </td>
        </tr>
        <tr>
          <td style="font-weight:700;">Last inbound received</td>
          <td>
            <?php if ($latest['id'] !== null): ?>
              <a href="/admin/compliance/email_thread.php?email_id=<?= (int)$latest['id'] ?>"
                 style="color:#1e3c72;font-weight:700;text-decoration:none;">
                <?= h(substr((string)($latest['received_at'] ?? ''), 0, 16)) ?>
              </a>
              · <?= h((string)($latest['from_email'] ?? '')) ?>
              · <?= h((string)($latest['subject'] ?? '(no subject)')) ?>
            <?php else: ?>
              <em style="color:#64748b;">no inbound emails recorded yet</em>
            <?php endif; ?>
          </td>
        </tr>
      </tbody>
    </table>

    <div class="cmpcc-help">
      <strong style="color:#0f172a;">Stage 1 setup checklist</strong>
      <ul>
        <li>Create <code><?= h((string)($summary['inbox_address'] !== '' ? $summary['inbox_address'] : 'compliance@ipca.training')) ?></code> in Google Workspace (MFA, retention, DKIM/SPF/DMARC).</li>
        <li>In Google Workspace, configure a forwarding/copy rule from the mailbox to your Postmark Inbound address (e.g. <code>&lt;hash&gt;@inbound.postmarkapp.com</code>).</li>
        <li>In Postmark → Servers → Inbound, paste the <em>Inbound webhook URL</em> above into "Webhook URL".</li>
        <li>
          Inject the following variables on the host (no <code>.env</code> file — this platform uses PHP-FPM pool config for web/webhooks
          and <code>/etc/ipca/ipca-courseware-cli.env</code> for CLI):
          <ul style="margin-top:4px;">
            <li><code>POSTMARK_SERVER_TOKEN</code> &nbsp;— Postmark server API token (used by Stage 2 outbound).</li>
            <li><code>POSTMARK_INBOUND_WEBHOOK_SECRET</code> &nbsp;— shared secret carried in the webhook URL.</li>
            <li><code>POSTMARK_TRACKING_WEBHOOK_SECRET</code> &nbsp;— shared secret for delivery/open/bounce webhooks (Stage 2).</li>
            <li><code>COMPLIANCE_INBOX_ADDRESS</code> &nbsp;— public compliance mailbox (e.g. <code>compliance@ipca.training</code>).</li>
            <li><code>COMPLIANCE_POSTMARK_FROM</code> &nbsp;— optional, defaults to <code>COMPLIANCE_INBOX_ADDRESS</code>.</li>
            <li><code>CW_PUBLIC_BASE_URL</code> &nbsp;— platform base URL (so this panel can render the full webhook URL).</li>
          </ul>
        </li>
        <li>Reload PHP-FPM after editing the pool file so the webhook process sees the new vars: <code>systemctl reload php8.3-fpm</code>.</li>
        <li>Send a test email to the mailbox and verify a row lands in this list within a few seconds.</li>
      </ul>
      <p style="margin:10px 0 0;">Outbound sending, reply composition, and Postmark tracking event ingestion are deliberately deferred to Stage 2 until inbound is proven on this environment.</p>
    </div>
  </section>
</section>
<?php
cw_footer();
