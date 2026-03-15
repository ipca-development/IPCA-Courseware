<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/courseware_progression_v2.php';

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

$engine = new CoursewareProgressionV2($pdo);
$policy = $engine->getAllPolicies(['cohort_id' => $cohortId]);

$completedRemediationByLesson = [];

if ($role === 'student') {
    $remSt = $pdo->prepare("
        SELECT lesson_id, MAX(id) AS latest_id
        FROM student_required_actions
        WHERE user_id = ?
          AND cohort_id = ?
          AND action_type = 'remediation_acknowledgement'
          AND status IN ('completed','approved')
        GROUP BY lesson_id
    ");
    $remSt->execute([$userId, $cohortId]);

    foreach ($remSt->fetchAll(PDO::FETCH_ASSOC) as $rr) {
        $completedRemediationByLesson[(int)$rr['lesson_id']] = (int)$rr['latest_id'];
    }
}

function lesson_passed(PDO $pdo, $userId, $cohortId, $lessonId) {
    $st = $pdo->prepare("
        SELECT completion_status, test_pass_status
        FROM lesson_activity
        WHERE user_id=? AND cohort_id=? AND lesson_id=?
        LIMIT 1
    ");
    $st->execute([$userId, $cohortId, $lessonId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    $completionStatus = (string)($row['completion_status'] ?? '');
    $testPassStatus   = (string)($row['test_pass_status'] ?? '');

    if ($completionStatus === 'completed' || $testPassStatus === 'passed') {
        return true;
    }

    $pt = $pdo->prepare("
        SELECT 1
        FROM progress_tests_v2
        WHERE user_id=? AND cohort_id=? AND lesson_id=? AND status='completed' AND pass_gate_met=1
        LIMIT 1
    ");
    $pt->execute([$userId, $cohortId, $lessonId]);

    return (bool)$pt->fetchColumn();
}

function get_summary_state(PDO $pdo, $userId, $cohortId, $lessonId) {
    $st = $pdo->prepare("
        SELECT summary_html, summary_plain, review_status, review_score, updated_at, review_feedback, review_notes_by_instructor
        FROM lesson_summaries
        WHERE user_id=? AND cohort_id=? AND lesson_id=?
        LIMIT 1
    ");
    $st->execute([$userId, $cohortId, $lessonId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row || !is_array($row)) {
        return [
            'len' => 0,
            'review_status' => '',
            'review_score' => null,
            'updated_at' => '',
            'review_feedback' => '',
            'review_notes_by_instructor' => '',
            'has_summary' => false,
            'ok' => false
        ];
    }

    $plain = (string)($row['summary_plain'] ?? '');
    $reviewStatus = (string)($row['review_status'] ?? '');
    $reviewScore = ($row['review_score'] === null) ? null : (int)$row['review_score'];

    $len = function_exists('mb_strlen') ? mb_strlen(trim($plain)) : strlen(trim($plain));

    return [
        'len' => $len,
        'review_status' => $reviewStatus,
        'review_score' => $reviewScore,
        'updated_at' => (string)($row['updated_at'] ?? ''),
        'review_feedback' => (string)($row['review_feedback'] ?? ''),
        'review_notes_by_instructor' => (string)($row['review_notes_by_instructor'] ?? ''),
        'has_summary' => ($len > 0),
        'ok' => ($reviewStatus === 'acceptable')
    ];
}

function summary_quality_meta(array $summaryState) {
    $len = (int)($summaryState['len'] ?? 0);
    $status = (string)($summaryState['review_status'] ?? '');
    $score = $summaryState['review_score'];

    if ($len <= 0) {
        return ['label' => 'Not Started', 'class' => 'neutral', 'sub' => 'No summary'];
    }

    if ($status === 'pending') {
        return ['label' => 'Pending review', 'class' => 'warn', 'sub' => 'Waiting for review', 'pct' => 58];
    }

    if ($status === 'needs_revision') {
        return ['label' => 'Needs revision', 'class' => 'danger', 'sub' => 'Improve summary', 'pct' => 32];
    }

    if ($status === 'rejected') {
        return ['label' => 'Needs revision', 'class' => 'danger', 'sub' => 'Major improvement needed', 'pct' => 20];
    }

    if ($status === 'acceptable') {
        if ($score !== null && (int)$score >= 90) {
            return ['label' => 'Excellent understanding', 'class' => 'ok', 'sub' => 'Excellent', 'pct' => 96];
        }
        if ($score !== null && (int)$score >= 75) {
            return ['label' => 'Strong understanding', 'class' => 'ok', 'sub' => 'Strong', 'pct' => 82];
        }
        return ['label' => 'Approved summary', 'class' => 'ok', 'sub' => 'Approved', 'pct' => 72];
    }

    return ['label' => 'Draft saved', 'class' => 'info', 'sub' => 'Draft', 'pct' => 44];
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

    $best = $pdo->prepare("
      SELECT MAX(score_pct)
      FROM progress_tests_v2
      WHERE user_id=? 
        AND cohort_id=? 
        AND lesson_id=? 
        AND status='completed'
    ");
    $best->execute([$userId, $cohortId, $lessonId]);
    $bestScore = $best->fetchColumn();
    $bestScore = ($bestScore === null) ? null : (int)$bestScore;

    $pass = $pdo->prepare("
      SELECT 1
      FROM progress_tests_v2
      WHERE user_id=? 
        AND cohort_id=? 
        AND lesson_id=? 
        AND status='completed'
        AND pass_gate_met=1
      LIMIT 1
    ");
    $pass->execute([$userId, $cohortId, $lessonId]);
    $passed = (bool)$pass->fetchColumn();

    return [
        'max_attempt' => $maxAttempt,
        'last' => $row ?: null,
        'best_score' => $bestScore,
        'passed' => $passed
    ];
}

function get_instructor_decision_state(PDO $pdo, $userId, $cohortId, $lessonId) {
    $st = $pdo->prepare("
        SELECT
            granted_extra_attempts,
            one_on_one_required,
            one_on_one_completed,
            training_suspended
        FROM lesson_activity
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id = ?
        LIMIT 1
    ");
    $st->execute([$userId, $cohortId, $lessonId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return [
        'granted_extra_attempts' => (int)($row['granted_extra_attempts'] ?? 0),
        'one_on_one_required' => (int)($row['one_on_one_required'] ?? 0),
        'one_on_one_completed' => (int)($row['one_on_one_completed'] ?? 0),
        'training_suspended' => (int)($row['training_suspended'] ?? 0),
    ];
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
            $label = 'Needs attention today';
            $class = 'deadline-red';
        } elseif ($days === 0) {
            $label = 'Due today';
            $class = 'deadline-red';
        } elseif ($days === 1) {
            $label = '1 day left';
            $class = 'deadline-orange';
        } elseif ($days <= 3) {
            $label = $days . ' days left';
            $class = 'deadline-orange';
        } else {
            $label = $days . ' days left';
            $class = 'deadline-green';
        }

        return [
            'label' => $label,
            'class' => $class,
            'date'  => $deadline->format('M j, Y')
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

function percent($num, $den) {
    if ((int)$den <= 0) return 0;
    return (int)round(((float)$num / (float)$den) * 100);
}

function score_badge_meta($testPassed, $bestScore, $last, $attemptsLeft) {
    if ($testPassed && $bestScore !== null) {
        return [
            'label' => (int)$bestScore . '%',
            'class' => 'ok'
        ];
    }

    if ($last && $last['score_pct'] !== null && (string)$last['status'] === 'completed') {
        return [
            'label' => (int)$last['score_pct'] . '%',
            'class' => 'danger'
        ];
    }

    if ($last && (string)$last['status'] !== 'completed') {
        return [
            'label' => 'In progress',
            'class' => 'info'
        ];
    }

    return [
        'label' => '—',
        'class' => 'neutral'
    ];
}

function student_workflow_label($status) {
    switch ((string)$status) {
        case 'awaiting_summary_review':
            return 'Waiting for instructor review';
        case 'awaiting_test':
            return 'Ready for progress test';
        case 'remediation_required':
            return 'Improve progress test';
        case 'blocked_deadline':
            return 'Priority';
        case 'blocked_reason_required':
            return 'Reason required before extension';
        case 'blocked_reason_rejected':
            return 'Extension request not approved';
        case 'blocked_final':
            return 'Training paused';
        case 'completed':
            return 'Completed';
        default:
            return 'In progress';
    }
}

function lesson_primary_action($lx) {
    $deadlineLabel = (string)($lx['deadline']['label'] ?? '');
    $isPriority = (strpos(strtolower($deadlineLabel), 'attention') !== false || strtolower($deadlineLabel) === 'due today');

    if (!empty($lx['instructor_decision']['training_suspended'])) {
        return [
            'priority' => 90,
            'label' => '',
            'href' => '',
            'class' => 'danger',
            'note' => 'Training paused'
        ];
    }

    if ((string)$lx['summary_review_status'] === 'needs_revision' && (int)$lx['first_slide_id'] > 0 && empty($lx['locked'])) {
        return [
            'priority' => 10,
            'label' => 'Continue Lesson',
            'href' => '/player/slide.php?slide_id=' . (int)$lx['first_slide_id'],
            'class' => 'primary',
            'note' => 'Improve summary'
        ];
    }

    if ($isPriority && empty($lx['passed']) && (int)$lx['first_slide_id'] > 0 && empty($lx['locked'])) {
        return [
            'priority' => 20,
            'label' => 'Continue Lesson',
            'href' => '/player/slide.php?slide_id=' . (int)$lx['first_slide_id'],
            'class' => 'primary',
            'note' => 'Study the lesson'
        ];
    }

    if (!empty($lx['can_test'])) {
        return [
            'priority' => 30,
            'label' => ($lx['test']['max_attempt'] > 0 ? 'Retake Test' : 'Take Test'),
            'href' => (string)$lx['progress_test_url'],
            'class' => 'primary',
            'note' => 'Ready for progress test'
        ];
    }

    if (!empty($lx['instructor_decision']['one_on_one_required']) && empty($lx['instructor_decision']['one_on_one_completed'])) {
        return [
            'priority' => 40,
            'label' => '',
            'href' => '',
            'class' => 'warn',
            'note' => 'Instructor session required'
        ];
    }

    if ((string)$lx['summary_review_status'] === 'pending') {
        return [
            'priority' => 50,
            'label' => '',
            'href' => '',
            'class' => 'neutral',
            'note' => 'Waiting for instructor review'
        ];
    }

    if (empty($lx['summary_state']['has_summary']) && (int)$lx['first_slide_id'] > 0 && empty($lx['locked'])) {
        return [
            'priority' => 60,
            'label' => 'Continue Lesson',
            'href' => '/player/slide.php?slide_id=' . (int)$lx['first_slide_id'],
            'class' => 'primary',
            'note' => 'Study the lesson'
        ];
    }

    if (!$lx['passed'] && (int)$lx['first_slide_id'] > 0 && empty($lx['locked'])) {
        return [
            'priority' => 70,
            'label' => 'Continue Lesson',
            'href' => '/player/slide.php?slide_id=' . (int)$lx['first_slide_id'],
            'class' => 'primary',
            'note' => 'Study the lesson'
        ];
    }

    if (!empty($lx['passed']) && (int)$lx['first_slide_id'] > 0) {
        return [
            'priority' => 95,
            'label' => 'Continue Lesson',
            'href' => '/player/slide.php?slide_id=' . (int)$lx['first_slide_id'],
            'class' => 'neutral',
            'note' => 'Completed'
        ];
    }

    if (!empty($lx['locked'])) {
        return [
            'priority' => 96,
            'label' => '',
            'href' => '',
            'class' => 'neutral',
            'note' => 'Locked'
        ];
    }

    return [
        'priority' => 99,
        'label' => '',
        'href' => '',
        'class' => 'neutral',
        'note' => 'Unavailable'
    ];
}

function module_motivation_meta(array $course) {
    $progress = (int)($course['progress_pct'] ?? 0);
    $revision = (int)($course['revision_count'] ?? 0);
    $overdue = (int)($course['overdue_count'] ?? 0);
    $ready = (int)($course['test_ready_count'] ?? 0);
    $blocked = (int)($course['blocked_count'] ?? 0);
    $passed = (int)($course['passed_count'] ?? 0);
    $lessons = (int)($course['lesson_count'] ?? 0);

    if ($lessons > 0 && $passed >= $lessons) {
        return ['label' => 'Completed Strongly', 'class' => 'ok', 'micro' => 'You finished this module with strong completion.'];
    }
    if ($blocked > 0) {
        return ['label' => 'Blocked', 'class' => 'danger', 'micro' => 'This module needs instructor resolution before moving forward.'];
    }
    if ($revision > 0) {
        return ['label' => 'Priority', 'class' => 'danger', 'micro' => 'A summary revision inside this module is the current priority.'];
    }
    if ($overdue > 0) {
        return ['label' => 'Priority', 'class' => 'warn', 'micro' => 'A time-sensitive item here should be addressed next.'];
    }
    if ($ready > 0) {
        return ['label' => 'Ready for Test', 'class' => 'info', 'micro' => 'You have a lesson here that is ready for testing.'];
    }
    if ($progress >= 85) {
        return ['label' => 'Nearly Complete', 'class' => 'ok', 'micro' => 'You are close to completing this section.'];
    }
    if ($progress >= 35) {
        return ['label' => 'Good Momentum', 'class' => 'ok', 'micro' => 'Strong progress in this module.'];
    }
    return ['label' => 'On Track', 'class' => 'neutral', 'micro' => 'A manageable module to continue building steadily.'];
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
$summariesStartedCount = 0;

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
            $locked = !lesson_passed($pdo, $userId, $cohortId, (int)$l['unlock_after_lesson_id']);
        }
        $passed = lesson_passed($pdo, $userId, $cohortId, $lessonId);
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

    $summaryState = ($role === 'admin')
        ? ['len' => 9999, 'review_status' => 'acceptable', 'review_score' => 90, 'updated_at' => '', 'review_feedback' => '', 'review_notes_by_instructor' => '', 'has_summary' => true, 'ok' => true]
        : get_summary_state($pdo, $userId, $cohortId, $lessonId);

    $summaryMeta = summary_quality_meta($summaryState);
    $sumLen = (int)$summaryState['len'];
    $summaryOk = !empty($summaryState['ok']);
    if (!empty($summaryState['has_summary'])) {
        $summariesStartedCount++;
    }

    $test = ($role === 'admin')
        ? ['max_attempt' => 0, 'last' => null, 'best_score' => null, 'passed' => false]
        : get_test_status_v2($pdo, $userId, $cohortId, $lessonId);

    $last = $test['last'];
    $attemptsUsed = (int)$test['max_attempt'];

    $initialAttemptLimit = (int)($policy['initial_attempt_limit'] ?? 3);
    $extraAttemptsAfterThresholdFail = (int)($policy['extra_attempts_after_threshold_fail'] ?? 2);
    $maxTotalAttemptsWithoutAdminOverride = (int)($policy['max_total_attempts_without_admin_override'] ?? 5);

    if ($initialAttemptLimit <= 0) $initialAttemptLimit = 3;
    if ($extraAttemptsAfterThresholdFail < 0) $extraAttemptsAfterThresholdFail = 0;
    if ($maxTotalAttemptsWithoutAdminOverride <= 0) {
        $maxTotalAttemptsWithoutAdminOverride = $initialAttemptLimit + $extraAttemptsAfterThresholdFail;
    }

    $completedRemediation = !empty($completedRemediationByLesson[$lessonId]);
    $baseMaxAllowedAttempts = min($initialAttemptLimit, $maxTotalAttemptsWithoutAdminOverride);

    if ($completedRemediation) {
        $baseMaxAllowedAttempts = min(
            $initialAttemptLimit + $extraAttemptsAfterThresholdFail,
            $maxTotalAttemptsWithoutAdminOverride
        );
    }

    $instructorDecision = ($role === 'admin')
        ? [
            'granted_extra_attempts' => 0,
            'one_on_one_required' => 0,
            'one_on_one_completed' => 0,
            'training_suspended' => 0,
        ]
        : get_instructor_decision_state($pdo, $userId, $cohortId, $lessonId);

    $maxAllowedAttempts = $baseMaxAllowedAttempts + (int)$instructorDecision['granted_extra_attempts'];
    $attemptsLeft = max(0, $maxAllowedAttempts - $attemptsUsed);

    $testPassed = !empty($test['passed']);
    $bestScore = $test['best_score'];

    $canTest = true;
    if ($role === 'student' && $locked) $canTest = false;
    if ($role === 'student' && !$summaryOk) $canTest = false;
    if ($role === 'student' && $testPassed) $canTest = false;
    if ($role === 'student' && $attemptsLeft <= 0) $canTest = false;
    if ($role === 'student' && (int)$instructorDecision['training_suspended'] === 1) $canTest = false;
    if (
        $role === 'student' &&
        (int)$instructorDecision['one_on_one_required'] === 1 &&
        (int)$instructorDecision['one_on_one_completed'] !== 1
    ) {
        $canTest = false;
    }

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

    $row = [
        'lesson_id' => $lessonId,
        'external_lesson_id' => (int)$l['external_lesson_id'],
        'lesson_title' => (string)$l['lesson_title'],
        'course_title' => (string)$l['course_title'],
        'deadline_utc' => (string)$l['deadline_utc'],
        'deadline' => $deadline,
        'locked' => $locked,
        'passed' => $passed,
        'summary_ok' => $summaryOk,
        'summary_len' => $sumLen,
        'summary_review_status' => (string)$summaryState['review_status'],
        'summary_state' => $summaryState,
        'summary_meta' => $summaryMeta,
        'test' => $test,
        'test_passed' => $testPassed,
        'best_score' => $bestScore,
        'attempts_left' => $attemptsLeft,
        'can_test' => $canTest,
        'instructor_decision' => $instructorDecision,
        'progress_test_url' => $ptUrlV2,
        'first_slide_id' => $firstSlideId
    ];

    $row['primary_action'] = lesson_primary_action($row);

    $courseBlocks[$courseId]['lessons'][] = $row;
    $courseBlocks[$courseId]['last_deadline_utc'] = (string)$l['deadline_utc'];
}

$courseBlocks = array_values($courseBlocks);

foreach ($courseBlocks as $k => $block) {
    $countLessons = count($block['lessons']);
    $countPassed = 0;
    $courseScores = [];
    $summaryApproved = 0;
    $revisionCount = 0;
    $overdueCount = 0;
    $testReadyCount = 0;
    $blockedCount = 0;
    $nextLessonTitle = null;
    $recommendedLessonId = null;

    foreach ($block['lessons'] as $lx) {
        if (!empty($lx['passed'])) $countPassed++;
        if ($lx['best_score'] !== null) $courseScores[] = (int)$lx['best_score'];
        if ((string)$lx['summary_review_status'] === 'acceptable') $summaryApproved++;
        if ((string)$lx['summary_review_status'] === 'needs_revision') $revisionCount++;

        $deadlineLabel = strtolower((string)$lx['deadline']['label']);
        if ((strpos($deadlineLabel, 'attention') !== false || $deadlineLabel === 'due today') && empty($lx['passed'])) {
            $overdueCount++;
        }
        if (!empty($lx['can_test'])) {
            $testReadyCount++;
        }
        if (!empty($lx['instructor_decision']['training_suspended']) || !empty($lx['locked'])) {
            $blockedCount++;
        }

        if ($nextLessonTitle === null && empty($lx['passed']) && empty($lx['locked'])) {
            $nextLessonTitle = (string)$lx['lesson_title'];
            $recommendedLessonId = (int)$lx['lesson_id'];
        }
    }

    $courseBlocks[$k]['lesson_count'] = $countLessons;
    $courseBlocks[$k]['passed_count'] = $countPassed;
    $courseBlocks[$k]['progress_pct'] = percent($countPassed, $countLessons);
    $courseBlocks[$k]['avg_score'] = $courseScores ? (int)round(array_sum($courseScores) / count($courseScores)) : null;
    $courseBlocks[$k]['deadline'] = deadline_progress_meta((string)$cohort['start_date'], (string)$block['last_deadline_utc']);
    $courseBlocks[$k]['summary_approved_count'] = $summaryApproved;
    $courseBlocks[$k]['revision_count'] = $revisionCount;
    $courseBlocks[$k]['overdue_count'] = $overdueCount;
    $courseBlocks[$k]['test_ready_count'] = $testReadyCount;
    $courseBlocks[$k]['blocked_count'] = $blockedCount;
    $courseBlocks[$k]['next_lesson_label'] = $nextLessonTitle ?: 'No pending lessons';
    $courseBlocks[$k]['recommended_lesson_id'] = $recommendedLessonId;
    $courseBlocks[$k]['motivation_meta'] = module_motivation_meta($courseBlocks[$k]);
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

$summariesPageExists = file_exists(__DIR__ . '/lesson_summaries.php');
$exportPdfExists = file_exists(__DIR__ . '/export_summaries_pdf.php');

$allLessonsFlat = [];
foreach ($courseBlocks as $cb) {
    foreach ($cb['lessons'] as $lx) {
        $lx['_module_title'] = $cb['course_title'];
        $allLessonsFlat[] = $lx;
    }
}

$nextBestStep = null;
$immediateAttention = [];

foreach ($allLessonsFlat as $lx) {
    $pa = $lx['primary_action'];

    if ($nextBestStep === null || (int)$pa['priority'] < (int)$nextBestStep['priority']) {
        $nextBestStep = [
            'priority' => (int)$pa['priority'],
            'lesson_id' => (int)$lx['lesson_id'],
            'lesson_label' => (string)$lx['lesson_title'],
            'module_title' => (string)$lx['_module_title'],
            'why' => (string)$pa['note'],
            'action_label' => (string)$pa['label'],
            'action_href' => (string)$pa['href'],
            'action_class' => (string)$pa['class']
        ];
    }

    if ((int)$pa['priority'] <= 50) {
        $immediateAttention[] = [
            'lesson_id' => (int)$lx['lesson_id'],
            'lesson_label' => (string)$lx['lesson_title'],
            'module_title' => (string)$lx['_module_title'],
            'why' => (string)$pa['note'],
            'label' => (string)$pa['label'],
            'href' => (string)$pa['href'],
            'class' => (string)$pa['class']
        ];
    }
}

$immediateAttention = array_slice($immediateAttention, 0, 6);
$recommendedLessonId = $nextBestStep ? (int)$nextBestStep['lesson_id'] : 0;

$momentumMessage = '';
if ($totalCompletedLessons > 0) {
    $momentumMessage = ($totalCompletedLessons >= 5)
        ? 'Your training momentum is building.'
        : 'You are making steady progress through the program.';
}

$todayProgressMessage = '';
foreach ($allLessonsFlat as $lx) {
    if (!empty($lx['test']['last']['completed_at'])) {
        try {
            $completed = new DateTime((string)$lx['test']['last']['completed_at'], new DateTimeZone('UTC'));
            $today = new DateTime('now', new DateTimeZone('UTC'));
            if ($completed->format('Y-m-d') === $today->format('Y-m-d')) {
                $todayProgressMessage = 'Great work today — another step completed toward becoming a pilot.';
                break;
            }
        } catch (Throwable $e) {
        }
    }
}

cw_header('Course');
?>
<style>
  .course-page-stack{display:flex;flex-direction:column;gap:22px}
  .hero-card{padding:26px 28px}
  .hero-eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.14em;color:#5f6f88;font-weight:700;margin-bottom:10px}
  .hero-title{margin:0;font-size:32px;line-height:1.02;letter-spacing:-0.04em;color:#152235;font-weight:800}
  .hero-sub{margin-top:12px;font-size:15px;color:#56677f;max-width:900px;line-height:1.55}
  .hero-meta{margin-top:14px;font-size:14px;color:#495a72;line-height:1.5}
  .hero-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
  .hero-btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 15px;border-radius:12px;text-decoration:none;font-size:14px;font-weight:700;color:#152235;background:#f4f7fb;border:1px solid rgba(15,23,42,0.08)}
  .hero-btn.primary{background:#12355f;color:#fff;border-color:#12355f}
  .hero-btn:hover{opacity:.95}
  .hero-btn.disabled{opacity:.45;pointer-events:none;cursor:default}

  .top-grid{display:grid;grid-template-columns:repeat(3,minmax(220px,1fr));gap:18px}
  .overview-card{padding:22px 24px}
  .overview-title{font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:#60718b;font-weight:700;margin-bottom:14px}
  .overview-main{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}
  .overview-big{font-size:40px;line-height:1;font-weight:800;letter-spacing:-0.04em;color:#152235;white-space:nowrap}
  .overview-sub{color:#5b6d85;font-size:14px;line-height:1.45;max-width:260px}
  .progress-shell{width:100%;height:12px;border-radius:999px;overflow:hidden;background:#e7edf4}
  .progress-fill{height:12px;border-radius:999px;background:linear-gradient(90deg,#113459 0%, #2458a6 100%)}
  .smallmuted{font-size:12px;color:#5f7088;margin-top:8px;line-height:1.45}
  .momentum-line{margin-top:10px;font-size:13px;color:#1d4f91;font-weight:700}
  .today-line{margin-top:8px;font-size:13px;color:#166534;font-weight:700}

  .support-grid{display:grid;grid-template-columns:1.25fr .75fr;gap:18px}
  .priority-card,.ai-placeholder{padding:22px 24px;border:1px solid rgba(15,23,42,0.06);border-radius:20px;background:#fff;box-shadow:0 10px 24px rgba(15,23,42,0.055)}
  .priority-label{font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:#5f718d;font-weight:700;margin-bottom:12px}
  .priority-title{font-size:24px;font-weight:800;color:#152235;line-height:1.12;margin:0}
  .priority-sub{margin-top:8px;font-size:14px;color:#56677f;line-height:1.5}
  .priority-why{margin-top:14px;font-size:15px;color:#32465f;line-height:1.55}
  .priority-actions{margin-top:16px;display:flex;gap:10px;flex-wrap:wrap}
  .next-step-meta{margin-top:10px;font-size:13px;color:#56677f;line-height:1.5}
  .next-step-reinforcement{margin-top:10px;font-size:13px;color:#1d4f91;font-weight:700}
  .ai-title{margin:0;font-size:20px;font-weight:800;color:#152235;line-height:1.15}
  .ai-text{margin-top:10px;font-size:14px;color:#56677f;line-height:1.6}

  .section-card{padding:22px 24px}
  .section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:14px}
  .section-title{margin:0;font-size:22px;line-height:1.05;letter-spacing:-0.02em;color:#152235}
  .section-sub{margin-top:6px;font-size:14px;color:#56677f;line-height:1.45}
  .count-pill{display:inline-block;padding:7px 11px;border-radius:999px;background:#edf4ff;color:#1d4f91;font-size:12px;font-weight:800;border:1px solid #d3e3ff;white-space:nowrap}

  .attention-list{display:flex;flex-direction:column;gap:10px}
  .attention-item{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:14px 16px;border-radius:16px;background:#f8fbfd;border:1px solid rgba(15,23,42,0.05)}
  .attention-main{min-width:0}
  .attention-title{font-size:14px;font-weight:700;color:#152235;line-height:1.3}
  .attention-meta{margin-top:5px;font-size:13px;color:#586b84;line-height:1.45}

  .course-card{border:1px solid rgba(15,23,42,0.06);border-radius:20px;background:#fff;box-shadow:0 10px 24px rgba(15,23,42,0.055);margin-bottom:16px;overflow:hidden}
  .course-card details{border:0}
  .course-card summary{list-style:none;cursor:pointer;padding:18px 20px}
  .course-card summary::-webkit-details-marker{display:none}
  .course-head{display:grid;grid-template-columns:60px 26px minmax(220px,1.45fr) minmax(180px,1fr) minmax(140px,0.8fr) minmax(210px,1fr);gap:12px;align-items:center}
  .course-badge{width:48px;height:48px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:17px;color:#fff;background:linear-gradient(135deg,#0b2744,#123b72)}
  .course-toggle{font-size:20px;color:#12355f;font-weight:900;text-align:center;transition:transform .2s ease}
  details[open] .course-toggle{transform:rotate(90deg)}
  .course-title{font-size:19px;font-weight:800;color:#152235;line-height:1.15}
  .metric-label{font-size:11px;color:#5f718d;margin-bottom:6px;font-weight:700;text-transform:uppercase;letter-spacing:.12em}
  .metric-value{font-size:15px;font-weight:800;color:#152235}
  .metric-sub{font-size:12px;color:#5b6d85;margin-top:6px;line-height:1.45}
  .module-micro{margin-top:8px;font-size:12px;color:#38506d;font-weight:700;line-height:1.45}
  .mini-progress{width:100%;height:8px;border-radius:999px;overflow:hidden;background:#e7edf4;margin-top:7px}
  .mini-progress > span{display:block;height:8px;border-radius:999px;background:linear-gradient(90deg,#113459 0%, #2458a6 100%)}
  .module-signal-row{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}

  .course-body{padding:0 20px 18px 20px;border-top:1px solid rgba(15,23,42,0.06);background:#fbfcfe}
  .lesson-table-wrap{overflow:visible;margin-top:12px}
  .lesson-table{width:100%;border-collapse:collapse;table-layout:fixed}
  .lesson-table th,.lesson-table td{padding:10px 8px;border-bottom:1px solid rgba(15,23,42,0.07);vertical-align:middle;text-align:left}
  .lesson-table th{font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:#60718b;font-weight:700}
  .lesson-title{font-size:14px;font-weight:700;color:#152235;line-height:1.3}

  .deadline-wrap{min-width:0}
  .deadline-date{font-weight:700;color:#152235;margin-bottom:4px;font-size:13px}
  .deadline-progress-shell{width:100%;height:7px;border-radius:999px;overflow:hidden;background:#e7edf4}
  .deadline-progress-fill{height:7px;border-radius:999px}
  .deadline-progress-fill.deadline-green{background:linear-gradient(90deg,#0f766e 0%, #14b8a6 100%)}
  .deadline-progress-fill.deadline-orange{background:linear-gradient(90deg,#d97706 0%, #f59e0b 100%)}
  .deadline-progress-fill.deadline-red{background:linear-gradient(90deg,#dc2626 0%, #ef4444 100%)}
  .deadline-progress-fill.deadline-neutral{background:linear-gradient(90deg,#64748b 0%, #94a3b8 100%)}
  .deadline-label{font-size:11px;font-weight:800;margin-top:4px}
  .deadline-label.deadline-green{color:#166534}
  .deadline-label.deadline-orange{color:#b45309}
  .deadline-label.deadline-red{color:#b91c1c}
  .deadline-label.deadline-neutral{color:#4b5563}

  .summary-compact{display:flex;flex-direction:column;gap:4px}
  .summary-bar-shell{width:100%;height:7px;border-radius:999px;overflow:hidden;background:#e7edf4}
  .summary-bar-fill{height:7px;border-radius:999px}
  .summary-bar-fill.ok{background:linear-gradient(90deg,#166534 0%, #22c55e 100%)}
  .summary-bar-fill.warn{background:linear-gradient(90deg,#b45309 0%, #f59e0b 100%)}
  .summary-bar-fill.danger{background:linear-gradient(90deg,#b91c1c 0%, #ef4444 100%)}
  .summary-bar-fill.info{background:linear-gradient(90deg,#1d4f91 0%, #3b82f6 100%)}
  .summary-bar-fill.neutral{background:linear-gradient(90deg,#64748b 0%, #94a3b8 100%)}
  .summary-label{font-size:11px;font-weight:700;color:#41556f;line-height:1.25}

  .state-pill{display:inline-block;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:800;line-height:1.2;border:1px solid transparent;white-space:nowrap}
  .state-pill.ok{background:#dcfce7;border-color:#86efac;color:#166534}
  .state-pill.danger{background:#fee2e2;border-color:#fca5a5;color:#991b1b}
  .state-pill.warn{background:#fef3c7;border-color:#fde68a;color:#92400e}
  .state-pill.info{background:#dbeafe;border-color:#93c5fd;color:#1d4ed8}
  .state-pill.neutral{background:#edf2f7;border-color:#d7dee9;color:#475569}

  .action-btn{display:inline-flex;align-items:center;justify-content:center;min-height:32px;padding:0 10px;border-radius:9px;text-decoration:none;font-size:11px;font-weight:800;white-space:nowrap;border:1px solid rgba(15,23,42,0.08);color:#152235;background:#f4f7fb}
  .action-btn.primary{background:#12355f;color:#fff;border-color:#12355f}
  .action-btn.warn{background:#fff7ed;color:#92400e;border-color:#fed7aa}
  .action-btn.danger{background:#fef2f2;color:#991b1b;border-color:#fecaca}
  .action-btn:hover{opacity:.95}
  .action-btn.disabled{opacity:.45;pointer-events:none}

  .status-text{font-size:12px;font-weight:700;color:#32465f;line-height:1.35}

  .resume-highlight td{background:#f7fbff}
  .resume-highlight{box-shadow:inset 3px 0 0 #2563eb}
  .module-resume-ring{box-shadow:0 0 0 2px rgba(37,99,235,0.18) inset}

  .empty-premium{padding:18px;border-radius:16px;border:1px dashed rgba(15,23,42,0.12);background:linear-gradient(180deg,#ffffff 0%,#fbfcfe 100%);color:#5f7088;font-size:14px}

  @media (max-width: 1320px){
    .top-grid{grid-template-columns:1fr}
    .support-grid{grid-template-columns:1fr}
  }
  @media (max-width: 1180px){
    .course-head{grid-template-columns:52px 28px 1fr}
    .course-head .metric-col{grid-column:1 / -1}
    .lesson-table{table-layout:auto}
    .lesson-table-wrap{overflow:auto}
  }
</style>

<div class="course-page-stack">

  <div class="card hero-card">
    <div class="hero-eyebrow">Theory Course</div>
    <h1 class="hero-title"><?= h($cohort['course_title']) ?></h1>
    <div class="hero-sub">
      Each step here moves you closer to becoming a safe and confident aviator.
    </div>
    <div class="hero-meta">
      Program: <strong><?= h($cohort['program_key']) ?></strong>
      · Cohort: <strong><?= h($cohort['name']) ?></strong>
      · Study Window: <strong><?= h($cohort['start_date']) ?></strong> to <strong><?= h($cohort['end_date']) ?></strong>
      · You’ve completed <strong><?= (int)$totalCompletedLessons ?></strong> of <strong><?= (int)$totalLessons ?></strong> lessons so far.
    </div>

    <div class="hero-actions">
      <a class="hero-btn" href="/student/dashboard.php">← Back to Dashboard</a>

      <?php if ($summariesPageExists): ?>
        <a class="hero-btn primary" href="/student/lesson_summaries.php?cohort_id=<?= (int)$cohortId ?>">Lesson Summaries</a>
      <?php else: ?>
        <span class="hero-btn disabled">Lesson Summaries</span>
      <?php endif; ?>

      <?php if ($exportPdfExists): ?>
        <a class="hero-btn" href="/student/export_summaries_pdf.php?cohort_id=<?= (int)$cohortId ?>">Export Summaries PDF</a>
      <?php else: ?>
        <span class="hero-btn disabled">Export Summaries PDF</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="top-grid">
    <div class="card overview-card">
      <div class="overview-title">Program Study Overview</div>
      <div class="overview-main">
        <div class="overview-big"><?= (int)$programProgressPct ?>%</div>
        <div class="overview-sub">
          You’ve completed <?= (int)$totalCompletedLessons ?>/<?= (int)$totalLessons ?> lessons.
        </div>
      </div>
      <div class="progress-shell">
        <div class="progress-fill" style="width:<?= (int)$programProgressPct ?>%;"></div>
      </div>
      <div class="smallmuted">Consistent progress leads to confident flying.</div>
      <?php if ($momentumMessage !== ''): ?>
        <div class="momentum-line"><?= h($momentumMessage) ?></div>
      <?php endif; ?>
      <?php if ($todayProgressMessage !== ''): ?>
        <div class="today-line"><?= h($todayProgressMessage) ?></div>
      <?php endif; ?>
    </div>

    <div class="card overview-card">
      <div class="overview-title">Average Progress Test Score</div>
      <div class="overview-main">
        <div class="overview-big"><?= $programAvgScore !== null ? (int)$programAvgScore . '%' : '—' ?></div>
        <div class="overview-sub">
          Based on your best completed result in each lesson test.
        </div>
      </div>
      <div class="progress-shell">
        <div class="progress-fill" style="width:<?= $programAvgScore !== null ? (int)$programAvgScore : 0 ?>%;"></div>
      </div>
    </div>

    <div class="card overview-card">
      <div class="overview-title">Ranking &amp; On-Time Performance</div>
      <div class="overview-main">
        <div class="overview-big"><?= $studentRankPct !== null ? (int)$studentRankPct . '%' : '—' ?></div>
        <div class="overview-sub">
          Your current standing places you ahead of <?= $studentRankPct !== null ? (int)$studentRankPct : '—' ?>% of students in this program.
        </div>
      </div>
      <div class="smallmuted">
        On-time performance: <strong><?= (int)$studentOnTimePct ?>%</strong>
        <?php if ($programAvgOnTimePct !== null): ?>
          · Program average: <strong><?= (int)$programAvgOnTimePct ?>%</strong>
        <?php endif; ?>
      </div>
      <div class="progress-shell">
        <div class="progress-fill" style="width:<?= (int)$studentOnTimePct ?>%;"></div>
      </div>
    </div>
  </div>

  <div class="support-grid">
    <div class="priority-card">
      <div class="priority-label">Your Next Step Toward Becoming a Pilot</div>
      <h2 class="priority-title"><?= $nextBestStep ? h($nextBestStep['lesson_label']) : 'No action available yet' ?></h2>
      <div class="priority-sub">This is the single most important action to keep your training momentum.</div>

      <?php if ($nextBestStep): ?>
        <div class="next-step-meta">Module: <?= h($nextBestStep['module_title']) ?></div>
        <div class="priority-why"><?= h($nextBestStep['why']) ?></div>
        <div class="priority-actions">
          <?php if ($nextBestStep['action_href'] !== ''): ?>
            <a class="action-btn <?= h($nextBestStep['action_class']) ?>" href="<?= h($nextBestStep['action_href']) ?>"><?= h($nextBestStep['action_label']) ?></a>
          <?php else: ?>
            <span class="action-btn <?= h($nextBestStep['action_class']) ?> disabled"><?= h($nextBestStep['action_label']) ?></span>
          <?php endif; ?>
        </div>
        <div class="next-step-reinforcement"><?= h($nextBestStep['why']) ?></div>
      <?php else: ?>
        <div class="empty-premium" style="margin-top:14px;">No next step could be determined yet.</div>
      <?php endif; ?>
    </div>

    <div class="ai-placeholder">
      <h3 class="ai-title">AI Instructor Assistant (coming soon)</h3>
      <div class="ai-text">
        Soon you will be able to ask your AI Instructor questions about any lesson topic.
      </div>
      <div class="ai-text">
        The AI will answer only using:
      </div>
      <div class="ai-text">
        • course slide content<br>
        • official FAA references<br>
        • canonical training data
      </div>
      <div class="ai-text">
        It will guide you directly to the exact slide where the concept is explained.
      </div>
    </div>
  </div>

  <div class="card section-card">
    <div class="section-head">
      <div>
        <h2 class="section-title">Immediate Attention</h2>
        <div class="section-sub">
          Focus on this shortlist first, then continue through the full module overview below.
        </div>
      </div>
      <div class="count-pill"><?= count($immediateAttention) ?> shown</div>
    </div>

    <?php if (!$immediateAttention): ?>
      <div class="empty-premium">No urgent items need your attention right now.</div>
    <?php else: ?>
      <div class="attention-list">
        <?php foreach ($immediateAttention as $item): ?>
          <?php $isRecommended = ((int)$item['lesson_id'] === $recommendedLessonId); ?>
          <div class="attention-item <?= $isRecommended ? 'module-resume-ring' : '' ?>">
            <div class="attention-main">
              <div class="attention-title"><?= h($item['lesson_label']) ?></div>
              <div class="attention-meta"><?= h($item['module_title']) ?> · <?= h($item['why']) ?></div>
            </div>
            <div>
              <?php if ($item['href'] !== ''): ?>
                <a class="action-btn <?= h($item['class']) ?>" href="<?= h($item['href']) ?>"><?= h($item['label']) ?></a>
              <?php else: ?>
                <span class="action-btn <?= h($item['class']) ?> disabled"><?= h($item['label']) ?></span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card section-card">
    <div class="section-head">
      <div>
        <h2 class="section-title">Module Overview</h2>
        <div class="section-sub">
          Expand a module to scan all lessons quickly and clearly.
        </div>
      </div>
      <div class="count-pill"><?= count($courseBlocks) ?> module<?= count($courseBlocks) === 1 ? '' : 's' ?></div>
    </div>

    <?php if (!$courseBlocks): ?>
      <div class="empty-premium">No lessons found for this cohort.</div>
    <?php else: ?>
      <?php $courseIndex = 0; ?>
      <?php foreach ($courseBlocks as $course): ?>
        <?php
          $courseIndex++;
          $moduleMood = $course['motivation_meta'];
          $moduleHasRecommended = ((int)$course['recommended_lesson_id'] === $recommendedLessonId && $recommendedLessonId > 0);
        ?>
        <div class="course-card <?= $moduleHasRecommended ? 'module-resume-ring' : '' ?>">
          <details <?= $moduleHasRecommended ? 'open' : '' ?>>
            <summary>
              <div class="course-head">
                <div class="course-badge"><?= (int)$courseIndex ?></div>
                <div class="course-toggle">›</div>

                <div>
                  <div class="metric-label">Module</div>
                  <div class="course-title"><?= h($course['course_title']) ?></div>
                  <div class="metric-sub">Next lesson: <?= h($course['next_lesson_label']) ?></div>
                  <div class="module-micro"><?= h($moduleMood['micro']) ?></div>

                  <div class="module-signal-row">
                    <span class="state-pill <?= h($moduleMood['class']) ?>"><?= h($moduleMood['label']) ?></span>

                    <?php if ((int)$course['revision_count'] > 0): ?>
                      <span class="state-pill danger"><?= (int)$course['revision_count'] ?> revision<?= (int)$course['revision_count'] === 1 ? '' : 's' ?></span>
                    <?php endif; ?>
                    <?php if ((int)$course['overdue_count'] > 0): ?>
                      <span class="state-pill warn"><?= (int)$course['overdue_count'] ?> priority</span>
                    <?php endif; ?>
                    <?php if ((int)$course['test_ready_count'] > 0): ?>
                      <span class="state-pill info"><?= (int)$course['test_ready_count'] ?> test ready</span>
                    <?php endif; ?>
                    <?php if ((int)$course['blocked_count'] > 0): ?>
                      <span class="state-pill danger"><?= (int)$course['blocked_count'] ?> blocked</span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="metric-col">
                  <div class="metric-label">Module Progress</div>
                  <div class="metric-value"><?= (int)$course['progress_pct'] ?>%</div>
                  <div class="mini-progress"><span style="width:<?= (int)$course['progress_pct'] ?>%;"></span></div>
                  <div class="metric-sub"><?= (int)$course['passed_count'] ?>/<?= (int)$course['lesson_count'] ?> completed</div>
                </div>

                <div class="metric-col">
                  <div class="metric-label">Average Score</div>
                  <div class="metric-value"><?= $course['avg_score'] !== null ? (int)$course['avg_score'] . '%' : '—' ?></div>
                  <div class="metric-sub"><?= (int)$course['summary_approved_count'] ?>/<?= (int)$course['lesson_count'] ?> summaries approved</div>
                </div>

                <div class="metric-col">
                  <div class="metric-label">Final Module Deadline</div>
                  <div class="deadline-date"><?= h($course['deadline']['date']) ?></div>
                  <div class="deadline-progress-shell">
                    <div class="deadline-progress-fill <?= h($course['deadline']['class']) ?>" style="width:<?= (int)$course['deadline']['pct'] ?>%;"></div>
                  </div>
                  <div class="deadline-label <?= h($course['deadline']['class']) ?>"><?= h($course['deadline']['label']) ?></div>
                </div>
              </div>
            </summary>

            <div class="course-body">
              <div class="lesson-table-wrap">
                <table class="lesson-table">
                  <tr>
                    <th style="width:24%;">Lesson</th>
                    <th style="width:16%;">Deadline</th>
                    <th style="width:12%;">Study</th>
                    <th style="width:14%;">Summary</th>
                    <th style="width:12%;">Progress Test</th>
                    <th style="width:9%;">Score</th>
                    <th style="width:13%;">Status</th>
                  </tr>

                  <?php foreach ($course['lessons'] as $lx): ?>
                    <?php
                      $last = $lx['test']['last'];
                      $attemptsLeft = (int)$lx['attempts_left'];
                      $scoreMeta = score_badge_meta($lx['test_passed'], $lx['best_score'], $last, $attemptsLeft);
                      $rowAction = $lx['primary_action'];
                      $rowClass = ((int)$lx['lesson_id'] === $recommendedLessonId) ? 'resume-highlight' : '';

                      $statusText = 'Study the lesson';
                      if (!empty($lx['locked'])) {
                          $statusText = 'Locked';
                      } elseif (!empty($lx['instructor_decision']['training_suspended'])) {
                          $statusText = 'Training paused';
                      } elseif (!empty($lx['instructor_decision']['one_on_one_required']) && empty($lx['instructor_decision']['one_on_one_completed'])) {
                          $statusText = 'Instructor session required';
                      } elseif ((string)$lx['summary_review_status'] === 'pending') {
                          $statusText = 'Waiting for instructor review';
                      } elseif ((string)$lx['summary_review_status'] === 'needs_revision') {
                          $statusText = 'Improve summary';
                      } elseif (!empty($lx['can_test'])) {
                          $statusText = 'Ready for progress test';
                      } elseif (!$lx['test_passed'] && $last && isset($last['status']) && (string)$last['status'] === 'completed') {
                          $statusText = 'Improve progress test';
                      } elseif (!empty($lx['passed'])) {
                          $statusText = 'Completed';
                      }

                      $studyHref = '';
                      if ((int)$lx['first_slide_id'] > 0 && empty($lx['locked'])) {
                          $studyHref = '/player/slide.php?slide_id=' . (int)$lx['first_slide_id'];
                      }

                      $testHref = '';
                      $testLabel = '';
                      if (!empty($lx['can_test'])) {
                          $testHref = (string)$lx['progress_test_url'];
                          $testLabel = ($lx['test']['max_attempt'] > 0 ? 'Retake Test' : 'Take Test');
                      }
                    ?>
                    <tr class="<?= h($rowClass) ?>">
                      <td>
                        <div class="lesson-title"><?= h($lx['lesson_title']) ?></div>
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
                        <?php if ($studyHref !== ''): ?>
                          <a class="action-btn primary" href="<?= h($studyHref) ?>">Continue Lesson</a>
                        <?php else: ?>
                          <span class="action-btn disabled">Continue Lesson</span>
                        <?php endif; ?>
                      </td>

                      <td>
                        <div class="summary-compact">
                          <div class="summary-bar-shell">
                            <div class="summary-bar-fill <?= h($lx['summary_meta']['class']) ?>" style="width:<?= (int)$lx['summary_meta']['pct'] ?>%;"></div>
                          </div>
                          <div class="summary-label"><?= h($lx['summary_meta']['label']) ?></div>
                        </div>
                      </td>

                      <td>
                        <?php if ($testHref !== ''): ?>
                          <a class="action-btn primary" href="<?= h($testHref) ?>"><?= h($testLabel) ?></a>
                        <?php else: ?>
                          <span class="action-btn disabled"><?= ($lx['test']['max_attempt'] > 0 ? 'Retake Test' : 'Take Test') ?></span>
                        <?php endif; ?>
                      </td>

                      <td>
                        <span class="state-pill <?= h($scoreMeta['class']) ?>"><?= h($scoreMeta['label']) ?></span>
                      </td>

                      <td>
                        <div class="status-text"><?= h($statusText) ?></div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </table>
              </div>
            </div>
          </details>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php cw_footer(); ?>