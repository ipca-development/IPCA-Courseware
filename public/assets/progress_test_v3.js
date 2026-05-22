(function () {
  'use strict';

  var cfg = window.IPCAProgressTestV3Config || {};
  var root = document.querySelector('[data-ptv3-root]');
  if (!root) return;

  function $(sel) { return root.querySelector(sel); }
  function text(el, value) { if (el) el.textContent = value; }
  function clamp(n, min, max) { return Math.max(min, Math.min(max, n)); }

  var els = {
    status: $('[data-ptv3-status]'),
    stage: $('[data-ptv3-stage]'),
    title: $('[data-ptv3-title]'),
    attempt: $('[data-ptv3-attempt]'),
    score: $('[data-ptv3-score]'),
    bar: $('[data-ptv3-bar]'),
    ready: $('[data-ptv3-ready]'),
    current: $('[data-ptv3-current]'),
    evaluated: $('[data-ptv3-evaluated]'),
    final: $('[data-ptv3-final]'),
    thread: $('[data-ptv3-thread]'),
    empty: $('[data-ptv3-empty]'),
    liveRow: $('[data-ptv3-live-row]'),
    start: $('[data-ptv3-start]'),
    finish: $('[data-ptv3-finish]'),
    end: $('[data-ptv3-end]'),
    video: $('[data-ptv3-video]'),
    videoFallback: $('[data-ptv3-video-fallback]'),
    recording: $('[data-ptv3-recording]'),
    answerTimer: $('[data-ptv3-answer-timer]'),
    timerFill: $('[data-ptv3-timer-fill]'),
    timerStatus: $('[data-ptv3-timer-status]')
  };

  var state = null;
  var attemptId = 0;
  var currentItem = null;
  var pc = null;
  var dc = null;
  var localStream = null;
  var cameraStream = null;
  var remoteAudio = null;
  var connected = false;
  var muted = false;
  var awaitingAnswer = false;
  var awaitingClarification = false;
  var clarificationMode = '';
  var originalAnswer = '';
  var clarificationQuestion = '';
  var activeStudentBubble = null;
  var responseInProgress = false;
  var pendingSpeakText = '';
  var processedTranscripts = {};
  var mayaTurnPurpose = '';
  var pendingPurpose = '';
  var acceptAnswerAfterMaya = false;
  var nextQuestionAfterFeedback = false;
  var completeAfterFeedback = false;
  var audioCheckActive = false;
  var awaitingAudioCheck = false;
  var answerSegments = [];
  var answerSettleTimer = null;
  var awaitingDoneConfirmation = false;
  var doneConfirmationText = '';
  var answerSettleMs = 4200;
  var prepTimer = null;
  var prepPct = 0;
  var lastBubbleKey = '';
  var lastBubbleAt = 0;
  var recentBubbleKeys = {};
  var donePromptShownForAnswer = '';
  var ignoreTranscriptsUntil = 0;
  var activeAnswerItemId = 0;
  var feedbackPlaybackTimer = null;
  var pendingNextQuestionAfterFeedback = false;
  var pendingCompleteAfterFeedback = false;
  var finalEvaluationReady = false;
  var phase = 'idle';
  var answerCaptureActive = false;
  var submitAfterFlushTimer = null;
  var audioContext = null;
  var liveMayaBubble = null;
  var liveMayaText = '';
  var preparedMayaText = '';
  var transcriptFlushUntil = 0;
  var transcriptWaitTimer = null;
  var pendingTranscriptSubmit = false;
  var pendingTranscriptKind = '';
  var transcriptWaitStartedAt = 0;
  var transcriptLastSegmentAt = 0;
  var pendingTranscriptSubmitAfterMaya = false;
  var transcriptQuietMs = 2000;
  var transcriptMaxWaitMs = 8000;
  var mayaTurnTailTimer = null;
  var mayaTurnMaxTimer = null;
  var mayaTranscriptDone = false;
  var pendingFinishPurpose = '';
  var mayaExpectedText = '';
  var mayaDriftDetected = false;
  var answerTimerInterval = null;
  var answerTimerDeadline = 0;
  var answerTimerHandled = false;
  var ANSWER_START_LIMIT_MS = 30000;
  var mayaQuestionRetryUsed = false;
  var mayaRetryAnswerUsed = false;
  var mayaEnglishRetryUsed = {};
  var mayaSpeechSettleMs = 220;
  var mayaIdlePollTimer = null;
  var transcriptStopAt = 0;
  var answerCaptureStartedAt = 0;
  var answerCommitInterval = null;
  var ANSWER_COMMIT_INTERVAL_MS = 12000;
  var MIN_AUDIO_COMMIT_MS = 400;
  var answerStopWasQuick = false;
  var answerRetryOfferedForItem = 0;
  var mayaAudioEndsAt = 0;
  var mayaSpeechStartedAt = 0;
  var mayaScriptRetryUsed = {};
  var answerTimerWaitHandle = null;
  var handledMayaResponseDoneKeys = {};
  var handledMayaTranscriptDoneKeys = {};
  var MAYA_RENDERER_INSTRUCTIONS = 'Read the user message aloud verbatim in English. Do not add, remove, translate, or repeat words. No preamble or closing remarks. Stop after the final word.';

  function clearMayaResponseDedupe() {
    handledMayaTranscriptDoneKeys = {};
  }

  function mayaResponseEventKey(msg) {
    return String((msg && msg.response && msg.response.id) || msg.response_id || msg.event_id || '').trim();
  }

  function setCoachState(name) {
    root.setAttribute('data-coach-state', name);
  }

  function setMayaSpeaking(active) {
    root.setAttribute('data-maya-speaking', active ? '1' : '0');
    if (active) stopAnswerTimer();
    syncAnswerButtonState();
  }

  function isMayaSpeaking() {
    return root.getAttribute('data-maya-speaking') === '1' || responseInProgress || isMayaAudioPlaybackActive();
  }

  function isMayaAudioPlaybackActive() {
    return Date.now() < mayaAudioEndsAt;
  }

  function scheduleMayaAudioEnd(text, resetStart) {
    var tail = mayaPlaybackTailMs(String(text || mayaExpectedText || preparedMayaText || ''));
    if (resetStart || !mayaSpeechStartedAt) {
      mayaSpeechStartedAt = Date.now();
    }
    mayaAudioEndsAt = mayaSpeechStartedAt + tail;
  }

  function clearMayaAudioEnd() {
    mayaAudioEndsAt = 0;
    mayaSpeechStartedAt = 0;
  }

  function syncAnswerButtonState() {
    if (!els.finish) return;
    var mode = els.finish.getAttribute('data-action-mode') || '';
    var label = String(els.finish.textContent || '').trim();
    if (mode !== 'answer') return;
    if (label !== 'START' && label !== 'ANSWER CLARIFICATION') return;
    if (isMayaSpeaking()) {
      els.finish.disabled = true;
      setFinishPulse(false);
    } else if (!answerCaptureActive) {
      els.finish.disabled = false;
      if (phase === 'answering' && awaitingAnswer) setFinishPulse(true);
    }
  }

  function stopMayaTurnTimers() {
    if (mayaTurnTailTimer) clearTimeout(mayaTurnTailTimer);
    if (mayaTurnMaxTimer) clearTimeout(mayaTurnMaxTimer);
    mayaTurnTailTimer = null;
    mayaTurnMaxTimer = null;
  }

  function estimateMayaSpeechMs(text) {
    var t = String(text || '').trim();
    if (!t) return 2200;
    var words = t.split(/\s+/).filter(Boolean).length;
    return clamp(Math.round(words * 360 + 900), 1600, 90000);
  }

  function mayaPlaybackTailMs(text) {
    var script = String(text || liveMayaText || preparedMayaText || '').trim();
    var full = estimateMayaSpeechMs(script);
    return clamp(Math.round(full * 0.9), 2200, 90000);
  }

  function completeMayaTurn() {
    if (!pendingFinishPurpose) return;
    if (isMayaAudioPlaybackActive() || responseInProgress) {
      syncAnswerButtonState();
      mayaTurnTailTimer = setTimeout(completeMayaTurn, 150);
      return;
    }
    var purpose = pendingFinishPurpose;
    pendingFinishPurpose = '';
    mayaDriftDetected = false;
    mayaTurnPurpose = '';
    mayaTranscriptDone = false;
    stopMayaTurnTimers();
    clearMayaAudioEnd();
    setMayaSpeaking(false);
    finishMayaTurn(purpose);
  }

  function scheduleMayaTurnComplete(purpose) {
    stopMayaTurnTimers();
    pendingFinishPurpose = purpose;
    scheduleMayaAudioEnd(liveMayaText || preparedMayaText || '');
    completeMayaTurn();
  }

  function onMayaTranscriptDone(transcript) {
    mayaTranscriptDone = true;
    liveMayaText = String(transcript || '').trim();
    scheduleMayaAudioEnd(liveMayaText || mayaExpectedText || preparedMayaText);
    if (!pendingFinishPurpose || responseInProgress) return;
    stopMayaTurnTimers();
    completeMayaTurn();
  }

  function clearRealtimeTurnState() {
    pendingSpeakText = '';
    pendingPurpose = '';
    pendingFinishPurpose = '';
    mayaTurnPurpose = '';
    mayaTranscriptDone = false;
    mayaDriftDetected = false;
    responseInProgress = false;
    stopMayaTurnTimers();
    clearMayaAudioEnd();
    setMayaSpeaking(false);
    if (dc && dc.readyState === 'open') {
      try { dc.send(JSON.stringify({ type: 'response.cancel' })); } catch (e) {}
      try { dc.send(JSON.stringify({ type: 'input_audio_buffer.clear' })); } catch (e) {}
    }
  }

  function cancelRealtimeAudioOnly() {
    stopMayaTurnTimers();
    if (dc && dc.readyState === 'open') {
      try { dc.send(JSON.stringify({ type: 'response.cancel' })); } catch (e) {}
      try { dc.send(JSON.stringify({ type: 'input_audio_buffer.clear' })); } catch (e) {}
    }
  }

  function prepareRealtimeForScriptedSpeech() {
    if (dc && dc.readyState === 'open') {
      try { dc.send(JSON.stringify({ type: 'input_audio_buffer.clear' })); } catch (e) {}
    }
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

  function escapeScriptQuote(text) {
    return String(text || '').replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  }

  function scriptOpeningWords(text, count) {
    return String(text || '').trim().split(/\s+/).filter(Boolean).slice(0, count || 3).join(' ');
  }

  function buildTurnInstructions(text) {
    return String(text || '').trim();
  }

  function normalizeCompareText(value) {
    return String(value || '').toLowerCase().replace(/[\u2018\u2019]/g, "'").replace(/[\u201c\u201d]/g, '"').replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
  }

  function isMayaMetaPreamble(transcript) {
    var actual = normalizeCompareText(transcript);
    return /\b(here is the next question|here is the quoted|quoted script|for your progress test|progress test script|the next question|can only read|only read the script|read the script aloud|read the script|text to speech|exact words|exact text|cannot assist right now|cannot assist|i am sorry but|i am sorry i|sorry but i can)\b/.test(actual);
  }

  function hasMayaMetaPreambleBeforeScript(transcript) {
    if (!isMayaMetaPreamble(transcript)) return false;
    var expected = normalizeCompareText(mayaExpectedText || preparedMayaText);
    var actual = normalizeCompareText(transcript);
    if (!expected) return true;
    var opening = expected.split(' ').filter(Boolean).slice(0, 3).join(' ');
    if (opening.length < 4) return true;
    var idx = actual.indexOf(opening);
    return idx === -1 || idx > 6;
  }

  function isMayaOffScriptSpeech(transcript) {
    var actual = normalizeCompareText(transcript);
    return isMayaMetaPreamble(transcript)
      || isMayaConversationalIntro(transcript)
      || /\b(i can help|let me help|happy to help|no problem|not sure what you|if you are unsure|there is no problem|there s no problem|i am here to help|i m here to help|dive into|materials deeper|ready to dive|let s review|lets review|let me explain|would you like|what would you like|great what|what do you want|feel free|great question|good question|shall we|cannot answer|can t answer|unable to answer|won t answer|not able to answer|sorry i cannot|sorry i can t|i cannot answer|i can t answer)\b/.test(actual);
  }

  function isMayaConversationalIntro(transcript) {
    var actual = normalizeCompareText(transcript);
    if (!actual) return false;
    return /^(got it|okay|ok|sure|understood|alright|all right|great|perfect|wonderful|thanks|thank you|now let|so let|let s start|lets start|let us start|moving on|next up|crucial question|important question)\b/.test(actual)
      || /\b(let s start|lets start|let us start|crucial question|important question|moving on to|start with a|start with the|question on weather|question about weather|next question is)\b/.test(actual);
  }

  function isBuildingExpectedScriptOpening(partial) {
    var expected = normalizeCompareText(mayaExpectedText || preparedMayaText || '');
    var actual = normalizeCompareText(partial);
    if (!expected || !actual) return true;
    return expected.indexOf(actual) === 0;
  }

  function spokenScriptAnchorMatches(transcript) {
    var expected = normalizeCompareText(mayaExpectedText || preparedMayaText || '');
    var actual = normalizeCompareText(transcript);
    if (!expected || !actual) return false;
    if (actual === expected || expected.indexOf(actual) === 0 || actual.indexOf(expected) === 0) return true;
    var opening = expected.split(' ').filter(Boolean).slice(0, 3).join(' ');
    if (opening.length < 4) return false;
    var idx = actual.indexOf(opening);
    return idx >= 0 && idx <= 8;
  }

  function hasExpectedScriptOpening(partial) {
    var actual = normalizeCompareText(partial);
    if (!actual || actual.length < 4) return true;
    return isBuildingExpectedScriptOpening(partial);
  }

  function englishWordOverlapScore(transcript, expected) {
    var act = normalizeCompareText(transcript).split(' ').filter(Boolean);
    var expSet = {};
    normalizeCompareText(expected).split(' ').filter(Boolean).forEach(function (word) { expSet[word] = true; });
    if (!act.length) return 1;
    var hits = 0;
    act.forEach(function (word) { if (expSet[word]) hits += 1; });
    return hits / act.length;
  }

  function looksLikeNonEnglishScript(transcript, expected) {
    var act = normalizeCompareText(transcript);
    var exp = normalizeCompareText(expected);
    if (!act || !exp) return false;
    if (/^(je|jij|u|het|de|een)\b/.test(act)) return true;
    if (/\b(je hebt|jij hebt|u hebt|niet duidelijk|niet correct|voor deze vraag|op deze vraag|zou moeten|zouden bespreken|vliegles|in het engels|in het nederlands)\b/.test(act)) return true;
    if (act.split(' ').length >= 4 && englishWordOverlapScore(transcript, expected) < 0.42) return true;
    return false;
  }

  function retryScriptedSpeechInEnglish() {
    var text = mayaExpectedText || preparedMayaText || '';
    var purpose = mayaTurnPurpose || pendingFinishPurpose || '';
    if (!text || !purpose) return false;
    if (mayaEnglishRetryUsed[purpose]) return false;
    mayaEnglishRetryUsed[purpose] = true;
    mayaDriftDetected = true;
    mayaTranscriptDone = false;
    stopMayaSpeechForNewScript();
    ensureExpectedMayaBubble();
    window.setTimeout(function () {
      mayaDriftDetected = false;
      sendSpokenText(text, purpose);
    }, mayaSpeechSettleMs);
    return true;
  }

  function retryScriptedSpeechWithoutPreamble() {
    var text = mayaExpectedText || preparedMayaText || '';
    var purpose = mayaTurnPurpose || pendingFinishPurpose || '';
    if (!text || !purpose) return false;
    if (mayaScriptRetryUsed[purpose]) return false;
    mayaScriptRetryUsed[purpose] = true;
    mayaDriftDetected = true;
    mayaTranscriptDone = false;
    responseInProgress = false;
    stopMayaSpeechForNewScript();
    ensureExpectedMayaBubble();
    window.setTimeout(function () {
      mayaDriftDetected = false;
      sendSpokenText(text, purpose);
    }, mayaSpeechSettleMs);
    return true;
  }

  function handleScriptedSpeechDrift(partialMayaText) {
    if (mayaTurnPurpose === 'retry_answer' || mayaTurnPurpose === 'audio_check') {
      abortMayaSpeechDrift();
      return;
    }
    if (mayaTurnPurpose === 'question') {
      if (retryScriptedSpeechWithoutPreamble()) return;
    }
    abortMayaSpeechDrift();
  }

  function isMayaSpeechDrift(transcript) {
    var expected = normalizeCompareText(mayaExpectedText || preparedMayaText);
    var actual = normalizeCompareText(transcript);
    if (!expected || !actual) return false;
    if (hasMayaMetaPreambleBeforeScript(transcript)) return true;
    if (isMayaOffScriptSpeech(actual)) return true;
    if (/^(understood|okay|ok|sure|got it|certainly|of course|yes)\b/.test(actual) && expected.indexOf(actual) !== 0) return true;
    if (actual === expected) return false;
    if (expected.indexOf(actual) !== -1 && actual.length >= Math.max(12, expected.length * 0.75)) return false;
    if (actual.indexOf(expected) === 0) {
      return actual.length > expected.length + 24;
    }
    var expectedWords = expected.split(' ').filter(Boolean);
    var actualWords = actual.split(' ').filter(Boolean);
    if (!expectedWords.length || !actualWords.length) return false;
    var actualSet = {};
    actualWords.forEach(function (word) { actualSet[word] = true; });
    var hits = 0;
    expectedWords.forEach(function (word) { if (actualSet[word]) hits += 1; });
    var coverage = hits / expectedWords.length;
    return coverage < 0.78 || actualWords.length > expectedWords.length + 6;
  }

  function handleQuestionPreambleDrift() {
    if (retryScriptedSpeechWithoutPreamble()) return true;
    abortMayaSpeechDrift();
    return false;
  }

  function retryQuestionWithoutPreamble() {
    return retryScriptedSpeechWithoutPreamble();
  }

  function retryAnswerWithoutPreamble() {
    var text = mayaExpectedText || preparedMayaText || '';
    if (!text) return false;
    mayaDriftDetected = true;
    mayaTranscriptDone = false;
    stopMayaSpeechForNewScript();
    ensureExpectedMayaBubble();
    window.setTimeout(function () {
      mayaDriftDetected = false;
      sendSpokenText(text, 'retry_answer');
    }, mayaSpeechSettleMs);
    return true;
  }

  function handleRetryAnswerDrift() {
    abortMayaSpeechDrift();
    return false;
  }

  function clearMayaIdlePoll() {
    if (mayaIdlePollTimer) clearTimeout(mayaIdlePollTimer);
    mayaIdlePollTimer = null;
  }

  function stopMayaSpeechForNewScript() {
    clearMayaIdlePoll();
    stopMayaTurnTimers();
    pendingSpeakText = '';
    pendingPurpose = '';
    pendingFinishPurpose = '';
    mayaDriftDetected = false;
    clearMayaAudioEnd();
    cancelRealtimeAudioOnly();
    responseInProgress = false;
    setMayaSpeaking(false);
  }

  function speakExactWhenIdle(textToSpeak, purpose) {
    textToSpeak = String(textToSpeak || '').trim();
    if (!textToSpeak) return;
    clearMayaIdlePoll();
    pendingSpeakText = '';
    pendingPurpose = '';
    var attempts = 0;
    function startWhenIdle() {
      if (responseInProgress && attempts < 80) {
        attempts += 1;
        mayaIdlePollTimer = setTimeout(startWhenIdle, 100);
        return;
      }
      clearMayaIdlePoll();
      stopMayaSpeechForNewScript();
      mayaIdlePollTimer = setTimeout(function () {
        mayaIdlePollTimer = null;
        speakExact(textToSpeak, purpose);
      }, mayaSpeechSettleMs);
    }
    startWhenIdle();
  }

  function abortMayaSpeechDrift() {
    if (mayaDriftDetected) return;
    var purpose = mayaTurnPurpose || pendingFinishPurpose || '';
    if (!purpose) return;
    mayaDriftDetected = true;
    cancelRealtimeAudioOnly();
    ensureExpectedMayaBubble();
    responseInProgress = false;
    mayaTranscriptDone = true;
    liveMayaText = mayaExpectedText || preparedMayaText || '';
    pendingFinishPurpose = purpose;
    stopMayaTurnTimers();
    clearMayaAudioEnd();
    completeMayaTurn();
  }

  function removeLiveMayaBubble() {
    if (liveMayaBubble && liveMayaBubble.row && liveMayaBubble.row.parentNode) {
      liveMayaBubble.row.parentNode.removeChild(liveMayaBubble.row);
    }
    liveMayaBubble = null;
    liveMayaText = '';
  }

  function ensureExpectedMayaBubble() {
    var expected = mayaExpectedText || preparedMayaText || '';
    if (!expected) return;
    if (!liveMayaBubble) {
      liveMayaBubble = addBubble('maya', 'Maya', expected, 'live');
    } else if (liveMayaBubble.body) {
      liveMayaBubble.body.textContent = expected;
    }
    liveMayaText = expected;
    if (els.thread) els.thread.scrollTop = els.thread.scrollHeight;
  }

  function stopAnswerTimer() {
    if (answerTimerInterval) clearInterval(answerTimerInterval);
    answerTimerInterval = null;
    answerTimerDeadline = 0;
    answerTimerHandled = false;
    if (answerTimerWaitHandle) clearTimeout(answerTimerWaitHandle);
    answerTimerWaitHandle = null;
    if (els.answerTimer) els.answerTimer.setAttribute('data-active', '0');
    if (els.timerFill) {
      els.timerFill.style.width = '100%';
      els.timerFill.setAttribute('data-danger', '0');
    }
    text(els.timerStatus, '');
  }

  function tickAnswerTimer() {
    if (!answerTimerDeadline) return;
    if (answerCaptureActive) {
      stopAnswerTimer();
      return;
    }
    if (isMayaAudioPlaybackActive() || responseInProgress) {
      answerTimerDeadline += 100;
      return;
    }
    var remain = Math.max(0, answerTimerDeadline - Date.now());
    var pct = (remain / ANSWER_START_LIMIT_MS) * 100;
    if (els.timerFill) {
      els.timerFill.style.width = clamp(pct, 0, 100) + '%';
      els.timerFill.setAttribute('data-danger', pct <= 35 ? '1' : '0');
    }
    var secs = Math.max(0, Math.ceil(remain / 1000));
    text(els.timerStatus, secs + ' second' + (secs === 1 ? '' : 's') + ' left to start your answer');
    if (remain <= 0 && !answerTimerHandled) {
      answerTimerHandled = true;
      handleAnswerTimeout();
    }
  }

  function startAnswerTimerAfterPlayback() {
    if (answerTimerWaitHandle) clearTimeout(answerTimerWaitHandle);
    syncAnswerButtonState();
    if (phase !== 'answering' || !awaitingAnswer || answerCaptureActive) return;
    if (responseInProgress || isMayaAudioPlaybackActive()) {
      answerTimerWaitHandle = setTimeout(startAnswerTimerAfterPlayback, 150);
      return;
    }
    syncAnswerButtonState();
    startAnswerTimer();
  }

  function startAnswerTimer() {
    stopAnswerTimer();
    if (phase !== 'answering' || !awaitingAnswer || answerCaptureActive || responseInProgress || isMayaAudioPlaybackActive()) return;
    if (!els.answerTimer) return;
    answerTimerHandled = false;
    els.answerTimer.setAttribute('data-active', '1');
    answerTimerDeadline = Date.now() + ANSWER_START_LIMIT_MS;
    tickAnswerTimer();
    answerTimerInterval = setInterval(tickAnswerTimer, 100);
  }

  function setStudentAnswering(active) {
    answerCaptureActive = !!active;
    root.setAttribute('data-student-answering', active ? '1' : '0');
  }

  function setStatus(message, stage) {
    text(els.status, message);
    if (stage) text(els.stage, stage);
  }

  function setTyping(label, visible, role) {
    if (!els.liveRow) return;
    if (visible && els.thread) {
      els.thread.appendChild(els.liveRow);
    } else if (els.thread && els.liveRow.parentNode !== els.thread) {
      els.thread.appendChild(els.liveRow);
    }
    var labelEl = els.liveRow.querySelector('span');
    if (labelEl) labelEl.textContent = label || 'Working';
    els.liveRow.setAttribute('data-role', role || 'maya');
    els.liveRow.setAttribute('data-visible', visible ? '1' : '0');
    if (visible && els.thread) els.thread.scrollTop = els.thread.scrollHeight;
  }

  function playBeep(kind) {
    try {
      var AudioContextCtor = window.AudioContext || window.webkitAudioContext;
      if (!AudioContextCtor) return;
      audioContext = audioContext || new AudioContextCtor();
      if (audioContext.state === 'suspended') audioContext.resume();
      var oscillator = audioContext.createOscillator();
      var gain = audioContext.createGain();
      oscillator.type = 'sine';
      oscillator.frequency.value = kind === 'start' ? 880 : 330;
      gain.gain.setValueAtTime(0.001, audioContext.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.16, audioContext.currentTime + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.001, audioContext.currentTime + 0.5);
      oscillator.connect(gain);
      gain.connect(audioContext.destination);
      oscillator.start();
      oscillator.stop(audioContext.currentTime + 0.52);
    } catch (e) {}
  }

  function postJson(url, payload) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload || {})
    }).then(function (res) {
      return res.text().then(function (txt) {
        var json = null;
        try { json = JSON.parse(txt); } catch (e) {}
        if (!json) throw new Error('Non-JSON response: ' + txt.slice(0, 180));
        if (!res.ok || json.ok === false) throw new Error(json.error || ('HTTP ' + res.status));
        return json;
      });
    });
  }

  function api(action, payload) {
    payload = payload || {};
    payload.action = action;
    if (attemptId && !payload.attempt_id) payload.attempt_id = attemptId;
    return postJson('/student/api/progress_test_v3_oral.php', payload);
  }


  function resetClientConversation() {
    stopAnswerTimer();
    stopMayaTurnTimers();
    pendingFinishPurpose = '';
    mayaTranscriptDone = false;
    resetAnswerBuffer();
    processedTranscripts = {};
    lastBubbleKey = '';
    lastBubbleAt = 0;
    recentBubbleKeys = {};
    activeStudentBubble = null;
    activeAnswerItemId = 0;
    if (els.finish) els.finish.disabled = true;
    setFinishButton('START', true, 'answer');
    awaitingAnswer = false;
    awaitingClarification = false;
    clarificationMode = '';
    audioCheckActive = false;
    awaitingAudioCheck = false;
    setStudentAnswering(false);
    setMayaSpeaking(false);
    phase = 'preparing';
    if (els.thread) {
      els.thread.innerHTML = '';
      if (els.empty) {
        els.empty.style.display = '';
        els.thread.appendChild(els.empty);
      }
      if (els.liveRow) {
        els.liveRow.setAttribute('data-visible', '0');
        els.thread.appendChild(els.liveRow);
      }
    }
  }

  function addBubble(role, label, message, kind) {
    message = String(message || '').trim();
    var bubbleKey = role + '|' + (kind || '') + '|' + message.toLowerCase().replace(/\s+/g, ' ');
    var now = Date.now();
    if (message && bubbleKey === lastBubbleKey && now - lastBubbleAt < 2500) return null;
    if (message && recentBubbleKeys[bubbleKey] && now - recentBubbleKeys[bubbleKey] < 45000) return null;
    lastBubbleKey = bubbleKey;
    lastBubbleAt = now;
    if (message) recentBubbleKeys[bubbleKey] = now;
    if (els.empty) els.empty.style.display = 'none';
    var row = document.createElement('div');
    row.className = 'maya-chat-row ' + (role === 'student' ? 'is-student' : 'is-maya');
    var bubble = document.createElement('div');
    bubble.className = 'maya-chat-bubble';
    if (kind) bubble.setAttribute('data-kind', kind);
    var meta = document.createElement('div');
    meta.className = 'maya-chat-meta';
    meta.textContent = label || (role === 'student' ? 'Student' : 'Maya');
    var body = document.createElement('div');
    body.textContent = message;
    bubble.appendChild(meta);
    bubble.appendChild(body);
    row.appendChild(bubble);
    els.thread.appendChild(row);
    if (els.liveRow && els.liveRow.parentNode === els.thread && els.liveRow.getAttribute('data-visible') === '1') {
      els.thread.appendChild(els.liveRow);
    }
    els.thread.scrollTop = els.thread.scrollHeight;
    return { row: row, bubble: bubble, body: body };
  }

  function updateProgress(nextState) {
    state = nextState || state;
    if (!state) return;
    attemptId = parseInt(state.attempt_id || attemptId || 0, 10) || 0;
    var total = parseInt(state.total_questions || 0, 10) || 0;
    var items = Array.isArray(state.items) ? state.items : [];
    var evaluated = items.filter(function (item) { return !!item.evaluated; }).length;
    if (!items.length) evaluated = parseInt(state.evaluated_count || 0, 10) || 0;
    var idx = 0;
    var currentId = parseInt(state.current_item_id || 0, 10) || 0;
    var scoreSum = 0;
    var scoreCount = 0;
    items.forEach(function (item) {
      if (parseInt(item.id, 10) === currentId) idx = parseInt(item.idx || 0, 10) || 0;
      if (item.evaluated && item.score_pct != null && item.score_pct !== '') {
        scoreSum += parseFloat(item.score_pct) || 0;
        scoreCount += 1;
      }
    });
    if (!idx) idx = parseInt(state.current_idx || 0, 10) || 0;
    var computedScoreProgress = scoreCount ? Math.round((scoreSum / scoreCount) * 10) / 10 : null;
    var scoreProgress = computedScoreProgress == null ? '--' : String(computedScoreProgress) + '%';
    var finalScore = state.score_pct == null ? scoreProgress : String(state.score_pct) + '%';
    var modeLabel = state.resume_mode === 'resumed' && evaluated > 0 ? 'Resumed attempt' : 'New/reset attempt';
    text(els.title, 'Progress Test');
    text(els.attempt, modeLabel + ' · Attempt status: ' + (state.formal_result_label || state.attempt_status || state.status || 'Ready'));
    text(els.score, 'Score: ' + finalScore);
    text(els.ready, total ? String(total) : '0');
    text(els.current, total ? (idx + '/' + total) : '0/0');
    text(els.evaluated, String(evaluated));
    text(els.final, finalScore);
    if (els.bar) {
      var pct = total ? Math.round((evaluated / total) * 100) : 0;
      els.bar.style.width = clamp(pct, 0, 100) + '%';
    }
    currentItem = null;
    items.forEach(function (item) {
      if (parseInt(item.id, 10) === parseInt(state.current_item_id, 10)) currentItem = item;
    });
  }

  function setRecording(active, label) {
    if (!els.recording) return;
    els.recording.textContent = label || (active ? 'Recording in Progress' : 'Not recording yet');
    els.recording.setAttribute('data-recording', active ? '1' : '0');
  }

  function setFinishButton(label, disabled, mode) {
    if (!els.finish) return;
    els.finish.textContent = label;
    els.finish.setAttribute('data-action-mode', mode || 'answer');
    var forceDisabled = !!disabled;
    if ((mode || 'answer') === 'answer' && (label === 'START' || label === 'ANSWER CLARIFICATION') && isMayaSpeaking()) {
      forceDisabled = true;
    }
    els.finish.disabled = forceDisabled;
    syncAnswerButtonState();
  }

  function setStartPulse(active) {
    if (!els.start) return;
    els.start.setAttribute('data-next-action', active ? '1' : '0');
  }

  function courseReturnUrl() {
    var cohortId = parseInt(cfg.cohortId || 0, 10) || 0;
    var lessonId = parseInt(cfg.lessonId || 0, 10) || 0;
    var url = '/student/course.php?cohort_id=' + encodeURIComponent(String(cohortId));
    if (lessonId > 0) url += '#progress-test-lesson-' + encodeURIComponent(String(lessonId));
    return url;
  }

  function leaveCompletedTest() {
    stopRealtime(false);
    window.location.href = courseReturnUrl();
  }

  function setCloseTestMode(label, enabled) {
    if (els.end) {
      els.end.disabled = enabled !== true;
      text(els.end, label || 'Close Test');
    }
  }

  function setFinishPulse(active) {
    if (!els.finish) return;
    els.finish.setAttribute('data-next-action', active ? '1' : '0');
  }

  function setMicEnabled(enabled) {
    if (!localStream) return;
    localStream.getAudioTracks().forEach(function (track) {
      track.enabled = !!enabled && !muted;
    });
  }

  function answerCaptureDurationMs() {
    if (!answerCaptureStartedAt) return 0;
    var endAt = transcriptStopAt || Date.now();
    return Math.max(0, endAt - answerCaptureStartedAt);
  }

  function shouldCommitStudentAudioBuffer() {
    if (!answerCaptureActive && !answerCaptureStartedAt) return false;
    if (answerSegments.length > 0) return true;
    return answerCaptureDurationMs() >= MIN_AUDIO_COMMIT_MS;
  }

  function commitStudentAudioBuffer() {
    if (!shouldCommitStudentAudioBuffer()) return false;
    if (dc && dc.readyState === 'open') {
      try { dc.send(JSON.stringify({ type: 'input_audio_buffer.commit' })); return true; } catch (e) {}
    }
    return false;
  }

  function stopAnswerCaptureMaintenance() {
    if (answerCommitInterval) clearInterval(answerCommitInterval);
    answerCommitInterval = null;
  }

  function startAnswerCaptureMaintenance() {
    stopAnswerCaptureMaintenance();
    answerCommitInterval = setInterval(function () {
      if (!answerCaptureActive || phase !== 'answering') return;
      commitStudentAudioBuffer();
    }, ANSWER_COMMIT_INTERVAL_MS);
  }

  function hasScorableBufferedAnswer() {
    var text = combinedAnswerText('');
    if (!text) return false;
    if (currentItem && String(currentItem.kind || '') === 'yesno' && hasYesNoAnswerToken(text)) return true;
    return !isUnreadableAnswer(text);
  }

  function clearTranscriptSubmitTimers() {
    if (transcriptWaitTimer) clearTimeout(transcriptWaitTimer);
    transcriptWaitTimer = null;
    pendingTranscriptSubmit = false;
    pendingTranscriptKind = '';
    pendingTranscriptSubmitAfterMaya = false;
    transcriptWaitStartedAt = 0;
    transcriptLastSegmentAt = 0;
    transcriptStopAt = 0;
  }

  function startCameraPreview(stream) {
    if (!els.video || !stream || !stream.getVideoTracks || stream.getVideoTracks().length === 0) return;
    try {
      els.video.srcObject = stream;
      if (els.videoFallback) els.videoFallback.hidden = true;
      var p = els.video.play();
      if (p && typeof p.catch === 'function') p.catch(function () {});
    } catch (e) {}
  }

  function startCameraOnlyPreview() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !els.video) {
      if (els.videoFallback) els.videoFallback.textContent = 'Camera unavailable';
      return Promise.resolve(null);
    }
    return navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false }).then(function (stream) {
      cameraStream = stream;
      startCameraPreview(stream);
      return stream;
    }).catch(function () {
      if (els.videoFallback) {
        els.videoFallback.hidden = false;
        els.videoFallback.textContent = 'Camera unavailable';
      }
      return null;
    });
  }

  function setProgressBarPct(pct) {
    if (els.bar) els.bar.style.width = clamp(pct, 0, 100) + '%';
  }

  function startPrepProgress() {
    stopPrepProgress();
    prepPct = 4;
    setProgressBarPct(prepPct);
    text(els.ready, '0');
    text(els.current, '0/0');
    text(els.evaluated, '0');
    text(els.final, '--');
    var messages = [
      'Checking attempt state...',
      'Loading lesson narration...',
      'Generating oral questions...',
      'Checking question quality...',
      'Saving generated questions...',
      'Preparing realtime oral mode...'
    ];
    var messageIdx = 0;
    prepTimer = setInterval(function () {
      prepPct = Math.min(92, prepPct + (prepPct < 45 ? 7 : prepPct < 75 ? 4 : 2));
      setProgressBarPct(prepPct);
      if (messageIdx < messages.length) {
        setStatus(messages[messageIdx], 'Preparing questions');
        messageIdx += 1;
      }
    }, 900);
  }

  function stopPrepProgress() {
    if (prepTimer) clearInterval(prepTimer);
    prepTimer = null;
  }

  function loadQuestions() {
    resetClientConversation();
    phase = 'preparing';
    setCoachState('thinking');
    setStatus('Generating/loading questions. Microphone is closed.', 'Preparing questions');
    startPrepProgress();
    return api('start_oral_test', {
      cohort_id: parseInt(cfg.cohortId || 0, 10),
      lesson_id: parseInt(cfg.lessonId || 0, 10)
    }).then(function (out) {
      stopPrepProgress();
      setProgressBarPct(100);
      updateProgress(out.state);
      setCoachState('ready');
      phase = 'ready';
      setStatus(out.state && out.state.resume_mode === 'resumed' ? 'Resumed existing attempt. Microphone is still closed.' : 'Ready to start a new/reset attempt. Microphone is still closed.', 'Ready to start');
      els.start.disabled = false;
      setStartPulse(true);
      addBubble('maya', 'Maya', (out.state && out.state.resume_mode === 'resumed') ? 'I found an unfinished progress test attempt. Click Start Progress Test to resume from the current question.' : 'Ready to start. I have loaded your generated progress test questions. Click Start Progress Test when you are ready.', 'greeting');
    }).catch(function (err) {
      stopPrepProgress();
      setProgressBarPct(100);
      setCoachState('error');
      setStatus('Could not prepare progress test: ' + err.message, 'Preparation failed');
      addBubble('maya', 'System', 'Could not prepare progress test: ' + err.message, 'warning');
    });
  }

  function ensureRemoteAudio() {
    if (remoteAudio) return remoteAudio;
    remoteAudio = document.createElement('audio');
    remoteAudio.autoplay = true;
    remoteAudio.muted = false;
    remoteAudio.volume = 1;
    remoteAudio.setAttribute('playsinline', 'playsinline');
    remoteAudio.className = 'ptv3-remote-audio';
    document.body.appendChild(remoteAudio);
    return remoteAudio;
  }

  function unlockAudioPlayback() {
    var audio = ensureRemoteAudio();
    audio.muted = false;
    audio.volume = 1;
    try {
      var p = audio.play();
      if (p && typeof p.then === 'function') p.then(function () {
      }).catch(function (err) {
      });
    } catch (e) {
    }
  }

  function connectRealtime(clientSecret, endpoint) {
    pc = new RTCPeerConnection();
    ensureRemoteAudio();
    var dcOpenResolve;
    var dcOpenReject;
    var dcOpen = new Promise(function (resolve, reject) {
      dcOpenResolve = resolve;
      dcOpenReject = reject;
    });
    pc.ontrack = function (event) {
      remoteAudio.srcObject = event.streams[0];
      unlockAudioPlayback();
      setCoachState('thinking');
    };
    pc.onconnectionstatechange = function () {
      if (pc.connectionState === 'failed' || pc.connectionState === 'disconnected') {
        handleDisconnect();
      }
    };
    dc = pc.createDataChannel('oai-events');
    dc.onmessage = handleRealtimeEvent;
    dc.onopen = function () {
      setStatus('Connected. Microphone is closed until you press START.', 'Realtime connected');
      dcOpenResolve();
    };
    dc.onerror = function () {
      dcOpenReject(new Error('Realtime data channel failed to open.'));
      handleDisconnect();
    };
    dc.onclose = function () { if (connected) handleDisconnect(); };

    return navigator.mediaDevices.getUserMedia({ audio: true, video: false }).then(function (stream) {
      localStream = stream;
      setRecording(true, 'Voice session connected');
      setMicEnabled(false);
      stream.getTracks().forEach(function (track) { pc.addTrack(track, stream); });
      return pc.createOffer();
    }).then(function (offer) {
      return pc.setLocalDescription(offer).then(function () { return offer; });
    }).then(function (offer) {
      return fetch(endpoint || 'https://api.openai.com/v1/realtime/calls', {
        method: 'POST',
        headers: {
          Authorization: 'Bearer ' + clientSecret,
          'Content-Type': 'application/sdp'
        },
        body: offer.sdp
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
      return dc.readyState === 'open' ? null : dcOpen;
    });
  }

  function startTest() {
    if (connected || !attemptId) return;
    els.start.disabled = true;
    setStartPulse(false);
    unlockAudioPlayback();
    setCoachState('thinking');
    setStatus('Opening microphone and realtime voice...', 'Connecting');
    setMicEnabled(false);
    startCameraOnlyPreview().then(function () {
      return postJson('/student/api/progress_test_voice_token.php', { attempt_id: attemptId });
    }).then(function (token) {
      return connectRealtime(token.client_secret, token.realtime_endpoint);
    }).then(function () {
      connected = true;
      els.end.disabled = true;
      setFinishButton('START', true, 'answer');
      setCoachState('ready');
      phase = 'readiness';
      startAudioCheck(!!(state && state.resume_mode === 'resumed'));
    }).catch(function (err) {
      els.start.disabled = false;
      setCoachState('error');
      setStatus('Voice failed. Restart the voice session when ready.', 'Voice unavailable');
      addBubble('maya', 'System', 'Voice failed: ' + err.message + '\nThis did not penalize the attempt. Restart voice when ready.', 'warning');
      api('abort_voice_session_without_penalty', {}).catch(function () {});
    });
  }

  function sendSpokenText(textToSpeak, purpose) {
    textToSpeak = String(textToSpeak || '').trim();
    if (!textToSpeak) return;
    setTyping('', false);
    setMicEnabled(false);
    setStudentAnswering(false);
    if (responseInProgress) {
      pendingSpeakText = textToSpeak;
      pendingPurpose = purpose || '';
      return;
    }
    liveMayaBubble = null;
    liveMayaText = '';
    mayaDriftDetected = false;
    mayaTurnPurpose = purpose || '';
    mayaTranscriptDone = false;
    pendingFinishPurpose = '';
    stopMayaTurnTimers();
    clearMayaResponseDedupe();
    responseInProgress = true;
    setMayaSpeaking(true);
    ensureExpectedMayaBubble();
    prepareRealtimeForScriptedSpeech();
    if (!dc || dc.readyState !== 'open') {
      window.setTimeout(function () { drainResponse(); }, estimateMayaSpeechMs(textToSpeak));
      return;
    }
    unlockAudioPlayback();
    dc.send(JSON.stringify({
      type: 'response.create',
      response: {
        conversation: 'none',
        output_modalities: ['audio'],
        instructions: MAYA_RENDERER_INSTRUCTIONS,
        input: [{
          type: 'message',
          role: 'user',
          content: [{ type: 'input_text', text: textToSpeak }]
        }]
      }
    }));
  }

  function speakExact(textToSpeak, purpose) {
    textToSpeak = String(textToSpeak || '').trim();
    if (!textToSpeak) return;
    preparedMayaText = textToSpeak;
    mayaExpectedText = textToSpeak;
    if ((purpose || '') === 'question') mayaQuestionRetryUsed = false;
    if ((purpose || '') === 'retry_answer') mayaRetryAnswerUsed = false;
    if (purpose) {
      mayaEnglishRetryUsed[purpose] = false;
      mayaScriptRetryUsed[purpose] = false;
    }
    sendSpokenText(textToSpeak, purpose || '');
  }

  function speakFinalSummary(textToSpeak) {
    speakExact(String(textToSpeak || 'Progress test complete.').trim(), 'final');
  }

  function drainResponse() {
    if (mayaDriftDetected) {
      responseInProgress = false;
      resumePendingTranscriptSubmit();
      return;
    }
    var finishedPurpose = mayaTurnPurpose;
    mayaTurnPurpose = '';
    responseInProgress = false;
    if (pendingSpeakText) {
      var next = pendingSpeakText;
      var nextPurpose = pendingPurpose;
      pendingSpeakText = '';
      pendingPurpose = '';
      sendSpokenText(next, nextPurpose);
      return;
    }
    scheduleMayaTurnComplete(finishedPurpose);
    resumePendingTranscriptSubmit();
  }

  function finishMayaTurn(finishedPurpose) {
    if ((finishedPurpose === 'question' || finishedPurpose === 'clarification' || finishedPurpose === 'retry_answer') && acceptAnswerAfterMaya) {
      acceptAnswerAfterMaya = false;
      awaitingAnswer = true;
      phase = 'answering';
      setStudentAnswering(false);
      setMicEnabled(false);
      setFinishButton(awaitingClarification ? 'ANSWER CLARIFICATION' : 'START', false, 'answer');
      setFinishPulse(true);
      setCoachState('ready');
      setStatus(awaitingClarification ? 'Tap ANSWER CLARIFICATION and add your follow-up answer.' : 'Tap START when you are ready to speak.', awaitingClarification ? 'Clarification answer' : ('Question ' + currentItem.idx + '/' + state.total_questions));
      answerStopWasQuick = false;
      startAnswerTimerAfterPlayback();
      return;
    }
    if (finishedPurpose === 'feedback' && nextQuestionAfterFeedback) {
      nextQuestionAfterFeedback = false;
      if (pendingNextQuestionAfterFeedback) {
        pendingNextQuestionAfterFeedback = false;
        phase = 'next_ready';
        setFinishButton('Next Question', false, 'next');
        setStatus('Maya finished explaining. Tap Next Question when ready.', 'Ready for next question');
      }
      return;
    }
    if (finishedPurpose === 'feedback' && completeAfterFeedback) {
      completeAfterFeedback = false;
      if (pendingCompleteAfterFeedback) {
        pendingCompleteAfterFeedback = false;
        finalEvaluationReady = true;
        phase = 'final_ready';
        setFinishButton('Test Evaluation', false, 'next');
        setFinishPulse(true);
        setCloseTestMode('Close Test', false);
        setStatus('Maya finished the last question feedback. Tap Test Evaluation for the final result.', 'Ready for final evaluation');
      }
      return;
    }
    if (finishedPurpose === 'final') {
      setStatus('Final evaluation complete. Tap Close Test to return to the course.', 'Complete');
      phase = 'completed';
      setCloseTestMode('Close Test', true);
      return;
    }
    if (finishedPurpose === 'audio_check' && audioCheckActive) {
      awaitingAudioCheck = true;
      phase = 'readiness';
      setStudentAnswering(false);
      setMicEnabled(false);
      setFinishButton('START', false, 'answer');
      setFinishPulse(true);
      setCoachState('ready');
      setStatus('Tap START and say "ready" when you are ready to begin. This is not scored.', 'Readiness check');
      return;
    }
    if (finishedPurpose === 'done_check') {
      awaitingAnswer = true;
      setCoachState('ready');
      setStatus('Say "done" to score, or continue answering.', 'Confirm answer');
      return;
    }
  }

  function handleAnswerTimeout() {
    if (!currentItem || phase !== 'answering' || !awaitingAnswer) return;
    if (answerCaptureActive) {
      stopAnswerTimer();
      return;
    }
    if (hasScorableBufferedAnswer()) {
      stopAnswerTimer();
      setTyping('Transcribing', true, 'student');
      setStatus('Using your captured answer before scoring.', awaitingClarification ? 'Clarification answer' : ('Question ' + currentItem.idx + '/' + state.total_questions));
      if (!transcriptStopAt) beginTranscriptStopCapture();
      scheduleTranscriptSubmit('answer');
      return;
    }
    stopAnswerTimer();
    awaitingAnswer = false;
    awaitingClarification = false;
    resetAnswerBuffer();
    setStudentAnswering(false);
    setMicEnabled(false);
    setFinishButton('Timed out', true, 'wait');
    setCoachState('thinking');
    setStatus('Time expired. Maya is scoring this question as 0%.', 'Timed out');
    phase = 'evaluating';
    api('evaluate_item_timeout', { item_id: currentItem.id }).then(function (out) {
      updateProgress(out.state);
      if (out.recovered_from_timeout) {
        setTyping('', false);
        var recoveredScoreLine = 'You scored ' + Math.round(out.score_pct || 0) + ' percent on this question.';
        var recoveredFeedback = String(out.feedback_for_student || '').replace(/\brubric\b/ig, 'expected answer');
        var recoveredSpeech = recoveredScoreLine + ' ' + recoveredFeedback;
        if (out.next_action === 'complete_test') {
          completeAfterFeedback = true;
          pendingCompleteAfterFeedback = true;
          phase = 'feedback';
          setCloseTestMode('Close Test', false);
          speakExactWhenIdle(recoveredSpeech, 'feedback');
        } else {
          nextQuestionAfterFeedback = true;
          pendingNextQuestionAfterFeedback = true;
          phase = 'feedback';
          speakExactWhenIdle(recoveredSpeech, 'feedback');
        }
        return;
      }
      var firstName = String(cfg.firstName || 'Student').trim() || 'Student';
      var timeoutSpeech = 'Sorry ' + firstName + ', that took you too long to give an answer. You have 0% on this question. Let\'s go to the next question when you are ready.';
      if (out.next_action === 'complete_test') {
        completeAfterFeedback = true;
        pendingCompleteAfterFeedback = true;
        phase = 'feedback';
        setCloseTestMode('Close Test', false);
        speakExactWhenIdle(timeoutSpeech, 'feedback');
      } else {
        nextQuestionAfterFeedback = true;
        pendingNextQuestionAfterFeedback = true;
        phase = 'feedback';
        speakExactWhenIdle(timeoutSpeech, 'feedback');
      }
    }).catch(function (err) {
      phase = 'answering';
      awaitingAnswer = true;
      setCoachState('error');
      setStatus('Timeout scoring failed: ' + err.message, 'Evaluation issue');
      setFinishButton('START', false, 'answer');
      setFinishPulse(true);
      startAnswerTimerAfterPlayback();
      addBubble('maya', 'System', 'Timeout scoring failed: ' + err.message, 'warning');
    });
  }

  function startAudioCheck(isResume) {
    audioCheckActive = true;
    phase = 'readiness';
    awaitingAudioCheck = false;
    awaitingAnswer = false;
    acceptAnswerAfterMaya = false;
    clarificationMode = '';
    activeStudentBubble = null;
    setStudentAnswering(false);
    stopAnswerTimer();
    var firstName = String(cfg.firstName || 'Student').trim() || 'Student';
    var readyPrompt = isResume
      ? 'Welcome back ' + firstName + ', are you ready to resume your progress test?'
      : 'Hello ' + firstName + ', are you ready for your progress test?';
    setStatus('Maya is confirming you are ready. This is not scored.', 'Readiness check');
    speakExact(readyPrompt, 'audio_check');
  }

  function audioCheckConfirmsReady(transcript) {
    var t = String(transcript || '').toLowerCase();
    return /\b(ready|i am ready|i'm ready|i can hear you|can hear you|i hear you|heard you|yes i can hear)\b/.test(t);
  }

  function isSetupOrHoldSpeech(transcript) {
    var t = String(transcript || '').toLowerCase();
    return /\b(can you hear me|do you hear me|wait|hold on|not ready|i am not ready|i'm not ready|haven't asked|have not asked|you didn't ask|you have not asked|you haven't asked|repeat|say that again|i can't hear|i cannot hear|no audio|audio is not working)\b/.test(t);
  }

  function isDoneSpeech(transcript) {
    var t = String(transcript || '').toLowerCase().replace(/[^\w\s']/g, ' ');
    return /\b(done|finished|complete|that's all|that is all|i'm done|i am done|submit|score it|go ahead)\b/.test(t);
  }

  function isContinueSpeech(transcript) {
    var t = String(transcript || '').toLowerCase().replace(/[^\w\s']/g, ' ');
    return /\b(not done|not finished|wait|hold on|one more thing|continue|still answering|i'm still answering|i am still answering|let me finish|more to add)\b/.test(t);
  }

  function isCourtesyOnlySpeech(transcript) {
    var t = String(transcript || '').toLowerCase().replace(/[^\w\s']/g, ' ').trim();
    return /^(ok|okay|thanks|thank you|ok thank you|okay thank you|all right|alright|got it|understood|yes thanks|yes thank you)$/.test(t);
  }

  function resetAnswerBuffer() {
    answerSegments = [];
    awaitingDoneConfirmation = false;
    doneConfirmationText = '';
    donePromptShownForAnswer = '';
    if (answerSettleTimer) clearTimeout(answerSettleTimer);
    answerSettleTimer = null;
    if (transcriptWaitTimer) clearTimeout(transcriptWaitTimer);
    transcriptWaitTimer = null;
    pendingTranscriptSubmit = false;
    pendingTranscriptKind = '';
    pendingTranscriptSubmitAfterMaya = false;
    transcriptWaitStartedAt = 0;
    transcriptLastSegmentAt = 0;
    transcriptStopAt = 0;
    answerCaptureStartedAt = 0;
    stopAnswerCaptureMaintenance();
    transcriptFlushUntil = 0;
    setTyping('', false);
  }

  function beginTranscriptStopCapture() {
    transcriptStopAt = Date.now();
    transcriptLastSegmentAt = 0;
  }

  function combinedAnswerText(extra) {
    var parts = answerSegments.slice();
    if (extra && String(extra).trim() !== '') parts.push(String(extra).trim());
    return parts.join(' ').replace(/\s+/g, ' ').trim();
  }

  function hasPostStopTranscriptSegment() {
    return transcriptStopAt > 0 && transcriptLastSegmentAt >= transcriptStopAt;
  }

  function postStopMaxWaitMs() {
    if (!transcriptStopAt) return transcriptMaxWaitMs;
    var captureMs = answerCaptureStartedAt ? Math.max(0, transcriptStopAt - answerCaptureStartedAt) : 0;
    if (captureMs < 3500 && answerSegments.length <= 1) return 2500;
    return transcriptMaxWaitMs;
  }

  function awaitingPostStopTranscript(elapsed) {
    if (answerStopWasQuick) return false;
    if (transcriptStopAt <= 0 || hasPostStopTranscriptSegment()) return false;
    return (elapsed || 0) < postStopMaxWaitMs();
  }

  function transcriptQuietRemainMs() {
    if (transcriptStopAt > 0 && !hasPostStopTranscriptSegment()) return transcriptQuietMs;
    if (!transcriptLastSegmentAt) return transcriptQuietMs;
    if (transcriptStopAt > 0 && transcriptLastSegmentAt < transcriptStopAt) return transcriptQuietMs;
    return Math.max(0, transcriptQuietMs - (Date.now() - transcriptLastSegmentAt));
  }

  function scheduleAnswerSettle() {
    if (answerSettleTimer) clearTimeout(answerSettleTimer);
    answerSettleTimer = setTimeout(function () {
      answerSettleTimer = null;
      setStatus('Click STOP when you are finished.', 'Answer captured');
    }, answerSettleMs);
    setStatus('Recording your answer. Click STOP when finished.', awaitingClarification ? 'Clarification answer' : ('Question ' + currentItem.idx + '/' + state.total_questions));
  }

  function handleAudioCheckTranscript(transcript) {
    setStudentAnswering(false);
    setMicEnabled(false);
    transcriptFlushUntil = 0;
    setTyping('', false);
    setFinishButton('Checking...', true, 'wait');
    if (audioCheckConfirmsReady(transcript)) {
      audioCheckActive = false;
      awaitingAudioCheck = false;
      setFinishPulse(false);
      phase = 'asking';
      askCurrentQuestion();
      return;
    }
    setStatus('Waiting for readiness confirmation. Say "ready" when you want to begin.', 'Readiness check');
    speakExact('I heard you, but I will not start the scored test until you say ready.', 'audio_check');
  }

  function retryCurrentAnswer(message) {
    if (!currentItem) return;
    var itemId = parseInt(currentItem.id, 10);
    if (answerRetryOfferedForItem === itemId) return;
    if (phase === 'retrying' && (responseInProgress || mayaTurnPurpose === 'retry_answer')) return;
    answerRetryOfferedForItem = itemId;
    clearTranscriptSubmitTimers();
    transcriptFlushUntil = 0;
    answerStopWasQuick = false;
    stopMayaSpeechForNewScript();
    resetAnswerBuffer();
    awaitingAnswer = false;
    acceptAnswerAfterMaya = true;
    activeStudentBubble = null;
    setStudentAnswering(false);
    setMicEnabled(false);
    setFinishButton(awaitingClarification ? 'ANSWER CLARIFICATION' : 'START', true, 'answer');
    setFinishPulse(false);
    phase = 'retrying';
    setStatus('Maya is giving you another chance to answer.', awaitingClarification ? 'Clarification answer' : ('Question ' + currentItem.idx + '/' + state.total_questions));
    speakExactWhenIdle(message, 'retry_answer');
  }

  function setClarificationTurn(answer, prompt, mode) {
    nextQuestionAfterFeedback = false;
    pendingNextQuestionAfterFeedback = false;
    completeAfterFeedback = false;
    pendingCompleteAfterFeedback = false;
    finalEvaluationReady = false;
    awaitingAnswer = false;
    acceptAnswerAfterMaya = true;
    awaitingClarification = true;
    clarificationMode = mode || 'weak';
    originalAnswer = answer;
    clarificationQuestion = prompt || 'Can you clarify this part of your answer?';
    activeStudentBubble = null;
    setStatus(clarificationMode === 'transcript_ambiguity' ? 'Maya is checking a possible transcription issue.' : 'Maya is asking one clarification.', 'Clarification');
    speakExactWhenIdle(clarificationQuestion, 'clarification');
  }

  function captureStudentTranscript(transcript, kind) {
    answerSegments.push(transcript);
    transcriptLastSegmentAt = Date.now();
    stopAnswerTimer();
    if (activeStudentBubble && activeStudentBubble.body) {
      activeStudentBubble.body.textContent = combinedAnswerText('');
    } else {
      activeStudentBubble = addBubble('student', 'Student', combinedAnswerText(''), kind || 'answer');
    }
    if (pendingTranscriptSubmit) scheduleTranscriptSubmit(pendingTranscriptKind);
  }

  function transcriptSubmitDelayMs(hasText, elapsed) {
    if (awaitingPostStopTranscript(elapsed)) return Math.min(400, Math.max(0, postStopMaxWaitMs() - elapsed));
    if (!hasText) return elapsed >= transcriptMaxWaitMs ? 0 : 500;
    var quietRemain = transcriptQuietRemainMs();
    if (quietRemain > 0 && elapsed < transcriptMaxWaitMs) return Math.min(quietRemain, 400);
    return 0;
  }

  function finalizeTranscriptSubmit(kind) {
    var elapsed = Date.now() - transcriptWaitStartedAt;
    var bufferText = combinedAnswerText('');
    var hasText = bufferText !== '';
    var quietRemain = transcriptQuietRemainMs();
    var maxWaitMs = answerStopWasQuick ? 1200 : transcriptMaxWaitMs;

    if (awaitingPostStopTranscript(elapsed)) {
      scheduleTranscriptSubmit(kind);
      return;
    }
    if (!hasText && elapsed < maxWaitMs) {
      scheduleTranscriptSubmit(kind);
      return;
    }
    if (hasText && quietRemain > 0 && elapsed < maxWaitMs) {
      scheduleTranscriptSubmit(kind);
      return;
    }

    if (kind === 'readiness') {
      clearTranscriptSubmitTimers();
      transcriptFlushUntil = 0;
      setTyping('', false);
      handleAudioCheckTranscript(bufferText);
      return;
    }

    if (!hasText) {
      clearTranscriptSubmitTimers();
      transcriptFlushUntil = 0;
      setTyping('', false);
      if (!transcriptStopAt) {
        if (phase === 'answering') {
          awaitingAnswer = true;
          setFinishButton(awaitingClarification ? 'ANSWER CLARIFICATION' : 'START', false, 'answer');
          setFinishPulse(true);
          startAnswerTimerAfterPlayback();
        }
        return;
      }
      var firstName = String(cfg.firstName || 'Student').trim() || 'Student';
      retryCurrentAnswer('I am sorry ' + firstName + ', I did not hear you well. Let me give you another chance to answer properly or check your audio connection.');
      return;
    }

    if (responseInProgress) {
      pendingTranscriptSubmitAfterMaya = true;
      setStatus('Finishing transcription, then evaluating your answer.', awaitingClarification ? 'Clarification answer' : ('Question ' + (currentItem ? currentItem.idx : '') + '/' + (state ? state.total_questions : '')));
      if (transcriptWaitTimer) clearTimeout(transcriptWaitTimer);
      transcriptWaitTimer = setTimeout(function () {
        transcriptWaitTimer = null;
        finalizeTranscriptSubmit(kind);
      }, 300);
      return;
    }

    pendingTranscriptSubmit = false;
    pendingTranscriptKind = '';
    pendingTranscriptSubmitAfterMaya = false;
    transcriptWaitStartedAt = 0;
    transcriptStopAt = 0;
    setTyping('Maya is thinking', true, 'maya');
    submitCurrentBufferedAnswer();
  }

  function scheduleTranscriptSubmit(kind) {
    pendingTranscriptSubmit = true;
    pendingTranscriptKind = kind || 'answer';
    if (!transcriptWaitStartedAt) transcriptWaitStartedAt = Date.now();
    if (transcriptWaitTimer) clearTimeout(transcriptWaitTimer);
    var elapsed = Date.now() - transcriptWaitStartedAt;
    var hasText = combinedAnswerText('') !== '';
    var delay = transcriptSubmitDelayMs(hasText, elapsed);
    transcriptWaitTimer = setTimeout(function () {
      transcriptWaitTimer = null;
      finalizeTranscriptSubmit(kind);
    }, delay);
  }

  function resumePendingTranscriptSubmit() {
    if (!pendingTranscriptSubmitAfterMaya) return;
    if (responseInProgress) return;
    if (phase !== 'answering' && phase !== 'readiness') {
      pendingTranscriptSubmitAfterMaya = false;
      return;
    }
    var kind = pendingTranscriptKind || 'answer';
    if (!combinedAnswerText('')) {
      pendingTranscriptSubmitAfterMaya = false;
      return;
    }
    pendingTranscriptSubmit = true;
    finalizeTranscriptSubmit(kind);
  }

  function isMayaEchoTranscript(transcript) {
    var t = String(transcript || '').toLowerCase().replace(/\s+/g, ' ').trim();
    return t.indexOf('i heard your answer') !== -1
      || t.indexOf('are you finished') !== -1
      || t.indexOf('say done to score') !== -1
      || t.indexOf('do you want to add more') !== -1
      || t.indexOf('i heard you, but i will not start') !== -1
      || t.indexOf('hello ' + String(cfg.firstName || '').toLowerCase()) === 0
      || t.indexOf('welcome back ' + String(cfg.firstName || '').toLowerCase()) === 0
      || t.indexOf('are you ready for your progress test') !== -1
      || t.indexOf('are you ready to resume your progress test') !== -1;
  }

  function askCurrentQuestion() {
    if (!currentItem || !currentItem.prompt) {
      setCoachState('error');
      setStatus('No lesson-grounded progress test question is available. Please reload to regenerate.', 'Question unavailable');
      addBubble('maya', 'System', 'No lesson-grounded progress test question is available. This attempt was not scored.', 'warning');
      return;
    }
    phase = 'asking';
    stopAnswerTimer();
    resetAnswerBuffer();
    awaitingAnswer = false;
    acceptAnswerAfterMaya = true;
    awaitingClarification = false;
    clarificationMode = '';
    originalAnswer = '';
    clarificationQuestion = '';
    activeStudentBubble = null;
    activeAnswerItemId = 0;
    setStudentAnswering(false);
    setMicEnabled(false);
    setFinishButton('START', true, 'answer');
    setFinishPulse(false);
    setStatus('Maya is asking question ' + currentItem.idx + '.', 'Question ' + currentItem.idx + '/' + state.total_questions);
    api('save_transcript_segment', {
      item_id: currentItem.id,
      role: 'maya',
      event_type: 'question',
      transcript_text: currentItem.spoken_question || currentItem.prompt
    }).catch(function () {});
    stopMayaSpeechForNewScript();
    speakExactWhenIdle(currentItem.spoken_question || currentItem.prompt, 'question');
  }

  function handleRealtimeEvent(event) {
    var msg = null;
    try { msg = JSON.parse(event.data); } catch (e) { return; }
    var type = String(msg.type || '');
    if (type === 'error') {
      if (isBenignRealtimeError(msg)) return;
      console.warn('Progress test realtime error', msg);
      if (connected && !responseInProgress) {
        setStatus('Voice connection notice. Continue if audio is working.', 'Connection notice');
      }
      return;
    }
    if (type === 'response.created' && !mayaTurnPurpose) {
      if (dc && dc.readyState === 'open') {
        try { dc.send(JSON.stringify({ type: 'response.cancel' })); } catch (e) {}
      }
      return;
    }
    if (type === 'response.created') {
      responseInProgress = true;
      setMayaSpeaking(true);
      stopAnswerTimer();
      scheduleMayaAudioEnd(mayaExpectedText || preparedMayaText || '', true);
      ensureExpectedMayaBubble();
      return;
    }
    if (type === 'response.output_audio_transcript.done' && msg.transcript && (mayaTurnPurpose || pendingFinishPurpose)) {
      var transcriptKey = mayaResponseEventKey(msg) || String(msg.transcript || '').slice(0, 220);
      if (transcriptKey && handledMayaTranscriptDoneKeys[transcriptKey]) return;
      if (transcriptKey) handledMayaTranscriptDoneKeys[transcriptKey] = 1;
      var canonicalMayaText = mayaExpectedText || preparedMayaText || '';
      ensureExpectedMayaBubble();
      onMayaTranscriptDone(canonicalMayaText);
      api('save_transcript_segment', {
        item_id: currentItem ? currentItem.id : 0,
        role: 'maya',
        event_type: 'audio_transcript',
        transcript_text: canonicalMayaText || String(msg.transcript || '')
      }).catch(function () {});
      return;
    }
    if ((type === 'response.output_audio_transcript.delta' || type === 'response.audio_transcript.delta') && (msg.delta || msg.text) && (mayaTurnPurpose || pendingFinishPurpose)) {
      ensureExpectedMayaBubble();
      return;
    }
    if (type === 'conversation.item.input_audio_transcription.completed' && msg.transcript) {
      handleStudentTranscript(msg);
    }
    if (type === 'response.done' || type === 'response.failed' || type === 'response.cancelled' || type === 'response.canceled') {
      var doneKey = mayaResponseEventKey(msg);
      if (doneKey && handledMayaResponseDoneKeys[doneKey]) return;
      if (doneKey) handledMayaResponseDoneKeys[doneKey] = 1;
      drainResponse();
    }
  }

  function transcriptKey(msg) {
    return String(msg.event_id || msg.item_id || msg.response_id || msg.transcript || '').slice(0, 260);
  }

  function handleStudentTranscript(msg) {
    var transcript = String(msg.transcript || '').trim();
    if (!transcript) return;
    if (Date.now() < ignoreTranscriptsUntil) return;
    if (isMayaEchoTranscript(transcript)) return;
    var key = transcriptKey(msg);
    if (processedTranscripts[key]) return;
    processedTranscripts[key] = true;
    if ((audioCheckActive || awaitingAudioCheck) && phase === 'readiness' && (answerCaptureActive || Date.now() < transcriptFlushUntil) && (!responseInProgress || Date.now() < transcriptFlushUntil)) {
      captureStudentTranscript(transcript, 'readiness_answer');
      api('save_transcript_segment', {
        item_id: currentItem ? currentItem.id : 0,
        role: 'student',
        event_type: 'readiness_answer',
        transcript_text: transcript
      }).catch(function () {});
      return;
    }
    if (phase !== 'answering' || !(answerCaptureActive || Date.now() < transcriptFlushUntil)) return;
    if (!awaitingAnswer || !currentItem) return;
    if (responseInProgress && !answerCaptureActive && !(Date.now() < transcriptFlushUntil)) return;
    if (isCourtesyOnlySpeech(transcript)) {
      if (awaitingClarification) {
        setStatus('That sounded like an acknowledgement. Tap ANSWER CLARIFICATION and add your clarification.', 'Clarification answer');
      } else {
        setStatus('That sounded like an acknowledgement. Use the available button for the next step.', 'Question ' + currentItem.idx + '/' + state.total_questions);
      }
      return;
    }
    if (isSetupOrHoldSpeech(transcript) && answerCaptureActive) {
      stopAnswerTimer();
      var firstName = String(cfg.firstName || 'Student').trim() || 'Student';
      retryCurrentAnswer('I am sorry ' + firstName + ', I did not hear you well. Let me give you another chance to answer properly or check your audio connection.');
      return;
    }
    if (awaitingDoneConfirmation) {
      if (isDoneSpeech(transcript)) {
        finishCurrentAnswer();
        return;
      }
      if (isContinueSpeech(transcript)) {
        awaitingDoneConfirmation = false;
        donePromptShownForAnswer = '';
        ignoreTranscriptsUntil = 0;
        setStatus('Keep going. Click STOP when finished.', awaitingClarification ? 'Clarification answer' : ('Question ' + currentItem.idx + '/' + state.total_questions));
        scheduleAnswerSettle();
        return;
      }
      awaitingDoneConfirmation = false;
      donePromptShownForAnswer = '';
      ignoreTranscriptsUntil = 0;
    }
    if (activeAnswerItemId !== parseInt(currentItem.id, 10)) {
      activeStudentBubble = null;
      activeAnswerItemId = parseInt(currentItem.id, 10);
    }
    captureStudentTranscript(transcript, 'answer');
    setFinishButton('STOP', false, 'recording');
    api('save_transcript_segment', {
      item_id: currentItem.id,
      role: 'student',
      event_type: awaitingClarification ? 'clarification_answer' : 'answer',
      transcript_text: transcript
    }).catch(function () {});
    scheduleAnswerSettle();
  }

  function finishCurrentAnswer() {
    if (els.finish && els.finish.getAttribute('data-action-mode') === 'next') {
      if (phase === 'final_ready' && finalEvaluationReady) {
        finalEvaluationReady = false;
        clearRealtimeTurnState();
        setFinishPulse(false);
        setFinishButton('Preparing Evaluation...', true, 'wait');
        phase = 'completing';
        completeTest();
        return;
      }
      if (phase !== 'next_ready') return;
      stopAnswerTimer();
      stopMayaSpeechForNewScript();
      phase = 'asking';
      setFinishButton('START', true, 'answer');
      askCurrentQuestion();
      return;
    }
    if (phase === 'readiness' && !answerCaptureActive && awaitingAudioCheck && !responseInProgress) {
      setStudentAnswering(true);
      setMicEnabled(true);
      setFinishButton('STOP', false, 'recording');
      setFinishPulse(false);
      playBeep('start');
      setStatus('Listening for readiness. Say "ready", then click STOP.', 'Readiness check');
      return;
    }
    if (phase === 'readiness' && answerCaptureActive) {
      beginTranscriptStopCapture();
      if (shouldCommitStudentAudioBuffer()) commitStudentAudioBuffer();
      else answerStopWasQuick = true;
      setStudentAnswering(false);
      setMicEnabled(false);
      transcriptFlushUntil = Date.now() + transcriptMaxWaitMs;
      playBeep('stop');
      setFinishButton('Checking...', true, 'wait');
      setTyping('Transcribing', true, 'student');
      setStatus('Transcribing your readiness response...', 'Readiness check');
      if (submitAfterFlushTimer) clearTimeout(submitAfterFlushTimer);
      scheduleTranscriptSubmit('readiness');
      return;
    }
    if ((phase === 'answering' || phase === 'retrying') && !answerCaptureActive) {
      if (responseInProgress || isMayaAudioPlaybackActive()) return;
      if (phase === 'retrying') phase = 'answering';
      answerRetryOfferedForItem = 0;
      answerStopWasQuick = false;
      stopAnswerTimer();
      answerCaptureStartedAt = Date.now();
      setStudentAnswering(true);
      setMicEnabled(true);
      setFinishButton('STOP', false, 'recording');
      setFinishPulse(false);
      playBeep('start');
      startAnswerCaptureMaintenance();
      setStatus(awaitingClarification ? 'Recording your clarification answer. Click STOP when finished.' : 'Recording your answer. Click STOP when finished.', awaitingClarification ? 'Clarification answer' : ('Question ' + currentItem.idx + '/' + state.total_questions));
      return;
    }
    if (phase === 'answering' && answerCaptureActive) {
      stopAnswerTimer();
      stopAnswerCaptureMaintenance();
      beginTranscriptStopCapture();
      answerStopWasQuick = !shouldCommitStudentAudioBuffer();
      if (!answerStopWasQuick) commitStudentAudioBuffer();
      setStudentAnswering(false);
      setMicEnabled(false);
      transcriptFlushUntil = Date.now() + transcriptMaxWaitMs;
      playBeep('stop');
      setFinishButton('Transcribing...', true, 'wait');
      setTyping('Transcribing', true, 'student');
      setStatus('Transcribing your answer. Waiting for the full transcript before scoring.', awaitingClarification ? 'Clarification answer' : ('Question ' + currentItem.idx + '/' + state.total_questions));
      if (submitAfterFlushTimer) clearTimeout(submitAfterFlushTimer);
      scheduleTranscriptSubmit('answer');
      return;
    }
  }

  function submitCurrentBufferedAnswer() {
    var finalAnswer = combinedAnswerText('');
    if (phase !== 'answering' || !currentItem) {
      setTyping('', false);
      if (!finalAnswer) handleUnreadableAnswerRetry();
      return;
    }
    if (responseInProgress) {
      if (finalAnswer) {
        pendingTranscriptSubmitAfterMaya = true;
        pendingTranscriptKind = 'answer';
        return;
      }
      setTyping('', false);
      handleUnreadableAnswerRetry();
      return;
    }
    if (!finalAnswer) {
      setTyping('', false);
      handleUnreadableAnswerRetry();
      return;
    }
    if (isUnreadableAnswer(finalAnswer)) {
      setTyping('', false);
      handleUnreadableAnswerRetry();
      return;
    }
    clearTranscriptSubmitTimers();
    transcriptFlushUntil = 0;
    if (answerSettleTimer) clearTimeout(answerSettleTimer);
    answerSettleTimer = null;
    awaitingDoneConfirmation = false;
    ignoreTranscriptsUntil = 0;
    setFinishButton('Maya Explaining...', true, 'wait');
    phase = 'evaluating';
    if (activeStudentBubble && activeStudentBubble.body) activeStudentBubble.body.textContent = finalAnswer;
    evaluateBufferedAnswer(finalAnswer);
  }

  function hasYesNoAnswerToken(answer) {
    var t = String(answer || '').toLowerCase().replace(/[^\w\s']/g, ' ').replace(/\s+/g, ' ').trim();
    return /\b(yes|yeah|yep|no|nope|true|false|correct|incorrect)\b/.test(t);
  }

  function isUnreadableAnswer(answer) {
    var t = String(answer || '').toLowerCase().replace(/[^\w\s']/g, ' ').replace(/\s+/g, ' ').trim();
    if (!t) return true;
    if (currentItem && String(currentItem.kind || '') === 'yesno' && hasYesNoAnswerToken(answer)) return false;
    var words = t.split(' ').filter(Boolean);
    if (words.length < 3) return true;
    if (/^(um|uh|ah|hmm|noise|inaudible|unintelligible|silence|static|you|the|a|i)+$/i.test(t.replace(/\s+/g, ''))) return true;
    if (/\b(inaudible|unintelligible|silence|static|background noise|no audio)\b/.test(t)) return true;
    return false;
  }

  function handleUnreadableAnswerRetry() {
    if (!currentItem) return;
    var firstName = String(cfg.firstName || 'Student').trim() || 'Student';
    var message = 'I am sorry ' + firstName + ', I did not hear you well. Let me give you another chance to answer properly or check your audio connection.';
    retryCurrentAnswer(message);
  }

  function evaluateBufferedAnswer(finalAnswer) {
    if (awaitingClarification) {
      evaluateAnswer(originalAnswer, clarificationQuestion, finalAnswer, clarificationMode);
    } else {
      evaluateAnswer(finalAnswer, '', '', '');
    }
  }

  function evaluateAnswer(answer, clarificationQ, clarificationA, clarificationModeValue) {
    stopAnswerTimer();
    resetAnswerBuffer();
    setTyping('Maya is thinking', true, 'maya');
    awaitingAnswer = false;
    setCoachState('thinking');
    setStatus('Backend is evaluating the answer...', 'Evaluating');
    api('evaluate_item_answer', {
      item_id: currentItem.id,
      student_answer_text: answer,
      clarification_question_text: clarificationQ,
      clarification_answer_text: clarificationA,
      clarification_mode: clarificationModeValue || ''
    }).then(function (out) {
      setTyping('', false);
      updateProgress(out.state);
      if (out.next_action === 'clarify') {
        setClarificationTurn(answer, out.feedback_for_student || 'Good start, but that is not complete yet. Say a little more.', out.clarification_mode || 'weak');
        return;
      }
      clarificationMode = '';

      var scoreLine = 'You scored ' + Math.round(out.score_pct || 0) + ' percent on this question.';
      var feedbackForStudent = String(out.feedback_for_student || '').replace(/\brubric\b/ig, 'expected answer');
      var exactFeedbackText = scoreLine + ' ' + feedbackForStudent;

      if (out.next_action === 'complete_test') {
        completeAfterFeedback = true;
        pendingCompleteAfterFeedback = true;
        phase = 'feedback';
        setCloseTestMode('Close Test', false);
        speakExactWhenIdle(exactFeedbackText, 'feedback');
      } else {
        nextQuestionAfterFeedback = true;
        pendingNextQuestionAfterFeedback = true;
        phase = 'feedback';
        speakExactWhenIdle(exactFeedbackText, 'feedback');
        if (feedbackPlaybackTimer) clearTimeout(feedbackPlaybackTimer);
        feedbackPlaybackTimer = null;
      }
    }).catch(function (err) {
      setTyping('', false);
      awaitingAnswer = true;
      phase = 'answering';
      setCoachState('error');
      setStatus('Evaluation failed. Please retry by voice.', 'Evaluation issue');
      setFinishButton('START', false, 'answer');
      addBubble('maya', 'System', 'Evaluation failed: ' + err.message, 'warning');
    });
  }

  function completeTest() {
    setCloseTestMode('Close Test', false);
    setCoachState('thinking');
    setStatus('Completing test through canonical attempt state...', 'Completing');
    api('complete_test', {}).then(function (out) {
      updateProgress(out.state);
      setStatus('Maya is reading your final evaluation.', 'Final review');
      phase = 'final_playing';
      setCloseTestMode('Close Test', false);
      setCoachState('ready');
      clearRealtimeTurnState();
      speakFinalSummary(out.summary || 'Progress test complete.');
    }).catch(function (err) {
      setCoachState('error');
      setStatus('Completion failed: ' + err.message, 'Completion issue');
      addBubble('maya', 'System', 'Completion failed: ' + err.message, 'warning');
    });
  }

  function stopRealtime(notifyBackend, action) {
    clearMayaIdlePoll();
    stopMayaTurnTimers();
    pendingFinishPurpose = '';
    mayaTranscriptDone = false;
    stopAnswerTimer();
    connected = false;
    if (localStream) {
      localStream.getTracks().forEach(function (track) { track.stop(); });
      localStream = null;
    }
    if (cameraStream) {
      cameraStream.getTracks().forEach(function (track) { track.stop(); });
      cameraStream = null;
    }
    if (els.video) els.video.srcObject = null;
    if (els.videoFallback) els.videoFallback.hidden = false;
    setRecording(false, notifyBackend ? 'Voice ended' : 'Recording stopped');
    if (dc) {
      try { dc.close(); } catch (e) {}
      dc = null;
    }
    if (pc) {
      try { pc.close(); } catch (e) {}
      pc = null;
    }
    if (notifyBackend) api(action || 'abort_voice_session_without_penalty', {}).then(function (out) {
      if (out && out.state) updateProgress(out.state);
    }).catch(function () {});
  }

  function handleDisconnect() {
    if (!connected) return;
    stopRealtime(true);
    setCoachState('error');
    setStatus('Voice disconnected. Restart voice when ready.', 'Voice disconnected');
    addBubble('maya', 'System', 'Voice disconnected. Your attempt was not failed; restart voice when ready.', 'warning');
    els.start.disabled = false;
    setStartPulse(true);
    els.end.disabled = true;
  }

  els.start.addEventListener('click', startTest);
  els.finish.addEventListener('click', finishCurrentAnswer);
  els.end.addEventListener('click', function () {
    if (phase === 'completed') {
      leaveCompletedTest();
    }
  });

  loadQuestions();
})();
