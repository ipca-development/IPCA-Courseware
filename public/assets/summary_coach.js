/*
 * Maya Summary Coach (TEST/v3 feature)
 *
 * Plain JavaScript module shared by:
 *   - /public/player/slide_v3.php
 *   - /public/student/lesson_summaries_v3.php
 *
 * Public API:
 *   window.IPCASummaryCoach.create(opts)        → instance
 *   window.IPCASummaryCoach.attach(rootEl, opts) → attaches to existing DOM
 *
 * Each PHP page sets `window.IPCASummaryCoachConfig` with at least:
 *   {
 *     apiUrl: "/student/api/summary_coach.php",
 *     context: "player" | "lesson_summaries",
 *     lessonId: <int>,
 *     cohortId: <int>,
 *     summaryId: <int|null>,
 *     editorSelector: "<css selector for the contenteditable / textarea>",
 *     // optional:
 *     csrfToken: "...",
 *     initialStage: "structure",
 *     initialScores: {...},
 *     mode: "compact" | "expanded",
 *     onCanonicalAccept: function(canonicalCheckResult) { ... }
 *   }
 *
 * No jQuery, no framework. Listens to keyboard / paste / idle on the
 * editor element. Calls the backend only on real triggers (button click,
 * student answer, large paste, idle pause after meaningful writing,
 * stage change). Server is the source of truth for scores + readiness;
 * the readiness button is gated by what the server returns.
 */

(function (global) {
  'use strict';

  if (global.IPCASummaryCoach) {
    return;
  }

  // ---------------------------------------------------------------------
  // Constants
  // ---------------------------------------------------------------------

  // Local observer thresholds.
  var PASTE_BURST_CHARS_THRESHOLD = 350; // chars inserted within 1s window
  var PASTE_BURST_WINDOW_MS = 1000;
  var IDLE_AFTER_TYPING_MS = 4500;       // pause length that triggers a checkpoint
  var MIN_NEW_TEXT_FOR_IDLE_CHECK = 60;  // chars added since last checkpoint
  var MIN_INTERVAL_BETWEEN_CHECKPOINTS_MS = 7000;
  var WALL_OF_TEXT_MIN_CHARS = 700;      // single block w/o paragraph breaks
  var SUMMARY_EXCERPT_MAX_CHARS = 1800;

  var STAGE_LABELS = {
    structure: 'Build the structure',
    explain: 'Explain in your own words',
    correlate: 'Connect the concepts',
    operational_example: 'Apply it in flight',
    readiness: 'Final check with Maya',
    final_review: 'Final review'
  };

  var SCORE_PASS_THRESHOLDS = {
    coverage: 80,
    accuracy: 75,
    own_wording: 75,
    correlation: 70,
    instructor_confidence: 75
  };

  var SCORE_LABELS = {
    coverage: 'Coverage',
    accuracy: 'Accuracy',
    own_wording: 'Own wording',
    correlation: 'Correlation',
    instructor_confidence: 'Instructor confidence'
  };

  // ---------------------------------------------------------------------
  // Utilities
  // ---------------------------------------------------------------------

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }
  function $$(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }
  function el(tag, opts) {
    var node = document.createElement(tag);
    if (!opts) return node;
    if (opts.cls) node.className = opts.cls;
    if (opts.text != null) node.textContent = opts.text;
    if (opts.html != null) node.innerHTML = opts.html;
    if (opts.attrs) {
      Object.keys(opts.attrs).forEach(function (k) {
        node.setAttribute(k, String(opts.attrs[k]));
      });
    }
    return node;
  }

  function debounce(fn, ms) {
    var t = null;
    return function () {
      var args = arguments;
      var ctx = this;
      if (t) clearTimeout(t);
      t = setTimeout(function () { fn.apply(ctx, args); }, ms);
    };
  }

  function nowMs() { return Date.now(); }

  function clampInt(v, min, max) {
    v = parseInt(v, 10);
    if (isNaN(v)) return min;
    if (v < min) return min;
    if (v > max) return max;
    return v;
  }

  function plainTextFromEditor(editor) {
    if (!editor) return '';
    if (editor.value !== undefined && editor.tagName === 'TEXTAREA') {
      return String(editor.value || '');
    }
    if (editor.isContentEditable || editor.getAttribute('contenteditable')) {
      var clone = editor.cloneNode(true);
      // Replace block-level closing with newline so paragraph_count works.
      var html = clone.innerHTML
        .replace(/<\s*\/(p|div|li|h[1-6]|blockquote|br)\s*>/gi, '\n')
        .replace(/<br\s*\/?>/gi, '\n');
      clone.innerHTML = html;
      var txt = (clone.textContent || clone.innerText || '');
      return txt;
    }
    return String(editor.textContent || '');
  }

  function htmlFromEditor(editor) {
    if (!editor) return '';
    if (editor.tagName === 'TEXTAREA') return '';
    return String(editor.innerHTML || '');
  }

  function countWords(s) {
    s = String(s || '').trim();
    if (!s) return 0;
    return s.split(/\s+/).length;
  }

  function countParagraphs(s) {
    s = String(s || '').trim();
    if (!s) return 0;
    var parts = s.split(/\n{1,}/).filter(function (p) {
      return p.trim().length > 0;
    });
    return parts.length;
  }

  function sliceFromEnd(s, n) {
    s = String(s || '');
    if (s.length <= n) return s;
    return s.substring(s.length - n);
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function safeMayaMessageHtml(s) {
    var html = escapeHtml(s).replace(/\r\n|\r|\n/g, '<br>');
    var allowed = [
      ['&lt;strong&gt;', '<strong>'],
      ['&lt;/strong&gt;', '</strong>'],
      ['&lt;u&gt;', '<u>'],
      ['&lt;/u&gt;', '</u>'],
      ['&lt;em&gt;', '<em>'],
      ['&lt;/em&gt;', '</em>'],
      ['&lt;br&gt;', '<br>'],
      ['&lt;br/&gt;', '<br>'],
      ['&lt;br /&gt;', '<br>']
    ];
    allowed.forEach(function (pair) {
      html = html.split(pair[0]).join(pair[1]);
    });
    return html;
  }

  // ---------------------------------------------------------------------
  // DOM builder — used when host page does not pre-render Maya markup.
  // Both v3 surfaces ship with the markup pre-rendered, but this lets the
  // module be embedded easily anywhere.
  // ---------------------------------------------------------------------

  function ensureCoachStructure(root, opts) {
    if (root.getAttribute('data-coach-built') === '1') return;

    var avatarUrl = opts.avatarUrl || '/assets/avatars/maya.png';
    var layout = opts.layout || 'classic';

    root.classList.add('maya-coach');
    root.setAttribute('data-summary-coach', '');
    if (!root.getAttribute('data-coach-mode')) {
      root.setAttribute('data-coach-mode', opts.mode || 'compact');
    }
    if (opts.host && !root.getAttribute('data-coach-host')) {
      root.setAttribute('data-coach-host', opts.host);
    }
    root.setAttribute('data-coach-layout', layout);

    root.innerHTML = '';

    if (layout === 'cockpit') {
      var cockpit = el('div', { cls: 'maya-cockpit' });

      var cockpitHeader = el('div', { cls: 'maya-cockpit-header' });
      var cAvWrap = el('div', { cls: 'maya-avatar-wrap' });
      var cAv = el('img', { cls: 'maya-avatar', attrs: { src: avatarUrl, alt: 'Maya AI Instructor' } });
      cAv.addEventListener('error', function () {
        var fallback = el('div', { cls: 'maya-avatar-fallback', text: 'M' });
        cAvWrap.replaceChild(fallback, cAv);
      });
      cAvWrap.appendChild(cAv);
      cAvWrap.appendChild(el('span', { cls: 'maya-status-dot' }));
      var titleBlock = el('div', { cls: 'maya-cockpit-titleblock' });
      titleBlock.appendChild(el('div', { cls: 'maya-title', text: 'Maya Summary Coach' }));
      titleBlock.appendChild(el('div', { cls: 'maya-subtitle', text: 'Watching your summary grow' }));
      cockpitHeader.appendChild(cAvWrap);
      cockpitHeader.appendChild(titleBlock);
      cockpit.appendChild(cockpitHeader);

      cockpit.appendChild(el('div', {
        cls: 'maya-mission-pill',
        attrs: { 'data-maya-stage': '' },
        text: 'Current mission: Build the structure'
      }));

      var chatShell = el('div', { cls: 'maya-chat-shell', attrs: { 'data-maya-chat-area': '' } });
      chatShell.appendChild(el('button', {
        cls: 'maya-progress-tab',
        attrs: { type: 'button', 'data-maya-stats-toggle': '', 'aria-label': 'Show understanding progress' },
        text: '›'
      }));

      var drawer = el('aside', { cls: 'maya-progress-drawer', attrs: { 'data-maya-stats-overlay': '' } });
      var drawerHead = el('div', { cls: 'maya-progress-drawer-head' });
      drawerHead.appendChild(el('strong', { text: 'Understanding Progress' }));
      drawerHead.appendChild(el('button', {
        attrs: { type: 'button', 'data-maya-stats-close': '', 'aria-label': 'Close progress panel' },
        text: '×'
      }));
      drawer.appendChild(drawerHead);

      var scoreCards = el('div', { cls: 'maya-score-cards' });
      Object.keys(SCORE_LABELS).forEach(function (key) {
        var card = el('div', { cls: 'maya-score-card maya-progress-line', attrs: { 'data-maya-stat': key } });
        card.appendChild(el('div', { cls: 'maya-score-label', text: SCORE_LABELS[key] }));
        var cardBar = el('div', { cls: 'maya-score-bar' });
        cardBar.appendChild(el('div', { cls: 'maya-score-fill' }));
        card.appendChild(cardBar);
        card.appendChild(el('div', { cls: 'maya-score-value', text: '0' }));
        scoreCards.appendChild(card);
      });
      drawer.appendChild(scoreCards);

      var still = el('div', { cls: 'maya-still-needed', attrs: { 'data-maya-readiness': '' } });
      still.appendChild(el('div', { cls: 'maya-still-needed-title', text: 'Still needed' }));
      still.appendChild(el('ul', { attrs: { 'data-maya-readiness-list': '' } }));
      drawer.appendChild(still);
      chatShell.appendChild(drawer);

      var cThread = el('div', {
        cls: 'maya-chat-thread',
        attrs: { 'data-maya-chat-thread': '', 'aria-label': 'Conversation with Maya' }
      });
      var cSentinel = el('div', { cls: 'maya-chat-sentinel', attrs: { 'data-maya-chat-sentinel': '', 'aria-hidden': 'true' } });
      cSentinel.hidden = true;
      cThread.appendChild(cSentinel);
      var cEarlier = el('div', { cls: 'maya-chat-load-earlier', attrs: { 'data-maya-chat-load-earlier': '' } });
      cEarlier.hidden = true;
      cEarlier.appendChild(el('button', {
        cls: 'maya-chat-load-earlier-btn',
        attrs: { type: 'button', 'data-maya-chat-load-earlier-btn': '' },
        text: 'Load earlier messages'
      }));
      cThread.appendChild(cEarlier);
      cThread.appendChild(el('div', {
        cls: 'maya-chat-thread-empty',
        attrs: { 'data-maya-chat-empty': '' },
        text: 'Maya will start coaching you here.'
      }));
      var cTypingRow = el('div', { cls: 'maya-chat-row is-maya', attrs: { 'data-maya-typing-row': '' } });
      cTypingRow.style.display = 'none';
      var cTyping = el('div', { cls: 'maya-chat-typing', attrs: { 'data-maya-typing': '' } });
      cTyping.appendChild(el('span', { text: 'Maya is thinking' }));
      var cDots = el('span', { cls: 'maya-chat-typing-dots' });
      cDots.appendChild(el('span'));
      cDots.appendChild(el('span'));
      cDots.appendChild(el('span'));
      cTyping.appendChild(cDots);
      cTypingRow.appendChild(cTyping);
      cThread.appendChild(cTypingRow);
      chatShell.appendChild(cThread);
      cockpit.appendChild(chatShell);

      var composeArea = el('div', { cls: 'maya-compose-area' });
      composeArea.appendChild(el('textarea', {
        cls: 'maya-reply',
        attrs: { 'data-maya-reply': '', placeholder: 'Answer Maya here...', rows: '3' }
      }));
      var actionRow = el('div', { cls: 'maya-action-row maya-actions' });
      actionRow.appendChild(el('button', {
        cls: 'maya-btn primary',
        attrs: { type: 'button', 'data-maya-continue': '' },
        text: 'Send to Maya'
      }));
      actionRow.appendChild(el('button', {
        cls: 'maya-btn ghost',
        attrs: { type: 'button', 'data-maya-final': '', disabled: 'disabled', 'aria-disabled': 'true' },
        text: 'Request Final Review'
      }));
      composeArea.appendChild(actionRow);
      composeArea.appendChild(el('div', { cls: 'maya-local-hint', attrs: { 'data-maya-local-hint': '' } }));
      composeArea.appendChild(el('div', { cls: 'maya-error', attrs: { 'data-maya-error': '' } }));
      cockpit.appendChild(composeArea);

      root.appendChild(cockpit);
      root.setAttribute('data-coach-built', '1');
      return;
    }

    // Header
    var header = el('div', { cls: 'maya-coach-header' });
    var avWrap = el('div', { cls: 'maya-avatar-wrap' });
    var av = el('img', { cls: 'maya-avatar', attrs: { src: avatarUrl, alt: 'Maya AI Instructor' } });
    av.addEventListener('error', function () {
      var fallback = el('div', { cls: 'maya-avatar-fallback', text: 'M' });
      avWrap.replaceChild(fallback, av);
    });
    avWrap.appendChild(av);
    avWrap.appendChild(el('span', { cls: 'maya-status-dot' }));
    var headText = el('div', { cls: 'maya-coach-header-text' });
    headText.appendChild(el('div', { cls: 'maya-title', text: 'Maya Summary Coach' }));
    headText.appendChild(el('div', { cls: 'maya-subtitle', text: 'Watching your summary grow' }));
    header.appendChild(avWrap);
    header.appendChild(headText);
    root.appendChild(header);

    // Stage
    var stage = el('div', { cls: 'maya-stage', attrs: { 'data-maya-stage': '' }, text: 'Current mission: Build the structure' });
    root.appendChild(stage);

    // Legacy single-message bubble (CSS hides it in chat layout). Keeps
    // back-compat with embedders that still render the simple flow.
    var msg = el('div', {
      cls: 'maya-message',
      attrs: { 'data-maya-message': '' },
      text: "Hi! Let's build your summary together. Start by writing the main ideas of this lesson in your own words."
    });
    root.appendChild(msg);

    // ---- Chat layout: scrollable thread + lazy-load sentinel + stats overlay ----
    if (layout === 'chat') {
      var chatArea = el('div', { cls: 'maya-chat-area', attrs: { 'data-maya-chat-area': '' } });

      // Stats overlay (slides in over the chat).
      var overlay = el('div', { cls: 'maya-stats-overlay', attrs: { 'data-maya-stats-overlay': '' } });
      var oHead = el('div', { cls: 'maya-stats-overlay-head' });
      oHead.appendChild(el('div', { cls: 'maya-stats-overlay-title', text: 'Understanding Progress' }));
      var closeBtn = el('button', { cls: 'maya-stats-close', attrs: { type: 'button', 'data-maya-stats-close': '', 'aria-label': 'Close progress panel' }, text: '×' });
      oHead.appendChild(closeBtn);
      overlay.appendChild(oHead);

      // Horizontal score bars (5 columns).
      var statsGrid = el('div', { cls: 'maya-stats-progress' });
      Object.keys(SCORE_LABELS).forEach(function (key) {
        var stat = el('div', { cls: 'maya-stat', attrs: { 'data-maya-stat': key } });
        stat.appendChild(el('div', { cls: 'maya-stat-label', text: SCORE_LABELS[key] }));
        var sBar = el('div', { cls: 'maya-stat-bar' });
        sBar.appendChild(el('div', { cls: 'maya-stat-fill' }));
        stat.appendChild(sBar);
        stat.appendChild(el('div', { cls: 'maya-stat-value', text: '0' }));
        statsGrid.appendChild(stat);
      });
      overlay.appendChild(statsGrid);

      // "Still needed" banner inside the overlay.
      var ready2 = el('div', {
        cls: 'maya-readiness',
        attrs: { 'data-maya-readiness': '' }
      });
      ready2.appendChild(el('div', { cls: 'maya-readiness-title', text: 'Still needed' }));
      ready2.appendChild(el('ul', { cls: 'maya-readiness-list', attrs: { 'data-maya-readiness-list': '' } }));
      overlay.appendChild(ready2);

      overlay.appendChild(el('div', { cls: 'maya-local-hint', attrs: { 'data-maya-local-hint': '' } }));
      var noteWrap2 = el('div', { cls: 'maya-note-suggestion', attrs: { 'data-maya-note': '' } });
      noteWrap2.appendChild(el('div', { cls: 'maya-note-suggestion-label', text: 'Maya suggests adding:' }));
      noteWrap2.appendChild(el('div', { attrs: { 'data-maya-note-body': '' } }));
      overlay.appendChild(noteWrap2);

      chatArea.appendChild(overlay);

      // Stats toggle button (left edge).
      var sToggle = el('button', {
        cls: 'maya-stats-toggle',
        attrs: { type: 'button', 'data-maya-stats-toggle': '', 'aria-label': 'Show understanding progress', title: 'Show understanding progress' }
      });
      chatArea.appendChild(sToggle);

      // Scrollable thread.
      var thread = el('div', {
        cls: 'maya-chat-thread',
        attrs: { 'data-maya-chat-thread': '', 'aria-label': 'Conversation with Maya' }
      });
      // Sentinel + fallback button at top of thread for lazy load.
      var sentinel = el('div', { cls: 'maya-chat-sentinel', attrs: { 'data-maya-chat-sentinel': '', 'aria-hidden': 'true' } });
      sentinel.hidden = true;
      thread.appendChild(sentinel);
      var earlierWrap = el('div', { cls: 'maya-chat-load-earlier', attrs: { 'data-maya-chat-load-earlier': '' } });
      earlierWrap.hidden = true;
      var earlierBtn = el('button', {
        cls: 'maya-chat-load-earlier-btn',
        attrs: { type: 'button', 'data-maya-chat-load-earlier-btn': '' },
        text: 'Load earlier messages'
      });
      earlierWrap.appendChild(earlierBtn);
      thread.appendChild(earlierWrap);
      var emptyHint = el('div', { cls: 'maya-chat-thread-empty', attrs: { 'data-maya-chat-empty': '' }, text: 'Maya will start coaching you here.' });
      thread.appendChild(emptyHint);
      // Typing indicator stays at the bottom of the thread (after messages).
      var typingRow = el('div', { cls: 'maya-chat-row is-maya', attrs: { 'data-maya-typing-row': '' } });
      typingRow.style.display = 'none';
      var typingBubble = el('div', { cls: 'maya-chat-typing', attrs: { 'data-maya-typing': '' } });
      typingBubble.appendChild(el('span', { text: 'Maya is thinking' }));
      var dots = el('span', { cls: 'maya-chat-typing-dots' });
      dots.appendChild(el('span'));
      dots.appendChild(el('span'));
      dots.appendChild(el('span'));
      typingBubble.appendChild(dots);
      typingRow.appendChild(typingBubble);
      thread.appendChild(typingRow);
      chatArea.appendChild(thread);

      root.appendChild(chatArea);

      // Compose row (textarea + buttons) below the chat area.
      var compose = el('div', { cls: 'maya-compose' });
      var reply = el('textarea', {
        cls: 'maya-reply',
        attrs: {
          'data-maya-reply': '',
          placeholder: 'Answer Maya here…',
          rows: '2'
        }
      });
      compose.appendChild(reply);
      var acts = el('div', { cls: 'maya-actions' });
      acts.appendChild(el('button', {
        cls: 'maya-btn primary',
        attrs: { type: 'button', 'data-maya-continue': '' },
        text: 'Continue with Maya'
      }));
      acts.appendChild(el('button', {
        cls: 'maya-btn ghost',
        attrs: { type: 'button', 'data-maya-final': '', disabled: 'disabled', 'aria-disabled': 'true' },
        text: 'Request Final Review'
      }));
      compose.appendChild(acts);
      root.appendChild(compose);

      // Legacy nodes (CSS hides) — kept so any external code that queried them
      // (or future fallbacks) still finds something. Empty placeholders.
      root.appendChild(el('div', { cls: 'maya-progress-grid', attrs: { 'aria-hidden': 'true' } }));
      root.appendChild(el('div', { cls: 'maya-next-question', attrs: { 'data-maya-question': '', 'aria-hidden': 'true' } }));
      root.appendChild(el('div', { cls: 'maya-error', attrs: { 'data-maya-error': '' } }));

      root.setAttribute('data-coach-built', '1');
      return;
    }

    // ---- Classic layout (back-compat) ----

    // Progress grid
    var prog = el('div', { cls: 'maya-progress-grid' });
    prog.appendChild(el('div', { cls: 'maya-progress-grid-title', text: 'Understanding Progress' }));
    Object.keys(SCORE_LABELS).forEach(function (key) {
      var row = el('div', { cls: 'maya-progress-row', attrs: { 'data-maya-score': key } });
      row.appendChild(el('div', { cls: 'maya-progress-label', text: SCORE_LABELS[key] }));
      var bar = el('div', { cls: 'maya-progress-bar' });
      bar.appendChild(el('div', { cls: 'maya-progress-fill' }));
      row.appendChild(bar);
      row.appendChild(el('div', { cls: 'maya-progress-value', text: '0' }));
      prog.appendChild(row);
    });
    root.appendChild(prog);

    // Question
    var qC = el('div', { cls: 'maya-next-question', attrs: { 'data-maya-question': '' }, text: 'What are the 3–5 most important ideas from this lesson?' });
    root.appendChild(qC);

    // Reply
    var replyC = el('textarea', {
      cls: 'maya-reply',
      attrs: {
        'data-maya-reply': '',
        placeholder: 'Answer Maya here…',
        rows: '3'
      }
    });
    root.appendChild(replyC);

    // Actions
    var actsC = el('div', { cls: 'maya-actions' });
    actsC.appendChild(el('button', {
      cls: 'maya-btn primary',
      attrs: { type: 'button', 'data-maya-continue': '' },
      text: 'Continue with Maya'
    }));
    actsC.appendChild(el('button', {
      cls: 'maya-btn ghost',
      attrs: { type: 'button', 'data-maya-final': '', disabled: 'disabled', 'aria-disabled': 'true' },
      text: 'Request Final Review'
    }));
    root.appendChild(actsC);

    // Local hint
    root.appendChild(el('div', { cls: 'maya-local-hint', attrs: { 'data-maya-local-hint': '' } }));

    // Note suggestion
    var noteWrap = el('div', { cls: 'maya-note-suggestion', attrs: { 'data-maya-note': '' } });
    noteWrap.appendChild(el('div', { cls: 'maya-note-suggestion-label', text: 'Maya suggests adding:' }));
    noteWrap.appendChild(el('div', { attrs: { 'data-maya-note-body': '' } }));
    root.appendChild(noteWrap);

    // Readiness
    var ready = el('div', {
      cls: 'maya-readiness',
      attrs: { 'data-maya-readiness': '' }
    });
    ready.appendChild(el('div', { cls: 'maya-readiness-title', text: 'Still needed' }));
    ready.appendChild(el('ul', { cls: 'maya-readiness-list', attrs: { 'data-maya-readiness-list': '' } }));
    root.appendChild(ready);

    // Error
    root.appendChild(el('div', {
      cls: 'maya-error',
      attrs: { 'data-maya-error': '' }
    }));

    root.setAttribute('data-coach-built', '1');
  }

  // ---------------------------------------------------------------------
  // Coach instance
  // ---------------------------------------------------------------------

  function Coach(root, config) {
    this.root = root;
    this.config = config;
    this.editor = null;
    this.history = [];
    this.lastQuestion = '';
    this.stage = config.initialStage || 'structure';
    this.scores = config.initialScores || { coverage: 0, accuracy: 0, own_wording: 0, correlation: 0, instructor_confidence: 0 };
    this.flags = { major_paste: false, needs_deeper_question: false };
    this.readiness = { ready_for_final_review: false, missing: [], minimum_interactions_met: false, unresolved_required_question: true };
    this.coachingState = {
      current_writing_task: '',
      awaiting_chat_reply: false,
      coach_state: '',
      active_assignment: null,
      current_section: '',
      current_slide_id: config.currentSlideId || 0,
      current_slide_number: config.currentSlideNumber || 0
    };
    this.summaryState = {
      reviewStatus: config.reviewStatus || '',
      locked: !!config.locked,
      hasText: !!config.hasText,
      wordCount: config.wordCount || 0,
      summaryId: config.summaryId || 0
    };
    this.interactionCount = 0;
    this.busy = false;
    this.lastCheckpointAt = 0;
    this.lastTextLen = 0;
    this.charsSinceLastCheckpoint = 0;
    this.lastInputAt = 0;
    this.idleTimer = null;
    this.pasteWindow = []; // {ts, chars}
    this.suppressPasteDetectionUntil = 0;
    this.isProgrammaticInsertion = false;
    this.aborted = false;
    this.startedSession = false;

    this._cacheElements();
    this._wireUi();
    this._attachEditor();
    this._bootstrap();
  }

  Coach.prototype._cacheElements = function () {
    this.elMessage = $('[data-maya-message]', this.root);
    this.elStage = $('[data-maya-stage]', this.root);
    this.elQuestion = $('[data-maya-question]', this.root);
    this.elReply = $('[data-maya-reply]', this.root);
    this.btnContinue = $('[data-maya-continue]', this.root);
    this.btnFinal = $('[data-maya-final]', this.root);
    this.elReadiness = $('[data-maya-readiness]', this.root);
    this.elReadinessList = $('[data-maya-readiness-list]', this.root) ||
                          (this.elReadiness ? this.elReadiness.querySelector('ul') : null);
    this.elLocalHint = $('[data-maya-local-hint]', this.root);
    this.elNote = $('[data-maya-note]', this.root);
    this.elNoteBody = $('[data-maya-note-body]', this.root) ||
                     (this.elNote ? this.elNote.querySelector('div:last-child') : null);
    this.elError = $('[data-maya-error]', this.root);
    this.elWritingTask = $('[data-maya-writing-task]');
    this.elWritingTaskBody = this.elWritingTask ? $('[data-maya-writing-task-body]', this.elWritingTask) : null;
    this.scoreRows = {};
    var self = this;
    Object.keys(SCORE_LABELS).forEach(function (k) {
      self.scoreRows[k] = $('[data-maya-score="' + k + '"]', self.root);
    });

    // v3 chat layout extras (may be null in classic layout).
    this.elChatArea     = $('[data-maya-chat-area]', this.root);
    this.elChatThread   = $('[data-maya-chat-thread]', this.root);
    this.elChatEmpty    = $('[data-maya-chat-empty]', this.root);
    this.elChatSentinel = $('[data-maya-chat-sentinel]', this.root);
    this.elChatLoadEarlier = $('[data-maya-chat-load-earlier]', this.root);
    this.elChatLoadEarlierBtn = $('[data-maya-chat-load-earlier-btn]', this.root);
    this.elTypingRow    = $('[data-maya-typing-row]', this.root);
    this.elTyping       = $('[data-maya-typing]', this.root);
    this.elStatsOverlay = $('[data-maya-stats-overlay]', this.root);
    this.elStatsToggle  = $('[data-maya-stats-toggle]', this.root);
    this.elStatsClose   = $('[data-maya-stats-close]', this.root);
    this.statRows = {};
    Object.keys(SCORE_LABELS).forEach(function (k) {
      self.statRows[k] = $('[data-maya-stat="' + k + '"]', self.root);
    });

    this.layout = this.root.getAttribute('data-coach-layout') || 'classic';
    this.historyOldestIndex = 0;
    this.historyHasMore = false;
    this.historyLoading = false;
    this._chatIo = null;
  };

  Coach.prototype._wireUi = function () {
    var self = this;

    if (this.btnContinue) {
      this.btnContinue.addEventListener('click', function () {
        self._handleContinue();
      });
    }
    if (this.btnFinal) {
      this.btnFinal.addEventListener('click', function () {
        self._handleFinalReview();
      });
    }

    if (this.elReply) {
      this.elReply.addEventListener('keydown', function (e) {
        // Ctrl/Cmd+Enter sends the reply quickly.
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
          e.preventDefault();
          self._handleContinue();
        }
      });
    }

    // Stats overlay: open / close.
    if (this.elStatsToggle) {
      this.elStatsToggle.addEventListener('click', function () {
        var open = self.root.getAttribute('data-stats-open') === '1';
        self.root.setAttribute('data-stats-open', open ? '0' : '1');
      });
    }
    if (this.elStatsClose) {
      this.elStatsClose.addEventListener('click', function () {
        self.root.setAttribute('data-stats-open', '0');
      });
    }

    // Lazy-load older chat messages (button fallback).
    if (this.elChatLoadEarlierBtn) {
      this.elChatLoadEarlierBtn.addEventListener('click', function () {
        self._loadOlderHistory();
      });
    }
  };

  Coach.prototype._resolveEditor = function () {
    var sel = this.config.editorSelector;
    if (!sel) return null;
    if (typeof sel === 'string') return document.querySelector(sel);
    if (sel && typeof sel.nodeType === 'number') return sel;
    if (typeof sel === 'function') {
      try { return sel(); } catch (e) { return null; }
    }
    return null;
  };

  Coach.prototype._attachEditor = function () {
    var ed = this._resolveEditor();
    if (!ed) {
      // Defer once: host page may render the editor lazily (e.g. modal).
      var self = this;
      setTimeout(function () {
        var ed2 = self._resolveEditor();
        if (ed2) self._bindEditor(ed2);
      }, 250);
      return;
    }
    this._bindEditor(ed);
  };

  Coach.prototype._bindEditor = function (ed) {
    if (this.editor === ed) return;
    this.editor = ed;
    this._ensureWritingTaskOverlay();
    var self = this;

    var snapshot = plainTextFromEditor(ed);
    this.lastTextLen = snapshot.length;

    ed.addEventListener('input', function () {
      self._onEditorInput();
    });
    ed.addEventListener('paste', function (e) {
      self._onEditorPaste(e);
    });
    this._renderWritingTask();
  };

  Coach.prototype._ensureWritingTaskOverlay = function () {
    if (this.elWritingTask && this.elWritingTaskBody) return;
    if (!this.editor) return;
    var pane = this.editor.closest ? this.editor.closest('.summary-editor-pane') : null;
    if (!pane) pane = this.editor.parentNode;
    if (!pane) return;
    var overlay = $('[data-maya-writing-task]', pane);
    if (!overlay) {
      overlay = el('div', { cls: 'summary-writing-task', attrs: { 'data-maya-writing-task': '' } });
      overlay.appendChild(el('div', { cls: 'maya-writing-task-label', attrs: { 'data-maya-writing-task-label': '' }, text: 'Current writing task' }));
      overlay.appendChild(el('div', { cls: 'maya-writing-task-body', attrs: { 'data-maya-writing-task-body': '' } }));
      pane.insertBefore(overlay, this.editor);
    }
    this.elWritingTask = overlay;
    this.elWritingTaskBody = $('[data-maya-writing-task-body]', overlay);
  };

  Coach.prototype.refreshEditor = function () {
    var ed = this._resolveEditor();
    if (ed && ed !== this.editor) {
      this._bindEditor(ed);
    }
    if (this.editor) {
      this.lastTextLen = plainTextFromEditor(this.editor).length;
    }
  };

  Coach.prototype.attachEditor = function (editorEl) {
    if (editorEl) this._bindEditor(editorEl);
  };

  Coach.prototype.setSummaryId = function (summaryId) {
    if (typeof summaryId === 'number' && summaryId > 0) {
      this.config.summaryId = summaryId;
      this.summaryState.summaryId = summaryId;
    }
  };

  Coach.prototype._usesThreadLayout = function () {
    return this.layout === 'chat' || this.layout === 'cockpit';
  };

  Coach.prototype.setSummaryState = function (state) {
    state = state || {};
    this.summaryState.reviewStatus = String(state.reviewStatus || state.review_status || this.summaryState.reviewStatus || '');
    this.summaryState.locked = !!(state.locked || state.student_soft_locked);
    this.summaryState.hasText = !!state.hasText;
    this.summaryState.wordCount = typeof state.wordCount === 'number' ? state.wordCount : (parseInt(state.word_count, 10) || 0);
    if (state.summaryId || state.summary_id) {
      this.summaryState.summaryId = parseInt(state.summaryId || state.summary_id, 10) || this.summaryState.summaryId;
      this.config.summaryId = this.summaryState.summaryId;
    }
    this._renderSummaryLockState();
  };

  Coach.prototype._renderSummaryLockState = function () {
    var accepted = this.summaryState.reviewStatus === 'acceptable';
    if (accepted && this.summaryState.locked) {
      this._setChatDisabled('Unlock your accepted summary before adding new notes.');
    } else {
      this._setChatDisabled('');
    }
  };

  Coach.prototype._setChatDisabled = function (reason) {
    var disabled = !!reason;
    if (this.elReply) {
      this.elReply.disabled = disabled;
      this.elReply.setAttribute('aria-disabled', disabled ? 'true' : 'false');
      this.elReply.placeholder = disabled ? reason : 'Answer Maya here...';
    }
    if (this.btnContinue) {
      if (disabled) {
        this.btnContinue.setAttribute('disabled', 'disabled');
        this.btnContinue.setAttribute('aria-disabled', 'true');
        this.btnContinue.setAttribute('title', reason);
      } else if (!this.busy) {
        this.btnContinue.removeAttribute('disabled');
        this.btnContinue.removeAttribute('aria-disabled');
        this.btnContinue.removeAttribute('title');
      }
    }
    this.root.setAttribute('data-chat-disabled', disabled ? '1' : '0');
  };

  Coach.prototype._appendSystemMessage = function (text) {
    this._appendBubble({
      role: 'system',
      message_type: 'system',
      message_body: text,
      message: text
    });
  };

  Coach.prototype._onEditorInput = function () {
    var text = plainTextFromEditor(this.editor);
    var newLen = text.length;
    var delta = newLen - this.lastTextLen;
    this.lastTextLen = newLen;
    this.lastInputAt = nowMs();

    if (this._isPasteDetectionSuppressed()) {
      this.charsSinceLastCheckpoint = 0;
      this.pasteWindow = [];
      return;
    }

    if (delta > 0) {
      this.charsSinceLastCheckpoint += delta;
      this._recordPasteWindow(delta);
    }

    if (this.idleTimer) clearTimeout(this.idleTimer);
  };

  Coach.prototype._onEditorPaste = function (e) {
    if (this._isPasteDetectionSuppressed()) return;
    var dt = e && e.clipboardData;
    var pasted = '';
    if (dt) {
      try {
        pasted = dt.getData('text/plain') || dt.getData('text') || '';
      } catch (err) {
        pasted = '';
      }
    }
    var ln = pasted.length;
    if (ln >= PASTE_BURST_CHARS_THRESHOLD) {
      this._triggerMajorPaste(ln);
    } else {
      // Small paste still counts toward burst window detection in
      // _recordPasteWindow on the subsequent `input` event.
      this._recordPasteWindow(ln);
    }
  };

  Coach.prototype._recordPasteWindow = function (chars) {
    if (this._isPasteDetectionSuppressed()) return;
    var t = nowMs();
    this.pasteWindow.push({ ts: t, chars: chars });
    // Drop entries older than the window.
    while (this.pasteWindow.length && (t - this.pasteWindow[0].ts > PASTE_BURST_WINDOW_MS)) {
      this.pasteWindow.shift();
    }
    var sum = 0;
    for (var i = 0; i < this.pasteWindow.length; i++) sum += this.pasteWindow[i].chars;
    if (sum >= PASTE_BURST_CHARS_THRESHOLD) {
      this._triggerMajorPaste(sum);
      this.pasteWindow = []; // reset window after triggering
    }
  };

  Coach.prototype._triggerMajorPaste = function (chars) {
    if (this._isPasteDetectionSuppressed()) return;
    if (this.flags.major_paste) {
      this._renderState();
      return;
    }
    this.flags.major_paste = true;
    this._renderState();
  };

  Coach.prototype._isPasteDetectionSuppressed = function () {
    if (this.isProgrammaticInsertion) return true;
    return nowMs() < (this.suppressPasteDetectionUntil || 0);
  };

  Coach.prototype._maybeIdleCheckpoint = function () {
    return;
  };

  Coach.prototype._evaluateLocalHints = function (text) {
    if (!text) {
      this._hideLocalHint();
      return;
    }
    if (this.flags.major_paste) {
      // Keep paste hint dominant.
      return;
    }
    var paragraphs = countParagraphs(text);
    var words = countWords(text);

    // Wall of text: long single block.
    var longestBlock = text.split(/\n{1,}/).reduce(function (mx, p) {
      var L = p.trim().length;
      return L > mx ? L : mx;
    }, 0);

    if (longestBlock > WALL_OF_TEXT_MIN_CHARS && paragraphs <= 1) {
      this._showLocalHint('Try splitting this into smaller sections so each idea is easy to find.');
      return;
    }

    if (words > 25 && paragraphs >= 1 && words < 80 && this.stage === 'structure') {
      this._showLocalHint('Nice start — now add why this matters in flight.');
      return;
    }

    if (words >= 80 && this.stage === 'explain') {
      this._showLocalHint('You are listing facts. Now explain the relationship between them.');
      return;
    }

    if (words >= 60 && (this.stage === 'correlate' || this.stage === 'operational_example')) {
      this._showLocalHint('Add one operational example — what would you do on the ramp or in the cockpit?');
      return;
    }

    this._hideLocalHint();
  };

  Coach.prototype._showLocalHint = function (text) {
    if (this.layout === 'cockpit') {
      this._renderWritingTask();
      if (this.elLocalHint) this.elLocalHint.removeAttribute('data-visible');
      return;
    }
    if (!this.elLocalHint) return;
    this.elLocalHint.textContent = text;
    this.elLocalHint.setAttribute('data-visible', '1');
  };
  Coach.prototype._hideLocalHint = function () {
    if (this.layout === 'cockpit') {
      if (this.elLocalHint) this.elLocalHint.removeAttribute('data-visible');
      return;
    }
    if (!this.elLocalHint) return;
    this.elLocalHint.removeAttribute('data-visible');
  };

  Coach.prototype._showError = function (text) {
    if (!this.elError) return;
    this.elError.textContent = text;
    this.elError.setAttribute('data-visible', '1');
    this.root.setAttribute('data-coach-state', 'error');
    var self = this;
    setTimeout(function () {
      if (self.elError) self.elError.removeAttribute('data-visible');
      if (self.root.getAttribute('data-coach-state') === 'error') {
        self.root.removeAttribute('data-coach-state');
      }
    }, 5000);
  };

  Coach.prototype._setBusy = function (v) {
    this.busy = !!v;
    if (v) {
      this.root.setAttribute('data-coach-state', 'thinking');
    } else if (this.root.getAttribute('data-coach-state') === 'thinking') {
      this.root.removeAttribute('data-coach-state');
    }
    // Chat layout: toggle the "Maya is thinking…" bubble at the bottom.
    if (this.elTypingRow && this.elTyping) {
      if (v) {
        this.elTypingRow.style.display = '';
        this.elTyping.setAttribute('data-visible', '1');
        this._scrollChatToBottom();
      } else {
        this.elTypingRow.style.display = 'none';
        this.elTyping.removeAttribute('data-visible');
      }
    }
    if (this.btnContinue) {
      if (v) {
        this.btnContinue.setAttribute('disabled', 'disabled');
        this.btnContinue.setAttribute('aria-disabled', 'true');
      } else {
        this._renderSummaryLockState();
      }
    }
    if (this.btnFinal) {
      if (v) {
        this.btnFinal.setAttribute('disabled', 'disabled');
        this.btnFinal.setAttribute('aria-disabled', 'true');
      } else {
        this._renderFinalButton();
      }
    }
  };

  // -------------------------------------------------------------------
  // API calls
  // -------------------------------------------------------------------

  Coach.prototype._buildLocalFlags = function () {
    var text = this.editor ? plainTextFromEditor(this.editor) : '';
    return {
      major_paste: !!this.flags.major_paste,
      wall_of_text: text.split(/\n{1,}/).some(function (p) { return p.trim().length > WALL_OF_TEXT_MIN_CHARS; }),
      word_count: countWords(text),
      paragraph_count: countParagraphs(text)
    };
  };

  Coach.prototype._buildSummaryExcerpt = function () {
    if (!this.editor) return '';
    var text = plainTextFromEditor(this.editor);
    return sliceFromEnd(text, SUMMARY_EXCERPT_MAX_CHARS);
  };

  Coach.prototype._buildPayload = function (action, extra) {
    var p = {
      action: action,
      lesson_id: this.config.lessonId,
      cohort_id: this.config.cohortId || 0,
      summary_id: this.config.summaryId || 0,
      context: this.config.context || 'player',
      current_slide_id: this.config.currentSlideId || this.coachingState.current_slide_id || 0,
      current_slide_number: this.config.currentSlideNumber || this.coachingState.current_slide_number || 0,
      coach_stage: this.stage,
      current_question: this.lastQuestion || '',
      summary_excerpt: this._buildSummaryExcerpt(),
      local_flags: this._buildLocalFlags(),
      coach_history: this.history.slice(-8)
    };
    if (extra) {
      Object.keys(extra).forEach(function (k) { p[k] = extra[k]; });
    }
    return p;
  };

  Coach.prototype._postJson = function (payload) {
    var url = this.config.apiUrl;
    if (!url) {
      return Promise.reject(new Error('summary_coach apiUrl missing'));
    }
    var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
    if (this.config.csrfToken) {
      headers['X-CSRF-Token'] = this.config.csrfToken;
    }
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers,
      body: JSON.stringify(payload)
    }).then(function (res) {
      return res.text().then(function (text) {
        var json = null;
        try { json = JSON.parse(text); } catch (e) { json = null; }
        if (!res.ok) {
          var err = (json && json.error) ? json.error : ('HTTP ' + res.status);
          throw new Error(err);
        }
        if (!json) throw new Error('Invalid JSON response');
        return json;
      });
    });
  };

  Coach.prototype._bootstrap = function () {
    this._renderState();
    if (!this.config.lessonId) {
      this._showError('Maya could not start: missing lesson context.');
      return;
    }
    var self = this;
    this._postJson({
      action: 'start_session',
      lesson_id: this.config.lessonId,
      cohort_id: this.config.cohortId || 0,
      summary_id: this.config.summaryId || 0,
      context: this.config.context || 'player'
    }).then(function (j) {
      if (!j || j.ok === false) {
        self._showError(j && j.error ? j.error : 'Maya could not start.');
        return;
      }
      self.startedSession = true;
      self._absorbResponse(j, { isStart: true });
    }).catch(function (e) {
      self._showError('Maya had trouble connecting. Your draft is still safe.');
      // Console for dev visibility, never uses user data.
      try { console.warn('[Maya] start_session failed:', e && e.message); } catch (ignored) {}
    });
  };

  Coach.prototype._handleContinue = function () {
    if (this.busy) return;
    if (this.summaryState.reviewStatus === 'acceptable' && this.summaryState.locked) {
      this._showLocalHint('Unlock your accepted summary before coaching improvements.');
      return;
    }
    var reply = this.elReply ? String(this.elReply.value || '').trim() : '';
    var trigger = reply !== '' ? 'student_reply' : 'micro_checkpoint';
    this._sendCheckpoint({ trigger: trigger, studentReply: reply });
  };

  Coach.prototype._sendCheckpoint = function (opts) {
    if (this.busy) return;
    opts = opts || {};

    this._setBusy(true);
    this.lastCheckpointAt = nowMs();
    this.charsSinceLastCheckpoint = 0;

    var action = (opts.trigger === 'student_reply') ? 'student_reply' : 'micro_checkpoint';
    var extra = {};
    if (opts.studentReply !== undefined) extra.student_reply = opts.studentReply;
    extra.client_trigger = opts.trigger || action;

    var self = this;
    this._postJson(this._buildPayload(action, extra)).then(function (j) {
      self._setBusy(false);
      if (!j || j.ok === false) {
        self._showError(j && j.error ? j.error : 'Maya had trouble responding.');
        return;
      }

      // Compose history client-side from this turn for next prompt.
      if (extra.student_reply) {
        self.history.push({ role: 'student', message: extra.student_reply, stage: self.stage });
      }
      if (j.maya_message) {
        self.history.push({ role: 'maya', message: j.maya_message, stage: j.stage || self.stage });
      }
      if (self.history.length > 16) {
        self.history = self.history.slice(-16);
      }

      // Note: in chat layout we clear the reply inside _absorbResponse so
      // the bubble copy reflects exactly what was sent.
      if (!self._usesThreadLayout() && extra.student_reply && self.elReply) {
        self.elReply.value = '';
      }

      self._absorbResponse(j, { studentReply: extra.student_reply || '' });
    }).catch(function (e) {
      self._setBusy(false);
      self._showError('Maya had trouble connecting. Your draft is still safe.');
      try { console.warn('[Maya] checkpoint failed:', e && e.message); } catch (ignored) {}
    });
  };

  Coach.prototype._handleFinalReview = function () {
    if (this.busy) return;
    if (!this.readiness || !this.readiness.ready_for_final_review) {
      this._runFinalReviewPrecheck();
      return;
    }
    this._setBusy(true);

    var payload = this._buildPayload('final_review', {});
    payload.summary_html = this.editor ? htmlFromEditor(this.editor) : '';
    payload.summary_excerpt = this.editor ? plainTextFromEditor(this.editor) : '';
    payload.coach_history = this.history.slice(-12);

    var self = this;
    this._postJson(payload).then(function (j) {
      self._setBusy(false);
      if (!j || j.ok === false) {
        self._showError(j && j.error ? j.error : 'Maya could not run the final review.');
        return;
      }
      self._absorbResponse(j, { isFinalReview: true, studentReply: 'Requested Final Review' });

      if (j.approved) {
        // Hand off to the host page so it can refresh its production-side
        // status (which is canonical, not Maya's). The canonical_check
        // result from the API is the production-engine response.
        if (typeof self.config.onCanonicalAccept === 'function') {
          try {
            self.config.onCanonicalAccept(j.canonical_check || null, j);
          } catch (e) {
            try { console.warn('[Maya] onCanonicalAccept handler error:', e && e.message); } catch (ignored) {}
          }
        }
      }
    }).catch(function (e) {
      self._setBusy(false);
      self._showError('Maya had trouble running final review. Your draft is still safe.');
      try { console.warn('[Maya] final_review failed:', e && e.message); } catch (ignored) {}
    });
  };

  Coach.prototype._runFinalReviewPrecheck = function () {
    if (this.busy) return;
    this._setBusy(true);
    var payload = this._buildPayload('final_review_precheck', {});
    payload.summary_html = this.editor ? htmlFromEditor(this.editor) : '';
    payload.summary_excerpt = this.editor ? plainTextFromEditor(this.editor) : '';
    payload.coach_history = this.history.slice(-12);
    var self = this;
    this._postJson(payload).then(function (j) {
      self._setBusy(false);
      if (!j || j.ok === false) {
        self._showError(j && j.error ? j.error : 'Maya could not check final review readiness.');
        return;
      }
      self._absorbResponse(j, { studentReply: 'Requested Final Review' });
      if (j.blocked_reasons && j.blocked_reasons.length) {
        self._showLocalHint(j.blocked_reasons.slice(0, 3).join(' • '));
      }
    }).catch(function (e) {
      self._setBusy(false);
      self._showError('Maya had trouble checking final review readiness. Your draft is still safe.');
      try { console.warn('[Maya] final_review_precheck failed:', e && e.message); } catch (ignored) {}
    });
  };

  // -------------------------------------------------------------------
  // Apply server response → state → DOM
  // -------------------------------------------------------------------

  Coach.prototype._absorbResponse = function (j, opts) {
    opts = opts || {};
    if (j.session_id) this.sessionId = parseInt(j.session_id, 10) || this.sessionId;
    if (j.summary_state && typeof j.summary_state === 'object') {
      this.setSummaryState({
        reviewStatus: j.summary_state.review_status,
        locked: !!j.summary_state.student_soft_locked,
        hasText: (j.summary_state.word_count || 0) > 0,
        wordCount: parseInt(j.summary_state.word_count, 10) || 0,
        summaryId: j.summary_state.summary_id || this.config.summaryId || 0
      });
    }
    if (j.stage) this.stage = String(j.stage);
    if (j.scores && typeof j.scores === 'object') {
      this.scores = {
        coverage: clampInt(j.scores.coverage, 0, 100),
        accuracy: clampInt(j.scores.accuracy, 0, 100),
        own_wording: clampInt(j.scores.own_wording, 0, 100),
        correlation: clampInt(j.scores.correlation, 0, 100),
        instructor_confidence: clampInt(j.scores.instructor_confidence, 0, 100)
      };
    }
    if (j.flags && typeof j.flags === 'object') {
      this.flags = {
        major_paste: !!j.flags.major_paste,
        needs_deeper_question: !!j.flags.needs_deeper_question
      };
    }
    if (j.current_task && typeof j.current_task === 'object') {
      var assignmentText = j.active_assignment && !j.active_assignment.completed
        ? String(j.active_assignment.short_task_label || j.active_assignment.instruction_text || '')
        : '';
      this.coachingState = {
        current_writing_task: assignmentText || String(j.current_task.task_text || ''),
        awaiting_chat_reply: String(j.current_task.mode || '') === 'answer_chat',
        coach_state: String(j.coach_state || (j.current_task && j.current_task.coach_state) || this.coachingState.coach_state || ''),
        active_assignment: j.active_assignment || null,
        current_section: String(j.current_task.section_title || j.current_task.section_id || ''),
        current_slide_id: this.coachingState.current_slide_id || 0,
        current_slide_number: Array.isArray(j.current_task.slide_group) && j.current_task.slide_group.length
          ? (parseInt(j.current_task.slide_group[0], 10) || 0)
          : (this.coachingState.current_slide_number || 0)
      };
    }
    if (j.coaching_state && typeof j.coaching_state === 'object') {
      var stateAssignment = j.coaching_state.active_assignment || j.active_assignment || null;
      var stateAssignmentText = stateAssignment && !stateAssignment.completed
        ? String(stateAssignment.short_task_label || stateAssignment.instruction_text || '')
        : '';
      this.coachingState = {
        current_writing_task: stateAssignmentText || String(j.coaching_state.current_writing_task || (j.current_task && j.current_task.task_text) || ''),
        awaiting_chat_reply: !!j.coaching_state.awaiting_chat_reply || !!(j.current_task && j.current_task.mode === 'answer_chat'),
        coach_state: String(j.coaching_state.coach_state || j.coach_state || this.coachingState.coach_state || ''),
        active_assignment: stateAssignment,
        current_section: String((j.current_task && (j.current_task.section_title || j.current_task.section_id)) || j.coaching_state.current_section || ''),
        current_slide_id: parseInt(j.coaching_state.current_slide_id, 10) || 0,
        current_slide_number: parseInt(j.coaching_state.current_slide_number, 10) || 0
      };
    } else if (j.flags && j.flags.section_progress && typeof j.flags.section_progress === 'object') {
      var flagAssignment = j.flags.active_assignment || j.flags.section_progress.active_assignment || null;
      this.coachingState.active_assignment = flagAssignment || this.coachingState.active_assignment || null;
      this.coachingState.current_writing_task = flagAssignment && !flagAssignment.completed
        ? String(flagAssignment.short_task_label || flagAssignment.instruction_text || '')
        : String(j.flags.section_progress.current_writing_task || this.coachingState.current_writing_task || '');
      this.coachingState.awaiting_chat_reply = !!j.flags.section_progress.awaiting_chat_reply;
      this.coachingState.coach_state = String(j.flags.section_progress.coach_state || j.flags.coach_state || this.coachingState.coach_state || '');
      this.coachingState.current_section = String(j.flags.section_progress.current_section || this.coachingState.current_section || '');
      this.coachingState.current_slide_id = parseInt(j.flags.section_progress.current_slide_id, 10) || this.coachingState.current_slide_id || 0;
      this.coachingState.current_slide_number = parseInt(j.flags.section_progress.current_slide_number, 10) || this.coachingState.current_slide_number || 0;
    }
    if (j.readiness && typeof j.readiness === 'object') {
      this.readiness = {
        ready_for_final_review: !!j.readiness.ready_for_final_review,
        missing: Array.isArray(j.readiness.missing) ? j.readiness.missing.slice(0) : [],
        minimum_interactions_met: !!j.readiness.minimum_interactions_met,
        unresolved_required_question: !!j.readiness.unresolved_required_question
      };
    }
    if (Array.isArray(j.blocked_reasons) && j.blocked_reasons.length) {
      this.readiness.missing = j.blocked_reasons.slice(0);
    }
    if (typeof j.interaction_count === 'number') {
      this.interactionCount = j.interaction_count;
    }
    if (typeof j.next_question === 'string') {
      this.lastQuestion = j.next_question;
    }

    // ---- Chat layout: render history (start) or append new turns ----
    if (this._usesThreadLayout() && this.elChatThread) {
      if (opts.isStart) {
        // Reset thread and load the latest page from history.
        this._resetThread(j.messages || j.history || [], !!(j.has_more || j.history_has_more), j.oldest_lazy_index || j.history_oldest_index || 0);
      } else {
        // Append student reply (if any) and Maya turn.
        if (typeof opts.studentReply === 'string' && opts.studentReply.trim() !== '') {
          this._appendBubble({
            role: 'student',
            message_body: opts.studentReply.trim(),
            message_type: opts.isFinalReview ? 'system' : 'chat'
          });
        }
        var msg = j.message || null;
        if (!msg && typeof j.maya_message === 'string' && j.maya_message.trim() !== '') {
          var body = j.maya_message;
          if (j.next_question && body.indexOf(j.next_question) === -1) {
            body += '\n\n' + j.next_question;
          }
          msg = {
            role: 'maya',
            message_body: body,
            message_type: opts.isFinalReview ? (j.approved ? 'final_approved' : 'final_revision') : 'chat',
            summary_insertions: j.summary_insertions || []
          };
        }
        if (msg) {
          this._appendBubble(msg);
        }
        if (this.elReply) this.elReply.value = '';
      }
    } else {
      // Classic layout
      if (typeof j.maya_message === 'string') {
        this._setMessage(j.maya_message);
      }
    }

    if (typeof j.student_note_suggestion === 'string') {
      this._setNoteSuggestion(j.student_note_suggestion);
    }
    if (opts.isFinalReview && j.approved) {
      this.root.setAttribute('data-coach-state', 'ready');
    }
    this._renderState();
  };

  // -------------------------------------------------------------------
  // Chat thread helpers (chat layout only)
  // -------------------------------------------------------------------

  Coach.prototype._resetThread = function (messages, hasMore, oldestIndex) {
    if (!this.elChatThread) return;

    // Disconnect any prior IO observer.
    if (this._chatIo) {
      try { this._chatIo.disconnect(); } catch (e) { /* noop */ }
      this._chatIo = null;
    }

    // Clear existing bubble rows but keep sentinel + load-earlier + empty-hint
    // + typing-row containers.
    var nodes = this.elChatThread.querySelectorAll('[data-maya-chat-bubble-row]');
    for (var i = 0; i < nodes.length; i++) {
      this.elChatThread.removeChild(nodes[i]);
    }

    var msgs = Array.isArray(messages) ? messages : [];
    if (msgs.length === 0 && this.elChatEmpty) {
      this.elChatEmpty.style.display = '';
    } else if (this.elChatEmpty) {
      this.elChatEmpty.style.display = 'none';
    }

    // Insert messages BEFORE the typing row but AFTER the sentinel/load-earlier/empty.
    var anchor = this.elTypingRow || null;
    var self = this;
    msgs.forEach(function (m) {
      var row = self._buildBubbleRow(m);
      if (!row) return;
      if (anchor) self.elChatThread.insertBefore(row, anchor);
      else self.elChatThread.appendChild(row);
    });

    this.historyOldestIndex = oldestIndex || 0;
    this.historyHasMore = !!hasMore;
    if (this.elChatLoadEarlier) this.elChatLoadEarlier.hidden = !hasMore;
    if (this.elChatSentinel) this.elChatSentinel.hidden = !hasMore;

    this._scrollChatToBottom();
    this._setupChatLazyObserver();
  };

  Coach.prototype._appendBubble = function (m) {
    if (!this.elChatThread) return;
    if (this.elChatEmpty) this.elChatEmpty.style.display = 'none';
    var row = this._renderMessage(m);
    if (!row) return;
    if (this.elTypingRow) this.elChatThread.insertBefore(row, this.elTypingRow);
    else this.elChatThread.appendChild(row);
    this._scrollChatToBottom();
  };

  Coach.prototype._renderMessage = function (m) {
    if (!m) return null;
    var role = String(m.role || '').toLowerCase();
    if (role === 'user') role = 'student';
    if (role !== 'student' && role !== 'maya' && role !== 'system') return null;
    var text = String(m.message_body || m.message || '').trim();
    if (text === '') return null;

    var row = document.createElement('div');
    row.className = 'maya-chat-row ' + (role === 'student' ? 'is-student' : (role === 'system' ? 'is-system' : 'is-maya'));
    row.setAttribute('data-maya-chat-bubble-row', '');
    if (m.id) row.setAttribute('data-message-id', String(m.id));
    if (m.lazy_index) row.setAttribute('data-lazy-index', String(m.lazy_index));

    var bubble = document.createElement('div');
    bubble.className = 'maya-chat-bubble';
    if (m.message_type || m.kind) bubble.setAttribute('data-kind', String(m.message_type || m.kind));

    if (role !== 'system') {
      var meta = document.createElement('div');
      meta.className = 'maya-chat-meta';
      meta.textContent = role === 'student' ? 'You' : 'Maya';
      bubble.appendChild(meta);
    }

    var body = document.createElement('div');
    body.className = 'maya-chat-body';
    if (role === 'maya') {
      body.innerHTML = safeMayaMessageHtml(text);
    } else {
      body.textContent = text;
    }
    bubble.appendChild(body);

    row.appendChild(bubble);
    if (role === 'maya' && Array.isArray(m.summary_insertions) && m.summary_insertions.length) {
      this._renderInsertionActions(row, m.summary_insertions, m.id || m.lazy_index || 0);
    }
    return row;
  };

  Coach.prototype._buildBubbleRow = function (m) {
    return this._renderMessage(m);
  };

  Coach.prototype._renderInsertionActions = function (row, insertions, messageId) {
    var self = this;
    var wrap = document.createElement('div');
    wrap.className = 'maya-insertion-actions';
    function labelForInsertion(ins) {
      if (ins.inserted) return 'Added';
      var type = String(ins.insertion_type || 'mature_concept');
      if (type === 'structure') return 'Add structure to summary';
      if (type === 'heading') return 'Add heading';
      if (type === 'highlighted_note') return 'Add highlighted note';
      if (type === 'remark') return 'Add remark';
      if (type === 'warning') return 'Add warning';
      if (type === 'caution') return 'Add caution';
      if (type === 'attention') return 'Add attention note';
      if (type === 'mnemonic') return 'Add mnemonic';
      if (type === 'quote') return 'Add quote';
      if (type === 'rule_of_thumb') return 'Add rule of thumb';
      if (type === 'reminder') return 'Add reminder note';
      if (type === 'bullet') return 'Add bullet';
      if (type === 'mature_concept') return 'Add completed idea to summary';
      return ins.label ? String(ins.label) : 'Add note';
    }
    insertions.forEach(function (ins) {
      if (!ins || !ins.id || !ins.label) return;
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'maya-insertion-btn';
      btn.textContent = labelForInsertion(ins);
      btn.disabled = !!ins.inserted;
      btn.setAttribute('data-insertion-id', String(ins.id));
      btn.addEventListener('click', function () {
        self._insertIntoSummary(ins, btn, messageId);
      });
      wrap.appendChild(btn);
    });
    if (wrap.childNodes.length) row.appendChild(wrap);
  };

  Coach.prototype._insertIntoSummary = function (insertion, btn, messageId) {
    if (this.summaryState.reviewStatus === 'acceptable' && this.summaryState.locked) {
      this._showLocalHint('Unlock your accepted summary before adding new notes.');
      return;
    }
    if (!this.editor || !insertion || !insertion.html) return;
    var html = String(insertion.html);
    var inserted = false;
    this.suppressPasteDetectionUntil = nowMs() + 1500;
    this.isProgrammaticInsertion = true;
    try {
      var sel = window.getSelection ? window.getSelection() : null;
      if (sel && sel.rangeCount && this.editor.contains(sel.anchorNode)) {
        var range = sel.getRangeAt(0);
        range.deleteContents();
        var frag = range.createContextualFragment(html);
        range.insertNode(frag);
        inserted = true;
      }
    } catch (e) {
      inserted = false;
    }
    if (!inserted) {
      this.editor.insertAdjacentHTML('beforeend', html);
    }
    this.editor.dispatchEvent(new Event('input', { bubbles: true }));
    var self = this;
    setTimeout(function () {
      self.isProgrammaticInsertion = false;
    }, 1500);
    if (String(insertion.insertion_type || '') === 'structure') {
      this.coachingState.current_writing_task = '';
      this.coachingState.active_assignment = null;
      this.coachingState.awaiting_chat_reply = false;
      this._renderWritingTask();
    }
    if (typeof this.config.onInsertSave === 'function') {
      try { this.config.onInsertSave(); } catch (e2) {}
    } else if (typeof global.scheduleSave === 'function') {
      try { global.scheduleSave(); } catch (e3) {}
    }
    if (!messageId) {
      if (btn) {
        btn.textContent = 'Added';
        btn.disabled = true;
        btn.classList.add('is-added');
      }
      return;
    }
    this._postJson({
      action: 'mark_inserted',
      session_id: this.sessionId || 0,
      message_id: messageId || 0,
      insertion_id: insertion.id,
      insertion_type: insertion.insertion_type || ''
    }).then(function (j) {
      self.isProgrammaticInsertion = false;
      if (!j || j.ok === false) throw new Error(j && j.error ? j.error : 'mark_inserted failed');
      if (j.current_task && typeof j.current_task === 'object') {
        self.coachingState.active_assignment = j.active_assignment || self.coachingState.active_assignment || null;
        self.coachingState.current_writing_task = self.coachingState.active_assignment && !self.coachingState.active_assignment.completed
          ? String(self.coachingState.active_assignment.short_task_label || self.coachingState.active_assignment.instruction_text || '')
          : String(j.current_task.task_text || '');
        self.coachingState.awaiting_chat_reply = String(j.current_task.mode || '') === 'answer_chat';
        self.coachingState.current_section = String(j.current_task.section_title || j.current_task.section_id || self.coachingState.current_section || '');
        self._renderWritingTask();
      }
      if (btn) {
        btn.textContent = 'Added';
        btn.disabled = true;
        btn.classList.add('is-added');
      }
    }).catch(function () {
      self.isProgrammaticInsertion = false;
      self._showError('Added to the summary, but Maya could not mark the note as inserted.');
    });
  };

  Coach.prototype._scrollChatToBottom = function () {
    if (!this.elChatThread) return;
    try {
      this.elChatThread.scrollTop = this.elChatThread.scrollHeight;
    } catch (e) { /* noop */ }
  };

  Coach.prototype._setupChatLazyObserver = function () {
    if (!this.elChatThread || !this.elChatSentinel) return;
    if (!this.historyHasMore) return;
    if (typeof IntersectionObserver === 'undefined') return; // fallback button only
    var self = this;
    try {
      this._chatIo = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting && en.target === self.elChatSentinel) {
            self._loadOlderHistory();
          }
        });
      }, { root: this.elChatThread, threshold: 0 });
      this._chatIo.observe(this.elChatSentinel);
    } catch (e) {
      this._chatIo = null;
    }
  };

  Coach.prototype._loadOlderHistory = function () {
    if (!this.elChatThread) return;
    if (this.historyLoading || !this.historyHasMore) return;
    if (this.historyOldestIndex <= 0) return;
    var self = this;
    this.historyLoading = true;
    if (this.elChatLoadEarlierBtn) this.elChatLoadEarlierBtn.disabled = true;

    this._postJson({
      action: 'load_history',
      session_id: this.sessionId || 0,
      lesson_id: this.config.lessonId,
      cohort_id: this.config.cohortId || 0,
      summary_id: this.config.summaryId || 0,
      context: this.config.context || 'player',
      before_lazy_index: this.historyOldestIndex,
      limit: 25
    }).then(function (j) {
      if (!j || j.ok === false) return;
      self._prependHistoryPage(j.messages || j.history || [], !!(j.has_more || j.history_has_more), j.oldest_lazy_index || j.history_oldest_index || 0);
    }).catch(function (e) {
      try { console.warn('[Maya] load_history failed:', e && e.message); } catch (ignored) {}
    }).then(function () {
      self.historyLoading = false;
      if (self.elChatLoadEarlierBtn) self.elChatLoadEarlierBtn.disabled = false;
    });
  };

  Coach.prototype._prependHistoryPage = function (msgs, hasMore, oldestIndex) {
    if (!this.elChatThread) return;
    var prevHeight = this.elChatThread.scrollHeight;

    // Insert AFTER the sentinel + load-earlier wrappers and BEFORE the
    // first existing bubble row (or empty hint, if any).
    var anchor = this.elChatThread.querySelector('[data-maya-chat-bubble-row]') ||
                 this.elChatEmpty ||
                 this.elTypingRow ||
                 null;

    var self = this;
    (msgs || []).forEach(function (m) {
      var lazy = m && (m.lazy_index || m.id);
      if (lazy && self.elChatThread.querySelector('[data-lazy-index="' + String(lazy) + '"]')) return;
      var row = self._buildBubbleRow(m);
      if (!row) return;
      if (anchor) self.elChatThread.insertBefore(row, anchor);
      else self.elChatThread.appendChild(row);
    });

    this.historyOldestIndex = oldestIndex || 0;
    this.historyHasMore = !!hasMore;
    if (this.elChatLoadEarlier) this.elChatLoadEarlier.hidden = !hasMore;
    if (this.elChatSentinel) this.elChatSentinel.hidden = !hasMore;

    if (!hasMore && this._chatIo) {
      try { this._chatIo.disconnect(); } catch (e) { /* noop */ }
      this._chatIo = null;
    }

    // Preserve scroll anchor so the user does not jump.
    try {
      var newHeight = this.elChatThread.scrollHeight;
      this.elChatThread.scrollTop = Math.max(0, newHeight - prevHeight);
    } catch (e) { /* noop */ }
  };

  Coach.prototype._setMessage = function (text) {
    if (!this.elMessage) return;
    var t = String(text || '').trim();
    if (t === '') {
      this.elMessage.textContent = '';
      this.elMessage.setAttribute('data-empty', '1');
    } else {
      this.elMessage.textContent = t;
      this.elMessage.removeAttribute('data-empty');
    }
  };

  Coach.prototype._setNoteSuggestion = function (text) {
    if (!this.elNote) return;
    var t = String(text || '').trim();
    if (t === '') {
      this.elNote.removeAttribute('data-visible');
      if (this.elNoteBody) this.elNoteBody.textContent = '';
      return;
    }
    if (this.elNoteBody) this.elNoteBody.textContent = t;
    this.elNote.setAttribute('data-visible', '1');
  };

  Coach.prototype._renderWritingTask = function () {
    this._ensureWritingTaskOverlay();
    if (!this.elWritingTask || !this.elWritingTaskBody) return;
    var task = String(this.coachingState.current_writing_task || '').trim();
    var assignment = this.coachingState.active_assignment || null;
    if (assignment && assignment.completed) task = '';
    var awaiting = !!this.coachingState.awaiting_chat_reply;
    if (!task) {
      this.elWritingTask.hidden = true;
      this.elWritingTask.setAttribute('aria-hidden', 'true');
      this.elWritingTaskBody.textContent = '';
      return;
    }
    this.elWritingTask.hidden = false;
    this.elWritingTask.removeAttribute('aria-hidden');
    var label = this.elWritingTask.querySelector('[data-maya-writing-task-label]');
    if (label) label.textContent = 'Current writing task';
    this.elWritingTaskBody.textContent = task;
    this.elWritingTask.setAttribute('data-awaiting-chat', awaiting ? '1' : '0');
  };

  Coach.prototype._renderState = function () {
    // Stage label
    if (this.elStage) {
      var label = STAGE_LABELS[this.stage] || STAGE_LABELS.structure;
      this.elStage.textContent = 'Current mission: ' + label;
    }
    // Question
    if (this.elQuestion) {
      var q = String(this.lastQuestion || '').trim();
      this.elQuestion.textContent = q !== '' ? q : 'Follow Maya’s current writing task in the summary editor.';
    }
    this._renderWritingTask();
    // Scores (classic vertical rows)
    var self = this;
    Object.keys(this.scoreRows).forEach(function (key) {
      var row = self.scoreRows[key];
      if (!row) return;
      var score = clampInt(self.scores[key], 0, 100);
      var fill = row.querySelector('.maya-progress-fill');
      var val = row.querySelector('.maya-progress-value');
      if (fill) fill.style.width = score + '%';
      if (val) val.textContent = String(score) + '%';
      var pass = score >= (SCORE_PASS_THRESHOLDS[key] || 100);
      var low = score < (SCORE_PASS_THRESHOLDS[key] || 100) * 0.6;
      if (pass) {
        row.setAttribute('data-pass', '1');
        row.removeAttribute('data-low');
      } else if (low) {
        row.setAttribute('data-low', '1');
        row.removeAttribute('data-pass');
      } else {
        row.removeAttribute('data-pass');
        row.removeAttribute('data-low');
      }
    });
    // Scores (chat-layout horizontal stat tiles in the slide-in overlay)
    if (this.statRows) {
      Object.keys(this.statRows).forEach(function (key) {
        var stat = self.statRows[key];
        if (!stat) return;
        var score = clampInt(self.scores[key], 0, 100);
        var fill = stat.querySelector('.maya-stat-fill, .maya-score-fill');
        var val = stat.querySelector('.maya-stat-value, .maya-score-value');
        if (fill) fill.style.width = score + '%';
        if (val) val.textContent = String(score) + '%';
        var pass = score >= (SCORE_PASS_THRESHOLDS[key] || 100);
        var low = score < (SCORE_PASS_THRESHOLDS[key] || 100) * 0.6;
        if (pass) {
          stat.setAttribute('data-pass', '1');
          stat.removeAttribute('data-low');
        } else if (low) {
          stat.setAttribute('data-low', '1');
          stat.removeAttribute('data-pass');
        } else {
          stat.removeAttribute('data-pass');
          stat.removeAttribute('data-low');
        }
      });
    }

    // Readiness panel
    if (this.elReadiness) {
      this.elReadiness.setAttribute('data-ready', this.readiness.ready_for_final_review ? '1' : '0');
      var titleEl = this.elReadiness.querySelector('.maya-readiness-title, .maya-still-needed-title');
      if (titleEl) {
        titleEl.textContent = this.readiness.ready_for_final_review
          ? 'Ready for Final Review'
          : 'Still needed';
      }
      if (this.elReadinessList) {
        this.elReadinessList.innerHTML = '';
        if (this.readiness.ready_for_final_review) {
          var li = document.createElement('li');
          li.className = 'maya-readiness-empty';
          li.textContent = 'Maya has enough evidence — request the final review when you are ready.';
          this.elReadinessList.appendChild(li);
        } else {
          var items = (this.readiness.missing || []);
          if (!items.length) {
            var liw = document.createElement('li');
            liw.className = 'maya-readiness-empty';
            liw.textContent = 'Keep coaching with Maya to unlock final review.';
            this.elReadinessList.appendChild(liw);
          } else {
            items.forEach(function (txt) {
              var li2 = document.createElement('li');
              li2.textContent = String(txt || '');
              self.elReadinessList.appendChild(li2);
            });
          }
        }
      }
    }

    // Final button
    this._renderFinalButton();
  };

  Coach.prototype._renderFinalButton = function () {
    if (!this.btnFinal) return;
    var ready = !!(this.readiness && this.readiness.ready_for_final_review);
    if (this.summaryState.reviewStatus === 'acceptable' && this.summaryState.locked) {
      ready = false;
    }
    if (ready && !this.busy) {
      this.btnFinal.removeAttribute('disabled');
      this.btnFinal.removeAttribute('aria-disabled');
      this.btnFinal.classList.remove('ghost');
      this.btnFinal.classList.add('primary');
      this.btnFinal.setAttribute('title', 'Run Maya\'s final review');
    } else {
      this.btnFinal.setAttribute('disabled', 'disabled');
      this.btnFinal.setAttribute('aria-disabled', 'true');
      this.btnFinal.classList.remove('primary');
      this.btnFinal.classList.add('ghost');
      var why = (this.readiness && this.readiness.missing && this.readiness.missing.length)
        ? this.readiness.missing.slice(0, 3).join(' • ')
        : 'Final review locked. Maya still needs more evidence of understanding.';
      if (this.summaryState.reviewStatus === 'acceptable' && this.summaryState.locked) {
        why = 'Unlock your accepted summary before requesting another review.';
      }
      this.btnFinal.setAttribute('title', why);
    }
  };

  // -------------------------------------------------------------------
  // Module entry points
  // -------------------------------------------------------------------

  function attach(rootEl, config) {
    if (!rootEl) return null;
    config = config || global.IPCASummaryCoachConfig || {};
    ensureCoachStructure(rootEl, {
      avatarUrl: config.avatarUrl,
      mode: config.mode,
      host: config.host,
      layout: config.layout || rootEl.getAttribute('data-coach-layout') || 'classic'
    });
    return new Coach(rootEl, config);
  }

  function create(config) {
    config = config || global.IPCASummaryCoachConfig || {};
    var rootEl = null;
    if (config.rootSelector) {
      rootEl = document.querySelector(config.rootSelector);
    } else if (config.root && config.root.nodeType === 1) {
      rootEl = config.root;
    } else {
      rootEl = document.querySelector('[data-summary-coach]');
    }
    if (!rootEl) {
      try { console.warn('[Maya] no root element found for create()'); } catch (e) {}
      return null;
    }
    return attach(rootEl, config);
  }

  global.IPCASummaryCoach = {
    create: create,
    attach: attach,
    version: '1.0.0-test'
  };

})(window);
