(function (global) {
  'use strict';

  var LIKERT = ['Strongly agree', 'Agree', 'Neutral', 'Disagree', 'Strongly disagree'];
  var ISSUE_OPTS = ['No', 'Audio problem', 'Transcript problem', 'Question unclear', 'Page got stuck', 'Other'];
  var DEFAULT_API_URL = '/student/api/progress_test_v4_oral.php';

  function text(el, v) {
    if (el) el.textContent = v;
  }

  function clamp(n, a, b) {
    return Math.max(a, Math.min(b, n));
  }

  function escapeHtml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function postJson(url, payload) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload || {})
    }).then(function (res) {
      return res.text().then(function (txt) {
        var json;
        try { json = JSON.parse(txt); } catch (e) { throw new Error('Non-JSON response'); }
        if (!res.ok || json.ok === false) throw new Error(json.error || ('HTTP ' + res.status));
        return json;
      });
    });
  }

  function create(options) {
    options = options || {};
    var cfg = options.cfg || {};
    var root = options.root || document;
    var apiUrl = options.apiUrl || DEFAULT_API_URL;
    var attemptId = parseInt(options.attemptId, 10) || 0;
    var getAttemptId = typeof options.getAttemptId === 'function' ? options.getAttemptId : function () { return attemptId; };
    var lastReport = null;

    function $(sel) { return root.querySelector(sel); }
    function $all(sel) { return root.querySelectorAll(sel); }

    var els = {
      reportModal: $('[data-ptv4-report-modal]'),
      reportClose: $('[data-ptv4-report-close]'),
      reportCloseBottom: $('[data-ptv4-report-close-bottom]'),
      reportSubtitle: $('[data-ptv4-report-subtitle]'),
      reportHero: $('[data-ptv4-report-hero]'),
      reportStats: $('[data-ptv4-report-stats]'),
      reportQuestions: $('[data-ptv4-report-questions]'),
      reportBadges: $('[data-ptv4-report-badges]'),
      reportFocus: $('[data-ptv4-report-focus]'),
      reportMetrics: $('[data-ptv4-report-metrics]'),
      reportExpandAll: $('[data-ptv4-report-expand-all]'),
      feedbackForm: $('[data-ptv4-feedback-form]'),
      feedbackSent: $('[data-ptv4-feedback-sent]'),
      feedbackSentBadge: $('[data-ptv4-feedback-sent-badge]'),
      feedbackReward: $('[data-ptv4-feedback-reward]'),
      feedbackCount: $('[data-ptv4-fb-count]'),
      myReport: options.myReportButton || $('[data-ptv4-my-report]')
    };

    function resolveAttemptId() {
      var id = parseInt(getAttemptId(), 10) || 0;
      return id > 0 ? id : attemptId;
    }

    function apiJson(action, payload) {
      payload = payload || {};
      payload.action = action;
      var id = resolveAttemptId();
      if (id > 0) payload.attempt_id = id;
      return postJson(apiUrl, payload);
    }

    function reportScoreTone(scorePct, passPct) {
      var pass = parseInt(passPct, 10) || 70;
      var score = parseInt(scorePct, 10);
      if (isNaN(score)) return 'partial';
      if (score >= pass) return 'pass';
      if (score >= 50) return 'partial';
      return 'fail';
    }

    function studentInitials(name) {
      var parts = String(name || 'S').trim().split(/\s+/).filter(Boolean);
      if (!parts.length) return 'S';
      if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
      return (parts[0][0] + parts[1][0]).toUpperCase();
    }

    function studentPhotoUrl(source) {
      return String((source && source.photo_path) || (source && source.studentPhotoUrl) || cfg.studentPhotoUrl || '').trim();
    }

    function renderReportStudentAvatar(firstName, photoUrl) {
      var url = String(photoUrl || '').trim();
      if (url) {
        return '<div class="ptv4-report-student-avatar ptv4-report-student-avatar-photo">'
          + '<img src="' + escapeHtml(url) + '" alt="' + escapeHtml(firstName || 'Student') + '">'
          + '</div>';
      }
      return '<div class="ptv4-report-student-avatar">' + escapeHtml(studentInitials(firstName)) + '</div>';
    }

    function renderReportMiniStudentAvatar(firstName, photoUrl) {
      var url = String(photoUrl || '').trim();
      if (url) {
        return '<img class="ptv4-report-mini-avatar ptv4-report-mini-avatar-photo" src="' + escapeHtml(url) + '" alt="' + escapeHtml(firstName || 'Student') + '">';
      }
      return '<span class="ptv4-report-mini-avatar">' + escapeHtml(studentInitials(firstName)) + '</span>';
    }

    function badgeEmblemClass(theme) {
      return 'ptv4-badge-emblem ptv4-badge-emblem-' + (theme || 'departure');
    }

    function renderBadgeImageHtml(badge) {
      var url = String((badge && badge.image_path) || '').trim();
      if (url) {
        return '<span class="ptv4-badge-image-wrap"><img class="ptv4-badge-image" src="' + escapeHtml(url) + '" alt="' + escapeHtml((badge && badge.name) || 'Badge') + '"></span>';
      }
      return '<span class="' + badgeEmblemClass(badge && badge.theme) + '">?</span>';
    }

    function renderScoreRing(scorePct, passPct, passed) {
      var score = scorePct == null ? 0 : parseInt(scorePct, 10);
      var tone = reportScoreTone(score, passPct);
      var pct = clamp(score, 0, 100);
      var radius = 46;
      var circumference = 2 * Math.PI * radius;
      var offset = circumference - ((pct / 100) * circumference);
      return ''
        + '<div class="ptv4-report-score-ring">'
        + '<svg viewBox="0 0 118 118" aria-hidden="true">'
        + '<circle class="ptv4-report-score-ring-bg" cx="59" cy="59" r="' + radius + '"></circle>'
        + '<circle class="ptv4-report-score-ring-fill" data-tone="' + tone + '" cx="59" cy="59" r="' + radius + '" stroke-dasharray="' + circumference.toFixed(2) + '" stroke-dashoffset="' + offset.toFixed(2) + '"></circle>'
        + '</svg>'
        + '<div class="ptv4-report-score-center">'
        + '<div class="ptv4-report-score-value">' + (scorePct == null ? '--' : score + '%') + '</div>'
        + '<div class="ptv4-report-score-label">' + (passed ? 'PASS' : 'RESULT') + '</div>'
        + '</div></div>'
        + '<div class="ptv4-report-score-caption">Overall Score</div>';
    }

    function renderReportHero(report) {
      if (!els.reportHero) return;
      var firstName = report.first_name || cfg.firstName || 'Student';
      var photoUrl = studentPhotoUrl(report);
      els.reportHero.innerHTML = ''
        + '<div class="ptv4-report-hero-maya">'
        + '<div class="ptv4-report-avatar-frame"><img src="/assets/avatars/maya.png" alt="Maya"></div>'
        + '<div class="ptv4-report-hero-quote">' + escapeHtml(report.motivation || '') + '</div>'
        + '</div>'
        + '<div>' + renderScoreRing(report.score_pct, report.pass_threshold_pct, !!report.passed) + '</div>'
        + '<div class="ptv4-report-hero-student">'
        + renderReportStudentAvatar(firstName, photoUrl)
        + '<div class="ptv4-report-student-name">' + escapeHtml(firstName) + '</div>'
        + '</div>';
    }

    function renderReportStats(report) {
      if (!els.reportStats) return;
      var stats = report.stats || {};
      var resultTone = report.passed ? 'pass' : '';
      els.reportStats.innerHTML = [
        { label: 'Lesson', value: stats.lesson_label || ('Lesson ' + (report.lesson_number || '')) },
        { label: 'Questions', value: (stats.questions_answered == null ? '0' : stats.questions_answered) + '/' + (stats.questions_total || (report.questions || []).length) + ' Answered' },
        { label: 'Average Score', value: (stats.average_score_pct == null ? '--' : stats.average_score_pct + '%') },
        { label: 'Result', value: stats.result_label || (report.passed ? 'PASS' : 'NOT YET PASSED'), tone: resultTone }
      ].map(function (item) {
        return '<div class="ptv4-report-stat"><div class="ptv4-report-stat-label">' + escapeHtml(item.label) + '</div>'
          + '<div class="ptv4-report-stat-value"' + (item.tone ? ' data-tone="' + item.tone + '"' : '') + '>' + escapeHtml(item.value) + '</div></div>';
      }).join('');
    }

    function renderReportQuestionCard(q, passPct, firstName, photoUrl) {
      var score = q.score_pct == null ? 0 : parseInt(q.score_pct, 10);
      var tone = q.performance_tone || reportScoreTone(score, passPct);
      var label = q.performance_label || 'Performance';
      var miniAvatar = renderReportMiniStudentAvatar(firstName, photoUrl);
      var card = document.createElement('div');
      card.className = 'ptv4-report-qcard';
      card.innerHTML = ''
        + '<button class="ptv4-report-qcard-head" type="button" aria-expanded="false">'
        + '<span class="ptv4-report-qcard-title">Question ' + q.idx + '</span>'
        + '<span class="ptv4-report-qcard-score" data-tone="' + tone + '">' + (q.score_pct == null ? '--' : score + '%') + '</span>'
        + '<span class="ptv4-report-qbar"><span class="ptv4-report-qbar-fill" data-tone="' + tone + '" style="width:' + clamp(score, 0, 100) + '%"></span></span>'
        + '<span class="ptv4-report-qcard-pill" data-tone="' + tone + '">' + escapeHtml(label) + '</span>'
        + '<span class="ptv4-report-qcard-chevron" aria-hidden="true">▾</span>'
        + '</button>'
        + '<div class="ptv4-report-qcard-body">'
        + '<div class="ptv4-report-qblock"><div class="ptv4-report-qblock-head"><span>Question</span></div><div class="ptv4-report-qblock-text">' + escapeHtml(q.question || '') + '</div></div>'
        + '<div class="ptv4-report-qblock"><div class="ptv4-report-qblock-head">' + miniAvatar + '<span>Your Answer</span></div><div class="ptv4-report-qblock-text">' + escapeHtml(q.student_answer || '—') + '</div></div>'
        + (q.clarification_question ? '<div class="ptv4-report-qblock"><div class="ptv4-report-qblock-head"><div class="ptv4-report-avatar-frame ptv4-report-avatar-frame-sm"><img src="/assets/avatars/maya.png" alt="Maya"></div><span>Clarification</span></div><div class="ptv4-report-qblock-text">' + escapeHtml(q.clarification_question) + '</div></div>' : '')
        + (q.clarification_answer ? '<div class="ptv4-report-qblock"><div class="ptv4-report-qblock-head">' + miniAvatar + '<span>Clarification Answer</span></div><div class="ptv4-report-qblock-text">' + escapeHtml(q.clarification_answer) + '</div></div>' : '')
        + '<div class="ptv4-report-qblock"><div class="ptv4-report-qblock-head"><div class="ptv4-report-avatar-frame ptv4-report-avatar-frame-sm"><img src="/assets/avatars/maya.png" alt="Maya"></div><span>Maya Feedback</span></div><div class="ptv4-report-qblock-text">' + escapeHtml(q.feedback || '') + '</div></div>'
        + '</div>';
      var head = card.querySelector('.ptv4-report-qcard-head');
      if (head) {
        head.addEventListener('click', function () {
          var open = card.classList.toggle('is-open');
          head.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
      }
      return card;
    }

    function setAllQuestionCards(open) {
      if (!els.reportQuestions) return;
      els.reportQuestions.querySelectorAll('.ptv4-report-qcard').forEach(function (card) {
        card.classList.toggle('is-open', !!open);
        var head = card.querySelector('.ptv4-report-qcard-head');
        if (head) head.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      if (els.reportExpandAll) text(els.reportExpandAll, open ? 'Collapse All' : 'Expand All');
    }

    function renderReportBadges(report) {
      if (!els.reportBadges) return;
      els.reportBadges.innerHTML = '';
      (report.badges || []).forEach(function (badge) {
        var card = document.createElement('div');
        card.className = 'ptv4-badge-card' + (badge.earned ? ' is-earned' : ' is-locked') + (badge.newly_earned ? ' is-new' : '');
        card.innerHTML = ''
          + renderBadgeImageHtml(badge)
          + '<div class="ptv4-badge-copy"><strong>' + escapeHtml(badge.name || '') + '</strong><span>' + escapeHtml(badge.description || '') + '</span>'
          + '<div class="ptv4-badge-status ' + (badge.earned ? 'is-earned' : 'is-locked') + '">' + (badge.earned ? 'UNLOCKED' : 'LOCKED') + '</div></div>';
        els.reportBadges.appendChild(card);
      });
    }

    function renderReportFocus(report) {
      if (!els.reportFocus) return;
      var focus = report.focus_areas || {};
      var strengths = focus.strengths || [];
      var weaknesses = focus.weaknesses || [];
      els.reportFocus.innerHTML = ''
        + '<div class="ptv4-report-focus-title">' + escapeHtml(focus.title || 'Your Next Focus Areas') + '</div>'
        + '<div class="ptv4-report-focus-summary">' + escapeHtml(focus.summary || report.recommendation || '') + '</div>'
        + '<div class="ptv4-report-focus-lists">'
        + '<div class="ptv4-report-focus-list"><h4>Strengths</h4><ul>' + (strengths.length ? strengths.map(function (s) { return '<li>' + escapeHtml(s) + '</li>'; }).join('') : '<li>Keep building on the concepts you answered confidently.</li>') + '</ul></div>'
        + '<div class="ptv4-report-focus-list"><h4>Focus Next</h4><ul>' + (weaknesses.length ? weaknesses.map(function (s) { return '<li>' + escapeHtml(s) + '</li>'; }).join('') : '<li>Review the question feedback and rehearse complete spoken answers.</li>') + '</ul></div>'
        + '</div>'
        + '<div class="ptv4-report-focus-strategy">' + escapeHtml(focus.strategy || report.recommendation || '') + '</div>';
    }

    function renderReportMetrics(report) {
      if (!els.reportMetrics) return;
      var metrics = report.performance_metrics || [];
      if (!metrics.length) {
        els.reportMetrics.hidden = true;
        els.reportMetrics.innerHTML = '';
        return;
      }
      els.reportMetrics.hidden = false;
      els.reportMetrics.innerHTML = '<div class="ptv4-report-section-title">Performance Signals</div><div class="ptv4-report-metrics-grid">'
        + metrics.map(function (m) {
          return '<div class="ptv4-report-metric"><label>' + escapeHtml(m.label || '') + '</label><div class="ptv4-report-metric-bar"><div class="ptv4-report-metric-fill" style="width:' + clamp(parseInt(m.score, 10) || 0, 0, 100) + '%"></div></div></div>';
        }).join('')
        + '</div>';
    }

    function renderReportModal(report) {
      report = report || lastReport;
      if (!report || !els.reportModal) return;
      lastReport = report;
      text(els.reportSubtitle, (report.lesson_title || cfg.lessonTitle || '') + ' · Score ' + (report.score_pct == null ? '--' : report.score_pct + '%'));
      renderReportHero(report);
      renderReportStats(report);
      if (els.reportQuestions) {
        els.reportQuestions.innerHTML = '';
        var reportFirstName = report.first_name || cfg.firstName || 'Student';
        var reportPhotoUrl = studentPhotoUrl(report);
        (report.questions || []).forEach(function (q) {
          els.reportQuestions.appendChild(renderReportQuestionCard(q, report.pass_threshold_pct || cfg.progressTestPassPct || 70, reportFirstName, reportPhotoUrl));
        });
      }
      renderReportBadges(report);
      renderReportFocus(report);
      renderReportMetrics(report);
      setAllQuestionCards(false);
      els.reportModal.hidden = false;
    }

    function closeReportModal() {
      if (els.reportModal) els.reportModal.hidden = true;
    }

    function collectFeedbackRatings() {
      var out = {};
      $all('[data-ptv4-fb-q]').forEach(function (block) {
        var key = block.getAttribute('data-ptv4-fb-q');
        var checked = block.querySelector('input[type="radio"]:checked');
        if (checked) out[key] = checked.value;
      });
      return out;
    }

    function initFeedbackForm() {
      $all('[data-ptv4-fb-q]').forEach(function (block) {
        var key = block.getAttribute('data-ptv4-fb-q');
        var optsWrap = block.querySelector('.ptv4-feedback-q-options');
        if (!optsWrap) return;
        optsWrap.innerHTML = '';
        var opts = key === 'went_wrong' ? ISSUE_OPTS : LIKERT;
        opts.forEach(function (label, i) {
          var id = 'ptv4-fb-' + key + '-' + i;
          var lab = document.createElement('label');
          lab.className = 'ptv4-feedback-option';
          lab.innerHTML = '<input type="radio" name="ptv4-fb-' + key + '" id="' + id + '" value="' + label.replace(/"/g, '&quot;') + '"><span>' + escapeHtml(label) + '</span>';
          optsWrap.appendChild(lab);
        });
      });
      var free = $('[data-ptv4-fb-free]');
      if (free && els.feedbackCount) {
        var syncCount = function () {
          text(els.feedbackCount, String(free.value.length) + ' / 500');
        };
        free.addEventListener('input', syncCount);
        syncCount();
      }
    }

    function fetchAndShowReport(forceRefresh) {
      var id = resolveAttemptId();
      if (!forceRefresh && lastReport && parseInt(lastReport.attempt_id, 10) === id) {
        renderReportModal(lastReport);
        return Promise.resolve(lastReport);
      }
      return apiJson('get_report', {}).then(function (out) {
        renderReportModal(out.report);
        return out.report;
      });
    }

    function bindEvents() {
      if (els.myReport) {
        els.myReport.addEventListener('click', function () {
          fetchAndShowReport().catch(function () {});
        });
      }
      if (els.reportClose) els.reportClose.addEventListener('click', closeReportModal);
      if (els.reportCloseBottom) els.reportCloseBottom.addEventListener('click', closeReportModal);
      if (els.reportExpandAll) {
        els.reportExpandAll.addEventListener('click', function () {
          var anyClosed = els.reportQuestions && els.reportQuestions.querySelector('.ptv4-report-qcard:not(.is-open)');
          setAllQuestionCards(!!anyClosed);
        });
      }
      if (els.reportModal) {
        els.reportModal.addEventListener('click', function (ev) {
          if (ev.target === els.reportModal) closeReportModal();
        });
      }
      $all('[data-ptv4-tab]').forEach(function (tabBtn) {
        tabBtn.addEventListener('click', function () {
          var tab = tabBtn.getAttribute('data-ptv4-tab');
          $all('[data-ptv4-tab]').forEach(function (b) {
            var active = b === tabBtn;
            b.classList.toggle('is-active', active);
            b.classList.toggle('app-btn-primary', active);
            b.classList.toggle('app-btn-secondary', !active);
          });
          $all('[data-ptv4-tab-panel]').forEach(function (p) {
            p.classList.toggle('is-active', p.getAttribute('data-ptv4-tab-panel') === tab);
          });
        });
      });
      if (els.feedbackForm) {
        els.feedbackForm.addEventListener('submit', function (ev) {
          ev.preventDefault();
          apiJson('submit_feedback', {
            rating_json: collectFeedbackRatings(),
            free_text: ($('[data-ptv4-fb-free]') || {}).value || ''
          }).then(function (out) {
            if (els.feedbackForm) els.feedbackForm.hidden = true;
            if (els.feedbackReward) els.feedbackReward.hidden = true;
            if (els.feedbackSent) els.feedbackSent.hidden = false;
            if (els.feedbackSentBadge) {
              els.feedbackSentBadge.hidden = !(out && out.contributor_badge_earned);
            }
            if (out && out.contributor_badge_earned && lastReport) {
              apiJson('get_report', {}).then(function (reportOut) {
                lastReport = reportOut.report;
              }).catch(function () {});
            }
          });
        });
      }
    }

    return {
      setAttemptId: function (id) {
        var nextId = parseInt(id, 10) || 0;
        if (nextId !== attemptId) lastReport = null;
        attemptId = nextId;
      },
      getAttemptId: resolveAttemptId,
      getLastReport: function () { return lastReport; },
      setLastReport: function (report) { lastReport = report || null; },
      renderReportModal: renderReportModal,
      closeReportModal: closeReportModal,
      fetchAndShowReport: fetchAndShowReport,
      initFeedbackForm: initFeedbackForm,
      bindEvents: bindEvents
    };
  }

  global.IPCAProgressTestV4Report = { create: create };
})(window);
