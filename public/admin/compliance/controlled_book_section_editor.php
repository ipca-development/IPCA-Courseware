<?php
declare(strict_types=1);

/**
 * Legacy URL — redirect to the document-style book editor.
 */
$versionId = isset($_GET['version_id']) ? (int)$_GET['version_id'] : 0;
$sectionId = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;
$q = 'version_id=' . $versionId;
if ($sectionId > 0) {
    $q .= '&section_id=' . $sectionId;
}
header('Location: /admin/compliance/controlled_book_editor.php?' . $q, true, 302);
exit;

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingBlockService.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);
$foundation = new ControlledPublishingFoundationService($pdo);
$blocks = new ControlledPublishingBlockService($pdo);

function cpse_flash(string $type, string $msg): void
{
    $_SESSION['_cpse_flash'] = array('type' => $type, 'message' => $msg);
}

function cpse_flash_take(): ?array
{
    if (empty($_SESSION['_cpse_flash']) || !is_array($_SESSION['_cpse_flash'])) {
        return null;
    }
    $f = $_SESSION['_cpse_flash'];
    unset($_SESSION['_cpse_flash']);
    return $f;
}

$versionId = isset($_GET['version_id']) ? (int)$_GET['version_id'] : 0;
$sectionId = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $versionId > 0 && $sectionId > 0) {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create_block') {
            $blockType = (string)($_POST['block_type'] ?? '');
            $payload = array(
                'text' => (string)($_POST['text'] ?? ''),
                'level' => (int)($_POST['level'] ?? 2),
            );
            $blockId = $blocks->createBlock($versionId, $sectionId, $blockType, $payload, $uid);
            cpse_flash('success', ucfirst($blockType) . ' block created (#' . $blockId . ').');
        } elseif ($action === 'update_block') {
            $blockId = (int)($_POST['block_id'] ?? 0);
            $payload = array(
                'text' => (string)($_POST['text'] ?? ''),
                'level' => (int)($_POST['level'] ?? 2),
            );
            $blocks->updateBlock($blockId, $payload, $uid);
            cpse_flash('success', 'Block #' . $blockId . ' saved.');
        } elseif ($action === 'delete_block') {
            $blockId = (int)($_POST['block_id'] ?? 0);
            $blocks->deleteBlock($blockId, $uid);
            cpse_flash('success', 'Block #' . $blockId . ' deleted.');
        }
    } catch (Throwable $e) {
        cpse_flash('error', $e->getMessage());
    }
    redirect('/admin/compliance/controlled_book_section_editor.php?version_id=' . $versionId . '&section_id=' . $sectionId);
}

$flash = cpse_flash_take();

if ($versionId <= 0 || $sectionId <= 0) {
    cw_header('Compliance · Section Editor');
    compliance_page_open(array(
        'overline' => 'Compliance · Controlled publishing',
        'title' => 'Section editor not specified',
        'back' => array('href' => '/admin/compliance/controlled_books.php', 'label' => 'All books'),
    ));
    echo '<section class="cmp-card"><p style="margin:0;">Provide ?version_id=...&amp;section_id=...</p></section>';
    compliance_page_close();
    cw_footer();
    return;
}

$version = $foundation->getVersion($versionId);
$section = $blocks->getSectionForEditing($versionId, $sectionId);

if ($version === null || $section === null) {
    cw_header('Compliance · Section Editor');
    compliance_page_open(array(
        'overline' => 'Compliance · Controlled publishing',
        'title' => 'Section not found',
        'back' => array('href' => '/admin/compliance/controlled_book_version.php?id=' . $versionId, 'label' => 'Book version'),
    ));
    echo '<section class="cmp-card"><p style="margin:0;">That section does not exist for this version.</p></section>';
    compliance_page_close();
    cw_footer();
    return;
}

if (empty($section['allow_author_blocks'])) {
    cw_header('Compliance · Section Editor');
    compliance_page_open(array(
        'overline' => 'Compliance · Controlled publishing',
        'title' => 'Section not editable',
        'back' => array('href' => '/admin/compliance/controlled_book_version.php?id=' . $versionId, 'label' => 'Book version'),
    ));
    echo '<section class="cmp-card"><p style="margin:0;">This section does not allow author blocks.</p></section>';
    compliance_page_close();
    cw_footer();
    return;
}

$sectionBlocks = $blocks->listSectionBlocks($sectionId);
$isReleased = (string)$version['lifecycle_status'] === 'released';

cw_header('Compliance · ' . (string)$section['title']);

compliance_page_open(array(
    'overline' => 'Compliance · Controlled publishing',
    'title' => (string)$section['title'],
    'description' => (string)$version['book_key'] . ' ' . (string)$version['version_label'] . ' — minimal block editor (heading / paragraph).',
    'back' => array('href' => '/admin/compliance/controlled_book_version.php?id=' . $versionId, 'label' => 'Book version'),
    'flash' => $flash,
    'stats' => array(
        array('label' => 'Blocks', 'value' => count($sectionBlocks)),
        array('label' => 'Anchor', 'value' => (string)$section['stable_anchor']),
        array('label' => 'Lifecycle', 'value' => (string)$version['lifecycle_status'], 'tone' => $isReleased ? 'warn' : 'ok'),
    ),
));

?>
<section class="cmp-card">
  <p style="margin:0 0 12px;color:#64748b;">
    Stable anchor: <code><?= h((string)$section['stable_anchor']) ?></code>
  </p>

  <?php if ($sectionBlocks === array()): ?>
    <p style="margin:0;color:#64748b;">No blocks yet. Add a heading or paragraph below.</p>
  <?php else: ?>
    <div style="display:grid;gap:16px;">
      <?php foreach ($sectionBlocks as $block): ?>
        <?php
        $payload = $blocks->decodePayload($block);
        $blockType = (string)$block['block_type'];
        $isEditable = !$isReleased && in_array($blockType, array('heading', 'paragraph'), true) && empty($block['is_system_managed']);
        ?>
        <article style="border:1px solid #e2e8f0;border-radius:8px;padding:12px;">
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:10px;">
            <div>
              <strong><?= h($blockType) ?></strong>
              <span style="font-size:12px;color:#64748b;margin-left:8px;">#<?= (int)$block['id'] ?></span>
            </div>
            <code style="font-size:11px;"><?= h((string)$block['stable_anchor']) ?></code>
          </div>

          <?php if ($blockType === 'generated_placeholder'): ?>
            <p style="margin:0;color:#64748b;font-style:italic;"><?= h((string)($payload['message'] ?? 'Generated placeholder')) ?></p>
          <?php elseif ($isEditable): ?>
            <form method="post" style="display:grid;gap:10px;">
              <input type="hidden" name="action" value="update_block">
              <input type="hidden" name="block_id" value="<?= (int)$block['id'] ?>">
              <?php if ($blockType === 'heading'): ?>
                <label style="display:grid;gap:4px;">
                  <span>Heading level</span>
                  <select name="level" style="max-width:120px;padding:6px;border-radius:6px;">
                    <?php for ($level = 1; $level <= 6; $level++): ?>
                      <option value="<?= $level ?>" <?= (int)($payload['level'] ?? 2) === $level ? 'selected' : '' ?>>H<?= $level ?></option>
                    <?php endfor; ?>
                  </select>
                </label>
              <?php endif; ?>
              <label style="display:grid;gap:4px;">
                <span>Text</span>
                <textarea name="text" rows="4" style="width:100%;padding:8px;border-radius:6px;border:1px solid #cbd5e1;"><?= h((string)($payload['text'] ?? '')) ?></textarea>
              </label>
              <div style="display:flex;gap:8px;">
                <button type="submit">Save block</button>
              </div>
            </form>
            <form method="post" style="margin-top:8px;" onsubmit="return confirm('Delete this block?');">
              <input type="hidden" name="action" value="delete_block">
              <input type="hidden" name="block_id" value="<?= (int)$block['id'] ?>">
              <button type="submit" style="color:#b91c1c;">Delete</button>
            </form>
          <?php else: ?>
            <?php if ($blockType === 'heading'): ?>
              <p style="margin:0;"><strong>H<?= (int)($payload['level'] ?? 2) ?>:</strong> <?= h((string)($payload['text'] ?? '')) ?></p>
            <?php else: ?>
              <p style="margin:0;"><?= nl2br(h((string)($payload['text'] ?? ''))) ?></p>
            <?php endif; ?>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php if (!$isReleased): ?>
<section class="cmp-card" style="margin-top:16px;">
  <h2 style="margin:0 0 12px;">Add block</h2>
  <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));">
    <form method="post" style="border:1px solid #e2e8f0;border-radius:8px;padding:12px;display:grid;gap:10px;">
      <input type="hidden" name="action" value="create_block">
      <input type="hidden" name="block_type" value="heading">
      <strong>Heading</strong>
      <label style="display:grid;gap:4px;">
        <span>Level</span>
        <select name="level" style="max-width:120px;padding:6px;border-radius:6px;">
          <?php for ($level = 1; $level <= 6; $level++): ?>
            <option value="<?= $level ?>" <?= $level === 2 ? 'selected' : '' ?>>H<?= $level ?></option>
          <?php endfor; ?>
        </select>
      </label>
      <label style="display:grid;gap:4px;">
        <span>Text</span>
        <textarea name="text" rows="3" style="width:100%;padding:8px;border-radius:6px;border:1px solid #cbd5e1;" placeholder="Section heading"></textarea>
      </label>
      <button type="submit">Add heading</button>
    </form>

    <form method="post" style="border:1px solid #e2e8f0;border-radius:8px;padding:12px;display:grid;gap:10px;">
      <input type="hidden" name="action" value="create_block">
      <input type="hidden" name="block_type" value="paragraph">
      <strong>Paragraph</strong>
      <label style="display:grid;gap:4px;">
        <span>Text</span>
        <textarea name="text" rows="5" style="width:100%;padding:8px;border-radius:6px;border:1px solid #cbd5e1;" placeholder="Body text"></textarea>
      </label>
      <button type="submit">Add paragraph</button>
    </form>
  </div>
</section>
<?php endif; ?>
<?php

compliance_page_close();
cw_footer();
