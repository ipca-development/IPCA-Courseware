<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/courseware_progression_v2.php';
require_once __DIR__ . '/../../src/mock_oral/mock_oral_bootstrap.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'student' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    exit('Missing authentication token.');
}

$studentId = (int)cw_student_view_user_id($pdo, $u);
$engine = new CoursewareProgressionV2($pdo);
$auth = $engine->loadMockOralAuthorizationByToken($token);

$pageError = '';
$pageInfo = '';
$cohortId = 0;
$areaId = 0;
$areaTitle = 'Mock Oral Module';
$hubUrl = '/student/mock_oral.php';

if (!$auth) {
    $pageError = 'Invalid or expired authentication link.';
} elseif ((int)$auth['student_id'] !== $studentId) {
    $pageError = 'This authentication link belongs to another account.';
} elseif (!in_array((string)$auth['status'], ['REQUESTED', 'EMAIL_SENT', 'AUTHENTICATED'], true)) {
    $pageError = 'This authorization is no longer valid.';
} elseif (strtotime((string)$auth['expires_at']) <= time()) {
    $pageError = 'This authentication link has expired.';
} else {
    $cohortId = (int)$auth['cohort_id'];
    $areaId = (int)$auth['area_id'];
    $hubUrl = '/student/mock_oral.php?cohort_id=' . $cohortId . '&area_id=' . $areaId . '&mo_remote_auth=1';
    $area = mo_area_by_id($pdo, $areaId);
    if ($area) {
        $areaTitle = (string)$area['title'];
    }
    if ((string)$auth['status'] === 'AUTHENTICATED') {
        $pageInfo = 'Authentication complete. Enter your Mock Oral Code on the mock oral page if you still have it.';
    }
}

function mo_auth_h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

cw_header('Mock Oral Authentication');
?>
<style>
  body{background:#fff;}
  .ptr-wrap{max-width:720px;margin:0 auto;padding:18px 12px 40px;}
  .ptr-card{padding:24px;border:1px solid #e8e8e8;border-radius:18px;background:#fff;box-shadow:0 10px 30px rgba(0,0,0,0.06);}
  .ptr-title{font-size:24px;font-weight:900;color:#1e3c72;margin:0 0 8px;}
  .ptr-sub{color:#475569;line-height:1.55;margin-bottom:18px;font-size:14px;}
  .ptr-label{display:block;font-size:12px;font-weight:800;color:#334155;margin:12px 0 6px;}
  .ptr-input,.ptr-video-wrap{width:100%;box-sizing:border-box;}
  .ptr-input{padding:14px 16px;border:1px solid #d6d6d6;border-radius:12px;font-size:16px;}
  .ptr-video-wrap{margin-top:8px;border:1px solid #d6d6d6;border-radius:14px;overflow:hidden;background:#0f172a;min-height:220px;display:flex;align-items:center;justify-content:center;}
  .ptr-video-wrap video{width:100%;max-height:320px;display:block;object-fit:cover;}
  .ptr-btn{width:100%;margin-top:16px;padding:16px 14px;border-radius:16px;border:2px solid rgba(30,60,114,0.25);background:rgba(30,60,114,0.08);color:#1e3c72;font-weight:900;font-size:18px;cursor:pointer;}
  .ptr-btn.primary{background:#12355f;color:#fff;border-color:#12355f;}
  .ptr-btn:disabled{opacity:.55;cursor:not-allowed;}
  .ptr-error{margin-top:12px;color:#b91c1c;font-weight:800;}
  .ptr-info{margin-top:12px;color:#1d4f91;font-weight:700;line-height:1.5;}
  .ptr-code-box{margin-top:16px;padding:18px;border-radius:14px;background:#f0fdf4;border:1px solid #86efac;text-align:center;}
  .ptr-code{font-size:34px;font-weight:900;letter-spacing:.22em;color:#166534;}
  .ptr-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;}
  .ptr-muted{font-size:12px;color:#64748b;margin-top:10px;line-height:1.5;}
  .ptr-warn{margin-top:14px;padding:14px 16px;border-radius:12px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;font-size:13px;line-height:1.55;font-weight:700;}
  .ptr-copy-ok{margin-top:10px;color:#166534;font-weight:800;font-size:13px;display:none;}
</style>

<div class="ptr-wrap">
  <div class="ptr-card">
    <h1 class="ptr-title">Mock Oral Exam Authentication</h1>
    <div class="ptr-sub">
      <?php if ($pageError === ''): ?>
        Confirm your identity for <strong><?= mo_auth_h($areaTitle) ?></strong>.
      <?php else: ?>
        Secure authentication for your mock oral session request.
      <?php endif; ?>
    </div>

    <?php if ($pageError !== ''): ?>
      <div class="ptr-error"><?= mo_auth_h($pageError) ?></div>
      <div class="ptr-actions"><a class="ptr-btn" href="/student/mock_oral.php">Back to Mock Oral</a></div>
    <?php elseif ($pageInfo !== ''): ?>
      <div class="ptr-info"><?= mo_auth_h($pageInfo) ?></div>
      <div class="ptr-actions"><a class="ptr-btn primary" href="<?= mo_auth_h($hubUrl) ?>">Open Mock Oral Page</a></div>
    <?php else: ?>
      <div id="ptrFormBlock">
        <label class="ptr-label" for="ptrPassword">Account password</label>
        <input class="ptr-input" id="ptrPassword" type="password" autocomplete="current-password">
        <label class="ptr-label">Live photo</label>
        <div class="ptr-video-wrap"><video id="ptrVideo" autoplay playsinline muted></video></div>
        <canvas id="ptrCanvas" style="display:none;"></canvas>
        <button type="button" class="ptr-btn" id="ptrCaptureBtn" style="margin-top:10px;">Capture photo</button>
        <div class="ptr-muted" id="ptrCaptureStatus">Allow camera access, center your face, then capture.</div>
        <button type="button" class="ptr-btn primary" id="ptrSubmitBtn" disabled>Verify and show Mock Oral Code</button>
        <div class="ptr-error" id="ptrError" style="display:none;"></div>
      </div>
      <div id="ptrCodeBlock" style="display:none;">
        <div class="ptr-warn">Copy your Mock Oral Code now. It is shown only once.</div>
        <div class="ptr-code-box"><div class="ptr-muted">Your Mock Oral Code</div><div class="ptr-code" id="ptrCodeValue"></div></div>
        <div class="ptr-actions"><button type="button" class="ptr-btn primary" id="ptrCopyBtn">Copy code</button></div>
        <div class="ptr-copy-ok" id="ptrCopyOk">Code copied.</div>
        <div class="ptr-actions" style="margin-top:14px;"><a class="ptr-btn" href="<?= mo_auth_h($hubUrl) ?>">Return to Mock Oral page</a></div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($pageError === '' && $pageInfo === ''): ?>
<script>
(function () {
  var token = <?= json_encode($token, JSON_UNESCAPED_UNICODE) ?>;
  var video = document.getElementById('ptrVideo');
  var canvas = document.getElementById('ptrCanvas');
  var captureBtn = document.getElementById('ptrCaptureBtn');
  var submitBtn = document.getElementById('ptrSubmitBtn');
  var captureStatus = document.getElementById('ptrCaptureStatus');
  var errBox = document.getElementById('ptrError');
  var photoDataUrl = '';
  function showError(msg){ errBox.style.display='block'; errBox.textContent=msg||'Error'; }
  navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false }).then(function (s){ video.srcObject=s; }).catch(function(){ captureStatus.textContent='Camera access required.'; });
  captureBtn.addEventListener('click', function(){
    if (!video.videoWidth) return;
    canvas.width=video.videoWidth; canvas.height=video.videoHeight;
    canvas.getContext('2d').drawImage(video,0,0);
    photoDataUrl=canvas.toDataURL('image/jpeg',0.92);
    captureStatus.textContent='Photo captured.';
    submitBtn.disabled=false;
  });
  submitBtn.addEventListener('click', function(){
    var password = String(document.getElementById('ptrPassword').value||'');
    if (!password) return showError('Enter your password.');
    if (!photoDataUrl) return showError('Capture a photo first.');
    submitBtn.disabled=true;
    var fd=new FormData();
    fd.append('token', token);
    fd.append('password', password);
    fd.append('photo_data_url', photoDataUrl);
    fetch('/student/api/mock_oral_remote_authenticate.php', { method:'POST', credentials:'same-origin', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j || !j.ok) throw new Error(j.error||'Verification failed');
        document.getElementById('ptrFormBlock').style.display='none';
        document.getElementById('ptrCodeBlock').style.display='block';
        document.getElementById('ptrCodeValue').textContent=String(j.verification_code||'');
        if (video.srcObject) video.srcObject.getTracks().forEach(function(t){ t.stop(); });
        (function notifyMockOralHubAuthComplete() {
          var payload = {
            type: 'mock_oral_authenticated',
            cohort_id: <?= (int)$cohortId ?>,
            area_id: <?= (int)$areaId ?>,
            ts: Date.now()
          };
          try { sessionStorage.setItem('mo_remote_code_open', JSON.stringify(payload)); } catch(e){}
          try { localStorage.setItem('mo_remote_auth_refresh', JSON.stringify(payload)); } catch(e){}
          try {
            if (typeof BroadcastChannel !== 'undefined') {
              var channel = new BroadcastChannel('ipca_mo_remote_auth');
              channel.postMessage(payload);
              channel.close();
            }
          } catch(e){}
          if (window.opener && !window.opener.closed) {
            try { window.opener.postMessage(payload, window.location.origin); } catch(e){}
          }
        })();
      })
      .catch(function(e){ submitBtn.disabled=false; showError(e.message); });
  });
  document.getElementById('ptrCopyBtn').addEventListener('click', function(){
    var code = document.getElementById('ptrCodeValue').textContent || '';
    navigator.clipboard.writeText(code).then(function(){
      document.getElementById('ptrCopyOk').style.display='block';
    });
  });
})();
</script>
<?php endif; ?>
<?php cw_footer(); ?>
