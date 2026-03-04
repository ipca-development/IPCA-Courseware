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

// If student, must be enrolled
if ($role === 'student') {
    $check = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id=? AND user_id=? LIMIT 1");
    $check->execute([$cohortId, (int)$u['id']]);
    if (!$check->fetchColumn()) {
        http_response_code(403);
        exit('Not enrolled in this cohort');
    }
}

cw_header('Progress Test');
?>
<div class="card">
  <div class="muted">
    Progress Test (target ≤ 10 minutes). Audio-first. Click <strong>Start</strong> to begin.
  </div>

  <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
    <button class="btn" id="btnStart" type="button">Start Progress Test</button>
    <button class="btn btn-sm" id="btnReplay" type="button" style="display:none;">Replay</button>
    <a class="btn btn-sm" href="/student/course.php?cohort_id=<?= (int)$cohortId ?>">Back to Lesson Menu</a>
  </div>

  <div class="muted" id="status" style="margin-top:10px;"></div>
</div>

<div class="card" id="quizCard" style="display:none;">
  <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
    <div style="display:flex; align-items:center; gap:10px;">
      <div style="width:54px; height:54px; border-radius:999px; overflow:hidden; background:#1e3c72; border:1px solid #e6e6e6;">
        <img src="/assets/avatars/Maya.png" alt="Instructor" style="width:100%; height:100%; object-fit:cover;">
      </div>
      <div>
        <div style="font-weight:800;">Maya</div>
        <div class="muted" style="font-size:12px;">AI Instructor</div>
      </div>
    </div>

    <div style="margin-left:auto; display:flex; gap:10px; align-items:center;">
      <div class="muted" style="font-size:12px;">Time</div>
      <div id="timer" style="font-weight:800;">00:00</div>
    </div>
  </div>

  <div style="margin-top:12px;">
    <!-- Audio-only experience: keep text minimal -->
    <div class="muted" id="promptText" style="display:none; white-space:pre-wrap;"></div>

    <div id="answerArea" style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;"></div>

    <div class="muted" id="status2" style="margin-top:10px;"></div>
  </div>
</div>

<div class="card" id="resultCard" style="display:none;">
  <h2 style="margin-top:0;">Result</h2>
  <div id="resultBox"></div>
  <div style="margin-top:12px;">
    <a class="btn" href="/student/course.php?cohort_id=<?= (int)$cohortId ?>">Back to Lesson Menu</a>
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
const answerArea = document.getElementById('answerArea');
const statusEl = document.getElementById('status');
const status2 = document.getElementById('status2');
const resultBox = document.getElementById('resultBox');
const timerEl = document.getElementById('timer');
const qAudio = document.getElementById('qAudio');

let t0 = 0;
let timerInt = null;

function setStatus(s){ statusEl.textContent = s || ''; }
function setStatus2(s){ status2.textContent = s || ''; }

function startTimer(){
  t0 = Date.now();
  if (timerInt) clearInterval(timerInt);
  timerInt = setInterval(()=>{
    const sec = Math.floor((Date.now()-t0)/1000);
    const mm = String(Math.floor(sec/60)).padStart(2,'0');
    const ss = String(sec%60).padStart(2,'0');
    timerEl.textContent = mm+':'+ss;
  }, 250);
}

function stopTimer(){
  if (timerInt) clearInterval(timerInt);
  timerInt = null;
}

function ttsUrl(testId, itemId, kind){
  // kind can be "intro" or "item"
  return `/student/api/tts_prompt.php?test_id=${encodeURIComponent(testId)}&item_id=${encodeURIComponent(itemId)}&kind=${encodeURIComponent(kind)}`;
}

async function playPromptAudio(testId, itemId, kind){
  return new Promise((resolve) => {
    qAudio.pause();
    qAudio.currentTime = 0;
    qAudio.src = ttsUrl(testId, itemId, kind);
    qAudio.onended = ()=> resolve(true);
    qAudio.onerror = ()=> resolve(false);
    qAudio.play().then(()=>{}).catch(()=> resolve(false));
  });
}

function renderItem(item){
  CURRENT_ITEM = item;
  answerArea.innerHTML = '';

  // Audio-only: keep prompt hidden, but still available for debugging if needed
  // document.getElementById('promptText').textContent = item.prompt || '';

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
  btnStart.disabled = true;

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
  btnReplay.style.display = 'inline-block';

  startTimer();
  setStatus('');
  setStatus2('Playing intro…');

  // 1) Intro
  await playPromptAudio(TEST_ID, 0, 'intro');

  // 2) First question prompt
  renderItem(j.item);
  setStatus2('Playing question…');
  await playPromptAudio(TEST_ID, j.item.item_id, 'item');
  setStatus2('');
}

async function submitAnswer(answer){
  if (!TEST_ID || !CURRENT_ITEM) return;
  setStatus2('Saving answer…');

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

  if (!j.ok) { setStatus2('Answer failed: ' + (j.error||'')); return; }

  if (j.done) {
    stopTimer();
    quizCard.style.display = 'none';
    resultCard.style.display = 'block';
    resultBox.innerHTML = `
      <div><strong>Score:</strong> ${j.score_pct}%</div>
      <div style="margin-top:10px;"><strong>AI Summary</strong><br><div style="white-space:pre-wrap;">${escapeHtml(j.ai_summary||'')}</div></div>
      <div style="margin-top:10px;"><strong>Weak Areas</strong><br><div style="white-space:pre-wrap;">${escapeHtml(j.weak_areas||'')}</div></div>
    `;
    return;
  }

  renderItem(j.item);
  setStatus2('Playing next question…');
  await playPromptAudio(TEST_ID, j.item.item_id, 'item');
  setStatus2('');
}

function escapeHtml(s){
  return (s||'').toString()
    .replaceAll('&','&amp;').replaceAll('<','&lt;')
    .replaceAll('>','&gt;').replaceAll('"','&quot;');
}

btnStart.onclick = startTest;
btnReplay.onclick = async ()=>{
  if (!TEST_ID) return;
  if (!CURRENT_ITEM) return;
  await playPromptAudio(TEST_ID, CURRENT_ITEM.item_id, 'item');
};
</script>
<?php cw_footer(); ?>