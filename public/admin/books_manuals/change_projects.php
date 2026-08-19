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
$architectServiceFile = __DIR__ . '/../../../src/publishing/BooksManualsChangePlanService.php';
if (is_file($architectServiceFile)) {
    require_once $architectServiceFile;
}

$user = compliance_require_access($pdo);
$csrf = (string)($_SESSION['books_manuals_ai_csrf'] ?? '');
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(32));
    $_SESSION['books_manuals_ai_csrf'] = $csrf;
}

$service = null;
$tablesReady = false;
$featureEnabled = false;
$readinessMessage = '';
$projects = array();
$versions = array();
$latestArchitectPlanId = 0;

try {
    if (!class_exists('BooksManualsChangeAssistantService')) {
        throw new RuntimeException('The AI Change Assistant service is not installed on this deployment.');
    }
    $service = new BooksManualsChangeAssistantService($pdo);
    if (!method_exists($service, 'tablesPresent') || !$service->tablesPresent()) {
        throw new RuntimeException('Install the AI Manual Change Assistant migration to activate change projects.');
    }
    $tablesReady = true;
    $featureEnabled = method_exists($service, 'featureEnabled')
        ? (bool)$service->featureEnabled()
        : (method_exists($service, 'enabled') ? (bool)$service::enabled() : true);
    if (!$featureEnabled) {
        throw new RuntimeException('The AI Manual Change Assistant feature is currently disabled.');
    }
    $projects = method_exists($service, 'listProjects')
        ? $service->listProjects(100, (int)($user['id'] ?? 0))
        : array();
    if (method_exists($service, 'listAvailableVersions')) {
        $versions = $service->listAvailableVersions();
    } elseif (class_exists('BooksManualsWorkflowService')) {
        $versions = (new BooksManualsWorkflowService($pdo))->listLibrary(false);
    }
    $projects = is_array($projects) ? $projects : array();
    $versions = is_array($versions) ? $versions : array();
    if (method_exists($service, 'getProject')) {
        foreach ($projects as &$projectRow) {
            if (!isset($projectRow['manual_scope']) && (int)($projectRow['id'] ?? 0) > 0) {
                try {
                    $projectSnapshot = $service->getProject((int)$projectRow['id']);
                    $projectRow['manual_scope'] = $projectSnapshot['scopes'] ?? array();
                } catch (Throwable) {
                    $projectRow['manual_scope'] = array();
                }
            }
        }
        unset($projectRow);
    }
    if (class_exists('BooksManualsChangePlanService')) {
        $architectPlans = new BooksManualsChangePlanService($pdo);
        if ($architectPlans->tablesPresent()) {
            $latestArchitectPlanId = (int)$pdo->query(
                'SELECT id FROM ipca_manual_ai_architect_plans
                 ORDER BY updated_at DESC,id DESC LIMIT 1'
            )->fetchColumn();
        }
    }
} catch (Throwable $e) {
    $readinessMessage = $e->getMessage();
}

$activeCount = 0;
$reviewCount = 0;
$readyCount = 0;
foreach ($projects as $project) {
    $status = strtolower((string)($project['status'] ?? $project['project_status'] ?? 'draft'));
    $activeCount += in_array($status, array('queued', 'analyzing', 'processing', 'in_progress'), true) ? 1 : 0;
    $reviewCount += in_array($status, array('review', 'review_ready', 'awaiting_review'), true) ? 1 : 0;
    $readyCount += in_array($status, array('approved', 'ready', 'ready_to_apply'), true) ? 1 : 0;
}

cw_header('Books & Manuals · AI Change Assistant');
books_manuals_page_open(array(
    'title' => 'AI Manual Change Assistant',
    'description' => 'Turn a typed instruction or source document into a cited, reviewable change set—without changing a controlled manual until you approve and apply it.',
    'back' => array('href' => '/admin/books_manuals/index.php', 'label' => 'IPCA Library'),
    'stats' => array(
        array('label' => 'Projects', 'value' => count($projects)),
        array('label' => 'Analyzing', 'value' => $activeCount),
        array('label' => 'Awaiting review', 'value' => $reviewCount, 'tone' => $reviewCount > 0 ? 'warn' : ''),
        array('label' => 'Ready to apply', 'value' => $readyCount, 'tone' => $readyCount > 0 ? 'ok' : ''),
    ),
    'actions' => $featureEnabled
        ? array_values(array_filter(array(
            $latestArchitectPlanId > 0 ? array(
                'label' => 'Manual Change Wizzard',
                'href' => '/admin/books_manuals/change_architect.php?plan_id=' . $latestArchitectPlanId,
                'variant' => 'secondary',
            ) : null,
            array('label' => 'New Change Project', 'modal' => 'bmca-create-project', 'icon' => 'plus'),
        )))
        : array(),
));
?>
<link rel="stylesheet" href="/assets/books-manual-change-assistant.css?v=<?= (int)(@filemtime(__DIR__ . '/../../assets/books-manual-change-assistant.css') ?: time()) ?>">

<main
  class="bmca"
  data-bmca-app="projects"
  data-api-url="/admin/api/books_manuals_change_assistant_api.php"
  data-csrf-token="<?= h($csrf) ?>"
>
  <?php if (!$tablesReady || !$featureEnabled): ?>
    <section class="cmp-card bmca-readiness" role="status">
      <div class="bmca-readiness__icon" aria-hidden="true">AI</div>
      <div>
        <h2>Change Assistant unavailable</h2>
        <p><?= h($readinessMessage !== '' ? $readinessMessage : 'This feature is not ready on this deployment.') ?></p>
        <p class="bmca-muted">The IPCA Library and existing manual workflows remain available and unchanged.</p>
      </div>
    </section>
  <?php else: ?>
    <section class="bmca-project-toolbar" aria-label="Change project tools">
      <div>
        <h2>Change projects</h2>
        <p>Persistent workspaces retain source evidence, review decisions and the final apply manifest.</p>
      </div>
      <label class="bmca-search">
        <span class="sr-only">Search projects</span>
        <input type="search" placeholder="Search request, manual or status…" data-bmca-project-search>
      </label>
    </section>

    <section class="bmca-project-list" data-bmca-project-list>
      <?php foreach ($projects as $project): ?>
        <?php
          $projectId = (int)($project['id'] ?? $project['project_id'] ?? 0);
          $title = trim((string)($project['title'] ?? $project['request_title'] ?? $project['name'] ?? ''));
          $request = trim((string)($project['request_text'] ?? $project['typed_request'] ?? $project['request'] ?? $project['description'] ?? ''));
          $status = strtolower((string)($project['status'] ?? $project['project_status'] ?? 'draft'));
          $progress = max(0, min(100, (int)($project['progress_percent'] ?? $project['progress'] ?? 0)));
          $manualScope = $project['manual_scope'] ?? $project['manuals'] ?? array();
          if (is_string($manualScope)) {
              $decodedScope = json_decode($manualScope, true);
              $manualScope = is_array($decodedScope) ? $decodedScope : array($manualScope);
          }
          $manualScope = is_array($manualScope) ? $manualScope : array();
          $scopeLabels = array();
          foreach ($manualScope as $scopeItem) {
              if (is_array($scopeItem)) {
                  $scopeLabels[] = (string)($scopeItem['book_key'] ?? $scopeItem['manual_code'] ?? $scopeItem['title'] ?? $scopeItem['book_title'] ?? '');
              } else {
                  $scopeLabels[] = (string)$scopeItem;
              }
          }
          $scopeLabels = array_values(array_filter(array_unique($scopeLabels)));
          $updatedAt = (string)($project['updated_at'] ?? $project['created_at'] ?? '');
        ?>
        <article class="cmp-card bmca-project-card" data-project-search="<?= h(strtolower($title . ' ' . $request . ' ' . $status . ' ' . implode(' ', $scopeLabels))) ?>">
          <div class="bmca-project-card__main">
            <div class="bmca-project-card__topline">
              <span class="bmca-status bmca-status--<?= h(preg_replace('/[^a-z0-9_-]/', '-', $status) ?: 'draft') ?>"><?= h(ucwords(str_replace('_', ' ', $status))) ?></span>
              <span class="bmca-muted"><?= h($updatedAt !== '' ? $updatedAt : 'Not started') ?></span>
            </div>
            <h3><?= h($title !== '' ? $title : ($request !== '' ? mb_strimwidth($request, 0, 96, '…') : 'Untitled change project')) ?></h3>
            <?php if ($request !== '' && $title !== ''): ?><p><?= h(mb_strimwidth($request, 0, 220, '…')) ?></p><?php endif; ?>
            <div class="bmca-scope-chips">
              <?php if ($scopeLabels === array()): ?>
                <span class="bmca-chip bmca-chip--muted">Manual scope not selected</span>
              <?php else: ?>
                <?php foreach (array_slice($scopeLabels, 0, 4) as $scopeLabel): ?><span class="bmca-chip"><?= h($scopeLabel) ?></span><?php endforeach; ?>
                <?php if (count($scopeLabels) > 4): ?><span class="bmca-chip">+<?= count($scopeLabels) - 4 ?></span><?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
          <div class="bmca-project-card__progress">
            <strong><?= $progress ?>%</strong>
            <div class="bmca-progress" aria-label="Project progress <?= $progress ?> percent"><span style="width:<?= $progress ?>%"></span></div>
            <a class="app-btn app-btn--primary" href="/admin/books_manuals/change_project.php?id=<?= $projectId ?>">Open workspace</a>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if ($projects === array()): ?>
        <div class="cmp-card bmca-empty" data-bmca-empty>
          <div class="bmca-empty__mark">✦</div>
          <h2>No change projects yet</h2>
          <p>Describe a required change, attach a source if useful, then select the controlled manuals to analyze.</p>
          <button class="app-btn app-btn--primary" type="button" data-compliance-modal-open="bmca-create-project">Create first project</button>
        </div>
      <?php endif; ?>
    </section>

    <?php compliance_modal_open('bmca-create-project', 'Create AI Change Project'); ?>
      <form class="bmca-create-form" data-bmca-create-form enctype="multipart/form-data">
        <div class="bmca-form-intro">
          <span class="bmca-kicker">Source</span>
          <p>Start with a precise request. A regulation, notice, PDF, DOCX or text source can be attached now or later.</p>
        </div>
        <label class="bmca-field">
          <span>Project title <small>optional</small></span>
          <input name="title" maxlength="160" placeholder="e.g. Update stabilized approach policy">
        </label>
        <label class="bmca-field">
          <span>What needs to change?</span>
          <textarea name="request_text" required minlength="10" maxlength="12000" rows="7" placeholder="Describe the operational or regulatory change, why it is needed, and any known effective date."></textarea>
        </label>
        <label class="bmca-upload">
          <input type="file" name="source_file" data-bmca-source-file accept=".pdf,.docx,.txt">
          <span class="bmca-upload__icon" aria-hidden="true">↑</span>
          <span><strong>Attach optional source file</strong><small data-bmca-file-name>PDF, DOCX, TXT and related documents</small></span>
        </label>
        <div class="bmca-field">
          <span>Initial manual scope <small>optional</small></span>
          <span class="bmca-version-picker" data-bmca-version-picker>
            <?php foreach ($versions as $version): ?>
              <?php $versionId = (int)($version['version_id'] ?? $version['id'] ?? 0); ?>
              <?php if ($versionId > 0): ?>
                <label class="bmca-version-option">
                  <input type="checkbox" name="version_ids[]" value="<?= $versionId ?>">
                  <span class="bmca-version-option__check" aria-hidden="true">✓</span>
                  <span>
                    <strong><?= h((string)($version['book_key'] ?? $version['manual_code'] ?? 'Manual')) ?> · <?= h((string)($version['title'] ?? $version['book_title'] ?? '')) ?></strong>
                    <small>Revision <?= h((string)($version['version_label'] ?? $version['revision'] ?? '—')) ?> · <?= h(ucwords(str_replace('_', ' ', (string)($version['lifecycle_status'] ?? $version['status'] ?? '')))) ?></small>
                  </span>
                </label>
              <?php endif; ?>
            <?php endforeach; ?>
          </span>
          <small>Select one or more exact revisions. Approved versions can be analysed read-only; applying changes later requires a Draft.</small>
        </div>
        <div class="bmca-form-error" data-bmca-form-error hidden></div>
        <div class="bmca-actions">
          <button class="app-btn app-btn--primary" type="submit" data-bmca-submit>Create project</button>
          <button class="app-btn app-btn--secondary" type="button" data-compliance-modal-close>Cancel</button>
        </div>
      </form>
    <?php compliance_modal_close(); ?>
  <?php endif; ?>
</main>

<script src="/assets/books-manual-change-assistant.js?v=<?= (int)(@filemtime(__DIR__ . '/../../assets/books-manual-change-assistant.js') ?: time()) ?>"></script>
<?php
compliance_page_close();
cw_footer();
