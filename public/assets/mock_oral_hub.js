(function () {
  var cfg = window.MOCK_ORAL_HUB || {};
  var cohortId = cfg.cohortId || 0;
  var apiBase = cfg.apiBase || '/student/api';

  var prepOverlay = document.getElementById('mockOralPrepOverlay');
  var prepStepEl = document.getElementById('mockOralPrepStep');
  var prepBarEl = document.getElementById('mockOralPrepBar');
  var prepTimer = null;
  var prepStepIdx = 0;
  var prepSteps = [
    'Preparing your Mock Oral Exam Session…',
    'Analyzing your weak areas…',
    'Building your ACS-based oral exam blueprint…',
    'Preparing Maya…',
    'Almost ready…'
  ];

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

  function showPrepOverlay() {
    if (!prepOverlay) return;
    prepStepIdx = 0;
    if (prepStepEl) prepStepEl.textContent = prepSteps[0];
    if (prepBarEl) prepBarEl.style.width = '8%';
    prepOverlay.classList.add('is-open');
    prepOverlay.setAttribute('aria-hidden', 'false');
    clearInterval(prepTimer);
    prepTimer = setInterval(function () {
      prepStepIdx = (prepStepIdx + 1) % prepSteps.length;
      if (prepStepEl) prepStepEl.textContent = prepSteps[prepStepIdx];
      if (prepBarEl) {
        var pct = Math.min(92, 8 + prepStepIdx * 18);
        prepBarEl.style.width = pct + '%';
      }
    }, 2800);
  }

  function hidePrepOverlay() {
    if (!prepOverlay) return;
    clearInterval(prepTimer);
    prepOverlay.classList.remove('is-open');
    prepOverlay.setAttribute('aria-hidden', 'true');
    if (prepBarEl) prepBarEl.style.width = '100%';
  }

  document.querySelectorAll('.moe-start-auth-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var areaId = parseInt(btn.getAttribute('data-area-id') || '0', 10);
      btn.disabled = true;
      postJson(apiBase + '/mock_oral_remote_request.php', { cohort_id: cohortId, area_id: areaId })
        .then(function (res) {
          if (!res.ok) throw new Error(res.error || 'Unable to start authentication');
          if (res.auth_url) {
            window.location.href = res.auth_url;
            return;
          }
          alert(res.message || 'Check your email for the authentication link.');
          window.location.reload();
        })
        .catch(function (e) {
          alert(e.message || 'Unable to start authentication');
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
    overlay.setAttribute('aria-hidden', 'false');
    overlay.classList.add('is-open');
    codeInput.focus();
  }
  function closeCodeModal() {
    if (!overlay) return;
    overlay.setAttribute('aria-hidden', 'true');
    overlay.classList.remove('is-open');
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
      var areaId = parseInt(areaInput.value || '0', 10);
      var code = (codeInput.value || '').trim();
      errBox.style.display = 'none';
      if (!/^\d{6}$/.test(code)) {
        errBox.style.display = 'block';
        errBox.textContent = 'Enter the 6-digit code from authentication.';
        return;
      }
      codeSubmit.disabled = true;
      codeSubmit.textContent = 'Preparing…';
      closeCodeModal();
      showPrepOverlay();
      postJson(apiBase + '/mock_oral_remote_verify_code.php', { cohort_id: cohortId, area_id: areaId, code: code })
        .then(function (res) {
          if (!res.ok) throw new Error(res.error || 'Code verification failed');
          hidePrepOverlay();
          window.location.href = '/student/mock_oral_session.php?cohort_id=' + cohortId + '&area_id=' + areaId + '&session_id=' + res.session_id;
        })
        .catch(function (e) {
          hidePrepOverlay();
          openCodeModal(areaId);
          errBox.style.display = 'block';
          errBox.textContent = e.message || 'Code verification failed';
          codeSubmit.disabled = false;
          codeSubmit.textContent = 'Verify Code';
        });
    });
  }

  function handleAuthRefresh() {
    if (window.location.search.indexOf('mo_remote_auth=1') !== -1) {
      var firstCodeBtn = document.querySelector('.moe-code-btn');
      if (firstCodeBtn) firstCodeBtn.click();
    }
  }

  try {
    window.addEventListener('storage', function (ev) {
      if (ev.key === 'mo_remote_auth_refresh') handleAuthRefresh();
    });
  } catch (e) {}
  try {
    var bc = new BroadcastChannel('ipca_mo_remote_auth');
    bc.onmessage = function () { handleAuthRefresh(); };
  } catch (e) {}

  handleAuthRefresh();
})();
