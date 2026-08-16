<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingSectionService.php';

$user = compliance_require_access($pdo);
$foundation = new ControlledPublishingFoundationService($pdo);
$sections = new ControlledPublishingSectionService($pdo);

$versionId = isset($_GET['version_id']) ? (int)$_GET['version_id'] : 0;
$sectionId = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;

if ($versionId <= 0) {
    cw_header('Compliance · Book Editor');
    compliance_page_open(array(
        'overline' => 'Compliance · Controlled publishing',
        'title' => 'Book editor',
        'back' => array('href' => '/admin/compliance/controlled_books.php', 'label' => 'All books'),
    ));
    echo '<section class="cmp-card"><p style="margin:0;">Provide ?version_id=...</p></section>';
    compliance_page_close();
    cw_footer();
    return;
}

$version = $foundation->getVersion($versionId);
if ($version === null) {
    cw_header('Compliance · Book Editor');
    compliance_page_open(array(
        'overline' => 'Compliance · Controlled publishing',
        'title' => 'Version not found',
        'back' => array('href' => '/admin/compliance/controlled_books.php', 'label' => 'All books'),
    ));
    echo '<section class="cmp-card"><p style="margin:0;">No version for that id.</p></section>';
    compliance_page_close();
    cw_footer();
    return;
}

if ($sectionId <= 0) {
    foreach (array('cover', 'part_1', 'main_content') as $key) {
        foreach ($sections->listFlatSections($versionId) as $row) {
            if ((string)$row['section_key'] === $key) {
                $sectionId = (int)$row['id'];
                break 2;
            }
        }
    }
    if ($sectionId <= 0) {
        $flat = $sections->listFlatSections($versionId);
        $sectionId = $flat !== array() ? (int)$flat[0]['id'] : 0;
    }
}

$cssPath = __DIR__ . '/../../../public/assets/controlled_book_editor.css';
$jsPath = __DIR__ . '/../../../public/assets/controlled_book_editor.js';
$cssVer = is_file($cssPath) ? (string)filemtime($cssPath) : '1';
$jsVer = is_file($jsPath) ? (string)filemtime($jsPath) : '1';

cw_header('Compliance · ' . (string)$version['book_key'] . ' Editor');

compliance_page_open(array(
    'overline' => 'Compliance · Controlled publishing',
    'title' => (string)$version['book_key'] . ' ' . (string)$version['version_label'] . ' — Editor',
    'description' => 'Document-style manual editor with section tree and governed content blocks.',
    'back' => array(
        'href' => '/admin/compliance/controlled_book_version.php?id=' . $versionId,
        'label' => 'Version settings',
    ),
    'actions' => array(
        array(
            'label' => 'Manage annexes',
            'href' => '/admin/compliance/controlled_book_annexes.php?version_id=' . $versionId,
            'variant' => 'secondary',
        ),
        array(
            'label' => 'Governance',
            'href' => '/admin/compliance/controlled_book_version.php?id=' . $versionId,
            'variant' => 'secondary',
        ),
    ),
));

?>
<link rel="stylesheet" href="/assets/controlled_book_editor.css?v=<?= h($cssVer) ?>">

<div class="cpb-editor-root" id="cpbEditorRoot"
     data-version-id="<?= (int)$versionId ?>"
     data-section-id="<?= (int)$sectionId ?>">
  <div class="cpb-editor-shell">
    <aside class="cpb-tree-panel">
      <div class="cpb-tree-head">
        <h2 id="cpbTreeHeadTitle">Manual sections</h2>
        <button type="button" id="cpbEditOutline" class="cpb-tree-toggle-all" title="Edit PART and MAIN chapter titles">Edit outline</button>
        <button type="button" id="cpbTreeToggleAll" class="cpb-tree-toggle-all" aria-pressed="false" title="Expand or collapse all sections">Expand all</button>
      </div>
      <div class="cpb-tree-scroll" id="cpbSectionTree">
        <p style="padding:12px 16px;margin:0;font-size:12px;color:#94a3b8;">Loading outline…</p>
      </div>
      <div class="cpb-outline-panel" id="cpbOutlinePanel" hidden>
        <p class="cpb-outline-note">Cover, Part 0, and Annexes stay fixed. Rename PARTs and add, remove, or reorder MAIN chapters.</p>
        <div id="cpbOutlineBody"></div>
      </div>
      <div class="cpb-tree-actions">
        <span id="cpbAddSubsection" class="cpb-tree-add" role="button" tabindex="0" style="display:none;">+ Add subsection</span>
      </div>
    </aside>

    <div class="cpb-workspace">
      <div class="cpb-toolbar" id="cpbToolbar">
        <div class="cpb-toolbar-main" id="cpbToolbarMain">
        <div class="cpb-toolbar-row cpb-toolbar-row--primary">
        <div class="cpb-toolbar-group">
          <button type="button" class="cpb-tool-btn" id="cpbUndo" title="Undo (Ctrl+Z)">↶</button>
          <button type="button" class="cpb-tool-btn" id="cpbRedo" title="Redo (Ctrl+Shift+Z)">↷</button>
        </div>
        <div class="cpb-toolbar-group">
          <button type="button" class="cpb-tool-btn" data-cmd="bold" title="Bold (Ctrl+B)"><strong>B</strong></button>
          <button type="button" class="cpb-tool-btn" data-cmd="italic" title="Italic (Ctrl+I)"><em>I</em></button>
          <button type="button" class="cpb-tool-btn" data-cmd="underline" title="Underline (Ctrl+U)"><u>U</u></button>
        </div>
        <div class="cpb-toolbar-group">
          <button type="button" class="cpb-tool-btn" data-align="left" title="Align left">L</button>
          <button type="button" class="cpb-tool-btn" data-align="center" title="Align center">C</button>
          <button type="button" class="cpb-tool-btn" data-align="right" title="Align right">R</button>
        </div>
        <div class="cpb-toolbar-group cpb-toolbar-group--style">
          <select id="cpbParagraphStyleSelect" class="cpb-tool-select cpb-tool-select--paragraph-style" title="Paragraph style">
            <option value="title">Title</option>
            <option value="subtitle_1">Subtitle 1</option>
            <option value="subtitle_2">Subtitle 2</option>
            <option value="subtitle_3">Subtitle 3</option>
            <option value="subtitle_4">Subtitle 4</option>
            <option value="regulatory_reference">Regulatory Reference</option>
            <option value="body" selected>Body</option>
            <option value="caption">Caption</option>
            <option value="custom" disabled>Custom</option>
          </select>
          <input type="text" id="cpbRegulatoryRef" class="cpb-tool-reg-ref" placeholder="MCCF key" title="MCCF regulatory reference (manual override)" hidden>
          <select id="cpbCrossRefDoc" class="cpb-tool-select cpb-tool-select--cross-ref" title="Cross reference document" hidden>
            <option value="">Cross ref doc…</option>
          </select>
          <select id="cpbCrossRefKey" class="cpb-tool-select cpb-tool-select--cross-ref" title="Cross reference entry" hidden disabled>
            <option value="">Select reference…</option>
          </select>
          <button type="button" class="cpb-tool-btn" id="cpbCrossRefClear" title="Clear cross reference" hidden>✕ Ref</button>
          <select id="cpbFontSelect" class="cpb-tool-select" title="Font family">
            <option value="serif">Serif</option>
            <option value="sans">Sans</option>
            <option value="arial">Arial</option>
            <option value="mono">Mono</option>
          </select>
          <select id="cpbFontSizeSelect" class="cpb-tool-select cpb-tool-select--size" title="Font size">
            <option value="8">8</option>
            <option value="9">9</option>
            <option value="10">10</option>
            <option value="11" selected>11</option>
            <option value="12">12</option>
            <option value="14">14</option>
            <option value="16">16</option>
            <option value="18">18</option>
          </select>
          <input type="color" id="cpbTextColor" class="cpb-tool-color" value="#0f172a" title="Text color">
        </div>
        <div class="cpb-toolbar-group">
          <button type="button" class="cpb-tool-btn" data-cmd="insertUnorderedList" title="Bullet list">•</button>
          <button type="button" class="cpb-tool-btn" data-cmd="insertOrderedList" title="Numbered list">1.</button>
          <select id="cpbListStart" class="cpb-tool-select cpb-tool-select--list-start"
                  title="Starting number for the selected numbered list" aria-label="Numbered list start" disabled>
            <?php for ($listStart = 1; $listStart <= 100; $listStart++): ?>
              <option value="<?= $listStart ?>"<?= $listStart === 1 ? ' selected' : '' ?>><?= $listStart ?></option>
            <?php endfor; ?>
          </select>
        </div>
        </div>
        <div class="cpb-table-toolbar" id="cpbTableToolbar" aria-label="Table editing controls">
        <div class="cpb-toolbar-row cpb-toolbar-row--secondary">
        <div class="cpb-toolbar-group">
          <button type="button" class="cpb-tool-btn" id="cpbOutdent" title="Decrease indent (Shift+Tab)">⇤</button>
          <button type="button" class="cpb-tool-btn" id="cpbIndent" title="Increase indent (Tab)">⇥</button>
        </div>
        <div class="cpb-toolbar-group">
          <button type="button" class="cpb-tool-btn" id="cpbOpenStyleEditor" title="Book style editor">Styles</button>
          <button type="button" class="cpb-tool-btn" id="cpbOpenHeaderEditor" title="Page header editor">Header</button>
          <button type="button" class="cpb-tool-btn" id="cpbInsertPageBreak" title="Insert a page break at the cursor">Page Break</button>
          <button type="button" class="cpb-tool-btn" data-add-block="paragraph" title="Add paragraph">¶</button>
          <button type="button" class="cpb-tool-btn" data-add-block="table" title="Add table">Table</button>
          <button type="button" class="cpb-tool-btn" id="cpbPickImage" title="Insert image">Image</button>
          <select id="cpbCalloutSelect" class="cpb-tool-select cpb-tool-select--callout" title="Insert Warning, Caution, Info…">
            <option value="" selected>⚑</option>
            <option value="warning">Warning</option>
            <option value="caution">Caution</option>
            <option value="info">Info</option>
            <option value="note">Note</option>
            <option value="manage">Presets…</option>
          </select>
          <select id="cpbDetectSelect" class="cpb-tool-select cpb-tool-select--detect" title="Auto-detect callouts, hyperlinks, and annex references">
            <option value="" selected>⌕</option>
            <option value="callouts">Callouts (page)</option>
            <option value="hyperlinks">Links (page)</option>
            <option value="annex_refs">Annex (page)</option>
            <option value="callouts_all">Callouts (all)</option>
            <option value="hyperlinks_all">Links (all)</option>
            <option value="annex_refs_all">Annex (all)</option>
          </select>
        </div>
        <div class="cpb-toolbar-group" aria-label="Table">
            <span class="cpb-toolbar-table-label">Table</span>
            <button type="button" class="cpb-tool-btn" data-table-action="table-align-left" title="Align table left" disabled>Left</button>
            <button type="button" class="cpb-tool-btn" data-table-action="table-align-center" title="Align table center" disabled>Center</button>
            <button type="button" class="cpb-tool-btn" data-table-action="table-align-right" title="Align table right" disabled>Right</button>
            <button type="button" class="cpb-tool-btn" data-table-action="toggle-title" title="Add or remove table title row" disabled>Title row</button>
            <button type="button" class="cpb-tool-btn cpb-tool-btn--danger" data-table-action="delete-table" title="Delete table" disabled>Delete table</button>
            <button type="button" class="cpb-tool-btn" data-table-action="copy-table" title="Copy entire table" disabled>Copy table</button>
            <button type="button" class="cpb-tool-btn" data-table-action="paste-table" title="Paste copied table below the selected table" disabled>Paste table</button>
        </div>
        </div>
        <div class="cpb-toolbar-row cpb-toolbar-row--table">
          <div class="cpb-toolbar-group" aria-label="Rows and columns">
            <button type="button" class="cpb-tool-btn" data-table-action="move-row-up" title="Move selected row up" disabled>↑ Row</button>
            <button type="button" class="cpb-tool-btn" data-table-action="move-row-down" title="Move selected row down" disabled>↓ Row</button>
            <button type="button" class="cpb-tool-btn" data-table-action="add-row" title="Add row at bottom" disabled>+ Row</button>
            <button type="button" class="cpb-tool-btn" data-table-action="del-row" title="Delete selected row" disabled>− Row</button>
            <button type="button" class="cpb-tool-btn" data-table-action="add-col" title="Add column at right" disabled>+ Col</button>
            <button type="button" class="cpb-tool-btn" data-table-action="del-col" title="Remove rightmost column" disabled>− Col</button>
          </div>
          <div class="cpb-toolbar-group" aria-label="Borders and formulas">
            <button type="button" class="cpb-tool-btn" data-table-action="border-thin" title="Thin table border" disabled>─</button>
            <button type="button" class="cpb-tool-btn" data-table-action="border-medium" title="Medium table border" disabled>━</button>
            <button type="button" class="cpb-tool-btn" data-table-action="border-thick" title="Thick table border" disabled>▬</button>
            <input type="color" class="cpb-tool-color" data-table-action="border-color" value="#94a3b8" title="Table border color" disabled>
            <button type="button" class="cpb-tool-btn" data-table-action="formula-sum" title="Insert SUM formula" disabled>SUM</button>
            <button type="button" class="cpb-tool-btn" data-table-action="formula-avg" title="Insert AVG formula" disabled>AVG</button>
            <button type="button" class="cpb-tool-btn" data-table-action="formula-custom" title="Insert custom formula" disabled>fx</button>
          </div>
          <div class="cpb-toolbar-group" aria-label="Cells">
            <button type="button" class="cpb-tool-btn" data-table-action="merge-cells-right" title="Merge selected cell horizontally" disabled>Merge H</button>
            <button type="button" class="cpb-tool-btn" data-table-action="unmerge-cells" title="Unmerge selected cell horizontally" disabled>Unmerge H</button>
            <button type="button" class="cpb-tool-btn" data-table-action="merge-cells-down" title="Merge selected cell vertically" disabled>Merge V</button>
            <button type="button" class="cpb-tool-btn" data-table-action="unmerge-cells-down" title="Unmerge selected cell vertically" disabled>Unmerge V</button>
            <label class="cpb-table-toolbar__color">Fill <input type="color" class="cpb-tool-color" data-table-action="cell-bg" value="#ffffff" title="Cell background" disabled></label>
            <button type="button" class="cpb-tool-btn" data-table-action="cell-bg-clear" title="Clear cell fill" disabled>Clear fill</button>
            <button type="button" class="cpb-tool-btn" data-table-action="copy-cells" title="Copy selected cell text" disabled>Copy cell</button>
            <button type="button" class="cpb-tool-btn" data-table-action="paste-cells" title="Paste copied cell text" disabled>Paste cell</button>
          </div>
        </div>
        </div>
        </div>
        <div class="cpb-toolbar-toc" id="cpbToolbarToc" hidden aria-hidden="true"></div>
        <div class="cpb-toolbar-lep" id="cpbToolbarLep" hidden aria-hidden="true"></div>
        <div class="cpb-toolbar-part0" id="cpbToolbarPart0" hidden aria-hidden="true"></div>
        <div class="cpb-toolbar-shared" id="cpbToolbarShared">
        <div class="cpb-toolbar-group">
          <button type="button" class="cpb-tool-btn" id="cpbZoomOut" title="Zoom out">−</button>
          <span class="cpb-zoom-label" id="cpbZoomLabel">100%</span>
          <button type="button" class="cpb-tool-btn" id="cpbZoomIn" title="Zoom in">+</button>
        </div>
        <div class="cpb-toolbar-group cpb-toolbar-group--view">
          <select id="cpbSyncSelect" class="cpb-tool-select cpb-tool-select--sync" title="Sync TOC, manual structure, or Highlight of Changes">
            <option value="" selected>⟳</option>
            <option value="toc">Sync TOC</option>
            <option value="structure">Sync manual structure</option>
            <option value="highlights">Sync changes</option>
          </select>
          <button type="button" class="cpb-tool-btn" id="cpbFullscreen" title="Full screen — hide app menu" aria-pressed="false">⤢</button>
        </div>
        </div>
        <span class="cpb-save-status" id="cpbSaveStatus">Loading…</span>
      </div>

      <div class="cpb-canvas-host" id="cpbCanvasHost">
        <div class="cpb-canvas-scroll" id="cpbCanvas">
          <p style="text-align:center;color:#64748b;font-family:system-ui,sans-serif;">Loading document…</p>
        </div>
        <div class="cpb-section-assembly" id="cpbSectionAssembly" role="status" aria-live="polite">
          <div class="cpb-section-assembly__card">
            <div class="cpb-section-assembly__spinner" aria-hidden="true"></div>
            <strong id="cpbSectionAssemblyLabel">Loading section…</strong>
            <div class="cpb-section-assembly__track" aria-hidden="true">
              <span id="cpbSectionAssemblyBar" style="width:8%"></span>
            </div>
            <span class="cpb-section-assembly__progress" id="cpbSectionAssemblyProgress">8%</span>
          </div>
        </div>
      </div>
      <style id="cpbPublicationCss"></style>
    </div>
  </div>
  <input type="file" id="cpbImageInput" accept="image/jpeg,image/png,image/webp" hidden>
  <input type="file" id="cpbHeaderLogoInput" accept="image/jpeg,image/png,image/webp" hidden>
  <input type="file" id="cpbCoverLogoInput" accept="image/jpeg,image/png,image/webp" hidden>
  <input type="file" id="cpbCoverImageInput" accept="image/jpeg,image/png,image/webp" hidden>
  <div class="cpb-definitions-import" id="cpbDefinitionsImport" hidden aria-hidden="true">
    <div class="cpb-definitions-import__dialog" role="dialog" aria-labelledby="cpbDefinitionsImportTitle">
      <h3 id="cpbDefinitionsImportTitle">Import definitions from Word</h3>
      <p class="cpb-definitions-import__help">Open your OM Word/PDF, select the full <strong>0.6 Definitions and Terms</strong> section (all pages), copy, and paste below. Existing saved definitions are kept when the term matches.</p>
      <textarea id="cpbDefinitionsImportText" class="cpb-definitions-import__textarea" rows="16" placeholder="Paste 0.6 Definitions and Terms here…"></textarea>
      <div class="cpb-definitions-import__actions">
        <button type="button" class="cpb-tool-btn" id="cpbDefinitionsImportCancel">Cancel</button>
        <button type="button" class="cpb-tool-btn cpb-definitions-import__submit" id="cpbDefinitionsImportSubmit">Import</button>
      </div>
    </div>
  </div>
</div>

<script src="/assets/controlled_book_editor.js?v=<?= h($jsVer) ?>"></script>
<?php

compliance_page_close();
cw_footer();
