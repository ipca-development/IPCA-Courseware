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
$foundation = new ControlledPublishingFoundationService($pdo);
$tablesReady = $workflow->tablesPresent();

$csrf = (string)($_SESSION['books_manuals_csrf'] ?? '');
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(24));
    $_SESSION['books_manuals_csrf'] = $csrf;
}

function bm_library_flash(string $type, string $message): void
{
    $_SESSION['books_manuals_flash'] = array('type' => $type, 'message' => $message);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('The form expired. Reload and try again.');
        }
        if (!$tablesReady) {
            throw new RuntimeException('Install the Books & Manuals workflow migration before creating manuals.');
        }
        if ((string)($_POST['action'] ?? '') !== 'create_manual') {
            throw new RuntimeException('Unknown Books & Manuals action.');
        }
        $created = $workflow->createBlankManual(
            (int)($_POST['source_version_id'] ?? 0),
            (string)($_POST['book_key'] ?? ''),
            (string)($_POST['title'] ?? ''),
            (string)($_POST['version_label'] ?? ''),
            (string)($_POST['manual_type'] ?? ''),
            (string)($_POST['approval_route'] ?? ''),
            isset($_POST['authority_code']) ? (string)$_POST['authority_code'] : null,
            $actorId
        );
        bm_library_flash('success', 'Manual created with the standard Part 0 structure.');
        redirect('/admin/books_manuals/manual.php?version_id=' . (int)$created['version_id']);
    } catch (Throwable $e) {
        bm_library_flash('error', $e->getMessage());
        redirect('/admin/books_manuals/index.php');
    }
}

$flash = $_SESSION['books_manuals_flash'] ?? null;
unset($_SESSION['books_manuals_flash']);
$rows = $tablesReady ? $workflow->listLibrary(false) : array();
$templates = $foundation->listBooksWithVersions();
$approved = 0;
$inReview = 0;
foreach ($rows as $row) {
    $status = (string)($row['lifecycle_status'] ?? '');
    $approved += $status === 'released' ? 1 : 0;
    $inReview += in_array($status, array('in_review', 'approved'), true) ? 1 : 0;
}

cw_header('Books & Manuals · IPCA Library');
books_manuals_page_open(array(
    'title' => 'IPCA Library',
    'description' => 'Create, govern and publish every IPCA book from one controlled lifecycle.',
    'flash' => is_array($flash) ? $flash : null,
    'stats' => array(
        array('label' => 'Manuals', 'value' => count($rows)),
        array('label' => 'In review', 'value' => $inReview),
        array('label' => 'Approved', 'value' => $approved, 'tone' => 'ok'),
    ),
    'actions' => array(
        array('label' => 'Annexes', 'href' => '/admin/books_manuals/annexes.php'),
    ),
));
?>

<?php if (!$tablesReady): ?>
  <section class="cmp-card" style="margin-bottom:16px;">
    <div class="cmp-alert cmp-alert--error">
      Install <code>scripts/sql/2026_08_18_books_manuals_workflow.sql</code> to activate the module.
      Existing Controlled Publishing routes remain available and unchanged.
    </div>
  </section>
<?php else: ?>
  <details class="cmp-card" style="margin-bottom:16px;">
    <summary style="cursor:pointer;font-weight:800;color:#183859;">Create a new manual or book</summary>
    <form method="post" class="bm-form-grid" style="margin-top:18px;">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="create_manual">
      <label class="bm-form-field">
        <span>Manual code</span>
        <input name="book_key" required maxlength="32" pattern="[A-Za-z0-9][A-Za-z0-9_-]{1,31}" placeholder="SMS">
      </label>
      <label class="bm-form-field">
        <span>Title</span>
        <input name="title" required maxlength="255" placeholder="Safety Management Manual">
      </label>
      <label class="bm-form-field">
        <span>Manual type</span>
        <select name="manual_type" required>
          <option value="operations">Operations Manual</option>
          <option value="training">Training Manual</option>
          <option value="sop">Standard Operating Procedures</option>
          <option value="course_book">Course Book</option>
        </select>
      </label>
      <label class="bm-form-field">
        <span>Original / version number</span>
        <input name="version_label" required maxlength="128" value="1.0">
      </label>
      <label class="bm-form-field">
        <span>Approval route</span>
        <select name="approval_route" required>
          <option value="internal">Internal approval</option>
          <option value="authority">Authority approval</option>
        </select>
      </label>
      <label class="bm-form-field">
        <span>Authority code, if applicable</span>
        <input name="authority_code" maxlength="64" placeholder="CAA / FAA / EASA">
      </label>
      <label class="bm-form-field">
        <span>Standard structure and Part 0 based on</span>
        <select name="source_version_id" required>
          <option value="">Select a template…</option>
          <?php foreach ($templates as $template): ?>
            <?php if ((int)($template['version_id'] ?? 0) > 0): ?>
              <option value="<?= (int)$template['version_id'] ?>">
                <?= h((string)$template['book_key']) ?> <?= h((string)$template['version_label']) ?>
              </option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="bm-actions" style="align-self:end;">
        <button class="app-btn app-btn--primary" type="submit">Create in Draft</button>
      </div>
    </form>
  </details>
<?php endif; ?>

<section class="bm-library-grid" aria-label="IPCA manuals">
  <?php foreach ($rows as $row): ?>
    <?php
      $versionId = (int)($row['version_id'] ?? 0);
      $coverage = max(0.0, min(100.0, (float)($row['coverage_percent'] ?? 0)));
    ?>
    <article class="cmp-card bm-book-card">
      <div class="bm-cover" aria-hidden="true">
        <div class="bm-cover__code"><?= h((string)$row['book_key']) ?></div>
        <div class="bm-cover__type"><?= h((string)($row['manual_type'] ?? 'Manual')) ?></div>
      </div>
      <div class="bm-book-card__body">
        <?= books_manuals_phase_pill((string)$row['phase_label'], (string)$row['phase_tone']) ?>
        <h2 class="bm-book-card__title"><?= h((string)$row['book_title']) ?></h2>
        <dl class="bm-meta">
          <dt>Revision</dt><dd><?= h((string)($row['version_label'] ?? '—')) ?></dd>
          <dt>Update</dt><dd><?= h(books_manuals_update_label($row['update_code'] ?? null)) ?></dd>
          <dt>Approval</dt><dd><?= h(books_manuals_approval_label((string)($row['approval_route'] ?? 'internal'), $row['authority_code'] ?? null)) ?></dd>
          <dt>Audience</dt><dd><?= (int)$row['audience_count'] ?> assignment<?= (int)$row['audience_count'] === 1 ? '' : 's' ?></dd>
          <dt>Audit</dt><dd><?= h(strtoupper((string)($row['audit_status'] ?? 'not run'))) ?></dd>
        </dl>
        <div class="bm-progress" title="MCCF coverage <?= h(number_format($coverage, 1)) ?>%">
          <span style="width:<?= h(number_format($coverage, 2, '.', '')) ?>%"></span>
        </div>
        <div class="bm-actions" style="margin-top:14px;">
          <a class="app-btn app-btn--primary" href="/admin/books_manuals/manual.php?version_id=<?= $versionId ?>">Open settings</a>
          <?php if (in_array((string)$row['lifecycle_status'], array('draft', 'in_review'), true)): ?>
            <a class="app-btn app-btn--secondary" href="/admin/compliance/controlled_book_editor.php?version_id=<?= $versionId ?>">Edit</a>
          <?php endif; ?>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
  <?php if ($tablesReady && $rows === array()): ?>
    <div class="cmp-card bm-empty">No manuals are registered yet.</div>
  <?php endif; ?>
</section>

<?php
compliance_page_close();
cw_footer();
