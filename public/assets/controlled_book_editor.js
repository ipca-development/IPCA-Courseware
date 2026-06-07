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
  };

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
    if (!state.editable) return;
    var layout = extractLayout();
    apiPost('save_section_layout', {
      version_id: state.versionId,
      section_id: state.sectionId,
      layout: layout,
    }).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Layout save failed');
      state.pageLayout = res.layout || layout;
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
          return loadSection(state.sectionId);
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

  function wireCanvas() {
    canvasEl.querySelectorAll('.cpb-block').forEach(function (blockEl) {
      blockEl.querySelectorAll('[contenteditable="true"]').forEach(function (field) {
        field.addEventListener('input', function () {
          scheduleSave(blockEl);
        });
        field.addEventListener('blur', function () {
          flushSave(blockEl);
        });
      });
    });

    canvasEl.querySelectorAll('.cpb-block-chrome [data-action]').forEach(function (btn) {
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

    canvasEl.querySelectorAll('[data-table-action]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var blockEl = btn.closest('.cpb-block');
        if (!blockEl) return;
        pushUndo();
        var action = btn.getAttribute('data-table-action');
        if (action === 'add-row') {
          tableAddRow(blockEl);
        } else if (action === 'del-row') {
          tableDelRow(blockEl);
        } else if (action === 'add-col') {
          tableAddColumn(blockEl);
        } else if (action === 'del-col') {
          tableDelColumn(blockEl);
        } else if (action === 'toggle-title') {
          tableToggleTitle(blockEl);
        }
        scheduleSave(blockEl);
        flushSave(blockEl);
      });
    });

    canvasEl.querySelectorAll('.cpb-block--table').forEach(function (blockEl) {
      wireTableResize(blockEl);
    });

    wireLayout();

    var dropzone = canvasEl.querySelector('[data-dropzone="image"]');
    if (dropzone && state.editable) {
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

  function extractTablePayload(blockEl) {
    var table = blockEl.querySelector('table');
    var titleCell = blockEl.querySelector('[data-title-row] td');
    var title = titleCell ? titleCell.textContent.trim() : '';
    var headers = [];
    var rows = [];
    var colWidths = [];

    if (table) {
      table.querySelectorAll('thead th').forEach(function (th) {
        var textEl = th.querySelector('.cpb-th-text');
        headers.push((textEl ? textEl.textContent : th.textContent).trim());
      });
      table.querySelectorAll('tbody[data-table-part="body"] tr').forEach(function (tr) {
        var line = [];
        tr.querySelectorAll('td').forEach(function (td) {
          line.push(td.textContent.trim());
        });
        if (line.length) rows.push(line);
      });
      table.querySelectorAll('colgroup col').forEach(function (col) {
        var w = parseInt((col.style.width || '140').replace('px', ''), 10);
        colWidths.push(isNaN(w) ? 140 : w);
      });
    }

    return {
      title: title,
      has_title_row: !!blockEl.querySelector('[data-title-row]'),
      headers: headers,
      rows: rows,
      col_widths: colWidths,
    };
  }

  function tableBody(blockEl) {
    var table = blockEl.querySelector('table');
    return table ? table.querySelector('tbody[data-table-part="body"]') : null;
  }

  function tableColCount(blockEl) {
    var table = blockEl.querySelector('table');
    if (!table) return 2;
    var head = table.querySelector('thead tr');
    return head ? head.cells.length : 2;
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
  }

  function tableDelRow(blockEl) {
    var tbody = tableBody(blockEl);
    if (!tbody) return;
    var rows = tbody.querySelectorAll('tr');
    if (rows.length <= 1) return;
    rows[rows.length - 1].remove();
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

    var titleCell = blockEl.querySelector('[data-title-row] td');
    if (titleCell) {
      titleCell.colSpan = cols + 1;
    }

    var headRow = table.querySelector('thead tr');
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

    var headRow = table.querySelector('thead tr');
    if (headRow && headRow.cells[colIndex]) {
      headRow.cells[colIndex].remove();
    }

    table.querySelectorAll('tbody[data-table-part="body"] tr').forEach(function (tr) {
      if (tr.cells[colIndex]) tr.cells[colIndex].remove();
    });

    var titleCell = blockEl.querySelector('[data-title-row] td');
    if (titleCell) {
      titleCell.colSpan = cols - 1;
    }
  }

  function tableToggleTitle(blockEl) {
    var table = blockEl.querySelector('table');
    if (!table) return;
    var existing = table.querySelector('[data-title-row]');
    var toggleBtn = blockEl.querySelector('[data-table-action="toggle-title"]');
    if (existing) {
      var tbody = existing.closest('tbody');
      if (tbody) tbody.remove();
      if (toggleBtn) toggleBtn.textContent = '+ Title row';
      return;
    }
    var cols = tableColCount(blockEl);
    var tbody = document.createElement('tbody');
    tbody.setAttribute('data-table-part', 'title');
    var tr = document.createElement('tr');
    tr.className = 'cpb-table-title-row is-empty';
    tr.setAttribute('data-title-row', '1');
    var td = document.createElement('td');
    td.colSpan = cols;
    td.contentEditable = 'true';
    td.setAttribute('data-placeholder', 'Table title (spans all columns)');
    tr.appendChild(td);
    tbody.appendChild(tr);
    table.insertBefore(tbody, table.querySelector('thead'));
    if (toggleBtn) toggleBtn.textContent = 'Remove title row';
    td.focus();
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

        function onMove(ev) {
          var w = Math.max(60, Math.min(600, startW + (ev.clientX - startX)));
          if (col) col.style.width = w + 'px';
        }

        function onUp() {
          document.removeEventListener('mousemove', onMove);
          document.removeEventListener('mouseup', onUp);
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
