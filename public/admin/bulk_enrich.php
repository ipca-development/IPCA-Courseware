<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$courses = $pdo->query("
  SELECT c.id, c.title, p.program_key
  FROM courses c JOIN programs p ON p.id=c.program_id
  ORDER BY p.sort_order, c.sort_order, c.id
")->fetchAll(PDO::FETCH_ASSOC);

$lessons = [];
$courseId = (int)($_GET['course_id'] ?? 0);
if ($courseId > 0) {
    $stmt = $pdo->prepare("SELECT id, external_lesson_id, title FROM lessons WHERE course_id=? ORDER BY sort_order, external_lesson_id");
    $stmt->execute([$courseId]);
    $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    <label>Pick course (loads lessons)</label>
    <select name="course_id" onchange="this.form.submit()">
      <option value="0">— Select course —</option>
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= ($courseId === (int)$c['id']) ? 'selected' : '' ?>>
          <?= h($c['program_key']) ?> — <?= h($c['title']) ?>
        </option>
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
          <?= (int)$l['external_lesson_id'] ?> — <?= h($l['title']) ?>
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

    <div></div>
    <button class="btn" type="submit">Run Bulk Build (opens new tab)</button>
  </form>

  <p class="muted" style="margin-top:14px;">
    Make sure <code>public/assets/kings_videos_manifest.json</code> exists for auto hotspots.
  </p>
</div>

<?php if ($courseId > 0): ?>
<div class="card" style="margin-top:18px;">
  <h2>Coverage audit</h2>
  <p class="muted">
    Per-slide checks against <code>slide_content</code> (EN/ES), <code>slide_enrichment</code> (narration),
    <code>slide_references</code> (PHAK/ACS + low confidence), and <code>slide_hotspots</code> when the Kings manifest lists a video for that page.
    Bulk enrich does not create eCFR rows — only PHAK/ACS. “Other refs” counts are informational.
    Every lesson in the course is listed in lesson order; lessons with <strong>no active slides</strong> appear as one placeholder row (upload slides first).
  </p>
  <div class="form-grid" style="margin-bottom:12px;">
    <label>Limit audit to lesson</label>
    <select id="becLessonFilter">
      <option value="0">All lessons in course</option>
      <?php foreach ($lessons as $l): ?>
        <option value="<?= (int)$l['id'] ?>"><?= (int)$l['external_lesson_id'] ?> — <?= h($l['title']) ?></option>
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
  </p>
</div>

<form id="bulkTargetForm" method="post" action="/admin/api/bulk_enrich_run.php" target="_blank" style="display:none;">
  <input type="hidden" name="scope" value="course">
  <input type="hidden" name="lesson_id" value="0">
  <input type="hidden" name="limit" value="0">
  <input type="hidden" name="course_id" value="<?= (int)$courseId ?>">
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
  var courseId = <?= (int)$courseId ?>;
  var main = document.getElementById('bulkMainForm');
  var tbody = document.getElementById('becTbody');
  var summaryEl = document.getElementById('becSummary');
  var lastRows = [];

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
    var lid = parseInt(document.getElementById('becLessonFilter').value, 10) || 0;
    var url = '/admin/api/bulk_enrich_coverage.php?course_id=' + encodeURIComponent(String(courseId));
    if (lid > 0) url += '&lesson_id=' + encodeURIComponent(String(lid));
    summaryEl.textContent = 'Loading…';
    tbody.innerHTML = '';
    fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (data) {
      if (!data || !data.ok) {
        summaryEl.textContent = 'Could not load coverage.';
        return;
      }
      lastRows = data.slides || [];
      var s = data.summary || {};
      var lessonLine = 'Lessons: <strong>' + (s.lessons_in_scope != null ? s.lessons_in_scope : '—') + '</strong>';
      if ((s.lessons_without_active_slides || 0) > 0) {
        lessonLine += ' · <strong>' + s.lessons_without_active_slides + '</strong> with no active slides';
      }
      summaryEl.innerHTML = lessonLine + ' · Slide rows: <strong>' + s.total + '</strong> · Flagged rows: <strong>' + s.flagged + '</strong> · '
        + 'EN ok: ' + s.en_ok + ' · ES ok: ' + s.es_ok + ' · Narr EN: ' + s.narr_en_ok + ' · Narr ES: ' + s.narr_es_ok + ' · '
        + 'PHAK: ' + s.phak_ok + ' · ACS: ' + s.acs_ok;
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
        var cbCell = ph
          ? '<span class="bec-cell-na" title="No slides to enrich">—</span>'
          : '<input type="checkbox" class="bec-sl" data-id="' + row.slide_id + '"' + (row.flagged ? ' checked' : '') + '>';
        var naCols = ph ? '<td colspan="8" style="padding:6px 8px; border-bottom:1px solid #f1f5f9; text-align:center;" class="bec-cell-na">No active slides in this lesson</td>' : '';
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
    var ids = [];
    tbody.querySelectorAll('input.bec-sl:checked').forEach(function (c) {
      var id = parseInt(c.getAttribute('data-id'), 10);
      if (id > 0) ids.push(id);
    });
    if (!ids.length) {
      alert('Select at least one slide.');
      return;
    }
    var tf = document.getElementById('bulkTargetForm');
    var box = document.getElementById('becTfSlideIds');
    box.innerHTML = '';
    tf.querySelectorAll('.js-action-mirror').forEach(function (n) { n.remove(); });
    ids.forEach(function (id) {
      var h = document.createElement('input');
      h.type = 'hidden';
      h.name = 'target_slide_ids[]';
      h.value = String(id);
      box.appendChild(h);
    });
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
    tf.elements.course_id.value = main.elements.course_id.value;
    var pk = main.elements.program_key;
    document.getElementById('becTfProgramKey').value = pk ? pk.value : 'private';
    if (!confirm('Re-run bulk enrich for ' + ids.length + ' slide(s)? Enabled actions will overwrite existing data for those slides.')) {
      return;
    }
    tf.submit();
  };
})();
</script>
<?php endif; ?>

<?php cw_footer(); ?>