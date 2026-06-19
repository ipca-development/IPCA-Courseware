<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/flight_training/FormInstanceService.php';

cw_require_login();

$user = cw_current_user($pdo);
$userId = (int)($user['id'] ?? 0);
$role = strtolower(trim((string)($user['role'] ?? '')));
if (!in_array($role, array('admin', 'supervisor', 'instructor', 'chief_instructor'), true)) {
    redirect(cw_home_path_for_role($role));
}

$recipientId = (int)($_GET['recipient_id'] ?? $_POST['recipient_id'] ?? 0);
$service = new FormInstanceService($pdo);
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $service->saveRecipientTask(
            $recipientId,
            $userId,
            is_array($_POST['fields'] ?? null) ? $_POST['fields'] : array(),
            (string)($_POST['submit_action'] ?? '') === 'complete'
        );
        $notice = (string)($_POST['submit_action'] ?? '') === 'complete' ? 'Your form task has been completed.' : 'Draft saved.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$task = array('recipient' => array(), 'fields' => array());
try {
    if ($recipientId <= 0) {
        throw new RuntimeException('Missing form task.');
    }
    $task = $service->loadRecipientTask($recipientId, $userId);
} catch (Throwable $e) {
    $error = $error !== '' ? $error : $e->getMessage();
}

$recipient = is_array($task['recipient'] ?? null) ? $task['recipient'] : array();
$fields = is_array($task['fields'] ?? null) ? $task['fields'] : array();
$recipientRole = strtolower(trim((string)($recipient['recipient_role'] ?? 'instructor')));
$isCompleted = (string)($recipient['status'] ?? '') === 'completed';

function ift_field_value(array $field): string
{
    return (string)($field['value_text'] ?? '');
}

function ift_render_control(array $field, bool $editable): string
{
    $key = (string)($field['field_key'] ?? '');
    $type = strtolower(trim((string)($field['field_type'] ?? 'text')));
    $value = ift_field_value($field);
    $name = 'fields[' . h($key) . ']';
    if (!$editable) {
        if ($type === 'checkbox') {
            return '<div class="ift-readonly">' . ($value !== '' ? 'Yes' : '-') . '</div>';
        }
        return '<div class="ift-readonly">' . h($value !== '' ? $value : '-') . '</div>';
    }
    if ($type === 'checkbox') {
        return '<label class="ift-check"><input type="checkbox" name="' . $name . '" value="1"' . ($value !== '' ? ' checked' : '') . '> Yes</label>';
    }
    if ($type === 'signature' || $type === 'initial') {
        return '<input class="ift-input" name="' . $name . '" value="' . h($value) . '" placeholder="Type your name to sign">';
    }
    if ($type === 'textarea') {
        return '<textarea class="ift-textarea" name="' . $name . '">' . h($value) . '</textarea>';
    }
    return '<input class="ift-input" name="' . $name . '" value="' . h($value) . '">';
}

cw_header('Form Task');
?>

<style>
.ift-page{display:flex;flex-direction:column;gap:18px}
.ift-hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;padding:22px;border-radius:24px;background:linear-gradient(135deg,#102845,#1d4f89);color:#fff;box-shadow:0 18px 40px rgba(15,40,69,.18)}
.ift-kicker{margin:0 0 7px;font-size:11px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#bfdbfe}
.ift-title{margin:0;font-size:28px;line-height:1.1}
.ift-subtitle{margin:9px 0 0;max-width:740px;color:#dbeafe;font-size:14px;line-height:1.5}
.ift-btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:999px;padding:9px 14px;font-size:13px;font-weight:900;text-decoration:none;white-space:nowrap;cursor:pointer}
.ift-btn--primary{background:#1d4f89;color:#fff}
.ift-btn--secondary{background:#eef2ff;color:#1e3a8a}
.ift-btn--ghost{background:#fff;color:#1d4f89}
.ift-card{border:1px solid rgba(15,23,42,.08);border-radius:22px;background:#fff;box-shadow:0 10px 26px rgba(15,23,42,.06);overflow:hidden}
.ift-field{display:grid;grid-template-columns:280px 1fr;gap:16px;padding:16px 18px;border-bottom:1px solid rgba(15,23,42,.06)}
.ift-field:last-child{border-bottom:0}
.ift-label{font-weight:900;color:#102845;font-size:14px}
.ift-meta{margin-top:4px;color:#64748b;font-size:12px}
.ift-input,.ift-textarea{width:100%;box-sizing:border-box;border:1px solid rgba(15,23,42,.13);border-radius:14px;padding:11px 12px;font:inherit;font-size:14px;color:#102845;background:#fff}
.ift-textarea{min-height:88px;resize:vertical}
.ift-readonly{min-height:20px;padding:10px 12px;border-radius:14px;background:#f8fafc;color:#334155;border:1px solid rgba(15,23,42,.08)}
.ift-check{display:inline-flex;align-items:center;gap:8px;font-weight:800;color:#102845}
.ift-actions{display:flex;justify-content:flex-end;gap:8px;padding:16px 18px;border-top:1px solid rgba(15,23,42,.07);background:#f8fafc}
.ift-notice,.ift-error{padding:13px 16px;border-radius:16px;font-size:13px;font-weight:800}
.ift-notice{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}
.ift-error{background:#fff1f2;color:#be123c;border:1px solid #fecdd3}
.ift-chip{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;background:#eef2ff;color:#1e3a8a}
@media (max-width:760px){.ift-hero{flex-direction:column}.ift-field{grid-template-columns:1fr}.ift-actions{justify-content:flex-start;flex-wrap:wrap}}
</style>

<div class="ift-page">
  <header class="ift-hero">
    <div>
      <p class="ift-kicker">Instructor · Form Task</p>
      <h1 class="ift-title"><?= h((string)($recipient['instance_title'] ?? 'Form Task')) ?></h1>
      <p class="ift-subtitle">Review the packet and complete the fields assigned to you. Other fields are shown for context only.</p>
    </div>
    <a class="ift-btn ift-btn--ghost" href="/instructor/forms/inbox.php">Back to Inbox</a>
  </header>

  <?php if ($notice !== ''): ?><div class="ift-notice"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="ift-error"><?= h($error) ?></div><?php endif; ?>

  <?php if ($recipient !== array()): ?>
    <form method="post" class="ift-card">
      <input type="hidden" name="recipient_id" value="<?= (int)$recipientId ?>">
      <?php foreach ($fields as $field): ?>
        <?php
          $assignedRole = strtolower(trim((string)($field['assigned_role'] ?? '')));
          $editable = !$isCompleted && $assignedRole === $recipientRole;
        ?>
        <div class="ift-field">
          <div>
            <div class="ift-label"><?= h((string)($field['label'] ?: $field['field_key'])) ?><?= !empty($field['required']) ? ' *' : '' ?></div>
            <div class="ift-meta">
              <span class="ift-chip"><?= h($assignedRole) ?></span>
              <?php if (trim((string)($field['variable_key'] ?? '')) !== ''): ?>
                · <?= h((string)$field['variable_key']) ?>
              <?php endif; ?>
            </div>
          </div>
          <div><?= ift_render_control($field, $editable) ?></div>
        </div>
      <?php endforeach; ?>
      <?php if (!$isCompleted): ?>
        <div class="ift-actions">
          <button class="ift-btn ift-btn--secondary" type="submit" name="submit_action" value="save">Save Draft</button>
          <button class="ift-btn ift-btn--primary" type="submit" name="submit_action" value="complete">Complete My Part</button>
        </div>
      <?php else: ?>
        <div class="ift-actions"><span class="ift-chip">Completed</span></div>
      <?php endif; ?>
    </form>
  <?php endif; ?>
</div>

<?php cw_footer(); ?>
