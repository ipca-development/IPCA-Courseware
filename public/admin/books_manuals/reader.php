<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingReaderService.php';

cw_require_admin();
$versionId = (int)($_GET['version_id'] ?? 0);
$reader = new ControlledPublishingReaderService($pdo);
$version = $versionId > 0 ? $reader->resolveVersionById($versionId) : null;
if ($version === null) {
    http_response_code(404);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h(is_array($version) ? (string)$version['book_key'] . ' ' . (string)$version['version_label'] : 'Book') ?></title>
  <style>
    *{box-sizing:border-box}
    html,body{margin:0;min-height:100%;background:#e7e9ed}
    body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
    .bmr-pages{display:flex;flex-direction:column;align-items:center;gap:28px;padding:30px 40px 70px}
    .bmr-page{width:816px;min-height:1056px;background:#fff;box-shadow:0 7px 24px rgba(20,31,45,.28)}
    .bmr-page>.reader-generated-page{margin:0!important}
    .reader-canonical-page.cpb-sheet{padding:0;margin:0;zoom:1;max-width:none;min-height:0;box-shadow:none;border-radius:0}
    .reader-page-header-region,
    .reader-page-footer-region,
    .reader-page-body:not(.reader-page-cover){overflow:hidden}
    .reader-page-header-region>.cpb-page-header,
    .reader-page-footer-region>.cpb-page-footer{position:static!important;inset:auto!important;width:100%!important;height:100%!important;margin:0!important;box-sizing:border-box!important}
    .bmr-message{margin:70px auto;padding:22px 26px;max-width:620px;border-radius:12px;color:#516276;background:#fff;text-align:center;box-shadow:0 6px 22px #12253c1c}
    @media(max-width:900px){.bmr-pages{align-items:flex-start;overflow:auto}.bmr-page{flex:0 0 816px}}
  </style>
</head>
<body>
  <?php if ($version === null): ?>
    <div class="bmr-message">Manual version not found.</div>
  <?php else: ?>
    <main class="bmr-pages" id="bmr-pages">
      <div class="bmr-message">Loading pages…</div>
    </main>
    <script>
    (() => {
      const versionId = <?= $versionId ?>;
      const pagesNode = document.getElementById('bmr-pages');
      let styleTag = null;

      function message(text) {
        pagesNode.innerHTML = '';
        const node = document.createElement('div');
        node.className = 'bmr-message';
        node.textContent = text;
        pagesNode.appendChild(node);
      }

      function applyBookStyle(css) {
        if (styleTag) styleTag.remove();
        if (!css) return;
        styleTag = document.createElement('style');
        styleTag.textContent = String(css).replaceAll('</style', '<\\/style');
        document.head.appendChild(styleTag);
      }

      async function loadPages() {
        try {
          const response = await fetch(
            `/admin/api/controlled_book_page_map_api.php?action=stored_preview&book_version_id=${versionId}`
          );
          const payload = await response.json();
          if (!response.ok || !payload.ok) {
            const error = payload.error || {};
            throw new Error(typeof error === 'string' ? error : (error.message || 'Unable to load pages.'));
          }
          const result = payload.result || {};
          const pages = Array.isArray(result.pages) ? result.pages : [];
          applyBookStyle(result.book_style_css || '');
          if (!pages.length) {
            message('No stored authoritative pages are available for this revision.');
            return;
          }
          pagesNode.replaceChildren(...pages.map((page) => {
            const frame = document.createElement('section');
            frame.className = 'bmr-page';
            frame.setAttribute('aria-label', `Page ${page.page_number || ''}`);
            const content = document.createElement('div');
            content.innerHTML = page.page_html || '';
            while (content.firstChild) frame.appendChild(content.firstChild);
            return frame;
          }));
        } catch (error) {
          message(error instanceof Error ? error.message : 'Unable to load pages.');
        }
      }

      loadPages();
    })();
    </script>
  <?php endif; ?>
</body>
</html>
