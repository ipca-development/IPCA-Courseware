(function () {
  'use strict';

  var cfg = window.MOCK_ORAL_SESSION || {};
  var sessionId = cfg.sessionId || 0;
  var maxDurationSec = cfg.maxDurationSec || 300;
  var apiBase = cfg.apiBase || '/student/api';
  var turnIndex = 0;
  var sessionActive = false;
  var examStarted = false;
  var remainingSec = maxDurationSec;
  var heartbeatTimer = null;
  var countdownTimer = null;
  var isSpeaking = false;
  var isSubmitting = false;
  var heygenReady = false;
  var pendingSpeakText = '';
  var audioUnlockNeeded = false;
  var preflightData = null;
  var prepFlags = { session: false, camera: false, mic: false, avatar: false };
  var userMicMuted = false;
  var mayaMicSuppressed = false;

  var mediaStream = null;
  var answerRecorder = null;
  var answerChunks = [];
  var audioCtx = null;
  var analyser = null;
  var vadTimer = null;
  var vadSpeaking = false;
  var vadSilenceSince = 0;
  var vadLastVoiceAt = 0;
  var currentAudio = null;

  var transcriptEl = document.getElementById('moeTranscript');
  var timerFillEl = document.getElementById('moeTimerFill');
  var timerLabelEl = document.getElementById('moeTimerLabel');
  var endBtn = document.getElementById('moeEndBtn');
  var startBtn = document.getElementById('moeStartBtn');
  var prepOverlayEl = document.getElementById('moePrepOverlay');
  var prepChecklistEl = document.getElementById('moePrepChecklist');
  var typedEl = document.getElementById('moeTypedAnswer');
  var submitTypedBtn = document.getElementById('moeSubmitTyped');
  var mayaStatusEl = document.getElementById('moeMayaStatus');
  var studentStatusEl = document.getElementById('moeStudentStatus');
  var voiceBannerEl = document.getElementById('moeVoiceBanner');
  var mayaAvatarEl = document.getElementById('moeMayaAvatar');
  var heygenVideoEl = document.getElementById('moeHeygenVideo');
  var videoShellEl = document.getElementById('moeVideoShell');
  var userVideoEl = document.getElementById('moeUserVideo');
  var userPipEl = document.getElementById('moeUserPip');
  var micBtn = document.getElementById('moeMicBtn');
  var currentQuestionEl = document.getElementById('moeCurrentQuestion');
  var currentQuestionBodyEl = document.getElementById('moeCurrentQuestionBody');

  var VAD_THRESHOLD = 0.018;
  var VAD_SILENCE_MS = 1600;
  var VAD_MIN_MS = 700;

  if (!sessionId || !transcriptEl) {
    return;
  }

  function postJson(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body || {})
    }).then(function (r) {
      return r.text().then(function (text) {
        var data = {};
        try { data = text ? JSON.parse(text) : {}; } catch (e) {
          throw new Error('Unexpected server response.');
        }
        if (!r.ok || data.ok === false) {
          throw new Error(data.error || 'Request failed.');
        }
        return data;
      });
    });
  }

  function fmtTime(sec) {
    sec = Math.max(0, sec | 0);
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
  }

  function setMayaState(state, label) {
    if (mayaAvatarEl) {
      mayaAvatarEl.className = 'moe-maya-avatar-fallback moe-state-' + state;
    }
    if (mayaStatusEl) mayaStatusEl.textContent = label;
  }

  function setStudentState(label) {
    if (studentStatusEl) studentStatusEl.textContent = label;
  }

  function setVoiceBanner(mode, message) {
    if (!voiceBannerEl) return;
    if (!message) {
      voiceBannerEl.hidden = true;
      voiceBannerEl.textContent = '';
      voiceBannerEl.className = 'moe-voice-banner';
      return;
    }
    voiceBannerEl.hidden = false;
    voiceBannerEl.textContent = message;
    voiceBannerEl.className = 'moe-voice-banner moe-voice-' + mode;
  }

  function setCurrentQuestion(text) {
    text = String(text || '').trim();
    if (!currentQuestionEl || !currentQuestionBodyEl) return;
    if (!text) {
      currentQuestionEl.hidden = true;
      currentQuestionBodyEl.textContent = '';
      return;
    }
    currentQuestionEl.hidden = false;
    currentQuestionBodyEl.textContent = text;
    try {
      currentQuestionEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (e) {}
  }

  function setPrepItem(key, state) {
    prepFlags[key] = state === 'ok';
    if (!prepChecklistEl) return;
    var li = prepChecklistEl.querySelector('[data-prep="' + key + '"]');
    if (!li) return;
    li.className = state === 'ok' ? 'is-ok' : (state === 'warn' ? 'is-warn' : 'is-loading');
    var icon = li.querySelector('.moe-prep-icon');
    if (icon) {
      icon.textContent = state === 'ok' ? '✓' : (state === 'warn' ? '!' : '…');
    }
    updateStartButton();
  }

  function updateStartButton() {
    if (!startBtn) return;
    var ready = prepFlags.session && prepFlags.camera && prepFlags.mic;
    startBtn.disabled = !ready;
  }

  function updateMicUi() {
    if (!micBtn) return;
    micBtn.hidden = !examStarted;
    micBtn.classList.remove('is-listening', 'is-muted', 'is-suppressed');
    if (!examStarted) return;
    if (mayaMicSuppressed || isSpeaking || isSubmitting) {
      micBtn.classList.add('is-suppressed');
      micBtn.title = 'Microphone muted while Maya speaks';
      return;
    }
    if (userMicMuted) {
      micBtn.classList.add('is-muted');
      micBtn.title = 'Microphone muted — tap to unmute';
      return;
    }
    micBtn.classList.add('is-listening');
    micBtn.title = 'Microphone live — tap to mute';
  }

  function appendTurn(role, text, opts) {
    opts = opts || {};
    if (!text) return;
    var div = document.createElement('div');
    div.className = 'moe-turn ' + role;
    if (role === 'maya') {
      div.innerHTML = '<div class="moe-turn-label">Maya</div><div class="moe-turn-body"></div>';
      div.querySelector('.moe-turn-body').textContent = text;
    } else if (role === 'student') {
      div.innerHTML = '<div class="moe-turn-label">You</div><div class="moe-turn-body"></div>';
      div.querySelector('.moe-turn-body').textContent = text;
    } else {
      div.textContent = text;
    }
    transcriptEl.appendChild(div);
    transcriptEl.scrollTop = transcriptEl.scrollHeight;
  }

  function renderTranscript(rows) {
    transcriptEl.innerHTML = '';
    (rows || []).forEach(function (row) {
      var role = (row.role === 'maya') ? 'maya' : ((row.role === 'student') ? 'student' : 'system');
      appendTurn(role, String(row.transcript_text || row.text || ''));
    });
  }

  function updateTimer() {
    var pct = maxDurationSec > 0 ? (remainingSec / maxDurationSec) * 100 : 0;
    if (timerFillEl) {
      timerFillEl.style.width = Math.max(0, Math.min(100, pct)) + '%';
      timerFillEl.classList.toggle('is-low', remainingSec <= 60);
    }
    if (timerLabelEl) {
      timerLabelEl.textContent = fmtTime(remainingSec) + ' remaining';
    }
  }

  function startCountdown() {
    updateTimer();
    if (countdownTimer) clearInterval(countdownTimer);
    countdownTimer = setInterval(function () {
      remainingSec -= 1;
      updateTimer();
      if (remainingSec <= 0) {
        clearInterval(countdownTimer);
        completeSession(true);
      }
    }, 1000);
  }

  function heartbeat() {
    postJson(apiBase + '/mock_oral.php', { action: 'heartbeat', session_id: sessionId })
      .then(function (res) {
        if (res.remaining_sec != null) {
          remainingSec = Math.min(remainingSec, parseInt(res.remaining_sec, 10) || remainingSec);
          updateTimer();
        }
        if (res.status === 'completed') {
          goDebrief();
        }
      })
      .catch(function () {});
  }

  function goDebrief() {
    window.location.href = window.location.pathname + '?cohort_id=' + cfg.cohortId + '&area_id=' + cfg.areaId + '&session_id=' + sessionId + '&view=debrief';
  }

  function stopCurrentAudio() {
    if (currentAudio) {
      try { currentAudio.pause(); } catch (e) {}
      currentAudio = null;
    }
    if ('speechSynthesis' in window) {
      try { window.speechSynthesis.cancel(); } catch (e) {}
    }
  }

  function showAudioUnlockPrompt(text) {
    pendingSpeakText = String(text || pendingSpeakText || '').trim();
    audioUnlockNeeded = pendingSpeakText !== '';
    if (videoShellEl) videoShellEl.classList.toggle('is-audio-blocked', audioUnlockNeeded);
    if (audioUnlockNeeded) {
      setVoiceBanner('prompt', 'Tap Maya\'s video once to hear her question.');
      setStudentState('Tap the video once to enable audio.');
    }
  }

  function clearAudioUnlockPrompt() {
    audioUnlockNeeded = false;
    pendingSpeakText = '';
    if (videoShellEl) videoShellEl.classList.remove('is-audio-blocked');
  }

  function replayPendingSpeak() {
    if (!pendingSpeakText) return Promise.resolve(false);
    var text = pendingSpeakText;
    clearAudioUnlockPrompt();
    return speakMaya(text, { append: false, skipUnlockPrompt: true });
  }

  function speakBrowserFallback(text) {
    return new Promise(function (resolve) {
      if (!('speechSynthesis' in window)) {
        resolve(false);
        return;
      }
      var u = new SpeechSynthesisUtterance(text);
      u.rate = 1;
      u.onend = function () {
        setVoiceBanner('fallback', 'Browser Voice Mode Active');
        resolve(true);
      };
      u.onerror = function () { resolve(false); };
      window.speechSynthesis.speak(u);
    });
  }

  function speakOpenAiTts(text) {
    var ttsUrl = apiBase + '/mock_oral_tts.php?session_id=' + encodeURIComponent(String(sessionId))
      + '&text=' + encodeURIComponent(text);

    return fetch(ttsUrl, { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('TTS unavailable');
        return r.blob();
      })
      .then(function (blob) {
        if (!blob || blob.size < 32) throw new Error('Empty TTS');
        return new Promise(function (resolve) {
          currentAudio = new Audio(URL.createObjectURL(blob));
          currentAudio.onended = function () { resolve(true); };
          currentAudio.onerror = function () { resolve(false); };
          var play = currentAudio.play();
          if (play && typeof play.then === 'function') {
            play.then(function () {
              setVoiceBanner('openai', 'Maya AI Voice');
              resolve(true);
            }).catch(function () { resolve(false); });
          } else {
            setVoiceBanner('openai', 'Maya AI Voice');
            resolve(true);
          }
        });
      })
      .catch(function () { return false; });
  }

  function speakViaHeygen(text) {
    if (!heygenReady || !window.MoeHeyGenPresenter || !MoeHeyGenPresenter.isReady()) {
      return Promise.resolve(false);
    }
    return MoeHeyGenPresenter.speak(text).then(function () {
      setVoiceBanner('heygen', 'Maya Live Avatar · LiveAvatar');
      return true;
    }).catch(function () {
      heygenReady = false;
      return false;
    });
  }

  function setMayaSpeaking(on) {
    isSpeaking = on;
    mayaMicSuppressed = on;
    if (on) {
      stopAnswerCapture();
    }
    updateMicUi();
  }

  function speakMaya(text, opts) {
    opts = opts || {};
    if (!text) return Promise.resolve();
    setCurrentQuestion(text);
    if (opts.append !== false) {
      appendTurn('maya', text);
    }
    setMayaState('speaking', 'Maya is speaking…');
    setStudentState('Listen to Maya…');
    setMayaSpeaking(true);
    clearAudioUnlockPrompt();
    stopCurrentAudio();

    return speakViaHeygen(text)
      .then(function (usedHeygen) {
        if (usedHeygen) return true;
        if (heygenVideoEl) heygenVideoEl.muted = true;
        return speakOpenAiTts(text);
      })
      .then(function (spoken) {
        if (heygenVideoEl && heygenReady) heygenVideoEl.muted = false;
        if (spoken) return true;
        if (!opts.skipUnlockPrompt) {
          showAudioUnlockPrompt(text);
          return true;
        }
        return speakBrowserFallback(text);
      })
      .then(function (spoken) {
        if (!spoken && opts.skipUnlockPrompt) {
          showAudioUnlockPrompt(text);
        }
        return spoken;
      })
      .then(function () {
        setMayaSpeaking(false);
        setMayaState('listening', 'Maya is listening');
        if (audioUnlockNeeded) {
          setStudentState('Tap the video once to hear Maya, then speak your answer.');
          return;
        }
        setStudentState('Speak naturally — your microphone is live.');
        if (examStarted && !userMicMuted) {
          startContinuousListening();
        }
      });
  }

  function latestMayaText(rows) {
    var text = '';
    (rows || []).forEach(function (row) {
      if ((row.role || '') === 'maya') {
        text = String(row.transcript_text || row.text || '');
      }
    });
    return text.trim();
  }

  function mayaPromptToSpeak(res) {
    if (res.opening && res.opening.maya_text && !res.resumed) {
      return String(res.opening.maya_text);
    }
    if (res.resumed && Array.isArray(res.transcript) && res.transcript.length) {
      return latestMayaText(res.transcript);
    }
    return '';
  }

  function setControlsEnabled(on) {
    submitTypedBtn.disabled = !on || isSpeaking || isSubmitting;
    endBtn.disabled = !on;
  }

  function submitAnswer(text) {
    text = String(text || '').trim();
    if (!text || !sessionActive || isSubmitting) return Promise.resolve();

    isSubmitting = true;
    stopAnswerCapture();
    updateMicUi();
    appendTurn('student', text);
    setCurrentQuestion('');
    setMayaState('thinking', 'Maya is thinking…');
    setStudentState('Evaluating your answer…');
    setControlsEnabled(false);

    return postJson(apiBase + '/mock_oral.php', {
      action: 'submit_turn',
      session_id: sessionId,
      turn_index: turnIndex,
      transcript: text
    }).then(function (res) {
      if (res.forced_complete) {
        goDebrief();
        return;
      }
      turnIndex = res.next_turn_index != null ? parseInt(res.next_turn_index, 10) : turnIndex;
      var mayaText = res.maya_response && res.maya_response.maya_text ? res.maya_response.maya_text : '';
      if (mayaText) {
        return speakMaya(mayaText);
      }
      setMayaState('listening', 'Maya is listening');
      setStudentState('Speak naturally — your microphone is live.');
      if (examStarted && !userMicMuted) startContinuousListening();
    }).catch(function (e) {
      appendTurn('system', e.message || 'Could not evaluate your answer.');
      setMayaState('listening', 'Maya is listening');
      setStudentState('Try speaking your answer again.');
      if (examStarted && !userMicMuted) startContinuousListening();
    }).then(function () {
      isSubmitting = false;
      updateMicUi();
      if (sessionActive) setControlsEnabled(true);
      if (typedEl) typedEl.value = '';
    });
  }

  function transcribeBlob(blob) {
    var fd = new FormData();
    fd.append('audio', blob, 'answer.webm');
    fd.append('lang', 'en');
    return fetch(apiBase + '/asr.php', { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Transcription failed.');
        var text = String(j.text || j.transcript || '').trim();
        if (!text) throw new Error('No speech detected.');
        return text;
      });
  }

  function getAudioLevel() {
    if (!analyser) return 0;
    var data = new Uint8Array(analyser.fftSize);
    analyser.getByteTimeDomainData(data);
    var sum = 0;
    for (var i = 0; i < data.length; i++) {
      var v = (data[i] - 128) / 128;
      sum += v * v;
    }
    return Math.sqrt(sum / data.length);
  }

  function startAnswerRecorder() {
    if (!mediaStream || answerRecorder || userMicMuted || mayaMicSuppressed || isSpeaking || isSubmitting) {
      return;
    }
    answerChunks = [];
    var mime = MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : 'audio/mp4';
    answerRecorder = new MediaRecorder(mediaStream, { mimeType: mime });
    answerRecorder.ondataavailable = function (ev) {
      if (ev.data && ev.data.size > 0) answerChunks.push(ev.data);
    };
    answerRecorder.onstop = function () {
      var recorder = answerRecorder;
      answerRecorder = null;
      var blob = new Blob(answerChunks, { type: recorder.mimeType || 'audio/webm' });
      answerChunks = [];
      if (blob.size < 800) return;
      setStudentState('Transcribing your answer…');
      transcribeBlob(blob)
        .then(function (text) { return submitAnswer(text); })
        .catch(function (e) {
          appendTurn('system', e.message || 'Could not transcribe speech.');
          setStudentState('Speak your answer again when ready.');
          if (examStarted && !userMicMuted) startContinuousListening();
        });
    };
    answerRecorder.start(250);
    vadLastVoiceAt = Date.now();
  }

  function stopAnswerCapture() {
    vadSpeaking = false;
    vadSilenceSince = 0;
    if (answerRecorder && answerRecorder.state !== 'inactive') {
      answerRecorder.stop();
    } else {
      answerRecorder = null;
      answerChunks = [];
    }
  }

  function startContinuousListening() {
    if (!examStarted || userMicMuted || mayaMicSuppressed || isSpeaking || isSubmitting || !mediaStream) {
      return;
    }
    if (vadTimer) return;

    if (!audioCtx) {
      audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      analyser = audioCtx.createAnalyser();
      analyser.fftSize = 2048;
      audioCtx.createMediaStreamSource(mediaStream).connect(analyser);
    }
    if (audioCtx.state === 'suspended') {
      audioCtx.resume().catch(function () {});
    }

    vadTimer = setInterval(function () {
      if (!examStarted || userMicMuted || mayaMicSuppressed || isSpeaking || isSubmitting) {
        if (vadSpeaking) stopAnswerCapture();
        return;
      }
      var level = getAudioLevel();
      var now = Date.now();
      if (level > VAD_THRESHOLD) {
        if (!vadSpeaking) {
          vadSpeaking = true;
          vadSilenceSince = 0;
          startAnswerRecorder();
        }
        vadLastVoiceAt = now;
        vadSilenceSince = 0;
      } else if (vadSpeaking) {
        if (!vadSilenceSince) vadSilenceSince = now;
        if (now - vadSilenceSince > VAD_SILENCE_MS && now - vadLastVoiceAt > VAD_MIN_MS) {
          vadSpeaking = false;
          vadSilenceSince = 0;
          stopAnswerCapture();
        }
      }
    }, 120);
  }

  function stopContinuousListening() {
    if (vadTimer) {
      clearInterval(vadTimer);
      vadTimer = null;
    }
    stopAnswerCapture();
  }

  function initUserMedia() {
    return navigator.mediaDevices.getUserMedia({ audio: true, video: { facingMode: 'user', width: 640, height: 480 } })
      .then(function (stream) {
        mediaStream = stream;
        if (userVideoEl) {
          userVideoEl.srcObject = stream;
          userVideoEl.play().catch(function () {});
        }
        if (userPipEl) userPipEl.hidden = false;
        setPrepItem('camera', 'ok');
        setPrepItem('mic', 'ok');
        return true;
      })
      .catch(function () {
        setPrepItem('camera', 'warn');
        setPrepItem('mic', 'warn');
        appendTurn('system', 'Camera and microphone access is required for the live oral exam.');
        return false;
      });
  }

  function initHeyGenAvatar(heygen) {
    if (!heygen || heygen.presentation_mode !== 'heygen' || !heygen.token) {
      if (heygen && heygen.message) {
        setVoiceBanner('warn', heygen.message);
      } else {
        setVoiceBanner('openai', 'Maya AI Voice (avatar fallback)');
      }
      return Promise.resolve(false);
    }
    if (!window.MoeHeyGenPresenter) {
      return Promise.reject(new Error('Live avatar script not loaded.'));
    }

    return MoeHeyGenPresenter.init({
      token: heygen.token,
      avatarId: heygen.avatar_id,
      voiceId: heygen.voice_id || '',
      quality: heygen.quality || 'high',
      videoEl: heygenVideoEl,
      activityIdleTimeoutSec: heygen.activity_idle_timeout_sec || 600,
    }).then(function () {
      heygenReady = true;
      if (heygenVideoEl) {
        heygenVideoEl.hidden = false;
        heygenVideoEl.muted = false;
      }
      if (mayaAvatarEl) mayaAvatarEl.hidden = true;
      setVoiceBanner('heygen', 'Maya Live Avatar · LiveAvatar');
      return true;
    }).catch(function (e) {
      heygenReady = false;
      setVoiceBanner('warn', (e && e.message) ? e.message : 'Live avatar unavailable.');
      return false;
    });
  }

  function connectMayaAvatar(heygen) {
    setMayaState('connecting', 'Connecting Maya live avatar…');
    var resetPromise = (window.MoeHeyGenPresenter && MoeHeyGenPresenter.reset)
      ? MoeHeyGenPresenter.reset()
      : Promise.resolve();
    return resetPromise.then(function () {
      heygenReady = false;
      if (mayaAvatarEl) mayaAvatarEl.hidden = false;
      if (heygenVideoEl) heygenVideoEl.hidden = true;
      return initHeyGenAvatar(heygen || {});
    });
  }

  function completeSession() {
    sessionActive = false;
    examStarted = false;
    setControlsEnabled(false);
    stopContinuousListening();
    stopCurrentAudio();
    if (window.MoeHeyGenPresenter && MoeHeyGenPresenter.isReady()) {
      MoeHeyGenPresenter.stop().catch(function () {});
      heygenReady = false;
    }
    if (mediaStream) {
      mediaStream.getTracks().forEach(function (t) { t.stop(); });
      mediaStream = null;
    }
    if (countdownTimer) clearInterval(countdownTimer);
    if (heartbeatTimer) clearInterval(heartbeatTimer);
    setMayaState('idle', 'Ending session…');
    setStudentState('Generating debrief…');
    postJson(apiBase + '/mock_oral.php', { action: 'complete_session', session_id: sessionId })
      .then(goDebrief)
      .catch(goDebrief);
  }

  function inferNextTurnFromTranscript(rows) {
    var lastMaya = 0;
    var lastStudent = -1;
    (rows || []).forEach(function (row) {
      var t = parseInt(row.turn_index, 10) || 0;
      if (row.role === 'maya') lastMaya = Math.max(lastMaya, t);
      if (row.role === 'student') lastStudent = Math.max(lastStudent, t);
    });
    return lastStudent < lastMaya ? lastMaya : lastMaya + 1;
  }

  function beginLiveExam(sessionResult) {
    sessionActive = true;
    examStarted = true;
    if (prepOverlayEl) prepOverlayEl.classList.add('is-hidden');
    if (micBtn) micBtn.hidden = false;
    userMicMuted = false;
    updateMicUi();

    if (Array.isArray(sessionResult.transcript) && sessionResult.transcript.length) {
      renderTranscript(sessionResult.transcript);
      turnIndex = sessionResult.next_turn_index != null
        ? parseInt(sessionResult.next_turn_index, 10)
        : inferNextTurnFromTranscript(sessionResult.transcript);
    }

    startCountdown();
    heartbeatTimer = setInterval(heartbeat, 30000);
    endBtn.disabled = false;
    setControlsEnabled(true);

    var promptText = mayaPromptToSpeak(sessionResult);
    if (promptText) {
      turnIndex = sessionResult.next_turn_index != null ? parseInt(sessionResult.next_turn_index, 10) : 0;
      var alreadyInTranscript = (Array.isArray(sessionResult.transcript) && sessionResult.transcript.length > 0)
        || (sessionResult.opening && sessionResult.opening.maya_text && !sessionResult.resumed);
      return speakMaya(promptText, { append: !alreadyInTranscript });
    }

    setMayaState('listening', 'Maya is listening');
    setStudentState('Speak naturally — your microphone is live.');
    startContinuousListening();
  }

  function onStartExam() {
    if (!preflightData || startBtn.disabled) return;
    startBtn.disabled = true;
    setStudentState('Connecting Maya and starting your oral exam…');
    setMayaState('connecting', 'Connecting Maya…');

    postJson(apiBase + '/mock_oral.php', { action: 'session_preflight', session_id: sessionId })
      .then(function (pf) {
        preflightData = pf;
        if (pf.resumed) {
          return pf;
        }
        return postJson(apiBase + '/mock_oral.php', { action: 'start_session', session_id: sessionId });
      })
      .then(function (res) {
        return connectMayaAvatar(res.heygen || {}).then(function () {
          return res;
        });
      })
      .then(function (res) {
        return beginLiveExam(res);
      })
      .catch(function (e) {
        startBtn.disabled = false;
        appendTurn('system', e.message || 'Unable to start session.');
        setMayaState('idle', 'Session unavailable');
        setStudentState('Return to the mock oral page and try again.');
      });
  }

  function prepareExam() {
    setMayaState('connecting', 'Preparing…');
    setStudentState('Loading camera and microphone…');
    updateTimer();
    setPrepItem('session', 'loading');
    setPrepItem('camera', 'loading');
    setPrepItem('mic', 'loading');
    setPrepItem('avatar', 'loading');

    var mediaPromise = initUserMedia().catch(function () { return false; });
    var preflightPromise = postJson(apiBase + '/mock_oral.php', { action: 'session_preflight', session_id: sessionId })
      .then(function (res) {
        preflightData = res;
        setPrepItem('session', 'ok');
        return res;
      })
      .catch(function (e) {
        setPrepItem('session', 'warn');
        throw e;
      });

    Promise.all([mediaPromise, preflightPromise])
      .then(function (results) {
        if (!results[0]) {
          setStudentState('Allow camera and microphone access, then reload this page.');
          return;
        }
        if (window.MoeHeyGenPresenter && window.LiveAvatarSdk) {
          setPrepItem('avatar', 'ok');
        } else {
          setPrepItem('avatar', 'warn');
        }
        setMayaState('idle', 'Ready to begin');
        setStudentState('Press Start Oral Exam when you are ready.');
      })
      .catch(function (e) {
        appendTurn('system', e.message || 'Unable to prepare session.');
        setMayaState('idle', 'Preparation failed');
        setStudentState('Return to the mock oral page and try again.');
      });
  }

  if (videoShellEl) {
    videoShellEl.addEventListener('click', function () {
      if (audioUnlockNeeded && pendingSpeakText) {
        replayPendingSpeak();
      }
    });
  }

  if (micBtn) {
    micBtn.addEventListener('click', function () {
      if (!examStarted || mayaMicSuppressed || isSpeaking || isSubmitting) return;
      userMicMuted = !userMicMuted;
      if (userMicMuted) {
        stopContinuousListening();
        setStudentState('Microphone muted — tap the mic icon to unmute.');
      } else {
        setStudentState('Microphone live — speak naturally.');
        startContinuousListening();
      }
      updateMicUi();
    });
  }

  if (startBtn) startBtn.addEventListener('click', onStartExam);
  submitTypedBtn.addEventListener('click', function () {
    submitAnswer(String(typedEl.value || '').trim());
  });
  endBtn.addEventListener('click', completeSession);

  prepareExam();
})();
