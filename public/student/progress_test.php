<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'student' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$cohortId = (int)($_GET['cohort_id'] ?? 0);
$lessonId = (int)($_GET['lesson_id'] ?? 0);
if ($cohortId <= 0 || $lessonId <= 0) exit('Missing cohort_id or lesson_id');

if ($role === 'student') {
    $check = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id=? AND user_id=? LIMIT 1");
    $check->execute([$cohortId, (int)$u['id']]);
    if (!$check->fetchColumn()) {
        http_response_code(403);
        exit('Not enrolled in this cohort');
    }
}

$userName = (string)($u['name'] ?? 'Student');
$INSTRUCTOR_NAME = 'Maya';
$INSTRUCTOR_AVATAR = '/assets/avatars/maya.png';
$fromMenu = ((string)($_GET['from'] ?? '') === 'menu');

cw_header('Progress Test');
?>
<style>
  body{ background:#fff; }
  .wrap{ max-width: 980px; margin: 0 auto; }

  .top-actions{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
  .led{ width:12px;height:12px;border-radius:999px;background:#dc2626; box-shadow:0 0 0 3px rgba(220,38,38,0.12); display:inline-block; }
  .led.on{ background:#16a34a; box-shadow:0 0 0 3px rgba(22,163,74,0.14); }
  .led-label{ font-size:12px; opacity:.75; display:flex; gap:6px; align-items:center; margin-left:auto; }

  .btn-start-green{ background:#16a34a !important; border-color:#12813c !important; color:#fff !important; }
  .btn-start-green:hover{ background:#138a3f !important; }

  .hero{
    display:flex;
    gap:14px;
    align-items:flex-start;
    flex-wrap:wrap;
    margin-top:12px;
  }

  /* =========================================================
     ✅ PERFECT CIRCLES (Safari/iPad proof)
     - Hard square sizing with flex-basis
     - clip-path circle to avoid oval rendering glitches
     - line-height:0 so text/layout can't affect box height
  ========================================================= */
  :root{
    --bubble: 120px;
  }

  .ring-wrap{
    width: var(--bubble) !important;
    height: var(--bubble) !important;
    min-width: var(--bubble) !important;
    min-height: var(--bubble) !important;

    flex: 0 0 var(--bubble) !important;
    align-self:flex-start;

    display:flex;
    align-items:center;
    justify-content:center;

    position:relative;
    box-sizing:border-box;
    line-height:0; /* critical */
    transform: translateZ(0); /* Safari paint stability */
  }

  /* Instructor speaking ring: GREEN pulsing */
  .ring-wrap.talking::after{
    content:"";
    position:absolute; inset:-10px;
    border-radius: 50%;
    clip-path: circle(50% at 50% 50%);
    border: 4px solid rgba(22,163,74,0.78);
    box-shadow: 0 0 26px rgba(22,163,74,0.48);
    animation:pulseG 0.95s infinite;
    pointer-events:none;
  }
  @keyframes pulseG{
    0%{ transform:scale(0.98); opacity:0.25; }
    50%{ transform:scale(1.06); opacity:0.98; }
    100%{ transform:scale(0.98); opacity:0.25; }
  }

  /* Student speaking ring: RED pulsing */
  .ring-wrap.rec::after{
    content:"";
    position:absolute; inset:-10px;
    border-radius: 50%;
    clip-path: circle(50% at 50% 50%);
    border: 4px solid rgba(220,38,38,0.82);
    box-shadow: 0 0 26px rgba(220,38,38,0.48);
    animation:pulseR 0.85s infinite;
    pointer-events:none;
  }
  @keyframes pulseR{
    0%{ transform:scale(0.98); opacity:0.25; }
    50%{ transform:scale(1.06); opacity:1.0; }
    100%{ transform:scale(0.98); opacity:0.25; }
  }

  .avatar-badge{
    width: var(--bubble) !important;
    height: var(--bubble) !important;
    min-width: var(--bubble) !important;
    min-height: var(--bubble) !important;

    border-radius: 50% !important;
    clip-path: circle(50% at 50% 50%); /* hard guarantee */
    overflow:hidden;

    background: linear-gradient(135deg,#1e3c72,#2a5298);
    display:block;
    box-sizing:border-box;

    border:4px solid rgba(255,255,255,0.90);
    box-shadow:0 10px 30px rgba(0,0,0,0.12);
    transform: translateZ(0);
  }
  .avatar-badge img{
    width:100% !important;
    height:100% !important;
    object-fit:cover !important;
    display:block !important;
    border-radius:0 !important; /* we rely on clip-path */
    user-select:none;
    -webkit-user-drag:none;
    pointer-events:none;
  }

  .cam{
    width: var(--bubble) !important;
    height: var(--bubble) !important;
    min-width: var(--bubble) !important;
    min-height: var(--bubble) !important;

    border-radius: 50% !important;
    clip-path: circle(50% at 50% 50%); /* hard guarantee */
    overflow:hidden;

    background:#000;
    display:block;
    position:relative;
    box-sizing:border-box;

    border:4px solid rgba(30,60,114,0.30);
    box-shadow:0 10px 30px rgba(0,0,0,0.12);
    transform: translateZ(0);
  }
  .cam video{
    width:100% !important;
    height:100% !important;
    object-fit:cover !important;
    display:block !important;
    border-radius:0 !important; /* we rely on clip-path */
  }
  .cam .fallback{
    position:absolute; inset:0;
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-weight:900; letter-spacing:1px; opacity:.85;
    background:#000;
  }
  .cam .label{
    position:absolute;left:0;right:0;bottom:6px;text-align:center;
    font-size:12px;color:#fff;padding:0 8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    text-shadow:0 1px 2px rgba(0,0,0,0.6);box-sizing:border-box;
    line-height: 1.1;
  }

  .meta{ line-height:1.1; padding-top:6px; }
  .meta .name{ font-weight:900; color:#1e3c72; font-size:18px; }
  .meta .role{ font-size:12px; opacity:.75; }

  .sysline{
    margin-top:12px;
    padding:10px 12px;
    border:1px solid #eee;
    border-radius:12px;
    background:#fafafa;
    font-weight:800;
    color:#1e3c72;
  }

  .ptt{
    width:100%; margin-top:14px; padding:16px 14px;
    border-radius:16px;
    border:2px solid rgba(30,60,114,0.25);
    background: rgba(30,60,114,0.08);
    color:#1e3c72;
    font-weight:900;
    font-size:18px;
    cursor:pointer;
    user-select:none;
  }
  .ptt:hover{ background: rgba(30,60,114,0.12); }
  .ptt.rec{ background: rgba(220,38,38,0.12); border-color: rgba(220,38,38,0.35); color:#b91c1c; }
  .ptt:disabled{ opacity:.5; cursor:not-allowed; }

  .timer-wrap{ margin-top:12px; }
  .timer-pill{ height:14px;border-radius:999px;background:#eee;overflow:hidden;border:1px solid #e6e6e6; }
  .timer-fill{ height:14px;width:0%;background:#1e3c72;transition: width 0.25s linear; }
  .timer-fill.danger{ background:#dc2626; }
  .timer-meta{ display:flex; justify-content:space-between; font-size:12px; opacity:.75; margin-top:6px; }

  .qstrip{ display:flex; gap:6px; flex-wrap:wrap; margin-top:12px; }
  .qdot{ width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;background:rgba(30,60,114,0.10);border:2px solid rgba(30,60,114,0.35); color:#1e3c72; }
  .qdot.done{ background:rgba(22,163,74,0.14); border-color:rgba(22,163,74,0.55); color:#166534; }

  .pinrow{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:10px; }
  .pinrow input{ height:36px; }
</style>

<div class="wrap">
  <div class="card">
    <div class="muted">Audio-only. Tap once to start talking, tap again to stop.</div>

    <div class="top-actions" style="margin-top:10px;">
      <button class="btn <?= $fromMenu ? 'btn-start-green' : '' ?>" id="btnStart" type="button">Start Progress Test</button>
      <button class="btn btn-sm" id="btnReplay" type="button" style="display:none;">↻ Replay</button>
      <a class="btn btn-sm" href="/student/course.php?cohort_id=<?= (int)$cohortId ?>">Back to Lesson Menu</a>

      <span class="led-label">
        <span class="led" id="jsLed"></span>
        <span id="jsLedTxt">JS</span>
      </span>
    </div>

    <div class="pinrow" id="pinRow" style="display:none;">
      <input class="input" id="pinInput" type="password" placeholder="Training PIN (optional)" style="width:220px;">
      <button class="btn btn-sm" id="btnPin" type="button">Submit PIN</button>
      <span class="muted" id="pinMsg"></span>
    </div>

    <div id="quizCard" style="display:none; margin-top:14px;">
      <div class="hero">
        <div class="ring-wrap" id="instructorRing">
          <div class="avatar-badge">
            <img src="<?= h($INSTRUCTOR_AVATAR) ?>" alt="Instructor">
          </div>
        </div>

        <div class="ring-wrap" id="studentRing">
          <div class="cam">
            <video id="studentCam" autoplay playsinline muted></video>
            <div class="fallback" id="camFallback">CAM</div>
            <div class="label"><?= h($userName) ?></div>
          </div>
        </div>

        <div class="meta">
          <div class="name"><?= h($INSTRUCTOR_NAME) ?></div>
          <div class="role">AI Instructor</div>
          <div class="muted" style="margin-top:6px;" id="camStatus">Camera permission requested on Start.</div>
        </div>
      </div>

      <div class="sysline" id="sysline">Ready.</div>

      <div class="qstrip" id="qstrip" style="display:none;"></div>

      <button class="ptt" id="btnPTT" type="button" disabled>🎙 Tap to Start Talking</button>

      <div class="timer-wrap">
        <div class="timer-pill"><div class="timer-fill" id="timerFill"></div></div>
        <div class="timer-meta">
          <div>Time to answer</div>
          <div id="timerText">60s</div>
        </div>
      </div>
    </div>

    <div id="resultCard" style="display:none; margin-top:14px;">
      <h2 style="margin:0 0 8px 0;">Result</h2>
      <div id="resultBox"></div>
      <div style="margin-top:12px;">
        <a class="btn" href="/student/course.php?cohort_id=<?= (int)$cohortId ?>">Back to Lesson Menu</a>
      </div>
    </div>
  </div>
</div>

<audio id="qAudio" preload="auto"></audio>
<audio id="qPreload" preload="auto"></audio>

<script>
/* IMPORTANT: this file is a CSS-only fix drop-in.
   Your working JS/logic below should remain exactly as you currently have it.
   If you already pasted the working “I am Ready” flow JS, keep it unchanged.
   This drop-in preserves the full structure + ids so your JS continues to work. */
document.getElementById('jsLed').classList.add('on');
document.getElementById('jsLedTxt').textContent = 'JS OK';
</script>

<?php cw_footer(); ?>