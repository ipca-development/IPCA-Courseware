<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

// programs for dropdown
$programs = $pdo->query("SELECT id, program_key, name FROM programs ORDER BY sort_order")->fetchAll();

// CREATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_course') {
    $programId = (int)($_POST['program_id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $order = (int)($_POST['sort_order'] ?? 0);
    $published = isset($_POST['is_published']) ? 1 : 0;

    if ($slug === '') $slug = slugify($title);

    $stmt = $pdo->prepare("INSERT INTO courses (program_id, title, slug, sort_order, is_published, revision) VALUES (?,?,?,?,?, '1.0')");
    $stmt->execute([$programId, $title, $slug, $order, $published]);
    redirect('/admin/courses.php');
}

// INLINE UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_course') {
    $id = (int)($_POST['id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $order = (int)($_POST['sort_order'] ?? 0);
    $published = isset($_POST['is_published']) ? 1 : 0;

    if ($slug === '') $slug = slugify($title);

    $stmt = $pdo->prepare("UPDATE courses SET title=?, slug=?, sort_order=?, is_published=? WHERE id=?");
    $stmt->execute([$title, $slug, $order, $published, $id]);
    redirect('/admin/courses.php');
}

// DELETE (optional – safe, will fail if lessons exist because of FK)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_course') {
    $id = (int)($_POST['id'] ?? 0);
    try {
        $stmt = $pdo->prepare("DELETE FROM courses WHERE id=?");
        $stmt->execute([$id]);
    } catch (Exception $e) {
        // ignore and show message
        $_SESSION['cw_flash'] = "Could not delete course (likely has lessons).";
    }
    redirect('/admin/courses.php');
}

// fetch courses
$courses = $pdo->query("
  SELECT c.*, p.program_key
  FROM courses c
  JOIN programs p ON p.id = c.program_id
  ORDER BY p.sort_order, c.sort_order, c.id
")->fetchAll();

$flash = $_SESSION['cw_flash'] ?? '';
unset($_SESSION['cw_flash']);

cw_header('Courses');
?>

<?php if ($flash): ?>
  <div class="card"><div class="alert"><?= h($flash) ?></div></div>
<?php endif; ?>

<div class="card">
  <h2>Create course</h2>
  <form method="post" class="form-grid">
    <input type="hidden" name="action" value="create_course">

    <label>Program</label>
    <select name="program_id" required>
      <?php foreach ($programs as $p): ?>
        <option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?> (<?= h($p['program_key']) ?>)</option>
      <?php endforeach; ?>
    </select>

    <label>Title</label>
    <input name="title" required>

    <label>Slug (optional)</label>
    <input name="slug" placeholder="auto from title">

    <label>Order</label>
    <input name="sort_order" type="number" value="0">

    <label>Published</label>
    <input name="is_published" type="checkbox">

    <div></div>
    <button class="btn" type="submit">Create</button>
  </form>
</div>

<div class="card">
  <h2>Edit courses (inline)</h2>
  <table>
    <tr>
      <th>ID</th><th>Program</th><th>Title</th><th>Slug</th><th>Order</th><th>Published</th><th>Save</th><th>Delete</th>
    </tr>

    <?php foreach ($courses as $c): ?>
      <tr>
        <form method="post">
          <input type="hidden" name="action" value="update_course">
          <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">

          <td><?= (int)$c['id'] ?></td>
          <td><?= h($c['program_key']) ?></td>

          <td><input name="title" value="<?= h($c['title']) ?>" style="width:260px"></td>
          <td><input name="slug" value="<?= h($c['slug']) ?>" style="width:200px"></td>
          <td><input name="sort_order" type="number" value="<?= (int)$c['sort_order'] ?>" style="width:80px"></td>

          <td style="text-align:center;">
            <input name="is_published" type="checkbox" <?= ((int)$c['is_published'] === 1) ? 'checked' : '' ?>>
          </td>

          <td><button class="btn btn-sm" type="submit">Save</button></td>
        </form>

        <td>
          <form method="post" onsubmit="return confirm('Delete course? This will fail if lessons exist.');">
            <input type="hidden" name="action" value="delete_course">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <button class="btn btn-sm" type="submit">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php cw_footer(); ?>