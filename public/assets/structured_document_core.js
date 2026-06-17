(function (window) {
  'use strict';

  function uid(prefix) {
    return (prefix || 'block') + '_' + Math.random().toString(16).slice(2, 10);
  }

  function clone(value) {
    return JSON.parse(JSON.stringify(value || {}));
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function normalizeDocument(doc) {
    doc = doc && typeof doc === 'object' ? clone(doc) : {};
    if (!Array.isArray(doc.blocks)) doc.blocks = [];
    if (!doc.document_type) doc.document_type = 'flight_training_form';
    if (!doc.schema_version) doc.schema_version = 1;
    if (!doc.layout || typeof doc.layout !== 'object') doc.layout = { page: 'letter', orientation: 'portrait' };
    doc.blocks = doc.blocks.map(normalizeBlock);
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
    payload = payload || {};
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
        label: payload.label || 'Field',
        placeholder: payload.placeholder || '',
        required: !!payload.required,
        assigned_role: payload.assigned_role || 'instructor',
        variable_key: payload.variable_key || '',
        validation: payload.validation || {},
      };
    }
    return { html: payload.html || 'Text block' };
  }

  function isFieldType(type) {
    return ['checkbox', 'field', 'date', 'signature', 'initial'].indexOf(type) >= 0;
  }

  function renderDocument(doc, opts) {
    opts = opts || {};
    doc = normalizeDocument(doc);
    var title = doc.title ? '<h1 class="sdoc-document-title">' + escapeHtml(doc.title) + '</h1>' : '';
    var blocks = doc.blocks.map(function (block) { return renderBlock(block, opts); }).join('');
    if (!blocks) blocks = '<div class="sdoc-empty">Add a block to start building this template.</div>';
    return '<div class="sdoc-sheet"><div class="sdoc-sheet-body">' + title + blocks + '</div></div>';
  }

  function renderBlock(block, opts) {
    opts = opts || {};
    block = normalizeBlock(block);
    var editable = opts.editable !== false;
    var chrome = editable
      ? '<div class="sdoc-block-chrome" contenteditable="false">'
        + '<button type="button" class="sdoc-block-btn" data-sdoc-action="add-after">+</button>'
        + '<button type="button" class="sdoc-block-btn" data-sdoc-action="move-up">Up</button>'
        + '<button type="button" class="sdoc-block-btn" data-sdoc-action="move-down">Down</button>'
        + '<button type="button" class="sdoc-block-btn sdoc-block-btn--danger" data-sdoc-action="delete">Delete</button>'
        + '</div>'
      : '';
    return '<article class="sdoc-block sdoc-block--' + escapeHtml(block.block_type) + '" data-block-key="' + escapeHtml(block.block_key) + '" data-block-type="' + escapeHtml(block.block_type) + '">'
      + chrome + renderInner(block, editable) + '</article>';
  }

  function renderInner(block, editable) {
    var payload = block.payload || {};
    if (block.block_type === 'heading') return '<h' + (payload.level || 2) + ' class="sdoc-heading" data-sdoc-prop="text" contenteditable="' + editable + '">' + escapeHtml(payload.text || 'Heading') + '</h' + (payload.level || 2) + '>';
    if (block.block_type === 'table') return renderTable(payload, editable);
    if (isFieldType(block.block_type)) return renderField(block.block_type, payload, editable);
    return '<div class="sdoc-paragraph" data-sdoc-prop="html" contenteditable="' + editable + '">' + (payload.html || 'Text block') + '</div>';
  }

  function renderTable(payload, editable) {
    var headers = Array.isArray(payload.headers) ? payload.headers : ['Column 1', 'Column 2'];
    var rows = Array.isArray(payload.rows) ? payload.rows : [['', '']];
    var html = '<div class="sdoc-table-wrap" contenteditable="false">';
    if (payload.title) html += '<div class="sdoc-table-title" data-sdoc-prop="table_title" contenteditable="' + editable + '">' + escapeHtml(payload.title) + '</div>';
    html += '<table class="sdoc-table"><thead><tr>';
    headers.forEach(function (h, idx) { html += '<th data-sdoc-table-header="' + idx + '" contenteditable="' + editable + '">' + escapeHtml(h) + '</th>'; });
    html += '</tr></thead><tbody>';
    rows.forEach(function (row, rIdx) {
      html += '<tr>';
      headers.forEach(function (_h, cIdx) {
        html += '<td data-sdoc-table-row="' + rIdx + '" data-sdoc-table-col="' + cIdx + '" contenteditable="' + editable + '">' + escapeHtml((row && row[cIdx]) || '') + '</td>';
      });
      html += '</tr>';
    });
    return html + '</tbody></table></div>';
  }

  function renderField(blockType, payload, editable) {
    var fieldType = payload.field_type || (blockType === 'field' ? 'text' : blockType);
    var control = '<span class="sdoc-input-line">' + escapeHtml(payload.placeholder || 'Text') + '</span>';
    if (fieldType === 'checkbox') control = '<span class="sdoc-checkbox-box" aria-hidden="true"></span>';
    if (fieldType === 'date') control = '<span class="sdoc-input-line">' + escapeHtml(payload.placeholder || 'Date') + '</span>';
    if (fieldType === 'signature') control = '<span class="sdoc-signature-box">Signature</span>';
    if (fieldType === 'initial') control = '<span class="sdoc-initial-box">Initial</span>';
    return '<div class="sdoc-field" contenteditable="false" data-field-key="' + escapeHtml(payload.field_key || '') + '" data-field-type="' + escapeHtml(fieldType) + '" data-assigned-role="' + escapeHtml(payload.assigned_role || 'instructor') + '" data-variable-key="' + escapeHtml(payload.variable_key || '') + '" data-required="' + (payload.required ? '1' : '0') + '">'
      + '<label class="sdoc-field-label">' + escapeHtml(payload.label || 'Field') + (payload.required ? ' <span class="sdoc-required">*</span>' : '') + '</label>'
      + control
      + (editable ? '<button type="button" class="sdoc-field-edit" data-sdoc-action="select-field">Field settings</button>' : '')
      + '</div>';
  }

  function createEditor(options) {
    options = options || {};
    var state = {
      document: normalizeDocument(options.document || {}),
      selectedBlockKey: '',
      selectedFieldKey: '',
      editable: options.editable !== false,
    };
    var canvas = options.canvas;
    var status = options.status;

    function setStatus(text, tone) {
      if (!status) return;
      status.textContent = text;
      status.className = 'sdoc-save-status' + (tone ? ' is-' + tone : '');
    }

    function render() {
      if (!canvas) return;
      canvas.innerHTML = renderDocument(state.document, { editable: state.editable });
      if (state.selectedBlockKey) {
        var selected = canvas.querySelector('[data-block-key="' + cssEscape(state.selectedBlockKey) + '"]');
        if (selected) selected.classList.add('is-selected');
      }
    }

    function addBlock(type, afterKey) {
      var block = normalizeBlock({ block_type: type, payload: defaultPayload(type) });
      var idx = afterKey ? findBlockIndex(afterKey) : -1;
      if (idx >= 0) state.document.blocks.splice(idx + 1, 0, block);
      else state.document.blocks.push(block);
      state.selectedBlockKey = block.block_key;
      render();
      return block;
    }

    function deleteBlock(key) {
      var idx = findBlockIndex(key);
      if (idx < 0) return;
      state.document.blocks.splice(idx, 1);
      state.selectedBlockKey = '';
      state.selectedFieldKey = '';
      render();
    }

    function moveBlock(key, delta) {
      var idx = findBlockIndex(key);
      var next = idx + delta;
      if (idx < 0 || next < 0 || next >= state.document.blocks.length) return;
      var tmp = state.document.blocks[idx];
      state.document.blocks[idx] = state.document.blocks[next];
      state.document.blocks[next] = tmp;
      state.selectedBlockKey = key;
      render();
    }

    function updateBlockPayload(key, patch) {
      var idx = findBlockIndex(key);
      if (idx < 0) return;
      state.document.blocks[idx].payload = Object.assign({}, state.document.blocks[idx].payload || {}, patch || {});
      state.document.blocks[idx] = normalizeBlock(state.document.blocks[idx]);
      render();
    }

    function serializeFromDom() {
      if (!canvas) return state.document;
      state.document.blocks.forEach(function (block) {
        var el = canvas.querySelector('[data-block-key="' + cssEscape(block.block_key) + '"]');
        if (!el) return;
        if (block.block_type === 'heading') {
          var heading = el.querySelector('[data-sdoc-prop="text"]');
          if (heading) block.payload.text = heading.textContent.trim();
        } else if (block.block_type === 'paragraph') {
          var para = el.querySelector('[data-sdoc-prop="html"]');
          if (para) block.payload.html = para.innerHTML.trim();
        } else if (block.block_type === 'table') {
          block.payload.headers = Array.prototype.map.call(el.querySelectorAll('[data-sdoc-table-header]'), function (cell) { return cell.textContent.trim(); });
          var rows = [];
          el.querySelectorAll('tbody tr').forEach(function (tr, rIdx) {
            rows[rIdx] = Array.prototype.map.call(tr.querySelectorAll('td'), function (cell) { return cell.textContent.trim(); });
          });
          block.payload.rows = rows;
        }
      });
      state.document = normalizeDocument(state.document);
      return state.document;
    }

    function findBlockIndex(key) {
      return state.document.blocks.findIndex(function (block) { return block.block_key === key; });
    }

    function getBlock(key) {
      var idx = findBlockIndex(key);
      return idx >= 0 ? state.document.blocks[idx] : null;
    }

    function defaultPayload(type) {
      if (type === 'heading') return { text: 'Heading', level: 2 };
      if (type === 'table') return { title: '', headers: ['Column 1', 'Column 2'], rows: [['', '']] };
      if (isFieldType(type)) return { field_key: uid('field'), field_type: type === 'field' ? 'text' : type, label: 'Field', required: false, assigned_role: 'instructor', variable_key: '', placeholder: '' };
      return { html: 'Text block' };
    }

    function cssEscape(value) {
      if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(value);
      return String(value).replace(/"/g, '\\"');
    }

    if (canvas) {
      canvas.addEventListener('click', function (event) {
        var block = event.target.closest('.sdoc-block');
        if (block) state.selectedBlockKey = block.getAttribute('data-block-key') || '';
        var action = event.target.closest('[data-sdoc-action]');
        if (action) {
          var act = action.getAttribute('data-sdoc-action');
          var key = block ? block.getAttribute('data-block-key') : state.selectedBlockKey;
          if (act === 'add-after') addBlock('paragraph', key);
          if (act === 'delete') deleteBlock(key);
          if (act === 'move-up') moveBlock(key, -1);
          if (act === 'move-down') moveBlock(key, 1);
          if (act === 'select-field' && options.onSelectField) options.onSelectField(getBlock(key));
          event.preventDefault();
          event.stopPropagation();
        }
        render();
        if (options.onSelectBlock) options.onSelectBlock(getBlock(state.selectedBlockKey));
      });
    }

    return {
      state: state,
      render: render,
      setStatus: setStatus,
      addBlock: addBlock,
      deleteBlock: deleteBlock,
      moveBlock: moveBlock,
      updateBlockPayload: updateBlockPayload,
      serializeFromDom: serializeFromDom,
      getBlock: getBlock,
      normalizeDocument: normalizeDocument,
    };
  }

  window.StructuredDocumentCore = {
    createEditor: createEditor,
    normalizeDocument: normalizeDocument,
    renderDocument: renderDocument,
    escapeHtml: escapeHtml,
    uid: uid,
  };
})(window);
