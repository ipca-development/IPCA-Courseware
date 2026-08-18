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
if (!$workflow->tablesPresent()) {
    redirect('/admin/books_manuals/index.php');
}
$versionId = (int)($_GET['version_id'] ?? $_POST['version_id'] ?? 0);
$csrf = (string)($_SESSION['books_manuals_csrf'] ?? '');
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(24));
    $_SESSION['books_manuals_csrf'] = $csrf;
}

function bm_manual_flash(string $type, string $message): void
{
    $_SESSION['books_manuals_manual_flash'] = array('type' => $type, 'message' => $message);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('The form expired. Reload and try again.');
        }
        $action = (string)($_POST['action'] ?? '');
        $detail = $workflow->getVersionDetail($versionId);
        if ($detail === null) {
            throw new RuntimeException('Manual version not found.');
        }
        if ($action === 'transition') {
            $workflow->transition(
                $versionId,
                (string)($_POST['lifecycle_action'] ?? ''),
                $actorId,
                trim((string)($_POST['note'] ?? '')) ?: null
            );
            bm_manual_flash('success', 'Lifecycle phase updated.');
        } elseif ($action === 'save_profile') {
            $workflow->saveProfile(
                (int)$detail['book_id'],
                (string)($_POST['manual_type'] ?? ''),
                (string)($_POST['approval_route'] ?? ''),
                isset($_POST['authority_code']) ? (string)$_POST['authority_code'] : null,
                $actorId,
                (string)($detail['approved_reader_policy'] ?? 'all_readers')
            );
            bm_manual_flash('success', 'Manual settings saved.');
        } elseif ($action === 'create_revision') {
            $created = $workflow->createRevision($versionId, $actorId);
            bm_manual_flash('success', 'Revision ' . (string)$created['version_label'] . ' created in Draft.');
            redirect('/admin/books_manuals/manual.php?version_id=' . (int)$created['version_id']);
        } else {
            throw new RuntimeException('Unknown Books & Manuals action.');
        }
        redirect('/admin/books_manuals/manual.php?version_id=' . $versionId);
    } catch (Throwable $e) {
        bm_manual_flash('error', $e->getMessage());
        redirect('/admin/books_manuals/manual.php?version_id=' . $versionId);
    }
}

$detail = $workflow->getVersionDetail($versionId);
if ($detail === null) {
    http_response_code(404);
    exit('Manual version not found.');
}
$flash = $_SESSION['books_manuals_manual_flash'] ?? null;
unset($_SESSION['books_manuals_manual_flash']);
$lifecycle = (string)$detail['lifecycle_status'];
$approvalRoute = (string)($detail['approval_route'] ?? 'internal');
$audit = is_array($detail['latest_audit'] ?? null) ? $detail['latest_audit'] : null;

cw_header('Books & Manuals · ' . (string)$detail['book_key']);
books_manuals_page_open(array(
    'title' => (string)$detail['book_title'],
    'description' => (string)$detail['book_key'] . ' · Revision ' . (string)$detail['version_label'],
    'back' => array('href' => '/admin/books_manuals/index.php', 'label' => 'IPCA Library'),
    'flash' => is_array($flash) ? $flash : null,
    'stats' => array(
        array('label' => 'Phase', 'value' => (string)$detail['phase_label']),
        array('label' => 'Update identity', 'value' => (string)$detail['update_code']),
        array('label' => 'Audit', 'value' => strtoupper((string)($audit['status'] ?? 'not run'))),
    ),
    'actions' => in_array($lifecycle, array('draft', 'in_review'), true)
        ? array(array(
            'label' => 'Open Editor',
            'href' => '/admin/compliance/controlled_book_editor.php?version_id=' . $versionId,
        ))
        : array(),
));
?>

<div class="bm-library-grid">
  <section class="cmp-card">
    <h2 style="margin-top:0;">Lifecycle</h2>
    <p><?= books_manuals_phase_pill((string)$detail['phase_label'], (string)$detail['phase_tone']) ?></p>
    <dl class="bm-meta">
      <dt>Update code</dt><dd><?= h((string)$detail['update_code']) ?></dd>
      <dt>Source</dt><dd><?= h(substr((string)$detail['source_fingerprint'], 0, 16)) ?>…</dd>
      <dt>Page map</dt><dd><?= h($detail['page_map_hash'] ? substr((string)$detail['page_map_hash'], 0, 16) . '…' : 'Not generated') ?></dd>
      <dt>Manifest</dt><dd><?= h($detail['manifest_hash'] ? substr((string)$detail['manifest_hash'], 0, 16) . '…' : 'Not generated') ?></dd>
    </dl>
    <?php if ($lifecycle === 'released'): ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="version_id" value="<?= $versionId ?>">
        <input type="hidden" name="action" value="create_revision">
        <button class="app-btn app-btn--primary" type="submit">Create a Revision</button>
      </form>
    <?php else: ?>
      <div class="bm-actions">
        <?php foreach ((array)$detail['actions'] as $action): ?>
          <?php
            if ($action['action'] === 'submit_authority' && $approvalRoute !== 'authority') {
                continue;
            }
            if ($action['action'] === 'manual_approve' && $approvalRoute === 'authority') {
                $action['label'] = 'Record Authority Approval';
            }
          ?>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="version_id" value="<?= $versionId ?>">
            <input type="hidden" name="action" value="transition">
            <input type="hidden" name="lifecycle_action" value="<?= h((string)$action['action']) ?>">
            <button class="app-btn app-btn--<?= $action['tone'] === 'primary' ? 'primary' : 'secondary' ?>" type="submit">
              <?= h((string)$action['label']) ?>
            </button>
          </form>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="cmp-card">
    <h2 style="margin-top:0;">Compliance audit</h2>
    <?php if ($audit === null): ?>
      <p>No immutable MCCF audit snapshot has been created for this revision.</p>
    <?php else: ?>
      <dl class="bm-meta">
        <dt>Status</dt><dd><?= h(strtoupper((string)$audit['status'])) ?></dd>
        <dt>Coverage</dt><dd><?= h(number_format((float)$audit['coverage_percent'], 1)) ?>%</dd>
        <dt>Missing</dt><dd><?= (int)$audit['missing_count'] ?></dd>
        <dt>Insufficient</dt><dd><?= (int)$audit['insufficient_count'] ?></dd>
        <dt>Snapshot</dt><dd><?= h((string)$audit['snapshot_uuid']) ?></dd>
      </dl>
      <div class="bm-progress"><span style="width:<?= h(number_format((float)$audit['coverage_percent'], 2, '.', '')) ?>%"></span></div>
    <?php endif; ?>
    <p style="margin-bottom:0;">
      <a href="/admin/compliance/books_manuals_audits.php?version_id=<?= $versionId ?>">Open Compliance audit</a>
    </p>
  </section>
</div>

<section class="cmp-card" style="margin-top:18px;">
  <h2 style="margin-top:0;">Manual settings</h2>
  <form method="post" class="bm-form-grid">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="version_id" value="<?= $versionId ?>">
    <input type="hidden" name="action" value="save_profile">
    <label class="bm-form-field">
      <span>Manual type</span>
      <select name="manual_type">
        <?php foreach (array('operations' => 'Operations Manual', 'training' => 'Training Manual', 'sop' => 'SOP', 'course_book' => 'Course Book', 'handbook' => 'Handbook', 'other' => 'Other') as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= (string)$detail['manual_type'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="bm-form-field">
      <span>Approval route</span>
      <select name="approval_route">
        <option value="internal" <?= $approvalRoute === 'internal' ? 'selected' : '' ?>>Internal approval</option>
        <option value="authority" <?= $approvalRoute === 'authority' ? 'selected' : '' ?>>Authority approval</option>
      </select>
    </label>
    <label class="bm-form-field">
      <span>Authority code</span>
      <input name="authority_code" maxlength="64" value="<?= h((string)($detail['authority_code'] ?? '')) ?>">
    </label>
    <div class="bm-actions" style="align-self:end;">
      <button class="app-btn app-btn--primary" type="submit">Save settings</button>
    </div>
  </form>
  <p style="margin-bottom:0;color:#64748b;font-size:12px;">
    Reviewer assignments and approved-reader policy are managed from the IPCA Library settings modal.
  </p>
</section>

<?php
compliance_page_close();
cw_footer();
