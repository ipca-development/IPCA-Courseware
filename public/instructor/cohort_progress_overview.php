<?php
declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);



require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/courseware_progression_v2.php';

cw_require_login();

$currentUser = cw_current_user($pdo);
$currentRole = trim((string)($currentUser['role'] ?? ''));

$allowedRoles = array('admin', 'supervisor', 'instructor', 'chief_instructor');
if (!in_array($currentRole, $allowedRoles, true)) {
    http_response_code(403);
    exit('Forbidden');
}

$engine = new CoursewareProgressionV2($pdo);

function cpo_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function cpo_svg_users(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true">'
        . '<path d="M12 12c2.761 0 5-2.462 5-5.5S14.761 1 12 1 7 3.462 7 6.5 9.239 12 12 12Z" fill="currentColor" opacity=".88"/>'
        . '<path d="M3.5 22c.364-4.157 4.006-7 8.5-7s8.136 2.843 8.5 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
        . '</svg>';
}

function cpo_avatar_html(array $user, string $displayName): string
{
    $photoPath = trim((string)($user['photo_path'] ?? ''));
    if ($photoPath !== '') {
        return '<div class="ip-avatar">'
            . '<img src="' . cpo_h($photoPath) . '" alt="' . cpo_h($displayName) . '">'
            . '</div>';
    }

    return '<div class="ip-avatar"><span class="ip-avatar-fallback">' . cpo_svg_users() . '</span></div>';
}

function cpo_format_dt(?string $dt): string
{
    $dt = trim((string)$dt);
    if ($dt === '') {
        return '—';
    }

    $ts = strtotime($dt);
    if ($ts === false) {
        return $dt;
    }

    return gmdate('M j, Y · H:i', $ts) . ' UTC';
}

function cpo_parse_json_assoc(?string $json): array
{
    $json = trim((string)$json);
    if ($json === '') {
        return array();
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : array();
}

function cpo_percent_clamped(float $value): int
{
    if ($value < 0) {
        $value = 0;
    }
    if ($value > 100) {
        $value = 100;
    }
    return (int)round($value);
}

/**
 * Fact-derived motivation score.
 * This is intentionally based on measurable behavior only.
 */
function cpo_calculate_motivation(array $row): array
{
    $score = 50.0;
    $reasons = array();

    $avgScore = (float)($row['avg_score'] ?? 0.0);
    $bestScore = (float)($row['best_score_calc'] ?? 0.0);
    $latestScore = (float)($row['latest_score'] ?? 0.0);
    $attemptCount = (int)($row['attempt_count_calc'] ?? 0);
    $passCount = (int)($row['pass_count_calc'] ?? 0);
    $lateCount = (int)($row['late_attempt_count'] ?? 0);
    $summaryAcceptable = (int)($row['summary_acceptable_count'] ?? 0);
    $summaryNeedsRevision = (int)($row['summary_needs_revision_count'] ?? 0);
    $deadlineMissed = (int)($row['deadline_missed_count'] ?? 0);
    $pendingActions = (int)($row['pending_actions_count'] ?? 0);
    $recentActivityTs = trim((string)($row['latest_activity_at'] ?? ''));
    $firstAttemptScore = (float)($row['first_attempt_score'] ?? 0.0);

    if ($attemptCount > 0) {
        if ($avgScore >= 85) {
            $score += 14;
            $reasons[] = 'Strong average progress test performance.';
        } elseif ($avgScore >= 75) {
            $score += 8;
            $reasons[] = 'Generally solid assessment performance.';
        } elseif ($avgScore < 60) {
            $score -= 10;
            $reasons[] = 'Low average assessment performance is limiting momentum.';
        }

        if ($latestScore > 0 && $firstAttemptScore > 0) {
            $delta = $latestScore - $firstAttemptScore;
            if ($delta >= 12) {
                $score += 12;
                $reasons[] = 'Clear improvement trend across attempts.';
            } elseif ($delta >= 5) {
                $score += 6;
                $reasons[] = 'Moderate score improvement across attempts.';
            } elseif ($delta <= -8) {
                $score -= 10;
                $reasons[] = 'Recent attempts indicate declining performance.';
            }
        }

        if ($attemptCount >= 3 && $passCount === 0) {
            $score -= 12;
            $reasons[] = 'Multiple attempts without a pass suggest reduced effective engagement.';
        } elseif ($attemptCount >= 2 && $passCount > 0) {
            $score += 5;
            $reasons[] = 'Persistence resulted in positive progression.';
        }
    }

    if ($summaryAcceptable > 0) {
        $score += 8;
        $reasons[] = 'Acceptable lesson summary quality supports engagement.';
    }

    if ($summaryNeedsRevision > 0) {
        $score -= 8;
        $reasons[] = 'Summary revisions indicate incomplete conceptual consolidation.';
    }

    if ($lateCount > 0) {
        $score -= min(12, $lateCount * 4);
        $reasons[] = 'Late progress test behavior reduced the motivation score.';
    }

    if ($deadlineMissed > 0) {
        $score -= min(18, $deadlineMissed * 6);
        $reasons[] = 'Missed deadlines are a strong factual warning sign.';
    }

    if ($pendingActions > 0) {
        $score -= min(10, $pendingActions * 3);
        $reasons[] = 'Open required actions indicate stalled progression.';
    }

    if ($recentActivityTs !== '') {
        $ts = strtotime($recentActivityTs);
        if ($ts !== false) {
            $days = (time() - $ts) / 86400;
            if ($days <= 3) {
                $score += 5;
                $reasons[] = 'Recent learning activity supports continued momentum.';
            } elseif ($days >= 14) {
                $score -= 8;
                $reasons[] = 'No recent activity reduces the momentum indicator.';
            }
        }
    }

    $scoreInt = cpo_percent_clamped($score);

    $label = 'Stable';
    if ($scoreInt >= 78) {
        $label = 'High';
    } elseif ($scoreInt >= 60) {
        $label = 'Good';
    } elseif ($scoreInt >= 40) {
        $label = 'Fragile';
    } else {
        $label = 'Low';
    }

    if (!$reasons) {
        $reasons[] = 'Limited factual data available; using neutral baseline.';
    }

    return array(
        'score' => $scoreInt,
        'label' => $label,
        'reasons' => array_slice(array_values(array_unique($reasons)), 0, 3),
    );
}

function cpo_progress_percent(array $row): int
{
    $attemptCount = (int)($row['attempt_count_calc'] ?? 0);
    $passCount = (int)($row['pass_count_calc'] ?? 0);
    $bestScore = (float)($row['best_score_calc'] ?? 0.0);
    $summaryAcceptable = (int)($row['summary_acceptable_count'] ?? 0);
    $pendingInstructor = (int)($row['pending_instructor_actions_count'] ?? 0);
    $deadlineMissed = (int)($row['deadline_missed_count'] ?? 0);

    $value = 0.0;

    if ($attemptCount > 0) {
        $value += min(35, $bestScore * 0.35);
    }

    if ($passCount > 0) {
        $value += 30;
    }

    if ($summaryAcceptable > 0) {
        $value += 15;
    }

    if ($pendingInstructor > 0) {
        $value -= 12;
    }

    if ($deadlineMissed > 0) {
        $value -= min(15, $deadlineMissed * 5);
    }

    return cpo_percent_clamped($value);
}

function cpo_student_rank_score(array $row, array $motivation): float
{
    $progress = cpo_progress_percent($row);
    $avgScore = (float)($row['avg_score'] ?? 0.0);
    $bestScore = (float)($row['best_score_calc'] ?? 0.0);
    $deadlineMissed = (int)($row['deadline_missed_count'] ?? 0);
    $pendingInstructor = (int)($row['pending_instructor_actions_count'] ?? 0);
    $trainingSuspended = (int)($row['training_suspended_count'] ?? 0);

    $rank = 0.0;
    $rank += $progress * 0.42;
    $rank += $avgScore * 0.24;
    $rank += $bestScore * 0.12;
    $rank += ((float)$motivation['score']) * 0.22;

    if ($deadlineMissed > 0) {
        $rank -= min(12, $deadlineMissed * 4);
    }
    if ($pendingInstructor > 0) {
        $rank -= 8;
    }
    if ($trainingSuspended > 0) {
        $rank -= 20;
    }

    return round($rank, 2);
}

function cpo_urgency(array $row): array
{
    $trainingSuspended = (int)($row['training_suspended_count'] ?? 0);
    $deadlineMissed = (int)($row['deadline_missed_count'] ?? 0);
    $pendingInstructor = (int)($row['pending_instructor_actions_count'] ?? 0);
    $pendingRemediation = (int)($row['pending_remediation_actions_count'] ?? 0);
    $pendingReason = (int)($row['pending_reason_actions_count'] ?? 0);
    $summaryNeedsRevision = (int)($row['summary_needs_revision_count'] ?? 0);
    $lateAttempts = (int)($row['late_attempt_count'] ?? 0);
    $passCount = (int)($row['pass_count_calc'] ?? 0);
    $attemptCount = (int)($row['attempt_count_calc'] ?? 0);

    if ($trainingSuspended > 0 || $deadlineMissed > 0 || $pendingInstructor > 0) {
        return array(
            'key' => 'urgent',
            'label' => 'Urgent Attention',
            'class' => 'urgent',
        );
    }

    if ($pendingRemediation > 0 || $pendingReason > 0 || $summaryNeedsRevision > 0) {
        return array(
            'key' => 'attention',
            'label' => 'Needs Attention',
            'class' => 'attention',
        );
    }

    if (($lateAttempts > 0 && $passCount === 0) || ($attemptCount >= 2 && $passCount === 0)) {
        return array(
            'key' => 'warning',
            'label' => 'Warning',
            'class' => 'warning',
        );
    }

    return array(
        'key' => 'ok',
        'label' => 'On Track',
        'class' => 'ok',
    );
}

function cpo_status_pills(array $row): array
{
    $pills = array();

    if ((int)($row['pending_instructor_actions_count'] ?? 0) > 0) {
        $pills[] = array('label' => 'Instructor Required', 'class' => 'danger');
    }
    if ((int)($row['pending_remediation_actions_count'] ?? 0) > 0) {
        $pills[] = array('label' => 'Remediation Required', 'class' => 'warning');
    }
    if ((int)($row['pending_reason_actions_count'] ?? 0) > 0) {
        $pills[] = array('label' => 'Reason Required', 'class' => 'warning');
    }
    if ((int)($row['deadline_missed_count'] ?? 0) > 0) {
        $pills[] = array('label' => 'Deadline Missed', 'class' => 'danger');
    }
    if ((int)($row['summary_needs_revision_count'] ?? 0) > 0) {
        $pills[] = array('label' => 'Summary Needs Revision', 'class' => 'info');
    }
    if ((int)($row['training_suspended_count'] ?? 0) > 0) {
        $pills[] = array('label' => 'Training Suspended', 'class' => 'danger');
    }
    if ((int)($row['one_on_one_required_count'] ?? 0) > 0 && (int)($row['one_on_one_completed_count'] ?? 0) === 0) {
        $pills[] = array('label' => 'One-on-One Required', 'class' => 'warning');
    }

    if (!$pills) {
        $pills[] = array('label' => 'Progressing', 'class' => 'ok');
    }

    return $pills;
}

$cohortId = (int)($_GET['cohort_id'] ?? 0);

/**
 * Cohorts visible to instructor/supervisor/admin.
 * Assumes enrollments table exists in the project.
 */
$cohortSql = "
    SELECT
        c.id,
        c.name,
        COALESCE(cr.title, CONCAT('Course #', cc.course_id)) AS course_title,
        COUNT(DISTINCT CASE
            WHEN u.role = 'student' AND u.status = 'active' THEN cs.user_id
            ELSE NULL
        END) AS student_count
    FROM cohorts c
    LEFT JOIN cohort_courses cc
        ON cc.cohort_id = c.id
    LEFT JOIN courses cr
        ON cr.id = cc.course_id
    LEFT JOIN cohort_students cs
        ON cs.cohort_id = c.id
    LEFT JOIN users u
        ON u.id = cs.user_id
    GROUP BY c.id, c.name, cr.title, cc.course_id
    ORDER BY c.name ASC, c.id ASC
";

$cohortStmt = $pdo->query($cohortSql);
$cohorts = $cohortStmt ? $cohortStmt->fetchAll(PDO::FETCH_ASSOC) : array();

if ($cohortId <= 0 && !empty($cohorts)) {
    $cohortId = (int)($cohorts[0]['id'] ?? 0);
}

$selectedCohort = null;
foreach ($cohorts as $cohortRow) {
    if ((int)($cohortRow['id'] ?? 0) === $cohortId) {
        $selectedCohort = $cohortRow;
        break;
    }
}

$studentRows = array();

if ($cohortId > 0) {
    $studentSql = "
    SELECT
        u.id AS user_id,
        u.name AS student_name,
        u.first_name,
        u.last_name,
        u.email,
        u.photo_path,

        c.id AS cohort_id,
        c.name AS cohort_name,
        COALESCE(cr.title, CONCAT('Course #', cc.course_id)) AS course_title,

        MAX(pt.completed_at) AS latest_attempt_at,
        MIN(CASE WHEN pt.score_pct IS NOT NULL THEN pt.score_pct END) AS first_attempt_score,
        MAX(CASE WHEN pt.score_pct IS NOT NULL THEN pt.score_pct END) AS best_score_calc,
        AVG(CASE WHEN pt.score_pct IS NOT NULL THEN pt.score_pct END) AS avg_score,
        MAX(CASE WHEN pt.id = (
            SELECT pt2.id
            FROM progress_tests_v2 pt2
            WHERE pt2.user_id = u.id
              AND pt2.cohort_id = c.id
            ORDER BY COALESCE(pt2.completed_at, pt2.created_at) DESC, pt2.id DESC
            LIMIT 1
        ) THEN pt.score_pct END) AS latest_score,

        COUNT(DISTINCT pt.id) AS attempt_count_calc,
        SUM(CASE WHEN pt.pass_gate_met = 1 THEN 1 ELSE 0 END) AS pass_count_calc,
        SUM(CASE WHEN pt.timing_status = 'late' OR pt.timing_status = 'after_final_deadline' THEN 1 ELSE 0 END) AS late_attempt_count,

        SUM(CASE WHEN ls.review_status = 'acceptable' THEN 1 ELSE 0 END) AS summary_acceptable_count,
        SUM(CASE WHEN ls.review_status = 'needs_revision' THEN 1 ELSE 0 END) AS summary_needs_revision_count,

        SUM(CASE WHEN la.completion_status = 'deadline_blocked' OR la.test_pass_status = 'deadline_missed' THEN 1 ELSE 0 END) AS deadline_missed_count,
        SUM(CASE WHEN la.training_suspended = 1 THEN 1 ELSE 0 END) AS training_suspended_count,
        SUM(CASE WHEN la.one_on_one_required = 1 THEN 1 ELSE 0 END) AS one_on_one_required_count,
        SUM(CASE WHEN la.one_on_one_completed = 1 THEN 1 ELSE 0 END) AS one_on_one_completed_count,

        COUNT(DISTINCT CASE WHEN sra.status IN ('pending','opened') THEN sra.id END) AS pending_actions_count,
        COUNT(DISTINCT CASE WHEN sra.action_type = 'instructor_approval' AND sra.status IN ('pending','opened') THEN sra.id END) AS pending_instructor_actions_count,
        COUNT(DISTINCT CASE WHEN sra.action_type = 'remediation_acknowledgement' AND sra.status IN ('pending','opened') THEN sra.id END) AS pending_remediation_actions_count,
        COUNT(DISTINCT CASE WHEN sra.action_type = 'deadline_reason_submission' AND sra.status IN ('pending','opened') THEN sra.id END) AS pending_reason_actions_count,

        MAX(COALESCE(la.last_state_eval_at, pt.completed_at, ls.updated_at, sra.updated_at, u.updated_at)) AS latest_activity_at,

        (
            SELECT sra2.id
            FROM student_required_actions sra2
            WHERE sra2.user_id = u.id
              AND sra2.cohort_id = c.id
              AND sra2.action_type = 'instructor_approval'
              AND sra2.status IN ('pending','opened','approved')
            ORDER BY sra2.id DESC
            LIMIT 1
        ) AS latest_instructor_action_id,

        (
            SELECT sra3.token
            FROM student_required_actions sra3
            WHERE sra3.user_id = u.id
              AND sra3.cohort_id = c.id
              AND sra3.action_type = 'instructor_approval'
              AND sra3.status IN ('pending','opened','approved')
            ORDER BY sra3.id DESC
            LIMIT 1
        ) AS latest_instructor_action_token,

        (
            SELECT l.title
            FROM student_required_actions sra4
            INNER JOIN lessons l
                ON l.id = sra4.lesson_id
            WHERE sra4.user_id = u.id
              AND sra4.cohort_id = c.id
              AND sra4.status IN ('pending','opened')
            ORDER BY sra4.id DESC
            LIMIT 1
        ) AS attention_lesson_title,

        (
            SELECT ls2.review_status
            FROM lesson_summaries ls2
            WHERE ls2.user_id = u.id
              AND ls2.cohort_id = c.id
            ORDER BY ls2.updated_at DESC, ls2.id DESC
            LIMIT 1
        ) AS latest_summary_status,

        (
            SELECT pt3.formal_result_label
            FROM progress_tests_v2 pt3
            WHERE pt3.user_id = u.id
              AND pt3.cohort_id = c.id
            ORDER BY COALESCE(pt3.completed_at, pt3.created_at) DESC, pt3.id DESC
            LIMIT 1
        ) AS latest_result_label,

        (
            SELECT pt4.formal_result_code
            FROM progress_tests_v2 pt4
            WHERE pt4.user_id = u.id
              AND pt4.cohort_id = c.id
            ORDER BY COALESCE(pt4.completed_at, pt4.created_at) DESC, pt4.id DESC
            LIMIT 1
        ) AS latest_result_code

    FROM cohort_students cs
    INNER JOIN users u
        ON u.id = cs.user_id
       AND u.role = 'student'
       AND u.status = 'active'
    INNER JOIN cohorts c
        ON c.id = cs.cohort_id
    LEFT JOIN cohort_courses cc
        ON cc.cohort_id = c.id
    LEFT JOIN courses cr
        ON cr.id = cc.course_id
    LEFT JOIN progress_tests_v2 pt
        ON pt.user_id = u.id
       AND pt.cohort_id = c.id
    LEFT JOIN lesson_summaries ls
        ON ls.user_id = u.id
       AND ls.cohort_id = c.id
    LEFT JOIN lesson_activity la
        ON la.user_id = u.id
       AND la.cohort_id = c.id
    LEFT JOIN student_required_actions sra
        ON sra.user_id = u.id
       AND sra.cohort_id = c.id
    WHERE cs.cohort_id = :cohort_id
    GROUP BY
        u.id,
        u.name,
        u.first_name,
        u.last_name,
        u.email,
        u.photo_path,
        c.id,
        c.name,
        cr.title,
        cc.course_id
    ORDER BY u.name ASC, u.id ASC
";

    $studentStmt = $pdo->prepare($studentSql);
    $studentStmt->execute(array(
        ':cohort_id' => $cohortId,
    ));
    $studentRows = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
}

$scope = array('cohort_id' => $cohortId);
$chiefInstructor = null;

if ($cohortId > 0) {
    try {
        $chiefRecipient = $engine->getChiefInstructorRecipient($scope);
        if ($chiefRecipient && !empty($chiefRecipient['user_id'])) {
            $chiefStmt = $pdo->prepare("
                SELECT id, name, first_name, last_name, email, photo_path, role
                FROM users
                WHERE id = ?
                LIMIT 1
            ");
            $chiefStmt->execute(array((int)$chiefRecipient['user_id']));
            $chiefInstructor = $chiefStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (Throwable $e) {
        $chiefInstructor = null;
    }
}

$cards = array();
$summaryMetrics = array(
    'students' => 0,
    'urgent' => 0,
    'attention' => 0,
    'warning' => 0,
    'on_track' => 0,
    'deadline_missed' => 0,
    'avg_score' => 0.0,
);

foreach ($studentRows as $row) {
    $motivation = cpo_calculate_motivation($row);
    $progressPercent = cpo_progress_percent($row);
    $rankScore = cpo_student_rank_score($row, $motivation);
    $urgency = cpo_urgency($row);
    $pills = cpo_status_pills($row);

    $studentName = trim((string)($row['student_name'] ?? ''));
    if ($studentName === '') {
        $studentName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
    }
    if ($studentName === '') {
        $studentName = 'Student #' . (int)($row['user_id'] ?? 0);
    }

    $actionToken = trim((string)($row['latest_instructor_action_token'] ?? ''));
    $openUrl = $actionToken !== ''
        ? '/instructor/instructor_approval.php?token=' . rawurlencode($actionToken)
        : '';

    $cards[] = array(
        'row' => $row,
        'student_name' => $studentName,
        'motivation' => $motivation,
        'progress_percent' => $progressPercent,
        'rank_score' => $rankScore,
        'urgency' => $urgency,
        'pills' => $pills,
        'open_url' => $openUrl,
    );

    $summaryMetrics['students']++;
    $summaryMetrics['avg_score'] += (float)($row['avg_score'] ?? 0.0);

    if ($urgency['key'] === 'urgent') {
        $summaryMetrics['urgent']++;
    } elseif ($urgency['key'] === 'attention') {
        $summaryMetrics['attention']++;
    } elseif ($urgency['key'] === 'warning') {
        $summaryMetrics['warning']++;
    } else {
        $summaryMetrics['on_track']++;
    }

    if ((int)($row['deadline_missed_count'] ?? 0) > 0) {
        $summaryMetrics['deadline_missed']++;
    }
}

if ($summaryMetrics['students'] > 0) {
    $summaryMetrics['avg_score'] = round($summaryMetrics['avg_score'] / $summaryMetrics['students'], 1);
}

usort($cards, function (array $a, array $b): int {
    $rankCmp = $b['rank_score'] <=> $a['rank_score'];
    if ($rankCmp !== 0) {
        return $rankCmp;
    }

    $nameA = strtolower((string)$a['student_name']);
    $nameB = strtolower((string)$b['student_name']);
    return $nameA <=> $nameB;
});

$topCards = array_slice($cards, 0, 5);

cw_header('Cohort Progress Overview');
?>

<style>
.cpo-page{display:flex;flex-direction:column;gap:18px}
.cpo-hero{padding:22px 24px}
.cpo-eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.14em;color:#64748b;font-weight:800;margin-bottom:8px}
.cpo-title{margin:0;font-size:32px;line-height:1.05;letter-spacing:-.04em;color:#102845}
.cpo-sub{margin-top:10px;font-size:14px;line-height:1.6;color:#56677f;max-width:1100px}
.cpo-toolbar{display:grid;grid-template-columns:1.2fr auto;gap:14px;align-items:end}
.cpo-field{display:flex;flex-direction:column;gap:7px}
.cpo-label{font-size:13px;font-weight:800;color:#102845}
.cpo-select,.cpo-input{
    width:100%;box-sizing:border-box;border:1px solid rgba(15,23,42,.12);
    border-radius:12px;padding:11px 12px;background:#fff;color:#102845;font:inherit;
}
.cpo-btn{
    display:inline-flex;align-items:center;justify-content:center;
    min-height:42px;padding:0 16px;border-radius:12px;border:1px solid #12355f;
    background:#12355f;color:#fff;font-size:13px;font-weight:800;text-decoration:none;cursor:pointer;
}
.cpo-btn.secondary{background:#fff;color:#12355f}
.cpo-metric-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:14px}
.cpo-metric-card{padding:18px 18px}
.cpo-metric-kicker{font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:#64748b;font-weight:800}
.cpo-metric-value{margin-top:8px;font-size:30px;line-height:1;font-weight:820;color:#102845;letter-spacing:-.04em}
.cpo-metric-sub{margin-top:8px;font-size:12px;line-height:1.5;color:#64748b}
.cpo-top-strip{padding:18px 20px}
.cpo-top-strip-title{margin:0 0 12px 0;font-size:18px;font-weight:800;color:#102845}
.cpo-top-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px}
.cpo-top-card{padding:16px;border:1px solid rgba(15,23,42,.07);border-radius:18px;background:#fff}
.cpo-top-rank{font-size:11px;font-weight:800;letter-spacing:.11em;text-transform:uppercase;color:#64748b}
.cpo-top-name{margin-top:10px;font-size:16px;font-weight:800;color:#102845}
.cpo-top-meta{margin-top:6px;font-size:12px;line-height:1.5;color:#64748b}
.cpo-chip-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.cpo-chip{
    display:inline-flex;align-items:center;justify-content:center;
    min-height:28px;padding:0 10px;border-radius:999px;font-size:11px;font-weight:800;
    border:1px solid rgba(15,23,42,.08);background:#f8fafc;color:#334155;
}
.cpo-chip.ok{background:#ecfdf5;color:#166534;border-color:#bbf7d0}
.cpo-chip.warning{background:#fff7ed;color:#c2410c;border-color:#fdba74}
.cpo-chip.attention{background:#fef3c7;color:#92400e;border-color:#fcd34d}
.cpo-chip.urgent{background:#fee2e2;color:#991b1b;border-color:#fca5a5}
.cpo-student-grid{display:grid;grid-template-columns:1fr;gap:16px}
.cpo-student-card{padding:20px 22px}
.cpo-student-top{display:grid;grid-template-columns:1.2fr 1fr auto;gap:18px;align-items:start}
.cpo-person{
    display:flex;gap:14px;align-items:center;min-width:0;
}
.ip-avatar{width:84px;height:84px;border-radius:24px;overflow:hidden;flex:0 0 84px;background:linear-gradient(180deg,#e8eef7 0%,#dfe7f2 100%);border:1px solid rgba(15,23,42,0.07);display:flex;align-items:center;justify-content:center;box-shadow:inset 0 1px 0 rgba(255,255,255,0.45)}
.ip-avatar img{width:100%;height:100%;object-fit:cover;display:block}
.ip-avatar-fallback{width:34px;height:34px;color:#7b8aa0}
.ip-avatar-fallback svg{width:100%;height:100%;display:block}
.cpo-person-copy{min-width:0}
.cpo-person-role{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#64748b}
.cpo-person-name{margin-top:4px;font-size:22px;line-height:1.05;letter-spacing:-.03em;font-weight:820;color:#102845}
.cpo-person-sub{margin-top:8px;font-size:13px;line-height:1.55;color:#64748b}
.cpo-chief-wrap{display:flex;justify-content:flex-end}
.cpo-chief-card{
    display:flex;gap:12px;align-items:center;padding:12px 14px;border-radius:18px;
    background:linear-gradient(180deg,#f8fbff 0%,#f3f7fd 100%);border:1px solid rgba(18,53,95,.08);
}
.cpo-chief-card .ip-avatar{width:56px;height:56px;border-radius:18px;flex:0 0 56px}
.cpo-chief-copy{min-width:0}
.cpo-chief-label{font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#64748b}
.cpo-chief-name{margin-top:4px;font-size:15px;font-weight:800;color:#102845}
.cpo-chief-sub{margin-top:3px;font-size:12px;color:#64748b}
.cpo-side-actions{display:flex;flex-direction:column;gap:10px;align-items:flex-end}
.cpo-urgency-pill{
    display:inline-flex;align-items:center;justify-content:center;
    min-height:34px;padding:0 12px;border-radius:999px;font-size:12px;font-weight:900;white-space:nowrap;
}
.cpo-urgency-pill.ok{background:#ecfdf5;color:#166534}
.cpo-urgency-pill.warning{background:#fff7ed;color:#c2410c}
.cpo-urgency-pill.attention{background:#fef3c7;color:#92400e}
.cpo-urgency-pill.urgent{background:#fee2e2;color:#991b1b}
.cpo-rank-badge{
    display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:0 12px;
    border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:900;border:1px solid #bfdbfe;
}
.cpo-content-grid{display:grid;grid-template-columns:1.15fr 1fr;gap:18px;margin-top:18px}
.cpo-panel{
    padding:18px;border-radius:20px;border:1px solid rgba(15,23,42,.07);background:#fff;
}
.cpo-panel-title{margin:0 0 12px 0;font-size:16px;font-weight:820;color:#102845}
.cpo-kv{display:grid;grid-template-columns:1fr auto;gap:10px;padding:10px 0;border-bottom:1px solid rgba(15,23,42,.06)}
.cpo-kv:last-child{border-bottom:0}
.cpo-kv-label{font-size:13px;font-weight:700;color:#334155}
.cpo-kv-value{font-size:13px;font-weight:800;color:#102845;text-align:right}
.cpo-bars{display:flex;flex-direction:column;gap:16px}
.cpo-progress-row{display:flex;flex-direction:column;gap:8px}
.cpo-progress-head{display:flex;justify-content:space-between;gap:10px;align-items:center}
.cpo-progress-label{font-size:13px;font-weight:800;color:#102845}
.cpo-progress-value{font-size:12px;font-weight:800;color:#64748b}
.cpo-progress-track{
    width:100%;height:12px;border-radius:999px;overflow:hidden;background:#e7edf5;position:relative;
}
.cpo-progress-fill{
    height:100%;border-radius:999px;
    background:linear-gradient(90deg,#12355f 0%,#2b6cb0 55%,#60a5fa 100%);
}
.cpo-mini-note{font-size:12px;line-height:1.55;color:#64748b}
.cpo-mini-list{display:grid;gap:8px;margin-top:10px}
.cpo-mini-list-item{
    display:flex;gap:10px;align-items:flex-start;font-size:12px;line-height:1.5;color:#475569;
}
.cpo-mini-dot{
    width:8px;height:8px;border-radius:999px;background:#3b82f6;flex:0 0 8px;margin-top:5px;
}
.cpo-state-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.cpo-state-pill{
    display:inline-flex;align-items:center;justify-content:center;
    min-height:30px;padding:0 10px;border-radius:999px;font-size:11px;font-weight:800;
    border:1px solid rgba(15,23,42,.08);
}
.cpo-state-pill.ok{background:#ecfdf5;color:#166534;border-color:#bbf7d0}
.cpo-state-pill.warning{background:#fff7ed;color:#c2410c;border-color:#fdba74}
.cpo-state-pill.danger{background:#fee2e2;color:#991b1b;border-color:#fca5a5}
.cpo-state-pill.info{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.cpo-empty{padding:28px 24px;text-align:center;font-size:14px;color:#64748b}
@media (max-width: 1320px){
    .cpo-metric-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
    .cpo-top-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media (max-width: 1180px){
    .cpo-student-top{grid-template-columns:1fr}
    .cpo-chief-wrap{justify-content:flex-start}
    .cpo-side-actions{align-items:flex-start;flex-direction:row;flex-wrap:wrap}
    .cpo-content-grid{grid-template-columns:1fr}
}
@media (max-width: 860px){
    .cpo-toolbar{grid-template-columns:1fr}
    .cpo-metric-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .cpo-top-grid{grid-template-columns:1fr}
}
</style>

<div class="cpo-page">

    <section class="card cpo-hero">
        <div class="cpo-eyebrow">Instructor Platform · Theory Progress</div>
        <h1 class="cpo-title">Cohort Progress Overview</h1>
        <div class="cpo-sub">
            Ranked operational overview of theory progression across the selected cohort. Students are compared using factual progression status, assessment performance, deadline compliance, and a fact-derived motivation score. Urgent cases are clearly flagged so instructors do not need to search for issues.
        </div>
    </section>

    <section class="card" style="padding:18px 20px;">
        <form method="get" class="cpo-toolbar">
            <div class="cpo-field">
                <label class="cpo-label">Select Cohort</label>
                <select class="cpo-select" name="cohort_id">
                    <?php foreach ($cohorts as $cohortRow): ?>
                        <option value="<?php echo (int)($cohortRow['id'] ?? 0); ?>" <?php echo (int)($cohortRow['id'] ?? 0) === $cohortId ? 'selected' : ''; ?>>
                            <?php
                            echo cpo_h(
                                (string)($cohortRow['name'] ?? ('Cohort #' . (int)($cohortRow['id'] ?? 0)))
                                . ' · '
                                . (string)($cohortRow['course_title'] ?? '')
                                . ' · '
                                . (int)($cohortRow['student_count'] ?? 0)
                                . ' student(s)'
                            );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <button class="cpo-btn" type="submit">Load Cohort</button>
            </div>
        </form>
    </section>

    <?php if (!$selectedCohort): ?>
        <section class="card">
            <div class="cpo-empty">No cohort available.</div>
        </section>
    <?php else: ?>

        <section class="cpo-metric-grid">
            <div class="card cpo-metric-card">
                <div class="cpo-metric-kicker">Cohort</div>
                <div class="cpo-metric-value"><?php echo cpo_h((string)$selectedCohort['name']); ?></div>
                <div class="cpo-metric-sub"><?php echo cpo_h((string)($selectedCohort['course_title'] ?? '')); ?></div>
            </div>

            <div class="card cpo-metric-card">
                <div class="cpo-metric-kicker">Students</div>
                <div class="cpo-metric-value"><?php echo (int)$summaryMetrics['students']; ?></div>
                <div class="cpo-metric-sub">Active students in this cohort overview.</div>
            </div>

            <div class="card cpo-metric-card">
                <div class="cpo-metric-kicker">Average Score</div>
                <div class="cpo-metric-value"><?php echo cpo_h((string)$summaryMetrics['avg_score']); ?><span style="font-size:18px;">%</span></div>
                <div class="cpo-metric-sub">Average across recorded progress tests in this cohort.</div>
            </div>

            <div class="card cpo-metric-card">
                <div class="cpo-metric-kicker">Urgent</div>
                <div class="cpo-metric-value" style="color:#991b1b;"><?php echo (int)$summaryMetrics['urgent']; ?></div>
                <div class="cpo-metric-sub">Includes instructor-required, missed-deadline, or suspended cases.</div>
            </div>

            <div class="card cpo-metric-card">
                <div class="cpo-metric-kicker">Needs Attention</div>
                <div class="cpo-metric-value" style="color:#92400e;"><?php echo (int)$summaryMetrics['attention']; ?></div>
                <div class="cpo-metric-sub">Open remediation, reason submission, or summary revision cases.</div>
            </div>

            <div class="card cpo-metric-card">
                <div class="cpo-metric-kicker">Deadline Flags</div>
                <div class="cpo-metric-value" style="color:#c2410c;"><?php echo (int)$summaryMetrics['deadline_missed']; ?></div>
                <div class="cpo-metric-sub">Students with deadline-blocked or deadline-missed progression signals.</div>
            </div>
        </section>

        <section class="card cpo-top-strip">
            <h2 class="cpo-top-strip-title">Top Ranked Students at a Glance</h2>

            <?php if (!$topCards): ?>
                <div class="cpo-empty" style="padding:18px 0 6px 0;">No student progress data found for this cohort yet.</div>
            <?php else: ?>
                <div class="cpo-top-grid">
                    <?php foreach ($topCards as $index => $card): ?>
                        <?php $row = $card['row']; ?>
                        <div class="cpo-top-card">
                            <div class="cpo-top-rank">Rank #<?php echo (int)($index + 1); ?></div>
                            <div style="margin-top:12px;">
                                <?php echo cpo_avatar_html($row, $card['student_name']); ?>
                            </div>
                            <div class="cpo-top-name"><?php echo cpo_h($card['student_name']); ?></div>
                            <div class="cpo-top-meta">
                                Avg Score: <strong><?php echo cpo_h((string)round((float)($row['avg_score'] ?? 0.0), 1)); ?>%</strong><br>
                                Motivation: <strong><?php echo cpo_h($card['motivation']['label']); ?></strong><br>
                                Progress: <strong><?php echo (int)$card['progress_percent']; ?>%</strong>
                            </div>
                            <div class="cpo-chip-row">
                                <span class="cpo-chip <?php echo cpo_h($card['urgency']['class']); ?>">
                                    <?php echo cpo_h($card['urgency']['label']); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="cpo-student-grid">
            <?php if (!$cards): ?>
                <section class="card">
                    <div class="cpo-empty">No active students were found for this cohort.</div>
                </section>
            <?php else: ?>
                <?php foreach ($cards as $index => $card): ?>
                    <?php
                    $row = $card['row'];
                    $studentName = $card['student_name'];
                    $chiefName = $chiefInstructor ? trim((string)($chiefInstructor['name'] ?? '')) : 'Chief Instructor';
                    $chiefSubtitle = $chiefInstructor ? trim((string)($chiefInstructor['email'] ?? '')) : 'Configured from policy';
                    $attentionLessonTitle = trim((string)($row['attention_lesson_title'] ?? ''));
                    $latestSummaryStatus = trim((string)($row['latest_summary_status'] ?? ''));
                    $latestResultLabel = trim((string)($row['latest_result_label'] ?? ''));
                    $latestResultCode = trim((string)($row['latest_result_code'] ?? ''));
                    $avgScoreDisplay = round((float)($row['avg_score'] ?? 0.0), 1);
                    $bestScoreDisplay = round((float)($row['best_score_calc'] ?? 0.0), 1);
                    $latestScoreDisplay = round((float)($row['latest_score'] ?? 0.0), 1);
                    ?>
                    <section class="card cpo-student-card">
                        <div class="cpo-student-top">
                            <div class="cpo-person">
                                <?php echo cpo_avatar_html($row, $studentName); ?>
                                <div class="cpo-person-copy">
                                    <div class="cpo-person-role">Student</div>
                                    <div class="cpo-person-name"><?php echo cpo_h($studentName); ?></div>
                                    <div class="cpo-person-sub">
                                        Rank Score: <strong><?php echo cpo_h((string)$card['rank_score']); ?></strong><br>
                                        <?php if ($attentionLessonTitle !== ''): ?>
                                            Current attention lesson: <strong><?php echo cpo_h($attentionLessonTitle); ?></strong>
                                        <?php else: ?>
                                            Current attention lesson: <strong>—</strong>
                                        <?php endif; ?>
                                    </div>
                                    <div class="cpo-state-row">
                                        <?php foreach ($card['pills'] as $pill): ?>
                                            <span class="cpo-state-pill <?php echo cpo_h((string)$pill['class']); ?>">
                                                <?php echo cpo_h((string)$pill['label']); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="cpo-chief-wrap">
                                <div class="cpo-chief-card">
                                    <?php
                                    echo $chiefInstructor
                                        ? cpo_avatar_html($chiefInstructor, $chiefName)
                                        : '<div class="ip-avatar"><span class="ip-avatar-fallback">' . cpo_svg_users() . '</span></div>';
                                    ?>
                                    <div class="cpo-chief-copy">
                                        <div class="cpo-chief-label">Chief Instructor</div>
                                        <div class="cpo-chief-name"><?php echo cpo_h($chiefName !== '' ? $chiefName : 'Chief Instructor'); ?></div>
                                        <div class="cpo-chief-sub"><?php echo cpo_h($chiefSubtitle !== '' ? $chiefSubtitle : 'Configured from policy'); ?></div>
                                    </div>
                                </div>
                            </div>

                            <div class="cpo-side-actions">
                                <span class="cpo-urgency-pill <?php echo cpo_h($card['urgency']['class']); ?>">
                                    <?php echo cpo_h($card['urgency']['label']); ?>
                                </span>
                                <span class="cpo-rank-badge">#<?php echo (int)($index + 1); ?></span>

                                <?php if ($card['open_url'] !== ''): ?>
                                    <a class="cpo-btn" href="<?php echo cpo_h($card['open_url']); ?>">Open Intervention</a>
                                <?php else: ?>
                                    <span class="cpo-btn secondary" style="opacity:.65;cursor:default;">No Active Instructor Link</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="cpo-content-grid">

                            <div class="cpo-panel">
                                <h3 class="cpo-panel-title">Progress Snapshot</h3>

                                <div class="cpo-kv">
                                    <div class="cpo-kv-label">Average Progress Test Score</div>
                                    <div class="cpo-kv-value"><?php echo cpo_h((string)$avgScoreDisplay); ?>%</div>
                                </div>
                                <div class="cpo-kv">
                                    <div class="cpo-kv-label">Best Score</div>
                                    <div class="cpo-kv-value"><?php echo cpo_h((string)$bestScoreDisplay); ?>%</div>
                                </div>
                                <div class="cpo-kv">
                                    <div class="cpo-kv-label">Latest Score</div>
                                    <div class="cpo-kv-value"><?php echo cpo_h((string)$latestScoreDisplay); ?>%</div>
                                </div>
                                <div class="cpo-kv">
                                    <div class="cpo-kv-label">Attempt Count</div>
                                    <div class="cpo-kv-value"><?php echo (int)($row['attempt_count_calc'] ?? 0); ?></div>
                                </div>
                                <div class="cpo-kv">
                                    <div class="cpo-kv-label">Latest Result</div>
                                    <div class="cpo-kv-value">
                                        <?php
                                        echo cpo_h(
                                            $latestResultLabel !== ''
                                                ? $latestResultLabel . ($latestResultCode !== '' ? ' (' . $latestResultCode . ')' : '')
                                                : '—'
                                        );
                                        ?>
                                    </div>
                                </div>
                                <div class="cpo-kv">
                                    <div class="cpo-kv-label">Latest Summary Status</div>
                                    <div class="cpo-kv-value"><?php echo cpo_h($latestSummaryStatus !== '' ? $latestSummaryStatus : '—'); ?></div>
                                </div>
                                <div class="cpo-kv">
                                    <div class="cpo-kv-label">Latest Activity</div>
                                    <div class="cpo-kv-value"><?php echo cpo_h(cpo_format_dt((string)($row['latest_activity_at'] ?? ''))); ?></div>
                                </div>
                            </div>

                            <div class="cpo-panel">
                                <h3 class="cpo-panel-title">At-a-Glance Indicators</h3>

                                <div class="cpo-bars">
                                    <div class="cpo-progress-row">
                                        <div class="cpo-progress-head">
                                            <div class="cpo-progress-label">Progress</div>
                                            <div class="cpo-progress-value"><?php echo (int)$card['progress_percent']; ?>%</div>
                                        </div>
                                        <div class="cpo-progress-track">
                                            <div class="cpo-progress-fill" style="width:<?php echo (int)$card['progress_percent']; ?>%;"></div>
                                        </div>
                                        <div class="cpo-mini-note">
                                            Derived from best score, pass status, summary quality, open instructor blocks, and deadline penalties.
                                        </div>
                                    </div>

                                    <div class="cpo-progress-row">
                                        <div class="cpo-progress-head">
                                            <div class="cpo-progress-label">Motivation</div>
                                            <div class="cpo-progress-value"><?php echo cpo_h($card['motivation']['label']); ?> · <?php echo (int)$card['motivation']['score']; ?>%</div>
                                        </div>
                                        <div class="cpo-progress-track">
                                            <div class="cpo-progress-fill" style="width:<?php echo (int)$card['motivation']['score']; ?>%;"></div>
                                        </div>

                                        <div class="cpo-mini-list">
                                            <?php foreach ($card['motivation']['reasons'] as $reason): ?>
                                                <div class="cpo-mini-list-item">
                                                    <span class="cpo-mini-dot"></span>
                                                    <span><?php echo cpo_h((string)$reason); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

    <?php endif; ?>

</div>

<?php cw_footer(); ?>