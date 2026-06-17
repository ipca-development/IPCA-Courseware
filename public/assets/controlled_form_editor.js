(function () {
  'use strict';

  var root = document.getElementById('flightFormEditorRoot');
  if (!root) return;

  var initial = window.flightFormInitialData || {};
  var template = initial.template || {};
  var version = initial.version || {};
  var editable = !!initial.editable;
  var documentState = normalizeDocument(initial.document || {});
  var selectedBlockKey = '';
  var canvasZoom = 100;

  var canvasEl = document.getElementById('cffCanvas');
  var treeEl = document.getElementById('cffBlockTree');
  var saveStatusEl = document.getElementById('cffSaveStatus');
  var fieldSettingsBtn = document.getElementById('cffFieldSettingsBtn');
  var fieldModal = document.getElementById('cffFieldModal');
  var fieldForm = document.getElementById('cffFieldForm');
  var variableModal = document.getElementById('cffVariableModal');
  var variableList = document.getElementById('cffVariableList');
  var zoomLabel = document.getElementById('cffZoomLabel');

  function h(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function uid(prefix) {
    return (prefix || 'block') + '_' + Math.random().toString(16).slice(2, 10);
  }

  function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(value);
    return String(value).replace(/"/g, '\\"');
  }

  function isFieldType(type) {
    return ['field', 'checkbox', 'date', 'signature', 'initial'].indexOf(type) >= 0;
  }

  function normalizeDocument(doc) {
    doc = doc && typeof doc === 'object' ? JSON.parse(JSON.stringify(doc)) : {};
    doc.document_type = doc.document_type || 'flight_training_form';
    doc.schema_version = doc.schema_version || 1;
    doc.title = doc.title || template.title || 'Form Template';
    doc.layout = doc.layout || { page: 'letter', orientation: 'portrait' };
    doc.blocks = Array.isArray(doc.blocks) ? doc.blocks.map(normalizeBlock) : [];
    return doc;
  }

  function normalizeBlock(block) {
    block = block && typeof block === 'object' ? block : {};
    var type = String(block.block_type || block.type || 'paragraph');
    var key = String(block.block_key || uid(type));
    var payload = block.payload && typeof block.payload === 'object' ? block.payload : {};
    return { block_key: key, block_type: type, payload: normalizePayload(type, payload) };
  }

  function normalizePayload(type, payload) {
    if (type === 'heading') return { text: payload.text || 'Heading', level: parseInt(payload.level || 2, 10) || 2 };
    if (type === 'table') {
      return {
        title: payload.title || '',
        headers: Array.isArray(payload.headers) && payload.headers.length ? payload.headers : ['Column 1', 'Column 2'],
        rows: Array.isArray(payload.rows) && payload.rows.length ? payload.rows : [['', '']],
      };
    }
    if (isFieldType(type)) {
      return {
        field_key: payload.field_key || uid('field'),
        field_type: payload.field_type || (type === 'field' ? 'text' : type),
        label: payload.label || fieldLabel(type),
        placeholder: payload.placeholder || '',
        required: !!payload.required,
        assigned_role: payload.assigned_role || 'instructor',
        variable_key: payload.variable_key || '',
        validation: payload.validation || {},
      };
    }
    return { html: payload.html || 'Text block' };
  }

  function fieldLabel(type) {
    if (type === 'checkbox') return 'Checkbox';
    if (type === 'date') return 'Date';
    if (type === 'signature') return 'Signature';
    if (type === 'initial') return 'Initial';
    return 'Field';
  }

  function setStatus(text, tone) {
    if (!saveStatusEl) return;
    saveStatusEl.textContent = text;
    saveStatusEl.className = 'cpb-save-status' + (tone ? ' is-' + tone : '');
  }

  function render() {
    renderCanvas();
    renderTree();
    updateToolbarState();
  }

  function renderCanvas() {
    var blocks = documentState.blocks.map(renderBlock).join('');
    if (!blocks) {
      blocks = '<div class="cpb-form-empty">Add a block from the toolbar to start building this form template.</div>';
    }
    canvasEl.innerHTML = '<section class="cpb-sheet" style="--cpb-sheet-zoom:' + (canvasZoom / 100) + '">'
      + '<header class="cpb-sheet-header"><div><div class="cpb-sheet-org">IPCA Flight Training Forms</div><div class="cpb-sheet-title">' + h(documentState.title || template.title || 'Form Template') + '</div><div class="cpb-sheet-meta">' + h(template.template_key || '') + ' · v' + h(version.version_label || '') + '</div></div></header>'
      + '<div class="cpb-sheet-body">' + blocks + '</div>'
      + '</section>';
    var selected = selectedBlockKey ? canvasEl.querySelector('[data-block-key="' + cssEscape(selectedBlockKey) + '"]') : null;
    if (selected) selected.classList.add('is-selected');
  }

  function renderBlock(block) {
    var type = block.block_type;
    var p = block.payload || {};
    var chrome = editable
      ? '<div class="cpb-block-chrome" contenteditable="false">'
        + '<button type="button" class="cpb-block-btn" data-cff-action="insert-paragraph" title="Insert paragraph below">¶+</button>'
        + '<button type="button" class="cpb-block-btn" data-cff-action="move-up" title="Move up">↑</button>'
        + '<button type="button" class="cpb-block-btn" data-cff-action="move-down" title="Move down">↓</button>'
        + '<button type="button" class="cpb-block-btn cpb-block-btn--danger" data-cff-action="delete" title="Delete">×</button>'
        + '</div>'
      : '';
    var inner = type === 'heading' ? renderHeading(p)
      : type === 'table' ? renderTable(p)
      : isFieldType(type) ? renderField(type, p)
      : renderParagraph(p);
    return '<article class="cpb-block cpb-block--' + h(type) + '" data-block-key="' + h(block.block_key) + '" data-block-type="' + h(type) + '">' + chrome + inner + '</article>';
  }

  function renderHeading(payload) {
    var level = Math.max(1, Math.min(6, parseInt(payload.level || 2, 10) || 2));
    return '<h' + level + ' class="cpb-heading cpb-ps-subtitle_2" data-cff-prop="text" contenteditable="' + editable + '">' + h(payload.text || 'Heading') + '</h' + level + '>';
  }

  function renderParagraph(payload) {
    return '<div class="cpb-paragraph cpb-ps-body" data-cff-prop="html" contenteditable="' + editable + '">' + (payload.html || 'Text block') + '</div>';
  }

  function renderTable(payload) {
    var headers = Array.isArray(payload.headers) ? payload.headers : ['Column 1', 'Column 2'];
    var rows = Array.isArray(payload.rows) ? payload.rows : [['', '']];
    var html = '<div class="cpb-table-wrap cpb-table-border-thin" contenteditable="false">';
    if (payload.title) html += '<div class="cpb-table-title" data-cff-prop="table_title" contenteditable="' + editable + '">' + h(payload.title) + '</div>';
    html += '<table class="cpb-table"><thead><tr class="cpb-table-header-row">';
    headers.forEach(function (header, idx) {
      html += '<th data-cff-table-header="' + idx + '" contenteditable="' + editable + '">' + h(header) + '</th>';
    });
    html += '</tr></thead><tbody>';
    rows.forEach(function (row, rIdx) {
      html += '<tr>';
      headers.forEach(function (_header, cIdx) {
        html += '<td data-cff-table-row="' + rIdx + '" data-cff-table-col="' + cIdx + '" contenteditable="' + editable + '">' + h((row && row[cIdx]) || '') + '</td>';
      });
      html += '</tr>';
    });
    return html + '</tbody></table></div>';
  }

  function renderField(type, payload) {
    var fieldType = payload.field_type || (type === 'field' ? 'text' : type);
    var control = '<span class="cpb-form-input-line">' + h(payload.placeholder || 'Text') + '</span>';
    if (fieldType === 'checkbox') control = '<span class="cpb-form-checkbox-box" aria-hidden="true"></span>';
    if (fieldType === 'date') control = '<span class="cpb-form-input-line">' + h(payload.placeholder || 'Date') + '</span>';
    if (fieldType === 'signature') control = '<span class="cpb-form-signature-box">Signature</span>';
    if (fieldType === 'initial') control = '<span class="cpb-form-initial-box">Initial</span>';
    return '<div class="cpb-form-field" contenteditable="false" data-field-key="' + h(payload.field_key || '') + '" data-field-type="' + h(fieldType) + '" data-assigned-role="' + h(payload.assigned_role || 'instructor') + '" data-variable-key="' + h(payload.variable_key || '') + '" data-required="' + (payload.required ? '1' : '0') + '">'
      + '<span class="cpb-form-field-label">' + h(payload.label || 'Field') + (payload.required ? ' <span class="cpb-form-required">*</span>' : '') + '</span>'
      + control
      + '<span class="cpb-form-role">' + h(payload.assigned_role || 'instructor') + '</span>'
      + '</div>';
  }

  function renderTree() {
    if (!treeEl) return;
    if (!documentState.blocks.length) {
      treeEl.innerHTML = '<p style="padding:12px 16px;margin:0;font-size:12px;color:#94a3b8;">No blocks yet.</p>';
      return;
    }
    var html = '<ul class="cpb-tree">';
    documentState.blocks.forEach(function (block, idx) {
      var label = blockLabel(block, idx);
      html += '<li class="cpb-tree-node"><div class="cpb-tree-row"><span class="cpb-tree-toggle is-leaf"></span><button type="button" class="cpb-tree-link' + (block.block_key === selectedBlockKey ? ' is-active' : '') + '" data-tree-block-key="' + h(block.block_key) + '">' + h(label) + '</button></div></li>';
    });
    treeEl.innerHTML = html + '</ul>';
  }

  function blockLabel(block, idx) {
    var p = block.payload || {};
    if (block.block_type === 'heading') return p.text || ('Heading ' + (idx + 1));
    if (isFieldType(block.block_type)) return (p.label || p.field_key || 'Field') + ' [' + (p.assigned_role || 'instructor') + ']';
    if (block.block_type === 'table') return p.title || 'Table';
    return 'Text block ' + (idx + 1);
  }

  function updateToolbarState() {
    var block = selectedBlock();
    var canEditField = editable && block && isFieldType(block.block_type);
    if (fieldSettingsBtn) fieldSettingsBtn.disabled = !canEditField;
    if (zoomLabel) zoomLabel.textContent = canvasZoom + '%';
    if (!editable) {
      document.querySelectorAll('#cffToolbar button').forEach(function (btn) {
        if (btn.id !== 'cffZoomIn' && btn.id !== 'cffZoomOut') btn.disabled = true;
      });
    }
  }

  function selectedBlock() {
    return documentState.blocks.find(function (block) { return block.block_key === selectedBlockKey; }) || null;
  }

  function selectedIndex() {
    return documentState.blocks.findIndex(function (block) { return block.block_key === selectedBlockKey; });
  }

  function addBlock(type, afterKey) {
    if (!editable) return;
    var block = normalizeBlock({ block_type: type, payload: defaultPayload(type) });
    var idx = afterKey ? documentState.blocks.findIndex(function (item) { return item.block_key === afterKey; }) : -1;
    if (idx >= 0) documentState.blocks.splice(idx + 1, 0, block);
    else documentState.blocks.push(block);
    selectedBlockKey = block.block_key;
    if (isFieldType(type)) openFieldModal();
    render();
  }

  function defaultPayload(type) {
    if (type === 'heading') return { text: 'Heading', level: 2 };
    if (type === 'table') return { title: '', headers: ['Column 1', 'Column 2'], rows: [['', '']] };
    if (isFieldType(type)) return { field_key: uid('field'), field_type: type === 'field' ? 'text' : type, label: fieldLabel(type), required: false, assigned_role: 'instructor', variable_key: '', placeholder: '' };
    return { html: 'Text block' };
  }

  function serializeFromDom() {
    documentState.blocks.forEach(function (block) {
      var el = canvasEl.querySelector('[data-block-key="' + cssEscape(block.block_key) + '"]');
      if (!el) return;
      if (block.block_type === 'heading') {
        var heading = el.querySelector('[data-cff-prop="text"]');
        if (heading) block.payload.text = heading.textContent.trim();
      } else if (block.block_type === 'paragraph') {
        var para = el.querySelector('[data-cff-prop="html"]');
        if (para) block.payload.html = para.innerHTML.trim();
      } else if (block.block_type === 'table') {
        block.payload.title = (el.querySelector('[data-cff-prop="table_title"]') || {}).textContent || block.payload.title || '';
        block.payload.headers = Array.prototype.map.call(el.querySelectorAll('[data-cff-table-header]'), function (cell) { return cell.textContent.trim(); });
        var rows = [];
        el.querySelectorAll('tbody tr').forEach(function (tr, rIdx) {
          rows[rIdx] = Array.prototype.map.call(tr.querySelectorAll('td'), function (cell) { return cell.textContent.trim(); });
        });
        block.payload.rows = rows;
      }
    });
    documentState = normalizeDocument(documentState);
  }

  function save() {
    if (!editable) {
      setStatus('Read only', 'error');
      return;
    }
    serializeFromDom();
    setStatus('Saving...', 'saving');
    apiPost('save_content', {
      template_id: parseInt(root.getAttribute('data-template-id') || '0', 10),
      template_version_id: parseInt(version.id || 0, 10),
      document: documentState,
    }).then(function (json) {
      if (json.data && json.data.document) documentState = normalizeDocument(json.data.document);
      setStatus('Saved', 'saved');
      render();
    }).catch(function (err) {
      setStatus(err.message || 'Save failed', 'error');
    });
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

  function openFieldModal() {
    var block = selectedBlock();
    if (!block || !isFieldType(block.block_type) || !fieldForm) return;
    var p = block.payload || {};
    fieldForm.field_key.value = p.field_key || '';
    fieldForm.label.value = p.label || '';
    fieldForm.field_type.value = p.field_type || (block.block_type === 'field' ? 'text' : block.block_type);
    fieldForm.assigned_role.value = p.assigned_role || 'instructor';
    fieldForm.variable_key.value = p.variable_key || '';
    fieldForm.required.checked = !!p.required;
    showModal(fieldModal);
  }

  function showModal(modal) {
    if (!modal) return;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
  }

  function hideModal(modal) {
    if (!modal) return;
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
  }

  function renderVariables() {
    if (!variableList) return;
    var groups = initial.variables || [];
    variableList.innerHTML = groups.map(function (group) {
      var vars = Array.isArray(group.variables) ? group.variables : [];
      return '<section class="cpb-form-variable-group"><h4>' + h(group.group || '') + '</h4>'
        + vars.map(function (item) {
          return '<button type="button" class="cpb-form-variable-item" data-variable-key="' + h(item.key || '') + '"><strong>' + h(item.label || item.key || '') + '</strong><code>' + h(item.token || '') + '</code></button>';
        }).join('')
        + '</section>';
    }).join('');
  }

  document.querySelectorAll('[data-add-block]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      addBlock(btn.getAttribute('data-add-block') || 'paragraph', selectedBlockKey);
    });
  });
  document.querySelectorAll('[data-form-tool]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      addBlock(btn.getAttribute('data-form-tool') || 'field', selectedBlockKey);
    });
  });
  document.getElementById('cffSaveBtn').addEventListener('click', save);
  document.getElementById('cffVariablePickerBtn').addEventListener('click', function () { renderVariables(); showModal(variableModal); });
  document.getElementById('cffFieldSettingsBtn').addEventListener('click', openFieldModal);
  document.getElementById('cffAddParagraph').addEventListener('click', function () { addBlock('paragraph', selectedBlockKey); });
  document.getElementById('cffZoomIn').addEventListener('click', function () { canvasZoom = Math.min(200, canvasZoom + 10); render(); });
  document.getElementById('cffZoomOut').addEventListener('click', function () { canvasZoom = Math.max(50, canvasZoom - 10); render(); });

  canvasEl.addEventListener('click', function (event) {
    var blockEl = event.target.closest('.cpb-block');
    if (blockEl) selectedBlockKey = blockEl.getAttribute('data-block-key') || '';
    var action = event.target.closest('[data-cff-action]');
    if (action && editable) {
      var act = action.getAttribute('data-cff-action');
      var idx = selectedIndex();
      if (act === 'insert-paragraph') addBlock('paragraph', selectedBlockKey);
      if (act === 'delete' && idx >= 0) {
        documentState.blocks.splice(idx, 1);
        selectedBlockKey = '';
      }
      if (act === 'move-up' && idx > 0) {
        var prev = documentState.blocks[idx - 1];
        documentState.blocks[idx - 1] = documentState.blocks[idx];
        documentState.blocks[idx] = prev;
      }
      if (act === 'move-down' && idx >= 0 && idx < documentState.blocks.length - 1) {
        var next = documentState.blocks[idx + 1];
        documentState.blocks[idx + 1] = documentState.blocks[idx];
        documentState.blocks[idx] = next;
      }
      event.preventDefault();
      event.stopPropagation();
    }
    render();
  });

  treeEl.addEventListener('click', function (event) {
    var link = event.target.closest('[data-tree-block-key]');
    if (!link) return;
    selectedBlockKey = link.getAttribute('data-tree-block-key') || '';
    render();
    var blockEl = canvasEl.querySelector('[data-block-key="' + cssEscape(selectedBlockKey) + '"]');
    if (blockEl) blockEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });

  fieldForm.addEventListener('submit', function (event) {
    event.preventDefault();
    var block = selectedBlock();
    if (!block || !isFieldType(block.block_type)) return;
    block.payload.field_key = fieldForm.field_key.value.trim();
    block.payload.label = fieldForm.label.value.trim();
    block.payload.field_type = fieldForm.field_type.value;
    block.payload.assigned_role = fieldForm.assigned_role.value;
    block.payload.variable_key = fieldForm.variable_key.value.trim();
    block.payload.required = fieldForm.required.checked;
    hideModal(fieldModal);
    render();
  });

  variableList.addEventListener('click', function (event) {
    var item = event.target.closest('[data-variable-key]');
    if (!item) return;
    var variableKey = item.getAttribute('data-variable-key') || '';
    var block = selectedBlock();
    if (block && isFieldType(block.block_type)) {
      block.payload.variable_key = variableKey;
      hideModal(variableModal);
      render();
      openFieldModal();
    } else {
      addBlock('field', selectedBlockKey);
      selectedBlock().payload.variable_key = variableKey;
      hideModal(variableModal);
      render();
      openFieldModal();
    }
  });

  document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      hideModal(fieldModal);
      hideModal(variableModal);
    });
  });

  renderVariables();
  render();
  setStatus(editable ? 'Ready' : 'Read only', editable ? 'saved' : 'error');
})();
