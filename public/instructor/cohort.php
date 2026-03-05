<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/schedule.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'supervisor' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

function tz_options(): array {
    return [
        'UTC' => 'UTC',
        'America/Los_Angeles' => 'California (America/Los_Angeles)',
        'Europe/Brussels' => 'Belgium (Europe/Brussels)',
    ];
}

function programs(PDO $pdo): array {
    return $pdo->query("SELECT id, program_key, sort_order FROM programs ORDER BY sort_order, id")
        ->fetchAll(PDO::FETCH_ASSOC);
}

function courses_for_program(PDO $pdo, int $programId): array {
    $st = $pdo->prepare("SELECT id, title, sort_order FROM courses WHERE program_id=? ORDER BY sort_order, id");
    $st->execute([$programId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function cohort_courses_enabled(PDO $pdo, int $cohortId): array {
    $st = $pdo->prepare("SELECT course_id, is_enabled FROM cohort_courses WHERE cohort_id=?");
    $st->execute([$cohortId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[(int)$r['course_id']] = (int)$r['is_enabled'];
    return $out;
}

function save_course_selection(PDO $pdo, int $cohortId, int $programId, array $selectedCourseIds): void {
    $all = courses_for_program($pdo, $programId);
    $selected = [];
    foreach ($selectedCourseIds as $cid) $selected[(int)$cid] = true;

    $ins = $pdo->prepare("
      INSERT INTO cohort_courses (cohort_id, course_id, is_enabled)
      VALUES (?,?,?)
      ON DUPLICATE KEY UPDATE is_enabled=VALUES(is_enabled)
    ");
    foreach ($all as $c) {
        $cid = (int)$c['id'];
        $enabled = isset($selected[$cid]) ? 1 : 0;
        $ins->execute([$cohortId, $cid, $enabled]);
    }
}

function pick_primary_course(PDO $pdo, int $programId, array $selectedCourseIds): int {
    $selected = [];
    foreach ($selectedCourseIds as $cid) $selected[(int)$cid] = true;

    $all = courses_for_program($pdo, $programId);
    foreach ($all as $c) {
        $cid = (int)$c['id'];
        if (isset($selected[$cid])) return $cid;
    }
    if ($all) return (int)$all[0]['id'];
    return 1;
}

function fmt_pretty(string $ymdOrDt): string {
    $d = substr($ymdOrDt, 0, 10);
    try {
        $dt = new DateTimeImmutable($d.' 00:00:00', new DateTimeZone('UTC'));
        return $dt->format('D, M j, Y');
    } catch (Throwable $e) {
        return $ymdOrDt;
    }
}

// Accept both ?cohort_id= and legacy ?id=
$cohortId = (int)($_GET['cohort_id'] ?? 0);
if ($cohortId <= 0) $cohortId = (int)($_GET['id'] ?? 0);
if ($cohortId <= 0) exit('Missing id');

$msg = '';
$scheduleSummary = null;
$scheduleCourses = [];

$programs = programs($pdo);

$stmt = $pdo->prepare("
  SELECT co.*, p.program_key
  FROM cohorts co
  LEFT JOIN programs p ON p.id=co.program_id
  WHERE co.id=? LIMIT 1
");
$stmt->execute([$cohortId]);
$cohort = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cohort) exit('Cohort not found');

$programId = (int)($cohort['program_id'] ?? 0);
$enabledMap = cohort_courses_enabled($pdo, $cohortId);
$courses = ($programId > 0) ? courses_for_program($pdo, $programId) : [];

// --- Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_cohort') {
        $programId = (int)($_POST['program_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $start = trim((string)($_POST['start_date'] ?? ''));
        $end   = trim((string)($_POST['end_date'] ?? ''));
        $tz    = trim((string)($_POST['timezone'] ?? 'UTC'));

        if ($programId <= 0 || $name === '' || $start === '' || $end === '') {
            $msg = 'Missing required fields.';
        } else {
            $firstCourse = $pdo->prepare("SELECT id FROM courses WHERE program_id=? ORDER BY sort_order, id LIMIT 1");
            $firstCourse->execute([$programId]);
            $fallbackCourseId = (int)($firstCourse->fetchColumn() ?: 0);
            if ($fallbackCourseId <= 0) {
                $msg = 'No courses exist for this program yet.';
            } else {
                $up = $pdo->prepare("
                  UPDATE cohorts
                  SET program_id=?, name=?, start_date=?, end_date=?, timezone=?, course_id=?
                  WHERE id=?
                ");
                $up->execute([$programId, $name, $start, $end, $tz, $fallbackCourseId, $cohortId]);

                redirect('/instructor/cohort.php?cohort_id='.$cohortId);
            }
        }
    }

    if ($action === 'save_courses') {
        $programId = (int)($_POST['program_id'] ?? 0);
        $courseIds = $_POST['course_ids'] ?? [];
        if (!is_array($courseIds)) $courseIds = [];
        $courseIds = array_values(array_map('intval', $courseIds));

        if ($programId <= 0) {
            $msg = 'Missing program.';
        } else {
            save_course_selection($pdo, $cohortId, $programId, $courseIds);
            $primary = pick_primary_course($pdo, $programId, $courseIds);
            $pdo->prepare("UPDATE cohorts SET course_id=? WHERE id=?")->execute([$primary, $cohortId]);
            redirect('/instructor/cohort.php?cohort_id='.$cohortId);
        }
    }

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
                $ins = $pdo->prepare("
                  INSERT INTO cohort_students (cohort_id, user_id, status, enrolled_at, created_at)
                  VALUES (?, ?, 'active', NOW(), NOW())
                  ON DUPLICATE KEY UPDATE status='active'
                ");
                $ins->execute([$cohortId, $uid]);
                $msg = "Student added: ".$userRow['email'];
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

    if ($action === 'recalc_deadlines') {
        try {
            $out = cw_recalculate_cohort_deadlines($pdo, $cohortId);
            $scheduleSummary = $out['summary'] ?? null;
            $scheduleCourses = $out['courses'] ?? [];
            $msg = "Schedule recalculated.";
        } catch (Throwable $e) {
            $msg = "Schedule error: " . $e->getMessage();
        }
    }
}

// reload after edits
$stmt->execute([$cohortId]);
$cohort = $stmt->fetch(PDO::FETCH_ASSOC);
$programId = (int)($cohort['program_id'] ?? 0);
$enabledMap = cohort_courses_enabled($pdo, $cohortId);
$courses = ($programId > 0) ? courses_for_program($pdo, $programId) : [];

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

// Also load schedule from DB for display (course grouping)
$schedRowsStmt = $pdo->prepare("
  SELECT d.sort_order, d.deadline_utc, d.lesson_id,
         l.external_lesson_id, l.title AS lesson_title,
         c.id AS course_id, c.title AS course_title, c.sort_order AS course_sort
  FROM cohort_lesson_deadlines d
  JOIN lessons l ON l.id=d.lesson_id
  JOIN courses c ON c.id=l.course_id
  WHERE d.cohort_id=?
  ORDER BY c.sort_order, c.id, d.sort_order, l.external_lesson_id
");
$schedRowsStmt->execute([$cohortId]);
$schedRows = $schedRowsStmt->fetchAll(PDO::FETCH_ASSOC);

$courseBlocks = [];
foreach ($schedRows as $r) {
    $cid = (int)$r['course_id'];
    if (!isset($courseBlocks[$cid])) {
        $courseBlocks[$cid] = [
            'course_id' => $cid,
            'course_title' => (string)$r['course_title'],
            'lessons' => [],
            'course_deadline_utc' => (string)$r['deadline_utc'],
        ];
    }
    $courseBlocks[$cid]['lessons'][] = [
        'sort_order' => (int)$r['sort_order'],
        'external_lesson_id' => (int)$r['external_lesson_id'],
        'lesson_title' => (string)$r['lesson_title'],
        'deadline_utc' => (string)$r['deadline_utc'],
    ];
    // keep updating; last row becomes course deadline
    $courseBlocks[$cid]['course_deadline_utc'] = (string)$r['deadline_utc'];
}

cw_header('Theory Training');
?>
<div class="card">
  <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:center;">
    <div>
      <h2 style="margin:0 0 6px 0;"><?= h((string)$cohort['name']) ?></h2>
      <div class="muted">
        Program: <strong><?= h((string)($cohort['program_key'] ?? '—')) ?></strong>
        • Start: <?= h(fmt_pretty((string)$cohort['start_date'])) ?>
        • End: <?= h(fmt_pretty((string)$cohort['end_date'])) ?>
        • TZ: <?= h((string)$cohort['timezone']) ?>
      </div>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
      <a class="btn btn-sm" href="/instructor/cohorts.php">← Back to cohorts</a>
    </div>
  </div>

  <?php if ($msg): ?>
    <div class="alert" style="margin-top:10px;"><?= h($msg) ?></div>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin:0 0 10px 0;">Cohort settings</h2>
  <form method="post" class="form-grid">
    <input type="hidden" name="action" value="save_cohort">

    <label>Program</label>
    <select name="program_id" required>
      <?php foreach ($programs as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id'] === (int)$cohort['program_id']) ? 'selected' : '' ?>>
          <?= h((string)$p['program_key']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label>Cohort name</label>
    <input name="name" required value="<?= h((string)$cohort['name']) ?>">

    <label>Start date</label>
    <input name="start_date" type="date" required value="<?= h((string)$cohort['start_date']) ?>">

    <label>End date</label>
    <input name="end_date" type="date" required value="<?= h((string)$cohort['end_date']) ?>">

    <label>Timezone</label>
    <select name="timezone" required>
      <?php foreach (tz_options() as $k=>$lbl): ?>
        <option value="<?= h($k) ?>" <?= ((string)$cohort['timezone'] === $k) ? 'selected' : '' ?>><?= h($lbl) ?></option>
      <?php endforeach; ?>
    </select>

    <div></div>
    <button class="btn" type="submit">Save settings</button>
  </form>
</div>

<div class="card">
  <h2 style="margin:0 0 10px 0;">Courses included</h2>
  <p class="muted" style="margin-top:0;">
    Default: all program courses enabled. Uncheck to exclude.
  </p>

  <form method="post">
    <input type="hidden" name="action" value="save_courses">
    <input type="hidden" name="program_id" value="<?= (int)$programId ?>">

    <?php if (!$programId || !$courses): ?>
      <div class="muted">No program/courses available. Set a program above first.</div>
    <?php else: ?>
      <div style="display:flex; flex-direction:column; gap:6px;">
        <?php foreach ($courses as $c): ?>
          <?php
            $cid = (int)$c['id'];
            $checked = ((int)($enabledMap[$cid] ?? 1) === 1);
          ?>
          <label style="display:flex; gap:10px; align-items:center;">
            <input type="checkbox" name="course_ids[]" value="<?= $cid ?>" <?= $checked ? 'checked' : '' ?>>
            <span><?= h((string)$c['title']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>

      <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
        <button class="btn" type="submit">Save course selection</button>
      </div>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <h2 style="margin:0 0 10px 0;">Schedule</h2>

  <form method="post" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
    <input type="hidden" name="action" value="recalc_deadlines">
    <button class="btn" type="submit">Recalculate deadlines</button>
  </form>

  <?php if ($scheduleSummary): ?>
    <div style="margin-top:12px;">
      <h3 style="margin:0 0 8px 0;">Schedule summary</h3>
      <div class="muted">
        <div>- Lessons scheduled: <?= (int)$scheduleSummary['lessons_scheduled'] ?></div>
        <div>- Total study hours (est.): <?= h((string)$scheduleSummary['total_study_hours']) ?></div>
        <div>- Usable days: <?= (int)$scheduleSummary['usable_days'] ?> (Recommended: <?= (int)$scheduleSummary['recommended_days'] ?> days)</div>
        <div>- <?= h((string)$scheduleSummary['assumptions']) ?></div>
        <div>- Note(s): Suggested End Date <?= h((string)$scheduleSummary['suggested_end_pretty']) ?> (<?= h((string)$scheduleSummary['suggested_end_delta']) ?>)</div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$courseBlocks): ?>
    <div class="muted" style="margin-top:12px;">No schedule yet. Click “Recalculate deadlines”.</div>
  <?php else: ?>
    <div style="margin-top:12px;">
      <table>
        <tr>
          <th style="width:70px;">Order</th>
          <th>Course</th>
          <th style="width:220px;">Deadline</th>
        </tr>

        <?php $i = 0; foreach ($courseBlocks as $cb): $i++; ?>
          <?php
            $courseDeadlinePretty = fmt_pretty((string)$cb['course_deadline_utc']);
            $courseTitle = (string)$cb['course_title'];
            $courseLessons = (array)$cb['lessons'];
            $detailsId = 'course_' . (int)$cb['course_id'];
          ?>
          <tr>
            <td><?= $i ?></td>
            <td>
              <details id="<?= h($detailsId) ?>">
                <summary style="cursor:pointer; font-weight:700;">
                  <?= h($courseTitle) ?>
                </summary>

                <div style="margin-top:10px;">
                  <table>
                    <tr>
                      <th style="width:70px;">Order</th>
                      <th>Lesson</th>
                      <th style="width:220px;">Deadline</th>
                    </tr>
                    <?php $j = 0; foreach ($courseLessons as $lx): $j++; ?>
                      <tr>
                        <td><?= $j ?></td>
                        <td><?= (int)$lx['external_lesson_id'] ?> — <?= h((string)$lx['lesson_title']) ?></td>
                        <td><?= h(fmt_pretty((string)$lx['deadline_utc'])) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </table>
                </div>
              </details>
            </td>
            <td><?= h($courseDeadlinePretty) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
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