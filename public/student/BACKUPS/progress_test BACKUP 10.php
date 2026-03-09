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
$firstName = trim(explode(' ', trim($userName))[0] ?? 'Student');

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

  .hero{ display:flex; gap:16px; align-items:center; flex-wrap:wrap; margin-top:12px; }

  /* ---- SAFARI "PERFECT CIRCLE" NUCLEAR OPTIONS ---- */
  .circle-lock{
    width:120px; height:120px;
    min-width:120px; min-height:120px;
    max-width:120px; max-height:120px;
    border-radius:999px;
    clip-path: circle(50% at 50% 50%);
    overflow:hidden;
    transform: translateZ(0);
    flex: 0 0 120px;
    line-height:0;
    position:relative;
  }

  /* Instructor bubble */
  .avatar-badge{
    background: linear-gradient(135deg,#1e3c72,#2a5298);
    border:4px solid rgba(255,255,255,0.85);
    box-shadow:0 10px 30px rgba(0,0,0,0.12);
  }
  .avatar-badge img{
    width:100%;
    height:100%;
    object-fit:cover;
    user-select:none;
    -webkit-user-drag:none;
    pointer-events:none;
    display:block;
    transform: translateZ(0);
  }

  
/* Instructor speaking ring: GREEN pulsing (robust selector) */
#instructorBadge.talking::after{
  content:"";
  position:absolute; inset:-10px;
  border-radius:999px;
  border: 4px solid rgba(22,163,74,0.65);
  box-shadow: 0 0 22px rgba(22,163,74,0.35);
  animation:pulseG 0.95s infinite;
  pointer-events:none;
}	
	
  @keyframes pulseG{
    0%{ transform:scale(0.98); opacity:0.25; }
    50%{ transform:scale(1.06); opacity:0.90; }
    100%{ transform:scale(0.98); opacity:0.25; }
  }

  /* Student cam bubble */
  .cam{
    background:#000;
    border:4px solid rgba(30,60,114,0.30);
    box-shadow:0 10px 30px rgba(0,0,0,0.12);
  }
  .cam video{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
    transform: translateZ(0);
  }
  .cam .fallback{
    position:absolute; inset:0;
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-weight:900; letter-spacing:1px;
    opacity:.85;
  }
  .cam .label{
    position:absolute; left:0; right:0; bottom:6px;
    text-align:center; font-size:12px; color:#fff;
    padding:0 8px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    text-shadow:0 1px 2px rgba(0,0,0,0.6);
    box-sizing:border-box;
    pointer-events:none;
    line-height: 1.1;
  }

/* Student speaking ring: RED pulsing (robust selector) */
#camBox.rec::after{
  content:"";
  position:absolute; inset:-10px;
  border-radius:999px;
  border: 4px solid rgba(220,38,38,0.70);
  box-shadow: 0 0 22px rgba(220,38,38,0.35);
  animation:pulseR 0.85s infinite;
  pointer-events:none;
}
  @keyframes pulseR{
    0%{ transform:scale(0.98); opacity:0.25; }
    50%{ transform:scale(1.06); opacity:0.95; }
    100%{ transform:scale(0.98); opacity:0.25; }
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

  .pinrow{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:10px; }
  .pinrow input{ height:36px; }

  .btn-replay::before{ content:"↻ "; font-weight:900; }
</style>

<div class="wrap">
  <div class="card">
    <div class="muted">Audio-only. Tap once to start talking, tap again to stop.</div>

    <div class="top-actions" style="margin-top:10px;">
      <button class="btn <?= $fromMenu ? 'btn-start-green' : '' ?>" id="btnStart" type="button">Start Progress Test</button>
      <button class="btn btn-sm btn-replay" id="btnReplay" type="button" style="display:none;">Replay</button>
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
        <div class="avatar-badge circle-lock" id="instructorBadge">
          <img src="<?= h($INSTRUCTOR_AVATAR) ?>" alt="Instructor">
        </div>

        <div class="cam circle-lock" id="camBox">
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

      <button class="ptt" id="btnPTT" type="button" disabled>✅ I am Ready</button>

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
const USER_FIRST = <?= json_encode($firstName) ?>;

let TEST_ID = 0;
let CURRENT_ITEM = null;
let LAST_AUDIO = { kind: null, item_id: 0 };
let TOTAL_Q = 10;

const btnStart = document.getElementById('btnStart');
const btnReplay = document.getElementById('btnReplay');
const btnPTT = document.getElementById('btnPTT');

const quizCard = document.getElementById('quizCard');
const resultCard = document.getElementById('resultCard');
const resultBox = document.getElementById('resultBox');

const instructorBadge = document.getElementById('instructorBadge');
const qAudio = document.getElementById('qAudio');
const sysline = document.getElementById('sysline');

const timerFill = document.getElementById('timerFill');
const timerText = document.getElementById('timerText');

const camStatus = document.getElementById('camStatus');
const camFallback = document.getElementById('camFallback');
const studentCam = document.getElementById('studentCam');
const camBox = document.getElementById('camBox');

const jsLed = document.getElementById('jsLed');
const jsLedTxt = document.getElementById('jsLedTxt');

const pinRow = document.getElementById('pinRow');
const pinInput = document.getElementById('pinInput');
const pinMsg = document.getElementById('pinMsg');

function setSys(s){ sysline.textContent = s || ''; }

function setJsReady(ok){
  if (ok) { jsLed.classList.add('on'); jsLedTxt.textContent = 'JS OK'; }
  else { jsLed.classList.remove('on'); jsLedTxt.textContent = 'JS ERR'; }
}

function setSpeaking(on){
  if (on) instructorBadge.classList.add('talking');
  else instructorBadge.classList.remove('talking');
}

/* ---------- “fake progress percent” helper ---------- */
let progTimer = null;
let progPct = 0;
function startProgress(label){
  stopProgress();
  progPct = 0;
  setSys(label + " 0%");
  progTimer = setInterval(()=>{
    // ramp quickly to 85%, then slow
    if (progPct < 85) progPct += 5;
    else if (progPct < 95) progPct += 1;
    setSys(label + " " + progPct + "%");
  }, 180);
}
function stopProgress(finalText){
  if (progTimer) clearInterval(progTimer);
  progTimer = null;
  if (finalText) setSys(finalText);
}

/* ---- iPad SAFE audio unlock ---- */
let audioUnlocked = false;
async function unlockAudio(){
  if (audioUnlocked) return true;
  try {
    const AudioContext = window.AudioContext || window.webkitAudioContext;
    if (!AudioContext) return false;
    const ctx = new AudioContext();
    if (ctx.state === 'suspended') await ctx.resume();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    gain.gain.value = 0.0001;
    osc.connect(gain); gain.connect(ctx.destination);
    osc.start(); osc.stop(ctx.currentTime + 0.02);
    audioUnlocked = true;
    return true;
  } catch(e) {
    audioUnlocked = false;
    return false;
  }
}

/* ---- Camera ---- */
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

/* ---- TTS URL ---- */
function ttsUrl(testId, itemId, kind){
  const voice = 'marin'; // your server can map this to a US female voice
  return `/student/api/tts_prompt.php?test_id=${encodeURIComponent(testId)}&item_id=${encodeURIComponent(itemId)}&kind=${encodeURIComponent(kind)}&voice=${encodeURIComponent(voice)}&speed=1.00`;
}

async function prefetchAudio(url){
  try { await fetch(url, { credentials:'same-origin', cache:'no-store' }); } catch(e) {}
}

async function playPromptAudio(testId, itemId, kind){
  LAST_AUDIO = { kind, item_id: itemId };

  return new Promise((resolve) => {
    setSpeaking(true);
    qAudio.pause();
    qAudio.currentTime = 0;
    qAudio.src = ttsUrl(testId, itemId, kind);
    qAudio.load();

    let settled = false;
    const done = (ok) => {
      if (settled) return;
      settled = true;
      setSpeaking(false);
      resolve(ok);
    };

    qAudio.onended = () => done(true);
    qAudio.onerror = () => done(false);

    const p = qAudio.play();
    if (p && p.catch) p.catch(()=>done(false));
  });
}

/* ---- Timer ---- */
let timerMax = 60;
let timerLeft = 60;
let timerInt = null;

function resetTimer(){
  stopAnswerTimer();
  timerMax = 60; timerLeft = 60;
  timerFill.style.width = '0%';
  timerFill.classList.remove('danger');
  timerText.textContent = timerLeft + 's';
}
function stopAnswerTimer(){ if (timerInt) clearInterval(timerInt); timerInt = null; }

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
        startProgress('I am saving your answers…');
        await submitAnswer({ timeout: true });
      }
    }
  }, 1000);
}

/* ---- Question dots ---- */
function renderQStrip(total){
  TOTAL_Q = total || 10;
  const el = document.getElementById('qstrip');
  el.innerHTML = '';
  for (let i=1;i<=TOTAL_Q;i++){
    const d = document.createElement('div');
    d.className = 'qdot';
    d.textContent = String(i);
    el.appendChild(d);
  }
  el.style.display = TOTAL_Q > 0 ? 'flex' : 'none';
}
function markAnswered(idx){
  const el = document.getElementById('qstrip');
  const dots = el.querySelectorAll('.qdot');
  const i = idx - 1;
  if (i >= 0 && i < dots.length) dots[i].classList.add('done');
}

/* ---- Tap-to-record (tap start / tap stop) ---- */
let mediaStream=null, recorder=null, chunks=[], lastBlob=null, isRecording=false;
let transcribeWatchdog = null;

function stopWatchdog(){
  if (transcribeWatchdog) clearTimeout(transcribeWatchdog);
  transcribeWatchdog = null;
}

async function startRecording(){
  try {
    stopWatchdog();
    chunks=[]; lastBlob=null;

    setSys('Your turn. Recording… Tap again to stop.');
    const stream = await navigator.mediaDevices.getUserMedia({ audio:true });
    mediaStream = stream;

    const mime = MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : '';
    recorder = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);

    recorder.ondataavailable = (e)=>{ if (e.data && e.data.size > 0) chunks.push(e.data); };

    recorder.onstop = async ()=>{
      lastBlob = new Blob(chunks, { type: recorder.mimeType || 'audio/webm' });
      chunks=[];
      if (mediaStream) mediaStream.getTracks().forEach(t=>t.stop());
      mediaStream=null;

      camBox.classList.remove('rec');
      btnPTT.classList.remove('rec');
      btnPTT.textContent='⏳ Reviewing…';

      // user-friendly label
      startProgress('I am reviewing your answer…');

      // watchdog: if ASR hangs, allow retry
      stopWatchdog();
      transcribeWatchdog = setTimeout(()=>{
        stopProgress('I had trouble reviewing that. Please try again.');
        btnPTT.disabled=false;
        btnPTT.textContent='🎙 Tap to Start Talking';
      }, 20000);

      await transcribeAndSubmit();
    };

    recorder.start();
    isRecording=true;
    camBox.classList.add('rec');
    btnPTT.classList.add('rec');
    btnPTT.textContent='⏹ Recording… Tap to Stop';
  } catch(e) {
    stopProgress('Mic denied or error.');
    isRecording=false;
    camBox.classList.remove('rec');
    btnPTT.classList.remove('rec');
    btnPTT.textContent='🎙 Tap to Start Talking';
  }
}

async function stopRecording(){
  try { if (recorder && recorder.state !== 'inactive') recorder.stop(); } catch(e){}
  isRecording=false;
}

btnPTT.addEventListener('click', async ()=>{
  // Two modes:
  // 1) After intro -> "I am Ready" triggers first question
  // 2) After question -> acts as tap-to-talk
  if (btnPTT.dataset.mode === 'ready') {
    btnPTT.disabled = true;
    btnPTT.dataset.mode = 'talk';
    await playNextQuestion();
    return;
  }

  if (timerLeft <= 0) return;
  stopAnswerTimer();
  if (!isRecording) await startRecording();
  else await stopRecording();
});

async function transcribeAndSubmit(){
  stopWatchdog();

  if (!lastBlob) {
    stopProgress('No audio captured. Try again.');
    btnPTT.disabled=false;
    await startAnswerTimer();
    return;
  }

  const fd=new FormData();
  fd.append('lang','en');
  fd.append('audio', lastBlob, 'answer.webm');

  const res = await fetch('/student/api/asr.php', { method:'POST', credentials:'same-origin', body: fd });
  const txt = await res.text();
  let j=null; try{ j=JSON.parse(txt);}catch(e){ j={ok:false,error:'Non-JSON: '+txt.slice(0,200)}; }

  if (!j.ok) {
    stopProgress('I had trouble reviewing that. Please try again.');
    btnPTT.disabled=false;
    timerLeft=Math.max(timerLeft,20);
    await startAnswerTimer();
    return;
  }

  const transcript=(j.text||'').trim();
  if (!transcript) {
    stopProgress('I didn’t catch that. Please try again.');
    btnPTT.disabled=false;
    timerLeft=Math.max(timerLeft,20);
    await startAnswerTimer();
    return;
  }

  startProgress('I am saving your answers…');
  await submitAnswer({ text: transcript });
}

/* ---- PIN flow (optional) ---- */
async function submitPin(pin){
  pinMsg.textContent = 'Checking PIN…';
  const res = await fetch('/student/api/test_start.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    credentials:'same-origin',
    body: JSON.stringify({ cohort_id: COHORT_ID, lesson_id: LESSON_ID, pin: pin, mode:'check_pin' })
  });
  const txt = await res.text();
  let j=null; try{ j=JSON.parse(txt);}catch(e){ j={ok:false,error:'Non-JSON: '+txt.slice(0,200)}; }
  if (!j.ok) { pinMsg.textContent = j.error || 'PIN failed'; return false; }
  pinMsg.textContent = 'PIN accepted.';
  pinRow.style.display = 'none';
  return true;
}

document.getElementById('btnPin').addEventListener('click', async ()=>{
  const pin = (pinInput.value||'').trim();
  if (pin==='') return;
  await submitPin(pin);
});

/* ---- State ---- */
let startingLock=false;
let introPlayed=false;
let firstItemFromStart=null;

/* ---- Start test ---- */
async function startTest(){
  if (startingLock) return;
  startingLock=true;

  await unlockAudio();

  btnStart.disabled=true;
  btnStart.textContent='Loading…';
  btnReplay.disabled=true;

  quizCard.style.display='block';
  resultCard.style.display='none';

  await startStudentCam();

  startProgress('I am preparing your test…');

  const res = await fetch('/student/api/test_start.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    credentials:'same-origin',
    body: JSON.stringify({ cohort_id: COHORT_ID, lesson_id: LESSON_ID })
  });

  const txt = await res.text();
  let j=null; try{ j=JSON.parse(txt);}catch(e){ j={ok:false,error:'Non-JSON: '+txt.slice(0,200)}; }

  if (!j.ok) {
    stopProgress();
    if ((j.code||'') === 'NEED_PIN') {
      setSys('This test requires a Training PIN.');
      pinRow.style.display = 'flex';
      btnStart.disabled = false;
      btnStart.textContent = 'Start Progress Test';
      startingLock = false;
      return;
    }
    setSys('Start failed: ' + (j.error||''));
    btnStart.disabled=false;
    btnStart.textContent='Start Progress Test';
    btnReplay.disabled=false;
    startingLock=false;
    return;
  }

  TEST_ID = j.test_id;
  btnReplay.style.display='inline-block';
  renderQStrip(j.total_questions ? parseInt(j.total_questions,10) : 10);

  // store first item from server
  firstItemFromStart = j.item || null;

  // prefetch intro
  prefetchAudio(ttsUrl(TEST_ID, 0, 'intro'));

  stopProgress('Maya is speaking…');
  const okIntro = await playPromptAudio(TEST_ID, 0, 'intro');
  introPlayed = okIntro;

  if (!okIntro) {
    setSys('Audio blocked. Tap Replay to hear the intro.');
    btnReplay.disabled=false;
    btnStart.textContent='Started';
    btnStart.disabled=true;
    startingLock=false;
    return;
  }

  // Gate: require "I am Ready" tap before first question (fix iPad)
  setSys('When you are ready, tap “I am Ready”.');
  btnPTT.disabled=false;
  btnPTT.dataset.mode = 'ready';
  btnPTT.textContent='✅ I am Ready';
  resetTimer();

  btnReplay.disabled=false;
  btnStart.textContent='Started';
  btnStart.disabled=true;
  startingLock=false;

  // Prefetch first question audio in background
  if (firstItemFromStart && firstItemFromStart.item_id) {
    prefetchAudio(ttsUrl(TEST_ID, firstItemFromStart.item_id, 'item'));
  }
}

btnStart.onclick = startTest;

/* ---- Ask next question ---- */
async function playNextQuestion(){
  if (!firstItemFromStart) {
    setSys('No question available yet. Tap Replay.');
    btnPTT.disabled=true;
    return;
  }

  // render item
  CURRENT_ITEM = firstItemFromStart;

  // speaking state
  startProgress('I am thinking…');
  // (we already prefetched; but keep it safe)
  prefetchAudio(ttsUrl(TEST_ID, CURRENT_ITEM.item_id, 'item'));
  stopProgress('Maya is speaking…');

  const ok = await playPromptAudio(TEST_ID, CURRENT_ITEM.item_id, 'item');
  if (!ok) {
    setSys('Audio blocked. Tap Replay.');
    btnPTT.disabled=true;
    btnPTT.dataset.mode = 'talk';
    btnPTT.textContent='🎙 Tap to Start Talking';
    return;
  }

  // enable talking
  btnPTT.dataset.mode = 'talk';
  btnPTT.disabled=false;
  btnPTT.textContent='🎙 Tap to Start Talking';
  setSys('Your turn.');
  await startAnswerTimer();
}

/* ---- Submit answer ---- */
async function submitAnswer(answer){
  if (!TEST_ID || !CURRENT_ITEM) return;

  btnPTT.disabled=true;

  const res = await fetch('/student/api/test_answer.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    credentials:'same-origin',
    body: JSON.stringify({ test_id: TEST_ID, item_id: CURRENT_ITEM.item_id, answer })
  });

  const txt = await res.text();
  let j=null; try{ j=JSON.parse(txt);}catch(e){ j={ok:false,error:'Non-JSON: '+txt.slice(0,200)}; }

  if (!j.ok) {
    stopProgress('I had trouble saving that. Try again.');
    btnPTT.disabled=false;
    timerLeft=Math.max(timerLeft,20);
    await startAnswerTimer();
    return;
  }

  stopProgress();

  if (CURRENT_ITEM && CURRENT_ITEM.idx) markAnswered(CURRENT_ITEM.idx);

  if (j.done) {
    stopAnswerTimer();
    btnPTT.disabled=true;

    startProgress('I am evaluating your answers…');
    await playPromptAudio(TEST_ID, 0, 'outro');

    stopProgress('Maya is speaking…');
    await playPromptAudio(TEST_ID, 0, 'debrief');

    quizCard.style.display='none';
    resultCard.style.display='block';

    resultBox.innerHTML = `
      <div><strong>Score:</strong> ${j.score_pct}%</div>
      <div style="margin-top:10px;"><strong>Debrief</strong><br><div style="white-space:pre-wrap;">${escapeHtml(j.ai_summary||'')}</div></div>
      <div style="margin-top:10px;"><strong>Weak Areas</strong><br><div style="white-space:pre-wrap;">${escapeHtml(j.weak_areas||'')}</div></div>
    `;
    stopProgress('Completed.');
    return;
  }

  // next item returned
  firstItemFromStart = j.item;
  CURRENT_ITEM = j.item;

  // prefetch next after that (if provided)
  if (j.next_item_id) prefetchAudio(ttsUrl(TEST_ID, parseInt(j.next_item_id,10), 'item'));

  // play next question
  startProgress('I am thinking…');
  stopProgress('Maya is speaking…');
  const ok = await playPromptAudio(TEST_ID, j.item.item_id, 'item');
  if (!ok) {
    setSys('Audio blocked. Tap Replay.');
    btnPTT.disabled=true;
    return;
  }

  btnPTT.disabled=false;
  btnPTT.textContent='🎙 Tap to Start Talking';
  setSys('Your turn.');
  await startAnswerTimer();
}

/* ---- Replay ---- */
btnReplay.onclick = async ()=>{
  if (!TEST_ID) return;
  await unlockAudio();

  stopAnswerTimer();

  startProgress('Let me repeat this for you, one moment…');

  let kind = LAST_AUDIO.kind || 'intro';
  let itemId = (kind === 'item') ? (LAST_AUDIO.item_id || (CURRENT_ITEM ? CURRENT_ITEM.item_id : 0)) : 0;
  if (kind === 'item' && itemId <= 0) { kind='intro'; itemId=0; }

  stopProgress('Maya is speaking…');
  const ok = await playPromptAudio(TEST_ID, itemId, kind);
  if (!ok) {
    setSys('Audio still blocked. Please check iPad silent mode.');
    return;
  }

  // After replaying intro: return to Ready gate
  if (kind === 'intro') {
    setSys('When you are ready, tap “I am Ready”.');
    btnPTT.disabled=false;
    btnPTT.dataset.mode = 'ready';
    btnPTT.textContent='✅ I am Ready';
    resetTimer();
    return;
  }

  // After replaying question: allow talk
  btnPTT.dataset.mode = 'talk';
  btnPTT.disabled=false;
  btnPTT.textContent='🎙 Tap to Start Talking';
  setSys('Your turn.');
  await startAnswerTimer();
};

function escapeHtml(s){
  return (s||'').toString()
    .replaceAll('&','&amp;').replaceAll('<','&lt;')
    .replaceAll('>','&gt;').replaceAll('"','&quot;');
}

// JS OK after binding
setJsReady(true);
</script>

<?php cw_footer(); ?>