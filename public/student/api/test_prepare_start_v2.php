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



$create = $engine->createProgressTestAttempt(
    $userId,
    $cohortId,
    $lessonId,
    $role === 'admin' ? 'admin' : 'student',
    $role === 'admin' ? $userId : null
);

if (!empty($create['blocked'])) {
    http_response_code(409);

    $reason = (string)($create['reason'] ?? 'blocked');
    $message = 'Progress test start blocked.';

    if ($reason === 'deadline') {
        $message = 'The effective deadline for this lesson has passed.';
    } elseif ($reason === 'training_suspended') {
        $message = 'Training is currently suspended for this lesson.';
    } elseif ($reason === 'instructor_required') {
        $message = 'Instructor approval is required before another attempt can begin.';
    } elseif ($reason === 'remediation_required') {
        $message = 'Remediation is required before another attempt can begin.';
    } elseif ($reason === 'summary_required') {
        $message = 'An acceptable lesson summary is required before this progress test can begin.';
    }

    json_ok([
        'ok' => false,
        'blocked' => true,
        'reason' => $reason,
        'error' => $message,
        'decision' => $create['decision'] ?? [],
		'deadline_state' => $create['deadline_state'] ?? [],
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