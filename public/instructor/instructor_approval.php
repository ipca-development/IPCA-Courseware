<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/courseware_progression_v2.php';

cw_require_login();

$u = cw_current_user($pdo);
$userId = (int)($u['id'] ?? 0);
$role = trim((string)($u['role'] ?? ''));

$allowedRoles = ['admin', 'chief_instructor', 'instructor'];
if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('Forbidden');
}

$engine = new CoursewareProgressionV2($pdo);

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
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

function hnl(?string $v): string
{
    return nl2br(h2($v));
}

function yesno2(int $v): string
{
    return $v ? 'Yes' : 'No';
}

function get_client_ip2(): ?string
{
    $keys = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR',
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

function get_user_agent2(): ?string
{
    if (empty($_SERVER['HTTP_USER_AGENT'])) {
        return null;
    }

    return substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 65535);
}

function format_dt_utc(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }

    return $value . ' UTC';
}

function short_text(?string $text, int $maxLen = 220): string
{
    $text = trim((string)$text);
    if ($text === '') {
        return '—';
    }

    $text = preg_replace('/\s+/', ' ', $text);
    if (!is_string($text)) {
        return '—';
    }

    if (mb_strlen($text) <= $maxLen) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, $maxLen - 1)) . '…';
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

    $action = (array)$state['action'];

    if ((string)($action['action_type'] ?? '') !== 'instructor_approval') {
        http_response_code(400);
        exit('Invalid action type');
    }

    if (isset($state['access']) && is_array($state['access']) && array_key_exists('is_allowed', $state['access'])) {
        if (empty($state['access']['is_allowed'])) {
            http_response_code(403);
            exit('Forbidden');
        }
    }

    return $state;
}

function load_attempt_history(PDO $pdo, int $userId, int $cohortId, int $lessonId): array
{
    $sql = "
        SELECT
            id,
            attempt,
            status,
            score_pct,
            formal_result_code,
            formal_result_label,
            counts_as_unsat,
            weak_areas,
            ai_summary,
            completed_at
        FROM progress_tests_v2
        WHERE user_id = :user_id
          AND cohort_id = :cohort_id
          AND lesson_id = :lesson_id
        ORDER BY attempt DESC, id DESC
        LIMIT 10
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':user_id' => $userId,
        ':cohort_id' => $cohortId,
        ':lesson_id' => $lessonId,
    ]);

    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

$state = load_instructor_approval_page_state($engine, $token);
$action = (array)$state['action'];
$activity = (array)($state['activity'] ?? []);
$progressionContext = (array)($state['progression_context'] ?? []);
$latestProgressTest = (array)($state['latest_progress_test'] ?? []);

$actionId = (int)($action['id'] ?? 0);
$actionUserId = (int)($action['user_id'] ?? 0);
$cohortId = (int)($action['cohort_id'] ?? 0);
$lessonId = (int)($action['lesson_id'] ?? 0);
$progressTestId = isset($action['progress_test_id']) ? (int)$action['progress_test_id'] : 0;

$studentName = trim((string)($state['student_name'] ?? ''));
if ($studentName === '') {
    $studentName = trim((string)(($progressionContext['student_recipient']['name'] ?? '') ?: 'Student'));
}

$lessonTitle = trim((string)($state['lesson_title'] ?? ($progressionContext['lesson_title'] ?? '')));
$cohortTitle = trim((string)($state['cohort_title'] ?? ($progressionContext['cohort_title'] ?? '')));

$chiefInstructorName = trim((string)(($progressionContext['chief_instructor_recipient']['name'] ?? '') ?: 'Chief Instructor'));
$chiefInstructorEmail = trim((string)($progressionContext['chief_instructor_recipient']['email'] ?? ''));

$ipAddress = get_client_ip2();
$userAgent = get_user_agent2();

try {
    $engine->markInstructorApprovalPageOpened(
        $actionId,
        (string)($ipAddress ?? ''),
        (string)($userAgent ?? '')
    );
} catch (Throwable $e) {
    error_log('markInstructorApprovalPageOpened failed: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['mark_instructor_session_completed'])) {
            $result = $engine->markInstructorApprovalOneOnOneCompleted(
                $actionId,
                $userId,
                (string)($ipAddress ?? ''),
                (string)($userAgent ?? '')
            );
        } else {
            $decisionCode = trim((string)($_POST['decision_code'] ?? ''));
            $grantedExtraAttempts = (int)($_POST['granted_extra_attempts'] ?? 0);

            $payload = [
                'decision_code' => $decisionCode,
                'granted_extra_attempts' => $grantedExtraAttempts,
                'summary_revision_required' => $decisionCode === 'approve_with_summary_revision' ? 1 : 0,
                'one_on_one_required' => $decisionCode === 'approve_with_one_on_one' ? 1 : 0,
                'training_suspended' => $decisionCode === 'suspend_training' ? 1 : 0,
                'major_intervention_flag' => $decisionCode === 'suspend_training' ? 1 : 0,
                'decision_notes' => trim((string)($_POST['decision_notes'] ?? '')),
            ];

            $result = $engine->processInstructorApprovalDecision(
                $actionId,
                $payload,
                $userId,
                (string)($ipAddress ?? ''),
                (string)($userAgent ?? '')
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
        $progressionContext = (array)($state['progression_context'] ?? []);
        $latestProgressTest = (array)($state['latest_progress_test'] ?? []);

        $studentName = trim((string)($state['student_name'] ?? ''));
        if ($studentName === '') {
            $studentName = trim((string)(($progressionContext['student_recipient']['name'] ?? '') ?: 'Student'));
        }

        $lessonTitle = trim((string)($state['lesson_title'] ?? ($progressionContext['lesson_title'] ?? '')));
        $cohortTitle = trim((string)($state['cohort_title'] ?? ($progressionContext['cohort_title'] ?? '')));
        $chiefInstructorName = trim((string)(($progressionContext['chief_instructor_recipient']['name'] ?? '') ?: 'Chief Instructor'));
        $chiefInstructorEmail = trim((string)($progressionContext['chief_instructor_recipient']['email'] ?? ''));

        $actionId = (int)($action['id'] ?? 0);
        $actionUserId = (int)($action['user_id'] ?? 0);
        $cohortId = (int)($action['cohort_id'] ?? 0);
        $lessonId = (int)($action['lesson_id'] ?? 0);
        $progressTestId = isset($action['progress_test_id']) ? (int)$action['progress_test_id'] : 0;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $state = load_instructor_approval_page_state($engine, $token);
        $action = (array)($state['action'] ?? []);
        $activity = (array)($state['activity'] ?? []);
        $progressionContext = (array)($state['progression_context'] ?? []);
        $latestProgressTest = (array)($state['latest_progress_test'] ?? []);
    }
}

$attemptHistory = load_attempt_history($pdo, $actionUserId, $cohortId, $lessonId);

$currentActionStatus = trim((string)($action['status'] ?? ''));
$currentDecisionCode = trim((string)($action['decision_code'] ?? ''));
$currentDecisionNotes = trim((string)($action['decision_notes'] ?? ''));

$latestScorePct = isset($latestProgressTest['score_pct']) ? (string)$latestProgressTest['score_pct'] : '';
$latestAttemptNo = isset($latestProgressTest['attempt']) ? (string)$latestProgressTest['attempt'] : '';
$latestWeakAreas = trim((string)($latestProgressTest['weak_areas'] ?? ''));
$latestDebrief = trim((string)($latestProgressTest['ai_summary'] ?? ''));

$pageTitle = 'Instructor Approval';
$pageSubtitle = 'Review blocked progression and decide the next training step.';

cw_header($pageTitle);
?>

<style>
.ia-shell{
    max-width:1180px;
    margin:0 auto;
    padding:24px 20px 36px 20px;
}
.ia-pagehead{
    margin-bottom:20px;
}
.ia-kicker{
    display:inline-block;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:800;
    letter-spacing:.04em;
    text-transform:uppercase;
    background:#dbeafe;
    color:#1d4ed8;
    margin-bottom:12px;
}
.ia-title{
    margin:0;
    font-size:30px;
    line-height:1.15;
    font-weight:900;
    color:#0f172a;
}
.ia-subtitle{
    margin:10px 0 0 0;
    color:#64748b;
    font-size:15px;
}
.ia-alert{
    margin:18px 0;
    padding:14px 16px;
    border-radius:14px;
    border:1px solid;
    font-weight:700;
}
.ia-alert.error{
    background:#fef2f2;
    border-color:#fecaca;
    color:#991b1b;
}
.ia-alert.success{
    background:#ecfdf5;
    border-color:#86efac;
    color:#166534;
}
.ia-grid{
    display:grid;
    grid-template-columns:1.2fr .8fr;
    gap:20px;
    align-items:start;
}
.ia-stack{
    display:grid;
    gap:20px;
}
.ia-card{
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:18px;
    box-shadow:0 8px 28px rgba(15,23,42,.05);
    overflow:hidden;
}
.ia-cardhead{
    padding:18px 20px 10px 20px;
    border-bottom:1px solid #eef2f7;
}
.ia-cardtitle{
    margin:0;
    font-size:18px;
    font-weight:800;
    color:#0f172a;
}
.ia-cardsub{
    margin:6px 0 0 0;
    color:#64748b;
    font-size:14px;
}
.ia-cardbody{
    padding:18px 20px 20px 20px;
}
.ia-meta{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:12px 16px;
}
.ia-meta-item{
    padding:12px 14px;
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:14px;
}
.ia-meta-label{
    display:block;
    font-size:12px;
    font-weight:800;
    letter-spacing:.03em;
    text-transform:uppercase;
    color:#64748b;
    margin-bottom:6px;
}
.ia-meta-value{
    font-size:15px;
    font-weight:700;
    color:#0f172a;
    word-break:break-word;
}
.ia-status{
    display:inline-block;
    padding:8px 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    letter-spacing:.03em;
    text-transform:uppercase;
}
.ia-status.pending,
.ia-status.opened{
    background:#fef3c7;
    color:#92400e;
}
.ia-status.approved,
.ia-status.completed{
    background:#dcfce7;
    color:#166534;
}
.ia-status.rejected,
.ia-status.expired{
    background:#fee2e2;
    color:#991b1b;
}
.ia-block{
    border:1px solid #e2e8f0;
    border-radius:14px;
    background:#f8fafc;
    padding:14px 16px;
}
.ia-block + .ia-block{
    margin-top:14px;
}
.ia-block-title{
    margin:0 0 8px 0;
    font-size:14px;
    font-weight:900;
    color:#0f172a;
}
.ia-block-text{
    color:#334155;
    font-size:14px;
    line-height:1.55;
}
.ia-history{
    width:100%;
    border-collapse:collapse;
}
.ia-history th,
.ia-history td{
    text-align:left;
    vertical-align:top;
    padding:12px 10px;
    border-bottom:1px solid #eef2f7;
    font-size:14px;
}
.ia-history th{
    font-size:12px;
    color:#64748b;
    text-transform:uppercase;
    letter-spacing:.03em;
}
.ia-form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:16px;
}
.ia-field{
    display:block;
}
.ia-field.full{
    grid-column:1 / -1;
}
.ia-label{
    display:block;
    margin-bottom:8px;
    font-size:13px;
    font-weight:800;
    color:#0f172a;
}
.ia-help{
    margin-top:6px;
    font-size:12px;
    color:#64748b;
}
.ia-input,
.ia-select,
.ia-textarea{
    width:100%;
    box-sizing:border-box;
    border:1px solid #cbd5e1;
    border-radius:12px;
    padding:12px 14px;
    font:inherit;
    background:#fff;
    color:#0f172a;
}
.ia-textarea{
    min-height:150px;
    resize:vertical;
}
.ia-decision-help{
    margin-top:10px;
    padding:12px 14px;
    border-radius:12px;
    background:#eff6ff;
    border:1px solid #bfdbfe;
    color:#1e3a8a;
    font-size:13px;
    line-height:1.5;
}
.ia-actions{
    display:flex;
    gap:12px;
    flex-wrap:wrap;
    margin-top:18px;
}
.ia-btn{
    appearance:none;
    border:none;
    border-radius:12px;
    padding:12px 18px;
    font-weight:800;
    cursor:pointer;
    text-decoration:none;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}
.ia-btn-primary{
    background:#1e3a8a;
    color:#fff;
}
.ia-btn-primary:hover{
    background:#1d3579;
}
.ia-btn-secondary{
    background:#e2e8f0;
    color:#0f172a;
}
.ia-btn-secondary:hover{
    background:#cbd5e1;
}
.ia-note{
    font-size:13px;
    color:#64748b;
    margin-top:10px;
}
@media (max-width: 980px){
    .ia-grid{
        grid-template-columns:1fr;
    }
    .ia-meta,
    .ia-form-grid{
        grid-template-columns:1fr;
    }
}
</style>

<div class="ia-shell">
    <div class="ia-pagehead">
        <div class="ia-kicker">Instructor Workflow</div>
        <h1 class="ia-title"><?= h2($pageTitle) ?></h1>
        <p class="ia-subtitle"><?= h2($pageSubtitle) ?></p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="ia-alert error"><?= h2($error) ?></div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="ia-alert success"><?= h2($success) ?></div>
    <?php endif; ?>

    <div class="ia-grid">
        <div class="ia-stack">
            <section class="ia-card">
                <div class="ia-cardhead">
                    <h2 class="ia-cardtitle">Review Context</h2>
                    <p class="ia-cardsub">Instructor-only review summary for the blocked lesson progression.</p>
                </div>
                <div class="ia-cardbody">
                    <div class="ia-meta">
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Student</span>
                            <div class="ia-meta-value"><?= h2($studentName) ?></div>
                        </div>
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Lesson</span>
                            <div class="ia-meta-value"><?= h2($lessonTitle) ?></div>
                        </div>
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Cohort</span>
                            <div class="ia-meta-value"><?= h2($cohortTitle) ?></div>
                        </div>
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Chief Instructor</span>
                            <div class="ia-meta-value">
                                <?= h2($chiefInstructorName) ?>
                                <?php if ($chiefInstructorEmail !== ''): ?>
                                    <div style="font-size:12px;color:#64748b;margin-top:4px;"><?= h2($chiefInstructorEmail) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Action Status</span>
                            <div class="ia-meta-value">
                                <span class="ia-status <?= h2($currentActionStatus !== '' ? $currentActionStatus : 'pending') ?>">
                                    <?= h2(strtoupper(str_replace('_', ' ', $currentActionStatus !== '' ? $currentActionStatus : 'pending'))) ?>
                                </span>
                            </div>
                        </div>
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Progress Test ID</span>
                            <div class="ia-meta-value"><?= $progressTestId > 0 ? h2((string)$progressTestId) : '—' ?></div>
                        </div>
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Latest Attempt</span>
                            <div class="ia-meta-value"><?= $latestAttemptNo !== '' ? h2($latestAttemptNo) : '—' ?></div>
                        </div>
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Latest Score</span>
                            <div class="ia-meta-value"><?= $latestScorePct !== '' ? h2($latestScorePct) . '%' : '—' ?></div>
                        </div>
                    </div>

                    <div class="ia-block" style="margin-top:18px;">
                        <h3 class="ia-block-title">Latest Review Areas</h3>
                        <div class="ia-block-text"><?= $latestWeakAreas !== '' ? hnl($latestWeakAreas) : 'No weak areas stored.' ?></div>
                    </div>

                    <div class="ia-block">
                        <h3 class="ia-block-title">Latest Debrief Summary</h3>
                        <div class="ia-block-text"><?= $latestDebrief !== '' ? hnl($latestDebrief) : 'No debrief summary stored.' ?></div>
                    </div>
                </div>
            </section>

            <section class="ia-card">
                <div class="ia-cardhead">
                    <h2 class="ia-cardtitle">Recent Attempt History</h2>
                    <p class="ia-cardsub">Most recent progress test results for this lesson.</p>
                </div>
                <div class="ia-cardbody" style="padding-top:8px;">
                    <?php if (!$attemptHistory): ?>
                        <div class="ia-note">No attempt history found.</div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="ia-history">
                                <thead>
                                    <tr>
                                        <th>Attempt</th>
                                        <th>Score</th>
                                        <th>Result</th>
                                        <th>Completed</th>
                                        <th>Main Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($attemptHistory as $row): ?>
                                        <tr>
                                            <td><?= h2((string)($row['attempt'] ?? '')) ?></td>
                                            <td><?= isset($row['score_pct']) && $row['score_pct'] !== null ? h2((string)$row['score_pct']) . '%' : '—' ?></td>
                                            <td>
                                                <?= h2((string)(($row['formal_result_code'] ?? '') ?: ($row['formal_result_label'] ?? '') ?: ($row['status'] ?? ''))) ?>
                                            </td>
                                            <td><?= h2(format_dt_utc((string)($row['completed_at'] ?? ''))) ?></td>
                                            <td><?= h2(short_text((string)(($row['weak_areas'] ?? '') ?: ($row['ai_summary'] ?? '')))) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="ia-stack">
            <section class="ia-card">
                <div class="ia-cardhead">
                    <h2 class="ia-cardtitle">Current Action State</h2>
                    <p class="ia-cardsub">Canonical state of this instructor approval action.</p>
                </div>
                <div class="ia-cardbody">
                    <div class="ia-meta" style="grid-template-columns:1fr;">
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Decision Code</span>
                            <div class="ia-meta-value"><?= $currentDecisionCode !== '' ? h2($currentDecisionCode) : '—' ?></div>
                        </div>
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Granted Extra Attempts</span>
                            <div class="ia-meta-value"><?= h2((string)($action['granted_extra_attempts'] ?? 0)) ?></div>
                        </div>
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Summary Revision Required</span>
                            <div class="ia-meta-value"><?= h2(yesno2((int)($action['summary_revision_required'] ?? 0))) ?></div>
                        </div>
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">One-on-One Required</span>
                            <div class="ia-meta-value"><?= h2(yesno2((int)($action['one_on_one_required'] ?? 0))) ?></div>
                        </div>
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Training Suspended</span>
                            <div class="ia-meta-value"><?= h2(yesno2((int)($action['training_suspended'] ?? 0))) ?></div>
                        </div>
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Major Intervention Flag</span>
                            <div class="ia-meta-value"><?= h2(yesno2((int)($action['major_intervention_flag'] ?? 0))) ?></div>
                        </div>
                        <div class="ia-meta-item">
                            <span class="ia-meta-label">Lesson Completion Status</span>
                            <div class="ia-meta-value"><?= h2((string)(($activity['completion_status'] ?? '') ?: '—')) ?></div>
                        </div>
                    </div>

                    <div class="ia-block" style="margin-top:18px;">
                        <h3 class="ia-block-title">Instructor Notes</h3>
                        <div class="ia-block-text"><?= $currentDecisionNotes !== '' ? hnl($currentDecisionNotes) : 'No instructor notes recorded yet.' ?></div>
                    </div>
                </div>
            </section>

            <?php if ((string)($action['status'] ?? '') !== 'approved'): ?>
                <section class="ia-card">
                    <div class="ia-cardhead">
                        <h2 class="ia-cardtitle">Record Instructor Decision</h2>
                        <p class="ia-cardsub">Choose the next operational path for this student.</p>
                    </div>
                    <div class="ia-cardbody">
                        <form method="post" action="">
                            <input type="hidden" name="token" value="<?= h2($token) ?>">

                            <div class="ia-form-grid">
                                <div class="ia-field full">
                                    <label class="ia-label" for="decision_code">Decision</label>
                                    <select class="ia-select" name="decision_code" id="decision_code" required>
                                        <option value="">Select a decision</option>
                                        <option value="approve_additional_attempts">Grant additional attempts</option>
                                        <option value="approve_with_summary_revision">Require summary revision before retry</option>
                                        <option value="approve_with_one_on_one">Require one-on-one instructor session</option>
                                        <option value="suspend_training">Suspend training pending major review</option>
                                    </select>
                                    <div class="ia-decision-help">
                                        <strong>Decision guide:</strong><br>
                                        Grant additional attempts = student may continue after approval.<br>
                                        Require summary revision = student must revise the lesson summary before continuing.<br>
                                        Require one-on-one instructor session = student must complete an instructor session before continuing.<br>
                                        Suspend training = stop further progression pending higher-level intervention.
                                    </div>
                                </div>

                                <div class="ia-field">
                                    <label class="ia-label" for="granted_extra_attempts">Extra attempts to grant</label>
                                    <input
                                        class="ia-input"
                                        type="number"
                                        name="granted_extra_attempts"
                                        id="granted_extra_attempts"
                                        min="0"
                                        max="5"
                                        step="1"
                                        value="0"
                                    >
                                    <div class="ia-help">Use this when granting additional attempts. Leave at 0 for the other paths.</div>
                                </div>

                                <div class="ia-field full">
                                    <label class="ia-label" for="decision_notes">Instructor decision notes</label>
                                    <textarea
                                        class="ia-textarea"
                                        name="decision_notes"
                                        id="decision_notes"
                                        required
                                        placeholder="Explain why this decision was made, what the student must do next, and any operational notes for audit/history."
                                    ></textarea>
                                    <div class="ia-help">These notes become part of the audit trail.</div>
                                </div>
                            </div>

                            <div class="ia-actions">
                                <button type="submit" class="ia-btn ia-btn-primary">Save Instructor Decision</button>
                            </div>
                        </form>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (
                (string)($action['status'] ?? '') === 'approved' &&
                (int)($action['one_on_one_required'] ?? 0) === 1 &&
                (int)($activity['one_on_one_completed'] ?? 0) !== 1 &&
                (int)($action['training_suspended'] ?? 0) !== 1
            ): ?>
                <section class="ia-card">
                    <div class="ia-cardhead">
                        <h2 class="ia-cardtitle">One-on-One Session</h2>
                        <p class="ia-cardsub">Confirm completion of the required instructor session.</p>
                    </div>
                    <div class="ia-cardbody">
                        <form method="post" action="">
                            <input type="hidden" name="token" value="<?= h2($token) ?>">
                            <input type="hidden" name="mark_instructor_session_completed" value="1">

                            <div class="ia-block">
                                <h3 class="ia-block-title">Pending requirement</h3>
                                <div class="ia-block-text">
                                    This student is currently blocked until the required one-on-one instructor session is completed and recorded.
                                </div>
                            </div>

                            <div class="ia-actions">
                                <button type="submit" class="ia-btn ia-btn-primary">Mark Instructor Session Completed</button>
                            </div>
                        </form>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php cw_footer(); ?>