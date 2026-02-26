<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

// If you want student auth, enable this:
// cw_require_login();

$slideId = (int)($_GET['slide_id'] ?? 0);
if ($slideId <= 0) exit('Missing slide_id');

$stmt = $pdo->prepare("
  SELECT s.*, l.external_lesson_id, c.title AS course_title, p.program_key
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

$HEADER = "/assets/overlay/header.png";
$FOOTER = "/assets/overlay/footer.png";

$hs = $pdo->prepare("SELECT id, label, src, x,y,w,h FROM slide_hotspots WHERE slide_id=? AND is_deleted=0 ORDER BY id ASC");
$hs->execute([$slideId]);
$hotspots = $hs->fetchAll(PDO::FETCH_ASSOC);

$en = $pdo->prepare("SELECT plain_text FROM slide_content WHERE slide_id=? AND lang='en' LIMIT 1");
$en->execute([$slideId]);
$enText = (string)($en->fetchColumn() ?: '');

$es = $pdo->prepare("SELECT plain_text FROM slide_content WHERE slide_id=? AND lang='es' LIMIT 1");
$es->execute([$slideId]);
$esText = (string)($es->fetchColumn() ?: '');

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Slide</title>
  <link rel="stylesheet" href="/assets/app.css">
  <style>
    body{
      margin:0;
      background:#ffffff;              /* ✅ no black */
      color:#111;
      font-family: Manrope, Arial, sans-serif;
    }

    .shell{
      display:grid;
      grid-template-columns: 1fr 420px;
      gap:14px;
      padding:14px;
      background:#ffffff;              /* ✅ no black */
    }

    .viewport{
      width:100%;
      aspect-ratio: 16/9;
      overflow:hidden;
      border-radius: 14px;
      border:1px solid rgba(0,0,0,0.10);
      background:#ffffff;              /* ✅ no black */
      position:relative;
    }

    .stage{
      width:1600px;
      height:900px;
      transform-origin: top left;
      position:absolute;
      left:0;
      top:0;
      background:#ffffff;              /* ✅ no black */
    }

    /* Screenshot exact sizing: 1315x900 centered */
    .content-img{
      position:absolute;
      width:1315px;
      height:900px;
      left: calc((1600px - 1315px)/2);
      top: 0;
      object-fit: contain;
      background:#ffffff;              /* ✅ no black behind transparent pixels */
    }

    /* Header/Footer fixed dims */
    .header-img{
      position:absolute; left:0; top:0;
      width:1600px; height:125px;
      object-fit:cover;
      pointer-events:none;
    }
    .footer-img{
      position:absolute; left:0; bottom:0;
      width:1600px; height:90px;
      object-fit:cover;
      pointer-events:none;
    }

    .hotspot{
      position:absolute;
      border:2px solid rgba(0,120,255,0.85);
      border-radius:10px;
      background: rgba(0,120,255,0.08);
      cursor:pointer;
    }
    .hotspot .tag{
      position:absolute; left:8px; top:8px;
      font-size:14px; padding:4px 8px;
      border-radius:10px;
      background: rgba(0,0,0,0.6);
      color:#fff;
    }

    .panel{
      background:#f6f8fc;              /* ✅ light panel */
      border:1px solid rgba(0,0,0,0.10);
      border-radius: 14px;
      padding:12px;
    }

    pre{
      white-space: pre-wrap;
      word-break: break-word;
      font-family: Manrope, Arial, sans-serif;
      font-size: 14px;
      line-height: 1.35;
      margin:0;
      color:#111;
    }

    .row{ display:flex; gap:8px; align-items:center; margin-bottom:10px; }

    .btnx{
      background:#1e3c72;
      border:1px solid rgba(0,0,0,0.10);
      color:#fff;
      padding:8px 10px;
      border-radius:10px;
      cursor:pointer;
    }
    .btnx:hover{ background:#16305a; }

    .modal{
      position:fixed;
      inset:0;
      display:none;
      align-items:center;
      justify-content:center;
      background: rgba(0,0,0,0.7);     /* ok to keep dark */
    }
    .modal .box{
      width:min(960px, 92vw);
      background:#ffffff;              /* ✅ white player box */
      border:1px solid rgba(0,0,0,0.10);
      border-radius:16px;
      overflow:hidden;
    }
    .modal video{ width:100%; height:auto; display:block; background:#000; }
  </style>
</head>
<body>
  <div class="shell">
    <div>
      <div class="viewport" id="viewport">
        <div class="stage" id="stage">
          <img class="content-img" src="<?= htmlspecialchars($imgUrl) ?>" alt="">
          <img class="header-img" src="<?= htmlspecialchars($HEADER) ?>" alt="">
          <img class="footer-img" src="<?= htmlspecialchars($FOOTER) ?>" alt="">

          <?php foreach ($hotspots as $h): ?>
            <div class="hotspot"
                 data-src="<?= htmlspecialchars($h['src']) ?>"
                 data-label="<?= htmlspecialchars($h['label']) ?>"
                 style="left:<?= (int)$h['x'] ?>px; top:<?= (int)$h['y'] ?>px; width:<?= (int)$h['w'] ?>px; height:<?= (int)$h['h'] ?>px;">
              <div class="tag"><?= htmlspecialchars($h['label']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="row">
        <button class="btnx" id="btnEN">English</button>
        <button class="btnx" id="btnES">Español</button>
      </div>
      <pre id="txt"><?= htmlspecialchars($enText) ?></pre>
    </div>
  </div>

  <div class="modal" id="modal">
    <div class="box">
      <video id="vid" controls playsinline></video>
    </div>
  </div>

<script>
const viewport = document.getElementById('viewport');
const stage = document.getElementById('stage');
let scale = 1;

function fitStage(){
  const vw = viewport.clientWidth;
  const vh = viewport.clientHeight;
  scale = Math.min(vw/1600, vh/900);
  stage.style.transform = `scale(${scale})`;
}
window.addEventListener('resize', ()=>setTimeout(fitStage, 60));
setTimeout(fitStage, 30);

// Language toggle
const en = <?= json_encode($enText) ?>;
const es = <?= json_encode($esText) ?>;
const txt = document.getElementById('txt');
document.getElementById('btnEN').onclick = ()=> txt.textContent = en || '(No English content yet)';
document.getElementById('btnES').onclick = ()=> txt.textContent = es || '(No Spanish content yet)';

// Video modal
const CDN_BASE = <?= json_encode(rtrim($CDN_BASE,'/')) ?>;
const modal = document.getElementById('modal');
const vid = document.getElementById('vid');

document.querySelectorAll('.hotspot').forEach(h=>{
  h.addEventListener('click', ()=>{
    const src = h.dataset.src || '';
    if (!src) return alert('No video linked yet.');
    const url = src.startsWith('http') ? src : (CDN_BASE + '/' + src.replace(/^\/+/, ''));
console.log('[VIDEO]', url);

modal.style.display = 'flex';
vid.src = url;
vid.load();

// show video element errors
vid.onerror = () => {
  console.error('VIDEO ERROR:', vid.error);
  alert('Video failed to load. Check console for details.\nURL:\n' + url);
};

vid.onloadedmetadata = () => console.log('loadedmetadata', vid.videoWidth, vid.videoHeight);
vid.oncanplay = () => console.log('canplay');
vid.onplay = () => console.log('play');
vid.onstalled = () => console.log('stalled');
vid.onwaiting = () => console.log('waiting');

vid.play().catch((e)=>{
  console.error('play() failed', e);
  alert('play() failed: ' + e);
});
  });
});

function closeModal(){
  try { vid.pause(); } catch(e){}
  vid.removeAttribute('src');
  vid.load(); // ensures it fully stops
  modal.style.display = 'none';
}

// Close when clicking backdrop
modal.addEventListener('click', (e)=>{
  // If you clicked OUTSIDE the video box, close
  if (e.target.id === 'modal') closeModal();
});

// Close on ESC
document.addEventListener('keydown', (e)=>{
  if (e.key === 'Escape' && modal.style.display === 'flex') closeModal();
});	
	
	
</script>
</body>
</html>