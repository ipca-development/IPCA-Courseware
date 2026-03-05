<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'supervisor' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

// Accept both ?cohort_id= and legacy ?id=
$cohortId = (int)($_GET['cohort_id'] ?? 0);
if ($cohortId <= 0) $cohortId = (int)($_GET['id'] ?? 0);
if ($cohortId <= 0) exit('Missing id');

$msg = '';

// Load cohort
$stmt = $pdo->prepare("
  SELECT co.*,
         p.program_key,
         c.title AS primary_course_title
  FROM cohorts co
  LEFT JOIN programs p ON p.id = co.program_id
  LEFT JOIN courses c ON c.id = co.course_id
  WHERE co.id=?
  LIMIT 1
");
$stmt->execute([$cohortId]);
$cohort = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cohort) exit('Cohort not found');

// Selected courses (program-level selection)
$selectedCourses = [];
if (!empty($cohort['program_id'])) {
    $stmt = $pdo->prepare("
      SELECT cc.course_id, cc.is_enabled, c.title
      FROM cohort_courses cc
      JOIN courses c ON c.id = cc.course_id
      WHERE cc.cohort_id=?
      ORDER BY c.sort_order, c.id
    ");
    $stmt->execute([$cohortId]);
    $selectedCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle add/remove student
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_student') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if ($email === '') {
            $msg = 'Enter an email address.';
        } else {
            $st = $pdo->prepare("SELECT id,email,name,role FROM users WHERE email=? LIMIT 1");
            $st->execute([$email]);
            $userRow = $st->fetch(PDO::FETCH_ASSOC);

            if (!$userRow) {
                $msg = "No user found for: {$email}";
            } else {
                $uid = (int)$userRow['id'];

                // Insert if not exists
                $ins = $pdo->prepare("
                  INSERT INTO cohort_students (cohort_id, user_id, status, enrolled_at, created_at)
                  VALUES (?, ?, 'active', NOW(), NOW())
                  ON DUPLICATE KEY UPDATE status='active'
                ");
                $ins->execute([$cohortId, $uid]);

                $msg = "Student added: " . $userRow['email'];
            }
        }
    }

    if ($action === 'remove_student') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid > 0) {
            $pdo->prepare("DELETE FROM cohort_students WHERE cohort_id=? AND user_id=?")->execute([$cohortId, $uid]);
            $msg = "Student removed.";
        }
    }
}

// Load students in cohort
$studentsStmt = $pdo->prepare("
  SELECT cs.user_id, cs.status, cs.enrolled_at,
         u.email, u.name, u.role
  FROM cohort_students cs
  JOIN users u ON u.id = cs.user_id
  WHERE cs.cohort_id=?
  ORDER BY cs.enrolled_at ASC, cs.user_id ASC
");
$studentsStmt->execute([$cohortId]);
$students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

cw_header('Theory Training');
?>

<div class="card">
  <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:center;">
    <div>
      <h2 style="margin:0 0 6px 0;"><?= h((string)$cohort['name']) ?></h2>
      <div class="muted">
        Program: <strong><?= h((string)($cohort['program_key'] ?? '—')) ?></strong>
        • Primary course (legacy): <strong><?= h((string)($cohort['primary_course_title'] ?? '—')) ?></strong><br>
        Start: <?= h((string)$cohort['start_date']) ?> • End: <?= h((string)$cohort['end_date']) ?> • TZ: <?= h((string)$cohort['timezone']) ?>
      </div>
    </div>

    <div style="display:flex; gap:10px; align-items:center;">
      <a class="btn btn-sm" href="/instructor/cohorts.php">← Back to cohorts</a>
      <a class="btn btn-sm" href="/instructor/cohorts.php?edit_id=<?= (int)$cohortId ?>">Edit cohort</a>
    </div>
  </div>

  <?php if ($msg): ?>
    <div class="alert" style="margin-top:10px;"><?= h($msg) ?></div>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin:0 0 10px 0;">Selected Courses</h2>

  <?php if (!$selectedCourses): ?>
    <div class="muted">No course selection saved yet for this cohort.</div>
  <?php else: ?>
    <ul style="margin:0; padding-left:18px;">
      <?php foreach ($selectedCourses as $c): ?>
        <li>
          <?= ((int)$c['is_enabled'] === 1) ? '✅' : '⬜️' ?>
          <?= h((string)$c['title']) ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <div class="muted" style="margin-top:10px;">
      (Enabled courses are ✅. Disabled ones are unchecked in cohort setup.)
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin:0 0 10px 0;">Students</h2>

  <form method="post" class="form-inline" style="margin-bottom:12px;">
    <input type="hidden" name="action" value="add_student">
    <input class="input" name="email" placeholder="student@email.com" style="min-width:280px;">
    <button class="btn" type="submit">Add student</button>
    <div class="muted">User must already exist in the users table.</div>
  </form>

  <?php if (!$students): ?>
    <div class="muted">No students enrolled yet.</div>
  <?php else: ?>
    <table>
      <tr>
        <th>Email</th>
        <th>Name</th>
        <th>Role</th>
        <th>Status</th>
        <th>Enrolled</th>
        <th>Actions</th>
      </tr>
      <?php foreach ($students as $s): ?>
        <tr>
          <td><?= h((string)$s['email']) ?></td>
          <td><?= h((string)$s['name']) ?></td>
          <td><?= h((string)$s['role']) ?></td>
          <td><?= h((string)$s['status']) ?></td>
          <td><?= h((string)$s['enrolled_at']) ?></td>
          <td style="white-space:nowrap;">
            <form method="post" style="display:inline" onsubmit="return confirm('Remove this student from cohort?');">
              <input type="hidden" name="action" value="remove_student">
              <input type="hidden" name="user_id" value="<?= (int)$s['user_id'] ?>">
              <button class="btn btn-sm" type="submit">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<?php cw_footer(); ?>