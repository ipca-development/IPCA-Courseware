<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

$svc = new TheoryContentStudioService($pdo);
$lessonId = (int)($_GET['lesson_id'] ?? 0);
try {
    $lesson = $svc->getLesson($lessonId);
} catch (TheoryStudioException $e) {
    http_response_code(404);
    cw_header('Lesson not found');
    echo '<p>Lesson not found.</p>';
    cw_footer();
    exit;
}
$slides = $lesson['slides'] ?? array();
$protected = !empty($lesson['protected']);
$activeId = (int)($_GET['slide_id'] ?? 0);
$active = null;
foreach ($slides as $slide) {
    if ($activeId <= 0 || (int)$slide['id'] === $activeId) {
        $active = $slide;
        $activeId = (int)$slide['id'];
        break;
    }
}
$csrf = theory_studio_csrf_token();

cw_header((string)$lesson['title'] . ' · Lesson Editor');
theory_studio_emit_assets();
echo '<meta name="theory-studio-csrf" content="' . h($csrf) . '">';

compliance_page_open(array(
    'overline' => 'Lesson Editor',
    'title' => (string)$lesson['title'],
    'description' => (string)$lesson['program_name'] . ' → ' . (string)$lesson['course_title'] . ' · ' . count($slides) . ' slides',
));

echo TheoryStudioUi::breadcrumb(array(
    array('label' => 'Programs', 'href' => '/admin/theory_studio/programs.php'),
    array('label' => (string)$lesson['program_name'], 'href' => '/admin/theory_studio/courses.php?program_id=' . (int)$lesson['program_id']),
    array('label' => (string)$lesson['course_title'], 'href' => '/admin/theory_studio/lessons.php?course_id=' . (int)$lesson['course_id']),
    array('label' => (string)$lesson['title'], 'href' => null),
));
if ($protected) {
    echo TheoryStudioUi::liveBannerHtml();
}
?>
<div class="ts-page">
  <div class="ts-editor">
    <aside class="ts-rail" aria-label="Slides">
      <?php foreach ($slides as $slide): ?>
        <?php
          $thumb = theory_studio_cover_url((string)($slide['image_path'] ?? ''));
          $href = '/admin/theory_studio/lesson_editor.php?lesson_id=' . (int)$lessonId . '&slide_id=' . (int)$slide['id'];
        ?>
        <a class="ts-rail-item <?= (int)$slide['id'] === $activeId ? 'is-active' : '' ?>" href="<?= h($href) ?>">
          <?php if ($thumb !== ''): ?>
            <img src="<?= h($thumb) ?>" alt="Slide <?= (int)$slide['page_number'] ?>">
          <?php else: ?>
            <span class="ts-rail-placeholder"></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
      <?php if (!$slides): ?>
        <p class="ts-meta">No slides yet.</p>
      <?php endif; ?>
      <?php if ($protected): ?>
        <p class="ts-disabled-note">Live screenshot lessons are inspect-only in Studio. Overlay edits remain on the legacy Slides page.</p>
      <?php else: ?>
        <button type="button" class="app-btn app-btn-secondary" disabled>Add Slide</button>
        <button type="button" class="app-btn app-btn-secondary" disabled>Import Slides</button>
        <p class="ts-disabled-note">Structured Slide Editor coming in Phase 2.</p>
      <?php endif; ?>
    </aside>
    <section class="ts-workspace">
      <?php if ($protected && $active && trim((string)($active['image_path'] ?? '')) !== ''): ?>
        <div class="ts-slide-viewport" aria-label="Slide <?= (int)$active['page_number'] ?> preview">
          <div class="ts-slide-stage">
            <img
              class="ts-slide-content"
              src="<?= h(theory_studio_cover_url((string)$active['image_path'])) ?>"
              alt="Slide <?= (int)$active['page_number'] ?>"
            >
            <img class="ts-slide-header" src="/assets/overlay/header.png" alt="">
            <img class="ts-slide-footer" src="/assets/overlay/footer.png" alt="">
          </div>
        </div>
      <?php elseif ($protected): ?>
        <div class="ts-workspace-copy">This Live lesson has no screenshot for the selected slide.</div>
      <?php else: ?>
        <div class="ts-workspace-copy">
          <p>New Studio lessons are authored as text, images, and video. Structured fields will be canonical. Screenshot placeholders are not inserted into the production slides table.</p>
          <p class="ts-disabled-note">Structured Slide Editor coming in Phase 2.</p>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php
compliance_page_close();
cw_footer();
