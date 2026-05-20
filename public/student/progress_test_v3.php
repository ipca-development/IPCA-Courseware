<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/progress_test_access.php';

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

$gateError = '';
$accessState = cw_progress_test_access_state($pdo, $studentContextId, $cohortId);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['progress_test_pin'])) {
    if (cw_progress_test_verify_submitted_pin($pdo, $studentContextId, $cohortId, (string)$_POST['progress_test_pin'])) {
        header('Location: ' . strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '#'));
        exit;
    }
    $gateError = 'Invalid access code.';
    $accessState = cw_progress_test_access_state($pdo, $studentContextId, $cohortId);
}

if (empty($accessState['allowed'])) {
    $mode = (string)($accessState['mode'] ?? 'pin');
    cw_header('Progress Test Access');
    ?>
    <style>
      body{ background:#fff; }
      .gate-wrap{ max-width:980px; margin:0 auto; padding:18px 12px; }
      .gate-card{ max-width:560px; margin:38px auto; padding:24px; border:1px solid #e8e8e8; border-radius:18px; background:#fff; box-shadow:0 10px 30px rgba(0,0,0,0.06); }
      .gate-title{ font-size:24px; font-weight:900; color:#1e3c72; margin-bottom:8px; }
      .gate-text{ color:#334155; line-height:1.5; }
      .gate-input{ width:100%; margin-top:14px; padding:14px 16px; border:1px solid #d6d6d6; border-radius:12px; font-size:16px; box-sizing:border-box; }
      .gate-btn{ width:100%; margin-top:14px; padding:16px 14px; border-radius:16px; border:2px solid rgba(30,60,114,0.25); background:rgba(30,60,114,0.08); color:#1e3c72; font-weight:900; font-size:18px; cursor:pointer; }
      .gate-error{ margin-top:12px; color:#b91c1c; font-weight:800; }
    </style>
    <div class="gate-wrap">
      <div class="gate-card">
        <div class="gate-title">Progress Test Access Required</div>
        <?php if ($mode === 'school_ip'): ?>
          <div class="gate-text">This progress test normally requires the approved school network.<br>Since you are not on the approved IP, enter the access code to continue.</div>
        <?php else: ?>
          <div class="gate-text">Enter the progress test access code to continue.</div>
        <?php endif; ?>
        <form method="post">
          <input class="gate-input" type="password" name="progress_test_pin" placeholder="Access code" autofocus>
          <button class="gate-btn" type="submit">Continue</button>
        </form>
        <?php if ($gateError !== ''): ?><div class="gate-error"><?= h($gateError) ?></div><?php endif; ?>
      </div>
    </div>
    <?php
    cw_footer();
    exit;
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

cw_header('Progress Test');
?>
<link rel="stylesheet" href="/assets/progress_test_v3.css?v=18">

<div class="ptv3-page">
  <section
    class="ptv3-shell maya-coach"
    data-coach-mode="expanded"
    data-coach-layout="chat"
    data-coach-state="thinking"
    data-ptv3-root
  >
    <header class="maya-coach-header ptv3-header">
      <div class="maya-coach-header-text">
        <div class="maya-title">Progress Test</div>
        <div class="maya-subtitle" data-ptv3-status>Loading generated questions...</div>
      </div>
    </header>

    <section class="ptv3-avatars-card" aria-label="Maya and student avatars">
      <div class="ptv3-avatar-slot">
        <div class="ptv3-avatar-frame" data-ptv3-maya-frame>
          <img class="ptv3-avatar-img" src="/assets/avatars/maya.png" alt="Maya">
        </div>
        <div class="ptv3-avatar-label">Maya</div>
      </div>
      <div class="ptv3-avatar-slot">
        <div class="ptv3-avatar-frame" data-ptv3-student-frame>
          <video data-ptv3-video autoplay playsinline muted></video>
          <div class="ptv3-video-fallback" data-ptv3-video-fallback>Camera preview</div>
        </div>
        <div class="ptv3-avatar-label"><?= h($firstName) ?></div>
        <div class="ptv3-recording-pill" data-ptv3-recording>Not recording yet</div>
      </div>
    </section>

    <section class="ptv3-answer-timer" data-ptv3-answer-timer hidden aria-label="Answer start timer">
      <div class="ptv3-timer-pill">
        <div class="ptv3-timer-fill" data-ptv3-timer-fill></div>
      </div>
      <div class="ptv3-timer-status" data-ptv3-timer-status></div>
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
      <button class="ptv3-btn success" type="button" data-ptv3-finish disabled>START</button>
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
<script src="/assets/progress_test_v3.js?v=18"></script>
<?php cw_footer(); ?>
