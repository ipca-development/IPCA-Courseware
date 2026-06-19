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
    if (sft_is_knowledge_code_field($field)) {
        return '<textarea class="sft-textarea" name="' . $name . '" placeholder="Paste the written test report learning statement / deficient knowledge codes here.">' . h($value) . '</textarea>';
    }
    if ($type === 'signature' || $type === 'initial') {
        return '<input class="sft-input" name="' . $name . '" value="' . h($value) . '" placeholder="Type your name to sign">';
    }
    if ($type === 'textarea') {
        return '<textarea class="sft-textarea" name="' . $name . '">' . h($value) . '</textarea>';
    }
    return '<input class="sft-input" name="' . $name . '" value="' . h($value) . '">';
}

function sft_is_knowledge_code_field(array $field): bool
{
    $variable = strtolower(trim((string)($field['variable_key'] ?? '')));
    $fieldKey = strtolower(trim((string)($field['field_key'] ?? '')));
    $label = strtolower(trim((string)($field['label'] ?? '')));
    return $variable === 'knowledge_test.deficient_codes'
        || str_contains($fieldKey, 'deficient_code')
        || str_contains($label, 'deficient code')
        || str_contains($label, 'written test report');
}

function sft_field_hint(array $field): string
{
    $variable = strtolower(trim((string)($field['variable_key'] ?? '')));
    $label = strtolower(trim((string)($field['label'] ?? '')));
    $map = array(
        'first_solo' => 'Expected evidence: tagged first solo flight with date/route/hours.',
        'dual_cross_country_training' => 'Expected evidence: tagged dual cross-country training flight(s).',
        'dual_night_training' => 'Expected evidence: tagged dual night training flight(s).',
        'dual_night_cross_country' => 'Expected evidence: tagged dual night cross-country flight including distance covered.',
        'dual_night_takeoffs_landings' => 'Expected evidence: tagged night takeoff/landing training entry or entries.',
        'dual_instrument_flight_training' => 'Expected evidence: tagged dual/basic instrument training flight entry or entries.',
        'basic_instrument_flying' => 'Expected evidence: tagged dual/basic instrument training flight entry or entries.',
        'solo_cross_country_flight' => 'Expected evidence: tagged solo cross-country flight entry or entries.',
        'long_150nm_solo_cross_country_flight' => 'Expected evidence: tagged long solo cross-country flight with at least 150 NM total distance.',
        'long_solo_cross_country' => 'Expected evidence: tagged long solo cross-country flight with date, route, hours, and distance.',
        'solo_cross_country_150_nm' => 'Expected evidence: tagged solo cross-country 150 NM flight with route and distance.',
        'towered_airport_takeoffs_landings' => 'Expected evidence: tagged takeoff/landing entry or entries at a towered airport.',
        'towered_airport_landings' => 'Expected evidence: tagged takeoff/landing entry or entries at a towered airport.',
    );
    foreach ($map as $needle => $hint) {
        if (str_contains($variable, $needle) || str_contains($label, str_replace('_', ' ', $needle))) {
            return $hint;
        }
    }
    if (str_contains($variable, '.events')) {
        return 'This should contain tagged logbook entry evidence: date, route, hours, distance when applicable, and aircraft.';
    }
    if (sft_is_knowledge_code_field($field)) {
        return 'Written test report codes are resolved into title and relevant section automatically.';
    }
    return '';
}

function sft_knowledge_codes_html(array $field): string
{
    $rows = is_array($field['knowledge_test_codes'] ?? null) ? $field['knowledge_test_codes'] : array();
    if ($rows === array()) {
        return sft_is_knowledge_code_field($field)
            ? '<div class="sft-code-help">Written test codes will appear here as code + title + relevant section after they are entered.</div>'
            : '';
    }
    $out = '<div class="sft-code-table-wrap"><table class="sft-code-table"><thead><tr><th>Code</th><th>Title</th><th>Relevant Section</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out .= '<tr><td><strong>' . h((string)($row['code'] ?? '')) . '</strong></td><td>' . h((string)($row['title'] ?? '')) . '</td><td>' . h((string)($row['relevant_section'] ?? '')) . '</td></tr>';
    }
    return $out . '</tbody></table></div>';
}

function sft_requirement_evidence_html(array $field): string
{
    $evidence = is_array($field['requirement_evidence'] ?? null) ? $field['requirement_evidence'] : array();
    if ($evidence === array()) {
        return '';
    }
    $status = strtolower(trim((string)($evidence['status'] ?? 'fail')));
    $entries = is_array($evidence['entries'] ?? null) ? $evidence['entries'] : array();
    $label = trim((string)($evidence['label'] ?? 'Requirement evidence'));
    $out = '<div class="sft-evidence">'
        . '<div class="sft-evidence-head">'
        . '<strong>' . h($label) . '</strong>'
        . '<span class="sft-evidence-badge sft-evidence-badge--' . h($status === 'pass' ? 'pass' : 'fail') . '">'
        . h($status === 'pass' ? 'Satisfies requirement' : 'Does not satisfy yet')
        . '</span>'
        . '</div>';
    if ($entries === array()) {
        return $out . '<p class="sft-evidence-empty">No tagged logbook record yet.</p></div>';
    }
    $out .= '<ul class="sft-evidence-list">';
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $bits = array_filter(array(
            trim((string)($entry['entry_date'] ?? '')),
            trim((string)($entry['route'] ?? '')),
            trim((string)($entry['total_flight_time'] ?? '')) !== '0.0' ? trim((string)($entry['total_flight_time'] ?? '')) . 'h' : '',
            (int)($entry['night_landings'] ?? 0) > 0 ? (int)$entry['night_landings'] . ' night LDG' : '',
            (int)($entry['day_landings'] ?? 0) > 0 ? (int)$entry['day_landings'] . ' day LDG' : '',
            (int)($entry['towered_airport_landings'] ?? 0) > 0 ? (int)$entry['towered_airport_landings'] . ' towered LDG' : '',
            trim((string)($entry['cross_country_distance_nm'] ?? '')) !== '0.0' ? trim((string)($entry['cross_country_distance_nm'] ?? '')) . ' NM' : '',
            trim((string)($entry['aircraft_registration'] ?? '')),
        ));
        $detail = $bits !== array() ? implode(' · ', $bits) : ('Entry #' . (int)($entry['id'] ?? 0));
        $remarks = trim((string)($entry['remarks'] ?? ''));
        $out .= '<li><strong>' . h($detail) . '</strong>' . ($remarks !== '' ? '<span>' . h($remarks) . '</span>' : '') . '</li>';
    }
    return $out . '</ul></div>';
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
.sft-evidence{margin-top:9px;padding:10px 12px;border-radius:14px;background:#f8fafc;border:1px solid rgba(15,23,42,.08);color:#334155;font-size:12px}.sft-evidence-head{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;color:#102845}.sft-evidence-badge{display:inline-flex;border-radius:999px;padding:5px 8px;font-size:10px;font-weight:900;text-transform:uppercase}.sft-evidence-badge--pass{background:#dcfce7;color:#166534}.sft-evidence-badge--fail{background:#fee2e2;color:#991b1b}.sft-evidence-list{margin:8px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:5px}.sft-evidence-list li{display:flex;gap:7px;align-items:flex-start;flex-wrap:wrap}.sft-evidence-empty{margin:8px 0 0;color:#92400e}
.sft-code-help{margin-top:9px;padding:10px 12px;border-radius:14px;background:#fffbeb;border:1px solid #fde68a;color:#92400e;font-size:12px;font-weight:800}.sft-code-table-wrap{margin-top:9px;overflow:auto;border:1px solid rgba(15,23,42,.08);border-radius:14px}.sft-code-table{width:100%;border-collapse:separate;border-spacing:0;background:#fff}.sft-code-table th{padding:8px 10px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;background:#f8fafc;border-bottom:1px solid rgba(15,23,42,.07)}.sft-code-table td{padding:9px 10px;border-bottom:1px solid rgba(15,23,42,.06);font-size:12px;color:#102845}.sft-code-table tr:last-child td{border-bottom:0}
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
              <?php $hint = sft_field_hint($field); ?>
              <?php if ($hint !== ''): ?>
                <div class="sft-meta"><?= h($hint) ?></div>
              <?php endif; ?>
            </div>
          </div>
          <div>
            <?= sft_render_control($field, $editable) ?>
            <?= sft_knowledge_codes_html($field) ?>
            <?= sft_requirement_evidence_html($field) ?>
          </div>
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
