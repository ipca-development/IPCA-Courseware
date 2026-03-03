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

  <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
    <!-- Inline onclick fallback ensures it works even if JS binding breaks -->
    <button class="btn" id="btnStart" type="button" onclick="window.__ptStart && window.__ptStart();">
      Start Progress Test
    </button>

    <a class="btn btn-sm" href="/student/course.php?cohort_id=<?= (int)$cohortId ?>">Back to Lesson Menu</a>
  </div>

  <div class="muted" id="jsState" style="margin-top:10px;">
    JS status: <strong>loading…</strong>
  </div>
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

<script>
(function(){
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
  const jsState = document.getElementById('jsState');
  const btnStart = document.getElementById('btnStart');

  function setStatus(s){ statusEl.textContent = s || ''; }

  function escapeHtml(s){
    return (s||'').toString()
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;');
  }

  function renderItem(item){
    CURRENT_ITEM = item;
    promptBox.textContent = item.prompt || '';
    answerArea.innerHTML = '';

    if (item.kind === 'info') {
      const b = document.createElement('button');
      b.className = 'btn';
      b.type = 'button';
      b.textContent = 'Continue';
      b.onclick = ()=> submitAnswer({action:'continue'});
      answerArea.appendChild(b);
      return;
    }

    if (item.kind === 'yesno') {
      ['Yes','No'].forEach(v=>{
        const b = document.createElement('button');
        b.className = 'btn';
        b.type = 'button';
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
        b.type = 'button';
        b.textContent = opt;
        b.onclick = ()=> submitAnswer({index: idx});
        answerArea.appendChild(b);
      });
      return;
    }

    // fallback
    const b = document.createElement('button');
    b.className = 'btn';
    b.type = 'button';
    b.textContent = 'Continue';
    b.onclick = ()=> submitAnswer({action:'continue'});
    answerArea.appendChild(b);
  }

  async function safeJson(res){
    const txt = await res.text();
    try { return { ok:true, json: JSON.parse(txt) }; }
    catch(e){ return { ok:false, error: 'Non-JSON response', txt: txt.slice(0, 600) }; }
  }

  async function startTest(){
    setStatus('Starting…');

    let res;
    try {
      res = await fetch('/student/api/test_start.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        credentials:'same-origin',
        cache:'no-store',
        body: JSON.stringify({ cohort_id: COHORT_ID, lesson_id: LESSON_ID })
      });
    } catch (e) {
      setStatus('Start failed (network): ' + e);
      return;
    }

    const parsed = await safeJson(res);
    if (!parsed.ok) {
      setStatus('Start failed: ' + parsed.error + ' — ' + parsed.txt);
      return;
    }

    const j = parsed.json;
    if (!res.ok || !j.ok) {
      setStatus('Start failed: HTTP ' + res.status + ' — ' + (j.error||'unknown'));
      return;
    }

    TEST_ID = j.test_id;
    quizCard.style.display = 'block';
    resultCard.style.display = 'none';
    renderItem(j.item);
    setStatus('');
  }

  async function submitAnswer(answer){
    if (!TEST_ID || !CURRENT_ITEM) return;
    setStatus('Saving answer…');

    let res;
    try {
      res = await fetch('/student/api/test_answer.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        credentials:'same-origin',
        cache:'no-store',
        body: JSON.stringify({
          test_id: TEST_ID,
          item_id: CURRENT_ITEM.item_id,
          answer: answer
        })
      });
    } catch (e) {
      setStatus('Answer failed (network): ' + e);
      return;
    }

    const parsed = await safeJson(res);
    if (!parsed.ok) {
      setStatus('Answer failed: ' + parsed.error + ' — ' + parsed.txt);
      return;
    }

    const j = parsed.json;
    if (!res.ok || !j.ok) {
      setStatus('Answer failed: HTTP ' + res.status + ' — ' + (j.error||'unknown'));
      return;
    }

    if (j.done) {
      quizCard.style.display = 'none';
      resultCard.style.display = 'block';
      resultBox.innerHTML = `
        <div><strong>Score:</strong> ${j.score_pct}%</div>
        <div style="margin-top:10px;"><strong>AI Summary</strong><br>
          <div style="white-space:pre-wrap;">${escapeHtml(j.ai_summary||'')}</div>
        </div>
        <div style="margin-top:10px;"><strong>Weak Areas</strong><br>
          <div style="white-space:pre-wrap;">${escapeHtml(j.weak_areas||'')}</div>
        </div>
      `;
      setStatus('');
      return;
    }

    renderItem(j.item);
    setStatus('');
  }

  // Expose a global start function for the inline onclick fallback
  window.__ptStart = startTest;

  document.addEventListener('DOMContentLoaded', function(){
    jsState.innerHTML = 'JS status: <strong style="color:#1e3c72;">ready</strong>';
    if (btnStart) {
      btnStart.addEventListener('click', startTest);
    }
  });
})();
</script>

<?php cw_footer(); ?>