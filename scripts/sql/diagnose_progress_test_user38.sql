-- Diagnose latest progress test state for user #38
-- Run in DigitalOcean SQL console.

SET @student_id := 38;

SELECT id, cohort_id, lesson_id, attempt, status, status_text, progress_pct, score_pct,
       started_at, updated_at, formal_result_code, formal_result_label
FROM progress_tests_v2
WHERE user_id = @student_id
ORDER BY id DESC
LIMIT 5;

SET @attempt_id := (
    SELECT id FROM progress_tests_v2
    WHERE user_id = @student_id
    ORDER BY id DESC
    LIMIT 1
);

SELECT @attempt_id AS latest_attempt_id;

SELECT id, idx, kind, LEFT(prompt, 80) AS prompt, audio_path IS NOT NULL AS has_audio,
       transcript_text IS NOT NULL AND transcript_text <> '' AS has_transcript,
       is_correct, score_points, updated_at
FROM progress_test_items_v2
WHERE test_id = @attempt_id
ORDER BY idx;

SELECT item_id, score_pct, is_correct, evaluated_at IS NOT NULL AS finalized,
       LEFT(student_answer_text, 80) AS answer_preview,
       clarification_question_text IS NOT NULL AND clarification_question_text <> '' AS has_clarify
FROM progress_test_oral_item_responses
WHERE attempt_id = @attempt_id
ORDER BY item_id;

SELECT item_id, card_state, clarification_used,
       LEFT(live_transcript, 80) AS live_transcript_preview, updated_at
FROM progress_test_v4_card_sessions
WHERE attempt_id = @attempt_id
ORDER BY item_id;

SELECT item_id, COUNT(*) AS chunk_count
FROM progress_test_v4_answer_chunks
WHERE attempt_id = @attempt_id
GROUP BY item_id
ORDER BY item_id;

SELECT created_at, event_type, item_id, LEFT(event_detail, 100) AS detail, meta_json
FROM progress_test_v4_debug_events
WHERE attempt_id = @attempt_id
ORDER BY id DESC
LIMIT 30;
