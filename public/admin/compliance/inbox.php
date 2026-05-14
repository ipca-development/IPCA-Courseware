<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/CompliancePostmarkConfig.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsCenterEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

/**
 * Compliance → Inbox.
 *
 * Thread-based view of everything that arrives via the Postmark inbound
 * webhook, plus outbound replies and bulk thread-level actions.
 *
 * Search:
 *   ?q=…  runs a full-text search over (subject, text_body); falls back to
 *         a LIKE scan if the FULLTEXT index hasn't been applied yet. When
 *         a search string is present, the page shows matching *emails*
 *         (deep-linking to their threads) instead of the thread list.
 *
 * Bulk actions:
 *   POST with action=bulk_status / bulk_priority + thread_ids[] flips many
 *   threads at once.
 */

function cmpcc_flash(string $type, string $msg): void
{
    $_SESSION['_ipca_compliance_flash_inbox'] = array('type' => $type, 'message' => $msg);
}
function cmpcc_flash_take(): ?array
{
    if (empty($_SESSION['_ipca_compliance_flash_inbox']) || !is_array($_SESSION['_ipca_compliance_flash_inbox'])) {
        return null;
    }
    $f = $_SESSION['_ipca_compliance_flash_inbox'];
    unset($_SESSION['_ipca_compliance_flash_inbox']);

    return $f;
}

function cmpcc_default_email_template_subject(): string
{
    return '[{{COMPLIANCE_THREAD_CODE}}] {{EMAIL_TITLE}}';
}

function cmpcc_default_email_template_text(): string
{
    return "Dear {{RECIPIENT_NAME}},\n\n{{EMAIL_BODY_TEXT}}\n\nSincerely,\n{{COMPLIANCE_MONITORING_MANAGER_NAME}}\n{{COMPLIANCE_MONITORING_MANAGER_SIGNATURE_TEXT}}\n\nIMPORTANT NOTES:\nPlease keep the information in this email and any attachments strictly confidential. To preserve the compliance audit trail, please reply directly to this email using your normal Reply button and do not change the subject line, remove the thread reference, or start a new email chain for this matter.\n\nCompliance object references: {{COMPLIANCE_OBJECT_SUMMARY_TEXT}}\nMessage tracking reference: {{COMPLIANCE_THREAD_CODE}}";
}

function cmpcc_default_email_template_allowed_variables_json(): string
{
    return json_encode(array(
        'EMAIL_TITLE',
        'RECIPIENT_NAME',
        'EMAIL_BODY_HTML',
        'EMAIL_BODY_TEXT',
        'COMPLIANCE_MONITORING_MANAGER_NAME',
        'COMPLIANCE_MONITORING_MANAGER_TITLE',
        'COMPLIANCE_MONITORING_MANAGER_SIGNATURE_HTML',
        'COMPLIANCE_MONITORING_MANAGER_SIGNATURE_TEXT',
        'COMPLIANCE_THREAD_CODE',
        'COMPLIANCE_OBJECT_TYPE_N',
        'COMPLIANCE_OBJECT_CODE_N',
        'COMPLIANCE_OBJECT_URL_N',
        'COMPLIANCE_OBJECT_SUMMARY_TEXT',
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]';
}

function cmpcc_default_email_template_html(): string
{
    return <<<'HTML'
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>IPCA.training Compliance Communication</title>
</head>
<body style="margin:0;padding:0;background:#f3f6fb;font-family:Arial,Helvetica,sans-serif;color:#152235;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f6fb;padding:28px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:720px;background:#ffffff;border:1px solid #dfe6f1;border-radius:20px;overflow:hidden;box-shadow:0 10px 24px rgba(15,23,42,0.055);">

          <tr>
            <td style="padding:26px 30px;background:#0d1d34;color:#ffffff;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td style="vertical-align:top;">
                    <div style="font-size:11px;letter-spacing:0.14em;text-transform:uppercase;color:rgba(255,255,255,0.72);font-weight:700;">
                      IPCA.training | Compliance
                    </div>
                    <h1 style="margin:10px 0 0;font-size:24px;line-height:1.2;font-weight:760;color:#ffffff;">
                      {{EMAIL_TITLE}}
                    </h1>
                    <div style="margin-top:8px;font-size:12px;color:rgba(255,255,255,0.72);line-height:1.5;">
                      Thread reference: <strong style="color:#ffffff;">{{COMPLIANCE_THREAD_CODE}}</strong>
                    </div>
                  </td>
                  <td align="right" style="vertical-align:top;width:150px;">
                    <img src="/assets/logo/ipca_logo_white.png" alt="IPCA" style="display:block;max-width:130px;height:auto;border:0;">
                  </td>
                </tr>
              </table>

              <div style="margin-top:18px;">
                <!-- Repeat one pill per linked compliance object. Keep these visible for humans; reply headers remain the primary automated threading mechanism. -->
                <a href="{{COMPLIANCE_OBJECT_URL_1}}" style="display:inline-block;margin:0 8px 8px 0;padding:7px 12px;border-radius:999px;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.22);color:#ffffff;text-decoration:none;font-size:12px;font-weight:700;">
                  {{COMPLIANCE_OBJECT_TYPE_1}} · {{COMPLIANCE_OBJECT_CODE_1}}
                </a>
                <a href="{{COMPLIANCE_OBJECT_URL_2}}" style="display:inline-block;margin:0 8px 8px 0;padding:7px 12px;border-radius:999px;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.22);color:#ffffff;text-decoration:none;font-size:12px;font-weight:700;">
                  {{COMPLIANCE_OBJECT_TYPE_2}} · {{COMPLIANCE_OBJECT_CODE_2}}
                </a>
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:30px;font-size:15px;line-height:1.65;color:#152235;">
              <p style="margin:0 0 16px;"><strong>Dear {{RECIPIENT_NAME}} –</strong></p>
              <p style="margin:0 0 16px;">
                {{EMAIL_BODY_HTML}}
              </p>
              <p style="margin:24px 0 0;">
                Sincerely,<br><br>
                <strong>{{COMPLIANCE_MONITORING_MANAGER_NAME}}</strong><br>
                <em>{{COMPLIANCE_MONITORING_MANAGER_TITLE}}</em><br>
                {{COMPLIANCE_MONITORING_MANAGER_SIGNATURE_HTML}}
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:18px 30px;background:#f7f9fc;border-top:1px solid #e7edf5;color:#728198;font-size:12px;line-height:1.5;">
              <strong style="color:#152235;">IMPORTANT NOTES:</strong><br>
              Please keep the information in this email and any attachments strictly confidential. To preserve the compliance audit trail, please reply directly to this email using your normal Reply button and do not change the subject line, remove the thread reference, or start a new email chain for this matter.
              <br><br>
              Compliance object references: {{COMPLIANCE_OBJECT_SUMMARY_TEXT}}<br>
              Message tracking reference: {{COMPLIANCE_THREAD_CODE}}
              <br><br>
              <strong style="color:#152235;">IPCA.training</strong><br>
              Compliance Operating System<br>
              This email and any attachments may contain compliance records. Please retain according to the applicable authority and company recordkeeping requirements.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $threadIds = isset($_POST['thread_ids']) && is_array($_POST['thread_ids'])
        ? array_map('intval', $_POST['thread_ids'])
        : array();
    try {
        if ($action === 'save_email_template') {
            ComplianceCommsCenterEngine::saveEmailTemplateVersion(
                $pdo,
                ComplianceCommsCenterEngine::DEFAULT_EMAIL_TEMPLATE_KEY,
                'Default outbound compliance email',
                'Default HTML wrapper used for authority-ready outbound compliance communication.',
                (string)($_POST['subject_template'] ?? ''),
                (string)($_POST['html_template'] ?? ''),
                (string)($_POST['text_template'] ?? ''),
                (string)($_POST['allowed_variables_json'] ?? '[]'),
                (string)($_POST['change_note'] ?? ''),
                $uid > 0 ? $uid : null
            );
            cmpcc_flash('success', 'Email template saved as a new version.');
        } elseif ($action === 'bulk_status' && $threadIds !== array()) {
            $newStatus = (string)($_POST['bulk_status_value'] ?? '');
            $n = ComplianceCommsCenterEngine::bulkUpdateThreadStatus($pdo, $threadIds, $newStatus);
            cmpcc_flash('success', $n . ' thread(s) set to ' . str_replace('_', ' ', $newStatus) . '.');
        } elseif ($action === 'bulk_priority' && $threadIds !== array()) {
            $newPrio = (string)($_POST['bulk_priority_value'] ?? '');
            $n = ComplianceCommsCenterEngine::bulkUpdateThreadPriority($pdo, $threadIds, $newPrio);
            cmpcc_flash('success', $n . ' thread(s) set to priority ' . $newPrio . '.');
        } elseif ($threadIds === array()) {
            cmpcc_flash('error', 'No threads selected.');
        }
    } catch (Throwable $e) {
        cmpcc_flash('error', $e->getMessage());
    }
    redirect('/admin/compliance/inbox.php' . ($_GET ? '?' . http_build_query($_GET) : ''));
}

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
$flash = cmpcc_flash_take();
$complianceManager = ComplianceSettings::complianceManager($pdo);

$defaultEmailTemplateSubject = cmpcc_default_email_template_subject();
$defaultEmailTemplateHtml = cmpcc_default_email_template_html();
$defaultEmailTemplateText = cmpcc_default_email_template_text();
$defaultEmailTemplateAllowedJson = cmpcc_default_email_template_allowed_variables_json();
ComplianceCommsCenterEngine::ensureDefaultEmailTemplate(
    $pdo,
    $defaultEmailTemplateSubject,
    $defaultEmailTemplateHtml,
    $defaultEmailTemplateText,
    $defaultEmailTemplateAllowedJson,
    $uid > 0 ? $uid : null
);
$emailTemplateTablesPresent = ComplianceCommsCenterEngine::emailTemplateTablesPresent($pdo);
$storedEmailTemplate = ComplianceCommsCenterEngine::getCurrentEmailTemplate($pdo);
$emailTemplateVersions = ComplianceCommsCenterEngine::listEmailTemplateVersions($pdo);
$emailTemplateSubject = is_array($storedEmailTemplate) && !empty($storedEmailTemplate['subject_template'])
    ? (string)$storedEmailTemplate['subject_template'] : $defaultEmailTemplateSubject;
$emailTemplateHtml = is_array($storedEmailTemplate) && !empty($storedEmailTemplate['html_template'])
    ? (string)$storedEmailTemplate['html_template'] : $defaultEmailTemplateHtml;
$emailTemplateText = is_array($storedEmailTemplate) && !empty($storedEmailTemplate['text_template'])
    ? (string)$storedEmailTemplate['text_template'] : $defaultEmailTemplateText;
$emailTemplateAllowedJson = is_array($storedEmailTemplate) && !empty($storedEmailTemplate['allowed_variables_json'])
    ? (string)$storedEmailTemplate['allowed_variables_json'] : $defaultEmailTemplateAllowedJson;
// Full-text search across email bodies — only when the user typed something.
$searchResults = array();
if ($filterQuery !== '') {
    $searchResults = ComplianceCommsCenterEngine::searchEmails($pdo, $filterQuery, 100);
}

cw_header('Compliance · Inbox');

compliance_page_open(array(
    'overline' => 'Compliance · Comms Center',
    'title' => 'Compliance inbox',
    'description' => 'Every email routed through ' . ($summary['inbox_address'] !== '' ? (string)$summary['inbox_address'] : 'compliance@ipca.training') . ' arrives here via the Postmark inbound webhook. Threads auto-group from In-Reply-To / References headers, falling back to mailbox-hash or normalised subject + sender.',
    'actions' => array(
        array('label' => 'New message', 'href' => '/admin/compliance/email_compose.php', 'icon' => 'plus'),
        array('label' => 'Drafts',      'href' => '/admin/compliance/email_drafts.php', 'icon' => 'doc'),
        array('label' => 'Settings',    'modal' => 'inboxIntegrationModal', 'icon' => 'settings'),
    ),
    'stats' => array(
        array('label' => 'Open threads',     'value' => (int)$stats['open'],              'href' => '/admin/compliance/inbox.php?status=open',              'tone' => (int)$stats['open'] > 0 ? 'warn' : 'ok'),
        array('label' => 'Waiting internal', 'value' => (int)$stats['waiting_internal'],  'href' => '/admin/compliance/inbox.php?status=waiting_internal'),
        array('label' => 'Waiting external', 'value' => (int)$stats['waiting_external'],  'href' => '/admin/compliance/inbox.php?status=waiting_external'),
        array('label' => 'Unlinked',         'value' => (int)$stats['unlinked'],          'href' => '/admin/compliance/inbox.php?linked=unlinked'),
        array('label' => 'With attachments', 'value' => (int)$stats['has_attachments'],   'href' => '/admin/compliance/inbox.php?attachments=1'),
        array('label' => 'Closed',           'value' => (int)$stats['closed'],            'href' => '/admin/compliance/inbox.php?status=closed', 'tone' => 'ok'),
    ),
    'flash' => $flash,
));
?>
<style>
  .cmpcc-card{margin-bottom:18px;}
  .cmpcc-table{width:100%;border-collapse:separate;border-spacing:0;}
  .cmpcc-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;color:var(--text-muted);}
  .cmpcc-help code{background:#eef2ff;color:#3730a3;padding:1px 6px;border-radius:6px;font-size:12px;}
  .cmpcc-help ul{margin:6px 0 0;padding-left:18px;}
  .cmpcc-help li{margin-bottom:4px;}
  .cmpcc-empty{padding:18px;color:var(--text-muted);text-align:center;background:#f6f9fd;border-radius:12px;}
  .cmpcc-flex{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
  .cmpcc-clear{font-size:12px;font-weight:700;color:#1f4079;text-decoration:none;margin-left:6px;}
  .cmpcc-bulk{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px;}
  .cmpcc-bulk select{height:36px;min-height:36px;padding:0 10px !important;font-size:12px !important;}
  .cmpcc-bulk button{height:36px;min-height:36px;padding:0 12px !important;font-size:12px !important;}
  #inboxIntegrationModal{width:min(980px,calc(100vw - 32px));}
  .cmpcc-template-card{margin-top:18px;padding:18px;border:1px solid var(--border-soft);border-radius:18px;background:#f8fafd;}
  .cmpcc-template-preview{margin-top:12px;border:1px solid #dfe6f1;border-radius:18px;overflow:hidden;background:#fff;box-shadow:var(--card-shadow);}
  .cmpcc-template-preview-head{padding:22px 24px;background:#0d1d34;color:#fff;}
  .cmpcc-template-preview-headrow{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;}
  .cmpcc-template-preview-logo{display:block;max-width:130px;height:auto;border:0;flex:0 0 auto;}
  .cmpcc-template-preview-kicker{font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.72);font-weight:700;}
  .cmpcc-template-preview-title{margin:8px 0 0;font-size:22px;line-height:1.2;color:#fff;font-weight:760;}
  .cmpcc-template-preview-pills{margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;}
  .cmpcc-template-preview-pill{display:inline-flex;align-items:center;min-height:28px;padding:0 12px;border-radius:999px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);color:#fff;font-size:12px;font-weight:700;text-decoration:none;}
  .cmpcc-template-vars{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:8px;margin:12px 0 0;padding:0;list-style:none;}
  .cmpcc-template-vars li{padding:8px 10px;border:1px solid var(--border-soft);border-radius:12px;background:#fff;font-size:12px;color:var(--text-muted);}
  .cmpcc-template-vars code{display:block;margin-bottom:3px;color:#1f4079;background:#eef2ff;padding:2px 6px;border-radius:7px;font-size:11px;}
  .cmpcc-template-preview-body{padding:24px;color:var(--text-strong);font-size:14px;line-height:1.65;}
  .cmpcc-template-preview-foot{padding:16px 24px;background:#f7f9fc;border-top:1px solid #e7edf5;color:var(--text-muted);font-size:12px;line-height:1.5;}
  .cmpcc-template-code{margin-top:12px;width:100%;min-height:220px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;line-height:1.45;}
  .cmpcc-template-code.is-small{min-height:120px;}
  .cmpcc-template-edit-grid{display:grid;grid-template-columns:1fr;gap:12px;margin-top:14px;}
  .cmpcc-template-version-list{margin:12px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:8px;}
  .cmpcc-template-version-list li{display:flex;justify-content:space-between;gap:12px;padding:10px 12px;border:1px solid var(--border-soft);border-radius:12px;background:#fff;color:var(--text-muted);font-size:12px;}
</style>

<section class="cmp-card cmp-toolbar">
    <div class="cmp-toolbar-head">
      <div class="cmp-toolbar-title">
        <?= compliance_ui_icon('filter') ?>
        <span>Filter and search</span>
      </div>
      <div class="cmp-toolbar-meta">Search inside subject + body, or narrow by status, priority and linkage.</div>
    </div>
    <form method="get">
      <div class="cmp-toolbar-row">
      <label class="cmp-field">
        <span>Search (subject + body)</span>
        <input type="search" name="q" placeholder="any phrase — searches inside email bodies…" value="<?= h($filterQuery) ?>">
      </label>
      <label class="cmp-field">
        <span>Status</span>
        <select name="status">
          <option value="">All</option>
          <?php foreach (array('open','waiting_internal','waiting_external','closed','archived') as $s): ?>
            <option value="<?= h($s) ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= h(str_replace('_', ' ', $s)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="cmp-field">
        <span>Priority</span>
        <select name="priority">
          <option value="">All</option>
          <?php foreach (array('low','normal','high','urgent') as $p): ?>
            <option value="<?= h($p) ?>" <?= $filterPriority === $p ? 'selected' : '' ?>><?= h($p) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="cmp-field">
        <span>Linked</span>
        <select name="linked">
          <option value="">All</option>
          <option value="linked" <?= $filterLinked === 'linked' ? 'selected' : '' ?>>Linked only</option>
          <option value="unlinked" <?= $filterLinked === 'unlinked' ? 'selected' : '' ?>>Unlinked only</option>
        </select>
      </label>
      </div>
      <div class="cmp-toolbar-actions">
        <button type="submit">Apply filters</button>
        <?php if ($filterStatus !== '' || $filterPriority !== '' || $filterAttachments || $filterLinked !== '' || $filterQuery !== ''): ?>
          <a class="cmp-btn-secondary cmp-btn-link" href="/admin/compliance/inbox.php" style="text-decoration:none;">Clear filters</a>
        <?php endif; ?>
        <label style="display:inline-flex;gap:6px;align-items:center;font-size:13px;color:var(--text-muted);margin-left:6px;">
          <input type="checkbox" form="hiddenAttForm" disabled <?= $filterAttachments ? 'checked' : '' ?>>
          <span>Has attachments</span>
        </label>
      </div>
    </form>
  </section>

  <?php if ($filterQuery !== '' && $searchResults !== array()): ?>
    <section class="cmp-card cmpcc-card">
      <h2 style="margin:0 0 6px;font-size:16px;">Search results for "<?= h($filterQuery) ?>"</h2>
      <p style="margin:0 0 12px;color:#64748b;font-size:13px;">
        Matching individual emails (across subject + body).
        <a href="/admin/compliance/inbox.php" style="color:#1e3c72;font-weight:700;">Clear search</a>.
      </p>
      <div class="compliance-table-wrap">
      <table class="cmpcc-table compliance-table">
        <thead><tr>
          <th>Direction</th>
          <th>Subject</th>
          <th>Contact / Recipient</th>
          <th>Status</th>
          <th>When</th>
          <th>Thread</th>
        </tr></thead>
        <tbody>
          <?php foreach ($searchResults as $r):
            $eid = (int)$r['id'];
            $tid = (int)$r['thread_id'];
            $direction = (string)$r['direction'];
            $threadStatus = (string)($r['thread_status'] ?? 'open');
            $when = (string)($r['received_at'] ?? $r['sent_at'] ?? '');
          ?>
            <tr>
              <td><strong style="font-size:11px;letter-spacing:.05em;color:<?= $direction === 'inbound' ? '#1e3a8a' : '#0f766e' ?>;"><?= h(strtoupper($direction)) ?></strong></td>
              <td>
                <a href="/admin/compliance/email_thread.php?email_id=<?= $eid ?>"
                   style="color:#1e3c72;font-weight:700;text-decoration:none;">
                  <?= h((string)($r['subject'] ?? '(no subject)')) ?>
                </a>
              </td>
              <td class="cmpcc-mono"><?= h((string)($r['from_email'] ?? $r['thread_contact'] ?? '—')) ?></td>
              <td><span class="cmpcc-pill s-<?= h($threadStatus) ?>"><?= h(str_replace('_', ' ', $threadStatus)) ?></span></td>
              <td class="cmpcc-mono"><?= h(substr($when, 0, 16)) ?></td>
              <td>
                <?php if ($tid > 0): ?>
                  <a href="/admin/compliance/email_thread.php?id=<?= $tid ?>"
                     style="color:#3730a3;font-weight:700;text-decoration:none;font-size:12px;">
                    thread #<?= $tid ?>
                  </a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </section>
  <?php elseif ($filterQuery !== '' && $searchResults === array()): ?>
    <section class="cmp-card cmpcc-card">
      <div class="cmpcc-empty">
        No emails match "<?= h($filterQuery) ?>". <a href="/admin/compliance/inbox.php" style="color:#1f4079;font-weight:700;">Clear search</a>.
      </div>
    </section>
  <?php endif; ?>

  <section class="cmp-card cmpcc-card">
    <div class="cmp-list-head" style="margin-bottom:14px;">
      <div class="cmp-list-title">
        <?= compliance_ui_icon('inbox') ?>
        <span>Thread roster</span>
      </div>
      <div class="cmp-count-pill"><?= count($threads) ?> thread<?= count($threads) === 1 ? '' : 's' ?></div>
    </div>
    <?php if ($threads === array()): ?>
      <div class="cmpcc-empty">
        No threads in scope. Either nothing has arrived yet, or your filter excludes everything on file.
      </div>
    <?php else: ?>
      <form method="post" action="/admin/compliance/inbox.php<?= $_GET ? '?' . h(http_build_query($_GET)) : '' ?>" id="inboxBulkForm">
        <div class="cmpcc-bulk">
          <strong id="inboxBulkSummary" style="font-size:13px;color:var(--text-strong);">0 selected</strong>
          <select name="bulk_status_value">
            <option value="open">open</option>
            <option value="waiting_internal">waiting internal</option>
            <option value="waiting_external">waiting external</option>
            <option value="closed">closed</option>
            <option value="archived" selected>archived</option>
          </select>
          <button type="submit" name="action" value="bulk_status"
                  onclick="return confirm('Apply this status to all selected threads?');">
            Set status
          </button>
          <select name="bulk_priority_value" style="margin-left:8px;">
            <option value="low">low</option>
            <option value="normal">normal</option>
            <option value="high">high</option>
            <option value="urgent">urgent</option>
          </select>
          <button type="submit" name="action" value="bulk_priority" class="cmp-btn-secondary"
                  onclick="return confirm('Apply this priority to all selected threads?');">
            Set priority
          </button>
        </div>
        <div class="compliance-table-wrap">
        <table class="cmpcc-table compliance-table">
          <thead><tr>
            <th style="width:30px;"><input type="checkbox" id="inboxSelectAll"></th>
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
                <td>
                  <input type="checkbox" class="inboxRowCheck"
                         name="thread_ids[]" value="<?= $tid ?>">
                </td>
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
        </div>
      </form>
      <script>
        (function () {
          var all = document.getElementById('inboxSelectAll');
          var rows = document.querySelectorAll('.inboxRowCheck');
          var summary = document.getElementById('inboxBulkSummary');
          function recount() {
            var n = 0;
            rows.forEach(function (cb) { if (cb.checked) { n++; } });
            if (summary) { summary.textContent = n + ' selected'; }
          }
          if (all) {
            all.addEventListener('change', function () {
              rows.forEach(function (cb) { cb.checked = all.checked; });
              recount();
            });
          }
          rows.forEach(function (cb) { cb.addEventListener('change', recount); });
        })();
      </script>
    <?php endif; ?>
  </section>

  <?php compliance_modal_open('inboxIntegrationModal', 'Webhook & integration status'); ?>
    <div class="compliance-table-wrap">
    <table class="cmpcc-table compliance-table" style="margin-bottom:14px;">
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
            <?= $summary['tracking_webhook_url'] !== '' ? h((string)$summary['tracking_webhook_url']) : '<em style="color:#b91c1c;">set CW_PUBLIC_BASE_URL + POSTMARK_TRACKING_WEBHOOK_SECRET</em>' ?>
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
    </div>

    <div class="cmpcc-help">
      <strong style="color:#0f172a;">Setup checklist</strong>
      <ul>
        <li>Create <code><?= h((string)($summary['inbox_address'] !== '' ? $summary['inbox_address'] : 'compliance@ipca.training')) ?></code> in Google Workspace (MFA, retention, DKIM/SPF/DMARC).</li>
        <li>In Google Workspace, configure a forwarding/copy rule from the mailbox to your Postmark Inbound address (e.g. <code>&lt;hash&gt;@inbound.postmarkapp.com</code>).</li>
        <li>In Postmark → Servers → Inbound, paste the <em>Inbound webhook URL</em> above into "Webhook URL".</li>
        <li>In Postmark → Servers → Message Streams → outbound → Webhooks, paste the <em>Tracking webhook URL</em> above and enable Delivery, Bounce, Open, Click and Spam Complaint events.</li>
        <li>
          Inject the following variables on the host (no <code>.env</code> file — this platform uses PHP-FPM pool config for web/webhooks
          and <code>/etc/ipca/ipca-courseware-cli.env</code> for CLI):
          <ul style="margin-top:4px;">
            <li><code>POSTMARK_SERVER_TOKEN</code> &nbsp;— Postmark server API token (used for outbound).</li>
            <li><code>POSTMARK_INBOUND_WEBHOOK_SECRET</code> &nbsp;— shared secret carried in the inbound webhook URL.</li>
            <li><code>POSTMARK_TRACKING_WEBHOOK_SECRET</code> &nbsp;— shared secret for delivery / open / bounce events.</li>
            <li><code>COMPLIANCE_INBOX_ADDRESS</code> &nbsp;— public compliance mailbox (e.g. <code>compliance@ipca.training</code>).</li>
            <li><code>COMPLIANCE_POSTMARK_FROM</code> &nbsp;— optional, defaults to <code>COMPLIANCE_INBOX_ADDRESS</code>.</li>
            <li><code>POSTMARK_OUTBOUND_STREAM</code> &nbsp;— optional, defaults to <code>outbound</code>.</li>
            <li><code>CW_PUBLIC_BASE_URL</code> &nbsp;— platform base URL (so this panel can render the full webhook URLs).</li>
          </ul>
        </li>
        <li>Reload PHP-FPM after editing the pool file so the webhook process sees the new vars: <code>systemctl reload php8.3-fpm</code>.</li>
        <li>Send a test email <em>to</em> the mailbox to verify inbound, then use the <em>New message</em> button to send an outbound test and watch for the Delivery event on the thread.</li>
      </ul>
    </div>
    <div class="cmpcc-template-card">
      <h3 style="margin:0 0 6px;">Styled outbound email HTML template</h3>
      <p style="margin:0;color:var(--text-muted);font-size:13px;line-height:1.5;">
        Use this wrapper for authority-ready outbound compliance emails. Header pills make the linked compliance objects visible to the recipient; email reply headers remain the primary automatic thread-tracking mechanism.
      </p>
      <ul class="cmpcc-template-vars">
        <li><code>{{COMPLIANCE_THREAD_CODE}}</code> Stable visible thread/reference code, also repeated in the footer.</li>
        <li><code>{{COMPLIANCE_OBJECT_TYPE_N}}</code> Human label such as Finding, Audit, CAP, MoC.</li>
        <li><code>{{COMPLIANCE_OBJECT_CODE_N}}</code> Object code such as NCR-2026-0042 or AUD-2026-001.</li>
        <li><code>{{COMPLIANCE_OBJECT_URL_N}}</code> Internal object URL used by the header pill link.</li>
        <li><code>{{COMPLIANCE_OBJECT_SUMMARY_TEXT}}</code> Plain-text fallback list for the footer.</li>
        <li><code>{{EMAIL_BODY_HTML}}</code> Sanitized outbound body content.</li>
      </ul>
      <div class="cmpcc-template-preview" aria-label="Email template preview">
        <div class="cmpcc-template-preview-head">
          <div class="cmpcc-template-preview-headrow">
            <div>
              <div class="cmpcc-template-preview-kicker">IPCA.training | Compliance</div>
              <div class="cmpcc-template-preview-title">Compliance Communication</div>
              <div style="margin-top:8px;font-size:12px;color:rgba(255,255,255,.72);line-height:1.5;">
                Thread reference: <strong style="color:#fff;">CMT-2026-00042</strong>
              </div>
            </div>
            <img class="cmpcc-template-preview-logo" src="/assets/logo/ipca_logo_white.png" alt="IPCA">
          </div>
          <div class="cmpcc-template-preview-pills">
            <span class="cmpcc-template-preview-pill">Finding · NCR-2026-0042</span>
            <span class="cmpcc-template-preview-pill">Audit · AUD-2026-001</span>
          </div>
        </div>
        <div class="cmpcc-template-preview-body">
          <p style="margin:0 0 12px;"><strong>Dear Recipient –</strong></p>
          <p style="margin:0 0 12px;">Your message content goes here. Keep the wording clear, authority-ready, and concise.</p>
          <p style="margin:18px 0 0;">Sincerely,<br><br><strong><?= h((string)$complianceManager['name']) ?></strong><br><em><?= h((string)$complianceManager['title']) ?></em><br><?= nl2br(h((string)$complianceManager['signature'])) ?></p>
        </div>
        <div class="cmpcc-template-preview-foot">
          <strong style="color:#152235;">IMPORTANT NOTES:</strong><br>
          Please keep the information in this email and any attachments strictly confidential. To preserve the compliance audit trail, please reply directly to this email using your normal Reply button and do not change the subject line, remove the thread reference, or start a new email chain for this matter.
          <br><br>
          <strong style="color:#152235;">IPCA.training</strong><br>
          Compliance Operating System<br>
          This email and any attachments may contain compliance records. Please retain according to the applicable authority and company recordkeeping requirements.
        </div>
      </div>
      <label class="cmp-field" style="margin-top:14px;">
        <span>Current HTML template source</span>
        <textarea class="cmpcc-template-code" readonly><?= h($emailTemplateHtml) ?></textarea>
      </label>
      <?php if (!$emailTemplateTablesPresent): ?>
        <p class="cmp-flash is-warn" style="margin:14px 0 0;">
          Template storage is not active yet. Apply <code>scripts/sql/compliance_os_phase_8_6_email_templates.sql</code> to enable editing and version history.
        </p>
      <?php else: ?>
        <form method="post" class="cmpcc-template-edit-grid">
          <input type="hidden" name="action" value="save_email_template">
          <label class="cmp-field">
            <span>Subject template</span>
            <input name="subject_template" required value="<?= h($emailTemplateSubject) ?>">
          </label>
          <label class="cmp-field">
            <span>Editable HTML template</span>
            <textarea name="html_template" class="cmpcc-template-code" required><?= h($emailTemplateHtml) ?></textarea>
          </label>
          <label class="cmp-field">
            <span>Plain-text fallback template</span>
            <textarea name="text_template" class="cmpcc-template-code is-small"><?= h($emailTemplateText) ?></textarea>
          </label>
          <label class="cmp-field">
            <span>Allowed variables JSON</span>
            <textarea name="allowed_variables_json" class="cmpcc-template-code is-small" required><?= h($emailTemplateAllowedJson) ?></textarea>
          </label>
          <label class="cmp-field">
            <span>Change note</span>
            <input name="change_note" placeholder="What changed in this version?">
          </label>
          <div class="cmp-toolbar-actions" style="margin:0;">
            <button type="submit">Save new template version</button>
          </div>
        </form>
        <div style="margin-top:16px;">
          <h4 style="margin:0 0 8px;">Recent versions</h4>
          <?php if ($emailTemplateVersions === array()): ?>
            <p style="margin:0;color:var(--text-muted);font-size:13px;">No saved versions yet.</p>
          <?php else: ?>
            <ul class="cmpcc-template-version-list">
              <?php foreach ($emailTemplateVersions as $v): ?>
                <li>
                  <span><strong>v<?= (int)$v['version_no'] ?></strong> · <?= h((string)$v['subject_template']) ?></span>
                  <span><?= h(substr((string)$v['created_at'], 0, 16)) ?><?= !empty($v['change_note']) ? ' · ' . h((string)$v['change_note']) : '' ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="compliance-modal__footer">
      <button type="button" class="cmp-btn-secondary" data-compliance-modal-close>Close</button>
    </div>
  <?php compliance_modal_close(); ?>
<?php
compliance_page_close();
cw_footer();
