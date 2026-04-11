<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/courseware_progression_v2.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

function json_ok(array $x): void {
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json(string $s): array {
    $j = json_decode($s, true);
    return is_array($j) ? $j : [];
}

function build_background_run_url(int $testId): string {
    return 'https://ipca.training/student/api/test_prepare_run_v2.php?test_id=' . urlencode((string)$testId);
}

function fire_and_forget_prepare_run(int $testId): void {
    $url = build_background_run_url($testId);

    $cookieHeader = '';
    if (!empty($_SERVER['HTTP_COOKIE'])) {
        $cookieHeader = (string)$_SERVER['HTTP_COOKIE'];
    }

    $headers = [];
    if ($cookieHeader !== '') {
        $headers[] = 'Cookie: ' . $cookieHeader;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_NOBODY => false,
        CURLOPT_POST => false,
        CURLOPT_TIMEOUT_MS => 3000,
        CURLOPT_CONNECTTIMEOUT_MS => 5000,
        CURLOPT_FRESH_CONNECT => true,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    @curl_exec($ch);
    @curl_close($ch);
}

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');

    if ($role !== 'student' && $role !== 'admin') {
        http_response_code(403);
        json_ok(['ok' => false, 'error' => 'Forbidden']);
    }

    $data = read_json((string)file_get_contents('php://input'));
    if (!$data) {
        json_ok(['ok' => false, 'error' => 'Invalid JSON']);
    }

    $cohortId = (int)($data['cohort_id'] ?? 0);
    $lessonId = (int)($data['lesson_id'] ?? 0);

    if ($cohortId <= 0 || $lessonId <= 0) {
        json_ok(['ok' => false, 'error' => 'Missing cohort_id or lesson_id']);
    }

    $userId = (int)($u['id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(403);
        json_ok(['ok' => false, 'error' => 'Invalid user']);
    }

$engine = new CoursewareProgressionV2($pdo);

    if ($role === 'student') {
        $en = $pdo->prepare("
            SELECT 1
            FROM cohort_students
            WHERE cohort_id = ?
              AND user_id = ?
              AND status = 'active'
            LIMIT 1
        ");
        $en->execute([$cohortId, $userId]);

        if (!$en->fetchColumn()) {
            http_response_code(403);
            json_ok(['ok' => false, 'error' => 'Not actively enrolled']);
        }
    }

    // IMPORTANT:
    // Do not gate test start from lesson_activity projection fields.
    // Always use CoursewareProgressionV2::prepareStartDecision() as the canonical source.

    $startDecision = $engine->prepareStartDecision($userId, $cohortId, $lessonId);

    if (!empty($startDecision['deadline_state']['deadline_passed'])) {
    $deadlineHandleResult = $engine->handleMissedDeadlineForLesson($userId, $cohortId, $lessonId, null);

    $requiredActionUrl = (string)($deadlineHandleResult['required_action_url'] ?? '');
    $actionTaken = (string)($deadlineHandleResult['action_taken'] ?? '');

    $message = 'The effective deadline for this lesson has passed. This progress test is currently blocked.';

    if ($actionTaken === 'deadline_reason_required_extension_1') {
        $message = 'The lesson deadline was missed. A deadline extension has been applied and your reason submission is now required.';
    } elseif ($actionTaken === 'deadline_reason_required_extension_2_final') {
        $message = 'The lesson deadline was missed again. A final deadline extension has been applied and your reason submission is now required.';
    } elseif ($actionTaken === 'deadline_missed_instructor_required') {
        $message = 'The lesson deadline path is exhausted. Instructor intervention is now required before progression can continue.';
    } elseif ($actionTaken === 'existing_reason_action_reused') {
        $message = 'A deadline reason submission is already pending for this lesson.';
    } elseif ($actionTaken === 'existing_instructor_action_reused') {
        $message = 'Instructor intervention is already pending for this lesson.';
    }

    http_response_code(409);
    json_ok([
        'ok' => false,
        'blocked' => true,
        'error' => $message,
        'deadline_blocked' => true,
        'deadline_action_taken' => $actionTaken,
        'required_action_url' => $requiredActionUrl,
        'deadline_handle_result' => $deadlineHandleResult,
    ]);
}

    if (empty($startDecision['allowed'])) {
        http_response_code(409);
        json_ok([
            'ok' => false,
            'blocked' => true,
            'error' => 'Progress test start blocked by progression rules.',
            'decision' => $startDecision['decision'],
            'deadline_state' => $startDecision['deadline_state'],
            'summary_state' => $startDecision['summary_state'],
            'attempt_state' => $startDecision['attempt_state'],
            'required_actions' => $startDecision['required_actions'],
        ]);
    }

    $summaryStatus = (string)($startDecision['summary_state']['summary_status'] ?? 'missing');

    $attempt = (int)($startDecision['attempt_state']['next_attempt_number'] ?? 1);
    if ($attempt <= 0) {
        $attempt = 1;
    }

    $maxAllowedAttempts = (int)($startDecision['attempt_state']['effective_allowed_attempts'] ?? 0);

    $effectiveDeadlineUtc = (string)($startDecision['deadline_state']['effective_deadline_utc'] ?? '');
    $deadlineSource = (string)($startDecision['deadline_state']['deadline_source'] ?? 'cohort_default');

    if ($effectiveDeadlineUtc === '') {
        throw new RuntimeException('Unable to resolve effective deadline');
    }

    $nowUtc = gmdate('Y-m-d H:i:s');

    $seed = bin2hex(random_bytes(16));

$create = $engine->createProgressTestAttempt(
    $userId,
    $cohortId,
    $lessonId,
    $role === 'admin' ? 'admin' : 'student'
);

if (!empty($create['blocked'])) {
    http_response_code(409);
    json_ok([
        'ok' => false,
        'blocked' => true,
        'reason' => $create['reason'] ?? 'blocked',
        'decision' => $create['decision'] ?? null
    ]);
}

$testId = (int)$create['test_id'];
$attempt = (int)$create['attempt'];
	
    // Release PHP session lock before internal background request
	if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
	}

	fire_and_forget_prepare_run($testId);

    json_ok([
        'ok' => true,
        'test_id' => $testId,
        'attempt' => $attempt,
        'progress_pct' => 1,
        'status_text' => 'Initializing progress test...'
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    json_ok([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}