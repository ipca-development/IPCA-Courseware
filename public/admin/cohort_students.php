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

function cs_h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function cs_fmt_pretty(string $ymdOrDt): string
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

function cs_initials(string $name, string $email = ''): string
{
    $name = trim($name);
    if ($name !== '') {
        $parts = preg_split('/\s+/', $name) ?: array();
        $out = '';
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part === '') {
                continue;
            }
            $out .= mb_strtoupper(mb_substr($part, 0, 1));
            if (mb_strlen($out) >= 2) {
                break;
            }
        }
        if ($out !== '') {
            return $out;
        }
    }

    $email = trim($email);
    if ($email !== '') {
        return mb_strtoupper(mb_substr($email, 0, 2));
    }

    return 'NA';
}

function cs_percent(int $numerator, int $denominator): int
{
    if ($denominator <= 0) {
        return 0;
    }
    return max(0, min(100, (int)floor(($numerator / $denominator) * 100)));
}

$cohortId = (int)($_GET['cohort_id'] ?? 0);
if ($cohortId <= 0) {
    $cohortId = (int)($_GET['id'] ?? 0);
}
if ($cohortId <= 0) {
    exit('Missing cohort_id');
}

$msg = '';
$msgType = 'info';
$search = trim((string)($_GET['q'] ?? ''));

$cohortStmt = $pdo->prepare("
    SELECT co.id, co.name, co.start_date, co.end_date, co.timezone, p.program_key
    FROM cohorts co
    LEFT JOIN programs p ON p.id = co.program_id
    WHERE co.id = ?
    LIMIT 1
");
$cohortStmt->execute(array($cohortId));
$cohort = $cohortStmt->fetch(PDO::FETCH_ASSOC);
if (!$cohort) {
    exit('Cohort not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'add_student') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if ($email === '') {
            $msg = 'Enter an email address.';
            $msgType = 'error';
        } else {
            $st = $pdo->prepare("
                SELECT id, email, name, role
                FROM users
                WHERE LOWER(email) = ?
                LIMIT 1
            ");
            $st->execute(array($email));
            $userRow = $st->fetch(PDO::FETCH_ASSOC);

            if (!$userRow) {
                $msg = 'No user found for: ' . $email;
                $msgType = 'error';
            } else {
                $uid = (int)$userRow['id'];
                $ins = $pdo->prepare("
                    INSERT INTO cohort_students (cohort_id, user_id, status, enrolled_at, created_at)
                    VALUES (?, ?, 'active', NOW(), NOW())
                    ON DUPLICATE KEY UPDATE status = 'active'
                ");
                $ins->execute(array($cohortId, $uid));
                $msg = 'Student added: ' . (string)$userRow['email'];
                $msgType = 'success';
            }
        }
    }

    if ($action === 'remove_student') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid > 0) {
            $pdo->prepare("
                DELETE FROM cohort_students
                WHERE cohort_id = ? AND user_id = ?
            ")->execute(array($cohortId, $uid));
            $msg = 'Student removed.';
            $msgType = 'success';
        }
    }
}

$userSearchSql = "
    SELECT
        u.id,
        u.name,
        u.email,
        u.role,
        CASE WHEN cs.user_id IS NULL THEN 0 ELSE 1 END AS already_in_cohort
    FROM users u
    LEFT JOIN cohort_students cs
        ON cs.user_id = u.id
       AND cs.cohort_id = :cohort_id
    WHERE 1 = 1
";
$params = array(':cohort_id' => $cohortId);

if ($search !== '') {
    $userSearchSql .= " AND (u.name LIKE :search OR u.email LIKE :search)";
    $params[':search'] = '%' . $search . '%';
} else {
    $userSearchSql .= " AND (u.role = 'student' OR u.role = 'admin')";
}

$userSearchSql .= "
    ORDER BY already_in_cohort ASC, u.name ASC, u.email ASC
    LIMIT 30
";

$userSearchStmt = $pdo->prepare($userSearchSql);
$userSearchStmt->execute($params);
$userSearchRows = $userSearchStmt->fetchAll(PDO::FETCH_ASSOC);

$studentsStmt = $pdo->prepare("
    SELECT
        cs.user_id,
        cs.status,
        cs.enrolled_at,
        u.email,
        u.name,
        u.role
    FROM cohort_students cs
    JOIN users u ON u.id = cs.user_id
    WHERE cs.cohort_id = ?
    ORDER BY
        CASE WHEN cs.status = 'active' THEN 0 ELSE 1 END,
        u.name ASC,
        u.email ASC
");
$studentsStmt->execute(array($cohortId));
$students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

$lessonStatsStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_enabled_lessons
    FROM cohort_lesson_deadlines d
    JOIN lessons l ON l.id = d.lesson_id
    JOIN courses c ON c.id = l.course_id
    LEFT JOIN cohort_courses cc
        ON cc.cohort_id = d.cohort_id
       AND cc.course_id = c.id
    WHERE d.cohort_id = ?
      AND COALESCE(cc.is_enabled, 1) = 1
");
$lessonStatsStmt->execute(array($cohortId));
$totalEnabledLessons = (int)($lessonStatsStmt->fetchColumn() ?: 0);

$studentStats = array();
if ($students) {
    $userIds = array();
    foreach ($students as $s) {
        $userIds[] = (int)$s['user_id'];
    }

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));

    $statsSql = "
        SELECT
            la.user_id,
            COUNT(*) AS activity_rows,
            SUM(CASE WHEN la.completion_status = 'completed' THEN 1 ELSE 0 END) AS completed_lessons,
            SUM(CASE WHEN la.completion_status IN ('in_progress','awaiting_summary_review','awaiting_test_completion','summary_required') THEN 1 ELSE 0 END) AS active_lessons,
            SUM(CASE WHEN la.completion_status IN ('remediation_required','instructor_required','deadline_blocked','training_suspended') THEN 1 ELSE 0 END) AS blocked_lessons,
            MAX(la.updated_at) AS last_activity_at,
            AVG(CASE WHEN la.best_score IS NOT NULL THEN la.best_score END) AS avg_best_score
        FROM lesson_activity la
        WHERE la.cohort_id = ?
          AND la.user_id IN ($placeholders)
        GROUP BY la.user_id
    ";
    $statsStmt = $pdo->prepare($statsSql);
    $statsStmt->execute(array_merge(array($cohortId), $userIds));

    foreach ($statsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $studentStats[(int)$row['user_id']] = $row;
    }
}

$activeStudentCount = 0;
foreach ($students as $s) {
    if ((string)($s['status'] ?? '') === 'active') {
        $activeStudentCount++;
    }
}

cw_header('Theory Training');
?>
<style>
.cs-page{display:flex;flex-direction:column;gap:18px}
.cs-hero{padding:22px 24px}
.cs-eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.14em;color:#64748b;font-weight:800;margin-bottom:8px}
.cs-title{margin:0;font-size:32px;line-height:1.05;letter-spacing:-.04em;color:#102845}
.cs-sub{margin-top:10px;font-size:14px;line-height:1.6;color:#56677f;max-width:980px}
.cs-alert{padding:14px 16px;border-radius:14px;font-size:14px;font-weight:700}
.cs-alert.success{background:rgba(22,101,52,.09);color:#166534;border:1px solid rgba(22,101,52,.18)}
.cs-alert.error{background:rgba(153,27,27,.08);color:#991b1b;border:1px solid rgba(153,27,27,.16)}
.cs-alert.info{background:rgba(18,53,95,.07);color:#12355f;border:1px solid rgba(18,53,95,.12)}
.cs-top-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.cs-btn{
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    min-height:40px;padding:0 14px;border-radius:12px;border:1px solid #12355f;
    background:#12355f;color:#fff;text-decoration:none;font-size:13px;font-weight:800;cursor:pointer;
}
.cs-btn:hover{opacity:.96}
.cs-btn-secondary{background:#fff;color:#12355f;border-color:rgba(18,53,95,.16)}
.cs-grid-main{display:grid;grid-template-columns:1.05fr .95fr;gap:18px}
.cs-grid-two{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.cs-card-pad{padding:20px 22px}
.cs-section-title{margin:0 0 8px 0;font-size:20px;font-weight:800;color:#102845}
.cs-section-sub{margin:0 0 14px 0;font-size:13px;line-height:1.6;color:#64748b}
.cs-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.cs-kpi{border:1px solid rgba(15,23,42,.06);border-radius:16px;background:#f8fafc;padding:14px 14px 12px 14px}
.cs-kpi-label{font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#64748b;margin-bottom:6px}
.cs-kpi-value{font-size:24px;font-weight:800;letter-spacing:-.04em;color:#102845}
.cs-kpi-sub{margin-top:5px;font-size:12px;line-height:1.45;color:#64748b}
.cs-search-bar{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:10px}
.cs-input{
    width:100%;box-sizing:border-box;border:1px solid rgba(15,23,42,.12);
    border-radius:12px;padding:11px 12px;background:#fff;color:#102845;font:inherit;
}
.cs-list{display:grid;gap:10px}
.cs-person-card{
    display:flex;align-items:center;justify-content:space-between;gap:14px;
    border:1px solid rgba(15,23,42,.06);border-radius:16px;background:#f8fafc;padding:12px;
}
.cs-person-main{display:flex;align-items:center;gap:12px;min-width:0}
.cs-avatar{
    width:42px;height:42px;border-radius:999px;display:flex;align-items:center;justify-content:center;
    font-size:13px;font-weight:800;color:#fff;background:linear-gradient(135deg,#12355f,#2767aa);
    box-shadow:0 2px 8px rgba(15,23,42,.12);flex:0 0 auto;
}
.cs-person-copy{min-width:0}
.cs-person-name{font-size:14px;font-weight:800;color:#102845;line-height:1.2}
.cs-person-meta{font-size:12px;color:#64748b;line-height:1.5}
.cs-chip{
    display:inline-flex;align-items:center;padding:6px 9px;border-radius:999px;
    background:rgba(18,53,95,.08);color:#12355f;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;
}
.cs-chip-success{background:rgba(22,101,52,.09);color:#166534}
.cs-chip-warning{background:rgba(161,98,7,.10);color:#a16207}
.cs-chip-danger{background:rgba(153,27,27,.08);color:#991b1b}
.cs-student-grid{display:grid;gap:14px}
.cs-student-card{
    border:1px solid rgba(15,23,42,.06);border-radius:18px;background:#fff;padding:16px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);
}
.cs-student-head{
    display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap;margin-bottom:12px;
}
.cs-student-main{display:flex;align-items:center;gap:12px;min-width:0}
.cs-progress-block{display:grid;gap:8px}
.cs-progress-label{font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#64748b}
.cs-progress-bar{height:12px;border-radius:999px;background:#e2e8f0;overflow:hidden}
.cs-progress-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,#12355f 0%, #1f5e99 55%, #38bdf8 100%)}
.cs-progress-sub{font-size:12px;color:#64748b;line-height:1.45}
.cs-snapshot-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:12px}
.cs-snapshot{
    border:1px solid rgba(15,23,42,.06);border-radius:14px;background:#f8fafc;padding:10px 11px;
}
.cs-snapshot-label{font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#64748b;margin-bottom:5px}
.cs-snapshot-value{font-size:17px;font-weight:800;color:#102845}
.cs-snapshot-sub{margin-top:4px;font-size:11px;color:#64748b;line-height:1.4}
.cs-empty{font-size:13px;color:#64748b;line-height:1.6}
@media (max-width: 1180px){
    .cs-grid-main,.cs-grid-two{grid-template-columns:1fr}
}
@media (max-width: 900px){
    .cs-kpis,.cs-snapshot-grid{grid-template-columns:1fr 1fr}
}
@media (max-width: 680px){
    .cs-kpis,.cs-snapshot-grid,.cs-search-bar{grid-template-columns:1fr}
}
</style>

<div class="cs-page">

    <?php if ($msg !== ''): ?>
        <div class="cs-alert <?php echo cs_h($msgType); ?>">
            <?php echo cs_h($msg); ?>
        </div>
    <?php endif; ?>

    <section class="card cs-hero">
        <div class="cs-eyebrow">Admin · Theory Training</div>
        <h1 class="cs-title">Cohort Students</h1>
        <div class="cs-sub">
            Manage the student roster for <strong><?php echo cs_h((string)$cohort['name']); ?></strong>.
            This page gives a proper searchable roster flow, keeps manual email add support, and shows quick progression snapshots for each enrolled student.
        </div>

        <div class="cs-top-actions">
            <a class="cs-btn cs-btn-secondary" href="/admin/cohorts.php">← Back to cohorts</a>
            <a class="cs-btn cs-btn-secondary" href="/admin/cohort.php?cohort_id=<?php echo (int)$cohortId; ?>">Open cohort overview</a>
        </div>
    </section>

    <section class="card cs-card-pad">
        <div class="cs-kpis">
            <div class="cs-kpi">
                <div class="cs-kpi-label">Program</div>
                <div class="cs-kpi-value"><?php echo cs_h((string)($cohort['program_key'] ?? '—')); ?></div>
                <div class="cs-kpi-sub">Current cohort program.</div>
            </div>
            <div class="cs-kpi">
                <div class="cs-kpi-label">Active students</div>
                <div class="cs-kpi-value"><?php echo (int)$activeStudentCount; ?></div>
                <div class="cs-kpi-sub">Students currently active in this cohort.</div>
            </div>
            <div class="cs-kpi">
                <div class="cs-kpi-label">Lesson scope</div>
                <div class="cs-kpi-value"><?php echo (int)$totalEnabledLessons; ?></div>
                <div class="cs-kpi-sub">Enabled scheduled lessons for snapshots.</div>
            </div>
            <div class="cs-kpi">
                <div class="cs-kpi-label">Cohort window</div>
                <div class="cs-kpi-value"><?php echo cs_h(cs_fmt_pretty((string)$cohort['start_date'])); ?></div>
                <div class="cs-kpi-sub">Ends <?php echo cs_h(cs_fmt_pretty((string)$cohort['end_date'])); ?> · <?php echo cs_h((string)$cohort['timezone']); ?></div>
            </div>
        </div>
    </section>

    <div class="cs-grid-main">
        <section class="card cs-card-pad">
            <h2 class="cs-section-title">Find and add students</h2>
            <p class="cs-section-sub">
                Search existing users by name or email and add them directly to this cohort. Manual email add remains available below for fast operational use.
            </p>

            <form method="get" class="cs-search-bar" style="margin-bottom:14px;">
                <input type="hidden" name="cohort_id" value="<?php echo (int)$cohortId; ?>">
                <input class="cs-input" type="text" name="q" value="<?php echo cs_h($search); ?>" placeholder="Search users by name or email">
                <button class="cs-btn" type="submit">Search</button>
                <a class="cs-btn cs-btn-secondary" href="/admin/cohort_students.php?cohort_id=<?php echo (int)$cohortId; ?>">Clear</a>
            </form>

            <div class="cs-list">
                <?php if (!$userSearchRows): ?>
                    <div class="cs-empty">No users found for this search.</div>
                <?php else: ?>
                    <?php foreach ($userSearchRows as $row): ?>
                        <?php $alreadyInCohort = (int)($row['already_in_cohort'] ?? 0) === 1; ?>
                        <div class="cs-person-card">
                            <div class="cs-person-main">
                                <div class="cs-avatar">
                                    <?php echo cs_h(cs_initials((string)($row['name'] ?? ''), (string)($row['email'] ?? ''))); ?>
                                </div>
                                <div class="cs-person-copy">
                                    <div class="cs-person-name"><?php echo cs_h((string)($row['name'] ?? '')); ?></div>
                                    <div class="cs-person-meta">
                                        <?php echo cs_h((string)($row['email'] ?? '')); ?>
                                        · <?php echo cs_h((string)($row['role'] ?? 'user')); ?>
                                    </div>
                                </div>
                            </div>

                            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end;">
                                <?php if ($alreadyInCohort): ?>
                                    <span class="cs-chip cs-chip-success">Already in cohort</span>
                                <?php else: ?>
                                    <form method="post" style="margin:0;">
                                        <input type="hidden" name="action" value="add_student">
                                        <input type="hidden" name="email" value="<?php echo cs_h((string)($row['email'] ?? '')); ?>">
                                        <button class="cs-btn" type="submit">Add</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div style="margin-top:18px;padding-top:16px;border-top:1px solid rgba(15,23,42,.06);">
                <h3 style="margin:0 0 8px 0;font-size:16px;font-weight:800;color:#102845;">Quick email add</h3>
                <p class="cs-section-sub" style="margin-bottom:10px;">
                    Keep the legacy add-by-email path for speed when the exact address is already known.
                </p>

                <form method="post" style="display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;">
                    <input type="hidden" name="action" value="add_student">
                    <input class="cs-input" name="email" placeholder="student@email.com">
                    <button class="cs-btn" type="submit">Add by email</button>
                </form>
            </div>
        </section>

        <section class="card cs-card-pad">
            <h2 class="cs-section-title">Roster snapshot</h2>
            <p class="cs-section-sub">
                Quick view of enrolled students with avatars and a lightweight progression snapshot pulled from lesson_activity.
            </p>

            <div class="cs-list">
                <?php if (!$students): ?>
                    <div class="cs-empty">No students enrolled in this cohort yet.</div>
                <?php else: ?>
                    <?php foreach ($students as $s): ?>
                        <?php
                        $uid = (int)$s['user_id'];
                        $stats = (array)($studentStats[$uid] ?? array());
                        $completedLessons = (int)($stats['completed_lessons'] ?? 0);
                        $activeLessons = (int)($stats['active_lessons'] ?? 0);
                        $blockedLessons = (int)($stats['blocked_lessons'] ?? 0);
                        $avgBestScore = isset($stats['avg_best_score']) && $stats['avg_best_score'] !== null
                            ? number_format((float)$stats['avg_best_score'], 0)
                            : '—';
                        $lastActivityAt = trim((string)($stats['last_activity_at'] ?? ''));
                        $progressPct = cs_percent($completedLessons, $totalEnabledLessons);
                        ?>
                        <div class="cs-student-card">
                            <div class="cs-student-head">
                                <div class="cs-student-main">
                                    <div class="cs-avatar">
                                        <?php echo cs_h(cs_initials((string)($s['name'] ?? ''), (string)($s['email'] ?? ''))); ?>
                                    </div>
                                    <div>
                                        <div class="cs-person-name"><?php echo cs_h((string)($s['name'] ?? '')); ?></div>
                                        <div class="cs-person-meta">
                                            <?php echo cs_h((string)($s['email'] ?? '')); ?>
                                            · <?php echo cs_h((string)($s['role'] ?? 'student')); ?>
                                            · Enrolled <?php echo cs_h(cs_fmt_pretty((string)($s['enrolled_at'] ?? ''))); ?>
                                        </div>
                                    </div>
                                </div>

                                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                    <span class="cs-chip <?php echo ((string)($s['status'] ?? '') === 'active') ? 'cs-chip-success' : 'cs-chip-warning'; ?>">
                                        <?php echo cs_h((string)($s['status'] ?? 'unknown')); ?>
                                    </span>

                                    <form method="post" style="margin:0;" onsubmit="return confirm('Remove this student from cohort?');">
                                        <input type="hidden" name="action" value="remove_student">
                                        <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                                        <button class="cs-btn cs-btn-secondary" type="submit">Remove</button>
                                    </form>
                                </div>
                            </div>

                            <div class="cs-progress-block">
                                <div class="cs-progress-label">Progress snapshot</div>
                                <div class="cs-progress-bar">
                                    <div class="cs-progress-fill" style="width: <?php echo (int)$progressPct; ?>%;"></div>
                                </div>
                                <div class="cs-progress-sub">
                                    <?php echo (int)$completedLessons; ?> of <?php echo (int)$totalEnabledLessons; ?> enabled lessons completed
                                    <?php if ($lastActivityAt !== ''): ?>
                                        · Last activity <?php echo cs_h(cs_fmt_pretty($lastActivityAt)); ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="cs-snapshot-grid">
                                <div class="cs-snapshot">
                                    <div class="cs-snapshot-label">Completed</div>
                                    <div class="cs-snapshot-value"><?php echo (int)$completedLessons; ?></div>
                                    <div class="cs-snapshot-sub">Lessons marked completed.</div>
                                </div>
                                <div class="cs-snapshot">
                                    <div class="cs-snapshot-label">Active</div>
                                    <div class="cs-snapshot-value"><?php echo (int)$activeLessons; ?></div>
                                    <div class="cs-snapshot-sub">Currently in progress flow.</div>
                                </div>
                                <div class="cs-snapshot">
                                    <div class="cs-snapshot-label">Blocked</div>
                                    <div class="cs-snapshot-value"><?php echo (int)$blockedLessons; ?></div>
                                    <div class="cs-snapshot-sub">Instructor, remediation, or deadline blocked.</div>
                                </div>
                                <div class="cs-snapshot">
                                    <div class="cs-snapshot-label">Avg score</div>
                                    <div class="cs-snapshot-value"><?php echo cs_h($avgBestScore); ?></div>
                                    <div class="cs-snapshot-sub">Average best_score across lesson_activity.</div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<?php cw_footer(); ?>