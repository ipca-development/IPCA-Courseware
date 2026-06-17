<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../src/bootstrap.php';
require_once __DIR__ . '/../../../../src/layout.php';
require_once __DIR__ . '/../../../../src/flight_training/FormTemplateEditorService.php';

cw_require_admin();

$templateId = (int)($_GET['template_id'] ?? 0);
$service = new FormTemplateEditorService($pdo);
$pageError = '';
$initial = array();
$versionId = 0;
$sectionId = 0;

try {
    $user = cw_current_user($pdo);
    $initial = $templateId > 0 ? $service->loadEditor($templateId, (int)($user['id'] ?? 0)) : array();
    $versionId = (int)($initial['version']['id'] ?? 0);
    $sectionId = 1;
} catch (Throwable $e) {
    $pageError = $e->getMessage();
}

$assetPaths = array(
    'book_css' => __DIR__ . '/../../../../public/assets/controlled_book_editor.css',
    'book_js' => __DIR__ . '/../../../../public/assets/controlled_book_editor.js',
);

cw_header('Flight Training · Form Template Editor');
?>
<link rel="stylesheet" href="/assets/controlled_book_editor.css?v=<?= h((string)(is_file($assetPaths['book_css']) ? filemtime($assetPaths['book_css']) : time())) ?>">

  <?php if ($pageError !== ''): ?>
    <section class="cpb-form-editor-error"><?= h($pageError) ?></section>
  <?php elseif ($templateId <= 0): ?>
    <section class="cpb-form-editor-error">Missing template_id.</section>
  <?php else: ?>
    <div class="cpb-editor-root cpb-form-editor-root" id="cpbEditorRoot"
         data-template-id="<?= (int)$templateId ?>"
         data-version-id="<?= (int)$versionId ?>"
         data-section-id="<?= (int)$sectionId ?>"
         data-document-type="form"
         data-api-base="/admin/api/form_template_editor_api.php">
      <div class="cpb-editor-shell">
        <aside class="cpb-tree-panel">
          <div class="cpb-tree-head">
            <h2>Form sections</h2>
            <button type="button" id="cpbTreeToggleAll" class="cpb-tree-toggle-all" aria-pressed="false" title="Expand or collapse all sections">Expand all</button>
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
              <button type="button" class="cpb-tool-btn" id="cpbOpenStyleEditor" title="Form Style Editor">Styles</button>
              <button type="button" class="cpb-tool-btn" id="cpbOpenHeaderEditor" title="Page header editor">Header</button>
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
            <div class="cpb-form-toolbar-row" aria-label="Form editor tools">
              <div class="cpb-toolbar-group cpb-form-toolbar-group">
                <span class="cpb-form-toolbar-label">Form tools</span>
                <button type="button" class="cpb-tool-btn" data-form-tool="field" title="Insert text field">Text Field</button>
                <button type="button" class="cpb-tool-btn" data-form-tool="checkbox" title="Insert checkbox">Checkbox</button>
                <button type="button" class="cpb-tool-btn" data-form-tool="date" title="Insert date field">Date Field</button>
                <button type="button" class="cpb-tool-btn" data-form-tool="signature" title="Insert signature">Signature</button>
                <button type="button" class="cpb-tool-btn" data-form-tool="initial" title="Insert initial">Initial</button>
                <select id="cpbFormVariableSelect" class="cpb-tool-select cpb-tool-select--form-variable" title="Insert variable">
                  <option value="" selected>Variable</option>
                  <option value="student.full_name">Student full name</option>
                  <option value="student.phone">Student phone</option>
                  <option value="student.email">Student email</option>
                  <option value="instructor.full_name">Instructor full name</option>
                  <option value="instructor.phone">Instructor phone</option>
                  <option value="instructor.email">Instructor email</option>
                  <option value="course.name">Course name</option>
                  <option value="theory.completion">Theory completion</option>
                  <option value="knowledge_test.score">Knowledge test score</option>
                  <option value="knowledge_test.deficient_codes">Knowledge test deficient codes</option>
                </select>
                <button type="button" class="cpb-tool-btn" id="cpbFormFieldSettings" title="Field settings">Field Settings</button>
              </div>
            </div>
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
  <?php endif; ?>

<?php if ($pageError === '' && $templateId > 0): ?>
<script>
window.flightFormInitialData = <?= json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/controlled_book_editor.js?v=<?= h((string)(is_file($assetPaths['book_js']) ? filemtime($assetPaths['book_js']) : time())) ?>"></script>
<?php endif; ?>

<?php cw_footer(); ?>
