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

function ia_avatar_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'S';
    }

    $parts = preg_split('/\s+/u', $name) ?: array();
    $parts = array_values(array_filter($parts, static fn($p): bool => trim((string)$p) !== ''));
    $sub = static function (string $s, int $start, int $len): string {
        if (function_exists('mb_substr')) {
            return (string)mb_substr($s, $start, $len, 'UTF-8');
        }

        return substr($s, $start, $len);
    };

    if (count($parts) >= 2) {
        $a = strtoupper($sub($parts[0], 0, 1));
        $b = strtoupper($sub($parts[count($parts) - 1], 0, 1));

        return $a . $b;
    }

    return strtoupper($sub($parts[0] ?? $name, 0, 2));
}

function ia_tcc_radar_color_student(array $activity, array $action, bool $deadlinePassed): string
{
    $cs = (string)($activity['completion_status'] ?? '');

    if (!empty($activity['training_suspended']) || $cs === 'training_suspended' || $cs === 'deadline_blocked') {
        return 'red';
    }
    if ($deadlinePassed && $cs !== 'completed') {
        return 'red';
    }
    if (in_array((string)($action['status'] ?? ''), array('pending', 'opened'), true)) {
        return 'blue';
    }
    if (in_array($cs, array(
        'remediation_required',
        'instructor_required',
        'summary_required',
        'awaiting_summary_review',
        'awaiting_test_completion',
    ), true)) {
        return 'orange';
    }

    return 'green';
}

function ia_tcc_avatar_markup(array $user, string $displayName, string $radarColor): string
{
    $photoPath = trim((string)($user['photo_path'] ?? ''));
    $san = preg_replace('/[^a-z]/', '', strtolower($radarColor));
    $radarColor = is_string($san) ? $san : '';
    if ($radarColor === '' || !in_array($radarColor, array('green', 'orange', 'red', 'blue', 'purple'), true)) {
        $radarColor = 'green';
    }

    $cls = 'tcc-avatar ' . $radarColor;
    $initials = ia_avatar_initials($displayName);

    if ($photoPath !== '') {
        return '<div class="' . ia_h($cls) . '"><span class="tcc-avatar-inner"><img src="' . ia_h($photoPath) . '" alt="' . ia_h($displayName) . '"></span></div>';
    }

    return '<div class="' . ia_h($cls) . '"><span class="tcc-avatar-inner">' . ia_h($initials) . '</span></div>';
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
            sra.decision_payload_json,
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
          AND NOT (
              COALESCE(pt.formal_result_code, '') = 'STALE_ABORTED'
              AND COALESCE(pt.counts_as_unsat, 0) = 0
              AND COALESCE(pt.pass_gate_met, 0) = 0
          )
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
            SELECT DISTINCT
                sr.ref_type,
                sr.ref_code,
                sr.ref_title,
                sr.notes,
                sr.confidence,
                s.id AS slide_id,
                s.title AS slide_title
            FROM slides s
            INNER JOIN slide_enrichment se
                ON se.slide_id = s.id
            INNER JOIN slide_references sr
                ON sr.slide_id = s.id
            WHERE s.lesson_id = ?
              AND COALESCE(s.is_deleted, 0) = 0
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
                sr.confidence,
                s.id AS slide_id,
                s.title AS slide_title
            FROM slide_references sr
            INNER JOIN slides s
                ON s.id = sr.slide_id
            WHERE s.lesson_id = ?
              AND COALESCE(s.is_deleted, 0) = 0
            ORDER BY
                FIELD(sr.ref_type, 'ACS', 'PHAK', 'FAR_AIM', 'EASA'),
                sr.confidence DESC,
                s.id ASC,
                sr.id ASC
        ",
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

    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                'OTHER' AS ref_type,
                CONCAT('Slide ', COALESCE(NULLIF(s.page_number, 0), s.id)) AS ref_code,
                COALESCE(NULLIF(s.title, ''), 'Lesson slide (enriched)') AS ref_title,
                'Linked via slide_enrichment (no formal ref rows on file)' AS notes,
                0.5 AS confidence,
                s.id AS slide_id,
                COALESCE(NULLIF(s.title, ''), CONCAT('Slide ', s.id)) AS slide_title
            FROM slides s
            INNER JOIN slide_enrichment se ON se.slide_id = s.id
            WHERE s.lesson_id = ?
              AND COALESCE(s.is_deleted, 0) = 0
            ORDER BY s.page_number ASC, s.id ASC
            LIMIT 40
        ");
        $stmt->execute(array($lessonId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        if ($rows) {
            return $rows;
        }
    } catch (Throwable $e) {
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

function ia_build_difficulty_struct(array $attempts, array $attemptItems): array
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

    $oral = array();
    foreach ($failedPromptCounts as $prompt => $count) {
        $oral[] = $count > 1 ? $prompt . ' (' . $count . ' times)' : $prompt;
        if (count($oral) >= 8) {
            break;
        }
    }

    $gaps = array();
    foreach ($weakKeywordCounts as $line => $count) {
        $gaps[] = $count > 1 ? $line . ' (' . $count . ' times)' : $line;
        if (count($gaps) >= 8) {
            break;
        }
    }

    return array('oral' => $oral, 'gaps' => $gaps);
}

/**
 * Turn stored weak-area blobs (often "1. … 2. …" on one line, sometimes prefixed with
 * "Review these areas:") into separate list items for readable HTML output.
 *
 * @param array<int,string> $lines
 * @return array<int,string>
 */
function ia_expand_core_gap_lines(array $lines): array
{
    $out = array();

    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }

        $line = preg_replace('/^(review\s+these\s+areas:\s*)+/iu', '', $line);
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }

        $parts = preg_split('/(?<!\d)(?=\d{1,3}\.\s)/u', $line);
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part === '') {
                continue;
            }
            $part = preg_replace('/^\d{1,3}\.\s*/u', '', $part);
            $part = trim((string)$part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
    }

    $seen = array();
    $uniq = array();
    foreach ($out as $p) {
        $k = function_exists('mb_strtolower') ? mb_strtolower($p, 'UTF-8') : strtolower($p);
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $uniq[] = $p;
    }

    return $uniq;
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

function ia_intervention_deadline_date_cohort(PDO $pdo, array $intervention, int $cohortId, int $studentUserId): string
{
    $raw = trim((string)($intervention['decision_payload_json'] ?? ''));
    $payload = array();
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    $utc = trim((string)($payload['reopened_effective_deadline_utc'] ?? ''));
    if ($utc === '') {
        return '—';
    }
    $tz = cw_effective_cohort_timezone($pdo, $cohortId, $studentUserId);
    $dt = cw_dt_obj($utc, $tz);
    if (!$dt) {
        return '—';
    }

    return $dt->format('M j, Y');
}

function ia_intervention_decision_summary(array $intervention): string
{
    $base = ia_decision_code_label((string)($intervention['decision_code'] ?? ''));
    $raw = trim((string)($intervention['decision_payload_json'] ?? ''));
    $payload = array();
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    $extras = array();
    $days = (int)($payload['deadline_extension_days'] ?? 0);
    if ($days > 0) {
        $extras[] = '+' . $days . 'd deadline ext';
    }
    if (!empty($payload['summary_revision_required'])) {
        $extras[] = 'summary revision';
    }
    if (!empty($payload['one_on_one_required'])) {
        $extras[] = 'one-on-one';
    }
    if (!empty($payload['training_suspended'])) {
        $extras[] = 'training suspended';
    }
    if ($extras) {
        return $base . ' · ' . implode(' · ', $extras);
    }

    return $base;
}

$state = ia_load_state($engine, $token);
$action = (array)$state['action'];
$activity = (array)($state['activity'] ?? array());
$progressionContext = (array)($state['progression_context'] ?? array());
$latestProgressTest = (array)($state['latest_progress_test'] ?? array());

$actionDecisionPayload = array();
$actionDecisionPayloadRaw = trim((string)($action['decision_payload_json'] ?? ''));
if ($actionDecisionPayloadRaw !== '') {
    $decoded = json_decode($actionDecisionPayloadRaw, true);
    if (is_array($decoded)) {
        $actionDecisionPayload = $decoded;
    }
}

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
            $rawGrantedExtraAttempts = trim((string)($_POST['granted_extra_attempts'] ?? ''));
            $grantedExtraAttempts = ($rawGrantedExtraAttempts === '') ? 0 : (int)$rawGrantedExtraAttempts;

            $rawDeadlineExtensionDays = trim((string)($_POST['deadline_extension_days'] ?? ''));
            $deadlineExtensionDays = ($rawDeadlineExtensionDays === '') ? 0 : (int)$rawDeadlineExtensionDays;

            $oneOnOneDate = trim((string)($_POST['one_on_one_date'] ?? ''));
            $oneOnOneTimeFrom = trim((string)($_POST['one_on_one_time_from'] ?? ''));
            $oneOnOneTimeUntil = trim((string)($_POST['one_on_one_time_until'] ?? ''));
            $oneOnOneInstructorUserId = (int)($_POST['one_on_one_instructor_user_id'] ?? 0);
            $oneOnOneStartUtc = trim((string)($_POST['one_on_one_start_utc'] ?? ''));
            $oneOnOneEndUtc = trim((string)($_POST['one_on_one_end_utc'] ?? ''));
            $oneOnOneTimezone = trim((string)($_POST['one_on_one_timezone'] ?? ''));

            $validDecisions = array_keys(ia_decision_ui_options());
            if (!in_array($uiDecision, $validDecisions, true)) {
                throw new RuntimeException('Please select a valid instructor decision.');
            }

            $requiresAttempts = in_array($uiDecision, array(
                'approve_additional_attempts',
                'approve_with_summary_revision',
                'approve_with_one_on_one',
            ), true);

            if ($requiresAttempts) {
                if ($grantedExtraAttempts < 1 || $grantedExtraAttempts > 5) {
                    throw new RuntimeException('Please select between 1 and 5 extra progress test attempts.');
                }
            } else {
                $grantedExtraAttempts = 0;
            }

            if ($deadlineExtensionDays < 0 || $deadlineExtensionDays > 10) {
                throw new RuntimeException('Please select between 0 and 10 deadline extension days.');
            }

            if (!$requiresAttempts) {
                $deadlineExtensionDays = 0;
            }

            if ($uiDecision === 'approve_with_one_on_one') {
                if ($oneOnOneDate === '') {
                    throw new RuntimeException('Please select the one-on-one date.');
                }
                if ($oneOnOneTimeFrom === '') {
                    throw new RuntimeException('Please select the one-on-one start time.');
                }
                if ($oneOnOneTimeUntil === '') {
                    throw new RuntimeException('Please select the one-on-one end time.');
                }
                if ($oneOnOneInstructorUserId <= 0) {
                    throw new RuntimeException('Please select the instructor for the one-on-one.');
                }

                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $oneOnOneDate)) {
                    throw new RuntimeException('Invalid one-on-one date format.');
                }
                if (!preg_match('/^\d{2}:\d{2}$/', $oneOnOneTimeFrom)) {
                    throw new RuntimeException('Invalid one-on-one start time format.');
                }
                if (!preg_match('/^\d{2}:\d{2}$/', $oneOnOneTimeUntil)) {
                    throw new RuntimeException('Invalid one-on-one end time format.');
                }
                if ($oneOnOneTimeUntil <= $oneOnOneTimeFrom) {
                    throw new RuntimeException('One-on-one end time must be later than the start time.');
                }

                if ($oneOnOneStartUtc === '' || $oneOnOneEndUtc === '') {
                    throw new RuntimeException('One-on-one UTC scheduling values are missing.');
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $oneOnOneStartUtc)) {
                    throw new RuntimeException('Invalid one-on-one UTC start format.');
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $oneOnOneEndUtc)) {
                    throw new RuntimeException('Invalid one-on-one UTC end format.');
                }
                if ($oneOnOneEndUtc <= $oneOnOneStartUtc) {
                    throw new RuntimeException('One-on-one UTC end time must be later than start time.');
                }
            }

            if ($uiDecision === 'suspend_training' && $decisionNotes === '') {
                throw new RuntimeException('Please provide the reason for suspending training.');
            }

            if ($decisionNotes === '') {
                throw new RuntimeException('Please provide instructor decision notes.');
            }

            $payload = array(
                'decision_code' => $uiDecision,
                'granted_extra_attempts' => $grantedExtraAttempts,
                'deadline_extension_days' => $deadlineExtensionDays,
                'summary_revision_required' => 0,
                'one_on_one_required' => 0,
                'training_suspended' => 0,
                'major_intervention_flag' => 0,
                'decision_notes' => $decisionNotes,
                'one_on_one_date' => $oneOnOneDate,
                'one_on_one_time_from' => $oneOnOneTimeFrom,
                'one_on_one_time_until' => $oneOnOneTimeUntil,
                'one_on_one_instructor_user_id' => $oneOnOneInstructorUserId,
                'one_on_one_start_utc' => $oneOnOneStartUtc,
                'one_on_one_end_utc' => $oneOnOneEndUtc,
                'one_on_one_timezone' => $oneOnOneTimezone,
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
        } elseif ($postAction === 'resend_intervention_emails') {
            $rid = (int)($_POST['required_action_id'] ?? 0);
            if ($rid <= 0) {
                throw new RuntimeException('Missing intervention reference.');
            }

            $verify = $pdo->prepare("
                SELECT id
                FROM student_required_actions
                WHERE id = ?
                  AND user_id = ?
                  AND cohort_id = ?
                  AND lesson_id = ?
                  AND action_type = 'instructor_approval'
                  AND status = 'approved'
                LIMIT 1
            ");
            $verify->execute(array(
                $rid,
                (int)($action['user_id'] ?? 0),
                (int)($action['cohort_id'] ?? 0),
                (int)($action['lesson_id'] ?? 0),
            ));
            if (!(int)$verify->fetchColumn()) {
                throw new RuntimeException('That intervention is not part of this approval context.');
            }

            $engine->resendInstructorDecisionRecordedAutomationEmails($rid, $currentUserId);

            $nmStmt = $pdo->prepare("
                SELECT COALESCE(NULLIF(name, ''), TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')))) AS display_name
                FROM users
                WHERE id = ?
                LIMIT 1
            ");
            $nmStmt->execute(array((int)($action['user_id'] ?? 0)));
            $studentFlashName = trim((string)$nmStmt->fetchColumn());
            if ($studentFlashName === '') {
                $studentFlashName = 'the student';
            }

            $chiefFlashName = 'Chief Instructor';
            try {
                $chiefRecipient = $engine->getChiefInstructorRecipient(array('cohort_id' => (int)($action['cohort_id'] ?? 0)));
                if ($chiefRecipient && !empty($chiefRecipient['user_id'])) {
                    $nmStmt->execute(array((int)$chiefRecipient['user_id']));
                    $cn = trim((string)$nmStmt->fetchColumn());
                    if ($cn !== '') {
                        $chiefFlashName = $cn;
                    }
                }
            } catch (Throwable $e) {
            }

            $flashSuccess = 'Instructor decision e-mail was manually sent successfully to '
                . $studentFlashName
                . ' and Instructor '
                . $chiefFlashName
                . '.';
        } else {
            throw new RuntimeException('Unknown action.');
        }

        $state = ia_load_state($engine, $token);
        $action = (array)$state['action'];
        $activity = (array)($state['activity'] ?? array());
        $progressionContext = (array)($state['progression_context'] ?? array());
        $latestProgressTest = (array)($state['latest_progress_test'] ?? array());

        $actionDecisionPayload = array();
        $actionDecisionPayloadRaw = trim((string)($action['decision_payload_json'] ?? ''));
        if ($actionDecisionPayloadRaw !== '') {
            $decoded = json_decode($actionDecisionPayloadRaw, true);
            if (is_array($decoded)) {
                $actionDecisionPayload = $decoded;
            }
        }
    } catch (Throwable $e) {
        $flashError = $e->getMessage();

        $state = ia_load_state($engine, $token);
        $action = (array)$state['action'];
        $activity = (array)($state['activity'] ?? array());
        $progressionContext = (array)($state['progression_context'] ?? array());
        $latestProgressTest = (array)($state['latest_progress_test'] ?? array());

        $actionDecisionPayload = array();
        $actionDecisionPayloadRaw = trim((string)($action['decision_payload_json'] ?? ''));
        if ($actionDecisionPayloadRaw !== '') {
            $decoded = json_decode($actionDecisionPayloadRaw, true);
            if (is_array($decoded)) {
                $actionDecisionPayload = $decoded;
            }
        }
    }
}

$studentUserId = (int)($action['user_id'] ?? 0);
$cohortId = (int)($action['cohort_id'] ?? 0);
$lessonId = (int)($action['lesson_id'] ?? 0);

$deadlinePassedForUi = false;
try {
    $deadlineStateForUi = $engine->resolveDeadlineState($studentUserId, $cohortId, $lessonId);
    $deadlinePassedForUi = !empty($deadlineStateForUi['deadline_passed']);
} catch (Throwable $e) {
    $deadlinePassedForUi = false;
}

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

$scheduledOneOnOneInstructorName = '';
$scheduledOneOnOneInstructorId = (int)($actionDecisionPayload['one_on_one_instructor_user_id'] ?? 0);

if ($scheduledOneOnOneInstructorId > 0) {
    try {
        $scheduledInstructorStmt = $pdo->prepare("
            SELECT id, name, first_name, last_name
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $scheduledInstructorStmt->execute(array($scheduledOneOnOneInstructorId));
        $scheduledInstructor = $scheduledInstructorStmt->fetch(PDO::FETCH_ASSOC) ?: array();

        $scheduledOneOnOneInstructorName = trim((string)($scheduledInstructor['name'] ?? ''));
        if ($scheduledOneOnOneInstructorName === '') {
            $scheduledOneOnOneInstructorName = trim((string)($scheduledInstructor['first_name'] ?? '') . ' ' . (string)($scheduledInstructor['last_name'] ?? ''));
        }
    } catch (Throwable $e) {
        $scheduledOneOnOneInstructorName = '';
    }
}

$lessonSummary = array();
try {
    $summaryStmt = $pdo->prepare("
        SELECT
            ls.*,
            ru.name AS reviewed_by_name,
            ru.first_name AS reviewed_by_first_name,
            ru.last_name AS reviewed_by_last_name
        FROM lesson_summaries ls
        LEFT JOIN users ru
            ON ru.id = ls.reviewed_by_user_id
        WHERE ls.user_id = ?
          AND ls.cohort_id = ?
          AND ls.lesson_id = ?
        LIMIT 1
    ");
    $summaryStmt->execute(array($studentUserId, $cohortId, $lessonId));
    $lessonSummary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: array();
} catch (Throwable $e) {
    $lessonSummary = array();
}

$reviewedByDisplayName = trim((string)($lessonSummary['reviewed_by_name'] ?? ''));
if ($reviewedByDisplayName === '') {
    $reviewedByDisplayName = trim((string)($lessonSummary['reviewed_by_first_name'] ?? '') . ' ' . (string)($lessonSummary['reviewed_by_last_name'] ?? ''));
}
if ($reviewedByDisplayName === '' && !empty($lessonSummary['reviewed_by_user_id'])) {
    $reviewedByDisplayName = 'Instructor #' . (int)$lessonSummary['reviewed_by_user_id'];
}

$attemptHistory = ia_collect_attempt_history($pdo, $studentUserId, $cohortId, $lessonId);
$attemptIds = array_values(array_filter(array_map(
    static fn(array $row): int => (int)($row['id'] ?? 0),
    $attemptHistory
)));
$attemptItems = ia_collect_attempt_items($pdo, $attemptIds);
$officialReferences = ia_collect_official_references($pdo, $lessonId);
$groupedReferences = ia_group_references($officialReferences);
$difficultyStruct = ia_build_difficulty_struct($attemptHistory, $attemptItems);
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

$studentRadarColor = ia_tcc_radar_color_student($activity, $action, $deadlinePassedForUi);

$policy = $engine->resolveEffectivePolicySet($cohortId);
$behaviorMode = $engine->resolveBehaviorMode($policy);
$attemptState = $engine->resolveAttemptPolicyState($studentUserId, $cohortId, $lessonId, $policy, null, $behaviorMode);
$attemptUsed = (int)($attemptState['current_attempt_number'] ?? 0);
$attemptCap = max(1, (int)($attemptState['effective_allowed_attempts'] ?? 1));
$attemptBarPct = min(100, (int)round(100 * $attemptUsed / $attemptCap));
$attemptBarCls = 'ok';
if ($attemptUsed >= $attemptCap) {
    $attemptBarCls = 'danger';
} elseif ($attemptCap > 1 && $attemptUsed >= $attemptCap - 1) {
    $attemptBarCls = 'warn';
} elseif ($attemptBarPct >= 70) {
    $attemptBarCls = 'warn';
}
$attemptRatioText = $attemptUsed . '/' . $attemptCap;

$decisionByUser = array();
$decisionById = (int)($action['decision_by_user_id'] ?? 0);
if ($decisionById > 0) {
    try {
        $decByStmt = $pdo->prepare("
            SELECT id, name, first_name, last_name, email
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $decByStmt->execute(array($decisionById));
        $decisionByUser = $decByStmt->fetch(PDO::FETCH_ASSOC) ?: array();
    } catch (Throwable $e) {
        $decisionByUser = array();
    }
}
$decisionByDisplayName = trim((string)($decisionByUser['name'] ?? ''));
if ($decisionByDisplayName === '') {
    $decisionByDisplayName = trim((string)($decisionByUser['first_name'] ?? '') . ' ' . (string)($decisionByUser['last_name'] ?? ''));
}
if ($decisionByDisplayName === '') {
    $decisionByDisplayName = $decisionById > 0 ? 'User #' . $decisionById : '';
}

$latestActivityUtc = trim((string)($latestProgressTest['completed_at'] ?? ''));
if ($latestActivityUtc === '') {
    $latestActivityUtc = trim((string)($latestProgressTest['updated_at'] ?? ''));
}
if ($latestActivityUtc === '') {
    $latestActivityUtc = trim((string)($activity['last_state_eval_at'] ?? ''));
}
$latestActivityCohortTz = $latestActivityUtc !== ''
    ? cw_dt_cohort_tz($latestActivityUtc, $pdo, $cohortId, $studentUserId)
    : '—';

$lessonTitleForSections = trim((string)($state['lesson_title'] ?? ''));

$cohortTzForJs = cw_effective_cohort_timezone($pdo, $cohortId, $studentUserId);

$iaBackToTccHref = '/instructor/theory_control_center.php';
if ($cohortId > 0) {
    $iaBackToTccHref .= '?cohort_id=' . $cohortId;
}

cw_header('Instructor approval');
?>

<link rel="stylesheet" href="/instructor/css/tcc_ia_shared.css">

<style>
.ia-bridge-bar{
    display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:14px;
    padding:16px 20px;border-radius:20px;margin-bottom:6px;
    background:linear-gradient(135deg,#0f2745 0%,#1d4f89 100%);
    color:#fff;border:1px solid rgba(15,23,42,.12);
    box-shadow:0 14px 30px rgba(15,23,42,.08);
}
.ia-bridge-copy{font-size:12px;line-height:1.45;color:rgba(255,255,255,.88);max-width:520px;font-weight:600}
.ia-back-to-tcc{
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    min-height:40px;padding:0 16px;border-radius:12px;font-size:13px;font-weight:900;
    text-decoration:none;white-space:nowrap;flex-shrink:0;
    background:rgba(255,255,255,.14);color:#fff;border:1px solid rgba(255,255,255,.32);
    cursor:pointer;
}
.ia-back-to-tcc:hover{background:rgba(255,255,255,.22);color:#fff}
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
.ia-form-grid{display:grid;grid-template-columns:1fr 220px 220px;gap:14px}
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
/* Theory Control Center queue avatar + meta + attempt mini-bar (mirrored for consistency) */
.ia-tcc-people-row{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:20px 32px}
.ia-tcc-person{display:flex;align-items:center;gap:12px;min-width:0;flex:1 1 260px}
.tcc-avatar-inner{position:relative;width:40px;height:40px;border-radius:999px;display:flex;align-items:center;justify-content:center;overflow:hidden;background:linear-gradient(135deg,#0f2745 0%,#1d4f89 100%);color:#fff;font-weight:900;font-size:13px}
.tcc-avatar-inner img{width:100%;height:100%;object-fit:cover;display:block}
.tcc-avatar{width:44px;height:44px;border-radius:50%;background:#12355f;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;flex:0 0 auto;overflow:hidden;border:3px solid #dbe7f4}
.tcc-avatar.green{border-color:#16a34a}
.tcc-avatar.orange{border-color:#f59e0b}
.tcc-avatar.red{border-color:#dc2626}
.tcc-avatar.blue{border-color:#2563eb}
.tcc-avatar.purple{border-color:#7c3aed}
.tcc-meta{display:flex;flex-direction:column;min-width:0}
.tcc-name{font-weight:900;color:#102845;line-height:1.25}
.tcc-sub{font-size:12px;color:#64748b;line-height:1.35;overflow:hidden;text-overflow:ellipsis}
.tcc-mini-bar{height:9px;border-radius:999px;background:#e5eef7;overflow:hidden;margin-top:4px}
.tcc-mini-bar span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#12355f 0%,#2b6dcc 100%)}
.tcc-mini-bar span.ok{background:linear-gradient(90deg,#166534 0%,#22c55e 100%)}
.tcc-mini-bar span.warn{background:linear-gradient(90deg,#d97706 0%,#f59e0b 100%)}
.tcc-mini-bar span.danger{background:linear-gradient(90deg,#b91c1c 0%,#ef4444 100%)}
.ia-action-note{margin-top:14px;padding:14px;border-radius:16px;border:1px solid rgba(15,23,42,.06);background:#fff}
.ia-action-note-meta{font-size:11px;font-weight:800;color:#64748b;margin-bottom:8px;line-height:1.45}
.ia-action-note-body{font-size:13px;line-height:1.6;color:#334155;white-space:pre-wrap}
.ia-role-caps{margin-top:3px;font-size:10px;font-weight:900;letter-spacing:.12em;color:#64748b;text-transform:uppercase}
.ia-kv.ia-kv-clickable{cursor:pointer;border:1px solid rgba(15,23,42,.10);border-radius:16px;transition:box-shadow .12s ease,transform .08s ease,border-color .12s ease;background:#fff}
.ia-kv.ia-kv-clickable:hover{box-shadow:0 8px 22px rgba(15,23,42,.08);transform:translateY(-1px);border-color:rgba(29,79,137,.22)}
.ia-kv.ia-kv-clickable:focus{outline:2px solid rgba(29,79,137,.35);outline-offset:2px}
.ia-int-detail-meta{font-size:12px;font-weight:800;color:#64748b;line-height:1.45}
.ia-int-notes{white-space:pre-wrap;font-size:13px;line-height:1.55;color:#334155}
.ia-usage-inline{display:flex;align-items:center;gap:12px;width:100%}
.ia-usage-ratio{min-width:48px;font-size:15px;font-weight:900;color:#102845;text-align:left;flex:0 0 auto}
.ia-int-table{width:100%;border-collapse:collapse;font-size:11px}
.ia-int-table th,.ia-int-table td{padding:8px 6px;border-bottom:1px solid rgba(15,23,42,.06);text-align:left;vertical-align:middle}
.ia-int-table th{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;color:#64748b;white-space:nowrap}
.ia-int-toggle-row{cursor:pointer}
.ia-int-toggle-row:hover td{background:#f8fafc}
.ia-int-detail-row td{padding:12px 10px;background:#fbfdff;font-size:12px;line-height:1.5}
.ia-diff-block{margin-top:14px}
.ia-diff-block h4{margin:0 0 8px 0;font-size:13px;font-weight:900;color:#102845}
.ia-diff-block ol{margin:6px 0 0 18px;padding:0;color:#334155;font-size:13px;line-height:1.55}
</style>

<div class="ia-page">

    <div class="ia-bridge-bar">
        <a class="ia-back-to-tcc" href="<?php echo ia_h($iaBackToTccHref); ?>">← Back to Instructor Theory Control Center</a>
        <p class="ia-bridge-copy">
            Record the formal approval decision here. To select multiple open items and run cohort-safe bulk actions (for example the instructor-approval queue mode), use the Needs My Action section on the Control Center.
        </p>
    </div>

    <?php if ($flashError !== ''): ?>
        <div class="ia-flash error"><?php echo ia_h($flashError); ?></div>
    <?php endif; ?>

    <?php if ($flashSuccess !== ''): ?>
        <div class="ia-flash success"><?php echo ia_h($flashSuccess); ?></div>
    <?php endif; ?>

    <section class="card ia-hero">
        <div class="ia-eyebrow">INSTRUCTOR WORKSPACE · Instructor Approval</div>
        <h1 class="ia-title">Instructor Approval</h1>

        <div class="ia-chip-row">
            <span class="ia-chip info">Cohort: <?php echo ia_h((string)($state['cohort_title'] ?? '')); ?></span>
            <span class="ia-chip info">Lesson: <?php echo ia_h((string)($state['lesson_title'] ?? '')); ?></span>
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
                <div class="ia-tcc-people-row">
                    <div class="ia-tcc-person">
                        <?php echo ia_tcc_avatar_markup($studentUser, $studentName, $studentRadarColor); ?>
                        <span class="tcc-meta">
                            <span class="tcc-name"><?php echo ia_h($studentName); ?></span>
                            <span class="tcc-sub"><?php echo ia_h((string)($studentUser['email'] ?? '—')); ?></span>
                            <span class="ia-role-caps">Student</span>
                        </span>
                    </div>
                    <div class="ia-tcc-person">
                        <?php echo ia_tcc_avatar_markup($chiefUser, $chiefName, 'green'); ?>
                        <span class="tcc-meta">
                            <span class="tcc-name"><?php echo ia_h($chiefName); ?></span>
                            <span class="tcc-sub"><?php echo ia_h((string)($chiefUser['email'] ?? '') !== '' ? (string)$chiefUser['email'] : '—'); ?></span>
                            <span class="ia-role-caps">Instructor</span>
                        </span>
                    </div>
                </div>

                <div style="margin-top:18px;">
                    <div class="ia-section-title">
                        Progress Test<?php echo $lessonTitleForSections !== '' ? ' - ' . ia_h($lessonTitleForSections) : ''; ?>
                    </div>
                    <div class="ia-kv-grid">
                        <div class="ia-kv ia-kv-clickable" role="button" tabindex="0" data-ia-open="attempts" title="Open progress test details (same view as Theory Control Center)">
                            <div class="ia-kv-label">Attempt usage</div>
                            <div class="ia-kv-value" style="font-size:15px;font-weight:700;">
                                <div class="ia-usage-inline">
                                    <div class="ia-usage-ratio"><?php echo ia_h($attemptRatioText); ?></div>
                                    <div class="ia-track" style="flex:1 1 auto;">
                                        <div class="ia-fill <?php echo ia_h($attemptBarCls === 'ok' ? 'good' : ($attemptBarCls === 'warn' ? 'amber' : ($attemptBarCls === 'danger' ? 'danger' : 'neutral'))); ?>" style="width:<?php echo (int)$attemptBarPct; ?>%;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="ia-kv ia-kv-clickable" role="button" tabindex="0" data-ia-open="attempts" title="Open progress test details (same view as Theory Control Center)">
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

                        <div class="ia-kv ia-kv-clickable" role="button" tabindex="0" data-ia-open="attempts" title="Open progress test details (same view as Theory Control Center)">
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

                        <div class="ia-kv ia-kv-clickable" role="button" tabindex="0" data-ia-open="summary" title="Open theory summary (same view as Theory Control Center)">
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
                                <span class="ia-small-status info"><?php echo ia_h($latestActivityCohortTz); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card ia-card">
                <div class="ia-section-title">Areas of Difficulty</div>
                <?php
                $difficultyOral = (array)($difficultyStruct['oral'] ?? array());
                $difficultyGaps = (array)($difficultyStruct['gaps'] ?? array());
                $difficultyGapsDisplay = ia_expand_core_gap_lines($difficultyGaps);
                ?>
                <?php if (!$difficultyOral && !$difficultyGapsDisplay): ?>
                    <div class="ia-note-list">
                        <div class="ia-note-row">
                            <span class="ia-note-dot"></span>
                            <span>No consolidated difficulty signals were found yet for this lesson.</span>
                        </div>
                    </div>
                <?php else: ?>
                    <?php if ($difficultyOral): ?>
                        <div class="ia-diff-block">
                            <h4>Weak oral performance areas</h4>
                            <ol>
                                <?php foreach ($difficultyOral as $line): ?>
                                    <li><?php echo ia_h($line); ?></li>
                                <?php endforeach; ?>
                            </ol>
                        </div>
                    <?php endif; ?>
                    <?php if ($difficultyGapsDisplay): ?>
                        <div class="ia-diff-block">
                            <p style="margin:0 0 6px 0;font-size:13px;line-height:1.45;color:#102845;"><strong>Core gaps</strong></p>
                            <div style="font-size:12px;color:#64748b;font-weight:700;margin-bottom:6px;">Review these areas:</div>
                            <ol>
                                <?php foreach ($difficultyGapsDisplay as $line): ?>
                                    <li><?php echo ia_h($line); ?></li>
                                <?php endforeach; ?>
                            </ol>
                        </div>
                    <?php endif; ?>
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

        </div>

        <div style="display:flex;flex-direction:column;gap:18px;">

            <section class="card ia-actions-card">
                <div class="ia-section-title">Instructor Interventions</div>

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

                <?php if (!$interventionHistory): ?>
                    <div style="margin-top:12px;font-size:13px;line-height:1.6;color:#64748b;">No approved instructor interventions are recorded for this lesson yet.</div>
                <?php else: ?>
                    <div class="ia-int-wrap" style="margin-top:12px;">
                        <?php foreach ($interventionHistory as $intervention): ?>
                            <?php
                            $interventionId = (int)($intervention['id'] ?? 0);
                            $decisionAtIv = trim((string)($intervention['decision_at'] ?? ''));
                            $decisionWhenIv = $decisionAtIv !== ''
                                ? cw_dt_cohort_tz($decisionAtIv, $pdo, $cohortId, $studentUserId)
                                : '—';
                            $deciderIv = trim((string)($intervention['decision_by_name'] ?? ''));
                            if ($deciderIv === '') {
                                $deciderIv = trim((string)($intervention['decision_by_first_name'] ?? '') . ' ' . (string)($intervention['decision_by_last_name'] ?? ''));
                            }
                            if ($deciderIv === '') {
                                $deciderIv = '—';
                            }
                            $notesIv = trim((string)($intervention['decision_notes'] ?? ''));
                            ?>
                            <details class="ia-int-card" style="border:1px solid rgba(15,23,42,.08);border-radius:14px;background:#fff;margin-bottom:10px;overflow:hidden;">
                                <summary style="list-style:none;cursor:pointer;padding:10px 12px;display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1.5fr) 52px minmax(0,.95fr);gap:8px;align-items:center;font-size:11px;font-weight:800;color:#102845;">
                                    <span style="color:#334155;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo ia_h($decisionWhenIv); ?></span>
                                    <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo ia_h(ia_intervention_decision_summary($intervention)); ?></span>
                                    <span style="text-align:right;"><?php echo (int)($intervention['granted_extra_attempts'] ?? 0); ?></span>
                                    <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo ia_h(ia_intervention_deadline_date_cohort($pdo, $intervention, $cohortId, $studentUserId)); ?></span>
                                </summary>
                                <div style="padding:12px 14px;border-top:1px solid rgba(15,23,42,.06);background:#fbfdff;">
                                    <div class="ia-int-detail-meta">
                                        Formal decision notes
                                        <?php if ($deciderIv !== '—'): ?>
                                            · <?php echo ia_h($deciderIv); ?>
                                        <?php endif; ?>
                                        · <?php echo ia_h($decisionWhenIv); ?>
                                    </div>
                                    <div class="ia-int-notes"><?php echo $notesIv !== '' ? nl2br(ia_h($notesIv)) : 'No decision notes recorded.'; ?></div>
                                    <form method="post" style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                                        <input type="hidden" name="page_action" value="resend_intervention_emails">
                                        <input type="hidden" name="required_action_id" value="<?php echo $interventionId; ?>">
                                        <button type="submit" class="ia-btn secondary">Re-send decision emails (automation)</button>
                                    </form>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ((string)($action['status'] ?? '') !== 'approved'): ?>
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

                            <div class="ia-field" id="ia-deadline-extension-field" style="display:none;">
                                <label class="ia-label">Deadline Extension</label>
                                <select class="ia-select" name="deadline_extension_days" id="ia-deadline-extension-days">
                                    <option value="0">No deadline extension</option>
                                    <option value="1">1 day</option>
                                    <option value="2">2 days</option>
                                    <option value="3">3 days</option>
                                    <option value="4">4 days</option>
                                    <option value="5">5 days</option>
                                    <option value="6">6 days</option>
                                    <option value="7">7 days</option>
                                    <option value="8">8 days</option>
                                    <option value="9">9 days</option>
                                    <option value="10">10 days</option>
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

                                <div class="ia-field" style="display:none;"></div>
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

                                <div class="ia-field" style="display:none;"></div>
                            </div>
                        </div>

                        <input type="hidden" name="one_on_one_start_utc" id="ia-one-on-one-start-utc" value="">
                        <input type="hidden" name="one_on_one_end_utc" id="ia-one-on-one-end-utc" value="">
                        <input type="hidden" name="one_on_one_timezone" id="ia-one-on-one-timezone" value="">

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
            </section>
            <?php endif; ?>

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

                    <div style="margin-top:14px;padding:14px;border-radius:16px;border:1px solid rgba(15,23,42,.06);background:#fff;">
                        <div class="ia-kv-grid">
                            <div class="ia-kv">
                                <div class="ia-kv-label">Scheduled Date</div>
                                <div class="ia-kv-value" style="font-size:15px;">
                                    <?php echo ia_h((string)($actionDecisionPayload['one_on_one_date'] ?? '—')); ?>
                                </div>
                            </div>

                            <div class="ia-kv">
                                <div class="ia-kv-label">Scheduled Instructor</div>
                                <div class="ia-kv-value" style="font-size:15px;">
                                    <?php echo ia_h($scheduledOneOnOneInstructorName !== '' ? $scheduledOneOnOneInstructorName : '—'); ?>
                                </div>
                            </div>

                            <div class="ia-kv">
                                <div class="ia-kv-label">Time From</div>
                                <div class="ia-kv-value" style="font-size:15px;">
                                    <?php echo ia_h((string)($actionDecisionPayload['one_on_one_time_from'] ?? '—')); ?>
                                </div>
                            </div>

                            <div class="ia-kv">
                                <div class="ia-kv-label">Time Until</div>
                                <div class="ia-kv-value" style="font-size:15px;">
                                    <?php echo ia_h((string)($actionDecisionPayload['one_on_one_time_until'] ?? '—')); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <form method="post" style="margin-top:14px;">
                        <input type="hidden" name="page_action" value="mark_one_on_one_completed">
                        <button type="submit" class="ia-btn">Mark Instructor Session Completed</button>
                    </form>
                </section>
            <?php endif; ?>

        </div>

    </div>
</div>

<div id="tccModalOverlay" class="tcc-modal-overlay" aria-hidden="true">
    <div class="tcc-modal-card">
        <div class="tcc-modal-head">
            <div>
                <div class="tcc-modal-kicker">Instructor Diagnostic</div>
                <div id="tccModalTitle" class="tcc-modal-title">Diagnostic</div>
            </div>
            <button type="button" class="tcc-modal-close" onclick="closeTccModal()">×</button>
        </div>
        <div id="tccModalBody" class="tcc-modal-body"></div>
    </div>
</div>

<script>
window.__IA_TCC__ = <?php echo json_encode(array(
    'cohortId' => $cohortId,
    'studentId' => $studentUserId,
    'lessonId' => $lessonId,
    'cohortTz' => $cohortTzForJs,
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
window.iaDeadlinePassed = <?php echo $deadlinePassedForUi ? 'true' : 'false'; ?>;
</script>
<script src="/instructor/js/tcc_ia_readonly_modals.js"></script>
<script>
(function () {
    if (window.iaInitTccReadonlyModals) {
        window.iaInitTccReadonlyModals();
    }

    function bindIaOpen(selector, fnName) {
        document.querySelectorAll(selector).forEach(function (el) {
            function go() {
                if (window[fnName]) {
                    window[fnName]();
                }
            }
            el.addEventListener('click', go);
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    go();
                }
            });
        });
    }
    bindIaOpen('[data-ia-open="attempts"]', 'iaOpenAttemptDetailsModal');
    bindIaOpen('[data-ia-open="summary"]', 'iaOpenLessonSummaryModal');

    var overlay = document.getElementById('tccModalOverlay');
    if (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay && window.closeTccModal) {
                window.closeTccModal();
            }
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay && overlay.classList.contains('open') && window.closeTccModal) {
            window.closeTccModal();
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
                    setTimeout(function () {
                        btn.textContent = old;
                    }, 1200);
                });
            }
        });
    });

    var decisionForm = document.getElementById('ia-decision-form');
    var decisionCode = document.getElementById('ia-decision-code');
    var extraAttemptsField = document.getElementById('ia-extra-attempts-field');
    var extraAttemptsInput = document.getElementById('ia-granted-extra-attempts');
    var deadlineExtensionField = document.getElementById('ia-deadline-extension-field');
    var deadlineExtensionInput = document.getElementById('ia-deadline-extension-days');
    var oneOnOneFields = document.getElementById('ia-one-on-one-fields');
    var decisionNotes = document.getElementById('ia-decision-notes');
    var oneOnOneDate = document.getElementById('ia-one-on-one-date');
    var oneOnOneInstructor = document.getElementById('ia-one-on-one-instructor');
    var oneOnOneTimeFrom = document.getElementById('ia-one-on-one-time-from');
    var oneOnOneTimeUntil = document.getElementById('ia-one-on-one-time-until');

    function setRequired(el, isRequired) {
        if (!el) {
            return;
        }
        if (isRequired) {
            el.setAttribute('required', 'required');
        } else {
            el.removeAttribute('required');
        }
    }

    function buildUtcFromLocal(dateValue, timeValue) {
        if (!dateValue || !timeValue) {
            return '';
        }

        var local = new Date(dateValue + 'T' + timeValue + ':00');
        if (isNaN(local.getTime())) {
            return '';
        }

        var yyyy = local.getUTCFullYear();
        var mm = String(local.getUTCMonth() + 1).padStart(2, '0');
        var dd = String(local.getUTCDate()).padStart(2, '0');
        var hh = String(local.getUTCHours()).padStart(2, '0');
        var mi = String(local.getUTCMinutes()).padStart(2, '0');
        var ss = String(local.getUTCSeconds()).padStart(2, '0');

        return yyyy + '-' + mm + '-' + dd + ' ' + hh + ':' + mi + ':' + ss;
    }

    function syncDecisionUi() {
        if (!decisionCode) {
            return;
        }

        var code = decisionCode.value || '';

        var needsAttempts =
            code === 'approve_additional_attempts' ||
            code === 'approve_with_summary_revision' ||
            code === 'approve_with_one_on_one';

        var needsOneOnOne =
            code === 'approve_with_one_on_one';

        var needsReasonOnly =
            code === 'suspend_training';

        var needsDeadlineExtension =
            needsAttempts && !!window.iaDeadlinePassed;

        if (extraAttemptsField) {
            extraAttemptsField.style.display = needsAttempts ? '' : 'none';
        }

        if (deadlineExtensionField) {
            deadlineExtensionField.style.display = needsAttempts ? '' : 'none';
        }

        if (oneOnOneFields) {
            oneOnOneFields.style.display = needsOneOnOne ? '' : 'none';
        }

        setRequired(extraAttemptsInput, needsAttempts);
        setRequired(deadlineExtensionInput, needsDeadlineExtension);
        setRequired(oneOnOneDate, needsOneOnOne);
        setRequired(oneOnOneInstructor, needsOneOnOne);
        setRequired(oneOnOneTimeFrom, needsOneOnOne);
        setRequired(oneOnOneTimeUntil, needsOneOnOne);
        setRequired(decisionNotes, needsReasonOnly || needsAttempts);

        if (!needsAttempts && extraAttemptsInput) {
            extraAttemptsInput.value = '';
        }

        if (!needsAttempts && deadlineExtensionInput) {
            deadlineExtensionInput.value = '0';
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
            if (!decisionCode) {
                return;
            }

            var code = decisionCode.value || '';

            var isApprovalDecision =
                code === 'approve_additional_attempts' ||
                code === 'approve_with_summary_revision' ||
                code === 'approve_with_one_on_one';

            if (
                isApprovalDecision &&
                (!extraAttemptsInput || !extraAttemptsInput.value || parseInt(extraAttemptsInput.value, 10) < 1)
            ) {
                e.preventDefault();
                alert('Please select between 1 and 5 extra progress test attempts.');
                return;
            }

            if (isApprovalDecision && window.iaDeadlinePassed) {
                var extensionDays = deadlineExtensionInput
                    ? parseInt(deadlineExtensionInput.value || '0', 10)
                    : 0;

                if (!extensionDays || extensionDays < 1 || extensionDays > 10) {
                    e.preventDefault();
                    alert('This lesson is already past due. Please select a deadline extension of 1 to 10 days.');
                    return;
                }
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

                var startUtcField = document.getElementById('ia-one-on-one-start-utc');
                var endUtcField = document.getElementById('ia-one-on-one-end-utc');
                var timezoneField = document.getElementById('ia-one-on-one-timezone');

                var startUtc = buildUtcFromLocal(oneOnOneDate.value, oneOnOneTimeFrom.value);
                var endUtc = buildUtcFromLocal(oneOnOneDate.value, oneOnOneTimeUntil.value);

                if (!startUtc || !endUtc) {
                    e.preventDefault();
                    alert('Unable to convert the one-on-one time to UTC.');
                    return;
                }

                if (endUtc <= startUtc) {
                    e.preventDefault();
                    alert('One-on-one end time must be later than the start time.');
                    return;
                }

                if (startUtcField) {
                    startUtcField.value = startUtc;
                }
                if (endUtcField) {
                    endUtcField.value = endUtc;
                }
                if (timezoneField && window.Intl && Intl.DateTimeFormat) {
                    timezoneField.value = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
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