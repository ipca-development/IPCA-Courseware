<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$slideId = (int)($_GET['slide_id'] ?? 0);
if ($slideId <= 0) exit('Missing slide_id');

$stmt = $pdo->prepare("
  SELECT s.*, l.external_lesson_id, p.program_key
  FROM slides s
  JOIN lessons l ON l.id=s.lesson_id
  JOIN courses c ON c.id=l.course_id
  JOIN programs p ON p.id=c.program_id
  WHERE s.id=?
");
$stmt->execute([$slideId]);
$slide = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$slide) exit('Slide not found');

$imgUrl = cdn_url($CDN_BASE, (string)$slide['image_path']);
$bgUrl  = "/assets/bg/ipca_bg.jpeg";

cw_header('Slide Designer');
?>
<div class="card">
  <p class="muted" style="margin:0 0 10px 0;">
    Program: <?= h($slide['program_key']) ?> • Lesson <?= (int)$slide['external_lesson_id'] ?> • Page <?= (int)$slide['page_number'] ?> • Slide ID <?= (int)$slideId ?>
  </p>

  <div style="
      position: sticky;
      top: 0;
      z-index: 50;
      background: rgba(255,255,255,0.95);
      backdrop-filter: blur(6px);
      border: 1px solid #eee;
      border-radius: 12px;
      padding: 10px;
      display:flex;
      flex-wrap:wrap;
      gap:10px;
      align-items:center;
    ">
    <strong style="margin-right:6px;">Tools</strong>

    <button class="btn btn-sm" id="btnAiLayout" type="button">AI Auto Layout</button>
    <button class="btn btn-sm" id="btnUndoAi" type="button" disabled>Undo AI</button>

    <span style="width:1px;height:26px;background:#e6e6e6;margin:0 6px;"></span>

    <button class="btn btn-sm" id="btnAddText" type="button">Add Text</button>
    <button class="btn btn-sm" id="btnAddRedact" type="button">Add Redaction</button>
    <button class="btn btn-sm" id="btnAddImageBox" type="button">Add Image Box</button>
    <button class="btn btn-sm" id="btnAddVideoBox" type="button">Add Video Box</button>

    <span style="width:1px;height:26px;background:#e6e6e6;margin:0 6px;"></span>

    <label class="muted" style="display:flex; gap:6px; align-items:center;">
      <input type="checkbox" id="refToggle" checked> Screenshot overlay
    </label>

    <label class="muted" style="display:flex; gap:6px; align-items:center;">
      Opacity
      <input type="range" id="refOpacity" min="0" max="100" value="35">
    </label>

    <span style="width:1px;height:26px;background:#e6e6e6;margin:0 6px;"></span>

    <button class="btn btn-sm" id="btnBringFront" type="button">Bring Front</button>
    <button class="btn btn-sm" id="btnSendBack" type="button">Send Back</button>
    <button class="btn btn-sm" id="btnDelete" type="button">Delete</button>

    <span style="width:1px;height:26px;background:#e6e6e6;margin:0 6px;"></span>

    <button class="btn" id="btnSave" type="button">Save Layout</button>
    <button class="btn btn-sm" id="btnSaveRender" type="button">Save + Render HTML</button>
    <a class="btn btn-sm" href="/admin/slides.php?lesson_id=<?= (int)$slide['lesson_id'] ?>">Back</a>

    <span class="muted" id="selInfo" style="margin-left:auto;">None</span>
  </div>

  <div class="muted" id="status" style="margin-top:10px;"></div>
  <div class="muted" id="zoomInfo" style="margin-top:6px;"></div>

  <div id="canvasWrap"
       style="
         width:100%;
         height: calc(100vh - 260px);
         margin-top: 12px;
         border:1px solid #e6e6e6;
         border-radius:12px;
         overflow:hidden;
         background:#f4f6ff;
         position:relative;
       ">
    <canvas id="c" width="1600" height="900"></canvas>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/fabric@5.3.0/dist/fabric.min.js"></script>
<script>
const SLIDE_ID = <?= (int)$slideId ?>;
const BG_URL   = <?= json_encode($bgUrl) ?>;
const REF_URL  = <?= json_encode($imgUrl) ?>;

const statusEl = document.getElementById('status');
const selInfo  = document.getElementById('selInfo');
const zoomInfo = document.getElementById('zoomInfo');

function setStatus(msg){ statusEl.textContent = msg; }

const BASE_W = 1600;
const BASE_H = 900;

const canvas = new fabric.Canvas('c', {
  selection: true,
  preserveObjectStacking: true
});

let refImage = null;
let undoAiJson = null;

function applyBackground(){
  fabric.Image.fromURL(BG_URL, (img) => {
    img.set({
      left: 0, top: 0,
      selectable: false, evented: false,
      scaleX: BASE_W / img.width,
      scaleY: BASE_H / img.height
    });
    canvas.setBackgroundImage(img, canvas.renderAll.bind(canvas));
  });
}
applyBackground();

const GRID = 10;
function snap(v){ return Math.round(v / GRID) * GRID; }

canvas.on('object:moving', (e) => {
  const o = e.target;
  if (o && o.data && o.data.kind === 'reference') return;
  o.set({ left: snap(o.left), top: snap(o.top) });
});
canvas.on('object:scaling', (e) => {
  const o = e.target;
  if (o && o.data && o.data.kind === 'reference') return;
  o.set({ left: snap(o.left), top: snap(o.top) });
});

canvas.on('selection:created', updateSel);
canvas.on('selection:updated', updateSel);
canvas.on('selection:cleared', () => selInfo.textContent = 'None');

function updateSel(){
  const o = canvas.getActiveObject();
  if (!o) return;
  const w = Math.round(o.width * o.scaleX);
  const h = Math.round(o.height * o.scaleY);
  selInfo.textContent = `${o.type} (${Math.round(o.left)},${Math.round(o.top)}) ${w}×${h}`;
}

function createReferenceOverlay(){
  fabric.Image.fromURL(REF_URL, (img) => {
    img.set({
      left: 0, top: 0,
      selectable: false,
      evented: false,
      opacity: 0.35,
      scaleX: BASE_W / img.width,
      scaleY: BASE_H / img.height
    });
    img.data = { kind: 'reference' };
    refImage = img;
    canvas.add(refImage);
    placeReferenceUnderObjects();
    canvas.renderAll();
  });
}

function placeReferenceUnderObjects(){
  if (!refImage) return;
  canvas.sendToBack(refImage);
  canvas.getObjects().forEach(o => {
    if (o === refImage) return;
    if (o.data && o.data.kind === 'reference') return;
    canvas.bringToFront(o);
  });
  refImage.selectable = false;
  refImage.evented = false;
}

document.getElementById('refToggle').addEventListener('change', (e) => {
  if (!refImage) return;
  refImage.visible = e.target.checked;
  canvas.renderAll();
});
document.getElementById('refOpacity').addEventListener('input', (e) => {
  if (!refImage) return;
  refImage.opacity = parseInt(e.target.value,10)/100;
  canvas.renderAll();
});

// Tools
document.getElementById('btnAddText').addEventListener('click', () => {
  const t = new fabric.Textbox('Edit text…', {
    left: 80, top: 200,
    width: 520,
    fontSize: 28,
    fill: '#0b2a4a',
    backgroundColor: 'rgba(255,255,255,0.75)',
    padding: 8
  });
  t.data = { kind: 'text' };
  canvas.add(t);
  canvas.setActiveObject(t);
  canvas.renderAll();
});

document.getElementById('btnAddRedact').addEventListener('click', () => {
  const r = new fabric.Rect({
    left: 80, top: 80,
    width: 420, height: 80,
    fill: 'rgba(255,255,255,0.96)',
    stroke: '#dddddd',
    strokeWidth: 1
  });
  r.data = { kind: 'redact' };
  canvas.add(r);
  canvas.setActiveObject(r);
  canvas.renderAll();
});

function addBox(kind, label){
  const rect = new fabric.Rect({
    left: 0, top: 0,
    width: 520, height: 320,
    fill: 'rgba(0,0,0,0.03)',
    stroke: '#0b2a4a',
    strokeWidth: 2,
    rx: 12, ry: 12
  });
  const text = new fabric.Text(label, {
    left: 18, top: 18,
    fontSize: 24,
    fill: '#0b2a4a'
  });
  const group = new fabric.Group([rect, text], { left: 900, top: 240 });
  group.data = { kind, src: '' };
  canvas.add(group);
  canvas.setActiveObject(group);
  canvas.renderAll();
}

document.getElementById('btnAddImageBox').addEventListener('click', () => addBox('image', 'IMAGE'));
document.getElementById('btnAddVideoBox').addEventListener('click', () => addBox('video', 'VIDEO'));

document.getElementById('btnBringFront').addEventListener('click', () => {
  const o = canvas.getActiveObject();
  if (!o) return;
  canvas.bringToFront(o);
  placeReferenceUnderObjects();
  canvas.renderAll();
});
document.getElementById('btnSendBack').addEventListener('click', () => {
  const o = canvas.getActiveObject();
  if (!o) return;
  canvas.sendToBack(o);
  placeReferenceUnderObjects();
  canvas.renderAll();
});
document.getElementById('btnDelete').addEventListener('click', () => {
  const o = canvas.getActiveObject();
  if (!o) return;
  if (o.data && o.data.kind === 'reference') return;
  canvas.remove(o);
  canvas.renderAll();
});

// Fit-to-screen
function fitCanvas(){
  const wrap = document.getElementById('canvasWrap');
  if(!wrap) return;
  const w = wrap.clientWidth;
  const h = wrap.clientHeight;
  const scale = Math.min(w / BASE_W, h / BASE_H);
  canvas.setWidth(Math.round(BASE_W * scale));
  canvas.setHeight(Math.round(BASE_H * scale));
  canvas.setZoom(scale);
  canvas.calcOffset();
  canvas.renderAll();
  zoomInfo.textContent = `Display scale: ${(scale*100).toFixed(0)}%  |  Internal: ${BASE_W}×${BASE_H}`;
}
window.addEventListener('resize', () => setTimeout(fitCanvas, 50));

// Load existing layout
async function loadDesign(){
  const res = await fetch('/admin/api/load_design.php?slide_id=' + SLIDE_ID);
  const j = await res.json();

  if (!j.ok || !j.design_json) {
    setStatus('No saved layout yet.');
    createReferenceOverlay();
    setTimeout(fitCanvas, 200);
    return;
  }

  canvas.loadFromJSON(j.design_json, () => {
    applyBackground();

    refImage = null;
    canvas.getObjects().forEach(o => {
      if (o && o.data && o.data.kind === 'reference') refImage = o;
    });

    if (!refImage) createReferenceOverlay();
    setStatus('Layout loaded.');
    canvas.renderAll();
    setTimeout(fitCanvas, 200);
  });
}

// ✅ Save: manual object serialization (textboxes always saved)
async function saveDesign(renderAlso){
  try {
    setStatus('Saving...');

    canvas.discardActiveObject();
    canvas.requestRenderAll();

    // commit any textbox edits
    canvas.getObjects().forEach(o => {
      if (o && o.type === 'textbox' && o.isEditing) {
        o.exitEditing();
      }
    });

    const objects = [];
    canvas.getObjects().forEach(o => {
      if (!o) return;
      if (o.data && o.data.kind === 'reference') return;

      let obj = null;
      try {
        obj = o.toObject(['data']);
      } catch(e) {
        return;
      }

      if (o.type === 'textbox') {
        obj.type = 'textbox';
        obj.text = o.text || '';
        obj.fontSize = o.fontSize || 26;
        obj.fill = o.fill || '#0b2a4a';
        obj.backgroundColor = o.backgroundColor || 'rgba(255,255,255,0.75)';
        obj.left = o.left; obj.top = o.top;
        obj.width = o.width; obj.height = o.height;
        obj.scaleX = o.scaleX; obj.scaleY = o.scaleY;
        obj.angle = o.angle || 0;
        obj.textAlign = o.textAlign || 'left';
        obj.lineHeight = o.lineHeight || 1.16;
        obj.charSpacing = o.charSpacing || 0;
        obj.fontFamily = o.fontFamily || 'sans-serif';
        obj.fontWeight = o.fontWeight || 'normal';
        obj.fontStyle = o.fontStyle || 'normal';
        obj.underline = !!o.underline;
      }

      objects.push(obj);
    });

    const design = { version:'5.3.0', objects: objects };

    const payload = { slide_id: SLIDE_ID, design_json: design, render: renderAlso ? 1 : 0 };

    const res = await fetch('/admin/api/save_design.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });

    const txt = await res.text();
    let j = null;
    try { j = JSON.parse(txt); }
    catch(e){ j = { ok:false, error:'Non-JSON response: ' + txt.slice(0,200) }; }

    if (!j.ok) {
      setStatus('Save failed: ' + (j.error || 'unknown'));
      return;
    }

    setStatus(renderAlso ? 'Saved + rendered HTML.' : 'Saved layout.');
  } catch (err) {
    setStatus('Save exception: ' + err);
  }
}

document.getElementById('btnSave').addEventListener('click', () => saveDesign(false));
document.getElementById('btnSaveRender').addEventListener('click', () => saveDesign(true));

// ✅ AI Auto Layout + Undo (re-apply background + restore overlay)
document.getElementById('btnAiLayout').addEventListener('click', async () => {
  setStatus('AI analyzing…');

  undoAiJson = { version:'5.3.0', objects: canvas.getObjects().filter(o => !(o && o.data && o.data.kind==='reference')).map(o => o.toObject(['data'])) };
  document.getElementById('btnUndoAi').disabled = false;

  const form = new FormData();
  form.append('slide_id', SLIDE_ID);

  const res = await fetch('/admin/api/ai_layout.php', { method:'POST', body: form });
  const j = await res.json();

  if (!j.ok) { setStatus('AI failed: ' + (j.error||'unknown')); return; }

  canvas.loadFromJSON(j.design_json, () => {
    applyBackground();

    refImage = null;
    canvas.getObjects().forEach(o => {
      if (o && o.data && o.data.kind === 'reference') refImage = o;
    });
    if (!refImage) createReferenceOverlay();

    setStatus('AI layout loaded. Review and Save + Render.');
    canvas.renderAll();
    setTimeout(fitCanvas, 200);
  });
});

document.getElementById('btnUndoAi').addEventListener('click', () => {
  if (!undoAiJson) return;
  canvas.loadFromJSON(undoAiJson, () => {
    applyBackground();

    refImage = null;
    canvas.getObjects().forEach(o => {
      if (o && o.data && o.data.kind === 'reference') refImage = o;
    });
    if (!refImage) createReferenceOverlay();

    setStatus('Undo AI complete.');
    canvas.renderAll();
    setTimeout(fitCanvas, 200);
  });
  undoAiJson = null;
  document.getElementById('btnUndoAi').disabled = true;
});

setTimeout(loadDesign, 600);
setTimeout(fitCanvas, 800);
createReferenceOverlay();
</script>

<?php cw_footer(); ?>