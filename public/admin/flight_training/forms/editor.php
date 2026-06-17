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
    'core_css' => __DIR__ . '/../../../../public/assets/structured_document_core.css',
    'form_css' => __DIR__ . '/../../../../public/assets/flight_form_editor.css',
    'core_js' => __DIR__ . '/../../../../public/assets/structured_document_core.js',
    'form_js' => __DIR__ . '/../../../../public/assets/flight_form_editor.js',
);

cw_header('Flight Training · Form Template Editor');
?>
<link rel="stylesheet" href="/assets/structured_document_core.css?v=<?= h((string)(is_file($assetPaths['core_css']) ? filemtime($assetPaths['core_css']) : time())) ?>">
<link rel="stylesheet" href="/assets/flight_form_editor.css?v=<?= h((string)(is_file($assetPaths['form_css']) ? filemtime($assetPaths['form_css']) : time())) ?>">

<div class="ffed-page" id="flightFormEditorRoot" data-template-id="<?= (int)$templateId ?>">
  <header class="ffed-hero">
    <div>
      <p class="ffed-kicker">Admin · Flight Training · Forms</p>
      <h1 class="ffed-title">Form Template Editor</h1>
      <p class="ffed-subtitle">Structured editor foundation for reusable checkride and training forms.</p>
    </div>
    <a class="ffed-btn ffed-btn--secondary" href="/admin/flight_training/forms/index.php">Back to Form Manager</a>
  </header>

  <?php if ($pageError !== ''): ?>
    <div class="ffed-error"><?= h($pageError) ?></div>
  <?php elseif ($templateId <= 0): ?>
    <div class="ffed-error">Missing template_id.</div>
  <?php else: ?>
    <div class="sdoc-toolbar">
      <button type="button" class="sdoc-tool-btn" data-ffed-add="heading">Heading</button>
      <button type="button" class="sdoc-tool-btn" data-ffed-add="paragraph">Text</button>
      <button type="button" class="sdoc-tool-btn" data-ffed-add="table">Table</button>
      <button type="button" class="sdoc-tool-btn" data-ffed-add="checkbox">Checkbox</button>
      <button type="button" class="sdoc-tool-btn" data-ffed-add="field">Input</button>
      <button type="button" class="sdoc-tool-btn" data-ffed-add="date">Date</button>
      <button type="button" class="sdoc-tool-btn" id="ffedSave">Save</button>
      <span class="sdoc-save-status" id="ffedSaveStatus">Loading...</span>
    </div>

    <div class="sdoc-editor-shell">
      <aside class="sdoc-panel">
        <div class="sdoc-panel-head">
          <h2 class="sdoc-panel-title">Template</h2>
        </div>
        <div class="sdoc-panel-body">
          <dl class="ffed-meta" id="ffedTemplateMeta"></dl>
        </div>
        <div class="sdoc-panel-head">
          <h2 class="sdoc-panel-title">Fields</h2>
        </div>
        <div class="sdoc-panel-body">
          <div class="ffed-field-list" id="ffedFieldList"></div>
        </div>
      </aside>

      <main class="sdoc-canvas-scroll" id="ffedCanvas">
        <p class="ffed-loading">Loading editor...</p>
      </main>

      <aside class="sdoc-panel">
        <div class="sdoc-panel-head">
          <h2 class="sdoc-panel-title">Selected Field</h2>
        </div>
        <div class="sdoc-panel-body">
          <form class="ffed-field-settings" id="ffedFieldSettings">
            <label><span>Field key</span><input type="text" name="field_key"></label>
            <label><span>Label</span><input type="text" name="label"></label>
            <label><span>Type</span><select name="field_type">
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
            <label><span>Variable</span><input type="text" name="variable_key" placeholder="student.full_name"></label>
            <label class="ffed-check"><input type="checkbox" name="required"> Required</label>
            <button type="submit" class="ffed-btn ffed-btn--primary">Apply Field Settings</button>
          </form>
        </div>

        <div class="sdoc-panel-head">
          <h2 class="sdoc-panel-title">Variables</h2>
        </div>
        <div class="sdoc-panel-body">
          <div class="ffed-variable-list" id="ffedVariableList"></div>
        </div>
      </aside>
    </div>
  <?php endif; ?>
</div>

<?php if ($pageError === '' && $templateId > 0): ?>
<script>
window.flightFormInitialData = <?= json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/structured_document_core.js?v=<?= h((string)(is_file($assetPaths['core_js']) ? filemtime($assetPaths['core_js']) : time())) ?>"></script>
<script src="/assets/flight_form_editor.js?v=<?= h((string)(is_file($assetPaths['form_js']) ? filemtime($assetPaths['form_js']) : time())) ?>"></script>
<?php endif; ?>

<?php cw_footer(); ?>
