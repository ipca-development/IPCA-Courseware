<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../src/bootstrap.php';
require_once __DIR__ . '/../../../../src/layout.php';
require_once __DIR__ . '/../../../../src/flight_training/AdminLogbookService.php';

cw_require_admin();

$user = cw_current_user($pdo);
$actorUserId = (int)($user['id'] ?? 0);
$service = new AdminLogbookService($pdo);
$error = '';
$logbookId = (int)($_GET['logbook_id'] ?? 0);
if ($logbookId <= 0 && (int)($_GET['student_user_id'] ?? 0) > 0) {
    try {
        $logbookId = $service->getOrCreateLogbook((int)$_GET['student_user_id'], null, $actorUserId);
        redirect('/admin/flight_training/logbooks/view.php?logbook_id=' . $logbookId);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$schemaReady = $service->schemaReady();
$workspace = array();
if ($schemaReady && $logbookId > 0) {
    try {
        $workspace = $service->loadWorkspace($logbookId);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$workspaceJson = json_encode($workspace, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

cw_header('Flight Training · Admin Logbook Workspace');
?>

<style>
.alogw{display:flex;flex-direction:column;gap:16px}
.alogw-top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;padding:18px;border-radius:22px;background:linear-gradient(135deg,#102845,#1d4f89);color:#fff;box-shadow:0 18px 40px rgba(15,40,69,.16)}
.alogw-title{margin:0;font-size:25px}.alogw-sub{margin:7px 0 0;color:#dbeafe;font-size:13px}.alogw-kicker{margin:0 0 5px;font-size:11px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#bfdbfe}
.alogw-btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:999px;padding:8px 12px;font-size:12px;font-weight:900;text-decoration:none;cursor:pointer;white-space:nowrap}
.alogw-btn--primary{background:#1d4f89;color:#fff}.alogw-btn--secondary{background:#eef2ff;color:#1e3a8a}.alogw-btn--ghost{background:#fff;color:#334155;border:1px solid rgba(15,23,42,.12)}.alogw-btn--danger{background:#fff1f2;color:#be123c;border:1px solid #fecdd3}
.alogw-status{font-size:12px;font-weight:800;color:#64748b}.alogw-error{padding:13px 16px;border-radius:16px;font-size:13px;font-weight:800;background:#fff1f2;color:#be123c;border:1px solid #fecdd3}
.alogw-panel{border:1px solid rgba(15,23,42,.08);border-radius:20px;background:#fff;box-shadow:0 10px 26px rgba(15,23,42,.06);overflow:hidden}
.alogw-panel-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:14px 16px;border-bottom:1px solid rgba(15,23,42,.07)}
.alogw-panel-title{margin:0;font-size:15px;color:#102845}.alogw-panel-text{margin:3px 0 0;color:#64748b;font-size:12px}
.alogw-cards{display:grid;grid-template-columns:repeat(6,minmax(120px,1fr));gap:10px}.alogw-card{padding:13px 14px;border-radius:17px;background:#fff;border:1px solid rgba(15,23,42,.08);box-shadow:0 8px 20px rgba(15,23,42,.05)}.alogw-card-label{font-size:10px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:#64748b}.alogw-card-value{margin-top:4px;font-size:20px;font-weight:900;color:#102845}
.alogw-import{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid rgba(15,23,42,.07);background:#f8fafc}.alogw-input,.alogw-select{border:1px solid rgba(15,23,42,.13);border-radius:12px;padding:8px 10px;font:inherit;font-size:12px;background:#fff;color:#102845}.alogw-extract-status{margin-left:auto;font-size:12px;font-weight:900;color:#475569;background:#e2e8f0;border-radius:999px;padding:7px 10px}
.alogw-grid-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid rgba(15,23,42,.07);background:#fff}
.alogw-table-wrap{overflow:auto;max-height:720px;background:#fff8ed}.alogw-table{width:100%;border-collapse:separate;border-spacing:0;min-width:2200px;background:#fffdf7}.alogw-table th{position:sticky;top:0;z-index:1;padding:10px 8px;background:#f1e4c7;border-right:1px solid #d8c7a2;border-bottom:2px solid #b99b61;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#5b4630;text-align:center}.alogw-table td{padding:6px;border-right:1px solid #ead9b5;border-bottom:1px solid #ead9b5;vertical-align:top;background:#fffdf7}.alogw-table tr:nth-child(even) td{background:#fff9ec}.alogw-table input,.alogw-table textarea,.alogw-table select{width:100%;box-sizing:border-box;border:1px solid rgba(91,70,48,.16);border-radius:7px;padding:7px;font:inherit;font-size:12px;color:#102845;background:#fffef9}.alogw-table textarea{height:34px;resize:vertical}.alogw-table input[type=checkbox]{width:auto}
.alogw-modal{position:fixed;inset:0;z-index:9999;display:none;background:rgba(15,23,42,.62);padding:28px}.alogw-modal.is-open{display:flex;align-items:center;justify-content:center}.alogw-modal-card{width:min(1100px,96vw);height:min(820px,92vh);border-radius:22px;background:#fff;box-shadow:0 28px 80px rgba(15,23,42,.35);display:flex;flex-direction:column;overflow:hidden}.alogw-modal-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:14px 16px;border-bottom:1px solid rgba(15,23,42,.08)}.alogw-modal-body{flex:1;background:#0f172a;overflow:auto;display:flex;align-items:center;justify-content:center}.alogw-modal-body img{max-width:100%;transform-origin:center;transition:transform .15s ease}.alogw-image-empty{color:#cbd5e1;text-align:center;padding:28px;font-size:13px}
.alogw-bottom{display:grid;grid-template-columns:1fr 1fr;gap:16px}.alogw-req-list{max-height:360px;overflow:auto}.alogw-req-row{display:grid;grid-template-columns:86px 1fr 70px;gap:10px;padding:10px 16px;border-bottom:1px solid rgba(15,23,42,.06);align-items:center}.alogw-badge{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:5px 8px;font-size:10px;font-weight:900;text-transform:uppercase}.alogw-badge--pass{background:#dcfce7;color:#166534}.alogw-badge--fail{background:#fee2e2;color:#991b1b}
.alogw-vars{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;padding:12px 16px;max-height:360px;overflow:auto}.alogw-var{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:11px;background:#f8fafc;border:1px solid rgba(15,23,42,.08);border-radius:10px;padding:7px;color:#334155}
@media(max-width:1200px){.alogw-bottom{grid-template-columns:1fr}.alogw-cards{grid-template-columns:repeat(2,minmax(120px,1fr))}.alogw-extract-status{margin-left:0}}
</style>

<div class="alogw" id="alogWorkspace" data-logbook-id="<?= (int)$logbookId ?>">
  <header class="alogw-top">
    <div>
      <p class="alogw-kicker">Admin · Flight Training · Admin Logbook</p>
      <h1 class="alogw-title"><?= h((string)($workspace['logbook']['student_name'] ?? 'Logbook Workspace')) ?></h1>
      <p class="alogw-sub"><?= h((string)($workspace['logbook']['student_email'] ?? 'Structured flight data, requirements, and future form variables.')) ?></p>
    </div>
    <a class="alogw-btn alogw-btn--secondary" href="/admin/flight_training/logbooks/index.php">Back to Logbooks</a>
  </header>

  <?php if ($error !== ''): ?><div class="alogw-error"><?= h($error) ?></div><?php endif; ?>

  <section class="alogw-cards" id="alogTotalsCards"></section>

  <section class="alogw-panel">
    <div class="alogw-panel-head">
      <div>
        <h2 class="alogw-panel-title">Electronic Logbook</h2>
        <p class="alogw-panel-text">Upload source scans, attempt extraction, review candidate rows, then save accepted trusted entries.</p>
      </div>
      <span class="alogw-status" id="alogStatus">Ready</span>
    </div>
    <form class="alogw-import" id="alogUploadForm" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload_page">
      <input type="hidden" name="logbook_id" value="<?= (int)$logbookId ?>">
      <input class="alogw-input" type="file" name="image" accept="image/jpeg,image/png,image/webp">
      <button class="alogw-btn alogw-btn--primary" type="submit">Upload Logbook Page</button>
      <button class="alogw-btn alogw-btn--secondary" type="button" id="alogExtractPage">Import / Extract</button>
      <select class="alogw-select" id="alogPageSelect"></select>
      <button class="alogw-btn alogw-btn--ghost" type="button" id="alogViewImage">View Source Image</button>
      <button class="alogw-btn alogw-btn--ghost" type="button" id="alogCompareImage">Compare With Image</button>
      <span class="alogw-extract-status" id="alogExtractionStatus">Extraction status: no page</span>
    </form>
    <div class="alogw-grid-tools">
      <button class="alogw-btn alogw-btn--primary" type="button" id="alogAddRow">Add Row</button>
      <button class="alogw-btn alogw-btn--ghost" type="button" id="alogSaveRows">Save Changed Rows</button>
      <button class="alogw-btn alogw-btn--ghost" type="button" id="alogSplitRow">Split Selected</button>
      <button class="alogw-btn alogw-btn--ghost" type="button" id="alogMergeRows">Merge Selected</button>
      <button class="alogw-btn alogw-btn--ghost" type="button" id="alogFlagRows">Flag Selected</button>
      <button class="alogw-btn alogw-btn--danger" type="button" id="alogDeleteRows">Delete Selected</button>
      <select class="alogw-select" id="alogRequirementSelect"></select>
      <button class="alogw-btn alogw-btn--secondary" type="button" id="alogAssignRequirement">Assign Requirement</button>
    </div>
    <div class="alogw-table-wrap">
      <table class="alogw-table">
        <thead id="alogTableHead"></thead>
        <tbody id="alogTableBody"></tbody>
      </table>
    </div>
  </section>

  <section class="alogw-bottom">
    <div class="alogw-panel">
      <div class="alogw-panel-head">
        <div>
          <h2 class="alogw-panel-title">Requirement Verification</h2>
          <p class="alogw-panel-text">PASS/FAIL preview based on totals and explicit assignments.</p>
        </div>
      </div>
      <div class="alogw-req-list" id="alogRequirements"></div>
    </div>
    <div class="alogw-panel">
      <div class="alogw-panel-head">
        <div>
          <h2 class="alogw-panel-title">IACRA / FAA 8710 + Variables</h2>
          <p class="alogw-panel-text">Generated data for future FAA/EASA form auto-fill.</p>
        </div>
      </div>
      <div class="alogw-vars" id="alogVariables"></div>
    </div>
  </section>
</div>

<div class="alogw-modal" id="alogImageModal" aria-hidden="true">
  <div class="alogw-modal-card">
    <div class="alogw-modal-head">
      <div>
        <h2 class="alogw-panel-title">Source Logbook Image</h2>
        <p class="alogw-panel-text">Use as source evidence while reviewing extracted or edited rows.</p>
      </div>
      <div>
        <button class="alogw-btn alogw-btn--ghost" type="button" id="alogZoomOut">-</button>
        <button class="alogw-btn alogw-btn--ghost" type="button" id="alogZoomIn">+</button>
        <button class="alogw-btn alogw-btn--danger" type="button" id="alogCloseImage">Close</button>
      </div>
    </div>
    <div class="alogw-modal-body" id="alogImageBox"></div>
  </div>
</div>

<script>
window.IPCA_ADMIN_LOGBOOK = <?= $workspaceJson ?: '{}' ?>;
(function(){
  const api = '/admin/api/admin_logbook_api.php';
  const root = document.getElementById('alogWorkspace');
  const logbookId = Number(root.dataset.logbookId || 0);
  const statusEl = document.getElementById('alogStatus');
  let data = window.IPCA_ADMIN_LOGBOOK || {};
  let entries = (data.entries || []).map(row => ({...row, _dirty:false}));
  let zoom = 1;
  const cols = [
    ['select',''],['entry_date','Date'],['departure_airport','Dep'],['departure_time','Dep Time'],['arrival_airport','Arr'],['arrival_time','Arr Time'],
    ['aircraft_type','Type'],['aircraft_registration','Reg'],['single_engine_time','SE'],['multi_engine_time','ME'],['pic_time','PIC'],['copilot_time','Co-Pilot'],
    ['dual_received_time','Dual'],['solo_time','Solo'],['cross_country_time','XC'],['cross_country_distance_nm','XC NM'],['night_time','Night'],['instrument_time','Instr'],
    ['actual_instrument_time','Actual Instr'],['simulated_instrument_time','Sim Instr'],['basic_instrument_flying_time','Basic Instr'],['day_landings','Day Ldg'],
    ['night_landings','Night Ldg'],['towered_airport_landings','Towered Ldg'],['total_flight_time','Total'],['instructor_name','Instructor'],['remarks','Remarks'],
    ['endorsements','Endorsements'],['review_status','Review']
  ];
  const numeric = new Set(['single_engine_time','multi_engine_time','pic_time','copilot_time','dual_received_time','solo_time','cross_country_time','cross_country_distance_nm','night_time','instrument_time','actual_instrument_time','simulated_instrument_time','basic_instrument_flying_time','day_landings','night_landings','towered_airport_landings','total_flight_time']);
  const textareas = new Set(['remarks','endorsements']);
  function setStatus(msg){ statusEl.textContent = msg; }
  async function post(payload){
    const res = await fetch(api, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
    const json = await res.json();
    if(!json.ok){ throw new Error(json.error || 'Request failed'); }
    if(json.data){ data = json.data; entries = (data.entries || []).map(row => ({...row, _dirty:false})); renderAll(); }
    return json;
  }
  function esc(value){ return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])); }
  function renderAll(){ renderCards(); renderPages(); renderTable(); renderRequirements(); renderVariables(); }
  function renderCards(){
    const t = data.totals || {};
    const cards = [['Total',t.total_flight_time],['PIC',t.pic_time],['Dual',t.dual_received_time],['Solo',t.solo_time],['XC',t.cross_country_time],['Basic Instr',t.basic_instrument_flying_time],['Night',t.night_time],['Instr',t.instrument_time],['Day Ldg',t.day_landings],['Night Ldg',t.night_landings],['SE',t.single_engine_time],['ME',t.multi_engine_time]];
    document.getElementById('alogTotalsCards').innerHTML = cards.map(([label,value]) => `<div class="alogw-card"><div class="alogw-card-label">${esc(label)}</div><div class="alogw-card-value">${esc(value ?? 0)}</div></div>`).join('');
  }
  function renderPages(){
    const pages = data.pages || [];
    const select = document.getElementById('alogPageSelect');
    const previous = select.value;
    select.innerHTML = pages.length ? pages.map(p => `<option value="${esc(p.id)}"${String(p.id) === previous ? ' selected' : ''}>Page ${esc(p.page_number)}</option>`).join('') : '<option value="">No source pages</option>';
    renderExtractionStatus();
    renderImage();
  }
  function currentPage(){
    const id = document.getElementById('alogPageSelect').value;
    return (data.pages || []).find(p => String(p.id) === String(id)) || null;
  }
  function renderExtractionStatus(){
    const page = currentPage();
    const label = page ? `Extraction status: ${page.extraction_status || 'manual'}` : 'Extraction status: no page';
    document.getElementById('alogExtractionStatus').textContent = label;
  }
  function renderImage(){
    const page = currentPage();
    const url = page ? page.image_url : '';
    const box = document.getElementById('alogImageBox');
    box.innerHTML = url ? `<img src="${esc(url)}" style="transform:scale(${zoom})">` : '<div class="alogw-image-empty">No uploaded source image yet.<br>Upload scans, run import/extract, then review candidate rows in the logbook table.</div>';
  }
  function renderTable(){
    document.getElementById('alogTableHead').innerHTML = '<tr>' + cols.map(([,label]) => `<th>${esc(label)}</th>`).join('') + '</tr>';
    document.getElementById('alogTableBody').innerHTML = entries.map((row,i) => '<tr data-i="'+i+'">' + cols.map(([key]) => cell(row,i,key)).join('') + '</tr>').join('');
  }
  function cell(row,i,key){
    if(key === 'select') return `<td><input type="checkbox" data-select="${i}"></td>`;
    if(key === 'review_status') return `<td><select data-i="${i}" data-k="${key}"><option value="ok"${row[key]==='ok'?' selected':''}>ok</option><option value="flagged"${row[key]==='flagged'?' selected':''}>flagged</option><option value="merged"${row[key]==='merged'?' selected':''}>merged</option><option value="split"${row[key]==='split'?' selected':''}>split</option></select></td>`;
    if(textareas.has(key)) return `<td><textarea data-i="${i}" data-k="${key}">${esc(row[key] || '')}</textarea></td>`;
    const type = key === 'entry_date' ? 'date' : (key.includes('_time') && !numeric.has(key) ? 'time' : (numeric.has(key) ? 'number' : 'text'));
    const step = numeric.has(key) ? ' step="0.01"' : '';
    return `<td><input type="${type}"${step} data-i="${i}" data-k="${key}" value="${esc(row[key] || '')}"></td>`;
  }
  function renderRequirements(){
    const reqs = data.requirements || [];
    document.getElementById('alogRequirements').innerHTML = reqs.map(r => `<div class="alogw-req-row"><span>${esc(r.authority)}</span><strong>${esc(r.label)}</strong><span class="alogw-badge alogw-badge--${r.status === 'pass' ? 'pass':'fail'}">${esc(r.status)}</span></div>`).join('') || '<div class="alogw-image-empty">No requirement categories.</div>';
    const cats = data.requirement_categories || [];
    document.getElementById('alogRequirementSelect').innerHTML = cats.map(c => `<option value="${esc(c.id)}">${esc(c.authority)} ${esc(c.certificate)} - ${esc(c.label)}</option>`).join('');
  }
  function renderVariables(){
    const vars = data.variables || {};
    const iacra = data.iacra_8710 || {};
    const blocks = [];
    Object.keys(iacra).forEach(k => blocks.push([`iacra.${k}`, iacra[k]]));
    Object.keys(vars).sort().forEach(k => blocks.push([k, vars[k]]));
    document.getElementById('alogVariables').innerHTML = blocks.map(([k,v]) => `<div class="alogw-var">{{${esc(k)}}}: ${esc(v ?? '')}</div>`).join('');
  }
  function selectedIndexes(){ return [...document.querySelectorAll('[data-select]:checked')].map(el => Number(el.dataset.select)).filter(i => entries[i]); }
  document.addEventListener('input', e => {
    const el = e.target;
    if(el.dataset && el.dataset.k){
      const row = entries[Number(el.dataset.i)];
      row[el.dataset.k] = numeric.has(el.dataset.k) ? Number(el.value || 0) : el.value;
      row._dirty = true;
      setStatus('Unsaved changes');
    }
  });
  document.addEventListener('change', e => {
    if(e.target.id === 'alogPageSelect') { renderExtractionStatus(); renderImage(); }
  });
  function openImageModal(){
    document.getElementById('alogImageModal').classList.add('is-open');
    document.getElementById('alogImageModal').setAttribute('aria-hidden','false');
    renderImage();
  }
  function closeImageModal(){
    document.getElementById('alogImageModal').classList.remove('is-open');
    document.getElementById('alogImageModal').setAttribute('aria-hidden','true');
  }
  document.getElementById('alogViewImage').addEventListener('click', openImageModal);
  document.getElementById('alogCompareImage').addEventListener('click', openImageModal);
  document.getElementById('alogCloseImage').addEventListener('click', closeImageModal);
  document.getElementById('alogZoomIn').addEventListener('click', () => { zoom += .1; renderImage(); });
  document.getElementById('alogZoomOut').addEventListener('click', () => { zoom = Math.max(.4, zoom - .1); renderImage(); });
  document.getElementById('alogExtractPage').addEventListener('click', async () => {
    try {
      const page = currentPage();
      if(!page) { setStatus('Upload a source page first'); return; }
      setStatus('Running import/extract...');
      const json = await post({action:'attempt_extract_page', logbook_id:logbookId, page_id:page.id});
      const count = json.result ? Number(json.result.candidate_count || 0) : 0;
      setStatus(count > 0 ? `Imported ${count} candidate row${count === 1 ? '' : 's'} for review` : 'Extraction attempted: no candidate rows returned yet');
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogAddRow').addEventListener('click', () => { entries.push({review_status:'ok', _dirty:true}); renderTable(); setStatus('New row added'); });
  document.getElementById('alogSaveRows').addEventListener('click', async () => {
    try {
      setStatus('Saving...');
      for (const row of entries.filter(r => r._dirty)) {
        const copy = {...row}; delete copy._dirty;
        await post({action:'save_entry', logbook_id:logbookId, entry:copy});
      }
      setStatus('Saved');
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogDeleteRows').addEventListener('click', async () => {
    try { for (const i of selectedIndexes().reverse()) { if(entries[i].id){ await post({action:'delete_entry', logbook_id:logbookId, entry_id:entries[i].id}); } else { entries.splice(i,1); renderTable(); } } setStatus('Deleted'); } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogFlagRows').addEventListener('click', async () => {
    try {
      const ids = selectedIndexes().map(i => Number(entries[i].id || 0)).filter(Boolean);
      await post({action:'flag_entries', logbook_id:logbookId, entry_ids:ids});
      setStatus('Flagged selected rows');
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogSplitRow').addEventListener('click', async () => {
    try {
      const [i] = selectedIndexes();
      if(i === undefined || !entries[i].id) return;
      await post({action:'split_entry', logbook_id:logbookId, entry_id:entries[i].id});
      setStatus('Split selected row');
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogMergeRows').addEventListener('click', async () => {
    try {
      const ids = selectedIndexes().map(i => Number(entries[i].id || 0)).filter(Boolean);
      await post({action:'merge_entries', logbook_id:logbookId, entry_ids:ids});
      setStatus('Merged selected rows');
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogAssignRequirement').addEventListener('click', async () => {
    try {
      const ids = selectedIndexes().map(i => Number(entries[i].id || 0)).filter(Boolean);
      await post({action:'assign_requirement', logbook_id:logbookId, student_user_id:data.logbook.student_user_id, requirement_category_id:document.getElementById('alogRequirementSelect').value, entry_ids:ids});
      setStatus('Requirement assigned');
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogUploadForm').addEventListener('submit', async e => {
    e.preventDefault();
    try {
      setStatus('Uploading...');
      const res = await fetch(api, {method:'POST', body:new FormData(e.currentTarget)});
      const json = await res.json();
      if(!json.ok) throw new Error(json.error || 'Upload failed');
      data = json.data; entries = (data.entries || []).map(row => ({...row, _dirty:false})); renderAll(); setStatus('Uploaded');
    } catch(err){ setStatus(err.message); }
  });
  renderAll();
})();
</script>

<?php cw_footer(); ?>
