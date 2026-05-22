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
        SELECT 1 FROM cohort_students
        WHERE cohort_id = ? AND user_id = ? AND status = 'active' LIMIT 1
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

$lessonSt = $pdo->prepare('SELECT title FROM lessons WHERE id = ? LIMIT 1');
$lessonSt->execute([$lessonId]);
$lessonTitle = trim((string)$lessonSt->fetchColumn());
if ($lessonTitle === '') $lessonTitle = 'Lesson';

$nameSt = $pdo->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
$nameSt->execute([$studentContextId]);
$userName = trim((string)$nameSt->fetchColumn());
if ($userName === '') $userName = trim((string)($u['name'] ?? 'Student'));
$firstName = trim(explode(' ', $userName)[0] ?? 'Student');
if ($firstName === '') $firstName = 'Student';

cw_header('Progress Test');
?>
<link rel="stylesheet" href="/assets/progress_test_v4.css?v=1">

<div class="ptv4-page">
  <section class="ptv4-hero" aria-label="Progress test header">
    <div class="ptv4-hero-main">
      <div class="ptv4-hero-eyebrow">Oral Progress Test</div>
      <h1 class="ptv4-hero-title">Progress Test</h1>
      <div class="ptv4-hero-lesson"><?= h($lessonTitle) ?></div>
      <div class="ptv4-hero-status" data-ptv4-status>Preparing your test...</div>
    </div>
    <div class="ptv4-hero-stats">
      <div class="ptv4-stat-chip" data-ptv4-score>Score: --</div>
      <div class="ptv4-stat-chip muted" data-ptv4-attempt>Attempt status: loading</div>
    </div>
    <div class="ptv4-hero-progress" aria-hidden="true">
      <div class="ptv4-bar"><div class="ptv4-bar-fill" data-ptv4-bar></div></div>
      <div class="ptv4-progress-meta">
        <span><strong data-ptv4-current>0/0</strong> question</span>
        <span><strong data-ptv4-evaluated>0</strong> evaluated</span>
        <span>Score progress <strong data-ptv4-final>--</strong></span>
      </div>
    </div>
  </section>

  <section class="ptv4-card" data-ptv4-card data-card-state="ready" aria-label="Current question">
    <div class="ptv4-card-head">
      <div class="ptv4-card-state-pill" data-ptv4-state-pill>Ready</div>
      <div class="ptv4-card-question-num" data-ptv4-qnum>Question</div>
    </div>

    <div class="ptv4-card-body">
      <div class="ptv4-avatars">
        <div class="ptv4-avatar-col">
          <div class="ptv4-avatar-frame" data-ptv4-maya-frame>
            <img src="/assets/avatars/maya.png" alt="Maya">
          </div>
          <div class="ptv4-avatar-name">Maya</div>
        </div>
        <div class="ptv4-avatar-col">
          <div class="ptv4-avatar-frame" data-ptv4-student-frame>
            <video data-ptv4-video autoplay playsinline muted></video>
            <div class="ptv4-video-fallback" data-ptv4-video-fallback>Camera</div>
          </div>
          <div class="ptv4-avatar-name"><?= h($firstName) ?></div>
          <div class="ptv4-rec-pill" data-ptv4-recording>Standby</div>
        </div>
      </div>

      <div class="ptv4-question-text" data-ptv4-question>Loading question...</div>

      <div class="ptv4-transcript-box">
        <div class="ptv4-transcript-label">Your answer transcript</div>
        <div class="ptv4-transcript" data-ptv4-transcript>Your spoken answer will appear here.</div>
      </div>

      <div class="ptv4-concepts">
        <div class="ptv4-concepts-label">Expected concepts</div>
        <div class="ptv4-concept-list" data-ptv4-concepts></div>
      </div>

      <div class="ptv4-feedback" data-ptv4-feedback hidden></div>
    </div>

    <div class="ptv4-timer" data-ptv4-timer data-active="0">
      <div class="ptv4-timer-pill"><div class="ptv4-timer-fill" data-ptv4-timer-fill></div></div>
      <div class="ptv4-timer-label" data-ptv4-timer-label></div>
    </div>
  </section>

  <section class="ptv4-actions" aria-label="Progress test controls">
    <button class="ptv4-btn primary" type="button" data-ptv4-start-test disabled>Start Progress Test</button>
    <button class="ptv4-btn success" type="button" data-ptv4-start-answer disabled>Start Answer</button>
    <button class="ptv4-btn danger" type="button" data-ptv4-stop-answer disabled>Stop Answer</button>
    <button class="ptv4-btn" type="button" data-ptv4-replay disabled>Replay Question</button>
    <button class="ptv4-btn" type="button" data-ptv4-clarify disabled>Submit Clarification</button>
    <button class="ptv4-btn" type="button" data-ptv4-next disabled>Next Question</button>
    <button class="ptv4-btn warn" type="button" data-ptv4-end disabled>End Test</button>
  </section>
</div>

<script>
window.IPCAProgressTestV4Config = {
  cohortId: <?= (int)$cohortId ?>,
  lessonId: <?= (int)$lessonId ?>,
  firstName: <?= json_encode($firstName, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
  lessonTitle: <?= json_encode($lessonTitle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="/assets/progress_test_v4.js?v=1"></script>
<?php cw_footer(); ?>
