<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/courseware_progression_v2.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');

    if ($role !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }

    $engine = new CoursewareProgressionV2($pdo);

    $chiefRecipient = $engine->getChiefInstructorRecipient([
        'cohort_id' => 2
    ]);

    if (!$chiefRecipient) {
        throw new RuntimeException('Chief instructor recipient not found.');
    }

    $emailId = $engine->queueProgressionEmail([
        'user_id' => 3,
        'cohort_id' => 2,
        'lesson_id' => 1,
        'progress_test_id' => null,
        'email_type' => 'instructor_approval_required_chief',
        'recipients_to' => [[
            'email' => (string)$chiefRecipient['email'],
            'name'  => (string)$chiefRecipient['name']
        ]],
        'recipients_cc' => [],
        'subject' => 'TEST - Chief Instructor Email',
        'body_html' => '<p>This is a direct test email to the Chief Instructor recipient.</p>',
        'body_text' => 'This is a direct test email to the Chief Instructor recipient.',
        'ai_inputs' => [
            'trigger' => 'manual_test'
        ],
        'sent_status' => 'queued'
    ]);

    $result = $engine->sendProgressionEmailById((int)$emailId);

    echo json_encode([
        'ok' => true,
        'chief_recipient' => $chiefRecipient,
        'email_id' => $emailId,
        'send_result' => $result
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}