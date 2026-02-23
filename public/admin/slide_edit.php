<?php
require_once __DIR__.'/../../src/bootstrap.php';
require_once __DIR__.'/../../src/layout.php';
cw_require_admin();

$id=(int)$_GET['slide_id'];
$stmt=$pdo->prepare("SELECT * FROM slides WHERE id=?");
$stmt->execute([$id]);
$s=$stmt->fetch();

if($_SERVER['REQUEST_METHOD']==='POST'){
 $pdo->prepare("UPDATE slides SET template_key=?,html_left=?,html_right=? WHERE id=?")
     ->execute([$_POST['template_key'],$_POST['html_left'],$_POST['html_right'],$id]);
 redirect('/admin/slide_edit.php?slide_id='.$id);
}

$templates=$pdo->query("SELECT template_key FROM templates")->fetchAll();

cw_header('Slide Edit');
?>

<img src="<?= cdn_url($CDN_BASE,$s['image_path']) ?>" width="500">

<form method="post">
<select name="template_key">
<?php foreach($templates as $t): ?>
<option value="<?= $t['template_key'] ?>" <?= $s['template_key']==$t['template_key']?'selected':'' ?>>
<?= $t['template_key'] ?>
</option>
<?php endforeach; ?>
</select>

<h3>Left HTML</h3>
<textarea name="html_left" rows="8"><?= h($s['html_left']??'') ?></textarea>

<h3>Right HTML</h3>
<textarea name="html_right" rows="8"><?= h($s['html_right']??'') ?></textarea>

<button class="btn">Save</button>
</form>

<?php cw_footer(); ?>