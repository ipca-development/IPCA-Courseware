<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/courseware_progression_v2.php';

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
$auth = $engine->loadRemoteAuthorizationByToken($token);

$pageError = '';
$pageInfo = '';
$showCode = false;
$progressTestCode = '';
$cohortId = 0;
$lessonId = 0;
$courseReturnUrl = '/student/course.php';

if (!$auth) {
    $pageError = 'Invalid or expired authentication link.';
} elseif ((int)$auth['student_id'] !== $studentId) {
    $pageError = 'This authentication link belongs to another account. Sign in with the correct student account.';
} elseif (!in_array((string)$auth['status'], ['REQUESTED', 'EMAIL_SENT', 'AUTHENTICATED'], true)) {
    $pageError = 'This authorization is no longer valid.';
} elseif (strtotime((string)$auth['expires_at']) <= time()) {
    $pageError = 'This authentication link has expired. Request a new progress test from your course page.';
} else {
    $cohortId = (int)$auth['cohort_id'];
    $lessonId = (int)$auth['lesson_id'];
    $courseReturnUrl = '/student/course.php?cohort_id=' . $cohortId . '#progress-test-lesson-' . $lessonId;

    if ($role === 'student') {
        $en = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id = ? AND user_id = ? AND status = 'active' LIMIT 1");
        $en->execute([$cohortId, $studentId]);
        if (!$en->fetchColumn()) {
            $pageError = 'You are not actively enrolled in this cohort.';
        }
    }

    if ($pageError === '' && (string)$auth['status'] === 'AUTHENTICATED' && !empty($auth['verification_code_hash'])) {
        $pageInfo = 'Authentication complete. Copy your Progress Test Code from this page if you still have it open, otherwise request a new authorization from the course page.';
    } elseif ($pageError === '') {
        $engine->logProgressionEvent([
            'user_id' => $studentId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'event_type' => 'progress_test',
            'event_code' => 'REMOTE_PROGRESS_TEST_AUTH_PAGE_OPENED',
            'event_status' => 'info',
            'actor_type' => 'student',
            'actor_user_id' => $studentId,
            'payload' => ['authorization_id' => (int)$auth['id']],
        ]);
    }
}

$lessonTitle = 'Lesson';
$courseTitle = 'Course';
if ($cohortId > 0 && $lessonId > 0) {
    $metaSt = $pdo->prepare("
        SELECT l.title AS lesson_title, c.title AS course_title
        FROM lessons l
        INNER JOIN courses c ON c.id = l.course_id
        WHERE l.id = ?
        LIMIT 1
    ");
    $metaSt->execute([$lessonId]);
    $meta = $metaSt->fetch(PDO::FETCH_ASSOC) ?: [];
    $lessonTitle = trim((string)($meta['lesson_title'] ?? $lessonTitle));
    $courseTitle = trim((string)($meta['course_title'] ?? $courseTitle));
}

cw_header('Remote Progress Test Authentication');
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
  .ptr-actions a,.ptr-actions button{flex:1;min-width:140px;text-align:center;text-decoration:none;}
  .ptr-muted{font-size:12px;color:#64748b;margin-top:10px;line-height:1.5;}
  .ptr-warn{margin-top:14px;padding:14px 16px;border-radius:12px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;font-size:13px;line-height:1.55;font-weight:700;}
  .ptr-copy-ok{margin-top:10px;color:#166534;font-weight:800;font-size:13px;display:none;}
</style>

<div class="ptr-wrap">
  <div class="ptr-card">
    <h1 class="ptr-title">Remote Progress Test Authentication</h1>
    <div class="ptr-sub">
      <?php if ($pageError === ''): ?>
        Confirm your identity for <strong><?= h($lessonTitle) ?></strong> in <?= h($courseTitle) ?>.
        Take a live photo and enter your account password. Your Progress Test Code will appear once verification succeeds.
      <?php else: ?>
        Secure authentication for your remote progress test request.
      <?php endif; ?>
    </div>

    <?php if ($pageError !== ''): ?>
      <div class="ptr-error"><?= h($pageError) ?></div>
      <div class="ptr-actions">
        <a class="ptr-btn" href="/student/course.php">Back to course</a>
      </div>
    <?php elseif ($pageInfo !== ''): ?>
      <div class="ptr-info"><?= h($pageInfo) ?></div>
      <div class="ptr-muted">Switch back to your <strong>existing course page tab</strong>, click <strong>Enter Progress Test Code</strong>, and type the code you copied. You can close this window.</div>
    <?php else: ?>
      <div id="ptrFormBlock">
        <label class="ptr-label" for="ptrPassword">Account password</label>
        <input class="ptr-input" id="ptrPassword" type="password" autocomplete="current-password" placeholder="Enter your IPCA password">

        <label class="ptr-label">Live photo</label>
        <div class="ptr-video-wrap"><video id="ptrVideo" autoplay playsinline muted></video></div>
        <canvas id="ptrCanvas" style="display:none;"></canvas>
        <button type="button" class="ptr-btn" id="ptrCaptureBtn" style="margin-top:10px;">Capture photo</button>
        <div class="ptr-muted" id="ptrCaptureStatus">Allow camera access, center your face, then capture.</div>

        <button type="button" class="ptr-btn primary" id="ptrSubmitBtn" disabled>Verify and show Progress Test Code</button>
        <div class="ptr-error" id="ptrError" style="display:none;"></div>
      </div>

      <div id="ptrCodeBlock" style="display:none;">
        <div class="ptr-warn">
          Copy your Progress Test Code now. It is shown <strong>only once</strong> and cannot be retrieved later.
          Switch back to your <strong>existing course page tab</strong> (do not open a new one), click
          <strong>Enter Progress Test Code</strong>, paste the code, then wait on the course page while your test is prepared.
        </div>
        <div class="ptr-code-box">
          <div class="ptr-muted" style="margin-top:0;">Your Progress Test Code</div>
          <div class="ptr-code" id="ptrCodeValue"></div>
        </div>
        <div class="ptr-actions">
          <button type="button" class="ptr-btn primary" id="ptrCopyBtn">Copy code</button>
        </div>
        <div class="ptr-copy-ok" id="ptrCopyOk">Code copied. You can close this window and return to your course page tab.</div>
        <div class="ptr-muted">Question generation starts only after you enter this code on the course page — the same preparation flow as on-site <strong>Prepare Progress Test</strong>.</div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($pageError === '' && $pageInfo === ''): ?>
<script>
(function () {
  var token = <?= json_encode($token, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var video = document.getElementById('ptrVideo');
  var canvas = document.getElementById('ptrCanvas');
  var captureBtn = document.getElementById('ptrCaptureBtn');
  var submitBtn = document.getElementById('ptrSubmitBtn');
  var captureStatus = document.getElementById('ptrCaptureStatus');
  var errBox = document.getElementById('ptrError');
  var photoDataUrl = '';

  function showError(msg) {
    errBox.style.display = 'block';
    errBox.textContent = msg || 'Something went wrong.';
  }

  navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false })
    .then(function (stream) {
      video.srcObject = stream;
    })
    .catch(function () {
      captureStatus.textContent = 'Camera access is required for remote authentication.';
    });

  captureBtn.addEventListener('click', function () {
    if (!video.videoWidth) return;
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    photoDataUrl = canvas.toDataURL('image/jpeg', 0.92);
    captureStatus.textContent = 'Photo captured. You can recapture or continue to verification.';
    submitBtn.disabled = false;
  });

  submitBtn.addEventListener('click', function () {
    var password = String(document.getElementById('ptrPassword').value || '');
    if (!password) {
      showError('Enter your account password.');
      return;
    }
    if (!photoDataUrl) {
      showError('Capture a live photo first.');
      return;
    }
    submitBtn.disabled = true;
    errBox.style.display = 'none';

    var fd = new FormData();
    fd.append('token', token);
    fd.append('password', password);
    fd.append('photo_data_url', photoDataUrl);

    fetch('/student/api/progress_test_remote_authenticate.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: fd
    })
      .then(function (res) { return res.json(); })
      .then(function (j) {
        if (!j || !j.ok) {
          throw new Error((j && j.error) ? j.error : 'Verification failed.');
        }
        document.getElementById('ptrFormBlock').style.display = 'none';
        document.getElementById('ptrCodeBlock').style.display = 'block';
        document.getElementById('ptrCodeValue').textContent = String(j.progress_test_code || '');
        if (video.srcObject) {
          video.srcObject.getTracks().forEach(function (t) { t.stop(); });
        }
        try {
          sessionStorage.setItem('pt_remote_code_open', JSON.stringify({
            cohort_id: <?= (int)$cohortId ?>,
            lesson_id: <?= (int)$lessonId ?>,
            ts: Date.now()
          }));
        } catch (e) {}
        (function notifyCourseTabRemoteAuthComplete() {
          var payload = {
            type: 'remote_progress_test_authenticated',
            cohort_id: <?= (int)$cohortId ?>,
            lesson_id: <?= (int)$lessonId ?>,
            ts: Date.now()
          };
          try {
            localStorage.setItem('pt_remote_auth_refresh', JSON.stringify(payload));
          } catch (e) {}
          try {
            if (typeof BroadcastChannel !== 'undefined') {
              var channel = new BroadcastChannel('ipca_pt_remote_auth');
              channel.postMessage(payload);
              channel.close();
            }
          } catch (e) {}
          if (window.opener && !window.opener.closed) {
            try {
              window.opener.postMessage(payload, window.location.origin);
            } catch (e) {}
          }
        })();
      })
      .catch(function (e) {
        submitBtn.disabled = false;
        showError(e.message || 'Verification failed.');
      });
  });

  function copyProgressTestCode(code) {
    if (!code) return Promise.reject(new Error('No code'));
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(code);
    }
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea');
      ta.value = code;
      ta.setAttribute('readonly', 'readonly');
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      try {
        if (!document.execCommand('copy')) {
          reject(new Error('Copy failed'));
        } else {
          resolve();
        }
      } catch (err) {
        reject(err);
      } finally {
        document.body.removeChild(ta);
      }
    });
  }

  document.getElementById('ptrCopyBtn').addEventListener('click', function () {
    var btn = this;
    var ok = document.getElementById('ptrCopyOk');
    var code = document.getElementById('ptrCodeValue').textContent || '';
    copyProgressTestCode(code)
      .then(function () {
        if (ok) ok.style.display = 'block';
        btn.textContent = 'Copied!';
        window.setTimeout(function () { btn.textContent = 'Copy code'; }, 2500);
      })
      .catch(function () {
        if (ok) {
          ok.style.display = 'block';
          ok.textContent = 'Automatic copy failed — select the code above and copy it manually (Cmd/Ctrl+C).';
          ok.style.color = '#b45309';
        }
      });
  });
})();
</script>
<?php endif; ?>

<?php cw_footer(); ?>
