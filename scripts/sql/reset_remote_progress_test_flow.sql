-- Reset remote progress test flow for re-testing Request -> email -> auth -> code -> test
-- Run the DISCOVERY queries first, then set @student_id / @cohort_id / @lesson_id and run DELETE block.

-- ========== DISCOVERY (run first) ==========
-- SELECT id, name, email FROM users WHERE name LIKE '%Kay%' OR email LIKE '%kay%' ORDER BY id DESC;
-- SELECT cs.cohort_id, c.name FROM cohort_students cs JOIN cohorts c ON c.id = cs.cohort_id WHERE cs.user_id = ? AND cs.status = 'active';
-- SELECT a.id, a.cohort_id, a.lesson_id, l.title, a.status, a.progress_test_attempt_id, a.expires_at
--   FROM progress_test_remote_authorizations a LEFT JOIN lessons l ON l.id = a.lesson_id
--   WHERE a.student_id = ? ORDER BY a.id DESC;
-- SELECT t.id, t.cohort_id, t.lesson_id, l.title, t.status, t.pass_gate_met, t.idempotency_key, t.updated_at
--   FROM progress_tests_v2 t LEFT JOIN lessons l ON l.id = t.lesson_id
--   WHERE t.user_id = ? AND t.status IN ('preparing','ready','in_progress','processing') ORDER BY t.id DESC;

-- ========== RESET (edit these three values) ==========
-- Use IDs from your course URL, e.g. cohort_id=5 lesson_id=3:
--   https://ipca.training/student/course.php?cohort_id=5
--   https://ipca.training/student/progress_test_v4.php?cohort_id=5&lesson_id=3
SET @student_id := 0;   -- your users.id (run discovery query first)
SET @cohort_id := 5;
SET @lesson_id := 3;

-- Preview
SELECT 'remote_authorizations' AS tbl, id, status, progress_test_attempt_id, expires_at
FROM progress_test_remote_authorizations
WHERE student_id = @student_id AND cohort_id = @cohort_id AND lesson_id = @lesson_id
ORDER BY id DESC;

SELECT 'attempts_to_delete' AS tbl, id, status, pass_gate_met, idempotency_key, updated_at
FROM progress_tests_v2
WHERE user_id = @student_id AND cohort_id = @cohort_id AND lesson_id = @lesson_id
  AND NOT (status = 'completed' AND pass_gate_met = 1)
  AND (
    status IN ('preparing','ready','in_progress','processing')
    OR idempotency_key LIKE 'remote_auth_%'
    OR status = 'failed'
    OR updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
  )
ORDER BY id DESC;

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

SELECT 'done' AS status;
