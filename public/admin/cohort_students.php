<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_login();

$u = cw_current_user($pdo);
if (($u['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

$cohortId = (int)($_GET['cohort_id'] ?? 0);
if ($cohortId <= 0) exit('Missing cohort_id');

/**
 * LOAD COHORT
 */
$stmt = $pdo->prepare("SELECT * FROM cohorts WHERE id=? LIMIT 1");
$stmt->execute([$cohortId]);
$cohort = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cohort) exit('Cohort not found');

$msg = '';

/**
 * ACTIONS
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($_POST['action'] === 'add_student') {
        $uid = (int)$_POST['user_id'];
        if ($uid > 0) {
            $pdo->prepare("
                INSERT INTO cohort_students (cohort_id, user_id, status, enrolled_at, created_at)
                VALUES (?, ?, 'active', NOW(), NOW())
                ON DUPLICATE KEY UPDATE status='active'
            ")->execute([$cohortId, $uid]);
            $msg = "Student added.";
        }
    }

    if ($_POST['action'] === 'remove_student') {
        $uid = (int)$_POST['user_id'];
        $pdo->prepare("DELETE FROM cohort_students WHERE cohort_id=? AND user_id=?")
            ->execute([$cohortId, $uid]);
        $msg = "Student removed.";
    }
}

/**
 * LOAD STUDENTS + PROJECTION SNAPSHOT
 */
$students = $pdo->prepare("
SELECT 
    u.id,
    u.name,
    u.email,
    u.role,
    cs.enrolled_at,

    COUNT(la.id) AS total_lessons,
    SUM(CASE WHEN la.completion_status='completed' THEN 1 ELSE 0 END) AS completed_lessons,
    MIN(CASE 
        WHEN la.completion_status <> 'completed' 
        THEN la.effective_deadline_utc 
        ELSE NULL 
    END) AS next_deadline

FROM cohort_students cs
JOIN users u ON u.id = cs.user_id
LEFT JOIN lesson_activity la 
    ON la.user_id = u.id AND la.cohort_id = cs.cohort_id

WHERE cs.cohort_id = ?

GROUP BY u.id
ORDER BY cs.enrolled_at ASC
");
$students->execute([$cohortId]);
$students = $students->fetchAll(PDO::FETCH_ASSOC);

/**
 * LOAD USERS FOR ADD PANEL
 */
$users = $pdo->query("SELECT id,name,email,role FROM users ORDER BY name ASC")
    ->fetchAll(PDO::FETCH_ASSOC);

cw_header('Cohort Students');
?>

<style>
.cs-page{display:flex;flex-direction:column;gap:18px}

.cs-card{padding:20px}

.cs-header{
    display:flex;align-items:center;gap:14px;
}

.cs-avatar{
    width:42px;height:42px;border-radius:50%;
    background:linear-gradient(135deg,#1e3c72,#2a5298);
    color:#fff;font-weight:800;
    display:flex;align-items:center;justify-content:center;
    font-size:14px;
}

.cs-name{font-weight:800;color:#102845}

.cs-email{font-size:12px;color:#64748b}

.cs-progress{
    height:6px;
    border-radius:6px;
    background:#e2e8f0;
    overflow:hidden;
    margin-top:6px;
}

.cs-progress-bar{
    height:100%;
    background:linear-gradient(90deg,#1e3c72,#2a5298);
}

.cs-risk{
    font-size:11px;
    font-weight:800;
    margin-top:4px;
}

.cs-risk.low{color:#16a34a}
.cs-risk.medium{color:#f59e0b}
.cs-risk.high{color:#dc2626}

.cs-user-card{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:14px;
}

.cs-left{display:flex;gap:12px;align-items:center}

.cs-right{
    display:flex;
    flex-direction:column;
    align-items:flex-end;
    min-width:180px;
}

.cs-btn{
    background:#12355f;
    color:#fff;
    border:none;
    padding:6px 10px;
    border-radius:8px;
    font-weight:700;
    cursor:pointer;
}

.cs-btn-remove{background:#991b1b}

.cs-search{
    width:100%;
    padding:10px;
    border-radius:10px;
    border:1px solid rgba(15,23,42,.12);
}
</style>

<div class="cs-page">

<div class="card cs-card">
    <h2><?= h($cohort['name']) ?></h2>
    <div class="cs-email">Cohort Students & Progress</div>
</div>

<?php if ($msg): ?>
<div class="card cs-card" style="background:#ecfeff;">
    <?= h($msg) ?>
</div>
<?php endif; ?>

<!-- ADD STUDENT -->
<div class="card cs-card">
    <input id="search" class="cs-search" placeholder="Search users..." onkeyup="filterUsers()">

    <div id="userList">
        <?php foreach ($users as $uRow): ?>
            <div class="cs-user-card user-item" data-search="<?= strtolower($uRow['name'].' '.$uRow['email']) ?>">
                <div><?= h($uRow['name']) ?> (<?= h($uRow['email']) ?>)</div>

                <form method="post">
                    <input type="hidden" name="action" value="add_student">
                    <input type="hidden" name="user_id" value="<?= (int)$uRow['id'] ?>">
                    <button class="cs-btn">Add</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- STUDENTS -->
<div class="cs-page">
<?php foreach ($students as $s): 

    $total = (int)$s['total_lessons'];
    $done  = (int)$s['completed_lessons'];

    $pct = $total > 0 ? round(($done/$total)*100) : 0;

    $risk = 'low';
    if (!empty($s['next_deadline'])) {
        $hours = (strtotime($s['next_deadline']) - time()) / 3600;

        if ($hours < 24) $risk = 'high';
        elseif ($hours < 48) $risk = 'medium';
    }

    $initials = strtoupper(substr($s['name'],0,1));
?>

<div class="card cs-card cs-user-card">

    <div class="cs-left">
        <div class="cs-avatar"><?= h($initials) ?></div>

        <div>
            <div class="cs-name"><?= h($s['name']) ?></div>
            <div class="cs-email"><?= h($s['email']) ?></div>
        </div>
    </div>

    <div class="cs-right">

        <div><?= $pct ?>%</div>

        <div class="cs-progress">
            <div class="cs-progress-bar" style="width:<?= $pct ?>%"></div>
        </div>

        <div class="cs-risk <?= $risk ?>">
            <?= strtoupper($risk) ?>
        </div>

    </div>

    <form method="post">
        <input type="hidden" name="action" value="remove_student">
        <input type="hidden" name="user_id" value="<?= (int)$s['id'] ?>">
        <button class="cs-btn cs-btn-remove">Remove</button>
    </form>

</div>

<?php endforeach; ?>
</div>

</div>

<script>
function filterUsers(){
    const q = document.getElementById('search').value.toLowerCase();
    document.querySelectorAll('.user-item').forEach(el=>{
        el.style.display = el.dataset.search.includes(q) ? 'flex' : 'none';
    });
}
</script>

<?php cw_footer(); ?>
