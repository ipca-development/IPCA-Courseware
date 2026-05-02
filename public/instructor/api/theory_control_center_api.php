<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/courseware_progression_v2.php';
require_once __DIR__ . '/../../../src/theory_ai_training_report_job.php';

cw_require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
$currentUserId = (int)($u['id'] ?? 0);

$allowedRoles = array('admin', 'supervisor', 'instructor', 'chief_instructor');
if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'forbidden',
        'message' => 'Instructor access required.'
    ]);
    exit;
}

$engine = new CoursewareProgressionV2($pdo);

tcc_ensure_theory_instructor_ai_cache($pdo);
tatr_ensure_table($pdo);

function tcc_json($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function tcc_int($value): int {
    return (int)($value ?? 0);
}

function tcc_str($value): string {
    return trim((string)($value ?? ''));
}

function tcc_ensure_theory_instructor_ai_cache(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS theory_instructor_ai_cache (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                cohort_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                lesson_id INT UNSIGNED NOT NULL,
                cache_kind VARCHAR(32) NOT NULL,
                fingerprint CHAR(64) NOT NULL,
                analysis_json MEDIUMTEXT NOT NULL,
                model VARCHAR(80) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_tcc_ai (cohort_id, user_id, lesson_id, cache_kind)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        // Table may already exist with different shape in rare deployments; AI cache is optional.
    }
}

function tcc_student_contact_row(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return ['display' => '', 'email' => ''];
    }
    try {
        $st = $pdo->prepare("
            SELECT
                email,
                COALESCE(NULLIF(TRIM(name), ''), email, CONCAT('User #', id)) AS display_name
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $st->execute([$userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return ['display' => 'User #' . $userId, 'email' => ''];
        }
        $name = trim((string)($r['display_name'] ?? ''));
        $em = trim((string)($r['email'] ?? ''));
        $line = $name;
        if ($em !== '') {
            $line = $name !== '' ? ($name . ' <' . $em . '>') : $em;
        }

        return ['display' => $line !== '' ? $line : $em, 'email' => $em, 'name' => $name];
    } catch (Throwable $e) {
        return ['display' => 'User #' . $userId, 'email' => ''];
    }
}

function tcc_decode_recipients_pretty(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $raw;
    }
    $parts = [];
    foreach ($decoded as $entry) {
        if (is_string($entry)) {
            $parts[] = $entry;
            continue;
        }
        if (!is_array($entry)) {
            continue;
        }
        $email = trim((string)($entry['email'] ?? $entry['address'] ?? ''));
        $name = trim((string)($entry['name'] ?? ''));
        if ($email !== '' && $name !== '') {
            $parts[] = $name . ' <' . $email . '>';
        } elseif ($email !== '') {
            $parts[] = $email;
        }
    }

    return $parts ? implode(', ', $parts) : $raw;
}

function tcc_enrich_training_email_row(PDO $pdo, array $row, int $studentUserId): array
{
    $student = tcc_student_contact_row($pdo, $studentUserId);
    $toPretty = tcc_decode_recipients_pretty((string)($row['recipients_to'] ?? ''));
    $row['recipients_to_display'] = $toPretty;
    $type = strtolower(trim((string)($row['recipient_type'] ?? '')));
    $emailType = strtolower((string)($row['email_type'] ?? ''));
    if ($type === '' && (strpos($emailType, 'instructor') !== false || strpos($emailType, 'chief') !== false)) {
        $type = 'instructor';
    }
    if ($type === 'instructor' || $toPretty !== '') {
        $row['recipient_display'] = $toPretty !== '' ? $toPretty : 'Instructor / staff (see To:)';
    } else {
        $row['recipient_display'] = $student['display'] !== '' ? $student['display'] : ($toPretty !== '' ? $toPretty : 'Student');
    }
    $sent = strtolower(trim((string)($row['sent_status'] ?? '')));
    $row['delivery_success'] = ($sent === 'sent' && trim((string)($row['sent_at'] ?? '')) !== '');
    $row['delivery_label'] = $row['delivery_success'] ? 'Sent successfully' : ($sent === 'failed' ? 'Send failed' : ($sent === 'queued' || $sent === 'pending' ? 'Queued / pending' : ($sent !== '' ? ucfirst($sent) : 'Unknown')));

    return $row;
}

function tcc_summary_cache_fingerprint(array $summaryRow): string
{
    $plain = tcc_strip_summary_for_ai((string)($summaryRow['summary_html'] ?? ''), (string)($summaryRow['summary_plain'] ?? ''));
    $updated = (string)($summaryRow['updated_at'] ?? $summaryRow['created_at'] ?? '');

    return hash('sha256', $updated . '|' . hash('sha256', $plain));
}

function tcc_attempts_cache_fingerprint(array $attempts): string
{
    $parts = [];
    foreach ($attempts as $a) {
        if (!is_array($a)) {
            continue;
        }
        $parts[] = (string)($a['id'] ?? '') . '|' . (string)($a['completed_at'] ?? '') . '|' . (string)($a['score_pct'] ?? '') . '|' . (string)($a['status'] ?? '') . '|' . (string)($a['formal_result_code'] ?? '');
    }
    sort($parts);

    return hash('sha256', implode("\n", $parts));
}

function tcc_ai_cache_get(PDO $pdo, int $cohortId, int $userId, int $lessonId, string $kind): ?array
{
    try {
        $st = $pdo->prepare('
            SELECT fingerprint, analysis_json, model, updated_at
            FROM theory_instructor_ai_cache
            WHERE cohort_id = ?
              AND user_id = ?
              AND lesson_id = ?
              AND cache_kind = ?
            LIMIT 1
        ');
        $st->execute([$cohortId, $userId, $lessonId, $kind]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function tcc_ai_cache_put(PDO $pdo, int $cohortId, int $userId, int $lessonId, string $kind, string $fingerprint, array $analysis, ?string $model): void
{
    try {
        $now = gmdate('Y-m-d H:i:s');
        $json = json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $st = $pdo->prepare('
            INSERT INTO theory_instructor_ai_cache
                (cohort_id, user_id, lesson_id, cache_kind, fingerprint, analysis_json, model, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                fingerprint = VALUES(fingerprint),
                analysis_json = VALUES(analysis_json),
                model = VALUES(model),
                updated_at = VALUES(updated_at)
        ');
        $st->execute([$cohortId, $userId, $lessonId, $kind, $fingerprint, $json, $model, $now, $now]);
    } catch (Throwable $e) {
        // ignore
    }
}

function tcc_normalize_summary_ai_response(array $analysis): array
{
    $u = trim((string)($analysis['understanding'] ?? ''));
    $du = trim((string)($analysis['deep_understanding'] ?? ''));
    $label = trim((string)($analysis['deep_understanding_label'] ?? ''));
    if ($label === '' && $du !== '') {
        $label = $du;
    }
    if ($label === '' && $u !== '') {
        $label = $u;
    }
    if ($label === '') {
        $label = 'Not evaluated';
    }
    $analysis['deep_understanding_label'] = $label;
    if (!isset($analysis['deep_understanding_score']) || $analysis['deep_understanding_score'] === '' || $analysis['deep_understanding_score'] === null) {
        $map = ['strong' => 92, 'good' => 78, 'partial' => 58, 'poor' => 38, 'not evaluated' => 0];
        $k = strtolower($label);
        foreach ($map as $word => $pct) {
            if (strpos($k, $word) !== false) {
                $analysis['deep_understanding_score'] = $pct;
                break;
            }
        }
        if (!isset($analysis['deep_understanding_score'])) {
            $analysis['deep_understanding_score'] = null;
        }
    }
    $sg = $analysis['substantially_good'] ?? null;
    if (!is_array($sg) && !empty($analysis['strong_points'])) {
        $analysis['substantially_good'] = is_array($analysis['strong_points']) ? $analysis['strong_points'] : [$analysis['strong_points']];
    }
    $sw = $analysis['substantially_weak'] ?? null;
    if (!is_array($sw) && !empty($analysis['weak_points'])) {
        $analysis['substantially_weak'] = is_array($analysis['weak_points']) ? $analysis['weak_points'] : [$analysis['weak_points']];
    }
    $sug = $analysis['improvement_suggestions'] ?? null;
    if (!is_array($sug)) {
        $fb = $analysis['suggestions'] ?? [];
        $analysis['improvement_suggestions'] = is_array($fb) ? $fb : ($fb !== '' ? [$fb] : []);
    }
    $analysis['analysis_status'] = 'generated';

    return $analysis;
}

function tcc_normalize_progress_test_ai_response(array $analysis): array
{
    $analysis['analysis_status'] = 'generated';
    foreach (['strong_points', 'weak_points', 'suggestions', 'official_references', 'integrity_concern_weak_points', 'integrity_concern_suggestions'] as $k) {
        if (isset($analysis[$k]) && !is_array($analysis[$k])) {
            $analysis[$k] = $analysis[$k] !== '' ? [$analysis[$k]] : [];
        }
        if (!isset($analysis[$k])) {
            $analysis[$k] = [];
        }
    }
    unset($analysis['evidence_notes']);

    return $analysis;
}

function tcc_is_placeholder_progress_feedback_line(string $line): bool
{
    $line = strtolower(trim($line));
    if ($line === '') {
        return true;
    }
    $needles = [
        'no specific summary issues',
        'no specific summary corrections',
        'no repeated misunderstanding',
        'could not be fully assessed',
        'you completed the progress test. review the areas below',
        'review the items that were incomplete or uncertain',
    ];
    foreach ($needles as $n) {
        if (strpos($line, $n) !== false) {
            return true;
        }
    }

    return false;
}

/** @return string[] */
function tcc_split_feedback_text_to_lines(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    $parts = preg_split('/\R+/u', $text) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p === '' || tcc_is_placeholder_progress_feedback_line($p)) {
            continue;
        }
        $p = preg_replace('/^[\-\*•]\s*/u', '', $p) ?? $p;
        if ($p !== '') {
            $out[] = $p;
        }
    }
    if (!$out && !tcc_is_placeholder_progress_feedback_line($text)) {
        return [$text];
    }

    return $out;
}

/**
 * STRONG / WEAK / SUGGESTIONS from debrief fields saved when each progress test was finalized.
 * $attempts should be newest-first (same order as progress_tests_v2 query).
 *
 * @param array<int,array<string,mixed>> $attempts
 * @return array{strong_points:string[],weak_points:string[],suggestions:string[]}
 */
function tcc_aggregate_saved_progress_test_feedback(array $attempts): array
{
    $strong = [];
    $weak = [];
    $suggestions = [];

    foreach ($attempts as $row) {
        if (!is_array($row)) {
            continue;
        }
        $n = (int)($row['attempt'] ?? 0);
        $pfx = $n > 0 ? ('Attempt ' . $n . ' — ') : '';

        foreach (tcc_split_feedback_text_to_lines((string)($row['summary_quality'] ?? '')) as $line) {
            $strong[] = $pfx . $line;
        }
        foreach (tcc_split_feedback_text_to_lines((string)($row['weak_areas'] ?? '')) as $line) {
            $weak[] = $pfx . $line;
        }
        foreach (tcc_split_feedback_text_to_lines((string)($row['summary_issues'] ?? '')) as $line) {
            $weak[] = $pfx . $line;
        }
        foreach (tcc_split_feedback_text_to_lines((string)($row['confirmed_misunderstandings'] ?? '')) as $line) {
            $weak[] = $pfx . $line;
        }
        foreach (tcc_split_feedback_text_to_lines((string)($row['summary_corrections'] ?? '')) as $line) {
            $suggestions[] = $pfx . $line;
        }

        $written = trim((string)($row['ai_summary'] ?? ''));
        if ($written !== '' && !tcc_is_placeholder_progress_feedback_line($written)) {
            $parts = preg_split('/\R{2,}/u', $written) ?: [];
            foreach ($parts as $para) {
                $para = trim($para);
                if ($para === '' || tcc_is_placeholder_progress_feedback_line($para)) {
                    continue;
                }
                $paraWithPfx = $pfx . $para;
                if (preg_match('/\b(review|revisit|focus on|study|practice|correct|revise|ensure|reread|re-read)\b/i', $para)) {
                    $suggestions[] = $paraWithPfx;
                } elseif (preg_match('/\b(incorrect|wrong|weak|missed|failed|did not|uncertain|incomplete|gap|error|struggle)\b/i', $para)) {
                    $weak[] = $paraWithPfx;
                } else {
                    $strong[] = $paraWithPfx;
                }
            }
        }
    }

    return [
        'strong_points' => array_values(array_unique($strong)),
        'weak_points' => array_values(array_unique($weak)),
        'suggestions' => array_values(array_unique($suggestions)),
    ];
}

function tcc_user_display_by_id(PDO $pdo, ?int $uid): string
{
    if ($uid === null || $uid <= 0) {
        return 'System';
    }
    $r = tcc_student_contact_row($pdo, $uid);

    return $r['display'] !== '' ? $r['display'] : ('User #' . $uid);
}

function tcc_student_name(array $row): string {
    $name = trim((string)($row['name'] ?? ''));
    if ($name !== '') return $name;

    $first = trim((string)($row['first_name'] ?? ''));
    $last = trim((string)($row['last_name'] ?? ''));
    $full = trim($first . ' ' . $last);

    if ($full !== '') return $full;

    return trim((string)($row['email'] ?? 'Student'));
}

function tcc_avatar_initials(string $name): string {
    $name = trim($name);
    if ($name === '') return 'S';

    $parts = preg_split('/\s+/', $name);
    if (!$parts) return strtoupper(substr($name, 0, 1));

    $a = strtoupper(substr((string)$parts[0], 0, 1));
    $b = isset($parts[1]) ? strtoupper(substr((string)$parts[1], 0, 1)) : '';

    return $a . $b;
}

function tcc_fetch_cohorts(PDO $pdo): array {
    $st = $pdo->query("
        SELECT
            co.id,
            co.name,
            co.start_date,
            co.end_date,
            c.title AS course_title,
            p.program_key,
            COALESCE(NULLIF(TRIM(co.timezone), ''), 'UTC') AS timezone
        FROM cohorts co
        JOIN courses c ON c.id = co.course_id
        JOIN programs p ON p.id = c.program_id
        ORDER BY co.start_date DESC, co.id DESC
    ");

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function tcc_cohort_students(PDO $pdo, int $cohortId): array {
    $st = $pdo->prepare("
        SELECT
            u.id,
            u.name,
            u.email,
            u.photo_path
        FROM cohort_students cs
        JOIN users u ON u.id = cs.user_id
        WHERE cs.cohort_id = ?
        ORDER BY u.name ASC, u.email ASC
    ");
    $st->execute([$cohortId]);

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function tcc_total_lessons(PDO $pdo, int $cohortId): int {
    $st = $pdo->prepare("
        SELECT COUNT(DISTINCT d.lesson_id)
        FROM cohort_lesson_deadlines d
        WHERE d.cohort_id = ?
    ");
    $st->execute([$cohortId]);

    return (int)$st->fetchColumn();
}

function tcc_passed_lessons(PDO $pdo, int $userId, int $cohortId): int {
    $st = $pdo->prepare("
        SELECT COUNT(DISTINCT pt.lesson_id)
        FROM progress_tests_v2 pt
        WHERE pt.user_id = ?
          AND pt.cohort_id = ?
          AND pt.status = 'completed'
          AND pt.pass_gate_met = 1
    ");
    $st->execute([$userId, $cohortId]);

    return (int)$st->fetchColumn();
}

function tcc_pending_actions(PDO $pdo, int $cohortId, ?int $userId = null): array {
    $params = [$cohortId];
    $userSql = '';

    if ($userId !== null && $userId > 0) {
        $userSql = ' AND sra.user_id = ? ';
        $params[] = $userId;
    }

    $st = $pdo->prepare("
        SELECT
            sra.*,
            u.name AS student_name,
            u.email AS student_email,
            u.photo_path AS student_photo_path,
            l.title AS lesson_title
        FROM student_required_actions sra
        JOIN users u ON u.id = sra.user_id
        LEFT JOIN lessons l ON l.id = sra.lesson_id
        WHERE sra.cohort_id = ?
          {$userSql}
          AND (
              (sra.action_type = 'deadline_reason_submission' AND sra.status IN ('pending','opened','completed'))
              OR
              (sra.action_type <> 'deadline_reason_submission' AND sra.status IN ('pending','opened'))
          )
        ORDER BY sra.created_at ASC, sra.id ASC
    ");
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function tcc_completed_actions(PDO $pdo, int $cohortId, int $userId): array {
    $st = $pdo->prepare("
        SELECT
            sra.*,
            l.title AS lesson_title
        FROM student_required_actions sra
        LEFT JOIN lessons l ON l.id = sra.lesson_id
        WHERE sra.cohort_id = ?
          AND sra.user_id = ?
          AND sra.status IN ('completed','approved')
        ORDER BY sra.updated_at DESC, sra.id DESC
        LIMIT 50
    ");
    $st->execute([$cohortId, $userId]);

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function tcc_student_attempt_stats(PDO $pdo, int $cohortId, int $userId): array {
    $st = $pdo->prepare("
        SELECT
            COUNT(*) AS total_attempts,
            SUM(CASE WHEN status='completed' AND pass_gate_met=0 THEN 1 ELSE 0 END) AS failed_attempts,
            AVG(CASE WHEN status='completed' AND score_pct IS NOT NULL THEN score_pct ELSE NULL END) AS avg_score,
            MAX(completed_at) AS last_completed_at
        FROM progress_tests_v2
        WHERE cohort_id = ?
          AND user_id = ?
          AND NOT (
              COALESCE(formal_result_code, '') = 'STALE_ABORTED'
              AND COALESCE(counts_as_unsat, 0) = 0
              AND COALESCE(pass_gate_met, 0) = 0
          )
    ");
    $st->execute([$cohortId, $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total_attempts' => (int)($row['total_attempts'] ?? 0),
        'failed_attempts' => (int)($row['failed_attempts'] ?? 0),
        'avg_score' => $row['avg_score'] === null ? null : round((float)$row['avg_score'], 1),
        'last_completed_at' => (string)($row['last_completed_at'] ?? ''),
    ];
}

/**
 * Open deadline-reason workflows (student or instructor still active).
 * Excludes approved rows so resolved history does not inflate risk forever.
 */
function tcc_deadline_reason_active_count(PDO $pdo, int $cohortId, int $userId): int {
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM student_required_actions
        WHERE cohort_id = ?
          AND user_id = ?
          AND action_type = 'deadline_reason_submission'
          AND status IN ('pending', 'opened', 'completed')
    ");
    $st->execute([$cohortId, $userId]);

    return (int)$st->fetchColumn();
}

/** Instructor-approved deadline reasons (historical; informational). */
function tcc_deadline_reason_resolved_count(PDO $pdo, int $cohortId, int $userId): int {
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM student_required_actions
        WHERE cohort_id = ?
          AND user_id = ?
          AND action_type = 'deadline_reason_submission'
          AND status = 'approved'
    ");
    $st->execute([$cohortId, $userId]);

    return (int)$st->fetchColumn();
}

/** Pending actions excluding deadline_reason_submission (those are counted separately). */
function tcc_pending_non_deadline_actions_count(array $pendingActions): int {
    $n = 0;
    foreach ($pendingActions as $row) {
        if ((string)($row['action_type'] ?? '') !== 'deadline_reason_submission') {
            $n++;
        }
    }

    return $n;
}

function tcc_motivation_detail_summary(array $stats, int $activeDeadlineIssues, int $otherPendingActions, int $score): string {
    $parts = [];

    if ($activeDeadlineIssues > 0) {
        $parts[] = $activeDeadlineIssues === 1
            ? '1 open deadline workflow'
            : $activeDeadlineIssues . ' open deadline workflows';
    }
    if ($otherPendingActions > 0) {
        $parts[] = $otherPendingActions === 1
            ? '1 other pending action'
            : $otherPendingActions . ' other pending actions';
    }
    $failed = (int)($stats['failed_attempts'] ?? 0);
    if ($failed > 0) {
        $parts[] = $failed === 1 ? '1 failed attempt' : $failed . ' failed attempts';
    }
    if ($stats['avg_score'] !== null && (float)$stats['avg_score'] < 70) {
        $parts[] = 'average score below 70%';
    }

    if ($parts === []) {
        return 'Engagement score ' . $score . '/100 (no negative signals in this snapshot).';
    }

    return 'Engagement score ' . $score . '/100 — based on ' . implode('; ', $parts) . '.';
}

function tcc_cohort_metric_averages(PDO $pdo, int $cohortId): array {
    $students = tcc_cohort_students($pdo, $cohortId);

    if (!$students) {
        return [
            'avg_active_deadline_issues' => 0,
            'avg_failed_attempts' => 0,
            'avg_score' => null,
        ];
    }

    $deadlineCounts = [];
    $failedCounts = [];
    $scores = [];

    foreach ($students as $s) {
        $sid = (int)$s['id'];
        $deadlineCounts[] = tcc_deadline_reason_active_count($pdo, $cohortId, $sid);

        $stats = tcc_student_attempt_stats($pdo, $cohortId, $sid);
        $failedCounts[] = (int)$stats['failed_attempts'];

        if ($stats['avg_score'] !== null) {
            $scores[] = (float)$stats['avg_score'];
        }
    }

    return [
        'avg_active_deadline_issues' => round(array_sum($deadlineCounts) / max(1, count($deadlineCounts)), 1),
        'avg_failed_attempts' => round(array_sum($failedCounts) / max(1, count($failedCounts)), 1),
        'avg_score' => $scores ? round(array_sum($scores) / count($scores), 1) : null,
    ];
}

/**
 * Engagement heuristic: open deadline workflows and other pending actions are counted separately
 * (avoid double-counting the same deadline_reason_submission rows).
 */
function tcc_motivation_signal(array $stats, int $activeDeadlineReasonCount, int $otherPendingActionsCount): array {
    $score = 100;

    $score -= min(35, $activeDeadlineReasonCount * 10);
    $score -= min(35, ((int)$stats['failed_attempts']) * 7);
    $score -= min(25, $otherPendingActionsCount * 10);

    if ($stats['avg_score'] !== null && (float)$stats['avg_score'] < 70) {
        $score -= 10;
    }

    $score = max(0, $score);

    if ($score >= 80) {
        return ['level' => 'strong', 'label' => 'Strong engagement', 'trend' => 'stable', 'score' => $score];
    }

    if ($score >= 60) {
        return ['level' => 'stable', 'label' => 'Stable', 'trend' => 'flat', 'score' => $score];
    }

    if ($score >= 40) {
        return ['level' => 'drifting', 'label' => 'Drifting', 'trend' => 'down', 'score' => $score];
    }

    return ['level' => 'needs_contact', 'label' => 'Needs instructor contact', 'trend' => 'down', 'score' => $score];
}

function tcc_action_severity(string $actionType): string {
    if ($actionType === 'instructor_approval') return 'high';
    if ($actionType === 'deadline_reason_submission') return 'medium';
    if ($actionType === 'remediation_acknowledgement') return 'medium';
    return 'low';
}

function tcc_recommended_action(string $actionType): string {
    if ($actionType === 'instructor_approval') return 'review_instructor_approval';
    if ($actionType === 'deadline_reason_submission') return 'review_deadline_reason';
    if ($actionType === 'remediation_acknowledgement') return 'monitor_remediation_completion';
    return 'review_required_action';
}

function tcc_official_flow_url(string $actionType, string $token): string {

    $actionType = trim($actionType);
    $token = trim($token);

    // ONLY instructor approval is allowed to open that page
    if ($actionType === 'instructor_approval' && $token !== '') {
        return '/instructor/instructor_approval.php?token=' . rawurlencode($token);
    }

    // EVERYTHING ELSE must stay inside TCC (no redirect)
    return '';
}



function tcc_safe_actions(string $actionType): array {
    if ($actionType === 'instructor_approval') {
        return ['review', 'grant_attempts', 'require_one_on_one', 'suspend_training'];
    }

    if ($actionType === 'deadline_reason_submission') {
        return ['review', 'extend_deadline', 'request_more_info'];
    }

    if ($actionType === 'remediation_acknowledgement') {
        return ['review', 'acknowledge_completion'];
    }

    return ['review'];
}

/**
 * UI grouping for bulk-select families. Uses action_type; instructor_approval titles
 * from deadline escalation use "Missed Deadline" and belong with deadline workflows.
 */
function tcc_blocker_family(string $actionType, string $title = ''): string
{
    if ($actionType === 'deadline_reason_submission') {
        return 'deadline_related';
    }
    if ($actionType === 'instructor_approval') {
        if (stripos($title, 'Missed Deadline') !== false) {
            return 'deadline_related';
        }
        return 'progress_test_failure_related';
    }
    return 'other';
}

function tcc_action_sort_rank(string $actionType): int
{
    if ($actionType === 'deadline_reason_submission') return 10;
    if ($actionType === 'instructor_approval') return 20;
    return 90;
}

function tcc_bulk_allowed_actions_for_item(array $item): array
{
    $actionType = (string)($item['action_type'] ?? '');
    $status = (string)($item['status'] ?? '');
    if ($actionType === 'deadline_reason_submission') {
        if (!in_array($status, ['pending', 'opened', 'completed'], true)) return [];
        return ['approve_deadline_reason_submission'];
    }
    if (!in_array($status, ['pending', 'opened'], true)) return [];

    if ($actionType === 'instructor_approval') return ['approve_additional_attempts'];
    return [];
}

/** Plain-language text for instructors (bulk preview / execute). */
function tcc_bulk_validation_message(?string $code): string
{
    if ($code === null || $code === '') {
        return '';
    }

    static $map = [
        'use_bulk_approve_additional_attempts_for_instructor_approval_rows' => 'Wrong bulk mode for this row: it is an instructor-approval item (for example “Missed final deadline”). Use “Instructor approval queue…” instead, with decision notes and at least +1 attempt.',
        'bulk_deadline_reason_only_for_deadline_reason_submission_actions' => 'Wrong bulk mode: “Approve deadline reason…” only applies to deadline-reason submission rows, not this action type.',
        'deadline_reason_not_actionable' => 'This deadline-reason row cannot be approved in its current status.',
        'use_bulk_approve_deadline_reason_for_deadline_reason_submission_rows' => 'Wrong bulk mode for this row: it is a deadline-reason submission. Use “Student submitted a deadline reason…” instead (and set +Days if you extend).',
        'bulk_additional_attempts_only_for_instructor_approval_actions' => 'Wrong bulk mode: “Instructor approval queue…” only applies to instructor-approval rows.',
        'granted_extra_attempts_must_be_at_least_1' => 'Enter +Attempts as at least 1 when using instructor-approval bulk.',
        'decision_notes_required' => 'Enter decision notes (required for instructor-approval bulk).',
        'not_allowed_for_action_type_or_status' => 'This row’s type or status does not allow the selected bulk action.',
        'unknown_bulk_action_code' => 'Unknown bulk action. Refresh the page and try again.',
    ];

    if (isset($map[$code])) {
        return $map[$code];
    }

    $pfxDeadline = 'deadline_reason_not_actionable_status_';
    if (strncmp($code, $pfxDeadline, strlen($pfxDeadline)) === 0) {
        $st = substr($code, strlen($pfxDeadline));
        return 'This deadline-reason row is in status “' . $st . '” and cannot be approved with this bulk action.';
    }

    $pfxInstructor = 'instructor_approval_not_pending_or_open_status_';
    if (strncmp($code, $pfxInstructor, strlen($pfxInstructor)) === 0) {
        $st = substr($code, strlen($pfxInstructor));
        return 'This instructor-approval row is in status “' . $st . '”. Bulk only applies when status is pending or opened.';
    }

    return $code;
}

function tcc_actor_ip(): string
{
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
}

function tcc_actor_user_agent(): string
{
    return trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function tcc_log_bulk_event(PDO $pdo, array $row, int $actorUserId, string $actionCode, string $batchId, array $payload, string $status): void
{
    $st = $pdo->prepare("
        INSERT INTO training_progression_events
        (
            user_id,
            cohort_id,
            lesson_id,
            progress_test_id,
            event_type,
            event_code,
            event_status,
            actor_type,
            actor_user_id,
            event_time,
            payload_json,
            legal_note
        )
        VALUES
        (
            :user_id,
            :cohort_id,
            :lesson_id,
            :progress_test_id,
            :event_type,
            :event_code,
            :event_status,
            :actor_type,
            :actor_user_id,
            :event_time,
            :payload_json,
            :legal_note
        )
    ");

    $st->execute([
        ':user_id' => (int)$row['user_id'],
        ':cohort_id' => (int)$row['cohort_id'],
        ':lesson_id' => (int)$row['lesson_id'],
        ':progress_test_id' => $row['progress_test_id'] === null ? null : (int)$row['progress_test_id'],
        ':event_type' => 'instructor_bulk_intervention',
        ':event_code' => $actionCode,
        ':event_status' => $status,
        ':actor_type' => 'admin',
        ':actor_user_id' => $actorUserId,
        ':event_time' => gmdate('Y-m-d H:i:s'),
        ':payload_json' => json_encode([
            'batch_id' => $batchId,
            'required_action_id' => (int)$row['id'],
            'action_type' => (string)$row['action_type'],
            'status_before' => (string)$row['status'],
            'requested_payload' => $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':legal_note' => 'Bulk intervention executed by instructor via Theory Control Center.',
    ]);
}

function tcc_system_watch(PDO $pdo, int $cohortId, ?int $userId = null): array {
    $issues = [];

    $params = [$cohortId];
    $userSql = '';
    if ($userId !== null && $userId > 0) {
        $userSql = ' AND la.user_id = ? ';
        $params[] = $userId;
    }

    $st = $pdo->prepare("
        SELECT
            la.user_id,
            la.cohort_id,
            la.lesson_id,
            la.completion_status,
            la.test_pass_status,
            u.name AS student_name,
            l.title AS lesson_title
        FROM lesson_activity la
        JOIN users u ON u.id = la.user_id
        LEFT JOIN lessons l ON l.id = la.lesson_id
        WHERE la.cohort_id = ?
          {$userSql}
          AND EXISTS (
              SELECT 1
              FROM progress_tests_v2 pt
              WHERE pt.user_id = la.user_id
                AND pt.cohort_id = la.cohort_id
                AND pt.lesson_id = la.lesson_id
                AND pt.status = 'completed'
                AND pt.pass_gate_met = 1
          )
          /* Exclude normal states after a canonical PASS: lesson completed, or test passed and summary review pending. */
          AND NOT (
              COALESCE(la.test_pass_status, '') = 'passed'
              AND COALESCE(la.completion_status, '') IN ('completed', 'awaiting_summary_review')
          )
        LIMIT 100
    ");
    $st->execute($params);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $issues[] = [
            'issue_type' => 'pass_exists_projection_not_completed',
            'severity' => 'high',
            'student_id' => (int)$row['user_id'],
            'cohort_id' => (int)$row['cohort_id'],
            'lesson_id' => (int)$row['lesson_id'],
            'student_name' => tcc_student_name($row),
            'lesson_title' => (string)($row['lesson_title'] ?? ''),
            'summary' => 'Canonical PASS exists, but lesson_activity still looks wrong (test not marked passed, or lesson stuck outside awaiting-summary / completed).',
            'recommended_safe_action' => 'recompute_projection',
        ];
    }

    $params = [$cohortId];
    $userSql = '';
    if ($userId !== null && $userId > 0) {
        $userSql = ' AND pt.user_id = ? ';
        $params[] = $userId;
    }

    $st = $pdo->prepare("
        SELECT
            pt.user_id,
            pt.cohort_id,
            pt.lesson_id,
            COUNT(*) AS active_count,
            u.name AS student_name,
            l.title AS lesson_title
        FROM progress_tests_v2 pt
        JOIN users u ON u.id = pt.user_id
        LEFT JOIN lessons l ON l.id = pt.lesson_id
        WHERE pt.cohort_id = ?
          {$userSql}
          AND pt.status IN ('ready','in_progress','processing','preparing')
        GROUP BY pt.user_id, pt.cohort_id, pt.lesson_id
        HAVING COUNT(*) > 1
        LIMIT 100
    ");
    $st->execute($params);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $issues[] = [
            'issue_type' => 'duplicate_active_attempts',
            'severity' => 'high',
            'student_id' => (int)$row['user_id'],
            'cohort_id' => (int)$row['cohort_id'],
            'lesson_id' => (int)$row['lesson_id'],
            'student_name' => tcc_student_name($row),
            'lesson_title' => (string)($row['lesson_title'] ?? ''),
            'active_count' => (int)$row['active_count'],
            'summary' => 'More than one active progress test attempt exists for this lesson.',
            'recommended_safe_action' => 'inspect_attempts_and_cleanup_stale',
        ];
    }

    $params = [$cohortId];
    $userSql = '';
    if ($userId !== null && $userId > 0) {
        $userSql = ' AND sra.user_id = ? ';
        $params[] = $userId;
    }

    $st = $pdo->prepare("
        SELECT
            sra.user_id,
            sra.cohort_id,
            sra.lesson_id,
            sra.action_type,
            sra.status,
            u.name AS student_name,
            l.title AS lesson_title
        FROM student_required_actions sra
        JOIN users u ON u.id = sra.user_id
        LEFT JOIN lessons l ON l.id = sra.lesson_id
        WHERE sra.cohort_id = ?
          {$userSql}
          AND sra.status IN ('pending','opened')
          AND EXISTS (
              SELECT 1
              FROM progress_tests_v2 pt
              WHERE pt.user_id = sra.user_id
                AND pt.cohort_id = sra.cohort_id
                AND pt.lesson_id = sra.lesson_id
                AND pt.status = 'completed'
                AND pt.pass_gate_met = 1
          )
        LIMIT 100
    ");
    $st->execute($params);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $issues[] = [
            'issue_type' => 'pending_action_on_passed_lesson',
            'severity' => 'high',
            'student_id' => (int)$row['user_id'],
            'cohort_id' => (int)$row['cohort_id'],
            'lesson_id' => (int)$row['lesson_id'],
            'student_name' => tcc_student_name($row),
            'lesson_title' => (string)($row['lesson_title'] ?? ''),
            'action_type' => (string)$row['action_type'],
            'summary' => 'A pending/open required action exists even though the lesson has a canonical PASS.',
            'recommended_safe_action' => 'inspect_required_action_and_recompute',
        ];
    }

	
	    $params = array($cohortId);
    $userSql = '';

    if ($userId !== null && $userId > 0) {
        $userSql = ' AND pt.user_id = ? ';
        $params[] = $userId;
    }

    $st = $pdo->prepare("
        SELECT
            pt.id AS test_id,
            pt.user_id,
            pt.user_id AS student_id,
            pt.cohort_id,
            pt.lesson_id,
            pt.attempt,
            pt.status,
            pt.created_at,
            pt.started_at,
            pt.updated_at,
            u.name AS student_name,
            u.email AS student_email,
            l.title AS lesson_title,
            (
                SELECT COUNT(*)
                FROM progress_tests_v2 pass_pt
                WHERE pass_pt.user_id = pt.user_id
                  AND pass_pt.cohort_id = pt.cohort_id
                  AND pass_pt.lesson_id = pt.lesson_id
                  AND pass_pt.status = 'completed'
                  AND pass_pt.pass_gate_met = 1
            ) AS canonical_pass_count
        FROM progress_tests_v2 pt
        JOIN users u ON u.id = pt.user_id
        LEFT JOIN lessons l ON l.id = pt.lesson_id
        WHERE pt.cohort_id = ?
          {$userSql}
          AND pt.status IN ('ready','in_progress','processing','preparing')
          AND COALESCE(pt.updated_at, pt.started_at, pt.created_at) < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
        ORDER BY COALESCE(pt.updated_at, pt.started_at, pt.created_at) ASC
        LIMIT 100
    ");
    $st->execute($params);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $passCount = (int)($row['canonical_pass_count'] ?? 0);

        if ($passCount <= 0) {
            $issues[] = array(
                'issue_type' => 'old_active_progress_test_attempt',
                'type' => 'old_active_progress_test_attempt',
                'blocker_category' => 'ambiguous',
                'severity' => 'high',
                'repair_allowed' => false,
                'repair_code' => 'inspect_only',
                'student_id' => (int)$row['user_id'],
                'cohort_id' => (int)$row['cohort_id'],
                'lesson_id' => (int)$row['lesson_id'],
                'student_name' => tcc_student_name($row),
                'lesson_title' => (string)($row['lesson_title'] ?? ''),
                'title' => 'Old active progress test attempt',
                'summary' => 'Old active progress test attempt exists without canonical PASS. Inspect only.',
                'recurrence_key' => 'old_active_progress_test_attempt|cohort:' . (int)$row['cohort_id'] . '|lesson:' . (int)$row['lesson_id'],
                'evidence' => array(
                    'test_id' => (int)$row['test_id'],
                    'attempt' => (int)$row['attempt'],
                    'status' => (string)$row['status'],
                    'created_at' => (string)($row['created_at'] ?? ''),
                    'started_at' => (string)($row['started_at'] ?? ''),
                    'updated_at' => (string)($row['updated_at'] ?? ''),
                    'canonical_pass_count' => $passCount,
                    'stale_hours_threshold' => 24
                )
            );
            continue;
        }

        $issues[] = array(
            'issue_type' => 'old_active_progress_test_attempt',
            'type' => 'old_active_progress_test_attempt',
            'blocker_category' => 'stale_bug',
            'severity' => 'medium',
            'repair_allowed' => true,
            'repair_code' => 'cleanup_old_active_attempt_after_pass',
            'student_id' => (int)$row['user_id'],
            'cohort_id' => (int)$row['cohort_id'],
            'lesson_id' => (int)$row['lesson_id'],
            'student_name' => tcc_student_name($row),
            'lesson_title' => (string)($row['lesson_title'] ?? ''),
            'title' => 'Stale progress test attempt after PASS',
            'summary' => 'Old active progress test attempt exists after canonical PASS. Eligible for one-click stale cleanup.',
            'recurrence_key' => 'old_active_progress_test_attempt|cohort:' . (int)$row['cohort_id'] . '|lesson:' . (int)$row['lesson_id'],
            'evidence' => array(
                'test_id' => (int)$row['test_id'],
                'attempt' => (int)$row['attempt'],
                'status' => (string)$row['status'],
                'created_at' => (string)($row['created_at'] ?? ''),
                'started_at' => (string)($row['started_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
                'canonical_pass_count' => $passCount,
                'stale_hours_threshold' => 24
            )
        );
    }
	
	
	
    return $issues;
}


function tcc_deadline_delta(?string $completedAt, ?string $deadlineUtc): array {
    $completedAt = trim((string)$completedAt);
    $deadlineUtc = trim((string)$deadlineUtc);

    if ($completedAt === '') {
        return [
            'label' => 'Not completed',
            'days' => null,
            'class' => 'neutral'
        ];
    }

    if ($deadlineUtc === '') {
        return [
            'label' => 'Completed',
            'days' => null,
            'class' => 'ok'
        ];
    }

    try {
        $completed = new DateTime($completedAt, new DateTimeZone('UTC'));
        $deadline = new DateTime($deadlineUtc, new DateTimeZone('UTC'));
        $seconds = $completed->getTimestamp() - $deadline->getTimestamp();
        $days = (int)ceil(abs($seconds) / 86400);

        if ($seconds <= 0) {
            return [
                'label' => ($days <= 0 ? 'On time' : $days . ' day' . ($days === 1 ? '' : 's') . ' early'),
                'days' => -$days,
                'class' => 'ok'
            ];
        }

        return [
            'label' => $days . ' day' . ($days === 1 ? '' : 's') . ' late',
            'days' => $days,
            'class' => 'danger'
        ];
    } catch (Throwable $e) {
        return [
            'label' => 'Unknown',
            'days' => null,
            'class' => 'neutral'
        ];
    }
}

function tcc_student_lesson_timeline(PDO $pdo, int $cohortId, int $studentId): array {
    $lessonStmt = $pdo->prepare("
        SELECT
            d.lesson_id,
            d.deadline_utc AS original_deadline_utc,
            d.sort_order AS cohort_lesson_sort_order,
            l.external_lesson_id,
            l.title AS lesson_title,
            c.id AS course_id,
            c.title AS course_title,
            c.sort_order AS course_sort_order,
            la.completed_at,
            la.effective_deadline_utc,
            la.extension_count,
            la.summary_status,
            la.test_pass_status,
            la.completion_status,
            ls.review_status AS summary_review_status,
            ls.review_score AS summary_review_score,
            ls.updated_at AS summary_updated_at
        FROM cohort_lesson_deadlines d
        JOIN lessons l ON l.id = d.lesson_id
        JOIN courses c ON c.id = l.course_id
        LEFT JOIN lesson_activity la
               ON la.user_id = ?
              AND la.cohort_id = d.cohort_id
              AND la.lesson_id = d.lesson_id
        LEFT JOIN lesson_summaries ls
               ON ls.user_id = ?
              AND ls.cohort_id = d.cohort_id
              AND ls.lesson_id = d.lesson_id
        WHERE d.cohort_id = ?
        ORDER BY c.sort_order ASC, c.id ASC, d.sort_order ASC, d.id ASC
    ");
    $lessonStmt->execute([$studentId, $studentId, $cohortId]);
    $rows = $lessonStmt->fetchAll(PDO::FETCH_ASSOC);

    $lessonIds = array_map(static fn($row): int => (int)$row['lesson_id'], $rows);

    if (!$lessonIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($lessonIds), '?'));

    $testMap = [];
    foreach ($lessonIds as $lessonId) {
        $testMap[$lessonId] = [
            'attempt_count' => 0,
            'last_score' => null,
            'last_status' => '',
            'last_completed_at' => '',
            'passed' => false
        ];
    }

    $testParams = array_merge([$studentId, $cohortId], $lessonIds);
    $testStmt = $pdo->prepare("
        SELECT
            id,
            lesson_id,
            attempt,
            status,
            pass_gate_met,
            score_pct,
            completed_at,
            formal_result_code,
            counts_as_unsat
        FROM progress_tests_v2
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id IN (" . $placeholders . ")
        ORDER BY lesson_id ASC, id DESC
    ");
    $testStmt->execute($testParams);

    foreach ($testStmt->fetchAll(PDO::FETCH_ASSOC) as $testRow) {
        $lessonId = (int)$testRow['lesson_id'];
        $formalCode = (string)($testRow['formal_result_code'] ?? '');
        $countsAsUnsat = (int)($testRow['counts_as_unsat'] ?? 0);
        $passGateMet = (int)($testRow['pass_gate_met'] ?? 0);
        $isStaleNoise = ($formalCode === 'STALE_ABORTED' && $countsAsUnsat === 0 && $passGateMet === 0);

        if ($isStaleNoise) {
            continue;
        }

        $testMap[$lessonId]['attempt_count']++;

        if ($testMap[$lessonId]['last_status'] === '') {
            $testMap[$lessonId]['last_status'] = (string)($testRow['status'] ?? '');
            $testMap[$lessonId]['last_score'] = ($testRow['score_pct'] === null ? null : (int)$testRow['score_pct']);
            $testMap[$lessonId]['last_completed_at'] = (string)($testRow['completed_at'] ?? '');
        }

        if ((string)($testRow['status'] ?? '') === 'completed' && $passGateMet === 1) {
            $testMap[$lessonId]['passed'] = true;
        }
    }

    $interventionMap = array_fill_keys($lessonIds, 0);
    $actionParams = array_merge([$studentId, $cohortId], $lessonIds);
    $actionStmt = $pdo->prepare("
        SELECT lesson_id, COUNT(*) AS intervention_count
        FROM student_required_actions
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id IN (" . $placeholders . ")
        GROUP BY lesson_id
    ");
    $actionStmt->execute($actionParams);
    foreach ($actionStmt->fetchAll(PDO::FETCH_ASSOC) as $actionRow) {
        $interventionMap[(int)$actionRow['lesson_id']] = (int)$actionRow['intervention_count'];
    }

    $overrideMap = array_fill_keys($lessonIds, 0);
    try {
        $overrideParams = array_merge([$studentId, $cohortId], $lessonIds);
        $overrideStmt = $pdo->prepare("
            SELECT lesson_id, COUNT(*) AS override_count
            FROM student_lesson_deadline_overrides
            WHERE user_id = ?
              AND cohort_id = ?
              AND is_active = 1
              AND lesson_id IN (" . $placeholders . ")
            GROUP BY lesson_id
        ");
        $overrideStmt->execute($overrideParams);
        foreach ($overrideStmt->fetchAll(PDO::FETCH_ASSOC) as $overrideRow) {
            $overrideMap[(int)$overrideRow['lesson_id']] = (int)$overrideRow['override_count'];
        }
    } catch (Throwable $e) {
        $overrideMap = array_fill_keys($lessonIds, 0);
    }

    $timeline = [];
    foreach ($rows as $row) {
        $lessonId = (int)$row['lesson_id'];
        $effectiveDeadline = trim((string)($row['effective_deadline_utc'] ?? ''));
        if ($effectiveDeadline === '') {
            $effectiveDeadline = (string)($row['original_deadline_utc'] ?? '');
        }

        $completedAt = (string)($row['completed_at'] ?? '');
        $delta = tcc_deadline_delta($completedAt, $effectiveDeadline);
        $summaryStatus = (string)($row['summary_review_status'] ?? '');
        if ($summaryStatus === '') {
            $summaryStatus = (string)($row['summary_status'] ?? '');
        }

        $extensionCount = (int)($row['extension_count'] ?? 0);
        if (isset($overrideMap[$lessonId]) && $overrideMap[$lessonId] > $extensionCount) {
            $extensionCount = $overrideMap[$lessonId];
        }

        $timeline[] = [
            'course_id' => (int)$row['course_id'],
            'course_title' => (string)$row['course_title'],
            'lesson_id' => $lessonId,
            'external_lesson_id' => (int)($row['external_lesson_id'] ?? 0),
            'lesson_title' => (string)$row['lesson_title'],
            'original_deadline_utc' => (string)($row['original_deadline_utc'] ?? ''),
            'effective_deadline_utc' => $effectiveDeadline,
            'completed_at' => $completedAt,
            'deadline_delta_label' => $delta['label'],
            'deadline_delta_days' => $delta['days'],
            'deadline_delta_class' => $delta['class'],
            'extension_count' => $extensionCount,
            'summary_status' => $summaryStatus,
            'summary_score' => ($row['summary_review_score'] === null ? null : (int)$row['summary_review_score']),
            'summary_updated_at' => (string)($row['summary_updated_at'] ?? ''),
            'test_status' => $testMap[$lessonId]['last_status'] ?? '',
            'test_passed' => !empty($testMap[$lessonId]['passed']),
            'last_score' => $testMap[$lessonId]['last_score'] ?? null,
            'attempt_count' => (int)($testMap[$lessonId]['attempt_count'] ?? 0),
            'intervention_count' => (int)($interventionMap[$lessonId] ?? 0),
            'completion_status' => (string)($row['completion_status'] ?? '')
        ];
    }

    return $timeline;
}


function tcc_audio_url(?string $audioPath): string {
    $audioPath = trim((string)$audioPath);
    if ($audioPath === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $audioPath)) {
        return $audioPath;
    }
    $base = 'https://ipca-media.nyc3.cdn.digitaloceanspaces.com/';
    return $base . ltrim($audioPath, '/');
}

function tcc_email_recipient_label(array $row): string {
    $recipientType = strtolower(trim((string)($row['recipient_type'] ?? '')));
    if ($recipientType === 'student' || $recipientType === 'instructor') {
        return $recipientType;
    }

    $emailType = strtolower(trim((string)($row['email_type'] ?? '')));
    if (strpos($emailType, 'chief') !== false || strpos($emailType, 'instructor') !== false) {
        return 'instructor';
    }

    return 'student';
}

function tcc_email_delivery_status(array $row): string {
    $sentStatus = strtolower(trim((string)($row['sent_status'] ?? '')));
    if (in_array($sentStatus, ['sent', 'failed', 'queued', 'pending'], true)) {
        return $sentStatus === 'queued' || $sentStatus === 'pending' ? 'sent' : $sentStatus;
    }

    return trim((string)($row['sent_at'] ?? '')) !== '' ? 'sent' : 'failed';
}

function tcc_email_readable_body(array $row): string {
    $html = trim((string)($row['body_html'] ?? ''));
    $text = trim((string)($row['body_text'] ?? ''));
    if ($text !== '') {
        return $text;
    }

    if ($html === '') {
        return 'No rendered email body available.';
    }

    $normalized = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $normalized = preg_replace('/<\/p>/i', "\n\n", (string)$normalized);
    $plain = strip_tags((string)$normalized);
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = preg_replace('/[ \t]+/', ' ', $plain);
    $plain = preg_replace('/\R{3,}/', "\n\n", $plain);
    $plain = trim((string)$plain);
    return $plain !== '' ? $plain : 'No rendered email body available.';
}

function tcc_lesson_identity(PDO $pdo, int $cohortId, int $lessonId): array {
    $st = $pdo->prepare("
        SELECT
            l.id AS lesson_id,
            l.external_lesson_id,
            l.title AS lesson_title,
            c.id AS course_id,
            c.title AS course_title,
            d.deadline_utc AS original_deadline_utc,
            d.sort_order AS cohort_lesson_sort_order
        FROM cohort_lesson_deadlines d
        JOIN lessons l ON l.id = d.lesson_id
        JOIN courses c ON c.id = l.course_id
        WHERE d.cohort_id = ?
          AND d.lesson_id = ?
        LIMIT 1
    ");
    $st->execute([$cohortId, $lessonId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

function tcc_lesson_summary_detail(PDO $pdo, int $cohortId, int $studentId, int $lessonId): array {
    $lesson = tcc_lesson_identity($pdo, $cohortId, $lessonId);

    $st = $pdo->prepare("
        SELECT *
        FROM lesson_summaries
        WHERE cohort_id = ?
          AND user_id = ?
          AND lesson_id = ?
        LIMIT 1
    ");
    $st->execute([$cohortId, $studentId, $lessonId]);
    $summary = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    $versions = [];
    try {
        $vs = $pdo->prepare("
            SELECT *
            FROM lesson_summary_versions
            WHERE cohort_id = ?
              AND user_id = ?
              AND lesson_id = ?
            ORDER BY id DESC
            LIMIT 10
        ");
        $vs->execute([$cohortId, $studentId, $lessonId]);
        $versions = $vs->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $versions = [];
    }

    $aiInterpretation = [
        'analysis_status' => 'pending',
        'copy_paste_likelihood' => '',
        'ai_tool_likelihood' => '',
        'instructor_quick_take' => '',
        'substantially_good' => [],
        'substantially_weak' => [],
        'improvement_suggestions' => [],
    ];
    $summaryFingerprint = '';
    $aiCacheStale = false;

    if (is_array($summary) && !empty($summary)) {
        $summaryFingerprint = tcc_summary_cache_fingerprint($summary);
        $cached = tcc_ai_cache_get($pdo, $cohortId, $studentId, $lessonId, 'summary');
        if ($cached && hash_equals((string)($cached['fingerprint'] ?? ''), $summaryFingerprint)) {
            $decoded = json_decode((string)($cached['analysis_json'] ?? '{}'), true);
            if (is_array($decoded) && !empty($decoded)) {
                $aiInterpretation = array_merge($aiInterpretation, tcc_normalize_summary_ai_response($decoded));
                $aiInterpretation['cached_at_utc'] = (string)($cached['updated_at'] ?? '');
                $aiInterpretation['cache_model'] = (string)($cached['model'] ?? '');
            }
        } else {
            $aiCacheStale = $cached !== null;
        }
    }

    return [
        'lesson' => $lesson,
        'summary' => $summary,
        'versions' => $versions,
        'summary_content_fingerprint' => $summaryFingerprint,
        'ai_cache_stale' => $aiCacheStale,
        'ai_interpretation' => $aiInterpretation,
    ];
}

function tcc_lesson_attempts_detail(PDO $pdo, int $cohortId, int $studentId, int $lessonId): array {
    $lesson = tcc_lesson_identity($pdo, $cohortId, $lessonId);

    $attemptStmt = $pdo->prepare("
        SELECT *
        FROM progress_tests_v2
        WHERE cohort_id = ?
          AND user_id = ?
          AND lesson_id = ?
        ORDER BY attempt DESC, id DESC
        LIMIT 20
    ");
    $attemptStmt->execute([$cohortId, $studentId, $lessonId]);
    $attempts = $attemptStmt->fetchAll(PDO::FETCH_ASSOC);

    $attemptIds = [];
    foreach ($attempts as $a) {
        $attemptIds[] = (int)($a['id'] ?? 0);
    }
    $attemptIds = array_values(array_filter($attemptIds));

    $itemsByAttempt = [];
    if ($attemptIds) {
        $placeholders = implode(',', array_fill(0, count($attemptIds), '?'));
        try {
            $itemStmt = $pdo->prepare("
                SELECT *
                FROM progress_test_items_v2
                WHERE test_id IN (" . $placeholders . ")
                ORDER BY test_id DESC, idx ASC, id ASC
            ");
            $itemStmt->execute($attemptIds);
            foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $testId = (int)($item['test_id'] ?? 0);
                if (!isset($itemsByAttempt[$testId])) {
                    $itemsByAttempt[$testId] = [];
                }
                if (isset($item['audio_path'])) {
                    $item['audio_url'] = tcc_audio_url((string)$item['audio_path']);
                } else {
                    $item['audio_url'] = '';
                }
                $itemsByAttempt[$testId][] = $item;
            }
        } catch (Throwable $e) {
            $itemsByAttempt = [];
        }
    }

    foreach ($attempts as &$attempt) {
        $testId = (int)($attempt['id'] ?? 0);
        $attempt['items'] = $itemsByAttempt[$testId] ?? [];
        $formalCode = (string)($attempt['formal_result_code'] ?? '');
        $countsAsUnsat = (int)($attempt['counts_as_unsat'] ?? 0);
        $passGateMet = (int)($attempt['pass_gate_met'] ?? 0);
        $attempt['is_stale_attempt'] = ($formalCode === 'STALE_ABORTED' && $countsAsUnsat === 0 && $passGateMet === 0);
    }
    unset($attempt);

    $fp = tcc_attempts_cache_fingerprint($attempts);
    $cachedPt = tcc_ai_cache_get($pdo, $cohortId, $studentId, $lessonId, 'progress_test');
    $oralAnalysis = [];
    $oralStale = false;
    if ($cachedPt && hash_equals((string)($cachedPt['fingerprint'] ?? ''), $fp)) {
        $decodedOral = json_decode((string)($cachedPt['analysis_json'] ?? '{}'), true);
        $oralAnalysis = is_array($decodedOral) ? tcc_normalize_progress_test_ai_response($decodedOral) : [];
    } elseif ($cachedPt !== null) {
        $oralStale = true;
    }

    $knowledgeFeedback = tcc_aggregate_saved_progress_test_feedback($attempts);

    return [
        'lesson' => $lesson,
        'attempts' => $attempts,
        'attempts_fingerprint' => $fp,
        'oral_analysis' => $oralAnalysis,
        'oral_analysis_stale' => $oralStale,
        'knowledge_feedback' => $knowledgeFeedback,
    ];
}

function tcc_lesson_interventions_detail(PDO $pdo, int $cohortId, int $studentId, int $lessonId = 0): array {
    $lesson = $lessonId > 0 ? tcc_lesson_identity($pdo, $cohortId, $lessonId) : [];
    $lessonWhere = $lessonId > 0 ? ' AND lesson_id = ? ' : '';

    $actionsParams = $lessonId > 0 ? [$cohortId, $studentId, $lessonId] : [$cohortId, $studentId];
    $actionsStmt = $pdo->prepare("
        SELECT *
        FROM student_required_actions
        WHERE cohort_id = ?
          AND user_id = ?
          " . $lessonWhere . "
        ORDER BY created_at ASC, id ASC
        LIMIT 100
    ");
    $actionsStmt->execute($actionsParams);
    $requiredActions = $actionsStmt->fetchAll(PDO::FETCH_ASSOC);

    $deadlineOverrides = [];
    try {
        $overrideParams = $lessonId > 0 ? [$cohortId, $studentId, $lessonId] : [$cohortId, $studentId];
        $overrideStmt = $pdo->prepare("
            SELECT *
            FROM student_lesson_deadline_overrides
            WHERE cohort_id = ?
              AND user_id = ?
              " . $lessonWhere . "
            ORDER BY granted_at ASC, id ASC
            LIMIT 100
        ");
        $overrideStmt->execute($overrideParams);
        $deadlineOverrides = $overrideStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $deadlineOverrides = [];
    }

    $emails = [];
    try {
        $emailParams = $lessonId > 0 ? [$cohortId, $studentId, $lessonId] : [$cohortId, $studentId];
        $emailStmt = $pdo->prepare("
            SELECT *
            FROM training_progression_emails
            WHERE cohort_id = ?
              AND user_id = ?
              " . $lessonWhere . "
            ORDER BY created_at ASC, id ASC
            LIMIT 100
        ");
        $emailStmt->execute($emailParams);
        $emails = $emailStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($emails as &$emailRow) {
            $emailRow['recipient_label'] = tcc_email_recipient_label($emailRow);
            $emailRow['delivery_status'] = tcc_email_delivery_status($emailRow);
            $emailRow['sent_timestamp'] = (string)($emailRow['sent_at'] ?? $emailRow['created_at'] ?? '');
            $emailRow['readable_body'] = tcc_email_readable_body($emailRow);
            if (trim((string)($emailRow['title'] ?? '')) === '') {
                $emailRow['title'] = (string)($emailRow['subject'] ?? $emailRow['email_type'] ?? 'Progression Email');
            }
            $emailRow = tcc_enrich_training_email_row($pdo, $emailRow, $studentId);
        }
        unset($emailRow);
    } catch (Throwable $e) {
        $emails = [];
    }

    $events = [];
    try {
        $eventParams = $lessonId > 0 ? [$cohortId, $studentId, $lessonId] : [$cohortId, $studentId];
        $eventStmt = $pdo->prepare("
            SELECT *
            FROM training_progression_events
            WHERE cohort_id = ?
              AND user_id = ?
              " . $lessonWhere . "
            ORDER BY event_time ASC, id ASC
            LIMIT 100
        ");
        $eventStmt->execute($eventParams);
        $events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $events = [];
    }

    return [
        'lesson' => $lesson,
        'required_actions' => $requiredActions,
        'deadline_overrides' => $deadlineOverrides,
        'emails' => $emails,
        'events' => $events
    ];
}

function tcc_lesson_course_meta_by_ids(PDO $pdo, array $lessonIds): array
{
    $lessonIds = array_values(array_unique(array_filter(array_map('intval', $lessonIds))));
    if (!$lessonIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($lessonIds), '?'));
    $st = $pdo->prepare("
        SELECT
            l.id,
            l.title AS lesson_title,
            l.course_id,
            c.title AS course_title
        FROM lessons l
        LEFT JOIN courses c ON c.id = l.course_id
        WHERE l.id IN ({$placeholders})
    ");
    $st->execute($lessonIds);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int)$row['id']] = [
            'lesson_title' => (string)($row['lesson_title'] ?? ''),
            'course_id' => (int)($row['course_id'] ?? 0),
            'course_title' => (string)($row['course_title'] ?? ''),
        ];
    }

    return $map;
}

function tcc_audit_pick_ts(string ...$candidates): string
{
    foreach ($candidates as $c) {
        $c = trim((string)$c);
        if ($c !== '') {
            return $c;
        }
    }

    return '1970-01-01 00:00:00';
}

/**
 * Intervention / email / progression audit for one student.
 *
 * Options:
 * - since_days (int): only rows with activity in the last N UTC days; 0 = no date filter (heavier).
 * - timeline_limit (int): page size for merged timeline (default 20).
 * - timeline_offset (int): offset into merged timeline sorted newest-first (default 0).
 * - per_source_cap (int): max rows pulled per underlying table after filters (default 600).
 *
 * Previously each source used ORDER BY … ASC LIMIT 500, which dropped newer emails/events once
 * a student had more than 500 historical rows. Resend + automation audit rows live in the newest slice.
 */
function tcc_student_interventions_audit(PDO $pdo, int $cohortId, int $studentId, array $options = []): array
{
    $sinceDays = array_key_exists('since_days', $options) ? max(0, (int)$options['since_days']) : 7;
    $timelineLimit = isset($options['timeline_limit']) ? max(1, min(100, (int)$options['timeline_limit'])) : 20;
    $timelineOffset = isset($options['timeline_offset']) ? max(0, (int)$options['timeline_offset']) : 0;
    $perSourceCap = isset($options['per_source_cap']) ? max(80, min(1200, (int)$options['per_source_cap'])) : 600;

    $dateSqlActions = '';
    $dateSqlOverrides = '';
    $dateSqlEmails = '';
    $dateSqlEvents = '';
    if ($sinceDays > 0) {
        $d = (int)$sinceDays;
        $dateSqlActions = " AND COALESCE(updated_at, created_at) >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$d} DAY) ";
        $dateSqlOverrides = " AND COALESCE(granted_at, created_at) >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$d} DAY) ";
        $dateSqlEmails = " AND COALESCE(sent_at, created_at) >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$d} DAY) ";
        $dateSqlEvents = " AND COALESCE(event_time, created_at) >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$d} DAY) ";
    }

    $actionsStmt = $pdo->prepare("
        SELECT *
        FROM student_required_actions
        WHERE cohort_id = ?
          AND user_id = ?
          {$dateSqlActions}
        ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
        LIMIT {$perSourceCap}
    ");
    $actionsStmt->execute([$cohortId, $studentId]);
    $requiredActions = $actionsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $deadlineOverrides = [];
    try {
        $overrideStmt = $pdo->prepare("
            SELECT *
            FROM student_lesson_deadline_overrides
            WHERE cohort_id = ?
              AND user_id = ?
              {$dateSqlOverrides}
            ORDER BY COALESCE(granted_at, created_at) DESC, id DESC
            LIMIT {$perSourceCap}
        ");
        $overrideStmt->execute([$cohortId, $studentId]);
        $deadlineOverrides = $overrideStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        usort($deadlineOverrides, static function (array $a, array $b): int {
            return strcmp(
                (string)tcc_audit_pick_ts((string)($a['granted_at'] ?? ''), (string)($a['created_at'] ?? '')),
                (string)tcc_audit_pick_ts((string)($b['granted_at'] ?? ''), (string)($b['created_at'] ?? ''))
            );
        });
        $deadlineExtSeq = [];
        foreach ($deadlineOverrides as &$drow) {
            $lid = (int)($drow['lesson_id'] ?? 0);
            $deadlineExtSeq[$lid] = ($deadlineExtSeq[$lid] ?? 0) + 1;
            $drow['extension_sequence'] = $deadlineExtSeq[$lid];
        }
        unset($drow);
    } catch (Throwable $e) {
        $deadlineOverrides = [];
    }

    $emails = [];
    try {
        $emailStmt = $pdo->prepare("
            SELECT *
            FROM training_progression_emails
            WHERE cohort_id = ?
              AND user_id = ?
              {$dateSqlEmails}
        ORDER BY COALESCE(sent_at, created_at) DESC, id DESC
            LIMIT {$perSourceCap}
        ");
        $emailStmt->execute([$cohortId, $studentId]);
        $emails = $emailStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($emails as &$emailRow) {
            $emailRow['recipient_label'] = tcc_email_recipient_label($emailRow);
            $emailRow['delivery_status'] = tcc_email_delivery_status($emailRow);
            $emailRow['sent_timestamp'] = (string)($emailRow['sent_at'] ?? $emailRow['created_at'] ?? '');
            $emailRow['readable_body'] = tcc_email_readable_body($emailRow);
            if (trim((string)($emailRow['title'] ?? '')) === '') {
                $emailRow['title'] = (string)($emailRow['subject'] ?? $emailRow['email_type'] ?? 'Progression Email');
            }
            $emailRow = tcc_enrich_training_email_row($pdo, $emailRow, $studentId);
        }
        unset($emailRow);
    } catch (Throwable $e) {
        $emails = [];
    }

    $events = [];
    try {
        $eventStmt = $pdo->prepare("
            SELECT *
            FROM training_progression_events
            WHERE cohort_id = ?
              AND user_id = ?
              {$dateSqlEvents}
        ORDER BY COALESCE(event_time, created_at) DESC, id DESC
            LIMIT {$perSourceCap}
        ");
        $eventStmt->execute([$cohortId, $studentId]);
        $events = $eventStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $events = [];
    }

    $lessonIds = [];
    foreach ($requiredActions as $r) {
        $lessonIds[] = (int)($r['lesson_id'] ?? 0);
    }
    foreach ($deadlineOverrides as $r) {
        $lessonIds[] = (int)($r['lesson_id'] ?? 0);
    }
    foreach ($emails as $r) {
        $lessonIds[] = (int)($r['lesson_id'] ?? 0);
    }
    foreach ($events as $r) {
        $lessonIds[] = (int)($r['lesson_id'] ?? 0);
    }
    $lessonMeta = tcc_lesson_course_meta_by_ids($pdo, $lessonIds);

    $timelineFull = [];

    foreach ($requiredActions as $row) {
        $lid = (int)($row['lesson_id'] ?? 0);
        $lm = $lessonMeta[$lid] ?? ['lesson_title' => '', 'course_id' => 0, 'course_title' => ''];
        $sortTs = tcc_audit_pick_ts((string)($row['created_at'] ?? ''), (string)($row['updated_at'] ?? ''));
        $timelineFull[] = [
            'kind' => 'required_action',
            'sort_ts' => $sortTs,
            'lesson_id' => $lid,
            'lesson_title' => (string)($lm['lesson_title'] ?? ''),
            'course_id' => (int)($lm['course_id'] ?? 0),
            'course_title' => (string)($lm['course_title'] ?? ''),
            'label' => (string)($row['title'] ?? $row['action_type'] ?? 'Required action'),
            'meta' => trim((string)($row['action_type'] ?? '') . ' · status ' . (string)($row['status'] ?? '')),
            'payload' => $row,
        ];
    }

    foreach ($deadlineOverrides as $row) {
        $lid = (int)($row['lesson_id'] ?? 0);
        $lm = $lessonMeta[$lid] ?? ['lesson_title' => '', 'course_id' => 0, 'course_title' => ''];
        $sortTs = tcc_audit_pick_ts((string)($row['granted_at'] ?? ''), (string)($row['created_at'] ?? ''));
        $timelineFull[] = [
            'kind' => 'deadline_override',
            'sort_ts' => $sortTs,
            'lesson_id' => $lid,
            'lesson_title' => (string)($lm['lesson_title'] ?? ''),
            'course_id' => (int)($lm['course_id'] ?? 0),
            'course_title' => (string)($lm['course_title'] ?? ''),
            'label' => (string)($row['override_type'] ?? 'Deadline override'),
            'meta' => 'New deadline: ' . (string)($row['new_deadline_utc'] ?? '—'),
            'payload' => $row,
        ];
    }

    foreach ($emails as $row) {
        $lid = (int)($row['lesson_id'] ?? 0);
        $lm = $lessonMeta[$lid] ?? ['lesson_title' => '', 'course_id' => 0, 'course_title' => ''];
        // Align with SQL filter COALESCE(sent_at, created_at): prefer sent_at so client date presets
        // (auditRowMatchesPreset) match rows included by the server; resends often touch sent_at.
        $sortTs = tcc_audit_pick_ts((string)($row['sent_at'] ?? ''), (string)($row['created_at'] ?? ''));
        $timelineFull[] = [
            'kind' => 'email',
            'sort_ts' => $sortTs,
            'lesson_id' => $lid,
            'lesson_title' => (string)($lm['lesson_title'] ?? ''),
            'course_id' => (int)($lm['course_id'] ?? 0),
            'course_title' => (string)($lm['course_title'] ?? ''),
            'label' => (string)($row['title'] ?? $row['subject'] ?? $row['email_type'] ?? 'Email'),
            'meta' => trim((string)($row['email_type'] ?? '') . ' · ' . (string)($row['delivery_status'] ?? $row['sent_status'] ?? '')),
            'payload' => $row,
        ];
    }

    foreach ($events as $row) {
        $lid = (int)($row['lesson_id'] ?? 0);
        $lm = $lessonMeta[$lid] ?? ['lesson_title' => '', 'course_id' => 0, 'course_title' => ''];
        $sortTs = tcc_audit_pick_ts((string)($row['event_time'] ?? ''), (string)($row['created_at'] ?? ''));
        $timelineFull[] = [
            'kind' => 'progression_event',
            'sort_ts' => $sortTs,
            'lesson_id' => $lid,
            'lesson_title' => (string)($lm['lesson_title'] ?? ''),
            'course_id' => (int)($lm['course_id'] ?? 0),
            'course_title' => (string)($lm['course_title'] ?? ''),
            'label' => (string)($row['event_code'] ?? $row['event_type'] ?? 'Event'),
            'meta' => trim((string)($row['event_type'] ?? '') . ' · ' . (string)($row['event_status'] ?? '')),
            'payload' => $row,
        ];
    }

    usort($timelineFull, static function (array $a, array $b): int {
        return strcmp((string)$b['sort_ts'], (string)$a['sort_ts']);
    });

    $engineTimeline = [];
    $instructorTimeline = [];
    foreach ($timelineFull as $row) {
        if (($row['kind'] ?? '') === 'progression_event') {
            $engineTimeline[] = $row;
        } else {
            $instructorTimeline[] = $row;
        }
    }

    $instructorMergedCount = count($instructorTimeline);
    $window = array_slice($instructorTimeline, $timelineOffset, $timelineLimit + 1);
    $hasMore = count($window) > $timelineLimit;
    if ($hasMore) {
        array_pop($window);
    }

    $engineCap = ($timelineOffset === 0) ? array_slice($engineTimeline, 0, 120) : [];
    $outTimeline = array_merge($window, $engineCap);
    usort($outTimeline, static function (array $a, array $b): int {
        return strcmp((string)$b['sort_ts'], (string)$a['sort_ts']);
    });

    return [
        'required_actions' => $requiredActions,
        'deadline_overrides' => $deadlineOverrides,
        'emails' => $emails,
        'events' => $events,
        'timeline' => $outTimeline,
        'timeline_total_merged' => count($timelineFull),
        'timeline_instructor_merged' => $instructorMergedCount,
        'has_more' => $hasMore,
        'since_days' => $sinceDays,
        'timeline_limit' => $timelineLimit,
        'timeline_offset' => $timelineOffset,
        'next_offset' => $timelineOffset + count($window),
        'counts' => [
            'required_actions' => count($requiredActions),
            'deadline_overrides' => count($deadlineOverrides),
            'emails' => count($emails),
            'events' => count($events),
            'timeline' => count($outTimeline),
            'timeline_merged' => count($timelineFull),
        ],
    ];
}

function tcc_ai_env_key(): string
{
    $keys = [
        'CW_OPENAI_API_KEY',
        'OPENAI_API_KEY',
        'IPCA_OPENAI_API_KEY',
    ];

    foreach ($keys as $key) {
        $value = trim((string)getenv($key));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function tcc_strip_summary_for_ai(string $html, string $plain): string
{
    $text = trim($plain);
    if ($text === '') {
        $text = trim(strip_tags($html));
    }

    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\R{3,}/', "\n\n", $text);
    $text = trim((string)$text);

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, 9000);
    }

    return substr($text, 0, 9000);
}

function tcc_word_count_for_ai(string $text): int
{
    $text = trim($text);
    if ($text === '') {
        return 0;
    }

    $parts = preg_split('/\s+/u', $text);
    return is_array($parts) ? count(array_filter($parts)) : 0;
}

function tcc_text_similarity_pct(string $a, string $b): float
{
    $a = trim(preg_replace('/\s+/', ' ', strtolower($a)));
    $b = trim(preg_replace('/\s+/', ' ', strtolower($b)));

    if ($a === '' || $b === '') {
        return 0.0;
    }

    if (function_exists('mb_substr')) {
        $a = mb_substr($a, 0, 4000);
        $b = mb_substr($b, 0, 4000);
    } else {
        $a = substr($a, 0, 4000);
        $b = substr($b, 0, 4000);
    }

    similar_text($a, $b, $pct);
    return round((float)$pct, 1);
}

function tcc_extract_response_text(array $response): string
{
    if (isset($response['output_text']) && is_string($response['output_text'])) {
        return trim($response['output_text']);
    }

    $chunks = [];

    if (!empty($response['output']) && is_array($response['output'])) {
        foreach ($response['output'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (!empty($item['content']) && is_array($item['content'])) {
                foreach ($item['content'] as $contentItem) {
                    if (!is_array($contentItem)) {
                        continue;
                    }

                    if (isset($contentItem['text']) && is_string($contentItem['text'])) {
                        $chunks[] = $contentItem['text'];
                    }
                }
            }
        }
    }

    return trim(implode("\n", $chunks));
}

function tcc_safe_json_from_ai(string $text): array
{
    $text = trim($text);

    if ($text === '') {
        return [];
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    if (preg_match('/\{.*\}/s', $text, $m)) {
        $decoded = json_decode($m[0], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [
        'analysis_status' => 'unparsed',
        'raw_text' => $text,
    ];
}


require_once __DIR__ . '/../../../src/openai.php';

function tcc_call_openai_summary_analysis(array $payload): array
{
    $summaryText = trim((string)($payload['summary']['text'] ?? ''));
    if ($summaryText === '') {
        return [
            'ok' => false,
            'error' => 'empty_summary_text',
            'message' => 'The lesson summary text is empty.',
        ];
    }

    $studentName = (string)($payload['student']['student_name'] ?? 'Student');
    $lessonTitle = (string)($payload['lesson']['lesson_title'] ?? 'Lesson');
    $courseTitle = (string)($payload['lesson']['course_title'] ?? 'Module');
    $reviewStatus = (string)($payload['summary']['review_status'] ?? '');
    $reviewScore = $payload['summary']['review_score'] ?? null;
    $wordCount = (int)($payload['summary']['word_count'] ?? 0);
    $similarityContext = $payload['cohort_similarity_context'] ?? [];

    $promptPayload = [
        'task' => 'Analyze an aviation theory lesson summary for instructor advisory review only.',
        'important_rules' => [
            'Return valid JSON only. No markdown. No prose outside JSON.',
            'Do not accuse the student of misconduct. Use likelihood language only.',
            'This is advisory only and must not be treated as canonical progression truth.',
            'Base the educational quality assessment on the supplied summary text only.',
        ],
        'required_json_schema' => [
            'copy_paste_likelihood' => 'Low|Medium|High|Not evaluated',
            'ai_tool_likelihood' => 'Low|Medium|High|Not evaluated',
            'similarity' => 'Low|Medium|High|Not evaluated',
            'highest_similarity' => 'Low|Medium|High|Not evaluated',
            'highest_similarity_student' => 'student name or null',
            'highest_similarity_pct' => 'number 0-100',
            'understanding' => 'Poor|Partial|Good|Strong|Not evaluated',
            'deep_understanding' => 'Poor|Partial|Good|Strong|Not evaluated',
            'quality_feedback' => 'short instructor-facing paragraph',
            'substantially_good' => ['short bullet strings'],
            'substantially_weak' => ['short bullet strings'],
            'suggestions' => ['short actionable improvement suggestions'],
            'red_flags' => ['short caution signals, empty if none'],
        ],
        'student' => [
            'name' => $studentName,
        ],
        'lesson' => [
            'module' => $courseTitle,
            'lesson' => $lessonTitle,
        ],
        'current_review_state' => [
            'review_status' => $reviewStatus,
            'review_score' => $reviewScore,
            'word_count' => $wordCount,
        ],
        'cohort_similarity_context' => $similarityContext,
        'student_summary_text' => $summaryText,
    ];

    try {
        $model = cw_openai_model();

        $resp = cw_openai_responses([
            'model' => $model,
            'input' => json_encode($promptPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'max_output_tokens' => 900,
        ]);

        $analysis = cw_openai_extract_json_text($resp);

        if (!is_array($analysis) || !$analysis) {
            return [
                'ok' => false,
                'error' => 'empty_or_invalid_ai_json',
                'message' => 'OpenAI returned no usable JSON analysis.',
            ];
        }

        return [
            'ok' => true,
            'model' => $model,
            'response_id' => (string)($resp['id'] ?? ''),
            'analysis' => $analysis,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => 'openai_request_failed',
            'message' => $e->getMessage(),
        ];
    }
}

function tcc_call_openai_progress_test_analysis(array $payload): array
{
    $attempts = $payload['attempts'] ?? [];
    if (!is_array($attempts) || !$attempts) {
        return [
            'ok' => false,
            'error' => 'no_attempts',
            'message' => 'No progress test attempts to analyze.',
        ];
    }

    $lessonTitle = (string)($payload['lesson']['lesson_title'] ?? 'Lesson');
    $courseTitle = (string)($payload['lesson']['course_title'] ?? 'Module');
    $studentName = (string)($payload['student']['student_name'] ?? 'Student');

    $attemptSummaries = [];
    foreach ($attempts as $a) {
        if (!is_array($a)) {
            continue;
        }
        $items = $a['items'] ?? [];
        $snippets = [];
        if (is_array($items)) {
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $t = trim((string)($it['transcript_text'] ?? $it['answer_text'] ?? ''));
                if ($t !== '') {
                    $snippets[] = mb_substr($t, 0, 600);
                }
                if (count($snippets) >= 4) {
                    break;
                }
            }
        }
        $attemptSummaries[] = [
            'attempt' => $a['attempt'] ?? null,
            'status' => $a['status'] ?? '',
            'formal_result_code' => $a['formal_result_code'] ?? '',
            'score_pct' => $a['score_pct'] ?? null,
            'pass_gate_met' => $a['pass_gate_met'] ?? null,
            'oral_transcript_excerpts' => $snippets,
        ];
    }

    $promptPayload = [
        'task' => 'Analyze oral progress test attempts for instructor-only integrity likelihoods (aviation theory course). Do NOT restate lesson knowledge strengths/weaknesses here — those come from the saved test debrief elsewhere.',
        'important_rules' => [
            'Return valid JSON only. No markdown.',
            'Do not accuse the student; use likelihood language.',
            'Advisory only — not proof of cheating.',
            'If overall_integrity_risk is High, populate integrity_concern_weak_points and integrity_concern_suggestions with short non-accusatory instructor bullets (empty arrays when risk is not High).',
        ],
        'required_json_schema' => [
            'natural_speech_likelihood' => 'Low|Medium|High|Not evaluated (likelihood transcript reflects natural spontaneous speech vs stiff/script-like)',
            'script_reading_likelihood' => 'Low|Medium|High|Not evaluated',
            'multiple_voices_or_coaching_likelihood' => 'Low|Medium|High|Not evaluated',
            'overall_integrity_risk' => 'Low|Medium|High|Not evaluated',
            'official_references' => ['short refs e.g. ICAO Annex / EASA AMC GM when regulation helps frame review; may be empty'],
            'integrity_concern_weak_points' => ['only when overall_integrity_risk is High: 2-4 advisory bullets for instructor review, else []'],
            'integrity_concern_suggestions' => ['only when overall_integrity_risk is High: 2-4 follow-up suggestions (human verification, audio review), else []'],
        ],
        'student' => ['name' => $studentName],
        'lesson' => ['module' => $courseTitle, 'lesson' => $lessonTitle],
        'attempts' => $attemptSummaries,
    ];

    try {
        $model = cw_openai_model();

        $resp = cw_openai_responses([
            'model' => $model,
            'input' => json_encode($promptPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'max_output_tokens' => 1100,
        ]);

        $analysis = cw_openai_extract_json_text($resp);

        if (!is_array($analysis) || !$analysis) {
            return [
                'ok' => false,
                'error' => 'empty_or_invalid_ai_json',
                'message' => 'OpenAI returned no usable JSON for oral analysis.',
            ];
        }

        return [
            'ok' => true,
            'model' => $model,
            'response_id' => (string)($resp['id'] ?? ''),
            'analysis' => $analysis,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => 'openai_request_failed',
            'message' => $e->getMessage(),
        ];
    }
}



function tcc_similarity_context_for_summary(PDO $pdo, int $cohortId, int $studentId, int $lessonId, string $summaryText): array
{
    $out = [
        'highest_similarity' => 'Not evaluated',
        'highest_similarity_student' => null,
        'highest_similarity_pct' => 0,
        'matches' => [],
    ];

    if (trim($summaryText) === '') {
        return $out;
    }

    try {
        $st = $pdo->prepare("
            SELECT
                ls.user_id,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))), ''), u.email, CONCAT('User #', u.id)) AS student_name,
                ls.summary_html,
                ls.summary_plain
            FROM lesson_summaries ls
            JOIN users u ON u.id = ls.user_id
            WHERE ls.cohort_id = ?
              AND ls.lesson_id = ?
              AND ls.user_id <> ?
            LIMIT 50
        ");
        $st->execute([$cohortId, $lessonId, $studentId]);

        $best = null;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $otherText = tcc_strip_summary_for_ai((string)($row['summary_html'] ?? ''), (string)($row['summary_plain'] ?? ''));
            $pct = tcc_text_similarity_pct($summaryText, $otherText);

            $match = [
                'student_id' => (int)$row['user_id'],
                'student_name' => (string)$row['student_name'],
                'similarity_pct' => $pct,
            ];

            $out['matches'][] = $match;

            if ($best === null || $pct > (float)$best['similarity_pct']) {
                $best = $match;
            }
        }

        usort($out['matches'], function ($a, $b) {
            return (float)$b['similarity_pct'] <=> (float)$a['similarity_pct'];
        });

        $out['matches'] = array_slice($out['matches'], 0, 5);

        if ($best !== null) {
            $out['highest_similarity_student'] = $best['student_name'];
            $out['highest_similarity_pct'] = $best['similarity_pct'];

            if ($best['similarity_pct'] >= 82) {
                $out['highest_similarity'] = 'High';
            } elseif ($best['similarity_pct'] >= 62) {
                $out['highest_similarity'] = 'Medium';
            } elseif ($best['similarity_pct'] > 0) {
                $out['highest_similarity'] = 'Low';
            }
        }
    } catch (Throwable $e) {
        $out['highest_similarity'] = 'Not evaluated';
    }

    return $out;
}

$action = tcc_str($_GET['action'] ?? '');

if ($action === '') {
    tcc_json([
        'ok' => false,
        'error' => 'missing_action',
        'allowed_actions' => [
            'cohort_overview',
            'action_queue',
            'bulk_action_preview',
            'bulk_action_execute',
            'student_snapshot',
            'system_watch',
            'student_lessons',
            'lesson_summary_detail',
            'lesson_attempts_detail',
            'lesson_interventions_detail',
            'student_interventions_audit',
            'debug_report',
            'ai_summary_analysis',
            'ai_progress_test_analysis'
        ]
    ], 400);
}

try {
    if ($action === 'cohort_overview') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);

        if ($cohortId <= 0) {
            tcc_json([
                'ok' => true,
                'action' => 'cohort_overview',
                'cohorts' => tcc_fetch_cohorts($pdo)
            ]);
        }

        $students = tcc_cohort_students($pdo, $cohortId);
        $totalLessons = tcc_total_lessons($pdo, $cohortId);
        $studentRows = [];

        $blockedCount = 0;
        $atRiskCount = 0;
        $onTrackCount = 0;

        foreach ($students as $student) {
            $sid = (int)$student['id'];
            $name = tcc_student_name($student);
            $passed = tcc_passed_lessons($pdo, $sid, $cohortId);
            $progressPct = $totalLessons > 0 ? (int)round(($passed / $totalLessons) * 100) : 0;
            $actions = tcc_pending_actions($pdo, $cohortId, $sid);
            $stats = tcc_student_attempt_stats($pdo, $cohortId, $sid);
            $activeDeadlineIssues = tcc_deadline_reason_active_count($pdo, $cohortId, $sid);
            $otherPending = tcc_pending_non_deadline_actions_count($actions);
            $motivation = tcc_motivation_signal($stats, $activeDeadlineIssues, $otherPending);
            $motivationDetail = tcc_motivation_detail_summary($stats, $activeDeadlineIssues, $otherPending, (int)$motivation['score']);

            $state = 'on_track';
            if (count($actions) > 0) {
                $state = 'blocked';
                $blockedCount++;
            } elseif ($motivation['level'] === 'drifting' || $motivation['level'] === 'needs_contact') {
                $state = 'at_risk';
                $atRiskCount++;
            } else {
                $onTrackCount++;
            }

            $studentRows[] = [
                'student_id' => $sid,
                'name' => $name,
                'email' => (string)($student['email'] ?? ''),
                'photo_path' => (string)($student['photo_path'] ?? ''),
                'avatar_initials' => tcc_avatar_initials($name),
                'progress_pct' => $progressPct,
                'passed_lessons' => $passed,
                'total_lessons' => $totalLessons,
                'pending_action_count' => count($actions),
                'failed_attempts' => $stats['failed_attempts'],
                'avg_score' => $stats['avg_score'],
                'active_deadline_issues' => $activeDeadlineIssues,
                'deadline_misses' => $activeDeadlineIssues,
                'motivation' => $motivation,
                'motivation_detail' => $motivationDetail,
                'state' => $state,
            ];
        }

        usort($studentRows, function ($a, $b) {
            if ($a['progress_pct'] === $b['progress_pct']) {
                return strcmp($a['name'], $b['name']);
            }
            return $b['progress_pct'] <=> $a['progress_pct'];
        });

        $cohortTimezone = 'UTC';
        try {
            $tzStmt = $pdo->prepare('SELECT timezone FROM cohorts WHERE id = ? LIMIT 1');
            $tzStmt->execute([$cohortId]);
            $rawTz = trim((string)$tzStmt->fetchColumn());
            if ($rawTz !== '') {
                $cohortTimezone = $rawTz;
            }
        } catch (Throwable $e) {
            $cohortTimezone = 'UTC';
        }

        tcc_json([
            'ok' => true,
            'action' => 'cohort_overview',
            'cohort_id' => $cohortId,
            'cohort_timezone' => $cohortTimezone,
            'summary' => [
                'student_count' => count($students),
                'total_lessons' => $totalLessons,
                'on_track_count' => $onTrackCount,
                'at_risk_count' => $atRiskCount,
                'blocked_count' => $blockedCount,
            ],
            'students' => $studentRows,
        ]);
    }

    if ($action === 'action_queue') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        if ($cohortId <= 0) {
            tcc_json(['ok' => false, 'error' => 'missing_cohort_id'], 400);
        }

        $actions = tcc_pending_actions($pdo, $cohortId);
        $items = [];

        foreach ($actions as $row) {
            $studentName = tcc_student_name([
                'name' => $row['student_name'] ?? '',
                'email' => $row['student_email'] ?? ''
            ]);

            $actionType = (string)$row['action_type'];

            $items[] = [
                'required_action_id' => (int)$row['id'],
                'student_id' => (int)$row['user_id'],
                'student_name' => $studentName,
                'photo_path' => (string)($row['student_photo_path'] ?? ''),
                'avatar_initials' => tcc_avatar_initials($studentName),
                'cohort_id' => (int)$row['cohort_id'],
                'lesson_id' => (int)$row['lesson_id'],
                'lesson_title' => (string)($row['lesson_title'] ?? ''),
                'action_type' => $actionType,
				'status' => (string)$row['status'],
				'severity' => tcc_action_severity($actionType),
                'blocker_family' => tcc_blocker_family($actionType, (string)($row['title'] ?? '')),
                'sort_rank' => tcc_action_sort_rank($actionType),
				'official_flow_url' => tcc_official_flow_url($actionType, (string)($row['token'] ?? '')),
                'reason' => (string)($row['title'] ?? $actionType),
                'recommended_action' => tcc_recommended_action($actionType),
                'safe_actions' => tcc_safe_actions($actionType),
                'bulk_allowed_actions' => tcc_bulk_allowed_actions_for_item($row),
                'created_at' => (string)($row['created_at'] ?? ''),
                'opened_at' => (string)($row['opened_at'] ?? ''),
                'token' => (string)($row['token'] ?? ''),
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $ar = (int)($a['sort_rank'] ?? 99);
            $br = (int)($b['sort_rank'] ?? 99);
            if ($ar !== $br) return $ar <=> $br;

            $severityRank = ['high' => 1, 'medium' => 2, 'low' => 3];
            $sa = $severityRank[(string)($a['severity'] ?? '')] ?? 9;
            $sb = $severityRank[(string)($b['severity'] ?? '')] ?? 9;
            if ($sa !== $sb) return $sa <=> $sb;

            $at = strtotime((string)($a['created_at'] ?? '')) ?: PHP_INT_MAX;
            $bt = strtotime((string)($b['created_at'] ?? '')) ?: PHP_INT_MAX;
            if ($at !== $bt) return $at <=> $bt;

            return strcmp((string)($a['student_name'] ?? ''), (string)($b['student_name'] ?? ''));
        });

        tcc_json([
            'ok' => true,
            'action' => 'action_queue',
            'cohort_id' => $cohortId,
            'count' => count($items),
            'items' => $items,
        ]);
    }

    if ($action === 'bulk_action_preview' || $action === 'bulk_action_execute') {
        if (function_exists('set_time_limit')) {
            @set_time_limit($action === 'bulk_action_execute' ? 900 : 300);
        }
        if ($action === 'bulk_action_execute' && function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) $payload = [];

        $cohortId = (int)($payload['cohort_id'] ?? 0);
        $requiredActionIds = array_values(array_unique(array_map('intval', (array)($payload['required_action_ids'] ?? []))));
        $actionCode = trim((string)($payload['bulk_action_code'] ?? ''));
        $decisionNotes = trim((string)($payload['decision_notes'] ?? ''));
        $grantedExtraAttempts = max(0, min(5, (int)($payload['granted_extra_attempts'] ?? 0)));
        $deadlineExtensionDays = max(0, min(10, (int)($payload['deadline_extension_days'] ?? 0)));

        if ($cohortId <= 0 || !$requiredActionIds || $actionCode === '') {
            tcc_json(['ok' => false, 'error' => 'missing_bulk_payload'], 400);
        }

        $ph = implode(',', array_fill(0, count($requiredActionIds), '?'));
        $params = array_merge([$cohortId], $requiredActionIds);
        $st = $pdo->prepare("
            SELECT id,user_id,cohort_id,lesson_id,progress_test_id,action_type,status,title,token,created_at,updated_at
            FROM student_required_actions
            WHERE cohort_id = ?
              AND id IN ($ph)
            ORDER BY created_at ASC, id ASC
        ");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        $allowedCount = 0;
        foreach ($rows as $row) {
            $allowedActions = tcc_bulk_allowed_actions_for_item($row);
            $rowActionType = (string)$row['action_type'];
            $rowStatus = (string)$row['status'];
            $validation = null;

            // Validate chosen bulk code against this row (specific messages first; do not short-circuit with a generic code).
            if ($actionCode === 'approve_deadline_reason_submission') {
                if ($rowActionType !== 'deadline_reason_submission') {
                    $validation = $rowActionType === 'instructor_approval'
                        ? 'use_bulk_approve_additional_attempts_for_instructor_approval_rows'
                        : 'bulk_deadline_reason_only_for_deadline_reason_submission_actions';
                } elseif (!in_array($rowStatus, ['pending', 'opened', 'completed'], true)) {
                    $validation = 'deadline_reason_not_actionable_status_' . $rowStatus;
                } elseif (!in_array($actionCode, $allowedActions, true)) {
                    $validation = 'deadline_reason_not_actionable';
                }
            } elseif ($actionCode === 'approve_additional_attempts') {
                if ($rowActionType !== 'instructor_approval') {
                    $validation = $rowActionType === 'deadline_reason_submission'
                        ? 'use_bulk_approve_deadline_reason_for_deadline_reason_submission_rows'
                        : 'bulk_additional_attempts_only_for_instructor_approval_actions';
                } elseif (!in_array($rowStatus, ['pending', 'opened'], true)) {
                    $validation = 'instructor_approval_not_pending_or_open_status_' . $rowStatus;
                } elseif ($grantedExtraAttempts < 1) {
                    $validation = 'granted_extra_attempts_must_be_at_least_1';
                } elseif ($decisionNotes === '') {
                    $validation = 'decision_notes_required';
                } elseif (!in_array($actionCode, $allowedActions, true)) {
                    $validation = 'not_allowed_for_action_type_or_status';
                }
            } else {
                $validation = 'unknown_bulk_action_code';
            }

            $isAllowed = $validation === null;
            if ($isAllowed) {
                $allowedCount++;
            }

            $results[] = [
                'required_action_id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'lesson_id' => (int)$row['lesson_id'],
                'action_type' => (string)$row['action_type'],
                'status' => (string)$row['status'],
                'title' => (string)$row['title'],
                'allowed' => $validation === null,
                'validation_error' => $validation,
                'validation_message' => tcc_bulk_validation_message($validation),
            ];
        }

        if ($action === 'bulk_action_preview') {
            tcc_json([
                'ok' => true,
                'action' => 'bulk_action_preview',
                'bulk_action_code' => $actionCode,
                'requested_count' => count($requiredActionIds),
                'matched_count' => count($rows),
                'allowed_count' => $allowedCount,
                'results' => $results,
            ]);
        }

        $batchId = 'BULK-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        $ip = tcc_actor_ip();
        $ua = tcc_actor_user_agent();
        $executeResults = [];

        foreach ($rows as $row) {
            $rowId = (int)$row['id'];
            $rowResult = null;
            foreach ($results as $r) {
                if ((int)$r['required_action_id'] === $rowId) {
                    $rowResult = $r;
                    break;
                }
            }
            if (!$rowResult || empty($rowResult['allowed'])) {
                $skipCode = (string)($rowResult['validation_error'] ?? 'validation_failed');
                $skipMsg = tcc_bulk_validation_message($skipCode !== '' ? $skipCode : null);
                if ($skipMsg === '' && $skipCode !== '') {
                    $skipMsg = $skipCode;
                }
                $executeResults[] = [
                    'required_action_id' => $rowId,
                    'executed' => false,
                    'status' => 'skipped',
                    'message' => $skipMsg,
                    'validation_code' => $skipCode,
                ];
                continue;
            }

            try {
                $automationDispatch = null;
                $automationDispatchError = null;
                if ($actionCode === 'approve_deadline_reason_submission') {
                    $engine->approveDeadlineReasonSubmissionByInstructor(
                        $rowId,
                        $currentUserId,
                        $decisionNotes,
                        $deadlineExtensionDays,
                        $ip,
                        $ua
                    );
                    try {
                        $decisionNotesText = $decisionNotes !== '' ? $decisionNotes : 'Approved by instructor.';
                        $decisionNotesHtml = nl2br(htmlspecialchars($decisionNotesText, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                        $automationDispatch = $engine->dispatchRequiredActionCompletedAutomationEvent(
                            $rowId,
                            $currentUserId,
                            'admin',
                            [
                                'decision_code' => 'approve_deadline_reason_submission',
                                'decision_notes_text' => $decisionNotesText,
                                'decision_notes_html' => $decisionNotesHtml,
                            ]
                        );
                    } catch (Throwable $dispatchError) {
                        $automationDispatchError = $dispatchError->getMessage();
                    }
                } elseif ($actionCode === 'approve_additional_attempts') {
                    $engine->processInstructorApprovalDecision(
                        $rowId,
                        [
                            'decision_code' => 'approve_additional_attempts',
                            'decision_notes' => $decisionNotes,
                            'granted_extra_attempts' => $grantedExtraAttempts,
                            'deadline_extension_days' => $deadlineExtensionDays,
                        ],
                        $currentUserId,
                        $ip,
                        $ua
                    );
                } else {
                    throw new RuntimeException('Unsupported bulk action code.');
                }

                tcc_log_bulk_event($pdo, $row, $currentUserId, $actionCode, $batchId, [
                    'decision_notes' => $decisionNotes,
                    'granted_extra_attempts' => $grantedExtraAttempts,
                    'deadline_extension_days' => $deadlineExtensionDays,
                    'automation_dispatch' => $automationDispatch,
                    'automation_dispatch_error' => $automationDispatchError,
                ], 'success');

                $executeResults[] = [
                    'required_action_id' => $rowId,
                    'user_id' => (int)$row['user_id'],
                    'executed' => true,
                    'status' => 'success',
                    'message' => $automationDispatchError === null ? 'applied' : ('applied_with_notification_warning: ' . $automationDispatchError),
                ];
            } catch (Throwable $e) {
                tcc_log_bulk_event($pdo, $row, $currentUserId, $actionCode, $batchId, [
                    'decision_notes' => $decisionNotes,
                    'granted_extra_attempts' => $grantedExtraAttempts,
                    'deadline_extension_days' => $deadlineExtensionDays,
                ], 'failure');

                $executeResults[] = [
                    'required_action_id' => $rowId,
                    'user_id' => (int)$row['user_id'],
                    'executed' => false,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        $summary = ['success' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($executeResults as $r) {
            if ($r['status'] === 'success') $summary['success']++;
            elseif ($r['status'] === 'failed') $summary['failed']++;
            else $summary['skipped']++;
        }

        tcc_json([
            'ok' => true,
            'action' => 'bulk_action_execute',
            'batch_id' => $batchId,
            'bulk_action_code' => $actionCode,
            'summary' => $summary,
            'results' => $executeResults,
        ]);
    }

    if ($action === 'student_snapshot') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);

        if ($cohortId <= 0 || $studentId <= 0) {
            tcc_json(['ok' => false, 'error' => 'missing_cohort_or_student_id'], 400);
        }

        $st = $pdo->prepare("
            SELECT id, name, email, photo_path
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $st->execute([$studentId]);
        $student = $st->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            tcc_json(['ok' => false, 'error' => 'student_not_found'], 404);
        }

        $totalLessons = tcc_total_lessons($pdo, $cohortId);
        $passed = tcc_passed_lessons($pdo, $studentId, $cohortId);
        $progressPct = $totalLessons > 0 ? (int)round(($passed / $totalLessons) * 100) : 0;

        $pending = tcc_pending_actions($pdo, $cohortId, $studentId);
        $completedActions = tcc_completed_actions($pdo, $cohortId, $studentId);
        $stats = tcc_student_attempt_stats($pdo, $cohortId, $studentId);
        $activeDeadlineIssues = tcc_deadline_reason_active_count($pdo, $cohortId, $studentId);
        $resolvedDeadlineIssues = tcc_deadline_reason_resolved_count($pdo, $cohortId, $studentId);
        $otherPending = tcc_pending_non_deadline_actions_count($pending);
        $cohortAvg = tcc_cohort_metric_averages($pdo, $cohortId);
        $motivation = tcc_motivation_signal($stats, $activeDeadlineIssues, $otherPending);
        $motivation['detail'] = tcc_motivation_detail_summary($stats, $activeDeadlineIssues, $otherPending, (int)$motivation['score']);
        $motivation['inputs'] = [
            'active_deadline_issues' => $activeDeadlineIssues,
            'resolved_deadline_issues' => $resolvedDeadlineIssues,
            'other_pending_actions' => $otherPending,
            'failed_attempts' => (int)$stats['failed_attempts'],
            'avg_score' => $stats['avg_score'],
        ];
        $systemIssues = tcc_system_watch($pdo, $cohortId, $studentId);

        $issues = [];

		
foreach ($pending as $p) {
            $actionType = (string)$p['action_type'];
            $token = (string)($p['token'] ?? '');

            $issues[] = [
                'type' => $actionType,
                'issue_type' => 'open_required_action_' . $actionType,
                'blocker_category' => 'policy',
                'repair_allowed' => false,
                'repair_code' => 'official_flow_only',
                'official_flow_url' => tcc_official_flow_url($actionType, $token),
                'status' => (string)$p['status'],
                'student_id' => $studentId,
                'cohort_id' => $cohortId,
                'lesson_id' => (int)$p['lesson_id'],
                'lesson_title' => (string)($p['lesson_title'] ?? ''),
                'title' => (string)($p['title'] ?? ''),
                'token' => $token,
                'evidence' => [
                    'required_action_id' => (int)$p['id'],
                    'action_type' => $actionType,
                    'status' => (string)$p['status'],
                    'created_at' => (string)($p['created_at'] ?? ''),
                    'opened_at' => (string)($p['opened_at'] ?? ''),
                    'has_token' => trim($token) !== '',
                ],
            ];
        }		
		

        foreach ($systemIssues as $si) {
            $issues[] = [
    'type' => (string)($si['type'] ?? $si['issue_type'] ?? 'system_watch'),
    'issue_type' => (string)($si['issue_type'] ?? $si['type'] ?? 'system_watch'),
    'blocker_category' => (string)($si['blocker_category'] ?? 'ambiguous'),
    'repair_allowed' => !empty($si['repair_allowed']),
    'repair_code' => (string)($si['repair_code'] ?? 'inspect_only'),
    'status' => 'system_watch',
    'student_id' => (int)($si['student_id'] ?? $studentId),
    'cohort_id' => (int)($si['cohort_id'] ?? $cohortId),
    'lesson_id' => (int)($si['lesson_id'] ?? 0),
    'lesson_title' => (string)($si['lesson_title'] ?? ''),
    'title' => (string)($si['summary'] ?? $si['title'] ?? 'System watch issue'),
    'summary' => (string)($si['summary'] ?? ''),
    'recurrence_key' => (string)($si['recurrence_key'] ?? ''),
    'evidence' => is_array($si['evidence'] ?? null) ? $si['evidence'] : [],
];
        }

        $name = tcc_student_name($student);

        tcc_json([
            'ok' => true,
            'action' => 'student_snapshot',
            'student' => [
                'student_id' => $studentId,
                'name' => $name,
                'email' => (string)($student['email'] ?? ''),
                'photo_path' => (string)($student['photo_path'] ?? ''),
                'avatar_initials' => tcc_avatar_initials($name),
            ],
            'cohort_id' => $cohortId,
            'progress' => [
                'passed_lessons' => $passed,
                'total_lessons' => $totalLessons,
                'progress_pct' => $progressPct,
            ],
            'comparison' => [
                'active_deadline_issues' => $activeDeadlineIssues,
                'resolved_deadline_issues' => $resolvedDeadlineIssues,
                'deadlines_missed' => $activeDeadlineIssues,
                'cohort_avg_active_deadline_issues' => $cohortAvg['avg_active_deadline_issues'],
                'cohort_avg_deadlines_missed' => $cohortAvg['avg_active_deadline_issues'],
                'failed_attempts' => $stats['failed_attempts'],
                'cohort_avg_failed_attempts' => $cohortAvg['avg_failed_attempts'],
                'avg_score' => $stats['avg_score'],
                'cohort_avg_score' => $cohortAvg['avg_score'],
            ],
            'motivation' => $motivation,
            'main_issues' => $issues,
            'pending_action_count' => count($pending),
            'completed_intervention_count' => count($completedActions),
            'system_issue_count' => count($systemIssues),
        ]);
    }


    if ($action === 'ai_summary_analysis') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);
        $lessonId = tcc_int($_GET['lesson_id'] ?? 0);
    
        if ($cohortId <= 0 || $studentId <= 0 || $lessonId <= 0) {
            tcc_json([
                'ok' => false,
                'error' => 'missing_required_ids',
            ], 400);
        }
    
        $summarySt = $pdo->prepare("
            SELECT
                ls.summary_html,
                ls.summary_plain,
                ls.review_status,
                ls.review_score,
                ls.review_feedback,
                ls.review_notes_by_instructor,
                ls.updated_at,
                l.title AS lesson_title,
                c.title AS course_title
            FROM lesson_summaries ls
            JOIN lessons l ON l.id = ls.lesson_id
            JOIN courses c ON c.id = l.course_id
            WHERE ls.cohort_id = ?
              AND ls.user_id = ?
              AND ls.lesson_id = ?
            LIMIT 1
        ");
        $summarySt->execute([$cohortId, $studentId, $lessonId]);
        $summary = $summarySt->fetch(PDO::FETCH_ASSOC);
    
        if (!$summary || !is_array($summary)) {
            tcc_json([
                'ok' => false,
                'error' => 'summary_not_found',
                'message' => 'No lesson summary found for this student and lesson.',
            ], 404);
        }
    
        $studentSt = $pdo->prepare("
            SELECT
                id,
                email,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))), ''), email, CONCAT('User #', id)) AS student_name
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $studentSt->execute([$studentId]);
        $student = $studentSt->fetch(PDO::FETCH_ASSOC) ?: [];
    
        $summaryText = tcc_strip_summary_for_ai((string)($summary['summary_html'] ?? ''), (string)($summary['summary_plain'] ?? ''));
        $similarity = tcc_similarity_context_for_summary($pdo, $cohortId, $studentId, $lessonId, $summaryText);

        $fp = tcc_summary_cache_fingerprint($summary);
        $force = (string)($_GET['force'] ?? '') === '1';
        if (!$force) {
            $cachedRow = tcc_ai_cache_get($pdo, $cohortId, $studentId, $lessonId, 'summary');
            if ($cachedRow && hash_equals((string)($cachedRow['fingerprint'] ?? ''), $fp)) {
                $cachedAnalysis = json_decode((string)($cachedRow['analysis_json'] ?? '{}'), true);
                $analysisOut = is_array($cachedAnalysis) ? tcc_normalize_summary_ai_response($cachedAnalysis) : [];
                tcc_json([
                    'ok' => true,
                    'action' => 'ai_summary_analysis',
                    'from_cache' => true,
                    'advisory_only' => true,
                    'student_id' => $studentId,
                    'cohort_id' => $cohortId,
                    'lesson_id' => $lessonId,
                    'analysis' => $analysisOut,
                    'similarity_context' => $similarity,
                ]);
            }
        }

        $payload = [
            'student' => [
                'student_id' => $studentId,
                'student_name' => (string)($student['student_name'] ?? ('Student #' . $studentId)),
            ],
            'cohort_id' => $cohortId,
            'lesson' => [
                'lesson_id' => $lessonId,
                'lesson_title' => (string)($summary['lesson_title'] ?? ''),
                'course_title' => (string)($summary['course_title'] ?? ''),
            ],
            'summary' => [
                'review_status' => (string)($summary['review_status'] ?? ''),
                'review_score' => $summary['review_score'] === null ? null : (int)$summary['review_score'],
                'word_count' => tcc_word_count_for_ai($summaryText),
                'text' => $summaryText,
            ],
            'cohort_similarity_context' => $similarity,
        ];
    
        $result = tcc_call_openai_summary_analysis($payload);
    
        if (empty($result['ok'])) {
            tcc_json([
                'ok' => false,
                'action' => 'ai_summary_analysis',
                'error' => $result['error'] ?? 'ai_analysis_failed',
                'message' => $result['message'] ?? 'AI summary analysis failed.',
                'advisory_only' => true,
            ], 500);
        }
    
        $analysis = is_array($result['analysis'] ?? null) ? $result['analysis'] : [];

        if (!isset($analysis['highest_similarity']) || $analysis['highest_similarity'] === 'Not evaluated') {
            $analysis['highest_similarity'] = $similarity['highest_similarity'];
            $analysis['highest_similarity_student'] = $similarity['highest_similarity_student'];
            $analysis['highest_similarity_pct'] = $similarity['highest_similarity_pct'];
        }

        $analysis = tcc_normalize_summary_ai_response($analysis);
        tcc_ai_cache_put($pdo, $cohortId, $studentId, $lessonId, 'summary', $fp, $analysis, (string)($result['model'] ?? ''));

        tcc_json([
            'ok' => true,
            'action' => 'ai_summary_analysis',
            'generated_at_utc' => gmdate('Y-m-d H:i:s'),
            'advisory_only' => true,
            'student_id' => $studentId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'model' => (string)($result['model'] ?? 'gpt-5.1-mini'),
            'response_id' => (string)($result['response_id'] ?? ''),
            'analysis' => $analysis,
            'similarity_context' => $similarity,
            'agent_instructions' => [
                'purpose' => 'Instructor advisory insight only. Do not use as canonical progression truth.',
                'rules' => [
                    'AI analysis does not change lesson_activity.',
                    'AI analysis does not create or close required actions.',
                    'AI analysis is not proof of misconduct.',
                    'Use as instructor review support only.',
                ],
            ],
        ]);
    }

    if ($action === 'ai_progress_test_analysis') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);
        $lessonId = tcc_int($_GET['lesson_id'] ?? 0);

        if ($cohortId <= 0 || $studentId <= 0 || $lessonId <= 0) {
            tcc_json(['ok' => false, 'error' => 'missing_required_ids'], 400);
        }

        $data = tcc_lesson_attempts_detail($pdo, $cohortId, $studentId, $lessonId);
        $attempts = $data['attempts'] ?? [];
        $fp = (string)($data['attempts_fingerprint'] ?? tcc_attempts_cache_fingerprint($attempts));
        $force = (string)($_GET['force'] ?? '') === '1';
        if (!$force) {
            $cachedRow = tcc_ai_cache_get($pdo, $cohortId, $studentId, $lessonId, 'progress_test');
            if ($cachedRow && hash_equals((string)($cachedRow['fingerprint'] ?? ''), $fp)) {
                $cachedAnalysis = json_decode((string)($cachedRow['analysis_json'] ?? '{}'), true);
                $analysisOut = is_array($cachedAnalysis) ? tcc_normalize_progress_test_ai_response($cachedAnalysis) : [];
                tcc_json([
                    'ok' => true,
                    'action' => 'ai_progress_test_analysis',
                    'from_cache' => true,
                    'advisory_only' => true,
                    'student_id' => $studentId,
                    'cohort_id' => $cohortId,
                    'lesson_id' => $lessonId,
                    'analysis' => $analysisOut,
                ]);
            }
        }

        $studentSt = $pdo->prepare("
            SELECT
                id,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))), ''), email, CONCAT('User #', id)) AS student_name
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $studentSt->execute([$studentId]);
        $student = $studentSt->fetch(PDO::FETCH_ASSOC) ?: [];

        $lesson = $data['lesson'] ?? [];
        $payload = [
            'student' => [
                'student_id' => $studentId,
                'student_name' => (string)($student['student_name'] ?? ('Student #' . $studentId)),
            ],
            'lesson' => [
                'lesson_title' => (string)($lesson['lesson_title'] ?? ''),
                'course_title' => (string)($lesson['course_title'] ?? ''),
            ],
            'attempts' => $attempts,
        ];

        $result = tcc_call_openai_progress_test_analysis($payload);

        if (empty($result['ok'])) {
            tcc_json([
                'ok' => false,
                'action' => 'ai_progress_test_analysis',
                'error' => $result['error'] ?? 'ai_analysis_failed',
                'message' => $result['message'] ?? 'AI oral analysis failed.',
                'advisory_only' => true,
            ], 500);
        }

        $analysis = is_array($result['analysis'] ?? null) ? $result['analysis'] : [];
        $analysis = tcc_normalize_progress_test_ai_response($analysis);
        tcc_ai_cache_put($pdo, $cohortId, $studentId, $lessonId, 'progress_test', $fp, $analysis, (string)($result['model'] ?? ''));

        tcc_json([
            'ok' => true,
            'action' => 'ai_progress_test_analysis',
            'generated_at_utc' => gmdate('Y-m-d H:i:s'),
            'advisory_only' => true,
            'student_id' => $studentId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'model' => (string)($result['model'] ?? ''),
            'response_id' => (string)($result['response_id'] ?? ''),
            'analysis' => $analysis,
        ]);
    }

    if ($action === 'student_lessons') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);

        if ($cohortId <= 0 || $studentId <= 0) {
            tcc_json([
                'ok' => false,
                'error' => 'missing_cohort_or_student_id'
            ], 400);
        }

        $timeline = tcc_student_lesson_timeline($pdo, $cohortId, $studentId);

        tcc_json([
            'ok' => true,
            'action' => 'student_lessons',
            'cohort_id' => $cohortId,
            'student_id' => $studentId,
            'count' => count($timeline),
            'lessons' => $timeline
        ]);
    }


    if ($action === 'lesson_summary_detail') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);
        $lessonId = tcc_int($_GET['lesson_id'] ?? 0);

        if ($cohortId <= 0 || $studentId <= 0 || $lessonId <= 0) {
            tcc_json(['ok' => false, 'error' => 'missing_required_ids'], 400);
        }

        tcc_json([
            'ok' => true,
            'action' => 'lesson_summary_detail',
            'cohort_id' => $cohortId,
            'student_id' => $studentId,
            'lesson_id' => $lessonId,
            'data' => tcc_lesson_summary_detail($pdo, $cohortId, $studentId, $lessonId)
        ]);
    }

    if ($action === 'lesson_attempts_detail') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);
        $lessonId = tcc_int($_GET['lesson_id'] ?? 0);

        if ($cohortId <= 0 || $studentId <= 0 || $lessonId <= 0) {
            tcc_json(['ok' => false, 'error' => 'missing_required_ids'], 400);
        }

        tcc_json([
            'ok' => true,
            'action' => 'lesson_attempts_detail',
            'cohort_id' => $cohortId,
            'student_id' => $studentId,
            'lesson_id' => $lessonId,
            'data' => tcc_lesson_attempts_detail($pdo, $cohortId, $studentId, $lessonId)
        ]);
    }

    if ($action === 'lesson_interventions_detail') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);
        $lessonId = tcc_int($_GET['lesson_id'] ?? 0);

        if ($cohortId <= 0 || $studentId <= 0) {
            tcc_json(['ok' => false, 'error' => 'missing_required_ids'], 400);
        }

        tcc_json([
            'ok' => true,
            'action' => 'lesson_interventions_detail',
            'cohort_id' => $cohortId,
            'student_id' => $studentId,
            'lesson_id' => $lessonId,
            'data' => tcc_lesson_interventions_detail($pdo, $cohortId, $studentId, $lessonId)
        ]);
    }

    if ($action === 'student_interventions_audit') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);

        if ($cohortId <= 0 || $studentId <= 0) {
            tcc_json(['ok' => false, 'error' => 'missing_required_ids'], 400);
        }

        $sinceDaysRaw = $_GET['since_days'] ?? null;
        if ($sinceDaysRaw === null || $sinceDaysRaw === '') {
            $sinceDays = 7;
        } else {
            $sinceDays = max(0, min(3660, tcc_int($sinceDaysRaw)));
        }
        $timelineLimit = max(1, min(100, tcc_int($_GET['limit'] ?? 20)));
        $timelineOffset = max(0, tcc_int($_GET['offset'] ?? 0));

        tcc_json([
            'ok' => true,
            'action' => 'student_interventions_audit',
            'cohort_id' => $cohortId,
            'student_id' => $studentId,
            'data' => tcc_student_interventions_audit($pdo, $cohortId, $studentId, [
                'since_days' => $sinceDays,
                'timeline_limit' => $timelineLimit,
                'timeline_offset' => $timelineOffset,
            ]),
        ]);
    }

    if ($action === 'system_watch') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);

        if ($cohortId <= 0) {
            tcc_json(['ok' => false, 'error' => 'missing_cohort_id'], 400);
        }

        $issues = tcc_system_watch($pdo, $cohortId, $studentId > 0 ? $studentId : null);

        tcc_json([
            'ok' => true,
            'action' => 'system_watch',
            'cohort_id' => $cohortId,
            'student_id' => $studentId ?: null,
            'count' => count($issues),
            'issues' => $issues,
        ]);
    }

    if ($action === 'debug_report') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);
        $lessonId = tcc_int($_GET['lesson_id'] ?? 0);
        $issueType = tcc_str($_GET['issue_type'] ?? '');

        if ($cohortId <= 0 || $studentId <= 0 || $lessonId <= 0) {
            tcc_json(['ok' => false, 'error' => 'missing_required_ids'], 400);
        }

        $pt = $pdo->prepare("
            SELECT id, attempt, status, formal_result_code, pass_gate_met, counts_as_unsat, score_pct, started_at, completed_at, updated_at
            FROM progress_tests_v2
            WHERE cohort_id = ?
              AND user_id = ?
              AND lesson_id = ?
            ORDER BY id DESC
            LIMIT 20
        ");
        $pt->execute([$cohortId, $studentId, $lessonId]);

        $la = $pdo->prepare("
            SELECT *
            FROM lesson_activity
            WHERE cohort_id = ?
              AND user_id = ?
              AND lesson_id = ?
            LIMIT 1
        ");
        $la->execute([$cohortId, $studentId, $lessonId]);

        $ra = $pdo->prepare("
            SELECT id, action_type, status, title, created_at, opened_at, completed_at, updated_at
            FROM student_required_actions
            WHERE cohort_id = ?
              AND user_id = ?
              AND lesson_id = ?
            ORDER BY id DESC
            LIMIT 20
        ");
        $ra->execute([$cohortId, $studentId, $lessonId]);

        $emails = [];
        try {
            $em = $pdo->prepare("
                SELECT id, email_type, subject, sent_status, created_at, sent_at
                FROM training_progression_emails
                WHERE cohort_id = ?
                  AND user_id = ?
                  AND lesson_id = ?
                ORDER BY id DESC
                LIMIT 20
            ");
            $em->execute([$cohortId, $studentId, $lessonId]);
            $emails = $em->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $emails = [];
        }

        tcc_json([
            'ok' => true,
            'action' => 'debug_report',
            'generated_at_utc' => gmdate('Y-m-d H:i:s'),
            'issue_type' => $issueType !== '' ? $issueType : 'manual_debug_report',
            'student_id' => $studentId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'canonical' => [
                'progress_tests_v2' => $pt->fetchAll(PDO::FETCH_ASSOC),
                'required_actions' => $ra->fetchAll(PDO::FETCH_ASSOC),
                'emails' => $emails,
            ],
            'projection' => [
                'lesson_activity' => $la->fetch(PDO::FETCH_ASSOC) ?: null,
            ],
            'agent_instructions' => [
                'purpose' => 'Use this report to diagnose SSOT drift or progression blockage without guessing.',
                'rules' => [
                    'Do not treat lesson_activity as canonical truth.',
                    'PASS in progress_tests_v2 is terminal.',
                    'Required actions must be checked against canonical completion state.',
                    'Any manual repair must be auditable.'
                ],
            ],
        ]);
    }

    if ($action === 'theory_ai_training_report_start') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);
        if ($cohortId <= 0 || $studentId <= 0) {
            tcc_json(['ok' => false, 'error' => 'missing_cohort_or_student'], 400);
        }
        try {
            $out = tatr_start_or_resume($pdo, $cohortId, $studentId);
        } catch (Throwable $e) {
            tcc_json(['ok' => false, 'error' => 'start_failed', 'message' => $e->getMessage()], 400);
        }
        if ($out['ready']) {
            $pdfUrl = '/instructor/export_student_theory_ai_report_pdf.php?cohort_id=' . rawurlencode((string)$cohortId)
                . '&student_id=' . rawurlencode((string)$studentId) . '&stored=1';
            tcc_json([
                'ok' => true,
                'action' => $action,
                'ready' => true,
                'job_id' => $out['job_id'],
                'pdf_url' => $pdfUrl,
                'fingerprint' => $out['fingerprint'],
            ]);
        }
        tcc_json([
            'ok' => true,
            'action' => $action,
            'ready' => false,
            'job_id' => $out['job_id'],
            'worker_spawned' => $out['worker_spawned'],
            'fingerprint' => $out['fingerprint'],
        ]);
    }

    if ($action === 'theory_ai_training_report_poll') {
        $jobId = tcc_int($_GET['job_id'] ?? 0);
        if ($jobId <= 0) {
            tcc_json(['ok' => false, 'error' => 'missing_job_id'], 400);
        }
        $row = tatr_get_job($pdo, $jobId);
        if (!$row) {
            tcc_json(['ok' => false, 'error' => 'job_not_found'], 404);
        }
        $cohortId = (int)$row['cohort_id'];
        $studentId = (int)$row['student_id'];
        try {
            InstructorTheoryTrainingReportAi::verifyCohortStudent($pdo, $cohortId, $studentId);
        } catch (Throwable $e) {
            tcc_json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        tatr_reset_stale_running($pdo, $cohortId, $studentId);
        $row = tatr_get_job($pdo, $jobId);
        if (!$row) {
            tcc_json(['ok' => false, 'error' => 'job_not_found'], 404);
        }
        $status = (string)$row['status'];
        $ready = $status === 'complete';
        $pdfUrl = $ready
            ? ('/instructor/export_student_theory_ai_report_pdf.php?cohort_id=' . rawurlencode((string)$cohortId)
                . '&student_id=' . rawurlencode((string)$studentId) . '&stored=1')
            : null;
        tcc_json([
            'ok' => true,
            'action' => $action,
            'job_id' => $jobId,
            'status' => $status,
            'progress' => (int)$row['progress'],
            'error_text' => $row['error_text'] !== null ? (string)$row['error_text'] : null,
            'ready' => $ready,
            'pdf_url' => $pdfUrl,
            'cohort_id' => $cohortId,
            'student_id' => $studentId,
        ]);
    }

    if ($action === 'theory_ai_training_report_run' && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $jobId = tcc_int($payload['job_id'] ?? 0);
        if ($jobId <= 0) {
            tcc_json(['ok' => false, 'error' => 'missing_job_id'], 400);
        }
        $row = tatr_get_job($pdo, $jobId);
        if (!$row) {
            tcc_json(['ok' => false, 'error' => 'job_not_found'], 404);
        }
        $cohortId = (int)$row['cohort_id'];
        $studentId = (int)$row['student_id'];
        try {
            InstructorTheoryTrainingReportAi::verifyCohortStudent($pdo, $cohortId, $studentId);
        } catch (Throwable $e) {
            tcc_json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        tatr_process_job($pdo, $jobId);
        $row = tatr_get_job($pdo, $jobId);
        $ready = $row && (string)$row['status'] === 'complete';
        $pdfUrl = $ready
            ? ('/instructor/export_student_theory_ai_report_pdf.php?cohort_id=' . rawurlencode((string)$cohortId)
                . '&student_id=' . rawurlencode((string)$studentId) . '&stored=1')
            : null;
        tcc_json([
            'ok' => true,
            'action' => $action,
            'job_id' => $jobId,
            'status' => $row ? (string)$row['status'] : 'unknown',
            'progress' => $row ? (int)$row['progress'] : 0,
            'ready' => $ready,
            'pdf_url' => $pdfUrl,
            'error_text' => $row && $row['error_text'] !== null ? (string)$row['error_text'] : null,
        ]);
    }

    tcc_json([
        'ok' => false,
        'error' => 'unknown_action',
        'action' => $action
    ], 400);

} catch (Throwable $e) {
    error_log('TCC_API_ERROR action=' . $action . ' msg=' . $e->getMessage());

    tcc_json([
        'ok' => false,
        'error' => 'server_error',
        'message' => $e->getMessage()
    ], 500);
}
