<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/lesson_summary_service.php';

cw_require_login();

header('Content-Type: application/json; charset=utf-8');

function summary_debug_log($label, $data = null)
{
    $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $label;

    if ($data !== null) {
        if (is_string($data)) {
            $line .= ' ' . $data;
        } else {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $line .= ' ' . ($json !== false ? $json : '[json_encode_failed]');
        }
    }

    $line .= "\n";
    @file_put_contents('/var/www/ipca/storage_summary_debug.log', $line, FILE_APPEND);
}

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');

    if ($role !== 'student' && $role !== 'admin') {
        http_response_code(403);
        $result = ['ok' => false, 'error' => 'Forbidden'];
        summary_debug_log('forbidden_role', [
            'role' => $role,
            'user_id' => (int)($u['id'] ?? 0)
        ]);
        echo json_encode($result);
        exit;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        throw new RuntimeException('Empty request body');
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON');
    }

    summary_debug_log('payload', $data);

    $action = trim((string)($data['action'] ?? 'save'));
    $cohortId = (int)($data['cohort_id'] ?? 0);
    $lessonId = (int)($data['lesson_id'] ?? 0);
    $summaryHtml = (string)($data['summary_html'] ?? '');

    if ($cohortId <= 0 || $lessonId <= 0) {
        throw new RuntimeException('Missing cohort_id or lesson_id');
    }

    $userId = (int)($u['id'] ?? 0);

    if ($role === 'student') {
        $chk = $pdo->prepare("
            SELECT 1
            FROM cohort_students
            WHERE cohort_id = ?
              AND user_id = ?
            LIMIT 1
        ");
        $chk->execute([$cohortId, $userId]);

        if (!$chk->fetchColumn()) {
            http_response_code(403);
            $result = ['ok' => false, 'error' => 'Not enrolled in this cohort'];
            summary_debug_log('not_enrolled', [
                'user_id' => $userId,
                'cohort_id' => $cohortId,
                'lesson_id' => $lessonId,
                'action' => $action
            ]);
            echo json_encode($result);
            exit;
        }
    }

    $service = new LessonSummaryService($pdo);

    if ($action === 'unlock') {
        $result = $service->unlockSummary(
            $userId,
            $cohortId,
            $lessonId,
            'student'
        );
    } elseif ($action === 'check') {
        $result = $service->checkSummary(
            $userId,
            $cohortId,
            $lessonId,
            'student'
        );
    } else {
        $result = $service->saveSummary(
            $userId,
            $cohortId,
            $lessonId,
            $summaryHtml,
            'student'
        );
    }

    summary_debug_log('result', $result);
    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code(400);

    summary_debug_log('error', [
        'message' => $e->getMessage(),
        'trace_file' => $e->getFile(),
        'trace_line' => $e->getLine()
    ]);

    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}