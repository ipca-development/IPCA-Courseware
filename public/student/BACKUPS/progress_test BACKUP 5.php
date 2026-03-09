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

// Student must be enrolled
if ($role === 'student') {
    $check = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id=? AND user_id=? LIMIT 1");
    $check->execute([$cohortId, (int)$u['id']]);
    if (!$check->fetchColumn()) {
        http_response_code(403);
        exit('Not enrolled in this cohort');
    }
}

$userName = (string)($u['name'] ?? 'Student');

// Instructor config (later: from cohort)
$INSTRUCTOR_NAME = 'Maya';
$INSTRUCTOR_AVATAR = '/assets/avatars/maya.png';

// UI hint: coming from lesson menu
$fromMenu = ((string)($_GET['from'] ?? '') === 'menu');

cw_header('Progress Test');
?>
<style>
  body { background:#ffffff; }
  .pt-wrap{ max-width: 1100px; margin: 0 auto; }
  .pt-top{ display:flex; gap:14px; align-items:flex-start; flex-wrap:wrap; }
  .pt-card{ flex: 1 1 560px; }
  .pt-side{ width: 340px; min-width: 300px; }

  .pt-actions{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
  .pt-status{ font-size:13px; opacity:.8; }

  /* JS ready LED */
  .led{
    width:12px; height:12px; border-radius:999px;
    background:#dc2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.12);
    display:inline-block;
    vertical-align:middle;
  }
  .led.on{
    background:#16a34a;
    box-shadow: 0 0 0 3px rgba(22,163,74,0.14);
  }
  .led-label{ font-size:12px; opacity:.75; display:flex; gap:6px; align-items:center; }

  /* Instructor + student */
  .avatar-stack{ display:flex; flex-direction:column; gap:12px; }
  .avatar-row{ display:flex; align-items:center; gap:10px; }
  .avatar-meta{ line-height:1.1; }
  .avatar-meta .name{ font-weight:900; color:#1e3c72; }
  .avatar-meta .role{ font-size:12px; opacity:.7; }

  .avatar-badge{
    width:132px; height:132px;
    border-radius:999px;
    background: linear-gradient(135deg, #1e3c72, #2a5298);
    display:flex; align-items:center; justify-content:center;
    overflow:hidden;
    border: 4px solid rgba(255,255,255,0.85);
    box-shadow: 0 10px 30px rgba(0,0,0,0.12);
    position:relative;
  }
  .avatar-badge img{
    width:120%; height:120%;
    object-fit:cover;
    transform: translateY(6px);
    user-select:none;
    -webkit-user-drag:none;
    pointer-events:none;
  }

  /* Speaking ring: stronger and only while audio is playing */
  .talking::after{
    content:"";
    position:absolute;
    inset:-10px;
    border-radius:999px;
    border: 4px solid rgba(46,128,255,0.55);
    box-shadow: 0 0 18px rgba(46,128,255,0.25);
    animation: pulse 0.95s infinite;
  }
  @keyframes pulse{
    0%{ transform:scale(0.98); opacity:0.25; }
    50%{ transform:scale(1.06); opacity:0.80; }
    100%{ transform:scale(0.98); opacity:0.25; }
  }

  .student-cam{
    width:132px; height:132px;
    border-radius:999px;
    overflow:hidden;
    background:#000; /* stays black */
    border: 4px solid rgba(30,60,114,0.30);
    box-shadow: 0 10px 30px rgba(0,0,0,0.12);
    position:relative;
  }
  .student-cam video{
    width:100%; height:100%;
    object-fit:cover;
    border-radius:999px;
  }
  .student-cam .cam-label{
    position:absolute; left:0; right:0; bottom:6px;
    text-align:center;
    font-size:12px;
    color:#fff;
    text-shadow: 0 1px 2px rgba(0,0,0,0.6);
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    padding:0 8px;
    box-sizing:border-box;
  }
  .student-cam .cam-fallback{
    position:absolute; inset:0;
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-weight:900;
    opacity:.85;
    letter-spacing:1px;
    border-radius:999px;
  }

  /* Start button highlight (green) when from menu */
  .btn-start-green{
    background:#16a34a !important;
    border-color:#12813c !important;
    color:#fff !important;
  }
  .btn-start-green:hover{ background:#138a3f !important; }

  /* Big system status under instructor avatar */
  .sysline{
    margin-top:12px;
    padding:10px 12px;
    border:1px solid #eee;
    border-radius:12px;
    background:#fafafa;
    font-weight:800;
    color:#1e3c72;
  }
  .sysline.muted{ font-weight:700; opacity:.85; }

  /* Push-to-talk */
  .ptt{
    width:100%;
    margin-top:14px;
    padding:16px 14px;
    border-radius:16px;
    border: 2px solid rgba(30,60,114,0.25);
    background: rgba(30,60,114,0.08);
    color:#1e3c72;
    font-weight:900;
    font-size:18px;
    cursor:pointer;
    user-select:none;
  }
  .ptt:hover{ background: rgba(30,60,114,0.12); }
  .ptt.rec{
    background: rgba(220,38,38,0.12);
    border-color: rgba(220,38,38,0.35);
    color:#b91c1c;
  }
  .ptt:disabled{ opacity:.5; cursor:not-allowed; }

  /* Timer pill */
  .timer-wrap{ margin-top:12px; }
  .timer-pill{
    height:14px;
    border-radius:999px;
    background:#eee;
    overflow:hidden;
    border:1px solid #e6e6e6;
  }
  .timer-fill{
    height:14px;
    width:0%;
    background:#1e3c72;
    transition: width 0.25s linear;
  }
  .timer-fill.danger{ background:#dc2626; }
  .timer-meta{
    display:flex;
    justify-content:space-between;
    font-size:12px;
    opacity:.75;
    margin-top:6px;
  }

  /* Question progress dots */
  .qstrip{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
    margin-top:12px;
  }
  .qdot{
    width:28px; height:28px;
    border-radius:999px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:12px;
    font-weight:900;
    background: rgba(30,60,114,0.10);
    border:2px solid rgba(30,60,114,0.35);
    color:#1e3c72;
  }
  .qdot.done{
    background: rgba(22,163,74,0.14);
    border-color: rgba(22,163,74,0.55);
    color:#166534;
  }
</style>

<div class="pt-wrap">

  <!-- Single title only (fix #1) -->
  <div class="card">
    <h1 style="margin:0 0 6px 0;">Progress Test</h1>
    <div class="muted">Audio-only. Tap once to start talking, tap again to stop.</div>
  </div>

  <div class="pt-top">
    <div class="card pt-card">
      <div class="pt-actions">
        <button class="btn <?= $fromMenu ? 'btn-start-green' : '' ?>" id="btnStart" type="button">Start Progress Test</button>
        <button class="btn btn-sm" id="btnReplay" type="button" style="display:none;">↻ Replay</button>
        <a class="btn btn-sm" href="/student/course.php?cohort_id=<?= (int)$cohortId ?>">Back to Lesson Menu</a>

        <span class="led-label" style="margin-left:auto;">
          <span class="led" id="jsLed"></span>
          <span id="jsLedTxt">JS</span>
        </span>
      </div>

      <div id="quizCard" style="display:none; margin-top:14px;">
        <!-- Instructor block above controls (fix #9) -->
        <div style="display:flex; align-items:center; gap:12px;">
          <div class="avatar-badge" id="instructorBadge" style="width:92px;height:92px;">
            <img src="<?= h($INSTRUCTOR_AVATAR) ?>" alt="Instructor">
          </div>
          <div>
            <div style="font-weight:900; color:#1e3c72; font-size:18px;"><?= h($INSTRUCTOR_NAME) ?></div>
            <div class="muted" style="font-size:12px;">AI Instructor</div>
          </div>
        </div>

        <div class="sysline" id="sysline">Ready.</div>

        <!-- Question progress strip (fix #10) -->
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

    <div class="card pt-side">
      <h2 style="margin:0 0 10px 0;">Presence</h2>

      <div class="avatar-stack">
        <div class="avatar-row">
          <div class="avatar-badge">
            <img src="<?= h($INSTRUCTOR_AVATAR) ?>" alt="Instructor">
          </div>
          <div class="avatar-meta">
            <div class="name"><?= h($INSTRUCTOR_NAME) ?></div>
            <div class="role">AI Instructor</div>
          </div>
        </div>

        <div class="avatar-row">
          <div class="student-cam">
            <video id="studentCam" autoplay playsinline muted></video>
            <div class="cam-fallback" id="camFallback">CAM</div>
            <div class="cam-label"><?= h($userName) ?></div>
          </div>
          <div class="avatar-meta">
            <div class="name"><?= h($userName) ?></div>
            <div class="role">Student camera</div>
            <div class="muted" style="margin-top:6px;" id="camStatus">Camera permission requested on Start.</div>
          </div>
        </div>
      </div>

      <!-- (fix #7) removed the "Next version…" text -->
    </div>
  </div>

</div>

<audio id="qAudio" preload="none"></audio>

<script>
const COHORT_ID = <?= (int)$cohortId ?>;
const LESSON_ID = <?= (int)$lessonId ?>;

let TEST_ID = 0;
let CURRENT_ITEM = null;
let TOTAL_Q = 0;
let ANSWERED_Q = 0;

const btnStart = document.getElementById('btnStart');
const btnReplay = document.getElementById('btnReplay');

const quizCard = document.getElementById('quizCard');
const resultCard = document.getElementById('resultCard');
const resultBox = document.getElementById('resultBox');

const instructorBadge = document.getElementById('instructorBadge');
const qAudio = document.getElementById('qAudio');

const btnPTT = document.getElementById('btnPTT');
const sysline = document.getElementById('sysline');

const timerFill = document.getElementById('timerFill');
const timerText = document.getElementById('timerText');

const camStatus = document.getElementById('camStatus');
const camFallback = document.getElementById('camFallback');
const studentCam = document.getElementById('studentCam');

const jsLed = document.getElementById('jsLed');
const jsLedTxt = document.getElementById('jsLedTxt');

function setSys(s){ sysline.textContent = s || ''; }
function setSpeaking(on){
  if (on) instructorBadge.classList.add('talking');
  else instructorBadge.classList.remove('talking');
}

// JS LED (fix #5)
function setJsReady(ok){
  if (ok) {
    jsLed.classList.add('on');
    jsLedTxt.textContent = 'JS OK';
  } else {
    jsLed.classList.remove('on');
    jsLedTxt.textContent = 'JS ERR';
  }
}
setJsReady(true);

// Camera
async function startStudentCam(){
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){
    camStatus.textContent = 'Camera not supported.';
    return;
  }
  try{
    camStatus.textContent = 'Requesting camera permission…';
    const stream = await navigator.mediaDevices.getUserMedia({ video:true, audio:false });
    studentCam.srcObject = stream;
    camFallback.style.display = 'none';
    camStatus.textContent = 'Camera active.';
  }catch(e){
    camStatus.textContent = 'Camera denied (ok).';
    camFallback.style.display = 'flex';
  }
}

// TTS URL — force a nicer female voice (fix #3)
// NOTE: this requires tts_prompt.php to accept &voice=. If it doesn't yet, tell me and I’ll give that 5-line drop-in.
function ttsUrl(testId, itemId, kind){
  const voice = 'fable'; // nice female
  return `/student/api/tts_prompt.php?test_id=${encodeURIComponent(testId)}&item_id=${encodeURIComponent(itemId)}&kind=${encodeURIComponent(kind)}&voice=${encodeURIComponent(voice)}`;
}

async function playPromptAudio(testId, itemId, kind){
  return new Promise((resolve) => {
    setSpeaking(true);
    qAudio.pause();
    qAudio.currentTime = 0;
    qAudio.src = ttsUrl(testId, itemId, kind);

    qAudio.onended = () => { setSpeaking(false); resolve(true); };
    qAudio.onerror = () => { setSpeaking(false); resolve(false); };

    qAudio.play().then(()=>{}).catch(()=>{
      setSpeaking(false);
      resolve(false);
    });
  });
}

// ---------- TIMER BAR (fix #2 reset each question) ----------
let timerMax = 60;
let timerLeft = 60;
let timerInt = null;

function resetTimer(){
  stopAnswerTimer();
  timerMax = 60;
  timerLeft = 60;
  timerFill.style.width = '0%';
  timerFill.classList.remove('danger');
  timerText.textContent = timerLeft + 's';
}

function stopAnswerTimer(){
  if (timerInt) clearInterval(timerInt);
  timerInt = null;
}

async function startAnswerTimer(){
  resetTimer();

  timerInt = setInterval(async ()=>{
    timerLeft -= 1;
    if (timerLeft < 0) timerLeft = 0;

    const pct = Math.round(((timerMax - timerLeft) / timerMax) * 100);
    timerFill.style.width = pct + '%';
    timerText.textContent = timerLeft + 's';

    if (timerLeft <= 10) timerFill.classList.add('danger');

    if (timerLeft <= 0) {
      stopAnswerTimer();
      if (!isRecording) {
        setSys('No answer received. Moving on…');
        await submitAnswer({ timeout: true });
      }
    }
  }, 1000);
}

// ---------- Question strip (fix #10) ----------
function renderQStrip(total){
  const el = document.getElementById('qstrip');
  el.innerHTML = '';
  for (let i=1;i<=total;i++){
    const d = document.createElement('div');
    d.className = 'qdot';
    d.textContent = String(i);
    el.appendChild(d);
  }
  el.style.display = total > 0 ? 'flex' : 'none';
}

function markAnswered(idx){
  const el = document.getElementById('qstrip');
  const dots = el.querySelectorAll('.qdot');
  const i = idx - 1;
  if (i >= 0 && i < dots.length) dots[i].classList.add('done');
}

// ---------- TAP-TO-RECORD ----------
let mediaStream = null;
let recorder = null;
let chunks = [];
let lastBlob = null;
let isRecording = false;

function canRecord(){
  return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
}

async function startRecording(){
  if (!canRecord()) { setSys('Mic not supported on this device.'); return; }

  try {
    setSys('Recording… tap again to stop.');
    chunks = [];
    lastBlob = null;

    const stream = await navigator.mediaDevices.getUserMedia({ audio:true });
    mediaStream = stream;

    const mime = MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : '';
    recorder = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);

    recorder.ondataavailable = (e)=>{ if (e.data && e.data.size > 0) chunks.push(e.data); };

    recorder.onstop = async ()=>{
      lastBlob = new Blob(chunks, { type: recorder.mimeType || 'audio/webm' });
      chunks = [];
      if (mediaStream) mediaStream.getTracks().forEach(t=>t.stop());
      mediaStream = null;

      setSys('Transcribing…');
      await transcribeAndSubmit();
    };

    recorder.start();
    isRecording = true;
    btnPTT.classList.add('rec');
    btnPTT.textContent = '⏺ Recording… Tap to Stop';
  } catch (e) {
    setSys('Mic denied or error.');
    isRecording = false;
    btnPTT.classList.remove('rec');
    btnPTT.textContent = '🎙 Tap to Start Talking';
  }
}

async function stopRecording(){
  try {
    if (recorder && recorder.state !== 'inactive') recorder.stop();
  } catch(e){}
  isRecording = false;
  btnPTT.classList.remove('rec');
  btnPTT.textContent = '🎙 Tap to Start Talking';
}

btnPTT.addEventListener('click', async ()=>{
  if (timerLeft <= 0) return;

  // Stop timeout timer during recording
  stopAnswerTimer();

  if (!isRecording) await startRecording();
  else await stopRecording();
});

async function transcribeAndSubmit(){
  if (!lastBlob) { setSys('No audio captured.'); await startAnswerTimer(); return; }

  const fd = new FormData();
  fd.append('lang', 'en'); // oral test in English
  const ext = 'webm';
  fd.append('audio', lastBlob, 'answer.' + ext);

  const res = await fetch('/student/api/asr.php', {
    method:'POST',
    credentials:'same-origin',
    body: fd
  });

  const txt = await res.text();
  let j = null;
  try { j = JSON.parse(txt); } catch(e){ j = {ok:false, error:'Non-JSON: ' + txt.slice(0,200)}; }

  if (!j.ok) {
    setSys('ASR failed: ' + (j.error||''));
    timerLeft = Math.max(timerLeft, 20);
    await startAnswerTimer();
    return;
  }

  const transcript = (j.text || '').trim();
  if (!transcript) {
    setSys('No speech detected. Try again.');
    timerLeft = Math.max(timerLeft, 20);
    await startAnswerTimer();
    return;
  }

  setSys('Answer received. Evaluating…');
  await submitAnswer({ text: transcript });
}

// ---------- QUIZ FLOW ----------
function renderItem(item){
  CURRENT_ITEM = item;
  btnPTT.disabled = false;
  btnPTT.classList.remove('rec');
  btnPTT.textContent = '🎙 Tap to Start Talking';
  isRecording = false;
  lastBlob = null;
}

let startingLock = false;

async function startTest(){
  if (startingLock) return;
  startingLock = true;

  setSys('Starting… please wait.');
  btnStart.disabled = true;
  btnReplay.disabled = true;

  await startStudentCam();

  // FIX #8: show loading while intro is generated/loaded
  setSys('Loading instructor…');

  const res = await fetch('/student/api/test_start.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    credentials:'same-origin',
    body: JSON.stringify({ cohort_id: COHORT_ID, lesson_id: LESSON_ID })
  });

  const txt = await res.text();
  let j = null;
  try { j = JSON.parse(txt); } catch(e){ j = {ok:false, error:'Non-JSON: '+txt.slice(0,200)}; }

  if (!j.ok) {
    setSys('Start failed: ' + (j.error||''));
    btnStart.disabled = false;
    btnReplay.disabled = false;
    startingLock = false;
    return;
  }

  TEST_ID = j.test_id;
  quizCard.style.display = 'block';
  resultCard.style.display = 'none';
  btnReplay.style.display = 'inline-block';

  // Optional: create strip after first item known
  // We'll set TOTAL_Q opportunistically later (we don't know count from API yet).
  // For now, display 10 default dots (you can refine later by returning count)
  TOTAL_Q = 10;
  renderQStrip(TOTAL_Q);

  // Intro (fix #4 + fix #8 wording + lock)
  setSys('Maya is speaking…');
  await playPromptAudio(TEST_ID, 0, 'intro');

  // First question
  renderItem(j.item);
  setSys('Maya is speaking…');
  await playPromptAudio(TEST_ID, j.item.item_id, 'item');

  setSys('Your turn.');
  await startAnswerTimer();

  btnReplay.disabled = false;
  startingLock = false;
}

async function submitAnswer(answer){
  if (!TEST_ID || !CURRENT_ITEM) return;

  btnPTT.disabled = true;
  setSys('Saving your answer…');

  const res = await fetch('/student/api/test_answer.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    credentials:'same-origin',
    body: JSON.stringify({
      test_id: TEST_ID,
      item_id: CURRENT_ITEM.item_id,
      answer: answer
    })
  });

  const txt = await res.text();
  let j = null;
  try { j = JSON.parse(txt); } catch(e){ j = {ok:false, error:'Non-JSON: '+txt.slice(0,200)}; }

  if (!j.ok) {
    setSys('Answer failed: ' + (j.error||''));
    btnPTT.disabled = false;
    timerLeft = Math.max(timerLeft, 20);
    await startAnswerTimer();
    return;
  }

  // Mark answered dot
  if (CURRENT_ITEM && CURRENT_ITEM.idx) markAnswered(CURRENT_ITEM.idx);

  if (j.done) {
    stopAnswerTimer();
    btnPTT.disabled = true;

    // Outro + debrief (spoken)
    setSys('Maya is evaluating…');
    await playPromptAudio(TEST_ID, 0, 'outro');

    setSys('Maya is speaking…');
    await playPromptAudio(TEST_ID, 0, 'debrief');

    quizCard.style.display = 'none';
    resultCard.style.display = 'block';
    resultBox.innerHTML = `
      <div><strong>Score:</strong> ${j.score_pct}%</div>
      <div style="margin-top:10px;"><strong>Debrief</strong><br><div style="white-space:pre-wrap;">${escapeHtml(j.ai_summary||'')}</div></div>
      <div style="margin-top:10px;"><strong>Weak Areas</strong><br><div style="white-space:pre-wrap;">${escapeHtml(j.weak_areas||'')}</div></div>
    `;
    setSys('Completed.');
    return;
  }

  // Next item
  renderItem(j.item);
  setSys('Loading next question…');

  // small pause so UI feels intentional
  await new Promise(r=>setTimeout(r, 250));

  setSys('Maya is speaking…');
  await playPromptAudio(TEST_ID, j.item.item_id, 'item');

  setSys('Your turn.');
  await startAnswerTimer();
}

btnStart.onclick = startTest;
btnReplay.onclick = async ()=>{
  if (!TEST_ID || !CURRENT_ITEM) return;
  stopAnswerTimer();
  setSys('Replaying…');
  await playPromptAudio(TEST_ID, CURRENT_ITEM.item_id, 'item');
  setSys('Your turn.');
  await startAnswerTimer();
};

function escapeHtml(s){
  return (s||'').toString()
    .replaceAll('&','&amp;').replaceAll('<','&lt;')
    .replaceAll('>','&gt;').replaceAll('"','&quot;');
}
</script>

<?php cw_footer(); ?>