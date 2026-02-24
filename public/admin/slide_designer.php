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
$bgUrl  = "/assets/bg/ipca_bg.jpeg"; // must exist in public/assets/bg/
cw_header('Slide Designer');
?>
<div class="card">
  <p class="muted">
    Program: <?= h($slide['program_key']) ?> • Lesson <?= (int)$slide['external_lesson_id'] ?> • Page <?= (int)$slide['page_number'] ?> • Slide ID <?= (int)$slideId ?>
  </p>

  <div style="display:grid; grid-template-columns: 320px 1fr; gap:14px;">
    <div>
      <h2>Tools</h2>

      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button class="btn btn-sm" id="btnAddText">Add Text</button>
        <button class="btn btn-sm" id="btnAddRedact">Add Redaction</button>
        <button class="btn btn-sm" id="btnAddImageBox">Add Image Box</button>
        <button class="btn btn-sm" id="btnAddVideoBox">Add Video Box</button>
      </div>

      <hr>

      <h3>Reference layer</h3>
      <label class="muted" style="display:flex; gap:8px; align-items:center;">
        <input type="checkbox" id="refToggle" checked> Show screenshot overlay
      </label>
      <label class="muted">Opacity</label>
      <input type="range" id="refOpacity" min="0" max="100" value="35" style="width:100%;">

      <hr>

      <h3>Selected object</h3>
      <div class="muted" id="selInfo">None</div>

      <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:8px;">
        <button class="btn btn-sm" id="btnBringFront">Bring Front</button>
        <button class="btn btn-sm" id="btnSendBack">Send Back</button>
        <button class="btn btn-sm" id="btnDelete">Delete</button>
      </div>

      <hr>

      <h3>Save</h3>
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button class="btn" id="btnSave">Save Layout</button>
        <button class="btn btn-sm" id="btnSaveRender">Save + Render HTML</button>
        <a class="btn btn-sm" href="/admin/slides.php?lesson_id=<?= (int)$slide['lesson_id'] ?>">Back</a>
      </div>

      <div class="muted" id="status" style="margin-top:10px;"></div>
    </div>

    <div>
      <h2>Canvas</h2>
      <div class="muted">Drag/resize objects. Grid snap = 10px. Canvas is 1600×900 (16:9).</div>
      <div style="border:1px solid #e6e6e6; border-radius:12px; overflow:hidden; background:#f4f6ff;">
        <canvas id="c" width="1600" height="900"></canvas>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fabric@5.3.0/dist/fabric.min.js"></script>
<script>
const SLIDE_ID = <?= (int)$slideId ?>;
const BG_URL   = <?= json_encode($bgUrl) ?>;
const REF_URL  = <?= json_encode($imgUrl) ?>;

const statusEl = document.getElementById('status');
const selInfo  = document.getElementById('selInfo');

function setStatus(msg){ statusEl.textContent = msg; }

const canvas = new fabric.Canvas('c', {
  selection: true,
  preserveObjectStacking: true
});

// --- grid snap ---
const GRID = 10;
function snap(v){ return Math.round(v / GRID) * GRID; }

canvas.on('object:moving', (e) => {
  const o = e.target;
  o.set({ left: snap(o.left), top: snap(o.top) });
});
canvas.on('object:scaling', (e) => {
  const o = e.target;
  o.set({ left: snap(o.left), top: snap(o.top) });
});

canvas.on('selection:created', updateSel);
canvas.on('selection:updated', updateSel);
canvas.on('selection:cleared', () => selInfo.textContent = 'None');

function updateSel(){
  const o = canvas.getActiveObject();
  if (!o) return;
  selInfo.textContent = `${o.type} (${Math.round(o.left)},${Math.round(o.top)}) ${Math.round(o.width*o.scaleX)}×${Math.round(o.height*o.scaleY)}`;
}

// --- background ---
fabric.Image.fromURL(BG_URL, (img) => {
  img.set({
    left: 0, top: 0,
    selectable: false,
    evented: false,
    scaleX: 1600 / img.width,
    scaleY: 900 / img.height
  });
  canvas.setBackgroundImage(img, canvas.renderAll.bind(canvas));
});

// --- reference overlay image ---
let refImage = null;
fabric.Image.fromURL(REF_URL, (img) => {
  img.set({
    left: 0, top: 0,
    selectable: false,
    evented: false,
    opacity: 0.35,
    scaleX: 1600 / img.width,
    scaleY: 900 / img.height
  });
  refImage = img;
  canvas.add(refImage);
  canvas.sendToBack(refImage); // behind objects, above background (we’ll keep it just above bg)
  // ensure bg remains bg
  canvas.renderAll();
});

// Toggle reference
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

// --- tools ---
document.getElementById('btnAddText').addEventListener('click', () => {
  const t = new fabric.Textbox('Edit text…', {
    left: 80, top: 200,
    width: 520,
    fontSize: 28,
    fill: '#0b2a4a',
    backgroundColor: 'rgba(255,255,255,0.75)',
    padding: 8
  });
  t.data = { kind: 'text', html: '<p>Edit text…</p>' };
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
    left: 900, top: 240,
    width: 520, height: 320,
    fill: 'rgba(0,0,0,0.03)',
    stroke: '#0b2a4a',
    strokeWidth: 2,
    rx: 12, ry: 12
  });
  const text = new fabric.Text(label, {
    left: 920, top: 260,
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

// Layer controls
document.getElementById('btnBringFront').addEventListener('click', () => {
  const o = canvas.getActiveObject();
  if (!o) return;
  canvas.bringToFront(o);
  canvas.renderAll();
});
document.getElementById('btnSendBack').addEventListener('click', () => {
  const o = canvas.getActiveObject();
  if (!o) return;
  canvas.sendToBack(o);
  // don’t allow it under background; if reference exists, keep reference above background
  if (refImage) canvas.sendToBack(refImage);
  canvas.renderAll();
});
document.getElementById('btnDelete').addEventListener('click', () => {
  const o = canvas.getActiveObject();
  if (!o) return;
  canvas.remove(o);
  canvas.renderAll();
});

// --- load existing design_json ---
async function loadDesign(){
  const res = await fetch('/admin/api/load_design.php?slide_id=' + SLIDE_ID);
  const j = await res.json();
  if (!j.ok) { setStatus('No saved layout yet.'); return; }
  if (!j.design_json) { setStatus('No saved layout yet.'); return; }

  // Remove all objects except refImage (if exists, keep it) and background (bg)
  const keep = new Set();
  if (refImage) keep.add(refImage);
  canvas.getObjects().forEach(o => {
    if (!keep.has(o)) canvas.remove(o);
  });

  canvas.loadFromJSON(j.design_json, () => {
    // Ensure reference visibility/opacity stays controlled by UI if exists
    setStatus('Layout loaded.');
    canvas.renderAll();
  });
}

async function saveDesign(renderAlso){
  setStatus('Saving...');
  const design = canvas.toJSON(['data']);
  const payload = { slide_id: SLIDE_ID, design_json: design, render: renderAlso ? 1 : 0 };
  const res = await fetch('/admin/api/save_design.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  });
  const j = await res.json();
  if (!j.ok) { setStatus('Save failed: ' + (j.error||'unknown')); return; }
  setStatus(renderAlso ? 'Saved + rendered HTML.' : 'Saved layout.');
}

document.getElementById('btnSave').addEventListener('click', () => saveDesign(false));
document.getElementById('btnSaveRender').addEventListener('click', () => saveDesign(true));

setTimeout(loadDesign, 800);
</script>
<?php cw_footer(); ?>