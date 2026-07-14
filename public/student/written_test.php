<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/written_test/bootstrap.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'student' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$studentId = cw_student_view_user_id($pdo, $u);
if ($role === 'student' && $studentId !== (int)($u['id'] ?? 0)) {
    http_response_code(403);
    exit('Forbidden');
}

$adminStudentPreviewQS = ($role === 'admin' && cw_users_id_is_student($pdo, $studentId))
    ? '&user_id=' . (int)$studentId
    : '';
$adminStudentPreviewLeadingQS = ($role === 'admin' && cw_users_id_is_student($pdo, $studentId))
    ? '?user_id=' . (int)$studentId
    : '';

$allocationSvc = new WrittenTestAllocationService($pdo);
$accessSvc = new WrittenTestAccessService($pdo);
$allocations = $allocationSvc->allocationsForStudent($studentId);

$selectedAllocationId = (int)($_GET['allocation_id'] ?? 0);
if ($selectedAllocationId <= 0 && $allocations) {
    $selectedAllocationId = (int)$allocations[0]['id'];
}

$state = null;
if ($selectedAllocationId > 0) {
    $state = $accessSvc->evaluate($studentId, $selectedAllocationId);
}

cw_header('Written Test Preparation');
?>
<style>
  .wt-stack{display:flex;flex-direction:column;gap:18px}
  .wt-hero{padding:24px 26px;background:linear-gradient(135deg,#071d33 0%,#123b68 58%,#1e5b93 100%);color:#fff;border-radius:22px;box-shadow:0 18px 40px rgba(15,23,42,.16)}
  .wt-eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.16em;font-weight:800;opacity:.78;margin-bottom:10px}
  .wt-title{margin:0;font-size:34px;line-height:1;letter-spacing:-.04em}
  .wt-sub{margin-top:12px;max-width:880px;color:rgba(255,255,255,.82);line-height:1.55}
  .wt-tabs{display:flex;flex-wrap:wrap;gap:10px}
  .wt-tab{display:inline-flex;align-items:center;gap:8px;padding:10px 13px;border-radius:999px;text-decoration:none;background:#fff;color:#17324f;border:1px solid rgba(15,23,42,.08);font-size:13px;font-weight:800}
  .wt-tab.active{background:#12355f;color:#fff;border-color:#12355f}
  .wt-grid{display:grid;grid-template-columns:1.15fr .85fr;gap:16px}
  .wt-card{padding:20px 22px}
  .wt-card h2{margin:0;font-size:22px;color:#152235;letter-spacing:-.02em}
  .wt-muted{margin-top:7px;color:#5b6d85;font-size:14px;line-height:1.5}
  .wt-status{display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;font-weight:900;font-size:12px;text-transform:uppercase;letter-spacing:.08em}
  .wt-status.locked,.wt-status.policy_required,.wt-status.manual_denial,.wt-status.not_allocated{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}
  .wt-status.unlocked{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}
  .wt-status.other{background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe}
  .wt-action{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 14px;border-radius:12px;text-decoration:none;font-size:13px;font-weight:800;color:#152235;background:#f4f7fb;border:1px solid rgba(15,23,42,0.08);width:max-content}
  .wt-reqs{display:flex;flex-direction:column;gap:10px;margin-top:14px}
  .wt-req{display:flex;align-items:flex-start;gap:12px;padding:13px 14px;border-radius:15px;background:#f8fbfd;border:1px solid rgba(15,23,42,.06)}
  .wt-mark{width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:900;flex:0 0 auto}
  .wt-mark.ok{background:#dcfce7;color:#166534}
  .wt-mark.no{background:#fee2e2;color:#991b1b}
  .wt-req-title{font-weight:800;color:#152235;font-size:14px}
  .wt-req-detail{margin-top:4px;color:#5b6d85;font-size:13px;line-height:1.45}
  .wt-modes{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
  .wt-mode{padding:16px;border-radius:16px;background:#fff;border:1px solid rgba(15,23,42,.07)}
  .wt-mode-title{font-weight:900;color:#152235}
  .wt-mode-copy{margin-top:7px;color:#5b6d85;font-size:13px;line-height:1.45}
  .wt-empty{padding:22px;border-radius:18px;background:#fff;border:1px dashed rgba(15,23,42,.18);color:#5b6d85}
  @media (max-width:900px){.wt-grid,.wt-modes{grid-template-columns:1fr}}
</style>

<div class="wt-stack">
  <section class="wt-hero">
    <div class="wt-eyebrow">Ground School Training</div>
    <h1 class="wt-title">Written Test Preparation</h1>
    <div class="wt-sub">
      This section unlocks only after the assigned cohort allocation, published policy snapshot, and Ground School requirements allow it.
      Phase 1 establishes the allocation and access foundation; question delivery and mock exam modes are intentionally not live yet.
    </div>
  </section>

  <?php if (!$allocations): ?>
    <div class="wt-empty">
      Written Test Preparation has not been allocated to one of your cohorts yet.
      Continue with your Ground School course and check back after your instructor assigns a preparation program.
    </div>
  <?php else: ?>
    <nav class="wt-tabs" aria-label="Written Test Preparation allocations">
      <?php foreach ($allocations as $allocation): ?>
        <?php
          $href = '/student/written_test.php?allocation_id=' . (int)$allocation['id'] . $adminStudentPreviewQS;
          $active = (int)$allocation['id'] === $selectedAllocationId;
        ?>
        <a class="wt-tab<?= $active ? ' active' : '' ?>" href="<?= h($href) ?>">
          <?= h((string)$allocation['written_test_program_name']) ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <?php if ($state): ?>
      <?php
        $stateCode = (string)$state['state_code'];
        $stateClass = in_array($stateCode, ['unlocked','locked','policy_required','manual_denial','not_allocated'], true) ? $stateCode : 'other';
        $allocation = $state['allocation'] ?? [];
        $requirements = is_array($state['requirements'] ?? null) ? $state['requirements'] : [];
        $lockReasons = is_array($state['lock_reasons'] ?? null) ? $state['lock_reasons'] : [];
      ?>
      <div class="wt-grid">
        <section class="card wt-card">
          <span class="wt-status <?= h($stateClass) ?>"><?= h((string)$state['state_label']) ?></span>
          <h2 style="margin-top:14px;"><?= h((string)($allocation['written_test_program_name'] ?? 'Written Test Preparation')) ?></h2>
          <div class="wt-muted">
            Cohort: <strong><?= h((string)($allocation['cohort_name'] ?? '')) ?></strong>
            <?php if (!empty($allocation['related_course_title'])): ?>
              <br>Scoped to: <strong><?= h((string)$allocation['related_course_title']) ?></strong>
            <?php endif; ?>
            <?php if (!empty($state['policy_version']['version_number'])): ?>
              <br>Policy snapshot: <strong>Version <?= (int)$state['policy_version']['version_number'] ?></strong>
            <?php endif; ?>
          </div>

          <?php if ($state['access_granted']): ?>
            <div class="wt-empty" style="margin-top:16px;border-style:solid;background:#f0fdf4;color:#166534;">
              You have met the Phase 1 access requirements. Question Mastery, Practice Mock Exams, and Supervised Mock Exam delivery will be connected in later phases.
            </div>
          <?php elseif ($lockReasons): ?>
            <div class="wt-reqs">
              <?php foreach ($lockReasons as $reason): ?>
                <div class="wt-req">
                  <div class="wt-mark no">!</div>
                  <div>
                    <div class="wt-req-title"><?= h((string)$reason['key']) ?></div>
                    <div class="wt-req-detail"><?= h((string)$reason['message']) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <section class="card wt-card">
          <h2>Qualification Path</h2>
          <div class="wt-muted">Each requirement is evaluated from the published policy snapshot for this allocation.</div>
          <div class="wt-reqs">
            <?php foreach ($requirements as $req): ?>
              <div class="wt-req">
                <div class="wt-mark <?= !empty($req['met']) ? 'ok' : 'no' ?>"><?= !empty($req['met']) ? 'OK' : '!' ?></div>
                <div>
                  <div class="wt-req-title"><?= h((string)$req['label']) ?></div>
                  <div class="wt-req-detail"><?= h((string)$req['detail']) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      </div>

      <section class="card wt-card">
        <h2>Preparation Modes</h2>
        <div class="wt-muted">Mode delivery is deferred until Phase 2 and later. These cards reserve the student workflow without launching unsupported attempts.</div>
        <div class="wt-modes" style="margin-top:14px;">
          <div class="wt-mode">
            <div class="wt-mode-title">Question Mastery</div>
            <div class="wt-mode-copy">Topic-focused practice and confidence tracking will appear here once the question bank foundation is added.</div>
          </div>
          <div class="wt-mode">
            <div class="wt-mode-title">Practice Mock Exams</div>
            <div class="wt-mode-copy">Blueprint-based timed mock exams will use frozen versions and delayed feedback in a later phase.</div>
          </div>
          <div class="wt-mode">
            <div class="wt-mode-title">Supervised Mock Exam</div>
            <div class="wt-mode-copy">Supervisor authorization, launch windows, and integrity closeout will reuse Progress Test V4 patterns later.</div>
          </div>
        </div>
      </section>
    <?php endif; ?>
  <?php endif; ?>

  <a class="wt-action" href="/student/dashboard.php<?= h($adminStudentPreviewLeadingQS) ?>">Back to Dashboard</a>
</div>

<?php cw_footer(); ?>
