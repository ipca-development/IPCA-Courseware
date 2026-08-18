<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/safety/SafetyKernel.php';

cw_require_admin();
$user = cw_current_user($pdo);
$session = array('user' => (is_array($user) ? $user : array()) + array('organization_id' => 1));
$kernel = new SafetyKernel($pdo);
if (!$kernel->access->hasPermission($session, 'report.read_all')) {
    http_response_code(403);
    cw_header('Safety Management');
    echo '<div class="card" style="padding:24px"><h2>Safety workspace access required</h2>'
        . '<p>A current organization-scoped Safety role assignment is required. Contact a Safety administrator.</p></div>';
    cw_footer();
    exit;
}
if (empty($_SESSION['safety_staff_csrf'])) {
    $_SESSION['safety_staff_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['safety_staff_csrf'];

cw_header('Safety Management');
?>
<style>
.sms{--navy:#102d53;--blue:#245d9f;--ink:#17263a;--muted:#64758b;--line:#dce5ef;display:grid;gap:18px}
.sms-hero{padding:25px 27px;border-radius:20px;color:#fff;background:linear-gradient(135deg,#102d53,#245d9f 70%,#3978b7);box-shadow:0 18px 42px rgba(16,45,83,.2)}
.sms-hero-row,.sms-toolbar,.sms-head,.sms-actions{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}
.sms-overline{font-size:11px;font-weight:800;letter-spacing:.15em;text-transform:uppercase;opacity:.72}.sms-hero h2{font-size:30px;margin:7px 0 5px;letter-spacing:-.03em}.sms-hero p{max-width:800px;margin:0;color:#dceafb;line-height:1.55}
.sms-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}.sms-tab{border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.09);color:#fff;padding:9px 13px;border-radius:999px;font-weight:750;text-decoration:none;cursor:pointer}.sms-tab.is-active,.sms-tab:hover{background:#fff;color:var(--navy)}
.sms-grid{display:grid;grid-template-columns:repeat(4,minmax(155px,1fr));gap:13px}.sms-kpi,.sms-panel{background:#fff;border:1px solid var(--line);border-radius:17px;box-shadow:0 8px 24px rgba(15,35,60,.06)}
.sms-kpi{padding:18px}.sms-kpi-label{color:var(--muted);font-size:12px;font-weight:750;text-transform:uppercase;letter-spacing:.08em}.sms-kpi-value{font-size:32px;font-weight:850;color:var(--ink);margin-top:8px}.sms-panel{padding:20px}.sms-panel h3{margin:0;color:var(--ink);font-size:19px}.sms-sub{color:var(--muted);font-size:13px;line-height:1.5;margin-top:4px}
.sms-btn{appearance:none;border:0;border-radius:10px;padding:10px 13px;font-weight:750;cursor:pointer;background:var(--navy);color:#fff;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.sms-btn.secondary{background:#edf3f9;color:var(--navy)}.sms-btn.danger{background:#9b2c2c}.sms-btn.small{font-size:12px;padding:7px 10px}
.sms-input,.sms-select,.sms-textarea{width:100%;box-sizing:border-box;border:1px solid #cfdbe8;background:#fff;border-radius:10px;padding:10px 11px;color:var(--ink);font:inherit}.sms-textarea{min-height:86px;resize:vertical}.sms-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px;margin-top:14px}.sms-form .wide{grid-column:1/-1}.sms-form label{display:grid;gap:5px;color:#45566c;font-size:12px;font-weight:750}
.sms-table-wrap{overflow:auto}.sms-table{width:100%;border-collapse:collapse;margin-top:13px;font-size:13px}.sms-table th{text-align:left;color:#708197;text-transform:uppercase;letter-spacing:.06em;font-size:10px;padding:10px;border-bottom:1px solid var(--line)}.sms-table td{padding:11px 10px;border-bottom:1px solid #edf2f7;vertical-align:top}.sms-row{cursor:pointer}.sms-row:hover{background:#f7faff}
.sms-pill{display:inline-block;border-radius:999px;padding:5px 8px;background:#eaf1f8;color:#27496e;font-weight:800;font-size:10px;text-transform:uppercase;letter-spacing:.04em}.sms-pill.closed,.sms-pill.effective,.sms-pill.not_reportable{background:#dcf4e8;color:#17633b}.sms-pill.overdue,.sms-pill.ineffective,.sms-pill.reportable{background:#fee6e2;color:#8d2a22}.sms-pill.submitted,.sms-pill.triaged,.sms-pill.monitoring{background:#e4edff;color:#234e99}
.sms-detail-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(300px,.6fr);gap:16px}.sms-stack{display:grid;gap:14px}.sms-card{border:1px solid var(--line);border-radius:13px;padding:14px}.sms-card h4{margin:0 0 8px;color:var(--ink)}.sms-narrative{white-space:pre-wrap;line-height:1.6;color:#33465d}.sms-empty{padding:20px;text-align:center;color:var(--muted);border:1px dashed #cbd8e6;border-radius:13px}.sms-message{padding:10px 12px;border-radius:11px;background:#f1f6fb;margin:7px 0}.sms-message.from_reporter{background:#fff7e8}.sms-alert{display:none;padding:11px 13px;border-radius:11px}.sms-alert.show{display:block}.sms-alert.ok{background:#dff5e9;color:#195b38}.sms-alert.error{background:#fee9e6;color:#8c2c25}
.sms-modal{position:fixed;inset:0;background:rgba(10,25,43,.62);z-index:1000;display:none;align-items:center;justify-content:center;padding:20px}.sms-modal.open{display:flex}.sms-modal-box{background:#fff;border-radius:18px;max-width:680px;width:100%;max-height:90vh;overflow:auto;padding:22px}
@media(max-width:1000px){.sms-grid{grid-template-columns:repeat(2,1fr)}.sms-detail-grid{grid-template-columns:1fr}}@media(max-width:620px){.sms-grid,.sms-form{grid-template-columns:1fr}.sms-form .wide{grid-column:auto}}
</style>

<div class="sms">
  <section class="sms-hero">
    <div class="sms-hero-row">
      <div>
        <div class="sms-overline">Organization Safety Operations</div>
        <h2>Safety Management System</h2>
        <p>Confidential, traceable management of reports, occurrence decisions, hazards, investigations, actions and the reporter feedback loop.</p>
      </div>
      <a class="sms-btn secondary" href="/admin/compliance/safety_monitoring.php">Compliance monitoring →</a>
    </div>
    <nav class="sms-tabs" aria-label="Safety workspace">
      <button class="sms-tab is-active" data-view="dashboard">Dashboard</button>
      <button class="sms-tab" data-view="reports">Report queue</button>
      <button class="sms-tab" data-view="registers">Registers</button>
      <button class="sms-tab" data-view="bulletins">Bulletins</button>
    </nav>
  </section>
  <div id="smsAlert" class="sms-alert"></div>
  <main id="smsContent" aria-live="polite"><div class="sms-panel">Loading safety workspace…</div></main>
</div>

<div class="sms-modal" id="smsModal" role="dialog" aria-modal="true">
  <div class="sms-modal-box">
    <div class="sms-head"><h3 id="smsModalTitle">Safety operation</h3><button class="sms-btn secondary small" data-close>Close</button></div>
    <form id="smsModalForm" class="sms-form"></form>
  </div>
</div>

<script>
(() => {
  const apiUrl = '/admin/api/safety.php';
  const csrf = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>;
  const content = document.getElementById('smsContent');
  const alertBox = document.getElementById('smsAlert');
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const label = v => String(v || 'not set').replaceAll('_',' ').replace(/\b\w/g, c => c.toUpperCase());
  const pill = v => `<span class="sms-pill ${esc(v)}">${esc(label(v))}</span>`;
  const fmt = v => v ? esc(String(v).replace('T',' ').slice(0,16)) : '—';
  const show = (message, ok=false) => { alertBox.className = `sms-alert show ${ok?'ok':'error'}`; alertBox.textContent = message; };
  async function get(action, params={}) {
    const u = new URL(apiUrl, location.origin); u.searchParams.set('action', action);
    Object.entries(params).forEach(([k,v]) => v !== '' && v != null && u.searchParams.set(k,v));
    const r = await fetch(u, {credentials:'same-origin', headers:{Accept:'application/json'}});
    const j = await r.json(); if (!r.ok || !j.ok) throw new Error(j.error || 'Safety request failed.'); return j;
  }
  async function post(payload) {
    const r = await fetch(apiUrl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify({...payload,csrf_token:csrf})});
    const j = await r.json(); if (!r.ok || !j.ok) throw new Error(j.error || 'Safety operation failed.'); return j.result;
  }
  function table(headers, rows, rowClass='') {
    if (!rows.length) return '<div class="sms-empty">No records in this organization scope.</div>';
    return `<div class="sms-table-wrap"><table class="sms-table"><thead><tr>${headers.map(h=>`<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows.map(r=>`<tr class="${rowClass}">${r.join('')}</tr>`).join('')}</tbody></table></div>`;
  }
  function setActive(view) { document.querySelectorAll('.sms-tab').forEach(b=>b.classList.toggle('is-active',b.dataset.view===view)); }

  async function dashboard() {
    setActive('dashboard'); const d = await get('dashboard');
    const statusTotal = Object.fromEntries(d.reports_by_status.map(x=>[x.status,Number(x.total)]));
    const open = Object.entries(statusTotal).filter(([k])=>!['draft','closed','screened_out'].includes(k)).reduce((n,[,v])=>n+v,0);
    content.innerHTML = `<div class="sms-grid">
      ${[['Open reports',open],['Pending decisions',d.pending_reportability],['Open hazards',d.open_hazards],['Active investigations',d.active_investigations],['Open actions',d.open_actions],['Overdue actions',d.overdue_actions]].map(([l,v])=>`<div class="sms-kpi"><div class="sms-kpi-label">${l}</div><div class="sms-kpi-value">${v}</div></div>`).join('')}
    </div><section class="sms-panel"><div class="sms-head"><div><h3>Recent reports</h3><div class="sms-sub">Latest activity across authenticated and anonymous reporting channels.</div></div><button class="sms-btn secondary" data-go="reports">Open queue</button></div>
    ${reportTable(d.recent_reports)}</section>`;
    bindReportRows(); content.querySelector('[data-go]').onclick=reports;
  }
  function reportTable(rows) {
    return table(['Reference','Report','Channel','Status','Updated'], rows.map(r=>[
      `<td><strong>${esc(r.report_number || 'Unnumbered')}</strong></td>`,
      `<td>${esc(r.title)}<div class="sms-sub">${esc(label(r.category_code))}</div></td>`,
      `<td>${esc(label(r.channel))}</td><td>${pill(r.status)}</td><td>${fmt(r.updated_at_utc)}</td>`
    ].map((v,i)=>i===0?`<td data-uuid="${esc(r.report_uuid)}">${v.replace(/^<td>|<\/td>$/g,'')}</td>`:v)), 'sms-row');
  }
  function bindReportRows(){ content.querySelectorAll('[data-uuid]').forEach(td=>td.closest('tr').onclick=()=>reportDetail(td.dataset.uuid)); }
  async function reports() {
    setActive('reports');
    content.innerHTML = `<section class="sms-panel"><div class="sms-head"><div><h3>Report queue</h3><div class="sms-sub">Organization-scoped triage queue. Anonymous entries contain no identity linkage.</div></div></div>
      <form id="queueFilter" class="sms-toolbar" style="margin-top:14px"><input class="sms-input" style="max-width:360px" name="q" placeholder="Search reference, title or category"><select class="sms-select" style="max-width:220px" name="status"><option value="">All statuses</option>${['submitted','triaged','under_investigation','actioning','monitoring','returned','screened_out','closed','reopened'].map(s=>`<option>${s}</option>`).join('')}</select><button class="sms-btn">Filter</button></form><div id="queueRows"></div></section>`;
    const load=async()=>{const f=new FormData(document.getElementById('queueFilter'));const d=await get('reports',Object.fromEntries(f));document.getElementById('queueRows').innerHTML=reportTable(d.reports);bindReportRows();};
    document.getElementById('queueFilter').onsubmit=e=>{e.preventDefault();load().catch(x=>show(x.message));}; await load();
  }
  async function reportDetail(uuid) {
    setActive('reports'); const d=await get('report',{report_uuid:uuid}), r=d.report;
    const cards = (title, rows, render) => `<div class="sms-card"><h4>${title}</h4>${rows.length?rows.map(render).join(''):'<div class="sms-sub">None recorded.</div>'}</div>`;
    content.innerHTML = `<section class="sms-panel"><div class="sms-head"><div><button class="sms-btn secondary small" id="backQueue">← Queue</button><h3 style="margin-top:12px">${esc(r.report_number || 'Unnumbered report')} · ${esc(r.title)}</h3><div class="sms-sub">${pill(r.status)} &nbsp; ${esc(label(r.channel))} channel · ${fmt(r.submitted_at_utc)}</div></div><button class="sms-btn" data-op="transition">Change status</button></div>
    <div class="sms-detail-grid" style="margin-top:16px"><div class="sms-stack">
      <div class="sms-card"><h4>Report narrative</h4><div class="sms-narrative">${esc(r.narrative)}</div><div class="sms-sub" style="margin-top:12px">${esc(r.location_text||'Location not supplied')} · ${esc(r.aircraft_registration||'No aircraft')} · Confidentiality: ${esc(label(r.confidentiality))}</div></div>
      ${cards('Occurrence & reportability',r.occurrences,o=>`<div class="sms-message"><strong>${esc(label(o.occurrence_type))}</strong> ${o.decision?pill(o.decision):pill('pending')}<div class="sms-sub">${esc(o.framework_code||'Not assessed')} · ${esc(o.rationale||'')}</div></div>`)}
      ${cards('Hazards & risk',r.hazards,h=>`<div class="sms-message"><strong>${esc(h.title)}</strong> ${pill(h.hazard_status)} ${h.band_code?pill(h.band_code):''}<div class="sms-sub">${esc(h.description)} · Latest risk: ${esc(h.score||'—')} ${h.accepted_at_utc?'· accepted':''}</div></div>`)}
      ${cards('Investigations',r.investigations,i=>`<div class="sms-message"><strong>${esc(i.investigation_uuid)}</strong> ${pill(i.status)}<div class="sms-sub">${esc(i.scope_text)} · ${i.factor_count} factor(s)</div></div>`)}
      ${cards('Actions & effectiveness',r.actions,a=>`<div class="sms-message"><strong>${esc(a.title)}</strong> ${pill(a.status)}<div class="sms-sub">Owner #${esc(a.owner_user_id)} · Due ${fmt(a.due_at_utc)} · ${a.evidence_count} evidence item(s)</div></div>`)}
      ${cards('Reporter feedback',r.updates,u=>`<div class="sms-message ${esc(u.direction)}"><strong>${u.direction==='to_reporter'?'Safety team':'Reporter'}</strong><div>${esc(u.body)}</div><div class="sms-sub">${fmt(u.created_at_utc)}</div></div>`)}
    </div><aside class="sms-stack">
      <div class="sms-card"><h4>Workflow operations</h4><div class="sms-actions">
        <button class="sms-btn secondary small" data-op="occurrence">Add occurrence</button><button class="sms-btn secondary small" data-op="reportability">Reportability</button>
        <button class="sms-btn secondary small" data-op="hazard">Add hazard</button><button class="sms-btn secondary small" data-op="risk">Assess risk</button><button class="sms-btn secondary small" data-op="accept-risk">Accept residual risk</button>
        <button class="sms-btn secondary small" data-op="investigation">Open investigation</button><button class="sms-btn secondary small" data-op="factor">Add factor</button>
        <button class="sms-btn secondary small" data-op="complete-investigation">Complete investigation</button><button class="sms-btn secondary small" data-op="action">Add action</button>
        <button class="sms-btn secondary small" data-op="evidence">Action evidence</button><button class="sms-btn secondary small" data-op="effectiveness">Effectiveness review</button>
        <button class="sms-btn secondary small" data-op="close-action">Close action</button><button class="sms-btn secondary small" data-op="feedback">Send feedback</button>
      </div></div><div class="sms-card"><h4>Audit trail</h4>${r.events.slice(0,12).map(e=>`<div class="sms-sub" style="padding:6px 0;border-bottom:1px solid #edf2f7">${fmt(e.occurred_at_utc)} · ${esc(label(e.event_type))}</div>`).join('')}</div>
    </aside></div></section>`;
    document.getElementById('backQueue').onclick=reports;
    content.querySelectorAll('[data-op]').forEach(b=>b.onclick=()=>openOperation(b.dataset.op,r));
  }
  const field=(name,labelText,type='text',options=[],wide=false)=>`<label class="${wide?'wide':''}">${esc(labelText)}${type==='textarea'?`<textarea class="sms-textarea" name="${name}" required></textarea>`:type==='select'?`<select class="sms-select" name="${name}" required>${options.map(x=>`<option value="${esc(x)}">${esc(label(x))}</option>`).join('')}</select>`:`<input class="sms-input" name="${name}" type="${type}" ${type==='number'?'min="1"':''} required>`}</label>`;
  function openOperation(op,r) {
    const modal=document.getElementById('smsModal'), form=document.getElementById('smsModalForm'), title=document.getElementById('smsModalTitle');
    const first=(xs)=>xs.length?xs[0]:{}; let action='',html='',name=label(op);
    if(op==='transition'){action='transition';html=field('target','Target status','select',['triaged','returned','screened_out','under_investigation','actioning','monitoring','closed','reopened'])+field('rationale','Decision rationale','textarea',[],true)}
    if(op==='occurrence'){action='create_occurrence';html=field('occurrence_type','Occurrence type')+field('occurred_at_utc','Occurred at UTC','datetime-local')}
    if(op==='reportability'){action='assess_reportability';html=field('occurrence_id','Occurrence ID','number')+field('framework','Framework code')+field('decision','Decision','select',['reportable','not_reportable','pending_information'])+field('deadline_at_utc','Authority deadline','datetime-local')+field('rationale','Rationale','textarea',[],true)}
    if(op==='hazard'){action='create_hazard';html=field('title','Hazard title')+field('description','Hazard description','textarea',[],true)}
    if(op==='risk'){action='assess_risk';html=field('hazard_id','Hazard ID','number')+field('matrix_version_id','Matrix version ID','number')+field('phase','Phase','select',['initial','current','residual'])+field('likelihood','Likelihood code')+field('severity','Severity code')+field('rationale','Assessment rationale','textarea',[],true)}
    if(op==='accept-risk'){action='accept_risk';html=field('snapshot_id','Residual risk snapshot ID','number')+field('rationale','Acceptance rationale','textarea',[],true)}
    if(op==='investigation'){action='open_investigation';html=field('lead_user_id','Lead user ID','number')+field('scope','Investigation scope','textarea',[],true)}
    if(op==='factor'){action='add_factor';html=field('investigation_id','Investigation ID','number')+field('factor_type','Factor type')+field('causal_role','Causal role')+field('statement','Factor statement','textarea',[],true)}
    if(op==='complete-investigation'){action='complete_investigation';html=field('investigation_id','Investigation ID','number')+field('conclusion','Conclusion','textarea',[],true)}
    if(op==='action'){action='create_action';html=field('title','Action title')+field('owner_user_id','Owner user ID','number')+field('due_at_utc','Due at UTC','datetime-local')+field('description','Action description','textarea',[],true)}
    if(op==='evidence'){action='add_action_evidence';html=field('action_id','Action ID','number')+field('note','Evidence note','textarea',[],true)}
    if(op==='effectiveness'){action='review_effectiveness';html=field('action_id','Action ID','number')+field('outcome','Outcome','select',['effective','partially_effective','ineffective','inconclusive'])+field('method','Review method','textarea',[],true)+field('result','Result','textarea',[],true)}
    if(op==='close-action'){action='close_action';html=field('action_id','Action ID','number')+field('review_id','Effectiveness review ID','number')+field('rationale','Closure rationale','textarea',[],true)}
    if(op==='feedback'){action='send_feedback';html=field('body','Message visible to reporter','textarea',[],true)}
    title.textContent=name; form.innerHTML=html+`<div class="wide sms-actions"><button class="sms-btn" type="submit">Complete operation</button></div>`;
    const defaults={occurrence_id:first(r.occurrences).id,hazard_id:first(r.hazards).id,snapshot_id:first(r.hazards).risk_snapshot_id,investigation_id:first(r.investigations).id,action_id:first(r.actions).id,review_id:first(r.actions).latest_review_id};
    Object.entries(defaults).forEach(([k,v])=>{if(v&&form.elements[k])form.elements[k].value=v});
    form.onsubmit=async e=>{e.preventDefault();const data=Object.fromEntries(new FormData(form));data.action=action;data.report_uuid=r.report_uuid;data.report_id=r.id;data.source_report_id=r.id;data.source_id=r.id;data.source_type='report';try{await post(data);modal.classList.remove('open');show('Safety operation recorded.',true);await reportDetail(r.report_uuid)}catch(x){show(x.message)}};
    modal.classList.add('open');
  }
  async function registers() {
    setActive('registers'); const [d,matrix]=await Promise.all([get('registers'),get('risk_matrix')]);
    content.innerHTML=`<div class="sms-stack">
      <section class="sms-panel"><h3>Hazard & risk register</h3>${table(['Hazard','Status','Latest risk','Accepted'],d.hazards.map(x=>[`<td><strong>${esc(x.title)}</strong><div class="sms-sub">${esc(x.hazard_uuid)}</div></td>`,`<td>${pill(x.hazard_status)}</td>`,`<td>${x.band_code?pill(x.band_code):'—'} ${esc(x.score||'')}</td>`,`<td>${fmt(x.accepted_at_utc)}</td>`]))}</section>
      <section class="sms-panel"><h3>Active risk matrix</h3><div class="sms-sub">Use the matrix version and dimension codes shown here when recording a risk snapshot.</div>${table(['Version','Likelihood','Severity','Score','Band'],matrix.cells.map(x=>[`<td>#${esc(x.matrix_version_id)} · v${esc(x.version_number)}</td>`,`<td>${esc(x.likelihood_code)}</td>`,`<td>${esc(x.severity_code)}</td>`,`<td>${esc(x.score)}</td>`,`<td>${pill(x.band_code)}</td>`]))}</section>
      <section class="sms-panel"><h3>Occurrence & reportability register</h3>${table(['Occurrence','Source report','Decision','Deadline'],d.occurrences.map(x=>[`<td>${esc(label(x.occurrence_type))}</td>`,`<td>${esc(x.report_number||x.report_title)}</td>`,`<td>${x.decision?pill(x.decision):pill('pending')}</td>`,`<td>${fmt(x.deadline_at_utc)}</td>`]))}</section>
      <section class="sms-panel"><h3>Investigation register</h3>${table(['Investigation','Report','Status','Completed'],d.investigations.map(x=>[`<td>${esc(x.investigation_uuid)}</td>`,`<td>${esc(x.report_number||x.report_title)}</td>`,`<td>${pill(x.status)}</td>`,`<td>${fmt(x.completed_at_utc)}</td>`]))}</section>
      <section class="sms-panel"><h3>Action & effectiveness register</h3>${table(['Action','Owner','Due','Status'],d.actions.map(x=>[`<td><strong>${esc(x.title)}</strong></td>`,`<td>#${esc(x.owner_user_id)}</td>`,`<td>${fmt(x.due_at_utc)}</td>`,`<td>${pill(x.status)}</td>`]))}</section></div>`;
  }
  async function bulletins() {
    setActive('bulletins'); const d=await get('bulletins');
    content.innerHTML=`<section class="sms-panel"><div class="sms-head"><div><h3>Safety bulletins</h3><div class="sms-sub">Controlled safety communication with publication and acknowledgement evidence.</div></div><button class="sms-btn" id="newBulletin">New bulletin</button></div>
    ${table(['Bulletin','Status','Acknowledgement','Published','Action'],d.bulletins.map(x=>[`<td><strong>${esc(x.title)}</strong><div class="sms-sub">${esc(x.body.slice(0,150))}</div></td>`,`<td>${pill(x.status)}</td>`,`<td>${Number(x.requires_acknowledgement)?`${esc(x.acknowledgement_count)} recorded`:'Not required'}</td>`,`<td>${fmt(x.published_at_utc)}</td>`,`<td>${x.status==='draft'?`<button class="sms-btn small" data-publish="${esc(x.bulletin_uuid)}">Publish</button>`:'—'}</td>`]))}</section>`;
    document.getElementById('newBulletin').onclick=()=>openBulletin();
    content.querySelectorAll('[data-publish]').forEach(b=>b.onclick=async()=>{try{await post({action:'publish_bulletin',bulletin_uuid:b.dataset.publish});show('Bulletin published.',true);bulletins()}catch(x){show(x.message)}});
  }
  function openBulletin(){
    const m=document.getElementById('smsModal'),f=document.getElementById('smsModalForm');document.getElementById('smsModalTitle').textContent='New safety bulletin';
    f.innerHTML=field('title','Title')+field('expires_at_utc','Expires at UTC','datetime-local')+field('body','Bulletin body','textarea',[],true)+`<label class="wide"><span><input type="checkbox" name="requires_acknowledgement" value="1"> Require acknowledgement</span></label><div class="wide"><button class="sms-btn">Save draft</button></div>`;
    f.onsubmit=async e=>{e.preventDefault();try{await post({action:'create_bulletin',audience:{roles:['student','instructor']},...Object.fromEntries(new FormData(f))});m.classList.remove('open');show('Bulletin draft created.',true);bulletins()}catch(x){show(x.message)}};m.classList.add('open');
  }
  document.querySelectorAll('.sms-tab').forEach(b=>b.onclick=()=>({dashboard,reports,registers,bulletins}[b.dataset.view]().catch(x=>show(x.message))));
  document.querySelectorAll('[data-close]').forEach(b=>b.onclick=()=>document.getElementById('smsModal').classList.remove('open'));
  document.getElementById('smsModal').onclick=e=>{if(e.target===e.currentTarget)e.currentTarget.classList.remove('open')};
  dashboard().catch(e=>show(e.message));
})();
</script>
<?php cw_footer(); ?>
