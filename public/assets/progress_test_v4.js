(function () {
  'use strict';

  var cfg = window.IPCAProgressTestV4Config || {};
  var CARD = { READY: 'ready', ASKING: 'asking', LISTENING: 'listening', EVALUATING: 'evaluating', CLARIFICATION: 'clarification', COMPLETE: 'complete' };
  var START_ANSWER_MS = 30000;
  var RECORDING_MS = 45000;
  var CHUNK_MS = 1500;
  var TRANSCRIPT = {
    idle: 'Your spoken answer will appear here after you stop recording.',
    recording: 'Recording your answer…',
    listening: 'Listening carefully…',
    processing: 'Processing your answer…',
    failed: 'We could not clearly transcribe your answer. Please try again.',
    noAudio: 'Audio was not received. Please try again.',
    uploadFailed: 'Audio upload failed. Please try again.'
  };
  var DEBUG_PTV4 = true;

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
    prestart: $('[data-ptv4-prestart]'),
    lessonMenu: $('[data-ptv4-lesson-menu]'),
    lessonMenuLink: $('[data-ptv4-lesson-menu-link]'),
    greeting: $('[data-ptv4-greeting]'),
    greetingCopy: $('[data-ptv4-greeting-copy]'),
    card: $('[data-ptv4-card]'),
    statePill: $('[data-ptv4-state-pill]'),
    qnum: $('[data-ptv4-qnum]'),
    question: $('[data-ptv4-question]'),
    transcript: $('[data-ptv4-transcript]'),
    feedback: $('[data-ptv4-feedback]'),
    hint: $('[data-ptv4-hint]'),
    recordHint: $('[data-ptv4-record-hint]'),
    timer: $('[data-ptv4-timer]'),
    timerFill: $('[data-ptv4-timer-fill]'),
    timerLabel: $('[data-ptv4-timer-label]'),
    timerNote: $('[data-ptv4-timer-note]'),
    mayaStatus: $('[data-ptv4-maya-status]'),
    studentStatus: $('[data-ptv4-student-status]'),
    video: $('[data-ptv4-video]'),
    videoFallback: $('[data-ptv4-video-fallback]'),
    startTest: $('[data-ptv4-start-test]'),
    beginTest: $('[data-ptv4-begin-test]'),
    startAnswer: $('[data-ptv4-start-answer]'),
    stopAnswer: $('[data-ptv4-stop-answer]'),
    replay: $('[data-ptv4-replay]'),
    clarify: $('[data-ptv4-clarify]'),
    next: $('[data-ptv4-next]'),
    retry: $('[data-ptv4-retry]'),
    end: $('[data-ptv4-end]')
  };

  var state = null;
  var attemptId = 0;
  var currentItem = null;
  var cardState = CARD.READY;
  var testStarted = false;
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

  function text(el, v) { if (el) el.textContent = v; }
  function clamp(n, a, b) { return Math.max(a, Math.min(b, n)); }
  function setStatus(msg) { text(els.status, msg); }

  function dbg() {
    if (!DEBUG_PTV4) return;
    var args = ['[PTV4]'].concat([].slice.call(arguments));
    console.log.apply(console, args);
  }

  function setTranscriptDisplay(mode, customText) {
    if (!els.transcript) return;
    var msg = customText || TRANSCRIPT[mode] || TRANSCRIPT.idle;
    text(els.transcript, msg);
    els.transcript.setAttribute('data-state', mode === 'final' ? 'final' : (mode === 'failed' ? 'failed' : 'status'));
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

  function isAttemptClosed(s) {
    if (!s) return false;
    var st = String(s.status || '');
    return st === 'completed' || st === 'failed';
  }

  function syncLessonMenu() {
    if (!els.lessonMenu) return;
    var url = courseReturnUrl();
    if (els.lessonMenuLink) els.lessonMenuLink.setAttribute('href', url);

    var closed = isAttemptClosed(state);
    var show = !testStarted || closed;

    els.lessonMenu.hidden = !show;

    if (els.lessonMenuLink) {
      if (closed) {
        els.lessonMenuLink.classList.add('primary');
        els.lessonMenuLink.classList.remove('ptv4-btn-outline');
      } else {
        els.lessonMenuLink.classList.remove('primary');
        els.lessonMenuLink.classList.add('ptv4-btn-outline');
      }
    }

    if (els.prestart) {
      els.prestart.hidden = closed && !testStarted;
    }
  }

  function hideRetry() {
    if (els.retry) {
      els.retry.hidden = true;
      els.retry.dataset.retryKind = '';
    }
  }

  function showRetry(kind, message) {
    if (els.feedback) {
      els.feedback.hidden = false;
      els.feedback.textContent = message;
    }
    if (els.retry) {
      els.retry.hidden = false;
      els.retry.dataset.retryKind = kind;
      els.retry.textContent = kind === 'evaluation' ? 'Retry Evaluation' : (kind === 'upload' ? 'Retry Upload' : 'Retry Answer');
    }
    syncButtons();
  }

  function setAvatarVisuals() {
    var mayaSpeaking = cardState === CARD.ASKING || (greetingReady && !oralQuestionsStarted);
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
    var labels = {
      ready: testStarted ? 'In Progress' : 'Ready',
      asking: 'In Progress',
      listening: 'In Progress',
      evaluating: 'Evaluating',
      clarification: 'Clarification',
      complete: 'Complete'
    };
    text(els.statePill, labels[next] || next);
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

  function syncButtons() {
    var prepared = state && state.prepared;
    var allDone = state && state.evaluated_count >= state.total_questions;
    var retryVisible = els.retry && !els.retry.hidden;
    if (els.startTest) els.startTest.disabled = !prepared || testStarted || allDone;
    if (els.beginTest) {
      els.beginTest.disabled = !testStarted || !greetingReady || oralQuestionsStarted;
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
    if (els.next) els.next.disabled = cardState !== CARD.COMPLETE || allDone;
    if (els.end) els.end.disabled = !testStarted;
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
    if (els.timerNote) els.timerNote.hidden = true;
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
    if (els.timerNote) els.timerNote.hidden = mode !== 'start';
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
    if (els.recordHint) els.recordHint.hidden = false;
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
      if (cardState !== CARD.ASKING && !(greetingReady && !oralQuestionsStarted)) return;
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
    (state.items || []).forEach(function (item) {
      if (parseInt(item.id, 10) === parseInt(state.current_item_id, 10)) currentItem = item;
    });
    if (currentItem && oralQuestionsStarted) renderCard(currentItem);
    syncLessonMenu();
    syncButtons();
  }

  function renderCard(item) {
    text(els.qnum, 'Question ' + item.idx + ' of ' + (state.total_questions || '?'));
    text(els.question, item.prompt || item.spoken_question || '');
    liveTranscript = item.live_transcript || item.student_answer_text || '';
    if (liveTranscript) setTranscriptDisplay('final', liveTranscript);
    else setTranscriptDisplay('idle');
    clarificationPending = item.card_state === CARD.CLARIFICATION || !!item.clarification_question_text;
    if (item.card_state === CARD.CLARIFICATION || item.clarification_question_text) {
      clarificationQuestion = item.clarification_question_text || clarificationQuestion;
      originalAnswer = item.student_answer_text || item.live_transcript || originalAnswer;
    }
    if (els.feedback) {
      if (item.evaluated && item.feedback_text) {
        els.feedback.hidden = false;
        els.feedback.textContent = item.feedback_text;
      } else if (!els.retry || els.retry.hidden) {
        els.feedback.hidden = true;
        els.feedback.textContent = '';
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
    if (els.hint) els.hint.hidden = false;
    startStartAnswerTimer();
  }

  function playQuestionAudio() {
    stopTimer();
    hideRetry();
    clarificationPending = false;
    clarificationMode = false;
    if (els.recordHint) els.recordHint.hidden = true;
    if (els.hint) els.hint.hidden = false;
    if (!currentItem) return;

    if (cardState !== CARD.COMPLETE) {
      liveTranscript = '';
      setTranscriptDisplay('idle');
    }

    setCardState(CARD.ASKING);
    startMayaBarMotion();
    setStatus('Maya is asking the question.');

    function doneAsking() {
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
    if (!dc || dc.readyState !== 'open') {
      if (onDone) onDone();
      return;
    }
    script = String(script || '').trim();
    if (!script) {
      if (onDone) onDone();
      return;
    }
    if (purpose === 'clarification') {
      setCardState(CARD.CLARIFICATION);
      clarificationPending = true;
    } else if (purpose === 'question' || purpose === 'greeting') {
      setCardState(CARD.ASKING);
      startMayaBarMotion();
    }
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
  }

  function connectVoice() {
    return postJson('/student/api/progress_test_v4_voice_token.php', { attempt_id: attemptId }).then(function (tok) {
      pc = new RTCPeerConnection();
      remoteAudio = document.createElement('audio');
      remoteAudio.autoplay = true;
      pc.ontrack = function (ev) { remoteAudio.srcObject = ev.streams[0]; };
      dc = pc.createDataChannel('oai-events');
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
    setTranscriptDisplay(clarificationMode ? 'listening' : 'recording');
    if (els.hint) els.hint.hidden = true;
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
    setTranscriptDisplay('processing');
    setStatus('Processing your answer...');

    return flushRecordingUploads()
      .then(function () {
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
        dbg('finalize transcript response', tr);
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
      .then(handleEvaluationResult)
      .catch(function (err) {
        dbg('submitAnswerEvaluation failed', err.message || err);
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
    if (els.recordHint) els.recordHint.hidden = true;
    submitAnswerEvaluation({
      timedOutStart: timedOutStart,
      evalStatusMessage: timedOutStart ? 'No answer started in time. Evaluating...' : 'Evaluating...'
    });
  }

  function stopAnswerCapture(timedOutRecording) {
    stopTimer();
    stopStudentAnalyser();
    if (els.recordHint) els.recordHint.hidden = true;
    submitAnswerEvaluation({
      evalStatusMessage: timedOutRecording ? 'Recording limit reached. Evaluating...' : 'Evaluating your answer...',
      originalAnswer: originalAnswer,
      clarificationMode: clarificationMode
    });
  }

  function handleEvaluationResult(out) {
    pendingEvalOpts = null;
    hideRetry();
    updateProgress(out.state);
    var ev = out.evaluation || {};
    var feedback = out.feedback_for_student || ev.feedback_for_student || '';
    if (els.feedback) {
      els.feedback.hidden = false;
      els.feedback.textContent = feedback;
    }

    if (out.next_action === 'retry_english') {
      clarificationPending = false;
      clarificationMode = false;
      liveTranscript = '';
      setTranscriptDisplay('idle');
      speakScript('Please answer this question again in English.', 'question', function () {
        onQuestionFinished();
      });
      return;
    }

    if (out.next_action === 'clarify') {
      clarificationQuestion = out.clarification_question || feedback;
      originalAnswer = liveTranscript;
      clarificationPending = true;
      clarificationMode = false;
      liveTranscript = '';
      setTranscriptDisplay('idle');
      speakScript(clarificationQuestion, 'clarification', function () {
        setCardState(CARD.CLARIFICATION);
        setStatus('One clarification allowed. Tap Start Answer.');
        startStartAnswerTimer();
      });
      return;
    }

    clarificationPending = false;
    var scoreLine = 'You scored ' + Math.round(out.score_pct || ev.score_pct || 0) + ' percent on this question.';
    speakScript(scoreLine + ' ' + feedback, 'feedback');
    setCardState(CARD.COMPLETE);
    setStatus('Question complete.');

    if (out.next_action === 'complete_test') {
      return apiJson('complete_test', {}).then(function (done) {
        updateProgress(done.state);
        setStatus(done.summary || 'Test complete.');
        speakScript(done.summary || 'Your progress test is complete.', 'final');
        testStarted = false;
        oralQuestionsStarted = false;
        greetingReady = false;
        if (els.card) els.card.hidden = true;
        if (els.greeting) els.greeting.hidden = true;
        if (els.prestart) els.prestart.hidden = true;
        syncLessonMenu();
        syncButtons();
      });
    }
    syncButtons();
  }

  function playGreeting() {
    greetingReady = false;
    oralQuestionsStarted = false;
    var resume = state && state.resume_mode === 'resumed';
    var greeting = resume
      ? ('Hi ' + (cfg.firstName || 'there') + ', I am ready to resume your progress test.')
      : ('Hi ' + (cfg.firstName || 'there') + ', I am ready to start your progress test.');
    if (els.greetingCopy) {
      text(els.greetingCopy, resume ? 'Maya is ready to resume your progress test.' : 'Maya is ready to start your progress test.');
    }
    setCardState(CARD.ASKING);
    startMayaBarMotion();
    setStatus('Maya is greeting you.');
    speakScript(greeting, 'greeting', function () {
      stopMayaBarMotion();
      greetingReady = true;
      setCardState(CARD.READY);
      setStatus('Tap Start my Progress Test when you are ready.');
      syncButtons();
    });
  }

  function beginOralQuestions() {
    if (!currentItem) return;
    oralQuestionsStarted = true;
    greetingReady = true;
    if (els.greeting) els.greeting.hidden = true;
    if (els.card) els.card.hidden = false;
    hideRetry();
    renderCard(currentItem);
    setStatus('Professional oral check in progress.');
    playQuestionAudio();
    syncButtons();
  }

  function ensurePrepared() {
    setStatus('Preparing questions and audio...');
    return apiJson('ensure_prepared', { cohort_id: cfg.cohortId, lesson_id: cfg.lessonId }).then(function (out) {
      updateProgress(out.state);
      if (out.preparing || !out.state.prepared) {
        if (els.startTest) els.startTest.disabled = true;
        prepTimer = setTimeout(ensurePrepared, 2500);
        setStatus(out.state.status_text || 'Generating questions and caching audio...');
        return;
      }
      if (prepTimer) clearTimeout(prepTimer);
      setStatus('Ready. Questions are prepared — start when you are.');
      syncLessonMenu();
      syncButtons();
    }).catch(function (err) {
      setStatus('Unable to prepare test: ' + err.message);
      syncLessonMenu();
    });
  }

  function startTestFlow() {
    greetingReady = false;
    oralQuestionsStarted = false;
    setStatus('Connecting oral exam session...');
    Promise.all([startCamera(), connectVoice()]).then(function () {
      return apiJson('start_oral_test', {});
    }).then(function (out) {
      testStarted = true;
      if (els.prestart) els.prestart.hidden = true;
      if (els.card) els.card.hidden = true;
      if (els.greeting) els.greeting.hidden = false;
      if (els.beginTest) els.beginTest.disabled = true;
      updateProgress(out.state);
      playGreeting();
      syncLessonMenu();
      syncButtons();
    }).catch(function (err) {
      setStatus('Could not start test: ' + err.message);
    });
  }

  function nextQuestion() {
    if (!state || !currentItem) return;
    clarificationMode = false;
    clarificationPending = false;
    originalAnswer = '';
    clarificationQuestion = '';
    liveTranscript = '';
    recordingStartedAt = 0;
    stopTimer();
    hideRetry();
    setTranscriptDisplay('idle');
    if (els.feedback) { els.feedback.hidden = true; els.feedback.textContent = ''; }
    if (els.recordHint) els.recordHint.hidden = true;
    return apiJson('advance_item', {}).then(function (out) {
      updateProgress(out.state);
      if (state.evaluated_count >= state.total_questions) {
        setStatus('All questions complete.');
        return;
      }
      playQuestionAudio();
    });
  }

  if (els.startTest) els.startTest.addEventListener('click', startTestFlow);
  if (els.beginTest) els.beginTest.addEventListener('click', beginOralQuestions);
  if (els.startAnswer) els.startAnswer.addEventListener('click', startAnswerCapture);
  if (els.stopAnswer) els.stopAnswer.addEventListener('click', function () { stopAnswerCapture(false); });
  if (els.replay) els.replay.addEventListener('click', playQuestionAudio);
  if (els.clarify) els.clarify.addEventListener('click', function () {
    if (clarificationQuestion) speakScript(clarificationQuestion, 'clarification');
  });
  if (els.next) els.next.addEventListener('click', nextQuestion);
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
    if (!window.confirm('Exit this progress test? Partial answers on the current attempt will be reset.')) return;
    apiJson('end_oral_test_without_penalty', {}).then(function (out) {
      testStarted = false;
      greetingReady = false;
      oralQuestionsStarted = false;
      stopTimer();
      stopMayaBarMotion();
      stopStudentAnalyser();
      if (els.card) els.card.hidden = true;
      if (els.greeting) els.greeting.hidden = true;
      updateProgress(out.state);
      setStatus('Test ended. You may start again when ready.');
      syncLessonMenu();
    });
  });

  if (els.lessonMenuLink) {
    els.lessonMenuLink.addEventListener('click', function (ev) {
      ev.preventDefault();
      leaveToLessonMenu();
    });
  }

  window.addEventListener('beforeunload', function () {
    if (testStarted && attemptId) {
      navigator.sendBeacon('/student/api/progress_test_v4_oral.php', new Blob([JSON.stringify({
        action: 'abort_voice_session_without_penalty',
        attempt_id: attemptId
      })], { type: 'application/json' }));
    }
  });

  ensurePrepared();
  syncLessonMenu();
})();
