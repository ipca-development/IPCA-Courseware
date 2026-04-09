<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/openai.php';

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

$displayUserSt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$displayUserSt->execute([$userId]);
$displayUser = $displayUserSt->fetch(PDO::FETCH_ASSOC);
if (!$displayUser) {
    $displayUser = is_array($u) ? $u : [];
}

function dash_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
            return 'Awaiting Summary Review';
        case 'awaiting_test_completion':
            return 'Awaiting Test Completion';
        case 'summary_required':
            return 'Summary Required';
        case 'remediation_required':
            return 'Remediation Required';
        case 'instructor_required':
            return 'Instructor Action Required';
        case 'deadline_blocked':
            return 'Deadline Blocked';
        case 'training_suspended':
            return 'Training Suspended';
        case 'completed':
            return 'Completed';
        default:
            return $status !== '' ? $status : 'Status';
    }
}

function student_action_badge_class(string $status): string
{
    if ($status === 'training_suspended' || $status === 'deadline_blocked') {
        return 'danger';
    }
    if ($status === 'remediation_required' || $status === 'instructor_required' || $status === 'summary_required') {
        return 'warn';
    }
    if ($status === 'awaiting_summary_review' || $status === 'awaiting_test_completion') {
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

function student_display_first_name(array $user): string
{
    $first = trim((string)($user['first_name'] ?? ''));
    if ($first !== '') {
        return $first;
    }

    $name = trim((string)($user['name'] ?? ''));
    if ($name !== '') {
        $parts = preg_split('/\s+/', $name);
        if (!empty($parts[0])) {
            return (string)$parts[0];
        }
    }

    $email = trim((string)($user['email'] ?? ''));
    if ($email !== '') {
        $pos = strpos($email, '@');
        return $pos !== false ? substr($email, 0, $pos) : $email;
    }

    return 'Student';
}

function student_display_name(array $user): string
{
    $first = trim((string)($user['first_name'] ?? ''));
    $last = trim((string)($user['last_name'] ?? ''));
    $full = trim($first . ' ' . $last);
    if ($full !== '') {
        return $full;
    }

    $name = trim((string)($user['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    return student_display_first_name($user);
}

function student_avatar_url(array $user): string
{
    $value = trim((string)($user['photo_path'] ?? ''));
    if ($value === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $value)) {
        return $value;
    }

    if (strpos($value, '/') === 0) {
        return $value;
    }

    return '/' . ltrim($value, '/');
}

function student_initials(array $user): string
{
    $first = trim((string)($user['first_name'] ?? ''));
    $last = trim((string)($user['last_name'] ?? ''));

    $initials = '';
    if ($first !== '') {
        $initials .= mb_strtoupper(mb_substr($first, 0, 1));
    }
    if ($last !== '') {
        $initials .= mb_strtoupper(mb_substr($last, 0, 1));
    }

    if ($initials !== '') {
        return $initials;
    }

    $name = student_display_name($user);
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach ($parts as $p) {
        if ($p !== '') {
            $initials .= mb_strtoupper(mb_substr($p, 0, 1));
        }
        if (mb_strlen($initials) >= 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : 'S';
}

function student_dashboard_ai_cache_key(int $userId): string
{
    return sys_get_temp_dir() . '/ipca_dashboard_welcome_' . $userId . '.txt';
}

function student_dashboard_ai_fallback_message(string $firstName): string
{
    return "Welcome {$firstName} to your dashboard page. Keep building steady pilot habits today. One disciplined training session at a time is how strong aviators are made.";
}

function student_openai_extract_output_text(array $resp): string
{
    $out = $resp['output'] ?? [];
    if (!is_array($out)) {
        return '';
    }

    $text = '';
    foreach ($out as $item) {
        if (!is_array($item)) {
            continue;
        }

        $content = $item['content'] ?? [];
        if (!is_array($content)) {
            continue;
        }

        foreach ($content as $c) {
            if (is_array($c) && ($c['type'] ?? '') === 'output_text') {
                $text .= (string)($c['text'] ?? '');
            }
        }
    }

    return trim($text);
}

function student_dashboard_generate_ai_welcome(
    array $user,
    int $theoryProgressPct,
    int $urgentLessonCount,
    int $summaryWarningCount,
    int $actionCount
): string {
    $userId = (int)($user['id'] ?? 0);
    $firstName = student_display_first_name($user);
    $studentName = student_display_name($user);
    $cacheFile = student_dashboard_ai_cache_key($userId);

    if ($userId > 0 && is_file($cacheFile)) {
        $age = time() - (int)@filemtime($cacheFile);
        if ($age >= 0 && $age < 14400) {
            $cached = trim((string)@file_get_contents($cacheFile));
            if ($cached !== '') {
                return $cached;
            }
        }
    }

    $input = <<<PROMPT
Write a short personalized motivational welcome message for a student pilot dashboard.

Rules:
- Address the student by first name: {$firstName}
- Mention this is their dashboard
- Tone must be professional, motivating, aviation-themed, and natural
- Length must be 2 to 5 sentences
- Include encouragement
- Include one practical study or training tip
- Include one fun aviation fact or aviation-style motivational element
- Keep it concise
- Do not use markdown
- Do not use bullet points
- Do not use quotation marks
- Do not sound cheesy
- Return plain text only

Student full name: {$studentName}
Theory progress: {$theoryProgressPct}%
Urgent lesson deadlines: {$urgentLessonCount}
Summary warnings: {$summaryWarningCount}
Action items: {$actionCount}
PROMPT;

    try {
        $resp = cw_openai_responses([
            'model' => cw_openai_model(),
            'input' => $input,
            'max_output_tokens' => 180
        ]);

        $message = student_openai_extract_output_text($resp);
        $message = trim((string)preg_replace('/\s+/', ' ', $message));

        if ($message === '') {
            $message = student_dashboard_ai_fallback_message($firstName);
        }

        if ($userId > 0) {
            @file_put_contents($cacheFile, $message);
        }

        return $message;
    } catch (Throwable $e) {
        return student_dashboard_ai_fallback_message($firstName);
    }
}

function student_program_title(string $programKey): string
{
    $programKey = trim($programKey);
    if ($programKey === '') {
        return 'Program';
    }

    $programKey = str_replace(['-', '_'], ' ', $programKey);
    return ucwords($programKey);
}

$stmt = $pdo->prepare("
  SELECT
      co.id,
      co.name,
      co.start_date,
      co.end_date,
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
$urgentLessonCount = 0;
$summaryWarningCount = 0;
$actionCount = 0;

$urgentLessons = [];
$summaryWarnings = [];
$actionItems = [];
$instructorMessages = [];
$primaryCohortId = null;

/*
|--------------------------------------------------------------------------
| Canonical current-state dashboard flags from lesson_activity
|--------------------------------------------------------------------------
*/
$dashboardFlags = [
    'training_suspended' => false,
    'summary_revision_required' => false,
    'one_on_one_required' => false,
    'extra_attempts_granted' => 0,
];

$dashboardFlagsSt = $pdo->prepare("
    SELECT
        COALESCE(SUM(la.granted_extra_attempts), 0) AS total_extra_attempts,
        MAX(CASE WHEN la.training_suspended = 1 THEN 1 ELSE 0 END) AS has_training_suspended,
        MAX(CASE WHEN la.one_on_one_required = 1 AND la.one_on_one_completed = 0 THEN 1 ELSE 0 END) AS has_one_on_one_required,
        MAX(CASE WHEN la.summary_status = 'needs_revision' THEN 1 ELSE 0 END) AS has_summary_revision
    FROM lesson_activity la
    WHERE la.user_id = ?
");
$dashboardFlagsSt->execute([$userId]);
$dashboardFlagsRow = $dashboardFlagsSt->fetch(PDO::FETCH_ASSOC);

if ($dashboardFlagsRow) {
    $dashboardFlags['training_suspended'] = !empty($dashboardFlagsRow['has_training_suspended']);
    $dashboardFlags['summary_revision_required'] = !empty($dashboardFlagsRow['has_summary_revision']);
    $dashboardFlags['one_on_one_required'] = !empty($dashboardFlagsRow['has_one_on_one_required']);
    $dashboardFlags['extra_attempts_granted'] = (int)($dashboardFlagsRow['total_extra_attempts'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| Latest instructor communication feed from student_required_actions
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
");
$instructorMessagesSt->execute([$userId]);
$instructorMessages = $instructorMessagesSt->fetchAll(PDO::FETCH_ASSOC);

foreach ($cohorts as $idx => $co) {
    $cohortId = (int)$co['id'];

    if ($idx === 0) {
        $primaryCohortId = $cohortId;
    }

    $totalSt = $pdo->prepare("
        SELECT COUNT(*)
        FROM cohort_lesson_deadlines
        WHERE cohort_id = ?
    ");
    $totalSt->execute([$cohortId]);
    $totalLessons = (int)$totalSt->fetchColumn();
    $totalLessonsAll += $totalLessons;

    $passedSt = $pdo->prepare("
        SELECT COUNT(DISTINCT la.lesson_id)
        FROM lesson_activity la
        WHERE la.user_id = ?
          AND la.cohort_id = ?
          AND la.lesson_id IN (
              SELECT cld.lesson_id
              FROM cohort_lesson_deadlines cld
              WHERE cld.cohort_id = ?
          )
          AND (
              la.completion_status = 'completed'
              OR la.test_pass_status = 'passed'
          )
    ");
    $passedSt->execute([$userId, $cohortId, $cohortId]);
    $passedLessons = (int)$passedSt->fetchColumn();
    $passedLessonsAll += $passedLessons;

    $urgentCountSt = $pdo->prepare("
        SELECT COUNT(*)
        FROM cohort_lesson_deadlines d
        LEFT JOIN lesson_activity la
          ON la.user_id = ?
         AND la.cohort_id = ?
         AND la.lesson_id = d.lesson_id
        WHERE d.cohort_id = ?
          AND (
            la.completion_status IS NULL
            OR la.completion_status <> 'completed'
          )
    ");
    $urgentCountSt->execute([$userId, $cohortId, $cohortId]);
    $urgentLessonCount += (int)$urgentCountSt->fetchColumn();

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
    ");
    $urgentSt->execute([
        (string)$co['name'],
        $cohortId,
        $userId,
        $cohortId,
        $cohortId
    ]);
    $urgentRows = $urgentSt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($urgentRows as $r) {
        $r['days_left'] = student_days_left((string)$r['deadline_utc']);
        $urgentLessons[] = $r;
    }

    $summaryCountSt = $pdo->prepare("
        SELECT COUNT(*)
        FROM lesson_summaries ls
        WHERE ls.user_id = ?
          AND ls.cohort_id = ?
          AND ls.review_status IN ('needs_revision', 'pending')
    ");
    $summaryCountSt->execute([$userId, $cohortId]);
    $summaryWarningCount += (int)$summaryCountSt->fetchColumn();

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
    ");
    $summaryWarnSt->execute([
        (string)$co['name'],
        $cohortId,
        $userId,
        $cohortId
    ]);
    $summaryRows = $summaryWarnSt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($summaryRows as $r) {
        $summaryWarnings[] = $r;
    }

    $actionCountSt = $pdo->prepare("
        SELECT COUNT(*)
        FROM lesson_activity la
        WHERE la.user_id = ?
          AND la.cohort_id = ?
          AND la.completion_status IN (
            'awaiting_summary_review',
            'awaiting_test_completion',
            'summary_required',
            'remediation_required',
            'instructor_required',
            'deadline_blocked',
            'training_suspended'
          )
    ");
    $actionCountSt->execute([$userId, $cohortId]);
    $actionCount += (int)$actionCountSt->fetchColumn();

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
            'awaiting_test_completion',
            'summary_required',
            'remediation_required',
            'instructor_required',
            'deadline_blocked',
            'training_suspended'
          )
        ORDER BY la.updated_at DESC
    ");
    $actionSt->execute([
        (string)$co['name'],
        $cohortId,
        $userId,
        $cohortId
    ]);
    $actionRows = $actionSt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($actionRows as $r) {
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

usort($summaryWarnings, function ($a, $b) {
    $aPriority = ((string)($a['review_status'] ?? '') === 'needs_revision') ? 0 : 1;
    $bPriority = ((string)($b['review_status'] ?? '') === 'needs_revision') ? 0 : 1;
    if ($aPriority !== $bPriority) {
        return $aPriority <=> $bPriority;
    }
    return strtotime((string)($b['updated_at'] ?? '')) <=> strtotime((string)($a['updated_at'] ?? ''));
});

usort($actionItems, function ($a, $b) {
    return strtotime((string)($b['updated_at'] ?? '')) <=> strtotime((string)($a['updated_at'] ?? ''));
});

$summaryNeedsRevisionCount = 0;
$summaryPendingCount = 0;
foreach ($summaryWarnings as $sw) {
    if (($sw['review_status'] ?? '') === 'needs_revision') {
        $summaryNeedsRevisionCount++;
    } elseif (($sw['review_status'] ?? '') === 'pending') {
        $summaryPendingCount++;
    }
}

if ($summaryNeedsRevisionCount > 0) {
    $dashboardFlags['summary_revision_required'] = true;
}

$displayName = student_display_name($displayUser);
$avatarUrl = student_avatar_url($displayUser);
$avatarInitials = student_initials($displayUser);
$welcomeMessage = student_dashboard_generate_ai_welcome(
    $displayUser,
    $theoryProgressPct,
    $urgentLessonCount,
    $summaryWarningCount,
    $actionCount
);

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
  .hero-wrap{
    display:flex;
    align-items:flex-start;
    gap:18px;
  }
  .hero-avatar{
    width:74px;
    height:74px;
    border-radius:50%;
    overflow:hidden;
    flex:0 0 74px;
    background:linear-gradient(180deg,#123b72 0%, #2f6ac6 100%);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:24px;
    font-weight:800;
    letter-spacing:-0.03em;
    box-shadow:0 8px 24px rgba(18,59,114,.18);
  }
  .hero-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
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
    max-width:860px;
    line-height:1.65;
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
    min-width:0;
  }

  .scroll-panel{
    max-height:300px;
    overflow-y:auto;
    padding-right:4px;
  }
  .scroll-panel.three-visible{
    max-height:286px;
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
    .hero-wrap{
      flex-direction:column;
      align-items:flex-start;
    }
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
    <div class="hero-wrap">
      <div class="hero-avatar">
        <?php if ($avatarUrl !== ''): ?>
          <img src="<?= dash_h($avatarUrl) ?>" alt="<?= dash_h($displayName) ?>">
        <?php else: ?>
          <?= dash_h($avatarInitials) ?>
        <?php endif; ?>
      </div>

      <div style="min-width:0;">
        <div class="hero-eyebrow">Student Workspace</div>
        <h2 class="hero-title">Training Overview</h2>
        <div class="hero-sub"><?= dash_h($welcomeMessage) ?></div>
      </div>
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
          Your instructor granted a total of <?= (int)$dashboardFlags['extra_attempts_granted'] ?> additional progress test attempt(s) across your current lesson activity states.
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
        <div class="kpi-value"><?= $urgentLessonCount ?></div>
        <div class="kpi-sub">Incomplete lessons with active deadlines currently visible in your theory path.</div>
      </div>

      <div class="card kpi-card">
        <div class="kpi-label">Summary Warnings</div>
        <div class="kpi-value"><?= $summaryWarningCount ?></div>
        <div class="kpi-sub">Summary items needing revision or waiting for instructor review.</div>
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
          <div class="progress-fill-student" style="width:<?= max(0, min(100, $theoryProgressPct)) ?>%;"></div>
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
          <a class="mini-action" href="/student/dashboard.php<?= ($role === 'admin' && $userId > 0) ? '?user_id=' . (int)$userId : '' ?>">Refresh Overview</a>
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
          <div class="scroll-panel three-visible">
            <div class="message-list">
              <?php foreach ($instructorMessages as $row): ?>
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
                      Lesson <?= (int)$row['external_lesson_id'] ?> · <?= dash_h((string)$row['lesson_title']) ?>
                    </div>
                    <div class="item-meta">
                      <?= dash_h((string)$row['cohort_name']) ?> · <?= dash_h(dash_fmt_datetime_student((string)$row['decision_at'])) ?>
                    </div>

                    <?php if ($notesSnippet !== ''): ?>
                      <div class="message-note"><?= dash_h($notesSnippet) ?></div>
                    <?php endif; ?>

                    <?php if ((int)($row['granted_extra_attempts'] ?? 0) > 0): ?>
                      <div class="message-note">Additional attempts granted: <?= (int)$row['granted_extra_attempts'] ?></div>
                    <?php endif; ?>
                  </div>

                  <div class="item-side">
                    <div class="badge <?= dash_h($decisionBadgeClass) ?>"><?= dash_h($decisionLabel) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
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
          <div class="scroll-panel three-visible">
            <div class="warn-list">
              <?php foreach ($summaryWarnings as $row): ?>
                <?php
                  $feedbackSnippet = '';
                  if ((string)$row['review_status'] === 'needs_revision') {
                      $feedbackSnippet = student_snippet((string)($row['review_notes_by_instructor'] ?: $row['review_feedback']), 160);
                  }
                ?>
                <div class="warn-item">
                  <div class="item-main">
                    <div class="item-title">
                      Lesson <?= (int)$row['external_lesson_id'] ?> · <?= dash_h((string)$row['lesson_title']) ?>
                    </div>
                    <div class="item-meta">
                      <?= dash_h((string)$row['cohort_name']) ?> · Updated <?= dash_h(dash_fmt_datetime_student((string)$row['updated_at'])) ?>
                    </div>

                    <?php if ($feedbackSnippet !== ''): ?>
                      <div class="message-note"><?= dash_h($feedbackSnippet) ?></div>
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
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel-grid equal">
      <div class="card panel-card">
        <div class="panel-head">
          <div>
            <h3 class="panel-title">Urgent Lesson Deadlines</h3>
            <div class="panel-sub">All current incomplete lesson deadlines, ordered from most urgent to least urgent.</div>
          </div>
        </div>

        <?php if (!$urgentLessons): ?>
          <div class="empty-premium">No urgent lesson deadlines found.</div>
        <?php else: ?>
          <div class="scroll-panel three-visible">
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
                      Lesson <?= (int)$row['external_lesson_id'] ?> · <?= dash_h((string)$row['title']) ?>
                    </div>
                    <div class="item-meta">
                      <?= dash_h((string)$row['cohort_name']) ?> · Due <?= dash_h(dash_fmt_date_student((string)$row['deadline_utc'])) ?>
                    </div>
                    <div class="deadline-progress-shell">
                      <div class="deadline-progress-fill <?= dash_h($progressClass) ?>" style="width:<?= $progressPct ?>%;"></div>
                    </div>
                  </div>
                  <div class="item-side">
                    <div class="badge <?= dash_h($badgeClass) ?>"><?= dash_h(student_deadline_label($row['days_left'])) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
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
          <div class="scroll-panel three-visible">
            <div class="action-list">
              <?php foreach ($actionItems as $row): ?>
                <?php
                  $status = (string)$row['completion_status'];
                  $badgeClass = student_action_badge_class($status);
                  $statusLabel = student_action_label($status);
                ?>
                <div class="action-item">
                  <div class="item-main">
                    <div class="item-title">
                      Lesson <?= (int)$row['external_lesson_id'] ?> · <?= dash_h((string)$row['lesson_title']) ?>
                    </div>
                    <div class="item-meta">
                      <?= dash_h((string)$row['cohort_name']) ?>
                      <?php if (!empty($row['effective_deadline_utc'])): ?>
                        · Deadline <?= dash_h(dash_fmt_date_student((string)$row['effective_deadline_utc'])) ?>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="item-side">
                    <div class="badge <?= dash_h($badgeClass) ?>"><?= dash_h($statusLabel) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="cohort-stack">
      <?php foreach ($cohorts as $co): ?>
        <?php
          $cohortId = (int)$co['id'];

          $totalSt = $pdo->prepare("
            SELECT COUNT(*)
            FROM cohort_lesson_deadlines
            WHERE cohort_id = ?
          ");
          $totalSt->execute([$cohortId]);
          $totalLessons = (int)$totalSt->fetchColumn();

          $passedSt = $pdo->prepare("
            SELECT COUNT(DISTINCT la.lesson_id)
            FROM lesson_activity la
            WHERE la.user_id = ?
              AND la.cohort_id = ?
              AND la.lesson_id IN (
                  SELECT lesson_id
                  FROM cohort_lesson_deadlines
                  WHERE cohort_id = ?
              )
              AND (
                  la.completion_status = 'completed'
                  OR la.test_pass_status = 'passed'
              )
          ");
          $passedSt->execute([$userId, $cohortId, $cohortId]);
          $passedLessons = (int)$passedSt->fetchColumn();

          $pct = ($totalLessons > 0)
            ? (int)round(($passedLessons / $totalLessons) * 100)
            : 0;

          $nextSt = $pdo->prepare("
            SELECT d.deadline_utc, l.title, l.external_lesson_id
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
            LIMIT 1
          ");
          $nextSt->execute([$userId, $cohortId, $cohortId]);
          $nextRow = $nextSt->fetch(PDO::FETCH_ASSOC);

          $programTitle = student_program_title((string)$co['program_key']);
        ?>
          <div class="card cohort-card">
            <div class="cohort-header">
              <div>
                <h3 class="cohort-title"><?= dash_h($programTitle) ?></h3>
                <div class="cohort-sub">
                  Course: <?= dash_h((string)$co['course_title']) ?>
                  · Cohort: <?= dash_h((string)$co['name']) ?>
                  · Start: <?= dash_h(dash_fmt_date_student((string)$co['start_date'])) ?>
                  · End: <?= dash_h(dash_fmt_date_student((string)$co['end_date'])) ?>
                </div>
              </div>

              <div class="cohort-chip"><?= $pct ?>% complete</div>
            </div>

            <div class="cohort-progress">
              <div class="progress-meta">
                <div class="progress-label"><?= $passedLessons ?>/<?= $totalLessons ?> lessons completed</div>
              </div>
              <div class="progress-shell-student">
                <div class="progress-fill-student" style="width:<?= max(0, min(100, $pct)) ?>%;"></div>
              </div>
            </div>

            <?php if ($nextRow): ?>
              <div class="item-meta" style="margin-top:14px;">
                Next lesson deadline: <?= dash_h(dash_fmt_date_student((string)$nextRow['deadline_utc'])) ?>
                · Lesson <?= (int)$nextRow['external_lesson_id'] ?>
                · <?= dash_h((string)$nextRow['title']) ?>
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