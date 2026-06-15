<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/publishing/ControlledPublishingReaderService.php';
require_once __DIR__ . '/../../src/publishing/ControlledPublishingReaderAccessService.php';

cw_require_login();

$u = cw_current_user($pdo);
$access = new ControlledPublishingReaderAccessService();
if (!$access->canReadManuals($u)) {
    redirect(cw_home_path_for_role((string)($u['role'] ?? '')));
}

function mr_page_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

$bookKey = strtoupper(trim((string)($_GET['book'] ?? 'OM')));
if (!in_array($bookKey, array('OM', 'OMM'), true)) {
    $bookKey = 'OM';
}

$reader = new ControlledPublishingReaderService($pdo);
$version = $reader->resolveLatestReleasedVersion($bookKey);
$bookTitle = is_array($version) ? (string)($version['book_title'] ?? 'Manual') : 'Manual';
$versionLabel = is_array($version) ? (string)($version['version_label'] ?? '') : '';
$hasReleased = $version !== null;

$anchor = trim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_FRAGMENT) ?: ''));
if ($anchor === '' && isset($_GET['anchor'])) {
    $anchor = trim((string)$_GET['anchor']);
}

$cssVersion = @filemtime(__DIR__ . '/../assets/manual_reader.css') ?: time();
$bookCssVersion = @filemtime(__DIR__ . '/../assets/controlled_book_editor.css') ?: time();
$jsVersion = @filemtime(__DIR__ . '/../assets/manual_reader.js') ?: time();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= mr_page_h($bookTitle) ?> · Reader</title>
  <link rel="stylesheet" href="/assets/controlled_book_editor.css?v=<?= (int)$bookCssVersion ?>">
  <link rel="stylesheet" href="/assets/manual_reader.css?v=<?= (int)$cssVersion ?>">
</head>
<body class="mr-body" data-mr-theme="light">
  <div
    id="manualReader"
    class="mr-app"
    data-book="<?= mr_page_h($bookKey) ?>"
    data-anchor="<?= mr_page_h($anchor) ?>"
    data-has-released="<?= $hasReleased ? '1' : '0' ?>"
  >
    <header class="mr-topbar">
      <div class="mr-topbar-left">
        <button type="button" class="mr-icon-btn" id="mrBackBtn" aria-label="Back">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M15 18l-6-6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
        <a class="mr-icon-btn mr-library-btn" href="/student/manuals.php" aria-label="Library" title="Library">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M4 19V5M4 19h16M4 19l2-14h5l1 9h6l1-5h3v10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </a>
        <div class="mr-topbar-titles">
          <div class="mr-book-title"><?= mr_page_h($bookTitle) ?></div>
          <?php if ($versionLabel !== ''): ?>
            <div class="mr-version-badge">v<?= mr_page_h($versionLabel) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="mr-topbar-actions">
        <button type="button" class="mr-icon-btn" id="mrSearchToggle" aria-label="Search" title="Search">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/>
            <path d="M20 20l-3-3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </button>
        <button type="button" class="mr-icon-btn" id="mrTocToggle" aria-label="Contents" title="Contents">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M4 6h16M4 12h16M4 18h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </button>
        <button type="button" class="mr-icon-btn" id="mrSettingsToggle" aria-label="Reader settings" title="Settings">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
            <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </button>
      </div>
    </header>

    <div class="mr-panel mr-search-panel" id="mrSearchPanel" hidden>
      <div class="mr-panel-inner">
        <input type="search" id="mrSearchInput" class="mr-search-input" placeholder="Search section titles…" autocomplete="off">
        <div class="mr-search-results" id="mrSearchResults"></div>
      </div>
    </div>

    <aside class="mr-toc-drawer" id="mrTocDrawer" aria-label="Table of contents" hidden>
      <div class="mr-toc-head">
        <h2 class="mr-toc-title">Contents</h2>
        <button type="button" class="mr-icon-btn" id="mrTocClose" aria-label="Close contents">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </button>
      </div>
      <nav class="mr-toc-nav" id="mrTocNav"></nav>
    </aside>

    <div class="mr-panel mr-settings-panel" id="mrSettingsPanel" hidden>
      <div class="mr-panel-inner mr-settings-inner">
        <h2 class="mr-panel-title">Reader Settings</h2>
        <label class="mr-setting">
          <span>Font size</span>
          <select id="mrSettingFontSize">
            <option value="small">Small</option>
            <option value="normal" selected>Normal</option>
            <option value="large">Large</option>
          </select>
        </label>
        <label class="mr-setting">
          <span>Theme</span>
          <select id="mrSettingTheme">
            <option value="light" selected>Light</option>
            <option value="sepia">Sepia</option>
            <option value="dark">Dark</option>
          </select>
        </label>
        <label class="mr-setting">
          <span>Page width</span>
          <select id="mrSettingPageWidth">
            <option value="narrow">Narrow</option>
            <option value="normal" selected>Normal</option>
            <option value="wide">Wide</option>
          </select>
        </label>
      </div>
    </div>

    <main class="mr-stage" id="mrStage">
      <?php if (!$hasReleased): ?>
        <div class="mr-empty-state">
          <h1>No released manual available</h1>
          <p>This manual has not been released yet. Check back after publishing completes.</p>
          <a class="mr-empty-link" href="/student/manuals.php">Back to Library</a>
        </div>
      <?php else: ?>
        <div class="mr-page-viewport" id="mrPageViewport">
          <button type="button" class="mr-page-nav mr-page-nav--prev" id="mrPagePrev" aria-label="Previous page">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M15 18l-6-6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          </button>

          <div class="mr-page-frame" id="mrPageFrame">
            <div class="mr-page-meta" id="mrPageMeta"></div>
            <div class="mr-page-content" id="mrPageContent">
              <div class="mr-loading">Opening manual…</div>
            </div>
            <div class="mr-page-footer-bar" id="mrPageFooter"></div>
          </div>

          <button type="button" class="mr-page-nav mr-page-nav--next" id="mrPageNext" aria-label="Next page">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M9 18l6-6-6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          </button>
        </div>

        <div class="mr-measure-host" id="mrMeasureHost" aria-hidden="true"></div>
      <?php endif; ?>
    </main>

    <div class="mr-overlay" id="mrOverlay" hidden></div>
  </div>

  <script src="/assets/manual_reader.js?v=<?= (int)$jsVersion ?>"></script>
</body>
</html>
