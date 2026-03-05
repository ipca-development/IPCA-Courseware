<?php
declare(strict_types=1);

/**
 * Schedule / deadline generation for theory cohorts.
 *
 * Stores deadlines in UTC at 00:00:00.
 * Uses narration scripts as primary source of truth for timing:
 *   slide_enrichment.narration_en per slide in lesson.
 *
 * If narration missing, falls back to slide_content plain_text (EN).
 */

function cw_setting(PDO $pdo, string $key, $defaultValue) {
    try {
        $st = $pdo->prepare("SELECT value FROM app_settings WHERE `key`=? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null) return $defaultValue;
        return $v;
    } catch (Throwable $e) {
        return $defaultValue;
    }
}

function cw_setting_float(PDO $pdo, string $key, float $defaultValue): float {
    $v = cw_setting($pdo, $key, (string)$defaultValue);
    $f = (float)$v;
    if ($f <= 0) return $defaultValue;
    return $f;
}

function cw_setting_int(PDO $pdo, string $key, int $defaultValue): int {
    $v = cw_setting($pdo, $key, (string)$defaultValue);
    $i = (int)$v;
    if ($i < 0) return $defaultValue;
    return $i;
}

/**
 * Estimate minutes for a lesson.
 * - Convert characters -> words using 5 chars/word heuristic.
 * - Reading time = words / WPM * 60
 * - Study time = reading time * factor
 * - Plus fixed test minutes
 */
function cw_estimate_lesson_minutes(PDO $pdo, int $lessonId, float $factor, int $wpm, int $testMinutes): int {
    $chars = 0;

    // 1) narration_en chars (preferred)
    $st = $pdo->prepare("
      SELECT COALESCE(SUM(CHAR_LENGTH(e.narration_en)),0) AS c
      FROM slides s
      JOIN slide_enrichment e ON e.slide_id = s.id
      WHERE s.lesson_id=? AND s.is_deleted=0
        AND e.narration_en IS NOT NULL AND e.narration_en <> ''
    ");
    $st->execute([$lessonId]);
    $chars = (int)($st->fetchColumn() ?: 0);

    // 2) fallback: slide_content EN plain_text (if narration missing)
    if ($chars <= 0) {
        $st = $pdo->prepare("
          SELECT COALESCE(SUM(CHAR_LENGTH(sc.plain_text)),0) AS c
          FROM slides s
          JOIN slide_content sc ON sc.slide_id = s.id AND sc.lang='en'
          WHERE s.lesson_id=? AND s.is_deleted=0
            AND sc.plain_text IS NOT NULL AND sc.plain_text <> ''
        ");
        $st->execute([$lessonId]);
        $chars = (int)($st->fetchColumn() ?: 0);
    }

    // If still unknown, assume minimum
    if ($chars <= 0) $chars = 800; // ~160 words baseline

    $words = max(20, (int)round($chars / 5)); // 5 chars/word heuristic
    $readSeconds = (int)round(($words / max(60, $wpm)) * 60.0);
    $studySeconds = (int)round($readSeconds * max(1.0, $factor));
    $studyMinutes = (int)ceil($studySeconds / 60);

    $total = $studyMinutes + max(0, $testMinutes);

    // floor + ceiling guard
    if ($total < 20) $total = 20;
    if ($total > 360) $total = 360; // prevent insane outliers per lesson

    return $total;
}

/**
 * Get enabled courses for a cohort. If none present, fall back to program courses.
 */
function cw_get_enabled_course_ids(PDO $pdo, int $cohortId, int $programId): array {
    $st = $pdo->prepare("
      SELECT course_id
      FROM cohort_courses
      WHERE cohort_id=? AND is_enabled=1
      ORDER BY course_id
    ");
    $st->execute([$cohortId]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);

    $out = [];
    foreach ($ids as $x) $out[] = (int)$x;
    $out = array_values(array_unique(array_filter($out)));

    if ($out) return $out;

    // fallback: all courses in program
    $st = $pdo->prepare("SELECT id FROM courses WHERE program_id=? ORDER BY sort_order, id");
    $st->execute([$programId]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($ids as $x) $out[] = (int)$x;
    return array_values(array_unique(array_filter($out)));
}

/**
 * Build the ordered lesson list for selected courses.
 */
function cw_get_lessons_for_courses(PDO $pdo, array $courseIds): array {
    if (!$courseIds) return [];

    $place = implode(',', array_fill(0, count($courseIds), '?'));
    $st = $pdo->prepare("
      SELECT l.id, l.course_id, l.external_lesson_id, l.title, l.sort_order,
             c.sort_order AS course_sort, c.title AS course_title
      FROM lessons l
      JOIN courses c ON c.id = l.course_id
      WHERE l.course_id IN ($place)
      ORDER BY c.sort_order, c.id, l.sort_order, l.external_lesson_id, l.id
    ");
    $st->execute($courseIds);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Generate and store deadlines.
 *
 * Returns summary array with warnings.
 */
function cw_recalculate_cohort_deadlines(PDO $pdo, int $cohortId): array {
    // Cohort row
    $st = $pdo->prepare("SELECT id, program_id, start_date, end_date FROM cohorts WHERE id=? LIMIT 1");
    $st->execute([$cohortId]);
    $co = $st->fetch(PDO::FETCH_ASSOC);
    if (!$co) throw new RuntimeException('Cohort not found');

    $programId = (int)($co['program_id'] ?? 0);
    if ($programId <= 0) throw new RuntimeException('Cohort has no program_id');

    $startDate = (string)$co['start_date'];
    $endDate   = (string)$co['end_date'];
    if ($startDate === '' || $endDate === '') throw new RuntimeException('Cohort missing start/end date');

    // Settings (not hard-coded)
    $factor = cw_setting_float($pdo, 'schedule_study_factor', 2.5);
    $wpm = cw_setting_int($pdo, 'schedule_wpm', 160);
    $testMinutes = cw_setting_int($pdo, 'schedule_progress_test_minutes', 30);
    $maxMinutesPerDay = cw_setting_int($pdo, 'schedule_max_minutes_per_day', 120);
    $bufferDays = cw_setting_int($pdo, 'schedule_buffer_days', 3);

    // Enabled courses
    $courseIds = cw_get_enabled_course_ids($pdo, $cohortId, $programId);
    $lessons = cw_get_lessons_for_courses($pdo, $courseIds);

    // Nothing to schedule
    if (!$lessons) {
        $pdo->prepare("DELETE FROM cohort_lesson_deadlines WHERE cohort_id=?")->execute([$cohortId]);
        return [
            'ok' => true,
            'lessons' => 0,
            'total_minutes' => 0,
            'days_used' => 0,
            'warnings' => ['No lessons found for selected courses.'],
        ];
    }

    // Build date buckets (UTC dates, deadline at 00:00 UTC)
    $utc = new DateTimeZone('UTC');
    $start = new DateTimeImmutable($startDate, $utc);
    $end   = new DateTimeImmutable($endDate, $utc);

    if ($end < $start) throw new RuntimeException('End date is before start date');

    $totalDays = (int)$end->diff($start)->days + 1;
    $usableDays = max(1, $totalDays - max(0, $bufferDays));
    $scheduleEnd = $start->modify('+' . ($usableDays - 1) . ' days');

    // Build list of dates
    $dates = [];
    for ($i = 0; $i < $usableDays; $i++) {
        $dates[] = $start->modify("+{$i} days");
    }

    // Estimate minutes per lesson
    $lessonPlan = [];
    $totalMinutes = 0;
    foreach ($lessons as $l) {
        $lid = (int)$l['id'];
        $mins = cw_estimate_lesson_minutes($pdo, $lid, $factor, $wpm, $testMinutes);
        $lessonPlan[] = [
            'lesson_id' => $lid,
            'mins' => $mins,
        ];
        $totalMinutes += $mins;
    }

    $capacity = $usableDays * max(30, $maxMinutesPerDay);
    $warnings = [];

    if ($totalMinutes > $capacity) {
        $warnings[] = "Planned workload ({$totalMinutes} min) exceeds schedule capacity ({$capacity} min) using max {$maxMinutesPerDay} min/day. Deadlines will still be generated, but daily load may exceed the target.";
    }

    // Assign lessons to days sequentially
    $assignments = []; // [lesson_id => deadlineDate]
    $dayIndex = 0;
    $dayUsed = 0;

    foreach ($lessonPlan as $lp) {
        if ($dayIndex >= count($dates)) {
            // no more days -> assign to last day
            $assignments[$lp['lesson_id']] = $dates[count($dates)-1];
            continue;
        }

        // if adding exceeds per-day, move day (unless day empty)
        if ($dayUsed > 0 && ($dayUsed + $lp['mins']) > $maxMinutesPerDay && $dayIndex < (count($dates)-1)) {
            $dayIndex++;
            $dayUsed = 0;
        }

        $assignments[$lp['lesson_id']] = $dates[$dayIndex];
        $dayUsed += $lp['mins'];
    }

    // Store into DB: replace existing
    $pdo->prepare("DELETE FROM cohort_lesson_deadlines WHERE cohort_id=?")->execute([$cohortId]);

    $ins = $pdo->prepare("
      INSERT INTO cohort_lesson_deadlines (cohort_id, lesson_id, deadline_utc, sort_order, unlock_after_lesson_id)
      VALUES (?,?,?,?,?)
    ");

    $sort = 10;
    $prevLessonId = null;

    foreach ($lessons as $l) {
        $lid = (int)$l['id'];
        $deadlineDate = $assignments[$lid] ?? $scheduleEnd;
        // deadline at 00:00 UTC
        $deadlineUtc = $deadlineDate->format('Y-m-d') . ' 00:00:00';

        $ins->execute([
            $cohortId,
            $lid,
            $deadlineUtc,
            $sort,
            $prevLessonId ? (int)$prevLessonId : null
        ]);

        $prevLessonId = $lid;
        $sort += 10;
    }

    // Buffer warning visibility
    if ($bufferDays > 0) {
        $warnings[] = "Buffer reserved: {$bufferDays} day(s). Scheduling ends on " . $scheduleEnd->format('Y-m-d') . " (end date is " . $end->format('Y-m-d') . ").";
    }

    return [
        'ok' => true,
        'lessons' => count($lessons),
        'total_minutes' => $totalMinutes,
        'usable_days' => $usableDays,
        'max_minutes_per_day' => $maxMinutesPerDay,
        'study_factor' => $factor,
        'wpm' => $wpm,
        'progress_test_minutes' => $testMinutes,
        'warnings' => $warnings,
    ];
}