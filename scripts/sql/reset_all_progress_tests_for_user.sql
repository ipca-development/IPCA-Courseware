-- Reset ALL progress tests for one student (full wipe for QA retesting)
-- Run in DigitalOcean SQL console on production.
--
-- Step 1: find your user id
-- SELECT id, name, email FROM users WHERE name LIKE '%Kay%' OR email LIKE '%kay%';

SET @student_id := 0;  -- <-- SET YOUR users.id

-- Preview
SELECT COUNT(*) AS attempt_count FROM progress_tests_v2 WHERE user_id = @student_id;
SELECT id, cohort_id, lesson_id, status, pass_gate_met, score_pct, idempotency_key, updated_at
FROM progress_tests_v2 WHERE user_id = @student_id ORDER BY id DESC;
SELECT id, cohort_id, lesson_id, status, progress_test_attempt_id
FROM progress_test_remote_authorizations WHERE student_id = @student_id ORDER BY id DESC;

-- ========== DELETE (run after setting @student_id) ==========

UPDATE lesson_activity la
INNER JOIN (
    SELECT DISTINCT cohort_id, lesson_id
    FROM progress_tests_v2
    WHERE user_id = @student_id
) x ON x.cohort_id = la.cohort_id AND x.lesson_id = la.lesson_id
SET la.test_pass_status = 'in_progress',
    la.completion_status = 'awaiting_test_completion',
    la.status = 'awaiting_test_completion',
    la.completed_at = NULL,
    la.next_lesson_unlocked_at = NULL,
    la.last_state_eval_at = UTC_TIMESTAMP(),
    la.updated_at = UTC_TIMESTAMP()
WHERE la.user_id = @student_id;

DELETE r FROM progress_test_oral_item_responses r
INNER JOIN progress_tests_v2 t ON t.id = r.attempt_id
WHERE t.user_id = @student_id;

DELETE s FROM progress_test_v4_card_sessions s
INNER JOIN progress_tests_v2 t ON t.id = s.attempt_id
WHERE t.user_id = @student_id;

DELETE c FROM progress_test_v4_answer_chunks c
INNER JOIN progress_tests_v2 t ON t.id = c.attempt_id
WHERE t.user_id = @student_id;

DELETE i FROM progress_test_oral_integrity_reviews i
INNER JOIN progress_tests_v2 t ON t.id = i.attempt_id
WHERE t.user_id = @student_id;

DELETE b FROM progress_test_user_badges b
INNER JOIN progress_tests_v2 t ON t.id = b.attempt_id
WHERE t.user_id = @student_id;

DELETE d FROM progress_test_v4_debug_events d
INNER JOIN progress_tests_v2 t ON t.id = d.attempt_id
WHERE t.user_id = @student_id;

DELETE v FROM progress_test_voice_events v
INNER JOIN progress_tests_v2 t ON t.id = v.attempt_id
WHERE t.user_id = @student_id;

DELETE it FROM progress_test_items_v2 it
INNER JOIN progress_tests_v2 t ON t.id = it.test_id
WHERE t.user_id = @student_id;

DELETE FROM progress_test_bank_question_usage WHERE user_id = @student_id;

DELETE FROM progress_test_remote_authorizations WHERE student_id = @student_id;

DELETE FROM progress_tests_v2 WHERE user_id = @student_id;

SELECT 'done' AS status,
       (SELECT COUNT(*) FROM progress_tests_v2 WHERE user_id = @student_id) AS remaining_attempts,
       (SELECT COUNT(*) FROM progress_test_remote_authorizations WHERE student_id = @student_id) AS remaining_auths;
