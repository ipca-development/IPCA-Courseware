<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/courseware_progression_v2.php';
require_once __DIR__ . '/../../src/openai.php';

cw_require_login();

$cohortId = (int)($_GET['cohort_id'] ?? 0);
$lessonId = (int)($_GET['lesson_id'] ?? 0);
$studentId = (int)($_GET['user_id'] ?? 0);

if ($cohortId <= 0 || $lessonId <= 0 || $studentId <= 0) {
    http_response_code(400);
    exit('Missing user_id, cohort_id or lesson_id');
}

$u = cw_current_user($pdo);
$userId = (int)($u['id'] ?? 0);
$role   = (string)($u['role'] ?? '');

$engine = new CoursewareProgressionV2($pdo);

$policy = $engine->getAllPolicies([
    'cohort_id' => $cohortId
]);

$chiefInstructorUserId = (int)($policy['chief_instructor_user_id'] ?? 0);

if ($role !== 'admin' && $userId !== $chiefInstructorUserId) {
    http_response_code(403);
    exit('Forbidden');
}

$sumSt = $pdo->prepare("
    SELECT *
    FROM lesson_summaries
    WHERE user_id = ?
      AND cohort_id = ?
      AND lesson_id = ?
    LIMIT 1
");
$sumSt->execute([$studentId, $cohortId, $lessonId]);
$summary = $sumSt->fetch(PDO::FETCH_ASSOC);

if (!$summary) {
    http_response_code(404);
    exit('Summary not found');
}

$studentSt = $pdo->prepare("SELECT name, email FROM users WHERE id = ? LIMIT 1");
$studentSt->execute([$studentId]);
$student = $studentSt->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'Student', 'email' => ''];

$lessonTitle = $engine->getLessonTitle($lessonId);
$cohortTitle = $engine->getCohortTitle($cohortId);

$actionSt = $pdo->prepare("
    SELECT *
    FROM student_required_actions
    WHERE user_id = ?
      AND cohort_id = ?
      AND lesson_id = ?
      AND action_type = 'instructor_approval'
    ORDER BY id DESC
    LIMIT 1
");
$actionSt->execute([$studentId, $cohortId, $lessonId]);
$latestAction = $actionSt->fetch(PDO::FETCH_ASSOC) ?: null;

$error = '';
$success = '';
$aiHelper = [
    'what_changed' => '',
    'addressed_points' => '',
    'suggested_recommendation' => ''
];

function h3(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function build_ai_helper(PDO $pdo, array $summary, ?array $latestAction): array
{
    $current = trim((string)($summary['summary_plain'] ?? ''));
    $previous = trim((string)($summary['last_reviewed_summary_plain'] ?? ''));
    $reviewFeedback = trim((string)($summary['review_feedback'] ?? ''));
    $decisionNotes = trim((string)($latestAction['decision_notes'] ?? ''));

    if ($current === '') {
        return [
            'what_changed' => 'No current summary text found.',
            'addressed_points' => 'Cannot evaluate.',
            'suggested_recommendation' => 'needs further revision'
        ];
    }

    $schema = [
        "type" => "object",
        "additionalProperties" => false,
        "properties" => [
            "what_changed" => ["type" => "string"],
            "addressed_points" => ["type" => "string"],
            "suggested_recommendation" => ["type" => "string"]
        ],
        "required" => ["what_changed", "addressed_points", "suggested_recommendation"]
    ];

    $system = "You are an instructor summary review assistant. Compare the student's current summary to the last reviewed version and the instructor's requested revision points. Be concise and practical.";
    $user = "LAST REVIEWED SUMMARY:\n{$previous}\n\nCURRENT SUMMARY:\n{$current}\n\nREVIEW FEEDBACK:\n{$reviewFeedback}\n\nINSTRUCTOR DECISION NOTES:\n{$decisionNotes}\n\nReturn:\n1) what changed\n2) whether requested points appear addressed\n3) suggested recommendation: approve OR needs further revision";

    try {
        $resp = cw_openai_responses([
            "model" => cw_openai_model(),
            "input" => [
                ["role" => "system", "content" => [["type" => "input_text", "text" => $system]]],
                ["role" => "user", "content" => [["type" => "input_text", "text" => $user]]]
            ],
            "text" => [
                "format" => [
                    "type" => "json_schema",
                    "name" => "summary_review_helper",
                    "schema" => $schema,
                    "strict" => true
                ]
            ],
            "temperature" => 0.2
        ]);

        $j = cw_openai_extract_json_text($resp);

        return [
            'what_changed' => trim((string)($j['what_changed'] ?? '')),
            'addressed_points' => trim((string)($j['addressed_points'] ?? '')),
            'suggested_recommendation' => trim((string)($j['suggested_recommendation'] ?? ''))
        ];
    } catch (Throwable $e) {
        return [
            'what_changed' => 'AI helper unavailable.',
            'addressed_points' => 'AI helper unavailable.',
            'suggested_recommendation' => 'manual review required'
        ];
    }
}

$aiHelper = build_ai_helper($pdo, $summary, $latestAction);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $decision = trim((string)($_POST['summary_decision'] ?? ''));
    $reviewNotes = trim((string)($_POST['review_notes_by_instructor'] ?? ''));

    if (!in_array($decision, ['acceptable', 'needs_revision'], true)) {
        $error = 'Invalid summary decision.';
    } else {
        try {
            $pdo->beginTransaction();

            $upd = $pdo->prepare("
                UPDATE lesson_summaries
                SET
                    last_reviewed_summary_html = summary_html,
                    last_reviewed_summary_plain = summary_plain,
                    review_status = ?,
                    review_feedback = ?,
                    review_notes_by_instructor = ?,
                    reviewed_at = NOW(),
                    reviewed_by_user_id = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $upd->execute([
                $decision,
                $reviewNotes,
                $reviewNotes,
                $userId,
                (int)$summary['id']
            ]);

            $completionStatus = ($decision === 'acceptable')
                ? 'in_progress'
                : 'awaiting_summary_review';

            $actUpd = $pdo->prepare("
                UPDATE lesson_activity
                SET
                    completion_status = ?,
                    last_state_eval_at = NOW(),
                    updated_at = NOW()
                WHERE user_id = ?
                  AND cohort_id = ?
                  AND lesson_id = ?
            ");
            $actUpd->execute([
                $completionStatus,
                $studentId,
                $cohortId,
                $lessonId
            ]);

			$engine->logProgressionEvent([
                'user_id' => $studentId,
                'cohort_id' => $cohortId,
                'lesson_id' => $lessonId,
                'event_type' => 'summary_review',
                'event_code' => ($decision === 'acceptable') ? 'summary_reapproved' : 'summary_needs_further_revision',
                'event_status' => 'warning',
                'actor_type' => 'admin',
                'actor_user_id' => $userId,
                'payload' => [
                    'summary_id' => (int)$summary['id'],
                    'summary_decision' => $decision
                ],
                'legal_note' => 'Instructor reviewed revised lesson summary.'
            ]);

            $studentRecipient = $engine->getUserRecipient($studentId);

            $pdo->commit();

            if ($decision === 'needs_revision' && $studentRecipient !== null) {
                $studentName = trim((string)($studentRecipient['name'] ?? 'Student'));
                if ($studentName === '') {
                    $studentName = 'Student';
                }

                $subject = 'Summary Revision Required - ' . $lessonTitle;

                $html = ''
                    . '<p>Dear ' . h3($studentName) . ',</p>'
                    . '<p>Your instructor has reviewed your revised summary for <strong>' . h3($lessonTitle) . '</strong> in <strong>' . h3($cohortTitle) . '</strong>.</p>'
                    . '<p><strong>Decision:</strong> Summary needs further revision.</p>'
                    . '<p><strong>Instructor feedback:</strong><br>' . nl2br(h3($reviewNotes)) . '</p>'
                    . '<p>Please reopen the lesson, update your summary, and save your changes. Once updated, your summary will return to pending review automatically.</p>'
                    . '<p>Kind regards,<br>Chief Training Team<br>IPCA Courseware</p>';

                $text = ''
                    . "Dear {$studentName},\n\n"
                    . "Your instructor has reviewed your revised summary for {$lessonTitle} in {$cohortTitle}.\n\n"
                    . "Decision: Summary needs further revision.\n\n"
                    . "Instructor feedback:\n{$reviewNotes}\n\n"
                    . "Please reopen the lesson, update your summary, and save your changes. Once updated, your summary will return to pending review automatically.\n\n"
                    . "Kind regards,\nChief Training Team\nIPCA Courseware";

                $emailId = $engine->queueProgressionEmail([
                    'user_id' => $studentId,
                    'cohort_id' => $cohortId,
                    'lesson_id' => $lessonId,
                    'email_type' => 'instructor_summary_revision_required',
                    'recipients_to' => [[
                        'email' => (string)$studentRecipient['email'],
                        'name' => $studentName
                    ]],
                    'recipients_cc' => [],
                    'subject' => $subject,
                    'body_html' => $html,
                    'body_text' => $text,
                    'ai_inputs' => [
                        'trigger' => 'instructor_summary_revision_required',
                        'lesson_title' => $lessonTitle,
                        'cohort_title' => $cohortTitle,
                        'review_notes' => $reviewNotes
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

            $success = ($decision === 'acceptable')
                ? 'Summary approved successfully.'
                : 'Summary marked as needs further revision.';

            $sumSt->execute([$studentId, $cohortId, $lessonId]);
            $summary = $sumSt->fetch(PDO::FETCH_ASSOC);
            $aiHelper = build_ai_helper($pdo, $summary, $latestAction);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

cw_header('Summary Review');
?>

<div class="card" style="max-width:1100px;margin:24px auto;">
    <h1>Summary Review</h1>

    <p><strong>Student:</strong> <?= h3((string)$student['name']) ?></p>
    <p><strong>Lesson:</strong> <?= h3($lessonTitle) ?></p>
    <p><strong>Cohort:</strong> <?= h3($cohortTitle) ?></p>
    <p><strong>Current review status:</strong> <?= h3((string)$summary['review_status']) ?></p>

    <?php if ($error !== ''): ?>
        <div style="margin-top:20px;padding:14px;border-radius:10px;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;">
            <?= h3($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div style="margin-top:20px;padding:14px;border-radius:10px;background:#dcfce7;border:1px solid #86efac;color:#166534;">
            <?= h3($success) ?>
        </div>
    <?php endif; ?>

 <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px;">
        <div style="padding:16px;border:1px solid #ddd;border-radius:10px;background:#fff;">
            <h3>Current Summary</h3>
            <div style="border:1px solid #eee;border-radius:10px;padding:12px;min-height:180px;background:#fafafa;overflow:auto;">
                <?= (string)($summary['summary_html'] ?? '') ?>
            </div>
        </div>

        <div style="padding:16px;border:1px solid #ddd;border-radius:10px;background:#fff;">
            <h3>Last Reviewed Summary</h3>
            <div style="border:1px solid #eee;border-radius:10px;padding:12px;min-height:180px;background:#fafafa;overflow:auto;">
                <?= (string)($summary['last_reviewed_summary_html'] ?? '') ?>
            </div>
        </div>
    </div>

    <div style="margin-top:20px;padding:16px;border:1px solid #ddd;border-radius:10px;background:#f8fafc;">
        <h3>AI Helper Panel</h3>
        <p><strong>What changed:</strong><br><?= nl2br(h3($aiHelper['what_changed'])) ?></p>
        <p><strong>Did the student address the requested points:</strong><br><?= nl2br(h3($aiHelper['addressed_points'])) ?></p>
        <p><strong>Suggested recommendation:</strong><br><?= nl2br(h3($aiHelper['suggested_recommendation'])) ?></p>
    </div>

    <form method="post" style="margin-top:20px;">
        <div>
            <label><strong>Instructor Notes</strong></label><br>
            <textarea
                name="review_notes_by_instructor"
                rows="6"
                style="margin-top:6px;padding:10px;width:100%;box-sizing:border-box;"
            ><?= h3((string)($summary['review_notes_by_instructor'] ?? '')) ?></textarea>
        </div>

        <div style="margin-top:16px;display:flex;gap:12px;flex-wrap:wrap;">
            <button type="submit" name="summary_decision" value="acceptable" class="btn">Approve Summary</button>
            <button type="submit" name="summary_decision" value="needs_revision" class="btn">Needs Further Revision</button>
        </div>
    </form>
</div>

<?php cw_footer(); ?>