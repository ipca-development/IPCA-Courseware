<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsAnnexBookService.php';

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

$isAnnexBook = BooksManualsAnnexBookService::isAnnexBookVersion($version);
$isReleased = (string)($version['lifecycle_status'] ?? '') === 'released';
$canEdit = !$isReleased || $isAnnexBook;
$bookLabel = (string)$version['book_key'] . ' ' . (string)$version['version_label'];

cw_header('Compliance · Annexes · ' . $bookLabel);

compliance_page_open(array(
    'overline' => 'Compliance · Controlled publishing',
    'title' => 'Manage annexes',
    'description' => 'Add, rename, revert, or remove annexes. The Annex Register updates automatically.',
    'back' => array(
        'href' => $isAnnexBook
            ? '/admin/books_manuals/annexes.php'
            : '/admin/compliance/controlled_book_version.php?id=' . $versionId,
        'label' => $bookLabel,
    ),
    'actions' => $canEdit
        ? array(array(
            'label' => '+ New Annex',
            'modal' => 'cp-annex-create-modal',
            'variant' => 'primary',
        ))
        : array(),
));

?>
<section class="cmp-card" id="cp-annex-manager">
  <h2 style="margin:0 0 8px;">Annex list</h2>
  <p style="margin:0 0 16px;font-size:13px;color:#64748b;">
    Edit opens the annex in the editor. Rename changes the title and number. Revert restores a previous stored version. Delete hides the annex from the register without removing its content.
  </p>
  <label style="display:flex;gap:8px;align-items:center;margin:0 0 12px;font-size:13px;">
    <input type="checkbox" id="cp-annex-show-deleted">
    <span>Show deleted annexes</span>
  </label>
  <div id="cp-annex-list" style="margin-bottom:20px;font-size:13px;color:#334155;">Loading annexes…</div>
  <?php if (!$canEdit): ?>
    <p style="margin:0;color:#b45309;">This version is released and cannot be edited.</p>
  <?php endif; ?>
  <div id="cp-annex-status" style="margin-top:16px;font-size:13px;color:#334155;"></div>
</section>
<style>
  #cp-annex-list { container-type: inline-size; }
  #cp-annex-manager .cp-annex-list-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    font-size: 13px;
  }
  #cp-annex-manager .cp-annex-list-table th,
  #cp-annex-manager .cp-annex-list-table td {
    padding: 8px 5px;
    vertical-align: top;
  }
  #cp-annex-manager .cp-annex-col-number { width: 42px; }
  #cp-annex-manager .cp-annex-col-revision { width: 60px; }
  #cp-annex-manager .cp-annex-col-date { width: 104px; }
  #cp-annex-manager .cp-annex-col-type,
  #cp-annex-manager .cp-annex-col-orientation { width: 38px; }
  #cp-annex-manager .cp-annex-col-actions { width: 176px; }
  #cp-annex-manager .cp-annex-meta {
    white-space: nowrap;
    overflow-wrap: normal;
    word-break: normal;
  }
  #cp-annex-manager .cp-annex-icon-heading,
  #cp-annex-manager .cp-annex-icon-cell { text-align: center; }
  #cp-annex-manager .cp-annex-icon-heading {
    padding-left: 2px;
    padding-right: 2px;
  }
  #cp-annex-manager .cp-annex-symbol {
    display: inline-flex;
    width: 24px;
    height: 24px;
    align-items: center;
    justify-content: center;
    color: #475569;
  }
  #cp-annex-manager .cp-annex-symbol svg { width: 20px; height: 20px; }
  #cp-annex-manager .cp-annex-icon-heading .cp-annex-symbol {
    width: 20px;
    height: 20px;
  }
  #cp-annex-manager .cp-annex-row-actions {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 5px;
    align-items: center;
  }
  #cp-annex-manager .cp-annex-row-actions .app-btn {
    width: 100%;
    min-height: 30px;
    height: 30px;
    padding: 0 5px;
    margin: 0;
    font-size: 11px;
    line-height: 28px;
    text-align: center;
    white-space: nowrap;
  }
  #cp-annex-manager .cp-annex-row-actions .cp-annex-restore-btn {
    grid-column: 1 / -1;
  }
  #cp-annex-revert-modal .cp-annex-revert-apply {
    min-height: 30px;
    height: 30px;
    padding: 0 9px;
    font-size: 12px;
  }
  @container (max-width: 560px) {
    #cp-annex-manager .cp-annex-list-table,
    #cp-annex-manager .cp-annex-list-table tbody {
      display: block;
    }
    #cp-annex-manager .cp-annex-list-table colgroup,
    #cp-annex-manager .cp-annex-list-table thead {
      display: none;
    }
    #cp-annex-manager .cp-annex-list-table tr {
      display: grid;
      grid-template-columns: 42px minmax(0, 1fr) 38px 38px;
      align-items: start;
      padding: 7px 0;
    }
    #cp-annex-manager .cp-annex-list-table td { padding: 5px; }
    #cp-annex-manager .cp-annex-list-table td:nth-child(1) { grid-area: 1 / 1; }
    #cp-annex-manager .cp-annex-list-table td:nth-child(2) { grid-area: 1 / 2; }
    #cp-annex-manager .cp-annex-list-table td:nth-child(5) { grid-area: 1 / 3; }
    #cp-annex-manager .cp-annex-list-table td:nth-child(6) { grid-area: 1 / 4; }
    #cp-annex-manager .cp-annex-list-table td:nth-child(3) { grid-area: 2 / 1; }
    #cp-annex-manager .cp-annex-list-table td:nth-child(4) { grid-area: 2 / 2 / 2 / 5; }
    #cp-annex-manager .cp-annex-list-table td:nth-child(7) { grid-area: 3 / 1 / 3 / 5; }
    #cp-annex-manager .cp-annex-list-table td:nth-child(3)::before {
      content: "Rev ";
      color: #64748b;
      font-size: 10px;
    }
    #cp-annex-manager .cp-annex-list-table td:nth-child(4)::before {
      content: "Date ";
      color: #64748b;
      font-size: 10px;
    }
  }
</style>

<?php if ($canEdit): ?>
<dialog class="compliance-modal" id="cp-annex-create-modal">
  <div class="compliance-modal__panel">
    <div class="compliance-modal__header">
      <h2 class="compliance-modal__title">New Annex</h2>
      <button type="button" class="compliance-modal__close cmp-btn-secondary" data-compliance-modal-close aria-label="Close">&times;</button>
    </div>
    <form id="cp-annex-form" enctype="multipart/form-data">
      <div class="compliance-modal__body" style="display:grid;gap:12px;">
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
        <p style="margin:-4px 0 0;font-size:12px;color:#64748b;">Use letter suffixes for shared numbers (e.g. <strong>02a</strong>, <strong>02b</strong>). Leave suffix blank to auto-assign the next letter.</p>
        <label style="display:flex;gap:8px;align-items:center;font-size:13px;">
          <input type="checkbox" name="use_letter_suffix" value="1" checked>
          <span>Use letter suffix for this annex (02a, not plain 02)</span>
        </label>
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
      </div>
      <div class="compliance-modal__footer">
        <button type="button" class="cmp-btn-secondary" data-compliance-modal-close>Cancel</button>
        <button type="submit">Create annex</button>
      </div>
    </form>
  </div>
</dialog>

<dialog class="compliance-modal" id="cp-annex-rename-modal">
  <div class="compliance-modal__panel">
    <div class="compliance-modal__header">
      <h2 class="compliance-modal__title">Rename Annex</h2>
      <button type="button" class="compliance-modal__close cmp-btn-secondary" data-compliance-modal-close aria-label="Close">&times;</button>
    </div>
    <form id="cp-annex-edit-form">
      <div class="compliance-modal__body" style="display:grid;gap:12px;">
        <input type="hidden" name="section_id" value="">
        <label style="display:grid;gap:6px;">
          <span style="font-size:13px;font-weight:600;">Annex name / title</span>
          <input type="text" name="title" required placeholder="e.g. Synthetic Device Safety Briefing AL42" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;">
        </label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <label style="display:grid;gap:6px;">
            <span style="font-size:13px;font-weight:600;">Base number</span>
            <input type="number" name="annex_number" min="1" required style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;">
          </label>
          <label style="display:grid;gap:6px;">
            <span style="font-size:13px;font-weight:600;">Suffix letter</span>
            <input type="text" name="annex_suffix" maxlength="1" placeholder="a, b, c…" pattern="[a-zA-Z]?" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;">
          </label>
        </div>
        <p style="margin:0;font-size:12px;color:#64748b;">Example: number <strong>2</strong> + suffix <strong>a</strong> → displayed as <strong>02a</strong>.</p>
      </div>
      <div class="compliance-modal__footer">
        <button type="button" class="cmp-btn-secondary" data-compliance-modal-close id="cp-annex-edit-cancel">Cancel</button>
        <button type="submit">Save</button>
      </div>
    </form>
  </div>
</dialog>

<dialog class="compliance-modal" id="cp-annex-revert-modal">
  <div class="compliance-modal__panel">
    <div class="compliance-modal__header">
      <h2 class="compliance-modal__title">Revert Annex</h2>
      <button type="button" class="compliance-modal__close cmp-btn-secondary" data-compliance-modal-close aria-label="Close">&times;</button>
    </div>
    <div class="compliance-modal__body">
      <p style="margin:0 0 12px;font-size:13px;color:#64748b;">Choose a stored revision to restore. The current content is kept in the revision log.</p>
      <div id="cp-annex-revert-list">Loading revisions…</div>
    </div>
    <div class="compliance-modal__footer">
      <button type="button" class="cmp-btn-secondary" data-compliance-modal-close>Close</button>
    </div>
  </div>
</dialog>
<?php endif; ?>

<script>
(function () {
  var versionId = <?= (int)$versionId ?>;
  var canEdit = <?= $canEdit ? 'true' : 'false' ?>;
  var apiUrl = '/admin/api/controlled_book_annex_api.php';
  var listEl = document.getElementById('cp-annex-list');
  var statusEl = document.getElementById('cp-annex-status');
  var form = document.getElementById('cp-annex-form');
  var editForm = document.getElementById('cp-annex-edit-form');
  var editCancelBtn = document.getElementById('cp-annex-edit-cancel');
  var imageWrap = document.getElementById('cp-annex-upload-image');
  var docxWrap = document.getElementById('cp-annex-upload-docx');
  var showDeletedEl = document.getElementById('cp-annex-show-deleted');
  var revertListEl = document.getElementById('cp-annex-revert-list');
  var revertSectionId = 0;

  function setStatus(msg, tone) {
    if (!statusEl) return;
    statusEl.textContent = msg || '';
    statusEl.style.color = tone === 'error' ? '#b45309' : '#334155';
  }

  function openModal(id) {
    var dialog = document.getElementById(id);
    if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
  }

  function closeModal(id) {
    var dialog = document.getElementById(id);
    if (dialog && typeof dialog.close === 'function') dialog.close();
  }

  function syncUploadFields() {
    if (!form) return;
    var mode = (form.querySelector('input[name="content_mode"]:checked') || {}).value || 'empty';
    if (imageWrap) imageWrap.style.display = mode === 'image' ? 'block' : 'none';
    if (docxWrap) docxWrap.style.display = mode === 'docx' ? 'block' : 'none';
  }

  function contentModeSymbol(mode) {
    var normalized = String(mode || 'empty').toLowerCase();
    var label = normalized === 'docx' ? 'Word DOCX' : (normalized === 'image' ? 'Image' : 'Editor content');
    var svg = normalized === 'docx'
      ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2.75h8l4 4V21.25H6z" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M14 2.75v4h4M8.5 11l1.2 5 1.3-3.6 1.3 3.6 1.2-5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
      : (normalized === 'image'
        ? '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="8.5" cy="9" r="1.5" fill="currentColor"/><path d="m5 17 4.5-4 3 2.5 2.5-2 4 3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>'
        : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3.5h14v17H5zM8 8h8M8 12h8M8 16h5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>');
    return '<span class="cp-annex-symbol" role="img" aria-label="' + label + '" title="' + label + '">' + svg + '</span>';
  }

  function orientationSymbol(orientation) {
    var landscape = String(orientation || 'portrait').toLowerCase() === 'landscape';
    var label = landscape ? 'Landscape' : 'Portrait';
    var rect = landscape
      ? '<rect x="2.75" y="6.25" width="18.5" height="11.5" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.7"/>'
      : '<rect x="6.25" y="2.75" width="11.5" height="18.5" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.7"/>';
    return '<span class="cp-annex-symbol" role="img" aria-label="' + label + '" title="' + label + '"><svg viewBox="0 0 24 24" aria-hidden="true">' + rect + '</svg></span>';
  }

  function renderList(annexes) {
    if (!listEl) return;
    if (!annexes || !annexes.length) {
      listEl.innerHTML = '<p style="margin:0;color:#64748b;">No annexes yet. Use + New Annex to add one.</p>';
      return;
    }
    var typeHeading = '<span class="cp-annex-symbol" role="img" aria-label="Content type" title="Content type">'
      + '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2.75h8l4 4V21.25H6zM14 2.75v4h4" fill="none" stroke="currentColor" stroke-width="1.6"/></svg></span>';
    var pageHeading = '<span class="cp-annex-symbol" role="img" aria-label="Page orientation" title="Page orientation">'
      + '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="6.25" y="2.75" width="11.5" height="18.5" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.6"/></svg></span>';
    var html = '<table class="cp-annex-list-table"><colgroup>'
      + '<col class="cp-annex-col-number"><col><col class="cp-annex-col-revision"><col class="cp-annex-col-date">'
      + '<col class="cp-annex-col-type"><col class="cp-annex-col-orientation"><col class="cp-annex-col-actions">'
      + '</colgroup><thead><tr style="text-align:left;border-bottom:1px solid #e2e8f0;">'
      + '<th class="cp-annex-meta">Nr</th><th>Title</th><th class="cp-annex-meta">Rev</th><th class="cp-annex-meta">Date</th>'
      + '<th class="cp-annex-icon-heading">' + typeHeading + '</th><th class="cp-annex-icon-heading">' + pageHeading + '</th>'
      + '<th class="cp-annex-meta">Actions</th></tr></thead><tbody>';
    annexes.forEach(function (a) {
      var num = a.annex_display_number || String(a.annex_number || 0).padStart(2, '0');
      var editUrl = '/admin/compliance/controlled_book_editor.php?version_id=' + versionId + '&section_id=' + a.section_id;
      var shortTitle = a.annex_short_title || '';
      var deleted = !!a.deleted;
      html += '<tr style="border-bottom:1px solid #f1f5f9;' + (deleted ? 'opacity:0.65;' : '') + '">'
        + '<td class="cp-annex-meta">' + num + '</td>'
        + '<td>' + (a.title || '')
        + (deleted ? ' <span style="color:#b45309;">(deleted)</span>' : '') + '</td>'
        + '<td class="cp-annex-meta">' + (a.revision || '') + '</td>'
        + '<td class="cp-annex-meta">' + (a.revision_date || '') + '</td>'
        + '<td class="cp-annex-icon-cell">' + contentModeSymbol(a.content_mode) + '</td>'
        + '<td class="cp-annex-icon-cell">' + orientationSymbol(a.orientation) + '</td>'
        + '<td><div class="cp-annex-row-actions">';
      if (!deleted) {
        html += '<a class="app-btn app-btn--secondary" href="' + editUrl + '">Edit</a>';
        if (canEdit) {
          html += '<button type="button" class="app-btn app-btn--secondary cp-annex-edit-btn" data-section-id="' + a.section_id + '" '
            + 'data-annex-number="' + (a.annex_number || 0) + '" data-annex-suffix="' + (a.annex_suffix || '') + '" '
            + 'data-short-title="' + escAttr(shortTitle) + '" title="Rename Annex">Rename</button>'
            + '<button type="button" class="app-btn app-btn--secondary cp-annex-revert-btn" data-section-id="' + a.section_id + '" '
            + 'data-title="' + escAttr(a.title || '') + '">Revert</button>'
            + '<button type="button" class="app-btn cp-annex-delete-btn" data-section-id="' + a.section_id + '" '
            + 'data-title="' + escAttr(a.title || '') + '" style="background:#b91c1c;border-color:#b91c1c;color:#fff;">Delete</button>';
        }
      } else if (canEdit) {
        html += '<button type="button" class="app-btn app-btn--secondary cp-annex-restore-btn" data-section-id="' + a.section_id + '">Restore</button>';
      } else {
        html += '<span style="color:#94a3b8;">Deleted</span>';
      }
      html += '</div></td></tr>';
    });
    html += '</tbody></table>';
    listEl.innerHTML = html;

    listEl.querySelectorAll('.cp-annex-edit-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openEditDialog({
          section_id: parseInt(btn.getAttribute('data-section-id') || '0', 10),
          annex_number: parseInt(btn.getAttribute('data-annex-number') || '0', 10),
          annex_suffix: btn.getAttribute('data-annex-suffix') || '',
          annex_short_title: btn.getAttribute('data-short-title') || '',
        });
      });
    });
    listEl.querySelectorAll('.cp-annex-revert-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openRevertDialog(parseInt(btn.getAttribute('data-section-id') || '0', 10));
      });
    });
    listEl.querySelectorAll('.cp-annex-delete-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var sectionId = parseInt(btn.getAttribute('data-section-id') || '0', 10);
        var title = btn.getAttribute('data-title') || 'this annex';
        if (!sectionId || !window.confirm('Delete ' + title + '? It will be hidden from the register and editor. You can restore it later from this page.')) {
          return;
        }
        postAnnexAction('soft_delete', sectionId, 'Deleting annex…', 'Annex deleted.');
      });
    });
    listEl.querySelectorAll('.cp-annex-restore-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var sectionId = parseInt(btn.getAttribute('data-section-id') || '0', 10);
        if (!sectionId) return;
        postAnnexAction('restore', sectionId, 'Restoring annex…', 'Annex restored.');
      });
    });
  }

  function escAttr(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  function openEditDialog(annex) {
    if (!editForm) return;
    editForm.querySelector('input[name="section_id"]').value = String(annex.section_id || '');
    editForm.querySelector('input[name="title"]').value = annex.annex_short_title || '';
    editForm.querySelector('input[name="annex_number"]').value = String(annex.annex_number || '');
    editForm.querySelector('input[name="annex_suffix"]').value = annex.annex_suffix || '';
    openModal('cp-annex-rename-modal');
  }

  function openRevertDialog(sectionId) {
    revertSectionId = sectionId;
    if (revertListEl) revertListEl.textContent = 'Loading revisions…';
    openModal('cp-annex-revert-modal');
    fetch(apiUrl + '?action=revisions&version_id=' + versionId + '&section_id=' + sectionId, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Could not load revisions.');
        renderRevisions(res.revisions || []);
      })
      .catch(function (e) {
        if (revertListEl) revertListEl.textContent = e.message || 'Could not load revisions.';
      });
  }

  function renderRevisions(revisions) {
    if (!revertListEl) return;
    if (!revisions.length) {
      revertListEl.innerHTML = '<p style="margin:0;color:#64748b;">No stored revisions yet.</p>';
      return;
    }
    var html = '<table style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr style="text-align:left;border-bottom:1px solid #e2e8f0;">'
      + '<th style="padding:8px 6px;">Revision</th><th>Date</th><th>By</th><th>Source</th><th></th></tr></thead><tbody>';
    revisions.forEach(function (rev) {
      html += '<tr style="border-bottom:1px solid #f1f5f9;">'
        + '<td style="padding:8px 6px;">' + (rev.revision_to || '') + '</td>'
        + '<td style="padding:8px 6px;">' + (rev.revision_date || '') + '</td>'
        + '<td style="padding:8px 6px;">' + (rev.actor_name || '') + '</td>'
        + '<td style="padding:8px 6px;">' + (rev.source || '') + (rev.has_snapshot ? '' : ' <span style="color:#b45309;">(no snapshot)</span>') + '</td>'
        + '<td style="padding:8px 6px;">';
      if (rev.has_snapshot) {
        html += '<button type="button" class="app-btn app-btn--primary cp-annex-revert-apply" data-revision-id="' + rev.id + '">Restore this version</button>';
      } else {
        html += '<span style="color:#94a3b8;">Unavailable</span>';
      }
      html += '</td></tr>';
    });
    html += '</tbody></table>';
    revertListEl.innerHTML = html;
    revertListEl.querySelectorAll('.cp-annex-revert-apply').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var revisionId = parseInt(btn.getAttribute('data-revision-id') || '0', 10);
        if (!revisionId || !revertSectionId) return;
        if (!window.confirm('Restore this stored annex version? Current content will remain in the revision log.')) {
          return;
        }
        var fd = new FormData();
        fd.set('action', 'revert');
        fd.set('version_id', String(versionId));
        fd.set('section_id', String(revertSectionId));
        fd.set('revision_id', String(revisionId));
        setStatus('Reverting annex…');
        fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (!res.ok) throw new Error(res.error || 'Revert failed');
            setStatus('Annex reverted.');
            closeModal('cp-annex-revert-modal');
            loadList();
          })
          .catch(function (e) {
            setStatus(e.message || 'Revert failed', 'error');
          });
      });
    });
  }

  function postAnnexAction(action, sectionId, pendingMsg, successMsg) {
    var fd = new FormData();
    fd.set('action', action);
    fd.set('version_id', String(versionId));
    fd.set('section_id', String(sectionId));
    setStatus(pendingMsg);
    fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Request failed');
        setStatus(successMsg);
        loadList();
      })
      .catch(function (e) {
        setStatus(e.message || 'Request failed', 'error');
      });
  }

  function loadList() {
    if (!listEl) return;
    listEl.textContent = 'Loading…';
    var includeDeleted = showDeletedEl && showDeletedEl.checked ? '1' : '0';
    fetch(apiUrl + '?action=list&version_id=' + versionId + '&include_deleted=' + includeDeleted, { credentials: 'same-origin' })
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
      var letterSuffix = form.querySelector('input[name="use_letter_suffix"]');
      if (!letterSuffix || !letterSuffix.checked) {
        fd.set('use_letter_suffix', '0');
      }
      fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res.ok) throw new Error(res.error || 'Create failed');
          setStatus('Annex created.');
          closeModal('cp-annex-create-modal');
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

  if (editForm) {
    editForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var sectionId = parseInt(editForm.querySelector('input[name="section_id"]').value || '0', 10);
      var title = (editForm.querySelector('input[name="title"]').value || '').trim();
      var number = parseInt(editForm.querySelector('input[name="annex_number"]').value || '0', 10);
      var suffix = (editForm.querySelector('input[name="annex_suffix"]').value || '').trim();
      if (sectionId <= 0 || number <= 0 || title === '') {
        setStatus('Title and annex number are required.', 'error');
        return;
      }
      var fd = new FormData();
      fd.set('action', 'update_identity');
      fd.set('version_id', String(versionId));
      fd.set('section_id', String(sectionId));
      fd.set('title', title);
      fd.set('annex_number', String(number));
      fd.set('annex_suffix', suffix);
      setStatus('Saving annex…');
      fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res.ok) throw new Error(res.error || 'Update failed');
          setStatus('Annex updated: ' + (res.annex && res.annex.title ? res.annex.title : 'saved') + '.');
          closeModal('cp-annex-rename-modal');
          loadList();
        })
        .catch(function (e) {
          setStatus(e.message || 'Update failed', 'error');
        });
    });
  }
  if (editCancelBtn) {
    editCancelBtn.addEventListener('click', function () { closeModal('cp-annex-rename-modal'); });
  }
  if (showDeletedEl) showDeletedEl.addEventListener('change', loadList);

  loadList();
})();
</script>

<?php
compliance_page_close();
cw_footer();
