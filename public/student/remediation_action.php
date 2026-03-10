<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/courseware_progression_v2.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
$userId = (int)($u['id'] ?? 0);

if ($role !== 'student' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    exit('Missing token');
}

$engine = new CoursewareProgressionV2($pdo);

$action = $engine->getRequiredActionByToken($token);
if (!$action) {
    http_response_code(404);
    exit('Required action not found');
}

$actionUserId = (int)($action['user_id'] ?? 0);
$cohortId = (int)($action['cohort_id'] ?? 0);
$lessonId = (int)($action['lesson_id'] ?? 0);
$progressTestId = isset($action['progress_test_id']) ? (int)$action['progress_test_id'] : null;
$actionId = (int)($action['id'] ?? 0);
$actionType = (string)($action['action_type'] ?? '');
$status = (string)($action['status'] ?? 'pending');

if ($role === 'student' && $actionUserId !== $userId) {
    http_response_code(403);
    exit('Forbidden');
}

if (!in_array($actionType, ['remediation_acknowledgement', 'deadline_reason_submission', 'instructor_approval'], true)) {
    http_response_code(400);
    exit('Unsupported action type');
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function get_client_ip(): ?string {
    $keys = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR'
    ];

    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $raw = trim((string)$_SERVER[$key]);
            if ($raw === '') {
                continue;
            }

            if ($key === 'HTTP_X_FORWARDED_FOR') {
                $parts = explode(',', $raw);
                $raw = trim((string)($parts[0] ?? ''));
            }

            if ($raw !== '') {
                return substr($raw, 0, 45);
            }
        }
    }

    return null;
}

function get_user_agent_string(): ?string {
    if (empty($_SERVER['HTTP_USER_AGENT'])) {
        return null;
    }
    return substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 65535);
}

$clientIp = get_client_ip();
$userAgent = get_user_agent_string();

if (in_array($status, ['pending', 'opened'], true)) {
    $engine->markRequiredActionOpened($actionId, $clientIp, $userAgent);
    $action = $engine->getRequiredActionByToken($token) ?: $action;
    $status = (string)($action['status'] ?? $status);

    $engine->logProgressionEvent([
        'user_id' => $actionUserId,
        'cohort_id' => $cohortId,
        'lesson_id' => $lessonId,
        'progress_test_id' => $progressTestId,
        'event_type' => 'required_action',
        'event_code' => 'required_action_opened',
        'event_status' => 'info',
        'actor_type' => $role === 'admin' ? 'admin' : 'student',
        'actor_user_id' => $userId,
        'event_time' => gmdate('Y-m-d H:i:s'),
        'payload' => [
            'required_action_id' => $actionId,
            'action_type' => $actionType,
            'token' => $token
        ],
        'legal_note' => 'Required action page opened by authenticated user.'
    ]);
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array($status, ['pending', 'opened'], true)) {
        $error = 'This action has already been completed or is no longer active.';
    } else {
        $responseText = trim((string)($_POST['student_response_text'] ?? ''));

        if ($actionType === 'remediation_acknowledgement') {
            if ($responseText === '') {
                $responseText = 'I confirm that I have reviewed and restudied the remedial study items.';
            }
        } elseif ($actionType === 'deadline_reason_submission') {
            if ($responseText === '') {
                $error = 'Please provide your reason before submitting.';
            }
        } elseif ($actionType === 'instructor_approval') {
            if ($responseText === '') {
                $responseText = 'Approved by instructor/chief instructor through secure action page.';
            }
        }

        if ($error === '') {
            $pdo->beginTransaction();

            try {
                $engine->completeRequiredAction($actionId, $responseText, $clientIp, $userAgent);

                if ($actionType === 'deadline_reason_submission') {
                    $updActivity = $pdo->prepare("
                        UPDATE lesson_activity
                        SET
                            reason_submitted = 1,
                            reason_decision = 'pending_review',
                            updated_at = NOW()
                        WHERE user_id = ?
                          AND cohort_id = ?
                          AND lesson_id = ?
                    ");
                    $updActivity->execute([$actionUserId, $cohortId, $lessonId]);
                }

                if ($actionType === 'instructor_approval') {
                    $updActivity = $pdo->prepare("
                        UPDATE lesson_activity
                        SET
                            reason_decision = 'approved',
                            updated_at = NOW()
                        WHERE user_id = ?
                          AND cohort_id = ?
                          AND lesson_id = ?
                    ");
                    $updActivity->execute([$actionUserId, $cohortId, $lessonId]);
                }

                $engine->logProgressionEvent([
                    'user_id' => $actionUserId,
                    'cohort_id' => $cohortId,
                    'lesson_id' => $lessonId,
                    'progress_test_id' => $progressTestId,
                    'event_type' => 'required_action',
                    'event_code' => 'required_action_completed',
                    'event_status' => 'info',
                    'actor_type' => $role === 'admin' ? 'admin' : 'student',
                    'actor_user_id' => $userId,
                    'event_time' => gmdate('Y-m-d H:i:s'),
                    'payload' => [
                        'required_action_id' => $actionId,
                        'action_type' => $actionType,
                        'response_text' => $responseText
                    ],
                    'legal_note' => 'Required action completed and timestamped by authenticated user.'
                ]);

                $pdo->commit();

                $action = $engine->getRequiredActionByToken($token) ?: $action;
                $status = (string)($action['status'] ?? 'completed');
                $success = 'Your acknowledgment has been recorded successfully.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $e->getMessage();
            }
        }
    }
}

$title = (string)($action['title'] ?? 'Required Action');
$instructionsHtml = (string)($action['instructions_html'] ?? '');
$instructionsText = (string)($action['instructions_text'] ?? '');
$studentResponseText = trim((string)($action['student_response_text'] ?? ''));
$openedAt = (string)($action['opened_at'] ?? '');
$completedAt = (string)($action['completed_at'] ?? '');

cw_header($title);
?>

<style>
  .ra-wrap{
    max-width:900px;
    margin:0 auto;
  }
  .ra-card{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:18px;
    box-shadow:0 8px 24px rgba(0,0,0,0.06);
    padding:22px;
    margin-bottom:18px;
  }
  .ra-title{
    font-size:28px;
    font-weight:900;
    color:#1f2937;
    margin-bottom:10px;
  }
  .ra-meta{
    color:#6b7280;
    font-size:14px;
    margin-bottom:14px;
  }
  .ra-status{
    display:inline-block;
    padding:8px 12px;
    border-radius:999px;
    font-size:13px;
    font-weight:800;
    margin-bottom:14px;
  }
  .ra-status.pending,
  .ra-status.opened{
    background:#fef3c7;
    color:#92400e;
  }
  .ra-status.completed,
  .ra-status.approved{
    background:#dcfce7;
    color:#166534;
  }
  .ra-status.rejected,
  .ra-status.expired{
    background:#fee2e2;
    color:#991b1b;
  }
  .ra-box{
    border:1px solid #e5e7eb;
    border-radius:14px;
    padding:16px;
    background:#fafafa;
    margin-top:12px;
  }
  .ra-label{
    display:block;
    font-weight:800;
    margin-bottom:8px;
    color:#1f2937;
  }
  .ra-textarea{
    width:100%;
    min-height:140px;
    border:1px solid #d1d5db;
    border-radius:12px;
    padding:12px;
    font:inherit;
    resize:vertical;
    box-sizing:border-box;
  }
  .ra-help{
    color:#6b7280;
    font-size:13px;
    margin-top:8px;
  }
  .ra-success{
    background:#ecfdf5;
    color:#166534;
    border:1px solid #86efac;
    border-radius:12px;
    padding:12px 14px;
    margin-bottom:14px;
    font-weight:700;
  }
  .ra-error{
    background:#fef2f2;
    color:#991b1b;
    border:1px solid #fca5a5;
    border-radius:12px;
    padding:12px 14px;
    margin-bottom:14px;
    font-weight:700;
  }
  .ra-actions{
    margin-top:16px;
    display:flex;
    gap:10px;
    flex-wrap:wrap;
  }
  .btn-primary{
    background:#1e3c72;
    color:#fff;
    border:none;
    border-radius:12px;
    padding:12px 18px;
    font-weight:800;
    cursor:pointer;
  }
  .btn-primary:hover{
    background:#16325f;
  }
</style>

<div class="ra-wrap">
  <div class="ra-card">
    <div class="ra-title"><?= h($title) ?></div>

    <div class="ra-meta">
      Action type: <strong><?= h($actionType) ?></strong>
      <?php if ($openedAt !== ''): ?>
        • Opened: <strong><?= h($openedAt) ?> UTC</strong>
      <?php endif; ?>
      <?php if ($completedAt !== ''): ?>
        • Completed: <strong><?= h($completedAt) ?> UTC</strong>
      <?php endif; ?>
    </div>

    <div class="ra-status <?= h($status) ?>">
      <?= h(strtoupper(str_replace('_', ' ', $status))) ?>
    </div>

    <?php if ($success !== ''): ?>
      <div class="ra-success"><?= h($success) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="ra-error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="ra-box">
      <?php if (trim($instructionsHtml) !== ''): ?>
        <?= $instructionsHtml ?>
      <?php else: ?>
        <pre style="white-space:pre-wrap; font:inherit; margin:0;"><?= h($instructionsText) ?></pre>
      <?php endif; ?>
    </div>

    <?php if (in_array($status, ['pending', 'opened'], true)): ?>
      <form method="post" action="">
        <input type="hidden" name="token" value="<?= h($token) ?>">

        <div class="ra-box">
          <?php if ($actionType === 'remediation_acknowledgement'): ?>
            <label class="ra-label" for="student_response_text">Student confirmation</label>
            <textarea
              class="ra-textarea"
              id="student_response_text"
              name="student_response_text"
            ><?= h($studentResponseText !== '' ? $studentResponseText : 'I confirm that I have reviewed and restudied the remedial study items and I am ready to continue.') ?></textarea>
            <div class="ra-help">
              This confirmation will be timestamped and stored for audit purposes.
            </div>
          <?php elseif ($actionType === 'deadline_reason_submission'): ?>
            <label class="ra-label" for="student_response_text">Reason for missed deadline</label>
            <textarea
              class="ra-textarea"
              id="student_response_text"
              name="student_response_text"
            ><?= h($studentResponseText) ?></textarea>
            <div class="ra-help">
              Please explain clearly why the deadline was missed. This will be logged in the training record.
            </div>
          <?php else: ?>
            <label class="ra-label" for="student_response_text">Approval note</label>
            <textarea
              class="ra-textarea"
              id="student_response_text"
              name="student_response_text"
            ><?= h($studentResponseText !== '' ? $studentResponseText : 'Approved.') ?></textarea>
          <?php endif; ?>
        </div>

        <div class="ra-actions">
          <?php if ($actionType === 'remediation_acknowledgement'): ?>
            <button class="btn-primary" type="submit">Confirm Remedial Study Completed</button>
          <?php elseif ($actionType === 'deadline_reason_submission'): ?>
            <button class="btn-primary" type="submit">Submit Reason</button>
          <?php else: ?>
            <button class="btn-primary" type="submit">Approve</button>
          <?php endif; ?>
        </div>
      </form>
    <?php else: ?>
      <div class="ra-box">
        <strong>Recorded response:</strong><br><br>
        <?= nl2br(h($studentResponseText !== '' ? $studentResponseText : 'No response text stored.')) ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php cw_footer(); ?>