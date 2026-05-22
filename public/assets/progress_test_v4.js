(function () {
  'use strict';

  var cfg = window.IPCAProgressTestV4Config || {};
  var CARD = { READY: 'ready', ASKING: 'asking', LISTENING: 'listening', EVALUATING: 'evaluating', CLARIFICATION: 'clarification', COMPLETE: 'complete' };

  var root = document.querySelector('[data-ptv4-card]');
  var page = document.querySelector('.ptv4-page');
  if (!page) return;

  function $(sel) { return page.querySelector(sel); }

  var els = {
    status: $('[data-ptv4-status]'),
    score: $('[data-ptv4-score]'),
    attempt: $('[data-ptv4-attempt]'),
    bar: $('[data-ptv4-bar]'),
    current: $('[data-ptv4-current]'),
    evaluated: $('[data-ptv4-evaluated]'),
    final: $('[data-ptv4-final]'),
    card: $('[data-ptv4-card]'),
    statePill: $('[data-ptv4-state-pill]'),
    qnum: $('[data-ptv4-qnum]'),
    question: $('[data-ptv4-question]'),
    transcript: $('[data-ptv4-transcript]'),
    concepts: $('[data-ptv4-concepts]'),
    feedback: $('[data-ptv4-feedback]'),
    timer: $('[data-ptv4-timer]'),
    timerFill: $('[data-ptv4-timer-fill]'),
    timerLabel: $('[data-ptv4-timer-label]'),
    recording: $('[data-ptv4-recording]'),
    video: $('[data-ptv4-video]'),
    videoFallback: $('[data-ptv4-video-fallback]'),
    startTest: $('[data-ptv4-start-test]'),
    startAnswer: $('[data-ptv4-start-answer]'),
    stopAnswer: $('[data-ptv4-stop-answer]'),
    replay: $('[data-ptv4-replay]'),
    clarify: $('[data-ptv4-clarify]'),
    next: $('[data-ptv4-next]'),
    end: $('[data-ptv4-end]')
  };

  var state = null;
  var attemptId = 0;
  var currentItem = null;
  var cardState = CARD.READY;
  var testStarted = false;
  var clarificationMode = false;
  var originalAnswer = '';
  var clarificationQuestion = '';
  var liveTranscript = '';
  var chunkIndex = 0;
  var mediaRecorder = null;
  var micStream = null;
  var cameraStream = null;
  var chunkUploadChain = Promise.resolve();
  var pc = null;
  var dc = null;
  var remoteAudio = null;
  var voiceReady = false;
  var questionAudio = new Audio();
  questionAudio.preload = 'auto';
  var prepTimer = null;
  var answerTimer = null;
  var answerDeadline = 0;
  var ANSWER_LIMIT_MS = 30000;
  var CHUNK_MS = 1500;

  function text(el, v) { if (el) el.textContent = v; }
  function clamp(n, a, b) { return Math.max(a, Math.min(b, n)); }

  function setStatus(msg) { text(els.status, msg); }

  function setCardState(next) {
    cardState = next;
    if (els.card) {
      els.card.setAttribute('data-card-state', next);
      els.card.setAttribute('data-maya-speaking', next === CARD.ASKING ? '1' : '0');
      els.card.setAttribute('data-student-answering', next === CARD.LISTENING || next === CARD.CLARIFICATION ? '1' : '0');
    }
    var labels = {
      ready: 'Ready',
      asking: 'Asking',
      listening: 'Listening',
      evaluating: 'Evaluating',
      clarification: 'Clarification',
      complete: 'Complete'
    };
    text(els.statePill, labels[next] || next);
    syncButtons();
    if (currentItem && attemptId) {
      apiJson('save_card_state', {
        item_id: currentItem.id,
        card_state: next,
        live_transcript: liveTranscript
      }).catch(function () {});
    }
  }

  function syncButtons() {
    var prepared = state && state.prepared;
    var allDone = state && state.evaluated_count >= state.total_questions;
    if (els.startTest) els.startTest.disabled = !prepared || testStarted || allDone;
    if (els.startAnswer) {
      els.startAnswer.disabled = !testStarted
        || (cardState !== CARD.READY && cardState !== CARD.ASKING)
        || cardState === CARD.LISTENING
        || cardState === CARD.EVALUATING;
    }
    if (els.stopAnswer) els.stopAnswer.disabled = cardState !== CARD.LISTENING && cardState !== CARD.CLARIFICATION;
    if (els.replay) els.replay.disabled = !testStarted || !currentItem || !currentItem.question_audio_url;
    if (els.clarify) els.clarify.disabled = cardState !== CARD.CLARIFICATION;
    if (els.next) els.next.disabled = cardState !== CARD.COMPLETE || allDone;
    if (els.end) els.end.disabled = !testStarted || allDone;
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

  function uploadChunk(blob) {
    var idx = chunkIndex++;
    var fd = new FormData();
    fd.append('action', 'upload_answer_chunk');
    fd.append('attempt_id', String(attemptId));
    fd.append('item_id', String(currentItem.id));
    fd.append('chunk_index', String(idx));
    fd.append('chunk', blob, 'chunk_' + idx + '.webm');
    chunkUploadChain = chunkUploadChain.then(function () {
      return fetch('/student/api/progress_test_v4_oral.php?action=upload_answer_chunk', {
        method: 'POST',
        credentials: 'same-origin',
        body: fd
      }).then(function (res) { return res.json(); }).then(function (out) {
        if (out.ok && out.transcript_partial) {
          liveTranscript = out.transcript_partial;
          text(els.transcript, liveTranscript);
        }
      });
    });
    return chunkUploadChain;
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
    if (currentItem) renderCard(currentItem);
    syncButtons();
  }

  function renderConcepts(item) {
    if (!els.concepts) return;
    els.concepts.innerHTML = '';
    var concepts = item.expected_concepts || [];
    var detected = item.detected_concepts || [];
    var missing = item.missing_concepts || [];
    concepts.forEach(function (c) {
      var pill = document.createElement('span');
      pill.className = 'ptv4-concept-pill';
      pill.textContent = c;
      if (item.evaluated) {
        if (detected.indexOf(c) >= 0) pill.classList.add('hit');
        else if (missing.indexOf(c) >= 0) pill.classList.add('miss');
      }
      els.concepts.appendChild(pill);
    });
  }

  function renderCard(item) {
    text(els.qnum, 'Question ' + item.idx + ' of ' + (state.total_questions || '?'));
    text(els.question, item.prompt || item.spoken_question || '');
    liveTranscript = item.live_transcript || item.student_answer_text || '';
    text(els.transcript, liveTranscript || 'Your spoken answer will appear here.');
    renderConcepts(item);
    if (els.feedback) {
      if (item.evaluated && item.feedback_text) {
        els.feedback.hidden = false;
        els.feedback.textContent = item.feedback_text;
      } else {
        els.feedback.hidden = true;
        els.feedback.textContent = '';
      }
    }
    if (item.evaluated) setCardState(CARD.COMPLETE);
    else if (item.card_state && item.card_state !== CARD.COMPLETE) setCardState(item.card_state);
    else if (testStarted) setCardState(CARD.READY);
  }

  function startAnswerTimer() {
    answerDeadline = Date.now() + ANSWER_LIMIT_MS;
    if (els.timer) els.timer.setAttribute('data-active', '1');
    if (answerTimer) clearInterval(answerTimer);
    answerTimer = setInterval(function () {
      var left = answerDeadline - Date.now();
      var pct = clamp((left / ANSWER_LIMIT_MS) * 100, 0, 100);
      if (els.timerFill) {
        els.timerFill.style.width = pct + '%';
        els.timerFill.setAttribute('data-danger', left < 8000 ? '1' : '0');
      }
      text(els.timerLabel, left > 0 ? ('Answer window: ' + Math.ceil(left / 1000) + 's') : 'Time expired');
      if (left <= 0) {
        clearInterval(answerTimer);
        stopAnswerCapture(true);
      }
    }, 100);
  }

  function stopAnswerTimer() {
    if (answerTimer) clearInterval(answerTimer);
    answerTimer = null;
    if (els.timer) els.timer.setAttribute('data-active', '0');
  }

  function playQuestionAudio() {
    if (!currentItem || !currentItem.question_audio_url) {
      speakScript(currentItem ? currentItem.spoken_question : '', 'question');
      return;
    }
    setCardState(CARD.ASKING);
    questionAudio.onended = function () {
      if (cardState === CARD.ASKING) setCardState(CARD.READY);
    };
    questionAudio.src = currentItem.question_audio_url;
    questionAudio.play().catch(function () {
      speakScript(currentItem.spoken_question, 'question');
    });
  }

  function speakScript(script, purpose) {
    if (!dc || dc.readyState !== 'open') return;
    script = String(script || '').trim();
    if (!script) return;
    setCardState(purpose === 'clarification' ? CARD.CLARIFICATION : CARD.ASKING);
    var instructions = [
      'Audio output only. English only. Verbatim text-to-speech renderer.',
      'Do not add words before or after the text. Speak exactly once, then stop.',
      'Text: "' + script.replace(/"/g, '\\"') + '"'
    ].join('\n');
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
      pc.ontrack = function (ev) {
        remoteAudio.srcObject = ev.streams[0];
      };
      dc = pc.createDataChannel('oai-events');
      dc.onopen = function () { voiceReady = true; };
      dc.onmessage = function (ev) {
        var msg;
        try { msg = JSON.parse(ev.data); } catch (e) { return; }
        handleRealtime(msg);
      };
      return navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
        micStream = stream;
        stream.getTracks().forEach(function (t) { pc.addTrack(t, stream); });
        return pc.createOffer();
      }).then(function (offer) {
        return pc.setLocalDescription(offer);
      }).then(function () {
        return fetch(tok.realtime_endpoint, {
          method: 'POST',
          headers: {
            Authorization: 'Bearer ' + tok.client_secret,
            'Content-Type': 'application/sdp'
          },
          body: pc.localDescription.sdp
        });
      }).then(function (res) { return res.text(); }).then(function (answer) {
        return pc.setRemoteDescription({ type: 'answer', sdp: answer });
      });
    });
  }

  function handleRealtime(msg) {
    var type = String(msg.type || '');
    if (type.indexOf('input_audio_transcription.completed') >= 0 || type === 'conversation.item.input_audio_transcription.completed') {
      var transcript = String(msg.transcript || '').trim();
      if (!transcript || !currentItem || cardState !== CARD.LISTENING && cardState !== CARD.CLARIFICATION) return;
      liveTranscript = liveTranscript ? (liveTranscript + ' ' + transcript) : transcript;
      text(els.transcript, liveTranscript);
      apiJson('save_transcript_segment', { item_id: currentItem.id, transcript_text: liveTranscript, event_type: clarificationMode ? 'clarification_answer' : 'answer' }).catch(function () {});
    }
    if (type === 'response.done' || type === 'response.output_audio.done') {
      if (cardState === CARD.ASKING) setCardState(CARD.READY);
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
    clarificationMode = cardState === CARD.CLARIFICATION;
    if (!clarificationMode) {
      originalAnswer = '';
      clarificationQuestion = '';
      liveTranscript = '';
      chunkIndex = 0;
      text(els.transcript, 'Listening...');
    }
    setCardState(clarificationMode ? CARD.CLARIFICATION : CARD.LISTENING);
    text(els.recording, 'Recording');
    if (els.recording) els.recording.setAttribute('data-recording', '1');
    startAnswerTimer();

    var mime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : 'audio/webm';
    mediaRecorder = new MediaRecorder(micStream, { mimeType: mime });
    mediaRecorder.ondataavailable = function (ev) {
      if (ev.data && ev.data.size > 0) uploadChunk(ev.data);
    };
    mediaRecorder.start(CHUNK_MS);
    syncButtons();
  }

  function stopAnswerCapture(timedOut) {
    stopAnswerTimer();
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
      mediaRecorder.stop();
    }
    text(els.recording, 'Standby');
    if (els.recording) els.recording.setAttribute('data-recording', '0');
    setCardState(CARD.EVALUATING);
    setStatus(timedOut ? 'Time expired. Evaluating...' : 'Evaluating your answer...');

    chunkUploadChain.then(function () {
      return apiJson('finalize_item_answer', {
        item_id: currentItem.id,
        student_answer_text: liveTranscript,
        original_answer_text: originalAnswer,
        clarification_answer_text: clarificationMode ? liveTranscript : '',
        clarification_question_text: clarificationQuestion
      });
    }).then(function (out) {
      updateProgress(out.state);
      var ev = out.evaluation || {};
      var feedback = out.feedback_for_student || ev.feedback_for_student || '';
      if (els.feedback) {
        els.feedback.hidden = false;
        els.feedback.textContent = feedback;
      }
      renderConcepts(currentItem);

      if (out.next_action === 'clarify') {
        clarificationQuestion = out.clarification_question || feedback;
        originalAnswer = liveTranscript;
        liveTranscript = '';
        text(els.transcript, 'Answer the clarification in English.');
        speakScript(clarificationQuestion, 'clarification');
        setCardState(CARD.CLARIFICATION);
        return;
      }

      var scoreLine = 'You scored ' + Math.round(out.score_pct || ev.score_pct || 0) + ' percent on this question.';
      speakScript(scoreLine + ' ' + feedback, 'feedback');
      setCardState(CARD.COMPLETE);

      if (out.next_action === 'complete_test') {
        return apiJson('complete_test', {}).then(function (done) {
          updateProgress(done.state);
          setStatus(done.summary || 'Test complete.');
          speakScript(done.summary || 'Your progress test is complete.', 'final');
          testStarted = false;
          syncButtons();
        });
      }
      syncButtons();
    }).catch(function (err) {
      setStatus('Evaluation failed: ' + err.message);
      setCardState(CARD.READY);
    });
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
      syncButtons();
    }).catch(function (err) {
      setStatus('Unable to prepare test: ' + err.message);
    });
  }

  function startTestFlow() {
    setStatus('Connecting oral exam session...');
    Promise.all([startCamera(), connectVoice()]).then(function () {
      return apiJson('start_oral_test', {});
    }).then(function (out) {
      testStarted = true;
      updateProgress(out.state);
      setStatus('Professional oral check in progress. Listen to each question, then start your answer.');
      playQuestionAudio();
      syncButtons();
    }).catch(function (err) {
      setStatus('Could not start test: ' + err.message);
    });
  }

  function nextQuestion() {
    if (!state || !currentItem) return;
    clarificationMode = false;
    originalAnswer = '';
    clarificationQuestion = '';
    liveTranscript = '';
    if (els.feedback) { els.feedback.hidden = true; els.feedback.textContent = ''; }
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
  if (els.startAnswer) els.startAnswer.addEventListener('click', startAnswerCapture);
  if (els.stopAnswer) els.stopAnswer.addEventListener('click', function () { stopAnswerCapture(false); });
  if (els.replay) els.replay.addEventListener('click', playQuestionAudio);
  if (els.clarify) els.clarify.addEventListener('click', startAnswerCapture);
  if (els.next) els.next.addEventListener('click', nextQuestion);
  if (els.end) els.end.addEventListener('click', function () {
    if (!window.confirm('End this progress test without penalty? Partial answers will be reset.')) return;
    apiJson('end_oral_test_without_penalty', {}).then(function (out) {
      testStarted = false;
      updateProgress(out.state);
      setStatus('Test ended. You may start again when ready.');
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

  ensurePrepared();
})();
