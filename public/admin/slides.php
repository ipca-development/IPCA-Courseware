<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$lessonId = (int)($_GET['lesson_id'] ?? 0);
$courseId = (int)($_GET['course_id'] ?? 0);

$courses = $pdo->query("
  SELECT c.id, c.title, p.program_key
  FROM courses c
  JOIN programs p ON p.id=c.program_id
  ORDER BY p.sort_order, c.sort_order, c.id
")->fetchAll(PDO::FETCH_ASSOC);

if ($lessonId > 0 && $courseId === 0) {
    $stmt = $pdo->prepare("SELECT course_id FROM lessons WHERE id=? LIMIT 1");
    $stmt->execute([$lessonId]);
    $courseId = (int)$stmt->fetchColumn();
}

$lessons = [];
if ($courseId > 0) {
    $stmt = $pdo->prepare("
      SELECT id, external_lesson_id, title, sort_order
      FROM lessons
      WHERE course_id=?
      ORDER BY sort_order, external_lesson_id
    ");
    $stmt->execute([$courseId]);
    $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$lesson = null;
$slides = [];
if ($lessonId > 0) {
    $stmt = $pdo->prepare("
      SELECT l.*, c.title AS course_title, p.program_key
      FROM lessons l
      JOIN courses c ON c.id=l.course_id
      JOIN programs p ON p.id=c.program_id
      WHERE l.id=?
      LIMIT 1
    ");
    $stmt->execute([$lessonId]);
    $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM slides WHERE lesson_id=? ORDER BY page_number");
    $stmt->execute([$lessonId]);
    $slides = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

cw_header('Slides');
?>

<style>
/* Both thumbs use the same 16:9 viewport */
.thumb-viewport{
  width: 420px;
  height: 236px;
  overflow: hidden;
  border-radius: 12px;
  background: #fff;
  border: 1px solid #eee;
  position: relative;
}

/* Screenshot image fills viewport with contain */
.thumb-viewport img{
  width: 100%;
  height: 100%;
  object-fit: contain;
  display:block;
}

/* Overlay thumbnail stage: fixed 1600x900 internally, scaled down */
.thumb-stage{
  position:absolute;
  left:0;
  top:0;
  width:1600px;
  height:900px;
  transform: scale(0.2625); /* 420/1600 */
  transform-origin: top left;
  background:#ffffff;
}

/* Overlay geometry */
.thumb-stage .ov-content{
  position:absolute;
  width:1315px;
  height:900px;
  left: calc((1600px - 1315px)/2);
  top:0;
  object-fit: contain;
  background:#ffffff;
}
.thumb-stage .ov-header{
  position:absolute;
  left:0; top:0;
  width:1600px; height:125px;
  object-fit: cover;
  pointer-events:none;
}
.thumb-stage .ov-footer{
  position:absolute;
  left:0; bottom:0;
  width:1600px; height:90px;
  object-fit: cover;
  pointer-events:none;
}

.cw-slide-card{ scroll-margin-top: 88px; }

a.cw-designer-hit{
  display:block;
  text-decoration:none;
  color:inherit;
  border-radius:12px;
  cursor:pointer;
}
a.cw-designer-hit:focus{
  outline:2px solid #2563eb;
  outline-offset:3px;
}

#slidesToast{
  display:none;
  position:fixed;
  bottom:28px;
  left:50%;
  transform:translateX(-50%);
  z-index:2000;
  padding:12px 20px;
  border-radius:10px;
  background:#0f172a;
  color:#f8fafc;
  font-size:14px;
  box-shadow:0 8px 24px rgba(15,23,42,.35);
  max-width:90vw;
}
#slidesToast.slides-toast-on{ display:block; }
</style>

<div class="card">
  <h2>Slides</h2>

  <form method="get" class="form-grid">
    <label>Course</label>
    <select name="course_id" onchange="this.form.submit()">
      <option value="0">— Select course —</option>
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= ($courseId === (int)$c['id']) ? 'selected' : '' ?>>
          <?= h($c['program_key']) ?> — <?= h($c['title']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label>Lesson</label>
    <select name="lesson_id">
      <option value="0">— Select lesson —</option>
      <?php foreach ($lessons as $l): ?>
        <option value="<?= (int)$l['id'] ?>" <?= ($lessonId === (int)$l['id']) ? 'selected' : '' ?>>
          <?= (int)$l['external_lesson_id'] ?> — <?= h($l['title']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <div></div>
    <button class="btn" type="submit">Load Slides</button>
  </form>

  <?php if ($lesson): ?>
    <p class="muted" style="margin-top:10px;">
      <?= h($lesson['course_title']) ?> → <?= h($lesson['title']) ?> (<?= (int)$lesson['external_lesson_id'] ?>)
    </p>
  <?php endif; ?>
</div>

<?php if ($lesson): ?>
<div class="card">
  <h2>Slide overview</h2>
  <p class="muted">Click the slide preview (IPCA overlay) to open the Designer.</p>

  <div id="slidesToast" role="status" aria-live="polite"></div>

  <div class="cw-slides-grid">
    <?php foreach ($slides as $s): ?>
      <?php
        $isDeleted = ((int)$s['is_deleted'] === 1);
        $imgUrl = cdn_url($CDN_BASE, (string)$s['image_path']);
        $overlayEditorUrl = '/admin/slide_overlay_editor.php?slide_id=' . (int)$s['id']
          . '&course_id=' . (int)$courseId
          . '&lesson_id=' . (int)$lessonId
          . '&return_to=slides';

        // Overlay assets (same as editor)
        $headerUrl = '/assets/overlay/header.png';
        $footerUrl = '/assets/overlay/footer.png';
      ?>
      <div class="cw-slide-card <?= $isDeleted ? 'cw-deleted' : '' ?>" id="slide-card-<?= (int)$s['id'] ?>">
        <div class="cw-slide-top">
          <div>
            <strong>Page <?= (int)$s['page_number'] ?></strong>
            <span class="muted">• <?= h($s['template_key']) ?></span>
          </div>

          <div class="cw-actions">
            <a class="btn btn-sm" href="<?= h($overlayEditorUrl) ?>">Designer</a>

            <?php if (!$isDeleted): ?>
              <button class="btn btn-sm cw-soft-delete" type="button" data-slide-id="<?= (int)$s['id'] ?>">Soft-delete</button>
            <?php else: ?>
              <button class="btn btn-sm cw-restore-slide" type="button" data-slide-id="<?= (int)$s['id'] ?>">Restore</button>
            <?php endif; ?>
          </div>
        </div>

        <div class="cw-slide-body" style="grid-template-columns: 420px; gap:12px;">
          <a class="cw-designer-hit" href="<?= h($overlayEditorUrl) ?>" title="Open Designer">
            <div class="thumb-viewport">
              <div class="thumb-stage">
                <img class="ov-content" src="<?= h($imgUrl) ?>" alt="">
                <img class="ov-header" src="<?= h($headerUrl) ?>" alt="">
                <img class="ov-footer" src="<?= h($footerUrl) ?>" alt="">
              </div>
            </div>
          </a>
        </div>

      </div>
    <?php endforeach; ?>
  </div>
</div>
<script>
(function () {
  var toast = document.getElementById('slidesToast');
  var tHide = null;
  function showToast(msg) {
    if (!toast) return;
    toast.textContent = msg;
    toast.classList.add('slides-toast-on');
    if (tHide) clearTimeout(tHide);
    tHide = setTimeout(function () {
      toast.classList.remove('slides-toast-on');
    }, 2800);
  }

  var params = new URLSearchParams(window.location.search);
  var focusId = parseInt(params.get('focus_slide') || '0', 10);
  if (focusId > 0) {
    var el = document.getElementById('slide-card-' + focusId);
    if (el) {
      el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
    params.delete('focus_slide');
    var q = params.toString();
    var path = window.location.pathname + (q ? '?' + q : '') + window.location.hash;
    window.history.replaceState({}, '', path);
  }

  function postToggle(slideId, isDeleted) {
    var fd = new FormData();
    fd.append('slide_id', String(slideId));
    fd.append('is_deleted', String(isDeleted));
    return fetch('/admin/api/bulk_enrich_slide_soft_toggle.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  }

  var grid = document.querySelector('.cw-slides-grid');
  if (grid) {
    grid.addEventListener('click', function (ev) {
      var t = ev.target;
      if (!t.classList.contains('cw-soft-delete') && !t.classList.contains('cw-restore-slide')) {
        return;
      }
      var sid = parseInt(t.getAttribute('data-slide-id') || '0', 10);
      if (!sid) return;

      var card = document.getElementById('slide-card-' + sid);
      var actions = card ? card.querySelector('.cw-actions') : null;

      if (t.classList.contains('cw-soft-delete')) {
        if (!confirm('Soft-delete slide ' + sid + '?')) return;
        postToggle(sid, 1).then(function (j) {
          if (!j || !j.ok) {
            alert((j && j.error) || 'Request failed');
            return;
          }
          if (card) card.classList.add('cw-deleted');
          t.remove();
          if (actions) {
            var rb = document.createElement('button');
            rb.type = 'button';
            rb.className = 'btn btn-sm cw-restore-slide';
            rb.setAttribute('data-slide-id', String(sid));
            rb.textContent = 'Restore';
            actions.appendChild(rb);
          }
          showToast('Slide soft-deleted.');
        }).catch(function () { alert('Network error'); });
        return;
      }

      if (t.classList.contains('cw-restore-slide')) {
        postToggle(sid, 0).then(function (j) {
          if (!j || !j.ok) {
            alert((j && j.error) || 'Request failed');
            return;
          }
          if (card) card.classList.remove('cw-deleted');
          t.remove();
          if (actions) {
            var db = document.createElement('button');
            db.type = 'button';
            db.className = 'btn btn-sm cw-soft-delete';
            db.setAttribute('data-slide-id', String(sid));
            db.textContent = 'Soft-delete';
            actions.appendChild(db);
          }
          showToast('Slide restored.');
        }).catch(function () { alert('Network error'); });
      }
    });
  }
})();
</script>
<?php endif; ?>

<?php cw_footer(); ?>