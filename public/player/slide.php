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
    $c = $pdo->prepare("SELECT id FROM cohorts WHERE course_id=? ORDER BY id DESC LIMIT 1");
    $c->execute([$courseId]);
    $cohortId = (int)($c->fetchColumn() ?: 0);
}

$backUrl = $cohortId > 0 ? ('/student/course.php?cohort_id='.(int)$cohortId) : '/student/dashboard.php';

$imgUrl = cdn_url($CDN_BASE, (string)$slide['image_path']);

$HEADER = "/assets/overlay/header.png";
$FOOTER = "/assets/overlay/footer.png";

$hs = $pdo->prepare("SELECT id, label, src, x,y,w,h FROM slide_hotspots WHERE slide_id=? AND is_deleted=0 ORDER BY id ASC");
$hs->execute([$slideId]);
$hotspots = $hs->fetchAll(PDO::FETCH_ASSOC);

$en = $pdo->prepare("SELECT plain_text FROM slide_content WHERE slide_id=? AND lang='en' LIMIT 1");
$en->execute([$slideId]);
$enText = (string)($en->fetchColumn() ?: '');

$es = $pdo->prepare("SELECT plain_text FROM slide_content WHERE slide_id=? AND lang='es' LIMIT 1");
$es->execute([$slideId]);
$esText = (string)($es->fetchColumn() ?: '');

$refsStmt = $pdo->prepare("
  SELECT ref_type, ref_code, ref_title, confidence, notes
  FROM slide_references
  WHERE slide_id=?
  ORDER BY ref_type, id
");
$refsStmt->execute([$slideId]);
$refs = $refsStmt->fetchAll(PDO::FETCH_ASSOC);

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
$isAdminViewer = ($role === 'admin');

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
 	.btnx.locked-disabled{
  		opacity:.45;
  		cursor:not-allowed;
  		pointer-events:auto;
	}
	  
	  
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
      max-width:1200px;
      margin:0 auto;
      aspect-ratio:16/9;
      overflow:hidden;
      border-radius:14px;
      border:1px solid #e6e6e6;
      background:#ffffff;
      position:relative;
    }
    .stage{
      width:1600px;
      height:900px;
      transform-origin:top left;
      position:absolute;
      left:0;
      top:0;
      background:#ffffff;
    }

    .content-img{
      position:absolute;
      width:1315px;
      height:900px;
      left:calc((1600px - 1315px)/2);
      top:0;
      object-fit:contain;
      background:#ffffff;
      user-drag:none;
      -webkit-user-drag:none;
      user-select:none;
      -webkit-user-select:none;
      pointer-events:none;
    }
    .header-img{
      position:absolute;
      left:0;
      top:0;
      width:1600px;
      height:125px;
      object-fit:cover;
      pointer-events:none;
      user-select:none;
      -webkit-user-drag:none;
    }
    .footer-img{
      position:absolute;
      left:0;
      bottom:0;
      width:1600px;
      height:90px;
      object-fit:cover;
      pointer-events:none;
      user-select:none;
      -webkit-user-drag:none;
    }

    .shield{
      position:absolute;
      inset:0;
      background:transparent;
      z-index:5;
    }

.hotspot{
  position:absolute;
  cursor:pointer;
  z-index:10;
}

/* Admin sees editable/visible hotspot areas */
.viewer-admin .hotspot{
  border:2px solid rgba(0,255,255,0.85);
  border-radius:10px;
  background:rgba(0,255,255,0.08);
}

.viewer-admin .hotspot .tag{
  position:absolute;
  left:8px;
  top:8px;
  font-size:14px;
  padding:4px 8px;
  border-radius:10px;
  background:rgba(0,0,0,0.55);
  color:#fff;
}

/* Students get invisible but still clickable hotspots */
.viewer-student .hotspot{
  border:none;
  border-radius:0;
  background:transparent;
}

.viewer-student .hotspot .tag{
  display:none;
}

    .fab{
      position:fixed;
      right:14px;
      bottom:14px;
      width:54px;
      height:54px;
      border-radius:999px;
      display:flex;
      align-items:center;
      justify-content:center;
      background:rgba(30,60,114,0.92);
      color:#fff;
      font-weight:900;
      border:none;
      cursor:pointer;
      box-shadow:0 12px 26px rgba(0,0,0,0.18);
      z-index:120;
    }

    .modal{
      position:fixed;
      inset:0;
      display:none;
      align-items:center;
      justify-content:center;
      background:rgba(0,0,0,0.55);
      z-index:130;
    }
    .modal .box{
      width:min(980px, 94vw);
      max-height:min(86vh, 900px);
      overflow:auto;
      background:#fff;
      border:1px solid rgba(0,0,0,0.10);
      border-radius:16px;
      padding:14px;
      box-shadow:0 16px 50px rgba(0,0,0,0.25);
    }
    .modal h3{ margin:0 0 10px 0; }
    .modal pre{
      white-space:pre-wrap;
      word-break:break-word;
      font-family:Manrope, Arial, sans-serif;
      font-size:14px;
      line-height:1.35;
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
      position:fixed;
      right:14px;
      bottom:80px;
      width:min(560px, 94vw);
      height:min(520px, 70vh);
      background:#fff;
      border:1px solid #eee;
      border-radius:16px;
      box-shadow:0 16px 50px rgba(0,0,0,0.18);
      display:none;
      flex-direction:column;
      z-index:125;
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
      display:flex;
      gap:6px;
      flex-wrap:wrap;
      padding:8px 12px;
      border-bottom:1px solid #eee;
    }
	.drawer.expanded{
	  right:50%;
	  transform:translateX(50%);
	  width:min(1200px, 96vw);
	  height:min(72vh, 820px);
	  bottom:18px;
	}

	.drawer.expanded .rte{
	  font-size:15px;
	  line-height:1.7;
	}

@media (max-width: 900px){
  .drawer.expanded{
    width:min(98vw, 98vw);
    height:min(78vh, 860px);
    right:50%;
    transform:translateX(50%);
    bottom:10px;
  }
}  
	  
	  
.rte{
  border:none;
  outline:none;
  padding:12px;
  width:100%;
  max-width:100%;
  height:100%;
  box-sizing:border-box;
  overflow-y:auto;
  overflow-x:hidden;
  font-family:Manrope, Arial, sans-serif;
  font-size:14px;
  line-height:1.6;
  background:#fff;
  white-space:normal;
  overflow-wrap:anywhere;
  word-break:break-word;
}

.rte p{ margin:0 0 12px 0; }
.rte ul,
.rte ol{ margin:0 0 12px 22px; }
.rte li{ margin:0 0 6px 0; }
.rte strong,
.rte b{ color:#16263c; }
.rte mark{
  background:#fff59d;
  color:inherit;
  padding:0 .1em;
  border-radius:3px;
}

.rte.size-sm { font-size:13px; }
.rte.size-md { font-size:14px; }
.rte.size-lg { font-size:16px; }

.rte .size-sm { font-size:13px; }
.rte .size-md { font-size:14px; }
.rte .size-lg { font-size:16px; }
	  
    .muted{ opacity:.7; font-size:12px; }

    .summary-alert{
      position:sticky;
      top:62px;
      z-index:70;
      width:100%;
      max-width:1200px;
      box-sizing:border-box;
      margin:10px auto 0 auto;
      padding:12px 44px 12px 14px;
      border-radius:12px;
      border:1px solid #f59e0b;
      background:#fff7ed;
      color:#9a3412;
      box-shadow:0 8px 24px rgba(0,0,0,0.06);
      display:none;
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
    .summary-alert.ok{
      border-color:#86efac;
      background:#f0fdf4;
      color:#166534;
    }
    .summary-alert-close{
      position:absolute;
      top:8px;
      right:8px;
      width:28px;
      height:28px;
      border:none;
      border-radius:999px;
      background:transparent;
      color:inherit;
      font-size:18px;
      font-weight:800;
      line-height:1;
      cursor:pointer;
    }
    .summary-alert-close:hover{
      background:rgba(0,0,0,0.06);
    }
	  
	.btnx.audio-pulse{
      animation:audioPulse 1.2s ease-in-out infinite;
      box-shadow:0 0 0 0 rgba(30,60,114,0.45);
    }

    @keyframes audioPulse{
      0%{
        transform:scale(1);
        box-shadow:0 0 0 0 rgba(30,60,114,0.45);
      }
      50%{
        transform:scale(1.04);
        box-shadow:0 0 0 10px rgba(30,60,114,0);
      }
      100%{
        transform:scale(1);
        box-shadow:0 0 0 0 rgba(30,60,114,0);
      }
    }  
	  
	  
	  
  </style>
</head>
<body class="<?= $isAdminViewer ? 'viewer-admin' : 'viewer-student' ?>">

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
    <button type="button" class="summary-alert-close" id="summaryAlertClose" aria-label="Close">×</button>
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

  <audio id="ttsAudio" preload="none"></audio>

  <button class="fab" id="btnSummary" title="My Study Summary">📝</button>

  <div class="drawer" id="drawer">
    <div class="head">
      <strong>My Study Summary (Lesson)</strong>
      <span class="muted" id="sumStatus">Draft</span>
      <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
		  <button class="btnx" id="btnCheckSummary">Check my Summary</button>
		  <button class="btnx" id="btnUnlockSummary" style="display:none;">Unlock</button>
		  <button class="btnx" id="btnToggleSummarySize">Expand</button>
		  <button class="btnx" id="btnCloseDrawer" style="padding:6px 10px;">Close</button>
	  </div>
    </div>
<div class="tools">
  <button class="btnx" type="button" data-cmd="bold">B</button>
  <button class="btnx" type="button" data-cmd="italic">I</button>
  <button class="btnx" type="button" data-cmd="underline">U</button>
  <button class="btnx" type="button" data-cmd="insertUnorderedList">•</button>

  <!-- NEW -->
  <button class="btnx" type="button" data-size="sm">S</button>
  <button class="btnx" type="button" data-size="md">M</button>
  <button class="btnx" type="button" data-size="lg">L</button>
  <button class="btnx" type="button" id="btnHighlight">Highlight</button>
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
let isLocked = false;
let currentReviewStatus = 'pending';	
	
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

document.getElementById('shield').addEventListener('contextmenu', (e)=>e.preventDefault());
document.addEventListener('contextmenu', (e)=>{
  if (e.target.closest && e.target.closest('#viewport')) e.preventDefault();
});

function tickClock(){
  const d = new Date();
  const hh = String(d.getHours()).padStart(2,'0');
  const mm = String(d.getMinutes()).padStart(2,'0');
  document.getElementById('clock').textContent = hh + ':' + mm;
}
tickClock(); setInterval(tickClock, 10000);

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

const AUTO_KEY = 'ipca_autoplay_next';

function armAutoplay(){
  localStorage.removeItem(AUTO_KEY);
}

function consumeAutoplay(){
  localStorage.removeItem(AUTO_KEY);
}

langSel.addEventListener('change', ()=>{
  setLang(langSel.value);

  ttsAudio.pause();
  ttsAudio.currentTime = 0;
  ttsAudio.removeAttribute('src');
  ttsAudio.dataset.src = '';
  setPlayLabel('idle');

  prefetchNeighborAudio();
});
applyLangUI();

const ttsAudio = document.getElementById('ttsAudio');
const btnPlay  = document.getElementById('btnAudioPlay');
const btnMute  = document.getElementById('btnAudioMute');

const MUTE_KEY = 'ipca_tts_muted';

	
function startAudioPulse(){
  btnPlay.classList.add('audio-pulse');
}

function stopAudioPulse(){
  btnPlay.classList.remove('audio-pulse');
}	
	
function setPlayLabel(state){
  if (state === 'generating') {
    stopAudioPulse();
    btnPlay.textContent = 'Generating Audio…';
  } else {
    btnPlay.textContent = '▶︎ Audio';
  }
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

document.getElementById('btnAudioPlay').onclick = ()=>{
  stopAudioPulse();
  playTTS();
};
document.getElementById('btnAudioPause').onclick = ()=> ttsAudio.pause();
document.getElementById('btnAudioRew').onclick = ()=>{
  stopAudioPulse();
  ttsAudio.currentTime = 0;
  playTTS();
};

btnMute.onclick = ()=>{
  const muted = !(ttsAudio.muted);
  ttsAudio.muted = muted;
  localStorage.setItem(MUTE_KEY, muted ? '1' : '0');
  applyMuteUI();
};

ttsAudio.addEventListener('waiting', ()=> setPlayLabel('generating'));
ttsAudio.addEventListener('canplay', ()=> setPlayLabel('idle'));
ttsAudio.addEventListener('playing', ()=> setPlayLabel('idle'));
ttsAudio.addEventListener('ended', ()=> setPlayLabel('idle'));

applyMuteUI();
consumeAutoplay();
startAudioPulse();

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


document.getElementById('btnPrev').onclick = async ()=>{
  if (PREV_ID <= 0) return;

  if (drawer.style.display === 'flex') {
    saveSummaryDrawerState(true);
    await flushSummarySaveNow();
  } else {
    saveSummaryDrawerState(false);
  }

  armAutoplay();
  location.href = '/player/slide.php?slide_id=' + PREV_ID;
};	
	
document.getElementById('btnNext').onclick = async ()=>{
  if (NEXT_ID <= 0) return;

  if (drawer.style.display === 'flex') {
    saveSummaryDrawerState(true);
    await flushSummarySaveNow();
  } else {
    saveSummaryDrawerState(false);
  }

  armAutoplay();
  location.href = '/player/slide.php?slide_id=' + NEXT_ID;
};

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

const modalES = document.getElementById('modalES');
document.getElementById('esBody').textContent = ES_TEXT || '(No Spanish text yet)';
btnTxtES.onclick = ()=>{
  document.getElementById('esBody').textContent = ES_TEXT || '(No Spanish text yet)';
  modalES.style.display='flex';
};
document.getElementById('btnCloseES').onclick = ()=> modalES.style.display='none';
modalES.addEventListener('click', (e)=>{ if(e.target===modalES) modalES.style.display='none'; });

const COHORT_ID = <?= (int)$cohortId ?>;
const LESSON_ID = <?= (int)$lessonId ?>;

const drawer = document.getElementById('drawer');
const rte = document.getElementById('rte');
	
	
function summaryUiStateKey(){
  return 'ipca_summary_ui_state|cohort:' + String(COHORT_ID) + '|lesson:' + String(LESSON_ID);
}

function getTextOffsetWithin(root, targetNode, targetOffset){
  let total = 0;

  function walk(node){
    if (node === targetNode) {
      total += targetOffset;
      throw new Error('__FOUND__');
    }

    if (node.nodeType === Node.TEXT_NODE) {
      total += node.nodeValue ? node.nodeValue.length : 0;
      return;
    }

    let child = node.firstChild;
    while (child) {
      walk(child);
      child = child.nextSibling;
    }
  }

  try {
    walk(root);
  } catch (e) {
    if (e && e.message === '__FOUND__') {
      return total;
    }
    throw e;
  }

  return total;
}

function findTextPosition(root, charIndex){
  let remaining = Math.max(0, charIndex);
  let result = { node: root, offset: 0 };

  function walk(node){
    if (node.nodeType === Node.TEXT_NODE) {
      const len = node.nodeValue ? node.nodeValue.length : 0;
      if (remaining <= len) {
        result = { node: node, offset: remaining };
        throw new Error('__FOUND__');
      }
      remaining -= len;
      return;
    }

    let child = node.firstChild;
    while (child) {
      walk(child);
      child = child.nextSibling;
    }
  }

  try {
    walk(root);
  } catch (e) {
    if (e && e.message === '__FOUND__') {
      return result;
    }
    throw e;
  }

  return result;
}

function saveSummaryUiState(){
  try {
    const state = {
      drawerOpen: drawer.style.display === 'flex',
      pageScrollY: window.scrollY || window.pageYOffset || 0,
      rteScrollTop: rte ? rte.scrollTop : 0,
      selectionStart: null,
      selectionEnd: null
    };

    if (state.drawerOpen) {
      const sel = window.getSelection();
      if (sel && sel.rangeCount > 0) {
        const range = sel.getRangeAt(0);

        if (rte.contains(range.startContainer) && rte.contains(range.endContainer)) {
          state.selectionStart = getTextOffsetWithin(rte, range.startContainer, range.startOffset);
          state.selectionEnd = getTextOffsetWithin(rte, range.endContainer, range.endOffset);
        }
      }
    }

    sessionStorage.setItem(summaryUiStateKey(), JSON.stringify(state));
  } catch (e) {}
}

function loadSummaryUiState(){
  try {
    const raw = sessionStorage.getItem(summaryUiStateKey());
    if (!raw) return null;
    return JSON.parse(raw);
  } catch (e) {
    return null;
  }
}

function restoreSummaryUiState(){
  const state = loadSummaryUiState();
  if (!state) return;

  if (state.drawerOpen) {
    drawer.style.display = 'flex';
  }

  setTimeout(function(){
    try {
      window.scrollTo(0, Number(state.pageScrollY || 0));
    } catch (e) {}
  }, 0);

  if (!state.drawerOpen) return;

  setTimeout(function(){
    try {
      rte.scrollTop = Number(state.rteScrollTop || 0);
    } catch (e) {}
  }, 0);

  if (state.selectionStart === null || state.selectionEnd === null) return;

  setTimeout(function(){
    try {
      const startPos = findTextPosition(rte, Number(state.selectionStart || 0));
      const endPos = findTextPosition(rte, Number(state.selectionEnd || 0));

      const range = document.createRange();
      range.setStart(startPos.node, startPos.offset);
      range.setEnd(endPos.node, endPos.offset);

      const sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
    } catch (e) {}
  }, 20);
}	
	
	
	
const sumStatus = document.getElementById('sumStatus');
const btnCheckSummary = document.getElementById('btnCheckSummary');
const btnUnlockSummary = document.getElementById('btnUnlockSummary');
const btnToggleSummarySize = document.getElementById('btnToggleSummarySize');
const summaryAlert = document.getElementById('summaryAlert');
const summaryAlertClose = document.getElementById('summaryAlertClose');
const summaryAlertTitle = document.getElementById('summaryAlertTitle');
const summaryAlertBody = document.getElementById('summaryAlertBody');

function showBanner(message, kind){
  sumStatus.textContent = message;

  setTimeout(function(){
    updateDrawerLockState();
  }, 2000);
}

let saveTimer = null;
let summaryAlertDismissed = false;

function summaryBannerStorageKey(status, feedback){
  return 'ipca_summary_banner_seen'
    + '|cohort:' + String(COHORT_ID)
    + '|lesson:' + String(LESSON_ID)
    + '|status:' + String(status || '')
    + '|feedback:' + String(feedback || '');
}

function markSummaryBannerSeen(status, feedback){
  try {
    sessionStorage.setItem(summaryBannerStorageKey(status, feedback), '1');
  } catch(e){}
}

function hasSeenSummaryBanner(status, feedback){
  try {
    return sessionStorage.getItem(summaryBannerStorageKey(status, feedback)) === '1';
  } catch(e){
    return false;
  }
}

summaryAlertClose.addEventListener('click', ()=>{
  const status = String(summaryAlert.dataset.status || '').trim();
  const feedback = String(summaryAlert.dataset.feedback || '').trim();

  summaryAlertDismissed = true;
  markSummaryBannerSeen(status, feedback);
  summaryAlert.style.display = 'none';
});

function renderSummaryAlert(j, options){
  options = options || {};

  const status = String(j.review_status || '').trim();
  const feedback = String(j.review_notes_by_instructor || j.review_feedback || '').trim();
  const suppressPending = !!options.suppressPending;
  const forceShow = !!options.forceShow;

  const newBannerKey = status + '|' + feedback;
  if (renderSummaryAlert._lastKey !== newBannerKey) {
    summaryAlertDismissed = false;
    renderSummaryAlert._lastKey = newBannerKey;
  }

  summaryAlert.classList.remove('pending', 'ok');
  summaryAlert.style.display = 'none';
  summaryAlertTitle.textContent = '';
  summaryAlertBody.textContent = '';
  summaryAlert.dataset.status = status;
  summaryAlert.dataset.feedback = feedback;

  if (summaryAlertDismissed) {
    return;
  }

  if (!forceShow && hasSeenSummaryBanner(status, feedback)) {
    return;
  }

  if (status === 'acceptable') {
    summaryAlert.style.display = 'block';
    summaryAlert.classList.add('ok');
    summaryAlertTitle.textContent = 'Accepted';
    summaryAlertBody.textContent = 'Accepted: Edit via Notebook if needed.';
    markSummaryBannerSeen(status, feedback);
    return;
  }

  if (status === 'needs_revision' || status === 'rejected') {
    summaryAlert.style.display = 'block';
    summaryAlertTitle.textContent = 'Not Accepted';
    summaryAlertBody.textContent = feedback !== ''
      ? feedback
      : 'Not accepted: Keep working on it and check again.';
    markSummaryBannerSeen(status, feedback);
    return;
  }

  if (status === 'pending') {
    if (suppressPending) {
      return;
    }

    summaryAlert.style.display = 'block';
    summaryAlert.classList.add('pending');
    summaryAlertTitle.textContent = 'Draft Not Yet Checked';
    summaryAlertBody.textContent = 'Your summary is saved as a draft. Click "Check my Summary" when you are ready.';
    markSummaryBannerSeen(status, feedback);
  }
}

async function loadSummaryFromDb(){
  try{
    const res = await fetch(`/student/api/summary_get.php?cohort_id=${COHORT_ID}&lesson_id=${LESSON_ID}`, {credentials:'same-origin'});
    const j = await res.json();

if (j.ok) {
  rte.innerHTML = j.summary_html || '';

  currentReviewStatus = j.review_status || 'pending';
  isLocked = Number(j.student_soft_locked || 0) === 1;

  updateDrawerLockState();
  renderSummaryAlert(j);
}
  }catch(e){}
}
	
function updateDrawerLockState() {
  rte.contentEditable = isLocked ? 'false' : 'true';
  rte.style.opacity = isLocked ? '0.7' : '1';
  rte.style.cursor = isLocked ? 'not-allowed' : 'text';

  btnUnlockSummary.style.display = isLocked ? 'inline-block' : 'none';
  btnCheckSummary.style.display = isLocked ? 'none' : 'inline-block';

  document.querySelectorAll('.drawer .tools .btnx').forEach(function(btn){
    const isUnlockButton = btn.id === 'btnUnlockSummary';
    const isCloseButton = btn.id === 'btnCloseDrawer';

    if (isLocked && !isUnlockButton && !isCloseButton) {
      btn.classList.add('locked-disabled');
    } else {
      btn.classList.remove('locked-disabled');
    }
  });

  if (isLocked) {
    sumStatus.textContent = 'Locked (Accepted)';
  } else if (currentReviewStatus === 'needs_revision' || currentReviewStatus === 'rejected') {
    sumStatus.textContent = 'Needs revision';
  } else if (currentReviewStatus === 'acceptable') {
    sumStatus.textContent = 'Accepted';
  } else {
    sumStatus.textContent = 'Draft';
  }
}	

async function refreshSummaryStatusOnly(){
  try{
    const res = await fetch(`/student/api/summary_get.php?cohort_id=${COHORT_ID}&lesson_id=${LESSON_ID}`, {credentials:'same-origin'});
    const j = await res.json();
    if (j.ok) {
	  currentReviewStatus = j.review_status || 'pending';
	  isLocked = Number(j.student_soft_locked || 0) === 1;
	  updateDrawerLockState();
	  renderSummaryAlert(j);
	}
  }catch(e){}
}

	
let savedSelection = null;

function saveSelection() {
  const sel = window.getSelection();
  if (!sel || sel.rangeCount === 0) return;

  const range = sel.getRangeAt(0);

  if (!rte.contains(range.startContainer)) return;

  savedSelection = {
    startContainer: range.startContainer,
    startOffset: range.startOffset,
    endContainer: range.endContainer,
    endOffset: range.endOffset
  };
}

function restoreSelection() {
  if (!savedSelection) return;

  try {
    const range = document.createRange();
    range.setStart(savedSelection.startContainer, savedSelection.startOffset);
    range.setEnd(savedSelection.endContainer, savedSelection.endOffset);

    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
  } catch (e) {
    // silently fail if DOM changed
  }
}	
	
function scheduleSave(){
	
  saveSelection();   
	
  if (saveTimer) clearTimeout(saveTimer);
  sumStatus.textContent = 'Saving draft...';
  saveTimer = setTimeout(async ()=>{
    try{
      const res = await fetch('/student/api/summary_save.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        credentials:'same-origin',
        body: JSON.stringify({
          action: 'save',
          cohort_id: COHORT_ID,
          lesson_id: LESSON_ID,
          summary_html: rte.innerHTML || ''
        })
      });
      const j = await res.json();
      sumStatus.textContent = j.ok ? (j.skipped ? 'Draft unchanged' : 'Draft saved') : 'Save failed';
      if (j.ok) {
          restoreSelection();
		  refreshSummaryStatusOnly();
      }
    }catch(e){
      sumStatus.textContent = 'Save failed';
    }
  }, 800);
}

async function checkSummaryNow(){
  try{
    sumStatus.textContent = 'Checking summary...';

    const res = await fetch('/student/api/summary_save.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      credentials:'same-origin',
      body: JSON.stringify({
        action: 'check',
        cohort_id: COHORT_ID,
        lesson_id: LESSON_ID
      })
    });

    const j = await res.json();

    if (!j.ok) {
  sumStatus.textContent = 'Check failed';
  return;
}

		currentReviewStatus = j.review_status || 'pending';
		isLocked = Number(j.student_soft_locked || 0) === 1;

		updateDrawerLockState();
		renderSummaryAlert(j, { forceShow: true });

  } catch(e){
    sumStatus.textContent = 'Check failed';
  }
}

rte.addEventListener('input', ()=>{
  if (isLocked) return;
  scheduleSave();
});

document.querySelectorAll('.drawer .tools button[data-cmd]').forEach(btn=>{
btn.addEventListener('click', ()=>{
  if (isLocked) {
  showBanner('Summary is locked. Unlock to edit.', 'warn');
  return;
}

  const cmd = btn.getAttribute('data-cmd');
  document.execCommand(cmd, false, null);
  rte.focus();
  scheduleSave();
});
});

	
document.querySelectorAll('[data-size]').forEach(function(btn){
  btn.addEventListener('mousedown', function(e){
    e.preventDefault();
  });

  btn.addEventListener('click', function(){
    if (isLocked) {
      showBanner('Summary is locked. Unlock to edit.', 'warn');
      return;
    }

    const size = btn.getAttribute('data-size');
    const sel = window.getSelection();

    const hasSelection =
      sel &&
      sel.rangeCount > 0 &&
      !sel.isCollapsed &&
      rte.contains(sel.anchorNode) &&
      rte.contains(sel.focusNode);

    if (hasSelection) {
      const range = sel.getRangeAt(0);
      const wrapper = document.createElement('span');
      wrapper.className = 'size-' + size;

      try {
        range.surroundContents(wrapper);
      } catch (e) {
        const fragment = range.extractContents();
        wrapper.appendChild(fragment);
        range.insertNode(wrapper);
      }

      sel.removeAllRanges();
      const newRange = document.createRange();
      newRange.selectNodeContents(wrapper);
      sel.addRange(newRange);
    } else {
      rte.classList.remove('size-sm', 'size-md', 'size-lg');
      rte.classList.add('size-' + size);
    }

    scheduleSave();
  });
});
// HIGHLIGHT
document.getElementById('btnHighlight').onclick = ()=>{
  if (isLocked) {
    showBanner('Summary is locked. Unlock to edit.', 'warn');
    return;
  }

  document.execCommand('hiliteColor', false, '#fff59d');
  scheduleSave();
};	
	
function summaryDrawerStateKey(){
  return 'ipca_summary_drawer_state|cohort:' + String(COHORT_ID) + '|lesson:' + String(LESSON_ID);
}

function summaryDrawerSizeStateKey(){
  return 'ipca_summary_drawer_size|cohort:' + String(COHORT_ID) + '|lesson:' + String(LESSON_ID);
}

function saveSummaryDrawerExpandedState(isExpanded){
  try {
    sessionStorage.setItem(summaryDrawerSizeStateKey(), isExpanded ? 'expanded' : 'compact');
  } catch(e){}
}

function loadSummaryDrawerExpandedState(){
  try {
    return sessionStorage.getItem(summaryDrawerSizeStateKey()) === 'expanded';
  } catch(e){
    return false;
  }
}

function applySummaryDrawerExpandedState(){
  const expanded = loadSummaryDrawerExpandedState();
  drawer.classList.toggle('expanded', expanded);
  if (btnToggleSummarySize) {
    btnToggleSummarySize.textContent = expanded ? 'Compact' : 'Expand';
  }
}
	
function saveSummaryDrawerState(isOpen){
  try {
    sessionStorage.setItem(summaryDrawerStateKey(), isOpen ? 'open' : 'closed');
  } catch(e){}
}

function loadSummaryDrawerState(){
  try {
    return sessionStorage.getItem(summaryDrawerStateKey()) === 'open';
  } catch(e){
    return false;
  }
}

async function flushSummarySaveNow(){
  if (isLocked) {
    return;
  }

  if (saveTimer) {
    clearTimeout(saveTimer);
    saveTimer = null;
  }

  try{
    const res = await fetch('/student/api/summary_save.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      credentials:'same-origin',
      body: JSON.stringify({
        action: 'save',
        cohort_id: COHORT_ID,
        lesson_id: LESSON_ID,
        summary_html: rte.innerHTML || ''
      })
    });

    const j = await res.json();
    sumStatus.textContent = (j && j.ok)
      ? (j.skipped ? 'Draft unchanged' : 'Draft saved')
      : 'Save failed';
  } catch(e){
    sumStatus.textContent = 'Save failed';
  }
}	
	
document.getElementById('btnSummary').onclick = ()=>{
  const willOpen = drawer.style.display !== 'flex';
  drawer.style.display = willOpen ? 'flex' : 'none';

  if (willOpen) {
    applySummaryDrawerExpandedState();
  }

  saveSummaryDrawerState(willOpen);
  saveSummaryUiState();

  if (willOpen && !isLocked) {
    setTimeout(function(){
      rte.focus();
    }, 80);
  }
};

document.getElementById('btnCloseDrawer').onclick = ()=>{
  drawer.style.display = 'none';
  saveSummaryDrawerState(false);
  saveSummaryUiState();
};
	
	
btnCheckSummary.onclick = ()=> checkSummaryNow();

btnToggleSummarySize.onclick = ()=>{
  const willExpand = !drawer.classList.contains('expanded');
  drawer.classList.toggle('expanded', willExpand);
  saveSummaryDrawerExpandedState(willExpand);
  btnToggleSummarySize.textContent = willExpand ? 'Compact' : 'Expand';

  saveSummaryUiState();

  if (drawer.style.display === 'flex' && !isLocked) {
    setTimeout(function(){
      rte.focus();
    }, 80);
  }
};	
	
btnUnlockSummary.onclick = async ()=>{
  const ok = confirm('Unlock summary for editing? You will need to check again.');
  if (!ok) return;

  const res = await fetch('/student/api/summary_save.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    credentials:'same-origin',
    body: JSON.stringify({
      action:'unlock',
      cohort_id: COHORT_ID,
      lesson_id: LESSON_ID
    })
  });

  const j = await res.json();

if (j.ok) {
  isLocked = false;
  currentReviewStatus = j.review_status || 'pending';
  updateDrawerLockState();
  renderSummaryAlert({
    review_status: currentReviewStatus,
    review_feedback: '',
    review_notes_by_instructor: ''
  }, { suppressPending: true });
  sumStatus.textContent = 'Unlocked';
}
};	
	
	
const initialSummaryUiState = loadSummaryUiState();

if (initialSummaryUiState && initialSummaryUiState.drawerOpen) {
  drawer.style.display = 'flex';
}

applySummaryDrawerExpandedState();

loadSummaryFromDb().then(()=>{
  restoreSummaryUiState();
  applySummaryDrawerExpandedState();
});

window.addEventListener('beforeunload', ()=>{
  saveSummaryUiState();
});
	
	
function escapeHtml(s){
  return (s||'').toString()
    .replaceAll('&','&amp;').replaceAll('<','&lt;')
    .replaceAll('>','&gt;').replaceAll('"','&quot;');
}

function isEditableTarget(el){
  if (!el) return false;
  const tag = (el.tagName || '').toUpperCase();

  if (el.isContentEditable) return true;
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
  if (typeof el.closest === 'function' && el.closest('#rte')) return true;

  return false;
}

	
	
rte.addEventListener('keydown', function(e){
  if (isLocked) return;

  const isMod = e.metaKey || e.ctrlKey;
  const key = String(e.key || '').toLowerCase();

  if (isMod && key === 'b') {
    e.preventDefault();
    document.execCommand('bold', false, null);
    scheduleSave();
    return;
  }

  if (isMod && key === 'i') {
    e.preventDefault();
    document.execCommand('italic', false, null);
    scheduleSave();
    return;
  }

  if (isMod && key === 'u') {
    e.preventDefault();
    document.execCommand('underline', false, null);
    scheduleSave();
    return;
  }

  if (e.key === 'Tab') {
    e.preventDefault();

    try {
      if (e.shiftKey) {
        document.execCommand('outdent', false, null);
      } else {
        document.execCommand('indent', false, null);
      }
    } catch(err) {}

    scheduleSave();
  }
});	
	
	
document.addEventListener('keydown', (e)=>{
  if (isEditableTarget(e.target)) {
    return;
  }


if (e.key === 'ArrowLeft') {
  if (PREV_ID > 0) {
    (async function(){
      if (drawer.style.display === 'flex') {
        saveSummaryDrawerState(true);
        await flushSummarySaveNow();
      } else {
        saveSummaryDrawerState(false);
      }
      armAutoplay();
      location.href = '/player/slide.php?slide_id=' + PREV_ID;
    })();
  }
}

if (e.key === 'ArrowRight') {
  if (NEXT_ID > 0) {
    (async function(){
      if (drawer.style.display === 'flex') {
        saveSummaryDrawerState(true);
        await flushSummarySaveNow();
      } else {
        saveSummaryDrawerState(false);
      }
      armAutoplay();
      location.href = '/player/slide.php?slide_id=' + NEXT_ID;
    })();
  }
}	
	
	
	
	
});
</script>
</body>
</html>