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

cw_header('Progress Test');
?>
<style>
  body{ background:#fff; }
  .wrap{ max-width:980px; margin:0 auto; }

  .hero{
    display:flex;
    gap:16px;
    align-items:center;
    flex-wrap:wrap;
    margin-top:12px;
  }

  .circle-lock{
    width:120px; height:120px;
    min-width:120px; min-height:120px;
    max-width:120px; max-height:120px;
    border-radius:999px;
    clip-path:circle(50% at 50% 50%);
    overflow:hidden;
    transform:translateZ(0);
    flex:0 0 120px;
    line-height:0;
    position:relative;
  }

  .ring-wrap{
    width:120px; height:120px;
    min-width:120px; min-height:120px;
    flex:0 0 120px;
    position:relative;
    display:flex;
    align-items:center;
    justify-content:center;
    transform:translateZ(0);
  }

  .ring-wrap.talking::after{
    content:"";
    position:absolute; inset:-10px;
    border-radius:999px;
    border:4px solid rgba(22,163,74,0.70);
    box-shadow:0 0 24px rgba(22,163,74,0.45);
    animation:pulseG .95s infinite;
    pointer-events:none;
  }

  .ring-wrap.rec::after{
    content:"";
    position:absolute; inset:-10px;
    border-radius:999px;
    border:4px solid rgba(220,38,38,0.78);
    box-shadow:0 0 24px rgba(220,38,38,0.45);
    animation:pulseR .85s infinite;
    pointer-events:none;
  }

  @keyframes pulseG{
    0%{ transform:scale(.98); opacity:.25; }
    50%{ transform:scale(1.06); opacity:.98; }
    100%{ transform:scale(.98); opacity:.25; }
  }
  @keyframes pulseR{
    0%{ transform:scale(.98); opacity:.25; }
    50%{ transform:scale(1.06); opacity:1; }
    100%{ transform:scale(.98); opacity:.25; }
  }

  .avatar-badge{
    background:linear-gradient(135deg,#1e3c72,#2a5298);
    border:4px solid rgba(255,255,255,0.85);
    box-shadow:0 10px 30px rgba(0,0,0,0.12);
  }
  .avatar-badge img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
  }

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
  }
  .cam .fallback{
    position:absolute; inset:0;
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-weight:900; opacity:.85;
  }
  .cam .label{
    position:absolute; left:0; right:0; bottom:6px;
    text-align:center; font-size:12px; color:#fff;
    padding:0 8px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    text-shadow:0 1px 2px rgba(0,0,0,0.6);
    box-sizing:border-box; pointer-events:none; line-height:1.1;
  }

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
    width:100%;
    margin-top:14px;
    padding:16px 14px;
    border-radius:16px;
    border:2px solid rgba(30,60,114,0.25);
    background:rgba(30,60,114,0.08);
    color:#1e3c72;
    font-weight:900;
    font-size:18px;
    cursor:pointer;
    user-select:none;
  }
  .ptt:hover{ background:rgba(30,60,114,0.12); }
  .ptt:disabled{ opacity:.5; cursor:not-allowed; }
  .ptt.rec{
    background:rgba(220,38,38,0.12);
    border-color:rgba(220,38,38,0.35);
    color:#b91c1c;
  }

  .btn-row{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:14px;
  }
  .btn-half{
    flex:1 1 220px;
    width:auto;
    margin-top:0;
  }

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
    transition:width .25s linear;
  }
  .timer-fill.danger{ background:#dc2626; }
  .timer-meta{
    display:flex;
    justify-content:space-between;
    font-size:12px;
    opacity:.75;
    margin-top:6px;
  }

  .qstrip{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
    margin-top:12px;
  }
  .qdot{
    width:28px;
    height:28px;
    border-radius:999px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:12px;
    font-weight:900;
    background:rgba(30,60,114,0.10);
    border:2px solid rgba(30,60,114,0.35);
    color:#1e3c72;
  }
  .qdot.done{
    background:rgba(22,163,74,0.14);
    border-color:rgba(22,163,74,0.55);
    color:#166534;
  }

  .result-box{
    margin-top:16px;
    padding:14px;
    border:1px solid #eee;
    border-radius:12px;
    background:#fff;
  }
</style>

<div class="wrap">
  <div class="card">
    <div class="hero">
      <div class="ring-wrap" id="instructorRing">
        <div class="avatar-badge circle-lock">
          <img src="<?= h($INSTRUCTOR_AVATAR) ?>" alt="Instructor">
        </div>
      </div>

      <div class="ring-wrap" id="studentRing">
        <div class="cam circle-lock">
          <video id="studentCam" autoplay playsinline muted></video>
          <div class="fallback" id="camFallback">CAM</div>
          <div class="label"><?= h($userName) ?></div>
        </div>
      </div>

      <div class="meta">
        <div class="name"><?= h($INSTRUCTOR_NAME) ?></div>
        <div class="role">AI Instructor</div>
        <div class="muted" id="camStatus" style="margin-top:6px;">Camera permission requested on load.</div>
      </div>
    </div>

    <div class="sysline" id="sysline"><?= h($firstName) ?>, we are loading your progress test...</div>

    <div class="timer-wrap" style="margin-top:12px;">
      <div class="timer-pill"><div class="timer-fill" id="prepFill"></div></div>
      <div class="timer-meta">
        <div id="prepLabel">Preparation progress</div>
        <div id="prepText">0%</div>
      </div>
    </div>

    <div class="qstrip" id="qstrip" style="display:none;"></div>

    <button class="ptt" id="btnStart" type="button" disabled>Start Progress Test</button>

    <div class="btn-row" id="answerBtns" style="display:none;">
      <button class="ptt btn-half" id="btnPTT" type="button" disabled>🎙 Tap to Start Talking</button>
      <button class="ptt btn-half" id="btnReplay" type="button" disabled>↺ Replay Question</button>
    </div>

    <div class="timer-wrap" id="answerWrap" style="display:none;">
      <div class="timer-pill"><div class="timer-fill" id="answerFill"></div></div>
      <div class="timer-meta">
        <div>Time to answer</div>
        <div id="answerText">60s</div>
      </div>
    </div>

    <div class="result-box" id="resultBox" style="display:none;"></div>
  </div>
</div>

<audio id="audioPlayer" preload="auto"></audio>

<script>
const COHORT_ID = <?= (int)$cohortId ?>;
const LESSON_ID = <?= (int)$lessonId ?>;
const FIRST_NAME = <?= json_encode($firstName) ?>;

let TEST_ID = 0;
let TOTAL_QUESTIONS = 0;
let ITEM_IDS = [];
let CUR_POS = 0;
let isRecording = false;
let mediaStream = null;
let recorder = null;
let chunks = [];
let answerLeft = 60;
let answerInt = null;

let INTRO_URL = '';
let QUESTION_URLS = {};
let RESULT_URL = '';

let INTRO_BLOB_URL = '';
let QUESTION_BLOB_URLS = {};
let CURRENT_QUESTION_BLOB_URL = '';
let preloadDone = false;
let preloadStarted = false;

const btnStart = document.getElementById('btnStart');
const btnPTT = document.getElementById('btnPTT');
const btnReplay = document.getElementById('btnReplay');
const answerBtns = document.getElementById('answerBtns');
const sysline = document.getElementById('sysline');
const prepFill = document.getElementById('prepFill');
const prepText = document.getElementById('prepText');
const prepLabel = document.getElementById('prepLabel');
const answerWrap = document.getElementById('answerWrap');
const answerFill = document.getElementById('answerFill');
const answerText = document.getElementById('answerText');
const audioPlayer = document.getElementById('audioPlayer');
const qstrip = document.getElementById('qstrip');
const instructorRing = document.getElementById('instructorRing');
const studentRing = document.getElementById('studentRing');
const studentCam = document.getElementById('studentCam');
const camFallback = document.getElementById('camFallback');
const camStatus = document.getElementById('camStatus');
const resultBox = document.getElementById('resultBox');

function setSys(t){ sysline.textContent = t; }
function setPrep(p){ prepFill.style.width = p + '%'; prepText.textContent = p + '%'; }
function setSpeaking(on){ on ? instructorRing.classList.add('talking') : instructorRing.classList.remove('talking'); }
function setRec(on){ on ? studentRing.classList.add('rec') : studentRing.classList.remove('rec'); }

async function startCam(){
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    camStatus.textContent = 'Camera not supported.';
    return;
  }
  try{
    const stream = await navigator.mediaDevices.getUserMedia({ video:true, audio:false });
    studentCam.srcObject = stream;
    camFallback.style.display = 'none';
    camStatus.textContent = 'Camera active.';
  }catch(e){
    camStatus.textContent = 'Camera denied (ok).';
  }
}

function renderDots(n){
  qstrip.innerHTML = '';
  for(let i=1;i<=n;i++){
    const d = document.createElement('div');
    d.className = 'qdot';
    d.id = 'qd_' + i;
    d.textContent = String(i);
    qstrip.appendChild(d);
  }
  qstrip.style.display = 'flex';
}

function markDone(i){
  const el = document.getElementById('qd_' + i);
  if(el) el.classList.add('done');
}

async function fetchBlobUrl(url){
  if(!url) return '';
  const res = await fetch(url, {
    method:'GET',
    credentials:'omit',
    cache:'force-cache'
  });
  if(!res.ok) throw new Error('Audio fetch failed: HTTP ' + res.status);
  const blob = await res.blob();
  if(!blob || blob.size <= 0) throw new Error('Audio blob empty');
  return URL.createObjectURL(blob);
}

async function preloadAllAudio(){
  preloadStarted = true;
  prepLabel.textContent = 'Audio buffering';
  setSys('Loading audio...');

  const total = 1 + ITEM_IDS.length;
  let done = 0;

  try{
    INTRO_BLOB_URL = await fetchBlobUrl(INTRO_URL);
  }catch(e){
    console.warn('Intro preload failed:', e);
    INTRO_BLOB_URL = '';
  }
  done++;
  setPrep(Math.round((done / total) * 100));

  for(let i=0;i<ITEM_IDS.length;i++){
    const itemId = ITEM_IDS[i];
    const src = QUESTION_URLS[String(itemId)] || QUESTION_URLS[itemId] || '';

    try{
      QUESTION_BLOB_URLS[itemId] = await fetchBlobUrl(src);
    }catch(e){
      console.warn('Question preload failed for item', itemId, e);
      QUESTION_BLOB_URLS[itemId] = '';
    }

    done++;
    setPrep(Math.round((done / total) * 100));
  }

  preloadDone = true;
  prepLabel.textContent = 'Preparation progress';

  if (INTRO_BLOB_URL) {
    setSys(FIRST_NAME + ', your progress test is ready.');
    btnStart.disabled = false;
  } else {
    setSys('Audio loading failed for the intro. Please refresh and try again.');
  }
}

async function prepareTest(){
  await startCam();

  let p = 0;
  const tick = setInterval(()=>{
    p = Math.min(70, p + 4);
    setPrep(p);
  }, 250);

  const res = await fetch('/student/api/test_prepare_v2.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    credentials:'same-origin',
    body: JSON.stringify({ cohort_id: COHORT_ID, lesson_id: LESSON_ID })
  });

  clearInterval(tick);

  const txt = await res.text();
  let j = null;
  try { j = JSON.parse(txt); } catch(e) { j = {ok:false,error:'Non-JSON: ' + txt.slice(0,200)}; }

  if (!j.ok) {
    setPrep(100);
    setSys('Preparation failed: ' + (j.error || 'Unknown error'));
    return;
  }

  TEST_ID = parseInt(j.test_id || 0, 10);
  TOTAL_QUESTIONS = parseInt(j.total_questions || 10, 10);

  if (!TEST_ID || !TOTAL_QUESTIONS) {
    setPrep(100);
    setSys('Preparation failed: invalid test data.');
    return;
  }

  if (Array.isArray(j.item_ids) && j.item_ids.length) {
    ITEM_IDS = j.item_ids.map(x => parseInt(x,10)).filter(Boolean);
  } else {
    ITEM_IDS = [];
    for(let i=1;i<=TOTAL_QUESTIONS;i++) ITEM_IDS.push(i);
  }

  INTRO_URL = String(j.intro_url || '');
  QUESTION_URLS = (j.question_urls && typeof j.question_urls === 'object') ? j.question_urls : {};

  renderDots(TOTAL_QUESTIONS);

  setPrep(75);
  preloadAllAudio();
}

async function playAudio(url){
  if (!url) return false;

  return new Promise((resolve)=>{
    setSpeaking(true);
    audioPlayer.pause();
    audioPlayer.currentTime = 0;
    audioPlayer.src = url;
    audioPlayer.onended = ()=>{ setSpeaking(false); resolve(true); };
    audioPlayer.onerror = ()=>{ setSpeaking(false); resolve(false); };
    const p = audioPlayer.play();
    if (p && p.catch) p.catch(()=>{ setSpeaking(false); resolve(false); });
  });
}

async function ensureQuestionBlob(itemId){
  const existing = QUESTION_BLOB_URLS[itemId] || QUESTION_BLOB_URLS[String(itemId)] || '';
  if (existing) return existing;

  const src = QUESTION_URLS[String(itemId)] || QUESTION_URLS[itemId] || '';
  if (!src) return '';

  setSys('Loading audio...');
  try{
    const blobUrl = await fetchBlobUrl(src);
    QUESTION_BLOB_URLS[itemId] = blobUrl;
    return blobUrl;
  }catch(e){
    console.warn('ensureQuestionBlob failed', itemId, e);
    return '';
  }
}

function resultUrl(){
  return '/student/api/test_audio_v2.php?test_id=' + encodeURIComponent(TEST_ID) + '&kind=result';
}

async function startFlow(){
  btnStart.disabled = true;

  if(!INTRO_BLOB_URL){
    setSys('Loading audio...');
    try{
      INTRO_BLOB_URL = await fetchBlobUrl(INTRO_URL);
    }catch(e){
      setSys('Intro audio failed to load.');
      btnStart.disabled = false;
      return;
    }
  }

  setSys('Maya is speaking...');
  const ok = await playAudio(INTRO_BLOB_URL);
  if (!ok) {
    setSys('Intro audio failed.');
    btnStart.disabled = false;
    return;
  }

  CUR_POS = 1;
  await askCurrent();
}

async function askCurrent(){
  const itemId = ITEM_IDS[CUR_POS - 1];
  if (!itemId) {
    await finalizeTest();
    return;
  }

  const qUrl = await ensureQuestionBlob(itemId);
  CURRENT_QUESTION_BLOB_URL = qUrl;

  if(!qUrl){
    setSys('Question audio failed to load.');
    return;
  }

  setSys('Maya is speaking...');
  const ok = await playAudio(qUrl);
  if (!ok) {
    setSys('Question audio failed.');
    return;
  }

  answerBtns.style.display = 'flex';
  btnPTT.style.display = 'block';
  btnPTT.disabled = false;
  btnReplay.disabled = false;
  answerWrap.style.display = 'block';
  setSys('Your turn.');
  startAnswerTimer();
}

async function replayCurrentQuestion(){
  if(isRecording) return;
  if(!CURRENT_QUESTION_BLOB_URL){
    setSys('Loading audio...');
    return;
  }

  btnPTT.disabled = true;
  btnReplay.disabled = true;
  stopAnswerTimer();

  setSys('Maya is speaking...');
  const ok = await playAudio(CURRENT_QUESTION_BLOB_URL);

  if(!ok){
    setSys('Replay failed.');
  } else {
    setSys('Your turn.');
  }

  btnPTT.disabled = false;
  btnReplay.disabled = false;
  startAnswerTimer();
}

function startAnswerTimer(){
  stopAnswerTimer();
  answerLeft = 60;
  answerFill.style.width = '0%';
  answerFill.classList.remove('danger');
  answerText.textContent = '60s';

  answerInt = setInterval(()=>{
    answerLeft--;
    if(answerLeft < 0) answerLeft = 0;
    const pct = Math.round(((60 - answerLeft) / 60) * 100);
    answerFill.style.width = pct + '%';
    answerText.textContent = answerLeft + 's';
    if(answerLeft <= 10) answerFill.classList.add('danger');

    if(answerLeft <= 0){
      stopAnswerTimer();
      if(!isRecording){
        uploadAnswerBlob(null, true);
      }
    }
  }, 1000);
}

function stopAnswerTimer(){
  if(answerInt) clearInterval(answerInt);
  answerInt = null;
}

async function startRecording(){
  try{
    chunks = [];
    mediaStream = await navigator.mediaDevices.getUserMedia({ audio:true });
    const mime = MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : '';
    recorder = new MediaRecorder(mediaStream, mime ? { mimeType:mime } : undefined);

    recorder.ondataavailable = (e)=>{ if(e.data && e.data.size > 0) chunks.push(e.data); };
    recorder.onstop = async ()=>{
      const blob = chunks.length ? new Blob(chunks, { type: recorder.mimeType || 'audio/webm' }) : null;
      if(mediaStream) mediaStream.getTracks().forEach(t=>t.stop());
      mediaStream = null;
      setRec(false);
      btnPTT.classList.remove('rec');
      btnPTT.textContent = '🎙 Tap to Start Talking';
      await uploadAnswerBlob(blob, false);
    };

    recorder.start();
    isRecording = true;
    setRec(true);
    btnPTT.classList.add('rec');
    btnPTT.textContent = '⏹ Recording... Tap to Stop';
  }catch(e){
    setSys('Microphone access failed.');
  }
}

async function stopRecording(){
  if(recorder && recorder.state !== 'inactive') recorder.stop();
  isRecording = false;
}

btnPTT.addEventListener('click', async ()=>{
  if (!TEST_ID) return;
  if (!isRecording) {
    stopAnswerTimer();
    btnReplay.disabled = true;
    await startRecording();
  } else {
    await stopRecording();
  }
});

btnReplay.addEventListener('click', replayCurrentQuestion);

async function uploadAnswerBlob(blob, timeoutOnly){
  btnPTT.disabled = true;
  btnReplay.disabled = true;
  setSys('Saving your answer...');

  const fd = new FormData();
  fd.append('test_id', String(TEST_ID));
  fd.append('idx', String(CUR_POS));

  if (timeoutOnly) {
    fd.append('timeout', '1');
  } else if (blob) {
    fd.append('audio', blob, 'q' + String(CUR_POS).padStart(2,'0') + '.webm');
  }

  const res = await fetch('/student/api/test_upload_answer_v2.php', {
    method:'POST',
    credentials:'same-origin',
    body: fd
  });

  const txt = await res.text();
  let j = null;
  try { j = JSON.parse(txt); } catch(e) { j = {ok:false,error:'Non-JSON: ' + txt.slice(0,200)}; }

  if (!j.ok) {
    setSys('Upload failed: ' + (j.error || 'Unknown error'));
    btnPTT.disabled = false;
    btnReplay.disabled = false;
    startAnswerTimer();
    return;
  }

  markDone(CUR_POS);
  CUR_POS++;

  if (CUR_POS > TOTAL_QUESTIONS) {
    await finalizeTest();
    return;
  }

  btnPTT.disabled = true;
  btnReplay.disabled = true;
  await askCurrent();
}

async function finalizeTest(){
  btnPTT.disabled = true;
  btnReplay.disabled = true;
  prepLabel.textContent = 'Evaluation progress';
  setPrep(10);
  setSys('I am evaluating your answers... please standby.');

  let p = 10;
  const tick = setInterval(()=>{
    p = Math.min(92, p + 4);
    setPrep(p);
  }, 300);

  const res = await fetch('/student/api/test_finalize_v2.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    credentials:'same-origin',
    body: JSON.stringify({ test_id: TEST_ID })
  });

  clearInterval(tick);

  const txt = await res.text();
  let j = null;
  try { j = JSON.parse(txt); } catch(e) { j = {ok:false,error:'Non-JSON: ' + txt.slice(0,200)}; }

  if (!j.ok) {
    setPrep(100);
    setSys('Evaluation failed: ' + (j.error || 'Unknown error'));
    return;
  }

  setPrep(100);
  setSys('Maya is speaking...');

  RESULT_URL = String(j.result_audio || '');
  const ok = await playAudio(RESULT_URL || resultUrl());
  if (!ok) {
    setSys('Result audio failed, but written results are available.');
  }

  resultBox.style.display = 'block';
  resultBox.innerHTML = `
    <div><strong>Score:</strong> ${escapeHtml(String(j.score_pct || 0))}%</div>
    <div style="margin-top:10px;"><strong>Debrief</strong><br><div style="white-space:pre-wrap;">${escapeHtml(j.ai_summary || '')}</div></div>
    <div style="margin-top:10px;"><strong>Weak Areas</strong><br><div style="white-space:pre-wrap;">${escapeHtml(j.weak_areas || '')}</div></div>
  `;
  setSys('Completed.');
}

function escapeHtml(s){
  return (s || '').toString()
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;');
}

prepareTest();
btnStart.addEventListener('click', startFlow);
</script>

<?php cw_footer(); ?>