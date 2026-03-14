<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/courseware_progression_v2.php';

cw_require_login();

$u = cw_current_user($pdo);
$userId = (int)($u['id'] ?? 0);
$role   = (string)($u['role'] ?? '');

$engine = new CoursewareProgressionV2($pdo);

function h4(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function dash_fmt_date(?string $value): string
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

function dash_fmt_datetime(?string $value): string
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

function can_view_instructor_dashboard(PDO $pdo, CoursewareProgressionV2 $engine, int $userId, string $role): bool
{
    if (in_array($role, ['admin', 'instructor', 'chief_instructor'], true)) {
        return true;
    }

    $cohortIds = $pdo->query("SELECT id FROM cohorts ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cohortIds as $cohortId) {
        $policy = $engine->getAllPolicies([
            'cohort_id' => (int)$cohortId
        ]);
        $chiefInstructorUserId = (int)($policy['chief_instructor_user_id'] ?? 0);
        if ($chiefInstructorUserId > 0 && $chiefInstructorUserId === $userId) {
            return true;
        }
    }

    return false;
}

if (!can_view_instructor_dashboard($pdo, $engine, $userId, $role)) {
    http_response_code(403);
    exit('Forbidden');
}

/*
|--------------------------------------------------------------------------
| Summary cards
|--------------------------------------------------------------------------
*/
$summaryCards = [
    'pending_summary_reviews' => 0,
    'pending_instructor_actions' => 0,
    'blocked_students' => 0,
    'one_on_one_required' => 0,
    'major_intervention_cases' => 0,
];

$summaryCards['pending_summary_reviews'] = (int)$pdo->query("
    SELECT COUNT(*)
    FROM lesson_summaries
    WHERE review_status = 'pending'
")->fetchColumn();

$summaryCards['pending_instructor_actions'] = (int)$pdo->query("
    SELECT COUNT(*)
    FROM student_required_actions
    WHERE action_type = 'instructor_approval'
      AND status IN ('pending', 'opened')
")->fetchColumn();

$summaryCards['blocked_students'] = (int)$pdo->query("
    SELECT COUNT(*)
    FROM lesson_activity
    WHERE completion_status IN (
        'blocked_deadline',
        'blocked_final',
        'remediation_required',
        'awaiting_summary_review'
    )
")->fetchColumn();

$summaryCards['one_on_one_required'] = (int)$pdo->query("
    SELECT COUNT(*)
    FROM lesson_activity
    WHERE one_on_one_required = 1
      AND one_on_one_completed = 0
")->fetchColumn();

$summaryCards['major_intervention_cases'] = (int)$pdo->query("
    SELECT COUNT(*)
    FROM student_required_actions
    WHERE action_type = 'instructor_approval'
      AND major_intervention_flag = 1
")->fetchColumn();

/*
|--------------------------------------------------------------------------
| Section A — Pending Summary Reviews
|--------------------------------------------------------------------------
*/
$pendingSummaryReviewsSt = $pdo->prepare("
    SELECT
        ls.user_id,
        ls.cohort_id,
        ls.lesson_id,
        ls.updated_at,
        u.name AS student_name,
        c.name AS cohort_name,
        l.title AS lesson_title
    FROM lesson_summaries ls
    JOIN users u   ON u.id = ls.user_id
    JOIN cohorts c ON c.id = ls.cohort_id
    JOIN lessons l ON l.id = ls.lesson_id
    WHERE ls.review_status = 'pending'
    ORDER BY ls.updated_at ASC
    LIMIT 50
");
$pendingSummaryReviewsSt->execute();
$pendingSummaryReviews = $pendingSummaryReviewsSt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Section B — Instructor Interventions
|--------------------------------------------------------------------------
*/
$interventionsSt = $pdo->prepare("
    SELECT
        sra.id,
        sra.user_id,
        sra.cohort_id,
        sra.lesson_id,
        sra.status,
        sra.decision_code,
        sra.major_intervention_flag,
        sra.decision_at,
        sra.created_at,
        sra.token,
        u.name AS student_name,
        c.name AS cohort_name,
        l.title AS lesson_title
    FROM student_required_actions sra
    JOIN users u   ON u.id = sra.user_id
    JOIN cohorts c ON c.id = sra.cohort_id
    JOIN lessons l ON l.id = sra.lesson_id
    WHERE sra.action_type = 'instructor_approval'
    ORDER BY COALESCE(sra.decision_at, sra.created_at) DESC
    LIMIT 50
");
$interventionsSt->execute();
$interventions = $interventionsSt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Section C — Blocked Students
|--------------------------------------------------------------------------
*/
$blockedStudentsSt = $pdo->prepare("
    SELECT
        la.user_id,
        la.cohort_id,
        la.lesson_id,
        la.completion_status,
        la.effective_deadline_utc,
        la.updated_at,
        u.name AS student_name,
        c.name AS cohort_name,
        l.title AS lesson_title
    FROM lesson_activity la
    JOIN users u   ON u.id = la.user_id
    JOIN cohorts c ON c.id = la.cohort_id
    JOIN lessons l ON l.id = la.lesson_id
    WHERE la.completion_status IN (
        'blocked_deadline',
        'blocked_final',
        'remediation_required',
        'awaiting_summary_review'
    )
    ORDER BY la.updated_at DESC
    LIMIT 50
");
$blockedStudentsSt->execute();
$blockedStudents = $blockedStudentsSt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Section D — Deadline Violations
|--------------------------------------------------------------------------
*/
$deadlineViolationsSt = $pdo->prepare("
    SELECT
        pt.user_id,
        pt.cohort_id,
        pt.lesson_id,
        pt.attempt,
        pt.timing_status,
        pt.completed_at,
        u.name AS student_name,
        c.name AS cohort_name,
        l.title AS lesson_title
    FROM progress_tests_v2 pt
    JOIN users u   ON u.id = pt.user_id
    JOIN cohorts c ON c.id = pt.cohort_id
    JOIN lessons l ON l.id = pt.lesson_id
    WHERE pt.timing_status = 'after_final_deadline'
    ORDER BY pt.completed_at DESC
    LIMIT 50
");
$deadlineViolationsSt->execute();
$deadlineViolations = $deadlineViolationsSt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Section E — One-on-One Sessions Required
|--------------------------------------------------------------------------
*/
$oneOnOneRequiredSt = $pdo->prepare("
    SELECT
        la.user_id,
        la.cohort_id,
        la.lesson_id,
        la.updated_at,
        u.name AS student_name,
        c.name AS cohort_name,
        l.title AS lesson_title
    FROM lesson_activity la
    JOIN users u   ON u.id = la.user_id
    JOIN cohorts c ON c.id = la.cohort_id
    JOIN lessons l ON l.id = la.lesson_id
    WHERE la.one_on_one_required = 1
      AND la.one_on_one_completed = 0
    ORDER BY la.updated_at DESC
    LIMIT 50
");
$oneOnOneRequiredSt->execute();
$oneOnOneRequired = $oneOnOneRequiredSt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Derived instructor work queues
|--------------------------------------------------------------------------
*/
$pendingInterventionActions = array_values(array_filter($interventions, function ($row) {
    return in_array((string)$row['status'], ['pending', 'opened'], true);
}));

$majorInterventionRows = array_values(array_filter($interventions, function ($row) {
    return !empty($row['major_intervention_flag']);
}));

/*
|--------------------------------------------------------------------------
| Unified attention queue
|--------------------------------------------------------------------------
*/
$attentionNow = [];

/* summary review items */
foreach (array_slice($pendingSummaryReviews, 0, 8) as $row) {
    $attentionNow[] = [
        'type' => 'review',
        'label' => 'Review Needed',
        'time' => (string)$row['updated_at'],
        'student_name' => (string)$row['student_name'],
        'cohort_name' => (string)$row['cohort_name'],
        'lesson_title' => (string)$row['lesson_title'],
        'action_href' => '/instructor/summary_review.php?user_id=' . (int)$row['user_id'] . '&cohort_id=' . (int)$row['cohort_id'] . '&lesson_id=' . (int)$row['lesson_id'],
        'action_label' => 'Review',
    ];
}

/* pending intervention items */
foreach (array_slice($pendingInterventionActions, 0, 8) as $row) {
    $attentionNow[] = [
        'type' => 'decision',
        'label' => 'Decision Required',
        'time' => (string)($row['decision_at'] ?: $row['created_at']),
        'student_name' => (string)$row['student_name'],
        'cohort_name' => (string)$row['cohort_name'],
        'lesson_title' => (string)$row['lesson_title'],
        'action_href' => !empty($row['token']) ? '/instructor/instructor_approval.php?token=' . urlencode((string)$row['token']) : '',
        'action_label' => !empty($row['token']) ? 'Open' : '',
    ];
}

/* one-on-one items */
foreach (array_slice($oneOnOneRequired, 0, 8) as $row) {
    $attentionNow[] = [
        'type' => 'session',
        'label' => 'Session Required',
        'time' => (string)$row['updated_at'],
        'student_name' => (string)$row['student_name'],
        'cohort_name' => (string)$row['cohort_name'],
        'lesson_title' => (string)$row['lesson_title'],
        'action_href' => '',
        'action_label' => '',
    ];
}

usort($attentionNow, function ($a, $b) {
    return strcmp((string)$a['time'], (string)$b['time']);
});
$attentionNow = array_slice($attentionNow, 0, 10);

cw_header('Dashboard');
?>
<style>
  .dash-stack{display:flex;flex-direction:column;gap:22px}

  .hero-card{padding:26px 28px}
  .hero-eyebrow{
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.14em;
    color:#7b8ba3;
    font-weight:700;
    margin-bottom:10px;
  }
  .hero-title{
    margin:0;
    font-size:32px;
    line-height:1.02;
    letter-spacing:-0.04em;
    color:#152235;
    font-weight:800;
  }
  .hero-sub{
    margin-top:12px;
    font-size:15px;
    color:#6f7f95;
    max-width:860px;
    line-height:1.55;
  }

  .kpi-grid{
    display:grid;
    grid-template-columns:repeat(5,minmax(180px,1fr));
    gap:18px;
  }
  .kpi-card{padding:22px 24px}
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

  .signal-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(220px,1fr));
    gap:18px;
  }
  .signal-card{
    padding:20px 22px;
    border-radius:20px;
    background:#fff;
    border:1px solid rgba(15,23,42,0.06);
    box-shadow:0 10px 24px rgba(15,23,42,0.055);
  }
  .signal-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
  }
  .signal-title{
    font-size:14px;
    font-weight:700;
    color:#152235;
    line-height:1.2;
  }
  .signal-value{
    font-size:34px;
    line-height:1;
    letter-spacing:-0.04em;
    font-weight:800;
    color:#152235;
    margin-top:12px;
  }
  .signal-note{
    margin-top:10px;
    font-size:13px;
    color:#728198;
    line-height:1.45;
  }
  .signal-pill{
    font-size:11px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.10em;
    padding:6px 8px;
    border-radius:999px;
    white-space:nowrap;
  }
  .signal-pill.ok{color:#22543d;background:#dcfce7}
  .signal-pill.warn{color:#92400e;background:#fef3c7}
  .signal-pill.alert{color:#991b1b;background:#fee2e2}

  .panel-grid{
    display:grid;
    grid-template-columns:1.2fr .8fr;
    gap:18px;
  }
  .panel-grid.equal{
    grid-template-columns:1fr 1fr;
  }
  .panel-card{padding:22px 24px}
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

  .queue-list{
    display:flex;
    flex-direction:column;
    gap:10px;
  }
  .queue-item{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:14px;
    padding:14px 16px;
    border-radius:16px;
    background:#f8fafd;
    border:1px solid rgba(15,23,42,0.05);
  }
  .queue-main{min-width:0}
  .queue-title{
    font-size:14px;
    font-weight:700;
    color:#152235;
    line-height:1.3;
  }
  .queue-meta{
    margin-top:5px;
    font-size:13px;
    color:#728198;
    line-height:1.45;
  }
  .queue-side{
    text-align:right;
    flex:0 0 auto;
  }
  .queue-status{
    display:inline-block;
    font-size:11px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.10em;
    padding:6px 8px;
    border-radius:999px;
  }
  .queue-status.review{background:#fef3c7;color:#92400e}
  .queue-status.decision{background:#dbeafe;color:#1d4ed8}
  .queue-status.session{background:#eff6ff;color:#1d4ed8}
  .queue-status.major{background:#fee2e2;color:#991b1b}
  .queue-status.overdue{background:#fee2e2;color:#b91c1c}
  .queue-status.blocked{background:#fff7ed;color:#c2410c}

  .queue-time{
    margin-top:8px;
    font-size:12px;
    color:#8a97ab;
    white-space:nowrap;
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
  .mini-action:hover{background:#edf2f8}

  .action-link{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:38px;
    padding:0 12px;
    border-radius:10px;
    background:#123b72;
    color:#fff;
    text-decoration:none;
    font-size:12px;
    font-weight:800;
    white-space:nowrap;
  }
  .action-link:hover{opacity:.94}

  .empty-premium{
    padding:18px;
    border-radius:16px;
    border:1px dashed rgba(15,23,42,0.12);
    background:linear-gradient(180deg,#ffffff 0%,#fbfcfe 100%);
    color:#728198;
    font-size:14px;
  }

  .status-pill{
    display:inline-block;
    padding:5px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:800;
    border:1px solid #d7dee9;
    background:#f9fbfd;
    color:#415067;
    white-space:nowrap;
  }
  .status-warning{background:#fff7ed;border-color:#fed7aa;color:#c2410c}
  .status-danger{background:#fef2f2;border-color:#fecaca;color:#b91c1c}
  .status-info{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}

  @media (max-width:1300px){
    .kpi-grid{grid-template-columns:repeat(3,minmax(180px,1fr))}
  }

  @media (max-width:1200px){
    .signal-grid{grid-template-columns:1fr}
    .panel-grid,
    .panel-grid.equal{grid-template-columns:1fr}
  }

  @media (max-width:700px){
    .kpi-grid{grid-template-columns:1fr}
  }
</style>

<div class="dash-stack">

  <div class="card hero-card">
    <div class="hero-eyebrow">Instructor Workspace</div>
    <h2 class="hero-title">Training Oversight Dashboard</h2>
    <div class="hero-sub">
      Review student friction points, process summary reviews, handle intervention decisions, and keep theory progression moving without jumping through separate pages.
    </div>
  </div>

  <div class="kpi-grid">
    <div class="card kpi-card">
      <div class="kpi-label">Pending Summary Reviews</div>
      <div class="kpi-value"><?= (int)$summaryCards['pending_summary_reviews'] ?></div>
      <div class="kpi-sub">Summaries waiting for instructor review before students can continue.</div>
    </div>

    <div class="card kpi-card">
      <div class="kpi-label">Pending Instructor Actions</div>
      <div class="kpi-value"><?= (int)$summaryCards['pending_instructor_actions'] ?></div>
      <div class="kpi-sub">Intervention approval items still waiting for a decision.</div>
    </div>

    <div class="card kpi-card">
      <div class="kpi-label">Blocked Students</div>
      <div class="kpi-value"><?= (int)$summaryCards['blocked_students'] ?></div>
      <div class="kpi-sub">Students currently blocked by remediation, deadlines, or review states.</div>
    </div>

    <div class="card kpi-card">
      <div class="kpi-label">One-on-One Required</div>
      <div class="kpi-value"><?= (int)$summaryCards['one_on_one_required'] ?></div>
      <div class="kpi-sub">Students requiring instructor session before progression can resume.</div>
    </div>

    <div class="card kpi-card">
      <div class="kpi-label">Major Intervention Cases</div>
      <div class="kpi-value"><?= (int)$summaryCards['major_intervention_cases'] ?></div>
      <div class="kpi-sub">Escalation-relevant intervention records that need awareness.</div>
    </div>
  </div>

  <div class="signal-grid">
    <div class="signal-card">
      <div class="signal-head">
        <div class="signal-title">Summary Review Queue</div>
        <div class="signal-pill <?= $summaryCards['pending_summary_reviews'] > 0 ? 'warn' : 'ok' ?>">
          <?= $summaryCards['pending_summary_reviews'] > 0 ? 'Action' : 'Clear' ?>
        </div>
      </div>
      <div class="signal-value"><?= (int)$summaryCards['pending_summary_reviews'] ?></div>
      <div class="signal-note">Instructor review work currently waiting in the summary flow.</div>
    </div>

    <div class="signal-card">
      <div class="signal-head">
        <div class="signal-title">Decision Queue</div>
        <div class="signal-pill <?= $summaryCards['pending_instructor_actions'] > 0 ? 'alert' : 'ok' ?>">
          <?= $summaryCards['pending_instructor_actions'] > 0 ? 'Urgent' : 'Clear' ?>
        </div>
      </div>
      <div class="signal-value"><?= (int)$summaryCards['pending_instructor_actions'] ?></div>
      <div class="signal-note">Instructor approval actions still waiting for intervention handling.</div>
    </div>

    <div class="signal-card">
      <div class="signal-head">
        <div class="signal-title">Major Cases</div>
        <div class="signal-pill <?= $summaryCards['major_intervention_cases'] > 0 ? 'warn' : 'ok' ?>">
          <?= $summaryCards['major_intervention_cases'] > 0 ? 'Monitor' : 'Low' ?>
        </div>
      </div>
      <div class="signal-value"><?= (int)$summaryCards['major_intervention_cases'] ?></div>
      <div class="signal-note">Cases flagged as major and relevant for escalation or training concern tracking.</div>
    </div>
  </div>

  <div class="panel-grid">
    <div class="card panel-card">
      <div class="panel-head">
        <div>
          <h3 class="panel-title">Attention Now</h3>
          <div class="panel-sub">The most immediate instructor workload items requiring action.</div>
        </div>
      </div>

      <?php if (!$attentionNow): ?>
        <div class="empty-premium">No immediate instructor attention items are currently waiting.</div>
      <?php else: ?>
        <div class="queue-list">
          <?php foreach ($attentionNow as $row): ?>
            <div class="queue-item">
              <div class="queue-main">
                <div class="queue-title"><?= h4((string)$row['student_name']) ?> · <?= h4((string)$row['lesson_title']) ?></div>
                <div class="queue-meta"><?= h4((string)$row['cohort_name']) ?></div>
              </div>
              <div class="queue-side">
                <div class="queue-status <?= h4((string)$row['type']) ?>"><?= h4((string)$row['label']) ?></div>
                <?php if (!empty($row['action_href']) && !empty($row['action_label'])): ?>
                  <div style="margin-top:8px;">
                    <a class="action-link" href="<?= h4((string)$row['action_href']) ?>"><?= h4((string)$row['action_label']) ?></a>
                  </div>
                <?php endif; ?>
                <div class="queue-time"><?= h4(dash_fmt_datetime((string)$row['time'])) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card panel-card">
      <div class="panel-head">
        <div>
          <h3 class="panel-title">Quick Access</h3>
          <div class="panel-sub">Jump directly into the instructor area used most often.</div>
        </div>
      </div>

      <div class="mini-actions">
        <a class="mini-action" href="/instructor/cohorts.php">Cohorts</a>
      </div>

      <div class="empty-premium" style="margin-top:14px;">
        More direct queue pages can be added here later once dedicated instructor queue pages exist as first-class screens.
      </div>
    </div>
  </div>

  <div class="panel-grid equal">
    <div class="card panel-card">
      <div class="panel-head">
        <div>
          <h3 class="panel-title">Major Intervention Cases</h3>
          <div class="panel-sub">Cases flagged as major and worth elevated instructor awareness.</div>
        </div>
      </div>

      <?php if (!$majorInterventionRows): ?>
        <div class="empty-premium">No major intervention cases are currently flagged.</div>
      <?php else: ?>
        <div class="queue-list">
          <?php foreach (array_slice($majorInterventionRows, 0, 8) as $row): ?>
            <div class="queue-item">
              <div class="queue-main">
                <div class="queue-title"><?= h4((string)$row['student_name']) ?> · <?= h4((string)$row['lesson_title']) ?></div>
                <div class="queue-meta">
                  <?= h4((string)$row['cohort_name']) ?>
                  <?php if (!empty($row['decision_code'])): ?>
                    · <?= h4((string)$row['decision_code']) ?>
                  <?php endif; ?>
                </div>
              </div>
              <div class="queue-side">
                <div class="queue-status major">Major Case</div>
                <?php if (!empty($row['token'])): ?>
                  <div style="margin-top:8px;">
                    <a class="action-link" href="/instructor/instructor_approval.php?token=<?= urlencode((string)$row['token']) ?>">Open</a>
                  </div>
                <?php endif; ?>
                <div class="queue-time"><?= h4(dash_fmt_datetime((string)($row['decision_at'] ?: $row['created_at']))) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card panel-card">
      <div class="panel-head">
        <div>
          <h3 class="panel-title">Deadline Violations</h3>
          <div class="panel-sub">Progress tests recorded after final deadline status.</div>
        </div>
      </div>

      <?php if (!$deadlineViolations): ?>
        <div class="empty-premium">No deadline violations found.</div>
      <?php else: ?>
        <div class="queue-list">
          <?php foreach (array_slice($deadlineViolations, 0, 8) as $row): ?>
            <div class="queue-item">
              <div class="queue-main">
                <div class="queue-title"><?= h4((string)$row['student_name']) ?> · <?= h4((string)$row['lesson_title']) ?></div>
                <div class="queue-meta"><?= h4((string)$row['cohort_name']) ?> · Attempt <?= (int)$row['attempt'] ?></div>
              </div>
              <div class="queue-side">
                <div class="queue-status overdue">Deadline Breach</div>
                <div class="queue-time"><?= h4(dash_fmt_datetime((string)$row['completed_at'])) ?></div>
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
          <h3 class="panel-title">Blocked Students</h3>
          <div class="panel-sub">Students blocked by current theory progression gates.</div>
        </div>
      </div>

      <?php if (!$blockedStudents): ?>
        <div class="empty-premium">No blocked students found.</div>
      <?php else: ?>
        <div class="queue-list">
          <?php foreach (array_slice($blockedStudents, 0, 10) as $row): ?>
            <?php
              $badgeLabel = 'Blocked';
              if ((string)$row['completion_status'] === 'awaiting_summary_review') {
                  $badgeLabel = 'Awaiting Review';
              } elseif ((string)$row['completion_status'] === 'remediation_required') {
                  $badgeLabel = 'Remediation';
              } elseif ((string)$row['completion_status'] === 'blocked_deadline') {
                  $badgeLabel = 'Deadline Block';
              } elseif ((string)$row['completion_status'] === 'blocked_final') {
                  $badgeLabel = 'Final Block';
              }
            ?>
            <div class="queue-item">
              <div class="queue-main">
                <div class="queue-title"><?= h4((string)$row['student_name']) ?> · <?= h4((string)$row['lesson_title']) ?></div>
                <div class="queue-meta"><?= h4((string)$row['cohort_name']) ?></div>
              </div>
              <div class="queue-side">
                <div class="queue-status blocked"><?= h4($badgeLabel) ?></div>
                <div class="queue-time"><?= h4(dash_fmt_date((string)($row['effective_deadline_utc'] ?? ''))) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card panel-card">
      <div class="panel-head">
        <div>
          <h3 class="panel-title">One-on-One Required</h3>
          <div class="panel-sub">Students requiring instructor session before progression continues.</div>
        </div>
      </div>

      <?php if (!$oneOnOneRequired): ?>
        <div class="empty-premium">No one-on-one sessions currently required.</div>
      <?php else: ?>
        <div class="queue-list">
          <?php foreach (array_slice($oneOnOneRequired, 0, 10) as $row): ?>
            <div class="queue-item">
              <div class="queue-main">
                <div class="queue-title"><?= h4((string)$row['student_name']) ?> · <?= h4((string)$row['lesson_title']) ?></div>
                <div class="queue-meta"><?= h4((string)$row['cohort_name']) ?></div>
              </div>
              <div class="queue-side">
                <div class="queue-status session">Session Required</div>
                <div class="queue-time"><?= h4(dash_fmt_datetime((string)$row['updated_at'])) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php cw_footer(); ?>
