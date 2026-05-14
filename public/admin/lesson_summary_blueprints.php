<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/lesson_summary_blueprint_service.php';

cw_require_admin();

$svc = new LessonSummaryBlueprintService($pdo);

function lsb_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function lsb_int_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    return array_values(array_unique(array_filter(array_map('intval', $value), static fn (int $v): bool => $v > 0)));
}

function lsb_string_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $out = [];
    foreach ($value as $item) {
        $s = trim((string)$item);
        if ($s !== '') {
            $out[] = $s;
        }
    }

    return array_values(array_unique($out));
}

if (isset($_GET['action'])) {
    $action = (string)$_GET['action'];
    try {
        if ($action === 'lessons') {
            $courseId = (int)($_GET['course_id'] ?? 0);
            lsb_json(['ok' => true, 'lessons' => $svc->listLessonsForCourse($courseId)]);
        }

        if ($action === 'lesson_detail') {
            $lessonId = (int)($_GET['lesson_id'] ?? 0);
            lsb_json(['ok' => true, 'detail' => $svc->lessonDetail($lessonId)]);
        }

        if ($action === 'compare') {
            $activeVersionId = (int)($_GET['active_version_id'] ?? 0);
            $selectedVersionId = (int)($_GET['selected_version_id'] ?? 0);
            lsb_json(['ok' => true, 'comparison' => $svc->compareVersions($activeVersionId, $selectedVersionId)]);
        }

        lsb_json(['ok' => false, 'error' => 'Unknown action'], 404);
    } catch (Throwable $e) {
        lsb_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_write_close();
    ignore_user_abort(true);
    @set_time_limit(900);
    @ini_set('max_execution_time', '900');
    @ini_set('default_socket_timeout', '600');

    $raw = file_get_contents('php://input');
    $payload = [];
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    if ($payload === []) {
        $payload = $_POST;
    }

    $action = (string)($payload['action'] ?? '');
    try {
        if ($action === 'generate_lesson') {
            $lessonId = (int)($payload['lesson_id'] ?? 0);
            $reason = (string)($payload['generation_reason'] ?? 'manual_regenerate');
            $verified = lsb_int_list($payload['verified_resource_ids'] ?? []);
            $unverified = lsb_string_list($payload['unverified_resource_ids'] ?? []);
            lsb_json($svc->generateLesson($lessonId, $reason, $verified, $unverified));
        }

        if ($action === 'activate_version') {
            $svc->activateVersion((int)($payload['version_id'] ?? 0));
            lsb_json(['ok' => true]);
        }

        if ($action === 'activate_lessons') {
            $lessonIds = lsb_int_list($payload['lesson_ids'] ?? []);
            lsb_json(['ok' => true] + $svc->activateLatestVersionsForLessons($lessonIds));
        }

        lsb_json(['ok' => false, 'error' => 'Unknown action'], 404);
    } catch (Throwable $e) {
        lsb_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

$programs = $pdo->query('SELECT id, program_key, name FROM programs ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
$courses = $pdo->query("
    SELECT c.id, c.title, c.program_id, p.program_key
    FROM courses c
    INNER JOIN programs p ON p.id = c.program_id
    ORDER BY p.sort_order, c.sort_order, c.id
")->fetchAll(PDO::FETCH_ASSOC);

$coursesByProgram = [];
foreach ($courses as $course) {
    $pid = (int)$course['program_id'];
    if (!isset($coursesByProgram[$pid])) {
        $coursesByProgram[$pid] = [];
    }
    $coursesByProgram[$pid][] = [
        'id' => (int)$course['id'],
        'title' => (string)$course['title'],
        'program_key' => (string)$course['program_key'],
    ];
}

$embed = [
    'schemaReady' => $svc->schemaReady(),
    'programs' => array_map(static fn (array $p): array => [
        'id' => (int)$p['id'],
        'key' => (string)$p['program_key'],
        'name' => (string)$p['name'],
    ], $programs),
    'coursesByProgram' => $coursesByProgram,
    'verifiedResources' => $svc->liveResources(),
    'unverifiedResources' => $svc->unverifiedResources(),
];

cw_header('Lesson Bulk Enrich');
?>
<div class="card lsb-hub">
  <h2>Lesson Summary Blueprints</h2>
  <p class="muted">
    Generate fully versioned lesson-level blueprints for Maya Summary Coach. The blueprint defines canonical structure,
    concept coverage, slide grouping, operational focus, boundaries, and official references. It does not define student wording.
  </p>

  <?php if (!$svc->schemaReady()): ?>
    <div class="lsb-alert lsb-alert-warn">
      Blueprint tables are not installed. Run <code>scripts/sql/2026_05_14_lesson_summary_blueprints.sql</code>, then reload.
    </div>
  <?php endif; ?>

  <div class="lsb-grid">
    <label for="lsbProgram">Program</label>
    <select id="lsbProgram">
      <option value="0">Select program</option>
      <?php foreach ($programs as $program): ?>
        <option value="<?= (int)$program['id'] ?>">
          <?= h((string)$program['name']) ?> (<?= h((string)$program['program_key']) ?>)
        </option>
      <?php endforeach; ?>
    </select>

    <label for="lsbCourse">Course</label>
    <select id="lsbCourse" disabled>
      <option value="0">Select course</option>
    </select>
  </div>

  <div class="lsb-source-panel">
    <div class="lsb-source-group">
      <h3>Verified / Live Resources</h3>
      <p class="muted">Populated dynamically from live Resource Library editions. Disabled, draft, and archived sources are ignored.</p>
      <div id="lsbVerifiedSources" class="lsb-check-list"></div>
    </div>
    <div class="lsb-source-group lsb-unverified">
      <h3>ACS Official Sources</h3>
      <p class="muted">Temporary ACS selectors. These remain separate from Resource Library editions, but selected ACS slide references are allowed as official blueprint references.</p>
      <div id="lsbUnverifiedSources" class="lsb-check-list"></div>
    </div>
  </div>

  <div class="lsb-actions">
    <button type="button" class="btn" id="lsbGenerateMissing" disabled>Generate Missing/Stale for Course</button>
    <button type="button" class="btn secondary" id="lsbRegenerateFull" disabled>Regenerate Full Course</button>
    <button type="button" class="btn secondary" id="lsbGenerateSelected" disabled>Generate Selected Lessons</button>
    <button type="button" class="btn secondary" id="lsbActivateSelected" disabled>Activate Selected Latest Versions</button>
    <button type="button" class="btn secondary" id="lsbReload" disabled>Refresh</button>
  </div>

  <div class="lsb-progress lsb-hidden" id="lsbProgress">
    <div class="lsb-progress-top">
      <strong id="lsbProgressTitle">Bulk generation</strong>
      <span class="muted" id="lsbProgressMeta"></span>
    </div>
    <div class="lsb-progress-bar"><div id="lsbProgressFill"></div></div>
    <div class="lsb-current-work" id="lsbCurrentWork">Waiting to start...</div>
    <div id="lsbProgressLog" class="lsb-log"></div>
  </div>

  <div class="lsb-table-tools">
    <button type="button" class="btn secondary" id="lsbSelectAll" disabled>Select visible</button>
    <button type="button" class="btn secondary" id="lsbClearSelection" disabled>Clear selection</button>
    <span class="muted" id="lsbLessonCount">Select a course to load lessons.</span>
  </div>

  <div class="lsb-table-scroll">
    <table class="lsb-table">
      <thead>
        <tr>
          <th><input type="checkbox" id="lsbToggleAll" disabled></th>
          <th>Lesson ID</th>
          <th>Lesson title</th>
          <th>External ID</th>
          <th>Active slides</th>
          <th>Active version</th>
          <th>Status</th>
          <th>Confidence</th>
          <th>Warnings</th>
          <th>Hash</th>
          <th>Last generated</th>
          <th>Last updated</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="lsbTbody">
        <tr><td colspan="13" class="muted">No course selected.</td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="lsb-modal-backdrop lsb-hidden" id="lsbModalBackdrop" role="presentation"></div>
<div class="lsb-modal lsb-hidden" id="lsbModal" role="dialog" aria-modal="true" aria-labelledby="lsbModalTitle">
  <div class="lsb-modal-head">
    <div>
      <div class="muted">Lesson Summary Blueprint</div>
      <h2 id="lsbModalTitle">Lesson</h2>
    </div>
    <button type="button" class="btn secondary" id="lsbCloseModal">Close</button>
  </div>
  <div id="lsbModalBody" class="lsb-modal-body"></div>
</div>

<style>
.lsb-hub .lsb-grid { display:grid; grid-template-columns:140px minmax(260px, 520px); gap:10px 14px; align-items:center; margin-top:14px; }
.lsb-source-panel { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:16px; }
.lsb-source-group { border:1px solid #e2e8f0; border-radius:12px; padding:12px; background:#f8fafc; }
.lsb-source-group h3 { margin:0 0 4px; font-size:1rem; }
.lsb-source-group p { margin:0 0 10px; font-size:12px; }
.lsb-unverified { border-color:#f59e0b; background:#fffbeb; }
.lsb-check-list { display:grid; gap:8px; }
.lsb-check-list label { display:flex; gap:8px; align-items:flex-start; font-size:13px; }
.lsb-check-list small { display:block; color:#64748b; }
.lsb-actions, .lsb-table-tools { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top:16px; }
.lsb-table-scroll { margin-top:10px; overflow:auto; max-height:62vh; border:1px solid #e5e7eb; border-radius:10px; }
.lsb-table { width:100%; border-collapse:collapse; font-size:12px; }
.lsb-table th, .lsb-table td { border-bottom:1px solid #f1f5f9; padding:7px 8px; text-align:left; vertical-align:top; }
.lsb-table th { position:sticky; top:0; background:#f8fafc; z-index:1; white-space:nowrap; }
.lsb-table tr.lsb-clickable-row { cursor:pointer; }
.lsb-table tr.lsb-clickable-row:hover { background:#f8fafc; }
.lsb-table tr.lsb-row-working { background:#eff6ff; }
.lsb-status { display:inline-flex; border-radius:999px; padding:2px 8px; font-size:11px; font-weight:700; text-transform:uppercase; }
.lsb-status-active { background:#dcfce7; color:#166534; }
.lsb-status-stale, .lsb-status-draft { background:#fef3c7; color:#92400e; }
.lsb-status-failed { background:#fee2e2; color:#991b1b; }
.lsb-status-missing { background:#e2e8f0; color:#475569; }
.lsb-status-generating, .lsb-status-activating { background:#dbeafe; color:#1d4ed8; }
.lsb-btn-row { display:flex; gap:6px; flex-wrap:wrap; }
.btn.tiny { font-size:12px; padding:4px 8px; }
.lsb-alert { border-radius:10px; padding:10px 12px; margin-top:12px; }
.lsb-alert-warn { background:#fffbeb; border:1px solid #f59e0b; color:#78350f; }
.lsb-progress { border:1px solid #e2e8f0; border-radius:12px; padding:12px; margin-top:16px; background:#fafafa; }
.lsb-progress-top { display:flex; justify-content:space-between; gap:10px; align-items:center; margin-bottom:8px; }
.lsb-progress-bar { height:10px; border-radius:999px; background:#e2e8f0; overflow:hidden; }
.lsb-progress-bar div { height:100%; width:0; background:linear-gradient(90deg,#2563eb,#22c55e); transition:width .2s ease; }
.lsb-current-work { margin-top:8px; padding:8px 10px; border-radius:8px; background:#eff6ff; color:#1e3a8a; font-size:13px; font-weight:600; }
.lsb-log { margin-top:10px; max-height:180px; overflow:auto; font-size:12px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:8px; }
.lsb-log-line { padding:3px 0; border-bottom:1px solid #f8fafc; }
.lsb-log-ok { color:#047857; }
.lsb-log-fail { color:#b91c1c; }
.lsb-hidden { display:none !important; }
.lsb-modal-backdrop { position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:900; }
.lsb-modal { position:fixed; z-index:901; inset:5vh 4vw; background:#fff; border-radius:14px; box-shadow:0 24px 80px rgba(15,23,42,.35); display:flex; flex-direction:column; overflow:hidden; }
.lsb-modal-head { display:flex; justify-content:space-between; align-items:center; gap:14px; padding:16px 18px; border-bottom:1px solid #e5e7eb; background:#f8fafc; }
.lsb-modal-head h2 { margin:2px 0 0; }
.lsb-modal-body { overflow:auto; padding:16px 18px 24px; }
.lsb-modal-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:10px; margin-bottom:14px; }
.lsb-metric { border:1px solid #e2e8f0; border-radius:10px; padding:10px; background:#f8fafc; }
.lsb-metric-label { font-size:11px; text-transform:uppercase; color:#64748b; letter-spacing:.04em; }
.lsb-metric-value { font-weight:800; margin-top:4px; }
.lsb-version-row { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:12px 0; }
.lsb-section-card, .lsb-slide-card, .lsb-compare-card { border:1px solid #e2e8f0; border-radius:12px; padding:12px; margin:10px 0; }
.lsb-section-card h4, .lsb-slide-card h4 { margin:0 0 8px; }
.lsb-pill-list { display:flex; gap:6px; flex-wrap:wrap; margin:4px 0; }
.lsb-pill { display:inline-flex; border-radius:999px; padding:2px 8px; background:#e0f2fe; color:#075985; font-size:12px; }
.lsb-ref { margin:6px 0; padding:8px; border-left:3px solid #2563eb; background:#f8fafc; font-size:12px; }
.lsb-json-wrap textarea { width:100%; min-height:320px; font:12px ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; border:1px solid #cbd5e1; border-radius:10px; padding:10px; }
.lsb-compare-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.lsb-compare-added { color:#047857; }
.lsb-compare-removed { color:#b91c1c; }
.lsb-compare-changed { color:#92400e; }
@media (max-width: 900px) {
  .lsb-hub .lsb-grid, .lsb-source-panel, .lsb-compare-grid { grid-template-columns:1fr; }
  .lsb-modal { inset:2vh 2vw; }
}
</style>

<script>
window.LSB_BOOT = <?= json_encode($embed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

(function () {
  'use strict';

  const boot = window.LSB_BOOT || {};
  const state = {
    lessons: [],
    courseId: 0,
    detail: null,
    selectedVersionId: 0
  };

  const els = {
    program: document.getElementById('lsbProgram'),
    course: document.getElementById('lsbCourse'),
    verified: document.getElementById('lsbVerifiedSources'),
    unverified: document.getElementById('lsbUnverifiedSources'),
    tbody: document.getElementById('lsbTbody'),
    lessonCount: document.getElementById('lsbLessonCount'),
    generateMissing: document.getElementById('lsbGenerateMissing'),
    regenerateFull: document.getElementById('lsbRegenerateFull'),
    generateSelected: document.getElementById('lsbGenerateSelected'),
    activateSelected: document.getElementById('lsbActivateSelected'),
    reload: document.getElementById('lsbReload'),
    selectAll: document.getElementById('lsbSelectAll'),
    clearSelection: document.getElementById('lsbClearSelection'),
    toggleAll: document.getElementById('lsbToggleAll'),
    progress: document.getElementById('lsbProgress'),
    progressTitle: document.getElementById('lsbProgressTitle'),
    progressMeta: document.getElementById('lsbProgressMeta'),
    progressFill: document.getElementById('lsbProgressFill'),
    currentWork: document.getElementById('lsbCurrentWork'),
    progressLog: document.getElementById('lsbProgressLog'),
    modalBackdrop: document.getElementById('lsbModalBackdrop'),
    modal: document.getElementById('lsbModal'),
    modalTitle: document.getElementById('lsbModalTitle'),
    modalBody: document.getElementById('lsbModalBody'),
    closeModal: document.getElementById('lsbCloseModal')
  };

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function statusBadge(status) {
    const s = String(status || 'missing');
    return '<span class="lsb-status lsb-status-' + esc(s) + '">' + esc(s) + '</span>';
  }

  function setControls(enabled) {
    const ok = enabled && !!boot.schemaReady;
    els.generateMissing.disabled = !ok;
    els.regenerateFull.disabled = !ok;
    els.generateSelected.disabled = !ok;
    els.activateSelected.disabled = !ok;
    els.reload.disabled = !enabled;
    els.selectAll.disabled = !enabled;
    els.clearSelection.disabled = !enabled;
    els.toggleAll.disabled = !enabled;
  }

  function selectedVerifiedIds() {
    return Array.from(document.querySelectorAll('.lsb-source-verified:checked')).map(function (el) {
      return parseInt(el.value, 10);
    }).filter(Boolean);
  }

  function selectedUnverifiedIds() {
    return Array.from(document.querySelectorAll('.lsb-source-unverified:checked')).map(function (el) {
      return el.value;
    }).filter(Boolean);
  }

  function selectedLessonIds() {
    return Array.from(document.querySelectorAll('.lsb-row-check:checked')).map(function (el) {
      return parseInt(el.value, 10);
    }).filter(Boolean);
  }

  function renderSources() {
    const verified = Array.isArray(boot.verifiedResources) ? boot.verifiedResources : [];
    if (!verified.length) {
      els.verified.innerHTML = '<div class="muted">No live Resource Library sources are currently available.</div>';
    } else {
      els.verified.innerHTML = verified.map(function (r) {
        const label = r.work_code || r.title || r.resource_type || ('Resource #' + r.id);
        const sub = [r.title, r.revision_code, r.resource_type].filter(Boolean).join(' · ');
        return '<label><input class="lsb-source-verified" type="checkbox" value="' + esc(r.id) + '" checked> <span><strong>' + esc(label) + '</strong><small>' + esc(sub) + '</small></span></label>';
      }).join('');
    }

    const unverified = Array.isArray(boot.unverifiedResources) ? boot.unverifiedResources : [];
    els.unverified.innerHTML = unverified.map(function (r) {
      return '<label><input class="lsb-source-unverified" type="checkbox" value="' + esc(r.id) + '"> <span><strong>' + esc(r.label) + '</strong><small>Temporary official ACS source, separate from live Resource Library editions.</small></span></label>';
    }).join('');
  }

  function renderCourses() {
    const pid = parseInt(els.program.value, 10) || 0;
    const courses = (boot.coursesByProgram && boot.coursesByProgram[String(pid)]) || [];
    els.course.innerHTML = '<option value="0">Select course</option>' + courses.map(function (c) {
      return '<option value="' + esc(c.id) + '">' + esc(c.title) + '</option>';
    }).join('');
    els.course.disabled = !courses.length;
    state.courseId = 0;
    state.lessons = [];
    renderLessons();
    setControls(false);
  }

  async function apiGet(params) {
    const url = new URL(window.location.href);
    url.search = new URLSearchParams(params).toString();
    const resp = await fetch(url.toString(), { credentials: 'same-origin' });
    const json = await resp.json();
    if (!json.ok) throw new Error(json.error || 'Request failed');
    return json;
  }

  async function apiPost(payload) {
    const resp = await fetch(window.location.pathname, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const json = await resp.json();
    if (!json.ok) {
      const err = new Error(json.error || 'Request failed');
      err.payload = json;
      throw err;
    }
    return json;
  }

  async function latestVersionIdForLesson(lessonId) {
    try {
      const json = await apiGet({ action: 'lesson_detail', lesson_id: lessonId });
      const versions = json.detail && Array.isArray(json.detail.versions) ? json.detail.versions : [];
      if (!versions.length) return 0;
      return versions.reduce(function (max, v) {
        return Math.max(max, parseInt(v.id, 10) || 0);
      }, 0);
    } catch (e) {
      return 0;
    }
  }

  async function waitForSavedGeneration(lessonId, previousLatestVersionId) {
    for (let i = 0; i < 30; i++) {
      await new Promise(function (resolve) { window.setTimeout(resolve, 3000); });
      const latest = await latestVersionIdForLesson(lessonId);
      if (latest > previousLatestVersionId) {
        return latest;
      }
      setCurrentWork('Lesson ' + lessonId + ' is still being verified in the background... ' + ((i + 1) * 3) + 's');
    }
    return 0;
  }

  function isBackgroundCompletionNetworkDrop(error) {
    const message = String(error && error.message ? error.message : '').toLowerCase();
    return message.indexOf('failed to fetch') !== -1 ||
      message.indexOf('load failed') !== -1 ||
      message.indexOf('networkerror') !== -1 ||
      message.indexOf('network error') !== -1;
  }

  async function loadLessons() {
    state.courseId = parseInt(els.course.value, 10) || 0;
    if (!state.courseId) {
      state.lessons = [];
      renderLessons();
      setControls(false);
      return;
    }
    els.tbody.innerHTML = '<tr><td colspan="13" class="muted">Loading lessons...</td></tr>';
    setControls(false);
    try {
      const json = await apiGet({ action: 'lessons', course_id: state.courseId });
      state.lessons = json.lessons || [];
      renderLessons();
      setControls(true);
    } catch (e) {
      els.tbody.innerHTML = '<tr><td colspan="13" class="lsb-log-fail">' + esc(e.message) + '</td></tr>';
      els.lessonCount.textContent = 'Could not load lessons.';
    }
  }

  function renderLessons() {
    if (!state.lessons.length) {
      els.tbody.innerHTML = '<tr><td colspan="13" class="muted">' + (state.courseId ? 'No lessons found for this course.' : 'No course selected.') + '</td></tr>';
      els.lessonCount.textContent = state.courseId ? '0 lessons.' : 'Select a course to load lessons.';
      return;
    }

    els.lessonCount.textContent = state.lessons.length + ' lesson(s) loaded.';
    els.tbody.innerHTML = state.lessons.map(function (l) {
      const confidence = l.confidence == null ? '-' : Number(l.confidence).toFixed(2);
      const version = l.active_version_number ? ('v' + l.active_version_number) : '-';
      return '<tr class="lsb-clickable-row" role="button" tabindex="0" data-lesson-id="' + esc(l.lesson_id) + '" title="Open blueprint details">' +
        '<td><input class="lsb-row-check" type="checkbox" value="' + esc(l.lesson_id) + '" aria-label="Select lesson ' + esc(l.lesson_id) + '"></td>' +
        '<td>' + esc(l.lesson_id) + '</td>' +
        '<td><strong>' + esc(l.title) + '</strong></td>' +
        '<td>' + esc(l.external_lesson_id) + '</td>' +
        '<td>' + esc(l.active_slide_count) + '</td>' +
        '<td>' + esc(version) + '</td>' +
        '<td>' + statusBadge(l.status) + '</td>' +
        '<td>' + esc(confidence) + '</td>' +
        '<td>' + esc(l.warning_count) + '</td>' +
        '<td><code>' + esc(l.source_hash_short || '') + '</code></td>' +
        '<td>' + esc(l.last_generated || '-') + '</td>' +
        '<td>' + esc(l.last_updated || '-') + '</td>' +
        '<td><div class="lsb-btn-row">' +
          '<button type="button" class="btn tiny lsb-generate-one" data-lesson-id="' + esc(l.lesson_id) + '">Generate</button>' +
          '<button type="button" class="btn tiny secondary lsb-regenerate-one" data-lesson-id="' + esc(l.lesson_id) + '">Regenerate</button>' +
        '</div></td>' +
      '</tr>';
    }).join('');
  }

  function addLog(message, ok) {
    const line = document.createElement('div');
    line.className = 'lsb-log-line ' + (ok === false ? 'lsb-log-fail' : ok === true ? 'lsb-log-ok' : '');
    line.textContent = message;
    els.progressLog.appendChild(line);
    els.progressLog.scrollTop = els.progressLog.scrollHeight;
  }

  function startProgress(title, total) {
    els.progress.classList.remove('lsb-hidden');
    els.progressTitle.textContent = title;
    els.progressMeta.textContent = '0 / ' + total;
    els.progressFill.style.width = '0%';
    els.currentWork.textContent = total > 0 ? 'Queued ' + total + ' lesson(s).' : 'Nothing to process.';
    els.progressLog.innerHTML = '';
  }

  function updateProgress(done, total) {
    const pct = total > 0 ? Math.round((done / total) * 100) : 0;
    els.progressMeta.textContent = done + ' / ' + total;
    els.progressFill.style.width = pct + '%';
  }

  function setCurrentWork(message) {
    els.currentWork.textContent = message;
  }

  function markLessonWorking(lessonId, status, detail) {
    const row = els.tbody.querySelector('tr[data-lesson-id="' + String(lessonId) + '"]');
    if (!row) return;
    row.classList.add('lsb-row-working');
    const cells = row.querySelectorAll('td');
    if (cells[6]) cells[6].innerHTML = statusBadge(status);
    if (cells[11] && detail) cells[11].textContent = detail;
  }

  function clearLessonWorking(lessonId) {
    const row = els.tbody.querySelector('tr[data-lesson-id="' + String(lessonId) + '"]');
    if (row) row.classList.remove('lsb-row-working');
  }

  function startElapsedTicker(label, lessonId, lessonTitle) {
    const started = Date.now();
    const render = function () {
      const elapsed = Math.max(0, Math.floor((Date.now() - started) / 1000));
      const message = label + ' lesson ' + lessonId + (lessonTitle ? ' - ' + lessonTitle : '') + ' (' + elapsed + 's elapsed)';
      setCurrentWork(message);
      markLessonWorking(lessonId, label.toLowerCase(), elapsed + 's elapsed');
    };
    render();
    const timer = window.setInterval(render, 1000);
    return function () {
      window.clearInterval(timer);
    };
  }

  async function generateLessons(lessonIds, reason, onlyMissingStale) {
    const targets = lessonIds.filter(function (id) {
      if (!onlyMissingStale) return true;
      const lesson = state.lessons.find(function (l) { return parseInt(l.lesson_id, 10) === id; });
      return lesson && ['missing', 'stale', 'failed'].indexOf(String(lesson.status || 'missing')) !== -1;
    });
    if (!targets.length) {
      startProgress('Bulk generation', 0);
      addLog('No lessons matched the requested scope.', false);
      return;
    }

    startProgress('Bulk generation', targets.length);
    let done = 0;
    for (const lessonId of targets) {
      const lesson = state.lessons.find(function (l) { return parseInt(l.lesson_id, 10) === lessonId; });
      addLog('Generating lesson ' + lessonId + (lesson ? ' — ' + lesson.title : '') + '...');
      const previousLatestVersionId = await latestVersionIdForLesson(lessonId);
      const stopTicker = startElapsedTicker('Generating', lessonId, lesson ? lesson.title : '');
      try {
        const json = await apiPost({
          action: 'generate_lesson',
          lesson_id: lessonId,
          generation_reason: reason,
          verified_resource_ids: selectedVerifiedIds(),
          unverified_resource_ids: selectedUnverifiedIds()
        });
        stopTicker();
        addLog('OK lesson ' + lessonId + ': ' + json.status + ', confidence ' + Number(json.confidence || 0).toFixed(2) + ', warnings ' + (json.warning_count || 0), true);
      } catch (e) {
        stopTicker();
        const payload = e.payload || {};
        if (!payload.error && isBackgroundCompletionNetworkDrop(e)) {
          addLog('Lesson ' + lessonId + ' is still processing. Checking for the saved version in the background...');
          setCurrentWork('Lesson ' + lessonId + ' is finishing in the background. Waiting for the saved version...');
          const savedVersionId = await waitForSavedGeneration(lessonId, previousLatestVersionId);
          if (savedVersionId > 0) {
            addLog('OK lesson ' + lessonId + ': new version ' + savedVersionId + ' was saved by the background run.', true);
          } else {
            addLog('FAILED lesson ' + lessonId + ': background check timed out before a saved version appeared.', false);
          }
        } else {
          addLog('FAILED lesson ' + lessonId + ': ' + (payload.error || e.message), false);
        }
      } finally {
        clearLessonWorking(lessonId);
      }
      done++;
      updateProgress(done, targets.length);
      await loadLessons();
    }
    setCurrentWork('Bulk generation finished.');
  }

  async function activateSelectedLessons() {
    const targets = selectedLessonIds();
    if (!targets.length) {
      startProgress('Bulk activation', 0);
      addLog('Select one or more lessons to activate.', false);
      return;
    }

    startProgress('Bulk activation', targets.length);
    let done = 0;
    for (const lessonId of targets) {
      const lesson = state.lessons.find(function (l) { return parseInt(l.lesson_id, 10) === lessonId; });
      addLog('Activating latest eligible version for lesson ' + lessonId + (lesson ? ' — ' + lesson.title : '') + '...');
      const stopTicker = startElapsedTicker('Activating', lessonId, lesson ? lesson.title : '');
      try {
        const json = await apiPost({ action: 'activate_lessons', lesson_ids: [lessonId] });
        stopTicker();
        if (Array.isArray(json.activated) && json.activated.length) {
          const v = json.activated[0].version || {};
          addLog('OK lesson ' + lessonId + ': activated v' + (v.version_number || '?'), true);
        } else {
          const failure = Array.isArray(json.failed) && json.failed.length ? json.failed[0].error : 'No eligible version found';
          addLog('FAILED lesson ' + lessonId + ': ' + failure, false);
        }
      } catch (e) {
        stopTicker();
        const payload = e.payload || {};
        addLog('FAILED lesson ' + lessonId + ': ' + (payload.error || e.message), false);
      } finally {
        clearLessonWorking(lessonId);
      }
      done++;
      updateProgress(done, targets.length);
      await loadLessons();
    }
    setCurrentWork('Bulk activation finished.');
  }

  async function openModal(lessonId) {
    els.modalBackdrop.classList.remove('lsb-hidden');
    els.modal.classList.remove('lsb-hidden');
    els.modalTitle.textContent = 'Loading...';
    els.modalBody.innerHTML = '<p class="muted">Loading blueprint data...</p>';
    try {
      const json = await apiGet({ action: 'lesson_detail', lesson_id: lessonId });
      state.detail = json.detail;
      state.selectedVersionId = state.detail && state.detail.active_version ? state.detail.active_version.id : 0;
      renderModal();
    } catch (e) {
      els.modalTitle.textContent = 'Error';
      els.modalBody.innerHTML = '<div class="lsb-alert lsb-alert-warn">' + esc(e.message) + '</div>';
    }
  }

  function closeModal() {
    els.modalBackdrop.classList.add('lsb-hidden');
    els.modal.classList.add('lsb-hidden');
    state.detail = null;
  }

  function versionOptions(detail) {
    const versions = detail.versions || [];
    return versions.map(function (v) {
      const label = 'v' + v.version_number + ' · ' + v.status + ' · ' + (v.generated_at || '');
      const selected = parseInt(v.id, 10) === parseInt(state.selectedVersionId, 10) ? ' selected' : '';
      return '<option value="' + esc(v.id) + '"' + selected + '>' + esc(label) + '</option>';
    }).join('');
  }

  function selectedVersion(detail) {
    const versions = detail.versions || [];
    return versions.find(function (v) { return parseInt(v.id, 10) === parseInt(state.selectedVersionId, 10); }) || detail.active_version || null;
  }

  function renderModal() {
    const detail = state.detail;
    if (!detail) return;
    const lesson = detail.lesson || {};
    const active = detail.active_version || null;
    const selected = selectedVersion(detail);
    const bp = selected ? (selected.blueprint_json || {}) : {};
    const status = selected ? selected.status : (detail.blueprint ? detail.blueprint.current_status : 'missing');

    els.modalTitle.textContent = lesson.title || ('Lesson ' + lesson.id);
    els.modalBody.innerHTML =
      '<div class="lsb-modal-grid">' +
        metric('Lesson ID', lesson.id || '') +
        metric('Blueprint status', statusBadge(status)) +
        metric('Confidence', selected && selected.confidence_score != null ? Number(selected.confidence_score).toFixed(2) : '-') +
        metric('Warnings', selected ? selected.warning_count : 0) +
        metric('Active slides used', (detail.active_slides || []).length) +
        metric('Current source hash', '<code>' + esc((detail.current_hash || '').slice(0, 12)) + '</code>') +
      '</div>' +
      '<div class="lsb-version-row">' +
        '<label><strong>Version</strong> <select id="lsbVersionSelect">' + versionOptions(detail) + '</select></label>' +
        (active ? '<span>' + statusBadge(active.status || 'active') + ' active pointer v' + esc(active.version_number) + '</span>' : '<span class="muted">No active version</span>') +
        '<button type="button" class="btn tiny secondary" id="lsbViewVersion">View version</button>' +
        '<button type="button" class="btn tiny secondary" id="lsbActivateVersion">Activate this version</button>' +
        '<button type="button" class="btn tiny secondary" id="lsbCompareVersion">Compare with active version</button>' +
        '<button type="button" class="btn tiny secondary" id="lsbToggleJson">Show JSON</button>' +
      '</div>' +
      versionMeta(selected) +
      renderWarnings(selected) +
      '<h3>Section status policy</h3>' + renderSectionStatusPolicy(bp.section_status_policy || {}) +
      '<h3>Maya current task templates</h3>' + renderTaskTemplates(bp.maya_current_task_templates || {}) +
      '<h3>Coach mode definitions</h3>' + renderCoachModeDefinitions(bp.coach_mode_definitions || {}) +
      '<h3>Coaching sequence</h3>' + renderCoachingSequence(bp.coaching_sequence || []) +
      '<h3>Summary structure</h3>' + renderSections(bp.summary_structure || []) +
      '<h3>Slide coverage map</h3>' + renderSlides(bp.slide_coverage_map || []) +
      '<h3>Common misconceptions</h3>' + renderList(bp.common_misconceptions || []) +
      '<h3>Student personalization allowed</h3>' + renderList(bp.student_personalization_allowed || []) +
      '<h3>Global do-not-ask boundaries</h3>' + renderList(bp.global_do_not_ask || []) +
      '<h3>Global not-required boundaries</h3>' + renderList(bp.not_required_global || []) +
      '<div id="lsbCompareWrap"></div>' +
      '<div class="lsb-json-wrap lsb-hidden" id="lsbJsonWrap"><h3>Blueprint JSON</h3><textarea readonly>' + esc(JSON.stringify(bp, null, 2)) + '</textarea></div>';
  }

  function metric(label, value) {
    return '<div class="lsb-metric"><div class="lsb-metric-label">' + esc(label) + '</div><div class="lsb-metric-value">' + value + '</div></div>';
  }

  function versionMeta(v) {
    if (!v) return '<p class="muted">No versions yet. Generate a blueprint to create version 1.</p>';
    return '<div class="lsb-modal-grid">' +
      metric('Generated date', esc(v.generated_at || '-')) +
      metric('Generation reason', esc(v.generation_reason || '-')) +
      metric('Source hash', '<code>' + esc(v.source_enrichment_hash || '') + '</code>') +
      metric('Status', statusBadge(v.status)) +
    '</div>';
  }

  function renderWarnings(v) {
    if (!v || !Array.isArray(v.warnings) || !v.warnings.length) {
      return '<h3>Warnings</h3><p class="muted">No warnings.</p>';
    }
    return '<h3>Warnings</h3>' + renderList(v.warnings.map(function (w) {
      if (typeof w === 'string') return w;
      return (w.severity ? '[' + w.severity + '] ' : '') + (w.message || JSON.stringify(w));
    }));
  }

  function renderSectionStatusPolicy(policy) {
    return '<div class="lsb-section-card">' +
      '<p><strong>Good enough rule:</strong> ' + esc(policy.good_enough_rule || '') + '</p>' +
      '<p><strong>Max refinement turns:</strong> ' + esc(policy.max_refinement_turns == null ? '' : policy.max_refinement_turns) + '</p>' +
      '<p><strong>Do not reopen after complete:</strong> ' + yesNo(policy.do_not_reopen_after_complete) + '</p>' +
    '</div>';
  }

  function renderTaskTemplates(templates) {
    return '<div class="lsb-section-card">' +
      '<p><strong>Watch slides:</strong> ' + esc(templates.watch_slides || '') + '</p>' +
      '<p><strong>Write summary:</strong> ' + esc(templates.write_summary || '') + '</p>' +
      '<p><strong>Chat check:</strong> ' + esc(templates.chat_check || '') + '</p>' +
    '</div>';
  }

  function renderCoachModeDefinitions(definitions) {
    return '<div class="lsb-section-card">' +
      '<p><strong>summary_editor:</strong> ' + esc(definitions.summary_editor || '') + '</p>' +
      '<p><strong>guided_capture:</strong> ' + esc(definitions.guided_capture || '') + '</p>' +
      '<p><strong>clarification_check:</strong> ' + esc(definitions.clarification_check || '') + '</p>' +
      '<p><strong>polishing:</strong> ' + esc(definitions.polishing || '') + '</p>' +
    '</div>';
  }

  function renderSections(sections) {
    if (!sections.length) return '<p class="muted">No structure in this version.</p>';
    return sections.map(function (s) {
      const scaffold = s.student_summary_scaffold || {};
      const behavior = s.section_completion_behavior || {};
      return '<div class="lsb-section-card">' +
        '<h4>' + esc(s.order || '') + '. ' + esc(s.title || '') + ' <code>' + esc(s.section_id || '') + '</code></h4>' +
        '<p><strong>Requires student section:</strong> ' + yesNo(s.requires_student_section) + ' · <strong>Intro/context:</strong> ' + yesNo(s.is_intro_context) + '</p>' +
        '<p><strong>Covered slides:</strong> ' + esc((s.covered_by_slides || []).join(', ') || '-') + '</p>' +
        '<p><strong>Student scaffold:</strong> heading "' + esc(scaffold.heading || '') + '", placeholders ' + esc((scaffold.placeholder_bullets || []).join(', ') || 'none') + '</p>' +
        blockList('Required concepts', s.required_concepts || []) +
        blockList('Operational focus', s.operational_focus || []) +
        blockList('Allowed coaching focus', s.allowed_coaching_focus || []) +
        blockList('Minimum completion checks', s.minimum_completion_check || []) +
        renderAcceptanceCriteria(s.section_acceptance_criteria || {}) +
        blockList('Not required', s.not_required || []) +
        blockList('Do not ask', s.do_not_ask || []) +
        '<p><strong>Completion:</strong> why reasoning ' + yesNo(s.completion_requirements && s.completion_requirements.requires_why_reasoning) +
          ', pilot action ' + yesNo(s.completion_requirements && s.completion_requirements.requires_pilot_action) +
          ', minimum bullets ' + esc(s.completion_requirements ? s.completion_requirements.minimum_student_bullets : 1) + '</p>' +
        '<p><strong>When complete:</strong> ' + esc(behavior.when_complete || '-') + '</p>' +
        blockList('Do not reopen unless', behavior.do_not_reopen_unless || []) +
        renderReferences(s.official_references || []) +
      '</div>';
    }).join('');
  }

  function renderAcceptanceCriteria(criteria) {
    return '<div class="lsb-section-card" style="background:#fafafa;">' +
      '<strong>Section acceptance criteria</strong>' +
      blockList('Must have', criteria.must_have || []) +
      blockList('Nice to have', criteria.nice_to_have || []) +
      blockList('Not needed', criteria.not_needed || []) +
    '</div>';
  }

  function renderCoachingSequence(sequence) {
    if (!Array.isArray(sequence) || !sequence.length) return '<p class="muted">No coaching sequence in this version.</p>';
    return sequence.map(function (step) {
      const guidance = step.slide_group_guidance || {};
      return '<div class="lsb-section-card">' +
        '<h4>Step ' + esc(step.step || '') + ' · <code>' + esc(step.section_id || '') + '</code></h4>' +
        '<p><strong>Slides:</strong> ' + esc((step.slide_group || []).join(', ') || '-') + '</p>' +
        '<p><strong>Instruction:</strong> ' + esc(step.instruction_to_student || '') + '</p>' +
        '<p><strong>Coach mode:</strong> ' + esc(step.coach_mode || '') + '</p>' +
        '<p><strong>Watch instruction:</strong> ' + esc(guidance.watch_instruction || '') + '</p>' +
        '<p><strong>Why grouped:</strong> ' + esc(guidance.why_grouped || '') + '</p>' +
        '<p><strong>Ready to write when:</strong> ' + esc(guidance.ready_to_write_when || '') + '</p>' +
      '</div>';
    }).join('');
  }

  function renderSlides(slides) {
    if (!slides.length) return '<p class="muted">No slide map in this version.</p>';
    return slides.map(function (s) {
      return '<div class="lsb-slide-card">' +
        '<h4>Slide ' + esc(s.slide_number || '') + ' <span class="muted">ID ' + esc(s.slide_id || '') + '</span></h4>' +
        '<p><strong>Section:</strong> <code>' + esc(s.section_id || '') + '</code></p>' +
        blockList('Concepts', s.concepts || []) +
        '<p><strong>Requires summary work:</strong> ' + yesNo(s.requires_summary_work) + ' · <strong>Support slide:</strong> ' + yesNo(s.is_support_slide) + '</p>' +
        renderReferences(s.official_references || []) +
      '</div>';
    }).join('');
  }

  function renderReferences(refs) {
    if (!Array.isArray(refs) || !refs.length) {
      return '<p class="muted">No official references mapped.</p>';
    }
    return '<div><strong>Official references</strong>' + refs.map(function (r) {
      return '<div class="lsb-ref">' +
        '<div><strong>' + esc(r.source_type || 'Resource') + '</strong> · confidence ' + esc(r.confidence == null ? '-' : Number(r.confidence).toFixed(2)) + '</div>' +
        '<div>' + esc(r.reference_code || '') + ' — ' + esc(r.reference_title || '') + '</div>' +
        '<div class="muted">' + esc(r.reference_path || '') + '</div>' +
        '<div class="muted">resource ' + esc(r.resource_id || 0) + ', edition ' + esc(r.edition_id || 0) + '</div>' +
      '</div>';
    }).join('') + '</div>';
  }

  function renderList(items) {
    if (!Array.isArray(items) || !items.length) return '<p class="muted">None.</p>';
    return '<ul>' + items.map(function (item) { return '<li>' + esc(item) + '</li>'; }).join('') + '</ul>';
  }

  function blockList(label, items) {
    if (!Array.isArray(items) || !items.length) return '<p><strong>' + esc(label) + ':</strong> <span class="muted">None.</span></p>';
    return '<p><strong>' + esc(label) + ':</strong></p><div class="lsb-pill-list">' + items.map(function (item) {
      return '<span class="lsb-pill">' + esc(item) + '</span>';
    }).join('') + '</div>';
  }

  function yesNo(value) {
    return value ? 'yes' : 'no';
  }

  async function activateSelectedVersion() {
    const detail = state.detail;
    const selected = selectedVersion(detail);
    if (!selected) return;
    await apiPost({ action: 'activate_version', version_id: selected.id });
    await loadLessons();
    await openModal(selected.lesson_id);
  }

  async function compareSelectedVersion() {
    const detail = state.detail;
    const active = detail && detail.active_version;
    const selected = selectedVersion(detail);
    const wrap = document.getElementById('lsbCompareWrap');
    if (!wrap || !active || !selected) {
      if (wrap) wrap.innerHTML = '<div class="lsb-alert lsb-alert-warn">Active and selected versions are required for comparison.</div>';
      return;
    }
    const json = await apiGet({ action: 'compare', active_version_id: active.id, selected_version_id: selected.id });
    const c = json.comparison || {};
    const summary = c.summary || {};
    wrap.innerHTML = '<h3>Version comparison</h3>' +
      '<div class="lsb-compare-card">' +
        compareLine('Added sections', summary.added_sections, 'lsb-compare-added') +
        compareLine('Removed sections', summary.removed_sections, 'lsb-compare-removed') +
        compareLine('Changed titles', summary.changed_section_titles, 'lsb-compare-changed') +
        compareLine('Changed slide mappings', summary.changed_slide_mappings, 'lsb-compare-changed') +
        compareLine('Changed required concepts', summary.changed_required_concepts, 'lsb-compare-changed') +
      '</div>' +
      '<div class="lsb-compare-grid">' +
        '<div><h4>Active structure</h4>' + compactStructure(c.active_structure || []) + '</div>' +
        '<div><h4>Selected structure</h4>' + compactStructure(c.selected_structure || []) + '</div>' +
      '</div>';
  }

  function compareLine(label, items, cls) {
    const list = Array.isArray(items) && items.length ? items.join(', ') : 'none';
    return '<p class="' + cls + '"><strong>' + esc(label) + ':</strong> ' + esc(list) + '</p>';
  }

  function compactStructure(sections) {
    if (!sections.length) return '<p class="muted">No sections.</p>';
    return sections.map(function (s) {
      return '<div class="lsb-compare-card"><strong>' + esc(s.title || '') + '</strong><br><code>' + esc(s.section_id || '') + '</code><br><span class="muted">Slides ' + esc((s.covered_by_slides || []).join(', ') || '-') + '</span><br>' + esc((s.required_concepts || []).join(', ')) + '</div>';
    }).join('');
  }

  els.program.addEventListener('change', renderCourses);
  els.course.addEventListener('change', loadLessons);
  els.reload.addEventListener('click', loadLessons);
  els.selectAll.addEventListener('click', function () {
    document.querySelectorAll('.lsb-row-check').forEach(function (el) { el.checked = true; });
    els.toggleAll.checked = true;
  });
  els.clearSelection.addEventListener('click', function () {
    document.querySelectorAll('.lsb-row-check').forEach(function (el) { el.checked = false; });
    els.toggleAll.checked = false;
  });
  els.toggleAll.addEventListener('change', function () {
    document.querySelectorAll('.lsb-row-check').forEach(function (el) { el.checked = els.toggleAll.checked; });
  });
  els.generateMissing.addEventListener('click', function () {
    generateLessons(state.lessons.map(function (l) { return parseInt(l.lesson_id, 10); }), 'full_course_bulk', true);
  });
  els.regenerateFull.addEventListener('click', function () {
    generateLessons(state.lessons.map(function (l) { return parseInt(l.lesson_id, 10); }), 'full_course_bulk', false);
  });
  els.generateSelected.addEventListener('click', function () {
    generateLessons(selectedLessonIds(), 'selected_bulk', false);
  });
  els.activateSelected.addEventListener('click', activateSelectedLessons);
  els.tbody.addEventListener('click', function (ev) {
    const target = ev.target;
    if (!(target instanceof HTMLElement)) return;
    if (target.closest('button') || target.closest('input') || target.closest('select') || target.closest('a')) return;
    const row = target.closest('tr[data-lesson-id]');
    if (!row) return;
    const lessonId = parseInt(row.getAttribute('data-lesson-id') || '0', 10);
    if (lessonId) openModal(lessonId);
  });
  els.tbody.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter' && ev.key !== ' ') return;
    const target = ev.target;
    if (!(target instanceof HTMLElement)) return;
    if (target.closest('button') || target.closest('input') || target.closest('select') || target.closest('a')) return;
    const row = target.closest('tr[data-lesson-id]');
    if (!row) return;
    ev.preventDefault();
    const lessonId = parseInt(row.getAttribute('data-lesson-id') || '0', 10);
    if (lessonId) openModal(lessonId);
  });
  els.tbody.addEventListener('click', function (ev) {
    const target = ev.target;
    if (!(target instanceof HTMLElement)) return;
    const lessonId = parseInt(target.getAttribute('data-lesson-id') || '0', 10);
    if (!lessonId) return;
    if (target.classList.contains('lsb-generate-one')) {
      generateLessons([lessonId], 'manual_regenerate', false);
    }
    if (target.classList.contains('lsb-regenerate-one')) {
      generateLessons([lessonId], 'manual_regenerate', false);
    }
  });
  els.closeModal.addEventListener('click', closeModal);
  els.modalBackdrop.addEventListener('click', closeModal);
  els.modalBody.addEventListener('click', function (ev) {
    const target = ev.target;
    if (!(target instanceof HTMLElement)) return;
    if (target.id === 'lsbViewVersion') renderModal();
    if (target.id === 'lsbActivateVersion') activateSelectedVersion().catch(function (e) { alert(e.message); });
    if (target.id === 'lsbCompareVersion') compareSelectedVersion().catch(function (e) { alert(e.message); });
    if (target.id === 'lsbToggleJson') {
      const wrap = document.getElementById('lsbJsonWrap');
      if (wrap) wrap.classList.toggle('lsb-hidden');
    }
  });
  els.modalBody.addEventListener('change', function (ev) {
    const target = ev.target;
    if (target instanceof HTMLSelectElement && target.id === 'lsbVersionSelect') {
      state.selectedVersionId = parseInt(target.value, 10) || 0;
      renderModal();
    }
  });

  renderSources();
  renderLessons();
})();
</script>
<?php cw_footer(); ?>
