<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/flight_training/FormInstanceService.php';

cw_require_student();

$authUser = cw_current_user($pdo);
$userId = cw_student_view_user_id($pdo, $authUser);
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
$recipientRole = strtolower(trim((string)($recipient['recipient_role'] ?? 'student')));
$isCompleted = (string)($recipient['status'] ?? '') === 'completed';

function sft_field_value(array $field): string
{
    return (string)($field['value_text'] ?? '');
}

function sft_render_control(array $field, bool $editable): string
{
    $key = (string)($field['field_key'] ?? '');
    $type = strtolower(trim((string)($field['field_type'] ?? 'text')));
    $value = sft_field_value($field);
    $name = 'fields[' . h($key) . ']';
    if (!$editable) {
        if ($type === 'checkbox') {
            return '<div class="sft-readonly">' . ($value !== '' ? 'Yes' : '-') . '</div>';
        }
        return '<div class="sft-readonly">' . h($value !== '' ? $value : '-') . '</div>';
    }
    if ($type === 'checkbox') {
        return '<label class="sft-check"><input type="checkbox" name="' . $name . '" value="1"' . ($value !== '' ? ' checked' : '') . '> Yes</label>';
    }
    if ($type === 'signature' || $type === 'initial') {
        return '<input class="sft-input" name="' . $name . '" value="' . h($value) . '" placeholder="Type your name to sign">';
    }
    if ($type === 'textarea') {
        return '<textarea class="sft-textarea" name="' . $name . '">' . h($value) . '</textarea>';
    }
    return '<input class="sft-input" name="' . $name . '" value="' . h($value) . '">';
}

cw_header('Form Task');
?>

<style>
.sft-page{display:flex;flex-direction:column;gap:18px}
.sft-hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;padding:22px;border-radius:24px;background:linear-gradient(135deg,#102845,#1d4f89);color:#fff;box-shadow:0 18px 40px rgba(15,40,69,.18)}
.sft-kicker{margin:0 0 7px;font-size:11px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#bfdbfe}
.sft-title{margin:0;font-size:28px;line-height:1.1}
.sft-subtitle{margin:9px 0 0;max-width:740px;color:#dbeafe;font-size:14px;line-height:1.5}
.sft-btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:999px;padding:9px 14px;font-size:13px;font-weight:900;text-decoration:none;white-space:nowrap;cursor:pointer}
.sft-btn--primary{background:#1d4f89;color:#fff}
.sft-btn--secondary{background:#eef2ff;color:#1e3a8a}
.sft-btn--ghost{background:#fff;color:#1d4f89}
.sft-card{border:1px solid rgba(15,23,42,.08);border-radius:22px;background:#fff;box-shadow:0 10px 26px rgba(15,23,42,.06);overflow:hidden}
.sft-field{display:grid;grid-template-columns:280px 1fr;gap:16px;padding:16px 18px;border-bottom:1px solid rgba(15,23,42,.06)}
.sft-field:last-child{border-bottom:0}
.sft-label{font-weight:900;color:#102845;font-size:14px}
.sft-meta{margin-top:4px;color:#64748b;font-size:12px}
.sft-input,.sft-textarea{width:100%;box-sizing:border-box;border:1px solid rgba(15,23,42,.13);border-radius:14px;padding:11px 12px;font:inherit;font-size:14px;color:#102845;background:#fff}
.sft-textarea{min-height:88px;resize:vertical}
.sft-readonly{min-height:20px;padding:10px 12px;border-radius:14px;background:#f8fafc;color:#334155;border:1px solid rgba(15,23,42,.08)}
.sft-check{display:inline-flex;align-items:center;gap:8px;font-weight:800;color:#102845}
.sft-actions{display:flex;justify-content:flex-end;gap:8px;padding:16px 18px;border-top:1px solid rgba(15,23,42,.07);background:#f8fafc}
.sft-notice,.sft-error{padding:13px 16px;border-radius:16px;font-size:13px;font-weight:800}
.sft-notice{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}
.sft-error{background:#fff1f2;color:#be123c;border:1px solid #fecdd3}
.sft-chip{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;background:#eef2ff;color:#1e3a8a}
@media (max-width:760px){.sft-hero{flex-direction:column}.sft-field{grid-template-columns:1fr}.sft-actions{justify-content:flex-start;flex-wrap:wrap}}
</style>

<div class="sft-page">
  <header class="sft-hero">
    <div>
      <p class="sft-kicker">Student · Form Task</p>
      <h1 class="sft-title"><?= h((string)($recipient['instance_title'] ?? 'Form Task')) ?></h1>
      <p class="sft-subtitle">Review the packet and complete the fields assigned to you. Other fields are shown for context only.</p>
    </div>
    <a class="sft-btn sft-btn--ghost" href="/student/forms/inbox.php<?= $userId > 0 && strtolower((string)($authUser['role'] ?? '')) === 'admin' ? '?user_id=' . (int)$userId : '' ?>">Back to Inbox</a>
  </header>

  <?php if ($notice !== ''): ?><div class="sft-notice"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="sft-error"><?= h($error) ?></div><?php endif; ?>

  <?php if ($recipient !== array()): ?>
    <form method="post" class="sft-card">
      <input type="hidden" name="recipient_id" value="<?= (int)$recipientId ?>">
      <?php foreach ($fields as $field): ?>
        <?php
          $assignedRole = strtolower(trim((string)($field['assigned_role'] ?? '')));
          $editable = !$isCompleted && $assignedRole === $recipientRole;
        ?>
        <div class="sft-field">
          <div>
            <div class="sft-label"><?= h((string)($field['label'] ?: $field['field_key'])) ?><?= !empty($field['required']) ? ' *' : '' ?></div>
            <div class="sft-meta">
              <span class="sft-chip"><?= h($assignedRole) ?></span>
              <?php if (trim((string)($field['variable_key'] ?? '')) !== ''): ?>
                · <?= h((string)$field['variable_key']) ?>
              <?php endif; ?>
            </div>
          </div>
          <div><?= sft_render_control($field, $editable) ?></div>
        </div>
      <?php endforeach; ?>
      <?php if (!$isCompleted): ?>
        <div class="sft-actions">
          <button class="sft-btn sft-btn--secondary" type="submit" name="submit_action" value="save">Save Draft</button>
          <button class="sft-btn sft-btn--primary" type="submit" name="submit_action" value="complete">Complete My Part</button>
        </div>
      <?php else: ?>
        <div class="sft-actions"><span class="sft-chip">Completed</span></div>
      <?php endif; ?>
    </form>
  <?php endif; ?>
</div>

<?php cw_footer(); ?>
