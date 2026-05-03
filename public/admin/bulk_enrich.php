<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$courses = $pdo->query("
  SELECT c.id, c.title, c.program_id, p.program_key
  FROM courses c JOIN programs p ON p.id=c.program_id
  ORDER BY p.sort_order, c.sort_order, c.id
")->fetchAll(PDO::FETCH_ASSOC);

$programs = $pdo->query('SELECT id, program_key FROM programs ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
$programCourses = [];
foreach ($courses as $cRow) {
    $pid = (int)$cRow['program_id'];
    if (!isset($programCourses[$pid])) {
        $programCourses[$pid] = [];
    }
    $programCourses[$pid][] = $cRow;
}

$pick = trim((string)($_GET['pick'] ?? ''));
$programId = (int)($_GET['program_id'] ?? 0);
$courseId = (int)($_GET['course_id'] ?? 0);
if ($pick !== '') {
    if (preg_match('/^program:(\d+)$/', $pick, $m)) {
        $programId = (int)$m[1];
        $courseId = 0;
    } elseif (preg_match('/^course:(\d+)$/', $pick, $m)) {
        $courseId = (int)$m[1];
        $programId = 0;
    }
}

$lessons = [];
if ($programId > 0) {
    $stmt = $pdo->prepare("
        SELECT l.id, l.external_lesson_id, l.title, l.course_id, c.title AS course_title
        FROM lessons l
        INNER JOIN courses c ON c.id = l.course_id
        WHERE c.program_id = ?
        ORDER BY c.sort_order, c.id, l.sort_order, l.external_lesson_id
    ");
    $stmt->execute([$programId]);
    $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($courseId > 0) {
    $stmt = $pdo->prepare('SELECT id, external_lesson_id, title, course_id FROM lessons WHERE course_id=? ORDER BY sort_order, external_lesson_id');
    $stmt->execute([$courseId]);
    $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$lessonsAllCourses = [];
if ($programId > 0) {
    $stmt = $pdo->prepare("
        SELECT l.id, l.external_lesson_id, l.title, l.course_id,
               c.title AS course_title, p.program_key
        FROM lessons l
        INNER JOIN courses c ON c.id = l.course_id
        INNER JOIN programs p ON p.id = c.program_id
        WHERE c.program_id = ?
        ORDER BY c.sort_order, c.id, l.sort_order, l.external_lesson_id
    ");
    $stmt->execute([$programId]);
    $lessonsAllCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($courseId > 0) {
    $lessonsAllCourses = $pdo->query("
      SELECT l.id, l.external_lesson_id, l.title, l.course_id,
             c.title AS course_title, p.program_key
      FROM lessons l
      INNER JOIN courses c ON c.id = l.course_id
      INNER JOIN programs p ON p.id = c.program_id
      ORDER BY p.sort_order, c.sort_order, c.id, l.sort_order, l.external_lesson_id
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$selectedCourseMeta = ['program_key' => '', 'title' => ''];
foreach ($courses as $c) {
    if ((int)$c['id'] === $courseId) {
        $selectedCourseMeta = $c;
        break;
    }
}
$fullCourseAuditLabel = '';
if ($programId > 0) {
    foreach ($programs as $p) {
        if ((int)$p['id'] === $programId) {
            $fullCourseAuditLabel = 'Entire program — ' . trim((string)($p['program_key'] ?? ''));
            break;
        }
    }
    if ($fullCourseAuditLabel === '') {
        $fullCourseAuditLabel = 'program #' . $programId;
    }
} else {
    $fullCourseAuditLabel = trim(trim((string)($selectedCourseMeta['program_key'] ?? '')) . ' — ' . trim((string)($selectedCourseMeta['title'] ?? '')));
    if ($fullCourseAuditLabel === '' || $fullCourseAuditLabel === '—') {
        $fullCourseAuditLabel = 'course #' . $courseId;
    }
}

cw_header('Bulk Canonical Builder');
?>
<div class="card">
  <h2>Bulk Canonical Builder</h2>
  <p class="muted">
    Builds canonical data for slides in bulk:
    EN extract + ES translation + narration + PHAK refs + ACS refs + auto video hotspot.
  </p>

  <form method="get" class="form-grid" style="margin-bottom:16px;">
    <label>Pick course <em>or</em> entire program (loads lessons / coverage scope)</label>
    <select name="pick" onchange="this.form.submit()">
      <option value="">— Select —</option>
      <?php foreach ($programs as $p): ?>
        <?php
          $pid = (int)$p['id'];
          $progSelected = ($programId === $pid && $courseId === 0);
        ?>
        <option value="program:<?= $pid ?>" <?= $progSelected ? 'selected' : '' ?>>
          <?= h('Entire program — ' . (string)($p['program_key'] ?? '')) ?>
        </option>
        <?php foreach ($programCourses[$pid] ?? [] as $c): ?>
          <option value="course:<?= (int)$c['id'] ?>" <?= ($courseId === (int)$c['id'] && $programId === 0) ? 'selected' : '' ?>>
            <?= h((string)($c['program_key'] ?? '')) ?> — <?= h((string)($c['title'] ?? '')) ?>
          </option>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </select>
    <div></div><div></div>
  </form>

  <form id="bulkMainForm" method="post" action="/admin/api/bulk_enrich_run.php" class="form-grid" target="_blank">
    <label>Scope</label>
    <select name="scope" required>
      <option value="course">Whole course</option>
      <option value="lesson">Single lesson</option>
    </select>

    <label>Course</label>
    <select name="course_id" required>
      <option value="0">— Select —</option>
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= ($courseId === (int)$c['id']) ? 'selected' : '' ?>>
          <?= h($c['program_key']) ?> — <?= h($c['title']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label>Lesson (only if scope=lesson)</label>
    <select name="lesson_id">
      <option value="0">— Select lesson —</option>
      <?php foreach ($lessons as $l): ?>
        <option value="<?= (int)$l['id'] ?>">
          <?php if ($programId > 0 && isset($l['course_title'])): ?>
            <?= h((string)$l['course_title']) ?> · <?= (int)$l['external_lesson_id'] ?> — <?= h((string)$l['title']) ?>
          <?php else: ?>
            <?= (int)$l['external_lesson_id'] ?> — <?= h((string)$l['title']) ?>
          <?php endif; ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label>Program key (for video base)</label>
    <select name="program_key">
      <option value="private">private</option>
      <option value="instrument">instrument</option>
      <option value="commercial">commercial</option>
    </select>

    <label>Actions</label>
    <div style="display:flex; flex-direction:column; gap:6px;">
      <label><input type="checkbox" name="do_en" value="1" checked> Extract English</label>
      <label><input type="checkbox" name="do_es" value="1" checked> Translate Spanish</label>
      <label><input type="checkbox" name="do_narration" value="1" checked> Narration script (EN)</label>
      <label><input type="checkbox" name="do_refs" value="1" checked> PHAK + ACS references</label>
      <label><input type="checkbox" name="do_hotspots" value="1" checked> Auto video hotspots</label>
    </div>

    <label>Skip already processed</label>
    <label><input type="checkbox" name="skip_existing" value="1" checked> Skip slides that already have EN content</label>

    <label>Limit (0 = no limit)</label>
    <input type="number" name="limit" value="0" min="0">

    <label>Long runs</label>
    <label><input type="checkbox" id="becMainAutoBatch" checked> Auto-split into batches of <strong>15</strong> slides per tab when Limit is <strong>0</strong> (recommended; avoids blocked pop-ups and proxy timeouts)</label>

    <div></div>
    <button class="btn" type="submit">Run Bulk Build (opens new tab)</button>
  </form>

  <p class="muted" style="margin-top:14px;">
    Make sure <code>public/assets/kings_videos_manifest.json</code> exists for auto hotspots.
    With auto-split off, use <strong>Limit</strong> (e.g. 10–20) and run again until done if a single tab times out.
  </p>
  <script>
  (function () {
    var BEC_MAIN_BATCH = 15;
    var main = document.getElementById('bulkMainForm');
    if (!main) return;
    main.addEventListener('submit', function (ev) {
      var auto = document.getElementById('becMainAutoBatch');
      if (!auto || !auto.checked) return;
      var limEl = main.elements.limit;
      var lim = limEl ? (parseInt(limEl.value, 10) || 0) : 0;
      if (lim > 0) return;
      var cid = parseInt(main.elements.course_id.value, 10) || 0;
      if (cid <= 0) return;
      var scope = main.elements.scope ? main.elements.scope.value : 'course';
      var lid = main.elements.lesson_id ? (parseInt(main.elements.lesson_id.value, 10) || 0) : 0;
      if (scope === 'lesson' && lid <= 0) return;

      ev.preventDefault();
      var q = 'course_id=' + encodeURIComponent(String(cid))
        + '&scope=' + encodeURIComponent(scope)
        + '&lesson_id=' + encodeURIComponent(String(lid));
      fetch('/admin/api/bulk_enrich_slide_count.php?' + q, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.ok) {
            window.alert(data && data.error ? data.error : 'Could not count slides.');
            return;
          }
          var n = parseInt(data.slide_count, 10) || 0;
          if (n <= 0) {
            window.alert('No slides in scope.');
            return;
          }
          if (n <= BEC_MAIN_BATCH) {
            main.target = '_blank';
            main.submit();
            return;
          }
          var batches = Math.ceil(n / BEC_MAIN_BATCH);
          if (!window.confirm('Run ' + n + ' slides in ' + batches + ' separate tabs (~' + BEC_MAIN_BATCH + ' slides each)? Allow pop-ups if the browser asks.')) {
            return;
          }
          var key = Date.now();
          var names = [];
          for (var w = 0; w < batches; w++) {
            names[w] = 'becMainBatch_' + key + '_' + w;
            window.open('about:blank', names[w]);
          }
          function addH(form, name, value) {
            var x = document.createElement('input');
            x.type = 'hidden';
            x.name = name;
            x.value = String(value);
            form.appendChild(x);
          }
          function mirrorCheckboxes(form) {
            main.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
              if (!cb.name || !cb.checked) return;
              addH(form, cb.name, cb.value || '1');
            });
          }
          function runBatch(idx) {
            if (idx >= batches) return;
            var f = document.createElement('form');
            f.method = 'post';
            f.action = '/admin/api/bulk_enrich_run.php';
            f.target = names[idx];
            f.acceptCharset = 'UTF-8';
            addH(f, 'scope', scope);
            addH(f, 'course_id', String(cid));
            addH(f, 'lesson_id', String(lid));
            var pk = main.elements.program_key;
            addH(f, 'program_key', pk ? pk.value : 'private');
            addH(f, 'limit', '0');
            addH(f, 'batch_offset', String(idx * BEC_MAIN_BATCH));
            addH(f, 'batch_size', String(Math.min(BEC_MAIN_BATCH, n - idx * BEC_MAIN_BATCH)));
            mirrorCheckboxes(f);
            document.body.appendChild(f);
            f.submit();
            document.body.removeChild(f);
            if (idx + 1 < batches) {
              window.setTimeout(function () { runBatch(idx + 1); }, 600);
            }
          }
          runBatch(0);
        })
        .catch(function () { window.alert('Could not count slides (network).'); });
    });
  })();
  </script>
</div>

<?php if ($courseId > 0 || $programId > 0): ?>
<div class="card" style="margin-top:18px;">
  <h2>Coverage audit</h2>
  <p class="muted">
    Per-slide checks across <strong>every active slide</strong> in the chosen scope (<strong>whole program</strong> or <strong>single course</strong>), using
    <code>slide_content</code>, <code>slide_enrichment</code>, <code>slide_references</code>, and <code>slide_hotspots</code> vs the Kings manifest when applicable.
    The lesson filter lists lessons from the <strong>program scope only</strong> when a program is selected; with a single course it lists all lessons system-wide for cross-course drill-down.
    Lessons with <strong>no active slides</strong> show one placeholder row. Re-enrich opens one browser tab <strong>per course</strong> if your selection spans multiple courses.
  </p>
  <div class="form-grid" style="margin-bottom:12px;">
    <label>Focus audit</label>
    <select id="becLessonFilter">
      <option value="0">Full course (<?= h($fullCourseAuditLabel) ?>)</option>
      <?php foreach ($lessonsAllCourses as $l): ?>
        <option value="<?= (int)$l['id'] ?>" data-course-id="<?= (int)$l['course_id'] ?>">
          <?= h((string)$l['program_key']) ?> — <?= h((string)$l['course_title']) ?> — <?= (int)$l['external_lesson_id'] ?> — <?= h((string)$l['title']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div></div>
    <div>
      <button class="btn secondary" type="button" id="becLoadCoverage">Load coverage report</button>
    </div>
  </div>
  <div id="becSummary" class="muted" style="margin-bottom:10px;"></div>
  <div style="overflow:auto; max-height:70vh; border:1px solid #e5e7eb; border-radius:10px;">
    <table class="bec-cov-table" id="becTable" style="width:100%; border-collapse:collapse; font-size:13px;">
      <thead style="position:sticky; top:0; background:#f8fafc; z-index:1;">
        <tr>
          <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;"><input type="checkbox" id="becToggleAll" title="Toggle all visible"></th>
          <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;" title="Kings / external lesson id">Ext</th>
          <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Course</th>
          <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Lesson</th>
          <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Pg</th>
          <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Slide</th>
          <th style="text-align:center; padding:8px; border-bottom:1px solid #e5e7eb;" title="English extract">EN</th>
          <th style="text-align:center; padding:8px; border-bottom:1px solid #e5e7eb;" title="Spanish translation">ES</th>
          <th style="text-align:center; padding:8px; border-bottom:1px solid #e5e7eb;" title="Narration EN">N·EN</th>
          <th style="text-align:center; padding:8px; border-bottom:1px solid #e5e7eb;" title="Narration ES">N·ES</th>
          <th style="text-align:center; padding:8px; border-bottom:1px solid #e5e7eb;">PHAK</th>
          <th style="text-align:center; padding:8px; border-bottom:1px solid #e5e7eb;">ACS</th>
          <th style="text-align:center; padding:8px; border-bottom:1px solid #e5e7eb;" title="Video hotspot">Hot</th>
          <th style="text-align:center; padding:8px; border-bottom:1px solid #e5e7eb;" title="Manifest lists video">Vid?</th>
          <th style="text-align:center; padding:8px; border-bottom:1px solid #e5e7eb;">⚠</th>
          <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Notes</th>
          <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;"></th>
        </tr>
      </thead>
      <tbody id="becTbody"></tbody>
    </table>
  </div>
  <div style="margin-top:12px; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
    <button class="btn secondary" type="button" id="becSelectFlagged">Select flagged only</button>
    <button class="btn secondary" type="button" id="becClearSel">Clear selection</button>
    <button class="btn" type="button" id="becRunSelected">Re-enrich selected slides (new tab)</button>
  </div>
  <p class="muted" style="margin-top:10px;">
    Re-enrich runs the same pipeline as above for <strong>only</strong> the checked slide IDs. “Skip already processed” is ignored for that run.
    Uncheck any actions you do not want to overwrite (e.g. turn off Extract English if you only want hotspots).
    Large selections are split into batches of <strong id="becChunkHintN">15</strong> slides per tab so proxies are less likely to time out; the list page can stay open — only the progress tabs need to complete.
  </p>
</div>

<form id="bulkTargetForm" method="post" action="/admin/api/bulk_enrich_run.php" target="_blank" style="display:none;">
  <input type="hidden" name="scope" value="course">
  <input type="hidden" name="lesson_id" value="0">
  <input type="hidden" name="limit" value="0">
  <input type="hidden" name="course_id" value="<?= (int)($courseId > 0 ? $courseId : 0) ?>">
  <input type="hidden" name="program_key" id="becTfProgramKey" value="private">
  <div id="becTfSlideIds"></div>
</form>

<style>
  .bec-cov-table tbody tr.flagged { background: #fffbeb; }
  .bec-cov-table tbody tr.okish { background: #f8fafc; }
  .bec-cell-ok { color: #047857; font-weight: 600; }
  .bec-cell-bad { color: #b45309; font-weight: 600; }
  .bec-cell-warn { color: #b91c1c; font-weight: 700; }
  .bec-cell-na { color: #94a3b8; }
</style>

<script>
(function () {
  /** Max slides per POST to reduce nginx/Cloudflare idle timeouts on long AI runs. */
  var BEC_MAX_SLIDES_PER_RUN = 15;

  var pageCourseId = <?= (int)$courseId ?>;
  var programId = <?= (int)$programId ?>;
  /** Course id from the last successful coverage fetch (0 when scope was whole program). */
  var lastCoverageCourseId = pageCourseId;
  var main = document.getElementById('bulkMainForm');
  var tbody = document.getElementById('becTbody');
  var summaryEl = document.getElementById('becSummary');
  var lastRows = [];
  var chunkHint = document.getElementById('becChunkHintN');
  if (chunkHint) chunkHint.textContent = String(BEC_MAX_SLIDES_PER_RUN);

  function becChunkArray(arr, size) {
    var out = [];
    for (var i = 0; i < arr.length; i += size) {
      out.push(arr.slice(i, i + size));
    }
    return out;
  }

  function cellOk(ok) {
    return ok ? '<span class="bec-cell-ok">✓</span>' : '<span class="bec-cell-bad">✗</span>';
  }
  function cellWarn(w) {
    return w ? '<span class="bec-cell-warn">⚠</span>' : '<span class="bec-cell-na">—</span>';
  }
  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  document.getElementById('becLoadCoverage').onclick = function () {
    var sel = document.getElementById('becLessonFilter');
    var lid = parseInt(sel.value, 10) || 0;
    var url;
    if (programId > 0) {
      url = '/admin/api/bulk_enrich_coverage.php?program_id=' + encodeURIComponent(String(programId));
    } else {
      var cid = pageCourseId;
      if (lid > 0) {
        var opt = sel.options[sel.selectedIndex];
        var dc = opt ? opt.getAttribute('data-course-id') : null;
        if (dc) {
          cid = parseInt(dc, 10) || cid;
        }
      }
      url = '/admin/api/bulk_enrich_coverage.php?course_id=' + encodeURIComponent(String(cid));
    }
    if (lid > 0) url += '&lesson_id=' + encodeURIComponent(String(lid));
    summaryEl.textContent = 'Loading…';
    tbody.innerHTML = '';
    fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (data) {
      if (!data || !data.ok) {
        summaryEl.textContent = 'Could not load coverage.';
        return;
      }
      lastCoverageCourseId = (data.scope === 'program') ? 0 : (parseInt(data.course_id, 10) || pageCourseId);
      lastRows = data.slides || [];
      var s = data.summary || {};
      var lessonLine = 'Lessons: <strong>' + (s.lessons_in_scope != null ? s.lessons_in_scope : '—') + '</strong>';
      if ((s.lessons_without_active_slides || 0) > 0) {
        lessonLine += ' · <strong>' + s.lessons_without_active_slides + '</strong> with no active slides';
      }
      summaryEl.innerHTML = lessonLine + ' · Slide rows (audit): <strong>' + s.total + '</strong>'
        + (s.slides_expected_db_count != null ? ' · DB active-slide count: <strong>' + s.slides_expected_db_count + '</strong>' : '')
        + ' · Flagged rows: <strong>' + s.flagged + '</strong> · '
        + 'EN ok: ' + s.en_ok + ' · ES ok: ' + s.es_ok + ' · Narr EN: ' + s.narr_en_ok + ' · Narr ES: ' + s.narr_es_ok + ' · '
        + 'PHAK: ' + s.phak_ok + ' · ACS: ' + s.acs_ok;
      if (s.coverage_warning) {
        summaryEl.innerHTML += '<br><span style="color:#b45309;font-weight:600;">' + esc(String(s.coverage_warning)) + '</span>';
      }
      if (s.hotspot_expected > 0) {
        summaryEl.innerHTML += ' · Manifest video pages with hotspot: ' + s.hotspot_expected_ok + ' / ' + s.hotspot_expected;
      }
      var th = data.thresholds || {};
      summaryEl.innerHTML += '<br><span style="font-size:12px;">Thresholds: EN≥' + th.min_en_len + ' chars, ES≥' + th.min_es_len
        + ', narr EN≥' + th.min_narration_en + ', narr ES≥' + th.min_narration_es
        + ', flag PHAK/ACS refs if confidence &lt; ' + th.low_confidence_below + '.</span>';

      tbody.innerHTML = '';
      lastRows.forEach(function (row) {
        var ch = row.checks || {};
        var tr = document.createElement('tr');
        tr.className = row.flagged ? 'flagged' : 'okish';
        var ph = !!row.placeholder;
        var reasons = (row.flag_reasons || []).join(', ');
        var lowConf = !!ch.refs_low_confidence;
        var otherRef = typeof ch.other_refs_count === 'number' && ch.other_refs_count > 0;
        var notes = reasons + (otherRef ? ' · other_refs:' + ch.other_refs_count : '');
        var extId = row.external_lesson_id != null ? String(row.external_lesson_id) : '—';
        var pg = row.page_number != null ? esc(String(row.page_number)) : '<span class="bec-cell-na">—</span>';
        var sid = ph ? '<span class="bec-cell-na">—</span>' : String(row.slide_id);
        var cidAttr = ph ? '0' : String(parseInt(row.course_id, 10) || 0);
        var cbCell = ph
          ? '<span class="bec-cell-na" title="No slides to enrich">—</span>'
          : '<input type="checkbox" class="bec-sl" data-id="' + row.slide_id + '" data-course-id="' + cidAttr + '"' + (row.flagged ? ' checked' : '') + '>';
        var naCols = ph ? '<td colspan="8" style="padding:6px 8px; border-bottom:1px solid #f1f5f9; text-align:center;" class="bec-cell-na">No active slides in this lesson</td>' : '';
        var courseCell = esc(String(row.course_title || '—'));
        var detailCols = ph ? '' : (
          '<td style="text-align:center; padding:6px; border-bottom:1px solid #f1f5f9;">' + cellOk(!!ch.extract_en) + '</td>' +
          '<td style="text-align:center; padding:6px; border-bottom:1px solid #f1f5f9;">' + cellOk(!!ch.translate_es) + '</td>' +
          '<td style="text-align:center; padding:6px; border-bottom:1px solid #f1f5f9;">' + cellOk(!!ch.narration_en) + '</td>' +
          '<td style="text-align:center; padding:6px; border-bottom:1px solid #f1f5f9;">' + cellOk(!!ch.narration_es) + '</td>' +
          '<td style="text-align:center; padding:6px; border-bottom:1px solid #f1f5f9;">' + cellOk(!!ch.phak_refs) + '</td>' +
          '<td style="text-align:center; padding:6px; border-bottom:1px solid #f1f5f9;">' + cellOk(!!ch.acs_refs) + '</td>' +
          '<td style="text-align:center; padding:6px; border-bottom:1px solid #f1f5f9;">' + cellOk(!!ch.video_hotspot) + '</td>' +
          '<td style="text-align:center; padding:6px; border-bottom:1px solid #f1f5f9;">' + (ch.manifest_lists_video ? '<span class="bec-cell-ok">yes</span>' : '<span class="bec-cell-na">no</span>') + '</td>'
        );
        var warnCell = ph ? '<td style="text-align:center; padding:6px; border-bottom:1px solid #f1f5f9;"><span class="bec-cell-na">—</span></td>' : '<td style="text-align:center; padding:6px; border-bottom:1px solid #f1f5f9;">' + cellWarn(lowConf) + '</td>';
        var linkLabel = ph ? 'Slides' : 'Edit';
        tr.innerHTML =
          '<td style="padding:6px 8px; border-bottom:1px solid #f1f5f9;">' + cbCell + '</td>' +
          '<td style="padding:6px 8px; border-bottom:1px solid #f1f5f9; white-space:nowrap;">' + esc(extId) + '</td>' +
          '<td style="padding:6px 8px; border-bottom:1px solid #f1f5f9; max-width:140px; font-size:12px;">' + courseCell + '</td>' +
          '<td style="padding:6px 8px; border-bottom:1px solid #f1f5f9; max-width:160px;">' + esc(row.lesson_title || '') + '</td>' +
          '<td style="padding:6px 8px; border-bottom:1px solid #f1f5f9;">' + pg + '</td>' +
          '<td style="padding:6px 8px; border-bottom:1px solid #f1f5f9;">' + sid + '</td>' +
          detailCols + naCols +
          warnCell +
          '<td style="padding:6px 8px; border-bottom:1px solid #f1f5f9; font-size:12px; max-width:240px;">' + esc(notes || '—') + '</td>' +
          '<td style="padding:6px 8px; border-bottom:1px solid #f1f5f9;"><a href="' + esc(row.overlay_editor_url) + '">' + esc(linkLabel) + '</a></td>';
        tbody.appendChild(tr);
      });
    }).catch(function () {
      summaryEl.textContent = 'Request failed.';
    });
  };

  document.getElementById('becToggleAll').onchange = function () {
    var on = this.checked;
    tbody.querySelectorAll('input.bec-sl').forEach(function (c) { c.checked = on; });
  };

  document.getElementById('becSelectFlagged').onclick = function () {
    tbody.querySelectorAll('tr').forEach(function (tr, i) {
      var row = lastRows[i];
      if (!row) return;
      var cb = tr.querySelector('input.bec-sl');
      if (cb) cb.checked = !!row.flagged;
    });
  };

  document.getElementById('becClearSel').onclick = function () {
    tbody.querySelectorAll('input.bec-sl').forEach(function (c) { c.checked = false; });
    var ta = document.getElementById('becToggleAll');
    if (ta) ta.checked = false;
  };

  document.getElementById('becRunSelected').onclick = function () {
    if (!main) return;
    var byCourse = {};
    tbody.querySelectorAll('input.bec-sl:checked').forEach(function (c) {
      var id = parseInt(c.getAttribute('data-id'), 10);
      var cid = parseInt(c.getAttribute('data-course-id'), 10) || 0;
      if (id <= 0 || cid <= 0) return;
      if (!byCourse[cid]) byCourse[cid] = [];
      byCourse[cid].push(id);
    });
    var courseIds = Object.keys(byCourse);
    if (!courseIds.length) {
      alert('Select at least one slide.');
      return;
    }
    var jobs = [];
    courseIds.forEach(function (k) {
      var cid = parseInt(k, 10);
      becChunkArray(byCourse[k], BEC_MAX_SLIDES_PER_RUN).forEach(function (chunk) {
        jobs.push({ courseId: cid, slideIds: chunk });
      });
    });
    var totalSlides = jobs.reduce(function (n, j) { return n + j.slideIds.length; }, 0);
    var msg = 'Re-run bulk enrich for ' + totalSlides + ' slide(s)';
    if (jobs.length > 1) {
      msg += ' in ' + jobs.length + ' batches (opens ' + jobs.length + ' tabs, ~' + BEC_MAX_SLIDES_PER_RUN + ' slides each) to avoid timeouts.';
    } else {
      msg += '?';
    }
    msg += ' Enabled actions will overwrite existing data for those slides.';
    if (!confirm(msg)) return;

    var batchWinNames = [];
    if (jobs.length > 1) {
      var wkey = String(Date.now());
      for (var wi = 0; wi < jobs.length; wi++) {
        batchWinNames[wi] = 'becReenrich_' + wkey + '_' + wi;
        window.open('about:blank', batchWinNames[wi]);
      }
    }

    function mirrorActions(tf) {
      ['do_en', 'do_es', 'do_narration', 'do_refs', 'do_hotspots'].forEach(function (name) {
        var el = main.querySelector('[name="' + name + '"]');
        if (el && el.checked) {
          var hi = document.createElement('input');
          hi.type = 'hidden';
          hi.name = name;
          hi.value = '1';
          hi.className = 'js-action-mirror';
          tf.appendChild(hi);
        }
      });
    }

    function submitJob(job, idx) {
      var tf = document.getElementById('bulkTargetForm');
      tf.target = jobs.length > 1 ? batchWinNames[idx] : '_blank';
      var box = document.getElementById('becTfSlideIds');
      box.innerHTML = '';
      tf.querySelectorAll('.js-action-mirror').forEach(function (n) { n.remove(); });
      job.slideIds.forEach(function (id) {
        var h = document.createElement('input');
        h.type = 'hidden';
        h.name = 'target_slide_ids[]';
        h.value = String(id);
        box.appendChild(h);
      });
      mirrorActions(tf);
      tf.elements.course_id.value = String(job.courseId);
      var pk = main.elements.program_key;
      document.getElementById('becTfProgramKey').value = pk ? pk.value : 'private';
      tf.submit();
      if (idx + 1 < jobs.length) {
        setTimeout(function () {
          submitJob(jobs[idx + 1], idx + 1);
        }, 600);
      }
    }
    submitJob(jobs[0], 0);
  };
})();
</script>
<?php endif; ?>

<?php cw_footer(); ?>