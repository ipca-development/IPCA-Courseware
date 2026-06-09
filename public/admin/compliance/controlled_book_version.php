<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingLepService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingApprovalService.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);
$svc = new ControlledPublishingFoundationService($pdo);
$lepSvc = new ControlledPublishingLepService($pdo);
$approvalSvc = new ControlledPublishingApprovalService($pdo, $lepSvc);

function cpv_flash(string $type, string $msg): void
{
    $_SESSION['_cpv_flash'] = array('type' => $type, 'message' => $msg);
}

function cpv_flash_take(): ?array
{
    if (empty($_SESSION['_cpv_flash']) || !is_array($_SESSION['_cpv_flash'])) {
        return null;
    }
    $f = $_SESSION['_cpv_flash'];
    unset($_SESSION['_cpv_flash']);
    return $f;
}

$versionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $versionId > 0) {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'apply_required_source_sets') {
            $svc->applyRequiredSourceSetsForVersion($versionId, $uid);
            cpv_flash('success', 'Required source sets applied.');
        } elseif ($action === 'save_source_sets') {
            $selected = $_POST['source_set_ids'] ?? array();
            if (!is_array($selected)) {
                $selected = array();
            }
            $roles = $_POST['selection_roles'] ?? array();
            if (!is_array($roles)) {
                $roles = array();
            }
            $selections = array();
            foreach ($selected as $sourceSetId) {
                $sourceSetId = (int)$sourceSetId;
                if ($sourceSetId <= 0) {
                    continue;
                }
                $role = (string)($roles[$sourceSetId] ?? 'reference_source');
                $selections[] = array(
                    'source_set_id' => $sourceSetId,
                    'selection_role' => $role,
                    'is_required_for_release' => true,
                );
            }
            $svc->setVersionSourceSets($versionId, $selections, $uid);
            cpv_flash('success', 'Statement of Compliance Source selections saved.');
        } elseif ($action === 'freeze_baseline') {
            $baselineId = $svc->freezeSourceBaseline($versionId, $uid);
            cpv_flash('success', 'Source baseline frozen (#' . $baselineId . ').');
        } elseif ($action === 'scaffold_sections') {
            $scaffold = $svc->scaffoldVersionSections($versionId, $uid);
            cpv_flash(
                'success',
                'Section scaffold complete: ' . (int)$scaffold['sections_total'] . ' sections (' .
                (int)$scaffold['sections_created'] . ' new, ' . (int)$scaffold['blocks_created'] . ' placeholders).'
            );
        } elseif ($action === 'release_version') {
            $svc->releaseVersion($versionId, $uid);
            cpv_flash('success', 'Version ' . (string)$version['version_label'] . ' released.');
        } elseif ($action === 'create_next_draft') {
            $newLabel = trim((string)($_POST['new_version_label'] ?? ''));
            if ($newLabel === '') {
                $newLabel = $svc->suggestNextVersionLabel((string)$version['version_label']);
            }
            $created = $svc->createNextDraftVersion($versionId, $newLabel, $uid);
            cpv_flash(
                'success',
                'Draft ' . (string)$created['version_label'] . ' created from ' . (string)$version['version_label'] . '.'
            );
            redirect('/admin/compliance/controlled_book_version.php?id=' . (int)$created['version_id']);
        }
    } catch (Throwable $e) {
        cpv_flash('error', $e->getMessage());
    }
    redirect('/admin/compliance/controlled_book_version.php?id=' . $versionId);
}

$flash = cpv_flash_take();

if ($versionId <= 0) {
    cw_header('Compliance · Book Version');
    compliance_page_open(array(
        'overline' => 'Compliance · Controlled publishing',
        'title' => 'Book version not specified',
        'back' => array('href' => '/admin/compliance/controlled_books.php', 'label' => 'All books'),
    ));
    echo '<section class="cmp-card"><p style="margin:0;">Provide ?id=version_id</p></section>';
    compliance_page_close();
    cw_footer();
    return;
}

$version = $svc->getVersion($versionId);
if ($version === null) {
    cw_header('Compliance · Book Version');
    compliance_page_open(array(
        'overline' => 'Compliance · Controlled publishing',
        'title' => 'Version not found',
        'back' => array('href' => '/admin/compliance/controlled_books.php', 'label' => 'All books'),
    ));
    echo '<section class="cmp-card"><p style="margin:0;">No version for that id.</p></section>';
    compliance_page_close();
    cw_footer();
    return;
}

$selections = $svc->getVersionSourceSelections($versionId);
$sections = $svc->listVersionSections($versionId);
$validation = $svc->validateVersionReleaseFoundation($versionId);
$canRelease = $svc->canReleaseVersion($versionId);
$suggestedNextVersion = $svc->suggestNextVersionLabel((string)$version['version_label']);
$allSourceSets = $svc->listActiveSourceSets();
$lepApprovalUrl = '';
if (in_array((string)$version['lifecycle_status'], array('draft', 'in_review', 'approved'), true)) {
    $approvalResult = $approvalSvc->ensureApprovalToken($versionId, $uid);
    $lepApprovalUrl = (string)($approvalResult['approval_url'] ?? '');
}
$selectedIds = array();
$selectedRoles = array();
foreach ($selections as $sel) {
    $selectedIds[(int)$sel['source_set_id']] = true;
    $selectedRoles[(int)$sel['source_set_id']] = (string)$sel['selection_role'];
}

cw_header('Compliance · ' . (string)$version['book_key'] . ' ' . (string)$version['version_label']);

compliance_page_open(array(
    'overline' => 'Compliance · Controlled publishing',
    'title' => (string)$version['book_key'] . ' ' . (string)$version['version_label'],
    'description' => 'Statement of Compliance Source selection and source baseline freeze.',
    'back' => array('href' => '/admin/compliance/controlled_books.php', 'label' => 'All books'),
    'flash' => $flash,
    'stats' => array(
        array('label' => 'Lifecycle', 'value' => (string)$version['lifecycle_status']),
        array('label' => 'Foundation', 'value' => (string)$validation['status'], 'tone' => $validation['ok'] ? 'ok' : 'warn'),
        array('label' => 'Source sets', 'value' => (int)$validation['selected_count']),
        array('label' => 'Sections', 'value' => count($sections)),
    ),
    'actions' => array(
        array(
            'label' => 'Open editor',
            'href' => '/admin/compliance/controlled_book_editor.php?version_id=' . $versionId,
            'variant' => 'primary',
        ),
        array(
            'label' => 'Import from Word',
            'href' => '/admin/compliance/controlled_book_docx_import.php?version_id=' . $versionId,
            'variant' => 'secondary',
        ),
    ),
));

?>
<section class="cmp-card">
  <h2 style="margin:0 0 12px;">Release foundation</h2>
  <p style="margin:0 0 8px;">Status: <strong><?= h((string)$validation['status']) ?></strong></p>
  <?php if ($validation['missing'] !== array()): ?>
    <p style="margin:0;color:#b45309;">Missing: <?= h(implode(', ', $validation['missing'])) ?></p>
  <?php endif; ?>
  <?php if (!empty($version['baseline_hash'])): ?>
    <p style="margin:12px 0 0;font-family:ui-monospace,monospace;font-size:12px;">Baseline hash: <?= h((string)$version['baseline_hash']) ?></p>
  <?php endif; ?>
</section>

<section class="cmp-card" style="margin-top:16px;">
  <h2 style="margin:0 0 12px;">Version lifecycle</h2>
  <p style="margin:0 0 12px;font-size:13px;color:#64748b;">
    Release the current version when the manual is approved, then create the next draft (e.g. 6.0 → 7.0).
    The Amendment List is copied forward and a new revision row is added automatically.
  </p>
  <?php if ((string)$version['lifecycle_status'] !== 'released'): ?>
    <form method="post" style="display:inline-block;margin-right:12px;">
      <input type="hidden" name="action" value="release_version">
      <button type="submit" <?= $canRelease ? '' : 'disabled' ?> title="<?= $canRelease ? '' : h((string)$validation['status']) ?>">
        Release <?= h((string)$version['version_label']) ?>
      </button>
    </form>
    <?php if (!$canRelease): ?>
      <p style="margin:8px 0 0;color:#b45309;font-size:13px;">Release blocked: <?= h((string)$validation['status']) ?></p>
    <?php endif; ?>
  <?php else: ?>
    <p style="margin:0 0 12px;"><strong>Released</strong><?= !empty($version['released_at']) ? (' · ' . h((string)$version['released_at'])) : '' ?></p>
    <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <input type="hidden" name="action" value="create_next_draft">
      <label style="font-size:13px;">Next draft label</label>
      <input type="text" name="new_version_label" value="<?= h($suggestedNextVersion) ?>" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;width:120px;">
      <button type="submit">Create draft <?= h($suggestedNextVersion) ?></button>
    </form>
  <?php endif; ?>
</section>

<section class="cmp-card" style="margin-top:16px;">
  <h2 style="margin:0 0 12px;">Statement of Compliance Source</h2>
  <form method="post" style="margin-bottom:16px;">
    <input type="hidden" name="action" value="apply_required_source_sets">
    <button type="submit">Apply required source sets for <?= h((string)$version['manual_code']) ?></button>
  </form>

  <form method="post">
    <input type="hidden" name="action" value="save_source_sets">
    <div style="display:grid;gap:10px;">
      <?php foreach ($allSourceSets as $set): ?>
        <?php $sid = (int)$set['id']; ?>
        <label style="display:flex;gap:12px;align-items:center;padding:10px;border:1px solid #e2e8f0;border-radius:8px;">
          <input type="checkbox" name="source_set_ids[]" value="<?= $sid ?>" <?= isset($selectedIds[$sid]) ? 'checked' : '' ?>>
          <span style="flex:1;">
            <strong><?= h((string)$set['source_set_key']) ?></strong><br>
            <span style="font-size:12px;color:#64748b;"><?= h((string)$set['source_family']) ?> · <?= h((string)$set['revision_label']) ?></span>
          </span>
          <select name="selection_roles[<?= $sid ?>]" style="padding:6px;border-radius:6px;">
            <?php
            $roles = array('compliance_source', 'manual_source', 'regulation_source', 'reference_source');
            $currentRole = $selectedRoles[$sid] ?? 'reference_source';
            foreach ($roles as $role):
            ?>
              <option value="<?= h($role) ?>" <?= $currentRole === $role ? 'selected' : '' ?>><?= h($role) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:12px;">
      <button type="submit">Save source selections</button>
    </div>
  </form>
</section>

<?php if ($lepApprovalUrl !== ''): ?>
<section class="cmp-card" style="margin-top:16px;">
  <h2 style="margin:0 0 12px;">LEP authority approval</h2>
  <p style="margin:0 0 12px;font-size:13px;color:#64748b;">
    Share this link with the competent authority to collect their e-signature on the List of Effective Parts.
    The link is token-protected and expires after 90 days.
  </p>
  <p style="margin:0;font-family:ui-monospace,monospace;font-size:12px;word-break:break-all;">
    <a href="<?= h($lepApprovalUrl) ?>" target="_blank" rel="noopener"><?= h($lepApprovalUrl) ?></a>
  </p>
</section>
<?php endif; ?>

<section class="cmp-card" style="margin-top:16px;">
  <h2 style="margin:0 0 12px;">Mandatory section scaffold</h2>
  <p style="margin:0 0 12px;">Creates the governed manual skeleton (Cover, TOC, LEP, Amendment List, Main Content, Annexes, etc.) with stable anchors.</p>
  <form method="post" style="margin-bottom:16px;">
    <input type="hidden" name="action" value="scaffold_sections">
    <button type="submit">Scaffold sections from template</button>
  </form>

  <?php if ($sections === array()): ?>
    <p style="margin:0;color:#64748b;">No sections scaffolded yet.</p>
  <?php else: ?>
    <div style="overflow:auto;">
      <table class="cmp-table" style="width:100%;border-collapse:collapse;">
        <thead>
          <tr>
            <th align="left">Order</th>
            <th align="left">Section</th>
            <th align="left">Type</th>
            <th align="left">Stable anchor</th>
            <th align="center">System</th>
            <th align="center">Generated</th>
            <th align="right">Blocks</th>
            <th align="left">Editor</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sections as $section): ?>
            <tr>
              <td><?= (int)$section['sort_order'] ?></td>
              <td><?= h((string)$section['title']) ?></td>
              <td><?= h((string)$section['section_type']) ?></td>
              <td style="font-family:ui-monospace,monospace;font-size:12px;"><?= h((string)$section['stable_anchor']) ?></td>
              <td align="center"><?= !empty($section['is_system_managed']) ? 'yes' : 'no' ?></td>
              <td align="center"><?= !empty($section['is_generated']) ? 'yes' : 'no' ?></td>
              <td align="right"><?= (int)$section['block_count'] ?></td>
              <td>
                <a href="/admin/compliance/controlled_book_editor.php?version_id=<?= $versionId ?>&amp;section_id=<?= (int)$section['id'] ?>">Open</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="cmp-card" style="margin-top:16px;">
  <h2 style="margin:0 0 12px;">Freeze source baseline</h2>
  <p style="margin:0 0 12px;">Creates an immutable source baseline snapshot with hash and per-source-set metadata.</p>
  <form method="post" onsubmit="return confirm('Freeze source baseline for this version?');">
    <input type="hidden" name="action" value="freeze_baseline">
    <button type="submit" class="cmp-btn-success">Freeze Source Baseline</button>
  </form>
</section>

<?php if (!empty($version['source_snapshot_json'])): ?>
<section class="cmp-card" style="margin-top:16px;">
  <h2 style="margin:0 0 12px;">Baseline snapshot summary</h2>
  <pre style="white-space:pre-wrap;font-size:12px;background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0;"><?= h((string)$version['source_snapshot_json']) ?></pre>
</section>
<?php endif; ?>
<?php

compliance_page_close();
cw_footer();
