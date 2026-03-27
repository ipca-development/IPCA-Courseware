<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/automation_catalog.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$eventOptions = automation_event_options();
$fieldOptions = automation_condition_field_options();
$operatorOptions = automation_condition_operator_options();
$actionOptions = automation_action_options();
$templateOptions = automation_load_notification_templates($pdo);

$flowsStmt = $pdo->query("
    SELECT *
    FROM automation_flows
    ORDER BY priority ASC, id DESC
");
$flows = $flowsStmt->fetchAll(PDO::FETCH_ASSOC);

$conditionsByFlow = array();
$cStmt = $pdo->query("
    SELECT *
    FROM automation_flow_conditions
    ORDER BY flow_id ASC, sort_order ASC, id ASC
");
foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $flowId = (int)$row['flow_id'];
    if (!isset($conditionsByFlow[$flowId])) {
        $conditionsByFlow[$flowId] = array();
    }

    $row['field_key_ui'] = automation_resolve_condition_field_key((string)($row['field_key'] ?? ''));
    $row['operator_ui'] = automation_resolve_condition_operator_key((string)($row['operator'] ?? ''));

    $conditionsByFlow[$flowId][] = $row;
}

$actionsByFlow = array();
$aStmt = $pdo->query("
    SELECT *
    FROM automation_flow_actions
    ORDER BY flow_id ASC, sort_order ASC, id ASC
");
foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $flowId = (int)$row['flow_id'];
    if (!isset($actionsByFlow[$flowId])) {
        $actionsByFlow[$flowId] = array();
    }

    $row['action_key_ui'] = automation_resolve_action_key((string)($row['action_key'] ?? ''));

    $config = array();
    if (isset($row['config_json']) && trim((string)$row['config_json']) !== '') {
        $decoded = json_decode((string)$row['config_json'], true);
        if (is_array($decoded)) {
            $config = $decoded;
        }
    }

    if (!isset($config['notification_key']) && isset($config['template_key'])) {
        $config['notification_key'] = $config['template_key'];
    }

    $row['config_json'] = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $actionsByFlow[$flowId][] = $row;
}

cw_header('Automation Flows');
?>
<style>
.af-wrap{max-width:1380px;margin:0 auto;padding:18px 18px 40px}
.af-top{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin-bottom:18px}
.af-title{margin:0;font-size:30px;line-height:1.05;font-weight:800;color:#13263f}
.af-sub{margin-top:8px;color:#607086;font-size:14px;line-height:1.55;max-width:920px}
.af-btn{
  border:none;border-radius:12px;padding:10px 14px;font-weight:800;font-size:13px;cursor:pointer;
  background:#12355f;color:#fff;box-shadow:0 10px 24px rgba(18,53,95,.16)
}
.af-btn.ghost{background:#fff;color:#13263f;border:1px solid rgba(15,23,42,.10);box-shadow:none}
.af-btn.warn{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;box-shadow:none}
.af-btn.danger{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;box-shadow:none}
.af-grid{display:grid;grid-template-columns:360px minmax(0,1fr);gap:18px}
.af-card{
  background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:18px;box-shadow:0 10px 28px rgba(15,23,42,.05)
}
.af-side{padding:14px}
.af-list{display:flex;flex-direction:column;gap:10px}
.af-flow-item{
  border:1px solid rgba(15,23,42,.08);border-radius:14px;padding:12px;background:#fbfdff;cursor:pointer
}
.af-flow-item.active{border-color:#93c5fd;background:#eff6ff}
.af-flow-name{font-size:14px;font-weight:800;color:#13263f}
.af-flow-meta{margin-top:6px;font-size:12px;color:#64748b;line-height:1.45}
.af-pill{
  display:inline-flex;align-items:center;min-height:22px;padding:0 8px;border-radius:999px;
  font-size:10px;font-weight:800;letter-spacing:.02em;border:1px solid transparent;white-space:nowrap
}
.af-pill.ok{background:#dcfce7;color:#166534;border-color:#86efac}
.af-pill.off{background:#f1f5f9;color:#475569;border-color:#cbd5e1}
.af-main{padding:18px}
.af-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.af-field{display:flex;flex-direction:column;gap:7px}
.af-field.full{grid-column:1 / -1}
.af-label{font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:#64748b;font-weight:800}
.af-input,.af-select,.af-textarea{
  width:100%;border:1px solid rgba(15,23,42,.12);border-radius:12px;background:#fff;color:#13263f;
  padding:10px 12px;font-size:14px;box-sizing:border-box
}
.af-textarea{min-height:96px;resize:vertical}
.af-section{margin-top:18px;padding-top:16px;border-top:1px solid rgba(15,23,42,.08)}
.af-section-title{margin:0 0 10px 0;font-size:18px;font-weight:800;color:#13263f}
.af-rows{display:flex;flex-direction:column;gap:10px}
.af-row{
  border:1px solid rgba(15,23,42,.08);border-radius:14px;padding:12px;background:#fbfdff
}
.af-row-grid{display:grid;grid-template-columns:1.2fr .9fr 1fr auto;gap:10px;align-items:end}
.af-row-grid.actions{grid-template-columns:1.1fr 1fr 1fr auto}
.af-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}
.af-test-box{
  margin-top:18px;border:1px solid rgba(15,23,42,.08);border-radius:14px;padding:14px;background:#fbfdff
}
.af-test-result{
  margin-top:12px;padding:12px;border-radius:12px;background:#fff;border:1px solid rgba(15,23,42,.08);
  font-size:13px;line-height:1.55;color:#243446;white-space:pre-wrap
}
.af-empty{padding:18px;color:#64748b;font-size:14px}
@media (max-width:1100px){
  .af-grid{grid-template-columns:1fr}
}
@media (max-width:760px){
  .af-form-grid,.af-row-grid,.af-row-grid.actions{grid-template-columns:1fr}
}
</style>

<div class="af-wrap">
  <div class="af-top">
    <div>
      <h1 class="af-title">Automation Flows</h1>
      <div class="af-sub">
        Build simple trigger-based logic for notifications and training automation. Each flow starts with one event, then optional conditions, then one or more actions.
      </div>
    </div>
    <button class="af-btn" id="newFlowBtn">New Flow</button>
  </div>

  <div class="af-grid">
    <div class="af-card af-side">
      <div class="af-list" id="flowList">
        <?php if (!$flows): ?>
          <div class="af-empty">No flows yet.</div>
        <?php else: ?>
          <?php foreach ($flows as $flow): ?>
            <?php
              $flowId = (int)$flow['id'];
              $payload = array(
                  'id' => $flowId,
                  'name' => (string)$flow['name'],
                  'description' => (string)$flow['description'],
                  'event_key' => (string)$flow['event_key'],
                  'is_active' => (int)$flow['is_active'],
                  'priority' => (int)$flow['priority'],
                  'conditions' => isset($conditionsByFlow[$flowId]) ? $conditionsByFlow[$flowId] : array(),
                  'actions' => isset($actionsByFlow[$flowId]) ? $actionsByFlow[$flowId] : array()
              );
            ?>
            <div class="af-flow-item" data-flow='<?= h(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
              <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">
                <div class="af-flow-name"><?= h((string)$flow['name']) ?></div>
                <span class="af-pill <?= ((int)$flow['is_active'] === 1 ? 'ok' : 'off') ?>">
                  <?= ((int)$flow['is_active'] === 1 ? 'Active' : 'Inactive') ?>
                </span>
              </div>
              <div class="af-flow-meta">
                Event: <?= h($eventOptions[(string)$flow['event_key']] ?? (string)$flow['event_key']) ?><br>
                Priority: <?= (int)$flow['priority'] ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="af-card af-main">
      <input type="hidden" id="flowId" value="0">

      <div class="af-form-grid">
        <div class="af-field">
          <label class="af-label" for="flowName">Flow Name</label>
          <input class="af-input" id="flowName" type="text" value="">
        </div>

        <div class="af-field">
          <label class="af-label" for="flowEvent">Trigger Event</label>
          <select class="af-select" id="flowEvent">
            <option value="">Select event</option>
            <?php foreach ($eventOptions as $key => $label): ?>
              <option value="<?= h($key) ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="af-field">
          <label class="af-label" for="flowPriority">Priority</label>
          <input class="af-input" id="flowPriority" type="number" value="100">
        </div>

        <div class="af-field">
          <label class="af-label" for="flowActive">Status</label>
          <select class="af-select" id="flowActive">
            <option value="1">Active</option>
            <option value="0">Inactive</option>
          </select>
        </div>

        <div class="af-field full">
          <label class="af-label" for="flowDescription">Description</label>
          <textarea class="af-textarea" id="flowDescription"></textarea>
        </div>
      </div>

      <div class="af-section">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px;">
          <h2 class="af-section-title">Conditions</h2>
          <button class="af-btn ghost" id="addConditionBtn" type="button">Add Condition</button>
        </div>
        <div class="af-rows" id="conditionsWrap"></div>
      </div>

      <div class="af-section">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px;">
          <h2 class="af-section-title">Actions</h2>
          <button class="af-btn ghost" id="addActionBtn" type="button">Add Action</button>
        </div>
        <div class="af-rows" id="actionsWrap"></div>
      </div>

      <div class="af-actions">
        <button class="af-btn" id="saveFlowBtn" type="button">Save Flow</button>
        <button class="af-btn ghost" id="toggleFlowBtn" type="button">Activate / Deactivate</button>
        <button class="af-btn danger" id="deleteFlowBtn" type="button">Delete Flow</button>
      </div>

      <div class="af-test-box">
        <h2 class="af-section-title" style="margin-top:0;">Test Flow</h2>
        <div class="af-field full">
          <label class="af-label" for="testContext">Test Context JSON</label>
          <textarea class="af-textarea" id="testContext">{
  "user_id": 14,
  "cohort_id": 3,
  "lesson_id": 1,
  "attempt_count": 3,
  "score_pct": 57,
  "result_code": "UNSAT_SCORE_BELOW_PASS",
  "deadline_status": "on_time",
  "summary_status": "acceptable",
  "decision_code": "approve_with_summary_revision",
  "user_role": "student"
}</textarea>
        </div>
        <button class="af-btn warn" id="testFlowBtn" type="button">Run Test</button>
        <div class="af-test-result" id="testResult">No test run yet.</div>
      </div>
    </div>
  </div>
</div>

<script>
const TEMPLATE_OPTIONS = <?= json_encode($templateOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const FIELD_OPTIONS = <?= json_encode($fieldOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const OPERATOR_OPTIONS = <?= json_encode($operatorOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const ACTION_OPTIONS = <?= json_encode($actionOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const FIELD_ALIASES = {
  attempt: 'attempt_count',
  attempts: 'attempt_count',
  attempt_number: 'attempt_count',
  total_attempts: 'attempt_count',
  score: 'score_pct',
  score_percent: 'score_pct',
  score_percentage: 'score_pct',
  percentage_score: 'score_pct',
  result: 'result_code',
  result_type: 'result_code',
  formal_result: 'result_code',
  deadline: 'deadline_status',
  timing_status: 'deadline_status',
  timing: 'deadline_status',
  review_status: 'summary_status',
  summary_review_status: 'summary_status',
  decision: 'decision_code',
  instructor_decision: 'decision_code',
  role: 'user_role'
};

const OPERATOR_ALIASES = {
  '=': 'equals',
  '==': 'equals',
  eq: 'equals',
  equal: 'equals',
  equals: 'equals',
  is: 'equals',

  '!=': 'not_equals',
  '<>': 'not_equals',
  neq: 'not_equals',
  not_equal: 'not_equals',
  not_equals: 'not_equals',
  is_not: 'not_equals',

  '>': 'greater_than',
  gt: 'greater_than',
  greater_than: 'greater_than',

  '>=': 'greater_or_equal',
  gte: 'greater_or_equal',
  greater_or_equal: 'greater_or_equal',
  greater_than_or_equal: 'greater_or_equal',

  '<': 'less_than',
  lt: 'less_than',
  less_than: 'less_than',

  '<=': 'less_or_equal',
  lte: 'less_or_equal',
  less_or_equal: 'less_or_equal',
  less_than_or_equal: 'less_or_equal',

  contains: 'contains',
  in: 'contains'
};

const ACTION_ALIASES = {
  send_notification: 'send_notification',
  send_email: 'send_notification',
  notification: 'send_notification',
  notify: 'send_notification',
  email: 'send_notification',

  grant_extra_attempts: 'grant_extra_attempts',
  extra_attempts: 'grant_extra_attempts',
  add_attempts: 'grant_extra_attempts',

  create_required_action: 'create_required_action',
  required_action: 'create_required_action',
  create_action: 'create_required_action',

  apply_deadline_extension: 'apply_deadline_extension',
  grant_deadline_extension: 'apply_deadline_extension',
  deadline_extension: 'apply_deadline_extension',
  extend_deadline: 'apply_deadline_extension',

  log_only: 'log_only',
  log: 'log_only'
};

const flowIdEl = document.getElementById('flowId');
const flowNameEl = document.getElementById('flowName');
const flowDescriptionEl = document.getElementById('flowDescription');
const flowEventEl = document.getElementById('flowEvent');
const flowPriorityEl = document.getElementById('flowPriority');
const flowActiveEl = document.getElementById('flowActive');
const conditionsWrap = document.getElementById('conditionsWrap');
const actionsWrap = document.getElementById('actionsWrap');
const testContextEl = document.getElementById('testContext');
const testResultEl = document.getElementById('testResult');

function apiPost(payload) {
  return fetch('/admin/api/automation_flows_api.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    credentials: 'same-origin',
    body: JSON.stringify(payload)
  }).then(function(r){ return r.json(); });
}

function normalizeKey(value) {
  value = String(value || '').trim().toLowerCase();
  if (value === '') {
    return '';
  }
  value = value.replaceAll('&', 'and');
  value = value.replace(/[^a-z0-9]+/g, '_');
  value = value.replace(/_+/g, '_');
  value = value.replace(/^_+|_+$/g, '');
  return value;
}

function canonicalOptionValue(value, optionsMap, aliasMap) {
  const raw = String(value || '').trim();
  if (raw === '') {
    return '';
  }

  if (Object.prototype.hasOwnProperty.call(optionsMap, raw)) {
    return raw;
  }

  const normalized = normalizeKey(raw);

  if (aliasMap && Object.prototype.hasOwnProperty.call(aliasMap, normalized)) {
    const aliasTarget = aliasMap[normalized];
    if (Object.prototype.hasOwnProperty.call(optionsMap, aliasTarget)) {
      return aliasTarget;
    }
  }

  const keys = Object.keys(optionsMap);
  for (let i = 0; i < keys.length; i++) {
    const key = keys[i];
    if (normalizeKey(key) === normalized || normalizeKey(optionsMap[key]) === normalized) {
      return key;
    }
  }

  return raw;
}

function optionHtml(map, selectedValue, aliasMap) {
  const canonicalSelected = canonicalOptionValue(selectedValue, map, aliasMap);
  let html = '';
  Object.keys(map).forEach(function(key){
    const selected = String(canonicalSelected) === String(key) ? ' selected' : '';
    html += '<option value="' + escapeHtml(key) + '"' + selected + '>' + escapeHtml(map[key]) + '</option>';
  });
  return html;
}

function parseActionConfig(configJson) {
  if (!configJson) {
    return {};
  }

  try {
    const decoded = JSON.parse(configJson);
    if (decoded && typeof decoded === 'object') {
      if (!decoded.notification_key && decoded.template_key) {
        decoded.notification_key = decoded.template_key;
      }
      return decoded;
    }
  } catch (e) {
  }

  return {};
}

function conditionRowHtml(data) {
  data = data || {};

  const selectedField = canonicalOptionValue(
    data.field_key_ui || data.field_key || '',
    FIELD_OPTIONS,
    FIELD_ALIASES
  );

  const selectedOperator = canonicalOptionValue(
    data.operator_ui || data.operator || '',
    OPERATOR_OPTIONS,
    OPERATOR_ALIASES
  );

  const value = data.value_text !== null && data.value_text !== undefined && data.value_text !== ''
    ? data.value_text
    : (data.value_number !== null && data.value_number !== undefined ? data.value_number : '');

  return '' +
    '<div class="af-row condition-row">' +
      '<div class="af-row-grid">' +
        '<div class="af-field">' +
          '<label class="af-label">Field</label>' +
          '<select class="af-select cond-field">' +
            '<option value="">Select field</option>' +
            optionHtml(FIELD_OPTIONS, selectedField, FIELD_ALIASES) +
          '</select>' +
        '</div>' +
        '<div class="af-field">' +
          '<label class="af-label">Operator</label>' +
          '<select class="af-select cond-operator">' +
            '<option value="">Select operator</option>' +
            optionHtml(OPERATOR_OPTIONS, selectedOperator, OPERATOR_ALIASES) +
          '</select>' +
        '</div>' +
        '<div class="af-field">' +
          '<label class="af-label">Value</label>' +
          '<input class="af-input cond-value" type="text" value="' + escapeHtml(String(value)) + '">' +
        '</div>' +
        '<div class="af-field">' +
          '<label class="af-label">&nbsp;</label>' +
          '<button class="af-btn danger remove-condition" type="button">Remove</button>' +
        '</div>' +
      '</div>' +
    '</div>';
}

function actionRowHtml(data) {
  data = data || {};

  const config = parseActionConfig(data.config_json || '');
  const selectedAction = canonicalOptionValue(
    data.action_key_ui || data.action_key || '',
    ACTION_OPTIONS,
    ACTION_ALIASES
  );

  const value = config.extra_attempts !== undefined && config.extra_attempts !== null && config.extra_attempts !== ''
    ? config.extra_attempts
    : (
        config.extension_hours !== undefined && config.extension_hours !== null && config.extension_hours !== ''
          ? config.extension_hours
          : (
              config.required_action_type !== undefined && config.required_action_type !== null
                ? config.required_action_type
                : ''
            )
      );

  return '' +
    '<div class="af-row action-row">' +
      '<div class="af-row-grid actions">' +
        '<div class="af-field">' +
          '<label class="af-label">Action</label>' +
          '<select class="af-select action-key">' +
            '<option value="">Select action</option>' +
            optionHtml(ACTION_OPTIONS, selectedAction, ACTION_ALIASES) +
          '</select>' +
        '</div>' +
        '<div class="af-field action-notification-wrap">' +
          '<label class="af-label">Notification Template</label>' +
          '<select class="af-select action-notification-key">' +
            '<option value="">Select template</option>' +
            optionHtml(TEMPLATE_OPTIONS, config.notification_key || '', null) +
          '</select>' +
        '</div>' +
        '<div class="af-field action-value-wrap">' +
          '<label class="af-label">Value</label>' +
          '<input class="af-input action-value" type="text" value="' + escapeHtml(String(value)) + '">' +
        '</div>' +
        '<div class="af-field">' +
          '<label class="af-label">&nbsp;</label>' +
          '<button class="af-btn danger remove-action" type="button">Remove</button>' +
        '</div>' +
      '</div>' +
    '</div>';
}

function refreshActionRowUi(row) {
  const key = canonicalOptionValue(
    row.querySelector('.action-key').value,
    ACTION_OPTIONS,
    ACTION_ALIASES
  );

  const notificationWrap = row.querySelector('.action-notification-wrap');
  const valueWrap = row.querySelector('.action-value-wrap');
  const valueInput = row.querySelector('.action-value');

  notificationWrap.style.display = 'none';
  valueWrap.style.display = 'none';
  valueInput.placeholder = '';

  if (key === 'send_notification') {
    notificationWrap.style.display = '';
  } else if (key === 'grant_extra_attempts') {
    valueWrap.style.display = '';
    valueInput.placeholder = 'Extra attempts';
  } else if (key === 'apply_deadline_extension') {
    valueWrap.style.display = '';
    valueInput.placeholder = 'Extension hours';
  } else if (key === 'create_required_action') {
    valueWrap.style.display = '';
    valueInput.placeholder = 'Action type';
  }
}

function bindDynamicRows() {
  document.querySelectorAll('.remove-condition').forEach(function(btn){
    btn.onclick = function() {
      btn.closest('.condition-row').remove();
    };
  });

  document.querySelectorAll('.remove-action').forEach(function(btn){
    btn.onclick = function() {
      btn.closest('.action-row').remove();
    };
  });

  document.querySelectorAll('.action-key').forEach(function(select){
    select.onchange = function() {
      refreshActionRowUi(select.closest('.action-row'));
    };
    refreshActionRowUi(select.closest('.action-row'));
  });
}

function clearEditor() {
  flowIdEl.value = '0';
  flowNameEl.value = '';
  flowDescriptionEl.value = '';
  flowEventEl.value = '';
  flowPriorityEl.value = '100';
  flowActiveEl.value = '1';
  conditionsWrap.innerHTML = '';
  actionsWrap.innerHTML = '';
  testResultEl.textContent = 'No test run yet.';

  document.querySelectorAll('.af-flow-item').forEach(function(item){
    item.classList.remove('active');
  });
}

function loadFlow(flow, itemEl) {
  clearEditor();

  if (itemEl) {
    itemEl.classList.add('active');
  }

  flowIdEl.value = String(flow.id || 0);
  flowNameEl.value = flow.name || '';
  flowDescriptionEl.value = flow.description || '';
  flowEventEl.value = flow.event_key || '';
  flowPriorityEl.value = String(flow.priority || 100);
  flowActiveEl.value = String(flow.is_active ? 1 : 0);

  (flow.conditions || []).forEach(function(row){
    conditionsWrap.insertAdjacentHTML('beforeend', conditionRowHtml(row));
  });

  (flow.actions || []).forEach(function(row){
    actionsWrap.insertAdjacentHTML('beforeend', actionRowHtml(row));
  });

  bindDynamicRows();
}

function collectConditions() {
  const rows = [];

  document.querySelectorAll('.condition-row').forEach(function(row){
    const fieldKey = canonicalOptionValue(
      row.querySelector('.cond-field').value,
      FIELD_OPTIONS,
      FIELD_ALIASES
    );

    const operatorKey = canonicalOptionValue(
      row.querySelector('.cond-operator').value,
      OPERATOR_OPTIONS,
      OPERATOR_ALIASES
    );

    rows.push({
      field_key: fieldKey,
      operator: operatorKey,
      value: row.querySelector('.cond-value').value
    });
  });

  return rows;
}

function collectActions() {
  const rows = [];

  document.querySelectorAll('.action-row').forEach(function(row){
    const actionKey = canonicalOptionValue(
      row.querySelector('.action-key').value,
      ACTION_OPTIONS,
      ACTION_ALIASES
    );

    const data = { action_key: actionKey };

    if (actionKey === 'send_notification') {
      data.notification_key = row.querySelector('.action-notification-key').value;
    }

    if (actionKey === 'grant_extra_attempts') {
      data.extra_attempts = row.querySelector('.action-value').value;
    }

    if (actionKey === 'apply_deadline_extension') {
      data.extension_hours = row.querySelector('.action-value').value;
    }

    if (actionKey === 'create_required_action') {
      data.required_action_type = row.querySelector('.action-value').value;
    }

    rows.push(data);
  });

  return rows;
}

document.querySelectorAll('.af-flow-item').forEach(function(item){
  item.addEventListener('click', function(){
    const payload = JSON.parse(item.getAttribute('data-flow'));
    loadFlow(payload, item);
  });
});

document.getElementById('newFlowBtn').onclick = clearEditor;

document.getElementById('addConditionBtn').onclick = function(){
  conditionsWrap.insertAdjacentHTML('beforeend', conditionRowHtml());
  bindDynamicRows();
};

document.getElementById('addActionBtn').onclick = function(){
  actionsWrap.insertAdjacentHTML('beforeend', actionRowHtml());
  bindDynamicRows();
};

document.getElementById('saveFlowBtn').onclick = async function(){
  const res = await apiPost({
    action: 'save_flow',
    flow_id: parseInt(flowIdEl.value, 10) || 0,
    name: flowNameEl.value,
    description: flowDescriptionEl.value,
    event_key: flowEventEl.value,
    is_active: flowActiveEl.value === '1' ? 1 : 0,
    priority: parseInt(flowPriorityEl.value, 10) || 100,
    conditions: collectConditions(),
    actions: collectActions()
  });

  if (!res.ok) {
    alert(res.error || 'Save failed');
    return;
  }

  window.location.reload();
};

document.getElementById('deleteFlowBtn').onclick = async function(){
  const flowId = parseInt(flowIdEl.value, 10) || 0;
  if (flowId <= 0) {
    alert('Select a flow first.');
    return;
  }

  if (!confirm('Delete this flow?')) {
    return;
  }

  const res = await apiPost({
    action: 'delete_flow',
    flow_id: flowId
  });

  if (!res.ok) {
    alert(res.error || 'Delete failed');
    return;
  }

  window.location.reload();
};

document.getElementById('toggleFlowBtn').onclick = async function(){
  const flowId = parseInt(flowIdEl.value, 10) || 0;
  if (flowId <= 0) {
    alert('Select a flow first.');
    return;
  }

  const target = flowActiveEl.value === '1' ? 0 : 1;

  const res = await apiPost({
    action: 'toggle_flow',
    flow_id: flowId,
    is_active: target
  });

  if (!res.ok) {
    alert(res.error || 'Toggle failed');
    return;
  }

  window.location.reload();
};

document.getElementById('testFlowBtn').onclick = async function(){
  const flowId = parseInt(flowIdEl.value, 10) || 0;
  if (flowId <= 0) {
    alert('Save or select a flow first.');
    return;
  }

  let context = {};
  try {
    context = JSON.parse(testContextEl.value || '{}');
  } catch (e) {
    alert('Invalid JSON in test context.');
    return;
  }

  const res = await apiPost({
    action: 'test_flow',
    flow_id: flowId,
    context: context
  });

  if (!res.ok) {
    testResultEl.textContent = res.error || 'Test failed';
    return;
  }

  let text = '';
  text += 'MATCHED: ' + (res.matched ? 'YES' : 'NO') + '\n\n';

  if (res.checks && res.checks.length) {
    text += 'Condition Checks:\n';
    res.checks.forEach(function(check){
      text += '- ' + check.field_key + ' ' + check.operator + ' ' + check.expected
        + ' | actual=' + check.actual
        + ' | ' + (check.passed ? 'PASS' : 'FAIL') + '\n';
    });
  } else {
    text += 'No conditions on this flow.\n';
  }

  text += '\nActions That Would Run:\n';
  if (res.actions && res.actions.length) {
    res.actions.forEach(function(action){
      text += '- ' + action.action_key + ' | config=' + (action.config_json || '{}') + '\n';
    });
  } else {
    text += '- none\n';
  }

  testResultEl.textContent = text;
};

function escapeHtml(str) {
  return String(str || '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;');
}
</script>

<?php cw_footer(); ?>