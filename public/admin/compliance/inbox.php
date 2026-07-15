<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/CompliancePostmarkConfig.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsCenterEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceMailUi.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

function cmpmail_flash(string $type, string $msg): void
{
    $_SESSION['_ipca_compliance_flash_inbox'] = array('type' => $type, 'message' => $msg);
}

function cmpmail_flash_take(): ?array
{
    if (empty($_SESSION['_ipca_compliance_flash_inbox']) || !is_array($_SESSION['_ipca_compliance_flash_inbox'])) {
        return null;
    }
    $f = $_SESSION['_ipca_compliance_flash_inbox'];
    unset($_SESSION['_ipca_compliance_flash_inbox']);
    return $f;
}

function cmpmail_default_email_template_subject(): string
{
    return '[{{COMPLIANCE_THREAD_CODE}}] {{EMAIL_TITLE}}';
}

function cmpmail_default_email_template_text(): string
{
    return "Dear {{RECIPIENT_NAME}},\n\n{{EMAIL_BODY_TEXT}}\n\nSincerely,\n{{COMPLIANCE_MONITORING_MANAGER_NAME}}\n{{COMPLIANCE_MONITORING_MANAGER_SIGNATURE_TEXT}}\n\nIMPORTANT NOTES:\nPlease keep the information in this email and any attachments strictly confidential. To preserve the compliance audit trail, please reply directly to this email using your normal Reply button and do not change the subject line, remove the thread reference, or start a new email chain for this matter.\n\nCompliance object references: {{COMPLIANCE_OBJECT_SUMMARY_TEXT}}\nMessage tracking reference: {{COMPLIANCE_THREAD_CODE}}";
}

function cmpmail_default_email_template_html(): string
{
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0;padding:0;background:#f3f6fb;font-family:Arial,Helvetica,sans-serif;color:#152235;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f6fb;padding:28px 12px;"><tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:720px;background:#ffffff;border:1px solid #dfe6f1;border-radius:20px;overflow:hidden;"><tr><td style="padding:26px 30px;background:#0d1d34;color:#ffffff;"><div style="font-size:11px;letter-spacing:0.14em;text-transform:uppercase;color:rgba(255,255,255,0.72);font-weight:700;">IPCA.training | Compliance</div><h1 style="margin:10px 0 0;font-size:24px;line-height:1.2;color:#ffffff;">{{EMAIL_TITLE}}</h1><div style="margin-top:8px;font-size:12px;color:rgba(255,255,255,0.72);">Thread reference: <strong style="color:#ffffff;">{{COMPLIANCE_THREAD_CODE}}</strong></div><div style="margin-top:18px;">{{COMPLIANCE_OBJECT_PILLS_HTML}}</div></td></tr><tr><td style="padding:30px;font-size:15px;line-height:1.65;color:#152235;">{{EMAIL_BODY_HTML}}<p style="margin:24px 0 0;">Sincerely,<br><br><strong>{{COMPLIANCE_MONITORING_MANAGER_NAME}}</strong><br><em>{{COMPLIANCE_MONITORING_MANAGER_TITLE}}</em><br>{{COMPLIANCE_MONITORING_MANAGER_SIGNATURE_HTML}}</p></td></tr><tr><td style="padding:18px 30px;background:#f7f9fc;border-top:1px solid #e7edf5;color:#728198;font-size:12px;line-height:1.5;"><strong style="color:#152235;">IMPORTANT NOTES:</strong><br>Please keep the information in this email and any attachments strictly confidential. Compliance object references: {{COMPLIANCE_OBJECT_SUMMARY_TEXT}}<br>Message tracking reference: {{COMPLIANCE_THREAD_CODE}}</td></tr></table></td></tr></table></body></html>';
}

function cmpmail_default_email_template_allowed_variables_json(): string
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
        'COMPLIANCE_OBJECT_SUMMARY_TEXT',
        'COMPLIANCE_OBJECT_PILLS_HTML',
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
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
            cmpmail_flash('success', 'Email template saved as a new version.');
        } elseif ($action === 'bulk_status') {
            $threadIds = isset($_POST['thread_ids']) && is_array($_POST['thread_ids']) ? array_map('intval', $_POST['thread_ids']) : array();
            $n = ComplianceCommsCenterEngine::bulkUpdateThreadStatus($pdo, $threadIds, (string)($_POST['bulk_status_value'] ?? ''));
            cmpmail_flash('success', $n . ' conversation(s) updated.');
        } elseif ($action === 'bulk_priority') {
            $threadIds = isset($_POST['thread_ids']) && is_array($_POST['thread_ids']) ? array_map('intval', $_POST['thread_ids']) : array();
            $n = ComplianceCommsCenterEngine::bulkUpdateThreadPriority($pdo, $threadIds, (string)($_POST['bulk_priority_value'] ?? ''));
            cmpmail_flash('success', $n . ' conversation(s) updated.');
        }
    } catch (Throwable $e) {
        cmpmail_flash('error', $e->getMessage());
    }
    redirect('/admin/compliance/inbox.php');
}

$folder = (string)($_GET['folder'] ?? 'inbox');
$q = trim((string)($_GET['q'] ?? ''));
$filters = array();
if ($folder === 'waiting_external') { $filters['status'] = 'waiting_external'; }
if ($folder === 'waiting_internal') { $filters['status'] = 'waiting_internal'; }
if ($folder === 'closed') { $filters['status'] = 'closed'; }
if ($folder === 'archive') { $filters['status'] = 'archived'; }
if ($folder === 'sent') { $filters['has_outbound'] = true; }
if ($q !== '') { $filters['q'] = $q; }

$draftRows = $folder === 'drafts' ? ComplianceCommsCenterEngine::listDrafts($pdo, array(), 200) : array();
$threads = $folder === 'drafts' ? array() : ComplianceCommsCenterEngine::listThreads($pdo, $filters, 200);
$stats = ComplianceCommsCenterEngine::threadStats($pdo);
$summary = CompliancePostmarkConfig::publicSummary();
$flash = cmpmail_flash_take();
$sentCount = 0;
try {
    $sentCount = (int)$pdo->query("SELECT COUNT(DISTINCT thread_id) FROM ipca_compliance_emails WHERE direction = 'outbound' AND thread_id IS NOT NULL")->fetchColumn();
} catch (Throwable) {
    $sentCount = 0;
}

$defaultSubject = cmpmail_default_email_template_subject();
$defaultHtml = cmpmail_default_email_template_html();
$defaultText = cmpmail_default_email_template_text();
$defaultAllowed = cmpmail_default_email_template_allowed_variables_json();
ComplianceCommsCenterEngine::ensureDefaultEmailTemplate($pdo, $defaultSubject, $defaultHtml, $defaultText, $defaultAllowed, $uid > 0 ? $uid : null);
$templateTablesPresent = ComplianceCommsCenterEngine::emailTemplateTablesPresent($pdo);
$template = ComplianceCommsCenterEngine::getCurrentEmailTemplate($pdo);
$templateVersions = ComplianceCommsCenterEngine::listEmailTemplateVersions($pdo);
$emailTemplateSubject = is_array($template) && !empty($template['subject_template']) ? (string)$template['subject_template'] : $defaultSubject;
$emailTemplateHtml = is_array($template) && !empty($template['html_template']) ? (string)$template['html_template'] : $defaultHtml;
$emailTemplateText = is_array($template) && !empty($template['text_template']) ? (string)$template['text_template'] : $defaultText;
$emailTemplateAllowed = is_array($template) && !empty($template['allowed_variables_json']) ? (string)$template['allowed_variables_json'] : $defaultAllowed;

$selectedThreadId = isset($_GET['thread_id']) ? (int)$_GET['thread_id'] : (int)($threads[0]['id'] ?? 0);
$folders = array(
    'inbox' => array('label' => 'Inbox', 'count' => (int)$stats['open']),
    'waiting_external' => array('label' => 'Waiting External', 'count' => (int)$stats['waiting_external']),
    'waiting_internal' => array('label' => 'Waiting Internal', 'count' => (int)$stats['waiting_internal']),
    'drafts' => array('label' => 'Drafts', 'count' => count(ComplianceCommsCenterEngine::listDrafts($pdo, array(), 200))),
    'sent' => array('label' => 'Sent', 'count' => $sentCount),
    'archive' => array('label' => 'Archive', 'count' => (int)$stats['archived']),
    'closed' => array('label' => 'Closed', 'count' => (int)$stats['closed']),
);
$selectedFolderLabel = (string)($folders[$folder]['label'] ?? 'All Mailboxes');
$selectedMailboxTitle = $folder === 'inbox' ? 'All Inboxes' : $selectedFolderLabel;
$selectedMailboxCount = (int)($folders[$folder]['count'] ?? count($threads));

cw_header('Compliance Mail');
?>
<style>
  .app-main,.app-content{overflow:hidden;}
  .mail-page{height:calc(100vh - 132px);min-height:0;display:flex;flex-direction:column;gap:12px;overflow:hidden;background:#fff;}
  .mail-page:fullscreen{height:100vh;padding:16px;background:#fff;}
  .mail-page.is-fullscreen{height:100vh;padding:16px;background:#fff;}
  #mailHero.mail-top{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 22px;border:1px solid rgba(255,255,255,.18);border-radius:24px;background:linear-gradient(135deg,#0d1d34 0%,#1e3c72 58%,#27538f 100%) !important;box-shadow:0 18px 42px rgba(15,23,42,.14);color:#fff;}
  #mailHero.mail-top h1{margin:0;font-size:26px;letter-spacing:-.03em;color:#fff;}
  #mailHero.mail-top p{margin:4px 0 0;color:rgba(255,255,255,.74);font-size:13px;}
  .mail-client-box{flex:1;min-height:0;display:flex;flex-direction:column;border:1px solid rgba(15,23,42,.07);border-radius:28px;background:#fff;box-shadow:0 22px 54px rgba(15,23,42,.075);}
  .mail-toolbar{--mail-folder-width:220px;--mail-list-width:360px;position:sticky;top:0;z-index:45;display:grid;grid-template-columns:var(--mail-folder-width) 2px var(--mail-list-width) 2px minmax(0,1fr);align-items:center;gap:0;border:0;border-bottom:1px solid rgba(15,23,42,.07);border-radius:28px 28px 0 0;background:rgba(248,250,252,.98);box-shadow:none;overflow:visible;}
  .mail-toolbar.folders-collapsed{grid-template-columns:28px 2px var(--mail-list-width) 2px minmax(0,1fr);}
  .mail-toolbar-left,.mail-toolbar-center,.mail-toolbar-actions{min-width:0;display:flex;align-items:center;gap:8px;padding:7px 14px;}
  .mail-toolbar-left{grid-column:1;}
  .mail-toolbar-center{grid-column:3;}
  .mail-toolbar-actions{grid-column:5;}
  .mail-toolbar-divider{align-self:stretch;background:rgba(15,23,42,.07);}
  .mail-toolbar-divider.folders{grid-column:2;}
  .mail-toolbar-divider.list{grid-column:4;}
  .mail-toolbar.folders-collapsed .mail-toolbar-divider.folders{display:block;grid-column:2;}
  .mail-toolbar.folders-collapsed .mail-toolbar-center{grid-column:3;}
  .mail-toolbar.folders-collapsed .mail-toolbar-actions{grid-column:5;}
  .mail-toolbar-center .mail-toolbar-title{flex:1;min-width:0;}
  .mail-toolbar-title strong{display:block;font-size:13px;line-height:1.1;color:#152235;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .mail-toolbar-title span{display:block;margin-top:2px;font-size:11px;line-height:1.15;color:#728198;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .mail-toolbar-actions{justify-content:flex-end;flex-wrap:nowrap;}
  .mail-toolbar-group{display:inline-flex;align-items:center;gap:2px;padding:2px;border-radius:999px;background:#fff;box-shadow:0 5px 16px rgba(15,23,42,.045);}
  .mail-toolbar-btn,.mail-toolbar-link{width:30px;height:30px;border:0;border-radius:999px;background:#fff;color:#243247;display:inline-flex;align-items:center;justify-content:center;font-size:15px;font-weight:850;text-decoration:none;cursor:pointer;}
  .mail-toolbar-btn:hover,.mail-toolbar-link:hover{background:#eef4ff;color:#1e3c72;}
  .mail-toolbar-menu{position:relative;}
  .mail-toolbar-menu summary{list-style:none;width:30px;height:30px;border-radius:999px;background:#fff;color:#243247;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:850;cursor:pointer;}
  .mail-toolbar-menu summary::-webkit-details-marker{display:none;}
  .mail-toolbar-popover{position:absolute;top:calc(100% + 6px);left:0;z-index:60;min-width:170px;display:grid;gap:8px;padding:10px;border:1px solid rgba(15,23,42,.08);border-radius:14px;background:#fff;box-shadow:0 20px 48px rgba(15,23,42,.18);}
  .mail-toolbar-popover label{display:grid;gap:5px;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#728198;font-weight:850;}
  .mail-toolbar-popover select{height:32px;border:1px solid rgba(15,23,42,.08);border-radius:10px;background:#fff;color:#243247;padding:0 9px;font-size:12px;font-weight:760;}
  .mail-toolbar-popover button{height:32px;border:0;border-radius:10px;background:#1e3c72;color:#fff;font-size:12px;font-weight:850;cursor:pointer;}
  .mail-selected-count{font-size:11px;color:#728198;white-space:nowrap;padding:0 4px;}
  .mail-toolbar-search{height:34px;min-width:220px;width:min(360px,32vw);border:0;border-radius:999px;background:#fff;color:#152235;padding:0 15px;font-size:13px;box-shadow:0 5px 16px rgba(15,23,42,.045);}
  .mail-action{border:1px solid rgba(15,23,42,.08);border-radius:999px;background:#fff;color:#1e3c72;padding:9px 14px;font-size:13px;font-weight:760;text-decoration:none;cursor:pointer;}
  .mail-workspace{--mail-folder-width:220px;--mail-list-width:360px;--mail-compliance-width:340px;flex:1;min-height:0;display:grid;grid-template-columns:var(--mail-folder-width) 2px var(--mail-list-width) 2px minmax(0,1fr);gap:0;border:0;border-radius:0 0 28px 28px;background:#fff;box-shadow:none;overflow:hidden;}
  .mail-workspace.folders-collapsed{grid-template-columns:28px var(--mail-list-width) 2px minmax(0,1fr);}
  .mail-workspace.folders-collapsed .mail-folders{display:none;}
  .mail-workspace.folders-collapsed .mail-resizer[data-resizer="folders"]{grid-column:1;cursor:pointer;background:#f0f4fb;}
  .mail-workspace.folders-collapsed .mail-resizer[data-resizer="folders"]::before{content:"›";position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:18px;height:34px;border-radius:999px;background:#fff;border:1px solid rgba(15,23,42,.08);color:#1e3c72;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:800;box-shadow:0 8px 20px rgba(15,23,42,.08);}
  .mail-workspace.folders-collapsed .mail-resizer[data-resizer="folders"]::after{display:none;}
  .mail-workspace.folders-collapsed .mail-list-pane{grid-column:2;}
  .mail-workspace.folders-collapsed .mail-resizer[data-resizer="list"]{grid-column:3;}
  .mail-workspace.folders-collapsed .mail-reader-pane{grid-column:4;}
  .mail-resizer{position:relative;background:rgba(15,23,42,.07);cursor:col-resize;z-index:8;touch-action:none;}
  .mail-resizer::after{content:"";position:absolute;top:0;bottom:0;left:0;width:1px;background:rgba(15,23,42,.08);transition:background .12s ease,width .12s ease;}
  .mail-resizer:hover::after,.mail-resizer.is-dragging::after{width:2px;background:rgba(30,60,114,.32);}
  .mail-workspace.is-resizing{user-select:none;cursor:col-resize;}
  .mail-folders{background:#f5f7fb;border-right:1px solid rgba(15,23,42,.06);padding:16px 12px;overflow:auto;}
  .mail-folder{width:100%;display:flex;justify-content:space-between;align-items:center;gap:8px;border:0;border-radius:14px;background:transparent;padding:11px 12px;color:#334155;font-weight:760;cursor:pointer;text-align:left;}
  .mail-folder:hover,.mail-folder.is-active{background:#e9eef8;color:#152235;}
  .mail-folder span:last-child{color:#728198;font-size:12px;}
  .mail-list-pane{display:flex;flex-direction:column;min-width:0;min-height:0;overflow:hidden;background:#fff;}
  .mail-thread-list{overflow-y:auto;overflow-x:hidden;min-height:0;padding:8px 10px 8px 12px;box-sizing:border-box;}
  .mail-thread-card{position:relative;box-sizing:border-box;max-width:100%;width:100%;display:grid;grid-template-columns:18px 8px minmax(0,1fr);gap:7px;border:0;border-radius:11px;background:transparent;padding:8px 10px;text-align:left;cursor:pointer;color:#152235;transition:background .15s ease,box-shadow .15s ease,transform .15s ease;}
  .mail-thread-card:hover{background:#f0f4fb;}
  .mail-thread-card.is-selected{background:#eaf1fb;box-shadow:inset 0 0 0 1px rgba(30,60,114,.12);padding-right:48px;}
  .mail-thread-select input{margin-top:2px;width:13px;height:13px;}
  .mail-thread-unread{width:7px;height:7px;border-radius:999px;background:transparent;margin-top:5px;}
  .mail-thread-unread.is-visible{background:#1e68d7;}
  .mail-thread-main{min-width:0;display:flex;flex-direction:column;gap:2px;}
  .mail-thread-row{display:flex;align-items:center;justify-content:space-between;gap:10px;}
  .mail-thread-sender,.mail-thread-subject,.mail-thread-preview{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .mail-thread-sender{font-size:11px;line-height:1.2;font-weight:760;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#243247;}
  .mail-thread-time{font-size:11px;line-height:1.2;color:#728198;flex:0 0 auto;}
  .mail-thread-subject{font-size:11px;line-height:1.25;font-weight:700;}
  .mail-thread-preview{font-size:11.5px;line-height:1.25;color:#728198;}
  .mail-thread-meta{display:flex;flex-wrap:wrap;gap:4px;margin-top:3px;}
  .mail-chip,.mail-priority,.mail-icon-chip{display:inline-flex;align-items:center;border-radius:999px;padding:2px 6px;background:#eef2f8;color:#526174;font-size:10px;line-height:1.2;font-weight:740;}
  .mail-priority.p-high,.mail-priority.p-urgent{background:#fff3df;color:#9a5a00;}
  .mail-icon-chip.muted{opacity:.7;}
  .mail-thread-messages{grid-column:1/-1;margin:8px 0 -7px 25px;padding:7px 0 0;border-top:1px solid rgba(30,60,114,.12);}
  .mail-thread-messages.is-collapsed{margin:0;padding:0;border:0;height:0;overflow:visible;}
  .mail-thread-string-count{position:absolute;top:34px;right:37px;color:#1685f6;font-size:11px;line-height:19px;font-weight:850;z-index:3;}
  .mail-thread-string-toggle{position:absolute;top:34px;right:12px;width:20px;height:20px;border-radius:999px;border:1px solid rgba(30,60,114,.24);background:#fff;color:#1685f6;box-shadow:0 2px 8px rgba(15,23,42,.08);cursor:pointer;padding:0;display:flex;align-items:center;justify-content:center;z-index:3;}
  .mail-thread-string-toggle::before{content:"⌄";font-size:12px;line-height:1;font-weight:850;}
  .mail-thread-messages.is-collapsed .mail-thread-string-toggle::before{content:"›";}
  .mail-thread-messages.is-collapsed .mail-thread-message-row{display:none;}
  .mail-thread-messages:not(.is-collapsed) .mail-thread-string-toggle{top:33px;}
  .mail-thread-messages:not(.is-collapsed) .mail-thread-string-count{top:33px;}
  .mail-thread-message-row{width:100%;display:grid;grid-template-columns:8px minmax(0,1fr) auto;gap:7px;align-items:start;border:0;border-bottom:1px solid rgba(15,23,42,.09);background:rgba(255,255,255,.34);padding:7px 8px;text-align:left;color:#152235;cursor:pointer;}
  .mail-thread-message-row:last-child{border-bottom:0;border-radius:0 0 11px 11px;}
  .mail-thread-message-row:hover{background:rgba(255,255,255,.68);}
  .mail-thread-message-dot{width:7px;height:7px;border-radius:999px;background:#1685f6;margin-top:5px;}
  .mail-thread-message-main{min-width:0;display:grid;gap:2px;}
  .mail-thread-message-line{display:flex;justify-content:space-between;gap:8px;min-width:0;}
  .mail-thread-message-line strong{font-size:11px;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .mail-thread-message-line time{font-size:10.5px;color:#64748b;white-space:nowrap;}
  .mail-thread-message-subject,.mail-thread-message-preview{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .mail-thread-message-subject{font-size:10.5px;line-height:1.2;font-weight:720;color:#243247;}
  .mail-thread-message-preview{font-size:10.5px;line-height:1.25;color:#728198;}
  .mail-thread-message-attachment{align-self:center;color:#64748b;font-size:9px;font-weight:850;}
  .mail-reader-pane{position:relative;min-width:0;overflow:auto;overflow-x:hidden;background:#fff;padding-right:18px;}
  .mail-reader-placeholder{height:100%;display:flex;align-items:center;justify-content:center;color:#728198;text-align:center;padding:40px;}
  .mail-reader-shell{position:relative;min-height:100%;overflow-x:hidden;background:#fff;}
  .mail-reader-header{position:sticky;top:0;z-index:5;display:flex;justify-content:space-between;gap:14px;align-items:flex-start;padding:10px 30px 10px 20px;background:rgba(255,255,255,.96);border-bottom:1px solid rgba(15,23,42,.08);backdrop-filter:blur(18px);overflow:visible;}
  .mail-reader-header h2{margin:4px 0 3px;font-size:13px;line-height:1.25;letter-spacing:-.01em;font-weight:760;}
  .mail-reader-header p{margin:0;color:#728198;font-size:10.5px;line-height:1.25;}
  .mail-reader-kicker{color:#1e3c72;text-transform:uppercase;letter-spacing:.08em;font-size:9px;font-weight:800;}
  .mail-status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:3px 7px;background:#eef2ff;color:#1e3c72;border:1px solid rgba(30,60,114,.12);font-size:9px;line-height:1;font-weight:850;}
  .mail-status-pill.s-waiting_external{background:#eef2ff;color:#3730a3;}
  .mail-status-pill.s-waiting_internal{background:#fff7ed;color:#9a5a00;}
  .mail-status-pill.s-open{background:#eff6ff;color:#1d4ed8;}
  .mail-status-pill.s-closed{background:#ecfdf5;color:#047857;}
  .mail-status-pill.s-archived{background:#f1f5f9;color:#475569;}
  .mail-status-pill.p-high,.mail-status-pill.p-urgent{background:#fff7ed;color:#9a5a00;}
  .mail-status-pill.l-linked{background:#ecfdf5;color:#047857;}
  .mail-status-pill.l-unlinked{background:#f1f5f9;color:#64748b;}
  .mail-reader-compliance-strip{display:flex;flex-wrap:wrap;gap:5px;margin:3px 0 4px;}
  .mail-reader-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;flex:0 0 auto;}
  .mail-reader-actions .mail-action{border-color:rgba(15,23,42,.08);background:#fff;color:#1e3c72;padding:7px 11px;font-size:12px;}
  .mail-reader-grid{display:block;min-height:0;}
  .mail-timeline{padding:16px 46px 16px 30px;max-width:1040px;width:min(100%,1040px);margin:0 auto;overflow-x:hidden;box-sizing:border-box;}
  .mail-reader-shell.sidebar-open .mail-timeline{padding-right:calc(var(--mail-compliance-width) + 36px);}
  .mail-compliance-sidebar{position:absolute;right:0;top:0;bottom:0;width:var(--mail-compliance-width);max-width:450px;min-width:220px;display:flex;flex-direction:column;overflow:hidden;border-left:1px solid rgba(15,23,42,.06);background:#fbfcfe;box-shadow:none;transform:translateX(105%);transition:transform .18s ease;z-index:30;}
  .mail-reader-shell.sidebar-open .mail-compliance-sidebar{transform:translateX(0);box-shadow:-22px 0 60px rgba(15,23,42,.14);}
  .mail-compliance-sidebar::before{content:"";position:absolute;top:0;bottom:0;left:-6px;width:6px;cursor:col-resize;background:rgba(15,23,42,.04);}
  .mail-compliance-sidebar.is-resizing::before,.mail-compliance-sidebar:hover::before{background:rgba(30,60,114,.22);}
  .mail-sidebar-sticky{position:sticky;top:0;z-index:2;background:rgba(251,252,254,.96);border-bottom:1px solid rgba(15,23,42,.06);padding:16px 18px 14px;backdrop-filter:blur(14px);}
  .mail-sidebar-scroll{min-height:0;overflow:auto;padding:14px 18px 18px;}
  .mail-sidebar-head{display:flex;justify-content:space-between;gap:10px;align-items:start;margin-bottom:12px;}
  .mail-sidebar-head span{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#728198;font-weight:800;}
  .mail-sidebar-head strong{display:block;margin-top:4px;color:#152235;}
  .mail-sidebar-head button,.mail-linked-object button{border:0;background:#eef2f8;border-radius:10px;padding:6px 8px;font-size:11px;font-weight:760;cursor:pointer;}
  .mail-sidebar-summary{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;}
  .mail-summary-badge{border:1px solid rgba(15,23,42,.07);border-radius:14px;background:#fff;padding:8px 9px;text-align:left;cursor:pointer;}
  .mail-summary-badge span{display:block;color:#728198;font-size:10px;text-transform:uppercase;letter-spacing:.07em;font-weight:850;}
  .mail-summary-badge strong{display:block;margin-top:3px;color:#152235;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .mail-edit-compliance{width:100%;border:0;border-radius:12px;background:#1e3c72;color:#fff;padding:9px 12px;font-size:12px;font-weight:850;cursor:pointer;}
  .mail-sidebar-section{border:1px solid rgba(15,23,42,.06);border-radius:18px;background:#fff;padding:14px;margin-bottom:12px;}
  .mail-sidebar-section.is-edit-mode{display:none;}
  .mail-compliance-sidebar.is-editing .mail-sidebar-section.is-edit-mode{display:block;}
  .mail-sidebar-section h3{margin:0 0 10px;font-size:13px;}
  .mail-inline-form,.mail-link-form{display:grid;gap:8px;}
  .mail-inline-form label{display:grid;gap:5px;font-size:11px;text-transform:uppercase;color:#728198;font-weight:800;}
  .mail-inline-form select,.mail-link-form select{width:100%;height:38px;border:1px solid rgba(15,23,42,.08);border-radius:11px;background:#fff;}
  .mail-inline-form button,.mail-link-form button{height:38px;border:0;border-radius:11px;background:#1e3c72;color:#fff;font-weight:800;}
  .mail-linked-object{display:grid;grid-template-columns:1fr auto;gap:4px;margin-bottom:9px;}
  .mail-linked-object span{grid-column:1/-1;color:#728198;font-size:11px;text-transform:uppercase;font-weight:800;}
  .mail-linked-object a{color:#1e3c72;font-weight:760;text-decoration:none;}
  .mail-context-list{margin:0;display:grid;gap:8px;}
  .mail-context-list div{display:flex;justify-content:space-between;gap:10px;}
  .mail-context-list dt{color:#728198;font-size:12px;}
  .mail-context-list dd{margin:0;text-align:right;font-weight:760;}
  .mail-message-card{position:relative;display:grid;grid-template-columns:4px minmax(0,1fr);margin-bottom:18px;border:1px solid rgba(15,23,42,.07);border-radius:18px;background:#fff;box-shadow:none;overflow:visible;max-width:100%;}
  .mail-message-card.is-jump-target{box-shadow:0 0 0 3px rgba(22,133,246,.2);}
  .mail-message-accent{background:#2f80ed;}
  .mail-message-card.is-outgoing .mail-message-accent{background:#2f9e62;}
  .mail-message-content{padding:14px 34px 14px 16px;min-width:0;overflow-x:hidden;background:#fff;border-radius:0 18px 18px 0;}
  .mail-message-header{display:flex;gap:12px;align-items:flex-start;border-bottom:1px solid rgba(15,23,42,.08);padding-bottom:10px;margin-bottom:12px;min-width:0;}
  .mail-avatar{width:42px;height:42px;border-radius:14px;background:#e9eef8;color:#1e3c72;display:flex;align-items:center;justify-content:center;font-weight:850;flex:0 0 auto;}
  .mail-message-meta{min-width:0;flex:1;overflow:hidden;}
  .mail-message-topline{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;}
  .mail-message-topline strong{font-size:13px;color:#152235;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .mail-message-topline span{color:#728198;font-size:11.5px;white-space:nowrap;}
  .mail-message-subject{font-size:12px;color:#152235;margin-top:2px;overflow-wrap:anywhere;}
  .mail-message-recipients{font-size:11.5px;color:#526174;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .mail-message-details{margin-top:4px;font-size:11.5px;color:#64748b;}
  .mail-message-details summary{cursor:pointer;color:#1e3c72;font-weight:760;}
  .mail-message-details dl{display:grid;gap:4px;margin:6px 0 0;}
  .mail-message-details div{display:grid;grid-template-columns:52px minmax(0,1fr);gap:8px;}
  .mail-message-details dd{margin:0;min-width:0;overflow-wrap:anywhere;}
  .mail-message-tools{display:flex;gap:6px;justify-content:flex-end;flex:0 0 auto;}
  .mail-message-body{margin-top:12px;max-width:100%;overflow-x:hidden;padding-right:16px;box-sizing:border-box;}
  .mail-html-frame{display:block;width:100%;max-width:100%;min-height:80px;border:0;background:transparent;overflow:hidden;}
  .mail-action-menu{position:relative;}
  .mail-action-menu summary{list-style:none;border:1px solid rgba(15,23,42,.08);background:#fff;border-radius:999px;padding:7px 11px;font-size:12px;font-weight:800;cursor:pointer;color:#1e3c72;}
  .mail-action-menu summary::-webkit-details-marker{display:none;}
  .mail-action-menu div{position:absolute;right:0;top:calc(100% + 6px);z-index:20;min-width:190px;display:grid;gap:2px;padding:6px;border:1px solid rgba(15,23,42,.08);border-radius:14px;background:#fff;box-shadow:0 18px 42px rgba(15,23,42,.16);}
  .mail-action-menu button,.mail-action-menu a{display:block;width:100%;border:0;background:transparent;border-radius:10px;padding:8px 10px;text-align:left;font-size:12px;font-weight:740;color:#152235;text-decoration:none;cursor:pointer;}
  .mail-action-menu button:hover,.mail-action-menu a:hover{background:#f1f5f9;}
  .mail-text-fallback{white-space:pre-wrap;font-size:15px;line-height:1.65;color:#152235;}
  .mail-attachments{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px;align-items:stretch;}
  .mail-attachment{width:260px;min-height:84px;display:grid;grid-template-columns:46px minmax(0,1fr);grid-template-rows:auto auto;gap:8px 10px;align-items:center;border:1px solid rgba(15,23,42,.07);border-radius:14px;background:#f8fafc;padding:10px;}
  .mail-attachment-icon{width:46px;height:46px;border-radius:12px;background:#e9eef8;color:#1e3c72;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:850;}
  .mail-attachment-thumb{width:46px;height:46px;object-fit:cover;border-radius:10px;grid-column:1;grid-row:1;}
  .mail-attachment-copy{min-width:0;}
  .mail-attachment-copy strong,.mail-attachment-copy span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .mail-attachment-copy span{color:#728198;font-size:12px;}
  .mail-attachment-actions{grid-column:1/-1;display:flex;gap:6px;}
  .mail-attachment-actions a{border:1px solid rgba(15,23,42,.08);background:#fff;border-radius:999px;padding:6px 9px;text-decoration:none;color:#1e3c72;font-size:12px;font-weight:760;}
  .mail-quoted,.mail-events{margin-top:14px;color:#526174;font-size:13px;}
  .mail-quoted summary,.mail-events summary{cursor:pointer;font-weight:800;color:#1e3c72;}
  .mail-composer-backdrop{position:fixed;inset:0;z-index:200;background:rgba(15,23,42,.22);display:none;align-items:flex-start;justify-content:center;padding:34px 24px;}
  .mail-composer-backdrop.is-open{display:flex;}
  .mail-composer{width:min(980px,calc(100vw - 32px));max-height:min(900px,calc(100vh - 68px));display:flex;flex-direction:column;border-radius:22px;background:#fff;box-shadow:0 30px 90px rgba(15,23,42,.28);overflow:hidden;}
  .mail-composer-top{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:12px;padding:10px 14px;background:rgba(248,250,252,.96);border-bottom:1px solid rgba(15,23,42,.08);}
  .mail-window-dots{display:flex;gap:7px;align-items:center;}
  .mail-window-dots button,.mail-window-dots span{width:12px;height:12px;border-radius:999px;display:block;border:0;padding:0;}
  .mail-window-dots button{background:#ff5f57;cursor:pointer;}
  .mail-window-dots span:nth-child(2){background:#ffbd2e;}
  .mail-window-dots span:nth-child(3){background:#28c840;}
  .mail-composer-title{text-align:center;font-weight:850;color:#152235;}
  .mail-composer-head-actions{display:flex;justify-content:flex-end;gap:8px;}
  .mail-composer-head-actions button,.mail-format-btn{border:0;border-radius:999px;background:#fff;color:#243247;min-width:32px;height:32px;padding:0 10px;font-weight:850;box-shadow:0 5px 16px rgba(15,23,42,.06);cursor:pointer;}
  .mail-composer-head-actions .primary{background:#1e3c72;color:#fff;}
  .mail-composer-fields{display:grid;border-bottom:1px solid rgba(15,23,42,.08);}
  .mail-compose-row{display:grid;grid-template-columns:82px minmax(0,1fr);align-items:center;min-height:44px;border-bottom:1px solid rgba(15,23,42,.07);}
  .mail-compose-row:last-child{border-bottom:0;}
  .mail-compose-row label{padding-left:22px;color:#728198;font-size:13px;font-weight:760;}
  .mail-compose-row input,.mail-compose-row select{height:100%;border:0;outline:0;background:#fff;color:#152235;font-size:14px;padding:0 14px;min-width:0;}
  .mail-compose-template{background:#fbfcfe;}
  .mail-compose-template select{font-weight:850;color:#1e3c72;}
  .mail-format-toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:10px 22px;border-bottom:1px solid rgba(15,23,42,.08);background:#fff;}
  .mail-format-toolbar select,.mail-format-toolbar input[type="color"]{height:32px;border:1px solid rgba(15,23,42,.08);border-radius:9px;background:#f8fafc;color:#243247;padding:0 8px;}
  .mail-format-group{display:inline-flex;gap:3px;padding:2px;border-radius:12px;background:#f8fafc;}
  .mail-format-btn{box-shadow:none;background:transparent;min-width:28px;height:28px;padding:0 8px;border-radius:9px;}
  .mail-format-btn:hover{background:#eaf1fb;color:#1e3c72;}
  .mail-compose-body{min-height:0;overflow:auto;padding:22px;}
  .mail-compose-body.is-hidden{display:none;}
  .mail-editor{min-height:180px;border:1px solid rgba(15,23,42,.08);border-radius:16px;padding:16px;line-height:1.55;outline:none;font-size:14px;color:#152235;background:#fff;}
  .mail-template-preview-wrap{display:none;padding:0 22px 18px;background:#fff;cursor:text;}
  .mail-template-preview-wrap.is-visible{display:block;}
  .mail-template-preview-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px;color:#728198;font-size:11px;font-weight:850;text-transform:uppercase;letter-spacing:.06em;}
  .mail-template-preview{width:100%;min-height:320px;border:1px solid rgba(15,23,42,.08);border-radius:18px;background:#f8fafc;overflow:auto;padding:28px 12px;box-sizing:border-box;}
  .mail-compose-template-card{max-width:720px;margin:0 auto;background:#fff;border:1px solid #dfe6f1;border-radius:20px;overflow:hidden;color:#152235;font-family:Arial,Helvetica,sans-serif;}
  .mail-compose-template-hero{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:22px;align-items:start;padding:26px 30px;background:#0d1d34;color:#fff;}
  .mail-compose-template-kicker{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.72);font-weight:700;}
  .mail-compose-template-title{margin:10px 0 0;font-size:24px;line-height:1.2;color:#fff;font-weight:850;}
  .mail-compose-template-ref{margin-top:8px;font-size:12px;color:rgba(255,255,255,.72);}
  .mail-compose-template-logo{font-size:32px;line-height:1;font-style:italic;font-weight:900;letter-spacing:-.06em;color:#fff;}
  .mail-compose-template-logo span{display:block;margin-top:2px;font-size:8px;letter-spacing:.02em;font-style:normal;font-weight:700;color:rgba(255,255,255,.78);}
  .mail-compose-template-pills{grid-column:1/-1;display:flex;flex-wrap:wrap;gap:8px;margin-top:4px;}
  .mail-compose-template-pills span{display:inline-flex;padding:7px 12px;border-radius:999px;background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.16);color:#fff;font-size:12px;font-weight:850;}
  .mail-compose-template-body{padding:30px;font-size:15px;line-height:1.65;color:#152235;}
  .mail-compose-template-signature{margin:24px 0 0;}
  .mail-compose-template-footer{padding:18px 30px;background:#f7f9fc;border-top:1px solid #e7edf5;color:#728198;font-size:12px;line-height:1.5;}
  .mail-compose-template-footer strong{color:#152235;}
  .mail-template-preview .mail-editor{min-height:120px;border:0;border-radius:0;padding:0;background:transparent;font:inherit;color:inherit;}
  .mail-template-preview .mail-editor:focus{outline:0;}
  .mail-template-preview .mail-editor:empty::before{content:"Type your message here...";color:#8a97aa;}
  .mail-composer.is-dragging .mail-editor{border-color:#1e3c72;background:#f3f7ff;}
  .mail-compose-extras{display:grid;grid-template-columns:110px minmax(0,1fr);gap:8px 12px;padding:14px 22px;border-top:1px solid rgba(15,23,42,.08);background:#fbfcfe;}
  .mail-compose-extras label{font-size:12px;text-transform:uppercase;color:#728198;font-weight:850;padding-top:10px;}
  .mail-compose-extras input,.mail-compose-extras select{min-height:38px;border:1px solid rgba(15,23,42,.1);border-radius:12px;padding:0 12px;background:#fff;}
  .mail-composer-actions{display:flex;gap:10px;justify-content:flex-end;padding:14px 18px;border-top:1px solid rgba(15,23,42,.06);}
  .mail-composer-actions button{border:1px solid rgba(15,23,42,.1);border-radius:999px;background:#fff;padding:10px 14px;font-weight:800;}
  .mail-composer-actions .primary{background:#1e3c72;color:#fff;border-color:#1e3c72;}
  .mail-flash{padding:11px 14px;border-radius:14px;background:#ecfdf5;color:#176f49;font-weight:760;}
  .mail-flash.is-error{background:#fee2e2;color:#991b1b;}
  .mail-muted{color:#728198;}
  .mail-empty{padding:26px;border:1px dashed rgba(15,23,42,.16);border-radius:18px;color:#728198;text-align:center;background:#fff;}
  #inboxIntegrationModal{width:min(980px,calc(100vw - 32px));}
  .mail-template-code{width:100%;min-height:180px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;line-height:1.45;}
  .mail-template-code.small{min-height:110px;}
  @media (max-width:1360px){.mail-timeline{max-width:980px;}.mail-reader-shell.sidebar-open .mail-timeline{padding-right:22px;}.mail-compliance-sidebar{position:fixed;right:0;top:0;bottom:0;height:auto;width:min(var(--mail-compliance-width),92vw);z-index:50;}}
  @media (max-width:980px){.mail-page{height:calc(100vh - 112px);}.mail-toolbar{grid-template-columns:1fr;}.mail-toolbar-left,.mail-toolbar-center,.mail-toolbar-actions{grid-column:1;border-left:0;border-right:0;border-bottom:1px solid rgba(15,23,42,.06);}.mail-toolbar-actions{justify-content:flex-start;}.mail-workspace{grid-template-columns:minmax(300px,var(--mail-list-width)) 2px minmax(0,1fr);}.mail-folders{position:absolute;z-index:40;width:250px;inset:0 auto 0 0;transform:translateX(-105%);transition:transform .18s ease;box-shadow:20px 0 60px rgba(15,23,42,.16);}.mail-workspace.folders-open .mail-folders{transform:translateX(0);}.mail-workspace > .mail-resizer[data-resizer="folders"]{display:none;}#mailHero.mail-top h1{font-size:22px;}}
  @media (max-width:760px){.mail-workspace{grid-template-columns:1fr;}.mail-resizer{display:none;}.mail-list-pane{border-right:0;}.mail-reader-pane{position:absolute;inset:0;z-index:30;transform:translateX(105%);transition:transform .2s ease;}.mail-workspace.reader-open .mail-reader-pane{transform:translateX(0);}.mail-reader-header{position:relative;}.mail-reader-actions{width:100%;justify-content:flex-start;}.mail-reader-header{flex-direction:column;}#mailHero.mail-top{align-items:flex-start;flex-direction:column;}.mail-message-header{flex-wrap:wrap;}.mail-message-tools{justify-content:flex-start;}.mail-attachment{grid-template-columns:42px minmax(0,1fr);}.mail-attachment-actions{grid-column:1/-1;}.mail-compliance-sidebar{width:min(92vw,var(--mail-compliance-width));}}
</style>

<div class="mail-page">
  <section class="mail-top" id="mailHero">
    <div>
      <h1>Compliance Mail</h1>
      <p>Professional communication workspace with compliance intelligence built around the conversation.</p>
    </div>
  </section>

  <?php if ($flash !== null): ?>
    <div class="mail-flash <?= (string)($flash['type'] ?? '') === 'error' ? 'is-error' : '' ?>"><?= h((string)$flash['message']) ?></div>
  <?php endif; ?>

  <section class="mail-client-box" aria-label="Mail workspace">
    <section class="mail-toolbar" aria-label="Mail controls">
      <div class="mail-toolbar-left">
        <button type="button" class="mail-toolbar-btn" data-folder-toggle aria-label="Hide folders" title="Hide folders">▣</button>
      </div>
      <div class="mail-toolbar-divider folders" aria-hidden="true"></div>
      <div class="mail-toolbar-center">
        <div class="mail-toolbar-title">
          <strong id="mailToolbarTitle"><?= h($selectedMailboxTitle) ?></strong>
          <span id="mailToolbarMeta">Compliance Mail · <?= (int)$selectedMailboxCount ?> conversations</span>
        </div>
        <span class="mail-selected-count" id="mailBulkCount">0 selected</span>
      </div>
      <div class="mail-toolbar-divider list" aria-hidden="true"></div>
      <div class="mail-toolbar-actions">
        <span class="mail-toolbar-group">
          <button type="button" class="mail-toolbar-btn" data-compose-new aria-label="New message" title="New message">✎</button>
        </span>
        <span class="mail-toolbar-group">
          <button type="button" class="mail-toolbar-btn" data-toolbar-thread-compose aria-label="Reply" title="Reply">↩</button>
          <button type="button" class="mail-toolbar-btn" data-toolbar-thread-compose aria-label="Reply all" title="Reply all">↞</button>
          <button type="button" class="mail-toolbar-btn" data-toolbar-thread-compose aria-label="Forward" title="Forward">↪</button>
        </span>
        <span class="mail-toolbar-group">
          <details class="mail-toolbar-menu">
            <summary aria-label="Set status" title="Set status">✓</summary>
            <div class="mail-toolbar-popover">
              <label>Status
                <select name="bulk_status_value" aria-label="Bulk status">
                  <option value="open">open</option>
                  <option value="waiting_internal">waiting internal</option>
                  <option value="waiting_external">waiting external</option>
                  <option value="closed">closed</option>
                  <option value="archived">archived</option>
                </select>
              </label>
              <button type="button" data-bulk-status>Apply status</button>
            </div>
          </details>
          <details class="mail-toolbar-menu">
            <summary aria-label="Set priority" title="Set priority">⚑</summary>
            <div class="mail-toolbar-popover">
              <label>Priority
                <select name="bulk_priority_value" aria-label="Bulk priority">
                  <option value="low">low</option>
                  <option value="normal">normal</option>
                  <option value="high">high</option>
                  <option value="urgent">urgent</option>
                </select>
              </label>
              <button type="button" data-bulk-priority>Apply priority</button>
            </div>
          </details>
        </span>
        <span class="mail-toolbar-group">
          <button type="button" class="mail-toolbar-btn" data-open-folder="drafts" aria-label="Drafts" title="Drafts">▱</button>
          <button type="button" class="mail-toolbar-btn" data-compliance-modal-open="inboxIntegrationModal" aria-label="Settings" title="Settings">⋯</button>
          <button type="button" class="mail-toolbar-btn" data-fullscreen-toggle aria-label="Full screen" title="Full screen">⛶</button>
        </span>
        <input class="mail-toolbar-search" type="search" id="mailSearch" placeholder="Search" value="<?= h($q) ?>">
      </div>
    </section>

    <section class="mail-workspace" id="mailWorkspace" data-initial-thread="<?= (int)$selectedThreadId ?>" data-folder="<?= h($folder) ?>">
    <nav class="mail-folders" aria-label="Mail folders">
      <?php foreach ($folders as $key => $meta): ?>
        <button type="button" class="mail-folder <?= $key === $folder ? 'is-active' : '' ?>" data-folder="<?= h($key) ?>" data-folder-label="<?= h((string)$meta['label']) ?>" data-folder-count="<?= (int)$meta['count'] ?>">
          <span class="folder-label"><?= h((string)$meta['label']) ?></span>
          <span><?= (int)$meta['count'] ?></span>
        </button>
      <?php endforeach; ?>
    </nav>
    <div class="mail-resizer" data-resizer="folders" role="separator" aria-orientation="vertical" aria-label="Resize folder pane"></div>

    <aside class="mail-list-pane">
      <div class="mail-thread-list" id="mailThreadList">
        <?php if ($folder === 'drafts'): ?>
          <?php if ($draftRows === array()): ?>
            <div class="mail-empty">No draft or outbox records found.</div>
          <?php else: ?>
            <?php foreach ($draftRows as $draft): ?>
              <?= ComplianceMailUi::draftCard($draft) ?>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php elseif ($threads === array()): ?>
          <div class="mail-empty">No conversations in this folder.</div>
        <?php else: ?>
          <?php foreach ($threads as $idx => $thread): ?>
            <?= ComplianceMailUi::conversationCard($thread, (int)$thread['id'] === $selectedThreadId || ($selectedThreadId <= 0 && $idx === 0)) ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </aside>
    <div class="mail-resizer" data-resizer="list" role="separator" aria-orientation="vertical" aria-label="Resize conversation list"></div>

    <main class="mail-reader-pane" id="mailReaderPane">
      <div class="mail-reader-placeholder" id="mailReaderPlaceholder">
        <div>
          <strong>Select a conversation</strong>
          <p>The reader stays open while you triage, reply, link, and update compliance state.</p>
        </div>
      </div>
    </main>
    </section>
  </section>
</div>

<div class="mail-composer-backdrop" id="mailComposerBackdrop" aria-hidden="true">
  <form class="mail-composer" id="mailComposer" enctype="multipart/form-data">
    <header class="mail-composer-top">
      <div class="mail-window-dots"><button type="button" data-compose-close aria-label="Close compose"></button><span aria-hidden="true"></span><span aria-hidden="true"></span></div>
      <strong class="mail-composer-title" id="mailComposerTitle">New message</strong>
      <div class="mail-composer-head-actions">
        <button type="submit" data-compose-action="save_draft" title="Save draft">Draft</button>
        <button type="submit" data-compose-action="send_now" class="primary" title="Send">Send ↑</button>
        <button type="button" data-compose-close title="Close">Close</button>
      </div>
    </header>
    <div class="mail-composer-fields">
      <input type="hidden" name="draft_id" id="composeDraftId" value="0">
      <input type="hidden" name="thread_id" id="composeThreadId" value="0">
      <input type="hidden" name="reply_to_email_id" id="composeReplyId" value="0">
      <input type="hidden" name="html_body" id="composeHtmlBody" value="">
      <input type="hidden" name="text_body" id="composeTextBody" value="">
      <div class="mail-compose-row"><label for="composeTo">To:</label><input id="composeTo" name="to" required></div>
      <div class="mail-compose-row"><label for="composeCc">Cc:</label><input id="composeCc" name="cc"></div>
      <div class="mail-compose-row"><label for="composeBcc">Bcc:</label><input id="composeBcc" name="bcc"></div>
      <div class="mail-compose-row"><label for="composeSubject">Subject:</label><input id="composeSubject" name="subject" required></div>
      <div class="mail-compose-row mail-compose-template">
        <label for="composeTemplateStyle">Template:</label>
        <select id="composeTemplateStyle" name="template_style">
          <option value="standard" selected>Standard Compliance Layout</option>
          <option value="none">No style</option>
        </select>
      </div>
    </div>
    <div class="mail-format-toolbar" aria-label="Text formatting">
      <select data-format-font aria-label="Font">
        <option value="Arial">Arial</option>
        <option value="Helvetica">Helvetica</option>
        <option value="Georgia">Georgia</option>
        <option value="Times New Roman">Times New Roman</option>
        <option value="Courier New">Courier New</option>
      </select>
      <select data-format-size aria-label="Text size">
        <option value="2">12</option>
        <option value="3" selected>14</option>
        <option value="4">16</option>
        <option value="5">18</option>
      </select>
      <input type="color" data-format-color value="#152235" aria-label="Text color">
      <span class="mail-format-group">
        <button type="button" class="mail-format-btn" data-format-cmd="bold"><strong>B</strong></button>
        <button type="button" class="mail-format-btn" data-format-cmd="italic"><em>I</em></button>
        <button type="button" class="mail-format-btn" data-format-cmd="underline"><u>U</u></button>
      </span>
      <span class="mail-format-group">
        <button type="button" class="mail-format-btn" data-format-cmd="justifyLeft">☰</button>
        <button type="button" class="mail-format-btn" data-format-cmd="justifyCenter">≡</button>
        <button type="button" class="mail-format-btn" data-format-cmd="justifyRight">☷</button>
      </span>
      <span class="mail-format-group">
        <button type="button" class="mail-format-btn" data-format-cmd="insertUnorderedList">• list</button>
        <button type="button" class="mail-format-btn" data-format-cmd="insertOrderedList">1. list</button>
      </span>
    </div>
    <div class="mail-compose-body" id="composePlainBody">
      <div class="mail-template-preview-head"><span>Message body</span><span>Type here</span></div>
      <div id="composeEditor" class="mail-editor" contenteditable="true" tabindex="0" aria-label="Message body"></div>
    </div>
    <div class="mail-template-preview-wrap is-visible" id="composeTemplatePreviewWrap">
      <div class="mail-template-preview-head"><span>Standard Compliance Layout</span><span>Type directly in the message area below</span></div>
      <div class="mail-template-preview" id="composeTemplatePreview" aria-label="Editable compliance template"></div>
    </div>
    <div class="mail-compose-extras">
      <label for="composeAttachments">Attachments</label><input type="file" id="composeAttachments" name="attachments[]" multiple>
      <label for="composeObjectRefs">Links</label>
      <select id="composeObjectRefs" name="object_refs[]" multiple>
        <?php foreach (ComplianceCommsCenterEngine::listLinkablePickerOptions($pdo) as $group): ?>
          <?php if (empty($group['options'])) { continue; } ?>
          <optgroup label="<?= h((string)$group['type_label']) ?>">
            <?php foreach ($group['options'] as $opt): ?>
              <option value="<?= h((string)$group['type'] . '|' . (string)$opt['id']) ?>"><?= h((string)$opt['label']) ?></option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
      <div></div><div class="mail-muted" id="composeStatus">Draft autosaves when recipient, subject, and message are present.</div>
    </div>
  </form>
</div>

<?php compliance_modal_open('inboxIntegrationModal', 'Compliance Mail settings'); ?>
  <div class="compliance-table-wrap">
    <table class="compliance-table" style="margin-bottom:14px;">
      <tbody>
        <tr><td style="width:240px;font-weight:700;">Inbox address</td><td><?= h((string)$summary['inbox_address']) ?></td></tr>
        <tr><td style="font-weight:700;">Outbound from</td><td><?= h((string)$summary['from_address']) ?></td></tr>
        <tr><td style="font-weight:700;">Outbound stream</td><td><?= h((string)$summary['outbound_stream']) ?></td></tr>
        <tr><td style="font-weight:700;">Postmark token</td><td><?= !empty($summary['server_token_set']) ? 'configured' : 'missing' ?> <?= h((string)$summary['server_token_masked']) ?></td></tr>
        <tr><td style="font-weight:700;">Inbound webhook</td><td style="word-break:break-all;"><?= h((string)$summary['inbound_webhook_url']) ?></td></tr>
        <tr><td style="font-weight:700;">Tracking webhook</td><td style="word-break:break-all;"><?= h((string)$summary['tracking_webhook_url']) ?></td></tr>
      </tbody>
    </table>
  </div>
  <section class="cmp-card">
    <h3 style="margin-top:0;">Outbound email template</h3>
    <?php if (!$templateTablesPresent): ?>
      <p class="cmp-flash is-warn">Template storage is not active yet. Apply <code>scripts/sql/compliance_os_phase_8_6_email_templates.sql</code> to enable editing and version history.</p>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="action" value="save_email_template">
        <label class="cmp-field"><span>Subject template</span><input name="subject_template" required value="<?= h($emailTemplateSubject) ?>"></label>
        <label class="cmp-field"><span>HTML template</span><textarea name="html_template" class="mail-template-code" required><?= h($emailTemplateHtml) ?></textarea></label>
        <label class="cmp-field"><span>Plain text fallback</span><textarea name="text_template" class="mail-template-code small"><?= h($emailTemplateText) ?></textarea></label>
        <label class="cmp-field"><span>Allowed variables JSON</span><textarea name="allowed_variables_json" class="mail-template-code small" required><?= h($emailTemplateAllowed) ?></textarea></label>
        <label class="cmp-field"><span>Change note</span><input name="change_note" placeholder="What changed in this version?"></label>
        <button type="submit">Save new template version</button>
      </form>
      <?php if ($templateVersions !== array()): ?>
        <h4>Recent versions</h4>
        <ul>
          <?php foreach ($templateVersions as $version): ?>
            <li>v<?= (int)$version['version_no'] ?> - <?= h((string)$version['subject_template']) ?> - <?= h(substr((string)$version['created_at'], 0, 16)) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php endif; ?>
  </section>
  <div class="compliance-modal__footer"><button type="button" class="cmp-btn-secondary" data-compliance-modal-close>Close</button></div>
<?php compliance_modal_close(); ?>

<script>
(function () {
  var workspace = document.getElementById('mailWorkspace');
  var toolbar = document.querySelector('.mail-toolbar');
  var list = document.getElementById('mailThreadList');
  var reader = document.getElementById('mailReaderPane');
  var search = document.getElementById('mailSearch');
  var currentThreadId = parseInt(workspace.getAttribute('data-initial-thread') || '0', 10) || 0;
  var currentFolder = workspace.getAttribute('data-folder') || 'inbox';
  var searchTimer = null;
  var autosaveTimer = null;
  var layoutKey = 'ipcaComplianceMailLayout';
  var layout = loadLayout();

  function clamp(n, min, max) {
    return Math.max(min, Math.min(max, n));
  }
  function loadLayout() {
    var fallback = {folderWidth: 220, listWidth: 360, complianceWidth: 340, complianceOpen: false, foldersCollapsed: false};
    try {
      var raw = localStorage.getItem(layoutKey);
      if (!raw) { return fallback; }
      var parsed = JSON.parse(raw);
      return {
        folderWidth: clamp(parseInt(parsed.folderWidth || fallback.folderWidth, 10), 180, 300),
        listWidth: clamp(parseInt(parsed.listWidth || fallback.listWidth, 10), 300, 520),
        complianceWidth: clamp(parseInt(parsed.complianceWidth || fallback.complianceWidth, 10), 0, 450),
        complianceOpen: parsed.complianceOpen === true,
        foldersCollapsed: parsed.foldersCollapsed === true
      };
    } catch (e) {
      return fallback;
    }
  }
  function saveLayout() {
    try { localStorage.setItem(layoutKey, JSON.stringify(layout)); } catch (e) {}
  }
  function applyLayout() {
    workspace.style.setProperty('--mail-folder-width', clamp(layout.folderWidth, 180, 300) + 'px');
    workspace.style.setProperty('--mail-list-width', clamp(layout.listWidth, 300, 520) + 'px');
    workspace.style.setProperty('--mail-compliance-width', clamp(layout.complianceWidth, 0, 450) + 'px');
    if (toolbar) {
      toolbar.style.setProperty('--mail-folder-width', clamp(layout.folderWidth, 180, 300) + 'px');
      toolbar.style.setProperty('--mail-list-width', clamp(layout.listWidth, 300, 520) + 'px');
      toolbar.classList.toggle('folders-collapsed', layout.foldersCollapsed === true);
    }
    workspace.classList.toggle('folders-collapsed', layout.foldersCollapsed === true);
    var folderToggle = document.querySelector('[data-folder-toggle]');
    if (folderToggle) {
      folderToggle.setAttribute('aria-label', layout.foldersCollapsed ? 'Show folders' : 'Hide folders');
      folderToggle.setAttribute('title', layout.foldersCollapsed ? 'Show folders' : 'Hide folders');
    }
    applySidebarState();
  }
  function updateToolbarMailbox(label, count, key) {
    var title = document.getElementById('mailToolbarTitle');
    var meta = document.getElementById('mailToolbarMeta');
    var display = key === 'inbox' ? 'All Inboxes' : (label || 'All Mailboxes');
    if (title) { title.textContent = display; }
    if (meta) { meta.textContent = 'Compliance Mail · ' + (count || 0) + ' conversations'; }
  }
  function applySidebarState() {
    var shell = document.querySelector('.mail-reader-shell');
    if (!shell) { return; }
    var isOpen = layout.complianceOpen && layout.complianceWidth > 0;
    shell.classList.toggle('sidebar-open', isOpen);
    var btn = shell.querySelector('[data-sidebar-toggle]');
    if (btn) {
      btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      btn.textContent = isOpen ? 'Hide Compliance' : 'Compliance';
    }
  }
  function startResize(kind, ev) {
    ev.preventDefault();
    if (ev.currentTarget.setPointerCapture && ev.pointerId !== undefined) {
      try { ev.currentTarget.setPointerCapture(ev.pointerId); } catch (e) {}
    }
    var startX = ev.clientX;
    var startFolder = layout.folderWidth;
    var startList = layout.listWidth;
    var target = ev.currentTarget;
    var finished = false;
    target.classList.add('is-dragging');
    workspace.classList.add('is-resizing');
    function move(moveEv) {
      var dx = moveEv.clientX - startX;
      if (kind === 'folders') {
        layout.folderWidth = clamp(startFolder + dx, 180, 300);
      } else {
        layout.listWidth = clamp(startList + dx, 300, 520);
      }
      applyLayout();
    }
    function done() {
      if (finished) { return; }
      finished = true;
      if (target.releasePointerCapture && ev.pointerId !== undefined) {
        try { target.releasePointerCapture(ev.pointerId); } catch (e) {}
      }
      target.classList.remove('is-dragging');
      workspace.classList.remove('is-resizing');
      saveLayout();
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', done);
      window.removeEventListener('pointercancel', done);
      window.removeEventListener('blur', done);
    }
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', done);
    window.addEventListener('pointercancel', done);
    window.addEventListener('blur', done);
  }
  function startComplianceResize(ev) {
    var sidebar = ev.target.closest('.mail-compliance-sidebar');
    if (!sidebar || ev.clientX > sidebar.getBoundingClientRect().left + 12) { return; }
    ev.preventDefault();
    if (sidebar.setPointerCapture && ev.pointerId !== undefined) {
      try { sidebar.setPointerCapture(ev.pointerId); } catch (e) {}
    }
    var startX = ev.clientX;
    var startWidth = layout.complianceWidth;
    var finished = false;
    sidebar.classList.add('is-resizing');
    function move(moveEv) {
      layout.complianceWidth = clamp(startWidth + (startX - moveEv.clientX), 0, 450);
      layout.complianceOpen = layout.complianceWidth > 0;
      applyLayout();
    }
    function done() {
      if (finished) { return; }
      finished = true;
      if (sidebar.releasePointerCapture && ev.pointerId !== undefined) {
        try { sidebar.releasePointerCapture(ev.pointerId); } catch (e) {}
      }
      sidebar.classList.remove('is-resizing');
      workspace.classList.remove('is-resizing');
      saveLayout();
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', done);
      window.removeEventListener('pointercancel', done);
      window.removeEventListener('blur', done);
    }
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', done);
    window.addEventListener('pointercancel', done);
    window.addEventListener('blur', done);
  }

  function apiUrl(params) {
    return '/admin/compliance/mail_api.php?' + new URLSearchParams(params).toString();
  }
  function selectedIds() {
    return Array.prototype.map.call(list.querySelectorAll('.mail-thread-select input:checked'), function (cb) {
      return cb.value;
    });
  }
  function updateBulkCount() {
    var el = document.getElementById('mailBulkCount');
    if (el) { el.textContent = selectedIds().length + ' selected'; }
  }
  function updateFullscreenButton() {
    var btn = document.querySelector('[data-fullscreen-toggle]');
    if (!btn) { return; }
    btn.setAttribute('aria-label', document.fullscreenElement ? 'Exit full screen' : 'Full screen');
    btn.setAttribute('title', document.fullscreenElement ? 'Exit full screen' : 'Full screen');
    document.querySelector('.mail-page').classList.toggle('is-fullscreen', !!document.fullscreenElement);
  }
  function showThreadMessages(threadId, html) {
    list.querySelectorAll('.mail-thread-messages').forEach(function (existing) {
      existing.remove();
    });
    if (!html) { return; }
    var selected = list.querySelector('.mail-thread-card[data-thread-id="' + threadId + '"]');
    if (!selected) { return; }
    selected.insertAdjacentHTML('beforeend', html);
  }
  function jumpToMessage(emailId) {
    var target = reader.querySelector('[data-email-id="' + emailId + '"]');
    if (!target) { return; }
    target.scrollIntoView({behavior: 'smooth', block: 'start'});
    target.classList.add('is-jump-target');
    window.setTimeout(function () { target.classList.remove('is-jump-target'); }, 1400);
  }
  function loadThread(id) {
    if (!id) { return; }
    currentThreadId = id;
    reader.innerHTML = '<div class="mail-reader-placeholder">Loading conversation...</div>';
    fetch(apiUrl({action: 'thread', id: id}), {headers: {'Accept': 'application/json'}})
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (!json.ok) { throw new Error(json.error || 'Unable to load conversation.'); }
        reader.innerHTML = json.html;
        list.querySelectorAll('.mail-thread-card').forEach(function (card) {
          card.classList.toggle('is-selected', parseInt(card.getAttribute('data-thread-id') || '0', 10) === id);
        });
        showThreadMessages(id, json.message_list_html || '');
        workspace.classList.add('reader-open');
        applyLayout();
      })
      .catch(function (err) {
        reader.innerHTML = '<div class="mail-reader-placeholder">' + err.message + '</div>';
      });
  }
  function loadList(folder, q) {
    currentFolder = folder || currentFolder;
    fetch(apiUrl({action: 'list', folder: currentFolder, q: q || ''}), {headers: {'Accept': 'application/json'}})
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (!json.ok) { throw new Error(json.error || 'Unable to load conversations.'); }
        list.innerHTML = json.html || '<div class="mail-empty">No conversations found.</div>';
        updateBulkCount();
        if (json.first_id) { loadThread(parseInt(json.first_id, 10)); }
        if (!json.first_id && currentFolder === 'drafts') {
          reader.innerHTML = '<div class="mail-reader-placeholder"><div><strong>Drafts</strong><p>Select an unsent draft to edit it, or a sent outbox record with a thread to view the conversation.</p></div></div>';
        }
      });
  }
  function postForm(action, data) {
    data.append('action', action);
    return fetch('/admin/compliance/mail_api.php', {method: 'POST', body: data, headers: {'Accept': 'application/json'}}).then(function (r) { return r.json(); });
  }
  function renderTemplatePreview() {
    var style = document.getElementById('composeTemplateStyle').value || 'standard';
    var wrap = document.getElementById('composeTemplatePreviewWrap');
    var preview = document.getElementById('composeTemplatePreview');
    var plainBody = document.getElementById('composePlainBody');
    var editor = document.getElementById('composeEditor');
    if (!wrap || !preview || !plainBody || !editor) { return; }
    wrap.classList.toggle('is-visible', style !== 'none');
    if (style === 'none') {
      plainBody.classList.remove('is-hidden');
      editor.classList.remove('mail-template-body-editor');
      plainBody.appendChild(editor);
      preview.innerHTML = '';
      return;
    }
    plainBody.classList.add('is-hidden');
    editor.classList.add('mail-template-body-editor');
    var subject = document.getElementById('composeSubject').value || 'Compliance email';
    preview.innerHTML = buildComposeTemplateShell(subject);
    var body = preview.querySelector('[data-compose-template-body]');
    if (body) {
      body.insertBefore(editor, body.firstChild);
    } else {
      preview.appendChild(editor);
    }
  }
  function buildComposeTemplateShell(subject) {
    return [
      '<div class="mail-compose-template-card">',
        '<div class="mail-compose-template-hero">',
          '<div>',
            '<div class="mail-compose-template-kicker">IPCA.training | Compliance</div>',
            '<div class="mail-compose-template-title">' + escapeHtml(subject) + '</div>',
            '<div class="mail-compose-template-ref">Thread reference: <strong>CMP-THREAD</strong></div>',
          '</div>',
          '<div class="mail-compose-template-logo">IPCA<span>International Pilot Center Alliance</span></div>',
          '<div class="mail-compose-template-pills">',
            '<span>{{COMPLIANCE_OBJECT_TYPE_1}} · {{COMPLIANCE_OBJECT_CODE_1}}</span>',
            '<span>{{COMPLIANCE_OBJECT_TYPE_2}} · {{COMPLIANCE_OBJECT_CODE_2}}</span>',
          '</div>',
        '</div>',
        '<div class="mail-compose-template-body" data-compose-template-body>',
          '<p class="mail-compose-template-signature">Sincerely,<br><br><strong>Compliance Monitoring Manager</strong><br><em>Compliance</em></p>',
        '</div>',
        '<div class="mail-compose-template-footer"><strong>IMPORTANT NOTES:</strong><br>Please keep the information in this email and any attachments strictly confidential. Compliance object references: Selected compliance objects<br>Message tracking reference: CMP-THREAD</div>',
      '</div>'
    ].join('');
  }
  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (ch) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch] || ch;
    });
  }
  document.querySelectorAll('.mail-resizer').forEach(function (resizer) {
    resizer.addEventListener('pointerdown', function (ev) {
      if (resizer.getAttribute('data-resizer') === 'folders' && layout.foldersCollapsed) {
        layout.foldersCollapsed = false;
        applyLayout();
        saveLayout();
        return;
      }
      startResize(resizer.getAttribute('data-resizer') || '', ev);
    });
  });
  function openComposer(params) {
    params = params || {};
    fetch(apiUrl(Object.assign({action: 'compose_prefill'}, params)), {headers: {'Accept': 'application/json'}})
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (!json.ok) { throw new Error(json.error || 'Unable to open composer.'); }
        var p = json.prefill || {};
        document.getElementById('composeDraftId').value = p.draft_id || '0';
        document.getElementById('composeThreadId').value = p.thread_id || params.thread_id || '0';
        document.getElementById('composeReplyId').value = p.reply_to_email_id || params.reply_to_email_id || '0';
        document.getElementById('composeTo').value = p.to || '';
        document.getElementById('composeCc').value = p.cc || '';
        document.getElementById('composeBcc').value = p.bcc || '';
        document.getElementById('composeSubject').value = p.subject || '';
        document.getElementById('composeTemplateStyle').value = 'standard';
        document.getElementById('composeEditor').innerHTML = p.html_body || '';
        if (!p.html_body) { document.getElementById('composeEditor').innerText = p.text_body || ''; }
        document.getElementById('composeStatus').textContent = 'Draft autosaves when recipient, subject, and message are present.';
        document.getElementById('mailComposerTitle').textContent = p.draft_id ? 'Draft' : (p.reply_to_email_id ? 'Reply' : 'New message');
        renderTemplatePreview();
        document.getElementById('mailComposerBackdrop').classList.add('is-open');
      })
      .catch(function (err) { alert(err.message); });
  }
  function syncComposerBody() {
    var editor = document.getElementById('composeEditor');
    document.getElementById('composeHtmlBody').value = editor.innerHTML;
    document.getElementById('composeTextBody').value = editor.innerText;
  }
  function maybeAutosave() {
    window.clearTimeout(autosaveTimer);
    autosaveTimer = window.setTimeout(function () {
      syncComposerBody();
      if (document.getElementById('composeAttachments').files.length > 0) { return; }
      if (!document.getElementById('composeTo').value || !document.getElementById('composeSubject').value || !document.getElementById('composeTextBody').value) { return; }
      var data = new FormData(document.getElementById('mailComposer'));
      postForm('save_draft', data).then(function (json) {
        if (json.ok) {
          document.getElementById('composeDraftId').value = json.draft_id || '0';
          if (json.thread_id) { document.getElementById('composeThreadId').value = json.thread_id; }
          document.getElementById('composeStatus').textContent = 'Draft saved ' + new Date().toLocaleTimeString();
        }
      });
    }, 1200);
  }
  window.addEventListener('message', function (event) {
    if (!event.data || event.data.type !== 'mailFrameHeight') { return; }
    document.querySelectorAll('.mail-html-frame').forEach(function (frame) {
      try {
        if (frame.contentWindow === event.source) {
          frame.style.height = Math.max(80, parseInt(event.data.height || '0', 10)) + 'px';
        }
      } catch (e) {}
    });
  });
  list.addEventListener('click', function (ev) {
    var checkbox = ev.target.closest('.mail-thread-select input');
    if (checkbox) { ev.stopPropagation(); updateBulkCount(); return; }
    var messageRow = ev.target.closest('[data-jump-email]');
    if (messageRow) {
      ev.stopPropagation();
      jumpToMessage(messageRow.getAttribute('data-jump-email'));
      return;
    }
    var stringToggle = ev.target.closest('[data-thread-string-toggle]');
    if (stringToggle) {
      ev.stopPropagation();
      var stringWrap = stringToggle.closest('.mail-thread-messages');
      if (!stringWrap) { return; }
      var collapsed = !stringWrap.classList.contains('is-collapsed');
      stringWrap.classList.toggle('is-collapsed', collapsed);
      stringToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      stringToggle.setAttribute('aria-label', collapsed ? 'Expand email string' : 'Collapse email string');
      stringToggle.setAttribute('title', collapsed ? 'Expand email string' : 'Collapse email string');
      return;
    }
    var card = ev.target.closest('.mail-thread-card');
    if (!card) { return; }
    if (card.classList.contains('is-draft') && card.getAttribute('data-draft-status') === 'draft') {
      var draftId = parseInt(card.getAttribute('data-draft-id') || '0', 10);
      if (draftId > 0) { openComposer({draft_id: draftId}); }
      return;
    }
    var id = parseInt(card.getAttribute('data-thread-id') || '0', 10);
    if (id > 0) { loadThread(id); }
  });
  list.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter' && ev.key !== ' ') { return; }
    if (ev.target.closest('[data-jump-email]')) { return; }
    var card = ev.target.closest('.mail-thread-card');
    if (!card) { return; }
    ev.preventDefault();
    var id = parseInt(card.getAttribute('data-thread-id') || '0', 10);
    if (id > 0) { loadThread(id); }
  });
  document.querySelectorAll('.mail-folder').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.mail-folder').forEach(function (b) { b.classList.remove('is-active'); });
      btn.classList.add('is-active');
      workspace.classList.remove('folders-open');
      updateToolbarMailbox(btn.getAttribute('data-folder-label') || '', btn.getAttribute('data-folder-count') || '0', btn.getAttribute('data-folder') || '');
      loadList(btn.getAttribute('data-folder') || 'inbox', search.value || '');
    });
  });
  search.addEventListener('input', function () {
    var q = search.value.toLowerCase();
    list.querySelectorAll('.mail-thread-card').forEach(function (card) {
      card.hidden = q !== '' && (card.getAttribute('data-search') || '').indexOf(q) === -1;
    });
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(function () { loadList(currentFolder, search.value || ''); }, 350);
  });
  document.querySelector('[data-folder-toggle]').addEventListener('click', function () {
    layout.foldersCollapsed = !layout.foldersCollapsed;
    applyLayout();
    saveLayout();
  });
  document.querySelectorAll('[data-open-folder]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var folderKey = btn.getAttribute('data-open-folder') || 'inbox';
      var folderBtn = document.querySelector('.mail-folder[data-folder="' + folderKey + '"]');
      if (folderBtn) { folderBtn.click(); return; }
      loadList(folderKey, search.value || '');
    });
  });
  document.querySelector('[data-fullscreen-toggle]').addEventListener('click', function () {
    var page = document.querySelector('.mail-page');
    if (!document.fullscreenElement && page && page.requestFullscreen) {
      page.requestFullscreen();
    } else if (document.fullscreenElement && document.exitFullscreen) {
      document.exitFullscreen();
    }
  });
  document.addEventListener('fullscreenchange', updateFullscreenButton);
  document.querySelector('[data-compose-new]').addEventListener('click', function () { openComposer({thread_id: currentThreadId || 0}); });
  document.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-focus-compose-editor]')) {
      var composeEditor = document.getElementById('composeEditor');
      if (composeEditor) {
        composeEditor.focus();
        var range = document.createRange();
        range.selectNodeContents(composeEditor);
        range.collapse(false);
        var sel = window.getSelection();
        if (sel) {
          sel.removeAllRanges();
          sel.addRange(range);
        }
      }
      ev.preventDefault();
      return;
    }
    var modalOpen = ev.target.closest('[data-compliance-modal-open]');
    if (modalOpen) {
      var modalId = modalOpen.getAttribute('data-compliance-modal-open');
      var modal = modalId ? document.getElementById(modalId) : null;
      if (modal) {
        if (typeof modal.showModal === 'function') {
          modal.showModal();
        } else {
          modal.setAttribute('open', 'open');
        }
      }
      ev.preventDefault();
      return;
    }
    if (ev.target.closest('[data-compliance-modal-close]')) {
      var dialog = ev.target.closest('dialog');
      if (dialog) {
        if (typeof dialog.close === 'function') { dialog.close(); }
        dialog.removeAttribute('open');
      }
      ev.preventDefault();
      return;
    }
    if (ev.target.closest('[data-toolbar-thread-compose]')) {
      openComposer({thread_id: currentThreadId || 0});
    }
    var reply = ev.target.closest('[data-compose-reply]');
    if (reply) { openComposer({reply_to_email_id: reply.getAttribute('data-compose-reply')}); }
    var replyAll = ev.target.closest('[data-compose-reply-all]');
    if (replyAll) { openComposer({reply_to_email_id: replyAll.getAttribute('data-compose-reply-all')}); }
    var thread = ev.target.closest('[data-compose-thread]');
    if (thread) { openComposer({thread_id: thread.getAttribute('data-compose-thread')}); }
    var forward = ev.target.closest('[data-compose-forward]');
    if (forward) { openComposer({forward_email_id: forward.getAttribute('data-compose-forward')}); }
    if (ev.target.closest('[data-print-message]')) {
      var card = ev.target.closest('.mail-message-card');
      if (card) {
        var w = window.open('', '_blank');
        if (w) {
          w.document.write('<!doctype html><html><head><title>Print message</title></head><body>' + card.outerHTML + '</body></html>');
          w.document.close();
          w.focus();
          w.print();
        }
      }
    }
    if (ev.target.closest('[data-compose-close]')) { document.getElementById('mailComposerBackdrop').classList.remove('is-open'); }
    if (ev.target.closest('[data-sidebar-toggle]')) {
      layout.complianceOpen = !layout.complianceOpen;
      if (layout.complianceOpen && layout.complianceWidth < 220) { layout.complianceWidth = 340; }
      applyLayout();
      saveLayout();
    }
    if (ev.target.closest('[data-sidebar-close]')) {
      layout.complianceOpen = false;
      applyLayout();
      saveLayout();
    }
    if (ev.target.closest('[data-compliance-edit]')) {
      var sidebar = ev.target.closest('.mail-compliance-sidebar') || document.querySelector('.mail-compliance-sidebar');
      if (sidebar) { sidebar.classList.toggle('is-editing'); }
    }
    var unlink = ev.target.closest('[data-unlink-id]');
    if (unlink && confirm('Remove this compliance link?')) {
      var fd = new FormData(); fd.append('link_id', unlink.getAttribute('data-unlink-id'));
      postForm('unlink_object', fd).then(function () { loadThread(currentThreadId); });
    }
  });
  reader.addEventListener('submit', function (ev) {
    var updateForm = ev.target.closest('[data-thread-update]');
    var linkForm = ev.target.closest('[data-link-object]');
    if (!updateForm && !linkForm) { return; }
    ev.preventDefault();
    postForm(updateForm ? 'update_thread' : 'link_object', new FormData(ev.target)).then(function (json) {
      if (!json.ok) { alert(json.error || 'Update failed.'); return; }
      loadThread(currentThreadId);
      loadList(currentFolder, search.value || '');
    });
  });
  reader.addEventListener('pointerdown', function (ev) {
    if (ev.target.closest('.mail-compliance-sidebar')) {
      startComplianceResize(ev);
    }
  });
  document.querySelector('[data-bulk-status]').addEventListener('click', function () {
    var ids = selectedIds(); if (ids.length === 0) { return; }
    Promise.all(ids.map(function (id) { var fd = new FormData(); fd.append('thread_id', id); fd.append('status', document.querySelector('[name="bulk_status_value"]').value); return postForm('update_thread', fd); })).then(function () { document.querySelectorAll('.mail-toolbar-menu[open]').forEach(function (m) { m.removeAttribute('open'); }); loadList(currentFolder, search.value || ''); });
  });
  document.querySelector('[data-bulk-priority]').addEventListener('click', function () {
    var ids = selectedIds(); if (ids.length === 0) { return; }
    Promise.all(ids.map(function (id) { var fd = new FormData(); fd.append('thread_id', id); fd.append('priority', document.querySelector('[name="bulk_priority_value"]').value); return postForm('update_thread', fd); })).then(function () { document.querySelectorAll('.mail-toolbar-menu[open]').forEach(function (m) { m.removeAttribute('open'); }); loadList(currentFolder, search.value || ''); });
  });
  document.getElementById('composeEditor').addEventListener('input', maybeAutosave);
  ['composeTo','composeSubject','composeCc','composeBcc'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', maybeAutosave);
  });
  document.getElementById('composeSubject').addEventListener('input', renderTemplatePreview);
  document.getElementById('composeTemplateStyle').addEventListener('change', renderTemplatePreview);
  document.querySelectorAll('[data-format-cmd]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('composeEditor').focus();
      document.execCommand(btn.getAttribute('data-format-cmd') || '', false, null);
      maybeAutosave();
    });
  });
  var formatFont = document.querySelector('[data-format-font]');
  if (formatFont) {
    formatFont.addEventListener('change', function () {
      document.getElementById('composeEditor').focus();
      document.execCommand('fontName', false, formatFont.value);
      maybeAutosave();
    });
  }
  var formatSize = document.querySelector('[data-format-size]');
  if (formatSize) {
    formatSize.addEventListener('change', function () {
      document.getElementById('composeEditor').focus();
      document.execCommand('fontSize', false, formatSize.value);
      maybeAutosave();
    });
  }
  var formatColor = document.querySelector('[data-format-color]');
  if (formatColor) {
    formatColor.addEventListener('input', function () {
      document.getElementById('composeEditor').focus();
      document.execCommand('foreColor', false, formatColor.value);
      maybeAutosave();
    });
  }
  (function () {
    var composer = document.getElementById('mailComposer');
    var input = document.getElementById('composeAttachments');
    if (!composer || !input || typeof DataTransfer === 'undefined') { return; }
    var dragDepth = 0;
    function hasFiles(ev) {
      if (!ev.dataTransfer || !ev.dataTransfer.types) { return false; }
      return Array.prototype.indexOf.call(ev.dataTransfer.types, 'Files') !== -1;
    }
    function mergeFiles(files) {
      var dt = new DataTransfer();
      Array.prototype.forEach.call(input.files || [], function (file) { dt.items.add(file); });
      Array.prototype.forEach.call(files || [], function (file) { dt.items.add(file); });
      input.files = dt.files;
      document.getElementById('composeStatus').textContent = input.files.length + ' attachment(s) ready.';
    }
    composer.addEventListener('dragenter', function (ev) {
      if (!hasFiles(ev)) { return; }
      ev.preventDefault(); dragDepth++; composer.classList.add('is-dragging');
    });
    composer.addEventListener('dragover', function (ev) {
      if (!hasFiles(ev)) { return; }
      ev.preventDefault(); ev.dataTransfer.dropEffect = 'copy';
    });
    composer.addEventListener('dragleave', function () {
      dragDepth = Math.max(0, dragDepth - 1);
      if (dragDepth === 0) { composer.classList.remove('is-dragging'); }
    });
    composer.addEventListener('drop', function (ev) {
      if (!hasFiles(ev)) { return; }
      ev.preventDefault(); dragDepth = 0; composer.classList.remove('is-dragging');
      mergeFiles(ev.dataTransfer.files);
    });
  })();
  document.getElementById('mailComposer').addEventListener('submit', function (ev) {
    ev.preventDefault();
    syncComposerBody();
    var submitter = ev.submitter;
    var action = submitter && submitter.getAttribute('data-compose-action') === 'send_now' ? 'send_now' : 'save_draft';
    var status = document.getElementById('composeStatus');
    status.textContent = action === 'send_now' ? 'Sending...' : 'Saving draft...';
    postForm(action, new FormData(ev.target)).then(function (json) {
      if (!json.ok) { status.textContent = json.error || 'Action failed.'; return; }
      status.textContent = action === 'send_now' ? 'Sent.' : 'Draft saved.';
      if (json.draft_id) { document.getElementById('composeDraftId').value = json.draft_id; }
      if (json.thread_id) { currentThreadId = parseInt(json.thread_id, 10); loadList(currentFolder, search.value || ''); loadThread(currentThreadId); }
      if (action === 'send_now') { document.getElementById('mailComposerBackdrop').classList.remove('is-open'); }
    });
  });
  applyLayout();
  if (currentThreadId > 0) {
    loadThread(currentThreadId);
  }
})();
</script>
<?php
cw_footer();
