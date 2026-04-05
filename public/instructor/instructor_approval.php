<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/courseware_progression_v2.php';

cw_require_login();

$u = cw_current_user($pdo);
$userId = (int)($u['id'] ?? 0);
$role = trim((string)($u['role'] ?? ''));

$allowedRoles = ['admin', 'supervisor', 'chief_instructor', 'instructor'];
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

function get_client_ip2(): ?string
{
    $keys = array(
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR',
    );

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

function fmt_dt_localish(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }

    try {
        $dt = new DateTime($value);
        return $dt->format('M j, Y · H:i');
    } catch (Throwable $e) {
        return $value;
    }
}

function short_text2(?string $text, int $maxLen = 220): string
{
    $text = trim((string)$text);
    if ($text === '') {
        return '—';
    }

    $text = preg_replace('/\s+/', ' ', $text);
    if (!is_string($text)) {
        return '—';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text) <= $maxLen) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $maxLen - 1)) . '…';
    }

    if (strlen($text) <= $maxLen) {
        return $text;
    }

    return rtrim(substr($text, 0, $maxLen - 1)) . '…';
}

function parse_list_lines(?string $text): array
{
    $text = trim((string)$text);
    if ($text === '') {
        return array();
    }

    $lines = preg_split('/\r\n|\r|\n/', $text);
    $out = array();

    foreach ((array)$lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }

        $line = preg_replace('/^\s*[-•\*\d\.\)\(]+\s*/', '', $line);
        $line = trim((string)$line);

        if ($line !== '') {
            $out[] = $line;
        }
    }

    return $out;
}

function render_bullet_lines(array $items): string
{
    if (!$items) {
        return '<div class="status-text">—</div>';
    }

    $html = '<ul class="ia-bullets">';
    foreach ($items as $item) {
        $html .= '<li>' . h2((string)$item) . '</li>';
    }
    $html .= '</ul>';

    return $html;
}

function normalize_audio_url(?string $audioPath): string
{
    $audioPath = trim((string)$audioPath);
    if ($audioPath === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $audioPath)) {
        return $audioPath;
    }

    $audioPath = ltrim($audioPath, '/');
    return 'https://ipca-media.nyc3.cdn.digitaloceanspaces.com/' . $audioPath;
}

function load_instructor_approval_page_state(
    CoursewareProgressionV2 $engine,
    string $token,
    string $role
): array {
    if (!in_array($role, array('admin', 'supervisor', 'instructor', 'chief_instructor'), true)) {
        http_response_code(403);
        exit('Forbidden');
    }

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

    return $state;
}

function fetch_user_basic(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return array();
    }

    $st = $pdo->prepare("
        SELECT id, name, first_name, last_name, email, photo_path, role
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $st->execute(array($userId));
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : array();
}

function fetch_chief_instructor_user(PDO $pdo, array $progressionContext): array
{
    $chiefEmail = trim((string)($progressionContext['chief_instructor_recipient']['email'] ?? ''));
    $chiefName  = trim((string)($progressionContext['chief_instructor_recipient']['name'] ?? ''));

    if ($chiefEmail !== '') {
        $st = $pdo->prepare("
            SELECT id, name, first_name, last_name, email, photo_path, role
            FROM users
            WHERE email = ?
            LIMIT 1
        ");
        $st->execute(array($chiefEmail));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }
    }

    if ($chiefName !== '') {
        $st = $pdo->prepare("
            SELECT id, name, first_name, last_name, email, photo_path, role
            FROM users
            WHERE name = ?
            LIMIT 1
        ");
        $st->execute(array($chiefName));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }
    }

    return array();
}

function load_attempt_history2(PDO $pdo, int $userId, int $cohortId, int $lessonId): array
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
    $stmt->execute(array(
        ':user_id' => $userId,
        ':cohort_id' => $cohortId,
        ':lesson_id' => $lessonId,
    ));

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : array();
}

function load_progress_test_items2(PDO $pdo, int $testId): array
{
    if ($testId <= 0) {
        return array();
    }

    $sql = "
        SELECT
            id,
            idx,
            kind,
            prompt,
            transcript_text,
            audio_path,
            is_correct,
            score_points,
            max_points,
            correct_json
        FROM progress_test_items_v2
        WHERE test_id = :test_id
        ORDER BY idx ASC, id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':test_id' => $testId));

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : array();
}

function load_lesson_summary2(PDO $pdo, int $userId, int $cohortId, int $lessonId): array
{
    $sql = "
        SELECT
            id,
            summary_html,
            summary_plain,
            review_status,
            student_soft_locked,
            review_score,
            review_feedback,
            review_notes_by_instructor,
            updated_at
        FROM lesson_summaries
        WHERE user_id = :user_id
          AND cohort_id = :cohort_id
          AND lesson_id = :lesson_id
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(
        ':user_id' => $userId,
        ':cohort_id' => $cohortId,
        ':lesson_id' => $lessonId,
    ));

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : array();
}

function load_official_references2(PDO $pdo, int $lessonId): array
{
    if ($lessonId <= 0) {
        return array();
    }

    $sql = "
        SELECT DISTINCT
            sr.ref_type,
            sr.ref_code,
            sr.ref_title,
            sr.ref_detail,
            sr.notes,
            sr.confidence
        FROM slides s
        INNER JOIN slide_references sr ON sr.slide_id = s.id
        WHERE s.lesson_id = :lesson_id
          AND s.is_deleted = 0
        ORDER BY
            FIELD(sr.ref_type, 'ACS', 'PHAK', 'FAR_AIM', 'EASA'),
            sr.ref_title ASC,
            sr.ref_code ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':lesson_id' => $lessonId));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : array();
}

function build_difficulty_summary2(array $attemptHistory, array $latestProgressTest, array $summaryRow): array
{
    $bucket = array();

    foreach ($attemptHistory as $row) {
        $weakAreas = trim((string)($row['weak_areas'] ?? ''));
        $aiSummary = trim((string)($row['ai_summary'] ?? ''));

        $lines = array_merge(
            parse_list_lines($weakAreas),
            parse_list_lines($aiSummary)
        );

        foreach ($lines as $line) {
            $normalized = strtolower(trim((string)$line));
            if ($normalized === '') {
                continue;
            }

            if (!isset($bucket[$normalized])) {
                $bucket[$normalized] = array(
                    'text' => $line,
                    'count' => 0,
                );
            }

            $bucket[$normalized]['count']++;
        }
    }

    if (!$bucket) {
        $fallback = trim((string)($latestProgressTest['weak_areas'] ?? ''));
        if ($fallback !== '') {
            return parse_list_lines($fallback);
        }

        $reviewFeedback = trim((string)($summaryRow['review_feedback'] ?? ''));
        if ($reviewFeedback !== '') {
            return parse_list_lines($reviewFeedback);
        }

        return array('No recurring difficulty pattern could be derived yet from stored attempts.');
    }

    uasort($bucket, function ($a, $b) {
        $ac = (int)($a['count'] ?? 0);
        $bc = (int)($b['count'] ?? 0);

        if ($ac === $bc) {
            return strcmp((string)$a['text'], (string)$b['text']);
        }

        return ($ac > $bc) ? -1 : 1;
    });

    $items = array();
    foreach ($bucket as $row) {
        $text = trim((string)($row['text'] ?? ''));
        $count = (int)($row['count'] ?? 0);

        if ($text === '') {
            continue;
        }

        if ($count >= 2) {
            $items[] = $text . ' Recurred across ' . $count . ' attempts.';
        } else {
            $items[] = $text;
        }

        if (count($items) >= 6) {
            break;
        }
    }

    return $items;
}
function build_recommendations2(array $difficultyItems, array $latestProgressTest): array
{
    $score = isset($latestProgressTest['score_pct']) ? (int)$latestProgressTest['score_pct'] : 0;

    $recs = array();

    $focus = $difficultyItems ? $difficultyItems[0] : 'core control relationships and cause-effect understanding';

    $recs[] = array(
        'title' => 'Targeted re-teach with active recall',
        'why'   => 'Repeated difficulty indicates conceptual gaps rather than memorization issues.',
        'how'   => 'Revisit the lesson focusing specifically on: ' . $focus . '. Have the student explain each concept out loud in a full cause → effect chain (input → surface → force → aircraft response). Use correction immediately when direction or terminology is wrong.'
    );

    $recs[] = array(
        'title' => 'Instructor-led micro oral check',
        'why'   => 'Low-to-mid scores suggest partial understanding but inconsistent recall under questioning.',
        'how'   => 'Conduct a short 5–10 minute oral focused only on weak areas. Ask the same concept from multiple angles. Require precise phrasing. Do not progress until answers are consistent without hesitation.'
    );

    $recs[] = array(
        'title' => 'Visual + kinesthetic reinforcement',
        'why'   => 'Directional errors often improve when visualized or physically demonstrated.',
        'how'   => 'Use hand demonstrations or cockpit controls to simulate aileron, elevator, and rudder movements. Link movement physically to airflow direction and resulting aircraft motion.'
    );

    if ($score < 40) {
        array_unshift($recs, array(
            'title' => 'Full lesson reset (recommended)',
            'why'   => 'Score indicates foundational misunderstanding.',
            'how'   => 'Restart the lesson from the beginning, then immediately follow with guided oral questioning before allowing another test attempt.'
        ));
    }

    return $recs;
}

function build_motivation_score2(array $attemptHistory, array $summaryRow): array
{
    $attemptCount = count($attemptHistory);
    $latestScore = isset($attemptHistory[0]['score_pct']) ? (int)$attemptHistory[0]['score_pct'] : 0;

    $improvement = 0;
    if ($attemptCount >= 2) {
        $prev = isset($attemptHistory[1]['score_pct']) ? (int)$attemptHistory[1]['score_pct'] : 0;
        $improvement = $latestScore - $prev;
    }

    $summaryScore = isset($summaryRow['review_score']) ? (int)$summaryRow['review_score'] : 0;

    $motivation = 50;

    if ($improvement > 10) $motivation += 15;
    if ($latestScore > 70) $motivation += 10;
    if ($summaryScore > 75) $motivation += 10;
    if ($latestScore < 30) $motivation -= 10;
    if ($attemptCount >= 5) $motivation -= 5;

    if ($motivation < 0) $motivation = 0;
    if ($motivation > 100) $motivation = 100;

    $label = 'On Track';
    if ($motivation >= 80) $label = 'Highly Engaged';
    elseif ($motivation >= 60) $label = 'Good Engagement';
    elseif ($motivation >= 40) $label = 'Stable';
    else $label = 'Needs Attention';

    return array(
        'score' => $motivation,
        'label' => $label
    );
}

$state = load_instructor_approval_page_state($engine, $token, $role);

$action = (array)$state['action'];
$activity = (array)($state['activity'] ?? array());
$progressionContext = (array)($state['progression_context'] ?? array());
$latestProgressTest = (array)($state['latest_progress_test'] ?? array());

$actionId = (int)($action['id'] ?? 0);
$actionUserId = (int)($action['user_id'] ?? 0);
$cohortId = (int)($action['cohort_id'] ?? 0);
$lessonId = (int)($action['lesson_id'] ?? 0);
$progressTestId = isset($action['progress_test_id']) ? (int)$action['progress_test_id'] : 0;

$studentUser = fetch_user_basic($pdo, $actionUserId);
$chiefUser = fetch_chief_instructor_user($pdo, $progressionContext);

$studentName = trim((string)($studentUser['name'] ?? 'Student'));
$lessonTitle = trim((string)($state['lesson_title'] ?? ($progressionContext['lesson_title'] ?? '')));
$cohortTitle = trim((string)($state['cohort_title'] ?? ($progressionContext['cohort_title'] ?? '')));

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
            $payload = array(
                'decision_code' => $decisionCode,
                'granted_extra_attempts' => (int)($_POST['granted_extra_attempts'] ?? 0),
                'summary_revision_required' => $decisionCode === 'approve_with_summary_revision' ? 1 : 0,
                'one_on_one_required' => $decisionCode === 'approve_with_one_on_one' ? 1 : 0,
                'training_suspended' => $decisionCode === 'suspend_training' ? 1 : 0,
                'major_intervention_flag' => $decisionCode === 'suspend_training' ? 1 : 0,
                'decision_notes' => trim((string)($_POST['decision_notes'] ?? '')),
            );

            $result = $engine->processInstructorApprovalDecision(
                $actionId,
                $payload,
                $userId,
                (string)($ipAddress ?? ''),
                (string)($userAgent ?? '')
            );
        }

        $success = trim((string)($result['message'] ?? 'Action saved'));

        $state = load_instructor_approval_page_state($engine, $token, $role);
        $action = (array)$state['action'];
        $activity = (array)($state['activity'] ?? array());
        $latestProgressTest = (array)($state['latest_progress_test'] ?? array());

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$attemptHistory = load_attempt_history2($pdo, $actionUserId, $cohortId, $lessonId);
$progressItems = load_progress_test_items2($pdo, $progressTestId);
$summaryRow = load_lesson_summary2($pdo, $actionUserId, $cohortId, $lessonId);
$references = load_official_references2($pdo, $lessonId);

$difficultyItems = build_difficulty_summary2($attemptHistory, $latestProgressTest, $summaryRow);
$recommendations = build_recommendations2($difficultyItems, $latestProgressTest);
$motivation = build_motivation_score2($attemptHistory, $summaryRow);

cw_header('Instructor Approval');
?>
<style>
.course-page-stack{display:flex;flex-direction:column;gap:20px}
.hero-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:16px}
.hero-card{padding:24px 26px}
.hero-eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.14em;color:#5f6f88;font-weight:700;margin-bottom:10px}
.hero-title{margin:0;font-size:32px;line-height:1.02;letter-spacing:-0.04em;color:#152235;font-weight:800}
.hero-sub{margin-top:12px;font-size:15px;color:#56677f;max-width:920px;line-height:1.55}
.hero-meta{margin-top:14px;font-size:14px;color:#495a72;line-height:1.6}

.top-grid{display:grid;grid-template-columns:repeat(3,minmax(220px,1fr));gap:16px}
.overview-card{padding:20px 22px}
.overview-title{font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:#60718b;font-weight:700;margin-bottom:14px}
.overview-main{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}
.overview-big{font-size:38px;line-height:1;font-weight:800;letter-spacing:-0.04em;color:#152235;white-space:nowrap}
.overview-sub{color:#5b6d85;font-size:14px;line-height:1.45;max-width:260px}
.progress-shell{width:100%;height:11px;border-radius:999px;overflow:hidden;background:#e7edf4}
.progress-fill{height:11px;border-radius:999px;background:linear-gradient(90deg,#102845 0%, #214d91 100%)}
.smallmuted{font-size:12px;color:#5f7088;margin-top:8px;line-height:1.45}

.section-card{padding:20px 22px}
.section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:14px}
.section-title{margin:0;font-size:22px;line-height:1.05;letter-spacing:-0.02em;color:#152235}
.section-sub{margin-top:6px;font-size:14px;color:#56677f;line-height:1.45}
.count-pill{display:inline-block;padding:7px 11px;border-radius:999px;background:#edf4ff;color:#1d4f91;font-size:12px;font-weight:800;border:1px solid #d3e3ff;white-space:nowrap}

.ia-avatar-row{display:flex;gap:14px;align-items:center}
.ip-avatar{width:84px;height:84px;border-radius:24px;overflow:hidden;flex:0 0 84px;background:linear-gradient(180deg,#e8eef7 0%,#dfe7f2 100%);border:1px solid rgba(15,23,42,0.07);display:flex;align-items:center;justify-content:center;box-shadow:inset 0 1px 0 rgba(255,255,255,0.45)}
.ip-avatar img{width:100%;height:100%;object-fit:cover;display:block}
.ip-avatar-fallback{width:34px;height:34px;color:#7b8aa0;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:800}
.ia-person-meta{min-width:0}
.ia-person-name{font-size:20px;font-weight:800;color:#152235;line-height:1.08;letter-spacing:-0.02em}
.ia-person-sub{margin-top:6px;font-size:12px;color:#5b6d85;line-height:1.35}

.two-col-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:16px}
.ia-stack{display:flex;flex-direction:column;gap:16px}

.ia-copy-list{
  margin:0;
  padding-left:18px;
  font-size:12px;
  color:#32465f;
  line-height:1.55;
  white-space:pre-wrap;
  word-break:break-word;
}
.ia-copy-list li{margin-bottom:6px}

.ia-body-text{
  font-size:12px;
  color:#32465f;
  line-height:1.55;
  white-space:pre-wrap;
  word-break:break-word;
}

.ia-reco-list{
  margin:0;
  padding-left:18px;
  font-size:12px;
  color:#32465f;
  line-height:1.55;
}
.ia-reco-list li{margin-bottom:10px}
.ia-reco-list strong{color:#152235}

.state-pill{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border-radius:999px;
  padding:4px 8px;
  font-size:10px;
  font-weight:800;
  line-height:1.2;
  border:1px solid transparent;
  white-space:nowrap;
}
.state-pill.ok{background:#dcfce7;border-color:#86efac;color:#166534}
.state-pill.danger{background:#fee2e2;border-color:#fca5a5;color:#991b1b}
.state-pill.warn{background:#fef3c7;border-color:#fde68a;color:#92400e}
.state-pill.info{background:#dbeafe;border-color:#93c5fd;color:#1d4ed8}
.state-pill.neutral{background:#edf2f7;border-color:#d7dee9;color:#475569}

.action-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:30px;
  padding:0 9px;
  border-radius:9px;
  text-decoration:none;
  font-size:10px;
  font-weight:800;
  white-space:nowrap;
  border:1px solid rgba(15,23,42,0.08);
  color:#152235;
  background:#f4f7fb;
  letter-spacing:.01em;
  cursor:pointer;
}
.action-btn.primary{background:#12355f;color:#fff;border-color:#12355f}
.action-btn.warn{background:#fff7ed;color:#92400e;border-color:#fed7aa}
.action-btn.danger{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.action-btn:hover{opacity:.95}
.action-btn.disabled{opacity:.45;pointer-events:none}

.ia-state-grid{display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:12px}
.ia-state-box{
  padding:12px 14px;
  background:#f8fafc;
  border:1px solid #e2e8f0;
  border-radius:14px;
}
.ia-state-label{
  display:block;
  font-size:10px;
  color:#5f718d;
  margin-bottom:5px;
  font-weight:700;
  text-transform:uppercase;
  letter-spacing:.13em;
}
.ia-state-value{
  font-size:13px;
  font-weight:700;
  color:#152235;
  line-height:1.4;
}

.lesson-table-wrap{overflow:visible}
.lesson-table{width:100%;border-collapse:collapse;table-layout:fixed}
.lesson-table thead th{
  padding:11px 8px;
  border-bottom:1px solid rgba(15,23,42,0.07);
  vertical-align:middle;
  text-align:left;
  font-size:10px;
  text-transform:uppercase;
  letter-spacing:.13em;
  color:#60718b;
  font-weight:700;
  white-space:nowrap;
}
.lesson-table tbody td{
  padding:9px 8px;
  border-bottom:1px solid rgba(15,23,42,0.06);
  vertical-align:middle;
  text-align:left;
}
.lesson-table thead th:first-child,
.lesson-table tbody td:first-child{padding-left:15px}
.lesson-table thead th:last-child,
.lesson-table tbody td:last-child{padding-right:15px}
.lesson-table tbody tr:last-child td{border-bottom:0}
.th-center,.td-center{text-align:center !important}

.summary-bar-shell{width:100%;height:6px;border-radius:999px;overflow:hidden;background:#e7edf4}
.summary-bar-fill{height:6px;border-radius:999px}
.summary-bar-fill.ok{background:linear-gradient(90deg,#166534 0%, #22c55e 100%)}
.summary-bar-fill.warn{background:linear-gradient(90deg,#b45309 0%, #f59e0b 100%)}
.summary-bar-fill.danger{background:linear-gradient(90deg,#b91c1c 0%, #ef4444 100%)}
.summary-bar-fill.info{background:linear-gradient(90deg,#1d4f91 0%, #3b82f6 100%)}
.summary-bar-fill.neutral{background:linear-gradient(90deg,#64748b 0%, #94a3b8 100%)}
.summary-label{font-size:10px;font-weight:800;margin-top:4px;line-height:1.15}
.summary-label.ok{color:#166534}
.summary-label.warn{color:#b45309}
.summary-label.danger{color:#b91c1c}
.summary-label.info{color:#1d4f91}
.summary-label.neutral{color:#4b5563}

.ia-form-grid{display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:16px}
.ia-field.full{grid-column:1 / -1}
.ia-label{display:block;margin-bottom:8px;font-size:13px;font-weight:800;color:#0f172a}
.ia-input,.ia-select,.ia-textarea{
  width:100%;
  box-sizing:border-box;
  border:1px solid #cbd5e1;
  border-radius:12px;
  padding:12px 14px;
  font:inherit;
  background:#fff;
  color:#0f172a;
}
.ia-textarea{min-height:150px;resize:vertical}
.ia-help{margin-top:6px;font-size:12px;color:#64748b}
.ia-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:18px}

.ia-guide-list{
  margin:10px 0 0 0;
  padding-left:18px;
  font-size:13px;
  color:#1e3a8a;
  line-height:1.55;
}
.ia-guide-list li{margin-bottom:6px}

.ia-modal{
  position:fixed;
  inset:0;
  background:rgba(15,23,42,.56);
  z-index:9999;
  display:none;
  align-items:center;
  justify-content:center;
  padding:18px;
}
.ia-modal.is-open{display:flex}
.ia-modal-card{
  width:min(1080px,100%);
  max-height:90vh;
  overflow:auto;
  background:#fff;
  border-radius:18px;
  box-shadow:0 24px 60px rgba(15,23,42,.28);
  border:1px solid rgba(15,23,42,.08);
}
.ia-modal-head{
  padding:18px 20px;
  border-bottom:1px solid rgba(15,23,42,.06);
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:12px;
}
.ia-modal-title{
  margin:0;
  font-size:20px;
  font-weight:800;
  color:#152235;
  line-height:1.1;
}
.ia-modal-sub{
  margin-top:6px;
  font-size:13px;
  color:#5b6d85;
}
.ia-modal-body{padding:18px 20px 22px 20px}
.ia-modal-close{
  border:none;
  background:#f4f7fb;
  border:1px solid rgba(15,23,42,0.08);
  border-radius:10px;
  min-width:34px;
  height:34px;
  cursor:pointer;
  font-size:18px;
  font-weight:700;
  color:#152235;
}
.ia-item-card{
  padding:14px 15px;
  border:1px solid rgba(15,23,42,0.07);
  border-radius:14px;
  background:#fff;
  margin-bottom:12px;
}
.ia-item-title{
  font-size:13px;
  font-weight:800;
  color:#152235;
  margin-bottom:8px;
}
.ia-item-block{
  margin-top:8px;
  font-size:12px;
  color:#32465f;
  line-height:1.55;
  white-space:pre-wrap;
  word-break:break-word;
}
.ia-item-meta{
  margin-top:10px;
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}
.ia-inline-actions{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  margin-top:10px;
}

@media (max-width: 1320px){
  .hero-grid{grid-template-columns:1fr}
  .top-grid{grid-template-columns:1fr}
  .two-col-grid{grid-template-columns:1fr}
}
@media (max-width: 980px){
  .ia-state-grid,
  .ia-form-grid{grid-template-columns:1fr}
  .lesson-table{table-layout:auto}
  .lesson-table-wrap{overflow:auto}
}
</style>

<div class="course-page-stack">

  <div class="hero-grid">
    <div class="card hero-card">
      <div class="hero-eyebrow">Instructor Workflow</div>
      <h1 class="hero-title">Instructor Approval</h1>
      <div class="hero-sub">
        Review blocked progression, inspect the latest performance evidence, and record the next training step.
      </div>
      <div class="hero-meta">
        Lesson: <strong><?= h2($lessonTitle) ?></strong><br>
        Cohort: <strong><?= h2($cohortTitle) ?></strong>
      </div>
    </div>

    <div class="card overview-card">
      <div class="overview-title">Motivation &amp; Engagement</div>
      <div class="overview-main">
        <div class="overview-big"><?= (int)$motivation['score'] ?>%</div>
        <div class="overview-sub">
          <?= h2((string)$motivation['label']) ?> based on summary quality, attempt trend, and recent performance behavior.
        </div>
      </div>
      <div class="progress-shell">
        <div class="progress-fill" style="width:<?= (int)$motivation['score'] ?>%;"></div>
      </div>
      <div class="smallmuted">
        This is a directional indicator for coaching judgment, not a final determination.
      </div>
    </div>
  </div>

  <?php if ($error !== ''): ?>
    <div class="card section-card" style="border:1px solid #fecaca;background:#fef2f2;color:#991b1b;">
      <?= h2($error) ?>
    </div>
  <?php endif; ?>

  <?php if ($success !== ''): ?>
    <div class="card section-card" style="border:1px solid #86efac;background:#ecfdf5;color:#166534;">
      <?= h2($success) ?>
    </div>
  <?php endif; ?>

  <div class="two-col-grid">
    <div class="ia-stack">

      <div class="card section-card">
        <div class="section-head">
          <div>
            <h2 class="section-title">Review Context</h2>
            <div class="section-sub">Instructor-only summary for this blocked progression case.</div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="ia-avatar-row">
            <div class="ip-avatar">
              <?php if (!empty($studentUser['photo_path'])): ?>
                <img src="<?= h2((string)$studentUser['photo_path']) ?>" alt="<?= h2($studentName) ?>">
              <?php else: ?>
                <span class="ip-avatar-fallback">👤</span>
              <?php endif; ?>
            </div>
            <div class="ia-person-meta">
              <div class="ia-person-name"><?= h2($studentName) ?></div>
              <div class="ia-person-sub">Student</div>
            </div>
          </div>

          <div class="ia-avatar-row">
            <div class="ip-avatar">
              <?php if (!empty($chiefUser['photo_path'])): ?>
                <img src="<?= h2((string)$chiefUser['photo_path']) ?>" alt="<?= h2((string)($chiefUser['name'] ?? 'Chief Instructor')) ?>">
              <?php else: ?>
                <span class="ip-avatar-fallback">👤</span>
              <?php endif; ?>
            </div>
            <div class="ia-person-meta">
              <div class="ia-person-name"><?= h2((string)($chiefUser['name'] ?? 'Chief Instructor')) ?></div>
              <div class="ia-person-sub">
                Chief Instructor<?php if (!empty($chiefUser['email'])): ?> · <?= h2((string)$chiefUser['email']) ?><?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card section-card">
        <div class="section-head">
          <div>
            <h2 class="section-title">Areas of Difficulty</h2>
            <div class="section-sub">AI-interpreted weakness pattern across all previous attempts.</div>
          </div>
        </div>
        <ul class="ia-copy-list">
          <?php if (!$difficultyItems): ?>
            <li>No persistent weakness pattern was derived.</li>
          <?php else: ?>
            <?php foreach ($difficultyItems as $item): ?>
              <li><?= h2($item) ?></li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </div>

      <div class="card section-card">
        <div class="section-head">
          <div>
            <h2 class="section-title">Official References</h2>
            <div class="section-sub">Copy-paste ready official references linked to the lesson slides.</div>
          </div>
        </div>
        <ul class="ia-copy-list">
          <?php if (!$references): ?>
            <li>No official references found for this lesson.</li>
          <?php else: ?>
            <?php foreach ($references as $ref): ?>
              <li><?= h2($ref) ?></li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </div>

      <div class="card section-card">
        <div class="section-head">
          <div>
            <h2 class="section-title">Recommendation</h2>
            <div class="section-sub">AI-generated didactical next-step options, best option on top.</div>
          </div>
        </div>
        <ul class="ia-reco-list">
          <?php foreach ($recommendations as $rec): ?>
            <li>
              <strong><?= h2((string)$rec['title']) ?></strong><br>
              Why: <?= h2((string)$rec['why']) ?><br>
              How: <?= h2((string)$rec['how']) ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="card section-card">
        <div class="section-head">
          <div>
            <h2 class="section-title">Recent Attempt History</h2>
            <div class="section-sub">Quick instructor scan of the most recent attempts for this lesson.</div>
          </div>
          <div class="count-pill"><?= count($attemptHistory) ?> shown</div>
        </div>

        <div class="lesson-table-wrap">
          <table class="lesson-table">
            <colgroup>
              <col style="width:14%;">
              <col style="width:14%;">
              <col style="width:16%;">
              <col style="width:56%;">
            </colgroup>
            <thead>
              <tr>
                <th>Attempt</th>
                <th>Score</th>
                <th class="th-center">Result</th>
                <th>Main Reason</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$attemptHistory): ?>
                <tr>
                  <td colspan="4">No attempt history found.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($attemptHistory as $row): ?>
                  <?php
                    $rowTestId = (int)($row['id'] ?? 0);
                    $scorePct = isset($row['score_pct']) ? (int)$row['score_pct'] : null;
                    $isCorrect = strtoupper((string)($row['formal_result_code'] ?? '')) === 'SAT';
                    $reasonText = trim((string)(($row['weak_areas'] ?? '') ?: ($row['ai_summary'] ?? '')));
                    if ($reasonText === '') $reasonText = 'See progress test details.';
                  ?>
                  <tr>
                    <td><?= h2((string)($row['attempt'] ?? '')) ?></td>
                    <td><?= $scorePct !== null ? h2((string)$scorePct) . '%' : '—' ?></td>
                    <td class="td-center">
                      <span class="state-pill <?= $isCorrect ? 'ok' : 'danger' ?>">
                        <?= $isCorrect ? '✓' : '✕' ?>
                      </span>
                    </td>
                    <td>
                      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                        <div class="ia-body-text" style="flex:1 1 auto;"><?= h2(short_text2($reasonText, 180)) ?></div>
                        <button
                          type="button"
                          class="action-btn primary"
                          data-open-modal="test-details-<?= (int)$rowTestId ?>"
                        >Test Details</button>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>

    <div class="ia-stack">

      <div class="card section-card">
        <div class="section-head">
          <div>
            <h2 class="section-title">Summary Review</h2>
            <div class="section-sub">Student lesson summary and instructor note visibility.</div>
          </div>
        </div>

        <div class="ia-state-grid">
          <div class="ia-state-box">
            <span class="ia-state-label">Review Status</span>
            <div class="ia-state-value"><?= h2((string)($summaryRow['review_status'] ?? '—')) ?></div>
          </div>
          <div class="ia-state-box">
            <span class="ia-state-label">Review Score</span>
            <div class="ia-state-value"><?= isset($summaryRow['review_score']) && $summaryRow['review_score'] !== null ? h2((string)$summaryRow['review_score']) . '%' : '—' ?></div>
          </div>
        </div>

        <div style="margin-top:14px;">
          <?php
            $summaryPct = isset($summaryRow['review_score']) && $summaryRow['review_score'] !== null ? (int)$summaryRow['review_score'] : 0;
            if ($summaryPct < 0) $summaryPct = 0;
            if ($summaryPct > 100) $summaryPct = 100;
            $summaryClass = 'neutral';
            if ((string)($summaryRow['review_status'] ?? '') === 'acceptable') $summaryClass = 'ok';
            elseif ((string)($summaryRow['review_status'] ?? '') === 'needs_revision') $summaryClass = 'danger';
            elseif ((string)($summaryRow['review_status'] ?? '') === 'pending') $summaryClass = 'warn';
          ?>
          <div class="summary-bar-shell">
            <div class="summary-bar-fill <?= h2($summaryClass) ?>" style="width:<?= (int)$summaryPct ?>%;"></div>
          </div>
          <div class="summary-label <?= h2($summaryClass) ?>">
            <?= h2((string)(($summaryRow['review_status'] ?? 'No review'))) ?>
          </div>
        </div>

        <div class="ia-actions">
          <button type="button" class="action-btn primary" data-open-modal="summary-modal">View Lesson Summary</button>
        </div>
      </div>

      <div class="card section-card">
        <div class="section-head">
          <div>
            <h2 class="section-title">Current Action State</h2>
            <div class="section-sub">Canonical state of this instructor approval action.</div>
          </div>
        </div>

        <div class="ia-state-grid">
          <div class="ia-state-box">
            <span class="ia-state-label">Action Status</span>
            <div class="ia-state-value"><?= h2((string)($action['status'] ?? '—')) ?></div>
          </div>
          <div class="ia-state-box">
            <span class="ia-state-label">Decision Code</span>
            <div class="ia-state-value"><?= h2((string)($action['decision_code'] ?? '—')) ?></div>
          </div>
          <div class="ia-state-box">
            <span class="ia-state-label">Granted Extra Attempts</span>
            <div class="ia-state-value"><?= h2((string)($action['granted_extra_attempts'] ?? 0)) ?></div>
          </div>
          <div class="ia-state-box">
            <span class="ia-state-label">Summary Revision Required</span>
            <div class="ia-state-value"><?= yesno2((int)($action['summary_revision_required'] ?? 0)) ?></div>
          </div>
          <div class="ia-state-box">
            <span class="ia-state-label">One-on-One Required</span>
            <div class="ia-state-value"><?= yesno2((int)($action['one_on_one_required'] ?? 0)) ?></div>
          </div>
          <div class="ia-state-box">
            <span class="ia-state-label">Training Suspended</span>
            <div class="ia-state-value"><?= yesno2((int)($action['training_suspended'] ?? 0)) ?></div>
          </div>
          <div class="ia-state-box">
            <span class="ia-state-label">Major Intervention Flag</span>
            <div class="ia-state-value"><?= yesno2((int)($action['major_intervention_flag'] ?? 0)) ?></div>
          </div>
          <div class="ia-state-box">
            <span class="ia-state-label">Lesson Completion Status</span>
            <div class="ia-state-value"><?= h2((string)($activity['completion_status'] ?? '—')) ?></div>
          </div>
        </div>

        <div style="margin-top:14px;">
          <span class="ia-state-label">Instructor Notes</span>
          <div class="ia-body-text"><?= h2((string)($action['decision_notes'] ?? 'No instructor notes recorded yet.')) ?></div>
        </div>
      </div>

      <?php if ((string)($action['status'] ?? '') !== 'approved'): ?>
      <div class="card section-card">
        <div class="section-head">
          <div>
            <h2 class="section-title">Record Instructor Decision</h2>
            <div class="section-sub">Choose the next operational path for this student.</div>
          </div>
        </div>

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
              <ul class="ia-guide-list">
                <li><strong>Grant additional attempts</strong> — student may continue after approval.</li>
                <li><strong>Require summary revision</strong> — student must improve the summary before retry.</li>
                <li><strong>Require one-on-one instructor session</strong> — student must complete an instructor session first.</li>
                <li><strong>Suspend training</strong> — stop further progression pending higher-level intervention.</li>
              </ul>
            </div>

            <div class="ia-field">
              <label class="ia-label" for="granted_extra_attempts">Extra attempts to grant</label>
              <input class="ia-input" type="number" name="granted_extra_attempts" id="granted_extra_attempts" min="0" max="5" step="1" value="0">
              <div class="ia-help">Use this when granting additional attempts. Leave at 0 for other decision paths.</div>
            </div>

            <div class="ia-field full">
              <label class="ia-label" for="decision_notes">Instructor decision notes</label>
              <textarea
                class="ia-textarea"
                name="decision_notes"
                id="decision_notes"
                required
                placeholder="Explain why this decision was made, what the student must do next, and any audit-relevant notes."
              ></textarea>
              <div class="ia-help">These notes become part of the audit trail.</div>
            </div>
          </div>

          <div class="ia-actions">
            <button type="submit" class="action-btn primary">Save Instructor Decision</button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <?php if (
        (string)($action['status'] ?? '') === 'approved' &&
        (int)($action['one_on_one_required'] ?? 0) === 1 &&
        (int)($activity['one_on_one_completed'] ?? 0) !== 1 &&
        (int)($action['training_suspended'] ?? 0) !== 1
      ): ?>
      <div class="card section-card">
        <div class="section-head">
          <div>
            <h2 class="section-title">One-on-One Session</h2>
            <div class="section-sub">Confirm completion of the required instructor session.</div>
          </div>
        </div>

        <form method="post" action="">
          <input type="hidden" name="token" value="<?= h2($token) ?>">
          <input type="hidden" name="mark_instructor_session_completed" value="1">
          <div class="ia-actions">
            <button type="submit" class="action-btn primary">Mark Instructor Session Completed</button>
          </div>
        </form>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<?php foreach ($attemptHistory as $row): ?>
  <?php
    $rowTestId = (int)($row['id'] ?? 0);
    $items = isset($progressItems[$rowTestId]) ? $progressItems[$rowTestId] : array();
  ?>
  <div class="ia-modal" id="test-details-<?= (int)$rowTestId ?>">
    <div class="ia-modal-card">
      <div class="ia-modal-head">
        <div>
          <h3 class="ia-modal-title">Progress Test Details · Attempt <?= h2((string)($row['attempt'] ?? '')) ?></h3>
          <div class="ia-modal-sub">Inspect prompts, transcript, audio, and AI scoring for this attempt.</div>
        </div>
        <button type="button" class="ia-modal-close" data-close-modal="test-details-<?= (int)$rowTestId ?>">×</button>
      </div>
      <div class="ia-modal-body">
        <?php if (!$items): ?>
          <div class="ia-body-text">No progress test item details found for this attempt.</div>
        <?php else: ?>
          <?php foreach ($items as $item): ?>
            <?php
              $audioUrl = audio_url_from_path2((string)($item['audio_path'] ?? ''));
              $scorePoints = isset($item['score_points']) && $item['score_points'] !== null ? (int)$item['score_points'] : null;
              $maxPoints = isset($item['max_points']) && $item['max_points'] !== null ? (int)$item['max_points'] : null;
            ?>
            <div class="ia-item-card">
              <div class="ia-item-title">Question <?= h2((string)($item['idx'] ?? '')) ?> · <?= h2((string)($item['kind'] ?? 'open')) ?></div>

              <div class="ia-item-block"><strong>Prompt:</strong> <?= h2((string)($item['prompt'] ?? '')) ?></div>
              <div class="ia-item-block"><strong>Student transcript:</strong> <?= h2((string)($item['transcript_text'] ?? 'No transcript stored.')) ?></div>

              <div class="ia-item-meta">
                <span class="state-pill <?= !empty($item['is_correct']) ? 'ok' : 'danger' ?>">
                  <?= !empty($item['is_correct']) ? 'AI Correct' : 'AI Incorrect' ?>
                </span>
                <span class="state-pill info">
                  <?= $scorePoints !== null ? h2((string)$scorePoints) : '—' ?>/<?= $maxPoints !== null ? h2((string)$maxPoints) : '—' ?> pts
                </span>
              </div>

              <?php if ($audioUrl !== ''): ?>
                <div class="ia-inline-actions">
                  <audio controls preload="none" style="width:100%;">
                    <source src="<?= h2($audioUrl) ?>" type="audio/webm">
                  </audio>
                </div>
              <?php endif; ?>

              <div class="ia-item-block">
                <strong>Instructor override:</strong> not yet implemented in schema.
                Add a dedicated override/audit table before enabling scoring overrides in production.
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<div class="ia-modal" id="summary-modal">
  <div class="ia-modal-card">
    <div class="ia-modal-head">
      <div>
        <h3 class="ia-modal-title">Lesson Summary</h3>
        <div class="ia-modal-sub">Instructor review of the student summary and instructor note field.</div>
      </div>
      <button type="button" class="ia-modal-close" data-close-modal="summary-modal">×</button>
    </div>
    <div class="ia-modal-body">
      <div class="ia-item-card">
        <div class="ia-item-title">Student Summary</div>
        <div class="ia-item-block"><?= trim((string)($summaryRow['summary_html'] ?? '')) !== '' ? (string)$summaryRow['summary_html'] : nl2br(h2((string)($summaryRow['summary_plain'] ?? 'No summary stored.'))) ?></div>
      </div>

      <div class="ia-item-card">
        <div class="ia-item-title">AI Review Feedback</div>
        <div class="ia-item-block"><?= h2((string)($summaryRow['review_feedback'] ?? 'No review feedback stored.')) ?></div>
      </div>

      <div class="ia-item-card">
        <div class="ia-item-title">Instructor Notes For Student Summary</div>
        <div class="ia-item-block"><?= h2((string)($summaryRow['review_notes_by_instructor'] ?? 'No instructor notes stored.')) ?></div>
      </div>

      <div class="ia-item-card">
        <div class="ia-item-title">Editing note</div>
        <div class="ia-item-block">
          Summary note editing is not yet wired into this page. The display is ready, and the save path should write to lesson_summaries.review_notes_by_instructor.
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  function openModal(id){
    var el = document.getElementById(id);
    if (el) el.classList.add('is-open');
  }
  function closeModal(id){
    var el = document.getElementById(id);
    if (el) el.classList.remove('is-open');
  }

  var openers = document.querySelectorAll('[data-open-modal]');
  for (var i = 0; i < openers.length; i++) {
    openers[i].addEventListener('click', function(){
      openModal(this.getAttribute('data-open-modal'));
    });
  }

  var closers = document.querySelectorAll('[data-close-modal]');
  for (var j = 0; j < closers.length; j++) {
    closers[j].addEventListener('click', function(){
      closeModal(this.getAttribute('data-close-modal'));
    });
  }

  var modals = document.querySelectorAll('.ia-modal');
  for (var k = 0; k < modals.length; k++) {
    modals[k].addEventListener('click', function(e){
      if (e.target === this) this.classList.remove('is-open');
    });
  }

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') {
      var open = document.querySelectorAll('.ia-modal.is-open');
      for (var z = 0; z < open.length; z++) {
        open[z].classList.remove('is-open');
      }
    }
  });
})();
</script>

<?php cw_footer(); ?>