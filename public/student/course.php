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
$cohortId = (int)($_GET['cohort_id'] ?? 0);
if ($cohortId <= 0) exit('Missing cohort_id');

if ($role === 'student') {
    $check = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id=? AND user_id=? LIMIT 1");
    $check->execute([$cohortId, $userId]);
    if (!$check->fetchColumn()) {
        http_response_code(403);
        exit('Not enrolled in this cohort');
    }
}

$co = $pdo->prepare("
  SELECT co.*, c.title AS course_title, p.program_key, p.id AS program_id
  FROM cohorts co
  JOIN courses c ON c.id=co.course_id
  JOIN programs p ON p.id=c.program_id
  WHERE co.id=? LIMIT 1
");
$co->execute([$cohortId]);
$cohort = $co->fetch(PDO::FETCH_ASSOC);
if (!$cohort) exit('Cohort not found');

function lesson_passed(PDO $pdo, $userId, $lessonId) {
    $st = $pdo->prepare("SELECT status FROM lesson_activity WHERE user_id=? AND lesson_id=? LIMIT 1");
    $st->execute([$userId, $lessonId]);
    $lessonActivityPassed = ((string)$st->fetchColumn() === 'passed');

    if ($lessonActivityPassed) {
        return true;
    }

    $pt = $pdo->prepare("
        SELECT 1
        FROM progress_tests_v2
        WHERE user_id=? AND lesson_id=? AND status='completed' AND score_pct >= 75
        LIMIT 1
    ");
    $pt->execute([$userId, $lessonId]);

    return (bool)$pt->fetchColumn();
}

function get_summary_len(PDO $pdo, $userId, $cohortId, $lessonId) {
    $st = $pdo->prepare("
        SELECT summary_plain
        FROM lesson_summaries
        WHERE user_id=? AND cohort_id=? AND lesson_id=?
        LIMIT 1
    ");
    $st->execute([$userId, $cohortId, $lessonId]);
    $plain = (string)($st->fetchColumn() ?: '');
    return function_exists('mb_strlen') ? mb_strlen(trim($plain)) : strlen(trim($plain));
}

function get_test_status_v2(PDO $pdo, $userId, $cohortId, $lessonId) {
    $st = $pdo->prepare("
      SELECT attempt, status, score_pct, started_at, completed_at
      FROM progress_tests_v2
      WHERE user_id=? AND cohort_id=? AND lesson_id=?
      ORDER BY attempt DESC
      LIMIT 1
    ");
    $st->execute([$userId, $cohortId, $lessonId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    $mx = $pdo->prepare("
      SELECT MAX(attempt)
      FROM progress_tests_v2
      WHERE user_id=? AND cohort_id=? AND lesson_id=?
    ");
    $mx->execute([$userId, $cohortId, $lessonId]);
    $maxAttempt = (int)($mx->fetchColumn() ?: 0);

    $pass = $pdo->prepare("
      SELECT MAX(score_pct)
      FROM progress_tests_v2
      WHERE user_id=? AND cohort_id=? AND lesson_id=? AND status='completed'
    ");
    $pass->execute([$userId, $cohortId, $lessonId]);
    $bestScore = $pass->fetchColumn();
    $bestScore = ($bestScore === null) ? null : (int)$bestScore;

    return [
        'max_attempt' => $maxAttempt,
        'last' => $row ?: null,
        'best_score' => $bestScore,
        'passed' => ($bestScore !== null && $bestScore >= 75)
    ];
}

function format_deadline_date($deadlineUtc) {
    if (trim($deadlineUtc) === '') return '—';

    try {
        $dt = new DateTime($deadlineUtc, new DateTimeZone('UTC'));
        return $dt->format('D, F j, Y');
    } catch (Throwable $e) {
        return $deadlineUtc;
    }
}

function deadline_meta($deadlineUtc) {
    if (trim($deadlineUtc) === '') {
        return [
            'label' => 'No deadline',
            'class' => 'deadline-neutral',
            'date'  => '—'
        ];
    }

    try {
        $deadline = new DateTime($deadlineUtc, new DateTimeZone('UTC'));
        $todayUtc = new DateTime('now', new DateTimeZone('UTC'));
        $todayDate = new DateTime($todayUtc->format('Y-m-d') . ' 00:00:00', new DateTimeZone('UTC'));
        $deadlineDate = new DateTime($deadline->format('Y-m-d') . ' 00:00:00', new DateTimeZone('UTC'));

        $diffSeconds = $deadlineDate->getTimestamp() - $todayDate->getTimestamp();
        $days = (int)floor($diffSeconds / 86400);

        if ($days < 0) {
            $label = 'overdue ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's');
            $class = 'deadline-red';
        } elseif ($days === 0) {
            $label = 'due today';
            $class = 'deadline-red';
        } elseif ($days === 1) {
            $label = '1 day';
            $class = 'deadline-red';
        } elseif ($days <= 3) {
            $label = $days . ' days';
            $class = 'deadline-orange';
        } else {
            $label = $days . ' days';
            $class = 'deadline-green';
        }

        return [
            'label' => $label,
            'class' => $class,
            'date'  => $deadline->format('D, F j, Y')
        ];
    } catch (Throwable $e) {
        return [
            'label' => 'Unknown',
            'class' => 'deadline-neutral',
            'date'  => $deadlineUtc
        ];
    }
}

function deadline_progress_meta($cohortStartDate, $deadlineUtc) {
    $meta = deadline_meta($deadlineUtc);
    $meta['pct'] = 0;

    if (trim($deadlineUtc) === '' || trim($cohortStartDate) === '') {
        return $meta;
    }

    try {
        $start = new DateTime(substr((string)$cohortStartDate, 0, 10) . ' 00:00:00', new DateTimeZone('UTC'));
        $deadline = new DateTime(substr((string)$deadlineUtc, 0, 10) . ' 00:00:00', new DateTimeZone('UTC'));
        $todayUtc = new DateTime('now', new DateTimeZone('UTC'));
        $today = new DateTime($todayUtc->format('Y-m-d') . ' 00:00:00', new DateTimeZone('UTC'));

        $startTs = $start->getTimestamp();
        $deadlineTs = $deadline->getTimestamp();
        $todayTs = $today->getTimestamp();

        if ($deadlineTs <= $startTs) {
            $meta['pct'] = ($todayTs >= $deadlineTs) ? 100 : 0;
            return $meta;
        }

        if ($todayTs <= $startTs) {
            $meta['pct'] = 0;
            return $meta;
        }

        if ($todayTs >= $deadlineTs) {
            $meta['pct'] = 100;
            return $meta;
        }

        $total = $deadlineTs - $startTs;
        $elapsed = $todayTs - $startTs;
        $pct = (int)round(($elapsed / $total) * 100);

        if ($pct < 0) $pct = 0;
        if ($pct > 100) $pct = 100;

        $meta['pct'] = $pct;
        return $meta;
    } catch (Throwable $e) {
        return $meta;
    }
}

function score_class($score) {
    $score = (int)$score;
    if ($score >= 75) return 'score-pass';
    if ($score > 0) return 'score-fail';
    return 'score-pending';
}

function percent($num, $den) {
    if ((int)$den <= 0) return 0;
    return (int)round(((float)$num / (float)$den) * 100);
}

$lessonRows = $pdo->prepare("
  SELECT
    d.deadline_utc,
    d.unlock_after_lesson_id,
    d.sort_order,
    l.id AS lesson_id,
    l.external_lesson_id,
    l.title AS lesson_title,
    c.id AS course_id,
    c.title AS course_title,
    c.sort_order AS course_sort_order
  FROM cohort_lesson_deadlines d
  JOIN lessons l ON l.id=d.lesson_id
  JOIN courses c ON c.id=l.course_id
  WHERE d.cohort_id=?
  ORDER BY c.sort_order, c.id, d.sort_order, d.id
");
$lessonRows->execute([$cohortId]);
$lessonRows = $lessonRows->fetchAll(PDO::FETCH_ASSOC);

$courseBlocks = [];
$totalLessons = 0;
$totalCompletedLessons = 0;
$allBestScores = [];
$onTimeEligible = 0;
$onTimeCount = 0;

foreach ($lessonRows as $l) {
    $lessonId = (int)$l['lesson_id'];
    $courseId = (int)$l['course_id'];

    if (!isset($courseBlocks[$courseId])) {
        $courseBlocks[$courseId] = [
            'course_id' => $courseId,
            'course_title' => (string)$l['course_title'],
            'course_sort_order' => (int)$l['course_sort_order'],
            'lessons' => [],
            'last_deadline_utc' => (string)$l['deadline_utc']
        ];
    }

    $locked = false;
    $passed = false;

    if ($role === 'student') {
        if (!empty($l['unlock_after_lesson_id'])) {
            $locked = !lesson_passed($pdo, $userId, (int)$l['unlock_after_lesson_id']);
        }
        $passed = lesson_passed($pdo, $userId, $lessonId);
    }

    $first = $pdo->prepare("
        SELECT id
        FROM slides
        WHERE lesson_id=? AND is_deleted=0
        ORDER BY page_number ASC
        LIMIT 1
    ");
    $first->execute([$lessonId]);
    $firstSlideId = (int)$first->fetchColumn();

    $sumLen = ($role === 'admin') ? 9999 : get_summary_len($pdo, $userId, $cohortId, $lessonId);
    $summaryOk = ($sumLen >= 400);

    $test = ($role === 'admin')
        ? ['max_attempt' => 0, 'last' => null, 'best_score' => null, 'passed' => false]
        : get_test_status_v2($pdo, $userId, $cohortId, $lessonId);

    $last = $test['last'];
    $attemptsUsed = (int)$test['max_attempt'];
    $attemptsLeft = max(0, 3 - $attemptsUsed);
    $testPassed = !empty($test['passed']);
    $bestScore = $test['best_score'];

    $canTest = true;
    if ($role === 'student' && $locked) $canTest = false;
    if ($role === 'student' && !$summaryOk) $canTest = false;
    if ($role === 'student' && $testPassed) $canTest = false;
    if ($role === 'student' && $attemptsLeft <= 0) $canTest = false;

    $ptUrlV2 = '/student/progress_test_v2.php?cohort_id=' . (int)$cohortId . '&lesson_id=' . $lessonId;
    $deadline = deadline_progress_meta((string)$cohort['start_date'], (string)$l['deadline_utc']);

    if ($bestScore !== null) {
        $allBestScores[] = (int)$bestScore;
    }

    if ($passed) {
        $totalCompletedLessons++;
    }
    $totalLessons++;

    if ($testPassed && !empty($last['completed_at']) && trim((string)$l['deadline_utc']) !== '') {
        $onTimeEligible++;
        try {
            $completedDate = new DateTime(substr((string)$last['completed_at'], 0, 10) . ' 00:00:00', new DateTimeZone('UTC'));
            $deadlineDate = new DateTime(substr((string)$l['deadline_utc'], 0, 10) . ' 00:00:00', new DateTimeZone('UTC'));
            if ($completedDate->getTimestamp() <= $deadlineDate->getTimestamp()) {
                $onTimeCount++;
            }
        } catch (Throwable $e) {
        }
    } elseif ($testPassed) {
        $onTimeEligible++;
    }

    $courseBlocks[$courseId]['lessons'][] = [
        'lesson_id' => $lessonId,
        'external_lesson_id' => (int)$l['external_lesson_id'],
        'lesson_title' => (string)$l['lesson_title'],
        'deadline_utc' => (string)$l['deadline_utc'],
        'deadline' => $deadline,
        'locked' => $locked,
        'passed' => $passed,
        'summary_ok' => $summaryOk,
        'summary_len' => $sumLen,
        'test' => $test,
        'test_passed' => $testPassed,
        'best_score' => $bestScore,
        'attempts_left' => $attemptsLeft,
        'can_test' => $canTest,
        'progress_test_url' => $ptUrlV2,
        'first_slide_id' => $firstSlideId
    ];

    $courseBlocks[$courseId]['last_deadline_utc'] = (string)$l['deadline_utc'];
}

$courseBlocks = array_values($courseBlocks);

foreach ($courseBlocks as $k => $block) {
    $countLessons = count($block['lessons']);
    $countPassed = 0;
    $courseScores = [];

    foreach ($block['lessons'] as $lx) {
        if (!empty($lx['passed'])) $countPassed++;
        if ($lx['best_score'] !== null) $courseScores[] = (int)$lx['best_score'];
    }

    $courseBlocks[$k]['lesson_count'] = $countLessons;
    $courseBlocks[$k]['passed_count'] = $countPassed;
    $courseBlocks[$k]['progress_pct'] = percent($countPassed, $countLessons);
    $courseBlocks[$k]['avg_score'] = $courseScores ? (int)round(array_sum($courseScores) / count($courseScores)) : null;
    $courseBlocks[$k]['deadline'] = deadline_progress_meta((string)$cohort['start_date'], (string)$block['last_deadline_utc']);
}

$programProgressPct = percent($totalCompletedLessons, $totalLessons);
$programAvgScore = $allBestScores ? (int)round(array_sum($allBestScores) / count($allBestScores)) : null;
$studentOnTimePct = percent($onTimeCount, $onTimeEligible);

$programId = (int)$cohort['program_id'];
$studentRankPct = null;
$programAvgOnTimePct = null;

if ($role === 'student' && $programId > 0) {
    try {
        $rankSql = "
            SELECT x.user_id, AVG(x.best_score) AS avg_score
            FROM (
                SELECT pt.user_id, pt.lesson_id, MAX(pt.score_pct) AS best_score
                FROM progress_tests_v2 pt
                JOIN lessons l ON l.id = pt.lesson_id
                JOIN courses c ON c.id = l.course_id
                WHERE c.program_id = ?
                  AND pt.status = 'completed'
                GROUP BY pt.user_id, pt.lesson_id
            ) x
            GROUP BY x.user_id
        ";
        $rankSt = $pdo->prepare($rankSql);
        $rankSt->execute([$programId]);
        $rankRows = $rankSt->fetchAll(PDO::FETCH_ASSOC);

        $scores = [];
        $myAvg = null;
        foreach ($rankRows as $rr) {
            $avg = isset($rr['avg_score']) ? (float)$rr['avg_score'] : null;
            if ($avg === null) continue;
            $scores[] = $avg;
            if ((int)$rr['user_id'] === $userId) {
                $myAvg = $avg;
            }
        }

        if ($myAvg !== null && $scores) {
            $belowOrEqual = 0;
            foreach ($scores as $s) {
                if ($s <= $myAvg) $belowOrEqual++;
            }
            $studentRankPct = (int)round(($belowOrEqual / count($scores)) * 100);
        }

        $onTimeSql = "
            SELECT
              z.user_id,
              SUM(CASE WHEN z.on_time = 1 THEN 1 ELSE 0 END) AS on_time_count,
              COUNT(*) AS total_count
            FROM (
                SELECT
                  pt.user_id,
                  pt.lesson_id,
                  CASE
                    WHEN DATE(pt.completed_at) <= DATE(d.deadline_utc) THEN 1
                    ELSE 0
                  END AS on_time
                FROM progress_tests_v2 pt
                JOIN lessons l ON l.id = pt.lesson_id
                JOIN courses c ON c.id = l.course_id
                JOIN cohort_lesson_deadlines d ON d.lesson_id = pt.lesson_id AND d.cohort_id = pt.cohort_id
                WHERE c.program_id = ?
                  AND pt.status = 'completed'
                  AND pt.score_pct >= 75
                  AND pt.completed_at IS NOT NULL
                GROUP BY pt.user_id, pt.lesson_id, pt.cohort_id
            ) z
            GROUP BY z.user_id
        ";
        $onTimeSt = $pdo->prepare($onTimeSql);
        $onTimeSt->execute([$programId]);
        $onTimeRows = $onTimeSt->fetchAll(PDO::FETCH_ASSOC);

        $programOnTimePcts = [];
        foreach ($onTimeRows as $otr) {
            $tc = (int)$otr['total_count'];
            $oc = (int)$otr['on_time_count'];
            if ($tc > 0) {
                $programOnTimePcts[] = ($oc / $tc) * 100;
            }
        }

        if ($programOnTimePcts) {
            $programAvgOnTimePct = (int)round(array_sum($programOnTimePcts) / count($programOnTimePcts));
        }
    } catch (Throwable $e) {
        $studentRankPct = null;
        $programAvgOnTimePct = null;
    }
}

cw_header('Course');
?>
<style>
  .top-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(220px,1fr));
    gap:16px;
    margin-bottom:16px;
  }
  .overview-card{
    border:1px solid #e5e7eb;
    border-radius:18px;
    padding:18px;
    background:#fff;
    box-shadow:0 8px 24px rgba(0,0,0,0.06);
  }
  .overview-title{
    font-size:18px;
    font-weight:800;
    color:#1f2937;
    margin-bottom:14px;
  }
  .overview-main{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:12px;
  }
  .overview-big{
    font-size:40px;
    line-height:1;
    font-weight:900;
    color:#1e3c72;
    white-space:nowrap;
  }
  .overview-sub{
    color:#4b5563;
    font-size:14px;
    line-height:1.35;
  }

  .progress-shell{
    width:100%;
    height:14px;
    border-radius:999px;
    overflow:hidden;
    background:#e5e7eb;
  }
  .progress-fill{
    height:14px;
    border-radius:999px;
    background:#1e3c72;
  }

  .deadline-progress-shell{
    width:100%;
    height:14px;
    border-radius:999px;
    overflow:hidden;
    background:#e5e7eb;
  }
  .deadline-progress-fill{
    height:14px;
    border-radius:999px;
  }
  .deadline-progress-fill.deadline-green{
    background:#16a34a;
  }
  .deadline-progress-fill.deadline-orange{
    background:#f59e0b;
  }
  .deadline-progress-fill.deadline-red{
    background:#dc2626;
  }
  .deadline-progress-fill.deadline-neutral{
    background:#9ca3af;
  }

  .course-card{
    border:1px solid #e5e7eb;
    border-radius:20px;
    background:#fff;
    box-shadow:0 8px 24px rgba(0,0,0,0.05);
    margin-bottom:16px;
    overflow:hidden;
  }
  .course-card details{
    border:0;
  }
  .course-card summary{
    list-style:none;
    cursor:pointer;
    padding:18px;
  }
  .course-card summary::-webkit-details-marker{
    display:none;
  }
  .course-head{
    display:grid;
    grid-template-columns:64px 40px minmax(220px,1.5fr) minmax(180px,1fr) minmax(140px,0.8fr) minmax(220px,1fr);
    gap:14px;
    align-items:center;
  }
  .course-badge{
    width:52px;
    height:52px;
    border-radius:999px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:900;
    font-size:18px;
    color:#fff;
    background:linear-gradient(135deg,#1e3c72,#2a5298);
  }
  .course-toggle{
    font-size:24px;
    color:#1e3c72;
    font-weight:900;
    text-align:center;
    transition:transform .2s ease;
  }
  details[open] .course-toggle{
    transform:rotate(90deg);
  }
  .course-title{
    font-size:20px;
    font-weight:900;
    color:#1f2937;
  }
  .metric-label{
    font-size:12px;
    color:#6b7280;
    margin-bottom:6px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.02em;
  }
  .metric-value{
    font-size:15px;
    font-weight:800;
    color:#1f2937;
  }
  .metric-sub{
    font-size:12px;
    color:#6b7280;
    margin-top:6px;
  }

  .course-body{
    padding:0 18px 18px 18px;
    border-top:1px solid #eef2f7;
    background:#fafafa;
  }

  .lesson-table{
    width:100%;
    border-collapse:collapse;
    margin-top:14px;
  }
  .lesson-table th,
  .lesson-table td{
    padding:12px 10px;
    border-bottom:1px solid #e5e7eb;
    vertical-align:middle;
    text-align:left;
  }
  .lesson-table th{
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.02em;
    color:#6b7280;
  }

  .deadline-wrap{
    min-width:220px;
  }
  .deadline-date{
    font-weight:700;
    color:#1f2937;
    margin-bottom:6px;
  }
  .deadline-label{
    font-size:12px;
    font-weight:800;
    margin-top:6px;
  }
  .deadline-label.deadline-green{ color:#166534; }
  .deadline-label.deadline-orange{ color:#c2410c; }
  .deadline-label.deadline-red{ color:#b91c1c; }
  .deadline-label.deadline-neutral{ color:#4b5563; }

  .icon-ok{
    color:#15803d;
    font-weight:900;
    font-size:20px;
    line-height:1;
  }
  .icon-bad{
    color:#b91c1c;
    font-weight:900;
    font-size:20px;
    line-height:1;
  }
  .icon-dash{
    color:#9ca3af;
    font-weight:700;
    font-size:18px;
    line-height:1;
  }

  .score-pill{
    display:inline-block;
    border-radius:999px;
    padding:6px 10px;
    font-size:12px;
    font-weight:800;
    line-height:1.2;
    border:1px solid transparent;
    margin-bottom:6px;
  }
  .score-pass{
    background:#dcfce7;
    border-color:#86efac;
    color:#166534;
  }
  .score-fail{
    background:#fee2e2;
    border-color:#fca5a5;
    color:#b91c1c;
  }
  .score-pending{
    background:#dbeafe;
    border-color:#93c5fd;
    color:#1d4ed8;
  }
  .smallmuted{
    font-size:11px;
    opacity:.75;
    margin-top:4px;
  }

  .mini-progress{
    width:100%;
    height:10px;
    border-radius:999px;
    overflow:hidden;
    background:#e5e7eb;
  }
  .mini-progress > span{
    display:block;
    height:10px;
    border-radius:999px;
    background:#1e3c72;
  }

  @media (max-width: 1100px){
    .top-grid{
      grid-template-columns:1fr;
    }
    .course-head{
      grid-template-columns:52px 34px 1fr;
    }
    .course-head .metric-col{
      grid-column:1 / -1;
    }
  }
</style>

<div class="card">
  <div class="muted">
    <?= h($cohort['program_key']) ?> — Cohort: <?= h($cohort['name']) ?>
  </div>
  <p style="margin-top:10px;">
    <a class="btn btn-sm" href="/student/dashboard.php">← Back</a>
  </p>
</div>

<div class="top-grid">
  <div class="overview-card">
    <div class="overview-title">Program Study Overview</div>
    <div class="overview-main">
      <div class="overview-big"><?= (int)$programProgressPct ?>%</div>
      <div class="overview-sub">
        <?= (int)$totalCompletedLessons ?>/<?= (int)$totalLessons ?> lessons completed
      </div>
    </div>
    <div class="progress-shell">
      <div class="progress-fill" style="width:<?= (int)$programProgressPct ?>%;"></div>
    </div>
  </div>

  <div class="overview-card">
    <div class="overview-title">Program Average Progress Test Score</div>
    <div class="overview-main">
      <div class="overview-big"><?= $programAvgScore !== null ? (int)$programAvgScore . '%' : '—' ?></div>
      <div class="overview-sub">
        Based on your best completed V2 test score per lesson
      </div>
    </div>
    <div class="progress-shell">
      <div class="progress-fill" style="width:<?= $programAvgScore !== null ? (int)$programAvgScore : 0 ?>%;"></div>
    </div>
  </div>

  <div class="overview-card">
    <div class="overview-title">Program Ranking & On-Time Performance</div>
    <div class="overview-main">
      <div class="overview-big"><?= $studentRankPct !== null ? (int)$studentRankPct . '%' : '—' ?></div>
      <div class="overview-sub">
        Ranking percentile vs all students in this program
      </div>
    </div>
    <div class="smallmuted" style="margin-bottom:8px;">
      On-time performance: <strong><?= (int)$studentOnTimePct ?>%</strong>
      <?php if ($programAvgOnTimePct !== null): ?>
        • Program average: <strong><?= (int)$programAvgOnTimePct ?>%</strong>
      <?php endif; ?>
    </div>
    <div class="progress-shell">
      <div class="progress-fill" style="width:<?= (int)$studentOnTimePct ?>%;"></div>
    </div>
  </div>
</div>

<div class="card">
  <h2 style="margin-bottom:14px;">Courses Overview</h2>

  <?php if (!$courseBlocks): ?>
    <div class="muted">No lessons found for this cohort.</div>
  <?php else: ?>
    <?php $courseIndex = 0; ?>
    <?php foreach ($courseBlocks as $course): ?>
      <?php $courseIndex++; ?>
      <div class="course-card">
        <details>
          <summary>
            <div class="course-head">
              <div class="course-badge"><?= (int)$courseIndex ?></div>
              <div class="course-toggle">›</div>

              <div>
                <div class="metric-label">Course</div>
                <div class="course-title"><?= h($course['course_title']) ?></div>
              </div>

              <div class="metric-col">
                <div class="metric-label">Course Progress</div>
                <div class="metric-value"><?= (int)$course['progress_pct'] ?>%</div>
                <div class="mini-progress"><span style="width:<?= (int)$course['progress_pct'] ?>%;"></span></div>
                <div class="metric-sub"><?= (int)$course['passed_count'] ?>/<?= (int)$course['lesson_count'] ?> lessons completed</div>
              </div>

              <div class="metric-col">
                <div class="metric-label">Average Test Score</div>
                <div class="metric-value"><?= $course['avg_score'] !== null ? (int)$course['avg_score'] . '%' : '—' ?></div>
              </div>

              <div class="metric-col">
                <div class="metric-label">Final Course Deadline</div>
                <div class="deadline-date"><?= h($course['deadline']['date']) ?></div>
                <div class="deadline-progress-shell">
                  <div class="deadline-progress-fill <?= h($course['deadline']['class']) ?>" style="width:<?= (int)$course['deadline']['pct'] ?>%;"></div>
                </div>
                <div class="deadline-label <?= h($course['deadline']['class']) ?>"><?= h($course['deadline']['label']) ?></div>
              </div>
            </div>
          </summary>

          <div class="course-body">
            <table class="lesson-table">
              <tr>
                <th style="width:34%;">Lesson</th>
                <th style="width:23%;">Deadline</th>
                <th style="width:8%;">Done</th>
                <th style="width:9%;">Summary</th>
                <th style="width:16%;">Progress Test</th>
                <th style="width:10%;">Action</th>
              </tr>

              <?php foreach ($course['lessons'] as $lx): ?>
                <?php
                  $last = $lx['test']['last'];
                  $attemptsLeft = (int)$lx['attempts_left'];
                ?>
                <tr>
                  <td>
                    <strong><?= h($lx['lesson_title']) ?></strong>
                  </td>

                  <td>
                    <div class="deadline-wrap">
                      <div class="deadline-date"><?= h($lx['deadline']['date']) ?></div>
                      <div class="deadline-progress-shell">
                        <div class="deadline-progress-fill <?= h($lx['deadline']['class']) ?>" style="width:<?= (int)$lx['deadline']['pct'] ?>%;"></div>
                      </div>
                      <div class="deadline-label <?= h($lx['deadline']['class']) ?>">
                        <?= h($lx['deadline']['label']) ?>
                      </div>
                    </div>
                  </td>

                  <td>
                    <?php if ($role === 'admin'): ?>
                      <span class="icon-dash">—</span>
                    <?php elseif ($lx['passed']): ?>
                      <span class="icon-ok">✓</span>
                    <?php else: ?>
                      <span class="icon-dash">—</span>
                    <?php endif; ?>
                  </td>

                  <td>
                    <?php if ($role === 'admin'): ?>
                      <span class="icon-dash">—</span>
                    <?php elseif ($lx['summary_ok']): ?>
                      <span class="icon-ok">✓</span>
                    <?php else: ?>
                      <span class="icon-bad">✕</span>
                    <?php endif; ?>
                  </td>

                  <td>
                    <?php if ($role === 'admin'): ?>
                      <span class="icon-dash">—</span>
                    <?php else: ?>

                      <?php if ($lx['test_passed'] && $lx['best_score'] !== null): ?>
                        <div class="score-pill score-pass"><?= (int)$lx['best_score'] ?>% PASS</div>

                      <?php elseif ($last && $last['score_pct'] !== null): ?>
                        <div class="score-pill score-fail">
                          <?= (int)$last['score_pct'] ?>% • <?= (int)$attemptsLeft ?> attempt<?= $attemptsLeft === 1 ? '' : 's' ?> left
                        </div>

                      <?php elseif ($last && (string)$last['status'] !== 'completed'): ?>
                        <div class="score-pill score-pending">In progress</div>

                      <?php endif; ?>

                      <?php if ($lx['can_test']): ?>
                        <a class="btn btn-sm" href="<?= h($lx['progress_test_url']) ?>">Start</a>
                      <?php elseif ($lx['locked']): ?>
                        <div class="smallmuted">Complete previous lesson first</div>
                      <?php elseif (!$lx['summary_ok']): ?>
                        <div class="smallmuted">Complete summary first</div>
                      <?php elseif (!$lx['test_passed'] && $attemptsLeft <= 0): ?>
                        <div class="smallmuted">No attempts left</div>
                      <?php endif; ?>

                    <?php endif; ?>
                  </td>

                  <td>
                    <?php if ((int)$lx['first_slide_id'] <= 0): ?>
                      <span class="icon-dash">—</span>
                    <?php elseif ($role === 'student' && $lx['locked']): ?>
                      <span class="icon-dash">—</span>
                    <?php else: ?>
                      <a class="btn btn-sm" href="/player/slide.php?slide_id=<?= (int)$lx['first_slide_id'] ?>">Start</a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>
        </details>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php cw_footer(); ?>