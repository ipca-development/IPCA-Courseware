<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$slideId = (int)($_GET['slide_id'] ?? 0);
if ($slideId <= 0) exit('Missing slide_id');

$stmt = $pdo->prepare("SELECT s.*, l.external_lesson_id FROM slides s JOIN lessons l ON l.id=s.lesson_id WHERE s.id=? LIMIT 1");
$stmt->execute([$slideId]);
$slide = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$slide) exit('Slide not found');

$html = (string)($slide['html_rendered'] ?? '');
if ($html === '') {
    // If not rendered yet, show message
    echo "<p style='font-family:Arial,sans-serif;padding:20px;'>No rendered HTML yet. Use “Save + Render HTML” first.</p>";
    exit;
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Slide Preview</title>
  <link rel="stylesheet" href="/assets/app.css">
  <style>
    body{ margin:0; padding:0; background:#0e1520; }
    .stage{
      display:flex;
      align-items:center;
      justify-content:center;
      min-height:100vh;
      padding:20px;
    }
    .viewport{
      width:1600px;
      height:900px;
      transform-origin: top left;
      background:#fff;
      border-radius:16px;
      overflow:hidden;
      box-shadow: 0 16px 50px rgba(0,0,0,0.55);
    }
    @media (max-width: 1680px){
      .viewport{
        transform: scale(calc((100vw - 40px)/1600));
      }
    }
    @media (max-width: 980px){
      .viewport{
        transform: scale(calc((100vw - 40px)/1600));
      }
    }
  </style>
</head>
<body>
  <div class="stage">
    <div class="viewport">
      <?php echo $html; ?>
    </div>
  </div>
</body>
</html>