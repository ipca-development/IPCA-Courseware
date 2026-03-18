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

function nv_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function nv_find_template(PDO $pdo, int $id): ?array
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

function nv_find_version(PDO $pdo, int $templateId, int $versionId): ?array
{
    $st = $pdo->prepare("
        SELECT *
        FROM notification_template_versions
        WHERE id = ?
          AND notification_template_id = ?
        LIMIT 1
    ");
    $st->execute([$versionId, $templateId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function nv_decode_allowed_variables(?string $json): array
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

function nv_render_srcdoc(string $html): string
{
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<style>body{font-family:Arial,Helvetica,sans-serif;margin:0;padding:20px;background:#ffffff;color:#1f2937;line-height:1.5}a{color:#1d4ed8}p{margin:0 0 14px}strong{font-weight:700}</style>'
        . '</head><body>' . $html . '</body></html>';
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Missing template id');
}

$service = new NotificationService($pdo);
$template = nv_find_template($pdo, $id);
if (!$template) {
    http_response_code(404);
    exit('Notification template not found');
}

$actorUserId = (int)($u['id'] ?? 0);
$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'restore_version') {
            $versionId = (int)($_POST['version_id'] ?? 0);
            if ($versionId <= 0) {
                throw new RuntimeException('Missing version_id.');
            }

            $changeNote = trim((string)($_POST['change_note'] ?? ''));
            $restore = $service->restoreVersion(
                (int)$template['id'],
                $versionId,
                $actorUserId,
                $changeNote !== '' ? $changeNote : null
            );

            $template = nv_find_template($pdo, $id);
            $flashSuccess = 'Version restored successfully as new live version v' . (int)$restore['version_no'] . '.';
        }
    } catch (Throwable $e) {
        $flashError = $e->getMessage();
    }
}

$template = nv_find_template($pdo, $id);
$versions = $service->listTemplateVersions((int)$template['id']);
$latestVersion = $service->getLatestTemplateVersion((int)$template['id']);
$liveVersionNo = (int)($latestVersion['version_no'] ?? 0);

$selectedVersionId = (int)($_GET['version_id'] ?? 0);
if ($selectedVersionId <= 0 && $versions) {
    $selectedVersionId = (int)($versions[0]['id'] ?? 0);
}
$selectedVersion = $selectedVersionId > 0 ? nv_find_version($pdo, (int)$template['id'], $selectedVersionId) : null;
if (!$selectedVersion && $versions) {
    $selectedVersion = $versions[0];
    $selectedVersionId = (int)($selectedVersion['id'] ?? 0);
}

$templateName = trim((string)($template['name'] ?? 'Notification Template'));
$templateKey = trim((string)($template['notification_key'] ?? ''));
$templateDescription = trim((string)($template['description'] ?? ''));

$previewResult = null;
$previewError = '';

if ($selectedVersion) {
    try {
        $draft = [
            'is_enabled' => (int)($template['is_enabled'] ?? 0),
            'subject_template' => (string)($selectedVersion['subject_template'] ?? ''),
            'html_template' => (string)($selectedVersion['html_template'] ?? ''),
            'text_template' => (string)($selectedVersion['text_template'] ?? ''),
        ];

        $previewResult = $service->renderPreview(
            (string)$template['notification_key'],
            $draft,
            $actorUserId,
            []
        );
    } catch (Throwable $e) {
        $previewError = $e->getMessage();
    }
}

$selectedVersionNo = (int)($selectedVersion['version_no'] ?? 0);
$selectedChangeNote = trim((string)($selectedVersion['change_note'] ?? ''));
$selectedCreatedAt = trim((string)($selectedVersion['created_at'] ?? ''));
$selectedCreatedAtDisplay = $selectedCreatedAt !== '' ? date('D, M j, Y g:i A', strtotime($selectedCreatedAt)) : '—';
$selectedChangedByUserId = isset($selectedVersion['changed_by_user_id']) && $selectedVersion['changed_by_user_id'] !== null
    ? (int)$selectedVersion['changed_by_user_id']
    : null;

$selectedVariables = nv_decode_allowed_variables((string)($selectedVersion['allowed_variables_json'] ?? ''));
$previewSubject = is_array($previewResult) ? (string)($previewResult['rendered_subject'] ?? '') : '';
$previewHtml = is_array($previewResult) ? (string)($previewResult['rendered_html'] ?? '') : '';
$previewText = is_array($previewResult) ? (string)($previewResult['rendered_text'] ?? '') : '';
$previewSrcdoc = nv_render_srcdoc($previewHtml);

cw_header('Notification Version History');
?>
<style>
  .nv-page{
    max-width:1440px;
    margin:0 auto;
  }

  .nv-hero{
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

  .nv-kicker{
    font-size:12px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.12em;
    color:rgba(255,255,255,0.82);
    margin-bottom:6px;
  }

  .nv-title{
    font-size:30px;
    line-height:1.1;
    font-weight:900;
    margin:0;
    color:#fff;
  }

  .nv-sub{
    margin-top:10px;
    max-width:1000px;
    color:rgba(255,255,255,0.90);
    line-height:1.55;
    font-size:15px;
  }

  .nv-meta-row{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:16px;
  }

  .nv-chip{
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

  .nv-alert{
    border-radius:16px;
    padding:14px 16px;
    margin-bottom:16px;
    font-weight:800;
    line-height:1.45;
  }

  .nv-alert.success{
    background:#ecfdf5;
    border:1px solid #bbf7d0;
    color:#166534;
  }

  .nv-alert.error{
    background:#fff1f2;
    border:1px solid #fecdd3;
    color:#be123c;
  }

  .nv-grid{
    display:grid;
    grid-template-columns:420px minmax(0, 1fr);
    gap:20px;
    align-items:start;
  }

  .nv-card{
    background:var(--panel-bg);
    border:1px solid var(--border-soft);
    border-radius:22px;
    box-shadow:var(--card-shadow);
    overflow:hidden;
  }

  .nv-card-head{
    padding:18px 20px 14px;
    border-bottom:1px solid var(--border-soft);
    background:#fbfcfe;
  }

  .nv-card-title{
    font-size:18px;
    font-weight:900;
    color:var(--text-strong);
    margin:0;
  }

  .nv-card-sub{
    margin-top:6px;
    color:var(--text-muted);
    font-size:13px;
    line-height:1.45;
  }

  .nv-card-body{
    padding:18px 20px 22px;
  }

  .nv-version-list{
    display:grid;
    gap:12px;
  }

  .nv-version-item{
    display:block;
    text-decoration:none;
    color:inherit;
    border:1px solid rgba(15,23,42,0.08);
    border-radius:18px;
    padding:14px 14px 12px;
    background:#fff;
    transition:.15s ease;
  }

  .nv-version-item:hover{
    border-color:rgba(15,23,42,0.18);
    background:#f9fbff;
    text-decoration:none;
  }

  .nv-version-item.active{
    border-color:#93c5fd;
    background:#eff6ff;
    box-shadow:0 0 0 3px rgba(59,130,246,0.08);
  }

  .nv-version-top{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:10px;
  }

  .nv-version-no{
    font-size:16px;
    font-weight:900;
    color:var(--text-strong);
  }

  .nv-pill{
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

  .nv-pill.live{
    background:#ecfdf5;
    color:#166534;
    border-color:#bbf7d0;
  }

  .nv-version-meta{
    margin-top:8px;
    color:var(--text-muted);
    font-size:13px;
    line-height:1.5;
  }

  .nv-version-note{
    margin-top:8px;
    color:var(--text-strong);
    font-size:13px;
    line-height:1.5;
    white-space:pre-wrap;
  }

  .nv-empty{
    text-align:center;
    color:var(--text-muted);
    padding:18px 0;
  }

  .nv-detail-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
  }

  .nv-field{
    display:flex;
    flex-direction:column;
    gap:8px;
  }

  .nv-label{
    font-size:13px;
    font-weight:900;
    color:var(--text-strong);
  }

  .nv-read{
    border:1px solid rgba(15,23,42,0.10);
    border-radius:14px;
    background:#fff;
    padding:12px 14px;
    min-height:48px;
    color:var(--text-strong);
    font-size:14px;
    line-height:1.5;
    word-break:break-word;
  }

  .nv-read.code{
    white-space:pre-wrap;
    font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
  }

  .nv-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:18px;
  }

  .nv-btn{
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

  .nv-btn:hover{
    background:#f8fbff;
    border-color:rgba(15,23,42,0.18);
  }

  .nv-btn.primary{
    color:#fff;
    border-color:transparent;
    background:
      linear-gradient(180deg, rgba(255,255,255,0.018) 0%, rgba(255,255,255,0.00) 100%),
      linear-gradient(180deg, var(--sidebar-bg) 0%, var(--sidebar-bg-2) 100%);
    box-shadow:0 8px 24px rgba(13, 29, 52, 0.18);
  }

  .nv-btn.primary:hover{
    filter:brightness(1.03);
  }

  .nv-input{
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

  .nv-input:focus{
    border-color:rgba(110,174,252,0.70);
    box-shadow:0 0 0 4px rgba(110,174,252,0.12);
  }

  .nv-var-table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
  }

  .nv-var-table th{
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

  .nv-var-table td{
    padding:12px 10px;
    border-bottom:1px solid rgba(15,23,42,0.05);
    vertical-align:top;
    font-size:13px;
    color:var(--text-strong);
  }

  .nv-var-table tr:last-child td{
    border-bottom:none;
  }

  .nv-token{
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

  .nv-mini-pill{
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

  .nv-mini-pill.text{
    background:#eef2ff;
    color:#3730a3;
    border-color:#c7d2fe;
  }

  .nv-mini-pill.html{
    background:#ecfeff;
    color:#155e75;
    border-color:#a5f3fc;
  }

  .nv-mini-pill.req{
    background:#fff7ed;
    color:#c2410c;
    border-color:#fdba74;
  }

  .nv-mini-pill.opt{
    background:#f8fafc;
    color:#475569;
    border-color:#e2e8f0;
  }

  .nv-preview-subject{
    background:#fff;
    border:1px solid rgba(15,23,42,0.08);
    border-radius:14px;
    padding:12px 14px;
    margin-bottom:12px;
  }

  .nv-preview-subject-label{
    font-size:12px;
    font-weight:900;
    color:var(--text-muted);
    text-transform:uppercase;
    letter-spacing:.06em;
    margin-bottom:6px;
  }

  .nv-preview-subject-value{
    font-size:15px;
    font-weight:900;
    color:var(--text-strong);
    word-break:break-word;
  }

  .nv-preview-frame-wrap{
    border:1px solid rgba(15,23,42,0.08);
    border-radius:18px;
    overflow:hidden;
    background:#fff;
  }

  .nv-preview-frame{
    width:100%;
    min-height:540px;
    border:0;
    display:block;
    background:#fff;
  }

  .nv-preview-plain{
    margin-top:14px;
    background:#fff;
    border:1px solid rgba(15,23,42,0.08);
    border-radius:14px;
    padding:14px;
  }

  .nv-preview-plain pre{
    margin:0;
    white-space:pre-wrap;
    word-break:break-word;
    font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
    font-size:12px;
    line-height:1.5;
    color:#111827;
  }

  @media (max-width: 1180px){
    .nv-grid{
      grid-template-columns:1fr;
    }
  }

  @media (max-width: 860px){
    .nv-detail-grid{
      grid-template-columns:1fr;
    }

    .nv-title{
      font-size:26px;
    }

    .nv-hero{
      padding:20px 18px;
    }
  }
</style>

<div class="nv-page">
  <div class="nv-hero">
    <div class="nv-kicker">Admin / Notification Control</div>
    <h1 class="nv-title"><?= nv_h($templateName) ?> — Version History</h1>
    <div class="nv-sub">
      <?= $templateDescription !== '' ? nl2br(nv_h($templateDescription)) : 'View historical versions and restore a prior template state safely.' ?>
    </div>

    <div class="nv-meta-row">
      <div class="nv-chip">Key: <?= nv_h($templateKey) ?></div>
      <div class="nv-chip">Channel: Email</div>
      <div class="nv-chip">Current Live Version: <?= $liveVersionNo > 0 ? ('v' . $liveVersionNo) : '—' ?></div>
    </div>
  </div>

  <?php if ($flashSuccess !== ''): ?>
    <div class="nv-alert success"><?= nl2br(nv_h($flashSuccess)) ?></div>
  <?php endif; ?>

  <?php if ($flashError !== ''): ?>
    <div class="nv-alert error"><?= nl2br(nv_h($flashError)) ?></div>
  <?php endif; ?>

  <div class="nv-grid">
    <div class="nv-card">
      <div class="nv-card-head">
        <h2 class="nv-card-title">Saved Versions</h2>
        <div class="nv-card-sub">
          Restoring a version does not destroy history. A restore creates a new live version snapshot.
        </div>
      </div>
      <div class="nv-card-body">
        <div class="nv-actions" style="margin-top:0; margin-bottom:16px;">
          <a class="nv-btn" href="/admin/notification_edit.php?id=<?= (int)$id ?>">Back to Edit</a>
          <a class="nv-btn" href="/admin/notifications.php">Back to List</a>
        </div>

        <div class="nv-version-list">
          <?php if (!$versions): ?>
            <div class="nv-empty">No versions found.</div>
          <?php else: ?>
            <?php foreach ($versions as $version): ?>
              <?php
                $versionId = (int)($version['id'] ?? 0);
                $versionNo = (int)($version['version_no'] ?? 0);
                $createdAt = trim((string)($version['created_at'] ?? ''));
                $createdAtDisplay = $createdAt !== '' ? date('D, M j, Y g:i A', strtotime($createdAt)) : '—';
                $changedByUserId = isset($version['changed_by_user_id']) && $version['changed_by_user_id'] !== null
                  ? (int)$version['changed_by_user_id']
                  : null;
                $changeNote = trim((string)($version['change_note'] ?? ''));
                $isLive = $versionNo === $liveVersionNo;
                $isActive = $versionId === $selectedVersionId;
              ?>
              <a
                class="nv-version-item<?= $isActive ? ' active' : '' ?>"
                href="/admin/notification_versions.php?id=<?= (int)$id ?>&version_id=<?= $versionId ?>"
              >
                <div class="nv-version-top">
                  <div class="nv-version-no">v<?= $versionNo ?></div>
                  <?php if ($isLive): ?>
                    <span class="nv-pill live">Live</span>
                  <?php endif; ?>
                </div>
                <div class="nv-version-meta">
                  Saved: <?= nv_h($createdAtDisplay) ?><br>
                  By: <?= $changedByUserId !== null ? ('User #' . $changedByUserId) : '—' ?>
                </div>
                <?php if ($changeNote !== ''): ?>
                  <div class="nv-version-note"><?= nv_h($changeNote) ?></div>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div style="display:grid; gap:20px;">
      <div class="nv-card">
        <div class="nv-card-head">
          <h2 class="nv-card-title">Selected Version</h2>
          <div class="nv-card-sub">
            Review the selected saved version and restore it as a new live version if required.
          </div>
        </div>
        <div class="nv-card-body">
          <?php if (!$selectedVersion): ?>
            <div class="nv-empty">No version selected.</div>
          <?php else: ?>
            <div class="nv-detail-grid">
              <div class="nv-field">
                <div class="nv-label">Version Number</div>
                <div class="nv-read">v<?= $selectedVersionNo ?></div>
              </div>

              <div class="nv-field">
                <div class="nv-label">Saved At</div>
                <div class="nv-read"><?= nv_h($selectedCreatedAtDisplay) ?></div>
              </div>

              <div class="nv-field">
                <div class="nv-label">Saved By</div>
                <div class="nv-read"><?= $selectedChangedByUserId !== null ? ('User #' . $selectedChangedByUserId) : '—' ?></div>
              </div>

              <div class="nv-field">
                <div class="nv-label">Current Live Status</div>
                <div class="nv-read"><?= $selectedVersionNo === $liveVersionNo ? 'This version is currently live.' : 'This version is not currently live.' ?></div>
              </div>

              <div class="nv-field" style="grid-column:1 / -1;">
                <div class="nv-label">Change Note</div>
                <div class="nv-read code"><?= $selectedChangeNote !== '' ? nv_h($selectedChangeNote) : '—' ?></div>
              </div>

              <div class="nv-field" style="grid-column:1 / -1;">
                <div class="nv-label">Saved Subject Template</div>
                <div class="nv-read code"><?= nv_h((string)($selectedVersion['subject_template'] ?? '')) ?></div>
              </div>

              <div class="nv-field" style="grid-column:1 / -1;">
                <div class="nv-label">Saved HTML Template</div>
                <div class="nv-read code"><?= nv_h((string)($selectedVersion['html_template'] ?? '')) ?></div>
              </div>

              <div class="nv-field" style="grid-column:1 / -1;">
                <div class="nv-label">Saved Plain-Text Template</div>
                <div class="nv-read code"><?= nv_h((string)($selectedVersion['text_template'] ?? '')) ?></div>
              </div>
            </div>

            <form method="post" style="margin-top:18px;">
              <input type="hidden" name="id" value="<?= (int)$id ?>">
              <input type="hidden" name="version_id" value="<?= (int)$selectedVersionId ?>">

              <div class="nv-field">
                <div class="nv-label">Restore Change Note</div>
                <input
                  class="nv-input"
                  type="text"
                  name="change_note"
                  value=""
                  placeholder="Optional note for restored version snapshot"
                >
              </div>

              <div class="nv-actions">
                <button
                  class="nv-btn primary"
                  type="submit"
                  name="action"
                  value="restore_version"
                  onclick="return confirm('Restore this version as a new live version snapshot?');"
                >
                  Restore as New Live Version
                </button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <div class="nv-card">
        <div class="nv-card-head">
          <h2 class="nv-card-title">Variable Metadata</h2>
          <div class="nv-card-sub">
            Variable catalog stored with the selected saved version.
          </div>
        </div>
        <div class="nv-card-body" style="padding-top:0;">
          <table class="nv-var-table">
            <thead>
              <tr>
                <th>Variable</th>
                <th>Type</th>
                <th>Requirement</th>
                <th>Sample</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($selectedVariables as $meta): ?>
                <?php
                  $safeMode = (string)($meta['safe_mode'] ?? 'escaped');
                  $isHtmlVar = $safeMode === 'approved_html';
                ?>
                <tr>
                  <td>
                    <div class="nv-token">{{<?= nv_h((string)$meta['name']) ?>}}</div>
                    <?php if (!empty($meta['description'])): ?>
                      <div style="margin-top:6px; color:var(--text-muted); font-size:12px; line-height:1.45;">
                        <?= nv_h((string)$meta['description']) ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="nv-mini-pill <?= $isHtmlVar ? 'html' : 'text' ?>">
                      <?= $isHtmlVar ? 'Safe HTML' : 'Escaped Text' ?>
                    </span>
                  </td>
                  <td>
                    <span class="nv-mini-pill <?= !empty($meta['required']) ? 'req' : 'opt' ?>">
                      <?= !empty($meta['required']) ? 'Required' : 'Optional' ?>
                    </span>
                  </td>
                  <td>
                    <div style="max-width:260px; white-space:pre-wrap; word-break:break-word;"><?= nv_h((string)($meta['sample_value'] ?? '')) ?></div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$selectedVariables): ?>
                <tr>
                  <td colspan="4">No variable metadata found for this version.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="nv-card">
        <div class="nv-card-head">
          <h2 class="nv-card-title">Saved Version Preview</h2>
          <div class="nv-card-sub">
            Preview of the selected saved version rendered with the seeded dummy dataset.
          </div>
        </div>
        <div class="nv-card-body">
          <?php if ($previewError !== ''): ?>
            <div class="nv-alert error" style="margin-bottom:0;"><?= nv_h($previewError) ?></div>
          <?php elseif ($selectedVersion): ?>
            <div class="nv-preview-subject">
              <div class="nv-preview-subject-label">Rendered Subject</div>
              <div class="nv-preview-subject-value"><?= nv_h($previewSubject) ?></div>
            </div>

            <div class="nv-preview-frame-wrap">
              <iframe
                class="nv-preview-frame"
                sandbox=""
                srcdoc="<?= nv_h($previewSrcdoc) ?>"
              ></iframe>
            </div>

            <div class="nv-preview-plain">
              <div class="nv-preview-subject-label">Rendered Plain Text</div>
              <pre><?= nv_h($previewText) ?></pre>
            </div>
          <?php else: ?>
            <div class="nv-empty">No preview available.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php cw_footer(); ?>