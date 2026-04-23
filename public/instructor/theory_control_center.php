<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');

$allowedRoles = array('admin', 'supervisor', 'instructor', 'chief_instructor');

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('Forbidden');
}

cw_header('Instructor Theory Control Center');
?>

<style>
.tcc-page{display:flex;flex-direction:column;gap:18px;padding-bottom:24px}
.tcc-card{background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:22px;box-shadow:0 14px 30px rgba(15,23,42,.06)}
.tcc-hero{padding:20px 22px;background:linear-gradient(135deg,#0f2745 0%,#1d4f89 100%);color:#fff;overflow:hidden}
.tcc-hero-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-end}
.tcc-kicker{font-size:11px;text-transform:uppercase;letter-spacing:.16em;font-weight:900;color:rgba(255,255,255,.72)}
.tcc-hero h1{margin:6px 0 0;font-size:30px;line-height:1.02;letter-spacing:-.04em;color:#fff}
.tcc-hero-sub{margin-top:8px;font-size:13px;line-height:1.55;color:rgba(255,255,255,.84);max-width:860px}
.tcc-toolbar{display:flex;align-items:flex-end;gap:10px;flex:0 0 auto}
.tcc-field{display:flex;flex-direction:column;gap:6px;min-width:300px}
.tcc-field label{font-size:11px;font-weight:800;color:rgba(255,255,255,.78);letter-spacing:.12em;text-transform:uppercase}
.tcc-select{min-height:44px;border-radius:14px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.96);color:#102845;padding:10px 12px;font:inherit;font-size:14px;font-weight:400;outline:none}
.tcc-select:focus{box-shadow:0 0 0 3px rgba(255,255,255,.22)}
.tcc-section{padding:18px 20px}
.tcc-section-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:14px}
.tcc-section-title{margin:0;font-size:20px;line-height:1.1;font-weight:900;color:#102845;letter-spacing:-.03em}
.tcc-section-sub{margin-top:5px;font-size:13px;color:#64748b;line-height:1.4}
.tcc-count-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:7px 11px;background:#edf4ff;color:#1d4f91;font-size:12px;font-weight:800;border:1px solid #d3e3ff;white-space:nowrap}
.tcc-health-strip{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px}
.tcc-health-card{border-radius:17px;background:linear-gradient(180deg,#fbfdff 0%,#f8fafc 100%);border:1px solid rgba(15,23,42,.07);padding:15px 16px;min-width:0}
.tcc-health-label{font-size:10px;color:#64748b;font-weight:900;text-transform:uppercase;letter-spacing:.13em;white-space:nowrap}
.tcc-health-value{margin-top:8px;font-size:30px;line-height:1;font-weight:900;color:#102845;letter-spacing:-.04em}
.tcc-health-note{margin-top:7px;font-size:12px;color:#64748b;line-height:1.35}
.tcc-radar-wrap{position:relative;min-height:138px;margin-top:4px;padding:30px 28px 42px;border-radius:20px;background:linear-gradient(180deg,#fbfdff 0%,#f8fafc 100%);border:1px solid rgba(15,23,42,.06);overflow:hidden}
.tcc-radar-line{position:absolute;top:64px;left:34px;right:34px;height:8px;background:linear-gradient(90deg,#dbeafe 0%,#bfdbfe 35%,#d1fae5 100%);border-radius:999px}
.tcc-radar-marker{position:absolute;top:82px;font-size:10px;color:#64748b;font-weight:900;white-space:nowrap}.tcc-radar-marker.start{left:34px}.tcc-radar-marker.end{right:34px}
.tcc-radar-avatar{position:absolute;top:64px;transform:translate(-50%,-50%);width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;color:#fff;font-size:12px;cursor:pointer;box-shadow:0 8px 20px rgba(15,23,42,.22);border:3px solid #fff;transition:transform .15s ease,box-shadow .15s ease;user-select:none}
.tcc-radar-avatar:hover{transform:translate(-50%,-50%) scale(1.05);box-shadow:0 12px 26px rgba(15,23,42,.30)}
.tcc-radar-avatar.selected{outline:3px solid rgba(18,53,95,.28);outline-offset:4px}
.tcc-radar-avatar.green{background:#16a34a}.tcc-radar-avatar.orange{background:#f59e0b}.tcc-radar-avatar.red{background:#dc2626}.tcc-radar-avatar.blue{background:#2563eb}.tcc-radar-avatar.purple{background:#7c3aed}
.tcc-radar-score{position:absolute;top:-26px;left:50%;transform:translateX(-50%);font-size:10px;font-weight:900;color:#334155;background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:999px;padding:3px 7px;white-space:nowrap}
.tcc-radar-label{position:absolute;top:51px;left:50%;transform:translateX(-50%);font-size:11px;font-weight:900;color:#334155;white-space:nowrap;max-width:120px;overflow:hidden;text-overflow:ellipsis}
.tcc-radar-legend{display:flex;flex-wrap:wrap;gap:8px;margin-top:13px}.tcc-legend-pill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:6px 9px;background:#f8fafc;border:1px solid rgba(15,23,42,.06);font-size:11px;font-weight:800;color:#475569}.tcc-dot{width:9px;height:9px;border-radius:999px;display:inline-block}.tcc-dot.green{background:#16a34a}.tcc-dot.orange{background:#f59e0b}.tcc-dot.red{background:#dc2626}.tcc-dot.blue{background:#2563eb}.tcc-dot.purple{background:#7c3aed}
.tcc-student-placeholder{padding:18px;border-radius:18px;border:1px dashed rgba(15,23,42,.16);background:#fbfdff;color:#64748b;font-size:14px;line-height:1.5}
.tcc-student-head{display:flex;align-items:center;gap:14px;margin-bottom:14px}.tcc-student-avatar{width:58px;height:58px;border-radius:20px;background:linear-gradient(135deg,#0f2745 0%,#1d4f89 100%);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:17px;flex:0 0 auto}.tcc-student-name{font-size:24px;line-height:1.05;font-weight:900;color:#102845;letter-spacing:-.03em}.tcc-student-email{margin-top:5px;font-size:13px;color:#64748b}.tcc-student-state-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.tcc-status-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:7px 10px;background:#f8fafc;border:1px solid rgba(15,23,42,.08);font-size:12px;font-weight:900;color:#334155}.tcc-status-pill.strong{background:#ecfdf5;color:#166534;border-color:#bbf7d0}.tcc-status-pill.stable{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}.tcc-status-pill.drifting{background:#fef3c7;color:#92400e;border-color:#fde68a}.tcc-status-pill.needs_contact{background:#fee2e2;color:#991b1b;border-color:#fca5a5}
.tcc-snapshot-grid{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px;margin-bottom:16px}.tcc-snapshot-tile{border-radius:17px;background:#f8fafc;border:1px solid rgba(15,23,42,.07);padding:14px 15px;min-width:0}.tcc-snapshot-label{font-size:10px;text-transform:uppercase;letter-spacing:.12em;font-weight:900;color:#64748b}.tcc-snapshot-value{margin-top:8px;font-size:28px;font-weight:900;line-height:1;color:#102845;letter-spacing:-.04em}.tcc-snapshot-sub{margin-top:8px;font-size:12px;color:#64748b;line-height:1.4}.tcc-mini-bar{height:9px;border-radius:999px;background:#e5eef7;overflow:hidden;margin-top:10px}.tcc-mini-bar span{display:block;height:100%;background:linear-gradient(90deg,#12355f 0%,#2b6dcc 100%);border-radius:999px}
.tcc-issues-list{display:flex;flex-direction:column;gap:9px}.tcc-issue-row{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:11px 12px;border-radius:15px;background:#fbfdff;border:1px solid rgba(15,23,42,.06)}.tcc-issue-title{font-size:13px;font-weight:900;color:#102845;line-height:1.35}.tcc-issue-meta{margin-top:4px;font-size:12px;color:#64748b;line-height:1.35}.tcc-panel-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.tcc-queue{display:flex;flex-direction:column;gap:12px}.tcc-item{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:14px;border-radius:16px;background:#f8fbfd;border:1px solid rgba(15,23,42,.06)}.tcc-item-left{display:flex;gap:12px;align-items:center;min-width:0}.tcc-avatar{width:44px;height:44px;border-radius:50%;background:#12355f;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;flex:0 0 auto}.tcc-meta{display:flex;flex-direction:column;min-width:0}.tcc-name{font-weight:900;color:#102845;line-height:1.25}.tcc-sub{font-size:12px;color:#64748b;line-height:1.35;overflow:hidden;text-overflow:ellipsis}.tcc-severity{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:4px 8px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;margin-top:6px;align-self:flex-start}.tcc-severity.high{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}.tcc-severity.medium{background:#fef3c7;color:#92400e;border:1px solid #fde68a}.tcc-severity.low{background:#e0f2fe;color:#075985;border:1px solid #bae6fd}.tcc-actions{display:flex;gap:8px;flex:0 0 auto}.tcc-btn{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 12px;border-radius:11px;font-size:12px;font-weight:900;border:1px solid rgba(15,23,42,.08);cursor:pointer;text-decoration:none}.tcc-btn.primary{background:#12355f;color:#fff;border-color:#12355f}.tcc-btn.warn{background:#fff7ed;color:#92400e;border-color:#fed7aa}.tcc-btn.secondary{background:#f1f5f9;color:#334155}.tcc-empty,.tcc-loading{padding:16px;border-radius:16px;border:1px dashed rgba(15,23,42,.15);background:#fbfdff;color:#64748b;font-size:14px}.tcc-error{padding:14px;border-radius:14px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;font-size:14px}
@media (max-width:1100px){.tcc-hero-head{flex-direction:column;align-items:stretch}.tcc-toolbar{width:100%}.tcc-field{min-width:0;width:100%}.tcc-health-strip,.tcc-snapshot-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:700px){.tcc-hero{padding:18px}.tcc-hero h1{font-size:25px}.tcc-health-strip,.tcc-snapshot-grid{grid-template-columns:1fr}.tcc-radar-wrap{min-height:190px;padding-left:18px;padding-right:18px;overflow-x:auto}.tcc-radar-line{left:24px;right:24px}.tcc-item{align-items:flex-start;flex-direction:column}.tcc-actions{width:100%;justify-content:flex-start}.tcc-student-name{font-size:21px}}
</style>

<div class="tcc-page">
    <section class="tcc-card tcc-hero">
        <div class="tcc-hero-head">
            <div>
                <div class="tcc-kicker">Instructor Workspace</div>
                <h1>Instructor Theory Control Center</h1>
                <div class="tcc-hero-sub">Live action queue, cohort radar, and risk visibility for keeping theory progression moving with minimal instructor workload and maximum traceability.</div>
            </div>
            <div class="tcc-toolbar">
                <div class="tcc-field">
                    <label for="cohortSelect">Cohort</label>
                    <select id="cohortSelect" class="tcc-select"></select>
                </div>
            </div>
        </div>
    </section>

    <section class="tcc-card tcc-section">
        <div class="tcc-section-head">
            <div>
                <h2 class="tcc-section-title">Cohort Health</h2>
                <div class="tcc-section-sub">High-level operational status for the selected cohort.</div>
            </div>
            <div id="healthCount" class="tcc-count-pill">—</div>
        </div>
        <div id="healthStrip" class="tcc-health-strip">
            <div class="tcc-loading">Loading cohort health…</div>
        </div>
    </section>

    <section class="tcc-card tcc-section">
        <div class="tcc-section-head">
            <div>
                <h2 class="tcc-section-title">Cohort Radar</h2>
                <div class="tcc-section-sub">Student position and risk state across the theory program. Tap an avatar to open the student deep dive below.</div>
            </div>
            <div id="radarCount" class="tcc-count-pill">—</div>
        </div>
        <div class="tcc-radar-wrap">
            <div class="tcc-radar-line"></div>
            <div class="tcc-radar-marker start">Start</div>
            <div class="tcc-radar-marker end">Complete</div>
            <div id="radarAvatars"></div>
        </div>
        <div class="tcc-radar-legend">
            <span class="tcc-legend-pill"><span class="tcc-dot green"></span>On track</span>
            <span class="tcc-legend-pill"><span class="tcc-dot orange"></span>At risk</span>
            <span class="tcc-legend-pill"><span class="tcc-dot red"></span>Blocked</span>
            <span class="tcc-legend-pill"><span class="tcc-dot blue"></span>Action pending</span>
            <span class="tcc-legend-pill"><span class="tcc-dot purple"></span>System watch</span>
        </div>
    </section>

    <section class="tcc-card tcc-section">
        <div class="tcc-section-head">
            <div>
                <h2 class="tcc-section-title">Student Deep Dive</h2>
                <div class="tcc-section-sub">Snapshot of the selected student's progress, friction points, and comparative indicators.</div>
            </div>
            <div id="studentPanelCount" class="tcc-count-pill">Select student</div>
        </div>
        <div id="studentPanel" class="tcc-student-placeholder">Tap a student avatar in the Cohort Radar, or tap a student in the Action Queue, to open the student deep dive here.</div>
    </section>

    <section class="tcc-card tcc-section">
        <div class="tcc-section-head">
            <div>
                <h2 class="tcc-section-title">Needs My Action</h2>
                <div class="tcc-section-sub">Instructor-facing queue of open required actions. This remains last so the page opens with cohort context first.</div>
            </div>
            <div id="queueCount" class="tcc-count-pill">—</div>
        </div>
        <div id="actionQueue" class="tcc-queue">
            <div class="tcc-loading">Loading action queue…</div>
        </div>
    </section>
</div>

<script>
(function(){
'use strict';
var cohortId=0;
var selectedStudentId=0;
function escapeHtml(value){return String(value===null||value===undefined?'':value).replace(/[&<>'"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c];});}
function api(action,params){params=params||{};params.action=action;return fetch('/instructor/api/theory_control_center_api.php?'+new URLSearchParams(params),{credentials:'same-origin'}).then(function(r){return r.json();});}
function showError(id,msg){var el=document.getElementById(id);if(el){el.innerHTML='<div class="tcc-error">'+escapeHtml(msg)+'</div>';}}
function loadCohorts(){api('cohort_overview').then(function(data){var select=document.getElementById('cohortSelect');select.innerHTML='';if(!data.ok||!data.cohorts||!data.cohorts.length){select.innerHTML='<option value="0">No cohorts available</option>';return;}data.cohorts.forEach(function(c){var opt=document.createElement('option');opt.value=c.id;opt.textContent=c.name;select.appendChild(opt);});cohortId=parseInt(data.cohorts[0].id,10)||0;select.value=cohortId;loadAll();}).catch(function(){showError('healthStrip','Unable to load cohorts.');});}
function loadAll(){selectedStudentId=0;document.getElementById('studentPanelCount').textContent='Select student';document.getElementById('studentPanel').className='tcc-student-placeholder';document.getElementById('studentPanel').innerHTML='Tap a student avatar in the Cohort Radar, or tap a student in the Action Queue, to open the student deep dive here.';loadOverview();loadQueue();}
function loadOverview(){document.getElementById('healthStrip').innerHTML='<div class="tcc-loading">Loading cohort health…</div>';document.getElementById('healthCount').textContent='—';document.getElementById('radarAvatars').innerHTML='';document.getElementById('radarCount').textContent='—';api('cohort_overview',{cohort_id:cohortId}).then(function(data){if(!data.ok){showError('healthStrip',data.message||data.error||'Unable to load cohort overview.');return;}renderHealth(data);renderRadar(data);}).catch(function(){showError('healthStrip','Unable to load cohort overview.');});}
function renderHealth(data){var s=data.summary||{};document.getElementById('healthCount').textContent=(s.student_count||0)+' students';document.getElementById('healthStrip').innerHTML='<div class="tcc-health-card"><div class="tcc-health-label">Students</div><div class="tcc-health-value">'+escapeHtml(s.student_count||0)+'</div><div class="tcc-health-note">Active in this cohort</div></div><div class="tcc-health-card"><div class="tcc-health-label">On Track</div><div class="tcc-health-value">'+escapeHtml(s.on_track_count||0)+'</div><div class="tcc-health-note">No immediate action</div></div><div class="tcc-health-card"><div class="tcc-health-label">At Risk</div><div class="tcc-health-value">'+escapeHtml(s.at_risk_count||0)+'</div><div class="tcc-health-note">Needs monitoring</div></div><div class="tcc-health-card"><div class="tcc-health-label">Blocked</div><div class="tcc-health-value">'+escapeHtml(s.blocked_count||0)+'</div><div class="tcc-health-note">Action required</div></div>';}
function radarColor(s){if(s.state==='blocked')return'red';if(s.pending_action_count&&parseInt(s.pending_action_count,10)>0)return'blue';if(s.state==='at_risk')return'orange';return'green';}
function renderRadar(data){var container=document.getElementById('radarAvatars');var count=document.getElementById('radarCount');var students=data.students||[];container.innerHTML='';count.textContent=students.length+' student'+(students.length===1?'':'s');if(!students.length){container.innerHTML='<div class="tcc-empty">No students found for this cohort.</div>';return;}students.forEach(function(s,idx){var progress=parseInt(s.progress_pct,10);if(isNaN(progress))progress=0;if(progress<0)progress=0;if(progress>100)progress=100;var verticalNudge=(idx%3)*6;var el=document.createElement('div');el.className='tcc-radar-avatar '+radarColor(s);el.style.left=progress+'%';el.style.top=(64+verticalNudge)+'px';el.title=s.name+' · '+progress+'% · '+s.state;el.setAttribute('role','button');el.setAttribute('tabindex','0');el.setAttribute('aria-label',s.name+' progress '+progress+' percent');el.setAttribute('data-student-id',s.student_id||0);el.innerHTML='<span class="tcc-radar-score">'+progress+'%</span>'+escapeHtml(s.avatar_initials||'S')+'<span class="tcc-radar-label">'+escapeHtml((s.name||'Student').split(' ')[0])+'</span>';el.onclick=function(){loadStudentPanel(s.student_id);};el.onkeydown=function(ev){if(ev.key==='Enter'||ev.key===' '){ev.preventDefault();el.click();}};container.appendChild(el);});}
function trendText(value,average,lowerIsBetter){if(value===null||value===undefined||average===null||average===undefined)return'—';var v=parseFloat(value),a=parseFloat(average);if(isNaN(v)||isNaN(a))return'—';if(Math.abs(v-a)<.1)return'≈ cohort';var better=lowerIsBetter?(v<a):(v>a);return better?'better than cohort':'worse than cohort';}
function loadStudentPanel(studentId){selectedStudentId=parseInt(studentId,10)||0;var panel=document.getElementById('studentPanel');if(!selectedStudentId||!panel)return;Array.prototype.forEach.call(document.querySelectorAll('.tcc-radar-avatar'),function(el){el.classList.remove('selected');if(parseInt(el.getAttribute('data-student-id')||'0',10)===selectedStudentId)el.classList.add('selected');});document.getElementById('studentPanelCount').textContent='Loading';panel.className='';panel.innerHTML='<div class="tcc-loading">Loading student snapshot…</div>';api('student_snapshot',{cohort_id:cohortId,student_id:selectedStudentId}).then(function(data){if(!data.ok){document.getElementById('studentPanelCount').textContent='Error';panel.innerHTML='<div class="tcc-error">'+escapeHtml(data.message||data.error||'Unable to load student snapshot.')+'</div>';return;}renderStudentPanel(data);}).catch(function(){document.getElementById('studentPanelCount').textContent='Error';panel.innerHTML='<div class="tcc-error">Unable to load student snapshot.</div>';});}
function renderStudentPanel(data){var panel=document.getElementById('studentPanel');var st=data.student||{};var p=data.progress||{};var c=data.comparison||{};var m=data.motivation||{};var issues=data.main_issues||[];var progressPct=parseInt(p.progress_pct||0,10);if(isNaN(progressPct))progressPct=0;if(progressPct<0)progressPct=0;if(progressPct>100)progressPct=100;document.getElementById('studentPanelCount').textContent=(issues.length||0)+' issue'+(issues.length===1?'':'s');var issueHtml='';if(!issues.length){issueHtml='<div class="tcc-empty">No current blockers or system issues returned for this student.</div>';}else{issues.slice(0,10).forEach(function(issue){issueHtml+='<div class="tcc-issue-row"><div><div class="tcc-issue-title">'+escapeHtml(issue.title||issue.type||'Issue')+'</div><div class="tcc-issue-meta">Lesson '+escapeHtml(issue.lesson_id||'—')+' · '+escapeHtml(issue.lesson_title||'')+' · '+escapeHtml(issue.status||'')+'</div></div></div>';});if(issues.length>10){issueHtml+='<div class="tcc-issue-row"><div><div class="tcc-issue-title">+'+(issues.length-10)+' more issue(s)</div><div class="tcc-issue-meta">Full lesson/intervention timeline will handle the expanded view in the next phase.</div></div></div>';}}
panel.className='';panel.innerHTML='<div class="tcc-student-head"><div class="tcc-student-avatar">'+escapeHtml(st.avatar_initials||'S')+'</div><div style="min-width:0;"><div class="tcc-student-name">'+escapeHtml(st.name||'Student')+'</div><div class="tcc-student-email">'+escapeHtml(st.email||'')+'</div></div></div><div class="tcc-student-state-row"><span class="tcc-status-pill '+escapeHtml(m.level||'')+'">'+escapeHtml(m.label||'Motivation signal')+'</span><span class="tcc-status-pill">Trend: '+escapeHtml(m.trend||'—')+'</span><span class="tcc-status-pill">Issues: '+escapeHtml(issues.length)+'</span></div><div class="tcc-snapshot-grid"><div class="tcc-snapshot-tile"><div class="tcc-snapshot-label">Progress</div><div class="tcc-snapshot-value">'+progressPct+'%</div><div class="tcc-mini-bar"><span style="width:'+progressPct+'%;"></span></div><div class="tcc-snapshot-sub">'+escapeHtml(p.passed_lessons||0)+'/'+escapeHtml(p.total_lessons||0)+' lessons passed</div></div><div class="tcc-snapshot-tile"><div class="tcc-snapshot-label">Avg Score</div><div class="tcc-snapshot-value">'+(c.avg_score!==null&&c.avg_score!==undefined?escapeHtml(c.avg_score)+'%':'—')+'</div><div class="tcc-snapshot-sub">Cohort avg: '+(c.cohort_avg_score!==null&&c.cohort_avg_score!==undefined?escapeHtml(c.cohort_avg_score)+'%':'—')+'<br>'+escapeHtml(trendText(c.avg_score,c.cohort_avg_score,false))+'</div></div><div class="tcc-snapshot-tile"><div class="tcc-snapshot-label">Deadlines Missed</div><div class="tcc-snapshot-value">'+escapeHtml(c.deadlines_missed||0)+'</div><div class="tcc-snapshot-sub">Cohort avg: '+escapeHtml(c.cohort_avg_deadlines_missed||0)+'<br>'+escapeHtml(trendText(c.deadlines_missed,c.cohort_avg_deadlines_missed,true))+'</div></div><div class="tcc-snapshot-tile"><div class="tcc-snapshot-label">Failed Attempts</div><div class="tcc-snapshot-value">'+escapeHtml(c.failed_attempts||0)+'</div><div class="tcc-snapshot-sub">Cohort avg: '+escapeHtml(c.cohort_avg_failed_attempts||0)+'<br>'+escapeHtml(trendText(c.failed_attempts,c.cohort_avg_failed_attempts,true))+'</div></div></div><h3 class="tcc-section-title" style="font-size:17px;margin:4px 0 10px;">Current Blockers / Issues</h3><div class="tcc-issues-list">'+issueHtml+'</div><div class="tcc-panel-actions"><button class="tcc-btn primary" type="button">Lessons</button><button class="tcc-btn secondary" type="button">Interventions</button><button class="tcc-btn warn" type="button">System Watch</button></div>';}
function loadQueue(){document.getElementById('actionQueue').innerHTML='<div class="tcc-loading">Loading action queue…</div>';document.getElementById('queueCount').textContent='—';api('action_queue',{cohort_id:cohortId}).then(function(data){var container=document.getElementById('actionQueue');var count=document.getElementById('queueCount');if(!data.ok){showError('actionQueue',data.message||data.error||'Unable to load action queue.');return;}var items=data.items||[];count.textContent=items.length+' item'+(items.length===1?'':'s');container.innerHTML='';if(!items.length){container.innerHTML='<div class="tcc-empty">No instructor actions required for this cohort right now.</div>';return;}items.forEach(function(item){var reviewHref='';if(item.token){if(item.action_type==='instructor_approval')reviewHref='/instructor/instructor_approval.php?token='+encodeURIComponent(item.token);else reviewHref='/student/remediation_action.php?token='+encodeURIComponent(item.token);}var severity=item.severity||'low';var el=document.createElement('div');el.className='tcc-item';el.innerHTML='<div class="tcc-item-left"><div class="tcc-avatar">'+escapeHtml(item.avatar_initials||'S')+'</div><div class="tcc-meta"><div class="tcc-name">'+escapeHtml(item.student_name||'Student')+'</div><div class="tcc-sub">'+escapeHtml(item.lesson_title||'No lesson title')+'</div><div class="tcc-sub">'+escapeHtml(item.reason||item.action_type||'Required action')+'</div><span class="tcc-severity '+escapeHtml(severity)+'">'+escapeHtml(severity)+'</span></div></div><div class="tcc-actions">'+(reviewHref?'<a class="tcc-btn primary" href="'+escapeHtml(reviewHref)+'">Review</a>':'<button class="tcc-btn primary" type="button">Review</button>')+'<button class="tcc-btn warn" type="button" data-action-id="'+escapeHtml(item.required_action_id||'')+'">Safe Fix</button></div>';var left=el.querySelector('.tcc-item-left');if(left){left.style.cursor='pointer';left.onclick=function(){loadStudentPanel(item.student_id);};}container.appendChild(el);});}).catch(function(){showError('actionQueue','Unable to load action queue.');});}
document.getElementById('cohortSelect').addEventListener('change',function(){cohortId=parseInt(this.value,10)||0;loadAll();});loadCohorts();
})();
</script>

<?php cw_footer(); ?>

