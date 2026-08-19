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
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create_manual') {
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
            redirect('/admin/books_manuals/index.php?open=' . (int)$created['version_id']);
        }
        if ($action === 'create_revision') {
            $created = $workflow->createRevision(
                (int)($_POST['version_id'] ?? 0),
                $actorId
            );
            bm_library_flash(
                'success',
                'Revision ' . (string)$created['version_label'] . ' created as a new Draft.'
            );
            redirect('/admin/books_manuals/index.php?open=' . (int)$created['version_id']);
        }
        if ($action === 'create_revision_override') {
            $created = $workflow->createRevisionWithBulkOverride(
                (int)($_POST['version_id'] ?? 0),
                $actorId,
                (string)($_POST['override_rationale'] ?? '')
            );
            bm_library_flash(
                'success',
                'All legacy approval blockers were overridden and Revision '
                    . (string)$created['version_label'] . ' was created as a new Draft.'
            );
            redirect('/admin/books_manuals/index.php?open=' . (int)$created['version_id']);
        }
        if ($action === 'lifecycle_transition') {
            $versionId = (int)($_POST['version_id'] ?? 0);
            $workflow->transition(
                $versionId,
                (string)($_POST['lifecycle_action'] ?? ''),
                $actorId
            );
            bm_library_flash('success', 'Manual lifecycle stage updated.');
            redirect('/admin/books_manuals/index.php?open=' . $versionId);
        }
        if ($action === 'save_manual_settings') {
            $versionId = (int)($_POST['version_id'] ?? 0);
            $detail = $workflow->getVersionDetail($versionId);
            if ($detail === null) {
                throw new RuntimeException('Manual version not found.');
            }
            $workflow->saveProfile(
                (int)$detail['book_id'],
                (string)($_POST['manual_type'] ?? ''),
                (string)($_POST['approval_route'] ?? ''),
                isset($_POST['authority_code']) ? (string)$_POST['authority_code'] : null,
                $actorId,
                (string)($_POST['approved_reader_policy'] ?? 'all_readers')
            );
            $reviewerIds = is_array($_POST['reviewer_user_ids'] ?? null)
                ? array_map('intval', $_POST['reviewer_user_ids'])
                : array();
            $workflow->replaceBookReviewers(
                (int)$detail['book_id'],
                $reviewerIds,
                $actorId
            );
            bm_library_flash('success', 'Manual settings and reviewer policy saved.');
            redirect('/admin/books_manuals/index.php?open=' . $versionId);
        }
        throw new RuntimeException('Unknown Books & Manuals action.');
    } catch (Throwable $e) {
        bm_library_flash('error', $e->getMessage());
        redirect('/admin/books_manuals/index.php');
    }
}

$flash = $_SESSION['books_manuals_flash'] ?? null;
unset($_SESSION['books_manuals_flash']);
$rows = $tablesReady ? $workflow->listLibrary(false) : array();
$templates = $foundation->listBooksWithVersions();
$availableReviewers = $tablesReady ? $workflow->availableReviewers() : array();
$approved = 0;
$inReview = 0;
foreach ($rows as &$row) {
    $row['reviewers'] = $workflow->bookReviewers((int)$row['book_id']);
    $row['previous_versions'] = $workflow->listPreviousVersions(
        (int)$row['book_id'],
        (int)$row['version_id']
    );
    $status = (string)($row['lifecycle_status'] ?? '');
    $inReview += in_array($status, array('in_review', 'approved'), true) ? 1 : 0;
    $hasApprovedVersion = $status === 'released';
    foreach ($row['previous_versions'] as $previousVersion) {
        if ((string)($previousVersion['lifecycle_status'] ?? '') === 'released') {
            $hasApprovedVersion = true;
            break;
        }
    }
    $approved += $hasApprovedVersion ? 1 : 0;
}
unset($row);

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
        array('label' => 'Manual Change Wizzard', 'href' => '/admin/books_manuals/change_architect.php'),
        array('label' => 'Annexes', 'href' => '/admin/books_manuals/annexes.php'),
        array('label' => '+', 'modal' => 'bm-create-manual'),
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
  <?php compliance_modal_open('bm-create-manual', 'Create New Manual / Book'); ?>
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
  <?php compliance_modal_close(); ?>
<?php endif; ?>

<section class="bm-library-grid" aria-label="IPCA manuals">
  <?php foreach ($rows as $row): ?>
    <?php
      $versionId = (int)($row['version_id'] ?? 0);
      $bookId = (int)($row['book_id'] ?? 0);
      $coverage = max(0.0, min(100.0, (float)($row['coverage_percent'] ?? 0)));
      $stageKeys = array('draft', 'in_review', 'approved', 'released');
      $stageIndex = array_search((string)$row['lifecycle_status'], $stageKeys, true);
      $stageIndex = $stageIndex === false ? 0 : (int)$stageIndex;
      $reviewers = is_array($row['reviewers'] ?? null) ? $row['reviewers'] : array();
      $previousVersions = is_array($row['previous_versions'] ?? null)
          ? $row['previous_versions']
          : array();
      $previewUrl = '/admin/books_manuals/reader.php?version_id=' . $versionId;
      $coverUrl = '/student/api/manual_reader_cover_thumbnail.php?book='
          . rawurlencode((string)$row['book_key']) . '&version_id=' . $versionId . '&admin_preview=1';
      $settingsModalId = 'bm-manual-settings-' . $versionId;
      $overrideModalId = 'bm-compliance-override-' . $versionId;
      $versionsModalId = 'bm-previous-versions-' . $versionId;
    ?>
    <article class="cmp-card bm-book-card" data-bm-reader-url="<?= h($previewUrl) ?>">
      <a class="bm-cover bm-cover--thumbnail" href="<?= h($previewUrl) ?>" data-bm-reader-open aria-label="Open <?= h((string)$row['book_title']) ?> page viewer">
        <img src="<?= h($coverUrl) ?>" alt="<?= h((string)$row['book_title']) ?> front page">
        <span class="bm-cover__fallback"><?= h((string)$row['book_key']) ?></span>
      </a>
      <div class="bm-book-card__body">
        <?= books_manuals_phase_pill((string)$row['phase_label'], (string)$row['phase_tone']) ?>
        <h2 class="bm-book-card__title">
          <a href="<?= h($previewUrl) ?>" data-bm-reader-open><?= h((string)$row['book_title']) ?></a>
        </h2>
        <dl class="bm-meta">
          <dt>Revision</dt><dd><?= h((string)($row['version_label'] ?? '—')) ?></dd>
          <dt>Update</dt><dd><?= h(books_manuals_update_label($row['update_code'] ?? null)) ?></dd>
          <dt>Approval</dt><dd><?= h(books_manuals_approval_label((string)($row['approval_route'] ?? 'internal'), $row['authority_code'] ?? null)) ?></dd>
          <dt>Reviewers</dt><dd><?= count($reviewers) ?> selected</dd>
          <dt>Audit</dt><dd><?= h(strtoupper((string)($row['audit_status'] ?? 'not run'))) ?></dd>
        </dl>
        <div class="bm-progress" title="MCCF coverage <?= h(number_format($coverage, 1)) ?>%">
          <span style="width:<?= h(number_format($coverage, 2, '.', '')) ?>%"></span>
        </div>
        <div class="bm-actions" style="margin-top:14px;">
          <button class="app-btn app-btn--primary" type="button" data-compliance-modal-open="<?= h($settingsModalId) ?>">Open Settings</button>
          <?php if ($previousVersions !== array()): ?>
            <button class="app-btn app-btn--secondary" type="button" data-compliance-modal-open="<?= h($versionsModalId) ?>">
              Previous Versions (<?= count($previousVersions) ?>)
            </button>
          <?php endif; ?>
          <?php if (in_array((string)$row['lifecycle_status'], array('draft', 'in_review'), true)): ?>
            <a class="app-btn app-btn--secondary" href="/admin/compliance/controlled_book_editor.php?version_id=<?= $versionId ?>">Edit</a>
          <?php endif; ?>
          <details class="bm-overflow-menu">
            <summary aria-label="More actions">…</summary>
            <div class="bm-overflow-menu__panel">
              <?php if ((string)$row['lifecycle_status'] === 'released'): ?>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="create_revision">
                  <input type="hidden" name="version_id" value="<?= $versionId ?>">
                  <button type="submit">Create NEW Revision Draft</button>
                </form>
              <?php else: ?>
                <button
                  class="bm-overflow-action bm-overflow-action--warning"
                  type="button"
                  data-compliance-modal-open="<?= h($overrideModalId) ?>"
                >
                  <span class="bm-overflow-action__icon" aria-hidden="true">!</span>
                  <span class="bm-overflow-action__copy">
                    <strong>Override approval blockers</strong>
                    <small>Requires a rationale and creates a new revision.</small>
                  </span>
                </button>
              <?php endif; ?>
            </div>
          </details>
        </div>
      </div>
    </article>

    <dialog class="compliance-modal bm-manual-modal" id="<?= h($settingsModalId) ?>">
      <div class="compliance-modal__panel bm-manual-modal__panel">
        <header class="bm-modal-hero">
          <div>
            <div class="hero-overline">Books &amp; Manuals · <?= h((string)$row['book_key']) ?></div>
            <h2><?= h((string)$row['book_title']) ?></h2>
            <p>Revision <?= h((string)$row['version_label']) ?> · <?= h((string)$row['update_code']) ?></p>
          </div>
          <div class="bm-modal-hero__actions">
            <button type="button" class="app-btn app-btn--secondary" data-bm-settings-toggle>Settings</button>
            <button type="button" class="bm-modal-close" data-compliance-modal-close aria-label="Close">&times;</button>
          </div>
        </header>
        <div class="compliance-modal__body bm-manual-modal__body">
          <section data-bm-lifecycle>
            <div class="bm-lifecycle-track" aria-label="Manual lifecycle">
              <?php foreach (array('Draft', 'Draft Review', 'Awaiting Approval', 'Approved') as $index => $label): ?>
                <div class="bm-lifecycle-step <?= $index <= $stageIndex ? 'is-active' : '' ?>">
                  <span></span>
                  <small><?= h($label) ?></small>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="bm-current-stage">
              <span>Current Stage</span>
              <strong><?= h((string)$row['phase_label']) ?></strong>
              <p>
                <?= match ((string)$row['lifecycle_status']) {
                    'draft' => 'Only administrators can edit this draft.',
                    'in_review' => 'Administrators and the selected reviewers can review this manual.',
                    'approved' => 'The manual is locked while it awaits final approval.',
                    'released' => 'This approved revision is available under its reader policy.',
                    default => '',
                } ?>
              </p>
            </div>
            <?php $lifecycleActions = BooksManualsWorkflowService::actionsFor((string)$row['lifecycle_status']); ?>
            <?php if ($lifecycleActions !== array()): ?>
              <div class="bm-actions bm-lifecycle-actions">
                <?php foreach ($lifecycleActions as $lifecycleAction): ?>
                  <?php
                    if ($lifecycleAction['action'] === 'submit_authority'
                        && (string)($row['approval_route'] ?? 'internal') !== 'authority') {
                        continue;
                    }
                    if ($lifecycleAction['action'] === 'manual_approve'
                        && (string)($row['approval_route'] ?? 'internal') === 'authority') {
                        $lifecycleAction['label'] = 'Record Authority Approval';
                    }
                  ?>
                  <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="lifecycle_transition">
                    <input type="hidden" name="version_id" value="<?= $versionId ?>">
                    <input type="hidden" name="lifecycle_action" value="<?= h((string)$lifecycleAction['action']) ?>">
                    <button class="app-btn app-btn--<?= $lifecycleAction['tone'] === 'primary' ? 'primary' : 'secondary' ?>" type="submit">
                      <?= h((string)$lifecycleAction['label']) ?>
                    </button>
                  </form>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <dl class="bm-meta bm-modal-summary">
              <dt>Manual type</dt><dd><?= h((string)($row['manual_type'] ?? 'operations')) ?></dd>
              <dt>Approval route</dt><dd><?= h(books_manuals_approval_label((string)($row['approval_route'] ?? 'internal'), $row['authority_code'] ?? null)) ?></dd>
              <dt>Reviewers</dt><dd><?= count($reviewers) ?> selected</dd>
              <dt>Approved reader rule</dt><dd><?= (string)($row['approved_reader_policy'] ?? 'all_readers') === 'selected_reviewers' ? 'Selected reviewers only' : 'All platform readers' ?></dd>
            </dl>
          </section>

          <section class="bm-governance-settings" data-bm-governance hidden>
            <h3>Manual Settings</h3>
            <form method="post" class="bm-form-grid">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="save_manual_settings">
              <input type="hidden" name="version_id" value="<?= $versionId ?>">
              <label class="bm-form-field">
                <span>Manual type</span>
                <select name="manual_type">
                  <?php foreach (array('operations' => 'Operations Manual', 'training' => 'Training Manual', 'sop' => 'Standard Operating Procedures', 'course_book' => 'Course Book', 'handbook' => 'Handbook', 'other' => 'Other') as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= (string)($row['manual_type'] ?? 'operations') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="bm-form-field">
                <span>Approval route</span>
                <select name="approval_route" data-bm-approval-route>
                  <option value="internal" <?= (string)($row['approval_route'] ?? 'internal') === 'internal' ? 'selected' : '' ?>>Internal approval</option>
                  <option value="authority" <?= (string)($row['approval_route'] ?? '') === 'authority' ? 'selected' : '' ?>>Authority approval</option>
                </select>
              </label>
              <label class="bm-form-field" data-bm-authority-field>
                <span>Authority code</span>
                <input name="authority_code" maxlength="64" value="<?= h((string)($row['authority_code'] ?? '')) ?>" placeholder="CAA / FAA / EASA">
              </label>
              <label class="bm-form-field">
                <span>Approved reader policy</span>
                <select name="approved_reader_policy">
                  <option value="all_readers" <?= (string)($row['approved_reader_policy'] ?? 'all_readers') === 'all_readers' ? 'selected' : '' ?>>All platform readers</option>
                  <option value="selected_reviewers" <?= (string)($row['approved_reader_policy'] ?? '') === 'selected_reviewers' ? 'selected' : '' ?>>Selected reviewers only</option>
                </select>
              </label>

              <div class="bm-reviewer-editor">
                <span class="bm-form-field__label">Draft Review reviewers</span>
                <div class="bm-reviewer-list" data-bm-reviewer-list>
                  <?php foreach ($reviewers as $reviewer): ?>
                    <div class="bm-reviewer-row" data-reviewer-id="<?= (int)$reviewer['id'] ?>">
                      <input type="hidden" name="reviewer_user_ids[]" value="<?= (int)$reviewer['id'] ?>">
                      <span><strong><?= h((string)($reviewer['name'] ?: $reviewer['email'])) ?></strong><small><?= h((string)$reviewer['email']) ?></small></span>
                      <button type="button" data-bm-reviewer-remove aria-label="Remove reviewer">&times;</button>
                    </div>
                  <?php endforeach; ?>
                </div>
                <select data-bm-reviewer-add>
                  <option value="">+ Add person as reviewer</option>
                  <?php foreach ($availableReviewers as $reviewer): ?>
                    <option value="<?= (int)$reviewer['id'] ?>" data-name="<?= h((string)($reviewer['name'] ?: $reviewer['email'])) ?>" data-email="<?= h((string)$reviewer['email']) ?>">
                      <?= h((string)($reviewer['name'] ?: $reviewer['email'])) ?> · <?= h((string)$reviewer['email']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="bm-policy-summary">
                <strong>Standard lifecycle policy</strong>
                <span>DRAFT · Administrators only</span>
                <span>DRAFT REVIEW · Administrators + selected reviewers</span>
                <span>AWAITING APPROVAL · Locked; administrators + selected reviewers can read</span>
                <span>APPROVED · Uses the approved reader policy above</span>
              </div>
              <div class="bm-actions">
                <button class="app-btn app-btn--primary" type="submit">Save Settings</button>
              </div>
            </form>
          </section>
        </div>
      </div>
    </dialog>

    <?php if ($previousVersions !== array()): ?>
      <dialog class="compliance-modal bm-versions-modal" id="<?= h($versionsModalId) ?>">
        <div class="compliance-modal__panel bm-versions-modal__panel">
          <header class="bm-modal-hero">
            <div>
              <div class="hero-overline">Books &amp; Manuals · Version history</div>
              <h2><?= h((string)$row['book_title']) ?></h2>
              <p>
                <?= count($previousVersions) ?> previous
                <?= count($previousVersions) === 1 ? 'version' : 'versions' ?>
              </p>
            </div>
            <button type="button" class="bm-modal-close" data-compliance-modal-close aria-label="Close">&times;</button>
          </header>
          <div class="compliance-modal__body bm-versions-modal__body">
            <div class="bm-version-history">
              <?php foreach ($previousVersions as $previousVersion): ?>
                <?php
                  $previousVersionId = (int)$previousVersion['version_id'];
                  $previousReaderUrl = '/admin/books_manuals/reader.php?version_id='
                      . $previousVersionId;
                  $previousCoverUrl = '/student/api/manual_reader_cover_thumbnail.php?book='
                      . rawurlencode((string)$row['book_key'])
                      . '&version_id=' . $previousVersionId
                      . '&admin_preview=1';
                  $approvedBy = trim((string)($previousVersion['approved_by_name'] ?? ''));
                  if ($approvedBy === '') {
                      $approvedBy = trim((string)($previousVersion['approved_by_email'] ?? ''));
                  }
                ?>
                <article class="bm-version-history__item">
                  <a
                    class="bm-version-history__cover"
                    href="<?= h($previousReaderUrl) ?>"
                    data-bm-reader-open
                    aria-label="Open Revision <?= h((string)$previousVersion['version_label']) ?> read-only"
                  >
                    <img
                      src="<?= h($previousCoverUrl) ?>"
                      alt="Revision <?= h((string)$previousVersion['version_label']) ?> front page"
                    >
                  </a>
                  <div class="bm-version-history__details">
                    <div class="bm-version-history__heading">
                      <div>
                        <span>Previous version</span>
                        <h3>Revision <?= h((string)$previousVersion['version_label']) ?></h3>
                      </div>
                      <?= books_manuals_phase_pill(
                          (string)$previousVersion['phase_label'],
                          (string)$previousVersion['phase_tone']
                      ) ?>
                    </div>
                    <dl class="bm-version-history__meta">
                      <dt>Approval date</dt>
                      <dd><?= h((string)($previousVersion['released_at'] ?: 'Not recorded')) ?></dd>
                      <dt>Approved by</dt>
                      <dd><?= h($approvedBy !== '' ? $approvedBy : 'Not recorded') ?></dd>
                      <dt>Effective date</dt>
                      <dd><?= h((string)($previousVersion['effective_date'] ?: 'Not recorded')) ?></dd>
                      <dt>Update</dt>
                      <dd><?= h(books_manuals_update_label($previousVersion['update_code'] ?? null)) ?></dd>
                      <dt>Audit</dt>
                      <dd>
                        <?= h(strtoupper((string)($previousVersion['audit_status'] ?? 'not run'))) ?>
                        <?php if ($previousVersion['coverage_percent'] !== null): ?>
                          · <?= h(number_format((float)$previousVersion['coverage_percent'], 1)) ?>%
                        <?php endif; ?>
                      </dd>
                    </dl>
                    <a class="app-btn app-btn--secondary" href="<?= h($previousReaderUrl) ?>" data-bm-reader-open>
                      Open Read-Only Version
                    </a>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </dialog>
    <?php endif; ?>

    <?php if ((string)$row['lifecycle_status'] !== 'released'): ?>
      <dialog class="compliance-modal bm-override-modal" id="<?= h($overrideModalId) ?>">
        <div class="compliance-modal__panel bm-override-modal__panel">
          <header class="bm-modal-hero">
            <div>
              <div class="hero-overline">Controlled legacy approval override</div>
              <h2>Override all blockers</h2>
              <p>
                <?= h((string)$row['book_key']) ?> <?= h((string)$row['version_label']) ?>
                will be recorded as the approved source for a new revision.
              </p>
            </div>
            <button type="button" class="bm-modal-close" data-compliance-modal-close aria-label="Close">&times;</button>
          </header>
          <div class="compliance-modal__body">
            <div class="cmp-alert cmp-alert--error">
              This one action overrides every current lifecycle, MCCF coverage,
              source-baseline and authoritative-pagination blocker. The actor,
              rationale, source fingerprint and complete blocker snapshot are
              retained permanently.
            </div>
            <form method="post" class="bm-override-form">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="create_revision_override">
              <input type="hidden" name="version_id" value="<?= $versionId ?>">
              <label class="bm-form-field">
                <span>Rationale for overriding all blockers</span>
                <textarea
                  name="override_rationale"
                  required
                  minlength="20"
                  maxlength="4000"
                  rows="6"
                  placeholder="Explain when and by whom this revision was previously approved, and why the current automated blockers do not invalidate that approval."
                ></textarea>
              </label>
              <label class="bm-override-confirm">
                <input type="checkbox" required>
                <span>
                  I confirm this version was already approved and authorize it
                  as the controlled source of the new revision.
                </span>
              </label>
              <div class="bm-actions">
                <button class="app-btn app-btn--primary" type="submit">
                  Override All Blockers &amp; Create Draft
                </button>
                <button class="app-btn app-btn--secondary" type="button" data-compliance-modal-close>
                  Cancel
                </button>
              </div>
            </form>
          </div>
        </div>
      </dialog>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if ($tablesReady && $rows === array()): ?>
    <div class="cmp-card bm-empty">No manuals are registered yet.</div>
  <?php endif; ?>
</section>

<dialog class="bm-reader-modal" id="bm-reader-modal" aria-label="Book reader">
  <button type="button" class="bm-reader-modal__close" data-bm-reader-close aria-label="Close book">&times;</button>
  <iframe class="bm-reader-modal__frame" title="Book pages"></iframe>
</dialog>

<script src="/assets/books-manuals.js?v=<?= (int)(@filemtime(__DIR__ . '/../../assets/books-manuals.js') ?: time()) ?>"></script>
<?php
compliance_page_close();
cw_footer();
