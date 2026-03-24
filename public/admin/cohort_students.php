<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$cohortId = (int)($_GET['cohort_id'] ?? 0);
if ($cohortId <= 0) exit('Missing cohort_id');

$stmt = $pdo->prepare("SELECT id, name FROM cohorts WHERE id=? LIMIT 1");
$stmt->execute([$cohortId]);
$cohort = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cohort) exit('Cohort not found');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO cohort_students (cohort_id,user_id,status) VALUES (?,?, 'active')");
            $stmt->execute([$cohortId,$userId]);
        }
        redirect('/instructor/cohort_students.php?cohort_id=' . $cohortId);
    }

    if ($action === 'remove') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            $stmt = $pdo->prepare("DELETE FROM cohort_students WHERE cohort_id=? AND user_id=?");
            $stmt->execute([$cohortId,$userId]);
        }
        redirect('/instructor/cohort_students.php?cohort_id=' . $cohortId);
    }
}

$users = $pdo->query("SELECT id, name, email, role FROM users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

$enrolled = $pdo->prepare("
  SELECT u.id, u.name, u.email, u.role
  FROM cohort_students cs
  JOIN users u ON u.id=cs.user_id
  WHERE cs.cohort_id=?
  ORDER BY cs.id DESC
");
$enrolled->execute([$cohortId]);
$enrolled = $enrolled->fetchAll(PDO::FETCH_ASSOC);

cw_header('Manage Cohort Students');
?>
<div class="card">
  <div class="row" style="justify-content:space-between;">
    <div class="muted">Cohort: <strong><?= h($cohort['name']) ?></strong></div>
    <a class="btn btn-sm" href="/instructor/cohort.php?id=<?= (int)$cohortId ?>">← Back</a>
  </div>
</div>

<div class="card">
  <h2>Add student</h2>
  <form method="post" class="form-grid">
    <input type="hidden" name="action" value="add">
    <label>User</label>
    <select name="user_id" required>
      <option value="">— select —</option>
      <?php foreach ($users as $urow): ?>
        <?php if (($urow['role'] ?? '') !== 'student') continue; ?>
        <option value="<?= (int)$urow['id'] ?>"><?= h($urow['name']) ?> (<?= h($urow['email']) ?>)</option>
      <?php endforeach; ?>
    </select>
    <div></div>
    <button class="btn" type="submit">Add</button>
  </form>
</div>

<div class="card">
  <h2>Enrolled students</h2>
  <?php if (!$enrolled): ?>
    <p class="muted">None yet.</p>
  <?php else: ?>
    <table>
      <tr><th>Name</th><th>Email</th><th>Action</th></tr>
      <?php foreach ($enrolled as $s): ?>
        <tr>
          <td><?= h($s['name']) ?></td>
          <td><?= h($s['email']) ?></td>
          <td>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="remove">
              <input type="hidden" name="user_id" value="<?= (int)$s['id'] ?>">
              <button class="btn btn-sm" type="submit">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
<?php cw_footer(); ?>