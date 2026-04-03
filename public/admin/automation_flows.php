<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/automation_catalog.php';

cw_require_admin();

$u = cw_current_user($pdo);
$eventGroups = automation_event_grouped_options($pdo, true);
$conditionFields = automation_condition_field_options();
$operators = automation_operator_options();
$stmt = $pdo->query("
    SELECT action_key, label
    FROM automation_action_definitions
    WHERE is_active = 1
    ORDER BY sort_order ASC, label ASC
");
$actionOptions = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $actionOptions[$row['action_key']] = $row['label'];
}
$flowGroups = automation_flow_rows_grouped($pdo);

$notificationTemplates = array();
try {
    $stmt = $pdo->query("
        SELECT
            id,
            notification_key,
            name,
            is_enabled
        FROM notification_templates
        WHERE channel = 'email'
        ORDER BY is_enabled DESC, name ASC, id ASC
    ");
    $notificationTemplates = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
} catch (Throwable $e) {
    $notificationTemplates = array();
}

$activeAdmins = array();
try {
    $stmt = $pdo->query("
        SELECT
            id,
            email,
            name,
            first_name,
            last_name
        FROM users
        WHERE role = 'admin'
          AND status = 'active'
        ORDER BY COALESCE(name, ''), COALESCE(first_name, ''), COALESCE(last_name, ''), id ASC
    ");
    $activeAdmins = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
} catch (Throwable $e) {
    $activeAdmins = array();
}

$activeInstructors = array();
try {
    $stmt = $pdo->query("
        SELECT
            id,
            email,
            name,
            first_name,
            last_name,
            role
        FROM users
        WHERE role IN ('instructor', 'supervisor')
          AND status = 'active'
        ORDER BY COALESCE(name, ''), COALESCE(first_name, ''), COALESCE(last_name, ''), id ASC
    ");
    $activeInstructors = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
} catch (Throwable $e) {
    $activeInstructors = array();
}

cw_header('Automation Flows');
?>
<style>
:root{
  --af-border: rgba(15,23,42,.08);
  --af-border-soft: rgba(15,23,42,.06);
  --af-shadow: 0 10px 24px rgba(15,23,42,.05);
  --af-blue: #1d4f91;
  --af-blue-soft: #eff6ff;
  --af-text: #102845;
  --af-muted: #64748b;
  --af-danger: #991b1b;
  --af-success: #166534;
  --af-control-h: 44px;
  --af-radius: 18px;
  --af-radius-sm: 14px;
}

.af-wrap{
  display:grid;
  grid-template-columns:380px minmax(0,1fr);
  gap:24px;
  align-items:start;
}

.af-panel{
  background:#fff;
  border:1px solid var(--af-border);
  border-radius:var(--af-radius);
  box-shadow:var(--af-shadow);
}

.af-panel-head{
  padding:18px 20px;
  border-bottom:1px solid var(--af-border-soft);
}

.af-panel-body{
  padding:18px 20px;
}

.af-list-shell{
  max-height:72vh;
  overflow-y:auto;
  padding-right:4px;
}

.af-list-shell::-webkit-scrollbar{
  width:8px;
}

.af-list-shell::-webkit-scrollbar-thumb{
  background:rgba(15,23,42,.12);
  border-radius:999px;
}

.af-list{
  display:flex;
  flex-direction:column;
  gap:14px;
}

.af-group{
  display:flex;
  flex-direction:column;
  gap:10px;
}

.af-group-label{
  font-size:11px;
  font-weight:800;
  color:var(--af-muted);
  text-transform:uppercase;
  letter-spacing:.12em;
  padding:2px 2px 0 2px;
}

.af-item{
  border:1px solid var(--af-border);
  border-radius:var(--af-radius-sm);
  padding:14px;
  cursor:pointer;
  transition:border-color .16s ease, box-shadow .16s ease, transform .08s ease;
}

.af-item:hover{
  border-color:rgba(29,79,145,.18);
  box-shadow:0 8px 20px rgba(15,23,42,.06);
}

.af-item.active{
  border-color:var(--af-blue);
  box-shadow:0 0 0 3px rgba(29,79,145,.08);
}

.af-item-top{
  display:flex;
  justify-content:space-between;
  gap:12px;
  align-items:flex-start;
}

.af-item-name{
  font-weight:800;
  color:var(--af-text);
}

.af-item-meta{
  margin-top:6px;
  font-size:13px;
  color:var(--af-muted);
}

.af-pill{
  display:inline-flex;
  align-items:center;
  border-radius:999px;
  padding:3px 9px;
  font-size:12px;
  font-weight:700;
}

.af-pill.on{
  background:#dcfce7;
  color:var(--af-success);
}

.af-pill.off{
  background:#fee2e2;
  color:var(--af-danger);
}

.af-form-grid{
  display:grid;
  grid-template-columns:1fr 140px 120px;
  gap:12px;
}

.af-field{
  display:flex;
  flex-direction:column;
  gap:6px;
}

.af-field label{
  font-size:12px;
  font-weight:700;
  color:var(--af-muted);
  text-transform:uppercase;
  letter-spacing:.08em;
}

.af-field input,
.af-field select,
.af-field textarea{
  width:100%;
  box-sizing:border-box;
  border:1px solid rgba(15,23,42,.12);
  border-radius:12px;
  background:#fff;
  color:#102845;
  font:inherit;
}

.af-field input,
.af-field select{
  height:var(--af-control-h);
  min-height:var(--af-control-h);
  padding:0 12px;
  line-height:1.2;
  -webkit-appearance:none;
  appearance:none;
}

.af-field textarea{
  padding:10px 12px;
  min-height:92px;
  resize:vertical;
}

.af-field input:focus,
.af-field select:focus,
.af-field textarea:focus{
  outline:none;
  border-color:rgba(29,79,145,.35);
  box-shadow:0 0 0 3px rgba(29,79,145,.10);
}

.af-subtitle{
  font-size:12px;
  font-weight:800;
  color:var(--af-muted);
  text-transform:uppercase;
  letter-spacing:.1em;
  margin:0 0 10px;
}

.af-repeater{
  display:flex;
  flex-direction:column;
  gap:10px;
}

.af-row{
  display:grid;
  grid-template-columns:1fr 1fr 1fr auto;
  gap:10px;
  align-items:end;
  border:1px solid rgba(15,23,42,.06);
  border-radius:14px;
  padding:12px;
  background:#fbfdff;
}

.af-row.actions{
  grid-template-columns:minmax(220px,1fr) minmax(260px,1fr) auto;
  align-items:start;
}

.af-row.actions .af-field{
  align-self:start;
}

.af-empty{
  padding:14px;
  border:1px dashed rgba(15,23,42,.12);
  border-radius:12px;
  color:var(--af-muted);
  background:#fafcff;
}

.af-btn-row{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
}

.af-btn-secondary{
  background:#fff;
  color:#102845;
  border:1px solid rgba(15,23,42,.12);
}

.af-danger{
  background:#fff;
  color:var(--af-danger);
  border:1px solid #fecaca;
}

.af-help{
  font-size:12px;
  color:var(--af-muted);
  line-height:1.45;
}

.af-action-extra{
  display:flex;
  flex-direction:column;
  gap:8px;
}

.af-action-primary{
  display:flex;
  flex-direction:column;
  gap:6px;
}

.af-action-advanced{
  margin-top:2px;
}

.af-action-advanced summary{
  cursor:pointer;
  color:var(--af-blue);
  font-size:12px;
  font-weight:700;
}

.af-action-advanced[open] summary{
  margin-bottom:8px;
}

.af-inline-note{
  margin-top:6px;
  font-size:12px;
  color:var(--af-muted);
}

.af-hidden{
  display:none !important;
}

.af-toast{
  position:fixed;
  top:22px;
  right:22px;
  z-index:9999;
  min-width:260px;
  max-width:420px;
  padding:12px 16px;
  border-radius:14px;
  color:#fff;
  font-size:14px;
  font-weight:800;
  line-height:1.45;
  box-shadow:0 14px 34px rgba(15,23,42,.18);
  opacity:0;
  transform:translateY(-10px);
  pointer-events:none;
  transition:opacity .18s ease, transform .18s ease;
}

.af-toast.is-visible{
  opacity:1;
  transform:translateY(0);
}

.af-toast.is-success{
  background:#166534;
}

.af-toast.is-error{
  background:#991b1b;
}

.af-form-saving{
  opacity:.72;
  pointer-events:none;
}

@media (max-width: 1180px){
  .af-wrap{
    grid-template-columns:1fr;
  }

  .af-list-shell{
    max-height:46vh;
  }
}

@media (max-width: 760px){
  .af-form-grid{
    grid-template-columns:1fr;
  }

  .af-row,
  .af-row.actions{
    grid-template-columns:1fr;
  }
}
</style>

<div class="af-wrap">
  <section class="af-panel">
    <div class="af-panel-head">
      <h2 style="margin:0;">Flows</h2>
      <div class="muted" style="margin-top:6px;">Sorted by event category, then event, then priority and name.</div>
    </div>
    <div class="af-panel-body">
      <div class="af-btn-row" style="margin-bottom:14px;">
        <button class="btn" id="afNewBtn" type="button">New Flow</button>
      </div>

      <div class="af-list-shell">
        <div class="af-list" id="afFlowList">
          <?php if (empty($flowGroups)): ?>
            <div class="af-empty">No automation flows created yet.</div>
          <?php else: ?>
            <?php foreach ($flowGroups as $group): ?>
              <div class="af-group">
                <div class="af-group-label"><?= h((string)$group['category_label']) ?></div>

                <?php foreach ((array)$group['items'] as $flow): ?>
                  <div class="af-item" data-flow-id="<?= (int)$flow['id'] ?>">
                    <div class="af-item-top">
                      <div>
                        <div class="af-item-name"><?= h((string)$flow['name']) ?></div>
                        <div class="af-item-meta">
                          <strong><?= h((string)$flow['event_label']) ?></strong>
                        </div>
                      </div>
                      <div>
                        <?php if (!empty($flow['is_active'])): ?>
                          <span class="af-pill on">Active</span>
                        <?php else: ?>
                          <span class="af-pill off">Inactive</span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="af-item-meta" style="margin-top:10px;">
                      Priority <?= (int)$flow['priority'] ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <section class="af-panel">
    <div class="af-panel-head">
      <h2 style="margin:0;" id="afEditorTitle">Edit Flow</h2>
      <div class="muted" style="margin-top:6px;">Use categories for organization only. Flow logic still binds to the selected event key.</div>
    </div>
    <div class="af-panel-body">
      <form id="afForm">
        <input type="hidden" id="afId" value="0">

        <div class="af-form-grid">
          <div class="af-field">
            <label for="afName">Flow Name</label>
            <input id="afName" type="text" maxlength="255">
          </div>

          <div class="af-field">
            <label for="afPriority">Priority</label>
            <input id="afPriority" type="number" min="1" step="1" value="100">
          </div>

          <div class="af-field">
            <label for="afActive">Status</label>
            <select id="afActive">
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </div>
        </div>

        <div class="af-field" style="margin-top:14px;">
          <label for="afDescription">Description</label>
          <textarea id="afDescription" rows="3"></textarea>
        </div>

        <div class="af-field" style="margin-top:14px;">
          <label for="afEventKey">Trigger Event</label>
          <select id="afEventKey">
            <option value="">Select event</option>
            <?php foreach ($eventGroups as $group): ?>
              <optgroup label="<?= h((string)$group['category_label']) ?>">
                <?php foreach ((array)$group['items'] as $item): ?>
                  <option value="<?= h((string)$item['event_key']) ?>">
                    <?= h((string)$item['label']) ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="margin-top:22px;">
          <div class="af-subtitle">Conditions</div>
          <div id="afConditions" class="af-repeater"></div>
          <div class="af-btn-row" style="margin-top:10px;">
            <button class="btn af-btn-secondary" type="button" id="afAddConditionBtn">Add Condition</button>
          </div>
        </div>

        <div style="margin-top:22px;">
          <div class="af-subtitle">Actions</div>
          <div id="afActions" class="af-repeater"></div>
          <div class="af-btn-row" style="margin-top:10px;">
            <button class="btn af-btn-secondary" type="button" id="afAddActionBtn">Add Action</button>
          </div>
          <div class="af-inline-note">Action-specific fields will adapt automatically. Advanced Config JSON remains available for optional fine tuning.</div>
        </div>

        <div class="af-btn-row" style="margin-top:24px;">
          <button class="btn" type="submit">Save Flow</button>
          <button class="btn af-btn-secondary" type="button" id="afResetBtn">Reset</button>
          <button class="btn af-danger" type="button" id="afDeleteBtn">Delete</button>
        </div>
      </form>
    </div>
  </section>
</div>


<div id="afToast" class="af-toast" aria-live="polite" aria-atomic="true"></div>

<script>
(function () {
  const apiUrl = 'api/automation_flows_api.php';
  const flowListEl = document.getElementById('afFlowList');
  const formEl = document.getElementById('afForm');
  const editorTitleEl = document.getElementById('afEditorTitle');
  const toastEl = document.getElementById('afToast');

  const idEl = document.getElementById('afId');
  const nameEl = document.getElementById('afName');
  const descEl = document.getElementById('afDescription');
  const eventKeyEl = document.getElementById('afEventKey');
  const priorityEl = document.getElementById('afPriority');
  const activeEl = document.getElementById('afActive');

  const conditionsEl = document.getElementById('afConditions');
  const actionsEl = document.getElementById('afActions');

  const newBtn = document.getElementById('afNewBtn');
  const resetBtn = document.getElementById('afResetBtn');
  const deleteBtn = document.getElementById('afDeleteBtn');
  const addConditionBtn = document.getElementById('afAddConditionBtn');
  const addActionBtn = document.getElementById('afAddActionBtn');

  const conditionFieldOptions = <?= json_encode($conditionFields) ?>;
  const operatorOptions = <?= json_encode($operators) ?>;
  const actionOptions = <?= json_encode($actionOptions) ?>;
  const emailTemplates = <?= json_encode($notificationTemplates) ?>;
  const activeAdmins = <?= json_encode($activeAdmins) ?>;
  const activeInstructors = <?= json_encode($activeInstructors) ?>;
  const eventGroups = <?= json_encode($eventGroups) ?>;

  let toastTimer = null;

  function esc(str) {
    return String(str === null || str === undefined ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function showToast(message, type) {
    if (!toastEl) return;

    toastEl.textContent = message || '';
    toastEl.className = 'af-toast ' + (type === 'error' ? 'is-error' : 'is-success') + ' is-visible';

    if (toastTimer) {
      clearTimeout(toastTimer);
    }

    toastTimer = setTimeout(function () {
      toastEl.className = 'af-toast';
    }, 2400);
  }

  function setSaving(isSaving) {
    if (isSaving) {
      formEl.classList.add('af-form-saving');
    } else {
      formEl.classList.remove('af-form-saving');
    }
  }

  function eventLabelForKey(eventKey) {
    let found = eventKey || '';
    (eventGroups || []).forEach(function (group) {
      (group.items || []).forEach(function (item) {
        if (String(item.event_key) === String(eventKey)) {
          found = item.label || item.event_key || eventKey;
        }
      });
    });
    return found;
  }

  function renderFlowListItem(flow) {
    const isActive = !!parseInt(flow.is_active || 0, 10);
    const eventLabel = eventLabelForKey(flow.event_key || '');
    return '' +
      '<div class="af-item active" data-flow-id="' + esc(flow.id) + '">' +
        '<div class="af-item-top">' +
          '<div>' +
            '<div class="af-item-name">' + esc(flow.name || '') + '</div>' +
            '<div class="af-item-meta"><strong>' + esc(eventLabel) + '</strong></div>' +
          '</div>' +
          '<div>' +
            (isActive
              ? '<span class="af-pill on">Active</span>'
              : '<span class="af-pill off">Inactive</span>') +
          '</div>' +
        '</div>' +
        '<div class="af-item-meta" style="margin-top:10px;">Priority ' + esc(flow.priority || 100) + '</div>' +
      '</div>';
  }

  function upsertFlowListItem(flow) {
    if (!flow || !flow.id) return;

    const existing = flowListEl.querySelector('.af-item[data-flow-id="' + String(flow.id) + '"]');
    const html = renderFlowListItem(flow);

    document.querySelectorAll('.af-item').forEach(function (el) {
      el.classList.remove('active');
    });

    if (existing) {
      existing.outerHTML = html;
    } else {
      const firstGroupItems = flowListEl.querySelector('.af-group');
      if (firstGroupItems) {
        firstGroupItems.insertAdjacentHTML('beforeend', html);
      } else {
        flowListEl.innerHTML = '<div class="af-group"><div class="af-group-label">Flows</div>' + html + '</div>';
      }
    }
  }

  function removeFlowListItem(flowId) {
    const existing = flowListEl.querySelector('.af-item[data-flow-id="' + String(flowId) + '"]');
    if (existing) {
      existing.remove();
    }
  }

  function optionHtml(map, selected) {
    let html = '';
    Object.keys(map).forEach(function (key) {
      html += '<option value="' + esc(key) + '"' + (String(selected) === String(key) ? ' selected' : '') + '>' + esc(map[key]) + '</option>';
    });
    return html;
  }

  function userOptionsHtml(users, selectedId) {
    let html = '<option value="">Select user</option>';
    (users || []).forEach(function (user) {
      const id = String(user.id || '');
      let label = String(user.name || '').trim();

      if (label === '') {
        label = (String(user.first_name || '') + ' ' + String(user.last_name || '')).trim();
      }
      if (label === '') {
        label = String(user.email || ('User #' + id));
      } else if (user.email) {
        label += ' (' + String(user.email) + ')';
      }

      html += '<option value="' + esc(id) + '"' + (String(selectedId) === id ? ' selected' : '') + '>' + esc(label) + '</option>';
    });
    return html;
  }

  function requiredActionTypeOptionsHtml(selectedValue) {
    const options = {
      remediation_acknowledgement: 'Remediation Acknowledgement',
      deadline_reason_submission: 'Deadline Reason Submission',
      instructor_approval: 'Instructor Approval'
    };

    let html = '<option value="">Select required action</option>';
    Object.keys(options).forEach(function (key) {
      html += '<option value="' + esc(key) + '"' + (String(selectedValue) === String(key) ? ' selected' : '') + '>' + esc(options[key]) + '</option>';
    });
    return html;
  }

  function conditionRowHtml(row) {
    row = row || {};
    return '' +
      '<div class="af-row" data-kind="condition">' +
        '<div class="af-field">' +
          '<label>Field</label>' +
          '<select class="af-cond-field">' +
            '<option value="">Select field</option>' +
            optionHtml(conditionFieldOptions, row.field_key || '') +
          '</select>' +
        '</div>' +
        '<div class="af-field">' +
          '<label>Operator</label>' +
          '<select class="af-cond-operator">' +
            '<option value="">Select operator</option>' +
            optionHtml(operatorOptions, row.operator || '') +
          '</select>' +
        '</div>' +
        '<div class="af-field">' +
          '<label>Value</label>' +
          '<input class="af-cond-value" type="text" value="' + esc(row.value_text || row.value_number || '') + '">' +
        '</div>' +
        '<div class="af-field">' +
          '<button class="btn af-danger af-remove-row" type="button">Remove</button>' +
        '</div>' +
      '</div>';
  }

  function parseActionConfig(row) {
    let config = {};
    if (row && row.config_json && typeof row.config_json === 'object') {
      config = row.config_json;
    } else if (row && typeof row.config_json === 'string' && row.config_json.trim() !== '') {
      try {
        config = JSON.parse(row.config_json);
      } catch (e) {
        config = {};
      }
    }
    return config;
  }

   function dynamicActionFieldHtml(actionKey, config) {
    const selectedTemplateId = config.notification_template_id || config.template_id || '';
    const requiredActionType = config.required_action_type || '';
    const logEventCode = config.event_code || '';
    const selectedAdminUserId = config.recipient_user_id || '';
    const selectedInstructorUserId = config.recipient_user_id || '';

    if (actionKey === 'send_email') {
      return '' +
        '<div class="af-action-primary" data-dynamic-kind="send_email">' +
          '<div class="af-field">' +
            '<label>Email Template</label>' +
            '<select class="af-email-template-id">' +
              emailTemplateOptionsHtml(selectedTemplateId) +
            '</select>' +
            '<div class="af-help">Choose the notification template used for this email action.</div>' +
          '</div>' +
        '</div>';
    }

    if (actionKey === 'notify_all_admins') {
      return '' +
        '<div class="af-action-primary" data-dynamic-kind="notify_all_admins">' +
          '<div class="af-field">' +
            '<label>Email Template</label>' +
            '<select class="af-notify-all-admins-template-id">' +
              emailTemplateOptionsHtml(selectedTemplateId) +
            '</select>' +
            '<div class="af-help">Send this template to all active admin users.</div>' +
          '</div>' +
        '</div>';
    }

    if (actionKey === 'notify_all_instructors') {
      return '' +
        '<div class="af-action-primary" data-dynamic-kind="notify_all_instructors">' +
          '<div class="af-field">' +
            '<label>Email Template</label>' +
            '<select class="af-notify-all-instructors-template-id">' +
              emailTemplateOptionsHtml(selectedTemplateId) +
            '</select>' +
            '<div class="af-help">Send this template to all active instructor users.</div>' +
          '</div>' +
        '</div>';
    }

    if (actionKey === 'notify_all_students') {
      return '' +
        '<div class="af-action-primary" data-dynamic-kind="notify_all_students">' +
          '<div class="af-field">' +
            '<label>Email Template</label>' +
            '<select class="af-notify-all-students-template-id">' +
              emailTemplateOptionsHtml(selectedTemplateId) +
            '</select>' +
            '<div class="af-help">Send this template to all active student users.</div>' +
          '</div>' +
        '</div>';
    }

    if (actionKey === 'notify_specific_admin') {
      return '' +
        '<div class="af-action-primary" data-dynamic-kind="notify_specific_admin">' +
          '<div class="af-field">' +
            '<label>Admin User</label>' +
            '<select class="af-recipient-admin-user-id">' +
              userOptionsHtml(activeAdmins, selectedAdminUserId) +
            '</select>' +
          '</div>' +
          '<div class="af-field">' +
            '<label>Email Template</label>' +
            '<select class="af-notify-specific-admin-template-id">' +
              emailTemplateOptionsHtml(selectedTemplateId) +
            '</select>' +
          '</div>' +
        '</div>';
    }

    if (actionKey === 'notify_specific_instructor') {
      return '' +
        '<div class="af-action-primary" data-dynamic-kind="notify_specific_instructor">' +
          '<div class="af-field">' +
            '<label>Instructor User</label>' +
            '<select class="af-recipient-instructor-user-id">' +
              userOptionsHtml(activeInstructors, selectedInstructorUserId) +
            '</select>' +
          '</div>' +
          '<div class="af-field">' +
            '<label>Email Template</label>' +
            '<select class="af-notify-specific-instructor-template-id">' +
              emailTemplateOptionsHtml(selectedTemplateId) +
            '</select>' +
          '</div>' +
        '</div>';
    }

    if (actionKey === 'create_required_action') {
      return '' +
        '<div class="af-action-primary" data-dynamic-kind="create_required_action">' +
          '<div class="af-field">' +
            '<label>Required Action</label>' +
            '<select class="af-required-action-type">' +
              requiredActionTypeOptionsHtml(requiredActionType) +
            '</select>' +
            '<div class="af-help">Choose which required action should be created for the student.</div>' +
          '</div>' +
        '</div>';
    }

    if (actionKey === 'log_event') {
      return '' +
        '<div class="af-action-primary" data-dynamic-kind="log_event">' +
          '<div class="af-field">' +
            '<label>Event Code</label>' +
            '<input class="af-log-event-code" type="text" value="' + esc(logEventCode) + '">' +
            '<div class="af-help">Short event code to log when this action runs.</div>' +
          '</div>' +
        '</div>';
    }

    return '' +
      '<div class="af-action-primary" data-dynamic-kind="none">' +
        '<div class="af-field">' +
          '<label>Action Details</label>' +
          '<input type="text" value="" disabled>' +
          '<div class="af-help">No guided field for this action. Use Advanced Config JSON if needed.</div>' +
        '</div>' +
      '</div>';
  }

  function actionRowHtml(row) {
    row = row || {};
    const config = parseActionConfig(row);
    const actionKey = row.action_key || '';
    const advancedJson = Object.keys(config).length ? JSON.stringify(config, null, 2) : '';

    return '' +
      '<div class="af-row actions" data-kind="action">' +
        '<div class="af-field">' +
          '<label>Action</label>' +
          '<select class="af-action-key">' +
            '<option value="">Select action</option>' +
            optionHtml(actionOptions, actionKey) +
          '</select>' +
        '</div>' +
        '<div class="af-action-extra">' +
          dynamicActionFieldHtml(actionKey, config) +
          '<details class="af-action-advanced"' + (advancedJson !== '' ? ' open' : '') + '>' +
            '<summary>Advanced Config JSON</summary>' +
            '<div class="af-field">' +
              '<textarea class="af-action-config" rows="4">' + esc(advancedJson) + '</textarea>' +
            '</div>' +
          '</details>' +
        '</div>' +
        '<div class="af-field">' +
          '<label>&nbsp;</label>' +
          '<button class="btn af-danger af-remove-row" type="button">Remove</button>' +
        '</div>' +
      '</div>';
  }

  function rerenderActionDynamicField(row) {
    if (!row) return;

    const actionKeyEl = row.querySelector('.af-action-key');
    const dynamicHost = row.querySelector('.af-action-extra');
    const advanced = row.querySelector('.af-action-advanced');
    const configTextarea = row.querySelector('.af-action-config');

    if (!actionKeyEl || !dynamicHost || !advanced || !configTextarea) {
      return;
    }

    let config = {};
    const raw = configTextarea.value.trim();
    if (raw !== '') {
      try {
        config = JSON.parse(raw);
      } catch (e) {
        config = {};
      }
    }

    const oldPrimary = dynamicHost.querySelector('.af-action-primary');
    if (oldPrimary) {
      oldPrimary.remove();
    }

    advanced.insertAdjacentHTML('beforebegin', dynamicActionFieldHtml(actionKeyEl.value, config));
  }

  function resetForm() {
    idEl.value = '0';
    nameEl.value = '';
    descEl.value = '';
    eventKeyEl.value = '';
    priorityEl.value = '100';
    activeEl.value = '1';
    conditionsEl.innerHTML = '';
    actionsEl.innerHTML = '';
    editorTitleEl.textContent = 'New Flow';

    document.querySelectorAll('.af-item').forEach(function (el) {
      el.classList.remove('active');
    });
  }

  function addConditionRow(row) {
    conditionsEl.insertAdjacentHTML('beforeend', conditionRowHtml(row));
  }

  function addActionRow(row) {
    actionsEl.insertAdjacentHTML('beforeend', actionRowHtml(row));
  }

  function collectConditions() {
    const rows = [];
    conditionsEl.querySelectorAll('[data-kind="condition"]').forEach(function (row) {
      rows.push({
        field_key: row.querySelector('.af-cond-field').value,
        operator: row.querySelector('.af-cond-operator').value,
        value_text: row.querySelector('.af-cond-value').value,
        value_number: null
      });
    });
    return rows;
  }

  function collectActions() {
    const rows = [];
    actionsEl.querySelectorAll('[data-kind="action"]').forEach(function (row) {
      const actionKey = row.querySelector('.af-action-key').value;
      const raw = row.querySelector('.af-action-config').value.trim();
      let configObj = {};

      if (raw !== '') {
        try {
          configObj = JSON.parse(raw);
        } catch (e) {
          throw new Error('Invalid action config JSON');
        }
      }

      if (actionKey === 'send_email') {
        const templateIdEl = row.querySelector('.af-email-template-id');
        const templateId = templateIdEl ? templateIdEl.value : '';
        const selectedTemplate = emailTemplates.find(function (tpl) {
          return String(tpl.id) === String(templateId);
        }) || null;

        delete configObj.required_action_type;
        delete configObj.event_code;
        delete configObj.notify_role;

        if (templateId !== '') {
          configObj.notification_template_id = parseInt(templateId, 10);
        } else {
          delete configObj.notification_template_id;
        }

        if (selectedTemplate && selectedTemplate.notification_key) {
          configObj.notification_key = String(selectedTemplate.notification_key);
        } else {
          delete configObj.notification_key;
        }
      } else if (actionKey === 'create_required_action') {
        const requiredActionTypeEl = row.querySelector('.af-required-action-type');
        const requiredActionType = requiredActionTypeEl ? requiredActionTypeEl.value : '';

        delete configObj.notification_template_id;
        delete configObj.notification_key;
        delete configObj.event_code;
        delete configObj.notify_role;

        if (requiredActionType !== '') {
          configObj.required_action_type = requiredActionType;
        } else {
          delete configObj.required_action_type;
        }
      } else if (actionKey === 'log_event') {
        const logEventCodeEl = row.querySelector('.af-log-event-code');
        const eventCode = logEventCodeEl ? logEventCodeEl.value.trim() : '';

        delete configObj.notification_template_id;
        delete configObj.notification_key;
        delete configObj.required_action_type;
        delete configObj.notify_role;

        if (eventCode !== '') {
          configObj.event_code = eventCode;
        } else {
          delete configObj.event_code;
        }
      } else if (actionKey === 'notify_all_admins') {
        const templateIdEl = row.querySelector('.af-notify-all-admins-template-id');
        const templateId = templateIdEl ? templateIdEl.value : '';
        const selectedTemplate = emailTemplates.find(function (tpl) {
          return String(tpl.id) === String(templateId);
        }) || null;

        delete configObj.recipient_user_id;
        delete configObj.required_action_type;
        delete configObj.event_code;

        if (templateId !== '') {
          configObj.notification_template_id = parseInt(templateId, 10);
        } else {
          delete configObj.notification_template_id;
        }

        if (selectedTemplate && selectedTemplate.notification_key) {
          configObj.notification_key = String(selectedTemplate.notification_key);
        } else {
          delete configObj.notification_key;
        }
      } else if (actionKey === 'notify_all_instructors') {
        const templateIdEl = row.querySelector('.af-notify-all-instructors-template-id');
        const templateId = templateIdEl ? templateIdEl.value : '';
        const selectedTemplate = emailTemplates.find(function (tpl) {
          return String(tpl.id) === String(templateId);
        }) || null;

        delete configObj.recipient_user_id;
        delete configObj.required_action_type;
        delete configObj.event_code;

        if (templateId !== '') {
          configObj.notification_template_id = parseInt(templateId, 10);
        } else {
          delete configObj.notification_template_id;
        }

        if (selectedTemplate && selectedTemplate.notification_key) {
          configObj.notification_key = String(selectedTemplate.notification_key);
        } else {
          delete configObj.notification_key;
        }
      } else if (actionKey === 'notify_all_students') {
        const templateIdEl = row.querySelector('.af-notify-all-students-template-id');
        const templateId = templateIdEl ? templateIdEl.value : '';
        const selectedTemplate = emailTemplates.find(function (tpl) {
          return String(tpl.id) === String(templateId);
        }) || null;

        delete configObj.recipient_user_id;
        delete configObj.required_action_type;
        delete configObj.event_code;

        if (templateId !== '') {
          configObj.notification_template_id = parseInt(templateId, 10);
        } else {
          delete configObj.notification_template_id;
        }

        if (selectedTemplate && selectedTemplate.notification_key) {
          configObj.notification_key = String(selectedTemplate.notification_key);
        } else {
          delete configObj.notification_key;
        }
      } else if (actionKey === 'notify_specific_admin') {
        const userIdEl = row.querySelector('.af-recipient-admin-user-id');
        const templateIdEl = row.querySelector('.af-notify-specific-admin-template-id');
        const userId = userIdEl ? userIdEl.value : '';
        const templateId = templateIdEl ? templateIdEl.value : '';
        const selectedTemplate = emailTemplates.find(function (tpl) {
          return String(tpl.id) === String(templateId);
        }) || null;

        delete configObj.required_action_type;
        delete configObj.event_code;

        if (userId !== '') {
          configObj.recipient_user_id = parseInt(userId, 10);
        } else {
          delete configObj.recipient_user_id;
        }

        if (templateId !== '') {
          configObj.notification_template_id = parseInt(templateId, 10);
        } else {
          delete configObj.notification_template_id;
        }

        if (selectedTemplate && selectedTemplate.notification_key) {
          configObj.notification_key = String(selectedTemplate.notification_key);
        } else {
          delete configObj.notification_key;
        }
      } else if (actionKey === 'notify_specific_instructor') {
        const userIdEl = row.querySelector('.af-recipient-instructor-user-id');
        const templateIdEl = row.querySelector('.af-notify-specific-instructor-template-id');
        const userId = userIdEl ? userIdEl.value : '';
        const templateId = templateIdEl ? templateIdEl.value : '';
        const selectedTemplate = emailTemplates.find(function (tpl) {
          return String(tpl.id) === String(templateId);
        }) || null;

        delete configObj.required_action_type;
        delete configObj.event_code;

        if (userId !== '') {
          configObj.recipient_user_id = parseInt(userId, 10);
        } else {
          delete configObj.recipient_user_id;
        }

        if (templateId !== '') {
          configObj.notification_template_id = parseInt(templateId, 10);
        } else {
          delete configObj.notification_template_id;
        }

        if (selectedTemplate && selectedTemplate.notification_key) {
          configObj.notification_key = String(selectedTemplate.notification_key);
        } else {
          delete configObj.notification_key;
        }
      } else {
        delete configObj.notification_template_id;
        delete configObj.notification_key;
        delete configObj.required_action_type;
        delete configObj.event_code;
        delete configObj.notify_role;
      }

      rows.push({
        action_key: actionKey,
        config_json: configObj
      });
    });
    return rows;
  }

  async function apiRequest(payload, method = 'POST') {
    const res = await fetch(apiUrl, {
      method: method,
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: method === 'POST' ? JSON.stringify(payload) : undefined
    });

    const data = await res.json();
    if (!data.ok) {
      throw new Error(data.error || 'Request failed');
    }
    return data;
  }

  async function loadDetail(flowId) {
    const detailUrl = apiUrl + '?mode=detail&id=' + encodeURIComponent(String(flowId));

    const res = await fetch(detailUrl, {
      method: 'GET',
      headers: { 'Accept': 'application/json' }
    });

    const data = await res.json();
    if (!data.ok) {
      throw new Error(data.error || 'Could not load flow');
    }

    const flow = data.flow || {};
    resetForm();

    idEl.value = String(flow.id || 0);
    nameEl.value = flow.name || '';
    descEl.value = flow.description || '';
    eventKeyEl.value = flow.event_key || '';
    priorityEl.value = String(flow.priority || 100);
    activeEl.value = String(flow.is_active ? 1 : 0);
    editorTitleEl.textContent = 'Edit Flow';

    (flow.conditions || []).forEach(function (row) {
      addConditionRow(row);
    });

    (flow.actions || []).forEach(function (row) {
      addActionRow(row);
    });

    document.querySelectorAll('.af-item').forEach(function (el) {
      el.classList.toggle('active', String(el.getAttribute('data-flow-id')) === String(flow.id));
    });
  }

  flowListEl.addEventListener('click', function (e) {
    const item = e.target.closest('.af-item');
    if (!item) return;
    const id = item.getAttribute('data-flow-id');
    if (!id) return;

    loadDetail(id).catch(function (err) {
      showToast(err.message, 'error');
    });
  });

  newBtn.addEventListener('click', function () {
    resetForm();
  });

  resetBtn.addEventListener('click', function () {
    resetForm();
  });

  addConditionBtn.addEventListener('click', function () {
    addConditionRow({});
  });

  addActionBtn.addEventListener('click', function () {
    addActionRow({});
  });

  document.addEventListener('click', function (e) {
    if (e.target.classList.contains('af-remove-row')) {
      const row = e.target.closest('.af-row');
      if (row) {
        row.remove();
      }
      return;
    }
  });

  document.addEventListener('change', function (e) {
    if (e.target.classList.contains('af-action-key')) {
      const row = e.target.closest('[data-kind="action"]');
      rerenderActionDynamicField(row);
    }
  });

  formEl.addEventListener('submit', async function (e) {
    e.preventDefault();
    setSaving(true);

    try {
      const payload = {
        action: 'save_flow',
        id: parseInt(idEl.value || '0', 10),
        name: nameEl.value.trim(),
        description: descEl.value.trim(),
        event_key: eventKeyEl.value,
        priority: parseInt(priorityEl.value || '100', 10),
        is_active: activeEl.value === '1' ? 1 : 0,
        conditions: collectConditions(),
        actions: collectActions()
      };

      const data = await apiRequest(payload, 'POST');

      if (data.flow && data.flow.id) {
        upsertFlowListItem(data.flow);
        await loadDetail(data.flow.id);
      }

      showToast('Flow updated successfully.', 'success');
    } catch (err) {
      showToast(err.message, 'error');
    } finally {
      setSaving(false);
    }
  });

  deleteBtn.addEventListener('click', async function () {
    const flowId = parseInt(idEl.value || '0', 10);
    if (flowId <= 0) {
      showToast('No saved flow selected.', 'error');
      return;
    }

    if (!window.confirm('Delete this flow?')) {
      return;
    }

    setSaving(true);

    try {
      await apiRequest({
        action: 'delete_flow',
        id: flowId
      }, 'POST');

      removeFlowListItem(flowId);
      resetForm();
      showToast('Flow deleted successfully.', 'success');
    } catch (err) {
      showToast(err.message, 'error');
    } finally {
      setSaving(false);
    }
  });
})();
</script>



<?php cw_footer(); ?>