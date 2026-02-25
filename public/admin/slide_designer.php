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

    <!-- Text styling controls -->
    <label class="muted" style="display:flex; gap:6px; align-items:center;">
      Font
      <select id="fontFamily" class="input" style="height:32px;">
        <option value="Manrope">Manrope</option>
        <option value="Arial">Arial</option>
      </select>
    </label>

    <label class="muted" style="display:flex; gap:6px; align-items:center;">
      Size
      <select id="fontSize" class="input" style="height:32px;">
        <option value="18">18</option>
        <option value="20">20</option>
        <option value="22">22</option>
        <option value="24">24</option>
        <option value="26" selected>26</option>
        <option value="28">28</option>
      </select>
    </label>

    <button class="btn btn-sm" id="btnBold" type="button"><strong>B</strong></button>
    <button class="btn btn-sm" id="btnItalic" type="button"><em>I</em></button>
    <button class="btn btn-sm" id="btnUnderline" type="button"><u>U</u></button>

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

  <!-- Inspector row -->
  <div style="margin-top:10px; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
    <div class="muted" id="status"></div>

    <div style="margin-left:auto; display:flex; gap:8px; align-items:center;">
      <span class="muted">X</span><input id="insX" class="input" style="width:80px;" type="number" step="1">
      <span class="muted">Y</span><input id="insY" class="input" style="width:80px;" type="number" step="1">
      <span class="muted">W</span><input id="insW" class="input" style="width:90px;" type="number" step="1" min="1">
      <span class="muted">H</span><input id="insH" class="input" style="width:90px;" type="number" step="1" min="1">
      <button class="btn btn-sm" id="insApply" type="button">Apply</button>
    </div>
  </div>

  <div class="muted" id="zoomInfo" style="margin-top:6px;"></div>

  <div id="canvasWrap"
       style="
         width:100%;
         height: calc(100vh - 310px);
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

// Inspector inputs
const insX = document.getElementById('insX');
const insY = document.getElementById('insY');
const insW = document.getElementById('insW');
const insH = document.getElementById('insH');
const insApply = document.getElementById('insApply');

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

// Grid snap
const GRID = 10;
function snap(v){ return Math.round(v / GRID) * GRID; }

canvas.on('object:moving', (e) => {
  const o = e.target;
  if (o && o.data && o.data.kind === 'reference') return;
  o.set({ left: snap(o.left), top: snap(o.top) });
  updateInspectorFromSelection();
});
canvas.on('object:scaling', (e) => {
  const o = e.target;
  if (o && o.data && o.data.kind === 'reference') return;
  o.set({ left: snap(o.left), top: snap(o.top) });
  updateInspectorFromSelection();
});

canvas.on('selection:created', () => { updateSel(); updateInspectorFromSelection(); syncTextControlsToSelection(); });
canvas.on('selection:updated', () => { updateSel(); updateInspectorFromSelection(); syncTextControlsToSelection(); });
canvas.on('selection:cleared', () => { selInfo.textContent='None'; clearInspector(); });

function updateSel(){
  const o = canvas.getActiveObject();
  if (!o) return;
  const w = Math.round(o.width * o.scaleX);
  const h = Math.round(o.height * o.scaleY);
  selInfo.textContent = `${o.type} (${Math.round(o.left)},${Math.round(o.top)}) ${w}×${h}`;
}

function clearInspector(){
  insX.value = '';
  insY.value = '';
  insW.value = '';
  insH.value = '';
}

function updateInspectorFromSelection(){
  const o = canvas.getActiveObject();
  if (!o) { clearInspector(); return; }
  const w = Math.round(o.width * o.scaleX);
  const h = Math.round(o.height * o.scaleY);
  insX.value = String(Math.round(o.left));
  insY.value = String(Math.round(o.top));
  insW.value = String(w);
  insH.value = String(h);
}

function applyInspectorToSelection(){
  const o = canvas.getActiveObject();
  if (!o) return;

  const x = parseInt(insX.value || '0', 10);
  const y = parseInt(insY.value || '0', 10);
  const w = parseInt(insW.value || '0', 10);
  const h = parseInt(insH.value || '0', 10);

  // Move
  if (!isNaN(x)) o.set('left', x);
  if (!isNaN(y)) o.set('top', y);

  // Resize: adjust scale to match desired w/h
  if (!isNaN(w) && w > 0 && o.width) o.set('scaleX', w / o.width);
  if (!isNaN(h) && h > 0 && o.height) o.set('scaleY', h / o.height);

  o.setCoords();
  canvas.requestRenderAll();
  updateSel();
}
insApply.addEventListener('click', applyInspectorToSelection);

// Reference overlay
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

// Text style controls
const fontFamilyEl = document.getElementById('fontFamily');
const fontSizeEl = document.getElementById('fontSize');
const btnBold = document.getElementById('btnBold');
const btnItalic = document.getElementById('btnItalic');
const btnUnderline = document.getElementById('btnUnderline');

function selectedTextbox(){
  const o = canvas.getActiveObject();
  if (!o) return null;
  if (o.type === 'textbox') return o;
  return null;
}

function syncTextControlsToSelection(){
  const t = selectedTextbox();
  if (!t) return;

  // Font
  fontFamilyEl.value = (t.fontFamily === 'Manrope') ? 'Manrope' : 'Arial';

  // Size (snap to nearest option)
  const sizes = [18,20,22,24,26,28];
  let best = sizes[0], bestDiff = 9999;
  sizes.forEach(s=>{
    const d = Math.abs((t.fontSize||26) - s);
    if (d < bestDiff) { bestDiff = d; best = s; }
  });
  fontSizeEl.value = String(best);
}

function applyFontFamily(){
  const t = selectedTextbox();
  if (!t) return;
  t.set('fontFamily', fontFamilyEl.value);
  canvas.requestRenderAll();
}
function applyFontSize(){
  const t = selectedTextbox();
  if (!t) return;
  t.set('fontSize', parseInt(fontSizeEl.value, 10));
  canvas.requestRenderAll();
}

fontFamilyEl.addEventListener('change', applyFontFamily);
fontSizeEl.addEventListener('change', applyFontSize);

btnBold.addEventListener('click', ()=>{
  const t = selectedTextbox();
  if (!t) return;
  t.set('fontWeight', (t.fontWeight === 'bold') ? 'normal' : 'bold');
  canvas.requestRenderAll();
});
btnItalic.addEventListener('click', ()=>{
  const t = selectedTextbox();
  if (!t) return;
  t.set('fontStyle', (t.fontStyle === 'italic') ? 'normal' : 'italic');
  canvas.requestRenderAll();
});
btnUnderline.addEventListener('click', ()=>{
  const t = selectedTextbox();
  if (!t) return;
  t.set('underline', !t.underline);
  canvas.requestRenderAll();
});

// Tools
document.getElementById('btnAddText').addEventListener('click', () => {
  const t = new fabric.Textbox('Edit text…', {
    left: 80, top: 200,
    width: 520,
    fontSize: 26,
    fontFamily: 'Manrope',
    fill: '#0b2a4a',
    backgroundColor: 'rgba(255,255,255,0.75)',
    padding: 8
  });
  t.data = { kind: 'text' };
  canvas.add(t);
  canvas.setActiveObject(t);
  syncTextControlsToSelection();
  updateInspectorFromSelection();
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
  updateInspectorFromSelection();
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
  updateInspectorFromSelection();
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
  updateInspectorFromSelection();
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

// ✅ Save (manual serialize; textboxes always saved)
async function saveDesign(renderAlso){
  try {
    setStatus('Saving...');

    canvas.discardActiveObject();
    canvas.requestRenderAll();

    // Commit any ongoing edits
    canvas.getObjects().forEach(o => {
      if (o && (o.type === 'textbox' || o.type === 'i-text' || o.type === 'text') && o.isEditing) {
        o.exitEditing();
      }
    });

    const objects = [];

    canvas.getObjects().forEach(o => {
      if (!o) return;

      // Skip screenshot overlay
      if (o.data && o.data.kind === 'reference') return;

      const isText = (o.type === 'textbox' || o.type === 'i-text' || o.type === 'text');

      // ✅ Text objects: build JSON manually (most reliable)
      if (isText) {
        objects.push({
          type: 'textbox',                    // always save as textbox
          left: o.left ?? 0,
          top: o.top ?? 0,
          width: o.width ?? 520,
          height: o.height ?? 120,
          scaleX: o.scaleX ?? 1,
          scaleY: o.scaleY ?? 1,
          angle: o.angle ?? 0,

          text: o.text || '',
          fontFamily: o.fontFamily || 'Manrope',
          fontSize: o.fontSize || 26,
          fontWeight: o.fontWeight || 'normal',
          fontStyle: o.fontStyle || 'normal',
          underline: !!o.underline,
          textAlign: o.textAlign || 'left',
          lineHeight: o.lineHeight || 1.16,
          charSpacing: o.charSpacing || 0,

          fill: o.fill || '#0b2a4a',
          backgroundColor: o.backgroundColor || 'rgba(255,255,255,0.75)',

          // keep your custom metadata
          data: o.data || {}
        });
        return;
      }

      // ✅ Non-text objects: use Fabric serialization
      try {
        objects.push(o.toObject(['data']));
      } catch (e) {
        console.warn('Skipping object (toObject failed):', o, e);
      }
    });

    const design = { version: '5.3.0', objects };

    const payload = { slide_id: SLIDE_ID, design_json: design, render: renderAlso ? 1 : 0 };

    const res = await fetch('/admin/api/save_design.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });

    const txt = await res.text();
    let j;
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

// ✅ AI Auto Layout + Undo (background persists; overlay restored)
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