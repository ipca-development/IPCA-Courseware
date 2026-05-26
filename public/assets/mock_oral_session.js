(function () {
  'use strict';

  var cfg = window.MOCK_ORAL_SESSION || {};
  var sessionId = cfg.sessionId || 0;
  var maxDurationSec = cfg.maxDurationSec || 300;
  var apiBase = cfg.apiBase || '/student/api';
  var turnIndex = 0;
  var sessionActive = false;
  var remainingSec = maxDurationSec;
  var heartbeatTimer = null;
  var countdownTimer = null;
  var mediaRecorder = null;
  var mediaStream = null;
  var audioChunks = [];
  var isRecording = false;
  var isSpeaking = false;
  var voiceMode = 'connecting';
  var currentAudio = null;

  var transcriptEl = document.getElementById('moeTranscript');
  var timerEl = document.getElementById('moeTimer');
  var answerBtn = document.getElementById('moeAnswerBtn');
  var endBtn = document.getElementById('moeEndBtn');
  var typedEl = document.getElementById('moeTypedAnswer');
  var submitTypedBtn = document.getElementById('moeSubmitTyped');
  var mayaStatusEl = document.getElementById('moeMayaStatus');
  var studentStatusEl = document.getElementById('moeStudentStatus');
  var voiceBannerEl = document.getElementById('moeVoiceBanner');
  var mayaAvatarEl = document.getElementById('moeMayaAvatar');
  var heygenVideoEl = document.getElementById('moeHeygenVideo');

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
    voiceMode = mode;
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
    if (opts.replaceLastStudent && transcriptEl.lastElementChild && transcriptEl.lastElementChild.classList.contains('student')) {
      transcriptEl.removeChild(transcriptEl.lastElementChild);
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
    if (timerEl) timerEl.textContent = 'Time remaining: ' + fmtTime(remainingSec);
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

  function speakBrowserFallback(text) {
    return new Promise(function (resolve) {
      if (!('speechSynthesis' in window)) {
        resolve(false);
        return;
      }
      var u = new SpeechSynthesisUtterance(text);
      u.rate = 1;
      u.onend = function () { resolve(true); };
      u.onerror = function () { resolve(false); };
      window.speechSynthesis.speak(u);
    });
  }

  function speakMaya(text, opts) {
    opts = opts || {};
    if (!text) return Promise.resolve();
    if (opts.append !== false) {
      appendTurn('maya', text);
    }
    setMayaState('speaking', 'Maya is speaking…');
    setStudentState('Listen to Maya…');
    isSpeaking = true;
    answerBtn.disabled = true;

    stopCurrentAudio();

    var ttsUrl = apiBase + '/mock_oral_tts.php?session_id=' + encodeURIComponent(String(sessionId))
      + '&text=' + encodeURIComponent(text);

    return fetch(ttsUrl, { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('TTS unavailable');
        return r.blob();
      })
      .then(function (blob) {
        if (!blob || blob.size < 32) throw new Error('Empty TTS');
        setVoiceBanner('openai', 'Maya AI Voice');
        return new Promise(function (resolve) {
          currentAudio = new Audio(URL.createObjectURL(blob));
          currentAudio.onended = function () { resolve(true); };
          currentAudio.onerror = function () { resolve(false); };
          currentAudio.play().catch(function () { resolve(false); });
        });
      })
      .catch(function () {
        setVoiceBanner('fallback', 'Fallback Voice Mode Active');
        return speakBrowserFallback(text);
      })
      .then(function () {
        isSpeaking = false;
        setMayaState('listening', 'Maya is listening');
        setStudentState('Tap to Answer when you are ready.');
        if (sessionActive) answerBtn.disabled = false;
      });
  }

  function setControlsEnabled(on) {
    answerBtn.disabled = !on || isSpeaking || isRecording;
    submitTypedBtn.disabled = !on || isSpeaking;
    endBtn.disabled = !on;
  }

  function submitAnswer(text) {
    text = String(text || '').trim();
    if (!text || !sessionActive) return Promise.resolve();

    appendTurn('student', text);
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
      setStudentState('Tap to Answer when you are ready.');
    }).catch(function (e) {
      appendTurn('system', e.message || 'Could not evaluate your answer.');
      setMayaState('listening', 'Maya is listening');
      setStudentState('Try again when ready.');
    }).then(function () {
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
        if (!text) throw new Error('No speech detected. Try again.');
        return text;
      });
  }

  function stopRecording() {
    if (!mediaRecorder || mediaRecorder.state === 'inactive') return;
    mediaRecorder.stop();
  }

  function startRecording() {
    if (!sessionActive || isSpeaking || isRecording) return;

    navigator.mediaDevices.getUserMedia({ audio: true })
      .then(function (stream) {
        mediaStream = stream;
        audioChunks = [];
        mediaRecorder = new MediaRecorder(stream, { mimeType: MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : 'audio/mp4' });
        mediaRecorder.ondataavailable = function (ev) {
          if (ev.data && ev.data.size > 0) audioChunks.push(ev.data);
        };
        mediaRecorder.onstop = function () {
          isRecording = false;
          answerBtn.classList.remove('is-recording');
          answerBtn.textContent = 'Tap to Answer';
          if (mediaStream) {
            mediaStream.getTracks().forEach(function (t) { t.stop(); });
            mediaStream = null;
          }
          var blob = new Blob(audioChunks, { type: mediaRecorder.mimeType || 'audio/webm' });
          audioChunks = [];
          if (blob.size < 800) {
            setStudentState('Recording too short. Tap to Answer and try again.');
            setControlsEnabled(true);
            return;
          }
          setStudentState('Transcribing your answer…');
          transcribeBlob(blob)
            .then(function (text) { return submitAnswer(text); })
            .catch(function (e) {
              appendTurn('system', e.message || 'Could not transcribe speech.');
              setStudentState('Tap to Answer and try again.');
              setControlsEnabled(true);
            });
        };
        mediaRecorder.start();
        isRecording = true;
        answerBtn.classList.add('is-recording');
        answerBtn.textContent = 'Tap to Stop';
        setStudentState('Recording… tap again when finished.');
      })
      .catch(function () {
        appendTurn('system', 'Microphone access is required for spoken answers.');
        setStudentState('Use typed answer below or allow microphone access.');
        setControlsEnabled(true);
      });
  }

  function toggleRecording() {
    if (isRecording) {
      stopRecording();
    } else {
      startRecording();
    }
  }

  function completeSession() {
    sessionActive = false;
    setControlsEnabled(false);
    stopCurrentAudio();
    if (isRecording) stopRecording();
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

  function bootSession() {
    setMayaState('connecting', 'Connecting…');
    setStudentState('Preparing your oral exam conversation…');
    setControlsEnabled(false);

    postJson(apiBase + '/mock_oral.php', { action: 'start_session', session_id: sessionId })
      .then(function (res) {
        sessionActive = true;
        if (Array.isArray(res.transcript) && res.transcript.length) {
          renderTranscript(res.transcript);
          turnIndex = res.next_turn_index != null ? parseInt(res.next_turn_index, 10) : inferNextTurnFromTranscript(res.transcript);
        } else if (res.opening && res.opening.maya_text && !res.resumed) {
          appendTurn('maya', res.opening.maya_text);
        }

        var heygen = res.heygen || {};
        if (heygen.presentation_mode === 'heygen' && heygen.token) {
          setVoiceBanner('heygen', 'Maya Avatar Voice');
          if (heygenVideoEl) heygenVideoEl.hidden = false;
          if (mayaAvatarEl) mayaAvatarEl.hidden = true;
        }

        startCountdown();
        heartbeatTimer = setInterval(heartbeat, 30000);
        endBtn.disabled = false;

        if (res.opening && res.opening.maya_text && !res.resumed) {
          turnIndex = res.next_turn_index != null ? parseInt(res.next_turn_index, 10) : 0;
          return speakMaya(res.opening.maya_text, { append: false });
        }

        setMayaState('listening', 'Maya is listening');
        setStudentState('Tap to Answer when you are ready.');
        setControlsEnabled(true);
      })
      .catch(function (e) {
        appendTurn('system', e.message || 'Unable to start session.');
        setMayaState('idle', 'Session unavailable');
        setStudentState('Return to the mock oral page and try again.');
      });
  }

  answerBtn.addEventListener('click', toggleRecording);
  submitTypedBtn.addEventListener('click', function () {
    submitAnswer(String(typedEl.value || '').trim());
  });
  endBtn.addEventListener('click', completeSession);

  bootSession();
})();
