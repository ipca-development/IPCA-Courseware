(function () {
  'use strict';

  var cfg = window.IPCAProgressTestV4Config || {};
  var CARD = { READY: 'ready', ASKING: 'asking', LISTENING: 'listening', EVALUATING: 'evaluating', CLARIFICATION: 'clarification', COMPLETE: 'complete' };
  var START_ANSWER_MS = 30000;
  var RECORDING_MS = 45000;
  var CHUNK_MS = 1500;
  var SHORT_CHUNK_MS = 500;
  var MIN_RECORDING_MS = 2500;
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
    primaryAction: $('[data-ptv4-primary-action]'),
    replay: $('[data-ptv4-replay]'),
    clarify: $('[data-ptv4-clarify]'),
    next: $('[data-ptv4-next]'),
    myReport: $('[data-ptv4-my-report]'),
    retry: $('[data-ptv4-retry]'),
    end: $('[data-ptv4-end]'),
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
    debugModal: $('[data-ptv4-debug-modal]'),
    debugClose: $('[data-ptv4-debug-close]'),
    debugPin: $('[data-ptv4-debug-pin]'),
    debugUnlock: $('[data-ptv4-debug-unlock]'),
    debugLog: $('[data-ptv4-debug-log]'),
    debugPinView: $('[data-ptv4-debug-pin-view]'),
    bugReport: $('[data-ptv4-bug-report]'),
    bugSent: $('[data-ptv4-bug-sent]'),
    yesnoFallback: $('[data-ptv4-yesno-fallback]'),
    yesnoButtons: $all('[data-ptv4-yesno-value]')
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
  var yesnoAudioFailed = false;
  var yesnoFallbackItemId = 0;
  var pendingEvalOpts = null;
  var pc = null;
  var dc = null;
  var remoteAudio = null;
  var questionAudio = new Audio();
  questionAudio.preload = 'auto';
  questionAudio.setAttribute('playsinline', 'playsinline');
  var prepTimer = null;
  var timerInterval = null;
  var timerDeadline = 0;
  var timerTotalMs = 0;
  var timerMode = '';
  var pausedAnswerTimer = null;
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
  var processingPctShown = 0;
  var processingCap = 88;
  var debugEvents = [];
  var mayaClickCount = 0;
  var mayaClickTimer = null;
  var wordRevealWords = [];
  var wordRevealIndex = 0;
  var wordRevealTimer = null;
  var dcOpenPromise = null;
  var responseInProgress = false;
  var mayaTurnPurpose = '';
  var mayaExpectedText = '';
  var mayaActiveResponseId = '';
  var scriptedResponsePending = false;
  var mayaTranscriptDone = false;
  var mayaAudioEndsAt = 0;
  var mayaSpeechStartedAt = 0;
  var mayaTurnTailTimer = null;
  var voiceConnectPromise = null;
  var remoteTrackReady = false;
  var remoteTrackWaiters = [];
  var oralSessionStarted = false;
  var debugSentCount = 0;
  var debugFlushTimer = null;
  var debugFlushInFlight = false;
  var feedbackSpeechWatchdog = null;

  function text(el, v) { if (el) el.textContent = v; }
  function clamp(n, a, b) { return Math.max(a, Math.min(b, n)); }
  function setStatus(msg) { /* hero status line removed — use stat chips only */ }

  function dbg() {
    if (!DEBUG_PTV4) return;
    var args = ['[PTV4]'].concat([].slice.call(arguments));
    console.log.apply(console, args);
  }

  function ensureRemoteAudio() {
    if (remoteAudio) return remoteAudio;
    remoteAudio = document.createElement('audio');
    remoteAudio.autoplay = true;
    remoteAudio.muted = false;
    remoteAudio.volume = 1;
    remoteAudio.setAttribute('playsinline', 'playsinline');
    remoteAudio.className = 'ptv4-remote-audio';
    document.body.appendChild(remoteAudio);
    return remoteAudio;
  }

  function unlockAudioPlayback() {
    var audio = ensureRemoteAudio();
    audio.muted = false;
    audio.volume = 1;
    try {
      var p = audio.play();
      if (p && typeof p.then === 'function') {
        p.then(function () {
          logEvent('remote_audio_play_ok');
        }).catch(function (err) {
          logEvent('remote_audio_play_blocked', String(err && err.message || err));
        });
      }
    } catch (e) {
      logEvent('remote_audio_play_error', String(e && e.message || e));
    }
  }

  function isMayaAudioPlaybackActive() {
    return Date.now() < mayaAudioEndsAt;
  }

  function estimateMayaSpeechMs(text) {
    var t = String(text || '').trim();
    if (!t) return 2200;
    var words = t.split(/\s+/).filter(Boolean).length;
    return clamp(Math.round(words * 360 + 900), 1600, 90000);
  }

  function mayaPlaybackTailMs(text) {
    return clamp(Math.round(estimateMayaSpeechMs(text) * 0.9), 2200, 90000);
  }

  function scheduleMayaAudioEnd(text, resetStart) {
    var tail = mayaPlaybackTailMs(text);
    if (mayaTurnPurpose === 'greeting') {
      tail = Math.max(tail, 6500);
    }
    if (resetStart || !mayaSpeechStartedAt) mayaSpeechStartedAt = Date.now();
    mayaAudioEndsAt = mayaSpeechStartedAt + tail;
  }

  function clearMayaAudioEnd() {
    mayaAudioEndsAt = 0;
    mayaSpeechStartedAt = 0;
  }

  function stopMayaTurnTimers() {
    if (mayaTurnTailTimer) clearTimeout(mayaTurnTailTimer);
    mayaTurnTailTimer = null;
  }

  function prepareRealtimeForScriptedSpeech() {
    if (dc && dc.readyState === 'open') {
      try { dc.send(JSON.stringify({ type: 'input_audio_buffer.clear' })); } catch (e) {}
    }
  }

  function escapeScriptQuote(text) {
    return String(text || '').replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  }

  function scriptOpeningWords(text, count) {
    return String(text || '').trim().split(/\s+/).filter(Boolean).slice(0, count || 3).join(' ');
  }

  function buildRendererInstructions(textToSpeak, purpose) {
    var script = escapeScriptQuote(String(textToSpeak || '').trim());
    if (!script) return '';
    var start = escapeScriptQuote(scriptOpeningWords(textToSpeak, 3));
    var lines = [
      'Audio output only. English only. Verbatim text-to-speech renderer.',
      'Ignore all prior conversation, prior questions, and prior student answers completely.',
      'Do not answer, solve, explain, tutor, grade, affirm, or respond to anything except reading the text.',
      'Do not add words before or after the text. Speak the text exactly once, then stop.'
    ];
    if (purpose === 'question' || purpose === 'clarification') {
      lines.push('The text is an oral exam question for the student. Never give the answer, hints, or examples.');
    }
    if (purpose === 'feedback' || purpose === 'final') {
      lines.push('The text is scored exam feedback. Read it verbatim. Do not reference other questions or lesson topics.');
    }
    if (purpose === 'greeting') {
      lines.push('The text is a short greeting before the exam begins. Read it verbatim.');
    }
    lines.push('Forbidden before the text: understood, okay, got it, sure, let us, moving on, next question.');
    lines.push('Begin with: ' + start + '. Text: "' + script + '"');
    return lines.join('\n');
  }

  function realtimeErrorText(msg) {
    var err = msg && msg.error ? msg.error : msg;
    var code = String((err && err.code) || '').toLowerCase();
    var message = String((err && err.message) || msg.message || '').toLowerCase();
    return (code + ' ' + message).trim();
  }

  function isBenignRealtimeError(msg) {
    var text = realtimeErrorText(msg);
    if (!text) return true;
    if (text.indexOf('no active response') !== -1) return true;
    if (text.indexOf('no response') !== -1 && text.indexOf('cancel') !== -1) return true;
    if (text.indexOf('already cancelled') !== -1 || text.indexOf('already canceled') !== -1) return true;
    if (text.indexOf('response_cancel') !== -1) return true;
    if (text.indexOf('cancelled') !== -1 || text.indexOf('canceled') !== -1) return true;
    if (text.indexOf('audio runtime') !== -1) return true;
    if (text.indexOf('runtime error') !== -1 && text.indexOf('audio') !== -1) return true;
    if (text.indexOf('buffer') !== -1 && text.indexOf('empty') !== -1) return true;
    if (text.indexOf('input_audio_buffer_commit') !== -1) return true;
    if (text.indexOf('commit_empty') !== -1) return true;
    if (text.indexOf('buffer too small') !== -1) return true;
    if (text.indexOf('0.00ms') !== -1) return true;
    return false;
  }

  function mayaResponseEventKey(msg) {
    return String((msg && msg.response && msg.response.id) || msg.response_id || '').trim();
  }

  function finishSpeechTurn(onDone) {
    stopMayaTurnTimers();
    clearMayaAudioEnd();
    stopMayaBarMotion();
    mayaActiveResponseId = '';
    scriptedResponsePending = false;
    mayaTranscriptDone = false;
    if (typeof onDone === 'function') onDone();
  }

  function completeSpeechTurn(onDone) {
    if (isMayaAudioPlaybackActive() || responseInProgress) {
      mayaTurnTailTimer = window.setTimeout(function () {
        completeSpeechTurn(onDone);
      }, 150);
      return;
    }
    finishSpeechTurn(onDone);
  }

  function drainSpeechResponse() {
    var onDone = dc && typeof dc._ptv4OnSpeechDone === 'function' ? dc._ptv4OnSpeechDone : null;
    if (!onDone && !responseInProgress && !mayaTurnPurpose) return;
    if (dc) dc._ptv4OnSpeechDone = null;
    responseInProgress = false;
    completeSpeechTurn(function () {
      mayaTurnPurpose = '';
      if (typeof onDone === 'function') onDone();
    });
  }

  function resetRemoteTrackWait() {
    remoteTrackReady = false;
    remoteTrackWaiters = [];
  }

  function notifyRemoteTrackReady() {
    if (remoteTrackReady) return;
    remoteTrackReady = true;
    remoteTrackWaiters.slice().forEach(function (resolve) { resolve(); });
    remoteTrackWaiters = [];
  }

  function waitForRemoteTrack(timeoutMs) {
    timeoutMs = timeoutMs || 15000;
    if (remoteTrackReady && remoteAudio && remoteAudio.srcObject) return Promise.resolve();
    return new Promise(function (resolve, reject) {
      remoteTrackWaiters.push(resolve);
      window.setTimeout(function () {
        reject(new Error('Maya audio channel not ready'));
      }, timeoutMs);
    });
  }

  function disconnectVoice() {
    stopMayaTurnTimers();
    clearMayaAudioEnd();
    mayaTurnPurpose = '';
    mayaActiveResponseId = '';
    scriptedResponsePending = false;
    mayaTranscriptDone = false;
    responseInProgress = false;
    if (dc) {
      try { dc.close(); } catch (e) {}
      dc = null;
    }
    if (pc) {
      try { pc.close(); } catch (e) {}
      pc = null;
    }
    dcOpenPromise = null;
    resetRemoteTrackWait();
  }

  function ensureVoiceConnected() {
    if (pc && dc && dc.readyState === 'open' && remoteTrackReady) return Promise.resolve();
    if (!voiceConnectPromise) {
      voiceConnectPromise = connectVoice().catch(function (err) {
        voiceConnectPromise = null;
        throw err;
      });
    }
    return voiceConnectPromise;
  }

  function playPreparedAudio(url, onDone, onStart) {
    url = String(url || '').trim();
    if (!url) return Promise.reject(new Error('Missing audio URL'));
    unlockAudioPlayback();
    questionAudio.pause();
    questionAudio.currentTime = 0;
    return new Promise(function (resolve, reject) {
      questionAudio.onplaying = function () {
        questionAudio.onplaying = null;
        if (onStart) onStart();
      };
      questionAudio.onended = function () {
        stopMayaBarMotion();
        if (onDone) onDone();
        resolve();
      };
      questionAudio.onerror = function () {
        reject(new Error('Prepared audio playback failed'));
      };
      questionAudio.src = url;
      var playPromise = questionAudio.play();
      if (playPromise && typeof playPromise.then === 'function') {
        playPromise.catch(reject);
      }
    });
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
    scheduleDebugFlush();
  }

  function scheduleDebugFlush() {
    if (debugFlushTimer) return;
    debugFlushTimer = window.setTimeout(function () {
      debugFlushTimer = null;
      flushDebugEventsToServer(false);
    }, 600);
  }

  function flushDebugEventsToServer(forceSync) {
    if (debugFlushInFlight || debugSentCount >= debugEvents.length) {
      return forceSync ? Promise.resolve() : undefined;
    }
    var batch = debugEvents.slice(debugSentCount);
    debugSentCount = debugEvents.length;
    var payload = {
      action: 'log_debug_events',
      cohort_id: cfg.cohortId,
      lesson_id: cfg.lessonId,
      events: batch
    };
    if (attemptId) payload.attempt_id = attemptId;

    debugFlushInFlight = true;
    var done = function () {
      debugFlushInFlight = false;
      if (debugSentCount < debugEvents.length) scheduleDebugFlush();
    };

    if (forceSync && navigator.sendBeacon) {
      try {
        navigator.sendBeacon(
          '/student/api/progress_test_v4_oral.php',
          new Blob([JSON.stringify(payload)], { type: 'application/json' })
        );
      } catch (e) {}
      done();
      return Promise.resolve();
    }

    return postJson('/student/api/progress_test_v4_oral.php', payload)
      .catch(function () {
        debugSentCount -= batch.length;
      })
      .then(done);
  }

  function stopGreetingPlayback() {
    try {
      questionAudio.pause();
      questionAudio.currentTime = 0;
    } catch (e) {}
    stopMayaBarMotion();
    stopWordReveal();
    mayaTurnPurpose = '';
    mayaActiveResponseId = '';
    scriptedResponsePending = false;
    mayaTranscriptDone = false;
    responseInProgress = false;
    if (dc && dc.readyState === 'open') {
      try { dc.send(JSON.stringify({ type: 'response.cancel' })); } catch (e) {}
    }
  }

  function skipGreetingIfNeeded() {
    if (greetingReady) return;
    stopGreetingPlayback();
    greetingReady = true;
    setCardState(CARD.READY);
    setHintContent('Tap <strong>Ready</strong> to begin Question 1.', true);
    logEvent('greeting_skipped');
    syncButtons();
  }

  function ensureOralSessionStarted() {
    if (oralSessionStarted) return Promise.resolve();
    return apiJson('start_oral_test', {}).then(function (out) {
      oralSessionStarted = true;
      updateProgress(out.state);
      logEvent('oral_session_started');
      return out;
    });
  }

  function rollbackOralSessionBeacon(reason) {
    if (!attemptId || !oralSessionStarted) return;
    if (oralQuestionsStarted || (state && (state.evaluated_count || 0) > 0)) return;
    var payload = JSON.stringify({
      action: 'rollback_oral_session',
      attempt_id: attemptId,
      reason: reason || 'page_unload'
    });
    if (navigator.sendBeacon) {
      navigator.sendBeacon(
        '/student/api/progress_test_v4_oral.php',
        new Blob([payload], { type: 'application/json' })
      );
    }
    oralSessionStarted = false;
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
      oscillator.frequency.value = kind === 'start' ? 880 : (kind === 'stop' ? 220 : 330);
      gain.gain.setValueAtTime(0.001, audioCtx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.16, audioCtx.currentTime + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.5);
      oscillator.connect(gain);
      gain.connect(audioCtx.destination);
      oscillator.start();
      oscillator.stop(audioCtx.currentTime + 0.52);
    } catch (e) {}
  }

  function isMayaSpeaking() {
    if (questionAudio && !questionAudio.paused && !questionAudio.ended && questionAudio.currentTime > 0) {
      return true;
    }
    if (cardState === CARD.ASKING) {
      return !!mayaBarTimer;
    }
    if (awaitingNextQuestion && cardState === CARD.COMPLETE && !feedbackSpeechDone) {
      return !!mayaBarTimer;
    }
    return false;
  }

  function clearFeedbackSpeechWatchdog() {
    if (feedbackSpeechWatchdog) clearTimeout(feedbackSpeechWatchdog);
    feedbackSpeechWatchdog = null;
  }

  function recoverPendingFeedbackSpeech(reason) {
    if (!awaitingNextQuestion || feedbackSpeechDone || cardState !== CARD.COMPLETE) return;
    logEvent('feedback_speech_recovery', reason || 'watchdog');
    clearFeedbackSpeechWatchdog();
    stopMayaBarMotion();
    finishProcessingProgress('Processing your answer');
    feedbackSpeechDone = true;
    syncButtons();
  }

  function scheduleFeedbackSpeechWatchdog(speechText) {
    clearFeedbackSpeechWatchdog();
    feedbackSpeechWatchdog = window.setTimeout(function () {
      recoverPendingFeedbackSpeech('watchdog');
    }, estimateMayaSpeechMs(speechText) + 8000);
  }

  function pauseAnswerTimerIfRunning() {
    if (timerMode === 'start' && timerDeadline > Date.now()) {
      pausedAnswerTimer = { deadline: timerDeadline, totalMs: timerTotalMs };
      return;
    }
    pausedAnswerTimer = null;
  }

  function restoreAnswerTimerIfAny() {
    if (pausedAnswerTimer && pausedAnswerTimer.deadline > Date.now()) {
      startTimer('start', pausedAnswerTimer.totalMs, 'Time to answer', function () {
        finalizeWithoutRecording(true);
      }, pausedAnswerTimer.deadline);
    } else if (cardState === CARD.READY || cardState === CARD.CLARIFICATION) {
      startStartAnswerTimer();
    }
    pausedAnswerTimer = null;
  }

  function abortActiveRecording() {
    return new Promise(function (resolve) {
      if (!mediaRecorder || mediaRecorder.state === 'inactive') {
        stopRecordStream();
        mediaRecorder = null;
        resolve();
        return;
      }
      mediaRecorder.onstop = function () {
        stopRecordStream();
        mediaRecorder = null;
        resolve();
      };
      try {
        if (mediaRecorder.state === 'recording') mediaRecorder.requestData();
        mediaRecorder.stop();
      } catch (e) {
        stopRecordStream();
        mediaRecorder = null;
        resolve();
      }
    });
  }

  function cancelAnswerCapture(reason) {
    return abortActiveRecording().then(function () {
      stopStudentAnalyser();
      setRecordHintVisible(false);
      recordingStartedAt = 0;
      chunkIndex = 0;
      hasRecordedAudio = false;
      chunksReceived = 0;
      chunksUploaded = 0;
      chunkUploadChain = Promise.resolve();
      setTranscriptDisplay('idle');
      setCardState(clarificationMode ? CARD.CLARIFICATION : CARD.READY);
      restoreAnswerTimerIfAny();
      setHintContent(reason || 'Your answer was not submitted. Tap <strong>Start Answer</strong> when you are ready.', true);
      markYesNoAudioFailed();
      logEvent('recording_cancelled', reason || 'too_short');
      syncButtons();
    });
  }

  function waitForDataChannel(timeoutMs) {
    timeoutMs = timeoutMs || 15000;
    if (dc && dc.readyState === 'open') return Promise.resolve();
    if (!dcOpenPromise) return Promise.reject(new Error('Voice session is not initialized'));
    return Promise.race([
      dcOpenPromise,
      new Promise(function (_, reject) {
        window.setTimeout(function () {
          reject(new Error('Voice data channel timed out'));
        }, timeoutMs);
      })
    ]);
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
    processingCap = 88;
    processingPct = 5;
    processingPctShown = 5;
    if (els.processing) els.processing.hidden = false;
    if (els.transcript) els.transcript.hidden = true;
    updateProcessingProgress(labelPrefix, processingPctShown);
    if (processingTimer) clearInterval(processingTimer);
    processingTimer = setInterval(function () {
      if (processingPctShown < processingCap) {
        processingPctShown = Math.min(processingCap, processingPctShown + Math.round(Math.random() * 4 + 1));
        updateProcessingProgress(labelPrefix, processingPctShown);
      }
    }, 450);
  }

  function beginFeedbackProcessingProgress() {
    processingCap = 98;
    var label = 'Preparing Maya feedback';
    updateProcessingProgress(label, Math.max(processingPctShown, 93));
    if (processingTimer) clearInterval(processingTimer);
    processingTimer = setInterval(function () {
      if (processingPctShown < processingCap) {
        processingPctShown = Math.min(processingCap, processingPctShown + 1);
        updateProcessingProgress(label, processingPctShown);
      }
    }, 400);
  }

  function updateProcessingProgress(labelPrefix, pct) {
    processingPctShown = Math.max(processingPctShown, Math.round(pct));
    var label = (labelPrefix || 'Processing your answer') + '… ' + processingPctShown + '%';
    text(els.processingLabel, label);
    if (els.processingFill) els.processingFill.style.width = clamp(processingPctShown, 0, 100) + '%';
  }

  function finishProcessingProgress(labelPrefix) {
    if (processingTimer) clearInterval(processingTimer);
    processingTimer = null;
    processingPctShown = 100;
    updateProcessingProgress(labelPrefix || 'Processing your answer', 100);
    window.setTimeout(function () {
      if (els.processing) els.processing.hidden = true;
      if (els.transcript) els.transcript.hidden = false;
    }, 250);
  }

  function formatQuestionNum(idx, total) {
    return 'Question ' + idx + '/' + (total || '?') + ':';
  }

  function formatQuestionText(item) {
    if (!item) return '';
    return formatQuestionNum(item.idx, (state && state.total_questions) || '?') + ' '
      + (item.prompt || item.spoken_question || '');
  }

  function feedbackTone(scorePct) {
    var passPct = parseInt(cfg.progressTestPassPct, 10);
    if (!passPct || passPct <= 0) passPct = 70;
    if (scorePct >= passPct) return 'pass';
    if (scorePct >= 50) return 'partial';
    return 'fail';
  }

  function setFeedbackPanel(scorePct, feedback) {
    if (!els.feedbackPanel) return;
    var tone = feedbackTone(scorePct);
    els.feedbackPanel.setAttribute('data-tone', tone);
    if (els.feedbackScore) {
      els.feedbackScore.setAttribute('data-tone', tone);
      text(els.feedbackScore, Math.round(scorePct) + '%');
    }
    if (els.feedbackText) {
      els.feedbackText.textContent = feedback || '';
    }
    setHintContent('', false);
    setRecordHintVisible(false);
    els.feedbackPanel.setAttribute('aria-hidden', 'false');
    setFeedbackVisible(false);
  }

  function hideFeedbackPanel() {
    if (els.feedbackPanel) {
      els.feedbackPanel.setAttribute('aria-hidden', 'true');
      els.feedbackPanel.removeAttribute('data-tone');
    }
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
      text(els.qnum, formatQuestionNum(idx, state.total_questions));
    } else {
      text(els.qnum, '—');
    }
    setTranscriptDisplay('preidle');
    setCardState(CARD.READY);
    setHintContent('Tap <strong>Ready</strong> when you are prepared. Maya will greet you before the first question.', true);
    setRecordHintVisible(false);
    hideFeedbackPanel();
    hideRetry();
    if (els.primaryAction) text(els.primaryAction, 'Ready');
    if (els.timer) els.timer.setAttribute('data-active', '0');
    text(els.timerLabel, '\u00a0');
    setTimerNoteVisible(false);
  }

  function shouldResumeOralSession(nextState) {
    nextState = nextState || state;
    if (!nextState || !nextState.prepared) return false;
    var st = String(nextState.status || '');
    return st === 'in_progress' || st === 'processing';
  }

  function renderResumeLayout() {
    if (!currentItem) {
      renderIdleLayout();
      return;
    }
    renderCard(currentItem);
    text(els.qnum, formatQuestionNum(currentItem.idx, state.total_questions || '?'));
    text(els.question, formatQuestionText(currentItem));
    setTranscriptDisplay('idle');
    stopMayaBarMotion();
    setCardState(resumeCardStateForItem(currentItem));
    setRecordHintVisible(false);
    hideFeedbackPanel();
    hideRetry();
    if (els.timer) els.timer.setAttribute('data-active', '0');
    text(els.timerLabel, '\u00a0');
    setTimerNoteVisible(false);
    var resumeHint = 'Welcome back at Question ' + currentItem.idx + ' of ' + (state.total_questions || '?')
      + '. Tap <strong>Start Answer</strong> when you are ready.';
    if (itemKind(currentItem) === 'yesno') {
      resumeHint = 'Welcome back at Question ' + currentItem.idx + ' of ' + (state.total_questions || '?')
        + '. Tap <strong>Start Answer</strong>, or tap <strong>Yes</strong> / <strong>No</strong> below.';
    }
    setHintContent(resumeHint, true);
    if (itemKind(currentItem) === 'yesno') {
      showYesNoFallback();
    }
  }

  function resumeOralSession() {
    applyLoadedSessionState();
    renderResumeLayout();
    sessionConnecting = true;
    syncButtons();
    logEvent('oral_session_resume');
    voiceConnectPromise = connectVoice().catch(function (err) {
      logEvent('voice_connect_failed', err.message || 'connect failed');
      voiceConnectPromise = null;
      throw err;
    });
    return Promise.all([startCamera(), voiceConnectPromise.catch(function () { return null; })])
      .then(function () {
        sessionConnecting = false;
        startStartAnswerTimer();
        syncButtons();
      })
      .catch(function (err) {
        sessionConnecting = false;
        setHintContent('Could not reconnect Maya and your microphone. Refresh the page and allow microphone access.<br><span style="font-size:12px;opacity:.85">' + (err.message || 'Connection failed') + '</span>', true);
        logEvent('oral_session_resume_failed', err.message || 'resume failed');
        syncButtons();
      });
  }

  function getRecordingMs() {
    if (!recordingStartedAt) return 0;
    return Math.max(0, Date.now() - recordingStartedAt);
  }

  function itemKind(item) {
    return item && String(item.kind || '');
  }

  function isShortAnswerKind(item) {
    var kind = itemKind(item);
    return kind === 'yesno' || kind === 'mcq';
  }

  function minRecordingMsForItem(item) {
    return isShortAnswerKind(item) ? 0 : MIN_RECORDING_MS;
  }

  function chunkMsForItem(item) {
    return isShortAnswerKind(item) ? SHORT_CHUNK_MS : CHUNK_MS;
  }

  function answerHintForItem(item) {
    if (itemKind(item) === 'yesno') {
      return 'Tap <strong>Start Answer</strong> and say <strong>yes</strong> or <strong>no</strong>, or tap <strong>Yes</strong> / <strong>No</strong> below.';
    }
    if (itemKind(item) === 'mcq') {
      return 'State your choice clearly, then tap <strong>Stop Answer</strong>.';
    }
    return 'Listen to the question carefully. Tap <strong>Start Answer</strong> and speak clearly.';
  }

  function resetYesNoFallbackState() {
    yesnoAudioFailed = false;
    yesnoFallbackItemId = 0;
    hideYesNoFallback();
  }

  function markYesNoAudioFailed() {
    if (!currentItem || itemKind(currentItem) !== 'yesno') return;
    yesnoAudioFailed = true;
    yesnoFallbackItemId = parseInt(currentItem.id, 10) || 0;
    showYesNoFallback();
  }

  function canShowYesNoFallback() {
    if (!currentItem || itemKind(currentItem) !== 'yesno') return false;
    if (currentItem.evaluated || cardState === CARD.LISTENING || cardState === CARD.EVALUATING) return false;
    if (awaitingNextQuestion && cardState === CARD.COMPLETE) return false;
    if (cardState === CARD.READY || cardState === CARD.CLARIFICATION) return true;
    return yesnoAudioFailed && (parseInt(currentItem.id, 10) || 0) === yesnoFallbackItemId;
  }

  function showYesNoFallback() {
    if (!els.yesnoFallback || !canShowYesNoFallback()) return;
    els.yesnoFallback.hidden = false;
    els.yesnoFallback.classList.add('is-visible');
    els.yesnoFallback.setAttribute('aria-hidden', 'false');
    syncButtons();
  }

  function hideYesNoFallback() {
    if (!els.yesnoFallback) return;
    els.yesnoFallback.hidden = true;
    els.yesnoFallback.classList.remove('is-visible');
    els.yesnoFallback.setAttribute('aria-hidden', 'true');
    syncButtons();
  }

  function submitTypedYesNo(value) {
    if (!currentItem || itemKind(currentItem) !== 'yesno' || !attemptId) return;
    if (!canShowYesNoFallback()) return;
    hideYesNoFallback();
    hideRetry();
    setCardState(CARD.EVALUATING);
    startProcessingProgress('Processing your answer');
    setStatus('Processing your answer...');
    logEvent('typed_yesno_fallback', value, { item_id: currentItem.id });
    apiJson('submit_typed_answer', {
      item_id: currentItem.id,
      student_answer_text: value
    }).then(function (out) {
      liveTranscript = value;
      setTranscriptDisplay('final', value);
      return handleEvaluationResult(out);
    }).catch(function (err) {
      finishProcessingProgress('Processing your answer');
      markYesNoAudioFailed();
      setHintContent('Could not submit your tap answer. Please try again or record your answer.<br><span style="font-size:12px;opacity:.85">' + (err.message || '') + '</span>', true);
      setCardState(CARD.READY);
      setStatus('Could not submit tap answer.');
    });
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
    var mayaSpeaking = isMayaSpeaking()
      || (awaitingNextQuestion && cardState === CARD.COMPLETE && !feedbackSpeechDone);
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
    var feedbackPending = awaitingNextQuestion && cardState === CARD.COMPLETE && !feedbackSpeechDone;
    var mayaSpeaking = isMayaSpeaking();
    var isRecording = cardState === CARD.LISTENING
      || (mediaRecorder && mediaRecorder.state !== 'inactive');
    if (els.primaryAction) {
      els.primaryAction.hidden = !!testCompleteReady;
      var canBegin = false;
      if (!testStarted) {
        canBegin = !!prepared && !sessionConnecting;
      } else if (greetingReady && !oralQuestionsStarted) {
        canBegin = true;
      }
      var canAnswer = oralQuestionsStarted
        && (cardState === CARD.READY || cardState === CARD.CLARIFICATION || retryVisible)
        && !isRecording && !mayaSpeaking && !sessionConnecting;
      var canStop = isRecording;
      var primaryEnabled = false;
      var primaryLabel = 'Ready';
      if (canStop) {
        primaryEnabled = true;
        primaryLabel = 'Stop Answer';
      } else if (canAnswer) {
        primaryEnabled = true;
        primaryLabel = 'Start Answer';
      } else if (!testStarted && canBegin) {
        primaryEnabled = true;
        primaryLabel = 'Ready';
      } else if (testStarted && !oralQuestionsStarted) {
        primaryEnabled = true;
        primaryLabel = greetingReady ? 'Ready' : 'Ready';
      } else if (testStarted && oralQuestionsStarted && sessionConnecting) {
        primaryLabel = 'Connecting...';
      } else if (oralQuestionsStarted) {
        primaryLabel = 'Start Answer';
      }
      els.primaryAction.disabled = !!testCompleteReady || !primaryEnabled;
      text(els.primaryAction, primaryLabel);
      els.primaryAction.classList.toggle('app-btn-danger', canStop && primaryEnabled);
      els.primaryAction.classList.toggle('app-btn-primary', primaryEnabled && !canStop);
      els.primaryAction.classList.toggle('app-btn-secondary', !primaryEnabled);
      els.primaryAction.classList.toggle('ptv4-btn-muted', !primaryEnabled);
    }
    if (els.replay) {
      var questionLocked = cardState === CARD.COMPLETE
        || cardState === CARD.LISTENING
        || cardState === CARD.EVALUATING
        || awaitingNextQuestion
        || (currentItem && currentItem.evaluated);
      var replayEnabled = testStarted && oralQuestionsStarted && !!currentItem && !questionLocked;
      els.replay.disabled = !replayEnabled;
      els.replay.classList.toggle('ptv4-btn-muted', !replayEnabled);
    }
    if (els.clarify) {
      var clarifyEnabled = clarificationPending && cardState === CARD.CLARIFICATION
        && !!clarificationQuestion && !mayaSpeaking && !isRecording;
      els.clarify.disabled = !clarifyEnabled;
      els.clarify.classList.toggle('ptv4-btn-muted', !clarifyEnabled);
    }
    if (els.next) {
      var nextEnabled = feedbackSpeechDone && cardState === CARD.COMPLETE && !allDone && !testCompleteReady;
      els.next.disabled = !nextEnabled;
      els.next.classList.toggle('app-btn-primary', nextEnabled);
      els.next.classList.toggle('app-btn-secondary', !nextEnabled);
      els.next.classList.toggle('ptv4-btn-muted', !nextEnabled);
    }
    if (els.myReport) {
      if (els.myReport.hidden !== !testCompleteReady) els.myReport.hidden = !testCompleteReady;
    }
    if (els.end) els.end.disabled = !!sessionConnecting;
    var actions = page.querySelector('.ptv4-card-actions');
    if (actions) {
      actions.classList.toggle('is-session-active', !!testStarted);
    }
    if (els.yesnoButtons && els.yesnoButtons.length) {
      var fallbackEnabled = canShowYesNoFallback() && !isRecording && !mayaSpeaking && cardState !== CARD.EVALUATING;
      els.yesnoButtons.forEach(function (btn) {
        btn.disabled = !fallbackEnabled;
      });
    }
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
    if (els.timerFill) els.timerFill.style.width = '0%';
    text(els.timerLabel, '\u00a0');
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

  function startTimer(mode, totalMs, labelPrefix, onExpire, existingDeadline) {
    stopTimer();
    timerMode = mode;
    timerTotalMs = totalMs;
    timerDeadline = existingDeadline && existingDeadline > Date.now()
      ? existingDeadline
      : Date.now() + totalMs;
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
    setAvatarVisuals();
    mayaBarTimer = setInterval(function () {
      if (cardState !== CARD.ASKING && cardState !== CARD.COMPLETE && !(testStarted && !oralQuestionsStarted && cardState === CARD.ASKING)) return;
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
    setAvatarVisuals();
  }

  function updateProgress(nextState) {
    state = nextState || state;
    if (!state) return;
    attemptId = parseInt(state.attempt_id || attemptId, 10) || 0;
    if (attemptId && (String(state.status || '') === 'in_progress' || String(state.status || '') === 'processing')) {
      oralSessionStarted = true;
    }
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
    applyLoadedSessionState();
    syncButtons();
  }

  function applyLoadedSessionState() {
    if (!state || !state.prepared) return;
    if (!shouldResumeOralSession(state)) return;
    oralSessionStarted = true;
    testStarted = true;
    greetingReady = true;
    oralQuestionsStarted = true;
  }

  function resumeCardStateForItem(item) {
    if (item.evaluated) return CARD.COMPLETE;
    var cs = String(item.card_state || '').toLowerCase();
    if (cs === CARD.CLARIFICATION || item.clarification_question_text) return CARD.CLARIFICATION;
    // Interrupted sessions can leave asking/evaluating/listening in DB while the item is still unanswered.
    if (cs === CARD.ASKING || cs === CARD.EVALUATING || cs === CARD.LISTENING) return CARD.READY;
    if (cs === CARD.COMPLETE) return CARD.READY;
    if (cs && cs !== CARD.COMPLETE) return cs;
    return CARD.READY;
  }

  function renderCard(item) {
    displayItem = item;
    text(els.qnum, formatQuestionNum(item.idx, state.total_questions || '?'));
    if (cardState !== CARD.ASKING) {
      text(els.question, formatQuestionText(item));
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
    if (awaitingNextQuestion) return;
    var transientStates = [CARD.ASKING, CARD.LISTENING, CARD.EVALUATING];
    if (transientStates.indexOf(cardState) >= 0 && !item.evaluated) return;
    var nextCardState = resumeCardStateForItem(item);
    if (nextCardState !== cardState) {
      if (nextCardState === CARD.READY) stopMayaBarMotion();
      setCardState(nextCardState);
    }
  }

  function onQuestionFinished(options) {
    options = options || {};
    stopMayaBarMotion();
    setCardState(CARD.READY);
    setStatus('Tap Start Answer within 30 seconds.');
    setHintContent(answerHintForItem(currentItem), true);
    if (!options.keepFallback) {
      resetYesNoFallbackState();
    }
    startStartAnswerTimer();
    if (currentItem && itemKind(currentItem) === 'yesno') {
      showYesNoFallback();
    }
  }

  function playMayaServerSpeech(speechText, logPurpose, onDone, hooks) {
    onDone = onDone || function () {};
    hooks = hooks || {};
    speechText = String(speechText || '').trim();
    if (!speechText) {
      onDone();
      return Promise.resolve();
    }

    startMayaBarMotion();
    logEvent('server_speech_start', logPurpose || 'speech', { chars: speechText.length });

    var finished = false;
    function finish(reason) {
      if (finished) return;
      finished = true;
      if (fallbackTimer) clearTimeout(fallbackTimer);
      stopMayaBarMotion();
      logEvent('server_speech_complete', (logPurpose || 'speech') + ':' + (reason || 'done'));
      onDone();
    }

    var fallbackTimer = window.setTimeout(function () {
      finish('timeout');
    }, estimateMayaSpeechMs(speechText) + 5000);

    return apiJson('synthesize_feedback_speech', { speech_text: speechText })
      .then(function (out) {
        if (!out.audio_data_url) throw new Error('Missing synthesized audio');
        if (hooks.onAudioReady) hooks.onAudioReady();
        if (logPurpose === 'feedback') {
          updateProcessingProgress('Preparing Maya feedback', 97);
        }
        return playPreparedAudio(out.audio_data_url, function () {
          finish('audio');
        }, hooks.onAudioStart);
      })
      .catch(function (err) {
        logEvent('server_speech_failed', (logPurpose || 'speech') + ':' + (err.message || 'TTS failed'));
        if (hooks.onAudioStart) hooks.onAudioStart();
        finish('error');
      });
  }

  function playMayaFeedbackSpeech(speechText, onDone, hooks) {
    return playMayaServerSpeech(speechText, 'feedback', onDone, hooks);
  }

  function playQuestionAudio(isReplay) {
    if (!currentItem || currentItem.evaluated || cardState === CARD.COMPLETE || awaitingNextQuestion) return;
    stopTimer();
    hideRetry();
    clarificationPending = false;
    clarificationMode = false;
    hideFeedbackPanel();
    setRecordHintVisible(false);
    setHintContent(answerHintForItem(currentItem), true);
    if (!isReplay) {
      resetYesNoFallbackState();
    }
    if (!currentItem) return;

    if (cardState !== CARD.COMPLETE) {
      liveTranscript = '';
      setTranscriptDisplay('idle');
    }

    var qText = formatQuestionText(currentItem);
    text(els.question, qText);
    setCardState(CARD.ASKING);
    startMayaBarMotion();
    setStatus('Maya is asking the question.');
    logEvent('question_audio_start', qText.slice(0, 120), { replay: !!isReplay });

    function doneAsking() {
      stopMayaBarMotion();
      logEvent('question_audio_end', isReplay ? 'replay' : 'initial');
      onQuestionFinished();
    }

    if (!currentItem.question_audio_url) {
      speakScript(currentItem.spoken_question, 'question', doneAsking);
      return;
    }

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
      return Promise.resolve();
    }
    return ensureVoiceConnected()
      .then(waitForDataChannel)
      .then(waitForRemoteTrack)
      .then(function () {
      if (!dc || dc.readyState !== 'open') {
        logEvent('tts_unavailable', purpose);
        throw new Error('Voice connection is not ready. Exit and try again.');
      }
      unlockAudioPlayback();
      prepareRealtimeForScriptedSpeech();
      mayaTurnPurpose = purpose || '';
      mayaExpectedText = script;
      mayaActiveResponseId = '';
      mayaTranscriptDone = false;
      scriptedResponsePending = true;
      responseInProgress = true;
      scheduleMayaAudioEnd(script, true);
      if (purpose === 'greeting') {
        text(els.question, script);
        startWordReveal(script);
      } else if (purpose === 'clarification') {
        setCardState(CARD.CLARIFICATION);
        clarificationPending = true;
      } else if (purpose === 'question' || purpose === 'feedback' || purpose === 'final') {
        setCardState(CARD.ASKING);
        startMayaBarMotion();
      }
      logEvent('tts_request', purpose, { chars: script.length });
      dc._ptv4OnSpeechDone = onDone || null;
      dc.send(JSON.stringify({
        type: 'response.create',
        response: {
          conversation: 'none',
          input: [],
          output_modalities: ['audio'],
          instructions: buildRendererInstructions(script, purpose || '')
        }
      }));
    });
  }

  function connectVoice() {
    disconnectVoice();
    return postJson('/student/api/progress_test_v4_voice_token.php', { attempt_id: attemptId }).then(function (tok) {
      pc = new RTCPeerConnection();
      ensureRemoteAudio();
      var dcOpenResolve;
      var dcOpenReject;
      dcOpenPromise = new Promise(function (resolve, reject) {
        dcOpenResolve = resolve;
        dcOpenReject = reject;
      });
      pc.ontrack = function (ev) {
        remoteAudio.srcObject = ev.streams[0];
        notifyRemoteTrackReady();
        unlockAudioPlayback();
        logEvent('remote_audio_track');
      };
      pc.onconnectionstatechange = function () {
        if (!pc) return;
        if (pc.connectionState === 'failed' || pc.connectionState === 'disconnected') {
          logEvent('voice_connection_lost', pc.connectionState);
        }
      };
      dc = pc.createDataChannel('oai-events');
      dc.onopen = function () {
        logEvent('voice_datachannel_open');
        dcOpenResolve();
      };
      dc.onerror = function () {
        dcOpenReject(new Error('Realtime data channel failed to open'));
      };
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
      }).then(function (res) {
        if (!res.ok) {
          return res.text().then(function (body) {
            throw new Error('Realtime SDP exchange failed (HTTP ' + res.status + '): ' + body.slice(0, 240));
          });
        }
        return res.text();
      }).then(function (answerSdp) {
        return pc.setRemoteDescription({ type: 'answer', sdp: answerSdp });
      }).then(function () {
        if (dc.readyState === 'open') return null;
        return waitForDataChannel();
      });
    });
  }

  function handleRealtime(msg) {
    var type = String(msg.type || '');

    if (type === 'error') {
      if (isBenignRealtimeError(msg)) return;
      logEvent('realtime_error', realtimeErrorText(msg));
      return;
    }

    if (type === 'response.created') {
      var responseId = mayaResponseEventKey(msg);
      if (!mayaTurnPurpose && !scriptedResponsePending) {
        if (dc && dc.readyState === 'open' && responseId && responseId !== mayaActiveResponseId) {
          try { dc.send(JSON.stringify({ type: 'response.cancel', response_id: responseId })); } catch (e) {}
        }
        return;
      }
      if (responseId) mayaActiveResponseId = responseId;
      scriptedResponsePending = false;
      responseInProgress = true;
      scheduleMayaAudioEnd(mayaExpectedText, true);
      if (mayaTurnPurpose !== 'greeting') {
        startMayaBarMotion();
      }
      return;
    }

    if (type === 'response.output_audio_transcript.done' && msg.transcript && mayaTurnPurpose) {
      mayaTranscriptDone = true;
      scheduleMayaAudioEnd(mayaExpectedText || String(msg.transcript || ''));
      if (mayaTurnPurpose === 'greeting') {
        finishWordReveal(mayaExpectedText || String(msg.transcript || ''));
      }
      return;
    }

    if (type === 'response.done' || type === 'response.failed' || type === 'response.cancelled' || type === 'response.canceled') {
      logEvent('tts_complete', type);
      drainSpeechResponse();
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

  function startAnswerCapture(micRetry) {
    if (!currentItem) return;
    if (!micStream) {
      if (micRetry) {
        setHintContent('Microphone unavailable. Allow microphone access and tap <strong>Start Answer</strong> again.', true);
        logEvent('recording_blocked', 'microphone still unavailable after reconnect');
        syncButtons();
        return Promise.resolve();
      }
      logEvent('recording_blocked', 'microphone not connected');
      setHintContent('Connecting your microphone...', true);
      syncButtons();
      return ensureVoiceConnected().then(function () {
        return startAnswerCapture(true);
      }).catch(function (err) {
        setHintContent('Microphone unavailable. Allow microphone access and tap <strong>Start Answer</strong> again.<br><span style="font-size:12px;opacity:.85">' + (err.message || '') + '</span>', true);
        logEvent('recording_blocked', err.message || 'microphone unavailable');
        syncButtons();
      });
    }
    if (cardState === CARD.LISTENING || cardState === CARD.EVALUATING) return;
    if (mediaRecorder && mediaRecorder.state !== 'inactive') return;
    if (isMayaSpeaking()) return;
    hideRetry();
    pauseAnswerTimerIfRunning();
    stopTimer();
    stopRecordStream();
    hideYesNoFallback();
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
    setCardState(CARD.LISTENING);
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
    var chunkMs = chunkMsForItem(currentItem);
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
    mediaRecorder.start(chunkMs);
    dbg('recorder started', { mime: mime, chunkMs: chunkMs, attemptId: attemptId, itemId: currentItem.id });
    setStatus(clarificationMode ? 'Recording clarification in English.' : 'Recording your answer.');
    syncButtons();
  }

  function handleUploadFailure(err, opts) {
    pendingEvalOpts = opts || pendingEvalOpts;
    var msg = String(err && err.message ? err.message : err || '');
    markYesNoAudioFailed();
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
          markYesNoAudioFailed();
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
        updateProcessingProgress('Evaluating your answer', 92);
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
    if (!timedOutRecording && recordingStartedAt > 0 && getRecordingMs() < minRecordingMsForItem(currentItem)) {
      cancelAnswerCapture('Please speak for at least a few seconds before stopping. Your answer was not submitted.');
      return;
    }
    playBeep('stop');
    stopTimer();
    stopStudentAnalyser();
    setRecordHintVisible(false);
    pausedAnswerTimer = null;
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
    logEvent('evaluation_complete', feedback.slice(0, 120), { score_pct: scorePct });

    if (out.next_action === 'retry_start') {
      awaitingNextQuestion = false;
      feedbackSpeechDone = true;
      clarificationPending = false;
      clarificationMode = false;
      liveTranscript = '';
      hideFeedbackPanel();
      setTranscriptDisplay('idle');
      finishProcessingProgress('Processing your answer');
      setHintContent(out.feedback_for_student || 'Tap <strong>Start Answer</strong> when you are ready.', true);
      logEvent('start_answer_timeout_retry');
      setCardState(CARD.READY);
      startStartAnswerTimer();
      if (out.show_typed_yesno_fallback) {
        showYesNoFallback();
      }
      syncButtons();
      return;
    }

    if (out.next_action === 'retry_english') {
      awaitingNextQuestion = false;
      feedbackSpeechDone = true;
      clarificationPending = false;
      clarificationMode = false;
      liveTranscript = '';
      hideFeedbackPanel();
      setTranscriptDisplay('idle');
      finishProcessingProgress('Processing your answer');
      markYesNoAudioFailed();
      playMayaServerSpeech('Please answer this question again in English.', 'retry_english', function () {
        onQuestionFinished({ keepFallback: true });
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
      finishProcessingProgress('Processing your answer');
      pausedAnswerTimer = null;
      if (out.show_typed_yesno_fallback) {
        markYesNoAudioFailed();
      }
      playMayaServerSpeech(clarificationQuestion, 'clarification', function () {
        setCardState(CARD.CLARIFICATION);
        setStatus('One clarification allowed. Tap Start Answer.');
        if (out.show_typed_yesno_fallback) {
          showYesNoFallback();
        }
        startStartAnswerTimer();
      });
      return;
    }

    resetYesNoFallbackState();

    clarificationPending = false;
    var scoreLine = 'You scored ' + scorePct + ' percent for this question.';
    var feedbackSpeech = scoreLine + ' ' + feedback;
    setCardState(CARD.COMPLETE);
    stopTimer();
    beginFeedbackProcessingProgress();
    syncButtons();
    scheduleFeedbackSpeechWatchdog(feedbackSpeech);
    playMayaFeedbackSpeech(feedbackSpeech, function () {
      clearFeedbackSpeechWatchdog();
      setCardState(CARD.COMPLETE);
      feedbackSpeechDone = true;
      syncButtons();
    }, {
      onAudioStart: function () {
        finishProcessingProgress('Processing your answer');
        setFeedbackPanel(scorePct, feedback);
      }
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
    text(els.question, greeting);
    setTranscriptDisplay('preidle');
    setHintContent('Listen to Maya, then tap <strong>Ready</strong>.', true);
    setCardState(CARD.ASKING);
    startMayaBarMotion();
    logEvent('greeting_start');

    var greetingFinished = false;
    var greetingFallbackTimer = null;

    function finishGreeting(reason) {
      if (greetingFinished) return;
      greetingFinished = true;
      if (greetingFallbackTimer) clearTimeout(greetingFallbackTimer);
      stopMayaBarMotion();
      finishWordReveal(greeting);
      greetingReady = true;
      setCardState(CARD.READY);
      setHintContent('Tap <strong>Ready</strong> to begin Question 1.', true);
      logEvent('greeting_complete', reason || 'done');
      syncButtons();
      ensureOralSessionStarted().catch(function (err) {
        logEvent('oral_session_start_failed', err.message || 'start failed');
        setHintContent('Could not mark the test as started. Tap <strong>Ready</strong> to try again.<br><span style="font-size:12px;opacity:.85">' + (err.message || '') + '</span>', true);
        syncButtons();
      });
    }

    function failGreeting(err) {
      if (greetingFallbackTimer) clearTimeout(greetingFallbackTimer);
      stopWordReveal(greeting);
      stopMayaBarMotion();
      testStarted = false;
      setCardState(CARD.READY);
      greetingReady = false;
      setHintContent('Maya audio could not start. Check your speakers and microphone permission, then tap <strong>Ready</strong> again.<br><span style="font-size:12px;opacity:.85">' + (err.message || 'Voice unavailable') + '</span>', true);
      logEvent('greeting_failed', err.message || 'Voice unavailable');
      syncButtons();
    }

    greetingFallbackTimer = window.setTimeout(function () {
      finishGreeting('timeout');
    }, estimateMayaSpeechMs(greeting) + 8000);

    return apiJson('synthesize_feedback_speech', { speech_text: greeting })
      .then(function (out) {
        if (!out || !out.audio_data_url) {
          throw new Error('Missing greeting audio');
        }
        logEvent('greeting_audio_start', 'server_tts');
        return playPreparedAudio(out.audio_data_url, function () {
          finishGreeting('audio');
        }, function () {
          startWordReveal(greeting, questionAudio);
        });
      })
      .catch(failGreeting);
  }

  function beginOralQuestions() {
    if (!currentItem) return;
    oralQuestionsStarted = true;
    greetingReady = true;
    awaitingNextQuestion = false;
    feedbackSpeechDone = false;
    hideRetry();
    hideFeedbackPanel();
    renderCard(currentItem);
    setStatus('Professional oral check in progress.');
    logEvent('oral_questions_start');
    playQuestionAudio();
    syncButtons();
  }

  function ensurePrepared() {
    return apiJson('ensure_prepared', { cohort_id: cfg.cohortId, lesson_id: cfg.lessonId }).then(function (out) {
      updateProgress(out.state);
      if (out.preparing || !out.state.prepared) {
        prepTimer = setTimeout(ensurePrepared, 2500);
        return;
      }
      if (prepTimer) clearTimeout(prepTimer);
      if (shouldResumeOralSession(out.state)) {
        return resumeOralSession();
      }
      startCamera();
      renderIdleLayout();
      syncButtons();
    }).catch(function () {
      syncButtons();
    });
  }

  function startTestFlow() {
    if (sessionConnecting || testStarted) return;
    unlockAudioPlayback();
    greetingReady = false;
    oralQuestionsStarted = false;
    oralSessionStarted = false;
    sessionConnecting = true;
    syncButtons();
    voiceConnectPromise = connectVoice().catch(function (err) {
      logEvent('voice_connect_failed', err.message || 'connect failed');
      voiceConnectPromise = null;
    });
    Promise.all([startCamera(), voiceConnectPromise.catch(function () { return null; })]).then(function () {
      sessionConnecting = false;
      testStarted = true;
      syncButtons();
      return playGreeting();
    }).then(function () {
      syncButtons();
    }).catch(function (err) {
      sessionConnecting = false;
      testStarted = false;
      voiceConnectPromise = null;
      disconnectVoice();
      syncButtons();
      setHintContent('Could not start the voice session. Check microphone permission and try again.<br><span style="font-size:12px;opacity:.85">' + (err.message || 'Connection failed') + '</span>', true);
      logEvent('start_flow_failed', err.message || 'Connection failed');
    });
  }

  function beginQuestionsFromReady() {
    skipGreetingIfNeeded();
    ensureOralSessionStarted().then(function () {
      beginOralQuestions();
    }).catch(function (err) {
      setHintContent('Could not start the oral session. Tap <strong>Ready</strong> to try again.<br><span style="font-size:12px;opacity:.85">' + (err.message || 'Start failed') + '</span>', true);
      logEvent('oral_session_start_failed', err.message || 'start failed');
      syncButtons();
    });
  }

  function onPrimaryActionClick() {
    unlockAudioPlayback();
    var isRecording = cardState === CARD.LISTENING
      || (mediaRecorder && mediaRecorder.state !== 'inactive');
    if (isRecording) {
      stopAnswerCapture(false);
      return;
    }
    if (!testStarted) {
      startTestFlow();
      return;
    }
    if (!oralQuestionsStarted) {
      beginQuestionsFromReady();
      return;
    }
    if (oralQuestionsStarted && !isMayaSpeaking()) {
      startAnswerCapture();
    }
  }

  function nextQuestion() {
    if (!state || !currentItem || !feedbackSpeechDone) return;
    clearFeedbackSpeechWatchdog();
    stopMayaBarMotion();
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

  function escapeHtml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
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

  function collectFeedbackRatings() {
    var out = {};
    $all('[data-ptv4-fb-q]').forEach(function (block) {
      var key = block.getAttribute('data-ptv4-fb-q');
      var checked = block.querySelector('input[type="radio"]:checked');
      if (checked) out[key] = checked.value;
    });
    return out;
  }

  if (els.primaryAction) els.primaryAction.addEventListener('click', onPrimaryActionClick);
  if (els.replay) els.replay.addEventListener('click', function () { playQuestionAudio(true); });
  if (els.clarify) els.clarify.addEventListener('click', function () {
    if (!clarificationQuestion || isMayaSpeaking()) return;
    if (cardState === CARD.LISTENING || (mediaRecorder && mediaRecorder.state !== 'inactive')) return;
    pauseAnswerTimerIfRunning();
    stopTimer();
    playMayaServerSpeech(clarificationQuestion, 'clarification', function () {
      setCardState(CARD.CLARIFICATION);
      setStatus('One clarification allowed. Tap Start Answer.');
      restoreAnswerTimerIfAny();
      syncButtons();
    });
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
  if (els.reportCloseBottom) els.reportCloseBottom.addEventListener('click', closeReportModal);
  if (els.reportExpandAll) {
    els.reportExpandAll.addEventListener('click', function () {
      var anyClosed = els.reportQuestions && els.reportQuestions.querySelector('.ptv4-report-qcard:not(.is-open)');
      setAllQuestionCards(!!anyClosed);
    });
  }
  if (els.reportModal) els.reportModal.addEventListener('click', function (ev) {
    if (ev.target === els.reportModal) closeReportModal();
  });
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
  if (els.yesnoButtons && els.yesnoButtons.length) {
    els.yesnoButtons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        submitTypedYesNo(String(btn.getAttribute('data-ptv4-yesno-value') || '').trim());
      });
    });
  }
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

  initFeedbackForm();
  ensurePrepared();
  syncButtons();

  window.addEventListener('pagehide', function () {
    flushDebugEventsToServer(true);
    if (testStarted && !oralQuestionsStarted) {
      rollbackOralSessionBeacon('page_unload');
    }
  });
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      flushDebugEventsToServer(true);
    }
  });
})();
