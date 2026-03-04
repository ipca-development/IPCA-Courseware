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

// Instructor selection (later: per cohort)
$INSTRUCTOR_NAME = 'Maya';
$INSTRUCTOR_AVATAR = '/assets/avatars/maya.png';

cw_header('Progress Test');
?>
<style>
  body { background:#ffffff; }
  .pt-wrap{ max-width: 1100px; margin: 0 auto; }
  .pt-top{ display:flex; gap:14px; align-items:flex-start; flex-wrap:wrap; }
  .pt-card{ flex: 1 1 560px; }
  .pt-side{ width: 340px; min-width: 300px; }

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
  .talking::after{
    content:"";
    position:absolute;
    inset:-10px;
    border-radius:999px;
    border: 3px solid rgba(46,128,255,0.45);
    animation: pulse 1.1s infinite;
  }
  @keyframes pulse{
    0%{ transform:scale(0.98); opacity:0.2; }
    50%{ transform:scale(1.05); opacity:0.6; }
    100%{ transform:scale(0.98); opacity:0.2; }
  }

  .student-cam{
    width:132px; height:132px;
    border-radius:999px;
    overflow:hidden;
    background:#111;
    border: 4px solid rgba(30,60,114,0.30);
    box-shadow: 0 10px 30px rgba(0,0,0,0.12);
    position:relative;
  }
  .student-cam video{
    width:100%; height:100%;
    object-fit:cover;
  }
  .student-cam .cam-label{
    position:absolute; left:0; right:0; bottom:6px;
    text-align:center;
    font-size:12px;
    color:#fff;
    text-shadow: 0 1px 2px rgba(0,0,0,0.6);
  }
  .student-cam .cam-fallback{
    position:absolute; inset:0;
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-weight:900;
    opacity:.85;
    letter-spacing:1px;
  }

  .pt-actions{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
  .pt-status{ font-size:12px; opacity:.75; }

  /* Push-to-talk big button */
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
  .ptt:disabled{
    opacity:.5;
    cursor:not-allowed;
  }

  /* Timer pill */
  .timer-wrap{
    margin-top:12px;
  }
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
  .timer-fill.danger{
    background:#dc2626;
  }
  .timer-meta{
    display:flex;
    justify-content:space-between;
    font-size:12px;
    opacity:.75;
    margin-top:6px;
  }
</style>

<div class="pt-wrap">

  <div class="card">
    <h1 style="margin:0 0 6px 0;">Progress Test</h1>
    <div class="muted">Audio-only. Tap once to start talking, tap again to stop.</div>
  </div>

  <div class="pt-top">
    <div class="card pt-card">
      <div class="pt-actions">
        <button class="btn" id="btnStart" type="button">Start Progress Test</button>
        <button class="btn btn-sm" id="btnReplay" type="button" style="display:none;">Replay</button>
        <a class="btn btn-sm" href="/student/course.php?cohort_id=<?= (int)$cohortId ?>">Back to Lesson Menu</a>
        <span class="muted" id="jsState" style="margin-left:auto;">JS READY</span>
      </div>

      <div id="quizCard" style="display:none; margin-top:14px;">
        <div class="pt-status" id="status"></div>

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
          <div class="avatar-badge" id="instructorBadge">
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

      <hr style="margin:14px 0;">
      <div class="muted">Next version: snapshots logged per question.</div>
    </div>
  </div>

</div>

<audio id="qAudio" preload="none"></audio>

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

const statusEl = document.getElementById('status');
const instructorBadge = document.getElementById('instructorBadge');
const qAudio = document.getElementById('qAudio');

const btnPTT = document.getElementById('btnPTT');

const timerFill = document.getElementById('timerFill');
const timerText = document.getElementById('timerText');

const camStatus = document.getElementById('camStatus');
const camFallback = document.getElementById('camFallback');
const studentCam = document.getElementById('studentCam');

function setStatus(s){ statusEl.textContent = s || ''; }

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

function setSpeaking(on){
  if (on) instructorBadge.classList.add('talking');
  else instructorBadge.classList.remove('talking');
}

function ttsUrl(testId, itemId, kind){
  return `/student/api/tts_prompt.php?test_id=${encodeURIComponent(testId)}&item_id=${encodeURIComponent(itemId)}&kind=${encodeURIComponent(kind)}`;
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

// ---------- TIMER BAR ----------
let timerMax = 60; // seconds
let timerLeft = 60;
let timerInt = null;

function stopAnswerTimer(){
  if (timerInt) clearInterval(timerInt);
  timerInt = null;
}

function startAnswerTimer(){
  stopAnswerTimer();
  timerMax = 60;
  timerLeft = 60;
  timerFill.style.width = '0%';
  timerFill.classList.remove('danger');
  timerText.textContent = timerLeft + 's';

  timerInt = setInterval(async ()=>{
    timerLeft -= 1;
    if (timerLeft < 0) timerLeft = 0;

    const pct = Math.round(((timerMax - timerLeft) / timerMax) * 100);
    timerFill.style.width = pct + '%';
    timerText.textContent = timerLeft + 's';

    if (timerLeft <= 10) timerFill.classList.add('danger');

    if (timerLeft <= 0) {
      stopAnswerTimer();
      // If student never started recording, mark timeout and continue
      if (!isRecording) {
        setStatus('No answer given. Moving on…');
        await submitAnswer({ timeout: true });
      }
    }
  }, 1000);
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
  if (!canRecord()) { setStatus('Mic not supported on this device.'); return; }

  try {
    setStatus('Recording… tap again to stop.');
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

      setStatus('Transcribing…');
      await transcribeAndSubmit();
    };

    recorder.start();
    isRecording = true;
    btnPTT.classList.add('rec');
    btnPTT.textContent = '⏺ Recording… Tap to Stop';
  } catch (e) {
    setStatus('Mic denied or error.');
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
  // If timer already expired, ignore
  if (timerLeft <= 0) return;

  // user starts answering = stop timeout timer (optional)
  // You can choose to keep running; I recommend stopping to avoid false timeout during recording.
  stopAnswerTimer();

  if (!isRecording) await startRecording();
  else await stopRecording();
});

async function transcribeAndSubmit(){
  if (!lastBlob) { setStatus('No audio captured.'); startAnswerTimer(); return; }

  const fd = new FormData();
  fd.append('lang', 'en'); // oral test in English
  const ext = (lastBlob.type && lastBlob.type.indexOf('mp4') !== -1) ? 'm4a' : 'webm';
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
    setStatus('ASR failed: ' + (j.error||''));
    // allow student to try again within remaining time by restarting timer at 20s
    timerLeft = Math.max(timerLeft, 20);
    startAnswerTimer();
    return;
  }

  const transcript = (j.text || '').trim();
  if (!transcript) {
    setStatus('No speech detected. Try again.');
    timerLeft = Math.max(timerLeft, 20);
    startAnswerTimer();
    return;
  }

  setStatus('Answer received.');
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

  // Start visible timer AFTER audio finishes (we do that in caller)
}

async function startTest(){
  setStatus('Starting…');
  btnStart.disabled = true;
  btnReplay.style.display = 'inline-block';

  await startStudentCam();

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
    setStatus('Start failed: ' + (j.error||''));
    btnStart.disabled = false;
    return;
  }

  TEST_ID = j.test_id;
  quizCard.style.display = 'block';
  resultCard.style.display = 'none';

  // Intro
  setStatus('Intro…');
  await playPromptAudio(TEST_ID, 0, 'intro');

  // First question
  renderItem(j.item);
  setStatus('Question…');
  await playPromptAudio(TEST_ID, j.item.item_id, 'item');

  setStatus('Your turn.');
  startAnswerTimer();
}

async function submitAnswer(answer){
  if (!TEST_ID || !CURRENT_ITEM) return;

  btnPTT.disabled = true;
  setStatus('Saving…');

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
    setStatus('Answer failed: ' + (j.error||''));
    btnPTT.disabled = false;
    // allow more time
    timerLeft = Math.max(timerLeft, 20);
    startAnswerTimer();
    return;
  }

  if (j.done) {
    stopAnswerTimer();
    btnPTT.disabled = true;
    quizCard.style.display = 'none';
    resultCard.style.display = 'block';
    resultBox.innerHTML = `
      <div><strong>Score:</strong> ${j.score_pct}%</div>
      <div style="margin-top:10px;"><strong>AI Summary</strong><br><div style="white-space:pre-wrap;">${escapeHtml(j.ai_summary||'')}</div></div>
      <div style="margin-top:10px;"><strong>Weak Areas</strong><br><div style="white-space:pre-wrap;">${escapeHtml(j.weak_areas||'')}</div></div>
    `;
    // Outro
    await playPromptAudio(TEST_ID, 0, 'outro');
    return;
  }

  // Next item
  renderItem(j.item);
  setStatus('Next question…');
  await playPromptAudio(TEST_ID, j.item.item_id, 'item');
  setStatus('Your turn.');
  startAnswerTimer();
}

btnStart.onclick = startTest;
btnReplay.onclick = async ()=>{
  if (!TEST_ID || !CURRENT_ITEM) return;
  stopAnswerTimer();
  setStatus('Replaying…');
  await playPromptAudio(TEST_ID, CURRENT_ITEM.item_id, 'item');
  setStatus('Your turn.');
  startAnswerTimer();
};

function escapeHtml(s){
  return (s||'').toString()
    .replaceAll('&','&amp;').replaceAll('<','&lt;')
    .replaceAll('>','&gt;').replaceAll('"','&quot;');
}
</script>

<?php cw_footer(); ?>