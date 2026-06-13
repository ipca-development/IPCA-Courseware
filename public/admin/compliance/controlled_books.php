<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingFoundationService.php';

$user = compliance_require_access($pdo);
$svc = new ControlledPublishingFoundationService($pdo);
$rows = $svc->listBooksWithVersions();

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
