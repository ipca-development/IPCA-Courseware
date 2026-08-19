<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsUi.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsChangePlanService.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsChangeArchitectService.php';

/** @return array<mixed> */
function bm_arch_array(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (is_string($value) && $value !== '') {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : array();
    }
    return array();
}

function bm_arch_text(mixed $value, string $fallback = ''): string
{
    $text = trim((string)$value);
    return $text !== '' ? $text : $fallback;
}

/** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
function bm_arch_group(array $rows, string $classification): array
{
    return array_values(array_filter(
        $rows,
        static fn(array $row): bool =>
            strtoupper((string)($row['classification'] ?? $row['boundary_classification'] ?? ''))
                === $classification
    ));
}

$user = compliance_require_access($pdo);
$userId = (int)($user['id'] ?? 0);
$csrf = (string)($_SESSION['books_manuals_ai_csrf'] ?? '');
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(32));
    $_SESSION['books_manuals_ai_csrf'] = $csrf;
}

$plans = new BooksManualsChangePlanService($pdo);
$planId = max(0, (int)($_GET['plan_id'] ?? $_GET['id'] ?? 0));
$report = array();
$readinessMessage = '';
$manual = array();
try {
    if (!$plans->tablesPresent()) {
        throw new RuntimeException('Install the Manual Change Architect migration to open this workspace.');
    }
    if ($planId <= 0) {
        $stmt = $pdo->prepare(
            'SELECT id FROM ipca_manual_ai_architect_plans
             WHERE owner_id=? OR status IN (?,?)
             ORDER BY updated_at DESC,id DESC LIMIT 1'
        );
        $stmt->execute(array($userId, 'ready_for_review', 'approved'));
        $planId = (int)$stmt->fetchColumn();
    }
    if ($planId <= 0) {
        throw new RuntimeException('No Manual Change Architect plan is available yet.');
    }
    $report = (new BooksManualsChangeArchitectService($pdo, $plans))
        ->getCompleteCheckpointReport($planId);
    $versionId = (int)($report['primary_manual_version_id'] ?? $report['primary_book_version_id'] ?? 0);
    $stmt = $pdo->prepare(
        'SELECT v.id,v.version_label,v.lifecycle_status,b.book_key,b.title
         FROM ipca_publishing_book_versions v
         JOIN ipca_publishing_books b ON b.id=v.book_id
         WHERE v.id=? LIMIT 1'
    );
    $stmt->execute(array($versionId));
    $manual = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
} catch (Throwable $e) {
    $readinessMessage = $e->getMessage();
}

$intents = array_values(array_filter((array)($report['change_intents'] ?? array()), 'is_array'));
$intent = $intents[0] ?? array();
$components = array_values(array_filter((array)($report['target_components'] ?? array()), 'is_array'));
$coverage = array_values(array_filter((array)($report['coverage'] ?? array()), 'is_array'));
$impacts = array_values(array_filter((array)($report['impacts'] ?? array()), 'is_array'));
$boundaries = array_values(array_filter((array)($report['boundaries'] ?? array()), 'is_array'));
$legacyHits = array_values(array_filter((array)($report['legacy_hits'] ?? array()), 'is_array'));
$impactDependencies = array_values(array_filter((array)($report['impact_dependencies'] ?? array()), 'is_array'));
$mustChange = bm_arch_group($boundaries, 'MUST_CHANGE');
$mustPreserve = bm_arch_group($boundaries, 'MUST_PRESERVE');
$outOfScope = bm_arch_group($boundaries, 'OUT_OF_SCOPE');
$reviewSeparately = bm_arch_group($boundaries, 'REVIEW_SEPARATELY');
$amendments = array_values(array_filter(
    $impacts,
    static fn(array $impact): bool => in_array(
        strtoupper((string)($impact['treatment'] ?? '')),
        array('AMEND', 'REPLACE', 'RESTRUCTURE', 'ADD', 'REMOVE_OBSOLETE'),
        true
    )
));
usort($amendments, static fn(array $a, array $b): int =>
    strnatcasecmp((string)($a['section_number'] ?? ''), (string)($b['section_number'] ?? ''))
);
$outsideLegacyCount = count(array_filter(
    $legacyHits,
    static fn(array $hit): bool => in_array(
        strtoupper((string)($hit['disposition'] ?? $hit['proposed_disposition'] ?? '')),
        array('PRESERVE_WITH_JUSTIFICATION', 'REVIEW_SEPARATELY'),
        true
    )
));
$unresolvedLegacyCount = count(array_filter(
    $legacyHits,
    static fn(array $hit): bool => !in_array(
        strtoupper((string)($hit['status'] ?? '')),
        array('DECIDED', 'RESOLVED'),
        true
    ) && trim((string)($hit['disposition'] ?? '')) === ''
));
$coverageComplete = !in_array(
    'REVIEW_REQUIRED',
    array_map(static fn(array $row): string => strtoupper((string)($row['coverage_status'] ?? '')), $coverage),
    true
);
$isOwner = (int)($report['owner_id'] ?? 0) === $userId;
$planTitle = bm_arch_text($report['title'] ?? '', 'Manual Change Architect');
$objective = bm_arch_text(
    $intent['desired_outcome'] ?? $report['objective'] ?? '',
    'Determine the smallest coherent and complete amendment architecture.'
);
$targetLifecycle = array_values(array_filter(
    $components,
    static fn(array $component): bool => in_array(
        strtolower((string)($component['component_type'] ?? '')),
        array('lifecycle', 'human_decision', 'approval', 'closure'),
        true
    )
));

cw_header('Books & Manuals · Manual Change Architect');
books_manuals_page_open(array(
    'title' => $planTitle,
    'description' => $objective,
    'back' => array(
        'href' => '/admin/books_manuals/change_projects.php',
        'label' => 'Change Projects',
        'code' => $planId > 0 ? 'Change Plan #' . $planId : '',
    ),
));
?>
<link rel="stylesheet" href="/assets/manual-change-architect.css?v=<?= (int)(@filemtime(__DIR__ . '/../../assets/manual-change-architect.css') ?: time()) ?>">

<main
  class="mca"
  data-mca-workspace
  data-plan-id="<?= $planId ?>"
  data-api-url="/admin/api/books_manuals_change_architect_api.php"
  data-csrf-token="<?= h($csrf) ?>"
>
  <?php if ($report === array()): ?>
    <section class="cmp-card mca-unavailable">
      <span class="mca-eyebrow">Manual Change Architect</span>
      <h2>Impact workspace unavailable</h2>
      <p><?= h($readinessMessage !== '' ? $readinessMessage : 'The Change Plan could not be loaded.') ?></p>
    </section>
  <?php else: ?>
    <div class="mca-shell">
      <aside class="mca-rail" aria-label="Manual Change Architect stages">
        <div class="mca-rail__heading">
          <span class="mca-eyebrow">Change Plan</span>
          <strong><?= h((string)($manual['book_key'] ?? 'Manual')) ?> <?= h((string)($manual['version_label'] ?? '')) ?></strong>
          <small><?= h(ucwords(str_replace('_', ' ', (string)($report['status'] ?? 'active')))) ?></small>
        </div>
        <nav class="mca-stage-nav">
          <?php foreach (array(
              array('request', '01', 'Change Request', 'complete'),
              array('impact', '02', 'Impact Analysis', 'active'),
              array('structure', '03', 'Proposed Structure', 'pending'),
              array('drafts', '04', 'Draft Amendments', 'pending'),
              array('review', '05', 'Independent Review', 'pending'),
              array('apply', '06', 'Apply', 'pending'),
          ) as [$key, $number, $label, $state]): ?>
            <button type="button" class="mca-stage-nav__item is-<?= h($state) ?>" <?= $key === 'impact' ? 'aria-current="step"' : 'disabled' ?>>
              <span><?= h($number) ?></span>
              <span><strong><?= h($label) ?></strong><small><?= $state === 'complete' ? 'Complete' : ($state === 'active' ? 'In review' : 'Not started') ?></small></span>
            </button>
          <?php endforeach; ?>
        </nav>
        <div class="mca-rail__guard">
          <strong>Publishing boundary</strong>
          <p>This workspace does not finalize, approve or publish a manual.</p>
        </div>
      </aside>

      <section class="mca-center">
        <header class="mca-summary">
          <div>
            <span class="mca-eyebrow">Impact Analysis</span>
            <h1><?= h(bm_arch_text($intent['title'] ?? '', $planTitle)) ?></h1>
            <p><?= h(bm_arch_text($intent['statement'] ?? $report['change_request'] ?? '', $objective)) ?></p>
          </div>
          <span class="mca-coverage-state <?= $coverageComplete ? 'is-complete' : 'is-review' ?>">
            <?= $coverageComplete ? 'Target-state coverage complete' : 'Coverage review required' ?>
          </span>
          <dl class="mca-summary__stats">
            <div><dt><?= count($amendments) ?></dt><dd>Amendment areas</dd></div>
            <div><dt><?= count($mustPreserve) ?></dt><dd>Preserve unchanged</dd></div>
            <div><dt><?= $outsideLegacyCount ?></dt><dd>Legacy references outside scope</dd></div>
            <div><dt><?= $unresolvedLegacyCount ?></dt><dd>Unresolved items</dd></div>
          </dl>
        </header>

        <?php if ($targetLifecycle !== array()): ?>
          <section class="mca-target-state" aria-label="Operational target state">
            <div class="mca-section-heading">
              <div><span class="mca-eyebrow">Operational target state</span><h2>Governed occurrence lifecycle</h2></div>
              <span><?= count($components) ?> accountable components</span>
            </div>
            <div class="mca-lifecycle">
              <?php foreach ($targetLifecycle as $index => $component): ?>
                <div><span><?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></span><strong><?= h(bm_arch_text($component['title'] ?? '', 'Lifecycle control')) ?></strong></div>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>

        <section class="mca-amendments">
          <div class="mca-section-heading mca-section-heading--prominent">
            <div><span class="mca-eyebrow">Architect conclusion</span><h2>What should actually be amended?</h2></div>
            <p>The smallest coherent set of controlled-manual changes.</p>
          </div>
          <div class="mca-amendment-list">
            <?php foreach ($amendments as $index => $impact): ?>
              <?php
                $impactId = (int)($impact['id'] ?? 0);
                $treatment = strtoupper((string)($impact['treatment'] ?? 'AMEND'));
                $concepts = bm_arch_array($impact['target_concepts_json'] ?? $impact['target_concepts'] ?? array());
                $dependencies = bm_arch_array($impact['dependencies_json'] ?? $impact['dependencies'] ?? array());
                foreach ($impactDependencies as $dependency) {
                    if ((int)($dependency['impact_id'] ?? 0) === $impactId) {
                        $dependencies[] = bm_arch_text(
                            $dependency['depends_on_section_number'] ?? $dependency['dependency_reference'] ?? '',
                            bm_arch_text($dependency['dependency_type'] ?? '', 'Related area')
                        );
                    }
                }
                $status = strtolower((string)($impact['status'] ?? 'proposed'));
              ?>
              <article
                class="mca-amendment <?= $index === 0 ? 'is-selected' : '' ?>"
                data-mca-impact-card
                data-impact-id="<?= $impactId ?>"
                tabindex="0"
              >
                <div class="mca-amendment__top">
                  <div>
                    <span class="mca-section-ref"><?= h((string)($impact['section_number'] ?? 'Section')) ?></span>
                    <h3><?= h(bm_arch_text($impact['section_title'] ?? $impact['title'] ?? '', 'Controlled manual area')) ?></h3>
                  </div>
                  <span class="mca-treatment mca-treatment--<?= h(strtolower($treatment)) ?>"><?= h($treatment) ?></span>
                </div>
                <p class="mca-amendment__rationale"><?= h(bm_arch_text($impact['substantive_rationale'] ?? $impact['description'] ?? '', 'Review the governed rationale for this amendment area.')) ?></p>
                <?php if ($concepts !== array()): ?>
                  <div class="mca-concepts"><strong>Covers</strong><?php foreach ($concepts as $concept): ?><span><?= h(is_array($concept) ? bm_arch_text($concept['title'] ?? $concept['concept'] ?? '', 'Control') : (string)$concept) ?></span><?php endforeach; ?></div>
                <?php endif; ?>
                <?php if ($dependencies !== array()): ?>
                  <div class="mca-dependencies"><strong>Depends on</strong><span><?= h(implode(' · ', array_values(array_unique(array_filter(array_map(static fn(mixed $item): string => is_array($item) ? bm_arch_text($item['section_number'] ?? $item['title'] ?? '', '') : trim((string)$item), $dependencies)))))) ?></span></div>
                <?php endif; ?>
                <footer>
                  <button type="button" class="mca-link-button" data-mca-open-inspector="<?= $impactId ?>">Review rationale &amp; evidence</button>
                  <span class="mca-decision-status is-<?= h($status) ?>" data-mca-card-status><?= h(ucwords(str_replace('_', ' ', $status))) ?></span>
                </footer>
              </article>
            <?php endforeach; ?>
          </div>
        </section>

        <?php foreach (array(
            array('Must preserve', 'Existing valid manual logic that remains physically and semantically unchanged.', $mustPreserve, 'preserve'),
            array('Out of scope', 'Sections reviewed and deliberately excluded from this Change Plan.', $outOfScope, 'out'),
            array('Related issue — review separately', 'Relevant issues that require a separate governed decision and do not authorize drafting here.', $reviewSeparately, 'review'),
        ) as [$heading, $description, $rows, $tone]): ?>
          <?php if ($rows !== array()): ?>
            <section class="mca-boundary mca-boundary--<?= h($tone) ?>">
              <div class="mca-section-heading"><div><span class="mca-eyebrow"><?= h(strtoupper($heading)) ?></span><h2><?= h($heading) ?></h2></div><p><?= h($description) ?></p></div>
              <div class="mca-boundary__grid">
                <?php foreach ($rows as $row): ?>
                  <article>
                    <strong><?= h(bm_arch_text($row['subject_reference'] ?? $row['section_number'] ?? '', 'Governed area')) ?></strong>
                    <p><?= h(bm_arch_text($row['rationale'] ?? '', 'No amendment is authorized under this Change Plan.')) ?></p>
                  </article>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($legacyHits !== array()): ?>
          <section class="mca-legacy">
            <div class="mca-section-heading">
              <div><span class="mca-eyebrow">Governed accounting</span><h2>Legacy references and dispositions</h2></div>
              <span><?= count($legacyHits) ?> exact references</span>
            </div>
            <div class="mca-legacy__rows">
              <?php foreach ($legacyHits as $hit): ?>
                <?php $disposition = strtoupper((string)($hit['disposition'] ?? $hit['proposed_disposition'] ?? 'REVIEW_SEPARATELY')); ?>
                <div>
                  <span><strong><?= h(bm_arch_text($hit['legacy_identity'] ?? $hit['matched_text'] ?? '', 'Legacy reference')) ?></strong><small><?= h(bm_arch_text($hit['section_reference'] ?? $hit['section_number'] ?? '', 'Canonical section')) ?></small></span>
                  <span class="mca-disposition"><?= h(str_replace('_', ' ', $disposition)) ?></span>
                  <p><?= h(bm_arch_text($hit['disposition_justification'] ?? $hit['rationale'] ?? '', 'Governed disposition requires human confirmation.')) ?></p>
                </div>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>
      </section>

      <aside class="mca-inspector" aria-label="Selected amendment area inspector">
        <?php foreach ($amendments as $index => $impact): ?>
          <?php
            $impactId = (int)($impact['id'] ?? 0);
            $evidence = bm_arch_array($impact['canonical_evidence_json'] ?? $impact['canonical_evidence'] ?? array());
            $preserved = bm_arch_array($impact['preserved_logic_json'] ?? $impact['preserved_logic'] ?? array());
            $linkedCoverage = array_values(array_filter(
                $coverage,
                static fn(array $cell): bool =>
                    (int)($cell['impact_id'] ?? 0) === $impactId
                    || (
                        (int)($cell['section_id'] ?? 0) > 0
                        && (int)($cell['section_id'] ?? 0) === (int)($impact['section_id'] ?? 0)
                    )
            ));
          ?>
          <div class="mca-inspector__panel" data-mca-inspector="<?= $impactId ?>" <?= $index === 0 ? '' : 'hidden' ?>>
            <header>
              <span class="mca-eyebrow">Selected amendment area</span>
              <div><span class="mca-section-ref"><?= h((string)($impact['section_number'] ?? 'Section')) ?></span><span class="mca-treatment mca-treatment--<?= h(strtolower((string)($impact['treatment'] ?? 'amend'))) ?>"><?= h((string)($impact['treatment'] ?? 'AMEND')) ?></span></div>
              <h2><?= h(bm_arch_text($impact['section_title'] ?? $impact['title'] ?? '', 'Controlled manual area')) ?></h2>
            </header>
            <section>
              <h3>Why this is necessary</h3>
              <p><?= h(bm_arch_text($impact['substantive_rationale'] ?? $impact['description'] ?? '', 'No rationale recorded.')) ?></p>
            </section>
            <section>
              <h3>Current canonical context</h3>
              <p><?= h(bm_arch_text($impact['current_state_summary'] ?? '', 'Open the canonical reader to inspect the complete current section.')) ?></p>
              <?php if ((int)($manual['id'] ?? 0) > 0): ?><a class="mca-canonical-link" href="/admin/books_manuals/reader.php?version_id=<?= (int)$manual['id'] ?>" target="_blank" rel="noopener">Open source revision ↗</a><?php endif; ?>
            </section>
            <?php if ($preserved !== array()): ?>
              <section><h3>Must remain valid</h3><ul><?php foreach ($preserved as $item): ?><li><?= h(is_array($item) ? bm_arch_text($item['title'] ?? $item['concept'] ?? '', 'Preserved control') : (string)$item) ?></li><?php endforeach; ?></ul></section>
            <?php endif; ?>
            <?php if ($evidence !== array()): ?>
              <details class="mca-inspector__evidence" open>
                <summary>Canonical evidence</summary>
                <?php foreach ($evidence as $item): ?><blockquote><?= h(is_array($item) ? bm_arch_text($item['excerpt'] ?? $item['text'] ?? $item['quote'] ?? '', json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : (string)$item) ?></blockquote><?php endforeach; ?>
              </details>
            <?php endif; ?>
            <section>
              <h3>Target-state coverage</h3>
              <div class="mca-inspector__coverage">
                <?php foreach ($linkedCoverage as $cell): ?><span class="is-<?= h(strtolower((string)($cell['coverage_status'] ?? 'review_required'))) ?>"><?= h(ucwords(strtolower(str_replace('_', ' ', (string)($cell['coverage_status'] ?? 'Review required'))))) ?></span><?php endforeach; ?>
                <?php if ($linkedCoverage === array()): ?><span>Covered by consolidated impact reasoning</span><?php endif; ?>
              </div>
            </section>
            <div class="mca-decision-bar">
              <label><span>Decision rationale</span><textarea rows="3" data-mca-decision-note placeholder="Required for Modify or Reject."></textarea></label>
              <div>
                <button type="button" class="app-btn app-btn--primary" data-mca-impact-decision="ACCEPT" data-impact-id="<?= $impactId ?>" <?= $isOwner ? '' : 'disabled' ?>>Accept</button>
                <button type="button" class="app-btn app-btn--secondary" data-mca-impact-decision="MODIFY" data-impact-id="<?= $impactId ?>" <?= $isOwner ? '' : 'disabled' ?>>Modify</button>
                <button type="button" class="app-btn app-btn--danger" data-mca-impact-decision="REJECT" data-impact-id="<?= $impactId ?>" <?= $isOwner ? '' : 'disabled' ?>>Reject</button>
              </div>
              <?php if (!$isOwner): ?><small>Only the Change Plan owner can record amendment-area decisions.</small><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </aside>
    </div>
  <?php endif; ?>
  <div class="mca-toast" data-mca-toast hidden role="status" aria-live="polite"></div>
</main>
<script src="/assets/manual-change-architect.js?v=<?= (int)(@filemtime(__DIR__ . '/../../assets/manual-change-architect.js') ?: time()) ?>"></script>
<?php
compliance_page_close();
cw_footer();
