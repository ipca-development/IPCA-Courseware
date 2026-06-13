<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingMccfBrowserService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingMccfRegulationLinkService.php';

$user = compliance_require_access($pdo);
$svc = new ControlledPublishingMccfBrowserService($pdo);
$regSvc = new ControlledPublishingMccfRegulationLinkService($pdo);
$regLinksAvailable = ControlledPublishingMccfRegulationLinkService::regulationLinksTablePresent($pdo);

$layout = strtolower(trim((string)($_GET['layout'] ?? 'bcaa')));
if (!in_array($layout, array('bcaa', 'coverage'), true)) {
    $layout = 'bcaa';
}

$sourceSets = $svc->listMccfSourceSets();
$sourceSetId = $svc->resolveSourceSetId(
    (int)($_GET['source_set_id'] ?? 0),
    trim((string)($_GET['source_set'] ?? ''))
);
$sourceSet = $svc->sourceSetById($sourceSetId);

$part = trim((string)($_GET['part'] ?? ''));
$coverage = trim((string)($_GET['coverage'] ?? 'all'));
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$requirementId = (int)($_GET['req'] ?? 0);

$parts = $sourceSetId > 0 ? $svc->listParts($sourceSetId) : array();
$summary = $sourceSetId > 0 ? $svc->coverageSummary($sourceSetId) : array('total' => 0, 'linked' => 0, 'unlinked' => 0, 'by_part' => array());
$search = $sourceSetId > 0
    ? $svc->searchRequirements($sourceSetId, array(
        'part' => $part,
        'coverage' => $coverage,
        'q' => $q,
    ), $page, 50)
    : array('rows' => array(), 'total' => 0, 'page' => 1, 'per_page' => 50);
$detail = $requirementId > 0 ? $svc->getRequirement($requirementId) : null;
$detailRegLinks = ($requirementId > 0 && $regLinksAvailable)
    ? $regSvc->listLinksForRequirement($requirementId)
    : array();
$detailRegParsed = is_array($detail)
    ? ControlledPublishingMccfRegulationLinkService::parseRegulationRef((string)($detail['regulation_ref'] ?? ''))
    : array();

$bcaaSections = ($sourceSetId > 0 && $layout === 'bcaa')
    ? $regSvc->listBcaaPartSections($sourceSetId, array(
        'part' => $part,
        'coverage' => $coverage,
        'q' => $q,
    ))
    : array();

$totalPages = $search['per_page'] > 0
    ? (int)max(1, ceil($search['total'] / $search['per_page']))
    : 1;

function mccf_browser_query(array $overrides = array()): string
{
    $params = array_merge(array(
        'source_set_id' => (int)($_GET['source_set_id'] ?? 0),
        'part' => trim((string)($_GET['part'] ?? '')),
        'coverage' => trim((string)($_GET['coverage'] ?? 'all')),
        'q' => trim((string)($_GET['q'] ?? '')),
        'page' => max(1, (int)($_GET['page'] ?? 1)),
        'req' => (int)($_GET['req'] ?? 0),
        'layout' => strtolower(trim((string)($_GET['layout'] ?? 'bcaa'))),
    ), $overrides);
    if ((int)$params['source_set_id'] <= 0) {
        unset($params['source_set_id']);
    }
    if ($params['part'] === '') {
        unset($params['part']);
    }
    if ($params['coverage'] === '' || $params['coverage'] === 'all') {
        unset($params['coverage']);
    }
    if ($params['q'] === '') {
        unset($params['q']);
    }
    if ((int)$params['page'] <= 1) {
        unset($params['page']);
    }
    if ((int)$params['req'] <= 0) {
        unset($params['req']);
    }
    if (($params['layout'] ?? 'bcaa') === 'bcaa') {
        unset($params['layout']);
    }
    $query = http_build_query($params);
    return $query !== '' ? ('?' . $query) : '';
}

$titleSuffix = is_array($sourceSet) ? (string)($sourceSet['source_set_key'] ?? '') : '';

cw_header('Compliance · MCCF Browser');

compliance_page_open(array(
    'overline' => 'Compliance · Controlled publishing',
    'title' => 'MCCF Requirements Browser',
    'description' => 'BCAA-format MCCF checklist view with manual coverage and automatic links to applicable EASA regulation sources in Resource Library.',
    'back' => array(
        'href' => '/admin/compliance/controlled_books.php',
        'label' => 'Controlled Books',
    ),
    'stats' => array(
        array('label' => 'Source set', 'value' => $titleSuffix !== '' ? $titleSuffix : '—'),
        array('label' => 'Requirements', 'value' => (int)($summary['total'] ?? 0)),
        array(
            'label' => 'Linked',
            'value' => (int)($summary['linked'] ?? 0),
            'tone' => 'ok',
        ),
        array(
            'label' => 'Unlinked',
            'value' => (int)($summary['unlinked'] ?? 0),
            'tone' => ((int)($summary['unlinked'] ?? 0) > 0) ? 'warn' : 'ok',
        ),
    ),
    'actions' => array(
        array(
            'label' => 'EASA Resource Library',
            'href' => '/admin/resource_library.php?tab=easa',
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
<style>
  .mccf-layout { display: grid; gap: 16px; }
  .mccf-filters { display: grid; gap: 12px; }
  .mccf-filters-row { display: grid; grid-template-columns: 1.2fr 1fr 1fr 1.4fr auto; gap: 10px; align-items: end; }
  @media (max-width: 960px) { .mccf-filters-row { grid-template-columns: 1fr; } }
  .mccf-filters label { display: grid; gap: 6px; font-size: 13px; font-weight: 600; color: #334155; }
  .mccf-filters input, .mccf-filters select { padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; }
  .mccf-coverage-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; }
  .mccf-coverage-chip { border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 12px; background: #fff; font-size: 12px; }
  .mccf-coverage-chip strong { display: block; font-size: 13px; margin-bottom: 4px; color: #0f172a; }
  .mccf-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
  .mccf-badge--linked { background: #dcfce7; color: #166534; }
  .mccf-badge--unlinked { background: #fef3c7; color: #92400e; }
  .mccf-badge--legacy { background: #e0e7ff; color: #3730a3; }
  .mccf-detail { border: 1px solid #bfdbfe; background: #f8fbff; border-radius: 12px; padding: 16px 18px; }
  .mccf-detail h3 { margin: 0 0 8px; font-size: 16px; }
  .mccf-detail-meta { font-size: 12px; color: #64748b; margin-bottom: 12px; }
  .mccf-detail-text { font-size: 13px; line-height: 1.55; color: #334155; white-space: pre-wrap; }
  .mccf-excerpt-list { margin: 12px 0 0; padding-left: 18px; font-size: 13px; }
  .mccf-excerpt-list li { margin-bottom: 8px; }
  .mccf-pagination { display: flex; gap: 10px; align-items: center; font-size: 13px; margin-top: 12px; }
  .mccf-subject { max-width: 320px; }
  .mccf-key { font-family: ui-monospace, monospace; font-size: 11px; color: #475569; }
  .mccf-bcaa-part { margin-bottom: 20px; }
  .mccf-bcaa-part h3 { margin: 0 0 10px; font-size: 15px; color: #0f172a; }
  .mccf-bcaa-table { width: 100%; border-collapse: collapse; font-size: 12px; min-width: 1100px; }
  .mccf-bcaa-table th, .mccf-bcaa-table td { border: 1px solid #cbd5e1; padding: 7px 8px; vertical-align: top; }
  .mccf-bcaa-table th { background: #f1f5f9; font-size: 11px; text-align: left; }
  .mccf-bcaa-table .col-item { width: 48px; }
  .mccf-bcaa-table .col-sub { width: 56px; }
  .mccf-reg-links { margin: 10px 0 0; padding: 0; list-style: none; font-size: 12px; }
  .mccf-reg-links li { margin-bottom: 6px; }
  .mccf-layout-toggle { display: flex; gap: 8px; margin-bottom: 12px; font-size: 13px; }
  .mccf-layout-toggle a { padding: 6px 10px; border-radius: 8px; border: 1px solid #cbd5e1; text-decoration: none; color: #334155; }
  .mccf-layout-toggle a.is-active { background: #0f172a; color: #fff; border-color: #0f172a; }
</style>

<div class="mccf-layout">
  <?php if ($sourceSets === array()): ?>
    <section class="cmp-card">
      <p style="margin:0;">No MCCF source sets found. Run the legacy canonical sync first (<code>php scripts/sync_legacy_compliance_canonical_sources.php --apply</code>).</p>
    </section>
  <?php else: ?>

    <?php if (is_array($detail)): ?>
      <section class="mccf-detail cmp-card" style="padding:16px 18px;">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;">
          <div>
            <h3><?= h((string)($detail['subject'] ?? 'Requirement')) ?></h3>
            <div class="mccf-detail-meta">
              <span class="mccf-key"><?= h((string)($detail['requirement_key'] ?? '')) ?></span>
              · <?= h((string)($detail['manual_part'] ?? '')) ?>
              · Item <?= h(ControlledPublishingMccfBrowserService::formatItemRef($detail)) ?>
              <?php if (trim((string)($detail['regulation_ref'] ?? '')) !== ''): ?>
                · <?= h((string)$detail['regulation_ref']) ?>
              <?php endif; ?>
            </div>
          </div>
          <a href="<?= h(mccf_browser_query(array('req' => 0))) ?>" style="font-size:13px;">Close detail</a>
        </div>
        <?php if (trim((string)($detail['manual_section_ref'] ?? '')) !== ''): ?>
          <p style="margin:0 0 10px;font-size:13px;"><strong>Manual section ref:</strong> <?= h((string)$detail['manual_section_ref']) ?></p>
        <?php endif; ?>
        <div class="mccf-detail-text"><?= h((string)($detail['requirement_text'] ?? '')) ?></div>
        <?php if (trim((string)($detail['remarks'] ?? '')) !== ''): ?>
          <p style="margin:12px 0 0;font-size:13px;color:#64748b;"><strong>Remarks / revision abstract:</strong> <?= h((string)$detail['remarks']) ?></p>
        <?php endif; ?>
        <?php if (trim((string)($detail['regulation_ref'] ?? '')) !== ''): ?>
          <h4 style="margin:16px 0 8px;font-size:14px;">Applicable regulation source</h4>
          <p style="margin:0 0 8px;font-size:13px;"><?= h((string)$detail['regulation_ref']) ?></p>
          <?php if ($detailRegLinks !== array()): ?>
            <ul class="mccf-reg-links">
              <?php foreach ($detailRegLinks as $regLink): ?>
                <?php
                $batchId = (int)($regLink['target_batch_id'] ?? 0);
                $nodeUid = trim((string)($regLink['target_node_uid'] ?? ''));
                $token = (string)($regLink['rule_token'] ?? '');
                $resolved = ($regLink['match_confidence'] ?? '') !== 'UNRESOLVED' && $batchId > 0 && $nodeUid !== '';
                ?>
                <li>
                  <strong><?= h($token) ?></strong>
                  <span class="mccf-badge <?= $resolved ? 'mccf-badge--linked' : 'mccf-badge--unlinked' ?>" style="margin-left:6px;">
                    <?= h((string)($regLink['match_confidence'] ?? 'AUTO')) ?>
                  </span>
                  <?php if ($resolved): ?>
                    · <a href="<?= h(ControlledPublishingMccfRegulationLinkService::resourceLibraryEasaHref($batchId, $nodeUid)) ?>">Open in EASA Resource Library</a>
                  <?php else: ?>
                    · <a href="<?= h(ControlledPublishingMccfRegulationLinkService::regulationsSearchHref($token)) ?>">Search regulations</a>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php elseif ($detailRegParsed !== array()): ?>
            <ul class="mccf-reg-links">
              <?php foreach ($detailRegParsed as $parsed): ?>
                <li>
                  <strong><?= h((string)$parsed['token']) ?></strong>
                  · <a href="<?= h(ControlledPublishingMccfRegulationLinkService::regulationsSearchHref((string)$parsed['token'])) ?>">Search EASA</a>
                  <?php if (!$regLinksAvailable): ?>
                    <span style="color:#64748b;"> (apply regulation-links migration + auto-link script)</span>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        <?php endif; ?>
        <h4 style="margin:16px 0 8px;font-size:14px;">Linked manual excerpts (<?= (int)($detail['link_count'] ?? 0) ?>)</h4>
        <?php if (!empty($detail['linked_excerpts']) && is_array($detail['linked_excerpts'])): ?>
          <ul class="mccf-excerpt-list">
            <?php foreach ($detail['linked_excerpts'] as $excerpt): ?>
              <li>
                <strong><?= h((string)($excerpt['excerpt_key'] ?? '')) ?></strong>
                <?php if (trim((string)($excerpt['excerpt_title'] ?? '')) !== ''): ?>
                  — <?= h((string)$excerpt['excerpt_title']) ?>
                <?php endif; ?>
                <?php if (trim((string)($excerpt['excerpt_section_ref'] ?? '')) !== ''): ?>
                  <span style="color:#64748b;"> (§<?= h((string)$excerpt['excerpt_section_ref']) ?>)</span>
                <?php endif; ?>
                <span class="mccf-badge mccf-badge--linked" style="margin-left:6px;"><?= h((string)($excerpt['link_type'] ?? 'PRIMARY')) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php elseif (trim((string)($detail['legacy_excerpt_id'] ?? '')) !== ''): ?>
          <p style="margin:0;font-size:13px;color:#64748b;">No active link row, but legacy inline excerpt id: <code><?= h((string)$detail['legacy_excerpt_id']) ?></code></p>
        <?php else: ?>
          <p style="margin:0;font-size:13px;color:#b45309;">No manual excerpt linked to this requirement.</p>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <section class="cmp-card">
      <h2 style="margin:0 0 12px;">Filters</h2>
      <form method="get" class="mccf-filters">
        <input type="hidden" name="layout" value="<?= h($layout) ?>">
        <div class="mccf-filters-row">
          <label>
            <span>MCCF source set</span>
            <select name="source_set_id" onchange="this.form.submit()">
              <?php foreach ($sourceSets as $set): ?>
                <?php $sid = (int)($set['id'] ?? 0); ?>
                <option value="<?= $sid ?>" <?= $sid === $sourceSetId ? 'selected' : '' ?>>
                  <?= h((string)($set['source_set_key'] ?? '')) ?>
                  (<?= (int)($set['requirements'] ?? 0) ?> req)
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span>Manual part</span>
            <select name="part">
              <option value="">All parts</option>
              <?php foreach ($parts as $partOpt): ?>
                <option value="<?= h($partOpt) ?>" <?= $partOpt === $part ? 'selected' : '' ?>><?= h($partOpt) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span>Coverage</span>
            <select name="coverage">
              <option value="all" <?= $coverage === 'all' ? 'selected' : '' ?>>All</option>
              <option value="linked" <?= $coverage === 'linked' ? 'selected' : '' ?>>Linked only</option>
              <option value="unlinked" <?= $coverage === 'unlinked' ? 'selected' : '' ?>>Unlinked only</option>
            </select>
          </label>
          <label>
            <span>Search</span>
            <input type="search" name="q" value="<?= h($q) ?>" placeholder="Subject, key, section ref, regulation…">
          </label>
          <button type="submit">Apply</button>
        </div>
      </form>
    </section>

    <?php if (($summary['by_part'] ?? array()) !== array()): ?>
      <section class="cmp-card">
        <h2 style="margin:0 0 12px;">Coverage by part</h2>
        <div class="mccf-coverage-grid">
          <?php foreach ($summary['by_part'] as $partRow): ?>
            <?php
            $partLabel = (string)($partRow['part'] ?? '—');
            $partLinked = (int)($partRow['linked'] ?? 0);
            $partTotal = (int)($partRow['total'] ?? 0);
            $partHref = mccf_browser_query(array(
                'part' => $partLabel === '—' ? '' : $partLabel,
                'page' => 1,
                'req' => 0,
            ));
            ?>
            <a class="mccf-coverage-chip" href="<?= h($partHref) ?>" style="text-decoration:none;color:inherit;">
              <strong><?= h($partLabel) ?></strong>
              <?= $partLinked ?> / <?= $partTotal ?> linked
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <section class="cmp-card">
      <div class="mccf-layout-toggle">
        <a href="<?= h(mccf_browser_query(array('layout' => 'bcaa', 'page' => 1))) ?>" class="<?= $layout === 'bcaa' ? 'is-active' : '' ?>">BCAA checklist layout</a>
        <a href="<?= h(mccf_browser_query(array('layout' => 'coverage', 'page' => 1))) ?>" class="<?= $layout === 'coverage' ? 'is-active' : '' ?>">Coverage view</a>
      </div>
      <h2 style="margin:0 0 12px;"><?= $layout === 'bcaa' ? 'MCCF checklist (BCAA format)' : 'Requirements' ?></h2>
      <p style="margin:0 0 12px;font-size:13px;color:#64748b;">
        <?php if ($layout === 'bcaa'): ?>
          Columns match the BCAA Word MCCF: Item, Subject, Sub-item, Description, Location, Applicable, Revision abstract, BCAA check, Finding.
          Canonical database rows are sourced from the same BCAA document used for compliance submission.
        <?php else: ?>
          Showing <?= count($search['rows']) ?> of <?= (int)$search['total'] ?> matching rows
        <?php endif; ?>
        <?php if (is_array($sourceSet) && !empty($sourceSet['last_synced_at'])): ?>
          · Last synced <?= h((string)$sourceSet['last_synced_at']) ?>
        <?php endif; ?>
        <?php if (!$regLinksAvailable): ?>
          · Regulation auto-links: apply <code>scripts/sql/2026_06_06_mccf_regulation_links.sql</code> then run <code>php scripts/link_mccf_regulation_sources.php --apply</code>
        <?php endif; ?>
      </p>
      <?php if ($layout === 'bcaa'): ?>
        <?php if ($bcaaSections === array()): ?>
          <p style="margin:0;">No requirements match the current filters.</p>
        <?php else: ?>
          <?php foreach ($bcaaSections as $section): ?>
            <div class="mccf-bcaa-part">
              <h3><?= h((string)$section['label']) ?></h3>
              <div style="overflow:auto;">
                <table class="mccf-bcaa-table">
                  <thead>
                    <tr>
                      <th class="col-item">Item #</th>
                      <th>Subject</th>
                      <th class="col-sub">Sub item#</th>
                      <th>Description / supplementary information</th>
                      <th>Location (Section/Chapter/Page/§)</th>
                      <th>Applicable (Yes/No)</th>
                      <th>Revision abstract / reason if N/A</th>
                      <th>BCAA Check</th>
                      <th>See finding N°</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($section['rows'] as $row): ?>
                      <?php
                      $rid = (int)($row['id'] ?? 0);
                      $itemNo = trim((string)($row['item_no'] ?? ''));
                      $subNo = trim((string)($row['sub_item_no'] ?? ''));
                      ?>
                      <tr>
                        <td><?= h($itemNo !== '' ? $itemNo : '—') ?></td>
                        <td><?= h((string)($row['subject'] ?? '')) ?></td>
                        <td><?= h($subNo !== '' ? $subNo : '—') ?></td>
                        <td>
                          <a href="<?= h(mccf_browser_query(array('req' => $rid))) ?>"><?= h((string)($row['requirement_text'] ?? '')) ?></a>
                        </td>
                        <td><?= h((string)($row['manual_section_ref'] ?? '')) ?></td>
                        <td><?= h((string)($row['applicable'] ?? '')) ?></td>
                        <td><?= h((string)($row['remarks'] ?? '')) ?></td>
                        <td></td>
                        <td><?= h((string)($row['finding_ref'] ?? '')) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php elseif ($search['rows'] === array()): ?>
        <p style="margin:0;">No requirements match the current filters.</p>
      <?php else: ?>
        <div style="overflow:auto;">
          <table class="cmp-table" style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
              <tr>
                <th align="left">Part</th>
                <th align="left">Item</th>
                <th align="left">Subject</th>
                <th align="left">Manual section ref</th>
                <th align="left">Regulation</th>
                <th align="left">Coverage</th>
                <th align="left"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($search['rows'] as $row): ?>
                <?php
                $rid = (int)($row['id'] ?? 0);
                $linkCount = (int)($row['link_count'] ?? 0);
                $legacy = trim((string)($row['legacy_excerpt_id'] ?? ''));
                $badgeClass = $linkCount > 0 ? 'mccf-badge--linked' : ($legacy !== '' ? 'mccf-badge--legacy' : 'mccf-badge--unlinked');
                $coverageLabel = ControlledPublishingMccfBrowserService::coverageLabel($linkCount, $legacy);
                ?>
                <tr>
                  <td><?= h((string)($row['manual_part'] ?? '')) ?></td>
                  <td><?= h(ControlledPublishingMccfBrowserService::formatItemRef($row)) ?></td>
                  <td class="mccf-subject">
                    <div><?= h((string)($row['subject'] ?? '')) ?></div>
                    <div class="mccf-key"><?= h((string)($row['requirement_key'] ?? '')) ?></div>
                  </td>
                  <td style="max-width:220px;"><?= h((string)($row['manual_section_ref'] ?? '')) ?></td>
                  <td style="max-width:160px;"><?= h((string)($row['regulation_ref'] ?? '')) ?></td>
                  <td><span class="mccf-badge <?= h($badgeClass) ?>"><?= h($coverageLabel) ?></span></td>
                  <td><a href="<?= h(mccf_browser_query(array('req' => $rid))) ?>">View</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($totalPages > 1): ?>
          <div class="mccf-pagination">
            <?php if ($page > 1): ?>
              <a href="<?= h(mccf_browser_query(array('page' => $page - 1))) ?>">← Previous</a>
            <?php endif; ?>
            <span>Page <?= (int)$page ?> of <?= (int)$totalPages ?></span>
            <?php if ($page < $totalPages): ?>
              <a href="<?= h(mccf_browser_query(array('page' => $page + 1))) ?>">Next →</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div>
<?php

compliance_page_close();
cw_footer();
