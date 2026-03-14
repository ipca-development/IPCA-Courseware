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

function dash_fmt_date_student(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '—';

    try {
        $dt = new DateTime($value);
        return $dt->format('M j, Y');
    } catch (Throwable $e) {
        return $value;
    }
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

cw_header('Dashboard');
?>

<style>
  .dash-stack{
    display:flex;
    flex-direction:column;
    gap:22px;
  }

  .hero-card{
    padding:24px 26px;
  }
  .hero-eyebrow{
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.12em;
    color:#7b8ba3;
    font-weight:700;
    margin-bottom:10px;
  }
  .hero-title{
    margin:0;
    font-size:28px;
    line-height:1.05;
    letter-spacing:-0.03em;
    color:#152235;
  }
  .hero-sub{
    margin-top:10px;
    font-size:15px;
    color:#6f7f95;
    max-width:760px;
  }

  .cohort-stack{
    display:flex;
    flex-direction:column;
    gap:18px;
  }
  .cohort-card{
    padding:24px 26px;
  }
  .cohort-header{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:18px;
    margin-bottom:14px;
    flex-wrap:wrap;
  }
  .cohort-title{
    margin:0;
    font-size:28px;
    line-height:1.05;
    letter-spacing:-0.03em;
    color:#152235;
  }
  .cohort-sub{
    margin-top:8px;
    font-size:15px;
    color:#6f7f95;
    line-height:1.5;
  }
  .cohort-chip{
    display:inline-block;
    padding:7px 11px;
    border-radius:999px;
    background:#f2f7ff;
    color:#2457b8;
    font-size:12px;
    font-weight:800;
    border:1px solid #dbe7ff;
  }

  .progress-meta{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:8px;
  }
  .progress-label{
    font-size:15px;
    color:#415067;
    font-weight:600;
  }
  .progress-shell-student{
    height:12px;
    background:#e9eef5;
    border-radius:999px;
    overflow:hidden;
  }
  .progress-fill-student{
    height:12px;
    border-radius:999px;
    background:linear-gradient(90deg,#123b72 0%, #2f6ac6 100%);
  }

  .deadline-box{
    margin-top:16px;
    padding:14px 16px;
    border-radius:16px;
    background:#f7f9fc;
    border:1px solid rgba(15,23,42,0.05);
  }
  .deadline-label{
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.12em;
    color:#7b8ba3;
    font-weight:700;
    margin-bottom:8px;
  }
  .deadline-text{
    font-size:15px;
    color:#415067;
    line-height:1.45;
  }

  .open-row{
    margin-top:18px;
  }

  .empty-premium{
    padding:22px 24px;
    border-radius:18px;
    border:1px dashed rgba(15,23,42,0.12);
    background:linear-gradient(180deg,#ffffff 0%,#fbfcfe 100%);
    color:#728198;
    font-size:15px;
  }
</style>

<div class="dash-stack">
  <div class="card hero-card">
    <div class="hero-eyebrow">Student Workspace</div>
    <h2 class="hero-title">Training Overview</h2>
    <div class="hero-sub">
      Track your assigned cohorts, lesson progression, and next upcoming training deadlines from one clean overview.
    </div>
  </div>

  <?php if (!$cohorts): ?>
    <div class="card">
      <div class="empty-premium">No cohort assigned yet.</div>
    </div>
  <?php else: ?>
    <div class="cohort-stack">
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
          <div class="card cohort-card">
            <div class="cohort-header">
              <div>
                <h3 class="cohort-title"><?= h($co['course_title']) ?></h3>
                <div class="cohort-sub">
                  Program: <?= h($co['program_key']) ?>
                  · Cohort: <?= h($co['name']) ?>
                  · Start: <?= h(dash_fmt_date_student($co['start_date'])) ?>
                  · End: <?= h(dash_fmt_date_student($co['end_date'])) ?>
                </div>
              </div>

              <div class="cohort-chip"><?= $pct ?>% complete</div>
            </div>

            <div class="progress-meta">
              <div class="progress-label">
                <?= $passedLessons ?>/<?= $totalLessons ?> lessons completed
              </div>
            </div>

            <div class="progress-shell-student">
              <div class="progress-fill-student" style="width:<?= $pct ?>%;"></div>
            </div>

            <?php if ($nextRow): ?>
              <div class="deadline-box">
                <div class="deadline-label">Next Deadline</div>
                <div class="deadline-text">
                  <?= h(dash_fmt_date_student($nextRow['deadline_utc'])) ?> ·
                  Lesson <?= (int)$nextRow['external_lesson_id'] ?> ·
                  <?= h($nextRow['title']) ?>
                </div>
              </div>
            <?php endif; ?>

            <div class="open-row">
              <a class="btn" href="/student/course.php?cohort_id=<?= $cohortId ?>">
                Open Course
              </a>
            </div>
          </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php cw_footer(); ?>
