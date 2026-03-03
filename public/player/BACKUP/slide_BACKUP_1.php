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
  SELECT s.*, l.id AS lesson_id, l.external_lesson_id, c.title AS course_title, p.program_key
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
$pageNum  = (int)$slide['page_number'];
$imgUrl   = cdn_url($CDN_BASE, (string)$slide['image_path']);

$HEADER = "/assets/overlay/header.png"; // 1600x125
$FOOTER = "/assets/overlay/footer.png"; // 1600x90

// Hotspots (video boxes)
$hs = $pdo->prepare("SELECT id, label, src, x,y,w,h FROM slide_hotspots WHERE slide_id=? AND is_deleted=0 ORDER BY id ASC");
$hs->execute([$slideId]);
$hotspots = $hs->fetchAll(PDO::FETCH_ASSOC);

// Content EN/ES
$en = $pdo->prepare("SELECT plain_text FROM slide_content WHERE slide_id=? AND lang='en' LIMIT 1");
$en->execute([$slideId]);
$enText = (string)($en->fetchColumn() ?: '');

$es = $pdo->prepare("SELECT plain_text FROM slide_content WHERE slide_id=? AND lang='es' LIMIT 1");
$es->execute([$slideId]);
$esText = (string)($es->fetchColumn() ?: '');

// Narration EN/ES (optional)
$narr = $pdo->prepare("SELECT narration_en, narration_es FROM slide_enrichment WHERE slide_id=? LIMIT 1");
$narr->execute([$slideId]);
$narrRow = $narr->fetch(PDO::FETCH_ASSOC) ?: [];
$narrEn = (string)($narrRow['narration_en'] ?? '');
$narrEs = (string)($narrRow['narration_es'] ?? '');

// References (PHAK/ACS/FAR_AIM if present in your DB)
$refsStmt = $pdo->prepare("
  SELECT ref_type, ref_code, ref_title, confidence, notes
  FROM slide_references
  WHERE slide_id=?
  ORDER BY ref_type, id
");
$refsStmt->execute([$slideId]);
$refs = $refsStmt->fetchAll(PDO::FETCH_ASSOC);

// Prev/Next within lesson, skip deleted
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

    /* Slide viewport */
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
    }
    .header-img{
      position:absolute; left:0; top:0;
      width:1600px; height:125px;
      object-fit:cover;
      pointer-events:none;
    }
    .footer-img{
      position:absolute; left:0; bottom:0;
      width:1600px; height:90px;
      object-fit:cover;
      pointer-events:none;
    }

    .hotspot{
      position:absolute;
      border:2px solid rgba(0,255,255,0.85);
      border-radius:10px;
      background: rgba(0,255,255,0.08);
      cursor:pointer;
    }
    .hotspot .tag{
      position:absolute; left:8px; top:8px;
      font-size:14px; padding:4px 8px;
      border-radius:10px;
      background: rgba(0,0,0,0.55);
      color:#fff;
    }

    /* Floating buttons */
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
    .fab:hover{ filter: brightness(1.05); }

    /* Modals */
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

    /* Video modal */
    .vbox{
      width:min(960px, 92vw);
      background:#0b1220;
      border:1px solid rgba(255,255,255,0.12);
      border-radius:16px;
      overflow:hidden;
    }
    .vbox video{ width:100%; height:auto; display:block; }

    /* Summary drawer */
    .drawer{
      position: fixed;
      right: 14px;
      bottom: 80px;
      width: min(520px, 92vw);
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
    .drawer textarea{
      border:none;
      outline:none;
      resize:none;
      width:100%;
      height:100%;
      padding:12px;
      font-family: Manrope, Arial, sans-serif;
      font-size: 14px;
      line-height: 1.35;
    }
    .muted{ opacity:.7; font-size:12px; }
  </style>
</head>
<body>

  <div class="topbar2">
    <button class="btnx" onclick="location.href='/student/dashboard.php'">← Back</button>

    <button class="btnx" <?= $prevId ? '' : 'disabled' ?>
      onclick="location.href='/player/slide.php?slide_id=<?= (int)$prevId ?>'">⬅ Prev</button>

    <button class="btnx" <?= $nextId ? '' : 'disabled' ?>
      onclick="location.href='/player/slide.php?slide_id=<?= (int)$nextId ?>'">Next ➜</button>

    <button class="btnx" id="btnLangEN">EN</button>
    <button class="btnx" id="btnLangES">ES</button>

    <button class="btnx" id="btnAudioPlay">▶︎ Audio</button>
    <button class="btnx" id="btnAudioPause">⏸︎</button>
    <button class="btnx" id="btnAudioRew">↺</button>

    <button class="btnx" id="btnRefs">FAA refs</button>
    <button class="btnx" id="btnTxtES" style="display:none;">Spanish text</button>

    <div class="meta">
      <span class="tiny"><?= h($slide['program_key']) ?> • <?= h($slide['course_title']) ?> • Lesson <?= (int)$slide['external_lesson_id'] ?> • Page <?= (int)$pageNum ?></span>
      <span class="tiny" id="clock">--:--</span>
    </div>
  </div>

  <div class="wrap">
    <div class="viewport" id="viewport">
      <div class="stage" id="stage">
        <img class="content-img" src="<?= htmlspecialchars($imgUrl) ?>" alt="">
        <img class="header-img" src="<?= htmlspecialchars($HEADER) ?>" alt="">
        <img class="footer-img" src="<?= htmlspecialchars($FOOTER) ?>" alt="">

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

  <!-- Summary floating button -->
  <button class="fab" id="btnSummary" title="My Study Summary">📝</button>

  <!-- Summary drawer -->
  <div class="drawer" id="drawer">
    <div class="head">
      <strong>My Study Summary</strong>
      <span class="muted" id="sumStatus">Saved</span>
      <button class="btnx" id="btnCloseDrawer" style="padding:6px 10px;">Close</button>
    </div>
    <textarea id="taSummary" placeholder="Write your summary in your own words while studying…"></textarea>
  </div>

  <!-- Refs modal -->
  <div class="modal" id="modalRefs">
    <div class="box">
      <h3>FAA References</h3>
      <div class="muted" style="margin-bottom:10px;">PHAK / ACS (and others if present)</div>
      <div id="refsBody"></div>
      <div style="margin-top:12px; text-align:right;">
        <button class="btnx" id="btnCloseRefs">Close</button>
      </div>
    </div>
  </div>

  <!-- Spanish text modal -->
  <div class="modal" id="modalES">
    <div class="box">
      <h3>Spanish Translation</h3>
      <pre id="esBody"></pre>
      <div style="margin-top:12px; text-align:right;">
        <button class="btnx" id="btnCloseES">Close</button>
      </div>
    </div>
  </div>

  <!-- Video modal -->
  <div class="modal" id="modalVid">
    <div class="vbox">
      <video id="vid" controls playsinline></video>
    </div>
  </div>

<script>
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

// Clock
function tickClock(){
  const d = new Date();
  const hh = String(d.getHours()).padStart(2,'0');
  const mm = String(d.getMinutes()).padStart(2,'0');
  document.getElementById('clock').textContent = hh + ':' + mm;
}
tickClock(); setInterval(tickClock, 10000);

// Language preference
const PREF_KEY = 'ipca_lang_pref';
let lang = localStorage.getItem(PREF_KEY) || 'en';

const btnLangEN = document.getElementById('btnLangEN');
const btnLangES = document.getElementById('btnLangES');
const btnTxtES  = document.getElementById('btnTxtES');

const EN_TEXT = <?= json_encode($enText) ?>;
const ES_TEXT = <?= json_encode($esText) ?>;
const NARR_EN = <?= json_encode($narrEn) ?>;
const NARR_ES = <?= json_encode($narrEs) ?>;

function applyLangUI(){
  btnLangEN.classList.toggle('on', lang==='en');
  btnLangES.classList.toggle('on', lang==='es');
  btnTxtES.style.display = (lang==='es') ? 'inline-block' : 'none';
}
function setLang(newLang){
  lang = newLang;
  localStorage.setItem(PREF_KEY, lang);
  applyLangUI();
}
btnLangEN.onclick = ()=>setLang('en');
btnLangES.onclick = ()=>setLang('es');
applyLangUI();

// ---- Audio (MVP using SpeechSynthesis) ----
let utter = null;
let speaking = false;

function getNarrationText(){
  if (lang === 'es') return (NARR_ES || ES_TEXT || '');
  return (NARR_EN || EN_TEXT || '');
}

function pickVoiceForLang(targetLang){
  const voices = window.speechSynthesis ? speechSynthesis.getVoices() : [];
  if (!voices || voices.length===0) return null;

  // Prefer language match
  const want = (targetLang === 'es') ? 'es' : 'en';
  let v = voices.find(x => (x.lang||'').toLowerCase().startsWith(want) && /google|natural|premium|enhanced/i.test(x.name||'')) ||
          voices.find(x => (x.lang||'').toLowerCase().startsWith(want)) ||
          null;
  return v;
}

function speakFromStart(){
  if (!window.speechSynthesis) { alert('Audio not supported in this browser.'); return; }
  const text = getNarrationText().trim();
  if (!text) { alert('No narration text yet for this slide.'); return; }

  speechSynthesis.cancel();
  utter = new SpeechSynthesisUtterance(text);
  utter.rate = 1.0;
  utter.pitch = 1.0;

  const v = pickVoiceForLang(lang);
  if (v) utter.voice = v;

  utter.onend = ()=>{ speaking=false; };
  utter.onerror = ()=>{ speaking=false; };

  speaking = true;
  speechSynthesis.speak(utter);
}

document.getElementById('btnAudioPlay').onclick = ()=>{
  if (!window.speechSynthesis) return alert('Audio not supported.');
  const text = getNarrationText().trim();
  if (!text) return alert('No narration text yet.');
  // If paused, resume; else start
  if (speechSynthesis.paused) {
    speechSynthesis.resume();
    return;
  }
  speakFromStart();
};

document.getElementById('btnAudioPause').onclick = ()=>{
  if (!window.speechSynthesis) return;
  if (speechSynthesis.speaking && !speechSynthesis.paused) speechSynthesis.pause();
};

document.getElementById('btnAudioRew').onclick = ()=>{
  if (!window.speechSynthesis) return;
  speakFromStart();
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

// ---- FAA refs modal ----
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

// ---- Spanish text modal (only useful when ES selected) ----
const modalES = document.getElementById('modalES');
document.getElementById('esBody').textContent = ES_TEXT || '(No Spanish text yet)';
btnTxtES.onclick = ()=>{
  document.getElementById('esBody').textContent = ES_TEXT || '(No Spanish text yet)';
  modalES.style.display='flex';
};
document.getElementById('btnCloseES').onclick = ()=> modalES.style.display='none';
modalES.addEventListener('click', (e)=>{ if(e.target===modalES) modalES.style.display='none'; });

// ---- Summary drawer (autosave localStorage MVP) ----
const SUM_KEY = 'ipca_summary_slide_' + <?= (int)$slideId ?>;
const drawer = document.getElementById('drawer');
const taSummary = document.getElementById('taSummary');
const sumStatus = document.getElementById('sumStatus');

function loadSummary(){
  const v = localStorage.getItem(SUM_KEY) || '';
  taSummary.value = v;
}
let saveTimer = null;
function markSaving(){ sumStatus.textContent = 'Saving…'; }
function markSaved(){ sumStatus.textContent = 'Saved'; }

function scheduleSave(){
  if (saveTimer) clearTimeout(saveTimer);
  markSaving();
  saveTimer = setTimeout(()=>{
    localStorage.setItem(SUM_KEY, taSummary.value || '');
    markSaved();
  }, 500);
}
taSummary.addEventListener('input', scheduleSave);

document.getElementById('btnSummary').onclick = ()=>{
  drawer.style.display = (drawer.style.display==='flex') ? 'none' : 'flex';
  if (drawer.style.display==='flex') setTimeout(()=>taSummary.focus(), 80);
};
document.getElementById('btnCloseDrawer').onclick = ()=> drawer.style.display='none';
loadSummary();

// utilities
function escapeHtml(s){
  return (s||'').toString()
    .replaceAll('&','&amp;').replaceAll('<','&lt;')
    .replaceAll('>','&gt;').replaceAll('"','&quot;');
}

// Keyboard navigation
document.addEventListener('keydown', (e)=>{
  if (e.key === 'ArrowLeft') {
    <?php if ($prevId): ?> location.href='/player/slide.php?slide_id=<?= (int)$prevId ?>'; <?php endif; ?>
  }
  if (e.key === 'ArrowRight') {
    <?php if ($nextId): ?> location.href='/player/slide.php?slide_id=<?= (int)$nextId ?>'; <?php endif; ?>
  }
});
</script>
</body>
</html>