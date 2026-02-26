<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$slideId = (int)($_GET['slide_id'] ?? 0);
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

// Fixed overlays (your provided dims)
$HEADER = "/assets/overlay/header.png"; // 1600x125
$FOOTER = "/assets/overlay/footer.png"; // 1600x90

cw_header('Overlay Slide Editor');
?>
<style>
  .editor-wrap{ display:grid; grid-template-columns: 1fr 420px; gap:14px; }

  /* Viewport scales the whole 1600x900 stage, and clips it */
  .viewport{
    width: 100%;
    aspect-ratio: 16 / 9;
    overflow: hidden;
    border:1px solid #e6e6e6;
    border-radius: 14px;
    background:#ffffff; /* ✅ was #111 */
    position:relative;
  }
  .stage{
    width:1600px; height:900px;
    transform-origin: top left;
    position:absolute; left:0; top:0;
    background:#ffffff; /* ✅ ensure behind everything is white */
  }
  .layer{ position:absolute; inset:0; }

  /* Screenshot centered at exact size (no warping) */
  .content-img{
    position:absolute;
    width:1315px;
    height:900px;
    left: calc((1600px - 1315px)/2);
    top: 0;
    object-fit: contain; /* no stretching */
    background: #ffffff; /* ✅ was transparent */
  }

  /* Overlays (sit on top of screenshot) */
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

  /* Hotspots */
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

  textarea{ width:100%; min-height:120px; }
  .small{ font-size: 12px; opacity: .75; }
  .row{ display:flex; gap:8px; align-items:center; }
  .row input[type="text"]{ width: 100%; }
  code{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
</style>

<div class="card">
  <div class="muted" style="margin-bottom:10px;">
    <?= h($slide['program_key']) ?> • <?= h($slide['course_title']) ?> • Lesson <?= (int)$slide['external_lesson_id'] ?> • Page <?= (int)$slide['page_number'] ?> • Slide ID <?= (int)$slideId ?>
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
        <h2 style="margin:0 0 8px 0;">Canonical content</h2>
        <div class="row" style="margin-bottom:8px;">
          <button class="btn" id="btnExtractEN" type="button">AI Extract (EN)</button>
          <button class="btn btn-sm" id="btnExtractES" type="button">AI Translate (ES)</button>
        </div>

        <label class="small muted">English (editable)</label>
        <textarea id="taEN" placeholder="AI extracted content will appear here…"></textarea>

        <label class="small muted" style="margin-top:10px;">Spanish (editable)</label>
        <textarea id="taES" placeholder="AI translated content will appear here…"></textarea>

        <div class="row" style="margin-top:10px;">
          <button class="btn" id="btnSaveContent" type="button">Save content</button>
          <a class="btn btn-sm" target="_blank" href="/player/slide.php?slide_id=<?= (int)$slideId ?>">Open student view</a>
        </div>

        <div class="small muted" id="status" style="margin-top:10px;"></div>
      </div>
    </div>
  </div>
</div>

<script>
const SLIDE_ID = <?= (int)$slideId ?>;
const hotspotLayer = document.getElementById('hotspotLayer');
const hotspotList = document.getElementById('hotspotList');
const suggestedBox = document.getElementById('suggestedBox');
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
      }
      if (rsz) {
        const dx = (ev.clientX - rsz.sx) / scale;
        const dy = (ev.clientY - rsz.sy) / scale;
        h.w = Math.max(30, Math.round(rsz.ow + dx));
        h.h = Math.max(30, Math.round(rsz.oh + dy));
        d.style.width = h.w + 'px';
        d.style.height = h.h + 'px';
        renderHotspotList();
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
        <input type="text" placeholder="ks_videos/private/lesson_10002/AN00001_vA.mp4" value="${escapeHtml(h.src||'')}" data-k="src" data-id="${h.id}">
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
  await loadHotspots();
});

// Draw new hotspot by drag
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

// Content load/save
async function loadContent(){
  const res = await fetch('/admin/api/ai_extract_content.php?slide_id='+SLIDE_ID+'&mode=get');
  const j = await res.json();
  if (!j.ok) return;
  document.getElementById('taEN').value = j.en_plain || '';
  document.getElementById('taES').value = j.es_plain || '';
}

document.getElementById('btnExtractEN').addEventListener('click', async ()=>{
  setStatus('AI extracting EN…');
  const res = await fetch('/admin/api/ai_extract_content.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ slide_id: SLIDE_ID, lang:'en', action:'extract' })
  });
  const j = await res.json();
  if (!j.ok) { setStatus('AI EN failed: ' + (j.error||'')); return; }
  document.getElementById('taEN').value = j.plain_text || '';
  setStatus('EN extracted.');
});

document.getElementById('btnExtractES').addEventListener('click', async ()=>{
  setStatus('AI translating to ES…');
  const res = await fetch('/admin/api/ai_extract_content.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ slide_id: SLIDE_ID, lang:'es', action:'translate' })
  });
  const j = await res.json();
  if (!j.ok) { setStatus('AI ES failed: ' + (j.error||'')); return; }
  document.getElementById('taES').value = j.plain_text || '';
  setStatus('ES saved.');
});

document.getElementById('btnSaveContent').addEventListener('click', async ()=>{
  setStatus('Saving content…');
  const en = document.getElementById('taEN').value;
  const es = document.getElementById('taES').value;

  const res = await fetch('/admin/api/ai_extract_content.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ slide_id: SLIDE_ID, action:'save_manual', en_plain: en, es_plain: es })
  });
  const j = await res.json();
  if (!j.ok) { setStatus('Save failed: ' + (j.error||'')); return; }
  setStatus('Content saved.');
});

loadHotspots();
loadContent();
</script>
<?php cw_footer(); ?>