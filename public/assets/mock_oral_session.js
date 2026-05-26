(function () {
  var cfg = window.MOCK_ORAL_SESSION || {};
  var sessionId = cfg.sessionId || 0;
  var maxDurationSec = cfg.maxDurationSec || 300;
  var apiBase = cfg.apiBase || '/student/api';
  var turnIndex = 0;
  var started = false;
  var remainingSec = maxDurationSec;
  var heartbeatTimer = null;
  var countdownTimer = null;
  var recognition = null;

  var transcriptEl = document.getElementById('moeTranscript');
  var timerEl = document.getElementById('moeTimer');
  var startBtn = document.getElementById('moeStartBtn');
  var listenBtn = document.getElementById('moeListenBtn');
  var endBtn = document.getElementById('moeEndBtn');
  var typedEl = document.getElementById('moeTypedAnswer');
  var submitTypedBtn = document.getElementById('moeSubmitTyped');
  var focusList = document.getElementById('moeFocusList');

  (cfg.focusAreas || []).forEach(function (item) {
    var li = document.createElement('li');
    li.textContent = String(item);
    focusList.appendChild(li);
  });

  function postJson(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json(); });
  }

  function fmtTime(sec) {
    sec = Math.max(0, sec | 0);
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
  }

  function appendTurn(role, text) {
    var div = document.createElement('div');
    div.className = 'moe-turn ' + role;
    div.textContent = text;
    transcriptEl.appendChild(div);
    transcriptEl.scrollTop = transcriptEl.scrollHeight;
  }

  function updateTimer() {
    timerEl.textContent = 'Time remaining: ' + fmtTime(remainingSec);
  }

  function speak(text) {
    if (!text) return;
    appendTurn('maya', text);
    if ('speechSynthesis' in window) {
      var u = new SpeechSynthesisUtterance(text);
      u.rate = 1;
      window.speechSynthesis.speak(u);
    }
  }

  function startCountdown() {
    updateTimer();
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
        if (res.status === 'completed') {
          window.location.href = window.location.pathname + window.location.search + '&view=debrief';
        }
      })
      .catch(function () {});
  }

  function submitAnswer(text) {
    if (!text) return;
    appendTurn('student', text);
    submitTypedBtn.disabled = true;
    listenBtn.disabled = true;
    return postJson(apiBase + '/mock_oral.php', {
      action: 'submit_turn',
      session_id: sessionId,
      turn_index: turnIndex,
      transcript: text
    }        ).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Evaluation failed');
      if (res.forced_complete) {
        window.location.href = window.location.pathname + '?cohort_id=' + cfg.cohortId + '&area_id=' + cfg.areaId + '&session_id=' + sessionId + '&view=debrief';
        return;
      }
      if (res.maya_response && res.maya_response.maya_text) {
        speak(res.maya_response.maya_text);
        turnIndex = res.next_turn_index != null ? res.next_turn_index : (turnIndex + 1);
      }
      submitTypedBtn.disabled = false;
      listenBtn.disabled = false;
      typedEl.value = '';
    }).catch(function (e) {
      appendTurn('system', e.message || 'Evaluation failed');
      submitTypedBtn.disabled = false;
      listenBtn.disabled = false;
    });
  }

  function completeSession() {
    endBtn.disabled = true;
    listenBtn.disabled = true;
    submitTypedBtn.disabled = true;
    if (countdownTimer) clearInterval(countdownTimer);
    if (heartbeatTimer) clearInterval(heartbeatTimer);
    postJson(apiBase + '/mock_oral.php', { action: 'complete_session', session_id: sessionId })
      .then(function () {
        window.location.href = window.location.pathname + '?cohort_id=' + cfg.cohortId + '&area_id=' + cfg.areaId + '&session_id=' + sessionId + '&view=debrief';
      });
  }

  startBtn.addEventListener('click', function () {
    startBtn.disabled = true;
    postJson(apiBase + '/mock_oral.php', { action: 'start_session', session_id: sessionId })
      .then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Unable to start');
        started = true;
        endBtn.disabled = false;
        listenBtn.disabled = false;
        submitTypedBtn.disabled = false;
        if (res.opening && res.opening.maya_text) {
          speak(res.opening.maya_text);
          turnIndex = 1;
        }
        startCountdown();
        heartbeatTimer = setInterval(heartbeat, 30000);
      })
      .catch(function (e) {
        appendTurn('system', e.message || 'Unable to start session');
        startBtn.disabled = false;
      });
  });

  submitTypedBtn.addEventListener('click', function () {
    submitAnswer(String(typedEl.value || '').trim());
  });

  endBtn.addEventListener('click', function () { completeSession(); });

  if (window.SpeechRecognition || window.webkitSpeechRecognition) {
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    recognition = new SR();
    recognition.continuous = false;
    recognition.interimResults = false;
    recognition.lang = 'en-US';
    recognition.onresult = function (ev) {
      var text = ev.results[0][0].transcript || '';
      submitAnswer(text.trim());
    };
    listenBtn.addEventListener('mousedown', function () {
      if (!started || !recognition) return;
      listenBtn.textContent = 'Listening...';
      recognition.start();
    });
    listenBtn.addEventListener('mouseup', function () {
      listenBtn.textContent = 'Hold to Answer';
      try { recognition.stop(); } catch (e) {}
    });
  } else {
    listenBtn.textContent = 'Use typed answers';
    listenBtn.disabled = true;
  }
})();
