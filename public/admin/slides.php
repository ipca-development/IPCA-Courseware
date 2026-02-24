<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$lessonId = (int)($_GET['lesson_id'] ?? 0);

$courses = $pdo->query("
  SELECT c.id, c.title, p.program_key
  FROM courses c
  JOIN programs p ON p.id=c.program_id
  ORDER BY p.sort_order, c.sort_order, c.id
")->fetchAll();

$selectedCourseId = (int)($_GET['course_id'] ?? 0);
if ($selectedCourseId === 0 && $lessonId > 0) {
    $stmt = $pdo->prepare("SELECT course_id FROM lessons WHERE id=?");
    $stmt->execute([$lessonId]);
    $selectedCourseId = (int)$stmt->fetchColumn();
}

$lessons = [];
if ($selectedCourseId > 0) {
    $stmt = $pdo->prepare("
      SELECT id, external_lesson_id, title, sort_order
      FROM lessons
      WHERE course_id=?
      ORDER BY sort_order, external_lesson_id
    ");
    $stmt->execute([$selectedCourseId]);
    $lessons = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $lessonId = (int)($_POST['lesson_id'] ?? $lessonId);

    if ($action === 'delete_slide') {
        $pdo->prepare("UPDATE slides SET is_deleted=1 WHERE id=?")->execute([(int)$_POST['slide_id']]);
        redirect('/admin/slides.php?lesson_id=' . $lessonId);
    }

    if ($action === 'restore_slide') {
        $pdo->prepare("UPDATE slides SET is_deleted=0 WHERE id=?")->execute([(int)$_POST['slide_id']]);
        redirect('/admin/slides.php?lesson_id=' . $lessonId);
    }
}

$lesson = null;
$slides = [];
if ($lessonId > 0) {
    $stmt = $pdo->prepare("
      SELECT l.*, c.title AS course_title, p.program_key
      FROM lessons l
      JOIN courses c ON c.id=l.course_id
      JOIN programs p ON p.id=c.program_id
      WHERE l.id=?
    ");
    $stmt->execute([$lessonId]);
    $lesson = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT * FROM slides WHERE lesson_id=? ORDER BY page_number");
    $stmt->execute([$lessonId]);
    $slides = $stmt->fetchAll();
}

cw_header('Slides');
?>

<div class="card">
  <h2>Select lesson</h2>
  <form method="get" class="form-grid">
    <label>Course</label>
    <select name="course_id" onchange="this.form.submit()">
      <option value="0">— Select course —</option>
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= ($selectedCourseId===(int)$c['id'])?'selected':'' ?>>
          <?= h($c['program_key']) ?> — <?= h($c['title']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label>Lesson</label>
    <select name="lesson_id">
      <option value="0">— Select lesson —</option>
      <?php foreach ($lessons as $l): ?>
        <option value="<?= (int)$l['id'] ?>" <?= ($lessonId===(int)$l['id'])?'selected':'' ?>>
          <?= (int)$l['external_lesson_id'] ?> — <?= h($l['title']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <div></div>
    <button class="btn" type="submit">Load Slides</button>
  </form>
</div>

<?php if ($lesson): ?>
<div class="card">
  <h2><?= h($lesson['course_title']) ?> → <?= h($lesson['title']) ?> (<?= (int)$lesson['external_lesson_id'] ?>)</h2>

  <div class="cw-slides-grid" id="cwSlidesGrid">
    <?php foreach ($slides as $s): ?>
      <?php
        $isDeleted = ((int)$s['is_deleted'] === 1);
        $imgUrl = cdn_url($CDN_BASE, (string)$s['image_path']);
      ?>
      <div class="cw-slide-card <?= $isDeleted ? 'cw-deleted' : '' ?>">
        <div class="cw-slide-top">
          <div><strong>Page <?= (int)$s['page_number'] ?></strong> • <?= h($s['template_key']) ?></div>
          <div class="cw-actions">
            <a class="btn btn-sm" href="/admin/slide_edit.php?slide_id=<?= (int)$s['id'] ?>">Edit</a>
			  
			<div class="cw-actions">
  <a class="btn btn-sm" href="/admin/slide_edit.php?slide_id=<?= (int)$s['id'] ?>">Edit</a>

  <a class="btn btn-sm" href="/admin/slide_designer.php?slide_id=<?= (int)$s['id'] ?>">Designer</a>

  <?php if (!$isDeleted): ?>
    <form method="post" style="display:inline">
      <input type="hidden" name="action" value="delete_slide">
      <input type="hidden" name="slide_id" value="<?= (int)$s['id'] ?>">
      <input type="hidden" name="lesson_id" value="<?= (int)$lessonId ?>">
      <button class="btn btn-sm" type="submit">Delete</button>
    </form>
  <?php else: ?>
    <form method="post" style="display:inline">
      <input type="hidden" name="action" value="restore_slide">
      <input type="hidden" name="slide_id" value="<?= (int)$s['id'] ?>">
      <input type="hidden" name="lesson_id" value="<?= (int)$lessonId ?>">
      <button class="btn btn-sm" type="submit">Restore</button>
    </form>
  <?php endif; ?>
</div>  

            <?php if (!$isDeleted): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="delete_slide">
                <input type="hidden" name="slide_id" value="<?= (int)$s['id'] ?>">
                <input type="hidden" name="lesson_id" value="<?= (int)$lessonId ?>">
                <button class="btn btn-sm" type="submit">Delete</button>
              </form>
            <?php else: ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="restore_slide">
                <input type="hidden" name="slide_id" value="<?= (int)$s['id'] ?>">
                <input type="hidden" name="lesson_id" value="<?= (int)$lessonId ?>">
                <button class="btn btn-sm" type="submit">Restore</button>
              </form>
            <?php endif; ?>

          </div>
        </div>

        <div class="cw-slide-body">
          <div class="cw-shot">
            <a target="_blank" href="<?= h($imgUrl) ?>"><img src="<?= h($imgUrl) ?>" alt=""></a>
          </div>
          <div class="cw-mini">
            <?php if (!empty($s['html_rendered'])): ?>
              <div class="cw-mini-preview"><?= $s['html_rendered'] ?></div>
            <?php else: ?>
              <div class="cw-mini-preview muted">No HTML yet</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php cw_footer(); ?>