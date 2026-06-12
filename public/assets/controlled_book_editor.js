(function () {
  'use strict';

  var root = document.getElementById('cpbEditorRoot');
  if (!root) return;

  var versionId = parseInt(root.getAttribute('data-version-id') || '0', 10);
  var initialSectionId = parseInt(root.getAttribute('data-section-id') || '0', 10);
  var apiBase = '/admin/api/controlled_book_editor_api.php';

  var treeEl = document.getElementById('cpbSectionTree');
  var treeToggleAllBtn = document.getElementById('cpbTreeToggleAll');
  var canvasEl = document.getElementById('cpbCanvas');
  var toolbarEl = document.getElementById('cpbToolbar');
  var toolbarMainEl = document.getElementById('cpbToolbarMain');
  var toolbarTocEl = document.getElementById('cpbToolbarToc');
  var toolbarLepEl = document.getElementById('cpbToolbarLep');
  var toolbarPart0El = document.getElementById('cpbToolbarPart0');
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
  var openHeaderEditorBtn = document.getElementById('cpbOpenHeaderEditor');
  var headerLogoInput = document.getElementById('cpbHeaderLogoInput');
  var coverLogoInput = document.getElementById('cpbCoverLogoInput');
  var coverImageInput = document.getElementById('cpbCoverImageInput');
  var calloutSelect = document.getElementById('cpbCalloutSelect');
  var detectSelect = document.getElementById('cpbDetectSelect');
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
    'cpb-ps-title', 'cpb-ps-subtitle_1', 'cpb-ps-subtitle_2', 'cpb-ps-subtitle_3', 'cpb-ps-subtitle_4',
    'cpb-ps-regulatory_reference', 'cpb-ps-body', 'cpb-ps-caption',
    'cpb-ps-heading_1', 'cpb-ps-heading_2',
  ];
  var PARAGRAPH_STYLE_KEYS = [
    'title', 'subtitle_1', 'subtitle_2', 'subtitle_3', 'subtitle_4',
    'regulatory_reference', 'body', 'caption',
  ];
  var LEGACY_PARAGRAPH_STYLE_ALIASES = {
    heading_1: 'subtitle_2',
    heading_2: 'subtitle_3',
  };
  var NUMBERED_PARAGRAPH_STYLES = {
    title: 1,
    subtitle_1: 2,
    subtitle_2: 3,
    subtitle_3: 4,
    subtitle_4: 5,
  };
  var PARAGRAPH_STYLE_LABELS = {
    title: 'Title',
    subtitle_1: 'Subtitle 1',
    subtitle_2: 'Subtitle 2',
    subtitle_3: 'Subtitle 3',
    subtitle_4: 'Subtitle 4',
    regulatory_reference: 'Regulatory Reference',
    body: 'Body',
    caption: 'Caption',
  };

  function canonicalParagraphStyleKey(styleKey) {
    styleKey = String(styleKey || '').toLowerCase();
    return LEGACY_PARAGRAPH_STYLE_ALIASES[styleKey] || styleKey;
  }
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
    pageHeader: null,
    pageFooter: null,
    headerTokens: [],
    headerPreviewTokens: {},
    pageHeaderScope: 'main',
    versionInfo: {},
    sectionTitle: '',
    calloutPresets: [],
    bookStyles: null,
    sectionNumberDisplay: {},
    suggestedRegulatoryRefs: {},
    manualCode: '',
    undoStack: [],
    redoStack: [],
    layoutTimer: null,
    focusedTableCell: null,
    pendingScrollRef: null,
    canvasEventsWired: false,
    resizeHintEl: null,
    tableClipboard: '',
    canvasZoom: 100,
    lastStyleTarget: null,
    savedSelectionRange: null,
    isCoverSection: false,
    coverPage: null,
    coverSaveTimer: null,
    coverDropTarget: null,
    isTocSection: false,
    tocSettings: null,
    tocSettingsCatalog: [],
    tocNavWired: false,
    isLepSection: false,
    lepPage: null,
    lepApprovalUrl: '',
    lepSaveTimer: null,
    lepSignModal: null,
    lepSignSlotKey: '',
    isPart0Section: false,
    part0SectionKey: '',
    part0Structured: false,
    part0Page: null,
    part0SaveTimer: null,
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

  function isConnectedEl(el) {
    return !!(el && document.body && document.body.contains(el));
  }

  function isLiveStyleTarget(target) {
    return !!(target && target.block && isConnectedEl(target.block)
      && target.el && isConnectedEl(target.el));
  }

  function clearStyleTargetForBlock(blockEl) {
    if (state.lastStyleTarget && state.lastStyleTarget.block === blockEl) {
      state.lastStyleTarget = null;
    }
    if (state.focusedTableCell && blockEl.contains(state.focusedTableCell)) {
      state.focusedTableCell = null;
    }
  }

  function isRichTextStyleTarget(target) {
    if (!target) return false;
    return target.type === 'heading' || target.type === 'paragraph' || target.type === 'list'
      || target.type === 'callout-title' || target.type === 'callout-text';
  }

  function isBlockTypographyTarget(target) {
    return !!(target && (target.type === 'heading' || target.type === 'paragraph' || target.type === 'list'));
  }

  function saveSelectionRange() {
    var sel = window.getSelection();
    if (!sel || sel.rangeCount === 0 || sel.isCollapsed || !selectionInCanvas()) {
      state.savedSelectionRange = null;
      return;
    }
    state.savedSelectionRange = sel.getRangeAt(0).cloneRange();
  }

  function restoreSelectionRange() {
    if (!state.savedSelectionRange) return false;
    var sel = window.getSelection();
    if (!sel) return false;
    try {
      sel.removeAllRanges();
      sel.addRange(state.savedSelectionRange);
      return !sel.isCollapsed;
    } catch (err) {
      state.savedSelectionRange = null;
      return false;
    }
  }

  function clearPendingForBlock(blockId) {
    if (state.pending[blockId]) {
      delete state.pending[blockId];
    }
  }

  function blurCanvasEditing() {
    var ae = document.activeElement;
    if (ae && ae.blur && canvasEl.contains(ae)) {
      ae.blur();
    }
    var sel = window.getSelection();
    if (sel && sel.removeAllRanges) {
      sel.removeAllRanges();
    }
  }

  function applyPageHtmlFromResponse(pageHtml) {
    if (!pageHtml) return;
    blurCanvasEditing();
    var tmp = document.createElement('div');
    tmp.innerHTML = pageHtml;
    var newRoot = tmp.querySelector('[data-blocks-root]');
    var curRoot = canvasEl.querySelector('[data-blocks-root]');
    if (newRoot && curRoot) {
      curRoot.innerHTML = newRoot.innerHTML;
      wireCanvas();
      return;
    }
    canvasEl.innerHTML = pageHtml;
    wireCanvas();
    applyCanvasZoom(state.canvasZoom, false);
  }

  function parseApiResponse(r) {
    return r.text().then(function (text) {
      if (!text) {
        throw new Error('Empty response from server (HTTP ' + r.status + ').');
      }
      try {
        return JSON.parse(text);
      } catch (parseErr) {
        throw new Error('Invalid server response (HTTP ' + r.status + ').');
      }
    });
  }

  function apiGet(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      return parseApiResponse(r);
    });
  }

  function apiPost(action, payload) {
    return fetch(apiBase, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ action: action }, payload || {})),
    }).then(function (r) {
      return parseApiResponse(r);
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

  function formatTreeLabel(text, maxLen) {
    text = String(text || '');
    maxLen = maxLen || 34;
    if (text.length <= maxLen) return text;
    return text.slice(0, maxLen - 3) + '...';
  }

  function loadSection(sectionId, scrollRef) {
    setStatus('Loading…', 'saving');
    state.pendingScrollRef = scrollRef || null;
    var url = apiBase + '?action=load&version_id=' + state.versionId + '&section_id=' + sectionId;
    return apiGet(url).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Load failed');
      state.sectionId = res.section_id;
      state.editable = !!res.editable;
      state.sectionsTree = res.sections_tree || [];
      state.pageLayout = res.page_layout || {};
      state.pageHeader = res.page_header || defaultPageHeader();
      state.pageFooter = res.page_footer || defaultPageFooter();
      state.headerTokens = res.header_tokens || defaultHeaderTokens();
      state.headerPreviewTokens = res.header_preview_tokens || {};
      state.pageHeaderScope = res.page_header_scope || 'main';
      state.versionInfo = res.version || {};
      state.sectionTitle = (res.section && res.section.title) ? res.section.title : '';
      state.isCoverSection = !!res.is_cover_section;
      state.isTocSection = !!res.is_toc_section;
      state.isLepSection = !!res.is_lep_section;
      state.isPart0Section = !!res.is_part0_section;
      state.isAnnexRegisterSection = !!res.is_annex_register_section;
      state.isAnnexHighlightsSection = !!res.is_annex_highlights_section;
      state.isAnnexContentSection = !!res.is_annex_content_section;
      state.part0SectionKey = res.part0_section_key || '';
      state.part0Structured = !!res.part0_structured;
      state.part0Page = res.part0_page || null;
      state.coverPage = res.cover_page || defaultCoverPage();
      state.lepPage = res.lep_page || defaultLepPage();
      state.lepApprovalUrl = res.lep_approval_url || '';
      state.tocSettings = res.toc_settings || defaultTocSettings();
      state.tocSettingsCatalog = res.toc_settings_catalog || defaultTocSettingsCatalog();
      state.bookStyles = res.book_styles || defaultBookStyles();
      if (state.bookStyles.callout_presets) {
        state.calloutPresets = state.bookStyles.callout_presets;
      }
      applyNumberingState(res);
      refreshCalloutSelectOptions();
      state.undoStack = [];
      state.redoStack = [];
      root.classList.toggle('cpb-editor-readonly', !state.editable);
      updateToolbarMode();
      renderTree(state.sectionsTree, state.sectionId);
      canvasEl.innerHTML = res.page_html || '';
      wireCanvas();
      applyLayoutToDom(state.pageLayout);
      refreshContentTableTypographyFromBookStyles();
      refreshCalloutTypographyFromBookStyles();
      if (state.isTocSection) {
        refreshTocTypographyFromBookStyles();
      }
      if (state.isLepSection) {
        refreshLepTypographyFromBookStyles();
      }
      if (state.isPart0Section) {
        refreshPart0TypographyFromBookStyles();
      }
      if (state.isAnnexRegisterSection || state.isAnnexHighlightsSection) {
        refreshAnnexAdminTypographyFromBookStyles();
      }
      applyCanvasZoom(state.canvasZoom, false);
      if (state.pendingScrollRef) {
        var target = canvasEl.querySelector('[data-canonical-section-ref="' + state.pendingScrollRef + '"]');
        if (target) {
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        state.pendingScrollRef = null;
      }
      setStatus(state.editable ? 'Ready' : 'Read-only (released)', state.editable ? 'saved' : '');
      updateAddSubsection(res.section);
      if (res.prior_version_label) {
        setStatus('Ready · changes vs ' + res.prior_version_label, 'saved');
      }
    }).catch(showError);
  }

  function updateAddSubsection(section) {
    if (!addSubBtn || !section) return;
    var key = section.section_key || '';
    var isNestable = ['part_1', 'part_2', 'part_3', 'part_4', 'main_content', 'annexes'].indexOf(key) >= 0
      || !!section.parent_section_id;
    addSubBtn.style.display = state.editable && isNestable ? 'block' : 'none';
    addSubBtn.setAttribute('data-parent-id', String(section.id || state.sectionId));
  }

  function collectExpandableTreeNodeIds(nodes, ids) {
    if (!nodes || !nodes.length) return ids;
    nodes.forEach(function (node) {
      if (!node || node.is_separator) return;
      var nodeId = node.nav_id || String(node.id || '');
      if (node.children && node.children.length) {
        ids.push(nodeId);
        collectExpandableTreeNodeIds(node.children, ids);
      }
    });
    return ids;
  }

  function isTreeFullyExpanded() {
    var ids = collectExpandableTreeNodeIds(state.sectionsTree, []);
    if (!ids.length) return false;
    return ids.every(function (id) { return !!state.expanded[id]; });
  }

  function updateTreeToggleAllLabel() {
    if (!treeToggleAllBtn) return;
    var allExpanded = isTreeFullyExpanded();
    treeToggleAllBtn.textContent = allExpanded ? 'Collapse all' : 'Expand all';
    treeToggleAllBtn.setAttribute('aria-pressed', allExpanded ? 'true' : 'false');
    treeToggleAllBtn.title = allExpanded ? 'Collapse all sections' : 'Expand all sections';
  }

  function setTreeExpandedAll(expanded) {
    var ids = collectExpandableTreeNodeIds(state.sectionsTree, []);
    ids.forEach(function (id) {
      state.expanded[id] = expanded;
    });
    renderTree(state.sectionsTree, state.sectionId);
  }

  function wireTreeToggleAll() {
    if (!treeToggleAllBtn || treeToggleAllBtn.getAttribute('data-wired') === '1') return;
    treeToggleAllBtn.setAttribute('data-wired', '1');
    treeToggleAllBtn.addEventListener('click', function () {
      setTreeExpandedAll(!isTreeFullyExpanded());
    });
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
    updateTreeToggleAllLabel();
  }

  function renderTreeNode(node, activeId, depth) {
    var li = document.createElement('li');
    if (node.is_separator) {
      li.className = 'cpb-tree-separator';
      li.setAttribute('aria-hidden', 'true');
      return li;
    }

    li.className = 'cpb-tree-node' + (node.is_group ? ' cpb-tree-node--group' : '');

    var hasChildren = node.children && node.children.length > 0;
    var nodeId = node.nav_id || String(node.id || '');
    if (state.expanded[nodeId] === undefined) {
      state.expanded[nodeId] = false;
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
    var labelStyle = node.label_style || '';
    link.className = 'cpb-tree-link'
      + (node.id === activeId ? ' is-active' : '')
      + (node.is_generated ? ' is-generated' : '')
      + (node.is_group ? ' cpb-tree-link--group' : '')
      + (labelStyle === 'chapter_upper' ? ' cpb-tree-link--upper' : '')
      + (labelStyle === 'part0' ? ' cpb-tree-link--part0' : '')
      + (labelStyle === 'subtitle' ? ' cpb-tree-link--subtitle' : '');
    link.textContent = node.truncate === false ? node.title : formatTreeLabel(node.title);
    link.setAttribute('role', 'button');
    link.setAttribute('tabindex', '0');
    link.title = node.title;
    if (node.is_navigable !== false && node.id) {
      link.addEventListener('click', function () {
        loadSection(node.id, node.scroll_section_ref || null);
      });
      link.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          link.click();
        }
      });
    } else if (hasChildren) {
      link.addEventListener('click', function () {
        state.expanded[nodeId] = !state.expanded[nodeId];
        renderTree(state.sectionsTree, state.sectionId);
      });
    }

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
    var cb = sheet.querySelector('[data-layout-toggle="hide_header_footer"]');
    var orientationEl = sheet.querySelector('[data-layout-toggle="orientation"]:checked');
    var layout = {
      hide_header_footer: cb ? !!cb.checked : false,
    };
    if (orientationEl) {
      layout.orientation = orientationEl.value || 'portrait';
    }
    return layout;
  }

  function applyLayoutToDom(layout) {
    var sheet = canvasEl.querySelector('.cpb-sheet');
    if (!sheet || !layout) return;
    var cb = sheet.querySelector('[data-layout-toggle="hide_header_footer"]');
    if (cb && layout.hide_header_footer !== undefined) {
      cb.checked = !!layout.hide_header_footer;
    }
    if (layout.orientation) {
      sheet.classList.toggle('cpb-sheet--landscape', layout.orientation === 'landscape');
      var orientInputs = sheet.querySelectorAll('[data-layout-toggle="orientation"]');
      orientInputs.forEach(function (input) {
        input.checked = input.value === layout.orientation;
      });
    }
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
    canvasEl.querySelectorAll('[data-open-header-editor]').forEach(function (el) {
      if (el.getAttribute('data-header-wired') === '1') return;
      el.setAttribute('data-header-wired', '1');
      el.addEventListener('click', function (e) {
        if (!state.editable) return;
        e.preventDefault();
        openHeaderEditor();
      });
    });
  }

  function initCanvasEvents() {
    if (state.canvasEventsWired) return;
    state.canvasEventsWired = true;

    canvasEl.addEventListener('click', function (e) {
      var annexLink = e.target.closest('a.cpb-annex-link[data-section-id]');
      if (annexLink && canvasEl.contains(annexLink)) {
        e.preventDefault();
        var sid = parseInt(annexLink.getAttribute('data-section-id') || '0', 10);
        if (sid > 0) loadSection(sid);
        return;
      }
    });

    canvasEl.addEventListener('focusin', function (e) {
      var cell = e.target.closest('.cpb-table th, .cpb-table td');
      if (cell && cell.isContentEditable) {
        state.focusedTableCell = cell;
      } else if (e.target.closest('.cpb-callout-title, .cpb-callout-text, .cpb-paragraph, .cpb-heading, .cpb-list')) {
        state.focusedTableCell = null;
      }
      requestAnimationFrame(function () {
        rememberStyleTarget();
      });
    });

    canvasEl.addEventListener('pointerdown', function (e) {
      var tableCell = e.target.closest('.cpb-table th, .cpb-table td');
      if (tableCell && tableCell.isContentEditable && canvasEl.contains(tableCell)) {
        state.focusedTableCell = tableCell;
        var tableBlock = tableCell.closest('.cpb-block');
        if (tableBlock) {
          state.lastStyleTarget = { block: tableBlock, el: tableCell, type: 'table-cell' };
        }
      }
      requestAnimationFrame(function () {
        saveSelectionRange();
        rememberStyleTarget();
      });
    }, true);

    canvasEl.addEventListener('mouseup', function () {
      saveSelectionRange();
      rememberStyleTarget();
    });

    canvasEl.addEventListener('keyup', rememberStyleTarget);

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
          clearPendingForBlock(blockId);
          clearStyleTargetForBlock(blockEl);
          blockEl.remove();
          setStatus('Table deleted', 'saved');
          return recomputeSectionNumbers();
        }).catch(showError);
        return;
      }
      pushUndo();
      if (action === 'add-row') tableAddRow(blockEl);
      else if (action === 'del-row') {
        if (!tableDelRow(blockEl)) return;
      } else if (action === 'move-row-up') {
        if (!tableMoveRow(blockEl, 'up')) return;
      } else if (action === 'move-row-down') {
        if (!tableMoveRow(blockEl, 'down')) return;
      } else if (action === 'merge-cells-right') {
        if (!tableMergeCellRight(blockEl)) return;
      } else if (action === 'unmerge-cells') {
        if (!tableUnmergeCell(blockEl)) return;
      } else if (action === 'add-col') tableAddColumn(blockEl);
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
      var input = e.target.closest('input[data-table-action="border-color"], input[data-table-action="cell-bg"], input[data-table-action="cell-text-color"]');
      if (!input || !state.editable) return;
      var blockEl = input.closest('.cpb-block');
      if (!blockEl) return;
      pushUndo();
      var action = input.getAttribute('data-table-action');
      if (action === 'border-color') applyTableBorderColor(blockEl, input.value);
      else if (action === 'cell-text-color') {
        if (!state.focusedTableCell || !blockEl.contains(state.focusedTableCell)) {
          setStatus('Click a table cell first', 'error');
          return;
        }
        applyColorToTableCell(state.focusedTableCell, input.value);
        if (textColorInput) textColorInput.value = input.value;
        updateParagraphStyleSelectForElement(state.focusedTableCell);
      } else if (!state.focusedTableCell || !blockEl.contains(state.focusedTableCell)) {
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
        if (field.classList.contains('cpb-paragraph')
          || field.classList.contains('cpb-heading')
          || field.classList.contains('cpb-list')) {
          refreshBlockTypographyFromBookStyles(field);
        }
        syncSectionNumberTypography(field);
        field.addEventListener('input', function () {
          scheduleSave(blockEl);
        });
        field.addEventListener('blur', function () {
          if (field.classList.contains('cpb-list')) {
            repairListDomStructure(field);
          }
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
            clearPendingForBlock(blockId);
            clearStyleTargetForBlock(blockEl);
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

    wireCoverPage();

    wireLepPage();

    wirePart0Page();

    wireTocNavigation();

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

    canvasEl.querySelectorAll('[data-annex-link]').forEach(function (row) {
      if (row.getAttribute('data-annex-link-wired') === '1') return;
      row.setAttribute('data-annex-link-wired', '1');
      row.addEventListener('click', function () {
        var sid = parseInt(row.getAttribute('data-annex-link') || '0', 10);
        if (sid > 0) loadSection(sid);
      });
    });
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
        var pendingEl = state.pending[id];
        if (isConnectedEl(pendingEl)) {
          flushSave(pendingEl);
        } else {
          clearPendingForBlock(parseInt(id, 10));
        }
      });
      state.pending = {};
    }, 700);
    setStatus('Editing…', 'saving');
  }

  function flushSave(blockEl, refreshNumbering) {
    if (!state.editable || !blockEl || !isConnectedEl(blockEl)) return;
    var blockId = parseInt(blockEl.getAttribute('data-block-id') || '0', 10);
    var blockType = blockEl.getAttribute('data-block-type') || '';
    var payload = extractPayload(blockEl, blockType);
    setStatus('Saving…', 'saving');
    apiPost('update_block', { block_id: blockId, payload: payload }).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Save failed');
      applyNumberingState(res);
      setStatus('Saved', 'saved');
      if (refreshNumbering) {
        return recomputeSectionNumbers().catch(showError);
      }
    }).catch(showError);
  }

  function defaultBookStyles() {
    return {
      paragraph_styles: {
        title: { font_family: 'sans', font_size: 24, color: '#0f2744', font_bold: true, font_italic: false, font_underline: false },
        subtitle_1: { font_family: 'sans', font_size: 18, color: '#0f2744', font_bold: true, font_italic: false, font_underline: false },
        subtitle_2: { font_family: 'sans', font_size: 16, color: '#0f2744', font_bold: true, font_italic: false, font_underline: false },
        subtitle_3: { font_family: 'sans', font_size: 14, color: '#0f2744', font_bold: false, font_italic: false, font_underline: false },
        subtitle_4: { font_family: 'sans', font_size: 12, color: '#334155', font_bold: false, font_italic: false, font_underline: false },
        regulatory_reference: { font_family: 'mono', font_size: 10, color: '#1e3a8a', font_bold: false, font_italic: false, font_underline: false },
        body: { font_family: 'serif', font_size: 11, color: '#0f172a', font_bold: false, font_italic: false, font_underline: false },
        caption: { font_family: 'sans', font_size: 9, color: '#64748b', font_bold: false, font_italic: false, font_underline: false },
      },
      table_styles: {
        standard: defaultTableStyleDef(),
        text: Object.assign({}, defaultTableStyleDef(), { border_width: 'thin' }),
      },
      callout_presets: [
        { callout_type: 'warning', title: 'WARNING', text: '' },
        { callout_type: 'caution', title: 'CAUTION', text: '' },
        { callout_type: 'info', title: 'INFO', text: '' },
        { callout_type: 'note', title: 'NOTE', text: '' },
      ],
      callout_styles: defaultCalloutStylesDef(),
      page_header: defaultPageHeader(),
      page_footer: defaultPageFooter(),
    };
  }

  function defaultCalloutStylesDef() {
    return {
      warning: {
        border_color: '#dc2626', background: '#fef2f2', icon_color: '#dc2626',
        title_color: '#991b1b', title_font_family: 'sans', title_font_size: 11, title_font_bold: true,
        text_color: '#1e293b', text_font_family: 'sans', text_font_size: 10,
      },
      caution: {
        border_color: '#ca8a04', background: '#fffbeb', icon_color: '#eab308',
        title_color: '#854d0e', title_font_family: 'sans', title_font_size: 11, title_font_bold: true,
        text_color: '#1e293b', text_font_family: 'sans', text_font_size: 10,
      },
      info: {
        border_color: '#1e40af', background: '#eff6ff', icon_color: '#1e3a8a',
        title_color: '#1e3a8a', title_font_family: 'sans', title_font_size: 11, title_font_bold: true,
        text_color: '#1e293b', text_font_family: 'sans', text_font_size: 10,
      },
      note: {
        border_color: '#0d9488', background: '#f0fdfa', icon_color: '#0d9488',
        title_color: '#115e59', title_font_family: 'sans', title_font_size: 11, title_font_bold: true,
        text_color: '#134e4a', text_font_family: 'sans', text_font_size: 10,
      },
    };
  }

  function calloutStyleDef(type) {
    var styles = (state.bookStyles && state.bookStyles.callout_styles) || defaultCalloutStylesDef();
    return styles[type] || defaultCalloutStylesDef()[type] || defaultCalloutStylesDef().info;
  }

  function defaultPageHeader() {
    return {
      enabled: true,
      left_type: 'logo',
      logo_url: '',
      logo_alt: 'EuroPilot Center',
      logo_max_height: 40,
      row_height: 32,
      center_text: '{book_title} ({manual_code})\n{part_title}',
      center_font_family: 'sans',
      center_font_size: 11,
      center_font_bold: true,
      center_font_italic: false,
      center_font_underline: false,
      right_text: 'Page: {page}\nRevision: {revision}\nDate: {date}',
      right_font_family: 'sans',
      right_font_size: 10,
      right_font_bold: true,
      right_font_italic: false,
      right_font_underline: false,
    };
  }

  function defaultPageFooter() {
    return {
      enabled: true,
      row_height: 26,
      left_text: '',
      left_font_family: 'sans',
      left_font_size: 9,
      left_font_bold: false,
      left_font_italic: false,
      left_font_underline: false,
      center_text: 'Controlled copy — internal use',
      center_font_family: 'sans',
      center_font_size: 9,
      center_font_bold: false,
      center_font_italic: false,
      center_font_underline: false,
      right_text: '',
      right_font_family: 'sans',
      right_font_size: 9,
      right_font_bold: false,
      right_font_italic: false,
      right_font_underline: false,
    };
  }

  function defaultTocSettings() {
    return {
      include_title: true,
      include_subtitle_1: true,
      include_subtitle_2: true,
      include_subtitle_3: false,
      include_subtitle_4: false,
    };
  }

  function defaultTocSettingsCatalog() {
    return [
      { key: 'include_title', style: 'title', label: 'Title', enabled: true, locked: true },
      { key: 'include_subtitle_1', style: 'subtitle_1', label: 'Subtitle 1', enabled: true, locked: false },
      { key: 'include_subtitle_2', style: 'subtitle_2', label: 'Subtitle 2', enabled: true, locked: false },
      { key: 'include_subtitle_3', style: 'subtitle_3', label: 'Subtitle 3', enabled: false, locked: false },
      { key: 'include_subtitle_4', style: 'subtitle_4', label: 'Subtitle 4', enabled: false, locked: false },
    ];
  }

  function collectTocSettingsFromPanel() {
    var settings = Object.assign({}, defaultTocSettings(), state.tocSettings || {});
    var panel = toolbarTocEl;
    if (!panel) return settings;
    panel.querySelectorAll('[data-toc-setting]').forEach(function (input) {
      var key = input.getAttribute('data-toc-setting');
      if (!key) return;
      settings[key] = input.checked;
    });
    settings.include_title = true;
    return settings;
  }

  function updateTocToolbarCheckboxes() {
    if (!toolbarTocEl) return;
    var catalog = state.tocSettingsCatalog && state.tocSettingsCatalog.length
      ? state.tocSettingsCatalog
      : defaultTocSettingsCatalog();
    catalog.forEach(function (item) {
      var input = toolbarTocEl.querySelector('[data-toc-setting="' + item.key + '"]');
      if (input) input.checked = !!item.enabled;
    });
  }

  function updateToolbarMode() {
    if (!toolbarEl) return;
    var isReleased = state.versionInfo && state.versionInfo.lifecycle_status === 'released';
    root.classList.toggle('cpb-editor-toc-mode', !!state.isTocSection && !isReleased);
    root.classList.toggle('cpb-editor-lep-mode', !!state.isLepSection && !isReleased);
    root.classList.toggle('cpb-editor-part0-mode', !!state.part0Structured && !isReleased);
    function hideAuxToolbars() {
      if (toolbarTocEl) {
        toolbarTocEl.hidden = true;
        toolbarTocEl.setAttribute('aria-hidden', 'true');
      }
      if (toolbarLepEl) {
        toolbarLepEl.hidden = true;
        toolbarLepEl.setAttribute('aria-hidden', 'true');
      }
      if (toolbarPart0El) {
        toolbarPart0El.hidden = true;
        toolbarPart0El.setAttribute('aria-hidden', 'true');
      }
    }
    if (state.isCoverSection || isReleased) {
      toolbarEl.style.display = 'none';
      hideAuxToolbars();
      return;
    }
    if (state.isTocSection) {
      toolbarEl.style.display = 'flex';
      if (toolbarLepEl) {
        toolbarLepEl.hidden = true;
        toolbarLepEl.setAttribute('aria-hidden', 'true');
      }
      if (toolbarPart0El) {
        toolbarPart0El.hidden = true;
        toolbarPart0El.setAttribute('aria-hidden', 'true');
      }
      renderTocToolbar();
      return;
    }
    if (state.isLepSection) {
      toolbarEl.style.display = 'flex';
      if (toolbarTocEl) {
        toolbarTocEl.hidden = true;
        toolbarTocEl.setAttribute('aria-hidden', 'true');
      }
      if (toolbarPart0El) {
        toolbarPart0El.hidden = true;
        toolbarPart0El.setAttribute('aria-hidden', 'true');
      }
      renderLepToolbar();
      return;
    }
    if (state.part0Structured) {
      toolbarEl.style.display = 'flex';
      if (toolbarTocEl) {
        toolbarTocEl.hidden = true;
        toolbarTocEl.setAttribute('aria-hidden', 'true');
      }
      if (toolbarLepEl) {
        toolbarLepEl.hidden = true;
        toolbarLepEl.setAttribute('aria-hidden', 'true');
      }
      renderPart0Toolbar();
      return;
    }
    if (state.isAnnexRegisterSection) {
      toolbarEl.style.display = 'none';
      hideAuxToolbars();
      return;
    }
    hideAuxToolbars();
    toolbarEl.style.display = state.editable ? 'flex' : 'none';
  }

  function renderTocToolbar() {
    if (!toolbarTocEl) return;
    toolbarTocEl.hidden = false;
    toolbarTocEl.setAttribute('aria-hidden', 'false');

    if (toolbarTocEl.getAttribute('data-toc-wired') !== '1') {
      var catalog = defaultTocSettingsCatalog();
      var levelsHtml = catalog.map(function (item) {
        var checked = item.enabled ? ' checked' : '';
        var disabled = item.locked ? ' disabled' : '';
        var lockedClass = item.locked ? ' is-locked' : '';
        return '<label class="cpb-toc-level-check' + lockedClass + '">'
          + '<input type="checkbox" data-toc-setting="' + escapeHtml(item.key) + '"'
          + checked + disabled + '> '
          + '<span>' + escapeHtml(item.label) + '</span></label>';
      }).join('');

      toolbarTocEl.innerHTML = ''
        + '<div class="cpb-toolbar-group cpb-toolbar-group--toc-label">'
        + '<span class="cpb-toolbar-toc-label">Include</span>'
        + '</div>'
        + '<div class="cpb-toolbar-group cpb-toolbar-group--toc-levels">' + levelsHtml + '</div>'
        + '<div class="cpb-toolbar-group">'
        + '<button type="button" class="cpb-tool-btn cpb-toc-regenerate" title="Regenerate table of contents">Regenerate</button>'
        + '<button type="button" class="cpb-tool-btn" id="cpbTocSaveSettings" title="Save TOC level settings">Save</button>'
        + '<button type="button" class="cpb-tool-btn" id="cpbTocOpenHeader" title="Page header editor">Header</button>'
        + '</div>';

      toolbarTocEl.setAttribute('data-toc-wired', '1');
      toolbarTocEl.querySelector('.cpb-toc-regenerate').addEventListener('click', function () {
        syncToc(true);
      });
      toolbarTocEl.querySelector('#cpbTocSaveSettings').addEventListener('click', function () {
        var settings = collectTocSettingsFromPanel();
        setStatus('Saving TOC settings…', 'saving');
        apiPost('save_toc_settings', {
          version_id: state.versionId,
          toc_settings: settings,
        }).then(function (res) {
          if (!res.ok) throw new Error(res.error || 'Save failed');
          state.tocSettings = res.toc_settings || settings;
          state.tocSettingsCatalog = res.toc_settings_catalog || state.tocSettingsCatalog;
          updateTocToolbarCheckboxes();
          setStatus('TOC settings saved', 'saved');
        }).catch(showError);
      });
      toolbarTocEl.querySelector('#cpbTocOpenHeader').addEventListener('click', function () {
        openHeaderEditor();
      });
    }

    updateTocToolbarCheckboxes();
  }

  function scrollToTocTarget(anchor) {
    if (!anchor) return;
    var target = document.getElementById(anchor)
      || canvasEl.querySelector('[data-stable-anchor="' + anchor + '"]');
    if (target && typeof target.scrollIntoView === 'function') {
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  function wireTocNavigation() {
    if (state.tocNavWired) return;
    state.tocNavWired = true;
    canvasEl.addEventListener('click', function (e) {
      var link = e.target.closest('.cpb-toc-link');
      if (!link) return;
      e.preventDefault();
      var sectionId = parseInt(link.getAttribute('data-section-id') || '0', 10);
      var target = link.getAttribute('data-toc-target') || '';
      if (sectionId > 0 && sectionId !== state.sectionId) {
        loadSection(sectionId).then(function () {
          scrollToTocTarget(target);
        });
      } else {
        scrollToTocTarget(target);
      }
    });
  }

  function defaultCoverPage() {
    return {
      logo_url: '',
      logo_alt: 'EuroPilot Center',
      company_name: 'EuroPilot Center',
      registration_number: 'B/ATO-017',
      cover_image_url: '',
      cover_image_alt: '',
      manual_title: '',
    };
  }

  function extractCoverPageFromCanvas() {
    var sheet = canvasEl.querySelector('.cpb-sheet--cover');
    if (!sheet) return Object.assign({}, defaultCoverPage(), state.coverPage || {});
    var cover = Object.assign({}, defaultCoverPage(), state.coverPage || {});
    var company = sheet.querySelector('[data-cover-field="company_name"]');
    var registration = sheet.querySelector('[data-cover-field="registration_number"]');
    var manualTitle = sheet.querySelector('[data-cover-field="manual_title"]');
    if (company) cover.company_name = company.textContent.trim();
    if (registration) cover.registration_number = registration.textContent.trim();
    if (manualTitle) cover.manual_title = manualTitle.textContent.trim();
    return cover;
  }

  function scheduleCoverSave() {
    if (!state.editable || !state.isCoverSection) return;
    if (state.coverSaveTimer) clearTimeout(state.coverSaveTimer);
    state.coverSaveTimer = setTimeout(function () {
      state.coverSaveTimer = null;
      flushCoverSave();
    }, 450);
  }

  function flushCoverSave() {
    if (!state.editable || !state.isCoverSection) return;
    var payload = extractCoverPageFromCanvas();
    setStatus('Saving cover…', 'saving');
    apiPost('save_cover_page', {
      version_id: state.versionId,
      cover_page: payload,
    }).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Cover save failed');
      state.coverPage = res.cover_page || payload;
      setStatus('Cover saved', 'saved');
    }).catch(showError);
  }

  function uploadCoverAsset(assetType, file) {
    if (!file || !file.type.match(/^image\/(jpeg|png|webp)$/)) {
      alert('Only JPG, PNG, or WEBP images are allowed.');
      return;
    }
    var action = assetType === 'logo' ? 'upload_cover_logo' : 'upload_cover_image';
    setStatus('Uploading…', 'saving');
    var fd = new FormData();
    fd.append('action', action);
    fd.append('version_id', String(state.versionId));
    fd.append('image', file);
    fetch(apiBase, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Upload failed');
        state.coverPage = res.cover_page || state.coverPage;
        return loadSection(state.sectionId);
      })
      .then(function () {
        setStatus('Upload complete', 'saved');
      })
      .catch(showError);
  }

  function wireCoverPage() {
    var sheet = canvasEl.querySelector('.cpb-sheet--cover');
    if (!sheet || sheet.getAttribute('data-cover-wired') === '1') return;
    sheet.setAttribute('data-cover-wired', '1');

    sheet.querySelectorAll('[data-cover-field]').forEach(function (field) {
      if (!state.editable || field.getAttribute('data-cover-field-wired') === '1') return;
      field.setAttribute('data-cover-field-wired', '1');
      field.addEventListener('input', scheduleCoverSave);
      field.addEventListener('blur', function () {
        if (state.coverSaveTimer) {
          clearTimeout(state.coverSaveTimer);
          state.coverSaveTimer = null;
        }
        flushCoverSave();
      });
    });

    sheet.querySelectorAll('[data-cover-drop]').forEach(function (zone) {
      if (!state.editable || zone.getAttribute('data-cover-drop-wired') === '1') return;
      zone.setAttribute('data-cover-drop-wired', '1');
      zone.addEventListener('click', function () {
        var target = zone.getAttribute('data-cover-drop');
        state.coverDropTarget = target;
        if (target === 'logo' && coverLogoInput) coverLogoInput.click();
        else if (target === 'cover_image' && coverImageInput) coverImageInput.click();
      });
      ['dragenter', 'dragover'].forEach(function (ev) {
        zone.addEventListener(ev, function (e) {
          e.preventDefault();
          zone.classList.add('is-drag');
        });
      });
      zone.addEventListener('dragleave', function () {
        zone.classList.remove('is-drag');
      });
      zone.addEventListener('drop', function (e) {
        e.preventDefault();
        zone.classList.remove('is-drag');
        var files = e.dataTransfer && e.dataTransfer.files;
        if (!files || !files[0]) return;
        uploadCoverAsset(zone.getAttribute('data-cover-drop'), files[0]);
      });
    });
  }

  function defaultLepPage() {
    return {
      certification_text: '',
      on_behalf_text: '',
      table_title: '0.1.1 Effective Parts',
      empty_rows: 10,
      headings: defaultLepHeadings(),
      signatories: [],
      effective_parts: [],
    };
  }

  function defaultLepHeadings() {
    return [
      { key: 'part_title', style: 'title', text: 'PART 0 – Manual Administration' },
      { key: 'title', style: 'title', text: '0. OUTLINE' },
      { key: 'subtitle_1', style: 'subtitle_1', text: '0.1 List of effective Parts' },
      { key: 'subtitle_2', style: 'subtitle_2', text: '0.1.1 Effective Parts' },
    ];
  }

  function renderLepToolbar() {
    if (!toolbarLepEl) return;
    toolbarLepEl.hidden = false;
    toolbarLepEl.setAttribute('aria-hidden', 'false');

    if (toolbarLepEl.getAttribute('data-lep-wired') !== '1') {
      toolbarLepEl.innerHTML = ''
        + '<div class="cpb-toolbar-group cpb-toolbar-group--lep-label">'
        + '<span class="cpb-toolbar-lep-label">List of Effective Parts</span>'
        + '</div>'
        + '<div class="cpb-toolbar-group">'
        + '<button type="button" class="cpb-tool-btn cpb-lep-regenerate" title="Regenerate effective parts from manual structure">Regenerate parts</button>'
        + '<button type="button" class="cpb-tool-btn" id="cpbLepSave" title="Save LEP text and signatory details">Save</button>'
        + '<button type="button" class="cpb-tool-btn" id="cpbLepOpenHeader" title="Page header editor">Header</button>'
        + '<button type="button" class="cpb-tool-btn" id="cpbLepApprovalLink" title="Open authority approval page">Approval page</button>'
        + '</div>';

      toolbarLepEl.setAttribute('data-lep-wired', '1');
      toolbarLepEl.querySelector('.cpb-lep-regenerate').addEventListener('click', function () {
        syncLepParts(true);
      });
      toolbarLepEl.querySelector('#cpbLepSave').addEventListener('click', function () {
        if (state.lepSaveTimer) {
          clearTimeout(state.lepSaveTimer);
          state.lepSaveTimer = null;
        }
        flushLepSave();
      });
      toolbarLepEl.querySelector('#cpbLepOpenHeader').addEventListener('click', function () {
        openHeaderEditor();
      });
      toolbarLepEl.querySelector('#cpbLepApprovalLink').addEventListener('click', function () {
        openLepApprovalPage();
      });
    }

    var approvalBtn = toolbarLepEl.querySelector('#cpbLepApprovalLink');
    if (approvalBtn) {
      approvalBtn.disabled = !state.lepApprovalUrl;
    }
  }

  function openLepApprovalPage() {
    if (!state.lepApprovalUrl) {
      setStatus('Generating approval link…', 'saving');
      apiPost('ensure_lep_approval', { version_id: state.versionId })
        .then(function (res) {
          if (!res.ok) throw new Error(res.error || 'Could not create approval link');
          state.lepApprovalUrl = res.approval_url || '';
          window.open(state.lepApprovalUrl, '_blank', 'noopener');
        })
        .catch(showError);
      return;
    }
    window.open(state.lepApprovalUrl, '_blank', 'noopener');
  }

  function extractLepPageFromCanvas() {
    var sheet = canvasEl.querySelector('.cpb-sheet--lep');
    if (!sheet) return Object.assign({}, defaultLepPage(), state.lepPage || {});
    var lep = Object.assign({}, defaultLepPage(), state.lepPage || {});

    var cert = sheet.querySelector('[data-lep-field="certification_text"]');
    var onBehalf = sheet.querySelector('[data-lep-field="on_behalf_text"]');
    if (cert) lep.certification_text = cert.textContent.trim();
    if (onBehalf) lep.on_behalf_text = onBehalf.textContent.trim();

    var headings = (state.lepPage && Array.isArray(state.lepPage.headings) && state.lepPage.headings.length)
      ? state.lepPage.headings.map(function (h) {
          return { key: h.key, style: h.style, text: h.text };
        })
      : defaultLepHeadings();
    headings.forEach(function (heading) {
      var field = sheet.querySelector('[data-lep-field="heading_' + heading.key + '"]');
      if (field) {
        heading.text = field.textContent.trim();
      }
    });
    lep.headings = headings;
    var subtitle2 = headings.filter(function (h) { return h.key === 'subtitle_2'; })[0];
    if (subtitle2) lep.table_title = subtitle2.text;

    var signatories = [];
    sheet.querySelectorAll('.cpb-lep-signatory[data-lep-slot]').forEach(function (box) {
      var slotKey = box.getAttribute('data-lep-slot') || '';
      if (!slotKey) return;
      var existing = null;
      (lep.signatories || []).forEach(function (slot) {
        if (slot && slot.slot_key === slotKey) existing = slot;
      });
      var nameEl = box.querySelector('[data-lep-field="name"]');
      var titleEl = box.querySelector('[data-lep-field="title"]');
      var dateEl = box.querySelector('[data-lep-field="date"]');
      signatories.push({
        slot_key: slotKey,
        name: nameEl ? nameEl.textContent.trim() : '',
        title: titleEl ? titleEl.textContent.trim() : '',
        date: dateEl ? dateEl.textContent.trim() : '',
        signature_url: existing ? (existing.signature_url || '') : '',
        signed_at: existing ? existing.signed_at : null,
        signed_by_user_id: existing ? existing.signed_by_user_id : null,
        signer_type: 'internal',
      });
    });
    if (signatories.length) lep.signatories = signatories;
    return lep;
  }

  function scheduleLepSave() {
    if (!state.editable || !state.isLepSection) return;
    if (state.lepSaveTimer) clearTimeout(state.lepSaveTimer);
    state.lepSaveTimer = setTimeout(function () {
      state.lepSaveTimer = null;
      flushLepSave();
    }, 450);
  }

  function flushLepSave() {
    if (!state.editable || !state.isLepSection) return;
    var payload = extractLepPageFromCanvas();
    setStatus('Saving LEP…', 'saving');
    apiPost('save_lep_page', {
      version_id: state.versionId,
      lep_page: payload,
    }).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'LEP save failed');
      state.lepPage = res.lep_page || payload;
      setStatus('LEP saved', 'saved');
    }).catch(showError);
  }

  function part0PageLabel() {
    var labels = {
      amendment_list: 'Amendment List',
      distribution_list: 'Distribution List',
      abbreviations: 'Index of Abbreviations',
      definitions: 'Definitions and Terms',
    };
    return labels[state.part0SectionKey] || 'Part 0';
  }

  function renderPart0Toolbar() {
    if (!toolbarPart0El) return;
    toolbarPart0El.hidden = false;
    toolbarPart0El.setAttribute('aria-hidden', 'false');
    var regenBtn = (state.part0SectionKey === 'abbreviations' || state.part0SectionKey === 'definitions')
      ? '<button type="button" class="cpb-tool-btn cpb-part0-regenerate" title="Regenerate from manual content">Regenerate</button>'
      : '';
    var importBtn = state.part0SectionKey === 'definitions'
      ? '<button type="button" class="cpb-tool-btn" id="cpbPart0ImportDefinitions" title="Paste definitions from Word/PDF">Import</button>'
      : '';
    if (toolbarPart0El.getAttribute('data-part0-wired') !== '1') {
      toolbarPart0El.innerHTML = ''
        + '<div class="cpb-toolbar-group cpb-toolbar-group--part0-label">'
        + '<span class="cpb-toolbar-part0-label" data-part0-toolbar-label="1">' + escapeHtml(part0PageLabel()) + '</span>'
        + '</div>'
        + '<div class="cpb-toolbar-group">'
        + regenBtn
        + importBtn
        + '<button type="button" class="cpb-tool-btn" id="cpbPart0Save" title="Save page">Save</button>'
        + '<button type="button" class="cpb-tool-btn" id="cpbPart0OpenHeader" title="Page header editor">Header</button>'
        + '</div>';
      toolbarPart0El.setAttribute('data-part0-wired', '1');
      var regenEl = toolbarPart0El.querySelector('.cpb-part0-regenerate');
      if (regenEl) {
        regenEl.addEventListener('click', function () {
          if (state.part0SectionKey === 'definitions') syncDefinitions(true);
          else syncAbbreviations(true);
        });
      }
      toolbarPart0El.querySelector('#cpbPart0Save').addEventListener('click', function () {
        if (state.part0SaveTimer) {
          clearTimeout(state.part0SaveTimer);
          state.part0SaveTimer = null;
        }
        flushPart0Save();
      });
      toolbarPart0El.querySelector('#cpbPart0OpenHeader').addEventListener('click', function () {
        openHeaderEditor();
      });
      wireDefinitionsImportButton();
    } else {
      var labelEl = toolbarPart0El.querySelector('[data-part0-toolbar-label="1"]');
      if (labelEl) labelEl.textContent = part0PageLabel();
      var existingRegen = toolbarPart0El.querySelector('.cpb-part0-regenerate');
      var existingImport = toolbarPart0El.querySelector('#cpbPart0ImportDefinitions');
      var wantsRegen = state.part0SectionKey === 'abbreviations' || state.part0SectionKey === 'definitions';
      var wantsImport = state.part0SectionKey === 'definitions';
      if (wantsRegen && !existingRegen) {
        var group = toolbarPart0El.querySelector('.cpb-toolbar-group:last-child');
        if (group) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'cpb-tool-btn cpb-part0-regenerate';
          btn.title = 'Regenerate from manual content';
          btn.textContent = 'Regenerate';
          btn.addEventListener('click', function () {
            if (state.part0SectionKey === 'definitions') syncDefinitions(true);
            else syncAbbreviations(true);
          });
          group.insertBefore(btn, group.firstChild);
        }
      } else if (!wantsRegen && existingRegen) {
        existingRegen.remove();
      }
      if (wantsImport && !existingImport) {
        var group = toolbarPart0El.querySelector('.cpb-toolbar-group:last-child');
        if (group) {
          var importEl = document.createElement('button');
          importEl.type = 'button';
          importEl.className = 'cpb-tool-btn';
          importEl.id = 'cpbPart0ImportDefinitions';
          importEl.title = 'Paste definitions from Word/PDF';
          importEl.textContent = 'Import';
          group.insertBefore(importEl, group.querySelector('#cpbPart0Save'));
          wireDefinitionsImportButton();
        }
      } else if (!wantsImport && existingImport) {
        existingImport.remove();
      }
    }
  }

  function wireDefinitionsImportButton() {
    var btn = document.getElementById('cpbPart0ImportDefinitions');
    if (!btn || btn.getAttribute('data-import-wired') === '1') return;
    btn.setAttribute('data-import-wired', '1');
    btn.addEventListener('click', openDefinitionsImportDialog);
  }

  function openDefinitionsImportDialog() {
    var overlay = document.getElementById('cpbDefinitionsImport');
    var textarea = document.getElementById('cpbDefinitionsImportText');
    var cancelBtn = document.getElementById('cpbDefinitionsImportCancel');
    var submitBtn = document.getElementById('cpbDefinitionsImportSubmit');
    if (!overlay || !textarea || !cancelBtn || !submitBtn) return;
    textarea.value = '';
    overlay.hidden = false;
    overlay.setAttribute('aria-hidden', 'false');
    textarea.focus();
    function closeDialog() {
      overlay.hidden = true;
      overlay.setAttribute('aria-hidden', 'true');
      cancelBtn.removeEventListener('click', closeDialog);
      submitBtn.removeEventListener('click', submitImport);
    }
    function submitImport() {
      var text = textarea.value.trim();
      if (!text) {
        showError(new Error('Paste the 0.6 Definitions and Terms text first.'));
        return;
      }
      closeDialog();
      setStatus('Importing definitions…', 'saving');
      apiPost('import_definitions_text', {
        version_id: state.versionId,
        section_id: state.sectionId,
        definitions_text: text,
      }).then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Import failed');
        var count = res.result && res.result.entries_count !== undefined ? res.result.entries_count : 0;
        state.part0Page = res.part0_page || state.part0Page;
        if (res.page_html) {
          canvasEl.innerHTML = res.page_html;
          wireCanvas();
          refreshPart0TypographyFromBookStyles();
          setStatus('Definitions imported (' + count + ' entries)', 'saved');
        } else {
          return loadSection(state.sectionId);
        }
      }).catch(showError);
    }
    cancelBtn.addEventListener('click', closeDialog);
    submitBtn.addEventListener('click', submitImport);
  }

  function extractPart0HeadingsFromCanvas() {
    var sheet = canvasEl.querySelector('.cpb-sheet--part0');
    if (!sheet) return [];
    var headings = [];
    sheet.querySelectorAll('[data-part0-field^="heading_"]').forEach(function (el) {
      var key = (el.getAttribute('data-part0-field') || '').replace(/^heading_/, '');
      if (!key) return;
      headings.push({
        key: key,
        style: el.getAttribute('data-paragraph-style') || 'body',
        text: el.textContent.trim(),
      });
    });
    return headings;
  }

  function extractPart0PageFromCanvas() {
    var sheet = canvasEl.querySelector('.cpb-sheet--part0');
    if (!sheet || !state.part0Structured) return null;
    var key = state.part0SectionKey;
    var emptyRows = state.part0Page && state.part0Page.empty_rows !== undefined
      ? state.part0Page.empty_rows
      : 10;

    if (key === 'amendment_list') {
      var amendRows = [];
      sheet.querySelectorAll('[data-part0-table="amendment_list"] tbody tr').forEach(function (tr) {
        var row = {};
        tr.querySelectorAll('[data-part0-col]').forEach(function (cell) {
          row[cell.getAttribute('data-part0-col')] = cell.textContent.replace(/\u00a0/g, ' ').trim();
        });
        if (Object.keys(row).some(function (k) { return row[k]; })) amendRows.push(row);
      });
      return {
        rows: amendRows,
        empty_rows: emptyRows,
      };
    }
    if (key === 'distribution_list') {
      var distRows = [];
      sheet.querySelectorAll('[data-part0-table="distribution_list"] tbody tr').forEach(function (tr) {
        var row = {};
        tr.querySelectorAll('[data-part0-col]').forEach(function (cell) {
          row[cell.getAttribute('data-part0-col')] = cell.textContent.replace(/\u00a0/g, ' ').trim();
        });
        if (Object.keys(row).some(function (k) { return row[k]; })) distRows.push(row);
      });
      return { rows: distRows, empty_rows: emptyRows };
    }
    if (key === 'abbreviations') {
      var abbrEntries = [];
      sheet.querySelectorAll('.cpb-part0-abbr-row').forEach(function (rowEl) {
        var abbrEl = rowEl.querySelector('[data-part0-col="abbreviation"]');
        var defEl = rowEl.querySelector('[data-part0-col="definition"]');
        var abbr = abbrEl ? abbrEl.textContent.replace(/\u00a0/g, ' ').trim() : '';
        if (!abbr) return;
        var def = defEl ? defEl.textContent.replace(/\u00a0/g, ' ').trim() : '';
        if (def === 'Add meaning…') def = '';
        var status = rowEl.getAttribute('data-definition-status') || '';
        if (def && status === 'needs_review') status = 'confirmed';
        abbrEntries.push({
          abbreviation: abbr,
          definition: def,
          definition_status: status || (def ? 'confirmed' : 'needs_review'),
        });
      });
      return { entries: abbrEntries, empty_rows: 0, excluded: (state.part0Page && state.part0Page.excluded) ? state.part0Page.excluded.slice() : [] };
    }
    if (key === 'definitions') {
      var defEntries = [];
      sheet.querySelectorAll('.cpb-part0-def-row').forEach(function (rowEl) {
        var termEl = rowEl.querySelector('[data-part0-col="term"]');
        var defTextEl = rowEl.querySelector('[data-part0-col="definition"]');
        var term = termEl ? termEl.textContent.replace(/\u00a0/g, ' ').replace(/:$/, '').trim() : '';
        if (!term) return;
        defEntries.push({
          term: term,
          definition: defTextEl ? defTextEl.textContent.replace(/\u00a0/g, ' ').trim() : '',
        });
      });
      return { entries: defEntries, empty_rows: 0 };
    }
    return null;
  }

  function schedulePart0Save() {
    if (!state.editable || !state.part0Structured) return;
    if (state.part0SaveTimer) clearTimeout(state.part0SaveTimer);
    state.part0SaveTimer = setTimeout(function () {
      state.part0SaveTimer = null;
      flushPart0Save();
    }, 450);
  }

  function flushPart0Save() {
    if (!state.editable || !state.part0Structured) return;
    var page = extractPart0PageFromCanvas();
    if (!page) return;
    setStatus('Saving…', 'saving');
    apiPost('save_part0_page', {
      version_id: state.versionId,
      section_key: state.part0SectionKey,
      part0_page: page,
      headings: extractPart0HeadingsFromCanvas(),
    }).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Save failed');
      state.part0Page = res.part0_page || page;
      setStatus('Saved', 'saved');
    }).catch(showError);
  }

  function syncDefinitions(skipConfirm) {
    if (!skipConfirm && !confirm('Suggest Definitions and Terms from the manual content using AI?')) return;
    setStatus('Generating definitions…', 'saving');
    apiPost('regenerate_definitions', {
      version_id: state.versionId,
      section_id: state.sectionId,
    }).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Regenerate failed');
      var count = res.result && res.result.entries_count !== undefined ? res.result.entries_count : 0;
      state.part0Page = res.part0_page || state.part0Page;
      if (res.page_html) {
        canvasEl.innerHTML = res.page_html;
        wireCanvas();
        refreshPart0TypographyFromBookStyles();
        setStatus('Definitions updated (' + count + ' entries)', 'saved');
      } else {
        return loadSection(state.sectionId);
      }
    }).catch(showError);
  }

  function syncAbbreviations(skipConfirm) {
    if (!skipConfirm && !confirm('Regenerate the Index of Abbreviations from the entire manual?')) return;
    setStatus('Regenerating abbreviations…', 'saving');
    apiPost('regenerate_abbreviations', {
      version_id: state.versionId,
      section_id: state.sectionId,
    }).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Regenerate failed');
      var count = res.result && res.result.entries_count !== undefined ? res.result.entries_count : 0;
      var reviewCount = res.result && res.result.needs_review_count !== undefined ? res.result.needs_review_count : 0;
      state.part0Page = res.part0_page || state.part0Page;
      if (res.page_html) {
        canvasEl.innerHTML = res.page_html;
        wireCanvas();
        refreshPart0TypographyFromBookStyles();
        var msg = 'Abbreviations updated (' + count + ' entries)';
        if (reviewCount > 0) msg += ' — ' + reviewCount + ' need review';
        setStatus(msg, reviewCount > 0 ? 'warn' : 'saved');
      } else {
        return loadSection(state.sectionId);
      }
    }).catch(showError);
  }

  function wirePart0Page() {
    var sheet = canvasEl.querySelector('.cpb-sheet--part0');
    if (!sheet || sheet.getAttribute('data-part0-wired') === '1') return;
    sheet.setAttribute('data-part0-wired', '1');

    sheet.querySelectorAll('[data-part0-field], [data-part0-col]').forEach(function (field) {
      if (!state.editable || field.getAttribute('contenteditable') !== 'true') return;
      if (field.getAttribute('data-part0-field-wired') === '1') return;
      field.setAttribute('data-part0-field-wired', '1');
      field.addEventListener('input', schedulePart0Save);
      field.addEventListener('blur', function () {
        if (state.part0SaveTimer) {
          clearTimeout(state.part0SaveTimer);
          state.part0SaveTimer = null;
        }
        flushPart0Save();
      });
    });

    if (state.part0SectionKey === 'abbreviations') {
      sheet.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-abbr-action]');
        if (!btn || !state.editable) return;
        ev.preventDefault();
        var abbr = (btn.getAttribute('data-abbr') || '').trim().toUpperCase();
        if (!abbr) return;
        if (btn.getAttribute('data-abbr-action') === 'remove') {
          removeAbbreviationPermanently(abbr, btn.closest('.cpb-part0-abbr-row'));
        } else if (btn.getAttribute('data-abbr-action') === 'find') {
          findAbbreviationMentions(abbr);
        }
      });
    }
  }

  function removeAbbreviationPermanently(abbr, rowEl) {
    if (!confirm('Remove ' + abbr + ' from the abbreviations list permanently? It will not reappear when you Regenerate.')) return;
    if (!state.part0Page) state.part0Page = {};
    if (!Array.isArray(state.part0Page.excluded)) state.part0Page.excluded = [];
    if (state.part0Page.excluded.indexOf(abbr) < 0) state.part0Page.excluded.push(abbr);
    if (rowEl && rowEl.parentNode) rowEl.parentNode.removeChild(rowEl);
    flushPart0Save();
    setStatus(abbr + ' removed from list', 'saved');
  }

  function findAbbreviationMentions(abbr) {
    setStatus('Searching for ' + abbr + '…', 'saving');
    apiPost('find_abbreviation_mentions', {
      version_id: state.versionId,
      abbreviation: abbr,
    }).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Search failed');
      var mentions = res.mentions || [];
      if (!mentions.length) {
        setStatus(abbr + ' — no mentions found in manual content', 'warn');
        return;
      }
      var lines = mentions.map(function (m, idx) {
        return (idx + 1) + '. ' + (m.section_title || m.section_key || 'Section') + '\n   …' + (m.snippet || '') + '…';
      });
      var pick = window.prompt(abbr + ' appears in ' + mentions.length + ' place(s). Enter number to open section, or Cancel:\n\n' + lines.join('\n\n'));
      if (pick === null) {
        setStatus('Ready', 'saved');
        return;
      }
      var n = parseInt(pick, 10);
      if (!n || n < 1 || n > mentions.length) {
        setStatus('Ready', 'saved');
        return;
      }
      var target = mentions[n - 1];
      if (target && target.section_id) {
        loadSection(target.section_id);
        setStatus('Opened: ' + (target.section_title || abbr), 'saved');
      }
    }).catch(showError);
  }

  function refreshPart0TypographyFromBookStyles() {
    var sheet = canvasEl.querySelector('.cpb-sheet--part0');
    if (!sheet) return;
    sheet.querySelectorAll('[data-paragraph-style]').forEach(function (el) {
      if (el.classList.contains('cpb-lep-emphasis')) return;
      refreshBlockTypographyFromBookStyles(el);
    });
    sheet.querySelectorAll('.cpb-lep-emphasis').forEach(function (el) {
      el.style.fontWeight = '700';
      el.setAttribute('data-font-bold', '1');
    });
    var tableStyle = (state.bookStyles && state.bookStyles.table_styles && state.bookStyles.table_styles.standard)
      || defaultTableStyleDef();
    sheet.querySelectorAll('.cpb-part0-table thead th').forEach(function (cell) {
      applyBookTableRowStyleToCell(cell, tableStyle.header_row || defaultTableStyleDef().header_row);
    });
    sheet.querySelectorAll('.cpb-part0-table tbody td').forEach(function (cell) {
      applyBookTableRowStyleToCell(cell, tableStyle.body_row || defaultTableStyleDef().body_row);
    });
  }

  function refreshAnnexAdminTypographyFromBookStyles() {
    var sheet = canvasEl.querySelector('.cpb-sheet--annex-admin');
    if (!sheet) return;
    sheet.querySelectorAll('[data-paragraph-style]').forEach(function (el) {
      refreshBlockTypographyFromBookStyles(el);
    });
    var tableStyle = (state.bookStyles && state.bookStyles.table_styles && state.bookStyles.table_styles.standard)
      || defaultTableStyleDef();
    sheet.querySelectorAll('.cpb-annex-register-table thead th').forEach(function (cell) {
      applyBookTableRowStyleToCell(cell, tableStyle.header_row || defaultTableStyleDef().header_row);
    });
    sheet.querySelectorAll('.cpb-annex-register-table tbody td').forEach(function (cell) {
      applyBookTableRowStyleToCell(cell, tableStyle.body_row || defaultTableStyleDef().body_row);
    });
  }

  function syncLepParts(skipConfirm) {
    if (!skipConfirm && !confirm('Regenerate the Effective Parts table from the current manual structure?')) return;
    setStatus('Regenerating parts…', 'saving');
    apiPost('regenerate_lep_parts', {
      version_id: state.versionId,
      section_id: state.sectionId,
    }).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Regenerate failed');
      var count = res.result && res.result.parts_count !== undefined ? res.result.parts_count : 0;
      state.lepPage = res.lep_page || state.lepPage;
      if (res.page_html) {
        canvasEl.innerHTML = res.page_html;
        wireCanvas();
        setStatus('Parts updated (' + count + ' rows)', 'saved');
      } else {
        return loadSection(state.sectionId);
      }
    }).catch(showError);
  }

  function ensureLepSignModal() {
    if (state.lepSignModal) return state.lepSignModal;
    var modal = document.createElement('div');
    modal.className = 'cpb-lep-sign-modal';
    modal.hidden = true;
    modal.innerHTML = ''
      + '<div class="cpb-lep-sign-modal-backdrop" data-lep-sign-close="1"></div>'
      + '<div class="cpb-lep-sign-modal-panel" role="dialog" aria-labelledby="cpbLepSignTitle">'
      + '<h3 id="cpbLepSignTitle">E-signature</h3>'
      + '<p class="cpb-lep-sign-modal-hint">Enter your name and draw your signature below.</p>'
      + '<label class="cpb-lep-sign-field">Name<input type="text" id="cpbLepSignName" autocomplete="name"></label>'
      + '<label class="cpb-lep-sign-field">Function / title<input type="text" id="cpbLepSignTitleInput" autocomplete="organization-title"></label>'
      + '<div class="cpb-lep-sign-canvas-wrap"><canvas id="cpbLepSignCanvas" width="480" height="140"></canvas></div>'
      + '<div class="cpb-lep-sign-modal-actions">'
      + '<button type="button" class="cpb-tool-btn" id="cpbLepSignClear">Clear</button>'
      + '<button type="button" class="cpb-tool-btn" id="cpbLepSignCancel" data-lep-sign-close="1">Cancel</button>'
      + '<button type="button" class="cpb-tool-btn cpb-lep-sign-submit" id="cpbLepSignSubmit">Apply signature</button>'
      + '</div></div>';
    root.appendChild(modal);
    state.lepSignModal = modal;
    return modal;
  }

  function openLepSignatureModal(slotKey) {
    var modal = ensureLepSignModal();
    state.lepSignSlotKey = slotKey;
    var sheet = canvasEl.querySelector('.cpb-sheet--lep');
    var box = sheet ? sheet.querySelector('.cpb-lep-signatory[data-lep-slot="' + slotKey + '"]') : null;
    var nameInput = modal.querySelector('#cpbLepSignName');
    var titleInput = modal.querySelector('#cpbLepSignTitleInput');
    var canvas = modal.querySelector('#cpbLepSignCanvas');
    if (box && nameInput) {
      var nameEl = box.querySelector('[data-lep-field="name"]');
      var titleEl = box.querySelector('[data-lep-field="title"]');
      nameInput.value = nameEl ? nameEl.textContent.trim() : '';
      titleInput.value = titleEl ? titleEl.textContent.trim() : '';
    }
    modal.hidden = false;
    initLepSignCanvas(canvas);
    if (canvas && canvas._cpbClear) canvas._cpbClear();
  }

  function closeLepSignatureModal() {
    if (state.lepSignModal) state.lepSignModal.hidden = true;
    state.lepSignSlotKey = '';
  }

  function initLepSignCanvas(canvas) {
    if (!canvas || canvas.getAttribute('data-sign-wired') === '1') {
      if (canvas && canvas.getContext) {
        var ctx0 = canvas.getContext('2d');
        ctx0.fillStyle = '#fff';
        ctx0.fillRect(0, 0, canvas.width, canvas.height);
      }
      return;
    }
    canvas.setAttribute('data-sign-wired', '1');
    var ctx = canvas.getContext('2d');
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#0f172a';
    var drawing = false;

    function pos(e) {
      var rect = canvas.getBoundingClientRect();
      var clientX = e.touches ? e.touches[0].clientX : e.clientX;
      var clientY = e.touches ? e.touches[0].clientY : e.clientY;
      return {
        x: (clientX - rect.left) * (canvas.width / rect.width),
        y: (clientY - rect.top) * (canvas.height / rect.height),
      };
    }

    function clearCanvas() {
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
    }
    clearCanvas();
    canvas._cpbClear = clearCanvas;

    function start(e) {
      e.preventDefault();
      drawing = true;
      var p = pos(e);
      ctx.beginPath();
      ctx.moveTo(p.x, p.y);
    }
    function move(e) {
      if (!drawing) return;
      e.preventDefault();
      var p = pos(e);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
    }
    function end() {
      drawing = false;
    }

    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    canvas.addEventListener('mouseup', end);
    canvas.addEventListener('mouseleave', end);
    canvas.addEventListener('touchstart', start, { passive: false });
    canvas.addEventListener('touchmove', move, { passive: false });
    canvas.addEventListener('touchend', end);

    var modal = canvas.closest('.cpb-lep-sign-modal');
    if (modal && modal.getAttribute('data-sign-actions-wired') !== '1') {
      modal.setAttribute('data-sign-actions-wired', '1');
      modal.querySelector('#cpbLepSignClear').addEventListener('click', function () {
        if (canvas._cpbClear) canvas._cpbClear();
      });
      modal.querySelectorAll('[data-lep-sign-close]').forEach(function (btn) {
        btn.addEventListener('click', closeLepSignatureModal);
      });
      modal.querySelector('#cpbLepSignSubmit').addEventListener('click', submitLepSignature);
    }
  }

  function submitLepSignature() {
    if (!state.lepSignSlotKey) return;
    var modal = state.lepSignModal;
    if (!modal) return;
    var name = (modal.querySelector('#cpbLepSignName') || {}).value || '';
    var title = (modal.querySelector('#cpbLepSignTitleInput') || {}).value || '';
    var canvas = modal.querySelector('#cpbLepSignCanvas');
    if (!name.trim()) {
      alert('Please enter your name.');
      return;
    }
    if (!canvas) return;
    var dataUrl = canvas.toDataURL('image/png');
    setStatus('Applying signature…', 'saving');
    apiPost('sign_lep_slot', {
      version_id: state.versionId,
      section_id: state.sectionId,
      slot_key: state.lepSignSlotKey,
      name: name.trim(),
      title: title.trim(),
      signature_data_url: dataUrl,
    }).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Signature failed');
      closeLepSignatureModal();
      state.lepPage = res.lep_page || state.lepPage;
      if (res.page_html) {
        canvasEl.innerHTML = res.page_html;
        wireCanvas();
      }
      setStatus('Signature applied', 'saved');
    }).catch(showError);
  }

  function wireLepPage() {
    var sheet = canvasEl.querySelector('.cpb-sheet--lep');
    if (!sheet || sheet.getAttribute('data-lep-wired') === '1') return;
    sheet.setAttribute('data-lep-wired', '1');

    sheet.querySelectorAll('[data-lep-field]').forEach(function (field) {
      if (!state.editable || field.getAttribute('data-lep-field-wired') === '1') return;
      field.setAttribute('data-lep-field-wired', '1');
      field.addEventListener('input', scheduleLepSave);
      field.addEventListener('blur', function () {
        if (state.lepSaveTimer) {
          clearTimeout(state.lepSaveTimer);
          state.lepSaveTimer = null;
        }
        flushLepSave();
      });
    });

    sheet.querySelectorAll('[data-lep-sign]').forEach(function (btn) {
      if (btn.getAttribute('data-lep-sign-wired') === '1') return;
      btn.setAttribute('data-lep-sign-wired', '1');
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        openLepSignatureModal(btn.getAttribute('data-lep-sign') || '');
      });
    });
  }

  var HEADER_FONT_SIZES = [8, 9, 10, 11, 12, 14, 16, 18, 20, 22, 24];
  var HEADER_LOGO_HEIGHTS = [24, 32, 40, 48, 56, 64, 72, 80, 96, 120];
  var HEADER_ROW_HEIGHTS = [24, 26, 28, 30, 32, 34, 36, 40, 44, 48, 52, 56, 60, 64, 72];

  function headerFontSizeOptions(selected) {
    return HEADER_FONT_SIZES.map(function (size) {
      return '<option value="' + size + '"' + (size === selected ? ' selected' : '') + '>' + size + ' pt</option>';
    }).join('');
  }

  function headerLogoHeightOptions(selected) {
    return HEADER_LOGO_HEIGHTS.map(function (size) {
      return '<option value="' + size + '"' + (size === selected ? ' selected' : '') + '>' + size + ' px</option>';
    }).join('');
  }

  function headerRowHeightOptions(selected) {
    return HEADER_ROW_HEIGHTS.map(function (size) {
      return '<option value="' + size + '"' + (size === selected ? ' selected' : '') + '>' + size + ' px</option>';
    }).join('');
  }

  function headerColumnFromBand(band, prefix) {
    return {
      font_family: band[prefix + '_font_family'] || 'sans',
      font_size: band[prefix + '_font_size'] || 11,
      font_bold: !!band[prefix + '_font_bold'],
      font_italic: !!band[prefix + '_font_italic'],
      font_underline: !!band[prefix + '_font_underline'],
    };
  }

  function headerFontClass(fontFamily) {
    var key = String(fontFamily || 'sans').toLowerCase().replace(/[^a-z]/g, '');
    if (['serif', 'sans', 'mono', 'arial'].indexOf(key) === -1) key = 'sans';
    return 'cpb-font-' + key;
  }

  function headerCellStyleAttr(column, rowHeight) {
    var col = column || {};
    var size = parseInt(col.font_size, 10) || 11;
    var row = parseInt(rowHeight, 10) || 32;
    row = Math.max(20, Math.min(120, row));
    var padY = Math.max(2, Math.round((row - 14) / 2));
    var stack = FONT_STACKS[col.font_family] || FONT_STACKS.sans;
    var parts = [
      'font-size:' + size + 'pt',
      'font-weight:' + (col.font_bold ? '700' : '400'),
      'font-style:' + (col.font_italic ? 'italic' : 'normal'),
      'text-decoration:' + (col.font_underline ? 'underline' : 'none'),
      'font-family:' + stack + ' !important',
      'padding:' + padY + 'px 8px',
      'min-height:' + row + 'px',
      'line-height:1.45',
      'box-sizing:border-box',
    ];
    return ' style="' + parts.join(';') + '"';
  }

  function headerRowCellStyleAttr(rowHeight) {
    var row = parseInt(rowHeight, 10) || 32;
    row = Math.max(20, Math.min(120, row));
    var padY = Math.max(2, Math.round((row - 14) / 2));
    return ' style="padding:' + padY + 'px 8px;min-height:' + row + 'px;line-height:1.2;box-sizing:border-box;"';
  }

  function defaultHeaderTokens() {
    return [
      { token: '{page}', label: 'Page number', description: 'Current page (adaptive in e-reader/PDF)' },
      { token: '{page_total}', label: 'Total pages', description: 'Total page count' },
      { token: '{revision}', label: 'Revision number', description: 'Manual version label' },
      { token: '{date}', label: 'Publication date', description: 'Effective or release date' },
      { token: '{manual_code}', label: 'Manual code', description: 'Short manual identifier (e.g. OM)' },
      { token: '{book_title}', label: 'Manual title', description: 'Full manual title' },
      { token: '{part_title}', label: 'Part title', description: 'Current manual part' },
      { token: '{section_title}', label: 'Section title', description: 'Current section name' },
      { token: '{annex_number}', label: 'Annex number', description: 'Annex number (e.g. 01)' },
      { token: '{annex_title}', label: 'Annex title', description: 'Annex title without prefix' },
      { token: '{annex_revision}', label: 'Annex revision', description: 'Annex revision label' },
      { token: '{annex_revision_date}', label: 'Annex revision date', description: 'Annex revision date' },
    ];
  }

  function resolveHeaderTokensPreview(template) {
    var text = String(template || '');
    var ctx = state.headerPreviewTokens;
    if (ctx && typeof ctx === 'object' && Object.keys(ctx).length) {
      Object.keys(ctx).forEach(function (key) {
        text = text.split('{' + key + '}').join(String(ctx[key] != null ? ctx[key] : ''));
      });
      return text;
    }

    var v = state.versionInfo || {};
    var manualCode = v.manual_code || v.book_key || '';
    var map = {
      '{page}': '—',
      '{page_total}': '—',
      '{revision}': String(v.version_label || ''),
      '{date}': '—',
      '{manual_code}': String(manualCode),
      '{book_title}': String(v.book_title || ''),
      '{part_title}': '',
      '{section_title}': String(state.sectionTitle || ''),
      '{annex_number}': '',
      '{annex_title}': '',
      '{annex_revision}': '',
      '{annex_revision_date}': '',
    };
    Object.keys(map).forEach(function (token) {
      text = text.split(token).join(map[token]);
    });
    return text;
  }

  function previewHeaderHtml(header, footer) {
    var h = header || defaultPageHeader();
    var f = footer || defaultPageFooter();
    var logoHeight = parseInt(h.logo_max_height, 10) || 40;
    var headerRow = parseInt(h.row_height, 10) || 32;
    var footerRow = parseInt(f.row_height, 10) || 26;
    var logo = h.logo_url
      ? '<img class="cpb-page-header-logo" src="' + escapeHtml(h.logo_url) + '" alt="' + escapeHtml(h.logo_alt || '') + '" style="max-height:' + logoHeight + 'px;">'
      : '<span class="cpb-page-header-logo-placeholder">Logo</span>';
    var center = escapeHtml(resolveHeaderTokensPreview(h.center_text)).replace(/\n/g, '<br>');
    var right = escapeHtml(resolveHeaderTokensPreview(h.right_text)).replace(/\n/g, '<br>');
    var footerLeft = escapeHtml(resolveHeaderTokensPreview(f.left_text)).replace(/\n/g, '<br>');
    var footerCenter = escapeHtml(resolveHeaderTokensPreview(f.center_text)).replace(/\n/g, '<br>');
    var footerRight = escapeHtml(resolveHeaderTokensPreview(f.right_text)).replace(/\n/g, '<br>');
    return '<header class="cpb-page-header">'
      + '<table class="cpb-page-header-table" role="presentation"><tr>'
      + '<td class="cpb-page-header-cell cpb-page-header-cell--left"' + headerRowCellStyleAttr(headerRow) + '>' + logo + '</td>'
      + '<td class="cpb-page-header-cell cpb-page-header-cell--center ' + headerFontClass(h.center_font_family) + '"' + headerCellStyleAttr(headerColumnFromBand(h, 'center'), headerRow) + '>' + center + '</td>'
      + '<td class="cpb-page-header-cell cpb-page-header-cell--right ' + headerFontClass(h.right_font_family) + '"' + headerCellStyleAttr(headerColumnFromBand(h, 'right'), headerRow) + '>' + right + '</td>'
      + '</tr></table></header>'
      + (f.enabled ? '<footer class="cpb-page-footer">'
        + '<table class="cpb-page-header-table cpb-page-footer-table" role="presentation"><tr>'
        + '<td class="cpb-page-header-cell cpb-page-header-cell--left ' + headerFontClass(f.left_font_family) + '"' + headerCellStyleAttr(headerColumnFromBand(f, 'left'), footerRow) + '>' + footerLeft + '</td>'
        + '<td class="cpb-page-header-cell cpb-page-header-cell--center ' + headerFontClass(f.center_font_family) + '"' + headerCellStyleAttr(headerColumnFromBand(f, 'center'), footerRow) + '>' + footerCenter + '</td>'
        + '<td class="cpb-page-header-cell cpb-page-header-cell--right ' + headerFontClass(f.right_font_family) + '"' + headerCellStyleAttr(headerColumnFromBand(f, 'right'), footerRow) + '>' + footerRight + '</td>'
        + '</tr></table></footer>' : '');
  }

  function defaultTableStyleDef() {
    return {
      border_width: 'thin',
      border_color: '#94a3b8',
      cell_bg: '#ffffff',
      title_row: { font_family: 'sans', font_size: 11, color: '#0f2744', bg: '#e8eef6', font_bold: true, font_italic: false, font_underline: false },
      header_row: { font_family: 'sans', font_size: 10, color: '#0f172a', bg: '#f1f5f9', font_bold: true, font_italic: false, font_underline: false },
      body_row: { font_family: 'sans', font_size: 10, color: '#0f172a', bg: '', font_bold: false, font_italic: false, font_underline: false },
    };
  }

  function paragraphStyleDef(styleKey) {
    var styles = state.bookStyles || defaultBookStyles();
    styleKey = canonicalParagraphStyleKey(styleKey || 'body') || 'body';
    var def = (styles.paragraph_styles && styles.paragraph_styles[styleKey])
      || (styles.paragraph_styles && styles.paragraph_styles.body)
      || { font_family: 'serif', font_size: 11, color: '#0f172a', font_bold: false, font_italic: false, font_underline: false };
    return {
      font_family: def.font_family || 'serif',
      font_size: def.font_size || 11,
      color: def.color || '#0f172a',
      font_bold: !!def.font_bold,
      font_italic: !!def.font_italic,
      font_underline: !!def.font_underline,
    };
  }

  function typographyMatchesParagraphStyleDef(fields, styleKey) {
    var def = paragraphStyleDef(styleKey);
    var colorA = cssColorToHex(fields.text_color || '');
    var colorB = cssColorToHex(def.color || '#0f172a');
    return fields.font_family === (def.font_family || 'serif')
      && fields.font_size === (def.font_size || 11)
      && colorA === colorB
      && !!fields.font_bold === !!def.font_bold
      && !!fields.font_italic === !!def.font_italic
      && !!fields.font_underline === !!def.font_underline;
  }

  function readElementTypographyFields(el) {
    var styleKey = canonicalParagraphStyleKey(el.getAttribute('data-paragraph-style') || 'body') || 'body';
    var styleDef = paragraphStyleDef(styleKey);
    var fontFamily = el.getAttribute('data-font-family') || '';
    var fontSize = parseInt(el.getAttribute('data-font-size') || '0', 10) || 0;
    if (!fontFamily || !fontSize) {
      var bodyDef = paragraphStyleDef('body');
      if (!fontFamily) fontFamily = styleDef.font_family || bodyDef.font_family || 'serif';
      if (!fontSize) fontSize = styleDef.font_size || bodyDef.font_size || 11;
    }
    function readTriStateAttr(name, styleDefault) {
      var value = el.getAttribute(name);
      if (value === null || value === '') return !!styleDefault;
      return value === '1' || value === 'true';
    }
    return {
      font_family: fontFamily,
      font_size: fontSize,
      text_color: extractCellTextColor(el)
        || styleDef.color
        || paragraphStyleDef('body').color
        || '#0f172a',
      font_bold: readTriStateAttr('data-font-bold', styleDef.font_bold),
      font_italic: readTriStateAttr('data-font-italic', styleDef.font_italic),
      font_underline: readTriStateAttr('data-font-underline', styleDef.font_underline),
    };
  }

  function fontFamilyKeyFromStack(stack) {
    stack = String(stack || '').toLowerCase();
    if (stack.indexOf('courier') >= 0 || stack.indexOf('mono') >= 0) return 'mono';
    if (stack.indexOf('arial') >= 0) return 'arial';
    if (stack.indexOf('georgia') >= 0 || stack.indexOf('times') >= 0) return 'serif';
    if (stack.indexOf('system-ui') >= 0 || stack.indexOf('segoe') >= 0) return 'sans';
    return '';
  }

  function readEffectiveTypographyForElement(el) {
    var fields = readElementTypographyFields(el);
    var sel = window.getSelection();
    if (!sel || sel.rangeCount === 0 || !selectionInCanvas()) return fields;
    var anchor = sel.anchorNode;
    if (!anchor || !el.contains(anchor)) return fields;
    var walker = anchor.nodeType === 1 ? anchor : anchor.parentElement;
    while (walker && walker !== el) {
      if (walker.style) {
        if (walker.style.fontSize) {
          fields.font_size = parseInt(walker.style.fontSize, 10) || fields.font_size;
        }
        if (walker.style.color) {
          fields.text_color = walker.style.color || fields.text_color;
        }
        if (walker.style.fontFamily) {
          var key = fontFamilyKeyFromStack(walker.style.fontFamily);
          if (key) fields.font_family = key;
        }
      }
      walker = walker.parentElement;
    }
    return fields;
  }

  function elementHasInlineTypographyOverrides(el) {
    var found = false;
    el.querySelectorAll('span, font').forEach(function (node) {
      var s = node.style;
      if (s && (s.fontFamily || s.fontSize || s.color)) found = true;
    });
    return found;
  }

  function elementHasCustomTypography(el) {
    var styleKey = canonicalParagraphStyleKey(el.getAttribute('data-paragraph-style') || 'body') || 'body';
    var fields = readEffectiveTypographyForElement(el);
    if (!typographyMatchesParagraphStyleDef(fields, styleKey)) return true;
    return elementHasInlineTypographyOverrides(el);
  }

  function resolveParagraphStyleSelectValue(el) {
    if (!el) return 'body';
    var styleKey = el.getAttribute('data-paragraph-style');
    if (styleKey) {
      if (elementHasCustomTypography(el)) return 'custom';
      return canonicalParagraphStyleKey(styleKey) || 'body';
    }
    var fields = readEffectiveTypographyForElement(el);
    if (elementHasInlineTypographyOverrides(el)) return 'custom';
    if (typographyMatchesParagraphStyleDef(fields, 'body')) return 'body';
    return 'custom';
  }

  function setParagraphStyleSelectValue(value) {
    if (!paragraphStyleSelect) return;
    paragraphStyleSelect.value = value;
  }

  function updateParagraphStyleSelectForElement(el) {
    if (!el || !paragraphStyleSelect) return;
    setParagraphStyleSelectValue(resolveParagraphStyleSelectValue(el));
  }

  function unwrapElement(node) {
    if (!node || !node.parentNode) return;
    var parent = node.parentNode;
    while (node.firstChild) {
      parent.insertBefore(node.firstChild, node);
    }
    parent.removeChild(node);
  }

  function clearInlineTypographyInElement(el) {
    if (!el) return;
    el.querySelectorAll('span, font').forEach(function (node) {
      var style = node.style;
      var hasLegacyColor = node.tagName === 'FONT' && node.getAttribute('color');
      if (!style && !hasLegacyColor) return;
      if (hasLegacyColor || (style && (style.fontFamily || style.fontSize || style.color || style.fontWeight || style.fontStyle))) {
        unwrapElement(node);
      }
    });
  }

  function clearInlineTypographyInSelection(rootEl) {
    if (!hasTextSelectionInCanvas()) return;
    var sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) return;
    var range = sel.getRangeAt(0);
    if (!rootEl.contains(range.commonAncestorContainer)) return;
    rootEl.querySelectorAll('span, font').forEach(function (node) {
      var style = node.style;
      if (!style || !(style.fontFamily || style.fontSize || style.color || style.fontWeight || style.fontStyle)) {
        return;
      }
      try {
        if (!range.intersectsNode(node)) return;
      } catch (err) {
        return;
      }
      unwrapElement(node);
    });
  }

  function refreshBlockTypographyFromBookStyles(el) {
    if (!el || el.classList.contains('cpb-lep-emphasis')) return;
    var styleKey = canonicalParagraphStyleKey(el.getAttribute('data-paragraph-style') || 'body') || 'body';
    var def = paragraphStyleDef(styleKey);
    var fields = {
      font_family: el.getAttribute('data-font-family') || def.font_family || 'serif',
      font_size: parseInt(el.getAttribute('data-font-size') || String(def.font_size || 11), 10) || 11,
      text_color: el.getAttribute('data-text-color') || def.color || '#0f172a',
      font_bold: el.getAttribute('data-font-bold') === '1',
      font_italic: el.getAttribute('data-font-italic') === '1',
      font_underline: el.getAttribute('data-font-underline') === '1',
    };
    if (typographyMatchesParagraphStyleDef(fields, styleKey)) {
      applyTypographyToElement(el, {
        font_family: def.font_family || 'serif',
        font_size: def.font_size || 11,
        color: def.color || '#0f172a',
        font_bold: !!def.font_bold,
        font_italic: !!def.font_italic,
        font_underline: !!def.font_underline,
      }, styleKey, true);
    }
  }

  function resolveTypographyFromPayload(payload) {
    var styles = state.bookStyles || defaultBookStyles();
    var ps = (payload && payload.paragraph_style) || 'body';
    var def = paragraphStyleDef(ps);
    return {
      font_family: (payload && payload.font_family) || def.font_family || 'serif',
      font_size: (payload && payload.font_size) || def.font_size || 11,
      color: (payload && (payload.text_color || payload.color)) || def.color || '#0f172a',
      text_align: (payload && payload.text_align) || 'left',
      indent_level: (payload && payload.indent_level) || 0,
      font_bold: payload && Object.prototype.hasOwnProperty.call(payload, 'font_bold') ? !!payload.font_bold : !!def.font_bold,
      font_italic: payload && Object.prototype.hasOwnProperty.call(payload, 'font_italic') ? !!payload.font_italic : !!def.font_italic,
      font_underline: payload && Object.prototype.hasOwnProperty.call(payload, 'font_underline') ? !!payload.font_underline : !!def.font_underline,
    };
  }

  function extractStyleFields(blockEl, blockType) {
    var el = null;
    if (blockType === 'heading') el = blockEl.querySelector('.cpb-heading');
    else if (blockType === 'paragraph') el = blockEl.querySelector('.cpb-paragraph');
    else if (blockType === 'list') el = blockEl.querySelector('.cpb-list');
    if (!el) return {};
    var styleKey = canonicalParagraphStyleKey(el.getAttribute('data-paragraph-style') || 'body') || 'body';
    var fields = {
      paragraph_style: styleKey,
      font_family: el.getAttribute('data-font-family') || 'serif',
      text_align: el.getAttribute('data-text-align') || 'left',
      font_size: parseInt(el.getAttribute('data-font-size') || '11', 10) || 11,
      text_color: el.getAttribute('data-text-color') || '#0f172a',
      indent_level: parseInt(el.getAttribute('data-indent-level') || '0', 10) || 0,
    };
    var def = paragraphStyleDef(styleKey);
    var out = {
      paragraph_style: styleKey,
      text_align: fields.text_align,
      indent_level: fields.indent_level,
    };
    if (fields.font_family !== (def.font_family || 'serif')) {
      out.font_family = fields.font_family;
    }
    if (fields.font_size !== (def.font_size || 11)) {
      out.font_size = fields.font_size;
    }
    if (fields.text_color !== (def.color || '#0f172a')) {
      out.text_color = fields.text_color;
    }
    if (styleKey === 'regulatory_reference') {
      out.regulatory_ref = el.getAttribute('data-regulatory-ref') || '';
    }
    return out;
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
      applyPageHtmlFromResponse(res.page_html);
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

  function splitMergedListItemText(text) {
    text = String(text || '').replace(/\u00a0/g, ' ').trim();
    if (!text) return [];
    var lines = text.split(/\n+/).map(function (line) { return line.trim(); }).filter(Boolean);
    if (lines.length > 1) return lines;
    if (/[•·▪‣]\s/.test(text)) {
      return text.split(/\s*[•·▪‣]\s+/).map(function (part) { return part.trim(); }).filter(Boolean);
    }
    if (/\.\s+(?=[A-Z0-9(])/.test(text) && text.length > 120) {
      return text.split(/\.\s+(?=[A-Z0-9(])/).map(function (part, idx, arr) {
        var chunk = part.trim();
        if (!chunk) return '';
        if (idx < arr.length - 1 && !/[.!?]$/.test(chunk)) chunk += '.';
        return chunk;
      }).filter(Boolean);
    }
    return [text];
  }

  function normalizeListItemsFromElement(list) {
    var items = [];
    if (!list) return items;
    var lis = list.querySelectorAll('li');
    if (lis.length === 1) {
      return splitMergedListItemText(lis[0].textContent);
    }
    lis.forEach(function (li) {
      var t = li.textContent.replace(/\u00a0/g, ' ').trim();
      if (t) items.push(t);
    });
    return items;
  }

  function repairListDomStructure(list) {
    if (!list || (list.tagName !== 'UL' && list.tagName !== 'OL')) return false;
    var items = normalizeListItemsFromElement(list);
    if (items.length <= 1) return false;
    var lis = list.querySelectorAll('li');
    if (lis.length !== 1) return false;
    list.innerHTML = items.map(function (item) {
      return '<li>' + item.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</li>';
    }).join('');
    return true;
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
      repairListDomStructure(list);
      var ordered = list && list.tagName === 'OL';
      var items = normalizeListItemsFromElement(list);
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
        rotation_deg: figure ? parseInt(figure.getAttribute('data-rotation-deg') || '0', 10) || 0 : 0,
      };
    }
    if (blockType === 'callout') {
      var titleEl = blockEl.querySelector('.cpb-callout-title');
      var textEl = blockEl.querySelector('.cpb-callout-text');
      var calloutRoot = blockEl.querySelector('.cpb-callout');
      var calloutType = calloutRoot ? (calloutRoot.getAttribute('data-callout-type') || 'warning') : 'warning';
      return {
        callout_type: calloutType,
        title: titleEl ? stripEditorChromeFromHtml(titleEl.innerHTML) : calloutType.toUpperCase(),
        text: textEl ? stripEditorChromeFromHtml(textEl.innerHTML) : '',
        title_font_family: titleEl ? (titleEl.getAttribute('data-font-family') || '') : '',
        title_font_size: titleEl && titleEl.getAttribute('data-font-size') ? parseInt(titleEl.getAttribute('data-font-size'), 10) : 0,
        title_text_color: titleEl ? extractCellTextColor(titleEl) : '',
        text_font_family: textEl ? (textEl.getAttribute('data-font-family') || '') : '',
        text_font_size: textEl && textEl.getAttribute('data-font-size') ? parseInt(textEl.getAttribute('data-font-size'), 10) : 0,
        text_text_color: textEl ? extractCellTextColor(textEl) : '',
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
        rememberStyleTarget();
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
        if (textColorInput) {
          var cellColor = extractCellTextColor(cell);
          if (cellColor) textColorInput.value = cellColor;
        }
        var textColorControl = blockEl.querySelector('[data-table-action="cell-text-color"]');
        if (textColorControl) {
          textColorControl.value = extractCellTextColor(cell) || '#0f172a';
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
    return cell.getAttribute('data-font-family') || '';
  }

  function extractCellFontSize(cell) {
    var size = cell.getAttribute('data-font-size');
    return size ? (parseInt(size, 10) || 0) : 0;
  }

  function cssColorToHex(color) {
    color = String(color || '').trim();
    if (!color) return '';
    if (color.charAt(0) === '#') return color.toLowerCase();
    var match = color.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i);
    if (!match) return color;
    function hex(n) {
      var s = parseInt(n, 10).toString(16);
      return s.length === 1 ? '0' + s : s;
    }
    return '#' + hex(match[1]) + hex(match[2]) + hex(match[3]);
  }

  function extractCellTextColor(cell) {
    if (!cell) return '';
    var fromAttr = cell.getAttribute('data-text-color');
    if (fromAttr) return fromAttr;
    if (cell.style && cell.style.color) return cssColorToHex(cell.style.color);
    var coloredSpan = cell.querySelector('span[style*="color"], font[color]');
    if (coloredSpan) {
      if (coloredSpan.style && coloredSpan.style.color) return cssColorToHex(coloredSpan.style.color);
      if (coloredSpan.getAttribute('color')) return cssColorToHex(coloredSpan.getAttribute('color'));
    }
    return '';
  }

  function extractCellHtml(cell) {
    if (!cell) return '';
    if (cell.tagName === 'TH') {
      var textEl = cell.querySelector('.cpb-th-text');
      return stripEditorChromeFromHtml(textEl ? textEl.innerHTML : cell.innerHTML);
    }
    return stripEditorChromeFromHtml(cell.innerHTML);
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
      cell.style.removeProperty('letter-spacing');
      cell.style.removeProperty('text-transform');
    }
    if (opts.size) {
      cell.style.setProperty('font-size', opts.size + 'pt', 'important');
      cell.setAttribute('data-font-size', String(opts.size));
    }
    if (opts.color) {
      cell.style.setProperty('color', opts.color, 'important');
      cell.style.setProperty('-webkit-text-fill-color', opts.color, 'important');
      cell.setAttribute('data-text-color', opts.color);
    }
    if (opts.align) {
      cell.style.textAlign = opts.align;
      cell.setAttribute('data-cell-align', opts.align);
    }
  }

  function applyTypographyToTableCell(cell, typo) {
    if (!cell) return;
    clearInlineTypographyInElement(cell);
    applyStyleToTableCell(cell, {
      font: typo.font_family,
      size: typo.font_size,
      color: typo.color,
    });
    applyTypographyDecorationToElement(cell, typo);
    cell.style.setProperty('font-weight', typo.font_bold ? '700' : '400', 'important');
    cell.style.setProperty('font-style', typo.font_italic ? 'italic' : 'normal', 'important');
    cell.style.setProperty('text-decoration', typo.font_underline ? 'underline' : 'none', 'important');
  }

  function resolveTableCellForStyle() {
    if (state.lastStyleTarget && state.lastStyleTarget.type === 'table-cell' && isLiveStyleTarget(state.lastStyleTarget)) {
      return state.lastStyleTarget;
    }
    var sel = window.getSelection();
    if (sel && sel.anchorNode) {
      var node = sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentElement;
      if (node) {
        var cell = node.closest('.cpb-table th, .cpb-table td');
        if (cell && cell.isContentEditable && canvasEl.contains(cell)) {
          var blockEl = cell.closest('.cpb-block');
          if (blockEl) return { block: blockEl, el: cell, type: 'table-cell' };
        }
      }
    }
    if (state.focusedTableCell && state.focusedTableCell.isContentEditable && canvasEl.contains(state.focusedTableCell)) {
      var block = state.focusedTableCell.closest('.cpb-block');
      if (block) return { block: block, el: state.focusedTableCell, type: 'table-cell' };
    }
    return null;
  }

  function applyTableCellTextColor(cell, color) {
    if (!cell || !color) return;
    clearInlineTypographyInElement(cell);
    cell.style.setProperty('color', color, 'important');
    cell.style.setProperty('-webkit-text-fill-color', color, 'important');
    cell.setAttribute('data-text-color', color);
    var blockEl = cell.closest('.cpb-block');
    if (blockEl) {
      var textColorControl = blockEl.querySelector('[data-table-action="cell-text-color"]');
      if (textColorControl) textColorControl.value = color;
    }
  }

  function applyColorToTableCell(cell, color) {
    if (!cell || !color) return;
    restoreSelectionRange();
    if (hasTextSelectionInCanvas() && !selectionCoversElementText(cell)) {
      cell.focus();
      restoreSelectionRange();
      if (applyInlineStyleToSelection({ color: color })) {
        cell.setAttribute('data-text-color', color);
        return;
      }
    }
    applyTableCellTextColor(cell, color);
  }

  function applyFontToTableCell(cell, font) {
    if (!cell || !font) return;
    var stack = FONT_STACKS[font] || '';
    cell.focus();
    restoreSelectionRange();
    if (hasTextSelectionInCanvas() && !selectionCoversElementText(cell)) {
      if (applyInlineStyleToSelection({ fontFamily: stack })) return;
    }
    clearInlineTypographyInElement(cell);
    applyStyleToTableCell(cell, { font: font });
  }

  function applySizeToTableCell(cell, size) {
    if (!cell || !size) return;
    cell.focus();
    restoreSelectionRange();
    if (hasTextSelectionInCanvas() && !selectionCoversElementText(cell)) {
      if (applyInlineStyleToSelection({ fontSize: size + 'pt' })) return;
    }
    clearInlineTypographyInElement(cell);
    applyStyleToTableCell(cell, { size: size });
  }

  function applyTypographyToCalloutElement(el, typo) {
    if (!el) return;
    clearInlineTypographyInElement(el);
    FONT_CLASSES.forEach(function (cls) { el.classList.remove(cls); });
    el.classList.add('cpb-font-' + typo.font_family);
    el.setAttribute('data-font-family', typo.font_family);
    el.setAttribute('data-font-size', String(typo.font_size));
    el.setAttribute('data-text-color', typo.color);
    el.style.fontFamily = FONT_STACKS[typo.font_family] || FONT_STACKS.serif;
    el.style.fontSize = typo.font_size + 'pt';
    el.style.color = typo.color;
    applyTypographyDecorationToElement(el, typo);
  }

  function applyImageRotation(figure, img, deg) {
    deg = ((deg % 360) + 360) % 360;
    if (deg !== 0 && deg !== 90 && deg !== 180 && deg !== 270) deg = 0;
    if (deg === 0) {
      figure.removeAttribute('data-rotation-deg');
      img.style.transform = '';
      img.removeAttribute('data-rotation-deg');
      return;
    }
    figure.setAttribute('data-rotation-deg', String(deg));
    img.setAttribute('data-rotation-deg', String(deg));
    img.style.transform = 'rotate(' + deg + 'deg)';
  }

  function rotateImageBlock(blockEl) {
    var figure = blockEl.querySelector('.cpb-image');
    var img = blockEl.querySelector('.cpb-image img');
    if (!figure || !img) return;
    pushUndo();
    var current = parseInt(figure.getAttribute('data-rotation-deg') || '0', 10) || 0;
    applyImageRotation(figure, img, current + 90);
    scheduleSave(blockEl);
    flushSave(blockEl);
  }

  function wireImageBlock(blockEl) {
    var figure = blockEl.querySelector('.cpb-image');
    if (!figure) return;
    var pct = parseInt(figure.getAttribute('data-width-pct') || '100', 10);
    if (!figure.style.width) figure.style.width = pct + '%';
    var img = figure.querySelector('img');
    if (img) {
      applyImageRotation(figure, img, parseInt(figure.getAttribute('data-rotation-deg') || '0', 10) || 0);
    }
    var rotateBtn = figure.querySelector('.cpb-image-rotate');
    if (rotateBtn && rotateBtn.getAttribute('data-wired') !== '1') {
      rotateBtn.setAttribute('data-wired', '1');
      rotateBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (!state.editable) return;
        rotateImageBlock(blockEl);
      });
    }
  }

  function extractTablePayload(blockEl) {
    var table = blockEl.querySelector('table');
    var wrap = tableWrap(blockEl);
    var titleCell = blockEl.querySelector('tr[data-title-row] td');
    var title = '';
    var headers = [];
    var rows = [];
    var colWidths = [];
    var headerBg = [];
    var cellBg = [];
    var headerAlign = [];
    var cellAlign = [];
    var headerFontFamily = [];
    var headerFontSize = [];
    var headerTextColor = [];
    var cellFontFamily = [];
    var cellFontSize = [];
    var cellTextColor = [];
    var headerColspans = [];
    var rowColspans = [];
    var titleAlign = 'center';
    var titleFontFamily = '';
    var titleFontSize = 0;
    var titleTextColor = '';
    var tableBlock = blockEl.querySelector('.cpb-table-block');
    var tableAlign = tableBlock ? (tableBlock.getAttribute('data-table-align') || 'left') : 'left';

    if (table) {
      var head = tableHeaderRow(blockEl);
      if (head) {
        head.querySelectorAll('th').forEach(function (th) {
          headers.push(extractCellHtml(th));
          headerColspans.push(parseInt(th.getAttribute('colspan') || '1', 10) || 1);
          headerBg.push(extractCellBg(th));
          headerAlign.push(extractCellAlign(th));
          headerFontFamily.push(extractCellFontFamily(th));
          headerFontSize.push(extractCellFontSize(th));
          headerTextColor.push(extractCellTextColor(th));
        });
      }
      if (titleCell) {
        title = extractCellHtml(titleCell);
        titleAlign = extractCellAlign(titleCell);
        titleFontFamily = extractCellFontFamily(titleCell);
        titleFontSize = extractCellFontSize(titleCell);
        titleTextColor = extractCellTextColor(titleCell);
      }
      table.querySelectorAll('tbody[data-table-part="body"] tr').forEach(function (tr) {
        var line = [];
        var bgLine = [];
        var alignLine = [];
        var fontLine = [];
        var sizeLine = [];
        var colorLine = [];
        var spanLine = [];
        tr.querySelectorAll('td').forEach(function (td) {
          line.push(extractCellHtml(td));
          spanLine.push(parseInt(td.getAttribute('colspan') || '1', 10) || 1);
          bgLine.push(extractCellBg(td));
          alignLine.push(extractCellAlign(td));
          fontLine.push(extractCellFontFamily(td));
          sizeLine.push(extractCellFontSize(td));
          colorLine.push(extractCellTextColor(td));
        });
        if (line.length) {
          rows.push(line);
          rowColspans.push(spanLine);
          cellBg.push(bgLine);
          cellAlign.push(alignLine);
          cellFontFamily.push(fontLine);
          cellFontSize.push(sizeLine);
          cellTextColor.push(colorLine);
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
      header_colspans: headerColspans,
      rows: rows,
      row_colspans: rowColspans,
      col_widths: colWidths,
      border_width: wrap ? normalizeBorderWidth(wrap.getAttribute('data-border-width') || 'medium') : 'medium',
      border_color: wrap ? (wrap.getAttribute('data-border-color') || '#94a3b8') : '#94a3b8',
      title_bg: titleCell ? extractCellBg(titleCell) : '',
      header_bg: headerBg,
      cell_bg: cellBg,
      title_align: titleAlign,
      title_font_family: titleFontFamily,
      title_font_size: titleFontSize,
      title_text_color: titleTextColor,
      header_align: headerAlign,
      header_font_family: headerFontFamily,
      header_font_size: headerFontSize,
      header_text_color: headerTextColor,
      cell_align: cellAlign,
      cell_font_family: cellFontFamily,
      cell_font_size: cellFontSize,
      cell_text_color: cellTextColor,
      table_align: tableAlign,
      table_style_kind: tableBlock ? (tableBlock.getAttribute('data-table-style-kind') || 'text') : 'text',
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

  function setCellHtml(cell, html) {
    if (!cell) return;
    if (cell.tagName === 'TH') {
      var textEl = cell.querySelector('.cpb-th-text');
      if (textEl) {
        textEl.innerHTML = html || '';
      } else {
        cell.innerHTML = html || '';
      }
      return;
    }
    cell.innerHTML = html || '';
  }

  function mergeCellHtml(left, right) {
    left = String(left || '').trim();
    right = String(right || '').trim();
    if (!right) return left;
    if (!left) return right;
    return left + ' ' + right;
  }

  function createTableHeaderCell(colIndex) {
    var th = document.createElement('th');
    th.contentEditable = 'true';
    th.setAttribute('data-col-index', String(colIndex));
    th.innerHTML = '<span class="cpb-th-text"></span>'
      + '<span class="cpb-col-resize" data-col-index="' + colIndex + '" title="Resize column"></span>';
    return th;
  }

  function createTableBodyCell() {
    var td = document.createElement('td');
    td.contentEditable = 'true';
    return td;
  }

  function tableMergeCellRight(blockEl) {
    var cell = state.focusedTableCell;
    if (!cell || !blockEl.contains(cell)) {
      setStatus('Click a table cell first', 'error');
      return false;
    }
    if (cell.closest('[data-title-row]')) {
      setStatus('Use column tools for the title row', 'error');
      return false;
    }
    var row = cell.parentElement;
    if (!row) return false;
    var idx = cell.cellIndex;
    if (idx < 0 || idx >= row.cells.length - 1) {
      setStatus('No cell to the right to merge', 'error');
      return false;
    }
    var next = row.cells[idx + 1];
    var span1 = parseInt(cell.getAttribute('colspan') || '1', 10) || 1;
    var span2 = parseInt(next.getAttribute('colspan') || '1', 10) || 1;
    setCellHtml(cell, mergeCellHtml(extractCellHtml(cell), extractCellHtml(next)));
    cell.colSpan = span1 + span2;
    next.remove();
    wireTableCellFocus(blockEl);
    if (cell.tagName === 'TH') {
      wireTableResize(blockEl);
    }
    setStatus('Cells merged', 'saved');
    return true;
  }

  function tableUnmergeCell(blockEl) {
    var cell = state.focusedTableCell;
    if (!cell || !blockEl.contains(cell)) {
      setStatus('Click a merged cell first', 'error');
      return false;
    }
    if (cell.closest('[data-title-row]')) {
      setStatus('Cannot unmerge the title row', 'error');
      return false;
    }
    var span = parseInt(cell.getAttribute('colspan') || '1', 10) || 1;
    if (span <= 1) {
      setStatus('This cell is not merged', 'error');
      return false;
    }
    cell.colSpan = 1;
    var insertAfter = cell;
    for (var i = 1; i < span; i++) {
      var extra = cell.tagName === 'TH'
        ? createTableHeaderCell(cell.cellIndex + i)
        : createTableBodyCell();
      insertAfter.parentElement.insertBefore(extra, insertAfter.nextSibling);
      insertAfter = extra;
    }
    wireTableCellFocus(blockEl);
    if (cell.tagName === 'TH') {
      wireTableResize(blockEl);
    }
    setStatus('Cell split into ' + span + ' columns', 'saved');
    return true;
  }

  function tableMoveRow(blockEl, direction) {
    var tbody = tableBody(blockEl);
    if (!tbody) return false;
    var tr = null;
    if (state.focusedTableCell && tbody.contains(state.focusedTableCell)) {
      tr = state.focusedTableCell.closest('tr');
    }
    if (!tr) {
      setStatus('Click a row cell first', 'error');
      return false;
    }
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    var idx = rows.indexOf(tr);
    if (idx < 0) return false;
    if (direction === 'up') {
      if (idx <= 0) {
        setStatus('Row is already at the top', 'error');
        return false;
      }
      tbody.insertBefore(tr, rows[idx - 1]);
      setStatus('Row moved up', 'saved');
      return true;
    }
    if (idx >= rows.length - 1) {
      setStatus('Row is already at the bottom', 'error');
      return false;
    }
    tbody.insertBefore(rows[idx + 1], tr);
    setStatus('Row moved down', 'saved');
    return true;
  }

  function tableAddRow(blockEl) {
    var tbody = tableBody(blockEl);
    if (!tbody) return;
    var tr = document.createElement('tr');
    var head = tableHeaderRow(blockEl);
    var colCount = head ? head.cells.length : tableColCount(blockEl);
    for (var i = 0; i < colCount; i++) {
      tr.appendChild(createTableBodyCell());
    }
    tbody.appendChild(tr);
    wireTableCellFocus(blockEl);
    setStatus('Row added', 'saved');
  }

  function tableDelRow(blockEl) {
    var tbody = tableBody(blockEl);
    if (!tbody) return false;
    var rows = tbody.querySelectorAll('tr');
    if (rows.length <= 1) {
      setStatus('Table must keep at least one row', 'error');
      return false;
    }
    var target = null;
    if (state.focusedTableCell && tbody.contains(state.focusedTableCell)) {
      target = state.focusedTableCell.closest('tr');
    }
    if (!target) {
      setStatus('Click a row cell first', 'error');
      return false;
    }
    var rowText = target.textContent.replace(/\s+/g, ' ').trim();
    if (rowText && !confirm('Delete this entire table row?')) {
      return false;
    }
    target.remove();
    state.focusedTableCell = null;
    setStatus('Row deleted', 'saved');
    return true;
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
    var sel = window.getSelection();
    if (sel && sel.anchorNode) {
      var node = sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentElement;
      if (node) {
        var cellFromSel = node.closest('.cpb-table th, .cpb-table td');
        if (cellFromSel && cellFromSel.isContentEditable && canvasEl.contains(cellFromSel)) {
          state.focusedTableCell = cellFromSel;
          var blockFromSel = cellFromSel.closest('.cpb-block');
          if (blockFromSel) return { block: blockFromSel, el: cellFromSel, type: 'table-cell' };
        }
      }
    }
    var focused = document.activeElement;
    if (focused && focused.closest) {
      var cell = focused.closest('.cpb-table th, .cpb-table td');
      if (cell && cell.isContentEditable && canvasEl.contains(cell)) {
        state.focusedTableCell = cell;
        var block = cell.closest('.cpb-block');
        if (block) return { block: block, el: cell, type: 'table-cell' };
      }
    }
    if (state.focusedTableCell && state.focusedTableCell.isContentEditable && canvasEl.contains(state.focusedTableCell)) {
      var rememberedBlock = state.focusedTableCell.closest('.cpb-block');
      if (rememberedBlock) {
        return { block: rememberedBlock, el: state.focusedTableCell, type: 'table-cell' };
      }
    }
    return null;
  }

  function styleTargetFromRowChrome(block, row) {
    if (!block || !row) return null;
    var heading = row.querySelector('.cpb-heading');
    var paragraph = row.querySelector('.cpb-paragraph');
    if (heading) return { block: block, el: heading, type: 'heading' };
    if (paragraph) return { block: block, el: paragraph, type: 'paragraph' };
    return null;
  }

  function styleTargetFromNode(block, node, focused) {
    if (!block || !node) return null;
    var row = node.closest && node.closest('.cpb-paragraph-row, .cpb-heading-row');
    if (row && node.closest && (node.closest('.cpb-regulatory-ref') || node.closest('.cpb-section-number'))) {
      var fromChrome = styleTargetFromRowChrome(block, row);
      if (fromChrome) return fromChrome;
    }
    return blockStyleTarget(block, node, focused);
  }

  function blockStyleTarget(block, node, focused) {
    if (!block) return null;
    var calloutTitle = block.querySelector('.cpb-callout-title');
    var calloutText = block.querySelector('.cpb-callout-text');
    if (calloutTitle && (calloutTitle.contains(node) || node === calloutTitle
      || (focused && focused.closest && focused.closest('.cpb-callout-title')))) {
      return { block: block, el: calloutTitle, type: 'callout-title' };
    }
    if (calloutText && (calloutText.contains(node) || node === calloutText
      || (focused && focused.closest && focused.closest('.cpb-callout-text')))) {
      return { block: block, el: calloutText, type: 'callout-text' };
    }
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
        var styleTarget = styleTargetFromNode(block, node, null);
        if (styleTarget) return styleTarget;
      }
    }
    var focused = document.activeElement;
    if (focused && focused.closest) {
      var blockEl = focused.closest('.cpb-block');
      var focusedTarget = styleTargetFromNode(blockEl, focused, focused);
      if (focusedTarget) return focusedTarget;
    }
    return null;
  }

  function syncToolbarFromTarget(target) {
    if (!target) return;
    if (target.type === 'table-cell') {
      var cellEffective = readEffectiveTypographyForElement(target.el);
      if (fontSelect) fontSelect.value = cellEffective.font_family;
      if (fontSizeSelect) fontSizeSelect.value = String(cellEffective.font_size);
      if (textColorInput) textColorInput.value = cellEffective.text_color;
      updateParagraphStyleSelectForElement(target.el);
      if (regulatoryRefInput) regulatoryRefInput.hidden = true;
      return;
    }
    if (target.type === 'heading' || target.type === 'paragraph' || target.type === 'list') {
      var ps = canonicalParagraphStyleKey(target.el.getAttribute('data-paragraph-style') || 'body');
      var effective = readEffectiveTypographyForElement(target.el);
      var blockId = parseInt(target.block.getAttribute('data-block-id') || '0', 10);
      setParagraphStyleSelectValue(resolveParagraphStyleSelectValue(target.el));
      if (fontSelect) fontSelect.value = effective.font_family;
      if (fontSizeSelect) fontSizeSelect.value = String(effective.font_size);
      if (textColorInput) textColorInput.value = effective.text_color;
      updateRegulatoryRefFieldVisibility(ps, target.el, blockId);
      return;
    }
    if (target.type === 'callout-title' || target.type === 'callout-text') {
      var calloutEffective = readEffectiveTypographyForElement(target.el);
      setParagraphStyleSelectValue(resolveParagraphStyleSelectValue(target.el));
      if (fontSelect) fontSelect.value = calloutEffective.font_family;
      if (fontSizeSelect) fontSizeSelect.value = String(calloutEffective.font_size);
      if (textColorInput) textColorInput.value = calloutEffective.text_color;
      if (regulatoryRefInput) regulatoryRefInput.hidden = true;
      return;
    }
  }

  function rememberStyleTarget() {
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
    var toolbarFocused = !!(toolbarEl && ae && toolbarEl.contains(ae));
    if (toolbarFocused) {
      restoreSelectionRange();
      if (isLiveStyleTarget(state.lastStyleTarget)) {
        return state.lastStyleTarget;
      }
      var tableTarget = resolveTableCellForStyle();
      if (tableTarget) {
        state.lastStyleTarget = tableTarget;
        return tableTarget;
      }
      return null;
    }
    var live = getActiveStyleTargetFromEditor();
    if (live) {
      state.lastStyleTarget = live;
      return live;
    }
    return isLiveStyleTarget(state.lastStyleTarget) ? state.lastStyleTarget : null;
  }

  function getFocusedBlock() {
    var target = getActiveStyleTargetFromEditor();
    if (target && target.block && isConnectedEl(target.block)) return target.block;
    if (isLiveStyleTarget(state.lastStyleTarget)) return state.lastStyleTarget.block;
    var ae = document.activeElement;
    if (ae && ae.closest) {
      var block = ae.closest('.cpb-block');
      if (block && isConnectedEl(block)) return block;
    }
    return null;
  }

  function hasTextSelectionInCanvas() {
    var sel = window.getSelection();
    return !!(sel && sel.rangeCount > 0 && !sel.isCollapsed && selectionInCanvas());
  }

  function selectionCoversElementText(el) {
    var sel = window.getSelection();
    if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return false;
    if (!el.contains(sel.anchorNode)) return false;
    var selected = String(sel.toString()).replace(/\u00a0/g, ' ').trim();
    var full = String(el.textContent || '').replace(/\u00a0/g, ' ').trim();
    return selected !== '' && selected === full;
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
      try {
        var extracted = range.extractContents();
        span.appendChild(extracted);
        range.insertNode(span);
      } catch (err2) {
        return false;
      }
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

  function applyTypographyDecorationToElement(el, typo) {
    if (!el || !typo) return;
    el.style.fontWeight = typo.font_bold ? '700' : '400';
    el.style.fontStyle = typo.font_italic ? 'italic' : 'normal';
    el.style.textDecoration = typo.font_underline ? 'underline' : 'none';
    el.setAttribute('data-font-bold', typo.font_bold ? '1' : '0');
    el.setAttribute('data-font-italic', typo.font_italic ? '1' : '0');
    el.setAttribute('data-font-underline', typo.font_underline ? '1' : '0');
  }

  function applyBookTableRowStyleToCell(cell, rowStyle) {
    if (!cell || !rowStyle) return;
    var typo = {
      font_family: rowStyle.font_family || 'serif',
      font_size: rowStyle.font_size || 10,
      color: rowStyle.color || '#0f172a',
      font_bold: !!rowStyle.font_bold,
      font_italic: !!rowStyle.font_italic,
      font_underline: !!rowStyle.font_underline,
    };
    FONT_CLASSES.forEach(function (cls) { cell.classList.remove(cls); });
    cell.classList.add('cpb-font-' + typo.font_family);
    cell.setAttribute('data-font-family', typo.font_family);
    cell.setAttribute('data-font-size', String(typo.font_size));
    cell.setAttribute('data-text-color', typo.color);
    cell.style.fontFamily = FONT_STACKS[typo.font_family] || FONT_STACKS.serif;
    cell.style.setProperty('font-family', FONT_STACKS[typo.font_family] || FONT_STACKS.serif, 'important');
    cell.style.setProperty('font-size', typo.font_size + 'pt', 'important');
    cell.style.setProperty('color', typo.color, 'important');
    cell.style.setProperty('-webkit-text-fill-color', typo.color, 'important');
    applyTypographyDecorationToElement(cell, typo);
    cell.style.setProperty('font-weight', typo.font_bold ? '700' : '400', 'important');
    cell.style.setProperty('font-style', typo.font_italic ? 'italic' : 'normal', 'important');
    cell.style.setProperty('text-decoration', typo.font_underline ? 'underline' : 'none', 'important');
  }

  function refreshTocTypographyFromBookStyles() {
    var titleDef = paragraphStyleDef('title');
    var titleColor = titleDef.color || '#0f2744';
    var styleKeys = ['title', 'subtitle_1', 'subtitle_2', 'subtitle_3', 'subtitle_4', 'body'];
    canvasEl.querySelectorAll('.cpb-toc-row[data-toc-style]').forEach(function (row) {
      var styleKey = row.getAttribute('data-toc-style') || 'body';
      var def = paragraphStyleDef(styleKey);
      var size = Math.max(6, (parseInt(def.font_size, 10) || 11) - 4);
      row.style.color = titleColor;
      row.style.fontSize = size + 'pt';
      row.setAttribute('data-text-color', titleColor);
      row.setAttribute('data-font-size', String(size));
      row.querySelectorAll('.cpb-toc-label, .cpb-toc-page, .cpb-toc-link').forEach(function (el) {
        el.style.color = titleColor;
        el.style.fontSize = size + 'pt';
      });
    });
  }

  function refreshCalloutTypographyFromBookStyles() {
    canvasEl.querySelectorAll('.cpb-callout').forEach(function (callout) {
      var type = callout.getAttribute('data-callout-type') || 'warning';
      var def = calloutStyleDef(type);
      callout.style.borderColor = def.border_color || '';
      callout.style.background = def.background || '';
      var icon = callout.querySelector('.cpb-callout-icon');
      if (icon) icon.style.background = def.icon_color || '';
      var title = callout.querySelector('.cpb-callout-title');
      if (title) {
        applyTypographyToElement(title, {
          font_family: def.title_font_family || 'sans',
          font_size: def.title_font_size || 11,
          color: def.title_color || '#0f2744',
          font_bold: !!def.title_font_bold,
          font_italic: false,
          font_underline: false,
        }, null, true);
      }
      var text = callout.querySelector('.cpb-callout-text');
      if (text) {
        applyTypographyToElement(text, {
          font_family: def.text_font_family || 'sans',
          font_size: def.text_font_size || 10,
          color: def.text_color || '#1e293b',
          font_bold: false,
          font_italic: false,
          font_underline: false,
        }, null, true);
      }
    });
  }

  function refreshContentTableTypographyFromBookStyles() {
    canvasEl.querySelectorAll('.cpb-block--table').forEach(function (blockEl) {
      if (blockEl.closest('.cpb-sheet--lep, .cpb-sheet--part0')) {
        return;
      }
      var tableBlock = blockEl.querySelector('.cpb-table-block');
      var kind = tableBlock ? (tableBlock.getAttribute('data-table-style-kind') || 'text') : 'text';
      var styles = state.bookStyles || defaultBookStyles();
      var tableStyle = (styles.table_styles && styles.table_styles[kind]) || defaultTableStyleDef();
      var headerStyle = tableStyle.header_row || defaultTableStyleDef().header_row;
      var bodyStyle = tableStyle.body_row || defaultTableStyleDef().body_row;
      blockEl.querySelectorAll('.cpb-table thead th').forEach(function (cell) {
        applyBookTableRowStyleToCell(cell, headerStyle);
      });
      blockEl.querySelectorAll('.cpb-table tbody td').forEach(function (cell) {
        applyBookTableRowStyleToCell(cell, bodyStyle);
      });
    });
  }

  function refreshLepTypographyFromBookStyles() {
    var sheet = canvasEl.querySelector('.cpb-sheet--lep');
    if (!sheet) return;
    sheet.querySelectorAll('[data-paragraph-style]').forEach(function (el) {
      if (el.classList.contains('cpb-lep-emphasis')) return;
      refreshBlockTypographyFromBookStyles(el);
    });
    sheet.querySelectorAll('.cpb-lep-emphasis').forEach(function (el) {
      el.style.fontWeight = '700';
      el.setAttribute('data-font-bold', '1');
    });
    var tableStyle = (state.bookStyles && state.bookStyles.table_styles && state.bookStyles.table_styles.standard)
      || defaultTableStyleDef();
    sheet.querySelectorAll('.cpb-lep-table thead th').forEach(function (cell) {
      applyBookTableRowStyleToCell(cell, tableStyle.header_row || defaultTableStyleDef().header_row);
    });
    sheet.querySelectorAll('.cpb-lep-table tbody td').forEach(function (cell) {
      applyBookTableRowStyleToCell(cell, tableStyle.body_row || defaultTableStyleDef().body_row);
    });
  }

  function applyTypographyToElement(el, typo, paragraphStyle, skipInlineClear) {
    if (!skipInlineClear) {
      clearInlineTypographyInElement(el);
    }
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
    applyTypographyDecorationToElement(el, typo);
    syncSectionNumberTypography(el);
  }

  function syncRegulatoryRefTypography(contentEl) {
    if (!contentEl) return;
    var row = contentEl.closest('.cpb-paragraph-row, .cpb-heading-row');
    if (!row) return;
    var ref = row.querySelector('.cpb-regulatory-ref');
    if (!ref) return;
    var bodyDef = paragraphStyleDef('body');
    var font = bodyDef.font_family || 'serif';
    var size = bodyDef.font_size || 11;
    FONT_CLASSES.forEach(function (cls) { ref.classList.remove(cls); });
    PARAGRAPH_STYLE_CLASSES.forEach(function (cls) { ref.classList.remove(cls); });
    ref.classList.add('cpb-font-' + font);
    ref.style.fontFamily = FONT_STACKS[font] || FONT_STACKS.serif;
    ref.style.fontSize = size + 'pt';
    ref.style.fontWeight = '400';
    ref.style.color = '#1e3a8a';
  }

  function syncSectionNumberTypography(contentEl) {
    if (!contentEl) return;
    var row = contentEl.closest('.cpb-paragraph-row, .cpb-heading-row');
    if (!row) return;
    var num = row.querySelector('.cpb-section-number');
    if (num) {
      var font = contentEl.getAttribute('data-font-family') || 'serif';
      var size = contentEl.getAttribute('data-font-size') || '11';
      var color = contentEl.getAttribute('data-text-color') || contentEl.style.color || '#0f172a';
      FONT_CLASSES.forEach(function (cls) { num.classList.remove(cls); });
      PARAGRAPH_STYLE_CLASSES.forEach(function (cls) { num.classList.remove(cls); });
      num.classList.add('cpb-font-' + font);
      var ps = contentEl.getAttribute('data-paragraph-style');
      if (ps) num.classList.add('cpb-ps-' + ps);
      num.style.fontFamily = contentEl.style.fontFamily || FONT_STACKS[font] || FONT_STACKS.serif;
      num.style.fontSize = contentEl.style.fontSize || (size + 'pt');
      num.style.color = color;
    }
    syncRegulatoryRefTypography(contentEl);
  }

  function applyParagraphStyle(styleKey) {
    styleKey = canonicalParagraphStyleKey(styleKey);
    if (styleKey === 'custom' || styleKey === '') return;
    var target = getActiveStyleTarget();
    if (!isLiveStyleTarget(target)) return;
    var styles = state.bookStyles || defaultBookStyles();
    var def = (styles.paragraph_styles && styles.paragraph_styles[styleKey]) || styles.paragraph_styles.body;
    var typo = {
      font_family: def.font_family || 'serif',
      font_size: def.font_size || 11,
      color: def.color || '#0f172a',
      font_bold: !!def.font_bold,
      font_italic: !!def.font_italic,
      font_underline: !!def.font_underline,
    };
    var stack = FONT_STACKS[typo.font_family] || FONT_STACKS.serif;

    if (target.type === 'table-cell' || target.type === 'callout-title' || target.type === 'callout-text') {
      pushUndo();
      target.el.focus();
      restoreSelectionRange();
      var applyWhole = !hasTextSelectionInCanvas() || selectionCoversElementText(target.el);
      if (target.type === 'table-cell') {
        if (applyWhole) {
          applyTypographyToTableCell(target.el, typo);
        } else {
          clearInlineTypographyInSelection(target.el);
          restoreSelectionRange();
          applyInlineStyleToSelection({
            fontFamily: stack,
            fontSize: typo.font_size + 'pt',
            color: typo.color,
          });
        }
      } else if (applyWhole) {
        applyTypographyToCalloutElement(target.el, typo);
      } else {
        clearInlineTypographyInSelection(target.el);
        restoreSelectionRange();
        applyInlineStyleToSelection({
          fontFamily: stack,
          fontSize: typo.font_size + 'pt',
          color: typo.color,
        });
      }
      if (fontSelect) fontSelect.value = typo.font_family;
      if (fontSizeSelect) fontSizeSelect.value = String(typo.font_size);
      if (textColorInput) textColorInput.value = typo.color;
      updateParagraphStyleSelectForElement(target.el);
      scheduleSave(target.block);
      flushSave(target.block);
      return;
    }

    if (!isBlockTypographyTarget(target)) return;
    pushUndo();
    target.el.focus();
    restoreSelectionRange();
    var applyWholeBlock = !hasTextSelectionInCanvas() || selectionCoversElementText(target.el);
    if (applyWholeBlock) {
      applyTypographyToElement(target.el, typo, styleKey);
    } else {
      PARAGRAPH_STYLE_CLASSES.forEach(function (cls) { target.el.classList.remove(cls); });
      target.el.classList.add('cpb-ps-' + styleKey);
      target.el.setAttribute('data-paragraph-style', styleKey);
      clearInlineTypographyInSelection(target.el);
      restoreSelectionRange();
      applyInlineStyleToSelection({
        fontFamily: stack,
        fontSize: typo.font_size + 'pt',
        color: typo.color,
      });
    }
    if (fontSelect) fontSelect.value = typo.font_family;
    if (fontSizeSelect) fontSizeSelect.value = String(typo.font_size);
    if (textColorInput) textColorInput.value = typo.color;
    updateParagraphStyleSelectForElement(target.el);
    var blockId = parseInt(target.block.getAttribute('data-block-id') || '0', 10);
    updateRegulatoryRefFieldVisibility(styleKey, target.el, blockId);
    if (styleKey === 'regulatory_reference') {
      target.el.setAttribute('data-regulatory-ref', '');
      if (regulatoryRefInput) regulatoryRefInput.value = '';
    }
    scheduleSave(target.block);
    flushSave(target.block, styleNeedsNumberingRefresh(styleKey));
  }

  function applyRichTextStyle(target, styles, wholeElementFallback) {
    target.el.focus();
    restoreSelectionRange();
    if (hasTextSelectionInCanvas() && applyInlineStyleToSelection(styles)) {
      return true;
    }
    if (typeof wholeElementFallback === 'function') {
      wholeElementFallback();
    }
    return false;
  }

  function applyFontFamily(font) {
    var target = getActiveStyleTarget();
    if (!target) return;
    pushUndo();
    if (target.type === 'table-cell') {
      applyFontToTableCell(target.el, font);
      updateParagraphStyleSelectForElement(target.el);
    } else if (isBlockTypographyTarget(target)) {
      var stack = FONT_STACKS[font];
      applyRichTextStyle(target, { fontFamily: stack || '' }, function () {
        FONT_CLASSES.forEach(function (cls) { target.el.classList.remove(cls); });
        target.el.classList.add('cpb-font-' + font);
        target.el.setAttribute('data-font-family', font);
        if (stack) target.el.style.fontFamily = stack;
        syncSectionNumberTypography(target.el);
      });
      updateParagraphStyleSelectForElement(target.el);
    } else if (target.type === 'callout-title' || target.type === 'callout-text') {
      var calloutStack = FONT_STACKS[font];
      applyRichTextStyle(target, { fontFamily: calloutStack || '' }, function () {
        FONT_CLASSES.forEach(function (cls) { target.el.classList.remove(cls); });
        target.el.classList.add('cpb-font-' + font);
        target.el.setAttribute('data-font-family', font);
        if (calloutStack) target.el.style.fontFamily = calloutStack;
      });
      updateParagraphStyleSelectForElement(target.el);
    }
    scheduleSave(target.block);
    flushSave(target.block);
  }

  function applyFontSizeToElement(el, size, updateSelect) {
    el.style.fontSize = size + 'pt';
    el.setAttribute('data-font-size', String(size));
    if (updateSelect && fontSizeSelect) fontSizeSelect.value = String(size);
    syncSectionNumberTypography(el);
  }

  function applyFontSize(size) {
    var target = getActiveStyleTarget();
    if (!target) return;
    pushUndo();
    if (target.type === 'table-cell') {
      applySizeToTableCell(target.el, size);
      updateParagraphStyleSelectForElement(target.el);
    } else if (isBlockTypographyTarget(target)) {
      applyRichTextStyle(target, { fontSize: size + 'pt' }, function () {
        applyFontSizeToElement(target.el, size, true);
      });
      if (fontSizeSelect) fontSizeSelect.value = String(size);
      updateParagraphStyleSelectForElement(target.el);
    } else if (target.type === 'callout-title' || target.type === 'callout-text') {
      applyRichTextStyle(target, { fontSize: size + 'pt' }, function () {
        target.el.style.fontSize = size + 'pt';
        target.el.setAttribute('data-font-size', String(size));
      });
      if (fontSizeSelect) fontSizeSelect.value = String(size);
      updateParagraphStyleSelectForElement(target.el);
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
    var liveInsertAfter = isConnectedEl(insertAfterBlockEl) ? insertAfterBlockEl : null;
    var afterId = liveInsertAfter
      ? parseInt(liveInsertAfter.getAttribute('data-block-id') || '0', 10)
      : 0;
    var focusedBlock = !liveInsertAfter ? getFocusedBlock() : null;
    if (!afterId && focusedBlock) {
      afterId = parseInt(focusedBlock.getAttribute('data-block-id') || '0', 10);
    }
    var req = {
      version_id: state.versionId,
      section_id: state.sectionId,
      block_type: blockType,
      payload: payload || {},
    };
    if (afterId > 0 && (liveInsertAfter || focusedBlock)) {
      req.insert_after_block_id = afterId;
    }
    return apiPost('create_block', req).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Create failed');
      var anchor = liveInsertAfter || (focusedBlock && isConnectedEl(focusedBlock) ? focusedBlock : null);
      var body = canvasEl.querySelector('[data-blocks-root]');
      if (anchor && res.block_html) {
        anchor.insertAdjacentHTML('afterend', res.block_html);
        wireCanvas();
        var newBlock = anchor.nextElementSibling;
        if (newBlock && newBlock.classList.contains('cpb-block')) {
          var field = newBlock.querySelector('[contenteditable="true"]');
          if (field) field.focus();
        }
      } else if (body && res.block_html) {
        body.insertAdjacentHTML('beforeend', res.block_html);
        wireCanvas();
        var lastBlock = body.querySelector('.cpb-block:last-child');
        if (lastBlock) {
          var field2 = lastBlock.querySelector('[contenteditable="true"]');
          if (field2) field2.focus();
        }
      }
      setStatus('Added', 'saved');
      applyNumberingState(res);
      if (blockType === 'paragraph' || blockType === 'heading') {
        return recomputeSectionNumbers().catch(showError).then(function () { return res; });
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
    var labels = { warning: 'Warning', caution: 'Caution', info: 'Info', note: 'Note' };
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
    var note = presetByType('note') || { callout_type: 'note', title: 'NOTE', text: '' };

    var overlay = document.createElement('div');
    overlay.className = 'cpb-callout-overlay';
    overlay.innerHTML = ''
      + '<div class="cpb-callout-dialog" role="dialog" aria-label="Manage callout presets">'
      + '<h3>Callout presets</h3>'
      + '<p style="margin:0 0 12px;font-size:13px;color:#64748b;">Default title and text used when inserting callout blocks.</p>'
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
      + '<div class="cpb-callout-field"><label>Note title</label>'
      + '<input type="text" id="cpbPresetNoteTitle" value="' + escapeAttr(note.title) + '"></div>'
      + '<div class="cpb-callout-field"><label>Note default text</label>'
      + '<textarea id="cpbPresetNoteText">' + escapeHtml(note.text) + '</textarea></div>'
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
        {
          callout_type: 'note',
          title: overlay.querySelector('#cpbPresetNoteTitle').value.trim() || 'NOTE',
          text: overlay.querySelector('#cpbPresetNoteText').value.trim(),
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

  function styleEditorFormatToggles(def) {
    var boldChecked = def && def.font_bold ? ' checked' : '';
    var italicChecked = def && def.font_italic ? ' checked' : '';
    var underlineChecked = def && def.font_underline ? ' checked' : '';
    return '<span class="cpb-style-format">'
      + '<label title="Bold"><input type="checkbox" data-ps-field="font_bold"' + boldChecked + '> <strong>B</strong></label>'
      + '<label title="Italic"><input type="checkbox" data-ps-field="font_italic"' + italicChecked + '> <em>I</em></label>'
      + '<label title="Underline"><input type="checkbox" data-ps-field="font_underline"' + underlineChecked + '> <u>U</u></label>'
      + '</span>';
  }

  function updateParagraphStyleSample(row) {
    if (!row) return;
    var key = row.getAttribute('data-ps-row');
    var sample = row.querySelector('[data-ps-sample="' + key + '"]');
    if (!sample) return;
    var font = row.querySelector('[data-ps-field="font_family"]').value;
    var size = row.querySelector('[data-ps-field="font_size"]').value;
    var color = row.querySelector('[data-ps-field="color"]').value;
    var bold = row.querySelector('[data-ps-field="font_bold"]').checked;
    var italic = row.querySelector('[data-ps-field="font_italic"]').checked;
    var underline = row.querySelector('[data-ps-field="font_underline"]').checked;
    sample.style.fontFamily = FONT_STACKS[font] || FONT_STACKS.serif;
    sample.style.fontSize = size + 'pt';
    sample.style.color = color;
    sample.style.fontWeight = bold ? '700' : '400';
    sample.style.fontStyle = italic ? 'italic' : 'normal';
    sample.style.textDecoration = underline ? 'underline' : 'none';
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

  function headerTypographyControls(idPrefix, column) {
    var col = column || {};
    var boldChecked = col.font_bold ? ' checked' : '';
    var italicChecked = col.font_italic ? ' checked' : '';
    var underlineChecked = col.font_underline ? ' checked' : '';
    return '<div class="cpb-header-typography">'
      + '<label>Font <select class="cpb-style-input" id="' + idPrefix + 'Font">'
      + styleEditorFontOptions(col.font_family || 'sans') + '</select></label>'
      + '<label>Size <select class="cpb-style-input" id="' + idPrefix + 'Size">'
      + headerFontSizeOptions(parseInt(col.font_size, 10) || 11) + '</select></label>'
      + '<span class="cpb-header-font-style">'
      + '<label title="Bold"><input type="checkbox" id="' + idPrefix + 'Bold"' + boldChecked + '> <strong>B</strong></label>'
      + '<label title="Italic"><input type="checkbox" id="' + idPrefix + 'Italic"' + italicChecked + '> <em>I</em></label>'
      + '<label title="Underline"><input type="checkbox" id="' + idPrefix + 'Underline"' + underlineChecked + '> <u>U</u></label>'
      + '</span></div>';
  }

  function applyColumnTypographyToBand(band, prefix, column) {
    band[prefix + '_font_family'] = column.font_family;
    band[prefix + '_font_size'] = column.font_size;
    band[prefix + '_font_bold'] = column.font_bold;
    band[prefix + '_font_italic'] = column.font_italic;
    band[prefix + '_font_underline'] = column.font_underline;
    return band;
  }

  function currentHeaderScope() {
    if (state.isAnnexRegisterSection || state.isAnnexHighlightsSection || state.isAnnexContentSection) {
      return 'annex';
    }
    return state.pageHeaderScope === 'annex' ? 'annex' : 'main';
  }

  function openHeaderEditor() {
    var headerScope = currentHeaderScope();
    var header = Object.assign({}, defaultPageHeader(), JSON.parse(JSON.stringify(state.pageHeader || {})));
    var footer = Object.assign({}, defaultPageFooter(), JSON.parse(JSON.stringify(state.pageFooter || {})));
    var tokens = state.headerTokens.length ? state.headerTokens : defaultHeaderTokens();
    var overlay = document.createElement('div');
    overlay.className = 'cpb-style-overlay cpb-header-overlay';
    var scopeLead = headerScope === 'annex'
      ? 'Configure the running header and footer for <strong>annex pages only</strong> (register, highlight of changes, and annex content). Changes here do not affect the rest of the manual.'
      : 'Configure the running header and footer for the main manual (all parts and Part 0 pages). Open an annex page to edit annex headers separately.';

    var tokenButtons = tokens.map(function (t) {
      return '<button type="button" class="cpb-header-token" data-token="' + escapeHtml(t.token) + '" title="'
        + escapeHtml(t.description || '') + '">' + escapeHtml(t.label || t.token) + '</button>';
    }).join('');

    overlay.innerHTML = ''
      + '<div class="cpb-style-dialog cpb-header-dialog" role="dialog" aria-label="Page header editor">'
      + '<h3>Page header editor</h3>'
      + '<p class="cpb-style-lead">' + scopeLead + ' '
      + 'Use variables for dynamic content — page numbers are resolved automatically in the e-reader.</p>'
      + '<section class="cpb-header-section">'
      + '<label class="cpb-header-enable"><input type="checkbox" id="cpbHeaderEnabled"' + (header.enabled ? ' checked' : '') + '> Show page header</label>'
      + '<label class="cpb-header-row-height">Row height <select class="cpb-style-input" id="cpbHeaderRowHeight">'
      + headerRowHeightOptions(parseInt(header.row_height, 10) || 32) + '</select></label>'
      + '<div class="cpb-header-grid">'
      + '<div class="cpb-header-col">'
      + '<h4>Left — Logo</h4>'
      + '<div class="cpb-header-logo-drop" id="cpbHeaderLogoDrop">'
      + '<div class="cpb-header-logo-preview" id="cpbHeaderLogoPreview"></div>'
      + '<p class="cpb-header-logo-hint">Drag &amp; drop logo image, or click to browse</p>'
      + '<button type="button" class="cpb-header-logo-clear" id="cpbHeaderLogoClear">Remove logo</button>'
      + '</div>'
      + '<label>Alt text <input type="text" class="cpb-style-input" id="cpbHeaderLogoAlt" value="' + escapeHtml(header.logo_alt || '') + '"></label>'
      + '<label>Logo height <select class="cpb-style-input" id="cpbHeaderLogoHeight">'
      + headerLogoHeightOptions(parseInt(header.logo_max_height, 10) || 48) + '</select></label>'
      + '</div>'
      + '<div class="cpb-header-col">'
      + '<h4>Center</h4>'
      + headerTypographyControls('cpbHeaderCenter', headerColumnFromBand(header, 'center'))
      + '<textarea class="cpb-header-textarea" id="cpbHeaderCenter" rows="4">' + escapeHtml(header.center_text || '') + '</textarea>'
      + '<div class="cpb-header-tokens" data-target="cpbHeaderCenter">' + tokenButtons + '</div>'
      + '</div>'
      + '<div class="cpb-header-col">'
      + '<h4>Right</h4>'
      + headerTypographyControls('cpbHeaderRight', headerColumnFromBand(header, 'right'))
      + '<textarea class="cpb-header-textarea" id="cpbHeaderRight" rows="4">' + escapeHtml(header.right_text || '') + '</textarea>'
      + '<div class="cpb-header-tokens" data-target="cpbHeaderRight">' + tokenButtons + '</div>'
      + '</div>'
      + '</div>'
      + '</section>'
      + '<section class="cpb-header-section cpb-header-section--footer">'
      + '<label class="cpb-header-enable"><input type="checkbox" id="cpbFooterEnabled"' + (footer.enabled ? ' checked' : '') + '> Show page footer</label>'
      + '<label class="cpb-header-row-height">Row height <select class="cpb-style-input" id="cpbFooterRowHeight">'
      + headerRowHeightOptions(parseInt(footer.row_height, 10) || 26) + '</select></label>'
      + '<div class="cpb-header-grid cpb-header-grid--footer">'
      + '<div class="cpb-header-col"><h4>Footer left</h4>'
      + headerTypographyControls('cpbFooterLeft', headerColumnFromBand(footer, 'left'))
      + '<textarea class="cpb-header-textarea" id="cpbFooterLeft" rows="2">' + escapeHtml(footer.left_text || '') + '</textarea>'
      + '<div class="cpb-header-tokens" data-target="cpbFooterLeft">' + tokenButtons + '</div></div>'
      + '<div class="cpb-header-col"><h4>Footer center</h4>'
      + headerTypographyControls('cpbFooterCenter', headerColumnFromBand(footer, 'center'))
      + '<textarea class="cpb-header-textarea" id="cpbFooterCenter" rows="2">' + escapeHtml(footer.center_text || '') + '</textarea>'
      + '<div class="cpb-header-tokens" data-target="cpbFooterCenter">' + tokenButtons + '</div></div>'
      + '<div class="cpb-header-col"><h4>Footer right</h4>'
      + headerTypographyControls('cpbFooterRight', headerColumnFromBand(footer, 'right'))
      + '<textarea class="cpb-header-textarea" id="cpbFooterRight" rows="2">' + escapeHtml(footer.right_text || '') + '</textarea>'
      + '<div class="cpb-header-tokens" data-target="cpbFooterRight">' + tokenButtons + '</div></div>'
      + '</div>'
      + '</section>'
      + '<section class="cpb-header-preview-section">'
      + '<h4>Preview (current section)</h4>'
      + '<div class="cpb-header-preview" id="cpbHeaderPreview"></div>'
      + '</section>'
      + '<div class="cpb-style-dialog-actions">'
      + '<button type="button" class="cpb-style-cancel">Cancel</button>'
      + '<button type="button" class="cpb-header-save">Save header</button>'
      + '</div></div>';

    var logoPreviewEl = overlay.querySelector('#cpbHeaderLogoPreview');
    var logoDropEl = overlay.querySelector('#cpbHeaderLogoDrop');
    var pendingLogoUrl = header.logo_url || '';

    function renderLogoPreview() {
      if (!logoPreviewEl) return;
      var logoHeight = parseInt(overlay.querySelector('#cpbHeaderLogoHeight').value, 10) || 48;
      if (pendingLogoUrl) {
        logoPreviewEl.innerHTML = '<img src="' + escapeHtml(pendingLogoUrl) + '" alt="" style="max-height:' + logoHeight + 'px;">';
      } else {
        logoPreviewEl.innerHTML = '<span class="cpb-header-logo-empty">No logo</span>';
      }
      refreshPreview();
    }

    function readColumnFromDialog(idPrefix) {
      return {
        font_family: overlay.querySelector('#' + idPrefix + 'Font').value,
        font_size: parseInt(overlay.querySelector('#' + idPrefix + 'Size').value, 10) || 11,
        font_bold: !!overlay.querySelector('#' + idPrefix + 'Bold').checked,
        font_italic: !!overlay.querySelector('#' + idPrefix + 'Italic').checked,
        font_underline: !!overlay.querySelector('#' + idPrefix + 'Underline').checked,
      };
    }

    function readDialogState() {
      var headerBand = {
        enabled: !!overlay.querySelector('#cpbHeaderEnabled').checked,
        left_type: 'logo',
        logo_url: pendingLogoUrl,
        logo_alt: overlay.querySelector('#cpbHeaderLogoAlt').value.trim() || 'EuroPilot Center',
        logo_max_height: parseInt(overlay.querySelector('#cpbHeaderLogoHeight').value, 10) || 40,
        row_height: parseInt(overlay.querySelector('#cpbHeaderRowHeight').value, 10) || 32,
        center_text: overlay.querySelector('#cpbHeaderCenter').value,
        right_text: overlay.querySelector('#cpbHeaderRight').value,
      };
      applyColumnTypographyToBand(headerBand, 'center', readColumnFromDialog('cpbHeaderCenter'));
      applyColumnTypographyToBand(headerBand, 'right', readColumnFromDialog('cpbHeaderRight'));

      var footerBand = {
        enabled: !!overlay.querySelector('#cpbFooterEnabled').checked,
        row_height: parseInt(overlay.querySelector('#cpbFooterRowHeight').value, 10) || 26,
        left_text: overlay.querySelector('#cpbFooterLeft').value,
        center_text: overlay.querySelector('#cpbFooterCenter').value,
        right_text: overlay.querySelector('#cpbFooterRight').value,
      };
      applyColumnTypographyToBand(footerBand, 'left', readColumnFromDialog('cpbFooterLeft'));
      applyColumnTypographyToBand(footerBand, 'center', readColumnFromDialog('cpbFooterCenter'));
      applyColumnTypographyToBand(footerBand, 'right', readColumnFromDialog('cpbFooterRight'));

      return { header: headerBand, footer: footerBand };
    }

    function refreshPreview() {
      var st = readDialogState();
      var previewEl = overlay.querySelector('#cpbHeaderPreview');
      if (previewEl) {
        previewEl.innerHTML = previewHeaderHtml(st.header, st.footer);
      }
    }

    function insertTokenAt(targetId, token) {
      var ta = overlay.querySelector('#' + targetId);
      if (!ta) return;
      var start = ta.selectionStart;
      var end = ta.selectionEnd;
      var val = ta.value;
      ta.value = val.slice(0, start) + token + val.slice(end);
      ta.focus();
      ta.selectionStart = ta.selectionEnd = start + token.length;
      refreshPreview();
    }

    function uploadLogoFile(file) {
      if (!file || !file.type.match(/^image\/(jpeg|png|webp)$/)) {
        alert('Only JPG, PNG, or WEBP images are allowed.');
        return;
      }
      var fd = new FormData();
      fd.append('action', 'upload_header_logo');
      fd.append('version_id', String(state.versionId));
      fd.append('header_scope', headerScope);
      fd.append('image', file);
      fd.append('alt', overlay.querySelector('#cpbHeaderLogoAlt').value.trim());
      setStatus('Uploading logo…', 'saving');
      fetch(apiBase, { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res.ok) throw new Error(res.error || 'Upload failed');
          pendingLogoUrl = res.url || (res.page_header && res.page_header.logo_url) || '';
          if (res.page_header) {
            state.pageHeader = res.page_header;
            state.pageFooter = res.page_footer || state.pageFooter;
          }
          renderLogoPreview();
          setStatus('Logo uploaded', 'saved');
        })
        .catch(showError);
    }

    renderLogoPreview();

    overlay.querySelectorAll('.cpb-header-token').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var target = btn.closest('.cpb-header-tokens').getAttribute('data-target');
        insertTokenAt(target, btn.getAttribute('data-token'));
      });
    });

    overlay.querySelectorAll('.cpb-header-textarea, #cpbHeaderLogoAlt').forEach(function (el) {
      el.addEventListener('input', refreshPreview);
    });
    overlay.querySelectorAll('.cpb-header-typography select, #cpbHeaderLogoHeight, #cpbHeaderRowHeight, #cpbFooterRowHeight').forEach(function (el) {
      el.addEventListener('change', function () {
        if (el.id === 'cpbHeaderLogoHeight') renderLogoPreview();
        else refreshPreview();
      });
    });
    overlay.querySelectorAll('.cpb-header-font-style input[type="checkbox"]').forEach(function (el) {
      el.addEventListener('change', refreshPreview);
    });
    overlay.querySelector('#cpbHeaderEnabled').addEventListener('change', refreshPreview);
    overlay.querySelector('#cpbFooterEnabled').addEventListener('change', refreshPreview);

    logoDropEl.addEventListener('click', function () {
      if (headerLogoInput) headerLogoInput.click();
    });
    logoDropEl.addEventListener('dragover', function (e) {
      e.preventDefault();
      logoDropEl.classList.add('cpb-header-logo-drop--over');
    });
    logoDropEl.addEventListener('dragleave', function () {
      logoDropEl.classList.remove('cpb-header-logo-drop--over');
    });
    logoDropEl.addEventListener('drop', function (e) {
      e.preventDefault();
      logoDropEl.classList.remove('cpb-header-logo-drop--over');
      if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
        uploadLogoFile(e.dataTransfer.files[0]);
      }
    });

    if (headerLogoInput) {
      headerLogoInput.onchange = function () {
        if (headerLogoInput.files && headerLogoInput.files[0]) {
          uploadLogoFile(headerLogoInput.files[0]);
        }
        headerLogoInput.value = '';
      };
    }

    overlay.querySelector('#cpbHeaderLogoClear').addEventListener('click', function () {
      pendingLogoUrl = '';
      renderLogoPreview();
    });

    function close() {
      if (headerLogoInput) headerLogoInput.onchange = null;
      overlay.remove();
    }
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    overlay.querySelector('.cpb-style-cancel').addEventListener('click', close);
    overlay.querySelector('.cpb-header-save').addEventListener('click', function () {
      var st = readDialogState();
      setStatus('Saving header…', 'saving');
      apiPost('save_page_header', {
        version_id: state.versionId,
        header_scope: headerScope,
        page_header: st.header,
        page_footer: st.footer,
      }).then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Save failed');
        state.pageHeader = res.page_header || st.header;
        state.pageFooter = res.page_footer || st.footer;
        state.pageHeaderScope = res.header_scope || headerScope;
        if (state.bookStyles && headerScope === 'main') {
          state.bookStyles.page_header = state.pageHeader;
          state.bookStyles.page_footer = state.pageFooter;
        }
        close();
        setStatus('Page header saved', 'saved');
        return loadSection(state.sectionId);
      }).catch(showError);
    });
    document.body.appendChild(overlay);
  }

  function openStyleEditor() {
    var styles = JSON.parse(JSON.stringify(state.bookStyles || defaultBookStyles()));
    var overlay = document.createElement('div');
    overlay.className = 'cpb-style-overlay';

    var paragraphRows = PARAGRAPH_STYLE_KEYS.map(function (key) {
      var def = styles.paragraph_styles[key] || {};
      var sample = PARAGRAPH_STYLE_LABELS[key] || key;
      var sampleStyle = 'font-family:' + escapeAttr(FONT_STACKS[def.font_family] || FONT_STACKS.serif)
        + ';font-size:' + (def.font_size || 11) + 'pt'
        + ';color:' + escapeAttr(def.color || '#0f172a')
        + ';font-weight:' + (def.font_bold ? '700' : '400')
        + ';font-style:' + (def.font_italic ? 'italic' : 'normal')
        + ';text-decoration:' + (def.font_underline ? 'underline' : 'none');
      return ''
        + '<tr data-ps-row="' + key + '">'
        + '<td class="cpb-style-name">' + escapeHtml(sample) + '</td>'
        + '<td><select class="cpb-style-input" data-ps-field="font_family">' + styleEditorFontOptions(def.font_family || 'serif') + '</select></td>'
        + '<td><input class="cpb-style-input cpb-style-input--num" type="number" min="8" max="32" data-ps-field="font_size" value="' + (def.font_size || 11) + '"></td>'
        + '<td><input class="cpb-style-input cpb-style-input--color" type="color" data-ps-field="color" value="' + escapeAttr(def.color || '#0f172a') + '"></td>'
        + '<td>' + styleEditorFormatToggles(def) + '</td>'
        + '<td><span class="cpb-style-sample" data-ps-sample="' + key + '" style="' + sampleStyle + '">' + escapeHtml(sample) + '</span></td>'
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

    function calloutStyleSection(type, label) {
      var c = styles.callout_styles && styles.callout_styles[type]
        ? styles.callout_styles[type]
        : defaultCalloutStylesDef()[type];
      function field(name, title, isColor) {
        var val = c[name] || '';
        if (isColor) {
          return '<label>' + escapeHtml(title) + ' <input class="cpb-style-input cpb-style-input--color" type="color" data-callout-type="' + type + '" data-callout-field="' + name + '" value="' + escapeAttr(val || '#000000') + '"></label>';
        }
        if (name.indexOf('font_family') > -1) {
          return '<label>' + escapeHtml(title) + ' <select class="cpb-style-input" data-callout-type="' + type + '" data-callout-field="' + name + '">' + styleEditorFontOptions(val || 'sans') + '</select></label>';
        }
        if (name.indexOf('font_size') > -1) {
          return '<label>' + escapeHtml(title) + ' <input class="cpb-style-input cpb-style-input--num" type="number" min="8" max="32" data-callout-type="' + type + '" data-callout-field="' + name + '" value="' + (val || 10) + '"></label>';
        }
        return '';
      }
      return ''
        + '<section class="cpb-style-section"><h4>' + escapeHtml(label) + '</h4>'
        + '<div class="cpb-style-table-grid">'
        + field('border_color', 'Border', true)
        + field('background', 'Background', true)
        + field('icon_color', 'Icon', true)
        + field('title_color', 'Title color', true)
        + field('title_font_family', 'Title font')
        + field('title_font_size', 'Title size')
        + field('text_color', 'Text color', true)
        + field('text_font_family', 'Text font')
        + field('text_font_size', 'Text size')
        + '</div></section>';
    }

    overlay.innerHTML = ''
      + '<div class="cpb-style-dialog" role="dialog" aria-label="Book style editor">'
      + '<h3>Book style editor</h3>'
      + '<p class="cpb-style-lead">Paragraph styles drive the Table of Contents and automatic section numbering '
      + '(Title 1. · Subtitle 1 1.1 · Subtitle 2 1.1.1 · …). '
      + 'Regulatory Reference blocks show an MCCF cross-reference — auto-derived or entered manually in the toolbar.</p>'
      + '<section class="cpb-style-section"><h4>Paragraph styles</h4>'
      + '<table class="cpb-style-table"><thead><tr><th>Style</th><th>Font</th><th>Size</th><th>Color</th><th>Format</th><th>Sample</th></tr></thead><tbody>'
      + paragraphRows + '</tbody></table></section>'
      + tableSection('standard', 'Standard tables')
      + tableSection('text', 'Text tables')
      + calloutStyleSection('warning', 'Warning boxes')
      + calloutStyleSection('caution', 'Caution boxes')
      + calloutStyleSection('info', 'Info boxes')
      + calloutStyleSection('note', 'Note boxes')
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
          font_bold: !!row.querySelector('[data-ps-field="font_bold"]').checked,
          font_italic: !!row.querySelector('[data-ps-field="font_italic"]').checked,
          font_underline: !!row.querySelector('[data-ps-field="font_underline"]').checked,
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
      ['warning', 'caution', 'info', 'note'].forEach(function (type) {
        var base = next.callout_styles[type];
        overlay.querySelectorAll('[data-callout-type="' + type + '"]').forEach(function (input) {
          var field = input.getAttribute('data-callout-field');
          if (!field) return;
          if (field === 'title_font_size' || field === 'text_font_size') {
            base[field] = parseInt(input.value, 10) || 10;
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
      updateParagraphStyleSample(row);
    });
    overlay.addEventListener('change', function (e) {
      var row = e.target.closest('[data-ps-row]');
      if (!row) return;
      updateParagraphStyleSample(row);
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
          return loadSection(state.sectionId).then(function () {
            canvasEl.querySelectorAll('.cpb-paragraph, .cpb-heading, .cpb-list').forEach(function (el) {
              refreshBlockTypographyFromBookStyles(el);
            });
            refreshTocTypographyFromBookStyles();
            refreshLepTypographyFromBookStyles();
            refreshCalloutTypographyFromBookStyles();
          });
        })
        .catch(showError);
    });
    document.body.appendChild(overlay);
  }

  function syncToc(skipConfirm) {
    if (!skipConfirm && !confirm('Regenerate the Table of Contents from the selected paragraph style levels?')) return;
    setStatus('Syncing TOC…', 'saving');
    var settings = collectTocSettingsFromPanel();
    apiPost('regenerate_toc', {
      version_id: state.versionId,
      section_id: state.sectionId,
      toc_settings: settings,
    })
      .then(function (res) {
        if (!res.ok) throw new Error(res.error || 'TOC sync failed');
        var count = res.result && res.result.entries_count !== undefined ? res.result.entries_count : 0;
        state.tocSettings = res.toc_settings || settings;
        state.tocSettingsCatalog = res.toc_settings_catalog || state.tocSettingsCatalog;
        if (res.page_html) {
          canvasEl.innerHTML = res.page_html;
          wireCanvas();
          updateTocToolbarCheckboxes();
          setStatus('TOC updated (' + count + ' entries)', 'saved');
        } else {
          var tocId = findTocSectionId(state.sectionsTree);
          if (tocId) return loadSection(tocId);
        }
        setStatus('TOC updated (' + count + ' entries)', 'saved');
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

  function syncManualStructure() {
    if (!state.editable) {
      showError(new Error('Released versions cannot be restructured.'));
      return;
    }
    if (!confirm('Sync chapter sections and sidebar labels from canonical structure? Existing page content is kept.')) return;
    setStatus('Syncing manual structure…', 'saving');
    apiPost('sync_manual_structure', { version_id: state.versionId })
      .then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Structure sync failed');
        var parts = res.parts_synced !== undefined ? res.parts_synced : 0;
        var created = res.chapters_created !== undefined ? res.chapters_created : 0;
        var updated = res.chapters_updated !== undefined ? res.chapters_updated : 0;
        var removed = res.chapters_removed !== undefined ? res.chapters_removed : 0;
        var retired = res.invalid_excerpts_retired !== undefined ? res.invalid_excerpts_retired : 0;
        var summary = parts + ' part(s), ' + created + ' created, ' + updated + ' updated';
        if (removed > 0) summary += ', ' + removed + ' removed';
        if (retired > 0) summary += ', ' + retired + ' junk excerpts retired';
        if (parts === 0 && created === 0 && updated === 0) {
          if (res.canonical_chapters_found && res.canonical_chapters_found > 0) {
            summary += ' — chapter sections already match canonical structure';
          } else if (res.source_set_id) {
            summary += ' — no canonical chapters found in source set #' + res.source_set_id;
          } else {
            summary += ' — no manual source set linked to this version';
          }
        }
        return loadSection(state.sectionId).then(function () {
          setStatus('Manual structure synced (' + summary + ')', 'saved');
        });
      })
      .catch(showError);
  }

  function detectCallouts(scope) {
    runContentDetection('detect_callouts', scope, {
      sectionMessage: 'Detect Note, Warning, Caution and Info prefixes in body paragraphs on this page and convert them to callout blocks?',
      versionMessage: 'Scan all sections for Note, Warning, Caution and Info prefixes and convert matching body paragraphs to callout blocks?',
      working: 'Detecting callouts…',
      countKey: 'converted',
      countLabel: 'callout',
    });
  }

  function detectHyperlinks(scope) {
    runContentDetection('detect_hyperlinks', scope, {
      sectionMessage: 'Turn plain http(s):// and www. URLs into clickable links in paragraphs, callouts, and tables on this page?',
      versionMessage: 'Scan all sections and turn plain URLs into clickable links?',
      working: 'Detecting hyperlinks…',
      countKey: 'enriched',
      countLabel: 'block',
    });
  }

  function detectAnnexRefs(scope) {
    runContentDetection('detect_annex_refs', scope, {
      sectionMessage: 'Turn Annex references (e.g. Annex 3, OM Annex 3) into navigation links on this page?',
      versionMessage: 'Scan all sections and turn Annex references into navigation links?',
      working: 'Detecting annex references…',
      countKey: 'enriched',
      countLabel: 'block',
    });
  }

  function runContentDetection(action, scope, opts) {
    scope = scope || 'section';
    if (!state.editable) {
      showError(new Error('Released versions cannot be edited.'));
      return;
    }
    var message = scope === 'version' ? opts.versionMessage : opts.sectionMessage;
    if (!confirm(message)) return;

    setStatus(opts.working, 'saving');
    apiPost(action, {
      version_id: state.versionId,
      scope: scope,
      section_id: state.sectionId,
    })
      .then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Detection failed');
        var result = res.result || {};
        var count = result[opts.countKey] !== undefined ? result[opts.countKey] : 0;
        if (res.page_html) {
          canvasEl.innerHTML = res.page_html;
          wireCanvas();
          refreshCalloutTypographyFromBookStyles();
        } else if (scope === 'version') {
          return loadSection(state.sectionId);
        }
        var summary = count + ' ' + opts.countLabel + (count === 1 ? '' : 's') + ' updated';
        if (scope === 'version' && result.sections_updated !== undefined) {
          summary += ' in ' + result.sections_updated + ' section' + (result.sections_updated === 1 ? '' : 's');
        }
        setStatus(count > 0 ? summary : 'Nothing detected', count > 0 ? 'saved' : '');
      })
      .catch(showError);
  }

  function syncHighlights() {
    if (!confirm('Regenerate the Highlight of Changes section from revision markers?')) return;
    setStatus('Syncing highlights…', 'saving');
    apiPost('regenerate_highlights', {
      version_id: state.versionId,
      section_id: state.sectionId,
    })
      .then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Sync failed');
        var count = res.result && res.result.changes_count !== undefined ? res.result.changes_count : 0;
        if (res.page_html && state.part0SectionKey === 'highlights') {
          canvasEl.innerHTML = res.page_html;
          wireCanvas();
          refreshPart0TypographyFromBookStyles();
          setStatus('Highlights updated (' + count + ' changes)', 'saved');
          return;
        }
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

  function isFormControl(el) {
    if (!el || !el.tagName) return false;
    var tag = el.tagName.toUpperCase();
    return tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA' || tag === 'BUTTON';
  }

  function isFormatEditingActive() {
    if (!state.editable) return false;
    var ae = document.activeElement;
    if (!ae || !root.contains(ae)) return false;
    if (isFormControl(ae) && !ae.isContentEditable) return false;
    if (ae.isContentEditable && canvasEl.contains(ae)) return true;
    return selectionInCanvas() && !!state.savedSelectionRange;
  }

  function focusFormatTarget() {
    restoreSelectionRange();
    var ae = document.activeElement;
    if (ae && ae.isContentEditable && canvasEl.contains(ae)) return ae;
    var target = getActiveStyleTarget();
    if (target && target.el && target.el.isContentEditable) {
      target.el.focus();
      restoreSelectionRange();
      return target.el;
    }
    return null;
  }

  function execFormat(cmd, value) {
    focusFormatTarget();
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
    if (!selectionInCanvas()) return;
    saveSelectionRange();
    rememberStyleTarget();
  });

  if (toolbarEl) {
    toolbarEl.addEventListener('mousedown', function (e) {
      if (e.target.closest('[data-cmd], [data-align], #cpbUndo, #cpbRedo, #cpbIndent, #cpbOutdent')) {
        e.preventDefault();
      }
      saveSelectionRange();
      rememberStyleTarget();
    }, true);
  }

  var paragraphStyleSelectValueOnFocus = '';

  if (paragraphStyleSelect) {
    paragraphStyleSelect.addEventListener('focus', function () {
      paragraphStyleSelectValueOnFocus = paragraphStyleSelect.value;
      rememberStyleTarget();
    });
    paragraphStyleSelect.addEventListener('change', function () {
      applyParagraphStyle(paragraphStyleSelect.value);
      paragraphStyleSelectValueOnFocus = paragraphStyleSelect.value;
    });
    paragraphStyleSelect.addEventListener('blur', function () {
      var value = paragraphStyleSelect.value;
      if (value === 'custom') return;
      if (value === paragraphStyleSelectValueOnFocus) {
        applyParagraphStyle(value);
      }
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
    textColorInput.addEventListener('mousedown', function () {
      saveSelectionRange();
      var tableTarget = resolveTableCellForStyle();
      if (tableTarget) state.lastStyleTarget = tableTarget;
    });
    textColorInput.addEventListener('focus', rememberStyleTarget);
    textColorInput.addEventListener('input', function () {
      var color = textColorInput.value;
      var tableTarget = resolveTableCellForStyle();
      if (tableTarget) {
        pushUndo();
        applyTableCellTextColor(tableTarget.el, color);
        updateParagraphStyleSelectForElement(tableTarget.el);
        scheduleSave(tableTarget.block);
        flushSave(tableTarget.block);
        return;
      }
      var target = getActiveStyleTarget();
      if (target && (target.type === 'callout-title' || target.type === 'callout-text')) {
        pushUndo();
        applyRichTextStyle(target, { color: color }, function () {
          target.el.style.color = color;
          target.el.setAttribute('data-text-color', color);
        });
        updateParagraphStyleSelectForElement(target.el);
        scheduleSave(target.block);
        flushSave(target.block);
        return;
      }
      if (target && isRichTextStyleTarget(target)) {
        pushUndo();
        applyRichTextStyle(target, { color: color }, function () {
          target.el.style.color = color;
          if (isBlockTypographyTarget(target)) {
            target.el.setAttribute('data-text-color', color);
            syncSectionNumberTypography(target.el);
          }
        });
        updateParagraphStyleSelectForElement(target.el);
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

  if (detectSelect) {
    detectSelect.addEventListener('change', function () {
      var action = detectSelect.value;
      detectSelect.value = '';
      if (!action) return;
      if (action === 'callouts') detectCallouts('section');
      else if (action === 'hyperlinks') detectHyperlinks('section');
      else if (action === 'annex_refs') detectAnnexRefs('section');
      else if (action === 'callouts_all') detectCallouts('version');
      else if (action === 'hyperlinks_all') detectHyperlinks('version');
      else if (action === 'annex_refs_all') detectAnnexRefs('version');
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
      else if (action === 'structure') syncManualStructure();
      else if (action === 'highlights') syncHighlights();
    });
  }

  if (openStyleEditorBtn) {
    openStyleEditorBtn.addEventListener('click', function (e) {
      e.preventDefault();
      openStyleEditor();
    });
  }

  if (openHeaderEditorBtn) {
    openHeaderEditorBtn.addEventListener('click', function (e) {
      e.preventDefault();
      openHeaderEditor();
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
    } else if ((e.ctrlKey || e.metaKey) && isFormatEditingActive()) {
      var key = (e.key || '').toLowerCase();
      var formatCmd = null;
      if (key === 'b') formatCmd = 'bold';
      else if (key === 'i') formatCmd = 'italic';
      else if (key === 'u') formatCmd = 'underline';
      if (formatCmd) {
        e.preventDefault();
        execFormat(formatCmd);
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

  if (coverLogoInput) {
    coverLogoInput.addEventListener('change', function () {
      if (coverLogoInput.files && coverLogoInput.files[0]) {
        uploadCoverAsset('logo', coverLogoInput.files[0]);
        coverLogoInput.value = '';
      }
    });
  }

  if (coverImageInput) {
    coverImageInput.addEventListener('change', function () {
      if (coverImageInput.files && coverImageInput.files[0]) {
        uploadCoverAsset('cover_image', coverImageInput.files[0]);
        coverImageInput.value = '';
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

  wireTreeToggleAll();

  loadCalloutPresets()
    .then(function () { return loadSection(initialSectionId || 0); })
    .catch(showError);
})();
