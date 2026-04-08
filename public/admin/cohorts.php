<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

function c_h(mixed $v): string
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

function cohort_fmt_pretty_date(?string $ymdOrDt): string
{
    $raw = trim((string)$ymdOrDt);
    if ($raw === '') {
        return '—';
    }

    $d = substr($raw, 0, 10);

    try {
        $dt = new DateTimeImmutable($d . ' 00:00:00', new DateTimeZone('UTC'));
        return $dt->format('D, M j, Y');
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

function cohort_progress_pct(int $lessonCount, int $deadlineCount): int
{
    if ($lessonCount <= 0) {
        return 0;
    }

    $pct = (int)floor(($deadlineCount / max(1, $lessonCount)) * 100);
    if ($pct < 0) {
        $pct = 0;
    }
    if ($pct > 100) {
        $pct = 100;
    }
    return $pct;
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

function cohort_avatar_html(string $name, string $email, string $photoPath, int $size = 34): string
{
    $label = trim($name) !== '' ? $name : $email;
    $initials = cohort_initials($name, $email);
    $url = cohort_avatar_url($photoPath);
    $style = 'width:' . $size . 'px;height:' . $size . 'px;';

    if ($url !== '') {
        return ''
            . '<span class="cohort-avatar" style="' . c_h($style) . '" title="' . c_h($label) . '">'
            . '<img src="' . c_h($url) . '" alt="' . c_h($label) . '" loading="lazy">'
            . '</span>';
    }

    return ''
        . '<span class="cohort-avatar cohort-avatar--fallback" style="' . c_h($style) . '" title="' . c_h($label) . '">'
        . c_h($initials)
        . '</span>';
}

$msg = '';

$programs = $pdo->query("
    SELECT id, program_key, sort_order
    FROM programs
    ORDER BY sort_order, id
")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'create_cohort') {
    $programId = (int)($_POST['program_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $start = trim((string)($_POST['start_date'] ?? ''));
    $end = trim((string)($_POST['end_date'] ?? ''));
    $tz = trim((string)($_POST['timezone'] ?? 'UTC'));

    if ($programId <= 0 || $name === '' || $start === '' || $end === '') {
        $msg = 'Missing required fields.';
    } else {
        $st = $pdo->prepare("
            SELECT id
            FROM courses
            WHERE program_id = ?
            ORDER BY sort_order, id
            LIMIT 1
        ");
        $st->execute(array($programId));
        $primaryCourseId = (int)($st->fetchColumn() ?: 0);

        if ($primaryCourseId <= 0) {
            $msg = 'No courses exist for this program yet. Create/import courses first.';
        } else {
            $ins = $pdo->prepare("
                INSERT INTO cohorts (program_id, course_id, name, start_date, end_date, timezone)
                VALUES (?,?,?,?,?,?)
            ");
            $ins->execute(array($programId, $primaryCourseId, $name, $start, $end, $tz));
            $cohortId = (int)$pdo->lastInsertId();

            $all = $pdo->prepare("
                SELECT id
                FROM courses
                WHERE program_id = ?
                ORDER BY sort_order, id
            ");
            $all->execute(array($programId));
            $courseIds = $all->fetchAll(PDO::FETCH_COLUMN);

            $insCC = $pdo->prepare("
                INSERT INTO cohort_courses (cohort_id, course_id, is_enabled)
                VALUES (?,?,1)
                ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)
            ");
            foreach ($courseIds as $cid) {
                $insCC->execute(array($cohortId, (int)$cid));
            }

            header('Location: /admin/cohort.php?cohort_id=' . $cohortId);
            exit;
        }
    }
}

$list = $pdo->query("
    SELECT
        co.id,
        co.name,
        co.start_date,
        co.end_date,
        co.timezone,
        co.program_id,
        p.program_key,
        (
            SELECT COUNT(*)
            FROM cohort_students cs
            WHERE cs.cohort_id = co.id
              AND cs.status = 'active'
        ) AS active_student_count,
        (
            SELECT COUNT(*)
            FROM cohort_courses cc
            WHERE cc.cohort_id = co.id
              AND cc.is_enabled = 1
        ) AS enabled_course_count,
        (
            SELECT COUNT(*)
            FROM cohort_courses cc
            JOIN lessons l ON l.course_id = cc.course_id
            WHERE cc.cohort_id = co.id
              AND cc.is_enabled = 1
        ) AS enabled_lesson_count,
        (
            SELECT COUNT(*)
            FROM cohort_lesson_deadlines d
            WHERE d.cohort_id = co.id
        ) AS deadline_count,
        (
            SELECT MIN(d.deadline_utc)
            FROM cohort_lesson_deadlines d
            WHERE d.cohort_id = co.id
        ) AS first_deadline_utc,
        (
            SELECT MAX(d.deadline_utc)
            FROM cohort_lesson_deadlines d
            WHERE d.cohort_id = co.id
        ) AS last_deadline_utc
    FROM cohorts co
    LEFT JOIN programs p ON p.id = co.program_id
    ORDER BY co.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$cohortIds = array();
foreach ($list as $row) {
    $cohortIds[] = (int)$row['id'];
}

$avatarRowsByCohort = array();
if ($cohortIds) {
    $in = implode(',', array_fill(0, count($cohortIds), '?'));
    $sql = "
        SELECT
			x.cohort_id,
			x.user_id,
			x.email,
			x.name,
			x.photo_path
        FROM (
            SELECT
                cs.cohort_id,
                u.id AS user_id,
				u.email,
				u.name,
				u.photo_path,
                ROW_NUMBER() OVER (
                    PARTITION BY cs.cohort_id
                    ORDER BY
                        CASE WHEN TRIM(COALESCE(u.name, '')) = '' THEN 1 ELSE 0 END ASC,
                        u.name ASC,
                        u.email ASC,
                        u.id ASC
                ) AS rn
            FROM cohort_students cs
            JOIN users u ON u.id = cs.user_id
            WHERE cs.cohort_id IN ($in)
              AND cs.status = 'active'
        ) x
        WHERE x.rn <= 5
        ORDER BY x.cohort_id ASC, x.rn ASC
    ";

    try {
        $st = $pdo->prepare($sql);
        $st->execute($cohortIds);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $cid = (int)$r['cohort_id'];
            if (!isset($avatarRowsByCohort[$cid])) {
                $avatarRowsByCohort[$cid] = array();
            }
            $avatarRowsByCohort[$cid][] = $r;
        }
    } catch (Throwable $e) {
        // Fallback for environments without window functions
        $fallbackSql = "
            SELECT cs.cohort_id, u.id AS user_id, u.email, u.name, u.photo_path
            FROM cohort_students cs
            JOIN users u ON u.id = cs.user_id
            WHERE cs.cohort_id IN ($in)
              AND cs.status = 'active'
            ORDER BY cs.cohort_id ASC, u.name ASC, u.email ASC, u.id ASC
        ";
        $st = $pdo->prepare($fallbackSql);
        $st->execute($cohortIds);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $cid = (int)$r['cohort_id'];
            if (!isset($avatarRowsByCohort[$cid])) {
                $avatarRowsByCohort[$cid] = array();
            }
            if (count($avatarRowsByCohort[$cid]) < 5) {
                $avatarRowsByCohort[$cid][] = $r;
            }
        }
    }
}

cw_header('Theory Training');
?>
<style>
.cohorts-page{display:flex;flex-direction:column;gap:18px}
.cohorts-hero{padding:22px 24px}
.cohorts-eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.14em;color:#64748b;font-weight:800;margin-bottom:8px}
.cohorts-title{margin:0;font-size:32px;line-height:1.05;letter-spacing:-.04em;color:#102845}
.cohorts-sub{margin-top:10px;font-size:14px;line-height:1.6;color:#56677f;max-width:980px}
.cohorts-grid{display:grid;grid-template-columns:minmax(320px,420px) minmax(0,1fr);gap:18px}
.cohorts-card-pad{padding:20px 22px}
.cohorts-section-title{margin:0 0 8px 0;font-size:20px;font-weight:800;color:#102845}
.cohorts-section-sub{margin:0 0 14px 0;font-size:13px;line-height:1.6;color:#64748b}
.cohorts-form-grid{display:grid;grid-template-columns:1fr;gap:12px}
.cohorts-field label{display:block;font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#64748b;margin-bottom:6px}
.cohorts-input,.cohorts-select{
    width:100%;box-sizing:border-box;border:1px solid rgba(15,23,42,.12);
    border-radius:12px;padding:11px 12px;background:#fff;color:#102845;font:inherit;
}
.cohorts-btn{
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    min-height:40px;padding:0 14px;border-radius:12px;border:1px solid #12355f;
    background:#12355f;color:#fff;text-decoration:none;font-size:13px;font-weight:800;cursor:pointer;
}
.cohorts-btn:hover{opacity:.96}
.cohorts-btn-secondary{
    background:#fff;color:#12355f;border-color:rgba(18,53,95,.16);
}
.cohorts-flash{padding:14px 16px;border-radius:14px;background:rgba(153,27,27,.08);color:#991b1b;border:1px solid rgba(153,27,27,.14);font-size:14px;font-weight:700}
.cohorts-list{display:grid;gap:14px}
.cohort-item{
    border:1px solid rgba(15,23,42,.08);
    border-radius:18px;
    background:#fff;
    box-shadow:0 10px 30px rgba(15,23,42,.04);
    overflow:hidden;
}
.cohort-item-top{
    padding:18px 20px 16px 20px;
    display:flex;justify-content:space-between;gap:16px;align-items:flex-start;
}
.cohort-left{min-width:0;display:flex;flex-direction:column;gap:10px}
.cohort-right{display:flex;flex-direction:column;gap:10px;align-items:flex-end;min-width:220px}
.cohort-program-chip{
    display:inline-flex;align-items:center;gap:8px;padding:7px 10px;border-radius:999px;
    background:rgba(18,53,95,.08);color:#12355f;font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;
    width:max-content;max-width:100%;
}
.cohort-name{margin:0;font-size:22px;line-height:1.15;letter-spacing:-.03em;color:#102845}
.cohort-meta{display:flex;gap:10px;flex-wrap:wrap}
.cohort-meta-pill{
    display:inline-flex;align-items:center;gap:8px;padding:8px 10px;border-radius:12px;
    background:#f8fafc;border:1px solid rgba(15,23,42,.06);font-size:12px;font-weight:700;color:#334155;
}
.cohort-meta-pill strong{color:#102845}
.cohort-avatars{display:flex;align-items:center}
.cohort-avatar{
    width:34px;height:34px;border-radius:999px;display:flex;align-items:center;justify-content:center;
    font-size:11px;font-weight:800;color:#fff;background:linear-gradient(135deg,#12355f,#2767aa);
    border:2px solid #fff;box-shadow:0 2px 8px rgba(15,23,42,.12);margin-left:-8px;
    overflow:hidden;flex:0 0 auto;
}
.cohort-avatar img{
    width:100%;height:100%;object-fit:cover;display:block;
}
.cohort-avatar--fallback{
    text-transform:uppercase;
}
.cohort-avatar:first-child{margin-left:0}
.cohort-avatar-more{
    background:linear-gradient(135deg,#64748b,#475569);
}
.cohort-count-note{font-size:12px;color:#64748b;font-weight:700}
.cohort-stats{
    display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;
    padding:0 20px 16px 20px;
}
.cohort-stat{
    border:1px solid rgba(15,23,42,.06);border-radius:14px;background:#f8fafc;padding:12px 12px 10px 12px;
}
.cohort-stat-label{font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#64748b;margin-bottom:6px}
.cohort-stat-value{font-size:20px;font-weight:800;letter-spacing:-.03em;color:#102845}
.cohort-stat-sub{margin-top:4px;font-size:12px;line-height:1.45;color:#64748b}
.cohort-progress-wrap{
    padding:0 20px 18px 20px;
    display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;align-items:center;
}
.cohort-progress-copy{min-width:0}
.cohort-progress-label{font-size:12px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#64748b;margin-bottom:8px}
.cohort-progress-bar{
    width:100%;height:12px;border-radius:999px;background:#e2e8f0;overflow:hidden;
}
.cohort-progress-fill{
    height:100%;border-radius:999px;background:linear-gradient(90deg,#12355f 0%, #1f5e99 55%, #38bdf8 100%);
}
.cohort-progress-sub{margin-top:8px;font-size:12px;color:#64748b}
.cohort-actions{
    padding:14px 20px 18px 20px;border-top:1px solid rgba(15,23,42,.06);
    display:flex;gap:10px;flex-wrap:wrap;
}
.cohorts-empty{padding:16px 0;color:#64748b;font-size:14px}
@media (max-width: 1180px){
    .cohorts-grid{grid-template-columns:1fr}
}
@media (max-width: 900px){
    .cohort-item-top{flex-direction:column}
    .cohort-right{align-items:flex-start;min-width:0}
    .cohort-stats{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media (max-width: 640px){
    .cohort-stats{grid-template-columns:1fr}
    .cohort-progress-wrap{grid-template-columns:1fr}
}
</style>

<div class="cohorts-page">

    <?php if ($msg !== ''): ?>
        <div class="cohorts-flash"><?php echo c_h($msg); ?></div>
    <?php endif; ?>

    <section class="card cohorts-hero">
        <div class="cohorts-eyebrow">Admin · Theory Training</div>
        <h1 class="cohorts-title">Theory Training Cohorts</h1>
        <div class="cohorts-sub">
            Create and manage theory cohorts without losing control of programs, courses, lessons, schedule visibility, and student roster access.
            Use each cohort as the operational container for course selection, lesson scheduling, student assignment, and deadline management.
        </div>
    </section>

    <div class="cohorts-grid">
        <section class="card cohorts-card-pad">
            <h2 class="cohorts-section-title">Create cohort</h2>
            <p class="cohorts-section-sub">
                Create a cohort by program. Courses are seeded from the selected program and remain configurable inside the cohort afterward.
            </p>

            <form method="post" class="cohorts-form-grid">
                <input type="hidden" name="action" value="create_cohort">

                <div class="cohorts-field">
                    <label>Program</label>
                    <select class="cohorts-select" name="program_id" required>
                        <?php foreach ($programs as $p): ?>
                            <option value="<?php echo (int)$p['id']; ?>">
                                <?php echo c_h((string)$p['program_key']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="cohorts-field">
                    <label>Cohort name</label>
                    <input class="cohorts-input" name="name" required placeholder="e.g. Private Ground School — Spring 2026">
                </div>

                <div class="cohorts-field">
                    <label>Start date</label>
                    <input class="cohorts-input" name="start_date" type="date" required>
                </div>

                <div class="cohorts-field">
                    <label>End date</label>
                    <input class="cohorts-input" name="end_date" type="date" required>
                </div>

                <div class="cohorts-field">
                    <label>Timezone</label>
                    <select class="cohorts-select" name="timezone" required>
                        <?php foreach (tz_options() as $k => $lbl): ?>
                            <option value="<?php echo c_h($k); ?>" <?php echo $k === 'UTC' ? 'selected' : ''; ?>>
                                <?php echo c_h($lbl); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="padding-top:6px;">
                    <button class="cohorts-btn" type="submit">Create cohort</button>
                </div>
            </form>
        </section>

        <section class="card cohorts-card-pad">
            <h2 class="cohorts-section-title">Existing cohorts</h2>
            <p class="cohorts-section-sub">
                Open a cohort to manage settings, included courses, lesson-level schedule, deadline publication, and student progress. Use the student roster page for enrollment management.
            </p>

            <?php if (!$list): ?>
                <div class="cohorts-empty">No cohorts exist yet.</div>
            <?php else: ?>
                <div class="cohorts-list">
                    <?php foreach ($list as $r): ?>
                        <?php
                        $cohortId = (int)$r['id'];
                        $studentCount = (int)($r['active_student_count'] ?? 0);
                        $courseCount = (int)($r['enabled_course_count'] ?? 0);
                        $lessonCount = (int)($r['enabled_lesson_count'] ?? 0);
                        $deadlineCount = (int)($r['deadline_count'] ?? 0);
                        $schedulePct = cohort_progress_pct($lessonCount, $deadlineCount);
                        $avatarRows = $avatarRowsByCohort[$cohortId] ?? array();
                        $extraStudents = max(0, $studentCount - count($avatarRows));
                        $durationDays = cohort_days_between((string)$r['start_date'], (string)$r['end_date']);
                        $firstDeadline = trim((string)($r['first_deadline_utc'] ?? ''));
                        $lastDeadline = trim((string)($r['last_deadline_utc'] ?? ''));
                        ?>
                        <article class="cohort-item">
                            <div class="cohort-item-top">
                                <div class="cohort-left">
                                    <div class="cohort-program-chip">
                                        <span><?php echo c_h((string)($r['program_key'] ?? '—')); ?></span>
                                    </div>

                                    <h3 class="cohort-name"><?php echo c_h((string)$r['name']); ?></h3>

                                    <div class="cohort-meta">
                                        <div class="cohort-meta-pill">
                                            <strong>Start</strong>
                                            <span><?php echo c_h(cohort_fmt_pretty_date((string)$r['start_date'])); ?></span>
                                        </div>
                                        <div class="cohort-meta-pill">
                                            <strong>End</strong>
                                            <span><?php echo c_h(cohort_fmt_pretty_date((string)$r['end_date'])); ?></span>
                                        </div>
                                        <div class="cohort-meta-pill">
                                            <strong>TZ</strong>
                                            <span><?php echo c_h((string)$r['timezone']); ?></span>
                                        </div>
                                        <div class="cohort-meta-pill">
                                            <strong>Span</strong>
                                            <span><?php echo (int)$durationDays; ?> day<?php echo $durationDays === 1 ? '' : 's'; ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="cohort-right">
                                    <div class="cohort-avatars">
                                        <?php if ($avatarRows): ?>
                                            <?php foreach ($avatarRows as $ar): ?>
													<?php
													echo cohort_avatar_html(
														(string)($ar['name'] ?? ''),
														(string)($ar['email'] ?? ''),
														(string)($ar['photo_path'] ?? ''),
														34
													);
													?>
												<?php endforeach; ?>
                                            <?php if ($extraStudents > 0): ?>
                                                <div class="cohort-avatar cohort-avatar-more" title="<?php echo c_h((string)$extraStudents . ' more student(s)'); ?>">
                                                    +<?php echo (int)$extraStudents; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="cohort-avatar cohort-avatar-more" title="No active students">0</div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="cohort-count-note">
                                        <?php echo (int)$studentCount; ?> active student<?php echo $studentCount === 1 ? '' : 's'; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="cohort-stats">
                                <div class="cohort-stat">
                                    <div class="cohort-stat-label">Courses Enabled</div>
                                    <div class="cohort-stat-value"><?php echo (int)$courseCount; ?></div>
                                    <div class="cohort-stat-sub">Program-scoped cohort selection.</div>
                                </div>

                                <div class="cohort-stat">
                                    <div class="cohort-stat-label">Lessons in Scope</div>
                                    <div class="cohort-stat-value"><?php echo (int)$lessonCount; ?></div>
                                    <div class="cohort-stat-sub">Current enabled lesson universe.</div>
                                </div>

                                <div class="cohort-stat">
                                    <div class="cohort-stat-label">Deadlines Stored</div>
                                    <div class="cohort-stat-value"><?php echo (int)$deadlineCount; ?></div>
                                    <div class="cohort-stat-sub">Baseline cohort schedule rows.</div>
                                </div>

                                <div class="cohort-stat">
                                    <div class="cohort-stat-label">Schedule Window</div>
                                    <div class="cohort-stat-value"><?php echo $lastDeadline !== '' ? c_h(cohort_fmt_pretty_date($lastDeadline)) : '—'; ?></div>
                                    <div class="cohort-stat-sub">
                                        <?php if ($firstDeadline !== ''): ?>
                                            First: <?php echo c_h(cohort_fmt_pretty_date($firstDeadline)); ?>
                                        <?php else: ?>
                                            No published deadlines yet.
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="cohort-progress-wrap">
                                <div class="cohort-progress-copy">
                                    <div class="cohort-progress-label">Schedule Coverage</div>
                                    <div class="cohort-progress-bar">
                                        <div class="cohort-progress-fill" style="width: <?php echo (int)$schedulePct; ?>%;"></div>
                                    </div>
                                    <div class="cohort-progress-sub">
                                        <?php echo (int)$deadlineCount; ?> of <?php echo (int)$lessonCount; ?> lessons currently have cohort baseline deadlines.
                                    </div>
                                </div>

                                <div class="cohort-count-note">
                                    <?php echo (int)$schedulePct; ?>%
                                </div>
                            </div>

                            <div class="cohort-actions">
                                <a class="cohorts-btn" href="/admin/cohort.php?cohort_id=<?php echo $cohortId; ?>">Open cohort</a>
                                <a class="cohorts-btn cohorts-btn-secondary" href="/admin/cohort_students.php?cohort_id=<?php echo $cohortId; ?>">Students</a>
                                <a class="cohorts-btn cohorts-btn-secondary" href="/admin/cohort.php?cohort_id=<?php echo $cohortId; ?>#schedule">Schedule</a>
                                <a class="cohorts-btn cohorts-btn-secondary" href="/admin/cohort.php?cohort_id=<?php echo $cohortId; ?>#courses">Courses & lessons</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php cw_footer(); ?>