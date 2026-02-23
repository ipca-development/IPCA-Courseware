<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$slideId = (int)($_GET['slide_id'] ?? 0);
if ($slideId <= 0) exit('Missing slide_id');

$stmt = $pdo->prepare("
  SELECT s.*, l.external_lesson_id, p.program_key
  FROM slides s
  JOIN lessons l ON l.id=s.lesson_id
  JOIN courses c ON c.id=l.course_id
  JOIN programs p ON p.id=c.program_id
  WHERE s.id=?
");
$stmt->execute([$slideId]);
$slide = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$slide) exit('Slide not found');

$templates = $pdo->query("SELECT template_key, name FROM templates WHERE is_active=1 ORDER BY sort_order")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $tpl = (string)($_POST['template_key'] ?? $slide['template_key']);
    $htmlLeft = (string)($_POST['html_left'] ?? '');
    $htmlRight = (string)($_POST['html_right'] ?? '');
    $mentor = trim((string)($_POST['mentor_video_path'] ?? ''));
    $video = trim((string)($_POST['video_path'] ?? ''));

    // store html_rendered too (fast mini previews)
    $templateRow = cw_get_template($pdo, $tpl);
    $rendered = cw_render_slide_html($CDN_BASE, [
        'image_path' => $slide['image_path'],
        'html_left' => $htmlLeft,
        'html_right' => $htmlRight
    ], $templateRow);

    $stmt = $pdo->prepare("
      UPDATE slides
      SET template_key=?, html_left=?, html_right=?, html_rendered=?, mentor_video_path=?, video_path=?
      WHERE id=?
    ");
    $stmt->execute([
        $tpl, $htmlLeft, $htmlRight, $rendered,
        ($mentor !== '' ? $mentor : null),
        ($video !== '' ? $video : null),
        $slideId
    ]);

    redirect('/admin/slide_edit.php?slide_id=' . $slideId);
}

cw_header('Slide Edit');

$imgUrl = cdn_url($CDN_BASE, (string)$slide['image_path']);
?>
<div class="card">
  <p class="muted">
    Program: <?= h($slide['program_key']) ?> • Lesson <?= (int)$slide['external_lesson_id'] ?> • Page <?= (int)$slide['page_number'] ?>
  </p>

  <div style="display:grid;grid-template-columns:1.1fr 0.9fr;gap:16px;align-items:start;">
    <div>
      <h2>Screenshot reference</h2>
      <a target="_blank" href="<?= h($imgUrl) ?>"><img src="<?= h($imgUrl) ?>" style="width:100%;border-radius:12px;"></a>

      <form method="post" id="slideForm" style="margin-top:12px;">
        <input type="hidden" name="action" value="save">
        <div class="form-grid">
          <label>Template</label>
          <select name="template_key" id="template_key">
            <?php foreach ($templates as $t): ?>
              <option value="<?= h($t['template_key']) ?>" <?= ($slide['template_key'] === $t['template_key']) ? 'selected' : '' ?>>
                <?= h($t['template_key']) ?> — <?= h($t['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <label>Video path</label>
          <input name="video_path" id="video_path" value="<?= h((string)($slide['video_path'] ?? '')) ?>" placeholder="ks_videos/private/...mp4">

          <label>Mentor path</label>
          <input name="mentor_video_path" id="mentor_video_path" value="<?= h((string)($slide['mentor_video_path'] ?? '')) ?>" placeholder="mentor/private/...mp4">
        </div>

        <hr>

        <h3>Left HTML</h3>
        <div class="cw-toolbar" data-target="html_left">
          <button type="button" data-cmd="b"><b>B</b></button>
          <button type="button" data-cmd="i"><i>I</i></button>
          <button type="button" data-cmd="u"><u>U</u></button>
          <button type="button" data-cmd="ul">• List</button>
          <button type="button" data-cmd="ol">1. List</button>
          <button type="button" data-cmd="indent">Indent</button>
          <button type="button" data-cmd="outdent">Outdent</button>
          <select data-cmd="fs">
            <option value="">Font</option>
            <option value="14">14px</option>
            <option value="16">16px</option>
            <option value="18">18px</option>
            <option value="22">22px</option>
          </select>
        </div>
        <textarea id="html_left" name="html_left" rows="10" style="width:100%;"><?= h((string)($slide['html_left'] ?? '')) ?></textarea>

        <h3>Right HTML</h3>
        <div class="cw-toolbar" data-target="html_right">
          <button type="button" data-cmd="b"><b>B</b></button>
          <button type="button" data-cmd="i"><i>I</i></button>
          <button type="button" data-cmd="u"><u>U</u></button>
          <button type="button" data-cmd="ul">• List</button>
          <button type="button" data-cmd="ol">1. List</button>
          <button type="button" data-cmd="indent">Indent</button>
          <button type="button" data-cmd="outdent">Outdent</button>
          <select data-cmd="fs">
            <option value="">Font</option>
            <option value="14">14px</option>
            <option value="16">16px</option>
            <option value="18">18px</option>
            <option value="22">22px</option>
          </select>
        </div>
        <textarea id="html_right" name="html_right" rows="10" style="width:100%;"><?= h((string)($slide['html_right'] ?? '')) ?></textarea>

        <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
          <button class="btn" type="submit">Save</button>
          <button class="btn btn-sm" type="button" id="refreshPreview">Refresh preview</button>

          <button class="btn btn-sm" type="button" id="ocrRight">OCR → Right</button>
          <button class="btn btn-sm" type="button" id="ocrLeft">OCR → Left</button>
          <label class="muted" style="display:flex;gap:6px;align-items:center;">
            <input type="checkbox" id="ocrFillHtml" checked> fill HTML
          </label>

          <a class="btn btn-sm" href="/admin/slides.php?lesson_id=<?= (int)$slide['lesson_id'] ?>">Back</a>
        </div>
      </form>
    </div>

    <div>
      <h2>Live preview</h2>
      <iframe id="previewFrame" style="width:100%;height:740px;border:1px solid #e5e5e5;border-radius:12px;background:#f4f6ff;"></iframe>
      <p class="muted" style="margin-top:8px;">Preview uses your real IPCA background + template.</p>
      <div id="ocrStatus" class="muted"></div>
    </div>
  </div>
</div>

<script>
function wrapSelection(textarea, before, after){
  const start = textarea.selectionStart;
  const end = textarea.selectionEnd;
  const value = textarea.value;
  const selected = value.substring(start, end);
  textarea.value = value.substring(0, start) + before + selected + after + value.substring(end);
  textarea.focus();
  textarea.selectionStart = start + before.length;
  textarea.selectionEnd = start + before.length + selected.length;
}

function listify(textarea, ordered){
  const start = textarea.selectionStart;
  const end = textarea.selectionEnd;
  const value = textarea.value;
  const selected = value.substring(start, end) || '';
  const lines = selected.split(/\r?\n/).filter(l => l.trim() !== '');
  if (!lines.length) return;
  const tagOpen = ordered ? "<ol>\n" : "<ul>\n";
  const tagClose = ordered ? "</ol>" : "</ul>";
  const items = lines.map(l => "  <li>" + l.replace(/<\/?[^>]+>/g,'') + "</li>").join("\n");
  textarea.value = value.substring(0, start) + tagOpen + items + "\n" + tagClose + value.substring(end);
  textarea.focus();
}

function indentBlock(textarea, dir){
  const start = textarea.selectionStart;
  const end = textarea.selectionEnd;
  const value = textarea.value;
  const selected = value.substring(start, end);
  if (!selected) return;
  if (dir === 'in') wrapSelection(textarea, '<div style="margin-left:24px;">\n', '\n</div>');
  else {
    const newSel = selected.replace(/^<div style="margin-left:24px;">\s*/,'').replace(/\s*<\/div>\s*$/,'');
    textarea.value = value.substring(0, start) + newSel + value.substring(end);
    textarea.focus();
  }
}

function setFontSize(textarea, px){
  if (!px) return;
  wrapSelection(textarea, '<span style="font-size:'+px+'px;">', '</span>');
}

function installToolbar(toolbar){
  const targetId = toolbar.dataset.target;
  const ta = document.getElementById(targetId);

  toolbar.addEventListener('click', (e)=>{
    const btn = e.target.closest('button');
    if (!btn) return;
    const cmd = btn.dataset.cmd;
    if (!cmd) return;
    if (cmd === 'b') wrapSelection(ta, '<b>', '</b>');
    if (cmd === 'i') wrapSelection(ta, '<i>', '</i>');
    if (cmd === 'u') wrapSelection(ta, '<u>', '</u>');
    if (cmd === 'ul') listify(ta, false);
    if (cmd === 'ol') listify(ta, true);
    if (cmd === 'indent') indentBlock(ta, 'in');
    if (cmd === 'outdent') indentBlock(ta, 'out');
  });

  const sel = toolbar.querySelector('select[data-cmd="fs"]');
  if (sel) {
    sel.addEventListener('change', ()=>{
      setFontSize(ta, sel.value);
      sel.value = '';
    });
  }

  ta.addEventListener('keydown', (e)=>{
    if (e.key === 'Tab') {
      e.preventDefault();
      const start = ta.selectionStart;
      const end = ta.selectionEnd;
      const v = ta.value;
      ta.value = v.substring(0, start) + "    " + v.substring(end);
      ta.selectionStart = ta.selectionEnd = start + 4;
    }
  });
}
document.querySelectorAll('.cw-toolbar').forEach(installToolbar);

// Preview
const previewFrame = document.getElementById('previewFrame');
const refreshBtn = document.getElementById('refreshPreview');
let timer = null;

async function refreshPreview(){
  const payload = {
    template_key: document.getElementById('template_key').value,
    image_path: <?= json_encode((string)$slide['image_path']) ?>,
    html_left: document.getElementById('html_left').value,
    html_right: document.getElementById('html_right').value
  };

  const res = await fetch('/admin/api/render_preview.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  });

  const html = await res.text();
  const doc = previewFrame.contentWindow.document;
  doc.open(); doc.write(html); doc.close();
}
refreshBtn.addEventListener('click', refreshPreview);

['html_left','html_right','template_key'].forEach(id=>{
  document.getElementById(id).addEventListener('input', ()=>{
    if (timer) clearTimeout(timer);
    timer = setTimeout(refreshPreview, 700);
  });
});

// OCR
async function runOCR(target){
  const status = document.getElementById('ocrStatus');
  status.textContent = "Running OCR…";
  const fill = document.getElementById('ocrFillHtml').checked ? '1' : '';
  const form = new FormData();
  form.append('slide_id', <?= (int)$slideId ?>);
  form.append('target', target);
  if (fill) form.append('fill_html', '1');

  const res = await fetch('/admin/api/ocr_slide.php', { method:'POST', body: form });
  const j = await res.json();
  if (!j.ok) {
    status.textContent = "OCR error: " + (j.error || 'unknown');
    return;
  }
  status.textContent = "OCR done. chars=" + j.raw_ocr_chars + " fill_html=" + j.filled_html;

  // Reload page to show updated html fields if filled
  location.reload();
}

document.getElementById('ocrRight').addEventListener('click', ()=>runOCR('right'));
document.getElementById('ocrLeft').addEventListener('click', ()=>runOCR('left'));

// initial preview
refreshPreview();
</script>

<?php cw_footer(); ?>