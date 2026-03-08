<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

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
    return 'http://127.0.0.1/student/api/test_prepare_run_v2.php?test_id=' . urlencode((string)$testId);
}

function fire_and_forget_prepare_run(int $testId): void {
    $url = build_background_run_url($testId);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_NOBODY => false,
        CURLOPT_POST => false,
        CURLOPT_TIMEOUT_MS => 800,
        CURLOPT_CONNECTTIMEOUT_MS => 800,
        CURLOPT_FRESH_CONNECT => true,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_FOLLOWLOCATION => true,
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

    if ($role === 'student') {
        $en = $pdo->prepare("
            SELECT 1
            FROM cohort_students
            WHERE cohort_id=? AND user_id=?
            LIMIT 1
        ");
        $en->execute([$cohortId, $userId]);

        if (!$en->fetchColumn()) {
            http_response_code(403);
            json_ok(['ok' => false, 'error' => 'Not enrolled']);
        }
    }

    $mx = $pdo->prepare("
        SELECT MAX(attempt)
        FROM progress_tests_v2
        WHERE user_id=? AND cohort_id=? AND lesson_id=?
    ");
    $mx->execute([$userId, $cohortId, $lessonId]);
    $attempt = (int)$mx->fetchColumn() + 1;
    if ($attempt <= 0) $attempt = 1;

    $seed = bin2hex(random_bytes(16));

    $ins = $pdo->prepare("
        INSERT INTO progress_tests_v2
        (
            user_id,
            cohort_id,
            lesson_id,
            attempt,
            status,
            seed,
            started_at,
            progress_pct,
            status_text,
            updated_at
        )
        VALUES
        (
            ?, ?, ?, ?, 'preparing', ?, NOW(), ?, ?, NOW()
        )
    ");

    $ins->execute([
        $userId,
        $cohortId,
        $lessonId,
        $attempt,
        $seed,
        1,
        'Initializing progress test...'
    ]);

    $testId = (int)$pdo->lastInsertId();
    if ($testId <= 0) {
        throw new RuntimeException('Failed to create progress test');
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
    http_response_code(400);
    json_ok([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}