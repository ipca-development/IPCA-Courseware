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

  <style>
    .btn-on{
      border-color:#1e3c72 !important;
      background: rgba(30,60,114,0.12) !important;
    }
    .mini-label{ font-size:12px; opacity:.75; }
  </style>

  <div style="
      position: sticky; top: 0; z-index: 50;
      background: rgba(255,255,255,0.95);
      backdrop-filter: blur(6px);
      border: 1px solid #eee;
      border-radius: 12px;
      padding: 10px;
      display:flex; flex-wrap:wrap;
      gap:10px; align-items:center;
    ">
    <strong style="margin-right:6px;">Tools</strong>

    <button class="btn btn-sm" id="btnAiLayout" type="button">AI Auto Layout</button>
    <button class="btn btn-sm" id="btnUndoAi" type="button" disabled>Undo AI</button>

    <a class="btn btn-sm" target="_blank" href="/admin/slide_preview.php?slide_id=<?= (int)$slideId ?>">Preview</a>

    <span style="width:1px;height:26px;background:#e6e6e6;margin:0 6px;"></span>

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
        <?php for ($i=12; $i<=60; $i+=2): ?>
          <option value="<?= $i ?>" <?= ($i===26?'selected':'') ?>><?= $i ?></option>
        <?php endfor; ?>
      </select>
    </label>

    <button class="btn btn-sm" id="btnBold" type="button"><strong>B</strong></button>
    <button class="btn btn-sm" id="btnItalic" type="button"><em>I</em></button>
    <button class="btn btn-sm" id="btnUnderline" type="button"><u>U</u></button>

    <label class="muted" style="display:flex; gap:6px; align-items:center;">
      Text color
      <input id="textColor" type="color" value="#0b2a4a" style="height:32px; width:44px; padding:0; border:1px solid #ddd; border-radius:8px;">
    </label>

    <label class="muted" style="display:flex; gap:6px; align-items:center;">
      Style
      <select id="quickStyle" class="input" style="height:32px;">
        <option value="">—</option>
        <option value="TITLE">Title</option>
        <option value="BODY_LEFT">Body Left</option>
        <option value="BODY_RIGHT">Body Right</option>
        <option value="CAPTION">Caption</option>
      </select>
    </label>

    <!-- BG controls -->
    <button class="btn btn-sm" id="btnToggleBg" type="button">BG</button>
    <label class="muted" style="display:flex; gap:6px; align-items:center;">
      <span class="mini-label">BG</span>
      <input id="bgColor" type="color" value="#ffffff" style="height:32px; width:44px; padding:0; border:1px solid #ddd; border-radius:8px;">
    </label>
    <label class="muted" style="display:flex; gap:6px; align-items:center;">
      <span class="mini-label">α</span>
      <input id="bgAlpha" type="range" min="0" max="100" value="75" style="width:110px;">
    </label>

    <!-- Guides -->
    <button class="btn btn-sm" id="btnGuideV" type="button">+ V Guide</button>
    <button class="btn btn-sm" id="btnGuideH" type="button">+ H Guide</button>
    <button class="btn btn-sm" id="btnGuideDel" type="button">Del Guide</button>
    <label class="muted" style="display:flex; gap:6px; align-items:center;">
      <span class="mini-label">Guide</span>
      <input id="guideColor" type="color" value="#abcde0" style="height:32px; width:44px; padding:0; border:1px solid #ddd; border-radius:8px;">
    </label>
    <button class="btn btn-sm" id="btnGuideApply" type="button">Update Guide</button>

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
      Opacity <input type="range" id="refOpacity" min="0" max="100" value="35">
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

  <div id="canvasWrap" style="width:100%; height: calc(100vh - 390px); margin-top: 12px;
         border:1px solid #e6e6e6; border-radius:12px; overflow:hidden; background:#f4f6ff; position:relative;">
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

const BASE_W = 1600, BASE_H = 900;
const canvas = new fabric.Canvas('c', { selection:true, preserveObjectStacking:true });

let refImage = null;
let undoAiJson = null;
let guideObjects = [];

function isTextObj(o){ return o && (o.type==='textbox' || o.type==='i-text' || o.type==='text'); }
function activeText(){ const o=canvas.getActiveObject(); return isTextObj(o)?o:null; }

function forceTextEditable(){
  canvas.getObjects().forEach(o=>{
    if(isTextObj(o)){ o.selectable=true; o.evented=true; o.editable=true; }
  });
}

function enterEdit(o){
  if(!isTextObj(o)) return;
  if(o.enterEditing) o.enterEditing();
  if(o.hiddenTextarea) o.hiddenTextarea.focus();
}

// dblclick
canvas.on('mouse:dblclick', ()=> enterEdit(canvas.getActiveObject()));

// click twice fast
let _lastClickObj=null, _lastClickAt=0;
canvas.on('mouse:down', ()=>{
  const o = canvas.getActiveObject();
  if(!isTextObj(o)){ _lastClickObj=null; return; }
  const now = Date.now();
  if(_lastClickObj===o && (now-_lastClickAt)<500){ enterEdit(o); }
  _lastClickObj=o; _lastClickAt=now;
});

// Enter starts editing
document.addEventListener('keydown',(e)=>{
  const o=canvas.getActiveObject();
  if(e.key==='Enter' && isTextObj(o) && !o.isEditing){
    e.preventDefault(); enterEdit(o);
  }
});

// Inspector
const insX=document.getElementById('insX');
const insY=document.getElementById('insY');
const insW=document.getElementById('insW');
const insH=document.getElementById('insH');
document.getElementById('insApply').addEventListener('click', ()=>{
  const o=canvas.getActiveObject(); if(!o) return;
  const x=parseInt(insX.value||'0',10), y=parseInt(insY.value||'0',10);
  const w=parseInt(insW.value||'0',10), h=parseInt(insH.value||'0',10);
  if(!isNaN(x)) o.set('left',x);
  if(!isNaN(y)) o.set('top',y);
  if(!isNaN(w)&&w>0&&o.width) o.set('scaleX', w/o.width);
  if(!isNaN(h)&&h>0&&o.height) o.set('scaleY', h/o.height);
  o.setCoords(); canvas.requestRenderAll(); canvas.calcOffset();
});

function updateInspector(){
  const o=canvas.getActiveObject();
  if(!o){ insX.value='';insY.value='';insW.value='';insH.value=''; return; }
  insX.value=Math.round(o.left||0);
  insY.value=Math.round(o.top||0);
  insW.value=Math.round((o.width||0)*(o.scaleX||1));
  insH.value=Math.round((o.height||0)*(o.scaleY||1));
}

canvas.on('selection:created', ()=>{ updateSel(); updateInspector(); syncTextUI(); syncColorUI(); syncBgBtnUI(); });
canvas.on('selection:updated', ()=>{ updateSel(); updateInspector(); syncTextUI(); syncColorUI(); syncBgBtnUI(); });
canvas.on('selection:cleared', ()=>{ document.getElementById('selInfo').textContent='None'; updateInspector(); syncBgBtnUI(); });

function updateSel(){
  const o=canvas.getActiveObject(); if(!o) return;
  const w=Math.round((o.width||0)*(o.scaleX||1));
  const h=Math.round((o.height||0)*(o.scaleY||1));
  selInfo.textContent = `${o.type} (${Math.round(o.left||0)},${Math.round(o.top||0)}) ${w}×${h}`;
}

// background
function applyBackground(){
  fabric.Image.fromURL(BG_URL, (img)=>{
    img.set({left:0,top:0,selectable:false,evented:false,scaleX:BASE_W/img.width,scaleY:BASE_H/img.height});
    canvas.setBackgroundImage(img, canvas.renderAll.bind(canvas));
  });
}
applyBackground();

// overlay
function createReferenceOverlay(){
  fabric.Image.fromURL(REF_URL, (img)=>{
    img.set({left:0,top:0,selectable:false,evented:false,opacity:0.35,scaleX:BASE_W/img.width,scaleY:BASE_H/img.height});
    img.data={kind:'reference'};
    refImage=img;
    canvas.add(refImage);
    placeRef();
    canvas.renderAll();
  });
}
function placeRef(){
  if(!refImage) return;
  canvas.sendToBack(refImage);
  canvas.getObjects().forEach(o=>{
    if(o===refImage) return;
    if(o?.data?.kind==='reference') return;
    canvas.bringToFront(o);
  });
  guideObjects.forEach(g=>canvas.bringToFront(g));
  refImage.selectable=false; refImage.evented=false;
}

// overlay toggle + disable slider
const refToggle=document.getElementById('refToggle');
const refOpacity=document.getElementById('refOpacity');

function applyOverlayUI(){
  if(!refImage) return;
  if(!refToggle.checked){
    refImage.visible=false;
    refOpacity.disabled=true;
  } else {
    refImage.visible=true;
    refOpacity.disabled=false;
    refImage.opacity=parseInt(refOpacity.value,10)/100;
  }
  canvas.requestRenderAll();
}

refToggle.addEventListener('change', ()=>{ applyOverlayUI(); });
refOpacity.addEventListener('input', ()=>{ applyOverlayUI(); });

// ===== Guides =====
function clearGuides(){
  guideObjects.forEach(g=>canvas.remove(g));
  guideObjects=[];
}
function drawGuide(g){
  const axis=g.axis;
  const pos=parseInt(g.pos,10)||0;
  const color=g.color||'#ABCDE0';
  let line;
  if(axis==='v') line=new fabric.Line([pos,0,pos,900],{stroke:color,strokeWidth:2,selectable:true,evented:true});
  else line=new fabric.Line([0,pos,1600,pos],{stroke:color,strokeWidth:2,selectable:true,evented:true});
  line.data={kind:'guide',guide_id:g.id,axis:axis};
  canvas.add(line); guideObjects.push(line);
}
async function loadGuides(){
  const res=await fetch('/admin/api/guides_get.php');
  const j=await res.json();
  if(!j.ok) return;
  clearGuides();
  (j.guides||[]).forEach(drawGuide);
  guideObjects.forEach(g=>canvas.bringToFront(g));
  canvas.requestRenderAll();
  canvas.calcOffset();
  applyOverlayUI();
}
function existingGuidePositions(axis){
  return guideObjects.filter(g=>g?.data?.axis===axis).map(g=>Math.round(g.x1||g.y1||0));
}
function nextFreePos(axis){
  const used=new Set(existingGuidePositions(axis));
  let p=80;
  while(used.has(p)) p += 20;
  return p;
}

document.getElementById('btnGuideV').addEventListener('click', async ()=>{
  const pos = nextFreePos('v');
  await fetch('/admin/api/guides_save.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add',axis:'v',pos:pos,color:'#ABCDE0'})});
  await loadGuides();
});
document.getElementById('btnGuideH').addEventListener('click', async ()=>{
  const pos = nextFreePos('h');
  await fetch('/admin/api/guides_save.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add',axis:'h',pos:pos,color:'#ABCDE0'})});
  await loadGuides();
});
document.getElementById('btnGuideDel').addEventListener('click', async ()=>{
  const o=canvas.getActiveObject();
  if(!o || o?.data?.kind!=='guide') return;
  await fetch('/admin/api/guides_save.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id:o.data.guide_id})});
  await loadGuides();
});

// guide recolor / move persist
const guideColor=document.getElementById('guideColor');
document.getElementById('btnGuideApply').addEventListener('click', async ()=>{
  const o=canvas.getActiveObject();
  if(!o || o?.data?.kind!=='guide') return;
  const id=o.data.guide_id;
  const axis=o.data.axis;
  const pos = axis==='v' ? Math.round(o.x1) : Math.round(o.y1);
  const color = guideColor.value || '#ABCDE0';
  await fetch('/admin/api/guides_save.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'update',id:id,pos:pos,color:color})});
  await loadGuides();
});
canvas.on('selection:updated', ()=>{
  const o=canvas.getActiveObject();
  if(o?.data?.kind==='guide'){
    guideColor.value = (o.stroke && typeof o.stroke==='string' && o.stroke.startsWith('#')) ? o.stroke : '#abcde0';
  }
});

// ===== Text styling =====
const fontFamilyEl=document.getElementById('fontFamily');
const fontSizeEl=document.getElementById('fontSize');
const btnBold=document.getElementById('btnBold');
const btnItalic=document.getElementById('btnItalic');
const btnUnderline=document.getElementById('btnUnderline');
const quickStyle=document.getElementById('quickStyle');
const bgColor=document.getElementById('bgColor');
const bgAlpha=document.getElementById('bgAlpha');
const btnToggleBg=document.getElementById('btnToggleBg');
const textColorEl=document.getElementById('textColor');

function syncTextUI(){
  const t=activeText(); if(!t) return;
  fontFamilyEl.value = (t.fontFamily==='Manrope')?'Manrope':'Arial';
  fontSizeEl.value = String(t.fontSize||26);
}
function syncColorUI(){
  const t=activeText(); if(!t) return;
  if(typeof t.fill==='string' && t.fill.startsWith('#') && t.fill.length===7) textColorEl.value=t.fill;
}
function syncBgBtnUI(){
  const t=activeText();
  if(!t){ btnToggleBg.classList.remove('btn-on'); return; }
  if(t.backgroundColor) btnToggleBg.classList.add('btn-on');
  else btnToggleBg.classList.remove('btn-on');
}

fontFamilyEl.addEventListener('change', ()=>{ const t=activeText(); if(!t) return; t.fontFamily=fontFamilyEl.value; canvas.requestRenderAll(); });
fontSizeEl.addEventListener('change', ()=>{ const t=activeText(); if(!t) return; t.fontSize=parseInt(fontSizeEl.value,10); canvas.requestRenderAll(); });

btnBold.addEventListener('click', ()=>{ const t=activeText(); if(!t) return; t.fontWeight=(t.fontWeight==='bold')?'normal':'bold'; canvas.requestRenderAll(); });
btnItalic.addEventListener('click', ()=>{ const t=activeText(); if(!t) return; t.fontStyle=(t.fontStyle==='italic')?'normal':'italic'; canvas.requestRenderAll(); });
btnUnderline.addEventListener('click', ()=>{ const t=activeText(); if(!t) return; t.underline=!t.underline; canvas.requestRenderAll(); });

textColorEl.addEventListener('input', ()=>{ const t=activeText(); if(!t) return; t.fill=textColorEl.value; canvas.requestRenderAll(); });

function applyTextboxBg(t, on){
  if(!t) return;
  if(!on){ t.backgroundColor=null; canvas.requestRenderAll(); syncBgBtnUI(); return; }
  const a=parseInt(bgAlpha.value,10)/100;
  // convert hex to rgb
  const hex=bgColor.value.replace('#','');
  const r=parseInt(hex.substring(0,2),16), g=parseInt(hex.substring(2,4),16), b=parseInt(hex.substring(4,6),16);
  t.backgroundColor = `rgba(${r},${g},${b},${a})`;
  canvas.requestRenderAll();
  syncBgBtnUI();
}
btnToggleBg.addEventListener('click', ()=>{ const t=activeText(); if(!t) return; applyTextboxBg(t, !t.backgroundColor); });
bgColor.addEventListener('input', ()=>{ const t=activeText(); if(!t) return; if(t.backgroundColor) applyTextboxBg(t,true); });
bgAlpha.addEventListener('input', ()=>{ const t=activeText(); if(!t) return; if(t.backgroundColor) applyTextboxBg(t,true); });

// Quick styles
const STYLE_PRESETS = {
  TITLE:      { x:80,y:110,w:1440,h:90, fontFamily:'Manrope', fontSize:40, fontWeight:'bold', fontStyle:'normal', underline:false },
  BODY_LEFT:  { x:80,y:240,w:680,h:560,  fontFamily:'Manrope', fontSize:26, fontWeight:'normal', fontStyle:'normal', underline:false },
  BODY_RIGHT: { x:840,y:240,w:680,h:560, fontFamily:'Manrope', fontSize:26, fontWeight:'normal', fontStyle:'normal', underline:false },
  CAPTION:    { x:80,y:820,w:1440,h:60,  fontFamily:'Manrope', fontSize:20, fontWeight:'normal', fontStyle:'italic', underline:false }
};
quickStyle.addEventListener('change', ()=>{
  const key=quickStyle.value; if(!key) return;
  const t=activeText(); if(!t) return;
  const s=STYLE_PRESETS[key];
  t.left=s.x; t.top=s.y;
  if(t.width) t.scaleX=s.w/t.width;
  if(t.height) t.scaleY=s.h/t.height;
  t.fontFamily=s.fontFamily; t.fontSize=s.fontSize;
  t.fontWeight=s.fontWeight; t.fontStyle=s.fontStyle; t.underline=!!s.underline;
  t.backgroundColor=null;
  t.setCoords(); canvas.requestRenderAll();
  updateInspector(); syncTextUI(); syncBgBtnUI();
  quickStyle.value='';
});

// Add objects
document.getElementById('btnAddText').addEventListener('click', ()=>{
  const t=new fabric.Textbox('Edit text…',{left:80,top:200,width:520,fontSize:26,fontFamily:'Manrope',fill:'#0b2a4a',backgroundColor:null,padding:8});
  t.data={kind:'text'};
  canvas.add(t); canvas.setActiveObject(t);
  forceTextEditable();
  syncTextUI(); syncColorUI(); syncBgBtnUI(); updateInspector();
  canvas.requestRenderAll();
});
document.getElementById('btnAddRedact').addEventListener('click', ()=>{
  const r=new fabric.Rect({left:80,top:80,width:420,height:80,fill:'rgba(255,255,255,0.96)',stroke:'#ddd',strokeWidth:1});
  r.data={kind:'redact'}; canvas.add(r); canvas.setActiveObject(r);
  updateInspector(); canvas.requestRenderAll();
});
function addBox(kind,label){
  const rect=new fabric.Rect({left:0,top:0,width:520,height:320,fill:'rgba(0,0,0,0.03)',stroke:'#0b2a4a',strokeWidth:2,rx:12,ry:12});
  const text=new fabric.Text(label,{left:18,top:18,fontSize:24,fill:'#0b2a4a'});
  const group=new fabric.Group([rect,text],{left:900,top:240});
  group.data={kind,src:''};
  canvas.add(group); canvas.setActiveObject(group);
  updateInspector(); canvas.requestRenderAll();
}
document.getElementById('btnAddImageBox').addEventListener('click',()=>addBox('image','IMAGE'));
document.getElementById('btnAddVideoBox').addEventListener('click',()=>addBox('video','VIDEO'));

document.getElementById('btnBringFront').addEventListener('click',()=>{ const o=canvas.getActiveObject(); if(!o) return; canvas.bringToFront(o); placeRef(); canvas.requestRenderAll(); });
document.getElementById('btnSendBack').addEventListener('click',()=>{ const o=canvas.getActiveObject(); if(!o) return; canvas.sendToBack(o); placeRef(); canvas.requestRenderAll(); });
document.getElementById('btnDelete').addEventListener('click',()=>{ const o=canvas.getActiveObject(); if(!o) return; if(o?.data?.kind==='reference') return; canvas.remove(o); canvas.requestRenderAll(); updateInspector(); });

function fitCanvas(){
  const wrap=document.getElementById('canvasWrap');
  const w=wrap.clientWidth, h=wrap.clientHeight;
  const scale=Math.min(w/BASE_W, h/BASE_H);
  canvas.setWidth(Math.round(BASE_W*scale));
  canvas.setHeight(Math.round(BASE_H*scale));
  canvas.setZoom(scale);
  canvas.calcOffset();
  canvas.requestRenderAll();
  zoomInfo.textContent = `Display scale: ${(scale*100).toFixed(0)}%  |  Internal: ${BASE_W}×${BASE_H}`;
}
window.addEventListener('resize',()=>setTimeout(fitCanvas,50));

async function loadDesign(){
  const res=await fetch('/admin/api/load_design.php?slide_id='+SLIDE_ID);
  const j=await res.json();
  if(!j.ok || !j.design_json){
    setStatus('No saved layout yet.');
    createReferenceOverlay();
    await loadGuides();
    setTimeout(fitCanvas,200);
    return;
  }
  canvas.loadFromJSON(j.design_json, async ()=>{
    applyBackground();
    forceTextEditable();
    refImage=null;
    canvas.getObjects().forEach(o=>{ if(o?.data?.kind==='reference') refImage=o; });
    if(!refImage) createReferenceOverlay();
    await loadGuides();
    setStatus('Layout loaded. Double-click or press Enter to edit text.');
    canvas.requestRenderAll();
    canvas.calcOffset();
    setTimeout(fitCanvas,200);
  });
}

// Save: manual serialize; skip guides + overlay
async function saveDesign(renderAlso){
  try{
    setStatus('Saving...');
    canvas.discardActiveObject();
    canvas.requestRenderAll();
    canvas.getObjects().forEach(o=>{ if(o && isTextObj(o) && o.isEditing) o.exitEditing(); });

    const objects=[];
    canvas.getObjects().forEach(o=>{
      if(!o) return;
      if(o?.data?.kind==='reference') return;
      if(o?.data?.kind==='guide') return;
      if(isTextObj(o)){
        objects.push({
          type:'textbox',
          left:o.left??0, top:o.top??0,
          width:o.width??520, height:o.height??120,
          scaleX:o.scaleX??1, scaleY:o.scaleY??1,
          angle:o.angle??0,
          text:o.text||'',
          fontFamily:o.fontFamily||'Manrope',
          fontSize:o.fontSize||26,
          fontWeight:o.fontWeight||'normal',
          fontStyle:o.fontStyle||'normal',
          underline:!!o.underline,
          textAlign:o.textAlign||'left',
          lineHeight:o.lineHeight||1.16,
          charSpacing:o.charSpacing||0,
          fill:(typeof o.fill==='string' ? o.fill : '#0b2a4a'),
          backgroundColor:o.backgroundColor||null,
          data:o.data||{}
        });
        return;
      }
      try{ objects.push(o.toObject(['data'])); }catch(e){}
    });

    const payload={ slide_id: SLIDE_ID, design_json:{version:'5.3.0',objects}, render: renderAlso?1:0 };
    const res=await fetch('/admin/api/save_design.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const txt=await res.text();
    let jr; try{ jr=JSON.parse(txt);}catch(e){ jr={ok:false,error:'Non-JSON response: '+txt.slice(0,200)};}
    if(!jr.ok){ setStatus('Save failed: '+(jr.error||'unknown')); return; }
    setStatus(renderAlso?'Saved + rendered HTML.':'Saved layout.');
  }catch(err){
    setStatus('Save exception: '+err);
  }
}
document.getElementById('btnSave').addEventListener('click',()=>saveDesign(false));
document.getElementById('btnSaveRender').addEventListener('click',()=>saveDesign(true));

// AI
document.getElementById('btnAiLayout').addEventListener('click', async ()=>{
  setStatus('AI analyzing…');
  undoAiJson={version:'5.3.0',objects:canvas.getObjects().filter(o=>!(o&&o.data&&o.data.kind==='reference') && !(o&&o.data&&o.data.kind==='guide')).map(o=>o.toObject(['data']))};
  document.getElementById('btnUndoAi').disabled=false;

  const form=new FormData(); form.append('slide_id',SLIDE_ID);
  const res=await fetch('/admin/api/ai_layout.php',{method:'POST',body:form});
  const j=await res.json();
  if(!j.ok){ setStatus('AI failed: '+(j.error||'unknown')); return; }

  canvas.loadFromJSON(j.design_json, async ()=>{
    applyBackground();
    forceTextEditable();
    canvas.getObjects().forEach(o=>{
      if(isTextObj(o)){
        o.backgroundColor=null;
        o.fontFamily=o.fontFamily||'Manrope';
        if(!o.fill) o.fill='#0b2a4a';
      }
    });
    refImage=null;
    canvas.getObjects().forEach(o=>{ if(o?.data?.kind==='reference') refImage=o; });
    if(!refImage) createReferenceOverlay();
    await loadGuides();
    setStatus('AI layout loaded. Double-click (or click twice) / press Enter to edit.');
    canvas.requestRenderAll();
    canvas.calcOffset();
    setTimeout(fitCanvas,200);
  });
});

document.getElementById('btnUndoAi').addEventListener('click', ()=>{
  if(!undoAiJson) return;
  canvas.loadFromJSON(undoAiJson, async ()=>{
    applyBackground();
    forceTextEditable();
    refImage=null;
    canvas.getObjects().forEach(o=>{ if(o?.data?.kind==='reference') refImage=o; });
    if(!refImage) createReferenceOverlay();
    await loadGuides();
    setStatus('Undo AI complete.');
    canvas.requestRenderAll();
    canvas.calcOffset();
    setTimeout(fitCanvas,200);
  });
  undoAiJson=null;
  document.getElementById('btnUndoAi').disabled=true;
});

setTimeout(async ()=>{
  await loadDesign();
  await loadGuides();
  createReferenceOverlay();
  applyOverlayUI();
  setTimeout(fitCanvas,300);
}, 400);
</script>

<?php cw_footer(); ?>