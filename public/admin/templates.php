<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$existingCount = (int)$pdo->query("SELECT COUNT(*) FROM templates")->fetchColumn();

if ($existingCount === 0) {
    $defaults = [
        [
            'TEXT_LEFT_MEDIA_RIGHT',
            'Text Left / Media Right',
            10,
            '<div class="ipca-canvas tpl-tlmr">
               <div class="ipca-content">
                 <div class="ipca-text-left">{{HTML_LEFT}}</div>
                 <div class="ipca-media-right">{{MEDIA_RIGHT}}</div>
               </div>
               <div class="ipca-mentor"></div>
             </div>'
        ],
        [
            'TEXT_SPLIT_TWO_COL',
            'Text Split Two Columns',
            20,
            '<div class="ipca-canvas tpl-2col">
               <div class="ipca-content">
                 <div class="ipca-text-left">{{HTML_LEFT}}</div>
                 <div class="ipca-text-right">{{HTML_RIGHT}}</div>
               </div>
               <div class="ipca-mentor"></div>
             </div>'
        ],
        [
            'MEDIA_LEFT_TEXT_RIGHT',
            'Media Left / Text Right',
            30,
            '<div class="ipca-canvas tpl-mltr">
               <div class="ipca-content">
                 <div class="ipca-media-left">{{MEDIA_LEFT}}</div>
                 <div class="ipca-text-right">{{HTML_RIGHT}}</div>
               </div>
               <div class="ipca-mentor"></div>
             </div>'
        ],
        [
            'DUAL_MEDIA_WITH_TOP_TEXT',
            'Dual Media + Top Text',
            40,
            '<div class="ipca-canvas tpl-dual">
               <div class="ipca-content">
                 <div class="ipca-text-left">{{HTML_LEFT}}</div>
                 <div class="ipca-text-right">{{HTML_RIGHT}}</div>
                 <div class="ipca-media-left">{{MEDIA_LEFT}}</div>
                 <div class="ipca-media-right">{{MEDIA_RIGHT}}</div>
               </div>
               <div class="ipca-mentor"></div>
             </div>'
        ],
        [
            'MEDIA_CENTER_ONLY',
            'Media Center Only',
            50,
            '<div class="ipca-canvas tpl-center">
               <div class="ipca-content">
                 <div class="ipca-media-center">{{MEDIA_CENTER}}</div>
               </div>
               <div class="ipca-mentor"></div>
             </div>'
        ],
    ];

    $stmt = $pdo->prepare("INSERT INTO templates (template_key, name, sort_order, html_skeleton, css, is_active) VALUES (?,?,?,?,?,1)");
    foreach ($defaults as $d) {
        $stmt->execute([$d[0], $d[1], $d[2], $d[3], ""]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_template') {
    $id = (int)$_POST['id'];
    $name = trim((string)$_POST['name']);
    $sort = (int)$_POST['sort_order'];
    $html = (string)($_POST['html_skeleton'] ?? '');
    $css  = (string)($_POST['css'] ?? '');

    $stmt = $pdo->prepare("UPDATE templates SET name=?, sort_order=?, html_skeleton=?, css=? WHERE id=?");
    $stmt->execute([$name, $sort, $html, $css, $id]);
    redirect('/admin/templates.php');
}

$templates = $pdo->query("SELECT * FROM templates ORDER BY sort_order, id")->fetchAll();

cw_header('Templates');
?>
<div class="card">
  <h2>Templates</h2>
  <p class="muted">
    These templates render inside the universal IPCA background.
    Placeholders: {{MEDIA_LEFT}} {{MEDIA_RIGHT}} {{MEDIA_CENTER}} {{HTML_LEFT}} {{HTML_RIGHT}}
  </p>
</div>

<?php foreach ($templates as $t): ?>
<div class="card">
  <form method="post">
    <input type="hidden" name="action" value="save_template">
    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">

    <div class="form-grid">
      <label>Key</label>
      <input value="<?= h($t['template_key']) ?>" disabled>

      <label>Name</label>
      <input name="name" value="<?= h($t['name']) ?>">

      <label>Order</label>
      <input name="sort_order" type="number" value="<?= (int)$t['sort_order'] ?>">

      <label>HTML skeleton</label>
      <textarea name="html_skeleton" rows="6" style="width:100%;"><?= h($t['html_skeleton']) ?></textarea>

      <label>CSS (optional)</label>
      <textarea name="css" rows="5" style="width:100%;"><?= h($t['css'] ?? '') ?></textarea>

      <div></div>
      <button class="btn" type="submit">Save</button>
    </div>
  </form>
</div>
<?php endforeach; ?>

<?php cw_footer(); ?>