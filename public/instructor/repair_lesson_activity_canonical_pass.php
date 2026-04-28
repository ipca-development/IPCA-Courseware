<?php
declare(strict_types=1);

/**
 * One-off web repair: align lesson_activity with canonical PASS (see CoursewareProgressionV2::repairLessonActivityForCanonicalPassIfNeeded).
 *
 * REMOVE THIS FILE after you have run it successfully (it is not meant to stay deployed).
 *
 * Usage (while logged in as admin or chief instructor):
 *   GET .../instructor/repair_lesson_activity_canonical_pass.php
 *       → dry-run: counts candidates only
 *   GET .../instructor/repair_lesson_activity_canonical_pass.php?run=1
 *       → executes repair for all matching rows (optional cohort_id, limit)
 *   GET .../instructor/repair_lesson_activity_canonical_pass.php?run=1&cohort_id=6
 *   GET .../instructor/repair_lesson_activity_canonical_pass.php?run=1&limit=500
 */

require_once __DIR__ . '/../../src/bootstrap.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if (!in_array($role, ['admin', 'chief_instructor'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden: admin or chief_instructor only. Delete this file after use.';
    exit;
}

$run = isset($_GET['run']) && (string)$_GET['run'] === '1';
$cohortId = isset($_GET['cohort_id']) ? (int)$_GET['cohort_id'] : 0;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;

require_once __DIR__ . '/../../src/courseware_progression_v2.php';
$engine = new CoursewareProgressionV2($pdo);

$sql = "
    SELECT DISTINCT la.user_id, la.cohort_id, la.lesson_id
    FROM lesson_activity la
    WHERE EXISTS (
        SELECT 1
        FROM progress_tests_v2 pt
        WHERE pt.user_id = la.user_id
          AND pt.cohort_id = la.cohort_id
          AND pt.lesson_id = la.lesson_id
          AND pt.status = 'completed'
          AND pt.pass_gate_met = 1
    )
    AND NOT (
        COALESCE(la.test_pass_status, '') = 'passed'
        AND COALESCE(la.completion_status, '') IN ('completed', 'awaiting_summary_review')
    )
";
$params = [];
if ($cohortId > 0) {
    $sql .= ' AND la.cohort_id = ? ';
    $params[] = $cohortId;
}
$sql .= ' ORDER BY la.cohort_id ASC, la.user_id ASC, la.lesson_id ASC ';
if ($limit > 0) {
    $sql .= ' LIMIT ' . $limit;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/plain; charset=utf-8');
echo "repair_lesson_activity_canonical_pass — REMOVE THIS FILE AFTER USE\n\n";
echo 'Candidates: ' . count($rows) . ($run ? '' : ' (dry-run; add &run=1 to write)') . "\n";
if ($cohortId > 0) {
    echo "Filter cohort_id={$cohortId}\n";
}
if ($limit > 0) {
    echo "Limit {$limit}\n";
}
echo "\n";

if (!$run) {
    $i = 0;
    foreach ($rows as $r) {
        if ($i++ >= 40) {
            echo "... (showing first 40 of " . count($rows) . ")\n";
            break;
        }
        echo "would repair user={$r['user_id']} cohort={$r['cohort_id']} lesson={$r['lesson_id']}\n";
    }
    echo "\nTo execute: same URL with &run=1 (and optional cohort_id / limit).\n";
    exit;
}

$stats = ['repaired' => 0, 'skipped' => 0, 'failed' => 0];
foreach ($rows as $r) {
    $uid = (int)$r['user_id'];
    $cid = (int)$r['cohort_id'];
    $lid = (int)$r['lesson_id'];
    $label = "user={$uid} cohort={$cid} lesson={$lid}";
    try {
        $out = $engine->repairLessonActivityForCanonicalPassIfNeeded($uid, $cid, $lid);
        $action = (string)($out['action'] ?? '');
        if ($action === 'repaired') {
            $stats['repaired']++;
            echo "repaired {$label}\n";
        } else {
            $stats['skipped']++;
            echo "skipped {$label} (" . ($out['reason'] ?? '?') . ")\n";
        }
    } catch (Throwable $e) {
        $stats['failed']++;
        echo "FAILED {$label}: " . $e->getMessage() . "\n";
    }
}

echo "\nSummary: repaired={$stats['repaired']} skipped={$stats['skipped']} failed={$stats['failed']}\n";
echo "\nDelete public/instructor/repair_lesson_activity_canonical_pass.php from the server now.\n";
