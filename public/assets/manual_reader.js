(function () {
  'use strict';

  var STORAGE_KEY = 'ipca_manual_reader_settings';
  var root = document.getElementById('manualReader');
  if (!root || root.dataset.hasReleased !== '1') {
    return;
  }

  var API = '/student/api/manual_reader_api.php';
  var bookKey = root.dataset.book || 'OM';
  var initialAnchor = root.dataset.anchor || '';

  var tocNav = document.getElementById('mrTocNav');
  var tocDrawer = document.getElementById('mrTocDrawer');
  var pageContent = document.getElementById('mrPageContent');
  var pageFrame = document.getElementById('mrPageFrame');
  var pageMeta = document.getElementById('mrPageMeta');
  var pageFooter = document.getElementById('mrPageFooter');
  var pagePrev = document.getElementById('mrPagePrev');
  var pageNext = document.getElementById('mrPageNext');
  var measureHost = document.getElementById('mrMeasureHost');
  var overlay = document.getElementById('mrOverlay');

  var state = {
    nav: [],
    section: null,
    pages: [],
    pageIndex: 0,
    tokenContext: {},
    progressTimer: null,
    searchTimer: null,
    openPanel: null
  };

  var settings = loadSettings();

  function loadSettings() {
    var defaults = { fontSize: 'normal', theme: 'light', pageWidth: 'normal' };
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return defaults;
      var parsed = JSON.parse(raw);
      return {
        fontSize: parsed.fontSize || defaults.fontSize,
        theme: parsed.theme || defaults.theme,
        pageWidth: parsed.pageWidth || defaults.pageWidth
      };
    } catch (e) {
      return defaults;
    }
  }

  function saveSettings() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
    } catch (e) { /* ignore */ }
  }

  function applySettings() {
    document.body.setAttribute('data-mr-theme', settings.theme);
    document.body.setAttribute('data-mr-font', settings.fontSize);
    document.body.setAttribute('data-mr-width', settings.pageWidth);
    var fs = document.getElementById('mrSettingFontSize');
    var th = document.getElementById('mrSettingTheme');
    var pw = document.getElementById('mrSettingPageWidth');
    if (fs) fs.value = settings.fontSize;
    if (th) th.value = settings.theme;
    if (pw) pw.value = settings.pageWidth;
  }

  function fetchJson(url, options) {
    return fetch(url, options || {}).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok || !data.ok) {
          throw new Error((data && data.error) || 'Request failed');
        }
        return data;
      });
    });
  }

  function apiUrl(action, params) {
    var qs = new URLSearchParams(params || {});
    qs.set('action', action);
    qs.set('book', bookKey);
    return API + '?' + qs.toString();
  }

  function closeAllPanels() {
    ['mrSearchPanel', 'mrSettingsPanel'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.hidden = true;
    });
    if (tocDrawer) {
      tocDrawer.classList.remove('is-open');
      tocDrawer.hidden = true;
    }
    if (overlay) overlay.hidden = true;
    document.body.style.overflow = '';
    state.openPanel = null;
  }

  function openToc() {
    closeAllPanels();
    if (!tocDrawer) return;
    tocDrawer.hidden = false;
    tocDrawer.classList.add('is-open');
    if (overlay) overlay.hidden = false;
    state.openPanel = 'toc';
  }

  function openPanel(id) {
    closeAllPanels();
    var el = document.getElementById(id);
    if (!el) return;
    el.hidden = false;
    state.openPanel = id;
    if (id === 'mrSearchPanel') {
      var inp = document.getElementById('mrSearchInput');
      if (inp) inp.focus();
    }
  }

  function stripRawTokens(text) {
    if (!text) return text;
    return text
      .replace(/\{\{[a-z_]+\}\}/g, '—')
      .replace(/\{[a-z_]+\}/g, '—');
  }

  function applyTokensToHtml(html, ctx, pageNum, pageTotal) {
    var merged = Object.assign({}, ctx || {});
    merged.page = String(pageNum || 1);
    merged.page_total = String(pageTotal || pageNum || 1);
    Object.keys(merged).forEach(function (key) {
      var val = merged[key];
      if (val === '' || val == null) val = '—';
      html = html.split('{{' + key + '}}').join(val);
      html = html.split('{' + key + '}').join(val);
    });
    return stripRawTokens(html);
  }

  var UNBREAKABLE = '.cpb-block--table, .cpb-block--image, .cpb-block--callout, .cpb-table, figure.cpb-image, .cpb-callout';

  function collectBlocks(container) {
    var blocks = [];
    var seen = new Set();
    container.querySelectorAll('.cpb-block').forEach(function (el) {
      if (seen.has(el)) return;
      seen.add(el);
      blocks.push(el.cloneNode(true));
    });
    if (blocks.length === 0 && container.innerHTML.trim() !== '') {
      blocks.push(container.cloneNode(true));
    }
    return blocks;
  }

  function measurePageCapacity() {
    if (!pageContent || !measureHost) return 400;
    measureHost.innerHTML = '';
    measureHost.style.width = pageContent.clientWidth + 'px';
    var probe = document.createElement('div');
    probe.style.visibility = 'hidden';
    probe.style.height = '1px';
    measureHost.appendChild(probe);
    var contentHeight = pageContent.clientHeight;
    return Math.max(200, contentHeight - 8);
  }

  function paginateHtml(html, isCover) {
    if (isCover) {
      return [{ type: 'cover', html: html }];
    }

    var wrap = document.createElement('div');
    wrap.innerHTML = html;

    var sheet = wrap.querySelector('.cpb-sheet') || wrap;
    var headerEl = sheet.querySelector('.cpb-page-header');
    var footerEl = sheet.querySelector('.cpb-page-footer');
    var bodyEl = sheet.querySelector('.cpb-sheet-body')
      || sheet.querySelector('.cpb-part0-admin-body')
      || sheet.querySelector('.cpb-lep-body')
      || sheet;

    var headerHtml = headerEl ? headerEl.outerHTML : '';
    var footerHtml = footerEl ? footerEl.outerHTML : '';
    var blocks = collectBlocks(bodyEl);

    if (blocks.length === 0) {
      return [{ type: 'content', headerHtml: headerHtml, footerHtml: footerHtml, bodyHtml: bodyEl.innerHTML }];
    }

    var capacity = measurePageCapacity();
    var pages = [];
    var current = [];
    var currentMeasure = document.createElement('div');
    currentMeasure.style.width = (pageContent ? pageContent.clientWidth : 600) + 'px';

    function flushPage() {
      if (current.length === 0) return;
      var bodyDiv = document.createElement('div');
      current.forEach(function (node) { bodyDiv.appendChild(node.cloneNode(true)); });
      pages.push({
        type: 'content',
        headerHtml: headerHtml,
        footerHtml: footerHtml,
        bodyHtml: bodyDiv.innerHTML
      });
      current = [];
      currentMeasure.innerHTML = '';
    }

    blocks.forEach(function (block) {
      var clone = block.cloneNode(true);
      currentMeasure.appendChild(clone);
      if (currentMeasure.offsetHeight > capacity && current.length > 0) {
        currentMeasure.removeChild(clone);
        flushPage();
        current.push(block.cloneNode(true));
        currentMeasure.appendChild(block.cloneNode(true));
      } else {
        current.push(block);
      }
    });
    flushPage();

    if (pages.length === 0) {
      pages.push({ type: 'content', headerHtml: headerHtml, footerHtml: footerHtml, bodyHtml: bodyEl.innerHTML });
    }

    return pages;
  }

  function renderCurrentPage() {
    if (!pageContent || state.pages.length === 0) return;

    var page = state.pages[state.pageIndex];
    var ctx = state.tokenContext || {};
    var total = state.pages.length;
    var num = state.pageIndex + 1;

    if (pageFrame) {
      pageFrame.classList.toggle('is-cover', page.type === 'cover');
    }

    if (page.type === 'cover') {
      pageContent.innerHTML = applyTokensToHtml(page.html, ctx, 1, 1);
      if (pageMeta) pageMeta.textContent = '';
      if (pageFooter) pageFooter.textContent = '';
    } else {
      var header = applyTokensToHtml(page.headerHtml || '', ctx, num, total);
      var body = applyTokensToHtml(page.bodyHtml || '', ctx, num, total);
      var footer = applyTokensToHtml(page.footerHtml || '', ctx, num, total);
      pageContent.innerHTML = header + '<div class="mr-page-body">' + body + '</div>' + footer;
      if (pageMeta && state.section) {
        pageMeta.textContent = state.section.section_title || '';
      }
      if (pageFooter) {
        pageFooter.textContent = 'Page ' + num + ' of ' + total;
      }
    }

    if (pagePrev) {
      pagePrev.disabled = state.pageIndex <= 0 && !(state.section && state.section.prev_section_id);
    }
    if (pageNext) {
      pageNext.disabled = state.pageIndex >= state.pages.length - 1 && !(state.section && state.section.next_section_id);
    }

    scheduleProgressSave();
  }

  function goNextPage() {
    if (state.pageIndex < state.pages.length - 1) {
      state.pageIndex++;
      renderCurrentPage();
      return;
    }
    if (state.section && state.section.next_section_id) {
      loadSection(state.section.next_section_id, '');
    }
  }

  function goPrevPage() {
    if (state.pageIndex > 0) {
      state.pageIndex--;
      renderCurrentPage();
      return;
    }
    if (state.section && state.section.prev_section_id) {
      loadSection(state.section.prev_section_id, '', 'last');
    }
  }

  function flattenNav(nodes, depth, out) {
    depth = depth || 0;
    out = out || [];
    (nodes || []).forEach(function (node) {
      out.push({ node: node, depth: depth });
      if (node.children && node.children.length) {
        flattenNav(node.children, depth + 1, out);
      }
    });
    return out;
  }

  function renderToc(nav) {
    if (!tocNav) return;
    tocNav.innerHTML = '';
    flattenNav(nav).forEach(function (entry) {
      var node = entry.node;
      if (node.is_separator) {
        var sep = document.createElement('div');
        sep.className = 'mr-toc-item is-separator';
        tocNav.appendChild(sep);
        return;
      }
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'mr-toc-item';
      btn.dataset.depth = String(entry.depth);
      btn.textContent = node.title || '';
      if (node.is_group) {
        btn.classList.add('is-group');
        btn.disabled = true;
      } else if (node.is_navigable && node.id) {
        btn.dataset.sectionId = String(node.id);
        btn.addEventListener('click', function () {
          loadSection(parseInt(btn.dataset.sectionId, 10), '');
          closeAllPanels();
        });
      } else {
        btn.disabled = true;
      }
      if (state.section && node.id === state.section.section_id) {
        btn.classList.add('is-active');
      }
      tocNav.appendChild(btn);
    });
  }

  function scheduleProgressSave() {
    if (state.progressTimer) clearTimeout(state.progressTimer);
    state.progressTimer = setTimeout(saveProgress, 800);
  }

  function saveProgress() {
    if (!state.section) return;
    var pct = state.pages.length <= 1 ? 0 : Math.round((state.pageIndex / (state.pages.length - 1)) * 100);
    fetchJson(apiUrl('progress_save'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        book_key: bookKey,
        section_id: state.section.section_id,
        stable_anchor: state.section.stable_anchor || '',
        scroll_pct: pct
      })
    }).catch(function () { /* ignore */ });
  }

  function loadSection(sectionId, anchor, scrollRestore) {
    var promise;
    if (!sectionId && anchor) {
      promise = fetchJson(apiUrl('section', { stable_anchor: anchor }));
    } else {
      promise = fetchJson(apiUrl('section', { section_id: String(sectionId) }));
    }
    return promise.then(function (data) {
      if (scrollRestore != null) {
        data._scrollRestore = scrollRestore;
      }
      return handleSectionLoaded(data, scrollRestore === 'last' ? 'last' : null);
    });
  }

  function handleSectionLoaded(data, pageHint) {
    state.section = data;
    state.tokenContext = data.token_context || {};
    state.pages = paginateHtml(data.html || '', !!data.is_cover);
    state.pageIndex = 0;

    if (pageHint === 'last') {
      state.pageIndex = Math.max(0, state.pages.length - 1);
    } else if (pageHint != null && typeof pageHint === 'number') {
      state.pageIndex = Math.min(pageHint, Math.max(0, state.pages.length - 1));
    } else if (data._scrollRestore != null && state.pages.length > 1) {
      state.pageIndex = Math.round((data._scrollRestore / 100) * (state.pages.length - 1));
    }

    renderCurrentPage();
    renderToc(state.nav);

    var hash = data.stable_anchor ? '#' + data.stable_anchor : '';
    if (history.replaceState) {
      history.replaceState(null, '', window.location.pathname + '?book=' + encodeURIComponent(bookKey) + hash);
    }

    return data;
  }

  function initProgress() {
    return fetchJson(apiUrl('progress_get')).then(function (data) {
      if (initialAnchor) {
        return loadSection(0, initialAnchor);
      }
      var progress = data.progress;
      if (progress && progress.section_id) {
        return loadSection(parseInt(progress.section_id, 10), progress.stable_anchor || '', progress.scroll_pct);
      }
      var defaultId = data.default_section_id;
      if (defaultId) {
        return loadSection(defaultId, '');
      }
      throw new Error('No section to display');
    });
  }

  function initNav() {
    return fetchJson(apiUrl('nav')).then(function (data) {
      state.nav = data.nav || [];
      renderToc(state.nav);
    });
  }

  function runSearch(query) {
    var searchResults = document.getElementById('mrSearchResults');
    if (!searchResults) return;
    if (!query) {
      searchResults.innerHTML = '';
      return;
    }
    fetchJson(apiUrl('search_titles', { q: query })).then(function (data) {
      searchResults.innerHTML = '';
      (data.results || []).forEach(function (row) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'mr-search-result';
        btn.textContent = row.section_title || 'Section';
        btn.addEventListener('click', function () {
          loadSection(row.section_id, row.stable_anchor || '');
          closeAllPanels();
          var inp = document.getElementById('mrSearchInput');
          if (inp) inp.value = '';
          searchResults.innerHTML = '';
        });
        searchResults.appendChild(btn);
      });
      if (!(data.results || []).length) {
        searchResults.innerHTML = '<div class="mr-loading">No matching sections</div>';
      }
    }).catch(function () {
      searchResults.innerHTML = '<div class="mr-error">Search failed</div>';
    });
  }

  /* Event wiring */
  applySettings();

  var backBtn = document.getElementById('mrBackBtn');
  if (backBtn) {
    backBtn.addEventListener('click', function () {
      if (window.history.length > 1) {
        window.history.back();
      } else {
        window.location.href = '/student/manuals.php';
      }
    });
  }

  var tocToggle = document.getElementById('mrTocToggle');
  var tocClose = document.getElementById('mrTocClose');
  if (tocToggle) {
    tocToggle.addEventListener('click', function () {
      if (tocDrawer && tocDrawer.classList.contains('is-open')) {
        closeAllPanels();
      } else {
        openToc();
      }
    });
  }
  if (tocClose) tocClose.addEventListener('click', closeAllPanels);
  if (overlay) overlay.addEventListener('click', closeAllPanels);

  var searchToggle = document.getElementById('mrSearchToggle');
  var searchInput = document.getElementById('mrSearchInput');
  if (searchToggle) {
    searchToggle.addEventListener('click', function () {
      var panel = document.getElementById('mrSearchPanel');
      if (panel && !panel.hidden) closeAllPanels();
      else openPanel('mrSearchPanel');
    });
  }
  if (searchInput) {
    searchInput.addEventListener('input', function () {
      if (state.searchTimer) clearTimeout(state.searchTimer);
      var q = searchInput.value.trim();
      state.searchTimer = setTimeout(function () { runSearch(q); }, 250);
    });
  }

  var settingsToggle = document.getElementById('mrSettingsToggle');
  if (settingsToggle) {
    settingsToggle.addEventListener('click', function () {
      var panel = document.getElementById('mrSettingsPanel');
      if (panel && !panel.hidden) closeAllPanels();
      else openPanel('mrSettingsPanel');
    });
  }

  ['mrSettingFontSize', 'mrSettingTheme', 'mrSettingPageWidth'].forEach(function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('change', function () {
      if (id === 'mrSettingFontSize') settings.fontSize = el.value;
      if (id === 'mrSettingTheme') settings.theme = el.value;
      if (id === 'mrSettingPageWidth') settings.pageWidth = el.value;
      saveSettings();
      applySettings();
      if (state.section) {
        state.pages = paginateHtml(state.section.html || '', !!state.section.is_cover);
        state.pageIndex = Math.min(state.pageIndex, Math.max(0, state.pages.length - 1));
        renderCurrentPage();
      }
    });
  });

  if (pagePrev) pagePrev.addEventListener('click', goPrevPage);
  if (pageNext) pageNext.addEventListener('click', goNextPage);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeAllPanels();
      return;
    }
    var tag = (e.target && e.target.tagName) || '';
    if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') return;

    if (e.key === 'ArrowRight') {
      e.preventDefault();
      goNextPage();
    } else if (e.key === 'ArrowLeft') {
      e.preventDefault();
      goPrevPage();
    }
  });

  window.addEventListener('hashchange', function () {
    var anchor = (window.location.hash || '').replace(/^#/, '');
    if (anchor && state.section && anchor !== state.section.stable_anchor) {
      loadSection(0, anchor);
    }
  });

  window.addEventListener('resize', function () {
    if (!state.section) return;
    var idx = state.pageIndex;
    state.pages = paginateHtml(state.section.html || '', !!state.section.is_cover);
    state.pageIndex = Math.min(idx, Math.max(0, state.pages.length - 1));
    renderCurrentPage();
  });

  if (!initialAnchor && window.location.hash) {
    initialAnchor = window.location.hash.replace(/^#/, '');
  }

  Promise.all([initNav(), initProgress()]).catch(function (err) {
    if (pageContent) {
      pageContent.innerHTML = '<div class="mr-error">' + (err.message || 'Failed to load manual') + '</div>';
    }
  });
})();
