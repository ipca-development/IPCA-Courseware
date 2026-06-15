<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/publishing/ControlledPublishingReaderService.php';
require_once __DIR__ . '/../../src/publishing/ControlledPublishingReaderAccessService.php';

cw_require_login();

$u = cw_current_user($pdo);
$access = new ControlledPublishingReaderAccessService();
if (!$access->canReadManuals($u)) {
    redirect(cw_home_path_for_role((string)($u['role'] ?? '')));
}

function manuals_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function manuals_fmt_date(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    try {
        $dt = new DateTime($value);

        return $dt->format('M j, Y');
    } catch (Throwable $e) {
        return $value;
    }
}

$userId = (int)($u['id'] ?? 0);
$reader = new ControlledPublishingReaderService($pdo);
$library = $reader->listActiveReleasedLibrary($userId);

$shelfCssVersion = @filemtime(__DIR__ . '/../assets/manual_shelf.css') ?: time();

cw_header('Manuals');
?>
<link rel="stylesheet" href="/assets/manual_shelf.css?v=<?= (int)$shelfCssVersion ?>">

<div class="ms-page">
  <header class="ms-hero">
    <p class="ms-kicker">Library</p>
    <h1 class="ms-title">Manuals</h1>
    <p class="ms-subtitle">Official operations and management manuals — released editions only.</p>
  </header>

  <?php if ($library === array()): ?>
    <div class="ms-empty">
      <div class="ms-empty-icon" aria-hidden="true">📚</div>
      <h2 class="ms-empty-title">No released manuals available</h2>
      <p class="ms-empty-text">
        When a manual version is released through controlled publishing, it will appear here for reading.
      </p>
    </div>
  <?php else: ?>
    <div class="ms-grid">
      <?php foreach ($library as $book): ?>
        <?php
          $bookKeyRaw = (string)($book['book_key'] ?? '');
          $bookKey = manuals_h($bookKeyRaw);
          $fallback = is_array($book['cover_fallback'] ?? null) ? $book['cover_fallback'] : array();
          $displayTitle = manuals_h((string)($fallback['book_title'] ?? $book['book_title'] ?? ''));
          $manualCode = manuals_h((string)($fallback['manual_code'] ?? $book['manual_code'] ?? $bookKeyRaw));
          $versionLabel = manuals_h((string)($book['version_label'] ?? ''));
          $coverUrl = trim((string)($book['cover_url'] ?? ''));
          $releasedAt = manuals_fmt_date($book['released_at'] ?? null);
          $effectiveDate = manuals_fmt_date($book['effective_date'] ?? null);
          $hasProgress = !empty($book['has_progress']);
          $continueAnchor = trim((string)($book['continue_stable_anchor'] ?? ''));
          $readerUrl = '/student/manual_reader.php?book=' . urlencode($bookKeyRaw);
          $continueUrl = $readerUrl . ($continueAnchor !== '' ? '#' . urlencode($continueAnchor) : '');
        ?>
        <article class="ms-card">
          <div class="ms-cover-wrap">
            <?php if ($coverUrl !== ''): ?>
              <img
                class="ms-cover-img"
                src="<?= manuals_h($coverUrl) ?>"
                alt="<?= $displayTitle ?> cover"
                loading="lazy"
              >
            <?php else: ?>
              <div class="ms-cover-fallback" aria-hidden="true">
                <div class="ms-cover-fallback-top">
                  <span class="ms-cover-code"><?= $manualCode ?></span>
                </div>
                <div class="ms-cover-fallback-body">
                  <h2 class="ms-cover-fallback-title"><?= $displayTitle ?></h2>
                  <?php if ($versionLabel !== ''): ?>
                    <span class="ms-cover-fallback-rev">Rev <?= $versionLabel ?></span>
                  <?php endif; ?>
                  <?php if ($releasedAt !== ''): ?>
                    <span class="ms-cover-fallback-date"><?= manuals_h($releasedAt) ?></span>
                  <?php elseif ($effectiveDate !== ''): ?>
                    <span class="ms-cover-fallback-date"><?= manuals_h($effectiveDate) ?></span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endif; ?>
          </div>

          <div class="ms-card-body">
            <h3 class="ms-card-title"><?= $displayTitle ?></h3>
            <div class="ms-card-meta">
              <span class="ms-badge"><?= $manualCode ?> · v<?= $versionLabel ?></span>
              <?php if ($releasedAt !== ''): ?>
                <span class="ms-meta-item">Released <?= manuals_h($releasedAt) ?></span>
              <?php endif; ?>
            </div>
            <div class="ms-card-actions">
              <a class="ms-btn ms-btn-primary" href="<?= manuals_h($readerUrl) ?>">Open Manual</a>
              <?php if ($hasProgress): ?>
                <a class="ms-btn ms-btn-secondary" href="<?= manuals_h($continueUrl) ?>">Continue Reading</a>
              <?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php cw_footer(); ?>
