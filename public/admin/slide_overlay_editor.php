<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$slideId  = (int)($_GET['slide_id'] ?? 0);
$courseId = (int)($_GET['course_id'] ?? 0);
$lessonId = (int)($_GET['lesson_id'] ?? 0);

if ($slideId <= 0) exit('Missing slide_id');

$stmt = $pdo->prepare("
  SELECT s.*, l.external_lesson_id, l.course_id, c.title AS course_title, p.program_key
  FROM slides s
  JOIN lessons l ON l.id=s.lesson_id
  JOIN courses c ON c.id=l.course_id
  JOIN programs p ON p.id=c.program_id
  WHERE s.id=? LIMIT 1
");
$stmt->execute([$slideId]);
$slide = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$slide) exit('Slide not found');

$imgUrl = cdn_url($CDN_BASE, (string)$slide['image_path']);

// If not passed, derive from slide
if ($lessonId <= 0) $lessonId = (int)$slide['lesson_id'];
if ($courseId <= 0) $courseId = (int)$slide['course_id'];

$backUrl = '/admin/slides.php?course_id='.(int)$courseId.'&lesson_id='.(int)$lessonId;

// Fixed overlays
$HEADER = "/assets/overlay/header.png"; // 1600x125
$FOOTER = "/assets/overlay/footer.png"; // 1600x90

cw_header('Overlay Slide Editor');
?>
<style>
  .editor-wrap{ display:grid; grid-template-columns: 1fr 420px; gap:14px; }

  .viewport{
    width: 100%;
    aspect-ratio: 16 / 9;
    overflow: hidden;
    border:1px solid #e6e6e6;
    border-radius: 14px;
    background:#ffffff;
    position:relative;
  }
  .stage{
    width:1600px; height:900px;
    transform-origin: top left;
    position:absolute; left:0; top:0;
    background:#ffffff;
  }
  .layer{ position:absolute; inset:0; }

  .content-img{
    position:absolute;
    width:1315px;
    height:900px;
    left: calc((1600px - 1315px)/2);
    top: 0;
    object-fit: contain;
    background: #ffffff;
  }

  .header-img{
    position:absolute;
    left:0; top:0;
    width:1600px;
    height:125px;
    object-fit: cover;
    pointer-events:none;
  }
  .footer-img{
    position:absolute;
    left:0; bottom:0;
    width:1600px;
    height:90px;
    object-fit: cover;
    pointer-events:none;
  }

  .hotspot{
    position:absolute;
    border: 2px dashed rgba(255,255,255,0.85);
    border-radius: 10px;
    background: rgba(0,0,0,0.10);
    box-sizing:border-box;
    cursor: move;
  }
  .hotspot .tag{
    position:absolute;
    left:8px; top:8px;
    font-size: 14px;
    padding: 4px 8px;
    border-radius: 10px;
    background: rgba(0,0,0,0.65);
    color:#fff;
  }
  .hotspot .resize{
    position:absolute;
    right:-6px; bottom:-6px;
    width:14px; height:14px;
    background: rgba(0,255,255,0.9);
    border-radius: 4px;
    cursor: nwse-resize;
  }

  .draw-rect{
    position:absolute;
    border:2px solid rgba(0,255,255,0.9);
    border-radius:10px;
    background: rgba(0,255,255,0.08);
    pointer-events:none;
  }

  textarea{ width:100%; min-height:120px; }
  .small{ font-size: 12px; opacity: .75; }
  .row{ display:flex; gap:8px; align-items:center; }
  .row input[type="text"]{ width: 100%; }
  code{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
</style>

<div class="card">
  <div class="row" style="justify-content:space-between; margin-bottom:10px;">
    <div class="muted">
      <?= h($slide['program_key']) ?> • <?= h($slide['course_title']) ?> • Lesson <?= (int)$slide['external_lesson_id'] ?> • Page <?= (int)$slide['page_number'] ?> • Slide ID <?= (int)$slideId ?>
    </div>
    <div class="row">
      <a class="btn btn-sm" href="<?= h($backUrl) ?>">← Back to Slides</a>
      <a class="btn btn-sm" target="_blank" href="/player/slide.php?slide_id=<?= (int)$slideId ?>">Student View</a>
    </div>
  </div>

  <div class="editor-wrap">
    <div>
      <div class="viewport" id="viewport">
        <div class="stage" id="stage">
          <div class="layer">
            <img class="content-img" id="contentImg" src="<?= h($imgUrl) ?>" alt="">
            <img class="header-img" src="<?= h($HEADER) ?>" alt="">
            <img class="footer-img" src="<?= h($FOOTER) ?>" alt="">
          </div>
          <div class="layer" id="hotspotLayer"></div>
        </div>
      </div>

      <div class="muted small" style="margin-top:8px;">
        Draw hotspot: click+drag on the slide. Resize using the cyan corner.
        (All coordinates are saved in 1600×900 space.)
      </div>
    </div>

    <div>
      <div class="card" style="margin-bottom:12px;">
        <h2 style="margin:0 0 8px 0;">Hotspots</h2>
        <div class="small muted" id="suggestedBox"></div>
        <div id="hotspotList" class="small muted">Loading…</div>
        <div class="row" style="margin-top:10px;">
          <button class="btn" id="btnSaveHotspots" type="button">Save hotspots</button>
          <button class="btn btn-sm" id="btnReloadHotspots" type="button">Reload</button>
        </div>
      </div>

      <div class="card">
        <h2 style="margin:0 0 8px 0;">Canonical content</h2>
        <div class="row" style="margin-bottom:8px;">
          <button class="btn" id="btnExtractEN" type="button">AI Extract (EN)</button>
          <button class="btn btn-sm" id="btnExtractES" type="button">AI Translate (ES)</button>
        </div>

        <label class="small muted">English (editable)</label>
        <textarea id="taEN" placeholder="AI extracted content will appear here…"></textarea>

        <label class="small muted" style="margin-top:10px;">Spanish (editable)</label>
        <textarea id="taES" placeholder="AI translated content will appear here…"></textarea>

        <div class="row" style="margin-top:10px;">
          <button class="btn" id="btnSaveContent" type="button">Save content</button>
        </div>

        <div class="small muted" id="status" style="margin-top:10px;"></div>
      </div>
    </div>
  </div>
</div>

<script>
/* Your existing JS stays exactly the same below — no functional changes. */
const SLIDE_ID = <?= (int)$slideId ?>;
const hotspotLayer = document.getElementById('hotspotLayer');
const hotspotList = document.getElementById('hotspotList');
const suggestedBox = document.getElementById('suggestedBox');
const statusEl = document.getElementById('status');

const viewport = document.getElementById('viewport');
const stage = document.getElementById('stage');

let scale = 1;
function fitStage(){
  const vw = viewport.clientWidth;
  const vh = viewport.clientHeight;
  scale = Math.min(vw/1600, vh/900);
  stage.style.transform = `scale(${scale})`;
}
window.addEventListener('resize', () => setTimeout(fitStage, 60));
setTimeout(fitStage, 50);

function setStatus(msg){ statusEl.textContent = msg; }

/* The rest of your existing hotspot/content JS should remain unchanged.
   Keep whatever you currently have below this line. */
</script>

<?php cw_footer(); ?>