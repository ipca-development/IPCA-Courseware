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
    mute: $('[data-ptv3-mute]'),
    end: $('[data-ptv3-end]'),
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
  var cameraStream = null;
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
  var transcriptFlushUntil = 0;
  var transcriptWaitTimer = null;
  var pendingTranscriptSubmit = false;
  var pendingTranscriptKind = '';
  var transcriptWaitStartedAt = 0;
  var transcriptQuietMs = 1300;
  var transcriptMaxWaitMs = 8000;

  function setCoachState(name) {
    root.setAttribute('data-coach-state', name);
  }

  function setMayaSpeaking(active) {
    root.setAttribute('data-maya-speaking', active ? '1' : '0');
  }

  function setStudentAnswering(active) {
    answerCaptureActive = !!active;
    root.setAttribute('data-student-answering', active ? '1' : '0');
  }

  function setStatus(message, stage) {
    text(els.status, message);
    if (stage) text(els.stage, stage);
  }

  function setTyping(label, visible) {
    if (!els.liveRow) return;
    var labelEl = els.liveRow.querySelector('span');
    if (labelEl) labelEl.textContent = label || 'Working';
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
    text(els.title, 'Progress Test V3');
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
    els.finish.disabled = !!disabled;
    els.finish.setAttribute('data-action-mode', mode || 'answer');
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

  function setCloseTestMode(label) {
    if (els.end) {
      els.end.disabled = false;
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
      els.mute.disabled = false;
      els.end.disabled = false;
      setFinishButton('START', true, 'answer');
      setCoachState('ready');
      if (state && state.resume_mode === 'resumed') {
        startResumeIntro();
      } else {
        phase = 'readiness';
        startAudioCheck();
      }
    }).catch(function (err) {
      els.start.disabled = false;
      setCoachState('error');
      setStatus('Voice failed. Restart the voice session when ready.', 'Voice unavailable');
      addBubble('maya', 'System', 'Voice failed: ' + err.message + '\nThis did not penalize the attempt. Restart voice when ready.', 'warning');
      api('abort_voice_session_without_penalty', {}).catch(function () {});
    });
  }

  function sendResponse(instructions, purpose) {
    if (!dc || dc.readyState !== 'open') return;
    setTyping('', false);
    setMicEnabled(false);
    setStudentAnswering(false);
    if (responseInProgress) {
      pendingInstructions = instructions;
      pendingPurpose = purpose || '';
      return;
    }
    liveMayaBubble = null;
    liveMayaText = '';
    mayaTurnPurpose = purpose || '';
    responseInProgress = true;
    setMayaSpeaking(true);
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
    setMayaSpeaking(false);
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
      phase = 'answering';
      setStudentAnswering(false);
      setMicEnabled(false);
      setFinishButton(finishedPurpose === 'clarification' ? 'ANSWER CLARIFICATION' : 'START', false, 'answer');
      setFinishPulse(true);
      setCoachState('ready');
      setStatus(finishedPurpose === 'clarification' ? 'Tap ANSWER CLARIFICATION and add your follow-up answer.' : 'Tap START when you are ready to speak.', finishedPurpose === 'clarification' ? 'Clarification answer' : ('Question ' + currentItem.idx + '/' + state.total_questions));
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
        setCloseTestMode('Close Test');
        setStatus('Maya finished the last question feedback. Tap Test Evaluation for the final result.', 'Ready for final evaluation');
      }
      return;
    }
    if (finishedPurpose === 'final') {
      setStatus('Final evaluation complete. Tap Close Test to return to the course.', 'Complete');
      setCloseTestMode('Close Test');
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
    if (finishedPurpose === 'resume_intro') {
      phase = 'asking';
      askCurrentQuestion();
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
    phase = 'readiness';
    awaitingAudioCheck = false;
    awaitingAnswer = false;
    acceptAnswerAfterMaya = false;
    activeStudentBubble = null;
    setStudentAnswering(false);
    var firstName = String(cfg.firstName || 'Student').trim() || 'Student';
    var readyPrompt = 'Hello ' + firstName + ', are you ready for your progress test?';
    setStatus('Maya is confirming you are ready. This is not scored.', 'Readiness check');
    sendResponse('This is a non-scored readiness check before the test. Say exactly, naturally: "' + readyPrompt + '" Then stop speaking and wait.', 'audio_check');
  }

  function startResumeIntro() {
    phase = 'resume_intro';
    awaitingAudioCheck = false;
    audioCheckActive = false;
    awaitingAnswer = false;
    acceptAnswerAfterMaya = false;
    activeStudentBubble = null;
    setStudentAnswering(false);
    setMicEnabled(false);
    setFinishButton('START', true, 'answer');
    setFinishPulse(false);
    var firstName = String(cfg.firstName || 'Student').trim() || 'Student';
    var prompt = 'Welcome back ' + firstName + ', let’s resume your test now.';
    setStatus('Maya is resuming your test at the next unanswered question.', 'Resuming');
    sendResponse('Read this exact sentence and nothing else: "' + prompt + '"', 'resume_intro');
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
    transcriptWaitStartedAt = 0;
    transcriptFlushUntil = 0;
    setTyping('', false);
  }

  function combinedAnswerText(extra) {
    var parts = answerSegments.slice();
    if (extra && String(extra).trim() !== '') parts.push(String(extra).trim());
    return parts.join(' ').replace(/\s+/g, ' ').trim();
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
    sendResponse('Do not ask a test question yet. Briefly say: "I heard you, but I will not start the scored test until you say ready."', 'audio_check');
  }

  function captureStudentTranscript(transcript, kind) {
    answerSegments.push(transcript);
    if (activeStudentBubble && activeStudentBubble.body) {
      activeStudentBubble.body.textContent = combinedAnswerText('');
    } else {
      activeStudentBubble = addBubble('student', 'Student', combinedAnswerText(''), kind || 'answer');
    }
    if (pendingTranscriptSubmit) scheduleTranscriptSubmit(pendingTranscriptKind);
  }

  function scheduleTranscriptSubmit(kind) {
    pendingTranscriptSubmit = true;
    pendingTranscriptKind = kind || 'answer';
    if (!transcriptWaitStartedAt) transcriptWaitStartedAt = Date.now();
    if (transcriptWaitTimer) clearTimeout(transcriptWaitTimer);
    var elapsed = Date.now() - transcriptWaitStartedAt;
    var hasText = combinedAnswerText('') !== '';
    var delay = hasText || elapsed >= transcriptMaxWaitMs ? transcriptQuietMs : 500;
    transcriptWaitTimer = setTimeout(function () {
      transcriptWaitTimer = null;
      elapsed = Date.now() - transcriptWaitStartedAt;
      if (!hasText && elapsed < transcriptMaxWaitMs) {
        scheduleTranscriptSubmit(kind);
        return;
      }
      pendingTranscriptSubmit = false;
      pendingTranscriptKind = '';
      transcriptWaitStartedAt = 0;
      transcriptFlushUntil = 0;
      if (kind === 'readiness') {
        setTyping('', false);
        handleAudioCheckTranscript(combinedAnswerText(''));
      } else {
        setTyping('Maya is thinking', true);
        submitCurrentBufferedAnswer();
      }
    }, delay);
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
    if (!currentItem || !currentItem.prompt) {
      setCoachState('error');
      setStatus('No lesson-grounded progress test question is available. Please reload to regenerate.', 'Question unavailable');
      addBubble('maya', 'System', 'No lesson-grounded progress test question is available. This attempt was not scored.', 'warning');
      return;
    }
    phase = 'asking';
    resetAnswerBuffer();
    awaitingAnswer = false;
    acceptAnswerAfterMaya = true;
    awaitingClarification = false;
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
    sendResponse(
      'Read this exact backend progress-test question and nothing else. '
      + 'Do not say you cannot load it. Do not substitute, explain, answer, tutor, or mention another topic. '
      + 'Exact words to read: "' + (currentItem.spoken_question || currentItem.prompt) + '"',
      'question'
    );
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
      if (liveMayaBubble && liveMayaBubble.body) {
        liveMayaBubble.body.textContent = String(msg.transcript || '');
      }
      api('save_transcript_segment', {
        item_id: currentItem ? currentItem.id : 0,
        role: 'maya',
        event_type: 'audio_transcript',
        transcript_text: String(msg.transcript || '')
      }).catch(function () {});
    }
    if ((type === 'response.output_audio_transcript.delta' || type === 'response.audio_transcript.delta') && (msg.delta || msg.text)) {
      liveMayaText += String(msg.delta || msg.text || '');
      if (!liveMayaBubble) liveMayaBubble = addBubble('maya', 'Maya', '', 'live');
      if (liveMayaBubble && liveMayaBubble.body) {
        liveMayaBubble.body.textContent = liveMayaText;
        els.thread.scrollTop = els.thread.scrollHeight;
      }
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
    if (Date.now() < ignoreTranscriptsUntil) return;
    if (isMayaEchoTranscript(transcript)) return;
    var key = transcriptKey(msg);
    if (processedTranscripts[key]) return;
    processedTranscripts[key] = true;
    if ((audioCheckActive || awaitingAudioCheck) && phase === 'readiness' && (answerCaptureActive || Date.now() < transcriptFlushUntil) && !responseInProgress) {
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
    if (!awaitingAnswer || responseInProgress || !currentItem) return;
    if (isCourtesyOnlySpeech(transcript)) {
      if (awaitingClarification) {
        setStatus('That sounded like an acknowledgement. Tap START and add your clarification, or End Test if needed.', 'Clarification answer');
      } else {
        setStatus('That sounded like an acknowledgement. Use the available button for the next step.', 'Question ' + currentItem.idx + '/' + state.total_questions);
      }
      return;
    }
    if (isSetupOrHoldSpeech(transcript)) {
      resetAnswerBuffer();
      awaitingAnswer = false;
      acceptAnswerAfterMaya = true;
      activeStudentBubble = null;
      sendResponse('The student is not ready or had an audio/setup issue. Do not score this. Repeat the exact current question clearly: "' + (currentItem.spoken_question || currentItem.prompt) + '"', awaitingClarification ? 'clarification' : 'question');
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
        setFinishPulse(false);
        setFinishButton('Preparing Evaluation...', true, 'wait');
        phase = 'completing';
        completeTest();
        return;
      }
      if (phase !== 'next_ready') return;
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
      setStudentAnswering(false);
      setMicEnabled(false);
      transcriptFlushUntil = Date.now() + transcriptMaxWaitMs;
      playBeep('stop');
      setFinishButton('Checking...', true, 'wait');
      setTyping('Transcribing', true);
      setStatus('Transcribing your readiness response...', 'Readiness check');
      if (submitAfterFlushTimer) clearTimeout(submitAfterFlushTimer);
      scheduleTranscriptSubmit('readiness');
      return;
    }
    if (phase === 'answering' && !answerCaptureActive) {
      setStudentAnswering(true);
      setMicEnabled(true);
      setFinishButton('STOP', false, 'recording');
      setFinishPulse(false);
      playBeep('start');
      setStatus(awaitingClarification ? 'Recording your clarification answer. Click STOP when finished.' : 'Recording your answer. Click STOP when finished.', awaitingClarification ? 'Clarification answer' : ('Question ' + currentItem.idx + '/' + state.total_questions));
      return;
    }
    if (phase === 'answering' && answerCaptureActive) {
      setStudentAnswering(false);
      setMicEnabled(false);
      transcriptFlushUntil = Date.now() + transcriptMaxWaitMs;
      playBeep('stop');
      setFinishButton('Transcribing...', true, 'wait');
      setTyping('Transcribing', true);
      setStatus('Transcribing your answer. Maya will evaluate after the text appears.', awaitingClarification ? 'Clarification answer' : ('Question ' + currentItem.idx + '/' + state.total_questions));
      if (submitAfterFlushTimer) clearTimeout(submitAfterFlushTimer);
      scheduleTranscriptSubmit('answer');
      return;
    }
  }

  function submitCurrentBufferedAnswer() {
    var finalAnswer = combinedAnswerText('');
    if (phase !== 'answering' || !currentItem || !finalAnswer || responseInProgress) {
      setTyping('', false);
      setFinishButton('START', false, 'answer');
      setFinishPulse(true);
      setStatus('I did not receive a transcript yet. Tap START and try your answer again.', awaitingClarification ? 'Clarification answer' : ('Question ' + (currentItem ? currentItem.idx : '') + '/' + state.total_questions));
      return;
    }
    if (answerSettleTimer) clearTimeout(answerSettleTimer);
    answerSettleTimer = null;
    awaitingDoneConfirmation = false;
    ignoreTranscriptsUntil = 0;
    setFinishButton('Maya Explaining...', true, 'wait');
    phase = 'evaluating';
    if (activeStudentBubble && activeStudentBubble.body) activeStudentBubble.body.textContent = finalAnswer;
    evaluateBufferedAnswer(finalAnswer);
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
    setTyping('Maya is thinking', true);
    awaitingAnswer = false;
    setCoachState('thinking');
    setStatus('Backend is evaluating the answer...', 'Evaluating');
    api('evaluate_item_answer', {
      item_id: currentItem.id,
      student_answer_text: answer,
      clarification_question_text: clarificationQ,
      clarification_answer_text: clarificationA
    }).then(function (out) {
      setTyping('', false);
      updateProgress(out.state);
      if (out.next_action === 'clarify') {
        awaitingAnswer = false;
        acceptAnswerAfterMaya = true;
        awaitingClarification = true;
        originalAnswer = answer;
        clarificationQuestion = out.feedback_for_student || 'Good start, but that is not complete yet. Say a little more.';
        activeStudentBubble = null;
        setStatus('Maya is asking one clarification.', 'Clarification');
        sendResponse('Read this exact clarification prompt and nothing else. Do not give the answer. Do not provide examples. Do not ask a different question. Do not say correct, exactly, well done, let us move on, next question, or anything about the rubric. Exact words to read: "' + clarificationQuestion + '"', 'clarification');
        return;
      }

      var scoreLine = 'You scored ' + Math.round(out.score_pct || 0) + '% on this question.';
      var feedbackForStudent = String(out.feedback_for_student || '').replace(/\brubric\b/ig, 'expected answer');

      if (out.next_action === 'complete_test') {
        completeAfterFeedback = true;
        pendingCompleteAfterFeedback = true;
        phase = 'feedback';
        setCloseTestMode('Close Test');
        sendResponse('Read this exact backend score and feedback only. Do not ask a new question, do not say thanks, do not answer the question for the student, do not mention the rubric, and do not add transition text: "' + scoreLine + ' ' + feedbackForStudent + '"', 'feedback');
      } else {
        nextQuestionAfterFeedback = true;
        pendingNextQuestionAfterFeedback = true;
        phase = 'feedback';
        sendResponse('Read this exact backend score and feedback only. Do not ask the next question, do not answer any student acknowledgement, do not mention the rubric, and do not add transition text: "' + scoreLine + ' ' + feedbackForStudent + '"', 'feedback');
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
    setCloseTestMode('Close Test');
    setCoachState('thinking');
    setStatus('Completing test through canonical attempt state...', 'Completing');
    api('complete_test', {}).then(function (out) {
      updateProgress(out.state);
      sendResponse('Say this short final progress test summary exactly and naturally: "' + (out.summary || 'Progress test complete.') + '"', 'final');
      setCoachState('ready');
      setStatus('Completed.', 'Complete');
      phase = 'completed';
      els.mute.disabled = true;
      setCloseTestMode('Close Test');
    }).catch(function (err) {
      setCoachState('error');
      setStatus('Completion failed: ' + err.message, 'Completion issue');
      addBubble('maya', 'System', 'Completion failed: ' + err.message, 'warning');
    });
  }

  function stopRealtime(notifyBackend, action) {
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
    els.mute.disabled = true;
    els.end.disabled = true;
  }

  function toggleMute() {
    if (!localStream) return;
    muted = !muted;
    setMicEnabled(answerCaptureActive);
    text(els.mute, muted ? 'Unmute Microphone' : 'Mute Microphone');
  }

  els.start.addEventListener('click', startTest);
  els.finish.addEventListener('click', finishCurrentAnswer);
  els.mute.addEventListener('click', toggleMute);
  els.end.addEventListener('click', function () {
    if (phase === 'completed' || phase === 'completing' || phase === 'final_ready' || finalEvaluationReady || completeAfterFeedback || pendingCompleteAfterFeedback || (state && parseInt(state.evaluated_count || 0, 10) >= parseInt(state.total_questions || 0, 10) && parseInt(state.total_questions || 0, 10) > 0)) {
      leaveCompletedTest();
      return;
    }
    resetAnswerBuffer();
    phase = 'aborted';
    stopRealtime(false);
    api('end_oral_test_without_penalty').then(function () {
      attemptId = 0;
      return loadQuestions();
    }).catch(function () {});
    setStatus('Test ended without penalty. Progress was reset and you can restart.', 'Voice ended');
    addBubble('maya', 'System', 'Test ended without penalty. Partial answers were reset; restart when ready.', 'warning');
    els.start.disabled = false;
    setStartPulse(true);
    els.mute.disabled = true;
    els.end.disabled = true;
  });

  loadQuestions();
})();
