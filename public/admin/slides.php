<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$templateKeys = template_keys();
$lessonId = (int)($_GET['lesson_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_template') {
    $lessonId = (int)$_POST['lesson_id'];
    $tpl = $_POST['template_key'] ?? 'MEDIA_LEFT_TEXT_RIGHT';
    $from = (int)$_POST['from_page'];
    $to = (int)$_POST['to_page'];

    $stmt = $pdo->prepare("UPDATE slides SET template_key=? WHERE lesson_id=? AND page_number BETWEEN ? AND ?");
    $stmt->execute([$tpl, $lessonId, $from, $to]);
    redirect('/admin/slides.php?lesson_id=' . $lessonId);
}

$lesson = null;
$slides = [];
if ($lessonId > 0) {
    $stmt = $pdo->prepare("
      SELECT l.*, p.program_key
      FROM lessons l
      JOIN courses c ON c.id = l.course_id
      JOIN programs p ON p.id = c.program_id
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
  <form method="get" class="form-inline">
    <label>Lesson ID</label>
    <input name="lesson_id" type="number" value="<?= (int)$lessonId ?>">
    <button class="btn btn-sm" type="submit">Load</button>
  </form>
</div>

<?php if ($lesson): ?>
  <div class="card">
    <h2><?= h($lesson['title']) ?> (Lesson <?= (int)$lesson['external_lesson_id'] ?>)</h2>

    <form method="post" class="form-grid">
      <input type="hidden" name="action" value="bulk_template">
      <input type="hidden" name="lesson_id" value="<?= (int)$lessonId ?>">

      <label>Template</label>
      <select name="template_key">
        <?php foreach ($templateKeys as $k): ?>
          <option value="<?= h($k) ?>"><?= h($k) ?></option>
        <?php endforeach; ?>
      </select>

      <label>From page</label>
      <input name="from_page" type="number" value="1" min="1">

      <label>To page</label>
      <input name="to_page" type="number" value="<?= (int)$lesson['page_count'] ?>" min="1">

      <div></div>
      <button class="btn" type="submit">Apply</button>
    </form>
  </div>

  <div class="card">
    <h2>Slides</h2>
    <table>
      <tr><th>Page</th><th>Template</th><th>Image</th></tr>
      <?php foreach ($slides as $s): ?>
        <tr>
          <td><?= (int)$s['page_number'] ?></td>
          <td><?= h($s['template_key']) ?></td>
          <td>
            <a target="_blank" href="<?= h(cdn_url($CDN_BASE, $s['image_path'])) ?>">Preview</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endif; ?>

<?php cw_footer(); ?>