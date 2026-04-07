<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/courseware_progression_v2.php';

cw_require_login();

$u = cw_current_user($pdo);
$currentUserId = (int)($u['id'] ?? 0);
$currentRole = trim((string)($u['role'] ?? ''));

$allowedRoles = array('admin', 'supervisor', 'instructor', 'chief_instructor');
if (!in_array($currentRole, $allowedRoles, true)) {
    http_response_code(403);
    exit('Forbidden');
}

$engine = new CoursewareProgressionV2($pdo);

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    exit('Missing token');
}

$ipAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
$userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

$flashError = '';
$flashSuccess = '';

function ia_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function ia_svg_users(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true">'
        . '<path d="M12 12c2.761 0 5-2.462 5-5.5S14.761 1 12 1 7 3.462 7 6.5 9.239 12 12 12Z" fill="currentColor" opacity=".88"/>'
        . '<path d="M3.5 22c.364-4.157 4.006-7 8.5-7s8.136 2.843 8.5 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
        . '</svg>';
}

function ia_avatar_html(array $user, string $displayName, int $size = 84): string
{
    $photoPath = trim((string)($user['photo_path'] ?? ''));
    $size = max(40, $size);
    $radius = max(14, (int)round($size * 0.29));
    $fallback = max(20, (int)round($size * 0.40));

    if ($photoPath !== '') {
        return '<div class="ip-avatar" style="width:' . $size . 'px;height:' . $size . 'px;border-radius:' . $radius . 'px;flex:0 0 ' . $size . 'px;">'
            . '<img src="' . ia_h($photoPath) . '" alt="' . ia_h($displayName) . '">'
            . '</div>';
    }

    return '<div class="ip-avatar" style="width:' . $size . 'px;height:' . $size . 'px;border-radius:' . $radius . 'px;flex:0 0 ' . $size . 'px;">'
        . '<span class="ip-avatar-fallback" style="width:' . $fallback . 'px;height:' . $fallback . 'px;">' . ia_svg_users() . '</span>'
        . '</div>';
}

function ia_format_datetime_utc(?string $dt): string
{
    $dt = trim((string)$dt);
    if ($dt === '') {
        return '—';
    }

    $ts = strtotime($dt);
    if ($ts === false) {
        return $dt;
    }

    return gmdate('D M j, Y', $ts) . ' - ' . gmdate('H:i', $ts) . ' UTC';
}

function ia_percent_clamped(?float $value): ?int
{
    if ($value === null) {
        return null;
    }
    if ($value < 0) {
        $value = 0;
    }
    if ($value > 100) {
        $value = 100;
    }
    return (int)round($value);
}

function ia_bar_class(?float $score): string
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

function ia_summary_status_class(string $status): string
{
    return match ($status) {
        'acceptable' => 'ok',
        'needs_revision' => 'danger',
        'pending' => 'warning',
        'rejected' => 'danger',
        default => 'info',
    };
}

function ia_summary_status_label(string $status): string
{
    return match ($status) {
        'acceptable' => 'Acceptable',
        'needs_revision' => 'Needs Revision',
        'pending' => 'Review Pending',
        'rejected' => 'Rejected',
        default => '—',
    };
}

function ia_result_label(string $code, string $label): string
{
    $label = trim($label);
    if ($label !== '') {
        return $label;
    }
    return strtoupper($code) === 'PASS' ? 'Satisfactory' : 'Unsatisfactory';
}

function ia_result_class(string $code): string
{
    return strtoupper($code) === 'PASS' ? 'ok' : 'danger';
}


function ia_decision_ui_options(): array
{
    return array(
        'approve_additional_attempts' => array(
            'label' => 'Approve additional attempts',
            'help' => 'Student may continue with additional attempts.',
        ),
        'approve_with_summary_revision' => array(
            'label' => 'Approve, but require summary revision',
            'help' => 'Student must improve the lesson summary before normal continuation.',
        ),
        'approve_with_one_on_one' => array(
            'label' => 'Approve, but require one-on-one',
            'help' => 'Instructor session required before continuation.',
        ),
        'suspend_training' => array(
            'label' => 'Suspend training',
            'help' => 'Progression paused pending stronger intervention.',
        ),
    );
}


function ia_decision_code_label(string $code): string
{
    return match ($code) {
        'approve_additional_attempts' => 'Extra Attempts',
        'approve_with_summary_revision' => 'Summary Revision',
        'approve_with_one_on_one' => 'One-on-One',
        'suspend_training' => 'Training Suspended',
        default => $code !== '' ? ucwords(str_replace('_', ' ', $code)) : '—',
    };
}

function ia_collect_instructor_interventions(PDO $pdo, int $userId, int $cohortId, int $lessonId): array
{
    $stmt = $pdo->prepare("
        SELECT
            sra.id,
            sra.progress_test_id,
            sra.status,
            sra.decision_code,
            sra.granted_extra_attempts,
            sra.summary_revision_required,
            sra.one_on_one_required,
            sra.training_suspended,
            sra.major_intervention_flag,
            sra.decision_notes,
            sra.decision_by_user_id,
            sra.decision_at,
            u.name AS decision_by_name,
            u.first_name AS decision_by_first_name,
            u.last_name AS decision_by_last_name
        FROM student_required_actions sra
        LEFT JOIN users u
            ON u.id = sra.decision_by_user_id
        WHERE sra.user_id = ?
          AND sra.cohort_id = ?
          AND sra.lesson_id = ?
          AND sra.action_type = 'instructor_approval'
          AND sra.status = 'approved'
          AND sra.decision_at IS NOT NULL
        ORDER BY sra.decision_at DESC, sra.id DESC
    ");
    $stmt->execute(array($userId, $cohortId, $lessonId));
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
}



function ia_human_completion_status(string $status): array
{
    return match ($status) {
        'instructor_required' => array('label' => 'Instructor Required', 'class' => 'warning'),
        'remediation_required' => array('label' => 'Remediation Required', 'class' => 'warning'),
        'training_suspended' => array('label' => 'Training Suspended', 'class' => 'danger'),
        'deadline_blocked' => array('label' => 'Deadline Blocked', 'class' => 'danger'),
        'summary_required' => array('label' => 'Summary Required', 'class' => 'warning'),
        'awaiting_summary_review' => array('label' => 'Awaiting Summary Review', 'class' => 'info'),
        'awaiting_test_completion' => array('label' => 'Awaiting Test Completion', 'class' => 'info'),
        'completed' => array('label' => 'Completed', 'class' => 'ok'),
        'in_progress' => array('label' => 'In Progress', 'class' => 'info'),
        default => array('label' => $status !== '' ? ucwords(str_replace('_', ' ', $status)) : '—', 'class' => 'info'),
    };
}

function ia_load_state(CoursewareProgressionV2 $engine, string $token): array
{
    $state = $engine->getInstructorApprovalPageStateByToken($token);

    if (!$state || empty($state['action'])) {
        http_response_code(404);
        exit('Approval action not found');
    }

    $action = (array)$state['action'];

    if ((string)($action['action_type'] ?? '') !== 'instructor_approval') {
        http_response_code(400);
        exit('Invalid action type');
    }

    if (isset($state['access']) && is_array($state['access']) && array_key_exists('is_allowed', $state['access'])) {
        if (empty($state['access']['is_allowed'])) {
            http_response_code(403);
            exit('Forbidden');
        }
    }

    return $state;
}

function ia_collect_attempt_history(PDO $pdo, int $userId, int $cohortId, int $lessonId): array
{
    $stmt = $pdo->prepare("
        SELECT
            pt.id,
            pt.user_id,
            pt.cohort_id,
            pt.lesson_id,
            pt.attempt,
            pt.status,
            pt.score_pct,
            pt.ai_summary,
            pt.weak_areas,
            pt.debrief_spoken,
            pt.summary_quality,
            pt.summary_issues,
            pt.summary_corrections,
            pt.confirmed_misunderstandings,
            pt.started_at,
            pt.completed_at,
            pt.effective_deadline_utc,
            pt.deadline_source,
            pt.timing_status,
            pt.formal_result_code,
            pt.formal_result_label,
            pt.pass_gate_met,
            pt.counts_as_unsat,
            pt.finalized_by_logic_version,
            pt.created_at,
            pt.updated_at,
            l.title AS lesson_title
        FROM progress_tests_v2 pt
        LEFT JOIN lessons l
            ON l.id = pt.lesson_id
        WHERE pt.user_id = ?
          AND pt.cohort_id = ?
          AND pt.lesson_id = ?
        ORDER BY pt.attempt DESC, pt.id DESC
    ");
    $stmt->execute(array($userId, $cohortId, $lessonId));
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
}

function ia_collect_attempt_items(PDO $pdo, array $attemptIds): array
{
    if (!$attemptIds) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($attemptIds), '?'));

    $stmt = $pdo->prepare("
        SELECT
            pti.id,
            pti.test_id,
            pti.idx,
            pti.kind,
            pti.prompt,
            pti.options_json,
            pti.correct_json,
            pti.transcript_text,
            pti.audio_path,
            pti.is_correct,
            pti.score_points,
            pti.max_points,
            pti.created_at,
            pti.updated_at,

            ov.id AS override_id,
            ov.overridden_by_user_id,
            ov.original_is_correct,
            ov.original_score_points,
            ov.original_max_points,
            ov.override_is_correct,
            ov.override_score_points,
            ov.override_reason,
            ov.created_at AS override_created_at
        FROM progress_test_items_v2 pti
        LEFT JOIN progress_test_item_score_overrides ov
            ON ov.id = (
                SELECT ov2.id
                FROM progress_test_item_score_overrides ov2
                WHERE ov2.progress_test_item_id = pti.id
                ORDER BY ov2.id DESC
                LIMIT 1
            )
        WHERE pti.test_id IN ($placeholders)
        ORDER BY pti.test_id DESC, pti.idx ASC, pti.id ASC
    ");
    $stmt->execute($attemptIds);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    $grouped = array();

    foreach ($rows as $row) {
        $testId = (int)($row['test_id'] ?? 0);
        if (!isset($grouped[$testId])) {
            $grouped[$testId] = array();
        }
        $grouped[$testId][] = $row;
    }

    return $grouped;
}

function ia_collect_official_references(PDO $pdo, int $lessonId): array
{
    $sqlVariants = array(
        "
            SELECT
                sr.ref_type,
                sr.ref_code,
                sr.ref_title,
                sr.notes,
                sr.ref_detail,
                sr.confidence,
                s.id AS slide_id,
                s.title AS slide_title
            FROM slide_references sr
            INNER JOIN slides s
                ON s.id = sr.slide_id
            WHERE s.lesson_id = ?
            ORDER BY
                FIELD(sr.ref_type, 'ACS', 'PHAK', 'FAR_AIM', 'EASA'),
                sr.confidence DESC,
                s.id ASC,
                sr.id ASC
        ",
        "
            SELECT
                sr.ref_type,
                sr.ref_code,
                sr.ref_title,
                sr.notes,
                sr.ref_detail,
                sr.confidence,
                s.id AS slide_id,
                s.title AS slide_title
            FROM slide_references sr
            INNER JOIN slides s
                ON s.id = sr.slide_id
            INNER JOIN slide_enrichment se
                ON se.slide_id = s.id
            WHERE se.lesson_id = ?
            ORDER BY
                FIELD(sr.ref_type, 'ACS', 'PHAK', 'FAR_AIM', 'EASA'),
                sr.confidence DESC,
                s.id ASC,
                sr.id ASC
        "
    );

    foreach ($sqlVariants as $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array($lessonId));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
            if ($rows) {
                return $rows;
            }
        } catch (Throwable $e) {
        }
    }

    return array();
}

function ia_build_audio_url(string $audioPath): string
{
    $audioPath = trim($audioPath);
    if ($audioPath === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $audioPath)) {
        return $audioPath;
    }

    $base = 'https://ipca-media.nyc3.cdn.digitaloceanspaces.com/';
    return $base . ltrim($audioPath, '/');
}

function ia_build_difficulty_lines(array $attempts, array $attemptItems): array
{
    $weakKeywordCounts = array();
    $failedPromptCounts = array();

    foreach ($attempts as $attempt) {
        $testId = (int)($attempt['id'] ?? 0);
        $weakAreas = trim((string)($attempt['weak_areas'] ?? ''));

        if ($weakAreas !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $weakAreas) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $line = preg_replace('/^[\-\*\•\d\.\)\s]+/u', '', $line);
                $line = trim((string)$line);
                if ($line === '') {
                    continue;
                }

                if (!isset($weakKeywordCounts[$line])) {
                    $weakKeywordCounts[$line] = 0;
                }
                $weakKeywordCounts[$line]++;
            }
        }

        foreach ((array)($attemptItems[$testId] ?? array()) as $item) {
            $effectiveCorrect = null;

            if (isset($item['override_id']) && $item['override_id'] !== null) {
                $effectiveCorrect = (int)($item['override_is_correct'] ?? 0);
            } elseif (isset($item['is_correct']) && $item['is_correct'] !== null) {
                $effectiveCorrect = (int)$item['is_correct'];
            }

            if ($effectiveCorrect === 0) {
                $prompt = trim((string)($item['prompt'] ?? ''));
                if ($prompt !== '') {
                    if (!isset($failedPromptCounts[$prompt])) {
                        $failedPromptCounts[$prompt] = 0;
                    }
                    $failedPromptCounts[$prompt]++;
                }
            }
        }
    }

    arsort($weakKeywordCounts);
    arsort($failedPromptCounts);

    $out = array();

    foreach ($weakKeywordCounts as $line => $count) {
        $out[] = $count > 1
            ? 'Repeated core gap: ' . $line . ' (' . $count . ' times)'
            : 'Core gap: ' . $line;
        if (count($out) >= 3) {
            break;
        }
    }

    if (count($out) < 5) {
        foreach ($failedPromptCounts as $prompt => $count) {
            $out[] = 'Weak oral performance area: ' . $prompt . ($count > 1 ? ' (' . $count . ' times)' : '');
            if (count($out) >= 5) {
                break;
            }
        }
    }

    return $out;
}

function ia_group_references(array $references): array
{
    $grouped = array(
        'ACS' => array(),
        'PHAK' => array(),
        'FAR_AIM' => array(),
        'EASA' => array(),
        'OTHER' => array(),
    );

    foreach ($references as $ref) {
        $type = trim((string)($ref['ref_type'] ?? ''));
        if (!isset($grouped[$type])) {
            $type = 'OTHER';
        }
        $grouped[$type][] = $ref;
    }

    return $grouped;
}

$state = ia_load_state($engine, $token);
$action = (array)$state['action'];
$activity = (array)($state['activity'] ?? array());
$progressionContext = (array)($state['progression_context'] ?? array());
$latestProgressTest = (array)($state['latest_progress_test'] ?? array());

try {
    $engine->markInstructorApprovalPageOpened(
        (int)$action['id'],
        $ipAddress,
        $userAgent
    );
} catch (Throwable $e) {
    error_log('markInstructorApprovalPageOpened failed: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $postAction = trim((string)($_POST['page_action'] ?? ''));

        if ($postAction === 'save_summary_notes') {
            $reviewNotes = trim((string)($_POST['review_notes_by_instructor'] ?? ''));

            $stmt = $pdo->prepare("
                UPDATE lesson_summaries
                SET
                    review_notes_by_instructor = ?,
                    updated_at = NOW()
                WHERE user_id = ?
                  AND cohort_id = ?
                  AND lesson_id = ?
                LIMIT 1
            ");
            $stmt->execute(array(
                $reviewNotes,
                (int)$action['user_id'],
                (int)$action['cohort_id'],
                (int)$action['lesson_id'],
            ));

            $engine->logProgressionEvent(array(
                'user_id' => (int)$action['user_id'],
                'cohort_id' => (int)$action['cohort_id'],
                'lesson_id' => (int)$action['lesson_id'],
                'progress_test_id' => isset($action['progress_test_id']) ? (int)$action['progress_test_id'] : null,
                'event_type' => 'instructor_intervention',
                'event_code' => 'instructor_summary_notes_saved',
                'event_status' => 'info',
                'actor_type' => 'admin',
                'actor_user_id' => $currentUserId,
                'event_time' => gmdate('Y-m-d H:i:s'),
                'payload' => array(
                    'required_action_id' => (int)$action['id'],
                    'review_notes_length' => strlen($reviewNotes),
                ),
                'legal_note' => 'Instructor summary notes saved from instructor intervention page.',
            ));

            $flashSuccess = 'Instructor summary notes saved successfully.';
        } elseif ($postAction === 'record_decision') {
            $uiDecision = trim((string)($_POST['decision_code'] ?? ''));
            $decisionNotes = trim((string)($_POST['decision_notes'] ?? ''));
            $grantedExtraAttempts = max(0, (int)($_POST['granted_extra_attempts'] ?? 0));

            $payload = array(
                'decision_code' => $uiDecision,
                'granted_extra_attempts' => $grantedExtraAttempts,
                'summary_revision_required' => 0,
                'one_on_one_required' => 0,
                'training_suspended' => 0,
                'major_intervention_flag' => 0,
                'decision_notes' => $decisionNotes,
            );

            $result = $engine->processInstructorApprovalDecision(
                (int)$action['id'],
                $payload,
                $currentUserId,
                $ipAddress,
                $userAgent
            );

            $flashSuccess = trim((string)($result['message'] ?? 'Instructor decision recorded successfully.'));
        } elseif ($postAction === 'mark_one_on_one_completed') {
            $result = $engine->markInstructorApprovalOneOnOneCompleted(
                (int)$action['id'],
                $currentUserId,
                $ipAddress,
                $userAgent
            );

            $flashSuccess = trim((string)($result['message'] ?? 'Required one-on-one session marked completed.'));
        } else {
            throw new RuntimeException('Unknown action.');
        }

        $state = ia_load_state($engine, $token);
        $action = (array)$state['action'];
        $activity = (array)($state['activity'] ?? array());
        $progressionContext = (array)($state['progression_context'] ?? array());
        $latestProgressTest = (array)($state['latest_progress_test'] ?? array());
    } catch (Throwable $e) {
        $flashError = $e->getMessage();
        $state = ia_load_state($engine, $token);
        $action = (array)$state['action'];
        $activity = (array)($state['activity'] ?? array());
        $progressionContext = (array)($state['progression_context'] ?? array());
        $latestProgressTest = (array)($state['latest_progress_test'] ?? array());
    }
}

$studentUserId = (int)($action['user_id'] ?? 0);
$cohortId = (int)($action['cohort_id'] ?? 0);
$lessonId = (int)($action['lesson_id'] ?? 0);

$studentStmt = $pdo->prepare("
    SELECT id, name, first_name, last_name, email, photo_path, role
    FROM users
    WHERE id = ?
    LIMIT 1
");
$studentStmt->execute(array($studentUserId));
$studentUser = $studentStmt->fetch(PDO::FETCH_ASSOC) ?: array();

$studentName = trim((string)($studentUser['name'] ?? ''));
if ($studentName === '') {
    $studentName = trim((string)($studentUser['first_name'] ?? '') . ' ' . (string)($studentUser['last_name'] ?? ''));
}
if ($studentName === '') {
    $studentName = 'Student #' . $studentUserId;
}

$chiefUser = array();
try {
    $chiefRecipient = $engine->getChiefInstructorRecipient(array('cohort_id' => $cohortId));
    if ($chiefRecipient && !empty($chiefRecipient['user_id'])) {
        $chiefStmt = $pdo->prepare("
            SELECT id, name, first_name, last_name, email, photo_path, role
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $chiefStmt->execute(array((int)$chiefRecipient['user_id']));
        $chiefUser = $chiefStmt->fetch(PDO::FETCH_ASSOC) ?: array();
    }
} catch (Throwable $e) {
    $chiefUser = array();
}

$chiefName = trim((string)($chiefUser['name'] ?? ''));
if ($chiefName === '') {
    $chiefName = trim((string)($chiefUser['first_name'] ?? '') . ' ' . (string)($chiefUser['last_name'] ?? ''));
}
if ($chiefName === '') {
    $chiefName = 'Chief Instructor';
}

$lessonSummary = array();
try {
    $summaryStmt = $pdo->prepare("
        SELECT *
        FROM lesson_summaries
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id = ?
        LIMIT 1
    ");
    $summaryStmt->execute(array($studentUserId, $cohortId, $lessonId));
    $lessonSummary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: array();
} catch (Throwable $e) {
    $lessonSummary = array();
}

$attemptHistory = ia_collect_attempt_history($pdo, $studentUserId, $cohortId, $lessonId);
$attemptIds = array_values(array_filter(array_map(
    static fn(array $row): int => (int)($row['id'] ?? 0),
    $attemptHistory
)));
$attemptItems = ia_collect_attempt_items($pdo, $attemptIds);
$officialReferences = ia_collect_official_references($pdo, $lessonId);
$groupedReferences = ia_group_references($officialReferences);
$difficultyLines = ia_build_difficulty_lines($attemptHistory, $attemptItems);
$interventionHistory = ia_collect_instructor_interventions($pdo, $studentUserId, $cohortId, $lessonId);

$summaryScore = isset($lessonSummary['review_score']) && $lessonSummary['review_score'] !== null
    ? (float)$lessonSummary['review_score']
    : null;
$summaryStatus = trim((string)($lessonSummary['review_status'] ?? ''));

$latestScore = isset($latestProgressTest['score_pct']) && $latestProgressTest['score_pct'] !== null
    ? (float)$latestProgressTest['score_pct']
    : null;
$latestResultCode = trim((string)($latestProgressTest['formal_result_code'] ?? ''));
$latestResultLabel = trim((string)($latestProgressTest['formal_result_label'] ?? ''));

$attemptCount = count($attemptHistory);
$bestScore = null;
foreach ($attemptHistory as $attemptRow) {
    if (isset($attemptRow['score_pct']) && $attemptRow['score_pct'] !== null) {
        $score = (float)$attemptRow['score_pct'];
        if ($bestScore === null || $score > $bestScore) {
            $bestScore = $score;
        }
    }
}

$decisionOptions = ia_decision_ui_options();
$completionStatusUi = ia_human_completion_status((string)($activity['completion_status'] ?? ''));

cw_header('Instructor Intervention');
?>

<style>
.ia-page{display:flex;flex-direction:column;gap:18px}
.ia-flash{padding:14px 16px;border-radius:14px;font-size:14px;font-weight:700}
.ia-flash.success{background:rgba(22,101,52,.09);color:#166534;border:1px solid rgba(22,101,52,.18)}
.ia-flash.error{background:rgba(153,27,27,.08);color:#991b1b;border:1px solid rgba(153,27,27,.16)}
.ia-hero{padding:22px 24px}
.ia-eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.14em;color:#64748b;font-weight:800;margin-bottom:8px}
.ia-title{margin:0;font-size:32px;line-height:1.05;letter-spacing:-.04em;color:#102845}
.ia-chip-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.ia-chip{
    display:inline-flex;align-items:center;justify-content:center;min-height:30px;
    padding:0 10px;border-radius:999px;font-size:11px;font-weight:800;
    border:1px solid rgba(15,23,42,.08);background:#f8fafc;color:#334155;
}
.ia-chip.ok{background:#ecfdf5;color:#166534;border-color:#bbf7d0}
.ia-chip.warning{background:#fff7ed;color:#c2410c;border-color:#fdba74}
.ia-chip.danger{background:#fee2e2;color:#991b1b;border-color:#fca5a5}
.ia-chip.info{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}

.ia-grid{display:grid;grid-template-columns:1.2fr .95fr;gap:18px}
.ia-card{padding:20px 22px}
.ia-student-top{display:grid;grid-template-columns:1.3fr 1fr;gap:18px;align-items:start}
.ia-person{display:flex;gap:14px;align-items:center;min-width:0}
.ip-avatar{width:84px;height:84px;border-radius:24px;overflow:hidden;flex:0 0 84px;background:linear-gradient(180deg,#e8eef7 0%,#dfe7f2 100%);border:1px solid rgba(15,23,42,0.07);display:flex;align-items:center;justify-content:center;box-shadow:inset 0 1px 0 rgba(255,255,255,0.45)}
.ip-avatar img{width:100%;height:100%;object-fit:cover;display:block}
.ip-avatar-fallback{width:34px;height:34px;color:#7b8aa0}
.ip-avatar-fallback svg{width:100%;height:100%;display:block}
.ia-person-copy{min-width:0}
.ia-person-role{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#64748b}
.ia-person-name{margin-top:4px;font-size:24px;line-height:1.05;letter-spacing:-.03em;font-weight:820;color:#102845}
.ia-person-sub{margin-top:8px;font-size:13px;line-height:1.55;color:#64748b}
.ia-chief-card{display:flex;gap:12px;align-items:center;padding:12px 14px;border-radius:18px;background:linear-gradient(180deg,#f8fbff 0%,#f3f7fd 100%);border:1px solid rgba(18,53,95,.08)}
.ia-chief-copy{min-width:0}
.ia-chief-label{font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#64748b}
.ia-chief-name{margin-top:4px;font-size:15px;font-weight:800;color:#102845}
.ia-chief-sub{margin-top:3px;font-size:12px;color:#64748b}
.ia-section-title{margin:0 0 12px 0;font-size:18px;font-weight:820;color:#102845}
.ia-kv-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.ia-kv{padding:14px 14px;border-radius:16px;border:1px solid rgba(15,23,42,.07);background:#fff}
.ia-kv-label{font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:#64748b;font-weight:800}
.ia-kv-value{margin-top:8px;font-size:20px;font-weight:820;color:#102845;letter-spacing:-.03em}
.ia-progress-row{display:flex;flex-direction:column;gap:7px}
.ia-inline-bar{display:flex;align-items:center;gap:12px}
.ia-inline-bar .ia-progress-value{min-width:52px;text-align:right;font-size:14px;font-weight:900;color:#102845}
.ia-track{flex:1 1 auto;height:14px;border-radius:999px;overflow:hidden;background:#e7edf5}
.ia-fill{height:100%;border-radius:999px}
.ia-fill.good{background:linear-gradient(90deg,#166534 0%,#22c55e 100%)}
.ia-fill.amber{background:linear-gradient(90deg,#c2410c 0%,#f59e0b 100%)}
.ia-fill.danger{background:linear-gradient(90deg,#991b1b 0%,#ef4444 100%)}
.ia-fill.neutral{background:linear-gradient(90deg,#64748b 0%,#cbd5e1 100%)}
.ia-small-status{
    display:inline-flex;align-items:center;justify-content:center;min-height:22px;padding:0 8px;
    border-radius:999px;font-size:10px;font-weight:800;border:1px solid rgba(15,23,42,.08);white-space:nowrap;
}
.ia-small-status.ok{background:#ecfdf5;color:#166534;border-color:#bbf7d0}
.ia-small-status.warning{background:#fff7ed;color:#c2410c;border-color:#fdba74}
.ia-small-status.danger{background:#fee2e2;color:#991b1b;border-color:#fca5a5}
.ia-small-status.info{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.ia-note-list{display:grid;gap:8px}
.ia-note-row{display:flex;gap:8px;align-items:flex-start;font-size:12px;line-height:1.4;color:#0f172a}
.ia-note-dot{width:4px;height:4px;border-radius:999px;background:#0f172a;flex:0 0 4px;margin-top:6px}
.ia-reference-groups{display:grid;gap:12px}
.ia-reference-group{padding:14px;border-radius:16px;border:1px solid rgba(15,23,42,.07);background:#fff}
.ia-reference-head{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:10px}
.ia-reference-title{font-size:13px;font-weight:820;color:#102845}
.ia-reference-list{display:flex;flex-wrap:wrap;gap:8px}
.ia-ref-pill{
    display:inline-flex;align-items:center;gap:8px;min-height:30px;padding:0 10px;border-radius:999px;
    background:#f8fafc;border:1px solid rgba(15,23,42,.08);font-size:11px;font-weight:800;color:#334155;
}
.ia-ref-copy{
    display:inline-flex;align-items:center;justify-content:center;min-height:28px;padding:0 10px;border-radius:10px;
    border:1px solid rgba(15,23,42,.10);background:#fff;color:#102845;font-size:11px;font-weight:800;cursor:pointer;
}
.ia-actions-card{padding:20px 22px}
.ia-help-list{display:grid;gap:8px;margin:12px 0 16px 0}
.ia-help-item{display:flex;gap:9px;align-items:flex-start;font-size:12px;line-height:1.45;color:#334155}
.ia-help-dot{width:5px;height:5px;border-radius:999px;background:#12355f;flex:0 0 5px;margin-top:6px}
.ia-form-grid{display:grid;grid-template-columns:1fr 220px;gap:14px}
.ia-field{display:flex;flex-direction:column;gap:7px}
.ia-label{font-size:13px;font-weight:800;color:#102845}
.ia-input,.ia-select,.ia-textarea{
    width:100%;box-sizing:border-box;border:1px solid rgba(15,23,42,.12);
    border-radius:12px;padding:10px 12px;background:#fff;color:#102845;font:inherit;
}
.ia-select,.ia-input{height:46px}
.ia-textarea{min-height:110px;resize:vertical}
.ia-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.ia-btn{
    display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 14px;
    border-radius:10px;border:1px solid #12355f;background:#12355f;color:#fff;
    font-size:13px;font-weight:800;cursor:pointer;text-decoration:none;
}
.ia-btn.secondary{background:#fff;color:#12355f}
.ia-attempt-table-wrap{overflow:auto}
.ia-attempt-table{width:100%;border-collapse:collapse}
.ia-attempt-table th,.ia-attempt-table td{padding:12px 10px;border-bottom:1px solid rgba(15,23,42,.06);vertical-align:middle}
.ia-attempt-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:#64748b;font-weight:800}
.ia-table-actions{display:flex;gap:8px;flex-wrap:wrap}
.ia-link-btn{
    display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:0 12px;border-radius:10px;
    border:1px solid rgba(15,23,42,.10);background:#fff;color:#102845;font-size:12px;font-weight:800;text-decoration:none;cursor:pointer;
}
.ia-summary-preview{max-height:220px;overflow:auto;padding:14px;border-radius:16px;border:1px solid rgba(15,23,42,.07);background:#fff}
.ia-modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:28px;background:rgba(15,23,42,.55);z-index:1000}
.ia-modal.is-open{display:flex}
.ia-modal-card{
    width:min(1080px,96vw);max-height:90vh;overflow:auto;background:#fff;border-radius:22px;
    border:1px solid rgba(15,23,42,.10);box-shadow:0 30px 80px rgba(15,23,42,.28);
}
.ia-modal-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:18px 20px;border-bottom:1px solid rgba(15,23,42,.06)}
.ia-modal-title{margin:0;font-size:20px;font-weight:820;color:#102845}
.ia-modal-sub{margin-top:6px;font-size:13px;line-height:1.5;color:#64748b}
.ia-modal-body{padding:18px 20px}
.ia-close{
    display:inline-flex;align-items:center;justify-content:center;min-width:38px;height:38px;border-radius:10px;
    border:1px solid rgba(15,23,42,.10);background:#fff;color:#102845;font-size:20px;cursor:pointer;
}
.ia-qa-card{padding:14px;border-radius:16px;border:1px solid rgba(15,23,42,.07);background:#fff;margin-bottom:12px}
.ia-qa-q{font-size:13px;font-weight:820;color:#102845;line-height:1.45}
.ia-qa-a{margin-top:10px;font-size:13px;line-height:1.6;color:#334155;white-space:pre-wrap}
.ia-audio-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px}
.ia-override-box{margin-top:12px;padding:12px;border-radius:14px;background:#f8fafc;border:1px solid rgba(15,23,42,.06)}
.ia-override-title{font-size:12px;font-weight:820;color:#102845;margin-bottom:8px}
.ia-override-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.ia-override-meta{margin-top:10px;padding:10px 12px;border-radius:12px;background:#fff;border:1px solid rgba(15,23,42,.06);font-size:12px;line-height:1.5;color:#334155}
.ia-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.ia-detail-box{padding:12px 14px;border-radius:14px;border:1px solid rgba(15,23,42,.06);background:#fff}
.ia-detail-label{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#64748b;font-weight:800}
.ia-detail-value{margin-top:6px;font-size:13px;line-height:1.55;color:#102845;font-weight:700}	
	
@media (max-width: 1220px){
    .ia-grid{grid-template-columns:1fr}
}
@media (max-width: 980px){
    .ia-student-top{grid-template-columns:1fr}
}
@media (max-width: 760px){
    .ia-kv-grid{grid-template-columns:1fr}
    .ia-form-grid{grid-template-columns:1fr}
    .ia-override-grid{grid-template-columns:1fr}
    .ia-detail-grid{grid-template-columns:1fr}
}
</style>

<div class="ia-page">

    <?php if ($flashError !== ''): ?>
        <div class="ia-flash error"><?php echo ia_h($flashError); ?></div>
    <?php endif; ?>

    <?php if ($flashSuccess !== ''): ?>
        <div class="ia-flash success"><?php echo ia_h($flashSuccess); ?></div>
    <?php endif; ?>

    <section class="card ia-hero">
        <div class="ia-eyebrow">Instructor Platform · Theory Intervention</div>
        <h1 class="ia-title">Instructor Intervention</h1>

        <div class="ia-chip-row">
            <span class="ia-chip info"><?php echo ia_h((string)($state['cohort_title'] ?? '')); ?></span>
            <span class="ia-chip info"><?php echo ia_h((string)($state['lesson_title'] ?? '')); ?></span>
            <span class="ia-chip <?php echo ia_h((string)($action['status'] ?? '') === 'approved' ? 'ok' : 'warning'); ?>">
                Action: <?php echo ia_h((string)($action['status'] ?? '')); ?>
            </span>
            <?php if (!empty($activity['training_suspended'])): ?>
                <span class="ia-chip danger">Training Suspended</span>
            <?php endif; ?>
            <?php if (!empty($activity['one_on_one_required']) && empty($activity['one_on_one_completed'])): ?>
                <span class="ia-chip warning">One-on-One Required</span>
            <?php endif; ?>
        </div>
    </section>

    <div class="ia-grid">

        <div style="display:flex;flex-direction:column;gap:18px;">

            <section class="card ia-card">
                <div class="ia-student-top">
                    <div class="ia-person">
                        <?php echo ia_avatar_html($studentUser, $studentName, 84); ?>
                        <div class="ia-person-copy">
                            <div class="ia-person-role">Student</div>
                            <div class="ia-person-name"><?php echo ia_h($studentName); ?></div>
                            <div class="ia-person-sub">
                                Lesson: <strong><?php echo ia_h((string)($state['lesson_title'] ?? '')); ?></strong><br>
                                Cohort: <strong><?php echo ia_h((string)($state['cohort_title'] ?? '')); ?></strong><br>
                                Email: <strong><?php echo ia_h((string)($studentUser['email'] ?? '—')); ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="ia-chief-card">
                        <?php echo ia_avatar_html($chiefUser, $chiefName, 56); ?>
                        <div class="ia-chief-copy">
                            <div class="ia-chief-label">Chief Instructor</div>
                            <div class="ia-chief-name"><?php echo ia_h($chiefName); ?></div>
                            <div class="ia-chief-sub"><?php echo ia_h((string)($chiefUser['email'] ?? 'Configured from policy')); ?></div>
                        </div>
                    </div>
                </div>

                <div style="margin-top:18px;">
                    <div class="ia-section-title">Current Operational State</div>
                    <div class="ia-kv-grid">
                        <div class="ia-kv">
                            <div class="ia-kv-label">Attempt Count</div>
                            <div class="ia-kv-value"><?php echo $attemptCount; ?></div>
                        </div>

                        <div class="ia-kv">
                            <div class="ia-kv-label">Best Score</div>
                            <div class="ia-kv-value">
                                <?php if ($bestScore !== null): ?>
                                    <div class="ia-progress-row">
                                        <div class="ia-inline-bar">
                                            <div class="ia-progress-value"><?php echo ia_h((string)ia_percent_clamped($bestScore)); ?>%</div>
                                            <div class="ia-track">
                                                <div class="ia-fill <?php echo ia_h(ia_bar_class($bestScore)); ?>" style="width:<?php echo (int)ia_percent_clamped($bestScore); ?>%;"></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="ia-kv">
                            <div class="ia-kv-label">Latest Result</div>
                            <div class="ia-kv-value">
                                <?php if ($latestScore !== null): ?>
                                    <div class="ia-progress-row">
                                        <div class="ia-inline-bar">
                                            <div class="ia-progress-value"><?php echo ia_h((string)ia_percent_clamped($latestScore)); ?>%</div>
                                            <div class="ia-track">
                                                <div class="ia-fill <?php echo ia_h(ia_bar_class($latestScore)); ?>" style="width:<?php echo (int)ia_percent_clamped($latestScore); ?>%;"></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="ia-kv">
                            <div class="ia-kv-label">Latest Summary</div>
                            <div class="ia-kv-value">
                                <?php if ($summaryScore !== null): ?>
                                    <div class="ia-progress-row">
                                        <div class="ia-inline-bar">
                                            <div class="ia-progress-value"><?php echo ia_h((string)ia_percent_clamped($summaryScore)); ?>%</div>
                                            <div class="ia-track">
                                                <div class="ia-fill <?php echo ia_h(ia_bar_class($summaryScore)); ?>" style="width:<?php echo (int)ia_percent_clamped($summaryScore); ?>%;"></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php elseif ($summaryStatus !== ''): ?>
                                    <span class="ia-small-status <?php echo ia_h(ia_summary_status_class($summaryStatus)); ?>">
                                        <?php echo ia_h(ia_summary_status_label($summaryStatus)); ?>
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="ia-kv">
                            <div class="ia-kv-label">Completion Status</div>
                            <div class="ia-kv-value">
                                <span class="ia-small-status <?php echo ia_h((string)$completionStatusUi['class']); ?>">
                                    <?php echo ia_h((string)$completionStatusUi['label']); ?>
                                </span>
                            </div>
                        </div>

                        <div class="ia-kv">
                            <div class="ia-kv-label">Latest Activity</div>
                            <div class="ia-kv-value">
                                <span class="ia-small-status info"><?php echo ia_h(ia_format_datetime_utc((string)($latestProgressTest['completed_at'] ?? $latestProgressTest['updated_at'] ?? ''))); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card ia-card">
                <div class="ia-section-title">Areas of Difficulty</div>
                <?php if (!$difficultyLines): ?>
                    <div class="ia-note-list">
                        <div class="ia-note-row">
                            <span class="ia-note-dot"></span>
                            <span>No consolidated core difficulty lines were found yet.</span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="ia-note-list">
                        <?php foreach ($difficultyLines as $line): ?>
                            <div class="ia-note-row">
                                <span class="ia-note-dot"></span>
                                <span><?php echo ia_h($line); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card ia-card">
                <div class="ia-section-title">Official References Requiring Attention</div>

                <?php if (!$officialReferences): ?>
                    <div class="ia-note-list">
                        <div class="ia-note-row">
                            <span class="ia-note-dot"></span>
                            <span>No official reference rows were found for this lesson.</span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="ia-reference-groups">
                        <?php foreach ($groupedReferences as $groupName => $rows): ?>
                            <?php if (!$rows): continue; endif; ?>
                            <div class="ia-reference-group">
                                <div class="ia-reference-head">
                                    <div class="ia-reference-title"><?php echo ia_h($groupName); ?></div>
                                </div>
                                <div class="ia-reference-list">
                                    <?php foreach ($rows as $ref): ?>
                                        <?php
                                        $copyText = trim((string)($ref['ref_type'] ?? ''))
                                            . ' | '
                                            . trim((string)($ref['ref_code'] ?? ''))
                                            . ' | '
                                            . trim((string)($ref['ref_title'] ?? ''))
                                            . ' | Slide '
                                            . (int)($ref['slide_id'] ?? 0)
                                            . ' | '
                                            . trim((string)($ref['slide_title'] ?? ''));
                                        ?>
                                        <span class="ia-ref-pill">
                                            <?php echo ia_h(trim((string)($ref['ref_code'] ?? '')) !== '' ? (string)$ref['ref_code'] : $groupName); ?>
                                            <?php if (trim((string)($ref['ref_title'] ?? '')) !== ''): ?>
                                                · <?php echo ia_h((string)$ref['ref_title']); ?>
                                            <?php endif; ?>
                                            <?php if (trim((string)($ref['slide_title'] ?? '')) !== ''): ?>
                                                · Slide: <?php echo ia_h((string)$ref['slide_title']); ?>
                                            <?php endif; ?>
                                            <button type="button" class="ia-ref-copy" data-copy="<?php echo ia_h($copyText); ?>">Copy</button>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card ia-card">
                <div class="ia-section-title">Recent Attempt Summary</div>
                <div class="ia-attempt-table-wrap">
                    <table class="ia-attempt-table">
                        <thead>
                            <tr>
                                <th style="width:14%;">Attempt</th>
                                <th style="width:34%;">Score</th>
                                <th style="width:26%;">Result</th>
                                <th style="width:26%;">Button</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$attemptHistory): ?>
                                <tr>
                                    <td colspan="4" style="color:#64748b;">No attempts found yet for this lesson.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($attemptHistory as $attempt): ?>
                                    <?php
                                    $attemptId = (int)($attempt['id'] ?? 0);
                                    $attemptScore = isset($attempt['score_pct']) && $attempt['score_pct'] !== null
                                        ? (float)$attempt['score_pct']
                                        : null;
                                    $attemptResultCode = trim((string)($attempt['formal_result_code'] ?? ''));
                                    $attemptResultLabel = trim((string)($attempt['formal_result_label'] ?? ''));
                                    ?>
                                    <tr>
                                        <td style="font-size:14px;font-weight:900;color:#102845;"><?php echo (int)($attempt['attempt'] ?? 0); ?></td>
                                        <td>
                                            <?php if ($attemptScore !== null): ?>
                                                <div class="ia-inline-bar">
                                                    <div class="ia-progress-value"><?php echo ia_h((string)ia_percent_clamped($attemptScore)); ?>%</div>
                                                    <div class="ia-track">
                                                        <div class="ia-fill <?php echo ia_h(ia_bar_class($attemptScore)); ?>" style="width:<?php echo (int)ia_percent_clamped($attemptScore); ?>%;"></div>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="ia-small-status <?php echo ia_h(ia_result_class($attemptResultCode)); ?>">
                                                <?php echo ia_h(ia_result_label($attemptResultCode, $attemptResultLabel)); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="ia-table-actions">
                                                <button type="button" class="ia-link-btn" data-open-modal="attempt-modal-<?php echo $attemptId; ?>">
                                                    Test Details
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        </div>

        <div style="display:flex;flex-direction:column;gap:18px;">

            <section class="card ia-actions-card">
                <div class="ia-section-title">Action State</div>

                <div class="ia-chip-row" style="margin-top:0;">
                    <span class="ia-chip <?php echo (string)($action['status'] ?? '') === 'approved' ? 'ok' : 'warning'; ?>">
                        Action Status: <?php echo ia_h((string)($action['status'] ?? '—')); ?>
                    </span>
                    <?php if (!empty($action['decision_code'])): ?>
                        <span class="ia-chip info">Decision: <?php echo ia_h(ia_decision_code_label((string)$action['decision_code'])); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($activity['one_on_one_required']) && empty($activity['one_on_one_completed'])): ?>
                        <span class="ia-chip warning">One-on-One Pending</span>
                    <?php endif; ?>
                    <?php if (!empty($activity['one_on_one_completed'])): ?>
                        <span class="ia-chip ok">One-on-One Completed</span>
                    <?php endif; ?>
                    <?php if (!empty($action['granted_extra_attempts'])): ?>
                        <span class="ia-chip info">Granted Extra Attempts: <?php echo (int)$action['granted_extra_attempts']; ?></span>
                    <?php endif; ?>
                </div>

                <?php if (trim((string)($action['decision_notes'] ?? '')) !== ''): ?>
                    <div style="margin-top:14px;padding:14px;border-radius:16px;border:1px solid rgba(15,23,42,.06);background:#fff;">
                        <div class="ia-label" style="margin-bottom:8px;">Recorded Instructor Notes</div>
                        <div style="font-size:13px;line-height:1.6;color:#334155;white-space:pre-wrap;"><?php echo ia_h((string)$action['decision_notes']); ?></div>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card ia-actions-card">
                <div class="ia-section-title">Record Instructor Decision</div>

                <div class="ia-help-list">
                    <?php foreach ($decisionOptions as $key => $meta): ?>
                        <div class="ia-help-item">
                            <span class="ia-help-dot"></span>
                            <span><strong><?php echo ia_h((string)$meta['label']); ?></strong> — <?php echo ia_h((string)$meta['help']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ((string)($action['status'] ?? '') !== 'approved'): ?>
                    <form method="post" id="ia-decision-form">
                        <input type="hidden" name="page_action" value="record_decision">

                        <div class="ia-form-grid">
                            <div class="ia-field">
                                <label class="ia-label">Decision</label>
                                <select name="decision_code" class="ia-select" id="ia-decision-code" required>
                                    <option value="">Select a decision</option>
                                    <?php foreach ($decisionOptions as $key => $meta): ?>
                                        <option value="<?php echo ia_h($key); ?>"><?php echo ia_h((string)$meta['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ia-field" id="ia-extra-attempts-field" style="display:none;">
								<label class="ia-label">Extra Progress Test Attempts</label>
								<select class="ia-select" name="granted_extra_attempts" id="ia-granted-extra-attempts">
									<option value="">Select attempts</option>
									<option value="1">1 extra attempt</option>
									<option value="2">2 extra attempts</option>
									<option value="3">3 extra attempts</option>
									<option value="4">4 extra attempts</option>
									<option value="5">5 extra attempts</option>
								</select>
							</div>
                        </div>

                        <div id="ia-one-on-one-fields" style="display:none;margin-top:14px;">
                            <div class="ia-form-grid">
                                <div class="ia-field">
                                    <label class="ia-label">One-on-One Date</label>
                                    <input class="ia-input" type="date" name="one_on_one_date" id="ia-one-on-one-date">
                                </div>

                                <div class="ia-field">
                                    <label class="ia-label">Instructor</label>
                                    <select class="ia-select" name="one_on_one_instructor_user_id" id="ia-one-on-one-instructor">
                                        <option value="">Select instructor</option>
                                        <?php
                                        $instructorListStmt = $pdo->prepare("
                                            SELECT id, name, first_name, last_name
                                            FROM users
                                            WHERE role IN ('instructor','supervisor','chief_instructor','admin')
                                            ORDER BY
                                                COALESCE(NULLIF(name, ''), CONCAT(TRIM(COALESCE(first_name,'')), ' ', TRIM(COALESCE(last_name,'')))) ASC,
                                                id ASC
                                        ");
                                        $instructorListStmt->execute();
                                        $instructorList = $instructorListStmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
                                        foreach ($instructorList as $inst):
                                            $instName = trim((string)($inst['name'] ?? ''));
                                            if ($instName === '') {
                                                $instName = trim((string)($inst['first_name'] ?? '') . ' ' . (string)($inst['last_name'] ?? ''));
                                            }
                                            if ($instName === '') {
                                                $instName = 'User #' . (int)$inst['id'];
                                            }
                                        ?>
                                            <option value="<?php echo (int)$inst['id']; ?>"><?php echo ia_h($instName); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="ia-form-grid" style="margin-top:14px;">
                                <div class="ia-field">
                                    <label class="ia-label">Time From</label>
                                    <input class="ia-input" type="time" name="one_on_one_time_from" id="ia-one-on-one-time-from">
                                </div>

                                <div class="ia-field">
                                    <label class="ia-label">Time Until</label>
                                    <input class="ia-input" type="time" name="one_on_one_time_until" id="ia-one-on-one-time-until">
                                </div>
                            </div>
                        </div>
						

                        <div class="ia-field" style="margin-top:14px;">
                            <label class="ia-label">Decision Notes</label>
                            <textarea
								class="ia-textarea"
								name="decision_notes"
								id="ia-decision-notes"
								placeholder="Explain why this instructional decision is appropriate and what the student must do next."
								required
							></textarea>
                        </div>

                        <div class="ia-actions">
                            <button type="submit" class="ia-btn">Record Instructor Decision</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div style="font-size:13px;line-height:1.6;color:#64748b;">
                        This instructor approval action has already been approved. You can still review the historical data and mark the one-on-one completion if it is still pending.
                    </div>
                <?php endif; ?>
            </section>

            <?php if (
                (string)($action['status'] ?? '') === 'approved'
                && (int)($action['one_on_one_required'] ?? 0) === 1
                && (int)($activity['one_on_one_completed'] ?? 0) !== 1
                && (int)($action['training_suspended'] ?? 0) !== 1
            ): ?>
                <section class="card ia-actions-card">
                    <div class="ia-section-title">One-on-One Completion</div>
                    <div style="font-size:13px;line-height:1.6;color:#64748b;">
                        Use this only once the required instructor one-on-one session has actually been completed.
                    </div>

                    <form method="post" style="margin-top:14px;">
                        <input type="hidden" name="page_action" value="mark_one_on_one_completed">
                        <button type="submit" class="ia-btn">Mark Instructor Session Completed</button>
                    </form>
                </section>
            <?php endif; ?>

            <section class="card ia-actions-card">
                <div class="ia-section-title">Lesson Summary</div>

                <?php if (!$lessonSummary): ?>
                    <div style="font-size:13px;line-height:1.6;color:#64748b;">No lesson summary record found yet for this lesson.</div>
                <?php else: ?>
                    <div class="ia-chip-row" style="margin-top:0;">
                        <?php if ($summaryStatus !== ''): ?>
                            <span class="ia-chip <?php echo ia_h(ia_summary_status_class($summaryStatus)); ?>">
                                <?php echo ia_h(ia_summary_status_label($summaryStatus)); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($summaryScore !== null): ?>
                            <span class="ia-chip info">Review Score: <?php echo ia_h((string)ia_percent_clamped($summaryScore)); ?>%</span>
                        <?php endif; ?>
                    </div>

                    <div class="ia-summary-preview" style="margin-top:14px;">
                        <?php
                        $summaryHtml = trim((string)($lessonSummary['summary_html'] ?? ''));
                        if ($summaryHtml !== '') {
                            echo $summaryHtml;
                        } else {
                            echo nl2br(ia_h((string)($lessonSummary['summary_plain'] ?? 'No summary content available.')));
                        }
                        ?>
                    </div>

                    <div class="ia-actions">
                        <button type="button" class="ia-btn secondary" data-open-modal="summary-modal">Open Summary Viewer</button>
                    </div>
                <?php endif; ?>
            </section>

			
			            <section class="card ia-actions-card">
                <div class="ia-section-title">Instructor Interventions</div>

                <?php if (!$interventionHistory): ?>
                    <div style="font-size:13px;line-height:1.6;color:#64748b;">No approved instructor interventions found for this lesson yet.</div>
                <?php else: ?>
                    <div class="ia-attempt-table-wrap">
                        <table class="ia-attempt-table">
                            <thead>
                                <tr>
                                    <th style="width:34%;">Date</th>
                                    <th style="width:32%;">Decision</th>
                                    <th style="width:14%;">Attempts</th>
                                    <th style="width:20%;">Button</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($interventionHistory as $intervention): ?>
                                    <?php $interventionId = (int)($intervention['id'] ?? 0); ?>
                                    <tr>
                                        <td><?php echo ia_h(ia_format_datetime_utc((string)($intervention['decision_at'] ?? ''))); ?></td>
                                        <td><?php echo ia_h(ia_decision_code_label((string)($intervention['decision_code'] ?? ''))); ?></td>
                                        <td style="font-weight:800;color:#102845;"><?php echo (int)($intervention['granted_extra_attempts'] ?? 0); ?></td>
                                        <td>
                                            <div class="ia-table-actions">
                                                <button type="button" class="ia-link-btn" data-open-modal="intervention-modal-<?php echo $interventionId; ?>">
                                                    Details
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
			
			
        </div>

    </div>
</div>

<?php foreach ($attemptHistory as $attempt): ?>
    <?php
    $attemptId = (int)($attempt['id'] ?? 0);
    $items = (array)($attemptItems[$attemptId] ?? array());
    ?>
    <div class="ia-modal" id="attempt-modal-<?php echo $attemptId; ?>">
        <div class="ia-modal-card">
            <div class="ia-modal-head">
                <div>
                    <h2 class="ia-modal-title">Progress Test Details · Attempt <?php echo (int)($attempt['attempt'] ?? 0); ?></h2>
                    <div class="ia-modal-sub">
                        Score:
                        <?php echo isset($attempt['score_pct']) && $attempt['score_pct'] !== null ? ia_h((string)$attempt['score_pct']) . '%' : '—'; ?>
                        · Result:
                        <?php echo ia_h(ia_result_label((string)($attempt['formal_result_code'] ?? ''), (string)($attempt['formal_result_label'] ?? ''))); ?>
                        · Completed:
                        <?php echo ia_h(ia_format_datetime_utc((string)($attempt['completed_at'] ?? ''))); ?>
                    </div>
                </div>
                <button type="button" class="ia-close" data-close-modal="attempt-modal-<?php echo $attemptId; ?>">×</button>
            </div>

            <div class="ia-modal-body">
                <?php if (!$items): ?>
                    <div style="font-size:13px;color:#64748b;">No progress test item rows found for this attempt.</div>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $audioUrl = ia_build_audio_url((string)($item['audio_path'] ?? ''));
                        $effectiveIsCorrect = null;
                        $effectiveScorePoints = null;

                        if (isset($item['override_id']) && $item['override_id'] !== null) {
                            $effectiveIsCorrect = isset($item['override_is_correct']) && $item['override_is_correct'] !== null
                                ? (int)$item['override_is_correct']
                                : null;
                            $effectiveScorePoints = isset($item['override_score_points']) && $item['override_score_points'] !== null
                                ? (int)$item['override_score_points']
                                : null;
                        } else {
                            $effectiveIsCorrect = isset($item['is_correct']) && $item['is_correct'] !== null
                                ? (int)$item['is_correct']
                                : null;
                            $effectiveScorePoints = isset($item['score_points']) && $item['score_points'] !== null
                                ? (int)$item['score_points']
                                : null;
                        }
                        ?>
                        <div class="ia-qa-card">
                            <div class="ia-qa-q">
                                Q<?php echo (int)($item['idx'] ?? 0); ?>.
                                <?php echo ia_h((string)($item['prompt'] ?? '')); ?>
                            </div>

                            <div class="ia-audio-row">
                                <?php if ($audioUrl !== ''): ?>
                                    <audio controls preload="none" src="<?php echo ia_h($audioUrl); ?>"></audio>
                                <?php else: ?>
                                    <span style="font-size:12px;color:#64748b;">No audio recording available.</span>
                                <?php endif; ?>
                            </div>

                            <div class="ia-qa-a"><?php echo ia_h((string)($item['transcript_text'] ?? 'No transcript available.')); ?></div>

                            <div class="ia-chip-row" style="margin-top:10px;">
                                <span class="ia-chip <?php echo $effectiveIsCorrect === 1 ? 'ok' : 'danger'; ?>">
                                    Score: <?php echo $effectiveScorePoints !== null ? (int)$effectiveScorePoints : '—'; ?> / <?php echo isset($item['max_points']) && $item['max_points'] !== null ? (int)$item['max_points'] : '—'; ?>
                                </span>
                                <span class="ia-chip info">
                                    Correctness:
                                    <?php
                                    echo $effectiveIsCorrect === null
                                        ? '—'
                                        : ($effectiveIsCorrect === 1 ? 'Correct' : 'Incorrect');
                                    ?>
                                </span>
                                <?php if (isset($item['override_id']) && $item['override_id'] !== null): ?>
                                    <span class="ia-chip warning">Instructor Override Logged</span>
                                <?php endif; ?>
                            </div>

                            <div class="ia-override-box">
                                <div class="ia-override-title">Manual Score Override</div>
                                <div style="font-size:12px;line-height:1.5;color:#64748b;">
                                    Override execution wiring belongs to the canonical follow-up path. This Phase 1 page surfaces the exact item-level context and latest override audit, while the full override apply/recompute flow is added separately to avoid unsafe state drift.
                                </div>

                                <div class="ia-override-grid" style="margin-top:10px;">
                                    <div class="ia-field">
                                        <label class="ia-label">Override Correctness</label>
                                        <select class="ia-select" disabled>
                                            <option><?php echo $effectiveIsCorrect === 1 ? 'Correct' : ($effectiveIsCorrect === 0 ? 'Incorrect' : '—'); ?></option>
                                        </select>
                                    </div>

                                    <div class="ia-field">
                                        <label class="ia-label">Override Score Points</label>
                                        <input class="ia-input" type="text" disabled value="<?php echo ia_h($effectiveScorePoints !== null ? (string)$effectiveScorePoints : ''); ?>">
                                    </div>
                                </div>

                                <div class="ia-field" style="margin-top:10px;">
                                    <label class="ia-label">Override Reason</label>
                                    <textarea class="ia-textarea" disabled placeholder="Override apply wiring is added in the next step."></textarea>
                                </div>
                            </div>

                            <?php if (isset($item['override_id']) && $item['override_id'] !== null): ?>
                                <div class="ia-override-meta">
                                    <strong>Latest override reason:</strong><br>
                                    <?php echo nl2br(ia_h((string)($item['override_reason'] ?? ''))); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<div class="ia-modal" id="summary-modal">
    <div class="ia-modal-card">
        <div class="ia-modal-head">
            <div>
                <h2 class="ia-modal-title">Lesson Summary</h2>
                <div class="ia-modal-sub">
                    Review the student summary and save instructor notes that will remain visible in the canonical summary record.
                </div>
            </div>
            <button type="button" class="ia-close" data-close-modal="summary-modal">×</button>
        </div>

        <div class="ia-modal-body">
            <?php if (!$lessonSummary): ?>
                <div style="font-size:13px;color:#64748b;">No lesson summary record found.</div>
            <?php else: ?>
                <div class="ia-chip-row" style="margin-top:0;margin-bottom:12px;">
                    <?php if ($summaryStatus !== ''): ?>
                        <span class="ia-chip <?php echo ia_h(ia_summary_status_class($summaryStatus)); ?>">
                            <?php echo ia_h(ia_summary_status_label($summaryStatus)); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($summaryScore !== null): ?>
                        <span class="ia-chip info">Review Score: <?php echo ia_h((string)ia_percent_clamped($summaryScore)); ?>%</span>
                    <?php endif; ?>
                    <?php if (!empty($lessonSummary['student_soft_locked'])): ?>
                        <span class="ia-chip warning">Student Soft Locked</span>
                    <?php endif; ?>
                </div>

                <div class="ia-summary-preview">
                    <?php
                    $summaryHtml = trim((string)($lessonSummary['summary_html'] ?? ''));
                    if ($summaryHtml !== '') {
                        echo $summaryHtml;
                    } else {
                        echo nl2br(ia_h((string)($lessonSummary['summary_plain'] ?? 'No summary content available.')));
                    }
                    ?>
                </div>

                <form method="post" style="margin-top:16px;">
                    <input type="hidden" name="page_action" value="save_summary_notes">

                    <div class="ia-field">
                        <label class="ia-label">Instructor Notes for Student Summary Page</label>
                        <textarea
                            class="ia-textarea"
                            name="review_notes_by_instructor"
                            placeholder="Add instructor notes to the lesson summary record."
                        ><?php echo ia_h((string)($lessonSummary['review_notes_by_instructor'] ?? '')); ?></textarea>
                    </div>

                    <div class="ia-actions">
                        <button type="submit" class="ia-btn">Save Summary Notes</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>


<?php foreach ($interventionHistory as $intervention): ?>
    <?php
    $interventionId = (int)($intervention['id'] ?? 0);
    $decisionByName = trim((string)($intervention['decision_by_name'] ?? ''));
    if ($decisionByName === '') {
        $decisionByName = trim((string)($intervention['decision_by_first_name'] ?? '') . ' ' . (string)($intervention['decision_by_last_name'] ?? ''));
    }
    if ($decisionByName === '') {
        $decisionByName = '—';
    }
    ?>
    <div class="ia-modal" id="intervention-modal-<?php echo $interventionId; ?>">
        <div class="ia-modal-card">
            <div class="ia-modal-head">
                <div>
                    <h2 class="ia-modal-title">Instructor Intervention Details</h2>
                    <div class="ia-modal-sub">
                        <?php echo ia_h(ia_decision_code_label((string)($intervention['decision_code'] ?? ''))); ?>
                        · <?php echo ia_h(ia_format_datetime_utc((string)($intervention['decision_at'] ?? ''))); ?>
                    </div>
                </div>
                <button type="button" class="ia-close" data-close-modal="intervention-modal-<?php echo $interventionId; ?>">×</button>
            </div>

            <div class="ia-modal-body">
                <div class="ia-detail-grid">
                    <div class="ia-detail-box">
                        <div class="ia-detail-label">Decision</div>
                        <div class="ia-detail-value"><?php echo ia_h(ia_decision_code_label((string)($intervention['decision_code'] ?? ''))); ?></div>
                    </div>

                    <div class="ia-detail-box">
                        <div class="ia-detail-label">Attempts Granted</div>
                        <div class="ia-detail-value"><?php echo (int)($intervention['granted_extra_attempts'] ?? 0); ?></div>
                    </div>

                    <div class="ia-detail-box">
                        <div class="ia-detail-label">Summary Revision Required</div>
                        <div class="ia-detail-value"><?php echo !empty($intervention['summary_revision_required']) ? 'Yes' : 'No'; ?></div>
                    </div>

                    <div class="ia-detail-box">
                        <div class="ia-detail-label">One-on-One Required</div>
                        <div class="ia-detail-value"><?php echo !empty($intervention['one_on_one_required']) ? 'Yes' : 'No'; ?></div>
                    </div>

                    <div class="ia-detail-box">
                        <div class="ia-detail-label">One-on-One Completed</div>
                        <div class="ia-detail-value">—</div>
                    </div>

                    <div class="ia-detail-box">
                        <div class="ia-detail-label">Training Suspended</div>
                        <div class="ia-detail-value"><?php echo !empty($intervention['training_suspended']) ? 'Yes' : 'No'; ?></div>
                    </div>

                    <div class="ia-detail-box">
                        <div class="ia-detail-label">Major Intervention Flag</div>
                        <div class="ia-detail-value"><?php echo !empty($intervention['major_intervention_flag']) ? 'Yes' : 'No'; ?></div>
                    </div>

                    <div class="ia-detail-box">
                        <div class="ia-detail-label">Recorded By</div>
                        <div class="ia-detail-value"><?php echo ia_h($decisionByName); ?></div>
                    </div>
                </div>

                <div style="margin-top:16px;">
                    <div class="ia-label" style="margin-bottom:8px;">Instructor Notes</div>
                    <div class="ia-summary-preview" style="max-height:none;">
                        <?php
                        $notes = trim((string)($intervention['decision_notes'] ?? ''));
                        echo $notes !== ''
                            ? nl2br(ia_h($notes))
                            : 'No decision notes recorded.';
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>


<script>
(function () {
    var openButtons = document.querySelectorAll('[data-open-modal]');
    var closeButtons = document.querySelectorAll('[data-close-modal]');

    function openModal(id) {
        var modal = document.getElementById(id);
        if (modal) {
            modal.classList.add('is-open');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeModal(id) {
        var modal = document.getElementById(id);
        if (modal) {
            modal.classList.remove('is-open');
        }

        var anyOpen = document.querySelector('.ia-modal.is-open');
        if (!anyOpen) {
            document.body.style.overflow = '';
        }
    }

    openButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal(btn.getAttribute('data-open-modal'));
        });
    });

    closeButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            closeModal(btn.getAttribute('data-close-modal'));
        });
    });

    document.querySelectorAll('.ia-modal').forEach(function (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                modal.classList.remove('is-open');
                var anyOpen = document.querySelector('.ia-modal.is-open');
                if (!anyOpen) {
                    document.body.style.overflow = '';
                }
            }
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.ia-modal.is-open').forEach(function (modal) {
                modal.classList.remove('is-open');
            });
            document.body.style.overflow = '';
        }
    });

    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-copy') || '';
            if (!text) {
                return;
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    var old = btn.textContent;
                    btn.textContent = 'Copied';
                    setTimeout(function () { btn.textContent = old; }, 1200);
                });
            }
        });
    });



    var decisionForm = document.getElementById('ia-decision-form');
    var decisionCode = document.getElementById('ia-decision-code');
    var extraAttemptsField = document.getElementById('ia-extra-attempts-field');
    var extraAttemptsInput = document.getElementById('ia-granted-extra-attempts');
    var oneOnOneFields = document.getElementById('ia-one-on-one-fields');
    var decisionNotes = document.getElementById('ia-decision-notes');
    var oneOnOneDate = document.getElementById('ia-one-on-one-date');
    var oneOnOneInstructor = document.getElementById('ia-one-on-one-instructor');
    var oneOnOneTimeFrom = document.getElementById('ia-one-on-one-time-from');
    var oneOnOneTimeUntil = document.getElementById('ia-one-on-one-time-until');

    function setRequired(el, isRequired) {
        if (!el) return;
        if (isRequired) {
            el.setAttribute('required', 'required');
        } else {
            el.removeAttribute('required');
        }
    }

    function syncDecisionUi() {
        if (!decisionCode) return;

        var code = decisionCode.value || '';
        var needsAttempts =
            code === 'approve_additional_attempts' ||
            code === 'approve_with_summary_revision' ||
            code === 'approve_with_one_on_one';

        var needsOneOnOne =
            code === 'approve_with_one_on_one';

        var needsReasonOnly =
            code === 'suspend_training';

        if (extraAttemptsField) {
            extraAttemptsField.style.display = needsAttempts ? '' : 'none';
        }
        if (oneOnOneFields) {
            oneOnOneFields.style.display = needsOneOnOne ? '' : 'none';
        }

        setRequired(extraAttemptsInput, needsAttempts);
        setRequired(oneOnOneDate, needsOneOnOne);
        setRequired(oneOnOneInstructor, needsOneOnOne);
        setRequired(oneOnOneTimeFrom, needsOneOnOne);
        setRequired(oneOnOneTimeUntil, needsOneOnOne);
        setRequired(decisionNotes, needsReasonOnly || needsAttempts);

        if (!needsAttempts && extraAttemptsInput) {
            extraAttemptsInput.value = '';
        }

        if (!needsOneOnOne) {
            if (oneOnOneDate) oneOnOneDate.value = '';
            if (oneOnOneInstructor) oneOnOneInstructor.value = '';
            if (oneOnOneTimeFrom) oneOnOneTimeFrom.value = '';
            if (oneOnOneTimeUntil) oneOnOneTimeUntil.value = '';
        }
    }

    if (decisionCode) {
        decisionCode.addEventListener('change', syncDecisionUi);
        syncDecisionUi();
    }

    if (decisionForm) {
        decisionForm.addEventListener('submit', function (e) {
            if (!decisionCode) return;

            var code = decisionCode.value || '';

            if (
                (code === 'approve_additional_attempts' ||
                 code === 'approve_with_summary_revision' ||
                 code === 'approve_with_one_on_one') &&
                (!extraAttemptsInput || !extraAttemptsInput.value || parseInt(extraAttemptsInput.value, 10) < 1)
            ) {
                e.preventDefault();
                alert('Please select between 1 and 5 extra progress test attempts.');
                return;
            }

            if (code === 'approve_with_one_on_one') {
                if (!oneOnOneDate || !oneOnOneDate.value) {
                    e.preventDefault();
                    alert('Please select the one-on-one date.');
                    return;
                }
                if (!oneOnOneTimeFrom || !oneOnOneTimeFrom.value) {
                    e.preventDefault();
                    alert('Please select the one-on-one start time.');
                    return;
                }
                if (!oneOnOneTimeUntil || !oneOnOneTimeUntil.value) {
                    e.preventDefault();
                    alert('Please select the one-on-one end time.');
                    return;
                }
                if (!oneOnOneInstructor || !oneOnOneInstructor.value) {
                    e.preventDefault();
                    alert('Please select the instructor for the one-on-one.');
                    return;
                }
            }

            if (code === 'suspend_training') {
                if (!decisionNotes || !decisionNotes.value.trim()) {
                    e.preventDefault();
                    alert('Please provide the reason for suspending training.');
                    return;
                }
            }
        });
    }


})();
</script>

<?php cw_footer(); ?>