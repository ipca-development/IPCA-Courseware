<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/spaces.php';
cw_require_admin();

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    if (!isset($_FILES['bg_file']) || $_FILES['bg_file']['error'] !== UPLOAD_ERR_OK) {
        $msg = "Upload failed. Try again.";
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') $name = 'Background ' . date('Y-m-d H:i');

        $tmp = $_FILES['bg_file']['tmp_name'];
        $orig = $_FILES['bg_file']['name'] ?? 'bg';
        $bytes = file_get_contents($tmp);

        // validate simple type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmp);
        if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
            $msg = "Unsupported file type: {$mime}. Use JPG/PNG/WEBP.";
        } else {
            // normalized filename
            $ext = ($mime === 'image/png') ? 'png' : (($mime === 'image/webp') ? 'webp' : 'jpg');
            $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo($orig, PATHINFO_FILENAME));
            $key = "bg/" . date('Ymd_His') . "_" . $safe . "." . $ext;

            try {
                $up = cw_spaces_put_object($key, $bytes, $mime);

                $stmt = $pdo->prepare("INSERT INTO backgrounds (name, bg_path, sort_order, is_active) VALUES (?,?,?,1)");
                $stmt->execute([$name, $key, (int)($_POST['sort_order'] ?? 0)]);

                $msg = "Uploaded OK: {$up['cdn_url']}";
            } catch (Exception $e) {
                $msg = "Upload error: " . $e->getMessage();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    $id = (int)$_POST['id'];
    $active = (int)$_POST['is_active'];
    $pdo->prepare("UPDATE backgrounds SET is_active=? WHERE id=?")->execute([$active, $id]);
    redirect('/admin/backgrounds.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rename') {
    $id = (int)$_POST['id'];
    $name = trim((string)$_POST['name']);
    $sort = (int)$_POST['sort_order'];
    $pdo->prepare("UPDATE backgrounds SET name=?, sort_order=? WHERE id=?")->execute([$name, $sort, $id]);
    redirect('/admin/backgrounds.php');
}

$rows = $pdo->query("SELECT * FROM backgrounds ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);

cw_header('Backgrounds');
?>
<div class="card">
  <h2>Upload Background (to Spaces)</h2>
  <?php if ($msg): ?><div class="alert"><?= h($msg) ?></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="form-grid">
    <input type="hidden" name="action" value="upload">

    <label>Name</label>
    <input name="name" placeholder="e.g. IPCA Title Slide">

    <label>Order</label>
    <input name="sort_order" type="number" value="0">

    <label>Image file</label>
    <input type="file" name="bg_file" accept="image/*" required>

    <div></div>
    <button class="btn" type="submit">Upload</button>
  </form>

  <p class="muted">Stored in Spaces under <code>bg/</code>. You can select per Course/Lesson/Slide.</p>
</div>

<div class="card">
  <h2>Existing Backgrounds</h2>
  <table>
    <tr><th>ID</th><th>Preview</th><th>Name</th><th>Key/Path</th><th>Order</th><th>Active</th><th>Actions</th></tr>
    <?php foreach ($rows as $r): ?>
      <?php $url = cw_resolve_bg_url((string)$r['bg_path']); ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><img src="<?= h($url) ?>" style="width:160px;border-radius:10px;border:1px solid #eee;"></td>

        <td>
          <form method="post" style="display:flex;gap:8px;align-items:center;">
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input name="name" value="<?= h($r['name']) ?>" style="width:220px;">
        </td>

        <td style="font-size:12px;"><?= h($r['bg_path']) ?></td>

        <td>
            <input name="sort_order" type="number" value="<?= (int)$r['sort_order'] ?>" style="width:70px;">
        </td>

        <td><?= ((int)$r['is_active']===1) ? 'Yes' : 'No' ?></td>

        <td style="white-space:nowrap;">
            <button class="btn btn-sm" type="submit">Save</button>
          </form>

          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="is_active" value="<?= ((int)$r['is_active']===1)?0:1 ?>">
            <button class="btn btn-sm" type="submit"><?= ((int)$r['is_active']===1)?'Disable':'Enable' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php cw_footer(); ?>