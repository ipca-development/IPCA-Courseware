<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingFoundationService.php';

$user = compliance_require_access($pdo);
$svc = new ControlledPublishingFoundationService($pdo);

$inventory = $svc->canonicalSourceSetInventory();
$broken = $svc->brokenCanonicalLinks(50);
$syncRuns = $pdo->query("
    SELECT
      sr.id,
      s.source_key,
      ss.source_set_key,
      sr.status,
      sr.dry_run,
      sr.started_at,
      sr.completed_at,
      sr.source_inventory_hash
    FROM ipca_canonical_sync_runs sr
    INNER JOIN ipca_canonical_sources s ON s.id = sr.source_id
    LEFT JOIN ipca_canonical_source_sets ss ON ss.id = sr.source_set_id
    ORDER BY sr.started_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC) ?: array();

cw_header('Compliance · Canonical Sources');

compliance_page_open(array(
    'overline' => 'Compliance · Controlled publishing',
    'title' => 'Canonical Sources',
    'description' => 'Read-only inventory of local canonical source sets, sync history, and link integrity.',
    'stats' => array(
        array('label' => 'Source sets', 'value' => count($inventory)),
        array('label' => 'Broken links', 'value' => count($broken), 'tone' => count($broken) > 0 ? 'warn' : 'ok'),
    ),
));

?>
<section class="cmp-card">
  <h2 style="margin:0 0 12px;">Source set inventory</h2>
  <?php if ($inventory === array()): ?>
    <p style="margin:0;">No canonical source sets found.</p>
  <?php else: ?>
    <div style="overflow:auto;">
      <table class="cmp-table" style="width:100%;border-collapse:collapse;">
        <thead>
          <tr>
            <th align="left">Key</th>
            <th align="left">Family</th>
            <th align="left">Revision</th>
            <th align="right">Req</th>
            <th align="right">Excerpts</th>
            <th align="right">Links</th>
            <th align="left">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($inventory as $row): ?>
            <tr>
              <td><?= h((string)$row['source_set_key']) ?></td>
              <td><?= h((string)$row['source_family']) ?></td>
              <td><?= h((string)$row['revision_label']) ?></td>
              <td align="right"><?= (int)$row['requirements'] ?></td>
              <td align="right"><?= (int)$row['excerpts'] ?></td>
              <td align="right"><?= (int)$row['requirement_excerpt_links'] ?></td>
              <td><?= h((string)$row['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="cmp-card" style="margin-top:16px;">
  <h2 style="margin:0 0 12px;">Latest sync runs</h2>
  <?php if ($syncRuns === array()): ?>
    <p style="margin:0;">No sync runs recorded.</p>
  <?php else: ?>
    <ul style="margin:0;padding-left:18px;">
      <?php foreach ($syncRuns as $run): ?>
        <li>
          #<?= (int)$run['id'] ?> · <?= h((string)$run['status']) ?>
          <?= !empty($run['dry_run']) ? '(dry-run)' : '(apply)' ?> ·
          <?= h((string)$run['started_at']) ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="cmp-card" style="margin-top:16px;">
  <h2 style="margin:0 0 12px;">Broken requirement/excerpt links</h2>
  <?php if ($broken === array()): ?>
    <p style="margin:0;">None detected.</p>
  <?php else: ?>
    <ul style="margin:0;padding-left:18px;">
      <?php foreach ($broken as $row): ?>
        <li>
          #<?= (int)$row['id'] ?> · <?= h((string)$row['requirement_key']) ?> → <?= h((string)$row['excerpt_key']) ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php

compliance_page_close();
cw_footer();
