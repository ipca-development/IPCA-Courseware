-- Delete specific lessons and dependent rows (MySQL).
-- Edit @lesson_ids to the comma-separated internal lessons.id values you want removed.
--
-- Use when lessons are placeholders (e.g. page_count = 0, no slides) and should not remain
-- in catalog / cohort scope / progression history.
--
-- Before running:
--   1) Backup the database (or at least affected tables).
--   2) Confirm lesson ids (internal id, not external_lesson_id).
--   3) If your schema omits a table below, comment out that DELETE (MySQL will error on unknown table).
--
-- Apply:  mysql ... < scripts/sql/delete_lessons_cascade.sql
-- Or paste into TablePlus / CLI after editing @lesson_ids.

SET @lesson_ids := '941,945,953,958,959,962,989,1080,1100';

-- ---------------------------------------------------------------------------
-- Slide graph (same child order as scripts/sql/delete_courses_cascade.sql)
-- ---------------------------------------------------------------------------
DELETE sc FROM slide_content sc
INNER JOIN slides s ON s.id = sc.slide_id
WHERE FIND_IN_SET(s.lesson_id, @lesson_ids);

DELETE se FROM slide_enrichment se
INNER JOIN slides s ON s.id = se.slide_id
WHERE FIND_IN_SET(s.lesson_id, @lesson_ids);

DELETE sr FROM slide_references sr
INNER JOIN slides s ON s.id = sr.slide_id
WHERE FIND_IN_SET(s.lesson_id, @lesson_ids);

DELETE sh FROM slide_hotspots sh
INNER JOIN slides s ON s.id = sh.slide_id
WHERE FIND_IN_SET(s.lesson_id, @lesson_ids);

DELETE sev FROM slide_events sev
INNER JOIN slides s ON s.id = sev.slide_id
WHERE FIND_IN_SET(s.lesson_id, @lesson_ids);

DELETE sao FROM slide_ai_outputs sao
INNER JOIN slides s ON s.id = sao.slide_id
WHERE FIND_IN_SET(s.lesson_id, @lesson_ids);

DELETE s FROM slides s
WHERE FIND_IN_SET(s.lesson_id, @lesson_ids);

-- ---------------------------------------------------------------------------
-- Training / progression audit rows (before progress_tests_v2 if FK references tests)
-- ---------------------------------------------------------------------------
DELETE FROM training_progression_events
WHERE FIND_IN_SET(lesson_id, @lesson_ids);

DELETE FROM training_progression_emails
WHERE FIND_IN_SET(lesson_id, @lesson_ids);

-- ---------------------------------------------------------------------------
-- Progress tests v2 (items first)
-- ---------------------------------------------------------------------------
DELETE it FROM progress_test_items_v2 it
INNER JOIN progress_tests_v2 pt ON pt.id = it.test_id
WHERE FIND_IN_SET(pt.lesson_id, @lesson_ids);

DELETE pt FROM progress_tests_v2 pt
WHERE FIND_IN_SET(pt.lesson_id, @lesson_ids);

-- Legacy progress_tests (omit this block if the table does not exist)
-- DELETE FROM progress_tests WHERE FIND_IN_SET(lesson_id, @lesson_ids);

-- ---------------------------------------------------------------------------
-- Lesson summaries + versions + security log
-- ---------------------------------------------------------------------------
DELETE FROM lesson_summary_versions
WHERE FIND_IN_SET(lesson_id, @lesson_ids);

DELETE FROM lesson_summary_security_events
WHERE FIND_IN_SET(lesson_id, @lesson_ids);

DELETE FROM lesson_summaries
WHERE FIND_IN_SET(lesson_id, @lesson_ids);

-- ---------------------------------------------------------------------------
-- Lesson activity + cohort lesson wiring + deadline overrides + required actions
-- ---------------------------------------------------------------------------
DELETE FROM lesson_activity
WHERE FIND_IN_SET(lesson_id, @lesson_ids);

DELETE FROM student_lesson_deadline_overrides
WHERE FIND_IN_SET(lesson_id, @lesson_ids);

DELETE FROM student_required_actions
WHERE FIND_IN_SET(lesson_id, @lesson_ids);

DELETE FROM cohort_lesson_deadlines
WHERE FIND_IN_SET(lesson_id, @lesson_ids);

DELETE FROM cohort_lesson_scope
WHERE FIND_IN_SET(lesson_id, @lesson_ids);

-- ---------------------------------------------------------------------------
-- Lessons (last)
-- ---------------------------------------------------------------------------
DELETE FROM lessons
WHERE FIND_IN_SET(id, @lesson_ids);
