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

  .hero{ display:flex; gap:14px; align-items:center; flex-wrap:wrap; margin-top:12px; }

  .avatar-badge{
    width:120px;height:120px;border-radius:999px;
    background: linear-gradient(135deg,#1e3c72,#2a5298);
    display:flex;align-items:center;justify-content:center;
    overflow:hidden;
    border:4px solid rgba(255,255,255,0.85);
    box-shadow:0 10px 30px rgba(0,0,0,0.12);
    position:relative;
  }
  .avatar-badge img{
    width:120%;height:120%;
    object-fit:cover;
    transform: translateY(6px);
    user-select:none;-webkit-user-drag:none;pointer-events:none;
  }

  .talking::after{
    content:"";
    position:absolute; inset:-10px; border-radius:999px;
    border: 4px solid rgba(46,128,255,0.55);
    box-shadow: 0 0 18px rgba(46,128,255,0.25);
    animation:pulse 0.95s infinite;
  }
  @keyframes pulse{ 0%{transform:scale(0.98);opacity:0.25} 50%{transform:scale(1.06);opacity:0.80} 100%{transform:scale(0.98);opacity:0.25} }

  .cam{
    width:120px;height:120px;border-radius:999px;
    overflow:hidden;background:#000;
    border:4px solid rgba(30,60,114,0.30);
    box-shadow:0 10px 30px rgba(0,0,0,0.12);
    position:relative;
  }
  .cam video{ width:100%;height:100%;object-fit:cover;border-radius:999px; }
  .cam .fallback{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:900; letter-spacing:1px; opacity:.85; border-radius:999px; }
  .cam .label{
    position:absolute;left:0;right:0;bottom:6px;
    text-align:center;font-size:12px;color:#fff;
    padding:0 8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    text-shadow:0 1px 2px rgba(0,0,0,0.6);
    box-sizing:border-box;
  }

  .meta{ line-height:1.1; }
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

    <div id="quizCard" style="display:none; margin-top:14px;">
      <div class="hero">
        <div class="avatar-badge" id="instructorBadge">
          <img src="<?= h($INSTRUCTOR_AVATAR) ?>" alt="Instructor">
        </div>
        <div class="cam">
          <video id="studentCam" autoplay playsinline muted></video>
          <div class="fallback" id="camFallback">CAM</div>
          <div class="label"><?= h($userName) ?></div>
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

<script>
const COHORT_ID = <?= (int)$cohortId ?>;
const LESSON_ID = <?= (int)$lessonId ?>;

let TEST_ID = 0;
let CURRENT_ITEM = null;

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

function setJsReady(ok){
  if (ok) { jsLed.classList.add('on'); jsLedTxt.textContent = 'JS OK'; }
  else { jsLed.classList.remove('on'); jsLedTxt.textContent = 'JS ERR'; }
}

function setSpeaking(on){
  if (on) instructorBadge.classList.add('talking');
  else instructorBadge.classList.remove('talking');
}

// ✅ iPad SAFE audio unlock using WebAudio (no base64)
let audioUnlocked = false;
async function unlockAudio(){
  if (audioUnlocked) return true;

  try {
    const AudioContext = window.AudioContext || window.webkitAudioContext;
    if (!AudioContext) return false;

    const ctx = new AudioContext();
    if (ctx.state === 'suspended') {
      await ctx.resume();
    }

    // tiny near-silent oscillator burst (inaudible)
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    gain.gain.value = 0.0001;

    osc.connect(gain);
    gain.connect(ctx.destination);

    osc.start();
    osc.stop(ctx.currentTime + 0.02);

    audioUnlocked = true;
    return true;
  } catch (e) {
    audioUnlocked = false;
    return false;
  }
}

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

// TTS URL
function ttsUrl(testId, itemId, kind){
  const voice = 'marin';
  return `/student/api/tts_prompt.php?test_id=${encodeURIComponent(testId)}&item_id=${encodeURIComponent(itemId)}&kind=${encodeURIComponent(kind)}&voice=${encodeURIComponent(voice)}&speed=1.00`;
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

// Timer
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

// Dots (default 10)
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

// Tap-to-record
let mediaStream = null;
let recorder = null;
let chunks = [];
let lastBlob = null;
let isRecording = false;

function canRecord(){ return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia); }

async function startRecording(){
  if (!canRecord()) { setSys('Mic not supported.'); return; }
  try {
    setSys('Recording… tap again to stop.');
    chunks = []; lastBlob = null;

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
  try { if (recorder && recorder.state !== 'inactive') recorder.stop(); } catch(e){}
  isRecording = false;
  btnPTT.classList.remove('rec');
  btnPTT.textContent = '🎙 Tap to Start Talking';
}
btnPTT.addEventListener('click', async ()=>{
  if (timerLeft <= 0) return;
  stopAnswerTimer();
  if (!isRecording) await startRecording();
  else await stopRecording();
});

async function transcribeAndSubmit(){
  if (!lastBlob) { setSys('No audio captured.'); await startAnswerTimer(); return; }

  const fd = new FormData();
  fd.append('lang', 'en');
  fd.append('audio', lastBlob, 'answer.webm');

  const res = await fetch('/student/api/asr.php', { method:'POST', credentials:'same-origin', body: fd });
  const txt = await res.text();
  let j=null; try{ j=JSON.parse(txt);}catch(e){ j={ok:false,error:'Non-JSON: '+txt.slice(0,200)}; }

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

  // Make sure button really works + show feedback
  btnStart.disabled = true;
  btnStart.textContent = 'Loading…';
  btnReplay.disabled = true;

  quizCard.style.display = 'block';
  resultCard.style.display = 'none';

  // Unlock audio on same tap gesture (iPad)
  await unlockAudio();

  await startStudentCam();

  setSys('Maya is preparing your test…');

  const res = await fetch('/student/api/test_start.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    credentials:'same-origin',
    body: JSON.stringify({ cohort_id: COHORT_ID, lesson_id: LESSON_ID })
  });

  const txt = await res.text();
  let j=null; try{ j=JSON.parse(txt);}catch(e){ j={ok:false,error:'Non-JSON: '+txt.slice(0,200)}; }

  if (!j.ok) {
    setSys('Start failed: ' + (j.error||''));
    btnStart.disabled = false;
    btnStart.textContent = 'Start Progress Test';
    btnReplay.disabled = false;
    startingLock = false;
    return;
  }

  TEST_ID = j.test_id;
  btnReplay.style.display = 'inline-block';
  renderQStrip(10);

  setSys('Maya is speaking…');
  const okIntro = await playPromptAudio(TEST_ID, 0, 'intro');
  if (!okIntro) {
    setSys('Audio blocked. Tap Replay to hear the intro.');
    btnReplay.disabled = false;
    btnStart.textContent = 'Started';
    startingLock = false;
    return;
  }

  renderItem(j.item);
  setSys('Maya is speaking…');
  const okQ = await playPromptAudio(TEST_ID, j.item.item_id, 'item');
  if (!okQ) {
    setSys('Audio blocked. Tap Replay to hear the question.');
    btnReplay.disabled = false;
    btnStart.textContent = 'Started';
    startingLock = false;
    return;
  }

  setSys('Your turn.');
  await startAnswerTimer();

  btnReplay.disabled = false;
  btnStart.textContent = 'Started';
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
    body: JSON.stringify({ test_id: TEST_ID, item_id: CURRENT_ITEM.item_id, answer })
  });

  const txt = await res.text();
  let j=null; try{ j=JSON.parse(txt);}catch(e){ j={ok:false,error:'Non-JSON: '+txt.slice(0,200)}; }

  if (!j.ok) {
    setSys('Answer failed: ' + (j.error||''));
    btnPTT.disabled = false;
    timerLeft = Math.max(timerLeft, 20);
    await startAnswerTimer();
    return;
  }

  if (CURRENT_ITEM && CURRENT_ITEM.idx) markAnswered(CURRENT_ITEM.idx);

  if (j.done) {
    stopAnswerTimer();
    btnPTT.disabled = true;

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

  renderItem(j.item);
  setSys('Loading next question…');
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

// Mark JS ok only after bindings are set (helps debugging)
setJsReady(true);
</script>

<?php cw_footer(); ?>