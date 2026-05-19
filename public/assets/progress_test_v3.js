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
    submitText: $('[data-ptv3-submit-text]')
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
    body.textContent = message || '';
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
    var evaluated = parseInt(state.evaluated_count || 0, 10) || 0;
    var idx = parseInt(state.current_idx || 0, 10) || 0;
    var scoreProgress = state.score_progress == null ? '--' : String(state.score_progress) + '%';
    var finalScore = state.score_pct == null ? scoreProgress : String(state.score_pct) + '%';
    text(els.title, 'Progress Test V3');
    text(els.attempt, 'Attempt status: ' + (state.formal_result_label || state.attempt_status || state.status || 'Ready'));
    text(els.score, 'Score: ' + finalScore);
    text(els.ready, String(state.ready_questions || total || 0));
    text(els.current, total ? (idx + '/' + total) : '0/0');
    text(els.evaluated, String(evaluated));
    text(els.final, finalScore);
    if (els.bar) {
      var pct = total ? Math.round((evaluated / total) * 100) : 0;
      els.bar.style.width = clamp(pct, 0, 100) + '%';
    }
    currentItem = null;
    (state.items || []).forEach(function (item) {
      if (parseInt(item.id, 10) === parseInt(state.current_item_id, 10)) currentItem = item;
    });
  }

  function loadQuestions() {
    setCoachState('thinking');
    setStatus('Generating/loading questions. Microphone is closed.', 'Preparing questions');
    return api('start_oral_test', {
      cohort_id: parseInt(cfg.cohortId || 0, 10),
      lesson_id: parseInt(cfg.lessonId || 0, 10)
    }).then(function (out) {
      updateProgress(out.state);
      setCoachState('ready');
      setStatus('Ready to start. Microphone is still closed.', 'Ready to start');
      els.start.disabled = false;
      els.textMode.disabled = false;
      addBubble('maya', 'Maya', 'Ready to start. I have loaded your generated progress test questions. Click Start Progress Test when you are ready.', 'greeting');
    }).catch(function (err) {
      setCoachState('error');
      setStatus('Could not prepare progress test: ' + err.message, 'Preparation failed');
      addBubble('maya', 'System', 'Could not prepare progress test: ' + err.message, 'warning');
    });
  }

  function connectRealtime(clientSecret, endpoint) {
    pc = new RTCPeerConnection();
    remoteAudio = document.createElement('audio');
    remoteAudio.autoplay = true;
    remoteAudio.setAttribute('playsinline', 'playsinline');
    pc.ontrack = function (event) {
      remoteAudio.srcObject = event.streams[0];
      setCoachState('thinking');
    };
    pc.onconnectionstatechange = function () {
      if (pc.connectionState === 'failed' || pc.connectionState === 'disconnected') {
        handleDisconnect();
      }
    };
    dc = pc.createDataChannel('oai-events');
    dc.onmessage = handleRealtimeEvent;
    dc.onopen = function () { setStatus('Connected. Maya will start now.', 'Realtime connected'); };
    dc.onerror = function () { handleDisconnect(); };
    dc.onclose = function () { if (connected) handleDisconnect(); };

    return navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      localStream = stream;
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
    });
  }

  function startTest() {
    if (connected || !attemptId) return;
    els.start.disabled = true;
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
      askCurrentQuestion();
    }).catch(function (err) {
      els.start.disabled = false;
      els.textMode.disabled = false;
      setCoachState('error');
      setStatus('Voice failed. Continue in text mode is available.', 'Voice unavailable');
      addBubble('maya', 'System', 'Voice failed: ' + err.message + '\nYou can continue in text mode without penalty.', 'warning');
      api('abort_voice_session_without_penalty', {}).catch(function () {});
    });
  }

  function sendResponse(instructions) {
    if (!dc || dc.readyState !== 'open') return;
    if (responseInProgress) {
      pendingInstructions = instructions;
      return;
    }
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
    responseInProgress = false;
    if (pendingInstructions) {
      var next = pendingInstructions;
      pendingInstructions = '';
      sendResponse(next);
    }
  }

  function askCurrentQuestion() {
    if (!currentItem) return;
    awaitingAnswer = true;
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
    sendResponse('Ask exactly this progress test question, naturally and briefly, then stop speaking and listen for the answer: "' + (currentItem.spoken_question || currentItem.prompt) + '"');
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
      if (awaitingAnswer) setCoachState('ready');
    }
  }

  function transcriptKey(msg) {
    return String(msg.event_id || msg.item_id || msg.response_id || msg.transcript || '').slice(0, 260);
  }

  function handleStudentTranscript(msg) {
    var transcript = String(msg.transcript || '').trim();
    if (!transcript || !awaitingAnswer || !currentItem) return;
    var key = transcriptKey(msg);
    if (processedTranscripts[key]) return;
    processedTranscripts[key] = true;
    if (activeStudentBubble) {
      activeStudentBubble.body.textContent = transcript;
    } else {
      activeStudentBubble = addBubble('student', 'Student', transcript, 'answer');
    }
    api('save_transcript_segment', {
      item_id: currentItem.id,
      role: 'student',
      event_type: awaitingClarification ? 'clarification_answer' : 'answer',
      transcript_text: transcript
    }).catch(function () {});

    if (awaitingClarification) {
      evaluateAnswer(originalAnswer, clarificationQuestion, transcript);
    } else {
      evaluateAnswer(transcript, '', '');
    }
  }

  function evaluateAnswer(answer, clarificationQ, clarificationA) {
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
        awaitingAnswer = true;
        awaitingClarification = true;
        originalAnswer = answer;
        clarificationQuestion = out.feedback_for_student || 'Good start, but that is not complete yet. Say a little more.';
        activeStudentBubble = null;
        addBubble('maya', 'Maya', clarificationQuestion, 'warning');
        setStatus('Maya is asking one clarification.', 'Clarification');
        sendResponse('Ask this one clarification only, without giving away the answer: "' + clarificationQuestion + '" Then stop speaking and listen.');
        return;
      }

      var scoreLine = 'You scored ' + Math.round(out.score_pct || 0) + '% on this question.';
      addBubble('maya', 'Maya', scoreLine + '\n' + (out.feedback_for_student || ''), 'score');
      sendResponse('Say this backend score and feedback concisely. Do not add your own score: "' + scoreLine + ' ' + (out.feedback_for_student || '') + '"');

      if (out.next_action === 'complete_test') {
        completeTest();
      } else {
        window.setTimeout(function () {
          addBubble('maya', 'Maya', 'OK, let’s go to question ' + (state.current_idx || '') + '.', 'transition');
          askCurrentQuestion();
        }, 900);
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
      sendResponse('Say this short final progress test summary exactly and naturally: "' + (out.summary || 'Progress test complete.') + '"');
      setCoachState('ready');
      setStatus('Completed.', 'Complete');
      els.mute.disabled = true;
      els.end.disabled = true;
      els.textMode.disabled = true;
      stopRealtime(false);
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
