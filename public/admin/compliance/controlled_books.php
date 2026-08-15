<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingFoundationService.php';

$user = compliance_require_access($pdo);
$svc = new ControlledPublishingFoundationService($pdo);
$uid = (int)($user['id'] ?? 0);

function cpb_books_flash(string $type, string $message): void
{
    $_SESSION['_cpb_books_flash'] = array('type' => $type, 'message' => $message);
}

function cpb_books_flash_take(): ?array
{
    $flash = $_SESSION['_cpb_books_flash'] ?? null;
    unset($_SESSION['_cpb_books_flash']);
    return is_array($flash) ? $flash : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ((string)($_POST['action'] ?? '') !== 'create_manual') {
            throw new RuntimeException('Unknown controlled-book action.');
        }
        $created = $svc->createManualFromVersion(
            (int)($_POST['source_version_id'] ?? 0),
            (string)($_POST['book_key'] ?? ''),
            (string)($_POST['title'] ?? ''),
            (string)($_POST['version_label'] ?? ''),
            !empty($_POST['copy_content']),
            $uid
        );
        cpb_books_flash(
            'success',
            'Manual ' . (string)$created['book_key'] . ' created as revision '
                . (string)$created['version_label'] . '.'
        );
        redirect('/admin/compliance/controlled_book_version.php?id=' . (int)$created['version_id']);
    } catch (Throwable $e) {
        cpb_books_flash('error', $e->getMessage());
        redirect('/admin/compliance/controlled_books.php');
    }
}

$rows = $svc->listBooksWithVersions();
$flash = cpb_books_flash_take();

cw_header('Compliance · Controlled Books');

compliance_page_open(array(
    'overline' => 'Compliance · Controlled publishing',
    'title' => 'Controlled Books',
    'description' => 'Book registry and draft versions with source baseline foundation status.',
    'stats' => array(
        array('label' => 'Rows', 'value' => count($rows)),
    ),
    'actions' => array(
        array(
            'label' => 'MCCF Browser',
            'href' => '/admin/compliance/mccf_browser.php',
            'variant' => 'secondary',
        ),
        array(
            'label' => 'Canonical Sources',
            'href' => '/admin/compliance/canonical_sources.php',
            'variant' => 'secondary',
        ),
    ),
));

?>
<?php if ($flash !== null): ?>
  <div class="cmp-alert cmp-alert--<?= h((string)$flash['type']) ?>" style="margin-bottom:16px;">
    <?= h((string)$flash['message']) ?>
  </div>
<?php endif; ?>
<section class="cmp-card" style="margin-bottom:16px;">
  <h2 style="margin:0 0 8px;">Create New Manual</h2>
  <p style="margin:0 0 16px;color:#64748b;font-size:13px;max-width:760px;">
    Create an independent controlled manual using an existing manual’s section structure and Book Style.
    A separate canonical import source is created automatically for Word imports.
  </p>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end;">
    <input type="hidden" name="action" value="create_manual">
    <label style="display:grid;gap:6px;font-size:13px;">
      <span>Manual code</span>
      <input type="text" name="book_key" required maxlength="32" pattern="[A-Za-z0-9][A-Za-z0-9_-]{1,31}" placeholder="SMS">
    </label>
    <label style="display:grid;gap:6px;font-size:13px;">
      <span>Manual title</span>
      <input type="text" name="title" required maxlength="255" placeholder="Safety Management Manual">
    </label>
    <label style="display:grid;gap:6px;font-size:13px;">
      <span>Initial revision</span>
      <input type="text" name="version_label" required maxlength="128" value="1.0">
    </label>
    <label style="display:grid;gap:6px;font-size:13px;">
      <span>Structure and style based on</span>
      <select name="source_version_id" required>
        <option value="">Select a manual…</option>
        <?php foreach ($rows as $row): ?>
          <?php if (!empty($row['version_id'])): ?>
            <option value="<?= (int)$row['version_id'] ?>">
              <?= h((string)$row['book_key']) ?> <?= h((string)$row['version_label']) ?>
              — <?= h((string)$row['book_title']) ?>
            </option>
          <?php endif; ?>
        <?php endforeach; ?>
      </select>
    </label>
    <label style="display:flex;gap:8px;align-items:center;font-size:13px;padding-bottom:8px;">
      <input type="checkbox" name="copy_content" value="1">
      <span>Also copy existing content</span>
    </label>
    <button type="submit" style="min-height:38px;">Create manual</button>
  </form>
  <p style="margin:12px 0 0;color:#64748b;font-size:12px;">
    With “copy existing content” off, only the hierarchy, system-managed placeholders, styles, headers and footers
    are copied. The new manual can then be populated through Import from Word.
  </p>
</section>
<section class="cmp-card">
  <?php if ($rows === array()): ?>
    <p style="margin:0;">No controlled books yet. Run <code>php scripts/seed_controlled_publishing_books.php</code>.</p>
  <?php else: ?>
    <div style="overflow:auto;">
      <table class="cmp-table" style="width:100%;border-collapse:collapse;">
        <thead>
          <tr>
            <th align="left">Book</th>
            <th align="left">Version</th>
            <th align="left">Lifecycle</th>
            <th align="right">Source sets</th>
            <th align="left">Baseline</th>
            <th align="left">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><?= h((string)$row['book_key']) ?> — <?= h((string)$row['book_title']) ?></td>
              <td><?= h((string)($row['version_label'] ?? '—')) ?></td>
              <td><?= h((string)($row['lifecycle_status'] ?? '—')) ?></td>
              <td align="right"><?= (int)($row['selected_source_sets'] ?? 0) ?></td>
              <td><?= h((string)($row['baseline_status'] ?? 'none')) ?></td>
              <td>
                <?php if (!empty($row['version_id'])): ?>
                  <a href="/admin/compliance/controlled_book_editor.php?version_id=<?= (int)$row['version_id'] ?>">Edit</a>
                  ·
                  <a href="/admin/compliance/controlled_book_version.php?id=<?= (int)$row['version_id'] ?>">Settings</a>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php

compliance_page_close();
cw_footer();
