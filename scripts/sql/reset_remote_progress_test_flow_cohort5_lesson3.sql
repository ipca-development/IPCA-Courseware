-- Reset remote progress test flow for re-testing
-- Course: https://ipca.training/student/course.php?cohort_id=5
-- Lesson: https://ipca.training/student/progress_test_v4.php?cohort_id=5&lesson_id=3
--
-- Step 1: Run DISCOVERY below and set @student_id from your user row.
-- Step 2: Run RESET block.

-- ========== DISCOVERY ==========
SELECT id, name, email FROM users WHERE email LIKE '%@%' ORDER BY id DESC LIMIT 20;
-- Or narrow down:
-- SELECT id, name, email FROM users WHERE name LIKE '%Kay%' OR email LIKE '%kay%';

SET @cohort_id := 5;
SET @lesson_id := 3;
SET @student_id := 0;  -- <-- SET THIS after discovery (your users.id)

SELECT 'remote_permission' AS tbl, remote_testing_enabled, updated_at
FROM student_remote_test_permissions
WHERE student_id = @student_id AND cohort_id = @cohort_id;

SELECT 'remote_authorizations' AS tbl, id, status, progress_test_attempt_id, expires_at, created_at
FROM progress_test_remote_authorizations
WHERE student_id = @student_id AND cohort_id = @cohort_id AND lesson_id = @lesson_id
ORDER BY id DESC;

SELECT 'open_attempts' AS tbl, id, status, pass_gate_met, idempotency_key, formal_result_code, updated_at
FROM progress_tests_v2
WHERE user_id = @student_id AND cohort_id = @cohort_id AND lesson_id = @lesson_id
  AND status IN ('preparing','ready','in_progress','processing')
ORDER BY id DESC;

SELECT 'all_recent_attempts' AS tbl, id, status, pass_gate_met, idempotency_key, updated_at
FROM progress_tests_v2
WHERE user_id = @student_id AND cohort_id = @cohort_id AND lesson_id = @lesson_id
ORDER BY id DESC
LIMIT 10;

-- ========== RESET (only after @student_id is set) ==========
-- Uncomment and run when @student_id > 0:

/*
DELETE r FROM progress_test_oral_item_responses r
INNER JOIN progress_tests_v2 t ON t.id = r.attempt_id
WHERE t.user_id = @student_id AND t.cohort_id = @cohort_id AND t.lesson_id = @lesson_id
  AND NOT (t.status = 'completed' AND t.pass_gate_met = 1);

DELETE s FROM progress_test_v4_card_sessions s
INNER JOIN progress_tests_v2 t ON t.id = s.attempt_id
WHERE t.user_id = @student_id AND t.cohort_id = @cohort_id AND t.lesson_id = @lesson_id
  AND NOT (t.status = 'completed' AND t.pass_gate_met = 1);

DELETE c FROM progress_test_v4_answer_chunks c
INNER JOIN progress_tests_v2 t ON t.id = c.attempt_id
WHERE t.user_id = @student_id AND t.cohort_id = @cohort_id AND t.lesson_id = @lesson_id
  AND NOT (t.status = 'completed' AND t.pass_gate_met = 1);

DELETE i FROM progress_test_oral_integrity_reviews i
INNER JOIN progress_tests_v2 t ON t.id = i.attempt_id
WHERE t.user_id = @student_id AND t.cohort_id = @cohort_id AND t.lesson_id = @lesson_id
  AND NOT (t.status = 'completed' AND t.pass_gate_met = 1);

DELETE b FROM progress_test_user_badges b
INNER JOIN progress_tests_v2 t ON t.id = b.attempt_id
WHERE t.user_id = @student_id AND t.cohort_id = @cohort_id AND t.lesson_id = @lesson_id
  AND NOT (t.status = 'completed' AND t.pass_gate_met = 1);

DELETE d FROM progress_test_v4_debug_events d
INNER JOIN progress_tests_v2 t ON t.id = d.attempt_id
WHERE t.user_id = @student_id AND t.cohort_id = @cohort_id AND t.lesson_id = @lesson_id
  AND NOT (t.status = 'completed' AND t.pass_gate_met = 1);

DELETE it FROM progress_test_items_v2 it
INNER JOIN progress_tests_v2 t ON t.id = it.test_id
WHERE t.user_id = @student_id AND t.cohort_id = @cohort_id AND t.lesson_id = @lesson_id
  AND NOT (t.status = 'completed' AND t.pass_gate_met = 1);

DELETE FROM progress_tests_v2
WHERE user_id = @student_id AND cohort_id = @cohort_id AND lesson_id = @lesson_id
  AND NOT (status = 'completed' AND pass_gate_met = 1);

DELETE FROM progress_test_remote_authorizations
WHERE student_id = @student_id AND cohort_id = @cohort_id AND lesson_id = @lesson_id;

UPDATE lesson_activity
SET test_pass_status = 'in_progress',
    completion_status = 'awaiting_test_completion',
    status = 'awaiting_test_completion',
    completed_at = NULL,
    next_lesson_unlocked_at = NULL,
    last_state_eval_at = UTC_TIMESTAMP(),
    updated_at = UTC_TIMESTAMP()
WHERE user_id = @student_id AND cohort_id = @cohort_id AND lesson_id = @lesson_id;

SELECT 'done — reload course page, expect amber Request Progress Test' AS status;
*/
