<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$u = cw_current_user();
if (($u['role'] ?? '') !== 'admin' && ($u['role'] ?? '') !== 'supervisor') {
    http_response_code(403);
    exit('Forbidden');
}

function utc_midnight(DateTimeInterface $d): string {
    $dt = new DateTime($d->format('Y-m-d') . ' 00:00:00', new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

function build_deadlines(PDO $pdo, int $cohortId): array {
    // Cohort
    $stmt = $pdo->prepare("SELECT * FROM cohorts WHERE id=? LIMIT 1");
    $stmt->execute([$cohortId]);
    $cohort = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cohort) return ['ok'=>false,'error'=>'Cohort not found'];

    // Lessons for course
    $stmt = $pdo->prepare("
      SELECT id, sort_order, external_lesson_id, title
      FROM lessons
      WHERE course_id=?
      ORDER BY sort_order, external_lesson_id, id
    ");
    $stmt->execute([(int)$cohort['course_id']]);
    $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$lessons) return ['ok'=>false,'error'=>'No lessons in course'];

    // Date range
    $start = new DateTime((string)$cohort['start_date'], new DateTimeZone('UTC'));
    $end   = new DateTime((string)$cohort['end_date'], new DateTimeZone('UTC'));
    if ($end < $start) return ['ok'=>false,'error'=>'End date must be >= start date'];

    $days = (int)$start->diff($end)->days;
    $slots = max(1, $days); // number of day steps
    $n = count($lessons);

    // wipe existing deadlines
    $pdo->prepare("DELETE FROM cohort_lesson_deadlines WHERE cohort_id=?")->execute([$cohortId]);

    $ins = $pdo->prepare("
      INSERT INTO cohort_lesson_deadlines (cohort_id, lesson_id, sort_order, unlock_after_lesson_id, deadline_utc)
      VALUES (?,?,?,?,?)
    ");

    for ($i=0; $i<$n; $i++) {
        $lessonId = (int)$lessons[$i]['id'];
        $unlockAfter = ($i === 0) ? null : (int)$lessons[$i-1]['id'];

        // spread lessons across range
        $offsetDays = (int)floor(($i / max(1, $n-1)) * $slots);
        $due = clone $start;
        $due->modify('+' . $offsetDays . ' days');

        $ins->execute([
            $cohortId,
            $lessonId,
            ($i+1)*10,
            $unlockAfter,
            utc_midnight($due)
        ]);
    }

    return ['ok'=>true,'count'=>$n];
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $start = trim((string)($_POST['start_date'] ?? ''));
        $end = trim((string)($_POST['end_date'] ?? ''));
        $tz = trim((string)($_POST['timezone'] ?? 'UTC'));
        if ($courseId <= 0 || $name === '' || $start === '' || $end === '') {
            exit('Missing fields');
        }

        $stmt = $pdo->prepare("INSERT INTO cohorts (course_id,name,start_date,end_date,timezone) VALUES (?,?,?,?,?)");
        $stmt->execute([$courseId,$name,$start,$end,$tz]);
        redirect('/instructor/cohorts.php');
    }

    if ($action === 'generate_deadlines') {
        $cohortId = (int)($_POST['cohort_id'] ?? 0);
        $r = build_deadlines($pdo, $cohortId);
        redirect('/instructor/cohort.php?id=' . $cohortId);
    }
}

$courses = $pdo->query("
  SELECT c.id, c.title, p.program_key
  FROM courses c
  JOIN programs p ON p.id=c.program_id
  ORDER BY p.sort_order, c.sort_order, c.id
")->fetchAll(PDO::FETCH_ASSOC);

$cohorts = $pdo->query("
  SELECT co.id, co.name, co.start_date, co.end_date, co.timezone, c.title AS course_title, p.program_key,
         (SELECT COUNT(*) FROM cohort_students cs WHERE cs.cohort_id=co.id) AS student_count
  FROM cohorts co
  JOIN courses c ON c.id=co.course_id
  JOIN programs p ON p.id=c.program_id
  ORDER BY co.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

cw_header('Cohorts');
?>
<div class="card">
  <h2>Create cohort</h2>
  <form method="post" class="form-grid">
    <input type="hidden" name="action" value="create">

    <label>Course</label>
    <select name="course_id" required>
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>"><?= h($c['program_key']) ?> — <?= h($c['title']) ?></option>
      <?php endforeach; ?>
    </select>

    <label>Cohort name</label>
    <input name="name" required placeholder="e.g. March 2026 Cohort">

    <label>Start date</label>
    <input name="start_date" type="date" required>

    <label>End date</label>
    <input name="end_date" type="date" required>

    <label>Timezone</label>
    <input name="timezone" value="UTC">

    <div></div>
    <button class="btn" type="submit">Create cohort</button>
  </form>
</div>

<div class="card">
  <h2>Existing cohorts</h2>
  <table>
    <tr>
      <th>ID</th><th>Course</th><th>Name</th><th>Start</th><th>End</th><th>Students</th><th>Actions</th>
    </tr>
    <?php foreach ($cohorts as $co): ?>
      <tr>
        <td><?= (int)$co['id'] ?></td>
        <td><?= h($co['program_key']) ?> — <?= h($co['course_title']) ?></td>
        <td><?= h($co['name']) ?></td>
        <td><?= h($co['start_date']) ?></td>
        <td><?= h($co['end_date']) ?></td>
        <td><?= (int)$co['student_count'] ?></td>
        <td style="white-space:nowrap;">
          <a class="btn btn-sm" href="/instructor/cohort.php?id=<?= (int)$co['id'] ?>">Open</a>
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="generate_deadlines">
            <input type="hidden" name="cohort_id" value="<?= (int)$co['id'] ?>">
            <button class="btn btn-sm" type="submit">Generate deadlines</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php cw_footer(); ?>