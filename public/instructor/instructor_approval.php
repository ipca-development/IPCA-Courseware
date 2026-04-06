<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/courseware_progression_v2.php';

cw_require_login();

$u = cw_current_user($pdo);
$userId = (int)($u['id'] ?? 0);

$engine = new CoursewareProgressionV2($pdo);

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    exit('Missing token');
}

$error = '';
$success = '';

function h2(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function yesno(int $v): string
{
    return $v ? 'Yes' : 'No';
}

function load_instructor_approval_page_state(
    CoursewareProgressionV2 $engine,
    string $token
): array {
    $state = $engine->getInstructorApprovalPageStateByToken($token);

    if (!$state || empty($state['action'])) {
        http_response_code(404);
        exit('Approval action not found');
    }

    if (isset($state['access']) && is_array($state['access']) && array_key_exists('is_allowed', $state['access'])) {
        if (empty($state['access']['is_allowed'])) {
            http_response_code(403);
            exit('Forbidden');
        }
    }

    $action = (array)$state['action'];
    if ((string)($action['action_type'] ?? '') !== 'instructor_approval') {
        http_response_code(400);
        exit('Invalid action type');
    }

    return $state;
}

$state = load_instructor_approval_page_state($engine, $token);
$action = (array)$state['action'];
$activity = (array)($state['activity'] ?? []);

$ipAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
$userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

try {
    $engine->markInstructorApprovalPageOpened(
    (int)$action['id'],
    $ipAddress,
    $userAgent
);
} catch (Throwable $e) {
    error_log('markInstructorApprovalPageOpened failed: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['mark_instructor_session_completed'])) {
            $result = $engine->markInstructorApprovalOneOnOneCompleted(
                (int)$action['id'],
                $userId,
                $ipAddress,
                $userAgent
            );
        } else {
            $payload = [
                'decision_code' => trim((string)($_POST['decision_code'] ?? '')),
                'granted_extra_attempts' => (int)($_POST['granted_extra_attempts'] ?? 0),
                'summary_revision_required' => isset($_POST['summary_revision_required']) ? 1 : 0,
                'one_on_one_required' => isset($_POST['one_on_one_required']) ? 1 : 0,
                'training_suspended' => isset($_POST['training_suspended']) ? 1 : 0,
                'major_intervention_flag' => isset($_POST['major_intervention_flag']) ? 1 : 0,
                'decision_notes' => trim((string)($_POST['decision_notes'] ?? '')),
            ];

            $result = $engine->processInstructorApprovalDecision(
                (int)$action['id'],
                $payload,
                $userId,
                $ipAddress,
                $userAgent
            );
        }

        $success = trim((string)($result['message'] ?? ''));
        if ($success === '') {
            $success = 'Action recorded successfully.';
        }

        if (!empty($result['state']) && is_array($result['state'])) {
            $state = $result['state'];
        } else {
            $state = load_instructor_approval_page_state($engine, $token);
        }

        $action = (array)($state['action'] ?? []);
        $activity = (array)($state['activity'] ?? []);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $state = load_instructor_approval_page_state($engine, $token);
        $action = (array)($state['action'] ?? []);
        $activity = (array)($state['activity'] ?? []);
    }
}

cw_header('Instructor Approval');
?>

<div class="card" style="max-width:980px;margin:24px auto;">
    <h1>Instructor Decision Page</h1>

    <p><strong>Title:</strong> <?= h2((string)($action['title'] ?? '')) ?></p>

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
        <p><strong>Action status:</strong> <?= h2((string)($action['status'] ?? '')) ?></p>
        <p><strong>Decision code:</strong> <?= h2((string)($action['decision_code'] ?? '')) ?: '—' ?></p>
        <p><strong>Granted extra attempts:</strong> <?= h2((string)($action['granted_extra_attempts'] ?? 0)) ?></p>
        <p><strong>Summary revision required:</strong> <?= h2(yesno((int)($action['summary_revision_required'] ?? 0))) ?></p>
        <p><strong>Instructor session required:</strong> <?= h2(yesno((int)($action['one_on_one_required'] ?? 0))) ?></p>
        <p><strong>Training suspended:</strong> <?= h2(yesno((int)($action['training_suspended'] ?? 0))) ?></p>
        <p><strong>Major intervention:</strong> <?= h2(yesno((int)($action['major_intervention_flag'] ?? 0))) ?></p>
        <p><strong>Lesson completion status:</strong> <?= h2((string)($activity['completion_status'] ?? '')) ?: '—' ?></p>
        <p><strong>Instructor notes:</strong><br><?= nl2br(h2((string)($action['decision_notes'] ?? ''))) ?></p>
    </div>

    <?php if ((string)($action['status'] ?? '') !== 'approved'): ?>
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
                    <label><strong>Extra Attempts Granted</strong></label><br>
                    <input
                        type="number"
                        name="granted_extra_attempts"
                        min="0"
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
        (string)($action['status'] ?? '') === 'approved' &&
        (int)($action['one_on_one_required'] ?? 0) === 1 &&
        (int)($activity['one_on_one_completed'] ?? 0) !== 1 &&
        (int)($action['training_suspended'] ?? 0) !== 1
    ): ?>
        <form method="post" style="margin-top:20px;">
            <input type="hidden" name="mark_instructor_session_completed" value="1">
            <button type="submit" class="btn">Mark Instructor Session Completed</button>
        </form>
    <?php endif; ?>
</div>

<?php cw_footer(); ?>