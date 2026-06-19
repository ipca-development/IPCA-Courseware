<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../src/bootstrap.php';
require_once __DIR__ . '/../../../../src/layout.php';
require_once __DIR__ . '/../../../../src/flight_training/FormInstanceService.php';

cw_require_admin();

$user = cw_current_user($pdo);
$actorUserId = (int)($user['id'] ?? 0);
$service = new FormInstanceService($pdo);
$notice = '';
$error = '';

if (isset($_GET['sent'])) {
    $notice = 'Form packet sent to internal inboxes.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $instanceId = $service->createAndSend(array(
            'template_id' => (int)($_POST['template_id'] ?? 0),
            'student_user_id' => (int)($_POST['student_user_id'] ?? 0),
            'instructor_user_id' => (int)($_POST['instructor_user_id'] ?? 0),
            'title' => (string)($_POST['title'] ?? ''),
        ), $actorUserId);
        redirect('/admin/flight_training/forms/send.php?sent=1&instance_id=' . $instanceId);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$schemaReady = $service->schemaReady();
$missingTables = $service->missingTables();
$templates = array();
$students = array();
$instructors = array();
$instances = array();
if ($schemaReady) {
    try {
        $templates = $service->listSendableTemplates();
        $students = $service->listInternalUsers(array('student'));
        $instructors = $service->listInternalUsers(array('instructor', 'supervisor', 'chief_instructor', 'admin'));
        $instances = $service->listAdminInstances();
    } catch (Throwable $e) {
        $error = $error !== '' ? $error : $e->getMessage();
    }
}

function ftfs_date(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }
    $ts = strtotime($value);
    return $ts ? date('M j, Y H:i', $ts) : $value;
}

function ftfs_badge(string $status): string
{
    $status = strtolower(trim($status));
    return match ($status) {
        'completed' => 'ftfs-badge ftfs-badge--ok',
        'in_progress', 'opened' => 'ftfs-badge ftfs-badge--info',
        'sent', 'pending' => 'ftfs-badge ftfs-badge--warn',
        'cancelled', 'declined' => 'ftfs-badge ftfs-badge--danger',
        default => 'ftfs-badge',
    };
}

cw_header('Flight Training · Send Form Packet');
?>

<style>
.ftfs-page{display:flex;flex-direction:column;gap:20px}
.ftfs-hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;padding:22px;border-radius:24px;background:linear-gradient(135deg,#102845,#1d4f89);color:#fff;box-shadow:0 18px 40px rgba(15,40,69,.18)}
.ftfs-kicker{margin:0 0 7px;font-size:11px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#bfdbfe}
.ftfs-title{margin:0;font-size:30px;line-height:1.1}
.ftfs-subtitle{margin:9px 0 0;max-width:760px;color:#dbeafe;font-size:14px;line-height:1.5}
.ftfs-card{border:1px solid rgba(15,23,42,.08);border-radius:22px;background:#fff;box-shadow:0 10px 26px rgba(15,23,42,.06);overflow:hidden}
.ftfs-card-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:18px 20px;border-bottom:1px solid rgba(15,23,42,.07)}
.ftfs-card-title{margin:0;font-size:17px;color:#102845}
.ftfs-card-text{margin:4px 0 0;color:#64748b;font-size:13px}
.ftfs-grid{display:grid;grid-template-columns:1.2fr 1fr 1fr;gap:12px;padding:18px 20px}
.ftfs-field{display:flex;flex-direction:column;gap:6px}
.ftfs-field--wide{grid-column:1 / -1}
.ftfs-label{font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#64748b}
.ftfs-input,.ftfs-select{width:100%;box-sizing:border-box;border:1px solid rgba(15,23,42,.13);border-radius:14px;padding:11px 12px;font:inherit;font-size:14px;color:#102845;background:#fff}
.ftfs-actions{display:flex;gap:8px;align-items:center;justify-content:flex-end;padding:0 20px 18px}
.ftfs-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:0;border-radius:999px;padding:9px 14px;font-size:13px;font-weight:900;text-decoration:none;cursor:pointer;white-space:nowrap}
.ftfs-btn--primary{background:#1d4f89;color:#fff}
.ftfs-btn--secondary{background:#eef2ff;color:#1e3a8a}
.ftfs-notice,.ftfs-error,.ftfs-warning{padding:13px 16px;border-radius:16px;font-size:13px;font-weight:800}
.ftfs-notice{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}
.ftfs-error{background:#fff1f2;color:#be123c;border:1px solid #fecdd3}
.ftfs-warning{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
.ftfs-table-wrap{overflow:auto}
.ftfs-table{width:100%;border-collapse:separate;border-spacing:0;min-width:980px}
.ftfs-table th{padding:12px 14px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#64748b;background:#f8fafc;border-bottom:1px solid rgba(15,23,42,.07)}
.ftfs-table td{padding:14px;border-bottom:1px solid rgba(15,23,42,.06);vertical-align:middle;color:#102845;font-size:14px}
.ftfs-muted{color:#64748b;font-size:12px}
.ftfs-badge{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;background:#f1f5f9;color:#334155}
.ftfs-badge--ok{background:#dcfce7;color:#166534}
.ftfs-badge--info{background:#dbeafe;color:#1e40af}
.ftfs-badge--warn{background:#fef3c7;color:#92400e}
.ftfs-badge--danger{background:#fff1f2;color:#be123c}
@media (max-width:900px){.ftfs-hero{flex-direction:column}.ftfs-grid{grid-template-columns:1fr}.ftfs-actions{justify-content:flex-start;flex-wrap:wrap}}
</style>

<div class="ftfs-page">
  <header class="ftfs-hero">
    <div>
      <p class="ftfs-kicker">Admin · Flight Training · Forms</p>
      <h1 class="ftfs-title">Send Form Packet</h1>
      <p class="ftfs-subtitle">Create an internal copy of a form template and place fill/sign tasks in the student and instructor inboxes.</p>
    </div>
    <a class="ftfs-btn ftfs-btn--secondary" href="/admin/flight_training/forms/index.php">Back to Form Manager</a>
  </header>

  <?php if ($notice !== ''): ?>
    <div class="ftfs-notice"><?= h($notice) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="ftfs-error"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if (!$schemaReady): ?>
    <div class="ftfs-warning">
      Apply <code>scripts/sql/2026_06_19_flight_training_form_instances_inbox.sql</code> before sending form packets.
      Missing tables: <?= h(implode(', ', $missingTables)) ?>.
    </div>
  <?php endif; ?>

  <section class="ftfs-card">
    <div class="ftfs-card-head">
      <div>
        <h2 class="ftfs-card-title">New Internal Packet</h2>
        <p class="ftfs-card-text">The packet auto-fills known fields and creates inbox tasks for both internal recipients.</p>
      </div>
    </div>
    <form method="post">
      <div class="ftfs-grid">
        <label class="ftfs-field">
          <span class="ftfs-label">Template</span>
          <select class="ftfs-select" name="template_id" required>
            <option value="">Choose template</option>
            <?php foreach ($templates as $template): ?>
              <option value="<?= (int)$template['id'] ?>">
                <?= h((string)$template['title']) ?> · v<?= h((string)$template['version_label']) ?> · <?= (int)$template['field_count'] ?> fields
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="ftfs-field">
          <span class="ftfs-label">Student Recipient</span>
          <select class="ftfs-select" name="student_user_id" required>
            <option value="">Choose student</option>
            <?php foreach ($students as $student): ?>
              <option value="<?= (int)$student['id'] ?>"><?= h((string)($student['name'] ?: $student['email'])) ?> · <?= h((string)$student['email']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="ftfs-field">
          <span class="ftfs-label">Instructor Recipient</span>
          <select class="ftfs-select" name="instructor_user_id" required>
            <option value="">Choose instructor</option>
            <?php foreach ($instructors as $instructor): ?>
              <option value="<?= (int)$instructor['id'] ?>"><?= h((string)($instructor['name'] ?: $instructor['email'])) ?> · <?= h((string)$instructor['email']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="ftfs-field ftfs-field--wide">
          <span class="ftfs-label">Packet Title</span>
          <input class="ftfs-input" name="title" placeholder="Leave blank to use template + student name">
        </label>
      </div>
      <div class="ftfs-actions">
        <button class="ftfs-btn ftfs-btn--primary" type="submit"<?= $schemaReady ? '' : ' disabled' ?>>Send to Internal Inboxes</button>
      </div>
    </form>
  </section>

  <section class="ftfs-card">
    <div class="ftfs-card-head">
      <div>
        <h2 class="ftfs-card-title">Sent Packets</h2>
        <p class="ftfs-card-text">Internal form packets created from templates.</p>
      </div>
      <span class="ftfs-badge"><?= (int)count($instances) ?> total</span>
    </div>
    <div class="ftfs-table-wrap">
      <table class="ftfs-table">
        <thead>
          <tr>
            <th>Packet</th>
            <th>Student</th>
            <th>Instructor</th>
            <th>Status</th>
            <th>Recipients</th>
            <th>Sent</th>
            <th>Completed</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($instances === array()): ?>
            <tr><td colspan="7" class="ftfs-muted">No form packets have been sent yet.</td></tr>
          <?php else: ?>
            <?php foreach ($instances as $instance): ?>
              <tr>
                <td>
                  <strong><?= h((string)$instance['title']) ?></strong>
                  <div class="ftfs-muted"><?= h((string)$instance['template_title']) ?> · v<?= h((string)$instance['version_label']) ?></div>
                </td>
                <td><?= h((string)($instance['student_name'] ?: $instance['student_email'] ?: '-')) ?></td>
                <td><?= h((string)($instance['instructor_name'] ?: $instance['instructor_email'] ?: '-')) ?></td>
                <td><span class="<?= h(ftfs_badge((string)$instance['status'])) ?>"><?= h((string)$instance['status']) ?></span></td>
                <td><?= (int)$instance['completed_recipient_count'] ?> / <?= (int)$instance['recipient_count'] ?></td>
                <td><?= h(ftfs_date($instance['sent_at'] ?? null)) ?></td>
                <td><?= h(ftfs_date($instance['completed_at'] ?? null)) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<?php cw_footer(); ?>
