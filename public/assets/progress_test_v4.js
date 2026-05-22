(function () {
  'use strict';

  var cfg = window.IPCAProgressTestV4Config || {};
  var CARD = { READY: 'ready', ASKING: 'asking', LISTENING: 'listening', EVALUATING: 'evaluating', CLARIFICATION: 'clarification', COMPLETE: 'complete' };
  var START_ANSWER_MS = 30000;
  var RECORDING_MS = 45000;
  var CHUNK_MS = 1500;
  var TRANSCRIPT = {
    preidle: 'Your spoken answer will appear here after you stop recording.',
    idle: 'Your spoken answer will appear here after you stop recording.',
    recording: 'Recording your answer…',
    listening: 'Listening carefully…',
    processing: 'Processing your answer…',
    failed: 'We could not clearly transcribe your answer. Please try again.',
    noAudio: 'Audio was not received. Please try again.',
    uploadFailed: 'Audio upload failed. Please try again.'
  };
  var DEBUG_PTV4 = true;

  var DEBUG_PIN = '5014';
  var LIKERT = ['Strongly agree', 'Agree', 'Neutral', 'Disagree', 'Strongly disagree'];
  var ISSUE_OPTS = ['No', 'Audio problem', 'Transcript problem', 'Question unclear', 'Page got stuck', 'Other'];

  var page = document.querySelector('[data-ptv4-root]');
  if (!page) return;

  function $(sel) { return page.querySelector(sel); }
  function $all(sel) { return page.querySelectorAll(sel); }

  var els = {
    root: page,
    status: $('[data-ptv4-status]'),
    score: $('[data-ptv4-score]'),
    attempt: $('[data-ptv4-attempt]'),
    bar: $('[data-ptv4-bar]'),
    current: $('[data-ptv4-current]'),
    evaluated: $('[data-ptv4-evaluated]'),
    final: $('[data-ptv4-final]'),
    card: $('[data-ptv4-card]'),
    qnum: $('[data-ptv4-qnum]'),
    question: $('[data-ptv4-question]'),
    transcript: $('[data-ptv4-transcript]'),
    processing: $('[data-ptv4-processing]'),
    processingLabel: $('[data-ptv4-processing-label]'),
    processingFill: $('[data-ptv4-processing-fill]'),
    feedbackPanel: $('[data-ptv4-feedback-panel]'),
    feedbackScore: $('[data-ptv4-feedback-score]'),
    feedbackText: $('[data-ptv4-feedback-text]'),
    feedback: $('[data-ptv4-feedback]'),
    hint: $('[data-ptv4-hint]'),
    recordHint: $('[data-ptv4-record-hint]'),
    timer: $('[data-ptv4-timer]'),
    timerFill: $('[data-ptv4-timer-fill]'),
    timerLabel: $('[data-ptv4-timer-label]'),
    timerNote: $('[data-ptv4-timer-note]'),
    mayaStatus: $('[data-ptv4-maya-status]'),
    studentStatus: $('[data-ptv4-student-status]'),
    mayaFrame: $('[data-ptv4-maya-click]'),
    video: $('[data-ptv4-video]'),
    videoFallback: $('[data-ptv4-video-fallback]'),
    beginTest: $('[data-ptv4-begin-test]'),
    startAnswer: $('[data-ptv4-start-answer]'),
    stopAnswer: $('[data-ptv4-stop-answer]'),
    replay: $('[data-ptv4-replay]'),
    clarify: $('[data-ptv4-clarify]'),
    next: $('[data-ptv4-next]'),
    myReport: $('[data-ptv4-my-report]'),
    retry: $('[data-ptv4-retry]'),
    end: $('[data-ptv4-end]'),
    reportModal: $('[data-ptv4-report-modal]'),
    reportClose: $('[data-ptv4-report-close]'),
    reportSubtitle: $('[data-ptv4-report-subtitle]'),
    reportMotivation: $('[data-ptv4-report-motivation]'),
    reportSummary: $('[data-ptv4-report-summary]'),
    reportQuestions: $('[data-ptv4-report-questions]'),
    reportRecommendation: $('[data-ptv4-report-recommendation]'),
    feedbackForm: $('[data-ptv4-feedback-form]'),
    feedbackSent: $('[data-ptv4-feedback-sent]'),
    debugModal: $('[data-ptv4-debug-modal]'),
    debugClose: $('[data-ptv4-debug-close]'),
    debugPin: $('[data-ptv4-debug-pin]'),
    debugUnlock: $('[data-ptv4-debug-unlock]'),
    debugLog: $('[data-ptv4-debug-log]'),
    debugPinView: $('[data-ptv4-debug-pin-view]'),
    bugReport: $('[data-ptv4-bug-report]'),
    bugSent: $('[data-ptv4-bug-sent]')
  };

  var state = null;
  var attemptId = 0;
  var currentItem = null;
  var cardState = CARD.READY;
  var testStarted = false;
  var sessionConnecting = false;
  var greetingReady = false;
  var oralQuestionsStarted = false;
  var clarificationMode = false;
  var clarificationPending = false;
  var originalAnswer = '';
  var clarificationQuestion = '';
  var liveTranscript = '';
  var chunkIndex = 0;
  var hasRecordedAudio = false;
  var recordingStartedAt = 0;
  var mediaRecorder = null;
  var recordStream = null;
  var micStream = null;
  var cameraStream = null;
  var chunkUploadChain = Promise.resolve();
  var chunksReceived = 0;
  var chunksUploaded = 0;
  var pendingEvalOpts = null;
  var pc = null;
  var dc = null;
  var remoteAudio = null;
  var questionAudio = new Audio();
  questionAudio.preload = 'auto';
  var prepTimer = null;
  var timerInterval = null;
  var timerDeadline = 0;
  var timerTotalMs = 0;
  var timerMode = '';
  var audioCtx = null;
  var micAnalyser = null;
  var micAnalyserRaf = 0;
  var mayaBarTimer = null;
  var studentBarEls = [];
  var awaitingNextQuestion = false;
  var feedbackSpeechDone = false;
  var testCompleteReady = false;
  var displayItem = null;
  var lastReport = null;
  var processingTimer = null;
  var processingPct = 0;
  var debugEvents = [];
  var mayaClickCount = 0;
  var mayaClickTimer = null;
  var wordRevealWords = [];
  var wordRevealIndex = 0;
  var wordRevealTimer = null;
  var dcOpenPromise = null;

  function text(el, v) { if (el) el.textContent = v; }
  function clamp(n, a, b) { return Math.max(a, Math.min(b, n)); }
  function setStatus(msg) { text(els.status, msg); }

  function dbg() {
    if (!DEBUG_PTV4) return;
    var args = ['[PTV4]'].concat([].slice.call(arguments));
    console.log.apply(console, args);
  }

  function logEvent(type, detail, meta) {
    var ev = {
      ts: new Date().toISOString(),
      type: type,
      detail: detail || '',
      meta: meta || {},
      item_id: currentItem ? currentItem.id : null
    };
    debugEvents.push(ev);
    dbg(type, detail, meta || {});
  }

  function playBeep(kind) {
    try {
      var AudioContextCtor = window.AudioContext || window.webkitAudioContext;
      if (!AudioContextCtor) return;
      audioCtx = audioCtx || new AudioContextCtor();
      if (audioCtx.state === 'suspended') audioCtx.resume();
      var oscillator = audioCtx.createOscillator();
      var gain = audioCtx.createGain();
      oscillator.type = 'sine';
      oscillator.frequency.value = kind === 'start' ? 880 : 330;
      gain.gain.setValueAtTime(0.001, audioCtx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.16, audioCtx.currentTime + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.5);
      oscillator.connect(gain);
      gain.connect(audioCtx.destination);
      oscillator.start();
      oscillator.stop(audioCtx.currentTime + 0.52);
    } catch (e) {}
  }

  function waitForDataChannel() {
    if (dc && dc.readyState === 'open') return Promise.resolve();
    if (!dcOpenPromise) {
      dcOpenPromise = new Promise(function (resolve) {
        if (!dc) { resolve(); return; }
        if (dc.readyState === 'open') { resolve(); return; }
        dc.addEventListener('open', function () { resolve(); }, { once: true });
        window.setTimeout(resolve, 4000);
      });
    }
    return dcOpenPromise;
  }

  function stopWordReveal() {
    if (wordRevealTimer) clearInterval(wordRevealTimer);
    wordRevealTimer = null;
    questionAudio.ontimeupdate = null;
  }

  function startWordReveal(fullText, audioEl) {
    stopWordReveal();
    wordRevealWords = String(fullText || '').split(/\s+/).filter(Boolean);
    wordRevealIndex = 0;
    text(els.question, '');
    if (!wordRevealWords.length) return;
    if (audioEl) {
      audioEl.ontimeupdate = function () {
        if (!audioEl.duration || !isFinite(audioEl.duration)) return;
        var target = Math.max(1, Math.ceil((audioEl.currentTime / audioEl.duration) * wordRevealWords.length));
        if (target > wordRevealIndex) {
          wordRevealIndex = target;
          text(els.question, wordRevealWords.slice(0, wordRevealIndex).join(' '));
        }
      };
      return;
    }
    var ms = Math.max(120, Math.min(320, Math.floor(7000 / Math.max(1, wordRevealWords.length))));
    wordRevealTimer = setInterval(function () {
      wordRevealIndex++;
      text(els.question, wordRevealWords.slice(0, wordRevealIndex).join(' '));
      if (wordRevealIndex >= wordRevealWords.length) stopWordReveal();
    }, ms);
  }

  function finishWordReveal(fullText) {
    stopWordReveal();
    text(els.question, fullText || wordRevealWords.join(' '));
  }

  function startProcessingProgress(labelPrefix) {
    processingPct = 5;
    if (els.processing) els.processing.hidden = false;
    if (els.transcript) els.transcript.hidden = true;
    updateProcessingProgress(labelPrefix, processingPct);
    if (processingTimer) clearInterval(processingTimer);
    processingTimer = setInterval(function () {
      if (processingPct < 92) {
        processingPct += Math.random() * 8 + 2;
        updateProcessingProgress(labelPrefix, Math.min(92, Math.round(processingPct)));
      }
    }, 450);
  }

  function updateProcessingProgress(labelPrefix, pct) {
    var label = (labelPrefix || 'Processing your answer') + '… ' + pct + '%';
    text(els.processingLabel, label);
    if (els.processingFill) els.processingFill.style.width = clamp(pct, 0, 100) + '%';
  }

  function finishProcessingProgress(labelPrefix) {
    if (processingTimer) clearInterval(processingTimer);
    processingTimer = null;
    updateProcessingProgress(labelPrefix || 'Processing your answer', 100);
    window.setTimeout(function () {
      if (els.processing) els.processing.hidden = true;
      if (els.transcript) els.transcript.hidden = false;
    }, 250);
  }

  function setFeedbackPanel(scorePct, feedback, answerText) {
    if (!els.feedbackPanel) return;
    var tone = scorePct >= 70 ? 'pass' : (scorePct >= 30 ? 'partial' : 'fail');
    if (els.feedbackScore) {
      els.feedbackScore.setAttribute('data-tone', tone);
      text(els.feedbackScore, Math.round(scorePct) + '%');
    }
    if (els.feedbackText) {
      var answerBlock = answerText ? ('Your answer: “' + answerText + '”\n\n') : '';
      els.feedbackText.textContent = answerBlock + (feedback || '');
    }
    els.feedbackPanel.setAttribute('aria-hidden', 'false');
    setFeedbackVisible(false);
  }

  function hideFeedbackPanel() {
    if (els.feedbackPanel) els.feedbackPanel.setAttribute('aria-hidden', 'true');
    if (els.feedbackScore) text(els.feedbackScore, '');
    if (els.feedbackText) text(els.feedbackText, '');
  }

  function setTranscriptDisplay(mode, customText) {
    if (!els.transcript) return;
    var msg = customText || TRANSCRIPT[mode] || TRANSCRIPT.idle;
    text(els.transcript, msg);
    els.transcript.setAttribute('data-state', mode === 'final' ? 'final' : (mode === 'failed' ? 'failed' : 'status'));
  }

  function setSlotVisible(el, show) {
    if (!el) return;
    el.classList.toggle('is-visible', !!show);
    el.setAttribute('aria-hidden', show ? 'false' : 'true');
  }

  function setHintContent(msg, show) {
    if (els.hint) els.hint.innerHTML = msg || '';
    setSlotVisible(els.hint, show);
    if (show) {
      setSlotVisible(els.recordHint, false);
      setSlotVisible(els.feedback, false);
    }
  }

  function setRecordHintVisible(show) {
    setSlotVisible(els.recordHint, show);
    if (show) {
      setSlotVisible(els.hint, false);
      setSlotVisible(els.feedback, false);
    }
  }

  function setFeedbackVisible(show, message) {
    if (!els.feedback) return;
    if (message !== undefined) els.feedback.textContent = message;
    setSlotVisible(els.feedback, show);
    if (show) {
      setSlotVisible(els.hint, false);
      setSlotVisible(els.recordHint, false);
    }
  }

  function setBeginTestVisible(show) {
    if (!els.beginTest) return;
    els.beginTest.classList.toggle('is-visible', !!show);
    els.beginTest.setAttribute('aria-hidden', show ? 'false' : 'true');
  }

  function setTimerNoteVisible(show) {
    if (!els.timerNote) return;
    els.timerNote.classList.toggle('is-visible', !!show);
    els.timerNote.setAttribute('aria-hidden', show ? 'false' : 'true');
  }

  function timeOfDaySalutation() {
    var h = new Date().getHours();
    if (h < 12) return 'Good morning';
    if (h < 17) return 'Good afternoon';
    return 'Good evening';
  }

  function greetingScript() {
    var name = cfg.firstName || 'there';
    return timeOfDaySalutation() + ' ' + name + ', click Ready when you want to start your progress test.';
  }

  function renderIdleLayout() {
    text(els.question, 'Progress Test ready');
    if (state && state.total_questions) {
      var idx = parseInt(state.current_idx || 1, 10) || 1;
      text(els.qnum, 'Question ' + idx + ' of ' + state.total_questions);
    } else {
      text(els.qnum, '—');
    }
    setTranscriptDisplay('preidle');
    setCardState(CARD.READY);
    setHintContent('Tap <strong>Start</strong> when you are ready. Maya will greet you before the first question.', true);
    setRecordHintVisible(false);
    hideFeedbackPanel();
    hideRetry();
    if (els.beginTest) text(els.beginTest, 'Start');
    setBeginTestVisible(true);
    if (els.timer) els.timer.setAttribute('data-active', '0');
    text(els.timerLabel, '\u00a0');
    setTimerNoteVisible(false);
  }

  function getRecordingMs() {
    if (!recordingStartedAt) return 0;
    return Math.max(0, Date.now() - recordingStartedAt);
  }

  function courseReturnUrl() {
    if (cfg.courseReturnUrl) return cfg.courseReturnUrl;
    var cohortId = parseInt(cfg.cohortId || 0, 10) || 0;
    var lessonId = parseInt(cfg.lessonId || 0, 10) || 0;
    var url = '/student/course.php?cohort_id=' + encodeURIComponent(String(cohortId));
    if (lessonId > 0) url += '#progress-test-lesson-' + encodeURIComponent(String(lessonId));
    return url;
  }

  function leaveToLessonMenu() {
    window.location.href = courseReturnUrl();
  }

  function hideRetry() {
    if (els.retry) {
      els.retry.classList.remove('is-visible');
      els.retry.setAttribute('aria-hidden', 'true');
      els.retry.dataset.retryKind = '';
    }
  }

  function showRetry(kind, message) {
    setFeedbackVisible(true, message);
    if (els.retry) {
      els.retry.classList.add('is-visible');
      els.retry.setAttribute('aria-hidden', 'false');
      els.retry.dataset.retryKind = kind;
      els.retry.textContent = kind === 'evaluation' ? 'Retry Evaluation' : (kind === 'upload' ? 'Retry Upload' : 'Retry Answer');
    }
    syncButtons();
  }

  function setAvatarVisuals() {
    var mayaSpeaking = cardState === CARD.ASKING;
    var studentActive = cardState === CARD.LISTENING || cardState === CARD.CLARIFICATION;
    var studentListening = testStarted && oralQuestionsStarted && cardState === CARD.READY && timerMode === 'start';

    if (els.root) {
      els.root.setAttribute('data-maya-speaking', mayaSpeaking ? '1' : '0');
      els.root.setAttribute('data-student-answering', studentActive ? '1' : '0');
      els.root.setAttribute('data-maya-audio-active', mayaSpeaking ? '1' : '0');
      els.root.setAttribute('data-student-audio-active', studentActive ? '1' : '0');
    }

    text(els.mayaStatus, mayaSpeaking ? 'Speaking' : 'Standby');
    if (studentActive) text(els.studentStatus, 'Recording');
    else if (studentListening) text(els.studentStatus, 'Listening');
    else text(els.studentStatus, 'Standby');
  }

  function setCardState(next) {
    cardState = next;
    if (els.card) els.card.setAttribute('data-card-state', next);
    setAvatarVisuals();
    syncButtons();
    if (currentItem && attemptId && oralQuestionsStarted) {
      var persistTranscript = liveTranscript && cardState !== CARD.LISTENING && cardState !== CARD.EVALUATING
        ? liveTranscript
        : '';
      apiJson('save_card_state', {
        item_id: currentItem.id,
        card_state: next,
        live_transcript: persistTranscript
      }).catch(function () {});
    }
  }

  function isAttemptClosed(s) {
    if (!s) return false;
    var st = String(s.status || '');
    return st === 'completed' || st === 'failed';
  }

  function syncButtons() {
    var prepared = state && state.prepared;
    var allDone = state && state.evaluated_count >= state.total_questions;
    var closed = isAttemptClosed(state);
    var retryVisible = els.retry && els.retry.classList.contains('is-visible');
    if (els.beginTest) {
      var showBegin = !oralQuestionsStarted && !allDone && !closed && !testCompleteReady;
      setBeginTestVisible(showBegin);
      var canBegin = false;
      if (!testStarted) {
        canBegin = !!prepared && !sessionConnecting;
      } else if (greetingReady) {
        canBegin = true;
      }
      els.beginTest.disabled = !showBegin || !canBegin;
      if (showBegin) {
        text(els.beginTest, testStarted && greetingReady ? 'Ready' : 'Start');
      }
    }
    if (els.startAnswer) {
      var canAnswer = cardState === CARD.READY || cardState === CARD.CLARIFICATION || retryVisible;
      els.startAnswer.disabled = !testStarted || !oralQuestionsStarted
        || !canAnswer
        || cardState === CARD.LISTENING
        || cardState === CARD.EVALUATING
        || cardState === CARD.ASKING;
    }
    if (els.stopAnswer) els.stopAnswer.disabled = cardState !== CARD.LISTENING && cardState !== CARD.CLARIFICATION;
    if (els.replay) els.replay.disabled = !testStarted || !oralQuestionsStarted || !currentItem || cardState === CARD.EVALUATING;
    if (els.clarify) els.clarify.disabled = !clarificationPending || cardState !== CARD.CLARIFICATION || !clarificationQuestion;
    if (els.next) els.next.disabled = !feedbackSpeechDone || cardState !== CARD.COMPLETE || allDone || testCompleteReady;
    if (els.myReport) {
      if (els.myReport.hidden !== !testCompleteReady) els.myReport.hidden = !testCompleteReady;
    }
    if (els.end) els.end.disabled = !!sessionConnecting;
    var actions = page.querySelector('.ptv4-card-actions');
    if (actions) actions.classList.toggle('is-session-active', !!testStarted);
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

  function apiJson(action, payload) {
    payload = payload || {};
    payload.action = action;
    if (attemptId) payload.attempt_id = attemptId;
    return postJson('/student/api/progress_test_v4_oral.php', payload);
  }

  function uploadChunk(blob, isFinal) {
    if (!currentItem || !attemptId) {
      dbg('chunk upload skipped — missing attempt/item', { attemptId: attemptId, itemId: currentItem && currentItem.id });
      return Promise.reject(new Error('Missing attempt or question context for chunk upload'));
    }
    var idx = chunkIndex++;
    chunksReceived++;
    dbg('chunk received', { index: idx, size: blob.size, final: !!isFinal });

    var fd = new FormData();
    fd.append('action', 'upload_answer_chunk');
    fd.append('attempt_id', String(attemptId));
    fd.append('item_id', String(currentItem.id));
    fd.append('chunk_index', String(idx));
    fd.append('chunk', blob, 'chunk_' + idx + '.webm');

    dbg('chunk upload request sent', { index: idx, attemptId: attemptId, itemId: currentItem.id });

    var uploadOne = fetch('/student/api/progress_test_v4_oral.php?action=upload_answer_chunk', {
      method: 'POST',
      credentials: 'same-origin',
      body: fd
    }).then(function (res) {
      return res.text().then(function (txt) {
        var out;
        try { out = JSON.parse(txt); } catch (e) { throw new Error('Chunk upload returned invalid JSON'); }
        dbg('chunk upload response', { index: idx, ok: out.ok, status: res.status, chunkCount: out.chunk_count, bytes: out.bytes });
        if (!res.ok || !out.ok) throw new Error(out.error || ('Chunk upload failed (HTTP ' + res.status + ')'));
        chunksUploaded++;
        hasRecordedAudio = true;
        return out;
      });
    });

    chunkUploadChain = chunkUploadChain.then(function () { return uploadOne; });
    return uploadOne;
  }

  function stopRecordStream() {
    if (recordStream) {
      recordStream.getTracks().forEach(function (track) {
        try { track.stop(); } catch (e) {}
      });
      recordStream = null;
    }
  }

  function flushRecordingUploads() {
    dbg('flush recording uploads start', { chunksReceived: chunksReceived, chunksUploaded: chunksUploaded });
    return new Promise(function (resolve, reject) {
      if (!mediaRecorder || mediaRecorder.state === 'inactive') {
        chunkUploadChain.then(resolve).catch(reject);
        return;
      }

      mediaRecorder.onstop = function () {
        dbg('recorder stopped', { chunksReceived: chunksReceived, chunksUploaded: chunksUploaded });
        window.setTimeout(function () {
          chunkUploadChain.then(function () {
            dbg('all chunk uploads complete', { chunksReceived: chunksReceived, chunksUploaded: chunksUploaded });
            resolve();
          }).catch(reject);
        }, 100);
      };

      try {
        if (mediaRecorder.state === 'recording') {
          dbg('requestData before stop');
          mediaRecorder.requestData();
        }
      } catch (e) {
        dbg('requestData failed', e.message || e);
      }

      try {
        mediaRecorder.stop();
        dbg('recorder stop issued');
      } catch (e) {
        dbg('recorder stop failed', e.message || e);
        chunkUploadChain.then(resolve).catch(reject);
      }
    });
  }

  function stopTimer() {
    if (timerInterval) clearInterval(timerInterval);
    timerInterval = null;
    timerMode = '';
    timerDeadline = 0;
    if (els.timer) els.timer.setAttribute('data-active', '0');
    setTimerNoteVisible(false);
    setAvatarVisuals();
  }

  function formatTimer(leftMs, totalMs) {
    var leftSec = Math.max(0, Math.ceil(leftMs / 1000));
    var totalSec = Math.max(1, Math.round(totalMs / 1000));
    var mm = String(Math.floor(leftSec / 60)).padStart(2, '0');
    var ss = String(leftSec % 60).padStart(2, '0');
    var tmm = String(Math.floor(totalSec / 60)).padStart(2, '0');
    var tss = String(totalSec % 60).padStart(2, '0');
    return mm + ':' + ss + ' / ' + tmm + ':' + tss;
  }

  function startTimer(mode, totalMs, labelPrefix, onExpire) {
    stopTimer();
    timerMode = mode;
    timerTotalMs = totalMs;
    timerDeadline = Date.now() + totalMs;
    if (els.timer) els.timer.setAttribute('data-active', '1');
    setTimerNoteVisible(mode === 'start');
    setAvatarVisuals();
    timerInterval = setInterval(function () {
      var left = timerDeadline - Date.now();
      var pct = clamp((left / totalMs) * 100, 0, 100);
      if (els.timerFill) {
        els.timerFill.style.width = pct + '%';
        els.timerFill.setAttribute('data-danger', left < Math.min(8000, totalMs * 0.25) ? '1' : '0');
      }
      text(els.timerLabel, labelPrefix + ': ' + formatTimer(left, totalMs));
      if (left <= 0) {
        stopTimer();
        onExpire();
      }
    }, 100);
  }

  function startStartAnswerTimer() {
    startTimer('start', START_ANSWER_MS, 'Time to answer', function () {
      finalizeWithoutRecording(true);
    });
  }

  function startRecordingTimer() {
    setRecordHintVisible(true);
    startTimer('record', RECORDING_MS, 'Recording', function () {
      stopAnswerCapture(true);
    });
  }

  function initStudentAnalyser() {
    if (!micStream || micAnalyser) return;
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      micAnalyser = audioCtx.createAnalyser();
      micAnalyser.fftSize = 256;
      var src = audioCtx.createMediaStreamSource(micStream);
      src.connect(micAnalyser);
      studentBarEls = [];
      $all('[data-ptv4-student-bars] span, [data-ptv4-student-bars-right] span').forEach(function (el) {
        studentBarEls.push(el);
      });
      var data = new Uint8Array(micAnalyser.frequencyBinCount);
      function tick() {
        if (cardState !== CARD.LISTENING && cardState !== CARD.CLARIFICATION) {
          micAnalyserRaf = 0;
          return;
        }
        micAnalyser.getByteFrequencyData(data);
        studentBarEls.forEach(function (bar, i) {
          var idx = Math.min(data.length - 1, (i + 1) * 8);
          var v = data[idx] / 255;
          bar.style.height = Math.round(6 + v * 14) + 'px';
          bar.style.opacity = String(0.45 + v * 0.55);
        });
        micAnalyserRaf = requestAnimationFrame(tick);
      }
      if (!micAnalyserRaf) micAnalyserRaf = requestAnimationFrame(tick);
    } catch (e) {}
  }

  function stopStudentAnalyser() {
    if (micAnalyserRaf) cancelAnimationFrame(micAnalyserRaf);
    micAnalyserRaf = 0;
    studentBarEls.forEach(function (bar) {
      bar.style.height = '6px';
      bar.style.opacity = '0.55';
    });
  }

  function startMayaBarMotion() {
    if (mayaBarTimer) clearInterval(mayaBarTimer);
    var bars = [];
    $all('[data-ptv4-maya-bars] span, [data-ptv4-maya-bars-right] span').forEach(function (el) { bars.push(el); });
    mayaBarTimer = setInterval(function () {
      if (cardState !== CARD.ASKING && !(testStarted && !oralQuestionsStarted && cardState === CARD.ASKING)) return;
      bars.forEach(function (bar, i) {
        var h = 6 + Math.round(Math.random() * 12);
        bar.style.height = h + 'px';
        bar.style.opacity = String(0.5 + Math.random() * 0.5);
        bar.style.animationDelay = (i * 80) + 'ms';
      });
    }, 140);
  }

  function stopMayaBarMotion() {
    if (mayaBarTimer) clearInterval(mayaBarTimer);
    mayaBarTimer = null;
  }

  function updateProgress(nextState) {
    state = nextState || state;
    if (!state) return;
    attemptId = parseInt(state.attempt_id || attemptId, 10) || 0;
    var total = parseInt(state.total_questions || 0, 10) || 0;
    var evaluated = parseInt(state.evaluated_count || 0, 10) || 0;
    var idx = parseInt(state.current_idx || 0, 10) || 0;
    var scoreProgress = state.score_progress == null ? '--' : String(state.score_progress) + '%';
    var finalScore = state.score_pct == null ? scoreProgress : String(state.score_pct) + '%';
    text(els.score, 'Score: ' + finalScore);
    text(els.attempt, (state.resume_mode === 'resumed' ? 'Resumed attempt · ' : '') + (state.formal_result_label || state.status_text || state.status || 'Ready'));
    text(els.current, total ? (idx + '/' + total) : '0/0');
    text(els.evaluated, String(evaluated));
    text(els.final, finalScore);
    if (els.bar) els.bar.style.width = clamp(total ? Math.round((evaluated / total) * 100) : 0, 0, 100) + '%';

    currentItem = null;
    if (!awaitingNextQuestion) {
      (state.items || []).forEach(function (item) {
        if (parseInt(item.id, 10) === parseInt(state.current_item_id, 10)) currentItem = item;
      });
    } else if (displayItem) {
      currentItem = displayItem;
    }
    if (currentItem && oralQuestionsStarted && !awaitingNextQuestion) renderCard(currentItem);
    syncButtons();
  }

  function renderCard(item) {
    displayItem = item;
    text(els.qnum, 'Question ' + item.idx + ' of ' + (state.total_questions || '?'));
    if (cardState !== CARD.ASKING) {
      text(els.question, item.prompt || item.spoken_question || '');
    }
    liveTranscript = item.live_transcript || item.student_answer_text || '';
    if (liveTranscript && cardState === CARD.COMPLETE) setTranscriptDisplay('final', liveTranscript);
    else if (!awaitingNextQuestion) setTranscriptDisplay('idle');
    clarificationPending = item.card_state === CARD.CLARIFICATION || !!item.clarification_question_text;
    if (item.card_state === CARD.CLARIFICATION || item.clarification_question_text) {
      clarificationQuestion = item.clarification_question_text || clarificationQuestion;
      originalAnswer = item.student_answer_text || item.live_transcript || originalAnswer;
    }
    if (els.feedback) {
      if (item.evaluated && item.feedback_text) {
        setFeedbackVisible(true, item.feedback_text);
      } else if (!els.retry || !els.retry.classList.contains('is-visible')) {
        setFeedbackVisible(false);
      }
    }
    if (item.evaluated) setCardState(CARD.COMPLETE);
    else if (item.card_state === CARD.CLARIFICATION) setCardState(CARD.CLARIFICATION);
    else if (item.card_state && item.card_state !== CARD.COMPLETE) setCardState(item.card_state);
    else if (testStarted) setCardState(CARD.READY);
  }

  function onQuestionFinished() {
    stopMayaBarMotion();
    setCardState(CARD.READY);
    setStatus('Tap Start Answer within 30 seconds.');
    setHintContent('Listen to the question carefully. Tap <strong>Start Answer</strong> and speak clearly.', true);
    startStartAnswerTimer();
  }

  function playQuestionAudio() {
    stopTimer();
    hideRetry();
    clarificationPending = false;
    clarificationMode = false;
    hideFeedbackPanel();
    setRecordHintVisible(false);
    setHintContent('Listen to the question carefully. Tap <strong>Start Answer</strong> and speak clearly.', true);
    if (!currentItem) return;

    if (cardState !== CARD.COMPLETE) {
      liveTranscript = '';
      setTranscriptDisplay('idle');
    }

    var qText = currentItem.prompt || currentItem.spoken_question || '';
    setCardState(CARD.ASKING);
    startMayaBarMotion();
    setStatus('Maya is asking the question.');
    logEvent('question_audio_start', qText.slice(0, 120));

    function doneAsking() {
      finishWordReveal(qText);
      stopMayaBarMotion();
      logEvent('question_audio_end');
      onQuestionFinished();
    }

    if (!currentItem.question_audio_url) {
      startWordReveal(qText, null);
      speakScript(currentItem.spoken_question, 'question', doneAsking);
      return;
    }

    startWordReveal(qText, questionAudio);
    questionAudio.onended = doneAsking;
    questionAudio.onerror = function () {
      speakScript(currentItem.spoken_question, 'question', doneAsking);
    };
    questionAudio.src = currentItem.question_audio_url;
    questionAudio.play().catch(function () {
      speakScript(currentItem.spoken_question, 'question', doneAsking);
    });
  }

  function speakScript(script, purpose, onDone) {
    script = String(script || '').trim();
    if (!script) {
      if (onDone) onDone();
      return;
    }
    return waitForDataChannel().then(function () {
      if (!dc || dc.readyState !== 'open') {
        logEvent('tts_unavailable', purpose);
        if (onDone) onDone();
        return;
      }
      if (purpose === 'clarification') {
        setCardState(CARD.CLARIFICATION);
        clarificationPending = true;
      } else if (purpose === 'question' || purpose === 'greeting' || purpose === 'feedback' || purpose === 'final') {
        setCardState(CARD.ASKING);
        startMayaBarMotion();
      }
      logEvent('tts_request', purpose, { chars: script.length });
      var instructions = [
        'Audio output only. English only. Verbatim text-to-speech renderer.',
        'Do not add words before or after the text. Speak exactly once, then stop.',
        'Text: "' + script.replace(/"/g, '\\"') + '"'
      ].join('\n');
      dc._ptv4OnSpeechDone = onDone || null;
      dc.send(JSON.stringify({
        type: 'response.create',
        response: {
          conversation: 'none',
          input: [],
          output_modalities: ['audio'],
          temperature: 0.6,
          instructions: instructions
        }
      }));
    });
  }

  function connectVoice() {
    dcOpenPromise = null;
    return postJson('/student/api/progress_test_v4_voice_token.php', { attempt_id: attemptId }).then(function (tok) {
      pc = new RTCPeerConnection();
      remoteAudio = document.createElement('audio');
      remoteAudio.autoplay = true;
      pc.ontrack = function (ev) { remoteAudio.srcObject = ev.streams[0]; };
      dc = pc.createDataChannel('oai-events');
      dcOpenPromise = new Promise(function (resolve) {
        dc.addEventListener('open', function () {
          logEvent('voice_datachannel_open');
          resolve();
        }, { once: true });
        window.setTimeout(resolve, 5000);
      });
      dc.onmessage = function (ev) {
        var msg;
        try { msg = JSON.parse(ev.data); } catch (e) { return; }
        handleRealtime(msg);
      };
      return navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
        micStream = stream;
        initStudentAnalyser();
        stream.getTracks().forEach(function (t) { pc.addTrack(t, stream); });
        return pc.createOffer();
      }).then(function (offer) {
        return pc.setLocalDescription(offer);
      }).then(function () {
        return fetch(tok.realtime_endpoint, {
          method: 'POST',
          headers: { Authorization: 'Bearer ' + tok.client_secret, 'Content-Type': 'application/sdp' },
          body: pc.localDescription.sdp
        });
      }).then(function (res) { return res.text(); }).then(function (answer) {
        return pc.setRemoteDescription({ type: 'answer', sdp: answer });
      });
    });
  }

  function handleRealtime(msg) {
    var type = String(msg.type || '');

    if (type === 'response.done' || type === 'response.output_audio.done') {
      logEvent('tts_complete', type);
      stopMayaBarMotion();
      if (dc && typeof dc._ptv4OnSpeechDone === 'function') {
        var cb = dc._ptv4OnSpeechDone;
        dc._ptv4OnSpeechDone = null;
        cb();
      } else if (cardState === CARD.ASKING) {
        onQuestionFinished();
      }
    }
  }

  function startCamera() {
    return navigator.mediaDevices.getUserMedia({ video: true, audio: false }).then(function (stream) {
      cameraStream = stream;
      if (els.video) {
        els.video.srcObject = stream;
        if (els.videoFallback) els.videoFallback.hidden = true;
      }
    }).catch(function () {
      if (els.videoFallback) els.videoFallback.hidden = false;
    });
  }

  function startAnswerCapture() {
    if (!currentItem || !micStream) return;
    hideRetry();
    stopTimer();
    stopRecordStream();
    clarificationMode = cardState === CARD.CLARIFICATION || clarificationPending;
    if (!clarificationMode) {
      originalAnswer = '';
      clarificationQuestion = '';
      liveTranscript = '';
      chunkIndex = 0;
      hasRecordedAudio = false;
      chunksReceived = 0;
      chunksUploaded = 0;
      chunkUploadChain = Promise.resolve();
      setTranscriptDisplay('idle');
    } else if (originalAnswer === '') {
      originalAnswer = liveTranscript;
      liveTranscript = '';
      chunkIndex = 0;
      hasRecordedAudio = false;
      chunksReceived = 0;
      chunksUploaded = 0;
      chunkUploadChain = Promise.resolve();
    }
    recordingStartedAt = Date.now();
    playBeep('start');
    logEvent('recording_start', '', { item_id: currentItem.id });
    setTranscriptDisplay(clarificationMode ? 'listening' : 'recording');
    setHintContent('', false);
    setCardState(clarificationMode ? CARD.CLARIFICATION : CARD.LISTENING);
    initStudentAnalyser();
    startRecordingTimer();

    recordStream = new MediaStream();
    micStream.getAudioTracks().forEach(function (track) {
      try {
        recordStream.addTrack(track.clone());
      } catch (e) {
        recordStream.addTrack(track);
      }
    });

    var mime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : 'audio/webm';
    mediaRecorder = new MediaRecorder(recordStream, { mimeType: mime });
    mediaRecorder.ondataavailable = function (ev) {
      if (ev.data && ev.data.size > 0) {
        dbg('dataavailable during recording', { size: ev.data.size, state: mediaRecorder ? mediaRecorder.state : 'none' });
        uploadChunk(ev.data, mediaRecorder && mediaRecorder.state === 'inactive');
      }
    };
    mediaRecorder.onerror = function (ev) {
      dbg('MediaRecorder error', ev.error || ev);
    };
    mediaRecorder.start(CHUNK_MS);
    dbg('recorder started', { mime: mime, chunkMs: CHUNK_MS, attemptId: attemptId, itemId: currentItem.id });
    setStatus(clarificationMode ? 'Recording clarification in English.' : 'Recording your answer.');
    syncButtons();
  }

  function handleUploadFailure(err, opts) {
    pendingEvalOpts = opts || pendingEvalOpts;
    var msg = String(err && err.message ? err.message : err || '');
    if (msg.indexOf('No audio received') >= 0 || msg === 'AUDIO_NOT_RECEIVED') {
      setTranscriptDisplay('noAudio');
      showRetry('answer', TRANSCRIPT.noAudio);
    } else if (msg.toLowerCase().indexOf('upload') >= 0 || msg.toLowerCase().indexOf('chunk') >= 0) {
      setTranscriptDisplay('uploadFailed');
      showRetry('upload', TRANSCRIPT.uploadFailed);
    } else {
      setTranscriptDisplay('failed');
      showRetry('evaluation', msg || TRANSCRIPT.failed);
    }
    setCardState(clarificationMode ? CARD.CLARIFICATION : CARD.READY);
    setStatus('Could not complete answer processing.');
  }

  function submitAnswerEvaluation(opts) {
    opts = opts || {};
    pendingEvalOpts = opts;
    hideRetry();
    setCardState(CARD.EVALUATING);
    startProcessingProgress('Processing your answer');
    setStatus('Processing your answer...');
    logEvent('evaluation_start');

    return flushRecordingUploads()
      .then(function () {
        updateProcessingProgress('Processing your answer', 55);
        if (!opts.timedOutStart && chunksUploaded <= 0 && !hasRecordedAudio) {
          throw new Error('AUDIO_NOT_RECEIVED');
        }
        dbg('finalize transcript called', {
          attemptId: attemptId,
          itemId: currentItem && currentItem.id,
          recordingMs: getRecordingMs(),
          chunksReceived: chunksReceived,
          chunksUploaded: chunksUploaded
        });
        return apiJson('finalize_transcript', {
          item_id: currentItem.id,
          recording_ms: getRecordingMs()
        });
      })
      .then(function (tr) {
        updateProcessingProgress('Processing your answer', 72);
        dbg('finalize transcript response', tr);
        logEvent('transcription_complete', tr.transcript_final ? 'ok' : 'empty', tr);
        if (!opts.timedOutStart && (!tr.audio_received || (tr.chunk_count || 0) <= 0)) {
          throw new Error('AUDIO_NOT_RECEIVED');
        }
        if (tr.upload_failed) {
          throw new Error('Audio upload failed');
        }
        if (tr.transcript_final) {
          liveTranscript = tr.transcript_final;
          setTranscriptDisplay('final', liveTranscript);
        } else if (tr.transcription_failed) {
          liveTranscript = '';
          setTranscriptDisplay('failed');
        } else {
          liveTranscript = '';
        }
        if (!opts.timedOutStart && !liveTranscript && !tr.transcription_failed && !tr.has_audio) {
          throw new Error('AUDIO_NOT_RECEIVED');
        }
        setStatus(opts.evalStatusMessage || 'Evaluating your answer...');
        updateProcessingProgress('Processing your answer', 88);
        return apiJson('finalize_item_answer', {
          item_id: currentItem.id,
          student_answer_text: liveTranscript,
          original_answer_text: opts.originalAnswer || originalAnswer,
          clarification_answer_text: opts.clarificationMode ? liveTranscript : '',
          clarification_question_text: clarificationQuestion,
          timed_out_start: opts.timedOutStart ? 1 : 0,
          recording_ms: getRecordingMs()
        });
      })
      .then(function (out) {
        finishProcessingProgress('Processing your answer');
        return handleEvaluationResult(out);
      })
      .catch(function (err) {
        finishProcessingProgress('Processing your answer');
        dbg('submitAnswerEvaluation failed', err.message || err);
        logEvent('evaluation_failed', err.message || String(err));
        handleUploadFailure(err, opts);
      })
      .finally(function () {
        stopRecordStream();
        mediaRecorder = null;
      });
  }

  function finalizeWithoutRecording(timedOutStart) {
    stopTimer();
    stopStudentAnalyser();
    recordingStartedAt = 0;
    setRecordHintVisible(false);
    submitAnswerEvaluation({
      timedOutStart: timedOutStart,
      evalStatusMessage: timedOutStart ? 'No answer started in time. Evaluating...' : 'Evaluating...'
    });
  }

  function stopAnswerCapture(timedOutRecording) {
    stopTimer();
    stopStudentAnalyser();
    setRecordHintVisible(false);
    submitAnswerEvaluation({
      evalStatusMessage: timedOutRecording ? 'Recording limit reached. Evaluating...' : 'Evaluating your answer...',
      originalAnswer: originalAnswer,
      clarificationMode: clarificationMode
    });
  }

  function handleEvaluationResult(out) {
    pendingEvalOpts = null;
    hideRetry();
    feedbackSpeechDone = false;
    awaitingNextQuestion = true;
    displayItem = currentItem;
    updateProgress(out.state);
    var ev = out.evaluation || {};
    var feedback = out.feedback_for_student || ev.feedback_for_student || '';
    var scorePct = Math.round(out.score_pct || ev.score_pct || 0);
    if (liveTranscript) setTranscriptDisplay('final', liveTranscript);
    setFeedbackPanel(scorePct, feedback, liveTranscript);
    logEvent('evaluation_complete', feedback.slice(0, 120), { score_pct: scorePct });

    if (out.next_action === 'retry_english') {
      awaitingNextQuestion = false;
      feedbackSpeechDone = true;
      clarificationPending = false;
      clarificationMode = false;
      liveTranscript = '';
      hideFeedbackPanel();
      setTranscriptDisplay('idle');
      speakScript('Please answer this question again in English.', 'question', function () {
        onQuestionFinished();
      });
      return;
    }

    if (out.next_action === 'clarify') {
      awaitingNextQuestion = false;
      feedbackSpeechDone = true;
      clarificationQuestion = out.clarification_question || feedback;
      originalAnswer = liveTranscript;
      clarificationPending = true;
      clarificationMode = false;
      liveTranscript = '';
      hideFeedbackPanel();
      setTranscriptDisplay('idle');
      speakScript(clarificationQuestion, 'clarification', function () {
        setCardState(CARD.CLARIFICATION);
        setStatus('One clarification allowed. Tap Start Answer.');
        startStartAnswerTimer();
      });
      return;
    }

    clarificationPending = false;
    var scoreLine = 'You scored ' + scorePct + ' percent for this question.';
    setCardState(CARD.COMPLETE);
    setStatus('Question complete. Listen to Maya’s feedback.');
    speakScript(scoreLine + ' ' + feedback, 'feedback', function () {
      stopMayaBarMotion();
      feedbackSpeechDone = true;
      setStatus('Tap Next Question when you are ready.');
      syncButtons();
    });

    if (out.next_action === 'complete_test') {
      return apiJson('complete_test', {}).then(function (done) {
        lastReport = done.report || null;
        testCompleteReady = true;
        updateProgress(done.state);
        setStatus('Progress test complete. Open My Report when you are ready.');
        if (els.next) els.next.hidden = true;
        if (els.myReport) els.myReport.hidden = false;
        syncButtons();
      });
    }
    syncButtons();
  }

  function playGreeting() {
    greetingReady = false;
    oralQuestionsStarted = false;
    var greeting = greetingScript();
    text(els.question, 'Progress Test ready');
    setTranscriptDisplay('preidle');
    setHintContent('Listen to Maya, then tap <strong>Ready</strong>.', true);
    setBeginTestVisible(true);
    setCardState(CARD.ASKING);
    startMayaBarMotion();
    setStatus('Maya is greeting you.');
    logEvent('greeting_start');
    speakScript(greeting, 'greeting', function () {
      stopMayaBarMotion();
      greetingReady = true;
      setCardState(CARD.READY);
      setHintContent('Tap <strong>Ready</strong> to begin Question 1.', true);
      setStatus('Tap Ready when you want to start your progress test.');
      logEvent('greeting_complete');
      syncButtons();
    });
  }

  function beginOralQuestions() {
    if (!currentItem) return;
    oralQuestionsStarted = true;
    greetingReady = true;
    awaitingNextQuestion = false;
    feedbackSpeechDone = false;
    setBeginTestVisible(false);
    hideRetry();
    hideFeedbackPanel();
    renderCard(currentItem);
    setStatus('Professional oral check in progress.');
    logEvent('oral_questions_start');
    playQuestionAudio();
    syncButtons();
  }

  function ensurePrepared() {
    setStatus('Preparing questions and audio...');
    return apiJson('ensure_prepared', { cohort_id: cfg.cohortId, lesson_id: cfg.lessonId }).then(function (out) {
      updateProgress(out.state);
      if (out.preparing || !out.state.prepared) {
        prepTimer = setTimeout(ensurePrepared, 2500);
        setStatus(out.state.status_text || 'Generating questions and caching audio...');
        return;
      }
      if (prepTimer) clearTimeout(prepTimer);
      setStatus('Ready. Questions are prepared — start when you are.');
      startCamera();
      renderIdleLayout();
      syncButtons();
    }).catch(function (err) {
      setStatus('Unable to prepare test: ' + err.message);
    });
  }

  function startTestFlow() {
    if (sessionConnecting || testStarted) return;
    greetingReady = false;
    oralQuestionsStarted = false;
    sessionConnecting = true;
    syncButtons();
    setStatus('Connecting oral exam session...');
    Promise.all([startCamera(), connectVoice()]).then(function () {
      return waitForDataChannel();
    }).then(function () {
      return apiJson('start_oral_test', {});
    }).then(function (out) {
      sessionConnecting = false;
      testStarted = true;
      updateProgress(out.state);
      playGreeting();
      syncButtons();
    }).catch(function (err) {
      sessionConnecting = false;
      syncButtons();
      setStatus('Could not start test: ' + err.message);
    });
  }

  function onBeginTestClick() {
    if (!testStarted) {
      startTestFlow();
      return;
    }
    if (greetingReady && !oralQuestionsStarted) {
      beginOralQuestions();
    }
  }

  function nextQuestion() {
    if (!state || !currentItem || !feedbackSpeechDone) return;
    awaitingNextQuestion = false;
    feedbackSpeechDone = false;
    hideFeedbackPanel();
    clarificationMode = false;
    clarificationPending = false;
    originalAnswer = '';
    clarificationQuestion = '';
    liveTranscript = '';
    recordingStartedAt = 0;
    stopTimer();
    hideRetry();
    setTranscriptDisplay('idle');
    setFeedbackVisible(false);
    setRecordHintVisible(false);
    logEvent('next_question_click');
    if (els.next) els.next.hidden = false;
    return apiJson('advance_item', {}).then(function (out) {
      updateProgress(out.state);
      if (state.evaluated_count >= state.total_questions) {
        setStatus('All questions complete.');
        return;
      }
      playQuestionAudio();
    });
  }

  function renderReportModal(report) {
    report = report || lastReport;
    if (!report || !els.reportModal) return;
    text(els.reportSubtitle, (report.lesson_title || cfg.lessonTitle || '') + ' · Score ' + (report.score_pct == null ? '--' : report.score_pct + '%'));
    text(els.reportMotivation, report.motivation || '');
    text(els.reportSummary, (report.passed ? 'Result: Pass · ' : 'Result: Not yet passed · ') + (report.formal_result_label || '') + (report.score_pct != null ? (' · Overall score ' + report.score_pct + '%') : ''));
    text(els.reportRecommendation, report.recommendation || '');
    if (els.reportQuestions) {
      els.reportQuestions.innerHTML = '';
      (report.questions || []).forEach(function (q) {
        var block = document.createElement('div');
        block.className = 'ptv4-report-q';
        block.innerHTML = '<div class="ptv4-report-q-score">Question ' + q.idx + ' · ' + (q.score_pct == null ? '--' : q.score_pct + '%') + '</div>'
          + '<div><strong>Question:</strong> ' + (q.question || '') + '</div>'
          + '<div style="margin-top:6px"><strong>Your answer:</strong> ' + (q.student_answer || '—') + '</div>'
          + (q.clarification_question ? '<div style="margin-top:6px"><strong>Clarification:</strong> ' + q.clarification_question + '</div>' : '')
          + (q.clarification_answer ? '<div style="margin-top:6px"><strong>Clarification answer:</strong> ' + q.clarification_answer + '</div>' : '')
          + '<div style="margin-top:6px"><strong>Maya feedback:</strong> ' + (q.feedback || '') + '</div>';
        els.reportQuestions.appendChild(block);
      });
    }
    els.reportModal.hidden = false;
  }

  function closeReportModal() {
    if (els.reportModal) els.reportModal.hidden = true;
  }

  function openDebugModal() {
    if (!els.debugModal) return;
    if (els.debugLog) els.debugLog.hidden = true;
    if (els.debugPinView) els.debugPinView.hidden = false;
    if (els.bugSent) els.bugSent.hidden = true;
    if (els.debugPin) els.debugPin.value = '';
    els.debugModal.hidden = false;
  }

  function closeDebugModal() {
    if (els.debugModal) els.debugModal.hidden = true;
  }

  function initFeedbackForm() {
    $all('[data-ptv4-fb-q]').forEach(function (block) {
      var key = block.getAttribute('data-ptv4-fb-q');
      var optsWrap = block.querySelector('.ptv4-feedback-q-options');
      if (!optsWrap) return;
      var opts = key === 'went_wrong' ? ISSUE_OPTS : LIKERT;
      opts.forEach(function (label, i) {
        var id = 'ptv4-fb-' + key + '-' + i;
        var lab = document.createElement('label');
        lab.innerHTML = '<input type="radio" name="ptv4-fb-' + key + '" value="' + label.replace(/"/g, '&quot;') + '"> ' + label;
        optsWrap.appendChild(lab);
      });
    });
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

  if (els.beginTest) els.beginTest.addEventListener('click', onBeginTestClick);
  if (els.startAnswer) els.startAnswer.addEventListener('click', startAnswerCapture);
  if (els.stopAnswer) els.stopAnswer.addEventListener('click', function () { stopAnswerCapture(false); });
  if (els.replay) els.replay.addEventListener('click', playQuestionAudio);
  if (els.clarify) els.clarify.addEventListener('click', function () {
    if (clarificationQuestion) speakScript(clarificationQuestion, 'clarification');
  });
  if (els.next) els.next.addEventListener('click', nextQuestion);
  if (els.myReport) els.myReport.addEventListener('click', function () {
    if (lastReport) {
      renderReportModal(lastReport);
      return;
    }
    apiJson('get_report', {}).then(function (out) {
      lastReport = out.report;
      renderReportModal(out.report);
    });
  });
  if (els.reportClose) els.reportClose.addEventListener('click', closeReportModal);
  if (els.reportModal) els.reportModal.addEventListener('click', function (ev) {
    if (ev.target === els.reportModal) closeReportModal();
  });
  $all('[data-ptv4-tab]').forEach(function (tabBtn) {
    tabBtn.addEventListener('click', function () {
      var tab = tabBtn.getAttribute('data-ptv4-tab');
      $all('[data-ptv4-tab]').forEach(function (b) { b.classList.toggle('is-active', b === tabBtn); });
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
      }).then(function () {
        if (els.feedbackSent) els.feedbackSent.hidden = false;
      });
    });
  }
  if (els.mayaFrame) {
    els.mayaFrame.addEventListener('click', function () {
      mayaClickCount++;
      if (mayaClickTimer) clearTimeout(mayaClickTimer);
      mayaClickTimer = setTimeout(function () { mayaClickCount = 0; }, 1200);
      if (mayaClickCount >= 3) {
        mayaClickCount = 0;
        openDebugModal();
      }
    });
  }
  if (els.debugClose) els.debugClose.addEventListener('click', closeDebugModal);
  if (els.debugUnlock) els.debugUnlock.addEventListener('click', function () {
    var pin = els.debugPin ? els.debugPin.value : '';
    if (pin !== DEBUG_PIN) {
      alert('Incorrect PIN.');
      return;
    }
    if (els.debugPinView) els.debugPinView.hidden = true;
    if (els.debugLog) {
      els.debugLog.hidden = false;
      els.debugLog.textContent = debugEvents.map(function (ev) {
        return ev.ts + ' [' + ev.type + '] ' + ev.detail + (ev.meta && Object.keys(ev.meta).length ? ' ' + JSON.stringify(ev.meta) : '');
      }).join('\n');
    }
  });
  if (els.bugReport) els.bugReport.addEventListener('click', function () {
    apiJson('submit_bug_report', { events: debugEvents }).then(function () {
      if (els.bugSent) els.bugSent.hidden = false;
    });
  });
  if (els.retry) els.retry.addEventListener('click', function () {
    var kind = els.retry.dataset.retryKind || 'evaluation';
    hideRetry();
    if (kind === 'answer' || kind === 'upload') {
      liveTranscript = '';
      chunkIndex = 0;
      hasRecordedAudio = false;
      chunksReceived = 0;
      chunksUploaded = 0;
      recordingStartedAt = 0;
      chunkUploadChain = Promise.resolve();
      stopRecordStream();
      mediaRecorder = null;
      startAnswerCapture();
      return;
    }
    if (pendingEvalOpts) {
      submitAnswerEvaluation(pendingEvalOpts);
      return;
    }
    stopAnswerCapture(false);
  });
  if (els.end) els.end.addEventListener('click', function () {
    if (sessionConnecting) return;
    if (!testStarted || isAttemptClosed(state)) {
      leaveToLessonMenu();
      return;
    }
    if (!window.confirm('Exit this progress test? Partial answers on the current attempt will be reset.')) return;
    apiJson('end_oral_test_without_penalty', {}).then(function () {
      leaveToLessonMenu();
    });
  });

  window.addEventListener('beforeunload', function () {
    if (testStarted && attemptId) {
      navigator.sendBeacon('/student/api/progress_test_v4_oral.php', new Blob([JSON.stringify({
        action: 'abort_voice_session_without_penalty',
        attempt_id: attemptId
      })], { type: 'application/json' }));
    }
  });

  initFeedbackForm();
  ensurePrepared();
  syncButtons();
})();
