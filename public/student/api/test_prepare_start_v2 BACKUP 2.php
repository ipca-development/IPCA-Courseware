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

    $policy = $engine->getAllPolicies([
        'cohort_id' => $cohortId
    ]);

    $summaryRequiredBeforeTestStart = !empty($policy['summary_required_before_test_start']);
$initialAttemptLimit = (int)($policy['initial_attempt_limit'] ?? 3);
$extraAttemptsAfterThresholdFail = (int)($policy['extra_attempts_after_threshold_fail'] ?? 2);
$maxTotalAttemptsWithoutAdminOverride = (int)($policy['max_total_attempts_without_admin_override'] ?? 5);
$thresholdAttemptForRemediationEmail = (int)($policy['threshold_attempt_for_remediation_email'] ?? 3);

if ($initialAttemptLimit <= 0) {
    $initialAttemptLimit = 3;
}
if ($extraAttemptsAfterThresholdFail < 0) {
    $extraAttemptsAfterThresholdFail = 0;
}
if ($maxTotalAttemptsWithoutAdminOverride <= 0) {
    $maxTotalAttemptsWithoutAdminOverride = 5;
}
if ($thresholdAttemptForRemediationEmail <= 0) {
    $thresholdAttemptForRemediationEmail = 3;
}

/*
 * IMPORTANT:
 * Before remediation acknowledgement is completed, only the initial attempt block is available.
 * After remediation acknowledgement is completed, the student may use the extra attempts.
 */
$baseMaxAllowedAttempts = min($initialAttemptLimit, $maxTotalAttemptsWithoutAdminOverride);

$completedRemediation = $engine->getLatestCompletedRequiredAction(
    $userId,
    $cohortId,
    $lessonId,
    'remediation_acknowledgement'
);

if ($completedRemediation !== null) {
    $baseMaxAllowedAttempts = min(
        $initialAttemptLimit + $extraAttemptsAfterThresholdFail,
        $maxTotalAttemptsWithoutAdminOverride
    );
}

$activityGateSt = $pdo->prepare("
    SELECT
        granted_extra_attempts,
        one_on_one_required,
        one_on_one_completed,
        training_suspended
    FROM lesson_activity
    WHERE user_id = ?
      AND cohort_id = ?
      AND lesson_id = ?
    LIMIT 1
");
$activityGateSt->execute([$userId, $cohortId, $lessonId]);
$activityGate = $activityGateSt->fetch(PDO::FETCH_ASSOC) ?: [];

$grantedExtraAttempts = max(0, (int)($activityGate['granted_extra_attempts'] ?? 0));
$oneOnOneRequired = (int)($activityGate['one_on_one_required'] ?? 0);
$oneOnOneCompleted = (int)($activityGate['one_on_one_completed'] ?? 0);
$trainingSuspended = (int)($activityGate['training_suspended'] ?? 0);

$maxAllowedAttempts = $baseMaxAllowedAttempts + $grantedExtraAttempts;

    $summaryStatus = 'missing';

    if ($summaryRequiredBeforeTestStart) {
        $sum = $pdo->prepare("
            SELECT
                id,
                review_status,
                review_score,
                summary_plain
            FROM lesson_summaries
            WHERE user_id = ?
              AND cohort_id = ?
              AND lesson_id = ?
            LIMIT 1
        ");
        $sum->execute([$userId, $cohortId, $lessonId]);
        $summaryRow = $sum->fetch(PDO::FETCH_ASSOC);

        if (!$summaryRow) {
            json_ok([
                'ok' => false,
                'error' => 'A lesson summary is required before the progress test can start.'
            ]);
        }

        $summaryStatus = (string)($summaryRow['review_status'] ?? 'pending');

        if ($summaryStatus !== 'acceptable') {
            json_ok([
                'ok' => false,
                'error' => 'Your lesson summary must be acceptable before the progress test can start.'
            ]);
        }
    } else {
        $sum = $pdo->prepare("
            SELECT review_status
            FROM lesson_summaries
            WHERE user_id = ?
              AND cohort_id = ?
              AND lesson_id = ?
            LIMIT 1
        ");
        $sum->execute([$userId, $cohortId, $lessonId]);
        $sumReview = $sum->fetchColumn();
        if (is_string($sumReview) && $sumReview !== '') {
            $summaryStatus = $sumReview;
        }
    }

    /**
     * NEW:
     * Block further attempts until remediation acknowledgement is completed.
     */
    $pendingRemediation = $engine->getPendingRequiredAction(
        $userId,
        $cohortId,
        $lessonId,
        'remediation_acknowledgement'
    );

    if ($pendingRemediation) {
        json_ok([
            'ok' => false,
            'error' => 'You must review and acknowledge the remediation instructions before additional attempts become available.'
        ]);
    }

    /**
     * NEW:
     * Block further attempts until deadline reason submission is completed.
     */
$pendingInstructorApproval = $engine->getPendingRequiredAction(
    $userId,
    $cohortId,
    $lessonId,
    'instructor_approval'
);

if ($pendingInstructorApproval) {
    json_ok([
        'ok' => false,
        'error' => 'Further attempts are blocked pending instructor approval.'
    ]);
}

if ($trainingSuspended === 1) {
    json_ok([
        'ok' => false,
        'error' => 'Further attempts are blocked because training progression has been suspended.'
    ]);
}

if ($oneOnOneRequired === 1 && $oneOnOneCompleted !== 1) {
    json_ok([
        'ok' => false,
        'error' => 'Further attempts are blocked until the required instructor session is completed.'
    ]);
}	
	
    $mx = $pdo->prepare("
        SELECT MAX(attempt)
        FROM progress_tests_v2
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id = ?
    ");
    $mx->execute([$userId, $cohortId, $lessonId]);
    $attempt = (int)$mx->fetchColumn() + 1;
    if ($attempt <= 0) {
        $attempt = 1;
    }

    if ($attempt > $maxAllowedAttempts) {
        json_ok([
            'ok' => false,
            'error' => 'Maximum allowed attempts reached for this lesson.'
        ]);
    }

    $deadlineMeta = $engine->getEffectiveDeadline($userId, $cohortId, $lessonId);
    $effectiveDeadlineUtc = (string)($deadlineMeta['effective_deadline_utc'] ?? '');
    $deadlineSource = (string)($deadlineMeta['deadline_source'] ?? 'cohort_default');

    if ($effectiveDeadlineUtc === '') {
        throw new RuntimeException('Unable to resolve effective deadline');
    }

    $nowUtc = gmdate('Y-m-d H:i:s');
    $deadlinePassed = (strtotime($nowUtc) > strtotime($effectiveDeadlineUtc));

    $seed = bin2hex(random_bytes(16));

    $pdo->beginTransaction();

    $activitySel = $pdo->prepare("
        SELECT id, attempt_count, best_score, completed_at
        FROM lesson_activity
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id = ?
        LIMIT 1
    ");
    $activitySel->execute([$userId, $cohortId, $lessonId]);
    $activityRow = $activitySel->fetch(PDO::FETCH_ASSOC);

    if ($deadlinePassed) {
        if ($activityRow) {
            $updBlocked = $pdo->prepare("
                UPDATE lesson_activity
                SET
                    summary_status = ?,
                    test_pass_status = 'deadline_missed',
                    completion_status = 'blocked_deadline',
                    effective_deadline_utc = ?,
                    last_state_eval_at = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $updBlocked->execute([
                $summaryStatus,
                $effectiveDeadlineUtc,
                $nowUtc,
                (int)$activityRow['id']
            ]);
        } else {
            $insBlocked = $pdo->prepare("
                INSERT INTO lesson_activity
                (
                    user_id,
                    cohort_id,
                    lesson_id,
                    started_at,
                    completed_at,
                    total_seconds,
                    attempt_count,
                    best_score,
                    summary_status,
                    test_pass_status,
                    completion_status,
                    effective_deadline_utc,
                    extension_count,
                    final_warning_issued,
                    reason_required,
                    reason_submitted,
                    reason_decision,
                    next_lesson_unlocked_at,
                    last_state_eval_at,
                    status,
                    created_at,
                    updated_at
                )
                VALUES
                (
                    ?, ?, ?, NULL, NULL, 0, 0, NULL, ?, 'deadline_missed', 'blocked_deadline',
                    ?, 0, 0, 0, 0, NULL, NULL, ?, 'in_progress', NOW(), NOW()
                )
            ");
            $insBlocked->execute([
                $userId,
                $cohortId,
                $lessonId,
                $summaryStatus,
                $effectiveDeadlineUtc,
                $nowUtc
            ]);
        }

        $pdo->commit();

        json_ok([
            'ok' => false,
            'error' => 'The effective deadline for this lesson has passed. This progress test is currently blocked.'
        ]);
    }

    if ($activityRow) {
        $updActivity = $pdo->prepare("
            UPDATE lesson_activity
            SET
                started_at = COALESCE(started_at, ?),
                summary_status = ?,
                test_pass_status = 'in_progress',
                completion_status = 'in_progress',
                effective_deadline_utc = ?,
                last_state_eval_at = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $updActivity->execute([
            $nowUtc,
            $summaryStatus,
            $effectiveDeadlineUtc,
            $nowUtc,
            (int)$activityRow['id']
        ]);
    } else {
        $insActivity = $pdo->prepare("
            INSERT INTO lesson_activity
            (
                user_id,
                cohort_id,
                lesson_id,
                started_at,
                completed_at,
                total_seconds,
                attempt_count,
                best_score,
                summary_status,
                test_pass_status,
                completion_status,
                effective_deadline_utc,
                extension_count,
                final_warning_issued,
                reason_required,
                reason_submitted,
                reason_decision,
                next_lesson_unlocked_at,
                last_state_eval_at,
                status,
                created_at,
                updated_at
            )
            VALUES
            (
                ?, ?, ?, ?, NULL, 0, 0, NULL, ?, 'in_progress', 'in_progress',
                ?, 0, 0, 0, 0, NULL, NULL, ?, 'in_progress', NOW(), NOW()
            )
        ");
        $insActivity->execute([
            $userId,
            $cohortId,
            $lessonId,
            $nowUtc,
            $summaryStatus,
            $effectiveDeadlineUtc,
            $nowUtc
        ]);
    }

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
            effective_deadline_utc,
            deadline_source,
            timing_status,
            progress_pct,
            status_text,
            updated_at
        )
        VALUES
        (
            ?, ?, ?, ?, 'preparing', ?, NOW(), ?, ?, 'unknown', ?, ?, NOW()
        )
    ");

    $ins->execute([
        $userId,
        $cohortId,
        $lessonId,
        $attempt,
        $seed,
        $effectiveDeadlineUtc,
        $deadlineSource,
        1,
        'Initializing progress test...'
    ]);

    $testId = (int)$pdo->lastInsertId();
    if ($testId <= 0) {
        throw new RuntimeException('Failed to create progress test');
    }

    $engine->logProgressionEvent([
        'user_id' => $userId,
        'cohort_id' => $cohortId,
        'lesson_id' => $lessonId,
        'progress_test_id' => $testId,
        'event_type' => 'attempt',
        'event_code' => 'progress_test_created',
        'event_status' => 'info',
        'actor_type' => $role === 'admin' ? 'admin' : 'student',
        'actor_user_id' => $userId,
        'payload' => [
            'attempt' => $attempt,
            'effective_deadline_utc' => $effectiveDeadlineUtc,
            'deadline_source' => $deadlineSource,
            'max_allowed_attempts' => $maxAllowedAttempts
        ],
        'legal_note' => 'Progress test attempt created under active V2 progression policy.'
    ]);

    $pdo->commit();

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