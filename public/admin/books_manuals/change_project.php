<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsUi.php';

$serviceFile = __DIR__ . '/../../../src/publishing/BooksManualsChangeAssistantService.php';
if (is_file($serviceFile)) {
    require_once $serviceFile;
}

function bmca_array(mixed $value): array
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

function bmca_value(array $row, array $keys, mixed $fallback = ''): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null) {
            return $row[$key];
        }
    }
    return $fallback;
}

$user = compliance_require_access($pdo);
$projectId = max(0, (int)($_GET['id'] ?? $_GET['project_id'] ?? 0));
$csrf = (string)($_SESSION['books_manuals_ai_csrf'] ?? '');
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(32));
    $_SESSION['books_manuals_ai_csrf'] = $csrf;
}

$service = null;
$tablesReady = false;
$featureEnabled = false;
$readinessMessage = '';
$project = null;
$detail = array();
$versions = array();
$reviewers = array();

try {
    if (!class_exists('BooksManualsChangeAssistantService')) {
        throw new RuntimeException('The AI Change Assistant service is not installed on this deployment.');
    }
    $service = new BooksManualsChangeAssistantService($pdo);
    if (!method_exists($service, 'tablesPresent') || !$service->tablesPresent()) {
        throw new RuntimeException('Install the AI Manual Change Assistant migration to open this workspace.');
    }
    $tablesReady = true;
    $featureEnabled = method_exists($service, 'featureEnabled')
        ? (bool)$service->featureEnabled()
        : (method_exists($service, 'enabled') ? (bool)$service::enabled() : true);
    if (!$featureEnabled) {
        throw new RuntimeException('The AI Manual Change Assistant feature is currently disabled.');
    }
    if ($projectId <= 0) {
        throw new RuntimeException('Select a valid change project.');
    }
    if (method_exists($service, 'assertProjectAccess')) {
        $service->assertProjectAccess($projectId, (int)($user['id'] ?? 0));
    }
    if (method_exists($service, 'getProject')) {
        $project = $service->getProject($projectId);
    }
    if (!is_array($project)) {
        throw new RuntimeException('Change project not found.');
    }
    $detail = method_exists($service, 'projectDetail') ? $service->projectDetail($projectId) : $project;
    $detail = is_array($detail) ? $detail : array();
    if (method_exists($service, 'listAvailableVersions')) {
        $versions = $service->listAvailableVersions();
    } elseif (class_exists('BooksManualsWorkflowService')) {
        $versions = (new BooksManualsWorkflowService($pdo))->listLibrary(false);
    }
    $versions = is_array($versions) ? $versions : array();
    if (method_exists($service, 'listReviewers')) {
        $reviewers = $service->listReviewers();
    }
} catch (Throwable $e) {
    $readinessMessage = $e->getMessage();
}

$project = is_array($project) ? $project : array();
$status = strtolower((string)bmca_value($project, array('status', 'project_status'), 'unavailable'));
$isOwner = (int)bmca_value($project, array('created_by'), 0) === (int)($user['id'] ?? 0);
$progress = max(0, min(100, (int)bmca_value($project, array('progress_percent', 'progress'), 0)));
$title = trim((string)bmca_value($project, array('title', 'request_title', 'name'), ''));
$requestText = trim((string)bmca_value($project, array('request_text', 'typed_request', 'request', 'description'), ''));
$source = bmca_array(bmca_value($detail, array('source'), bmca_value($project, array('source'), array())));
if ($source === array()) {
    $sources = bmca_array(bmca_value($detail, array('sources'), bmca_value($project, array('sources'), array())));
    $source = isset($sources[0]) && is_array($sources[0]) ? $sources[0] : array();
}
$scope = bmca_array(bmca_value($detail, array('scope', 'manual_scope', 'manuals', 'scopes'), bmca_value($project, array('manual_scope', 'manuals', 'scopes'), array())));
$impacts = bmca_array(bmca_value($detail, array('impacts', 'impact_findings', 'findings'), array()));
$proposals = bmca_array(bmca_value($detail, array('proposals', 'proposed_changes', 'changes', 'findings'), array()));
$conflicts = bmca_array(bmca_value($detail, array('conflicts', 'consistency_conflicts'), array()));
$approvedChanges = bmca_array(bmca_value($detail, array('approved_changes', 'approved_change_set'), array()));
$jobs = bmca_array(bmca_value($detail, array('jobs', 'runs', 'analysis_runs'), array()));
$job = bmca_array(bmca_value($detail, array('job'), array()));
if ($job !== array()) {
    $jobs = array($job);
    $jobStatus = strtolower((string)bmca_value($job, array('status'), ''));
    if (in_array($jobStatus, array('queued', 'running', 'retry'), true)) {
        $status = $jobStatus === 'running' ? 'analyzing' : $jobStatus;
    }
    $progress = max($progress, max(0, min(100, (int)bmca_value($job, array('progress_percent'), 0))));
}

$normalizeFinding = static function (array $finding): array {
    $evidence = bmca_array(bmca_value($finding, array('evidence', 'evidence_items', 'evidence_json'), array()));
    $versionEvidence = bmca_array($evidence['version'] ?? $evidence['book'] ?? array());
    $sectionEvidence = bmca_array($evidence['section'] ?? array());
    $blockEvidence = bmca_array($evidence['block'] ?? array());
    $blocksEvidence = bmca_array($evidence['blocks'] ?? array());
    $areaEvidence = bmca_array($evidence['impact_area'] ?? array());
    $sourceEvidence = bmca_array($evidence['source'] ?? array());
    $confidenceValue = (float)bmca_value($finding, array('confidence'), 0.0);
    $finding['version_id'] = (int)bmca_value($finding, array('version_id', 'book_version_id'), bmca_value($versionEvidence, array('id', 'version_id'), 0));
    $finding['section_id'] = (int)bmca_value($finding, array('section_id'), bmca_value($sectionEvidence, array('id'), 0));
    $finding['block_id'] = (int)bmca_value($finding, array('block_id'), bmca_value($blockEvidence, array('id'), 0));
    $finding['stable_anchor'] = (string)bmca_value($finding, array('stable_anchor'), bmca_value($blockEvidence, array('stable_anchor'), ''));
    if ($finding['stable_anchor'] === '' && isset($blocksEvidence[0]) && is_array($blocksEvidence[0])) {
        $finding['stable_anchor'] = (string)bmca_value($blocksEvidence[0], array('stable_anchor'), '');
    }
    $finding['impact_area_id'] = (int)bmca_value(
        $finding,
        array('impact_area_id'),
        bmca_value($areaEvidence, array('id'), bmca_value($evidence, array('impact_area_id'), 0))
    );
    $finding['manual_code'] = (string)bmca_value($finding, array('manual_code', 'book_key'), bmca_value($versionEvidence, array('book_key'), 'Manual'));
    $finding['section_label'] = (string)bmca_value($finding, array('section_label'), bmca_value($sectionEvidence, array('key', 'title'), 'Section'));
    $finding['exact_citation'] = $finding['manual_code']
        . ' ' . (string)bmca_value($versionEvidence, array('version_label'), '')
        . ' · ' . $finding['section_label']
        . ($blockEvidence !== array() ? ' · block ' . (string)bmca_value($blockEvidence, array('stable_anchor', 'id'), '') : '');
    $finding['confidence_label'] = (string)bmca_value(
        $finding,
        array('confidence_label'),
        $confidenceValue >= 0.8 ? 'high' : ($confidenceValue >= 0.5 ? 'medium' : 'low')
    );
    $finding['action'] = strtolower((string)bmca_value(
        $finding,
        array('impact_treatment', 'action', 'action_classification'),
        'review'
    ));
    $finding['evidence'] = array_values(array_filter(array_merge(array(
        bmca_value($sourceEvidence, array('exact_quote', 'quote', 'text'), ''),
        bmca_value($blockEvidence, array('current_text', 'text'), ''),
    ), array_map(
        static fn(array $block): string => (string)bmca_value($block, array('excerpt', 'current_text', 'text'), ''),
        array_filter($blocksEvidence, 'is_array')
    ))));
    return $finding;
};
$impacts = array_map($normalizeFinding, $impacts);
$proposals = array_map($normalizeFinding, $proposals);
$conflicts = array_map($normalizeFinding, $conflicts);
$approvedChanges = array_map($normalizeFinding, $approvedChanges);

if (!array_key_exists('proposals', $detail) && !array_key_exists('proposed_changes', $detail)) {
    $allFindings = $proposals;
    $impacts = array_values(array_filter($allFindings, static fn(array $finding): bool => (string)bmca_value($finding, array('finding_type'), 'impact') === 'impact'));
    $conflicts = array_values(array_filter($allFindings, static fn(array $finding): bool => in_array((string)bmca_value($finding, array('finding_type'), ''), array('consistency', 'duplication', 'conflict'), true)));
    $proposals = array_values(array_filter($allFindings, static fn(array $finding): bool => (int)bmca_value($finding, array('proposal_id'), 0) > 0));
}
$isAnalyzing = in_array($status, array('queued', 'analyzing', 'processing', 'in_progress'), true);
$canApply = (bool)bmca_value($detail, array('can_apply', 'apply_ready'), false);
$blockers = bmca_array(bmca_value($detail, array('apply_blockers', 'blockers'), array()));
$requirements = bmca_array(bmca_value($detail, array('requirements'), array()));
$changeIntent = bmca_array(bmca_value($detail, array('change_intent'), array()));
$targetWorkflowAreas = bmca_array(bmca_value($detail, array('target_workflow_areas'), array()));
$scopeWarnings = bmca_array(bmca_value($detail, array('scope_warnings'), array()));
$approvedImpactAreaIds = array_values(array_unique(array_filter(array_map(
    static fn(array $impact): int => strtolower((string)bmca_value($impact, array('decision', 'status'), '')) === 'approved'
        ? (int)bmca_value($impact, array('impact_area_id'), 0)
        : 0,
    $impacts
))));
$totalProposals = count($proposals);
$approvedCount = count($approvedChanges);
$reviewedCount = 0;
foreach ($proposals as $proposal) {
    if ((string)bmca_value($proposal, array('decision'), '') !== '') {
        $reviewedCount++;
    }
}
$unresolvedConflictCount = count(array_filter(
    $conflicts,
    static fn(array $conflict): bool => !in_array(
        (string)bmca_value($conflict, array('decision'), ''),
        array('approved', 'rejected'),
        true
    )
));
if ($approvedCount === 0) {
    foreach ($proposals as $proposal) {
        $approvedCount += strtolower((string)bmca_value($proposal, array('decision', 'proposal_status', 'status'), '')) === 'approved' ? 1 : 0;
    }
}
if ($approvedChanges === array()) {
    foreach ($proposals as $proposal) {
        if (strtolower((string)bmca_value($proposal, array('decision', 'status', 'proposal_status'), '')) === 'approved') {
            $approvedChanges[] = $proposal;
        }
    }
}
if (!array_key_exists('can_apply', $detail) && !array_key_exists('apply_ready', $detail)) {
    $allDecided = $proposals !== array();
    foreach ($proposals as $proposal) {
        if (!in_array(strtolower((string)bmca_value($proposal, array('decision'), '')), array('approved', 'rejected', 'dismissed'), true)) {
            $allDecided = false;
            break;
        }
    }
    $editableScope = $scope !== array();
    foreach ($scope as $scopeItem) {
        if (is_array($scopeItem) && !in_array(strtolower((string)bmca_value($scopeItem, array('lifecycle_status', 'status'), '')), array('draft', 'in_review'), true)) {
            $editableScope = false;
            break;
        }
    }
    $canApply = $allDecided && $approvedChanges !== array() && $editableScope && !$isAnalyzing;
}

cw_header('Books & Manuals · Change Project');
books_manuals_page_open(array(
    'title' => $title !== '' ? $title : 'AI Change Project',
    'description' => $requestText !== '' ? mb_strimwidth($requestText, 0, 240, '…') : 'Cited impact analysis and controlled manual change review.',
    'back' => array('href' => '/admin/books_manuals/change_projects.php', 'label' => 'Change Projects', 'code' => $projectId > 0 ? 'Project #' . $projectId : ''),
    'stats' => array(
        array('label' => 'Progress', 'value' => $progress . '%'),
        array('label' => 'Impacts', 'value' => count($impacts)),
        array('label' => 'Proposals', 'value' => $totalProposals),
        array('label' => 'Approved', 'value' => $approvedCount, 'tone' => $approvedCount > 0 ? 'ok' : ''),
    ),
));
?>
<link rel="stylesheet" href="/assets/books-manual-change-assistant.css?v=<?= (int)(@filemtime(__DIR__ . '/../../assets/books-manual-change-assistant.css') ?: time()) ?>">

<main
  class="bmca bmca-workspace"
  data-bmca-app="workspace"
  data-project-id="<?= $projectId ?>"
  data-project-status="<?= h($status) ?>"
  data-api-url="/admin/api/books_manuals_change_assistant_api.php"
  data-csrf-token="<?= h($csrf) ?>"
  data-polling="<?= $isAnalyzing ? 'true' : 'false' ?>"
>
  <?php if (!$tablesReady || !$featureEnabled || $project === array()): ?>
    <section class="cmp-card bmca-readiness" role="status">
      <div class="bmca-readiness__icon" aria-hidden="true">AI</div>
      <div>
        <h2>Workspace unavailable</h2>
        <p><?= h($readinessMessage !== '' ? $readinessMessage : 'This project cannot be loaded.') ?></p>
        <a class="app-btn app-btn--secondary" href="/admin/books_manuals/change_projects.php">Return to Change Projects</a>
      </div>
    </section>
  <?php else: ?>
    <section class="cmp-card bmca-runbar">
      <div class="bmca-runbar__state">
        <span class="bmca-status bmca-status--<?= h(preg_replace('/[^a-z0-9_-]/', '-', $status) ?: 'draft') ?>" data-bmca-status><?= h(ucwords(str_replace('_', ' ', $status))) ?></span>
        <div>
          <strong data-bmca-progress-label><?= $isAnalyzing ? 'Analysis in progress' : 'Project workspace' ?></strong>
          <span data-bmca-progress-detail><?= h((string)bmca_value($project, array('progress_message', 'status_message'), 'Review each AI suggestion against its exact source and manual citation.')) ?></span>
        </div>
      </div>
      <div class="bmca-runbar__meter">
        <div class="bmca-progress"><span data-bmca-progress-bar style="width:<?= $progress ?>%"></span></div>
        <strong data-bmca-progress-value><?= $progress ?>%</strong>
      </div>
      <div class="bmca-actions">
        <button class="app-btn app-btn--primary" type="button" data-bmca-start-analysis <?= $isAnalyzing || !$isOwner ? 'disabled' : '' ?>>
          <?= $isAnalyzing ? 'Analyzing…' : ($jobs === array() ? 'Start impact analysis' : 'Run analysis again') ?>
        </button>
        <button class="app-btn app-btn--secondary" type="button" data-bmca-export-manifest>Export impact report</button>
      </div>
    </section>

    <section class="bmca-coverage" aria-label="Project analysis coverage">
      <div><strong><?= count($requirements) ?></strong><span>Requirements analysed</span></div>
      <div><strong><?= count($scope) ?></strong><span>Manual versions searched</span></div>
      <div><strong><?= $reviewedCount ?>/<?= $totalProposals ?></strong><span>Findings reviewed</span></div>
      <div><strong><?= $unresolvedConflictCount ?></strong><span>Conflicts unresolved</span></div>
      <div><strong><?= $approvedCount ?></strong><span>Proposals approved</span></div>
      <p>Coverage reflects the selected scope and retrieved evidence; it does not imply completeness where retrieval confidence is insufficient.</p>
    </section>

    <nav class="bmca-steps" aria-label="Change project stages">
      <?php foreach (array(
          'source' => 'Source',
          'scope' => 'Manual Scope',
          'impact' => 'Impact Finder',
          'proposals' => 'Proposed Changes',
          'conflicts' => 'Consistency & Conflicts',
          'approved' => 'Approved Change Set',
      ) as $stageKey => $stageLabel): ?>
        <button type="button" data-bmca-stage-target="<?= h($stageKey) ?>" class="<?= $stageKey === 'source' ? 'is-active' : '' ?>">
          <span><?= h((string)(array_search($stageKey, array('source', 'scope', 'impact', 'proposals', 'conflicts', 'approved'), true) + 1)) ?></span>
          <?= h($stageLabel) ?>
        </button>
      <?php endforeach; ?>
    </nav>

    <section class="bmca-stage is-active" data-bmca-stage="source">
      <div class="bmca-stage-heading">
        <div><span class="bmca-kicker">Step 1</span><h2>Source</h2><p>Preserve the instruction and supporting document that prompted this controlled change.</p></div>
      </div>
      <div class="bmca-two-column">
        <article class="cmp-card bmca-panel">
          <h3>Change request</h3>
          <label class="bmca-field">
            <span>Typed request</span>
            <textarea rows="10" maxlength="12000" data-bmca-request-text><?= h($requestText) ?></textarea>
          </label>
          <div class="bmca-actions">
            <button class="app-btn app-btn--primary" type="button" data-bmca-save-source <?= $isOwner ? '' : 'disabled' ?>>Save source</button>
          </div>
        </article>
        <article class="cmp-card bmca-panel">
          <h3>Supporting source</h3>
          <?php if ($source !== array()): ?>
            <div class="bmca-source-file">
              <span aria-hidden="true">DOC</span>
              <div>
                <strong><?= h((string)bmca_value($source, array('filename', 'original_filename', 'name'), 'Uploaded source')) ?></strong>
                <small><?= h((string)bmca_value($source, array('mime_type', 'type'), 'Document')) ?> · <?= h((string)bmca_value($source, array('uploaded_at', 'created_at'), '')) ?></small>
              </div>
            </div>
          <?php endif; ?>
          <label class="bmca-upload">
            <input type="file" data-bmca-workspace-upload accept=".pdf,.docx,.txt">
            <span class="bmca-upload__icon" aria-hidden="true">↑</span>
            <span><strong><?= $source === array() ? 'Attach source document' : 'Replace source document' ?></strong><small data-bmca-file-name>Choose a supported document</small></span>
          </label>
          <div class="bmca-actions">
            <button class="app-btn app-btn--secondary" type="button" data-bmca-upload-source <?= $isOwner ? '' : 'disabled' ?>>Upload source</button>
          </div>
        </article>
      </div>
      <?php if ($changeIntent !== array()): ?>
        <article class="cmp-card bmca-panel bmca-intent-summary">
          <div>
            <span class="bmca-kicker">Authoritative change intent</span>
            <h3><?= h((string)bmca_value($changeIntent, array('change_type'), 'Change')) ?> · <?= h((string)bmca_value($changeIntent, array('primary_domain'), 'Controlled manuals')) ?></h3>
            <p><?= h((string)bmca_value($changeIntent, array('summary'), '')) ?></p>
          </div>
          <div class="bmca-intent-summary__areas">
            <?php foreach ($targetWorkflowAreas as $area): ?>
              <?php if (is_array($area)): ?><span class="bmca-chip"><?= h((string)bmca_value($area, array('title'), 'Workflow area')) ?></span><?php endif; ?>
            <?php endforeach; ?>
          </div>
        </article>
      <?php endif; ?>
    </section>

    <section class="bmca-stage" data-bmca-stage="scope" hidden>
      <div class="bmca-stage-heading">
        <div><span class="bmca-kicker">Step 2</span><h2>Manual Scope</h2><p>Select exact controlled revisions. Analysis never silently expands beyond this scope.</p></div>
        <button class="app-btn app-btn--primary" type="button" data-bmca-save-scope <?= $isOwner ? '' : 'disabled' ?>>Save manual scope</button>
      </div>
      <div class="bmca-scope-grid" data-bmca-scope-list>
        <?php
          $selectedVersionIds = array_map(
              'intval',
              array_map(static fn(mixed $item): mixed => is_array($item) ? bmca_value($item, array('version_id', 'id'), 0) : $item, $scope)
          );
        ?>
        <?php foreach ($versions as $version): ?>
          <?php
            $versionId = (int)bmca_value($version, array('version_id', 'id'), 0);
            if ($versionId <= 0) { continue; }
            $selected = in_array($versionId, $selectedVersionIds, true);
          ?>
          <label class="cmp-card bmca-scope-card <?= $selected ? 'is-selected' : '' ?>">
            <input type="checkbox" value="<?= $versionId ?>" data-bmca-scope-version <?= $selected ? 'checked' : '' ?>>
            <span class="bmca-scope-card__check">✓</span>
            <span class="bmca-kicker"><?= h((string)bmca_value($version, array('book_key', 'manual_code'), 'Manual')) ?></span>
            <strong><?= h((string)bmca_value($version, array('title', 'book_title'), 'Controlled manual')) ?></strong>
            <small>Revision <?= h((string)bmca_value($version, array('version_label', 'revision'), '—')) ?> · <?= h(ucwords(str_replace('_', ' ', (string)bmca_value($version, array('lifecycle_status', 'status'), '')))) ?></small>
          </label>
        <?php endforeach; ?>
        <?php if ($versions === array()): ?><div class="cmp-card bmca-empty">No controlled manual versions are currently available.</div><?php endif; ?>
      </div>
      <?php if ($scopeWarnings !== array()): ?>
        <div class="bmca-scope-warnings">
          <?php foreach ($scopeWarnings as $warning): ?>
            <?php if (is_array($warning)): ?><div class="bmca-alert bmca-alert--warning"><strong>Scope warning</strong><span><?= h((string)bmca_value($warning, array('message'), 'Review the resolved manual scope.')) ?></span></div><?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="bmca-stage" data-bmca-stage="impact" hidden>
      <div class="bmca-stage-heading">
        <div><span class="bmca-kicker">Step 3</span><h2>Impact Finder</h2><p>Consolidated amendment areas reasoned from the complete change intent and current section context.</p></div>
        <div class="bmca-filters">
          <select data-bmca-filter="manual" aria-label="Filter impacted manual"><option value="">All manuals</option><?php foreach ($scope as $scopeItem): ?><?php if (is_array($scopeItem)): ?><option value="<?= h(strtolower((string)bmca_value($scopeItem, array('book_key'), ''))) ?>"><?= h((string)bmca_value($scopeItem, array('book_key', 'book_title'), 'Manual')) ?></option><?php endif; ?><?php endforeach; ?></select>
          <select data-bmca-filter="confidence" aria-label="Filter impact confidence"><option value="">All confidence</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option></select>
          <select data-bmca-filter="action" aria-label="Filter action"><option value="">All actions</option><option value="keep">Keep</option><option value="delete">Delete</option><option value="replace">Replace</option><option value="amend">Amend</option><option value="add">Add</option><option value="cross_reference">Cross-reference</option><option value="review">Review</option></select>
        </div>
      </div>
      <div class="bmca-impact-list">
        <?php foreach ($impacts as $impact): ?>
          <?php
            $confidence = strtolower((string)bmca_value($impact, array('confidence_label', 'confidence'), 'unknown'));
            $action = strtolower((string)bmca_value($impact, array('action', 'change_action'), 'investigate'));
          ?>
          <article class="cmp-card bmca-impact" data-bmca-impact-finding="<?= (int)bmca_value($impact, array('id'), 0) ?>" data-bmca-impact-area="<?= (int)bmca_value($impact, array('impact_area_id'), 0) ?>" data-bmca-filter-item data-manual="<?= h(strtolower((string)bmca_value($impact, array('manual_code', 'book_key'), ''))) ?>" data-confidence="<?= h($confidence) ?>" data-action="<?= h($action) ?>">
            <div class="bmca-impact__meta">
              <span class="bmca-confidence bmca-confidence--<?= h($confidence) ?>"><?= h(ucfirst($confidence)) ?> confidence</span>
              <span class="bmca-chip"><?= h(ucfirst($action)) ?></span>
            </div>
            <span class="bmca-kicker"><?= h((string)bmca_value($impact, array('manual_code', 'book_key'), 'Controlled manual')) ?></span>
            <h3><?= h((string)bmca_value($impact, array('impact_title', 'title', 'summary', 'section_label'), 'Affected manual area')) ?></h3>
            <a class="bmca-citation" href="<?= h((string)bmca_value($impact, array('reader_url'), '/admin/books_manuals/reader.php?version_id=' . (int)bmca_value($impact, array('version_id'), 0))) ?>" target="_blank" rel="noopener">
              <?= h((string)bmca_value($impact, array('citation', 'exact_citation', 'section_label'), 'Citation unavailable')) ?> ↗
            </a>
            <?php if ((string)bmca_value($impact, array('requirement_text'), '') !== ''): ?>
              <div class="bmca-impact__requirement">
                <strong><?= h((string)bmca_value($impact, array('requirement_key'), 'Source requirement')) ?></strong>
                <span><?= h((string)bmca_value($impact, array('requirement_text'), '')) ?></span>
              </div>
            <?php endif; ?>
            <p><?= h((string)bmca_value($impact, array('impact_summary', 'reason', 'rationale', 'summary'), '')) ?></p>
            <?php $evidence = bmca_array(bmca_value($impact, array('evidence', 'evidence_items'), array())); ?>
            <?php if ($evidence !== array()): ?>
              <details class="bmca-evidence"><summary>Evidence (<?= count($evidence) ?>)</summary>
                <?php foreach ($evidence as $item): ?><blockquote><?= h(is_array($item) ? (string)bmca_value($item, array('quote', 'text', 'excerpt'), '') : (string)$item) ?></blockquote><?php endforeach; ?>
              </details>
            <?php endif; ?>
            <?php
              $canDecideImpact = $isOwner
                  || (int)bmca_value($impact, array('assigned_reviewer_id'), 0) === (int)($user['id'] ?? 0);
              $impactDecision = strtolower((string)bmca_value($impact, array('decision', 'status'), 'proposed'));
            ?>
            <div class="bmca-impact__decision">
              <label class="bmca-field">
                <span>Impact decision rationale <small>required for dismissal</small></span>
                <textarea rows="2" maxlength="4000" data-bmca-impact-rationale <?= $canDecideImpact ? '' : 'disabled' ?>><?= h((string)bmca_value($impact, array('decision_note'), '')) ?></textarea>
              </label>
              <div class="bmca-proposal__actions">
                <button class="app-btn app-btn--primary" type="button" data-bmca-impact-decision="approved" <?= $canDecideImpact ? '' : 'disabled' ?>>Approve impact</button>
                <button class="app-btn app-btn--danger" type="button" data-bmca-impact-decision="rejected" <?= $canDecideImpact ? '' : 'disabled' ?>>Dismiss</button>
                <span class="bmca-status bmca-status--<?= h($impactDecision) ?>" data-bmca-impact-status><?= h(ucfirst($impactDecision)) ?></span>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
        <?php if ($impacts === array()): ?><div class="cmp-card bmca-empty">No impacts yet. Save source and scope, then start analysis.</div><?php endif; ?>
      </div>
    </section>

    <section class="bmca-stage" data-bmca-stage="proposals" hidden>
      <div class="bmca-stage-heading">
        <div><span class="bmca-kicker">Step 4</span><h2>Proposed Changes</h2><p>Generate coherent amendments only from approved impact areas, then review each controlled redline.</p></div>
        <?php if ($isOwner && $approvedImpactAreaIds !== array()): ?>
          <button class="app-btn app-btn--primary" type="button" data-bmca-start-compose data-impact-area-ids="<?= h(implode(',', $approvedImpactAreaIds)) ?>">Generate proposed amendments</button>
        <?php endif; ?>
        <div class="bmca-filters">
          <select data-bmca-filter="manual" aria-label="Filter proposal manual"><option value="">All manuals</option><?php foreach ($scope as $scopeItem): ?><?php if (is_array($scopeItem)): ?><option value="<?= h(strtolower((string)bmca_value($scopeItem, array('book_key'), ''))) ?>"><?= h((string)bmca_value($scopeItem, array('book_key', 'book_title'), 'Manual')) ?></option><?php endif; ?><?php endforeach; ?></select>
          <select data-bmca-filter="status" aria-label="Filter decision status"><option value="">All statuses</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="edited">Edited</option><option value="dismissed">Dismissed</option></select>
          <select data-bmca-filter="confidence" aria-label="Filter confidence"><option value="">All confidence</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option></select>
          <select data-bmca-filter="action" aria-label="Filter action"><option value="">All actions</option><option value="add">Add</option><option value="delete">Delete</option><option value="replace">Replace</option><option value="amend">Amend</option><option value="cross_reference">Cross-reference</option><option value="review">Review</option></select>
        </div>
      </div>
      <div class="bmca-review-layout">
        <aside class="bmca-requirement-queue" aria-label="Extracted requirements">
          <h3>Requirements</h3>
          <?php foreach ($requirements as $requirement): ?>
            <?php
              $requirementId = (int)bmca_value($requirement, array('id'), 0);
              $covered = count(array_filter(
                  $proposals,
                  static fn(array $proposal): bool => (int)bmca_value($proposal, array('requirement_id'), 0) === $requirementId
              ));
            ?>
            <article>
              <span><?= h((string)bmca_value($requirement, array('requirement_key'), 'Requirement')) ?></span>
              <p><?= h(mb_strimwidth((string)bmca_value($requirement, array('requirement_text'), ''), 0, 180, '…')) ?></p>
              <?php $validationStatus = (string)bmca_value($requirement, array('validation_status'), 'active'); ?>
              <small><?= h($validationStatus !== 'active' ? ucwords(str_replace('_', ' ', $validationStatus)) . ' · excluded from impact generation' : ($covered > 0 ? $covered . ' cited finding' . ($covered === 1 ? '' : 's') : 'No retrieved coverage')) ?></small>
            </article>
          <?php endforeach; ?>
          <?php if ($requirements === array()): ?><p class="bmca-muted">Requirements appear after analysis.</p><?php endif; ?>
        </aside>
        <aside class="bmca-review-queue" aria-label="Proposal queue">
          <?php foreach ($proposals as $index => $proposal): ?>
            <?php
              $proposalId = (int)bmca_value($proposal, array('id', 'proposal_id', 'change_id'), 0);
              $proposalStatus = strtolower((string)bmca_value($proposal, array('decision', 'proposal_status', 'status'), 'pending'));
              $proposalConfidence = strtolower((string)bmca_value($proposal, array('confidence_label', 'confidence'), 'unknown'));
              $proposalAction = strtolower((string)bmca_value($proposal, array('action', 'change_action'), 'replace'));
            ?>
            <button
              type="button"
              class="bmca-queue-item <?= $index === 0 ? 'is-active' : '' ?>"
              data-bmca-proposal-select="<?= $proposalId ?>"
              data-bmca-filter-item
              data-manual="<?= h(strtolower((string)bmca_value($proposal, array('manual_code', 'book_key'), ''))) ?>"
              data-status="<?= h($proposalStatus) ?>"
              data-confidence="<?= h($proposalConfidence) ?>"
              data-action="<?= h($proposalAction) ?>"
            >
              <span><?= h((string)bmca_value($proposal, array('manual_code', 'book_key'), 'Manual')) ?> · <?= h((string)bmca_value($proposal, array('section_label', 'citation'), 'Section')) ?></span>
              <strong><?= h((string)bmca_value($proposal, array('title', 'summary'), 'Proposed change')) ?></strong>
              <small><?= h(ucfirst($proposalAction)) ?> · <?= h(ucfirst($proposalConfidence)) ?> · <?= h(ucfirst($proposalStatus)) ?></small>
            </button>
          <?php endforeach; ?>
        </aside>
        <div class="bmca-review-detail">
          <?php foreach ($proposals as $index => $proposal): ?>
            <?php
              $proposalId = (int)bmca_value($proposal, array('id', 'proposal_id', 'change_id'), 0);
              $canDecideProposal = $isOwner
                  || (int)bmca_value($proposal, array('assigned_reviewer_id'), 0) === (int)($user['id'] ?? 0);
              $versionId = (int)bmca_value($proposal, array('version_id'), 0);
              $sectionId = (int)bmca_value($proposal, array('section_id'), 0);
              $blockId = (int)bmca_value($proposal, array('block_id'), 0);
              $stableAnchor = (string)bmca_value($proposal, array('stable_anchor'), '');
              $currentText = (string)bmca_value($proposal, array('current_text', 'before_text', 'original_text'), '');
              $proposedText = (string)bmca_value($proposal, array('proposed_text', 'after_text', 'replacement_text'), '');
              $decision = strtolower((string)bmca_value($proposal, array('decision', 'proposal_status', 'status'), 'pending'));
              $evidence = bmca_array(bmca_value($proposal, array('evidence', 'evidence_items'), array()));
            ?>
            <article class="cmp-card bmca-proposal <?= $index === 0 ? 'is-active' : '' ?>" data-bmca-proposal="<?= $proposalId ?>" <?= $index === 0 ? '' : 'hidden' ?>>
              <header class="bmca-proposal__header">
                <div>
                  <span class="bmca-kicker"><?= h((string)bmca_value($proposal, array('manual_code', 'book_key'), 'Manual')) ?></span>
                  <h3><?= h((string)bmca_value($proposal, array('title', 'summary'), 'Proposed change')) ?></h3>
                  <a class="bmca-citation" href="/admin/books_manuals/reader.php?version_id=<?= $versionId ?><?= $stableAnchor !== '' ? '#' . rawurlencode($stableAnchor) : '' ?>" target="_blank" rel="noopener"><?= h((string)bmca_value($proposal, array('exact_citation', 'citation', 'section_label'), 'Citation unavailable')) ?> ↗</a>
                </div>
                <span class="bmca-status bmca-status--<?= h($decision) ?>" data-bmca-decision-status><?= h(ucfirst($decision)) ?></span>
              </header>
              <div class="bmca-redline">
                <section><span>Current controlled text</span><div class="bmca-redline__current"><?= nl2br(h($currentText)) ?></div></section>
                <section><span>Proposed redline</span><div class="bmca-redline__proposed"><?= nl2br(h($proposedText)) ?></div></section>
              </div>
              <label class="bmca-field bmca-edit-field" hidden data-bmca-edit-field>
                <span>Human-edited proposed text</span>
                <textarea rows="10" data-bmca-edited-text><?= h((string)bmca_value($proposal, array('edited_text'), $proposedText)) ?></textarea>
              </label>
              <div class="bmca-proposal__reason">
                <strong>Why this change</strong>
                <p><?= h((string)bmca_value($proposal, array('rationale', 'reason'), 'No rationale supplied.')) ?></p>
              </div>
              <details class="bmca-evidence" <?= $evidence !== array() ? 'open' : '' ?>>
                <summary>Source evidence (<?= count($evidence) ?>)</summary>
                <?php foreach ($evidence as $item): ?>
                  <blockquote>
                    <?= h(is_array($item) ? (string)bmca_value($item, array('quote', 'text', 'excerpt'), '') : (string)$item) ?>
                    <?php if (is_array($item) && bmca_value($item, array('citation', 'source_citation'), '') !== ''): ?><cite><?= h((string)bmca_value($item, array('citation', 'source_citation'), '')) ?></cite><?php endif; ?>
                  </blockquote>
                <?php endforeach; ?>
              </details>
              <label class="bmca-field">
                <span>Decision rationale <small>required for edit or dismissal</small></span>
                <textarea rows="3" maxlength="4000" data-bmca-decision-rationale placeholder="Record why this decision is appropriate." <?= $canDecideProposal ? '' : 'disabled' ?>><?= h((string)bmca_value($proposal, array('decision_rationale'), '')) ?></textarea>
              </label>
              <div class="bmca-proposal__actions">
                <button class="app-btn app-btn--primary" type="button" data-bmca-decision="approve" <?= $canDecideProposal ? '' : 'disabled' ?>>Approve</button>
                <button class="app-btn app-btn--secondary" type="button" data-bmca-edit-toggle <?= $canDecideProposal ? '' : 'disabled' ?>>Edit proposal</button>
                <button class="app-btn app-btn--secondary" type="button" data-bmca-decision="edit" hidden>Save edit</button>
                <button class="app-btn app-btn--danger" type="button" data-bmca-decision="dismiss" <?= $canDecideProposal ? '' : 'disabled' ?>>Dismiss</button>
                <label class="bmca-reviewer">
                  <span class="sr-only">Assigned reviewer</span>
                  <select data-bmca-reviewer <?= $isOwner ? '' : 'disabled' ?>>
                    <option value="">Unassigned reviewer</option>
                    <?php foreach ($reviewers as $reviewer): ?>
                      <option
                        value="<?= (int)($reviewer['id'] ?? 0) ?>"
                        <?= (int)bmca_value($proposal, array('assigned_reviewer_id'), 0) === (int)($reviewer['id'] ?? 0) ? 'selected' : '' ?>
                      ><?= h((string)($reviewer['name'] ?? $reviewer['email'] ?? 'Reviewer')) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <span class="bmca-deep-links">
                  <a href="/admin/books_manuals/reader.php?version_id=<?= $versionId ?><?= $stableAnchor !== '' ? '#' . rawurlencode($stableAnchor) : '' ?>" target="_blank" rel="noopener">Reader ↗</a>
                  <a href="/admin/compliance/controlled_book_editor.php?version_id=<?= $versionId ?><?= $sectionId > 0 ? '&section_id=' . $sectionId : '' ?><?= $blockId > 0 ? '&block_id=' . $blockId : '' ?>" target="_blank" rel="noopener">Editor ↗</a>
                </span>
              </div>
            </article>
          <?php endforeach; ?>
          <?php if ($proposals === array()): ?><div class="cmp-card bmca-empty">Approve relevant Impact Finder areas, then generate proposed amendments.</div><?php endif; ?>
        </div>
      </div>
    </section>

    <section class="bmca-stage" data-bmca-stage="conflicts" hidden>
      <div class="bmca-stage-heading">
        <div><span class="bmca-kicker">Step 5</span><h2>Consistency &amp; Conflicts</h2><p>Cross-manual contradictions, terminology drift, duplicate clauses and unresolved dependencies.</p></div>
      </div>
      <div class="bmca-conflict-list">
        <?php foreach ($conflicts as $conflict): ?>
          <?php $severity = strtolower((string)bmca_value($conflict, array('severity'), 'medium')); ?>
          <article class="cmp-card bmca-conflict bmca-conflict--<?= h($severity) ?>">
            <div><span class="bmca-status"><?= h(ucfirst($severity)) ?></span><span class="bmca-chip"><?= h(ucwords(str_replace('_', ' ', (string)bmca_value($conflict, array('type', 'conflict_type'), 'consistency')))) ?></span></div>
            <h3><?= h((string)bmca_value($conflict, array('title', 'summary'), 'Potential conflict')) ?></h3>
            <p><?= h((string)bmca_value($conflict, array('description', 'reason', 'rationale'), '')) ?></p>
            <?php foreach (bmca_array(bmca_value($conflict, array('citations', 'affected_citations'), array())) as $citation): ?><span class="bmca-citation"><?= h(is_array($citation) ? (string)bmca_value($citation, array('citation', 'label'), '') : (string)$citation) ?></span><?php endforeach; ?>
          </article>
        <?php endforeach; ?>
        <?php if ($conflicts === array()): ?><div class="cmp-card bmca-empty"><h3>No unresolved conflicts</h3><p>Consistency results will appear here after analysis.</p></div><?php endif; ?>
      </div>
    </section>

    <section class="bmca-stage" data-bmca-stage="approved" hidden>
      <div class="bmca-stage-heading">
        <div><span class="bmca-kicker">Step 6</span><h2>Approved Change Set</h2><p>Only human-approved or human-edited changes can enter the guarded apply operation.</p></div>
        <button class="app-btn app-btn--primary bmca-apply" type="button" data-bmca-apply <?= $canApply && $isOwner ? '' : 'disabled' ?>>Apply approved changes</button>
      </div>
      <?php if (!$canApply): ?>
        <div class="cmp-alert cmp-alert--error bmca-apply-guard" data-bmca-apply-guard>
          <strong>Apply is guarded.</strong>
          <?php if ($blockers !== array()): ?>
            <?= h(implode(' ', array_map(static fn(mixed $blocker): string => is_array($blocker) ? (string)bmca_value($blocker, array('message', 'label', 'code'), 'Unresolved blocker.') : (string)$blocker, $blockers))) ?>
          <?php else: ?>
            Complete analysis, resolve conflicts, and record a decision for every proposal before applying.
          <?php endif; ?>
          <?php foreach ($scope as $scopeItem): ?>
            <?php if (is_array($scopeItem) && (string)bmca_value($scopeItem, array('lifecycle_status', 'status'), '') === 'released'): ?>
              <button
                class="app-btn app-btn--secondary"
                type="button"
                data-bmca-create-revision="<?= (int)bmca_value($scopeItem, array('book_version_id', 'version_id', 'id'), 0) ?>"
                <?= $isOwner ? '' : 'disabled' ?>
              >
                Create draft revision for <?= h((string)bmca_value($scopeItem, array('book_key', 'book_title'), 'manual')) ?>
              </button>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="bmca-approved-list">
        <?php foreach ($approvedChanges as $change): ?>
          <article class="cmp-card bmca-approved-item">
            <div><span class="bmca-status bmca-status--approved">Approved</span><span class="bmca-chip"><?= h((string)bmca_value($change, array('manual_code', 'book_key'), 'Manual')) ?></span></div>
            <h3><?= h((string)bmca_value($change, array('title', 'summary'), 'Approved change')) ?></h3>
            <span class="bmca-citation"><?= h((string)bmca_value($change, array('exact_citation', 'citation', 'section_label'), '')) ?></span>
            <p><?= h(mb_strimwidth((string)bmca_value($change, array('edited_text', 'proposed_text', 'after_text'), ''), 0, 320, '…')) ?></p>
          </article>
        <?php endforeach; ?>
        <?php if ($approvedChanges === array()): ?><div class="cmp-card bmca-empty">Approved proposals will collect here as the controlled change set.</div><?php endif; ?>
      </div>
    </section>

    <dialog class="compliance-modal bmca-confirm-modal" id="bmca-apply-confirm">
      <div class="compliance-modal__panel">
        <div class="compliance-modal__header"><h2 class="compliance-modal__title">Apply approved change set?</h2><button type="button" class="compliance-modal__close" data-compliance-modal-close aria-label="Close">&times;</button></div>
        <div class="compliance-modal__body">
          <div class="cmp-alert cmp-alert--error"><strong>This is a controlled write.</strong> The approved changes will be applied to the scoped draft revisions and recorded in the project manifest.</div>
          <label class="bmca-confirm-check"><input type="checkbox" data-bmca-apply-ack><span>I reviewed the exact citations, redlines, evidence and decisions and authorize this apply operation.</span></label>
          <label class="bmca-field"><span>Apply rationale</span><textarea rows="4" minlength="10" maxlength="4000" data-bmca-apply-rationale placeholder="Record why this approved change set is ready to apply."></textarea></label>
          <div class="bmca-actions"><button class="app-btn app-btn--primary" type="button" data-bmca-confirm-apply disabled>Confirm and apply</button><button class="app-btn app-btn--secondary" type="button" data-compliance-modal-close>Cancel</button></div>
        </div>
      </div>
    </dialog>
  <?php endif; ?>
  <div class="bmca-toast" data-bmca-toast role="status" aria-live="polite" hidden></div>
</main>

<script src="/assets/books-manual-change-assistant.js?v=<?= (int)(@filemtime(__DIR__ . '/../../assets/books-manual-change-assistant.js') ?: time()) ?>"></script>
<?php
compliance_page_close();
cw_footer();
