<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

$svc = new TheoryContentStudioService($pdo);
$statusSvc = new TheoryLessonEnhancementStatusService($pdo);
$courseId = (int)($_GET['course_id'] ?? 0);
try {
    $course = $svc->requireCourse($courseId);
    $program = $svc->getProgram((int)$course['program_id']);
} catch (TheoryStudioException $e) {
    http_response_code(404);
    cw_header('Course not found');
    echo '<p>Course not found.</p>';
    cw_footer();
    exit;
}
$lessons = $svc->listLessons($courseId);
$chips = $statusSvc->forLessons(array_map(static fn(array $row): int => (int)$row['id'], $lessons));
$protected = !empty($program['protected']);
$csrf = theory_studio_csrf_token();

cw_header((string)$course['title'] . ' · Lessons');
theory_studio_emit_assets();
echo '<meta name="theory-studio-csrf" content="' . h($csrf) . '">';

$actions = array();
if (!$protected) {
    $actions[] = array(
        'label' => 'Add Lesson',
        'modal' => 'ts-add-lesson',
        'variant' => 'primary',
    );
}

compliance_page_open(array(
    'overline' => 'Theory Training',
    'title' => (string)$course['title'],
    'description' => (string)$program['name'] . ' · ' . count($lessons) . ' lessons',
    'actions' => $actions,
));

echo TheoryStudioUi::breadcrumb(array(
    array('label' => 'Programs', 'href' => '/admin/theory_studio/programs.php'),
    array('label' => (string)$program['name'], 'href' => '/admin/theory_studio/courses.php?program_id=' . (int)$program['id']),
    array('label' => (string)$course['title'], 'href' => null),
));
echo TheoryStudioUi::statusPills($program);
if ($protected) {
    echo TheoryStudioUi::liveBannerHtml();
}
?>
<div class="ts-page">
  <div class="ts-list" <?= $protected ? '' : 'data-ts-reorder="reorder_lessons" data-parent-id="' . (int)$courseId . '"' ?>>
    <?php $n = 0; foreach ($lessons as $lesson): $n++; $status = $chips[(int)$lesson['id']] ?? array('chips' => array()); ?>
      <a class="ts-row <?= $protected ? 'is-readonly' : '' ?>" href="/admin/theory_studio/lesson_editor.php?lesson_id=<?= (int)$lesson['id'] ?>" <?= $protected ? '' : 'draggable="true" data-id="' . (int)$lesson['id'] . '"' ?>>
        <?php if (!$protected): ?><span class="ts-drag" aria-hidden="true">⋮⋮</span><?php endif; ?>
        <div class="ts-thumb" style="display:flex;align-items:center;justify-content:center;font-weight:800;color:#1e3c72;"><?= $n ?></div>
        <div>
          <h3><?= h((string)$lesson['title']) ?></h3>
          <p class="ts-meta"><?= (int)$lesson['slide_count'] ?> slide<?= (int)$lesson['slide_count'] === 1 ? '' : 's' ?></p>
          <div class="ts-chips">
            <?php foreach (($status['chips'] ?? array()) as $chip): ?>
              <span class="<?= h(TheoryStudioUi::chipClass((string)$chip['tone'])) ?>"><?= h((string)$chip['display']) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
        <span class="app-btn app-btn-secondary"><?= $protected ? 'Inspect' : 'Edit Lesson' ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<?php if (!$protected): ?>
<dialog class="compliance-modal ts-modal" id="ts-add-lesson">
  <div class="compliance-modal__panel">
    <div class="compliance-modal__header">
      <h2 class="compliance-modal__title">Add Lesson</h2>
      <button type="button" class="app-btn app-btn-secondary" data-ts-modal-close>&times;</button>
    </div>
    <form class="ts-form" data-ts-form="create_lessons">
      <input type="hidden" name="course_id" value="<?= (int)$courseId ?>">
      <label for="ts-lesson-title">Title</label>
      <input id="ts-lesson-title" name="title">
      <label for="ts-lesson-titles">Or paste multiple titles (one per line)</label>
      <textarea id="ts-lesson-titles" name="titles" rows="8"></textarea>
      <div class="ts-form-actions">
        <button type="button" class="app-btn app-btn-secondary" data-ts-modal-close>Cancel</button>
        <button type="submit" class="app-btn app-btn-primary">Add Lesson</button>
      </div>
    </form>
  </div>
</dialog>
<?php endif; ?>
<?php
compliance_page_close();
cw_footer();
