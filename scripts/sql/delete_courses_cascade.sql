-- Delete courses and dependent rows (MySQL / TablePlus).
-- Edit the IN (...) list to match the course id(s) you want removed.
--
-- Error you saw: lessons.course_id -> courses.id must be cleared before DELETE FROM courses.

SET @course_ids := '23,24';

-- Slide-level children (adjust table list if your schema differs)
DELETE sc FROM slide_content sc
INNER JOIN slides s ON s.id = sc.slide_id
INNER JOIN lessons l ON l.id = s.lesson_id
WHERE FIND_IN_SET(l.course_id, @course_ids);

DELETE se FROM slide_enrichment se
INNER JOIN slides s ON s.id = se.slide_id
INNER JOIN lessons l ON l.id = s.lesson_id
WHERE FIND_IN_SET(l.course_id, @course_ids);

DELETE sr FROM slide_references sr
INNER JOIN slides s ON s.id = sr.slide_id
INNER JOIN lessons l ON l.id = s.lesson_id
WHERE FIND_IN_SET(l.course_id, @course_ids);

DELETE sh FROM slide_hotspots sh
INNER JOIN slides s ON s.id = sh.slide_id
INNER JOIN lessons l ON l.id = s.lesson_id
WHERE FIND_IN_SET(l.course_id, @course_ids);

DELETE sev FROM slide_events sev
INNER JOIN slides s ON s.id = sev.slide_id
INNER JOIN lessons l ON l.id = s.lesson_id
WHERE FIND_IN_SET(l.course_id, @course_ids);

DELETE sao FROM slide_ai_outputs sao
INNER JOIN slides s ON s.id = sao.slide_id
INNER JOIN lessons l ON l.id = s.lesson_id
WHERE FIND_IN_SET(l.course_id, @course_ids);

-- Slides
DELETE s FROM slides s
INNER JOIN lessons l ON l.id = s.lesson_id
WHERE FIND_IN_SET(l.course_id, @course_ids);

-- Lesson-level cohort links (if present)
DELETE d FROM cohort_lesson_deadlines d
INNER JOIN lessons l ON l.id = d.lesson_id
WHERE FIND_IN_SET(l.course_id, @course_ids);

DELETE cs FROM cohort_lesson_scope cs
INNER JOIN lessons l ON l.id = cs.lesson_id
WHERE FIND_IN_SET(l.course_id, @course_ids);

-- Lessons (this clears fk_lessons_course so courses can be deleted)
DELETE FROM lessons WHERE FIND_IN_SET(course_id, @course_ids);

-- Cohort ↔ course link
DELETE FROM cohort_courses WHERE FIND_IN_SET(course_id, @course_ids);

-- Courses
DELETE FROM courses WHERE FIND_IN_SET(id, @course_ids);
