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
  var redoBtn = document.getElementById('cpbRedo');
  var paragraphStyleSelect = document.getElementById('cpbParagraphStyleSelect');
  var regulatoryRefInput = document.getElementById('cpbRegulatoryRef');
  var fontSelect = document.getElementById('cpbFontSelect');
  var fontSizeSelect = document.getElementById('cpbFontSizeSelect');
  var openStyleEditorBtn = document.getElementById('cpbOpenStyleEditor');
  var calloutSelect = document.getElementById('cpbCalloutSelect');
  var syncSelect = document.getElementById('cpbSyncSelect');
  var textColorInput = document.getElementById('cpbTextColor');
  var fullscreenBtn = document.getElementById('cpbFullscreen');
  var zoomInBtn = document.getElementById('cpbZoomIn');
  var zoomOutBtn = document.getElementById('cpbZoomOut');
  var zoomLabelEl = document.getElementById('cpbZoomLabel');
  var indentBtn = document.getElementById('cpbIndent');
  var outdentBtn = document.getElementById('cpbOutdent');

  var FONT_CLASSES = [
    'cpb-font-serif', 'cpb-font-sans', 'cpb-font-mono', 'cpb-font-arial',
  ];
  var PARAGRAPH_STYLE_CLASSES = [
    'cpb-ps-title', 'cpb-ps-subtitle_1', 'cpb-ps-heading_1', 'cpb-ps-heading_2',
    'cpb-ps-subtitle_3', 'cpb-ps-subtitle_4', 'cpb-ps-regulatory_reference', 'cpb-ps-body', 'cpb-ps-caption',
  ];
  var PARAGRAPH_STYLE_KEYS = [
    'title', 'subtitle_1', 'heading_1', 'heading_2', 'subtitle_3', 'subtitle_4',
    'regulatory_reference', 'body', 'caption',
  ];
  var NUMBERED_PARAGRAPH_STYLES = {
    title: 1,
    subtitle_1: 2,
    heading_1: 3,
    heading_2: 4,
    subtitle_3: 5,
    subtitle_4: 6,
  };
  var PARAGRAPH_STYLE_LABELS = {
    title: 'Title',
    subtitle_1: 'Subtitle 1',
    heading_1: 'Heading 1',
    heading_2: 'Heading 2',
    subtitle_3: 'Subtitle 3',
    subtitle_4: 'Subtitle 4',
    regulatory_reference: 'Regulatory Reference',
    body: 'Body',
    caption: 'Caption',
  };
  var ALIGN_CLASSES = ['cpb-align-left', 'cpb-align-center', 'cpb-align-right'];
  var FONT_STACKS = {
    serif: "Georgia, 'Times New Roman', serif",
    sans: "system-ui, -apple-system, 'Segoe UI', sans-serif",
    mono: "'Courier New', Courier, monospace",
    arial: 'Arial, Helvetica, sans-serif',
  };

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
    bookStyles: null,
    sectionNumberDisplay: {},
    suggestedRegulatoryRefs: {},
    manualCode: '',
    undoStack: [],
    redoStack: [],
    layoutTimer: null,
    focusedTableCell: null,
    canvasEventsWired: false,
    resizeHintEl: null,
    tableClipboard: '',
    canvasZoom: 100,
    lastStyleTarget: null,
  };

  var INDENT_STEP_PX = 24;
  var INDENT_MAX_LEVEL = 8;
  var ZOOM_MIN = 50;
  var ZOOM_MAX = 200;
  var ZOOM_STEP = 10;

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
        state.calloutPresets = defaultBookStyles().callout_presets;
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
      state.bookStyles = res.book_styles || defaultBookStyles();
      if (state.bookStyles.callout_presets) {
        state.calloutPresets = state.bookStyles.callout_presets;
      }
      applyNumberingState(res);
      refreshCalloutSelectOptions();
      state.undoStack = [];
      state.redoStack = [];
      root.classList.toggle('cpb-editor-readonly', !state.editable);
      if (toolbarEl) toolbarEl.style.display = state.editable ? 'flex' : 'none';
      renderTree(state.sectionsTree, state.sectionId);
      canvasEl.innerHTML = res.page_html || '';
      wireCanvas();
      applyCanvasZoom(state.canvasZoom, false);
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

  function doRedo() {
    var active = document.activeElement;
    if (active && active.isContentEditable && document.queryCommandSupported('redo')) {
      document.execCommand('redo');
      var blockEl = active.closest('.cpb-block');
      if (blockEl) scheduleSave(blockEl);
      return;
    }
    if (state.redoStack.length === 0) return;
    state.undoStack.push(captureUndoSnapshot());
    restoreUndoSnapshot(state.redoStack.pop());
    setStatus('Redone', 'saved');
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

    canvasEl.addEventListener('focusin', function (e) {
      var cell = e.target.closest('.cpb-table th, .cpb-table td');
      if (cell && cell.isContentEditable) {
        state.focusedTableCell = cell;
      }
      rememberStyleTarget();
    });

    canvasEl.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-table-action]');
      if (!btn || !state.editable) return;
      e.preventDefault();
      var blockEl = btn.closest('.cpb-block');
      if (!blockEl) return;
      var action = btn.getAttribute('data-table-action');
      if (action === 'delete-table') {
        if (!confirm('Delete this entire table?')) return;
        var blockId = parseInt(blockEl.getAttribute('data-block-id') || '0', 10);
        apiPost('delete_block', { block_id: blockId }).then(function (res) {
          if (!res.ok) throw new Error(res.error || 'Delete failed');
          blockEl.remove();
          setStatus('Table deleted', 'saved');
        }).catch(showError);
        return;
      }
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
      else if (action === 'table-align-left' || action === 'table-align-center' || action === 'table-align-right') {
        applyTableBlockAlign(blockEl, action.replace('table-align-', ''));
      }
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

    canvasEl.addEventListener('mousedown', function (e) {
      var handle = e.target.closest('.cpb-col-resize');
      if (!handle || !state.editable) return;
      e.preventDefault();
      e.stopPropagation();
      var blockEl = handle.closest('.cpb-block--table');
      if (!blockEl) return;
      pushUndo();
      var colIndex = parseInt(handle.getAttribute('data-col-index') || '0', 10);
      var table = blockEl.querySelector('table');
      if (!table) return;
      var cols = table.querySelectorAll('colgroup col');
      var col = cols[colIndex];
      var startX = e.clientX;
      var startW = colWidthPx(col);
      var hint = ensureResizeHint();

      function onMove(ev) {
        var w = clampColWidth(blockEl, colIndex, startW + (ev.clientX - startX));
        if (col) col.style.width = w + 'px';
        syncTableWidth(blockEl);
        hint.textContent = w + 'px';
        hint.style.display = 'block';
        hint.style.left = (ev.clientX + 12) + 'px';
        hint.style.top = (ev.clientY + 12) + 'px';
      }

      function onUp() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        hint.style.display = 'none';
        syncTableWidth(blockEl);
        scheduleSave(blockEl);
        flushSave(blockEl);
      }

      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onUp);
    });

    canvasEl.addEventListener('mousedown', function (e) {
      var imgHandle = e.target.closest('.cpb-image-resize');
      if (!imgHandle || !state.editable) return;
      e.preventDefault();
      e.stopPropagation();
      var blockEl = imgHandle.closest('.cpb-block--image');
      var figure = blockEl ? blockEl.querySelector('.cpb-image') : null;
      if (!blockEl || !figure) return;
      pushUndo();
      var startX = e.clientX;
      var startW = figure.offsetWidth;
      var container = figure.closest('.cpb-sheet-body') || figure.parentElement;
      var maxW = container ? container.clientWidth : startW;
      var hint = ensureResizeHint();

      function onMove(ev) {
        var w = Math.max(80, Math.min(maxW, startW + (ev.clientX - startX)));
        var pct = Math.max(20, Math.min(100, Math.round((w / maxW) * 100)));
        figure.style.width = pct + '%';
        figure.setAttribute('data-width-pct', String(pct));
        hint.textContent = pct + '%';
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
        if (action === 'insert-paragraph') {
          pushUndo();
          createBlock('paragraph', { html: '<p>New paragraph</p>' }, blockEl).catch(showError);
        } else if (action === 'delete') {
          if (!confirm('Delete this block?')) return;
          pushUndo();
          apiPost('delete_block', { block_id: blockId }).then(function (res) {
            if (!res.ok) throw new Error(res.error || 'Delete failed');
            blockEl.remove();
            setStatus('Deleted', 'saved');
            return recomputeSectionNumbers();
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
            applyNumberingState(res);
            if (body && res.page_body_html) {
              body.innerHTML = res.page_body_html;
              wireCanvas();
            } else {
              return recomputeSectionNumbers();
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

    canvasEl.querySelectorAll('.cpb-block--image').forEach(function (blockEl) {
      wireImageBlock(blockEl);
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

  function flushSave(blockEl, refreshNumbering) {
    if (!state.editable || !blockEl) return;
    var blockId = parseInt(blockEl.getAttribute('data-block-id') || '0', 10);
    var blockType = blockEl.getAttribute('data-block-type') || '';
    var payload = extractPayload(blockEl, blockType);
    setStatus('Saving…', 'saving');
    apiPost('update_block', { block_id: blockId, payload: payload }).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Save failed');
      applyNumberingState(res);
      setStatus('Saved', 'saved');
      if (refreshNumbering) {
        return recomputeSectionNumbers();
      }
    }).catch(showError);
  }

  function defaultBookStyles() {
    return {
      paragraph_styles: {
        title: { font_family: 'sans', font_size: 24, color: '#0f2744' },
        subtitle_1: { font_family: 'sans', font_size: 18, color: '#0f2744' },
        heading_1: { font_family: 'sans', font_size: 16, color: '#0f2744' },
        heading_2: { font_family: 'sans', font_size: 14, color: '#0f2744' },
        subtitle_3: { font_family: 'sans', font_size: 12, color: '#334155' },
        subtitle_4: { font_family: 'sans', font_size: 11, color: '#475569' },
        regulatory_reference: { font_family: 'mono', font_size: 10, color: '#1e3a8a' },
        body: { font_family: 'serif', font_size: 11, color: '#0f172a' },
        caption: { font_family: 'sans', font_size: 9, color: '#64748b' },
      },
      table_styles: {
        standard: defaultTableStyleDef(),
        text: Object.assign({}, defaultTableStyleDef(), { border_width: 'thin' }),
      },
      callout_presets: [
        { callout_type: 'warning', title: 'WARNING', text: '' },
        { callout_type: 'caution', title: 'CAUTION', text: '' },
        { callout_type: 'info', title: 'INFO', text: '' },
      ],
    };
  }

  function defaultTableStyleDef() {
    return {
      border_width: 'medium',
      border_color: '#94a3b8',
      cell_bg: '#ffffff',
      title_row: { font_family: 'sans', font_size: 11, color: '#0f2744', bg: '#e8eef6' },
      header_row: { font_family: 'sans', font_size: 10, color: '#0f172a', bg: '#f1f5f9' },
      body_row: { font_family: 'serif', font_size: 10, color: '#0f172a', bg: '' },
    };
  }

  function resolveTypographyFromPayload(payload) {
    var styles = state.bookStyles || defaultBookStyles();
    var ps = (payload && payload.paragraph_style) || 'body';
    var def = (styles.paragraph_styles && styles.paragraph_styles[ps]) || styles.paragraph_styles.body;
    return {
      font_family: (payload && payload.font_family) || def.font_family || 'serif',
      font_size: (payload && payload.font_size) || def.font_size || 11,
      color: (payload && (payload.text_color || payload.color)) || def.color || '#0f172a',
      text_align: (payload && payload.text_align) || 'left',
      indent_level: (payload && payload.indent_level) || 0,
    };
  }

  function extractStyleFields(blockEl, blockType) {
    var el = null;
    if (blockType === 'heading') el = blockEl.querySelector('.cpb-heading');
    else if (blockType === 'paragraph') el = blockEl.querySelector('.cpb-paragraph');
    else if (blockType === 'list') el = blockEl.querySelector('.cpb-list');
    if (!el) return {};
    var fields = {
      paragraph_style: el.getAttribute('data-paragraph-style') || 'body',
      font_family: el.getAttribute('data-font-family') || 'serif',
      text_align: el.getAttribute('data-text-align') || 'left',
      font_size: parseInt(el.getAttribute('data-font-size') || '11', 10) || 11,
      text_color: el.getAttribute('data-text-color') || '#0f172a',
      indent_level: parseInt(el.getAttribute('data-indent-level') || '0', 10) || 0,
    };
    if (fields.paragraph_style === 'regulatory_reference') {
      fields.regulatory_ref = el.getAttribute('data-regulatory-ref') || '';
    }
    return fields;
  }

  function applyNumberingState(res) {
    if (!res) return;
    if (res.section_number_display) {
      state.sectionNumberDisplay = res.section_number_display;
    }
    if (res.suggested_regulatory_refs) {
      state.suggestedRegulatoryRefs = res.suggested_regulatory_refs;
    }
    if (res.manual_code !== undefined) {
      state.manualCode = res.manual_code || '';
    }
  }

  function styleNeedsNumberingRefresh(styleKey) {
    return !!NUMBERED_PARAGRAPH_STYLES[styleKey] || styleKey === 'regulatory_reference';
  }

  function recomputeSectionNumbers() {
    return apiPost('recompute_section_numbers', {
      version_id: state.versionId,
      section_id: state.sectionId,
    }).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Numbering refresh failed');
      applyNumberingState(res);
      if (res.page_html) {
        canvasEl.innerHTML = res.page_html;
        wireCanvas();
        applyCanvasZoom(state.canvasZoom, false);
      }
      return res;
    });
  }

  function updateRegulatoryRefFieldVisibility(styleKey, el, blockId) {
    if (!regulatoryRefInput) return;
    var show = styleKey === 'regulatory_reference';
    regulatoryRefInput.hidden = !show;
    if (!show) return;
    var manual = el ? (el.getAttribute('data-regulatory-ref') || '') : '';
    if (manual) {
      regulatoryRefInput.value = manual;
      regulatoryRefInput.placeholder = 'MCCF key (manual)';
      return;
    }
    var suggested = state.suggestedRegulatoryRefs[blockId] || '';
    regulatoryRefInput.value = '';
    regulatoryRefInput.placeholder = suggested ? ('Auto: ' + suggested) : 'MCCF key';
  }

  function stripEditorChromeFromHtml(html) {
    var tmp = document.createElement('div');
    tmp.innerHTML = html || '';
    tmp.querySelectorAll('.cpb-section-number, .cpb-regulatory-ref').forEach(function (el) {
      el.remove();
    });
    return tmp.innerHTML;
  }

  function stripLeadingSectionNumberText(text) {
    text = String(text || '').trim();
    var prev = null;
    while (prev !== text) {
      prev = text;
      text = text.replace(/^\d+(?:\.\d+)*\.?\s+/, '').trim();
    }
    return text;
  }

  function extractPayload(blockEl, blockType) {
    if (blockType === 'heading') {
      var h = blockEl.querySelector('.cpb-heading');
      return Object.assign({
        text: h ? stripLeadingSectionNumberText(h.textContent) : '',
        level: parseInt(h ? (h.getAttribute('data-level') || h.tagName.replace('H', '')) : '2', 10),
      }, extractStyleFields(blockEl, blockType));
    }
    if (blockType === 'paragraph') {
      var p = blockEl.querySelector('.cpb-paragraph');
      return Object.assign({
        html: p ? stripEditorChromeFromHtml(p.innerHTML) : '',
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
      return Object.assign({ ordered: ordered, items: items }, extractStyleFields(blockEl, 'list'));
    }
    if (blockType === 'table') {
      return extractTablePayload(blockEl);
    }
    if (blockType === 'image') {
      var figure = blockEl.querySelector('.cpb-image');
      var img = blockEl.querySelector('img');
      var cap = blockEl.querySelector('figcaption');
      return {
        url: img ? img.getAttribute('src') || '' : '',
        alt: cap ? cap.textContent.trim() : '',
        width_pct: figure ? parseInt(figure.getAttribute('data-width-pct') || '100', 10) : 100,
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
    var tableBlock = blockEl.querySelector('.cpb-table-block');
    var tableAlign = tableBlock ? (tableBlock.getAttribute('data-table-align') || 'left') : 'left';
    blockEl.querySelectorAll('[data-table-action^="table-align-"]').forEach(function (btn) {
      btn.classList.toggle('is-active', btn.getAttribute('data-table-action') === 'table-align-' + tableAlign);
    });
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
        if (fontSelect && cell.getAttribute('data-font-family')) {
          fontSelect.value = cell.getAttribute('data-font-family');
        }
        if (fontSizeSelect && cell.getAttribute('data-font-size')) {
          fontSizeSelect.value = cell.getAttribute('data-font-size');
        }
      });
      cell.addEventListener('input', function () {
        var titleRow = cell.closest('[data-title-row]');
        if (titleRow) {
          titleRow.classList.toggle('is-empty', cell.textContent.trim() === '');
        }
      });
    });
  }

  function extractCellBg(cell) {
    return cell.getAttribute('data-cell-bg') || '';
  }

  function extractCellAlign(cell) {
    return cell.getAttribute('data-cell-align') || 'left';
  }

  function extractCellFontFamily(cell) {
    return cell.getAttribute('data-font-family') || 'serif';
  }

  function extractCellFontSize(cell) {
    return parseInt(cell.getAttribute('data-font-size') || '11', 10) || 11;
  }

  function applyTableBlockAlign(blockEl, align) {
    var tableBlock = blockEl.querySelector('.cpb-table-block');
    if (!tableBlock) return;
    tableBlock.classList.remove('cpb-table-block--align-left', 'cpb-table-block--align-center', 'cpb-table-block--align-right');
    tableBlock.classList.add('cpb-table-block--align-' + align);
    tableBlock.setAttribute('data-table-align', align);
    blockEl.querySelectorAll('[data-table-action^="table-align-"]').forEach(function (btn) {
      btn.classList.toggle('is-active', btn.getAttribute('data-table-action') === 'table-align-' + align);
    });
  }

  function applyStyleToTableCell(cell, opts) {
    if (!cell) return;
    if (opts.font) {
      FONT_CLASSES.forEach(function (cls) { cell.classList.remove(cls); });
      cell.classList.add('cpb-font-' + opts.font);
      cell.setAttribute('data-font-family', opts.font);
      var stack = FONT_STACKS[opts.font];
      if (stack) {
        cell.style.fontFamily = stack;
        cell.style.setProperty('font-family', stack, 'important');
      }
      cell.style.removeProperty('color');
      cell.style.removeProperty('letter-spacing');
      cell.style.removeProperty('text-transform');
    }
    if (opts.size) {
      cell.style.fontSize = opts.size + 'pt';
      cell.setAttribute('data-font-size', String(opts.size));
    }
    if (opts.align) {
      cell.style.textAlign = opts.align;
      cell.setAttribute('data-cell-align', opts.align);
    }
  }

  function wireImageBlock(blockEl) {
    var figure = blockEl.querySelector('.cpb-image');
    if (!figure) return;
    var pct = parseInt(figure.getAttribute('data-width-pct') || '100', 10);
    if (!figure.style.width) figure.style.width = pct + '%';
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
    var headerAlign = [];
    var cellAlign = [];
    var titleAlign = 'center';
    var titleFontFamily = 'serif';
    var titleFontSize = 11;
    var tableBlock = blockEl.querySelector('.cpb-table-block');
    var tableAlign = tableBlock ? (tableBlock.getAttribute('data-table-align') || 'left') : 'left';

    if (table) {
      var head = tableHeaderRow(blockEl);
      if (head) {
        head.querySelectorAll('th').forEach(function (th) {
          var textEl = th.querySelector('.cpb-th-text');
          headers.push((textEl ? textEl.textContent : th.textContent).trim());
          headerBg.push(extractCellBg(th));
          headerAlign.push(extractCellAlign(th));
        });
      }
      if (titleCell) {
        titleAlign = extractCellAlign(titleCell);
        titleFontFamily = extractCellFontFamily(titleCell);
        titleFontSize = extractCellFontSize(titleCell);
      }
      table.querySelectorAll('tbody[data-table-part="body"] tr').forEach(function (tr) {
        var line = [];
        var bgLine = [];
        var alignLine = [];
        tr.querySelectorAll('td').forEach(function (td) {
          line.push(td.textContent.trim());
          bgLine.push(extractCellBg(td));
          alignLine.push(extractCellAlign(td));
        });
        if (line.length) {
          rows.push(line);
          cellBg.push(bgLine);
          cellAlign.push(alignLine);
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
      title_align: titleAlign,
      title_font_family: titleFontFamily,
      title_font_size: titleFontSize,
      header_align: headerAlign,
      cell_align: cellAlign,
      table_align: tableAlign,
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
      var others = tableOtherColsWidth(blockEl, cols);
      var newW = clampColWidth(blockEl, cols, Math.min(140, tableContentMaxWidth(blockEl) - others));
      col.style.width = newW + 'px';
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
    td.setAttribute('data-cell-align', 'center');
    td.setAttribute('data-font-family', 'serif');
    td.setAttribute('data-font-size', '11');
    td.classList.add('cpb-font-serif');
    td.style.textAlign = 'center';
    td.style.fontSize = '11pt';
    td.style.fontFamily = FONT_STACKS.serif;
    td.style.setProperty('font-family', FONT_STACKS.serif, 'important');
    tr.appendChild(td);
    thead.insertBefore(tr, tableHeaderRow(blockEl));
    if (toggleBtn) toggleBtn.textContent = 'Remove title row';
    wireTableCellFocus(blockEl);
    td.focus();
  }

  function colWidthPx(col) {
    if (!col) return 140;
    var w = parseInt(String(col.style.width || '140').replace('px', ''), 10);
    return isNaN(w) ? 140 : w;
  }

  function tableContentMaxWidth(blockEl) {
    var sheetBody = blockEl.closest('.cpb-sheet-body');
    if (sheetBody && sheetBody.clientWidth > 0) {
      return sheetBody.clientWidth;
    }
    var sheet = blockEl.closest('.cpb-sheet');
    if (sheet && sheet.clientWidth > 0) {
      var style = window.getComputedStyle(sheet);
      var padL = parseFloat(style.paddingLeft) || 0;
      var padR = parseFloat(style.paddingRight) || 0;
      return Math.max(200, sheet.clientWidth - padL - padR);
    }
    return 704;
  }

  function tableOtherColsWidth(blockEl, skipIndex) {
    var table = blockEl.querySelector('table');
    if (!table) return 0;
    var total = 0;
    table.querySelectorAll('colgroup col').forEach(function (col, idx) {
      if (idx !== skipIndex) total += colWidthPx(col);
    });
    return total;
  }

  function clampColWidth(blockEl, colIndex, desired) {
    var min = 60;
    var max = 600;
    var maxTable = tableContentMaxWidth(blockEl);
    var others = tableOtherColsWidth(blockEl, colIndex);
    var maxForCol = Math.max(min, maxTable - others);
    return Math.max(min, Math.min(max, maxForCol, desired));
  }

  function tableTotalWidth(blockEl) {
    var table = blockEl.querySelector('table');
    if (!table) return 0;
    var total = 0;
    table.querySelectorAll('colgroup col').forEach(function (col) {
      total += colWidthPx(col);
    });
    return total;
  }

  function syncTableWidth(blockEl) {
    var table = blockEl.querySelector('table');
    if (!table) return;
    var total = tableTotalWidth(blockEl);
    var maxTable = tableContentMaxWidth(blockEl);
    table.style.width = Math.min(total, maxTable) + 'px';
    table.style.maxWidth = '100%';
    table.style.minWidth = '0';
  }

  function fitTableToPage(blockEl) {
    var table = blockEl.querySelector('table');
    if (!table) return;
    var cols = table.querySelectorAll('colgroup col');
    var total = tableTotalWidth(blockEl);
    var maxTable = tableContentMaxWidth(blockEl);
    if (total > maxTable && cols.length > 0) {
      var scale = maxTable / total;
      cols.forEach(function (col) {
        var w = Math.max(60, Math.round(colWidthPx(col) * scale));
        col.style.width = w + 'px';
      });
    }
    syncTableWidth(blockEl);
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
    fitTableToPage(blockEl);
  }

  function getActiveTableCell() {
    var cell = state.focusedTableCell;
    if (cell && document.body.contains(cell) && cell.isContentEditable) {
      var trackedBlock = cell.closest('.cpb-block');
      if (trackedBlock) return { block: trackedBlock, el: cell, type: 'table-cell' };
    }
    var focused = document.activeElement;
    if (!focused || !focused.closest) return null;
    cell = focused.closest('.cpb-table th, .cpb-table td');
    if (!cell || !cell.isContentEditable) return null;
    var block = cell.closest('.cpb-block');
    return block ? { block: block, el: cell, type: 'table-cell' } : null;
  }

  function blockStyleTarget(block, node, focused) {
    if (!block) return null;
    var heading = block.querySelector('.cpb-heading');
    var paragraph = block.querySelector('.cpb-paragraph');
    var list = block.querySelector('.cpb-list');
    if (heading && (heading.contains(node) || node === heading || (focused && focused.closest('.cpb-heading')))) {
      return { block: block, el: heading, type: 'heading' };
    }
    if (paragraph && (paragraph.contains(node) || node === paragraph || (focused && focused.closest('.cpb-paragraph')))) {
      return { block: block, el: paragraph, type: 'paragraph' };
    }
    if (list && (list.contains(node) || node === list || (focused && focused.closest('.cpb-list')))) {
      return { block: block, el: list, type: 'list' };
    }
    return null;
  }

  function selectionInCanvas() {
    var sel = window.getSelection();
    if (!sel || !sel.anchorNode) return false;
    var node = sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentElement;
    return !!(node && canvasEl.contains(node));
  }

  function getActiveStyleTargetFromEditor() {
    var tableTarget = getActiveTableCell();
    if (tableTarget) return tableTarget;

    var sel = window.getSelection();
    if (sel && sel.anchorNode) {
      var node = sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentElement;
      if (node) {
        var tableCell = node.closest('.cpb-table th, .cpb-table td');
        if (tableCell && tableCell.isContentEditable) {
          var tableBlock = tableCell.closest('.cpb-block');
          if (tableBlock) return { block: tableBlock, el: tableCell, type: 'table-cell' };
        }
        var block = node.closest('.cpb-block');
        var styleTarget = blockStyleTarget(block, node, null);
        if (styleTarget) return styleTarget;
      }
    }
    var focused = document.activeElement;
    if (focused && focused.closest) {
      var blockEl = focused.closest('.cpb-block');
      var focusedTarget = blockStyleTarget(blockEl, focused, focused);
      if (focusedTarget) return focusedTarget;
    }
    return null;
  }

  function syncToolbarFromTarget(target) {
    if (!target) return;
    if (target.type === 'table-cell') {
      var cellFont = target.el.getAttribute('data-font-family') || 'serif';
      var cellSize = parseInt(target.el.getAttribute('data-font-size') || '10', 10) || 10;
      if (fontSelect) fontSelect.value = cellFont;
      if (fontSizeSelect) fontSizeSelect.value = String(cellSize);
      return;
    }
    if (target.type === 'heading' || target.type === 'paragraph' || target.type === 'list') {
      var ps = target.el.getAttribute('data-paragraph-style') || 'body';
      var font = target.el.getAttribute('data-font-family') || 'serif';
      var size = parseInt(target.el.getAttribute('data-font-size') || '11', 10) || 11;
      var color = target.el.getAttribute('data-text-color') || '#0f172a';
      var blockId = parseInt(target.block.getAttribute('data-block-id') || '0', 10);
      if (paragraphStyleSelect) paragraphStyleSelect.value = ps;
      if (fontSelect) fontSelect.value = font;
      if (fontSizeSelect) fontSizeSelect.value = String(size);
      if (textColorInput) textColorInput.value = color;
      updateRegulatoryRefFieldVisibility(ps, target.el, blockId);
    }
  }

  function rememberStyleTarget() {
    if (!selectionInCanvas() && !(document.activeElement && canvasEl.contains(document.activeElement))) {
      return;
    }
    var target = getActiveStyleTargetFromEditor();
    if (target) {
      state.lastStyleTarget = target;
      syncToolbarFromTarget(target);
    }
  }

  function tablePayloadFromBookStyles(kind) {
    kind = kind || 'standard';
    var styles = state.bookStyles || defaultBookStyles();
    var def = (styles.table_styles && styles.table_styles[kind]) || defaultTableStyleDef();
    return {
      title: '',
      headers: ['Column 1', 'Column 2'],
      rows: [['', '']],
      col_widths: [140, 140],
      border_width: def.border_width || 'medium',
      border_color: def.border_color || '#94a3b8',
      cell_bg: def.cell_bg || '#ffffff',
      table_style_kind: kind,
    };
  }

  function getActiveStyleTarget() {
    var ae = document.activeElement;
    if (toolbarEl && ae && toolbarEl.contains(ae) && state.lastStyleTarget) {
      return state.lastStyleTarget;
    }
    return getActiveStyleTargetFromEditor();
  }

  function getFocusedBlock() {
    var target = getActiveStyleTargetFromEditor();
    if (target && target.block) return target.block;
    if (state.lastStyleTarget && state.lastStyleTarget.block) return state.lastStyleTarget.block;
    var ae = document.activeElement;
    if (ae && ae.closest) {
      var block = ae.closest('.cpb-block');
      if (block) return block;
    }
    return null;
  }

  function hasTextSelectionInCanvas() {
    var sel = window.getSelection();
    return !!(sel && sel.rangeCount > 0 && !sel.isCollapsed && selectionInCanvas());
  }

  function applyInlineStyleToSelection(styles) {
    var sel = window.getSelection();
    if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return false;
    var range = sel.getRangeAt(0);
    if (!canvasEl.contains(range.commonAncestorContainer)) return false;
    var span = document.createElement('span');
    Object.keys(styles).forEach(function (key) {
      if (styles[key]) span.style[key] = styles[key];
    });
    try {
      range.surroundContents(span);
    } catch (err) {
      var extracted = range.extractContents();
      span.appendChild(extracted);
      range.insertNode(span);
    }
    sel.removeAllRanges();
    var next = document.createRange();
    next.selectNodeContents(span);
    sel.addRange(next);
    return true;
  }

  function applyIndentToElement(el, level) {
    level = Math.max(0, Math.min(INDENT_MAX_LEVEL, level));
    el.setAttribute('data-indent-level', String(level));
    if (level > 0) {
      el.style.marginLeft = (level * INDENT_STEP_PX) + 'px';
    } else {
      el.style.marginLeft = '';
    }
  }

  function applyIndentDelta(delta) {
    var target = getActiveStyleTarget();
    if (!target || target.type === 'table-cell') return;
    if (target.type !== 'heading' && target.type !== 'paragraph' && target.type !== 'list') return;
    pushUndo();
    if (target.type === 'heading') {
      var level = parseInt(target.el.getAttribute('data-indent-level') || '0', 10) || 0;
      applyIndentToElement(target.el, level + delta);
    } else {
      target.el.focus();
      document.execCommand(delta > 0 ? 'indent' : 'outdent', false, null);
    }
    scheduleSave(target.block);
    flushSave(target.block);
  }

  function applyCanvasZoom(pct, persist) {
    state.canvasZoom = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, pct));
    var sheet = canvasEl.querySelector('.cpb-sheet');
    if (sheet) {
      sheet.style.setProperty('--cpb-sheet-zoom', String(state.canvasZoom / 100));
    }
    if (zoomLabelEl) zoomLabelEl.textContent = state.canvasZoom + '%';
    if (persist !== false) {
      try {
        sessionStorage.setItem('cpb_canvas_zoom', String(state.canvasZoom));
      } catch (err) { /* ignore */ }
    }
  }

  function applyTypographyToElement(el, typo, paragraphStyle) {
    FONT_CLASSES.forEach(function (cls) { el.classList.remove(cls); });
    PARAGRAPH_STYLE_CLASSES.forEach(function (cls) { el.classList.remove(cls); });
    el.classList.add('cpb-font-' + typo.font_family);
    if (paragraphStyle) {
      el.classList.add('cpb-ps-' + paragraphStyle);
      el.setAttribute('data-paragraph-style', paragraphStyle);
    }
    el.setAttribute('data-font-family', typo.font_family);
    el.setAttribute('data-font-size', String(typo.font_size));
    el.setAttribute('data-text-color', typo.color);
    el.style.fontFamily = FONT_STACKS[typo.font_family] || FONT_STACKS.serif;
    el.style.fontSize = typo.font_size + 'pt';
    el.style.color = typo.color;
  }

  function applyParagraphStyle(styleKey) {
    var target = getActiveStyleTarget();
    if (!target || target.type === 'table-cell') return;
    if (target.type !== 'heading' && target.type !== 'paragraph' && target.type !== 'list') return;
    pushUndo();
    var styles = state.bookStyles || defaultBookStyles();
    var def = (styles.paragraph_styles && styles.paragraph_styles[styleKey]) || styles.paragraph_styles.body;
    var typo = {
      font_family: def.font_family || 'serif',
      font_size: def.font_size || 11,
      color: def.color || '#0f172a',
    };
    applyTypographyToElement(target.el, typo, styleKey);
    if (fontSelect) fontSelect.value = typo.font_family;
    if (fontSizeSelect) fontSizeSelect.value = String(typo.font_size);
    if (textColorInput) textColorInput.value = typo.color;
    if (paragraphStyleSelect) paragraphStyleSelect.value = styleKey;
    var blockId = parseInt(target.block.getAttribute('data-block-id') || '0', 10);
    updateRegulatoryRefFieldVisibility(styleKey, target.el, blockId);
    if (styleKey === 'regulatory_reference') {
      target.el.setAttribute('data-regulatory-ref', '');
      if (regulatoryRefInput) regulatoryRefInput.value = '';
    }
    scheduleSave(target.block);
    flushSave(target.block, styleNeedsNumberingRefresh(styleKey));
  }

  function applyFontFamily(font) {
    var target = getActiveStyleTarget();
    if (!target) return;
    pushUndo();
    if (target.type === 'table-cell') {
      applyStyleToTableCell(target.el, { font: font });
    } else if (target.type === 'heading' || target.type === 'paragraph' || target.type === 'list') {
      var stack = FONT_STACKS[font];
      target.el.focus();
      if (!hasTextSelectionInCanvas() || !applyInlineStyleToSelection({ fontFamily: stack || '' })) {
        FONT_CLASSES.forEach(function (cls) { target.el.classList.remove(cls); });
        target.el.classList.add('cpb-font-' + font);
        target.el.setAttribute('data-font-family', font);
        if (stack) target.el.style.fontFamily = stack;
      }
    }
    scheduleSave(target.block);
    flushSave(target.block);
  }

  function applyFontSizeToElement(el, size, updateSelect) {
    el.style.fontSize = size + 'pt';
    el.setAttribute('data-font-size', String(size));
    if (updateSelect && fontSizeSelect) fontSizeSelect.value = String(size);
  }

  function applyFontSize(size) {
    var target = getActiveStyleTarget();
    if (!target) return;
    pushUndo();
    if (target.type === 'table-cell') {
      applyStyleToTableCell(target.el, { size: size });
    } else if (target.type === 'heading' || target.type === 'paragraph' || target.type === 'list') {
      target.el.focus();
      if (!hasTextSelectionInCanvas() || !applyInlineStyleToSelection({ fontSize: size + 'pt' })) {
        applyFontSizeToElement(target.el, size, true);
      } else if (fontSizeSelect) {
        fontSizeSelect.value = String(size);
      }
    }
    scheduleSave(target.block);
    flushSave(target.block);
  }

  function applyTextAlign(align) {
    var target = getActiveStyleTarget();
    if (!target) return;
    pushUndo();
    if (target.type === 'table-cell') {
      applyStyleToTableCell(target.el, { align: align });
    } else if (target.type === 'heading' || target.type === 'paragraph' || target.type === 'list') {
      ALIGN_CLASSES.forEach(function (cls) { target.el.classList.remove(cls); });
      target.el.classList.add('cpb-align-' + align);
      target.el.setAttribute('data-text-align', align);
      target.el.style.textAlign = align;
    }
    scheduleSave(target.block);
    flushSave(target.block);
  }

  function createBlock(blockType, payload, insertAfterBlockEl) {
    pushUndo();
    setStatus('Adding block…', 'saving');
    var afterId = insertAfterBlockEl
      ? parseInt(insertAfterBlockEl.getAttribute('data-block-id') || '0', 10)
      : 0;
    var focusedBlock = !insertAfterBlockEl ? getFocusedBlock() : null;
    if (!afterId && focusedBlock) {
      afterId = parseInt(focusedBlock.getAttribute('data-block-id') || '0', 10);
    }
    var req = {
      version_id: state.versionId,
      section_id: state.sectionId,
      block_type: blockType,
      payload: payload || {},
    };
    if (afterId > 0) req.insert_after_block_id = afterId;
    return apiPost('create_block', req).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Create failed');
      var anchor = insertAfterBlockEl || focusedBlock;
      if (anchor && res.block_html) {
        anchor.insertAdjacentHTML('afterend', res.block_html);
        wireCanvas();
        var newBlock = anchor.nextElementSibling;
        if (newBlock && newBlock.classList.contains('cpb-block')) {
          var field = newBlock.querySelector('[contenteditable="true"]');
          if (field) field.focus();
        }
      } else {
        var body = canvasEl.querySelector('[data-blocks-root]');
        if (body && res.block_html) {
          body.insertAdjacentHTML('beforeend', res.block_html);
          wireCanvas();
          var lastBlock = body.querySelector('.cpb-block:last-child');
          if (lastBlock) {
            var field2 = lastBlock.querySelector('[contenteditable="true"]');
            if (field2) field2.focus();
          }
        }
      }
      setStatus('Added', 'saved');
      applyNumberingState(res);
      if (blockType === 'paragraph' || blockType === 'heading') {
        return recomputeSectionNumbers().then(function () { return res; });
      }
      return res;
    });
  }

  function insertCallout(type) {
    var preset = presetByType(type);
    if (!preset) {
      preset = {
        callout_type: type,
        title: type === 'caution' ? 'CAUTION' : (type === 'info' ? 'INFO' : 'WARNING'),
        text: '',
      };
    }
    createBlock('callout', {
      callout_type: type,
      title: preset.title || (type === 'caution' ? 'CAUTION' : (type === 'info' ? 'INFO' : 'WARNING')),
      text: preset.text || 'Enter callout text…',
    }).catch(showError);
  }

  function presetByType(type) {
    for (var i = 0; i < state.calloutPresets.length; i++) {
      if (state.calloutPresets[i].callout_type === type) return state.calloutPresets[i];
    }
    return null;
  }

  function refreshCalloutSelectOptions() {
    if (!calloutSelect) return;
    var presets = state.calloutPresets && state.calloutPresets.length
      ? state.calloutPresets
      : defaultBookStyles().callout_presets;
    var labels = { warning: 'Warning', caution: 'Caution', info: 'Info' };
    var html = '<option value="">⚑</option>';
    presets.forEach(function (preset) {
      var type = preset.callout_type || '';
      if (!type || type === 'manage') return;
      var label = labels[type] || (type.charAt(0).toUpperCase() + type.slice(1));
      html += '<option value="' + escapeAttr(type) + '">' + escapeHtml(label) + '</option>';
    });
    html += '<option value="manage">Presets…</option>';
    calloutSelect.innerHTML = html;
  }

  function openCalloutManager() {
    var warning = presetByType('warning') || { callout_type: 'warning', title: 'WARNING', text: '' };
    var caution = presetByType('caution') || { callout_type: 'caution', title: 'CAUTION', text: '' };
    var info = presetByType('info') || { callout_type: 'info', title: 'INFO', text: '' };

    var overlay = document.createElement('div');
    overlay.className = 'cpb-callout-overlay';
    overlay.innerHTML = ''
      + '<div class="cpb-callout-dialog" role="dialog" aria-label="Manage callout presets">'
      + '<h3>Callout presets</h3>'
      + '<p style="margin:0 0 12px;font-size:13px;color:#64748b;">Default title and text used when inserting Warning, Caution, or Info blocks.</p>'
      + '<div class="cpb-callout-field"><label>Warning title</label>'
      + '<input type="text" id="cpbPresetWarnTitle" value="' + escapeAttr(warning.title) + '"></div>'
      + '<div class="cpb-callout-field"><label>Warning default text</label>'
      + '<textarea id="cpbPresetWarnText">' + escapeHtml(warning.text) + '</textarea></div>'
      + '<div class="cpb-callout-field"><label>Caution title</label>'
      + '<input type="text" id="cpbPresetCautionTitle" value="' + escapeAttr(caution.title) + '"></div>'
      + '<div class="cpb-callout-field"><label>Caution default text</label>'
      + '<textarea id="cpbPresetCautionText">' + escapeHtml(caution.text) + '</textarea></div>'
      + '<div class="cpb-callout-field"><label>Info title</label>'
      + '<input type="text" id="cpbPresetInfoTitle" value="' + escapeAttr(info.title) + '"></div>'
      + '<div class="cpb-callout-field"><label>Info default text</label>'
      + '<textarea id="cpbPresetInfoText">' + escapeHtml(info.text) + '</textarea></div>'
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
        {
          callout_type: 'info',
          title: overlay.querySelector('#cpbPresetInfoTitle').value.trim() || 'INFO',
          text: overlay.querySelector('#cpbPresetInfoText').value.trim(),
        },
      ];
      apiPost('save_callout_presets', {
        version_id: state.versionId,
        presets: presets,
      }).then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Save failed');
        state.calloutPresets = res.presets || presets;
        refreshCalloutSelectOptions();
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

  function styleEditorFontOptions(selected) {
    var html = '';
    ['serif', 'sans', 'arial', 'mono'].forEach(function (font) {
      html += '<option value="' + font + '"' + (font === selected ? ' selected' : '') + '>'
        + (font === 'serif' ? 'Serif' : font.charAt(0).toUpperCase() + font.slice(1)) + '</option>';
    });
    return html;
  }

  function styleEditorBorderOptions(selected) {
    return ['thin', 'medium', 'thick'].map(function (w) {
      return '<option value="' + w + '"' + (w === selected ? ' selected' : '') + '>' + w + '</option>';
    }).join('');
  }

  function openStyleEditor() {
    var styles = JSON.parse(JSON.stringify(state.bookStyles || defaultBookStyles()));
    var overlay = document.createElement('div');
    overlay.className = 'cpb-style-overlay';

    var paragraphRows = PARAGRAPH_STYLE_KEYS.map(function (key) {
      var def = styles.paragraph_styles[key] || {};
      var sample = PARAGRAPH_STYLE_LABELS[key] || key;
      return ''
        + '<tr data-ps-row="' + key + '">'
        + '<td class="cpb-style-name">' + escapeHtml(sample) + '</td>'
        + '<td><select class="cpb-style-input" data-ps-field="font_family">' + styleEditorFontOptions(def.font_family || 'serif') + '</select></td>'
        + '<td><input class="cpb-style-input cpb-style-input--num" type="number" min="8" max="32" data-ps-field="font_size" value="' + (def.font_size || 11) + '"></td>'
        + '<td><input class="cpb-style-input cpb-style-input--color" type="color" data-ps-field="color" value="' + escapeAttr(def.color || '#0f172a') + '"></td>'
        + '<td><span class="cpb-style-sample" data-ps-sample="' + key + '" style="font-family:' + escapeAttr(FONT_STACKS[def.font_family] || FONT_STACKS.serif) + ';font-size:' + (def.font_size || 11) + 'pt;color:' + escapeAttr(def.color || '#0f172a') + '">' + escapeHtml(sample) + '</span></td>'
        + '</tr>';
    }).join('');

    function tableSection(kind, label) {
      var t = styles.table_styles[kind] || defaultTableStyleDef();
      function rowField(rowKey, rowLabel) {
        var row = t[rowKey] || {};
        return ''
          + '<div class="cpb-style-table-row">'
          + '<div class="cpb-style-table-row-label">' + escapeHtml(rowLabel) + '</div>'
          + '<select class="cpb-style-input" data-table-kind="' + kind + '" data-table-field="' + rowKey + '.font_family">' + styleEditorFontOptions(row.font_family || 'serif') + '</select>'
          + '<input class="cpb-style-input cpb-style-input--num" type="number" min="8" max="32" data-table-kind="' + kind + '" data-table-field="' + rowKey + '.font_size" value="' + (row.font_size || 10) + '">'
          + '<input class="cpb-style-input cpb-style-input--color" type="color" data-table-kind="' + kind + '" data-table-field="' + rowKey + '.color" value="' + escapeAttr(row.color || '#0f172a') + '">'
          + '<input class="cpb-style-input cpb-style-input--color" type="color" data-table-kind="' + kind + '" data-table-field="' + rowKey + '.bg" value="' + escapeAttr(row.bg || '#ffffff') + '" title="Background">'
          + '</div>';
      }
      return ''
        + '<section class="cpb-style-section"><h4>' + escapeHtml(label) + '</h4>'
        + '<div class="cpb-style-table-grid">'
        + '<label>Border <select class="cpb-style-input" data-table-kind="' + kind + '" data-table-field="border_width">' + styleEditorBorderOptions(t.border_width || 'medium') + '</select></label>'
        + '<label>Border color <input class="cpb-style-input cpb-style-input--color" type="color" data-table-kind="' + kind + '" data-table-field="border_color" value="' + escapeAttr(t.border_color || '#94a3b8') + '"></label>'
        + '<label>Default cell fill <input class="cpb-style-input cpb-style-input--color" type="color" data-table-kind="' + kind + '" data-table-field="cell_bg" value="' + escapeAttr(t.cell_bg || '#ffffff') + '"></label>'
        + '</div>'
        + rowField('title_row', 'Title row')
        + rowField('header_row', 'Header row')
        + rowField('body_row', 'Body rows')
        + '</section>';
    }

    overlay.innerHTML = ''
      + '<div class="cpb-style-dialog" role="dialog" aria-label="Book style editor">'
      + '<h3>Book style editor</h3>'
      + '<p class="cpb-style-lead">Paragraph styles drive the Table of Contents and automatic section numbering (1. / 1.1 / 1.1.1). '
      + 'Regulatory Reference blocks show an MCCF cross-reference — auto-derived from the parent section number or entered manually in the toolbar.</p>'
      + '<section class="cpb-style-section"><h4>Paragraph styles</h4>'
      + '<table class="cpb-style-table"><thead><tr><th>Style</th><th>Font</th><th>Size</th><th>Color</th><th>Sample</th></tr></thead><tbody>'
      + paragraphRows + '</tbody></table></section>'
      + tableSection('standard', 'Standard tables')
      + tableSection('text', 'Text tables')
      + '<div class="cpb-style-dialog-actions">'
      + '<button type="button" class="cpb-style-cancel">Cancel</button>'
      + '<button type="button" class="cpb-style-save">Save styles</button>'
      + '</div></div>';

    function readStylesFromDialog() {
      var next = defaultBookStyles();
      PARAGRAPH_STYLE_KEYS.forEach(function (key) {
        var row = overlay.querySelector('[data-ps-row="' + key + '"]');
        if (!row) return;
        next.paragraph_styles[key] = {
          font_family: row.querySelector('[data-ps-field="font_family"]').value,
          font_size: parseInt(row.querySelector('[data-ps-field="font_size"]').value, 10) || 11,
          color: row.querySelector('[data-ps-field="color"]').value,
        };
      });
      ['standard', 'text'].forEach(function (kind) {
        var base = next.table_styles[kind];
        overlay.querySelectorAll('[data-table-kind="' + kind + '"]').forEach(function (input) {
          var field = input.getAttribute('data-table-field');
          if (!field) return;
          if (field.indexOf('.') > -1) {
            var parts = field.split('.');
            base[parts[0]][parts[1]] = input.value;
          } else {
            base[field] = input.value;
          }
        });
      });
      next.callout_presets = (state.bookStyles && state.bookStyles.callout_presets) || defaultBookStyles().callout_presets;
      return next;
    }

    overlay.addEventListener('input', function (e) {
      var row = e.target.closest('[data-ps-row]');
      if (!row) return;
      var key = row.getAttribute('data-ps-row');
      var sample = row.querySelector('[data-ps-sample="' + key + '"]');
      if (!sample) return;
      var font = row.querySelector('[data-ps-field="font_family"]').value;
      var size = row.querySelector('[data-ps-field="font_size"]').value;
      var color = row.querySelector('[data-ps-field="color"]').value;
      sample.style.fontFamily = FONT_STACKS[font] || FONT_STACKS.serif;
      sample.style.fontSize = size + 'pt';
      sample.style.color = color;
    });

    function close() { overlay.remove(); }
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    overlay.querySelector('.cpb-style-cancel').addEventListener('click', close);
    overlay.querySelector('.cpb-style-save').addEventListener('click', function () {
      var payload = readStylesFromDialog();
      apiPost('save_book_styles', { version_id: state.versionId, book_styles: payload })
        .then(function (res) {
          if (!res.ok) throw new Error(res.error || 'Save failed');
          state.bookStyles = res.book_styles || payload;
          state.calloutPresets = state.bookStyles.callout_presets || state.calloutPresets;
          close();
          setStatus('Book styles saved', 'saved');
          return loadSection(state.sectionId);
        })
        .catch(showError);
    });
    document.body.appendChild(overlay);
  }

  function syncToc() {
    if (!confirm('Regenerate the Table of Contents from Title / Subtitle / Heading paragraph styles?')) return;
    setStatus('Syncing TOC…', 'saving');
    apiPost('regenerate_toc', { version_id: state.versionId })
      .then(function (res) {
        if (!res.ok) throw new Error(res.error || 'TOC sync failed');
        var count = res.result && res.result.entries_count !== undefined ? res.result.entries_count : 0;
        setStatus('TOC updated (' + count + ' entries)', 'saved');
        var tocId = findTocSectionId(state.sectionsTree);
        if (tocId) loadSection(tocId);
      })
      .catch(showError);
  }

  function findTocSectionId(nodes) {
    for (var i = 0; i < nodes.length; i++) {
      if (nodes[i].section_key === 'toc') return nodes[i].id;
      if (nodes[i].children && nodes[i].children.length) {
        var found = findTocSectionId(nodes[i].children);
        if (found) return found;
      }
    }
    return null;
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
      var add = e.target.closest('[data-add-block]');
      if (add) {
        e.preventDefault();
        var type = add.getAttribute('data-add-block');
        var payload = {};
        if (type === 'paragraph') payload = { html: '<p>New paragraph</p>', paragraph_style: 'body' };
        if (type === 'table') {
          payload = tablePayloadFromBookStyles('standard');
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
      if (e.target.closest('#cpbRedo')) {
        e.preventDefault();
        doRedo();
      }
    });
  }

  document.addEventListener('selectionchange', function () {
    if (selectionInCanvas()) rememberStyleTarget();
  });

  if (paragraphStyleSelect) {
    paragraphStyleSelect.addEventListener('focus', rememberStyleTarget);
    paragraphStyleSelect.addEventListener('change', function () {
      applyParagraphStyle(paragraphStyleSelect.value);
    });
  }

  if (regulatoryRefInput) {
    regulatoryRefInput.addEventListener('focus', rememberStyleTarget);
    regulatoryRefInput.addEventListener('change', function () {
      var target = getActiveStyleTarget();
      if (!target || target.type === 'table-cell') return;
      if (target.type !== 'heading' && target.type !== 'paragraph' && target.type !== 'list') return;
      pushUndo();
      var value = regulatoryRefInput.value.trim();
      if (value) {
        target.el.setAttribute('data-regulatory-ref', value);
      } else {
        target.el.removeAttribute('data-regulatory-ref');
      }
      scheduleSave(target.block);
      flushSave(target.block, true);
    });
  }

  if (fontSelect) {
    fontSelect.addEventListener('focus', rememberStyleTarget);
    fontSelect.addEventListener('change', function () {
      applyFontFamily(fontSelect.value);
    });
  }

  if (fontSizeSelect) {
    fontSizeSelect.addEventListener('focus', rememberStyleTarget);
    fontSizeSelect.addEventListener('change', function () {
      applyFontSize(parseInt(fontSizeSelect.value, 10) || 11);
    });
  }

  if (textColorInput) {
    textColorInput.addEventListener('focus', rememberStyleTarget);
    textColorInput.addEventListener('input', function () {
      var target = getActiveStyleTarget();
      var color = textColorInput.value;
      if (target && target.type !== 'table-cell'
        && (target.type === 'heading' || target.type === 'paragraph' || target.type === 'list')) {
        pushUndo();
        target.el.focus();
        if (!hasTextSelectionInCanvas() || !applyInlineStyleToSelection({ color: color })) {
          target.el.style.color = color;
          target.el.setAttribute('data-text-color', color);
        }
        scheduleSave(target.block);
        flushSave(target.block);
        return;
      }
      execFormat('foreColor', color);
    });
  }

  if (zoomInBtn) {
    zoomInBtn.addEventListener('click', function (e) {
      e.preventDefault();
      applyCanvasZoom(state.canvasZoom + ZOOM_STEP);
    });
  }

  if (zoomOutBtn) {
    zoomOutBtn.addEventListener('click', function (e) {
      e.preventDefault();
      applyCanvasZoom(state.canvasZoom - ZOOM_STEP);
    });
  }

  if (indentBtn) {
    indentBtn.addEventListener('click', function (e) {
      e.preventDefault();
      applyIndentDelta(1);
    });
  }

  if (outdentBtn) {
    outdentBtn.addEventListener('click', function (e) {
      e.preventDefault();
      applyIndentDelta(-1);
    });
  }

  try {
    var savedZoom = parseInt(sessionStorage.getItem('cpb_canvas_zoom') || '100', 10);
    if (!isNaN(savedZoom)) applyCanvasZoom(savedZoom, false);
  } catch (err) { /* ignore */ }

  if (undoBtn) {
    undoBtn.addEventListener('click', function (e) {
      e.preventDefault();
      doUndo();
    });
  }

  if (redoBtn) {
    redoBtn.addEventListener('click', function (e) {
      e.preventDefault();
      doRedo();
    });
  }

  if (calloutSelect) {
    calloutSelect.addEventListener('change', function () {
      var action = calloutSelect.value;
      calloutSelect.value = '';
      if (!action) return;
      if (action === 'manage') {
        openCalloutManager();
        return;
      }
      insertCallout(action);
    });
  }

  if (syncSelect) {
    syncSelect.addEventListener('change', function () {
      var action = syncSelect.value;
      syncSelect.value = '';
      if (action === 'toc') syncToc();
      else if (action === 'highlights') syncHighlights();
    });
  }

  if (openStyleEditorBtn) {
    openStyleEditorBtn.addEventListener('click', function (e) {
      e.preventDefault();
      openStyleEditor();
    });
  }

  function isBrowserFullscreen() {
    return document.body.classList.contains('cpb-browser-fullscreen');
  }

  function setBrowserFullscreen(on) {
    document.body.classList.toggle('cpb-browser-fullscreen', on);
    if (fullscreenBtn) {
      fullscreenBtn.classList.toggle('is-active', on);
      fullscreenBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
      fullscreenBtn.title = on ? 'Exit full screen (Esc)' : 'Full screen — hide app menu';
      fullscreenBtn.textContent = on ? '⤡' : '⤢';
    }
    try {
      sessionStorage.setItem('cpb_browser_fullscreen', on ? '1' : '0');
    } catch (err) { /* ignore */ }
  }

  if (fullscreenBtn) {
    fullscreenBtn.addEventListener('click', function (e) {
      e.preventDefault();
      setBrowserFullscreen(!isBrowserFullscreen());
    });
    try {
      if (sessionStorage.getItem('cpb_browser_fullscreen') === '1') {
        setBrowserFullscreen(true);
      }
    } catch (err) { /* ignore */ }
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isBrowserFullscreen()) {
      setBrowserFullscreen(false);
      return;
    }
    if (e.key === 'Tab' && root.contains(document.activeElement)) {
      var indentTarget = getActiveStyleTarget();
      if (indentTarget && indentTarget.type !== 'table-cell') {
        e.preventDefault();
        applyIndentDelta(e.shiftKey ? -1 : 1);
        return;
      }
    }
    var inEditor = root.contains(document.activeElement);
    if (!inEditor) return;
    if ((e.ctrlKey || e.metaKey) && e.key === 'z' && e.shiftKey) {
      e.preventDefault();
      doRedo();
    } else if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
      e.preventDefault();
      doUndo();
    } else if ((e.ctrlKey || e.metaKey) && e.key === 'y') {
      e.preventDefault();
      doRedo();
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
