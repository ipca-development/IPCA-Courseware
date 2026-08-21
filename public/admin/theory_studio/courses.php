<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

$svc = new TheoryContentStudioService($pdo);
$programId = (int)($_GET['program_id'] ?? 0);
try {
    $program = $svc->getProgram($programId);
} catch (TheoryStudioException $e) {
    http_response_code(404);
    cw_header('Program not found');
    echo '<p>Program not found.</p>';
    cw_footer();
    exit;
}
$courses = $svc->listCourses($programId);
$protected = !empty($program['protected']);
$csrf = theory_studio_csrf_token();

cw_header($program['name'] . ' · Courses');
theory_studio_emit_assets();
echo '<meta name="theory-studio-csrf" content="' . h($csrf) . '">';

$actions = array();
if (!$protected) {
    $actions[] = array(
        'label' => 'Add Course',
        'modal' => 'ts-add-course',
        'variant' => 'primary',
    );
}

compliance_page_open(array(
    'overline' => 'Theory Training',
    'title' => (string)$program['name'],
    'description' => 'Revision ' . (string)$program['revision_number'] . ' · ' . count($courses) . ' courses',
    'actions' => $actions,
));

echo TheoryStudioUi::breadcrumb(array(
    array('label' => 'Programs', 'href' => '/admin/theory_studio/programs.php'),
    array('label' => (string)$program['name'], 'href' => null),
));
echo TheoryStudioUi::statusPills($program);
if ($protected) {
    echo TheoryStudioUi::liveBannerHtml();
}
?>
<div class="ts-page">
  <div class="ts-list" <?= $protected ? '' : 'data-ts-reorder="reorder_courses" data-parent-id="' . (int)$programId . '"' ?>>
    <?php foreach ($courses as $course): ?>
      <?php $cover = theory_studio_cover_url((string)($course['cover_image_path'] ?? '')); ?>
      <a class="ts-row <?= $protected ? 'is-readonly' : '' ?>" href="/admin/theory_studio/lessons.php?course_id=<?= (int)$course['id'] ?>" <?= $protected ? '' : 'draggable="true" data-id="' . (int)$course['id'] . '"' ?>>
        <?php if (!$protected): ?><span class="ts-drag" aria-hidden="true">⋮⋮</span><?php endif; ?>
        <div class="ts-thumb">
          <?php if ($cover !== ''): ?><img src="<?= h($cover) ?>" alt=""><?php endif; ?>
        </div>
        <div>
          <h3><?= h((string)$course['title']) ?></h3>
          <p class="ts-meta">
            <?= h((string)($course['revision'] ?? $program['revision_number'])) ?>
            · <?= (int)$course['lesson_count'] ?> lesson<?= (int)$course['lesson_count'] === 1 ? '' : 's' ?>
          </p>
        </div>
        <span class="app-btn app-btn-secondary">Open</span>
      </a>
    <?php endforeach; ?>
  </div>
  <?php if (!$courses): ?>
    <div class="ts-empty"><?= $protected ? 'This Live program has no courses to inspect.' : 'Add the first course to this Draft program.' ?></div>
  <?php endif; ?>
</div>

<?php if (!$protected): ?>
<dialog class="compliance-modal ts-modal" id="ts-add-course">
  <div class="compliance-modal__panel">
    <div class="compliance-modal__header">
      <h2 class="compliance-modal__title">Add Course</h2>
      <button type="button" class="app-btn app-btn-secondary" data-ts-modal-close>&times;</button>
    </div>
    <form class="ts-form" data-ts-form="create_course">
      <input type="hidden" name="program_id" value="<?= (int)$programId ?>">
      <label for="ts-course-title">Title</label>
      <input id="ts-course-title" name="title" required>
      <label for="ts-course-slug">Slug</label>
      <input id="ts-course-slug" name="slug" placeholder="auto-from-title">
      <label for="ts-course-rev">Revision</label>
      <input id="ts-course-rev" name="revision" value="<?= h((string)$program['revision_number']) ?>">
      <div class="ts-form-actions">
        <button type="button" class="app-btn app-btn-secondary" data-ts-modal-close>Cancel</button>
        <button type="submit" class="app-btn app-btn-primary">Add Course</button>
      </div>
    </form>
  </div>
</dialog>
<?php endif; ?>
<?php
compliance_page_close();
cw_footer();
