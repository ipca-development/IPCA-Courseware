<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/progress_test_v4_oral.php';

cw_require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') {
        ptv4_json(['ok' => false, 'error' => 'Forbidden'], 403);
    }

    ptv4_ensure_tables($pdo);

    $action = (string)($_REQUEST['action'] ?? '');
    if ($action === '' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = 'get_state';
    }
    if ($action === '') {
        $data = ptv4_body();
        $action = (string)($data['action'] ?? 'get_state');
    } else {
        $data = $_SERVER['REQUEST_METHOD'] === 'GET' ? $_GET : ptv4_body();
        if (!isset($data['action'])) $data['action'] = $action;
    }

    if ($action === 'ensure_prepared') {
        $cohortId = (int)($data['cohort_id'] ?? 0);
        $lessonId = (int)($data['lesson_id'] ?? 0);
        if ($cohortId <= 0 || $lessonId <= 0) ptv4_json(['ok' => false, 'error' => 'Missing cohort_id or lesson_id'], 400);

        $studentUserId = $role === 'admin' ? (int)($data['user_id'] ?? $u['id']) : (int)$u['id'];
        if ($role === 'student') {
            $en = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id = ? AND user_id = ? AND status = 'active' LIMIT 1");
            $en->execute([$cohortId, $studentUserId]);
            if (!$en->fetchColumn()) ptv4_json(['ok' => false, 'error' => 'Not actively enrolled'], 403);
        }
        ptv4_require_progress_test_access($pdo, $u, $cohortId, $studentUserId);

        $cookieHeader = (string)($_SERVER['HTTP_COOKIE'] ?? '');
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $out = ptv4_ensure_prepared_attempt($pdo, array_merge($u, ['id' => $studentUserId]), $cohortId, $lessonId, $cookieHeader);
        if (!empty($out['blocked'])) {
            ptv4_json(['ok' => false, 'blocked' => true, 'reason' => (string)($out['reason'] ?? 'blocked')], 409);
        }
        ptv4_json($out);
    }

    if ($action === 'upload_answer_chunk') {
        $attemptId = (int)($_POST['attempt_id'] ?? 0);
        $itemId = (int)($_POST['item_id'] ?? 0);
        $chunkIndex = (int)($_POST['chunk_index'] ?? 0);
        if ($attemptId <= 0 || $itemId <= 0 || $chunkIndex < 0) {
            ptv4_json(['ok' => false, 'error' => 'attempt_id, item_id, chunk_index required'], 400);
        }
        if (empty($_FILES['chunk']) || !is_uploaded_file((string)$_FILES['chunk']['tmp_name'])) {
            ptv4_json(['ok' => false, 'error' => 'chunk file required'], 400);
        }

        $attempt = ptv4_load_attempt($pdo, $u, $attemptId);
        ptv4_require_progress_test_access($pdo, $u, (int)$attempt['cohort_id'], (int)$attempt['user_id']);

        $dir = ptv4_answer_chunk_dir($attemptId, $itemId);
        $path = $dir . '/chunk_' . $chunkIndex . '.webm';
        if (!move_uploaded_file((string)$_FILES['chunk']['tmp_name'], $path)) {
            ptv4_json(['ok' => false, 'error' => 'Failed to store audio chunk'], 500);
        }

        $transcript = ptv4_transcribe_audio_file($path);
        $st = $pdo->prepare("
            INSERT INTO progress_test_v4_answer_chunks
              (attempt_id, item_id, user_id, chunk_index, storage_path, transcript_text, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE storage_path = VALUES(storage_path), transcript_text = VALUES(transcript_text)
        ");
        $st->execute([$attemptId, $itemId, (int)$attempt['user_id'], $chunkIndex, $path, $transcript !== '' ? $transcript : null]);

        $partial = ptv4_merge_chunk_transcripts($pdo, $attemptId, $itemId);
        ptv4_save_card_state($pdo, $attemptId, $itemId, (int)$attempt['user_id'], 'listening', $partial);
        if ($transcript !== '') {
            ptv4_log_event($pdo, $attemptId, $itemId, (int)$attempt['user_id'], 'student', 'answer_chunk', $transcript);
        }

        $pdo->prepare("
            UPDATE progress_tests_v2 SET status='in_progress', status_text='Oral progress test in progress.', updated_at=NOW()
            WHERE id=? AND status NOT IN ('completed','failed')
        ")->execute([$attemptId]);

        ptv4_json([
            'ok' => true,
            'chunk_index' => $chunkIndex,
            'transcript_partial' => $partial,
            'chunk_transcript' => $transcript,
        ]);
    }

    $attemptId = (int)($data['attempt_id'] ?? 0);
    if ($attemptId <= 0 && $action !== 'ensure_prepared') {
        ptv4_json(['ok' => false, 'error' => 'attempt_id required'], 400);
    }

    if ($attemptId > 0) {
        $attempt = ptv4_load_attempt($pdo, $u, $attemptId);
        ptv4_require_progress_test_access($pdo, $u, (int)$attempt['cohort_id'], (int)$attempt['user_id']);
    }

    if ($action === 'get_state') {
        $state = ptv4_state_payload($pdo, $attempt);
        if (!$state['prepared']) {
            ptv4_json(['ok' => true, 'preparing' => true, 'state' => $state]);
        }
        ptv4_json(['ok' => true, 'preparing' => false, 'state' => $state]);
    }

    if ($action === 'save_card_state') {
        $itemId = (int)($data['item_id'] ?? 0);
        $cardState = (string)($data['card_state'] ?? 'ready');
        $liveTranscript = trim((string)($data['live_transcript'] ?? ''));
        if ($itemId <= 0) ptv4_json(['ok' => false, 'error' => 'item_id required'], 400);
        ptv4_save_card_state($pdo, $attemptId, $itemId, (int)$attempt['user_id'], $cardState, $liveTranscript !== '' ? $liveTranscript : null);
        $pdo->prepare("UPDATE progress_tests_v2 SET updated_at=NOW() WHERE id=?")->execute([$attemptId]);
        ptv4_json(['ok' => true, 'state' => ptv4_state_payload($pdo, $attempt)]);
    }

    if ($action === 'save_transcript_segment') {
        $itemId = (int)($data['item_id'] ?? 0);
        $text = trim((string)($data['transcript_text'] ?? ''));
        if ($itemId <= 0 || $text === '') ptv4_json(['ok' => false, 'error' => 'item_id and transcript_text required'], 400);
        ptv4_log_event($pdo, $attemptId, $itemId, (int)$attempt['user_id'], 'student', (string)($data['event_type'] ?? 'answer'), $text);
        ptv4_save_card_state($pdo, $attemptId, $itemId, (int)$attempt['user_id'], 'listening', $text);
        ptv4_json(['ok' => true, 'transcript_partial' => $text]);
    }

    if ($action === 'start_oral_test') {
        if (!(bool)ptv4_state_payload($pdo, $attempt)['prepared']) {
            ptv4_json(['ok' => false, 'error' => 'Progress test is not prepared yet.'], 409);
        }
        $pdo->prepare("
            UPDATE progress_tests_v2 SET status='in_progress', status_text='Oral progress test in progress.', updated_at=NOW()
            WHERE id=? AND status IN ('ready','preparing')
        ")->execute([$attemptId]);
        ptv4_json(['ok' => true, 'state' => ptv4_state_payload($pdo, ptv4_load_attempt($pdo, $u, $attemptId))]);
    }

    if ($action === 'finalize_item_answer') {
        $itemId = (int)($data['item_id'] ?? 0);
        $liveTranscript = trim((string)($data['student_answer_text'] ?? ''));
        $clarificationAnswer = trim((string)($data['clarification_answer_text'] ?? ''));
        $clarificationQuestion = trim((string)($data['clarification_question_text'] ?? ''));
        if ($itemId <= 0) ptv4_json(['ok' => false, 'error' => 'item_id required'], 400);

        $itemSt = $pdo->prepare("SELECT * FROM progress_test_items_v2 WHERE id = ? AND test_id = ? LIMIT 1");
        $itemSt->execute([$itemId, $attemptId]);
        $item = $itemSt->fetch(PDO::FETCH_ASSOC);
        if (!$item) ptv4_json(['ok' => false, 'error' => 'Question item not found'], 404);

        ptv4_save_card_state($pdo, $attemptId, $itemId, (int)$attempt['user_id'], 'evaluating', $liveTranscript);

        $chunkTranscript = ptv4_merge_chunk_transcripts($pdo, $attemptId, $itemId);
        $answer = $liveTranscript !== '' ? $liveTranscript : $chunkTranscript;
        if ($answer === '') {
            $cards = ptv4_load_card_sessions($pdo, $attemptId);
            $answer = trim((string)($cards[$itemId]['live_transcript'] ?? ''));
        }

        $cards = ptv4_load_card_sessions($pdo, $attemptId);
        $clarificationUsed = !empty($cards[$itemId]['clarification_used']);
        $originalAnswer = trim((string)($data['original_answer_text'] ?? ''));
        $timedOutStart = !empty($data['timed_out_start']);

        if ($timedOutStart && $answer === '') {
            $answer = '[timeout: no answer started within 30 seconds]';
        }

        if ($clarificationAnswer !== '' && $originalAnswer !== '') {
            $eval = ptv4_grade_item($pdo, $item, $originalAnswer, $clarificationAnswer);
            ptv4_json(ptv4_evaluation_response($pdo, $u, $attempt, $item, $eval, $originalAnswer, true));
        }

        if ($answer === '' && !$timedOutStart) {
            ptv4_json(['ok' => false, 'error' => 'No answer captured'], 400);
        }

        $eval = ptv4_grade_item($pdo, $item, $answer);
        ptv4_json(ptv4_evaluation_response($pdo, $u, $attempt, $item, $eval, $answer, $clarificationUsed));
    }

    if ($action === 'advance_item') {
        ptv4_json(['ok' => true, 'state' => ptv4_state_payload($pdo, $attempt)]);
    }

    if ($action === 'abort_voice_session_without_penalty') {
        $pdo->prepare("
            UPDATE progress_tests_v2 SET status='in_progress',
                status_text='Voice disconnected. Resume within 15 minutes.', updated_at=NOW()
            WHERE id=? AND status NOT IN ('completed','failed')
        ")->execute([$attemptId]);
        ptv4_log_event($pdo, $attemptId, null, (int)$attempt['user_id'], 'system', 'voice_disconnected', 'Voice session ended without penalty.');
        ptv4_json(['ok' => true, 'state' => ptv4_state_payload($pdo, ptv4_load_attempt($pdo, $u, $attemptId))]);
    }

    if ($action === 'end_oral_test_without_penalty') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM progress_test_oral_item_responses WHERE attempt_id = ?")->execute([$attemptId]);
            $pdo->prepare("DELETE FROM progress_test_v4_card_sessions WHERE attempt_id = ?")->execute([$attemptId]);
            $pdo->prepare("DELETE FROM progress_test_v4_answer_chunks WHERE attempt_id = ?")->execute([$attemptId]);
            $pdo->prepare("
                UPDATE progress_test_items_v2 SET transcript_text=NULL, is_correct=NULL, score_points=NULL, max_points=NULL, updated_at=NOW()
                WHERE test_id = ?
            ")->execute([$attemptId]);
            $pdo->prepare("
                UPDATE progress_tests_v2 SET status='failed', score_pct=NULL, progress_pct=0,
                    status_text='Oral test ended without penalty.', formal_result_code='STALE_ABORTED',
                    formal_result_label='Aborted (technical/no penalty)', counts_as_unsat=0, pass_gate_met=0,
                    timing_status='unknown', completed_at=NULL, updated_at=NOW()
                WHERE id=? AND status NOT IN ('completed','failed')
            ")->execute([$attemptId]);
            ptv4_log_event($pdo, $attemptId, null, (int)$attempt['user_id'], 'system', 'oral_test_ended_without_penalty', 'Student ended oral test; partial responses reset.');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        ptv4_json(['ok' => true, 'state' => ptv4_state_payload($pdo, ptv4_load_attempt($pdo, $u, $attemptId))]);
    }

    if ($action === 'complete_test') {
        $state = ptv4_state_payload($pdo, $attempt);
        if ((int)$state['total_questions'] <= 0 || (int)$state['evaluated_count'] < (int)$state['total_questions']) {
            ptv4_json(['ok' => false, 'error' => 'All items must be evaluated before completion.'], 409);
        }

        $responses = ptv4_load_responses($pdo, $attemptId);
        $scoreTotal = 0.0;
        $weak = [];
        $strong = [];
        foreach ($responses as $response) {
            $score = (float)($response['score_pct'] ?? 0);
            $scoreTotal += $score;
            $missing = json_decode((string)($response['missing_concepts_json'] ?? '[]'), true);
            $detected = json_decode((string)($response['detected_concepts_json'] ?? '[]'), true);
            if ($score < 70 && is_array($missing)) $weak = array_merge($weak, array_map('strval', $missing));
            if ($score >= 70 && is_array($detected)) $strong = array_merge($strong, array_map('strval', $detected));
        }
        $scorePct = (int)round($scoreTotal / max(1, count($responses)));
        $weak = array_values(array_unique(array_filter($weak)));
        $strong = array_values(array_unique(array_filter($strong)));
        $weakText = $weak ? implode(', ', array_slice($weak, 0, 5)) : 'No major weak areas identified.';
        $strongText = $strong ? implode(', ', array_slice($strong, 0, 4)) : 'You handled several concepts adequately.';

        $engine = new CoursewareProgressionV2($pdo);
        $finalize = $engine->finalizeAssessedProgressTest($attemptId, [
            'score_pct' => $scorePct,
            'ai_summary' => 'Answered via oral progress test V4. Score: ' . $scorePct . '%. Weak areas: ' . $weakText,
            'weak_areas' => $weakText,
            'debrief_spoken' => 'Your oral progress test score is ' . $scorePct . ' percent.',
            'summary_quality' => 'Not reassessed in oral V4 mode.',
            'summary_issues' => '',
            'summary_corrections' => '',
            'confirmed_misunderstandings' => $weakText,
            'completed_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $attempt = ptv4_load_attempt($pdo, $u, $attemptId);
        $passed = !empty($attempt['pass_gate_met']);
        $summary = $passed
            ? 'You passed with ' . $scorePct . '%. Strongest areas: ' . $strongText . '. Review ' . $weakText . ' before moving on.'
            : 'You did not pass this attempt. Main weak areas: ' . $weakText . '. Study those before re-attempting.';

        ptv4_json([
            'ok' => true,
            'score_pct' => $scorePct,
            'pass_gate_met' => $passed ? 1 : 0,
            'formal_result_label' => (string)($attempt['formal_result_label'] ?? ''),
            'strongest_areas' => $strong,
            'weak_areas' => $weak,
            'summary' => $summary,
            'state' => ptv4_state_payload($pdo, $attempt),
            'automation_result' => $finalize['automation_result'] ?? null,
        ]);
    }

    ptv4_json(['ok' => false, 'error' => 'Unknown action'], 400);
} catch (Throwable $e) {
    ptv4_json(['ok' => false, 'error' => $e->getMessage()], 400);
}
