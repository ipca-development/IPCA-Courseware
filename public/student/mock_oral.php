<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/courseware_progression_v2.php';
require_once __DIR__ . '/../../src/mock_oral/mock_oral_bootstrap.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'student' && $role !== 'admin') {
    redirect(cw_home_path_for_role($role));
}

$userId = (int)cw_student_view_user_id($pdo, $u);
$cohortId = (int)($_GET['cohort_id'] ?? 0);

if ($cohortId <= 0) {
    $st = $pdo->prepare("SELECT cohort_id FROM cohort_students WHERE user_id = ? AND status = 'active' ORDER BY enrolled_at DESC LIMIT 1");
    $st->execute([$userId]);
    $cohortId = (int)$st->fetchColumn();
}

$engine = new CoursewareProgressionV2($pdo);
$catalogId = mo_default_catalog_id($pdo);
$areas = $catalogId > 0 ? $engine->listMockOralAreas($catalogId) : [];
$theoryComplete = $cohortId > 0 ? $engine->isTheoryCompleteForMockOral($userId, $cohortId) : false;
$mockOralEnabled = $cohortId > 0 ? $engine->hasMockOralPermission($userId, $cohortId, $catalogId) : false;

$cohortName = 'Cohort';
if ($cohortId > 0) {
    $cst = $pdo->prepare('SELECT name FROM cohorts WHERE id = ? LIMIT 1');
    $cst->execute([$cohortId]);
    $cohortName = trim((string)$cst->fetchColumn()) ?: $cohortName;
}

function mo_h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

cw_header('Mock Oral Exam Preparation');
?>
<link rel="stylesheet" href="/assets/mock_oral_session.css?v=3">

<div class="moe-page">
  <section class="app-section-hero">
    <div class="hero-overline">Theory Training · Checkride Preparation</div>
    <h1 class="moe-hero-title">FAA Mock Oral Exam Preparation</h1>
    <p class="moe-hero-sub">ACS-driven modules with Maya as your oral examiner. Sessions focus on your weak areas from progress tests, FAA knowledge test reports, and prior mock orals.</p>
  </section>

  <?php if ($cohortId <= 0): ?>
    <section class="card moe-card"><div class="moe-gate">You are not enrolled in an active cohort.</div></section>
  <?php elseif (!$theoryComplete): ?>
    <section class="card moe-card"><div class="moe-gate">Complete all theory lessons and progress tests before mock oral preparation unlocks.</div></section>
  <?php elseif (!$mockOralEnabled): ?>
    <section class="card moe-card"><div class="moe-gate">Mock Oral Exam access requires Head of Training approval. Contact your training manager.</div></section>
  <?php else: ?>
    <div id="moRemoteRequestToast" class="mo-remote-toast" role="status" aria-live="polite"></div>
    <section class="card moe-card">
      <div class="moe-meta">Cohort: <strong><?= mo_h($cohortName) ?></strong></div>
      <div class="modules-stack" id="mockOralModules">
        <?php foreach ($areas as $area): ?>
          <?php
            $state = $engine->getMockOralModuleButtonState($userId, $cohortId, (int)$area['id']);
            $mode = (string)($state['mode'] ?? 'blocked');
            $prep = (array)($state['prep'] ?? []);
            $autoOpenCodeModal = ($mode === 'remote_code_entry' || !empty($state['show_code_modal']))
              && empty($prep['show_bar'])
              && !in_array($mode, ['remote_preparing', 'remote_start', 'continue'], true)
              && !empty($_GET['mo_remote_auth'])
              && (empty($_GET['area_id']) || (int)$_GET['area_id'] === (int)$area['id']);
          ?>
          <article class="module-header-card" data-area-id="<?= (int)$area['id'] ?>">
            <div class="module-header-main">
              <div class="module-kicker">ACS Area <?= mo_h((string)$area['area_code']) ?></div>
              <h2 class="module-title"><?= mo_h((string)$area['title']) ?></h2>
            </div>
            <div class="module-header-actions">
              <?php if (!empty($state['disabled']) && empty($state['show_bar'])): ?>
                <span class="app-btn app-btn-secondary moe-btn-disabled" title="<?= mo_h((string)($state['message'] ?? '')) ?>"><?= mo_h((string)$state['label']) ?></span>
              <?php elseif (!empty($state['show_bar'])): ?>
                <div class="moe-prep-status" data-session-id="<?= (int)($state['session_id'] ?? $prep['session_id'] ?? 0) ?>">
                  <div class="moe-prep-head"><?= mo_h((string)($prep['sub'] ?? 'Preparing Mock Oral')) ?></div>
                  <div class="moe-prep-bar-shell">
                    <div class="moe-prep-bar-fill <?= mo_h((string)($prep['class'] ?? 'info')) ?>" style="width:<?= (int)($prep['pct'] ?? 8) ?>%;"></div>
                  </div>
                  <div class="moe-prep-label <?= mo_h((string)($prep['class'] ?? 'info')) ?>"><?= mo_h((string)($prep['label'] ?? 'Preparing your Mock Oral Exam Session…')) ?></div>
                </div>
              <?php elseif ($mode === 'remote_request'): ?>
                <button type="button" class="app-btn app-btn-secondary moe-remote-request-btn" data-area-id="<?= (int)$area['id'] ?>"><?= mo_h((string)$state['label']) ?></button>
              <?php elseif ($mode === 'remote_code_entry'): ?>
                <button type="button" class="app-btn app-btn-primary moe-code-btn" data-area-id="<?= (int)$area['id'] ?>" data-auto-open-remote-code="<?= $autoOpenCodeModal ? '1' : '0' ?>"><?= mo_h((string)$state['label']) ?></button>
              <?php elseif (!empty($state['href'])): ?>
                <a class="app-btn app-btn-primary" href="<?= mo_h((string)$state['href']) ?>"><?= mo_h((string)$state['label']) ?></a>
              <?php else: ?>
                <button type="button" class="app-btn app-btn-primary" disabled><?= mo_h((string)$state['label']) ?></button>
              <?php endif; ?>
            </div>
            <?php if (!empty($state['message'])): ?>
              <div class="moe-module-note"><?= mo_h((string)$state['message']) ?></div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div>

<div id="mockOralCodeModal" class="moe-modal-overlay" aria-hidden="true">
  <div class="moe-modal">
    <h3>Enter Mock Oral Code</h3>
    <p>Enter the six-digit code from the authentication page. After verification, your personalized mock oral exam is prepared on <strong>this page</strong>. You start the interactive session only once preparation finishes.</p>
    <input class="app-input" id="mockOralCodeInput" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="000000">
    <input type="hidden" id="mockOralCodeAreaId" value="">
    <div class="moe-modal-actions">
      <button type="button" class="app-btn app-btn-secondary" id="mockOralCodeCancel">Cancel</button>
      <button type="button" class="app-btn app-btn-primary" id="mockOralCodeSubmit">Verify &amp; prepare</button>
    </div>
    <div class="moe-error" id="mockOralCodeError" style="display:none;"></div>
  </div>
</div>

<script>
window.MOCK_ORAL_HUB = {
  cohortId: <?= (int)$cohortId ?>,
  apiBase: '/student/api'
};
</script>
<script src="/assets/mock_oral_hub.js?v=3"></script>

<?php cw_footer(); ?>
