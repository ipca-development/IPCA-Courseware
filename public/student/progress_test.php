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

$userName = (string)($u['name'] ?? 'Student');

// For now: choose instructor avatar (later you can store per cohort/course)
$INSTRUCTOR_NAME = 'Maya';
$INSTRUCTOR_AVATAR = '/assets/avatars/maya.png'; // or '/assets/avatars/kevin.png'

cw_header('Progress Test');
?>
<style>
  /* Page layout */
  .pt-wrap{ max-width: 1100px; margin: 0 auto; }
  .pt-top{
    display:flex;
    gap:14px;
    align-items:flex-start;
    flex-wrap:wrap;
  }
  .pt-card{ flex: 1 1 520px; }
  .pt-side{ width: 320px; min-width: 280px; }

  /* Avatars */
  .avatar-stack{ display:flex; flex-direction:column; gap:12px; }
  .avatar-row{ display:flex; align-items:center; gap:10px; }
  .avatar-meta{ line-height:1.1; }
  .avatar-meta .name{ font-weight:800; }
  .avatar-meta .role{ font-size:12px; opacity:.7; }

  .avatar-badge{
    width:120px; height:120px;
    border-radius:999px;
    background: linear-gradient(135deg, #1e3c72, #2a5298);
    display:flex; align-items:center; justify-content:center;
    overflow:hidden;
    border: 4px solid rgba(255,255,255,0.75);
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

  /* "Speaking" animation (simple pulse ring) */
  .talking::after{
    content:"";
    position:absolute;
    inset:-8px;
    border-radius:999px;
    border: 3px solid rgba(46,128,255,0.45);
    animation: pulse 1.2s infinite;
  }
  @keyframes pulse{
    0%{ transform:scale(0.98); opacity:0.2; }
    50%{ transform:scale(1.04); opacity:0.55; }
    100%{ transform:scale(0.98); opacity:0.2; }
  }

  /* Student camera bubble */
  .student-cam{
    width:120px; height:120px;
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
    color:#fff; font-weight:800;
    opacity:.85;
  }

  /* Quiz UI */
  .pt-prompt{
    white-space:pre-wrap;
    font-size:16px;
    line-height:1.35;
    min-height: 120px;
  }
  .pt-actions{ display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; }
  .pt-answer{ display:flex; gap:10px; flex-wrap:wrap; margin-top:14px; }
</style>

<div class="pt-wrap">

  <div class="card">
    <h1 style="margin:0 0 6px 0;">Progress Test</h1>
    <div class="muted">
      Timed Progress Test (target ≤ 10 minutes). Minimal hints. Answer carefully.
    </div>
  </div>

  <div class="pt-top">
    <div class="card pt-card">
      <div class="pt-actions">
        <button class="btn" id="btnStart" type="button">Start Progress Test</button>
        <a class="btn btn-sm" href="/student/course.php?cohort_id=<?= (int)$cohortId ?>">Back to Lesson Menu</a>
        <span class="muted" id="jsReady">JS READY</span>
      </div>

      <div id="quizCard" style="display:none; margin-top:14px;">
        <h2 style="margin:0 0 8px 0;">AI Instructor</h2>
        <div class="pt-prompt" id="promptBox"></div>
        <div class="pt-answer" id="answerArea"></div>
        <div class="muted" id="status" style="margin-top:10px;"></div>
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
          <div class="student-cam" id="studentCamWrap">
            <video id="studentCam" autoplay playsinline muted></video>
            <div class="cam-fallback" id="camFallback">CAM</div>
            <div class="cam-label"><?= h($userName) ?></div>
          </div>
          <div class="avatar-meta">
            <div class="name"><?= h($userName) ?></div>
            <div class="role">Student camera</div>
            <div class="muted" style="margin-top:6px;" id="camStatus">Camera will ask permission when you start.</div>
          </div>
        </div>

      </div>

      <hr style="margin:14px 0;">

      <div class="muted">
        Note: camera is used to support supervised study. (No face matching yet.)
      </div>
    </div>
  </div>
</div>

<script>
const COHORT_ID = <?= (int)$cohortId ?>;
const LESSON_ID = <?= (int)$lessonId ?>;

let TEST_ID = 0;
let CURRENT_ITEM = null;

const quizCard = document.getElementById('quizCard');
const resultCard = document.getElementById('resultCard');
const promptBox = document.getElementById('promptBox');
const answerArea = document.getElementById('answerArea');
const statusEl = document.getElementById('status');
const resultBox = document.getElementById('resultBox');
const instructorBadge = document.getElementById('instructorBadge');

const camStatus = document.getElementById('camStatus');
const camFallback = document.getElementById('camFallback');
const studentCam = document.getElementById('studentCam');

function setStatus(s){ statusEl.textContent = s || ''; }
function escapeHtml(s){
  return (s||'').toString()
    .replaceAll('&','&amp;').replaceAll('<','&lt;')
    .replaceAll('>','&gt;').replaceAll('"','&quot;');
}

/* Camera: only ask on user gesture */
async function startStudentCam(){
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){
    camStatus.textContent = 'Camera not supported on this browser.';
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

function renderItem(item){
  CURRENT_ITEM = item;
  promptBox.textContent = item.prompt || '';
  answerArea.innerHTML = '';

  // simple "speaking" pulse while prompt is on screen (visual only)
  instructorBadge.classList.add('talking');
  setTimeout(()=>instructorBadge.classList.remove('talking'), 900);

  if (item.kind === 'info') {
    const b = document.createElement('button');
    b.className = 'btn';
    b.textContent = 'Continue';
    b.onclick = ()=> submitAnswer({action:'continue'});
    answerArea.appendChild(b);
    return;
  }

  if (item.kind === 'yesno') {
    ['Yes','No'].forEach(v=>{
      const b = document.createElement('button');
      b.className = 'btn';
      b.textContent = v;
      b.onclick = ()=> submitAnswer({value: v.toLowerCase() === 'yes'});
      answerArea.appendChild(b);
    });
    return;
  }

  if (item.kind === 'mcq') {
    (item.options || []).forEach((opt, idx)=>{
      const b = document.createElement('button');
      b.className = 'btn';
      b.textContent = opt;
      b.onclick = ()=> submitAnswer({index: idx});
      answerArea.appendChild(b);
    });
    return;
  }
}

async function startTest(){
  setStatus('Starting…');

  // request camera now (user gesture = button click)
  await startStudentCam();

  const res = await fetch('/student/api/test_start.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    credentials:'same-origin',
    body: JSON.stringify({ cohort_id: COHORT_ID, lesson_id: LESSON_ID })
  });

  const j = await res.json();
  if (!j.ok) { setStatus('Start failed: ' + (j.error||'')); return; }

  TEST_ID = j.test_id;
  quizCard.style.display = 'block';
  renderItem(j.item);
  setStatus('');
}

async function submitAnswer(answer){
  if (!TEST_ID || !CURRENT_ITEM) return;
  setStatus('Saving answer…');

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

  const j = await res.json();
  if (!j.ok) { setStatus('Answer failed: ' + (j.error||'')); return; }

  if (j.done) {
    quizCard.style.display = 'none';
    resultCard.style.display = 'block';
    resultBox.innerHTML = `
      <div><strong>Score:</strong> ${j.score_pct}%</div>
      <div style="margin-top:10px;"><strong>AI Summary</strong><br><div style="white-space:pre-wrap;">${escapeHtml(j.ai_summary||'')}</div></div>
      <div style="margin-top:10px;"><strong>Weak Areas</strong><br><div style="white-space:pre-wrap;">${escapeHtml(j.weak_areas||'')}</div></div>
    `;
    setStatus('');
    return;
  }

  renderItem(j.item);
  setStatus('');
}

document.getElementById('btnStart').onclick = startTest;
</script>

<?php cw_footer(); ?>