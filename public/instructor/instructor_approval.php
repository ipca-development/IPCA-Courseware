<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/courseware_progression_v2.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');

if ($role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

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

$ipAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
$userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

$engine->markRequiredActionOpened((int)$action['id'], $ipAddress, $userAgent);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $engine->approveRequiredAction((int)$action['id'], $ipAddress, $userAgent);

    $engine->logProgressionEvent([
        'user_id' => (int)$action['user_id'],
        'cohort_id' => (int)$action['cohort_id'],
        'lesson_id' => (int)$action['lesson_id'],
        'progress_test_id' => isset($action['progress_test_id']) ? (int)$action['progress_test_id'] : null,
        'event_type' => 'required_action',
        'event_code' => 'instructor_approval_completed',
        'event_status' => 'info',
        'actor_type' => 'admin',
        'actor_user_id' => (int)($u['id'] ?? 0),
        'event_time' => gmdate('Y-m-d H:i:s'),
        'payload' => [
            'required_action_id' => (int)$action['id']
        ],
        'legal_note' => 'Instructor approved additional progression after maximum failed attempts.'
    ]);

    $done = true;
} else {
    $done = ((string)$action['status'] === 'approved');
}

cw_header('Instructor Approval');
?>

<div class="card" style="max-width:900px;margin:24px auto;">
    <h1>Instructor Approval Required</h1>

    <p><strong>Title:</strong> <?= h((string)$action['title']) ?></p>

    <div style="margin-top:16px;padding:16px;border:1px solid #ddd;border-radius:10px;background:#fafafa;">
        <?= (string)($action['instructions_html'] ?? '') ?>
    </div>

    <?php if ($done): ?>
        <div style="margin-top:20px;padding:14px;border-radius:10px;background:#dcfce7;border:1px solid #86efac;color:#166534;">
            Instructor approval has been recorded successfully.
        </div>
    <?php else: ?>
        <form method="post" style="margin-top:20px;">
            <button type="submit" class="btn">Approve Further Progression</button>
        </form>
    <?php endif; ?>
</div>

<?php cw_footer(); ?>