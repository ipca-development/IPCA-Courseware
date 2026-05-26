(function () {
  var cfg = window.MOCK_ORAL_HUB || {};
  var cohortId = cfg.cohortId || 0;
  var apiBase = cfg.apiBase || '/student/api';
  var remoteAuthReloading = false;

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
          throw new Error('Server returned an invalid response.');
        }
        if (!r.ok && data.error) throw new Error(data.error);
        return data;
      });
    });
  }

  function showRequestToast(message) {
    var toast = document.getElementById('moRemoteRequestToast');
    if (!toast) return;
    toast.textContent = message || 'Your mock oral request was received. Check your email for the authentication link in a few moments.';
    toast.classList.add('show');
    if (toast._hideTimer) window.clearTimeout(toast._hideTimer);
    toast._hideTimer = window.setTimeout(function () {
      toast.classList.remove('show');
    }, 5000);
  }

  document.querySelectorAll('.moe-remote-request-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (btn.disabled) return;
      btn.disabled = true;
      var areaId = parseInt(btn.getAttribute('data-area-id') || '0', 10);
      postJson(apiBase + '/mock_oral_remote_request.php', { cohort_id: cohortId, area_id: areaId })
        .then(function (res) {
          if (!res.ok) throw new Error(res.error || 'Unable to submit request');
          showRequestToast(res.message);
          window.setTimeout(function () { window.location.reload(); }, 1200);
        })
        .catch(function (e) {
          alert(e.message || 'Unable to submit request');
          btn.disabled = false;
        });
    });
  });

  var overlay = document.getElementById('mockOralCodeModal');
  var areaInput = document.getElementById('mockOralCodeAreaId');
  var codeInput = document.getElementById('mockOralCodeInput');
  var errBox = document.getElementById('mockOralCodeError');
  var codeSubmit = document.getElementById('mockOralCodeSubmit');

  function openCodeModal(areaId) {
    if (!overlay) return;
    areaInput.value = String(areaId);
    codeInput.value = '';
    errBox.style.display = 'none';
    overlay.setAttribute('data-area-id', String(areaId));
    overlay.setAttribute('aria-hidden', 'false');
    overlay.classList.add('is-open');
    codeInput.focus();
  }

  function closeCodeModal() {
    if (!overlay) return;
    overlay.setAttribute('aria-hidden', 'true');
    overlay.classList.remove('is-open');
    overlay.removeAttribute('data-area-id');
    if (codeInput) codeInput.value = '';
    errBox.style.display = 'none';
    if (codeSubmit) {
      codeSubmit.disabled = false;
      codeSubmit.textContent = 'Verify & prepare';
    }
  }

  document.querySelectorAll('.moe-code-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openCodeModal(parseInt(btn.getAttribute('data-area-id') || '0', 10));
    });
  });

  var cancelBtn = document.getElementById('mockOralCodeCancel');
  if (cancelBtn) cancelBtn.addEventListener('click', closeCodeModal);

  if (codeSubmit) {
    codeSubmit.addEventListener('click', function () {
      var areaId = parseInt(areaInput.value || overlay.getAttribute('data-area-id') || '0', 10);
      var code = (codeInput.value || '').replace(/\D+/g, '');
      if (codeInput) codeInput.value = code;
      errBox.style.display = 'none';
      if (!/^\d{6}$/.test(code)) {
        errBox.style.display = 'block';
        errBox.textContent = 'Enter the 6-digit code from authentication.';
        return;
      }
      codeSubmit.disabled = true;
      codeSubmit.textContent = 'Preparing…';
      postJson(apiBase + '/mock_oral_remote_verify_code.php', { cohort_id: cohortId, area_id: areaId, code: code })
        .then(function (res) {
          if (!res.ok) throw new Error(res.error || 'Code verification failed');
          closeCodeModal();
          var url = res.redirect_url || ('/student/mock_oral.php?cohort_id=' + cohortId + '&area_id=' + areaId + '&mo_code_verified=1');
          window.location.replace(url + '&_ts=' + Date.now());
        })
        .catch(function (e) {
          errBox.style.display = 'block';
          errBox.textContent = e.message || 'Code verification failed';
          codeSubmit.disabled = false;
          codeSubmit.textContent = 'Verify & prepare';
        });
    });
  }

  function redirectHubForRemoteAuth(areaId) {
    if (remoteAuthReloading) return;
    remoteAuthReloading = true;
    var qs = 'cohort_id=' + encodeURIComponent(String(cohortId))
      + '&area_id=' + encodeURIComponent(String(areaId))
      + '&mo_remote_auth=1'
      + '&_ts=' + Date.now();
    window.location.replace('/student/mock_oral.php?' + qs);
  }

  function handleRemoteAuthComplete(data) {
    if (!data || data.type !== 'mock_oral_authenticated') return;
    try { localStorage.removeItem('mo_remote_auth_refresh'); } catch (e) {}
    redirectHubForRemoteAuth(parseInt(data.area_id || '0', 10));
  }

  window.addEventListener('message', function (ev) {
    if (!ev || !ev.data || ev.data.type !== 'mock_oral_authenticated') return;
    if (ev.origin !== window.location.origin) return;
    handleRemoteAuthComplete(ev.data);
  });

  window.addEventListener('storage', function (ev) {
    if (ev.key !== 'mo_remote_auth_refresh' || !ev.newValue) return;
    try { handleRemoteAuthComplete(JSON.parse(ev.newValue)); } catch (e) {}
  });

  try {
    var bc = new BroadcastChannel('ipca_mo_remote_auth');
    bc.onmessage = function (ev) { handleRemoteAuthComplete(ev.data); };
  } catch (e) {}

  function readPendingRemoteCodeOpen() {
    try {
      var raw = sessionStorage.getItem('mo_remote_code_open');
      if (!raw) return null;
      sessionStorage.removeItem('mo_remote_code_open');
      var pending = JSON.parse(raw);
      if (!pending || !pending.area_id) return null;
      return { areaId: parseInt(pending.area_id, 10) || 0 };
    } catch (e) {
      return null;
    }
  }

  function resolveRemoteCodeOpenTarget() {
    var params = new URLSearchParams(window.location.search || '');
    var areaId = parseInt(params.get('area_id') || '0', 10);
    if (params.get('mo_remote_auth') === '1' && areaId > 0) {
      return { areaId: areaId, source: 'url' };
    }
    var pending = readPendingRemoteCodeOpen();
    if (pending && pending.areaId > 0) {
      return { areaId: pending.areaId, source: 'session' };
    }
    var autoBtn = document.querySelector('.moe-code-btn[data-auto-open-remote-code="1"]');
    if (autoBtn) {
      return { areaId: parseInt(autoBtn.getAttribute('data-area-id') || '0', 10), source: 'button' };
    }
    return null;
  }

  function stripRemoteAuthQueryParams() {
    if (!window.history || !window.history.replaceState) return;
    var params = new URLSearchParams(window.location.search || '');
    if (!params.has('mo_remote_auth') && !params.has('_ts') && !params.has('mo_code_verified')) return;
    params.delete('mo_remote_auth');
    params.delete('_ts');
    params.delete('mo_code_verified');
    var qs = params.toString();
    window.history.replaceState({}, '', window.location.pathname + (qs ? '?' + qs : ''));
  }

  function maybeAutoOpenRemoteCodeModal() {
    var target = resolveRemoteCodeOpenTarget();
    if (!target || target.areaId <= 0) return;
    var btn = document.querySelector('.moe-code-btn[data-area-id="' + target.areaId + '"]');
    if (!btn) return;
    window.setTimeout(function () {
      openCodeModal(target.areaId);
      stripRemoteAuthQueryParams();
    }, 150);
  }

  (function checkStoredRemoteAuthRefresh() {
    try {
      var params = new URLSearchParams(window.location.search || '');
      if (params.get('mo_remote_auth') === '1') return;
      var raw = localStorage.getItem('mo_remote_auth_refresh');
      if (!raw) return;
      var data = JSON.parse(raw);
      if (!data || data.type !== 'mock_oral_authenticated') return;
      localStorage.removeItem('mo_remote_auth_refresh');
      handleRemoteAuthComplete(data);
    } catch (e) {}
  })();

  maybeAutoOpenRemoteCodeModal();

  var prepNodes = document.querySelectorAll('.moe-prep-status[data-session-id]');
  if (prepNodes.length) {
    function applyPrepDisplay(node, display) {
      if (!display) return;
      var fill = node.querySelector('.moe-prep-bar-fill');
      var label = node.querySelector('.moe-prep-label');
      var head = node.querySelector('.moe-prep-head');
      var pct = Math.max(0, Math.min(100, parseInt(display.pct, 10) || 0));
      var cssClass = String(display.class || 'info');
      if (fill) {
        fill.style.width = pct + '%';
        fill.className = 'moe-prep-bar-fill ' + cssClass;
      }
      if (label) {
        label.textContent = String(display.label || '');
        label.className = 'moe-prep-label ' + cssClass;
      }
      if (head) head.textContent = String(display.sub || 'Preparing');
    }

    function pollPrepNode(node) {
      var sessionId = parseInt(node.getAttribute('data-session-id') || '0', 10);
      if (sessionId <= 0) return;
      fetch(apiBase + '/mock_oral_prep_status.php?session_id=' + encodeURIComponent(String(sessionId)), {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store'
      })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.ok) return;
          applyPrepDisplay(node, j.display);
          if (j.prepared || String(j.status || '') === 'ready') {
            window.location.reload();
          }
        })
        .catch(function () {});
    }

    prepNodes.forEach(pollPrepNode);
    window.setInterval(function () { prepNodes.forEach(pollPrepNode); }, 2000);
  }
})();
