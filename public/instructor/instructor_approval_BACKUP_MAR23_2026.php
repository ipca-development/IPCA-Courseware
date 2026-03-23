<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/courseware_progression_v2.php';

cw_require_login();

$u = cw_current_user($pdo);
$userId = (int)($u['id'] ?? 0);
$role   = (string)($u['role'] ?? '');

$engine = new CoursewareProgressionV2($pdo);

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    exit('Missing token');
}

$action = $engine->getRequiredActionByToken($token);
if (!$action) {
    http_response_code(404);
    exit('Approval action not found');
}

if ((string)$action['action_type'] !== 'instructor_approval') {
    http_response_code(400);
    exit('Invalid action type');
}

$policy = $engine->getAllPolicies([
    'cohort_id' => (int)$action['cohort_id']
]);

$chiefInstructorUserId = (int)($policy['chief_instructor_user_id'] ?? 0);

if ($role !== 'admin' && $userId !== $chiefInstructorUserId) {
    http_response_code(403);
    exit('Forbidden');
}

$ipAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
$userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

$engine->markRequiredActionOpened((int)$action['id'], $ipAddress, $userAgent);

$error = '';
$success = '';
$emailSendResult = null;

function h2(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function yesno(int $v): string
{
    return $v ? 'Yes' : 'No';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['mark_instructor_session_completed'])) {
            $engine->markInstructorSessionCompleted(
                (int)$action['id'],
                $userId,
                $ipAddress,
                $userAgent
            );
            $success = 'Instructor session completion recorded.';
        } else {
            $decisionCode = trim((string)($_POST['decision_code'] ?? ''));
            $grantedExtraAttempts = max(0, min(5, (int)($_POST['granted_extra_attempts'] ?? 0)));
            $summaryRevisionRequired = isset($_POST['summary_revision_required']) ? 1 : 0;
            $oneOnOneRequired = isset($_POST['one_on_one_required']) ? 1 : 0;
            $trainingSuspended = isset($_POST['training_suspended']) ? 1 : 0;
            $majorInterventionFlag = isset($_POST['major_intervention_flag']) ? 1 : 0;
            $decisionNotes = trim((string)($_POST['decision_notes'] ?? ''));

            $decisionResult = $engine->recordInstructorDecision(
                (int)$action['id'],
                [
                    'decision_code' => $decisionCode,
                    'granted_extra_attempts' => $grantedExtraAttempts,
                    'summary_revision_required' => $summaryRevisionRequired,
                    'one_on_one_required' => $oneOnOneRequired,
                    'training_suspended' => $trainingSuspended,
                    'major_intervention_flag' => $majorInterventionFlag,
                    'decision_notes' => $decisionNotes,
                ],
                $userId,
                $ipAddress,
                $userAgent
            );

            $studentRecipient = $engine->getUserRecipient((int)$action['user_id']);
            if ($studentRecipient !== null) {
                $lessonTitle = $engine->getLessonTitle((int)$action['lesson_id']);
                $cohortTitle = $engine->getCohortTitle((int)$action['cohort_id']);
                $studentName = trim((string)($studentRecipient['name'] ?? 'Student'));
                if ($studentName === '') {
                    $studentName = 'Student';
                }

                $subject = 'Instructor Intervention Decision - ' . $lessonTitle;

                $html = ''
                    . '<p>Dear ' . h2($studentName) . ',</p>'
                    . '<p>Your instructor has recorded a decision for <strong>' . h2($lessonTitle) . '</strong> in <strong>' . h2($cohortTitle) . '</strong>.</p>'
                    . '<p><strong>Decision:</strong> ' . h2((string)$decisionResult['decision_code']) . '</p>'
                    . '<p><strong>Extra attempts granted:</strong> ' . h2((string)$decisionResult['granted_extra_attempts']) . '</p>'
                    . '<p><strong>Summary revision required:</strong> ' . h2(yesno((int)$decisionResult['summary_revision_required'])) . '</p>'
                    . '<p><strong>Instructor session required:</strong> ' . h2(yesno((int)$decisionResult['one_on_one_required'])) . '</p>'
                    . '<p><strong>Training suspended:</strong> ' . h2(yesno((int)$decisionResult['training_suspended'])) . '</p>'
                    . '<p><strong>Instructor notes:</strong><br>' . nl2br(h2((string)$decisionResult['decision_notes'])) . '</p>'
                    . '<p>Please review your course page for the next step.</p>'
                    . '<p>Kind regards,<br>Chief Training Team<br>IPCA Courseware</p>';

                $text = ''
                    . "Dear {$studentName},\n\n"
                    . "Your instructor has recorded a decision for {$lessonTitle} in {$cohortTitle}.\n\n"
                    . "Decision: {$decisionResult['decision_code']}\n"
                    . "Extra attempts granted: {$decisionResult['granted_extra_attempts']}\n"
                    . "Summary revision required: " . yesno((int)$decisionResult['summary_revision_required']) . "\n"
                    . "Instructor session required: " . yesno((int)$decisionResult['one_on_one_required']) . "\n"
                    . "Training suspended: " . yesno((int)$decisionResult['training_suspended']) . "\n\n"
                    . "Instructor notes:\n{$decisionResult['decision_notes']}\n\n"
                    . "Please review your course page for the next step.\n\n"
                    . "Kind regards,\nChief Training Team\nIPCA Courseware";

                $emailId = $engine->queueProgressionEmail([
                    'user_id' => (int)$action['user_id'],
                    'cohort_id' => (int)$action['cohort_id'],
                    'lesson_id' => (int)$action['lesson_id'],
                    'progress_test_id' => isset($action['progress_test_id']) ? (int)$action['progress_test_id'] : null,
                    'email_type' => 'instructor_intervention_decision',
                    'recipients_to' => [[
                        'email' => (string)$studentRecipient['email'],
                        'name' => $studentName
                    ]],
                    'recipients_cc' => [],
                    'subject' => $subject,
                    'body_html' => $html,
                    'body_text' => $text,
                    'ai_inputs' => [
                        'decision_code' => $decisionResult['decision_code'],
                        'granted_extra_attempts' => $decisionResult['granted_extra_attempts'],
                        'summary_revision_required' => $decisionResult['summary_revision_required'],
                        'one_on_one_required' => $decisionResult['one_on_one_required'],
                        'training_suspended' => $decisionResult['training_suspended'],
                        'major_intervention_flag' => $decisionResult['major_intervention_flag'],
                    ],
                    'sent_status' => 'queued'
                ]);

                try {
                    $emailSendResult = $engine->sendProgressionEmailById((int)$emailId);
                } catch (Throwable $mailEx) {
                    $emailSendResult = [
                        'ok' => false,
                        'error' => $mailEx->getMessage()
                    ];
                }
            }

            $success = 'Instructor decision recorded successfully.';
        }

        $action = $engine->getRequiredActionByToken($token);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $action = $engine->getRequiredActionByToken($token);
    }
}

$activitySt = $pdo->prepare("
    SELECT
        completion_status,
        granted_extra_attempts,
        one_on_one_required,
        one_on_one_completed
    FROM lesson_activity
    WHERE user_id = ?
      AND cohort_id = ?
      AND lesson_id = ?
    LIMIT 1
");
$activitySt->execute([
    (int)$action['user_id'],
    (int)$action['cohort_id'],
    (int)$action['lesson_id']
]);
$activity = $activitySt->fetch(PDO::FETCH_ASSOC) ?: [];

cw_header('Instructor Approval');
?>

<div class="card" style="max-width:980px;margin:24px auto;">
    <h1>Instructor Decision Page</h1>

    <p><strong>Title:</strong> <?= h2((string)$action['title']) ?></p>

    <div style="margin-top:16px;padding:16px;border:1px solid #ddd;border-radius:10px;background:#fafafa;">
        <?= (string)($action['instructions_html'] ?? '') ?>
    </div>

    <?php if ($error !== ''): ?>
        <div style="margin-top:20px;padding:14px;border-radius:10px;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;">
            <?= h2($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div style="margin-top:20px;padding:14px;border-radius:10px;background:#dcfce7;border:1px solid #86efac;color:#166534;">
            <?= h2($success) ?>
        </div>
    <?php endif; ?>

    <div style="margin-top:20px;padding:16px;border:1px solid #ddd;border-radius:10px;background:#fff;">
        <h3 style="margin-top:0;">Current state</h3>
        <p><strong>Action status:</strong> <?= h2((string)$action['status']) ?></p>
        <p><strong>Decision code:</strong> <?= h2((string)($action['decision_code'] ?? '')) ?: '—' ?></p>
        <p><strong>Granted extra attempts:</strong> <?= h2((string)($action['granted_extra_attempts'] ?? 0)) ?></p>
        <p><strong>Summary revision required:</strong> <?= h2(yesno((int)($action['summary_revision_required'] ?? 0))) ?></p>
        <p><strong>Instructor session required:</strong> <?= h2(yesno((int)($action['one_on_one_required'] ?? 0))) ?></p>
        <p><strong>Training suspended:</strong> <?= h2(yesno((int)($action['training_suspended'] ?? 0))) ?></p>
        <p><strong>Major intervention:</strong> <?= h2(yesno((int)($action['major_intervention_flag'] ?? 0))) ?></p>
        <p><strong>Lesson completion status:</strong> <?= h2((string)($activity['completion_status'] ?? '')) ?: '—' ?></p>
        <p><strong>Instructor notes:</strong><br><?= nl2br(h2((string)($action['decision_notes'] ?? ''))) ?></p>
    </div>

    <?php if ((string)$action['status'] !== 'approved'): ?>
        <form method="post" style="margin-top:20px;">
            <div style="display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:16px;">
                <div>
                    <label><strong>Decision Type</strong></label><br>
                    <select name="decision_code" style="margin-top:6px;padding:10px;width:100%;box-sizing:border-box;" required>
                        <option value="">Select decision</option>
                        <option value="approve_additional_attempts">approve_additional_attempts</option>
                        <option value="approve_with_summary_revision">approve_with_summary_revision</option>
                        <option value="approve_with_one_on_one">approve_with_one_on_one</option>
                        <option value="suspend_training">suspend_training</option>
                    </select>
                </div>

                <div>
                    <label><strong>Extra Attempts Granted (0–5)</strong></label><br>
                    <input
                        type="number"
                        name="granted_extra_attempts"
                        min="0"
                        max="5"
                        step="1"
                        value="0"
                        style="margin-top:6px;padding:10px;width:100%;box-sizing:border-box;"
                    >
                </div>
            </div>

            <div style="margin-top:16px;display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:12px;">
                <label><input type="checkbox" name="summary_revision_required" value="1"> summary_revision_required</label>
                <label><input type="checkbox" name="one_on_one_required" value="1"> one_on_one_required</label>
                <label><input type="checkbox" name="training_suspended" value="1"> training_suspended</label>
                <label><input type="checkbox" name="major_intervention_flag" value="1"> major_intervention_flag</label>
            </div>

            <div style="margin-top:16px;">
                <label><strong>Decision Notes</strong></label><br>
                <textarea
                    name="decision_notes"
                    rows="6"
                    style="margin-top:6px;padding:10px;width:100%;box-sizing:border-box;"
                    required
                ></textarea>
            </div>

            <div style="margin-top:20px;">
                <button type="submit" class="btn">Record Instructor Decision</button>
            </div>
        </form>
    <?php endif; ?>

    <?php if (
        (string)$action['status'] === 'approved' &&
        (int)($action['one_on_one_required'] ?? 0) === 1 &&
        (int)($activity['one_on_one_completed'] ?? 0) !== 1 &&
        (int)($action['training_suspended'] ?? 0) !== 1
    ): ?>
        <form method="post" style="margin-top:20px;">
            <input type="hidden" name="mark_instructor_session_completed" value="1">
            <button type="submit" class="btn">Mark Instructor Session Completed</button>
        </form>
    <?php endif; ?>

    <?php if ($emailSendResult !== null): ?>
        <div style="margin-top:20px;padding:14px;border-radius:10px;background:#f8fafc;border:1px solid #cbd5e1;color:#0f172a;">
            Student notification email:
            <strong><?= !empty($emailSendResult['ok']) ? 'sent' : 'failed' ?></strong>
            <?php if (!empty($emailSendResult['error'])): ?>
                <br><?= h2((string)$emailSendResult['error']) ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php cw_footer(); ?>