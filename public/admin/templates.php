<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$existingCount = (int)$pdo->query("SELECT COUNT(*) FROM templates")->fetchColumn();

if ($existingCount === 0) {
    $defaults = [
        ['TEXT_LEFT_MEDIA_RIGHT','Text Left / Media Right',10,
         '<div class="cw-grid"><div>{{HTML_LEFT}}</div><div>{{MEDIA_RIGHT}}</div></div>'],
        ['TEXT_SPLIT_TWO_COL','Text Split Two Columns',20,
         '<div class="cw-grid"><div>{{HTML_LEFT}}</div><div>{{HTML_RIGHT}}</div></div>'],
        ['MEDIA_LEFT_TEXT_RIGHT','Media Left / Text Right',30,
         '<div class="cw-grid"><div>{{MEDIA_LEFT}}</div><div>{{HTML_RIGHT}}</div></div>'],
        ['DUAL_MEDIA_WITH_TOP_TEXT','Dual Media + Top Text',40,
         '<div class="cw-grid"><div>{{HTML_LEFT}}{{MEDIA_LEFT}}</div><div>{{HTML_RIGHT}}{{MEDIA_RIGHT}}</div></div>'],
        ['MEDIA_CENTER_ONLY','Media Center Only',50,
         '<div class="cw-grid"><div>{{MEDIA_CENTER}}</div></div>']
    ];

    $stmt = $pdo->prepare("INSERT INTO templates (template_key,name,sort_order,html_skeleton,is_active) VALUES (?,?,?,?,1)");
    foreach ($defaults as $d) {
        $stmt->execute([$d[0],$d[1],$d[2],$d[3]]);
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $id = (int)$_POST['id'];
    $stmt = $pdo->prepare("UPDATE templates SET name=?, sort_order=?, html_skeleton=?, css=? WHERE id=?");
    $stmt->execute([$_POST['name'],(int)$_POST['sort_order'],$_POST['html_skeleton'],$_POST['css'],$id]);
    redirect('/admin/templates.php');
}

$templates = $pdo->query("SELECT * FROM templates ORDER BY sort_order")->fetchAll();

cw_header('Templates');
?>

<div class="card">
<h2>Templates</h2>
<?php foreach($templates as $t): ?>
<form method="post" class="card">
<input type="hidden" name="id" value="<?= $t['id'] ?>">
<label>Name</label>
<input name="name" value="<?= h($t['name']) ?>">
<label>Order</label>
<input type="number" name="sort_order" value="<?= $t['sort_order'] ?>">
<label>HTML Skeleton</label>
<textarea name="html_skeleton" rows="4"><?= h($t['html_skeleton']) ?></textarea>
<label>CSS</label>
<textarea name="css" rows="4"><?= h($t['css'] ?? '') ?></textarea>
<button class="btn">Save</button>
</form>
<?php endforeach; ?>
</div>

<?php cw_footer(); ?>
