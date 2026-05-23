<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/progress_test_prep.php';
require_once __DIR__ . '/../../../src/courseware_progression_v2.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pt_manual_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pt_manual_read_json(): array
{
    $raw = (string)file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        pt_manual_json(['ok' => false, 'error' => 'POST required'], 405);
    }

    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') {
        pt_manual_json(['ok' => false, 'error' => 'Forbidden'], 403);
    }

    $data = pt_manual_read_json();
    $cohortId = (int)($data['cohort_id'] ?? 0);
    $lessonId = (int)($data['lesson_id'] ?? 0);
    if ($cohortId <= 0 || $lessonId <= 0) {
        pt_manual_json(['ok' => false, 'error' => 'Missing cohort_id or lesson_id'], 400);
    }

    $userId = $role === 'admin' ? (int)($data['user_id'] ?? $u['id']) : (int)$u['id'];
    if ($userId <= 0) {
        pt_manual_json(['ok' => false, 'error' => 'Invalid user'], 403);
    }

    if ($role === 'student') {
        $en = $pdo->prepare("
            SELECT 1 FROM cohort_students
            WHERE cohort_id = ? AND user_id = ? AND status = 'active'
            LIMIT 1
        ");
        $en->execute([$cohortId, $userId]);
        if (!$en->fetchColumn()) {
            pt_manual_json(['ok' => false, 'error' => 'Not actively enrolled'], 403);
        }
    }

    $cookieHeader = (string)($_SERVER['HTTP_COOKIE'] ?? '');
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $scheduled = pt_prep_schedule_progress_test(
        $pdo,
        $userId,
        $cohortId,
        $lessonId,
        'manual_prepare',
        $cookieHeader,
        $role === 'admin' ? 'admin' : 'student',
        (int)$u['id']
    );

    if (!empty($scheduled['skipped']) && ($scheduled['reason'] ?? '') === 'canonical_pass_exists') {
        pt_manual_json(['ok' => false, 'blocked' => true, 'reason' => 'canonical_pass_exists', 'error' => 'This lesson already has a passing progress test on record.'], 409);
    }

    if (!empty($scheduled['skipped']) && empty($scheduled['scheduled']) && ($scheduled['reason'] ?? '') !== 'already_prepared') {
        $reason = (string)($scheduled['reason'] ?? 'blocked');
        $message = 'Progress test preparation blocked.';
        if ($reason === 'summary_required') {
            $message = 'An acceptable lesson summary is required before this progress test can be prepared.';
        } elseif ($reason === 'deadline') {
            $message = 'The effective deadline for this lesson has passed.';
        } elseif ($reason === 'training_suspended') {
            $message = 'Training is currently suspended for this lesson.';
        } elseif ($reason === 'instructor_required') {
            $message = 'Instructor approval is required before another attempt can begin.';
        } elseif ($reason === 'remediation_required') {
            $message = 'Remediation is required before another attempt can begin.';
        } elseif ($reason === 'active_oral_session') {
            $message = 'An active progress test session is already in progress.';
        }
        pt_manual_json(['ok' => false, 'blocked' => true, 'reason' => $reason, 'error' => $message], 409);
    }

    if (empty($scheduled['ok']) && empty($scheduled['scheduled']) && ($scheduled['reason'] ?? '') !== 'already_prepared') {
        pt_manual_json(['ok' => false, 'error' => (string)($scheduled['error'] ?? 'Could not start preparation.')], 400);
    }

    $testId = (int)($scheduled['test_id'] ?? 0);
    if ($testId <= 0) {
        $attempt = pt_prep_get_open_attempt($pdo, $userId, $cohortId, $lessonId);
        $testId = $attempt ? (int)$attempt['id'] : 0;
    }
    if ($testId <= 0) {
        pt_manual_json(['ok' => false, 'error' => 'Could not locate progress test attempt.'], 500);
    }

    $attempt = pt_prep_get_open_attempt($pdo, $userId, $cohortId, $lessonId) ?: ['id' => $testId];
    $prepared = pt_prep_attempt_is_prepared($attempt, $pdo);
    $display = pt_prep_progress_label($attempt, $pdo);

    pt_manual_json([
        'ok' => true,
        'test_id' => $testId,
        'prepared' => $prepared,
        'preparing' => !$prepared,
        'display' => $display,
    ]);
} catch (Throwable $e) {
    pt_manual_json(['ok' => false, 'error' => $e->getMessage()], 400);
}
