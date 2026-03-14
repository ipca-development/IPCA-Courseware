<?php
declare(strict_types=1);

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

function dash_fmt_datetime_student(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '—';

    try {
        $dt = new DateTime($value);
        return $dt->format('M j, Y · H:i');
    } catch (Throwable $e) {
        return $value;
    }
}

function student_days_left(?string $deadlineUtc): ?int
{
    $deadlineUtc = trim((string)$deadlineUtc);
    if ($deadlineUtc === '') return null;

    try {
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $deadline = new DateTime($deadlineUtc, new DateTimeZone('UTC'));
        $now->setTime(0, 0, 0);
        $deadline->setTime(0, 0, 0);
        return (int)$now->diff($deadline)->format('%r%a');
    } catch (Throwable $e) {
        return null;
    }
}

function student_deadline_badge_class(?int $daysLeft): string
{
    if ($daysLeft === null) return 'neutral';
    if ($daysLeft < 0) return 'danger';
    if ($daysLeft <= 2) return 'danger';
    if ($daysLeft <= 5) return 'warn';
    return 'ok';
}

function student_deadline_progress_class(?int $daysLeft): string
{
    if ($daysLeft === null) return 'neutral';
    if ($daysLeft < 0) return 'danger';
    if ($daysLeft <= 2) return 'danger';
    if ($daysLeft <= 5) return 'warn';
    return 'ok';
}

function student_deadline_label(?int $daysLeft): string
{
    if ($daysLeft === null) return 'No date';
    if ($daysLeft < 0) return abs($daysLeft) . ' day(s) overdue';
    if ($daysLeft === 0) return 'Due today';
    if ($daysLeft === 1) return '1 day left';
    return $daysLeft . ' days left';
}

function student_deadline_progress_pct(?int $daysLeft): int
{
    if ($daysLeft === null) return 35;
    if ($daysLeft < 0) return 100;
    if ($daysLeft <= 2) return 92;
    if ($daysLeft <= 5) return 68;
    if ($daysLeft <= 10) return 42;
    return 22;
}

function student_action_label(string $status): string
{
    switch ($status) {
        case 'awaiting_summary_review':
            return 'Awaiting Instructor Review';
        case 'awaiting_test':
            return 'Ready for Progress Test';
        case 'remediation_required':
            return 'Remediation Required';
        case 'blocked_deadline':
            return 'Deadline Missed';
        case 'blocked_reason_required':
            return 'Reason Required';
        case 'blocked_reason_rejected':
            return 'Reason Rejected';
        case 'blocked_final':
            return 'Training Blocked';
        case 'completed':
            return 'Completed';
        default:
            return $status !== '' ? $status : 'Status';
    }
}

function student_action_badge_class(string $status): string
{
    if (in_array($status, ['blocked_deadline', 'blocked_final', 'blocked_reason_rejected'], true)) {
        return 'danger';
    }
    if (in_array($status, ['remediation_required', 'blocked_reason_required'], true)) {
        return 'warn';
    }
    if (in_array($status, ['awaiting_summary_review', 'awaiting_test'], true)) {
        return 'info';
    }
    if ($status === 'completed') {
        return 'ok';
    }
    return 'neutral';
}

function student_decision_label(string $decisionCode): string
{
    switch ($decisionCode) {
        case 'approve_additional_attempts':
            return 'Additional Attempts Granted';
        case 'approve_with_summary_revision':
            return 'Summary Revision Required';
        case 'approve_with_one_on_one':
            return 'Instructor Session Required';
        case 'suspend_training':
            return 'Training Suspended';
        default:
            return $decisionCode !== '' ? $decisionCode : 'Instructor Decision';
    }
}

function student_snippet(?string $value, int $max = 180): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    $value = preg_replace('/\s+/', ' ', $value);
    if ($value === null) return '';
    if (mb_strlen($value) <= $max) return $value;
    return rtrim(mb_substr($value, 0, $max - 1)) . '…';
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

/*
|--------------------------------------------------------------------------
| Aggregate dashboard data
|--------------------------------------------------------------------------
*/
$totalLessonsAll = 0;
$passedLessonsAll = 0;
$urgentLessons = [];
$summaryWarnings = [];
$actionItems = [];
$instructorMessages = [];
$primaryCohortId = null;

/*
|--------------------------------------------------------------------------
| Latest instructor communication
|--------------------------------------------------------------------------
*/
$instructorMessagesSt = $pdo->prepare("
    SELECT
        sra.user_id,
        sra.cohort_id,
        sra.lesson_id,
        sra.decision_code,
        sra.decision_notes,
        sra.decision_at,
        sra.granted_extra_attempts,
        sra.summary_revision_required,
        sra.one_on_one_required,
        sra.training_suspended,
        sra.major_intervention_flag,
        l.title AS lesson_title,
        l.external_lesson_id,
        c.name AS cohort_name
    FROM student_required_actions sra
    JOIN lessons l ON l.id = sra.lesson_id
    JOIN cohorts c ON c.id = sra.cohort_id
    WHERE sra.user_id = ?
      AND sra.action_type = 'instructor_approval'
      AND sra.status = 'approved'
      AND (
        sra.decision_at IS NOT NULL
        OR sra.created_at IS NOT NULL
      )
    ORDER BY COALESCE(sra.decision_at, sra.created_at) DESC
    LIMIT 10
");
$instructorMessagesSt->execute([$userId]);
$instructorMessages = $instructorMessagesSt->fetchAll(PDO::FETCH_ASSOC);

$dashboardFlags = [
    'training_suspended' => false,
    'summary_revision_required' => false,
    'one_on_one_required' => false,
    'extra_attempts_granted' => 0,
];

foreach ($instructorMessages as $msg) {
    if (!empty($msg['training_suspended'])) {
        $dashboardFlags['training_suspended'] = true;
    }
    if (!empty($msg['summary_revision_required'])) {
        $dashboardFlags['summary_revision_required'] = true;
    }
    if (!empty($msg['one_on_one_required'])) {
        $dashboardFlags['one_on_one_required'] = true;
    }
    $dashboardFlags['extra_attempts_granted'] += (int)($msg['granted_extra_attempts'] ?? 0);
}

foreach ($cohorts as $idx => $co) {
    $cohortId = (int)$co['id'];

    if ($idx === 0) {
        $primaryCohortId = $cohortId;
    }

    $total = $pdo->prepare("
        SELECT COUNT(*)
        FROM cohort_lesson_deadlines
        WHERE cohort_id = ?
    ");
    $total->execute([$cohortId]);
    $totalLessons = (int)$total->fetchColumn();
    $totalLessonsAll += $totalLessons;

    /*
    |--------------------------------------------------------------------------
    | Keep same baseline logic as current live version:
    | progress based on lesson_activity.status = 'passed'
    |--------------------------------------------------------------------------
    */
    $passed = $pdo->prepare("
        SELECT COUNT(*)
        FROM lesson_activity
        WHERE user_id = ?
          AND status = 'passed'
          AND lesson_id IN (
              SELECT lesson_id
              FROM cohort_lesson_deadlines
              WHERE cohort_id = ?
          )
    ");
    $passed->execute([$userId, $cohortId]);
    $passedLessons = (int)$passed->fetchColumn();
    $passedLessonsAll += $passedLessons;

    $urgentSt = $pdo->prepare("
        SELECT
            d.deadline_utc,
            d.lesson_id,
            l.title,
            l.external_lesson_id,
            la.completion_status,
            la.summary_status,
            la.test_pass_status,
            la.updated_at,
            ? AS cohort_name,
            ? AS cohort_id
        FROM cohort_lesson_deadlines d
        JOIN lessons l ON l.id = d.lesson_id
        LEFT JOIN lesson_activity la
          ON la.user_id = ?
         AND la.cohort_id = ?
         AND la.lesson_id = d.lesson_id
        WHERE d.cohort_id = ?
          AND (
            la.completion_status IS NULL
            OR la.completion_status <> 'completed'
          )
        ORDER BY d.deadline_utc ASC
        LIMIT 12
    ");
    $urgentSt->execute([
        (string)$co['name'],
        $cohortId,
        $userId,
        $cohortId,
        $cohortId
    ]);
    $rows = $urgentSt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $r['days_left'] = student_days_left((string)$r['deadline_utc']);
        $urgentLessons[] = $r;
    }

    $summaryWarnSt = $pdo->prepare("
        SELECT
            ls.review_status,
            ls.review_feedback,
            ls.review_notes_by_instructor,
            ls.updated_at,
            l.title AS lesson_title,
            l.external_lesson_id,
            ? AS cohort_name,
            ? AS cohort_id
        FROM lesson_summaries ls
        JOIN lessons l ON l.id = ls.lesson_id
        WHERE ls.user_id = ?
          AND ls.cohort_id = ?
          AND ls.review_status IN ('needs_revision', 'pending')
        ORDER BY
            CASE ls.review_status
                WHEN 'needs_revision' THEN 0
                WHEN 'pending' THEN 1
                ELSE 2
            END,
            ls.updated_at DESC
        LIMIT 10
    ");
    $summaryWarnSt->execute([
        (string)$co['name'],
        $cohortId,
        $userId,
        $cohortId
    ]);
    $sumRows = $summaryWarnSt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sumRows as $r) {
        $summaryWarnings[] = $r;
    }

    $actionSt = $pdo->prepare("
        SELECT
            la.lesson_id,
            la.completion_status,
            la.summary_status,
            la.test_pass_status,
            la.effective_deadline_utc,
            la.updated_at,
            l.title AS lesson_title,
            l.external_lesson_id,
            ? AS cohort_name,
            ? AS cohort_id
        FROM lesson_activity la
        JOIN lessons l ON l.id = la.lesson_id
        WHERE la.user_id = ?
          AND la.cohort_id = ?
          AND la.completion_status IN (
            'awaiting_summary_review',
            'awaiting_test',
            'remediation_required',
            'blocked_deadline',
            'blocked_reason_required',
            'blocked_reason_rejected',
            'blocked_final'
          )
        ORDER BY la.updated_at DESC
        LIMIT 10
    ");
    $actionSt->execute([
        (string)$co['name'],
        $cohortId,
        $userId,
        $cohortId
    ]);
    $actRows = $actionSt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($actRows as $r) {
        $actionItems[] = $r;
    }
}

$theoryProgressPct = ($totalLessonsAll > 0)
    ? (int)round(($passedLessonsAll / $totalLessonsAll) * 100)
    : 0;

usort($urgentLessons, function ($a, $b) {
    $aDate = strtotime((string)($a['deadline_utc'] ?? ''));
    $bDate = strtotime((string)($b['deadline_utc'] ?? ''));
    return $aDate <=> $bDate;
});
$urgentLessons = array_slice($urgentLessons, 0, 5);

$summaryNeedsRevisionCount = 0;
$summaryPendingCount = 0;
foreach ($summaryWarnings as $sw) {
    if (($sw['review_status'] ?? '') === 'needs_revision') {
        $summaryNeedsRevisionCount++;
    } elseif (($sw['review_status'] ?? '') === 'pending') {
        $summaryPendingCount++;
    }
}

$actionCount = count($actionItems);

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

  .kpi-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(180px,1fr));
    gap:18px;
  }
  .kpi-card{
    padding:22px 24px;
  }
  .kpi-label{
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.12em;
    color:#7b8ba3;
    font-weight:700;
    margin-bottom:14px;
  }
  .kpi-value{
    font-size:38px;
    line-height:1;
    letter-spacing:-0.04em;
    font-weight:800;
    color:#152235;
  }
  .kpi-sub{
    margin-top:10px;
    font-size:14px;
    color:#728198;
    line-height:1.45;
  }

  .panel-grid{
    display:grid;
    grid-template-columns:1.15fr .85fr;
    gap:18px;
  }
  .panel-grid.equal{
    grid-template-columns:1fr 1fr;
  }
  .panel-card{
    padding:22px 24px;
  }
  .panel-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:14px;
    margin-bottom:14px;
  }
  .panel-title{
    margin:0;
    font-size:20px;
    line-height:1.1;
    letter-spacing:-0.02em;
    color:#152235;
  }
  .panel-sub{
    margin-top:6px;
    font-size:14px;
    color:#728198;
    line-height:1.45;
  }

  .progress-meta{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:10px;
  }
  .progress-label{
    font-size:15px;
    color:#415067;
    font-weight:600;
  }
  .progress-shell-student{
    height:14px;
    background:#e9eef5;
    border-radius:999px;
    overflow:hidden;
  }
  .progress-fill-student{
    height:14px;
    border-radius:999px;
    background:linear-gradient(90deg,#123b72 0%, #2f6ac6 100%);
  }

  .urgent-list,
  .action-list,
  .warn-list,
  .message-list{
    display:flex;
    flex-direction:column;
    gap:10px;
  }

  .urgent-item,
  .action-item,
  .warn-item,
  .message-item{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:14px;
    padding:14px 16px;
    border-radius:16px;
    background:#f8fafd;
    border:1px solid rgba(15,23,42,0.05);
  }

  .item-main{
    min-width:0;
  }
  .item-title{
    font-size:14px;
    font-weight:700;
    color:#152235;
    line-height:1.3;
  }
  .item-meta{
    margin-top:5px;
    font-size:13px;
    color:#728198;
    line-height:1.45;
  }
  .item-side{
    flex:0 0 auto;
    text-align:right;
    min-width:120px;
  }

  .badge{
    display:inline-block;
    font-size:11px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.10em;
    padding:6px 8px;
    border-radius:999px;
    white-space:nowrap;
  }
  .badge.ok{background:#dcfce7;color:#22543d}
  .badge.warn{background:#fef3c7;color:#92400e}
  .badge.danger{background:#fee2e2;color:#991b1b}
  .badge.info{background:#dbeafe;color:#1d4ed8}
  .badge.neutral{background:#edf2f7;color:#475569}

  .deadline-progress-shell{
    margin-top:10px;
    height:8px;
    background:#e9eef5;
    border-radius:999px;
    overflow:hidden;
  }
  .deadline-progress-fill{
    height:8px;
    border-radius:999px;
    width:0;
  }
  .deadline-progress-fill.ok{
    background:linear-gradient(90deg,#0f766e 0%, #14b8a6 100%);
  }
  .deadline-progress-fill.warn{
    background:linear-gradient(90deg,#d97706 0%, #f59e0b 100%);
  }
  .deadline-progress-fill.danger{
    background:linear-gradient(90deg,#dc2626 0%, #ef4444 100%);
  }
  .deadline-progress-fill.neutral{
    background:linear-gradient(90deg,#64748b 0%, #94a3b8 100%);
  }

  .mini-actions{
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

  .cohort-progress{
    margin-top:14px;
  }
  .open-row{
    margin-top:18px;
  }

  .priority-banner{
    padding:16px 18px;
    border-radius:16px;
    border:1px solid transparent;
  }
  .priority-banner + .priority-banner{
    margin-top:0;
  }
  .priority-banner.danger{
    border-color:#fecaca;
    background:linear-gradient(180deg,#fff8f8 0%,#fff2f2 100%);
  }
  .priority-banner.warn{
    border-color:#fde68a;
    background:linear-gradient(180deg,#fffdf7 0%,#fff8eb 100%);
  }
  .priority-banner.info{
    border-color:#bfdbfe;
    background:linear-gradient(180deg,#f8fbff 0%,#eff6ff 100%);
  }
  .priority-title{
    font-size:14px;
    font-weight:800;
    margin-bottom:6px;
  }
  .priority-banner.danger .priority-title{color:#991b1b}
  .priority-banner.warn .priority-title{color:#92400e}
  .priority-banner.info .priority-title{color:#1d4ed8}

  .priority-text{
    font-size:14px;
    line-height:1.5;
  }
  .priority-banner.danger .priority-text{color:#7f1d1d}
  .priority-banner.warn .priority-text{color:#78350f}
  .priority-banner.info .priority-text{color:#1e3a8a}

  .message-note{
    margin-top:8px;
    font-size:13px;
    line-height:1.5;
    color:#58677d;
  }

  .empty-premium{
    padding:22px 24px;
    border-radius:18px;
    border:1px dashed rgba(15,23,42,0.12);
    background:linear-gradient(180deg,#ffffff 0%,#fbfcfe 100%);
    color:#728198;
    font-size:15px;
  }

  @media (max-width:1200px){
    .kpi-grid{
      grid-template-columns:repeat(2,minmax(180px,1fr));
    }
    .panel-grid,
    .panel-grid.equal{
      grid-template-columns:1fr;
    }
  }

  @media (max-width:700px){
    .kpi-grid{
      grid-template-columns:1fr;
    }
    .item-side{
      min-width:unset;
    }
  }
</style>

<div class="dash-stack">
  <div class="card hero-card">
    <div class="hero-eyebrow">Student Workspace</div>
    <h2 class="hero-title">Training Overview</h2>
    <div class="hero-sub">
      Track your theory progress, urgent deadlines, and the latest instructor guidance that may affect what you must do next before training can continue.
    </div>
  </div>

  <?php if (!$cohorts): ?>
    <div class="card">
      <div class="empty-premium">No cohort assigned yet.</div>
    </div>
  <?php else: ?>

    <?php if ($dashboardFlags['training_suspended']): ?>
      <div class="priority-banner danger">
        <div class="priority-title">Training temporarily suspended</div>
        <div class="priority-text">
          Your instructor has placed one or more theory items into a suspended state. You cannot continue progression on the affected lesson until your instructor clears the suspension.
        </div>
      </div>
    <?php endif; ?>

    <?php if (!$dashboardFlags['training_suspended'] && $dashboardFlags['summary_revision_required']): ?>
      <div class="priority-banner warn">
        <div class="priority-title">Summary revision required</div>
        <div class="priority-text">
          One or more lesson summaries were sent back for revision. Update the summary first before progress testing can continue on the affected lesson.
        </div>
      </div>
    <?php endif; ?>

    <?php if (!$dashboardFlags['training_suspended'] && $dashboardFlags['one_on_one_required']): ?>
      <div class="priority-banner info">
        <div class="priority-title">Instructor session required</div>
        <div class="priority-text">
          An instructor one-on-one session is required before progression can continue on one or more lessons.
        </div>
      </div>
    <?php endif; ?>

    <?php if ($dashboardFlags['extra_attempts_granted'] > 0): ?>
      <div class="priority-banner info">
        <div class="priority-title">Additional attempts granted</div>
        <div class="priority-text">
          Your instructor granted a total of <?= (int)$dashboardFlags['extra_attempts_granted'] ?> additional progress test attempt(s) across your current intervention items.
        </div>
      </div>
    <?php endif; ?>

    <div class="kpi-grid">
      <div class="card kpi-card">
        <div class="kpi-label">Theory Progress</div>
        <div class="kpi-value"><?= $theoryProgressPct ?>%</div>
        <div class="kpi-sub"><?= $passedLessonsAll ?>/<?= $totalLessonsAll ?> lessons completed across your active theory cohorts.</div>
      </div>

      <div class="card kpi-card">
        <div class="kpi-label">Urgent Lessons</div>
        <div class="kpi-value"><?= count($urgentLessons) ?></div>
        <div class="kpi-sub">Most urgent upcoming lesson deadlines currently visible in your theory path.</div>
      </div>

      <div class="card kpi-card">
        <div class="kpi-label">Summary Warnings</div>
        <div class="kpi-value"><?= $summaryNeedsRevisionCount + $summaryPendingCount ?></div>
        <div class="kpi-sub">Summary items needing revision or currently waiting for instructor review.</div>
      </div>

      <div class="card kpi-card">
        <div class="kpi-label">Action Items</div>
        <div class="kpi-value"><?= $actionCount ?></div>
        <div class="kpi-sub">Current progression states requiring action or awareness.</div>
      </div>
    </div>

    <div class="panel-grid">
      <div class="card panel-card">
        <div class="panel-head">
          <div>
            <h3 class="panel-title">Theory Progress</h3>
            <div class="panel-sub">Your overall completion across currently assigned theory cohorts.</div>
          </div>
        </div>

        <div class="progress-meta">
          <div class="progress-label"><?= $passedLessonsAll ?>/<?= $totalLessonsAll ?> lessons completed</div>
          <div class="badge info"><?= $theoryProgressPct ?>%</div>
        </div>

        <div class="progress-shell-student">
          <div class="progress-fill-student" style="width:<?= $theoryProgressPct ?>%;"></div>
        </div>

        <div style="margin-top:18px;" class="empty-premium">
          Flight Progress will appear here later once the flight training module is enabled.
        </div>
      </div>

      <div class="card panel-card">
        <div class="panel-head">
          <div>
            <h3 class="panel-title">Quick Access</h3>
            <div class="panel-sub">Jump directly into the areas you are most likely to use next.</div>
          </div>
        </div>

        <div class="mini-actions">
          <?php if ($primaryCohortId !== null): ?>
            <a class="mini-action" href="/student/course.php?cohort_id=<?= (int)$primaryCohortId ?>">Open Course</a>
          <?php endif; ?>
          <a class="mini-action" href="/student/dashboard.php">Refresh Overview</a>
        </div>
      </div>
    </div>

    <div class="panel-grid equal">
      <div class="card panel-card">
        <div class="panel-head">
          <div>
            <h3 class="panel-title">Instructor Messages</h3>
            <div class="panel-sub">Latest instructor decisions and guidance affecting your progression.</div>
          </div>
        </div>

        <?php if (!$instructorMessages): ?>
          <div class="empty-premium">No instructor decision messages are currently active.</div>
        <?php else: ?>
          <div class="message-list">
            <?php foreach (array_slice($instructorMessages, 0, 6) as $row): ?>
              <?php
                $decisionLabel = student_decision_label((string)($row['decision_code'] ?? ''));
                $decisionBadgeClass = 'info';
                if (!empty($row['training_suspended'])) {
                    $decisionBadgeClass = 'danger';
                } elseif (!empty($row['summary_revision_required'])) {
                    $decisionBadgeClass = 'warn';
                } elseif (!empty($row['one_on_one_required'])) {
                    $decisionBadgeClass = 'info';
                }
                $notesSnippet = student_snippet((string)($row['decision_notes'] ?? ''), 180);
              ?>
              <div class="message-item">
                <div class="item-main">
                  <div class="item-title">
                    Lesson <?= (int)$row['external_lesson_id'] ?> · <?= h((string)$row['lesson_title']) ?>
                  </div>
                  <div class="item-meta">
                    <?= h((string)$row['cohort_name']) ?> · <?= h(dash_fmt_datetime_student((string)$row['decision_at'])) ?>
                  </div>

                  <?php if ($notesSnippet !== ''): ?>
                    <div class="message-note"><?= h($notesSnippet) ?></div>
                  <?php endif; ?>

                  <?php if ((int)($row['granted_extra_attempts'] ?? 0) > 0): ?>
                    <div class="message-note">Additional attempts granted: <?= (int)$row['granted_extra_attempts'] ?></div>
                  <?php endif; ?>
                </div>

                <div class="item-side">
                  <div class="badge <?= h($decisionBadgeClass) ?>"><?= h($decisionLabel) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="card panel-card">
        <div class="panel-head">
          <div>
            <h3 class="panel-title">Summary Status</h3>
            <div class="panel-sub">Summaries needing revision or waiting for instructor review.</div>
          </div>
        </div>

        <?php if (!$summaryWarnings): ?>
          <div class="empty-premium">No summary review warnings are currently active.</div>
        <?php else: ?>
          <div class="warn-list">
            <?php foreach (array_slice($summaryWarnings, 0, 5) as $row): ?>
              <?php
                $feedbackSnippet = '';
                if ((string)$row['review_status'] === 'needs_revision') {
                    $feedbackSnippet = student_snippet((string)($row['review_notes_by_instructor'] ?: $row['review_feedback']), 160);
                }
              ?>
              <div class="warn-item">
                <div class="item-main">
                  <div class="item-title">
                    Lesson <?= (int)$row['external_lesson_id'] ?> · <?= h((string)$row['lesson_title']) ?>
                  </div>
                  <div class="item-meta">
                    <?= h((string)$row['cohort_name']) ?> · Updated <?= h(dash_fmt_datetime_student((string)$row['updated_at'])) ?>
                  </div>

                  <?php if ($feedbackSnippet !== ''): ?>
                    <div class="message-note"><?= h($feedbackSnippet) ?></div>
                  <?php endif; ?>
                </div>
                <div class="item-side">
                  <?php if ((string)$row['review_status'] === 'needs_revision'): ?>
                    <div class="badge danger">Needs revision</div>
                  <?php else: ?>
                    <div class="badge warn">Pending review</div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel-grid equal">
      <div class="card panel-card">
        <div class="panel-head">
          <div>
            <h3 class="panel-title">5 Most Urgent Lesson Deadlines</h3>
            <div class="panel-sub">The nearest lesson deadlines that deserve your attention first.</div>
          </div>
        </div>

        <?php if (!$urgentLessons): ?>
          <div class="empty-premium">No urgent lesson deadlines found.</div>
        <?php else: ?>
          <div class="urgent-list">
            <?php foreach ($urgentLessons as $row): ?>
              <?php
                $badgeClass = student_deadline_badge_class($row['days_left']);
                $progressClass = student_deadline_progress_class($row['days_left']);
                $progressPct = student_deadline_progress_pct($row['days_left']);
              ?>
              <div class="urgent-item">
                <div class="item-main">
                  <div class="item-title">
                    Lesson <?= (int)$row['external_lesson_id'] ?> · <?= h((string)$row['title']) ?>
                  </div>
                  <div class="item-meta">
                    <?= h((string)$row['cohort_name']) ?> · Due <?= h(dash_fmt_date_student((string)$row['deadline_utc'])) ?>
                  </div>
                  <div class="deadline-progress-shell">
                    <div class="deadline-progress-fill <?= h($progressClass) ?>" style="width:<?= $progressPct ?>%;"></div>
                  </div>
                </div>
                <div class="item-side">
                  <div class="badge <?= h($badgeClass) ?>"><?= h(student_deadline_label($row['days_left'])) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="card panel-card">
        <div class="panel-head">
          <div>
            <h3 class="panel-title">Action Items</h3>
            <div class="panel-sub">Training states that may require your immediate attention.</div>
          </div>
        </div>

        <?php if (!$actionItems): ?>
          <div class="empty-premium">No active action items found right now.</div>
        <?php else: ?>
          <div class="action-list">
            <?php foreach (array_slice($actionItems, 0, 8) as $row): ?>
              <?php
                $status = (string)$row['completion_status'];
                $badgeClass = student_action_badge_class($status);
                $statusLabel = student_action_label($status);
              ?>
              <div class="action-item">
                <div class="item-main">
                  <div class="item-title">
                    Lesson <?= (int)$row['external_lesson_id'] ?> · <?= h((string)$row['lesson_title']) ?>
                  </div>
                  <div class="item-meta">
                    <?= h((string)$row['cohort_name']) ?>
                    <?php if (!empty($row['effective_deadline_utc'])): ?>
                      · Deadline <?= h(dash_fmt_date_student((string)$row['effective_deadline_utc'])) ?>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="item-side">
                  <div class="badge <?= h($badgeClass) ?>"><?= h($statusLabel) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

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
                <h3 class="cohort-title"><?= h((string)$co['course_title']) ?></h3>
                <div class="cohort-sub">
                  Program: <?= h((string)$co['program_key']) ?>
                  · Cohort: <?= h((string)$co['name']) ?>
                  · Start: <?= h(dash_fmt_date_student((string)$co['start_date'])) ?>
                  · End: <?= h(dash_fmt_date_student((string)$co['end_date'])) ?>
                </div>
              </div>

              <div class="cohort-chip"><?= $pct ?>% complete</div>
            </div>

            <div class="cohort-progress">
              <div class="progress-meta">
                <div class="progress-label"><?= $passedLessons ?>/<?= $totalLessons ?> lessons completed</div>
              </div>
              <div class="progress-shell-student">
                <div class="progress-fill-student" style="width:<?= $pct ?>%;"></div>
              </div>
            </div>

            <?php if ($nextRow): ?>
              <div class="item-meta" style="margin-top:14px;">
                Next lesson deadline: <?= h(dash_fmt_date_student((string)$nextRow['deadline_utc'])) ?>
                · Lesson <?= (int)$nextRow['external_lesson_id'] ?>
                · <?= h((string)$nextRow['title']) ?>
              </div>
            <?php endif; ?>

            <div class="open-row">
              <a class="mini-action" href="/student/course.php?cohort_id=<?= $cohortId ?>">
                Open Course
              </a>
            </div>
          </div>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>
</div>

<?php cw_footer(); ?>
