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
  var filmstripTrack = document.getElementById('mrFilmstripTrack');
  var filmstrip = document.getElementById('mrFilmstrip');
  var overlay = document.getElementById('mrOverlay');
  var pageViewport = document.getElementById('mrPageViewport');

  var state = {
    pageMap: null,
    pages: [],
    pageHtmlCache: {},
    sectionPageIndex: {},
    nav: [],
    pageIndex: 0,
    pageCount: 0,
    progressTimer: null,
    searchTimer: null,
    openPanel: null,
    loadingPage: false
  };

  var settings = loadSettings();

  function loadSettings() {
    var defaults = { theme: 'light', zoom: 'fit-width', filmstrip: true };
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return defaults;
      var parsed = JSON.parse(raw);
      return {
        theme: parsed.theme || defaults.theme,
        zoom: parsed.zoom || defaults.zoom,
        filmstrip: parsed.filmstrip !== false
      };
    } catch (e) {
      return defaults;
    }
  }

  function saveSettings() {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(settings)); } catch (e) { /* ignore */ }
  }

  function applySettings() {
    document.body.setAttribute('data-mr-theme', settings.theme);
    document.body.setAttribute('data-mr-zoom', settings.zoom);
    document.body.setAttribute('data-mr-filmstrip', settings.filmstrip ? 'on' : 'off');
    var th = document.getElementById('mrSettingTheme');
    var zm = document.getElementById('mrSettingZoom');
    var fs = document.getElementById('mrSettingFilmstrip');
    if (th) th.value = settings.theme;
    if (zm) zm.value = settings.zoom;
    if (fs) fs.checked = settings.filmstrip;
    if (filmstrip && filmstrip.parentElement) {
      filmstrip.parentElement.hidden = !settings.filmstrip;
    }
    applyViewportZoom();
  }

  function applyViewportZoom() {
    if (!pageFrame) return;
    pageFrame.classList.remove('is-fit-width', 'is-fit-page', 'is-zoom-75', 'is-zoom-100', 'is-zoom-125');
    if (settings.zoom === 'fit-width') pageFrame.classList.add('is-fit-width');
    else if (settings.zoom === 'fit-page') pageFrame.classList.add('is-fit-page');
    else if (settings.zoom === '75') pageFrame.classList.add('is-zoom-75');
    else if (settings.zoom === '125') pageFrame.classList.add('is-zoom-125');
    else pageFrame.classList.add('is-zoom-100');
  }

  function fetchJson(url, options) {
    return fetch(url, options || {}).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok || !data.ok) throw new Error((data && data.error) || 'Request failed');
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
    return text.replace(/\{\{[a-z_]+\}\}/g, '—').replace(/\{[a-z_]+\}/g, '—');
  }

  function fetchFrozenPage(pageNumber) {
    if (state.pageHtmlCache[pageNumber]) {
      return Promise.resolve(state.pageHtmlCache[pageNumber]);
    }
    return fetchJson(apiUrl('page', { page_number: pageNumber })).then(function (data) {
      state.pageHtmlCache[pageNumber] = data;
      return data;
    });
  }

  function renderCurrentPage() {
    if (!pageContent || !state.pages.length) return;
    var page = state.pages[state.pageIndex];
    if (!page) return;

    if (pageFrame) {
      pageFrame.classList.toggle('is-cover', !!page.is_cover);
    }

    pageContent.innerHTML = '<div class="mr-loading">Loading page…</div>';
    fetchFrozenPage(page.page_number).then(function (data) {
      pageContent.innerHTML =
        '<div class="mr-frozen-viewport">' + (data.page_html || '') + '</div>';
      if (pageMeta) {
        pageMeta.textContent = page.is_cover ? '' : (data.section_title || page.section_title || '');
      }
      if (pageFooter) {
        pageFooter.textContent = page.is_cover
          ? ''
          : ('Page ' + page.page_number + ' of ' + state.pageCount);
      }
      if (pagePrev) pagePrev.disabled = state.pageIndex <= 0;
      if (pageNext) pageNext.disabled = state.pageIndex >= state.pages.length - 1;
      highlightFilmstrip();
      scheduleProgressSave();
    }).catch(function (err) {
      pageContent.innerHTML = '<div class="mr-error">' + (err.message || 'Failed to load page') + '</div>';
    });
  }

  function goToPage(index) {
    if (index < 0 || index >= state.pages.length) return;
    state.pageIndex = index;
    renderCurrentPage();
    centerFilmstripThumb();
  }

  function goNextPage() {
    if (state.pageIndex < state.pages.length - 1) goToPage(state.pageIndex + 1);
  }

  function goPrevPage() {
    if (state.pageIndex > 0) goToPage(state.pageIndex - 1);
  }

  function goToSection(sectionId) {
    var pageNum = state.sectionPageIndex[sectionId];
    if (pageNum) goToPage(pageNum - 1);
  }

  function flattenNav(nodes, depth, out) {
    depth = depth || 0;
    out = out || [];
    (nodes || []).forEach(function (node) {
      out.push({ node: node, depth: depth });
      if (node.children && node.children.length) flattenNav(node.children, depth + 1, out);
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
      var row = document.createElement('div');
      row.className = 'mr-toc-row';
      row.dataset.depth = String(entry.depth);

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'mr-toc-item';
      btn.textContent = node.title || '';
      if (node.is_group) {
        btn.classList.add('is-group');
        btn.disabled = true;
      } else if (node.is_navigable && node.id) {
        btn.dataset.sectionId = String(node.id);
        btn.addEventListener('click', function () {
          goToSection(parseInt(btn.dataset.sectionId, 10));
          closeAllPanels();
        });
        var pg = state.sectionPageIndex[node.id];
        if (pg) {
          var pageLabel = document.createElement('span');
          pageLabel.className = 'mr-toc-page-num';
          pageLabel.textContent = String(pg);
          row.appendChild(btn);
          row.appendChild(pageLabel);
          var activePage = state.pages[state.pageIndex];
          if (activePage && activePage.section_id === node.id) btn.classList.add('is-active');
          tocNav.appendChild(row);
          return;
        }
      } else {
        btn.disabled = true;
      }
      row.appendChild(btn);
      tocNav.appendChild(row);
    });
  }

  function buildFilmstrip() {
    if (!filmstripTrack) return;
    filmstripTrack.innerHTML = '';
    state.pages.forEach(function (page, idx) {
      var item = document.createElement('button');
      item.type = 'button';
      item.className = 'mr-filmstrip-item';
      item.dataset.pageIndex = String(idx);
      if (page.is_cover) item.classList.add('is-cover');
      if (page.is_section_start) item.classList.add('is-section-start');
      if (page.is_major_section_start) item.classList.add('is-major-start');

      var mini = document.createElement('div');
      mini.className = 'mr-filmstrip-mini';
      if (page.thumbnail_html) {
        mini.innerHTML = page.thumbnail_html;
      } else if (page.is_cover) {
        mini.innerHTML = '<div class="mr-filmstrip-cover-label">Cover</div>';
      } else {
        mini.innerHTML = '<div class="mr-filmstrip-cover-label">' + page.page_number + '</div>';
      }
      item.appendChild(mini);

      var num = document.createElement('span');
      num.className = 'mr-filmstrip-num';
      num.textContent = String(page.page_number);
      item.appendChild(num);

      item.addEventListener('click', function () {
        goToPage(parseInt(item.dataset.pageIndex, 10));
      });
      filmstripTrack.appendChild(item);
    });
  }

  function highlightFilmstrip() {
    if (!filmstripTrack) return;
    filmstripTrack.querySelectorAll('.mr-filmstrip-item').forEach(function (el) {
      el.classList.toggle('is-active', parseInt(el.dataset.pageIndex, 10) === state.pageIndex);
    });
  }

  function centerFilmstripThumb() {
    if (!filmstrip || !filmstripTrack) return;
    var active = filmstripTrack.querySelector('.mr-filmstrip-item.is-active');
    if (!active) return;
    var left = active.offsetLeft - (filmstrip.clientWidth / 2) + (active.clientWidth / 2);
    filmstrip.scrollTo({ left: Math.max(0, left), behavior: 'smooth' });
  }

  function scheduleProgressSave() {
    if (state.progressTimer) clearTimeout(state.progressTimer);
    state.progressTimer = setTimeout(saveProgress, 800);
  }

  function saveProgress() {
    var page = state.pages[state.pageIndex];
    if (!page) return;
    fetchJson(apiUrl('progress_save'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        book_key: bookKey,
        section_id: page.section_id,
        stable_anchor: page.stable_anchor || '',
        page_number: page.page_number
      })
    }).catch(function () { /* ignore */ });
  }

  function initReader() {
    return Promise.all([
      fetchJson(apiUrl('page_map')),
      fetchJson(apiUrl('toc_with_pages'))
    ]).then(function (results) {
      var pageMap = results[0];
      var toc = results[1];
      state.pageMap = pageMap;
      state.pages = pageMap.pages || [];
      state.pageCount = pageMap.page_count || state.pages.length;
      state.sectionPageIndex = toc.section_page_index || {};
      state.nav = toc.nav || [];
      buildFilmstrip();
      renderToc(state.nav);

      var startPage = 0;
      return fetchJson(apiUrl('progress_get')).then(function (prog) {
        if (initialAnchor) {
          var match = state.pages.find(function (p) { return p.stable_anchor === initialAnchor; });
          if (match) startPage = match.page_number - 1;
        } else if (prog.progress && prog.progress.scroll_pct > 0) {
          startPage = Math.min(prog.progress.scroll_pct - 1, state.pages.length - 1);
        }
        goToPage(Math.max(0, startPage));
      }, function () {
        goToPage(0);
      });
    });
  }

  function runSearch(query) {
    var searchResults = document.getElementById('mrSearchResults');
    if (!searchResults) return;
    if (!query) { searchResults.innerHTML = ''; return; }
    fetchJson(apiUrl('search_titles', { q: query })).then(function (data) {
      searchResults.innerHTML = '';
      (data.results || []).forEach(function (row) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'mr-search-result';
        var pg = state.sectionPageIndex[row.section_id];
        btn.textContent = (row.section_title || 'Section') + (pg ? (' · p.' + pg) : '');
        btn.addEventListener('click', function () {
          goToSection(row.section_id);
          closeAllPanels();
        });
        searchResults.appendChild(btn);
      });
      if (!(data.results || []).length) {
        searchResults.innerHTML = '<div class="mr-loading">No matching sections</div>';
      }
    });
  }

  applySettings();

  document.getElementById('mrBackBtn') && document.getElementById('mrBackBtn').addEventListener('click', function () {
    window.history.length > 1 ? window.history.back() : (window.location.href = '/student/manuals.php');
  });

  var tocToggle = document.getElementById('mrTocToggle');
  document.getElementById('mrTocClose') && document.getElementById('mrTocClose').addEventListener('click', closeAllPanels);
  if (tocToggle) tocToggle.addEventListener('click', function () {
    tocDrawer && tocDrawer.classList.contains('is-open') ? closeAllPanels() : openToc();
  });
  overlay && overlay.addEventListener('click', closeAllPanels);

  document.getElementById('mrSearchToggle') && document.getElementById('mrSearchToggle').addEventListener('click', function () {
    var panel = document.getElementById('mrSearchPanel');
    panel && !panel.hidden ? closeAllPanels() : openPanel('mrSearchPanel');
  });

  document.getElementById('mrSettingsToggle') && document.getElementById('mrSettingsToggle').addEventListener('click', function () {
    var panel = document.getElementById('mrSettingsPanel');
    panel && !panel.hidden ? closeAllPanels() : openPanel('mrSettingsPanel');
  });

  var searchInput = document.getElementById('mrSearchInput');
  searchInput && searchInput.addEventListener('input', function () {
    if (state.searchTimer) clearTimeout(state.searchTimer);
    var q = searchInput.value.trim();
    state.searchTimer = setTimeout(function () { runSearch(q); }, 250);
  });

  ['mrSettingTheme', 'mrSettingZoom'].forEach(function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('change', function () {
      if (id === 'mrSettingTheme') settings.theme = el.value;
      if (id === 'mrSettingZoom') settings.zoom = el.value;
      saveSettings();
      applySettings();
    });
  });

  var filmstripToggle = document.getElementById('mrSettingFilmstrip');
  if (filmstripToggle) {
    filmstripToggle.addEventListener('change', function () {
      settings.filmstrip = filmstripToggle.checked;
      saveSettings();
      applySettings();
    });
  }

  pagePrev && pagePrev.addEventListener('click', goPrevPage);
  pageNext && pageNext.addEventListener('click', goNextPage);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeAllPanels(); return; }
    var tag = (e.target && e.target.tagName) || '';
    if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') return;
    if (e.key === 'ArrowRight') { e.preventDefault(); goNextPage(); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); goPrevPage(); }
  });

  window.addEventListener('resize', function () {
    applyViewportZoom();
  });

  if (!initialAnchor && window.location.hash) {
    initialAnchor = window.location.hash.replace(/^#/, '');
  }

  initReader().catch(function (err) {
    if (pageContent) pageContent.innerHTML = '<div class="mr-error">' + (err.message || 'Failed to load manual') + '</div>';
  });
})();
