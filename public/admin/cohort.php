<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/schedule.php';

cw_require_login();

$u = cw_current_user($pdo);
if (($u['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

// use existing h() from layout.php

$cohortId = (int)($_GET['cohort_id'] ?? 0);
if ($cohortId <= 0) exit('Missing cohort_id');

$stmt = $pdo->prepare("SELECT co.*, p.program_key FROM cohorts co LEFT JOIN programs p ON p.id=co.program_id WHERE co.id=?");
$stmt->execute([$cohortId]);
$cohort = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cohort) exit('Not found');

$msg = '';
$preview = null;

/**
 * LIGHTWEIGHT VERSIONING TABLE (SAFE)
 * If not exists, create once manually:
 * 
 * CREATE TABLE cohort_schedule_versions (
 *   id BIGINT AUTO_INCREMENT PRIMARY KEY,
 *   cohort_id INT NOT NULL,
 *   version_no INT NOT NULL,
 *   is_published TINYINT(1) DEFAULT 0,
 *   policy_snapshot_json LONGTEXT,
 *   created_at DATETIME DEFAULT CURRENT_TIMESTAMP
 * );
 */

function get_next_version(PDO $pdo, int $cohortId): int {
    $st = $pdo->prepare("SELECT MAX(version_no) FROM cohort_schedule_versions WHERE cohort_id=?");
    $st->execute([$cohortId]);
    return ((int)$st->fetchColumn()) + 1;
}

/**
 * ACTIONS
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'preview_schedule') {

        $preview = cw_recalculate_cohort_deadlines($pdo, $cohortId);
        $msg = "Preview generated (NOT published).";

    } elseif ($action === 'publish_schedule') {

        $pdo->beginTransaction();

        try {
            $out = cw_recalculate_cohort_deadlines($pdo, $cohortId);

            $version = get_next_version($pdo, $cohortId);

            $policySnapshot = json_encode([
                'weekend_skip' => $_POST['weekend_skip'] ?? 'none',
                'cutoff_time' => $_POST['cutoff_time'] ?? '23:59:59'
            ]);

            $pdo->prepare("
                INSERT INTO cohort_schedule_versions
                (cohort_id, version_no, is_published, policy_snapshot_json)
                VALUES (?,?,1,?)
            ")->execute([$cohortId, $version, $policySnapshot]);

            $pdo->commit();

            $msg = "Schedule published (Version {$version}).";

        } catch (Throwable $e) {
            $pdo->rollBack();
            $msg = "Error: " . $e->getMessage();
        }
    }
}

/**
 * LOAD STUDENTS + PROGRESS SNAPSHOT
 */
$students = $pdo->prepare("
    SELECT u.id, u.name, u.email
    FROM cohort_students cs
    JOIN users u ON u.id = cs.user_id
    WHERE cs.cohort_id=?
");
$students->execute([$cohortId]);
$students = $students->fetchAll(PDO::FETCH_ASSOC);

/**
 * SIMPLE PROGRESS %
 */
function student_progress(PDO $pdo, int $userId, int $cohortId): int {
    $st = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN completion_status='completed' THEN 1 ELSE 0 END) as done,
            COUNT(*) as total
        FROM lesson_activity
        WHERE user_id=? AND cohort_id=?
    ");
    $st->execute([$userId, $cohortId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || (int)$r['total'] === 0) return 0;
    return (int)round(((int)$r['done'] / (int)$r['total']) * 100);
}

cw_header("Cohort");
?>

<style>
.cohort-page{display:flex;flex-direction:column;gap:18px}
.cohort-hero{padding:22px}
.cohort-title{font-size:28px;font-weight:800;color:#102845;margin:0}
.cohort-sub{color:#64748b;margin-top:6px}
.card-pad{padding:20px}

.student-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(260px,1fr));
    gap:14px;
}
.student-card{
    display:flex;
    gap:12px;
    padding:14px;
    border-radius:14px;
    border:1px solid rgba(15,23,42,.08);
    background:#fff;
}
.avatar{
    width:42px;height:42px;border-radius:50%;
    background:#12355f;color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-weight:800;
}
.progress-bar{
    height:6px;
    border-radius:6px;
    background:rgba(15,23,42,.08);
    overflow:hidden;
}
.progress-fill{
    height:100%;
    background:linear-gradient(90deg,#1e3c72,#2a5298);
}
</style>

<div class="cohort-page">

    <section class="card cohort-hero">
        <h1 class="cohort-title"><?= h($cohort['name']) ?></h1>
        <div class="cohort-sub">
            <?= h($cohort['program_key']) ?> • 
            <?= h($cohort['start_date']) ?> → <?= h($cohort['end_date']) ?>
        </div>
    </section>

    <?php if ($msg): ?>
        <div class="card card-pad"><?= h($msg) ?></div>
    <?php endif; ?>

    <!-- SCHEDULING -->
    <section class="card card-pad">
        <h2>Schedule</h2>

        <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
            <input type="hidden" name="action" value="preview_schedule">

            <label>Weekend handling</label>
            <select name="weekend_skip">
                <option value="none">No skip</option>
                <option value="sat_sun">Skip Sat+Sun</option>
                <option value="sun">Skip Sun only</option>
            </select>

            <label>Cutoff time</label>
            <input type="time" name="cutoff_time" value="23:59">

            <button class="btn">Preview</button>
        </form>

        <form method="post">
            <input type="hidden" name="action" value="publish_schedule">
            <button class="btn">Publish Schedule</button>
        </form>

        <?php if ($preview): ?>
            <div style="margin-top:14px;">
                <strong>Preview Summary</strong><br>
                Lessons: <?= (int)$preview['summary']['lessons_scheduled'] ?><br>
                Hours: <?= h($preview['summary']['total_study_hours']) ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- STUDENTS -->
    <section class="card card-pad">
        <h2>Students</h2>

        <div class="student-grid">

            <?php foreach ($students as $s): 
                $progress = student_progress($pdo, (int)$s['id'], $cohortId);
                $initial = strtoupper(substr($s['name'],0,1));
            ?>

                <div class="student-card">
                    <div class="avatar"><?= h($initial) ?></div>

                    <div style="flex:1;">
                        <div style="font-weight:800"><?= h($s['name']) ?></div>
                        <div class="muted"><?= h($s['email']) ?></div>

                        <div style="margin-top:8px;">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width:<?= $progress ?>%"></div>
                            </div>
                            <div class="muted"><?= $progress ?>%</div>
                        </div>
                    </div>
                </div>

            <?php endforeach; ?>

        </div>
    </section>

</div>

<?php cw_footer(); ?>