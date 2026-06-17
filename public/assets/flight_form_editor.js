(function () {
  'use strict';

  var root = document.getElementById('flightFormEditorRoot');
  if (!root || !window.StructuredDocumentCore) return;

  var initial = window.flightFormInitialData || {};
  var template = initial.template || {};
  var version = initial.version || {};
  var editable = !!initial.editable;
  var canvas = document.getElementById('ffedCanvas');
  var statusEl = document.getElementById('ffedSaveStatus');
  var fieldList = document.getElementById('ffedFieldList');
  var variableList = document.getElementById('ffedVariableList');
  var meta = document.getElementById('ffedTemplateMeta');
  var fieldForm = document.getElementById('ffedFieldSettings');
  var saveBtn = document.getElementById('ffedSave');
  var selectedFieldBlockKey = '';

  var editor = window.StructuredDocumentCore.createEditor({
    document: initial.document || {},
    canvas: canvas,
    status: statusEl,
    editable: editable,
    onSelectBlock: function (block) {
      if (block && isFieldBlock(block)) {
        selectedFieldBlockKey = block.block_key;
        fillFieldForm(block);
      }
    },
    onSelectField: function (block) {
      if (!block) return;
      selectedFieldBlockKey = block.block_key;
      fillFieldForm(block);
    },
  });

  function isFieldBlock(block) {
    return block && ['checkbox', 'field', 'date', 'signature', 'initial'].indexOf(block.block_type) >= 0;
  }

  function h(value) {
    return window.StructuredDocumentCore.escapeHtml(value == null ? '' : value);
  }

  function renderMeta() {
    if (!meta) return;
    meta.innerHTML = ''
      + '<dt>Title</dt><dd>' + h(template.title || '') + '</dd>'
      + '<dt>Key</dt><dd><code>' + h(template.template_key || '') + '</code></dd>'
      + '<dt>Status</dt><dd>' + h(template.status || '') + '</dd>'
      + '<dt>Version</dt><dd>v' + h(version.version_label || '') + ' · ' + h(version.lifecycle_status || '') + '</dd>'
      + '<dt>Editable</dt><dd>' + (editable ? 'Yes, draft version' : 'No, active or archived') + '</dd>';
  }

  function collectFields() {
    return editor.state.document.blocks.filter(isFieldBlock).map(function (block) {
      var p = block.payload || {};
      return {
        block_key: block.block_key,
        field_key: p.field_key || '',
        field_type: p.field_type || block.block_type,
        label: p.label || '',
        required: !!p.required,
        assigned_role: p.assigned_role || '',
        variable_key: p.variable_key || '',
      };
    });
  }

  function renderFields() {
    if (!fieldList) return;
    var fields = collectFields();
    if (!fields.length) {
      fieldList.innerHTML = '<div class="ffed-empty">No fields yet.</div>';
      return;
    }
    fieldList.innerHTML = fields.map(function (field) {
      return '<button type="button" class="ffed-field-item" data-block-key="' + h(field.block_key) + '">'
        + '<strong>' + h(field.label || field.field_key || 'Field') + '</strong>'
        + '<span>' + h(field.field_type) + ' · ' + h(field.assigned_role || 'instructor') + (field.required ? ' · required' : '') + '</span>'
        + (field.variable_key ? '<code>' + h(field.variable_key) + '</code>' : '')
        + '</button>';
    }).join('');
  }

  function renderVariables() {
    if (!variableList) return;
    var groups = initial.variables || [];
    variableList.innerHTML = groups.map(function (group) {
      var vars = Array.isArray(group.variables) ? group.variables : [];
      return '<section class="ffed-variable-group">'
        + '<h3>' + h(group.group || '') + '</h3>'
        + vars.map(function (item) {
          return '<button type="button" class="ffed-variable-item" data-variable-key="' + h(item.key || '') + '">'
            + '<strong>' + h(item.label || item.key || '') + '</strong>'
            + '<code>' + h(item.token || '') + '</code>'
            + '</button>';
        }).join('')
        + '</section>';
    }).join('');
  }

  function fillFieldForm(block) {
    if (!fieldForm || !block) return;
    var p = block.payload || {};
    fieldForm.field_key.value = p.field_key || '';
    fieldForm.label.value = p.label || '';
    fieldForm.field_type.value = p.field_type || (block.block_type === 'field' ? 'text' : block.block_type);
    fieldForm.assigned_role.value = p.assigned_role || 'instructor';
    fieldForm.variable_key.value = p.variable_key || '';
    fieldForm.required.checked = !!p.required;
  }

  function applyFieldSettings() {
    if (!selectedFieldBlockKey || !fieldForm) return;
    var payload = {
      field_key: fieldForm.field_key.value.trim(),
      label: fieldForm.label.value.trim(),
      field_type: fieldForm.field_type.value,
      assigned_role: fieldForm.assigned_role.value,
      variable_key: fieldForm.variable_key.value.trim(),
      required: fieldForm.required.checked,
    };
    editor.updateBlockPayload(selectedFieldBlockKey, payload);
    renderFields();
  }

  function apiPost(action, payload) {
    payload = payload || {};
    payload.action = action;
    return fetch('/admin/api/form_template_editor_api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).then(function (res) {
      return res.json().then(function (json) {
        if (!res.ok || !json.ok) throw new Error((json && json.error) || 'Request failed');
        return json;
      });
    });
  }

  function save() {
    if (!editable) {
      editor.setStatus('Not editable', 'error');
      return;
    }
    editor.setStatus('Saving...', 'saving');
    var doc = editor.serializeFromDom();
    apiPost('save_content', {
      template_id: parseInt(root.getAttribute('data-template-id') || '0', 10),
      template_version_id: parseInt(version.id || 0, 10),
      document: doc,
    }).then(function (json) {
      if (json.data && json.data.document) {
        editor.state.document = editor.normalizeDocument(json.data.document);
      }
      renderFields();
      editor.render();
      editor.setStatus('Saved', 'saved');
    }).catch(function (err) {
      editor.setStatus(err.message || 'Save failed', 'error');
    });
  }

  document.querySelectorAll('[data-ffed-add]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!editable) return;
      var block = editor.addBlock(btn.getAttribute('data-ffed-add') || 'paragraph', editor.state.selectedBlockKey);
      if (isFieldBlock(block)) {
        selectedFieldBlockKey = block.block_key;
        fillFieldForm(block);
      }
      renderFields();
    });
  });

  if (saveBtn) saveBtn.addEventListener('click', save);
  if (fieldForm) {
    fieldForm.addEventListener('submit', function (event) {
      event.preventDefault();
      applyFieldSettings();
    });
  }
  if (fieldList) {
    fieldList.addEventListener('click', function (event) {
      var item = event.target.closest('[data-block-key]');
      if (!item) return;
      selectedFieldBlockKey = item.getAttribute('data-block-key') || '';
      fillFieldForm(editor.getBlock(selectedFieldBlockKey));
      editor.state.selectedBlockKey = selectedFieldBlockKey;
      editor.render();
    });
  }
  if (variableList) {
    variableList.addEventListener('click', function (event) {
      var item = event.target.closest('[data-variable-key]');
      if (!item || !fieldForm) return;
      fieldForm.variable_key.value = item.getAttribute('data-variable-key') || '';
      applyFieldSettings();
    });
  }

  if (!editable) {
    document.querySelectorAll('[data-ffed-add], #ffedSave, #ffedFieldSettings input, #ffedFieldSettings select, #ffedFieldSettings button').forEach(function (el) {
      el.disabled = true;
    });
    editor.setStatus('Read only', 'error');
  } else {
    editor.setStatus('Ready', 'saved');
  }
  renderMeta();
  renderVariables();
  editor.render();
  renderFields();
})();
