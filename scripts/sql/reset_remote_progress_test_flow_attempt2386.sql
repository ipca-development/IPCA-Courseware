-- Targeted reset: auth #5 + attempt #2386 (cohort 5, lesson 3)
-- Run in DigitalOcean SQL console. Reload course page after — expect amber Request.

SET @attempt_id := 2386;
SET @auth_id := 5;

-- 1) Capture user_id before deletes
SELECT @student_id := user_id, @cohort_id := cohort_id, @lesson_id := lesson_id
FROM progress_tests_v2
WHERE id = @attempt_id
LIMIT 1;

SELECT @student_id AS student_id, @cohort_id AS cohort_id, @lesson_id AS lesson_id;

-- 2) Delete child rows + attempt + auth
DELETE FROM progress_test_oral_item_responses WHERE attempt_id = @attempt_id;
DELETE FROM progress_test_v4_card_sessions WHERE attempt_id = @attempt_id;
DELETE FROM progress_test_v4_answer_chunks WHERE attempt_id = @attempt_id;
DELETE FROM progress_test_oral_integrity_reviews WHERE attempt_id = @attempt_id;
DELETE FROM progress_test_user_badges WHERE attempt_id = @attempt_id;
DELETE FROM progress_test_v4_debug_events WHERE attempt_id = @attempt_id;
DELETE FROM progress_test_items_v2 WHERE test_id = @attempt_id;
DELETE FROM progress_tests_v2 WHERE id = @attempt_id;
DELETE FROM progress_test_remote_authorizations WHERE id = @auth_id;

-- Also remove any other auths for this lesson (USED/EXPIRED leftovers)
DELETE FROM progress_test_remote_authorizations
WHERE student_id = @student_id AND cohort_id = @cohort_id AND lesson_id = @lesson_id;

-- 3) Reset lesson activity
UPDATE lesson_activity
SET test_pass_status = 'in_progress',
    completion_status = 'awaiting_test_completion',
    status = 'awaiting_test_completion',
    completed_at = NULL,
    next_lesson_unlocked_at = NULL,
    last_state_eval_at = UTC_TIMESTAMP(),
    updated_at = UTC_TIMESTAMP()
WHERE user_id = @student_id AND cohort_id = @cohort_id AND lesson_id = @lesson_id;

-- 4) Verify
SELECT 'remaining_auths' AS check_name, COUNT(*) AS n
FROM progress_test_remote_authorizations
WHERE student_id = @student_id AND cohort_id = @cohort_id AND lesson_id = @lesson_id;

SELECT 'open_attempts' AS check_name, id, status, idempotency_key
FROM progress_tests_v2
WHERE user_id = @student_id AND cohort_id = @cohort_id AND lesson_id = @lesson_id
  AND status IN ('preparing','ready','in_progress','processing');
