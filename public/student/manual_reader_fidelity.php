<?php
declare(strict_types=1);

/**
 * Fidelity test — one editor-rendered .cpb-sheet inside reader chrome (no pagination).
 * Compare visually against the controlled book editor preview for the same section.
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/publishing/ControlledPublishingReaderService.php';
require_once __DIR__ . '/../../src/publishing/ControlledPublishingReaderAccessService.php';

cw_require_login();

$u = cw_current_user($pdo);
$access = new ControlledPublishingReaderAccessService();
if (!$access->canReadManuals($u)) {
    redirect(cw_home_path_for_role((string)($u['role'] ?? '')));
}

function mrf_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

$bookKey = strtoupper(trim((string)($_GET['book'] ?? 'OM')));
if (!in_array($bookKey, array('OM', 'OMM'), true)) {
    $bookKey = 'OM';
}

$reader = new ControlledPublishingReaderService($pdo);
$version = $reader->resolveLatestReleasedVersion($bookKey);
$error = null;
$sectionHtml = '';
$sectionTitle = '';
$sectionId = (int)($_GET['section_id'] ?? 0);
$sections = array();

if ($version !== null) {
    $versionId = (int)$version['id'];
    foreach ($reader->paginationSections()->listFlatSections($versionId) as $row) {
        $sections[] = $row;
    }
    if ($sectionId <= 0 && $sections !== array()) {
        $sectionId = (int)$sections[0]['id'];
    }
    if ($sectionId > 0) {
        try {
            $payload = $reader->loadSection($bookKey, $sectionId, null);
            if ($payload !== null) {
                $sectionHtml = (string)($payload['html'] ?? '');
                $sectionTitle = (string)($payload['section_title'] ?? '');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$bookCssVersion = @filemtime(__DIR__ . '/../assets/controlled_book_editor.css') ?: time();
$cssVersion = @filemtime(__DIR__ . '/../assets/manual_reader.css') ?: time();
$editorUrl = '/admin/compliance/controlled_books.php?book=' . urlencode($bookKey);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Fidelity · <?= mrf_h($bookKey) ?></title>
  <link rel="stylesheet" href="/assets/controlled_book_editor.css?v=<?= (int)$bookCssVersion ?>">
  <link rel="stylesheet" href="/assets/manual_reader.css?v=<?= (int)$cssVersion ?>">
  <style>
    .mrf-toolbar {
      flex-shrink: 0;
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      align-items: center;
      padding: 10px 16px;
      background: #fff;
      border-bottom: 1px solid rgba(0,0,0,0.08);
      font-family: -apple-system, BlinkMacSystemFont, sans-serif;
      font-size: 0.85rem;
    }
    .mrf-toolbar label { display: flex; align-items: center; gap: 8px; }
    .mrf-toolbar select { min-width: 280px; }
    .mrf-note { color: #64748b; margin: 0; }
    .mrf-stage { flex: 1; overflow: auto; padding: 24px 16px; background: var(--mr-bg); }
    .mrf-scale { transform-origin: top center; margin: 0 auto; width: fit-content; }
  </style>
</head>
<body class="mr-body" data-mr-theme="light">
  <div class="mr-app" style="height:100dvh;">
    <div class="mrf-toolbar">
      <strong>Reader fidelity test</strong>
      <form method="get">
        <input type="hidden" name="book" value="<?= mrf_h($bookKey) ?>">
        <label>
          Section
          <select name="section_id" onchange="this.form.submit()">
            <?php foreach ($sections as $row): ?>
              <option value="<?= (int)$row['id'] ?>"<?= (int)$row['id'] === $sectionId ? ' selected' : '' ?>>
                <?= mrf_h((string)($row['section_key'] ?? '') . ' — ' . (string)($row['title'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </form>
      <a href="<?= mrf_h($editorUrl) ?>" target="_blank" rel="noopener">Open editor</a>
      <a href="/student/manual_reader.php?book=<?= urlencode($bookKey) ?>">Full reader</a>
      <p class="mrf-note">Canonical MODE_READ HTML — no pagination, no reader content overrides.</p>
    </div>
    <main class="mrf-stage">
      <?php if ($error !== null): ?>
        <p class="mr-error"><?= mrf_h($error) ?></p>
      <?php elseif ($sectionHtml === ''): ?>
        <p class="mr-loading">No section selected.</p>
      <?php else: ?>
        <div class="mrf-scale mr-page-scale">
          <?= $sectionHtml ?>
        </div>
      <?php endif; ?>
    </main>
  </div>
</body>
</html>
