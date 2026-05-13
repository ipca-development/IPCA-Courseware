<?php
declare(strict_types=1);

/**
 * scripts/lesson_activity_extension_count_backfill.php
 *
 * Q7 / BUG E — One-time backfill for lesson_activity.extension_count and
 * lesson_activity.final_warning_issued.
 *
 * Background:
 *   - persistLessonActivityProjection() previously did not include extension_count or
 *     final_warning_issued in its $allowedFields list. As a result these two columns
 *     were only written at initial INSERT and never updated thereafter.
 *   - The canonical source is student_lesson_deadline_overrides:
 *       extension_count       = COUNT(*) WHERE override_type IN ('extension_1','extension_2_final')
 *       final_warning_issued  = EXISTS one row WHERE override_type = 'extension_2_final'
 *   - The diagnostic scan identified ~214 rows where lesson_activity.extension_count
 *     drifted from the canonical count.
 *   - Going forward, persistLessonActivityProjection now recomputes these fields on every
 *     persist. This script is a one-time backfill for any rows that have not yet had a
 *     projection persist since the fix shipped.
 *
 * Modes:
 *   php scripts/lesson_activity_extension_count_backfill.php                  → READ-ONLY (default)
 *   php scripts/lesson_activity_extension_count_backfill.php --apply           → APPLY backfill
 *   php scripts/lesson_activity_extension_count_backfill.php --apply --limit=50
 *   php scripts/lesson_activity_extension_count_backfill.php --user-id=21 --lesson-id=930
 *
 * Safety:
 *   - READ-ONLY mode prints a diff table and a summary; no rows are modified.
 *   - APPLY mode writes an audit event per row to training_progression_events with
 *     event_code='lesson_activity_extension_count_backfilled' and full before/after JSON.
 *   - All updates are wrapped in a transaction per row (idempotent re-runs are safe).
 */

$root = dirname(__DIR__);

$loadDotenv = static function (string $path): void {
    if (!is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            continue;
        }
        $key = $m[1];
        $val = $m[2];
        if ($val !== '' && (($val[0] === '"' && str_ends_with($val, '"')) || ($val[0] === "'" && str_ends_with($val, "'")))) {
            $val = substr($val, 1, -1);
        }
        if (getenv($key) !== false) {
            continue;
        }
        putenv($key . '=' . $val);
        $_ENV[$key] = $val;
    }
};

if (!getenv('CW_DB_HOST')) {
    $loadDotenv($root . '/.env');
}

require_once $root . '/src/db.php';

$apply = false;
$limit = 1000;
$filterUserId = null;
$filterLessonId = null;
$filterCohortId = null;

foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int)$m[1];
        continue;
    }
    if (preg_match('/^--user-id=(\d+)$/', $arg, $m)) {
        $filterUserId = (int)$m[1];
        continue;
    }
    if (preg_match('/^--cohort-id=(\d+)$/', $arg, $m)) {
        $filterCohortId = (int)$m[1];
        continue;
    }
    if (preg_match('/^--lesson-id=(\d+)$/', $arg, $m)) {
        $filterLessonId = (int)$m[1];
        continue;
    }
    fwrite(STDERR, "Unknown argument: $arg\n");
    fwrite(STDERR, "Usage: php scripts/lesson_activity_extension_count_backfill.php [--apply] [--limit=N] [--user-id=N] [--cohort-id=N] [--lesson-id=N]\n");
    exit(2);
}

echo "=== lesson_activity extension_count + final_warning_issued backfill ===\n";
echo "mode:   " . ($apply ? "APPLY (writes will be persisted)" : "READ-ONLY (no writes)") . "\n";
echo "limit:  {$limit}\n";
if ($filterUserId !== null)   echo "user_id filter:   {$filterUserId}\n";
if ($filterCohortId !== null) echo "cohort_id filter: {$filterCohortId}\n";
if ($filterLessonId !== null) echo "lesson_id filter: {$filterLessonId}\n";
echo "\n";

try {
    $pdo = cw_db();
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$where = "WHERE 1=1";
$params = [];
if ($filterUserId !== null) {
    $where .= " AND la.user_id = :user_id";
    $params[':user_id'] = $filterUserId;
}
if ($filterCohortId !== null) {
    $where .= " AND la.cohort_id = :cohort_id";
    $params[':cohort_id'] = $filterCohortId;
}
if ($filterLessonId !== null) {
    $where .= " AND la.lesson_id = :lesson_id";
    $params[':lesson_id'] = $filterLessonId;
}

$candidatesSql = "
    SELECT
        la.user_id,
        la.cohort_id,
        la.lesson_id,
        COALESCE(la.extension_count, 0) AS current_extension_count,
        COALESCE(la.final_warning_issued, 0) AS current_final_warning_issued,
        (
            SELECT COUNT(*)
            FROM student_lesson_deadline_overrides slo
            WHERE slo.user_id = la.user_id
              AND slo.cohort_id = la.cohort_id
              AND slo.lesson_id = la.lesson_id
              AND slo.override_type IN ('extension_1','extension_2_final')
        ) AS canonical_extension_count,
        (
            SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END
            FROM student_lesson_deadline_overrides slo2
            WHERE slo2.user_id = la.user_id
              AND slo2.cohort_id = la.cohort_id
              AND slo2.lesson_id = la.lesson_id
              AND slo2.override_type = 'extension_2_final'
        ) AS canonical_final_warning_issued
    FROM lesson_activity la
    {$where}
    HAVING
        current_extension_count <> canonical_extension_count
        OR current_final_warning_issued <> canonical_final_warning_issued
    ORDER BY la.cohort_id, la.user_id, la.lesson_id
    LIMIT :limit
";

$stmt = $pdo->prepare($candidatesSql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, PDO::PARAM_INT);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "No drifting rows found. lesson_activity is in sync with student_lesson_deadline_overrides.\n";
    exit(0);
}

echo str_pad("user_id", 8) . " "
   . str_pad("cohort_id", 10) . " "
   . str_pad("lesson_id", 10) . " "
   . str_pad("ext_count_now", 14) . " "
   . str_pad("ext_count_canon", 16) . " "
   . str_pad("fw_now", 7) . " "
   . str_pad("fw_canon", 9) . "\n";

$applied = 0;
$failed = 0;
$wouldUpdate = 0;

foreach ($rows as $r) {
    $userId   = (int)$r['user_id'];
    $cohortId = (int)$r['cohort_id'];
    $lessonId = (int)$r['lesson_id'];
    $curExt   = (int)$r['current_extension_count'];
    $curFW    = (int)$r['current_final_warning_issued'];
    $canExt   = (int)$r['canonical_extension_count'];
    $canFW    = (int)$r['canonical_final_warning_issued'];

    echo str_pad((string)$userId, 8) . " "
       . str_pad((string)$cohortId, 10) . " "
       . str_pad((string)$lessonId, 10) . " "
       . str_pad((string)$curExt, 14) . " "
       . str_pad((string)$canExt, 16) . " "
       . str_pad((string)$curFW, 7) . " "
       . str_pad((string)$canFW, 9) . "\n";

    if (!$apply) {
        $wouldUpdate++;
        continue;
    }

    try {
        $pdo->beginTransaction();

        $update = $pdo->prepare("
            UPDATE lesson_activity
            SET extension_count = :ext_count,
                final_warning_issued = :fw_issued,
                updated_at = UTC_TIMESTAMP()
            WHERE user_id = :user_id
              AND cohort_id = :cohort_id
              AND lesson_id = :lesson_id
              AND COALESCE(extension_count, 0) = :assert_ext
              AND COALESCE(final_warning_issued, 0) = :assert_fw
            LIMIT 1
        ");
        $update->execute([
            ':ext_count'  => $canExt,
            ':fw_issued'  => $canFW,
            ':user_id'    => $userId,
            ':cohort_id'  => $cohortId,
            ':lesson_id'  => $lessonId,
            ':assert_ext' => $curExt,
            ':assert_fw'  => $curFW,
        ]);

        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            $failed++;
            echo "  -> SKIPPED (row changed between scan and update; idempotent re-run will catch it).\n";
            continue;
        }

        $auditPayload = [
            'before' => [
                'extension_count' => $curExt,
                'final_warning_issued' => $curFW,
            ],
            'after' => [
                'extension_count' => $canExt,
                'final_warning_issued' => $canFW,
            ],
            'source' => 'scripts/lesson_activity_extension_count_backfill.php',
        ];

        $event = $pdo->prepare("
            INSERT INTO training_progression_events
            (user_id, cohort_id, lesson_id, progress_test_id, event_type, event_code, event_status,
             actor_type, actor_user_id, event_time, payload_json, legal_note)
            VALUES
            (:user_id, :cohort_id, :lesson_id, NULL, 'projection', 'lesson_activity_extension_count_backfilled',
             'info', 'system', NULL, UTC_TIMESTAMP(), :payload, :legal_note)
        ");
        $event->execute([
            ':user_id'    => $userId,
            ':cohort_id'  => $cohortId,
            ':lesson_id'  => $lessonId,
            ':payload'    => json_encode($auditPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':legal_note' => 'One-time backfill of lesson_activity.extension_count and final_warning_issued from canonical student_lesson_deadline_overrides (Q7 / BUG E).',
        ]);

        $pdo->commit();
        $applied++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $failed++;
        echo "  -> FAILED: " . $e->getMessage() . "\n";
    }
}

echo "\n";
if ($apply) {
    echo "Applied:  {$applied}\n";
    echo "Failed:   {$failed}\n";
} else {
    echo "Would update:  {$wouldUpdate}\n";
    echo "(Re-run with --apply to persist.)\n";
}
