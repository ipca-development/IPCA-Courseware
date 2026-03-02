<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');

if ($role !== 'student' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$userId = (int)$u['id'];

// Admin: allow selecting any cohort
$selectedCohortId = (int)($_GET['cohort_id'] ?? 0);

if ($role === 'admin') {
    // list all cohorts
    $stmt = $pdo->query("
      SELECT co.id, co.name, co.start_date, co.end_date, c.title AS course_title, p.program_key
      FROM cohorts co
      JOIN courses c ON c.id=co.course_id
      JOIN programs p ON p.id=c.program_id
      ORDER BY co.id DESC
    ");
    $cohorts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // if none selected, show all; else filter to one
    if ($selectedCohortId > 0) {
        $cohorts = array_values(array_filter($cohorts, function($r) use ($selectedCohortId){
            return (int)$r['id'] === $selectedCohortId;
        }));
    }
} else {
    // Student: only their cohorts
    $stmt = $pdo->prepare("
      SELECT co.id, co.name, co.start_date, co.end_date, c.title AS course_title, p.program_key
      FROM cohort_students cs
      JOIN cohorts co ON co.id=cs.cohort_id
      JOIN courses c ON c.id=co.course_id
      JOIN programs p ON p.id=c.program_id
      WHERE cs.user_id=?
      ORDER BY co.id DESC
    ");
    $stmt->execute([$userId]);
    $cohorts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

cw_header('My Dashboard');
?>

<div class="card">
  <p class="muted">
    This is your study dashboard (optimized for iPad landscape). Deadlines are in UTC.
  </p>

  <?php if ($role === 'admin'): ?>
    <form method="get" class="form-grid" style="margin-top:10px;">
      <label>Admin: view cohort</label>
      <select name="cohort_id" onchange="this.form.submit()">
        <option value="0">— All cohorts —</option>
        <?php
          $all = $pdo->query("SELECT id, name FROM cohorts ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
          foreach ($all as $x):
        ?>
          <option value="<?= (int)$x['id'] ?>" <?= ($selectedCohortId === (int)$x['id']) ? 'selected' : '' ?>>
            <?= (int)$x['id'] ?> — <?= h($x['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div></div><div></div>
    </form>
  <?php endif; ?>
</div>

<?php if (!$cohorts): ?>
  <div class="card"><p class="muted">No cohort found.</p></div>
<?php else: ?>
  <?php foreach ($cohorts as $co): ?>
    <?php
      $cohortId = (int)$co['id'];

      $total = $pdo->prepare("SELECT COUNT(*) FROM cohort_lesson_deadlines WHERE cohort_id=?");
      $total->execute([$cohortId]);
      $totalLessons = (int)$total->fetchColumn();

      // If admin, show progress as 0% (no specific student); if student show their passed lessons
      $passedLessons = 0;
      if ($role === 'student') {
          $passed = $pdo->prepare("
            SELECT COUNT(*)
            FROM lesson_activity
            WHERE user_id=? AND status='passed'
            AND lesson_id IN (SELECT lesson_id FROM cohort_lesson_deadlines WHERE cohort_id=?)
          ");
          $passed->execute([$userId, $cohortId]);
          $passedLessons = (int)$passed->fetchColumn();
      }

      $pct = ($totalLessons > 0) ? (int)round(($passedLessons / $totalLessons) * 100) : 0;

      $next = $pdo->prepare("
        SELECT d.deadline_utc, l.title, l.external_lesson_id, l.id AS lesson_id
        FROM cohort_lesson_deadlines d
        JOIN lessons l ON l.id=d.lesson_id
        WHERE d.cohort_id=?
        ORDER BY d.deadline_utc ASC
        LIMIT 1
      ");
      $next->execute([$cohortId]);
      $nextRow = $next->fetch(PDO::FETCH_ASSOC);
    ?>
    <div class="card">
      <h2 style="margin:0 0 6px 0;"><?= h($co['program_key']) ?> — <?= h($co['course_title']) ?></h2>
      <div class="muted">
        Cohort: <?= h($co['name']) ?> • Start: <?= h($co['start_date']) ?> • End: <?= h($co['end_date']) ?>
      </div>

      <div style="margin-top:10px;">
        <div class="muted">
          <?php if ($role === 'student'): ?>
            Progress: <?= $pct ?>% (<?= $passedLessons ?>/<?= $totalLessons ?>)
          <?php else: ?>
            Admin view: schedule overview (progress shown per student later)
          <?php endif; ?>
        </div>

        <div style="height:10px;background:#eee;border-radius:999px;overflow:hidden;margin-top:6px;">
          <div style="height:10px;width:<?= $pct ?>%;background:#1e3c72;"></div>
        </div>
      </div>

      <?php if ($nextRow): ?>
        <div class="muted" style="margin-top:10px;">
          Next deadline: <?= h($nextRow['deadline_utc']) ?> UTC — <?= (int)$nextRow['external_lesson_id'] ?> <?= h($nextRow['title']) ?>
        </div>
      <?php endif; ?>

      <div style="margin-top:12px;">
        <a class="btn" href="/student/course.php?cohort_id=<?= $cohortId ?>">Open course</a>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php cw_footer(); ?>