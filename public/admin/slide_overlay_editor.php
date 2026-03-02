<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$slideId  = (int)($_GET['slide_id'] ?? 0);
$courseId = (int)($_GET['course_id'] ?? 0);
$lessonId = (int)($_GET['lesson_id'] ?? 0);

if ($slideId <= 0) exit('Missing slide_id');

$stmt = $pdo->prepare("
  SELECT s.*, l.external_lesson_id, l.course_id, c.title AS course_title, p.program_key
  FROM slides s
  JOIN lessons l ON l.id=s.lesson_id
  JOIN courses c ON c.id=l.course_id
  JOIN programs p ON p.id=c.program_id
  WHERE s.id=? LIMIT 1
");
$stmt->execute([$slideId]);
$slide = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$slide) exit('Slide not found');

$imgUrl = cdn_url($CDN_BASE, (string)$slide['image_path']);

// If not passed, derive from slide
if ($lessonId <= 0) $lessonId = (int)$slide['lesson_id'];
if ($courseId <= 0) $courseId = (int)$slide['course_id'];

$backUrl = '/admin/slides.php?course_id='.(int)$courseId.'&lesson_id='.(int)$lessonId;

// Prev/Next slide in this lesson (skip deleted slides)
$prevId = 0; $nextId = 0;
$stmt = $pdo->prepare("SELECT id FROM slides WHERE lesson_id=? AND is_deleted=0 AND page_number < ? ORDER BY page_number DESC LIMIT 1");
$stmt->execute([(int)$lessonId, (int)$slide['page_number']]);
$prevId = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT id FROM slides WHERE lesson_id=? AND is_deleted=0 AND page_number > ? ORDER BY page_number ASC LIMIT 1");
$stmt->execute([(int)$lessonId, (int)$slide['page_number']]);
$nextId = (int)$stmt->fetchColumn();

$prevUrl = $prevId ? '/admin/slide_overlay_editor.php?slide_id='.$prevId.'&course_id='.(int)$courseId.'&lesson_id='.(int)$lessonId : '';
$nextUrl = $nextId ? '/admin/slide_overlay_editor.php?slide_id='.$nextId.'&course_id='.(int)$courseId.'&lesson_id='.(int)$lessonId : '';

// Fixed overlays
$HEADER = "/assets/overlay/header.png"; // 1600x125
$FOOTER = "/assets/overlay/footer.png"; // 1600x90

cw_header('Overlay Slide Editor');
?>
<style>
  .editor-wrap{ display:grid; grid-template-columns: 1fr 420px; gap:14px; }

  .viewport{
    width: 100%;
    aspect-ratio: 16 / 9;
    overflow: hidden;
    border:1px solid #e6e6e6;
    border-radius: 14px;
    background:#ffffff;
    position:relative;
  }
  .stage{
    width:1600px; height:900px;
    transform-origin: top left;
    position:absolute; left:0; top:0;
    background:#ffffff;
  }
  .layer{ position:absolute; inset:0; }

  .content-img{
    position:absolute;
    width:1315px;
    height:900px;
    left: calc((1600px - 1315px)/2);
    top: 0;
    object-fit: contain;
    background: #ffffff;
  }

  .header-img{
    position:absolute;
    left:0; top:0;
    width:1600px;
    height:125px;
    object-fit: cover;
    pointer-events:none;
  }
  .footer-img{
    position:absolute;
    left:0; bottom:0;
    width:1600px;
    height:90px;
    object-fit: cover;
    pointer-events:none;
  }

  .hotspot{
    position:absolute;
    border: 2px dashed rgba(255,255,255,0.85);
    border-radius: 10px;
    background: rgba(0,0,0,0.10);
    box-sizing:border-box;
    cursor: move;
  }
  .hotspot .tag{
    position:absolute;
    left:8px; top:8px;
    font-size: 14px;
    padding: 4px 8px;
    border-radius: 10px;
    background: rgba(0,0,0,0.65);
    color:#fff;
  }
  .hotspot .resize{
    position:absolute;
    right:-6px; bottom:-6px;
    width:14px; height:14px;
    background: rgba(0,255,255,0.9);
    border-radius: 4px;
    cursor: nwse-resize;
  }

  .draw-rect{
    position:absolute;
    border:2px solid rgba(0,255,255,0.9);
    border-radius:10px;
    background: rgba(0,255,255,0.08);
    pointer-events:none;
  }

  textarea{ width:100%; min-height:110px; }
  .small{ font-size: 12px; opacity: .75; }
  .row{ display:flex; gap:8px; align-items:center; }
  .row input[type="text"]{ width: 100%; }
  code{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }

  .pill{
    display:inline-block;
    padding:3px 8px;
    border-radius:999px;
    border:1px solid #ddd;
    background:#f7f7f7;
    font-size:12px;
    margin: 2px 6px 2px 0;
  }
  .refs-box{
    border:1px solid #eee;
    border-radius:12px;
    padding:10px;
    background:#fff;
  }
</style>

<div class="card">
  <div class="row" style="justify-content:space-between; margin-bottom:10px;">
    <div class="muted">
      <?= h($slide['program_key']) ?> • <?= h($slide['course_title']) ?> • Lesson <?= (int)$slide['external_lesson_id'] ?> • Page <?= (int)$slide['page_number'] ?> • Slide ID <?= (int)$slideId ?>
    </div>
    <div class="row">
      <?php if ($prevUrl): ?>
        <a class="btn btn-sm navlink" href="<?= h($prevUrl) ?>">← Prev</a>
      <?php endif; ?>
      <?php if ($nextUrl): ?>
        <a class="btn btn-sm navlink" href="<?= h($nextUrl) ?>">Next →</a>
      <?php endif; ?>
      <a class="btn btn-sm navlink" href="<?= h($backUrl) ?>">← Back to Slides</a>
      <a class="btn btn-sm" target="_blank" href="/player/slide.php?slide_id=<?= (int)$slideId ?>">Student View</a>
    </div>
  </div>

  <div class="editor-wrap">
    <div>
      <div class="viewport" id="viewport">
        <div class="stage" id="stage">
          <div class="layer">
            <img class="content-img" id="contentImg" src="<?= h($imgUrl) ?>" alt="">
            <img class="header-img" src="<?= h($HEADER) ?>" alt="">
            <img class="footer-img" src="<?= h($FOOTER) ?>" alt="">
          </div>
          <div class="layer" id="hotspotLayer"></div>
        </div>
      </div>

      <div class="muted small" style="margin-top:8px;">
        Draw hotspot: click+drag on the slide. Resize using the cyan corner.
        (All coordinates are saved in 1600×900 space.)
      </div>
    </div>

    <div>
      <div class="card" style="margin-bottom:12px;">
        <h2 style="margin:0 0 8px 0;">Hotspots</h2>
        <div class="small muted" id="suggestedBox"></div>
        <div id="hotspotList" class="small muted">Loading…</div>
        <div class="row" style="margin-top:10px;">
          <button class="btn" id="btnSaveHotspots" type="button">Save hotspots</button>
          <button class="btn btn-sm" id="btnReloadHotspots" type="button">Reload</button>
        </div>
      </div>

      <div class="card">
        <h2 style="margin:0 0 8px 0;">Canonical data</h2>

        <div class="row" style="margin-bottom:8px;">
          <button class="btn" id="btnExtractEN" type="button">AI Extract (EN)</button>
          <button class="btn btn-sm" id="btnExtractES" type="button">AI Translate (ES)</button>
        </div>

        <label class="small muted">English (editable)</label>
        <textarea id="taEN" placeholder="English extracted content…"></textarea>

        <label class="small muted" style="margin-top:10px;">Spanish (editable)</label>
        <textarea id="taES" placeholder="Spanish translation…"></textarea>

        <label class="small muted" style="margin-top:10px;">Narration script (EN)</label>
        <textarea id="taNarrEN" placeholder="Narration script in English…"></textarea>

        <label class="small muted" style="margin-top:10px;">Narration script (ES)</label>
        <textarea id="taNarrES" placeholder="Narration script in Spanish…"></textarea>

        <div class="refs-box" style="margin-top:10px;">
          <div class="small muted" style="margin-bottom:6px;">PHAK references</div>
          <div id="phakRefs" class="small muted">Loading…</div>
          <div class="small muted" style="margin-top:10px;margin-bottom:6px;">ACS references</div>
          <div id="acsRefs" class="small muted">Loading…</div>
        </div>

        <div class="row" style="margin-top:10px;">
          <button class="btn" id="btnSaveCanonical" type="button">Save canonical data</button>
          <button class="btn btn-sm" id="btnReloadCanonical" type="button">Reload</button>
        </div>

        <div class="small muted" id="status" style="margin-top:10px;"></div>
      </div>
    </div>
  </div>
</div>

<script>
const SLIDE_ID = <?= (int)$slideId ?>;
const PROGRAM_KEY = <?= json_encode((string)$slide['program_key']) ?>;

const hotspotLayer = document.getElementById('hotspotLayer');
const hotspotList = document.getElementById('hotspotList');
const suggestedBox = document.getElementById('suggestedBox');

const taEN = document.getElementById('taEN');
const taES = document.getElementById('taES');
const taNarrEN = document.getElementById('taNarrEN');
const taNarrES = document.getElementById('taNarrES');
const phakRefsEl = document.getElementById('phakRefs');
const acsRefsEl = document.getElementById('acsRefs');

const statusEl = document.getElementById('status');

const viewport = document.getElementById('viewport');
const stage = document.getElementById('stage');

let scale = 1;
function fitStage(){
  const vw = viewport.clientWidth;
  const vh = viewport.clientHeight;
  scale = Math.min(vw/1600, vh/900);
  stage.style.transform = `scale(${scale})`;
}
window.addEventListener('resize', () => setTimeout(fitStage, 60));
setTimeout(fitStage, 50);

function setStatus(msg){ statusEl.textContent = msg; }

// ------------------------
// Unsaved changes tracking
// ------------------------
let dirty = false;
function markDirty(){ dirty = true; }
function markSaved(){ dirty = false; }

['taEN','taES','taNarrEN','taNarrES'].forEach(id=>{
  const el = document.getElementById(id);
  if (el) el.addEventListener('input', markDirty);
});

window.addEventListener('beforeunload', (e)=>{
  if (!dirty) return;
  e.preventDefault();
  e.returnValue = '';
});

// Intercept navigation links (Prev/Next/Back)
document.querySelectorAll('a.navlink').forEach(a=>{
  a.addEventListener('click', (e)=>{
    if (!dirty) return;
    if (!confirm('You have unsaved changes. Leave without saving?')) {
      e.preventDefault();
    }
  });
});

// ------------------------
// Hotspots
// ------------------------
let hotspots = [];
let suggestedSrc = '';

function escapeHtml(s){
  return (s||'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
}

function renderHotspots(){
  hotspotLayer.innerHTML = '';
  hotspots.filter(h=>!h.is_deleted).forEach(h => {
    const d = document.createElement('div');
    d.className = 'hotspot';
    d.dataset.id = h.id;
    d.style.left = h.x + 'px';
    d.style.top  = h.y + 'px';
    d.style.width  = h.w + 'px';
    d.style.height = h.h + 'px';

    const tag = document.createElement('div');
    tag.className = 'tag';
    tag.textContent = h.label || 'Video';
    d.appendChild(tag);

    const rez = document.createElement('div');
    rez.className = 'resize';
    d.appendChild(rez);

    let drag = null;
    d.addEventListener('mousedown', (ev) => {
      if (ev.target === rez) return;
      ev.preventDefault();
      drag = { sx: ev.clientX, sy: ev.clientY, ox: h.x, oy: h.y };
    });

    let rsz = null;
    rez.addEventListener('mousedown', (ev)=>{
      ev.preventDefault();
      ev.stopPropagation();
      rsz = { sx: ev.clientX, sy: ev.clientY, ow: h.w, oh: h.h };
    });

    window.addEventListener('mousemove', (ev) => {
      if (drag) {
        const dx = (ev.clientX - drag.sx) / scale;
        const dy = (ev.clientY - drag.sy) / scale;
        h.x = Math.round(drag.ox + dx);
        h.y = Math.round(drag.oy + dy);
        d.style.left = h.x + 'px';
        d.style.top  = h.y + 'px';
        renderHotspotList();
        markDirty();
      }
      if (rsz) {
        const dx = (ev.clientX - rsz.sx) / scale;
        const dy = (ev.clientY - rsz.sy) / scale;
        h.w = Math.max(30, Math.round(rsz.ow + dx));
        h.h = Math.max(30, Math.round(rsz.oh + dy));
        d.style.width = h.w + 'px';
        d.style.height = h.h + 'px';
        renderHotspotList();
        markDirty();
      }
    });
    window.addEventListener('mouseup', () => { drag = null; rsz = null; });

    hotspotLayer.appendChild(d);
  });
}

function renderHotspotList(){
  const rows = hotspots.filter(h=>!h.is_deleted).map(h => `
    <div style="border:1px solid #eee; border-radius:12px; padding:10px; margin-bottom:8px;">
      <div class="row">
        <strong style="min-width:54px;">Label</strong>
        <input type="text" value="${escapeHtml(h.label||'')}" data-k="label" data-id="${h.id}">
      </div>
      <div class="row" style="margin-top:6px;">
        <strong style="min-width:54px;">Video</strong>
        <input type="text" placeholder="ks_videos/${escapeHtml(PROGRAM_KEY)}/lesson_10002/page_001__file.mp4" value="${escapeHtml(h.src||'')}" data-k="src" data-id="${h.id}">
      </div>
      <div class="small muted" style="margin-top:6px;">x=${h.x}, y=${h.y}, w=${h.w}, h=${h.h}</div>
      <div class="row" style="margin-top:8px;">
        ${(!h.src && suggestedSrc) ? `<button class="btn btn-sm" type="button" data-use="${h.id}">Use suggested</button>` : ``}
        <button class="btn btn-sm" type="button" data-del="${h.id}">Delete</button>
      </div>
    </div>
  `).join('');
  hotspotList.innerHTML = rows || '<span class="muted">No hotspots yet.</span>';

  hotspotList.querySelectorAll('input[data-k]').forEach(inp => {
    inp.addEventListener('input', () => {
      const id = parseInt(inp.dataset.id,10);
      const k = inp.dataset.k;
      const h = hotspots.find(x=>x.id===id);
      if (!h) return;
      h[k] = inp.value;
      renderHotspots();
      markDirty();
    });
  });

  hotspotList.querySelectorAll('button[data-use]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id = parseInt(btn.dataset.use,10);
      const h = hotspots.find(x=>x.id===id);
      if (!h) return;
      h.src = suggestedSrc;
      renderHotspots();
      renderHotspotList();
      markDirty();
    });
  });

  hotspotList.querySelectorAll('button[data-del]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id = parseInt(btn.dataset.del,10);
      const h = hotspots.find(x=>x.id===id);
      if (!h) return;
      h.is_deleted = 1;
      renderHotspots();
      renderHotspotList();
      markDirty();
    });
  });
}

async function loadHotspots(){
  const res = await fetch('/admin/api/get_hotspots.php?slide_id=' + SLIDE_ID);
  const j = await res.json();
  if (!j.ok) { hotspotList.textContent = 'Failed to load hotspots'; return; }
  hotspots = j.hotspots || [];
  suggestedSrc = j.suggested_src || '';
  suggestedBox.innerHTML = suggestedSrc ? `Suggested video: <code>${escapeHtml(suggestedSrc)}</code>` : `Suggested video: <span class="muted">none</span>`;
  renderHotspots();
  renderHotspotList();
}

document.getElementById('btnReloadHotspots').addEventListener('click', loadHotspots);

document.getElementById('btnSaveHotspots').addEventListener('click', async ()=>{
  setStatus('Saving hotspots…');
  const res = await fetch('/admin/api/save_hotspots.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ slide_id: SLIDE_ID, hotspots })
  });
  const j = await res.json();
  if (!j.ok) { setStatus('Hotspot save failed: ' + (j.error||'')); return; }
  setStatus('Hotspots saved.');
  markSaved();
  await loadHotspots();
});

// draw hotspot
let drawing = null;
let drawEl = null;

stage.addEventListener('mousedown', (ev)=>{
  if (ev.target.closest('.hotspot')) return;

  const rect = stage.getBoundingClientRect();
  const x = (ev.clientX - rect.left) / scale;
  const y = (ev.clientY - rect.top) / scale;

  drawing = { x0: x, y0: y, x1: x, y1: y };

  drawEl = document.createElement('div');
  drawEl.className = 'draw-rect';
  hotspotLayer.appendChild(drawEl);
  updateDrawEl();
});

window.addEventListener('mousemove', (ev)=>{
  if (!drawing) return;
  const rect = stage.getBoundingClientRect();
  drawing.x1 = (ev.clientX - rect.left) / scale;
  drawing.y1 = (ev.clientY - rect.top) / scale;
  updateDrawEl();
});

window.addEventListener('mouseup', ()=>{
  if (!drawing) return;

  const x = Math.round(Math.min(drawing.x0, drawing.x1));
  const y = Math.round(Math.min(drawing.y0, drawing.y1));
  const w = Math.round(Math.abs(drawing.x1 - drawing.x0));
  const h = Math.round(Math.abs(drawing.y1 - drawing.y0));
  drawing = null;

  if (drawEl) drawEl.remove();
  drawEl = null;

  if (w < 30 || h < 30) return;

  const tempId = -Math.floor(Math.random()*1000000);
  const autoSrc = suggestedSrc || '';
  hotspots.push({ id: tempId, kind:'video', label:'Video', src:autoSrc, x,y,w,h, is_deleted:0 });

  renderHotspots();
  renderHotspotList();
  markDirty();
});

function updateDrawEl(){
  if (!drawEl || !drawing) return;
  const x = Math.min(drawing.x0, drawing.x1);
  const y = Math.min(drawing.y0, drawing.y1);
  const w = Math.abs(drawing.x1 - drawing.x0);
  const h = Math.abs(drawing.y1 - drawing.y0);
  drawEl.style.left = x + 'px';
  drawEl.style.top = y + 'px';
  drawEl.style.width = w + 'px';
  drawEl.style.height = h + 'px';
}

// ------------------------
// Canonical loading/saving
// ------------------------
async function loadCanonical(){
  setStatus('Loading canonical…');

  // EN/ES (slide_content)
  const res = await fetch('/admin/api/ai_extract_content.php?slide_id=' + SLIDE_ID);
  const j = await res.json();
  if (j.ok) {
    taEN.value = j.en_plain || '';
    taES.value = j.es_plain || '';
  }

  // Narration + refs (slide_enrichment + slide_references)
  const res2 = await fetch('/admin/api/slide_canonical_get.php?slide_id=' + SLIDE_ID);
  const j2 = await res2.json();
  if (j2.ok) {
    taNarrEN.value = j2.narration_en || '';
    taNarrES.value = j2.narration_es || '';
    renderRefs(phakRefsEl, j2.phak || []);
    renderRefs(acsRefsEl, j2.acs || []);
  } else {
    phakRefsEl.textContent = '—';
    acsRefsEl.textContent = '—';
  }

  setStatus('Ready.');
  markSaved();
}

function renderRefs(container, refs){
  if (!Array.isArray(refs) || refs.length === 0) {
    container.innerHTML = '<span class="muted">—</span>';
    return;
  }
  container.innerHTML = refs.map(r => {
    const code = escapeHtml(r.ref_code || '');
    const title = escapeHtml(r.ref_title || '');
    const conf = (r.confidence !== null && r.confidence !== undefined) ? String(r.confidence) : '';
    return `<span class="pill">${code}${title ? ' — ' + title : ''}${conf ? ' ('+conf+')' : ''}</span>`;
  }).join(' ');
}

document.getElementById('btnReloadCanonical').addEventListener('click', loadCanonical);

document.getElementById('btnSaveCanonical').addEventListener('click', async ()=>{
  setStatus('Saving canonical…');

  // save EN/ES
  const res = await fetch('/admin/api/ai_extract_content.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ slide_id: SLIDE_ID, action:'save_manual', en_plain: taEN.value, es_plain: taES.value })
  });
  const j = await res.json();
  if (!j.ok) { setStatus('Save EN/ES failed: ' + (j.error||'')); return; }

  // save narration EN/ES
  const res2 = await fetch('/admin/api/slide_canonical_save.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ slide_id: SLIDE_ID, narration_en: taNarrEN.value, narration_es: taNarrES.value })
  });
  const j2 = await res2.json();
  if (!j2.ok) { setStatus('Save narration failed: ' + (j2.error||'')); return; }

  setStatus('Saved canonical data.');
  markSaved();
});

// AI buttons (EN extract / ES translate)
document.getElementById('btnExtractEN').addEventListener('click', async ()=>{
  setStatus('AI extracting EN…');
  const res = await fetch('/admin/api/ai_extract_content.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ slide_id: SLIDE_ID, lang:'en', action:'extract' })
  });
  const j = await res.json();
  if (!j.ok) { setStatus('AI EN failed: ' + (j.error||'')); return; }
  taEN.value = j.plain_text || '';
  setStatus('EN extracted.');
  markDirty();
});

document.getElementById('btnExtractES').addEventListener('click', async ()=>{
  setStatus('AI translating ES…');
  const res = await fetch('/admin/api/ai_extract_content.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ slide_id: SLIDE_ID, lang:'es', action:'translate' })
  });
  const j = await res.json();
  if (!j.ok) { setStatus('AI ES failed: ' + (j.error||'')); return; }
  taES.value = j.plain_text || '';
  setStatus('ES translated.');
  markDirty();
});

// init
loadHotspots();
loadCanonical();
</script>

<?php cw_footer(); ?>