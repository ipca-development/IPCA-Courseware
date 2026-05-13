<?php
declare(strict_types=1);

/**
 * Offline harness for the v2.1 summary evaluator (Option A).
 *
 * v2.1 is the active production evaluator as of 2026-05-12. This script lets
 * you replay historical content against both v1 and v2 to verify the change
 * is behaving as expected — without writing anything to the database.
 *
 * Loads:
 *   1. All historical needs_revision snapshots from lesson_summary_versions.
 *      These are summaries that v1 rejected and that blocked a student's progress
 *      test until they re-edited the summary into acceptance.
 *   2. A sample of recently accepted summaries from lesson_summaries.
 *      These are summaries v1 accepted; v2 must keep accepting the vast majority.
 *
 * For each row, calls the v2.1 evaluator and compares its verdict to v1's
 * historical verdict. Prints a side-by-side table and a go/no-go decision based
 * on configurable thresholds.
 *
 * READ-ONLY: this script never writes to the database. It DOES call the OpenAI
 * API once per row (cost roughly $0.01 - $0.05 per row depending on model).
 * The default 14 + 20 = 34 rows costs well under $1.
 *
 * The script bypasses the production dispatcher and calls the v1 / v2 evaluators
 * directly via LessonSummaryService::replayEvaluateV1 / replayEvaluateV2, so
 * running it does NOT affect live verdicts.
 *
 * Usage (from project root, with DB + OpenAI env set in .env or shell):
 *   php scripts/replay_summary_evals.php
 *
 * Optional environment overrides (script-local, NOT production config):
 *   REPLAY_REJECT_LIMIT  (default 14)  — historical needs_revision rows to replay
 *   REPLAY_ACCEPT_LIMIT  (default 20)  — recent acceptable rows to regress
 *   REPLAY_VERBOSE       (1 = print full v2 output per row, default off)
 *   REPLAY_PASS_TURNAROUND_PCT (default 60) — minimum percent of historical rejections
 *                                              that v2 must now accept
 *   REPLAY_PASS_REGRESSION_PCT (default 90) — minimum percent of v1-accepted rows
 *                                              that v2 must still accept
 */

$root = dirname(__DIR__);

/**
 * Minimal .env loader for CLI use, copied from scripts/verify_theory_progression_db.php
 * for parity with other scripts in this directory.
 */
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

$loadDotenv($root . '/.env');

require_once $root . '/src/db.php';
require_once $root . '/src/lesson_summary_service.php';

$pdo = cw_db();
$service = new LessonSummaryService($pdo);

$rejectLimit       = (int)(getenv('REPLAY_REJECT_LIMIT') ?: 14);
$acceptLimit       = (int)(getenv('REPLAY_ACCEPT_LIMIT') ?: 20);
$verbose           = ((string)(getenv('REPLAY_VERBOSE') ?: '')) === '1';
$passTurnaroundPct = (float)(getenv('REPLAY_PASS_TURNAROUND_PCT') ?: 60);
$passRegressionPct = (float)(getenv('REPLAY_PASS_REGRESSION_PCT') ?: 90);

echo "Replay harness for evaluateSummaryQualityV2 (v2.1)\n";
echo "Reject limit: {$rejectLimit} | Accept limit: {$acceptLimit}\n";
echo "Pass criteria: >= {$passTurnaroundPct}% rejection turnaround AND >= {$passRegressionPct}% acceptance retention.\n\n";

/* ----------------------- 1. Load historical rejections ----------------------- */
$st = $pdo->prepare("
  SELECT lsv.id, lsv.user_id, lsv.cohort_id, lsv.lesson_id, lsv.version_no,
         lsv.source_review_score, lsv.summary_html, lsv.summary_plain,
         lsv.source_updated_at, COALESCE(l.title,'') AS lesson_title
  FROM lesson_summary_versions lsv
  LEFT JOIN lessons l ON l.id = lsv.lesson_id
  WHERE lsv.source_review_status = 'needs_revision'
  ORDER BY lsv.source_updated_at DESC
  LIMIT {$rejectLimit}
");
$st->execute();
$rejects = $st->fetchAll(PDO::FETCH_ASSOC);

echo "Loaded ", count($rejects), " historical needs_revision snapshots.\n";

/* ----------------------- 2. Load random acceptances ------------------------- */
$st = $pdo->prepare("
  SELECT ls.id, ls.user_id, ls.cohort_id, ls.lesson_id,
         ls.review_score, ls.summary_html, ls.summary_plain,
         ls.reviewed_at, COALESCE(l.title,'') AS lesson_title
  FROM lesson_summaries ls
  LEFT JOIN lessons l ON l.id = ls.lesson_id
  WHERE ls.review_status = 'acceptable'
    AND ls.review_score >= 80
    AND CHAR_LENGTH(ls.summary_plain) >= 200
  ORDER BY ls.reviewed_at DESC
  LIMIT {$acceptLimit}
");
$st->execute();
$accepts = $st->fetchAll(PDO::FETCH_ASSOC);

echo "Loaded ", count($accepts), " recent acceptable summaries.\n\n";

/* ----------------------- 3. Helpers ---------------------------------------- */
$truncate = static function (string $s, int $n): string {
    $s = preg_replace('/\s+/u', ' ', trim($s));
    if (function_exists('mb_strlen') && mb_strlen($s) > $n) {
        return rtrim(mb_substr($s, 0, $n - 1)) . '…';
    }
    if (strlen($s) > $n) {
        return rtrim(substr($s, 0, $n - 1)) . '…';
    }
    return $s;
};

$printRow = static function (int $userId, int $lessonId, string $title, int $v1Score, $v2Score, string $v1Status, string $v2Status): void {
    $delta = is_int($v2Score) ? sprintf('%+d', $v2Score - $v1Score) : '?';
    $v2ScoreStr = is_int($v2Score) ? (string)$v2Score : '?';
    $transition = $v1Status === $v2Status
        ? "{$v1Status} (same)"
        : "{$v1Status} -> {$v2Status}";
    printf("u=%-4d l=%-5d %-46s  v1=%-3d  v2=%-3s  %-5s  %s\n",
        $userId,
        $lessonId,
        substr($title, 0, 46),
        $v1Score,
        $v2ScoreStr,
        $delta,
        $transition
    );
};

/* ----------------------- 4. Replay rejections ------------------------------ */
echo "=== Replay of historical needs_revision rows (v1 rejected) ===\n";
echo "Goal: v2 should ACCEPT a majority of these (v1 was over-strict at score 70).\n\n";

$rejectsNowAccepted = 0;
$rejectsStillRejected = 0;
$rejectsErrored = 0;

foreach ($rejects as $r) {
    try {
        $res = $service->replayEvaluateV2(
            (int)$r['user_id'], (int)$r['cohort_id'], (int)$r['lesson_id'],
            (string)$r['summary_html'], (string)$r['summary_plain']
        );
        $v1score = (int)$r['source_review_score'];
        $v2score = isset($res['review_score']) ? (int)$res['review_score'] : null;
        $v2status = (string)($res['review_status'] ?? '?');

        $printRow((int)$r['user_id'], (int)$r['lesson_id'], (string)$r['lesson_title'],
            $v1score, $v2score, 'needs_revision', $v2status);

        if ($v2status === 'acceptable') {
            $rejectsNowAccepted++;
        } elseif ($v2status === 'needs_revision') {
            $rejectsStillRejected++;
        }

        if ($verbose) {
            echo "    feedback : " . $truncate((string)($res['review_feedback'] ?? ''), 160) . "\n";
            $gt = (string)($res['gap_topics'] ?? '');
            if ($gt !== '') {
                echo "    gap_topics:\n";
                foreach (explode("\n", $gt) as $ln) {
                    echo "      " . $ln . "\n";
                }
            }
            echo "\n";
        }
    } catch (Throwable $e) {
        $rejectsErrored++;
        printf("u=%-4d l=%-5d %-46s  ERROR: %s\n",
            (int)$r['user_id'], (int)$r['lesson_id'],
            substr((string)$r['lesson_title'], 0, 46),
            $e->getMessage()
        );
    }
}

echo "\n";

/* ----------------------- 5. Replay acceptances (regression) ---------------- */
echo "=== Regression check on recent acceptable rows (v1 accepted) ===\n";
echo "Goal: v2 should STILL accept at least {$passRegressionPct}% of these.\n\n";

$acceptsStillAccepted = 0;
$acceptsNowRejected = 0;
$acceptsErrored = 0;

foreach ($accepts as $r) {
    try {
        $res = $service->replayEvaluateV2(
            (int)$r['user_id'], (int)$r['cohort_id'], (int)$r['lesson_id'],
            (string)$r['summary_html'], (string)$r['summary_plain']
        );
        $v1score = (int)$r['review_score'];
        $v2score = isset($res['review_score']) ? (int)$res['review_score'] : null;
        $v2status = (string)($res['review_status'] ?? '?');

        $printRow((int)$r['user_id'], (int)$r['lesson_id'], (string)$r['lesson_title'],
            $v1score, $v2score, 'acceptable', $v2status);

        if ($v2status === 'acceptable') {
            $acceptsStillAccepted++;
        } elseif ($v2status === 'needs_revision') {
            $acceptsNowRejected++;
        }

        if ($verbose) {
            echo "    feedback : " . $truncate((string)($res['review_feedback'] ?? ''), 160) . "\n\n";
        }
    } catch (Throwable $e) {
        $acceptsErrored++;
        printf("u=%-4d l=%-5d %-46s  ERROR: %s\n",
            (int)$r['user_id'], (int)$r['lesson_id'],
            substr((string)$r['lesson_title'], 0, 46),
            $e->getMessage()
        );
    }
}

echo "\n";

/* ----------------------- 6. Summary + go/no-go ----------------------------- */
$rejTotal = count($rejects);
$accTotal = count($accepts);
$turnaroundPct = $rejTotal > 0 ? round(100 * $rejectsNowAccepted / $rejTotal, 1) : 0.0;
$regressionPct = $accTotal > 0 ? round(100 * $acceptsStillAccepted / $accTotal, 1) : 0.0;

echo "=== Summary ===\n";
echo "Historical rejections (v1 needs_revision):\n";
echo "  total              : {$rejTotal}\n";
echo "  now accepted by v2 : {$rejectsNowAccepted} ({$turnaroundPct}%)\n";
echo "  still rejected     : {$rejectsStillRejected}\n";
echo "  errored            : {$rejectsErrored}\n\n";

echo "Recent acceptances (v1 acceptable, regression check):\n";
echo "  total              : {$accTotal}\n";
echo "  still accepted     : {$acceptsStillAccepted} ({$regressionPct}%)\n";
echo "  now rejected by v2 : {$acceptsNowRejected}\n";
echo "  errored            : {$acceptsErrored}\n\n";

$go = ($turnaroundPct >= $passTurnaroundPct) && ($regressionPct >= $passRegressionPct);

echo "Pass criteria : turnaround >= {$passTurnaroundPct}% AND regression >= {$passRegressionPct}%\n";
echo "Actual        : turnaround = {$turnaroundPct}%, regression = {$regressionPct}%\n";
echo "Decision      : " . ($go ? "GO  -- v2.1 is safe to enable on canary." : "NO-GO  -- v2.1 needs tuning before enabling.") . "\n";

exit($go ? 0 : 2);
