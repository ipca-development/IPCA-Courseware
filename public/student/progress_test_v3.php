<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'student' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$cohortId = (int)($_GET['cohort_id'] ?? 0);
$lessonId = (int)($_GET['lesson_id'] ?? 0);
if ($cohortId <= 0 || $lessonId <= 0) {
    exit('Missing cohort_id or lesson_id');
}

$studentContextId = cw_student_view_user_id($pdo, $u);

if ($role === 'student') {
    $check = $pdo->prepare("
        SELECT 1
        FROM cohort_students
        WHERE cohort_id = ?
          AND user_id = ?
          AND status = 'active'
        LIMIT 1
    ");
    $check->execute([$cohortId, $studentContextId]);
    if (!$check->fetchColumn()) {
        http_response_code(403);
        exit('Not enrolled in this cohort');
    }
}

$nameSt = $pdo->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
$nameSt->execute([$studentContextId]);
$userName = trim((string)$nameSt->fetchColumn());
if ($userName === '') {
    $userName = trim((string)($u['name'] ?? 'Student'));
}
$firstName = trim(explode(' ', $userName)[0] ?? 'Student');
if ($firstName === '') {
    $firstName = 'Student';
}

cw_header('Progress Test V3');
?>
<link rel="stylesheet" href="/assets/progress_test_v3.css?v=2">

<div class="ptv3-page">
  <section
    class="ptv3-shell maya-coach"
    data-coach-mode="expanded"
    data-coach-layout="chat"
    data-coach-state="thinking"
    data-ptv3-root
  >
    <header class="maya-coach-header ptv3-header">
      <div class="maya-avatar-wrap">
        <img class="maya-avatar" src="/assets/avatars/maya.png" alt="Maya">
        <span class="maya-status-dot" aria-hidden="true"></span>
      </div>
      <div class="maya-coach-header-text">
        <div class="maya-title">Progress Test V3</div>
        <div class="maya-subtitle" data-ptv3-status>Loading generated questions...</div>
      </div>
      <div class="ptv3-mode-pill">Realtime oral mode</div>
    </header>

    <div class="maya-stage" data-ptv3-stage>Preparing questions</div>

    <section class="ptv3-video-card" aria-label="Student recording preview">
      <div class="ptv3-video-frame">
        <video data-ptv3-video autoplay playsinline muted></video>
        <div class="ptv3-video-fallback" data-ptv3-video-fallback>Camera preview</div>
      </div>
      <div class="ptv3-video-copy">
        <div class="ptv3-video-title"><?= h($firstName) ?></div>
        <div class="ptv3-recording-pill" data-ptv3-recording>Not recording yet</div>
      </div>
    </section>

    <section class="ptv3-progress-card" aria-label="Progress test progress">
      <div class="ptv3-progress-top">
        <div>
          <div class="ptv3-progress-title" data-ptv3-title>Progress test title</div>
          <div class="ptv3-progress-subtitle" data-ptv3-attempt>Attempt status: loading</div>
        </div>
        <div class="ptv3-score-chip" data-ptv3-score>Score: --</div>
      </div>
      <div class="ptv3-bar" aria-hidden="true">
        <div class="ptv3-bar-fill" data-ptv3-bar></div>
      </div>
      <div class="ptv3-progress-grid">
        <div><strong data-ptv3-ready>0</strong><span>Questions ready</span></div>
        <div><strong data-ptv3-current>0/0</strong><span>Current question</span></div>
        <div><strong data-ptv3-evaluated>0</strong><span>Answered/evaluated</span></div>
        <div><strong data-ptv3-final>--</strong><span>Final score progress</span></div>
      </div>
    </section>

    <section class="maya-chat-area ptv3-chat" aria-label="Progress test transcript">
      <div class="maya-chat-thread" data-ptv3-thread>
        <div class="maya-chat-thread-empty" data-ptv3-empty>
          Questions are being generated. Microphone access will start only after you click Start Progress Test.
        </div>
      </div>
      <div class="maya-chat-typing" data-ptv3-live-row data-visible="0">
        <span>Listening</span>
        <span class="maya-chat-typing-dots"><span></span><span></span><span></span></span>
      </div>
    </section>

    <section class="ptv3-controls" aria-label="Progress test controls">
      <button class="ptv3-btn primary" type="button" data-ptv3-start disabled>Start Progress Test</button>
      <button class="ptv3-btn" type="button" data-ptv3-mute disabled>Mute Microphone</button>
      <button class="ptv3-btn danger" type="button" data-ptv3-end disabled>End Test</button>
    </section>
  </section>
</div>

<script>
window.IPCAProgressTestV3Config = {
  cohortId: <?= (int)$cohortId ?>,
  lessonId: <?= (int)$lessonId ?>,
  firstName: <?= json_encode($firstName, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="/assets/progress_test_v3.js?v=2"></script>
<?php cw_footer(); ?>
