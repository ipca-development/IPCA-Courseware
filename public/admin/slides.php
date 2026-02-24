<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$lessonId = (int)($_GET['lesson_id'] ?? 0);
$courseId = (int)($_GET['course_id'] ?? 0);

$courses = $pdo->query("
  SELECT c.id, c.title, p.program_key
  FROM courses c
  JOIN programs p ON p.id=c.program_id
  ORDER BY p.sort_order, c.sort_order, c.id
")->fetchAll(PDO::FETCH_ASSOC);

if ($lessonId > 0 && $courseId === 0) {
    $stmt = $pdo->prepare("SELECT course_id FROM lessons WHERE id=? LIMIT 1");
    $stmt->execute([$lessonId]);
    $courseId = (int)$stmt->fetchColumn();
}

$lessons = [];
if ($courseId > 0) {
    $stmt = $pdo->prepare("
      SELECT id, external_lesson_id, title, sort_order
      FROM lessons
      WHERE course_id=?
      ORDER BY sort_order, external_lesson_id
    ");
    $stmt->execute([$courseId]);
    $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $lessonId = (int)($_POST['lesson_id'] ?? $lessonId);

    if ($action === 'delete_slide') {
        $pdo->prepare("UPDATE slides SET is_deleted=1 WHERE id=?")->execute([(int)$_POST['slide_id']]);
        redirect('/admin/slides.php?course_id='.$courseId.'&lesson_id='.$lessonId);
    }
    if ($action === 'restore_slide') {
        $pdo->prepare("UPDATE slides SET is_deleted=0 WHERE id=?")->execute([(int)$_POST['slide_id']]);
        redirect('/admin/slides.php?course_id='.$courseId.'&lesson_id='.$lessonId);
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
      LIMIT 1
    ");
    $stmt->execute([$lessonId]);
    $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM slides WHERE lesson_id=? ORDER BY page_number");
    $stmt->execute([$lessonId]);
    $slides = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

cw_header('Slides');
?>

<style>
/* Both thumbs use the same 16:9 viewport */
.thumb-viewport{
  width: 420px;
  height: 236px;
  overflow: hidden;
  border-radius: 12px;
  background: #fff;
  border: 1px solid #eee;
  display:flex;
  align-items:center;
  justify-content:center;
}
.thumb-viewport img{
  width: 100%;
  height: 100%;
  object-fit: contain;
  display:block;
}

/* HTML stage: fixed 1600x900 internally, scaled down into viewport */
.thumb-stage{
  width:1600px;
  height:900px;
  transform: scale(0.2625); /* 420/1600 */
  transform-origin: top left;
}

/* Force IPCA canvas to fixed pixels INSIDE thumbnails only */
.thumb-stage .ipca-canvas{
  width:1600px !important;
  height:900px !important;
  aspect-ratio: auto !important;
}
</style>

<div class="card">
  <h2>Slides</h2>

  <form method="get" class="form-grid">
    <label>Course</label>
    <select name="course_id" onchange="this.form.submit()">
      <option value="0">— Select course —</option>
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= ($courseId === (int)$c['id']) ? 'selected' : '' ?>>
          <?= h($c['program_key']) ?> — <?= h($c['title']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label>Lesson</label>
    <select name="lesson_id">
      <option value="0">— Select lesson —</option>
      <?php foreach ($lessons as $l): ?>
        <option value="<?= (int)$l['id'] ?>" <?= ($lessonId === (int)$l['id']) ? 'selected' : '' ?>>
          <?= (int)$l['external_lesson_id'] ?> — <?= h($l['title']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <div></div>
    <button class="btn" type="submit">Load Slides</button>
  </form>

  <?php if ($lesson): ?>
    <p class="muted" style="margin-top:10px;">
      <?= h($lesson['course_title']) ?> → <?= h($lesson['title']) ?> (<?= (int)$lesson['external_lesson_id'] ?>)
    </p>
  <?php endif; ?>
</div>

<?php if ($lesson): ?>
<div class="card">
  <h2>Slide overview</h2>
  <p class="muted">Designer only. Double-click screenshot to open Designer.</p>

  <div class="cw-slides-grid">
    <?php foreach ($slides as $s): ?>
      <?php
        $isDeleted = ((int)$s['is_deleted'] === 1);
        $imgUrl = cdn_url($CDN_BASE, (string)$s['image_path']);
      ?>
      <div class="cw-slide-card <?= $isDeleted ? 'cw-deleted' : '' ?>">
        <div class="cw-slide-top">
          <div>
            <strong>Page <?= (int)$s['page_number'] ?></strong>
            <span class="muted">• <?= h($s['template_key']) ?></span>
          </div>

          <div class="cw-actions">
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
        </div>

        <div class="cw-slide-body" style="grid-template-columns: 420px 420px; gap:12px;">
          <!-- Screenshot thumb (fixed 420x236) -->
          <div class="cw-shot" ondblclick="location.href='/admin/slide_designer.php?slide_id=<?= (int)$s['id'] ?>'">
            <a target="_blank" href="<?= h($imgUrl) ?>">
              <div class="thumb-viewport">
                <img src="<?= h($imgUrl) ?>" alt="">
              </div>
            </a>
          </div>

          <!-- HTML thumb (fixed 420x236) -->
          <div class="cw-mini">
            <?php if (!empty($s['html_rendered'])): ?>
              <div class="thumb-viewport">
                <div class="thumb-stage">
                  <?= $s['html_rendered'] ?>
                </div>
              </div>
            <?php else: ?>
              <div class="thumb-viewport" style="display:flex;align-items:center;justify-content:center;">
                <div class="muted" style="padding:10px;text-align:center;">
                  No rendered HTML yet.<br>
                  Use “Save + Render HTML” in Designer.
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>

      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php cw_footer(); ?>