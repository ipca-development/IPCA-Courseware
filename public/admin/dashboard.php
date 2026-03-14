<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_admin();

function cw_dash_scalar(PDO $pdo, string $sql, array $params = array(), int $default = 0): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $v = $stmt->fetchColumn();
        return ($v !== false && $v !== null) ? (int)$v : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function cw_dash_rows(PDO $pdo, string $sql, array $params = array()): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    } catch (Throwable $e) {
        return array();
    }
}

function cw_human_dt(?string $dt): string
{
    if (!$dt) return '—';
    $ts = strtotime($dt);
    if (!$ts) return '—';
    return date('M j, Y · H:i', $ts);
}

function cw_status_class(string $status): string
{
    $status = strtolower(trim($status));

    if (in_array($status, array('error', 'failed', 'danger', 'critical'), true)) {
        return 'queue-status is-danger';
    }

    if (in_array($status, array('warning', 'warn', 'pending', 'opened', 'needs_revision'), true)) {
        return 'queue-status is-warn';
    }

    if (in_array($status, array('success', 'completed', 'approved', 'ok'), true)) {
        return 'queue-status is-ok';
    }

    return 'queue-status is-neutral';
}

/*
|--------------------------------------------------------------------------
| Core content metrics
|--------------------------------------------------------------------------
*/
$stats = array(
    'courses' => cw_dash_scalar($pdo, "SELECT COUNT(*) FROM courses"),
    'lessons' => cw_dash_scalar($pdo, "SELECT COUNT(*) FROM lessons"),
    'slides'  => cw_dash_scalar($pdo, "SELECT COUNT(*) FROM slides WHERE is_deleted = 0"),
    'cohorts' => cw_dash_scalar($pdo, "SELECT COUNT(*) FROM cohorts"),
    'students_active' => cw_dash_scalar($pdo, "SELECT COUNT(*) FROM cohort_students WHERE status = 'active'")
);

/*
|--------------------------------------------------------------------------
| Operational attention metrics
|--------------------------------------------------------------------------
*/
$attention = array(
    'summary_attention_queue' => cw_dash_scalar(
        $pdo,
        "SELECT COUNT(*) FROM lesson_summaries WHERE review_status IN ('pending','needs_revision')"
    ),

    'pending_instructor_actions' => cw_dash_scalar(
        $pdo,
        "SELECT COUNT(*) FROM student_required_actions
         WHERE action_type = 'instructor_approval'
           AND status IN ('pending','opened')"
    ),

    'blocked_students' => cw_dash_scalar(
        $pdo,
        "SELECT COUNT(DISTINCT user_id) FROM lesson_activity
         WHERE completion_status IN (
            'remediation_required',
            'awaiting_summary_review',
            'blocked_deadline',
            'blocked_reason_required',
            'blocked_reason_rejected',
            'blocked_final'
         )"
    ),

    'overdue_lessons' => cw_dash_scalar(
        $pdo,
        "SELECT COUNT(*) FROM lesson_activity
         WHERE effective_deadline_utc IS NOT NULL
           AND effective_deadline_utc < UTC_TIMESTAMP()
           AND completion_status <> 'completed'"
    ),

    'summary_revisions_required' => cw_dash_scalar(
        $pdo,
        "SELECT COUNT(*) FROM lesson_summaries WHERE review_status = 'needs_revision'"
    ),

    'major_interventions' => cw_dash_scalar(
        $pdo,
        "SELECT COUNT(*) FROM student_required_actions
         WHERE major_intervention_flag = 1"
    )
);

/*
|--------------------------------------------------------------------------
| Recent activity
|--------------------------------------------------------------------------
*/
$recentEvents = cw_dash_rows(
    $pdo,
    "SELECT
        tpe.event_time,
        tpe.event_code,
        tpe.event_status,
        u.name AS student_name,
        c.name AS cohort_name,
        l.title AS lesson_title
     FROM training_progression_events tpe
     LEFT JOIN users u   ON u.id = tpe.user_id
     LEFT JOIN cohorts c ON c.id = tpe.cohort_id
     LEFT JOIN lessons l ON l.id = tpe.lesson_id
     ORDER BY tpe.event_time DESC
     LIMIT 8"
);

/*
|--------------------------------------------------------------------------
| Summary attention queue
|--------------------------------------------------------------------------
*/
$summaryQueue = cw_dash_rows(
    $pdo,
    "SELECT
        ls.review_status,
        ls.updated_at,
        u.name AS student_name,
        c.name AS cohort_name,
        l.title AS lesson_title
     FROM lesson_summaries ls
     INNER JOIN users u   ON u.id = ls.user_id
     INNER JOIN cohorts c ON c.id = ls.cohort_id
     INNER JOIN lessons l ON l.id = ls.lesson_id
     WHERE ls.review_status IN ('pending','needs_revision')
     ORDER BY
        CASE ls.review_status
            WHEN 'needs_revision' THEN 0
            WHEN 'pending' THEN 1
            ELSE 2
        END,
        ls.updated_at DESC
     LIMIT 8"
);

/*
|--------------------------------------------------------------------------
| Pending instructor approvals
|--------------------------------------------------------------------------
*/
$approvalQueue = cw_dash_rows(
    $pdo,
    "SELECT
        sra.status,
        sra.created_at,
        u.name AS student_name,
        c.name AS cohort_name,
        l.title AS lesson_title
     FROM student_required_actions sra
     INNER JOIN users u   ON u.id = sra.user_id
     INNER JOIN cohorts c ON c.id = sra.cohort_id
     INNER JOIN lessons l ON l.id = sra.lesson_id
     WHERE sra.action_type = 'instructor_approval'
       AND sra.status IN ('pending','opened')
     ORDER BY sra.created_at ASC
     LIMIT 8"
);

cw_header('Dashboard');
?>
<style>
  .dash-stack{display:flex;flex-direction:column;gap:22px}
  .hero-card{padding:26px 28px}
  .hero-eyebrow{
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.14em;
    color:#7a8aa2;
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
  .signal-pill.ok{
    color:#22543d;
    background:#dcfce7;
  }
  .signal-pill.warn{
    color:#92400e;
    background:#fef3c7;
  }
  .signal-pill.alert{
    color:#991b1b;
    background:#fee2e2;
  }

  .panel-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:18px;
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
  .queue-main{
    min-width:0;
  }
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
    white-space:nowrap;
  }
  .queue-status.is-neutral{
    background:#edf2f7;
    color:#475569;
  }
  .queue-status.is-ok{
    background:#dcfce7;
    color:#166534;
  }
  .queue-status.is-warn{
    background:#fef3c7;
    color:#92400e;
  }
  .queue-status.is-danger{
    background:#fee2e2;
    color:#991b1b;
  }
  .queue-time{
    margin-top:8px;
    font-size:12px;
    color:#8a97ab;
    white-space:nowrap;
  }

  .empty-premium{
    padding:18px;
    border-radius:16px;
    border:1px dashed rgba(15,23,42,0.12);
    background:linear-gradient(180deg,#ffffff 0%,#fbfcfe 100%);
    color:#728198;
    font-size:14px;
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

  @media (max-width:1400px){
    .kpi-grid{grid-template-columns:repeat(3,minmax(180px,1fr))}
  }

  @media (max-width:1200px){
    .signal-grid{grid-template-columns:1fr}
    .panel-grid{grid-template-columns:1fr}
  }

  @media (max-width:700px){
    .kpi-grid{grid-template-columns:1fr}
  }
</style>

<div class="dash-stack">

  <div class="card hero-card">
    <div class="hero-eyebrow">Admin Workspace</div>
    <h2 class="hero-title">Aviation Training Control Center</h2>
    <div class="hero-sub">
      Monitor operational friction, student progression blockers, summary review workload, and overall courseware readiness from one central command view.
    </div>
  </div>

  <div class="kpi-grid">
    <div class="card kpi-card">
      <div class="kpi-label">Courses</div>
      <div class="kpi-value"><?= $stats['courses'] ?></div>
      <div class="kpi-sub">Configured course structures across all programs.</div>
    </div>

    <div class="card kpi-card">
      <div class="kpi-label">Lessons</div>
      <div class="kpi-value"><?= $stats['lessons'] ?></div>
      <div class="kpi-sub">Instructional lesson units currently in the platform.</div>
    </div>

    <div class="card kpi-card">
      <div class="kpi-label">Slides</div>
      <div class="kpi-value"><?= $stats['slides'] ?></div>
      <div class="kpi-sub">Active rendered slide pages available to students.</div>
    </div>

    <div class="card kpi-card">
      <div class="kpi-label">Active Cohorts</div>
      <div class="kpi-value"><?= $stats['cohorts'] ?></div>
      <div class="kpi-sub">Cohort instances currently configured inside the platform.</div>
    </div>

    <div class="card kpi-card">
      <div class="kpi-label">Active Students</div>
      <div class="kpi-value"><?= $stats['students_active'] ?></div>
      <div class="kpi-sub">Students currently enrolled in active cohort lifecycle.</div>
    </div>
  </div>

  <div class="signal-grid">
    <div class="signal-card">
      <div class="signal-head">
        <div class="signal-title">Summary Attention Queue</div>
        <div class="signal-pill <?= $attention['summary_attention_queue'] > 0 ? 'warn' : 'ok' ?>">
          <?= $attention['summary_attention_queue'] > 0 ? 'Action' : 'Clear' ?>
        </div>
      </div>
      <div class="signal-value"><?= $attention['summary_attention_queue'] ?></div>
      <div class="signal-note">Summaries currently awaiting instructor review or still sitting in revision-required state.</div>
    </div>

    <div class="signal-card">
      <div class="signal-head">
        <div class="signal-title">Pending Instructor Actions</div>
        <div class="signal-pill <?= $attention['pending_instructor_actions'] > 0 ? 'alert' : 'ok' ?>">
          <?= $attention['pending_instructor_actions'] > 0 ? 'Urgent' : 'Clear' ?>
        </div>
      </div>
      <div class="signal-value"><?= $attention['pending_instructor_actions'] ?></div>
      <div class="signal-note">Intervention items requiring instructor escalation or approval action.</div>
    </div>

    <div class="signal-card">
      <div class="signal-head">
        <div class="signal-title">Blocked Students</div>
        <div class="signal-pill <?= $attention['blocked_students'] > 0 ? 'warn' : 'ok' ?>">
          <?= $attention['blocked_students'] > 0 ? 'Blocked' : 'Open' ?>
        </div>
      </div>
      <div class="signal-value"><?= $attention['blocked_students'] ?></div>
      <div class="signal-note">Students currently blocked by deadlines, remediation, review, or final-state gates.</div>
    </div>

    <div class="signal-card">
      <div class="signal-head">
        <div class="signal-title">Overdue Lessons</div>
        <div class="signal-pill <?= $attention['overdue_lessons'] > 0 ? 'alert' : 'ok' ?>">
          <?= $attention['overdue_lessons'] > 0 ? 'Overdue' : 'On Track' ?>
        </div>
      </div>
      <div class="signal-value"><?= $attention['overdue_lessons'] ?></div>
      <div class="signal-note">Lesson activity records with passed deadlines that remain incomplete.</div>
    </div>

    <div class="signal-card">
      <div class="signal-head">
        <div class="signal-title">Summary Revisions Required</div>
        <div class="signal-pill <?= $attention['summary_revisions_required'] > 0 ? 'warn' : 'ok' ?>">
          <?= $attention['summary_revisions_required'] > 0 ? 'Revision' : 'Clear' ?>
        </div>
      </div>
      <div class="signal-value"><?= $attention['summary_revisions_required'] ?></div>
      <div class="signal-note">Summaries sent back to students for further revision before testing can continue.</div>
    </div>

    <div class="signal-card">
      <div class="signal-head">
        <div class="signal-title">Major Interventions</div>
        <div class="signal-pill <?= $attention['major_interventions'] > 0 ? 'warn' : 'ok' ?>">
          <?= $attention['major_interventions'] > 0 ? 'Monitor' : 'Low' ?>
        </div>
      </div>
      <div class="signal-value"><?= $attention['major_interventions'] ?></div>
      <div class="signal-note">Instructor interventions marked as major and relevant for escalation trend tracking.</div>
    </div>
  </div>

  <div class="panel-grid">
    <div class="card panel-card">
      <div class="panel-head">
        <div>
          <h3 class="panel-title">Summary Attention Queue</h3>
          <div class="panel-sub">Students currently waiting for instructor review or revision follow-up.</div>
        </div>
      </div>

      <?php if (!$summaryQueue): ?>
        <div class="empty-premium">No summary review items are currently waiting in the queue.</div>
      <?php else: ?>
        <div class="queue-list">
          <?php foreach ($summaryQueue as $row): ?>
            <div class="queue-item">
              <div class="queue-main">
                <div class="queue-title">
                  <?= h((string)$row['student_name']) ?> · <?= h((string)$row['lesson_title']) ?>
                </div>
                <div class="queue-meta">
                  <?= h((string)$row['cohort_name']) ?>
                </div>
              </div>
              <div class="queue-side">
                <div class="<?= h(cw_status_class((string)$row['review_status'])) ?>">
                  <?= h((string)$row['review_status']) ?>
                </div>
                <div class="queue-time"><?= h(cw_human_dt((string)$row['updated_at'])) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card panel-card">
      <div class="panel-head">
        <div>
          <h3 class="panel-title">Instructor Action Queue</h3>
          <div class="panel-sub">Approval and intervention items currently waiting for instructor handling.</div>
        </div>
      </div>

      <?php if (!$approvalQueue): ?>
        <div class="empty-premium">No instructor approval actions are currently pending.</div>
      <?php else: ?>
        <div class="queue-list">
          <?php foreach ($approvalQueue as $row): ?>
            <div class="queue-item">
              <div class="queue-main">
                <div class="queue-title">
                  <?= h((string)$row['student_name']) ?> · <?= h((string)$row['lesson_title']) ?>
                </div>
                <div class="queue-meta">
                  <?= h((string)$row['cohort_name']) ?>
                </div>
              </div>
              <div class="queue-side">
                <div class="<?= h(cw_status_class((string)$row['status'])) ?>">
                  <?= h((string)$row['status']) ?>
                </div>
                <div class="queue-time"><?= h(cw_human_dt((string)$row['created_at'])) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel-grid">
    <div class="card panel-card">
      <div class="panel-head">
        <div>
          <h3 class="panel-title">Recent Progression Activity</h3>
          <div class="panel-sub">Latest recorded workflow events across training progression.</div>
        </div>
      </div>

      <?php if (!$recentEvents): ?>
        <div class="empty-premium">No recent progression events found.</div>
      <?php else: ?>
        <div class="queue-list">
          <?php foreach ($recentEvents as $row): ?>
            <div class="queue-item">
              <div class="queue-main">
                <div class="queue-title">
                  <?= h((string)($row['student_name'] ?: 'System')) ?> · <?= h((string)($row['lesson_title'] ?: '—')) ?>
                </div>
                <div class="queue-meta">
                  <?= h((string)($row['event_code'] ?: 'event')) ?>
                  <?php if (!empty($row['cohort_name'])): ?>
                    · <?= h((string)$row['cohort_name']) ?>
                  <?php endif; ?>
                </div>
              </div>
              <div class="queue-side">
                <div class="<?= h(cw_status_class((string)$row['event_status'])) ?>">
                  <?= h((string)$row['event_status']) ?>
                </div>
                <div class="queue-time"><?= h(cw_human_dt((string)$row['event_time'])) ?></div>
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
          <div class="panel-sub">Move directly into core admin actions for theory training management.</div>
        </div>
      </div>

      <div class="mini-actions">
        <a class="mini-action" href="/admin/courses.php">Courses</a>
        <a class="mini-action" href="/admin/lessons.php">Lessons</a>
        <a class="mini-action" href="/admin/slides.php">Slides</a>
        <a class="mini-action" href="/admin/import_lab.php">Bulk Import</a>
        <a class="mini-action" href="/admin/bulk_enrich.php">Bulk Enrich</a>
        <a class="mini-action" href="/admin/architecture_scanner.php">Architecture Scanner</a>
      </div>
    </div>
  </div>

</div>

<?php cw_footer(); ?>
