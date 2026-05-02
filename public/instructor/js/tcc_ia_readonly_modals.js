(function () {
    'use strict';
    var cohortId = 0;
    var selectedStudentId = 0;
    function cohortTimeZone() {
        return (window.__IA_TCC__ && window.__IA_TCC__.cohortTz) || window.tccCohortTimezone || 'UTC';
    }
    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>'"]/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c];
        });
    }

    function api(action, params) {
        params = params || {};
        params.action = action;
        return fetch('/instructor/api/theory_control_center_api.php?' + new URLSearchParams(params), {credentials: 'same-origin'}).then(function (r) { return r.json(); });
    }
    function openTccModal(title, bodyHtml, kicker) {
        document.querySelector('#tccModalOverlay .tcc-modal-kicker').textContent = kicker || 'Instructor Diagnostic';
        document.getElementById('tccModalTitle').textContent = title || 'Diagnostic';
        document.getElementById('tccModalBody').innerHTML = bodyHtml || '';
        var o = document.getElementById('tccModalOverlay');
        o.classList.add('open');
        o.setAttribute('aria-hidden', 'false');
    }

    function closeTccModal() {
        var o = document.getElementById('tccModalOverlay');
        o.classList.remove('open');
        o.setAttribute('aria-hidden', 'true');
    }
    function clampPct(v) {
        v = parseFloat(v);
        if (isNaN(v)) v = 0;
        return Math.max(0, Math.min(100, Math.round(v)));
    }
    function parseUtcDate(v) {
        if (!v) return null;
        var raw = String(v).trim();
        if (raw === '') return null;
        var iso = raw.indexOf('T') >= 0 ? raw : (raw.replace(' ', 'T') + 'Z');
        var d = new Date(iso);
        return isNaN(d.getTime()) ? null : d;
    }

    function partsInCohortTime(v) {
        var d = parseUtcDate(v);
        if (!d) return null;
        try {
            var fmt = new Intl.DateTimeFormat('en-US', {timeZone: cohortTimeZone(), weekday:'short', month:'short', day:'numeric', year:'numeric', hour:'2-digit', minute:'2-digit', hour12:false});
            var parts = {};
            fmt.formatToParts(d).forEach(function (p) { parts[p.type] = p.value; });
            return parts;
        } catch (e) {
            return null;
        }
    }

    function niceDate(v) {
        var p = partsInCohortTime(v);
        if (!p) return v ? String(v).slice(0, 16) : '—';
        return p.weekday + ' ' + p.month + ' ' + p.day + ', ' + p.year;
    }

    function niceDateTime(v) {
        var p = partsInCohortTime(v);
        if (!p) return v ? String(v).slice(0, 16) : '—';
        return p.weekday + ' ' + p.month + ' ' + p.day + ', ' + p.year + ' ' + p.hour + ':' + p.minute;
    }

    function niceTime(v) {
        var p = partsInCohortTime(v);
        if (!p) return '—';
        return p.hour + ':' + p.minute;
    }

    function prettyStatus(s) {
        s = String(s || '').replace(/_/g, ' ');
        return s ? s.charAt(0).toUpperCase() + s.slice(1) : 'Not started';
    }

    function formatScoreValue(value, max) {
        if (value === null || value === undefined || value === '') return '—';
        if (max !== undefined && max !== null && max !== '') return String(value) + ' / ' + String(max);
        return String(value);
    }

    function modalStatusRows(rows) {
        var html = '';
        rows.forEach(function (row) {
            html += '<div class="tcc-modal-row"><div class="tcc-modal-label">' + escapeHtml(row[0]) + '</div><div class="tcc-modal-value">' + escapeHtml(row[1]) + '</div></div>';
        });
        return html;
    }
    function sanitizeSummaryHtml(html) {
        html = String(html || '');
        var tpl = document.createElement('template');
        tpl.innerHTML = html;
        var allowedTags = {P:1,BR:1,UL:1,OL:1,LI:1,STRONG:1,B:1,EM:1,I:1,U:1,H1:1,H2:1,H3:1,H4:1,BLOCKQUOTE:1,SPAN:1,DIV:1,MARK:1};
        var walker = document.createTreeWalker(tpl.content, NodeFilter.SHOW_ELEMENT, null);
        var remove = [];
        while (walker.nextNode()) {
            var el = walker.currentNode;
            if (!allowedTags[el.tagName]) {
                remove.push(el);
                continue;
            }
            Array.prototype.slice.call(el.attributes).forEach(function (a) {
                if (a.name.toLowerCase() !== 'class') el.removeAttribute(a.name);
            });
        }
        remove.forEach(function (el) {
            var text = document.createTextNode(el.textContent || '');
            if (el.parentNode) el.parentNode.replaceChild(text, el);
        });
        return tpl.innerHTML;
    }

    function rawSummaryHtml(s) {
        var html = String((s && (s.summary_html || s.summaryHtml)) || '').trim();
        if (html !== '') return sanitizeSummaryHtml(html);
        var plain = String((s && (s.summary_plain || s.summary_text)) || '').trim();
        return plain !== '' ? escapeHtml(plain).replace(/\n/g, '<br>') : 'No student summary text found.';
    }

    function reviewStatusPill(status) {
        var clean = String(status || 'neutral').toLowerCase();
        return '<span class="tcc-review-pill ' + escapeHtml(clean) + '">' + escapeHtml(prettyStatus(clean)) + '</span>';
    }

    function reviewScoreBar(score) {
        var n = (score !== null && score !== undefined && score !== '') ? clampPct(score) : 0;
        var cls = n >= 85 ? 'ok' : (n >= 70 ? 'warn' : 'danger');
        return '<div class="tcc-review-score-row"><div class="tcc-review-score-bar"><span class="' + cls + '" style="width:' + n + '%;"></span></div><div class="tcc-review-score-value">' + (score !== null && score !== undefined && score !== '' ? escapeHtml(score) + '%' : '—') + '</div></div>';
    }

    function aiSignalClass(value) {
        var s = String(value || '').toLowerCase();
        if (s.indexOf('high') >= 0 || s.indexOf('likely') >= 0 || s.indexOf('weak') >= 0 || s.indexOf('poor') >= 0) return 'danger';
        if (s.indexOf('medium') >= 0 || s.indexOf('possible') >= 0 || s.indexOf('developing') >= 0 || s.indexOf('adequate') >= 0) return 'warn';
        if (s.indexOf('low') >= 0 || s.indexOf('unlikely') >= 0 || s.indexOf('strong') >= 0 || s.indexOf('excellent') >= 0) return 'ok';
        return '';
    }

    function aiList(items, maxItems) {
        items = Array.isArray(items) ? items : [];
        maxItems = maxItems === undefined || maxItems === null ? 8 : parseInt(maxItems, 10) || 8;
        if (!items.length) return '<div class="tcc-modal-muted">No items generated yet.</div>';
        var html = '<ul>';
        items.slice(0, maxItems).forEach(function (item) { html += '<li>' + escapeHtml(item) + '</li>'; });
        return html + '</ul>';
    }

    function oralIntegrityLikelihoodClass(value) {
        var s = String(value || '').toLowerCase();
        if (s.indexOf('not eval') >= 0) return '';
        if (/\bhigh\b/.test(s)) return 'danger';
        if (/\bmedium\b/.test(s)) return 'warn';
        if (/\blow\b/.test(s)) return 'ok';
        return '';
    }

    function mergeKnowledgeFeedbackWithOral(knowledgeFb, oral) {
        oral = oral || {};
        knowledgeFb = knowledgeFb || {};
        var kf = {
            strong_points: [].concat(knowledgeFb.strong_points || []),
            weak_points: [].concat(knowledgeFb.weak_points || []),
            suggestions: [].concat(knowledgeFb.suggestions || [])
        };
        var risk = String(oral.overall_integrity_risk || '').trim().toLowerCase();
        if (risk === 'high') {
            var w = oral.integrity_concern_weak_points;
            var s = oral.integrity_concern_suggestions;
            if (Array.isArray(w)) {
                w.forEach(function (x) {
                    var t = String(x || '').trim();
                    if (t) kf.weak_points.push(t);
                });
            }
            if (Array.isArray(s)) {
                s.forEach(function (x) {
                    var t = String(x || '').trim();
                    if (t) kf.suggestions.push(t);
                });
            }
            if ((!w || !w.length) && (!s || !s.length)) {
                kf.weak_points.push('Oral integrity signals indicate high overall integrity risk (advisory only — verify with human review).');
                kf.suggestions.push('Follow institutional procedures for suspected irregular test behavior; have a human reviewer listen to the attempt audio and timing before any formal action.');
            }
        }
        function uniq(arr) {
            var seen = {};
            return arr.filter(function (x) {
                var k = String(x);
                if (seen[k]) return false;
                seen[k] = true;
                return true;
            });
        }
        kf.strong_points = uniq(kf.strong_points);
        kf.weak_points = uniq(kf.weak_points);
        kf.suggestions = uniq(kf.suggestions);
        return kf;
    }

    function renderReferencesListBox(refs) {
        refs = Array.isArray(refs) ? refs : [];
        var body = refs.length
            ? aiList(refs, 24)
            : '<div class="tcc-modal-muted">None listed.</div>';
        return '<div class="tcc-ai-list-box" style="margin-top:12px;"><div class="tcc-ai-list-title">References</div>' + body + '</div>';
    }

    function renderProgressTestOralAndKnowledgeSection(oral, mergedKnowledge, aiLoading) {
        oral = oral || {};
        mergedKnowledge = mergedKnowledge || {};
        if (aiLoading) {
            return '<div class="tcc-loading" style="margin-bottom:12px;">Generating AI oral-integrity analysis…</div>'
                + '<div class="tcc-modal-section-title" style="margin-top:4px;">Answer quality (saved test debrief)</div>'
                + '<div class="tcc-oral-ai-cols">' +
                '<div class="tcc-ai-list-box"><div class="tcc-ai-list-title">STRONG POINTS</div>' + aiList(mergedKnowledge.strong_points || [], 16) + '</div>' +
                '<div class="tcc-ai-list-box"><div class="tcc-ai-list-title">WEAK POINTS</div>' + aiList(mergedKnowledge.weak_points || [], 16) + '</div>' +
                '<div class="tcc-ai-list-box"><div class="tcc-ai-list-title">SUGGESTIONS</div>' + aiList(mergedKnowledge.suggestions || [], 16) + '</div>' +
                '</div>';
        }
        var hasLikelihood = !!(oral.natural_speech_likelihood || oral.script_reading_likelihood || oral.multiple_voices_or_coaching_likelihood || oral.overall_integrity_risk);
        var grid = '';
        if (hasLikelihood) {
            grid = '<div class="tcc-ai-result-grid" style="margin-top:4px;">';
            grid += '<div class="tcc-ai-result-card ' + oralIntegrityLikelihoodClass(oral.natural_speech_likelihood) + '"><div class="tcc-ai-result-label">Natural speech</div><div class="tcc-ai-result-value">' + escapeHtml(oral.natural_speech_likelihood || '—') + '</div></div>';
            grid += '<div class="tcc-ai-result-card ' + oralIntegrityLikelihoodClass(oral.script_reading_likelihood) + '"><div class="tcc-ai-result-label">Script reading</div><div class="tcc-ai-result-value">' + escapeHtml(oral.script_reading_likelihood || '—') + '</div></div>';
            grid += '<div class="tcc-ai-result-card ' + oralIntegrityLikelihoodClass(oral.multiple_voices_or_coaching_likelihood) + '"><div class="tcc-ai-result-label">Other voices / coaching</div><div class="tcc-ai-result-value">' + escapeHtml(oral.multiple_voices_or_coaching_likelihood || '—') + '</div></div>';
            grid += '<div class="tcc-ai-result-card ' + oralIntegrityLikelihoodClass(oral.overall_integrity_risk) + '"><div class="tcc-ai-result-label">Overall integrity risk</div><div class="tcc-ai-result-value">' + escapeHtml(oral.overall_integrity_risk || '—') + '</div></div>';
            grid += '</div>';
            grid += renderReferencesListBox(oral.official_references || []);
        } else {
            grid = '<div class="tcc-modal-muted" style="margin-bottom:12px;">AI oral likelihoods will appear here once generated.</div>';
        }
        grid += '<div class="tcc-modal-section-title" style="margin-top:18px;">Answer quality (saved test debrief)</div>';
        grid += '<div class="tcc-oral-ai-cols">';
        grid += '<div class="tcc-ai-list-box"><div class="tcc-ai-list-title">STRONG POINTS</div>' + aiList(mergedKnowledge.strong_points || [], 16) + '</div>';
        grid += '<div class="tcc-ai-list-box"><div class="tcc-ai-list-title">WEAK POINTS</div>' + aiList(mergedKnowledge.weak_points || [], 16) + '</div>';
        grid += '<div class="tcc-ai-list-box"><div class="tcc-ai-list-title">SUGGESTIONS</div>' + aiList(mergedKnowledge.suggestions || [], 16) + '</div>';
        grid += '</div>';
        return grid;
    }

    function renderAiAnalysisObject(ai) {
        ai = ai || {};
        var copy = ai.copy_paste_likelihood || 'Not generated';
        var tool = ai.ai_tool_likelihood || 'Not generated';
        var sim = ai.highest_similarity || 'Not generated';
        var simStudent = ai.highest_similarity_student || '—';
        var simPct = (ai.highest_similarity_pct !== undefined && ai.highest_similarity_pct !== null) ? String(ai.highest_similarity_pct) + '%' : '—';
        var understandingLabel = ai.deep_understanding_label || ai.deep_understanding || ai.understanding || 'Not generated';
        var understandingScore = (ai.deep_understanding_score !== undefined && ai.deep_understanding_score !== null && String(ai.deep_understanding_score) !== '') ? String(ai.deep_understanding_score) + '%' : '';
        var html = '<div class="tcc-ai-result-grid">';
        html += '<div class="tcc-ai-result-card ' + aiSignalClass(copy) + '"><div class="tcc-ai-result-label">Copy/Paste</div><div class="tcc-ai-result-value">' + escapeHtml(copy) + '</div></div>';
        html += '<div class="tcc-ai-result-card ' + aiSignalClass(tool) + '"><div class="tcc-ai-result-label">AI Tool Use</div><div class="tcc-ai-result-value">' + escapeHtml(tool) + '</div></div>';
        html += '<div class="tcc-ai-result-card ' + aiSignalClass(sim) + '"><div class="tcc-ai-result-label">Similarity</div><div class="tcc-ai-result-value">' + escapeHtml(sim) + ' · ' + escapeHtml(simPct) + '</div><div class="tcc-modal-muted" style="margin-top:5px;">' + escapeHtml(simStudent) + '</div></div>';
        html += '<div class="tcc-ai-result-card ' + aiSignalClass(understandingLabel) + '"><div class="tcc-ai-result-label">Understanding</div><div class="tcc-ai-result-value">' + escapeHtml(understandingLabel) + (understandingScore ? (' · ' + escapeHtml(understandingScore)) : '') + '</div></div>';
        html += '</div>';
        html += '<div class="tcc-ai-take-box"><strong>Instructor quick take:</strong><br>' + escapeHtml(ai.instructor_quick_take || ai.quality_feedback || 'No AI analysis generated yet.') + '</div>';
        html += '<div class="tcc-ai-list-grid">';
        html += '<div class="tcc-ai-list-box"><div class="tcc-ai-list-title">STRONG POINTS</div>' + aiList(ai.substantially_good) + '</div>';
        html += '<div class="tcc-ai-list-box"><div class="tcc-ai-list-title">WEAK POINTS</div>' + aiList(ai.substantially_weak) + '</div>';
        html += '<div class="tcc-ai-list-box"><div class="tcc-ai-list-title">SUGGESTIONS</div>' + aiList(ai.improvement_suggestions || ai.suggestions) + '</div>';
        html += '</div>';
        if (ai.student_safe_feedback) html += '<div class="tcc-ai-take-box"><strong>Student-safe feedback:</strong><br>' + escapeHtml(ai.student_safe_feedback) + '</div>';
        return html;
    }

    function renderAiInterpretation(ai, studentId, lessonId) {
        ai = ai || {};
        var hasGenerated = (ai.analysis_status === 'generated' || (ai.copy_paste_likelihood && String(ai.copy_paste_likelihood).indexOf('Not generated') === -1) || (ai.deep_understanding_label && String(ai.deep_understanding_label).indexOf('Not generated') === -1 && String(ai.deep_understanding_label).indexOf('Not evaluated') === -1));
        var panelId = 'aiPanel_' + parseInt(studentId || 0, 10) + '_' + parseInt(lessonId || 0, 10);
        var btnId = 'aiBtn_' + parseInt(studentId || 0, 10) + '_' + parseInt(lessonId || 0, 10);
        var body = hasGenerated ? renderAiAnalysisObject(ai) : '<div class="tcc-ai-loading-box">No AI analysis generated yet. Click “Generate AI Analysis” to create an instructor advisory review for this summary.</div>';
        return '<div id="' + panelId + '" class="tcc-ai-live-panel"><div class="tcc-ai-live-head"><div><div class="tcc-ai-live-title">AI Summary Analysis</div><div class="tcc-ai-live-sub">Advisory only. Does not change progression status or canonical records.</div></div><button id="' + btnId + '" type="button" class="tcc-ai-action-btn" data-student-id="' + parseInt(studentId || 0, 10) + '" data-lesson-id="' + parseInt(lessonId || 0, 10) + '" data-panel-id="' + escapeHtml(panelId) + '" data-btn-id="' + escapeHtml(btnId) + '" onclick="window.generateAiSummaryAnalysisFromButton(this)">Generate AI Analysis</button></div><div class="tcc-ai-live-body">' + body + '</div></div>';
    }

    function generateAiSummaryAnalysis(studentId, lessonId, panelId, btnId) {
        studentId = parseInt(studentId, 10) || selectedStudentId;
        lessonId = parseInt(lessonId, 10) || 0;
        var panel = document.getElementById(panelId);
        var btn = document.getElementById(btnId);
        if (!panel || !studentId || !lessonId || !cohortId) {
            openTccModal('AI Summary Analysis', '<div class="tcc-error">Missing cohort, student, or lesson id.</div>');
            return;
        }
        var body = panel.querySelector('.tcc-ai-live-body');
        if (btn) { btn.disabled = true; btn.textContent = 'Analyzing…'; }
        if (body) body.innerHTML = '<div class="tcc-ai-loading-box">Generating AI analysis. This is advisory only and will not change student progression state…</div>';
        api('ai_summary_analysis', {cohort_id: cohortId, student_id: studentId, lesson_id: lessonId, force: 1}).then(function (resp) {
            if (!resp.ok) {
                if (body) body.innerHTML = '<div class="tcc-ai-error-box">' + escapeHtml(resp.message || resp.error || 'AI analysis failed.') + '</div>';
                return;
            }
            if (body) body.innerHTML = renderAiAnalysisObject(resp.analysis || {});
        }).catch(function () {
            if (body) body.innerHTML = '<div class="tcc-ai-error-box">Unable to generate AI analysis.</div>';
        }).finally(function () {
            if (btn) { btn.disabled = false; btn.textContent = 'Regenerate AI Analysis'; }
        });
    }

    function generateAiSummaryAnalysisFromButton(btn) {
        generateAiSummaryAnalysis(parseInt(btn.getAttribute('data-student-id') || '0', 10), parseInt(btn.getAttribute('data-lesson-id') || '0', 10), btn.getAttribute('data-panel-id') || '', btn.getAttribute('data-btn-id') || '');
    }
    function tccAttemptDuration(started, completed) {
        try {
            var a = new Date(String(started || '').replace(' ', 'T') + 'Z').getTime();
            var b = new Date(String(completed || '').replace(' ', 'T') + 'Z').getTime();
            if (isNaN(a) || isNaN(b) || b <= a) return '—';
            var sec = Math.round((b - a) / 1000);
            var m = Math.floor(sec / 60);
            var s = sec % 60;
            return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        } catch (e) {
            return '—';
        }
    }

    function tccToggleInlineAudio(btn) {
        if (!btn) return;
        var wrap = btn.parentNode;
        if (!wrap) return;
        var a = wrap.querySelector('audio');
        if (!a) return;
        if (a.paused) {
            a.play();
            btn.textContent = 'Pause audio';
        } else {
            a.pause();
            btn.textContent = 'Play audio';
        }
    }

    function renderAttemptItems(items) {
        if (!items || !items.length) return '<div class="tcc-modal-muted">No answer-level items found for this attempt.</div>';
        var html = '<div class="tcc-chat-thread">';
        items.forEach(function (item, idx) {
            var score = formatScoreValue(item.score_points, item.max_points);
            var q = item.prompt || item.question_text || 'Question';
            var transcript = item.transcript_text || item.answer_text || item.student_answer || '—';
            var audio = item.audio_url || item.audio_path || item.answer_audio_url || item.recording_url || item.media_url || '';
            html += '<div class="tcc-chat-bubble ai"><strong>Question ' + (idx + 1) + ':</strong> ' + escapeHtml(q) + '</div>';
            html += '<div class="tcc-chat-bubble student">';
            if (audio) {
                html += '<div class="tcc-answer-audio-wrap" style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">';
                html += '<button type="button" class="tcc-btn secondary" onclick="event.stopPropagation();tccToggleInlineAudio(this)">Play audio</button>';
                html += '<audio preload="none" src="' + escapeHtml(audio) + '"></audio></div>';
            }
            html += escapeHtml(transcript) + '<div class="tcc-chat-score">Score: ' + escapeHtml(score) + '</div></div>';
        });
        html += '</div>';
        return html;
    }

    function renderAttemptRowSummary(a) {
        var score = a.score_pct !== null && a.score_pct !== undefined ? a.score_pct + '%' : '—';
        var passFail = parseInt(a.pass_gate_met, 10) === 1 ? 'PASS' : 'FAIL';
        var dur = tccAttemptDuration(a.started_at, a.completed_at);
        return '<div style="flex:1;min-width:0;"><div class="tcc-attempt-title">Attempt ' + escapeHtml(a.attempt || '—') + ' · ' + escapeHtml(passFail) + ' · ' + escapeHtml(a.formal_result_code || a.status || '') + '</div>'
            + '<div class="tcc-attempt-meta">Started: ' + escapeHtml(niceDateTime(a.started_at)) + ' · Completed: ' + escapeHtml(niceDateTime(a.completed_at)) + ' (Duration: ' + escapeHtml(dur) + ')</div></div>'
            + '<span class="tcc-score-pill ' + (parseInt(a.pass_gate_met, 10) === 1 ? 'ok' : 'danger') + '">' + escapeHtml(score) + '</span>';
    }

    function renderAttemptDetailInner(a) {
        var jsonBlock = '<details class="tcc-inline-json"><summary style="cursor:pointer;font-weight:800;color:#64748b;">Raw attempt JSON (troubleshooting)</summary><pre class="tcc-debug-pre">' + escapeHtml(JSON.stringify(a, null, 2)) + '</pre></details>';
        return renderAttemptItems(a.items || []) + jsonBlock;
    }

    function renderAttemptCards(attempts) {
        if (!attempts || !attempts.length) return '<div class="tcc-empty">No progress test attempts found.</div>';
        var list = attempts.slice().sort(function (a, b) {
            return (parseInt(b.attempt, 10) || 0) - (parseInt(a.attempt, 10) || 0);
        });

        var passedOnFirstAttempt = list.length === 1
            && parseInt(list[0].attempt, 10) === 1
            && parseInt(list[0].pass_gate_met, 10) === 1
            && !list[0].is_stale_attempt;

        if (passedOnFirstAttempt) {
            var a = list[0];
            return '<div class="tcc-attempt-stack">'
                + '<div class="tcc-attempt-card"><div class="tcc-attempt-head"><div><div class="tcc-attempt-title">Attempt 1 · PASS · ' + escapeHtml(a.formal_result_code || a.status || '') + '</div>'
                + '<div class="tcc-attempt-meta">Started: ' + escapeHtml(niceDateTime(a.started_at)) + ' · Completed: ' + escapeHtml(niceDateTime(a.completed_at)) + ' (Duration: ' + escapeHtml(tccAttemptDuration(a.started_at, a.completed_at)) + ')</div></div>'
                + '<span class="tcc-score-pill ok">' + escapeHtml(a.score_pct !== null && a.score_pct !== undefined ? a.score_pct + '%' : '—') + '</span></div>'
                + renderAttemptDetailInner(a)
                + '</div></div>';
        }

        var html = '<div class="tcc-attempt-stack">';
        list.forEach(function (a) {
            var stale = !!a.is_stale_attempt;
            var detCls = 'tcc-attempt-details' + (stale ? ' tcc-attempt-stale' : '');
            html += '<details class="' + detCls + '">';
            html += '<summary class="tcc-attempt-row-summary">' + renderAttemptRowSummary(a) + '</summary>';
            html += '<div class="tcc-attempt-detail-body">' + renderAttemptDetailInner(a) + '</div>';
            html += '</details>';
        });
        html += '</div>';
        return html;
    }

    function buildProgressTestModalHtml(d, oralAnalysis, aiLoading) {
        var lesson = d.lesson || {};
        var sub = escapeHtml((lesson.course_title || 'Module') + ' · ' + (lesson.lesson_title || 'Lesson'));
        var mergedK = mergeKnowledgeFeedbackWithOral(d.knowledge_feedback || {}, oralAnalysis || {});
        var oralBlock = renderProgressTestOralAndKnowledgeSection(oralAnalysis || {}, mergedK, !!aiLoading);
        return '<div class="tcc-modal-section full tcc-progress-test-lesson-spacer"><div class="tcc-modal-section-title">Lesson</div><div class="tcc-modal-muted">' + sub + '</div></div>'
            + '<div class="tcc-oral-ai-panel"><div class="tcc-modal-section-title">AI oral integrity review</div>' + oralBlock + '</div>'
            + renderAttemptCards(d.attempts || []);
    }

    function openAttemptDetails(studentId, lessonId) {
        studentId = parseInt(studentId, 10) || selectedStudentId;
        lessonId = parseInt(lessonId, 10) || 0;
        if (!cohortId || !studentId || !lessonId) {
            openTccModal('Progress Test Details', '<div class="tcc-error">Missing cohort, student, or lesson id.</div>');
            return;
        }
        openTccModal('Progress Test Details', '<div class="tcc-loading">Loading progress test attempts…</div>');
        api('lesson_attempts_detail', {cohort_id: cohortId, student_id: studentId, lesson_id: lessonId}).then(function (resp) {
            if (!resp.ok) {
                openTccModal('Progress Test Details', '<div class="tcc-error">' + escapeHtml(resp.message || resp.error || 'Unable to load attempts.') + '</div>');
                return;
            }
            var d = resp.data || {};
            var oral = d.oral_analysis || {};
            var needOral = (!oral || (!oral.natural_speech_likelihood && !oral.script_reading_likelihood && !oral.multiple_voices_or_coaching_likelihood && !oral.overall_integrity_risk)) || d.oral_analysis_stale === true;
            if (!needOral) {
                openTccModal('Progress Test Details', buildProgressTestModalHtml(d, oral, false));
                return;
            }
            openTccModal('Progress Test Details', buildProgressTestModalHtml(d, {}, true));
            api('ai_progress_test_analysis', {cohort_id: cohortId, student_id: studentId, lesson_id: lessonId}).then(function (ar) {
                if (ar.ok) {
                    oral = ar.analysis || {};
                }
                openTccModal('Progress Test Details', buildProgressTestModalHtml(d, oral, false));
            }).catch(function () {
                openTccModal('Progress Test Details', buildProgressTestModalHtml(d, {}, false));
            });
        }).catch(function () {
            openTccModal('Progress Test Details', '<div class="tcc-error">Unable to load attempts.</div>');
        });
    }
    function renderTheorySummaryModalInner(d, studentId, lessonId, aiBanner) {
        d = d || {};
        var lesson = d.lesson || {};
        var s = d.summary || {};
        var ai = d.ai_interpretation || {};
        var summaryHtml = rawSummaryHtml(s);
        var banner = aiBanner ? '<div style="padding:10px 12px;background:#eff6ff;border-radius:12px;border:1px solid rgba(29,79,137,.18);margin-bottom:12px;font-size:13px;line-height:1.45;color:#0f2745;font-weight:700;">' + escapeHtml(aiBanner) + '</div>' : '';
        return banner + '<div class="tcc-modal-grid"><div class="tcc-modal-section"><div class="tcc-modal-section-title">Review Status</div>' + modalStatusRows([['Module', lesson.course_title || '—'], ['Lesson', lesson.lesson_title || '—'], ['Updated', niceDateTime(s.updated_at)]]) + '<div style="margin-top:10px;">' + reviewStatusPill(s.review_status || 'neutral') + '</div></div><div class="tcc-modal-section"><div class="tcc-modal-section-title">Review Score</div>' + reviewScoreBar(s.review_score) + '</div><div class="tcc-modal-section full"><div class="tcc-modal-section-title">Student Summary</div><div class="tcc-summary-paper nb-content">' + summaryHtml + '</div></div><div class="tcc-modal-section full"><div class="tcc-modal-section-title">AI Interpretation</div>' + renderAiInterpretation(ai, studentId, lessonId) + '</div><div class="tcc-modal-section full"><div class="tcc-modal-section-title">Instructor Feedback</div><div class="tcc-modal-readable">' + escapeHtml(s.review_feedback || s.review_notes_by_instructor || 'No instructor feedback recorded for this summary yet.') + '</div></div></div>';
    }

    function openLessonSummary(studentId, lessonId) {
        studentId = parseInt(studentId, 10) || selectedStudentId;
        lessonId = parseInt(lessonId, 10) || 0;
        if (!cohortId || !studentId || !lessonId) {
            openTccModal('Theory Summary', '<div class="tcc-error">Missing cohort, student, or lesson id.</div>');
            return;
        }
        var title = 'Lesson Summary';
        openTccModal(title, '<div class="tcc-loading">Loading theory summary…</div>');
        api('lesson_summary_detail', {cohort_id: cohortId, student_id: studentId, lesson_id: lessonId}).then(function (resp) {
            if (!resp.ok) {
                openTccModal('Theory Summary', '<div class="tcc-error">' + escapeHtml(resp.message || resp.error || 'Unable to load summary.') + '</div>');
                return;
            }
            var d = resp.data || {};
            var lesson = d.lesson || {};
            title = lesson.lesson_title || 'Theory Summary';
            var ai = d.ai_interpretation || {};
            var needsAi = d.ai_cache_stale === true || String(ai.analysis_status || '') !== 'generated';
            function paint(banner) {
                openTccModal(title, renderTheorySummaryModalInner(d, studentId, lessonId, banner));
            }
            if (!needsAi) {
                paint('AI status: advisory analysis is stored for this summary revision.');
                return;
            }
            paint('AI status: generating advisory analysis for the current summary text…');
            api('ai_summary_analysis', {cohort_id: cohortId, student_id: studentId, lesson_id: lessonId}).then(function (air) {
                if (air.ok && air.analysis) {
                    d.ai_interpretation = Object.assign({}, ai, air.analysis);
                }
                paint(air.ok ? 'AI status: generated and stored; tied to the current summary revision.' : 'AI status: generation failed — you can retry with “Regenerate AI Analysis”.');
            }).catch(function () {
                paint('AI status: request failed — try “Regenerate AI Analysis”.');
            });
        }).catch(function () {
            openTccModal('Theory Summary', '<div class="tcc-error">Unable to load summary.</div>');
        });
    }
    function iaInitTccReadonlyModals() {
        var cfg = window.__IA_TCC__ || {};
        cohortId = parseInt(cfg.cohortId, 10) || 0;
        selectedStudentId = parseInt(cfg.studentId, 10) || 0;
        window.tccCohortTimezone = cohortTimeZone();
    }
    window.iaInitTccReadonlyModals = iaInitTccReadonlyModals;
    window.iaOpenAttemptDetailsModal = function () {
        var cfg = window.__IA_TCC__ || {};
        openAttemptDetails(parseInt(cfg.studentId, 10) || 0, parseInt(cfg.lessonId, 10) || 0);
    };
    window.iaOpenLessonSummaryModal = function () {
        var cfg = window.__IA_TCC__ || {};
        openLessonSummary(parseInt(cfg.studentId, 10) || 0, parseInt(cfg.lessonId, 10) || 0);
    };
    window.closeTccModal = closeTccModal;
    window.generateAiSummaryAnalysisFromButton = generateAiSummaryAnalysisFromButton;
})();
