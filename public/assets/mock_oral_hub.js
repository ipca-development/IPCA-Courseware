(function () {
  var cfg = window.MOCK_ORAL_HUB || {};
  var cohortId = cfg.cohortId || 0;
  var apiBase = cfg.apiBase || '/student/api';

  function postJson(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json(); });
  }

  document.querySelectorAll('.moe-request-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var areaId = parseInt(btn.getAttribute('data-area-id') || '0', 10);
      btn.disabled = true;
      postJson(apiBase + '/mock_oral_remote_request.php', { cohort_id: cohortId, area_id: areaId })
        .then(function (res) {
          if (!res.ok) throw new Error(res.error || 'Request failed');
          alert(res.message || 'Check your email for the authentication link.');
        })
        .catch(function (e) { alert(e.message || 'Request failed'); })
        .finally(function () { btn.disabled = false; });
    });
  });

  document.querySelectorAll('.moe-onsite-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var areaId = parseInt(btn.getAttribute('data-area-id') || '0', 10);
      btn.disabled = true;
      postJson(apiBase + '/mock_oral.php', { action: 'start_on_site', cohort_id: cohortId, area_id: areaId })
        .then(function (res) {
          if (!res.ok) throw new Error(res.error || 'Unable to start session');
          window.location.href = '/student/mock_oral_session.php?cohort_id=' + cohortId + '&area_id=' + areaId + '&session_id=' + res.session_id;
        })
        .catch(function (e) { alert(e.message || 'Unable to start session'); btn.disabled = false; });
    });
  });

  var overlay = document.getElementById('mockOralCodeModal');
  var areaInput = document.getElementById('mockOralCodeAreaId');
  var codeInput = document.getElementById('mockOralCodeInput');
  var errBox = document.getElementById('mockOralCodeError');

  function openCodeModal(areaId) {
    areaInput.value = String(areaId);
    codeInput.value = '';
    errBox.style.display = 'none';
    overlay.setAttribute('aria-hidden', 'false');
    overlay.classList.add('is-open');
    codeInput.focus();
  }
  function closeCodeModal() {
    overlay.setAttribute('aria-hidden', 'true');
    overlay.classList.remove('is-open');
  }

  document.querySelectorAll('.moe-code-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openCodeModal(parseInt(btn.getAttribute('data-area-id') || '0', 10));
    });
  });
  document.getElementById('mockOralCodeCancel').addEventListener('click', closeCodeModal);
  document.getElementById('mockOralCodeSubmit').addEventListener('click', function () {
    var areaId = parseInt(areaInput.value || '0', 10);
    var code = (codeInput.value || '').trim();
    errBox.style.display = 'none';
    postJson(apiBase + '/mock_oral_remote_verify_code.php', { cohort_id: cohortId, area_id: areaId, code: code })
      .then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Code verification failed');
        window.location.href = '/student/mock_oral_session.php?cohort_id=' + cohortId + '&area_id=' + areaId + '&session_id=' + res.session_id;
      })
      .catch(function (e) {
        errBox.style.display = 'block';
        errBox.textContent = e.message || 'Code verification failed';
      });
  });

  if (window.location.search.indexOf('mo_remote_auth=1') !== -1) {
    var firstCodeBtn = document.querySelector('.moe-code-btn');
    if (firstCodeBtn) firstCodeBtn.click();
  }
})();
