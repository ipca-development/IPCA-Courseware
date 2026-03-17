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

$service = new NotificationService($pdo);
$rows = $service->listTemplates();

cw_header('Notification Templates');
?>
<style>
  body { background:#f6f8fb; }

  .nt-page{
    max-width:1280px;
    margin:0 auto;
  }

  .nt-hero{
    background:linear-gradient(135deg,#1e3c72,#2a5298);
    color:#fff;
    border-radius:22px;
    padding:24px 26px;
    box-shadow:0 16px 40px rgba(30,60,114,0.18);
    margin-bottom:20px;
  }

  .nt-hero-kicker{
    font-size:12px;
    font-weight:900;
    letter-spacing:.12em;
    text-transform:uppercase;
    opacity:.86;
    margin-bottom:6px;
  }

  .nt-hero-title{
    font-size:30px;
    line-height:1.1;
    font-weight:900;
    margin:0;
  }

  .nt-hero-sub{
    margin-top:10px;
    max-width:920px;
    color:rgba(255,255,255,0.92);
    line-height:1.55;
    font-size:15px;
  }

  .nt-toolbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:14px;
  }

  .nt-count{
    color:#334155;
    font-weight:800;
  }

  .nt-chip-row{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
  }

  .nt-chip{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 12px;
    border-radius:999px;
    background:#fff;
    border:1px solid #dbe3f0;
    color:#1e3c72;
    font-size:13px;
    font-weight:800;
  }

  .nt-card{
    background:#fff;
    border:1px solid #e4e9f2;
    border-radius:22px;
    box-shadow:0 12px 34px rgba(15,23,42,0.06);
    overflow:hidden;
  }

  .nt-table-wrap{
    overflow:auto;
  }

  .nt-table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    min-width:1060px;
  }

  .nt-table thead th{
    background:#f8fafc;
    color:#1e3c72;
    font-size:12px;
    font-weight:900;
    letter-spacing:.06em;
    text-transform:uppercase;
    padding:14px 16px;
    border-bottom:1px solid #e7edf6;
    text-align:left;
    white-space:nowrap;
  }

  .nt-table tbody td{
    padding:16px;
    border-bottom:1px solid #eef2f7;
    vertical-align:top;
    color:#1f2937;
    font-size:14px;
  }

  .nt-table tbody tr:last-child td{
    border-bottom:none;
  }

  .nt-table tbody tr:hover{
    background:#fbfdff;
  }

  .nt-name{
    font-weight:900;
    color:#0f172a;
    font-size:15px;
    margin-bottom:4px;
  }

  .nt-key{
    display:inline-block;
    font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
    font-size:12px;
    line-height:1.3;
    color:#1d4ed8;
    background:#eff6ff;
    border:1px solid #bfdbfe;
    border-radius:999px;
    padding:4px 9px;
  }

  .nt-desc{
    margin-top:8px;
    color:#475569;
    line-height:1.45;
    max-width:420px;
  }

  .nt-pill{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:6px 10px;
    border-radius:999px;
    border:1px solid transparent;
    font-size:12px;
    font-weight:900;
    white-space:nowrap;
  }

  .nt-pill .dot{
    width:8px;
    height:8px;
    border-radius:999px;
    flex:0 0 8px;
  }

  .nt-pill.enabled{
    background:#ecfdf5;
    color:#166534;
    border-color:#bbf7d0;
  }

  .nt-pill.enabled .dot{
    background:#16a34a;
  }

  .nt-pill.disabled{
    background:#fff1f2;
    color:#be123c;
    border-color:#fecdd3;
  }

  .nt-pill.disabled .dot{
    background:#e11d48;
  }

  .nt-pill.mode{
    background:#eef2ff;
    color:#3730a3;
    border-color:#c7d2fe;
  }

  .nt-pill.mode .dot{
    background:#4f46e5;
  }

  .nt-meta{
    color:#475569;
    font-weight:700;
    line-height:1.5;
  }

  .nt-muted{
    color:#64748b;
    font-size:12px;
  }

  .nt-actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
  }

  .nt-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    min-height:40px;
    padding:0 14px;
    border-radius:12px;
    border:1px solid #cdd8e8;
    background:#fff;
    color:#1e3c72;
    text-decoration:none;
    font-weight:900;
    font-size:14px;
    transition:.15s ease;
    box-sizing:border-box;
  }

  .nt-btn:hover{
    background:#f8fbff;
    border-color:#9fb6d8;
    text-decoration:none;
  }

  .nt-btn.primary{
    background:linear-gradient(135deg,#1e3c72,#2a5298);
    color:#fff;
    border-color:transparent;
    box-shadow:0 8px 24px rgba(30,60,114,0.18);
  }

  .nt-btn.primary:hover{
    filter:brightness(1.03);
    text-decoration:none;
  }

  .nt-empty{
    padding:34px 24px;
    text-align:center;
    color:#475569;
  }

  @media (max-width: 860px){
    .nt-hero-title{
      font-size:25px;
    }
    .nt-hero{
      padding:20px 18px;
    }
  }
</style>

<div class="nt-page">
  <div class="nt-hero">
    <div class="nt-hero-kicker">Admin / Notification Control</div>
    <h1 class="nt-hero-title">Notification Templates</h1>
    <div class="nt-hero-sub">
      Manage the live email templates used by the progression system. These are the canonical saved templates used for real progression emails.
      Preview and test-send are handled safely from the edit page using draft content and dummy data only.
    </div>
  </div>

  <div class="nt-toolbar">
    <div class="nt-count">
      <?= (int)count($rows) ?> notification template<?= count($rows) === 1 ? '' : 's' ?>
    </div>

    <div class="nt-chip-row">
      <div class="nt-chip">Channel: Email</div>
      <div class="nt-chip">Real sends only use saved live versions</div>
      <div class="nt-chip">Preview/Test use draft content only</div>
    </div>
  </div>

  <div class="nt-card">
    <div class="nt-table-wrap">
      <table class="nt-table">
        <thead>
          <tr>
            <th style="min-width:350px;">Notification</th>
            <th>Status</th>
            <th>Delivery</th>
            <th>Duplicate Strategy</th>
            <th>Live Version</th>
            <th>Updated</th>
            <th style="min-width:190px;">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="7">
              <div class="nt-empty">No notification templates found.</div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $row): ?>
            <?php
              $templateId = (int)($row['id'] ?? 0);
              $isEnabled = (int)($row['is_enabled'] ?? 0) === 1;
              $name = trim((string)($row['name'] ?? ''));
              $notificationKey = trim((string)($row['notification_key'] ?? ''));
              $description = trim((string)($row['description'] ?? ''));
              $deliveryMode = trim((string)($row['delivery_mode'] ?? ''));
              $duplicateStrategy = trim((string)($row['duplicate_strategy'] ?? ''));
              $liveVersionNo = (int)($row['live_version_no'] ?? 0);
              $updatedAt = trim((string)($row['updated_at'] ?? ''));
              $updatedByUserId = isset($row['updated_by_user_id']) && $row['updated_by_user_id'] !== null
                ? (int)$row['updated_by_user_id']
                : null;

              $updatedAtDisplay = $updatedAt !== ''
                ? date('D, M j, Y g:i A', strtotime($updatedAt))
                : '—';
            ?>
            <tr>
              <td>
                <div class="nt-name"><?= htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
                <div class="nt-key"><?= htmlspecialchars($notificationKey, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
                <?php if ($description !== ''): ?>
                  <div class="nt-desc"><?= nl2br(htmlspecialchars($description, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?></div>
                <?php endif; ?>
              </td>

              <td>
                <span class="nt-pill <?= $isEnabled ? 'enabled' : 'disabled' ?>">
                  <span class="dot"></span>
                  <?= $isEnabled ? 'Enabled' : 'Disabled' ?>
                </span>
              </td>

              <td>
                <span class="nt-pill mode">
                  <span class="dot"></span>
                  <?= htmlspecialchars($deliveryMode !== '' ? $deliveryMode : 'immediate', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
                </span>
              </td>

              <td>
                <div class="nt-meta">
                  <?= htmlspecialchars($duplicateStrategy !== '' ? $duplicateStrategy : '—', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
                </div>
              </td>

              <td>
                <div class="nt-meta">
                  <?= $liveVersionNo > 0 ? ('v' . $liveVersionNo) : '—' ?>
                </div>
              </td>

              <td>
                <div class="nt-meta"><?= htmlspecialchars($updatedAtDisplay, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
                <div class="nt-muted">
                  Updated by:
                  <?= $updatedByUserId !== null ? ('User #' . $updatedByUserId) : '—' ?>
                </div>
              </td>

              <td>
                <div class="nt-actions">
                  <a class="nt-btn primary" href="/admin/notification_edit.php?id=<?= $templateId ?>">Edit</a>
                  <a class="nt-btn" href="/admin/notification_versions.php?id=<?= $templateId ?>">Versions</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php cw_footer(); ?>