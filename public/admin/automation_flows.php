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
$actionOptions = automation_action_options();
$flows = automation_flow_rows($pdo);

cw_header('Automation Flows');
?>
<style>
.af-wrap{display:grid;grid-template-columns:360px minmax(0,1fr);gap:24px}
.af-panel{background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:18px;box-shadow:0 10px 24px rgba(15,23,42,.05)}
.af-panel-head{padding:18px 20px;border-bottom:1px solid rgba(15,23,42,.06)}
.af-panel-body{padding:18px 20px}
.af-list{display:flex;flex-direction:column;gap:12px}
.af-item{border:1px solid rgba(15,23,42,.08);border-radius:14px;padding:14px;cursor:pointer}
.af-item.active{border-color:#1d4f91;box-shadow:0 0 0 3px rgba(29,79,145,.08)}
.af-item-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
.af-item-name{font-weight:800;color:#102845}
.af-item-meta{margin-top:6px;font-size:13px;color:#64748b}
.af-pill{display:inline-flex;align-items:center;border-radius:999px;padding:3px 9px;font-size:12px;font-weight:700}
.af-pill.on{background:#dcfce7;color:#166534}
.af-pill.off{background:#fee2e2;color:#991b1b}
.af-pill.cat{background:#eff6ff;color:#1d4f91}
.af-form-grid{display:grid;grid-template-columns:1fr 140px 120px;gap:12px}
.af-field{display:flex;flex-direction:column;gap:6px}
.af-field label{font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.08em}
.af-field input,.af-field select,.af-field textarea{width:100%;box-sizing:border-box}
.af-subtitle{font-size:12px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin:0 0 10px}
.af-repeater{display:flex;flex-direction:column;gap:10px}
.af-row{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:end}
.af-row.actions{grid-template-columns:1fr 2fr auto}
.af-empty{padding:14px;border:1px dashed rgba(15,23,42,.12);border-radius:12px;color:#64748b;background:#fafcff}
.af-btn-row{display:flex;gap:10px;flex-wrap:wrap}
.af-btn-secondary{background:#fff;color:#102845;border:1px solid rgba(15,23,42,.12)}
.af-danger{background:#fff;color:#991b1b;border:1px solid #fecaca}
@media (max-width: 1180px){
  .af-wrap{grid-template-columns:1fr}
}
</style>

<div class="af-wrap">
  <section class="af-panel">
    <div class="af-panel-head">
      <h2 style="margin:0;">Flows</h2>
      <div class="muted" style="margin-top:6px;">Grouped by event category in the selector, with execution still driven by event_key.</div>
    </div>
    <div class="af-panel-body">
      <div class="af-btn-row" style="margin-bottom:14px;">
        <button class="btn" id="afNewBtn" type="button">New Flow</button>
      </div>

      <div class="af-list" id="afFlowList">
        <?php if (empty($flows)): ?>
          <div class="af-empty">No automation flows created yet.</div>
        <?php else: ?>
          <?php foreach ($flows as $flow): ?>
            <div class="af-item" data-flow-id="<?= (int)$flow['id'] ?>">
              <div class="af-item-top">
                <div>
                  <div class="af-item-name"><?= h((string)$flow['name']) ?></div>
                  <div class="af-item-meta">
                    <span class="af-pill cat"><?= h((string)$flow['category_label']) ?></span>
                    &nbsp;
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
        <?php endif; ?>
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

<script>
(function () {
  const apiUrl = 'api/automation_flows_api.php';
  const flowListEl = document.getElementById('afFlowList');
  const formEl = document.getElementById('afForm');
  const editorTitleEl = document.getElementById('afEditorTitle');

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

  function esc(str) {
    return String(str === null || str === undefined ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function optionHtml(map, selected) {
    let html = '';
    Object.keys(map).forEach(function (key) {
      html += '<option value="' + esc(key) + '"' + (String(selected) === String(key) ? ' selected' : '') + '>' + esc(map[key]) + '</option>';
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

  function actionRowHtml(row) {
    row = row || {};
    let configText = '';
    if (row.config_json && typeof row.config_json === 'string') {
      configText = row.config_json;
    } else if (row.config_json && typeof row.config_json === 'object') {
      configText = JSON.stringify(row.config_json);
    }

    return '' +
      '<div class="af-row actions" data-kind="action">' +
        '<div class="af-field">' +
          '<label>Action</label>' +
          '<select class="af-action-key">' +
            '<option value="">Select action</option>' +
            optionHtml(actionOptions, row.action_key || '') +
          '</select>' +
        '</div>' +
        '<div class="af-field">' +
          '<label>Config JSON</label>' +
          '<textarea class="af-action-config" rows="2">' + esc(configText) + '</textarea>' +
        '</div>' +
        '<div class="af-field">' +
          '<button class="btn af-danger af-remove-row" type="button">Remove</button>' +
        '</div>' +
      '</div>';
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
      let configObj = {};
      const raw = row.querySelector('.af-action-config').value.trim();
      if (raw !== '') {
        try {
          configObj = JSON.parse(raw);
        } catch (e) {
          throw new Error('Invalid action config JSON');
        }
      }

      rows.push({
        action_key: row.querySelector('.af-action-key').value,
        config_json: configObj
      });
    });
    return rows;
  }

  async function apiRequest(payload, method = 'POST') {
    const res = await fetch(apiUrl, {
      method: method,
      headers: { 'Content-Type': 'application/json' },
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
      alert(err.message);
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
    if (!e.target.classList.contains('af-remove-row')) return;
    const row = e.target.closest('.af-row');
    if (row) {
      row.remove();
    }
  });

  formEl.addEventListener('submit', async function (e) {
    e.preventDefault();

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

      await apiRequest(payload, 'POST');
      window.location.reload();
    } catch (err) {
      alert(err.message);
    }
  });

  deleteBtn.addEventListener('click', async function () {
    const flowId = parseInt(idEl.value || '0', 10);
    if (flowId <= 0) {
      alert('No saved flow selected.');
      return;
    }

    if (!window.confirm('Delete this flow?')) {
      return;
    }

    try {
      await apiRequest({
        action: 'delete_flow',
        id: flowId
      }, 'POST');
      window.location.reload();
    } catch (err) {
      alert(err.message);
    }
  });
})();
</script>

<?php cw_footer(); ?>