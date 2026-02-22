<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$templateKeys = template_keys();

$courses = $pdo->query("
  SELECT c.id, c.title, p.program_key
  FROM courses c
  JOIN programs p ON p.id = c.program_id
  ORDER BY p.sort_order, c.sort_order, c.id
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_lesson') {
    $courseId = (int)$_POST['course_id'];
    $extLessonId = (int)$_POST['external_lesson_id'];
    $title = trim($_POST['title'] ?? '');
    $pageCount = (int)$_POST['page_count'];
    $order = (int)$_POST['sort_order'];
    $defaultTpl = $_POST['default_template_key'] ?? 'MEDIA_LEFT_TEXT_RIGHT';

    $stmt = $pdo->prepare("INSERT INTO lessons (course_id, external_lesson_id, title, sort_order, page_count, default_template_key)
                           VALUES (?,?,?,?,?,?)");
    $stmt->execute([$courseId, $extLessonId, $title, $order, $pageCount, $defaultTpl]);
    redirect('/admin/lessons.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_slides') {
    $lessonId = (int)$_POST['lesson_id'];

    // Load lesson + program_key
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

    $programKey = $lesson['program_key'];
    $extLessonId = (int)$lesson['external_lesson_id'];
    $pageCount = (int)$lesson['page_count'];
    $tpl = $lesson['default_template_key'];

    $ins = $pdo->prepare("INSERT INTO slides (lesson_id, page_number, template_key, image_path)
                          VALUES (?,?,?,?)
                          ON DUPLICATE KEY UPDATE template_key=VALUES(template_key), image_path=VALUES(image_path)");

    for ($p = 1; $p <= $pageCount; $p++) {
        $imagePath = image_path_for($programKey, $extLessonId, $p);
        $ins->execute([$lessonId, $p, $tpl, $imagePath]);
    }

    redirect('/admin/slides.php?lesson_id=' . $lessonId);
}

$lessons = $pdo->query("
  SELECT l.*, c.title AS course_title, p.program_key
  FROM lessons l
  JOIN courses c ON c.id = l.course_id
  JOIN programs p ON p.id = c.program_id
  ORDER BY p.sort_order, c.sort_order, l.sort_order, l.id
")->fetchAll();

cw_header('Lessons');
?>
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
      <th>ID</th><th>Program</th><th>Course</th><th>Ext ID</th><th>Title</th><th>Pages</th><th>Generate</th>
    </tr>
    <?php foreach ($lessons as $l): ?>
      <tr>
        <td><?= (int)$l['id'] ?></td>
        <td><?= h($l['program_key']) ?></td>
        <td><?= h($l['course_title']) ?></td>
        <td><?= (int)$l['external_lesson_id'] ?></td>
        <td><?= h($l['title']) ?></td>
        <td><?= (int)$l['page_count'] ?></td>
        <td>
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="generate_slides">
            <input type="hidden" name="lesson_id" value="<?= (int)$l['id'] ?>">
            <button class="btn btn-sm" type="submit">Generate Slides</button>
          </form>
          <a class="btn btn-sm" href="/admin/slides.php?lesson_id=<?= (int)$l['id'] ?>">View Slides</a>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php cw_footer(); ?>