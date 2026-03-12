<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'admin' && $role !== 'supervisor') {
    http_response_code(403);
    exit('Forbidden');
}

$cohortId = (int)($_GET['id'] ?? 0);
if ($cohortId <= 0) exit('Missing id');

function cw_column_exists(PDO $pdo, string $table, string $col): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $col]);
    return ((int)$stmt->fetchColumn() > 0);
}

$hasTimezone = cw_column_exists($pdo, 'cohorts', 'timezone');
$hasSortOrder = cw_column_exists($pdo, 'cohort_lesson_deadlines', 'sort_order');

$selectTimezone = $hasTimezone ? "co.timezone" : "'UTC' AS timezone";

$stmt = $pdo->prepare("
  SELECT co.*, c.title AS course_title, c.id AS course_id, p.program_key, {$selectTimezone}
  FROM cohorts co
  JOIN courses c ON c.id=co.course_id
  JOIN programs p ON p.id=c.program_id
  WHERE co.id=? LIMIT 1
");
$stmt->execute([$cohortId]);
$cohort = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cohort) exit('Cohort not found');

// Students in cohort
$studentsStmt = $pdo->prepare("
  SELECT u.id, u.name, u.email, cs.status, cs.enrolled_at
  FROM cohort_students cs
  JOIN users u ON u.id=cs.user_id
  WHERE cs.cohort_id=?
  ORDER BY cs.id DESC
");
$studentsStmt->execute([$cohortId]);
$students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Deadlines
$orderBy = $hasSortOrder ? "d.sort_order, d.id" : "d.deadline_utc, d.id";

$deadlinesStmt = $pdo->prepare("
  SELECT d.*, l.external_lesson_id, l.title AS lesson_title
  FROM cohort_lesson_deadlines d
  JOIN lessons l ON l.id=d.lesson_id
  WHERE d.cohort_id=?
  ORDER BY {$orderBy}
");
$deadlinesStmt->execute([$cohortId]);
$deadlines = $deadlinesStmt->fetchAll(PDO::FETCH_ASSOC);

cw_header('Cohort');
?>
<div class="card">
  <div class="muted">
    <?= h($cohort['program_key']) ?> — <?= h($cohort['course_title']) ?>
    • Cohort: <strong><?= h($cohort['name']) ?></strong><br>
    Start: <?= h($cohort['start_date']) ?>
    • End: <?= h($cohort['end_date']) ?>
    • TZ: <?= h((string)($cohort['timezone'] ?? 'UTC')) ?>
  </div>

  <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn btn-sm" href="/instructor/cohorts.php">← Back</a>
    <a class="btn btn-sm" href="/instructor/cohort_students.php?cohort_id=<?= (int)$cohortId ?>">Manage students</a>
  </div>
</div>

<div class="card">
  <h2>Students</h2>
  <?php if (!$students): ?>
    <p class="muted">No students enrolled yet.</p>
  <?php else: ?>
    <table>
      <tr><th>Name</th><th>Email</th><th>Status</th><th>Enrolled</th></tr>
      <?php foreach ($students as $s): ?>
        <tr>
          <td><?= h($s['name']) ?></td>
          <td><?= h($s['email']) ?></td>
          <td><?= h($s['status']) ?></td>
          <td><?= h($s['enrolled_at']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Deadlines</h2>
  <?php if (!$deadlines): ?>
    <p class="muted">No deadlines generated yet. Go back to cohorts and click “Generate deadlines”.</p>
  <?php else: ?>
    <table>
      <tr>
        <th><?= $hasSortOrder ? 'Order' : 'Deadline Order' ?></th>
        <th>Lesson</th>
        <th>Deadline (UTC)</th>
        <th>Unlock after</th>
      </tr>
      <?php foreach ($deadlines as $d): ?>
        <tr>
          <td>
            <?= $hasSortOrder ? (int)$d['sort_order'] : h($d['deadline_utc']) ?>
          </td>
          <td><?= (int)$d['external_lesson_id'] ?> — <?= h($d['lesson_title']) ?></td>
          <td><?= h($d['deadline_utc']) ?></td>
          <td><?= !empty($d['unlock_after_lesson_id']) ? (int)$d['unlock_after_lesson_id'] : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<?php cw_footer(); ?>