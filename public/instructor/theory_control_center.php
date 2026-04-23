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
.tcc-shell {
    display:flex;
    flex-direction:column;
    gap:18px;
}

.tcc-header {
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.tcc-title {
    font-size:26px;
    font-weight:800;
}

.tcc-select {
    padding:10px 12px;
    border-radius:10px;
    border:1px solid #ccc;
    font-size:14px;
}

.tcc-card {
    background:#fff;
    border-radius:16px;
    padding:18px;
    box-shadow:0 6px 18px rgba(0,0,0,0.05);
}

.tcc-section-title {
    font-weight:800;
    font-size:18px;
    margin-bottom:12px;
}

.tcc-queue {
    display:flex;
    flex-direction:column;
    gap:12px;
}

.tcc-item {
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:14px;
    border-radius:14px;
    background:#f9fafb;
    border:1px solid #e5e7eb;
}

.tcc-item-left {
    display:flex;
    gap:12px;
    align-items:center;
}

.tcc-avatar {
    width:42px;
    height:42px;
    border-radius:50%;
    background:#12355f;
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:800;
}

.tcc-meta {
    display:flex;
    flex-direction:column;
}

.tcc-name {
    font-weight:700;
}

.tcc-sub {
    font-size:12px;
    color:#666;
}

.tcc-actions {
    display:flex;
    gap:8px;
}

.tcc-btn {
    padding:8px 10px;
    border-radius:8px;
    font-size:12px;
    font-weight:700;
    border:none;
    cursor:pointer;
}

.tcc-btn.primary {
    background:#12355f;
    color:#fff;
}

.tcc-btn.warn {
    background:#f59e0b;
    color:#fff;
}

.tcc-btn.secondary {
    background:#e5e7eb;
}
</style>

<div class="tcc-shell">

    <div class="tcc-header">
        <div class="tcc-title">Instructor Control Center</div>

        <select id="cohortSelect" class="tcc-select"></select>
    </div>

    <div class="tcc-card">
        <div class="tcc-section-title">Needs My Action</div>

        <div id="actionQueue" class="tcc-queue"></div>
    </div>

</div>

<script>
let cohortId = 0;

function api(action, params = {}) {
    params.action = action;
    return fetch('/instructor/api/theory_control_center_api.php?' + new URLSearchParams(params))
        .then(r => r.json());
}

function loadCohorts() {
    api('cohort_overview').then(data => {
        const select = document.getElementById('cohortSelect');
        select.innerHTML = '';

        data.cohorts.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.name;
            select.appendChild(opt);
        });

        if (data.cohorts.length) {
            cohortId = data.cohorts[0].id;
            select.value = cohortId;
            loadQueue();
        }
    });
}

function loadQueue() {
    api('action_queue', {cohort_id: cohortId}).then(data => {
        const container = document.getElementById('actionQueue');
        container.innerHTML = '';

        if (!data.items.length) {
            container.innerHTML = '<div>No actions required</div>';
            return;
        }

        data.items.forEach(item => {
            const el = document.createElement('div');
            el.className = 'tcc-item';

            el.innerHTML = `
                <div class="tcc-item-left">
                    <div class="tcc-avatar">${item.avatar_initials}</div>
                    <div class="tcc-meta">
                        <div class="tcc-name">${item.student_name}</div>
                        <div class="tcc-sub">${item.lesson_title}</div>
                        <div class="tcc-sub">${item.reason}</div>
                    </div>
                </div>

                <div class="tcc-actions">
                    <button class="tcc-btn primary">Review</button>
                    <button class="tcc-btn warn">Fix</button>
                </div>
            `;

            container.appendChild(el);
        });
    });
}

document.getElementById('cohortSelect').addEventListener('change', function() {
    cohortId = this.value;
    loadQueue();
});

loadCohorts();
</script>

<?php cw_footer(); ?>