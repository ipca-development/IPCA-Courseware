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
    <link rel="stylesheet" href="/assets/mock_oral_session.css?v=8">
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
<link rel="stylesheet" href="/assets/mock_oral_session.css?v=8">

<div class="moe-page moe-live-page" id="moeLivePage">
  <section class="moe-live-hero">
    <div class="hero-overline">Mock Oral Exam · ACS Area <?= mo_sh((string)($area['area_code'] ?? '')) ?></div>
    <h1 class="moe-hero-title"><?= mo_sh((string)($area['title'] ?? 'Oral Session')) ?></h1>
    <p class="moe-scenario-line"><?= mo_sh((string)($blueprint['cross_country_context'] ?? 'Scenario-driven DPE-style oral exam with Maya.')) ?></p>
  </section>

  <div id="moeVoiceBanner" class="moe-voice-banner" hidden></div>

  <div class="moe-live-stage" id="moeLiveStage">
    <div class="moe-video-shell" id="moeVideoShell">
      <video id="moeHeygenVideo" class="moe-heygen-video" playsinline autoplay hidden></video>
      <div class="moe-maya-avatar-fallback" id="moeMayaAvatar">M</div>
      <div class="moe-user-pip" id="moeUserPip" hidden>
        <video id="moeUserVideo" class="moe-user-video" playsinline autoplay muted></video>
      </div>
      <button type="button" class="moe-mic-btn is-muted" id="moeMicBtn" hidden title="Toggle microphone" aria-label="Toggle microphone">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 14a3 3 0 0 0 3-3V6a3 3 0 1 0-6 0v5a3 3 0 0 0 3 3zm5-3a1 1 0 1 0-2 0 5 5 0 0 1-10 0 1 1 0 1 0-2 0 7 7 0 0 0 6 6.92V19H9a1 1 0 1 0 0 2h6a1 1 0 1 0 0-2h-2v-1.08A7 7 0 0 0 17 11z"/></svg>
      </button>
    </div>

    <div class="moe-maya-status" id="moeMayaStatus">Preparing…</div>

    <div class="moe-current-question" id="moeCurrentQuestion" hidden>
      <div class="moe-current-question-label">Maya asks</div>
      <div class="moe-current-question-body" id="moeCurrentQuestionBody"></div>
    </div>

    <div class="moe-timer-block">
      <div class="moe-timer-bar-shell">
        <div class="moe-timer-bar-fill" id="moeTimerFill"></div>
      </div>
      <div class="moe-timer-label" id="moeTimerLabel">05:00 remaining</div>
    </div>

    <div class="moe-student-status" id="moeStudentStatus">Loading exam resources…</div>

    <div class="moe-prep-overlay" id="moePrepOverlay">
      <div class="moe-prep-card">
        <h2>Preparing your oral exam</h2>
        <p class="moe-prep-lead">Maya will begin when everything is ready. Press Start when the checklist is complete.</p>
        <ul class="moe-prep-checklist" id="moePrepChecklist">
          <li data-prep="session"><span class="moe-prep-icon">○</span> Session authorization</li>
          <li data-prep="camera"><span class="moe-prep-icon">○</span> Camera access</li>
          <li data-prep="mic"><span class="moe-prep-icon">○</span> Microphone access</li>
          <li data-prep="avatar"><span class="moe-prep-icon">○</span> Maya live avatar (connects on start)</li>
        </ul>
        <button type="button" class="app-btn app-btn-primary moe-start-btn" id="moeStartBtn" disabled>Start Oral Exam</button>
      </div>
    </div>
  </div>

  <details class="moe-transcript-fold">
    <summary>Full conversation history</summary>
    <div class="moe-transcript" id="moeTranscript"></div>
  </details>

  <div class="moe-live-controls">
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
<script src="/assets/mock_oral_heygen_presenter.js?v=5"></script>
<script src="/assets/mock_oral_session.js?v=10"></script>

<?php cw_footer(); ?>
