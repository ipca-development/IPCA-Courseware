<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsUi.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsWorkflowService.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsAnnexBookService.php';

cw_require_admin();
$user = cw_current_user($pdo);
$actorId = (int)($user['id'] ?? 0);
$workflow = new BooksManualsWorkflowService($pdo);
$annexBooks = new BooksManualsAnnexBookService($pdo);
$tablesReady = $workflow->tablesPresent();
$structureReady = $annexBooks->tablesPresent();
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
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'ensure_annex_book') {
            $created = $annexBooks->ensureAnnexBookForParent(
                (int)($_POST['parent_book_id'] ?? 0),
                $actorId
            );
            $_SESSION['books_manuals_annex_flash'] = array(
                'type' => 'success',
                'message' => !empty($created['created'])
                    ? 'Annex Book created with Cover, Annex Register and annexes.'
                    : 'Opened the existing Annex Book for this manual.',
            );
            redirect('/admin/compliance/controlled_book_editor.php?version_id=' . (int)$created['version_id']);
        }
        if ($action === 'transition') {
            $to = $workflow->transition(
                (int)($_POST['version_id'] ?? 0),
                (string)($_POST['lifecycle_action'] ?? ''),
                $actorId,
                trim((string)($_POST['note'] ?? '')) ?: null
            );
            $_SESSION['books_manuals_annex_flash'] = array(
                'type' => 'success',
                'message' => $to === 'released'
                    ? 'Annex Book published. It is now visible in the iOS reader.'
                    : 'Annex Book unpublished. It is hidden from the iOS reader.',
            );
            redirect('/admin/books_manuals/annexes.php');
        }
        throw new RuntimeException('Unknown Annexes action.');
    } catch (Throwable $e) {
        $_SESSION['books_manuals_annex_flash'] = array(
            'type' => 'error',
            'message' => $e->getMessage(),
        );
        redirect('/admin/books_manuals/annexes.php');
    }
}

$rows = ($tablesReady && $structureReady) ? $workflow->listLibrary(true) : array();
$parents = $structureReady ? $annexBooks->listParentManualsWithoutAnnexBook() : array();
$flash = $_SESSION['books_manuals_annex_flash'] ?? null;
unset($_SESSION['books_manuals_annex_flash']);

cw_header('Books & Manuals · Annexes');
books_manuals_page_open(array(
    'title' => 'Annexes',
    'description' => 'One Annex Book per manual: Cover (identical to the parent), automatic Annex Register, then the annexes.',
    'back' => array('href' => '/admin/books_manuals/index.php', 'label' => 'IPCA Library'),
    'flash' => is_array($flash) ? $flash : null,
    'stats' => array(
        array('label' => 'Annex Books', 'value' => count($rows)),
    ),
));
?>

<?php if (!$tablesReady || !$structureReady): ?>
  <section class="cmp-card">
    <div class="cmp-alert cmp-alert--error">
      Install the Books &amp; Manuals workflow and Annex Book structure migrations to activate annexes.
      <div style="margin-top:8px;"><code>php scripts/apply_annex_book_structure.php</code></div>
    </div>
  </section>
<?php else: ?>
  <?php if ($parents !== array()): ?>
  <details class="cmp-card" style="margin-bottom:16px;" open>
    <summary style="cursor:pointer;font-weight:800;color:#183859;">Create Annex Book for a manual</summary>
    <form method="post" class="bm-form-grid" style="margin-top:18px;">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="ensure_annex_book">
      <label class="bm-form-field">
        <span>Parent manual</span>
        <select name="parent_book_id" required>
          <option value="">Select a manual…</option>
          <?php foreach ($parents as $manual): ?>
            <option value="<?= (int)$manual['book_id'] ?>">
              <?= h((string)$manual['book_key']) ?> — <?= h((string)$manual['book_title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="bm-actions" style="align-self:end;">
        <button class="app-btn app-btn--primary" type="submit">Create Annex Book</button>
      </div>
    </form>
    <p style="margin:12px 0 0;font-size:13px;color:#64748b;">
      The Annex Book cover is copied from the selected manual. Add and edit annexes in the existing editor.
      Landscape and multi-page annexes keep the current pagination.
    </p>
  </details>
  <?php endif; ?>

  <?php if ($rows === array()): ?>
  <section class="cmp-card bm-empty">
    <h2>No Annex Books yet</h2>
    <p>Create one Annex Book per manual (OM, OMM, TM_GEN, …). Import existing embedded annexes with:</p>
    <p><code>php scripts/migrate_books_manuals_annexes.php --dry-run --output=tmp/books-manuals-annexes.json</code></p>
  </section>
  <?php else: ?>
  <section class="bm-library-grid">
    <?php foreach ($rows as $row): ?>
      <article class="cmp-card bm-book-card">
        <div class="bm-cover" aria-hidden="true">
          <div class="bm-cover__code"><?= h((string)$row['book_key']) ?></div>
          <div class="bm-cover__type">Annex Book</div>
        </div>
        <div class="bm-book-card__body">
          <?= books_manuals_phase_pill((string)$row['phase_label'], (string)$row['phase_tone']) ?>
          <h2 class="bm-book-card__title"><?= h((string)$row['book_title']) ?></h2>
          <dl class="bm-meta">
            <dt>Book code</dt><dd><?= h((string)$row['book_key']) ?></dd>
            <dt>Revision</dt><dd><?= h((string)$row['version_label']) ?></dd>
            <dt>Updated by</dt><dd><?= h((string)($row['updated_by_name'] ?? 'Not recorded')) ?></dd>
          </dl>
          <div class="bm-actions">
            <?php if ((int)($row['version_id'] ?? 0) > 0): ?>
              <a class="app-btn app-btn--primary" href="/admin/compliance/controlled_book_editor.php?version_id=<?= (int)$row['version_id'] ?>">Edit</a>
              <a class="app-btn app-btn--secondary" href="/admin/compliance/controlled_book_annexes.php?version_id=<?= (int)$row['version_id'] ?>">Manage annexes</a>
              <a class="app-btn app-btn--secondary" href="/admin/books_manuals/manual.php?version_id=<?= (int)$row['version_id'] ?>">Settings</a>
              <?php foreach ((array)($row['actions'] ?? array()) as $lifecycleAction): ?>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="transition">
                  <input type="hidden" name="version_id" value="<?= (int)$row['version_id'] ?>">
                  <input type="hidden" name="lifecycle_action" value="<?= h((string)$lifecycleAction['action']) ?>">
                  <button class="app-btn app-btn--<?= ($lifecycleAction['tone'] ?? '') === 'primary' ? 'primary' : 'secondary' ?>" type="submit">
                    <?= h((string)$lifecycleAction['label']) ?>
                  </button>
                </form>
              <?php endforeach; ?>
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
