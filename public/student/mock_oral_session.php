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
    <link rel="stylesheet" href="/assets/mock_oral_session.css?v=6">
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

if ((string)$session['status'] === 'blueprint_generating') {
    redirect('/student/mock_oral.php?cohort_id=' . $cohortId . '&area_id=' . $areaId);
}

if ((string)$session['status'] !== 'ready' && (string)$session['status'] !== 'in_progress' && (string)$session['status'] !== 'turn_evaluating') {
    cw_header('Mock Oral Session');
    echo '<section class="card moe-card"><div class="moe-gate">This session is not available to start yet.</div></section>';
    cw_footer();
    exit;
}

$blueprint = mo_json_decode($session['blueprint_json'] ?? null);

function mo_sh(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

cw_header('Mock Oral Exam');
?>
<link rel="stylesheet" href="/assets/mock_oral_session.css?v=6">

<div class="moe-page moe-live-page" id="moeLivePage">
  <section class="moe-live-hero">
    <div class="hero-overline">Mock Oral Exam · ACS Area <?= mo_sh((string)($area['area_code'] ?? '')) ?></div>
    <h1 class="moe-hero-title"><?= mo_sh((string)($area['title'] ?? 'Oral Session')) ?></h1>
    <p class="moe-scenario-line"><?= mo_sh((string)($blueprint['cross_country_context'] ?? 'Scenario-driven DPE-style oral exam with Maya.')) ?></p>
  </section>

  <div id="moeVoiceBanner" class="moe-voice-banner" hidden></div>

  <div class="moe-conversation-shell">
    <div class="moe-stage-panel">
      <div class="moe-maya-stage">
        <video id="moeHeygenVideo" class="moe-heygen-video" playsinline autoplay hidden></video>
        <div class="moe-maya-avatar-fallback" id="moeMayaAvatar">M</div>
        <div class="moe-maya-status" id="moeMayaStatus">Connecting…</div>
      </div>
      <div class="moe-timer" id="moeTimer">Time remaining: 05:00</div>
      <div class="moe-student-status" id="moeStudentStatus">Preparing your oral exam conversation…</div>
    </div>

    <div class="moe-transcript-panel">
      <div class="moe-transcript-head">Conversation</div>
      <div class="moe-transcript" id="moeTranscript"></div>
    </div>
  </div>

  <div class="moe-live-controls">
    <button type="button" class="app-btn app-btn-primary moe-answer-btn" id="moeAnswerBtn" disabled>Tap to Answer</button>
    <button type="button" class="app-btn app-btn-secondary" id="moeEndBtn" disabled>End Oral Exam</button>
  </div>

  <details class="moe-typed-fallback">
    <summary>Type an answer instead</summary>
    <textarea class="app-textarea" id="moeTypedAnswer" rows="3" placeholder="Type your answer here…"></textarea>
    <button type="button" class="app-btn app-btn-secondary" id="moeSubmitTyped" disabled>Submit Typed Answer</button>
  </details>
</div>

<script>
window.MOCK_ORAL_SESSION = {
  cohortId: <?= (int)$cohortId ?>,
  areaId: <?= (int)$areaId ?>,
  sessionId: <?= (int)$sessionId ?>,
  maxDurationSec: <?= (int)($session['max_duration_sec'] ?? 300) ?>,
  status: <?= json_encode((string)$session['status'], JSON_UNESCAPED_UNICODE) ?>,
  scenario: <?= json_encode((string)($blueprint['opening_scenario'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
  apiBase: '/student/api'
};
</script>
<?php
$liveAvatarBundlePath = dirname(__DIR__) . '/assets/vendor/liveavatar-web-sdk.bundle.js';
$legacyBundlePath = dirname(__DIR__) . '/assets/vendor/heygen-streaming-avatar.bundle.js';
if (is_readable($liveAvatarBundlePath)): ?>
<script src="/assets/vendor/liveavatar-web-sdk.bundle.js?v=2"></script>
<?php elseif (is_readable($legacyBundlePath)): ?>
<script src="/assets/vendor/heygen-streaming-avatar.bundle.js?v=2"></script>
<?php endif; ?>
<script src="/assets/mock_oral_heygen_presenter.js?v=3"></script>
<script src="/assets/mock_oral_session.js?v=7"></script>

<?php cw_footer(); ?>
