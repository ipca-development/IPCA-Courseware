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
    $check = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id=? AND user_id=? LIMIT 1");
    $check->execute([$cohortId, $userId]);
    if (!$check->fetchColumn()) {
        http_response_code(403);
        exit('Not enrolled in this cohort');
    }
}

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
    $st->execute([$userId, $lessonId]);
    $lessonActivityPassed = ((string)$st->fetchColumn() === 'passed');

    if ($lessonActivityPassed) {
        return true;
    }

    $pt = $pdo->prepare("
        SELECT 1
        FROM progress_tests_v2
        WHERE user_id=? AND lesson_id=? AND status='completed' AND score_pct >= 75
        LIMIT 1
    ");
    $pt->execute([$userId, $lessonId]);

    return (bool)$pt->fetchColumn();
}

function get_summary_len(PDO $pdo, int $userId, int $cohortId, int $lessonId): int {
    $st = $pdo->prepare("
        SELECT summary_plain
        FROM lesson_summaries
        WHERE user_id=? AND cohort_id=? AND lesson_id=?
        LIMIT 1
    ");
    $st->execute([$userId, $cohortId, $lessonId]);
    $plain = (string)($st->fetchColumn() ?: '');
    return mb_strlen(trim($plain));
}

function get_test_status_v2(PDO $pdo, int $userId, int $cohortId, int $lessonId): array {
    $st = $pdo->prepare("
      SELECT attempt, status, score_pct, started_at, completed_at
      FROM progress_tests_v2
      WHERE user_id=? AND cohort_id=? AND lesson_id=?
      ORDER BY attempt DESC
      LIMIT 1
    ");
    $st->execute([$userId, $cohortId, $lessonId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    $mx = $pdo->prepare("
      SELECT MAX(attempt)
      FROM progress_tests_v2
      WHERE user_id=? AND cohort_id=? AND lesson_id=?
    ");
    $mx->execute([$userId, $cohortId, $lessonId]);
    $maxAttempt = (int)($mx->fetchColumn() ?: 0);

    $pass = $pdo->prepare("
      SELECT MAX(score_pct)
      FROM progress_tests_v2
      WHERE user_id=? AND cohort_id=? AND lesson_id=? AND status='completed'
    ");
    $pass->execute([$userId, $cohortId, $lessonId]);
    $bestScore = $pass->fetchColumn();
    $bestScore = ($bestScore === null) ? null : (int)$bestScore;

    return [
        'max_attempt' => $maxAttempt,
        'last' => $row ?: null,
        'best_score' => $bestScore,
        'passed' => ($bestScore !== null && $bestScore >= 75)
    ];
}

function format_deadline_date(string $deadlineUtc): string {
    if (trim($deadlineUtc) === '') return '—';

    try {
        $dt = new DateTimeImmutable($deadlineUtc, new DateTimeZone('UTC'));
        return $dt->format('D, F j, Y');
    } catch (Throwable $e) {
        return h($deadlineUtc);
    }
}

function deadline_meta(string $deadlineUtc): array {
    if (trim($deadlineUtc) === '') {
        return [
            'label' => 'No deadline',
            'class' => 'deadline-neutral',
            'date'  => '—'
        ];
    }

    try {
        $deadline = new DateTimeImmutable($deadlineUtc, new DateTimeZone('UTC'));
        $todayUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $todayDate = new DateTimeImmutable($todayUtc->format('Y-m-d') . ' 00:00:00', new DateTimeZone('UTC'));
        $deadlineDate = new DateTimeImmutable($deadline->format('Y-m-d') . ' 00:00:00', new DateTimeZone('UTC'));

        $diffSeconds = $deadlineDate->getTimestamp() - $todayDate->getTimestamp();
        $days = (int)floor($diffSeconds / 86400);

        if ($days < 0) {
            $label = 'overdue ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's');
            $class = 'deadline-red';
        } elseif ($days === 0) {
            $label = 'due today';
            $class = 'deadline-red';
        } elseif ($days === 1) {
            $label = '1 day';
            $class = 'deadline-red';
        } elseif ($days <= 3) {
            $label = $days . ' days';
            $class = 'deadline-orange';
        } else {
            $label = $days . ' days';
            $class = 'deadline-green';
        }

        return [
            'label' => $label,
            'class' => $class,
            'date'  => $deadline->format('D, F j, Y')
        ];
    } catch (Throwable $e) {
        return [
            'label' => 'Unknown',
            'class' => 'deadline-neutral',
            'date'  => $deadlineUtc
        ];
    }
}

cw_header('Course');
?>
<style>
  table td, table th { vertical-align: middle; }

  .deadline-wrap{
    min-width:220px;
  }
  .deadline-date{
    font-weight:700;
    color:#1f2937;
    margin-bottom:6px;
  }
  .deadline-pill{
    width:100%;
    min-width:190px;
    border-radius:999px;
    padding:6px 12px;
    font-size:12px;
    font-weight:700;
    line-height:1.2;
    display:inline-block;
    text-align:center;
    box-sizing:border-box;
    border:1px solid transparent;
  }
  .deadline-green{
    background:#dcfce7;
    border-color:#86efac;
    color:#166534;
  }
  .deadline-orange{
    background:#ffedd5;
    border-color:#fdba74;
    color:#c2410c;
  }
  .deadline-red{
    background:#fee2e2;
    border-color:#fca5a5;
    color:#b91c1c;
  }
  .deadline-neutral{
    background:#f3f4f6;
    border-color:#d1d5db;
    color:#4b5563;
  }

  .icon-ok{
    color:#15803d;
    font-weight:900;
    font-size:20px;
    line-height:1;
  }
  .icon-bad{
    color:#b91c1c;
    font-weight:900;
    font-size:20px;
    line-height:1;
  }
  .icon-dash{
    color:#9ca3af;
    font-weight:700;
    font-size:18px;
    line-height:1;
  }

  .score-pill{
    display:inline-block;
    border-radius:999px;
    padding:6px 10px;
    font-size:12px;
    font-weight:800;
    line-height:1.2;
    border:1px solid transparent;
    margin-bottom:6px;
  }
  .score-pass{
    background:#dcfce7;
    border-color:#86efac;
    color:#166534;
  }
  .score-fail{
    background:#fee2e2;
    border-color:#fca5a5;
    color:#b91c1c;
  }
  .score-pending{
    background:#dbeafe;
    border-color:#93c5fd;
    color:#1d4ed8;
  }
  .smallmuted{
    font-size:11px;
    opacity:.75;
    margin-top:4px;
  }

  .pt-cell .btn{
    margin-top:6px;
  }
</style>

<div class="card">
  <div class="muted">
    <?= h($cohort['program_key']) ?> — <?= h($cohort['course_title']) ?> • Cohort: <?= h($cohort['name']) ?><br>
    Deadlines based on UTC date
  </div>
  <p style="margin-top:10px;">
    <a class="btn btn-sm" href="/student/dashboard.php">← Back</a>
  </p>
</div>

<div class="card">
  <h2>Lessons</h2>
  <table>
    <tr>
      <th style="width:28%;">Lesson</th>
      <th style="width:25%;">Deadline</th>
      <th style="width:9%;">Status</th>
      <th style="width:10%;">Summary</th>
      <th style="width:18%;">Progress Test</th>
      <th style="width:10%;">Action</th>
    </tr>

    <?php foreach ($lessons as $l): ?>
      <?php
        $lessonRowId = (int)$l['lesson_id'];

        $locked = false;
        $passed = false;

        if ($role === 'student') {
            if (!empty($l['unlock_after_lesson_id'])) {
                $locked = !lesson_passed($pdo, $userId, (int)$l['unlock_after_lesson_id']);
            }
            $passed = lesson_passed($pdo, $userId, $lessonRowId);
        }

        $first = $pdo->prepare("
            SELECT id
            FROM slides
            WHERE lesson_id=? AND is_deleted=0
            ORDER BY page_number ASC
            LIMIT 1
        ");
        $first->execute([$lessonRowId]);
        $firstSlideId = (int)$first->fetchColumn();

        $sumLen = ($role === 'admin') ? 9999 : get_summary_len($pdo, $userId, $cohortId, $lessonRowId);
        $summaryOk = ($sumLen >= 400);

        $test = ($role === 'admin')
            ? ['max_attempt' => 0, 'last' => null, 'best_score' => null, 'passed' => false]
            : get_test_status_v2($pdo, $userId, $cohortId, $lessonRowId);

        $last = $test['last'];
        $attemptsUsed = (int)$test['max_attempt'];
        $attemptsLeft = max(0, 3 - $attemptsUsed);
        $testPassed = !empty($test['passed']);
        $bestScore = $test['best_score'];

        $canTest = true;
        if ($role === 'student' && $locked) $canTest = false;
        if ($role === 'student' && !$summaryOk) $canTest = false;
        if ($role === 'student' && $testPassed) $canTest = false;
        if ($role === 'student' && $attemptsLeft <= 0) $canTest = false;

        $ptUrlV2 = '/student/progress_test_v2.php?cohort_id=' . (int)$cohortId . '&lesson_id=' . (int)$lessonRowId;
        $deadline = deadline_meta((string)$l['deadline_utc']);
      ?>
      <tr>
        <td>
          <strong><?= h($l['title']) ?></strong>
        </td>

        <td>
          <div class="deadline-wrap">
            <div class="deadline-date"><?= h($deadline['date']) ?></div>
            <div class="deadline-pill <?= h($deadline['class']) ?>">
              <?= h($deadline['label']) ?>
            </div>
          </div>
        </td>

        <td>
          <?php if ($role === 'admin'): ?>
            <span class="icon-dash">—</span>
          <?php elseif ($passed): ?>
            <span class="icon-ok">✓</span>
          <?php else: ?>
            <span class="icon-dash">—</span>
          <?php endif; ?>
        </td>

        <td>
          <?php if ($role === 'admin'): ?>
            <span class="icon-dash">—</span>
          <?php elseif ($summaryOk): ?>
            <span class="icon-ok">✓</span>
          <?php else: ?>
            <span class="icon-bad">✕</span>
          <?php endif; ?>
        </td>

        <td class="pt-cell">
          <?php if ($role === 'admin'): ?>
            <span class="icon-dash">—</span>

          <?php else: ?>

            <?php if ($testPassed && $bestScore !== null): ?>
              <div class="score-pill score-pass">
                <?= (int)$bestScore ?>% PASS
              </div>

            <?php elseif ($last && $last['score_pct'] !== null): ?>
              <div class="score-pill score-fail">
                <?= (int)$last['score_pct'] ?>% • <?= (int)$attemptsLeft ?> attempt<?= $attemptsLeft === 1 ? '' : 's' ?> left
              </div>

            <?php elseif ($last && (string)$last['status'] !== 'completed'): ?>
              <div class="score-pill score-pending">
                In progress
              </div>

            <?php endif; ?>

            <?php if ($canTest): ?>
              <a class="btn btn-sm" href="<?= h($ptUrlV2) ?>">Start</a>

            <?php elseif ($locked): ?>
              <div class="smallmuted">Complete previous lesson first</div>

            <?php elseif (!$summaryOk): ?>
              <div class="smallmuted">Complete summary first</div>

            <?php elseif (!$testPassed && $attemptsLeft <= 0): ?>
              <div class="smallmuted">No attempts left</div>

            <?php endif; ?>

          <?php endif; ?>
        </td>

        <td>
          <?php if ($firstSlideId <= 0): ?>
            <span class="icon-dash">—</span>
          <?php elseif ($role === 'student' && $locked): ?>
            <span class="icon-dash">—</span>
          <?php else: ?>
            <a class="btn btn-sm" href="/player/slide.php?slide_id=<?= (int)$firstSlideId ?>">Start</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php cw_footer(); ?>