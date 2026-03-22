<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');

if ($role !== 'student' && $role !== 'admin') {
    redirect(cw_home_path_for_role($role));
}

$userId = (int)$u['id'];

if ($role === 'admin' && isset($_GET['user_id'])) {
    $userId = (int)$_GET['user_id'];
}

function courses_fmt_date(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }

    try {
        $dt = new DateTime($value);
        return $dt->format('M j, Y');
    } catch (Throwable $e) {
        return $value;
    }
}

$stmt = $pdo->prepare("
    SELECT
        co.id,
        co.name,
        co.start_date,
        co.end_date,
        c.title AS course_title,
        p.program_key,
        p.name AS program_name
    FROM cohort_students cs
    JOIN cohorts co ON co.id = cs.cohort_id
    JOIN courses c ON c.id = co.course_id
    LEFT JOIN programs p ON p.id = co.program_id
    WHERE cs.user_id = ?
    ORDER BY co.id DESC
");
$stmt->execute([$userId]);
$cohorts = $stmt->fetchAll(PDO::FETCH_ASSOC);

cw_header('My Courses');
?>

<style>
  .courses-stack{
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
    font-size:30px;
    line-height:1.05;
    letter-spacing:-0.03em;
    color:#152235;
  }
  .hero-sub{
    margin-top:10px;
    font-size:15px;
    color:#6f7f95;
    max-width:780px;
    line-height:1.55;
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
    font-size:24px;
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

  .open-row{
    margin-top:18px;
    display:flex;
    flex-wrap:wrap;
    gap:10px;
  }

  .mini-action{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:0 14px;
    border-radius:12px;
    text-decoration:none;
    font-size:14px;
    font-weight:700;
    color:#152235;
    background:#f4f7fb;
    border:1px solid rgba(15,23,42,0.08);
  }

  .mini-action:hover{
    background:#edf2f8;
  }

  .mini-action.primary{
    background:#12355f;
    color:#fff;
    border-color:#12355f;
  }

  .mini-action.primary:hover{
    background:#0f2f54;
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

<div class="courses-stack">
  <div class="card hero-card">
    <div class="hero-eyebrow">Theory Training</div>
    <h2 class="hero-title">My Courses</h2>
    <div class="hero-sub">
      View the training cohorts and course pages currently assigned to you. Open a course directly or jump to your notebook for that training scope.
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
          $programLabel = trim((string)($co['program_name'] ?? ''));
          if ($programLabel === '') {
              $programLabel = trim((string)($co['program_key'] ?? ''));
          }
          if ($programLabel === '') {
              $programLabel = 'Training Program';
          }
        ?>
        <div class="card cohort-card">
          <div class="cohort-header">
            <div>
              <h3 class="cohort-title"><?= h((string)$co['course_title']) ?></h3>
              <div class="cohort-sub">
                Program: <?= h($programLabel) ?>
                · Cohort: <?= h((string)$co['name']) ?>
                · Start: <?= h(courses_fmt_date((string)$co['start_date'])) ?>
                · End: <?= h(courses_fmt_date((string)$co['end_date'])) ?>
              </div>
            </div>

            <div class="cohort-chip">Cohort #<?= $cohortId ?></div>
          </div>

          <div class="open-row">
            <a class="mini-action primary" href="/student/course.php?cohort_id=<?= $cohortId ?>">
              Open Course
            </a>
            <a class="mini-action" href="/student/lesson_summaries.php?cohort_id=<?= $cohortId ?>">
              Open Notebook
            </a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php cw_footer(); ?>