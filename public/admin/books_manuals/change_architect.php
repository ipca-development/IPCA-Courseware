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
$report = array();
$manual = array();
$versions = array();
$loadError = '';
try {
    if (!$plans->tablesPresent()) {
        throw new RuntimeException('The Manual Change Wizzard is not installed on this deployment.');
    }
    if ($planId <= 0) {
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
$outOfScope = mcw_boundaries($boundaries, 'OUT_OF_SCOPE');
$reviewSeparately = mcw_boundaries($boundaries, 'REVIEW_SEPARATELY');
$legacyHits = array_values(array_filter((array)($report['legacy_hits'] ?? array()), 'is_array'));
$otherLegacy = array_values(array_filter(
    $legacyHits,
    static fn(array $hit): bool => strtoupper((string)($hit['disposition'] ?? '')) !== 'REMOVE_OR_REPLACE'
));
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

$step1Complete = $report !== array() && $impacts !== array();
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
>
  <?php if ($loadError !== '' && $report === array()): ?>
    <div class="cmp-alert cmp-alert--error"><?= h($loadError) ?></div>
  <?php endif; ?>

  <div class="mcw-steps">
    <?php if ($step1Complete): ?>
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
            <strong>Analyzing your change…</strong>
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
          <summary><span class="mcw-check">✓</span><span><strong>2. Impact Analysis</strong><small><?= count($amendments) ?> amendment areas accepted</small></span><span>View</span></summary>
          <div class="mcw-completed-body"><?php foreach ($amendments as $impact): ?><p><strong><?= h((string)$impact['section_number']) ?></strong> <?= h((string)$impact['section_title']) ?> — <?= h((string)$impact['treatment']) ?></p><?php endforeach; ?></div>
        </details>
      <?php elseif ($activeStep === 2): ?>
        <section class="mcw-step mcw-step--active" data-mcw-step="2">
          <header><span class="mcw-step-number">2</span><div><h2>What should actually be amended?</h2><p>I reviewed <?= h((string)($manual['book_key'] ?? 'the manual')) ?> <?= h((string)($manual['version_label'] ?? '')) ?> and recommend changes to <?= count($amendments) ?> areas.</p></div></header>
          <div class="mcw-amendments">
            <?php foreach ($amendments as $impact): ?>
              <?php
                $impactId = (int)($impact['id'] ?? 0);
                $changes = mcw_array($impact['target_concepts_json'] ?? array());
                $stays = mcw_array($impact['preserved_logic_json'] ?? array());
                $primary = (string)($impact['section_number'] ?? '') === '5.6';
              ?>
              <article class="mcw-amendment <?= $primary ? 'is-primary' : '' ?>">
                <div class="mcw-amendment-heading"><div><h3><?= h((string)$impact['section_number']) ?> — <?= h((string)$impact['section_title']) ?></h3><span class="mcw-treatment"><?= h((string)$impact['treatment']) ?></span><?php if ($primary): ?><span class="mcw-primary-label">Primary change</span><?php endif; ?></div><span class="mcw-impact-status is-<?= h((string)($impact['status'] ?? 'proposed')) ?>" data-mcw-impact-status="<?= $impactId ?>"><?= h(ucfirst((string)($impact['status'] ?? 'proposed'))) ?></span></div>
                <p><?= h(mcw_text($impact['substantive_rationale'] ?? '', 'This section requires amendment to support the requested change.')) ?></p>
                <dl><div><dt>What changes</dt><dd><?= h(implode(' · ', array_map(static fn(mixed $item): string => is_array($item) ? mcw_text($item['title'] ?? '', '') : (string)$item, $changes))) ?></dd></div><div><dt>What stays</dt><dd><?= h(implode(' · ', array_map(static fn(mixed $item): string => is_array($item) ? mcw_text($item['title'] ?? '', '') : (string)$item, $stays))) ?></dd></div></dl>
                <details class="mcw-impact-details">
                  <summary>Details</summary>
                  <p><strong>Current context:</strong> <?= h(mcw_text($impact['current_state_summary'] ?? '', 'The complete source section was reviewed.')) ?></p>
                  <p><strong>Why:</strong> <?= h(mcw_text($impact['description'] ?? $impact['substantive_rationale'] ?? '', 'Governed amendment required.')) ?></p>
                  <label class="mcw-field"><span>Decision note</span><textarea rows="3" data-mcw-impact-note="<?= $impactId ?>" placeholder="Required when modifying or rejecting."></textarea></label>
                  <div class="mcw-secondary-actions">
                    <button class="app-btn app-btn--secondary" type="button" data-mcw-impact-decision="MODIFY" data-impact-id="<?= $impactId ?>" <?= $isOwner ? '' : 'disabled' ?>>Modify</button>
                    <button class="app-btn app-btn--danger" type="button" data-mcw-impact-decision="REJECT" data-impact-id="<?= $impactId ?>" <?= $isOwner ? '' : 'disabled' ?>>Reject</button>
                  </div>
                </details>
              </article>
            <?php endforeach; ?>
          </div>
          <details class="mcw-secondary-review"><summary>Reviewed — no change required (<?= count($preserved) ?>)</summary><div><?php foreach ($preserved as $row): ?><p><strong><?= h((string)$row['subject_reference']) ?></strong><br><?= h((string)$row['rationale']) ?></p><?php endforeach; ?></div></details>
          <details class="mcw-secondary-review"><summary>Other legacy references found (<?= count($otherLegacy) ?>)</summary><div><?php foreach ($otherLegacy as $hit): ?><p><strong><?= h((string)$hit['legacy_identity']) ?></strong> — <?= h(str_replace('_', ' ', (string)$hit['disposition'])) ?><br><?= h((string)($hit['disposition_justification'] ?? '')) ?></p><?php endforeach; ?></div></details>
          <details class="mcw-secondary-review"><summary>View analysis details</summary><div><p>The detailed evidence, preserved areas, out-of-scope areas and governed legacy dispositions remain retained in the Change Plan audit record.</p><?php foreach ($outOfScope as $row): ?><p><strong>Out of scope:</strong> <?= h((string)$row['subject_reference']) ?></p><?php endforeach; ?><?php foreach ($reviewSeparately as $row): ?><p><strong>Review separately:</strong> <?= h((string)$row['subject_reference']) ?></p><?php endforeach; ?></div></details>
          <button class="app-btn app-btn--primary mcw-primary-action" type="button" data-mcw-accept-impacts <?= $isOwner ? '' : 'disabled' ?>>Accept Impact Analysis &amp; Continue</button>
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
