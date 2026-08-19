<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsUi.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsChangePlanService.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsChangeArchitectService.php';

/** @return array<mixed> */
function mcw_array(mixed $value): array
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

function mcw_text(mixed $value, string $fallback = ''): string
{
    $value = trim((string)$value);
    return $value !== '' ? $value : $fallback;
}

function mcw_treatment_icon(string $treatment): string
{
    return match (strtoupper($treatment)) {
        'PRESERVE', 'PRESERVE_REFINE' => '✓',
        'ADD' => '+',
        'REMOVE', 'REMOVE_OBSOLETE', 'REPLACE' => '−',
        'RESTRUCTURE' => '↕',
        'REVIEW_SEPARATELY' => '!',
        default => '✎',
    };
}

function mcw_treatment_label(string $treatment): string
{
    return match (strtoupper($treatment)) {
        'PRESERVE_REFINE' => 'PRESERVE / REFINE',
        'REMOVE', 'REMOVE_OBSOLETE', 'REPLACE' => 'REMOVE OBSOLETE',
        'REVIEW_SEPARATELY' => 'REVIEW SEPARATELY',
        default => strtoupper($treatment),
    };
}

/** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
function mcw_boundaries(array $rows, string $classification): array
{
    return array_values(array_filter(
        $rows,
        static fn(array $row): bool =>
            strtoupper((string)($row['classification'] ?? '')) === $classification
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
$startNew = (string)($_GET['new'] ?? '') === '1';
$report = array();
$manual = array();
$versions = array();
$loadError = '';
try {
    if (!$plans->tablesPresent()) {
        throw new RuntimeException('The Manual Change Wizzard is not installed on this deployment.');
    }
    if ($planId <= 0 && !$startNew) {
        $stmt = $pdo->prepare(
            'SELECT id FROM ipca_manual_ai_architect_plans
             WHERE owner_id=? ORDER BY updated_at DESC,id DESC LIMIT 1'
        );
        $stmt->execute(array($userId));
        $planId = (int)$stmt->fetchColumn();
    }
    if ($planId > 0) {
        $report = (new BooksManualsChangeArchitectService($pdo, $plans))
            ->getCompleteCheckpointReport($planId);
        $versionId = (int)($report['primary_manual_version_id'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT v.id,v.version_label,v.lifecycle_status,b.book_key,b.title
             FROM ipca_publishing_book_versions v
             JOIN ipca_publishing_books b ON b.id=v.book_id WHERE v.id=? LIMIT 1'
        );
        $stmt->execute(array($versionId));
        $manual = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
    }
    $versions = $pdo->query(
        "SELECT v.id,v.version_label,v.lifecycle_status,b.book_key,b.title
         FROM ipca_publishing_book_versions v
         JOIN ipca_publishing_books b ON b.id=v.book_id
         LEFT JOIN ipca_publishing_annex_book_map annex_map
           ON annex_map.annex_book_id=b.id AND annex_map.status='active'
         LEFT JOIN ipca_publishing_annex_book_links legacy_annex
           ON legacy_annex.annex_book_id=b.id
         WHERE v.lifecycle_status IN ('draft','in_review')
           AND b.status='active'
           AND b.book_type NOT IN ('annex','annex_book')
           AND annex_map.id IS NULL
           AND legacy_annex.id IS NULL
         ORDER BY b.title,v.id DESC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: array();
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

$intents = array_values(array_filter((array)($report['change_intents'] ?? array()), 'is_array'));
$intent = $intents[0] ?? array();
$impacts = array_values(array_filter((array)($report['impacts'] ?? array()), 'is_array'));
$impactById = array();
foreach ($impacts as $impactRow) {
    $impactById[(int)($impactRow['id'] ?? 0)] = $impactRow;
}
$impactPresentation = mcw_array($report['impact_presentation'] ?? array());
$presentationAreas = array_values(array_filter(
    (array)($impactPresentation['areas'] ?? array()),
    'is_array'
));
usort($presentationAreas, static fn(array $a, array $b): int =>
    strnatcasecmp((string)($a['section_number'] ?? ''), (string)($b['section_number'] ?? ''))
);
$restructureCount = count(array_filter(
    $presentationAreas,
    static fn(array $area): bool => strtoupper((string)($area['treatment'] ?? '')) === 'RESTRUCTURE'
));
$areaAnchorByLabel = array();
foreach ($presentationAreas as $area) {
    $label = trim((string)($area['section_number'] ?? '') . ' ' . (string)($area['section_title'] ?? ''));
    $areaAnchorByLabel[$label] = 'mcw-impact-' . (int)($area['impact_id'] ?? 0);
}
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
$boundaries = array_values(array_filter((array)($report['boundaries'] ?? array()), 'is_array'));
$preserved = mcw_boundaries($boundaries, 'MUST_PRESERVE');
if (is_array($impactPresentation['no_change_areas'] ?? null)) {
    $preserved = array_values(array_filter($impactPresentation['no_change_areas'], 'is_array'));
}
$outOfScope = mcw_boundaries($boundaries, 'OUT_OF_SCOPE');
$reviewSeparately = mcw_boundaries($boundaries, 'REVIEW_SEPARATELY');
$legacyHits = array_values(array_filter((array)($report['legacy_hits'] ?? array()), 'is_array'));
$legacyOnlySectionIds = array_map(
    'intval',
    (array)($impactPresentation['legacy_only_section_ids'] ?? array())
);
$otherLegacy = array_values(array_filter(
    $legacyHits,
    static fn(array $hit): bool =>
        strtoupper((string)($hit['disposition'] ?? '')) !== 'REMOVE_OR_REPLACE'
        || in_array((int)($hit['section_id'] ?? 0), $legacyOnlySectionIds, true)
));
$sectionLabels = array();
foreach ($impacts as $impactRow) {
    $sectionId = (int)($impactRow['section_id'] ?? 0);
    if ($sectionId > 0) {
        $sectionLabels[$sectionId] = trim(
            (string)($impactRow['section_number'] ?? '') . ' '
            . (string)($impactRow['section_title'] ?? '')
        );
    }
}
$legacyReviewRows = array();
foreach ($otherLegacy as $hit) {
    $key = implode('|', array(
        (string)($hit['section_id'] ?? ''),
        (string)($hit['legacy_identity'] ?? ''),
        (string)($hit['disposition'] ?? ''),
    ));
    $legacyReviewRows[$key] ??= $hit;
}
$legacyReviewRows = array_values($legacyReviewRows);
$evidence = array_values(array_filter((array)($report['evidence'] ?? array()), 'is_array'));
$structures = array_values(array_filter((array)($report['structure_proposals'] ?? array()), 'is_array'));
$structure = $structures === array() ? array() : $structures[array_key_last($structures)];
$structureNodes = array_values(array_filter((array)($report['structure_nodes'] ?? array()), 'is_array'));
$drafts = array_values(array_filter((array)($report['drafts'] ?? array()), 'is_array'));
$draft = $drafts === array() ? array() : $drafts[array_key_last($drafts)];
$draftPayload = mcw_array($draft['draft_payload_json'] ?? array());
$draftSections = array();
foreach ((array)($draftPayload['section_drafts'] ?? $draftPayload['sections'] ?? array()) as $key => $value) {
    if (!is_array($value)) {
        continue;
    }
    $value['section_number'] = (string)($value['section_number'] ?? (is_string($key) ? $key : ''));
    $draftSections[] = $value;
}
$draftDecisions = mcw_array($draftPayload['decisions'] ?? array());
$allDraftsAccepted = $draftSections !== array();
foreach ($draftSections as $section) {
    $number = (string)($section['section_number'] ?? '');
    if ((string)($draftDecisions[$number]['decision'] ?? '') !== 'accepted') {
        $allDraftsAccepted = false;
        break;
    }
}
$reviews = array_values(array_filter((array)($report['reviews'] ?? array()), 'is_array'));
$review = $reviews === array() ? array() : $reviews[array_key_last($reviews)];
$reviewPayload = mcw_array($review['review_payload_json'] ?? array());
$operations = array_values(array_filter((array)($report['operations'] ?? array()), 'is_array'));
$operation = $operations === array() ? array() : $operations[array_key_last($operations)];

$analysisPending = $report !== array()
    && in_array((string)($report['status'] ?? ''), array('active', 'analyzing'), true);
$step1Complete = $report !== array() && $impacts !== array() && !$analysisPending;
$step2Complete = $amendments !== array() && !in_array(
    false,
    array_map(static fn(array $impact): bool => (string)($impact['status'] ?? '') === 'approved', $amendments),
    true
);
$step3Complete = $structure !== array() && (string)($structure['status'] ?? '') === 'approved';
$step4Complete = $draft !== array()
    && (string)($draftPayload['wizard_status'] ?? '') === 'accepted';
$reviewReady = in_array(
    strtoupper((string)($reviewPayload['status'] ?? $review['status'] ?? '')),
    array('READY', 'READY_FOR_HUMAN_REVIEW', 'APPROVED'),
    true
);
$step5Complete = $reviewReady && (string)($report['stage'] ?? '') === 'operations';
$step6Complete = (string)($operation['status'] ?? '') === 'succeeded';
$isOwner = (int)($report['owner_id'] ?? 0) === $userId || $report === array();

$activeStep = !$step1Complete ? 1
    : (!$step2Complete ? 2
        : (!$step3Complete ? 3
            : (!$step4Complete ? 4
                : (!$step5Complete ? 5 : 6))));

cw_header('Books & Manuals · Manual Change Wizzard');
books_manuals_page_open(array(
    'title' => 'Manual Change Wizzard',
    'description' => 'A guided path from change request to a controlled working revision.',
    'back' => array('href' => '/admin/books_manuals/index.php', 'label' => 'IPCA Library'),
));
?>
<link rel="stylesheet" href="/assets/manual-change-architect.css?v=<?= (int)(@filemtime(__DIR__ . '/../../assets/manual-change-architect.css') ?: time()) ?>">
<main
  class="mcw"
  data-mcw
  data-plan-id="<?= $planId ?>"
  data-api-url="/admin/api/books_manuals_change_architect_api.php"
  data-csrf-token="<?= h($csrf) ?>"
  data-active-step="<?= $activeStep ?>"
  data-analysis-pending="<?= $analysisPending ? '1' : '0' ?>"
>
  <?php if ($loadError !== '' && $report === array()): ?>
    <div class="cmp-alert cmp-alert--error"><?= h($loadError) ?></div>
  <?php endif; ?>

  <?php if ($report !== array()): ?>
    <div class="mcw-restart">
      <span>The current Change Plan remains saved in its present state.</span>
      <a class="app-btn app-btn--secondary" href="/admin/books_manuals/change_architect.php?new=1">Start New Wizzard</a>
    </div>
  <?php endif; ?>

  <div class="mcw-steps">
    <?php if ($analysisPending): ?>
      <section class="mcw-step mcw-step--active" data-mcw-step="1" data-mcw-pending-analysis>
        <header><span class="mcw-step-number">1</span><div><h2>Change Request &amp; Sources</h2><p><?= h(mcw_text($report['title'] ?? '', 'Analyzing requested change')) ?> · <?= h((string)($manual['book_key'] ?? 'Manual')) ?> <?= h((string)($manual['version_label'] ?? '')) ?></p></div></header>
        <div class="mcw-analysis-progress">
          <div class="mcw-analysis-progress-heading"><strong>Analyzing your change…</strong><span data-mcw-progress-percent>2%</span></div>
          <div class="mcw-analysis-progress-track" role="progressbar" aria-label="Manual change analysis progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="2" data-mcw-progress-bar><span style="width:2%" data-mcw-progress-fill></span></div>
          <p class="mcw-analysis-current" data-mcw-progress-label>Preparing the controlled analysis</p>
          <ul>
            <li data-mcw-progress-step="understanding_change">○ Understanding requested change</li>
            <li data-mcw-progress-step="reviewing_evidence">○ Reviewing supporting evidence</li>
            <li data-mcw-progress-step="reviewing_manual">○ Reviewing selected manual</li>
            <li data-mcw-progress-step="checking_references">○ Checking legacy references</li>
            <li data-mcw-progress-step="checking_related_sections">○ Checking related sections</li>
            <li data-mcw-progress-step="mapping_coverage">○ Mapping requirements to manual sections</li>
            <li data-mcw-progress-step="building_recommendation">○ Building amendment recommendation</li>
            <li data-mcw-progress-step="quality_check">○ Running final coverage checks</li>
          </ul>
          <p class="mcw-analysis-elapsed" data-mcw-progress-elapsed>Started just now · this page will continue automatically when ready.</p>
        </div>
      </section>
    <?php elseif ($step1Complete): ?>
      <details class="mcw-step mcw-step--complete" <?= $activeStep === 1 ? 'open' : '' ?>>
        <summary>
          <span class="mcw-check">✓</span>
          <span><strong>1. Change Request &amp; Sources</strong><small><?= h(mcw_text($report['title'] ?? '', 'Change request')) ?> · <?= h((string)($manual['book_key'] ?? 'Manual')) ?> <?= h((string)($manual['version_label'] ?? '')) ?> · <?= count($evidence) ?> supporting source<?= count($evidence) === 1 ? '' : 's' ?></small></span>
          <span>View / Edit</span>
        </summary>
        <div class="mcw-completed-body">
          <h3>Requested change</h3>
          <p><?= nl2br(h(mcw_text($report['change_request'] ?? '', 'No request text recorded.'))) ?></p>
          <?php if ($evidence !== array()): ?><h3>Supporting evidence</h3><ul><?php foreach ($evidence as $item): ?><li><?= h(mcw_text($item['title'] ?? '', 'Supporting source')) ?></li><?php endforeach; ?></ul><?php endif; ?>
        </div>
      </details>
    <?php elseif ($activeStep === 1): ?>
      <section class="mcw-step mcw-step--active" data-mcw-step="1">
        <header><span class="mcw-step-number">1</span><div><h2>What change do you want to make?</h2><p>Describe the regulatory, operational, organizational, procedural or system change you want reflected in the manual.</p></div></header>
        <form class="mcw-intake" data-mcw-intake enctype="multipart/form-data">
          <label class="mcw-field"><span>Change request</span><textarea name="change_request" rows="12" maxlength="40000" required placeholder="Describe the complete requested change, affected workflow, accountable roles, constraints and anything that must remain valid."></textarea></label>
          <section class="mcw-intake-section">
            <div><span class="mcw-kicker">Supporting evidence — optional</span><p>Add source documents or paste supporting text.</p></div>
            <label class="mcw-upload"><input type="file" name="evidence_files[]" multiple accept=".txt,.pdf,.docx"><span>Choose documents</span><small>TXT, PDF or DOCX · multiple files allowed</small></label>
            <label class="mcw-field"><span>Add text evidence</span><textarea name="evidence_text" rows="5" placeholder="Paste regulatory extracts, operational facts or known system limitations."></textarea></label>
            <div class="mcw-files" data-mcw-files hidden></div>
          </section>
          <section class="mcw-intake-section">
            <div><span class="mcw-kicker">Manual(s) to review</span><p>Select one primary controlled revision. Related manuals remain linked review items.</p></div>
            <label class="mcw-field"><span>Primary manual</span><select name="primary_version_id" required><option value="">Select a controlled revision</option><?php foreach ($versions as $version): ?><?php $versionPhase = (string)$version['lifecycle_status'] === 'in_review' ? 'Draft Review' : 'Draft'; ?><option value="<?= (int)$version['id'] ?>"><?= h((string)$version['book_key']) ?> <?= h((string)$version['version_label']) ?> — <?= h((string)$version['title']) ?> · <?= h($versionPhase) ?></option><?php endforeach; ?></select></label>
          </section>
          <div class="mcw-analysis-progress" data-mcw-analysis-progress hidden>
            <div class="mcw-analysis-progress-heading"><strong>Starting analysis…</strong><span>0%</span></div>
            <div class="mcw-analysis-progress-track"><span style="width:0%"></span></div>
            <ul>
              <li data-progress-index="0">○ Understanding requested change</li>
              <li data-progress-index="1">○ Reviewing supporting evidence</li>
              <li data-progress-index="2">○ Reviewing selected manual</li>
              <li data-progress-index="3">○ Checking related sections</li>
              <li data-progress-index="4">○ Building amendment recommendation</li>
            </ul>
          </div>
          <button class="app-btn app-btn--primary mcw-primary-action" type="submit">Analyze Change</button>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($step1Complete && $activeStep >= 2): ?>
      <?php if ($step2Complete): ?>
        <details class="mcw-step mcw-step--complete">
          <summary><span class="mcw-check">✓</span><span><strong>2. Impact Analysis</strong><small><?= count($presentationAreas) ?> amendment areas accepted</small></span><span>View</span></summary>
          <div class="mcw-completed-body"><?php foreach ($presentationAreas as $area): ?><p><strong><?= h(trim((string)$area['section_number'] . ' ' . (string)$area['section_title'])) ?></strong> — <?= h((string)$area['treatment']) ?></p><?php endforeach; ?></div>
        </details>
      <?php elseif ($activeStep === 2): ?>
        <section class="mcw-step mcw-step--active" data-mcw-step="2">
          <header><span class="mcw-step-number">2</span><div><h2>Review Proposed Manual Changes</h2><p>The Architect reviewed the selected manual against your change request and identified the sections below that should be amended. For each section, compare the current manual with the recommended change. If the overall analysis is correct, continue. If anything is missing, unnecessary or incorrect, request changes.</p></div></header>
          <div class="mcw-impact-summary" aria-label="Impact analysis summary">
            <span><strong><?= count($presentationAreas) ?></strong> sections require amendment</span>
            <span><strong><?= $restructureCount ?></strong> primary procedure<?= $restructureCount === 1 ? '' : 's' ?> require<?= $restructureCount === 1 ? 's' : '' ?> restructuring</span>
            <span><strong><?= count($legacyReviewRows) ?></strong> legacy references found outside this change</span>
          </div>
          <div class="mcw-amendments">
            <?php foreach ($presentationAreas as $area): ?>
              <?php
                $impactId = (int)($area['impact_id'] ?? 0);
                $impact = $impactById[$impactId] ?? array();
                $primary = !empty($area['is_primary_change']);
                $currentManual = mcw_array($area['current_manual'] ?? array());
                $previewSubsections = array_values(array_filter(
                    (array)($currentManual['preview_subsections'] ?? array()),
                    'is_array'
                ));
                $allSubsections = array_values(array_filter(
                    (array)($currentManual['subsections'] ?? array()),
                    'is_array'
                ));
                $sectionHeading = trim(
                    (string)($area['section_number'] ?? '') . ' '
                    . (string)($area['section_title'] ?? 'Manual section')
                );
              ?>
              <article class="mcw-amendment <?= $primary ? 'is-primary' : '' ?>" id="mcw-impact-<?= $impactId ?>">
                <div class="mcw-amendment-heading"><div><span class="mcw-section-number"><?= h((string)($area['section_number'] ?? '')) ?></span><h3><?= h((string)($area['section_title'] ?? 'Manual section')) ?></h3><span class="mcw-treatment"><?= h(mcw_treatment_icon((string)$area['treatment']) . ' ' . mcw_treatment_label((string)$area['treatment'])) ?></span><?php if ($primary): ?><span class="mcw-primary-label">Primary change</span><?php endif; ?></div></div>

                <section class="mcw-impact-section">
                  <h4>Why this section is affected</h4>
                  <p><?= h(mcw_text($area['concise_rationale'] ?? '', 'This section requires amendment to support the requested change.')) ?></p>
                </section>

                <section class="mcw-impact-section mcw-current-manual">
                  <h4>Current manual <small>Canonical source</small></h4>
                  <?php if ($previewSubsections !== array()): ?>
                    <?php if ((array)($currentManual['legacy_phrases'] ?? array()) !== array()): ?><div class="mcw-legacy-phrases"><?php foreach ((array)$currentManual['legacy_phrases'] as $phrase): ?><span>legacy · <?= h((string)$phrase) ?></span><?php endforeach; ?></div><?php endif; ?>
                    <div class="mcw-source-preview">
                      <?php foreach ($previewSubsections as $subsection): ?>
                        <article class="mcw-source-subsection">
                          <h5><span><?= h((string)($subsection['number'] ?? '')) ?></span><?= h((string)($subsection['title'] ?? '')) ?></h5>
                          <?php foreach (array_slice(array_values(array_filter((array)($subsection['paragraphs'] ?? array()), 'is_string')), 0, 2) as $paragraph): ?><p>“<?= h(mb_strimwidth(trim($paragraph), 0, 520, '…')) ?>”</p><?php endforeach; ?>
                        </article>
                      <?php endforeach; ?>
                    </div>
                    <?php if (count($allSubsections) > count($previewSubsections)): ?>
                      <details class="mcw-current-complete">
                        <summary>View complete current section</summary>
                        <div><?php foreach ($allSubsections as $subsection): ?><article class="mcw-source-subsection"><h5><span><?= h((string)($subsection['number'] ?? '')) ?></span><?= h((string)($subsection['title'] ?? '')) ?></h5><?php foreach ((array)($subsection['paragraphs'] ?? array()) as $paragraph): ?><p><?= h((string)$paragraph) ?></p><?php endforeach; ?></article><?php endforeach; ?></div>
                      </details>
                    <?php endif; ?>
                  <?php else: ?>
                    <p class="mcw-source-unavailable">This saved analysis predates the canonical source projection. Request re-analysis to rebuild it from the controlled manual.</p>
                  <?php endif; ?>
                </section>

                <div class="mcw-change-flow" aria-hidden="true"><span>↓</span></div>

                <section class="mcw-impact-section mcw-proposed-change">
                  <h4>Proposed change <small>Architect recommendation</small></h4>
                  <div class="mcw-component-list">
                    <?php foreach ((array)($area['amendment_components'] ?? array()) as $component): ?>
                      <?php if (!is_array($component)) { continue; } ?>
                      <article class="mcw-component">
                        <span class="mcw-component-mark" aria-hidden="true"><?= h(mcw_treatment_icon((string)($component['treatment'] ?? 'AMEND'))) ?></span>
                        <div><h5><?php if (mcw_text($component['number'] ?? '') !== ''): ?><span><?= h((string)$component['number']) ?></span><?php endif; ?><?= h((string)($component['title'] ?? 'Manual amendment')) ?></h5><span class="mcw-mini-treatment"><?= h(mcw_treatment_label((string)($component['treatment'] ?? 'AMEND'))) ?></span><p><?= h((string)($component['summary'] ?? '')) ?></p></div>
                      </article>
                    <?php endforeach; ?>
                  </div>
                </section>

                <section class="mcw-impact-section mcw-preserved">
                  <h4>Preserved <small>What will remain unchanged</small></h4>
                  <?php if ((array)($area['preserved_concepts'] ?? array()) !== array()): ?>
                    <ul class="mcw-preserved-list"><?php foreach ((array)$area['preserved_concepts'] as $concept): ?><li><?= h((string)$concept) ?></li><?php endforeach; ?></ul>
                  <?php else: ?><p>No section-specific preservation decision was recorded.</p><?php endif; ?>
                </section>

                <?php if ((array)($area['dependencies'] ?? array()) !== array()): ?>
                  <section class="mcw-impact-section mcw-impact-section--compact">
                    <h4>Related amendments</h4>
                    <div class="mcw-related-sections"><?php foreach ((array)$area['dependencies'] as $dependency): ?><?php $dependency = trim((string)$dependency); ?><a href="#<?= h($areaAnchorByLabel[$dependency] ?? 'mcw-impact-' . $impactId) ?>"><?= h($dependency) ?></a><?php endforeach; ?></div>
                  </section>
                <?php endif; ?>

                <details class="mcw-impact-details">
                  <summary>View evidence / analysis</summary>
                  <p><strong>Architect rationale:</strong> <?= h(mcw_text($impact['substantive_rationale'] ?? '', 'The complete canonical section was reviewed.')) ?></p>
                  <?php if ((array)($area['removed_or_obsolete_concepts'] ?? array()) !== array()): ?><p><strong>Legacy concepts:</strong> <?= h(implode(' · ', array_map('strval', (array)$area['removed_or_obsolete_concepts']))) ?></p><?php endif; ?>
                </details>
                <button class="mcw-flag-section" type="button" data-mcw-flag-impact="<?= $impactId ?>">Flag this section</button>
              </article>
            <?php endforeach; ?>
          </div>
          <details class="mcw-secondary-review"><summary>Reviewed — no change required (<?= count($preserved) ?>)</summary><div class="mcw-secondary-rows"><?php foreach ($preserved as $row): ?><article><strong><?= h(trim((string)($row['section_number'] ?? '') . ' ' . (string)($row['section_title'] ?? $row['subject_reference'] ?? 'Manual section'))) ?></strong><span class="mcw-mini-treatment">✓ Preserve</span><p><?= h((string)$row['rationale']) ?></p></article><?php endforeach; ?></div></details>
          <details class="mcw-secondary-review"><summary>Other legacy references found (<?= count($legacyReviewRows) ?>)</summary><div><p>These references belong to other workflows and remain available for separate governed review.</p><?php foreach ($legacyReviewRows as $hit): ?><article class="mcw-legacy-row"><strong><?= h($sectionLabels[(int)($hit['section_id'] ?? 0)] ?? 'Related manual section') ?></strong><span><?= h((string)$hit['legacy_identity']) ?></span><span class="mcw-mini-treatment">! Review separately</span><p><strong>Reason:</strong> <?= h((string)($hit['disposition_justification'] ?? 'This reference is outside the primary operational change.')) ?></p></article><?php endforeach; ?></div></details>
          <details class="mcw-secondary-review mcw-technical-analysis"><summary><span>Technical Analysis &amp; Coverage<small>Whole-manual review complete · <?= count((array)($impactPresentation['analysis_details']['target_components'] ?? array())) ?> target-state components · <?= count((array)($impactPresentation['analysis_details']['coverage'] ?? array())) ?> coverage decisions</small></span><span>View details</span></summary><div><p>The target-state model, complete coverage matrix, deterministic legacy scan, preservation boundaries, dependencies and canonical provenance remain retained in the Change Plan audit record.</p><?php foreach ($outOfScope as $row): ?><p><strong>Out of scope:</strong> <?= h(trim((string)($row['section_number'] ?? '') . ' ' . (string)($row['section_title'] ?? $row['subject_reference'] ?? ''))) ?></p><?php endforeach; ?><?php foreach ($reviewSeparately as $row): ?><p><strong>Review separately:</strong> <?= h(trim((string)($row['section_number'] ?? '') . ' ' . (string)($row['section_title'] ?? $row['subject_reference'] ?? ''))) ?></p><?php endforeach; ?></div></details>
          <section class="mcw-change-request-panel" data-mcw-impact-feedback hidden>
            <div><h3>What should the Architect reconsider?</h3><p>Describe the correction. The Architect will re-analyze the impact and prepare a revised recommendation.</p></div>
            <label class="mcw-field"><span>Affected area</span><select data-mcw-feedback-area><option value="">General / overall analysis</option><?php foreach ($presentationAreas as $area): ?><option value="<?= (int)$area['impact_id'] ?>"><?= h(trim((string)$area['section_number'] . ' ' . (string)$area['section_title'])) ?></option><?php endforeach; ?></select></label>
            <label class="mcw-field"><span>Correction</span><textarea rows="7" maxlength="10000" data-mcw-feedback-text placeholder="Section 5.7 should remain unchanged because…&#10;&#10;The analysis appears to have missed the Safety Manager responsibility for…"></textarea></label>
            <div class="mcw-change-request-actions"><button class="app-btn app-btn--secondary" type="button" data-mcw-cancel-impact-feedback>Cancel</button><button class="app-btn app-btn--primary" type="button" data-mcw-reanalyze-impact>Re-analyze Impact</button></div>
          </section>
          <div class="mcw-step-actions"><button class="app-btn app-btn--secondary" type="button" data-mcw-request-impact-changes>Request Changes</button><button class="app-btn app-btn--primary mcw-primary-action" type="button" data-mcw-accept-impacts <?= $isOwner ? '' : 'disabled' ?>>Accept Impact Analysis &amp; Continue</button></div>
        </section>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($step2Complete && $activeStep >= 3): ?>
      <?php if ($step3Complete): ?>
        <details class="mcw-step mcw-step--complete"><summary><span class="mcw-check">✓</span><span><strong>3. Proposed Structure</strong><small>Section 5.6 restructure accepted</small></span><span>View</span></summary><div class="mcw-completed-body"><p><?= h(mcw_text($structure['rationale'] ?? '', 'The proposed structure was accepted.')) ?></p></div></details>
      <?php elseif ($activeStep === 3): ?>
        <section class="mcw-step mcw-step--active" data-mcw-step="3">
          <header><span class="mcw-step-number">3</span><div><h2>Proposed Manual Structure</h2><p>The primary structural change is Section 5.6. The other affected sections retain their existing hierarchy.</p></div></header>
          <div class="mcw-structure">
            <section><span class="mcw-kicker">Current</span><h3>Section 5.6</h3><ol><li>Mandatory Occurrence Reporting</li><li>Voluntary Occurrence Reporting</li><li>Exchange of Information</li><li>Occurrence Reporting Scheme</li><li>E-Occurrence Report</li><li>Internal Safety Investigation</li></ol></section>
            <section><span class="mcw-kicker">Proposed</span><h3>Section 5.6</h3><ol><?php foreach ($structureNodes as $node): ?><li class="is-<?= h(strtolower((string)($node['action'] ?? 'preserve'))) ?>"><?= h((string)$node['title']) ?></li><?php endforeach; ?></ol></section>
          </div>
          <button class="app-btn app-btn--secondary" type="button" data-mcw-modify-structure>Modify</button>
          <button class="app-btn app-btn--primary mcw-primary-action" type="button" data-mcw-accept-structure>Accept Structure &amp; Continue</button>
        </section>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($step3Complete && $activeStep >= 4): ?>
      <?php if ($step4Complete): ?>
        <details class="mcw-step mcw-step--complete"><summary><span class="mcw-check">✓</span><span><strong>4. Proposed Manual Amendments</strong><small><?= count($draftSections) ?> amendments accepted</small></span><span>View</span></summary></details>
      <?php elseif ($activeStep === 4): ?>
        <section class="mcw-step mcw-step--active" data-mcw-step="4">
          <header><span class="mcw-step-number">4</span><div><h2>Proposed Manual Amendments</h2><p>Review the resulting controlled-manual wording one section at a time.</p></div></header>
          <div class="mcw-drafts">
            <?php foreach ($draftSections as $section): ?>
              <?php $sectionNumber = (string)($section['section_number'] ?? $section['number'] ?? 'Section'); ?>
              <?php $draftDecision = (string)($draftDecisions[$sectionNumber]['decision'] ?? ''); ?>
              <details class="mcw-draft" data-mcw-draft-section="<?= h($sectionNumber) ?>">
                <summary><span><strong><?= h($sectionNumber) ?> <?= h((string)($section['section_title'] ?? $section['title'] ?? '')) ?></strong><small><span data-mcw-draft-status><?= h((string)($section['treatment'] ?? 'AMEND')) ?> · <?= $draftDecision === 'accepted' ? 'Accepted' : 'Ready for review' ?></span></small></span><span><?= $draftDecision === 'accepted' ? 'View' : 'Review' ?></span></summary>
                <div>
                  <h4>Why this changes</h4><p><?= h((string)($section['rationale'] ?? '')) ?></p>
                  <h4>Current content preserved</h4><p><?= nl2br(h(implode("\n", array_map('strval', (array)($section['current_preserved'] ?? array()))))) ?></p>
                  <h4>Current content removed / replaced</h4><p><?= nl2br(h(implode("\n", array_map('strval', (array)($section['current_removed_replaced'] ?? array()))))) ?></p>
                  <h4>New content</h4><p><?= nl2br(h(implode("\n", array_map('strval', (array)($section['new_content_added'] ?? array()))))) ?></p>
                  <h4>Resulting proposed manual wording</h4><div class="mcw-resulting-wording"><?= nl2br(h((string)($section['resulting'] ?? $section['resulting_proposed_wording'] ?? implode("\n\n", array_map('strval', (array)($section['nodes'] ?? array())))))) ?></div>
                  <div class="mcw-secondary-actions"><button class="app-btn app-btn--primary" type="button" data-mcw-draft-decision="accepted">Accept</button><button class="app-btn app-btn--secondary" type="button" data-mcw-draft-decision="edit_requested">Edit</button><button class="app-btn app-btn--secondary" type="button" data-mcw-draft-decision="regenerate_requested">Regenerate</button><button class="app-btn app-btn--danger" type="button" data-mcw-draft-decision="rejected">Reject</button></div>
                </div>
              </details>
            <?php endforeach; ?>
          </div>
          <button class="app-btn app-btn--primary mcw-primary-action" type="button" data-mcw-accept-drafts <?= $allDraftsAccepted ? '' : 'disabled' ?>>Accept Draft Amendments &amp; Continue</button>
        </section>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($step4Complete && $activeStep >= 5): ?>
      <?php if ($step5Complete): ?>
        <details class="mcw-step mcw-step--complete"><summary><span class="mcw-check">✓</span><span><strong>5. Independent Review</strong><small>Ready for human approval</small></span><span>View</span></summary></details>
      <?php elseif ($activeStep === 5): ?>
        <section class="mcw-step mcw-step--active" data-mcw-step="5">
          <header><span class="mcw-step-number">5</span><div><h2>Independent Review</h2><p>Reviewing the resulting manual for completeness, consistency and unsupported claims.</p></div></header>
          <div class="mcw-review-result <?= $reviewReady ? 'is-ready' : 'is-review' ?>">
            <h3><?= $reviewReady ? '✓ Ready for Human Approval' : 'Requires Review' ?></h3>
            <?php $checks = mcw_array($reviewPayload['checks'] ?? $reviewPayload['final_quality_checks'] ?? array()); ?>
            <?php if ($checks !== array()): ?><ul><?php foreach ($checks as $label => $result): ?><li><?= !empty($result) ? '✓' : '!' ?> <?= h(is_string($label) ? ucwords(str_replace('_', ' ', $label)) : (string)$result) ?></li><?php endforeach; ?></ul><?php endif; ?>
            <details><summary>View full review details</summary><pre><?= h(json_encode($reviewPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details>
          </div>
          <?php if ($reviewReady): ?>
            <button class="app-btn app-btn--primary mcw-primary-action" type="button" data-mcw-continue-apply>Continue to Apply</button>
          <?php else: ?>
            <button class="app-btn app-btn--primary mcw-primary-action" type="button" data-mcw-run-review>Run Independent Review</button>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($step5Complete && $activeStep >= 6): ?>
      <section class="mcw-step mcw-step--active" data-mcw-step="6">
        <header><span class="mcw-step-number"><?= $step6Complete ? '✓' : '6' ?></span><div><h2><?= $step6Complete ? 'New Working Revision Created' : 'Create Working Revision' ?></h2><p>The accepted amendment is ready to be applied to a new working revision. <?= h((string)($manual['book_key'] ?? 'The source manual')) ?> <?= h((string)($manual['version_label'] ?? '')) ?> will remain unchanged.</p></div></header>
        <?php if ($step6Complete): ?>
          <?php $result = mcw_array($operation['result_json'] ?? array()); ?><a class="app-btn app-btn--primary mcw-primary-action" href="/admin/compliance/controlled_book_editor.php?version_id=<?= (int)($result['working_revision_id'] ?? 0) ?>">Open Working Revision</a>
        <?php else: ?>
          <dl class="mcw-apply-summary"><div><dt>Source</dt><dd><?= h((string)($manual['book_key'] ?? 'Manual')) ?> <?= h((string)($manual['version_label'] ?? '')) ?></dd></div><div><dt>Changes</dt><dd><?= count($amendments) ?> amendment areas</dd></div><div><dt>Independent Review</dt><dd>Ready</dd></div><div><dt>Preserved content</dt><dd>Verified</dd></div></dl>
          <button class="app-btn app-btn--primary mcw-primary-action" type="button" data-mcw-apply>CREATE WORKING REVISION &amp; APPLY ACCEPTED CHANGES</button>
          <p class="mcw-apply-note">This does not approve, finalize or publish the manual.</p>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </div>
  <div class="mcw-toast" data-mcw-toast hidden role="status" aria-live="polite"></div>
</main>
<script src="/assets/manual-change-architect.js?v=<?= (int)(@filemtime(__DIR__ . '/../../assets/manual-change-architect.js') ?: time()) ?>"></script>
<?php
compliance_page_close();
cw_footer();
