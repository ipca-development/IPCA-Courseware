<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/theory_ai_training_report_job.php';

cw_require_login();

try {
    $u = cw_current_user($pdo);
    $role = trim((string)($u['role'] ?? ''));
    $allowed = array('admin', 'supervisor', 'instructor', 'chief_instructor');
    if (!in_array($role, $allowed, true)) {
        http_response_code(403);
        exit('Forbidden');
    }

    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    $studentId = (int)($_GET['student_id'] ?? 0);
    if ($cohortId <= 0 || $studentId <= 0) {
        throw new RuntimeException('Missing cohort_id or student_id');
    }

    InstructorTheoryTrainingReportAi::verifyCohortStudent($pdo, $cohortId, $studentId);

    $stored = (string)($_GET['stored'] ?? '') === '1';
    $sync = (string)($_GET['sync'] ?? '') === '1';

    if ($stored) {
        tatr_ensure_table($pdo);
        $st = $pdo->prepare("
            SELECT result_json, status
            FROM theory_ai_training_report_jobs
            WHERE cohort_id = ? AND student_id = ? AND status = 'complete'
            LIMIT 1
        ");
        $st->execute([$cohortId, $studentId]);
        $job = $st->fetch(PDO::FETCH_ASSOC);
        if (!$job || trim((string)($job['result_json'] ?? '')) === '') {
            http_response_code(409);
            echo 'No prepared report for this student. Generate it from Theory Control Center first.';
            exit;
        }
        $decoded = json_decode((string)$job['result_json'], true);
        if (!is_array($decoded) || !isset($decoded['exportData']) || !is_array($decoded['exportData'])) {
            throw new RuntimeException('Stored report payload is invalid');
        }
        $exportData = $decoded['exportData'];
        tatr_stream_theory_ai_report_pdf($exportData);
        exit;
    }

    if ($sync) {
        if ($role !== 'admin') {
            http_response_code(403);
            exit('Synchronous export is restricted to administrators.');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        $exportData = tatr_build_export_payload($pdo, $cohortId, $studentId, null);
        tatr_stream_theory_ai_report_pdf($exportData);
        exit;
    }

    http_response_code(400);
    echo 'Use Theory Control Center to prepare this report, then open the PDF from the dialog. '
        . 'Administrators may add sync=1 for a one-shot synchronous export.';
    exit;
} catch (Throwable $e) {
    http_response_code(400);
    echo 'PDF export failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    exit;
}
