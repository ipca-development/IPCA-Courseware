<?php
declare(strict_types=1);

/**
 * IPCA Courseware - Cohort scheduling helper
 *
 * Writes: cohort_lesson_deadlines (deadline_utc always stored as YYYY-MM-DD 00:00:00 in UTC)
 * Displays: dates as "Mon, Jan 12, 2026"
 */

function cw_setting(PDO $pdo, string $key, string $default = ''): string {
    // Your app_settings table column naming may vary.
    // We try the most common variants in a safe order.
    $candidates = [
        'value',
        'setting_value',
        'val',
        'setting',
        'data',
        'json_value',
    ];

    foreach ($candidates as $col) {
        try {
            // NOTE: column name cannot be bound as parameter; we safely whitelist above.
            $sql = "SELECT `$col` FROM app_settings WHERE `key`=? LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute([$key]);
            $v = $st->fetchColumn();
            if ($v !== false && $v !== null) return (string)$v;
        } catch (PDOException $e) {
            // Try next candidate column name
            continue;
        }
    }

    return $default;
}

function cw_fmt_date_pretty(string $ymd): string {
    $d = substr($ymd, 0, 10);
    try {
        $dt = new DateTimeImmutable($d . ' 00:00:00', new DateTimeZone('UTC'));
        return $dt->format('D, M j, Y');
    } catch (Throwable $e) {
        return $ymd;
    }
}

function cw_minutes_to_hours(float $minutes): string {
    $hours = $minutes / 60.0;
    return number_format($hours, 1);
}

function cw_clamp_int($v, int $min, int $max, int $default): int {
    $n = (int)$v;
    if ($n < $min || $n > $max) return $default;
    return $n;
}

/**
 * Estimate reading minutes based on text length (narration_en).
 * WPM default 160. Factor default 2.5.
 */
function cw_estimate_lesson_minutes(PDO $pdo, int $lessonId, int $wpm, float $factor, int $progressTestMinutes): int {
    $st = $pdo->prepare("
      SELECT e.narration_en
      FROM slides s
      JOIN slide_enrichment e ON e.slide_id = s.id
      WHERE s.lesson_id=? AND s.is_deleted=0 AND e.narration_en IS NOT NULL AND e.narration_en <> ''
      ORDER BY s.page_number ASC
    ");
    $st->execute([$lessonId]);
    $rows = $st->fetchAll(PDO::FETCH_COLUMN);

    $txt = '';
    foreach ($rows as $r) {
        $t = trim((string)$r);
        if ($t !== '') $txt .= $t . "\n";
    }

    $chars = mb_strlen($txt);
    $words = (int)ceil($chars / 6); // ~6 chars/word
    $readMin = ($wpm > 0) ? ($words / $wpm) : 0.0;

    $studyMin = (int)ceil($readMin * $factor);
    $total = $studyMin + max(0, $progressTestMinutes);

    if ($total < 20) $total = 20;
    return $total;
}

function cw_get_enabled_course_ids_for_cohort(PDO $pdo, int $cohortId, int $programId): array {
    $st = $pdo->prepare("SELECT course_id, is_enabled FROM cohort_courses WHERE cohort_id=?");
    $st->execute([$cohortId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if ($rows) {
        $ids = [];
        foreach ($rows as $r) {
            if ((int)$r['is_enabled'] === 1) $ids[] = (int)$r['course_id'];
        }
        return array_values(array_unique($ids));
    }

    $st = $pdo->prepare("SELECT id FROM courses WHERE program_id=? ORDER BY sort_order, id");
    $st->execute([$programId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function cw_recalculate_cohort_deadlines(PDO $pdo, int $cohortId): array {
    $co = $pdo->prepare("SELECT id, name, start_date, end_date, timezone, program_id FROM cohorts WHERE id=? LIMIT 1");
    $co->execute([$cohortId]);
    $cohort = $co->fetch(PDO::FETCH_ASSOC);
    if (!$cohort) throw new RuntimeException("Cohort not found");

    $programId = (int)($cohort['program_id'] ?? 0);
    if ($programId <= 0) throw new RuntimeException("Cohort program_id is missing");

    // Settings (adaptable, not hardcoded)
    $maxMinDay = cw_clamp_int(cw_setting($pdo, 'study_max_minutes_per_day', '120'), 30, 600, 120);
    $wpm       = cw_clamp_int(cw_setting($pdo, 'study_wpm', '160'), 60, 400, 160);
    $factorStr = cw_setting($pdo, 'study_factor', '2.5');
    $factor    = (float)$factorStr;
    if ($factor < 1.0 || $factor > 6.0) $factor = 2.5;
    $ptMin     = cw_clamp_int(cw_setting($pdo, 'progress_test_minutes', '30'), 0, 180, 30);

    $enabledCourseIds = cw_get_enabled_course_ids_for_cohort($pdo, $cohortId, $programId);
    if (!$enabledCourseIds) throw new RuntimeException("No courses enabled for cohort/program");

    $in = implode(',', array_fill(0, count($enabledCourseIds), '?'));
    $sql = "
      SELECT
        c.id AS course_id, c.title AS course_title, c.sort_order AS course_sort,
        l.id AS lesson_id, l.external_lesson_id, l.title AS lesson_title, l.sort_order AS lesson_sort
      FROM courses c
      JOIN lessons l ON l.course_id = c.id
      WHERE c.id IN ($in)
      ORDER BY c.sort_order, c.id, l.sort_order, l.external_lesson_id
    ";
    $st = $pdo->prepare($sql);
    $st->execute($enabledCourseIds);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $courses = [];
    foreach ($rows as $r) {
        $cid = (int)$r['course_id'];
        if (!isset($courses[$cid])) {
            $courses[$cid] = [
                'course_id' => $cid,
                'course_title' => (string)$r['course_title'],
                'course_sort' => (int)$r['course_sort'],
                'lessons' => []
            ];
        }
        $courses[$cid]['lessons'][] = [
            'lesson_id' => (int)$r['lesson_id'],
            'external_lesson_id' => (int)$r['external_lesson_id'],
            'title' => (string)$r['lesson_title'],
            'lesson_sort' => (int)$r['lesson_sort'],
        ];
    }

    $startYmd = (string)$cohort['start_date'];
    $endYmd   = (string)$cohort['end_date'];

    $start = new DateTimeImmutable($startYmd . ' 00:00:00', new DateTimeZone('UTC'));
    $end   = new DateTimeImmutable($endYmd   . ' 00:00:00', new DateTimeZone('UTC'));
    if ($end < $start) throw new RuntimeException("End date is before start date");

    $days = [];
    $cursor = $start;
    while ($cursor <= $end) {
        $days[] = $cursor;
        $cursor = $cursor->modify('+1 day');
    }
    $usableDays = count($days);

    $pdo->prepare("DELETE FROM cohort_lesson_deadlines WHERE cohort_id=?")->execute([$cohortId]);

    $ins = $pdo->prepare("
      INSERT INTO cohort_lesson_deadlines
        (cohort_id, lesson_id, sort_order, unlock_after_lesson_id, deadline_utc, created_at)
      VALUES
        (?,?,?,?,?,NOW())
    ");

    $scheduleLessons = [];
    $totalMinutes = 0;

    $dayIdx = 0;
    $usedToday = 0;
    $globalLessonOrder = 0;
    $lastLessonId = null;

    foreach ($courses as $cid => $c) {
        foreach ($c['lessons'] as $lesson) {
            $globalLessonOrder += 1;

            $lessonMin = cw_estimate_lesson_minutes($pdo, (int)$lesson['lesson_id'], $wpm, $factor, $ptMin);
            $totalMinutes += $lessonMin;

            $remaining = $lessonMin;

            while ($remaining > 0) {
                if ($dayIdx >= $usableDays) {
                    $dayIdx = $usableDays - 1;
                    $usedToday = $maxMinDay;
                    $remaining = 0;
                    break;
                }

                $free = $maxMinDay - $usedToday;
                if ($free <= 0) {
                    $dayIdx++;
                    $usedToday = 0;
                    continue;
                }

                $take = min($free, $remaining);
                $usedToday += $take;
                $remaining -= $take;

                if ($remaining <= 0) {
                    $deadlineDay = $days[$dayIdx]->format('Y-m-d');
                    $deadlineUtc = $deadlineDay . ' 00:00:00';

                    $unlockAfter = null;
                    if ($lastLessonId !== null) $unlockAfter = (int)$lastLessonId;

                    $ins->execute([$cohortId, (int)$lesson['lesson_id'], $globalLessonOrder * 10, $unlockAfter, $deadlineUtc]);

                    $scheduleLessons[] = [
                        'course_id' => (int)$cid,
                        'course_title' => (string)$c['course_title'],
                        'lesson_id' => (int)$lesson['lesson_id'],
                        'external_lesson_id' => (int)$lesson['external_lesson_id'],
                        'title' => (string)$lesson['title'],
                        'deadline_utc' => $deadlineUtc,
                        'deadline_pretty' => cw_fmt_date_pretty($deadlineUtc),
                        'sort_order' => $globalLessonOrder * 10,
                    ];

                    $lastLessonId = (int)$lesson['lesson_id'];
                }
            }
        }
    }

    $courseGroups = [];
    $courseOrder = 0;
    foreach ($courses as $cid => $c) {
        $courseOrder++;
        $lessonsOut = [];
        $courseLastDeadlineUtc = null;

        $courseLessons = array_values(array_filter($scheduleLessons, function($x) use ($cid){
            return (int)$x['course_id'] === (int)$cid;
        }));

        foreach ($courseLessons as $lx) {
            $lessonsOut[] = [
                'lesson_id' => (int)$lx['lesson_id'],
                'external_lesson_id' => (int)$lx['external_lesson_id'],
                'title' => (string)$lx['title'],
                'deadline_utc' => (string)$lx['deadline_utc'],
                'deadline_pretty' => (string)$lx['deadline_pretty'],
                'sort_order' => (int)$lx['sort_order'],
            ];
            $courseLastDeadlineUtc = (string)$lx['deadline_utc'];
        }

        $courseGroups[] = [
            'course_id' => (int)$cid,
            'course_title' => (string)$c['course_title'],
            'course_order' => $courseOrder,
            'course_deadline_utc' => $courseLastDeadlineUtc ?: ($end->format('Y-m-d') . ' 00:00:00'),
            'course_deadline_pretty' => cw_fmt_date_pretty($courseLastDeadlineUtc ?: $end->format('Y-m-d').' 00:00:00'),
            'lessons' => $lessonsOut,
        ];
    }

    $totalHours = cw_minutes_to_hours((float)$totalMinutes);
    $recommendedDays = (int)ceil(((float)$totalMinutes) / (float)$maxMinDay);

    $suggestedEndUtc = $scheduleLessons ? (string)end($scheduleLessons)['deadline_utc'] : ($end->format('Y-m-d').' 00:00:00');
    $suggestedPretty = cw_fmt_date_pretty($suggestedEndUtc);

    $suggested = new DateTimeImmutable(substr($suggestedEndUtc,0,10) . ' 00:00:00', new DateTimeZone('UTC'));
    $deltaDays = (int)$end->diff($suggested)->format('%r%a');

    $deltaLabel = 'same as';
    if ($deltaDays > 0) $deltaLabel = $deltaDays . ' days later than';
    if ($deltaDays < 0) $deltaLabel = abs($deltaDays) . ' days earlier than';

    $summary = [
        'lessons_scheduled' => count($scheduleLessons),
        'total_study_hours' => $totalHours,
        'usable_days' => $usableDays,
        'recommended_days' => $recommendedDays,
        'assumptions' => "Assumptions: Max {$maxMinDay} min/day, Factor {$factor}, {$wpm} WPM, PT {$ptMin} minutes.",
        'suggested_end_pretty' => $suggestedPretty,
        'suggested_end_delta' => $deltaLabel . " cohort end date",
    ];

    return [
        'summary' => $summary,
        'courses' => $courseGroups,
    ];
}