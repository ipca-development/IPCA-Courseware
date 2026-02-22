<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$programs = $pdo->query("SELECT id, program_key, name FROM programs ORDER BY sort_order")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $programId = (int)($_POST['program_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $order = (int)($_POST['sort_order'] ?? 0);
    $published = isset($_POST['is_published']) ? 1 : 0;

    if ($slug === '') $slug = slugify($title);

    $stmt = $pdo->prepare("INSERT INTO courses (program_id, title, slug, sort_order, is_published) VALUES (?,?,?,?,?)");
    $stmt->execute([$programId, $title, $slug, $order, $published]);
    redirect('/admin/courses.php');
}

$courses = $pdo->query("
  SELECT c.*, p.program_key
  FROM courses c
  JOIN programs p ON p.id = c.program_id
  ORDER BY p.sort_order, c.sort_order, c.id
")->fetchAll();

cw_header('Courses');
?>
<div class="card">
  <h2>Create course</h2>
  <form method="post" class="form-grid">
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
  <h2>Existing courses</h2>
  <table>
    <tr><th>ID</th><th>Program</th><th>Title</th><th>Slug</th><th>Order</th><th>Published</th></tr>
    <?php foreach ($courses as $c): ?>
      <tr>
        <td><?= (int)$c['id'] ?></td>
        <td><?= h($c['program_key']) ?></td>
        <td><?= h($c['title']) ?></td>
        <td><?= h($c['slug']) ?></td>
        <td><?= (int)$c['sort_order'] ?></td>
        <td><?= (int)$c['is_published'] ? 'Yes' : 'No' ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php cw_footer(); ?>