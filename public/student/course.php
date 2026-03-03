<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');

if ($role !== 'student' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$userId = (int)$u['id'];
$cohortId = (int)($_GET['cohort_id'] ?? 0);
if ($cohortId <= 0) exit('Missing cohort_id');

if ($role === 'student') {
    // student must be enrolled
    $check = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id=? AND user_id=? LIMIT 1");
    $check->execute([$cohortId,$userId]);
    if (!$check->fetchColumn()) {
        http_response_code(403);
        exit('Not enrolled in this cohort');
    }
}
// admin bypass: no enrollment required

$co = $pdo->prepare("
  SELECT co.*, c.title AS course_title, p.program_key
  FROM cohorts co
  JOIN courses c ON c.id=co.course_id
  JOIN programs p ON p.id=c.program_id
  WHERE co.id=? LIMIT 1
");
$co->execute([$cohortId]);
$cohort = $co->fetch(PDO::FETCH_ASSOC);
if (!$cohort) exit('Cohort not found');

$lessons = $pdo->prepare("
  SELECT d.deadline_utc, d.unlock_after_lesson_id,
         l.id AS lesson_id, l.external_lesson_id, l.title
  FROM cohort_lesson_deadlines d
  JOIN lessons l ON l.id=d.lesson_id
  WHERE d.cohort_id=?
  ORDER BY d.sort_order, d.id
");
$lessons->execute([$cohortId]);
$lessons = $lessons->fetchAll(PDO::FETCH_ASSOC);

function lesson_passed(PDO $pdo, int $userId, int $lessonId): bool {
    $st = $pdo->prepare("SELECT status FROM lesson_activity WHERE user_id=? AND lesson_id=? LIMIT 1");
    $st->execute([$userId,$lessonId]);
    return ((string)$st->fetchColumn() === 'passed');
}

function get_summary_len(PDO $pdo, int $userId, int $cohortId, int $lessonId): int {
    $st = $pdo->prepare("SELECT summary_plain FROM lesson_summaries WHERE user_id=? AND cohort_id=? AND lesson_id=? LIMIT 1");
    $st->execute([$userId, $cohortId, $lessonId]);
    $plain = (string)($st->fetchColumn() ?: '');
    $plain = trim($plain);
    return mb_strlen($plain);
}

function get_test_status(PDO $pdo, int $userId, int $cohortId, int $lessonId): array {
    // last attempt
    $st = $pdo->prepare("
      SELECT attempt, status, score_pct, started_at, completed_at
      FROM progress_tests
      WHERE user_id=? AND cohort_id=? AND lesson_id=?
      ORDER BY attempt DESC
      LIMIT 1
    ");
    $st->execute([$userId, $cohortId, $lessonId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    $max = $pdo->prepare("SELECT MAX(attempt) FROM progress_tests WHERE user_id=? AND cohort_id=? AND lesson_id=?");
    $max->execute([$userId, $cohortId, $lessonId]);
    $maxAttempt = (int)($max->fetchColumn() ?: 0);

    return [
        'max_attempt' => $maxAttempt,
        'last' => $row ?: null
    ];
}

cw_header('Course');
?>
<div class="card">
  <div class="muted">
    <?= h($cohort['program_key']) ?> — <?= h($cohort['course_title']) ?> • Cohort: <?= h($cohort['name']) ?><br>
    Deadlines: 00:00 UTC
  </div>
  <p style="margin-top:10px;">
    <a class="btn btn-sm" href="/student/dashboard.php">← Back</a>
  </p>
</div>

<div class="card">
  <h2>Lessons</h2>
  <table>
    <tr>
      <th>Lesson</th>
      <th>Deadline (UTC)</th>
      <th>Status</th>
      <th>Summary</th>
      <th>Progress Test</th>
      <th>Action</th>
    </tr>

    <?php foreach ($lessons as $l): ?>
      <?php
        $locked = false;
        $passed = false;

        if ($role === 'student') {
            if (!empty($l['unlock_after_lesson_id'])) {
                $locked = !lesson_passed($pdo, $userId, (int)$l['unlock_after_lesson_id']);
            }
            $passed = lesson_passed($pdo, $userId, (int)$l['lesson_id']);
        }

        $status = ($role === 'admin') ? 'admin_view' : ($passed ? 'passed' : ($locked ? 'locked' : 'unlocked'));

        $first = $pdo->prepare("SELECT id FROM slides WHERE lesson_id=? AND is_deleted=0 ORDER BY page_number ASC LIMIT 1");
        $first->execute([(int)$l['lesson_id']]);
        $slideId = (int)$first->fetchColumn();

        $sumLen = ($role === 'admin') ? 9999 : get_summary_len($pdo, $userId, $cohortId, (int)$l['lesson_id']);
        $summaryOk = ($sumLen >= 400);

        $test = ($role === 'admin') ? ['max_attempt'=>0,'last'=>null] : get_test_status($pdo, $userId, $cohortId, (int)$l['lesson_id']);
        $last = $test['last'];

        $attemptsUsed = (int)$test['max_attempt'];
        $attemptsLeft = max(0, 3 - $attemptsUsed);

        $canTest = true;
        if ($role === 'student' && $locked) $canTest = false;
        if ($role === 'student' && !$summaryOk) $canTest = false;
      ?>
      <tr>
        <td><?= (int)$l['external_lesson_id'] ?> — <?= h($l['title']) ?></td>
        <td><?= h($l['deadline_utc']) ?></td>
        <td><?= h($status) ?></td>

        <td>
  <?php if ($role === 'admin'): ?>
    <span class="muted">admin</span>
  <?php else: ?>
    <?php if ($last): ?>
      <div class="muted" style="font-size:12px;">
        Last: <?= h($last['status']) ?>
        <?= $last['score_pct'] !== null ? (' • '.(int)$last['score_pct'].'%') : '' ?>
        • Attempt <?= (int)$last['attempt'] ?>/3
      </div>
    <?php else: ?>
      <div class="muted" style="font-size:12px;">Not started</div>
    <?php endif; ?>

    <div class="muted" style="font-size:12px;">Attempts left: <?= (int)$attemptsLeft ?></div>

    <?php
      $ptUrl = '/student/progress_test.php?cohort_id='.(int)$cohortId.'&lesson_id='.(int)$l['lesson_id'];
    ?>

    <?php if ($canTest && $attemptsLeft > 0): ?>
      <a class="btn btn-sm" href="<?= h($ptUrl) ?>">Take Progress Test</a>
      <div class="muted" style="font-size:11px; margin-top:6px;">
        <?= h($ptUrl) ?>
      </div>
    <?php elseif ($locked): ?>
      <span class="muted">Locked</span>
    <?php elseif (!$summaryOk): ?>
      <span class="muted">Complete summary first</span>
    <?php else: ?>
      <span class="muted">No attempts left</span>
    <?php endif; ?>
  <?php endif; ?>
</td>

        

        <td>
          <?php if ($slideId <= 0): ?>
            <span class="muted">—</span>
          <?php elseif ($role === 'student' && $locked): ?>
            <span class="muted">Locked</span>
          <?php else: ?>
            <a class="btn btn-sm" href="/player/slide.php?slide_id=<?= (int)$slideId ?>">Start</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php cw_footer(); ?>