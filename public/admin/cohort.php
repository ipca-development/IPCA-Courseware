<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/schedule.php';
 
cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

function cohort_h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function tz_options(): array
{
    return array(
        'UTC' => 'UTC',
        'America/Los_Angeles' => 'California (America/Los_Angeles)',
        'Europe/Brussels' => 'Belgium (Europe/Brussels)',
    );
}

function cohort_programs(PDO $pdo): array
{
    return $pdo->query("
        SELECT id, program_key, sort_order
        FROM programs
        ORDER BY sort_order, id
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function cohort_courses_for_program(PDO $pdo, int $programId): array
{
    $st = $pdo->prepare("
        SELECT
            c.id,
            c.title,
            c.sort_order,
            (
                SELECT COUNT(*)
                FROM lessons l
                WHERE l.course_id = c.id
            ) AS lesson_count
        FROM courses c
        WHERE c.program_id = ?
        ORDER BY c.sort_order, c.id
    ");
    $st->execute(array($programId));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function cohort_lessons_for_program(PDO $pdo, int $programId): array
{
    $st = $pdo->prepare("
        SELECT
            c.id AS course_id,
            c.title AS course_title,
            c.sort_order AS course_sort,
            l.id AS lesson_id,
            l.title AS lesson_title,
            l.external_lesson_id,
            l.sort_order AS lesson_sort
        FROM courses c
        JOIN lessons l
            ON l.course_id = c.id
        WHERE c.program_id = ?
        ORDER BY c.sort_order, c.id, l.sort_order, l.external_lesson_id, l.id
    ");
    $st->execute(array($programId));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function cohort_courses_enabled(PDO $pdo, int $cohortId): array
{
    $st = $pdo->prepare("
        SELECT course_id, is_enabled
        FROM cohort_courses
        WHERE cohort_id = ?
    ");
    $st->execute(array($cohortId));

    $out = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['course_id']] = (int)$r['is_enabled'];
    }

    return $out;
}

function cohort_selected_lessons(PDO $pdo, int $cohortId): array
{
    $st = $pdo->prepare("
        SELECT lesson_id, is_selected
        FROM cohort_lesson_scope
        WHERE cohort_id = ?
    ");
    $st->execute(array($cohortId));

    $out = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['lesson_id']] = (int)$r['is_selected'];
    }

    return $out;
}

function cohort_save_course_selection(PDO $pdo, int $cohortId, int $programId, array $selectedCourseIds): void
{
    $all = cohort_courses_for_program($pdo, $programId);
    $selected = array();
    foreach ($selectedCourseIds as $cid) {
        $selected[(int)$cid] = true;
    }

    $ins = $pdo->prepare("
        INSERT INTO cohort_courses (cohort_id, course_id, is_enabled)
        VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)
    ");

    foreach ($all as $c) {
        $cid = (int)$c['id'];
        $enabled = isset($selected[$cid]) ? 1 : 0;
        $ins->execute(array($cohortId, $cid, $enabled));
    }
}

function cohort_save_lesson_scope(PDO $pdo, int $cohortId, int $programId, array $selectedCourseIds, array $selectedLessonIds): void
{
    $selectedCourses = array();
    foreach ($selectedCourseIds as $cid) {
        $selectedCourses[(int)$cid] = true;
    }

    $selectedLessons = array();
    foreach ($selectedLessonIds as $lid) {
        $selectedLessons[(int)$lid] = true;
    }

    $rows = cohort_lessons_for_program($pdo, $programId);

    $ins = $pdo->prepare("
        INSERT INTO cohort_lesson_scope (cohort_id, lesson_id, is_selected)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            is_selected = VALUES(is_selected),
            updated_at = CURRENT_TIMESTAMP
    ");

    foreach ($rows as $row) {
        $courseId = (int)$row['course_id'];
        $lessonId = (int)$row['lesson_id'];

        $isSelected = 0;
        if (isset($selectedCourses[$courseId]) && isset($selectedLessons[$lessonId])) {
            $isSelected = 1;
        }

        $ins->execute(array($cohortId, $lessonId, $isSelected));
    }
}

function cohort_pick_primary_course(PDO $pdo, int $programId, array $selectedCourseIds): int
{
    $selected = array();
    foreach ($selectedCourseIds as $cid) {
        $selected[(int)$cid] = true;
    }

    $all = cohort_courses_for_program($pdo, $programId);
    foreach ($all as $c) {
        $cid = (int)$c['id'];
        if (isset($selected[$cid])) {
            return $cid;
        }
    }

    if ($all) {
        return (int)$all[0]['id'];
    }

    return 0;
}

function cohort_fmt_pretty(string $ymdOrDt): string
{
    $raw = trim($ymdOrDt);
    if ($raw === '') {
        return '—';
    }

    $d = substr($raw, 0, 10);

    try {
        $dt = new DateTimeImmutable($d . ' 00:00:00', new DateTimeZone('UTC'));
        return $dt->format('D, M j, Y');
    } catch (Throwable $e) {
        return $ymdOrDt;
    }
}

function cohort_initials(string $name, string $email = ''): string
{
    $name = trim($name);
    if ($name !== '') {
        $parts = preg_split('/\s+/', $name) ?: array();
        $initials = '';
        foreach ($parts as $p) {
            $p = trim((string)$p);
            if ($p === '') {
                continue;
            }
            $initials .= mb_strtoupper(mb_substr($p, 0, 1));
            if (mb_strlen($initials) >= 2) {
                break;
            }
        }
        if ($initials !== '') {
            return $initials;
        }
    }

    $email = trim($email);
    if ($email !== '') {
        return mb_strtoupper(mb_substr($email, 0, 2));
    }

    return 'NA';
}

function cohort_avatar_url(string $photoPath): string
{
    $photoPath = trim($photoPath);
    if ($photoPath === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $photoPath)) {
        return $photoPath;
    }

    if ($photoPath[0] !== '/') {
        $photoPath = '/' . $photoPath;
    }

    return $photoPath;
}

function cohort_avatar_html(string $name, string $email, string $photoPath, int $size = 38, bool $withMarginReset = false): string
{
    $label = trim($name) !== '' ? $name : $email;
    $initials = cohort_initials($name, $email);
    $url = cohort_avatar_url($photoPath);

    $style = 'width:' . $size . 'px;height:' . $size . 'px;';
    if ($withMarginReset) {
        $style .= 'margin-left:0;';
    }

    if ($url !== '') {
        return ''
            . '<span class="cohort-avatar" style="' . cohort_h($style) . '" title="' . cohort_h($label) . '">'
            . '<img src="' . cohort_h($url) . '" alt="' . cohort_h($label) . '" loading="lazy">'
            . '</span>';
    }

    return ''
        . '<span class="cohort-avatar cohort-avatar--fallback" style="' . cohort_h($style) . '" title="' . cohort_h($label) . '">'
        . cohort_h($initials)
        . '</span>';
}


function cohort_percent(int $numerator, int $denominator): int
{
    if ($denominator <= 0) {
        return 0;
    }

    $pct = (int)floor(($numerator / $denominator) * 100);
    if ($pct < 0) {
        $pct = 0;
    }
    if ($pct > 100) {
        $pct = 100;
    }
    return $pct;
}

function cohort_deadline_preview_label(?string $utc, string $timezone = 'UTC'): string
{
    $raw = trim((string)$utc);
    if ($raw === '') {
        return '—';
    }

    try {
        $tz = new DateTimeZone($timezone !== '' ? $timezone : 'UTC');
    } catch (Throwable $e) {
        $tz = new DateTimeZone('UTC');
    }

    try {
        $dt = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        $local = $dt->setTimezone($tz);
        return $local->format('D, M j, Y H:i');
    } catch (Throwable $e) {
        return $raw;
    }
}


function cohort_days_between(?string $startDate, ?string $endDate): int
{
    $start = trim((string)$startDate);
    $end = trim((string)$endDate);

    if ($start === '' || $end === '') {
        return 0;
    }

    try {
        $a = new DateTimeImmutable(substr($start, 0, 10) . ' 00:00:00', new DateTimeZone('UTC'));
        $b = new DateTimeImmutable(substr($end, 0, 10) . ' 00:00:00', new DateTimeZone('UTC'));
        return max(0, (int)$a->diff($b)->format('%a') + 1);
    } catch (Throwable $e) {
        return 0;
    }
}

function cohort_setting(PDO $pdo, string $key, string $default = ''): string
{
    static $cache = array();

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $st = $pdo->prepare("SELECT v FROM app_settings WHERE k = ? LIMIT 1");
    $st->execute(array($key));
    $val = $st->fetchColumn();

    $cache[$key] = ($val !== false && $val !== null) ? (string)$val : $default;
    return $cache[$key];
}

function cohort_build_course_lesson_tree(PDO $pdo, int $programId, array $enabledMap, array $selectedLessonMap): array
{
    $rows = cohort_lessons_for_program($pdo, $programId);
    $tree = array();

    foreach ($rows as $row) {
        $courseId = (int)$row['course_id'];

        if (!isset($tree[$courseId])) {
            $tree[$courseId] = array(
                'course_id' => $courseId,
                'course_title' => (string)$row['course_title'],
                'course_sort' => (int)$row['course_sort'],
                'is_enabled' => ((int)($enabledMap[$courseId] ?? 1) === 1),
                'lessons' => array(),
                'total_lessons' => 0,
                'selected_lessons' => 0,
            );
        }

        $lessonId = (int)$row['lesson_id'];
        $courseEnabled = ((int)($enabledMap[$courseId] ?? 1) === 1);

        $isSelected = false;
        if (array_key_exists($lessonId, $selectedLessonMap)) {
            $isSelected = ((int)$selectedLessonMap[$lessonId] === 1);
        } else {
            $isSelected = $courseEnabled;
        }

        $tree[$courseId]['lessons'][] = array(
            'lesson_id' => $lessonId,
            'lesson_title' => (string)$row['lesson_title'],
            'external_lesson_id' => (int)$row['external_lesson_id'],
            'lesson_sort' => (int)$row['lesson_sort'],
            'is_selected' => $isSelected,
        );

        $tree[$courseId]['total_lessons']++;
        if ($isSelected) {
            $tree[$courseId]['selected_lessons']++;
        }
    }

    return $tree;
}


function cohort_weekday_labels(): array
{
    return array(
        1 => 'Mon',
        2 => 'Tue',
        3 => 'Wed',
        4 => 'Thu',
        5 => 'Fri',
        6 => 'Sat',
        7 => 'Sun',
    );
}

function cohort_schedule_setting_value(array $settings, string $key, string $default = ''): string
{
    return isset($settings[$key]) ? (string)$settings[$key] : $default;
}

function cohort_schedule_setting_int(array $settings, string $key, int $default = 0): int
{
    return isset($settings[$key]) ? (int)$settings[$key] : $default;
}

function cohort_schedule_setting_float(array $settings, string $key, float $default = 0.0): string
{
    if (!isset($settings[$key])) {
        return (string)$default;
    }
    $value = (float)$settings[$key];
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}

$cohortId = (int)($_GET['cohort_id'] ?? 0);
if ($cohortId <= 0) {
    $cohortId = (int)($_GET['id'] ?? 0);
}
if ($cohortId <= 0) {
    exit('Missing id');
}

$msg = '';
$scheduleSummary = array();
$scheduleCourses = array();
$scheduleAdvice = array();
$schedulePreview = null;

$programs = cohort_programs($pdo);

$stmt = $pdo->prepare("
    SELECT co.*, p.program_key
    FROM cohorts co
    LEFT JOIN programs p ON p.id = co.program_id
    WHERE co.id = ?
    LIMIT 1
");
$stmt->execute(array($cohortId));
$cohort = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cohort) {
    exit('Cohort not found');
}

$programId = (int)($cohort['program_id'] ?? 0);
$enabledMap = cohort_courses_enabled($pdo, $cohortId);
$selectedLessonMap = cohort_selected_lessons($pdo, $cohortId);
$courses = ($programId > 0) ? cohort_courses_for_program($pdo, $programId) : array();
$courseLessonTree = ($programId > 0)
    ? cohort_build_course_lesson_tree($pdo, $programId, $enabledMap, $selectedLessonMap)
    : array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_cohort') {
        $programId = (int)($_POST['program_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $start = trim((string)($_POST['start_date'] ?? ''));
        $end = trim((string)($_POST['end_date'] ?? ''));
        $tz = trim((string)($_POST['timezone'] ?? 'UTC'));

        if ($programId <= 0 || $name === '' || $start === '' || $end === '') {
            $msg = 'Missing required fields.';
        } else {
            $firstCourse = $pdo->prepare("
                SELECT id
                FROM courses
                WHERE program_id = ?
                ORDER BY sort_order, id
                LIMIT 1
            ");
            $firstCourse->execute(array($programId));
            $fallbackCourseId = (int)($firstCourse->fetchColumn() ?: 0);

            if ($fallbackCourseId <= 0) {
                $msg = 'No courses exist for this program yet.';
            } else {
                $up = $pdo->prepare("
                    UPDATE cohorts
                    SET program_id = ?, name = ?, start_date = ?, end_date = ?, timezone = ?, course_id = ?
                    WHERE id = ?
                ");
                $up->execute(array($programId, $name, $start, $end, $tz, $fallbackCourseId, $cohortId));

                header('Location: /admin/cohort.php?cohort_id=' . $cohortId);
                exit;
            }
        }
    }

    if ($action === 'save_scope') {
        $programId = (int)($_POST['program_id'] ?? 0);

        $courseIds = $_POST['course_ids'] ?? array();
        if (!is_array($courseIds)) {
            $courseIds = array();
        }
        $courseIds = array_values(array_unique(array_map('intval', $courseIds)));

        $lessonIds = $_POST['lesson_ids'] ?? array();
        if (!is_array($lessonIds)) {
            $lessonIds = array();
        }
        $lessonIds = array_values(array_unique(array_map('intval', $lessonIds)));

        if ($programId <= 0) {
            $msg = 'Missing program.';
        } else {
            cohort_save_course_selection($pdo, $cohortId, $programId, $courseIds);
            cohort_save_lesson_scope($pdo, $cohortId, $programId, $courseIds, $lessonIds);

            $primary = cohort_pick_primary_course($pdo, $programId, $courseIds);
            if ($primary > 0) {
                $pdo->prepare("UPDATE cohorts SET course_id = ? WHERE id = ?")->execute(array($primary, $cohortId));
            }

            header('Location: /admin/cohort.php?cohort_id=' . $cohortId . '#courses');
            exit;
        }
    }

	    if ($action === 'save_schedule_settings') {
        try {
            $allowedWeekdays = $_POST['allowed_weekdays'] ?? array();
            if (!is_array($allowedWeekdays)) {
                $allowedWeekdays = array();
            }

            $savedSettings = cw_save_cohort_schedule_settings($pdo, $cohortId, array(
                'schedule_start_date' => trim((string)($_POST['schedule_start_date'] ?? '')),
                'daily_cap_min' => (int)($_POST['daily_cap_min'] ?? 120),
                'allowed_weekdays' => array_values(array_map('intval', $allowedWeekdays)),
                'cutoff_local_time' => trim((string)($_POST['cutoff_local_time'] ?? '23:59')),
                'reading_wpm' => (int)($_POST['reading_wpm'] ?? 140),
                'study_multiplier' => (float)($_POST['study_multiplier'] ?? 2.5),
                'progress_test_minutes' => (int)($_POST['progress_test_minutes'] ?? 30),
                'buffer_min_days' => (int)($_POST['buffer_min_days'] ?? 3),
                'buffer_pct' => (float)($_POST['buffer_pct'] ?? 0.15),
            ), (int)($u['id'] ?? 0));

            header('Location: /admin/cohort.php?cohort_id=' . $cohortId . '#schedule-settings');
            exit;
        } catch (Throwable $e) {
            $msg = 'Schedule settings error: ' . $e->getMessage();
        }
    }
	
	
        if ($action === 'preview_schedule') {
        try {
            $schedulePreview = cw_generate_cohort_schedule_preview($pdo, $cohortId);
            $scheduleSummary = $schedulePreview['summary'] ?? array();
            $scheduleCourses = $schedulePreview['courses'] ?? array();
            $msg = 'Schedule preview generated. No live deadlines were changed.';
        } catch (Throwable $e) {
            $msg = 'Schedule preview error: ' . $e->getMessage();
        }
    }

        if ($action === 'publish_schedule') {
        try {
            $out = cw_publish_cohort_schedule($pdo, $cohortId, array(), (int)($u['id'] ?? 0));
            $scheduleSummary = $out['summary'] ?? array();
            $scheduleCourses = $out['courses'] ?? array();
            $msg = 'Schedule published.';
        } catch (Throwable $e) {
            $msg = 'Schedule publish error: ' . $e->getMessage();
        }
    }
}

$stmt->execute(array($cohortId));
$cohort = $stmt->fetch(PDO::FETCH_ASSOC);
$programId = (int)($cohort['program_id'] ?? 0);
$enabledMap = cohort_courses_enabled($pdo, $cohortId);
$selectedLessonMap = cohort_selected_lessons($pdo, $cohortId);
$courses = ($programId > 0) ? cohort_courses_for_program($pdo, $programId) : array();
$courseLessonTree = ($programId > 0)
    ? cohort_build_course_lesson_tree($pdo, $programId, $enabledMap, $selectedLessonMap)
    : array();

$studentsStmt = $pdo->prepare("
    SELECT
        cs.user_id,
        cs.status,
        cs.enrolled_at,
        u.email,
        u.name,
        u.role,
        u.photo_path
    FROM cohort_students cs
    JOIN users u ON u.id = cs.user_id
    WHERE cs.cohort_id = ?
    ORDER BY cs.enrolled_at ASC, cs.user_id ASC
");
$studentsStmt->execute(array($cohortId));
$students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

$studentCount = 0;
$studentPreview = array();
foreach ($students as $s) {
    if ((string)($s['status'] ?? '') === 'active') {
        $studentCount++;
        if (count($studentPreview) < 6) {
            $studentPreview[] = $s;
        }
    }
}

$schedRowsStmt = $pdo->prepare("
    SELECT
        d.sort_order,
        d.deadline_utc,
        d.lesson_id,
        l.external_lesson_id,
        l.title AS lesson_title,
        c.id AS course_id,
        c.title AS course_title,
        c.sort_order AS course_sort
    FROM cohort_lesson_deadlines d
    JOIN lessons l ON l.id = d.lesson_id
    JOIN courses c ON c.id = l.course_id
    WHERE d.cohort_id = ?
    ORDER BY c.sort_order, c.id, d.sort_order, l.external_lesson_id
");
$schedRowsStmt->execute(array($cohortId));
$schedRows = $schedRowsStmt->fetchAll(PDO::FETCH_ASSOC);

$courseBlocks = array();
$firstDeadlineUtc = '';
$lastDeadlineUtc = '';
foreach ($schedRows as $r) {
    $cid = (int)$r['course_id'];

    if ($firstDeadlineUtc === '') {
        $firstDeadlineUtc = (string)$r['deadline_utc'];
    }
    $lastDeadlineUtc = (string)$r['deadline_utc'];

    if (!isset($courseBlocks[$cid])) {
        $courseBlocks[$cid] = array(
            'course_id' => $cid,
            'course_title' => (string)$r['course_title'],
            'lessons' => array(),
            'course_deadline_utc' => (string)$r['deadline_utc'],
        );
    }

    $courseBlocks[$cid]['lessons'][] = array(
        'sort_order' => (int)$r['sort_order'],
        'external_lesson_id' => (int)$r['external_lesson_id'],
        'lesson_title' => (string)$r['lesson_title'],
        'deadline_utc' => (string)$r['deadline_utc'],
        'lesson_id' => (int)$r['lesson_id'],
    );

    $courseBlocks[$cid]['course_deadline_utc'] = (string)$r['deadline_utc'];
}

$enabledCourseCount = 0;
$totalSelectedLessonCount = 0;
$totalProgramLessonCount = 0;

foreach ($courseLessonTree as $courseTreeRow) {
    $totalProgramLessonCount += (int)$courseTreeRow['total_lessons'];
    if (!empty($courseTreeRow['is_enabled'])) {
        $enabledCourseCount++;
    }
    $totalSelectedLessonCount += (int)$courseTreeRow['selected_lessons'];
}

$publishedLessonCount = count($schedRows);
$publishedCoverageLessonCount = min($publishedLessonCount, $totalSelectedLessonCount);
$scopePct = cohort_percent($totalSelectedLessonCount, $totalProgramLessonCount);
$schedulePct = $scopePct;
$publishedScopeMismatch = ($publishedLessonCount !== $totalSelectedLessonCount);

$durationDays = cohort_days_between((string)$cohort['start_date'], (string)$cohort['end_date']);

$scheduleSettings = cw_get_cohort_schedule_settings($pdo, $cohortId);

$settingsSnapshot = array(
    'schedule_start_date' => (string)($scheduleSettings['schedule_start_date'] ?? ''),
    'daily_cap_min' => (string)($scheduleSettings['daily_cap_min'] ?? '120'),
    'wpm' => (string)($scheduleSettings['reading_wpm'] ?? '140'),
    'multiplier' => (string)($scheduleSettings['study_multiplier'] ?? '2.5'),
    'progress_test_min' => (string)($scheduleSettings['progress_test_minutes'] ?? '30'),
    'buffer_min_days' => (string)($scheduleSettings['buffer_min_days'] ?? '3'),
    'buffer_pct' => (string)($scheduleSettings['buffer_pct'] ?? '0.15'),
    'cutoff_time_local' => (string)($scheduleSettings['cutoff_local_time'] ?? '23:59'),
    'allowed_weekdays' => (array)($scheduleSettings['allowed_weekdays'] ?? array(1,2,3,4,5,6,7)),
);

$scheduleVersionRows = array();
if (cw_table_exists($pdo, 'cohort_schedule_versions')) {
    $versionSql = "
        SELECT *
        FROM cohort_schedule_versions
        WHERE cohort_id = ?
        ORDER BY id DESC
        LIMIT 12
    ";
    $versionStmt = $pdo->prepare($versionSql);
    $versionStmt->execute(array($cohortId));
    $scheduleVersionRows = $versionStmt->fetchAll(PDO::FETCH_ASSOC);
}

cw_header('Theory Training');
?>
<style>
.cohort-page{display:flex;flex-direction:column;gap:18px}
.cohort-hero{padding:22px 24px}
.cohort-eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.14em;color:#64748b;font-weight:800;margin-bottom:8px}
.cohort-title{margin:0;font-size:32px;line-height:1.05;letter-spacing:-.04em;color:#102845}
.cohort-sub{margin-top:10px;font-size:14px;line-height:1.6;color:#56677f;max-width:980px}
.cohort-alert{padding:14px 16px;border-radius:14px;background:rgba(18,53,95,.07);color:#12355f;border:1px solid rgba(18,53,95,.12);font-size:14px;font-weight:700}
.cohort-top-actions{display:flex;gap:10px;flex-wrap:wrap}
.cohort-btn{
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    min-height:40px;padding:0 14px;border-radius:12px;border:1px solid #12355f;
    background:#12355f;color:#fff;text-decoration:none;font-size:13px;font-weight:800;cursor:pointer;
}
.cohort-btn:hover{opacity:.96}
.cohort-btn-secondary{background:#fff;color:#12355f;border-color:rgba(18,53,95,.16)}
.cohort-grid-main{display:grid;grid-template-columns:1.2fr .8fr;gap:18px}
.cohort-grid-two{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.cohort-card-pad{padding:20px 22px}
.cohort-section-title{margin:0 0 8px 0;font-size:20px;font-weight:800;color:#102845}
.cohort-section-sub{margin:0 0 14px 0;font-size:13px;line-height:1.6;color:#64748b}
.cohort-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.cohort-field label{display:block;font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#64748b;margin-bottom:6px}
.cohort-input,.cohort-select{
    width:100%;box-sizing:border-box;border:1px solid rgba(15,23,42,.12);
    border-radius:12px;padding:11px 12px;background:#fff;color:#102845;font:inherit;
}
.cohort-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.cohort-kpi{
    border:1px solid rgba(15,23,42,.06);border-radius:16px;background:#f8fafc;padding:14px 14px 12px 14px;
}
.cohort-kpi-label{font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#64748b;margin-bottom:6px}
.cohort-kpi-value{font-size:24px;font-weight:800;letter-spacing:-.04em;color:#102845}
.cohort-kpi-sub{margin-top:5px;font-size:12px;line-height:1.45;color:#64748b}
.cohort-progress-wrap{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;align-items:center;margin-top:14px}
.cohort-progress-label{font-size:12px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#64748b;margin-bottom:8px}
.cohort-progress-bar{width:100%;height:12px;border-radius:999px;background:#e2e8f0;overflow:hidden}
.cohort-progress-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,#12355f 0%, #1f5e99 55%, #38bdf8 100%)}
.cohort-progress-sub{margin-top:8px;font-size:12px;color:#64748b}
.cohort-avatar-row{display:flex;align-items:center;gap:0;margin-bottom:10px}
.cohort-avatar{
    width:38px;height:38px;border-radius:999px;display:flex;align-items:center;justify-content:center;
    font-size:12px;font-weight:800;color:#fff;background:linear-gradient(135deg,#12355f,#2767aa);
    border:2px solid #fff;box-shadow:0 2px 8px rgba(15,23,42,.12);margin-left:-8px;
    overflow:hidden;flex:0 0 auto;
}
.cohort-avatar img{width:100%;height:100%;object-fit:cover;display:block}
.cohort-avatar--fallback{text-transform:uppercase}
.cohort-avatar:first-child{margin-left:0}
.cohort-avatar-more{background:linear-gradient(135deg,#64748b,#475569)}
.cohort-student-list{display:grid;gap:10px}
.cohort-student-item{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    border:1px solid rgba(15,23,42,.06);border-radius:14px;padding:12px 12px;background:#f8fafc;
}
.cohort-student-main{display:flex;align-items:center;gap:12px;min-width:0}
.cohort-student-copy{min-width:0}
.cohort-student-name{font-size:14px;font-weight:800;color:#102845;line-height:1.2}
.cohort-student-meta{font-size:12px;color:#64748b;line-height:1.5}
.cohort-chip{
    display:inline-flex;align-items:center;padding:6px 9px;border-radius:999px;
    background:rgba(18,53,95,.08);color:#12355f;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;
}
.cohort-course-grid{display:grid;gap:12px}
.cohort-scope-card{
    border:1px solid rgba(15,23,42,.06);border-radius:16px;background:#f8fafc;padding:14px;
}
.cohort-scope-head{
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:flex-start;
    flex-wrap:nowrap;
}
.cohort-scope-head > div:first-child{
    flex:1 1 auto;
    min-width:0;
}
.cohort-scope-head > div:last-child{
    margin-left:auto;
    flex:0 0 auto;
    align-self:flex-start;
}
.cohort-chip-disabled{
    background:#e5e7eb;
    color:#64748b;
}
.cohort-scope-title{font-size:15px;font-weight:800;color:#102845}
.cohort-scope-meta{font-size:12px;color:#64748b;margin-top:4px}
.cohort-scope-lessons{display:grid;gap:8px;margin-top:12px}
.cohort-scope-lesson{
    display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:12px;background:#fff;border:1px solid rgba(15,23,42,.05);
}
.cohort-scope-lesson-copy{font-size:13px;color:#334155}
.cohort-schedule-head{
    display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;
}
.cohort-schedule-summary{
    display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:14px;
}
.cohort-summary-box{
    border:1px solid rgba(15,23,42,.06);border-radius:14px;background:#f8fafc;padding:12px 12px;
}
.cohort-summary-label{font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#64748b;margin-bottom:6px}
.cohort-summary-value{font-size:16px;font-weight:800;color:#102845}
.cohort-table-wrap{margin-top:14px;overflow:auto}
.cohort-table{width:100%;border-collapse:collapse}
.cohort-table th,.cohort-table td{padding:12px 10px;border-bottom:1px solid rgba(15,23,42,.06);vertical-align:top}
.cohort-table th{text-align:left;font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#64748b}
.cohort-course-details {
    width: 100%;
}

.cohort-course-details summary {
    cursor: pointer;
    list-style: none;

    display: flex;
    align-items: center;
    justify-content: space-between;

    font-weight: 800;
    color: #102845;

    padding: 10px 12px;
    border-radius: 10px;
    background: #f1f5f9;

    transition: background 0.15s ease;
}

.cohort-course-details summary:hover {
    background: #e2e8f0;
}

/* remove default arrow */
.cohort-course-details summary::-webkit-details-marker {
    display: none;
}

/* custom arrow */
.cohort-course-details summary::after {
    content: '▸';
    font-size: 12px;
    margin-left: 10px;
    transition: transform 0.15s ease;
}

.cohort-course-details[open] summary::after {
    transform: rotate(90deg);
}

/* spacing when open */
.cohort-course-details[open] summary {
    margin-bottom: 10px;
}
.cohort-modal-btn{border:0;background:none;color:#12355f;font-size:12px;font-weight:800;cursor:pointer;padding:0}
.cohort-policy-list{display:grid;gap:8px}
.cohort-policy-item{
    display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid rgba(15,23,42,.06);
}
.cohort-policy-item:last-child{border-bottom:0}
.cohort-policy-label{font-size:13px;font-weight:700;color:#334155}
.cohort-policy-value{font-size:13px;font-weight:800;color:#102845;text-align:right}
.cohort-muted{font-size:12px;color:#64748b;line-height:1.55}
@media (max-width: 1180px){
    .cohort-grid-main,.cohort-grid-two{grid-template-columns:1fr}
}
@media (max-width: 900px){
    .cohort-form-grid,.cohort-kpis,.cohort-schedule-summary{grid-template-columns:1fr 1fr}
}
@media (max-width: 700px){
    .cohort-form-grid,.cohort-kpis,.cohort-schedule-summary{grid-template-columns:1fr}
    .cohort-progress-wrap{grid-template-columns:1fr}
}
</style>

<div class="cohort-page">

    <?php if ($msg !== ''): ?>
        <div class="cohort-alert"><?php echo cohort_h($msg); ?></div>
    <?php endif; ?>

    <section class="card cohort-hero">
        <div class="cohort-eyebrow">Admin · Theory Training</div>
        <h1 class="cohort-title"><?php echo cohort_h((string)$cohort['name']); ?></h1>
        <div class="cohort-sub">
            Cohort control page for program assignment, course inclusion, lesson scope, schedule preview, and publication.
            Scheduling rules are informative only on this page. Final cohort scope and published schedule remain an explicit admin decision.
        </div>

        <div class="cohort-top-actions" style="margin-top:16px;">
            <a class="cohort-btn cohort-btn-secondary" href="/admin/cohorts.php">← Back to cohorts</a>
            <a class="cohort-btn cohort-btn-secondary" href="/admin/cohort_students.php?cohort_id=<?php echo (int)$cohortId; ?>">Manage students</a>
            <a class="cohort-btn cohort-btn-secondary" href="#courses">Courses & lessons</a>
            <a class="cohort-btn cohort-btn-secondary" href="#schedule">Schedule</a>
        </div>
    </section>

     <section class="card cohort-card-pad" id="schedule-settings">
        <div class="cohort-kpis">
            <div class="cohort-kpi">
                <div class="cohort-kpi-label">Program</div>
                <div class="cohort-kpi-value"><?php echo cohort_h((string)($cohort['program_key'] ?? '—')); ?></div>
                <div class="cohort-kpi-sub">Current cohort program binding.</div>
            </div>

            <div class="cohort-kpi">
                <div class="cohort-kpi-label">Students</div>
                <div class="cohort-kpi-value"><?php echo (int)$studentCount; ?></div>
                <div class="cohort-kpi-sub">Active students currently assigned.</div>
            </div>

            <div class="cohort-kpi">
                <div class="cohort-kpi-label">Lessons in Scope</div>
                <div class="cohort-kpi-value"><?php echo (int)$totalSelectedLessonCount; ?></div>
                <div class="cohort-kpi-sub">
                    <?php echo (int)$enabledCourseCount; ?> enabled courses · <?php echo (int)$scopePct; ?>% of program content selected.
                </div>
            </div>

            <div class="cohort-kpi">
                <div class="cohort-kpi-label">Cohort Span</div>
                <div class="cohort-kpi-value"><?php echo (int)$durationDays; ?>d</div>
                <div class="cohort-kpi-sub">
                    <?php echo cohort_h(cohort_fmt_pretty((string)$cohort['start_date'])); ?>
                    → <?php echo cohort_h(cohort_fmt_pretty((string)$cohort['end_date'])); ?>
                </div>
            </div>
        </div>

 <div class="cohort-progress-wrap">
            <div>
                <div class="cohort-progress-label">Program Content Selected</div>
                <div class="cohort-progress-bar">
                    <div class="cohort-progress-fill" style="width: <?php echo (int)$scopePct; ?>%;"></div>
                </div>
                <div class="cohort-progress-sub">
                    <?php echo (int)$totalSelectedLessonCount; ?> selected lessons out of <?php echo (int)$totalProgramLessonCount; ?> total program lessons.
                    <?php if ($publishedScopeMismatch): ?>
                        <br><span style="color:#a16207;font-weight:700;">Published schedule still does not fully match the current selected scope. Preview and publish again after scope changes.</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="cohort-chip"><?php echo (int)$scopePct; ?>%</div>
        </div>
    </section>
	
	    <div class="cohort-grid-main">
        <section class="card cohort-card-pad">
            <h2 class="cohort-section-title">Cohort settings</h2>
            <p class="cohort-section-sub">
                Update the administrative cohort binding and date range. Scope and schedule are managed separately below.
            </p>

            <form method="post">
                <input type="hidden" name="action" value="save_cohort">

                <div class="cohort-form-grid">
                    <div class="cohort-field">
                        <label>Program</label>
                        <select class="cohort-select" name="program_id" required>
                            <?php foreach ($programs as $p): ?>
                                <option
                                    value="<?php echo (int)$p['id']; ?>"
                                    <?php echo ((int)$p['id'] === (int)$cohort['program_id']) ? 'selected' : ''; ?>
                                >
                                    <?php echo cohort_h((string)$p['program_key']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="cohort-field">
                        <label>Cohort name</label>
                        <input class="cohort-input" name="name" required value="<?php echo cohort_h((string)$cohort['name']); ?>">
                    </div>

                    <div class="cohort-field">
                        <label>Start date</label>
                        <input class="cohort-input" name="start_date" type="date" required value="<?php echo cohort_h((string)$cohort['start_date']); ?>">
                    </div>

                    <div class="cohort-field">
                        <label>End date</label>
                        <input class="cohort-input" name="end_date" type="date" required value="<?php echo cohort_h((string)$cohort['end_date']); ?>">
                    </div>

                    <div class="cohort-field">
                        <label>Timezone</label>
                        <select class="cohort-select" name="timezone" required>
                            <?php foreach (tz_options() as $k => $lbl): ?>
                                <option
                                    value="<?php echo cohort_h($k); ?>"
                                    <?php echo ((string)$cohort['timezone'] === $k) ? 'selected' : ''; ?>
                                >
                                    <?php echo cohort_h($lbl); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="cohort-field">
                        <label>Published baseline window</label>
                        <input
                            class="cohort-input"
                            type="text"
                            readonly
                            value="<?php echo cohort_h(($firstDeadlineUtc !== '' ? cohort_fmt_pretty((string)$firstDeadlineUtc) : '—') . ' → ' . ($lastDeadlineUtc !== '' ? cohort_fmt_pretty((string)$lastDeadlineUtc) : '—')); ?>"
                        >
                    </div>
                </div>

                <div style="margin-top:14px;">
                    <button class="cohort-btn" type="submit">Save settings</button>
                </div>
            </form>
        </section>

        <section class="card cohort-card-pad">
            <h2 class="cohort-section-title">Students snapshot</h2>
            <p class="cohort-section-sub">
                Quick cohort roster preview with avatars. Use the dedicated student page for enrollment management and full roster actions.
            </p>

            <div class="cohort-avatar-row">
                <?php if ($studentPreview): ?>
                    <?php foreach ($studentPreview as $s): ?>
                        <?php
                        echo cohort_avatar_html(
                            (string)($s['name'] ?? ''),
                            (string)($s['email'] ?? ''),
                            (string)($s['photo_path'] ?? ''),
                            38,
                            false
                        );
                        ?>
                    <?php endforeach; ?>
                    <?php if ($studentCount > count($studentPreview)): ?>
                        <div class="cohort-avatar cohort-avatar-more">+<?php echo (int)($studentCount - count($studentPreview)); ?></div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="cohort-avatar cohort-avatar-more">0</div>
                <?php endif; ?>
            </div>

            <div class="cohort-student-list">
                <?php if (!$studentPreview): ?>
                    <div class="cohort-muted">No active students are assigned to this cohort yet.</div>
                <?php else: ?>
                    <?php foreach ($studentPreview as $s): ?>
                        <div class="cohort-student-item">
                            <div class="cohort-student-main">
                                <?php
                                echo cohort_avatar_html(
                                    (string)($s['name'] ?? ''),
                                    (string)($s['email'] ?? ''),
                                    (string)($s['photo_path'] ?? ''),
                                    38,
                                    true
                                );
                                ?>
                                <div class="cohort-student-copy">
                                    <div class="cohort-student-name"><?php echo cohort_h((string)($s['name'] ?? '')); ?></div>
                                    <div class="cohort-student-meta">
                                        <?php echo cohort_h((string)($s['email'] ?? '')); ?>
                                        · <?php echo cohort_h((string)($s['role'] ?? 'student')); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="cohort-chip"><?php echo cohort_h((string)($s['status'] ?? 'active')); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
                <a class="cohort-btn" href="/admin/cohort_students.php?cohort_id=<?php echo (int)$cohortId; ?>">Open student roster</a>
            </div>
        </section>
    </div>

    <div class="cohort-grid-two">
        <section class="card cohort-card-pad" id="courses">
            <h2 class="cohort-section-title">Courses and lesson scope</h2>
            <p class="cohort-section-sub">
                Admin-selected scope is authoritative. Advisory scheduling rules must never silently remove courses or lessons from storage.
            </p>

            <form method="post">
                <input type="hidden" name="action" value="save_scope">
                <input type="hidden" name="program_id" value="<?php echo (int)$programId; ?>">

                <?php if (!$programId || !$courseLessonTree): ?>
                    <div class="cohort-muted">No program or lessons available. Set a valid program above first.</div>
                <?php else: ?>
                    <div class="cohort-course-grid">
                        <?php foreach ($courseLessonTree as $courseRow): ?>
                            <?php
                            $cid = (int)$courseRow['course_id'];
                            $courseChecked = !empty($courseRow['is_enabled']);
                            $totalLessons = (int)$courseRow['total_lessons'];
                            $selectedLessons = (int)$courseRow['selected_lessons'];
                            $scopeDetailsId = 'scope_course_' . $cid;
                            ?>
                            <div class="cohort-scope-card">
                                <div class="cohort-scope-head">
                                    <div>
                                        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
                                            <input
                                                type="checkbox"
                                                name="course_ids[]"
                                                value="<?php echo $cid; ?>"
                                                <?php echo $courseChecked ? 'checked' : ''; ?>
                                            >
                                            <span>
                                                <span class="cohort-scope-title"><?php echo cohort_h((string)$courseRow['course_title']); ?></span>
                                                <span class="cohort-scope-meta">
                                                    <?php echo $selectedLessons; ?> selected of <?php echo $totalLessons; ?> total lessons
                                                    · Course ID <?php echo $cid; ?>
                                                </span>
                                            </span>
                                        </label>
                                    </div>
                                   <div class="cohort-chip <?php echo $courseChecked ? '' : 'cohort-chip-disabled'; ?>">
                                        <?php echo $courseChecked ? 'Enabled' : 'Disabled'; ?>
                                    </div>
                                </div>

                                <details id="<?php echo cohort_h($scopeDetailsId); ?>" style="margin-top:12px;">
                                    <summary class="cohort-modal-btn">Select lessons</summary>
                                    <div class="cohort-scope-lessons">
                                        <?php foreach ($courseRow['lessons'] as $lessonRow): ?>
                                            <?php
                                            $lessonId = (int)$lessonRow['lesson_id'];
                                            $lessonChecked = !empty($lessonRow['is_selected']);
                                            ?>
                                            <label class="cohort-scope-lesson">
                                                <input
                                                    type="checkbox"
                                                    name="lesson_ids[]"
                                                    value="<?php echo $lessonId; ?>"
                                                    <?php echo $lessonChecked ? 'checked' : ''; ?>
                                                >
                                                <span class="cohort-scope-lesson-copy">
                                                    <?php echo (int)$lessonRow['external_lesson_id']; ?>
                                                    — <?php echo cohort_h((string)$lessonRow['lesson_title']); ?>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="margin-top:14px;">
                        <button class="cohort-btn" type="submit">Save scope selection</button>
                    </div>
                <?php endif; ?>
            </form>
        </section>

        
		        <section class="card cohort-card-pad" id="schedule-settings">
            <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap;">
                <div>
                    <h2 class="cohort-section-title">Scheduling settings</h2>
                    <p class="cohort-section-sub" style="margin-bottom:0;">
                        These settings apply to this cohort only. They help calculate preview and published deadlines, but they do not change the selected course and lesson scope.
                    </p>
                </div>
            </div>

            <form method="post" style="margin-top:14px;">
                <input type="hidden" name="action" value="save_schedule_settings">

                <div class="cohort-form-grid">
                    <div class="cohort-field">
                        <label>Projected schedule start date</label>
                        <input
                            class="cohort-input"
                            type="date"
                            name="schedule_start_date"
                            value="<?php echo cohort_h(cohort_schedule_setting_value($settingsSnapshot, 'schedule_start_date', '')); ?>"
                        >
                        <div class="cohort-muted">The first date the planner may use when distributing deadlines. This can be later than the cohort start date.</div>
                    </div>

                    <div class="cohort-field">
                        <label>Cutoff time</label>
                        <input
                            class="cohort-input"
                            type="time"
                            name="cutoff_local_time"
                            value="<?php echo cohort_h(cohort_schedule_setting_value($settingsSnapshot, 'cutoff_time_local', '23:59')); ?>"
                        >
                        <div class="cohort-muted">The local deadline time shown on each scheduled date for this cohort.</div>
                    </div>

                    <div class="cohort-field">
                        <label>Daily study cap (min)</label>
                        <input
                            class="cohort-input"
                            type="number"
                            min="30"
                            max="600"
                            step="1"
                            name="daily_cap_min"
                            value="<?php echo cohort_h(cohort_schedule_setting_value($settingsSnapshot, 'daily_cap_min', '120')); ?>"
                        >
                        <div class="cohort-muted">Preferred amount of study time per usable day before the planner considers the schedule compressed.</div>
                    </div>

                    <div class="cohort-field">
                        <label>Reading speed (WPM)</label>
                        <input
                            class="cohort-input"
                            type="number"
                            min="60"
                            max="400"
                            step="1"
                            name="reading_wpm"
                            value="<?php echo cohort_h(cohort_schedule_setting_value($settingsSnapshot, 'wpm', '140')); ?>"
                        >
                        <div class="cohort-muted">Used to estimate how long lesson narration may take a student to go through.</div>
                    </div>

                    <div class="cohort-field">
                        <label>Study multiplier</label>
                        <input
                            class="cohort-input"
                            type="number"
                            min="1"
                            max="6"
                            step="0.1"
                            name="study_multiplier"
                            value="<?php echo cohort_h(cohort_schedule_setting_float($scheduleSettings, 'study_multiplier', 2.5)); ?>"
                        >
                        <div class="cohort-muted">Adds extra time for note-taking, repetition, and review beyond raw reading time.</div>
                    </div>

                    <div class="cohort-field">
                        <label>Progress test minutes</label>
                        <input
                            class="cohort-input"
                            type="number"
                            min="0"
                            max="180"
                            step="1"
                            name="progress_test_minutes"
                            value="<?php echo cohort_h(cohort_schedule_setting_value($settingsSnapshot, 'progress_test_min', '30')); ?>"
                        >
                        <div class="cohort-muted">Extra time reserved per lesson for the progress test.</div>
                    </div>

                    <div class="cohort-field">
                        <label>Buffer minimum days</label>
                        <input
                            class="cohort-input"
                            type="number"
                            min="0"
                            max="365"
                            step="1"
                            name="buffer_min_days"
                            value="<?php echo cohort_h(cohort_schedule_setting_value($settingsSnapshot, 'buffer_min_days', '3')); ?>"
                        >
                        <div class="cohort-muted">Minimum advisory buffer for the plan. Used to help judge whether the window is tight.</div>
                    </div>

                    <div class="cohort-field">
                        <label>Buffer percentage</label>
                        <input
                            class="cohort-input"
                            type="number"
                            min="0"
                            max="5"
                            step="0.01"
                            name="buffer_pct"
                            value="<?php echo cohort_h(cohort_schedule_setting_float($scheduleSettings, 'buffer_pct', 0.15)); ?>"
                        >
                        <div class="cohort-muted">Extra advisory margin added on top of estimated effort when assessing tight schedules.</div>
                    </div>
                </div>

                <div style="margin-top:16px;">
                    <label style="display:block;font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#64748b;margin-bottom:8px;">No schedule days</label>
                    <div style="display:flex;gap:14px;flex-wrap:wrap;">
                        <?php foreach (cohort_weekday_labels() as $dayNo => $dayLabel): ?>
                            <?php $allowed = in_array((int)$dayNo, (array)$settingsSnapshot['allowed_weekdays'], true); ?>
                            <label style="display:inline-flex;align-items:center;gap:8px;">
                                <input
                                    type="checkbox"
                                    name="allowed_weekdays[]"
                                    value="<?php echo (int)$dayNo; ?>"
                                    <?php echo $allowed ? 'checked' : ''; ?>
                                >
                                <span><?php echo cohort_h($dayLabel); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="cohort-muted" style="margin-top:8px;">
                        Uncheck days you do not want the planner to use. For example, uncheck Sat and Sun to skip weekends.
                    </div>
                </div>

                <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
                    <button class="cohort-btn" type="submit">Save scheduling settings</button>
                </div>
            </form>

            <?php if ($scheduleVersionRows): ?>
                <div style="margin-top:20px;padding-top:16px;border-top:1px solid rgba(15,23,42,.06);">
                    <h3 style="margin:0 0 10px 0;font-size:16px;font-weight:800;color:#102845;">Schedule version history</h3>

                    <div class="cohort-table-wrap" style="margin-top:0;">
                        <table class="cohort-table">
                            <thead>
                                <tr>
                                    <th style="width:80px;">Version</th>
                                    <th style="width:120px;">Status</th>
                                    <th style="width:180px;">Created</th>
                                    <th style="width:180px;">Published</th>
                                    <th>Summary</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($scheduleVersionRows as $versionRow): ?>
                                    <?php
                                    $summaryJson = json_decode((string)($versionRow['summary_json'] ?? ''), true);
                                    if (!is_array($summaryJson)) {
                                        $summaryJson = array();
                                    }
                                    ?>
                                    <tr>
                                        <td><?php echo (int)($versionRow['version_no'] ?? 0); ?></td>
                                        <td><?php echo cohort_h((string)($versionRow['status'] ?? '—')); ?></td>
                                        <td><?php echo cohort_h((string)($versionRow['created_at'] ?? '—')); ?></td>
                                        <td><?php echo cohort_h((string)($versionRow['published_at'] ?? '—')); ?></td>
                                        <td>
                                            <?php
                                            echo cohort_h(
                                                (string)($summaryJson['lessons_scheduled'] ?? '0')
                                                . ' lessons · Suggested end '
                                                . (string)($summaryJson['suggested_end_pretty'] ?? '—')
                                            );
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </section>
		
		
    </div>

    <section class="card cohort-card-pad" id="schedule">
        <div class="cohort-schedule-head">
            <div>
                <h2 class="cohort-section-title">Schedule preview and publish</h2>
                <p class="cohort-section-sub">
                    Preview compares existing published deadlines against newly projected deadlines for the current selected scope.
                    Publish makes the new baseline real.
                </p>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="preview_schedule">
                    <button class="cohort-btn cohort-btn-secondary" type="submit">Preview schedule</button>
                </form>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="publish_schedule">
                    <button class="cohort-btn" type="submit">Publish schedule</button>
                </form>
            </div>
        </div>

        <?php if ($scheduleSummary): ?>
            <div class="cohort-schedule-summary">
                <div class="cohort-summary-box">
                    <div class="cohort-summary-label">Lessons scheduled</div>
                    <div class="cohort-summary-value"><?php echo (int)$scheduleSummary['lessons_scheduled']; ?></div>
                </div>
                <div class="cohort-summary-box">
                    <div class="cohort-summary-label">Total study hours</div>
                    <div class="cohort-summary-value"><?php echo cohort_h((string)$scheduleSummary['total_study_hours']); ?></div>
                </div>
                 <div class="cohort-summary-box">
                    <div class="cohort-summary-label">Usable days</div>
                    <div class="cohort-summary-value">
                        <?php echo (int)($scheduleSummary['usable_days'] ?? 0); ?> usable days
                        <span class="cohort-muted"> · recommended <?php echo (int)($scheduleSummary['recommended_days'] ?? 0); ?></span>
                    </div>
                </div>
                <div class="cohort-summary-box">
                    <div class="cohort-summary-label">Suggested end date</div>
                    <div class="cohort-summary-value"><?php echo cohort_h((string)($scheduleSummary['suggested_end_local_label'] ?? '—')); ?></div>
                    <div class="cohort-muted">
                        Start <?php echo cohort_h((string)($scheduleSummary['schedule_start_date'] ?? '—')); ?>
                        · Cutoff <?php echo cohort_h((string)($scheduleSummary['cutoff_local_time'] ?? '—')); ?>
                    </div>
                </div>
            </div>

            <div class="cohort-muted" style="margin-top:12px;">
                <?php echo cohort_h((string)$scheduleSummary['assumptions']); ?>
            </div>

            <?php if (!empty($scheduleSummary['advisory_text'])): ?>
                <div class="cohort-alert" style="margin-top:12px;">
                    <?php echo cohort_h((string)$scheduleSummary['advisory_text']); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($scheduleCourses): ?>
            
		
<div class="cohort-table-wrap">
    <table class="cohort-table">
        <thead>
            <tr>
                <th style="width:70px;">Order</th>
                <th>Course</th>
                <th style="width:220px;">Current deadline</th>
                <th style="width:220px;">Projected deadline</th>
                <th style="width:120px;">Delta</th>
            </tr>
        </thead>
        <tbody>

            <?php $courseCounter = 0; ?>
            <?php foreach ($scheduleCourses as $courseRow): ?>
                <?php
                $courseCounter++;
                $previewDetailsId = 'preview_course_' . $courseCounter;
                ?>

                <tr>
                    <td><?php echo $courseCounter; ?></td>

                    <td colspan="4">
                        <details class="cohort-course-details" id="<?php echo cohort_h($previewDetailsId); ?>">
                            
                            <summary style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
                                <span>
                                    <strong><?php echo cohort_h((string)$courseRow['course_title']); ?></strong>
                                </span>

                                <span style="display:flex;gap:14px;align-items:center;">
                                    <span class="cohort-muted">
                                        <?php echo cohort_h((string)($courseRow['existing_course_deadline_local_label'] ?? '—')); ?>
                                    </span>

                                    <span class="cohort-muted">→</span>

                                    <span>
                                        <?php echo cohort_h((string)($courseRow['course_deadline_local_label'] ?? '—')); ?>
                                    </span>

                                    <span class="cohort-chip">
                                        <?php echo cohort_h((string)($courseRow['course_deadline_delta_label'] ?? '—')); ?>
                                    </span>
                                </span>
                            </summary>

                            <div style="margin-top:10px;">
                                <table class="cohort-table" style="width:100%;">
                                    <thead>
                                        <tr>
                                            <th style="width:70px;">Order</th>
                                            <th>Lesson</th>
                                            <th style="width:220px;">Current deadline</th>
                                            <th style="width:220px;">Projected deadline</th>
                                            <th style="width:120px;">Delta</th>
                                        </tr>
                                    </thead>
                                    <tbody>

                                        <?php $lessonCounter = 0; ?>
                                        <?php foreach ((array)$courseRow['lessons'] as $lessonRow): ?>
                                            <?php
                                            $lessonCounter++;
                                            $isWeekend = !empty($lessonRow['is_weekend']);
                                            $rowBg = $isWeekend ? ' style="background:rgba(245,158,11,.08);"' : '';
                                            ?>

                                            <tr<?php echo $rowBg; ?>>
                                                <td><?php echo $lessonCounter; ?></td>

                                                <td>
                                                    <?php echo (int)$lessonRow['external_lesson_id']; ?>
                                                    — <?php echo cohort_h((string)$lessonRow['title']); ?>

                                                    <div class="cohort-muted">
                                                        <?php echo $isWeekend ? 'Weekend date' : 'Weekday date'; ?>
                                                        · Cutoff <?php echo cohort_h((string)($lessonRow['cutoff_label'] ?? ($settingsSnapshot['cutoff_time_local'] . ' local'))); ?>
                                                    </div>
                                                </td>

                                                <td>
                                                    <?php echo cohort_h((string)($lessonRow['old_deadline_local_label'] ?? '—')); ?>
                                                </td>

                                                <td>
                                                    <?php echo cohort_h((string)($lessonRow['new_deadline_local_label'] ?? '—')); ?>
                                                </td>

                                                <td>
                                                    <?php echo cohort_h((string)($lessonRow['delta_label'] ?? '—')); ?>
                                                </td>
                                            </tr>

                                        <?php endforeach; ?>

                                    </tbody>
                                </table>
                            </div>

                        </details>
                    </td>
                </tr>

            <?php endforeach; ?>

        </tbody>
    </table>
</div>		
		
		
        <?php elseif (!$courseBlocks): ?>
            <div class="cohort-muted" style="margin-top:12px;">
                No published schedule exists yet. Use <strong>Preview schedule</strong> to inspect the proposed schedule, then <strong>Publish schedule</strong> to store it.
            </div>
        <?php else: ?>
            <div class="cohort-table-wrap">
                <table class="cohort-table">
                    <thead>
                        <tr>
                            <th style="width:70px;">Order</th>
                            <th>Course</th>
                            <th style="width:220px;">Published deadline</th>
                        </tr>
                    </thead>
                    <tbody>
                                                <?php $i = 0; foreach ($courseBlocks as $cb): $i++; ?>
                            <?php
                            $courseDeadlinePretty = cohort_fmt_pretty((string)$cb['course_deadline_utc']);
                            $courseTitle = (string)$cb['course_title'];
                            $courseLessons = (array)$cb['lessons'];
                            $detailsId = 'course_' . (int)$cb['course_id'];
                            ?>
                            <tr>
                                <td><?php echo $i; ?></td>
                                <td>
                                    <details class="cohort-course-details" id="<?php echo cohort_h($detailsId); ?>">
                                        <summary><?php echo cohort_h($courseTitle); ?></summary>
                                    </details>
                                </td>
                                <td><?php echo cohort_h($courseDeadlinePretty); ?></td>
                            </tr>
                            <tr>
                                <td></td>
                                <td colspan="2" style="padding-top:0;">
                                    <div style="padding:10px 0 0 0;">
                                        <table class="cohort-table" style="width:100%;margin-top:0;">
                                            <thead>
                                                <tr>
                                                    <th style="width:70px;">Order</th>
                                                    <th>Lesson</th>
                                                    <th style="width:220px;">Published deadline</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $j = 0; foreach ($courseLessons as $lx): $j++; ?>
                                                    <tr>
                                                        <td><?php echo $j; ?></td>
                                                        <td>
                                                            <?php echo (int)$lx['external_lesson_id']; ?>
                                                            — <?php echo cohort_h((string)$lx['lesson_title']); ?>
                                                        </td>
                                                        <td><?php echo cohort_h(cohort_fmt_pretty((string)$lx['deadline_utc'])); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>



<?php cw_footer(); ?>