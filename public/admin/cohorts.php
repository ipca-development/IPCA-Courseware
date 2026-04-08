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

function ch_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function tz_options(): array {
    return [
        'UTC' => 'UTC',
        'America/Los_Angeles' => 'California (America/Los_Angeles)',
        'Europe/Brussels' => 'Belgium (Europe/Brussels)',
    ];
}

// Programs
$programs = $pdo->query("
    SELECT id, program_key, sort_order
    FROM programs
    ORDER BY sort_order, id
")->fetchAll(PDO::FETCH_ASSOC);

$msg = '';

// Create cohort
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_cohort') {
    $programId = (int)($_POST['program_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $start = trim((string)($_POST['start_date'] ?? ''));
    $end   = trim((string)($_POST['end_date'] ?? ''));
    $tz    = trim((string)($_POST['timezone'] ?? 'UTC'));

    if ($programId <= 0 || $name === '' || $start === '' || $end === '') {
        $msg = 'Missing required fields.';
    } else {
        $st = $pdo->prepare("SELECT id FROM courses WHERE program_id=? ORDER BY sort_order, id LIMIT 1");
        $st->execute([$programId]);
        $primaryCourseId = (int)($st->fetchColumn() ?: 0);

        if ($primaryCourseId <= 0) {
            $msg = 'No courses exist for this program yet.';
        } else {
            $ins = $pdo->prepare("
                INSERT INTO cohorts (program_id, course_id, name, start_date, end_date, timezone)
                VALUES (?,?,?,?,?,?)
            ");
            $ins->execute([$programId, $primaryCourseId, $name, $start, $end, $tz]);
            $cohortId = (int)$pdo->lastInsertId();

            // Enable all courses by default
            $all = $pdo->prepare("SELECT id FROM courses WHERE program_id=?");
            $all->execute([$programId]);
            $courseIds = $all->fetchAll(PDO::FETCH_COLUMN);

            $insCC = $pdo->prepare("
                INSERT INTO cohort_courses (cohort_id, course_id, is_enabled)
                VALUES (?,?,1)
                ON DUPLICATE KEY UPDATE is_enabled=VALUES(is_enabled)
            ");

            foreach ($courseIds as $cid) {
                $insCC->execute([$cohortId, (int)$cid]);
            }

            header('Location: /admin/cohort.php?cohort_id=' . $cohortId);
            exit;
        }
    }
}

// Load cohorts with student counts
$list = $pdo->query("
    SELECT 
        co.id,
        co.name,
        co.start_date,
        co.end_date,
        co.timezone,
        p.program_key,
        (
            SELECT COUNT(*) 
            FROM cohort_students cs 
            WHERE cs.cohort_id = co.id
        ) AS student_count
    FROM cohorts co
    LEFT JOIN programs p ON p.id = co.program_id
    ORDER BY co.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

cw_header('Theory Training');
?>

<style>
.cohorts-page{display:flex;flex-direction:column;gap:18px}
.cohorts-hero{padding:22px 24px}
.cohorts-title{margin:0;font-size:30px;font-weight:800;color:#102845}
.cohorts-sub{margin-top:8px;color:#64748b;font-size:14px}
.cohorts-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.cohorts-card{padding:20px 22px}
.cohorts-table{width:100%;border-collapse:collapse}
.cohorts-table th,.cohorts-table td{padding:12px;border-bottom:1px solid rgba(15,23,42,.06)}
.cohorts-table th{font-size:11px;text-transform:uppercase;color:#64748b}
.cohort-pill{
    padding:4px 10px;
    border-radius:999px;
    font-size:11px;
    font-weight:700;
    display:inline-block;
}
.cohort-pill.active{background:rgba(16,185,129,.15);color:#065f46}
.cohort-pill.draft{background:rgba(245,158,11,.15);color:#92400e}
.cohort-pill.empty{background:rgba(148,163,184,.15);color:#475569}
@media(max-width:1000px){
    .cohorts-grid{grid-template-columns:1fr}
}
</style>

<div class="cohorts-page">

    <section class="card cohorts-hero">
        <h1 class="cohorts-title">Theory Training Cohorts</h1>
        <div class="cohorts-sub">
            Manage training cohorts, scheduling, and student groups.  
            Schedules are versioned and safely published.
        </div>
    </section>

    <?php if ($msg): ?>
        <div class="card" style="padding:12px;color:#991b1b;">
            <?= ch_h($msg) ?>
        </div>
    <?php endif; ?>

    <div class="cohorts-grid">

        <!-- CREATE -->
        <section class="card cohorts-card">
            <h2>Create Cohort</h2>

            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="create_cohort">

                <label>Program</label>
                <select name="program_id" required>
                    <?php foreach ($programs as $p): ?>
                        <option value="<?= (int)$p['id'] ?>">
                            <?= ch_h($p['program_key']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Name</label>
                <input name="name" required placeholder="March 2026 Intake">

                <label>Start Date</label>
                <input type="date" name="start_date" required>

                <label>End Date</label>
                <input type="date" name="end_date" required>

                <label>Timezone</label>
                <select name="timezone">
                    <?php foreach (tz_options() as $k=>$v): ?>
                        <option value="<?= ch_h($k) ?>"><?= ch_h($v) ?></option>
                    <?php endforeach; ?>
                </select>

                <div></div>
                <button class="btn" type="submit">Create</button>
            </form>
        </section>

        <!-- LIST -->
        <section class="card cohorts-card">
            <h2>Existing Cohorts</h2>

            <?php if (!$list): ?>
                <div class="muted">No cohorts yet.</div>
            <?php else: ?>
                <table class="cohorts-table">
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Program</th>
                        <th>Students</th>
                        <th>Period</th>
                        <th>Status</th>
                        <th></th>
                    </tr>

                    <?php foreach ($list as $r): ?>

                        <?php
                        $status = 'draft';
                        if ((int)$r['student_count'] === 0) {
                            $status = 'empty';
                        } else {
                            $status = 'active';
                        }
                        ?>

                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <td><strong><?= ch_h($r['name']) ?></strong></td>
                            <td><?= ch_h($r['program_key'] ?? '-') ?></td>
                            <td><?= (int)$r['student_count'] ?></td>
                            <td>
                                <?= ch_h($r['start_date']) ?><br>
                                <span class="muted"><?= ch_h($r['end_date']) ?></span>
                            </td>
                            <td>
                                <span class="cohort-pill <?= $status ?>">
                                    <?= ucfirst($status) ?>
                                </span>
                            </td>
                            <td>
                                <a class="btn btn-sm" href="/admin/cohort.php?cohort_id=<?= (int)$r['id'] ?>">
                                    Open
                                </a>
                            </td>
                        </tr>

                    <?php endforeach; ?>
                </table>
            <?php endif; ?>

        </section>

    </div>

</div>

<?php cw_footer(); ?>