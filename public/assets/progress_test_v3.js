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
    mute: $('[data-ptv3-mute]'),
    end: $('[data-ptv3-end]'),
    textMode: $('[data-ptv3-text]'),
    textBox: $('[data-ptv3-text-box]'),
    textAnswer: $('[data-ptv3-text-answer]'),
    submitText: $('[data-ptv3-submit-text]'),
    video: $('[data-ptv3-video]'),
    videoFallback: $('[data-ptv3-video-fallback]'),
    recording: $('[data-ptv3-recording]')
  };

  var state = null;
  var attemptId = 0;
  var currentItem = null;
  var pc = null;
  var dc = null;
  var localStream = null;
  var remoteAudio = null;
  var connected = false;
  var muted = false;
  var awaitingAnswer = false;
  var awaitingClarification = false;
  var originalAnswer = '';
  var clarificationQuestion = '';
  var activeStudentBubble = null;
  var responseInProgress = false;
  var pendingInstructions = '';
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
  var donePromptShownForAnswer = '';

  function setCoachState(name) {
    root.setAttribute('data-coach-state', name);
  }

  function setStatus(message, stage) {
    text(els.status, message);
    if (stage) text(els.stage, stage);
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

  function addBubble(role, label, message, kind) {
    message = String(message || '').trim();
    var bubbleKey = role + '|' + (kind || '') + '|' + message.toLowerCase().replace(/\s+/g, ' ');
    var now = Date.now();
    if (message && bubbleKey === lastBubbleKey && now - lastBubbleAt < 2500) return null;
    lastBubbleKey = bubbleKey;
    lastBubbleAt = now;
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
    text(els.title, 'Progress Test V3');
    text(els.attempt, 'Attempt status: ' + (state.formal_result_label || state.attempt_status || state.status || 'Ready'));
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

  function startCameraPreview(stream) {
    if (!els.video || !stream) return;
    try {
      els.video.srcObject = stream;
      if (els.videoFallback) els.videoFallback.hidden = true;
      var p = els.video.play();
      if (p && typeof p.catch === 'function') p.catch(function () {});
    } catch (e) {}
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
      setStatus('Ready to start. Microphone is still closed.', 'Ready to start');
      els.start.disabled = false;
      els.textMode.disabled = false;
      addBubble('maya', 'Maya', 'Ready to start. I have loaded your generated progress test questions. Click Start Progress Test when you are ready.', 'greeting');
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
    remoteAudio.setAttribute('playsinline', 'playsinline');
    remoteAudio.className = 'ptv3-remote-audio';
    document.body.appendChild(remoteAudio);
    return remoteAudio;
  }

  function unlockAudioPlayback() {
    var audio = ensureRemoteAudio();
    audio.muted = false;
    try {
      var p = audio.play();
      if (p && typeof p.catch === 'function') p.catch(function () {});
    } catch (e) {}
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
      setStatus('Connected. Maya will start now.', 'Realtime connected');
      dcOpenResolve();
    };
    dc.onerror = function () {
      dcOpenReject(new Error('Realtime data channel failed to open.'));
      handleDisconnect();
    };
    dc.onclose = function () { if (connected) handleDisconnect(); };

    return navigator.mediaDevices.getUserMedia({ audio: true, video: { facingMode: 'user' } }).catch(function () {
      return navigator.mediaDevices.getUserMedia({ audio: true });
    }).then(function (stream) {
      localStream = stream;
      startCameraPreview(stream);
      setRecording(true, 'Recording in Progress');
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
    unlockAudioPlayback();
    setCoachState('thinking');
    setStatus('Opening microphone and realtime voice...', 'Connecting');
    postJson('/student/api/progress_test_voice_token.php', { attempt_id: attemptId }).then(function (token) {
      return connectRealtime(token.client_secret, token.realtime_endpoint);
    }).then(function () {
      connected = true;
      els.mute.disabled = false;
      els.end.disabled = false;
      els.textMode.disabled = false;
      setCoachState('ready');
      startAudioCheck();
    }).catch(function (err) {
      els.start.disabled = false;
      els.textMode.disabled = false;
      setCoachState('error');
      setStatus('Voice failed. Continue in text mode is available.', 'Voice unavailable');
      addBubble('maya', 'System', 'Voice failed: ' + err.message + '\nYou can continue in text mode without penalty.', 'warning');
      api('abort_voice_session_without_penalty', {}).catch(function () {});
    });
  }

  function sendResponse(instructions, purpose) {
    if (!dc || dc.readyState !== 'open') return;
    if (responseInProgress) {
      pendingInstructions = instructions;
      pendingPurpose = purpose || '';
      return;
    }
    mayaTurnPurpose = purpose || '';
    responseInProgress = true;
    dc.send(JSON.stringify({
      type: 'response.create',
      response: {
        output_modalities: ['audio'],
        instructions: instructions
      }
    }));
  }

  function drainResponse() {
    var finishedPurpose = mayaTurnPurpose;
    mayaTurnPurpose = '';
    responseInProgress = false;
    if (pendingInstructions) {
      var next = pendingInstructions;
      var nextPurpose = pendingPurpose;
      pendingInstructions = '';
      pendingPurpose = '';
      sendResponse(next, nextPurpose);
      return;
    }
    if ((finishedPurpose === 'question' || finishedPurpose === 'clarification') && acceptAnswerAfterMaya) {
      acceptAnswerAfterMaya = false;
      awaitingAnswer = true;
      setCoachState('ready');
      setStatus('Listening for your answer now.', finishedPurpose === 'clarification' ? 'Clarification answer' : ('Question ' + currentItem.idx + '/' + state.total_questions));
      return;
    }
    if (finishedPurpose === 'feedback' && nextQuestionAfterFeedback) {
      nextQuestionAfterFeedback = false;
      addBubble('maya', 'Maya', 'OK, let’s go to question ' + (state.current_idx || '') + '.', 'transition');
      askCurrentQuestion();
      return;
    }
    if (finishedPurpose === 'feedback' && completeAfterFeedback) {
      completeAfterFeedback = false;
      completeTest();
      return;
    }
    if (finishedPurpose === 'final') {
      stopRealtime(false);
      return;
    }
    if (finishedPurpose === 'audio_check' && audioCheckActive) {
      awaitingAudioCheck = true;
      setCoachState('ready');
      setStatus('Say "ready" when you are ready to begin. This is not scored.', 'Readiness check');
      return;
    }
    if (finishedPurpose === 'done_check') {
      awaitingAnswer = true;
      setCoachState('ready');
      setStatus('Say "done" to score, or continue answering.', 'Confirm answer');
      return;
    }
  }

  function startAudioCheck() {
    audioCheckActive = true;
    awaitingAudioCheck = false;
    awaitingAnswer = false;
    acceptAnswerAfterMaya = false;
    activeStudentBubble = null;
    var firstName = String(cfg.firstName || 'Student').trim() || 'Student';
    var readyPrompt = 'Hello ' + firstName + ', are you ready for your progress test?';
    setStatus('Maya is confirming you are ready. This is not scored.', 'Readiness check');
    addBubble('maya', 'Maya', readyPrompt, 'greeting');
    sendResponse('This is a non-scored readiness check before the test. Say exactly, naturally: "' + readyPrompt + '" Then stop speaking and wait.', 'audio_check');
  }

  function audioCheckConfirmsReady(transcript) {
    var t = String(transcript || '').toLowerCase();
    return /\b(ready|i am ready|i'm ready|i can hear you|can hear you|i hear you|heard you|yes i can hear)\b/.test(t);
  }

  function isSetupOrHoldSpeech(transcript) {
    var t = String(transcript || '').toLowerCase();
    return /\b(can you hear me|do you hear me|hello|wait|hold on|not ready|i am not ready|i'm not ready|haven't asked|have not asked|you didn't ask|you have not asked|you haven't asked|repeat|say that again|i can't hear|i cannot hear|no audio|audio is not working)\b/.test(t);
  }

  function isDoneSpeech(transcript) {
    var t = String(transcript || '').toLowerCase().replace(/[^\w\s']/g, ' ');
    return /\b(done|finished|complete|that's all|that is all|i'm done|i am done|submit|score it|go ahead)\b/.test(t);
  }

  function isContinueSpeech(transcript) {
    var t = String(transcript || '').toLowerCase().replace(/[^\w\s']/g, ' ');
    return /\b(not done|not finished|wait|hold on|one more thing|continue|still answering|i'm still answering|i am still answering|let me finish|more to add)\b/.test(t);
  }

  function resetAnswerBuffer() {
    answerSegments = [];
    awaitingDoneConfirmation = false;
    doneConfirmationText = '';
    donePromptShownForAnswer = '';
    if (answerSettleTimer) clearTimeout(answerSettleTimer);
    answerSettleTimer = null;
  }

  function combinedAnswerText(extra) {
    var parts = answerSegments.slice();
    if (extra && String(extra).trim() !== '') parts.push(String(extra).trim());
    return parts.join(' ').replace(/\s+/g, ' ').trim();
  }

  function scheduleAnswerSettle() {
    if (awaitingDoneConfirmation) return;
    if (answerSettleTimer) clearTimeout(answerSettleTimer);
    answerSettleTimer = setTimeout(function () {
      answerSettleTimer = null;
      promptDoneConfirmation();
    }, answerSettleMs);
    setStatus('Listening. Pause when you are done; Maya will confirm before scoring.', awaitingClarification ? 'Clarification answer' : ('Question ' + currentItem.idx + '/' + state.total_questions));
  }

  function promptDoneConfirmation() {
    var answerText = combinedAnswerText('');
    if (!awaitingAnswer || responseInProgress || awaitingDoneConfirmation || !answerText) return;
    if (donePromptShownForAnswer === answerText) return;
    donePromptShownForAnswer = answerText;
    awaitingDoneConfirmation = true;
    awaitingAnswer = false;
    doneConfirmationText = 'I heard your answer. Are you finished, or do you want to add more? Say "done" to score it, or continue answering.';
    addBubble('maya', 'Maya', doneConfirmationText, 'transition');
    sendResponse('Do not score yet. Ask this concise confirmation only: "' + doneConfirmationText + '"', 'done_check');
  }

  function handleAudioCheckTranscript(transcript) {
    addBubble('student', 'Student', transcript, 'answer');
    if (audioCheckConfirmsReady(transcript)) {
      audioCheckActive = false;
      awaitingAudioCheck = false;
      addBubble('maya', 'Maya', 'Great, I can hear you. Starting Question 1 now.', 'transition');
      askCurrentQuestion();
      return;
    }
    setStatus('Waiting for readiness confirmation. Say "ready" when you want to begin.', 'Readiness check');
    addBubble('maya', 'Maya', 'I heard you, but I will not start the scored test until you say "ready".', 'warning');
    sendResponse('Do not ask a test question yet. Briefly say: "I heard you, but I will not start the scored test until you say ready."', 'audio_check');
  }

  function isMayaEchoTranscript(transcript) {
    var t = String(transcript || '').toLowerCase().replace(/\s+/g, ' ').trim();
    return t.indexOf('i heard your answer') !== -1
      || t.indexOf('are you finished') !== -1
      || t.indexOf('say done to score') !== -1
      || t.indexOf('do you want to add more') !== -1
      || t.indexOf('i heard you, but i will not start') !== -1
      || t.indexOf('hello ' + String(cfg.firstName || '').toLowerCase()) === 0;
  }

  function askCurrentQuestion() {
    if (!currentItem) return;
    resetAnswerBuffer();
    awaitingAnswer = false;
    acceptAnswerAfterMaya = true;
    awaitingClarification = false;
    originalAnswer = '';
    clarificationQuestion = '';
    activeStudentBubble = null;
    setStatus('Maya is asking question ' + currentItem.idx + '.', 'Question ' + currentItem.idx + '/' + state.total_questions);
    addBubble('maya', 'Maya', currentItem.spoken_question || ('Question ' + currentItem.idx + '. ' + currentItem.prompt), 'question');
    api('save_transcript_segment', {
      item_id: currentItem.id,
      role: 'maya',
      event_type: 'question',
      transcript_text: currentItem.spoken_question || currentItem.prompt
    }).catch(function () {});
    sendResponse('Ask exactly this progress test question, naturally and briefly, then stop speaking and listen for the answer: "' + (currentItem.spoken_question || currentItem.prompt) + '"', 'question');
  }

  function handleRealtimeEvent(event) {
    var msg = null;
    try { msg = JSON.parse(event.data); } catch (e) { return; }
    var type = String(msg.type || '');
    if (type === 'error') {
      setStatus('Realtime voice error.', 'Connection issue');
      return;
    }
    if (type === 'response.created') responseInProgress = true;
    if (type === 'response.output_audio_transcript.done' && msg.transcript) {
      api('save_transcript_segment', {
        item_id: currentItem ? currentItem.id : 0,
        role: 'maya',
        event_type: 'audio_transcript',
        transcript_text: String(msg.transcript || '')
      }).catch(function () {});
    }
    if (type === 'conversation.item.input_audio_transcription.completed' && msg.transcript) {
      handleStudentTranscript(msg);
    }
    if (type === 'response.done' || type === 'response.failed' || type === 'response.cancelled' || type === 'response.canceled') {
      drainResponse();
    }
  }

  function transcriptKey(msg) {
    return String(msg.event_id || msg.item_id || msg.response_id || msg.transcript || '').slice(0, 260);
  }

  function handleStudentTranscript(msg) {
    var transcript = String(msg.transcript || '').trim();
    if (!transcript) return;
    if (isMayaEchoTranscript(transcript)) return;
    var key = transcriptKey(msg);
    if (processedTranscripts[key]) return;
    processedTranscripts[key] = true;
    if (audioCheckActive || awaitingAudioCheck) {
      handleAudioCheckTranscript(transcript);
      return;
    }
    if (!awaitingAnswer || responseInProgress || !currentItem) return;
    if (isSetupOrHoldSpeech(transcript)) {
      resetAnswerBuffer();
      awaitingAnswer = false;
      acceptAnswerAfterMaya = true;
      activeStudentBubble = null;
      addBubble('maya', 'Maya', 'No problem. I will repeat the current question. This was not scored.', 'warning');
      sendResponse('The student is not ready or had an audio/setup issue. Do not score this. Repeat the exact current question clearly: "' + (currentItem.spoken_question || currentItem.prompt) + '"', awaitingClarification ? 'clarification' : 'question');
      return;
    }
    if (awaitingDoneConfirmation) {
      if (isDoneSpeech(transcript)) {
        var finalAnswer = combinedAnswerText('');
        if (!finalAnswer) return;
        awaitingDoneConfirmation = false;
        if (activeStudentBubble && activeStudentBubble.body) activeStudentBubble.body.textContent = finalAnswer;
        evaluateBufferedAnswer(finalAnswer);
        return;
      }
      if (isContinueSpeech(transcript)) {
        awaitingDoneConfirmation = false;
        donePromptShownForAnswer = '';
        setStatus('Keep going. Maya will wait.', awaitingClarification ? 'Clarification answer' : ('Question ' + currentItem.idx + '/' + state.total_questions));
        scheduleAnswerSettle();
        return;
      }
      awaitingDoneConfirmation = false;
      donePromptShownForAnswer = '';
    }
    answerSegments.push(transcript);
    if (activeStudentBubble && activeStudentBubble.body) {
      activeStudentBubble.body.textContent = combinedAnswerText('');
    } else {
      activeStudentBubble = addBubble('student', 'Student', combinedAnswerText(''), 'answer');
    }
    api('save_transcript_segment', {
      item_id: currentItem.id,
      role: 'student',
      event_type: awaitingClarification ? 'clarification_answer' : 'answer',
      transcript_text: transcript
    }).catch(function () {});
    scheduleAnswerSettle();
  }

  function evaluateBufferedAnswer(finalAnswer) {
    if (awaitingClarification) {
      evaluateAnswer(originalAnswer, clarificationQuestion, finalAnswer);
    } else {
      evaluateAnswer(finalAnswer, '', '');
    }
  }

  function evaluateAnswer(answer, clarificationQ, clarificationA) {
    resetAnswerBuffer();
    awaitingAnswer = false;
    setCoachState('thinking');
    setStatus('Backend is evaluating the answer...', 'Evaluating');
    api('evaluate_item_answer', {
      item_id: currentItem.id,
      student_answer_text: answer,
      clarification_question_text: clarificationQ,
      clarification_answer_text: clarificationA
    }).then(function (out) {
      updateProgress(out.state);
      if (out.next_action === 'clarify') {
        awaitingAnswer = false;
        acceptAnswerAfterMaya = true;
        awaitingClarification = true;
        originalAnswer = answer;
        clarificationQuestion = out.feedback_for_student || 'Good start, but that is not complete yet. Say a little more.';
        activeStudentBubble = null;
        addBubble('maya', 'Maya', clarificationQuestion, 'warning');
        setStatus('Maya is asking one clarification.', 'Clarification');
        sendResponse('Ask this one clarification only, without giving away the answer: "' + clarificationQuestion + '" Then stop speaking and listen.', 'clarification');
        return;
      }

      var scoreLine = 'You scored ' + Math.round(out.score_pct || 0) + '% on this question.';
      addBubble('maya', 'Maya', scoreLine + '\n' + (out.feedback_for_student || ''), 'score');

      if (out.next_action === 'complete_test') {
        completeAfterFeedback = true;
        sendResponse('Say this backend score and feedback concisely. Do not add your own score: "' + scoreLine + ' ' + (out.feedback_for_student || '') + '"', 'feedback');
      } else {
        nextQuestionAfterFeedback = true;
        sendResponse('Say this backend score and feedback concisely. Do not add your own score: "' + scoreLine + ' ' + (out.feedback_for_student || '') + '"', 'feedback');
      }
    }).catch(function (err) {
      awaitingAnswer = true;
      setCoachState('error');
      setStatus('Evaluation failed. You can retry or continue in text mode.', 'Evaluation issue');
      addBubble('maya', 'System', 'Evaluation failed: ' + err.message, 'warning');
    });
  }

  function completeTest() {
    setCoachState('thinking');
    setStatus('Completing test through canonical attempt state...', 'Completing');
    api('complete_test', {}).then(function (out) {
      updateProgress(out.state);
      addBubble('maya', 'Maya', out.summary || 'Progress test complete.', out.pass_gate_met ? 'final_approved' : 'final_revision');
      sendResponse('Say this short final progress test summary exactly and naturally: "' + (out.summary || 'Progress test complete.') + '"', 'final');
      setCoachState('ready');
      setStatus('Completed.', 'Complete');
      els.mute.disabled = true;
      els.end.disabled = true;
      els.textMode.disabled = true;
    }).catch(function (err) {
      setCoachState('error');
      setStatus('Completion failed: ' + err.message, 'Completion issue');
      addBubble('maya', 'System', 'Completion failed: ' + err.message, 'warning');
    });
  }

  function stopRealtime(notifyBackend) {
    connected = false;
    if (localStream) {
      localStream.getTracks().forEach(function (track) { track.stop(); });
      localStream = null;
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
    if (notifyBackend) api('abort_voice_session_without_penalty', {}).catch(function () {});
  }

  function handleDisconnect() {
    if (!connected) return;
    stopRealtime(true);
    setCoachState('error');
    setStatus('Voice disconnected. Continue in text mode is available.', 'Voice disconnected');
    addBubble('maya', 'System', 'Voice disconnected. Your attempt was not failed; continue in text mode or restart voice.', 'warning');
    els.start.disabled = false;
    els.mute.disabled = true;
    els.end.disabled = true;
    els.textMode.disabled = false;
  }

  function toggleMute() {
    if (!localStream) return;
    muted = !muted;
    localStream.getAudioTracks().forEach(function (track) { track.enabled = !muted; });
    text(els.mute, muted ? 'Unmute Microphone' : 'Mute Microphone');
  }

  function showTextMode() {
    els.textBox.hidden = false;
    els.textMode.disabled = true;
    stopRealtime(true);
    if (currentItem && !awaitingAnswer) awaitingAnswer = true;
    setStatus('Text fallback enabled.', 'Text mode');
  }

  function submitTextAnswer() {
    if (!currentItem) return;
    var answer = String(els.textAnswer.value || '').trim();
    if (!answer) return;
    addBubble('student', 'Student', answer, 'answer');
    els.textAnswer.value = '';
    if (awaitingClarification) {
      evaluateAnswer(originalAnswer, clarificationQuestion, answer);
    } else {
      evaluateAnswer(answer, '', '');
    }
  }

  els.start.addEventListener('click', startTest);
  els.mute.addEventListener('click', toggleMute);
  els.end.addEventListener('click', function () {
    stopRealtime(true);
    setStatus('Voice ended without penalty. You can continue in text mode.', 'Voice ended');
    addBubble('maya', 'System', 'Voice session ended without penalty. Continue in text mode if needed.', 'warning');
    els.start.disabled = false;
    els.mute.disabled = true;
    els.end.disabled = true;
  });
  els.textMode.addEventListener('click', showTextMode);
  els.submitText.addEventListener('click', submitTextAnswer);

  loadQuestions();
})();
