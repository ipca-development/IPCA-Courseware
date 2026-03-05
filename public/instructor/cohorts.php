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

function programs(PDO $pdo): array {
    return $pdo->query("SELECT id, program_key, sort_order FROM programs ORDER BY sort_order, id")
        ->fetchAll(PDO::FETCH_ASSOC);
}

function courses_for_program(PDO $pdo, int $programId): array {
    $st = $pdo->prepare("SELECT id, title, sort_order FROM courses WHERE program_id=? ORDER BY sort_order, id");
    $st->execute([$programId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function cohort_courses_enabled(PDO $pdo, int $cohortId): array {
    $st = $pdo->prepare("SELECT course_id, is_enabled FROM cohort_courses WHERE cohort_id=?");
    $st->execute([$cohortId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[(int)$r['course_id']] = (int)$r['is_enabled'];
    return $out;
}

/**
 * Upsert cohort_courses selection for cohort.
 * Ensures every course in program gets a row, enabled=1 if selected else 0.
 */
function save_course_selection(PDO $pdo, int $cohortId, int $programId, array $selectedCourseIds): void {
    $all = courses_for_program($pdo, $programId);
    $selected = [];
    foreach ($selectedCourseIds as $cid) $selected[(int)$cid] = true;

    $ins = $pdo->prepare("
      INSERT INTO cohort_courses (cohort_id, course_id, is_enabled)
      VALUES (?,?,?)
      ON DUPLICATE KEY UPDATE is_enabled=VALUES(is_enabled)
    ");

    foreach ($all as $c) {
        $cid = (int)$c['id'];
        $enabled = isset($selected[$cid]) ? 1 : 0;
        $ins->execute([$cohortId, $cid, $enabled]);
    }
}

/**
 * Because cohorts.course_id is NOT NULL in your DB, we must keep it populated.
 * We set it to the FIRST selected course in the program (lowest sort_order).
 */
function pick_primary_course(PDO $pdo, int $programId, array $selectedCourseIds): int {
    $selected = [];
    foreach ($selectedCourseIds as $cid) $selected[(int)$cid] = true;

    $all = courses_for_program($pdo, $programId);
    foreach ($all as $c) {
        $cid = (int)$c['id'];
        if (isset($selected[$cid])) return $cid;
    }
    // fallback: first course in program
    if ($all) return (int)$all[0]['id'];
    return 1; // last resort (should never happen if program has courses)
}

$msg = '';
$editId = (int)($_GET['edit_id'] ?? 0);
$editing = null;

if ($editId > 0) {
    $st = $pdo->prepare("SELECT * FROM cohorts WHERE id=? LIMIT 1");
    $st->execute([$editId]);
    $editing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_cohort') {
        $programId = (int)($_POST['program_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $start = trim((string)($_POST['start_date'] ?? ''));
        $end   = trim((string)($_POST['end_date'] ?? ''));
        $tz    = trim((string)($_POST['timezone'] ?? 'UTC'));
        $courseIds = $_POST['course_ids'] ?? [];
        if (!is_array($courseIds)) $courseIds = [];
        $courseIds = array_values(array_map('intval', $courseIds));

        if ($programId <= 0 || $name === '' || $start === '' || $end === '') {
            $msg = 'Missing required fields.';
        } else {
            // PRIMARY course_id required by schema
            $primaryCourseId = pick_primary_course($pdo, $programId, $courseIds);

            $ins = $pdo->prepare("
              INSERT INTO cohorts (program_id, course_id, name, start_date, end_date, timezone)
              VALUES (?,?,?,?,?,?)
            ");
            $ins->execute([$programId, $primaryCourseId, $name, $start, $end, $tz]);
            $cohortId = (int)$pdo->lastInsertId();

            // Save course selection (default: all checked)
            save_course_selection($pdo, $cohortId, $programId, $courseIds);

            redirect('/instructor/cohort.php?cohort_id='.$cohortId);
        }
    }

    if ($action === 'update_cohort') {
        $cohortId = (int)($_POST['cohort_id'] ?? 0);
        $programId = (int)($_POST['program_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $start = trim((string)($_POST['start_date'] ?? ''));
        $end   = trim((string)($_POST['end_date'] ?? ''));
        $tz    = trim((string)($_POST['timezone'] ?? 'UTC'));
        $courseIds = $_POST['course_ids'] ?? [];
        if (!is_array($courseIds)) $courseIds = [];
        $courseIds = array_values(array_map('intval', $courseIds));

        if ($cohortId <= 0 || $programId <= 0 || $name === '' || $start === '' || $end === '') {
            $msg = 'Missing required fields.';
        } else {
            $primaryCourseId = pick_primary_course($pdo, $programId, $courseIds);

            $up = $pdo->prepare("
              UPDATE cohorts
              SET program_id=?, course_id=?, name=?, start_date=?, end_date=?, timezone=?
              WHERE id=?
            ");
            $up->execute([$programId, $primaryCourseId, $name, $start, $end, $tz, $cohortId]);

            save_course_selection($pdo, $cohortId, $programId, $courseIds);

            redirect('/instructor/cohorts.php?edit_id='.$cohortId);
        }
    }
}

$programs = programs($pdo);

// List cohorts
$list = $pdo->query("
  SELECT co.id, co.name, co.start_date, co.end_date, co.timezone, p.program_key, co.program_id
  FROM cohorts co
  LEFT JOIN programs p ON p.id = co.program_id
  ORDER BY co.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

cw_header('Theory Training');
?>

<div class="card">
  <h2 style="margin:0 0 10px 0;">Theory Training Cohorts</h2>
  <p class="muted">
    Create a cohort by <strong>Program</strong>. Courses are pre-selected by default and can be adjusted anytime.
  </p>
  <?php if ($msg): ?>
    <div class="alert"><?= h($msg) ?></div>
  <?php endif; ?>
</div>

<?php
$formProgramId = $editing ? (int)$editing['program_id'] : (int)($programs[0]['id'] ?? 0);
$courses = ($formProgramId > 0) ? courses_for_program($pdo, $formProgramId) : [];
$enabledMap = $editing ? cohort_courses_enabled($pdo, (int)$editing['id']) : [];
?>

<div class="card">
  <h2 style="margin:0 0 10px 0;"><?= $editing ? 'Edit Cohort' : 'Create Cohort' ?></h2>

  <form method="post" class="form-grid">
    <input type="hidden" name="action" value="<?= $editing ? 'update_cohort' : 'create_cohort' ?>">
    <?php if ($editing): ?>
      <input type="hidden" name="cohort_id" value="<?= (int)$editing['id'] ?>">
    <?php endif; ?>

    <label>Program</label>
    <select name="program_id" id="program_id" required>
      <?php foreach ($programs as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id'] === $formProgramId) ? 'selected' : '' ?>>
          <?= h($p['program_key']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label>Cohort name</label>
    <input name="name" required value="<?= $editing ? h((string)$editing['name']) : '' ?>" placeholder="e.g. Private March 2026">

    <label>Start date</label>
    <input name="start_date" type="date" required value="<?= $editing ? h((string)$editing['start_date']) : '' ?>">

    <label>End date</label>
    <input name="end_date" type="date" required value="<?= $editing ? h((string)$editing['end_date']) : '' ?>">

    <label>Timezone</label>
    <select name="timezone" required>
      <?php
        $tzs = tz_options();
        $curTz = $editing ? (string)$editing['timezone'] : 'UTC';
        foreach ($tzs as $k=>$lbl):
      ?>
        <option value="<?= h($k) ?>" <?= ($k === $curTz) ? 'selected' : '' ?>><?= h($lbl) ?></option>
      <?php endforeach; ?>
    </select>

    <label>Courses in program</label>
    <div>
      <div class="muted" style="margin-bottom:8px;">
        Default: all courses checked. Uncheck any courses you don’t want in this cohort.
      </div>
      <div style="display:flex; flex-direction:column; gap:6px;">
        <?php if (!$courses): ?>
          <div class="muted">No courses found for this program.</div>
        <?php else: ?>
          <?php foreach ($courses as $c): ?>
            <?php
              $cid = (int)$c['id'];
              $checked = $editing ? ((int)($enabledMap[$cid] ?? 0) === 1) : true;
            ?>
            <label style="display:flex; gap:10px; align-items:center;">
              <input type="checkbox" name="course_ids[]" value="<?= $cid ?>" <?= $checked ? 'checked' : '' ?>>
              <span><?= h((string)$c['title']) ?></span>
            </label>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div class="muted" style="margin-top:10px;">
        Note: Your database requires <code>cohorts.course_id</code> (legacy). We automatically set it to the first selected course.
      </div>
    </div>

    <div></div>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <button class="btn" type="submit"><?= $editing ? 'Save Cohort' : 'Create Cohort' ?></button>
      <?php if ($editing): ?>
        <a class="btn btn-sm" href="/instructor/cohorts.php">Cancel</a>
        <a class="btn btn-sm" href="/instructor/cohort.php?cohort_id=<?= (int)$editing['id'] ?>">Open</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="muted" style="margin-top:10px;">
    If you change program selection, save once — then edit again to see the correct course list for the new program.
  </div>
</div>

<div class="card">
  <h2 style="margin:0 0 10px 0;">Existing Cohorts</h2>
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
          <a class="btn btn-sm" href="/instructor/cohorts.php?edit_id=<?= (int)$r['id'] ?>">Edit</a>
          <a class="btn btn-sm" href="/instructor/cohort.php?cohort_id=<?= (int)$r['id'] ?>">Open</a>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php cw_footer(); ?>