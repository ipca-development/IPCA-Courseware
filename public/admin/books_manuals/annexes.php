<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsUi.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsWorkflowService.php';

cw_require_admin();
$user = cw_current_user($pdo);
$actorId = (int)($user['id'] ?? 0);
$workflow = new BooksManualsWorkflowService($pdo);
$tablesReady = $workflow->tablesPresent();
$foundation = new ControlledPublishingFoundationService($pdo);
$csrf = (string)($_SESSION['books_manuals_csrf'] ?? '');
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(24));
    $_SESSION['books_manuals_csrf'] = $csrf;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('The form expired. Reload and try again.');
        }
        if ((string)($_POST['action'] ?? '') !== 'create_annex') {
            throw new RuntimeException('Unknown Annexes action.');
        }
        $created = $workflow->createStandaloneAnnex(
            (int)($_POST['parent_book_id'] ?? 0),
            (int)($_POST['source_version_id'] ?? 0),
            (string)($_POST['annex_key'] ?? ''),
            (string)($_POST['book_key'] ?? ''),
            (string)($_POST['title'] ?? ''),
            (string)($_POST['version_label'] ?? ''),
            (string)($_POST['revision_date'] ?? ''),
            $actorId
        );
        $_SESSION['books_manuals_annex_flash'] = array(
            'type' => 'success',
            'message' => 'Standalone annex created in Draft.',
        );
        redirect('/admin/books_manuals/manual.php?version_id=' . (int)$created['version_id']);
    } catch (Throwable $e) {
        $_SESSION['books_manuals_annex_flash'] = array(
            'type' => 'error',
            'message' => $e->getMessage(),
        );
        redirect('/admin/books_manuals/annexes.php');
    }
}

$rows = $tablesReady ? $workflow->listLibrary(true) : array();
$manuals = $tablesReady ? $workflow->listLibrary(false) : array();
$templates = $foundation->listBooksWithVersions();
$flash = $_SESSION['books_manuals_annex_flash'] ?? null;
unset($_SESSION['books_manuals_annex_flash']);

cw_header('Books & Manuals · Annexes');
books_manuals_page_open(array(
    'title' => 'Annexes',
    'description' => 'Standalone controlled annex books with independent revisions, dates and approvals.',
    'back' => array('href' => '/admin/books_manuals/index.php', 'label' => 'IPCA Library'),
    'flash' => is_array($flash) ? $flash : null,
    'stats' => array(
        array('label' => 'Standalone annexes', 'value' => count($rows)),
    ),
));
?>

<?php if (!$tablesReady): ?>
  <section class="cmp-card">
    <div class="cmp-alert cmp-alert--error">Install the Books & Manuals workflow migration to activate annexes.</div>
  </section>
<?php else: ?>
  <details class="cmp-card" style="margin-bottom:16px;">
    <summary style="cursor:pointer;font-weight:800;color:#183859;">Create a standalone annex</summary>
    <form method="post" class="bm-form-grid" style="margin-top:18px;">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="create_annex">
      <label class="bm-form-field">
        <span>Parent manual</span>
        <select name="parent_book_id" required>
          <option value="">Select a manual…</option>
          <?php foreach ($manuals as $manual): ?>
            <option value="<?= (int)$manual['book_id'] ?>"><?= h((string)$manual['book_key']) ?> — <?= h((string)$manual['book_title']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="bm-form-field">
        <span>Annex identifier</span>
        <input name="annex_key" required maxlength="64" placeholder="A">
      </label>
      <label class="bm-form-field">
        <span>Book code</span>
        <input name="book_key" required maxlength="32" placeholder="ANNEX_OM_A">
      </label>
      <label class="bm-form-field">
        <span>Title</span>
        <input name="title" required maxlength="255" placeholder="Flight Training Forms">
      </label>
      <label class="bm-form-field">
        <span>Revision</span>
        <input name="version_label" required maxlength="128" value="1.0">
      </label>
      <label class="bm-form-field">
        <span>Revision date</span>
        <input type="date" name="revision_date" value="<?= h(date('Y-m-d')) ?>">
      </label>
      <label class="bm-form-field">
        <span>Standard Part 0 and style based on</span>
        <select name="source_version_id" required>
          <option value="">Select a template…</option>
          <?php foreach ($templates as $template): ?>
            <?php if ((int)($template['version_id'] ?? 0) > 0): ?>
              <option value="<?= (int)$template['version_id'] ?>"><?= h((string)$template['book_key']) ?> <?= h((string)$template['version_label']) ?></option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="bm-actions" style="align-self:end;">
        <button class="app-btn app-btn--primary" type="submit">Create Annex</button>
      </div>
    </form>
  </details>
  <?php if ($rows === array()): ?>
  <section class="cmp-card bm-empty">
    <h2>No standalone annexes yet</h2>
    <p>Run the annex inventory dry-run first. Existing embedded annexes remain untouched until a reviewed migration is explicitly approved.</p>
    <p><code>php scripts/migrate_books_manuals_annexes.php --dry-run --output=tmp/books-manuals-annexes.json</code></p>
  </section>
  <?php else: ?>
  <section class="bm-library-grid">
    <?php foreach ($rows as $row): ?>
      <article class="cmp-card bm-book-card">
        <div class="bm-cover" aria-hidden="true">
          <div class="bm-cover__code"><?= h((string)$row['book_key']) ?></div>
          <div class="bm-cover__type">Standalone Annex</div>
        </div>
        <div class="bm-book-card__body">
          <?= books_manuals_phase_pill((string)$row['phase_label'], (string)$row['phase_tone']) ?>
          <h2 class="bm-book-card__title"><?= h((string)$row['book_title']) ?></h2>
          <dl class="bm-meta">
            <dt>Revision</dt><dd><?= h((string)$row['version_label']) ?></dd>
            <dt>Revision date</dt><dd><?= h((string)($row['effective_date'] ?? 'Not set')) ?></dd>
            <dt>Updated by</dt><dd><?= h((string)($row['updated_by_name'] ?? 'Not recorded')) ?></dd>
            <dt>Update</dt><dd><?= h((string)$row['update_code']) ?></dd>
            <dt>Migration</dt><dd><?= h(strtoupper((string)$row['migration_status'])) ?></dd>
          </dl>
          <div class="bm-actions">
            <a class="app-btn app-btn--primary" href="/admin/books_manuals/manual.php?version_id=<?= (int)$row['version_id'] ?>">Open settings</a>
            <?php if (in_array((string)$row['lifecycle_status'], array('draft', 'in_review'), true)): ?>
              <a class="app-btn app-btn--secondary" href="/admin/compliance/controlled_book_editor.php?version_id=<?= (int)$row['version_id'] ?>">Edit</a>
            <?php endif; ?>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
<?php endif; ?>

<?php
compliance_page_close();
cw_footer();
