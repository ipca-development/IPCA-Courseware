<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'supervisor' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

function tz_options(): array {
    return [
        'UTC' => 'UTC',
        'America/Los_Angeles' => 'California (America/Los_Angeles)',
        'Europe/Brussels' => 'Belgium (Europe/Brussels)',
    ];
}

$programs = $pdo->query("SELECT id, program_key, sort_order FROM programs ORDER BY sort_order, id")
    ->fetchAll(PDO::FETCH_ASSOC);

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'create_cohort') {
    $programId = (int)($_POST['program_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $start = trim((string)($_POST['start_date'] ?? ''));
    $end   = trim((string)($_POST['end_date'] ?? ''));
    $tz    = trim((string)($_POST['timezone'] ?? 'UTC'));

    if ($programId <= 0 || $name === '' || $start === '' || $end === '') {
        $msg = 'Missing required fields.';
    } else {
        // cohorts.course_id is NOT NULL (legacy). We set it to FIRST course of program.
        $st = $pdo->prepare("SELECT id FROM courses WHERE program_id=? ORDER BY sort_order, id LIMIT 1");
        $st->execute([$programId]);
        $primaryCourseId = (int)($st->fetchColumn() ?: 0);
        if ($primaryCourseId <= 0) {
            $msg = 'No courses exist for this program yet. Create/import courses first.';
        } else {
            $ins = $pdo->prepare("
              INSERT INTO cohorts (program_id, course_id, name, start_date, end_date, timezone)
              VALUES (?,?,?,?,?,?)
            ");
            $ins->execute([$programId, $primaryCourseId, $name, $start, $end, $tz]);
            $cohortId = (int)$pdo->lastInsertId();

            // Optional: seed cohort_courses with ALL courses enabled by default
            $all = $pdo->prepare("SELECT id FROM courses WHERE program_id=? ORDER BY sort_order, id");
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

            redirect('/instructor/cohort.php?cohort_id='.$cohortId);
        }
    }
}

$list = $pdo->query("
  SELECT co.id, co.name, co.start_date, co.end_date, co.timezone, p.program_key
  FROM cohorts co
  LEFT JOIN programs p ON p.id=co.program_id
  ORDER BY co.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

cw_header('Theory Training');
?>
<div class="card">
  <h2 style="margin:0 0 10px 0;">Theory Training Cohorts</h2>
  <p class="muted">Create a cohort by <strong>Program</strong>. Course selection + scheduling happens inside the cohort.</p>

  <?php if ($msg): ?>
    <div class="alert"><?= h($msg) ?></div>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin:0 0 10px 0;">Create cohort</h2>

  <form method="post" class="form-grid">
    <input type="hidden" name="action" value="create_cohort">

    <label>Program</label>
    <select name="program_id" required>
      <?php foreach ($programs as $p): ?>
        <option value="<?= (int)$p['id'] ?>"><?= h((string)$p['program_key']) ?></option>
      <?php endforeach; ?>
    </select>

    <label>Cohort name</label>
    <input name="name" required placeholder="e.g. Private Ground School — March 2026">

    <label>Start date</label>
    <input name="start_date" type="date" required>

    <label>End date</label>
    <input name="end_date" type="date" required>

    <label>Timezone</label>
    <select name="timezone" required>
      <?php foreach (tz_options() as $k=>$lbl): ?>
        <option value="<?= h($k) ?>" <?= ($k==='UTC')?'selected':'' ?>><?= h($lbl) ?></option>
      <?php endforeach; ?>
    </select>

    <div></div>
    <button class="btn" type="submit">Create cohort</button>
  </form>
</div>

<div class="card">
  <h2 style="margin:0 0 10px 0;">Existing cohorts</h2>
  <table>
    <tr>
      <th>ID</th>
      <th>Program</th>
      <th>Name</th>
      <th>Start</th>
      <th>End</th>
      <th>Timezone</th>
      <th>Actions</th>
    </tr>
    <?php foreach ($list as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= h((string)($r['program_key'] ?? '—')) ?></td>
        <td><?= h((string)$r['name']) ?></td>
        <td><?= h((string)$r['start_date']) ?></td>
        <td><?= h((string)$r['end_date']) ?></td>
        <td><?= h((string)$r['timezone']) ?></td>
        <td style="white-space:nowrap;">
          <a class="btn btn-sm" href="/instructor/cohort.php?cohort_id=<?= (int)$r['id'] ?>">Open</a>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php cw_footer(); ?>