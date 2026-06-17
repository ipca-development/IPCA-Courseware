<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../src/bootstrap.php';
require_once __DIR__ . '/../../../../src/layout.php';
require_once __DIR__ . '/../../../../src/flight_training/AdminLogbookService.php';

cw_require_admin();

$service = new AdminLogbookService($pdo);
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $service->saveRequirementCategory($_POST);
        $notice = 'Requirement category saved.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$schemaReady = $service->schemaReady();
$missingTables = $service->missingTables();
$categories = array();
if ($schemaReady) {
    try {
        $categories = $service->listRequirementCategories();
    } catch (Throwable $e) {
        $error = $error !== '' ? $error : $e->getMessage();
    }
}

cw_header('Flight Training · Requirement Categories');
?>

<style>
.reqm{display:flex;flex-direction:column;gap:20px}
.reqm-hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;padding:22px;border-radius:24px;background:linear-gradient(135deg,#102845,#1d4f89);color:#fff;box-shadow:0 18px 40px rgba(15,40,69,.18)}
.reqm-kicker{margin:0 0 7px;font-size:11px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#bfdbfe}.reqm-title{margin:0;font-size:30px;line-height:1.1}.reqm-subtitle{margin:9px 0 0;max-width:760px;color:#dbeafe;font-size:14px;line-height:1.5}
.reqm-card{border:1px solid rgba(15,23,42,.08);border-radius:22px;background:#fff;box-shadow:0 10px 26px rgba(15,23,42,.06);overflow:hidden}.reqm-card-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:18px 20px;border-bottom:1px solid rgba(15,23,42,.07)}.reqm-card-title{margin:0;font-size:17px;color:#102845}.reqm-card-text{margin:4px 0 0;color:#64748b;font-size:13px}
.reqm-form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;padding:18px 20px}.reqm-field{display:flex;flex-direction:column;gap:6px}.reqm-field--wide{grid-column:1/-1}.reqm-label{font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#64748b}.reqm-input,.reqm-textarea,.reqm-select{width:100%;box-sizing:border-box;border:1px solid rgba(15,23,42,.13);border-radius:14px;padding:10px 12px;font:inherit;font-size:13px;color:#102845;background:#fff}.reqm-textarea{min-height:72px;resize:vertical}.reqm-actions{display:flex;gap:8px;align-items:center;justify-content:flex-end;padding:0 20px 18px}
.reqm-btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:999px;padding:9px 14px;font-size:13px;font-weight:900;text-decoration:none;cursor:pointer;white-space:nowrap}.reqm-btn--primary{background:#1d4f89;color:#fff}.reqm-btn--secondary{background:#eef2ff;color:#1e3a8a}
.reqm-notice,.reqm-error,.reqm-warning{padding:13px 16px;border-radius:16px;font-size:13px;font-weight:800}.reqm-notice{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}.reqm-error{background:#fff1f2;color:#be123c;border:1px solid #fecdd3}.reqm-warning{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
.reqm-table-wrap{overflow:auto}.reqm-table{width:100%;border-collapse:separate;border-spacing:0;min-width:1100px}.reqm-table th{padding:12px 14px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#64748b;background:#f8fafc;border-bottom:1px solid rgba(15,23,42,.07)}.reqm-table td{padding:12px 14px;border-bottom:1px solid rgba(15,23,42,.06);vertical-align:top;color:#102845;font-size:13px}.reqm-key{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px;color:#334155;background:#f1f5f9;border-radius:999px;padding:4px 8px}.reqm-muted{color:#64748b;font-size:12px}
@media(max-width:900px){.reqm-form-grid{grid-template-columns:1fr}.reqm-actions{justify-content:flex-start;flex-wrap:wrap}}
</style>

<div class="reqm">
  <header class="reqm-hero">
    <div>
      <p class="reqm-kicker">Admin · Flight Training</p>
      <h1 class="reqm-title">Requirement Categories</h1>
      <p class="reqm-subtitle">
        Configure FAA Part 61, FAA Part 141, EASA, and school requirements independently from form templates.
      </p>
    </div>
    <a class="reqm-btn reqm-btn--secondary" href="/admin/api/admin_logbook_api.php?action=requirement_categories">API: categories</a>
  </header>

  <?php if ($notice !== ''): ?><div class="reqm-notice"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="reqm-error"><?= h($error) ?></div><?php endif; ?>
  <?php if (!$schemaReady): ?>
    <div class="reqm-warning">
      Apply <code>scripts/sql/2026_06_17_admin_logbook_requirements_foundation.sql</code>.
      Missing tables: <?= h(implode(', ', $missingTables)) ?>.
    </div>
  <?php endif; ?>

  <section class="reqm-card">
    <div class="reqm-card-head">
      <div>
        <h2 class="reqm-card-title">Create / Update Category</h2>
        <p class="reqm-card-text">Automatic rules use JSON. For MVP, prefer explicit assignment for complex requirements.</p>
      </div>
    </div>
    <form method="post">
      <div class="reqm-form-grid">
        <label class="reqm-field"><span class="reqm-label">Authority</span><select class="reqm-select" name="authority"><option>FAA_PART_61</option><option>FAA_PART_141</option><option>EASA</option></select></label>
        <label class="reqm-field"><span class="reqm-label">Certificate</span><select class="reqm-select" name="certificate"><option>PPL</option><option>IR</option><option>CPL</option><option>OTHER</option></select></label>
        <label class="reqm-field"><span class="reqm-label">Requirement Key</span><input class="reqm-input" name="requirement_key" required placeholder="basic_instrument_flying"></label>
        <label class="reqm-field"><span class="reqm-label">Label</span><input class="reqm-input" name="label" required placeholder="Basic Instrument Flying"></label>
        <label class="reqm-field"><span class="reqm-label">Minimum Time</span><input class="reqm-input" name="minimum_time" type="number" step="0.01"></label>
        <label class="reqm-field"><span class="reqm-label">Minimum Distance NM</span><input class="reqm-input" name="minimum_distance_nm" type="number" step="0.1"></label>
        <label class="reqm-field"><span class="reqm-label">Minimum Count</span><input class="reqm-input" name="minimum_count" type="number" step="1"></label>
        <label class="reqm-field"><span class="reqm-label">Status</span><select class="reqm-select" name="status"><option>active</option><option>archived</option></select></label>
        <label class="reqm-field reqm-field--wide"><span class="reqm-label">Description</span><textarea class="reqm-textarea" name="description"></textarea></label>
        <label class="reqm-field reqm-field--wide"><span class="reqm-label">Automatic Rules JSON</span><textarea class="reqm-textarea" name="automatic_rules_json">{"type":"manual_assignment"}</textarea></label>
        <label class="reqm-field reqm-field--wide"><span class="reqm-label">Manual Rules JSON</span><textarea class="reqm-textarea" name="manual_rules_json">{}</textarea></label>
      </div>
      <div class="reqm-actions"><button class="reqm-btn reqm-btn--primary" type="submit"<?= $schemaReady ? '' : ' disabled' ?>>Save Category</button></div>
    </form>
  </section>

  <section class="reqm-card">
    <div class="reqm-card-head">
      <div>
        <h2 class="reqm-card-title">Configured Categories</h2>
        <p class="reqm-card-text">Seeded categories are created by the foundation migration and can be adjusted here.</p>
      </div>
    </div>
    <div class="reqm-table-wrap">
      <table class="reqm-table">
        <thead><tr><th>Authority</th><th>Certificate</th><th>Key</th><th>Label</th><th>Minimums</th><th>Status</th><th>Rules</th></tr></thead>
        <tbody>
          <?php foreach ($categories as $category): ?>
            <tr>
              <td><?= h((string)$category['authority']) ?></td>
              <td><?= h((string)$category['certificate']) ?></td>
              <td><span class="reqm-key"><?= h((string)$category['requirement_key']) ?></span></td>
              <td><strong><?= h((string)$category['label']) ?></strong><div class="reqm-muted"><?= h((string)($category['description'] ?? '')) ?></div></td>
              <td>
                <div>Time: <?= h((string)($category['minimum_time'] ?? '—')) ?></div>
                <div>Distance: <?= h((string)($category['minimum_distance_nm'] ?? '—')) ?></div>
                <div>Count: <?= h((string)($category['minimum_count'] ?? '—')) ?></div>
              </td>
              <td><?= h((string)$category['status']) ?></td>
              <td><code><?= h((string)($category['automatic_rules_json'] ?? '{}')) ?></code></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($categories === array()): ?>
            <tr><td colspan="7" class="reqm-muted">No requirement categories available.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<?php cw_footer(); ?>
