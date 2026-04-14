<?php
declare(strict_types=1);

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

function ptr_fetch_one(PDO $pdo, string $sql, array $params = array()): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

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

function cpo_avatar_html(array $user, string $displayName, string $size = '84'): string
{
    $photoPath = trim((string)($user['photo_path'] ?? ''));
    $sizePx = max(40, (int)$size);
    $radius = max(14, (int)round($sizePx * 0.29));
    $fallbackSize = max(20, (int)round($sizePx * 0.40));

    if ($photoPath !== '') {
        return '<div class="ip-avatar" style="width:' . $sizePx . 'px;height:' . $sizePx . 'px;border-radius:' . $radius . 'px;flex:0 0 ' . $sizePx . 'px;">'
            . '<img src="' . cpo_h($photoPath) . '" alt="' . cpo_h($displayName) . '">'
            . '</div>';
    }

    return '<div class="ip-avatar" style="width:' . $sizePx . 'px;height:' . $sizePx . 'px;border-radius:' . $radius . 'px;flex:0 0 ' . $sizePx . 'px;">'
        . '<span class="ip-avatar-fallback" style="width:' . $fallbackSize . 'px;height:' . $fallbackSize . 'px;">' . cpo_svg_users() . '</span>'
        . '</div>';
}

function cpo_format_datetime_utc(?string $dt): string
{
    $dt = trim((string)$dt);
    if ($dt === '') {
        return '—';
    }

    $ts = strtotime($dt);
    if ($ts === false) {
        return $dt;
    }

    return gmdate('D M j, Y', $ts) . ' · ' . gmdate('H:i', $ts) . ' UTC';
}

function cpo_format_date_label(?string $dt): string
{
    $dt = trim((string)$dt);
    if ($dt === '') {
        return '—';
    }

    $ts = strtotime($dt);
    if ($ts === false) {
        return $dt;
    }

    return gmdate('D M j, Y', $ts);
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

function cpo_cohort_status(array $cohort): array
{
    $startDate = trim((string)($cohort['start_date'] ?? ''));
    $endDate = trim((string)($cohort['end_date'] ?? ''));
    $today = gmdate('Y-m-d');

    if ($startDate !== '' && $startDate > $today) {
        return array(
            'label' => 'Future Start',
            'class' => 'info',
        );
    }

    if ($endDate !== '' && $endDate < $today) {
        return array(
            'label' => 'Retired',
            'class' => 'warning',
        );
    }

    return array(
        'label' => 'Ongoing',
        'class' => 'ok',
    );
}

function cpo_bar_class(?float $score): string
{
    if ($score === null) {
        return 'neutral';
    }

    if ($score >= 75) {
        return 'good';
    }
    if ($score >= 60) {
        return 'amber';
    }
    return 'danger';
}

function cpo_summary_pill_class(string $status): string
{
    return match ($status) {
        'acceptable' => 'ok',
        'needs_revision' => 'danger',
        'pending' => 'warning',
        'rejected' => 'danger',
        default => 'info',
    };
}

function cpo_summary_pill_label(string $status): string
{
    return match ($status) {
        'acceptable' => 'Acceptable',
        'needs_revision' => 'Needs Revision',
        'pending' => 'Review Pending',
        'rejected' => 'Rejected',
        default => '—',
    };
}

function cpo_result_pill_class(string $code): string
{
    return strtoupper($code) === 'PASS' ? 'ok' : 'danger';
}

function cpo_result_pill_label(string $label, string $code): string
{
    $label = trim($label);
    if ($label !== '') {
        return $label;
    }
    return strtoupper($code) === 'PASS' ? 'Satisfactory' : 'Unsatisfactory';
}

/**
 * Fact-derived motivation score only.
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
    $summaryScore = isset($row['latest_summary_score']) && $row['latest_summary_score'] !== null
        ? (float)$row['latest_summary_score']
        : null;
    $summaryStatus = trim((string)($row['latest_summary_status'] ?? ''));
    $deadlineMissed = (int)($row['deadline_missed_count'] ?? 0);
    $pendingActions = (int)($row['pending_actions_count'] ?? 0);
    $recentActivityTs = trim((string)($row['latest_activity_at'] ?? ''));
    $firstAttemptScore = isset($row['first_attempt_score']) && $row['first_attempt_score'] !== null
        ? (float)$row['first_attempt_score']
        : 0.0;

    if ($attemptCount > 0) {
        if ($avgScore >= 85) {
            $score += 14;
            $reasons[] = 'Strong average progress test performance.';
        } elseif ($avgScore >= 75) {
            $score += 8;
            $reasons[] = 'Solid overall theory assessment performance.';
        } elseif ($avgScore < 60) {
            $score -= 10;
            $reasons[] = 'Low average assessment performance is reducing momentum.';
        }

        if ($latestScore > 0 && $firstAttemptScore > 0) {
            $delta = $latestScore - $firstAttemptScore;
            if ($delta >= 12) {
                $score += 12;
                $reasons[] = 'Clear score improvement across attempts.';
            } elseif ($delta >= 5) {
                $score += 6;
                $reasons[] = 'Moderate improvement trend is visible.';
            } elseif ($delta <= -8) {
                $score -= 10;
                $reasons[] = 'Recent assessment results show decline.';
            }
        }

        if ($attemptCount >= 3 && $passCount === 0) {
            $score -= 12;
            $reasons[] = 'Multiple attempts without a pass suggest weakening momentum.';
        } elseif ($attemptCount >= 2 && $passCount > 0) {
            $score += 5;
            $reasons[] = 'Persistence converted into progression.';
        }
    }

    if ($summaryScore !== null) {
        if ($summaryScore >= 80) {
            $score += 8;
            $reasons[] = 'Strong summary quality supports understanding.';
        } elseif ($summaryScore < 60) {
            $score -= 8;
            $reasons[] = 'Weak summary quality suggests low consolidation.';
        }
    } else {
        if ($summaryStatus === 'acceptable') {
            $score += 4;
            $reasons[] = 'Accepted summary supports healthy engagement.';
        } elseif ($summaryStatus === 'needs_revision') {
            $score -= 8;
            $reasons[] = 'Summary revision requirement reduces stability.';
        }
    }

    if ($lateCount > 0) {
        $score -= min(12, $lateCount * 4);
        $reasons[] = 'Late progress test timing reduced the score.';
    }

    if ($deadlineMissed > 0) {
        $score -= min(18, $deadlineMissed * 6);
        $reasons[] = 'Missed deadlines are a strong factual warning sign.';
    }

    if ($pendingActions > 0) {
        $score -= min(10, $pendingActions * 3);
        $reasons[] = 'Open required actions indicate blocked momentum.';
    }

    if ($recentActivityTs !== '') {
        $ts = strtotime($recentActivityTs);
        if ($ts !== false) {
            $days = (time() - $ts) / 86400;
            if ($days <= 3) {
                $score += 5;
                $reasons[] = 'Recent activity supports continuity.';
            } elseif ($days >= 14) {
                $score -= 8;
                $reasons[] = 'Long inactivity reduced the momentum score.';
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
        $reasons[] = 'Limited factual data available.';
    }

    return array(
        'score' => $scoreInt,
        'label' => $label,
        'reasons' => array_slice(array_values(array_unique($reasons)), 0, 3),
    );
}

function cpo_course_progress_percent(array $row): int
{
    $totalLessons = (int)($row['total_lessons_count'] ?? 0);
    $completedLessons = (int)($row['completed_lessons_count'] ?? 0);

    if ($totalLessons <= 0) {
        return 0;
    }

    return cpo_percent_clamped(($completedLessons / $totalLessons) * 100);
}

function cpo_student_rank_score(array $row, array $motivation): float
{
    $progress = cpo_course_progress_percent($row);
    $avgScore = (float)($row['avg_score'] ?? 0.0);
    $latestScore = (float)($row['latest_score'] ?? 0.0);
    $deadlineMissed = (int)($row['deadline_missed_count'] ?? 0);
    $pendingInstructor = (int)($row['pending_instructor_actions_count'] ?? 0);
    $trainingSuspended = (int)($row['training_suspended_count'] ?? 0);

    $rank = 0.0;
    $rank += $progress * 0.40;
    $rank += $avgScore * 0.22;
    $rank += $latestScore * 0.14;
    $rank += ((float)$motivation['score']) * 0.24;

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
    $summaryNeedsRevision = trim((string)($row['latest_summary_status'] ?? '')) === 'needs_revision';
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

    if ($pendingRemediation > 0 || $pendingReason > 0 || $summaryNeedsRevision) {
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
    if (trim((string)($row['latest_summary_status'] ?? '')) === 'needs_revision') {
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
$showRetiredCohorts = !empty($_GET['show_retired']) ? 1 : 0;

$cohortSql = "
    SELECT
        c.id,
        c.course_id,
        c.name,
        c.start_date,
        c.end_date,
        COALESCE(cr.title, CONCAT('Course #', c.course_id)) AS course_title,
        COUNT(DISTINCT CASE
            WHEN u.role = 'student' AND u.status = 'active' THEN cs.user_id
            ELSE NULL
        END) AS student_count
    FROM cohorts c
    LEFT JOIN courses cr
        ON cr.id = c.course_id
    LEFT JOIN cohort_students cs
        ON cs.cohort_id = c.id
    LEFT JOIN users u
        ON u.id = cs.user_id
    WHERE (
        :show_retired = 1
        OR c.end_date >= CURDATE()
    )
    GROUP BY c.id, c.course_id, c.name, c.start_date, c.end_date, cr.title
    ORDER BY
        CASE
            WHEN CURDATE() BETWEEN c.start_date AND c.end_date THEN 0
            WHEN c.start_date > CURDATE() THEN 1
            ELSE 2
        END,
        c.start_date ASC,
        c.name ASC,
        c.id ASC
";

$cohortStmt = $pdo->prepare($cohortSql);
$cohortStmt->execute(array(
    ':show_retired' => $showRetiredCohorts,
));
$cohorts = $cohortStmt->fetchAll(PDO::FETCH_ASSOC);

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

$scope = array('cohort_id' => $cohortId);
$policySnapshot = $cohortId > 0 ? $engine->getAllPolicies($scope) : array();
$multipleUnsatSameLessonThreshold = max(1, (int)($policySnapshot['multiple_unsat_same_lesson_threshold'] ?? 3));
$maxTotalAttemptsWithoutAdminOverride = max(1, (int)($policySnapshot['max_total_attempts_without_admin_override'] ?? 5));

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
            c.course_id,
            COALESCE(cr.title, CONCAT('Course #', c.course_id)) AS course_title,

            (
                SELECT COUNT(*)
                FROM lessons lcount
                WHERE lcount.course_id = c.course_id
            ) AS total_lessons_count,

            (
                SELECT COUNT(*)
                FROM lesson_activity la_done
                WHERE la_done.user_id = u.id
                  AND la_done.cohort_id = c.id
                  AND la_done.completion_status = 'completed'
            ) AS completed_lessons_count,

            (
                SELECT cld_current.lesson_id
                FROM cohort_lesson_deadlines cld_current
                LEFT JOIN lesson_activity la_current
                    ON la_current.user_id = u.id
                   AND la_current.cohort_id = c.id
                   AND la_current.lesson_id = cld_current.lesson_id
                WHERE cld_current.cohort_id = c.id
                  AND (
                    la_current.id IS NULL
                    OR COALESCE(la_current.completion_status, '') <> 'completed'
                  )
                ORDER BY cld_current.sort_order ASC, cld_current.id ASC
                LIMIT 1
            ) AS current_lesson_id,

            (
                SELECT COUNT(*)
                FROM progress_tests_v2 pt_count
                WHERE pt_count.user_id = u.id
                  AND pt_count.cohort_id = c.id
                  AND pt_count.lesson_id = (
                      SELECT cld_current2.lesson_id
                      FROM cohort_lesson_deadlines cld_current2
                      LEFT JOIN lesson_activity la_current2
                          ON la_current2.user_id = u.id
                         AND la_current2.cohort_id = c.id
                         AND la_current2.lesson_id = cld_current2.lesson_id
                      WHERE cld_current2.cohort_id = c.id
                        AND (
                            la_current2.id IS NULL
                            OR COALESCE(la_current2.completion_status, '') <> 'completed'
                        )
                      ORDER BY cld_current2.sort_order ASC, cld_current2.id ASC
                      LIMIT 1
                  )
                  AND pt_count.status = 'completed'
            ) AS attempt_count_calc,

            (
                SELECT COUNT(*)
                FROM progress_tests_v2 pt_pass
                WHERE pt_pass.user_id = u.id
                  AND pt_pass.cohort_id = c.id
                  AND pt_pass.status = 'completed'
                  AND pt_pass.pass_gate_met = 1
            ) AS pass_count_calc,

            (
                SELECT COUNT(*)
                FROM progress_tests_v2 pt_late
                WHERE pt_late.user_id = u.id
                  AND pt_late.cohort_id = c.id
                  AND pt_late.status = 'completed'
                  AND (pt_late.timing_status = 'late' OR pt_late.timing_status = 'after_final_deadline')
            ) AS late_attempt_count,

            (
                SELECT ROUND(AVG(pt_avg.score_pct), 1)
                FROM progress_tests_v2 pt_avg
                WHERE pt_avg.user_id = u.id
                  AND pt_avg.cohort_id = c.id
                  AND pt_avg.status = 'completed'
                  AND pt_avg.score_pct IS NOT NULL
            ) AS avg_score,

            (
                SELECT MAX(pt_best.score_pct)
                FROM progress_tests_v2 pt_best
                WHERE pt_best.user_id = u.id
                  AND pt_best.cohort_id = c.id
                  AND pt_best.status = 'completed'
                  AND pt_best.score_pct IS NOT NULL
            ) AS best_score_calc,

            (
                SELECT pt_first.score_pct
                FROM progress_tests_v2 pt_first
                WHERE pt_first.user_id = u.id
                  AND pt_first.cohort_id = c.id
                  AND pt_first.status = 'completed'
                  AND pt_first.score_pct IS NOT NULL
                ORDER BY COALESCE(pt_first.completed_at, pt_first.created_at) ASC, pt_first.id ASC
                LIMIT 1
            ) AS first_attempt_score,

            (
                SELECT pt_latest.score_pct
                FROM progress_tests_v2 pt_latest
                WHERE pt_latest.user_id = u.id
                  AND pt_latest.cohort_id = c.id
                  AND pt_latest.status = 'completed'
                ORDER BY COALESCE(pt_latest.completed_at, pt_latest.created_at) DESC, pt_latest.id DESC
                LIMIT 1
            ) AS latest_score,

            (
                SELECT pt_latest.formal_result_label
                FROM progress_tests_v2 pt_latest
                WHERE pt_latest.user_id = u.id
                  AND pt_latest.cohort_id = c.id
                  AND pt_latest.status = 'completed'
                ORDER BY COALESCE(pt_latest.completed_at, pt_latest.created_at) DESC, pt_latest.id DESC
                LIMIT 1
            ) AS latest_result_label,

            (
                SELECT pt_latest.formal_result_code
                FROM progress_tests_v2 pt_latest
                WHERE pt_latest.user_id = u.id
                  AND pt_latest.cohort_id = c.id
                  AND pt_latest.status = 'completed'
                ORDER BY COALESCE(pt_latest.completed_at, pt_latest.created_at) DESC, pt_latest.id DESC
                LIMIT 1
            ) AS latest_result_code,

            (
                SELECT COALESCE(pt_latest.completed_at, pt_latest.created_at)
                FROM progress_tests_v2 pt_latest
                WHERE pt_latest.user_id = u.id
                  AND pt_latest.cohort_id = c.id
                  AND pt_latest.status = 'completed'
                ORDER BY COALESCE(pt_latest.completed_at, pt_latest.created_at) DESC, pt_latest.id DESC
                LIMIT 1
            ) AS latest_attempt_at,

            (
                SELECT lpt.title
                FROM progress_tests_v2 pt_lesson
                INNER JOIN lessons lpt
                    ON lpt.id = pt_lesson.lesson_id
                WHERE pt_lesson.user_id = u.id
                  AND pt_lesson.cohort_id = c.id
                ORDER BY COALESCE(pt_lesson.completed_at, pt_lesson.created_at) DESC, pt_lesson.id DESC
                LIMIT 1
            ) AS latest_progress_test_lesson_title,

            (
                SELECT ls_latest.review_status
                FROM lesson_summaries ls_latest
                WHERE ls_latest.user_id = u.id
                  AND ls_latest.cohort_id = c.id
                ORDER BY ls_latest.updated_at DESC, ls_latest.id DESC
                LIMIT 1
            ) AS latest_summary_status,

            (
                SELECT ls_latest.review_score
                FROM lesson_summaries ls_latest
                WHERE ls_latest.user_id = u.id
                  AND ls_latest.cohort_id = c.id
                ORDER BY ls_latest.updated_at DESC, ls_latest.id DESC
                LIMIT 1
            ) AS latest_summary_score,

            (
                SELECT l_current.title
                FROM cohort_lesson_deadlines cld_current
                INNER JOIN lessons l_current
                    ON l_current.id = cld_current.lesson_id
                LEFT JOIN lesson_activity la_current
                    ON la_current.user_id = u.id
                   AND la_current.cohort_id = c.id
                   AND la_current.lesson_id = cld_current.lesson_id
                WHERE cld_current.cohort_id = c.id
                  AND (
                    la_current.id IS NULL
                    OR COALESCE(la_current.completion_status, '') <> 'completed'
                  )
                ORDER BY cld_current.sort_order ASC, cld_current.id ASC
                LIMIT 1
            ) AS current_lesson_title,

            (
                SELECT COUNT(*)
                FROM lesson_activity la_deadline
                WHERE la_deadline.user_id = u.id
                  AND la_deadline.cohort_id = c.id
                  AND (
                    la_deadline.completion_status = 'deadline_blocked'
                    OR la_deadline.test_pass_status = 'deadline_missed'
                  )
            ) AS deadline_missed_count,

            (
                SELECT COUNT(*)
                FROM lesson_activity la_suspend
                WHERE la_suspend.user_id = u.id
                  AND la_suspend.cohort_id = c.id
                  AND la_suspend.training_suspended = 1
            ) AS training_suspended_count,

            (
                SELECT COUNT(*)
                FROM lesson_activity la_o2o
                WHERE la_o2o.user_id = u.id
                  AND la_o2o.cohort_id = c.id
                  AND la_o2o.one_on_one_required = 1
            ) AS one_on_one_required_count,

            (
                SELECT COUNT(*)
                FROM lesson_activity la_o2o_done
                WHERE la_o2o_done.user_id = u.id
                  AND la_o2o_done.cohort_id = c.id
                  AND la_o2o_done.one_on_one_completed = 1
            ) AS one_on_one_completed_count,

            (
                SELECT COUNT(*)
                FROM student_required_actions sra_all
                WHERE sra_all.user_id = u.id
                  AND sra_all.cohort_id = c.id
                  AND sra_all.status IN ('pending', 'opened')
            ) AS pending_actions_count,

            (
                SELECT COUNT(*)
                FROM student_required_actions sra_instr
                WHERE sra_instr.user_id = u.id
                  AND sra_instr.cohort_id = c.id
                  AND sra_instr.action_type = 'instructor_approval'
                  AND sra_instr.status IN ('pending', 'opened')
            ) AS pending_instructor_actions_count,

            (
                SELECT COUNT(*)
                FROM student_required_actions sra_rem
                WHERE sra_rem.user_id = u.id
                  AND sra_rem.cohort_id = c.id
                  AND sra_rem.action_type = 'remediation_acknowledgement'
                  AND sra_rem.status IN ('pending', 'opened')
            ) AS pending_remediation_actions_count,

            (
                SELECT COUNT(*)
                FROM student_required_actions sra_reason
                WHERE sra_reason.user_id = u.id
                  AND sra_reason.cohort_id = c.id
                  AND sra_reason.action_type = 'deadline_reason_submission'
                  AND sra_reason.status IN ('pending', 'opened')
            ) AS pending_reason_actions_count,

            (
                SELECT sra2.id
                FROM student_required_actions sra2
                WHERE sra2.user_id = u.id
                  AND sra2.cohort_id = c.id
                  AND sra2.action_type = 'instructor_approval'
                  AND sra2.status IN ('pending', 'opened', 'approved')
                ORDER BY sra2.id DESC
                LIMIT 1
            ) AS latest_instructor_action_id,

            (
                SELECT sra3.token
                FROM student_required_actions sra3
                WHERE sra3.user_id = u.id
                  AND sra3.cohort_id = c.id
                  AND sra3.action_type = 'instructor_approval'
                  AND sra3.status IN ('pending', 'opened', 'approved')
                ORDER BY sra3.id DESC
                LIMIT 1
            ) AS latest_instructor_action_token,

            (
                SELECT l_attention.title
                FROM student_required_actions sra4
                INNER JOIN lessons l_attention
                    ON l_attention.id = sra4.lesson_id
                WHERE sra4.user_id = u.id
                  AND sra4.cohort_id = c.id
                  AND sra4.status IN ('pending', 'opened')
                ORDER BY sra4.id DESC
                LIMIT 1
            ) AS attention_lesson_title,

            GREATEST(
                COALESCE((
                    SELECT MAX(COALESCE(pt_act.completed_at, pt_act.updated_at, pt_act.created_at))
                    FROM progress_tests_v2 pt_act
                    WHERE pt_act.user_id = u.id
                      AND pt_act.cohort_id = c.id
                ), '1000-01-01 00:00:00'),
                COALESCE((
                    SELECT MAX(ls_act.updated_at)
                    FROM lesson_summaries ls_act
                    WHERE ls_act.user_id = u.id
                      AND ls_act.cohort_id = c.id
                ), '1000-01-01 00:00:00'),
                COALESCE((
                    SELECT MAX(COALESCE(la_act.last_state_eval_at, la_act.updated_at))
                    FROM lesson_activity la_act
                    WHERE la_act.user_id = u.id
                      AND la_act.cohort_id = c.id
                ), '1000-01-01 00:00:00'),
                COALESCE((
                    SELECT MAX(sra_act.updated_at)
                    FROM student_required_actions sra_act
                    WHERE sra_act.user_id = u.id
                      AND sra_act.cohort_id = c.id
                ), '1000-01-01 00:00:00'),
                COALESCE(u.updated_at, '1000-01-01 00:00:00')
            ) AS latest_activity_at

        FROM cohort_students cs
        INNER JOIN users u
            ON u.id = cs.user_id
           AND u.role = 'student'
           AND u.status = 'active'
        INNER JOIN cohorts c
            ON c.id = cs.cohort_id
        LEFT JOIN courses cr
            ON cr.id = c.course_id
        WHERE cs.cohort_id = :cohort_id
        ORDER BY u.name ASC, u.id ASC
";

    $studentStmt = $pdo->prepare($studentSql);
    $studentStmt->execute(array(
        ':cohort_id' => $cohortId,
    ));
    $studentRows = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
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
    $progressPercent = cpo_course_progress_percent($row);
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
    $latestInstructorActionId = (int)($row['latest_instructor_action_id'] ?? 0);

    $reviewTestId = 0;

    $latestTestRow = ptr_fetch_one(
        $pdo,
        "
        SELECT id
        FROM progress_tests_v2
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id = ?
        ORDER BY
            CASE
                WHEN status = 'ready' THEN 0
                WHEN status = 'completed' THEN 1
                ELSE 2
            END,
            attempt_no DESC,
            id DESC
        LIMIT 1
        ",
        array(
            (int)$row['user_id'],
            (int)$row['cohort_id'],
            (int)($row['current_lesson_id'] ?? 0)
        )
    );

    if ($latestTestRow && !empty($latestTestRow['id'])) {
        $reviewTestId = (int)$latestTestRow['id'];
    }

    $openUrl = $reviewTestId > 0
        ? '/instructor/progress_test_review.php?test_id=' . $reviewTestId
        : '';

$currentLessonId = (int)($row['current_lesson_id'] ?? 0);
$progressReviewUrl = '';

if ($cohortId > 0 && (int)($row['user_id'] ?? 0) > 0 && $currentLessonId > 0) {
    $progressReviewUrl = '/instructor/progress_test_review.php?cohort_id='
        . rawurlencode((string)$cohortId)
        . '&user_id=' . rawurlencode((string)((int)$row['user_id']))
        . '&lesson_id=' . rawurlencode((string)$currentLessonId);
}

$cards[] = array(
    'row' => $row,
    'student_name' => $studentName,
    'motivation' => $motivation,
    'progress_percent' => $progressPercent,
    'rank_score' => $rankScore,
    'urgency' => $urgency,
    'pills' => $pills,
    'open_url' => $openUrl,
    'progress_review_url' => $progressReviewUrl,
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
$selectedCohortStatus = $selectedCohort ? cpo_cohort_status($selectedCohort) : array(
    'label' => '—',
    'class' => 'info',
);

cw_header('Cohort Progress Overview');
?>

<style>
.cpo-page{display:flex;flex-direction:column;gap:18px}
.cpo-hero{padding:22px 24px}
.cpo-eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.14em;color:#64748b;font-weight:800;margin-bottom:8px}
.cpo-title{margin:0;font-size:32px;line-height:1.05;letter-spacing:-.04em;color:#102845}
.cpo-toolbar{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:14px;align-items:end;margin-top:14px}
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
.cpo-info-card{
    padding:18px 20px;background:linear-gradient(135deg,#12355f 0%,#1f4e89 100%);
    color:#fff;border-radius:22px;border:1px solid rgba(18,53,95,.20);
}
.cpo-info-title{margin:0;font-size:24px;line-height:1.05;font-weight:820;letter-spacing:-.03em;color:#fff}
.cpo-info-sub{margin-top:8px;font-size:13px;line-height:1.55;color:rgba(255,255,255,.82)}
.cpo-hero-meta{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.cpo-hero-chip{
    display:inline-flex;align-items:center;justify-content:center;
    min-height:32px;padding:0 12px;border-radius:999px;
    font-size:12px;font-weight:800;white-space:nowrap;
    border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.10);color:#fff;
}
.cpo-hero-chip.ok{background:rgba(22,163,74,.18);border-color:rgba(255,255,255,.16)}
.cpo-hero-chip.info{background:rgba(59,130,246,.20);border-color:rgba(255,255,255,.16)}
.cpo-hero-chip.warning{background:rgba(245,158,11,.22);border-color:rgba(255,255,255,.16)}
.cpo-metric-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px}
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
.cpo-person{display:flex;gap:14px;align-items:center;min-width:0}
.ip-avatar{width:84px;height:84px;border-radius:24px;overflow:hidden;flex:0 0 84px;background:linear-gradient(180deg,#e8eef7 0%,#dfe7f2 100%);border:1px solid rgba(15,23,42,0.07);display:flex;align-items:center;justify-content:center;box-shadow:inset 0 1px 0 rgba(255,255,255,0.45)}
.ip-avatar img{width:100%;height:100%;object-fit:cover;display:block}
.ip-avatar-fallback{width:34px;height:34px;color:#7b8aa0}
.ip-avatar-fallback svg{width:100%;height:100%;display:block}
.cpo-person-copy{min-width:0}
.cpo-person-role{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#64748b}
.cpo-person-name{margin-top:4px;font-size:22px;line-height:1.05;letter-spacing:-.03em;font-weight:820;color:#102845}
.cpo-person-sub{margin-top:8px;font-size:13px;line-height:1.55;color:#64748b}
.cpo-chief-wrap{display:flex;justify-content:flex-end}
.cpo-chief-card{display:flex;gap:12px;align-items:center;padding:12px 14px;border-radius:18px;background:linear-gradient(180deg,#f8fbff 0%,#f3f7fd 100%);border:1px solid rgba(18,53,95,.08)}
.cpo-chief-copy{min-width:0}
.cpo-chief-label{font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#64748b}
.cpo-chief-name{margin-top:4px;font-size:15px;font-weight:800;color:#102845}
.cpo-chief-sub{margin-top:3px;font-size:12px;color:#64748b}
.cpo-side-actions{display:flex;flex-direction:column;gap:10px;align-items:flex-end}
.cpo-urgency-pill{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:0 12px;border-radius:999px;font-size:12px;font-weight:900;white-space:nowrap}
.cpo-urgency-pill.ok{background:#ecfdf5;color:#166534}
.cpo-urgency-pill.warning{background:#fff7ed;color:#c2410c}
.cpo-urgency-pill.attention{background:#fef3c7;color:#92400e}
.cpo-urgency-pill.urgent{background:#fee2e2;color:#991b1b}
.cpo-rank-badge{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:0 12px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:900;border:1px solid #bfdbfe}
.cpo-content-grid{display:grid;grid-template-columns:1.15fr 1fr;gap:18px;margin-top:18px}
.cpo-panel{padding:18px;border-radius:20px;border:1px solid rgba(15,23,42,.07);background:#fff}
.cpo-panel-title{margin:0 0 12px 0;font-size:16px;font-weight:820;color:#102845}
.cpo-kv{display:grid;grid-template-columns:1fr auto;gap:10px;padding:12px 0;border-bottom:1px solid rgba(15,23,42,.06)}
.cpo-kv:last-child{border-bottom:0}
.cpo-kv-label{font-size:13px;font-weight:700;color:#334155}
.cpo-kv-value{font-size:13px;font-weight:800;color:#102845;text-align:right}
.cpo-bars{display:flex;flex-direction:column;gap:16px}
.cpo-progress-row{display:flex;flex-direction:column;gap:8px}
.cpo-progress-head{display:flex;justify-content:space-between;gap:10px;align-items:center}
.cpo-progress-label{font-size:13px;font-weight:800;color:#102845}
.cpo-progress-value{font-size:12px;font-weight:800;color:#64748b}
.cpo-inline-bar{display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:210px}
.cpo-inline-bar .cpo-progress-value{min-width:44px;text-align:right;flex:0 0 auto}
.cpo-inline-bar .cpo-progress-track{flex:1 1 auto;min-width:120px}
.cpo-inline-bar.compact{min-width:250px}
.cpo-inline-bar.compact .cpo-progress-track{min-width:110px}
.cpo-inline-status{
    display:inline-flex;align-items:center;justify-content:center;
    min-height:22px;padding:0 8px;border-radius:999px;font-size:10px;font-weight:800;
    border:1px solid rgba(15,23,42,.08);white-space:nowrap;
}
.cpo-inline-status.ok{background:#ecfdf5;color:#166534;border-color:#bbf7d0}
.cpo-inline-status.warning{background:#fff7ed;color:#c2410c;border-color:#fdba74}
.cpo-inline-status.danger{background:#fee2e2;color:#991b1b;border-color:#fca5a5}
.cpo-inline-status.info{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.cpo-progress-track{width:100%;height:12px;border-radius:999px;overflow:hidden;background:#e7edf5;position:relative}
.cpo-progress-fill{height:100%;border-radius:999px}
.cpo-progress-fill.good{background:linear-gradient(90deg,#166534 0%,#22c55e 100%)}
.cpo-progress-fill.amber{background:linear-gradient(90deg,#c2410c 0%,#f59e0b 100%)}
.cpo-progress-fill.danger{background:linear-gradient(90deg,#991b1b 0%,#ef4444 100%)}
.cpo-progress-fill.neutral{background:linear-gradient(90deg,#64748b 0%,#cbd5e1 100%)}
.cpo-mini-note{font-size:12px;line-height:1.55;color:#64748b}
.cpo-mini-list{display:grid;gap:8px;margin-top:10px}
.cpo-mini-list-item{display:flex;gap:8px;align-items:flex-start;font-size:11px;line-height:1.35;color:#0f172a}
.cpo-mini-dot{width:4px;height:4px;border-radius:999px;background:#0f172a;flex:0 0 4px;margin-top:5px}
.cpo-state-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.cpo-state-pill{display:inline-flex;align-items:center;justify-content:center;min-height:30px;padding:0 10px;border-radius:999px;font-size:11px;font-weight:800;border:1px solid rgba(15,23,42,.08)}
.cpo-state-pill.ok{background:#ecfdf5;color:#166534;border-color:#bbf7d0}
.cpo-state-pill.warning{background:#fff7ed;color:#c2410c;border-color:#fdba74}
.cpo-state-pill.danger{background:#fee2e2;color:#991b1b;border-color:#fca5a5}
.cpo-state-pill.info{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.cpo-attempt-ok{color:#166534}
.cpo-attempt-warning{color:#c2410c}
.cpo-attempt-danger{color:#991b1b}
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
@media (max-width: 960px){
    .cpo-toolbar{grid-template-columns:1fr}
}
@media (max-width: 860px){
    .cpo-metric-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .cpo-top-grid{grid-template-columns:1fr}
}
</style>

<div class="cpo-page">

    <section class="card cpo-hero">
        <div class="cpo-eyebrow">Instructor Platform · Theory Progress</div>
        <h1 class="cpo-title">Cohort Progress Overview</h1>

        <form method="get" class="cpo-toolbar">
            <div class="cpo-field">
                <label class="cpo-label">Select Cohort</label>
                <select class="cpo-select" name="cohort_id">
                    <?php foreach ($cohorts as $cohortRow): ?>
                        <?php
                        $rowId = (int)($cohortRow['id'] ?? 0);
                        $rowName = (string)($cohortRow['name'] ?? ('Cohort #' . $rowId));
                        $rowStart = (string)($cohortRow['start_date'] ?? '');
                        $rowEnd = (string)($cohortRow['end_date'] ?? '');
                        $rowStudents = (int)($cohortRow['student_count'] ?? 0);

                        $statusLabel = 'Ongoing';
                        $today = gmdate('Y-m-d');

                        if ($rowStart !== '' && $rowStart > $today) {
                            $statusLabel = 'Future';
                        } elseif ($rowEnd !== '' && $rowEnd < $today) {
                            $statusLabel = 'Retired';
                        }
                        ?>
                        <option value="<?php echo $rowId; ?>" <?php echo $rowId === $cohortId ? 'selected' : ''; ?>>
                            <?php echo cpo_h($rowName . ' · ' . $statusLabel . ' · ' . $rowStudents . ' student(s)'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="cpo-field" style="justify-content:end;">
                <label class="cpo-label" style="visibility:hidden;">Options</label>
                <label class="cpo-mini-note" style="display:flex;align-items:center;gap:8px;min-height:42px;">
                    <input type="checkbox" name="show_retired" value="1" <?php echo $showRetiredCohorts ? 'checked' : ''; ?>>
                    Show retired cohorts
                </label>
            </div>

            <div>
                <button class="cpo-btn" type="submit">Load Cohort</button>
            </div>
        </form>
    </section>

    <?php if ($selectedCohort): ?>
        <section class="cpo-info-card">
            <h2 class="cpo-info-title"><?php echo cpo_h((string)($selectedCohort['name'] ?? 'Cohort')); ?></h2>
            <div class="cpo-info-sub">
                <?php echo cpo_h((string)($selectedCohort['course_title'] ?? '')); ?>
            </div>

            <div class="cpo-hero-meta">
                <span class="cpo-hero-chip <?php echo cpo_h((string)$selectedCohortStatus['class']); ?>">
                    <?php echo cpo_h((string)$selectedCohortStatus['label']); ?>
                </span>
                <span class="cpo-hero-chip">
                    Start: <?php echo cpo_h(cpo_format_date_label((string)($selectedCohort['start_date'] ?? ''))); ?>
                </span>
                <span class="cpo-hero-chip">
                    End: <?php echo cpo_h(cpo_format_date_label((string)($selectedCohort['end_date'] ?? ''))); ?>
                </span>
                <span class="cpo-hero-chip">
                    <?php echo (int)($selectedCohort['student_count'] ?? 0); ?> student(s)
                </span>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!$selectedCohort): ?>
        <section class="card">
            <div class="cpo-empty">No cohort available.</div>
        </section>
    <?php else: ?>

        <section class="cpo-metric-grid">
            <div class="card cpo-metric-card">
                <div class="cpo-metric-kicker">Students</div>
                <div class="cpo-metric-value"><?php echo (int)$summaryMetrics['students']; ?></div>
                <div class="cpo-metric-sub">Active students in this cohort overview.</div>
            </div>

            <div class="card cpo-metric-card">
                <div class="cpo-metric-kicker">Average Score</div>
                <div class="cpo-metric-value"><?php echo cpo_h((string)$summaryMetrics['avg_score']); ?><span style="font-size:18px;">%</span></div>
                <div class="cpo-metric-sub">Average across each student’s completed progress test results.</div>
            </div>

            <div class="card cpo-metric-card">
                <div class="cpo-metric-kicker">Urgent</div>
                <div class="cpo-metric-value" style="color:#991b1b;"><?php echo (int)$summaryMetrics['urgent']; ?></div>
                <div class="cpo-metric-sub">Instructor-required, suspended, or deadline-critical cases.</div>
            </div>

            <div class="card cpo-metric-card">
                <div class="cpo-metric-kicker">Needs Attention</div>
                <div class="cpo-metric-value" style="color:#92400e;"><?php echo (int)$summaryMetrics['attention']; ?></div>
                <div class="cpo-metric-sub">Remediation, reason submission, or summary revision cases.</div>
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
                                <?php echo cpo_avatar_html($row, $card['student_name'], '72'); ?>
                            </div>
                            <div class="cpo-top-name"><?php echo cpo_h($card['student_name']); ?></div>
                            <div class="cpo-top-meta">
                                Avg Score: <strong><?php echo cpo_h((string)round((float)($row['avg_score'] ?? 0.0), 1)); ?>%</strong><br>
                                Motivation: <strong><?php echo cpo_h($card['motivation']['label']); ?></strong><br>
                                Course Progress: <strong><?php echo (int)$card['progress_percent']; ?>%</strong>
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
                    $currentLessonTitle = trim((string)($row['current_lesson_title'] ?? ''));
                    $latestSummaryStatus = trim((string)($row['latest_summary_status'] ?? ''));
                    $latestSummaryScore = isset($row['latest_summary_score']) && $row['latest_summary_score'] !== null
                        ? (float)$row['latest_summary_score']
                        : null;
                    $latestResultLabel = trim((string)($row['latest_result_label'] ?? ''));
                    $latestResultCode = trim((string)($row['latest_result_code'] ?? ''));
                    $latestProgressTestLessonTitle = trim((string)($row['latest_progress_test_lesson_title'] ?? ''));
                    $avgScoreDisplay = isset($row['avg_score']) && $row['avg_score'] !== null ? round((float)$row['avg_score'], 1) : null;
                    $bestScoreDisplay = isset($row['best_score_calc']) && $row['best_score_calc'] !== null ? round((float)$row['best_score_calc'], 1) : null;
                    $latestScoreDisplay = isset($row['latest_score']) && $row['latest_score'] !== null ? round((float)$row['latest_score'], 1) : null;
                    $attemptCount = (int)($row['attempt_count_calc'] ?? 0);

                    $attemptClass = 'cpo-attempt-ok';
                    if ($attemptCount >= $maxTotalAttemptsWithoutAdminOverride) {
                        $attemptClass = 'cpo-attempt-danger';
                    } elseif ($attemptCount >= $multipleUnsatSameLessonThreshold) {
                        $attemptClass = 'cpo-attempt-warning';
                    }
                    ?>
                    <section class="card cpo-student-card">
                        <div class="cpo-student-top">
                            <div class="cpo-person">
                                <?php echo cpo_avatar_html($row, $studentName, '84'); ?>
                                <div class="cpo-person-copy">
                                    <div class="cpo-person-role">Student</div>
                                    <div class="cpo-person-name"><?php echo cpo_h($studentName); ?></div>
                                    <div class="cpo-person-sub">
                                        Rank Score: <strong><?php echo cpo_h((string)$card['rank_score']); ?></strong><br>
                                        Current lesson: <strong><?php echo cpo_h($currentLessonTitle !== '' ? $currentLessonTitle : '—'); ?></strong>
                                        <?php if ($attentionLessonTitle !== ''): ?>
                                            <br>Current attention lesson: <strong><?php echo cpo_h($attentionLessonTitle); ?></strong>
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
                                        ? cpo_avatar_html($chiefInstructor, $chiefName, '56')
                                        : '<div class="ip-avatar" style="width:56px;height:56px;border-radius:18px;flex:0 0 56px;"><span class="ip-avatar-fallback" style="width:24px;height:24px;">' . cpo_svg_users() . '</span></div>';
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

                                <?php if (!empty($card['progress_review_url'])): ?>
    <a class="cpo-btn secondary" href="<?php echo cpo_h($card['progress_review_url']); ?>">Open Progress Test Review</a>
<?php else: ?>
    <span class="cpo-btn secondary" style="opacity:.65;cursor:default;">No Progress Test Review Available</span>
<?php endif; ?>

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
                                    <div class="cpo-kv-label">Average Score</div>
                                    <div class="cpo-kv-value" style="min-width:210px;">
                                        <?php if ($avgScoreDisplay !== null): ?>
											<div class="cpo-inline-bar">
												<div class="cpo-progress-value"><?php echo cpo_h((string)$avgScoreDisplay); ?>%</div>
												<div class="cpo-progress-track">
													<div class="cpo-progress-fill <?php echo cpo_h(cpo_bar_class($avgScoreDisplay)); ?>" style="width:<?php echo (int)$avgScoreDisplay; ?>%;"></div>
												</div>
											</div>
										<?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="cpo-kv">
                                    <div class="cpo-kv-label">Best Score</div>
                                    <div class="cpo-kv-value" style="min-width:210px;">
                                        <?php if ($bestScoreDisplay !== null): ?>
											<div class="cpo-inline-bar">
												<div class="cpo-progress-value"><?php echo cpo_h((string)$bestScoreDisplay); ?>%</div>
												<div class="cpo-progress-track">
													<div class="cpo-progress-fill <?php echo cpo_h(cpo_bar_class($bestScoreDisplay)); ?>" style="width:<?php echo (int)$bestScoreDisplay; ?>%;"></div>
												</div>
											</div>
										<?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="cpo-kv">
                                    <div class="cpo-kv-label">Latest Progress Test</div>
                                    <div class="cpo-kv-value"><?php echo cpo_h($latestProgressTestLessonTitle !== '' ? $latestProgressTestLessonTitle : '—'); ?></div>
                                </div>

                                <div class="cpo-kv">
                                    <div class="cpo-kv-label">Attempt Count</div>
                                    <div class="cpo-kv-value <?php echo cpo_h($attemptClass); ?>"><?php echo $attemptCount; ?></div>
                                </div>

                                <div class="cpo-kv">
                                    <div class="cpo-kv-label">Latest Score</div>
                                    <div class="cpo-kv-value" style="min-width:210px;">
                                        <?php if ($latestScoreDisplay !== null): ?>
											<div class="cpo-inline-bar">
												<div class="cpo-progress-value"><?php echo cpo_h((string)$latestScoreDisplay); ?>%</div>
												<div class="cpo-progress-track">
													<div class="cpo-progress-fill <?php echo cpo_h(cpo_bar_class($latestScoreDisplay)); ?>" style="width:<?php echo (int)$latestScoreDisplay; ?>%;"></div>
												</div>
											</div>
										<?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="cpo-kv">
										<div class="cpo-kv-label">Latest Summary</div>
										<div class="cpo-kv-value" style="min-width:250px;">
											<?php if ($latestSummaryScore !== null): ?>
												<div class="cpo-inline-bar compact">
													<div class="cpo-progress-value"><?php echo cpo_h((string)round($latestSummaryScore, 1)); ?>%</div>
													<div class="cpo-progress-track">
														<div class="cpo-progress-fill <?php echo cpo_h(cpo_bar_class($latestSummaryScore)); ?>" style="width:<?php echo (int)round($latestSummaryScore); ?>%;"></div>
													</div>
													<span class="cpo-inline-status <?php echo cpo_h(cpo_summary_pill_class($latestSummaryStatus)); ?>">
														<?php echo cpo_h(cpo_summary_pill_label($latestSummaryStatus)); ?>
													</span>
												</div>
											<?php else: ?>
												<?php if ($latestSummaryStatus !== ''): ?>
													<span class="cpo-inline-status <?php echo cpo_h(cpo_summary_pill_class($latestSummaryStatus)); ?>">
														<?php echo cpo_h(cpo_summary_pill_label($latestSummaryStatus)); ?>
													</span>
												<?php else: ?>
													—
												<?php endif; ?>
											<?php endif; ?>
										</div>
									</div>

                                <div class="cpo-kv">
                                    <div class="cpo-kv-label">Latest Activity</div>
                                    <div class="cpo-kv-value"><?php echo cpo_h(cpo_format_datetime_utc((string)($row['latest_activity_at'] ?? ''))); ?></div>
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
                                            <div class="cpo-progress-fill <?php echo cpo_h(cpo_bar_class((float)$card['progress_percent'])); ?>" style="width:<?php echo (int)$card['progress_percent']; ?>%;"></div>
                                        </div>
                                        <div class="cpo-mini-note">
                                            Course-wide progress based on completed lessons versus total lessons in the course.
                                        </div>
                                    </div>

                                    <div class="cpo-progress-row">
                                        <div class="cpo-progress-head">
                                            <div class="cpo-progress-label">Motivation</div>
                                            <div class="cpo-progress-value"><?php echo cpo_h($card['motivation']['label']); ?> · <?php echo (int)$card['motivation']['score']; ?>%</div>
                                        </div>
                                        <div class="cpo-progress-track">
                                            <div class="cpo-progress-fill <?php echo cpo_h(cpo_bar_class((float)$card['motivation']['score'])); ?>" style="width:<?php echo (int)$card['motivation']['score']; ?>%;"></div>
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