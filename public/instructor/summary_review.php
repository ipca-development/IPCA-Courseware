<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/courseware_progression_v2.php';
require_once __DIR__ . '/../../src/openai.php';

cw_require_login();

$u = cw_current_user($pdo);
$userId = (int)($u['id'] ?? 0);
$role   = (string)($u['role'] ?? '');

$engine = new CoursewareProgressionV2($pdo);

$error = '';
$success = '';

function h3(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$requestContext = [
    'method' => strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
    'get' => $_GET,
    'post' => $_POST,
    'actor_user_id' => $userId,
    'actor_role' => $role,
    'ip_address' => trim((string)($_SERVER['REMOTE_ADDR'] ?? '')),
    'user_agent' => trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
];

$state = null;

if ($requestContext['method'] === 'POST') {
    $decisionPayload = [
        'decision' => trim((string)($_POST['summary_decision'] ?? '')),
        'review_notes' => trim((string)($_POST['review_notes_by_instructor'] ?? '')),
        'allow_ai_helper_context' => 0,
    ];

    try {
        $result = $engine->processInstructorSummaryReviewDecision($requestContext, $decisionPayload);
        $success = trim((string)($result['message'] ?? 'Summary review decision saved.'));
        if (isset($result['state']) && is_array($result['state'])) {
            $state = $result['state'];
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (!is_array($state)) {
    try {
        $state = $engine->getSummaryReviewPageState($requestContext);
    } catch (Throwable $e) {
        http_response_code(500);
        exit($e->getMessage());
    }
}

$pageTitle = trim((string)($state['page_title'] ?? 'Summary Review'));
$pageSubtitle = trim((string)($state['page_subtitle'] ?? ''));
$studentName = trim((string)($state['student_name'] ?? 'Student'));
$lessonTitle = trim((string)($state['lesson_title'] ?? 'Lesson'));
$cohortTitle = trim((string)($state['cohort_title'] ?? 'Cohort'));
$summaryHtml = (string)($state['summary_html'] ?? '');
$summaryText = (string)($state['summary_text'] ?? '');
$summaryStatus = trim((string)($state['summary_status'] ?? ''));
$reviewStatus = trim((string)($state['review_status'] ?? ''));
$canSubmit = !empty($state['can_submit']);
$isReadOnly = !empty($state['is_read_only']);
$backUrl = trim((string)($state['back_url'] ?? ''));
$metaRows = is_array($state['meta_rows'] ?? null) ? $state['meta_rows'] : [];
$decisionOptions = is_array($state['decision_options'] ?? null) ? $state['decision_options'] : [];
$selectedDecision = trim((string)($state['selected_decision'] ?? ''));
$reviewNotes = trim((string)($state['review_notes'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['summary_decision'])) {
        $selectedDecision = trim((string)$_POST['summary_decision']);
    }
    if (isset($_POST['review_notes_by_instructor'])) {
        $reviewNotes = trim((string)$_POST['review_notes_by_instructor']);
    }
}

$lastReviewedSummaryHtml = '';
if (isset($state['last_reviewed_summary_html'])) {
    $lastReviewedSummaryHtml = (string)$state['last_reviewed_summary_html'];
} elseif (isset($state['summary']) && is_array($state['summary']) && isset($state['summary']['last_reviewed_summary_html'])) {
    $lastReviewedSummaryHtml = (string)$state['summary']['last_reviewed_summary_html'];
}

$aiHelper = is_array($state['ai_helper'] ?? null) ? $state['ai_helper'] : [];
$hasDetailedAiHelper =
    array_key_exists('what_changed', $aiHelper) ||
    array_key_exists('addressed_points', $aiHelper) ||
    array_key_exists('suggested_recommendation', $aiHelper);

$aiHelperWhatChanged = trim((string)($aiHelper['what_changed'] ?? ''));
$aiHelperAddressedPoints = trim((string)($aiHelper['addressed_points'] ?? ''));
$aiHelperSuggestedRecommendation = trim((string)($aiHelper['suggested_recommendation'] ?? ''));
$aiHelperMessage = trim((string)($aiHelper['message'] ?? ''));

if (!$decisionOptions) {
    $decisionOptions = [
        ['value' => 'approve', 'label' => 'Approve Summary'],
        ['value' => 'needs_revision', 'label' => 'Needs Further Revision'],
    ];
}

cw_header('Summary Review');
?>

<div class="card" style="max-width:1100px;margin:24px auto;">
    <h1><?= h3($pageTitle !== '' ? $pageTitle : 'Summary Review') ?></h1>

    <?php if ($pageSubtitle !== ''): ?>
        <p><?= h3($pageSubtitle) ?></p>
    <?php endif; ?>

    <p><strong>Student:</strong> <?= h3($studentName) ?></p>
    <p><strong>Lesson:</strong> <?= h3($lessonTitle) ?></p>
    <p><strong>Cohort:</strong> <?= h3($cohortTitle) ?></p>
    <p><strong>Current review status:</strong> <?= h3($reviewStatus !== '' ? $reviewStatus : $summaryStatus) ?></p>

    <?php if ($backUrl !== ''): ?>
        <p style="margin-top:12px;">
            <a href="<?= h3($backUrl) ?>" class="btn">Back</a>
        </p>
    <?php endif; ?>

    <?php if (!empty($metaRows)): ?>
        <div style="margin-top:16px;">
            <?php foreach ($metaRows as $row): ?>
                <?php
                $label = trim((string)($row['label'] ?? ''));
                $value = trim((string)($row['value'] ?? ''));
                if ($label === '' && $value === '') {
                    continue;
                }
                ?>
                <p style="margin:6px 0;"><strong><?= h3($label) ?>:</strong> <?= h3($value) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

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
                <?php if (trim($summaryHtml) !== ''): ?>
                    <?= $summaryHtml ?>
                <?php else: ?>
                    <?= nl2br(h3($summaryText)) ?>
                <?php endif; ?>
            </div>
        </div>

        <div style="padding:16px;border:1px solid #ddd;border-radius:10px;background:#fff;">
            <h3>Last Reviewed Summary</h3>
            <div style="border:1px solid #eee;border-radius:10px;padding:12px;min-height:180px;background:#fafafa;overflow:auto;">
                <?= $lastReviewedSummaryHtml ?>
            </div>
        </div>
    </div>

    <div style="margin-top:20px;padding:16px;border:1px solid #ddd;border-radius:10px;background:#f8fafc;">
        <h3>AI Helper Panel</h3>

        <?php if ($hasDetailedAiHelper): ?>
            <p><strong>What changed:</strong><br><?= nl2br(h3($aiHelperWhatChanged)) ?></p>
            <p><strong>Did the student address the requested points:</strong><br><?= nl2br(h3($aiHelperAddressedPoints)) ?></p>
            <p><strong>Suggested recommendation:</strong><br><?= nl2br(h3($aiHelperSuggestedRecommendation)) ?></p>
        <?php elseif ($aiHelperMessage !== ''): ?>
            <p><?= nl2br(h3($aiHelperMessage)) ?></p>
        <?php else: ?>
            <p><?= h3('AI helper unavailable.') ?></p>
        <?php endif; ?>
    </div>

    <form method="post" style="margin-top:20px;">
        <div>
            <label><strong>Instructor Notes</strong></label><br>
            <textarea
                name="review_notes_by_instructor"
                rows="6"
                style="margin-top:6px;padding:10px;width:100%;box-sizing:border-box;"
                <?= ($isReadOnly || !$canSubmit) ? 'readonly' : '' ?>
            ><?= h3($reviewNotes) ?></textarea>
        </div>

        <div style="margin-top:16px;display:flex;gap:12px;flex-wrap:wrap;">
           <button
    type="submit"
    name="summary_decision"
    value="approve"
    class="btn"
    <?= ($isReadOnly || !$canSubmit) ? 'disabled' : '' ?>
>Approve Summary</button>

            <button
                type="submit"
                name="summary_decision"
                value="needs_revision"
                class="btn"
                <?= ($isReadOnly || !$canSubmit) ? 'disabled' : '' ?>
            >Needs Further Revision</button>
        </div>
    </form>
</div>

<?php cw_footer(); ?>