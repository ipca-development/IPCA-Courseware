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
    cw_header('Compliance · Annexes');
    compliance_page_open(array(
        'overline' => 'Compliance · Controlled publishing',
        'title' => 'Annex import',
        'back' => array('href' => '/admin/compliance/controlled_books.php', 'label' => 'All books'),
    ));
    echo '<section class="cmp-card"><p style="margin:0;">Provide ?version_id=…</p></section>';
    compliance_page_close();
    cw_footer();
    return;
}

$version = $foundation->getVersion($versionId);
if ($version === null) {
    cw_header('Compliance · Annexes');
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

cw_header('Compliance · Annexes · ' . $bookLabel);

compliance_page_open(array(
    'overline' => 'Compliance · Controlled publishing',
    'title' => 'Import & manage annexes',
    'description' => 'Add annexes as images (styled forms) or editable DOCX tables. Choose portrait or landscape per annex.',
    'back' => array(
        'href' => '/admin/compliance/controlled_book_version.php?id=' . $versionId,
        'label' => $bookLabel,
    ),
    'actions' => array(
        array(
            'label' => 'Open editor',
            'href' => '/admin/compliance/controlled_book_editor.php?version_id=' . $versionId,
            'variant' => 'primary',
        ),
    ),
));

?>
<section class="cmp-card" id="cp-annex-manager">
  <h2 style="margin:0 0 8px;">Annex list</h2>
  <p style="margin:0 0 16px;font-size:13px;color:#64748b;">The Annex Register and Highlight of Changes pages in the editor are updated automatically.</p>
  <div id="cp-annex-list" style="margin-bottom:20px;font-size:13px;color:#334155;">Loading annexes…</div>

  <?php if ($isReleased): ?>
    <p style="margin:0;color:#b45309;">This version is released and cannot be edited.</p>
  <?php else: ?>
    <h3 style="margin:24px 0 12px;font-size:15px;">Add annex</h3>
    <form id="cp-annex-form" enctype="multipart/form-data" style="display:grid;gap:12px;max-width:720px;">
      <input type="hidden" name="version_id" value="<?= (int)$versionId ?>">
      <label style="display:grid;gap:6px;">
        <span style="font-size:13px;font-weight:600;">Annex title</span>
        <input type="text" name="title" required placeholder="e.g. Checklist C172SP" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;">
      </label>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
        <label style="display:grid;gap:6px;">
          <span style="font-size:13px;font-weight:600;">Annex number (optional)</span>
          <input type="number" name="annex_number" min="1" placeholder="Auto" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;">
        </label>
        <label style="display:grid;gap:6px;">
          <span style="font-size:13px;font-weight:600;">Suffix (optional)</span>
          <input type="text" name="annex_suffix" maxlength="1" placeholder="a, b, c…" pattern="[a-zA-Z]?" title="Single letter when multiple annexes share the same number" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;">
        </label>
        <label style="display:grid;gap:6px;">
          <span style="font-size:13px;font-weight:600;">Revision</span>
          <input type="text" name="revision" value="1.0" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;">
        </label>
      </div>
      <p style="margin:-4px 0 0;font-size:12px;color:#64748b;">When several annexes share one number (e.g. three Annex&nbsp;02 briefings), leave suffix blank to auto-assign <strong>a</strong>, <strong>b</strong>, <strong>c</strong> after the first.</p>
      <label style="display:grid;gap:6px;">
        <span style="font-size:13px;font-weight:600;">Revision date</span>
        <input type="date" name="revision_date" value="<?= h(date('Y-m-d')) ?>" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;">
      </label>
      <fieldset style="border:1px solid #e2e8f0;border-radius:10px;padding:12px;margin:0;">
        <legend style="font-size:13px;font-weight:600;padding:0 6px;">Content type</legend>
        <label style="display:block;margin-bottom:6px;font-size:13px;"><input type="radio" name="content_mode" value="empty" checked> Empty (build in editor)</label>
        <label style="display:block;margin-bottom:6px;font-size:13px;"><input type="radio" name="content_mode" value="image"> Image (styled form — OCR stored for compliance mapping)</label>
        <label style="display:block;font-size:13px;"><input type="radio" name="content_mode" value="docx"> Word DOCX (editable tables)</label>
      </fieldset>
      <fieldset style="border:1px solid #e2e8f0;border-radius:10px;padding:12px;margin:0;">
        <legend style="font-size:13px;font-weight:600;padding:0 6px;">Page orientation</legend>
        <label style="margin-right:16px;font-size:13px;"><input type="radio" name="orientation" value="portrait" checked> Portrait</label>
        <label style="font-size:13px;"><input type="radio" name="orientation" value="landscape"> Landscape</label>
      </fieldset>
      <div id="cp-annex-upload-image" style="display:none;">
        <label style="display:grid;gap:6px;">
          <span style="font-size:13px;font-weight:600;">Image file (PNG, JPG, WEBP)</span>
          <input type="file" name="image" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp">
        </label>
      </div>
      <div id="cp-annex-upload-docx" style="display:none;">
        <label style="display:grid;gap:6px;">
          <span style="font-size:13px;font-weight:600;">Word document (.docx)</span>
          <input type="file" name="docx" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
        </label>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button type="submit">Create annex</button>
        <button type="button" id="cp-annex-refresh-btn" class="cmp-btn cmp-btn--secondary">Refresh list</button>
      </div>
    </form>
    <div id="cp-annex-status" style="margin-top:16px;font-size:13px;color:#334155;"></div>
  <?php endif; ?>
</section>

<script>
(function () {
  var versionId = <?= (int)$versionId ?>;
  var apiUrl = '/admin/api/controlled_book_annex_api.php';
  var listEl = document.getElementById('cp-annex-list');
  var statusEl = document.getElementById('cp-annex-status');
  var form = document.getElementById('cp-annex-form');
  var imageWrap = document.getElementById('cp-annex-upload-image');
  var docxWrap = document.getElementById('cp-annex-upload-docx');

  function setStatus(msg, tone) {
    if (!statusEl) return;
    statusEl.textContent = msg || '';
    statusEl.style.color = tone === 'error' ? '#b45309' : '#334155';
  }

  function syncUploadFields() {
    if (!form) return;
    var mode = (form.querySelector('input[name="content_mode"]:checked') || {}).value || 'empty';
    if (imageWrap) imageWrap.style.display = mode === 'image' ? 'block' : 'none';
    if (docxWrap) docxWrap.style.display = mode === 'docx' ? 'block' : 'none';
  }

  function renderList(annexes) {
    if (!listEl) return;
    if (!annexes || !annexes.length) {
      listEl.innerHTML = '<p style="margin:0;color:#64748b;">No annexes yet. Use the form below to add one.</p>';
      return;
    }
    var html = '<table style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr style="text-align:left;border-bottom:1px solid #e2e8f0;">'
      + '<th style="padding:8px 6px;">Nr</th><th>Title</th><th>Rev</th><th>Date</th><th>Mode</th><th></th></tr></thead><tbody>';
    annexes.forEach(function (a) {
      var num = a.annex_display_number || String(a.annex_number || 0).padStart(2, '0');
      var editUrl = '/admin/compliance/controlled_book_editor.php?version_id=' + versionId + '&section_id=' + a.section_id;
      html += '<tr style="border-bottom:1px solid #f1f5f9;">'
        + '<td style="padding:8px 6px;">' + num + '</td>'
        + '<td style="padding:8px 6px;">' + (a.title || '') + '</td>'
        + '<td style="padding:8px 6px;">' + (a.revision || '') + '</td>'
        + '<td style="padding:8px 6px;">' + (a.revision_date || '') + '</td>'
        + '<td style="padding:8px 6px;">' + (a.content_mode || '') + ' / ' + (a.orientation || 'portrait') + '</td>'
        + '<td style="padding:8px 6px;"><a href="' + editUrl + '">Open in editor</a></td></tr>';
    });
    html += '</tbody></table>';
    listEl.innerHTML = html;
  }

  function loadList() {
    if (!listEl) return;
    listEl.textContent = 'Loading…';
    fetch(apiUrl + '?action=list&version_id=' + versionId, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Load failed');
        renderList(res.annexes || []);
      })
      .catch(function (e) {
        listEl.textContent = e.message || 'Could not load annexes.';
      });
  }

  if (form) {
    form.querySelectorAll('input[name="content_mode"]').forEach(function (el) {
      el.addEventListener('change', syncUploadFields);
    });
    syncUploadFields();

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      setStatus('Creating annex…');
      var fd = new FormData(form);
      fd.set('action', 'create');
      fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res.ok) throw new Error(res.error || 'Create failed');
          setStatus('Annex created.');
          if (res.editor_url) {
            window.location.href = res.editor_url;
          } else {
            loadList();
          }
        })
        .catch(function (e) {
          setStatus(e.message || 'Create failed', 'error');
        });
    });
  }

  var refreshBtn = document.getElementById('cp-annex-refresh-btn');
  if (refreshBtn) refreshBtn.addEventListener('click', loadList);

  loadList();
})();
</script>

<?php
compliance_page_close();
cw_footer();
