<?php
declare(strict_types=1);

/**
 * IPCA Courseware - Cohort scheduling helper
 *
 * Canonical responsibilities:
 * - resolve cohort lesson scope from cohort_lesson_scope
 * - preview a cohort schedule without mutating live deadlines
 * - publish a cohort schedule into cohort_lesson_deadlines
 * - optionally persist lightweight schedule versions if version tables exist
 *
 * Important design rules:
 * - Admin-selected scope is authoritative.
 * - Scheduling rules are advisory only; they must never silently omit scoped lessons.
 * - If the schedule is too tight, preview/publish still includes all selected lessons.
 * - Student deadline overrides remain separate and are not mutated here.
 *
 * Current compatibility strategy:
 * - Uses app_settings as default seeds.
 * - Uses cohort_schedule_settings / cohort_schedule_versions / cohort_schedule_version_items if present.
 * - Falls back safely when those tables do not exist yet.
 */

/* =========================================================
 * Basic DB / schema helpers
 * ========================================================= */

function cw_table_exists(PDO $pdo, string $tableName): bool
{
    static $cache = array();

    $key = strtolower(trim($tableName));
    if ($key === '') {
        return false;
    }

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute(array($tableName));
        $exists = (bool)$stmt->fetchColumn();
        $cache[$key] = $exists;
        return $exists;
    } catch (Throwable $e) {
        $cache[$key] = false;
        return false;
    }
}

function cw_table_has_column(PDO $pdo, string $tableName, string $columnName): bool
{
    static $cache = array();

    $cacheKey = strtolower($tableName . '.' . $columnName);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `" . str_replace('`', '``', $tableName) . "` LIKE ?");
        $stmt->execute(array($columnName));
        $exists = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        $cache[$cacheKey] = $exists;
        return $exists;
    } catch (Throwable $e) {
        $cache[$cacheKey] = false;
        return false;
    }
}

/* =========================================================
 * Settings / formatting helpers
 * ========================================================= */

function cw_setting(PDO $pdo, string $key, string $default = ''): string
{
    static $cache = array();

    $cacheKey = 'app_setting:' . $key;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $key = trim($key);
    if ($key === '') {
        return $default;
    }

    if (!cw_table_exists($pdo, 'app_settings')) {
        $cache[$cacheKey] = $default;
        return $default;
    }

    $keyColumnCandidates = array('k', 'key');
    $valueColumnCandidates = array('v', 'value', 'setting_value', 'val', 'setting', 'data', 'json_value');

    foreach ($keyColumnCandidates as $keyCol) {
        if (!cw_table_has_column($pdo, 'app_settings', $keyCol)) {
            continue;
        }

        foreach ($valueColumnCandidates as $valueCol) {
            if (!cw_table_has_column($pdo, 'app_settings', $valueCol)) {
                continue;
            }

            try {
                $sql = "SELECT `" . $valueCol . "` FROM app_settings WHERE `" . $keyCol . "` = ? LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array($key));
                $val = $stmt->fetchColumn();
                if ($val !== false && $val !== null) {
                    $cache[$cacheKey] = (string)$val;
                    return $cache[$cacheKey];
                }
            } catch (Throwable $e) {
                continue;
            }
        }
    }

    $cache[$cacheKey] = $default;
    return $default;
}

function cw_fmt_date_pretty(string $ymdOrDt): string
{
    $raw = trim($ymdOrDt);
    if ($raw === '') {
        return '—';
    }

    try {
        $dt = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        return $dt->format('D, M j, Y');
    } catch (Throwable $e) {
        $d = substr($raw, 0, 10);
        try {
            $dt = new DateTimeImmutable($d . ' 00:00:00', new DateTimeZone('UTC'));
            return $dt->format('D, M j, Y');
        } catch (Throwable $e2) {
            return $raw;
        }
    }
}

function cw_fmt_local_deadline_label(string $utcDateTime, string $timezone, string $format = 'D, M j, Y H:i'): string
{
    $raw = trim($utcDateTime);
    if ($raw === '') {
        return '—';
    }

    $tzName = trim($timezone) !== '' ? $timezone : 'UTC';

    try {
        $dt = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        $local = $dt->setTimezone(new DateTimeZone($tzName));
        return $local->format($format);
    } catch (Throwable $e) {
        return $raw;
    }
}

function cw_minutes_to_hours(float $minutes): string
{
    $hours = $minutes / 60.0;
    return number_format($hours, 1);
}

function cw_clamp_int($v, int $min, int $max, int $default): int
{
    $n = (int)$v;
    if ($n < $min || $n > $max) {
        return $default;
    }
    return $n;
}

function cw_clamp_float($v, float $min, float $max, float $default): float
{
    $n = (float)$v;
    if ($n < $min || $n > $max) {
        return $default;
    }
    return $n;
}

function cw_parse_allowed_weekdays($raw): array
{
    $default = array(1, 2, 3, 4, 5, 6, 7);

    if (is_array($raw)) {
        $source = $raw;
    } else {
        $str = trim((string)$raw);
        if ($str === '') {
            return $default;
        }

        $decoded = json_decode($str, true);
        if (is_array($decoded)) {
            $source = $decoded;
        } else {
            $source = preg_split('/\s*,\s*/', $str) ?: array();
        }
    }

    $out = array();
    foreach ($source as $item) {
        $n = (int)$item;
        if ($n >= 1 && $n <= 7) {
            $out[$n] = $n;
        }
    }

    if (!$out) {
        return $default;
    }

    ksort($out);
    return array_values($out);
}

function cw_normalize_cutoff_time(string $timeText, string $default = '23:59'): string
{
    $raw = trim($timeText);
    if ($raw === '') {
        return $default;
    }

    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $raw, $m)) {
        return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
    }

    return $default;
}

function cw_is_allowed_weekday(DateTimeImmutable $dateUtc, array $allowedWeekdays, string $timezone): bool
{
    $tzName = trim($timezone) !== '' ? $timezone : 'UTC';
    try {
        $local = $dateUtc->setTimezone(new DateTimeZone($tzName));
        $dow = (int)$local->format('N'); // 1=Mon ... 7=Sun
        return in_array($dow, $allowedWeekdays, true);
    } catch (Throwable $e) {
        $dow = (int)$dateUtc->format('N');
        return in_array($dow, $allowedWeekdays, true);
    }
}

function cw_find_next_allowed_day(DateTimeImmutable $startUtc, array $allowedWeekdays, string $timezone): DateTimeImmutable
{
    $cursor = $startUtc;
    $guard = 0;

    while ($guard < 3700) {
        if (cw_is_allowed_weekday($cursor, $allowedWeekdays, $timezone)) {
            return $cursor;
        }
        $cursor = $cursor->modify('+1 day');
        $guard++;
    }

    return $startUtc;
}


function cw_build_usable_schedule_days(
    string $startDateYmd,
    string $endDateYmd,
    array $allowedWeekdays,
    string $timezone
): array {
    $days = array();

    if (trim($startDateYmd) === '' || trim($endDateYmd) === '') {
        return $days;
    }

    try {
        $startUtc = new DateTimeImmutable($startDateYmd . ' 00:00:00', new DateTimeZone('UTC'));
        $endUtc   = new DateTimeImmutable($endDateYmd . ' 23:59:59', new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return $days;
    }

    if ($endUtc < $startUtc) {
        return $days;
    }

    $cursor = $startUtc;
    $guard = 0;

    while ($cursor <= $endUtc && $guard < 3700) {
        if (cw_is_allowed_weekday($cursor, $allowedWeekdays, $timezone)) {
            $days[] = $cursor;
        }

        $cursor = $cursor->modify('+1 day');
        $guard++;
    }

    return $days;
}

/* =========================================================
 * Reading / lesson estimate helpers
 * ========================================================= */

/**
 * Estimate study minutes based on narration length.
 * Falls back to a minimum practical value when text is sparse.
 */
function cw_estimate_lesson_minutes(PDO $pdo, int $lessonId, int $wpm, float $factor, int $progressTestMinutes): int
{
    $stmt = $pdo->prepare("
        SELECT e.narration_en
        FROM slides s
        JOIN slide_enrichment e ON e.slide_id = s.id
        WHERE s.lesson_id = ?
          AND s.is_deleted = 0
          AND e.narration_en IS NOT NULL
          AND e.narration_en <> ''
        ORDER BY s.page_number ASC
    ");
    $stmt->execute(array($lessonId));
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $txt = '';
    foreach ($rows as $r) {
        $t = trim((string)$r);
        if ($t !== '') {
            $txt .= $t . "\n";
        }
    }

    $chars = function_exists('mb_strlen') ? mb_strlen($txt) : strlen($txt);
    $words = (int)ceil($chars / 6); // rough approximation
    $readMin = ($wpm > 0) ? ($words / $wpm) : 0.0;

    $studyMin = (int)ceil($readMin * $factor);
    $total = $studyMin + max(0, $progressTestMinutes);

    if ($total < 20) {
        $total = 20;
    }

    return $total;
}

/* =========================================================
 * Cohort / course / scope helpers
 * ========================================================= */

function cw_get_cohort_row(PDO $pdo, int $cohortId): array
{
    $stmt = $pdo->prepare("
        SELECT id, name, start_date, end_date, timezone, program_id
        FROM cohorts
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute(array($cohortId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException('Cohort not found.');
    }

    return $row;
}

function cw_get_enabled_course_ids_for_cohort(PDO $pdo, int $cohortId, int $programId): array
{
    $stmt = $pdo->prepare("
        SELECT course_id, is_enabled
        FROM cohort_courses
        WHERE cohort_id = ?
    ");
    $stmt->execute(array($cohortId));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($rows) {
        $ids = array();
        foreach ($rows as $r) {
            if ((int)$r['is_enabled'] === 1) {
                $ids[] = (int)$r['course_id'];
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM courses
        WHERE program_id = ?
        ORDER BY sort_order, id
    ");
    $stmt->execute(array($programId));
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Seeds cohort_lesson_scope from currently enabled courses if no scope rows exist yet.
 * This preserves old cohorts while upgrading them to explicit lesson scope.
 */
function cw_seed_cohort_lesson_scope_from_enabled_courses(PDO $pdo, int $cohortId): int
{
    if (!cw_table_exists($pdo, 'cohort_lesson_scope')) {
        return 0;
    }

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM cohort_lesson_scope
        WHERE cohort_id = ?
    ");
    $countStmt->execute(array($cohortId));
    $existingCount = (int)$countStmt->fetchColumn();

    if ($existingCount > 0) {
        return 0;
    }

    $cohort = cw_get_cohort_row($pdo, $cohortId);
    $programId = (int)($cohort['program_id'] ?? 0);
    if ($programId <= 0) {
        return 0;
    }

    $enabledCourseIds = cw_get_enabled_course_ids_for_cohort($pdo, $cohortId, $programId);
    if (!$enabledCourseIds) {
        return 0;
    }

    $in = implode(',', array_fill(0, count($enabledCourseIds), '?'));
    $sql = "
        SELECT l.id
        FROM lessons l
        JOIN courses c ON c.id = l.course_id
        WHERE c.id IN ($in)
        ORDER BY c.sort_order, c.id, l.sort_order, l.external_lesson_id, l.id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($enabledCourseIds);
    $lessonIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if (!$lessonIds) {
        return 0;
    }

    $ins = $pdo->prepare("
        INSERT INTO cohort_lesson_scope
            (cohort_id, lesson_id, is_selected, selected_at, updated_at)
        VALUES
            (?, ?, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            is_selected = VALUES(is_selected),
            updated_at = NOW()
    ");

    $seeded = 0;
    foreach ($lessonIds as $lessonId) {
        $ins->execute(array($cohortId, $lessonId));
        $seeded++;
    }

    return $seeded;
}

/**
 * Returns selected lessons in authoritative cohort scope.
 * If scope rows do not exist yet, attempts to seed them from enabled courses.
 */
function cw_get_selected_lessons_for_cohort(PDO $pdo, int $cohortId, int $programId): array
{
    if (!cw_table_exists($pdo, 'cohort_lesson_scope')) {
        return cw_get_selected_lessons_for_cohort_legacy($pdo, $cohortId, $programId);
    }

    cw_seed_cohort_lesson_scope_from_enabled_courses($pdo, $cohortId);

    $stmt = $pdo->prepare("
        SELECT
            c.id AS course_id,
            c.title AS course_title,
            c.sort_order AS course_sort,
            l.id AS lesson_id,
            l.external_lesson_id,
            l.title AS lesson_title,
            l.sort_order AS lesson_sort,
            cls.is_selected
        FROM cohort_lesson_scope cls
        JOIN lessons l ON l.id = cls.lesson_id
        JOIN courses c ON c.id = l.course_id
        WHERE cls.cohort_id = ?
          AND cls.is_selected = 1
        ORDER BY c.sort_order, c.id, l.sort_order, l.external_lesson_id, l.id
    ");
    $stmt->execute(array($cohortId));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($rows) {
        return $rows;
    }

    return cw_get_selected_lessons_for_cohort_legacy($pdo, $cohortId, $programId);
}

function cw_get_selected_lessons_for_cohort_legacy(PDO $pdo, int $cohortId, int $programId): array
{
    $enabledCourseIds = cw_get_enabled_course_ids_for_cohort($pdo, $cohortId, $programId);
    if (!$enabledCourseIds) {
        return array();
    }

    $in = implode(',', array_fill(0, count($enabledCourseIds), '?'));
    $sql = "
        SELECT
            c.id AS course_id,
            c.title AS course_title,
            c.sort_order AS course_sort,
            l.id AS lesson_id,
            l.external_lesson_id,
            l.title AS lesson_title,
            l.sort_order AS lesson_sort,
            1 AS is_selected
        FROM courses c
        JOIN lessons l ON l.course_id = c.id
        WHERE c.id IN ($in)
        ORDER BY c.sort_order, c.id, l.sort_order, l.external_lesson_id, l.id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($enabledCourseIds);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function cw_group_scoped_lessons_by_course(array $rows): array
{
    $courses = array();

    foreach ($rows as $r) {
        $cid = (int)$r['course_id'];
        if (!isset($courses[$cid])) {
            $courses[$cid] = array(
                'course_id' => $cid,
                'course_title' => (string)$r['course_title'],
                'course_sort' => (int)$r['course_sort'],
                'lessons' => array(),
            );
        }

        $courses[$cid]['lessons'][] = array(
            'lesson_id' => (int)$r['lesson_id'],
            'external_lesson_id' => (int)$r['external_lesson_id'],
            'title' => (string)$r['lesson_title'],
            'lesson_sort' => (int)$r['lesson_sort'],
        );
    }

    return $courses;
}

/* =========================================================
 * Cohort schedule settings
 * ========================================================= */

function cw_get_default_schedule_settings(PDO $pdo, array $cohort): array
{
    $cohortStartDate = trim((string)($cohort['start_date'] ?? ''));
    $timezone = trim((string)($cohort['timezone'] ?? 'UTC'));
    if ($timezone === '') {
        $timezone = 'UTC';
    }

    $scheduleStartDate = $cohortStartDate;
    if ($scheduleStartDate !== '') {
        try {
            $dt = new DateTimeImmutable($scheduleStartDate . ' 00:00:00', new DateTimeZone('UTC'));
            $scheduleStartDate = $dt->modify('+1 day')->format('Y-m-d');
        } catch (Throwable $e) {
            $scheduleStartDate = $cohortStartDate;
        }
    }

    // Supports both the original keys and the newer sched_* keys.
    $dailyCap = cw_setting($pdo, 'sched_daily_cap_min', '');
    if ($dailyCap === '') {
        $dailyCap = cw_setting($pdo, 'study_max_minutes_per_day', '120');
    }

    $wpm = cw_setting($pdo, 'sched_wpm', '');
    if ($wpm === '') {
        $wpm = cw_setting($pdo, 'study_wpm', '140');
    }

    $multiplier = cw_setting($pdo, 'sched_multiplier', '');
    if ($multiplier === '') {
        $multiplier = cw_setting($pdo, 'study_factor', '2.5');
    }

    $ptMin = cw_setting($pdo, 'sched_progress_test_min', '');
    if ($ptMin === '') {
        $ptMin = cw_setting($pdo, 'progress_test_minutes', '30');
    }

    return array(
        'schedule_start_date' => $scheduleStartDate,
        'daily_cap_min' => cw_clamp_int($dailyCap, 30, 600, 120),
        'allowed_weekdays' => array(1, 2, 3, 4, 5, 6, 7),
        'cutoff_local_time' => '23:59',
        'reading_wpm' => cw_clamp_int($wpm, 60, 400, 140),
        'study_multiplier' => cw_clamp_float($multiplier, 1.0, 6.0, 2.5),
        'progress_test_minutes' => cw_clamp_int($ptMin, 0, 180, 30),
        'buffer_min_days' => cw_clamp_int(cw_setting($pdo, 'sched_buffer_min_days', '3'), 0, 365, 3),
        'buffer_pct' => cw_clamp_float(cw_setting($pdo, 'sched_buffer_pct', '0.15'), 0.0, 5.0, 0.15),
        'timezone' => $timezone,
        'source' => 'defaults',
    );
}

function cw_get_cohort_schedule_settings(PDO $pdo, int $cohortId): array
{
    $cohort = cw_get_cohort_row($pdo, $cohortId);
    $defaults = cw_get_default_schedule_settings($pdo, $cohort);

    if (!cw_table_exists($pdo, 'cohort_schedule_settings')) {
        return $defaults;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM cohort_schedule_settings
        WHERE cohort_id = ?
        LIMIT 1
    ");
    $stmt->execute(array($cohortId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return $defaults;
    }

    $settings = $defaults;

    if (isset($row['schedule_start_date'])) {
        $settings['schedule_start_date'] = trim((string)$row['schedule_start_date']) !== ''
            ? (string)$row['schedule_start_date']
            : $defaults['schedule_start_date'];
    }

    if (isset($row['daily_cap_min'])) {
        $settings['daily_cap_min'] = cw_clamp_int($row['daily_cap_min'], 30, 600, $defaults['daily_cap_min']);
    }

    if (isset($row['allowed_weekdays_json'])) {
        $settings['allowed_weekdays'] = cw_parse_allowed_weekdays((string)$row['allowed_weekdays_json']);
    }

    if (isset($row['cutoff_local_time'])) {
        $settings['cutoff_local_time'] = cw_normalize_cutoff_time((string)$row['cutoff_local_time'], $defaults['cutoff_local_time']);
    }

    if (isset($row['reading_wpm'])) {
        $settings['reading_wpm'] = cw_clamp_int($row['reading_wpm'], 60, 400, $defaults['reading_wpm']);
    }

    if (isset($row['study_multiplier'])) {
        $settings['study_multiplier'] = cw_clamp_float($row['study_multiplier'], 1.0, 6.0, $defaults['study_multiplier']);
    }

    if (isset($row['progress_test_minutes'])) {
        $settings['progress_test_minutes'] = cw_clamp_int($row['progress_test_minutes'], 0, 180, $defaults['progress_test_minutes']);
    }

    if (isset($row['buffer_min_days'])) {
        $settings['buffer_min_days'] = cw_clamp_int($row['buffer_min_days'], 0, 365, $defaults['buffer_min_days']);
    }

    if (isset($row['buffer_pct'])) {
        $settings['buffer_pct'] = cw_clamp_float($row['buffer_pct'], 0.0, 5.0, $defaults['buffer_pct']);
    }

    $settings['timezone'] = trim((string)($cohort['timezone'] ?? $defaults['timezone'])) !== ''
        ? (string)$cohort['timezone']
        : $defaults['timezone'];

    $settings['source'] = 'cohort_schedule_settings';

    return $settings;
}

function cw_save_cohort_schedule_settings(PDO $pdo, int $cohortId, array $input, ?int $actorUserId = null): array
{
    $cohort = cw_get_cohort_row($pdo, $cohortId);
    $defaults = cw_get_default_schedule_settings($pdo, $cohort);

    $settings = array(
        'schedule_start_date' => trim((string)($input['schedule_start_date'] ?? $defaults['schedule_start_date'])),
        'daily_cap_min' => cw_clamp_int($input['daily_cap_min'] ?? $defaults['daily_cap_min'], 30, 600, $defaults['daily_cap_min']),
        'allowed_weekdays' => cw_parse_allowed_weekdays($input['allowed_weekdays'] ?? $defaults['allowed_weekdays']),
        'cutoff_local_time' => cw_normalize_cutoff_time((string)($input['cutoff_local_time'] ?? $defaults['cutoff_local_time']), $defaults['cutoff_local_time']),
        'reading_wpm' => cw_clamp_int($input['reading_wpm'] ?? $defaults['reading_wpm'], 60, 400, $defaults['reading_wpm']),
        'study_multiplier' => cw_clamp_float($input['study_multiplier'] ?? $defaults['study_multiplier'], 1.0, 6.0, $defaults['study_multiplier']),
        'progress_test_minutes' => cw_clamp_int($input['progress_test_minutes'] ?? $defaults['progress_test_minutes'], 0, 180, $defaults['progress_test_minutes']),
        'buffer_min_days' => cw_clamp_int($input['buffer_min_days'] ?? $defaults['buffer_min_days'], 0, 365, $defaults['buffer_min_days']),
        'buffer_pct' => cw_clamp_float($input['buffer_pct'] ?? $defaults['buffer_pct'], 0.0, 5.0, $defaults['buffer_pct']),
        'timezone' => trim((string)($cohort['timezone'] ?? 'UTC')) !== '' ? (string)$cohort['timezone'] : 'UTC',
        'source' => 'cohort_schedule_settings',
    );

    if ($settings['schedule_start_date'] === '') {
        $settings['schedule_start_date'] = $defaults['schedule_start_date'];
    }

    if (!cw_table_exists($pdo, 'cohort_schedule_settings')) {
        return $settings;
    }

    $allowedJson = json_encode(array_values($settings['allowed_weekdays']));

    $columns = array(
        'cohort_id' => $cohortId,
        'schedule_start_date' => $settings['schedule_start_date'],
        'daily_cap_min' => $settings['daily_cap_min'],
        'allowed_weekdays_json' => $allowedJson,
        'cutoff_local_time' => $settings['cutoff_local_time'],
        'reading_wpm' => $settings['reading_wpm'],
        'study_multiplier' => $settings['study_multiplier'],
        'progress_test_minutes' => $settings['progress_test_minutes'],
        'buffer_min_days' => $settings['buffer_min_days'],
        'buffer_pct' => $settings['buffer_pct'],
    );

    if (cw_table_has_column($pdo, 'cohort_schedule_settings', 'updated_by_user_id')) {
        $columns['updated_by_user_id'] = $actorUserId;
    }

    $insertCols = array();
    $insertPlaceholders = array();
    $updateAssignments = array();
    $params = array();

    foreach ($columns as $col => $value) {
        $insertCols[] = $col;
        $insertPlaceholders[] = ':' . $col;
        $updateAssignments[] = $col . ' = VALUES(' . $col . ')';
        $params[':' . $col] = $value;
    }

    if (cw_table_has_column($pdo, 'cohort_schedule_settings', 'updated_at')) {
        $updateAssignments[] = 'updated_at = NOW()';
    }

    $sql = "
        INSERT INTO cohort_schedule_settings (" . implode(', ', $insertCols) . ")
        VALUES (" . implode(', ', $insertPlaceholders) . ")
        ON DUPLICATE KEY UPDATE " . implode(', ', $updateAssignments) . "
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $settings;
}

/* =========================================================
 * Preview engine
 * ========================================================= */

function cw_get_existing_deadlines_map(PDO $pdo, int $cohortId): array
{
    $stmt = $pdo->prepare("
        SELECT lesson_id, deadline_utc, sort_order, unlock_after_lesson_id
        FROM cohort_lesson_deadlines
        WHERE cohort_id = ?
    ");
    $stmt->execute(array($cohortId));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = array();
    foreach ($rows as $r) {
        $map[(int)$r['lesson_id']] = $r;
    }

    return $map;
}

function cw_build_deadline_utc_from_local_day_and_cutoff(string $ymd, string $cutoffLocalTime, string $timezone): string
{
    $tzName = trim($timezone) !== '' ? $timezone : 'UTC';
    $timeText = cw_normalize_cutoff_time($cutoffLocalTime, '23:59');

    try {
        $local = new DateTimeImmutable($ymd . ' ' . $timeText . ':00', new DateTimeZone($tzName));
        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return $ymd . ' 23:59:00';
    }
}

function cw_format_deadline_delta_label(string $oldUtc, string $newUtc): string
{
    $oldUtc = trim($oldUtc);
    $newUtc = trim($newUtc);

    if ($oldUtc === '' && $newUtc === '') {
        return '—';
    }

    if ($oldUtc === '' && $newUtc !== '') {
        return 'New';
    }

    if ($oldUtc !== '' && $newUtc === '') {
        return 'Removed';
    }

    $oldTs = strtotime($oldUtc);
    $newTs = strtotime($newUtc);

    if ($oldTs === false || $newTs === false) {
        return 'Changed';
    }

    $deltaSeconds = $newTs - $oldTs;
    if ($deltaSeconds === 0) {
        return 'No change';
    }

    $abs = abs((int)$deltaSeconds);
    $days = (int)floor($abs / 86400);
    $hours = (int)floor(($abs % 86400) / 3600);
    $sign = $deltaSeconds > 0 ? '+' : '-';

    if ($days > 0) {
        return $sign . $days . 'd ' . $hours . 'h';
    }

    if ($hours > 0) {
        return $sign . $hours . 'h';
    }

    $minutes = (int)floor(($abs % 3600) / 60);
    return $sign . $minutes . 'm';
}


function cw_generate_cohort_schedule_preview(PDO $pdo, int $cohortId, array $overrideSettings = array()): array
{
    $cohort = cw_get_cohort_row($pdo, $cohortId);

    $programId = (int)($cohort['program_id'] ?? 0);
    if ($programId <= 0) {
        throw new RuntimeException('Cohort program_id is missing.');
    }

    $settings = cw_get_cohort_schedule_settings($pdo, $cohortId);

    foreach ($overrideSettings as $k => $v) {
        if ($k === 'allowed_weekdays') {
            $settings[$k] = cw_parse_allowed_weekdays($v);
        } elseif ($k === 'cutoff_local_time') {
            $settings[$k] = cw_normalize_cutoff_time((string)$v, (string)$settings['cutoff_local_time']);
        } elseif ($k === 'daily_cap_min') {
            $settings[$k] = cw_clamp_int($v, 30, 600, (int)$settings['daily_cap_min']);
        } elseif ($k === 'reading_wpm') {
            $settings[$k] = cw_clamp_int($v, 60, 400, (int)$settings['reading_wpm']);
        } elseif ($k === 'study_multiplier') {
            $settings[$k] = cw_clamp_float($v, 1.0, 6.0, (float)$settings['study_multiplier']);
        } elseif ($k === 'progress_test_minutes') {
            $settings[$k] = cw_clamp_int($v, 0, 180, (int)$settings['progress_test_minutes']);
        } elseif ($k === 'buffer_min_days') {
            $settings[$k] = cw_clamp_int($v, 0, 365, (int)$settings['buffer_min_days']);
        } elseif ($k === 'buffer_pct') {
            $settings[$k] = cw_clamp_float($v, 0.0, 5.0, (float)$settings['buffer_pct']);
        } elseif ($k === 'schedule_start_date') {
            $val = trim((string)$v);
            if ($val !== '') {
                $settings[$k] = $val;
            }
        }
    }

    $timezone = trim((string)($cohort['timezone'] ?? $settings['timezone'] ?? 'UTC'));
    if ($timezone === '') {
        $timezone = 'UTC';
    }
    $settings['timezone'] = $timezone;

    $scopedRows = cw_get_selected_lessons_for_cohort($pdo, $cohortId, $programId);
    if (!$scopedRows) {
        throw new RuntimeException('No selected lessons found in cohort scope.');
    }

    $courses = cw_group_scoped_lessons_by_course($scopedRows);
    $existingMap = cw_get_existing_deadlines_map($pdo, $cohortId);

     $cohortStartDate = trim((string)$cohort['start_date']);
    $cohortEndDate = trim((string)$cohort['end_date']);

    $scheduleStartDate = trim((string)$settings['schedule_start_date']);
    if ($scheduleStartDate === '') {
        $scheduleStartDate = $cohortStartDate;
    }

    $maxMinDay = (int)$settings['daily_cap_min'];
    $wpm = (int)$settings['reading_wpm'];
    $factor = (float)$settings['study_multiplier'];
    $ptMin = (int)$settings['progress_test_minutes'];

    $previewLessons = array();
    $previewCourses = array();

    $globalLessonOrder = 0;
    $lastLessonId = null;
    $totalMinutes = 0;

    $warningCodes = array();
    $warningMessages = array();

    $usableDays = cw_build_usable_schedule_days(
    $scheduleStartDate,
    $cohortEndDate,
    (array)$settings['allowed_weekdays'],
    $timezone
);

    if (!$usableDays) {
        if (!$usableDays) {
    $warningCodes[] = 'no_usable_days';
    $warningMessages[] = 'No usable days available based on current weekday selection.';

    return array(
        'cohort' => $cohort,
        'settings' => $settings,
        'summary' => array(
            'lessons_scheduled' => 0,
            'total_study_hours' => '0',
            'usable_days' => 0,
            'recommended_days' => 0,
            'suggested_end_pretty' => '—',
            'suggested_end_delta' => 'No usable days',
            'assumptions' => '',
            'advisory_text' => 'No usable days selected.',
        ),
        'warnings' => array(
            'codes' => $warningCodes,
            'messages' => $warningMessages,
        ),
        'courses' => array(),
        'lessons' => array(),
    );
}
    }

    $flattenedLessons = array();
    foreach ($courses as $cid => $course) {
        foreach ((array)$course['lessons'] as $lesson) {
            $lessonId = (int)$lesson['lesson_id'];
            $lessonMinutes = cw_estimate_lesson_minutes($pdo, $lessonId, $wpm, $factor, $ptMin);
            $totalMinutes += $lessonMinutes;

            $flattenedLessons[] = array(
                'course_id' => (int)$cid,
                'course_title' => (string)$course['course_title'],
                'lesson_id' => $lessonId,
                'external_lesson_id' => (int)$lesson['external_lesson_id'],
                'title' => (string)$lesson['title'],
                'estimated_minutes' => $lessonMinutes,
            );
        }
    }

    $selectedLessonCount = count($flattenedLessons);
    $usableDayCount = count($usableDays);

    $requiredMinutesPerUsableDay = $usableDayCount > 0
        ? (float)$totalMinutes / (float)$usableDayCount
        : 0.0;

    if ($requiredMinutesPerUsableDay > (float)$maxMinDay) {
        $warningCodes[] = 'compressed_schedule';
        $warningMessages[] = 'To fit the selected scope inside the selected period, the schedule requires about '
            . (int)ceil($requiredMinutesPerUsableDay)
            . ' minutes per usable day, which is above the preferred daily cap of '
            . $maxMinDay
            . ' minutes.';
    }

    $lessonIndex = 0;
    foreach ($courses as $cid => $course) {
        $courseLessons = array();
        $courseLastDeadlineUtc = '';

        foreach ((array)$course['lessons'] as $lesson) {
            if (!isset($flattenedLessons[$lessonIndex])) {
                continue;
            }

            $globalLessonOrder++;

            $flatLesson = $flattenedLessons[$lessonIndex];
            $lessonId = (int)$flatLesson['lesson_id'];

            $dayIndex = (int)floor(($lessonIndex * $usableDayCount) / max(1, $selectedLessonCount));
            if ($dayIndex < 0) {
                $dayIndex = 0;
            }
            if ($dayIndex >= $usableDayCount) {
                $dayIndex = $usableDayCount - 1;
            }

            $assignedDayUtc = $usableDays[$dayIndex];
            $deadlineDayYmd = $assignedDayUtc->format('Y-m-d');
            $deadlineUtc = cw_build_deadline_utc_from_local_day_and_cutoff(
                $deadlineDayYmd,
                (string)$settings['cutoff_local_time'],
                $timezone
            );

            $oldDeadlineUtc = isset($existingMap[$lessonId]['deadline_utc']) ? (string)$existingMap[$lessonId]['deadline_utc'] : '';
            $oldLabel = $oldDeadlineUtc !== '' ? cw_fmt_local_deadline_label($oldDeadlineUtc, $timezone, 'D, M j, Y H:i') : '—';
            $newLabel = cw_fmt_local_deadline_label($deadlineUtc, $timezone, 'D, M j, Y H:i');
            $deltaLabel = cw_format_deadline_delta_label($oldDeadlineUtc, $deadlineUtc);

            $localDeadline = new DateTimeImmutable($deadlineUtc, new DateTimeZone('UTC'));
            $localDeadline = $localDeadline->setTimezone(new DateTimeZone($timezone));
            $isWeekendLocal = in_array((int)$localDeadline->format('N'), array(6, 7), true);

            $unlockAfter = $lastLessonId !== null ? (int)$lastLessonId : null;

            $lessonRow = array(
    'course_id' => (int)$cid,
    'course_title' => (string)$course['course_title'],
    'lesson_id' => $lessonId,
    'external_lesson_id' => (int)$flatLesson['external_lesson_id'],
    'title' => (string)$flatLesson['title'],
    'sort_order' => $globalLessonOrder * 10,
    'unlock_after_lesson_id' => $unlockAfter,
    'estimated_minutes' => (int)$flatLesson['estimated_minutes'],

    // 🔥 STANDARDIZED KEYS (MATCH UI)
    'existing_deadline_pretty' => $oldLabel,
    'deadline_pretty' => $newLabel,
    'deadline_delta_label' => $deltaLabel,

    // keep raw values too (optional but useful)
    'old_deadline_utc' => $oldDeadlineUtc,
    'new_deadline_utc' => $deadlineUtc,

    'weekday_local' => $localDeadline->format('D'),
    'is_weekend' => $isWeekendLocal,
    'cutoff_label' => $settings['cutoff_local_time'] . ' ' . $timezone,
);

            $previewLessons[] = $lessonRow;
            $courseLessons[] = $lessonRow;
            $courseLastDeadlineUtc = $deadlineUtc;
            $lastLessonId = $lessonId;
            $lessonIndex++;
        }

		        $existingCourseDeadlineUtc = '';
        foreach ($courseLessons as $courseLessonRow) {
            $candidate = trim((string)($courseLessonRow['old_deadline_utc'] ?? ''));
            if ($candidate !== '') {
                if ($existingCourseDeadlineUtc === '' || strtotime($candidate) > strtotime($existingCourseDeadlineUtc)) {
                    $existingCourseDeadlineUtc = $candidate;
                }
            }
        }

        $existingCourseDeadlinePretty = $existingCourseDeadlineUtc !== ''
            ? cw_fmt_local_deadline_label($existingCourseDeadlineUtc, $timezone, 'D, M j, Y H:i')
            : '—';

        $courseDeadlinePretty = $courseLastDeadlineUtc !== ''
            ? cw_fmt_local_deadline_label($courseLastDeadlineUtc, $timezone, 'D, M j, Y H:i')
            : '—';

        $courseDeadlineDeltaLabel = cw_format_deadline_delta_label($existingCourseDeadlineUtc, $courseLastDeadlineUtc);
		
		
        $previewCourses[] = array(
            'course_id' => (int)$cid,
            'course_title' => (string)$course['course_title'],
            'course_order' => count($previewCourses) + 1,

            'existing_course_deadline_utc' => $existingCourseDeadlineUtc,
			'existing_course_deadline_pretty' => $existingCourseDeadlinePretty,

			'course_deadline_utc' => $courseLastDeadlineUtc,
			'course_deadline_pretty' => $courseDeadlinePretty,

            'course_deadline_delta_label' => $courseDeadlineDeltaLabel,
            'lessons' => $courseLessons,
        );
    }

    $selectedLessonCount = count($previewLessons);
    $recommendedDays = (int)ceil(((float)$totalMinutes) / max(1.0, (float)$maxMinDay));
    $totalHours = cw_minutes_to_hours((float)$totalMinutes);

    $firstDeadlineUtc = $previewLessons ? (string)$previewLessons[0]['new_deadline_utc'] : '';
    $lastDeadlineUtc = $previewLessons ? (string)$previewLessons[count($previewLessons) - 1]['new_deadline_utc'] : '';

    $usableDays = $usableDayCount;
    $fitsWithinCohort = true;
    $daysOverrun = 0;

    if ($selectedLessonCount <= 0) {
        $warningCodes[] = 'empty_scope';
        $warningMessages[] = 'No lessons are currently selected in scope.';
    }

        $summary = array(
        'selected_lessons' => $selectedLessonCount,
        'lessons_scheduled' => $selectedLessonCount,
        'usable_days' => $usableDays,
        'recommended_days' => $recommendedDays,

        'total_study_minutes' => $totalMinutes,
        'total_study_hours' => $totalHours,

        'first_deadline_utc' => $firstDeadlineUtc,
        'first_deadline_pretty' => $firstDeadlineUtc !== '' ? cw_fmt_local_deadline_label($firstDeadlineUtc, $timezone, 'D, M j, Y H:i') : '—',

        'suggested_end_utc' => $lastDeadlineUtc,
        'suggested_end_pretty' => $lastDeadlineUtc !== '' ? cw_fmt_local_deadline_label($lastDeadlineUtc, $timezone, 'D, M j, Y H:i') : '—',

        'fits_within_cohort' => $fitsWithinCohort,
        'days_overrun' => $daysOverrun,
        'suggested_end_delta' => $fitsWithinCohort
            ? 'Within cohort end date'
            : ($daysOverrun > 0 ? ($daysOverrun . ' day(s) beyond cohort end date') : 'Beyond cohort end date'),

        'schedule_start_date' => $settings['schedule_start_date'],
        'cutoff_local_time' => $settings['cutoff_local_time'],
        'timezone' => $timezone,
        'allowed_weekdays' => array_values((array)$settings['allowed_weekdays']),
        'assumptions' => 'Assumptions: Max ' . $maxMinDay . ' min/day, Factor ' . rtrim(rtrim(number_format($factor, 2, '.', ''), '0'), '.') . ', ' . $wpm . ' WPM, PT ' . $ptMin . ' minutes.',
                'advisory_text' => ($requiredMinutesPerUsableDay > (float)$maxMinDay)
            ? 'All selected lessons were fitted inside the selected cohort window, but the plan is compressed. It requires about '
                . (int)ceil($requiredMinutesPerUsableDay)
                . ' minutes per usable day versus the preferred '
                . $maxMinDay
                . ' minutes per day.'
            : 'All selected lessons fit inside the selected cohort window with the current scheduling settings.',
    );

    return array(
        'cohort' => $cohort,
        'settings' => $settings,
        'summary' => $summary,
        'warnings' => array(
            'codes' => $warningCodes,
            'messages' => $warningMessages,
        ),
        'courses' => $previewCourses,
        'lessons' => $previewLessons,
    );
}

/* =========================================================
 * Lightweight schedule versioning
 * ========================================================= */

function cw_create_schedule_preview_version(PDO $pdo, int $cohortId, array $preview, ?int $actorUserId = null): ?int
{
    if (!cw_table_exists($pdo, 'cohort_schedule_versions')) {
        return null;
    }

    $versionNo = 1;
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(MAX(version_no), 0) + 1
            FROM cohort_schedule_versions
            WHERE cohort_id = ?
        ");
        $stmt->execute(array($cohortId));
        $versionNo = (int)$stmt->fetchColumn();
        if ($versionNo <= 0) {
            $versionNo = 1;
        }
    } catch (Throwable $e) {
        $versionNo = 1;
    }

    $scopeSnapshot = array();
    foreach ((array)$preview['lessons'] as $lesson) {
        $scopeSnapshot[] = (int)$lesson['lesson_id'];
    }

    $columns = array(
        'cohort_id' => $cohortId,
        'version_no' => $versionNo,
        'status' => 'preview',
        'settings_snapshot_json' => json_encode((array)$preview['settings']),
        'scope_snapshot_json' => json_encode($scopeSnapshot),
        'summary_json' => json_encode((array)$preview['summary']),
    );

    if (cw_table_has_column($pdo, 'cohort_schedule_versions', 'created_by_user_id')) {
        $columns['created_by_user_id'] = $actorUserId;
    }
    if (cw_table_has_column($pdo, 'cohort_schedule_versions', 'created_at')) {
        // handled with NOW() below if needed
    }

    $insertCols = array();
    $insertVals = array();
    $params = array();

    foreach ($columns as $col => $value) {
        $insertCols[] = $col;
        $insertVals[] = ':' . $col;
        $params[':' . $col] = $value;
    }

    if (cw_table_has_column($pdo, 'cohort_schedule_versions', 'created_at')) {
        $insertCols[] = 'created_at';
        $insertVals[] = 'NOW()';
    }

    $sql = "
        INSERT INTO cohort_schedule_versions (" . implode(', ', $insertCols) . ")
        VALUES (" . implode(', ', $insertVals) . ")
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $versionId = (int)$pdo->lastInsertId();

    if ($versionId > 0 && cw_table_exists($pdo, 'cohort_schedule_version_items')) {
        $ins = $pdo->prepare("
            INSERT INTO cohort_schedule_version_items
                (schedule_version_id, lesson_id, sort_order, unlock_after_lesson_id, deadline_utc, deadline_local_label)
            VALUES
                (?, ?, ?, ?, ?, ?)
        ");

        foreach ((array)$preview['lessons'] as $lesson) {
            $ins->execute(array(
                $versionId,
                (int)$lesson['lesson_id'],
                (int)$lesson['sort_order'],
                $lesson['unlock_after_lesson_id'] !== null ? (int)$lesson['unlock_after_lesson_id'] : null,
                (string)$lesson['new_deadline_utc'],
                (string)$lesson['new_deadline_local_label'],
            ));
        }
    }

    return $versionId;
}

/* =========================================================
 * Publish schedule
 * ========================================================= */

function cw_publish_cohort_schedule(PDO $pdo, int $cohortId, array $overrideSettings = array(), ?int $actorUserId = null): array
{
    $preview = cw_generate_cohort_schedule_preview($pdo, $cohortId, $overrideSettings);

    if ($pdo->inTransaction()) {
        throw new RuntimeException('cw_publish_cohort_schedule must own its transaction.');
    }

    $pdo->beginTransaction();

    try {
        // Save cohort-level schedule settings if the table exists.
        $effectiveSettings = cw_save_cohort_schedule_settings($pdo, $cohortId, (array)$preview['settings'], $actorUserId);

        // Optionally create preview/publish version record.
        $versionId = cw_create_schedule_preview_version($pdo, $cohortId, $preview, $actorUserId);

        if ($versionId !== null && cw_table_exists($pdo, 'cohort_schedule_versions')) {
            $updates = array();
            $params = array(':id' => $versionId);

            if (cw_table_has_column($pdo, 'cohort_schedule_versions', 'status')) {
                $updates[] = "status = 'published'";
            }
            if (cw_table_has_column($pdo, 'cohort_schedule_versions', 'published_at')) {
                $updates[] = "published_at = NOW()";
            }
            if (cw_table_has_column($pdo, 'cohort_schedule_versions', 'published_by_user_id')) {
                $updates[] = "published_by_user_id = :published_by_user_id";
                $params[':published_by_user_id'] = $actorUserId;
            }

            if ($updates) {
                $sql = "UPDATE cohort_schedule_versions SET " . implode(', ', $updates) . " WHERE id = :id LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }
        }

        // Replace published baseline deadlines with the authoritative selected-scope schedule.
        $del = $pdo->prepare("DELETE FROM cohort_lesson_deadlines WHERE cohort_id = ?");
        $del->execute(array($cohortId));

        $ins = $pdo->prepare("
            INSERT INTO cohort_lesson_deadlines
                (cohort_id, lesson_id, sort_order, unlock_after_lesson_id, deadline_utc, created_at)
            VALUES
                (?, ?, ?, ?, ?, NOW())
        ");

        $publishedLessonIds = array();
        foreach ((array)$preview['lessons'] as $lesson) {
            $ins->execute(array(
                $cohortId,
                (int)$lesson['lesson_id'],
                (int)$lesson['sort_order'],
                $lesson['unlock_after_lesson_id'] !== null ? (int)$lesson['unlock_after_lesson_id'] : null,
                (string)$lesson['new_deadline_utc'],
            ));
            $publishedLessonIds[] = (int)$lesson['lesson_id'];
        }

        $pdo->commit();

        return array(
            'ok' => true,
            'cohort_id' => $cohortId,
            'version_id' => $versionId,
            'settings' => $effectiveSettings,
            'summary' => $preview['summary'],
            'warnings' => $preview['warnings'],
            'courses' => $preview['courses'],
            'lessons' => $preview['lessons'],
            'published_lesson_ids' => $publishedLessonIds,
            'published_count' => count($publishedLessonIds),
        );
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}


function cw_recalculate_cohort_deadlines(PDO $pdo, int $cohortId, array $options = array()): array
{
    $previewOnly = !empty($options['preview_only']);
    $overrideSettings = array();

    foreach (array(
        'schedule_start_date',
        'daily_cap_min',
        'allowed_weekdays',
        'cutoff_local_time',
        'reading_wpm',
        'study_multiplier',
        'progress_test_minutes',
        'buffer_min_days',
        'buffer_pct'
    ) as $key) {
        if (array_key_exists($key, $options)) {
            $overrideSettings[$key] = $options[$key];
        }
    }

    if ($previewOnly) {
        $preview = cw_generate_cohort_schedule_preview($pdo, $cohortId, $overrideSettings);

        return array(
            'summary' => $preview['summary'],
            'courses' => $preview['courses'],
            'lessons' => $preview['lessons'],
            'warnings' => $preview['warnings'],
        );
    }

    $published = cw_publish_cohort_schedule($pdo, $cohortId, $overrideSettings);

    return array(
        'summary' => $published['summary'],
        'courses' => $published['courses'],
        'lessons' => $published['lessons'],
        'warnings' => $published['warnings'],
        'published_lesson_ids' => $published['published_lesson_ids'],
        'published_count' => $published['published_count'],
    );
}