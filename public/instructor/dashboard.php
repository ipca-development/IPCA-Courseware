<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$msg = '';

$courses = $pdo->query("
  SELECT c.id, c.title, p.program_key
  FROM courses c JOIN programs p ON p.id=c.program_id
  ORDER BY p.sort_order, c.sort_order, c.id
")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $courseId = (int)($_POST['course_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $start = trim((string)($_POST['start_date'] ?? ''));
    $end = trim((string)($_POST['end_date'] ?? ''));

    if ($courseId <= 0 || $name === '' || $start === '' || $end === '') {
        $msg = 'Missing fields.';
    } else {
        $stmt = $pdo->prepare("INSERT INTO cohorts (course_id,name,start_date,end_date,timezone) VALUES (?,?,?,?, 'UTC')");
        $stmt->execute([$courseId, $name, $start, $end]);
        $msg = 'Cohort created.';
    }
}

$rows = $pdo->query("
  SELECT ch.*, c.title AS course_title, p.program_key
  FROM cohorts ch
  JOIN courses c ON c.id=ch.course_id
  JOIN programs p ON p.id=c.program_id
  ORDER BY ch.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

cw_header('Cohorts');
?>
<div class="card">
  <h2>Create cohort</h2>
  <?php if ($msg): ?><p class="muted"><?= h($msg) ?></p><?php endif; ?>

  <form method="post" class="form-grid">
    <input type="hidden" name="action" value="create">

    <label>Course</label>
    <select name="course_id" required>
      <option value="">— Select —</option>
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>"><?= h($c['program_key']) ?> — <?= h($c['title']) ?></option>
      <?php endforeach; ?>
    </select>

    <label>Cohort name</label>
    <input name="name" required placeholder="e.g. March 2026 PPL Cohort A">

    <label>Start date</label>
    <input name="start_date" type="date" required>

    <label>End date</label>
    <input name="end_date" type="date" required>

    <div></div>
    <button class="btn" type="submit">Create</button>
  </form>
</div>

<div class="card">
  <h2>Existing cohorts</h2>
  <table>
    <tr>
      <th>ID</th><th>Program</th><th>Course</th><th>Name</th><th>Start</th><th>End</th><th>Actions</th>
    </tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= h($r['program_key']) ?></td>
        <td><?= h($r['course_title']) ?></td>
        <td><?= h($r['name']) ?></td>
        <td><?= h($r['start_date']) ?></td>
        <td><?= h($r['end_date']) ?></td>
        <td style="white-space:nowrap;">
          <a class="btn btn-sm" href="/instructor/cohort.php?cohort_id=<?= (int)$r['id'] ?>">Open</a>
          <a class="btn btn-sm" href="/instructor/cohort_students.php?cohort_id=<?= (int)$r['id'] ?>">Students</a>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php cw_footer(); ?>