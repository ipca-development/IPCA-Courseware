<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_admin();

$courses = $pdo->query("
  SELECT c.id, c.title, c.program_id, p.program_key
  FROM courses c
  JOIN programs p ON p.id = c.program_id
  ORDER BY p.sort_order, c.sort_order, c.id
")->fetchAll(PDO::FETCH_ASSOC);

$programs = $pdo->query('SELECT id, program_key, name FROM programs ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);

$lessonsFlat = $pdo->query("
  SELECT l.id, l.course_id, l.external_lesson_id, l.title, c.program_id, c.title AS course_title
  FROM lessons l
  INNER JOIN courses c ON c.id = l.course_id
  ORDER BY c.program_id, c.sort_order, c.id, l.sort_order, l.external_lesson_id
")->fetchAll(PDO::FETCH_ASSOC);

$lessonsByProgram = [];
foreach ($lessonsFlat as $row) {
    $pid = (int)$row['program_id'];
    if (!isset($lessonsByProgram[$pid])) {
        $lessonsByProgram[$pid] = [];
    }
    $lessonsByProgram[$pid][] = [
        'id' => (int)$row['id'],
        'course_id' => (int)$row['course_id'],
        'external_lesson_id' => (int)$row['external_lesson_id'],
        'title' => (string)$row['title'],
        'course_title' => (string)$row['course_title'],
    ];
}

$coursesByProgram = [];
foreach ($courses as $cRow) {
    $pid = (int)$cRow['program_id'];
    if (!isset($coursesByProgram[$pid])) {
        $coursesByProgram[$pid] = [];
    }
    $coursesByProgram[$pid][] = [
        'id' => (int)$cRow['id'],
        'title' => (string)$cRow['title'],
        'program_key' => (string)$cRow['program_key'],
    ];
}

$becEmbed = [
    'programs' => array_map(static function ($p) {
        return [
            'id' => (int)$p['id'],
            'key' => (string)($p['program_key'] ?? ''),
            'name' => (string)($p['name'] ?? ''),
        ];
    }, $programs),
    'coursesByProgram' => $coursesByProgram,
    'lessonsByProgram' => $lessonsByProgram,
];
cw_header('Bulk enrich');
?>
<div class="card bec-hub">
  <h2>Bulk enrich</h2>
  <p class="muted">
    Scope by <strong>program</strong>, then optionally narrow to a <strong>course</strong> or <strong>lesson</strong>.
    Coverage and runs use only <strong>active</strong> slides (<code>slides.is_deleted = 0</code>). Video expectations come from the selected manifest under <code>public/assets/</code>.
  </p>

  <div class="bec-toolbar form-grid" style="align-items:end;">
    <label>Program</label>
    <select id="becProgram" class="bec-select-wide">
      <option value="0">— Select program —</option>
      <?php foreach ($programs as $p): ?>
        <option value="<?= (int)$p['id'] ?>" data-key="<?= h((string)$p['program_key']) ?>">
          <?= h((string)($p['name'] ?? $p['program_key'])) ?> (<?= h((string)$p['program_key']) ?>)
        </option>
      <?php endforeach; ?>
    </select>

    <label>Course <span class="muted">(optional)</span></label>
    <select id="becCourse" disabled>
      <option value="0">All courses in program</option>
    </select>

    <label>Lesson <span class="muted">(optional)</span></label>
    <select id="becLesson" disabled>
      <option value="0">All lessons in scope</option>
    </select>

    <label>Video manifest</label>
    <select id="becVideoManifest" title="JSON files in public/assets/"></select>

    <label title="Saved in this browser">Rows / page</label>
    <select id="becPageSize" title="Coverage table page size (saved locally)">
      <option value="50">50</option>
      <option value="100">100</option>
      <option value="200">200</option>
      <option value="300">300</option>
      <option value="400">400</option>
      <option value="500">500</option>
    </select>
  </div>

  <div id="becStatsRow" class="bec-stats bec-hidden">
    <button type="button" class="bec-stat" data-filter="all" title="Show all slide rows (paged)">
      <div class="bec-stat-label">Active slides</div>
      <div class="bec-stat-value" id="becStatActive">—</div>
    </button>
    <button type="button" class="bec-stat" data-filter="en_missing">
      <div class="bec-stat-label">English text</div>
      <div class="bec-stat-value"><span id="becStatEnOk">—</span> / <span id="becStatEnNeed">—</span></div>
    </button>
    <button type="button" class="bec-stat" data-filter="es_missing">
      <div class="bec-stat-label">Spanish text</div>
      <div class="bec-stat-value"><span id="becStatEsOk">—</span> / <span id="becStatEsNeed">—</span></div>
    </button>
    <button type="button" class="bec-stat" data-filter="narr_en_missing">
      <div class="bec-stat-label">Narration EN</div>
      <div class="bec-stat-value"><span id="becStatNenOk">—</span> / <span id="becStatNenNeed">—</span></div>
    </button>
    <button type="button" class="bec-stat" data-filter="narr_es_missing">
      <div class="bec-stat-label">Narration ES</div>
      <div class="bec-stat-value"><span id="becStatNesOk">—</span> / <span id="becStatNesNeed">—</span></div>
    </button>
    <button type="button" class="bec-stat" data-filter="refs_missing">
      <div class="bec-stat-label">References</div>
      <div class="bec-stat-value"><span id="becStatRefOk">—</span> / <span id="becStatRefNeed">—</span></div>
    </button>
    <button type="button" class="bec-stat" data-filter="video_hotspot_missing">
      <div class="bec-stat-label">Video hotspots</div>
      <div class="bec-stat-value"><span id="becStatVidOk">—</span> / <span id="becStatVidNeed">—</span></div>
    </button>
    <button type="button" class="bec-stat" data-filter="flagged">
      <div class="bec-stat-label">Flagged</div>
      <div class="bec-stat-value" id="becStatFlagged">—</div>
    </button>
  </div>

  <div id="becProgressWrap" class="bec-progress bec-hidden">
    <div class="bec-progress-label">
      <span id="becProgressPct">0</span>% overall readiness
      <span class="muted" id="becProgressSub"></span>
    </div>
    <div class="bec-progress-bar"><div class="bec-progress-fill" id="becProgressFill" style="width:0%"></div></div>
  </div>

  <div class="bec-actions bec-hidden" id="becActions">
    <h3 style="margin:16px 0 8px; font-size:1rem;">AI actions</h3>
    <div class="bec-check-grid">
      <label><input type="checkbox" id="becDoEn" checked> 1) Extract English from slide</label>
      <label><input type="checkbox" id="becDoEs" checked> 2) English → Spanish (slide body text)</label>
      <label><input type="checkbox" id="becDoNarrEn" checked> 3) English narration (from vision)</label>
      <label><input type="checkbox" id="becDoNarrEs" checked> 4) Spanish narration (translate narration EN → ES)</label>
      <label><input type="checkbox" id="becDoRefs" checked> 5) PHAK + ACS references</label>
      <label><input type="checkbox" id="becDoHotspots" checked> 6) Video hotspots (manifest)</label>
      <label class="muted" style="grid-column:1/-1; font-size:12px;">Slide Spanish (2) and narration Spanish (4) are independent. Legacy API clients that only send <code>do_narration</code> + <code>do_es</code> still imply Spanish narration.</label>
      <label><input type="checkbox" id="becSkipExisting" checked> Skip slides that already have EN extract</label>
      <p class="muted" style="grid-column:1/-1; font-size:12px; margin:0;">
        Vision enrichment uses <strong>Resource Library</strong> handbook excerpts when an edition is <strong>Live</strong> and indexed (<a href="/admin/resource_library.php">Resource Library</a>). Turn Live off there to disable; optional env <code>CW_RESOURCE_LIBRARY_ENRICH_EDITION_ID</code> must also point at a live edition.
      </p>
    </div>
    <div class="bec-run-row">
      <label>Slides / batch
        <input type="number" id="becBatchSize" value="35" min="5" max="100" step="1" style="width:4rem;margin-left:6px;">
      </label>
      <button type="button" class="btn secondary" id="becBtnReload">Refresh list</button>
      <button type="button" class="btn secondary" id="becBtnPipeline">Run recommended 3‑phase pipeline</button>
      <button type="button" class="btn" id="becBtnRun">Start enrichment (selected actions)</button>
    </div>
    <p class="muted" style="font-size:12px;margin-top:8px;">
      <strong>Phase 1</strong> vision: English + English narration + references (no slide ES / no narr ES / no hotspots).
      <strong>Phase 2</strong>: slide body ES + Spanish narration from DB/vision EN narration.
      <strong>Phase 3</strong>: video hotspots. Progress streams over <strong>SSE</strong> in the log below — keep this tab open.
    </p>
  </div>

  <div id="becRunPanel" class="bec-run-panel bec-hidden">
    <div class="bec-run-title">Run status</div>
    <div id="becRunStatus" class="bec-run-status">Idle.</div>
    <pre id="becRunLog" class="bec-run-log"></pre>
  </div>

  <div id="becTableWrap" class="bec-hidden" style="margin-top:16px;">
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:8px;">
      <button type="button" class="btn secondary" id="becSelectVisible">Select visible</button>
      <button type="button" class="btn secondary" id="becClearSel">Clear selection</button>
      <button type="button" class="btn secondary" id="becLoadMore">Load more rows</button>
      <button type="button" class="btn" id="becBtnSelected">Enrich selected slides</button>
      <button type="button" class="btn secondary" id="becBtnSoftDeleteSelected">Soft-delete selected</button>
      <span class="muted" id="becPageHint"></span>
    </div>
    <div class="bec-table-scroll">
      <table class="bec-grid-table" id="becTable">
        <thead>
          <tr>
            <th><input type="checkbox" id="becToggleAll" title="Toggle visible"></th>
            <th>Thumb</th>
            <th>EN</th>
            <th>ES</th>
            <th>N·EN</th>
            <th>N·ES</th>
            <th>Refs</th>
            <th>Video</th>
            <th>⚠</th>
            <th>Hide</th>
            <th>1‑slide</th>
          </tr>
        </thead>
        <tbody id="becTbody"></tbody>
      </table>
    </div>
  </div>
</div>

<style>
.bec-hub .bec-stats { display:flex; flex-wrap:wrap; gap:10px; margin:14px 0; }
.bec-hub .bec-stat {
  min-width:118px; padding:10px 12px; border:1px solid #e2e8f0; border-radius:10px;
  background:#f8fafc; text-align:left; cursor:pointer; font:inherit;
}
.bec-hub .bec-stat:hover { border-color:#94a3b8; background:#fff; }
.bec-hub .bec-stat.bec-stat-active { border-color:#2563eb; box-shadow:0 0 0 1px #2563eb33; }
.bec-stat-label { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#64748b; }
.bec-stat-value { font-size:18px; font-weight:700; color:#0f172a; margin-top:4px; }
.bec-progress { margin:12px 0 4px; }
.bec-progress-label { font-size:14px; margin-bottom:6px; }
.bec-progress-bar { height:10px; background:#e2e8f0; border-radius:999px; overflow:hidden; }
.bec-progress-fill { height:100%; background:linear-gradient(90deg,#2563eb,#38bdf8); transition:width .25s ease; }
.bec-check-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:8px 16px; margin:8px 0; }
.bec-run-row { display:flex; flex-wrap:wrap; gap:12px; align-items:center; margin-top:10px; }
.bec-run-panel { margin-top:16px; border:1px solid #e2e8f0; border-radius:10px; padding:12px; background:#fafafa; }
.bec-run-title { font-weight:600; margin-bottom:6px; }
.bec-run-status { font-size:14px; color:#0f172a; margin-bottom:8px; }
.bec-run-log { max-height:42vh; overflow:auto; font-size:12px; white-space:pre-wrap; background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:10px; margin:0; }
.bec-table-scroll { overflow:auto; max-height:65vh; border:1px solid #e5e7eb; border-radius:10px; }
.bec-grid-table { width:100%; border-collapse:collapse; font-size:12px; }
.bec-grid-table th, .bec-grid-table td { border-bottom:1px solid #f1f5f9; padding:6px 8px; vertical-align:top; }
.bec-grid-table th { position:sticky; top:0; background:#f8fafc; z-index:1; text-align:left; }
.bec-thumb { width:94px; height:70px; object-fit:cover; border-radius:6px; background:#e2e8f0; display:block; }
.bec-ov-thumb { position:relative; width:94px; height:53px; border-radius:6px; overflow:hidden; background:#e2e8f0; display:inline-block; vertical-align:middle; border:1px solid #e5e7eb; }
.bec-ov-thumb .bec-ov-stage { position:absolute; left:0; top:0; width:1600px; height:900px; transform:scale(0.05875); transform-origin:top left; background:#fff; }
.bec-ov-thumb .bec-ov-content { position:absolute; width:1315px; height:900px; left:calc((1600px - 1315px) / 2); top:0; object-fit:contain; background:#fff; }
.bec-ov-thumb .bec-ov-head { position:absolute; left:0; top:0; width:1600px; height:125px; object-fit:cover; pointer-events:none; }
.bec-ov-thumb .bec-ov-foot { position:absolute; left:0; bottom:0; width:1600px; height:90px; object-fit:cover; pointer-events:none; }
.bec-thumb-link { display:inline-block; text-decoration:none; }
.bec-thumb-link:focus { outline:2px solid #2563eb; outline-offset:2px; }
.bec-preview { max-height:4.5rem; overflow:hidden; font-size:11px; line-height:1.25; color:#334155; white-space:pre-wrap; }
.bec-preview:hover { overflow:visible; max-height:none; z-index:2; position:relative; background:#fffbeb; box-shadow:0 2px 8px #0001; }
.bec-yes { color:#047857; font-weight:700; }
.bec-no { color:#b45309; font-weight:700; }
.bec-warn { color:#b91c1c; font-weight:700; }
.bec-hidden { display:none !important; }
.bec-row-flagged { background:#fffbeb; }
.btn.tiny { font-size:12px; padding:4px 8px; }
</style>

<script>
var BEC_DATA = <?= json_encode($becEmbed, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
(function () {
  var DATA = BEC_DATA;

  var BEC_LIST_LIMIT_KEY = 'bec_list_limit';
  var BEC_PAGE_SIZES = [50, 100, 200, 300, 400, 500];

  var state = {
    programId: 0,
    programKey: '',
    courseId: 0,
    lessonId: 0,
    videoManifest: 'kings_videos_manifest.json',
    filter: 'all',
    offset: 0,
    pageSize: 100,
    lastPayload: null,
    running: false,
    abortRun: false
  };

  function el(id) { return document.getElementById(id); }

  function setHidden(id, on) {
    var n = el(id);
    if (!n) return;
    if (on) n.classList.add('bec-hidden'); else n.classList.remove('bec-hidden');
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function yn(v) {
    return v ? '<span class="bec-yes">Yes</span>' : '<span class="bec-no">No</span>';
  }

  function fillProgram(skipLoad) {
    skipLoad = skipLoad === true;
    var pid = parseInt(el('becProgram').value, 10) || 0;
    state.programId = pid;
    var opt = el('becProgram').selectedOptions[0];
    state.programKey = opt ? (opt.getAttribute('data-key') || '') : '';

    var cSel = el('becCourse');
    var lSel = el('becLesson');
    cSel.innerHTML = '<option value="0">All courses in program</option>';
    lSel.innerHTML = '<option value="0">All lessons in scope</option>';
    state.courseId = 0;
    state.lessonId = 0;

    if (pid <= 0) {
      cSel.disabled = true;
      lSel.disabled = true;
      setHidden('becStatsRow', true);
      setHidden('becProgressWrap', true);
      setHidden('becActions', true);
      setHidden('becTableWrap', true);
      setHidden('becRunPanel', true);
      return;
    }

    var courses = (DATA.coursesByProgram[String(pid)] || DATA.coursesByProgram[pid] || []);
    courses.forEach(function (c) {
      var o = document.createElement('option');
      o.value = String(c.id);
      o.textContent = (c.program_key || '') + ' — ' + (c.title || '');
      cSel.appendChild(o);
    });
    cSel.disabled = false;
    fillLessons();
    if (!skipLoad) loadCoverage(false);
  }

  function fillLessons() {
    var pid = state.programId;
    var cid = parseInt(el('becCourse').value, 10) || 0;
    state.courseId = cid;
    state.lessonId = 0;
    el('becLesson').value = '0';
    var lSel = el('becLesson');
    lSel.innerHTML = '<option value="0">All lessons in scope</option>';
    state.lessonId = 0;

    var lessons = DATA.lessonsByProgram[String(pid)] || DATA.lessonsByProgram[pid] || [];
    lessons.forEach(function (L) {
      if (cid > 0 && L.course_id !== cid) return;
      var o = document.createElement('option');
      o.value = String(L.id);
      o.setAttribute('data-course-id', String(L.course_id));
      o.textContent = (L.course_title || '') + ' · ' + L.external_lesson_id + ' — ' + (L.title || '');
      lSel.appendChild(o);
    });
    lSel.disabled = false;
  }

  function coverageUrl(extra) {
    var q = [];
    q.push('video_manifest=' + encodeURIComponent(state.videoManifest));
    if (state.courseId > 0) {
      q.push('course_id=' + encodeURIComponent(String(state.courseId)));
    }
    if (state.programId > 0) {
      q.push('program_id=' + encodeURIComponent(String(state.programId)));
    }
    if (state.lessonId > 0) q.push('lesson_id=' + encodeURIComponent(String(state.lessonId)));
    if (state.filter && state.filter !== 'all') q.push('filter=' + encodeURIComponent(state.filter));
    q.push('offset=' + encodeURIComponent(String(state.offset)));
    q.push('limit=' + encodeURIComponent(String(state.pageSize)));
    if (extra) q.push(extra);
    return '/admin/api/bulk_enrich_coverage.php?' + q.join('&');
  }

  function updateStatActiveClass() {
    document.querySelectorAll('.bec-stat').forEach(function (b) {
      var f = b.getAttribute('data-filter') || 'all';
      if (f === state.filter || (state.filter === 'all' && f === 'all')) {
        b.classList.add('bec-stat-active');
      } else {
        b.classList.remove('bec-stat-active');
      }
    });
  }

  function applyDashboard(d, s) {
    var total = Math.max(1, (s && s.total) ? s.total : 1);
    el('becStatActive').textContent = String((d && d.active_slides) != null ? d.active_slides : '—');
    el('becStatEnOk').textContent = String(s.en_ok);
    el('becStatEnNeed').textContent = String(total);
    el('becStatEsOk').textContent = String(s.es_ok);
    el('becStatEsNeed').textContent = String(total);
    el('becStatNenOk').textContent = String(s.narr_en_ok);
    el('becStatNenNeed').textContent = String(total);
    el('becStatNesOk').textContent = String(s.narr_es_ok);
    el('becStatNesNeed').textContent = String(total);
    el('becStatRefOk').textContent = String(s.refs_ok != null ? s.refs_ok : 0);
    el('becStatRefNeed').textContent = String(total);
    el('becStatVidOk').textContent = String(s.hotspot_expected_ok || 0);
    el('becStatVidNeed').textContent = String(Math.max(0, s.hotspot_expected || 0));
    el('becStatFlagged').textContent = String(s.flagged || 0);
    var pct = (d && d.completion_percent != null) ? d.completion_percent : 0;
    el('becProgressPct').textContent = String(pct);
    el('becProgressFill').style.width = Math.min(100, Math.max(0, pct)) + '%';
    var sub = '';
    if (s.coverage_warning) sub = ' · ' + s.coverage_warning;
    el('becProgressSub').textContent = sub;
  }

  function appendRows(rows, append) {
    var tb = el('becTbody');
    if (!append) tb.innerHTML = '';
    rows.forEach(function (row) {
      if (row.placeholder) return;
      var ch = row.checks || {};
      var tr = document.createElement('tr');
      tr.id = 'bec-row-' + row.slide_id;
      if (row.flagged) tr.className = 'bec-row-flagged';
      var low = ch.refs_low_confidence ? '⚠' : '—';
      var vidCell = ch.manifest_lists_video
        ? (ch.video_hotspot ? '<span class="bec-yes">Ready</span>' : '<span class="bec-no">Missing HS</span>')
        : '<span class="muted">n/a</span>';
      var editorUrl = row.overlay_editor_url;
      if (!editorUrl) {
        var qEditor = [
          'slide_id=' + encodeURIComponent(String(row.slide_id)),
          'course_id=' + encodeURIComponent(String(row.course_id)),
          'lesson_id=' + encodeURIComponent(String(row.lesson_id || 0)),
          'return_to=bulk_enrich',
          'program_id=' + encodeURIComponent(String(row.program_id || state.programId || 0)),
          'video_manifest=' + encodeURIComponent(state.videoManifest || 'kings_videos_manifest.json')
        ];
        editorUrl = '/admin/slide_overlay_editor.php?' + qEditor.join('&');
      }
      var thumb = row.thumb_url
        ? ('<a class="bec-thumb-link" href="' + esc(editorUrl) + '" title="Open Designer (IPCA overlay)">'
          + '<span class="bec-ov-thumb"><span class="bec-ov-stage">'
          + '<img class="bec-ov-content" src="' + esc(row.thumb_url) + '" alt="" loading="lazy">'
          + '<img class="bec-ov-head" src="/assets/overlay/header.png" alt="">'
          + '<img class="bec-ov-foot" src="/assets/overlay/footer.png" alt="">'
          + '</span></span></a>')
        : '<span class="muted">—</span>';
      tr.innerHTML =
        '<td><input type="checkbox" class="bec-sl" data-id="' + row.slide_id + '" data-course="' + row.course_id + '"></td>' +
        '<td>' + thumb + '</td>' +
        '<td title="English">' + yn(!!ch.extract_en) + '<div class="bec-preview">' + esc(row.preview_en || '') + '</div></td>' +
        '<td title="Spanish">' + yn(!!ch.translate_es) + '<div class="bec-preview">' + esc(row.preview_es || '') + '</div></td>' +
        '<td title="Narration EN">' + yn(!!ch.narration_en) + '<div class="bec-preview">' + esc(row.preview_narr_en || '') + '</div></td>' +
        '<td title="Narration ES">' + yn(!!ch.narration_es) + '<div class="bec-preview">' + esc(row.preview_narr_es || '') + '</div></td>' +
        '<td title="PHAK+ACS+confidence">' + yn(!!ch.refs_ok) + '</td>' +
        '<td title="Manifest video / hotspot">' + vidCell + '</td>' +
        '<td>' + (low === '⚠' ? '<span class="bec-warn">⚠</span>' : '—') + '</td>' +
        '<td><button type="button" class="btn secondary tiny bec-soft" data-id="' + row.slide_id + '">Soft‑delete</button></td>' +
        '<td><button type="button" class="btn tiny bec-one" data-id="' + row.slide_id + '" data-course="' + row.course_id + '">Enrich</button></td>';
      tb.appendChild(tr);
    });
  }

  function loadCoverage(append) {
    if (state.programId <= 0 && state.courseId <= 0) return;
    if (!append) state.offset = 0;
    fetch(coverageUrl(''), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          alert(data && data.error ? data.error : 'Coverage failed');
          return;
        }
        state.lastPayload = data;
        setHidden('becStatsRow', false);
        setHidden('becProgressWrap', false);
        setHidden('becActions', false);
        setHidden('becTableWrap', false);
        applyDashboard(data.dashboard || {}, data.summary || {});
        updateStatActiveClass();
        appendRows(data.slides || [], append);
        var pag = data.pagination || {};
        el('becPageHint').textContent = 'Showing ' + (pag.returned || 0) + ' of ' + (pag.filtered_total || 0)
          + (pag.has_more ? ' · more available' : '');
        scrollToFocusSlideFromUrl();
      })
      .catch(function () { alert('Network error loading coverage'); });
  }

  function scrollToFocusSlideFromUrl() {
    var p = new URLSearchParams(window.location.search);
    var fid = parseInt(p.get('focus_slide') || '0', 10);
    if (fid <= 0) return;
    var row = document.getElementById('bec-row-' + fid);
    if (row) row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    p.delete('focus_slide');
    var q = p.toString();
    var path = window.location.pathname + (q ? '?' + q : '') + window.location.hash;
    window.history.replaceState({}, '', path);
  }

  function hydrateBulkFromQuery() {
    var p = new URLSearchParams(window.location.search);
    var pid = parseInt(p.get('program_id') || '0', 10);
    if (pid <= 0) return;
    el('becProgram').value = String(pid);
    fillProgram(true);
    var cid = parseInt(p.get('course_id') || '0', 10);
    if (cid > 0) {
      var cOpt = el('becCourse').querySelector('option[value="' + String(cid) + '"]');
      if (cOpt) el('becCourse').value = String(cid);
    }
    fillLessons();
    var lid = parseInt(p.get('lesson_id') || '0', 10);
    if (lid > 0) {
      var lOpt = el('becLesson').querySelector('option[value="' + String(lid) + '"]');
      if (lOpt) el('becLesson').value = String(lid);
    }
    state.lessonId = parseInt(el('becLesson').value, 10) || 0;
    state.courseId = parseInt(el('becCourse').value, 10) || 0;
    if (state.lessonId > 0) {
      var opt = el('becLesson').selectedOptions[0];
      var dc = opt ? parseInt(opt.getAttribute('data-course-id') || '0', 10) : 0;
      if (dc > 0) state.courseId = dc;
    }
    var vm = p.get('video_manifest');
    if (vm) {
      state.videoManifest = vm;
      var sel = el('becVideoManifest');
      var found = false;
      sel.querySelectorAll('option').forEach(function (o) { if (o.value === vm) found = true; });
      if (!found) {
        var o = document.createElement('option');
        o.value = vm;
        o.textContent = vm;
        sel.appendChild(o);
      }
      sel.value = vm;
    }
    loadCoverage(false);
  }

  function loadManifests() {
    return fetch('/admin/api/bulk_enrich_manifest_list.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var sel = el('becVideoManifest');
        sel.innerHTML = '';
        (data.files || []).forEach(function (f) {
          var o = document.createElement('option');
          o.value = f;
          o.textContent = f;
          sel.appendChild(o);
        });
        if (!sel.options.length) {
          var o = document.createElement('option');
          o.value = 'kings_videos_manifest.json';
          o.textContent = 'kings_videos_manifest.json (add JSON under public/assets/)';
          sel.appendChild(o);
        }
        sel.value = state.videoManifest;
        state.videoManifest = sel.value || 'kings_videos_manifest.json';
      });
  }

  function appendLog(line) {
    var log = el('becRunLog');
    log.textContent += line + '\n';
    log.scrollTop = log.scrollHeight;
  }

  function setRun(on, msg) {
    state.running = on;
    el('becRunStatus').textContent = msg || (on ? 'Working…' : 'Idle.');
  }

  function mirrorActions(fd, flags) {
    flags = flags || {};
    function pick(key, elId) {
      return Object.prototype.hasOwnProperty.call(flags, key) ? flags[key] : el(elId).checked;
    }
    var useEn = pick('en', 'becDoEn');
    var useEs = pick('es', 'becDoEs');
    var useNarrEn = pick('narr_en', 'becDoNarrEn');
    var useNarrEs = pick('narr_es', 'becDoNarrEs');
    var useRefs = pick('refs', 'becDoRefs');
    var useHot = pick('hotspots', 'becDoHotspots');
    if (useEn) fd.append('do_en', '1');
    if (useEs) fd.append('do_es', '1');
    if (useNarrEn) {
      fd.append('do_narration_en', '1');
      fd.append('do_narration', '1');
    }
    if (useNarrEs) fd.append('do_narration_es', '1');
    if (useRefs) fd.append('do_refs', '1');
    if (useHot) fd.append('do_hotspots', '1');
    var skip = Object.prototype.hasOwnProperty.call(flags, 'skip_existing')
      ? flags.skip_existing
      : el('becSkipExisting').checked;
    if (skip) fd.append('skip_existing', '1');
    fd.append('program_key', state.programKey || 'private');
    fd.append('video_manifest', state.videoManifest);
  }

  function handleSseEvent(evName, data) {
    if (evName === 'run_start') {
      appendLog('— Run start · course ' + (data.course_id != null ? data.course_id : '?'));
      return;
    }
    if (evName === 'batch_info') {
      appendLog('— Batch: ' + (data.slides_in_batch || 0) + ' slide(s) (scope total ' + (data.full_scope_slides || '') + ')');
      return;
    }
    if (evName === 'slide_start') {
      appendLog('Slide ' + data.slide_id + ' · lesson ' + data.external_lesson_id + ' · page ' + data.page_number);
      return;
    }
    if (evName === 'slide_skipped') {
      appendLog('  skip: ' + (data.reason || '') + ' (slide ' + data.slide_id + ')');
      return;
    }
    if (evName === 'step') {
      appendLog('  ' + (data.phase || '') + ': ' + (data.message || ''));
      return;
    }
    if (evName === 'slide_error') {
      appendLog('  ERROR: ' + (data.error || '') + ' [slide ' + data.slide_id + ']');
      return;
    }
    if (evName === 'slide_done') {
      appendLog((data.ok === false ? '  ✗ finished slide ' : '  ✓ finished slide ') + data.slide_id);
      return;
    }
    if (evName === 'run_done') {
      appendLog('— Run done · processed ' + (data.processed != null ? data.processed : '?'));
      return;
    }
    if (evName === 'run_error') {
      appendLog('— RUN ERROR: ' + (data.message || JSON.stringify(data)));
    }
  }

  function parseSseBlock(block) {
    var evName = 'message';
    var dataLines = [];
    block.split(/\r?\n/).forEach(function (line) {
      if (!line || line[0] === ':') return;
      if (line.indexOf('event:') === 0) evName = line.slice(6).trim();
      else if (line.indexOf('data:') === 0) dataLines.push(line.slice(5).trim());
    });
    if (!dataLines.length) return;
    var payload = dataLines.join('\n');
    try {
      handleSseEvent(evName, JSON.parse(payload));
    } catch (e) {
      appendLog('[SSE parse] ' + payload.slice(0, 240));
    }
  }

  function runBatchSSE(fd) {
    return fetch('/admin/api/bulk_enrich_run_sse.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { Accept: 'text/event-stream' }
    }).then(function (res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      var reader = res.body.getReader();
      var dec = new TextDecoder();
      var buf = '';
      function pump() {
        return reader.read().then(function (result) {
          if (result.done) return;
          buf += dec.decode(result.value, { stream: true });
          var sep = '\n\n';
          var idx;
          while ((idx = buf.indexOf(sep)) >= 0) {
            var raw = buf.slice(0, idx);
            buf = buf.slice(idx + sep.length);
            parseSseBlock(raw);
          }
          return pump();
        });
      }
      return pump();
    });
  }

  function runCourseBatches(courseId, scope, lessonId, labelPrefix) {
    var batch = Math.max(5, Math.min(100, parseInt(el('becBatchSize').value, 10) || 35));
    var q = 'course_id=' + encodeURIComponent(String(courseId))
      + '&scope=' + encodeURIComponent(scope)
      + '&lesson_id=' + encodeURIComponent(String(lessonId || 0));
    return fetch('/admin/api/bulk_enrich_slide_count.php?' + q, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) throw new Error(data.error || 'count failed');
        var n = parseInt(data.slide_count, 10) || 0;
        if (n <= 0) {
          appendLog(labelPrefix + ' — no slides.');
          return Promise.resolve();
        }
        var batches = Math.ceil(n / batch);
        appendLog(labelPrefix + ' — ' + n + ' slide(s) in ' + batches + ' batch(es).');
        function runBi(i) {
          if (state.abortRun) {
            appendLog('Aborted by user.');
            return Promise.resolve();
          }
          if (i >= batches) return Promise.resolve();
          appendLog(labelPrefix + ' batch ' + (i + 1) + '/' + batches + '…');
          var fd = new FormData();
          fd.append('scope', scope);
          fd.append('course_id', String(courseId));
          fd.append('lesson_id', String(lessonId || 0));
          fd.append('limit', '0');
          fd.append('batch_offset', String(i * batch));
          fd.append('batch_size', String(Math.min(batch, n - i * batch)));
          mirrorActions(fd, null);
          return runBatchSSE(fd).then(function () {
            appendLog('  ✓ batch stream complete');
            return runBi(i + 1);
          });
        }
        return runBi(0);
      });
  }

  function runBatchedCustom(courseId, scope, lessonId, label, flags, useSkip) {
    var batch = Math.max(5, Math.min(100, parseInt(el('becBatchSize').value, 10) || 35));
    var q = 'course_id=' + encodeURIComponent(String(courseId))
      + '&scope=' + encodeURIComponent(scope)
      + '&lesson_id=' + encodeURIComponent(String(lessonId || 0));
    return fetch('/admin/api/bulk_enrich_slide_count.php?' + q, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) throw new Error(data.error || 'count failed');
        var n = parseInt(data.slide_count, 10) || 0;
        if (n <= 0) {
          appendLog(label + ' — no slides.');
          return;
        }
        var batches = Math.ceil(n / batch);
        appendLog(label + ' — ' + n + ' slide(s), ' + batches + ' batch(es).');
        var fObj = Object.assign({ skip_existing: useSkip }, flags);
        function runBi(i) {
          if (state.abortRun) {
            appendLog('Aborted.');
            return Promise.resolve();
          }
          if (i >= batches) return Promise.resolve();
          appendLog(label + ' batch ' + (i + 1) + '/' + batches + '…');
          var fd = new FormData();
          fd.append('scope', scope);
          fd.append('course_id', String(courseId));
          fd.append('lesson_id', String(lessonId || 0));
          fd.append('limit', '0');
          fd.append('batch_offset', String(i * batch));
          fd.append('batch_size', String(Math.min(batch, n - i * batch)));
          mirrorActions(fd, fObj);
          return runBatchSSE(fd).then(function () { return runBi(i + 1); });
        }
        return runBi(0);
      });
  }

  function runPhasedForCourse(courseId, scope, lessonId, prefix) {
    appendLog(prefix + ' PHASE 1: EN + narration + references (batched)');
    return runBatchedCustom(courseId, scope, lessonId, prefix + ' P1', {
      en: true, es: false, narr_en: true, narr_es: false, refs: true, hotspots: false
    }, el('becSkipExisting').checked).then(function () {
      appendLog(prefix + ' PHASE 2: Spanish slide text + Spanish narration');
      return runBatchedCustom(courseId, scope, lessonId, prefix + ' P2', {
        en: false, es: true, narr_en: false, narr_es: true, refs: false, hotspots: false
      }, false);
    }).then(function () {
      appendLog(prefix + ' PHASE 3: Video hotspots');
      return runBatchedCustom(courseId, scope, lessonId, prefix + ' P3', {
        en: false, es: false, narr_en: false, narr_es: false, refs: false, hotspots: true
      }, false);
    });
  }

  function courseQueue() {
    if (state.lessonId > 0) {
      var cid = state.courseId;
      if (cid <= 0) {
        var opt = el('becLesson').selectedOptions[0];
        cid = opt ? parseInt(opt.getAttribute('data-course-id') || '0', 10) : 0;
      }
      if (cid > 0) {
        return Promise.resolve([{ id: cid, scope: 'lesson', lesson: state.lessonId }]);
      }
    }
    if (state.courseId > 0) {
      return Promise.resolve([{ id: state.courseId, scope: 'course', lesson: 0 }]);
    }
    var list = DATA.coursesByProgram[String(state.programId)] || DATA.coursesByProgram[state.programId] || [];
    return Promise.resolve(list.map(function (c) { return { id: c.id, scope: 'course', lesson: 0 }; }));
  }

  function runEnrichmentPipeline(usePhased) {
    if (state.running) return;
    state.abortRun = false;
    setHidden('becRunPanel', false);
    el('becRunLog').textContent = '';
    setRun(true, 'Queued…');
    courseQueue().then(function (queue) {
      if (!queue.length) {
        setRun(false, 'Nothing to run.');
        return;
      }
      var i = 0;
      function next() {
        if (state.abortRun) { setRun(false, 'Aborted.'); return; }
        if (i >= queue.length) {
          setRun(false, 'All queued courses finished.');
          loadCoverage(false);
          return;
        }
        var job = queue[i];
        var label = 'Course ' + job.id + ' (' + (i + 1) + '/' + queue.length + ')';
        el('becRunStatus').textContent = label + '…';
        var p = usePhased
          ? runPhasedForCourse(job.id, job.scope, job.lesson, label)
          : runCourseBatches(job.id, job.scope, job.lesson, label);
        p.then(function () { i++; next(); }).catch(function (e) {
          appendLog('ERROR: ' + (e && e.message ? e.message : String(e)));
          setRun(false, 'Stopped on error.');
        });
      }
      next();
    });
  }

  el('becProgram').addEventListener('change', fillProgram);
  el('becCourse').addEventListener('change', function () {
    fillLessons();
    loadCoverage(false);
  });
  el('becLesson').addEventListener('change', function () {
    state.lessonId = parseInt(el('becLesson').value, 10) || 0;
    var opt = el('becLesson').selectedOptions[0];
    var dc = opt ? parseInt(opt.getAttribute('data-course-id') || '0', 10) : 0;
    if (state.lessonId > 0 && dc > 0) {
      state.courseId = dc;
    } else {
      state.courseId = parseInt(el('becCourse').value, 10) || 0;
    }
    loadCoverage(false);
  });
  el('becVideoManifest').addEventListener('change', function () {
    state.videoManifest = el('becVideoManifest').value;
    loadCoverage(false);
  });

  document.querySelectorAll('.bec-stat').forEach(function (btn) {
    btn.addEventListener('click', function () {
      state.filter = btn.getAttribute('data-filter') || 'all';
      state.offset = 0;
      updateStatActiveClass();
      loadCoverage(false);
    });
  });

  el('becBtnReload').onclick = function () { loadCoverage(false); };
  el('becLoadMore').onclick = function () {
    state.offset += state.pageSize;
    fetch(coverageUrl(''), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok) appendRows(data.slides || [], true);
      });
  };

  el('becToggleAll').onchange = function () {
    var on = el('becToggleAll').checked;
    document.querySelectorAll('#becTbody .bec-sl').forEach(function (c) { c.checked = on; });
  };
  el('becSelectVisible').onclick = function () {
    document.querySelectorAll('#becTbody .bec-sl').forEach(function (c) { c.checked = true; });
    el('becToggleAll').checked = true;
  };
  el('becClearSel').onclick = function () {
    document.querySelectorAll('#becTbody .bec-sl').forEach(function (c) { c.checked = false; });
    el('becToggleAll').checked = false;
  };

  function becChunk(arr, size) {
    var out = [];
    for (var i = 0; i < arr.length; i += size) out.push(arr.slice(i, i + size));
    return out;
  }

  el('becBtnSelected').onclick = function () {
    var byCourse = {};
    document.querySelectorAll('#becTbody .bec-sl:checked').forEach(function (c) {
      var sid = parseInt(c.getAttribute('data-id'), 10);
      var cid = parseInt(c.getAttribute('data-course'), 10);
      if (sid <= 0 || cid <= 0) return;
      if (!byCourse[cid]) byCourse[cid] = [];
      byCourse[cid].push(sid);
    });
    var keys = Object.keys(byCourse);
    if (!keys.length) {
      alert('Select at least one slide.');
      return;
    }
    var bs = Math.max(5, Math.min(100, parseInt(el('becBatchSize').value, 10) || 35));
    var jobs = [];
    keys.forEach(function (k) {
      var cid = parseInt(k, 10);
      becChunk(byCourse[k], bs).forEach(function (chunk) {
        jobs.push({ courseId: cid, slideIds: chunk });
      });
    });
    if (!confirm('Run enrichment for ' + jobs.reduce(function (n, j) { return n + j.slideIds.length; }, 0) + ' slide(s) in ' + jobs.length + ' batch(es)?')) return;
    setHidden('becRunPanel', false);
    el('becRunLog').textContent = '';
    var i = 0;
    function runJob() {
      if (i >= jobs.length) {
        el('becRunStatus').textContent = 'Selected slides: all batches done.';
        loadCoverage(false);
        return;
      }
      var j = jobs[i];
      el('becRunStatus').textContent = 'Selected batch ' + (i + 1) + '/' + jobs.length + ' (course ' + j.courseId + ', ' + j.slideIds.length + ' slides)…';
      var fd = new FormData();
      fd.append('scope', 'course');
      fd.append('course_id', String(j.courseId));
      fd.append('lesson_id', '0');
      fd.append('limit', '0');
      fd.append('batch_offset', '0');
      fd.append('batch_size', '0');
      j.slideIds.forEach(function (id) { fd.append('target_slide_ids[]', String(id)); });
      mirrorActions(fd, null);
      runBatchSSE(fd).then(function () {
        appendLog('Batch ' + (i + 1) + ' done.');
        i++;
        runJob();
      }).catch(function () {
        appendLog('Network error on batch ' + (i + 1));
      });
    }
    runJob();
  };

  el('becBtnSoftDeleteSelected').onclick = function () {
    var ids = [];
    document.querySelectorAll('#becTbody .bec-sl:checked').forEach(function (c) {
      var sid = parseInt(c.getAttribute('data-id'), 10);
      if (sid > 0) ids.push(sid);
    });
    if (!ids.length) {
      alert('Select at least one slide.');
      return;
    }
    if (!confirm('Soft-delete ' + ids.length + ' slide(s)? They will be hidden from coverage until restored in Slides.')) return;
    Promise.all(ids.map(function (sid) {
      var fd = new FormData();
      fd.append('slide_id', String(sid));
      fd.append('is_deleted', '1');
      return fetch('/admin/api/bulk_enrich_slide_soft_toggle.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.ok) throw new Error((j && j.error) || ('Slide ' + sid));
          return sid;
        });
    })).then(function () {
      loadCoverage(false);
      document.querySelectorAll('#becTbody .bec-sl').forEach(function (c) { c.checked = false; });
      el('becToggleAll').checked = false;
    }).catch(function (e) {
      alert(e && e.message ? e.message : 'Soft-delete failed');
      loadCoverage(false);
    });
  };

  el('becBtnRun').onclick = function () {
    if (state.programId <= 0 && state.courseId <= 0) {
      alert('Select a program (or course) first.');
      return;
    }
    runEnrichmentPipeline(false);
  };
  el('becBtnPipeline').onclick = function () {
    if (state.programId <= 0 && state.courseId <= 0) {
      alert('Select a program (or course) first.');
      return;
    }
    if (!confirm('Run 3-phase pipeline for all slides in scope? This uses many API calls.')) return;
    runEnrichmentPipeline(true);
  };

  document.getElementById('becTbody').addEventListener('click', function (ev) {
    var t = ev.target;
    if (t.classList.contains('bec-soft')) {
      var sid = parseInt(t.getAttribute('data-id'), 10);
      if (!sid || !confirm('Soft-delete slide ' + sid + '?')) return;
      var fd = new FormData();
      fd.append('slide_id', String(sid));
      fd.append('is_deleted', '1');
      fetch('/admin/api/bulk_enrich_slide_soft_toggle.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) loadCoverage(false);
          else alert((j && j.error) || 'Failed');
        });
    }
    if (t.classList.contains('bec-one')) {
      var id = parseInt(t.getAttribute('data-id'), 10);
      var cid = parseInt(t.getAttribute('data-course'), 10);
      if (!id || !cid) return;
      var fd = new FormData();
      fd.append('scope', 'course');
      fd.append('course_id', String(cid));
      fd.append('lesson_id', '0');
      fd.append('limit', '0');
      fd.append('batch_offset', '0');
      fd.append('batch_size', '0');
      fd.append('target_slide_ids[]', String(id));
      mirrorActions(fd, null);
      setHidden('becRunPanel', false);
      appendLog('Single slide ' + id + '…');
      runBatchSSE(fd).then(function () { appendLog('Done slide ' + id); loadCoverage(false); });
    }
  });

  (function initPageSize() {
    var saved = parseInt(localStorage.getItem(BEC_LIST_LIMIT_KEY) || '100', 10);
    if (BEC_PAGE_SIZES.indexOf(saved) < 0) saved = 100;
    state.pageSize = saved;
    el('becPageSize').value = String(saved);
  })();

  el('becPageSize').addEventListener('change', function () {
    var v = parseInt(el('becPageSize').value, 10) || 100;
    if (BEC_PAGE_SIZES.indexOf(v) < 0) v = 100;
    state.pageSize = v;
    localStorage.setItem(BEC_LIST_LIMIT_KEY, String(v));
    state.offset = 0;
    if (state.programId > 0) loadCoverage(false);
  });

  loadManifests().then(function () {
    hydrateBulkFromQuery();
  });
})();
</script>

<?php cw_footer(); ?>
