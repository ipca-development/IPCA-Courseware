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

        ptv4_ensure_v4_chunk_table($pdo);

        $itemSt = $pdo->prepare("SELECT id FROM progress_test_items_v2 WHERE id = ? AND test_id = ? LIMIT 1");
        $itemSt->execute([$itemId, $attemptId]);
        if (!$itemSt->fetchColumn()) {
            ptv4_json(['ok' => false, 'error' => 'Question item not found for this attempt'], 404);
        }

        $dir = ptv4_answer_chunk_dir($attemptId, $itemId);
        $path = $dir . '/chunk_' . $chunkIndex . '.webm';
        if (!move_uploaded_file((string)$_FILES['chunk']['tmp_name'], $path)) {
            ptv4_json(['ok' => false, 'error' => 'Failed to store audio chunk'], 500);
        }

        $bytes = (int)filesize($path);
        $recordedAt = gmdate('Y-m-d H:i:s');
        try {
            $st = $pdo->prepare("
                INSERT INTO progress_test_v4_answer_chunks
                  (attempt_id, item_id, user_id, chunk_index, storage_path, transcript_text, created_at)
                VALUES (?, ?, ?, ?, ?, NULL, ?)
                ON DUPLICATE KEY UPDATE storage_path = VALUES(storage_path), created_at = VALUES(created_at)
            ");
            $st->execute([$attemptId, $itemId, (int)$attempt['user_id'], $chunkIndex, $path, $recordedAt]);
        } catch (Throwable $e) {
            ptv4_json(['ok' => false, 'error' => 'Failed to record audio chunk metadata: ' . $e->getMessage()], 500);
        }

        ptv4_save_card_state($pdo, $attemptId, $itemId, (int)$attempt['user_id'], 'listening', null);
        ptv4_touch_answer_chunk_count($pdo, $attemptId, $itemId);

        $pdo->prepare("
            UPDATE progress_tests_v2 SET status='in_progress', status_text='Oral progress test in progress.', updated_at=NOW()
            WHERE id=? AND status NOT IN ('completed','failed')
        ")->execute([$attemptId]);

        ptv4_json([
            'ok' => true,
            'chunk_index' => $chunkIndex,
            'chunk_count' => ptv4_answer_chunk_count($pdo, $attemptId, $itemId),
            'bytes' => $bytes,
            'storage_path' => $path,
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

    if ($action === 'finalize_transcript') {
        $itemId = (int)($data['item_id'] ?? 0);
        $recordingMs = max(0, (int)($data['recording_ms'] ?? 0));
        if ($itemId <= 0) ptv4_json(['ok' => false, 'error' => 'item_id required'], 400);
        $cookieHeader = (string)($_SERVER['HTTP_COOKIE'] ?? '');
        $finalized = ptv4_finalize_transcript($pdo, $attemptId, $itemId, $recordingMs, $cookieHeader);
        if ($finalized['transcript_final'] !== '') {
            ptv4_save_card_state($pdo, $attemptId, $itemId, (int)$attempt['user_id'], 'listening', $finalized['transcript_final']);
        }
        ptv4_json(['ok' => true] + $finalized);
    }

    if ($action === 'start_oral_test') {
        if (!(bool)ptv4_state_payload($pdo, $attempt)['prepared']) {
            ptv4_json(['ok' => false, 'error' => 'Progress test is not prepared yet.'], 409);
        }
        $pdo->prepare("
            UPDATE progress_tests_v2 SET status='in_progress', status_text='Oral progress test in progress.', updated_at=NOW()
            WHERE id=? AND status NOT IN ('completed','failed')
        ")->execute([$attemptId]);
        ptv4_json(['ok' => true, 'state' => ptv4_state_payload($pdo, ptv4_load_attempt($pdo, $u, $attemptId))]);
    }

    if ($action === 'synthesize_feedback_speech') {
        $speechText = trim((string)($data['speech_text'] ?? ''));
        if ($speechText === '') {
            ptv4_json(['ok' => false, 'error' => 'speech_text required'], 400);
        }
        if (strlen($speechText) > 4000) {
            $speechText = substr($speechText, 0, 4000);
        }
        $mp3 = ptv4_synthesize_speech_mp3($speechText);
        if ($mp3 === '') {
            ptv4_json(['ok' => false, 'error' => 'Could not synthesize feedback audio.'], 502);
        }
        ptv4_json([
            'ok' => true,
            'audio_data_url' => 'data:audio/mpeg;base64,' . base64_encode($mp3),
        ]);
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

        ptv4_save_card_state($pdo, $attemptId, $itemId, (int)$attempt['user_id'], 'evaluating', null);

        $recordingMs = max(0, (int)($data['recording_ms'] ?? 0));
        $cookieHeader = (string)($_SERVER['HTTP_COOKIE'] ?? '');
        $answer = trim((string)($data['student_answer_text'] ?? ''));
        if ($answer === '') {
            $finalized = ptv4_finalize_transcript($pdo, $attemptId, $itemId, $recordingMs, $cookieHeader);
            $answer = (string)$finalized['transcript_final'];
        } else {
            $finalized = [
                'transcript_final' => $answer,
                'has_audio' => true,
                'audio_received' => true,
                'transcription_failed' => false,
                'upload_failed' => false,
            ];
        }
        $hasAudio = !empty($finalized['has_audio']);
        $audioReceived = !empty($finalized['audio_received']);
        $transcriptionFailed = !empty($finalized['transcription_failed']);
        $uploadFailed = !empty($finalized['upload_failed']);

        $cards = ptv4_load_card_sessions($pdo, $attemptId);
        $clarificationUsed = !empty($cards[$itemId]['clarification_used']);
        $originalAnswer = trim((string)($data['original_answer_text'] ?? ''));
        $timedOutStart = !empty($data['timed_out_start']);

        if ($timedOutStart && $answer === '') {
            $answer = '[timeout: no answer started within 30 seconds]';
        }

        if (!$audioReceived && !$timedOutStart && $clarificationAnswer === '') {
            ptv4_json(['ok' => false, 'error' => 'No audio received', 'code' => 'no_audio'], 409);
        }

        if ($uploadFailed && $answer === '' && !$timedOutStart) {
            ptv4_json(['ok' => false, 'error' => 'Audio upload failed', 'code' => 'upload_failed'], 502);
        }

        if ($clarificationAnswer !== '' && ($originalAnswer !== '' || $clarificationUsed)) {
            $baseAnswer = $originalAnswer !== '' ? $originalAnswer : $answer;
            $combined = trim($baseAnswer . ($baseAnswer !== '' && $clarificationAnswer !== '' ? ' ' : '') . $clarificationAnswer);
            $eval = ptv4_grade_item($pdo, $item, $baseAnswer, $clarificationAnswer);
            ptv4_json(ptv4_evaluation_response($pdo, $u, $attempt, $item, $eval, $combined, true));
        }

        if ($answer === '' && !$timedOutStart) {
            if ($hasAudio || $transcriptionFailed) {
                $eval = ptv4_validate_evaluator_json([
                    'score_pct' => 0,
                    'result' => 'clarify',
                    'missing_concepts' => ['Transcript unavailable'],
                    'clarification_question' => ptv4_default_clarification_text(),
                    'feedback_for_student' => 'I may not have heard that correctly. Please answer again in English.',
                    'safety_critical_issue' => false,
                ]);
                ptv4_json(ptv4_evaluation_response($pdo, $u, $attempt, $item, $eval, $answer, $clarificationUsed));
            }
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

        if (!$passed) {
            require_once __DIR__ . '/../../../src/progress_test_prep.php';
            $cookieHeader = (string)($_SERVER['HTTP_COOKIE'] ?? '');
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            pt_prep_schedule_progress_test(
                $pdo,
                (int)$attempt['user_id'],
                (int)$attempt['cohort_id'],
                (int)$attempt['lesson_id'],
                'after_failed_attempt',
                $cookieHeader
            );
        }

        ptv4_generate_integrity_review($pdo, $attempt);
        $newlyEarnedBadges = ptv4_evaluate_and_award_badges($pdo, $attempt);
        $report = ptv4_report_payload($pdo, $attempt, $u, $newlyEarnedBadges);

        ptv4_json([
            'ok' => true,
            'score_pct' => $scorePct,
            'pass_gate_met' => $passed ? 1 : 0,
            'formal_result_label' => (string)($attempt['formal_result_label'] ?? ''),
            'strongest_areas' => $strong,
            'weak_areas' => $weak,
            'summary' => $summary,
            'report' => $report,
            'state' => ptv4_state_payload($pdo, $attempt),
            'automation_result' => $finalize['automation_result'] ?? null,
        ]);
    }

    if ($action === 'get_report') {
        ptv4_json(['ok' => true, 'report' => ptv4_report_payload($pdo, $attempt, $u, [])]);
    }

    if ($action === 'log_debug_events') {
        $events = $data['events'] ?? [];
        if (!is_array($events)) $events = [];
        foreach ($events as $ev) {
            if (!is_array($ev)) continue;
            ptv4_log_debug_event(
                $pdo,
                (int)$attempt['user_id'],
                (string)($ev['type'] ?? 'client_event'),
                (string)($ev['detail'] ?? ''),
                is_array($ev['meta'] ?? null) ? $ev['meta'] : null,
                $attemptId,
                (int)($ev['item_id'] ?? 0) ?: null,
                (int)$attempt['cohort_id'],
                (int)$attempt['lesson_id']
            );
        }
        ptv4_json(['ok' => true, 'logged' => count($events)]);
    }

    if ($action === 'submit_feedback') {
        $payload = ptv4_save_user_feedback($pdo, $u, array_merge($data, [
            'cohort_id' => (int)$attempt['cohort_id'],
            'lesson_id' => (int)$attempt['lesson_id'],
            'attempt_id' => $attemptId,
            'type' => 'Progress Test AI Modal Maya',
        ]));
        $earnedContributor = ptv4_award_feedback_badge($pdo, (int)$attempt['user_id'], [
            'attempt_id' => $attemptId,
            'lesson_id' => (int)$attempt['lesson_id'],
            'cohort_id' => (int)$attempt['cohort_id'],
        ]);
        $payload['contributor_badge_earned'] = $earnedContributor ? 1 : 0;
        ptv4_json($payload);
    }

    if ($action === 'submit_bug_report') {
        $events = $data['events'] ?? [];
        if (!is_array($events)) $events = [];
        foreach ($events as $ev) {
            if (!is_array($ev)) continue;
            ptv4_log_debug_event(
                $pdo,
                (int)$attempt['user_id'],
                (string)($ev['type'] ?? 'bug_report_event'),
                (string)($ev['detail'] ?? ''),
                is_array($ev['meta'] ?? null) ? $ev['meta'] : null,
                $attemptId,
                (int)($ev['item_id'] ?? 0) ?: null,
                (int)$attempt['cohort_id'],
                (int)$attempt['lesson_id']
            );
        }
        $body = "Progress Test V4 bug report\n"
            . "Attempt: {$attemptId}\nUser: " . (int)$attempt['user_id'] . "\n"
            . "Cohort: " . (int)$attempt['cohort_id'] . " Lesson: " . (int)$attempt['lesson_id'] . "\n\n"
            . json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        require_once __DIR__ . '/../../../src/mailer.php';
        cw_send_mail([
            'to' => 'info@ipca.aero',
            'subject' => 'Progress Test V4 Bug Report (Attempt ' . $attemptId . ')',
            'body_text' => $body,
        ]);
        ptv4_json(['ok' => true, 'message' => 'Bug report sent. Thank you.']);
    }

    ptv4_json(['ok' => false, 'error' => 'Unknown action'], 400);
} catch (Throwable $e) {
    ptv4_json(['ok' => false, 'error' => $e->getMessage()], 400);
}
