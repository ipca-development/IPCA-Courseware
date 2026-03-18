<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/notification_service.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

function ne_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function ne_post_bool(string $key): int
{
    return !empty($_POST[$key]) ? 1 : 0;
}

function ne_decode_allowed_variables(?string $json): array
{
    $json = trim((string)$json);
    if ($json === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $out = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }

        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $out[] = [
            'name' => $name,
            'label' => trim((string)($row['label'] ?? $name)),
            'type' => trim((string)($row['type'] ?? 'text')),
            'safe_mode' => trim((string)($row['safe_mode'] ?? 'escaped')),
            'required' => !empty($row['required']),
            'sample_value' => (string)($row['sample_value'] ?? ''),
            'description' => trim((string)($row['description'] ?? '')),
        ];
    }

    return $out;
}

function ne_find_template(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("
        SELECT *
        FROM notification_templates
        WHERE id = ?
        LIMIT 1
    ");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Missing template id');
}

$service = new NotificationService($pdo);
$template = ne_find_template($pdo, $id);
if (!$template) {
    http_response_code(404);
    exit('Notification template not found');
}

$latestVersion = $service->getLatestTemplateVersion((int)$template['id']);
$actorUserId = (int)($u['id'] ?? 0);

$flashSuccess = '';
$flashError = '';
$previewResult = null;
$testSendResult = null;

$draft = [
    'is_enabled' => (int)($template['is_enabled'] ?? 0),
    'subject_template' => (string)($template['subject_template'] ?? ''),
    'html_template' => (string)($template['html_template'] ?? ''),
    'text_template' => (string)($template['text_template'] ?? ''),
];

$dummyOverrides = [];
$testEmail = trim((string)($_POST['test_email'] ?? ''));
$testName = trim((string)($_POST['test_name'] ?? ''));
$changeNote = trim((string)($_POST['change_note'] ?? ''));

$allowedVariables = ne_decode_allowed_variables((string)($template['allowed_variables_json'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $draft = [
        'is_enabled' => ne_post_bool('is_enabled'),
        'subject_template' => (string)($_POST['subject_template'] ?? ''),
        'html_template' => (string)($_POST['html_template'] ?? ''),
        'text_template' => (string)($_POST['text_template'] ?? ''),
    ];

    foreach ($allowedVariables as $meta) {
        $varName = (string)$meta['name'];
        $fieldKey = 'dummy_' . $varName;
        if (array_key_exists($fieldKey, $_POST)) {
            $dummyOverrides[$varName] = (string)$_POST[$fieldKey];
        }
    }

    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'save_template') {
            $save = $service->saveTemplate(
                (int)$template['id'],
                $draft,
                $actorUserId,
                $changeNote !== '' ? $changeNote : null
            );

            $template = ne_find_template($pdo, $id);
            $latestVersion = $service->getLatestTemplateVersion((int)$template['id']);
            $allowedVariables = ne_decode_allowed_variables((string)($template['allowed_variables_json'] ?? ''));

            $draft = [
                'is_enabled' => (int)($template['is_enabled'] ?? 0),
                'subject_template' => (string)($template['subject_template'] ?? ''),
                'html_template' => (string)($template['html_template'] ?? ''),
                'text_template' => (string)($template['text_template'] ?? ''),
            ];

            $flashSuccess = 'Template saved successfully as version v' . (int)$save['version_no'] . '.';
        } elseif ($action === 'preview_template') {
            $previewResult = $service->renderPreview(
                (string)$template['notification_key'],
                $draft,
                $actorUserId,
                $dummyOverrides
            );
            $flashSuccess = 'Draft preview rendered successfully.';
        } elseif ($action === 'send_test') {
            $testSendResult = $service->sendTest(
                (string)$template['notification_key'],
                $draft,
                $testEmail,
                $testName,
                $actorUserId,
                $dummyOverrides
            );

            if (!empty($testSendResult['ok'])) {
                $flashSuccess = 'Test email sent successfully.';
            } else {
                $flashError = 'Test email failed: ' . (string)($testSendResult['error'] ?? 'Unknown error');
            }

            $previewResult = $service->renderPreview(
                (string)$template['notification_key'],
                $draft,
                $actorUserId,
                $dummyOverrides
            );
        }
    } catch (Throwable $e) {
        $flashError = $e->getMessage();
    }
}

if (!$previewResult) {
    try {
        $previewResult = $service->renderPreview(
            (string)$template['notification_key'],
            $draft,
            $actorUserId,
            $dummyOverrides
        );
    } catch (Throwable $e) {
        $flashError = $flashError !== '' ? $flashError : $e->getMessage();
    }
}

$liveVersionNo = (int)($latestVersion['version_no'] ?? 0);
$templateName = trim((string)($template['name'] ?? 'Notification Template'));
$templateKey = trim((string)($template['notification_key'] ?? ''));
$templateDescription = trim((string)($template['description'] ?? ''));
$updatedAt = trim((string)($template['updated_at'] ?? ''));
$updatedAtDisplay = $updatedAt !== '' ? date('D, M j, Y g:i A', strtotime($updatedAt)) : '—';

$previewSubject = is_array($previewResult) ? (string)($previewResult['rendered_subject'] ?? '') : '';
$previewHtml = is_array($previewResult) ? (string)($previewResult['rendered_html'] ?? '') : '';
$previewText = is_array($previewResult) ? (string)($previewResult['rendered_text'] ?? '') : '';
$previewValidation = is_array($previewResult) ? (array)($previewResult['validation'] ?? []) : [];
$usedDummyContext = is_array($previewResult) ? (array)($previewResult['context'] ?? []) : [];

$unknownTokens = is_array($previewValidation['unknown_tokens'] ?? null) ? $previewValidation['unknown_tokens'] : [];
$missingRequired = is_array($previewValidation['missing_required_variables'] ?? null) ? $previewValidation['missing_required_variables'] : [];

$previewSrcdoc = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<style>body{font-family:Arial,Helvetica,sans-serif;margin:0;padding:20px;background:#ffffff;color:#1f2937;line-height:1.5}a{color:#1d4ed8}p{margin:0 0 14px}strong{font-weight:700}</style>'
    . '</head><body>' . $previewHtml . '</body></html>';

cw_header('Edit Notification Template');
?>
<style>
  .ne-page{
    max-width:1440px;
    margin:0 auto;
  }

  .ne-hero{
    background:
      linear-gradient(180deg, rgba(255,255,255,0.018) 0%, rgba(255,255,255,0.00) 100%),
      linear-gradient(180deg, var(--sidebar-bg) 0%, var(--sidebar-bg-2) 100%);
    color:#fff;
    border-radius:22px;
    padding:24px 26px;
    box-shadow:
      inset 0 1px 0 rgba(255,255,255,0.04),
      0 16px 40px rgba(13, 29, 52, 0.18);
    margin-bottom:20px;
  }

  .ne-kicker{
    font-size:12px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.12em;
    color:rgba(255,255,255,0.82);
    margin-bottom:6px;
  }

  .ne-title{
    font-size:30px;
    line-height:1.1;
    font-weight:900;
    margin:0;
    color:#fff;
  }

  .ne-sub{
    margin-top:10px;
    max-width:1000px;
    color:rgba(255,255,255,0.90);
    line-height:1.55;
    font-size:15px;
  }

  .ne-meta-row{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:16px;
  }

  .ne-chip{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 12px;
    border-radius:999px;
    background:rgba(255,255,255,0.14);
    border:1px solid rgba(255,255,255,0.18);
    color:#fff;
    font-size:12px;
    font-weight:900;
  }

  .ne-grid{
    display:grid;
    grid-template-columns:minmax(0, 1.06fr) minmax(420px, .94fr);
    gap:20px;
    align-items:start;
  }

  .ne-card{
    background:var(--panel-bg);
    border:1px solid var(--border-soft);
    border-radius:22px;
    box-shadow:var(--card-shadow);
    overflow:hidden;
  }

  .ne-card-head{
    padding:18px 20px 14px;
    border-bottom:1px solid var(--border-soft);
    background:#fbfcfe;
  }

  .ne-card-title{
    font-size:18px;
    font-weight:900;
    color:var(--text-strong);
    margin:0;
  }

  .ne-card-sub{
    margin-top:6px;
    color:var(--text-muted);
    font-size:13px;
    line-height:1.45;
  }

  .ne-card-body{
    padding:18px 20px 22px;
  }

  .ne-alert{
    border-radius:16px;
    padding:14px 16px;
    margin-bottom:16px;
    font-weight:800;
    line-height:1.45;
  }

  .ne-alert.success{
    background:#ecfdf5;
    border:1px solid #bbf7d0;
    color:#166534;
  }

  .ne-alert.error{
    background:#fff1f2;
    border:1px solid #fecdd3;
    color:#be123c;
  }

  .ne-form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
  }

  .ne-field{
    display:flex;
    flex-direction:column;
    gap:8px;
    margin-bottom:16px;
  }

  .ne-field.full{
    grid-column:1 / -1;
  }

  .ne-label{
    font-size:13px;
    font-weight:900;
    color:var(--text-strong);
  }

  .ne-help{
    color:var(--text-muted);
    font-size:12px;
    line-height:1.45;
  }

  .ne-input,
  .ne-textarea{
    width:100%;
    box-sizing:border-box;
    border:1px solid rgba(15,23,42,0.10);
    border-radius:14px;
    padding:12px 14px;
    font-size:14px;
    color:var(--text-strong);
    background:#fff;
    outline:none;
  }

  .ne-input:focus,
  .ne-textarea:focus{
    border-color:rgba(110,174,252,0.70);
    box-shadow:0 0 0 4px rgba(110,174,252,0.12);
  }

  .ne-textarea{
    min-height:150px;
    resize:vertical;
    font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
    line-height:1.45;
  }

  .ne-textarea.html{
    min-height:320px;
  }

  .ne-checkbox-row{
    display:flex;
    align-items:center;
    gap:10px;
    min-height:48px;
    padding:0 2px;
  }

  .ne-checkbox-row input[type="checkbox"]{
    width:18px;
    height:18px;
  }

  .ne-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:10px;
  }

  .ne-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    min-height:44px;
    padding:0 16px;
    border-radius:14px;
    border:1px solid rgba(15,23,42,0.10);
    background:#fff;
    color:var(--text-strong);
    font-weight:900;
    font-size:14px;
    cursor:pointer;
    transition:.15s ease;
    text-decoration:none;
  }

  .ne-btn:hover{
    background:#f8fbff;
    border-color:rgba(15,23,42,0.18);
  }

  .ne-btn.primary{
    color:#fff;
    border-color:transparent;
    background:
      linear-gradient(180deg, rgba(255,255,255,0.018) 0%, rgba(255,255,255,0.00) 100%),
      linear-gradient(180deg, var(--sidebar-bg) 0%, var(--sidebar-bg-2) 100%);
    box-shadow:0 8px 24px rgba(13, 29, 52, 0.18);
  }

  .ne-btn.primary:hover{
    filter:brightness(1.03);
  }

  .ne-btn.warn{
    background:#eff6ff;
    border-color:#bfdbfe;
    color:#1d4ed8;
  }

  .ne-split{
    display:grid;
    grid-template-columns:minmax(0,1fr) minmax(0,1fr);
    gap:16px;
  }

  .ne-var-table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
  }

  .ne-var-table th{
    text-align:left;
    font-size:12px;
    color:var(--text-strong);
    text-transform:uppercase;
    letter-spacing:.05em;
    padding:11px 10px;
    background:#f8fafc;
    border-bottom:1px solid var(--border-soft);
    white-space:nowrap;
  }

  .ne-var-table td{
    padding:12px 10px;
    border-bottom:1px solid rgba(15,23,42,0.05);
    vertical-align:top;
    font-size:13px;
    color:var(--text-strong);
  }

  .ne-var-table tr:last-child td{
    border-bottom:none;
  }

  .ne-token{
    display:inline-block;
    font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
    font-size:12px;
    line-height:1.35;
    color:#1d4ed8;
    background:#eff6ff;
    border:1px solid #bfdbfe;
    border-radius:999px;
    padding:4px 8px;
  }

  .ne-pill{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:5px 9px;
    border-radius:999px;
    font-size:11px;
    font-weight:900;
    white-space:nowrap;
    border:1px solid transparent;
  }

  .ne-pill.text{
    background:#eef2ff;
    color:#3730a3;
    border-color:#c7d2fe;
  }

  .ne-pill.html{
    background:#ecfeff;
    color:#155e75;
    border-color:#a5f3fc;
  }

  .ne-pill.req{
    background:#fff7ed;
    color:#c2410c;
    border-color:#fdba74;
  }

  .ne-pill.opt{
    background:#f8fafc;
    color:#475569;
    border-color:#e2e8f0;
  }

  .ne-preview-tabs{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-bottom:12px;
  }

  .ne-preview-tab{
    padding:8px 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    border:1px solid rgba(15,23,42,0.10);
    background:#fff;
    color:var(--text-strong);
    cursor:pointer;
  }

  .ne-preview-tab.active{
    background:#eff6ff;
    border-color:#bfdbfe;
    color:#1d4ed8;
  }

  .ne-preview-shell{
    border:1px solid rgba(15,23,42,0.08);
    border-radius:18px;
    padding:14px;
    background:#f8fafc;
  }

  .ne-preview-subject{
    background:#fff;
    border:1px solid rgba(15,23,42,0.08);
    border-radius:14px;
    padding:12px 14px;
    margin-bottom:12px;
  }

  .ne-preview-subject-label{
    font-size:12px;
    font-weight:900;
    color:var(--text-muted);
    text-transform:uppercase;
    letter-spacing:.06em;
    margin-bottom:6px;
  }

  .ne-preview-subject-value{
    font-size:15px;
    font-weight:900;
    color:var(--text-strong);
    word-break:break-word;
  }

  .ne-preview-frame-wrap{
    border:1px solid rgba(15,23,42,0.08);
    border-radius:18px;
    overflow:hidden;
    background:#fff;
    transition:max-width .15s ease;
  }

  .ne-preview-frame-wrap.desktop{
    max-width:100%;
  }

  .ne-preview-frame-wrap.mobile{
    max-width:420px;
    margin:0 auto;
  }

  .ne-preview-frame{
    width:100%;
    min-height:540px;
    border:0;
    display:block;
    background:#fff;
  }

  .ne-preview-plain{
    margin-top:14px;
    background:#fff;
    border:1px solid rgba(15,23,42,0.08);
    border-radius:14px;
    padding:14px;
  }

  .ne-preview-plain pre{
    margin:0;
    white-space:pre-wrap;
    word-break:break-word;
    font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
    font-size:12px;
    line-height:1.5;
    color:#111827;
  }

  .ne-validation{
    display:grid;
    gap:10px;
    margin-bottom:14px;
  }

  .ne-validation-box{
    border-radius:14px;
    padding:12px 14px;
    border:1px solid rgba(15,23,42,0.08);
    background:#fff;
  }

  .ne-validation-box.warn{
    border-color:#fdba74;
    background:#fff7ed;
    color:#9a3412;
  }

  .ne-validation-box.ok{
    border-color:#bbf7d0;
    background:#ecfdf5;
    color:#166534;
  }

  .ne-list{
    margin:8px 0 0;
    padding-left:18px;
  }

  .ne-list li{
    margin:4px 0;
  }

  .ne-dummy-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:14px 16px;
  }

  .ne-field.compact{
    margin-bottom:0;
  }

  .ne-test-meta{
    margin-top:12px;
    padding:12px 14px;
    border-radius:14px;
    border:1px solid #dbeafe;
    background:#eff6ff;
    color:#1e3a8a;
    font-size:13px;
    line-height:1.5;
  }

  .ne-code{
    font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
  }

  @media (max-width: 1180px){
    .ne-grid{
      grid-template-columns:1fr;
    }
  }

  @media (max-width: 820px){
    .ne-form-grid,
    .ne-split,
    .ne-dummy-grid{
      grid-template-columns:1fr;
    }

    .ne-title{
      font-size:26px;
    }

    .ne-hero{
      padding:20px 18px;
    }
  }
</style>

<div class="ne-page">
  <div class="ne-hero">
    <div class="ne-kicker">Admin / Notification Control</div>
    <h1 class="ne-title"><?= ne_h($templateName) ?></h1>
    <div class="ne-sub">
      <?= $templateDescription !== '' ? nl2br(ne_h($templateDescription)) : 'Edit the saved live template, preview the current draft, and send safe test emails using dummy data only.' ?>
    </div>

    <div class="ne-meta-row">
      <div class="ne-chip">Key: <?= ne_h($templateKey) ?></div>
      <div class="ne-chip">Channel: Email</div>
      <div class="ne-chip">Live Version: <?= $liveVersionNo > 0 ? ('v' . $liveVersionNo) : '—' ?></div>
      <div class="ne-chip">Updated: <?= ne_h($updatedAtDisplay) ?></div>
    </div>
  </div>

  <?php if ($flashSuccess !== ''): ?>
    <div class="ne-alert success"><?= nl2br(ne_h($flashSuccess)) ?></div>
  <?php endif; ?>

  <?php if ($flashError !== ''): ?>
    <div class="ne-alert error"><?= nl2br(ne_h($flashError)) ?></div>
  <?php endif; ?>

  <div class="ne-grid">
    <div class="ne-card">
      <div class="ne-card-head">
        <h2 class="ne-card-title">Edit Template</h2>
        <div class="ne-card-sub">
          Preview and test-send use the current draft below. Save creates a new live version snapshot.
        </div>
      </div>
      <div class="ne-card-body">
        <form method="post">
          <input type="hidden" name="id" value="<?= (int)$id ?>">

          <div class="ne-form-grid">
            <div class="ne-field">
              <div class="ne-label">Notification Key</div>
              <input class="ne-input" type="text" value="<?= ne_h($templateKey) ?>" disabled>
              <div class="ne-help">Canonical identifier for this notification template.</div>
            </div>

            <div class="ne-field">
              <div class="ne-label">Change Note</div>
              <input class="ne-input" type="text" name="change_note" value="<?= ne_h($changeNote) ?>" placeholder="Optional note for version history">
              <div class="ne-help">Stored only when saving a new live version.</div>
            </div>

            <div class="ne-field full">
              <div class="ne-checkbox-row">
                <input type="checkbox" id="is_enabled" name="is_enabled" value="1" <?= !empty($draft['is_enabled']) ? 'checked' : '' ?>>
                <label class="ne-label" for="is_enabled" style="margin:0;">Enable this notification for real progression sends</label>
              </div>
              <div class="ne-help">
                If disabled, real notifications will be suppressed before queue creation. Preview and test-send remain available.
              </div>
            </div>

            <div class="ne-field full">
              <div class="ne-label">Subject Template</div>
              <textarea class="ne-textarea" name="subject_template" rows="4"><?= ne_h((string)$draft['subject_template']) ?></textarea>
              <div class="ne-help">Token-only rendering. Example: <span class="ne-code">{{student_name}}</span></div>
            </div>

            <div class="ne-field full">
              <div class="ne-label">HTML Template</div>
              <textarea class="ne-textarea html" name="html_template"><?= ne_h((string)$draft['html_template']) ?></textarea>
              <div class="ne-help">
                HTML email body. Approved HTML variables render only when marked safe in variable metadata.
              </div>
            </div>

            <div class="ne-field full">
              <div class="ne-label">Plain-Text Template</div>
              <textarea class="ne-textarea" name="text_template"><?= ne_h((string)$draft['text_template']) ?></textarea>
              <div class="ne-help">
                Optional. If blank, plain-text preview will be derived from the rendered HTML.
              </div>
            </div>
          </div>

          <div class="ne-actions">
            <button class="ne-btn primary" type="submit" name="action" value="save_template">Save Live Version</button>
            <button class="ne-btn warn" type="submit" name="action" value="preview_template">Refresh Preview</button>
            <a class="ne-btn" href="/admin/notification_versions.php?id=<?= (int)$id ?>">View Version History</a>
            <a class="ne-btn" href="/admin/notifications.php">Back to List</a>
          </div>

          <div class="ne-card" style="margin-top:18px;">
            <div class="ne-card-head">
              <h3 class="ne-card-title">Test Send</h3>
              <div class="ne-card-sub">
                Sends the current draft only. No queue row is created. No progression state or required actions are altered.
              </div>
            </div>
            <div class="ne-card-body">
              <div class="ne-split">
                <div class="ne-field">
                  <div class="ne-label">Test Recipient Email</div>
                  <input class="ne-input" type="email" name="test_email" value="<?= ne_h($testEmail) ?>" placeholder="admin@example.com">
                </div>

                <div class="ne-field">
                  <div class="ne-label">Test Recipient Name</div>
                  <input class="ne-input" type="text" name="test_name" value="<?= ne_h($testName) ?>" placeholder="Admin User">
                </div>
              </div>

              <div class="ne-test-meta">
                Test emails are always marked server-side with a <strong>[TEST]</strong> subject prefix and a visible banner injected into the message body.
              </div>

              <div class="ne-actions" style="margin-top:14px;">
                <button class="ne-btn primary" type="submit" name="action" value="send_test">Send Test Email</button>
              </div>

              <?php if (is_array($testSendResult)): ?>
                <div class="ne-test-meta">
                  <strong>Latest test-send result:</strong><br>
                  Status: <?= !empty($testSendResult['ok']) ? 'Success' : 'Failed' ?><br>
                  Provider: <?= ne_h((string)($testSendResult['provider'] ?? 'smtp')) ?><br>
                  <?php if (!empty($testSendResult['message_id'])): ?>
                    Message ID: <?= ne_h((string)$testSendResult['message_id']) ?><br>
                  <?php endif; ?>
                  <?php if (!empty($testSendResult['error'])): ?>
                    Error: <?= ne_h((string)$testSendResult['error']) ?>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="ne-card" style="margin-top:18px;">
            <div class="ne-card-head">
              <h3 class="ne-card-title">Dummy Context Values</h3>
              <div class="ne-card-sub">
                These values are used for preview and test-send. Leave them as-is for the default seeded dataset or change them manually.
              </div>
            </div>
            <div class="ne-card-body">
              <div class="ne-dummy-grid">
                <?php foreach ($allowedVariables as $meta): ?>
                  <?php
                    $varName = (string)$meta['name'];
                    $fieldKey = 'dummy_' . $varName;
                    $fieldValue = array_key_exists($varName, $dummyOverrides)
                      ? (string)$dummyOverrides[$varName]
                      : (string)($usedDummyContext[$varName] ?? (string)($meta['sample_value'] ?? ''));
                    $isHtmlVar = (string)($meta['safe_mode'] ?? '') === 'approved_html';
                  ?>
                  <div class="ne-field compact">
                    <div class="ne-label">
                      <?= ne_h($meta['label']) ?>
                      <span class="ne-token">{{<?= ne_h($varName) ?>}}</span>
                    </div>
                    <?php if ($isHtmlVar): ?>
                      <textarea class="ne-textarea" name="<?= ne_h($fieldKey) ?>" rows="4"><?= ne_h($fieldValue) ?></textarea>
                    <?php else: ?>
                      <input class="ne-input" type="text" name="<?= ne_h($fieldKey) ?>" value="<?= ne_h($fieldValue) ?>">
                    <?php endif; ?>
                    <div class="ne-help">
                      <?= ne_h((string)($meta['description'] ?? '')) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </form>
      </div>
    </div>

    <div style="display:grid; gap:20px;">
      <div class="ne-card">
        <div class="ne-card-head">
          <h2 class="ne-card-title">Variable Help</h2>
          <div class="ne-card-sub">
            Allowed template variables for this notification type, including sample values, safety mode, and required status.
          </div>
        </div>
        <div class="ne-card-body" style="padding-top:0;">
          <table class="ne-var-table">
            <thead>
              <tr>
                <th>Variable</th>
                <th>Type</th>
                <th>Requirement</th>
                <th>Sample</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($allowedVariables as $meta): ?>
                <?php
                  $safeMode = (string)($meta['safe_mode'] ?? 'escaped');
                  $isHtmlVar = $safeMode === 'approved_html';
                ?>
                <tr>
                  <td>
                    <div class="ne-token">{{<?= ne_h((string)$meta['name']) ?>}}</div>
                    <?php if (!empty($meta['description'])): ?>
                      <div class="ne-help" style="margin-top:6px;"><?= ne_h((string)$meta['description']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="ne-pill <?= $isHtmlVar ? 'html' : 'text' ?>">
                      <?= $isHtmlVar ? 'Safe HTML' : 'Escaped Text' ?>
                    </span>
                  </td>
                  <td>
                    <span class="ne-pill <?= !empty($meta['required']) ? 'req' : 'opt' ?>">
                      <?= !empty($meta['required']) ? 'Required' : 'Optional' ?>
                    </span>
                  </td>
                  <td>
                    <div style="max-width:260px; white-space:pre-wrap; word-break:break-word;"><?= ne_h((string)($meta['sample_value'] ?? '')) ?></div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$allowedVariables): ?>
                <tr>
                  <td colspan="4">No allowed variables defined.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="ne-card">
        <div class="ne-card-head">
          <h2 class="ne-card-title">Draft Preview</h2>
          <div class="ne-card-sub">
            This preview uses the current draft content and dummy/manual values only. No save is required.
          </div>
        </div>
        <div class="ne-card-body">
          <div class="ne-validation">
            <?php if ($unknownTokens || $missingRequired): ?>
              <?php if ($unknownTokens): ?>
                <div class="ne-validation-box warn">
                  <strong>Unknown tokens detected</strong>
                  <ul class="ne-list">
                    <?php foreach ($unknownTokens as $token): ?>
                      <li><span class="ne-code">{{<?= ne_h((string)$token) ?>}}</span></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php endif; ?>

              <?php if ($missingRequired): ?>
                <div class="ne-validation-box warn">
                  <strong>Missing required variables in preview context</strong>
                  <ul class="ne-list">
                    <?php foreach ($missingRequired as $token): ?>
                      <li><span class="ne-code"><?= ne_h((string)$token) ?></span></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php endif; ?>
            <?php else: ?>
              <div class="ne-validation-box ok">
                <strong>Validation OK</strong><br>
                No unknown tokens detected. Required variables are present in the current preview context.
              </div>
            <?php endif; ?>
          </div>

          <div class="ne-preview-shell">
            <div class="ne-preview-subject">
              <div class="ne-preview-subject-label">Rendered Subject</div>
              <div class="ne-preview-subject-value"><?= ne_h($previewSubject) ?></div>
            </div>

            <div class="ne-preview-tabs">
              <button class="ne-preview-tab active" type="button" data-preview-mode="desktop">Desktop Width</button>
              <button class="ne-preview-tab" type="button" data-preview-mode="mobile">Mobile Width</button>
            </div>

            <div class="ne-preview-frame-wrap desktop" id="previewFrameWrap">
              <iframe
                id="previewFrame"
                class="ne-preview-frame"
                sandbox=""
                srcdoc="<?= ne_h($previewSrcdoc) ?>"
              ></iframe>
            </div>

            <div class="ne-preview-plain">
              <div class="ne-preview-subject-label">Rendered Plain Text</div>
              <pre><?= ne_h($previewText) ?></pre>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var tabs = document.querySelectorAll('.ne-preview-tab');
  var wrap = document.getElementById('previewFrameWrap');
  if (!tabs.length || !wrap) return;

  function activate(mode){
    for (var i = 0; i < tabs.length; i++) {
      var t = tabs[i];
      if (t.getAttribute('data-preview-mode') === mode) {
        t.classList.add('active');
      } else {
        t.classList.remove('active');
      }
    }

    wrap.classList.remove('desktop', 'mobile');
    wrap.classList.add(mode === 'mobile' ? 'mobile' : 'desktop');
  }

  for (var i = 0; i < tabs.length; i++) {
    tabs[i].addEventListener('click', function(){
      activate(this.getAttribute('data-preview-mode') || 'desktop');
    });
  }
})();
</script>

<?php cw_footer(); ?>