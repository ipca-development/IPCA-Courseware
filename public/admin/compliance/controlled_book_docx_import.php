<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingFoundationService.php';

$user = compliance_require_access($pdo);
$versionId = isset($_GET['version_id']) ? (int)$_GET['version_id'] : 0;

$foundation = new ControlledPublishingFoundationService($pdo);

if ($versionId <= 0) {
    cw_header('Compliance · DOCX Import');
    compliance_page_open(array(
        'overline' => 'Compliance · Controlled publishing',
        'title' => 'Manual DOCX import',
        'back' => array('href' => '/admin/compliance/controlled_books.php', 'label' => 'All books'),
    ));
    echo '<section class="cmp-card"><p style="margin:0;">Provide ?version_id=…</p></section>';
    compliance_page_close();
    cw_footer();
    return;
}

$version = $foundation->getVersion($versionId);
if ($version === null) {
    cw_header('Compliance · DOCX Import');
    compliance_page_open(array(
        'overline' => 'Compliance · Controlled publishing',
        'title' => 'Version not found',
        'back' => array('href' => '/admin/compliance/controlled_books.php', 'label' => 'All books'),
    ));
    echo '<section class="cmp-card"><p style="margin:0;">Unknown version id.</p></section>';
    compliance_page_close();
    cw_footer();
    return;
}

$isReleased = (string)($version['lifecycle_status'] ?? '') === 'released';
$bookLabel = (string)$version['book_key'] . ' ' . (string)$version['version_label'];

cw_header('Compliance · DOCX Import · ' . $bookLabel);

compliance_page_open(array(
    'overline' => 'Compliance · Controlled publishing',
    'title' => 'Import manual from Word (DOCX)',
    'description' => 'Upload one DOCX per manual Part (Part 0–4). Content is written as normal editor blocks with your book styles, tables, lists, and images.',
    'back' => array(
        'href' => '/admin/compliance/controlled_book_version.php?id=' . $versionId,
        'label' => $bookLabel,
    ),
    'actions' => array(
        array(
            'label' => 'Open editor',
            'href' => '/admin/compliance/controlled_book_editor.php?version_id=' . $versionId,
            'variant' => 'secondary',
        ),
    ),
));

?>
<section class="cmp-card" id="cp-docx-import">
  <h2 style="margin:0 0 8px;">Upload Parts</h2>
  <p style="margin:0 0 16px;font-size:13px;color:#64748b;max-width:720px;">
    Export each Part from Apple Pages as <strong>Word (.docx)</strong>. Embedded per-Part TOCs are skipped automatically.
    Tables use your book table styles; images are uploaded and inserted as normal image blocks.
  </p>

  <?php if ($isReleased): ?>
    <p style="margin:0;color:#b45309;">This version is released and cannot be imported. Create a new draft first.</p>
  <?php else: ?>
    <form id="cp-docx-import-form" enctype="multipart/form-data" style="display:grid;gap:12px;max-width:720px;">
      <input type="hidden" name="version_id" value="<?= (int)$versionId ?>">
      <?php for ($part = 0; $part <= 4; $part++): ?>
        <label style="display:grid;gap:6px;padding:12px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;">
          <span style="font-size:13px;font-weight:700;color:#0f2744;">Part <?= $part ?><?= $part === 0 ? ' — Manual Administration' : '' ?></span>
          <input type="file" name="part_<?= $part ?>" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
        </label>
      <?php endfor; ?>

      <label style="display:flex;gap:8px;align-items:center;font-size:13px;color:#334155;">
        <input type="checkbox" name="force" value="1" checked>
        Replace existing author blocks in imported sections (recommended)
      </label>

      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px;">
        <button type="button" id="cp-docx-preview-btn">Preview import</button>
        <button type="button" id="cp-docx-apply-btn" style="background:#0f2744;color:#fff;border:none;padding:10px 16px;border-radius:8px;cursor:pointer;">
          Import into book
        </button>
      </div>
    </form>

    <div id="cp-docx-import-status" style="margin-top:16px;font-size:13px;color:#334155;"></div>
    <pre id="cp-docx-import-report" style="margin-top:12px;padding:12px;background:#0f172a;color:#e2e8f0;border-radius:8px;font-size:12px;overflow:auto;max-height:360px;display:none;"></pre>
  <?php endif; ?>
</section>

<script>
(function () {
  var form = document.getElementById('cp-docx-import-form');
  if (!form) return;

  var statusEl = document.getElementById('cp-docx-import-status');
  var reportEl = document.getElementById('cp-docx-import-report');
  var apiUrl = '/admin/api/controlled_book_docx_import_api.php';

  function hasAnyFile() {
    var inputs = form.querySelectorAll('input[type="file"]');
    for (var i = 0; i < inputs.length; i++) {
      if (inputs[i].files && inputs[i].files.length > 0) return true;
    }
    return false;
  }

  function setStatus(msg, isError) {
    statusEl.textContent = msg || '';
    statusEl.style.color = isError ? '#b45309' : '#334155';
  }

  function showReport(obj) {
    reportEl.style.display = 'block';
    reportEl.textContent = JSON.stringify(obj, null, 2);
  }

  function buildFormData(action) {
    var fd = new FormData(form);
    fd.append('action', action);
    if (!fd.has('force')) {
      fd.append('force', '0');
    }
    return fd;
  }

  function postImport(action) {
    if (!hasAnyFile()) {
      setStatus('Select at least one Part DOCX file.', true);
      return;
    }
    setStatus(action === 'preview_docx_import' ? 'Analyzing files…' : 'Importing… this may take a minute.');
    reportEl.style.display = 'none';

    fetch(apiUrl, { method: 'POST', body: buildFormData(action), credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          throw new Error((data && data.error) || 'Request failed');
        }
        if (action === 'preview_docx_import') {
          setStatus('Preview ready. Review counts below, then click Import into book.');
          showReport(data.preview || data);
          return;
        }
        setStatus('Import complete. Open the editor to review content.');
        showReport(data.result || data);
      })
      .catch(function (err) {
        setStatus(err.message || String(err), true);
      });
  }

  document.getElementById('cp-docx-preview-btn').addEventListener('click', function () {
    postImport('preview_docx_import');
  });

  document.getElementById('cp-docx-apply-btn').addEventListener('click', function () {
    if (!window.confirm('Import uploaded Parts into this book version? Existing author blocks in affected sections will be replaced.')) {
      return;
    }
    postImport('apply_docx_import');
  });
})();
</script>
<?php

compliance_page_close();
cw_footer();
