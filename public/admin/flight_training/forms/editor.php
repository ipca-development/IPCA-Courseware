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

try {
    $user = cw_current_user($pdo);
    $initial = $templateId > 0 ? $service->loadEditor($templateId, (int)($user['id'] ?? 0)) : array();
} catch (Throwable $e) {
    $pageError = $e->getMessage();
}

$assetPaths = array(
    'book_css' => __DIR__ . '/../../../../public/assets/controlled_book_editor.css',
    'form_css' => __DIR__ . '/../../../../public/assets/controlled_form_editor.css',
    'form_js' => __DIR__ . '/../../../../public/assets/controlled_form_editor.js',
);

cw_header('Flight Training · Form Template Editor');
?>
<link rel="stylesheet" href="/assets/controlled_book_editor.css?v=<?= h((string)(is_file($assetPaths['book_css']) ? filemtime($assetPaths['book_css']) : time())) ?>">
<link rel="stylesheet" href="/assets/controlled_form_editor.css?v=<?= h((string)(is_file($assetPaths['form_css']) ? filemtime($assetPaths['form_css']) : time())) ?>">

  <?php if ($pageError !== ''): ?>
    <section class="cpb-form-editor-error"><?= h($pageError) ?></section>
  <?php elseif ($templateId <= 0): ?>
    <section class="cpb-form-editor-error">Missing template_id.</section>
  <?php else: ?>
    <div class="cpb-editor-root cpb-form-editor-root" id="flightFormEditorRoot"
         data-template-id="<?= (int)$templateId ?>">
      <div class="cpb-editor-shell">
        <aside class="cpb-tree-panel">
          <div class="cpb-tree-head">
            <h2>Form outline</h2>
            <a class="cpb-form-back-link" href="/admin/flight_training/forms/index.php">Form Manager</a>
          </div>
          <div class="cpb-tree-scroll" id="cffBlockTree">
            <p style="padding:12px 16px;margin:0;font-size:12px;color:#94a3b8;">Loading outline...</p>
          </div>
          <div class="cpb-tree-actions">
            <span class="cpb-tree-add" id="cffAddParagraph" role="button" tabindex="0">+ Add paragraph</span>
          </div>
        </aside>

        <div class="cpb-workspace">
          <div class="cpb-toolbar" id="cffToolbar">
            <div class="cpb-toolbar-main" id="cffToolbarMain">
              <div class="cpb-toolbar-group">
                <button type="button" class="cpb-tool-btn" data-add-block="heading" title="Add heading">Heading</button>
                <button type="button" class="cpb-tool-btn" data-add-block="paragraph" title="Add text block">Text</button>
                <button type="button" class="cpb-tool-btn" data-add-block="table" title="Add table">Table</button>
              </div>
              <div class="cpb-toolbar-group">
                <button type="button" class="cpb-tool-btn" data-form-tool="field" title="Insert input field">Field</button>
                <button type="button" class="cpb-tool-btn" data-form-tool="checkbox" title="Insert checkbox field">Checkbox</button>
                <button type="button" class="cpb-tool-btn" data-form-tool="date" title="Insert date field">Date Field</button>
                <button type="button" class="cpb-tool-btn" data-form-tool="signature" title="Insert signature field">Signature</button>
                <button type="button" class="cpb-tool-btn" data-form-tool="initial" title="Insert initial field">Initial</button>
                <button type="button" class="cpb-tool-btn" id="cffVariablePickerBtn" title="Insert variable">Variable</button>
              </div>
              <div class="cpb-toolbar-group">
                <button type="button" class="cpb-tool-btn" id="cffFieldSettingsBtn" title="Field settings" disabled>Field Settings</button>
                <button type="button" class="cpb-tool-btn" id="cffSaveBtn" title="Save template">Save</button>
              </div>
            </div>
            <div class="cpb-toolbar-shared" id="cffToolbarShared">
              <div class="cpb-toolbar-group">
                <button type="button" class="cpb-tool-btn" id="cffZoomOut" title="Zoom out">-</button>
                <span class="cpb-zoom-label" id="cffZoomLabel">100%</span>
                <button type="button" class="cpb-tool-btn" id="cffZoomIn" title="Zoom in">+</button>
              </div>
            </div>
            <span class="cpb-save-status" id="cffSaveStatus">Loading...</span>
          </div>

          <div class="cpb-canvas-scroll" id="cffCanvas">
            <p style="text-align:center;color:#64748b;font-family:system-ui,sans-serif;">Loading form template...</p>
          </div>
        </div>
      </div>

      <div class="cpb-form-modal" id="cffFieldModal" hidden aria-hidden="true">
        <div class="cpb-form-modal__dialog" role="dialog" aria-labelledby="cffFieldModalTitle">
          <h3 id="cffFieldModalTitle">Field Settings</h3>
          <form id="cffFieldForm" class="cpb-form-modal__form">
            <label><span>Field key</span><input type="text" name="field_key" required></label>
            <label><span>Label</span><input type="text" name="label" required></label>
            <label><span>Field type</span><select name="field_type">
              <option value="text">Text</option>
              <option value="textarea">Textarea</option>
              <option value="checkbox">Checkbox</option>
              <option value="date">Date</option>
              <option value="signature">Signature</option>
              <option value="initial">Initial</option>
            </select></label>
            <label><span>Assigned role</span><select name="assigned_role">
              <option value="admin">Admin</option>
              <option value="instructor">Instructor</option>
              <option value="student">Student</option>
              <option value="other_instructor">Other Instructor</option>
              <option value="examiner">Examiner / External Party</option>
              <option value="external_party">External Party</option>
            </select></label>
            <label><span>Variable binding</span><input type="text" name="variable_key" placeholder="student.full_name"></label>
            <label class="cpb-form-check"><input type="checkbox" name="required"> Required field</label>
            <div class="cpb-form-modal__actions">
              <button type="button" class="cpb-tool-btn" data-modal-close>Cancel</button>
              <button type="submit" class="cpb-tool-btn cpb-form-primary">Apply</button>
            </div>
          </form>
        </div>
      </div>

      <div class="cpb-form-modal" id="cffVariableModal" hidden aria-hidden="true">
        <div class="cpb-form-modal__dialog cpb-form-modal__dialog--wide" role="dialog" aria-labelledby="cffVariableModalTitle">
          <h3 id="cffVariableModalTitle">Insert Variable</h3>
          <div class="cpb-form-variable-grid" id="cffVariableList"></div>
          <div class="cpb-form-modal__actions">
            <button type="button" class="cpb-tool-btn" data-modal-close>Close</button>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

<?php if ($pageError === '' && $templateId > 0): ?>
<script>
window.flightFormInitialData = <?= json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/controlled_form_editor.js?v=<?= h((string)(is_file($assetPaths['form_js']) ? filemtime($assetPaths['form_js']) : time())) ?>"></script>
<?php endif; ?>

<?php cw_footer(); ?>
