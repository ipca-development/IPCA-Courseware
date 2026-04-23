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

cw_header('Theory Control Center');
?>

<style>
.tcc-shell { display:flex; flex-direction:column; gap:18px; }
.tcc-header { display:flex; justify-content:space-between; align-items:center; gap:14px; }
.tcc-title-wrap { min-width:0; }
.tcc-title { font-size:26px; line-height:1.05; font-weight:800; color:#152235; letter-spacing:-0.03em; }
.tcc-subtitle { margin-top:5px; font-size:13px; color:#64748b; line-height:1.35; }
.tcc-select { min-height:42px; padding:0 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.12); font-size:14px; font-weight:700; color:#152235; background:#fff; min-width:280px; }
.tcc-card { background:#fff; border-radius:18px; padding:18px; border:1px solid rgba(15,23,42,0.06); box-shadow:0 10px 24px rgba(15,23,42,0.055); }
.tcc-section-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; margin-bottom:14px; }
.tcc-section-title { font-weight:800; font-size:19px; line-height:1.1; color:#152235; letter-spacing:-0.02em; }
.tcc-section-sub { margin-top:5px; font-size:13px; color:#64748b; line-height:1.35; }
.tcc-count-pill { display:inline-flex; align-items:center; justify-content:center; border-radius:999px; padding:7px 11px; background:#edf4ff; color:#1d4f91; font-size:12px; font-weight:800; border:1px solid #d3e3ff; white-space:nowrap; }
.tcc-health-strip { display:grid; grid-template-columns:repeat(4,minmax(120px,1fr)); gap:10px; }
.tcc-health-card { border-radius:15px; background:#f8fbfd; border:1px solid rgba(15,23,42,0.06); padding:13px 14px; }
.tcc-health-label { font-size:10px; color:#64748b; font-weight:800; text-transform:uppercase; letter-spacing:.12em; }
.tcc-health-value { margin-top:7px; font-size:25px; line-height:1; font-weight:900; color:#152235; }
.tcc-health-note { margin-top:6px; font-size:12px; color:#64748b; }
.tcc-radar-track { position:relative; min-height:122px; margin-top:8px; padding:28px 22px 36px; border-radius:18px; background:linear-gradient(180deg,#fbfdff 0%,#f8fafc 100%); border:1px solid rgba(15,23,42,0.06); overflow:hidden; }
.tcc-radar-line { position:absolute; top:58px; left:28px; right:28px; height:8px; background:linear-gradient(90deg,#dbeafe 0%,#bfdbfe 35%,#d1fae5 100%); border-radius:999px; }
.tcc-radar-marker { position:absolute; top:72px; font-size:10px; color:#64748b; font-weight:800; transform:translateX(-50%); white-space:nowrap; }
.tcc-radar-marker.start { left:28px; transform:none; }
.tcc-radar-marker.end { right:28px; left:auto; transform:none; }
.tcc-radar-avatar { position:absolute; top:58px; transform:translate(-50%, -50%); width:46px; height:46px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:900; color:#fff; font-size:12px; cursor:pointer; box-shadow:0 7px 18px rgba(15,23,42,0.22); border:3px solid #fff; transition:transform .15s ease, box-shadow .15s ease; user-select:none; }
.tcc-radar-avatar:hover { transform:translate(-50%, -50%) scale(1.05); box-shadow:0 10px 24px rgba(15,23,42,0.28); }
.tcc-radar-avatar.green { background:#16a34a; }
.tcc-radar-avatar.orange { background:#f59e0b; }
.tcc-radar-avatar.red { background:#dc2626; }
.tcc-radar-avatar.blue { background:#2563eb; }
.tcc-radar-avatar.purple { background:#7c3aed; }
.tcc-radar-label { position:absolute; top:49px; left:50%; transform:translateX(-50%); font-size:11px; font-weight:800; color:#334155; white-space:nowrap; max-width:110px; overflow:hidden; text-overflow:ellipsis; }
.tcc-radar-score { position:absolute; top:-24px; left:50%; transform:translateX(-50%); font-size:10px; font-weight:900; color:#334155; background:#fff; border:1px solid rgba(15,23,42,0.08); border-radius:999px; padding:3px 6px; white-space:nowrap; }
.tcc-radar-legend { display:flex; flex-wrap:wrap; gap:8px; margin-top:13px; }
.tcc-legend-pill { display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:6px 9px; background:#f8fafc; border:1px solid rgba(15,23,42,0.06); font-size:11px; font-weight:800; color:#475569; }
.tcc-dot { width:9px; height:9px; border-radius:999px; display:inline-block; }
.tcc-dot.green { background:#16a34a; } .tcc-dot.orange { background:#f59e0b; } .tcc-dot.red { background:#dc2626; } .tcc-dot.blue { background:#2563eb; } .tcc-dot.purple { background:#7c3aed; }
.tcc-queue { display:flex; flex-direction:column; gap:12px; }
.tcc-item { display:flex; justify-content:space-between; align-items:center; gap:14px; padding:14px; border-radius:15px; background:#f8fbfd; border:1px solid rgba(15,23,42,0.06); }
.tcc-item-left { display:flex; gap:12px; align-items:center; min-width:0; }
.tcc-avatar { width:44px; height:44px; border-radius:50%; background:#12355f; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:900; flex:0 0 auto; }
.tcc-meta { display:flex; flex-direction:column; min-width:0; }
.tcc-name { font-weight:800; color:#152235; line-height:1.25; }
.tcc-sub { font-size:12px; color:#64748b; line-height:1.35; overflow:hidden; text-overflow:ellipsis; }
.tcc-severity { display:inline-flex; align-items:center; justify-content:center; border-radius:999px; padding:4px 8px; font-size:10px; font-weight:900; text-transform:uppercase; letter-spacing:.06em; margin-top:6px; align-self:flex-start; }
.tcc-severity.high { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; } .tcc-severity.medium { background:#fef3c7; color:#92400e; border:1px solid #fde68a; } .tcc-severity.low { background:#e0f2fe; color:#075985; border:1px solid #bae6fd; }
.tcc-actions { display:flex; gap:8px; flex:0 0 auto; }
.tcc-btn { display:inline-flex; align-items:center; justify-content:center; min-height:34px; padding:0 11px; border-radius:10px; font-size:12px; font-weight:800; border:1px solid rgba(15,23,42,0.08); cursor:pointer; text-decoration:none; }
.tcc-btn.primary { background:#12355f; color:#fff; border-color:#12355f; } .tcc-btn.warn { background:#fff7ed; color:#92400e; border-color:#fed7aa; } .tcc-btn.secondary { background:#f1f5f9; color:#334155; }
.tcc-empty { padding:16px; border-radius:14px; border:1px dashed rgba(15,23,42,0.15); background:#fbfdff; color:#64748b; font-size:14px; }
.tcc-error { padding:14px; border-radius:14px; border:1px solid #fecaca; background:#fef2f2; color:#991b1b; font-weight:800; font-size:13px; }
.tcc-loading { color:#64748b; font-size:14px; padding:12px 0; }
@media (max-width: 900px) { .tcc-header { align-items:stretch; flex-direction:column; } .tcc-select { min-width:0; width:100%; } .tcc-health-strip { grid-template-columns:repeat(2,minmax(120px,1fr)); } .tcc-item { align-items:flex-start; flex-direction:column; } .tcc-actions { width:100%; } .tcc-btn { flex:1; } .tcc-radar-track { min-height:155px; overflow-x:auto; padding-left:18px; padding-right:18px; } .tcc-radar-line { left:24px; right:24px; } }
@media (max-width: 520px) { .tcc-title { font-size:23px; } .tcc-health-strip { grid-template-columns:1fr; } .tcc-card { padding:15px; border-radius:16px; } .tcc-radar-avatar { width:42px; height:42px; font-size:11px; } .tcc-radar-label { font-size:10px; } }
</style>

<div class="tcc-shell">
    <div class="tcc-header">
        <div class="tcc-title-wrap">
            <div class="tcc-title">Instructor Theory Control Center</div>
            <div class="tcc-subtitle">Live action queue, cohort radar, and training-risk visibility.</div>
        </div>
        <select id="cohortSelect" class="tcc-select" aria-label="Select cohort"></select>
    </div>

    <div class="tcc-card">
        <div class="tcc-section-head"><div><div class="tcc-section-title">Cohort Health</div><div class="tcc-section-sub">At-a-glance status for the selected cohort.</div></div></div>
        <div id="healthStrip" class="tcc-health-strip"><div class="tcc-loading">Loading cohort health…</div></div>
    </div>

    <div class="tcc-card">
        <div class="tcc-section-head">
            <div><div class="tcc-section-title">Cohort Radar</div><div class="tcc-section-sub">Students positioned by completed progress across the cohort lesson track.</div></div>
            <div id="radarCount" class="tcc-count-pill">—</div>
        </div>
        <div id="radarTrack" class="tcc-radar-track">
            <div class="tcc-radar-line"></div><div class="tcc-radar-marker start">Start</div><div class="tcc-radar-marker end">Complete</div><div id="radarAvatars"></div>
        </div>
        <div class="tcc-radar-legend">
            <span class="tcc-legend-pill"><span class="tcc-dot green"></span> On track</span>
            <span class="tcc-legend-pill"><span class="tcc-dot orange"></span> At risk</span>
            <span class="tcc-legend-pill"><span class="tcc-dot red"></span> Blocked</span>
            <span class="tcc-legend-pill"><span class="tcc-dot blue"></span> Action pending</span>
            <span class="tcc-legend-pill"><span class="tcc-dot purple"></span> System issue</span>
        </div>
    </div>

    <div class="tcc-card">
        <div class="tcc-section-head">
            <div><div class="tcc-section-title">Needs My Action</div><div class="tcc-section-sub">Instructor-facing items that need review, approval, or safe follow-up.</div></div>
            <div id="queueCount" class="tcc-count-pill">—</div>
        </div>
        <div id="actionQueue" class="tcc-queue"><div class="tcc-loading">Loading action queue…</div></div>
    </div>
</div>

<script>
(function() {
    var cohortId = 0;

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function api(action, params) {
        params = params || {};
        params.action = action;
        return fetch('/instructor/api/theory_control_center_api.php?' + new URLSearchParams(params), { credentials:'same-origin', cache:'no-store' }).then(function(response) { return response.json(); });
    }

    function showError(containerId, message) {
        var container = document.getElementById(containerId);
        if (container) container.innerHTML = '<div class="tcc-error">' + escapeHtml(message) + '</div>';
    }

    function loadCohorts() {
        api('cohort_overview').then(function(data) {
            var select = document.getElementById('cohortSelect');
            select.innerHTML = '';
            if (!data.ok || !data.cohorts || !data.cohorts.length) {
                select.innerHTML = '<option value="">No cohorts found</option>';
                showError('actionQueue', 'No cohorts were returned by the API.');
                return;
            }
            data.cohorts.forEach(function(c) {
                var opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.name;
                select.appendChild(opt);
            });
            cohortId = parseInt(data.cohorts[0].id, 10) || 0;
            select.value = cohortId;
            loadAll();
        }).catch(function() { showError('actionQueue', 'Unable to load cohorts.'); });
    }

    function loadAll() {
        if (!cohortId) return;
        loadOverview();
        loadQueue();
    }

    function loadOverview() {
        document.getElementById('healthStrip').innerHTML = '<div class="tcc-loading">Loading cohort health…</div>';
        document.getElementById('radarAvatars').innerHTML = '';
        document.getElementById('radarCount').textContent = '—';
        api('cohort_overview', { cohort_id: cohortId }).then(function(data) {
            if (!data.ok) { showError('healthStrip', data.message || data.error || 'Unable to load cohort overview.'); return; }
            renderHealth(data);
            renderRadar(data);
        }).catch(function() { showError('healthStrip', 'Unable to load cohort overview.'); });
    }

    function renderHealth(data) {
        var s = data.summary || {};
        document.getElementById('healthStrip').innerHTML =
            '<div class="tcc-health-card"><div class="tcc-health-label">Students</div><div class="tcc-health-value">' + escapeHtml(s.student_count || 0) + '</div><div class="tcc-health-note">Active in this cohort</div></div>' +
            '<div class="tcc-health-card"><div class="tcc-health-label">On Track</div><div class="tcc-health-value">' + escapeHtml(s.on_track_count || 0) + '</div><div class="tcc-health-note">No immediate action</div></div>' +
            '<div class="tcc-health-card"><div class="tcc-health-label">At Risk</div><div class="tcc-health-value">' + escapeHtml(s.at_risk_count || 0) + '</div><div class="tcc-health-note">Needs monitoring</div></div>' +
            '<div class="tcc-health-card"><div class="tcc-health-label">Blocked</div><div class="tcc-health-value">' + escapeHtml(s.blocked_count || 0) + '</div><div class="tcc-health-note">Action required</div></div>';
    }

    function radarColor(student) {
        if (student.state === 'blocked') return 'red';
        if (student.pending_action_count && parseInt(student.pending_action_count, 10) > 0) return 'blue';
        if (student.state === 'at_risk') return 'orange';
        return 'green';
    }

    function renderRadar(data) {
        var container = document.getElementById('radarAvatars');
        var count = document.getElementById('radarCount');
        var students = data.students || [];
        container.innerHTML = '';
        count.textContent = students.length + ' student' + (students.length === 1 ? '' : 's');
        if (!students.length) { container.innerHTML = '<div class="tcc-empty">No students found for this cohort.</div>'; return; }
        students.forEach(function(s, idx) {
            var progress = parseInt(s.progress_pct, 10);
            if (isNaN(progress)) progress = 0;
            if (progress < 0) progress = 0;
            if (progress > 100) progress = 100;
            var verticalNudge = (idx % 3) * 5;
            var el = document.createElement('div');
            el.className = 'tcc-radar-avatar ' + radarColor(s);
            el.style.left = progress + '%';
            el.style.top = (58 + verticalNudge) + 'px';
            el.title = s.name + ' · ' + progress + '% · ' + s.state;
            el.setAttribute('role', 'button');
            el.setAttribute('tabindex', '0');
            el.setAttribute('aria-label', s.name + ' progress ' + progress + ' percent');
            el.innerHTML = '<span class="tcc-radar-score">' + progress + '%</span>' + escapeHtml(s.avatar_initials || 'S') + '<span class="tcc-radar-label">' + escapeHtml((s.name || 'Student').split(' ')[0]) + '</span>';
            el.onclick = function() { alert('Next step: open Student Deep Dive for ' + s.name); };
            el.onkeydown = function(ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); el.click(); } };
            container.appendChild(el);
        });
    }

    function loadQueue() {
        document.getElementById('actionQueue').innerHTML = '<div class="tcc-loading">Loading action queue…</div>';
        document.getElementById('queueCount').textContent = '—';
        api('action_queue', { cohort_id: cohortId }).then(function(data) {
            var container = document.getElementById('actionQueue');
            var count = document.getElementById('queueCount');
            if (!data.ok) { showError('actionQueue', data.message || data.error || 'Unable to load action queue.'); return; }
            var items = data.items || [];
            count.textContent = items.length + ' item' + (items.length === 1 ? '' : 's');
            container.innerHTML = '';
            if (!items.length) { container.innerHTML = '<div class="tcc-empty">No instructor actions required for this cohort right now.</div>'; return; }
            items.forEach(function(item) {
                var reviewHref = '';
                if (item.token) {
                    if (item.action_type === 'instructor_approval') reviewHref = '/instructor/instructor_approval.php?token=' + encodeURIComponent(item.token);
                    else reviewHref = '/student/remediation_action.php?token=' + encodeURIComponent(item.token);
                }
                var severity = item.severity || 'low';
                var el = document.createElement('div');
                el.className = 'tcc-item';
                el.innerHTML = '<div class="tcc-item-left"><div class="tcc-avatar">' + escapeHtml(item.avatar_initials || 'S') + '</div><div class="tcc-meta"><div class="tcc-name">' + escapeHtml(item.student_name || 'Student') + '</div><div class="tcc-sub">' + escapeHtml(item.lesson_title || 'No lesson title') + '</div><div class="tcc-sub">' + escapeHtml(item.reason || item.action_type || 'Required action') + '</div><span class="tcc-severity ' + escapeHtml(severity) + '">' + escapeHtml(severity) + '</span></div></div><div class="tcc-actions">' + (reviewHref ? '<a class="tcc-btn primary" href="' + escapeHtml(reviewHref) + '">Review</a>' : '<button class="tcc-btn primary" type="button">Review</button>') + '<button class="tcc-btn warn" type="button" data-action-id="' + escapeHtml(item.required_action_id || '') + '">Safe Fix</button></div>';
                container.appendChild(el);
            });
        }).catch(function() { showError('actionQueue', 'Unable to load action queue.'); });
    }

    document.getElementById('cohortSelect').addEventListener('change', function() {
        cohortId = parseInt(this.value, 10) || 0;
        loadAll();
    });

    loadCohorts();
})();
</script>

<?php cw_footer(); ?>
