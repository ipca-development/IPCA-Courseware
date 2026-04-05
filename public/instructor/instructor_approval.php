<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/courseware_progression_v2.php';

cw_require_login();

$u = cw_current_user($pdo);
$userId = (int)($u['id'] ?? 0);
$role = trim((string)($u['role'] ?? ''));

$allowedRoles = ['admin', 'supervisor'];
if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('Forbidden');
}

$engine = new CoursewareProgressionV2($pdo);

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    exit('Missing token');
}

$error = '';
$success = '';

const IPCA_MEDIA_CDN_BASE = 'https://ipca-media.nyc3.cdn.digitaloceanspaces.com/';

function h2(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function hnl(?string $v): string
{
    return nl2br(h2($v));
}

function yesno2(int $v): string
{
    return $v ? 'Yes' : 'No';
}

function ipca_media_url(?string $path): string
{
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }

    return rtrim(IPCA_MEDIA_CDN_BASE, '/') . '/' . ltrim($path, '/');
}

function get_client_ip2(): ?string
{
    $keys = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR',
    ];

    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $raw = trim((string)$_SERVER[$key]);
            if ($raw === '') {
                continue;
            }

            if ($key === 'HTTP_X_FORWARDED_FOR') {
                $parts = explode(',', $raw);
                $raw = trim((string)($parts[0] ?? ''));
            }

            if ($raw !== '') {
                return substr($raw, 0, 45);
            }
        }
    }

    return null;
}

function get_user_agent2(): ?string
{
    if (empty($_SERVER['HTTP_USER_AGENT'])) {
        return null;
    }

    return substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 65535);
}

function format_dt_utc(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }

    return $value . ' UTC';
}

function short_text(?string $text, int $maxLen = 220): string
{
    $text = trim((string)$text);
    if ($text === '') {
        return '—';
    }

    $text = preg_replace('/\s+/', ' ', $text);
    if (!is_string($text)) {
        return '—';
    }

    if (mb_strlen($text) <= $maxLen) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, $maxLen - 1)) . '…';
}

function score_to_icon(float $scorePct, ?string $formalResultCode = null): string
{
    $formalResultCode = strtoupper(trim((string)$formalResultCode));
    $passed = ($formalResultCode === 'SAT') || ($formalResultCode === 'PASS') || ($scorePct >= 75.0);

    if ($passed) {
        return '<span class="ia-result ia-result-pass" title="Satisfactory">✓</span>';
    }

    return '<span class="ia-result ia-result-fail" title="Unsatisfactory">✕</span>';
}

function load_instructor_approval_page_state(
    CoursewareProgressionV2 $engine,
    string $token,
    string $role
): array {
    if (!in_array($role, ['admin', 'supervisor'], true)) {
        http_response_code(403);
        exit('Forbidden');
    }

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

    return $state;
}

function load_user_by_id(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT id, name, first_name, last_name, email, photo_path, role
        FROM users
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function load_chief_instructor_user(PDO $pdo): ?array
{
    try {
        $stmt = $pdo->prepare("
            SELECT policy_value
            FROM system_policy_values
            WHERE policy_key = 'chief_instructor_user_id'
              AND scope_type = 'global'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute();
        $value = $stmt->fetchColumn();
        $userId = (int)$value;

        if ($userId > 0) {
            return load_user_by_id($pdo, $userId);
        }
    } catch (Throwable $e) {
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, name, first_name, last_name, email, photo_path, role
            FROM users
            WHERE role IN ('admin', 'supervisor')
              AND status = 'active'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function load_attempt_history(PDO $pdo, int $userId, int $cohortId, int $lessonId): array
{
    $sql = "
        SELECT
            id,
            attempt,
            status,
            score_pct,
            formal_result_code,
            formal_result_label,
            counts_as_unsat,
            weak_areas,
            ai_summary,
            completed_at,
            timing_status
        FROM progress_tests_v2
        WHERE user_id = :user_id
          AND cohort_id = :cohort_id
          AND lesson_id = :lesson_id
        ORDER BY attempt DESC, id DESC
        LIMIT 10
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':user_id' => $userId,
        ':cohort_id' => $cohortId,
        ':lesson_id' => $lessonId,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function load_slide_references_for_lesson(PDO $pdo, int $lessonId): array
{
    $stmt = $pdo->prepare("
        SELECT
            sr.ref_type,
            sr.ref_code,
            sr.ref_title,
            sr.notes,
            sr.ref_detail,
            sr.confidence
        FROM slides s
        INNER JOIN slide_references sr ON sr.slide_id = s.id
        WHERE s.lesson_id = :lesson_id
          AND s.is_deleted = 0
        ORDER BY
            FIELD(sr.ref_type, 'ACS', 'PHAK', 'FAR_AIM', 'EASA'),
            sr.confidence DESC,
            sr.ref_code ASC,
            sr.id ASC
    ");
    $stmt->execute([':lesson_id' => $lessonId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $seen = [];
    $out = [];

    if (!is_array($rows)) {
        return [];
    }

    foreach ($rows as $row) {
        $key = trim((string)($row['ref_type'] ?? '')) . '|' .
               trim((string)($row['ref_code'] ?? '')) . '|' .
               trim((string)($row['ref_title'] ?? ''));

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $out[] = $row;
    }

    return $out;
}

function load_progress_test_items_by_test_ids(PDO $pdo, array $testIds): array
{
    $testIds = array_values(array_filter(array_map('intval', $testIds)));
    if (!$testIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($testIds), '?'));

    $sql = "
        SELECT
            i.id,
            i.test_id,
            i.idx,
            i.kind,
            i.prompt,
            i.transcript_text,
            i.audio_path,
            i.is_correct,
            i.score_points,
            i.max_points,
            o.id AS override_id,
            o.override_is_correct,
            o.override_score_points,
            o.override_reason,
            o.created_at AS override_created_at,
            u.name AS override_user_name
        FROM progress_test_items_v2 i
        LEFT JOIN (
            SELECT o1.*
            FROM progress_test_item_score_overrides o1
            INNER JOIN (
                SELECT progress_test_item_id, MAX(id) AS max_id
                FROM progress_test_item_score_overrides
                GROUP BY progress_test_item_id
            ) latest
              ON latest.progress_test_item_id = o1.progress_test_item_id
             AND latest.max_id = o1.id
        ) o ON o.progress_test_item_id = i.id
        LEFT JOIN users u ON u.id = o.overridden_by_user_id
        WHERE i.test_id IN ($placeholders)
        ORDER BY i.test_id DESC, i.idx ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($testIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    if (!is_array($rows)) {
        return $grouped;
    }

    foreach ($rows as $row) {
        $testId = (int)($row['test_id'] ?? 0);
        if (!isset($grouped[$testId])) {
            $grouped[$testId] = [];
        }
        $grouped[$testId][] = $row;
    }

    return $grouped;
}

function load_latest_summary(PDO $pdo, int $userId, int $cohortId, int $lessonId): ?array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM lesson_summaries
        WHERE user_id = :user_id
          AND cohort_id = :cohort_id
          AND lesson_id = :lesson_id
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':cohort_id' => $cohortId,
        ':lesson_id' => $lessonId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function categorize_difficulty_signals(string $text, array &$bucketCounts, array &$bucketSnippets): void
{
    $text = trim($text);
    if ($text === '') {
        return;
    }

    $map = [
        'Elevator / Pitch Control' => ['elevator', 'pitch', 'nose up', 'nose down', 'pulling back', 'pushing forward'],
        'Ailerons / Roll Direction' => ['aileron', 'bank', 'roll', 'left aileron', 'right aileron'],
        'Rudder / Yaw Control' => ['rudder', 'yaw', 'pedal', 'foot pedals'],
        'Throttle / Power Control' => ['throttle', 'power', 'rpm', 'engine power'],
        'Turn Mechanics / Lift' => ['horizontal lift', 'vertical lift', 'turn', 'bank redirects', 'lift acts horizontally'],
        'Cause-and-Effect Explanation' => ['cause-and-effect', 'sequence', 'explain', 'surface deflection', 'airplane response'],
        'Confidence / Precision Under Questioning' => ['inconsistent', 'uncertainty', 'precision', 'changed answers', 'directly', 'cleanly'],
        'Missing / Weak Oral Response Capture' => ['no audible', 'not captured', 'could not verify', 'no oral responses'],
    ];

    $lower = mb_strtolower($text);

    foreach ($map as $label => $needles) {
        foreach ($needles as $needle) {
            if (mb_stripos($lower, mb_strtolower($needle)) !== false) {
                if (!isset($bucketCounts[$label])) {
                    $bucketCounts[$label] = 0;
                }
                $bucketCounts[$label]++;

                if (!isset($bucketSnippets[$label])) {
                    $bucketSnippets[$label] = [];
                }

                if (count($bucketSnippets[$label]) < 3) {
                    $bucketSnippets[$label][] = short_text($text, 180);
                }
                break;
            }
        }
    }
}

function build_difficulty_summary(array $attemptHistory): array
{
    $bucketCounts = [];
    $bucketSnippets = [];

    foreach ($attemptHistory as $row) {
        categorize_difficulty_signals((string)($row['weak_areas'] ?? ''), $bucketCounts, $bucketSnippets);
        categorize_difficulty_signals((string)($row['ai_summary'] ?? ''), $bucketCounts, $bucketSnippets);
    }

    arsort($bucketCounts);

    $bullets = [];
    foreach ($bucketCounts as $label => $count) {
        $context = '';
        $examples = isset($bucketSnippets[$label]) ? array_unique($bucketSnippets[$label]) : [];
        if ($examples) {
            $context = ' Seen across ' . $count . ' signal(s). ' . $examples[0];
        } else {
            $context = ' Seen across ' . $count . ' signal(s).';
        }

        if ($label === 'Elevator / Pitch Control') {
            $bullets[] = '<strong>Elevator / pitch-direction understanding</strong> — The student appears to struggle with the exact relationship between control input, elevator movement, and nose response.' . h2($context);
        } elseif ($label === 'Ailerons / Roll Direction') {
            $bullets[] = '<strong>Aileron deflection and bank direction</strong> — Repeated patterns suggest incomplete mental mapping of which aileron moves up/down and how that creates the resulting bank.' . h2($context);
        } elseif ($label === 'Rudder / Yaw Control') {
            $bullets[] = '<strong>Rudder and yaw control distinction</strong> — There are recurring signs of confusion between yaw control and the other primary flight controls.' . h2($context);
        } elseif ($label === 'Throttle / Power Control') {
            $bullets[] = '<strong>Throttle versus primary flight-control function</strong> — The student appears to need a firmer distinction between power control and attitude / directional control.' . h2($context);
        } elseif ($label === 'Turn Mechanics / Lift') {
            $bullets[] = '<strong>Turn mechanics and lift redirection</strong> — The student seems to know the surface outcome but not always the aerodynamic reason why the airplane turns in a bank.' . h2($context);
        } elseif ($label === 'Cause-and-Effect Explanation') {
            $bullets[] = '<strong>Structured cause-and-effect explanation</strong> — The deeper gap appears to be verbalizing the full chain: pilot input → surface deflection → aerodynamic force → aircraft motion.' . h2($context);
        } elseif ($label === 'Confidence / Precision Under Questioning') {
            $bullets[] = '<strong>Precision under questioning</strong> — The student may know parts of the concept, but not yet with stable, concise recall under oral assessment pressure.' . h2($context);
        } elseif ($label === 'Missing / Weak Oral Response Capture') {
            $bullets[] = '<strong>Weak usable oral evidence in some attempts</strong> — Some attempts provided limited oral evidence, reducing confidence that the student can reliably explain the concept aloud.' . h2($context);
        }

        if (count($bullets) >= 5) {
            break;
        }
    }

    if (!$bullets) {
        $bullets[] = '<strong>No dominant repeated weakness detected</strong> — Attempt history does not yet show a single clear recurring gap. The issue may be general inconsistency rather than one isolated topic.';
    }

    return $bullets;
}

function build_recommendations(array $attemptHistory, ?array $summaryRow): array
{
    $recommendations = [];
    $scores = [];

    foreach ($attemptHistory as $row) {
        if (isset($row['score_pct']) && $row['score_pct'] !== null) {
            $scores[] = (float)$row['score_pct'];
        }
    }

    $latest = $scores ? $scores[0] : 0.0;
    $best = $scores ? max($scores) : 0.0;
    $trendImproved = count($scores) >= 2 && $scores[0] > $scores[1];
    $summaryScore = isset($summaryRow['review_score']) ? (float)$summaryRow['review_score'] : 0.0;

    if ($latest < 75.0) {
        $recommendations[] = '<strong>Best next step: targeted one-on-one concept correction with visual cause-and-effect mapping</strong> — The attempt history suggests that the student is not only missing isolated facts, but also the directional logic linking control input to aircraft response. A short instructor session using a whiteboard, force arrows, and left/right control examples is likely the fastest didactical correction because it converts fragmented recall into one coherent mental model.';
    }

    if ($summaryScore >= 70.0) {
        $recommendations[] = '<strong>Second option: oral rehearsal rather than more writing</strong> — The written summary appears stronger than the oral performance, which suggests the student may conceptually recognize the content but cannot yet retrieve it cleanly under questioning. A guided oral drill with immediate instructor correction would likely produce better transfer than another unguided written task.';
    } else {
        $recommendations[] = '<strong>Second option: require a short structured summary rewrite before the next attempt</strong> — Because the written understanding is not yet strong enough, a concise rewrite focused on control input → surface movement → aircraft response can help stabilize the foundation before another oral assessment.';
    }

    if ($trendImproved || $best >= 60.0) {
        $recommendations[] = '<strong>Third option: allow a tightly conditioned additional attempt</strong> — Because there are signs of partial improvement, another attempt may be reasonable, but only after a specific remediation task and not as an immediate retry. This protects motivation while still enforcing learning quality.';
    } else {
        $recommendations[] = '<strong>Third option: pause new attempts and require coached remediation first</strong> — The repeated unsatisfactory pattern suggests that simply giving another attempt would likely reinforce the same weak recall path. A short intervention before any retry is more educationally efficient.';
    }

    return array_slice($recommendations, 0, 3);
}

function compute_engagement_indicator(array $attemptHistory, ?array $summaryRow): array
{
    $score = 55.0;

    $scores = [];
    $onTimeCount = 0;

    foreach ($attemptHistory as $row) {
        $scores[] = isset($row['score_pct']) ? (float)$row['score_pct'] : 0.0;
        if ((string)($row['timing_status'] ?? '') === 'on_time') {
            $onTimeCount++;
        }
    }

    if ($attemptHistory) {
        $score += min(15, $onTimeCount * 3);
    }

    if (count($scores) >= 2) {
        if ($scores[0] > $scores[1]) {
            $score += 8;
        } elseif ($scores[0] < $scores[1]) {
            $score -= 6;
        }
    }

    $best = $scores ? max($scores) : 0.0;
    if ($best >= 60.0) {
        $score += 6;
    }

    if ($summaryRow) {
        $reviewScore = isset($summaryRow['review_score']) ? (float)$summaryRow['review_score'] : 0.0;
        $score += min(12, max(0, ($reviewScore - 50.0) * 0.18));

        if (!empty($summaryRow['student_soft_locked'])) {
            $score -= 8;
        }
    }

    $score = max(0.0, min(100.0, $score));

    $label = 'Moderate';
    if ($score >= 75.0) {
        $label = 'Strong';
    } elseif ($score < 45.0) {
        $label = 'Low';
    }

    $rationale = [];
    $rationale[] = 'On-time test behavior: ' . (string)$onTimeCount . ' on-time attempt(s)';
    $rationale[] = 'Best score reached: ' . number_format($best, 0) . '%';
    if ($summaryRow) {
        $rationale[] = 'Summary review score: ' . number_format((float)($summaryRow['review_score'] ?? 0), 0) . '%';
    }

    return [
        'score' => round($score, 1),
        'label' => $label,
        'rationale' => $rationale,
    ];
}

function recalculate_progress_test_score(PDO $pdo, int $testId): void
{
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(COALESCE(score_points, 0)), 0) AS total_points,
            COALESCE(SUM(COALESCE(max_points, 0)), 0) AS total_max
        FROM progress_test_items_v2
        WHERE test_id = :test_id
    ");
    $stmt->execute([':test_id' => $testId]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);

    $totalPoints = (int)($totals['total_points'] ?? 0);
    $totalMax = (int)($totals['total_max'] ?? 0);
    $scorePct = $totalMax > 0 ? round(($totalPoints / $totalMax) * 100, 2) : 0.0;

    $upd = $pdo->prepare("
        UPDATE progress_tests_v2
        SET
            score_pct = :score_pct,
            updated_at = NOW()
        WHERE id = :id
        LIMIT 1
    ");
    $upd->execute([
        ':score_pct' => $scorePct,
        ':id' => $testId,
    ]);
}

$state = load_instructor_approval_page_state($engine, $token, $role);
$action = (array)$state['action'];
$activity = (array)($state['activity'] ?? []);
$progressionContext = (array)($state['progression_context'] ?? []);
$latestProgressTest = (array)($state['latest_progress_test'] ?? []);

$actionId = (int)($action['id'] ?? 0);
$actionUserId = (int)($action['user_id'] ?? 0);
$cohortId = (int)($action['cohort_id'] ?? 0);
$lessonId = (int)($action['lesson_id'] ?? 0);
$progressTestId = isset($action['progress_test_id']) ? (int)$action['progress_test_id'] : 0;

$studentUser = load_user_by_id($pdo, $actionUserId);
$chiefInstructorUser = load_chief_instructor_user($pdo);

$studentName = trim((string)($studentUser['name'] ?? ($state['student_name'] ?? '')));
if ($studentName === '') {
    $studentName = trim((string)(($progressionContext['student_recipient']['name'] ?? '') ?: 'Student'));
}

$lessonTitle = trim((string)($state['lesson_title'] ?? ($progressionContext['lesson_title'] ?? '')));
$cohortTitle = trim((string)($state['cohort_title'] ?? ($progressionContext['cohort_title'] ?? '')));

$chiefInstructorName = trim((string)($chiefInstructorUser['name'] ?? (($progressionContext['chief_instructor_recipient']['name'] ?? '') ?: 'Chief Instructor')));
$chiefInstructorEmail = trim((string)($chiefInstructorUser['email'] ?? ($progressionContext['chief_instructor_recipient']['email'] ?? '')));

$ipAddress = get_client_ip2();
$userAgent = get_user_agent2();

try {
    $engine->markInstructorApprovalPageOpened(
        $actionId,
        (string)($ipAddress ?? ''),
        (string)($userAgent ?? '')
    );
} catch (Throwable $e) {
    error_log('markInstructorApprovalPageOpened failed: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['save_summary_notes'])) {
            $summaryId = (int)($_POST['summary_id'] ?? 0);
            $reviewNotes = trim((string)($_POST['review_notes_by_instructor'] ?? ''));

            $stmt = $pdo->prepare("
                UPDATE lesson_summaries
                SET
                    review_notes_by_instructor = :notes,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([
                ':notes' => $reviewNotes,
                ':id' => $summaryId,
            ]);

            $success = 'Instructor summary notes updated.';
        } elseif (isset($_POST['override_item_score'])) {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $overrideScorePoints = (int)($_POST['override_score_points'] ?? 0);
            $overrideIsCorrectRaw = (string)($_POST['override_is_correct'] ?? '');
            $overrideReason = trim((string)($_POST['override_reason'] ?? ''));

            if ($itemId <= 0) {
                throw new RuntimeException('Invalid progress test item.');
            }
            if ($overrideReason === '') {
                throw new RuntimeException('Override reason is required.');
            }

            $stmt = $pdo->prepare("
                SELECT i.*, t.user_id, t.cohort_id, t.lesson_id
                FROM progress_test_items_v2 i
                INNER JOIN progress_tests_v2 t ON t.id = i.test_id
                WHERE i.id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $itemId]);
            $itemRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$itemRow) {
                throw new RuntimeException('Progress test item not found.');
            }

            if ((int)$itemRow['user_id'] !== $actionUserId || (int)$itemRow['cohort_id'] !== $cohortId || (int)$itemRow['lesson_id'] !== $lessonId) {
                throw new RuntimeException('Item does not belong to this instructor review context.');
            }

            $maxPoints = (int)($itemRow['max_points'] ?? 0);
            if ($overrideScorePoints < 0 || ($maxPoints > 0 && $overrideScorePoints > $maxPoints)) {
                throw new RuntimeException('Override score is out of range.');
            }

            $overrideIsCorrect = null;
            if ($overrideIsCorrectRaw === '1') {
                $overrideIsCorrect = 1;
            } elseif ($overrideIsCorrectRaw === '0') {
                $overrideIsCorrect = 0;
            } else {
                $overrideIsCorrect = $overrideScorePoints > 0 ? 1 : 0;
            }

            $pdo->beginTransaction();

            $ins = $pdo->prepare("
                INSERT INTO progress_test_item_score_overrides (
                    progress_test_item_id,
                    progress_test_id,
                    overridden_by_user_id,
                    original_is_correct,
                    original_score_points,
                    original_max_points,
                    override_is_correct,
                    override_score_points,
                    override_reason,
                    created_at
                ) VALUES (
                    :progress_test_item_id,
                    :progress_test_id,
                    :overridden_by_user_id,
                    :original_is_correct,
                    :original_score_points,
                    :original_max_points,
                    :override_is_correct,
                    :override_score_points,
                    :override_reason,
                    NOW()
                )
            ");
            $ins->execute([
                ':progress_test_item_id' => $itemId,
                ':progress_test_id' => (int)$itemRow['test_id'],
                ':overridden_by_user_id' => $userId,
                ':original_is_correct' => $itemRow['is_correct'] === null ? null : (int)$itemRow['is_correct'],
                ':original_score_points' => $itemRow['score_points'] === null ? null : (int)$itemRow['score_points'],
                ':original_max_points' => $itemRow['max_points'] === null ? null : (int)$itemRow['max_points'],
                ':override_is_correct' => $overrideIsCorrect,
                ':override_score_points' => $overrideScorePoints,
                ':override_reason' => $overrideReason,
            ]);

            $upd = $pdo->prepare("
                UPDATE progress_test_items_v2
                SET
                    is_correct = :is_correct,
                    score_points = :score_points,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $upd->execute([
                ':is_correct' => $overrideIsCorrect,
                ':score_points' => $overrideScorePoints,
                ':id' => $itemId,
            ]);

            recalculate_progress_test_score($pdo, (int)$itemRow['test_id']);

            $pdo->commit();
            $success = 'Instructor override saved and test score recalculated.';
        } elseif (isset($_POST['mark_instructor_session_completed'])) {
            $result = $engine->markInstructorApprovalOneOnOneCompleted(
                $actionId,
                $userId,
                (string)($ipAddress ?? ''),
                (string)($userAgent ?? '')
            );
            $success = trim((string)($result['message'] ?? 'Action recorded successfully.'));
        } else {
            $decisionCode = trim((string)($_POST['decision_code'] ?? ''));
            $grantedExtraAttempts = (int)($_POST['granted_extra_attempts'] ?? 0);

            $payload = [
                'decision_code' => $decisionCode,
                'granted_extra_attempts' => $grantedExtraAttempts,
                'summary_revision_required' => $decisionCode === 'approve_with_summary_revision' ? 1 : 0,
                'one_on_one_required' => $decisionCode === 'approve_with_one_on_one' ? 1 : 0,
                'training_suspended' => $decisionCode === 'suspend_training' ? 1 : 0,
                'major_intervention_flag' => $decisionCode === 'suspend_training' ? 1 : 0,
                'decision_notes' => trim((string)($_POST['decision_notes'] ?? '')),
            ];

            $result = $engine->processInstructorApprovalDecision(
                $actionId,
                $payload,
                $userId,
                (string)($ipAddress ?? ''),
                (string)($userAgent ?? '')
            );

            $success = trim((string)($result['message'] ?? ''));
            if ($success === '') {
                $success = 'Action recorded successfully.';
            }
        }

        $state = load_instructor_approval_page_state($engine, $token, $role);
        $action = (array)($state['action'] ?? []);
        $activity = (array)($state['activity'] ?? []);
        $progressionContext = (array)($state['progression_context'] ?? []);
        $latestProgressTest = (array)($state['latest_progress_test'] ?? []);

        $actionId = (int)($action['id'] ?? 0);
        $actionUserId = (int)($action['user_id'] ?? 0);
        $cohortId = (int)($action['cohort_id'] ?? 0);
        $lessonId = (int)($action['lesson_id'] ?? 0);
        $progressTestId = isset($action['progress_test_id']) ? (int)$action['progress_test_id'] : 0;

        $studentUser = load_user_by_id($pdo, $actionUserId);
        $chiefInstructorUser = load_chief_instructor_user($pdo);

        $studentName = trim((string)($studentUser['name'] ?? ($state['student_name'] ?? '')));
        if ($studentName === '') {
            $studentName = trim((string)(($progressionContext['student_recipient']['name'] ?? '') ?: 'Student'));
        }

        $lessonTitle = trim((string)($state['lesson_title'] ?? ($progressionContext['lesson_title'] ?? '')));
        $cohortTitle = trim((string)($state['cohort_title'] ?? ($progressionContext['cohort_title'] ?? '')));
        $chiefInstructorName = trim((string)($chiefInstructorUser['name'] ?? (($progressionContext['chief_instructor_recipient']['name'] ?? '') ?: 'Chief Instructor')));
        $chiefInstructorEmail = trim((string)($chiefInstructorUser['email'] ?? ($progressionContext['chief_instructor_recipient']['email'] ?? '')));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
        $state = load_instructor_approval_page_state($engine, $token, $role);
        $action = (array)($state['action'] ?? []);
        $activity = (array)($state['activity'] ?? []);
        $progressionContext = (array)($state['progression_context'] ?? []);
        $latestProgressTest = (array)($state['latest_progress_test'] ?? []);
    }
}

$attemptHistory = load_attempt_history($pdo, $actionUserId, $cohortId, $lessonId);
$lessonRefs = load_slide_references_for_lesson($pdo, $lessonId);
$summaryRow = load_latest_summary($pdo, $actionUserId, $cohortId, $lessonId);
$testIds = array_map(static function ($r) { return (int)($r['id'] ?? 0); }, $attemptHistory);
$attemptItems = load_progress_test_items_by_test_ids($pdo, $testIds);
$difficultyBullets = build_difficulty_summary($attemptHistory);
$recommendations = build_recommendations($attemptHistory, $summaryRow);
$engagement = compute_engagement_indicator($attemptHistory, $summaryRow);

$currentActionStatus = trim((string)($action['status'] ?? ''));
$currentDecisionCode = trim((string)($action['decision_code'] ?? ''));
$currentDecisionNotes = trim((string)($action['decision_notes'] ?? ''));

$latestScorePct = isset($latestProgressTest['score_pct']) ? (string)$latestProgressTest['score_pct'] : '';
$latestAttemptNo = isset($latestProgressTest['attempt']) ? (string)$latestProgressTest['attempt'] : '';

$pageTitle = 'Instructor Approval';
$pageSubtitle = 'Review blocked progression and decide the next training step.';

cw_header($pageTitle);
?>

<style>
.ia-shell{max-width:1260px;margin:0 auto;padding:24px 20px 36px 20px}
.ia-pagehead{margin-bottom:20px}
.ia-kicker{display:inline-block;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;background:#dbeafe;color:#1d4ed8;margin-bottom:12px}
.ia-title{margin:0;font-size:30px;line-height:1.15;font-weight:900;color:#0f172a}
.ia-subtitle{margin:10px 0 0 0;color:#64748b;font-size:15px}
.ia-alert{margin:18px 0;padding:14px 16px;border-radius:14px;border:1px solid;font-weight:700}
.ia-alert.error{background:#fef2f2;border-color:#fecaca;color:#991b1b}
.ia-alert.success{background:#ecfdf5;border-color:#86efac;color:#166534}
.ia-grid{display:grid;grid-template-columns:1.25fr .75fr;gap:20px;align-items:start}
.ia-stack{display:grid;gap:20px}
.ia-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;box-shadow:0 8px 28px rgba(15,23,42,.05);overflow:hidden}
.ia-cardhead{padding:18px 20px 10px 20px;border-bottom:1px solid #eef2f7}
.ia-cardtitle{margin:0;font-size:18px;font-weight:800;color:#0f172a}
.ia-cardsub{margin:6px 0 0 0;color:#64748b;font-size:14px}
.ia-cardbody{padding:18px 20px 20px 20px}
.ia-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 16px}
.ia-meta-item{padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px}
.ia-meta-label{display:block;font-size:12px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;color:#64748b;margin-bottom:6px}
.ia-meta-value{font-size:15px;font-weight:700;color:#0f172a;word-break:break-word}
.ia-identity{display:flex;gap:14px;align-items:center}
.ip-avatar{width:84px;height:84px;border-radius:24px;overflow:hidden;flex:0 0 84px;background:linear-gradient(180deg,#e8eef7 0%,#dfe7f2 100%);border:1px solid rgba(15,23,42,0.07);display:flex;align-items:center;justify-content:center;box-shadow:inset 0 1px 0 rgba(255,255,255,0.45)}
.ip-avatar img{width:100%;height:100%;object-fit:cover;display:block}
.ip-avatar-fallback{width:34px;height:34px;color:#7b8aa0;display:flex;align-items:center;justify-content:center}
.ia-status{display:inline-block;padding:8px 12px;border-radius:999px;font-size:12px;font-weight:900;letter-spacing:.03em;text-transform:uppercase}
.ia-status.pending,.ia-status.opened{background:#fef3c7;color:#92400e}
.ia-status.approved,.ia-status.completed{background:#dcfce7;color:#166534}
.ia-status.rejected,.ia-status.expired{background:#fee2e2;color:#991b1b}
.ia-block{border:1px solid #e2e8f0;border-radius:14px;background:#f8fafc;padding:14px 16px}
.ia-block + .ia-block{margin-top:14px}
.ia-block-title{margin:0 0 8px 0;font-size:14px;font-weight:900;color:#0f172a}
.ia-block-text{color:#334155;font-size:14px;line-height:1.55}
.ia-bullets{margin:0;padding-left:18px;color:#334155}
.ia-bullets li{margin:0 0 10px 0;line-height:1.55}
.ia-ref-list{display:grid;gap:10px}
.ia-ref-item{padding:12px 14px;border:1px solid #e2e8f0;border-radius:12px;background:#fff}
.ia-ref-top{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.ia-ref-badge{display:inline-flex;padding:5px 9px;border-radius:999px;background:#e2e8f0;color:#0f172a;font-size:11px;font-weight:900;letter-spacing:.03em;text-transform:uppercase}
.ia-ref-code{font-size:13px;font-weight:900;color:#1d4ed8}
.ia-ref-title{margin-top:6px;font-weight:700;color:#0f172a;font-size:14px}
.ia-ref-detail{margin-top:4px;color:#64748b;font-size:13px}
.ia-engage-wrap{display:flex;align-items:center;gap:14px}
.ia-engage-bar{position:relative;flex:1;height:18px;border-radius:999px;background:linear-gradient(90deg,#ef4444 0%,#f59e0b 45%,#22c55e 100%);overflow:hidden}
.ia-engage-thumb{position:absolute;top:50%;width:20px;height:20px;border-radius:999px;background:#fff;border:3px solid #0f172a;transform:translate(-50%,-50%);box-shadow:0 2px 8px rgba(0,0,0,.18)}
.ia-engage-score{min-width:86px;text-align:right;font-size:14px;font-weight:900;color:#0f172a}
.ia-history{width:100%;border-collapse:collapse}
.ia-history th,.ia-history td{text-align:left;vertical-align:top;padding:12px 10px;border-bottom:1px solid #eef2f7;font-size:14px}
.ia-history th{font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.03em}
.ia-result{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:999px;font-size:16px;font-weight:900}
.ia-result-pass{background:#dcfce7;color:#166534}
.ia-result-fail{background:#fee2e2;color:#991b1b}
.ia-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.ia-field{display:block}
.ia-field.full{grid-column:1 / -1}
.ia-label{display:block;margin-bottom:8px;font-size:13px;font-weight:800;color:#0f172a}
.ia-help{margin-top:6px;font-size:12px;color:#64748b}
.ia-input,.ia-select,.ia-textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:12px;padding:12px 14px;font:inherit;background:#fff;color:#0f172a}
.ia-textarea{min-height:150px;resize:vertical}
.ia-decision-help{margin-top:10px;padding:12px 14px;border-radius:12px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;font-size:13px;line-height:1.5}
.ia-decision-help ul{margin:0;padding-left:18px}
.ia-decision-help li{margin:0 0 8px 0}
.ia-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:18px}
.ia-btn{appearance:none;border:none;border-radius:12px;padding:12px 18px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
.ia-btn-primary{background:#1e3a8a;color:#fff}
.ia-btn-primary:hover{background:#1d3579}
.ia-btn-secondary{background:#e2e8f0;color:#0f172a}
.ia-btn-secondary:hover{background:#cbd5e1}
.ia-btn-small{padding:8px 12px;border-radius:10px;font-size:13px}
.ia-note{font-size:13px;color:#64748b;margin-top:10px}
.ia-modal{position:fixed;inset:0;background:rgba(15,23,42,.58);display:none;align-items:center;justify-content:center;padding:20px;z-index:1000}
.ia-modal.is-open{display:flex}
.ia-modal-dialog{width:min(1080px,100%);max-height:88vh;overflow:auto;background:#fff;border-radius:20px;box-shadow:0 20px 60px rgba(15,23,42,.28);border:1px solid #dbe3ef}
.ia-modal-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:18px 20px;border-bottom:1px solid #eef2f7}
.ia-modal-title{margin:0;font-size:20px;font-weight:900;color:#0f172a}
.ia-modal-subtitle{margin:6px 0 0 0;font-size:14px;color:#64748b}
.ia-modal-close{background:#e2e8f0;color:#0f172a;border:none;border-radius:10px;padding:10px 12px;font-weight:900;cursor:pointer}
.ia-modal-body{padding:18px 20px 20px 20px}
.ia-item-card{border:1px solid #e2e8f0;border-radius:14px;padding:14px 16px;background:#f8fafc}
.ia-item-card + .ia-item-card{margin-top:14px}
.ia-qhead{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin-bottom:10px}
.ia-qidx{font-size:12px;font-weight:900;letter-spacing:.03em;text-transform:uppercase;color:#64748b}
.ia-qscore{font-size:13px;font-weight:900;color:#0f172a}
.ia-prompt{font-size:15px;font-weight:800;color:#0f172a;line-height:1.45}
.ia-transcript{margin-top:10px;padding:12px 14px;border-radius:12px;background:#fff;border:1px solid #e2e8f0;color:#334155;line-height:1.55}
.ia-audio{margin-top:12px}
.ia-override-box{margin-top:12px;padding:12px 14px;border-radius:12px;background:#fff;border:1px solid #dbeafe}
.ia-override-meta{font-size:12px;color:#64748b;margin-top:8px}
.ia-summary-view{border:1px solid #e2e8f0;border-radius:14px;background:#fff;padding:16px;line-height:1.6;color:#0f172a}
@media (max-width: 980px){
    .ia-grid{grid-template-columns:1fr}
    .ia-meta,.ia-form-grid{grid-template-columns:1fr}
    .ia-identity{align-items:flex-start}
}
</style>

<div class="ia-shell">
    <div class="ia-pagehead">
        <div class="ia-kicker">Instructor Workflow</div>
        <h1 class="ia-title"><?= h2($pageTitle) ?></h1>
        <p class="ia-subtitle"><?= h2($pageSubtitle) ?></p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="ia-alert error"><?= h2($error) ?></div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="ia-alert success"><?= h2($success) ?></div>
    <?php endif; ?>

    <div class="ia-grid">
        <div class="ia-stack">
            <section class="ia-card">
                <div class="ia-cardhead">
                    <h2 class="ia-cardtitle">Review Context</h2>
                    <p class="ia-cardsub">Instructor-only review summary for the blocked lesson progression.</p>
                </div>
                <div class="ia-cardbody">
                    <div class="ia-meta">
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Student</span>
                            <div class="ia-identity">
                                <div class="ip-avatar">
                                    <?php if (!empty($studentUser['photo_path'])): ?>
                                        <img src="<?= h2((string)$studentUser['photo_path']) ?>" alt="<?= h2($studentName) ?>">
                                    <?php else: ?>
                                        <span class="ip-avatar-fallback"><?= function_exists('ip_svg') ? ip_svg('users') : '👤' ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="ia-meta-value">
                                    <?= h2($studentName) ?>
                                    <?php if (!empty($studentUser['email'])): ?>
                                        <div style="font-size:12px;color:#64748b;margin-top:4px;"><?= h2((string)$studentUser['email']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Chief Instructor</span>
                            <div class="ia-identity">
                                <div class="ip-avatar">
                                    <?php if (!empty($chiefInstructorUser['photo_path'])): ?>
                                        <img src="<?= h2((string)$chiefInstructorUser['photo_path']) ?>" alt="<?= h2($chiefInstructorName) ?>">
                                    <?php else: ?>
                                        <span class="ip-avatar-fallback"><?= function_exists('ip_svg') ? ip_svg('users') : '👤' ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="ia-meta-value">
                                    <?= h2($chiefInstructorName) ?>
                                    <?php if ($chiefInstructorEmail !== ''): ?>
                                        <div style="font-size:12px;color:#64748b;margin-top:4px;"><?= h2($chiefInstructorEmail) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Lesson</span>
                            <div class="ia-meta-value"><?= h2($lessonTitle) ?></div>
                        </div>

                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Cohort</span>
                            <div class="ia-meta-value"><?= h2($cohortTitle) ?></div>
                        </div>

                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Action Status</span>
                            <div class="ia-meta-value">
                                <span class="ia-status <?= h2($currentActionStatus !== '' ? $currentActionStatus : 'pending') ?>">
                                    <?= h2(strtoupper(str_replace('_', ' ', $currentActionStatus !== '' ? $currentActionStatus : 'pending'))) ?>
                                </span>
                            </div>
                        </div>

                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Progress Test ID</span>
                            <div class="ia-meta-value"><?= $progressTestId > 0 ? h2((string)$progressTestId) : '—' ?></div>
                        </div>

                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Latest Attempt</span>
                            <div class="ia-meta-value"><?= $latestAttemptNo !== '' ? h2($latestAttemptNo) : '—' ?></div>
                        </div>

                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Latest Score</span>
                            <div class="ia-meta-value"><?= $latestScorePct !== '' ? h2($latestScorePct) . '%' : '—' ?></div>
                        </div>
                    </div>

                    <div class="ia-block" style="margin-top:18px;">
                        <h3 class="ia-block-title">Areas of Difficulty</h3>
                        <ul class="ia-bullets">
                            <?php foreach ($difficultyBullets as $bullet): ?>
                                <li><?= $bullet ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <div class="ia-block">
                        <h3 class="ia-block-title">Official References Requiring Attention</h3>
                        <?php if (!$lessonRefs): ?>
                            <div class="ia-block-text">No official lesson references were found for this lesson.</div>
                        <?php else: ?>
                            <div class="ia-ref-list">
                                <?php foreach (array_slice($lessonRefs, 0, 8) as $ref): ?>
                                    <div class="ia-ref-item">
                                        <div class="ia-ref-top">
                                            <span class="ia-ref-badge"><?= h2((string)($ref['ref_type'] ?? 'REF')) ?></span>
                                            <?php if (trim((string)($ref['ref_code'] ?? '')) !== ''): ?>
                                                <span class="ia-ref-code"><?= h2((string)$ref['ref_code']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (trim((string)($ref['ref_title'] ?? '')) !== ''): ?>
                                            <div class="ia-ref-title"><?= h2((string)$ref['ref_title']) ?></div>
                                        <?php endif; ?>
                                        <?php if (trim((string)($ref['ref_detail'] ?? '')) !== ''): ?>
                                            <div class="ia-ref-detail"><?= h2((string)$ref['ref_detail']) ?></div>
                                        <?php elseif (trim((string)($ref['notes'] ?? '')) !== ''): ?>
                                            <div class="ia-ref-detail"><?= h2((string)$ref['notes']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="ia-block">
                        <h3 class="ia-block-title">Recommendation</h3>
                        <ul class="ia-bullets">
                            <?php foreach ($recommendations as $rec): ?>
                                <li><?= $rec ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <div class="ia-block">
                        <h3 class="ia-block-title">Motivation / Engagement Indicator</h3>
                        <div class="ia-engage-wrap">
                            <div class="ia-engage-bar">
                                <div class="ia-engage-thumb" style="left: <?= h2((string)$engagement['score']) ?>%;"></div>
                            </div>
                            <div class="ia-engage-score"><?= h2((string)$engagement['score']) ?> / 100</div>
                        </div>
                        <div class="ia-note" style="margin-top:10px;">
                            Current indication: <strong><?= h2((string)$engagement['label']) ?></strong>
                        </div>
                        <ul class="ia-bullets" style="margin-top:10px;">
                            <?php foreach ((array)$engagement['rationale'] as $line): ?>
                                <li><?= h2((string)$line) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </section>

            <section class="ia-card">
                <div class="ia-cardhead">
                    <h2 class="ia-cardtitle">Recent Attempt History</h2>
                    <p class="ia-cardsub">Most recent progress test results for this lesson.</p>
                </div>
                <div class="ia-cardbody" style="padding-top:8px;">
                    <?php if (!$attemptHistory): ?>
                        <div class="ia-note">No attempt history found.</div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="ia-history">
                                <thead>
                                    <tr>
                                        <th>Attempt</th>
                                        <th>Score</th>
                                        <th>Result</th>
                                        <th>Main Reason / Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($attemptHistory as $row): ?>
                                        <?php
                                        $rowTestId = (int)($row['id'] ?? 0);
                                        $rowScore = (float)($row['score_pct'] ?? 0);
                                        $rowReason = short_text((string)(($row['weak_areas'] ?? '') ?: ($row['ai_summary'] ?? '')), 130);
                                        ?>
                                        <tr>
                                            <td><?= h2((string)($row['attempt'] ?? '')) ?></td>
                                            <td><?= isset($row['score_pct']) && $row['score_pct'] !== null ? h2((string)$row['score_pct']) . '%' : '—' ?></td>
                                            <td><?= score_to_icon($rowScore, (string)($row['formal_result_code'] ?? '')) ?></td>
                                            <td>
                                                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                                    <span><?= h2($rowReason) ?></span>
                                                    <button type="button" class="ia-btn ia-btn-secondary ia-btn-small" data-open-modal="modal-test-<?= h2((string)$rowTestId) ?>">Test Details</button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if ($summaryRow): ?>
                        <div class="ia-actions" style="margin-top:18px;">
                            <button type="button" class="ia-btn ia-btn-secondary ia-btn-small" data-open-modal="modal-summary">View Lesson Summary</button>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="ia-stack">
            <section class="ia-card">
                <div class="ia-cardhead">
                    <h2 class="ia-cardtitle">Current Action State</h2>
                    <p class="ia-cardsub">Canonical state of this instructor approval action.</p>
                </div>
                <div class="ia-cardbody">
                    <div class="ia-meta" style="grid-template-columns:1fr;">
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Decision Code</span>
                            <div class="ia-meta-value"><?= $currentDecisionCode !== '' ? h2($currentDecisionCode) : '—' ?></div>
                        </div>
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Granted Extra Attempts</span>
                            <div class="ia-meta-value"><?= h2((string)($action['granted_extra_attempts'] ?? 0)) ?></div>
                        </div>
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Summary Revision Required</span>
                            <div class="ia-meta-value"><?= h2(yesno2((int)($action['summary_revision_required'] ?? 0))) ?></div>
                        </div>
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">One-on-One Required</span>
                            <div class="ia-meta-value"><?= h2(yesno2((int)($action['one_on_one_required'] ?? 0))) ?></div>
                        </div>
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Training Suspended</span>
                            <div class="ia-meta-value"><?= h2(yesno2((int)($action['training_suspended'] ?? 0))) ?></div>
                        </div>
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Major Intervention Flag</span>
                            <div class="ia-meta-value"><?= h2(yesno2((int)($action['major_intervention_flag'] ?? 0))) ?></div>
                        </div>
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Lesson Completion Status</span>
                            <div class="ia-meta-value"><?= h2((string)(($activity['completion_status'] ?? '') ?: '—')) ?></div>
                        </div>
                    </div>

                    <div class="ia-block" style="margin-top:18px;">
                        <h3 class="ia-block-title">Instructor Notes</h3>
                        <div class="ia-block-text"><?= $currentDecisionNotes !== '' ? hnl($currentDecisionNotes) : 'No instructor notes recorded yet.' ?></div>
                    </div>
                </div>
            </section>

            <?php if ((string)($action['status'] ?? '') !== 'approved'): ?>
                <section class="ia-card">
                    <div class="ia-cardhead">
                        <h2 class="ia-cardtitle">Record Instructor Decision</h2>
                        <p class="ia-cardsub">Choose the next operational path for this student.</p>
                    </div>
                    <div class="ia-cardbody">
                        <form method="post" action="">
                            <input type="hidden" name="token" value="<?= h2($token) ?>">

                            <div class="ia-form-grid">
                                <div class="ia-field full">
                                    <label class="ia-label" for="decision_code">Decision</label>
                                    <select class="ia-select" name="decision_code" id="decision_code" required>
                                        <option value="">Select a decision</option>
                                        <option value="approve_additional_attempts">Grant additional attempts</option>
                                        <option value="approve_with_summary_revision">Require summary revision before retry</option>
                                        <option value="approve_with_one_on_one">Require one-on-one instructor session</option>
                                        <option value="suspend_training">Suspend training pending major review</option>
                                    </select>
                                    <div class="ia-decision-help">
                                        <strong>Decision guide:</strong>
                                        <ul>
                                            <li><strong>Grant additional attempts</strong> — student may continue after approval.</li>
                                            <li><strong>Require summary revision before retry</strong> — student must revise the lesson summary before continuing.</li>
                                            <li><strong>Require one-on-one instructor session</strong> — student must complete an instructor session before continuing.</li>
                                            <li><strong>Suspend training pending major review</strong> — stop further progression pending higher-level intervention.</li>
                                        </ul>
                                    </div>
                                </div>

                                <div class="ia-field">
                                    <label class="ia-label" for="granted_extra_attempts">Extra attempts to grant</label>
                                    <input
                                        class="ia-input"
                                        type="number"
                                        name="granted_extra_attempts"
                                        id="granted_extra_attempts"
                                        min="0"
                                        max="5"
                                        step="1"
                                        value="0"
                                    >
                                    <div class="ia-help">Use this when granting additional attempts. Leave at 0 for the other paths.</div>
                                </div>

                                <div class="ia-field full">
                                    <label class="ia-label" for="decision_notes">Instructor decision notes</label>
                                    <textarea
                                        class="ia-textarea"
                                        name="decision_notes"
                                        id="decision_notes"
                                        required
                                        placeholder="Explain why this decision was made, what the student must do next, and any operational notes for audit/history."
                                    ></textarea>
                                    <div class="ia-help">These notes become part of the audit trail.</div>
                                </div>
                            </div>

                            <div class="ia-actions">
                                <button type="submit" class="ia-btn ia-btn-primary">Save Instructor Decision</button>
                            </div>
                        </form>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (
                (string)($action['status'] ?? '') === 'approved' &&
                (int)($action['one_on_one_required'] ?? 0) === 1 &&
                (int)($activity['one_on_one_completed'] ?? 0) !== 1 &&
                (int)($action['training_suspended'] ?? 0) !== 1
            ): ?>
                <section class="ia-card">
                    <div class="ia-cardhead">
                        <h2 class="ia-cardtitle">One-on-One Session</h2>
                        <p class="ia-cardsub">Confirm completion of the required instructor session.</p>
                    </div>
                    <div class="ia-cardbody">
                        <form method="post" action="">
                            <input type="hidden" name="token" value="<?= h2($token) ?>">
                            <input type="hidden" name="mark_instructor_session_completed" value="1">

                            <div class="ia-block">
                                <h3 class="ia-block-title">Pending requirement</h3>
                                <div class="ia-block-text">
                                    This student is currently blocked until the required one-on-one instructor session is completed and recorded.
                                </div>
                            </div>

                            <div class="ia-actions">
                                <button type="submit" class="ia-btn ia-btn-primary">Mark Instructor Session Completed</button>
                            </div>
                        </form>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php foreach ($attemptHistory as $row): ?>
    <?php
    $rowTestId = (int)($row['id'] ?? 0);
    $rowItems = isset($attemptItems[$rowTestId]) && is_array($attemptItems[$rowTestId]) ? $attemptItems[$rowTestId] : [];
    ?>
    <div class="ia-modal" id="modal-test-<?= h2((string)$rowTestId) ?>" aria-hidden="true">
        <div class="ia-modal-dialog">
            <div class="ia-modal-head">
                <div>
                    <h3 class="ia-modal-title">Progress Test Details — Attempt <?= h2((string)($row['attempt'] ?? '')) ?></h3>
                    <div class="ia-modal-subtitle">
                        Score <?= isset($row['score_pct']) ? h2((string)$row['score_pct']) : '—' ?>%
                        · Completed <?= h2(format_dt_utc((string)($row['completed_at'] ?? ''))) ?>
                    </div>
                </div>
                <button type="button" class="ia-modal-close" data-close-modal>Close</button>
            </div>
            <div class="ia-modal-body">
                <?php if (!$rowItems): ?>
                    <div class="ia-note">No question-level detail found for this attempt.</div>
                <?php else: ?>
                    <?php foreach ($rowItems as $item): ?>
                        <?php
                        $audioUrl = ipca_media_url((string)($item['audio_path'] ?? ''));
                        $overrideId = (int)($item['override_id'] ?? 0);
                        ?>
                        <div class="ia-item-card">
                            <div class="ia-qhead">
                                <div>
                                    <div class="ia-qidx">Question <?= h2((string)($item['idx'] ?? '')) ?></div>
                                    <div class="ia-prompt"><?= h2((string)($item['prompt'] ?? '')) ?></div>
                                </div>
                                <div class="ia-qscore">
                                    Score: <?= h2((string)($item['score_points'] ?? 0)) ?> / <?= h2((string)($item['max_points'] ?? 0)) ?>
                                </div>
                            </div>

                            <div class="ia-block-text"><strong>AI scoring:</strong> <?= ((int)($item['is_correct'] ?? 0) === 1) ? 'Correct / acceptable' : 'Unsatisfactory / incomplete' ?></div>

                            <div class="ia-transcript">
                                <strong>Transcribed answer:</strong><br>
                                <?= trim((string)($item['transcript_text'] ?? '')) !== '' ? hnl((string)$item['transcript_text']) : 'No transcript text stored.' ?>
                            </div>

                            <?php if ($audioUrl !== ''): ?>
                                <div class="ia-audio">
                                    <strong>Audio review:</strong><br>
                                    <audio controls preload="none" style="width:100%;margin-top:8px;">
                                        <source src="<?= h2($audioUrl) ?>" type="audio/webm">
                                    </audio>
                                </div>
                            <?php endif; ?>

                            <div class="ia-override-box">
                                <strong>Instructor override</strong>
                                <form method="post" action="" style="margin-top:12px;">
                                    <input type="hidden" name="token" value="<?= h2($token) ?>">
                                    <input type="hidden" name="override_item_score" value="1">
                                    <input type="hidden" name="item_id" value="<?= h2((string)($item['id'] ?? 0)) ?>">

                                    <div class="ia-form-grid">
                                        <div class="ia-field">
                                            <label class="ia-label">Override score points</label>
                                            <input class="ia-input" type="number" name="override_score_points" min="0" max="<?= h2((string)($item['max_points'] ?? 0)) ?>" step="1" value="<?= h2((string)($item['score_points'] ?? 0)) ?>">
                                        </div>

                                        <div class="ia-field">
                                            <label class="ia-label">Override result</label>
                                            <select class="ia-select" name="override_is_correct">
                                                <option value="1" <?= ((int)($item['is_correct'] ?? 0) === 1) ? 'selected' : '' ?>>Correct / acceptable</option>
                                                <option value="0" <?= ((int)($item['is_correct'] ?? 0) !== 1) ? 'selected' : '' ?>>Unsatisfactory / incomplete</option>
                                            </select>
                                        </div>

                                        <div class="ia-field full">
                                            <label class="ia-label">Reason for override</label>
                                            <textarea class="ia-textarea" name="override_reason" rows="4" required placeholder="Explain why the AI score was changed after instructor review."></textarea>
                                        </div>
                                    </div>

                                    <div class="ia-actions">
                                        <button type="submit" class="ia-btn ia-btn-primary ia-btn-small">Save Override</button>
                                    </div>
                                </form>

                                <?php if ($overrideId > 0): ?>
                                    <div class="ia-override-meta">
                                        Latest override by <?= h2((string)($item['override_user_name'] ?? 'Instructor')) ?>
                                        on <?= h2(format_dt_utc((string)($item['override_created_at'] ?? ''))) ?>.
                                        Reason: <?= h2((string)($item['override_reason'] ?? '')) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($summaryRow): ?>
    <div class="ia-modal" id="modal-summary" aria-hidden="true">
        <div class="ia-modal-dialog">
            <div class="ia-modal-head">
                <div>
                    <h3 class="ia-modal-title">Student Lesson Summary</h3>
                    <div class="ia-modal-subtitle">
                        Review status: <?= h2((string)($summaryRow['review_status'] ?? '—')) ?>
                        · Review score: <?= isset($summaryRow['review_score']) ? h2((string)$summaryRow['review_score']) . '%' : '—' ?>
                    </div>
                </div>
                <button type="button" class="ia-modal-close" data-close-modal>Close</button>
            </div>
            <div class="ia-modal-body">
                <div class="ia-summary-view">
                    <?= trim((string)($summaryRow['summary_html'] ?? '')) !== '' ? (string)$summaryRow['summary_html'] : 'No summary content stored.' ?>
                </div>

                <div class="ia-block" style="margin-top:14px;">
                    <h3 class="ia-block-title">AI Review Feedback</h3>
                    <div class="ia-block-text"><?= trim((string)($summaryRow['review_feedback'] ?? '')) !== '' ? hnl((string)$summaryRow['review_feedback']) : 'No review feedback stored.' ?></div>
                </div>

                <div class="ia-block" style="margin-top:14px;">
                    <h3 class="ia-block-title">Instructor Notes Visible in Summary Workflow</h3>
                    <form method="post" action="">
                        <input type="hidden" name="token" value="<?= h2($token) ?>">
                        <input type="hidden" name="save_summary_notes" value="1">
                        <input type="hidden" name="summary_id" value="<?= h2((string)($summaryRow['id'] ?? 0)) ?>">
                        <textarea class="ia-textarea" name="review_notes_by_instructor" rows="6"><?= h2((string)($summaryRow['review_notes_by_instructor'] ?? '')) ?></textarea>
                        <div class="ia-actions">
                            <button type="submit" class="ia-btn ia-btn-primary ia-btn-small">Save Summary Notes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
(function(){
    var modalOpenButtons = document.querySelectorAll('[data-open-modal]');
    var modalCloseButtons = document.querySelectorAll('[data-close-modal]');

    function openModal(id){
        var modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal){
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    modalOpenButtons.forEach(function(btn){
        btn.addEventListener('click', function(){
            openModal(btn.getAttribute('data-open-modal'));
        });
    });

    modalCloseButtons.forEach(function(btn){
        btn.addEventListener('click', function(){
            closeModal(btn.closest('.ia-modal'));
        });
    });

    document.querySelectorAll('.ia-modal').forEach(function(modal){
        modal.addEventListener('click', function(e){
            if (e.target === modal) {
                closeModal(modal);
            }
        });
    });

    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') {
            document.querySelectorAll('.ia-modal.is-open').forEach(function(modal){
                closeModal(modal);
            });
        }
    });
})();
</script>

<?php cw_footer(); ?>