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
    'blocked_students' => 0,
    'deadline_violations' => 0,
    'one_on_one_required' => 0,
    'recent_interventions' => 0,
];

$summaryCards['pending_summary_reviews'] = (int)$pdo->query("
    SELECT COUNT(*)
    FROM lesson_summaries
    WHERE review_status = 'pending'
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

$summaryCards['deadline_violations'] = (int)$pdo->query("
    SELECT COUNT(*)
    FROM progress_tests_v2
    WHERE timing_status = 'after_final_deadline'
")->fetchColumn();

$summaryCards['one_on_one_required'] = (int)$pdo->query("
    SELECT COUNT(*)
    FROM lesson_activity
    WHERE one_on_one_required = 1
      AND one_on_one_completed = 0
")->fetchColumn();

$summaryCards['recent_interventions'] = (int)$pdo->query("
    SELECT COUNT(*)
    FROM student_required_actions
    WHERE action_type = 'instructor_approval'
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

cw_header('Instructor Dashboard');
?>
<style>
  .dash-grid{
    display:grid;
    grid-template-columns:repeat(5,minmax(180px,1fr));
    gap:14px;
    margin-bottom:20px;
  }
  .dash-card{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:16px;
    padding:16px;
    box-shadow:0 8px 24px rgba(0,0,0,0.05);
  }
  .dash-card .label{
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.03em;
    color:#6b7280;
    margin-bottom:8px;
    font-weight:700;
  }
  .dash-card .value{
    font-size:34px;
    line-height:1;
    font-weight:900;
    color:#1e3c72;
  }

  .section-card{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:18px;
    padding:18px;
    box-shadow:0 8px 24px rgba(0,0,0,0.05);
    margin-bottom:18px;
  }
  .section-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:12px;
  }
  .section-head h2{
    margin:0;
    font-size:20px;
    color:#1f2937;
  }
  .count-pill{
    display:inline-block;
    padding:6px 10px;
    border-radius:999px;
    background:#eff6ff;
    color:#1d4ed8;
    font-size:12px;
    font-weight:800;
    border:1px solid #bfdbfe;
  }
  .muted-small{
    font-size:12px;
    color:#6b7280;
  }
  .table-wrap{
    overflow:auto;
  }
  table.dash-table{
    width:100%;
    border-collapse:collapse;
  }
  table.dash-table th,
  table.dash-table td{
    text-align:left;
    padding:10px 8px;
    border-bottom:1px solid #e5e7eb;
    vertical-align:top;
    font-size:14px;
  }
  table.dash-table th{
    font-size:12px;
    color:#6b7280;
    text-transform:uppercase;
    letter-spacing:.03em;
  }
  .status-pill{
    display:inline-block;
    padding:4px 8px;
    border-radius:999px;
    font-size:12px;
    font-weight:800;
    border:1px solid #d1d5db;
    background:#f9fafb;
    color:#374151;
    white-space:nowrap;
  }
  .status-warning{
    background:#fff7ed;
    border-color:#fdba74;
    color:#c2410c;
  }
  .status-danger{
    background:#fef2f2;
    border-color:#fca5a5;
    color:#b91c1c;
  }
  .status-info{
    background:#eff6ff;
    border-color:#93c5fd;
    color:#1d4ed8;
  }
  .action-link{
    display:inline-block;
    padding:6px 10px;
    border-radius:10px;
    background:#1e3c72;
    color:#fff;
    text-decoration:none;
    font-size:12px;
    font-weight:800;
  }
  .action-link:hover{
    opacity:.92;
  }
  @media (max-width: 1100px){
    .dash-grid{
      grid-template-columns:repeat(2,minmax(180px,1fr));
    }
  }
</style>

<div class="dash-grid">
  <div class="dash-card">
    <div class="label">Pending Summary Reviews</div>
    <div class="value"><?= (int)$summaryCards['pending_summary_reviews'] ?></div>
  </div>
  <div class="dash-card">
    <div class="label">Blocked Students</div>
    <div class="value"><?= (int)$summaryCards['blocked_students'] ?></div>
  </div>
  <div class="dash-card">
    <div class="label">Deadline Violations</div>
    <div class="value"><?= (int)$summaryCards['deadline_violations'] ?></div>
  </div>
  <div class="dash-card">
    <div class="label">One-on-One Required</div>
    <div class="value"><?= (int)$summaryCards['one_on_one_required'] ?></div>
  </div>
  <div class="dash-card">
    <div class="label">Interventions Logged</div>
    <div class="value"><?= (int)$summaryCards['recent_interventions'] ?></div>
  </div>
</div>

<div class="section-card">
  <div class="section-head">
    <h2>Pending Summary Reviews</h2>
    <span class="count-pill"><?= count($pendingSummaryReviews) ?> shown</span>
  </div>
  <div class="table-wrap">
    <table class="dash-table">
      <thead>
        <tr>
          <th>Student</th>
          <th>Cohort</th>
          <th>Lesson</th>
          <th>Updated</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$pendingSummaryReviews): ?>
        <tr><td colspan="5" class="muted-small">No pending summary reviews.</td></tr>
      <?php else: ?>
        <?php foreach ($pendingSummaryReviews as $row): ?>
          <tr>
            <td><?= h4((string)$row['student_name']) ?></td>
            <td><?= h4((string)$row['cohort_name']) ?></td>
            <td><?= h4((string)$row['lesson_title']) ?></td>
            <td><?= h4((string)$row['updated_at']) ?></td>
            <td>
              <a class="action-link" href="/instructor/summary_review.php?user_id=<?= (int)$row['user_id'] ?>&cohort_id=<?= (int)$row['cohort_id'] ?>&lesson_id=<?= (int)$row['lesson_id'] ?>">
                Review Summary
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="section-card">
  <div class="section-head">
    <h2>Instructor Interventions</h2>
    <span class="count-pill"><?= count($interventions) ?> shown</span>
  </div>
  <div class="table-wrap">
    <table class="dash-table">
      <thead>
        <tr>
          <th>Student</th>
          <th>Cohort</th>
          <th>Lesson</th>
          <th>Status</th>
          <th>Decision</th>
          <th>Major</th>
          <th>Time</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$interventions): ?>
        <tr><td colspan="8" class="muted-small">No instructor interventions found.</td></tr>
      <?php else: ?>
        <?php foreach ($interventions as $row): ?>
          <?php
            $statusClass = 'status-info';
            if ((string)$row['status'] === 'approved') $statusClass = 'status-info';
            if ((string)$row['status'] === 'pending' || (string)$row['status'] === 'opened') $statusClass = 'status-warning';
          ?>
          <tr>
            <td><?= h4((string)$row['student_name']) ?></td>
            <td><?= h4((string)$row['cohort_name']) ?></td>
            <td><?= h4((string)$row['lesson_title']) ?></td>
            <td><span class="status-pill <?= h4($statusClass) ?>"><?= h4((string)$row['status']) ?></span></td>
            <td><?= h4((string)($row['decision_code'] ?? '—')) ?></td>
            <td><?= !empty($row['major_intervention_flag']) ? 'Yes' : 'No' ?></td>
            <td><?= h4((string)($row['decision_at'] ?: $row['created_at'])) ?></td>
            <td>
              <?php if (!empty($row['token'])): ?>
                <a class="action-link" href="/instructor/instructor_approval.php?token=<?= urlencode((string)$row['token']) ?>">
                  Open
                </a>
              <?php else: ?>
                <span class="muted-small">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="section-card">
  <div class="section-head">
    <h2>Blocked Students</h2>
    <span class="count-pill"><?= count($blockedStudents) ?> shown</span>
  </div>
  <div class="table-wrap">
    <table class="dash-table">
      <thead>
        <tr>
          <th>Student</th>
          <th>Cohort</th>
          <th>Lesson</th>
          <th>Completion Status</th>
          <th>Effective Deadline</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$blockedStudents): ?>
        <tr><td colspan="5" class="muted-small">No blocked students found.</td></tr>
      <?php else: ?>
        <?php foreach ($blockedStudents as $row): ?>
          <?php
            $statusClass = 'status-warning';
            if (in_array((string)$row['completion_status'], ['blocked_deadline', 'blocked_final'], true)) {
                $statusClass = 'status-danger';
            }
          ?>
          <tr>
            <td><?= h4((string)$row['student_name']) ?></td>
            <td><?= h4((string)$row['cohort_name']) ?></td>
            <td><?= h4((string)$row['lesson_title']) ?></td>
            <td><span class="status-pill <?= h4($statusClass) ?>"><?= h4((string)$row['completion_status']) ?></span></td>
            <td><?= h4((string)($row['effective_deadline_utc'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="section-card">
  <div class="section-head">
    <h2>Deadline Violations</h2>
    <span class="count-pill"><?= count($deadlineViolations) ?> shown</span>
  </div>
  <div class="table-wrap">
    <table class="dash-table">
      <thead>
        <tr>
          <th>Student</th>
          <th>Cohort</th>
          <th>Lesson</th>
          <th>Attempt</th>
          <th>Status</th>
          <th>Completed</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$deadlineViolations): ?>
        <tr><td colspan="6" class="muted-small">No deadline violations found.</td></tr>
      <?php else: ?>
        <?php foreach ($deadlineViolations as $row): ?>
          <tr>
            <td><?= h4((string)$row['student_name']) ?></td>
            <td><?= h4((string)$row['cohort_name']) ?></td>
            <td><?= h4((string)$row['lesson_title']) ?></td>
            <td><?= (int)$row['attempt'] ?></td>
            <td><span class="status-pill status-danger"><?= h4((string)$row['timing_status']) ?></span></td>
            <td><?= h4((string)$row['completed_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="section-card">
  <div class="section-head">
    <h2>One-on-One Required</h2>
    <span class="count-pill"><?= count($oneOnOneRequired) ?> shown</span>
  </div>
  <div class="table-wrap">
    <table class="dash-table">
      <thead>
        <tr>
          <th>Student</th>
          <th>Cohort</th>
          <th>Lesson</th>
          <th>Updated</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$oneOnOneRequired): ?>
        <tr><td colspan="4" class="muted-small">No one-on-one sessions currently required.</td></tr>
      <?php else: ?>
        <?php foreach ($oneOnOneRequired as $row): ?>
          <tr>
            <td><?= h4((string)$row['student_name']) ?></td>
            <td><?= h4((string)$row['cohort_name']) ?></td>
            <td><?= h4((string)$row['lesson_title']) ?></td>
            <td><?= h4((string)$row['updated_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php cw_footer(); ?>