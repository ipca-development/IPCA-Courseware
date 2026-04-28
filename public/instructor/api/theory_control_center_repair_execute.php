<?php
declare(strict_types=1);

/**
 * public/instructor/api/theory_control_center_repair_execute.php
 *
 * SAFE FIRST DEPLOYMENT:
 * - WRITE endpoint.
 * - Executes ONLY cleanup_old_active_attempt_after_pass.
 * - recompute_projection is intentionally blocked for now.
 * - No policy repair.
 * - No ambiguous repair.
 * - Requires scan evidence test_id.
 */

require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
$currentUserId = (int)($u['id'] ?? 0);

$allowedRoles = array('admin', 'supervisor', 'instructor', 'chief_instructor');

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode(array(
        'ok' => false,
        'error' => 'forbidden',
        'message' => 'Instructor access required.'
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function tcc_repair_json($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function tcc_repair_int($value): int
{
    return (int)($value ?? 0);
}

function tcc_repair_str($value): string
{
    return trim((string)($value ?? ''));
}

function tcc_repair_decode_payload(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode((string)$raw, true);

    if (is_array($json)) {
        return $json;
    }

    return $_POST;
}

function tcc_repair_json_encode($value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function tcc_repair_table_exists(PDO $pdo, string $tableName): bool
{
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");
    $st->execute(array($tableName));
    return ((int)$st->fetchColumn()) > 0;
}

function tcc_repair_fetch_state(PDO $pdo, int $studentId, int $cohortId, int $lessonId, int $testId): array
{
    $pt = $pdo->prepare("
        SELECT *
        FROM progress_tests_v2
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id = ?
        ORDER BY id DESC
    ");
    $pt->execute(array($studentId, $cohortId, $lessonId));

    $la = $pdo->prepare("
        SELECT *
        FROM lesson_activity
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id = ?
        LIMIT 1
    ");
    $la->execute(array($studentId, $cohortId, $lessonId));

    return array(
        'target_test_id' => $testId,
        'progress_tests_v2' => $pt->fetchAll(PDO::FETCH_ASSOC),
        'lesson_activity' => $la->fetch(PDO::FETCH_ASSOC) ?: null,
    );
}

function tcc_repair_insert_log(
    PDO $pdo,
    string $repairCode,
    string $issueType,
    string $blockerCategory,
    string $recurrenceKey,
    int $studentId,
    int $cohortId,
    int $lessonId,
    array $detectedEvidence,
    array $beforeState,
    array $afterState,
    array $result,
    int $executedByUserId
): void {
    $st = $pdo->prepare("
        INSERT INTO tcc_repair_log
        (
            repair_code,
            issue_type,
            blocker_category,
            recurrence_key,
            student_id,
            cohort_id,
            lesson_id,
            detected_evidence_json,
            before_state_json,
            after_state_json,
            result_json,
            executed_by_user_id,
            executed_at_utc
        )
        VALUES
        (
            :repair_code,
            :issue_type,
            :blocker_category,
            :recurrence_key,
            :student_id,
            :cohort_id,
            :lesson_id,
            :detected_evidence_json,
            :before_state_json,
            :after_state_json,
            :result_json,
            :executed_by_user_id,
            :executed_at_utc
        )
    ");

    $st->execute(array(
        ':repair_code' => $repairCode,
        ':issue_type' => $issueType,
        ':blocker_category' => $blockerCategory,
        ':recurrence_key' => $recurrenceKey,
        ':student_id' => $studentId,
        ':cohort_id' => $cohortId,
        ':lesson_id' => $lessonId,
        ':detected_evidence_json' => tcc_repair_json_encode($detectedEvidence),
        ':before_state_json' => tcc_repair_json_encode($beforeState),
        ':after_state_json' => tcc_repair_json_encode($afterState),
        ':result_json' => tcc_repair_json_encode($result),
        ':executed_by_user_id' => $executedByUserId,
        ':executed_at_utc' => gmdate('Y-m-d H:i:s'),
    ));
}

$payload = tcc_repair_decode_payload();

$studentId = tcc_repair_int($payload['student_id'] ?? 0);
$cohortId = tcc_repair_int($payload['cohort_id'] ?? 0);
$lessonId = tcc_repair_int($payload['lesson_id'] ?? 0);
$repairCode = tcc_repair_str($payload['repair_code'] ?? '');

$issueType = tcc_repair_str($payload['issue_type'] ?? 'old_active_progress_test_attempt');
$blockerCategory = tcc_repair_str($payload['blocker_category'] ?? 'stale_bug');
$recurrenceKey = tcc_repair_str($payload['recurrence_key'] ?? '');

$detectedEvidence = array();

if (isset($payload['evidence']) && is_array($payload['evidence'])) {
    $detectedEvidence = $payload['evidence'];
} elseif (isset($payload['detected_evidence']) && is_array($payload['detected_evidence'])) {
    $detectedEvidence = $payload['detected_evidence'];
} elseif (isset($payload['detected_evidence_json'])) {
    $decoded = json_decode((string)$payload['detected_evidence_json'], true);
    $detectedEvidence = is_array($decoded) ? $decoded : array();
}

$testId = tcc_repair_int(
    $payload['test_id']
    ?? $payload['progress_test_id']
    ?? ($detectedEvidence['test_id'] ?? 0)
);

if ($studentId <= 0 || $cohortId <= 0 || $lessonId <= 0 || $repairCode === '') {
    tcc_repair_json(array(
        'ok' => false,
        'error' => 'missing_required_fields',
        'message' => 'student_id, cohort_id, lesson_id, and repair_code are required.'
    ), 400);
}

if (!tcc_repair_table_exists($pdo, 'tcc_repair_log')) {
    tcc_repair_json(array(
        'ok' => false,
        'error' => 'repair_log_missing',
        'message' => 'tcc_repair_log does not exist.'
    ), 500);
}

if ($blockerCategory !== 'stale_bug') {
    tcc_repair_json(array(
        'ok' => false,
        'error' => 'repair_not_allowed',
        'message' => 'Only stale_bug repairs are allowed.'
    ), 403);
}

if ($repairCode === 'recompute_projection') {
    tcc_repair_json(array(
        'ok' => false,
        'error' => 'repair_disabled_pending_engine_verification',
        'message' => 'recompute_projection is intentionally disabled until a safe engine repair method is verified.'
    ), 409);
}

if ($repairCode !== 'cleanup_old_active_attempt_after_pass') {
    tcc_repair_json(array(
        'ok' => false,
        'error' => 'repair_code_not_allowed',
        'message' => 'This first deployment only allows cleanup_old_active_attempt_after_pass.'
    ), 403);
}

if ($testId <= 0) {
    tcc_repair_json(array(
        'ok' => false,
        'error' => 'missing_scan_evidence_test_id',
        'message' => 'cleanup_old_active_attempt_after_pass requires the exact stale progress_tests_v2 test_id from scan evidence.'
    ), 400);
}

if ($recurrenceKey === '') {
    $recurrenceKey = $issueType . '|cohort:' . $cohortId . '|lesson:' . $lessonId;
}

try {
    $pdo->beginTransaction();

    $targetStmt = $pdo->prepare("
        SELECT *
        FROM progress_tests_v2
        WHERE id = ?
          AND user_id = ?
          AND cohort_id = ?
          AND lesson_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $targetStmt->execute(array($testId, $studentId, $cohortId, $lessonId));
    $target = $targetStmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        $pdo->rollBack();
        tcc_repair_json(array(
            'ok' => false,
            'error' => 'target_test_not_found',
            'message' => 'The scan evidence test_id does not match this student/cohort/lesson.'
        ), 404);
    }

    if (!in_array((string)$target['status'], array('ready', 'in_progress', 'processing', 'preparing'), true)) {
        $pdo->rollBack();
        tcc_repair_json(array(
            'ok' => false,
            'error' => 'target_not_active',
            'message' => 'The target progress test is no longer active and was not changed.'
        ), 409);
    }

    $passStmt = $pdo->prepare("
        SELECT id, attempt, score_pct, completed_at, formal_result_code, pass_gate_met
        FROM progress_tests_v2
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id = ?
          AND status = 'completed'
          AND pass_gate_met = 1
        ORDER BY completed_at DESC, id DESC
        LIMIT 1
        FOR UPDATE
    ");
    $passStmt->execute(array($studentId, $cohortId, $lessonId));
    $passRow = $passStmt->fetch(PDO::FETCH_ASSOC);

    if (!$passRow) {
        $pdo->rollBack();
        tcc_repair_json(array(
            'ok' => false,
            'error' => 'canonical_pass_missing',
            'message' => 'No canonical PASS exists for this student/cohort/lesson. Repair blocked.'
        ), 409);
    }

    // Stale rows created after a canonical PASS often have the highest id — refusing "latest row"
    // would block the main real-world case. Only block cleaning the canonical PASS row itself
    // (should never be active+completed PASS at once; defensive).
    if ((int)$target['id'] === (int)$passRow['id']) {
        $pdo->rollBack();
        tcc_repair_json(array(
            'ok' => false,
            'error' => 'target_is_canonical_pass_row',
            'message' => 'Target matches canonical PASS row; cannot stale-abort that attempt.'
        ), 409);
    }

    $targetTimeRaw = (string)($target['updated_at'] ?? '');
    if ($targetTimeRaw === '') {
        $targetTimeRaw = (string)($target['started_at'] ?? '');
    }
    if ($targetTimeRaw === '') {
        $targetTimeRaw = (string)($target['created_at'] ?? '');
    }

    $targetTimestamp = strtotime($targetTimeRaw);
    if ($targetTimestamp === false) {
        $pdo->rollBack();
        tcc_repair_json(array(
            'ok' => false,
            'error' => 'invalid_target_timestamp',
            'message' => 'Could not validate stale age for target attempt.'
        ), 409);
    }

    $ageSeconds = time() - $targetTimestamp;

    if ($ageSeconds < (24 * 3600)) {
        $pdo->rollBack();
        tcc_repair_json(array(
            'ok' => false,
            'error' => 'not_old_enough',
            'message' => 'Attempt is not old enough to be considered stale.'
        ), 409);
    }

    $passCompletedRaw = (string)($passRow['completed_at'] ?? '');
    $passCompletedTimestamp = strtotime($passCompletedRaw);
    $targetAnchorRaw = (string)($target['created_at'] ?? '');
    if ($targetAnchorRaw === '') {
        $targetAnchorRaw = (string)($target['started_at'] ?? '');
    }
    $targetCreatedTimestamp = strtotime($targetAnchorRaw);

    if ($passCompletedTimestamp === false || $targetCreatedTimestamp === false) {
        $pdo->rollBack();
        tcc_repair_json(array(
            'ok' => false,
            'error' => 'invalid_pass_or_target_timestamp',
            'message' => 'Could not validate PASS/target timestamp relationship.'
        ), 409);
    }

    // Allow only when the open attempt clearly started after the canonical PASS finished (orphan session).
    if ($targetCreatedTimestamp <= $passCompletedTimestamp) {
        $pdo->rollBack();
        tcc_repair_json(array(
            'ok' => false,
            'error' => 'target_not_after_canonical_pass',
            'message' => 'This active attempt does not start after the canonical PASS completed; not a safe stale cleanup case.'
        ), 409);
    }

    $beforeState = tcc_repair_fetch_state($pdo, $studentId, $cohortId, $lessonId, $testId);

    $updateStmt = $pdo->prepare("
        UPDATE progress_tests_v2
        SET
            status = 'failed',
            formal_result_code = 'STALE_ABORTED',
            counts_as_unsat = 0,
            updated_at = UTC_TIMESTAMP()
        WHERE id = ?
          AND user_id = ?
          AND cohort_id = ?
          AND lesson_id = ?
          AND status IN ('ready','in_progress','processing','preparing')
        LIMIT 1
    ");

    $updateStmt->execute(array($testId, $studentId, $cohortId, $lessonId));
    $affected = $updateStmt->rowCount();

    if ($affected !== 1) {
        $pdo->rollBack();
        tcc_repair_json(array(
            'ok' => false,
            'error' => 'repair_update_failed_or_noop',
            'message' => 'The repair did not update exactly one active target row.'
        ), 409);
    }

    $afterState = tcc_repair_fetch_state($pdo, $studentId, $cohortId, $lessonId, $testId);

    $result = array(
        'ok' => true,
        'repair_code' => $repairCode,
        'affected_rows' => $affected,
        'target_test_id' => $testId,
        'canonical_pass_test_id' => (int)$passRow['id'],
        'stale_age_hours' => round($ageSeconds / 3600, 1),
        'note' => 'Marked exact stale active progress test as failed / STALE_ABORTED / non-unsat. No projection update performed.'
    );

    tcc_repair_insert_log(
        $pdo,
        $repairCode,
        $issueType,
        $blockerCategory,
        $recurrenceKey,
        $studentId,
        $cohortId,
        $lessonId,
        $detectedEvidence,
        $beforeState,
        $afterState,
        $result,
        $currentUserId
    );

    $pdo->commit();

    tcc_repair_json(array(
        'ok' => true,
        'action' => 'theory_control_center_repair_execute',
        'repair_code' => $repairCode,
        'student_id' => $studentId,
        'cohort_id' => $cohortId,
        'lesson_id' => $lessonId,
        'target_test_id' => $testId,
        'canonical_pass_test_id' => (int)$passRow['id'],
        'affected_rows' => $affected,
        'stale_age_hours' => round($ageSeconds / 3600, 1),
        'message' => 'Stale active attempt cleaned and audit log written.'
    ));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('TCC_REPAIR_EXECUTE_ERROR repair_code=' . $repairCode . ' msg=' . $e->getMessage());

    tcc_repair_json(array(
        'ok' => false,
        'error' => 'server_error',
        'message' => $e->getMessage()
    ), 500);
}