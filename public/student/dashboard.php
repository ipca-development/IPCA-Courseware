<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');

// Allow admin to view student dashboard for testing
if ($role !== 'student' && $role !== 'admin') {
    redirect(cw_home_path_for_role($role));
}

$userId = (int)$u['id'];

// If admin, optionally simulate a student view via ?user_id=
if ($role === 'admin' && isset($_GET['user_id'])) {
    $userId = (int)$_GET['user_id'];
}

$stmt = $pdo->prepare("
  SELECT co.id, co.name, co.start_date, co.end_date,
         c.title AS course_title,
         p.program_key
  FROM cohort_students cs
  JOIN cohorts co ON co.id = cs.cohort_id
  JOIN courses c ON c.id = co.course_id
  JOIN programs p ON p.id = c.program_id
  WHERE cs.user_id = ?
  ORDER BY co.id DESC
");
$stmt->execute([$userId]);
$cohorts = $stmt->fetchAll(PDO::FETCH_ASSOC);

cw_header('My Dashboard');
?>

<div class="card">
  <p class="muted">
    Study dashboard (optimized for iPad landscape). Deadlines in UTC.
  </p>
</div>

<?php if (!$cohorts): ?>
  <div class="card"><p class="muted">No cohort assigned yet.</p></div>
<?php else: ?>
  <?php foreach ($cohorts as $co): ?>
    <?php
      $cohortId = (int)$co['id'];

      $total = $pdo->prepare("SELECT COUNT(*) FROM cohort_lesson_deadlines WHERE cohort_id=?");
      $total->execute([$cohortId]);
      $totalLessons = (int)$total->fetchColumn();

      $passed = $pdo->prepare("
        SELECT COUNT(*)
        FROM lesson_activity
        WHERE user_id=? AND status='passed'
        AND lesson_id IN (
            SELECT lesson_id
            FROM cohort_lesson_deadlines
            WHERE cohort_id=?
        )
      ");
      $passed->execute([$userId, $cohortId]);
      $passedLessons = (int)$passed->fetchColumn();

      $pct = ($totalLessons > 0)
        ? (int)round(($passedLessons / $totalLessons) * 100)
        : 0;

      $next = $pdo->prepare("
        SELECT d.deadline_utc, l.title, l.external_lesson_id
        FROM cohort_lesson_deadlines d
        JOIN lessons l ON l.id = d.lesson_id
        WHERE d.cohort_id=?
        ORDER BY d.deadline_utc ASC
        LIMIT 1
      ");
      $next->execute([$cohortId]);
      $nextRow = $next->fetch(PDO::FETCH_ASSOC);
    ?>
      <div class="card">
        <h2 style="margin:0 0 6px 0;">
          <?= h($co['program_key']) ?> — <?= h($co['course_title']) ?>
        </h2>

        <div class="muted">
          Cohort: <?= h($co['name']) ?>
          • Start: <?= h($co['start_date']) ?>
          • End: <?= h($co['end_date']) ?>
        </div>

        <div style="margin-top:10px;">
          <div class="muted">
            Progress: <?= $pct ?>% (<?= $passedLessons ?>/<?= $totalLessons ?>)
          </div>
          <div style="height:10px;background:#eee;border-radius:999px;overflow:hidden;margin-top:6px;">
            <div style="height:10px;width:<?= $pct ?>%;background:#1e3c72;"></div>
          </div>
        </div>

        <?php if ($nextRow): ?>
          <div class="muted" style="margin-top:10px;">
            Next deadline:
            <?= h($nextRow['deadline_utc']) ?> UTC —
            <?= (int)$nextRow['external_lesson_id'] ?>
            <?= h($nextRow['title']) ?>
          </div>
        <?php endif; ?>

        <div style="margin-top:12px;">
          <a class="btn"
             href="/student/course.php?cohort_id=<?= $cohortId ?>">
             Open course
          </a>
        </div>
      </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php cw_footer(); ?>