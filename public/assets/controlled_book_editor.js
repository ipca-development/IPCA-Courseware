(function () {
  'use strict';

  var root = document.getElementById('cpbEditorRoot');
  if (!root) return;

  var versionId = parseInt(root.getAttribute('data-version-id') || '0', 10);
  var initialSectionId = parseInt(root.getAttribute('data-section-id') || '0', 10);
  var apiBase = '/admin/api/controlled_book_editor_api.php';

  var treeEl = document.getElementById('cpbSectionTree');
  var canvasEl = document.getElementById('cpbCanvas');
  var toolbarEl = document.getElementById('cpbToolbar');
  var saveStatusEl = document.getElementById('cpbSaveStatus');
  var addSubBtn = document.getElementById('cpbAddSubsection');
  var imageInput = document.getElementById('cpbImageInput');
  var undoBtn = document.getElementById('cpbUndo');
  var fontSelect = document.getElementById('cpbFontSelect');
  var textColorInput = document.getElementById('cpbTextColor');
  var manageCalloutsBtn = document.getElementById('cpbManageCallouts');
  var syncHighlightsBtn = document.getElementById('cpbSyncHighlights');

  var FONT_CLASSES = ['cpb-font-serif', 'cpb-font-sans', 'cpb-font-mono', 'cpb-font-arial'];
  var ALIGN_CLASSES = ['cpb-align-left', 'cpb-align-center', 'cpb-align-right'];

  var state = {
    versionId: versionId,
    sectionId: initialSectionId,
    editable: true,
    sectionsTree: [],
    saveTimer: null,
    saving: false,
    pending: {},
    expanded: {},
    pageLayout: {},
    calloutPresets: [],
    undoStack: [],
    redoStack: [],
    layoutTimer: null,
    focusedTableCell: null,
    canvasEventsWired: false,
    resizeHintEl: null,
    tableClipboard: '',
  };

  function colLetter(index) {
    var n = index + 1;
    var s = '';
    while (n > 0) {
      var rem = (n - 1) % 26;
      s = String.fromCharCode(65 + rem) + s;
      n = Math.floor((n - 1) / 26);
    }
    return s;
  }

  function tableFormulaDisplay(raw, bodyRows) {
    raw = (raw || '').trim();
    if (!raw || raw.charAt(0) !== '=') return raw;
    try {
      return String(evaluateTableFormula(raw, bodyRows));
    } catch (e) {
      return '#ERR';
    }
  }

  function parseCellRef(ref) {
    var m = String(ref).toUpperCase().match(/^([A-Z]+)([0-9]+)$/);
    if (!m) throw new Error('bad ref');
    var col = 0;
    for (var i = 0; i < m[1].length; i++) {
      col = col * 26 + (m[1].charCodeAt(i) - 64);
    }
    return { col: col - 1, row: parseInt(m[2], 10) - 1 };
  }

  function cellNumber(bodyRows, row, col) {
    var raw = String((bodyRows[row] && bodyRows[row][col]) || '').trim();
    if (!raw) return 0;
    if (raw.charAt(0) === '=') {
      var v = evaluateTableFormula(raw, bodyRows);
      return isNaN(parseFloat(v)) ? 0 : parseFloat(v);
    }
    return isNaN(parseFloat(raw)) ? 0 : parseFloat(raw);
  }

  function evaluateTableFormula(formula, bodyRows) {
    var expr = formula.trim().slice(1).trim();
    var fnMatch = expr.match(/^(SUM|AVG|AVERAGE|MIN|MAX|COUNT)\((.+)\)$/i);
    if (fnMatch) {
      var args = parseFormulaArgs(fnMatch[2], bodyRows);
      var fn = fnMatch[1].toUpperCase();
      if (fn === 'SUM') return args.reduce(function (a, b) { return a + b; }, 0);
      if (fn === 'AVG' || fn === 'AVERAGE') return args.length ? args.reduce(function (a, b) { return a + b; }, 0) / args.length : 0;
      if (fn === 'MIN') return args.length ? Math.min.apply(null, args) : 0;
      if (fn === 'MAX') return args.length ? Math.max.apply(null, args) : 0;
      if (fn === 'COUNT') return args.length;
    }
    var replaced = expr.replace(/([A-Z]+[0-9]+)/gi, function (token) {
      var ref = parseCellRef(token);
      return String(cellNumber(bodyRows, ref.row, ref.col));
    });
    if (!/^[0-9+\-*/().\s]+$/.test(replaced)) throw new Error('bad expr');
    return Function('"use strict";return (' + replaced + ')')();
  }

  function parseFormulaArgs(raw, bodyRows) {
    return raw.split(',').map(function (part) {
      part = part.trim();
      if (!part) return 0;
      if (part.indexOf(':') >= 0) {
        var bits = part.split(':');
        var a = parseCellRef(bits[0]);
        var b = parseCellRef(bits[1]);
        var vals = [];
        for (var r = Math.min(a.row, b.row); r <= Math.max(a.row, b.row); r++) {
          for (var c = Math.min(a.col, b.col); c <= Math.max(a.col, b.col); c++) {
            vals.push(cellNumber(bodyRows, r, c));
          }
        }
        return vals;
      }
      if (/^[A-Z]+[0-9]+$/i.test(part)) {
        var ref = parseCellRef(part);
        return [cellNumber(bodyRows, ref.row, ref.col)];
      }
      return [parseFloat(part) || 0];
    }).reduce(function (acc, item) {
      return acc.concat(item);
    }, []);
  }

  function setStatus(text, tone) {
    if (!saveStatusEl) return;
    saveStatusEl.textContent = text;
    saveStatusEl.className = 'cpb-save-status' + (tone ? ' is-' + tone : '');
  }

  function apiGet(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      return r.json();
    });
  }

  function apiPost(action, payload) {
    return fetch(apiBase, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ action: action }, payload || {})),
    }).then(function (r) {
      return r.json();
    });
  }

  function loadCalloutPresets() {
    return apiGet(apiBase + '?action=get_callout_presets&version_id=' + state.versionId)
      .then(function (res) {
        if (res.ok && res.presets) {
          state.calloutPresets = res.presets;
        }
      })
      .catch(function () {
        state.calloutPresets = [
          { callout_type: 'warning', title: 'WARNING', text: '' },
          { callout_type: 'caution', title: 'CAUTION', text: '' },
        ];
      });
  }

  function loadSection(sectionId) {
    setStatus('Loading…', 'saving');
    var url = apiBase + '?action=load&version_id=' + state.versionId + '&section_id=' + sectionId;
    return apiGet(url).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Load failed');
      state.sectionId = res.section_id;
      state.editable = !!res.editable;
      state.sectionsTree = res.sections_tree || [];
      state.pageLayout = res.page_layout || {};
      state.undoStack = [];
      state.redoStack = [];
      root.classList.toggle('cpb-editor-readonly', !state.editable);
      if (toolbarEl) toolbarEl.style.display = state.editable ? 'flex' : 'none';
      renderTree(state.sectionsTree, state.sectionId);
      canvasEl.innerHTML = res.page_html || '';
      wireCanvas();
      setStatus(state.editable ? 'Ready' : 'Read-only (released)', state.editable ? 'saved' : '');
      updateAddSubsection(res.section);
      if (res.prior_version_label) {
        setStatus('Ready · changes vs ' + res.prior_version_label, 'saved');
      }
    });
  }

  function updateAddSubsection(section) {
    if (!addSubBtn || !section) return;
    var key = section.section_key || '';
    var isNestable = key === 'main_content' || key === 'annexes' || !!section.parent_section_id;
    addSubBtn.style.display = state.editable && isNestable ? 'block' : 'none';
    addSubBtn.setAttribute('data-parent-id', String(section.id || state.sectionId));
  }

  function renderTree(nodes, activeId) {
    if (!treeEl) return;
    treeEl.innerHTML = '';
    var ul = document.createElement('ul');
    ul.className = 'cpb-tree';
    nodes.forEach(function (node) {
      ul.appendChild(renderTreeNode(node, activeId, 0));
    });
    treeEl.appendChild(ul);
  }

  function renderTreeNode(node, activeId, depth) {
    var li = document.createElement('li');
    li.className = 'cpb-tree-node';

    var hasChildren = node.children && node.children.length > 0;
    var nodeId = String(node.id);
    if (state.expanded[nodeId] === undefined) {
      state.expanded[nodeId] = depth < 1 || hasChildren;
    }

    var row = document.createElement('div');
    row.className = 'cpb-tree-row';

    var toggle = document.createElement('span');
    toggle.className = 'cpb-tree-toggle' + (hasChildren ? '' : ' is-leaf');
    toggle.textContent = state.expanded[nodeId] ? '▾' : '▸';
    toggle.setAttribute('role', 'button');
    toggle.setAttribute('tabindex', hasChildren ? '0' : '-1');
    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      state.expanded[nodeId] = !state.expanded[nodeId];
      renderTree(state.sectionsTree, state.sectionId);
    });
    toggle.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        toggle.click();
      }
    });

    var link = document.createElement('span');
    link.className = 'cpb-tree-link'
      + (node.id === activeId ? ' is-active' : '')
      + (node.is_generated ? ' is-generated' : '');
    link.textContent = node.title;
    link.setAttribute('role', 'button');
    link.setAttribute('tabindex', '0');
    link.addEventListener('click', function () {
      loadSection(node.id);
    });
    link.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        link.click();
      }
    });

    row.appendChild(toggle);
    row.appendChild(link);
    li.appendChild(row);

    if (hasChildren) {
      var childUl = document.createElement('ul');
      childUl.style.display = state.expanded[nodeId] ? 'block' : 'none';
      node.children.forEach(function (child) {
        childUl.appendChild(renderTreeNode(child, activeId, depth + 1));
      });
      li.appendChild(childUl);
    }

    return li;
  }

  function captureUndoSnapshot() {
    var blocksRoot = canvasEl.querySelector('[data-blocks-root]');
    return {
      blocksHtml: blocksRoot ? blocksRoot.innerHTML : '',
      layout: extractLayout(),
    };
  }

  function pushUndo() {
    if (!state.editable) return;
    state.undoStack.push(captureUndoSnapshot());
    if (state.undoStack.length > 40) state.undoStack.shift();
    state.redoStack = [];
  }

  function restoreUndoSnapshot(snap) {
    var blocksRoot = canvasEl.querySelector('[data-blocks-root]');
    if (blocksRoot && snap.blocksHtml !== undefined) {
      blocksRoot.innerHTML = snap.blocksHtml;
      wireCanvas();
      saveAllBlocks();
    }
    applyLayoutToDom(snap.layout || {});
    saveLayout();
  }

  function doUndo() {
    var active = document.activeElement;
    if (active && active.isContentEditable && document.queryCommandSupported('undo')) {
      document.execCommand('undo');
      var blockEl = active.closest('.cpb-block');
      if (blockEl) scheduleSave(blockEl);
      return;
    }
    if (state.undoStack.length === 0) return;
    state.redoStack.push(captureUndoSnapshot());
    restoreUndoSnapshot(state.undoStack.pop());
    setStatus('Undone', 'saved');
  }

  function extractLayout() {
    var sheet = canvasEl.querySelector('.cpb-sheet');
    if (!sheet) return {};
    var cb = sheet.querySelector('[data-layout-toggle="show_running_header_footer"]');
    var layout = {
      show_running_header_footer: cb ? !!cb.checked : false,
      header_left: '',
      header_center: '',
      header_right: '',
      footer_left: '',
      footer_center: '',
      footer_right: '',
    };
    sheet.querySelectorAll('[data-layout-field]').forEach(function (el) {
      var key = el.getAttribute('data-layout-field');
      if (key) layout[key] = el.textContent.trim();
    });
    return layout;
  }

  function applyLayoutToDom(layout) {
    var sheet = canvasEl.querySelector('.cpb-sheet');
    if (!sheet || !layout) return;
    var cb = sheet.querySelector('[data-layout-toggle="show_running_header_footer"]');
    if (cb && layout.show_running_header_footer !== undefined) {
      cb.checked = !!layout.show_running_header_footer;
    }
    sheet.querySelectorAll('[data-layout-field]').forEach(function (el) {
      var key = el.getAttribute('data-layout-field');
      if (key && layout[key] !== undefined) {
        el.textContent = layout[key];
      }
    });
  }

  function saveLayout() {
    if (!state.editable) return Promise.resolve();
    var layout = extractLayout();
    setStatus('Saving layout…', 'saving');
    return apiPost('save_section_layout', {
      version_id: state.versionId,
      section_id: state.sectionId,
      layout: layout,
    }).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Layout save failed');
      state.pageLayout = res.layout || layout;
      setStatus('Layout saved', 'saved');
    }).catch(showError);
  }

  function scheduleLayoutSave() {
    clearTimeout(state.layoutTimer);
    state.layoutTimer = setTimeout(saveLayout, 600);
    setStatus('Saving layout…', 'saving');
  }

  function findHighlightsSectionId(nodes) {
    for (var i = 0; i < nodes.length; i++) {
      if (nodes[i].section_key === 'highlights') return nodes[i].id;
      if (nodes[i].children && nodes[i].children.length) {
        var found = findHighlightsSectionId(nodes[i].children);
        if (found) return found;
      }
    }
    return null;
  }

  function wireLayout() {
    canvasEl.querySelectorAll('[data-layout-toggle]').forEach(function (el) {
      if (el.getAttribute('data-layout-wired') === '1') return;
      el.setAttribute('data-layout-wired', '1');
      el.addEventListener('change', function () {
        pushUndo();
        setStatus('Saving layout…', 'saving');
        apiPost('save_section_layout', {
          version_id: state.versionId,
          section_id: state.sectionId,
          layout: extractLayout(),
        }).then(function (res) {
          if (!res.ok) throw new Error(res.error || 'Layout save failed');
          state.pageLayout = res.layout || extractLayout();
          return loadSection(state.sectionId);
        }).then(function () {
          setStatus('Layout saved', 'saved');
        }).catch(showError);
      });
    });
    canvasEl.querySelectorAll('[data-layout-field]').forEach(function (el) {
      if (el.getAttribute('data-layout-wired') === '1') return;
      el.setAttribute('data-layout-wired', '1');
      el.addEventListener('blur', function () {
        scheduleLayoutSave();
      });
      el.addEventListener('input', function () {
        setStatus('Editing header/footer…', 'saving');
      });
    });
  }

  function initCanvasEvents() {
    if (state.canvasEventsWired) return;
    state.canvasEventsWired = true;

    canvasEl.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-table-action]');
      if (!btn || !state.editable) return;
      e.preventDefault();
      var blockEl = btn.closest('.cpb-block');
      if (!blockEl) return;
      var action = btn.getAttribute('data-table-action');
      pushUndo();
      if (action === 'add-row') tableAddRow(blockEl);
      else if (action === 'del-row') tableDelRow(blockEl);
      else if (action === 'add-col') tableAddColumn(blockEl);
      else if (action === 'del-col') tableDelColumn(blockEl);
      else if (action === 'toggle-title') tableToggleTitle(blockEl);
      else if (action === 'border-thin' || action === 'border-medium' || action === 'border-thick') {
        applyTableBorderWidth(blockEl, action.replace('border-', ''));
      } else if (action === 'cell-bg-clear') {
        if (!state.focusedTableCell || !blockEl.contains(state.focusedTableCell)) {
          setStatus('Click a table cell first', 'error');
          return;
        }
        applyTableCellBg(blockEl, state.focusedTableCell, '');
      } else if (action === 'copy-cells') copyTableCells(blockEl);
      else if (action === 'paste-cells') pasteTableCells(blockEl);
      else if (action === 'formula-sum') insertTableFormula(blockEl, 'SUM');
      else if (action === 'formula-avg') insertTableFormula(blockEl, 'AVG');
      else if (action === 'formula-custom') insertTableFormula(blockEl, 'CUSTOM');
      scheduleSave(blockEl);
      flushSave(blockEl);
    });

    canvasEl.addEventListener('input', function (e) {
      var input = e.target.closest('input[data-table-action="border-color"], input[data-table-action="cell-bg"]');
      if (!input || !state.editable) return;
      var blockEl = input.closest('.cpb-block');
      if (!blockEl) return;
      pushUndo();
      var action = input.getAttribute('data-table-action');
      if (action === 'border-color') applyTableBorderColor(blockEl, input.value);
      else if (!state.focusedTableCell || !blockEl.contains(state.focusedTableCell)) {
        setStatus('Click a table cell first', 'error');
        return;
      } else applyTableCellBg(blockEl, state.focusedTableCell, input.value);
      scheduleSave(blockEl);
      flushSave(blockEl);
    });

    canvasEl.addEventListener('copy', function (e) {
      var cell = e.target.closest('.cpb-table th, .cpb-table td');
      if (!cell || !cell.isContentEditable) return;
      var blockEl = cell.closest('.cpb-block--table');
      if (!blockEl) return;
      var text = buildCopyText(blockEl, cell);
      if (!text) return;
      e.preventDefault();
      state.tableClipboard = text;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).catch(function () {});
      }
      e.clipboardData.setData('text/plain', text);
      setStatus('Copied', 'saved');
    });

    canvasEl.addEventListener('paste', function (e) {
      var cell = e.target.closest('.cpb-table th, .cpb-table td');
      if (!cell || !cell.isContentEditable || !state.editable) return;
      var blockEl = cell.closest('.cpb-block--table');
      if (!blockEl) return;
      var text = (e.clipboardData && e.clipboardData.getData('text/plain')) || state.tableClipboard;
      if (!text || (text.indexOf('\t') < 0 && text.indexOf('\n') < 0)) return;
      e.preventDefault();
      pushUndo();
      pasteTableData(blockEl, cell, text);
      scheduleSave(blockEl);
      flushSave(blockEl);
    });
  }

  function wireCanvas() {
    initCanvasEvents();

    canvasEl.querySelectorAll('.cpb-block').forEach(function (blockEl) {
      blockEl.querySelectorAll('[contenteditable="true"]').forEach(function (field) {
        if (field.getAttribute('data-input-wired') === '1') return;
        field.setAttribute('data-input-wired', '1');
        field.addEventListener('input', function () {
          scheduleSave(blockEl);
        });
        field.addEventListener('blur', function () {
          flushSave(blockEl);
        });
      });
    });

    canvasEl.querySelectorAll('.cpb-block-chrome [data-action]').forEach(function (btn) {
      if (btn.getAttribute('data-chrome-wired') === '1') return;
      btn.setAttribute('data-chrome-wired', '1');
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var blockEl = btn.closest('.cpb-block');
        if (!blockEl) return;
        var blockId = parseInt(blockEl.getAttribute('data-block-id') || '0', 10);
        var action = btn.getAttribute('data-action');
        if (action === 'delete') {
          if (!confirm('Delete this block?')) return;
          pushUndo();
          apiPost('delete_block', { block_id: blockId }).then(function (res) {
            if (!res.ok) throw new Error(res.error || 'Delete failed');
            blockEl.remove();
            setStatus('Deleted', 'saved');
          }).catch(showError);
        } else if (action === 'move-up' || action === 'move-down') {
          pushUndo();
          apiPost('move_block', {
            block_id: blockId,
            direction: action === 'move-up' ? 'up' : 'down',
            section_id: state.sectionId,
          }).then(function (res) {
            if (!res.ok) throw new Error(res.error || 'Move failed');
            var body = canvasEl.querySelector('[data-blocks-root]');
            if (body && res.page_body_html) {
              body.innerHTML = res.page_body_html;
              wireCanvas();
            }
            setStatus('Moved', 'saved');
          }).catch(showError);
        }
      });
    });

    canvasEl.querySelectorAll('.cpb-block--table').forEach(function (blockEl) {
      wireTableResize(blockEl);
      wireTableCellFocus(blockEl);
      syncTableStyleControls(blockEl);
    });

    wireLayout();

    var dropzone = canvasEl.querySelector('[data-dropzone="image"]');
    if (dropzone && state.editable && dropzone.getAttribute('data-drop-wired') !== '1') {
      dropzone.setAttribute('data-drop-wired', '1');
      ['dragenter', 'dragover'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) {
          e.preventDefault();
          dropzone.classList.add('is-drag');
        });
      });
      dropzone.addEventListener('dragleave', function () {
        dropzone.classList.remove('is-drag');
      });
      dropzone.addEventListener('drop', function (e) {
        e.preventDefault();
        dropzone.classList.remove('is-drag');
        var files = e.dataTransfer && e.dataTransfer.files;
        if (files && files[0]) uploadImageFile(files[0]);
      });
    }
  }

  function saveAllBlocks() {
    canvasEl.querySelectorAll('.cpb-block').forEach(function (blockEl) {
      flushSave(blockEl);
    });
  }

  function scheduleSave(blockEl) {
    var blockId = parseInt(blockEl.getAttribute('data-block-id') || '0', 10);
    if (!blockId) return;
    state.pending[blockId] = blockEl;
    clearTimeout(state.saveTimer);
    state.saveTimer = setTimeout(function () {
      Object.keys(state.pending).forEach(function (id) {
        flushSave(state.pending[id]);
      });
      state.pending = {};
    }, 700);
    setStatus('Editing…', 'saving');
  }

  function flushSave(blockEl) {
    if (!state.editable || !blockEl) return;
    var blockId = parseInt(blockEl.getAttribute('data-block-id') || '0', 10);
    var blockType = blockEl.getAttribute('data-block-type') || '';
    var payload = extractPayload(blockEl, blockType);
    setStatus('Saving…', 'saving');
    apiPost('update_block', { block_id: blockId, payload: payload }).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Save failed');
      setStatus('Saved', 'saved');
    }).catch(showError);
  }

  function extractStyleFields(blockEl, blockType) {
    var el = null;
    if (blockType === 'heading') el = blockEl.querySelector('.cpb-heading');
    else if (blockType === 'paragraph') el = blockEl.querySelector('.cpb-paragraph');
    if (!el) return {};
    return {
      font_family: el.getAttribute('data-font-family') || 'serif',
      text_align: el.getAttribute('data-text-align') || 'left',
    };
  }

  function extractPayload(blockEl, blockType) {
    if (blockType === 'heading') {
      var h = blockEl.querySelector('.cpb-heading');
      return Object.assign({
        text: h ? h.textContent.trim() : '',
        level: parseInt(h ? (h.getAttribute('data-level') || h.tagName.replace('H', '')) : '2', 10),
      }, extractStyleFields(blockEl, blockType));
    }
    if (blockType === 'paragraph') {
      var p = blockEl.querySelector('.cpb-paragraph');
      return Object.assign({
        html: p ? p.innerHTML : '',
      }, extractStyleFields(blockEl, blockType));
    }
    if (blockType === 'list') {
      var list = blockEl.querySelector('.cpb-list');
      var ordered = list && list.tagName === 'OL';
      var items = [];
      if (list) {
        list.querySelectorAll('li').forEach(function (li) {
          var t = li.textContent.trim();
          if (t) items.push(t);
        });
      }
      return { ordered: ordered, items: items };
    }
    if (blockType === 'table') {
      return extractTablePayload(blockEl);
    }
    if (blockType === 'image') {
      var img = blockEl.querySelector('img');
      var cap = blockEl.querySelector('figcaption');
      return {
        url: img ? img.getAttribute('src') || '' : '',
        alt: cap ? cap.textContent.trim() : '',
        width_pct: 100,
      };
    }
    if (blockType === 'callout') {
      var titleEl = blockEl.querySelector('.cpb-callout-title');
      var textEl = blockEl.querySelector('.cpb-callout-text');
      var calloutRoot = blockEl.querySelector('.cpb-callout');
      return {
        callout_type: calloutRoot ? (calloutRoot.getAttribute('data-callout-type') || 'warning') : 'warning',
        title: titleEl ? titleEl.textContent.trim() : 'WARNING',
        text: textEl ? textEl.textContent.trim() : '',
      };
    }
    return {};
  }

  function tableWrap(blockEl) {
    return blockEl.querySelector('.cpb-table-wrap');
  }

  function normalizeBorderWidth(value) {
    return value === 'thin' || value === 'thick' ? value : 'medium';
  }

  function applyTableBorderWidth(blockEl, width) {
    var wrap = tableWrap(blockEl);
    if (!wrap) return;
    width = normalizeBorderWidth(width);
    wrap.classList.remove('cpb-table-border-thin', 'cpb-table-border-medium', 'cpb-table-border-thick');
    wrap.classList.add('cpb-table-border-' + width);
    wrap.setAttribute('data-border-width', width);
    syncTableStyleControls(blockEl);
  }

  function applyTableBorderColor(blockEl, color) {
    var wrap = tableWrap(blockEl);
    if (!wrap || !color) return;
    wrap.style.setProperty('--cpb-table-border-color', color);
    wrap.setAttribute('data-border-color', color);
    syncTableStyleControls(blockEl);
  }

  function applyTableCellBg(blockEl, cell, color) {
    if (!cell || !blockEl.contains(cell)) return;
    if (color) {
      cell.style.backgroundColor = color;
      cell.setAttribute('data-cell-bg', color);
    } else {
      cell.style.backgroundColor = '';
      cell.removeAttribute('data-cell-bg');
    }
  }

  function syncTableStyleControls(blockEl) {
    var wrap = tableWrap(blockEl);
    if (!wrap) return;
    var width = normalizeBorderWidth(wrap.getAttribute('data-border-width') || 'medium');
    var color = wrap.getAttribute('data-border-color') || '#94a3b8';
    blockEl.querySelectorAll('[data-table-action^="border-"]').forEach(function (btn) {
      var action = btn.getAttribute('data-table-action');
      if (action === 'border-thin' || action === 'border-medium' || action === 'border-thick') {
        btn.classList.toggle('is-active', action === 'border-' + width);
      }
    });
    var borderColorInput = blockEl.querySelector('[data-table-action="border-color"]');
    if (borderColorInput) borderColorInput.value = color;
  }

  function wireTableCellFocus(blockEl) {
    blockEl.querySelectorAll('.cpb-table th, .cpb-table td').forEach(function (cell) {
      if (cell.getAttribute('data-cell-focus-wired') === '1') return;
      cell.setAttribute('data-cell-focus-wired', '1');
      cell.addEventListener('focus', function () {
        state.focusedTableCell = cell;
        var bgInput = blockEl.querySelector('[data-table-action="cell-bg"]');
        if (bgInput) {
          bgInput.value = cell.getAttribute('data-cell-bg') || '#ffffff';
        }
      });
    });
  }

  function extractCellBg(cell) {
    return cell.getAttribute('data-cell-bg') || '';
  }

  function extractTablePayload(blockEl) {
    var table = blockEl.querySelector('table');
    var wrap = tableWrap(blockEl);
    var titleCell = blockEl.querySelector('tr[data-title-row] td');
    var title = titleCell ? titleCell.textContent.trim() : '';
    var headers = [];
    var rows = [];
    var colWidths = [];
    var headerBg = [];
    var cellBg = [];

    if (table) {
      var head = tableHeaderRow(blockEl);
      if (head) {
        head.querySelectorAll('th').forEach(function (th) {
          var textEl = th.querySelector('.cpb-th-text');
          headers.push((textEl ? textEl.textContent : th.textContent).trim());
          headerBg.push(extractCellBg(th));
        });
      }
      table.querySelectorAll('tbody[data-table-part="body"] tr').forEach(function (tr) {
        var line = [];
        var bgLine = [];
        tr.querySelectorAll('td').forEach(function (td) {
          line.push(td.textContent.trim());
          bgLine.push(extractCellBg(td));
        });
        if (line.length) {
          rows.push(line);
          cellBg.push(bgLine);
        }
      });
      table.querySelectorAll('colgroup col').forEach(function (col) {
        var w = parseInt((col.style.width || '140').replace('px', ''), 10);
        colWidths.push(isNaN(w) ? 140 : w);
      });
    }

    return {
      title: title,
      has_title_row: !!blockEl.querySelector('tr[data-title-row]'),
      headers: headers,
      rows: rows,
      col_widths: colWidths,
      border_width: wrap ? normalizeBorderWidth(wrap.getAttribute('data-border-width') || 'medium') : 'medium',
      border_color: wrap ? (wrap.getAttribute('data-border-color') || '#94a3b8') : '#94a3b8',
      title_bg: titleCell ? extractCellBg(titleCell) : '',
      header_bg: headerBg,
      cell_bg: cellBg,
    };
  }

  function tableBody(blockEl) {
    var table = blockEl.querySelector('table');
    return table ? table.querySelector('tbody[data-table-part="body"]') : null;
  }

  function tableHeaderRow(blockEl) {
    var table = blockEl.querySelector('table');
    return table ? table.querySelector('thead tr.cpb-table-header-row') : null;
  }

  function tableColCount(blockEl) {
    var head = tableHeaderRow(blockEl);
    return head ? head.cells.length : 2;
  }

  function getTableCellCoords(blockEl, cell) {
    if (!cell || cell.closest('[data-title-row]')) return null;
    var table = blockEl.querySelector('table');
    if (!table || !table.contains(cell)) return null;
    if (cell.tagName === 'TH') {
      var head = tableHeaderRow(blockEl);
      if (!head || !head.contains(cell)) return null;
      return { row: -1, col: cell.cellIndex, ref: colLetter(cell.cellIndex) + '0' };
    }
    var tbody = tableBody(blockEl);
    if (!tbody || !tbody.contains(cell)) return null;
    var tr = cell.parentElement;
    var row = Array.prototype.indexOf.call(tbody.querySelectorAll('tr'), tr);
    return { row: row, col: cell.cellIndex, ref: colLetter(cell.cellIndex) + String(row + 1) };
  }

  function buildCopyText(blockEl, anchorCell) {
    var coords = getTableCellCoords(blockEl, anchorCell);
    if (!coords) {
      return anchorCell.textContent.trim();
    }
    var lines = [];
    var table = blockEl.querySelector('table');
    var tbody = tableBody(blockEl);
    if (coords.row < 0) {
      var head = tableHeaderRow(blockEl);
      if (head) lines.push(head.cells[coords.col].textContent.trim());
      if (tbody) {
        tbody.querySelectorAll('tr').forEach(function (tr) {
          if (tr.cells[coords.col]) lines.push(tr.cells[coords.col].textContent.trim());
        });
      }
      return lines.join('\n');
    }
    if (tbody) {
      var tr = tbody.querySelectorAll('tr')[coords.row];
      if (tr) {
        tr.querySelectorAll('td').forEach(function (td) {
          lines.push(td.textContent.trim());
        });
        return lines.join('\t');
      }
    }
    return anchorCell.textContent.trim();
  }

  function copyTableCells(blockEl) {
    if (!state.focusedTableCell || !blockEl.contains(state.focusedTableCell)) {
      setStatus('Click a table cell first', 'error');
      return;
    }
    var text = buildCopyText(blockEl, state.focusedTableCell);
    state.tableClipboard = text;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        setStatus('Copied', 'saved');
      }).catch(function () {
        setStatus('Copied to editor clipboard', 'saved');
      });
    } else {
      setStatus('Copied to editor clipboard', 'saved');
    }
  }

  function pasteTableCells(blockEl) {
    if (!state.focusedTableCell || !blockEl.contains(state.focusedTableCell)) {
      setStatus('Click a table cell first', 'error');
      return;
    }
    if (!state.tableClipboard) {
      setStatus('Nothing copied yet', 'error');
      return;
    }
    pushUndo();
    pasteTableData(blockEl, state.focusedTableCell, state.tableClipboard);
    scheduleSave(blockEl);
    flushSave(blockEl);
  }

  function pasteTableData(blockEl, anchorCell, text) {
    var coords = getTableCellCoords(blockEl, anchorCell);
    if (!coords) return;
    var rows = text.replace(/\r/g, '').split('\n').map(function (line) {
      return line.split('\t');
    });
    var tbody = tableBody(blockEl);
    var head = tableHeaderRow(blockEl);
    rows.forEach(function (cells, rIndex) {
      var targetRow = coords.row + rIndex;
      if (targetRow < 0 && head) {
        cells.forEach(function (val, cIndex) {
          var col = coords.col + cIndex;
          if (head.cells[col]) {
            var thText = head.cells[col].querySelector('.cpb-th-text');
            if (thText) thText.textContent = val;
            else head.cells[col].textContent = val;
          }
        });
        return;
      }
      while (tbody && targetRow >= tbody.querySelectorAll('tr').length) {
        tableAddRow(blockEl);
      }
      var tr = tbody ? tbody.querySelectorAll('tr')[targetRow] : null;
      if (!tr) return;
      cells.forEach(function (val, cIndex) {
        var col = coords.col + cIndex;
        while (col >= tableColCount(blockEl)) tableAddColumn(blockEl);
        if (tr.cells[col]) tr.cells[col].textContent = val;
      });
    });
    wireTableCellFocus(blockEl);
    wireTableResize(blockEl);
  }

  function insertTableFormula(blockEl, kind) {
    if (!state.focusedTableCell || !blockEl.contains(state.focusedTableCell)) {
      setStatus('Click a body cell first', 'error');
      return;
    }
    var coords = getTableCellCoords(blockEl, state.focusedTableCell);
    if (!coords || coords.row < 0) {
      setStatus('Formulas apply to body cells', 'error');
      return;
    }
    var formula = '';
    if (kind === 'CUSTOM') {
      formula = prompt('Enter formula (e.g. =SUM(A1:A3) or =A1+B1)', '=');
      if (!formula) return;
      if (formula.charAt(0) !== '=') formula = '=' + formula;
    } else {
      var start = colLetter(0) + '1';
      var end = colLetter(Math.max(0, tableColCount(blockEl) - 1)) + String(coords.row + 1);
      formula = '=' + kind + '(' + start + ':' + end + ')';
    }
    state.focusedTableCell.textContent = formula;
    scheduleSave(blockEl);
  }

  function tableAddRow(blockEl) {
    var tbody = tableBody(blockEl);
    if (!tbody) return;
    var cols = tableColCount(blockEl);
    var tr = document.createElement('tr');
    for (var i = 0; i < cols; i++) {
      var td = document.createElement('td');
      td.contentEditable = 'true';
      td.textContent = '';
      tr.appendChild(td);
    }
    tbody.appendChild(tr);
    wireTableCellFocus(blockEl);
  }

  function tableDelRow(blockEl) {
    var tbody = tableBody(blockEl);
    if (!tbody) return;
    var rows = tbody.querySelectorAll('tr');
    if (rows.length <= 1) return;
    var target = null;
    if (state.focusedTableCell && tbody.contains(state.focusedTableCell)) {
      target = state.focusedTableCell.closest('tr');
    }
    if (!target) target = rows[rows.length - 1];
    target.remove();
  }

  function tableAddColumn(blockEl) {
    var table = blockEl.querySelector('table');
    if (!table) return;
    var colgroup = table.querySelector('colgroup');
    var cols = tableColCount(blockEl);

    if (colgroup) {
      var col = document.createElement('col');
      col.style.width = '140px';
      colgroup.appendChild(col);
    }

    var titleCell = blockEl.querySelector('tr[data-title-row] td');
    if (titleCell) {
      titleCell.colSpan = cols + 1;
    }

    var headRow = tableHeaderRow(blockEl);
    if (headRow) {
      var th = document.createElement('th');
      th.contentEditable = 'true';
      th.setAttribute('data-col-index', String(cols));
      th.innerHTML = '<span class="cpb-th-text">Column ' + (cols + 1) + '</span>'
        + '<span class="cpb-col-resize" data-col-index="' + cols + '" title="Resize column"></span>';
      headRow.appendChild(th);
    }

    table.querySelectorAll('tbody[data-table-part="body"] tr').forEach(function (tr) {
      var td = document.createElement('td');
      td.contentEditable = 'true';
      td.textContent = '';
      tr.appendChild(td);
    });

    wireTableResize(blockEl);
    wireTableCellFocus(blockEl);
  }

  function tableDelColumn(blockEl) {
    var table = blockEl.querySelector('table');
    if (!table) return;
    var cols = tableColCount(blockEl);
    if (cols <= 1) return;
    var colIndex = cols - 1;

    var colgroup = table.querySelector('colgroup');
    if (colgroup && colgroup.children[colIndex]) {
      colgroup.children[colIndex].remove();
    }

    var headRow = tableHeaderRow(blockEl);
    if (headRow && headRow.cells[colIndex]) {
      headRow.cells[colIndex].remove();
    }

    table.querySelectorAll('tbody[data-table-part="body"] tr').forEach(function (tr) {
      if (tr.cells[colIndex]) tr.cells[colIndex].remove();
    });

    var titleCell = blockEl.querySelector('tr[data-title-row] td');
    if (titleCell) {
      titleCell.colSpan = cols - 1;
    }
  }

  function tableToggleTitle(blockEl) {
    var table = blockEl.querySelector('table');
    if (!table) return;
    var thead = table.querySelector('thead');
    if (!thead) return;
    var existing = thead.querySelector('[data-title-row]');
    var toggleBtn = blockEl.querySelector('[data-table-action="toggle-title"]');
    if (existing) {
      existing.remove();
      if (toggleBtn) toggleBtn.textContent = '+ Title row';
      return;
    }
    var cols = tableColCount(blockEl);
    var tr = document.createElement('tr');
    tr.className = 'cpb-table-title-row is-empty';
    tr.setAttribute('data-title-row', '1');
    var td = document.createElement('td');
    td.colSpan = cols;
    td.contentEditable = 'true';
    td.setAttribute('data-placeholder', 'Table title (spans all columns)');
    tr.appendChild(td);
    thead.insertBefore(tr, tableHeaderRow(blockEl));
    if (toggleBtn) toggleBtn.textContent = 'Remove title row';
    wireTableCellFocus(blockEl);
    td.focus();
  }

  function ensureResizeHint() {
    if (!state.resizeHintEl) {
      state.resizeHintEl = document.createElement('div');
      state.resizeHintEl.className = 'cpb-col-resize-hint';
      document.body.appendChild(state.resizeHintEl);
    }
    return state.resizeHintEl;
  }

  function wireTableResize(blockEl) {
    blockEl.querySelectorAll('.cpb-col-resize').forEach(function (handle) {
      if (handle.getAttribute('data-wired') === '1') return;
      handle.setAttribute('data-wired', '1');
      handle.addEventListener('mousedown', function (e) {
        e.preventDefault();
        pushUndo();
        var colIndex = parseInt(handle.getAttribute('data-col-index') || '0', 10);
        var table = blockEl.querySelector('table');
        if (!table) return;
        var col = table.querySelectorAll('colgroup col')[colIndex];
        var startX = e.clientX;
        var startW = col ? parseInt((col.style.width || '140').replace('px', ''), 10) : 140;
        if (isNaN(startW)) startW = 140;

        var hint = ensureResizeHint();

        function onMove(ev) {
          var w = Math.max(60, Math.min(600, startW + (ev.clientX - startX)));
          if (col) col.style.width = w + 'px';
          hint.textContent = w + 'px';
          hint.style.display = 'block';
          hint.style.left = (ev.clientX + 12) + 'px';
          hint.style.top = (ev.clientY + 12) + 'px';
        }

        function onUp() {
          document.removeEventListener('mousemove', onMove);
          document.removeEventListener('mouseup', onUp);
          hint.style.display = 'none';
          scheduleSave(blockEl);
          flushSave(blockEl);
        }

        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
      });
    });
  }

  function getActiveStyleTarget() {
    var sel = window.getSelection();
    if (sel && sel.anchorNode) {
      var node = sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentElement;
      if (node) {
        var block = node.closest('.cpb-block');
        if (block) {
          var heading = block.querySelector('.cpb-heading');
          var paragraph = block.querySelector('.cpb-paragraph');
          if (heading && (heading.contains(node) || node === heading)) return { block: block, el: heading, type: 'heading' };
          if (paragraph && (paragraph.contains(node) || node === paragraph)) return { block: block, el: paragraph, type: 'paragraph' };
          if (heading) return { block: block, el: heading, type: 'heading' };
          if (paragraph) return { block: block, el: paragraph, type: 'paragraph' };
        }
      }
    }
    var focused = document.activeElement;
    if (focused && focused.closest) {
      var blockEl = focused.closest('.cpb-block');
      if (blockEl) {
        var h2 = blockEl.querySelector('.cpb-heading');
        var p2 = blockEl.querySelector('.cpb-paragraph');
        if (h2) return { block: blockEl, el: h2, type: 'heading' };
        if (p2) return { block: blockEl, el: p2, type: 'paragraph' };
      }
    }
    return null;
  }

  function applyFontFamily(font) {
    var target = getActiveStyleTarget();
    if (!target) return;
    pushUndo();
    FONT_CLASSES.forEach(function (cls) { target.el.classList.remove(cls); });
    target.el.classList.add('cpb-font-' + font);
    target.el.setAttribute('data-font-family', font);
    scheduleSave(target.block);
    flushSave(target.block);
  }

  function applyTextAlign(align) {
    var target = getActiveStyleTarget();
    if (!target) return;
    pushUndo();
    ALIGN_CLASSES.forEach(function (cls) { target.el.classList.remove(cls); });
    target.el.classList.add('cpb-align-' + align);
    target.el.setAttribute('data-text-align', align);
    target.el.style.textAlign = align;
    scheduleSave(target.block);
    flushSave(target.block);
  }

  function createBlock(blockType, payload) {
    pushUndo();
    setStatus('Adding block…', 'saving');
    return apiPost('create_block', {
      version_id: state.versionId,
      section_id: state.sectionId,
      block_type: blockType,
      payload: payload || {},
    }).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Create failed');
      var body = canvasEl.querySelector('[data-blocks-root]');
      if (body && res.block_html) {
        body.insertAdjacentHTML('beforeend', res.block_html);
        wireCanvas();
        var newBlock = body.querySelector('.cpb-block:last-child');
        if (newBlock) {
          var field = newBlock.querySelector('[contenteditable="true"]');
          if (field) field.focus();
        }
      }
      setStatus('Added', 'saved');
      return res;
    });
  }

  function insertCallout(type) {
    var preset = presetByType(type);
    if (!preset) {
      preset = {
        callout_type: type,
        title: type === 'caution' ? 'CAUTION' : 'WARNING',
        text: '',
      };
    }
    createBlock('callout', {
      callout_type: type,
      title: preset.title || (type === 'caution' ? 'CAUTION' : 'WARNING'),
      text: preset.text || 'Enter callout text…',
    }).catch(showError);
  }

  function presetByType(type) {
    for (var i = 0; i < state.calloutPresets.length; i++) {
      if (state.calloutPresets[i].callout_type === type) return state.calloutPresets[i];
    }
    return null;
  }

  function openCalloutManager() {
    var warning = presetByType('warning') || { callout_type: 'warning', title: 'WARNING', text: '' };
    var caution = presetByType('caution') || { callout_type: 'caution', title: 'CAUTION', text: '' };

    var overlay = document.createElement('div');
    overlay.className = 'cpb-callout-overlay';
    overlay.innerHTML = ''
      + '<div class="cpb-callout-dialog" role="dialog" aria-label="Manage callout presets">'
      + '<h3>Callout presets</h3>'
      + '<p style="margin:0 0 12px;font-size:13px;color:#64748b;">Default title and text used when inserting Warning or Caution blocks.</p>'
      + '<div class="cpb-callout-field"><label>Warning title</label>'
      + '<input type="text" id="cpbPresetWarnTitle" value="' + escapeAttr(warning.title) + '"></div>'
      + '<div class="cpb-callout-field"><label>Warning default text</label>'
      + '<textarea id="cpbPresetWarnText">' + escapeHtml(warning.text) + '</textarea></div>'
      + '<div class="cpb-callout-field"><label>Caution title</label>'
      + '<input type="text" id="cpbPresetCautionTitle" value="' + escapeAttr(caution.title) + '"></div>'
      + '<div class="cpb-callout-field"><label>Caution default text</label>'
      + '<textarea id="cpbPresetCautionText">' + escapeHtml(caution.text) + '</textarea></div>'
      + '<div class="cpb-callout-dialog-actions">'
      + '<button type="button" class="cpb-callout-cancel">Cancel</button>'
      + '<button type="button" class="cpb-callout-save">Save presets</button>'
      + '</div></div>';

    function close() {
      overlay.remove();
    }

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) close();
    });
    overlay.querySelector('.cpb-callout-cancel').addEventListener('click', close);
    overlay.querySelector('.cpb-callout-save').addEventListener('click', function () {
      var presets = [
        {
          callout_type: 'warning',
          title: overlay.querySelector('#cpbPresetWarnTitle').value.trim() || 'WARNING',
          text: overlay.querySelector('#cpbPresetWarnText').value.trim(),
        },
        {
          callout_type: 'caution',
          title: overlay.querySelector('#cpbPresetCautionTitle').value.trim() || 'CAUTION',
          text: overlay.querySelector('#cpbPresetCautionText').value.trim(),
        },
      ];
      apiPost('save_callout_presets', {
        version_id: state.versionId,
        presets: presets,
      }).then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Save failed');
        state.calloutPresets = res.presets || presets;
        close();
        setStatus('Callout presets saved', 'saved');
      }).catch(showError);
    });

    document.body.appendChild(overlay);
  }

  function escapeAttr(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
  }

  function escapeHtml(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function syncHighlights() {
    if (!confirm('Regenerate the Highlight of Changes section from revision markers?')) return;
    setStatus('Syncing highlights…', 'saving');
    apiPost('regenerate_highlights', { version_id: state.versionId })
      .then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Sync failed');
        var count = res.result && res.result.changes_count !== undefined ? res.result.changes_count : 0;
        setStatus('Highlights updated (' + count + ' changes)', 'saved');
        var highlightsId = findHighlightsSectionId(state.sectionsTree);
        if (highlightsId) loadSection(highlightsId);
      })
      .catch(showError);
  }

  function uploadImageFile(file) {
    if (!file || !file.type || file.type.indexOf('image/') !== 0) {
      showError(new Error('Please drop an image file'));
      return;
    }
    pushUndo();
    setStatus('Uploading image…', 'saving');
    var fd = new FormData();
    fd.append('action', 'upload_image');
    fd.append('version_id', String(state.versionId));
    fd.append('section_id', String(state.sectionId));
    fd.append('image', file);
    fetch(apiBase, { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Upload failed');
        var body = canvasEl.querySelector('[data-blocks-root]');
        if (body && res.block_html) {
          body.insertAdjacentHTML('beforeend', res.block_html);
          wireCanvas();
        }
        setStatus('Image added', 'saved');
      })
      .catch(showError);
  }

  function showError(err) {
    setStatus(err && err.message ? err.message : 'Error', 'error');
  }

  function execFormat(cmd, value) {
    document.execCommand(cmd, false, value || null);
    var sel = window.getSelection();
    if (!sel || !sel.anchorNode) return;
    var blockEl = sel.anchorNode.nodeType === 1
      ? sel.anchorNode.closest('.cpb-block')
      : sel.anchorNode.parentElement && sel.anchorNode.parentElement.closest('.cpb-block');
    if (blockEl) scheduleSave(blockEl);
  }

  if (toolbarEl) {
    toolbarEl.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-cmd]');
      if (btn) {
        e.preventDefault();
        execFormat(btn.getAttribute('data-cmd'));
        return;
      }
      var alignBtn = e.target.closest('[data-align]');
      if (alignBtn) {
        e.preventDefault();
        applyTextAlign(alignBtn.getAttribute('data-align'));
        return;
      }
      var calloutBtn = e.target.closest('[data-add-callout]');
      if (calloutBtn) {
        e.preventDefault();
        insertCallout(calloutBtn.getAttribute('data-add-callout'));
        return;
      }
      var add = e.target.closest('[data-add-block]');
      if (add) {
        e.preventDefault();
        var type = add.getAttribute('data-add-block');
        var payload = {};
        if (type === 'heading') payload = { text: 'New heading', level: 2 };
        if (type === 'paragraph') payload = { html: '<p>New paragraph</p>' };
        if (type === 'list') payload = { ordered: false, items: ['List item'] };
        if (type === 'table') {
          payload = {
            title: '',
            headers: ['Column 1', 'Column 2'],
            rows: [['', '']],
            col_widths: [140, 140],
            border_width: 'medium',
            border_color: '#94a3b8',
          };
        }
        createBlock(type, payload).catch(showError);
        return;
      }
      if (e.target.closest('#cpbPickImage')) {
        e.preventDefault();
        if (imageInput) imageInput.click();
      }
      if (e.target.closest('#cpbUndo')) {
        e.preventDefault();
        doUndo();
      }
      if (e.target.closest('#cpbManageCallouts')) {
        e.preventDefault();
        openCalloutManager();
      }
      if (e.target.closest('#cpbSyncHighlights')) {
        e.preventDefault();
        syncHighlights();
      }
    });
  }

  if (fontSelect) {
    fontSelect.addEventListener('change', function () {
      applyFontFamily(fontSelect.value);
    });
  }

  if (textColorInput) {
    textColorInput.addEventListener('input', function () {
      execFormat('foreColor', textColorInput.value);
    });
  }

  if (undoBtn) {
    undoBtn.addEventListener('click', function (e) {
      e.preventDefault();
      doUndo();
    });
  }

  if (manageCalloutsBtn) {
    manageCalloutsBtn.addEventListener('click', function (e) {
      e.preventDefault();
      openCalloutManager();
    });
  }

  if (syncHighlightsBtn) {
    syncHighlightsBtn.addEventListener('click', function (e) {
      e.preventDefault();
      syncHighlights();
    });
  }

  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
      var inEditor = root.contains(document.activeElement);
      if (inEditor) {
        e.preventDefault();
        doUndo();
      }
    }
  });

  if (imageInput) {
    imageInput.addEventListener('change', function () {
      if (imageInput.files && imageInput.files[0]) {
        uploadImageFile(imageInput.files[0]);
        imageInput.value = '';
      }
    });
  }

  if (addSubBtn) {
    addSubBtn.addEventListener('click', function () {
      var parentId = parseInt(addSubBtn.getAttribute('data-parent-id') || '0', 10);
      var title = prompt('Subsection title');
      if (!title || !title.trim()) return;
      apiPost('create_subsection', {
        version_id: state.versionId,
        parent_section_id: parentId,
        title: title.trim(),
      }).then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Could not create subsection');
        state.sectionsTree = res.sections_tree || state.sectionsTree;
        renderTree(state.sectionsTree, res.section_id || state.sectionId);
        if (res.section_id) loadSection(res.section_id);
        else loadSection(state.sectionId);
        setStatus('Subsection created', 'saved');
      }).catch(showError);
    });
    addSubBtn.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        addSubBtn.click();
      }
    });
  }

  loadCalloutPresets()
    .then(function () { return loadSection(initialSectionId || 0); })
    .catch(showError);
})();
