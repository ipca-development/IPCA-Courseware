<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../src/bootstrap.php';
require_once __DIR__ . '/../../../../src/layout.php';
require_once __DIR__ . '/../../../../src/flight_training/FormTemplateService.php';

cw_require_admin();

$user = cw_current_user($pdo);
$actorUserId = (int)($user['id'] ?? 0);
$service = new FormTemplateService($pdo);

function ftfm_date(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('M j, Y H:i', $ts) : $value;
}

function ftfm_badge_class(string $status): string
{
    return match (strtolower(trim($status))) {
        'active' => 'ftfm-badge ftfm-badge--active',
        'draft' => 'ftfm-badge ftfm-badge--draft',
        'archived' => 'ftfm-badge ftfm-badge--archived',
        'superseded' => 'ftfm-badge ftfm-badge--archived',
        default => 'ftfm-badge',
    };
}

$notice = '';
$error = '';

if (isset($_GET['created'])) {
    $notice = 'Template created.';
} elseif (isset($_GET['imported'])) {
    $notice = 'PDF imported as a draft form template.';
} elseif (isset($_GET['archived'])) {
    $notice = 'Template archived.';
} elseif (isset($_GET['activated'])) {
    $notice = 'Template version activated.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!$service->schemaReady()) {
            throw new RuntimeException('Flight Training Forms tables are not installed yet.');
        }

        if ($action === 'create') {
            $service->createTemplate(array(
                'title' => (string)($_POST['title'] ?? ''),
                'template_key' => (string)($_POST['template_key'] ?? ''),
                'category' => (string)($_POST['category'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'version_label' => (string)($_POST['version_label'] ?? '1.0'),
            ), $actorUserId);
            redirect('/admin/flight_training/forms/index.php?created=1');
        }

        if ($action === 'import_pdf') {
            $templateId = $service->importPdfTemplate(array(
                'title' => (string)($_POST['title'] ?? ''),
                'template_key' => (string)($_POST['template_key'] ?? ''),
                'category' => (string)($_POST['category'] ?? 'Checkride'),
                'description' => (string)($_POST['description'] ?? ''),
                'version_label' => (string)($_POST['version_label'] ?? '1.0'),
                'import_profile' => (string)($_POST['import_profile'] ?? 'private_sel_practical_test'),
            ), is_array($_FILES['source_pdf'] ?? null) ? $_FILES['source_pdf'] : array(), $actorUserId);
            redirect('/admin/flight_training/forms/editor.php?template_id=' . $templateId . '&imported=1');
        }

        if ($action === 'archive') {
            $service->archiveTemplate((int)($_POST['template_id'] ?? 0), $actorUserId);
            redirect('/admin/flight_training/forms/index.php?archived=1');
        }

        if ($action === 'activate_version') {
            $service->activateTemplateVersion((int)($_POST['template_version_id'] ?? 0), $actorUserId);
            redirect('/admin/flight_training/forms/index.php?activated=1');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$schemaReady = $service->schemaReady();
$missingTables = $service->missingTables();
$templates = array();
if ($schemaReady) {
    try {
        $templates = $service->listTemplates();
    } catch (Throwable $e) {
        $error = $error !== '' ? $error : $e->getMessage();
    }
}

cw_header('Flight Training · Form Manager');
?>

<style>
.ftfm-page{display:flex;flex-direction:column;gap:20px}
.ftfm-hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;padding:22px;border-radius:24px;background:linear-gradient(135deg,#102845,#1d4f89);color:#fff;box-shadow:0 18px 40px rgba(15,40,69,.18)}
.ftfm-kicker{margin:0 0 7px;font-size:11px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#bfdbfe}
.ftfm-title{margin:0;font-size:30px;line-height:1.1}
.ftfm-subtitle{margin:9px 0 0;max-width:720px;color:#dbeafe;font-size:14px;line-height:1.5}
.ftfm-card{border:1px solid rgba(15,23,42,.08);border-radius:22px;background:#fff;box-shadow:0 10px 26px rgba(15,23,42,.06);overflow:hidden}
.ftfm-card-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:18px 20px;border-bottom:1px solid rgba(15,23,42,.07)}
.ftfm-card-title{margin:0;font-size:17px;color:#102845}
.ftfm-card-text{margin:4px 0 0;color:#64748b;font-size:13px}
.ftfm-form-grid{display:grid;grid-template-columns:1.4fr .9fr .9fr .55fr;gap:12px;padding:18px 20px}
.ftfm-form-grid--import{grid-template-columns:1.3fr .8fr .8fr .55fr}
.ftfm-field{display:flex;flex-direction:column;gap:6px}
.ftfm-field--wide{grid-column:1 / -1}
.ftfm-label{font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#64748b}
.ftfm-input,.ftfm-textarea,.ftfm-select{width:100%;box-sizing:border-box;border:1px solid rgba(15,23,42,.13);border-radius:14px;padding:11px 12px;font:inherit;font-size:14px;color:#102845;background:#fff}
.ftfm-input[type="file"]{padding:9px 12px}
.ftfm-textarea{min-height:72px;resize:vertical}
.ftfm-actions{display:flex;gap:8px;align-items:center;justify-content:flex-end;padding:0 20px 18px}
.ftfm-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:0;border-radius:999px;padding:9px 14px;font-size:13px;font-weight:900;text-decoration:none;cursor:pointer;white-space:nowrap}
.ftfm-btn--primary{background:#1d4f89;color:#fff}
.ftfm-btn--secondary{background:#eef2ff;color:#1e3a8a}
.ftfm-btn--ghost{background:#f8fafc;color:#334155;border:1px solid rgba(15,23,42,.1)}
.ftfm-btn--danger{background:#fff1f2;color:#be123c;border:1px solid #fecdd3}
.ftfm-btn:disabled,.ftfm-btn[aria-disabled="true"]{opacity:.52;cursor:not-allowed}
.ftfm-notice,.ftfm-error,.ftfm-warning{padding:13px 16px;border-radius:16px;font-size:13px;font-weight:800}
.ftfm-notice{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}
.ftfm-error{background:#fff1f2;color:#be123c;border:1px solid #fecdd3}
.ftfm-warning{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
.ftfm-table-wrap{overflow:auto}
.ftfm-table{width:100%;border-collapse:separate;border-spacing:0;min-width:980px}
.ftfm-table th{padding:12px 14px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#64748b;background:#f8fafc;border-bottom:1px solid rgba(15,23,42,.07)}
.ftfm-table td{padding:14px;border-bottom:1px solid rgba(15,23,42,.06);vertical-align:middle;color:#102845;font-size:14px}
.ftfm-table tr:last-child td{border-bottom:0}
.ftfm-key{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px;color:#334155;background:#f1f5f9;border-radius:999px;padding:4px 8px}
.ftfm-muted{color:#64748b;font-size:12px}
.ftfm-badge{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;background:#f1f5f9;color:#334155}
.ftfm-badge--active{background:#dcfce7;color:#166534}
.ftfm-badge--draft{background:#fef3c7;color:#92400e}
.ftfm-badge--archived{background:#e2e8f0;color:#475569}
.ftfm-row-actions{display:flex;flex-wrap:wrap;gap:7px}
.ftfm-empty{padding:34px 20px;text-align:center;color:#64748b}
@media (max-width:900px){.ftfm-hero{flex-direction:column}.ftfm-form-grid{grid-template-columns:1fr}.ftfm-actions{justify-content:flex-start;flex-wrap:wrap}}
</style>

<div class="ftfm-page">
  <header class="ftfm-hero">
    <div>
      <p class="ftfm-kicker">Admin · Flight Training · Forms</p>
      <h1 class="ftfm-title">Form Manager</h1>
      <p class="ftfm-subtitle">
        Manage reusable structured form templates for checkride packets and future flight-training documents.
        Phase 1 creates the template registry and lifecycle foundation only.
      </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
      <a class="ftfm-btn ftfm-btn--secondary" href="/admin/flight_training/forms/send.php">Send Packet</a>
      <a class="ftfm-btn ftfm-btn--secondary" href="/admin/api/form_template_manager_api.php?action=list">API: list</a>
    </div>
  </header>

  <?php if ($notice !== ''): ?>
    <div class="ftfm-notice"><?= h($notice) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="ftfm-error"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if (!$schemaReady): ?>
    <div class="ftfm-warning">
      Apply <code>scripts/sql/2026_06_16_flight_training_forms_foundation.sql</code> before using the Form Manager.
      Missing tables: <?= h(implode(', ', $missingTables)) ?>.
    </div>
  <?php endif; ?>

  <section class="ftfm-card">
    <div class="ftfm-card-head">
      <div>
        <h2 class="ftfm-card-title">Create Template</h2>
        <p class="ftfm-card-text">Creates a draft template with an initial draft version.</p>
      </div>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="create">
      <div class="ftfm-form-grid">
        <label class="ftfm-field">
          <span class="ftfm-label">Title</span>
          <input class="ftfm-input" name="title" required placeholder="Private Pilot SEL Practical Test Checklist">
        </label>
        <label class="ftfm-field">
          <span class="ftfm-label">Template Key</span>
          <input class="ftfm-input" name="template_key" placeholder="PPL_SEL_CHECKRIDE">
        </label>
        <label class="ftfm-field">
          <span class="ftfm-label">Category</span>
          <input class="ftfm-input" name="category" placeholder="Checkride">
        </label>
        <label class="ftfm-field">
          <span class="ftfm-label">Version</span>
          <input class="ftfm-input" name="version_label" value="1.0">
        </label>
        <label class="ftfm-field ftfm-field--wide">
          <span class="ftfm-label">Description</span>
          <textarea class="ftfm-textarea" name="description" placeholder="Internal structured form template for checkride preparation."></textarea>
        </label>
      </div>
      <div class="ftfm-actions">
        <button class="ftfm-btn ftfm-btn--primary" type="submit"<?= $schemaReady ? '' : ' disabled' ?>>Create Template</button>
      </div>
    </form>
  </section>

  <section class="ftfm-card">
    <div class="ftfm-card-head">
      <div>
        <h2 class="ftfm-card-title">Import PDF Form</h2>
        <p class="ftfm-card-text">Imports a source PDF and seeds a structured form template with auto-fill field bindings.</p>
      </div>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="import_pdf">
      <input type="hidden" name="import_profile" value="private_sel_practical_test">
      <div class="ftfm-form-grid ftfm-form-grid--import">
        <label class="ftfm-field ftfm-field--wide">
          <span class="ftfm-label">PDF Source</span>
          <input class="ftfm-input" type="file" name="source_pdf" accept="application/pdf,.pdf" required>
        </label>
        <label class="ftfm-field">
          <span class="ftfm-label">Title</span>
          <input class="ftfm-input" name="title" value="Practical Test Guide - Private Pilot SEL" placeholder="Practical Test Guide - Private Pilot SEL">
        </label>
        <label class="ftfm-field">
          <span class="ftfm-label">Template Key</span>
          <input class="ftfm-input" name="template_key" value="PRACTICAL_TEST_GUIDE_PRIVATE_SEL" placeholder="PRACTICAL_TEST_GUIDE_PRIVATE_SEL">
        </label>
        <label class="ftfm-field">
          <span class="ftfm-label">Category</span>
          <input class="ftfm-input" name="category" value="Checkride" placeholder="Checkride">
        </label>
        <label class="ftfm-field">
          <span class="ftfm-label">Version</span>
          <input class="ftfm-input" name="version_label" value="1.0">
        </label>
        <label class="ftfm-field ftfm-field--wide">
          <span class="ftfm-label">Description</span>
          <textarea class="ftfm-textarea" name="description">Imported Practical Test Guide for Private Pilot Single-Engine Land checkride preparation and auto-fill.</textarea>
        </label>
      </div>
      <div class="ftfm-actions">
        <button class="ftfm-btn ftfm-btn--primary" type="submit"<?= $schemaReady ? '' : ' disabled' ?>>Import PDF</button>
      </div>
    </form>
  </section>

  <section class="ftfm-card">
    <div class="ftfm-card-head">
      <div>
        <h2 class="ftfm-card-title">Templates</h2>
        <p class="ftfm-card-text">Draft, active, and archived Flight Training form templates.</p>
      </div>
      <span class="ftfm-badge"><?= (int)count($templates) ?> total</span>
    </div>

    <?php if ($templates === array()): ?>
      <div class="ftfm-empty">
        <?= $schemaReady ? 'No form templates have been created yet.' : 'Template list unavailable until the migration is applied.' ?>
      </div>
    <?php else: ?>
      <div class="ftfm-table-wrap">
        <table class="ftfm-table">
          <thead>
            <tr>
              <th>Template</th>
              <th>Template Key</th>
              <th>Category</th>
              <th>Current Version</th>
              <th>Status</th>
              <th>Created</th>
              <th>Updated</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($templates as $template): ?>
              <?php
                $templateId = (int)($template['id'] ?? 0);
                $versionId = (int)($template['current_version_id'] ?? 0);
                $status = (string)($template['status'] ?? '');
                $versionStatus = (string)($template['current_version_status'] ?? '');
              ?>
              <tr>
                <td>
                  <strong><?= h((string)($template['title'] ?? '')) ?></strong>
                  <?php if (trim((string)($template['description'] ?? '')) !== ''): ?>
                    <div class="ftfm-muted"><?= h((string)$template['description']) ?></div>
                  <?php endif; ?>
                </td>
                <td><span class="ftfm-key"><?= h((string)($template['template_key'] ?? '')) ?></span></td>
                <td><?= h((string)($template['category'] ?? '—')) ?></td>
                <td>
                  <?php if ($versionId > 0): ?>
                    <strong>v<?= h((string)($template['current_version_label'] ?? '')) ?></strong>
                    <div><span class="<?= h(ftfm_badge_class($versionStatus)) ?>"><?= h($versionStatus ?: 'draft') ?></span></div>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td><span class="<?= h(ftfm_badge_class($status)) ?>"><?= h($status ?: 'draft') ?></span></td>
                <td><?= h(ftfm_date($template['created_at'] ?? null)) ?></td>
                <td><?= h(ftfm_date($template['updated_at'] ?? null)) ?></td>
                <td>
                  <div class="ftfm-row-actions">
                    <a class="ftfm-btn ftfm-btn--ghost" href="/admin/flight_training/forms/editor.php?template_id=<?= $templateId ?>">Edit</a>
                    <?php if ($versionId > 0 && $versionStatus !== 'active' && $status !== 'archived'): ?>
                      <form method="post">
                        <input type="hidden" name="action" value="activate_version">
                        <input type="hidden" name="template_version_id" value="<?= $versionId ?>">
                        <button class="ftfm-btn ftfm-btn--secondary" type="submit">Activate</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($status !== 'archived'): ?>
                      <form method="post" onsubmit="return confirm('Archive this template?');">
                        <input type="hidden" name="action" value="archive">
                        <input type="hidden" name="template_id" value="<?= $templateId ?>">
                        <button class="ftfm-btn ftfm-btn--danger" type="submit">Archive</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php cw_footer(); ?>
