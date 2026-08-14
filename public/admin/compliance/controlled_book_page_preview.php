<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingReaderService.php';

compliance_require_access($pdo);
$versionId = isset($_GET['version_id']) ? (int)$_GET['version_id'] : 0;

$reader = new ControlledPublishingReaderService($pdo);
$version = $versionId > 0 ? $reader->resolveVersionById($versionId) : null;
if ($version === null) {
    http_response_code(404);
    cw_header('Exact Page Preview');
    echo '<main style="padding:32px;"><p>Manual version not found.</p></main>';
    cw_footer();
    return;
}
$editable = in_array((string)$version['lifecycle_status'], array('draft', 'in_review', 'approved'), true);
$editorUrl = '/admin/compliance/controlled_book_editor.php?version_id=' . $versionId;
$versionUrl = '/admin/compliance/controlled_book_version.php?id=' . $versionId;

cw_header('Exact Page Preview · ' . (string)$version['book_key'] . ' ' . (string)$version['version_label']);
?>
<style>
.cpp-shell{display:grid;grid-template-columns:minmax(880px,1fr) 300px;min-height:calc(100vh - 64px);background:#e7e9ed}
.cpp-toolbar{position:sticky;top:0;z-index:20;grid-column:1/-1;display:flex;align-items:center;gap:10px;padding:10px 18px;background:#fff;border-bottom:1px solid #cbd0d8;box-shadow:0 1px 4px #0001}
.cpp-toolbar a{color:#1f4f8a;text-decoration:none}.cpp-toolbar .cpp-spacer{flex:1}.cpp-status{font-size:13px;color:#536170}
.cpp-workspace{padding:28px 36px 80px;min-width:0}
.cpp-pages{display:flex;flex-direction:column;align-items:center;gap:28px}
.cpp-page{position:relative;width:816px;min-height:1056px;background:#fff;border:1px solid #c8cdd4;box-shadow:0 5px 18px #252b3440}
.cpp-page-label{position:absolute;left:-72px;top:8px;width:58px;text-align:right;color:#657180;font:12px/1.3 system-ui,sans-serif}
.cpp-page>.reader-generated-page{margin:0!important}
.reader-canonical-page.cpb-sheet{padding:0;margin:0;zoom:1;max-width:none;min-height:0;box-shadow:none;border-radius:0}
.reader-page-header-region,
.reader-page-footer-region,
.reader-page-body:not(.reader-page-cover){overflow:hidden}
.reader-page-header-region>.cpb-page-header,
.reader-page-footer-region>.cpb-page-footer{position:static!important;inset:auto!important;width:100%!important;height:100%!important;margin:0!important;box-sizing:border-box!important}
.cpp-sidebar{position:sticky;top:51px;align-self:start;height:calc(100vh - 51px);overflow:auto;padding:18px;background:#fff;border-left:1px solid #cbd0d8}
.cpp-sidebar h2{margin:0 0 8px;font:600 16px/1.3 system-ui,sans-serif}
.cpp-sidebar p,.cpp-sidebar dt,.cpp-sidebar dd{font:13px/1.45 system-ui,sans-serif;color:#536170}
.cpp-sidebar dl{margin:0 0 16px}.cpp-sidebar dt{font-weight:600;color:#243040}.cpp-sidebar dd{margin:0 0 8px}
.cpp-empty{padding:50px;color:#5d6774;font:15px/1.5 system-ui,sans-serif;text-align:center}
.cpp-error{max-width:720px;margin:40px auto;padding:24px;background:#fff6f6;border:1px solid #e8b4b4;color:#7f1d1d;font:15px/1.5 system-ui,sans-serif}
.cpp-error a{color:#1f4f8a}
.cpp-busy{opacity:.6;pointer-events:none}
.cpp-note{font:12px/1.4 system-ui,sans-serif;color:#657180;margin-top:16px}
@media(max-width:1250px){.cpp-shell{grid-template-columns:1fr}.cpp-sidebar{position:static;height:auto;border-left:0;border-top:1px solid #cbd0d8}.cpp-workspace{overflow:auto}}
</style>
<div class="cpp-shell" id="cpp-shell">
  <header class="cpp-toolbar">
    <a href="<?= h($versionUrl) ?>">← Version</a>
    <a href="<?= h($editorUrl) ?>">Back to Editor</a>
    <strong>Exact Page Preview</strong>
    <span><?= h((string)$version['book_key']) ?> <?= h((string)$version['version_label']) ?></span>
    <span class="cpp-status" id="cpp-status">Loading stored pages…</span>
    <span class="cpp-spacer"></span>
    <?php if ($editable): ?>
      <button type="button" id="cpp-generate">Regenerate</button>
      <button type="button" id="cpp-approve">Finalize / Approve</button>
    <?php endif; ?>
  </header>
  <main class="cpp-workspace">
    <div class="cpp-pages" id="cpp-pages"><div class="cpp-empty">Loading stored authoritative pages…</div></div>
  </main>
  <aside class="cpp-sidebar">
    <h2>Pagination</h2>
    <dl>
      <dt>Status</dt><dd id="cpp-meta-status">—</dd>
      <dt>Page count</dt><dd id="cpp-meta-count">—</dd>
      <dt>Freshness</dt><dd id="cpp-meta-fresh">—</dd>
      <dt>Validation</dt><dd id="cpp-meta-valid">—</dd>
    </dl>
    <p class="cpp-note">This preview shows the stored authoritative page HTML only. Viewing never regenerates or reinterprets pages.</p>
  </aside>
</div>
<script>
(() => {
  const versionId = <?= $versionId ?>;
  const bookKey = <?= json_encode((string)$version['book_key']) ?>;
  const editorUrl = <?= json_encode($editorUrl) ?>;
  const shell = document.getElementById('cpp-shell');
  const pagesNode = document.getElementById('cpp-pages');
  const statusNode = document.getElementById('cpp-status');
  let bookStyleTag = null;

  async function request(url, body = null) {
    const options = body ? {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(body)
    } : {};
    const response = await fetch(url, options);
    const payload = await response.json();
    if (!response.ok || !payload.ok) {
      const error = payload.error || { message: 'Request failed.' };
      const wrapped = new Error(typeof error === 'string' ? error : (error.message || 'Request failed.'));
      wrapped.payload = typeof error === 'object' ? error : { message: String(error) };
      wrapped.httpStatus = response.status;
      throw wrapped;
    }
    return payload;
  }

  function setBusy(busy, text = '') {
    shell.classList.toggle('cpp-busy', busy);
    if (text) statusNode.textContent = text;
  }

  function applyBookStyle(css) {
    if (bookStyleTag) bookStyleTag.remove();
    if (!css) return;
    bookStyleTag = document.createElement('style');
    bookStyleTag.textContent = String(css).replaceAll('</style', '<\\/style');
    document.head.appendChild(bookStyleTag);
  }

  function setMeta(status, count, freshness, validation) {
    document.getElementById('cpp-meta-status').textContent = status;
    document.getElementById('cpp-meta-count').textContent = String(count);
    document.getElementById('cpp-meta-fresh').textContent = freshness;
    document.getElementById('cpp-meta-valid').textContent = validation;
  }

  function renderManualBreakRequired(error) {
    const title = error.before_block_title || error.before_block_anchor || 'the overflowing block';
    const returnUrl = error.editor_url || editorUrl;
    pagesNode.innerHTML = `<div class="cpp-error">
      <p><strong>A Manual Page Break is required.</strong></p>
      <p>${escapeHtml(error.message || 'Page content exceeds the available body area.')}</p>
      <p>Insert another Manual Page Break before: “${escapeHtml(title)}”</p>
      <p><a href="${escapeHtml(returnUrl)}">Return to Editor</a></p>
    </div>`;
    setMeta('validation failed', 0, 'Not current', 'MANUAL_BREAK_REQUIRED');
  }

  function escapeHtml(value) {
    return String(value || '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;');
  }

  function renderPages(result) {
    const pages = Array.isArray(result.pages) ? result.pages : [];
    const freshness = result.freshness && result.freshness.is_current ? 'Current' : 'Needs regeneration';
    const approval = (result.pagination && result.pagination.status) || 'not generated';
    const validation = result.freshness && result.freshness.is_current ? 'Passed' : 'Stale or missing';
    statusNode.textContent = `${result.lifecycle_status === 'released' ? 'Released' : 'Draft'} · ${pages.length} pages · ${freshness} · ${approval}`;
    setMeta(approval, result.page_count || pages.length, freshness, validation);
    applyBookStyle(result.book_style_css);
    if (!pages.length) {
      pagesNode.innerHTML = '<div class="cpp-empty">Pagination has not been generated. Use Regenerate to create the authoritative draft page map.</div>';
      return;
    }
    pagesNode.replaceChildren(...pages.map((page) => {
      const frame = document.createElement('section');
      frame.className = 'cpp-page';
      frame.dataset.pageNumber = String(page.page_number);
      const label = document.createElement('span');
      label.className = 'cpp-page-label';
      label.textContent = `Page ${page.page_number}`;
      frame.appendChild(label);
      const content = document.createElement('div');
      content.innerHTML = page.page_html;
      while (content.firstChild) frame.appendChild(content.firstChild);
      return frame;
    }));
  }

  async function loadStored() {
    setBusy(true, 'Loading stored pages…');
    try {
      const pagePayload = await request(
        `/admin/api/controlled_book_page_map_api.php?action=stored_preview&book_version_id=${versionId}`
      );
      renderPages(pagePayload.result);
    } catch (error) {
      statusNode.textContent = error.message;
      pagesNode.innerHTML = `<div class="cpp-empty">${escapeHtml(error.message)}</div>`;
    } finally {
      setBusy(false);
    }
  }

  async function generate() {
    setBusy(true, 'Generating and validating authoritative pages…');
    try {
      await request('/admin/api/controlled_book_page_map_api.php', {
        action: 'generate',
        book_key: bookKey,
        book_version_id: versionId
      });
      await loadStored();
    } catch (error) {
      const payload = error.payload || {};
      if (payload.code === 'MANUAL_BREAK_REQUIRED') {
        statusNode.textContent = 'MANUAL_BREAK_REQUIRED';
        renderManualBreakRequired(payload);
        setBusy(false);
        return;
      }
      statusNode.textContent = `Generation failed: ${error.message}`;
      setBusy(false);
    }
  }

  document.getElementById('cpp-generate')?.addEventListener('click', generate);
  document.getElementById('cpp-approve')?.addEventListener('click', async () => {
    setBusy(true, 'Approving validated pagination…');
    try {
      await request('/admin/api/controlled_book_page_map_api.php', {
        action: 'approve', book_version_id: versionId
      });
      await loadStored();
    } catch (error) {
      statusNode.textContent = error.message;
      setBusy(false);
    }
  });
  loadStored();
})();
</script>
<?php cw_footer(); ?>
