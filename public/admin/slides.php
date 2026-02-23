<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$lessonId = (int)($_GET['lesson_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if ($_POST['action']==='delete') {
        $pdo->prepare("UPDATE slides SET is_deleted=1 WHERE id=?")
            ->execute([(int)$_POST['slide_id']]);
    }
    if ($_POST['action']==='restore') {
        $pdo->prepare("UPDATE slides SET is_deleted=0 WHERE id=?")
            ->execute([(int)$_POST['slide_id']]);
    }
    redirect('/admin/slides.php?lesson_id='.$lessonId);
}

$slides = [];
if ($lessonId>0) {
    $stmt = $pdo->prepare("SELECT * FROM slides WHERE lesson_id=? ORDER BY page_number");
    $stmt->execute([$lessonId]);
    $slides = $stmt->fetchAll();
}

cw_header('Slides');
?>

<div class="card">
<form method="get">
<input type="number" name="lesson_id" value="<?= $lessonId ?>">
<button class="btn">Load</button>
</form>
</div>

<div id="slides">
<?php foreach($slides as $s): ?>
<div class="slide-item <?= $s['is_deleted']?'deleted':'' ?>" draggable="true"
     data-id="<?= $s['id'] ?>">
  <div>
    Page <?= $s['page_number'] ?>
    <a href="/admin/slide_edit.php?slide_id=<?= $s['id'] ?>">Edit</a>
    <form method="post" style="display:inline">
      <input type="hidden" name="slide_id" value="<?= $s['id'] ?>">
      <input type="hidden" name="lesson_id" value="<?= $lessonId ?>">
      <input type="hidden" name="action" value="<?= $s['is_deleted']?'restore':'delete' ?>">
      <button><?= $s['is_deleted']?'Restore':'Delete' ?></button>
    </form>
  </div>
  <img src="<?= cdn_url($CDN_BASE,$s['image_path']) ?>" width="250">
</div>
<?php endforeach; ?>
</div>

<script>
const container=document.getElementById('slides');
let drag;
container.addEventListener('dragstart',e=>{
 drag=e.target.closest('.slide-item');
});
container.addEventListener('dragover',e=>{
 e.preventDefault();
 const after=[...container.children]
 .find(c=>c!==drag && e.clientY<=c.getBoundingClientRect().top+c.offsetHeight/2);
 container.insertBefore(drag,after||null);
});
container.addEventListener('dragend',()=>{
 const ordered=[...container.children].map((c,i)=>({id:c.dataset.id,page:i+1}));
 fetch('/admin/api/reorder_slides.php',{
   method:'POST',
   headers:{'Content-Type':'application/json'},
   body:JSON.stringify({lesson_id:<?= $lessonId ?>,ordered})
 }).then(()=>location.reload());
});
</script>

<?php cw_footer(); ?>
