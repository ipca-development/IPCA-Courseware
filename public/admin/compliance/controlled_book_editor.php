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
    foreach (array('part_1', 'main_content') as $key) {
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
        <h2>Manual sections</h2>
      </div>
      <div class="cpb-tree-scroll" id="cpbSectionTree">
        <p style="padding:12px 16px;margin:0;font-size:12px;color:#94a3b8;">Loading outline…</p>
      </div>
      <div class="cpb-tree-actions">
        <span id="cpbAddSubsection" class="cpb-tree-add" role="button" tabindex="0" style="display:none;">+ Add subsection</span>
      </div>
    </aside>

    <div class="cpb-workspace">
      <div class="cpb-toolbar" id="cpbToolbar">
        <div class="cpb-toolbar-main" id="cpbToolbarMain">
        <div class="cpb-toolbar-group">
          <button type="button" class="cpb-tool-btn" id="cpbUndo" title="Undo (Ctrl+Z)">↶</button>
          <button type="button" class="cpb-tool-btn" id="cpbRedo" title="Redo (Ctrl+Shift+Z)">↷</button>
        </div>
        <div class="cpb-toolbar-group">
          <button type="button" class="cpb-tool-btn" data-cmd="bold" title="Bold"><strong>B</strong></button>
          <button type="button" class="cpb-tool-btn" data-cmd="italic" title="Italic"><em>I</em></button>
          <button type="button" class="cpb-tool-btn" data-cmd="underline" title="Underline"><u>U</u></button>
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
        </div>
        <div class="cpb-toolbar-group">
          <button type="button" class="cpb-tool-btn" id="cpbOutdent" title="Decrease indent (Shift+Tab)">⇤</button>
          <button type="button" class="cpb-tool-btn" id="cpbIndent" title="Increase indent (Tab)">⇥</button>
        </div>
        <div class="cpb-toolbar-group">
          <button type="button" class="cpb-tool-btn" id="cpbOpenStyleEditor" title="Book style editor">Styles</button>
          <button type="button" class="cpb-tool-btn" id="cpbOpenHeaderEditor" title="Page header editor">Header</button>
          <button type="button" class="cpb-tool-btn" data-add-block="paragraph" title="Add paragraph">¶</button>
          <button type="button" class="cpb-tool-btn" data-add-block="table" title="Add table">Table</button>
          <button type="button" class="cpb-tool-btn" id="cpbPickImage" title="Insert image">Image</button>
          <select id="cpbCalloutSelect" class="cpb-tool-select cpb-tool-select--callout" title="Insert Warning, Caution, Info…">
            <option value="" selected>⚑</option>
            <option value="warning">Warning</option>
            <option value="caution">Caution</option>
            <option value="info">Info</option>
            <option value="manage">Presets…</option>
          </select>
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
          <select id="cpbSyncSelect" class="cpb-tool-select cpb-tool-select--sync" title="Sync TOC or Highlight of Changes">
            <option value="" selected>⟳</option>
            <option value="toc">Sync TOC</option>
            <option value="highlights">Sync changes</option>
          </select>
          <button type="button" class="cpb-tool-btn" id="cpbFullscreen" title="Full screen — hide app menu" aria-pressed="false">⤢</button>
        </div>
        </div>
        <span class="cpb-save-status" id="cpbSaveStatus">Loading…</span>
      </div>

      <div class="cpb-canvas-scroll" id="cpbCanvas">
        <p style="text-align:center;color:#64748b;font-family:system-ui,sans-serif;">Loading document…</p>
      </div>
    </div>
  </div>
  <input type="file" id="cpbImageInput" accept="image/jpeg,image/png,image/webp" hidden>
  <input type="file" id="cpbHeaderLogoInput" accept="image/jpeg,image/png,image/webp" hidden>
  <input type="file" id="cpbCoverLogoInput" accept="image/jpeg,image/png,image/webp" hidden>
  <input type="file" id="cpbCoverImageInput" accept="image/jpeg,image/png,image/webp" hidden>
</div>

<script src="/assets/controlled_book_editor.js?v=<?= h($jsVer) ?>"></script>
<?php

compliance_page_close();
cw_footer();
