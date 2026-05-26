<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/courseware_progression_v2.php';
require_once __DIR__ . '/../../src/mock_oral/mock_oral_bootstrap.php';
require_once __DIR__ . '/../../src/mock_oral/MockOralSessionService.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'student' && $role !== 'admin') {
    redirect(cw_home_path_for_role($role));
}

$userId = (int)cw_student_view_user_id($pdo, $u);
$cohortId = (int)($_GET['cohort_id'] ?? 0);
$areaId = (int)($_GET['area_id'] ?? 0);
$sessionId = (int)($_GET['session_id'] ?? 0);
$view = trim((string)($_GET['view'] ?? 'session'));

$sessionSvc = new MockOralSessionService($pdo);
$session = $sessionId > 0 ? $sessionSvc->loadSessionForUser($sessionId, $userId) : null;
$area = $areaId > 0 ? mo_area_by_id($pdo, $areaId) : null;

if ($view === 'debrief' && $session) {
    $debriefPayload = $sessionSvc->getDebriefPayload($sessionId);
    cw_header('Mock Oral Debrief');
    ?>
    <link rel="stylesheet" href="/assets/mock_oral_session.css?v=1">
    <div class="moe-page">
      <section class="moe-debrief-hero">
        <div class="hero-overline">Mock Oral Debrief</div>
        <h1><?= htmlspecialchars((string)($area['title'] ?? 'Session Debrief'), ENT_QUOTES) ?></h1>
        <?php if ($session['score_pct'] !== null): ?>
          <p>Session score: <strong><?= htmlspecialchars((string)$session['score_pct'], ENT_QUOTES) ?>%</strong></p>
        <?php endif; ?>
      </section>
      <section class="card moe-card">
        <div><?= (string)($debriefPayload['debrief']['written_debrief_html'] ?? '') ?></div>
      </section>
      <div style="margin-top:16px;">
        <a class="app-btn app-btn-primary" href="/student/mock_oral.php?cohort_id=<?= (int)$cohortId ?>">Back to Modules</a>
      </div>
    </div>
    <?php
    cw_footer();
    exit;
}

if (!$session || (int)$session['cohort_id'] !== $cohortId) {
    cw_header('Mock Oral Session');
    echo '<section class="card moe-card"><div class="moe-gate">Session not found or access denied.</div></section>';
    cw_footer();
    exit;
}

if ((string)$session['status'] === 'completed') {
    redirect('/student/mock_oral_session.php?cohort_id=' . $cohortId . '&area_id=' . $areaId . '&session_id=' . $sessionId . '&view=debrief');
}

$blueprint = mo_json_decode((string)($session['blueprint_json'] ?? ''));

function mo_sh(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

cw_header('Mock Oral Session');
?>
<link rel="stylesheet" href="/assets/mock_oral_session.css?v=1">

<div class="moe-page">
  <section class="app-section-hero">
    <div class="hero-overline">Mock Oral Exam · ACS Area <?= mo_sh((string)($area['area_code'] ?? '')) ?></div>
    <h1 class="moe-hero-title"><?= mo_sh((string)($area['title'] ?? 'Oral Session')) ?></h1>
    <p class="moe-hero-sub"><?= mo_sh((string)($blueprint['cross_country_context'] ?? 'Scenario-driven DPE-style oral exam with Maya.')) ?></p>
  </section>

  <div class="moe-session-layout">
    <section class="moe-panel">
      <div class="moe-timer" id="moeTimer">Time remaining: 05:00</div>
      <div class="moe-transcript" id="moeTranscript"></div>
      <div class="moe-controls">
        <button type="button" class="app-btn app-btn-primary" id="moeStartBtn">Start Session</button>
        <button type="button" class="app-btn app-btn-secondary" id="moeListenBtn" disabled>Hold to Answer</button>
        <button type="button" class="app-btn app-btn-secondary" id="moeEndBtn" disabled>End Session</button>
      </div>
      <textarea class="app-textarea" id="moeTypedAnswer" rows="3" placeholder="Or type your answer here..." style="margin-top:12px;width:100%;"></textarea>
      <button type="button" class="app-btn app-btn-primary" id="moeSubmitTyped" style="margin-top:10px;" disabled>Submit Answer</button>
    </section>
    <aside class="moe-panel">
      <h3 style="margin:0 0 10px;font-size:18px;">Focus Areas</h3>
      <ul id="moeFocusList" style="margin:0;padding-left:18px;line-height:1.6;color:var(--text-muted);"></ul>
      <div style="margin-top:18px;font-size:13px;color:var(--text-muted);line-height:1.55;">
        Maya follows a pre-generated session blueprint. Your answers adapt follow-ups toward weak knowledge areas.
      </div>
    </aside>
  </div>
</div>

<script>
window.MOCK_ORAL_SESSION = {
  cohortId: <?= (int)$cohortId ?>,
  areaId: <?= (int)$areaId ?>,
  sessionId: <?= (int)$sessionId ?>,
  maxDurationSec: <?= (int)($session['max_duration_sec'] ?? 300) ?>,
  status: <?= json_encode((string)$session['status'], JSON_UNESCAPED_UNICODE) ?>,
  focusAreas: <?= json_encode(array_slice((array)($blueprint['weakness_priorities'] ?? []), 0, 5), JSON_UNESCAPED_UNICODE) ?>,
  apiBase: '/student/api'
};
</script>
<script src="/assets/mock_oral_session.js?v=1"></script>

<?php cw_footer(); ?>
