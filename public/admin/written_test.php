<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/written_test/bootstrap.php';

cw_require_login();

$u = cw_current_user($pdo);
if ((string)($u['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$actorUserId = (int)($u['id'] ?? 0);
$programSvc = new WrittenTestProgramService($pdo);
$allocationSvc = new WrittenTestAllocationService($pdo);
$policySvc = new WrittenTestPolicyService($pdo);
$accessSvc = new WrittenTestAccessService($pdo);

$message = '';
$messageType = 'success';

function wt_admin_redirect(string $msg = '', string $type = 'success', array $params = []): void
{
    $params['msg'] = $msg;
    $params['type'] = $type;
    header('Location: /admin/written_test.php?' . http_build_query($params));
    exit;
}

if (isset($_GET['msg'])) {
    $message = trim((string)$_GET['msg']);
    $messageType = trim((string)($_GET['type'] ?? 'success')) === 'error' ? 'error' : 'success';
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create_program') {
            $id = $programSvc->createProgram($_POST, $actorUserId);
            wt_admin_redirect('Written Test Preparation program created.', 'success', ['program_id' => $id]);
        }
        if ($action === 'update_program') {
            $programSvc->updateProgram((int)($_POST['program_id'] ?? 0), $_POST, $actorUserId);
            wt_admin_redirect('Program updated.', 'success', ['program_id' => (int)($_POST['program_id'] ?? 0)]);
        }
        if ($action === 'create_allocation') {
            $id = $allocationSvc->createAllocation($_POST, $actorUserId);
            wt_admin_redirect('Cohort allocation created. Publish a policy snapshot before activation.', 'success', ['allocation_id' => $id]);
        }
        if ($action === 'publish_policy') {
            $version = $policySvc->publishSnapshot((int)($_POST['allocation_id'] ?? 0), $actorUserId, (string)($_POST['change_summary'] ?? ''));
            wt_admin_redirect('Policy snapshot version ' . (int)($version['version_number'] ?? 0) . ' published.', 'success', ['allocation_id' => (int)($_POST['allocation_id'] ?? 0)]);
        }
        if ($action === 'activate_allocation') {
            $allocationSvc->activateAllocation((int)($_POST['allocation_id'] ?? 0), $actorUserId);
            wt_admin_redirect('Allocation activated.', 'success', ['allocation_id' => (int)($_POST['allocation_id'] ?? 0)]);
        }
        if ($action === 'suspend_allocation') {
            $allocationSvc->suspendAllocation((int)($_POST['allocation_id'] ?? 0), $actorUserId, (string)($_POST['reason'] ?? ''));
            wt_admin_redirect('Allocation suspended.', 'success', ['allocation_id' => (int)($_POST['allocation_id'] ?? 0)]);
        }
        if ($action === 'create_override') {
            $accessSvc->createOverride($_POST, $actorUserId);
            wt_admin_redirect('Access override recorded.', 'success', ['allocation_id' => (int)($_POST['allocation_id'] ?? 0), 'student_id' => (int)($_POST['student_id'] ?? 0)]);
        }
        if ($action === 'approve_access') {
            $accessSvc->approveAccess(
                (int)($_POST['allocation_id'] ?? 0),
                (int)($_POST['student_id'] ?? 0),
                (string)($_POST['approval_type'] ?? 'instructor'),
                $actorUserId,
                (string)($_POST['reason'] ?? '')
            );
            wt_admin_redirect('Access approval recorded.', 'success', ['allocation_id' => (int)($_POST['allocation_id'] ?? 0), 'student_id' => (int)($_POST['student_id'] ?? 0)]);
        }
        throw new RuntimeException('Unknown Written Test Preparation action.');
    }
} catch (Throwable $e) {
    $message = $e->getMessage();
    $messageType = 'error';
}

$programs = $programSvc->listPrograms(true);
$allocations = $allocationSvc->listAllocations();
$policyDefinitions = $policySvc->definitions();

$selectedProgramId = (int)($_GET['program_id'] ?? 0);
$selectedAllocationId = (int)($_GET['allocation_id'] ?? 0);
$selectedStudentId = (int)($_GET['student_id'] ?? 0);

$selectedProgram = $selectedProgramId > 0 ? $programSvc->getProgram($selectedProgramId) : ($programs[0] ?? null);
$selectedAllocation = $selectedAllocationId > 0 ? $allocationSvc->getAllocation($selectedAllocationId) : ($allocations[0] ?? null);
if ($selectedAllocation && $selectedAllocationId <= 0) {
    $selectedAllocationId = (int)$selectedAllocation['id'];
}

$cohorts = $pdo->query("
    SELECT co.id, co.name, p.program_key
    FROM cohorts co
    LEFT JOIN programs p ON p.id = co.program_id
    ORDER BY co.name ASC, co.id DESC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$courses = $pdo->query("
    SELECT c.id, c.title, p.program_key
    FROM courses c
    LEFT JOIN programs p ON p.id = c.program_id
    ORDER BY p.sort_order ASC, c.sort_order ASC, c.title ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$studentsForAllocation = [];
$studentState = null;
if ($selectedAllocationId > 0) {
    $st = $pdo->prepare("
        SELECT u.id, COALESCE(NULLIF(TRIM(u.name), ''), u.email) AS label, u.email
        FROM cohort_students cs
        JOIN users u ON u.id = cs.user_id
        JOIN cohort_written_test_allocations a ON a.cohort_id = cs.cohort_id
        WHERE a.id = ?
        ORDER BY label ASC, u.id ASC
    ");
    $st->execute([$selectedAllocationId]);
    $studentsForAllocation = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($selectedStudentId <= 0 && $studentsForAllocation) {
        $selectedStudentId = (int)$studentsForAllocation[0]['id'];
    }
    if ($selectedStudentId > 0) {
        $studentState = $accessSvc->evaluate($selectedStudentId, $selectedAllocationId);
    }
}

cw_header('Written Test Preparation');
?>
<style>
  .wt-admin{display:flex;flex-direction:column;gap:18px}
  .wt-admin-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  .wt-admin-card{padding:20px 22px}
  .wt-admin-title{margin:0;font-size:22px;color:#152235;letter-spacing:-.02em}
  .wt-admin-muted{margin-top:7px;color:#5b6d85;font-size:14px;line-height:1.5}
  .wt-admin-table{width:100%;border-collapse:collapse;margin-top:12px;font-size:13px}
  .wt-admin-table th,.wt-admin-table td{padding:9px 8px;border-bottom:1px solid rgba(15,23,42,.08);text-align:left;vertical-align:top}
  .wt-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px}
  .wt-form.full{grid-template-columns:1fr}
  .wt-field label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:#60718b;font-weight:800;margin-bottom:6px}
  .wt-field input,.wt-field select,.wt-field textarea{width:100%;box-sizing:border-box;border:1px solid rgba(15,23,42,.14);border-radius:11px;padding:10px 11px;font:inherit}
  .wt-field textarea{min-height:78px}
  .wt-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:12px}
  .wt-btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:0 13px;border-radius:11px;border:1px solid rgba(15,23,42,.12);background:#f4f7fb;color:#152235;text-decoration:none;font-size:13px;font-weight:800;cursor:pointer}
  .wt-btn.primary{background:#12355f;color:#fff;border-color:#12355f}
  .wt-btn.warn{background:#fff7ed;color:#9a3412;border-color:#fed7aa}
  .wt-msg{padding:12px 14px;border-radius:14px;font-weight:800}
  .wt-msg.success{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}
  .wt-msg.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
  .wt-pill{display:inline-flex;padding:5px 8px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.08em}
  .wt-req{padding:10px 12px;border-radius:13px;background:#f8fbfd;border:1px solid rgba(15,23,42,.06);margin-top:8px}
  .wt-req.ok{background:#f0fdf4}
  .wt-req.no{background:#fff7ed}
  @media (max-width:1000px){.wt-admin-grid,.wt-form{grid-template-columns:1fr}}
</style>

<div class="wt-admin">
  <?php if ($message !== ''): ?>
    <div class="wt-msg <?= h($messageType) ?>"><?= h($message) ?></div>
  <?php endif; ?>

  <section class="card wt-admin-card">
    <h1 class="wt-admin-title">Ground School Training &rarr; Written Test Preparation</h1>
    <div class="wt-admin-muted">
      Phase 1 manages programs, cohort allocations, immutable policy snapshots, approvals, overrides, and access diagnostics.
      Question banks, attempts, mastery, mock exams, supervised exam delivery, and analytics are intentionally deferred.
    </div>
  </section>

  <div class="wt-admin-grid">
    <section class="card wt-admin-card">
      <h2 class="wt-admin-title">Programs</h2>
      <table class="wt-admin-table">
        <thead><tr><th>Name</th><th>Authority</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($programs as $program): ?>
          <tr>
            <td><?= h((string)$program['display_name']) ?><br><span class="wt-admin-muted"><?= h((string)$program['program_key']) ?></span></td>
            <td><?= h((string)$program['authority']) ?><br><?= h((string)($program['certificate_type'] ?? '')) ?></td>
            <td><span class="wt-pill"><?= h((string)$program['program_status']) ?></span><br><?= h((string)$program['feature_availability_state']) ?></td>
            <td><a class="wt-btn" href="/admin/written_test.php?program_id=<?= (int)$program['id'] ?>">Edit</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <section class="card wt-admin-card">
      <h2 class="wt-admin-title"><?= $selectedProgram ? 'Edit Program' : 'Create Program' ?></h2>
      <form method="post" class="wt-form">
        <input type="hidden" name="action" value="<?= $selectedProgram ? 'update_program' : 'create_program' ?>">
        <?php if ($selectedProgram): ?><input type="hidden" name="program_id" value="<?= (int)$selectedProgram['id'] ?>"><?php endif; ?>
        <div class="wt-field">
          <label>Program Key</label>
          <input name="program_key" value="<?= h((string)($selectedProgram['program_key'] ?? '')) ?>" <?= $selectedProgram ? 'readonly' : '' ?> placeholder="faa_private_pilot_written_test_prep">
        </div>
        <div class="wt-field">
          <label>Display Name</label>
          <input name="display_name" value="<?= h((string)($selectedProgram['display_name'] ?? '')) ?>">
        </div>
        <div class="wt-field">
          <label>Authority</label>
          <input name="authority" value="<?= h((string)($selectedProgram['authority'] ?? 'FAA')) ?>">
        </div>
        <div class="wt-field">
          <label>Certificate</label>
          <input name="certificate_type" value="<?= h((string)($selectedProgram['certificate_type'] ?? '')) ?>">
        </div>
        <div class="wt-field">
          <label>Related Course</label>
          <select name="related_course_id">
            <option value="0">No default course scope</option>
            <?php foreach ($courses as $course): ?>
              <option value="<?= (int)$course['id'] ?>" <?= (int)($selectedProgram['related_course_id'] ?? 0) === (int)$course['id'] ? 'selected' : '' ?>>
                <?= h((string)$course['program_key']) ?> · <?= h((string)$course['title']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="wt-field">
          <label>Program Status</label>
          <select name="program_status">
            <?php foreach (['draft','active','suspended','retired'] as $status): ?>
              <option value="<?= h($status) ?>" <?= (string)($selectedProgram['program_status'] ?? 'draft') === $status ? 'selected' : '' ?>><?= h($status) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="wt-field">
          <label>Feature Availability</label>
          <select name="feature_availability_state">
            <?php foreach (['disabled','preview','available'] as $availability): ?>
              <option value="<?= h($availability) ?>" <?= (string)($selectedProgram['feature_availability_state'] ?? 'disabled') === $availability ? 'selected' : '' ?>><?= h($availability) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="wt-field" style="grid-column:1/-1;">
          <label>Description</label>
          <textarea name="description"><?= h((string)($selectedProgram['description'] ?? '')) ?></textarea>
        </div>
        <div class="wt-actions" style="grid-column:1/-1;">
          <button class="wt-btn primary" type="submit"><?= $selectedProgram ? 'Save Program' : 'Create Program' ?></button>
          <?php if ($selectedProgram): ?><a class="wt-btn" href="/admin/written_test.php">New Program</a><?php endif; ?>
        </div>
      </form>
    </section>
  </div>

  <div class="wt-admin-grid">
    <section class="card wt-admin-card">
      <h2 class="wt-admin-title">Cohort Allocations</h2>
      <table class="wt-admin-table">
        <thead><tr><th>Cohort</th><th>Program</th><th>Status</th><th>Policy</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($allocations as $allocation): ?>
          <tr>
            <td><?= h((string)$allocation['cohort_name']) ?></td>
            <td><?= h((string)$allocation['written_test_program_name']) ?></td>
            <td><span class="wt-pill"><?= h((string)$allocation['allocation_status']) ?></span></td>
            <td><?= !empty($allocation['current_policy_version_number']) ? 'v' . (int)$allocation['current_policy_version_number'] : 'Not published' ?></td>
            <td><a class="wt-btn" href="/admin/written_test.php?allocation_id=<?= (int)$allocation['id'] ?>">Manage</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <section class="card wt-admin-card">
      <h2 class="wt-admin-title">Create Allocation</h2>
      <form method="post" class="wt-form">
        <input type="hidden" name="action" value="create_allocation">
        <div class="wt-field">
          <label>Cohort</label>
          <select name="cohort_id" required>
            <?php foreach ($cohorts as $cohort): ?>
              <option value="<?= (int)$cohort['id'] ?>"><?= h((string)$cohort['name']) ?> · <?= h((string)$cohort['program_key']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="wt-field">
          <label>Program</label>
          <select name="written_test_program_id" required>
            <?php foreach ($programs as $program): ?>
              <option value="<?= (int)$program['id'] ?>"><?= h((string)$program['display_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="wt-field">
          <label>Scoped Course</label>
          <select name="related_course_id">
            <option value="0">Use program default/all cohort lessons</option>
            <?php foreach ($courses as $course): ?>
              <option value="<?= (int)$course['id'] ?>"><?= h((string)$course['program_key']) ?> · <?= h((string)$course['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="wt-field">
          <label>Effective Start</label>
          <input type="date" name="effective_start_at">
        </div>
        <div class="wt-field">
          <label>Effective End</label>
          <input type="date" name="effective_end_at">
        </div>
        <div class="wt-actions" style="grid-column:1/-1;"><button class="wt-btn primary" type="submit">Create Draft Allocation</button></div>
      </form>
    </section>
  </div>

  <?php if ($selectedAllocation): ?>
    <section class="card wt-admin-card">
      <h2 class="wt-admin-title">Selected Allocation</h2>
      <div class="wt-admin-muted">
        <?= h((string)$selectedAllocation['cohort_name']) ?> · <?= h((string)$selectedAllocation['written_test_program_name']) ?>
        · status <strong><?= h((string)$selectedAllocation['allocation_status']) ?></strong>
        · policy <strong><?= !empty($selectedAllocation['current_policy_version_number']) ? 'v' . (int)$selectedAllocation['current_policy_version_number'] : 'not published' ?></strong>
      </div>
      <div class="wt-actions">
        <form method="post">
          <input type="hidden" name="action" value="publish_policy">
          <input type="hidden" name="allocation_id" value="<?= (int)$selectedAllocation['id'] ?>">
          <input type="hidden" name="change_summary" value="Published from Written Test Preparation Phase 1 admin foundation.">
          <button class="wt-btn primary" type="submit">Publish Immutable Policy Snapshot</button>
        </form>
        <form method="post">
          <input type="hidden" name="action" value="activate_allocation">
          <input type="hidden" name="allocation_id" value="<?= (int)$selectedAllocation['id'] ?>">
          <button class="wt-btn primary" type="submit">Activate Allocation</button>
        </form>
        <form method="post" style="display:flex;gap:8px;align-items:center;">
          <input type="hidden" name="action" value="suspend_allocation">
          <input type="hidden" name="allocation_id" value="<?= (int)$selectedAllocation['id'] ?>">
          <input name="reason" placeholder="Suspension reason" required style="min-height:38px;border-radius:10px;border:1px solid rgba(15,23,42,.14);padding:0 10px;">
          <button class="wt-btn warn" type="submit">Suspend</button>
        </form>
      </div>
    </section>

    <div class="wt-admin-grid">
      <section class="card wt-admin-card">
        <h2 class="wt-admin-title">Student Access Diagnostics</h2>
        <form method="get" class="wt-form">
          <input type="hidden" name="allocation_id" value="<?= (int)$selectedAllocation['id'] ?>">
          <div class="wt-field">
            <label>Student</label>
            <select name="student_id" onchange="this.form.submit()">
              <?php foreach ($studentsForAllocation as $student): ?>
                <option value="<?= (int)$student['id'] ?>" <?= $selectedStudentId === (int)$student['id'] ? 'selected' : '' ?>>
                  <?= h((string)$student['label']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="wt-actions"><button class="wt-btn" type="submit">Refresh</button></div>
        </form>
        <?php if ($studentState): ?>
          <div class="wt-admin-muted">State: <strong><?= h((string)$studentState['state_label']) ?></strong></div>
          <?php foreach ((array)($studentState['requirements'] ?? []) as $req): ?>
            <div class="wt-req <?= !empty($req['met']) ? 'ok' : 'no' ?>">
              <strong><?= h((string)$req['label']) ?></strong><br>
              <?= h((string)$req['detail']) ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>

      <section class="card wt-admin-card">
        <h2 class="wt-admin-title">Overrides and Approvals</h2>
        <form method="post" class="wt-form">
          <input type="hidden" name="action" value="create_override">
          <input type="hidden" name="allocation_id" value="<?= (int)$selectedAllocation['id'] ?>">
          <input type="hidden" name="student_id" value="<?= (int)$selectedStudentId ?>">
          <div class="wt-field">
            <label>Scope</label>
            <select name="override_scope">
              <option value="student">Selected student</option>
              <option value="cohort">Whole cohort</option>
            </select>
          </div>
          <div class="wt-field">
            <label>Requirement Key</label>
            <select name="requirement_key">
              <option value="all_access_requirements">All access requirements</option>
              <?php foreach ((array)($studentState['requirements'] ?? []) as $req): ?>
                <option value="<?= h((string)$req['key']) ?>"><?= h((string)$req['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="wt-field">
            <label>Action</label>
            <select name="override_action">
              <option value="waive">Waive</option>
              <option value="satisfy">Satisfy</option>
              <option value="deny">Deny</option>
            </select>
          </div>
          <div class="wt-field">
            <label>Expires At</label>
            <input type="date" name="expires_at">
          </div>
          <div class="wt-field" style="grid-column:1/-1;">
            <label>Reason</label>
            <textarea name="reason" required></textarea>
          </div>
          <div class="wt-actions" style="grid-column:1/-1;"><button class="wt-btn primary" type="submit">Record Override</button></div>
        </form>

        <form method="post" class="wt-actions">
          <input type="hidden" name="action" value="approve_access">
          <input type="hidden" name="allocation_id" value="<?= (int)$selectedAllocation['id'] ?>">
          <input type="hidden" name="student_id" value="<?= (int)$selectedStudentId ?>">
          <select name="approval_type" style="min-height:38px;border-radius:10px;border:1px solid rgba(15,23,42,.14);padding:0 10px;">
            <option value="instructor">Instructor approval</option>
            <option value="administrator">Administrator approval</option>
          </select>
          <input name="reason" placeholder="Approval reason" style="min-height:38px;border-radius:10px;border:1px solid rgba(15,23,42,.14);padding:0 10px;">
          <button class="wt-btn primary" type="submit">Approve</button>
        </form>
      </section>
    </div>
  <?php endif; ?>

  <section class="card wt-admin-card">
    <h2 class="wt-admin-title">Phase 1 Policy Definitions</h2>
    <table class="wt-admin-table">
      <thead><tr><th>Key</th><th>Type</th><th>Default</th><th>Description</th></tr></thead>
      <tbody>
      <?php foreach ($policyDefinitions as $definition): ?>
        <tr>
          <td><?= h((string)$definition['policy_key']) ?></td>
          <td><?= h((string)$definition['value_type']) ?></td>
          <td><?= h((string)$definition['default_value_text']) ?></td>
          <td><?= h((string)($definition['description_text'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>

<?php cw_footer(); ?>
