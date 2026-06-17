<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../src/bootstrap.php';
require_once __DIR__ . '/../../../../src/layout.php';
require_once __DIR__ . '/../../../../src/flight_training/AdminLogbookService.php';

cw_require_admin();

$user = cw_current_user($pdo);
$actorUserId = (int)($user['id'] ?? 0);
$service = new AdminLogbookService($pdo);
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $logbookId = $service->getOrCreateLogbook((int)($_POST['student_user_id'] ?? 0), null, $actorUserId);
        redirect('/admin/flight_training/logbooks/view.php?logbook_id=' . $logbookId);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$schemaReady = $service->schemaReady();
$missingTables = $service->missingTables();
$students = array();
$logbooks = array();
if ($schemaReady) {
    try {
        $students = $service->listStudents();
        $logbooks = $service->listLogbooks();
    } catch (Throwable $e) {
        $error = $error !== '' ? $error : $e->getMessage();
    }
}

cw_header('Flight Training · Admin Logbook');
?>

<style>
.alog-page{display:flex;flex-direction:column;gap:20px}
.alog-hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;padding:22px;border-radius:24px;background:linear-gradient(135deg,#102845,#1d4f89);color:#fff;box-shadow:0 18px 40px rgba(15,40,69,.18)}
.alog-kicker{margin:0 0 7px;font-size:11px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#bfdbfe}
.alog-title{margin:0;font-size:30px;line-height:1.1}
.alog-subtitle{margin:9px 0 0;max-width:760px;color:#dbeafe;font-size:14px;line-height:1.5}
.alog-card{border:1px solid rgba(15,23,42,.08);border-radius:22px;background:#fff;box-shadow:0 10px 26px rgba(15,23,42,.06);overflow:hidden}
.alog-card-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:18px 20px;border-bottom:1px solid rgba(15,23,42,.07)}
.alog-card-title{margin:0;font-size:17px;color:#102845}
.alog-card-text{margin:4px 0 0;color:#64748b;font-size:13px}
.alog-form{display:flex;gap:12px;align-items:flex-end;padding:18px 20px;flex-wrap:wrap}
.alog-field{display:flex;flex-direction:column;gap:6px;min-width:280px}
.alog-label{font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#64748b}
.alog-input{border:1px solid rgba(15,23,42,.13);border-radius:14px;padding:11px 12px;font:inherit;font-size:14px;color:#102845;background:#fff}
.alog-btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:999px;padding:9px 14px;font-size:13px;font-weight:900;text-decoration:none;cursor:pointer;white-space:nowrap}
.alog-btn--primary{background:#1d4f89;color:#fff}
.alog-btn--secondary{background:#eef2ff;color:#1e3a8a}
.alog-notice,.alog-error,.alog-warning{padding:13px 16px;border-radius:16px;font-size:13px;font-weight:800}
.alog-notice{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}
.alog-error{background:#fff1f2;color:#be123c;border:1px solid #fecdd3}
.alog-warning{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
.alog-table-wrap{overflow:auto}
.alog-table{width:100%;border-collapse:separate;border-spacing:0;min-width:880px}
.alog-table th{padding:12px 14px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#64748b;background:#f8fafc;border-bottom:1px solid rgba(15,23,42,.07)}
.alog-table td{padding:14px;border-bottom:1px solid rgba(15,23,42,.06);vertical-align:middle;color:#102845;font-size:14px}
.alog-muted{color:#64748b;font-size:12px}
.alog-empty{padding:34px 20px;text-align:center;color:#64748b}
</style>

<div class="alog-page">
  <header class="alog-hero">
    <div>
      <p class="alog-kicker">Admin · Flight Training</p>
      <h1 class="alog-title">Admin Logbook</h1>
      <p class="alog-subtitle">
        Build structured flight records first. These rows feed totals, requirements verification, IACRA/FAA 8710 summaries, and future form auto-fill.
      </p>
    </div>
    <a class="alog-btn alog-btn--secondary" href="/admin/api/admin_logbook_api.php?action=status">API: status</a>
  </header>

  <?php if ($notice !== ''): ?><div class="alog-notice"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alog-error"><?= h($error) ?></div><?php endif; ?>
  <?php if (!$schemaReady): ?>
    <div class="alog-warning">
      Apply <code>scripts/sql/2026_06_17_admin_logbook_requirements_foundation.sql</code> before using Admin Logbook.
      Missing tables: <?= h(implode(', ', $missingTables)) ?>.
    </div>
  <?php endif; ?>

  <section class="alog-card">
    <div class="alog-card-head">
      <div>
        <h2 class="alog-card-title">Open Student Logbook</h2>
        <p class="alog-card-text">Select a student to create or open their structured admin logbook.</p>
      </div>
    </div>
    <form class="alog-form" method="post">
      <label class="alog-field">
        <span class="alog-label">Student</span>
        <select class="alog-input" name="student_user_id" required<?= $schemaReady ? '' : ' disabled' ?>>
          <option value="">Select student</option>
          <?php foreach ($students as $student): ?>
            <option value="<?= (int)$student['id'] ?>"><?= h((string)($student['name'] ?: $student['email'])) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="alog-btn alog-btn--primary" type="submit"<?= $schemaReady ? '' : ' disabled' ?>>Open Logbook</button>
    </form>
  </section>

  <section class="alog-card">
    <div class="alog-card-head">
      <div>
        <h2 class="alog-card-title">Recent Logbooks</h2>
        <p class="alog-card-text">Structured flight records currently in the system.</p>
      </div>
    </div>
    <?php if ($logbooks === array()): ?>
      <div class="alog-empty"><?= $schemaReady ? 'No admin logbooks have been created yet.' : 'Logbook list unavailable until the migration is applied.' ?></div>
    <?php else: ?>
      <div class="alog-table-wrap">
        <table class="alog-table">
          <thead>
            <tr>
              <th>Student</th>
              <th>Status</th>
              <th>Entries</th>
              <th>Updated</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($logbooks as $logbook): ?>
              <tr>
                <td>
                  <strong><?= h((string)($logbook['student_name'] ?: 'Student #' . $logbook['student_user_id'])) ?></strong>
                  <div class="alog-muted"><?= h((string)($logbook['student_email'] ?? '')) ?></div>
                </td>
                <td><?= h((string)$logbook['status']) ?></td>
                <td><?= (int)$logbook['entry_count'] ?></td>
                <td><?= h((string)$logbook['updated_at']) ?></td>
                <td><a class="alog-btn alog-btn--secondary" href="/admin/flight_training/logbooks/view.php?logbook_id=<?= (int)$logbook['id'] ?>">Open</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php cw_footer(); ?>
