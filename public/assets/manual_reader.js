(function () {
  'use strict';

  var root = document.getElementById('manualReader');
  if (!root || root.dataset.hasReleased !== '1') {
    return;
  }

  var API = '/student/api/manual_reader_api.php';
  var bookKey = root.dataset.book || 'OM';
  var initialAnchor = root.dataset.anchor || '';

  var tocNav = document.getElementById('mrTocNav');
  var contentEl = document.getElementById('mrContent');
  var mainEl = document.getElementById('mrMain');
  var prevBtn = document.getElementById('mrPrevBtn');
  var nextBtn = document.getElementById('mrNextBtn');
  var tocDrawer = document.getElementById('mrTocDrawer');
  var tocToggle = document.getElementById('mrTocToggle');
  var tocClose = document.getElementById('mrTocClose');
  var overlay = document.getElementById('mrOverlay');
  var searchToggle = document.getElementById('mrSearchToggle');
  var searchPanel = document.getElementById('mrSearchPanel');
  var searchInput = document.getElementById('mrSearchInput');
  var searchResults = document.getElementById('mrSearchResults');

  var state = {
    nav: [],
    currentSectionId: null,
    currentAnchor: '',
    prevSectionId: null,
    nextSectionId: null,
    progressTimer: null,
    searchTimer: null
  };

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

  function setTocOpen(open) {
    if (!tocDrawer) return;
    tocDrawer.classList.toggle('is-open', open);
    if (overlay) {
      overlay.hidden = !open;
    }
    document.body.style.overflow = open && window.matchMedia('(max-width: 900px)').matches ? 'hidden' : '';
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
        sep.setAttribute('role', 'presentation');
        tocNav.appendChild(sep);
        return;
      }

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'mr-toc-item';
      btn.dataset.depth = String(entry.depth);
      btn.textContent = node.title || '';

      if (node.label_style) {
        btn.classList.add('label-' + node.label_style);
      }
      if (node.is_group) {
        btn.classList.add('is-group');
        btn.disabled = true;
      } else if (node.is_navigable && node.id) {
        btn.dataset.sectionId = String(node.id);
        btn.dataset.anchor = node.stable_anchor || '';
        btn.addEventListener('click', function () {
          loadSection(parseInt(btn.dataset.sectionId, 10), btn.dataset.anchor || '');
          setTocOpen(false);
        });
      } else {
        btn.disabled = true;
      }

      if (state.currentSectionId && node.id === state.currentSectionId) {
        btn.classList.add('is-active');
      }

      tocNav.appendChild(btn);
    });
  }

  function updateNavButtons() {
    if (prevBtn) {
      prevBtn.disabled = !state.prevSectionId;
    }
    if (nextBtn) {
      nextBtn.disabled = !state.nextSectionId;
    }
  }

  function highlightToc(sectionId) {
    if (!tocNav) return;
    tocNav.querySelectorAll('.mr-toc-item.is-active').forEach(function (el) {
      el.classList.remove('is-active');
    });
    var active = tocNav.querySelector('[data-section-id="' + sectionId + '"]');
    if (active) {
      active.classList.add('is-active');
      active.scrollIntoView({ block: 'nearest' });
    }
  }

  function scheduleProgressSave() {
    if (state.progressTimer) {
      clearTimeout(state.progressTimer);
    }
    state.progressTimer = setTimeout(saveProgress, 800);
  }

  function scrollPct() {
    if (!mainEl) return 0;
    var el = mainEl;
    var max = el.scrollHeight - el.clientHeight;
    if (max <= 0) return 0;
    return Math.round(Math.min(100, Math.max(0, (el.scrollTop / max) * 100)));
  }

  function saveProgress() {
    if (!state.currentSectionId) return;
    fetchJson(apiUrl('progress_save'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        book_key: bookKey,
        section_id: state.currentSectionId,
        stable_anchor: state.currentAnchor,
        scroll_pct: scrollPct()
      })
    }).catch(function () {
      /* ignore progress write failures */
    });
  }

  function restoreScroll(pct) {
    if (!mainEl || pct == null) return;
    var max = mainEl.scrollHeight - mainEl.clientHeight;
    mainEl.scrollTop = Math.round((pct / 100) * max);
  }

  function loadSection(sectionId, anchor, scrollRestore) {
    if (!sectionId && anchor) {
      return fetchJson(apiUrl('section', { stable_anchor: anchor })).then(handleSectionLoaded);
    }
    return fetchJson(apiUrl('section', { section_id: String(sectionId) })).then(function (data) {
      if (scrollRestore != null) {
        data._scrollRestore = scrollRestore;
      }
      return handleSectionLoaded(data);
    });
  }

  function handleSectionLoaded(data) {
    state.currentSectionId = data.section_id;
    state.currentAnchor = data.stable_anchor || '';
    state.prevSectionId = data.prev_section_id || null;
    state.nextSectionId = data.next_section_id || null;

    if (contentEl) {
      contentEl.innerHTML = data.html || '<div class="mr-error">No content.</div>';
    }

    updateNavButtons();
    highlightToc(state.currentSectionId);

    var hash = state.currentAnchor ? '#' + state.currentAnchor : '';
    if (history.replaceState) {
      history.replaceState(null, '', window.location.pathname + '?book=' + encodeURIComponent(bookKey) + hash);
    }

    if (mainEl) {
      mainEl.scrollTop = 0;
      if (data._scrollRestore != null) {
        requestAnimationFrame(function () {
          restoreScroll(data._scrollRestore);
        });
      }
    }

    scheduleProgressSave();
    return data;
  }

  function initProgress() {
    return fetchJson(apiUrl('progress_get')).then(function (data) {
      var progress = data.progress;
      if (initialAnchor) {
        return loadSection(0, initialAnchor);
      }
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

  function toggleSearch() {
    if (!searchPanel) return;
    var open = searchPanel.hidden;
    searchPanel.hidden = !open;
    if (open && searchInput) {
      searchInput.focus();
    }
  }

  function runSearch(query) {
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
          searchPanel.hidden = true;
          if (searchInput) searchInput.value = '';
          searchResults.innerHTML = '';
        });
        searchResults.appendChild(btn);
      });
      if (!(data.results || []).length) {
        var empty = document.createElement('div');
        empty.className = 'mr-loading';
        empty.textContent = 'No matching sections';
        searchResults.appendChild(empty);
      }
    }).catch(function () {
      searchResults.innerHTML = '<div class="mr-error">Search failed</div>';
    });
  }

  if (prevBtn) {
    prevBtn.addEventListener('click', function () {
      if (state.prevSectionId) {
        loadSection(state.prevSectionId, '');
      }
    });
  }
  if (nextBtn) {
    nextBtn.addEventListener('click', function () {
      if (state.nextSectionId) {
        loadSection(state.nextSectionId, '');
      }
    });
  }
  if (tocToggle) {
    tocToggle.addEventListener('click', function () {
      setTocOpen(!tocDrawer.classList.contains('is-open'));
    });
  }
  if (tocClose) {
    tocClose.addEventListener('click', function () {
      setTocOpen(false);
    });
  }
  if (overlay) {
    overlay.addEventListener('click', function () {
      setTocOpen(false);
    });
  }
  if (searchToggle) {
    searchToggle.addEventListener('click', toggleSearch);
  }
  if (searchInput) {
    searchInput.addEventListener('input', function () {
      if (state.searchTimer) clearTimeout(state.searchTimer);
      var q = searchInput.value.trim();
      state.searchTimer = setTimeout(function () {
        runSearch(q);
      }, 250);
    });
  }
  if (mainEl) {
    mainEl.addEventListener('scroll', scheduleProgressSave, { passive: true });
  }

  window.addEventListener('hashchange', function () {
    var anchor = (window.location.hash || '').replace(/^#/, '');
    if (anchor && anchor !== state.currentAnchor) {
      loadSection(0, anchor);
    }
  });

  if (!initialAnchor && window.location.hash) {
    initialAnchor = window.location.hash.replace(/^#/, '');
  }

  Promise.all([initNav(), initProgress()]).catch(function (err) {
    if (contentEl) {
      contentEl.innerHTML = '<div class="mr-error">' + (err.message || 'Failed to load manual') + '</div>';
    }
  });
})();
