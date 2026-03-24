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

$slideId = (int)($_GET['slide_id'] ?? 0);
if ($slideId <= 0) exit('Missing slide_id');

$stmt = $pdo->prepare("
  SELECT s.*, l.id AS lesson_id, l.course_id, l.external_lesson_id, c.title AS course_title, p.program_key
  FROM slides s
  JOIN lessons l ON l.id=s.lesson_id
  JOIN courses c ON c.id=l.course_id
  JOIN programs p ON p.id=c.program_id
  WHERE s.id=? LIMIT 1
");
$stmt->execute([$slideId]);
$slide = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$slide) exit('Slide not found');

$lessonId = (int)$slide['lesson_id'];
$courseId = (int)$slide['course_id'];
$pageNum  = (int)$slide['page_number'];

//
// SECURITY: students may only access slides that belong to a cohort they are enrolled in,
// and that cohort contains this lesson in its schedule.
// (Admin bypasses.)
//
$cohortId = 0;
if ($role === 'student') {
    $uid = (int)$u['id'];
    $chk = $pdo->prepare("
      SELECT cs.cohort_id
      FROM cohort_students cs
      JOIN cohorts co ON co.id = cs.cohort_id
      JOIN cohort_lesson_deadlines d ON d.cohort_id = cs.cohort_id
      WHERE cs.user_id = ?
        AND co.course_id = ?
        AND d.lesson_id = ?
      ORDER BY cs.id DESC
      LIMIT 1
    ");
    $chk->execute([$uid, $courseId, $lessonId]);
    $cohortId = (int)($chk->fetchColumn() ?: 0);
    if ($cohortId <= 0) {
        http_response_code(403);
        exit('Forbidden');
    }
} else {
    // Admin: try to find any cohort for this course (for back link convenience)
    $c = $pdo->prepare("SELECT id FROM cohorts WHERE course_id=? ORDER BY id DESC LIMIT 1");
    $c->execute([$courseId]);
    $cohortId = (int)($c->fetchColumn() ?: 0);
}

$backUrl = $cohortId > 0 ? ('/student/course.php?cohort_id='.(int)$cohortId) : '/student/dashboard.php';

$imgUrl = cdn_url($CDN_BASE, (string)$slide['image_path']);

$HEADER = "/assets/overlay/header.png"; // 1600x125
$FOOTER = "/assets/overlay/footer.png"; // 1600x90

// Hotspots
$hs = $pdo->prepare("SELECT id, label, src, x,y,w,h FROM slide_hotspots WHERE slide_id=? AND is_deleted=0 ORDER BY id ASC");
$hs->execute([$slideId]);
$hotspots = $hs->fetchAll(PDO::FETCH_ASSOC);

// Content EN/ES (for ES popup)
$en = $pdo->prepare("SELECT plain_text FROM slide_content WHERE slide_id=? AND lang='en' LIMIT 1");
$en->execute([$slideId]);
$enText = (string)($en->fetchColumn() ?: '');

$es = $pdo->prepare("SELECT plain_text FROM slide_content WHERE slide_id=? AND lang='es' LIMIT 1");
$es->execute([$slideId]);
$esText = (string)($es->fetchColumn() ?: '');

// References
$refsStmt = $pdo->prepare("
  SELECT ref_type, ref_code, ref_title, confidence, notes
  FROM slide_references
  WHERE slide_id=?
  ORDER BY ref_type, id
");
$refsStmt->execute([$slideId]);
$refs = $refsStmt->fetchAll(PDO::FETCH_ASSOC);

// Prev/Next within lesson
$prevStmt = $pdo->prepare("
  SELECT id FROM slides
  WHERE lesson_id=? AND is_deleted=0 AND page_number < ?
  ORDER BY page_number DESC
  LIMIT 1
");
$prevStmt->execute([$lessonId, $pageNum]);
$prevId = (int)($prevStmt->fetchColumn() ?: 0);

$nextStmt = $pdo->prepare("
  SELECT id FROM slides
  WHERE lesson_id=? AND is_deleted=0 AND page_number > ?
  ORDER BY page_number ASC
  LIMIT 1
");
$nextStmt->execute([$lessonId, $pageNum]);
$nextId = (int)($nextStmt->fetchColumn() ?: 0);

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Slide</title>
  <link rel="stylesheet" href="/assets/app.css">
  <style>
    body{ margin:0; background:#ffffff; color:#0b2a4a; font-family: Manrope, Arial, sans-serif; }

    .topbar2{
      position: sticky;
      top: 0;
      z-index: 80;
      background: rgba(255,255,255,0.97);
      border-bottom: 1px solid #eee;
      padding: 10px 12px;
      display:flex;
      gap:10px;
      align-items:center;
      flex-wrap:wrap;
    }

    .btnx{
      background: rgba(30,60,114,0.10);
      border:1px solid rgba(30,60,114,0.25);
      color:#1e3c72;
      padding:8px 10px;
      border-radius:10px;
      cursor:pointer;
      font-weight:700;
      user-select:none;
      white-space:nowrap;
    }
    .btnx:hover{ background: rgba(30,60,114,0.14); }
    .btnx:disabled{ opacity:.4; cursor:not-allowed; }
    .btnx.on{ background: rgba(30,60,114,0.20); border-color: rgba(30,60,114,0.45); }

    .meta{
      margin-left:auto;
      display:flex;
      gap:10px;
      align-items:center;
      flex-wrap:wrap;
      justify-content:flex-end;
    }
    .meta .tiny{ font-size:12px; opacity:.7; white-space:nowrap; }

    .wrap{ padding:14px; }
    .viewport{
      width:100%;
      max-width: 1200px;
      margin: 0 auto;
      aspect-ratio: 16/9;
      overflow:hidden;
      border-radius: 14px;
      border:1px solid #e6e6e6;
      background:#ffffff;
      position:relative;
    }
    .stage{
      width:1600px; height:900px;
      transform-origin: top left;
      position:absolute; left:0; top:0;
      background:#ffffff;
    }

    .content-img{
      position:absolute;
      width:1315px;
      height:900px;
      left: calc((1600px - 1315px)/2);
      top: 0;
      object-fit: contain;
      background:#ffffff;
      user-drag: none;
      -webkit-user-drag: none;
      user-select: none;
      -webkit-user-select: none;
      pointer-events:none;
    }
    .header-img{
      position:absolute; left:0; top:0;
      width:1600px; height:125px;
      object-fit:cover;
      pointer-events:none;
      user-select:none;
      -webkit-user-drag:none;
    }
    .footer-img{
      position:absolute; left:0; bottom:0;
      width:1600px; height:90px;
      object-fit:cover;
      pointer-events:none;
      user-select:none;
      -webkit-user-drag:none;
    }

    .shield{
      position:absolute;
      inset:0;
      background: transparent;
      z-index: 5;
    }

    .hotspot{
      position:absolute;
      border:2px solid rgba(0,255,255,0.85);
      border-radius:10px;
      background: rgba(0,255,255,0.08);
      cursor:pointer;
      z-index: 10;
    }
    .hotspot .tag{
      position:absolute; left:8px; top:8px;
      font-size:14px; padding:4px 8px;
      border-radius:10px;
      background: rgba(0,0,0,0.55);
      color:#fff;
    }

    .fab{
      position: fixed;
      right: 14px;
      bottom: 14px;
      width: 54px;
      height: 54px;
      border-radius: 999px;
      display:flex;
      align-items:center;
      justify-content:center;
      background: rgba(30,60,114,0.92);
      color:#fff;
      font-weight:900;
      border: none;
      cursor:pointer;
      box-shadow: 0 12px 26px rgba(0,0,0,0.18);
      z-index: 120;
    }

    .modal{
      position:fixed; inset:0;
      display:none;
      align-items:center;
      justify-content:center;
      background: rgba(0,0,0,0.55);
      z-index: 130;
    }
    .modal .box{
      width:min(980px, 94vw);
      max-height: min(86vh, 900px);
      overflow:auto;
      background:#fff;
      border:1px solid rgba(0,0,0,0.10);
      border-radius:16px;
      padding:14px;
      box-shadow: 0 16px 50px rgba(0,0,0,0.25);
    }
    .modal h3{ margin: 0 0 10px 0; }
    .modal pre{
      white-space: pre-wrap;
      word-break: break-word;
      font-family: Manrope, Arial, sans-serif;
      font-size: 14px;
      line-height: 1.35;
      margin:0;
    }

    .vbox{
      width:min(960px, 92vw);
      background:#0b1220;
      border:1px solid rgba(255,255,255,0.12);
      border-radius:16px;
      overflow:hidden;
    }
    .vbox video{ width:100%; height:auto; display:block; }

    .drawer{
      position: fixed;
      right: 14px;
      bottom: 80px;
      width: min(560px, 94vw);
      height: min(520px, 70vh);
      background:#fff;
      border:1px solid #eee;
      border-radius:16px;
      box-shadow: 0 16px 50px rgba(0,0,0,0.18);
      display:none;
      flex-direction:column;
      z-index: 125;
      overflow:hidden;
    }
    .drawer .head{
      padding:10px 12px;
      border-bottom:1px solid #eee;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
    }
    .drawer .tools{
      display:flex; gap:6px; flex-wrap:wrap;
      padding:8px 12px;
      border-bottom:1px solid #eee;
    }
    .rte{
      border:none;
      outline:none;
      padding:12px;
      width:100%;
      height:100%;
      overflow:auto;
      font-family: Manrope, Arial, sans-serif;
      font-size: 14px;
      line-height: 1.35;
    }
    .muted{ opacity:.7; font-size:12px; }
    .summary-alert{
      position: sticky;
      top: 62px;
      z-index: 70;
      max-width: 1200px;
      margin: 10px auto 0 auto;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid #f59e0b;
      background: #fff7ed;
      color: #9a3412;
      box-shadow: 0 8px 24px rgba(0,0,0,0.06);
      display: none;
    }
    .summary-alert strong{
      display:block;
      margin-bottom:4px;
    }
    .summary-alert.pending{
      border-color:#93c5fd;
      background:#eff6ff;
      color:#1d4ed8;
    }	  
	  
	  
  </style>
</head>
<body>

  <div class="topbar2">
    <button class="btnx" onclick="location.href='<?= htmlspecialchars($backUrl) ?>'">Lesson Menu</button>

    <button class="btnx" id="btnPrev" <?= $prevId ? '' : 'disabled' ?>>◀ Prev</button>
    <button class="btnx" id="btnNext" <?= $nextId ? '' : 'disabled' ?>>Next ▶</button>

    <label class="muted" style="display:flex;align-items:center;gap:6px;">
      <span style="font-weight:700; color:#1e3c72;">Lang</span>
      <select id="langSel" class="input" style="height:34px;">
        <option value="en">English</option>
        <option value="es">Español</option>
      </select>
    </label>

    <button class="btnx" id="btnAudioPlay">▶︎ Audio</button>
    <button class="btnx" id="btnAudioPause">⏸︎</button>
    <button class="btnx" id="btnAudioRew">↺</button>
    <button class="btnx" id="btnAudioMute">Mute</button>

    <button class="btnx" id="btnRefs">Study Refs</button>
    <button class="btnx" id="btnTxtES" style="display:none;">Spanish text</button>

    <div class="meta">
      <span class="tiny"><?= h($slide['program_key']) ?> • <?= h($slide['course_title']) ?> • Lesson <?= (int)$slide['external_lesson_id'] ?> • Page <?= (int)$pageNum ?></span>
      <span class="tiny" id="clock">--:--</span>
    </div>
  </div>

  <div class="summary-alert" id="summaryAlert">
    <strong id="summaryAlertTitle"></strong>
    <div id="summaryAlertBody"></div>
  </div>	
	
  <div class="wrap">
    <div class="viewport" id="viewport">
      <div class="stage" id="stage">
        <img class="content-img" src="<?= htmlspecialchars($imgUrl) ?>" alt="">
        <img class="header-img" src="<?= htmlspecialchars($HEADER) ?>" alt="">
        <img class="footer-img" src="<?= htmlspecialchars($FOOTER) ?>" alt="">
        <div class="shield" id="shield"></div>

        <?php foreach ($hotspots as $h): ?>
          <div class="hotspot"
              data-src="<?= htmlspecialchars($h['src']) ?>"
              data-label="<?= htmlspecialchars($h['label']) ?>"
              style="left:<?= (int)$h['x'] ?>px; top:<?= (int)$h['y'] ?>px; width:<?= (int)$h['w'] ?>px; height:<?= (int)$h['h'] ?>px;">
            <div class="tag"><?= htmlspecialchars($h['label']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Audio element for OpenAI TTS -->
  <audio id="ttsAudio" preload="none"></audio>

  <button class="fab" id="btnSummary" title="My Study Summary">📝</button>

  <div class="drawer" id="drawer">
    <div class="head">
      <strong>My Study Summary (Lesson)</strong>
      <span class="muted" id="sumStatus">Saved</span>
      <button class="btnx" id="btnCloseDrawer" style="padding:6px 10px;">Close</button>
    </div>
    <div class="tools">
      <button class="btnx" type="button" data-cmd="bold" style="padding:6px 10px;">B</button>
      <button class="btnx" type="button" data-cmd="italic" style="padding:6px 10px;">I</button>
      <button class="btnx" type="button" data-cmd="insertUnorderedList" style="padding:6px 10px;">•</button>
    </div>
    <div id="rte" class="rte" contenteditable="true"></div>
  </div>

  <div class="modal" id="modalRefs">
    <div class="box">
      <h3>Study References</h3>
      <div class="muted" style="margin-bottom:10px;">PHAK / ACS (and others if present)</div>
      <div id="refsBody"></div>
      <div style="margin-top:12px; text-align:right;">
        <button class="btnx" id="btnCloseRefs">Close</button>
      </div>
    </div>
  </div>

  <div class="modal" id="modalES">
    <div class="box">
      <h3>Spanish Translation</h3>
      <pre id="esBody"></pre>
      <div style="margin-top:12px; text-align:right;">
        <button class="btnx" id="btnCloseES">Close</button>
      </div>
    </div>
  </div>

  <div class="modal" id="modalVid">
    <div class="vbox">
      <video id="vid" controls playsinline></video>
    </div>
  </div>

<script>
const SLIDE_ID = <?= (int)$slideId ?>;
const PREV_ID = <?= (int)$prevId ?>;
const NEXT_ID = <?= (int)$nextId ?>;

const viewport = document.getElementById('viewport');
const stage = document.getElementById('stage');
let scale = 1;

function fitStage(){
  const vw = viewport.clientWidth;
  const vh = viewport.clientHeight;
  scale = Math.min(vw/1600, vh/900);
  stage.style.transform = `scale(${scale})`;
}
window.addEventListener('resize', ()=>setTimeout(fitStage, 60));
setTimeout(fitStage, 30);

// Prevent context menu (basic deterrent)
document.getElementById('shield').addEventListener('contextmenu', (e)=>e.preventDefault());
document.addEventListener('contextmenu', (e)=>{
  if (e.target.closest && e.target.closest('#viewport')) e.preventDefault();
});

// Clock
function tickClock(){
  const d = new Date();
  const hh = String(d.getHours()).padStart(2,'0');
  const mm = String(d.getMinutes()).padStart(2,'0');
  document.getElementById('clock').textContent = hh + ':' + mm;
}
tickClock(); setInterval(tickClock, 10000);

// Language dropdown
const PREF_KEY = 'ipca_lang_pref';
let lang = localStorage.getItem(PREF_KEY) || 'en';
const langSel = document.getElementById('langSel');
langSel.value = (lang === 'es') ? 'es' : 'en';

const btnTxtES  = document.getElementById('btnTxtES');
const ES_TEXT = <?= json_encode($esText) ?>;

function applyLangUI(){
  btnTxtES.style.display = (lang==='es') ? 'inline-block' : 'none';
}
function setLang(newLang){
  lang = newLang;
  localStorage.setItem(PREF_KEY, lang);
  applyLangUI();
}

// Autoplay arm
const AUTO_KEY = 'ipca_autoplay_next';
function armAutoplay(){ localStorage.setItem(AUTO_KEY, '1'); }
function consumeAutoplay(){
  const v = localStorage.getItem(AUTO_KEY);
  if (v === '1') {
    localStorage.removeItem(AUTO_KEY);
    setTimeout(()=>playTTS(), 350);
  }
}

langSel.addEventListener('change', ()=>{
  setLang(langSel.value);

  // stop audio when switching language
  ttsAudio.pause();
  ttsAudio.currentTime = 0;
  ttsAudio.removeAttribute('src');
  ttsAudio.dataset.src = '';
  setPlayLabel('idle');

  // Prefetch neighbors in the new language
  prefetchNeighborAudio();
});
applyLangUI();

// ---- AI Voice (OpenAI TTS MP3 via API) ----
const ttsAudio = document.getElementById('ttsAudio');
const btnPlay  = document.getElementById('btnAudioPlay');
const btnMute  = document.getElementById('btnAudioMute');

const MUTE_KEY = 'ipca_tts_muted';

function setPlayLabel(state){
  if (state === 'generating') btnPlay.textContent = 'Generating Audio…';
  else btnPlay.textContent = '▶︎ Audio';
}

function applyMuteUI(){
  const muted = (localStorage.getItem(MUTE_KEY) === '1');
  ttsAudio.muted = muted;
  btnMute.classList.toggle('on', muted);
  btnMute.textContent = muted ? 'Unmute' : 'Mute';
}

function ttsUrl(prefetch=false, slideId=SLIDE_ID, useLang=lang){
  const p = prefetch ? '&prefetch=1' : '';
  return `/player/api/tts.php?slide_id=${slideId}&lang=${encodeURIComponent(useLang)}${p}`;
}

async function playTTS(){
  const want = ttsUrl(false, SLIDE_ID, lang);

  const isNew = (ttsAudio.dataset.src !== want);
  if (isNew) {
    setPlayLabel('generating');
    ttsAudio.pause();
    ttsAudio.currentTime = 0;
    ttsAudio.src = want;
    ttsAudio.dataset.src = want;
  }

  try {
    await ttsAudio.play();
    setPlayLabel('idle');
  } catch(e) {
    setPlayLabel('idle');
  }
}

// Button handlers
document.getElementById('btnAudioPlay').onclick = ()=> playTTS();
document.getElementById('btnAudioPause').onclick = ()=> ttsAudio.pause();
document.getElementById('btnAudioRew').onclick = ()=>{
  ttsAudio.currentTime = 0;
  playTTS();
};

btnMute.onclick = ()=>{
  const muted = !(ttsAudio.muted);
  ttsAudio.muted = muted;
  localStorage.setItem(MUTE_KEY, muted ? '1' : '0');
  applyMuteUI();
};

// audio events
ttsAudio.addEventListener('waiting', ()=> setPlayLabel('generating'));
ttsAudio.addEventListener('canplay', ()=> setPlayLabel('idle'));
ttsAudio.addEventListener('playing', ()=> setPlayLabel('idle'));
ttsAudio.addEventListener('ended', ()=> setPlayLabel('idle'));

applyMuteUI();
consumeAutoplay();

// ---- Prefetch next/prev slide audio (warm cache) ----
async function prefetchOne(slideId){
  if (!slideId || slideId <= 0) return;
  try {
    await fetch(ttsUrl(true, slideId, lang), { method:'GET', credentials:'same-origin' });
  } catch(e) {}
}
async function prefetchNeighborAudio(){
  if (NEXT_ID > 0) prefetchOne(NEXT_ID);
  if (PREV_ID > 0) prefetchOne(PREV_ID);
}
setTimeout(prefetchNeighborAudio, 600);

// ---- Prev/Next clicks: arm autoplay + navigate ----
document.getElementById('btnPrev').onclick = (e)=>{
  if (PREV_ID <= 0) return;
  armAutoplay();
  location.href = '/player/slide.php?slide_id=' + PREV_ID;
};
document.getElementById('btnNext').onclick = (e)=>{
  if (NEXT_ID <= 0) return;
  armAutoplay();
  location.href = '/player/slide.php?slide_id=' + NEXT_ID;
};

// ---- Video modal ----
const CDN_BASE = <?= json_encode(rtrim($CDN_BASE,'/')) ?>;
const modalVid = document.getElementById('modalVid');
const vid = document.getElementById('vid');

document.querySelectorAll('.hotspot').forEach(h=>{
  h.addEventListener('click', ()=>{
    const src = h.dataset.src || '';
    if (!src) return alert('No video linked yet.');
    vid.src = src.startsWith('http') ? src : (CDN_BASE + '/' + src.replace(/^\/+/, ''));
    modalVid.style.display = 'flex';
    vid.play().catch(()=>{});
  });
});
modalVid.addEventListener('click', (e)=>{
  if (e.target === modalVid){
    vid.pause();
    vid.src = '';
    modalVid.style.display = 'none';
  }
});

// ---- Study refs modal ----
const REFS = <?= json_encode($refs) ?>;
const modalRefs = document.getElementById('modalRefs');
const refsBody = document.getElementById('refsBody');
document.getElementById('btnRefs').onclick = ()=>{
  const groups = {};
  (REFS||[]).forEach(r=>{
    const t = r.ref_type || 'OTHER';
    if (!groups[t]) groups[t]=[];
    groups[t].push(r);
  });

  let html = '';
  Object.keys(groups).sort().forEach(t=>{
    html += `<div style="margin-bottom:10px;"><strong>${t}</strong><ul style="margin:6px 0 0 18px;">`;
    groups[t].forEach(r=>{
      const code = (r.ref_code||'').toString();
      const title = (r.ref_title||'').toString();
      html += `<li><span style="font-weight:700;">${escapeHtml(code)}</span> ${title ? '— '+escapeHtml(title) : ''}</li>`;
    });
    html += `</ul></div>`;
  });
  if (!html) html = `<div class="muted">No references saved yet for this slide.</div>`;

  refsBody.innerHTML = html;
  modalRefs.style.display='flex';
};
document.getElementById('btnCloseRefs').onclick = ()=> modalRefs.style.display='none';
modalRefs.addEventListener('click', (e)=>{ if(e.target===modalRefs) modalRefs.style.display='none'; });

// ---- Spanish text modal ----
const modalES = document.getElementById('modalES');
document.getElementById('esBody').textContent = ES_TEXT || '(No Spanish text yet)';
btnTxtES.onclick = ()=>{
  document.getElementById('esBody').textContent = ES_TEXT || '(No Spanish text yet)';
  modalES.style.display='flex';
};
document.getElementById('btnCloseES').onclick = ()=> modalES.style.display='none';
modalES.addEventListener('click', (e)=>{ if(e.target===modalES) modalES.style.display='none'; });

// ---- Summary (lesson-level rich text, autosave to DB) ----
const COHORT_ID = <?= (int)$cohortId ?>;
const LESSON_ID = <?= (int)$lessonId ?>;

const drawer = document.getElementById('drawer');
const rte = document.getElementById('rte');
const sumStatus = document.getElementById('sumStatus');
const summaryAlert = document.getElementById('summaryAlert');
const summaryAlertTitle = document.getElementById('summaryAlertTitle');
const summaryAlertBody = document.getElementById('summaryAlertBody');
	
function renderSummaryAlert(j){
  const status = String(j.review_status || '').trim();
  const feedback = String(j.review_notes_by_instructor || j.review_feedback || '').trim();

  summaryAlert.classList.remove('pending');
  summaryAlert.style.display = 'none';
  summaryAlertTitle.textContent = '';
  summaryAlertBody.textContent = '';

  if (status === 'needs_revision') {
    summaryAlert.style.display = 'block';
    summaryAlertTitle.textContent = 'Instructor requested summary revision';
    summaryAlertBody.textContent = feedback !== ''
      ? feedback
      : 'Please revise your lesson summary based on the instructor feedback before continuing.';
  } else if (status === 'pending') {
    summaryAlert.style.display = 'block';
    summaryAlert.classList.add('pending');
    summaryAlertTitle.textContent = 'Summary pending instructor review';
    summaryAlertBody.textContent = 'Your updated summary has been saved and is awaiting instructor review.';
  }
}	

async function loadSummaryFromDb(){
  try{
    const res = await fetch(`/student/api/summary_get.php?cohort_id=${COHORT_ID}&lesson_id=${LESSON_ID}`, {credentials:'same-origin'});
    const j = await res.json();
    if (j.ok) {
      rte.innerHTML = j.summary_html || '';
      sumStatus.textContent = 'Loaded';
      renderSummaryAlert(j);
    }
  }catch(e){}
}

async function refreshSummaryStatusOnly(){
  try{
    const res = await fetch(`/student/api/summary_get.php?cohort_id=${COHORT_ID}&lesson_id=${LESSON_ID}`, {credentials:'same-origin'});
    const j = await res.json();
    if (j.ok) {
      renderSummaryAlert(j);
    }
  }catch(e){}
}

let saveTimer = null;
function scheduleSave(){
  if (saveTimer) clearTimeout(saveTimer);
  sumStatus.textContent = 'Saving…';
  saveTimer = setTimeout(async ()=>{
    try{
      const res = await fetch('/student/api/summary_save.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        credentials:'same-origin',
        body: JSON.stringify({
          cohort_id: COHORT_ID,
          lesson_id: LESSON_ID,
          summary_html: rte.innerHTML || ''
        })
      });
const j = await res.json();
      sumStatus.textContent = j.ok ? 'Saved' : ('Save failed');
      if (j.ok) {
        refreshSummaryStatusOnly();
      }
    }catch(e){
      sumStatus.textContent = 'Save failed';
    }
  }, 800);
}
rte.addEventListener('input', scheduleSave);

document.querySelectorAll('.drawer .tools button[data-cmd]').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const cmd = btn.getAttribute('data-cmd');
    document.execCommand(cmd, false, null);
    rte.focus();
    scheduleSave();
  });
});

document.getElementById('btnSummary').onclick = ()=>{
  drawer.style.display = (drawer.style.display==='flex') ? 'none' : 'flex';
  if (drawer.style.display==='flex') setTimeout(()=>rte.focus(), 80);
};
document.getElementById('btnCloseDrawer').onclick = ()=> drawer.style.display='none';

loadSummaryFromDb();

// utils
function escapeHtml(s){
  return (s||'').toString()
    .replaceAll('&','&amp;').replaceAll('<','&lt;')
    .replaceAll('>','&gt;').replaceAll('"','&quot;');
}

// Keyboard nav
function isEditableTarget(el){
  if (!el) return false;
  const tag = (el.tagName || '').toUpperCase();

  if (el.isContentEditable) return true;
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
  if (typeof el.closest === 'function' && el.closest('#rte')) return true;

  return false;
}

document.addEventListener('keydown', (e)=>{
  // Do not hijack arrow keys while editing the summary
  if (isEditableTarget(e.target)) {
    return;
  }

  if (e.key === 'ArrowLeft') {
    if (PREV_ID > 0) {
      armAutoplay();
      location.href = '/player/slide.php?slide_id=' + PREV_ID;
    }
  }

  if (e.key === 'ArrowRight') {
    if (NEXT_ID > 0) {
      armAutoplay();
      location.href = '/player/slide.php?slide_id=' + NEXT_ID;
    }
  }
});
</script>
</body>
</html>