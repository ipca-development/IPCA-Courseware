(function () {
  'use strict';

  var root = document.getElementById('cpbEditorRoot');
  if (!root) return;

  var versionId = parseInt(root.getAttribute('data-version-id') || '0', 10);
  var initialSectionId = parseInt(root.getAttribute('data-section-id') || '0', 10);
  var apiBase = root.getAttribute('data-api-base') || '/admin/api/controlled_book_editor_api.php';
  var documentType = root.getAttribute('data-document-type') || 'manual';
  var isAnnexBook = root.getAttribute('data-annex-book') === '1';
  var initialViewMode = root.getAttribute('data-initial-view') === 'paginated' ? 'paginated' : 'edit';
  var formSelectedBlockId = 0;

  var treeEl = document.getElementById('cpbSectionTree');
  var treeToggleAllBtn = document.getElementById('cpbTreeToggleAll');
  var canvasEl = document.getElementById('cpbCanvas');
  var sectionAssemblyEl = document.getElementById('cpbSectionAssembly');
  var sectionAssemblyLabelEl = document.getElementById('cpbSectionAssemblyLabel');
  var sectionAssemblyBarEl = document.getElementById('cpbSectionAssemblyBar');
  var sectionAssemblyProgressEl = document.getElementById('cpbSectionAssemblyProgress');
  var toolbarEl = document.getElementById('cpbToolbar');
  var toolbarMainEl = document.getElementById('cpbToolbarMain');
  var toolbarTocEl = document.getElementById('cpbToolbarToc');
  var toolbarLepEl = document.getElementById('cpbToolbarLep');
  var toolbarPart0El = document.getElementById('cpbToolbarPart0');
  var tableToolbarEl = document.getElementById('cpbTableToolbar');
  var saveStatusEl = document.getElementById('cpbSaveStatus');
  var addSubBtn = document.getElementById('cpbAddSubsection');
  var editOutlineBtn = document.getElementById('cpbEditOutline');
  var outlinePanelEl = document.getElementById('cpbStructModal');
  var outlineBodyEl = document.getElementById('cpbOutlineBody');
  var structCloseBtn = document.getElementById('cpbStructClose');
  var structDoneBtn = document.getElementById('cpbStructDone');
  var structStatusEl = document.getElementById('cpbStructStatus');
  var treeHeadTitleEl = document.getElementById('cpbTreeHeadTitle');
  var imageInput = document.getElementById('cpbImageInput');
  var paragraphStyleSelect = document.getElementById('cpbParagraphStyleSelect');
  var regulatoryRefInput = document.getElementById('cpbRegulatoryRef');
  var crossRefDocSelect = document.getElementById('cpbCrossRefDoc');
  var crossRefKeySelect = document.getElementById('cpbCrossRefKey');
  var crossRefClearBtn = document.getElementById('cpbCrossRefClear');
  var crossRefAnnex = {};
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
  var listStartInput = document.getElementById('cpbListStart');
  var fullscreenBtn = document.getElementById('cpbFullscreen');
  var zoomInBtn = document.getElementById('cpbZoomIn');
  var zoomOutBtn = document.getElementById('cpbZoomOut');
  var zoomLabelEl = document.getElementById('cpbZoomLabel');
  var indentBtn = document.getElementById('cpbIndent');
  var outdentBtn = document.getElementById('cpbOutdent');
  var viewEditBtn = document.getElementById('cpbViewEdit');
  var viewPaginatedBtn = document.getElementById('cpbViewPaginated');
  var paginationToolsEl = document.getElementById('cpbPaginationTools');
  var paginationRegenerateBtn = document.getElementById('cpbPaginationRegenerate');
  var paginationApproveBtn = document.getElementById('cpbPaginationApprove');
  var pageBreakBtn = document.getElementById('cpbInsertPageBreak');
  var paginationStatusEl = document.getElementById('cpbPaginationStatus');
  var publicationCssEl = document.getElementById('cpbPublicationCss');
  var liveProjectionEnabled = (
    new URLSearchParams(window.location.search).get('live_projection') === '1'
  ) && new URLSearchParams(window.location.search).get('continuous_editor') !== '1';
  var liveProjectionEl = null;
  var liveProjectionPagesEl = null;
  var liveProjectionStatusEl = null;

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
    outlineOpen: false,
    pageLayout: {},
    pageHeader: null,
    pageFooter: null,
    headerTokens: [],
    headerPreviewTokens: {},
    pageHeaderScope: 'main',
    versionInfo: {},
    publicationFontCSS: '',
    authoritativeEditorPageStartsEnabled: false,
    authoritativeEditorPageStarts: [],
    authoritativeEditorPageStartsVersionId: 0,
    authoritativeEditorPageStartsSectionId: 0,
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
    tableBlockClipboard: null,
    canvasZoom: 100,
    lastStyleTarget: null,
    activeOrderedList: null,
    activeTableToolsBlock: null,
    activeTableToolsAnchor: null,
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
    selectedTableCells: [],
    tableCellUndoLock: false,
    contentUndoLock: false,
    isAnnexCrossRefSection: false,
    crossRefAnnexSectionId: 0,
    tocSyncTimer: null,
    viewMode: 'edit',
    paginatedResult: null,
    manualBreaks: [],
    paginationCandidates: [],
    paginationStale: false,
    paginationRegenerateTimer: null,
    pendingPaginatedAnchor: '',
    lastPaginatedRange: null,
    sectionPageIndex: 0,
    printLayoutTimer: null,
    printPageCount: 1,
    authoritativePageCount: 0,
    sectionPageStarts: {},
    sectionLoadSequence: 0,
    sectionAssemblyProgress: 0,
    livePagination: {
      mutationRevision: 0,
      scheduledRevision: 0,
      activeRevision: 0,
      acceptedRevision: 0,
      pendingMutation: null,
      activeRequest: null,
      debounceTimer: null,
      retryMutation: null,
      requestSequence: 0,
      sourceHash: '',
      status: 'current',
      lastError: '',
      retryAvailable: false,
    },
    liveProjection: {
      enabled: liveProjectionEnabled,
      loading: false,
      requestSequence: 0,
      acceptedSequence: 0,
      sectionId: 0,
      pageCount: 0,
      freshness: 'unknown',
      error: '',
    },
  };

  var INDENT_MAX_LEVEL = 8;
  var ZOOM_MIN = 50;
  var ZOOM_MAX = 200;
  var ZOOM_STEP = 10;
  var PRINT_PAGE = {
    width: 816,
    height: 1056,
    gap: 28,
    side: 56,
    contentTop: 152,
    contentHeight: 744,
    headerTop: 48,
    headerHeight: 84,
    headerGap: 20,
    footerTop: 920,
    footerHeight: 72,
    footerGap: 24,
    bottomMargin: 64,
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

  function setSectionAssembly(active, label, progress) {
    progress = Math.max(
      state.sectionAssemblyProgress || 0,
      Math.min(100, Math.round(Number(progress || 0)))
    );
    state.sectionAssemblyProgress = active ? progress : 0;
    root.classList.toggle('cpb-section-assembly-active', !!active);
    root.setAttribute('aria-busy', active ? 'true' : 'false');
    if (!sectionAssemblyEl) return;
    sectionAssemblyEl.hidden = !active;
    if (label && sectionAssemblyLabelEl) sectionAssemblyLabelEl.textContent = label;
    if (sectionAssemblyBarEl) sectionAssemblyBarEl.style.width = progress + '%';
    if (sectionAssemblyProgressEl) sectionAssemblyProgressEl.textContent = progress + '%';
  }

  function nextAnimationFrame() {
    return new Promise(function (resolve) {
      window.requestAnimationFrame(function () { resolve(); });
    });
  }

  function settleWithin(promise, timeoutMs) {
    return Promise.race([
      Promise.resolve(promise).catch(function () { return null; }),
      new Promise(function (resolve) {
        setTimeout(function () { resolve(null); }, timeoutMs);
      }),
    ]);
  }

  function waitForCanvasImages() {
    var images = Array.prototype.slice.call(canvasEl.querySelectorAll('img'));
    if (!images.length) return Promise.resolve();
    return Promise.all(images.map(function (image) {
      // Pagination needs the dimensions of every source image, including images
      // several pages below the viewport. Lazy loading leaves those images at
      // zero height until the author scrolls near them and invalidates geometry.
      image.loading = 'eager';
      image.setAttribute('loading', 'eager');
      image.setAttribute('fetchpriority', 'high');
      if (image.complete) {
        return typeof image.decode === 'function'
          ? image.decode().catch(function () { return null; })
          : Promise.resolve();
      }
      return new Promise(function (resolve) {
        var done = function () {
          image.removeEventListener('load', done);
          image.removeEventListener('error', done);
          if (typeof image.decode === 'function') {
            image.decode().catch(function () { return null; }).then(resolve);
          } else {
            resolve();
          }
        };
        image.addEventListener('load', done, { once: true });
        image.addEventListener('error', done, { once: true });
      });
    }));
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

  function caretRangeAtPointWithin(element, clientX, clientY) {
    if (!element) return null;
    var range = null;
    if (document.caretPositionFromPoint) {
      var position = document.caretPositionFromPoint(clientX, clientY);
      if (position && position.offsetNode) {
        range = document.createRange();
        range.setStart(position.offsetNode, position.offset);
        range.collapse(true);
      }
    } else if (document.caretRangeFromPoint) {
      range = document.caretRangeFromPoint(clientX, clientY);
    }
    if (!range) return null;
    var node = range.startContainer.nodeType === Node.ELEMENT_NODE
      ? range.startContainer
      : range.startContainer.parentElement;
    return node && (node === element || element.contains(node)) ? range.cloneRange() : null;
  }

  function keepCaretInClickedTableCell(cell, fallbackRange) {
    if (!cell || !cell.isConnected || !cell.isContentEditable) return;
    var selection = window.getSelection();
    var anchorNode = selection && selection.anchorNode;
    var anchorElement = anchorNode
      ? (anchorNode.nodeType === Node.ELEMENT_NODE ? anchorNode : anchorNode.parentElement)
      : null;
    if (
      document.activeElement === cell
      && anchorElement
      && (anchorElement === cell || cell.contains(anchorElement))
    ) {
      return;
    }
    try {
      cell.focus({ preventScroll: true });
    } catch (err) {
      cell.focus();
    }
    var range = fallbackRange;
    if (!range || !range.startContainer || !range.startContainer.isConnected) {
      range = document.createRange();
      range.selectNodeContents(cell);
      range.collapse(false);
    }
    selection.removeAllRanges();
    selection.addRange(range);
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

  function captureEditorSurfaceBookmarks(reason) {
    state.surfaceBookmarks = {
      reason: String(reason || 'surface-reconcile'),
      section_id: state.sectionId,
      selection: captureSemanticSelectionBookmark(),
      scroll: captureSemanticScrollBookmark(),
    };
    return state.surfaceBookmarks;
  }

  function restoreEditorSurfaceBookmarks(reason) {
    var bookmarks = state.surfaceBookmarks;
    if (!bookmarks || bookmarks.section_id !== state.sectionId) return false;
    if (reason && bookmarks.reason !== reason) return false;
    state.surfaceBookmarks = null;
    var restoredSelection = restoreSemanticSelectionBookmark(bookmarks.selection);
    restoreSemanticScrollBookmark(bookmarks.scroll);
    root.dispatchEvent(new CustomEvent('cpb:surface-bookmarks-restored', {
      detail: {
        reason: bookmarks.reason,
        selection_restored: restoredSelection,
        scroll_restored: !!bookmarks.scroll,
      },
    }));
    return true;
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
      scheduleUnifiedPrintLayout(0);
      return;
    }
    canvasEl.innerHTML = pageHtml;
    wireCanvas();
    applyCanvasZoom(state.canvasZoom, false);
    scheduleUnifiedPrintLayout(0);
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

  var SOURCE_MUTATION_ACTIONS = {
    update_block: 'suffix',
    create_block: 'suffix',
    delete_block: 'suffix',
    move_block: 'suffix',
    split_block_page_break: 'suffix',
    upload_image: 'suffix',
    save_section_layout: 'suffix',
    create_subsection: 'global',
    rename_outline_part: 'global',
    rename_outline_chapter: 'global',
    add_outline_chapter: 'global',
    delete_outline_chapter: 'global',
    move_outline_chapter: 'global',
    promote_outline_heading: 'global',
    save_book_styles: 'global',
    copy_book_styles: 'global',
    save_page_header: 'global',
    upload_header_logo: 'global',
    save_cover_page: 'global',
    upload_cover_logo: 'global',
    upload_cover_image: 'global',
    save_toc_settings: 'global',
    regenerate_toc: 'global',
    save_lep_page: 'global',
    regenerate_lep_parts: 'global',
    sign_lep_slot: 'global',
    save_part0_page: 'global',
    regenerate_definitions: 'global',
    regenerate_abbreviations: 'global',
    import_definitions_text: 'global',
    regenerate_highlights: 'global',
    regenerate_annex_register: 'global',
    regenerate_annex_highlights: 'global',
    save_callout_presets: 'global',
    sync_manual_structure: 'global',
    recompute_section_numbers: 'global',
    detect_callouts: 'global',
    detect_hyperlinks: 'global',
    detect_annex_refs: 'global',
  };
  var AUTO_HIGHLIGHT_ACTIONS = {
    update_block: true,
    create_block: true,
    delete_block: true,
    move_block: true,
    split_block_page_break: true,
    upload_image: true,
    create_subsection: true,
    rename_outline_part: true,
    rename_outline_chapter: true,
    add_outline_chapter: true,
    delete_outline_chapter: true,
    move_outline_chapter: true,
    promote_outline_heading: true,
    save_part0_page: true,
  };
  var autoHighlightsTimer = null;

  function scheduleAutomaticHighlights(action) {
    // Annex Books maintain their per-Annex revision register server-side.
    // The Highlight of Changes generator belongs only to Books/Manuals.
    if (isAnnexBook) return;
    if (!AUTO_HIGHLIGHT_ACTIONS[action] || !state.editable || state.versionId <= 0) return;
    if (autoHighlightsTimer) clearTimeout(autoHighlightsTimer);
    autoHighlightsTimer = setTimeout(function () {
      autoHighlightsTimer = null;
      apiPost('regenerate_highlights', {
        version_id: state.versionId,
        section_id: 0,
      }).then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Automatic change-list refresh failed');
        if (state.part0SectionKey === 'highlights' && res.page_html) {
          applyPageHtmlFromResponse(res.page_html);
          refreshPart0TypographyFromBookStyles();
        }
      }).catch(showError);
    }, 700);
  }

  function livePaginationPayload(payload) {
    if (payload && payload.live_draft) return payload.live_draft;
    if (payload && payload.result && payload.result.live_draft) return payload.result.live_draft;
    if (payload && payload.result) return payload.result;
    return payload || {};
  }

  function setLivePaginationState(status, values) {
    var live = state.livePagination;
    live.status = status;
    Object.keys(values || {}).forEach(function (key) {
      live[key] = values[key];
    });
    root.dispatchEvent(new CustomEvent('cpb:live-pagination-state', {
      detail: {
        version_id: state.versionId,
        status: live.status,
        mutation_revision: live.mutationRevision,
        active_revision: live.activeRevision,
        accepted_revision: live.acceptedRevision,
        source_hash: live.sourceHash,
        retry_available: live.retryAvailable,
        error: live.lastError,
      },
    }));
  }

  function blockAnchorFromResponse(result) {
    var html = result && (result.block_html || result.page_body_html || '');
    if (!html) return '';
    var holder = document.createElement('div');
    holder.innerHTML = html;
    var block = holder.querySelector('.cpb-block[data-stable-anchor]');
    return block ? (block.getAttribute('data-stable-anchor') || '') : '';
  }

  function sourceMutationContext(action, payload, result, overrides) {
    payload = payload || {};
    overrides = overrides || {};
    var blockId = parseInt(
      overrides.block_id || payload.block_id || result && result.block_id || '0',
      10
    ) || null;
    var block = blockId
      ? canvasEl.querySelector('.cpb-block[data-block-id="' + blockId + '"]')
      : null;
    var stableAnchor = String(
      overrides.stable_anchor
      || payload.stable_anchor
      || payload.before_block_anchor
      || block && block.getAttribute('data-stable-anchor')
      || blockAnchorFromResponse(result)
      || ''
    );
    return {
      version_id: parseInt(overrides.version_id || payload.version_id || state.versionId || '0', 10) || 0,
      section_id: parseInt(overrides.section_id || payload.section_id || state.sectionId || '0', 10) || null,
      block_id: blockId,
      stable_anchor: stableAnchor,
      mutation_kind: String(overrides.mutation_kind || action || 'source_mutation'),
      layout_impact: String(overrides.layout_impact || SOURCE_MUTATION_ACTIONS[action] || 'suffix'),
    };
  }

  function livePaginationDelay(mutation) {
    if (!mutation) return 450;
    return mutation.mutation_kind === 'update_block' ? 450 : 0;
  }

  function scheduleLivePagination(mutation) {
    var live = state.livePagination;
    if (!mutation || mutation.client_mutation_revision <= live.acceptedRevision) return;
    if (
      !live.pendingMutation
      || mutation.client_mutation_revision >= live.pendingMutation.client_mutation_revision
    ) {
      live.pendingMutation = mutation;
    }
    live.scheduledRevision = Math.max(
      live.scheduledRevision,
      mutation.client_mutation_revision
    );
    setLivePaginationState(
      live.activeRequest ? 'stale' : 'pending',
      { retryAvailable: false, lastError: '' }
    );
    clearTimeout(live.debounceTimer);
    live.debounceTimer = setTimeout(function () {
      live.debounceTimer = null;
      runPendingLivePagination();
    }, livePaginationDelay(mutation));
  }

  function normalizeLivePaginationError(error) {
    if (!error) return 'Live pagination failed.';
    if (typeof error === 'string') return error;
    return String(error.message || error.error || 'Live pagination failed.');
  }

  function requestLivePagination(action, mutation) {
    var live = state.livePagination;
    var revision = mutation ? mutation.client_mutation_revision : live.mutationRevision;
    var requestSequence = ++live.requestSequence;
    var body = {
      action: action,
      book_version_id: state.versionId,
      book_key: state.versionInfo.book_key || '',
      client_mutation_revision: revision,
      request_token: String(state.versionId) + ':' + revision + ':' + requestSequence,
    };
    if (mutation) {
      body.section_id = mutation.section_id;
      body.block_id = mutation.block_id;
      body.stable_anchor = mutation.stable_anchor;
      body.mutation_kind = mutation.mutation_kind;
      body.layout_impact = mutation.layout_impact;
    }
    return paginationRequest('/admin/api/controlled_book_page_map_api.php', body);
  }

  function observeLivePaginationResult(payload, requestedRevision) {
    var live = state.livePagination;
    var result = livePaginationPayload(payload);
    if (requestedRevision < live.mutationRevision) {
      setLivePaginationState('stale', { retryAvailable: false });
      return false;
    }
    var status = String(result.status || 'pending');
    var fingerprint = result.current_fingerprint || result.requested_fingerprint || result.fingerprint || {};
    var sourceHash = String(result.source_hash || fingerprint.source_hash || '');
    if (status === 'current') {
      live.acceptedRevision = requestedRevision;
      setLivePaginationState('current', {
        sourceHash: sourceHash,
        retryAvailable: false,
        retryMutation: null,
        lastError: '',
      });
      state.paginationStale = false;
      loadUnifiedManualBreaks().catch(function () {});
      return true;
    }
    if (status === 'failed' || status === 'retry_available') {
      setLivePaginationState('failed', {
        sourceHash: sourceHash || live.sourceHash,
        retryAvailable: true,
        retryMutation: live.retryMutation,
        lastError: normalizeLivePaginationError(
          result.error || result.last_error || result.last_error_message
        ),
      });
      return false;
    }
    if (status === 'stale') {
      setLivePaginationState('stale', {
        sourceHash: sourceHash || live.sourceHash,
        retryAvailable: false,
      });
      if (live.retryMutation) {
        live.pendingMutation = live.retryMutation;
      }
      return false;
    }
    setLivePaginationState(status === 'generating' ? 'generating' : 'pending', {
      sourceHash: sourceHash || live.sourceHash,
      retryAvailable: false,
    });
    scheduleLivePaginationStatusPoll(requestedRevision);
    return false;
  }

  function scheduleLivePaginationStatusPoll(requestedRevision) {
    var live = state.livePagination;
    clearTimeout(live.debounceTimer);
    live.debounceTimer = setTimeout(function () {
      live.debounceTimer = null;
      if (live.pendingMutation && live.pendingMutation.client_mutation_revision > requestedRevision) {
        runPendingLivePagination();
        return;
      }
      if (live.activeRequest) {
        scheduleLivePaginationStatusPoll(requestedRevision);
        return;
      }
      live.activeRevision = requestedRevision;
      live.activeRequest = requestLivePagination('live_status', {
        client_mutation_revision: requestedRevision,
      }).then(function (payload) {
        observeLivePaginationResult(payload, requestedRevision);
      }).catch(function (error) {
        setLivePaginationState('failed', {
          retryAvailable: true,
          lastError: normalizeLivePaginationError(error),
        });
      }).finally(function () {
        live.activeRequest = null;
        live.activeRevision = 0;
        if (live.pendingMutation) runPendingLivePagination();
      });
    }, 1000);
  }

  function runPendingLivePagination() {
    var live = state.livePagination;
    if (live.activeRequest) return;
    var mutation = live.pendingMutation;
    if (!mutation) return;
    live.pendingMutation = null;
    live.retryMutation = mutation;
    live.activeRevision = mutation.client_mutation_revision;
    setLivePaginationState('generating', {
      retryAvailable: false,
      lastError: '',
    });
    var requestAction = mutation.live_action === 'live_retry' ? 'live_retry' : 'live_ensure';
    live.activeRequest = requestLivePagination(requestAction, mutation)
      .then(function (payload) {
        observeLivePaginationResult(payload, mutation.client_mutation_revision);
      })
      .catch(function (error) {
        setLivePaginationState('failed', {
          retryAvailable: true,
          retryMutation: mutation,
          lastError: normalizeLivePaginationError(error),
        });
      })
      .finally(function () {
        live.activeRequest = null;
        live.activeRevision = 0;
        if (live.pendingMutation) {
          clearTimeout(live.debounceTimer);
          live.debounceTimer = setTimeout(runPendingLivePagination, 0);
        }
      });
  }

  function retryLivePagination() {
    var mutation = state.livePagination.retryMutation;
    if (!mutation) return false;
    scheduleLivePagination(Object.assign({}, mutation, { live_action: 'live_retry' }));
    return true;
  }

  function recordCommittedSourceMutation(action, payload, result, overrides) {
    if (!result || result.ok !== true) return null;
    if (!SOURCE_MUTATION_ACTIONS[action] && !(overrides && overrides.mutation_kind)) {
      return null;
    }
    var detail = sourceMutationContext(action, payload, result, overrides);
    detail.client_mutation_revision = ++state.livePagination.mutationRevision;
    root.dispatchEvent(new CustomEvent('cpb:source-mutation-committed', {
      detail: detail,
    }));
    scheduleLivePagination(detail);
    scheduleAutomaticHighlights(action);
    return detail;
  }

  function apiPost(action, payload) {
    return fetch(apiBase, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ action: action }, payload || {})),
    }).then(function (r) {
      return parseApiResponse(r).then(function (result) {
        recordCommittedSourceMutation(action, payload || {}, result || {});
        return result;
      });
    });
  }

  function apiUpload(formData) {
    var action = String(formData.get('action') || '');
    var payload = {};
    ['version_id', 'section_id', 'block_id', 'stable_anchor'].forEach(function (key) {
      if (formData.has(key)) payload[key] = formData.get(key);
    });
    return fetch(apiBase, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData,
    }).then(function (r) {
      return r.json();
    }).then(function (result) {
      recordCommittedSourceMutation(action, payload, result || {});
      return result;
    });
  }

  function printScale() {
    return Math.max(0.01, state.canvasZoom / 100);
  }

  function printY(element, sheet) {
    return (element.getBoundingClientRect().top - sheet.getBoundingClientRect().top) / printScale();
  }

  function printBottom(element, sheet) {
    return (element.getBoundingClientRect().bottom - sheet.getBoundingClientRect().top) / printScale();
  }

  function printX(element, sheet) {
    return (element.getBoundingClientRect().left - sheet.getBoundingClientRect().left) / printScale();
  }

  function capturePrintCaret() {
    var selection = window.getSelection();
    if (!selection || selection.rangeCount < 1) return null;
    var range = selection.getRangeAt(0);
    var node = range.startContainer.nodeType === Node.ELEMENT_NODE
      ? range.startContainer
      : range.startContainer.parentElement;
    var field = node && node.closest ? node.closest('[contenteditable="true"]') : null;
    var block = field ? field.closest('.cpb-block') : null;
    if (!field || !block || !canvasEl.contains(field)) return null;
    var active = document.activeElement;
    if (active !== field && !field.contains(active)) return null;
    var prefix = document.createRange();
    prefix.selectNodeContents(field);
    prefix.setEnd(range.startContainer, range.startOffset);
    return {
      blockId: block.getAttribute('data-block-id') || '',
      field: field.getAttribute('data-field') || '',
      className: field.classList.length ? field.classList[0] : '',
      fieldIdentity: sourceFieldIdentity(field, block),
      offset: prefix.toString().length,
      domRange: range.cloneRange(),
      hadFocus: true,
    };
  }

  function restorePrintCaret(caret) {
    if (!caret || !caret.blockId) return;
    var block = canvasEl.querySelector('.cpb-block[data-block-id="' + caret.blockId + '"]');
    if (!block) return;
    var field = caret.fieldIdentity ? findSourceField(block, caret.fieldIdentity) : null;
    if (!field && caret.field) {
      var matchingFields = block.querySelectorAll('[data-field="' + caret.field + '"]');
      field = matchingFields.length === 1 ? matchingFields[0] : null;
    }
    if (!field && caret.className) field = block.querySelector('.' + caret.className);
    if (!field) return;
    var selection = window.getSelection();
    if (caret.hadFocus && document.activeElement !== field) {
      try {
        field.focus({ preventScroll: true });
      } catch (err) {
        field.focus();
      }
    }
    if (
      caret.domRange
      && caret.domRange.startContainer
      && caret.domRange.startContainer.isConnected
      && field.contains(
        caret.domRange.startContainer.nodeType === Node.ELEMENT_NODE
          ? caret.domRange.startContainer
          : caret.domRange.startContainer.parentElement
      )
    ) {
      selection.removeAllRanges();
      selection.addRange(caret.domRange);
      return;
    }
    var boundary = textBoundary(field, caret.offset);
    var range = document.createRange();
    range.setStart(boundary.node, boundary.offset);
    range.collapse(true);
    selection.removeAllRanges();
    selection.addRange(range);
  }

  function removeAutomaticPrintBreaks(sheet) {
    sheet.querySelectorAll('[data-auto-page-break="1"]').forEach(function (node) {
      node.remove();
    });
    var furniture = sheet.querySelector('.cpb-print-furniture-layer');
    if (furniture) furniture.remove();
  }

  function isHeadingParagraphStyle(styleKey) {
    styleKey = canonicalParagraphStyleKey(styleKey || '');
    return styleKey === 'title'
      || styleKey === 'subtitle_1'
      || styleKey === 'subtitle_2'
      || styleKey === 'subtitle_3'
      || styleKey === 'subtitle_4';
  }

  function isHeadingLikeField(field) {
    if (!field) return false;
    if (field.classList.contains('cpb-heading')) return true;
    if (/^H[1-6]$/.test(field.tagName || '')) return true;
    return isHeadingParagraphStyle(field.getAttribute('data-paragraph-style') || '');
  }

  function isHeadingLikeBlock(block) {
    if (!block) return false;
    if ((block.getAttribute('data-block-type') || '') === 'heading') return true;
    return Array.prototype.some.call(
      block.querySelectorAll('.cpb-heading, [data-paragraph-style], h1, h2, h3, h4, h5, h6'),
      isHeadingLikeField
    );
  }

  function hasPrecedingManualPrintBreak(block) {
    var sibling = block ? block.previousElementSibling : null;
    while (sibling && sibling.getAttribute('data-auto-page-break') === '1') {
      if (sibling.getAttribute('data-manual-page-break') === '1') return true;
      sibling = sibling.previousElementSibling;
    }
    return false;
  }

  function hasPrecedingAutomaticPrintBreak(block) {
    var sibling = block ? block.previousElementSibling : null;
    return !!(sibling && sibling.getAttribute('data-auto-page-break') === '1');
  }

  function automaticBreakBefore(body, block, sheet, pageIndex, manualBreak) {
    var pageStart = pageIndex * (PRINT_PAGE.height + PRINT_PAGE.gap);
    var target = pageStart + PRINT_PAGE.height + PRINT_PAGE.gap + PRINT_PAGE.contentTop;
    var current = printY(block, sheet);
    var gridGap = parseFloat(window.getComputedStyle(body).rowGap || '0') || 0;
    var spacer = document.createElement('div');
    spacer.className = 'cpb-flow-page-break';
    spacer.setAttribute('data-auto-page-break', '1');
    spacer.setAttribute('data-editor-only', '1');
    spacer.setAttribute('contenteditable', 'false');
    if (manualBreak) spacer.setAttribute('data-manual-page-break', '1');
    spacer.style.height = Math.max(1, target - current - gridGap) + 'px';
    if (manualBreak) {
      var control = document.createElement('div');
      control.className = 'cpb-manual-break-control';
      control.setAttribute('data-auto-page-break', '1');
      control.setAttribute('data-editor-only', '1');
      control.setAttribute('contenteditable', 'false');
      control.style.top = (
        pageStart + PRINT_PAGE.height + (PRINT_PAGE.gap / 2)
      ) + 'px';
      var label = document.createElement('span');
      label.textContent = 'Page Break';
      control.appendChild(label);
      var remove = document.createElement('button');
      remove.type = 'button';
      remove.textContent = 'Remove';
      remove.setAttribute('aria-label', 'Remove manual page break');
      remove.addEventListener('click', function () {
        var row = state.manualBreaks.find(function (item) {
          return item.before_block_anchor === block.getAttribute('data-stable-anchor');
        });
        if (row) {
          paginationRequest('/admin/api/controlled_book_page_break_api.php', {
            action: 'remove',
            book_version_id: state.versionId,
            break_id: row.id,
          }).then(loadUnifiedManualBreaks).catch(showError);
        }
      });
      control.appendChild(remove);
      sheet.appendChild(control);
    }
    body.insertBefore(spacer, block);
  }

  function automaticBreakBeforeNested(target, sheet, targetContentTop) {
    var spacer = document.createElement('div');
    spacer.className = 'cpb-flow-page-break';
    spacer.setAttribute('data-auto-page-break', '1');
    spacer.setAttribute('data-editor-only', '1');
    spacer.setAttribute('contenteditable', 'false');
    var lineHeight = parseFloat(window.getComputedStyle(target).lineHeight || '0') || 20;
    spacer.style.height = Math.max(
      1,
      targetContentTop - printY(target, sheet) + Math.min(32, Math.max(8, lineHeight))
    ) + 'px';
    target.parentNode.insertBefore(spacer, target);
  }

  function insertTableRowPageBreak(block, sheet) {
    var tbody = tableBody(block);
    if (!tbody) return false;
    var rows = tableSourceRows(tbody);
    var colCount = Math.max(1, tableColCount(block));
    var stride = PRINT_PAGE.height + PRINT_PAGE.gap;
    for (var index = 0; index < rows.length; index++) {
      var row = rows[index];
      var top = printY(row, sheet);
      var bottom = printBottom(row, sheet);
      var rowHeight = Math.max(1, bottom - top);
      if (rowHeight > PRINT_PAGE.contentHeight) continue;
      var pageIndex = Math.max(0, Math.floor(top / stride));
      var contentTop = pageIndex * stride + PRINT_PAGE.contentTop;
      var contentBottom = contentTop + PRINT_PAGE.contentHeight;
      var target = 0;
      if (pageIndex > 0 && top < contentTop - 1) {
        target = contentTop;
      } else if (top > contentBottom - 1 || bottom > contentBottom + 0.5) {
        target = (pageIndex + 1) * stride + PRINT_PAGE.contentTop;
      }
      if (!target || target <= top + 1) continue;
      var breakRow = tableContinuationGroupStart(rows, index, colCount);
      var breakTop = printY(breakRow, sheet);
      var breakPage = Math.max(0, Math.floor(breakTop / stride));
      target = (breakPage + 1) * stride + PRINT_PAGE.contentTop;
      if (target <= breakTop + 1) continue;
      var repeatedRows = tableRepeatedHeaderRows(block);
      var table = block.querySelector('table');
      var tableHead = table ? table.querySelector('thead') : null;
      var repeatedHeight = tableHead
        ? Math.max(0, tableHead.getBoundingClientRect().height / printScale())
        : 0;
      var spacerRow = document.createElement('tr');
      spacerRow.className = 'cpb-table-page-spacer';
      spacerRow.setAttribute('data-auto-page-break', '1');
      spacerRow.setAttribute('data-editor-only', '1');
      spacerRow.setAttribute('contenteditable', 'false');
      var spacerCell = document.createElement('td');
      spacerCell.colSpan = colCount;
      spacerCell.setAttribute('contenteditable', 'false');
      spacerCell.style.cssText = 'height:' + Math.max(1, target - breakTop - repeatedHeight)
        + 'px!important;padding:0!important;border:0!important;line-height:0!important;'
        + 'background:transparent!important;';
      spacerRow.appendChild(spacerCell);
      tbody.insertBefore(spacerRow, breakRow);
      repeatedRows.forEach(function (repeatedRow) {
        tbody.insertBefore(repeatedRow, breakRow);
      });
      return true;
    }
    return false;
  }

  function tableContinuationGroupStart(rows, rowIndex, colCount) {
    var startIndex = rowIndex;
    for (var priorIndex = 0; priorIndex < rowIndex; priorIndex++) {
      var priorRow = rows[priorIndex];
      var spansIntoTarget = Array.prototype.some.call(priorRow.cells, function (cell) {
        return Math.max(1, parseInt(cell.getAttribute('rowspan') || '1', 10) || 1)
          > rowIndex - priorIndex;
      });
      if (spansIntoTarget) startIndex = Math.min(startIndex, priorIndex);
    }
    if (startIndex === rowIndex && rowIndex > 0) {
      var previousRow = rows[rowIndex - 1];
      var previousSpan = Array.prototype.reduce.call(previousRow.cells, function (total, cell) {
        if (cell.hidden || cell.getAttribute('data-rowspan-covered') === '1') return total;
        return total + Math.max(1, parseInt(cell.getAttribute('colspan') || '1', 10) || 1);
      }, 0);
      if (previousRow.cells.length === 1 && previousSpan >= colCount) {
        startIndex = rowIndex - 1;
      }
    }
    return rows[startIndex];
  }

  function tableRepeatedHeaderRows(block) {
    var table = block.querySelector('table');
    var head = table ? table.querySelector('thead') : null;
    if (!head) return [];
    return Array.prototype.map.call(head.rows, function (sourceRow) {
      var clone = sourceRow.cloneNode(true);
      clone.classList.add('cpb-table-repeated-header');
      clone.setAttribute('data-auto-page-break', '1');
      clone.setAttribute('data-editor-only', '1');
      clone.setAttribute('contenteditable', 'false');
      clone.querySelectorAll('[contenteditable]').forEach(function (element) {
        element.setAttribute('contenteditable', 'false');
      });
      clone.querySelectorAll('.cpb-col-resize, button, input, select').forEach(function (control) {
        control.remove();
      });
      return clone;
    });
  }

  function insertTocRowPageBreak(block, sheet) {
    var toc = block.querySelector('.cpb-toc');
    if (!toc) return false;
    var rows = Array.prototype.slice.call(toc.querySelectorAll(':scope > .cpb-toc-row'));
    var stride = PRINT_PAGE.height + PRINT_PAGE.gap;
    var rowGap = parseFloat(window.getComputedStyle(toc).rowGap || '0') || 0;
    for (var index = 0; index < rows.length; index++) {
      var row = rows[index];
      var top = printY(row, sheet);
      var bottom = printBottom(row, sheet);
      if (bottom - top > PRINT_PAGE.contentHeight) continue;
      var pageIndex = Math.max(0, Math.floor(top / stride));
      var contentTop = pageIndex * stride + PRINT_PAGE.contentTop;
      var contentBottom = contentTop + PRINT_PAGE.contentHeight;
      var target = 0;
      if (pageIndex > 0 && top < contentTop - 1) {
        target = contentTop;
      } else if (top > contentBottom - 1 || bottom > contentBottom + 0.5) {
        target = (pageIndex + 1) * stride + PRINT_PAGE.contentTop;
      }
      if (!target || target <= top + 1) continue;
      var spacer = document.createElement('div');
      spacer.className = 'cpb-flow-page-break cpb-toc-page-spacer';
      spacer.setAttribute('data-auto-page-break', '1');
      spacer.setAttribute('data-editor-only', '1');
      spacer.setAttribute('contenteditable', 'false');
      spacer.style.height = Math.max(1, target - top - rowGap) + 'px';
      toc.insertBefore(spacer, row);
      return true;
    }
    return false;
  }

  function paragraphBreak(field, sheet, pageIndex) {
    var textLength = field.textContent.length;
    if (textLength < 2) return false;
    var pageBottom = pageIndex * (PRINT_PAGE.height + PRINT_PAGE.gap)
      + PRINT_PAGE.contentTop + PRINT_PAGE.contentHeight;
    var low = 1;
    var high = textLength - 1;
    var best = -1;
    while (low <= high) {
      var middle = Math.floor((low + high) / 2);
      var before = textBoundary(field, Math.max(0, middle - 1));
      var boundary = textBoundary(field, middle);
      var range = document.createRange();
      range.setStart(before.node, before.offset);
      range.setEnd(boundary.node, boundary.offset);
      var rects = Array.prototype.slice.call(range.getClientRects()).filter(function (rect) {
        return rect.width > 0 || rect.height > 0;
      });
      var endRect = rects.length ? rects[rects.length - 1] : range.getBoundingClientRect();
      var bottom = (endRect.bottom
        - sheet.getBoundingClientRect().top) / printScale();
      if (bottom <= pageBottom) {
        best = middle;
        low = middle + 1;
      } else {
        high = middle - 1;
      }
    }
    if (best <= 0 || best >= textLength) return false;
    var text = field.textContent;
    while (best > 1 && best < text.length && !/\s/.test(text.charAt(best))) best--;
    if (best <= 0) return false;
    var insertion = textBoundary(field, best);
    var insertRange = document.createRange();
    insertRange.setStart(insertion.node, insertion.offset);
    insertRange.collapse(true);
    var spacer = document.createElement('span');
    spacer.className = 'cpb-flow-page-break cpb-flow-page-break--automatic';
    spacer.setAttribute('data-auto-page-break', '1');
    spacer.setAttribute('data-editor-only', '1');
    spacer.setAttribute('contenteditable', 'false');
    insertRange.insertNode(spacer);
    var spacerTop = printY(spacer, sheet);
    var nextContentTop = (pageIndex + 1) * (PRINT_PAGE.height + PRINT_PAGE.gap)
      + PRINT_PAGE.contentTop;
    var lineHeight = parseFloat(window.getComputedStyle(field).lineHeight || '0') || 20;
    spacer.style.height = Math.max(
      1,
      nextContentTop - spacerTop + Math.min(32, Math.max(8, lineHeight))
    ) + 'px';
    return true;
  }

  function renderPrintFurniture(sheet, pageCount) {
    var templateHeader = sheet.querySelector(':scope > .cpb-page-header');
    var templateFooter = sheet.querySelector(':scope > .cpb-page-footer');
    if (templateHeader) templateHeader.classList.add('cpb-print-template');
    if (templateFooter) templateFooter.classList.add('cpb-print-template');
    var layer = document.createElement('div');
    layer.className = 'cpb-print-furniture-layer';
    layer.setAttribute('contenteditable', 'false');
    for (var index = 0; index < pageCount; index++) {
      var page = document.createElement('div');
      page.className = 'cpb-print-page';
      page.style.top = (index * (PRINT_PAGE.height + PRINT_PAGE.gap)) + 'px';
      page.setAttribute(
        'data-page-identity',
        state.liveProjection.enabled ? 'editing-layout-approximate' : 'advisory-page-number'
      );
      var pageNumber = parseInt(state.sectionPageStarts[state.sectionId] || '1', 10) + index;
      var pageTotal = Math.max(
        state.authoritativePageCount,
        parseInt(state.sectionPageStarts[state.sectionId] || '1', 10) + pageCount - 1
      );
      var preview = document.createElement('div');
      preview.innerHTML = state.pageLayout && state.pageLayout.hide_header_footer
        ? ''
        : previewHeaderHtml(
          state.pageHeader,
          state.pageFooter,
          state.liveProjection.enabled ? 'Editing layout' : pageNumber,
          state.liveProjection.enabled ? 'Approximate' : pageTotal
        );
      var header = preview.querySelector('.cpb-page-header');
      var footer = preview.querySelector('.cpb-page-footer');
      if (header) {
        header.classList.add('cpb-print-page-header');
        page.appendChild(header);
      }
      if (footer) {
        footer.classList.add('cpb-print-page-footer');
        page.appendChild(footer);
      }
      layer.appendChild(page);
    }
    sheet.querySelectorAll('.cpb-block--changed').forEach(function (block) {
      var blockTop = printY(block, sheet);
      var blockBottom = printBottom(block, sheet);
      var markerLeft = printX(block, sheet) - 8;
      for (var pageIndex = 0; pageIndex < pageCount; pageIndex++) {
        var pageStart = pageIndex * (PRINT_PAGE.height + PRINT_PAGE.gap);
        var bodyTop = pageStart + PRINT_PAGE.contentTop;
        var bodyBottom = bodyTop + PRINT_PAGE.contentHeight;
        var segmentTop = Math.max(blockTop, bodyTop);
        var segmentBottom = Math.min(blockBottom, bodyBottom);
        if (segmentBottom <= segmentTop + 1) continue;
        var marker = document.createElement('span');
        marker.className = 'cpb-print-change-marker';
        marker.style.left = markerLeft + 'px';
        marker.style.top = (segmentTop - pageStart) + 'px';
        marker.style.height = (segmentBottom - segmentTop) + 'px';
        layer.children[pageIndex].appendChild(marker);
      }
    });
    sheet.insertBefore(layer, sheet.firstChild);
    var renderedFooter = layer.querySelector('.cpb-print-page-footer');
    if (renderedFooter) {
      sheet.style.setProperty(
        '--cpb-editor-footer-clearance',
        (PRINT_PAGE.bottomMargin + renderedFooter.getBoundingClientRect().height + 16) + 'px'
      );
    }
  }

  function authoritativeEditorPageStartsEnabled() {
    return !!state.authoritativeEditorPageStartsEnabled;
  }

  function authoritativeEditorPageStartsFromResult(result, sectionId) {
    if (!result || !result.freshness || result.freshness.is_current !== true) return [];
    var pages = result && Array.isArray(result.pages) ? result.pages : [];
    var seenSectionPage = false;
    var starts = [];
    for (var index = 0; index < pages.length; index++) {
      if (pages[index].is_cover) continue;
      var metadata = pages[index].metadata && typeof pages[index].metadata === 'object'
        ? pages[index].metadata
        : {};
      var coverage = Array.isArray(metadata.coverage) ? metadata.coverage : [];
      var sectionCoverage = coverage.filter(function (entry) {
        return !entry.presentation_copy && parseInt(entry.section_id || '0', 10) === sectionId;
      });
      if (!sectionCoverage.length) continue;
      if (!seenSectionPage) {
        seenSectionPage = true;
        continue;
      }
      var first = sectionCoverage[0];
      var fragmentId = String(first.source_fragment_id || '');
      if (
        parseInt(first.range_start || '0', 10) === 0
        && /\/root$/.test(fragmentId)
      ) {
        starts.push({
          pageNumber: parseInt(pages[index].page_number || '0', 10) || 0,
          sourceFragmentId: fragmentId,
        });
      }
    }
    return starts;
  }

  function loadAuthoritativeEditorPageStarts() {
    if (!authoritativeEditorPageStartsEnabled()) {
      state.authoritativeEditorPageStarts = [];
      state.authoritativeEditorPageStartsVersionId = 0;
      state.authoritativeEditorPageStartsSectionId = 0;
      return Promise.resolve(true);
    }
    if (
      state.authoritativeEditorPageStartsVersionId === state.versionId
      && state.authoritativeEditorPageStartsSectionId === state.sectionId
    ) {
      return Promise.resolve(true);
    }
    var url = '/admin/api/controlled_book_page_map_api.php?action=stored_preview'
      + '&book_version_id=' + state.versionId
      + '&section_id=' + state.sectionId
      + '&include_style=0&check_freshness=1';
    return paginationRequest(url).then(function (response) {
      state.authoritativeEditorPageStarts = authoritativeEditorPageStartsFromResult(
        response.result || {},
        state.sectionId
      );
      state.authoritativeEditorPageStartsVersionId = state.versionId;
      state.authoritativeEditorPageStartsSectionId = state.sectionId;
      return true;
    });
  }

  function authoritativeEditorPageStartAnchors(body) {
    if (!authoritativeEditorPageStartsEnabled() || !body) return {};
    var starts = state.authoritativeEditorPageStarts || [];
    var anchors = {};
    body.querySelectorAll(':scope > .cpb-block[data-stable-anchor]').forEach(function (block) {
      var anchor = String(block.getAttribute('data-stable-anchor') || '');
      if (!anchor) return;
      var marker = '/' + anchor + '/';
      if (starts.some(function (start) {
        return String(start.sourceFragmentId || '').indexOf(marker) !== -1;
      })) {
        anchors[anchor] = true;
      }
    });
    return anchors;
  }

  function measurePrintFurnitureGeometry(sheet) {
    var hideFurniture = !!(state.pageLayout && state.pageLayout.hide_header_footer);
    var host = document.createElement('div');
    host.setAttribute('aria-hidden', 'true');
    var contentWidth = Math.max(1, (PRINT_PAGE.width || 816) - ((PRINT_PAGE.side || 56) * 2));
    host.style.cssText = 'position:fixed;left:-10000px;top:0;width:' + contentWidth + 'px;'
      + 'height:auto;visibility:hidden;overflow:visible;pointer-events:none;';
    var sheetStyle = window.getComputedStyle(sheet);
    [
      '--cpb-frame-radius',
      '--cpb-frame-border-color',
      '--cpb-frame-border-width',
    ].forEach(function (property) {
      var value = sheetStyle.getPropertyValue(property);
      if (value) host.style.setProperty(property, value);
    });
    host.innerHTML = hideFurniture
      ? ''
      : previewHeaderHtml(state.pageHeader, state.pageFooter, 1, 1);
    document.body.appendChild(host);

    function measure(selector) {
      var element = host.querySelector(selector);
      if (!element) return 0;
      element.style.setProperty('position', 'static', 'important');
      element.style.setProperty('inset', 'auto', 'important');
      element.style.setProperty('width', '100%', 'important');
      element.style.setProperty('height', 'auto', 'important');
      element.style.setProperty('margin', '0', 'important');
      element.style.setProperty('box-sizing', 'border-box', 'important');
      return Math.max(
        element.getBoundingClientRect().height,
        element.scrollHeight
      );
    }

    var headerHeight = measure('.cpb-page-header');
    var footerHeight = measure('.cpb-page-footer');
    host.remove();

    PRINT_PAGE.headerHeight = headerHeight;
    PRINT_PAGE.footerHeight = footerHeight;
    PRINT_PAGE.contentTop = PRINT_PAGE.headerTop
      + headerHeight
      + (headerHeight > 0 ? PRINT_PAGE.headerGap : 0);
    PRINT_PAGE.footerTop = PRINT_PAGE.height - PRINT_PAGE.bottomMargin - footerHeight;
    PRINT_PAGE.contentHeight = PRINT_PAGE.footerTop
      - (footerHeight > 0 ? PRINT_PAGE.footerGap : 0)
      - PRINT_PAGE.contentTop;
    if (PRINT_PAGE.contentHeight <= 0) {
      PRINT_PAGE.headerHeight = 84;
      PRINT_PAGE.footerHeight = 72;
      PRINT_PAGE.contentTop = PRINT_PAGE.headerTop
        + PRINT_PAGE.headerHeight
        + PRINT_PAGE.headerGap;
      PRINT_PAGE.footerTop = PRINT_PAGE.height - PRINT_PAGE.bottomMargin - PRINT_PAGE.footerHeight;
      PRINT_PAGE.contentHeight = PRINT_PAGE.footerTop
        - PRINT_PAGE.footerGap
        - PRINT_PAGE.contentTop;
    }
    sheet.style.setProperty('--cpb-print-content-top', PRINT_PAGE.contentTop + 'px');
    sheet.style.setProperty('--cpb-print-header-height', PRINT_PAGE.headerHeight + 'px');
    sheet.style.setProperty('--cpb-print-footer-top', PRINT_PAGE.footerTop + 'px');
    sheet.style.setProperty('--cpb-print-footer-height', PRINT_PAGE.footerHeight + 'px');
  }

  function syncPrintPageGeometry(sheet) {
    var landscape = !!(state.pageLayout && state.pageLayout.orientation === 'landscape');
    PRINT_PAGE.width = landscape ? 1056 : 816;
    PRINT_PAGE.height = landscape ? 816 : 1056;
    if (!sheet) return;
    sheet.classList.toggle('cpb-sheet--landscape', landscape);
    sheet.style.setProperty('--cpb-print-page-width', PRINT_PAGE.width + 'px');
    sheet.style.setProperty('--cpb-print-page-height', PRINT_PAGE.height + 'px');
    sheet.style.setProperty(
      '--cpb-print-content-width',
      (PRINT_PAGE.width - PRINT_PAGE.side * 2) + 'px'
    );
  }

  function applyUnifiedPrintLayout() {
    var sheet = canvasEl.querySelector('.cpb-sheet');
    var body = sheet ? sheet.querySelector('[data-blocks-root="1"]') : null;
    if (!sheet || !body || state.isCoverSection) return;
    var caret = capturePrintCaret();
    removeAutomaticPrintBreaks(sheet);
    sheet.classList.add('cpb-print-layout');
    syncPrintPageGeometry(sheet);
    applyStoredTableWidths();
    measurePrintFurnitureGeometry(sheet);
    var blocks = Array.prototype.slice.call(body.querySelectorAll(':scope > .cpb-block'));
    var manualAnchors = {};
    state.manualBreaks.forEach(function (row) {
      if (parseInt(row.section_id || '0', 10) === state.sectionId) {
        manualAnchors[row.before_block_anchor] = true;
      }
    });
    var authoritativePageStartAnchors = authoritativeEditorPageStartAnchors(body);
    var inserted = true;
    var attempts = 0;
    while (inserted && attempts < 200) {
      inserted = false;
      attempts++;
      blocks = Array.prototype.slice.call(body.querySelectorAll(':scope > .cpb-block'));
      for (var index = 0; index < blocks.length; index++) {
        var block = blocks[index];
        var top = printY(block, sheet);
        var bottom = printBottom(block, sheet);
        var pageIndex = Math.max(0, Math.floor(top / (PRINT_PAGE.height + PRINT_PAGE.gap)));
        var contentTop = pageIndex * (PRINT_PAGE.height + PRINT_PAGE.gap) + PRINT_PAGE.contentTop;
        var contentBottom = contentTop + PRINT_PAGE.contentHeight;
        var anchor = block.getAttribute('data-stable-anchor') || '';
        var hasManualSpacer = hasPrecedingManualPrintBreak(block);
        var hasAutomaticSpacer = hasPrecedingAutomaticPrintBreak(block);
        if (manualAnchors[anchor] && top > contentTop + 1 && !hasManualSpacer) {
          automaticBreakBefore(body, block, sheet, pageIndex, true);
          inserted = true;
          break;
        }
        if (
          authoritativePageStartAnchors[anchor]
          && top > contentTop + 1
          && !hasAutomaticSpacer
        ) {
          automaticBreakBefore(body, block, sheet, pageIndex, false);
          var projectedSpacer = block.previousElementSibling;
          if (projectedSpacer) projectedSpacer.setAttribute('data-authoritative-page-break', '1');
          inserted = true;
          break;
        }
        var isHeading = isHeadingLikeBlock(block);
        var nextBlock = blocks[index + 1] || null;
        var nextMeaningfulBottom = nextBlock
          ? printY(nextBlock, sheet) + Math.min(64, Math.max(1, printBottom(nextBlock, sheet) - printY(nextBlock, sheet)))
          : 0;
        if (
          isHeading
          && !hasManualSpacer
          && nextBlock
          && top > contentTop + 1
          && nextMeaningfulBottom > contentBottom
        ) {
          automaticBreakBefore(body, block, sheet, pageIndex, false);
          inserted = true;
          break;
        }
        var isTableBlock = (block.getAttribute('data-block-type') || '') === 'table'
          || !!block.querySelector('.cpb-table tbody[data-table-part="body"]');
        if (isTableBlock && insertTableRowPageBreak(block, sheet)) {
          inserted = true;
          break;
        }
        var isTocBlock = (block.getAttribute('data-block-type') || '') === 'toc'
          || !!block.querySelector('.cpb-toc-row');
        if (isTocBlock && insertTocRowPageBreak(block, sheet)) {
          inserted = true;
          break;
        }
        if (bottom <= contentBottom + 0.5) continue;
        if (isTableBlock) continue;
        if (isTocBlock) continue;
        var flowingFields = Array.prototype.slice.call(block.querySelectorAll(
          '.cpb-paragraph[contenteditable="true"],'
          + '.cpb-list[contenteditable="true"],'
          + '.cpb-list-continuation[contenteditable="true"],'
          + '.cpb-callout-text[contenteditable="true"]'
        )).filter(function (field) {
          return !isHeadingLikeField(field);
        });
        var flowingField = null;
        var laterField = null;
        var laterFieldTarget = 0;
        flowingFields.some(function (field) {
          var fieldTop = printY(field, sheet);
          var candidatePage = Math.max(0, Math.floor(
            fieldTop / (PRINT_PAGE.height + PRINT_PAGE.gap)
          ));
          var candidateTop = candidatePage * (PRINT_PAGE.height + PRINT_PAGE.gap)
            + PRINT_PAGE.contentTop;
          var candidateBottom = candidatePage * (PRINT_PAGE.height + PRINT_PAGE.gap)
            + PRINT_PAGE.contentTop + PRINT_PAGE.contentHeight;
          if (fieldTop < candidateTop - 1) {
            laterField = field;
            laterFieldTarget = candidateTop;
            return true;
          }
          if (fieldTop >= candidateBottom - 1) {
            laterField = field;
            laterFieldTarget = (candidatePage + 1) * (PRINT_PAGE.height + PRINT_PAGE.gap)
              + PRINT_PAGE.contentTop;
            return true;
          }
          if (printBottom(field, sheet) > candidateBottom + 0.5) {
            flowingField = field;
            return true;
          }
          return false;
        });
        var fieldPage = flowingField
          ? Math.max(0, Math.floor(printY(flowingField, sheet)
            / (PRINT_PAGE.height + PRINT_PAGE.gap)))
          : pageIndex;
        var paragraphPage = fieldPage + (flowingField
          ? flowingField.querySelectorAll('[data-auto-page-break="1"]').length
          : 0);
        var paragraphBottom = paragraphPage * (PRINT_PAGE.height + PRINT_PAGE.gap)
          + PRINT_PAGE.contentTop + PRINT_PAGE.contentHeight;
        if (flowingField && printBottom(block, sheet) > paragraphBottom
          && top < contentBottom - 12 && paragraphBreak(flowingField, sheet, paragraphPage)) {
          inserted = true;
          break;
        }
        if (!flowingField && laterField) {
          automaticBreakBeforeNested(laterField, sheet, laterFieldTarget);
          inserted = true;
          break;
        }
        if (flowingFields.length) continue;
        if (top > contentTop + 1) {
          automaticBreakBefore(body, block, sheet, pageIndex, false);
          inserted = true;
          break;
        }
      }
    }
    var lastBlock = blocks.length ? blocks[blocks.length - 1] : null;
    var lastBottom = lastBlock ? printBottom(lastBlock, sheet) : PRINT_PAGE.contentTop;
    var pageCount = Math.max(
      1,
      Math.floor(lastBottom / (PRINT_PAGE.height + PRINT_PAGE.gap)) + 1
    );
    state.printPageCount = pageCount;
    sheet.style.height = ((pageCount * PRINT_PAGE.height)
      + ((pageCount - 1) * PRINT_PAGE.gap)) + 'px';
    renderPrintFurniture(sheet, pageCount);
    restorePrintCaret(caret);
  }

  function scheduleUnifiedPrintLayout(delay) {
    clearTimeout(state.printLayoutTimer);
    state.printLayoutTimer = setTimeout(function () {
      window.requestAnimationFrame(function () {
        applyUnifiedPrintLayout();
      });
    }, delay == null ? 450 : delay);
  }

  function loadUnifiedManualBreaks(scheduleLayout) {
    return Promise.all([
      paginationRequest(
        '/admin/api/controlled_book_page_break_api.php?action=list&book_version_id=' + state.versionId
      ),
      paginationRequest(
        '/admin/api/controlled_book_page_map_api.php?action=section_index&book_version_id=' + state.versionId
      ),
    ]).then(function (responses) {
      state.manualBreaks = responses[0].breaks || [];
      state.paginationCandidates = responses[0].candidates || [];
      state.sectionPageStarts = responses[1].section_page_index || {};
      state.authoritativePageCount = parseInt(responses[1].page_count || '0', 10) || 0;
      if (scheduleLayout !== false) scheduleUnifiedPrintLayout(0);
      return responses[0];
    });
  }

  function paginationRequest(url, body) {
    var options = { credentials: 'same-origin' };
    if (body) {
      options.method = 'POST';
      options.headers = { 'Content-Type': 'application/json' };
      options.body = JSON.stringify(body);
    }
    return fetch(url, options).then(function (r) {
      return parseApiResponse(r).then(function (payload) {
        if (!r.ok || !payload.ok) throw new Error(payload.error || 'Pagination request failed');
        if (
          body
          && url.indexOf('controlled_book_page_break_api.php') !== -1
          && /^(insert|remove|move)$/.test(String(body.action || ''))
        ) {
          recordCommittedSourceMutation(body.action, body, payload, {
            mutation_kind: 'manual_page_break_' + String(body.action),
            layout_impact: 'suffix',
            stable_anchor: body.stable_anchor || body.before_block_anchor || '',
          });
        }
        return payload;
      });
    });
  }

  function setPaginationStatus(text, stale) {
    if (!paginationStatusEl) return;
    paginationStatusEl.hidden = false;
    paginationStatusEl.textContent = text;
    paginationStatusEl.classList.toggle('is-stale', !!stale);
  }

  function installLiveProjectionSurface() {
    if (!state.liveProjection.enabled || liveProjectionEl) return;
    var workspace = canvasEl.parentElement;
    if (!workspace) return;
    var stage = document.createElement('div');
    stage.className = 'cpb-editor-stage';
    stage.id = 'cpbEditorStage';
    workspace.insertBefore(stage, canvasEl);
    stage.appendChild(canvasEl);

    liveProjectionEl = document.createElement('aside');
    liveProjectionEl.className = 'cpb-live-projection';
    liveProjectionEl.id = 'cpbProjection';
    liveProjectionEl.setAttribute('aria-label', 'Read-only authoritative page projection');
    var heading = document.createElement('div');
    heading.className = 'cpb-live-projection__head';
    heading.innerHTML = '<strong>Authoritative pages</strong>'
      + '<span class="cpb-live-projection__readonly">Read-only</span>';
    liveProjectionStatusEl = document.createElement('span');
    liveProjectionStatusEl.id = 'cpbProjectionStatus';
    liveProjectionStatusEl.className = 'cpb-live-projection__status';
    liveProjectionStatusEl.textContent = 'Loading stored page map…';
    heading.appendChild(liveProjectionStatusEl);
    liveProjectionPagesEl = document.createElement('div');
    liveProjectionPagesEl.id = 'cpbProjectionPages';
    liveProjectionPagesEl.className = 'cpb-live-projection__pages';
    liveProjectionEl.appendChild(heading);
    liveProjectionEl.appendChild(liveProjectionPagesEl);
    stage.appendChild(liveProjectionEl);
    root.classList.add('cpb-editor-live-projection');
  }

  function setLiveProjectionStatus(text, stateName) {
    if (!liveProjectionStatusEl) return;
    liveProjectionStatusEl.textContent = String(text || '');
    liveProjectionStatusEl.setAttribute('data-state', String(stateName || ''));
  }

  function readOnlyPageHtml(pageHtml) {
    var doc = document.implementation.createHTMLDocument('');
    var holder = doc.createElement('div');
    holder.innerHTML = String(pageHtml || '');
    holder.querySelectorAll('script,iframe,object,embed').forEach(function (node) {
      node.remove();
    });
    holder.querySelectorAll('[contenteditable]').forEach(function (node) {
      node.setAttribute('contenteditable', 'false');
    });
    holder.querySelectorAll('input,textarea,select,button,a[href]').forEach(function (node) {
      node.setAttribute('tabindex', '-1');
      if ('disabled' in node) node.disabled = true;
    });
    holder.querySelectorAll('*').forEach(function (node) {
      Array.prototype.slice.call(node.attributes || []).forEach(function (attribute) {
        if (/^on/i.test(attribute.name)) node.removeAttribute(attribute.name);
      });
    });
    return holder.innerHTML;
  }

  function projectionPageGeometry(pageHtml) {
    var doc = document.implementation.createHTMLDocument('');
    var holder = doc.createElement('div');
    holder.innerHTML = String(pageHtml || '');
    var generated = holder.querySelector('.reader-generated-page');
    var width = generated && /^\d+(?:\.\d+)?px$/.test(generated.style.width)
      ? generated.style.width
      : '816px';
    var height = generated && /^\d+(?:\.\d+)?px$/.test(generated.style.height)
      ? generated.style.height
      : '1056px';
    return { width: width, height: height };
  }

  function projectionFrameDocument(pageHtml, bookStyleCss) {
    var safeCss = String(bookStyleCss || '').replace(/<\/style/gi, '<\\/style');
    var base = window.location.origin.replace(/"/g, '&quot;') + '/';
    return '<!doctype html><html><head><meta charset="utf-8">'
      + '<base href="' + base + '"><style>'
      + 'html,body{margin:0;padding:0;background:#fff;overflow:hidden;}'
      + safeCss
      + '</style></head><body>' + readOnlyPageHtml(pageHtml) + '</body></html>';
  }

  function projectionPieceBindings(pageHtml) {
    var doc = document.implementation.createHTMLDocument('');
    var holder = doc.createElement('div');
    holder.innerHTML = String(pageHtml || '');
    var bindings = {};
    holder.querySelectorAll('.reader-semantic-piece[data-source-fragment-id]').forEach(function (piece) {
      var fragmentId = piece.getAttribute('data-source-fragment-id') || '';
      if (!fragmentId || piece.getAttribute('data-presentation-copy') === '1') return;
      bindings[fragmentId] = {
        block_id: parseInt(piece.getAttribute('data-block-id') || '0', 10) || null,
        block_type: piece.getAttribute('data-block-type') || '',
        stable_anchor: piece.getAttribute('data-stable-anchor') || '',
        range_start: parseInt(piece.getAttribute('data-source-range-start') || '0', 10) || 0,
        range_end: parseInt(piece.getAttribute('data-source-range-end') || '0', 10) || 0,
      };
    });
    return bindings;
  }

  function activateProjectedParagraph(portal, sourceOffset) {
    if (!state.editable || !portal) return false;
    var location = {
      block_id: parseInt(portal.getAttribute('data-block-id') || '0', 10) || null,
      stable_anchor: portal.getAttribute('data-stable-anchor') || '',
    };
    var block = findSourceBlock(location);
    if (!block || (block.getAttribute('data-block-type') || '') !== 'paragraph') return false;
    var field = block.querySelector('.cpb-paragraph[contenteditable="true"]');
    if (!field) return false;
    var offset = Math.min(
      Math.max(0, parseInt(sourceOffset == null
        ? portal.getAttribute('data-source-range-start') || '0'
        : sourceOffset, 10) || 0),
      String(field.textContent || '').length
    );
    var sourceLocation = {
      version_id: state.versionId,
      section_id: state.sectionId,
      block_id: location.block_id,
      stable_anchor: location.stable_anchor,
      block_type: 'paragraph',
      field: sourceFieldIdentity(field, block),
      source_offset: offset,
    };
    block.scrollIntoView({ block: 'center', inline: 'nearest' });
    var restored = restoreSemanticSelectionBookmark({
      kind: 'contenteditable',
      anchor: sourceLocation,
      focus: Object.assign({}, sourceLocation),
      direction: 'forward',
      is_collapsed: true,
    });
    if (!restored) return false;
    if (liveProjectionPagesEl) {
      liveProjectionPagesEl.querySelectorAll('.cpb-live-projection__portal.is-active')
        .forEach(function (node) { node.classList.remove('is-active'); });
    }
    portal.classList.add('is-active');
    root.dispatchEvent(new CustomEvent('cpb:projection-source-activated', {
      detail: {
        section_id: state.sectionId,
        block_id: location.block_id,
        stable_anchor: location.stable_anchor,
        block_type: 'paragraph',
        source_offset: offset,
      },
    }));
    return true;
  }

  function appendParagraphProjectionPortals(frame, page) {
    var metadata = page && page.metadata && typeof page.metadata === 'object'
      ? page.metadata
      : {};
    var metrics = metadata.metrics && typeof metadata.metrics === 'object'
      ? metadata.metrics
      : {};
    var contentFrame = metrics.content_frame && typeof metrics.content_frame === 'object'
      ? metrics.content_frame
      : null;
    var measurements = Array.isArray(metrics.block_measurements)
      ? metrics.block_measurements
      : [];
    if (!contentFrame || !measurements.length) return;
    var bindings = projectionPieceBindings(page.page_html);
    var layer = document.createElement('div');
    layer.className = 'cpb-live-projection__portals';
    layer.setAttribute('aria-label', 'Paragraph source portals');
    measurements.forEach(function (measurement) {
      var fragmentId = String(measurement && measurement.source_fragment_id || '');
      var binding = bindings[fragmentId];
      var bounds = measurement && measurement.frame;
      if (
        !binding
        || binding.block_type !== 'paragraph'
        || String(measurement.semantic_type || '') !== 'paragraph'
        || !bounds
      ) return;
      var portal = document.createElement('div');
      portal.className = 'cpb-live-projection__portal';
      portal.setAttribute('role', 'presentation');
      portal.setAttribute('data-source-fragment-id', fragmentId);
      portal.setAttribute('data-block-id', String(binding.block_id || ''));
      portal.setAttribute('data-stable-anchor', binding.stable_anchor);
      portal.setAttribute('data-source-range-start', String(binding.range_start));
      portal.setAttribute('data-source-range-end', String(binding.range_end));
      portal.style.left = (Number(contentFrame.x || 0) + Number(bounds.x || 0)) + 'px';
      portal.style.top = (Number(contentFrame.y || 0) + Number(bounds.y || 0)) + 'px';
      portal.style.width = Math.max(0, Number(bounds.width || 0)) + 'px';
      portal.style.height = Math.max(0, Number(bounds.height || 0)) + 'px';
      portal.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        activateProjectedParagraph(portal, binding.range_start);
      });
      layer.appendChild(portal);
    });
    if (layer.childElementCount) frame.appendChild(layer);
  }

  function renderLiveProjection(result, requestSequence, sectionId) {
    if (
      !state.liveProjection.enabled
      || !liveProjectionPagesEl
      || requestSequence < state.liveProjection.acceptedSequence
      || sectionId !== state.sectionId
    ) return false;
    state.liveProjection.acceptedSequence = requestSequence;
    state.liveProjection.sectionId = sectionId;
    var pages = Array.isArray(result.pages) ? result.pages : [];
    var fragment = document.createDocumentFragment();
    pages.forEach(function (page) {
      var geometry = projectionPageGeometry(page.page_html);
      var frame = document.createElement('section');
      frame.className = 'cpb-live-projection__page';
      frame.setAttribute('data-page-number', String(page.page_number || 0));
      frame.setAttribute('data-section-id', String(page.section_id || 0));
      frame.style.width = geometry.width;
      frame.style.height = geometry.height;
      var label = document.createElement('span');
      label.className = 'cpb-live-projection__page-label';
      label.textContent = 'Page ' + String(page.page_number || '');
      var iframe = document.createElement('iframe');
      iframe.className = 'cpb-live-projection__frame';
      iframe.setAttribute('sandbox', '');
      iframe.setAttribute('aria-hidden', 'true');
      iframe.setAttribute('tabindex', '-1');
      iframe.title = 'Read-only authoritative page ' + String(page.page_number || '');
      iframe.style.width = geometry.width;
      iframe.style.height = geometry.height;
      iframe.srcdoc = projectionFrameDocument(page.page_html, result.book_style_css);
      frame.appendChild(label);
      frame.appendChild(iframe);
      appendParagraphProjectionPortals(frame, page);
      fragment.appendChild(frame);
    });
    liveProjectionPagesEl.replaceChildren(fragment);
    state.liveProjection.pageCount = pages.length;
    state.liveProjection.freshness = result.freshness && result.freshness.is_current
      ? 'current'
      : 'stale';
    state.liveProjection.error = '';
    if (!pages.length) {
      var empty = document.createElement('p');
      empty.className = 'cpb-live-projection__empty';
      empty.textContent = 'No authoritative pages are stored for this section yet.';
      liveProjectionPagesEl.appendChild(empty);
      setLiveProjectionStatus('Waiting for the first valid page map', 'empty');
    } else if (state.liveProjection.freshness === 'current') {
      setLiveProjectionStatus(
        pages.length + (pages.length === 1 ? ' page · Current' : ' pages · Current'),
        'current'
      );
    } else {
      setLiveProjectionStatus(
        pages.length + (pages.length === 1 ? ' page · Last valid map' : ' pages · Last valid map'),
        'stale'
      );
    }
    root.dispatchEvent(new CustomEvent('cpb:live-projection-rendered', {
      detail: {
        section_id: sectionId,
        page_count: pages.length,
        freshness: state.liveProjection.freshness,
        request_sequence: requestSequence,
      },
    }));
    return true;
  }

  function refreshLiveProjection() {
    if (!state.liveProjection.enabled || !liveProjectionPagesEl || !state.sectionId) {
      return Promise.resolve(false);
    }
    var requestSequence = ++state.liveProjection.requestSequence;
    var sectionId = state.sectionId;
    state.liveProjection.loading = true;
    setLiveProjectionStatus('Loading authoritative pages…', 'loading');
    var url = '/admin/api/controlled_book_page_map_api.php?action=stored_preview'
      + '&book_version_id=' + state.versionId
      + '&section_id=' + sectionId
      + '&include_style=1&check_freshness=1';
    return paginationRequest(url).then(function (payload) {
      return renderLiveProjection(payload.result || {}, requestSequence, sectionId);
    }).catch(function (error) {
      if (requestSequence >= state.liveProjection.acceptedSequence) {
        state.liveProjection.error = normalizeLivePaginationError(error);
        setLiveProjectionStatus('Projection unavailable · source editor unaffected', 'failed');
      }
      return false;
    }).finally(function () {
      if (requestSequence === state.liveProjection.requestSequence) {
        state.liveProjection.loading = false;
      }
    });
  }

  function observeLiveProjectionState(event) {
    if (!state.liveProjection.enabled) return;
    var detail = event && event.detail ? event.detail : {};
    var status = String(detail.status || '');
    if (status === 'current') {
      refreshLiveProjection();
      return;
    }
    if (status === 'pending' || status === 'generating' || status === 'stale') {
      setLiveProjectionStatus('Updating authoritative pages…', status);
      return;
    }
    if (status === 'failed') {
      setLiveProjectionStatus(
        state.liveProjection.pageCount
          ? 'Update failed · showing last valid map'
          : 'Update failed · no valid page map yet',
        'failed'
      );
    }
  }

  function updateViewModeControls() {
    var paginated = state.viewMode === 'paginated';
    root.classList.toggle('cpb-editor-paginated-mode', paginated);
    if (viewEditBtn) viewEditBtn.classList.toggle('is-active', !paginated);
    if (viewPaginatedBtn) viewPaginatedBtn.classList.toggle('is-active', paginated);
    if (paginationToolsEl) paginationToolsEl.hidden = !paginated;
    if (paginationStatusEl) paginationStatusEl.hidden = !paginated;
    if (toolbarMainEl) toolbarMainEl.hidden = paginated;
    if (addSubBtn) addSubBtn.style.display = paginated ? 'none' : addSubBtn.style.display;
  }

  function paginatedEditableFields(blockEl) {
    var type = blockEl.getAttribute('data-block-type') || '';
    if (type === 'heading') return blockEl.querySelectorAll('.cpb-heading');
    if (type === 'paragraph') return blockEl.querySelectorAll('.cpb-paragraph');
    if (type === 'callout') return blockEl.querySelectorAll('.cpb-callout-title,.cpb-callout-text');
    if (type === 'image') return blockEl.querySelectorAll('figcaption');
    return [];
  }

  function pieceIsWholeEditable(piece, blockEl) {
    if (!state.editable || !piece || !blockEl) return false;
    if (piece.getAttribute('data-presentation-copy') === '1') return false;
    var sourceLength = parseInt(piece.getAttribute('data-source-length') || '0', 10);
    var rangeStart = parseInt(piece.getAttribute('data-source-range-start') || '0', 10);
    var rangeEnd = parseInt(piece.getAttribute('data-source-range-end') || '0', 10);
    if (sourceLength <= 0 || rangeStart !== 0 || rangeEnd < sourceLength) return false;
    return ['heading', 'paragraph', 'callout', 'image'].indexOf(
      blockEl.getAttribute('data-block-type') || ''
    ) >= 0;
  }

  function pieceIsSplitParagraph(piece, blockEl) {
    if (!state.editable || !piece || !blockEl) return false;
    if ((blockEl.getAttribute('data-block-type') || '') !== 'paragraph') return false;
    var sourceLength = parseInt(piece.getAttribute('data-source-length') || '0', 10);
    var rangeStart = parseInt(piece.getAttribute('data-source-range-start') || '0', 10);
    var rangeEnd = parseInt(piece.getAttribute('data-source-range-end') || '0', 10);
    return sourceLength > 0 && (rangeStart > 0 || rangeEnd < sourceLength);
  }

  function textBoundary(rootEl, offset) {
    var walker = document.createTreeWalker(rootEl, NodeFilter.SHOW_TEXT);
    var remaining = Math.max(0, offset);
    var node = walker.nextNode();
    var last = rootEl;
    while (node) {
      var length = node.nodeValue.length;
      if (remaining <= length) return { node: node, offset: remaining };
      remaining -= length;
      last = node;
      node = walker.nextNode();
    }
    return {
      node: last,
      offset: last.nodeType === Node.TEXT_NODE ? last.nodeValue.length : last.childNodes.length,
    };
  }

  function sourceFieldIdentity(field, block) {
    if (!field || !block) return null;
    var editableFields = Array.prototype.slice.call(
      block.querySelectorAll('[contenteditable="true"],input,textarea,select')
    );
    var tableCell = field.matches && field.matches('.cpb-table th, .cpb-table td')
      ? field
      : null;
    var table = tableCell ? tableCell.closest('.cpb-table') : null;
    var tableRow = tableCell ? tableCell.closest('tr') : null;
    var tables = table ? Array.prototype.slice.call(block.querySelectorAll('.cpb-table')) : [];
    var tableRows = table ? Array.prototype.slice.call(table.querySelectorAll('tr')) : [];
    var rowCells = tableRow
      ? Array.prototype.slice.call(tableRow.querySelectorAll(':scope > th, :scope > td'))
      : [];
    var className = Array.prototype.slice.call(field.classList || []).find(function (name) {
      return /^cpb-(heading|paragraph|list|callout-title|callout-text|list-continuation)$/.test(name);
    }) || '';
    return {
      data_field: field.getAttribute('data-field') || '',
      data_part0_field: field.getAttribute('data-part0-field') || '',
      data_part0_col: field.getAttribute('data-part0-col') || '',
      data_lep_field: field.getAttribute('data-lep-field') || '',
      data_cover_field: field.getAttribute('data-cover-field') || '',
      class_name: className,
      field_index: Math.max(0, editableFields.indexOf(field)),
      tag_name: String(field.tagName || '').toLowerCase(),
      table_index: table ? tables.indexOf(table) : -1,
      table_row_index: tableRow ? tableRows.indexOf(tableRow) : -1,
      table_cell_index: tableCell ? rowCells.indexOf(tableCell) : -1,
    };
  }

  function sourceBlockForNode(node) {
    var element = node && node.nodeType === Node.ELEMENT_NODE ? node : node && node.parentElement;
    return element && element.closest ? element.closest('.cpb-block') : null;
  }

  function sourceFieldForNode(node) {
    var element = node && node.nodeType === Node.ELEMENT_NODE ? node : node && node.parentElement;
    return element && element.closest
      ? element.closest('[contenteditable="true"],input,textarea,select')
      : null;
  }

  function textOffsetWithin(field, node, offset) {
    if (!field || !node || !field.contains(node) && field !== node) return 0;
    var range = document.createRange();
    range.selectNodeContents(field);
    try {
      range.setEnd(node, offset);
    } catch (error) {
      return 0;
    }
    return range.toString().length;
  }

  function semanticSourceLocation(node, offset) {
    var block = sourceBlockForNode(node);
    var field = sourceFieldForNode(node);
    if (!block || !field || !canvasEl.contains(block)) return null;
    var element = node && node.nodeType === Node.ELEMENT_NODE ? node : node && node.parentElement;
    var piece = element && element.closest
      ? element.closest('[data-source-range-start][data-source-range-end]')
      : null;
    var location = {
      version_id: state.versionId,
      section_id: state.sectionId,
      block_id: parseInt(block.getAttribute('data-block-id') || '0', 10) || null,
      stable_anchor: block.getAttribute('data-stable-anchor') || '',
      block_type: block.getAttribute('data-block-type') || '',
      field: sourceFieldIdentity(field, block),
      source_offset: textOffsetWithin(field, node, offset),
    };
    if (piece) {
      location.source_range = {
        start: parseInt(piece.getAttribute('data-source-range-start') || '0', 10) || 0,
        end: parseInt(piece.getAttribute('data-source-range-end') || '0', 10) || 0,
      };
    }
    return location;
  }

  function findSourceBlock(location) {
    if (!location) return null;
    var block = null;
    if (location.stable_anchor) {
      block = canvasEl.querySelector(
        '.cpb-block[data-stable-anchor="' + CSS.escape(location.stable_anchor) + '"]'
      );
    }
    if (!block && location.block_id) {
      block = canvasEl.querySelector(
        '.cpb-block[data-block-id="' + String(location.block_id) + '"]'
      );
    }
    return block;
  }

  function findSourceField(block, identity) {
    if (!block || !identity) return null;
    var fields = Array.prototype.slice.call(
      block.querySelectorAll('[contenteditable="true"],input,textarea,select')
    );
    var indexedField = fields[identity.field_index] || null;
    if (
      parseInt(identity.table_index, 10) >= 0
      && parseInt(identity.table_row_index, 10) >= 0
      && parseInt(identity.table_cell_index, 10) >= 0
    ) {
      var tables = block.querySelectorAll('.cpb-table');
      var table = tables[parseInt(identity.table_index, 10)] || null;
      var rows = table ? table.querySelectorAll('tr') : [];
      var row = rows[parseInt(identity.table_row_index, 10)] || null;
      var cells = row ? row.querySelectorAll(':scope > th, :scope > td') : [];
      var cell = cells[parseInt(identity.table_cell_index, 10)] || null;
      if (cell && cell.matches('[contenteditable="true"],input,textarea,select')) {
        return cell;
      }
    }
    var selectors = [];
    if (identity.data_field) {
      selectors.push('[data-field="' + CSS.escape(identity.data_field) + '"]');
    }
    if (identity.data_part0_field) {
      selectors.push('[data-part0-field="' + CSS.escape(identity.data_part0_field) + '"]');
    }
    if (identity.data_part0_col) {
      selectors.push('[data-part0-col="' + CSS.escape(identity.data_part0_col) + '"]');
    }
    if (identity.data_lep_field) {
      selectors.push('[data-lep-field="' + CSS.escape(identity.data_lep_field) + '"]');
    }
    if (identity.data_cover_field) {
      selectors.push('[data-cover-field="' + CSS.escape(identity.data_cover_field) + '"]');
    }
    if (identity.class_name) selectors.push('.' + CSS.escape(identity.class_name));
    for (var index = 0; index < selectors.length; index++) {
      var matches = block.querySelectorAll(selectors[index]);
      if (indexedField && indexedField.matches(selectors[index])) return indexedField;
      if (matches.length === 1) return matches[0];
    }
    return indexedField;
  }

  function resolveSourceBoundary(location) {
    var block = findSourceBlock(location);
    var field = findSourceField(block, location && location.field);
    if (!field) return null;
    if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
      return {
        field: field,
        inputOffset: Math.min(
          Math.max(0, parseInt(location.source_offset || '0', 10)),
          String(field.value || '').length
        ),
      };
    }
    var boundary = textBoundary(field, parseInt(location.source_offset || '0', 10));
    return { field: field, node: boundary.node, offset: boundary.offset };
  }

  function captureSemanticSelectionBookmark() {
    var active = document.activeElement;
    if (
      active
      && canvasEl.contains(active)
      && (active instanceof HTMLInputElement || active instanceof HTMLTextAreaElement)
    ) {
      var block = active.closest('.cpb-block');
      if (!block) return null;
      var base = semanticSourceLocation(active, 0);
      if (!base) return null;
      var start = active.selectionStart == null ? 0 : active.selectionStart;
      var end = active.selectionEnd == null ? start : active.selectionEnd;
      var direction = active.selectionDirection || 'none';
      return {
        kind: 'input',
        active: base,
        anchor: Object.assign({}, base, {
          source_offset: direction === 'backward' ? end : start,
        }),
        focus: Object.assign({}, base, {
          source_offset: direction === 'backward' ? start : end,
        }),
        start_offset: start,
        end_offset: end,
        direction: direction === 'backward' ? 'backward' : 'forward',
        is_collapsed: start === end,
      };
    }
    var selection = window.getSelection();
    if (!selection || selection.rangeCount < 1 || !selectionInCanvas()) return null;
    var anchor = semanticSourceLocation(selection.anchorNode, selection.anchorOffset);
    var focus = semanticSourceLocation(selection.focusNode, selection.focusOffset);
    if (!anchor || !focus) return null;
    var range = selection.getRangeAt(0);
    var start = semanticSourceLocation(range.startContainer, range.startOffset);
    var end = semanticSourceLocation(range.endContainer, range.endOffset);
    var direction = (
      anchor.block_id === start.block_id
      && anchor.stable_anchor === start.stable_anchor
      && anchor.source_offset === start.source_offset
    ) ? 'forward' : 'backward';
    return {
      kind: 'contenteditable',
      active: semanticSourceLocation(
        document.activeElement,
        0
      ) || anchor,
      anchor: anchor,
      focus: focus,
      start: start,
      end: end,
      start_offset: start ? start.source_offset : 0,
      end_offset: end ? end.source_offset : 0,
      direction: direction,
      is_collapsed: selection.isCollapsed,
    };
  }

  function restoreSemanticSelectionBookmark(bookmark) {
    if (!bookmark || !bookmark.anchor || !bookmark.focus) return false;
    var anchor = resolveSourceBoundary(bookmark.anchor);
    var focus = resolveSourceBoundary(bookmark.focus);
    if (!anchor || !focus) return false;
    if (bookmark.kind === 'input' && anchor.field === focus.field) {
      anchor.field.focus();
      var start = Math.min(anchor.inputOffset, focus.inputOffset);
      var end = Math.max(anchor.inputOffset, focus.inputOffset);
      anchor.field.setSelectionRange(start, end, bookmark.direction || 'none');
      return true;
    }
    if (!anchor.node || !focus.node) return false;
    anchor.field.focus();
    var selection = window.getSelection();
    selection.removeAllRanges();
    if (typeof selection.setBaseAndExtent === 'function') {
      selection.setBaseAndExtent(anchor.node, anchor.offset, focus.node, focus.offset);
    } else {
      var range = document.createRange();
      range.setStart(anchor.node, anchor.offset);
      range.setEnd(focus.node, focus.offset);
      selection.addRange(range);
      if (bookmark.direction === 'backward' && selection.extend) {
        selection.collapse(focus.node, focus.offset);
        selection.extend(anchor.node, anchor.offset);
      }
    }
    return true;
  }

  function captureSemanticScrollBookmark() {
    var canvasRect = canvasEl.getBoundingClientRect();
    var blocks = Array.prototype.slice.call(
      canvasEl.querySelectorAll('.cpb-block[data-stable-anchor]')
    );
    var anchor = blocks.find(function (block) {
      return block.getBoundingClientRect().bottom >= canvasRect.top;
    }) || null;
    return {
      section_id: state.sectionId,
      scroll_top: canvasEl.scrollTop,
      stable_anchor: anchor ? (anchor.getAttribute('data-stable-anchor') || '') : '',
      anchor_offset_px: anchor ? anchor.getBoundingClientRect().top - canvasRect.top : 0,
    };
  }

  function restoreSemanticScrollBookmark(bookmark) {
    if (!bookmark) return false;
    if (bookmark.stable_anchor) {
      var anchor = canvasEl.querySelector(
        '.cpb-block[data-stable-anchor="' + CSS.escape(bookmark.stable_anchor) + '"]'
      );
      if (anchor) {
        var before = anchor.getBoundingClientRect().top - canvasEl.getBoundingClientRect().top;
        canvasEl.scrollTop += before - Number(bookmark.anchor_offset_px || 0);
        return true;
      }
    }
    canvasEl.scrollTop = Number(bookmark.scroll_top || 0);
    return true;
  }

  function replaceTextRangeHtml(rootEl, start, end, replacementHtml) {
    var startBoundary = textBoundary(rootEl, start);
    var endBoundary = textBoundary(rootEl, end);
    var range = document.createRange();
    range.setStart(startBoundary.node, startBoundary.offset);
    range.setEnd(endBoundary.node, endBoundary.offset);
    range.deleteContents();
    var template = document.createElement('template');
    template.innerHTML = replacementHtml;
    range.insertNode(template.content);
  }

  function flushPaginatedParagraphFragment(blockEl, piece, field) {
    if (field.getAttribute('data-fragment-dirty') !== '1') return Promise.resolve();
    field.removeAttribute('data-fragment-dirty');
    var blockId = parseInt(blockEl.getAttribute('data-block-id') || '0', 10);
    var page = piece.closest('.cpb-paginated-page');
    var sectionId = parseInt(page ? (page.getAttribute('data-section-id') || '0') : '0', 10);
    var rangeStart = parseInt(piece.getAttribute('data-source-range-start') || '0', 10);
    var rangeEnd = parseInt(piece.getAttribute('data-source-range-end') || '0', 10);
    if (!blockId || !sectionId || rangeEnd <= rangeStart) {
      return Promise.reject(new Error('The paragraph fragment cannot be mapped to its source block.'));
    }
    setStatus('Saving paragraph…', 'saving');
    return apiGet(
      apiBase + '?action=load&version_id=' + state.versionId + '&section_id=' + sectionId
    ).then(function (response) {
      if (!response.ok) throw new Error(response.error || 'Source paragraph load failed');
      var holder = document.createElement('div');
      holder.innerHTML = response.page_html || '';
      var sourceBlock = holder.querySelector('[data-block-id="' + blockId + '"]');
      var sourceField = sourceBlock ? sourceBlock.querySelector('.cpb-paragraph') : null;
      if (!sourceBlock || !sourceField) throw new Error('The complete source paragraph was not found.');
      replaceTextRangeHtml(sourceField, rangeStart, rangeEnd, field.innerHTML);
      return apiPost('update_block', {
        version_id: state.versionId,
        block_id: blockId,
        payload: extractPayload(sourceBlock, 'paragraph'),
      });
    }).then(function (response) {
      if (!response.ok) throw new Error(response.error || 'Paragraph save failed');
      state.pendingPaginatedAnchor = blockEl.getAttribute('data-stable-anchor') || '';
      setStatus('Saved', 'saved');
      markPaginationChanged();
    }).catch(function (error) {
      field.setAttribute('data-fragment-dirty', '1');
      showError(error);
    });
  }

  function markPaginationChanged() {
    state.paginationStale = true;
    state.authoritativeEditorPageStarts = [];
    state.authoritativeEditorPageStartsVersionId = 0;
    state.authoritativeEditorPageStartsSectionId = 0;
    scheduleUnifiedPrintLayout(450);
    clearTimeout(state.paginationRegenerateTimer);
    state.paginationRegenerateTimer = null;
  }

  function wirePaginatedFields() {
    canvasEl.querySelectorAll('.reader-semantic-piece').forEach(function (piece) {
      var blockEl = piece.matches('.cpb-block') ? piece : piece.querySelector('.cpb-block');
      if (!blockEl || piece.getAttribute('data-presentation-copy') === '1') return;
      if (blockEl.getAttribute('data-system-managed') === '1'
        || (blockEl.getAttribute('data-block-type') || '') === 'toc') {
        piece.classList.add('cpb-paginated-piece--automatic');
        return;
      }
      var splitParagraph = pieceIsSplitParagraph(piece, blockEl);
      var editable = pieceIsWholeEditable(piece, blockEl) || splitParagraph;
      piece.classList.toggle('cpb-paginated-piece--editable', editable);
      piece.classList.toggle('cpb-paginated-piece--source-editor', !editable);
      if (editable) {
        Array.prototype.slice.call(paginatedEditableFields(blockEl)).forEach(function (field) {
          field.setAttribute('contenteditable', 'true');
          if (field.getAttribute('data-paginated-input-wired') === '1') return;
          field.setAttribute('data-paginated-input-wired', '1');
          field.addEventListener('input', function () {
            state.pendingPaginatedAnchor = blockEl.getAttribute('data-stable-anchor') || '';
            if (splitParagraph) {
              field.setAttribute('data-fragment-dirty', '1');
              setStatus('Editing…', 'saving');
            } else {
              scheduleSave(blockEl);
            }
          });
          field.addEventListener('blur', function (event) {
            if (isDeferredSaveControl(event.relatedTarget)) {
              pausePendingSaveTimer();
              return;
            }
            if (splitParagraph) {
              flushPaginatedParagraphFragment(blockEl, piece, field);
            } else {
              flushPendingSave(blockEl);
            }
          });
        });
      } else if (state.editable && blockEl.getAttribute('data-block-id')) {
        var editButton = document.createElement('button');
        editButton.type = 'button';
        editButton.className = 'cpb-paginated-edit-block';
        editButton.textContent = 'Edit block';
        editButton.addEventListener('click', function (event) {
          event.preventDefault();
          event.stopPropagation();
          openPaginatedBlockEditor(blockEl, piece);
        });
        piece.appendChild(editButton);
      }
    });
  }

  function closePaginatedBlockEditor() {
    var overlay = document.getElementById('cpbPaginatedBlockEditor');
    if (overlay) overlay.remove();
  }

  function openPaginatedBlockEditor(blockEl, piece) {
    var blockId = parseInt(blockEl.getAttribute('data-block-id') || '0', 10);
    var page = piece.closest('.cpb-paginated-page');
    var sectionId = parseInt(page ? (page.getAttribute('data-section-id') || '0') : '0', 10);
    if (!blockId || !sectionId) {
      setPaginationStatus('This generated element is read-only.', true);
      return;
    }
    setPaginationStatus('Loading complete source block…', false);
    apiGet(
      apiBase + '?action=load&version_id=' + state.versionId + '&section_id=' + sectionId
    ).then(function (response) {
      if (!response.ok) throw new Error(response.error || 'Block load failed');
      var holder = document.createElement('div');
      holder.innerHTML = response.page_html || '';
      var sourceBlock = holder.querySelector('[data-block-id="' + blockId + '"]');
      if (!sourceBlock) throw new Error('The complete source block could not be found.');
      closePaginatedBlockEditor();
      var overlay = document.createElement('div');
      overlay.id = 'cpbPaginatedBlockEditor';
      overlay.className = 'cpb-paginated-block-overlay';
      var dialog = document.createElement('div');
      dialog.className = 'cpb-paginated-block-dialog';
      var heading = document.createElement('div');
      heading.className = 'cpb-paginated-block-dialog__head';
      heading.innerHTML = '<strong>Edit complete source block</strong>';
      var close = document.createElement('button');
      close.type = 'button';
      close.textContent = 'Close';
      close.addEventListener('click', closePaginatedBlockEditor);
      heading.appendChild(close);
      var content = document.createElement('div');
      content.className = 'cpb-paginated-block-dialog__content';
      content.appendChild(sourceBlock);
      var actions = document.createElement('div');
      actions.className = 'cpb-paginated-block-dialog__actions';
      var save = document.createElement('button');
      save.type = 'button';
      save.textContent = 'Save block';
      save.addEventListener('click', function () {
        Promise.resolve(flushSave(sourceBlock)).then(function () {
          markPaginationChanged();
          closePaginatedBlockEditor();
        });
      });
      actions.appendChild(save);
      dialog.appendChild(heading);
      dialog.appendChild(content);
      dialog.appendChild(actions);
      overlay.appendChild(dialog);
      canvasEl.appendChild(overlay);
      wireCanvas();
    }).catch(showError);
  }

  function markManualBreaksInPages() {
    canvasEl.querySelectorAll('.cpb-manual-break-before').forEach(function (node) {
      node.classList.remove('cpb-manual-break-before');
    });
    state.manualBreaks.forEach(function (row) {
      var anchor = String(row.before_block_anchor || '');
      if (!anchor) return;
      canvasEl.querySelectorAll('.cpb-block[data-stable-anchor]').forEach(function (block) {
        if (block.getAttribute('data-stable-anchor') === anchor) {
          block.classList.add('cpb-manual-break-before');
        }
      });
    });
  }

  function paginationBreakControl() {
    var panel = document.createElement('div');
    panel.className = 'cpb-pagination-panel';
    var title = document.createElement('strong');
    title.textContent = selectedSectionUsesAutomaticPages()
      ? 'Automatic generated pages'
      : 'Manual page breaks';
    panel.appendChild(title);
    if (selectedSectionUsesAutomaticPages()) {
      panel.appendChild(document.createTextNode(
        ' · page flow is generated from the controlled outline and content length'
      ));
      return panel;
    }
    if (!state.editable) {
      panel.appendChild(document.createTextNode(' · released revision'));
      return panel;
    }
    var existing = {};
    state.manualBreaks.forEach(function (row) {
      existing[row.before_block_anchor] = true;
    });
    var select = document.createElement('select');
    select.className = 'cpb-pagination-break-select';
    state.paginationCandidates.forEach(function (row) {
      if (parseInt(row.section_id || '0', 10) !== state.sectionId) return;
      if (existing[row.stable_anchor]) return;
      var option = document.createElement('option');
      option.value = row.stable_anchor;
      option.textContent = row.section_title + ' · ' + row.block_type;
      select.appendChild(option);
    });
    var insert = document.createElement('button');
    insert.type = 'button';
    insert.textContent = 'Insert break';
    insert.disabled = !select.options.length;
    insert.addEventListener('click', function () {
      mutateManualBreak({
        action: 'insert',
        book_version_id: state.versionId,
        before_block_anchor: select.value,
      });
    });
    panel.appendChild(select);
    panel.appendChild(insert);
    state.manualBreaks.forEach(function (row) {
      if (parseInt(row.section_id || '0', 10) !== state.sectionId) return;
      var item = document.createElement('span');
      item.className = 'cpb-pagination-break-item';
      item.textContent = 'Before ' + (row.section_title || row.before_block_anchor);
      var moveSelect = document.createElement('select');
      moveSelect.setAttribute('aria-label', 'Move page break');
      state.paginationCandidates.forEach(function (candidate) {
        if (parseInt(candidate.section_id || '0', 10) !== state.sectionId) return;
        if (existing[candidate.stable_anchor] && candidate.stable_anchor !== row.before_block_anchor) {
          return;
        }
        var moveOption = document.createElement('option');
        moveOption.value = candidate.stable_anchor;
        moveOption.textContent = candidate.section_title + ' · ' + candidate.block_type;
        moveOption.selected = candidate.stable_anchor === row.before_block_anchor;
        moveSelect.appendChild(moveOption);
      });
      var move = document.createElement('button');
      move.type = 'button';
      move.textContent = 'Move';
      move.addEventListener('click', function () {
        mutateManualBreak({
          action: 'move',
          book_version_id: state.versionId,
          break_id: row.id,
          before_block_anchor: moveSelect.value,
        });
      });
      var remove = document.createElement('button');
      remove.type = 'button';
      remove.textContent = 'Remove';
      remove.addEventListener('click', function () {
        mutateManualBreak({
          action: 'remove',
          book_version_id: state.versionId,
          break_id: row.id,
        });
      });
      item.appendChild(moveSelect);
      item.appendChild(move);
      item.appendChild(remove);
      panel.appendChild(item);
    });
    return panel;
  }

  function selectedSectionUsesAutomaticPages() {
    var selected = null;
    function visit(nodes) {
      (nodes || []).some(function (node) {
        if (parseInt(node.id || '0', 10) === state.sectionId) {
          selected = node;
          return true;
        }
        return visit(node.children || []);
      });
      return !!selected;
    }
    visit(state.sectionsTree);
    if (!selected) return false;
    var key = String(selected.section_key || selected.key || '').toLowerCase();
    return !!selected.is_generated
      || ['cover', 'toc', 'lep', 'revision_system', 'amendment_list',
        'distribution_list', 'abbreviations', 'definitions'].indexOf(key) >= 0;
  }

  function paginationPageNavigation(sectionPages, allPageCount) {
    var navigation = document.createElement('div');
    navigation.className = 'cpb-pagination-page-navigation';
    var previous = document.createElement('button');
    previous.type = 'button';
    previous.textContent = 'Previous page';
    previous.disabled = state.sectionPageIndex <= 0;
    previous.addEventListener('click', function () {
      state.sectionPageIndex = Math.max(0, state.sectionPageIndex - 1);
      renderPaginatedView(state.paginatedResult);
      canvasEl.scrollTop = 0;
    });
    var current = document.createElement('strong');
    var page = sectionPages[state.sectionPageIndex] || null;
    current.textContent = page
      ? 'Page ' + page.page_number + ' · ' + (state.sectionPageIndex + 1)
        + ' of ' + sectionPages.length + ' in this section · ' + allPageCount + ' total'
      : 'No page in this section';
    var next = document.createElement('button');
    next.type = 'button';
    next.textContent = 'Next page';
    next.disabled = state.sectionPageIndex >= sectionPages.length - 1;
    next.addEventListener('click', function () {
      state.sectionPageIndex = Math.min(sectionPages.length - 1, state.sectionPageIndex + 1);
      renderPaginatedView(state.paginatedResult);
      canvasEl.scrollTop = 0;
    });
    navigation.appendChild(previous);
    navigation.appendChild(current);
    navigation.appendChild(next);
    return navigation;
  }

  function storedPageContainsSection(page, sectionId) {
    if (parseInt(page.section_id || '0', 10) === sectionId) return true;
    var metadata = page.metadata && typeof page.metadata === 'object' ? page.metadata : {};
    var coverage = Array.isArray(metadata.coverage) ? metadata.coverage : [];
    return coverage.some(function (entry) {
      return !entry.presentation_copy
        && parseInt(entry.section_id || '0', 10) === sectionId;
    });
  }

  function renderPaginatedView(result) {
    state.paginatedResult = result;
    state.paginationStale = !(result.freshness && result.freshness.is_current);
    if (publicationCssEl) publicationCssEl.textContent = result.book_style_css || '';
    if (pageBreakBtn) {
      pageBreakBtn.disabled = !state.editable || selectedSectionUsesAutomaticPages();
      pageBreakBtn.title = selectedSectionUsesAutomaticPages()
        ? 'This generated section uses automatic page breaks'
        : 'Insert a hard page break at the cursor';
    }
    var allPages = Array.isArray(result.pages) ? result.pages : [];
    var sectionPages = allPages.filter(function (page) {
      return storedPageContainsSection(page, state.sectionId);
    });
    if (state.pendingPaginatedAnchor) {
      var anchorNeedle = 'data-stable-anchor="' + state.pendingPaginatedAnchor + '"';
      var retainedPageIndex = sectionPages.findIndex(function (page) {
        return String(page.page_html || '').indexOf(anchorNeedle) >= 0;
      });
      if (retainedPageIndex >= 0) state.sectionPageIndex = retainedPageIndex;
    }
    state.sectionPageIndex = Math.max(
      0,
      Math.min(state.sectionPageIndex, Math.max(0, sectionPages.length - 1))
    );
    var pages = sectionPages.length ? [sectionPages[state.sectionPageIndex]] : [];
    canvasEl.innerHTML = '';
    canvasEl.appendChild(paginationBreakControl());
    canvasEl.appendChild(paginationPageNavigation(sectionPages, allPages.length));
    if (!pages.length) {
      var empty = document.createElement('div');
      empty.className = 'cpb-pagination-empty';
      empty.textContent = allPages.length
        ? 'This section has no generated pages yet. Click Regenerate.'
        : 'No authoritative pages have been generated. Click Regenerate.';
      canvasEl.appendChild(empty);
    } else {
      var stack = document.createElement('div');
      stack.className = 'cpb-pages-stack';
      pages.forEach(function (page) {
        var frame = document.createElement('section');
        frame.className = 'cpb-paginated-page';
        frame.setAttribute('data-page-number', String(page.page_number || 0));
        frame.setAttribute('data-section-id', String(page.section_id || 0));
        var label = document.createElement('span');
        label.className = 'cpb-paginated-page-label';
        label.textContent = 'Page ' + page.page_number;
        frame.appendChild(label);
        var content = document.createElement('div');
        content.innerHTML = page.page_html || '';
        while (content.firstChild) frame.appendChild(content.firstChild);
        var generatedPage = frame.querySelector('.reader-generated-page');
        if (generatedPage) {
          var generatedWidth = generatedPage.style.width || '';
          var generatedHeight = generatedPage.style.height || '';
          if (generatedWidth) {
            frame.style.width = generatedWidth;
            frame.style.minWidth = generatedWidth;
          }
          if (generatedHeight) {
            frame.style.height = generatedHeight;
            frame.style.minHeight = generatedHeight;
          }
        }
        stack.appendChild(frame);
      });
      canvasEl.appendChild(stack);
    }
    markManualBreaksInPages();
    wirePaginatedFields();
    applyCanvasZoom(state.canvasZoom, false);
    if (state.pendingPaginatedAnchor) {
      var retained = canvasEl.querySelector(
        '.cpb-block[data-stable-anchor="' + state.pendingPaginatedAnchor + '"]'
      );
      if (retained) retained.scrollIntoView({ behavior: 'smooth', block: 'center' });
      state.pendingPaginatedAnchor = '';
    }
    var status = result.pagination && result.pagination.status
      ? result.pagination.status
      : 'not generated';
    setPaginationStatus(
      sectionPages.length + ' section pages of ' + allPages.length + ' total · '
        + state.manualBreaks.filter(function (row) {
          return parseInt(row.section_id || '0', 10) === state.sectionId;
        }).length + ' manual breaks · '
        + (state.paginationStale ? 'Needs regeneration' : 'Current') + ' · ' + status,
      state.paginationStale
    );
  }

  function loadPaginatedView() {
    setStatus('Loading pages…', 'saving');
    var pageURL = '/admin/api/controlled_book_page_map_api.php?action=stored_preview&book_version_id='
      + state.versionId;
    var breakURL = '/admin/api/controlled_book_page_break_api.php?action=list&book_version_id='
      + state.versionId;
    return Promise.all([
      paginationRequest(pageURL),
      paginationRequest(breakURL),
    ]).then(function (responses) {
      state.manualBreaks = responses[1].breaks || [];
      state.paginationCandidates = responses[1].candidates || [];
      renderPaginatedView(responses[0].result || {});
      setStatus(state.editable ? 'Ready' : 'Read-only (released)', state.editable ? 'saved' : '');
    }).catch(showError);
  }

  function setViewMode(mode) {
    mode = mode === 'paginated' ? 'paginated' : 'edit';
    if (mode === state.viewMode) return Promise.resolve();
    return Promise.resolve(flushAllPendingSaves()).then(function () {
      closePaginatedBlockEditor();
      state.viewMode = mode;
      updateViewModeControls();
      if (mode === 'paginated') return loadPaginatedView();
      if (publicationCssEl) publicationCssEl.textContent = '';
      return loadSection(state.sectionId || initialSectionId || 0);
    });
  }

  function regeneratePagination() {
    clearTimeout(state.paginationRegenerateTimer);
    state.paginationRegenerateTimer = null;
    setPaginationStatus('Generating and validating authoritative pages…', false);
    return paginationRequest('/admin/api/controlled_book_page_map_api.php', {
      action: 'generate',
      book_version_id: state.versionId,
      book_key: (state.versionInfo.book_key || ''),
    }).then(loadPaginatedView).catch(showError);
  }

  function approvePagination() {
    setPaginationStatus('Approving pagination…', false);
    return paginationRequest('/admin/api/controlled_book_page_map_api.php', {
      action: 'approve',
      book_version_id: state.versionId,
    }).then(loadPaginatedView).catch(showError);
  }

  function mutateManualBreak(payload) {
    return paginationRequest('/admin/api/controlled_book_page_break_api.php', payload)
      .then(function () {
        markPaginationChanged();
        return loadUnifiedManualBreaks();
      })
      .catch(showError);
  }

  function capturePaginatedSelection() {
    var selection = window.getSelection();
    if (!selection || selection.rangeCount < 1) return state.lastPaginatedRange;
    var range = selection.getRangeAt(0);
    var container = range.commonAncestorContainer.nodeType === Node.ELEMENT_NODE
      ? range.commonAncestorContainer
      : range.commonAncestorContainer.parentElement;
    if (!container || !canvasEl.contains(container)) return state.lastPaginatedRange;
    state.lastPaginatedRange = range.cloneRange();
    return state.lastPaginatedRange;
  }

  function fragmentHtml(range) {
    var holder = document.createElement('div');
    holder.appendChild(range.cloneContents());
    return holder.innerHTML;
  }

  function candidateAfterAnchor(anchor) {
    var candidates = state.paginationCandidates.filter(function (row) {
      return parseInt(row.section_id || '0', 10) === state.sectionId;
    });
    var index = candidates.findIndex(function (row) {
      return row.stable_anchor === anchor;
    });
    return index >= 0 && index + 1 < candidates.length ? candidates[index + 1] : null;
  }

  function insertManualBreakBefore(anchor) {
    if (!anchor) throw new Error('Select content where the new page should begin.');
    return mutateManualBreak({
      action: 'insert',
      book_version_id: state.versionId,
      before_block_anchor: anchor,
    });
  }

  function submitParagraphPageBreak(block, leftPayload, rightPayload) {
    setStatus('Inserting page break…', 'saving');
    return apiPost('split_block_page_break', {
      version_id: state.versionId,
      block_id: parseInt(block.getAttribute('data-block-id') || '0', 10),
      left_payload: leftPayload,
      right_payload: rightPayload,
    }).then(function (response) {
      if (!response.ok) throw new Error(response.error || 'Page break insertion failed');
      state.pendingPaginatedAnchor = response.new_block
        ? (response.new_block.stable_anchor || '')
        : '';
      markPaginationChanged();
      return loadSection(state.sectionId);
    });
  }

  function splitSourceParagraphAtOffset(block, piece, textOffset) {
    var page = piece.closest('.cpb-paginated-page');
    var sectionId = parseInt(page ? (page.getAttribute('data-section-id') || '0') : '0', 10);
    var blockId = parseInt(block.getAttribute('data-block-id') || '0', 10);
    return apiGet(
      apiBase + '?action=load&version_id=' + state.versionId + '&section_id=' + sectionId
    ).then(function (response) {
      if (!response.ok) throw new Error(response.error || 'Source paragraph load failed');
      var holder = document.createElement('div');
      holder.innerHTML = response.page_html || '';
      var sourceBlock = holder.querySelector('[data-block-id="' + blockId + '"]');
      var sourceField = sourceBlock ? sourceBlock.querySelector('.cpb-paragraph') : null;
      if (!sourceBlock || !sourceField) throw new Error('The complete source paragraph was not found.');
      var boundary = textBoundary(sourceField, textOffset);
      var before = document.createRange();
      before.selectNodeContents(sourceField);
      before.setEnd(boundary.node, boundary.offset);
      var after = document.createRange();
      after.selectNodeContents(sourceField);
      after.setStart(boundary.node, boundary.offset);
      var leftHtml = fragmentHtml(before);
      var rightHtml = fragmentHtml(after);
      if (!before.cloneContents().textContent.trim() || !after.cloneContents().textContent.trim()) {
        throw new Error('Place the cursor between text to split this paragraph.');
      }
      var payload = extractPayload(sourceBlock, 'paragraph');
      return submitParagraphPageBreak(
        block,
        Object.assign({}, payload, { html: leftHtml }),
        Object.assign({}, payload, { html: rightHtml })
      );
    });
  }

  function insertPageBreakAtCursor() {
    if (!state.editable) return;
    var range = capturePaginatedSelection();
    var node = range
      ? (range.startContainer.nodeType === Node.ELEMENT_NODE
        ? range.startContainer
        : range.startContainer.parentElement)
      : null;
    var block = node && node.closest ? node.closest('.cpb-block') : null;
    if (!block && state.lastStyleTarget && state.lastStyleTarget.block) {
      block = state.lastStyleTarget.block;
    }
    if (!block) {
      showError(new Error('Place the cursor in the section where the page break should be inserted.'));
      return;
    }
    var stableAnchor = block.getAttribute('data-stable-anchor') || '';
    var paragraph = block.querySelector('.cpb-paragraph[contenteditable="true"]');
    if (!range || !paragraph || !paragraph.contains(range.startContainer)) {
      insertManualBreakBefore(stableAnchor);
      return;
    }

    var piece = block.closest('.reader-semantic-piece');
    if (pieceIsSplitParagraph(piece, block)) {
      var localPrefix = document.createRange();
      localPrefix.selectNodeContents(paragraph);
      localPrefix.setEnd(range.startContainer, range.startOffset);
      var globalOffset = parseInt(piece.getAttribute('data-source-range-start') || '0', 10)
        + localPrefix.toString().length;
      splitSourceParagraphAtOffset(block, piece, globalOffset).catch(showError);
      return;
    }

    var before = document.createRange();
    before.selectNodeContents(paragraph);
    before.setEnd(range.startContainer, range.startOffset);
    var after = document.createRange();
    after.selectNodeContents(paragraph);
    after.setStart(range.startContainer, range.startOffset);
    var leftHtml = fragmentHtml(before);
    var rightHtml = fragmentHtml(after);
    var leftText = before.cloneContents().textContent.trim();
    var rightText = after.cloneContents().textContent.trim();

    if (!leftText) {
      insertManualBreakBefore(stableAnchor);
      return;
    }
    if (!rightText) {
      var next = candidateAfterAnchor(stableAnchor);
      if (!next) {
        showError(new Error('There is no following block in this section to start on a new page.'));
        return;
      }
      insertManualBreakBefore(next.stable_anchor);
      return;
    }

    var payload = extractPayload(block, 'paragraph');
    submitParagraphPageBreak(
      block,
      Object.assign({}, payload, { html: leftHtml }),
      Object.assign({}, payload, { html: rightHtml })
    ).catch(showError);
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

  function reviewThreadTextRange(target, thread) {
    if (!target || typeof document.createTreeWalker !== 'function') return null;
    var nodes = [];
    var text = '';
    var walker = document.createTreeWalker(target, NodeFilter.SHOW_TEXT, {
      acceptNode: function (node) {
        var parent = node.parentElement;
        if (!parent || parent.closest(
          '.cpb-block-controls,.cpb-review-thread-pin,button,input,select,textarea'
        )) {
          return NodeFilter.FILTER_REJECT;
        }
        if (!node.nodeValue) return NodeFilter.FILTER_REJECT;
        return NodeFilter.FILTER_ACCEPT;
      },
    });
    var node;
    while ((node = walker.nextNode())) {
      nodes.push({ node: node, start: text.length, end: text.length + node.nodeValue.length });
      text += node.nodeValue;
    }
    if (!nodes.length || !text) return null;

    var selected = String(thread.selected_text || '').trim();
    if (!selected) return null;
    var start = Number(thread.start_offset);
    var end = Number(thread.end_offset);
    if (!Number.isFinite(start) || !Number.isFinite(end) || start < 0 || end <= start
      || end > text.length
      || text.slice(start, end).replace(/\s+/g, ' ').trim()
        !== selected.replace(/\s+/g, ' ').trim()) {
      start = text.indexOf(selected);
      end = start >= 0 ? start + selected.length : -1;
    }
    if (start < 0 || end <= start) {
      var normalized = '';
      var rawOffsets = [];
      var previousWasSpace = false;
      Array.from(text).forEach(function (char, rawOffset) {
        var isSpace = /\s/.test(char);
        if (isSpace && previousWasSpace) return;
        normalized += isSpace ? ' ' : char;
        rawOffsets.push(rawOffset);
        previousWasSpace = isSpace;
      });
      var normalizedSelected = selected.replace(/\s+/g, ' ').trim();
      var normalizedStart = normalized.indexOf(normalizedSelected);
      if (normalizedStart < 0) return null;
      start = rawOffsets[normalizedStart];
      var normalizedEnd = normalizedStart + normalizedSelected.length - 1;
      end = (rawOffsets[normalizedEnd] ?? start) + 1;
    }

    function pointForOffset(offset, preferEnd) {
      for (var i = 0; i < nodes.length; i += 1) {
        var item = nodes[i];
        if (offset < item.end || (preferEnd && offset === item.end)) {
          return {
            node: item.node,
            offset: Math.max(0, Math.min(item.node.nodeValue.length, offset - item.start)),
          };
        }
      }
      var last = nodes[nodes.length - 1];
      return { node: last.node, offset: last.node.nodeValue.length };
    }

    var startPoint = pointForOffset(start, false);
    var endPoint = pointForOffset(end, true);
    var range = document.createRange();
    range.setStart(startPoint.node, startPoint.offset);
    range.setEnd(endPoint.node, endPoint.offset);
    return range.collapsed ? null : range;
  }

  function reviewUUID() {
    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (char) {
      var value = Math.random() * 16 | 0;
      return (char === 'x' ? value : (value & 3 | 8)).toString(16);
    });
  }

  function reviewSelectionAnchor(range) {
    if (!range || range.collapsed) return null;
    var startElement = range.startContainer.nodeType === Node.ELEMENT_NODE
      ? range.startContainer
      : range.startContainer.parentElement;
    var endElement = range.endContainer.nodeType === Node.ELEMENT_NODE
      ? range.endContainer
      : range.endContainer.parentElement;
    var block = startElement && startElement.closest
      ? startElement.closest('.cpb-block')
      : null;
    if (!block || !endElement || block !== endElement.closest('.cpb-block')) return null;
    var selectedText = range.toString().trim();
    if (!selectedText) return null;

    var nodes = [];
    var walker = document.createTreeWalker(block, NodeFilter.SHOW_TEXT, {
      acceptNode: function (node) {
        var parent = node.parentElement;
        if (!parent || parent.closest(
          '.cpb-block-controls,.cpb-review-thread-pin,button,input,select,textarea'
        ) || !node.nodeValue) {
          return NodeFilter.FILTER_REJECT;
        }
        return NodeFilter.FILTER_ACCEPT;
      },
    });
    var node;
    while ((node = walker.nextNode())) nodes.push(node);
    var total = 0;
    var startOffset = -1;
    var endOffset = -1;
    nodes.forEach(function (textNode) {
      var length = textNode.nodeValue.length;
      if (range.intersectsNode(textNode)) {
        var localStart = textNode === range.startContainer ? range.startOffset : 0;
        var localEnd = textNode === range.endContainer ? range.endOffset : length;
        if (startOffset < 0) startOffset = total + localStart;
        endOffset = total + localEnd;
      }
      total += length;
    });
    if (startOffset < 0 || endOffset <= startOffset) return null;
    var sheet = block.closest('.cpb-sheet');
    var sectionStart = parseInt(state.sectionPageStarts[state.sectionId] || '1', 10);
    var localPage = sheet
      ? Math.max(0, Math.floor(printY(block, sheet) / (PRINT_PAGE.height + PRINT_PAGE.gap)))
      : 0;
    var stableAnchor = String(block.getAttribute('data-stable-anchor') || '');
    return {
      thread_uuid: reviewUUID(),
      comment_uuid: reviewUUID(),
      page_number: sectionStart + localPage,
      selected_text: selectedText,
      source_fragment_id: String(
        block.getAttribute('data-source-fragment-id')
          || block.getAttribute('data-fragment-id')
          || stableAnchor
      ),
      stable_anchor: stableAnchor,
      start_offset: startOffset,
      end_offset: endOffset,
    };
  }

  function updateReviewerSelectionAction() {
    var action = document.getElementById('cpbAddReviewerComment');
    var selection = window.getSelection();
    var range = selection && selection.rangeCount ? selection.getRangeAt(0) : null;
    var anchor = selectionInCanvas() ? reviewSelectionAnchor(range) : null;
    if (!anchor) {
      if (action) action.hidden = true;
      state.pendingReviewAnchor = null;
      return;
    }
    state.pendingReviewAnchor = anchor;
    if (!action) {
      action = document.createElement('button');
      action.type = 'button';
      action.id = 'cpbAddReviewerComment';
      action.className = 'cpb-review-selection-action';
      action.textContent = 'Add Reviewer Comment';
      action.addEventListener('mousedown', function (event) {
        event.preventDefault();
      });
      action.addEventListener('click', function () {
        var pending = state.pendingReviewAnchor;
        if (!pending) return;
        action.hidden = true;
        showReviewThreadPanel({
          thread_uuid: pending.thread_uuid,
          selected_text: pending.selected_text,
          comments: [],
          is_new: true,
          anchor: pending,
        });
      });
      document.body.appendChild(action);
    }
    var rect = range.getBoundingClientRect();
    action.style.left = Math.max(12, Math.min(
      window.innerWidth - action.offsetWidth - 12,
      rect.left + (rect.width / 2) - (action.offsetWidth / 2)
    )) + 'px';
    action.style.top = Math.min(window.innerHeight - 48, rect.bottom + 8) + 'px';
    action.hidden = false;
  }

  function reviewCommentTimestamp(value) {
    var raw = String(value || '').trim();
    if (!raw) return '';
    var normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw)
      ? raw.replace(' ', 'T') + 'Z'
      : raw;
    var date = new Date(normalized);
    if (Number.isNaN(date.getTime())) return raw;
    return new Intl.DateTimeFormat(undefined, {
      dateStyle: 'medium',
      timeStyle: 'short',
    }).format(date);
  }

  function reviewThreadMessagesHtml(comments) {
    return comments.map(function (comment) {
      var author = comment.author || {};
      var photo = String(author.photo_url || '');
      var avatar = photo
        ? '<img src="' + escapeHtml(photo) + '" alt="">'
        : '<span>' + escapeHtml(String(author.initials || '?')) + '</span>';
      var timestamp = reviewCommentTimestamp(comment.created_at_utc);
      return '<article class="cpb-review-thread-message"><div class="cpb-review-thread-avatar">'
        + avatar + '</div><div><strong>' + escapeHtml(String(author.name || 'Reviewer'))
        + '</strong><time datetime="' + escapeHtml(String(comment.created_at_utc || '')) + '">'
        + escapeHtml(timestamp) + '</time><p>'
        + escapeHtml(String(comment.body || '')) + '</p></div></article>';
    }).join('');
  }

  function refreshOpenReviewThread(threads) {
    var panel = document.getElementById('cpbReviewThreadPanel');
    if (!panel || panel.hidden) return;
    var threadUUID = String(panel.getAttribute('data-thread-uuid') || '');
    var thread = threads.find(function (candidate) {
      return String(candidate.thread_uuid || '') === threadUUID;
    });
    if (!thread) return;
    var messages = panel.querySelector('.cpb-review-thread-panel__messages');
    if (!messages) return;
    messages.innerHTML = reviewThreadMessagesHtml(
      Array.isArray(thread.comments) ? thread.comments : []
    );
    messages.scrollTop = messages.scrollHeight;
  }

  function scheduleReviewThreadSync() {
    clearTimeout(state.reviewThreadSyncTimer);
    if (document.hidden || !state.versionId) return;
    state.reviewThreadSyncTimer = setTimeout(function () {
      loadReviewThreadMarkers();
    }, 5000);
  }

  function reviewThreadTarget(thread) {
    var anchor = String(thread.stable_anchor || '');
    var fragment = String(thread.source_fragment_id || '');
    var target = null;
    if (anchor) {
      target = canvasEl.querySelector(
        '[data-stable-anchor="' + CSS.escape(anchor) + '"],#' + CSS.escape(anchor)
      );
    }
    if (!target && fragment) {
      target = canvasEl.querySelector(
        '[data-source-fragment-id="' + CSS.escape(fragment) + '"],'
          + '[data-fragment-id="' + CSS.escape(fragment) + '"],'
          + '[data-stable-anchor="' + CSS.escape(fragment) + '"]'
      );
    }
    if (!target && fragment.indexOf('/') !== -1) {
      var fragmentParts = fragment.split('/').filter(Boolean).reverse();
      fragmentParts.some(function (part) {
        target = canvasEl.querySelector(
          '[data-stable-anchor="' + CSS.escape(part) + '"],#' + CSS.escape(part)
        );
        return !!target;
      });
    }
    if (!target) {
      var selected = String(thread.selected_text || '').replace(/\s+/g, ' ').trim();
      if (selected) {
        var candidates = canvasEl.querySelectorAll(
          '.cpb-block,[data-blocks-root="1"]'
        );
        target = Array.prototype.find.call(candidates, function (candidate) {
          return String(candidate.textContent || '').replace(/\s+/g, ' ').indexOf(selected) !== -1;
        }) || null;
      }
    }
    return target;
  }

  function loadReviewThreadMarkers() {
    if (!state.versionId) return;
    return apiGet(
      apiBase + '?action=review_threads&version_id=' + encodeURIComponent(String(state.versionId))
        + '&sync=' + Date.now()
    ).then(function (res) {
      if (!res || !res.ok || !Array.isArray(res.threads)) return;
      canvasEl.querySelectorAll('.cpb-review-thread-pin').forEach(function (pin) {
        pin.remove();
      });
      if (window.CSS && CSS.highlights) {
        CSS.highlights.delete('cpb-review-remarks');
      }
      var reviewRanges = [];
      res.threads.forEach(function (thread) {
        var target = reviewThreadTarget(thread);
        if (!target) return;
        var block = target.closest('.cpb-block') || target;
        var range = reviewThreadTextRange(target, thread);
        if (range) reviewRanges.push(range);
        var pin = document.createElement('button');
        pin.type = 'button';
        pin.className = 'cpb-review-thread-pin';
        pin.textContent = '•••';
        pin.title = 'Open reviewer notes';
        pin.setAttribute(
          'aria-label',
          'Open reviewer notes (' + String((thread.comments || []).length || 1) + ')'
        );
        pin.addEventListener('click', function (event) {
          event.preventDefault();
          event.stopPropagation();
          showReviewThreadPanel(thread);
        });
        block.appendChild(pin);
      });
      if (reviewRanges.length && window.CSS && CSS.highlights && window.Highlight) {
        CSS.highlights.set('cpb-review-remarks', new Highlight(...reviewRanges));
      }
      refreshOpenReviewThread(res.threads);
    }).catch(function () {
      // Reviewer notes are additive and must never interrupt authoring.
    }).finally(function () {
      scheduleReviewThreadSync();
    });
  }

  function showReviewThreadPanel(thread) {
    var panel = document.getElementById('cpbReviewThreadPanel');
    if (!panel) {
      panel = document.createElement('div');
      panel.id = 'cpbReviewThreadPanel';
      panel.className = 'cpb-review-thread-panel';
      document.body.appendChild(panel);
    }
    var comments = Array.isArray(thread.comments) ? thread.comments : [];
    panel.setAttribute('data-thread-uuid', String(thread.thread_uuid || ''));
    panel.innerHTML =
      '<div class="cpb-review-thread-panel__backdrop" data-review-close></div>'
      + '<section class="cpb-review-thread-panel__card" role="dialog" aria-modal="true">'
      + '<header><div><strong>Reviewer Notes</strong><small>'
      + escapeHtml(String(thread.selected_text || ''))
      + '</small></div><button type="button" data-review-close aria-label="Close">×</button></header>'
      + '<div class="cpb-review-thread-panel__messages">'
      + reviewThreadMessagesHtml(comments)
      + '</div><form class="cpb-review-thread-panel__composer">'
      + '<textarea name="body" rows="2" placeholder="Reply to reviewer thread" required></textarea>'
      + '<button type="submit" aria-label="Send reviewer reply">'
      + '<span aria-hidden="true">↑</span></button>'
      + '<small class="cpb-review-thread-panel__regulation">'
      + 'Regulation references will be enabled in the next phase.'
      + '</small></form></section>';
    panel.hidden = false;
    window.requestAnimationFrame(function () {
      var messages = panel.querySelector('.cpb-review-thread-panel__messages');
      if (messages) messages.scrollTop = messages.scrollHeight;
    });
    panel.querySelectorAll('[data-review-close]').forEach(function (button) {
      button.addEventListener('click', function () {
        panel.hidden = true;
      });
    });
    var form = panel.querySelector('.cpb-review-thread-panel__composer');
    if (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var textarea = form.querySelector('textarea');
        var body = textarea ? String(textarea.value || '').trim() : '';
        if (!body) return;
        var submit = form.querySelector('button');
        if (submit) submit.disabled = true;
        var actionName = thread.is_new ? 'review_thread_create' : 'review_comment_add';
        var request = thread.is_new ? {
          version_id: state.versionId,
          anchor: thread.anchor,
          body: body,
        } : {
          version_id: state.versionId,
          thread_uuid: thread.thread_uuid,
          comment_uuid: reviewUUID(),
          body: body,
        };
        apiPost(actionName, request).then(function (res) {
          if (!res || !res.ok || !res.thread) {
            throw new Error((res && res.error) || 'Unable to add reviewer comment.');
          }
          showReviewThreadPanel(res.thread);
          loadReviewThreadMarkers();
        }).catch(showError).finally(function () {
          if (submit) submit.disabled = false;
        });
      });
    }
  }

  function loadSection(sectionId, scrollRef) {
    var loadSequence = ++state.sectionLoadSequence;
    state.sectionAssemblyProgress = 0;
    setSectionAssembly(true, 'Loading section…', 8);
    setStatus('Loading…', 'saving');
    state.pendingScrollRef = scrollRef || null;
    var url = apiBase + '?action=load&version_id=' + state.versionId + '&section_id=' + sectionId;
    return apiGet(url).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Load failed');
      if (loadSequence !== state.sectionLoadSequence) return false;
      setSectionAssembly(true, 'Preparing page content…', 28);
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
      if (state.versionInfo.is_annex_book) isAnnexBook = true;
      state.publicationFontCSS = res.publication_font_css || '';
      state.authoritativeEditorPageStartsEnabled = !!res.authoritative_editor_page_starts_enabled;
      if (!state.authoritativeEditorPageStartsEnabled) {
        state.authoritativeEditorPageStarts = [];
        state.authoritativeEditorPageStartsVersionId = 0;
        state.authoritativeEditorPageStartsSectionId = 0;
      }
      if (publicationCssEl) publicationCssEl.textContent = state.publicationFontCSS;
      state.sectionTitle = (res.section && res.section.title) ? res.section.title : '';
      state.isCoverSection = !!res.is_cover_section;
      state.isTocSection = !!res.is_toc_section;
      state.isLepSection = !!res.is_lep_section;
      state.isPart0Section = !!res.is_part0_section;
      state.isAnnexRegisterSection = !!res.is_annex_register_section;
      state.isAnnexCrossRefSection = !!res.is_annex_cross_ref_section;
      state.isAnnexHighlightsSection = !!res.is_annex_highlights_section;
      state.crossRefAnnexSectionId = parseInt(res.cross_ref_annex_section_id || '0', 10) || 0;
      applyCrossRefAnnexCatalog(res.cross_ref_annex);
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
      loadReviewThreadMarkers();
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
      if (state.isAnnexRegisterSection || state.isAnnexCrossRefSection || state.isAnnexHighlightsSection) {
        refreshAnnexAdminTypographyFromBookStyles();
      }
      applyCanvasZoom(state.canvasZoom, false);
      updateAddSubsection(res.section);
      updateOutlineButton();
      setSectionAssembly(true, 'Loading fonts, images, and page rules…', 52);
      var fontReady = settleWithin(
        document.fonts && document.fonts.ready
          ? Promise.resolve(document.fonts.ready).then(function () { return true; })
          : Promise.resolve(true),
        5000
      );
      var imagesReady = settleWithin(
        waitForCanvasImages().then(function () { return true; }),
        20000
      );
      var rulesReady = settleWithin(
        loadUnifiedManualBreaks(false).then(function () { return true; }),
        6000
      );
      var authoritativePageStartsReady = settleWithin(
        loadAuthoritativeEditorPageStarts(),
        6000
      );
      return Promise.all([
        fontReady,
        imagesReady,
        rulesReady,
        authoritativePageStartsReady,
      ]).then(function (readiness) {
        if (loadSequence !== state.sectionLoadSequence) return false;
        var incomplete = readiness.some(function (ready) { return ready !== true; });
        setSectionAssembly(true, 'Assembling pages…', 84);
        return nextAnimationFrame().then(function () {
          if (loadSequence !== state.sectionLoadSequence) return false;
          applyUnifiedPrintLayout();
          setSectionAssembly(true, 'Stabilizing page geometry…', 91);
          return nextAnimationFrame().then(function () {
            if (loadSequence !== state.sectionLoadSequence) return false;
            applyUnifiedPrintLayout();
            setSectionAssembly(true, 'Finalizing page geometry…', 96);
            return nextAnimationFrame();
          }).then(function () {
            if (loadSequence !== state.sectionLoadSequence) return false;
            if (state.pendingScrollRef) {
              var target = canvasEl.querySelector(
                '[data-canonical-section-ref="' + state.pendingScrollRef + '"]'
              );
              if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
              state.pendingScrollRef = null;
            } else {
              canvasEl.scrollTop = 0;
            }
            var projectionReady = state.liveProjection.enabled
              ? refreshLiveProjection()
              : Promise.resolve(true);
            return projectionReady.then(function () {
              if (loadSequence !== state.sectionLoadSequence) return false;
              setSectionAssembly(true, 'Pages ready', 100);
              return nextAnimationFrame().then(function () {
                if (loadSequence !== state.sectionLoadSequence) return false;
                setSectionAssembly(false, '', 100);
                if (incomplete) {
                  setStatus('Ready · some page assets could not be verified', 'warn');
                } else if (res.prior_version_label) {
                  setStatus('Ready · changes vs ' + res.prior_version_label, 'saved');
                } else {
                  setStatus(
                    state.editable ? 'Ready' : 'Read-only (released)',
                    state.editable ? 'saved' : ''
                  );
                }
                root.dispatchEvent(new CustomEvent('cpb:section-assembly-complete', {
                  detail: {
                    section_id: state.sectionId,
                    load_sequence: loadSequence,
                    complete: !incomplete,
                  },
                }));
                return true;
              });
            });
          });
        });
      });
    }).catch(function (error) {
      if (loadSequence !== state.sectionLoadSequence) return false;
      setSectionAssembly(false, '', 100);
      showError(error);
      return false;
    });
  }

  function updateAddSubsection(section) {
    if (!addSubBtn || !section) return;
    var key = section.section_key || '';
    var isNestable = ['part_1', 'part_2', 'part_3', 'part_4', 'main_content', 'annexes'].indexOf(key) >= 0
      || !!section.parent_section_id
      || documentType === 'form';
    addSubBtn.style.display = state.editable && isNestable ? 'block' : 'none';
    addSubBtn.setAttribute('data-parent-id', String(section.id || state.sectionId));
  }

  function updateOutlineButton() {
    if (!editOutlineBtn) return;
    var status = String((state.versionInfo && state.versionInfo.lifecycle_status) || '');
    var show = documentType !== 'form' && !isAnnexBook && status !== 'released';
    editOutlineBtn.style.display = show ? '' : 'none';
    if (!show && state.outlineOpen) setOutlineMode(false);
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

  function setOutlineMode(open) {
    state.outlineOpen = !!open;
    if (outlinePanelEl) {
      outlinePanelEl.hidden = !state.outlineOpen;
      if (state.outlineOpen) outlinePanelEl.classList.add('is-open');
      else outlinePanelEl.classList.remove('is-open');
    }
    if (editOutlineBtn) {
      editOutlineBtn.setAttribute('aria-pressed', state.outlineOpen ? 'true' : 'false');
    }
  }

  function setOutlineStatus(message, kind) {
    if (!structStatusEl) return;
    var text = String(message || '');
    structStatusEl.hidden = text === '';
    structStatusEl.textContent = text;
    structStatusEl.className = 'cpb-struct-status' + (kind ? ' is-' + kind : '');
  }

  function applyOutlineResult(res) {
    if (!res || !res.ok) throw new Error((res && res.error) || 'Could not update outline');
    if (res.sections_tree) {
      state.sectionsTree = res.sections_tree;
      renderTree(state.sectionsTree, state.sectionId);
    }
    renderOutlinePanel(res);
    if (res.toc_refreshed === false) {
      setOutlineStatus('Outline saved; TOC refresh needs attention: ' + String(
        res.toc_refresh_warning || 'unknown error'
      ), 'error');
      setStatus('Outline saved · TOC refresh failed', 'warn');
    } else {
      setOutlineStatus('');
      setStatus('Outline and TOC saved', 'saved');
    }
    return res;
  }

  function outlinePost(action, payload) {
    setOutlineStatus('Saving…', 'busy');
    return apiPost(action, Object.assign({ version_id: state.versionId }, payload || {}))
      .then(applyOutlineResult)
      .catch(function (error) {
        setOutlineStatus(error && error.message ? error.message : 'Could not update outline', 'error');
        throw error;
      });
  }

  function outlineSmallBtn(label, className, disabled, onClick) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'cpb-outline-btn' + (className ? ' ' + className : '');
    btn.textContent = label;
    btn.disabled = !!disabled;
    btn.addEventListener('click', onClick);
    return btn;
  }

  function renderOutlineHeadings(box, part, chapter, headings, nested) {
    (headings || []).forEach(function (heading) {
      var row = document.createElement('div');
      row.className = 'cpb-struct-heading' + (nested ? ' is-nested' : '');
      if (heading.can_promote) {
        row.draggable = true;
        row.setAttribute('data-drag-kind', 'heading');
        row.setAttribute('data-section-id', String(chapter.section_id));
        row.setAttribute('data-section-ref', heading.section_ref || '');
        row.setAttribute('data-block-id', String(heading.block_id || 0));
        row.setAttribute('data-part-id', String(part.section_id));
        var handle = document.createElement('span');
        handle.className = 'cpb-struct-handle';
        handle.textContent = '⋮⋮';
        handle.title = 'Drag onto a PART to make this a MAIN chapter';
        row.appendChild(handle);
      }
      var label = document.createElement('span');
      label.className = 'cpb-struct-heading-label';
      label.textContent = heading.nav_label || heading.title || heading.section_ref;
      row.appendChild(label);
      if (heading.can_promote) {
        var promote = document.createElement('button');
        promote.type = 'button';
        promote.className = 'cpb-struct-promote';
        promote.textContent = 'Make this a MAIN chapter';
        promote.addEventListener('click', function (event) {
          event.preventDefault();
          event.stopPropagation();
          outlinePost('promote_outline_heading', {
            section_id: chapter.section_id,
            section_ref: heading.section_ref,
            block_id: heading.block_id || 0,
          }).then(function (res) {
            if (res.section_id) loadSection(res.section_id);
          }).catch(showError);
        });
        row.appendChild(promote);
      }
      box.appendChild(row);
      if (heading.headings && heading.headings.length) {
        renderOutlineHeadings(box, part, chapter, heading.headings, true);
      }
    });
  }

  function wireOutlineDrag(row) {
    row.addEventListener('dragstart', function (event) {
      if (event.target && event.target.closest && event.target.closest('input,button')) {
        event.preventDefault();
        return;
      }
      row.classList.add('is-dragging');
      var payload = {
        kind: row.getAttribute('data-drag-kind') || '',
        section_id: parseInt(row.getAttribute('data-section-id') || '0', 10),
        section_ref: row.getAttribute('data-section-ref') || '',
        block_id: parseInt(row.getAttribute('data-block-id') || '0', 10),
        part_id: parseInt(row.getAttribute('data-part-id') || '0', 10),
      };
      event.dataTransfer.setData('application/json', JSON.stringify(payload));
      event.dataTransfer.setData('text/plain', JSON.stringify(payload));
      event.dataTransfer.effectAllowed = payload.kind === 'heading' ? 'move' : 'move';
    });
    row.addEventListener('dragend', function () {
      row.classList.remove('is-dragging');
      if (outlineBodyEl) {
        outlineBodyEl.querySelectorAll('.is-drop-target').forEach(function (el) {
          el.classList.remove('is-drop-target');
        });
      }
    });
  }

  function renderOutlinePanel(data) {
    if (!outlineBodyEl) return;
    outlineBodyEl.innerHTML = '';
    var locked = document.createElement('div');
    locked.className = 'cpb-struct-locked';
    (data.locked || []).forEach(function (item) {
      var row = document.createElement('div');
      row.className = 'cpb-struct-locked-item';
      var icon = document.createElement('span');
      icon.textContent = '🔒';
      var label = document.createElement('span');
      label.textContent = item.title || item.kind;
      row.appendChild(icon);
      row.appendChild(label);
      locked.appendChild(row);
    });
    outlineBodyEl.appendChild(locked);

    (data.parts || []).forEach(function (part) {
      var box = document.createElement('div');
      box.className = 'cpb-struct-part';
      box.setAttribute('data-part-id', String(part.section_id));

      var head = document.createElement('div');
      head.className = 'cpb-struct-part-head';
      var num = document.createElement('span');
      num.className = 'cpb-struct-part-num';
      num.textContent = 'PART ' + part.part_number;
      var partInput = document.createElement('input');
      partInput.type = 'text';
      partInput.value = part.title || '';
      partInput.setAttribute('aria-label', 'PART ' + part.part_number + ' title');
      partInput.addEventListener('change', function () {
        outlinePost('rename_outline_part', {
          section_id: part.section_id,
          title: partInput.value,
        }).catch(showError);
      });
      head.appendChild(num);
      head.appendChild(partInput);
      box.appendChild(head);

      (part.chapters || []).forEach(function (chapter, index) {
        var row = document.createElement('div');
        row.className = 'cpb-struct-chapter';
        row.draggable = true;
        row.setAttribute('data-drag-kind', 'chapter');
        row.setAttribute('data-section-id', String(chapter.section_id));
        row.setAttribute('data-part-id', String(part.section_id));
        var handle = document.createElement('span');
        handle.className = 'cpb-struct-handle';
        handle.textContent = '⋮⋮';
        var chNum = document.createElement('span');
        chNum.className = 'cpb-struct-num';
        chNum.textContent = chapter.chapter_number + '.';
        var chInput = document.createElement('input');
        chInput.type = 'text';
        chInput.value = chapter.title || '';
        chInput.setAttribute('aria-label', 'Chapter ' + chapter.chapter_number + ' title');
        chInput.addEventListener('change', function () {
          outlinePost('rename_outline_chapter', {
            section_id: chapter.section_id,
            title: chInput.value,
          }).catch(showError);
        });
        var actions = document.createElement('div');
        actions.className = 'cpb-struct-actions';
        actions.appendChild(outlineSmallBtn('↑', '', index === 0, function () {
          outlinePost('move_outline_chapter', { section_id: chapter.section_id, direction: 'up' }).catch(showError);
        }));
        actions.appendChild(outlineSmallBtn('↓', '', index === part.chapters.length - 1, function () {
          outlinePost('move_outline_chapter', { section_id: chapter.section_id, direction: 'down' }).catch(showError);
        }));
        actions.appendChild(outlineSmallBtn('Make subchapter', 'cpb-outline-btn--demote', index === 0, function () {
          var target = part.chapters[index - 1];
          if (!target) return;
          if (!window.confirm(
            'Move MAIN chapter “' + (chapter.title || chapter.nav_label)
              + '” under “' + (target.title || target.nav_label) + '” as a subchapter?'
          )) return;
          outlinePost('demote_outline_chapter', {
            section_id: chapter.section_id,
            target_section_id: target.section_id,
          }).then(function (res) {
            if (res.section_id) loadSection(res.section_id);
          }).catch(showError);
        }));
        actions.appendChild(outlineSmallBtn('×', 'cpb-outline-btn--danger', false, function () {
          if (!window.confirm('Remove MAIN chapter “' + (chapter.title || chapter.nav_label) + '”?')) return;
          outlinePost('delete_outline_chapter', { section_id: chapter.section_id }).then(function () {
            if (state.sectionId === chapter.section_id) {
              loadSection(part.section_id);
            }
          }).catch(showError);
        }));
        row.appendChild(handle);
        row.appendChild(chNum);
        row.appendChild(chInput);
        row.appendChild(actions);
        wireOutlineDrag(row);
        box.appendChild(row);
        renderOutlineHeadings(box, part, chapter, chapter.headings || [], false);
      });

      var add = document.createElement('span');
      add.className = 'cpb-struct-add';
      add.setAttribute('role', 'button');
      add.tabIndex = 0;
      add.textContent = '+ Add MAIN chapter';
      add.addEventListener('click', function () {
        var title = window.prompt('MAIN chapter title');
        if (!title || !title.trim()) return;
        outlinePost('add_outline_chapter', {
          part_section_id: part.chapter_parent_id || part.section_id,
          title: title.trim(),
        }).then(function (res) {
          if (res.section_id) loadSection(res.section_id);
        }).catch(showError);
      });
      box.appendChild(add);

      box.addEventListener('dragover', function (event) {
        var draggingHeading = outlineBodyEl && outlineBodyEl.querySelector('.cpb-struct-heading.is-dragging');
        if (!draggingHeading) return;
        event.preventDefault();
        box.classList.add('is-drop-target');
      });
      box.addEventListener('dragleave', function (event) {
        if (event.target === box) box.classList.remove('is-drop-target');
      });
      box.addEventListener('drop', function (event) {
        event.preventDefault();
        box.classList.remove('is-drop-target');
        var payload = parseOutlineDrag(event);
        if (!payload || payload.kind !== 'heading') return;
        if (payload.part_id !== part.section_id) return;
        outlinePost('promote_outline_heading', {
          section_id: payload.section_id,
          section_ref: payload.section_ref,
          block_id: payload.block_id || 0,
          insert_before_section_id: 0,
        }).then(function (res) {
          if (res.section_id) loadSection(res.section_id);
        }).catch(showError);
      });

      outlineBodyEl.appendChild(box);
    });

    if (outlineBodyEl) {
      outlineBodyEl.querySelectorAll('.cpb-struct-heading[draggable="true"]').forEach(wireOutlineDrag);
    }
  }

  function parseOutlineDrag(event) {
    try {
      var raw = event.dataTransfer.getData('application/json') || event.dataTransfer.getData('text/plain') || '';
      var payload = JSON.parse(raw);
      return payload && typeof payload === 'object' ? payload : null;
    } catch (err) {
      return null;
    }
  }

  function openOutlinePanel() {
    setOutlineMode(true);
    apiPost('get_outline', { version_id: state.versionId }).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Could not load outline');
      renderOutlinePanel(res);
    }).catch(function (error) {
      setOutlineMode(false);
      showError(error);
    });
  }

  function closeOutlinePanel() {
    setOutlineMode(false);
    renderTree(state.sectionsTree, state.sectionId);
    if (state.sectionId) loadSection(state.sectionId);
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
        if (state.viewMode === 'paginated') {
          state.sectionId = node.id;
          state.sectionPageIndex = 0;
          renderTree(state.sectionsTree, state.sectionId);
          if (state.paginatedResult) {
            renderPaginatedView(state.paginatedResult);
            canvasEl.scrollTop = 0;
          } else {
            loadPaginatedView();
          }
          return;
        }
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
      blocksRoot.querySelectorAll('*').forEach(function (el) {
        Array.prototype.slice.call(el.attributes || []).forEach(function (attr) {
          if (attr.name === 'data-wired' || /^data-.*-wired$/.test(attr.name)) {
            el.removeAttribute(attr.name);
          }
        });
      });
      wireCanvas();
      saveAllBlocks();
    }
    applyLayoutToDom(snap.layout || {});
    saveLayout();
  }

  function doUndo(snapshotOnly) {
    var active = document.activeElement;
    var inTableCell = active && active.closest && active.closest('.cpb-table th, .cpb-table td');
    if (snapshotOnly) {
      if (state.undoStack.length === 0) {
        setStatus('Nothing to undo', '');
        return;
      }
      state.redoStack.push(captureUndoSnapshot());
      restoreUndoSnapshot(state.undoStack.pop());
      setStatus('Undone', 'saved');
      return;
    }
    if (inTableCell && state.undoStack.length > 0) {
      state.redoStack.push(captureUndoSnapshot());
      restoreUndoSnapshot(state.undoStack.pop());
      setStatus('Undone', 'saved');
      return;
    }
    if (active && active.isContentEditable && !inTableCell && document.queryCommandSupported('undo')) {
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

  function doRedo(snapshotOnly) {
    var active = document.activeElement;
    var inTableCell = active && active.closest && active.closest('.cpb-table th, .cpb-table td');
    if (snapshotOnly) {
      if (state.redoStack.length === 0) {
        setStatus('Nothing to redo', '');
        return;
      }
      state.undoStack.push(captureUndoSnapshot());
      restoreUndoSnapshot(state.redoStack.pop());
      setStatus('Redone', 'saved');
      return;
    }
    if (inTableCell && state.redoStack.length > 0) {
      state.undoStack.push(captureUndoSnapshot());
      restoreUndoSnapshot(state.redoStack.pop());
      setStatus('Redone', 'saved');
      return;
    }
    if (active && active.isContentEditable && !inTableCell && document.queryCommandSupported('redo')) {
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
    if (state.layoutTimer) {
      clearTimeout(state.layoutTimer);
      state.layoutTimer = null;
    }
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

  function handleCanonicalTableAction(blockEl, action) {
    if (!blockEl || !action || !state.editable) return;
    if (action === 'delete-table') {
      if (!confirm('Delete this entire table?')) return;
      var blockId = parseInt(blockEl.getAttribute('data-block-id') || '0', 10);
      flushAllPendingSaves().then(function () {
        return apiPost('delete_block', { version_id: state.versionId, block_id: blockId });
      }).then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Delete failed');
        clearPendingForBlock(blockId);
        clearStyleTargetForBlock(blockEl);
        closeTableTools();
        blockEl.remove();
        setStatus('Table deleted', 'saved');
        markPaginationChanged();
        return recomputeSectionNumbers();
      }).catch(showError);
      return;
    }
    if (action === 'copy-table') {
      copyEntireTable(blockEl);
      return;
    }
    if (action === 'paste-table') {
      pasteEntireTable(blockEl);
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
    } else if (action === 'merge-cells-down') {
      if (!tableMergeCellDown(blockEl)) return;
    } else if (action === 'unmerge-cells-down') {
      if (!tableUnmergeCellDown(blockEl)) return;
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
      getTableCellsForStyle({
        block: blockEl,
        el: state.focusedTableCell,
        type: 'table-cell',
      }).forEach(function (cell) {
        applyTableCellBg(blockEl, cell, '');
      });
    } else if (action === 'copy-cells') {
      copyTableCells(blockEl);
      return;
    } else if (action === 'paste-cells') {
      pasteTableCells(blockEl);
      return;
    } else if (action === 'formula-sum') insertTableFormula(blockEl, 'SUM');
    else if (action === 'formula-avg') insertTableFormula(blockEl, 'AVG');
    else if (action === 'formula-custom') insertTableFormula(blockEl, 'CUSTOM');
    else if (action === 'cell-align-left' || action === 'cell-align-center' || action === 'cell-align-right') {
      var alignmentCell = resolveSelectedTableCell(blockEl);
      if (!alignmentCell) return;
      var cellAlign = action.replace('cell-align-', '');
      getTableCellsForStyle({
        block: blockEl,
        el: alignmentCell,
        type: 'table-cell',
      }).forEach(function (cell) {
        applyStyleToTableCell(cell, { align: cellAlign });
      });
    }
    else if (action === 'table-align-left' || action === 'table-align-center' || action === 'table-align-right') {
      applyTableBlockAlign(blockEl, action.replace('table-align-', ''));
    }
    wireTableResize(blockEl);
    syncTableWidth(blockEl);
    syncTableToolsContext(blockEl);
    scheduleSave(blockEl);
    flushSave(blockEl);
  }

  function wireTableToolbar() {
    if (!tableToolbarEl || tableToolbarEl.getAttribute('data-wired') === '1') return;
    tableToolbarEl.setAttribute('data-wired', '1');
    tableToolbarEl.addEventListener('mousedown', function (event) {
      if (event.target.closest('button[data-table-action]')) event.preventDefault();
      if (event.target.closest('input[type="color"][data-table-action]')) {
        pausePendingSaveTimer();
      }
    });
    tableToolbarEl.addEventListener('focusin', function (event) {
      if (!event.target.matches('input[type="color"][data-table-action]')) return;
      pausePendingSaveTimer();
    });
    tableToolbarEl.addEventListener('focusout', function (event) {
      if (!event.target.matches('input[type="color"][data-table-action]')) return;
      var blockEl = state.activeTableToolsBlock;
      if (blockEl && isConnectedEl(blockEl)) resumePendingSave(blockEl);
    });
    tableToolbarEl.addEventListener('click', function (event) {
      var control = event.target.closest('button[data-table-action]');
      if (!control || control.disabled || !state.editable) return;
      event.preventDefault();
      var blockEl = state.activeTableToolsBlock;
      if (!blockEl || !isConnectedEl(blockEl)) {
        syncTableToolsContext(null);
        return;
      }
      var action = control.getAttribute('data-table-action') || '';
      if (blockEl.getAttribute('data-structured-table-editor') === '1') {
        handleStructuredTableAction(blockEl, action);
      } else {
        handleCanonicalTableAction(blockEl, action);
      }
    });
    tableToolbarEl.addEventListener('input', function (event) {
      var input = event.target.closest('input[data-table-action]');
      var blockEl = state.activeTableToolsBlock;
      if (!input || input.disabled || !blockEl || !isConnectedEl(blockEl)) return;
      if (document.activeElement === input && input.getAttribute('data-color-commit') !== '1') return;
      if (blockEl.getAttribute('data-structured-table-editor') === '1') return;
      var action = input.getAttribute('data-table-action') || '';
      pushUndo();
      if (action === 'border-color') {
        applyTableBorderColor(blockEl, input.value);
      } else if (action === 'cell-bg') {
        var selectedCell = resolveSelectedTableCell(blockEl);
        if (!selectedCell) return;
        getTableCellsForStyle({
          block: blockEl,
          el: selectedCell,
          type: 'table-cell',
        }).forEach(function (cell) {
          applyTableCellBg(blockEl, cell, input.value);
        });
      }
      flushSave(blockEl);
      syncTableToolsContext(blockEl);
    });
    tableToolbarEl.addEventListener('change', function (event) {
      var input = event.target.closest('input[type="color"][data-table-action]');
      if (!input) return;
      input.setAttribute('data-color-commit', '1');
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.removeAttribute('data-color-commit');
    });
    syncTableToolsContext(null);
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
      if (listStartInput) {
        var focusedOrderedList = e.target.closest('ol');
        state.activeOrderedList = focusedOrderedList;
        listStartInput.disabled = !focusedOrderedList;
        if (focusedOrderedList) {
          var firstOrderedList = focusedOrderedList.classList.contains('cpb-list')
            ? focusedOrderedList.closest('.cpb-block').querySelector('ol.cpb-list')
            : focusedOrderedList;
          setListStartSelectorValue(Math.max(
            1,
            parseInt(firstOrderedList.getAttribute('start') || '1', 10) || 1
          ));
        }
      }
      if (cell && cell.isContentEditable) {
        state.focusedTableCell = cell;
      } else if (e.target.closest('.cpb-callout-title, .cpb-callout-text, .cpb-paragraph, .cpb-heading, .cpb-list, .cpb-list-continuation')) {
        state.focusedTableCell = null;
      }
      requestAnimationFrame(function () {
        rememberStyleTarget();
      });
    });

    canvasEl.addEventListener('keydown', function (e) {
      var list = e.target.closest && e.target.closest('.cpb-list');
      if (!list || !list.isContentEditable || !canvasEl.contains(list)) return;
      var blockEl = list.closest('.cpb-block');
      if (blockEl) handleListKeyDown(e, list, blockEl);
    }, true);

    canvasEl.addEventListener('pointerdown', function (e) {
      var tableCell = e.target.closest('.cpb-table th, .cpb-table td');
      var clickedCellCaret = tableCell
        ? caretRangeAtPointWithin(tableCell, e.clientX, e.clientY)
        : null;
      var tableTools = e.target.closest('.cpb-table-tools');
      var clickedTableBlock = e.target.closest(
        '.cpb-block--table, [data-structured-table-editor="1"]'
      );
      if (tableCell && tableCell.isContentEditable && canvasEl.contains(tableCell)) {
        var toggleCell = e.metaKey || e.ctrlKey;
        if (toggleCell) e.preventDefault();
        selectTableCell(tableCell, e.shiftKey, toggleCell);
        state.focusedTableCell = tableCell;
        var tableBlock = tableCell.closest('.cpb-block, [data-structured-table-editor="1"]');
        if (tableBlock) {
          state.lastStyleTarget = { block: tableBlock, el: tableCell, type: 'table-cell' };
        }
      } else if (!tableTools && !e.target.closest('.cpb-table')) {
        clearTableCellSelection();
      }
      requestAnimationFrame(function () {
        saveSelectionRange();
        rememberStyleTarget();
        if (tableCell && clickedTableBlock) {
          openTableTools(clickedTableBlock, tableCell);
        } else if (!tableTools && clickedTableBlock) {
          openTableTools(clickedTableBlock, clickedTableBlock.querySelector('.cpb-table-wrap'));
        } else if (!tableTools && !clickedTableBlock) {
          closeTableTools();
        }
        if (tableCell && !toggleCell) {
          keepCaretInClickedTableCell(tableCell, clickedCellCaret);
        }
      });
    }, true);

    document.addEventListener('pointerdown', function (e) {
      var activeBlock = state.activeTableToolsBlock;
      if (!activeBlock || !isConnectedEl(activeBlock)) return;
      if (tableToolbarEl && tableToolbarEl.contains(e.target)) return;
      if (!activeBlock.contains(e.target)) closeTableTools();
    }, true);

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape' || !state.activeTableToolsBlock) return;
      e.preventDefault();
      closeTableTools();
    });

    window.addEventListener('resize', function () {
      if (!state.activeTableToolsBlock) return;
      positionTableTools(state.activeTableToolsBlock, state.activeTableToolsAnchor);
    });

    canvasEl.addEventListener('mouseup', function () {
      saveSelectionRange();
      rememberStyleTarget();
    });

    canvasEl.addEventListener('keyup', rememberStyleTarget);

    canvasEl.addEventListener('click', function (e) {
      var clickedCell = e.target.closest('.cpb-table th, .cpb-table td');
      if (clickedCell && clickedCell.isContentEditable) {
        keepCaretInClickedTableCell(
          clickedCell,
          caretRangeAtPointWithin(clickedCell, e.clientX, e.clientY)
        );
      }
      var closeToolsBtn = e.target.closest('[data-table-tools-close]');
      if (closeToolsBtn) {
        e.preventDefault();
        closeTableTools();
        return;
      }
      var btn = e.target.closest('button[data-table-action]');
      if (!btn || !state.editable) return;
      e.preventDefault();
      var blockEl = btn.closest('.cpb-block, [data-structured-table-editor="1"]');
      if (!blockEl) return;
      var action = btn.getAttribute('data-table-action');
      if (blockEl.getAttribute('data-structured-table-editor') === '1') {
        handleStructuredTableAction(blockEl, action);
        return;
      }
      handleCanonicalTableAction(blockEl, action);
    });

    canvasEl.addEventListener('input', function (e) {
      var input = e.target.closest('input[data-table-action="border-color"], input[data-table-action="cell-bg"], input[data-table-action="cell-text-color"]');
      if (!input || !state.editable) return;
      if (document.activeElement === input && input.getAttribute('data-color-commit') !== '1') return;
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
        var colorTarget = {
          block: blockEl,
          el: state.focusedTableCell,
          type: 'table-cell',
        };
        var colorCells = getTableCellsForStyle(colorTarget);
        colorCells.forEach(function (cell) {
          if (colorCells.length > 1) applyTableCellTextColor(cell, input.value);
          else applyColorToTableCell(cell, input.value);
        });
        if (textColorInput) textColorInput.value = input.value;
        updateParagraphStyleSelectForElement(state.focusedTableCell);
      } else if (!state.focusedTableCell || !blockEl.contains(state.focusedTableCell)) {
        setStatus('Click a table cell first', 'error');
        return;
      } else {
        getTableCellsForStyle({
          block: blockEl,
          el: state.focusedTableCell,
          type: 'table-cell',
        }).forEach(function (cell) {
          applyTableCellBg(blockEl, cell, input.value);
        });
      }
      flushSave(blockEl);
    });
    canvasEl.addEventListener('change', function (e) {
      var input = e.target.closest('input[type="color"][data-table-action]');
      if (!input) return;
      input.setAttribute('data-color-commit', '1');
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.removeAttribute('data-color-commit');
    });

    canvasEl.addEventListener('copy', function (e) {
      var cell = e.target.closest('.cpb-table th, .cpb-table td');
      if (!cell || !cell.isContentEditable) return;
      var blockEl = cell.closest('.cpb-block--table');
      if (!blockEl) return;
      if (shouldUseNativeTableCellClipboard(cell)) return;
      var text = buildCopyText(blockEl, cell);
      if (!text) return;
      e.preventDefault();
      state.tableClipboard = text;
      state.tableClipboardStyles = buildCopyStyles(blockEl, cell);
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
      var part0Resize = handle.getAttribute('data-part0-column-resize') === '1';
      var blockEl = part0Resize
        ? handle.closest('.cpb-table-wrap')
        : handle.closest('.cpb-block--table');
      if (!blockEl) return;
      pushUndo();
      var colIndex = parseInt(handle.getAttribute('data-col-index') || '0', 10);
      var table = blockEl.querySelector('table');
      if (!table) return;
      var cols = table.querySelectorAll('colgroup col');
      var col = cols[colIndex];
      if (!col) return;
      var nextCol = cols[colIndex + 1] || null;
      var startX = e.clientX;
      var startW = part0Resize ? col.getBoundingClientRect().width : colWidthPx(col);
      var startNextW = nextCol
        ? (part0Resize ? nextCol.getBoundingClientRect().width : colWidthPx(nextCol))
        : 0;
      var zoomScale = Math.max(0.1, state.canvasZoom / 100);
      var hint = ensureResizeHint();

      function onMove(ev) {
        var desired = startW + ((ev.clientX - startX) / zoomScale);
        var w;
        if (nextCol) {
          var pairWidth = startW + startNextW;
          w = Math.max(60, Math.min(pairWidth - 60, desired));
          nextCol.style.width = (pairWidth - w) + 'px';
        } else {
          w = clampColWidth(blockEl, colIndex, desired);
        }
        col.style.width = w + 'px';
        if (!part0Resize) syncTableWidth(blockEl);
        hint.textContent = w + 'px';
        hint.style.display = 'block';
        hint.style.left = (ev.clientX + 12) + 'px';
        hint.style.top = (ev.clientY + 12) + 'px';
      }

      function onUp() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        hint.style.display = 'none';
        if (part0Resize) {
          var totalWidth = Math.max(1, table.getBoundingClientRect().width);
          cols.forEach(function (item) {
            var width = item.getBoundingClientRect().width;
            item.style.width = ((width / totalWidth) * 100).toFixed(3) + '%';
          });
          table.style.width = '100%';
          if (state.isLepSection) {
            var lepWidths = extractTableColumnWidths(table);
            apiPost('save_lep_page', {
              version_id: state.versionId,
              lep_page: { column_widths: lepWidths },
            }).then(function (res) {
              if (!res.ok) throw new Error(res.error || 'LEP table layout save failed');
              state.lepPage = res.lep_page || state.lepPage;
              setStatus('LEP table layout saved', 'saved');
            }).catch(showError);
          } else {
            schedulePart0Save();
            flushPart0Save();
          }
        } else {
          syncTableWidth(blockEl);
          scheduleSave(blockEl);
          flushSave(blockEl);
        }
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
      if (shouldUseNativeTableCellClipboard(cell)) return;
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

    canvasEl.querySelectorAll('.cpb-paragraph-row').forEach(function (row) {
      ensureParagraphStack(row);
    });

    canvasEl.querySelectorAll('.cpb-block').forEach(function (blockEl) {
      blockEl.querySelectorAll('[contenteditable="true"]').forEach(function (field) {
        if (field.getAttribute('data-input-wired') === '1') return;
        field.setAttribute('data-input-wired', '1');
        if (field.classList.contains('cpb-paragraph')
          || field.classList.contains('cpb-heading')
          || field.classList.contains('cpb-list')
          || field.classList.contains('cpb-list-continuation')) {
          refreshBlockTypographyFromBookStyles(field);
        }
        syncSectionNumberTypography(field);
        field.addEventListener('beforeinput', function () {
          if (field.closest('.cpb-table th, .cpb-table td') || state.contentUndoLock) return;
          pushUndo();
          state.contentUndoLock = true;
          setTimeout(function () { state.contentUndoLock = false; }, 0);
        });
        field.addEventListener('input', function () {
          scheduleSave(blockEl);
          scheduleUnifiedPrintLayout(300);
        });
        field.addEventListener('blur', function (event) {
          if (isDeferredSaveControl(event.relatedTarget)) {
            pausePendingSaveTimer();
            return;
          }
          flushPendingSave(blockEl);
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
          flushAllPendingSaves().then(function () {
            return apiPost('delete_block', { version_id: state.versionId, block_id: blockId });
          }).then(function (res) {
            if (!res.ok) throw new Error(res.error || 'Delete failed');
            clearPendingForBlock(blockId);
            clearStyleTargetForBlock(blockEl);
            blockEl.remove();
            setStatus('Deleted', 'saved');
            markPaginationChanged();
            return recomputeSectionNumbers();
          }).catch(showError);
        } else if (action === 'move-up' || action === 'move-down') {
          pushUndo();
          apiPost('move_block', {
            version_id: state.versionId,
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
            markPaginationChanged();
          }).catch(showError);
        }
      });
    });

    canvasEl.querySelectorAll('.cpb-block--table').forEach(function (blockEl) {
      normalizeTableTitleRow(blockEl);
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
    applyTMGenPublicationFont();
    setTimeout(applyTMGenPublicationFont, 0);
  }

  function applyTMGenPublicationFont() {
    if (!state.publicationFontCSS || !canvasEl) return;
    canvasEl.querySelectorAll('[style]').forEach(function (el) {
      var family = String(el.style.fontFamily || '').toLowerCase();
      if (
        family.indexOf('system-ui') === -1
        && family.indexOf('arial') === -1
        && family.indexOf('segoe ui') === -1
      ) {
        return;
      }
      el.style.setProperty('font-family', '"IPCA TM GEN Noto Sans", sans-serif', 'important');
    });
  }

  function saveAllBlocks() {
    canvasEl.querySelectorAll('.cpb-block').forEach(function (blockEl) {
      flushSave(blockEl);
    });
  }

  function flushAllPendingSaves() {
    clearTimeout(state.saveTimer);
    var promises = [];
    Object.keys(state.pending).forEach(function (id) {
      var pendingEl = state.pending[id];
      if (isConnectedEl(pendingEl)) {
        var result = flushSave(pendingEl);
        if (result && typeof result.then === 'function') {
          promises.push(result);
        }
      }
    });
    state.pending = {};
    return promises.length ? Promise.all(promises) : Promise.resolve();
  }

  function formatCrossRefDisplay(documentKey, refKey) {
    refKey = String(refKey || '').trim();
    documentKey = String(documentKey || '').trim();
    if (!refKey) return '';
    if (/^(NCO|FCL|CAMO|SPO|CAT|ORO)\./i.test(refKey)) {
      return 'Part ' + refKey;
    }
    if (documentKey) return documentKey + ' ' + refKey;
    return refKey;
  }

  function initCrossRefAnnexSelects() {
    if (!crossRefDocSelect) return;
    crossRefDocSelect.innerHTML = '<option value="">Cross ref doc…</option>';
    Object.keys(crossRefAnnex).forEach(function (docKey) {
      var opt = document.createElement('option');
      opt.value = docKey;
      opt.textContent = docKey;
      crossRefDocSelect.appendChild(opt);
    });
  }

  function applyCrossRefAnnexCatalog(catalog) {
    crossRefAnnex = catalog && typeof catalog === 'object' ? catalog : {};
    initCrossRefAnnexSelects();
    var target = getActiveStyleTarget();
    if (target && target.type === 'paragraph') {
      var ps = canonicalParagraphStyleKey(target.el.getAttribute('data-paragraph-style') || 'body');
      updateCrossRefFieldVisibility(ps, target.el);
    }
  }

  function populateCrossRefKeySelect(documentKey) {
    if (!crossRefKeySelect) return;
    crossRefKeySelect.innerHTML = '<option value="">Select reference…</option>';
    var entries = crossRefAnnex[documentKey] || [];
    entries.forEach(function (entry) {
      var opt = document.createElement('option');
      opt.value = entry.key;
      opt.textContent = entry.label || entry.key;
      crossRefKeySelect.appendChild(opt);
    });
    crossRefKeySelect.disabled = entries.length === 0;
  }

  function ensureParagraphStack(row) {
    if (!row) return null;
    var stack = row.querySelector('.cpb-paragraph-stack');
    if (stack) return stack;
    var paragraph = row.querySelector('.cpb-paragraph');
    if (!paragraph) return null;
    stack = document.createElement('div');
    stack.className = 'cpb-paragraph-stack';
    paragraph.parentNode.insertBefore(stack, paragraph);
    stack.appendChild(paragraph);
    return stack;
  }

  function updateCrossRefLine(row, documentKey, refKey) {
    if (!row) return;
    var stack = ensureParagraphStack(row);
    if (!stack) return;
    var existing = stack.querySelector('.cpb-cross-ref');
    refKey = String(refKey || '').trim();
    if (!refKey) {
      if (existing) existing.remove();
      return;
    }
    var display = formatCrossRefDisplay(documentKey, refKey);
    if (!existing) {
      existing = document.createElement('div');
      existing.className = 'cpb-cross-ref';
      existing.setAttribute('contenteditable', 'false');
      stack.appendChild(existing);
    }
    if (documentKey) {
      existing.setAttribute('data-cross-ref-document', documentKey);
    } else {
      existing.removeAttribute('data-cross-ref-document');
    }
    existing.setAttribute('data-cross-ref-key', refKey);
    existing.textContent = '(Ref. ' + display + ')';
  }

  function readCrossRefFromRow(row) {
    if (!row) return { document: '', key: '' };
    var line = row.querySelector('.cpb-cross-ref');
    if (!line) return { document: '', key: '' };
    return {
      document: line.getAttribute('data-cross-ref-document') || '',
      key: line.getAttribute('data-cross-ref-key') || '',
    };
  }

  function updateCrossRefFieldVisibility(styleKey, el) {
    var show = styleKey === 'title'
      || styleKey === 'subtitle_1'
      || styleKey === 'subtitle_2'
      || styleKey === 'subtitle_3'
      || styleKey === 'subtitle_4'
      || styleKey === 'body';
    if (crossRefDocSelect) crossRefDocSelect.hidden = !show;
    if (crossRefKeySelect) crossRefKeySelect.hidden = !show;
    if (crossRefClearBtn) crossRefClearBtn.hidden = !show;
    if (!show || !el) return;
    var row = el.closest('.cpb-paragraph-row');
    var crossRef = readCrossRefFromRow(row);
    if (crossRefDocSelect) crossRefDocSelect.value = crossRef.document || '';
    populateCrossRefKeySelect(crossRef.document || '');
    if (crossRefKeySelect) crossRefKeySelect.value = crossRef.key || '';
  }

  function applyCrossRef(documentKey, refKey) {
    var target = getActiveStyleTarget();
    if (!target || target.type !== 'paragraph') return;
    pushUndo();
    var row = target.el.closest('.cpb-paragraph-row');
    updateCrossRefLine(row, documentKey, refKey);
    scheduleSave(target.block);
    flushSave(target.block);
  }

  function clearTableCellSelection() {
    canvasEl.querySelectorAll('.cpb-table th.is-cell-selected, .cpb-table td.is-cell-selected').forEach(function (cell) {
      cell.classList.remove('is-cell-selected');
    });
    state.selectedTableCells = [];
  }

  function addTableCellToSelection(cell) {
    if (!cell || state.selectedTableCells.indexOf(cell) >= 0) return;
    cell.classList.add('is-cell-selected');
    state.selectedTableCells.push(cell);
  }

  function toggleTableCellInSelection(cell) {
    if (!cell) return;
    var index = state.selectedTableCells.indexOf(cell);
    if (index >= 0) {
      cell.classList.remove('is-cell-selected');
      state.selectedTableCells.splice(index, 1);
      return;
    }
    addTableCellToSelection(cell);
  }

  function selectTableCell(cell, extend, toggle) {
    if (!cell) return;
    if (toggle) {
      var selectedInOtherTable = state.selectedTableCells.some(function (selectedCell) {
        return selectedCell.closest('table') !== cell.closest('table');
      });
      if (selectedInOtherTable) clearTableCellSelection();
      toggleTableCellInSelection(cell);
      return;
    }
    if (!extend) {
      clearTableCellSelection();
      addTableCellToSelection(cell);
      return;
    }
    var anchor = state.focusedTableCell;
    if (!anchor || !anchor.closest('table') || anchor.closest('table') !== cell.closest('table')) {
      clearTableCellSelection();
      addTableCellToSelection(cell);
      return;
    }
    clearTableCellSelection();
    var table = cell.closest('table');
    var cells = Array.prototype.slice.call(table.querySelectorAll('th, td'));
    var start = cells.indexOf(anchor);
    var end = cells.indexOf(cell);
    if (start < 0 || end < 0) {
      addTableCellToSelection(cell);
      return;
    }
    if (start > end) {
      var tmp = start;
      start = end;
      end = tmp;
    }
    for (var i = start; i <= end; i++) {
      addTableCellToSelection(cells[i]);
    }
  }

  function getTableCellsForStyle(target) {
    if (!target || target.type !== 'table-cell') return [];
    var selected = state.selectedTableCells.filter(function (cell) {
      return target.block && target.block.contains(cell);
    });
    if (selected.length > 1) return selected;
    return target.el ? [target.el] : [];
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

  function pausePendingSaveTimer() {
    if (!state.saveTimer) return;
    clearTimeout(state.saveTimer);
    state.saveTimer = null;
  }

  function isDeferredSaveControl(element) {
    return !!(
      element
      && element.matches
      && element.matches('input[type="color"]')
      && root.contains(element)
    );
  }

  function resumePendingSave(blockEl) {
    if (!blockEl || !isConnectedEl(blockEl)) return;
    var blockId = parseInt(blockEl.getAttribute('data-block-id') || '0', 10);
    if (!blockId || !state.pending[blockId]) return;
    scheduleSave(blockEl);
  }

  function flushPendingSave(blockEl) {
    if (!blockEl || !isConnectedEl(blockEl)) return;
    var blockId = parseInt(blockEl.getAttribute('data-block-id') || '0', 10);
    if (!blockId || !state.pending[blockId]) return;
    return flushSave(blockEl);
  }

  function syncBlockChangePresentation(blockEl, blockHtml) {
    if (!blockEl || !blockHtml) return;
    var holder = document.createElement('div');
    holder.innerHTML = blockHtml;
    var source = holder.querySelector('.cpb-block');
    if (!source) return;
    ['cpb-block--changed', 'cpb-block--new', 'cpb-block--modified'].forEach(function (className) {
      blockEl.classList.toggle(className, source.classList.contains(className));
    });
    var status = source.getAttribute('data-change-status');
    if (status) blockEl.setAttribute('data-change-status', status);
    else blockEl.removeAttribute('data-change-status');
    var currentMarker = blockEl.querySelector(':scope > .cpb-change-marker');
    var sourceMarker = source.querySelector(':scope > .cpb-change-marker');
    if (sourceMarker && !currentMarker) {
      blockEl.insertBefore(sourceMarker.cloneNode(true), blockEl.firstChild);
    } else if (!sourceMarker && currentMarker) {
      currentMarker.remove();
    }
    scheduleUnifiedPrintLayout(0);
  }

  function flushSave(blockEl, refreshNumbering) {
    if (!state.editable || !blockEl || !isConnectedEl(blockEl)) return;
    var blockId = parseInt(blockEl.getAttribute('data-block-id') || '0', 10);
    var blockType = blockEl.getAttribute('data-block-type') || '';
    clearPendingForBlock(blockId);
    if (Object.keys(state.pending).length === 0 && state.saveTimer) {
      clearTimeout(state.saveTimer);
      state.saveTimer = null;
    }
    var payload = extractPayload(blockEl, blockType);
    setStatus('Saving…', 'saving');
    return apiPost('update_block', { version_id: state.versionId, block_id: blockId, payload: payload }).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Save failed');
      applyNumberingState(res);
      if (res.cross_ref_annex) {
        applyCrossRefAnnexCatalog(res.cross_ref_annex);
      }
      syncBlockChangePresentation(blockEl, res.block_html || '');
      setStatus('Saved', 'saved');
      markPaginationChanged();
      if (blockNeedsTocRefresh(blockEl)) {
        scheduleTocSync();
      }
      if (refreshNumbering) {
        return recomputeSectionNumbers().catch(showError);
      }
    }).catch(showError);
  }

  function defaultBookStyles() {
    return {
      paragraph_styles: {
        title: { font_family: 'sans', font_size: 24, color: '#0f2744', font_bold: true, font_italic: false, font_underline: false, margin_top: null, margin_bottom: null },
        subtitle_1: { font_family: 'sans', font_size: 18, color: '#0f2744', font_bold: true, font_italic: false, font_underline: false, margin_top: null, margin_bottom: null },
        subtitle_2: { font_family: 'sans', font_size: 16, color: '#0f2744', font_bold: true, font_italic: false, font_underline: false, margin_top: null, margin_bottom: null },
        subtitle_3: { font_family: 'sans', font_size: 14, color: '#0f2744', font_bold: false, font_italic: false, font_underline: false, margin_top: null, margin_bottom: null },
        subtitle_4: { font_family: 'sans', font_size: 12, color: '#334155', font_bold: false, font_italic: false, font_underline: false, margin_top: null, margin_bottom: null },
        regulatory_reference: { font_family: 'mono', font_size: 10, color: '#1e3a8a', font_bold: false, font_italic: false, font_underline: false, margin_top: null, margin_bottom: null },
        body: { font_family: 'serif', font_size: 11, color: '#0f172a', font_bold: false, font_italic: false, font_underline: false, margin_top: null, margin_bottom: null },
        caption: { font_family: 'sans', font_size: 9, color: '#64748b', font_bold: false, font_italic: false, font_underline: false, margin_top: null, margin_bottom: null },
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
    if (state.isCoverSection || (isReleased && !isAnnexBook)) {
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
    if (state.coverSaveTimer) {
      clearTimeout(state.coverSaveTimer);
      state.coverSaveTimer = null;
    }
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
    apiUpload(fd)
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
        if (state.coverSaveTimer) flushCoverSave();
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

  function extractTableColumnWidths(table) {
    if (!table) return [];
    var cols = Array.prototype.slice.call(table.querySelectorAll('colgroup col'));
    if (!cols.length) return [];
    var allPercent = cols.every(function (col) {
      return /%$/.test(col.style.width || '');
    });
    var widths = cols.map(function (col) {
      var raw = parseFloat(col.style.width || '');
      if (allPercent && !isNaN(raw)) return raw;
      return col.getBoundingClientRect().width;
    });
    if (!allPercent) {
      var total = widths.reduce(function (sum, width) { return sum + width; }, 0);
      if (total > 0) {
        widths = widths.map(function (width) { return (width / total) * 100; });
      }
    }
    return widths.map(function (width) { return Math.round(width * 1000) / 1000; });
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
    var effectiveParts = [];
    sheet.querySelectorAll('[data-lep-part-row]').forEach(function (rowEl, rowIndex) {
      var row = {};
      rowEl.querySelectorAll('[data-lep-part-col]').forEach(function (cell) {
        row[cell.getAttribute('data-lep-part-col')] = cell.textContent.replace(/\u00a0/g, ' ').trim();
      });
      if (!Object.keys(row).some(function (key) { return row[key]; })) return;
      var existing = Array.isArray(lep.effective_parts) ? lep.effective_parts[rowIndex] : null;
      row.label = rowEl.getAttribute('data-lep-label')
        || (existing && existing.label ? existing.label : '');
      row.section_id = parseInt(rowEl.getAttribute('data-lep-section-id') || '0', 10)
        || (existing && existing.section_id ? existing.section_id : 0);
      effectiveParts.push(row);
    });
    lep.effective_parts = effectiveParts;
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
    if (state.lepSaveTimer) {
      clearTimeout(state.lepSaveTimer);
      state.lepSaveTimer = null;
    }
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
    return labels[state.part0SectionKey] || 'PART 0';
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
        column_widths: extractTableColumnWidths(
          sheet.querySelector('[data-part0-table="amendment_list"]')
        ),
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
      return {
        rows: distRows,
        empty_rows: emptyRows,
        column_widths: extractTableColumnWidths(
          sheet.querySelector('[data-part0-table="distribution_list"]')
        ),
      };
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
    if (state.part0SaveTimer) {
      clearTimeout(state.part0SaveTimer);
      state.part0SaveTimer = null;
    }
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
    if (state.part0SectionKey === 'amendment_list' || state.part0SectionKey === 'distribution_list') {
      ensureStructuredTableEditor(
        sheet.querySelector('.cpb-part0-amendment, .cpb-part0-distribution')
      );
    }

    sheet.querySelectorAll('[data-part0-field], [data-part0-col]').forEach(function (field) {
      if (!state.editable || field.getAttribute('contenteditable') !== 'true') return;
      if (field.getAttribute('data-part0-field-wired') === '1') return;
      field.setAttribute('data-part0-field-wired', '1');
      field.addEventListener('input', schedulePart0Save);
      field.addEventListener('blur', function () {
        if (state.part0SaveTimer) flushPart0Save();
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
    ensureStructuredTableEditor(sheet.querySelector('.cpb-lep-parts-wrap'));

    sheet.querySelectorAll('[data-lep-field], [data-lep-part-col]').forEach(function (field) {
      if (!state.editable || field.getAttribute('data-lep-field-wired') === '1') return;
      field.setAttribute('data-lep-field-wired', '1');
      field.addEventListener('input', scheduleLepSave);
      field.addEventListener('blur', function () {
        if (state.lepSaveTimer) flushLepSave();
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
      { token: '{annex_number}', label: 'Annex number', description: 'Annex number (e.g. 01, 02a)' },
      { token: '{annex_title}', label: 'Annex title', description: 'Annex title without prefix' },
      { token: '{annex_revision}', label: 'Annex revision', description: 'Annex revision label' },
      { token: '{annex_revision_date}', label: 'Annex revision date', description: 'Annex revision date' },
    ];
  }

  function resolveHeaderTokensPreview(template, pageNumber, pageTotal) {
    var text = String(template || '');
    var ctx = state.headerPreviewTokens;
    if (ctx && typeof ctx === 'object' && Object.keys(ctx).length) {
      Object.keys(ctx).forEach(function (key) {
        var value = ctx[key];
        if (key === 'page' && pageNumber != null) value = pageNumber;
        if (key === 'page_total' && pageTotal != null) value = pageTotal;
        text = text.split('{' + key + '}').join(String(value != null ? value : ''));
      });
      if (pageNumber != null) text = text.split('{page}').join(String(pageNumber));
      if (pageTotal != null) text = text.split('{page_total}').join(String(pageTotal));
      return text;
    }

    var v = state.versionInfo || {};
    var manualCode = v.manual_code || v.book_key || '';
    var map = {
      '{page}': pageNumber != null ? String(pageNumber) : '—',
      '{page_total}': pageTotal != null ? String(pageTotal) : '—',
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

  function previewHeaderHtml(header, footer, pageNumber, pageTotal) {
    var h = header || defaultPageHeader();
    var f = footer || defaultPageFooter();
    var logoHeight = parseInt(h.logo_max_height, 10) || 40;
    var headerRow = parseInt(h.row_height, 10) || 32;
    var footerRow = parseInt(f.row_height, 10) || 26;
    var logo = h.logo_url
      ? '<img class="cpb-page-header-logo" src="' + escapeHtml(h.logo_url) + '" alt="' + escapeHtml(h.logo_alt || '') + '" style="max-height:' + logoHeight + 'px;">'
      : '<span class="cpb-page-header-logo-placeholder">Logo</span>';
    var center = escapeHtml(resolveHeaderTokensPreview(h.center_text, pageNumber, pageTotal)).replace(/\n/g, '<br>');
    var right = escapeHtml(resolveHeaderTokensPreview(h.right_text, pageNumber, pageTotal)).replace(/\n/g, '<br>');
    var footerLeft = escapeHtml(resolveHeaderTokensPreview(f.left_text, pageNumber, pageTotal)).replace(/\n/g, '<br>');
    var footerCenter = escapeHtml(resolveHeaderTokensPreview(f.center_text, pageNumber, pageTotal)).replace(/\n/g, '<br>');
    var footerRight = escapeHtml(resolveHeaderTokensPreview(f.right_text, pageNumber, pageTotal)).replace(/\n/g, '<br>');
    return (h.enabled ? '<header class="cpb-page-header">'
      + '<table class="cpb-page-header-table" role="presentation"><tr>'
      + '<td class="cpb-page-header-cell cpb-page-header-cell--left"' + headerRowCellStyleAttr(headerRow) + '>' + logo + '</td>'
      + '<td class="cpb-page-header-cell cpb-page-header-cell--center ' + headerFontClass(h.center_font_family) + '"' + headerCellStyleAttr(headerColumnFromBand(h, 'center'), headerRow) + '>' + center + '</td>'
      + '<td class="cpb-page-header-cell cpb-page-header-cell--right ' + headerFontClass(h.right_font_family) + '"' + headerCellStyleAttr(headerColumnFromBand(h, 'right'), headerRow) + '>' + right + '</td>'
      + '</tr></table></header>' : '')
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

  function mergedTableStyle(base, override) {
    var merged = JSON.parse(JSON.stringify(base || defaultTableStyleDef()));
    override = override && typeof override === 'object' ? override : {};
    ['border_width', 'border_color', 'cell_bg'].forEach(function (field) {
      if (override[field] !== undefined) merged[field] = override[field];
    });
    ['title_row', 'header_row', 'body_row'].forEach(function (rowKey) {
      merged[rowKey] = Object.assign({}, merged[rowKey] || {}, override[rowKey] || {});
    });
    return merged;
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
      margin_top: def.margin_top !== null && def.margin_top !== '' && Number.isFinite(Number(def.margin_top))
        ? Number(def.margin_top) : null,
      margin_bottom: def.margin_bottom !== null && def.margin_bottom !== '' && Number.isFinite(Number(def.margin_bottom))
        ? Number(def.margin_bottom) : null,
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
    el.style.marginTop = def.margin_top === null ? '' : def.margin_top + 'px';
    el.style.marginBottom = def.margin_bottom === null ? '' : def.margin_bottom + 'px';
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
    else if (blockType === 'list') {
      el = blockEl.querySelector('.cpb-list') || blockEl.querySelector('.cpb-list-continuation');
    }
    if (!el) return {};
    el.style.marginLeft = '';
    el.setAttribute('data-indent-level', '0');
    var styleKey = canonicalParagraphStyleKey(el.getAttribute('data-paragraph-style') || 'body') || 'body';
    var fields = {
      paragraph_style: styleKey,
      font_family: el.getAttribute('data-font-family') || 'serif',
      text_align: el.getAttribute('data-text-align') || 'left',
      font_size: parseInt(el.getAttribute('data-font-size') || '11', 10) || 11,
      text_color: el.getAttribute('data-text-color') || '#0f172a',
      indent_level: 0,
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
    if (blockType === 'paragraph') {
      var crossRef = readCrossRefFromRow(blockEl.querySelector('.cpb-paragraph-row'));
      if (crossRef.key) {
        out.cross_ref_key = crossRef.key;
        if (crossRef.document) out.cross_ref_document = crossRef.document;
      }
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

  function styleNeedsTocRefresh(styleKey) {
    return styleKey === 'title'
      || styleKey === 'subtitle_1'
      || styleKey === 'subtitle_2'
      || styleKey === 'subtitle_3'
      || styleKey === 'subtitle_4';
  }

  function blockNeedsTocRefresh(blockEl) {
    if (!blockEl) return false;
    var el = blockEl.querySelector('.cpb-paragraph, .cpb-heading');
    if (!el) return false;
    return styleNeedsTocRefresh(canonicalParagraphStyleKey(el.getAttribute('data-paragraph-style') || 'body'));
  }

  function scheduleTocSync() {
    if (!state.editable) return;
    clearTimeout(state.tocSyncTimer);
    state.tocSyncTimer = setTimeout(function () {
      var req = {
        version_id: state.versionId,
        toc_settings: state.tocSettings || defaultTocSettings(),
      };
      if (state.isTocSection) {
        req.section_id = state.sectionId;
      }
      apiPost('regenerate_toc', req).then(function (res) {
        if (!res.ok) return;
        state.tocSettings = res.toc_settings || state.tocSettings;
        state.tocSettingsCatalog = res.toc_settings_catalog || state.tocSettingsCatalog;
        if (state.isTocSection && res.page_html) {
          canvasEl.innerHTML = res.page_html;
          wireCanvas();
          updateTocToolbarCheckboxes();
          refreshTocTypographyFromBookStyles();
        }
      }).catch(function () { /* background TOC sync — ignore */ });
    }, 900);
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
      scheduleTocSync();
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
    tmp.querySelectorAll(
      '.cpb-section-number, .cpb-regulatory-ref, .cpb-col-resize, [data-editor-only="1"]'
    ).forEach(function (el) {
      el.remove();
    });
    return tmp.innerHTML;
  }

  function normalizeListItemsFromElement(list) {
    var items = [];
    if (!list) return items;
    var lis = list.querySelectorAll(':scope > li');
    lis.forEach(function (li) {
      var text = li.textContent.replace(/\u00a0/g, ' ').trim();
      if (text) items.push(stripEditorChromeFromHtml(li.innerHTML));
    });
    return items;
  }

  function normalizeListIndentLevelsFromElement(list) {
    var levels = [];
    if (!list) return levels;
    list.querySelectorAll(':scope > li').forEach(function (li) {
      var text = li.textContent.replace(/\u00a0/g, ' ').trim();
      if (!text) return;
      levels.push(Math.max(0, Math.min(
        INDENT_MAX_LEVEL,
        parseInt(li.getAttribute('data-indent-level') || '0', 10) || 0
      )));
    });
    return levels;
  }

  function activeDirectListItem(list) {
    var sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) return null;
    var node = sel.getRangeAt(0).startContainer;
    var el = node.nodeType === 1 ? node : node.parentElement;
    var item = el && el.closest ? el.closest('li') : null;
    return item && item.parentElement === list ? item : null;
  }

  function focusStartOfElement(el) {
    var sel = window.getSelection();
    if (!sel) return;
    var range = document.createRange();
    range.selectNodeContents(el);
    range.collapse(true);
    sel.removeAllRanges();
    sel.addRange(range);
  }

  function exitListWithinBlock(list, blockEl, item, moveTrailingContent) {
    var continuation = blockEl.querySelector('.cpb-list-continuation');
    if (!continuation) {
      continuation = document.createElement('div');
      continuation.className = 'cpb-list-continuation';
      continuation.contentEditable = 'true';
      continuation.setAttribute('data-field', 'continuation_html');
      continuation.setAttribute('spellcheck', 'true');
      list.insertAdjacentElement('afterend', continuation);
    }

    var trailingItems = [];
    var nextItem = item.nextElementSibling;
    while (nextItem) {
      var following = nextItem.nextElementSibling;
      if (nextItem.tagName === 'LI') trailingItems.push(nextItem);
      nextItem = following;
    }

    if (moveTrailingContent) {
      var sel = window.getSelection();
      if (sel && sel.rangeCount > 0) {
        var cursorRange = sel.getRangeAt(0);
        if (item.contains(cursorRange.startContainer)) {
          var trailingRange = document.createRange();
          trailingRange.selectNodeContents(item);
          trailingRange.setStart(cursorRange.startContainer, cursorRange.startOffset);
          var trailing = trailingRange.extractContents();
          continuation.appendChild(trailing);
        }
      }
    }

    if (item.textContent.replace(/\u00a0/g, ' ').trim() === '') {
      item.remove();
    }

    if (trailingItems.length) {
      var nextList = list.cloneNode(false);
      nextList.removeAttribute('start');
      nextList.removeAttribute('data-input-wired');
      continuation.insertAdjacentElement('afterend', nextList);
      trailingItems.forEach(function (trailingItem) {
        nextList.appendChild(trailingItem);
      });
      if (nextList.tagName === 'OL') {
        var firstOrderedList = blockEl.querySelector('ol.cpb-list');
        var baseStart = firstOrderedList
          ? Math.max(1, parseInt(firstOrderedList.getAttribute('start') || '1', 10) || 1)
          : 1;
        var priorItems = 0;
        blockEl.querySelectorAll('.cpb-list').forEach(function (segment) {
          if (segment === nextList) return;
          if (segment.compareDocumentPosition(nextList) & Node.DOCUMENT_POSITION_FOLLOWING) {
            priorItems += segment.querySelectorAll(':scope > li').length;
          }
        });
        nextList.setAttribute('start', String(baseStart + priorItems));
      }
    }

    if (!continuation.hasChildNodes()) continuation.appendChild(document.createElement('br'));
    wireCanvas();
    continuation.focus();
    focusStartOfElement(continuation);
    scheduleSave(blockEl);
    flushSave(blockEl);
  }

  function handleListKeyDown(e, list, blockEl) {
    if (e.key !== 'Enter' || e.isComposing) return;
    var item = activeDirectListItem(list);
    if (!item) return;

    if (e.shiftKey) {
      e.preventDefault();
      pushUndo();
      exitListWithinBlock(list, blockEl, item, true);
      return;
    }

    if (item.textContent.replace(/\u00a0/g, ' ').trim() !== '') {
      // Leave non-empty item splitting and number increments to contenteditable.
      return;
    }
    e.preventDefault();
    pushUndo();
    exitListWithinBlock(list, blockEl, item, false);
  }

  function setListStartSelectorValue(value) {
    if (!listStartInput) return;
    var normalized = String(Math.max(1, parseInt(value, 10) || 1));
    if (!listStartInput.querySelector('option[value="' + normalized + '"]')) {
      var option = document.createElement('option');
      option.value = normalized;
      option.textContent = normalized;
      listStartInput.appendChild(option);
    }
    listStartInput.value = normalized;
  }

  function applyOrderedListStart(value) {
    var orderedList = isConnectedEl(state.activeOrderedList)
      ? state.activeOrderedList
      : orderedListAtSelection(getActiveStyleTarget());
    if (!orderedList) return;
    var block = orderedList.closest('.cpb-block');
    if (!block) return;
    var startNumber = Math.max(1, parseInt(value, 10) || 1);
    var segments = orderedList.classList.contains('cpb-list')
      ? block.querySelectorAll('ol.cpb-list')
      : [orderedList];
    if (!segments.length) return;
    pushUndo();
    var precedingItems = 0;
    segments.forEach(function (segment) {
      segment.setAttribute('start', String(startNumber + precedingItems));
      precedingItems += segment.querySelectorAll(':scope > li').length;
    });
    setListStartSelectorValue(startNumber);
    scheduleSave(block);
    flushSave(block);
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
      var lists = Array.prototype.slice.call(blockEl.querySelectorAll('.cpb-list'));
      var list = lists[0] || null;
      var ordered = list && list.tagName === 'OL';
      var startNumber = ordered
        ? Math.max(1, parseInt(list.getAttribute('start') || '1', 10) || 1)
        : 1;
      var items = [];
      var itemIndentLevels = [];
      lists.forEach(function (segment) {
        items = items.concat(normalizeListItemsFromElement(segment));
        itemIndentLevels = itemIndentLevels.concat(normalizeListIndentLevelsFromElement(segment));
      });
      var continuation = blockEl.querySelector('.cpb-list-continuation');
      var continuationAfter = items.length;
      if (continuation) {
        continuationAfter = 0;
        lists.forEach(function (segment) {
          if (segment.compareDocumentPosition(continuation) & Node.DOCUMENT_POSITION_FOLLOWING) {
            continuationAfter += normalizeListItemsFromElement(segment).length;
          }
        });
      }
      return Object.assign({
        ordered: ordered,
        start_number: startNumber,
        items: items,
        item_indent_levels: itemIndentLevels,
        continuation_html: continuation
          ? stripEditorChromeFromHtml(continuation.innerHTML)
          : '',
        continuation_after: continuationAfter,
      }, extractStyleFields(blockEl, 'list'));
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
    if (['field', 'checkbox', 'date', 'signature', 'initial'].indexOf(blockType) >= 0) {
      var fieldRoot = blockEl.querySelector('[data-form-field="1"]');
      return {
        field_key: fieldRoot ? (fieldRoot.getAttribute('data-field-key') || '') : '',
        field_type: fieldRoot ? (fieldRoot.getAttribute('data-field-type') || blockType) : blockType,
        label: fieldRoot ? (fieldRoot.getAttribute('data-label') || '') : '',
        required: fieldRoot ? fieldRoot.getAttribute('data-required') === '1' : false,
        assigned_role: fieldRoot ? (fieldRoot.getAttribute('data-assigned-role') || 'instructor') : 'instructor',
        variable_key: fieldRoot ? (fieldRoot.getAttribute('data-variable-key') || '') : '',
        placeholder: fieldRoot ? (fieldRoot.getAttribute('data-placeholder') || '') : '',
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

  function ensureStructuredTableEditor(container) {
    if (!container || container.getAttribute('data-structured-table-editor') === '1') return;
    container.setAttribute('data-structured-table-editor', '1');
  }

  function wireStructuredTableCell(cell, isLep) {
    if (!cell || cell.getAttribute('data-structured-cell-wired') === '1') return;
    cell.setAttribute('data-structured-cell-wired', '1');
    cell.addEventListener('input', isLep ? scheduleLepSave : schedulePart0Save);
    cell.addEventListener('blur', function () {
      if (isLep) {
        if (state.lepSaveTimer) flushLepSave();
      } else {
        if (state.part0SaveTimer) flushPart0Save();
      }
    });
  }

  function structuredTableAddRow(container) {
    var tbody = tableBody(container);
    var sourceRows = tableSourceRows(tbody);
    var template = sourceRows.length ? sourceRows[sourceRows.length - 1] : null;
    if (!tbody || !template) return false;
    var row = template.cloneNode(true);
    row.classList.add('cpb-part0-row--empty');
    row.removeAttribute('data-lep-label');
    row.removeAttribute('data-lep-section-id');
    row.querySelectorAll('td, th').forEach(function (cell) {
      cell.innerHTML = '&nbsp;';
      cell.classList.remove('is-cell-selected');
      cell.removeAttribute('data-structured-cell-wired');
      wireStructuredTableCell(cell, !!state.isLepSection);
    });
    tbody.appendChild(row);
    var firstCell = row.querySelector('td, th');
    if (firstCell) {
      clearTableCellSelection();
      addTableCellToSelection(firstCell);
      state.focusedTableCell = firstCell;
      state.lastStyleTarget = { block: container, el: firstCell, type: 'table-cell' };
      firstCell.focus();
    }
    return true;
  }

  function saveStructuredTable(container) {
    if (!container) return;
    if (state.isLepSection) {
      flushLepSave();
    } else {
      flushPart0Save();
    }
  }

  function handleStructuredTableAction(container, action) {
    if (!container || !action) return;
    if (action === 'structured-appearance') {
      closeTableTools();
      openStyleEditor();
      return;
    }
    pushUndo();
    var changed = false;
    if (action === 'add-row') changed = structuredTableAddRow(container);
    else if (action === 'del-row') changed = tableDelRow(container);
    else if (action === 'move-row-up') changed = tableMoveRow(container, 'up');
    else if (action === 'move-row-down') changed = tableMoveRow(container, 'down');
    if (!changed) return;
    syncTableToolsContext(container);
    positionTableTools(container, state.activeTableToolsAnchor);
    saveStructuredTable(container);
  }

  function closeTableTools() {
    state.activeTableToolsBlock = null;
    state.activeTableToolsAnchor = null;
    syncTableToolsContext(null);
  }

  function openTableTools(blockEl, anchorEl) {
    if (!state.editable || !blockEl || !isConnectedEl(blockEl)) return;
    if (state.activeTableToolsBlock && state.activeTableToolsBlock !== blockEl) {
      closeTableTools();
    }
    state.activeTableToolsBlock = blockEl;
    state.activeTableToolsAnchor = anchorEl && blockEl.contains(anchorEl)
      ? anchorEl
      : blockEl.querySelector('.cpb-table-wrap');
    syncTableToolsContext(blockEl);
  }

  function setTableToolDisabled(blockEl, actions, disabled) {
    if (!tableToolbarEl) return;
    actions.forEach(function (action) {
      var control = tableToolbarEl.querySelector('[data-table-action="' + action + '"]');
      if (control) control.disabled = !!disabled;
    });
  }

  function syncTableToolsContext(blockEl) {
    if (!tableToolbarEl) return;
    var active = !!(blockEl && isConnectedEl(blockEl));
    tableToolbarEl.querySelectorAll('[data-table-action]').forEach(function (control) {
      control.disabled = !active;
      control.classList.remove('is-active');
    });
    if (!active) return;
    var structured = blockEl.getAttribute('data-structured-table-editor') === '1';
    var selected = state.selectedTableCells.filter(function (cell) {
      return blockEl.contains(cell);
    });
    var selectedCell = resolveSelectedTableCell(blockEl);

    var selectedRow = selectedCell ? selectedCell.closest('tr') : null;
    var body = tableBody(blockEl);
    var isBodyRow = !!(selectedRow && body && body.contains(selectedRow));
    var bodyRows = tableSourceRows(body);
    var bodyRowIndex = isBodyRow ? bodyRows.indexOf(selectedRow) : -1;
    var hasVerticalMerge = !!blockEl.querySelector(
      'tbody[data-table-part="body"] td[rowspan]:not([rowspan="1"]),'
      + 'tbody[data-table-part="body"] td[data-rowspan-covered="1"]'
    );
    setTableToolDisabled(
      blockEl,
      ['move-row-up'],
      hasVerticalMerge || !isBodyRow || bodyRowIndex <= 0
    );
    setTableToolDisabled(
      blockEl,
      ['move-row-down'],
      hasVerticalMerge || !isBodyRow || bodyRowIndex < 0 || bodyRowIndex >= bodyRows.length - 1
    );
    var canDeleteSelectedRow = !!selectedRow
      && (!isBodyRow || bodyRows.length > 1);
    setTableToolDisabled(blockEl, ['del-row'], hasVerticalMerge || !canDeleteSelectedRow);
    setTableToolDisabled(blockEl, ['del-col'], tableColCount(blockEl) <= 1);
    setTableToolDisabled(blockEl, ['paste-table'], !state.tableBlockClipboard);
    var titleButton = tableToolbarEl.querySelector('[data-table-action="toggle-title"]');
    if (titleButton) {
      titleButton.textContent = blockEl.querySelector('tr[data-title-row]')
        ? 'Remove title'
        : '+ Title';
    }

    var selectedCount = selected.length > 1 ? selected.length : (selectedCell ? 1 : 0);
    var isTitleCell = !!(selectedCell && selectedCell.closest('[data-title-row]'));
    var nextCell = selectedCell ? selectedCell.nextElementSibling : null;
    setTableToolDisabled(
      blockEl,
      ['merge-cells-right'],
      selectedCount !== 1 || isTitleCell || !nextCell
    );
    var colspan = selectedCell
      ? Math.max(1, parseInt(selectedCell.getAttribute('colspan') || '1', 10) || 1)
      : 1;
    setTableToolDisabled(blockEl, ['unmerge-cells'], !selectedCell || colspan <= 1);
    var rowspan = selectedCell
      ? Math.max(1, parseInt(selectedCell.getAttribute('rowspan') || '1', 10) || 1)
      : 1;
    setTableToolDisabled(
      blockEl,
      ['merge-cells-down'],
      !selectedCell || !canMergeCellDown(blockEl, selectedCell)
    );
    setTableToolDisabled(blockEl, ['unmerge-cells-down'], !selectedCell || rowspan <= 1);
    setTableToolDisabled(
      blockEl,
      [
        'cell-bg',
        'cell-bg-clear',
        'copy-cells',
        'paste-cells',
      ],
      selectedCount === 0
    );
    setTableToolDisabled(
      blockEl,
      ['formula-sum', 'formula-avg', 'formula-custom'],
      !selectedCell || !isBodyRow
    );
    if (structured) {
      setTableToolDisabled(blockEl, [
        'table-align-left', 'table-align-center', 'table-align-right',
        'toggle-title', 'delete-table', 'copy-table', 'paste-table',
        'add-col', 'del-col', 'merge-cells-right', 'unmerge-cells',
        'merge-cells-down', 'unmerge-cells-down',
        'cell-bg', 'cell-bg-clear', 'copy-cells', 'paste-cells',
        'border-thin', 'border-medium', 'border-thick', 'border-color',
        'formula-sum', 'formula-avg', 'formula-custom',
      ], true);
    }
    syncTableStyleControls(blockEl);
  }

  function positionTableTools(blockEl, anchorEl) {
    if (blockEl && state.activeTableToolsBlock === blockEl) syncTableToolsContext(blockEl);
  }

  function syncTableStyleControls(blockEl) {
    var wrap = tableWrap(blockEl);
    if (!wrap) return;
    var width = normalizeBorderWidth(wrap.getAttribute('data-border-width') || 'medium');
    var color = wrap.getAttribute('data-border-color') || '#94a3b8';
    if (!tableToolbarEl || state.activeTableToolsBlock !== blockEl) return;
    tableToolbarEl.querySelectorAll('[data-table-action^="border-"]').forEach(function (btn) {
      var action = btn.getAttribute('data-table-action');
      if (action === 'border-thin' || action === 'border-medium' || action === 'border-thick') {
        btn.classList.toggle('is-active', action === 'border-' + width);
      }
    });
    var borderColorInput = tableToolbarEl.querySelector('[data-table-action="border-color"]');
    if (borderColorInput) borderColorInput.value = color;
    var tableBlock = blockEl.querySelector('.cpb-table-block');
    var tableAlign = tableBlock ? (tableBlock.getAttribute('data-table-align') || 'left') : 'left';
    tableToolbarEl.querySelectorAll('[data-table-action^="table-align-"]').forEach(function (btn) {
      btn.classList.toggle('is-active', btn.getAttribute('data-table-action') === 'table-align-' + tableAlign);
    });
  }

  function wireTableCellFocus(blockEl) {
    blockEl.querySelectorAll('.cpb-table th, .cpb-table td').forEach(function (cell) {
      if (cell.getAttribute('data-cell-focus-wired') === '1') return;
      cell.setAttribute('data-cell-focus-wired', '1');
      cell.addEventListener('beforeinput', function () {
        if (state.tableCellUndoLock) return;
        pushUndo();
        state.tableCellUndoLock = true;
        setTimeout(function () { state.tableCellUndoLock = false; }, 0);
      });
      cell.addEventListener('focus', function () {
        state.focusedTableCell = cell;
        rememberStyleTarget();
        openTableTools(blockEl, cell);
        var bgInput = tableToolbarEl
          ? tableToolbarEl.querySelector('[data-table-action="cell-bg"]')
          : null;
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
        scheduleSave(blockEl);
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
    var html = '';
    if (cell.tagName === 'TH') {
      var textEl = cell.querySelector('.cpb-th-text');
      html = stripEditorChromeFromHtml(textEl ? textEl.innerHTML : cell.innerHTML);
    } else {
      html = stripEditorChromeFromHtml(cell.innerHTML);
    }
    var tmp = document.createElement('div');
    tmp.innerHTML = html;
    if (!tmp.querySelector('a, span, b, strong, i, em, u, br, p, ul, ol, li, font')) {
      return tmp.textContent;
    }
    return html;
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
    normalizeTableTitleRow(blockEl);
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
    var rowRowspans = [];
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
      tableSourceRows(table.querySelector('tbody[data-table-part="body"]')).forEach(function (tr) {
        var line = [];
        var bgLine = [];
        var alignLine = [];
        var fontLine = [];
        var sizeLine = [];
        var colorLine = [];
        var spanLine = [];
        var rowSpanLine = [];
        tr.querySelectorAll('td').forEach(function (td) {
          line.push(extractCellHtml(td));
          spanLine.push(parseInt(td.getAttribute('colspan') || '1', 10) || 1);
          rowSpanLine.push(
            td.getAttribute('data-rowspan-covered') === '1'
              ? 0
              : (parseInt(td.getAttribute('rowspan') || '1', 10) || 1)
          );
          bgLine.push(extractCellBg(td));
          alignLine.push(extractCellAlign(td));
          fontLine.push(extractCellFontFamily(td));
          sizeLine.push(extractCellFontSize(td));
          colorLine.push(extractCellTextColor(td));
        });
        if (line.length) {
          rows.push(line);
          rowColspans.push(spanLine);
          rowRowspans.push(rowSpanLine);
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

    var extracted = {
      title: title,
      has_title_row: !!blockEl.querySelector('tr[data-title-row]'),
      has_header_row: !!tableHeaderRow(blockEl),
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
    var hasVerticalMerge = rowRowspans.some(function (spans) {
      return spans.some(function (span) { return span !== 1; });
    });
    if (hasVerticalMerge) extracted.row_rowspans = rowRowspans;
    return extracted;
  }

  function copyEntireTable(blockEl) {
    if (!blockEl || blockEl.getAttribute('data-block-type') !== 'table') {
      setStatus('Select an editable table first', 'error');
      return;
    }
    state.tableBlockClipboard = JSON.parse(JSON.stringify(extractTablePayload(blockEl)));
    var table = blockEl.querySelector('table');
    var plainText = table ? table.innerText.trim() : '';
    if (navigator.clipboard && navigator.clipboard.writeText && plainText) {
      navigator.clipboard.writeText(plainText).catch(function () {});
    }
    setStatus('Entire table copied', 'saved');
    syncTableToolsContext(blockEl);
  }

  function pasteEntireTable(afterBlock) {
    if (!state.tableBlockClipboard) {
      setStatus('Copy a table first', 'error');
      return;
    }
    var payload = JSON.parse(JSON.stringify(state.tableBlockClipboard));
    setStatus('Pasting table…', 'saving');
    flushAllPendingSaves().then(function () {
      return createBlock('table', payload, afterBlock);
    }).then(function () {
      setStatus('Entire table pasted', 'saved');
    }).catch(showError);
  }

  function tableBody(blockEl) {
    var table = blockEl.querySelector('table');
    return table ? table.querySelector('tbody[data-table-part="body"]') : null;
  }

  function tableSourceRows(tbody) {
    if (!tbody) return [];
    return Array.prototype.slice.call(tbody.rows).filter(function (row) {
      return row.getAttribute('data-auto-page-break') !== '1';
    });
  }

  function tableHeaderRow(blockEl) {
    var table = blockEl.querySelector('table');
    return table ? table.querySelector('thead tr.cpb-table-header-row') : null;
  }

  function tableColCount(blockEl) {
    var table = blockEl.querySelector('table');
    if (!table) return 2;
    var cols = table.querySelectorAll('colgroup col');
    if (cols.length) return cols.length;
    var head = tableHeaderRow(blockEl);
    if (head) {
      return Array.prototype.reduce.call(head.cells, function (total, cell) {
        return total + (parseInt(cell.getAttribute('colspan') || '1', 10) || 1);
      }, 0);
    }
    var bodyRow = table.querySelector('tbody[data-table-part="body"] tr');
    if (bodyRow && bodyRow.cells.length) {
      return Array.prototype.reduce.call(bodyRow.cells, function (total, cell) {
        return total + (parseInt(cell.getAttribute('colspan') || '1', 10) || 1);
      }, 0);
    }
    return 2;
  }

  function normalizeTableTitleRow(blockEl) {
    var table = blockEl ? blockEl.querySelector('table') : null;
    var thead = table ? table.querySelector('thead') : null;
    if (!table || !thead) return null;
    var titleRows = Array.prototype.slice.call(
      thead.querySelectorAll('tr[data-title-row], tr.cpb-table-title-row')
    );
    if (!titleRows.length) return null;

    var titleRow = titleRows.shift();
    titleRows.forEach(function (duplicateRow) {
      Array.prototype.slice.call(duplicateRow.cells).forEach(function (cell) {
        if (!cell.textContent.trim()) return;
        var target = titleRow.cells[0];
        if (!target) {
          target = document.createElement('td');
          titleRow.appendChild(target);
        }
        setCellHtml(target, mergeCellHtml(extractCellHtml(target), extractCellHtml(cell)));
      });
      duplicateRow.remove();
    });

    var cells = Array.prototype.slice.call(titleRow.cells);
    var titleCell = cells.shift();
    if (!titleCell) {
      titleCell = document.createElement('td');
      titleRow.appendChild(titleCell);
    } else if (titleCell.tagName !== 'TD') {
      var replacement = document.createElement('td');
      Array.prototype.slice.call(titleCell.attributes).forEach(function (attribute) {
        replacement.setAttribute(attribute.name, attribute.value);
      });
      replacement.innerHTML = titleCell.innerHTML;
      titleCell.replaceWith(replacement);
      titleCell = replacement;
    }
    cells.forEach(function (extraCell) {
      if (extraCell.textContent.trim()) {
        setCellHtml(titleCell, mergeCellHtml(extractCellHtml(titleCell), extractCellHtml(extraCell)));
      }
      extraCell.remove();
    });

    titleRow.setAttribute('data-title-row', '1');
    titleRow.classList.add('cpb-table-title-row');
    titleRow.classList.toggle('is-empty', titleCell.textContent.trim() === '');
    titleCell.colSpan = tableColCount(blockEl);
    return titleCell;
  }

  function removeLogicalColumnFromRow(row, logicalIndex) {
    if (!row || logicalIndex < 0) return;
    var logicalStart = 0;
    for (var index = 0; index < row.cells.length; index++) {
      var cell = row.cells[index];
      var span = Math.max(1, parseInt(cell.getAttribute('colspan') || '1', 10) || 1);
      if (logicalIndex < logicalStart + span) {
        if (span > 1) cell.colSpan = span - 1;
        else cell.remove();
        return;
      }
      logicalStart += span;
    }
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
    var row = tableSourceRows(tbody).indexOf(tr);
    return { row: row, col: cell.cellIndex, ref: colLetter(cell.cellIndex) + String(row + 1) };
  }

  function selectedTableCellsInBlock(blockEl) {
    return state.selectedTableCells.filter(function (cell) {
      return blockEl && cell && blockEl.contains(cell);
    });
  }

  function tableCellPlainText(cell) {
    return (cell && cell.textContent ? cell.textContent : '').replace(/\s+/g, ' ').trim();
  }

  function selectedTextInTableCell(cell) {
    var sel = window.getSelection();
    if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return null;
    if (!cell || !(cell.contains(sel.anchorNode) || cell.contains(sel.focusNode))) return null;
    return String(sel.toString() || '').trim();
  }

  function buildSelectedCellsCopy(blockEl, cells) {
    var grouped = {};
    cells.forEach(function (cell) {
      var coords = getTableCellCoords(blockEl, cell);
      if (!coords) return;
      var rowKey = String(coords.row);
      if (!grouped[rowKey]) grouped[rowKey] = [];
      grouped[rowKey].push({ col: coords.col, cell: cell });
    });
    var rowKeys = Object.keys(grouped).sort(function (a, b) {
      return parseInt(a, 10) - parseInt(b, 10);
    });
    var lines = [];
    var styles = [];
    rowKeys.forEach(function (rowKey) {
      grouped[rowKey].sort(function (a, b) { return a.col - b.col; });
      lines.push(grouped[rowKey].map(function (item) {
        return tableCellPlainText(item.cell);
      }).join('\t'));
      styles.push(grouped[rowKey].map(function (item) {
        return cellStyleSnapshot(item.cell);
      }));
    });
    return { text: lines.join('\n'), styles: styles };
  }

  function buildCopyText(blockEl, anchorCell) {
    var selected = selectedTableCellsInBlock(blockEl);
    if (selected.length > 1) {
      return buildSelectedCellsCopy(blockEl, selected).text;
    }
    var cell = selected[0] || anchorCell;
    var highlighted = selectedTextInTableCell(cell);
    if (highlighted) return highlighted;
    return tableCellPlainText(cell);
  }

  function cellStyleSnapshot(cell) {
    return {
      bg: extractCellBg(cell),
      align: extractCellAlign(cell),
      font_family: extractCellFontFamily(cell),
      font_size: extractCellFontSize(cell),
      text_color: extractCellTextColor(cell),
      colspan: parseInt(cell.getAttribute('colspan') || '1', 10) || 1,
      rowspan: parseInt(cell.getAttribute('rowspan') || '1', 10) || 1,
    };
  }

  function buildCopyStyles(blockEl, anchorCell) {
    var selected = selectedTableCellsInBlock(blockEl);
    if (selected.length > 1) {
      return buildSelectedCellsCopy(blockEl, selected).styles;
    }
    var cell = selected[0] || anchorCell;
    if (!cell) return [];
    return [[cellStyleSnapshot(cell)]];
  }

  function applyCellStyleSnapshot(cell, style) {
    if (!cell || !style) return;
    applyTableCellBg(null, cell, style.bg || '');
    if (style.align) applyStyleToTableCell(cell, { align: style.align });
    if (style.font_family) applyFontToTableCell(cell, style.font_family);
    if (style.font_size) {
      cell.setAttribute('data-font-size', String(style.font_size));
      cell.style.setProperty('font-size', style.font_size + 'pt', 'important');
    }
    if (style.text_color) applyColorToTableCell(cell, style.text_color);
    if (style.colspan && style.colspan > 1) cell.setAttribute('colspan', String(style.colspan));
    if (style.rowspan && style.rowspan > 1) cell.setAttribute('rowspan', String(style.rowspan));
  }

  function copyTableCells(blockEl) {
    var cell = resolveSelectedTableCell(blockEl) || state.focusedTableCell;
    if (!cell || !blockEl.contains(cell)) {
      setStatus('Click a table cell first', 'error');
      return;
    }
    var highlighted = selectedTableCellsInBlock(blockEl).length <= 1
      ? selectedTextInTableCell(cell)
      : null;
    var text = highlighted || buildCopyText(blockEl, cell);
    state.tableClipboard = text;
    state.tableClipboardStyles = highlighted ? [[cellStyleSnapshot(cell)]] : buildCopyStyles(blockEl, cell);
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
            if (state.tableClipboardStyles && state.tableClipboardStyles[rIndex] && state.tableClipboardStyles[rIndex][cIndex]) {
              applyCellStyleSnapshot(head.cells[col], state.tableClipboardStyles[rIndex][cIndex]);
            }
          }
        });
        return;
      }
      while (tbody && targetRow >= tableSourceRows(tbody).length) {
        tableAddRow(blockEl);
      }
      var tr = tbody ? tableSourceRows(tbody)[targetRow] : null;
      if (!tr) return;
      cells.forEach(function (val, cIndex) {
        var col = coords.col + cIndex;
        while (col >= tableColCount(blockEl)) tableAddColumn(blockEl);
        if (tr.cells[col]) {
          tr.cells[col].textContent = val;
          if (state.tableClipboardStyles && state.tableClipboardStyles[rIndex] && state.tableClipboardStyles[rIndex][cIndex]) {
            applyCellStyleSnapshot(tr.cells[col], state.tableClipboardStyles[rIndex][cIndex]);
          }
        }
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
    var rows = tableSourceRows(tbody);
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

  function tableCellLogicalStart(cell) {
    if (!cell || !cell.parentElement) return -1;
    var start = 0;
    for (var index = 0; index < cell.parentElement.cells.length; index++) {
      var candidate = cell.parentElement.cells[index];
      if (candidate === cell) return start;
      start += Math.max(1, parseInt(candidate.getAttribute('colspan') || '1', 10) || 1);
    }
    return -1;
  }

  function tableCellAtLogicalStart(row, logicalStart) {
    if (!row || logicalStart < 0) return null;
    var start = 0;
    for (var index = 0; index < row.cells.length; index++) {
      var candidate = row.cells[index];
      if (start === logicalStart) return candidate;
      start += Math.max(1, parseInt(candidate.getAttribute('colspan') || '1', 10) || 1);
    }
    return null;
  }

  function canMergeCellDown(blockEl, cell) {
    if (!cell || cell.tagName !== 'TD' || cell.getAttribute('data-rowspan-covered') === '1') {
      return false;
    }
    var tbody = tableBody(blockEl);
    var row = cell.parentElement;
    if (!tbody || !row || !tbody.contains(row)) return false;
    var rows = tableSourceRows(tbody);
    var rowIndex = rows.indexOf(row);
    var rowspan = Math.max(1, parseInt(cell.getAttribute('rowspan') || '1', 10) || 1);
    var targetRow = rows[rowIndex + rowspan];
    if (!targetRow) return false;
    var target = tableCellAtLogicalStart(targetRow, tableCellLogicalStart(cell));
    if (!target || target.getAttribute('data-rowspan-covered') === '1') return false;
    var colspan = Math.max(1, parseInt(cell.getAttribute('colspan') || '1', 10) || 1);
    var targetColspan = Math.max(1, parseInt(target.getAttribute('colspan') || '1', 10) || 1);
    return colspan === targetColspan;
  }

  function tableMergeCellDown(blockEl) {
    var cell = resolveSelectedTableCell(blockEl);
    if (!canMergeCellDown(blockEl, cell)) {
      setStatus('No matching cell below to merge', 'error');
      return false;
    }
    var tbody = tableBody(blockEl);
    var rows = tableSourceRows(tbody);
    var rowIndex = rows.indexOf(cell.parentElement);
    var rowspan = Math.max(1, parseInt(cell.getAttribute('rowspan') || '1', 10) || 1);
    var logicalStart = tableCellLogicalStart(cell);
    var target = tableCellAtLogicalStart(rows[rowIndex + rowspan], logicalStart);
    setCellHtml(cell, mergeCellHtml(extractCellHtml(cell), extractCellHtml(target)));
    cell.rowSpan = rowspan + 1;
    target.setAttribute('data-rowspan-covered', '1');
    target.setAttribute('contenteditable', 'false');
    target.hidden = true;
    target.style.display = 'none';
    setCellHtml(target, '');
    wireTableCellFocus(blockEl);
    setStatus('Cells merged vertically', 'saved');
    return true;
  }

  function tableUnmergeCellDown(blockEl) {
    var cell = resolveSelectedTableCell(blockEl);
    if (!cell || cell.tagName !== 'TD') {
      setStatus('Click a vertically merged cell first', 'error');
      return false;
    }
    var rowspan = Math.max(1, parseInt(cell.getAttribute('rowspan') || '1', 10) || 1);
    if (rowspan <= 1) {
      setStatus('This cell is not vertically merged', 'error');
      return false;
    }
    var tbody = tableBody(blockEl);
    var rows = tableSourceRows(tbody);
    var rowIndex = rows.indexOf(cell.parentElement);
    var logicalStart = tableCellLogicalStart(cell);
    for (var offset = 1; offset < rowspan; offset++) {
      var targetRow = rows[rowIndex + offset];
      var target = tableCellAtLogicalStart(targetRow, logicalStart);
      if (!target) continue;
      target.removeAttribute('data-rowspan-covered');
      target.setAttribute('contenteditable', 'true');
      target.hidden = false;
      target.style.removeProperty('display');
      setCellHtml(target, '');
    }
    cell.rowSpan = 1;
    wireTableCellFocus(blockEl);
    setStatus('Vertical merge removed', 'saved');
    return true;
  }

  function tableAddRow(blockEl) {
    var tbody = tableBody(blockEl);
    if (!tbody) return;
    var tr = document.createElement('tr');
    var colCount = tableColCount(blockEl);
    for (var i = 0; i < colCount; i++) {
      tr.appendChild(createTableBodyCell());
    }
    tbody.appendChild(tr);
    wireTableCellFocus(blockEl);
    var firstCell = tr.querySelector('td, th');
    if (firstCell) {
      clearTableCellSelection();
      addTableCellToSelection(firstCell);
      state.focusedTableCell = firstCell;
      state.lastStyleTarget = { block: blockEl, el: firstCell, type: 'table-cell' };
      firstCell.focus();
    }
    setStatus('Row added', 'saved');
  }

  function resolveSelectedTableCell(blockEl) {
    var candidates = [];
    if (state.focusedTableCell) candidates.push(state.focusedTableCell);
    if (state.lastStyleTarget && state.lastStyleTarget.type === 'table-cell') {
      candidates.push(state.lastStyleTarget.el);
    }
    state.selectedTableCells.slice().reverse().forEach(function (cell) {
      candidates.push(cell);
    });
    for (var i = 0; i < candidates.length; i++) {
      var cell = candidates[i];
      if (cell && blockEl.contains(cell) && cell.closest('.cpb-table')) return cell;
    }
    return null;
  }

  function tableDelRow(blockEl) {
    var tbody = tableBody(blockEl);
    if (!tbody) return false;
    var selectedCell = resolveSelectedTableCell(blockEl);
    var titleRow = selectedCell ? selectedCell.closest('tr[data-title-row]') : null;
    if (titleRow) {
      var titleText = titleRow.textContent.replace(/\s+/g, ' ').trim();
      if (titleText && !confirm('Delete this table title row?')) {
        return false;
      }
      titleRow.remove();
      var titleToggle = blockEl.querySelector('[data-table-action="toggle-title"]');
      if (titleToggle) titleToggle.textContent = '+ Title row';
      clearTableCellSelection();
      var fallbackCell = blockEl.querySelector('.cpb-table-header-row th')
        || tbody.querySelector('td');
      if (fallbackCell) {
        addTableCellToSelection(fallbackCell);
        state.focusedTableCell = fallbackCell;
        state.lastStyleTarget = { block: blockEl, el: fallbackCell, type: 'table-cell' };
        fallbackCell.focus();
      } else {
        state.focusedTableCell = null;
      }
      setStatus('Title row deleted', 'saved');
      return true;
    }

    var headerRow = selectedCell ? selectedCell.closest('tr.cpb-table-header-row') : null;
    if (headerRow) {
      var headerText = headerRow.textContent.replace(/\s+/g, ' ').trim();
      if (headerText && !confirm('Delete this table header row?')) {
        return false;
      }
      headerRow.remove();
      clearTableCellSelection();
      var firstBodyCell = tbody.querySelector('td');
      if (firstBodyCell) {
        addTableCellToSelection(firstBodyCell);
        state.focusedTableCell = firstBodyCell;
        state.lastStyleTarget = { block: blockEl, el: firstBodyCell, type: 'table-cell' };
        firstBodyCell.focus();
      } else {
        state.focusedTableCell = null;
      }
      setStatus('Header row deleted', 'saved');
      return true;
    }

    var rows = tableSourceRows(tbody);
    if (rows.length <= 1) {
      setStatus('Table must keep at least one row', 'error');
      return false;
    }
    var target = selectedCell && tbody.contains(selectedCell)
      ? selectedCell.closest('tr')
      : null;
    if (!target) {
      setStatus('Click a row cell first', 'error');
      return false;
    }
    var rowText = target.textContent.replace(/\s+/g, ' ').trim();
    if (rowText && !confirm('Delete this entire table row?')) {
      return false;
    }
    var rowIndex = rows.indexOf(target);
    var nextRow = rows[rowIndex + 1] || rows[rowIndex - 1] || null;
    var nextCell = nextRow ? nextRow.querySelector('td, th') : null;
    target.remove();
    clearTableCellSelection();
    if (nextCell) {
      addTableCellToSelection(nextCell);
      state.focusedTableCell = nextCell;
      state.lastStyleTarget = { block: blockEl, el: nextCell, type: 'table-cell' };
      nextCell.focus();
    } else {
      state.focusedTableCell = null;
    }
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

    var headRow = tableHeaderRow(blockEl);
    if (headRow) {
      var th = document.createElement('th');
      th.contentEditable = 'true';
      th.setAttribute('data-col-index', String(cols));
      th.innerHTML = '<span class="cpb-th-text">Column ' + (cols + 1) + '</span>'
        + '<span class="cpb-col-resize" data-col-index="' + cols + '" title="Resize column"></span>';
      headRow.appendChild(th);
    }

    tableSourceRows(table.querySelector('tbody[data-table-part="body"]')).forEach(function (tr) {
      var td = document.createElement('td');
      td.contentEditable = 'true';
      td.textContent = '';
      tr.appendChild(td);
    });

    normalizeTableTitleRow(blockEl);
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

    removeLogicalColumnFromRow(tableHeaderRow(blockEl), colIndex);

    tableSourceRows(table.querySelector('tbody[data-table-part="body"]')).forEach(function (tr) {
      removeLogicalColumnFromRow(tr, colIndex);
    });

    normalizeTableTitleRow(blockEl);
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
      clearTableCellSelection();
      var fallbackCell = blockEl.querySelector('.cpb-table-header-row th')
        || blockEl.querySelector('tbody[data-table-part="body"] td');
      if (fallbackCell) {
        addTableCellToSelection(fallbackCell);
        state.focusedTableCell = fallbackCell;
        state.lastStyleTarget = { block: blockEl, el: fallbackCell, type: 'table-cell' };
        fallbackCell.focus();
      } else {
        state.focusedTableCell = null;
      }
      setStatus('Title row deleted', 'saved');
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
    normalizeTableTitleRow(blockEl);
    if (toggleBtn) toggleBtn.textContent = 'Remove title row';
    wireTableCellFocus(blockEl);
    clearTableCellSelection();
    addTableCellToSelection(td);
    state.focusedTableCell = td;
    state.lastStyleTarget = { block: blockEl, el: td, type: 'table-cell' };
    td.focus();
  }

  function colWidthPx(col) {
    if (!col) return 140;
    var w = parseInt(String(col.style.width || '140').replace('px', ''), 10);
    return isNaN(w) ? 140 : w;
  }

  function tablePageContentMaxWidth() {
    var landscape = !!(state.pageLayout && state.pageLayout.orientation === 'landscape');
    var side = PRINT_PAGE.side || 56;
    return Math.max(200, (landscape ? 1056 : 816) - (side * 2));
  }

  function tableContentMaxWidth(blockEl) {
    var expected = tablePageContentMaxWidth();
    var sheetBody = blockEl.closest('.cpb-sheet-body');
    if (sheetBody && sheetBody.clientWidth >= expected - 8) {
      return sheetBody.clientWidth;
    }
    var sheet = blockEl.closest('.cpb-sheet');
    if (sheet && sheet.clientWidth > 0) {
      var style = window.getComputedStyle(sheet);
      var padL = parseFloat(style.paddingLeft) || 0;
      var padR = parseFloat(style.paddingRight) || 0;
      var inner = sheet.clientWidth - padL - padR;
      if (inner >= expected - 8) return Math.max(200, inner);
    }
    return expected;
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
    var maxTable = tableContentMaxWidth(blockEl);
    var others = tableOtherColsWidth(blockEl, colIndex);
    var maxForCol = Math.max(min, maxTable - others);
    return Math.max(min, Math.min(maxTable, maxForCol, desired));
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
    var landscape = !!(state.pageLayout && state.pageLayout.orientation === 'landscape');
    var renderedWidth = Math.min(total, maxTable);
    table.style.width = renderedWidth + 'px';
    table.style.maxWidth = landscape ? 'none' : '100%';
    table.style.minWidth = '0';
    var wrap = tableWrap(blockEl);
    if (wrap && blockEl.getAttribute('data-structured-table-editor') !== '1') {
      wrap.style.width = renderedWidth + 'px';
      wrap.style.maxWidth = landscape ? 'none' : '100%';
    }
  }

  function applyStoredTableWidths() {
    canvasEl.querySelectorAll('.cpb-block--table').forEach(function (blockEl) {
      if (blockEl.getAttribute('data-structured-table-editor') === '1') return;
      syncTableWidth(blockEl);
    });
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
    var table = blockEl.querySelector('table');
    if (!table) return;
    table.querySelectorAll('tbody .cpb-col-resize').forEach(function (handle) {
      handle.remove();
    });
    if (!tableHeaderRow(blockEl)) {
      var firstBodyRow = table.querySelector('tbody[data-table-part="body"] tr');
      var colIndex = 0;
      if (firstBodyRow) {
        Array.prototype.slice.call(firstBodyRow.children).forEach(function (cell) {
          var colspan = Math.max(1, parseInt(cell.getAttribute('colspan') || '1', 10) || 1);
          var handle = document.createElement('span');
          handle.className = 'cpb-col-resize';
          handle.setAttribute('data-col-index', String(colIndex + colspan - 1));
          handle.setAttribute('title', 'Resize column');
          handle.setAttribute('contenteditable', 'false');
          cell.appendChild(handle);
          colIndex += colspan;
        });
      }
    }
    syncTableWidth(blockEl);
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
    var list = node.closest && node.closest('.cpb-list');
    if (!list && focused && focused.closest) list = focused.closest('.cpb-list');
    var listContinuation = block.querySelector('.cpb-list-continuation');
    if (heading && (heading.contains(node) || node === heading || (focused && focused.closest('.cpb-heading')))) {
      return { block: block, el: heading, type: 'heading' };
    }
    if (paragraph && (paragraph.contains(node) || node === paragraph || (focused && focused.closest('.cpb-paragraph')))) {
      return { block: block, el: paragraph, type: 'paragraph' };
    }
    if (list && (list.contains(node) || node === list || (focused && focused.closest('.cpb-list')))) {
      return { block: block, el: list, type: 'list' };
    }
    if (listContinuation && (listContinuation.contains(node) || node === listContinuation
      || (focused && focused.closest('.cpb-list-continuation')))) {
      return { block: block, el: listContinuation, type: 'paragraph' };
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

  function orderedListAtSelection(target) {
    var sel = window.getSelection();
    if (sel && sel.anchorNode) {
      var selectionNode = sel.anchorNode.nodeType === 1
        ? sel.anchorNode
        : sel.anchorNode.parentElement;
      var selectedList = selectionNode && selectionNode.closest
        ? selectionNode.closest('ol')
        : null;
      if (selectedList && canvasEl.contains(selectedList)) return selectedList;
      if (selectionNode && canvasEl.contains(selectionNode)) return null;
    }
    if (target && target.el) {
      if (target.el.tagName === 'OL') return target.el;
      var targetList = target.el.closest ? target.el.closest('ol') : null;
      if (targetList && canvasEl.contains(targetList)) return targetList;
    }
    return null;
  }

  function syncToolbarFromTarget(target) {
    if (!target) return;
    if (listStartInput) {
      var orderedList = orderedListAtSelection(target);
      state.activeOrderedList = orderedList;
      listStartInput.hidden = false;
      listStartInput.disabled = !orderedList;
      if (orderedList) {
        var displayedOrderedList = orderedList.classList.contains('cpb-list')
          ? orderedList.closest('.cpb-block').querySelector('ol.cpb-list')
          : orderedList;
        setListStartSelectorValue(Math.max(
          1,
          parseInt(displayedOrderedList.getAttribute('start') || '1', 10) || 1
        ));
      }
    }
    if (target.type === 'table-cell') {
      var cellEffective = readEffectiveTypographyForElement(target.el);
      if (fontSelect) fontSelect.value = cellEffective.font_family;
      if (fontSizeSelect) fontSizeSelect.value = String(cellEffective.font_size);
      if (textColorInput) textColorInput.value = cellEffective.text_color;
      updateParagraphStyleSelectForElement(target.el);
      if (regulatoryRefInput) regulatoryRefInput.hidden = true;
      if (crossRefDocSelect) crossRefDocSelect.hidden = true;
      if (crossRefKeySelect) crossRefKeySelect.hidden = true;
      if (crossRefClearBtn) crossRefClearBtn.hidden = true;
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
      if (target.type === 'paragraph') {
        updateCrossRefFieldVisibility(ps, target.el);
      } else {
        if (crossRefDocSelect) crossRefDocSelect.hidden = true;
        if (crossRefKeySelect) crossRefKeySelect.hidden = true;
        if (crossRefClearBtn) crossRefClearBtn.hidden = true;
      }
      return;
    }
    if (target.type === 'callout-title' || target.type === 'callout-text') {
      var calloutEffective = readEffectiveTypographyForElement(target.el);
      setParagraphStyleSelectValue(resolveParagraphStyleSelectValue(target.el));
      if (fontSelect) fontSelect.value = calloutEffective.font_family;
      if (fontSizeSelect) fontSizeSelect.value = String(calloutEffective.font_size);
      if (textColorInput) textColorInput.value = calloutEffective.text_color;
      if (regulatoryRefInput) regulatoryRefInput.hidden = true;
      if (crossRefDocSelect) crossRefDocSelect.hidden = true;
      if (crossRefKeySelect) crossRefKeySelect.hidden = true;
      if (crossRefClearBtn) crossRefClearBtn.hidden = true;
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

  function shouldUseNativeTableCellClipboard(cell) {
    if (!cell) return false;
    var selection = window.getSelection();
    if (selection && selection.rangeCount > 0) {
      var range = selection.getRangeAt(0);
      if (!selection.isCollapsed && (cell.contains(selection.anchorNode)
        || cell.contains(selection.focusNode)
        || cell.contains(range.commonAncestorContainer))) {
        return true;
      }
    }

    var blockEl = cell.closest('.cpb-block--table');
    var selectedCells = state.selectedTableCells.filter(function (selectedCell) {
      return blockEl && blockEl.contains(selectedCell);
    });
    if (selectedCells.length > 1) return false;

    if (selection && selection.rangeCount > 0) {
      var caretRange = selection.getRangeAt(0);
      if (cell.contains(selection.anchorNode)
        || cell.contains(selection.focusNode)
        || cell.contains(caretRange.commonAncestorContainer)) {
        return true;
      }
    }

    var active = document.activeElement;
    return active === cell || !!(active && cell.contains(active));
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

  function indentSelectionRange(rootEl) {
    restoreSelectionRange();
    var sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) return null;
    var range = sel.getRangeAt(0);
    var container = range.commonAncestorContainer.nodeType === 1
      ? range.commonAncestorContainer
      : range.commonAncestorContainer.parentElement;
    return container && rootEl.contains(container) ? range : null;
  }

  function rangeTouchesNode(range, node) {
    if (!range || !node) return false;
    if (range.collapsed) {
      return node === range.startContainer || node.contains(range.startContainer);
    }
    try {
      return range.intersectsNode(node);
    } catch (err) {
      return false;
    }
  }

  function directLineUnit(rootEl, node) {
    var el = node && node.nodeType === 1 ? node : node && node.parentElement;
    while (el && el !== rootEl) {
      if (el.parentElement === rootEl
        && (el.tagName === 'P' || el.tagName === 'DIV' || el.classList.contains('cpb-line-indent'))) {
        return el;
      }
      el = el.parentElement;
    }
    return null;
  }

  function wrapSelectedInlineLines(rootEl, range) {
    var lines = [[]];
    Array.prototype.slice.call(rootEl.childNodes).forEach(function (node) {
      if (node.nodeType === 1 && node.tagName === 'BR') {
        lines.push([]);
      } else {
        lines[lines.length - 1].push(node);
      }
    });
    var units = [];
    lines.forEach(function (line) {
      if (!line.length || !line.some(function (node) { return rangeTouchesNode(range, node); })) return;
      if (line.length === 1 && line[0].nodeType === 1
        && line[0].classList.contains('cpb-line-indent')) {
        units.push(line[0]);
        return;
      }
      var wrapper = document.createElement('span');
      wrapper.className = 'cpb-line-indent';
      wrapper.setAttribute('data-indent-level', '0');
      rootEl.insertBefore(wrapper, line[0]);
      line.forEach(function (node) { wrapper.appendChild(node); });
      units.push(wrapper);
    });
    return units;
  }

  function selectedTextLineUnits(rootEl, range) {
    var units = [];
    rootEl.querySelectorAll(':scope > p, :scope > div, :scope > .cpb-line-indent').forEach(function (el) {
      if (rangeTouchesNode(range, el)) units.push(el);
    });
    if (units.length) return units;
    var direct = directLineUnit(rootEl, range.startContainer);
    if (direct) return [direct];
    return wrapSelectedInlineLines(rootEl, range);
  }

  function setLineIndentLevel(el, level) {
    level = Math.max(0, Math.min(INDENT_MAX_LEVEL, level));
    if (level > 0) {
      el.classList.add('cpb-line-indent');
      el.setAttribute('data-indent-level', String(level));
      return;
    }
    el.removeAttribute('data-indent-level');
    if (el.tagName === 'SPAN' && el.classList.contains('cpb-line-indent')) {
      unwrapElement(el);
    } else {
      el.classList.remove('cpb-line-indent');
    }
  }

  function applyListItemIndent(target, delta) {
    var list = target.el;
    var range = indentSelectionRange(list);
    if (!range) return false;
    var items = Array.prototype.slice.call(list.querySelectorAll(':scope > li')).filter(function (li) {
      return rangeTouchesNode(range, li);
    });
    if (!items.length) {
      var activeItem = range.startContainer.nodeType === 1
        ? range.startContainer.closest('li')
        : range.startContainer.parentElement && range.startContainer.parentElement.closest('li');
      if (activeItem && activeItem.parentElement === list) items = [activeItem];
    }
    if (!items.length) return false;
    list.style.marginLeft = '';
    list.setAttribute('data-indent-level', '0');
    items.forEach(function (li) {
      var level = parseInt(li.getAttribute('data-indent-level') || '0', 10) || 0;
      var nextLevel = Math.max(0, Math.min(INDENT_MAX_LEVEL, level + delta));
      if (nextLevel > 0) li.setAttribute('data-indent-level', String(nextLevel));
      else li.removeAttribute('data-indent-level');
    });
    return true;
  }

  function applyIndentDelta(delta) {
    var target = getActiveStyleTarget();
    if (!target || target.type === 'table-cell') return;
    if (target.type !== 'heading' && target.type !== 'paragraph' && target.type !== 'list') return;
    pushUndo();
    var changed = false;
    if (target.type === 'list') {
      changed = applyListItemIndent(target, delta);
    } else {
      var range = indentSelectionRange(target.el);
      if (range) {
        target.el.style.marginLeft = '';
        target.el.setAttribute('data-indent-level', '0');
        var units = selectedTextLineUnits(target.el, range);
        units.forEach(function (unit) {
          var level = parseInt(unit.getAttribute('data-indent-level') || '0', 10) || 0;
          setLineIndentLevel(unit, level + delta);
        });
        changed = units.length > 0;
      }
    }
    if (!changed) return;
    scheduleSave(target.block);
    flushSave(target.block);
  }

  function applyCanvasZoom(pct, persist) {
    state.canvasZoom = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, pct));
    var pagesStack = canvasEl.querySelector('.cpb-pages-stack');
    if (state.viewMode === 'paginated' && pagesStack) {
      pagesStack.style.zoom = String(state.canvasZoom / 100);
    } else {
      var sheet = canvasEl.querySelector('.cpb-sheet');
      if (sheet) {
        sheet.style.setProperty('--cpb-sheet-zoom', String(state.canvasZoom / 100));
      }
    }
    if (liveProjectionPagesEl) {
      liveProjectionPagesEl.style.setProperty('--cpb-projection-zoom', String(state.canvasZoom / 100));
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
    var tocColor = '#000000';
    canvasEl.querySelectorAll('.cpb-toc-row[data-toc-style]').forEach(function (row) {
      var styleKey = row.getAttribute('data-toc-style') || 'body';
      var def = paragraphStyleDef(styleKey);
      var size = Math.max(6, (parseInt(def.font_size, 10) || 11) - 4);
      row.style.color = tocColor;
      row.style.fontSize = size + 'pt';
      row.setAttribute('data-text-color', tocColor);
      row.setAttribute('data-font-size', String(size));
      row.querySelectorAll('.cpb-toc-label, .cpb-toc-page, .cpb-toc-link').forEach(function (el) {
        el.style.color = tocColor;
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
    var spacing = paragraphStyleDef(paragraphStyle || 'body');
    el.style.marginTop = spacing.margin_top === null ? '' : spacing.margin_top + 'px';
    el.style.marginBottom = spacing.margin_bottom === null ? '' : spacing.margin_bottom + 'px';
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
        var tableCells = getTableCellsForStyle(target);
        if (tableCells.length > 1) applyWhole = true;
        if (applyWhole) {
          tableCells.forEach(function (cell) {
            applyTypographyToTableCell(cell, typo);
          });
          if (tableCells.length > 1) {
            applyFormatStateToTableCells(tableCells, 'bold', typo.font_bold);
            applyFormatStateToTableCells(tableCells, 'italic', typo.font_italic);
            applyFormatStateToTableCells(tableCells, 'underline', typo.font_underline);
          }
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
    if (target.type === 'paragraph') {
      updateCrossRefFieldVisibility(styleKey, target.el);
    }
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
      var fontCells = getTableCellsForStyle(target);
      fontCells.forEach(function (cell) {
        if (fontCells.length > 1) {
          clearInlineTypographyInElement(cell);
          applyStyleToTableCell(cell, { font: font });
        } else {
          applyFontToTableCell(cell, font);
        }
        updateParagraphStyleSelectForElement(cell);
      });
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
      var sizeCells = getTableCellsForStyle(target);
      sizeCells.forEach(function (cell) {
        if (sizeCells.length > 1) {
          clearInlineTypographyInElement(cell);
          applyStyleToTableCell(cell, { size: size });
        } else {
          applySizeToTableCell(cell, size);
        }
        updateParagraphStyleSelectForElement(cell);
      });
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
      getTableCellsForStyle(target).forEach(function (cell) {
        applyStyleToTableCell(cell, { align: align });
      });
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
      var createdBlockId = parseInt(
        (res.block && res.block.id) || res.block_id || '0',
        10
      );
      function focusCreatedBlock() {
        if (createdBlockId <= 0) return;
        var created = canvasEl.querySelector(
          '.cpb-block[data-block-id="' + String(createdBlockId) + '"]'
        );
        var field = created ? created.querySelector('[contenteditable="true"]') : null;
        if (!field) return;
        try {
          field.focus({ preventScroll: true });
        } catch (err) {
          field.focus();
        }
        var range = document.createRange();
        range.selectNodeContents(field);
        range.collapse(true);
        var selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
      }
      var anchor = liveInsertAfter || (focusedBlock && isConnectedEl(focusedBlock) ? focusedBlock : null);
      var body = canvasEl.querySelector('[data-blocks-root]');
      if (anchor && res.block_html) {
        anchor.insertAdjacentHTML('afterend', res.block_html);
        wireCanvas();
        var newBlock = anchor.nextElementSibling;
        if (newBlock && newBlock.classList.contains('cpb-block')) {
          focusCreatedBlock();
        }
      } else if (body && res.block_html) {
        body.insertAdjacentHTML('beforeend', res.block_html);
        wireCanvas();
        var lastBlock = body.querySelector('.cpb-block:last-child');
        if (lastBlock) {
          focusCreatedBlock();
        }
      }
      setStatus('Added', 'saved');
      markPaginationChanged();
      applyNumberingState(res);
      if (blockType === 'table') {
        var createdTable = anchor ? anchor.nextElementSibling : (body ? body.querySelector('.cpb-block:last-child') : null);
        if (createdTable && createdTable.classList.contains('cpb-block--table')) {
          refreshContentTableTypographyFromBookStyles();
          flushSave(createdTable);
        }
      }
      if (blockType === 'paragraph' || blockType === 'heading') {
        return recomputeSectionNumbers().catch(showError).then(function () {
          focusCreatedBlock();
          return res;
        });
      }
      return res;
    });
  }

  function insertCallout(type) {
    var preset = presetByType(type);
    if (!preset) {
      preset = {
        callout_type: type,
        title: type === 'caution' ? 'CAUTION' : (type === 'info' ? 'INFO' : (type === 'note' ? 'NOTE' : 'WARNING')),
        text: '',
      };
    }
    var focusedBlock = getFocusedBlock();
    if (focusedBlock && (focusedBlock.getAttribute('data-block-type') || '') === 'callout') {
      var callout = focusedBlock.querySelector('.cpb-callout');
      var title = focusedBlock.querySelector('.cpb-callout-title');
      if (callout) {
        pushUndo();
        ['warning', 'caution', 'info', 'note'].forEach(function (name) {
          callout.classList.remove('cpb-callout--' + name);
        });
        callout.classList.add('cpb-callout--' + type);
        callout.setAttribute('data-callout-type', type);
        if (title) {
          title.innerHTML = preset.title || (type === 'caution' ? 'CAUTION' : (type === 'info' ? 'INFO' : (type === 'note' ? 'NOTE' : 'WARNING')));
        }
        refreshCalloutTypographyFromBookStyles();
        scheduleSave(focusedBlock);
        flushSave(focusedBlock);
        return;
      }
    }
    createBlock('callout', {
      callout_type: type,
      title: preset.title || (type === 'caution' ? 'CAUTION' : (type === 'info' ? 'INFO' : (type === 'note' ? 'NOTE' : 'WARNING'))),
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
    if (state.isAnnexRegisterSection || state.isAnnexCrossRefSection || state.isAnnexHighlightsSection || state.isAnnexContentSection) {
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
      + '<label class="cpb-header-row-height">Minimum row height <select class="cpb-style-input" id="cpbHeaderRowHeight">'
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
      + '<label class="cpb-header-row-height">Minimum row height <select class="cpb-style-input" id="cpbFooterRowHeight">'
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
      apiUpload(fd)
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
    var localTablePage = state.isLepSection
      ? state.lepPage
      : (
          state.part0Structured
          && (state.part0SectionKey === 'amendment_list' || state.part0SectionKey === 'distribution_list')
            ? state.part0Page
            : null
        );
    var localTableOnly = !!localTablePage;
    if (localTableOnly) {
      styles.table_styles.standard = mergedTableStyle(
        styles.table_styles.standard || defaultTableStyleDef(),
        localTablePage.table_style || {}
      );
    }
    var overlay = document.createElement('div');
    overlay.className = 'cpb-style-overlay';
    var styleEditorTitle = localTableOnly
      ? 'Table Editor'
      : (documentType === 'form' ? 'Form Style Editor' : 'Book style editor');
    var styleSavedStatus = localTableOnly
      ? 'Page table style saved'
      : (documentType === 'form' ? 'Form styles saved' : 'Book styles saved');

    var paragraphRows = PARAGRAPH_STYLE_KEYS.map(function (key) {
      var def = styles.paragraph_styles[key] || {};
      var sample = PARAGRAPH_STYLE_LABELS[key] || key;
      var sampleStyle = 'font-family:' + escapeAttr(FONT_STACKS[def.font_family] || FONT_STACKS.serif)
        + ';font-size:' + (def.font_size || 11) + 'pt'
        + ';color:' + escapeAttr(def.color || '#0f172a')
        + ';font-weight:' + (def.font_bold ? '700' : '400')
        + ';font-style:' + (def.font_italic ? 'italic' : 'normal')
        + ';text-decoration:' + (def.font_underline ? 'underline' : 'none');
      var marginTop = def.margin_top === null || def.margin_top === undefined ? '' : def.margin_top;
      var marginBottom = def.margin_bottom === null || def.margin_bottom === undefined ? '' : def.margin_bottom;
      return ''
        + '<tr data-ps-row="' + key + '">'
        + '<td class="cpb-style-name">' + escapeHtml(sample) + '</td>'
        + '<td><select class="cpb-style-input" data-ps-field="font_family">' + styleEditorFontOptions(def.font_family || 'serif') + '</select></td>'
        + '<td><input class="cpb-style-input cpb-style-input--num" type="number" min="8" max="32" data-ps-field="font_size" value="' + (def.font_size || 11) + '"></td>'
        + '<td><input class="cpb-style-input cpb-style-input--margin" type="number" min="0" max="200" placeholder="Auto" data-ps-field="margin_top" value="' + marginTop + '"></td>'
        + '<td><input class="cpb-style-input cpb-style-input--margin" type="number" min="0" max="200" placeholder="Auto" data-ps-field="margin_bottom" value="' + marginBottom + '"></td>'
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
          + '<span class="cpb-style-format">'
          + '<label title="Bold"><input type="checkbox" data-table-kind="' + kind + '" data-table-field="' + rowKey + '.font_bold"' + (row.font_bold ? ' checked' : '') + '>B</label>'
          + '<label title="Italic"><input type="checkbox" data-table-kind="' + kind + '" data-table-field="' + rowKey + '.font_italic"' + (row.font_italic ? ' checked' : '') + '>I</label>'
          + '<label title="Underline"><input type="checkbox" data-table-kind="' + kind + '" data-table-field="' + rowKey + '.font_underline"' + (row.font_underline ? ' checked' : '') + '>U</label>'
          + '</span>'
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

    if (localTableOnly) {
      overlay.innerHTML = ''
        + '<div class="cpb-style-dialog" role="dialog" aria-label="' + escapeAttr(styleEditorTitle) + '">'
        + '<h3>' + escapeHtml(styleEditorTitle) + '</h3>'
        + '<p class="cpb-style-lead">Edit this Part 0 table independently from the global book table style.</p>'
        + tableSection('standard', 'Table appearance')
        + '<div class="cpb-style-dialog-actions">'
        + '<button type="button" class="cpb-style-cancel">Cancel</button>'
        + '<button type="button" class="cpb-style-save">Save table style</button>'
        + '</div></div>';
    } else {
      overlay.innerHTML = ''
        + '<div class="cpb-style-dialog" role="dialog" aria-label="' + escapeAttr(styleEditorTitle) + '">'
      + '<h3>' + escapeHtml(styleEditorTitle) + '</h3>'
      + '<p class="cpb-style-lead">Paragraph styles drive the Table of Contents and automatic section numbering '
      + '(Title 1. · Subtitle 1 1.1 · Subtitle 2 1.1.1 · …). '
      + 'Regulatory Reference blocks show an MCCF cross-reference — auto-derived or entered manually in the toolbar.</p>'
      + (documentType === 'form' ? '' : ''
        + '<section class="cpb-style-section cpb-style-copy-section">'
        + '<h4>Copy style from another book</h4>'
        + '<div class="cpb-style-copy-row">'
        + '<select class="cpb-style-input cpb-style-copy-select" aria-label="Source book version">'
        + '<option value="">Loading existing books…</option></select>'
        + '<button type="button" class="cpb-style-copy-button" disabled>Copy style</button>'
        + '</div>'
        + '<p class="cpb-style-copy-help">Copies paragraph, table, callout, header and footer styles only. '
        + 'Manual content and callout preset text are not copied.</p>'
        + '</section>')
      + '<section class="cpb-style-section"><h4>Paragraph styles</h4>'
      + '<table class="cpb-style-table"><thead><tr><th>Style</th><th>Font</th><th>Size</th><th>Top margin (px)</th><th>Bottom margin (px)</th><th>Color</th><th>Format</th><th>Sample</th></tr></thead><tbody>'
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
    }

    function readStylesFromDialog() {
      var next = defaultBookStyles();
      PARAGRAPH_STYLE_KEYS.forEach(function (key) {
        var row = overlay.querySelector('[data-ps-row="' + key + '"]');
        if (!row) return;
        function optionalMargin(field) {
          var value = row.querySelector('[data-ps-field="' + field + '"]').value;
          return value === '' ? null : Math.max(0, Math.min(200, parseInt(value, 10) || 0));
        }
        next.paragraph_styles[key] = {
          font_family: row.querySelector('[data-ps-field="font_family"]').value,
          font_size: parseInt(row.querySelector('[data-ps-field="font_size"]').value, 10) || 11,
          margin_top: optionalMargin('margin_top'),
          margin_bottom: optionalMargin('margin_bottom'),
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
            base[parts[0]][parts[1]] = input.type === 'checkbox'
              ? !!input.checked
              : (parts[1] === 'font_size' ? (parseInt(input.value, 10) || 10) : input.value);
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
    var copySelect = overlay.querySelector('.cpb-style-copy-select');
    var copyButton = overlay.querySelector('.cpb-style-copy-button');
    if (copySelect && copyButton) {
      copySelect.addEventListener('change', function () {
        copyButton.disabled = !parseInt(copySelect.value || '0', 10);
      });
      copyButton.addEventListener('click', function () {
        var sourceVersionId = parseInt(copySelect.value || '0', 10);
        if (!sourceVersionId) return;
        var selected = copySelect.options[copySelect.selectedIndex];
        var sourceLabel = selected ? selected.textContent : 'the selected book';
        if (!confirm(
          'Replace this draft\'s book styles, running header and footer with the style from '
          + sourceLabel + '?\\n\\nManual content will not be copied.'
        )) return;
        copyButton.disabled = true;
        setStatus('Copying book style…', 'saving');
        apiPost('copy_book_styles', {
          version_id: state.versionId,
          source_version_id: sourceVersionId,
        }).then(function (res) {
          if (!res.ok) throw new Error(res.error || 'Style copy failed');
          state.bookStyles = res.book_styles || state.bookStyles;
          state.pageHeader = state.bookStyles.page_header || state.pageHeader;
          state.pageFooter = state.bookStyles.page_footer || state.pageFooter;
          state.calloutPresets = state.bookStyles.callout_presets || state.calloutPresets;
          close();
          setStatus('Book style copied from ' + sourceLabel, 'saved');
          return loadSection(state.sectionId);
        }).catch(function (err) {
          copyButton.disabled = false;
          showError(err);
        });
      });
    }
    overlay.querySelector('.cpb-style-save').addEventListener('click', function () {
      var payload = readStylesFromDialog();
      if (localTableOnly) {
        var tableStyle = payload.table_styles.standard;
        var request;
        if (state.isLepSection) {
          var lepPage = extractLepPageFromCanvas();
          lepPage.table_style = tableStyle;
          request = apiPost('save_lep_page', {
            version_id: state.versionId,
            lep_page: lepPage,
          }).then(function (res) {
            if (!res.ok) throw new Error(res.error || 'Table style save failed');
            state.lepPage = res.lep_page || lepPage;
          });
        } else {
          var part0Page = extractPart0PageFromCanvas();
          part0Page.table_style = tableStyle;
          request = apiPost('save_part0_page', {
            version_id: state.versionId,
            section_key: state.part0SectionKey,
            part0_page: part0Page,
            headings: extractPart0HeadingsFromCanvas(),
          }).then(function (res) {
            if (!res.ok) throw new Error(res.error || 'Table style save failed');
            state.part0Page = res.part0_page || part0Page;
          });
        }
        request.then(function () {
          close();
          setStatus(styleSavedStatus, 'saved');
          return loadSection(state.sectionId);
        }).catch(showError);
        return;
      }
      apiPost('save_book_styles', { version_id: state.versionId, book_styles: payload })
        .then(function (res) {
          if (!res.ok) throw new Error(res.error || 'Save failed');
          state.bookStyles = res.book_styles || payload;
          state.calloutPresets = state.bookStyles.callout_presets || state.calloutPresets;
          close();
          setStatus(styleSavedStatus, 'saved');
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
    if (copySelect && copyButton) {
      apiGet(apiBase + '?action=list_style_copy_sources&version_id=' + state.versionId)
        .then(function (res) {
          if (!res.ok) throw new Error(res.error || 'Could not load existing books');
          var sources = Array.isArray(res.sources) ? res.sources : [];
          copySelect.innerHTML = '<option value="">Choose a book and revision…</option>'
            + sources.map(function (source) {
              var status = source.lifecycle_status ? ' · ' + source.lifecycle_status : '';
              var label = (source.book_key || source.book_title || 'Book')
                + ' — ' + (source.version_label || ('Version ' + source.version_id))
                + status;
              return '<option value="' + parseInt(source.version_id, 10) + '">'
                + escapeHtml(label) + '</option>';
            }).join('');
          copyButton.disabled = true;
        })
        .catch(function (err) {
          copySelect.innerHTML = '<option value="">Unable to load existing books</option>';
          copyButton.disabled = true;
          showError(err);
        });
    }
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
    if (isAnnexBook) return;
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
    apiUpload(fd)
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

  function removeListFormatting() {
    var el = focusFormatTarget();
    if (!el) return;
    var list = el.closest('ul, ol');
    if (!list) return;
    pushUndo();
    var parent = list.parentNode;
    var items = list.querySelectorAll(':scope > li');
    var replacement = document.createDocumentFragment();
    items.forEach(function (li) {
      var block = document.createElement('div');
      block.innerHTML = li.innerHTML;
      replacement.appendChild(block);
    });
    parent.replaceChild(replacement, list);
    var blockEl = el.closest('.cpb-block');
    if (blockEl) {
      scheduleSave(blockEl);
      flushSave(blockEl);
    }
  }

  function selectTableCellText(cell) {
    if (!cell) return false;
    var contentEl = cell.tagName === 'TH'
      ? (cell.querySelector('.cpb-th-text') || cell)
      : cell;
    var sel = window.getSelection();
    if (!sel) return false;
    var range = document.createRange();
    range.selectNodeContents(contentEl);
    sel.removeAllRanges();
    sel.addRange(range);
    return true;
  }

  function applyFormatStateToTableCells(cells, cmd, shouldEnable) {
    cells.forEach(function (cell) {
      if (!selectTableCellText(cell)) return;
      if (document.queryCommandState(cmd) !== shouldEnable) {
        document.execCommand(cmd, false, null);
      }
    });
  }

  function execFormatForSelectedTableCells(cmd) {
    if (cmd !== 'bold' && cmd !== 'italic' && cmd !== 'underline') return false;
    var target = resolveTableCellForStyle();
    if (!target) return false;
    var cells = getTableCellsForStyle(target);
    if (cells.length < 2) return false;

    pushUndo();
    var allActive = cells.every(function (cell) {
      if (!selectTableCellText(cell)) return false;
      return document.queryCommandState(cmd);
    });
    applyFormatStateToTableCells(cells, cmd, !allActive);

    var sel = window.getSelection();
    if (sel) sel.removeAllRanges();
    state.savedSelectionRange = null;
    state.focusedTableCell = target.el;
    state.lastStyleTarget = target;
    target.el.focus();
    scheduleSave(target.block);
    return true;
  }

  function execFormat(cmd, value) {
    if (cmd === 'removeList') {
      removeListFormatting();
      return;
    }
    if (execFormatForSelectedTableCells(cmd)) return;
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
        doUndo(true);
      }
      if (e.target.closest('#cpbRedo')) {
        e.preventDefault();
        doRedo(true);
      }
    });
  }

  document.addEventListener('selectionchange', function () {
    updateReviewerSelectionAction();
    if (!selectionInCanvas()) return;
    saveSelectionRange();
    rememberStyleTarget();
  });
  canvasEl.addEventListener('scroll', function () {
    var action = document.getElementById('cpbAddReviewerComment');
    if (action) action.hidden = true;
  }, { passive: true });

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

  if (listStartInput) {
    listStartInput.addEventListener('focus', rememberStyleTarget);
    listStartInput.addEventListener('change', function () {
      applyOrderedListStart(listStartInput.value);
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
    textColorInput.addEventListener('focus', function () {
      pausePendingSaveTimer();
      rememberStyleTarget();
    });
    textColorInput.addEventListener('blur', function () {
      if (isLiveStyleTarget(state.lastStyleTarget)) {
        resumePendingSave(state.lastStyleTarget.block);
      }
    });
    textColorInput.addEventListener('input', function () {
      if (
        document.activeElement === textColorInput
        && textColorInput.getAttribute('data-color-commit') !== '1'
      ) return;
      var color = textColorInput.value;
      var tableTarget = resolveTableCellForStyle();
      if (tableTarget) {
        pushUndo();
        getTableCellsForStyle(tableTarget).forEach(function (cell) {
          applyTableCellTextColor(cell, color);
          updateParagraphStyleSelectForElement(cell);
        });
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
        flushSave(target.block);
        return;
      }
      execFormat('foreColor', color);
    });
    textColorInput.addEventListener('change', function () {
      textColorInput.setAttribute('data-color-commit', '1');
      textColorInput.dispatchEvent(new Event('input', { bubbles: true }));
      textColorInput.removeAttribute('data-color-commit');
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
    if (isAnnexBook) syncSelect.style.display = 'none';
    syncSelect.addEventListener('change', function () {
      var action = syncSelect.value;
      syncSelect.value = '';
      if (action === 'toc') syncToc();
      else if (action === 'structure') syncManualStructure();
      else if (action === 'highlights') syncHighlights();
    });
  }

  function formFieldDefaults(type, variableKey) {
    var variableLabels = {
      'student.full_name': 'Student full name',
      'student.phone': 'Student phone',
      'student.email': 'Student email',
      'instructor.full_name': 'Instructor full name',
      'instructor.phone': 'Instructor phone',
      'instructor.email': 'Instructor email',
      'course.name': 'Course name',
      'theory.completion': 'Theory completion',
      'knowledge_test.score': 'Knowledge test score',
      'knowledge_test.deficient_codes': 'Knowledge test deficient codes',
    };
    var keyBase = (variableKey || type).toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '');
    var key = (keyBase || 'field') + '_' + Date.now();
    var label = variableKey && variableLabels[variableKey] ? variableLabels[variableKey] : (type === 'checkbox' ? 'Checkbox' : (type === 'date' ? 'Date' : (type === 'signature' ? 'Signature' : (type === 'initial' ? 'Initial' : 'Text Field'))));
    return {
      field_key: key || ('field_' + Date.now()),
      field_type: type === 'field' ? 'text' : type,
      label: label,
      required: false,
      assigned_role: 'instructor',
      variable_key: variableKey || '',
      placeholder: variableKey ? '{{' + variableKey + '}}' : '',
    };
  }

  function selectedFormFieldBlock() {
    var block = formSelectedBlockId ? canvasEl.querySelector('.cpb-block[data-block-id="' + formSelectedBlockId + '"]') : null;
    if (!block) block = getFocusedBlock();
    if (!block) return null;
    var type = block.getAttribute('data-block-type') || '';
    return ['field', 'checkbox', 'date', 'signature', 'initial'].indexOf(type) >= 0 ? block : null;
  }

  function openFormFieldSettings(blockEl) {
    blockEl = blockEl || selectedFormFieldBlock();
    if (!blockEl) return;
    var field = blockEl.querySelector('[data-form-field="1"]');
    if (!field) return;
    var overlay = document.createElement('div');
    overlay.className = 'cpb-callout-overlay';
    overlay.innerHTML = ''
      + '<div class="cpb-callout-dialog" role="dialog" aria-label="Field settings">'
      + '<h3>Field settings</h3>'
      + '<div class="cpb-callout-field"><label>Field key</label><input type="text" id="cffFieldKey" value="' + escapeAttr(field.getAttribute('data-field-key') || '') + '"></div>'
      + '<div class="cpb-callout-field"><label>Label</label><input type="text" id="cffFieldLabel" value="' + escapeAttr(field.getAttribute('data-label') || '') + '"></div>'
      + '<div class="cpb-callout-field"><label>Assigned role</label><select id="cffAssignedRole">'
      + ['admin', 'instructor', 'student', 'other_instructor', 'examiner', 'external_party'].map(function (role) {
        var selected = role === (field.getAttribute('data-assigned-role') || 'instructor') ? ' selected' : '';
        return '<option value="' + role + '"' + selected + '>' + role.replace(/_/g, ' ') + '</option>';
      }).join('')
      + '</select></div>'
      + '<div class="cpb-callout-field"><label>Variable binding</label><input type="text" id="cffVariableKey" value="' + escapeAttr(field.getAttribute('data-variable-key') || '') + '"></div>'
      + '<div class="cpb-callout-field"><label>Placeholder</label><input type="text" id="cffPlaceholder" value="' + escapeAttr(field.getAttribute('data-placeholder') || '') + '"></div>'
      + '<label style="display:flex;gap:8px;align-items:center;margin:10px 0;font-size:13px;font-weight:800;color:#334155;"><input type="checkbox" id="cffRequired"' + (field.getAttribute('data-required') === '1' ? ' checked' : '') + '> Required</label>'
      + '<div class="cpb-callout-dialog-actions"><button type="button" class="cpb-callout-cancel">Cancel</button><button type="button" class="cpb-callout-save">Apply</button></div>'
      + '</div>';
    function close() { overlay.remove(); }
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    overlay.querySelector('.cpb-callout-cancel').addEventListener('click', close);
    overlay.querySelector('.cpb-callout-save').addEventListener('click', function () {
      field.setAttribute('data-field-key', overlay.querySelector('#cffFieldKey').value.trim());
      field.setAttribute('data-label', overlay.querySelector('#cffFieldLabel').value.trim());
      field.setAttribute('data-assigned-role', overlay.querySelector('#cffAssignedRole').value);
      field.setAttribute('data-variable-key', overlay.querySelector('#cffVariableKey').value.trim());
      field.setAttribute('data-placeholder', overlay.querySelector('#cffPlaceholder').value.trim());
      field.setAttribute('data-required', overlay.querySelector('#cffRequired').checked ? '1' : '0');
      var label = field.querySelector('.cpb-form-field-label');
      if (label) label.textContent = field.getAttribute('data-label') || 'Field';
      var role = field.querySelector('.cpb-form-role');
      if (role) role.textContent = field.getAttribute('data-assigned-role') || 'instructor';
      flushSave(blockEl);
      close();
    });
    document.body.appendChild(overlay);
  }

  function openFormVariablePicker() {
    apiGet(apiBase + '?action=variables&version_id=' + state.versionId).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Variables failed');
      var groups = res.variables || [];
      var overlay = document.createElement('div');
      overlay.className = 'cpb-callout-overlay';
      overlay.innerHTML = '<div class="cpb-callout-dialog" role="dialog" aria-label="Insert variable"><h3>Insert variable</h3><div id="cffVariableList"></div><div class="cpb-callout-dialog-actions"><button type="button" class="cpb-callout-cancel">Close</button></div></div>';
      var list = overlay.querySelector('#cffVariableList');
      list.innerHTML = groups.map(function (group) {
        return '<div class="cpb-callout-field"><label>' + escapeHtml(group.group || '') + '</label>'
          + (group.variables || []).map(function (item) {
            return '<button type="button" class="cpb-tool-btn" style="margin:3px 4px 3px 0;" data-form-variable="' + escapeAttr(item.key || '') + '">' + escapeHtml(item.label || item.key || '') + '</button>';
          }).join('')
          + '</div>';
      }).join('');
      function close() { overlay.remove(); }
      overlay.querySelector('.cpb-callout-cancel').addEventListener('click', close);
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) close();
        var btn = e.target.closest('[data-form-variable]');
        if (!btn) return;
        var key = btn.getAttribute('data-form-variable') || '';
        var block = selectedFormFieldBlock();
        if (block) {
          var field = block.querySelector('[data-form-field="1"]');
          if (field) field.setAttribute('data-variable-key', key);
          flushSave(block);
        } else {
          createBlock('field', formFieldDefaults('field', key)).catch(showError);
        }
        close();
      });
      document.body.appendChild(overlay);
    }).catch(showError);
  }

  if (documentType === 'form') {
    root.querySelectorAll('[data-form-tool]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var type = btn.getAttribute('data-form-tool') || 'field';
        createBlock(type, formFieldDefaults(type)).catch(showError);
      });
    });
    var fieldSettingsBtn = document.getElementById('cpbFormFieldSettings');
    if (fieldSettingsBtn) {
      fieldSettingsBtn.addEventListener('click', function (e) {
        e.preventDefault();
        openFormFieldSettings();
      });
    }
    var variableBtn = document.getElementById('cpbFormVariablePicker');
    if (variableBtn) {
      variableBtn.addEventListener('click', function (e) {
        e.preventDefault();
        openFormVariablePicker();
      });
    }
    var variableSelect = document.getElementById('cpbFormVariableSelect');
    if (variableSelect) {
      variableSelect.addEventListener('change', function () {
        var key = variableSelect.value || '';
        variableSelect.value = '';
        if (!key) return;
        var block = selectedFormFieldBlock();
        if (block) {
          var field = block.querySelector('[data-form-field="1"]');
          if (field) {
            field.setAttribute('data-variable-key', key);
            field.setAttribute('data-placeholder', '{{' + key + '}}');
            var labelText = variableSelect.querySelector('option[value="' + key.replace(/"/g, '\\"') + '"]');
            if (labelText && !field.getAttribute('data-label')) {
              field.setAttribute('data-label', labelText.textContent || key);
            }
            var placeholder = field.querySelector('.cpb-form-input-line');
            if (placeholder) placeholder.textContent = '{{' + key + '}}';
            flushSave(block);
            setStatus('Variable bound', 'saved');
          }
        } else {
          createBlock('field', formFieldDefaults('field', key)).then(function () {
            var body = canvasEl.querySelector('[data-blocks-root]');
            var lastBlock = body ? body.querySelector('.cpb-block:last-child') : null;
            if (lastBlock) lastBlock.scrollIntoView({ behavior: 'smooth', block: 'center' });
            setStatus('Variable field added', 'saved');
          }).catch(showError);
        }
      });
    }
    canvasEl.addEventListener('click', function (e) {
      var block = e.target.closest('.cpb-block');
      if (!block) return;
      var type = block.getAttribute('data-block-type') || '';
      if (['field', 'checkbox', 'date', 'signature', 'initial'].indexOf(type) >= 0) {
        formSelectedBlockId = parseInt(block.getAttribute('data-block-id') || '0', 10) || 0;
      } else {
        formSelectedBlockId = 0;
      }
    });
    canvasEl.addEventListener('dblclick', function (e) {
      var block = e.target.closest('.cpb-block');
      if (block) {
        formSelectedBlockId = parseInt(block.getAttribute('data-block-id') || '0', 10) || 0;
        openFormFieldSettings(block);
      }
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

  if (editOutlineBtn) {
    if (documentType === 'form' || isAnnexBook) editOutlineBtn.style.display = 'none';
    editOutlineBtn.addEventListener('click', function () {
      if (state.outlineOpen) closeOutlinePanel();
      else openOutlinePanel();
    });
  }
  if (structCloseBtn) structCloseBtn.addEventListener('click', closeOutlinePanel);
  if (structDoneBtn) structDoneBtn.addEventListener('click', closeOutlinePanel);
  if (outlinePanelEl) {
    outlinePanelEl.addEventListener('click', function (event) {
      if (event.target === outlinePanelEl) closeOutlinePanel();
    });
  }
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && state.outlineOpen) closeOutlinePanel();
  });

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

  if (crossRefDocSelect) {
    crossRefDocSelect.addEventListener('focus', rememberStyleTarget);
    crossRefDocSelect.addEventListener('change', function () {
      populateCrossRefKeySelect(crossRefDocSelect.value || '');
      if (crossRefKeySelect) crossRefKeySelect.value = '';
    });
  }

  if (crossRefKeySelect) {
    crossRefKeySelect.addEventListener('focus', rememberStyleTarget);
    crossRefKeySelect.addEventListener('change', function () {
      if (!crossRefDocSelect || !crossRefDocSelect.value) return;
      applyCrossRef(crossRefDocSelect.value, crossRefKeySelect.value || '');
    });
  }

  if (crossRefClearBtn) {
    crossRefClearBtn.addEventListener('click', function (e) {
      e.preventDefault();
      applyCrossRef('', '');
      if (crossRefDocSelect) crossRefDocSelect.value = '';
      populateCrossRefKeySelect('');
      if (crossRefKeySelect) crossRefKeySelect.value = '';
    });
  }

  if (viewEditBtn) {
    viewEditBtn.addEventListener('click', function () {
      setViewMode('edit').catch(showError);
    });
  }
  if (viewPaginatedBtn) {
    viewPaginatedBtn.addEventListener('click', function () {
      setViewMode('paginated').catch(showError);
    });
  }
  if (paginationRegenerateBtn) {
    paginationRegenerateBtn.addEventListener('click', regeneratePagination);
  }
  if (paginationApproveBtn) {
    paginationApproveBtn.addEventListener('click', approvePagination);
  }
  if (pageBreakBtn) {
    pageBreakBtn.addEventListener('mousedown', function (event) {
      capturePaginatedSelection();
      event.preventDefault();
    });
    pageBreakBtn.addEventListener('click', insertPageBreakAtCursor);
  }
  canvasEl.addEventListener('mouseup', capturePaginatedSelection);
  canvasEl.addEventListener('keyup', capturePaginatedSelection);

  wireTreeToggleAll();
  initCrossRefAnnexSelects();
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      clearTimeout(state.reviewThreadSyncTimer);
    } else {
      loadReviewThreadMarkers();
    }
  });

  if (state.liveProjection.enabled) {
    installLiveProjectionSurface();
    root.addEventListener('cpb:live-pagination-state', observeLiveProjectionState);
  }

  root.__cpbPhaseB = {
    sourceLocation: semanticSourceLocation,
    captureSelection: captureSemanticSelectionBookmark,
    restoreSelection: restoreSemanticSelectionBookmark,
    captureScroll: captureSemanticScrollBookmark,
    restoreScroll: restoreSemanticScrollBookmark,
    captureSurface: captureEditorSurfaceBookmarks,
    restoreSurface: restoreEditorSurfaceBookmarks,
    retryLivePagination: retryLivePagination,
    livePaginationState: function () {
      return Object.assign({}, state.livePagination, {
        activeRequest: !!state.livePagination.activeRequest,
        debounceTimer: !!state.livePagination.debounceTimer,
      });
    },
    refreshLiveProjection: refreshLiveProjection,
    liveProjectionState: function () {
      return Object.assign({}, state.liveProjection);
    },
    projectionRoot: function () {
      return liveProjectionEl;
    },
  };
  root.__cpbPhaseC = {
    enabled: state.liveProjection.enabled,
    refresh: refreshLiveProjection,
    state: function () {
      return Object.assign({}, state.liveProjection);
    },
    root: function () {
      return liveProjectionEl;
    },
  };
  root.__cpbPhaseD = {
    paragraphPortalEnabled: state.liveProjection.enabled,
    activateParagraph: activateProjectedParagraph,
    portals: function () {
      return liveProjectionPagesEl
        ? Array.prototype.slice.call(
          liveProjectionPagesEl.querySelectorAll('.cpb-live-projection__portal')
        )
        : [];
    },
  };
  root.addEventListener('cpb:live-pagination-retry', function () {
    retryLivePagination();
  });

  wireTableToolbar();
  loadCalloutPresets()
    .then(function () { return loadSection(initialSectionId || 0); })
    .then(function () {
      if (initialViewMode === 'paginated') return setViewMode('paginated');
    })
    .catch(showError);
})();
