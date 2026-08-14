<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingReaderService.php';

compliance_require_access($pdo);
$versionId = isset($_GET['version_id']) ? (int)$_GET['version_id'] : 0;
redirect(
    '/admin/compliance/controlled_book_editor.php?version_id='
    . $versionId
);

$reader = new ControlledPublishingReaderService($pdo);
$version = $versionId > 0 ? $reader->resolveVersionById($versionId) : null;
if ($version === null) {
    http_response_code(404);
    cw_header('Page Preview');
    echo '<main style="padding:32px;"><p>Manual version not found.</p></main>';
    cw_footer();
    return;
}
$source = $reader->loadReaderPaginateSource($version);
$publication = $reader->paginationPublicationPackage($version, $source);
$bookCss = (string)($publication['css']['content'] ?? '');
$editable = in_array((string)$version['lifecycle_status'], array('draft', 'in_review', 'approved'), true);

cw_header('Page Preview · ' . (string)$version['book_key'] . ' ' . (string)$version['version_label']);
?>
<style>
<?= str_replace('</style', '<\/style', $bookCss) ?>
.cpp-shell{display:grid;grid-template-columns:minmax(880px,1fr) 320px;min-height:calc(100vh - 64px);background:#e7e9ed}
.cpp-toolbar{position:sticky;top:0;z-index:20;grid-column:1/-1;display:flex;align-items:center;gap:10px;padding:10px 18px;background:#fff;border-bottom:1px solid #cbd0d8;box-shadow:0 1px 4px #0001}
.cpp-toolbar a{color:#1f4f8a;text-decoration:none}.cpp-toolbar .cpp-spacer{flex:1}.cpp-status{font-size:13px;color:#536170}
.cpp-workspace{padding:28px 36px 80px;min-width:0}
.cpp-pages{display:flex;flex-direction:column;align-items:center;gap:28px}
.cpp-page{position:relative;width:816px;min-height:1056px;background:#fff;border:1px solid #c8cdd4;box-shadow:0 5px 18px #252b3440}
.cpp-page-label{position:absolute;left:-72px;top:8px;width:58px;text-align:right;color:#657180;font:12px/1.3 system-ui,sans-serif}
.cpp-page>.reader-generated-page{margin:0!important}
.reader-page-header-region>.cpb-page-header,
.reader-page-footer-region>.cpb-page-footer{position:static!important;inset:auto!important;width:100%!important;height:100%!important;margin:0!important;box-sizing:border-box!important}
.cpp-sidebar{position:sticky;top:51px;align-self:start;height:calc(100vh - 51px);overflow:auto;padding:18px;background:#fff;border-left:1px solid #cbd0d8}
.cpp-sidebar h2{margin:0 0 8px;font:600 16px/1.3 system-ui,sans-serif}.cpp-sidebar p,.cpp-sidebar label{font:13px/1.45 system-ui,sans-serif;color:#536170}
.cpp-sidebar select{width:100%;margin:5px 0 8px}.cpp-break{padding:10px 0;border-top:1px solid #e3e6ea;font:12px/1.4 system-ui,sans-serif}.cpp-break code{word-break:break-all}
.cpp-empty{padding:50px;color:#5d6774;font:15px/1.5 system-ui,sans-serif;text-align:center}
.cpp-busy{opacity:.6;pointer-events:none}
.reader-semantic-piece.cpp-manual-break-before{outline:2px solid #1e73be44;outline-offset:3px}
.reader-semantic-piece.cpp-manual-break-before:before{content:"Manual Page Break";display:block;color:#1e5f9e!important;background:#e8f2ff!important;border:1px dashed #1e73be!important;padding:3px 7px!important;margin:0 0 8px!important;font:600 10px/1.2 system-ui,sans-serif!important}
@media(max-width:1250px){.cpp-shell{grid-template-columns:1fr}.cpp-sidebar{position:static;height:auto;border-left:0;border-top:1px solid #cbd0d8}.cpp-workspace{overflow:auto}}
</style>
<div class="cpp-shell" id="cpp-shell">
  <header class="cpp-toolbar">
    <a href="/admin/compliance/controlled_book_version.php?id=<?= $versionId ?>">← Version</a>
    <strong>Page Preview</strong>
    <span><?= h((string)$version['book_key']) ?> <?= h((string)$version['version_label']) ?></span>
    <span class="cpp-status" id="cpp-status">Loading pagination…</span>
    <span class="cpp-spacer"></span>
    <?php if ($editable): ?>
      <button type="button" id="cpp-generate">Regenerate</button>
      <button type="button" id="cpp-approve">Approve pagination</button>
    <?php endif; ?>
  </header>
  <main class="cpp-workspace">
    <div class="cpp-pages" id="cpp-pages"><div class="cpp-empty">Loading authoritative pages…</div></div>
  </main>
  <aside class="cpp-sidebar">
    <h2>Manual page breaks</h2>
    <p>Breaks are editorial instructions attached before stable source blocks. Automatic breaks are not stored.</p>
    <?php if ($editable): ?>
      <label for="cpp-candidate">Insert break before block</label>
      <select id="cpp-candidate"></select>
      <button type="button" id="cpp-insert-break">Insert Page Break</button>
    <?php else: ?>
      <p><strong>Released pagination is frozen.</strong></p>
    <?php endif; ?>
    <div id="cpp-breaks"></div>
  </aside>
</div>
<script>
(() => {
  const versionId = <?= $versionId ?>;
  const editable = <?= $editable ? 'true' : 'false' ?>;
  const shell = document.getElementById('cpp-shell');
  const pagesNode = document.getElementById('cpp-pages');
  const statusNode = document.getElementById('cpp-status');
  const breaksNode = document.getElementById('cpp-breaks');
  const candidateNode = document.getElementById('cpp-candidate');
  let breakRows = [];
  let candidateRows = [];

  async function request(url, body = null) {
    const options = body ? {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(body)
    } : {};
    const response = await fetch(url, options);
    const payload = await response.json();
    if (!response.ok || !payload.ok) throw new Error(payload.error || 'Request failed.');
    return payload;
  }

  function setBusy(busy, text = '') {
    shell.classList.toggle('cpp-busy', busy);
    if (text) statusNode.textContent = text;
  }

  function markManualBreaks() {
    document.querySelectorAll('.cpp-manual-break-before').forEach((node) =>
      node.classList.remove('cpp-manual-break-before')
    );
    breakRows.forEach((row) => {
      const anchor = String(row.before_block_anchor || '');
      document.querySelectorAll('[data-source-fragment-id]').forEach((node) => {
        const fragment = node.getAttribute('data-source-fragment-id') || '';
        if (fragment === anchor || fragment.startsWith(anchor + '/')) {
          node.classList.add('cpp-manual-break-before');
        }
      });
    });
  }

  function renderPages(result) {
    const pages = Array.isArray(result.pages) ? result.pages : [];
    const freshness = result.freshness && result.freshness.is_current
      ? 'Current'
      : 'Needs regeneration';
    statusNode.textContent = `${result.lifecycle_status === 'released' ? 'Released' : 'Draft'} pagination · ${pages.length} pages · ${breakRows.length} manual breaks · ${freshness} · ${(result.pagination && result.pagination.status) || 'not generated'}`;
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
    markManualBreaks();
  }

  function renderBreaks(payload) {
    breakRows = payload.breaks || [];
    candidateRows = payload.candidates || [];
    const existing = new Set(breakRows.map((row) => row.before_block_anchor));
    breaksNode.replaceChildren(...breakRows.map((row) => {
      const item = document.createElement('div');
      item.className = 'cpp-break';
      item.innerHTML = `<strong>Before ${row.block_type || 'missing block'}</strong><br><span>${row.section_title || ''}</span><br><code>${row.before_block_anchor}</code>`;
      if (editable) {
        const moveTarget = document.createElement('select');
        candidateRows.filter((candidate) =>
          candidate.stable_anchor !== row.before_block_anchor
          && !existing.has(candidate.stable_anchor)
        ).forEach((candidate) => {
          const option = document.createElement('option');
          option.value = candidate.stable_anchor;
          option.textContent = `${candidate.section_title} · ${candidate.block_type}`;
          moveTarget.appendChild(option);
        });
        const move = document.createElement('button');
        move.type = 'button';
        move.textContent = 'Move';
        move.addEventListener('click', () => mutateBreak({
          action: 'move',
          book_version_id: versionId,
          break_id: row.id,
          before_block_anchor: moveTarget.value
        }));
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.textContent = 'Remove';
        remove.addEventListener('click', () => mutateBreak({
          action: 'remove', book_version_id: versionId, break_id: row.id
        }));
        item.append(document.createElement('br'), moveTarget, move, remove);
      }
      return item;
    }));
    if (editable && candidateNode) {
      candidateNode.replaceChildren(...candidateRows
        .filter((row) => !existing.has(row.stable_anchor))
        .map((row) => {
          const option = document.createElement('option');
          option.value = row.stable_anchor;
          option.textContent = `${row.section_title} · ${row.block_type} · ${row.stable_anchor}`;
          return option;
        }));
    }
  }

  async function load() {
    setBusy(true, 'Loading pagination…');
    try {
      const [pagePayload, breakPayload] = await Promise.all([
        request(`/admin/api/controlled_book_page_map_api.php?action=stored_preview&book_version_id=${versionId}`),
        request(`/admin/api/controlled_book_page_break_api.php?action=list&book_version_id=${versionId}`)
      ]);
      renderBreaks(breakPayload);
      renderPages(pagePayload.result);
    } catch (error) {
      statusNode.textContent = error.message;
      pagesNode.innerHTML = `<div class="cpp-empty">${error.message}</div>`;
    } finally {
      setBusy(false);
    }
  }

  async function generate() {
    setBusy(true, 'Generating and validating authoritative pages…');
    try {
      await request('/admin/api/controlled_book_page_map_api.php', {
        action: 'generate',
        book_key: <?= json_encode((string)$version['book_key']) ?>,
        book_version_id: versionId
      });
      await load();
    } catch (error) {
      statusNode.textContent = `Generation failed: ${error.message}`;
      setBusy(false);
    }
  }

  async function mutateBreak(body) {
    setBusy(true, 'Saving manual page break…');
    try {
      await request('/admin/api/controlled_book_page_break_api.php', body);
      await generate();
    } catch (error) {
      statusNode.textContent = error.message;
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
      await load();
    } catch (error) {
      statusNode.textContent = error.message;
      setBusy(false);
    }
  });
  document.getElementById('cpp-insert-break')?.addEventListener('click', () => {
    if (!candidateNode.value) return;
    mutateBreak({
      action: 'insert',
      book_version_id: versionId,
      before_block_anchor: candidateNode.value
    });
  });
  load();
})();
</script>
<?php cw_footer(); ?>
