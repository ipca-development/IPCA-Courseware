<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$templateKeys = template_keys();

// Load courses for dropdown
$courses = $pdo->query("
  SELECT c.id, c.title, p.program_key
  FROM courses c
  JOIN programs p ON p.id = c.program_id
  ORDER BY p.sort_order, c.sort_order, c.id
")->fetchAll();

$editId = (int)($_GET['edit_id'] ?? 0);
$editingLesson = null;

if ($editId > 0) {
    $stmt = $pdo->prepare("
      SELECT l.*, c.title AS course_title, p.program_key
      FROM lessons l
      JOIN courses c ON c.id = l.course_id
      JOIN programs p ON p.id = c.program_id
      WHERE l.id = ?
    ");
    $stmt->execute([$editId]);
    $editingLesson = $stmt->fetch();
    if (!$editingLesson) {
        $editId = 0;
    }
}

// ------------------------------------------------------------
// CREATE LESSON
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_lesson') {
    $courseId    = (int)($_POST['course_id'] ?? 0);
    $extLessonId = (int)($_POST['external_lesson_id'] ?? 0);
    $title       = trim((string)($_POST['title'] ?? ''));
    $pageCount   = (int)($_POST['page_count'] ?? 0);
    $order       = (int)($_POST['sort_order'] ?? 0);
    $defaultTpl  = (string)($_POST['default_template_key'] ?? 'MEDIA_LEFT_TEXT_RIGHT');

    if ($courseId <= 0 || $extLessonId <= 0 || $title === '' || $pageCount <= 0) {
        http_response_code(400);
        exit('Missing required fields.');
    }

    $stmt = $pdo->prepare("
      INSERT INTO lessons (course_id, external_lesson_id, title, sort_order, page_count, default_template_key)
      VALUES (?,?,?,?,?,?)
    ");
    $stmt->execute([$courseId, $extLessonId, $title, $order, $pageCount, $defaultTpl]);

    redirect('/admin/lessons.php');
}

// ------------------------------------------------------------
// UPDATE LESSON
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_lesson') {
    $lessonId    = (int)($_POST['lesson_id'] ?? 0);
    $courseId    = (int)($_POST['course_id'] ?? 0);
    $extLessonId = (int)($_POST['external_lesson_id'] ?? 0);
    $title       = trim((string)($_POST['title'] ?? ''));
    $pageCount   = (int)($_POST['page_count'] ?? 0);
    $order       = (int)($_POST['sort_order'] ?? 0);
    $defaultTpl  = (string)($_POST['default_template_key'] ?? 'MEDIA_LEFT_TEXT_RIGHT');

    $syncSlides  = isset($_POST['sync_slides']) ? 1 : 0;
    $deleteExtra = isset($_POST['delete_extra_slides']) ? 1 : 0;

    if ($lessonId <= 0 || $courseId <= 0 || $extLessonId <= 0 || $title === '' || $pageCount <= 0) {
        http_response_code(400);
        exit('Missing required fields.');
    }

    // Fetch old lesson state (we need old page_count and program_key if syncing slides)
    $stmt = $pdo->prepare("
      SELECT l.*, p.program_key
      FROM lessons l
      JOIN courses c ON c.id = l.course_id
      JOIN programs p ON p.id = c.program_id
      WHERE l.id = ?
    ");
    $stmt->execute([$lessonId]);
    $old = $stmt->fetch();
    if (!$old) {
        http_response_code(404);
        exit('Lesson not found.');
    }

    // Update lesson row
    $stmt = $pdo->prepare("
      UPDATE lessons
      SET course_id=?, external_lesson_id=?, title=?, sort_order=?, page_count=?, default_template_key=?
      WHERE id=?
    ");
    $stmt->execute([$courseId, $extLessonId, $title, $order, $pageCount, $defaultTpl, $lessonId]);

    // Optional: sync slides
    if ($syncSlides) {
        // program_key may change if course_id changed; re-fetch program_key for new course_id
        $stmt = $pdo->prepare("
          SELECT p.program_key
          FROM courses c
          JOIN programs p ON p.id = c.program_id
          WHERE c.id=?
        ");
        $stmt->execute([$courseId]);
        $programKey = (string)$stmt->fetchColumn();
        if ($programKey === '') $programKey = (string)$old['program_key'];

        // Create/Upsert pages 1..pageCount
        $ins = $pdo->prepare("
          INSERT INTO slides (lesson_id, page_number, template_key, image_path)
          VALUES (?,?,?,?)
          ON DUPLICATE KEY UPDATE
            template_key=VALUES(template_key),
            image_path=VALUES(image_path)
        ");

        for ($p = 1; $p <= $pageCount; $p++) {
            $imagePath = image_path_for($programKey, $extLessonId, $p);
            $ins->execute([$lessonId, $p, $defaultTpl, $imagePath]);
        }

        // If reduced page count, optionally delete extra slides
        if ($deleteExtra) {
            $del = $pdo->prepare("DELETE FROM slides WHERE lesson_id=? AND page_number > ?");
            $del->execute([$lessonId, $pageCount]);
        }
    }

    redirect('/admin/lessons.php');
}

// ------------------------------------------------------------
// GENERATE SLIDES (existing button)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_slides') {
    $lessonId = (int)($_POST['lesson_id'] ?? 0);

    $stmt = $pdo->prepare("
      SELECT l.*, p.program_key
      FROM lessons l
      JOIN courses c ON c.id = l.course_id
      JOIN programs p ON p.id = c.program_id
      WHERE l.id = ?
    ");
    $stmt->execute([$lessonId]);
    $lesson = $stmt->fetch();
    if (!$lesson) exit('Lesson not found');

    $programKey  = (string)$lesson['program_key'];
    $extLessonId = (int)$lesson['external_lesson_id'];
    $pageCount   = (int)$lesson['page_count'];
    $tpl         = (string)$lesson['default_template_key'];

    $ins = $pdo->prepare("
      INSERT INTO slides (lesson_id, page_number, template_key, image_path)
      VALUES (?,?,?,?)
      ON DUPLICATE KEY UPDATE template_key=VALUES(template_key), image_path=VALUES(image_path)
    ");

    for ($p = 1; $p <= $pageCount; $p++) {
        $imagePath = image_path_for($programKey, $extLessonId, $p);
        $ins->execute([$lessonId, $p, $tpl, $imagePath]);
    }

    redirect('/admin/slides.php?lesson_id=' . $lessonId);
}

// Load lessons list
$lessons = $pdo->query("
  SELECT l.*, c.title AS course_title, p.program_key
  FROM lessons l
  JOIN courses c ON c.id = l.course_id
  JOIN programs p ON p.id = c.program_id
  ORDER BY p.sort_order, c.sort_order, l.sort_order, l.id
")->fetchAll();

cw_header('Lessons');
?>

<?php if ($editingLesson): ?>
  <div class="card">
    <h2>Edit lesson (ID <?= (int)$editingLesson['id'] ?>)</h2>
    <p class="muted">
      Program: <strong><?= h($editingLesson['program_key']) ?></strong> /
      Course: <strong><?= h($editingLesson['course_title']) ?></strong>
    </p>

    <form method="post" class="form-grid">
      <input type="hidden" name="action" value="update_lesson">
      <input type="hidden" name="lesson_id" value="<?= (int)$editingLesson['id'] ?>">

      <label>Course</label>
      <select name="course_id" required>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= ((int)$editingLesson['course_id'] === (int)$c['id']) ? 'selected' : '' ?>>
            <?= h($c['program_key']) ?> — <?= h($c['title']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label>External Lesson ID</label>
      <input name="external_lesson_id" type="number" required value="<?= (int)$editingLesson['external_lesson_id'] ?>">

      <label>Title</label>
      <input name="title" required value="<?= h($editingLesson['title']) ?>">

      <label>Page count</label>
      <input name="page_count" type="number" min="1" required value="<?= (int)$editingLesson['page_count'] ?>">

      <label>Order</label>
      <input name="sort_order" type="number" value="<?= (int)$editingLesson['sort_order'] ?>">

      <label>Default template</label>
      <select name="default_template_key">
        <?php foreach ($templateKeys as $k): ?>
          <option value="<?= h($k) ?>" <?= ($editingLesson['default_template_key'] === $k) ? 'selected' : '' ?>>
            <?= h($k) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label>Sync slides to page_count</label>
      <input type="checkbox" name="sync_slides" value="1">

      <label>Delete extra slides if reduced</label>
      <input type="checkbox" name="delete_extra_slides" value="1">

      <div></div>
      <div style="display:flex; gap:10px; align-items:center;">
        <button class="btn" type="submit">Save changes</button>
        <a class="btn btn-sm" href="/admin/lessons.php">Cancel</a>
      </div>

      <div></div>
      <p class="muted" style="grid-column: 1 / -1;">
        Tip: If you already generated slides and you change the page count, tick <strong>Sync slides</strong>.
        If you reduced the page count and you want to remove pages above the new count, also tick <strong>Delete extra slides</strong>.
      </p>
    </form>
  </div>
<?php endif; ?>


<div class="card">
  <h2>Create lesson</h2>
  <form method="post" class="form-grid">
    <input type="hidden" name="action" value="create_lesson">

    <label>Course</label>
    <select name="course_id" required>
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>"><?= h($c['program_key']) ?> — <?= h($c['title']) ?></option>
      <?php endforeach; ?>
    </select>

    <label>External Lesson ID</label>
    <input name="external_lesson_id" type="number" required>

    <label>Title</label>
    <input name="title" required>

    <label>Page count</label>
    <input name="page_count" type="number" value="1" min="1" required>

    <label>Order</label>
    <input name="sort_order" type="number" value="0">

    <label>Default template</label>
    <select name="default_template_key">
      <?php foreach ($templateKeys as $k): ?>
        <option value="<?= h($k) ?>"><?= h($k) ?></option>
      <?php endforeach; ?>
    </select>

    <div></div>
    <button class="btn" type="submit">Create</button>
  </form>
</div>

<div class="card">
  <h2>Existing lessons</h2>
  <table>
    <tr>
      <th>ID</th><th>Program</th><th>Course</th><th>Ext ID</th><th>Title</th><th>Pages</th><th>Actions</th>
    </tr>
    <?php foreach ($lessons as $l): ?>
      <tr>
        <td><?= (int)$l['id'] ?></td>
        <td><?= h($l['program_key']) ?></td>
        <td><?= h($l['course_title']) ?></td>
        <td><?= (int)$l['external_lesson_id'] ?></td>
        <td><?= h($l['title']) ?></td>
        <td><?= (int)$l['page_count'] ?></td>
        <td style="white-space:nowrap;">
          <a class="btn btn-sm" href="/admin/lessons.php?edit_id=<?= (int)$l['id'] ?>">Edit</a>

          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="generate_slides">
            <input type="hidden" name="lesson_id" value="<?= (int)$l['id'] ?>">
            <button class="btn btn-sm" type="submit">Generate Slides</button>
          </form>

          <a class="btn btn-sm" href="/admin/slides.php?lesson_id=<?= (int)$l['id'] ?>">Slides</a>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php cw_footer(); ?>
