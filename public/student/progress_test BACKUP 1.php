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

cw_header('Progress Test');
?>
<div class="card">
  <div class="muted">
    This is a timed Progress Test (target ≤ 10 minutes). Answer carefully — minimal hints.
  </div>

  <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
    <button class="btn" id="btnStart" type="button">Start Progress Test</button>
    <a class="btn btn-sm" href="/student/course.php?cohort_id=<?= (int)$cohortId ?>">Back to Lesson Menu</a>

    <span style="margin-left:auto; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
      <label class="muted" style="display:flex; gap:6px; align-items:center;">
        <span style="font-weight:700;">Voice</span>
        <select id="voiceLang" class="input" style="height:34px;">
          <option value="en" selected>English</option>
          <option value="es">Español</option>
        </select>
      </label>
      <button class="btn btn-sm" id="btnSpeakToggle" type="button">🔊 Speak: ON</button>
      <button class="btn btn-sm" id="btnMute" type="button">Mute</button>
    </span>
  </div>

  <div class="muted" id="topStatus" style="margin-top:10px;"></div>
</div>

<div class="card" id="quizCard" style="display:none;">
  <h2 style="margin-top:0;">AI Instructor</h2>

  <div id="promptBox" style="white-space:pre-wrap; font-size:16px; line-height:1.35;"></div>

  <div id="answerArea" style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;"></div>

  <div class="muted" id="status" style="margin-top:10px;"></div>
</div>

<div class="card" id="resultCard" style="display:none;">
  <h2 style="margin-top:0;">Result</h2>
  <div id="resultBox"></div>
  <div style="margin-top:12px;">
    <a class="btn" href="/student/course.php?cohort_id=<?= (int)$cohortId ?>">Back to Lesson Menu</a>
  </div>
</div>

<!-- TTS Audio element -->
<audio id="ttsAudio" preload="none"></audio>

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
const topStatusEl = document.getElementById('topStatus');
const resultBox = document.getElementById('resultBox');

const ttsAudio = document.getElementById('ttsAudio');
const voiceLangSel = document.getElementById('voiceLang');
const btnSpeakToggle = document.getElementById('btnSpeakToggle');
const btnMute = document.getElementById('btnMute');

let speakEnabled = true;
let muted = false;

function setStatus(s){ statusEl.textContent = s || ''; }
function setTopStatus(s){ topStatusEl.textContent = s || ''; }

function escapeHtml(s){
  return (s||'').toString()
    .replaceAll('&','&amp;').replaceAll('<','&lt;')
    .replaceAll('>','&gt;').replaceAll('"','&quot;');
}

// ---- TTS helpers ----
// We reuse your existing slide-player TTS endpoint pattern.
// You can implement a dedicated endpoint later, but for MVP we speak the prompt text via querystring.
function ttsUrlFromText(text){
  // If you already have /player/api/tts.php that accepts `text=...`, use that.
  // If it only accepts slide_id, you can implement /student/api/tts_prompt.php later.
  // For now we call a lightweight URL that your backend should support.
  // Fallback: if it doesn't exist, we just don't play audio.
  const lang = encodeURIComponent(voiceLangSel.value || 'en');
  return `/student/api/tts_prompt.php?lang=${lang}&text=${encodeURIComponent(text || '')}`;
}

async function speak(text){
  if (!speakEnabled || muted) return;
  const t = (text || '').trim();
  if (!t) return;

  try {
    const url = ttsUrlFromText(t);
    ttsAudio.pause();
    ttsAudio.currentTime = 0;
    ttsAudio.src = url;

    await ttsAudio.play();
  } catch (e) {
    // no autoplay / endpoint missing / etc.
    // show minimal hint in UI
    setTopStatus('Audio not available yet (or blocked by browser).');
  }
}

btnSpeakToggle.addEventListener('click', ()=>{
  speakEnabled = !speakEnabled;
  btnSpeakToggle.textContent = speakEnabled ? '🔊 Speak: ON' : '🔇 Speak: OFF';
  if (!speakEnabled) {
    ttsAudio.pause();
    ttsAudio.currentTime = 0;
    ttsAudio.removeAttribute('src');
  }
});
btnMute.addEventListener('click', ()=>{
  muted = !muted;
  btnMute.textContent = muted ? 'Unmute' : 'Mute';
  if (muted) {
    ttsAudio.pause();
  }
});

// If language changes, stop current audio
voiceLangSel.addEventListener('change', ()=>{
  ttsAudio.pause();
  ttsAudio.currentTime = 0;
  ttsAudio.removeAttribute('src');
});

// ---- Render items ----
function renderItem(item){
  CURRENT_ITEM = item;
  promptBox.textContent = item.prompt || '';
  answerArea.innerHTML = '';

  // Speak prompt (triggered after a user gesture: Start click / answer click)
  speak(item.prompt || '');

  if (item.kind === 'info') {
    const b = document.createElement('button');
    b.className = 'btn';
    b.textContent = 'Continue';
    b.type = 'button';
    b.onclick = ()=> submitAnswer({action:'continue'});
    answerArea.appendChild(b);
    return;
  }

  if (item.kind === 'yesno') {
    ['Yes','No'].forEach(v=>{
      const b = document.createElement('button');
      b.className = 'btn';
      b.textContent = v;
      b.type = 'button';
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
      b.type = 'button';
      b.onclick = ()=> submitAnswer({index: idx});
      answerArea.appendChild(b);
    });
    return;
  }
}

// ---- Start / Answer flow ----
async function startTest(){
  setTopStatus('');
  setStatus('Starting…');

  const res = await fetch('/student/api/test_start.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    credentials:'same-origin',
    body: JSON.stringify({ cohort_id: COHORT_ID, lesson_id: LESSON_ID })
  });

  const txt = await res.text();
  let j = null;
  try { j = JSON.parse(txt); } catch(e){ j = { ok:false, error:'Non-JSON response: ' + txt.slice(0,200) }; }

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

  const txt = await res.text();
  let j = null;
  try { j = JSON.parse(txt); } catch(e){ j = { ok:false, error:'Non-JSON response: ' + txt.slice(0,200) }; }

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
    // speak a short closing line
    speak(`Test complete. Your score is ${j.score_pct} percent.`);
    return;
  }

  renderItem(j.item);
  setStatus('');
}

document.getElementById('btnStart').onclick = startTest;
setTopStatus('JS READY');
</script>
<?php cw_footer(); ?>