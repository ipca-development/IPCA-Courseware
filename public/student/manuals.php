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
        return '—';
    }

    try {
        $dt = new DateTime($value);

        return $dt->format('M j, Y');
    } catch (Throwable $e) {
        return $value;
    }
}

$reader = new ControlledPublishingReaderService($pdo);
$library = $reader->listActiveReleasedLibrary();

cw_header('Manuals');
?>
<div class="page manuals-shelf-page">
  <div class="page-head">
    <div>
      <h1 class="page-title">Manuals</h1>
      <p class="page-subtitle">Official IPCA operations and management manuals — released editions only.</p>
    </div>
  </div>

  <?php if ($library === array()): ?>
    <div class="card manuals-empty-card">
      <h2 class="manuals-empty-title">No released manuals available</h2>
      <p class="manuals-empty-text">
        When a manual version is released through controlled publishing, it will appear here for reading.
      </p>
    </div>
  <?php else: ?>
    <div class="manuals-shelf-grid">
      <?php foreach ($library as $book): ?>
        <?php
          $bookKey = manuals_h((string)($book['book_key'] ?? ''));
          $bookTitle = manuals_h((string)($book['book_title'] ?? ''));
          $versionLabel = manuals_h((string)($book['version_label'] ?? ''));
          $effectiveDate = manuals_fmt_date($book['effective_date'] ?? null);
          $releasedAt = manuals_fmt_date($book['released_at'] ?? null);
        ?>
        <article class="card manual-shelf-card">
          <div class="manual-shelf-cover" aria-hidden="true">
            <span class="manual-shelf-cover-key"><?= $bookKey ?></span>
          </div>
          <div class="manual-shelf-body">
            <h2 class="manual-shelf-title"><?= $bookTitle ?></h2>
            <div class="manual-shelf-meta">
              <span class="manual-shelf-badge">v<?= $versionLabel ?></span>
              <span class="manual-shelf-meta-item">Effective <?= $effectiveDate ?></span>
              <?php if (($book['released_at'] ?? '') !== ''): ?>
                <span class="manual-shelf-meta-item">Released <?= $releasedAt ?></span>
              <?php endif; ?>
            </div>
            <div class="manual-shelf-actions">
              <a class="mini-action primary" href="/student/manual_reader.php?book=<?= urlencode((string)($book['book_key'] ?? '')) ?>">
                Open Reader
              </a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<style>
.manuals-shelf-page .page-subtitle {
  margin-top: 6px;
  color: var(--text-muted, #64748b);
  max-width: 52ch;
}

.manuals-shelf-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 18px;
  margin-top: 20px;
}

.manual-shelf-card {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  padding: 0;
}

.manual-shelf-cover {
  background: linear-gradient(145deg, #0f2744 0%, #1e3a5f 55%, #334155 100%);
  color: #fff;
  min-height: 120px;
  display: flex;
  align-items: flex-end;
  padding: 18px 20px;
}

.manual-shelf-cover-key {
  font-size: 1.75rem;
  font-weight: 700;
  letter-spacing: 0.06em;
}

.manual-shelf-body {
  padding: 18px 20px 20px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  flex: 1;
}

.manual-shelf-title {
  margin: 0;
  font-size: 1.05rem;
  line-height: 1.35;
}

.manual-shelf-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 12px;
  align-items: center;
  color: var(--text-muted, #64748b);
  font-size: 0.875rem;
}

.manual-shelf-badge {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 999px;
  background: rgba(15, 39, 68, 0.08);
  color: #0f2744;
  font-weight: 600;
  font-size: 0.8rem;
}

.manual-shelf-actions {
  margin-top: auto;
  padding-top: 4px;
}

.manuals-empty-card {
  margin-top: 20px;
  padding: 28px 24px;
}

.manuals-empty-title {
  margin: 0 0 8px;
  font-size: 1.1rem;
}

.manuals-empty-text {
  margin: 0;
  color: var(--text-muted, #64748b);
}
</style>

<?php cw_footer(); ?>
