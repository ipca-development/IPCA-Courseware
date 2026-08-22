<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

$studio = new TheoryContentStudioService($pdo);
$structured = new TheoryStructuredSlideService($pdo);
$programId = (int)($_GET['program_id'] ?? 0);
$programs = array_values(array_filter(
    $studio->listPrograms(),
    static fn(array $program): bool => empty($program['protected'])
));
$templates = $structured->listTemplates($programId > 0 ? $programId : null);
$csrf = theory_studio_csrf_token();

cw_header('Theory Templates');
theory_studio_emit_assets();
theory_structured_editor_emit_assets();
echo '<meta name="theory-studio-csrf" content="' . h($csrf) . '">';

compliance_page_open(array(
    'overline' => 'Theory Training',
    'title' => 'Template Editor',
    'description' => 'Version-safe 1600×900 geometry and semantic placeholders for native structured Slides.',
));
?>
<div class="ts-page">
  <?php if ($programs): ?>
  <form class="ts-inline-form" data-ts-form="create_template">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <label>Draft Program
      <select name="program_id" required>
        <?php foreach ($programs as $program): ?>
          <option value="<?= (int)$program['id'] ?>"<?= $programId === (int)$program['id'] ? ' selected' : '' ?>>
            <?= h((string)$program['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Template key <input name="template_key" required placeholder="LESSON_TITLE_MEDIA"></label>
    <label>Name <input name="name" required placeholder="Lesson title with media"></label>
    <button type="submit" class="app-btn app-btn-primary">Create Template</button>
    <span class="ts-form-status" data-form-status></span>
  </form>
  <?php else: ?>
    <p class="ts-disabled-note">Create a Studio Draft Program before creating a custom Template.</p>
  <?php endif; ?>

  <div class="tse-template-grid">
    <?php foreach ($templates as $template): ?>
      <?php
        $owner = (int)($template['owning_program_id'] ?? 0);
        $href = '/admin/theory_studio/template_editor.php?template_id=' . (int)$template['id'];
        if ($owner > 0) {
            $href .= '&program_id=' . $owner;
        }
      ?>
      <article class="tse-template-card">
        <h3><?= h((string)$template['name']) ?></h3>
        <p class="ts-meta">
          <?= h((string)$template['template_key']) ?> ·
          <?= !empty($template['is_system']) ? 'System' : 'Draft Program' ?> ·
          Active version <?= (int)($template['active_version_id'] ?? 0) ?>
        </p>
        <a class="app-btn app-btn-secondary" href="<?= h($href) ?>">
          <?= !empty($template['is_system']) ? 'Inspect' : 'Edit layout' ?>
        </a>
      </article>
    <?php endforeach; ?>
  </div>
</div>
<?php
compliance_page_close();
cw_footer();
