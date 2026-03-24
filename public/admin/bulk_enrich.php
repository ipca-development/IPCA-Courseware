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

  <form method="post" action="/admin/api/bulk_enrich_run.php" class="form-grid" target="_blank">
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
<?php cw_footer(); ?>